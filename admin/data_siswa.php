<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

// Define CSS libraries for this page
$css_libs = [
    'node_modules/datatables.net-bs4/css/dataTables.bootstrap4.min.css',
    'node_modules/datatables.net-select-bs4/css/select.bootstrap4.min.css'
];

// Define JS libraries for this page
$js_libs = [
    'node_modules/datatables/media/js/jquery.dataTables.min.js',
    'node_modules/datatables.net-bs4/js/dataTables.bootstrap4.min.js',
    'node_modules/datatables.net-select-bs4/js/select.bootstrap4.min.js',
    'student_management_unified.js'
];

// Define page-specific JS
$js_page = [];

// Set page title
$page_title = 'Data Siswa';

// Handle form submissions
$message = null;

// Handle adding student
if ($_POST['add_siswa'] ?? false) {
    $nama_siswa = sanitizeInput($_POST['nama_siswa'] ?? '');
    $nisn = sanitizeInput($_POST['nisn'] ?? '');
    $jenis_kelamin = sanitizeInput($_POST['jenis_kelamin'] ?? '');
    $id_kelas = (int)($_POST['id_kelas'] ?? 0);
    
    if ($nama_siswa && $nisn && $jenis_kelamin && $id_kelas) {
        global $pdo;
        $stmt = $pdo->prepare("INSERT INTO tb_siswa (nama_siswa, nisn, jenis_kelamin, id_kelas) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$nama_siswa, $nisn, $jenis_kelamin, $id_kelas])) {
            $message = ['type' => 'success', 'text' => 'Data siswa berhasil ditambahkan!'];
            logActivity($pdo, $_SESSION['username'], 'Tambah Siswa', "Menambahkan siswa baru: $nama_siswa");
        } else {
            $message = ['type' => 'danger', 'text' => 'Gagal menambahkan data siswa!'];
        }
    } else {
        $message = ['type' => 'warning', 'text' => 'Harap lengkapi semua data siswa!'];
    }
}

// Handle updating student
if ($_POST['update_siswa'] ?? false) {
    $id_siswa = (int)($_POST['id_siswa'] ?? 0);
    $nama_siswa = sanitizeInput($_POST['nama_siswa'] ?? '');
    $nisn = sanitizeInput($_POST['nisn'] ?? '');
    $jenis_kelamin = sanitizeInput($_POST['jenis_kelamin'] ?? '');
    $new_id_kelas = (int)($_POST['id_kelas'] ?? 0);
    
    if ($id_siswa && $nama_siswa && $nisn && $jenis_kelamin && $new_id_kelas) {
        global $pdo;
        
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Get current student data including current class
            $current_stmt = $pdo->prepare("SELECT s.*, k.nama_kelas as current_kelas_name FROM tb_siswa s LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_siswa = ?");
            $current_stmt->execute([$id_siswa]);
            $current_student = $current_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$current_student) {
                throw new Exception('Data siswa tidak ditemukan');
            }
            
            $current_id_kelas = (int)$current_student['id_kelas'];
            $current_kelas_name = $current_student['current_kelas_name'] ?? 'Tidak ada kelas';
            
            // Update student data
            $stmt = $pdo->prepare("UPDATE tb_siswa SET nama_siswa = ?, nisn = ?, jenis_kelamin = ?, id_kelas = ? WHERE id_siswa = ?");
            $result = $stmt->execute([$nama_siswa, $nisn, $jenis_kelamin, $new_id_kelas, $id_siswa]);
            
            if ($result) {
                // Get new class name for logging
                $new_class_stmt = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas = ?");
                $new_class_stmt->execute([$new_id_kelas]);
                $new_class = $new_class_stmt->fetch(PDO::FETCH_ASSOC);
                $new_kelas_name = $new_class ? $new_class['nama_kelas'] : 'Kelas tidak ditemukan';
                
                // Log the transfer if class changed
                if ($current_id_kelas != $new_id_kelas) {
                    logActivity($pdo, $_SESSION['username'], 'Transfer Siswa', "Memindahkan siswa {$nama_siswa} dari kelas {$current_kelas_name} ke kelas {$new_kelas_name}");
                    $message = ['type' => 'success', 'text' => "Data siswa berhasil diupdate dan dipindahkan dari kelas {$current_kelas_name} ke kelas {$new_kelas_name}!"];
                } else {
                    logActivity($pdo, $_SESSION['username'], 'Edit Siswa', "Mengupdate data siswa: $nama_siswa");
                    $message = ['type' => 'success', 'text' => 'Data siswa berhasil diupdate!'];
                }
                
                // Commit transaction
                $pdo->commit();
            } else {
                throw new Exception('Gagal mengupdate data siswa');
            }
        } catch (Exception $e) {
            // Rollback transaction on error
            $pdo->rollBack();
            $message = ['type' => 'danger', 'text' => 'Gagal mengupdate data siswa: ' . $e->getMessage()];
        }
    } else {
        $message = ['type' => 'warning', 'text' => 'Harap lengkapi semua data siswa!'];
    }
}

// Handle deleting student
if ($_POST['delete_siswa'] ?? false) {
    $id_siswa = (int)($_POST['id_siswa'] ?? 0);
    
    if ($id_siswa) {
        global $pdo;
        // Get student name for logging
        $stmt = $pdo->prepare("SELECT nama_siswa FROM tb_siswa WHERE id_siswa = ?");
        $stmt->execute([$id_siswa]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($student) {
            $stmt = $pdo->prepare("DELETE FROM tb_siswa WHERE id_siswa = ?");
            if ($stmt->execute([$id_siswa])) {
                $message = ['type' => 'success', 'text' => 'Data siswa berhasil dihapus!'];
                logActivity($pdo, $_SESSION['username'], 'Hapus Siswa', "Menghapus data siswa: {$student['nama_siswa']}");
            } else {
                $message = ['type' => 'danger', 'text' => 'Gagal menghapus data siswa!'];
            }
        } else {
            $message = ['type' => 'warning', 'text' => 'Data siswa tidak ditemukan!'];
        }
    } else {
        $message = ['type' => 'warning', 'text' => 'ID siswa tidak valid!'];
    }
}

// Initialize variables to prevent undefined variable warnings
$selected_kelas_id = isset($_GET['kelas_id']) ? (int)$_GET['kelas_id'] : 0;

// Get class list for the dropdown
$kelas_list = getAllKelas();

// Get students if a class is selected
$students = [];
if ($selected_kelas_id > 0) {
    $students = getStudentsByClass($selected_kelas_id);
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Data Siswa</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item"><a href="#">Master Data</a></div>
                <div class="breadcrumb-item">Data Siswa</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Data Siswa</h4>
                            <div class="card-header-action">
                                <!-- Show add button only when class is selected -->
                                <?php if ($selected_kelas_id > 0): ?>
                                <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addModal">Tambah Siswa</button>
                                <button type="button" class="btn btn-info" data-toggle="modal" data-target="#importModal" onclick="setImportType('siswa')"><i class="fas fa-file-import"></i> Impor Excel</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if ($message): ?>
                            <script>
                                document.addEventListener('DOMContentLoaded', function() {
                                    Swal.fire({
                                        title: '<?php echo $message['type'] === 'success' ? 'Sukses!' : 'Error!'; ?>',
                                        text: '<?php echo addslashes($message['text']); ?>',
                                        icon: '<?php echo $message['type']; ?>',
                                        timer: <?php echo $message['type'] === 'success' ? '3000' : '5000'; ?>,
                                        timerProgressBar: true,
                                        showConfirmButton: false
                                    });
                                });
                            </script>
                            <?php endif; ?>
                            
                            <!-- Filter Section -->
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label for="filter_kelas">Pilih Kelas:</label>
                                    <form method="GET" id="kelasForm">
                                        <select class="form-control" id="filter_kelas" name="kelas_id" onchange="this.form.submit()">
                                            <option value="">Semua Kelas</option>
                                            <?php foreach ($kelas_list as $kelas): ?>
                                            <option value="<?php echo $kelas['id_kelas']; ?>" <?php echo $selected_kelas_id == $kelas['id_kelas'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($kelas['nama_kelas']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </div>
                            </div>
                            
                            <!-- Table Section - Only shown when class is selected -->
                            <?php if ($selected_kelas_id > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-striped" id="table-1">
                                    <thead>
                                        <tr>
                                            <th>No</th>
                                            <th>Nama Siswa</th>
                                            <th>NISN</th>
                                            <th>Jenis Kelamin</th>
                                            <th>Kelas</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $index => $student): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td><?php echo htmlspecialchars($student['nama_siswa']); ?></td>
                                            <td><?php echo htmlspecialchars($student['nisn']); ?></td>
                                            <td><?php echo $student['jenis_kelamin'] == 'L' ? 'Laki-laki' : ($student['jenis_kelamin'] == 'P' ? 'Perempuan' : '-'); ?></td>
                                            <td><?php echo htmlspecialchars($student['nama_kelas'] ?? '-'); ?></td>
                                            <td>
                                                <a href="#" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#editModal<?php echo $student['id_siswa']; ?>"><i class="fas fa-edit"></i></a>
                                                <a href="#" class="btn btn-danger btn-sm delete-btn" data-id="<?php echo $student['id_siswa']; ?>" data-name="<?php echo htmlspecialchars($student['nama_siswa']); ?>" data-action="delete_siswa"><i class="fas fa-trash"></i></a>
                                            </td>
                                        </tr>
                                        
                                        <!-- Edit Modal -->
                                        <div class="modal fade edit-modal" id="editModal<?php echo $student['id_siswa']; ?>" tabindex="-1" role="dialog" aria-labelledby="editModalLabel<?php echo $student['id_siswa']; ?>" aria-hidden="true">
                                            <div class="modal-dialog" role="document">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title" id="editModalLabel<?php echo $student['id_siswa']; ?>">Edit Data Siswa</h5>
                                                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                            <span aria-hidden="true">&times;</span>
                                                        </button>
                                                    </div>
                                                    <form method="POST" action="">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="id_siswa" value="<?php echo $student['id_siswa']; ?>">
                                                            <input type="hidden" name="update_siswa" value="1">
                                                            <div class="form-group">
                                                                <label>Nama Siswa</label>
                                                                <input type="text" class="form-control" name="nama_siswa" value="<?php echo htmlspecialchars($student['nama_siswa']); ?>" required>
                                                            </div>
                                                            <div class="form-group">
                                                                <label>NISN</label>
                                                                <input type="text" class="form-control" name="nisn" value="<?php echo htmlspecialchars($student['nisn']); ?>" required>
                                                            </div>
                                                            <div class="form-group">
                                                                <label>Jenis Kelamin</label>
                                                                <select class="form-control" name="jenis_kelamin" required>
                                                                    <option value="">Pilih Jenis Kelamin</option>
                                                                    <option value="L" <?php echo $student['jenis_kelamin'] == 'L' ? 'selected' : ''; ?>>Laki-laki</option>
                                                                    <option value="P" <?php echo $student['jenis_kelamin'] == 'P' ? 'selected' : ''; ?>>Perempuan</option>
                                                                </select>
                                                            </div>
                                                            <div class="form-group">
                                                                <label>Kelas</label>
                                                                <select class="form-control" name="id_kelas" required>
                                                                    <option value="">Pilih Kelas</option>
                                                                    <?php foreach ($kelas_list as $kelas): ?>
                                                                    <option value="<?php echo $kelas['id_kelas']; ?>" <?php echo $student['id_kelas'] == $kelas['id_kelas'] ? 'selected' : ''; ?>>
                                                                        <?php echo htmlspecialchars($kelas['nama_kelas']); ?>
                                                                    </option>
                                                                    <?php endforeach; ?>
                                                                </select>
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
                                        

                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="alert alert-info">
                                <h6>Silakan pilih kelas terlebih dahulu untuk menampilkan data siswa.</h6>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-labelledby="addModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addModalLabel">Tambah Data Siswa</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="add_siswa" value="1">
                    <div class="form-group">
                        <label>Nama Siswa</label>
                        <input type="text" class="form-control" name="nama_siswa" required>
                    </div>
                    <div class="form-group">
                        <label>NISN</label>
                        <input type="text" class="form-control" name="nisn" required>
                    </div>
                    <div class="form-group">
                        <label>Jenis Kelamin</label>
                        <select class="form-control" name="jenis_kelamin" required>
                            <option value="">Pilih Jenis Kelamin</option>
                            <option value="L">Laki-laki</option>
                            <option value="P">Perempuan</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Kelas</label>
                        <select class="form-control" name="id_kelas" required>
                            <option value="">Pilih Kelas</option>
                            <?php foreach ($kelas_list as $kelas): ?>
                            <option value="<?php echo $kelas['id_kelas']; ?>">
                                <?php echo htmlspecialchars($kelas['nama_kelas']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
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

<?php
// Add JavaScript for delete confirmation
if (!isset($js_page)) {
    $js_page = [];
}
$js_page[] = '
$(document).ready(function() {
    console.log("Delete handler initialized");
});

// Use delegated event binding for dynamically added content
$(document).on("click", ".delete-btn", function(e) {
    e.preventDefault();
    console.log("Delete button clicked");
    
    var id = $(this).data("id");
    var name = $(this).data("name");
    var action = $(this).data("action");
    
    Swal.fire({
        title: "Konfirmasi Hapus",
        text: "Apakah Anda yakin ingin menghapus data siswa " + name + "?",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: "Ya, Hapus!",
        cancelButtonText: "Batal"
    }).then((result) => {
        if (result.isConfirmed) {
            console.log("Deleting student ID: " + id);
            // Create a temporary form and submit it
            var form = $("<form method=\"POST\" action=\"\"><input type=\"hidden\" name=\"id_siswa\" value=\"" + id + "\"><input type=\"hidden\" name=\"delete_siswa\" value=\"1\"></form>");
            $("body").append(form);
            form.submit();
        }
    });
});';

// Include the import modal
include '../templates/import_modal.php';

include '../templates/footer.php'; 
?>