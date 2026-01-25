<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Ensure session is started for activity logging
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

// Define CSS libraries for this page
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'node_modules/datatables.net-select-bs4/css/select.bootstrap4.min.css'
];

// Define JS libraries for this page
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
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
            $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
            $log_result = logActivity($pdo, $username, 'Tambah Siswa', "Menambahkan siswa baru: $nama_siswa");
            if (!$log_result) error_log("Failed to log activity for Tambah Siswa: $nama_siswa");
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
                $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                if ($current_id_kelas != $new_id_kelas) {
                    $log_result = logActivity($pdo, $username, 'Transfer Siswa', "Memindahkan siswa {$nama_siswa} dari kelas {$current_kelas_name} ke kelas {$new_kelas_name}");
                    if (!$log_result) error_log("Failed to log activity for Transfer Siswa: $nama_siswa");
                    $message = ['type' => 'success', 'text' => "Data siswa berhasil diupdate dan dipindahkan dari kelas {$current_kelas_name} ke kelas {$new_kelas_name}!"];
                } else {
                    $log_result = logActivity($pdo, $username, 'Edit Siswa', "Mengupdate data siswa: $nama_siswa");
                    if (!$log_result) error_log("Failed to log activity for Edit Siswa: $nama_siswa");
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

// Handle bulk edit form submission FIRST (before any output)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_edit_siswa'])) {
    $ids = $_POST['bulk_edit_ids'] ?? [];
    $names = $_POST['bulk_edit_nama'] ?? [];
    $nisns = $_POST['bulk_edit_nisn'] ?? [];
    $jenis_kelamins = $_POST['bulk_edit_jenis_kelamin'] ?? [];
    $id_kelass = $_POST['bulk_edit_id_kelas'] ?? [];
    
    if (!empty($ids) && is_array($ids)) {
        $updatedCount = 0;
        $errors = [];
        
        for ($i = 0; $i < count($ids); $i++) {
            if (isset($ids[$i]) && !empty($ids[$i])) {
                $id = (int)$ids[$i];
                $nama = sanitizeInput($names[$i] ?? '');
                $nisn = sanitizeInput($nisns[$i] ?? '');
                $jenis_kelamin = sanitizeInput($jenis_kelamins[$i] ?? '');
                $id_kelas = (int)($id_kelass[$i] ?? 0);
                
                // Validate
                if (empty($nama) || empty($nisn) || empty($jenis_kelamin) || empty($id_kelas)) {
                    $errors[] = "Data ke-" . ($i + 1) . " tidak lengkap";
                    continue;
                }
                
                // Check if NISN already exists for another student
                $check_stmt = $pdo->prepare("SELECT id_siswa FROM tb_siswa WHERE nisn = ? AND id_siswa != ?");
                $check_stmt->execute([$nisn, $id]);
                
                if ($check_stmt->rowCount() > 0) {
                    $errors[] = "NISN " . $nisn . " sudah terdaftar oleh siswa lain";
                    continue;
                }
                
                // Update
                $stmt = $pdo->prepare("UPDATE tb_siswa SET nama_siswa=?, nisn=?, jenis_kelamin=?, id_kelas=? WHERE id_siswa=?");
                if ($stmt->execute([$nama, $nisn, $jenis_kelamin, $id_kelas, $id])) {
                    $updatedCount++;
                }
            }
        }
        
        header('Content-Type: application/json');
        if ($updatedCount > 0) {
            $message = "Berhasil memperbarui $updatedCount data siswa!";
            if (!empty($errors)) {
                $message .= " " . count($errors) . " data gagal: " . implode(', ', $errors);
            }
            echo json_encode([
                'success' => true,
                'message' => $message
            ]);
            $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
            $log_result = logActivity($pdo, $username, 'Bulk Edit Siswa', "Bulk memperbarui $updatedCount data siswa");
            if (!$log_result) error_log("Failed to log activity for Bulk Edit Siswa: $updatedCount data");
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Gagal memperbarui data siswa! ' . (!empty($errors) ? implode(', ', $errors) : '')
            ]);
        }
        exit;
    }
}

// Handle bulk delete via AJAX
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_delete_siswa'])) {
    $ids = json_decode($_POST['ids'] ?? '[]', true);
    
    if (!empty($ids) && is_array($ids)) {
        $deletedCount = 0;
        $errors = [];
        
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                // Get student name for logging
                $stmt = $pdo->prepare("SELECT nama_siswa FROM tb_siswa WHERE id_siswa = ?");
                $stmt->execute([$id]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($student) {
                    // Check if student has attendance records
                    $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM tb_absensi WHERE id_siswa = ?");
                    $check_stmt->execute([$id]);
                    $absensi_count = $check_stmt->fetchColumn();
                    
                    if ($absensi_count > 0) {
                        $errors[] = "Siswa {$student['nama_siswa']} memiliki data absensi dan tidak dapat dihapus";
                        continue;
                    }
                    
                    $stmt = $pdo->prepare("DELETE FROM tb_siswa WHERE id_siswa = ?");
                    if ($stmt->execute([$id])) {
                        $deletedCount++;
                        $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                        $log_result = logActivity($pdo, $username, 'Hapus Siswa', "Menghapus data siswa: {$student['nama_siswa']}");
                        if (!$log_result) error_log("Failed to log activity for Hapus Siswa: {$student['nama_siswa']}");
                    }
                }
            }
        }
        
        header('Content-Type: application/json');
        $message = "Berhasil menghapus $deletedCount data siswa!";
        if (!empty($errors)) {
            $message .= " " . count($errors) . " data gagal: " . implode(', ', $errors);
        }
        echo json_encode([
            'success' => $deletedCount > 0,
            'message' => $message
        ]);
        exit;
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
                $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                $log_result = logActivity($pdo, $username, 'Hapus Siswa', "Menghapus data siswa: {$student['nama_siswa']}");
                if (!$log_result) error_log("Failed to log activity for Hapus Siswa: {$student['nama_siswa']}");
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

// Prepare kelas options for JavaScript (for bulk edit modal)
$kelas_options_js_array = [];
foreach ($kelas_list as $kelas) {
    $kelas_options_js_array[] = [
        'id' => $kelas['id_kelas'],
        'nama' => htmlspecialchars($kelas['nama_kelas'], ENT_QUOTES, 'UTF-8')
    ];
}
$kelas_options_js = json_encode($kelas_options_js_array);

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
                                <a href="cetak_qr_siswa.php?kelas=<?php echo $selected_kelas_id; ?>" target="_blank" class="btn btn-dark"><i class="fas fa-print"></i> Cetak QR Kelas</a>
                                <button type="button" class="btn btn-warning" id="bulk-edit-btn" disabled><i class="fas fa-edit"></i> Edit Terpilih</button>
                                <button type="button" class="btn btn-danger" id="bulk-delete-btn" disabled><i class="fas fa-trash"></i> Hapus Terpilih</button>
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
                                            <th>
                                                <div class="custom-checkbox custom-control">
                                                    <input type="checkbox" data-checkboxes="siswa" data-checkbox-role="dad" class="custom-control-input" id="checkbox-all">
                                                    <label for="checkbox-all" class="custom-control-label">&nbsp;</label>
                                                </div>
                                            </th>
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
                                        <tr data-id-kelas="<?php echo $student['id_kelas'] ?? ''; ?>">
                                            <td>
                                                <div class="custom-checkbox custom-control">
                                                    <input type="checkbox" data-checkboxes="siswa" class="custom-control-input" id="checkbox-<?php echo $student['id_siswa']; ?>" value="<?php echo $student['id_siswa']; ?>">
                                                    <label for="checkbox-<?php echo $student['id_siswa']; ?>" class="custom-control-label">&nbsp;</label>
                                                </div>
                                            </td>
                                            <td><?php echo $index + 1; ?></td>
                                            <td><?php echo htmlspecialchars($student['nama_siswa']); ?></td>
                                            <td><?php echo htmlspecialchars($student['nisn']); ?></td>
                                            <td><?php echo $student['jenis_kelamin'] == 'L' ? 'Laki-laki' : ($student['jenis_kelamin'] == 'P' ? 'Perempuan' : '-'); ?></td>
                                            <td><?php echo htmlspecialchars($student['nama_kelas'] ?? '-'); ?></td>
                                            <td>
                                                <a href="cetak_qr_siswa.php?id=<?php echo $student['id_siswa']; ?>" target="_blank" class="btn btn-dark btn-sm" title="Cetak QR Code"><i class="fas fa-qrcode"></i></a>
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
$js_page[] = "
$(document).ready(function() {
    // Initialize DataTables with pagination and show entries
    if ($.fn.DataTable) {
        $('#table-1').DataTable({
            \"columnDefs\": [
                { \"sortable\": false, \"targets\": [5] } // Disable sorting for action column
            ],
            \"paging\": true,
            \"lengthChange\": true,
            \"pageLength\": 10,
            \"lengthMenu\": [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Semua']],
            \"dom\": 'lfrtip',
            \"info\": true,
            \"language\": {
                \"lengthMenu\": \"Tampilkan _MENU_ entri\",
                \"zeroRecords\": \"Tidak ada data yang ditemukan\",
                \"info\": \"Menampilkan _START_ sampai _END_ dari _TOTAL_ entri\",
                \"infoEmpty\": \"Menampilkan 0 sampai 0 dari 0 entri\",
                \"infoFiltered\": \"(disaring dari _MAX_ total entri)\",
                \"search\": \"Cari:\",
                \"paginate\": {
                    \"first\": \"Pertama\",
                    \"last\": \"Terakhir\",
                    \"next\": \"Selanjutnya\",
                    \"previous\": \"Sebelumnya\"
                }
            }
        });
    }
});
";
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
?>

<!-- Bulk Edit Modal -->
<div class="modal fade" id="bulkEditModal" tabindex="-1" role="dialog" aria-labelledby="bulkEditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bulkEditModalLabel">Edit Data Terpilih</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="bulkEditForm" method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="bulk_edit_siswa" value="1">
                    <p class="mb-3">Edit data untuk <strong id="bulkEditCount">0</strong> data siswa yang dipilih:</p>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm">
                            <thead>
                                <tr>
                                    <th width="5%">No</th>
                                    <th width="20%">Nama Siswa</th>
                                    <th width="20%">Nama Baru</th>
                                    <th width="20%">NISN Baru</th>
                                    <th width="15%">Jenis Kelamin</th>
                                    <th width="20%">Kelas</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Data akan diisi oleh JavaScript -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer bg-whitesmoke br">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
// Add JavaScript for bulk operations
$js_page[] = "
// Define bulk functions globally
window.bulkEdit = function() {
    console.log('bulkEdit function called!');
    if (typeof jQuery === 'undefined' || typeof $ === 'undefined') {
        console.error('jQuery is not loaded!');
        Swal.fire({
            title: 'Error!',
            text: 'jQuery tidak dimuat. Silakan refresh halaman.',
            icon: 'error',
            confirmButtonText: 'OK'
        });
        return;
    }
    var checkedBoxes = $('input[type=\"checkbox\"][data-checkboxes=\"siswa\"]:checked').not('[data-checkbox-role=\"dad\"]');
    var selectedIds = [];
    
    checkedBoxes.each(function() {
        var id = $(this).val();
        if (id && id !== 'on') {
            selectedIds.push(id);
        }
    });
    
    if (selectedIds.length === 0) {
        Swal.fire({
            title: 'Tidak ada data terpilih',
            text: 'Silakan pilih setidaknya satu data siswa untuk diedit.',
            icon: 'warning'
        });
        return;
    }
    
    // Get selected student data
    var selectedStudents = [];
    checkedBoxes.each(function() {
        var id = $(this).val();
        if (id && id !== 'on') {
            var row = $(this).closest('tr');
            var cells = row.find('td');
            selectedStudents.push({
                id: id,
                nama: cells.eq(2).text().trim(),
                nisn: cells.eq(3).text().trim(),
                jenis_kelamin: cells.eq(4).text().trim(),
                id_kelas: row.data('id-kelas') || ''
            });
        }
    });
    
    // Populate modal table
    var tableBody = $('#bulkEditModal tbody');
    tableBody.empty();
    
    selectedStudents.forEach(function(student, index) {
        var jenisKelaminOptions = '';
        if (student.jenis_kelamin === 'Laki-laki') {
            jenisKelaminOptions = '<option value=\"L\" selected>Laki-laki</option><option value=\"P\">Perempuan</option>';
        } else if (student.jenis_kelamin === 'Perempuan') {
            jenisKelaminOptions = '<option value=\"L\">Laki-laki</option><option value=\"P\" selected>Perempuan</option>';
        } else {
            jenisKelaminOptions = '<option value=\"L\">Laki-laki</option><option value=\"P\">Perempuan</option>';
        }
        
        var kelasOptionsArray = " . $kelas_options_js . ";
        var kelasSelectHtml = '<select class=\"form-control form-control-sm\" name=\"bulk_edit_id_kelas[]\" required><option value=\"\">Pilih Kelas</option>';
        for (var k = 0; k < kelasOptionsArray.length; k++) {
            var kelas = kelasOptionsArray[k];
            var selected = (student.id_kelas && (kelas.id == student.id_kelas || kelas.id === parseInt(student.id_kelas) || parseInt(kelas.id) === parseInt(student.id_kelas))) ? ' selected' : '';
            kelasSelectHtml += '<option value=\"' + kelas.id + '\"' + selected + '>' + kelas.nama + '</option>';
        }
        kelasSelectHtml += '</select>';
        
        var row = '<tr>' +
            '<td>' + (index + 1) + '</td>' +
            '<td>' + student.nama + '</td>' +
            '<td><input type=\"text\" class=\"form-control form-control-sm\" name=\"bulk_edit_nama[]\" value=\"' + student.nama + '\" required></td>' +
            '<td><input type=\"text\" class=\"form-control form-control-sm\" name=\"bulk_edit_nisn[]\" value=\"' + student.nisn + '\" required></td>' +
            '<td><select class=\"form-control form-control-sm\" name=\"bulk_edit_jenis_kelamin[]\" required>' + jenisKelaminOptions + '</select></td>' +
            '<td>' + kelasSelectHtml + '</td>' +
            '<input type=\"hidden\" name=\"bulk_edit_ids[]\" value=\"' + student.id + '\">' +
            '</tr>';
        tableBody.append(row);
    });
    
    // Update count
    $('#bulkEditCount').text(selectedStudents.length);
    
    // Show modal
    $('#bulkEditModal').modal('show');
};

window.bulkDelete = function() {
    if (typeof jQuery === 'undefined' || typeof $ === 'undefined') {
        console.error('jQuery is not loaded!');
        Swal.fire({
            title: 'Error!',
            text: 'jQuery tidak dimuat. Silakan refresh halaman.',
            icon: 'error',
            confirmButtonText: 'OK'
        });
        return;
    }
    console.log('bulkDelete function called!');
    var checkedBoxes = $('input[type=\"checkbox\"][data-checkboxes=\"siswa\"]:checked').not('[data-checkbox-role=\"dad\"]');
    var selectedIds = [];
    var selectedNames = [];
    
    checkedBoxes.each(function() {
        var id = $(this).val();
        if (id && id !== 'on') {
            selectedIds.push(id);
            var row = $(this).closest('tr');
            var nameCell = row.find('td').eq(2); // Nama Siswa column
            var name = nameCell.text().trim();
            if (name) {
                selectedNames.push(name);
            }
        }
    });
    
    if (selectedIds.length === 0) {
        Swal.fire({
            title: 'Tidak ada data terpilih',
            text: 'Silakan pilih setidaknya satu data siswa untuk dihapus.',
            icon: 'warning'
        });
        return;
    }
    
    var deleteMessage = 'Apakah Anda yakin ingin menghapus <strong>' + selectedIds.length + ' data siswa</strong>?';
    if (selectedNames.length > 0 && selectedNames.length <= 5) {
        deleteMessage += '<br><br>Data yang akan dihapus:<br>' + selectedNames.join('<br>');
    }
    
    Swal.fire({
        title: 'Konfirmasi Hapus',
        html: deleteMessage,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Ya, Hapus!',
        cancelButtonText: 'Batal'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '',
                type: 'POST',
                data: {
                    bulk_delete_siswa: '1',
                    ids: JSON.stringify(selectedIds)
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            title: 'Berhasil!',
                            text: response.message,
                            icon: 'success',
                            timer: 3000,
                            timerProgressBar: true
                        }).then(function() {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            title: 'Error!',
                            text: response.message,
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        title: 'Error!',
                        text: 'Terjadi kesalahan saat menghapus data.',
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                }
            });
        }
    });
};

// Handle bulk edit form submission
$(document).ready(function() {
    $('#bulkEditForm').on('submit', function(e) {
        e.preventDefault();
        
        $.ajax({
            url: '',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        title: 'Berhasil!',
                        text: response.message,
                        icon: 'success',
                        timer: 3000,
                        timerProgressBar: true
                    }).then(function() {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        title: 'Error!',
                        text: response.message,
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                }
            },
            error: function() {
                Swal.fire({
                    title: 'Error!',
                    text: 'Terjadi kesalahan saat memperbarui data.',
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
            }
        });
    });
    
    // Update bulk buttons state
    function updateBulkButtons() {
        var checkedCount = $('input[type=\"checkbox\"][data-checkboxes=\"siswa\"]:checked').not('[data-checkbox-role=\"dad\"]').length;
        var totalCount = $('input[type=\"checkbox\"][data-checkboxes=\"siswa\"]:not([data-checkbox-role=\"dad\"])').length;
        
        // Check visible checkboxes (for DataTables pagination)
        var visibleCheckboxes = $('#table-1 tbody tr:visible input[data-checkboxes=\"siswa\"]:not([data-checkbox-role=\"dad\"])');
        var visibleChecked = visibleCheckboxes.filter(':checked').length;
        var allVisibleChecked = visibleCheckboxes.length > 0 && visibleChecked === visibleCheckboxes.length;
        
        $('#bulk-edit-btn').prop('disabled', checkedCount === 0);
        $('#bulk-delete-btn').prop('disabled', checkedCount === 0);
        
        // Update select all checkbox state based on visible checkboxes
        $('#checkbox-all').prop('checked', allVisibleChecked && totalCount > 0);
        $('#checkbox-all').prop('indeterminate', checkedCount > 0 && checkedCount < totalCount);
    }
    
    // Handle select all checkbox
    $(document).on('change', '#checkbox-all', function() {
        const isChecked = $(this).is(':checked');
        // Only check/uncheck visible checkboxes (current page)
        $('#table-1 tbody tr:visible input[data-checkboxes=\"siswa\"]:not([data-checkbox-role=\"dad\"])').prop('checked', isChecked);
        updateBulkButtons();
    });
    
    // Use event delegation to handle individual checkbox changes
    $(document).on('change', 'input[data-checkboxes=\"siswa\"]:not([data-checkbox-role=\"dad\"])', function() {
        updateBulkButtons();
    });
    
    // Attach click handlers to bulk action buttons
    $('#bulk-edit-btn').on('click', function(e) {
        e.preventDefault();
        if (typeof window.bulkEdit === 'function') {
            window.bulkEdit();
        } else {
            console.error('bulkEdit function is not available');
            Swal.fire({
                title: 'Error!',
                text: 'Fungsi edit belum dimuat. Silakan refresh halaman.',
                icon: 'error',
                confirmButtonText: 'OK'
            });
        }
    });
    
    $('#bulk-delete-btn').on('click', function(e) {
        e.preventDefault();
        if (typeof window.bulkDelete === 'function') {
            window.bulkDelete();
        } else {
            console.error('bulkDelete function is not available');
            Swal.fire({
                title: 'Error!',
                text: 'Fungsi hapus belum dimuat. Silakan refresh halaman.',
                icon: 'error',
                confirmButtonText: 'OK'
            });
        }
    });
    
    // Initialize DataTables with pagination and show entries
    function initDataTable() {
        if (typeof $.fn.DataTable !== 'undefined') {
            if ($.fn.DataTable.isDataTable('#table-1')) {
                $('#table-1').DataTable().destroy();
            }
            
            $('#table-1').DataTable({
                \"columnDefs\": [
                    { \"sortable\": false, \"targets\": [0, 6] } // Disable sorting for checkbox (col 0) and action (col 6) columns
                ],
                \"paging\": true,
                \"lengthChange\": true,
                \"pageLength\": 10,
                \"lengthMenu\": [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Semua']],
                \"dom\": 'lfrtip',
                \"info\": true,
                \"language\": {
                    \"lengthMenu\": \"Tampilkan _MENU_ entri\",
                    \"zeroRecords\": \"Tidak ada data yang ditemukan\",
                    \"info\": \"Menampilkan _START_ sampai _END_ dari _TOTAL_ entri\",
                    \"infoEmpty\": \"Menampilkan 0 sampai 0 dari 0 entri\",
                    \"infoFiltered\": \"(disaring dari _MAX_ total entri)\",
                    \"search\": \"Cari:\",
                    \"paginate\": {
                        \"first\": \"Pertama\",
                        \"last\": \"Terakhir\",
                        \"next\": \"Selanjutnya\",
                        \"previous\": \"Sebelumnya\"
                    }
                },
                \"drawCallback\": function(settings) {
                    setTimeout(updateBulkButtons, 100);
                }
            });
            console.log('DataTables initialized successfully');
        } else {
            console.warn('DataTables library not loaded, retrying...');
            setTimeout(initDataTable, 100);
        }
    }
    
    initDataTable();
});
";

include '../templates/footer.php'; 
?>