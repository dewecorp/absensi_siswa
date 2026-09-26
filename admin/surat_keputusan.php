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

$page_title = 'Surat Keputusan';

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

$response = fetch_sims_surat('surat-keputusan', $params);
$surat_rows = $response['data'] ?? [];
$fetch_error = ($response['status'] ?? '') === 'error' ? ($response['message'] ?? 'Gagal mengambil data dari SIMS') : null;

$cfg = getInboundEndpointConfig('sims');
$sims_root_url = get_sims_root_url($cfg['base_url'] ?? '');

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-surat-keputusan').length) {
        $('#table-surat-keputusan').DataTable({
            'order': [[0, 'asc']],
            'columnDefs': [
                { 'sortable': false, 'targets': [5] }
            ],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada surat keputusan yang ditemukan',
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

    $(document).on('click', '.btn-cetak-sk', function(e) {
        e.preventDefault();
        var printUrl = $(this).data('print-url');
        var fileUrl = $(this).data('file-url');
        var title = $(this).data('title') || 'Cetak Surat Keputusan';
        var targetUrl = '';
        if (fileUrl) {
            targetUrl = 'view_surat.php?url=' + encodeURIComponent(fileUrl) + '&title=' + encodeURIComponent(title);
        } else if (printUrl) {
            targetUrl = printUrl;
        }
        if (!targetUrl) {
            Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Dokumen SK tidak dapat dicetak.' });
            return;
        }
        window.open(targetUrl, '_blank');
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
            <h1>Surat Keputusan</h1>
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
                    <h4>Daftar Surat Keputusan (SIMS)</h4>
                    <div class="card-header-action">
                        <a href="?refresh=1" class="btn btn-outline-primary mr-2" title="Sinkron Ulang Data SIMS">
                            <i class="fas fa-sync-alt"></i> Sinkron
                        </a>
                        <span class="badge badge-primary font-weight-bold" style="font-size:14px; padding:6px 12px;">
                            Total: <?= count($surat_rows) ?> SK
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-surat-keputusan">
                            <thead>
                                <tr>
                                    <th class="text-center" width="5%">No</th>
                                    <th>Tanggal Pembuatan</th>
                                    <th>Tanggal Surat</th>
                                    <th>Nomor Surat</th>
                                    <th>Nama SK</th>
                                    <th class="text-center" width="12%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($surat_rows as $i => $row): ?>
                                    <?php
                                        $id_sk = (int)($row['id'] ?? 0);
                                        $file_url = $row['file_url'] ?? '';
                                        $print_url = $sims_root_url !== '' ? rtrim($sims_root_url, '/') . '/print_surat_keputusan.php?id=' . $id_sk : '';
                                        $tgl_buat = !empty($row['created_at']) ? formatDateDMY($row['created_at']) : formatDateDMY($row['tgl_surat'] ?? '');
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td><?= htmlspecialchars($tgl_buat) ?></td>
                                        <td><?= formatDateDMY($row['tgl_surat'] ?? '') ?></td>
                                        <td><strong><?= htmlspecialchars($row['no_surat'] ?? '-') ?></strong></td>
                                        <td><?= htmlspecialchars($row['nama_sk'] ?? '-') ?></td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-primary btn-sm btn-cetak-sk"
                                                data-print-url="<?= htmlspecialchars($print_url, ENT_QUOTES) ?>"
                                                data-file-url="<?= htmlspecialchars($file_url, ENT_QUOTES) ?>"
                                                data-title="Surat Keputusan: <?= htmlspecialchars($row['nama_sk'] ?? '', ENT_QUOTES) ?>"
                                                title="Cetak Surat Keputusan">
                                                <i class="fas fa-print"></i> Cetak
                                            </button>
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
