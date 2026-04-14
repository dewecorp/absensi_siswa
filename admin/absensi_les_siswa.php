<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has allowed level
if (!isAuthorized(['admin', 'wali', 'guru', 'tata_usaha'])) {
    redirect('../login.php');
}

// Get school profile
$school_profile = getSchoolProfile($pdo);

// Get Grade 6 Class ID
$stmt_grade6 = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas WHERE nama_kelas = 'VI' OR nama_kelas = '6' LIMIT 1");
$class_grade6 = $stmt_grade6->fetch(PDO::FETCH_ASSOC);
$id_kelas_fixed = $class_grade6 ? $class_grade6['id_kelas'] : 6;
$nama_kelas_fixed = $class_grade6 ? $class_grade6['nama_kelas'] : 'VI';

// Check if there is a schedule for selected date
$tanggal = isset($_GET['tanggal']) && !empty($_GET['tanggal']) ? $_GET['tanggal'] : date('Y-m-d');
$stmt_check_sched = $pdo->prepare("SELECT COUNT(*) FROM tb_jadwal_les WHERE tanggal = ?");
$stmt_check_sched->execute([$tanggal]);
$has_schedule = $stmt_check_sched->fetchColumn() > 0;

// Handle form submission for attendance
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_attendance'])) {
    if (!$has_schedule) {
        $message = ['type' => 'danger', 'text' => 'Tidak ada jadwal les untuk tanggal ini. Absensi tidak dapat disimpan.'];
    } else {
        $id_kelas = $id_kelas_fixed;
        $tanggal = $_POST['tanggal'];
        
        // Get current user's guru ID if applicable
        $current_guru_id = null;
        $user_level = getUserLevel();
        if (in_array($user_level, ['guru', 'wali'])) {
            if (isset($_SESSION['user_id'])) {
                $id_check = $_SESSION['user_id'];
                if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
                    $stmt_uid = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
                    $stmt_uid->execute([$id_check]);
                    $current_guru_id = $stmt_uid->fetchColumn();
                } else {
                    $current_guru_id = $id_check;
                }
            }
        }
        
        // Validate if teacher has schedule for this class and date
        $validation_error = false;
        if ($current_guru_id && !in_array($user_level, ['admin', 'tata_usaha', 'kepala_madrasah'])) {
            // Check if teacher has a les schedule for today
            $stmt_check_teacher = $pdo->prepare("
                SELECT COUNT(*) 
                FROM tb_jadwal_les jl
                INNER JOIN tb_jadwal_pelajaran jp ON jl.id_guru = jp.guru_id
                WHERE jl.id_guru = ? 
                AND jl.tanggal = ?
                AND jp.kelas_id = ?
            ");
            $stmt_check_teacher->execute([$current_guru_id, $tanggal, $id_kelas]);
            $has_teacher_schedule = $stmt_check_teacher->fetchColumn() > 0;
            
            if (!$has_teacher_schedule) {
                $validation_error = true;
                $message = ['type' => 'danger', 'text' => 'Anda tidak memiliki jadwal les untuk kelas ini pada tanggal tersebut. Tidak dapat mengisi absensi.'];
            }
        }
        
        if (!$validation_error) {
            // Note: Tutoring attendance does NOT check school holidays
            // It only depends on whether there is a tutoring schedule (tb_jadwal_les)
            // This allows tutoring on holidays like Fridays if scheduled
            
            $saved_count = 0;
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'keterangan_') === 0) {
                    $id_siswa = (int)str_replace('keterangan_', '', $key);
                    $status = $value;
                    
                    if (!in_array($status, ['Hadir', 'Sakit', 'Izin', 'Alpa'])) {
                        continue;
                    }
                    
                    $check_stmt = $pdo->prepare("SELECT id_absensi_les FROM tb_absensi_les WHERE id_siswa = ? AND tanggal = ?");
                    $check_stmt->execute([$id_siswa, $tanggal]);
                    $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        $update_stmt = $pdo->prepare("UPDATE tb_absensi_les SET status = ?, waktu_input = NOW() WHERE id_absensi_les = ?");
                        $update_stmt->execute([$status, $existing['id_absensi_les']]);
                    } else {
                        $insert_stmt = $pdo->prepare("INSERT INTO tb_absensi_les (id_siswa, tanggal, status) VALUES (?, ?, ?)");
                        $insert_stmt->execute([$id_siswa, $tanggal, $status]);
                    }
                    $saved_count++;
                }
            }
            
            $message = ['type' => 'success', 'text' => "Data absensi les berhasil disimpan untuk $saved_count siswa!"];
            $username = $_SESSION['username'] ?? 'system';
            logActivity($pdo, $username, 'Input Absensi Les', "Melakukan input absensi les siswa kelas $nama_kelas_fixed untuk $saved_count siswa");

            // Add notification for Admin/Kepala
            if (in_array($user_level, ['guru', 'wali'])) {
                $nama_guru = $_SESSION['nama_guru'] ?? $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Guru';
                $role_label = ($user_level == 'wali') ? 'Wali' : 'Guru';
                $msg = "$nama_guru ($role_label) telah mengisi absensi les siswa kelas $nama_kelas_fixed pada " . date('d-m-Y H:i');
                createNotification($pdo, $msg, 'absensi_les_siswa.php');
            }
        }
    }
}

// Get students for fixed Grade 6 class
$students = [];
try {
    $stmt = $pdo->prepare("SELECT s.*, a.status as keterangan 
                           FROM tb_siswa s 
                           LEFT JOIN tb_absensi_les a ON s.id_siswa = a.id_siswa AND a.tanggal = ? 
                           WHERE s.id_kelas = ? 
                           ORDER BY s.nama_siswa ASC");
    $stmt->execute([$tanggal, $id_kelas_fixed]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $students = [];
}

$page_title = 'Absensi Les Siswa';
$css_libs = ['https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Absensi Les Siswa</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Absensi Les Siswa</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Form Absensi Les Siswa</h4>
                            <?php if (!$has_schedule): ?>
                                <div class="card-header-action">
                                    <span class="badge badge-warning">Tidak Ada Jadwal Les Hari Ini</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="" id="filterForm">
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label>Kelas</label>
                                            <input type="text" class="form-control" value="<?php echo $nama_kelas_fixed; ?>" readonly>
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
                            <form method="POST" action="" id="attendanceForm">
                                <input type="hidden" name="id_kelas" value="<?php echo $id_kelas_fixed; ?>">
                                <input type="hidden" name="tanggal" value="<?php echo $tanggal; ?>">
                                <input type="hidden" name="save_attendance" value="1">
                                
                                <div class="table-responsive">
                                    <table class="table table-striped" id="table-1">
                                        <thead>
                                            <tr>
                                                <th width="5%">No</th>
                                                <th>Nama Siswa</th>
                                                <th>NISN</th>
                                                <th width="25%">Status Kehadiran</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($students as $index => $student): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($student['nama_siswa']); ?>
                                                    <span class="ml-2 badge <?php 
                                                        $status_badge = $student['keterangan'] ?? 'Hadir';
                                                        switch($status_badge) {
                                                            case 'Hadir': echo 'badge-success'; break;
                                                            case 'Sakit': echo 'badge-warning'; break;
                                                            case 'Izin': echo 'badge-info'; break;
                                                            case 'Alpa': echo 'badge-danger'; break;
                                                            default: echo 'badge-secondary';
                                                        }
                                                    ?>" id="badge_<?php echo $student['id_siswa']; ?>">
                                                        <?php echo $status_badge; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($student['nisn']); ?></td>
                                                <td>
                                                    <select class="form-control student-status" id="status_<?php echo $student['id_siswa']; ?>" name="keterangan_<?php echo $student['id_siswa']; ?>" <?php echo !$has_schedule ? 'disabled' : ''; ?>>
                                                        <option value="Hadir" <?php echo ($student['keterangan'] ?? 'Hadir') === 'Hadir' ? 'selected' : ''; ?>>Hadir</option>
                                                        <option value="Sakit" <?php echo ($student['keterangan'] ?? '') === 'Sakit' ? 'selected' : ''; ?>>Sakit</option>
                                                        <option value="Izin" <?php echo ($student['keterangan'] ?? '') === 'Izin' ? 'selected' : ''; ?>>Izin</option>
                                                        <option value="Alpa" <?php echo ($student['keterangan'] ?? '') === 'Alpa' ? 'selected' : ''; ?>>Alpa</option>
                                                    </select>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="row mt-4">
                                    <div class="col-12 text-center">
                                        <button type="submit" class="btn btn-primary" id="saveAttendanceBtn" <?php echo !$has_schedule ? 'disabled' : ''; ?>>
                                            <i class="fas fa-save"></i> Simpan Absensi
                                        </button>
                                    </div>
                                </div>
                            </form>
                            <?php elseif (empty($students)): ?>
                                <div class="alert alert-info text-center">Data siswa tidak ditemukan untuk kelas <?php echo $nama_kelas_fixed; ?>.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js'
];

$js_page = [];

if (isset($message)) {
    $js_page[] = "
    Swal.fire({
        title: '" . ($message['type'] === 'success' ? 'Sukses!' : 'Gagal!') . "',
        text: '" . addslashes($message['text']) . "',
        icon: '" . $message['type'] . "',
        timer: 3000,
        showConfirmButton: false
    });
    ";
}

if (!$has_schedule) {
    $js_page[] = "
    Swal.fire({
        icon: 'warning',
        title: 'Tidak Ada Jadwal',
        text: 'Tidak ada jadwal les untuk tanggal " . formatDateIndonesia($tanggal) . ". Tombol simpan dinonaktifkan.',
        confirmButtonText: 'Tutup'
    });
    ";
}

$js_page[] = "
$(document).ready(function() {
    $('#table-1').DataTable({
        'language': { 'url': 'https://cdn.datatables.net/plug-ins/1.10.25/i18n/Indonesian.json' },
        'pageLength': 50
    });
    
    // No alert on dropdown change - just allow user to change values
    // User can click 'Simpan Semua Perubahan' button to save
    
    $('#tanggalInput').on('change', function() {
        $('#filterForm').submit();
    });

    // Handle status change to update badge
    $('.student-status').on('change', function() {
        var selectedOption = this.options[this.selectedIndex].text;
        var selectedValue = this.value;
        var studentId = this.id.replace('status_', '');
        var badge = $('#badge_' + studentId);
        
        badge.text(selectedOption);
        badge.removeClass('badge-success badge-warning badge-info badge-danger badge-secondary');
        
        switch(selectedValue) {
            case 'Hadir': badge.addClass('badge-success'); break;
            case 'Sakit': badge.addClass('badge-warning'); break;
            case 'Izin': badge.addClass('badge-info'); break;
            case 'Alpa': badge.addClass('badge-danger'); break;
            default: badge.addClass('badge-secondary');
        }
    });
});
";

include '../templates/footer.php';
?>
