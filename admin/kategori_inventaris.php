<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

// Get school profile
$school_profile = getSchoolProfile($pdo);

// Set page title
$page_title = 'Kategori Inventaris';

// Get user level
$user_level = getUserLevel();
$is_admin = ($user_level === 'admin');

// Handle Form Submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $is_admin) {
    if (isset($_POST['action'])) {
        try {
            if ($_POST['action'] == 'add') {
                $stmt = $pdo->prepare("INSERT INTO tb_kategori_inventaris (nama_kategori) VALUES (?)");
                $stmt->execute([$_POST['nama_kategori']]);
                $message = ['type' => 'success', 'text' => 'Kategori berhasil ditambahkan!'];
            } elseif ($_POST['action'] == 'edit') {
                $stmt = $pdo->prepare("UPDATE tb_kategori_inventaris SET nama_kategori = ? WHERE id = ?");
                $stmt->execute([$_POST['nama_kategori'], $_POST['id']]);
                $message = ['type' => 'success', 'text' => 'Kategori berhasil diperbarui!'];
            } elseif ($_POST['action'] == 'delete') {
                $stmt = $pdo->prepare("DELETE FROM tb_kategori_inventaris WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                $message = ['type' => 'success', 'text' => 'Kategori berhasil dihapus!'];
            }
            // Refresh data after change
            header("Location: kategori_inventaris.php?msg=" . urlencode($message['text']));
            exit;
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Terjadi kesalahan: ' . $e->getMessage()];
        }
    }
}

if (isset($_GET['msg'])) {
    $message = ['type' => 'success', 'text' => $_GET['msg']];
}

// Get Categories Data
$stmt = $pdo->query("SELECT * FROM tb_kategori_inventaris ORDER BY nama_kategori ASC");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Add DataTables CSS and JS
if (!isset($css_libs)) $css_libs = [];
$css_libs[] = 'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css';
if (!isset($js_libs)) $js_libs = [];
$js_libs[] = 'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js';
$js_libs[] = 'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js';

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Kategori Inventaris Sarpras</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Kategori Inventaris</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Daftar Kategori Inventaris</h4>
                            <div class="card-header-action">
                                <?php if ($is_admin): ?>
                                <button class="btn btn-primary" data-toggle="modal" data-target="#modalAdd">
                                    <i class="fas fa-plus"></i> Tambah Kategori
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-bordered" id="table-kategori">
                                    <thead>
                                        <tr>
                                            <th class="text-center" width="10%">NO</th>
                                            <th class="text-center">NAMA KATEGORI</th>
                                            <?php if ($is_admin): ?>
                                            <th class="text-center" width="20%">AKSI</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($categories) > 0): ?>
                                            <?php foreach ($categories as $idx => $row): ?>
                                            <tr>
                                                <td class="text-center"><?php echo $idx + 1; ?></td>
                                                <td><?php echo htmlspecialchars($row['nama_kategori']); ?></td>
                                                <?php if ($is_admin): ?>
                                                <td class="text-center">
                                                    <button class="btn btn-warning btn-sm btn-edit" 
                                                            data-id="<?php echo $row['id']; ?>" 
                                                            data-nama="<?php echo htmlspecialchars($row['nama_kategori']); ?>"
                                                            data-toggle="modal" 
                                                            data-target="#modalEdit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-danger btn-sm btn-delete" 
                                                            data-id="<?php echo $row['id']; ?>" 
                                                            data-nama="<?php echo htmlspecialchars($row['nama_kategori']); ?>"
                                                            data-toggle="modal" 
                                                            data-target="#modalDelete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </td>
                                                <?php endif; ?>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="<?php echo $is_admin ? '3' : '2'; ?>" class="text-center">Tidak ada data kategori</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Add Modal -->
<?php if ($is_admin): ?>
<div class="modal fade" id="modalAdd" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Kategori</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label>Nama Kategori</label>
                        <input type="text" class="form-control" name="nama_kategori" placeholder="Masukkan nama kategori" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="modalEdit" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Kategori</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="form-group">
                        <label>Nama Kategori</label>
                        <input type="text" class="form-control" name="nama_kategori" id="edit_nama_kategori" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="modalDelete" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Hapus Kategori</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="delete_id">
                    <p>Apakah Anda yakin ingin menghapus kategori <strong id="delete_nama_kategori"></strong>?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger">Hapus</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include '../templates/footer.php'; ?>

<script>
<?php if ($message): ?>
Swal.fire({ icon: '<?php echo $message['type'] == 'danger' ? 'error' : 'success'; ?>', title: '<?php echo $message['text']; ?>', timer: 2000, showConfirmButton: false });
<?php endif; ?>

$(document).ready(function() {
    // Initialize DataTable
    $('#table-kategori').DataTable();
    
    // Handle Edit Button Click
    $('.btn-edit').on('click', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        
        $('#edit_id').val(id);
        $('#edit_nama_kategori').val(nama);
    });
    
    // Handle Delete Button Click
    $('.btn-delete').on('click', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        
        $('#delete_id').val(id);
        $('#delete_nama_kategori').text(nama);
    });
});
</script>
