<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has guru level
if (!isAuthorized(['guru'])) {
    redirect('../login.php');
}

// Get school profile
$school_profile = getSchoolProfile($pdo);

// Get teacher information
if ($_SESSION['level'] == 'guru' || $_SESSION['level'] == 'wali') {
    // Direct login via NUPTK, user_id is actually the id_guru
    $stmt = $pdo->prepare("SELECT * FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    // Traditional login via tb_pengguna
    $stmt = $pdo->prepare("SELECT g.* FROM tb_guru g JOIN tb_pengguna p ON g.id_guru = p.id_guru WHERE p.id_pengguna = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Check if teacher was found
if (!$teacher) {
    die('Error: Teacher data not found');
}

// Ensure nama_guru is set in session
if (!isset($_SESSION['nama_guru']) || empty($_SESSION['nama_guru'])) {
    $_SESSION['nama_guru'] = $teacher['nama_guru'];
}

// Get classes that this teacher teaches
$classes = [];
if (!empty($teacher['mengajar'])) {
    $mengajar_decoded = json_decode($teacher['mengajar'], true);
    
    if (is_array($mengajar_decoded) && !empty($mengajar_decoded)) {
        // Build placeholders for IN clause
        $placeholders = implode(',', array_fill(0, count($mengajar_decoded), '?'));
        $stmt = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas IN ($placeholders) ORDER BY nama_kelas ASC");
        $stmt->execute($mengajar_decoded);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Handle form submission for attendance
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_attendance'])) {
    $id_kelas = (int)$_POST['id_kelas'];
    $tanggal = $_POST['tanggal'];
    
    // Only process students that are actually in the POST data
    $saved_count = 0;
    foreach ($_POST as $key => $value) {
        // Check if this is a status field (status_[id_siswa])
        if (strpos($key, 'status_') === 0) {
            $id_siswa = (int)str_replace('status_', '', $key);
            $status = $value;
            
            // Validate status value
            if (!in_array($status, ['Hadir', 'Tidak Hadir', 'Berhalangan'])) {
                continue; // Skip invalid values
            }
            
            // Check if attendance already exists for this student and date
            $check_stmt = $pdo->prepare("SELECT * FROM tb_sholat WHERE id_siswa = ? AND tanggal = ?");
            $check_stmt->execute([$id_siswa, $tanggal]);
            
            if ($check_stmt->rowCount() > 0) {
                // Update existing record
                $update_stmt = $pdo->prepare("UPDATE tb_sholat SET status = ? WHERE id_siswa = ? AND tanggal = ?");
                $update_stmt->execute([$status, $id_siswa, $tanggal]);
            } else {
                // Insert new record
                $insert_stmt = $pdo->prepare("INSERT INTO tb_sholat (id_siswa, tanggal, status) VALUES (?, ?, ?)");
                $insert_stmt->execute([$id_siswa, $tanggal, $status]);
            }
            
            // Sync with Sholat Dhuha (Always sync)
            $check_dhuha = $pdo->prepare("SELECT * FROM tb_sholat_dhuha WHERE id_siswa = ? AND tanggal = ?");
            $check_dhuha->execute([$id_siswa, $tanggal]);
            if ($check_dhuha->rowCount() > 0) {
                    $update_dhuha = $pdo->prepare("UPDATE tb_sholat_dhuha SET status = ? WHERE id_siswa = ? AND tanggal = ?");
                    $update_dhuha->execute([$status, $id_siswa, $tanggal]);
            } else {
                    $insert_dhuha = $pdo->prepare("INSERT INTO tb_sholat_dhuha (id_siswa, tanggal, status) VALUES (?, ?, ?)");
                    $insert_dhuha->execute([$id_siswa, $tanggal, $status]);
            }
            
            $saved_count++;
        }
    }
    
    $message = ['type' => 'success', 'text' => "Data sholat berjamaah berhasil disimpan untuk $saved_count siswa!"];
    $username = isset($_SESSION['nama_guru']) ? $_SESSION['nama_guru'] : (isset($_SESSION['username']) ? $_SESSION['username'] : 'Guru');
    // Using simple logActivity wrapper if available, or manual insert if needed. 
    // Assuming logActivity is globally available from functions.php
    if (function_exists('logActivity')) {
        logActivity($pdo, $_SESSION['user_id'], 'Input Sholat Berjamaah', "Guru $username melakukan input sholat berjamaah kelas ID: $id_kelas untuk $saved_count siswa");
    }
}

// Get students for selected class
$students = [];
$class_info = [];
if (isset($_GET['kelas']) && !empty($_GET['kelas'])) {
    $id_kelas = (int)$_GET['kelas'];
    
    // Get class info
    $stmt_class = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas = ?");
    $stmt_class->execute([$id_kelas]);
    $class_info = $stmt_class->fetch(PDO::FETCH_ASSOC) ?: [];
    
    $tanggal = isset($_GET['tanggal']) && !empty($_GET['tanggal']) ? $_GET['tanggal'] : date('Y-m-d');
    
    // Get all students in the class
    try {
        $stmt = $pdo->prepare("SELECT s.*, sh.status as status_sholat, ab.keterangan as status_absensi 
                               FROM tb_siswa s 
                               LEFT JOIN tb_sholat sh ON s.id_siswa = sh.id_siswa AND sh.tanggal = ? 
                               LEFT JOIN tb_absensi ab ON s.id_siswa = ab.id_siswa AND ab.tanggal = ?
                               WHERE s.id_kelas = ? 
                               ORDER BY s.nama_siswa ASC");
        $stmt->execute([$tanggal, $tanggal, $id_kelas]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $students = [];
    }
} else {
    $tanggal = date('Y-m-d');
}

// Set page title
$page_title = 'Sholat Berjamaah';

// Define CSS libraries
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'
];

include '../templates/header.php';
?>

<!-- Main Content -->
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Sholat Berjamaah</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Absensi</div>
                <div class="breadcrumb-item">Sholat Berjamaah</div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4>Form Absensi Sholat Berjamaah</h4>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="<?php echo $_SERVER['PHP_SELF']; ?>" id="filterForm">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Kelas</label>
                                        <select class="form-control" name="kelas" id="kelasSelect" required>
                                            <option value="">Pilih Kelas</option>
                                            <?php foreach ($classes as $class): ?>
                                            <option value="<?php echo $class['id_kelas']; ?>" <?php echo (isset($_GET['kelas']) && $_GET['kelas'] == $class['id_kelas']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($class['nama_kelas']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Tanggal</label>
                                        <input type="date" class="form-control" name="tanggal" id="tanggalInput" value="<?php echo $tanggal; ?>" required>
                                    </div>
                                </div>
                            </div>
                        </form>
                        
                        <?php if (!empty($students)): ?>
                        <form method="POST" action="">
                            <input type="hidden" name="id_kelas" value="<?php echo $_GET['kelas']; ?>">
                            <input type="hidden" name="tanggal" value="<?php echo $tanggal; ?>">
                            <input type="hidden" name="save_attendance" value="1">
                            
                            <div class="table-responsive">
                                <table class="table table-striped" id="table-1">
                                    <thead>
                                        <tr>
                                            <th>No</th>
                                            <th>Nama Siswa</th>
                                            <th>NISN</th>
                                            <th>Status Kehadiran</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $index => $student): ?>
                                        <?php
                                            $status_sholat = $student['status_sholat'] ?? null;
                                            $status_absensi = $student['status_absensi'] ?? null;
                                            $is_absent_daily = in_array($status_absensi, ['Sakit', 'Izin', 'Alpa']);
                                            
                                            if ($is_absent_daily) {
                                                $current_status = 'Tidak Hadir';
                                            } elseif ($status_sholat) {
                                                $current_status = $status_sholat;
                                            } else {
                                                $current_status = 'Hadir';
                                            }
                                        ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($student['nama_siswa']); ?>
                                                <span class="ml-2 badge <?php 
                                                    if ($current_status == 'Hadir') echo 'badge-success';
                                                    elseif ($current_status == 'Berhalangan') echo 'badge-danger';
                                                    else echo 'badge-danger';
                                                ?>" id="badge_<?php echo $student['id_siswa']; ?>">
                                                    <?php echo $current_status; ?>
                                                </span>
                                                <?php if ($is_absent_daily): ?>
                                                    <small class="text-danger d-block">(Absensi: <?php echo $status_absensi; ?>)</small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($student['nisn']); ?></td>
                                            <td>
                                                <div class="btn-group btn-group-toggle" data-toggle="buttons">
                                                    <label class="btn btn-outline-success <?php echo $current_status === 'Hadir' ? 'active' : ''; ?>" 
                                                           onclick="updateBadgeLocal(<?php echo $student['id_siswa']; ?>, 'Hadir')">
                                                        <input type="radio" name="status_<?php echo $student['id_siswa']; ?>" value="Hadir" 
                                                               <?php echo $current_status === 'Hadir' ? 'checked' : ''; ?>> Hadir
                                                    </label>
                                                    <label class="btn btn-outline-danger <?php echo $current_status === 'Tidak Hadir' ? 'active' : ''; ?>"
                                                           onclick="updateBadgeLocal(<?php echo $student['id_siswa']; ?>, 'Tidak Hadir')">
                                                        <input type="radio" name="status_<?php echo $student['id_siswa']; ?>" value="Tidak Hadir" 
                                                               <?php echo $current_status === 'Tidak Hadir' ? 'checked' : ''; ?>> Tidak Hadir
                                                    </label>
                                                    <?php if ($student['jenis_kelamin'] == 'P'): ?>
                                                    <label class="btn btn-outline-warning <?php echo $current_status === 'Berhalangan' ? 'active' : ''; ?>"
                                                           onclick="updateBadgeLocal(<?php echo $student['id_siswa']; ?>, 'Berhalangan')">
                                                        <input type="radio" name="status_<?php echo $student['id_siswa']; ?>" value="Berhalangan" 
                                                               <?php echo $current_status === 'Berhalangan' ? 'checked' : ''; ?>> Berhalangan
                                                    </label>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="row mt-4">
                                <div class="col-12 text-center">
                                    <button type="submit" class="btn btn-primary" id="saveAttendanceBtn">Simpan Absensi</button>
                                </div>
                            </div>
                        </form>
                        <?php elseif (isset($_GET['kelas']) && !empty($_GET['kelas'])): ?>
                        <div class="alert alert-info">
                            <p class="text-center mb-0">Belum ada siswa dalam kelas ini.</p>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <p class="text-center mb-0">Silakan pilih kelas terlebih dahulu.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php
// Add DataTables JS libraries
$js_libs = [];
$js_libs[] = 'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js';
$js_libs[] = 'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js';

// Add page-specific JavaScript
$js_page = [];

// SweetAlert logic
if (isset($message)) {
    $js_page[] = "
    Swal.fire({
        title: '" . ($message['type'] === 'success' ? 'Sukses!' : 'Info!') . "',
        text: '" . addslashes($message['text']) . "',
        icon: '" . $message['type'] . "',
        timer: " . ($message['type'] === 'success' ? '3000' : '5000') . ",
        timerProgressBar: true,
        showConfirmButton: false
    });
    ";
}

$js_page[] = "
$(document).ready(function() {
    // Auto-submit when class is selected
    $('#kelasSelect').on('change', function() {
        var kelasId = $(this).val();
        if (kelasId && kelasId !== '') {
            $('#filterForm').submit();
        }
    });
    
    // Auto-submit when date is selected
    $('#tanggalInput').on('change', function() {
        var tanggal = $(this).val();
        var kelasId = $('#kelasSelect').val();
        if (tanggal && tanggal !== '' && kelasId && kelasId !== '') {
            $('#filterForm').submit();
        }
    });
    
    // Initialize DataTable
    $('#table-1').DataTable({
        'paging': false, // Disable paging to show all students
        'ordering': false, // Disable ordering to keep list stable
        'info': false,
        'searching': true
    });
});

function updateBadgeLocal(id, status) {
    var badge = $('#badge_' + id);
    badge.removeClass('badge-danger badge-success badge-warning');
    if (status === 'Hadir') {
        badge.addClass('badge-success').text('Hadir');
    } else if (status === 'Berhalangan') {
        badge.addClass('badge-danger').text('Berhalangan');
    } else {
        badge.addClass('badge-danger').text('Tidak Hadir');
    }
}
";

include '../templates/footer.php';
?>