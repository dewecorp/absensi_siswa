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

$page_title = 'Surat Masuk';

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

$response = fetch_sims_surat('surat-masuk', $params);
$surat_rows = $response['data'] ?? [];
$fetch_error = ($response['status'] ?? '') === 'error' ? ($response['message'] ?? 'Gagal mengambil data dari SIMS') : null;

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-surat-masuk').length) {
        $('#table-surat-masuk').DataTable({
            'order': [[0, 'asc']],
            'columnDefs': [
                { 'sortable': false, 'targets': [6] }
            ],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada surat masuk yang ditemukan',
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

    $(document).on('click', '.btn-preview-surat', function(e) {
        e.preventDefault();
        var url = $(this).data('file');
        var title = $(this).data('title') || 'Preview Surat Masuk';
        if (!url) {
            Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'File surat tidak tersedia.' });
            return;
        }
        var targetUrl = 'view_surat.php?url=' + encodeURIComponent(url) + '&title=' + encodeURIComponent(title);
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
            <h1>Surat Masuk</h1>
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
                    <h4>Daftar Surat Masuk (SIMS)</h4>
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
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-surat-masuk">
                            <thead>
                                <tr>
                                    <th class="text-center" width="5%">No</th>
                                    <th>Tanggal Terima</th>
                                    <th>No Surat</th>
                                    <th>Tanggal Surat</th>
                                    <th>Perihal</th>
                                    <th>Pengirim</th>
                                    <th class="text-center" width="12%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($surat_rows as $i => $row): ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td><?= formatDateDMY($row['tgl_terima'] ?? '') ?></td>
                                        <td><strong><?= htmlspecialchars($row['no_surat'] ?? '-') ?></strong></td>
                                        <td><?= formatDateDMY($row['tgl_surat'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($row['perihal'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($row['pengirim'] ?? '-') ?></td>
                                        <td class="text-center">
                                            <?php if (!empty($row['file_url'])): ?>
                                                <a href="view_surat.php?url=<?= urlencode($row['file_url']) ?>&amp;title=<?= urlencode('Surat Masuk: ' . ($row['no_surat'] ?? '')) ?>"
                                                   target="_blank"
                                                   class="btn btn-info btn-sm btn-preview-surat"
                                                   data-file="<?= htmlspecialchars($row['file_url'], ENT_QUOTES) ?>"
                                                   data-title="Surat Masuk: <?= htmlspecialchars($row['no_surat'] ?? '', ENT_QUOTES) ?>">
                                                    <i class="fas fa-eye"></i> Lihat
                                                </a>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">Tidak ada file</span>
                                            <?php endif; ?>
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
