<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) session_start();

if (!isAuthorized(['admin', 'tata_usaha', 'kepala_madrasah', 'guru', 'wali'])) {
    redirect('../login.php');
}

$can_edit = isAuthorized(['admin', 'tata_usaha']);

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_jadwal_piket (
        id INT AUTO_INCREMENT PRIMARY KEY,
        hari VARCHAR(20) NOT NULL UNIQUE,
        id_guru TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {
    error_log("Create table error: " . $e->getMessage());
}

$hari_list = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Ahad'];

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $hari = $_POST['hari'];
        $id_guru = isset($_POST['id_guru']) ? implode(',', array_map('intval', (array)$_POST['id_guru'])) : '';

        if (!$hari || !$id_guru) {
            $error = 'Data tidak lengkap.';
        } else {
            if ($action === 'add') {
                $stmt = $pdo->prepare("INSERT INTO tb_jadwal_piket (hari, id_guru) VALUES (?, ?)");
                $stmt->execute([$hari, $id_guru]);
                $_SESSION['flash'] = 'Jadwal piket ' . $hari . ' berhasil ditambahkan.';
            } else {
                $id = (int)$_POST['id'];
                $stmt = $pdo->prepare("UPDATE tb_jadwal_piket SET hari=?, id_guru=? WHERE id=?");
                $stmt->execute([$hari, $id_guru, $id]);
                $_SESSION['flash'] = 'Jadwal piket ' . $hari . ' berhasil diubah.';
            }
            header('Location: jadwal_piket.php');
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM tb_jadwal_piket WHERE id=?");
        $stmt->execute([$id]);
        $_SESSION['flash'] = 'Jadwal piket berhasil dihapus.';
        header('Location: jadwal_piket.php');
        exit;
    }
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// Fetch data
$stmt = $pdo->query("SELECT * FROM tb_jadwal_piket ORDER BY id ASC");
$jadwal = $stmt->fetchAll(PDO::FETCH_ASSOC);

$jadwal_by_hari = [];
foreach ($jadwal as $j) {
    $jadwal_by_hari[$j['hari']] = $j;
}

// All teachers for dropdown
$stmt_g = $pdo->query("SELECT id_guru, nama_guru FROM tb_guru ORDER BY nama_guru ASC");
$all_guru = $stmt_g->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Jadwal Guru Piket';
$css_libs = ['assets/vendor/select2/css/select2.min.css'];
$js_libs = ['https://cdn.jsdelivr.net/npm/sweetalert2@11', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js'];

$js_page = ["
$(document).ready(function() {
    $('.select2-multiple').select2({ placeholder: '-- Pilih Guru --', allowClear: true, width: '100%' });
    $('#editModal').on('show.bs.modal', function(e) {
        var btn = $(e.relatedTarget);
        $('#edit_id').val(btn.data('id'));
        $('#edit_hari').val(btn.data('hari'));
        var ids = (btn.data('guru') || '').split(',').map(Number);
        $('#edit_guru').val(ids).trigger('change');
    });
    $('#addModal').on('shown.bs.modal', function() {
        $('#add_guru').select2({ placeholder: '-- Pilih Guru --', allowClear: true, width: '100%', dropdownParent: $('#addModal') });
    });
    $('#editModal').on('shown.bs.modal', function() {
        $('#edit_guru').select2({ placeholder: '-- Pilih Guru --', allowClear: true, width: '100%', dropdownParent: $('#editModal') });
    });
});
var flashMsg = " . json_encode($flash) . ";
if (flashMsg) { Swal.fire({ icon: 'success', title: 'Berhasil', text: flashMsg, timer: 2000, showConfirmButton: false }); }
"];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><i class="fas fa-clipboard-list mr-2"></i>Jadwal Guru Piket</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4>Daftar Jadwal Piket Harian</h4>
                    <div class="card-header-action">
                        <?php if ($can_edit): ?>
                        <button class="btn btn-primary" data-toggle="modal" data-target="#addModal"><i class="fas fa-plus"></i> Tambah Jadwal</button>
                        <?php endif; ?>
                        <?php if (!empty($jadwal)): ?>
                        <div class="btn-group btn-pill ml-2 overflow-hidden" style="border-radius: 30px;">
                            <button type="button" class="btn btn-danger px-3" onclick="exportToPDF()" style="background-color:#ff5e5e;border:none;border-top-left-radius:30px;border-bottom-left-radius:30px;"><i class="fas fa-file-pdf"></i> PDF</button>
                            <button type="button" class="btn btn-success px-3" onclick="exportToExcel()" style="background-color:#47c363;border:none;border-top-right-radius:30px;border-bottom-right-radius:30px;"><i class="fas fa-file-excel"></i> Excel</button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th class="text-center" style="width:60px">No</th>
                                    <th style="width:120px">Hari</th>
                                    <th>Nama Guru</th>
                                    <?php if ($can_edit): ?>
                                    <th class="text-center" style="width:100px">Aksi</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($jadwal)): ?>
                                <tr><td colspan="<?= $can_edit ? 4 : 3 ?>" class="text-center text-muted py-4">Belum ada jadwal piket. Tambah jadwal baru.</td></tr>
                                <?php else: ?>
                                <?php $no = 1; foreach ($jadwal as $j): ?>
                                <tr>
                                    <td class="text-center"><?= $no++ ?></td>
                                    <td><strong><?= htmlspecialchars($j['hari']) ?></strong></td>
                                    <td>
                                        <?php
                                        $ids = array_filter(explode(',', $j['id_guru']));
                                        $nama_guru = [];
                                        foreach ($ids as $id) {
                                            foreach ($all_guru as $g) {
                                                if ($g['id_guru'] == $id) {
                                                    $nama_guru[] = htmlspecialchars($g['nama_guru']);
                                                    break;
                                                }
                                            }
                                        }
                                        echo implode(', ', $nama_guru);
                                        ?>
                                    </td>
                                    <?php if ($can_edit): ?>
                                    <td class="text-center" style="white-space:nowrap">
                                        <button class="btn btn-sm btn-warning" data-toggle="modal" data-target="#editModal"
                                            data-id="<?= $j['id'] ?>"
                                            data-hari="<?= $j['hari'] ?>"
                                            data-guru="<?= $j['id_guru'] ?>"><i class="fas fa-edit"></i></button>
                                        <form method="POST" style="display:inline" class="delete-form">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $j['id'] ?>">
                                            <button class="btn btn-sm btn-danger delete-btn" type="button"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!empty($jadwal)): ?>
                    <div id="exportTableContainer" style="display:none">
                        <table border="1">
                            <thead>
                                <tr><th colspan="3" style="text-align:center;font-size:16px;font-weight:bold;">JADWAL GURU PIKET</th></tr>
                                <tr><th>No</th><th>Hari</th><th>Nama Guru</th></tr>
                            </thead>
                            <tbody>
                                <?php $no2 = 1; foreach ($jadwal as $j): ?>
                                <?php
                                $ids = array_filter(explode(',', $j['id_guru']));
                                $nama_guru = [];
                                foreach ($ids as $id) {
                                    foreach ($all_guru as $g) {
                                        if ($g['id_guru'] == $id) {
                                            $nama_guru[] = $g['nama_guru'];
                                            break;
                                        }
                                    }
                                }
                                ?>
                                <tr><td><?= $no2++ ?></td><td><?= htmlspecialchars($j['hari']) ?></td><td><?= htmlspecialchars(implode(', ', $nama_guru)) ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <form id="exportForm" method="POST" action="" target="_blank">
                        <input type="hidden" name="table_data" id="table_data">
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Tambah Jadwal Piket</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label>Hari</label>
                        <select name="hari" class="form-control" required>
                            <option value="">-- Pilih Hari --</option>
                            <?php foreach ($hari_list as $h): ?>
                            <option value="<?= $h ?>"><?= $h ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nama Guru</label>
                        <select name="id_guru[]" id="add_guru" class="form-control select2-multiple" multiple required>
                            <?php foreach ($all_guru as $g): ?>
                            <option value="<?= $g['id_guru'] ?>"><?= htmlspecialchars($g['nama_guru']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Edit Jadwal Piket</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="form-group">
                        <label>Hari</label>
                        <select name="hari" id="edit_hari" class="form-control" required>
                            <option value="">-- Pilih Hari --</option>
                            <?php foreach ($hari_list as $h): ?>
                            <option value="<?= $h ?>"><?= $h ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nama Guru</label>
                        <select name="id_guru[]" id="edit_guru" class="form-control select2-multiple" multiple required>
                            <?php foreach ($all_guru as $g): ?>
                            <option value="<?= $g['id_guru'] ?>"><?= htmlspecialchars($g['nama_guru']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php
$js_page[] = "
$(document).on('click', '.delete-btn', function() {
    var form = $(this).closest('form');
    Swal.fire({
        title: 'Hapus Jadwal?',
        text: 'Data tidak bisa dikembalikan.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Ya, Hapus!',
        cancelButtonText: 'Batal'
    }).then(function(r) { if (r.isConfirmed) form.submit(); });
});

function exportToPDF() {
    var html = document.getElementById('exportTableContainer').innerHTML;
    document.getElementById('table_data').value = html;
    document.getElementById('exportForm').action = 'export_jadwal_piket_pdf.php';
    document.getElementById('exportForm').submit();
}
function exportToExcel() {
    var html = document.getElementById('exportTableContainer').innerHTML;
    document.getElementById('table_data').value = html;
    document.getElementById('exportForm').action = 'export_jadwal_piket_excel.php';
    document.getElementById('exportForm').submit();
}
";
include '../templates/footer.php';
?>
