<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

ensureTbJabatanMaster($pdo);

$page_title = 'Data Jabatan';

$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_jabatan'])) {
        $nama = sanitizeInput((string)($_POST['nama_jabatan'] ?? ''));
        if ($nama === '') {
            $message = ['type' => 'danger', 'text' => 'Nama jabatan wajib diisi.'];
        } else {
            $chk = $pdo->prepare("SELECT id_jabatan FROM tb_jabatan WHERE LOWER(nama_jabatan) = LOWER(?) LIMIT 1");
            $chk->execute([$nama]);
            if ($chk->fetch()) {
                $message = ['type' => 'danger', 'text' => 'Jabatan sudah ada.'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO tb_jabatan (nama_jabatan) VALUES (?)");
                if ($stmt->execute([$nama])) {
                    $message = ['type' => 'success', 'text' => 'Jabatan berhasil ditambahkan.'];
                    logActivity($pdo, $_SESSION['username'] ?? 'system', 'Tambah Jabatan', "Tambah jabatan: $nama");
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal menambahkan jabatan.'];
                }
            }
        }
    } elseif (isset($_POST['update_jabatan'])) {
        $id = (int)($_POST['id_jabatan'] ?? 0);
        $nama = sanitizeInput((string)($_POST['nama_jabatan'] ?? ''));
        if ($id <= 0 || $nama === '') {
            $message = ['type' => 'danger', 'text' => 'Data tidak valid.'];
        } else {
            $chk = $pdo->prepare("SELECT id_jabatan FROM tb_jabatan WHERE LOWER(nama_jabatan) = LOWER(?) AND id_jabatan != ? LIMIT 1");
            $chk->execute([$nama, $id]);
            if ($chk->fetch()) {
                $message = ['type' => 'danger', 'text' => 'Jabatan sudah ada.'];
            } else {
                $stmt = $pdo->prepare("UPDATE tb_jabatan SET nama_jabatan = ? WHERE id_jabatan = ?");
                if ($stmt->execute([$nama, $id])) {
                    $message = ['type' => 'success', 'text' => 'Jabatan berhasil diperbarui.'];
                    logActivity($pdo, $_SESSION['username'] ?? 'system', 'Update Jabatan', "Update jabatan ID $id menjadi: $nama");
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal memperbarui jabatan.'];
                }
            }
        }
    } elseif (isset($_POST['delete_jabatan'])) {
        $id = (int)($_POST['id_jabatan'] ?? 0);
        if ($id <= 0) {
            $message = ['type' => 'danger', 'text' => 'ID tidak valid.'];
        } else {
            $cnt = 0;
            try {
                if (dbColumnExists($pdo, 'tb_guru', 'jabatan')) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tb_guru WHERE jabatan = (SELECT nama_jabatan FROM tb_jabatan WHERE id_jabatan = ? LIMIT 1)");
                    $stmt->execute([$id]);
                    $cnt = (int)$stmt->fetchColumn();
                }
            } catch (Throwable $e) { $cnt = 0; }
            if ($cnt > 0) {
                $message = ['type' => 'danger', 'text' => "Tidak bisa dihapus. Masih dipakai $cnt guru."];
            } else {
                $nameStmt = $pdo->prepare("SELECT nama_jabatan FROM tb_jabatan WHERE id_jabatan = ? LIMIT 1");
                $nameStmt->execute([$id]);
                $nm = (string)($nameStmt->fetchColumn() ?: $id);
                $stmt = $pdo->prepare("DELETE FROM tb_jabatan WHERE id_jabatan = ?");
                if ($stmt->execute([$id])) {
                    $message = ['type' => 'success', 'text' => 'Jabatan berhasil dihapus.'];
                    logActivity($pdo, $_SESSION['username'] ?? 'system', 'Hapus Jabatan', "Hapus jabatan: $nm");
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal menghapus jabatan.'];
                }
            }
        }
    }
}

$rows = [];
try {
    $rows = $pdo->query("SELECT * FROM tb_jabatan ORDER BY nama_jabatan ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $rows = []; }

$css_libs = ['https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'];
$js_libs = ['https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js', 'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js'];

$js_page = [];
if ($message) {
    $swal_icon = ($message['type'] ?? '') === 'success' ? 'success' : (($message['type'] ?? '') === 'danger' ? 'error' : 'warning');
    $swal_title = ($message['type'] ?? '') === 'success' ? 'Berhasil' : 'Gagal';
    $swal_text = json_encode((string)($message['text'] ?? ''), JSON_UNESCAPED_UNICODE);
    $swal_timer = $swal_icon === 'success' ? '2000' : '3000';
    $js_page[] = "Swal.fire({icon:'{$swal_icon}',title:'{$swal_title}',text:{$swal_text},timer:{$swal_timer},showConfirmButton:false});";
}
$js_page[] = <<<'JS'
$(document).ready(function () {
    if ($.fn.DataTable.isDataTable('#table-1')) { $('#table-1').DataTable().destroy(); }
    var t = $('#table-1').DataTable({
        columnDefs: [{ sortable: false, targets: [2] }],
        paging: true, lengthChange: true, pageLength: 10,
        lengthMenu: [[10,25,50,100,-1],[10,25,50,100,'Semua']],
        language: {
            lengthMenu: "Tampilkan _MENU_ entri", zeroRecords: "Tidak ada data", info: "Menampilkan _START_ sampai _END_ dari _TOTAL_ entri",
            infoEmpty: "Menampilkan 0 sampai 0 dari 0 entri", infoFiltered: "(disaring dari _MAX_ total)", search: "Cari:",
            paginate: { first: "Pertama", last: "Terakhir", next: "Selanjutnya", previous: "Sebelumnya" }
        }
    });
    t.on('draw', function () {
        var info = t.page.info();
        $('#table-1 tbody tr').each(function (i) { $(this).find('td:first').text(info.start + i + 1); });
    });
    t.draw();
    $(document).on('click', '.btn-edit', function () {
        $('#edit_id_jabatan').val($(this).data('id'));
        $('#edit_nama_jabatan').val($(this).data('nama'));
        $('#editModal').modal('show');
    });
    $(document).on('click', '.btn-hapus', function (e) {
        e.preventDefault();
        var id = $(this).data('id'), nama = $(this).data('nama');
        Swal.fire({
            title: 'Konfirmasi Hapus', text: 'Hapus jabatan "' + nama + '"?', icon: 'warning',
            showCancelButton: true, confirmButtonColor: '#3085d6', cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus!', cancelButtonText: 'Batal'
        }).then(function (r) {
            if (r.isConfirmed) {
                var f = $('<form method="POST" action=""><input type="hidden" name="id_jabatan" value="' + id + '"><input type="hidden" name="delete_jabatan" value="1"></form>');
                $('body').append(f); f.submit();
            }
        });
    });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Data Jabatan</h1>
            <?php echo render_breadcrumb(); ?>
        </div>
        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4>Daftar Jabatan</h4>
                    <div class="card-header-action">
                        <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addModal"><i class="fas fa-plus"></i> Tambah Jabatan</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-1">
                            <thead>
                                <tr><th width="8%">No</th><th>Nama Jabatan</th><th width="18%">Aksi</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="text-center"></td>
                                        <td><?= htmlspecialchars($r['nama_jabatan']) ?></td>
                                        <td>
                                            <button class="btn btn-warning btn-sm btn-edit" data-id="<?= (int)$r['id_jabatan'] ?>" data-nama="<?= htmlspecialchars($r['nama_jabatan'], ENT_QUOTES) ?>"><i class="fas fa-edit"></i></button>
                                            <button class="btn btn-danger btn-sm btn-hapus" data-id="<?= (int)$r['id_jabatan'] ?>" data-nama="<?= htmlspecialchars($r['nama_jabatan'], ENT_QUOTES) ?>"><i class="fas fa-trash"></i></button>
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

<div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Jabatan</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="add_jabatan" value="1">
                    <div class="form-group">
                        <label>Nama Jabatan</label>
                        <input type="text" class="form-control" name="nama_jabatan" required placeholder="cth: Guru Kelas, Kepala Madrasah">
                    </div>
                </div>
                <div class="modal-footer bg-whitesmoke br">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Jabatan</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="id_jabatan" id="edit_id_jabatan">
                    <input type="hidden" name="update_jabatan" value="1">
                    <div class="form-group">
                        <label>Nama Jabatan</label>
                        <input type="text" class="form-control" name="nama_jabatan" id="edit_nama_jabatan" required>
                    </div>
                </div>
                <div class="modal-footer bg-whitesmoke br">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php include '../templates/footer.php'; ?>
