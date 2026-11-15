<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$session_type = sanitizeInput((string)($_GET['session_type'] ?? ''));
$allowed = ['admin'];
if (in_array($session_type, ['kepala_madrasah', 'tata_usaha', 'guru', 'wali'], true)) {
    $allowed[] = $session_type;
}
if (!isAuthorized($allowed)) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$can_edit = isAuthorized(['admin']) || ($user_level === 'admin');

// Pastikan tabel ada
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_jadwal_pembina_upacara (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tanggal DATE NOT NULL,
        id_guru INT NOT NULL,
        keterangan VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_tanggal (tanggal),
        KEY idx_id_guru (id_guru)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$page_title = 'Jadwal Pembina Upacara';
$alert = null;

// Handle CRUD POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $action = sanitizeInput((string)($_POST['_action'] ?? ''));

    if ($action === 'add') {
        $tanggal = trim(sanitizeInput((string)($_POST['tanggal'] ?? '')));
        $id_guru = (int)($_POST['id_guru'] ?? 0);
        $keterangan = trim(sanitizeInput((string)($_POST['keterangan'] ?? '')));

        if ($tanggal === '' || $id_guru <= 0) {
            $alert = ['type' => 'danger', 'text' => 'Tanggal dan guru wajib diisi.'];
        } else {
            $stmt = $pdo->prepare("INSERT INTO tb_jadwal_pembina_upacara (tanggal, id_guru, keterangan) VALUES (?, ?, ?)");
            $stmt->execute([$tanggal, $id_guru, $keterangan ?: null]);
            $alert = ['type' => 'success', 'text' => 'Jadwal pembina upacara berhasil ditambahkan.'];
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $tanggal = trim(sanitizeInput((string)($_POST['tanggal'] ?? '')));
        $id_guru = (int)($_POST['id_guru'] ?? 0);
        $keterangan = trim(sanitizeInput((string)($_POST['keterangan'] ?? '')));

        if ($id <= 0 || $tanggal === '' || $id_guru <= 0) {
            $alert = ['type' => 'danger', 'text' => 'Data tidak valid.'];
        } else {
            $stmt = $pdo->prepare("UPDATE tb_jadwal_pembina_upacara SET tanggal = ?, id_guru = ?, keterangan = ? WHERE id = ?");
            $stmt->execute([$tanggal, $id_guru, $keterangan ?: null, $id]);
            $alert = ['type' => 'success', 'text' => 'Jadwal pembina upacara berhasil diperbarui.'];
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM tb_jadwal_pembina_upacara WHERE id = ?");
            $stmt->execute([$id]);
            $alert = ['type' => 'success', 'text' => 'Jadwal pembina upacara berhasil dihapus.'];
        }
    }
}

// Ambil daftar semua guru untuk dropdown
$all_guru = $pdo->query("SELECT id_guru, nama_guru FROM tb_guru ORDER BY nama_guru ASC")->fetchAll(PDO::FETCH_ASSOC);

// Ambil daftar jadwal
$rows = $pdo->query("
    SELECT j.*, g.nama_guru, g.foto 
    FROM tb_jadwal_pembina_upacara j 
    JOIN tb_guru g ON j.id_guru = g.id_guru 
    ORDER BY j.tanggal DESC, j.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$js_page = [];
if ($alert) {
    $icon  = $alert['type'] === 'success' ? 'success' : 'error';
    $title = $alert['type'] === 'success' ? 'Berhasil' : 'Gagal';
    $text  = json_encode($alert['text'], JSON_UNESCAPED_UNICODE);
    $js_page[] = "Swal.fire({icon:'{$icon}',title:'{$title}',text:{$text},timer:2000,showConfirmButton:false});";
}

$can_edit_js = $can_edit ? 'true' : 'false';
$js_page[] = <<<JS
$(function () {
    var canEdit = {$can_edit_js};
    var tableOpts = {
        paging: true,
        lengthChange: true,
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Semua']],
        order: [],
        language: {
            lengthMenu: "Tampilkan _MENU_ entri",
            zeroRecords: "Tidak ada data jadwal pembina upacara",
            info: "Menampilkan _START_ sampai _END_ dari _TOTAL_ entri",
            infoEmpty: "Menampilkan 0 sampai 0 dari 0 entri",
            infoFiltered: "(disaring dari _MAX_ total)",
            search: "Cari:",
            paginate: { first: "Pertama", last: "Terakhir", next: "Selanjutnya", previous: "Sebelumnya" }
        }
    };
    if (canEdit) {
        tableOpts.columnDefs = [{ sortable: false, targets: [3] }];
    }

    if ($.fn.DataTable.isDataTable('#table-upacara')) {
        $('#table-upacara').DataTable().destroy();
    }
    var t = $('#table-upacara').DataTable(tableOpts);

    t.on('draw', function () {
        var info = t.page.info();
        $('#table-upacara tbody tr').each(function (i) {
            $(this).find('td:first .badge-card-no').text(info.start + i + 1);
        });
    });

    $(document).on('click', '.btn-edit', function () {
        $('#edit_id').val($(this).data('id'));
        $('#edit_tanggal').val($(this).data('tanggal'));
        $('#edit_id_guru').val($(this).data('guru')).trigger('change');
        $('#edit_keterangan').val($(this).data('keterangan'));
        $('#editModal').modal('show');
    });

    $(document).on('click', '.btn-hapus', function () {
        var id = $(this).data('id'), nama = $(this).data('nama'), tgl = $(this).data('tanggal');
        Swal.fire({
            title: 'Konfirmasi Hapus',
            html: 'Hapus jadwal pembina upacara untuk <b>' + nama + '</b> (Tanggal: ' + tgl + ')?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then(function (r) {
            if (r.isConfirmed) {
                $('<form method="POST" action="">'+
                  '<input type="hidden" name="_action" value="delete">'+
                  '<input type="hidden" name="id" value="'+id+'">'+
                  '</form>').appendTo('body').submit();
            }
        });
    });
});
JS;

$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css'
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.full.min.js'
];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<link rel="stylesheet" href="../assets/css/simad-card-view.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/simad-card-view.css') ?>">
<style>
/* Tampilan grid 4 kolom hemat ruang untuk desktop */
@media (min-width: 576px) {
    .upacara-card-grid tbody {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 12px !important;
    }
}
@media (min-width: 992px) {
    .upacara-card-grid tbody {
        grid-template-columns: repeat(3, 1fr) !important;
        gap: 14px !important;
    }
}
@media (min-width: 1200px) {
    .upacara-card-grid tbody {
        grid-template-columns: repeat(4, 1fr) !important;
        gap: 14px !important;
    }
}
.upacara-card-grid tbody tr {
    padding: 12px 14px !important;
    border-radius: 10px !important;
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    box-shadow: 0 1px 4px rgba(0,0,0,0.03) !important;
}
.upacara-card-grid tbody tr:hover {
    border-color: #cbd5e1 !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.06) !important;
}
.upacara-card-grid tbody tr td {
    padding: 2px 0 !important;
}
.upacara-card-grid tbody tr td.simad-card-actions {
    margin-top: 8px !important;
    padding-top: 8px !important;
    border-top: 1px dashed #f1f5f9 !important;
}

.select2-container--default .select2-selection--single {
    height: 42px;
    border: 1px solid #e4e6fc;
    border-radius: 4px;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 40px;
    padding-left: 12px;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 40px;
}
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Jadwal Pembina Upacara</h1>
            <?php echo render_breadcrumb(); ?>
        </div>
        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-flag mr-2 text-danger"></i>Jadwal Pembina Upacara</h4>
                    <div class="card-header-action">
                        <a href="export_jadwal_pembina_upacara_excel.php<?= $session_type ? '?session_type=' . urlencode($session_type) : '' ?>" class="btn btn-success btn-icon icon-left">
                            <i class="fas fa-file-excel"></i> Ekspor Excel
                        </a>
                        <a href="cetak_jadwal_pembina_upacara.php<?= $session_type ? '?session_type=' . urlencode($session_type) : '' ?>" target="_blank" class="btn btn-danger btn-icon icon-left">
                            <i class="fas fa-file-pdf"></i> Ekspor PDF
                        </a>
                        <?php if ($can_edit): ?>
                        <button type="button" class="btn btn-primary btn-icon icon-left" data-toggle="modal" data-target="#addModal">
                            <i class="fas fa-plus"></i> Tambah Jadwal
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="simad-card-view-wrapper">
                        <div class="table-responsive">
                            <table class="table table-striped simad-card-view upacara-card-grid" id="table-upacara">
                                <thead>
                                    <tr>
                                        <th style="width:7%">No</th>
                                        <th style="width:25%">Tanggal</th>
                                        <th>Nama Guru</th>
                                        <?php if ($can_edit): ?>
                                        <th style="width:18%">Aksi</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $today_str = date('Y-m-d');
                                    foreach ($rows as $i => $r): 
                                        $tgl_formatted = formatDateIndonesia($r['tanggal']);
                                        $hari_name = getHariIndonesia($r['tanggal']);
                                        $is_today = $r['tanggal'] === $today_str;
                                        $is_past = $r['tanggal'] < $today_str;
                                    ?>
                                    <tr>
                                        <td class="simad-card-no">
                                            <span class="badge-card-no"><?= $i + 1 ?></span>
                                        </td>
                                        <td>
                                            <div class="font-weight-bold text-dark">
                                                <i class="far fa-calendar-alt text-primary mr-1"></i>
                                                <?= htmlspecialchars($hari_name . ', ' . $tgl_formatted) ?>
                                            </div>
                                            <?php if (!empty($r['keterangan'])): ?>
                                                <small class="text-muted d-block mt-1">
                                                    <i class="fas fa-info-circle mr-1"></i><?= htmlspecialchars($r['keterangan']) ?>
                                                </small>
                                            <?php endif; ?>
                                            <?php if ($is_today): ?>
                                                <span class="badge badge-success badge-pill mt-1" style="font-size:10px; background-color: #10b981 !important;">Hari Ini</span>
                                            <?php elseif ($is_past): ?>
                                                <span class="badge badge-secondary badge-pill mt-1" style="font-size:10px; background-color: #64748b !important;">Dilaksanakan</span>
                                            <?php else: ?>
                                                <span class="badge badge-primary badge-pill mt-1" style="font-size:10px; background-color: #2563eb !important;">Mendatang</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="simad-card-name">
                                            <div class="d-flex align-items-center" style="gap: 10px;">
                                                <span class="chip-avatar" style="width:36px;height:36px;border-radius:50%;overflow:hidden;display:inline-flex;align-items:center;justify-content:center;background:#e2e8f0;font-weight:bold;color:#475569;">
                                                    <?= getPersonAvatarHtml($r['foto'] ?? '', strtoupper(mb_substr(trim((string)$r['nama_guru']), 0, 1))) ?>
                                                </span>
                                                <div class="person-name font-weight-bold text-dark">
                                                    <?= htmlspecialchars($r['nama_guru']) ?>
                                                </div>
                                            </div>
                                        </td>
                                        <?php if ($can_edit): ?>
                                        <td class="simad-card-actions">
                                            <button type="button" class="btn btn-warning btn-sm btn-edit"
                                                data-id="<?= (int)$r['id'] ?>"
                                                data-tanggal="<?= htmlspecialchars($r['tanggal'], ENT_QUOTES) ?>"
                                                data-guru="<?= (int)$r['id_guru'] ?>"
                                                data-keterangan="<?= htmlspecialchars($r['keterangan'] ?? '', ENT_QUOTES) ?>">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm btn-hapus"
                                                data-id="<?= (int)$r['id'] ?>"
                                                data-tanggal="<?= htmlspecialchars($r['tanggal'], ENT_QUOTES) ?>"
                                                data-nama="<?= htmlspecialchars($r['nama_guru'], ENT_QUOTES) ?>">
                                                <i class="fas fa-trash"></i> Hapus
                                            </button>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php if ($can_edit): ?>
<!-- Modal Tambah -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus text-primary mr-1"></i> Tambah Jadwal Pembina Upacara</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="_action" value="add">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Tanggal Upacara <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="tanggal" required value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label>Nama Guru Pembina <span class="text-danger">*</span></label>
                        <select class="form-control" name="id_guru" required style="width: 100%;">
                            <option value="">-- Pilih Guru --</option>
                            <?php foreach ($all_guru as $g): ?>
                            <option value="<?= (int)$g['id_guru'] ?>"><?= htmlspecialchars($g['nama_guru']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Keterangan <small class="text-muted">(opsional)</small></label>
                        <input type="text" class="form-control" name="keterangan" placeholder="Contoh: Upacara Bendera Hari Senin / Hari Guru / dsb">
                    </div>
                </div>
                <div class="modal-footer bg-whitesmoke br">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit text-warning mr-1"></i> Edit Jadwal Pembina Upacara</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="_action" value="update">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Tanggal Upacara <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="tanggal" id="edit_tanggal" required>
                    </div>
                    <div class="form-group">
                        <label>Nama Guru Pembina <span class="text-danger">*</span></label>
                        <select class="form-control" name="id_guru" id="edit_id_guru" required style="width: 100%;">
                            <option value="">-- Pilih Guru --</option>
                            <?php foreach ($all_guru as $g): ?>
                            <option value="<?= (int)$g['id_guru'] ?>"><?= htmlspecialchars($g['nama_guru']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Keterangan <small class="text-muted">(opsional)</small></label>
                        <input type="text" class="form-control" name="keterangan" id="edit_keterangan" placeholder="Contoh: Upacara Bendera Hari Senin">
                    </div>
                </div>
                <div class="modal-footer bg-whitesmoke br">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Update</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include '../templates/footer.php'; ?>
