<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has wali level
if (!isAuthorized(['wali'])) {
    redirect('../login.php');
}

// Get school profile
$school_profile = getSchoolProfile($pdo);

// Get teacher/wali name
if (isset($_SESSION['nama_guru']) && !empty($_SESSION['nama_guru'])) {
    $teacher_name = $_SESSION['nama_guru'];
} else {
    // For traditional login via tb_pengguna, get teacher name
    if ($_SESSION['level'] == 'wali' || $_SESSION['level'] == 'guru') {
        // Direct login via NUPTK, user_id is actually the id_guru
        $stmt = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
        $stmt->execute([$_SESSION['user_id']]);
    } else {
        // Traditional login via tb_pengguna
        $stmt = $pdo->prepare("SELECT g.nama_guru FROM tb_guru g JOIN tb_pengguna p ON g.id_guru = p.id_guru WHERE p.id_pengguna = ?");
        $stmt->execute([$_SESSION['user_id']]);
    }
    $teacher_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $teacher_name = $teacher_result['nama_guru'] ?? $_SESSION['username'];
    
    // Ensure nama_guru is set in session for consistent navbar display
    if ($teacher_result && isset($teacher_result['nama_guru'])) {
        $_SESSION['nama_guru'] = $teacher_result['nama_guru'];
    }
}

// Get the class that the wali teaches
$wali_kelas_stmt = $pdo->prepare("SELECT id_kelas, nama_kelas FROM tb_kelas WHERE wali_kelas = ?");
$wali_kelas_stmt->execute([$teacher_name]);
$wali_kelas = $wali_kelas_stmt->fetch(PDO::FETCH_ASSOC);

if (!$wali_kelas) {
    die('<div class="alert alert-danger">Anda belum ditugaskan sebagai Wali Kelas untuk kelas manapun. Silakan hubungi Administrator.</div>');
}

$id_kelas = $wali_kelas['id_kelas'];
$nama_kelas = $wali_kelas['nama_kelas'];

// Handle form submission for attendance
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_attendance'])) {
    // Validate that the posted class ID matches the wali's class
    if ((int)$_POST['id_kelas'] !== (int)$id_kelas) {
        die('Unauthorized class modification');
    }
    
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
    $username = isset($_SESSION['nama_guru']) ? $_SESSION['nama_guru'] : (isset($_SESSION['username']) ? $_SESSION['username'] : 'Wali Kelas');
    if (function_exists('logActivity')) {
        logActivity($pdo, $_SESSION['user_id'], 'Input Sholat Berjamaah', "Wali Kelas $username melakukan input sholat berjamaah kelas ID: $id_kelas untuk $saved_count siswa");
    }
}

// Get students for the class
$tanggal = isset($_GET['tanggal']) && !empty($_GET['tanggal']) ? $_GET['tanggal'] : date('Y-m-d');
$students = [];

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
            <h1>Sholat Berjamaah - Kelas <?php echo htmlspecialchars($nama_kelas); ?></h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Absensi Siswa</div>
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
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Tanggal</label>
                                        <input type="date" class="form-control" name="tanggal" id="tanggalInput" value="<?php echo $tanggal; ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                     <div class="form-group">
                                        <label>Kelas</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($nama_kelas); ?>" readonly>
                                    </div>
                                </div>
                            </div>
                        </form>
                        
                        <?php if (!empty($students)): ?>
                        <form method="POST" action="">
                            <input type="hidden" name="id_kelas" value="<?php echo $id_kelas; ?>">
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
                        <?php else: ?>
                        <div class="alert alert-info">
                            <p class="text-center mb-0">Belum ada siswa dalam kelas ini.</p>
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
    // Auto-submit when date is selected
    $('#tanggalInput').on('change', function() {
        $('#filterForm').submit();
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