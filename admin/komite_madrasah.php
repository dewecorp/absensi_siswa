<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$session_type = sanitizeInput((string)($_GET['session_type'] ?? ''));
$allowed = ['admin'];
if ($session_type === 'kepala_madrasah') $allowed[] = 'kepala_madrasah';
if (!isAuthorized($allowed)) redirect('../login.php');

// Buat tabel jika belum ada
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_komite_madrasah (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        nama        VARCHAR(150) NOT NULL,
        jabatan     VARCHAR(100) NOT NULL DEFAULT '',
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) { /* tabel sudah ada */ }

$page_title = 'Komite Madrasah';

$alert = null;

// CRUD —————————————————————————————————————————
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitizeInput((string)($_POST['_action'] ?? ''));

    if ($action === 'add') {
        $nama    = trim(sanitizeInput((string)($_POST['nama']    ?? '')));
        $jabatan = trim(sanitizeInput((string)($_POST['jabatan'] ?? '')));
        if ($nama === '' || $jabatan === '') {
            $alert = ['type' => 'danger', 'text' => 'Nama dan jabatan wajib diisi.'];
        } else {
            $pdo->prepare("INSERT INTO tb_komite_madrasah (nama, jabatan) VALUES (?, ?)")->execute([$nama, $jabatan]);
            logActivity($pdo, $_SESSION['username'] ?? 'system', 'Tambah Komite', "Tambah: $nama ($jabatan)");
            $alert = ['type' => 'success', 'text' => 'Data komite berhasil ditambahkan.'];
        }

    } elseif ($action === 'update') {
        $id      = (int)($_POST['id'] ?? 0);
        $nama    = trim(sanitizeInput((string)($_POST['nama']    ?? '')));
        $jabatan = trim(sanitizeInput((string)($_POST['jabatan'] ?? '')));
        if ($id <= 0 || $nama === '' || $jabatan === '') {
            $alert = ['type' => 'danger', 'text' => 'Data tidak valid.'];
        } else {
            $pdo->prepare("UPDATE tb_komite_madrasah SET nama=?, jabatan=? WHERE id=?")->execute([$nama, $jabatan, $id]);
            logActivity($pdo, $_SESSION['username'] ?? 'system', 'Edit Komite', "Edit ID $id: $nama ($jabatan)");
            $alert = ['type' => 'success', 'text' => 'Data berhasil diperbarui.'];
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $st = $pdo->prepare("SELECT nama FROM tb_komite_madrasah WHERE id=? LIMIT 1");
            $st->execute([$id]);
            $nm = (string)($st->fetchColumn() ?: $id);
            $pdo->prepare("DELETE FROM tb_komite_madrasah WHERE id=?")->execute([$id]);
            logActivity($pdo, $_SESSION['username'] ?? 'system', 'Hapus Komite', "Hapus: $nm");
            $alert = ['type' => 'success', 'text' => 'Data berhasil dihapus.'];
        }
    }
}

$rows = $pdo->query("SELECT * FROM tb_komite_madrasah ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// JS alert
$js_page = [];
if ($alert) {
    $icon  = $alert['type'] === 'success' ? 'success' : 'error';
    $title = $alert['type'] === 'success' ? 'Berhasil' : 'Gagal';
    $text  = json_encode($alert['text'], JSON_UNESCAPED_UNICODE);
    $js_page[] = "Swal.fire({icon:'{$icon}',title:'{$title}',text:{$text},timer:2000,showConfirmButton:false});";
}
$js_page[] = <<<'JS'
$(function () {
    if ($.fn.DataTable.isDataTable('#table-komite')) {
        $('#table-komite').DataTable().destroy();
    }
    var t = $('#table-komite').DataTable({
        columnDefs: [{ sortable: false, targets: [-1] }],
        paging: true,
        lengthChange: true,
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Semua']],
        language: {
            lengthMenu: "Tampilkan _MENU_ entri",
            zeroRecords: "Tidak ada data",
            info: "Menampilkan _START_ sampai _END_ dari _TOTAL_ entri",
            infoEmpty: "Menampilkan 0 sampai 0 dari 0 entri",
            infoFiltered: "(disaring dari _MAX_ total)",
            search: "Cari:",
            paginate: { first: "Pertama", last: "Terakhir", next: "Selanjutnya", previous: "Sebelumnya" }
        }
    });
    t.on('draw', function () {
        var info = t.page.info();
        $('#table-komite tbody tr').each(function (i) {
            $(this).find('td:first .badge-card-no').text(info.start + i + 1);
        });
    });

    // edit button → isi modal
    $(document).on('click', '.btn-edit', function () {
        $('#edit_id').val($(this).data('id'));
        $('#edit_nama').val($(this).data('nama'));
        $('#edit_jabatan').val($(this).data('jabatan'));
        $('#editModal').modal('show');
    });
    // hapus button
    $(document).on('click', '.btn-hapus', function () {
        var id = $(this).data('id'), nama = $(this).data('nama');
        Swal.fire({
            title: 'Konfirmasi Hapus',
            html: 'Hapus anggota komite <b>' + nama + '</b>?',
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

$css_libs = ['https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'];
$js_libs  = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<link rel="stylesheet" href="../assets/css/simad-card-view.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/simad-card-view.css') ?>">

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Komite Madrasah</h1>
            <?php echo render_breadcrumb(); ?>
        </div>
        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-users-cog mr-2 text-primary"></i>Pengurus Komite Madrasah</h4>
                    <div class="card-header-action">
                        <a href="export_komite_excel.php<?= $session_type ? '?session_type=' . urlencode($session_type) : '' ?>" class="btn btn-success btn-icon icon-left">
                            <i class="fas fa-file-excel"></i> Ekspor Excel
                        </a>
                        <a href="cetak_komite_madrasah.php<?= $session_type ? '?session_type=' . urlencode($session_type) : '' ?>" target="_blank" class="btn btn-danger btn-icon icon-left">
                            <i class="fas fa-file-pdf"></i> Ekspor PDF
                        </a>
                        <?php if (isAuthorized(['admin'])): ?>
                        <button type="button" class="btn btn-primary btn-icon icon-left" data-toggle="modal" data-target="#addModal">
                            <i class="fas fa-plus"></i> Tambah Anggota
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="simad-card-view-wrapper">
                        <div class="table-responsive">
                            <table class="table table-striped simad-card-view" id="table-komite">
                                <thead>
                                    <tr>
                                        <th style="width:7%">No</th>
                                        <th>Nama</th>
                                        <th>Jabatan</th>
                                        <?php if (isAuthorized(['admin'])): ?>
                                        <th style="width:18%">Aksi</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $i => $r): ?>
                                    <tr>
                                        <td class="simad-card-no">
                                            <span class="badge-card-no"><?= $i + 1 ?></span>
                                        </td>
                                        <td class="simad-card-name">
                                            <div class="person-name"><?= htmlspecialchars($r['nama']) ?></div>
                                        </td>
                                        <td>
                                            <span class="badge badge-primary badge-pill px-2 py-1" style="font-size:12px;">
                                                <?= htmlspecialchars($r['jabatan']) ?>
                                            </span>
                                        </td>
                                        <?php if (isAuthorized(['admin'])): ?>
                                        <td class="simad-card-actions">
                                            <button class="btn btn-warning btn-sm btn-edit"
                                                data-id="<?= (int)$r['id'] ?>"
                                                data-nama="<?= htmlspecialchars($r['nama'], ENT_QUOTES) ?>"
                                                data-jabatan="<?= htmlspecialchars($r['jabatan'], ENT_QUOTES) ?>">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn btn-danger btn-sm btn-hapus"
                                                data-id="<?= (int)$r['id'] ?>"
                                                data-nama="<?= htmlspecialchars($r['nama'], ENT_QUOTES) ?>">
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

<?php if (isAuthorized(['admin'])): ?>
<!-- Modal Tambah -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Anggota Komite</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="_action" value="add">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nama <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nama" required placeholder="Nama lengkap anggota komite">
                    </div>
                    <div class="form-group">
                        <label>Jabatan <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="jabatan" required placeholder="cth: Ketua, Sekretaris, Bendahara, Anggota">
                    </div>
                </div>
                <div class="modal-footer bg-whitesmoke br">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Simpan</button>
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
                <h5 class="modal-title">Edit Anggota Komite</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="_action" value="update">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nama <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nama" id="edit_nama" required>
                    </div>
                    <div class="form-group">
                        <label>Jabatan <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="jabatan" id="edit_jabatan" required>
                    </div>
                </div>
                <div class="modal-footer bg-whitesmoke br">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Update</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include '../templates/footer.php'; ?>
