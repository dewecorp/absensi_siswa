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
        var title = $(this).data('title') || 'Preview Surat';
        if (!url) {
            Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'File surat tidak tersedia.' });
            return;
        }
        $('#modalPreviewTitle').text(title);
        var ext = url.split('.').pop().toLowerCase();
        var content = '';
        if (ext === 'pdf') {
            content = '<iframe src="' + url + '" style="width:100%; height:550px; border:none;"></iframe>';
        } else if (['jpg', 'jpeg', 'png', 'gif', 'webp'].indexOf(ext) !== -1) {
            content = '<div class="text-center"><img src="' + url + '" class="img-fluid rounded" style="max-height:550px;"></div>';
        } else {
            content = '<div class="text-center p-4"><p>File tipe <strong>.' + ext + '</strong> tidak dapat dipreview langsung.</p><a href="' + url + '" target="_blank" class="btn btn-primary"><i class="fas fa-download"></i> Unduh File</a></div>';
        }
        $('#modalPreviewBody').html(content);
        $('#modalPreviewSurat').modal('show');
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
                                                <button type="button" class="btn btn-info btn-sm btn-preview-surat"
                                                    data-file="<?= htmlspecialchars($row['file_url'], ENT_QUOTES) ?>"
                                                    data-title="Surat: <?= htmlspecialchars($row['no_surat'] ?? '', ENT_QUOTES) ?>">
                                                    <i class="fas fa-eye"></i> Lihat
                                                </button>
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

<!-- Modal Preview Surat -->
<div class="modal fade" id="modalPreviewSurat" tabindex="-1" role="dialog" aria-labelledby="modalPreviewTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalPreviewTitle">Preview Surat Masuk</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="modalPreviewBody">
            </div>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
