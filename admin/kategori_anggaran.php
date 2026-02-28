<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check authorization
if (!isAuthorized(['admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}
$is_admin = isAuthorized(['admin']);

// Get school profile
$school_profile = getSchoolProfile($pdo);
$school_name = strtoupper($school_profile['nama_madrasah'] ?? 'Sistem Absensi Siswa');

// Page title
$page_title = 'Kategori Anggaran';

// Create table if not exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_kategori_anggaran (
        id_kategori INT PRIMARY KEY AUTO_INCREMENT,
        nama_kategori VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    // Silent fail or log error
    error_log("Error creating table: " . $e->getMessage());
}

// Handle Form Submissions
$message = '';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if flash message exists
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!$is_admin) {
        die('Unauthorized');
    }
    $redirect_url = $_SERVER['PHP_SELF'];

    if (isset($_POST['add_kategori'])) {
        $nama_kategori = trim($_POST['nama_kategori']);
        
        if (!empty($nama_kategori)) {
            // Cek duplikat
            $check = $pdo->prepare("SELECT COUNT(*) FROM tb_kategori_anggaran WHERE nama_kategori = ?");
            $check->execute([$nama_kategori]);
            if ($check->fetchColumn() > 0) {
                $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Nama kategori sudah ada!'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO tb_kategori_anggaran (nama_kategori) VALUES (?)");
                if ($stmt->execute([$nama_kategori])) {
                    $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Kategori berhasil ditambahkan!'];
                    logActivity($pdo, $_SESSION['username'] ?? 'system', 'Tambah Kategori Anggaran', "Menambahkan kategori $nama_kategori");
                } else {
                    $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Gagal menambahkan kategori!'];
                }
            }
        } else {
            $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Nama kategori tidak boleh kosong!'];
        }
    } elseif (isset($_POST['update_kategori'])) {
        $id_kategori = $_POST['id_kategori'];
        $nama_kategori = trim($_POST['nama_kategori']);
        
        if (!empty($nama_kategori)) {
            // Cek duplikat
            $check = $pdo->prepare("SELECT COUNT(*) FROM tb_kategori_anggaran WHERE nama_kategori = ? AND id_kategori != ?");
            $check->execute([$nama_kategori, $id_kategori]);
            if ($check->fetchColumn() > 0) {
                $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Nama kategori sudah ada!'];
            } else {
                $stmt = $pdo->prepare("UPDATE tb_kategori_anggaran SET nama_kategori = ? WHERE id_kategori = ?");
                if ($stmt->execute([$nama_kategori, $id_kategori])) {
                    $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Kategori berhasil diupdate!'];
                    logActivity($pdo, $_SESSION['username'] ?? 'system', 'Update Kategori Anggaran', "Update kategori ID $id_kategori menjadi $nama_kategori");
                } else {
                    $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Gagal mengupdate kategori!'];
                }
            }
        } else {
            $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Nama kategori tidak boleh kosong!'];
        }
    } elseif (isset($_POST['delete_kategori'])) {
        $id_kategori = $_POST['id_kategori'];
        
        // Get name for log
        $stmt = $pdo->prepare("SELECT nama_kategori FROM tb_kategori_anggaran WHERE id_kategori = ?");
        $stmt->execute([$id_kategori]);
        $data = $stmt->fetch();
        $nama_kategori = $data ? $data['nama_kategori'] : 'Unknown';

        $stmt = $pdo->prepare("DELETE FROM tb_kategori_anggaran WHERE id_kategori = ?");
        if ($stmt->execute([$id_kategori])) {
            $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Kategori berhasil dihapus!'];
            logActivity($pdo, $_SESSION['username'] ?? 'system', 'Hapus Kategori Anggaran', "Menghapus kategori $nama_kategori");
        } else {
            $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Gagal menghapus kategori!'];
        }
    }
    
    // Redirect to prevent form resubmission and show flash message
    header("Location: $redirect_url");
    exit();
}

// Fetch Data
$stmt = $pdo->query("SELECT * FROM tb_kategori_anggaran ORDER BY id_kategori ASC");
$kategori_anggaran = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Define CSS libraries for this page
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'https://cdn.datatables.net/select/1.3.3/css/select.bootstrap4.min.css'
];

// Define JS libraries for this page
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.datatables.net/select/1.3.3/js/dataTables.select.min.js'
];

// Include header
include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Kategori Anggaran</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="#">Dashboard</a></div>
                <div class="breadcrumb-item"><a href="#">Keuangan</a></div>
                <div class="breadcrumb-item">Kategori Anggaran</div>
            </div>
        </div>

        <div class="section-body">
            <h2 class="section-title">Data Kategori Anggaran</h2>
            <p class="section-lead">
                Kelola data kategori anggaran untuk RAB.
            </p>

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Daftar Kategori</h4>
                            <div class="card-header-action">
                                <?php if ($is_admin): ?>
                                <button class="btn btn-primary" data-toggle="modal" data-target="#addModal">
                                    <i class="fas fa-plus"></i> Tambah Kategori
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped" id="table-1">
                                    <thead>
                                        <tr>
                                            <th class="text-center" width="5%">No</th>
                                            <th>Nama Kategori</th>
                                            <?php if ($is_admin): ?>
                                            <th width="15%">Aksi</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($kategori_anggaran as $index => $row): ?>
                                        <tr>
                                            <td class="text-center"><?= $index + 1 ?></td>
                                            <td><?= htmlspecialchars($row['nama_kategori']) ?></td>
                                            <?php if ($is_admin): ?>
                                            <td>
                                                <button class="btn btn-warning btn-sm edit-btn" 
                                                    data-id="<?= $row['id_kategori'] ?>" 
                                                    data-nama="<?= htmlspecialchars($row['nama_kategori']) ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-danger btn-sm delete-btn" 
                                                    data-id="<?= $row['id_kategori'] ?>" 
                                                    data-nama="<?= htmlspecialchars($row['nama_kategori']) ?>">
                                                    <i class="fas fa-trash"></i>
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
        </div>
    </section>
</div>

<!-- Modal Tambah -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-labelledby="addModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addModalLabel">Tambah Kategori Anggaran</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form action="" method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nama Kategori</label>
                        <input type="text" class="form-control" name="nama_kategori" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" name="add_kategori" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editModalLabel">Edit Kategori Anggaran</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form action="" method="POST">
                <input type="hidden" name="id_kategori" id="edit_id_kategori">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nama Kategori</label>
                        <input type="text" class="form-control" name="nama_kategori" id="edit_nama_kategori" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" name="update_kategori" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Form Delete (Hidden) -->
<form id="deleteForm" action="" method="POST" style="display: none;">
    <input type="hidden" name="id_kategori" id="delete_id_kategori">
    <input type="hidden" name="delete_kategori" value="1">
</form>

<?php include '../templates/footer.php'; ?>

<script>
$(document).ready(function() {
    var table = $('#table-1').DataTable();

    <?php if ($message): ?>
    var msgType = '<?= $message['type'] == 'success' ? 'success' : 'error' ?>';
    var msgTitle = '<?= $message['type'] == 'success' ? 'Berhasil' : 'Gagal' ?>';
    var msgText = '<?= addslashes($message['text']) ?>';

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: msgType,
            title: msgTitle,
            text: msgText,
            showConfirmButton: false,
            timer: 1500
        });
    } else {
        // Fallback if Swal is not loaded
        alert(msgTitle + ': ' + msgText);
    }
    <?php endif; ?>

    // Edit Button
    $('#table-1').on('click', '.edit-btn', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        
        $('#edit_id_kategori').val(id);
        $('#edit_nama_kategori').val(nama);
        $('#editModal').modal('show');
    });

    // Delete Button
    $('#table-1').on('click', '.delete-btn', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        
        Swal.fire({
            title: 'Konfirmasi Hapus',
            text: "Apakah Anda yakin ingin menghapus kategori '" + nama + "'?",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#delete_id_kategori').val(id);
                $('#deleteForm').submit();
            }
        });
    });
});
</script>
