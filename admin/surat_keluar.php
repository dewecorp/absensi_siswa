<?php
error_reporting(E_ALL & ~E_DEPRECATED);

require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/endpoint_registry.php';
require_once '../config/sims_surat_helper.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha'])) {
    redirect('../login.php');
}

$page_title = 'Surat Keluar';

$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$search = trim((string)($_GET['search'] ?? ''));
$params = ['limit' => 1000];
if ($search !== '') {
    $params['search'] = $search;
}

$force_refresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
if ($force_refresh) {
    get_sims_surat_counts(true);
}

$response = fetch_sims_surat('surat-keluar', $params);
$all_surat_rows = $response['data'] ?? [];
$fetch_error = ($response['status'] ?? '') === 'error' ? ($response['message'] ?? 'Gagal mengambil data dari SIMS') : null;

$selected_tahun = trim((string)($_GET['tahun'] ?? ''));
$selected_penerima = trim((string)($_GET['penerima'] ?? ''));

$tahun_list = [];
$penerima_list = [];
foreach ($all_surat_rows as $r) {
    $tgl = $r['tgl_surat'] ?? '';
    if ($tgl) {
        $y = date('Y', strtotime($tgl));
        if ($y && !in_array($y, $tahun_list, true)) {
            $tahun_list[] = $y;
        }
    }
    $p = trim((string)($r['penerima'] ?? ''));
    if ($p !== '' && !in_array($p, $penerima_list, true)) {
        $penerima_list[] = $p;
    }
}
rsort($tahun_list);
natcasesort($penerima_list);

$surat_rows = $all_surat_rows;
if ($selected_tahun !== '' || $selected_penerima !== '') {
    $surat_rows = array_filter($all_surat_rows, static function($r) use ($selected_tahun, $selected_penerima) {
        if ($selected_tahun !== '') {
            $tgl = $r['tgl_surat'] ?? '';
            $y = $tgl ? date('Y', strtotime($tgl)) : '';
            if ($y !== $selected_tahun) return false;
        }
        if ($selected_penerima !== '') {
            $p = trim((string)($r['penerima'] ?? ''));
            if (strcasecmp($p, $selected_penerima) !== 0) return false;
        }
        return true;
    });
}

$cfg = getInboundEndpointConfig('sims');
$sims_root_url = get_sims_root_url($cfg['base_url'] ?? '');

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-surat-keluar').length) {
        $('#table-surat-keluar').DataTable({
            'order': [[0, 'asc']],
            'columnDefs': [
                { 'sortable': false, 'targets': [5] }
            ],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada surat keluar yang ditemukan',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari:',
                'paginate': {
                    'first': 'Pertama',
                    'last': 'Terakhir',
                    'next': 'Selanjutnya',
                    'previous': 'Sebelumnya'
                }
            }
        });
    }

    $(document).on('click', '.btn-cetak-surat', function(e) {
        e.preventDefault();
        var viewUrl = $(this).data('view-url') || $(this).attr('href');
        if (!viewUrl) {
            Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Dokumen surat tidak dapat dicetak.' });
            return;
        }
        window.open(viewUrl, '_blank');
    });
});
JS
];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Surat Keluar</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <?php if ($fetch_error): ?>
                <div class="alert alert-warning alert-dismissible show fade">
                    <div class="alert-body">
                        <button class="close" data-dismiss="alert"><span>&times;</span></button>
                        <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($fetch_error) ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h4>Daftar Surat Keluar (SIMS)</h4>
                    <div class="card-header-action">
                        <a href="?refresh=1" class="btn btn-outline-primary mr-2" title="Sinkron Ulang Data SIMS">
                            <i class="fas fa-sync-alt"></i> Sinkron
                        </a>
                        <span class="badge badge-primary font-weight-bold" style="font-size:14px; padding:6px 12px;">
                            Total: <?= count($surat_rows) ?> Surat
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <form method="GET" class="form-inline mb-3">
                        <?php if (isset($_GET['session_type'])): ?>
                            <input type="hidden" name="session_type" value="<?= htmlspecialchars($_GET['session_type']) ?>">
                        <?php endif; ?>
                        <div class="form-group mr-3 mb-2">
                            <label for="filterTahun" class="mr-2 font-weight-bold">Tahun:</label>
                            <select name="tahun" id="filterTahun" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Tahun --</option>
                                <?php foreach ($tahun_list as $y): ?>
                                    <option value="<?= htmlspecialchars($y) ?>" <?= $selected_tahun === (string)$y ? 'selected' : '' ?>><?= htmlspecialchars($y) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group mr-3 mb-2">
                            <label for="filterPenerima" class="mr-2 font-weight-bold">Penerima:</label>
                            <select name="penerima" id="filterPenerima" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Penerima --</option>
                                <?php foreach ($penerima_list as $p): ?>
                                    <option value="<?= htmlspecialchars($p) ?>" <?= strcasecmp($selected_penerima, $p) === 0 ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($selected_tahun !== '' || $selected_penerima !== ''): ?>
                            <a href="surat_keluar.php<?= isset($_GET['session_type']) ? '?session_type=' . urlencode($_GET['session_type']) : '' ?>" class="btn btn-sm btn-secondary mb-2">
                                <i class="fas fa-undo"></i> Reset Filter
                            </a>
                        <?php endif; ?>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-striped" id="table-surat-keluar">
                            <thead>
                                <tr>
                                    <th class="text-center" width="5%">No</th>
                                    <th>Tanggal</th>
                                    <th>Nomor Surat</th>
                                    <th>Perihal</th>
                                    <th>Penerima</th>
                                    <th class="text-center" width="12%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($surat_rows as $i => $row): ?>
                                    <?php
                                        $id_surat = (int)($row['id'] ?? 0);
                                        $file_url = $row['file_url'] ?? '';
                                        $print_url = $sims_root_url !== '' ? rtrim($sims_root_url, '/') . '/print_surat_keluar.php?id=' . $id_surat : '';
                                        $target_surat_url = !empty($file_url) ? $file_url : $print_url;
                                        $view_url = 'view_surat.php?url=' . urlencode($target_surat_url) . '&title=' . urlencode('Surat Keluar: ' . ($row['no_surat'] ?? ''));
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td><?= formatDateDMY($row['tgl_surat'] ?? '') ?></td>
                                        <td><strong><?= htmlspecialchars($row['no_surat'] ?? '-') ?></strong></td>
                                        <td><?= htmlspecialchars($row['perihal'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($row['penerima'] ?? '-') ?></td>
                                        <td class="text-center">
                                            <a href="<?= htmlspecialchars($view_url, ENT_QUOTES) ?>" target="_blank"
                                               class="btn btn-primary btn-sm btn-cetak-surat"
                                               data-view-url="<?= htmlspecialchars($view_url, ENT_QUOTES) ?>"
                                               title="Cetak Surat">
                                                <i class="fas fa-print"></i> Cetak
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
