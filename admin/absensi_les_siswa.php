<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has allowed level
if (!isAuthorized(['admin', 'wali', 'guru', 'tata_usaha'])) {
    redirect('../login.php');
}

// Get school profile
$school_profile = getSchoolProfile($pdo);

// Get all Grade 6 Classes
$stmt_grade6_all = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas WHERE nama_kelas LIKE '%VI%' OR nama_kelas LIKE '%6%' ORDER BY nama_kelas ASC");
$all_grade6_classes = $stmt_grade6_all->fetchAll(PDO::FETCH_ASSOC);

// Determine which classes to show
$user_level = getUserLevel();
$current_guru_id = null;
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

$classes_to_show = [];
if (in_array($user_level, ['admin', 'tata_usaha', 'kepala_madrasah'])) {
    $classes_to_show = $all_grade6_classes;
} elseif ($current_guru_id) {
    // Get teacher's classes
    $stmt_g = $pdo->prepare("SELECT mengajar FROM tb_guru WHERE id_guru = ?");
    $stmt_g->execute([$current_guru_id]);
    $mengajar_json = (string)$stmt_g->fetchColumn();
    $mengajar_arr = json_decode($mengajar_json, true) ?? [];
    
    // Filter to Grade 6 only
    foreach ($all_grade6_classes as $cls) {
        if (in_array($cls['id_kelas'], $mengajar_arr) || in_array($cls['nama_kelas'], $mengajar_arr)) {
            $classes_to_show[] = $cls;
        }
    }
}

// If no classes to show but it's grade 6 wali, try to find by wali_kelas name
if (empty($classes_to_show) && $user_level === 'wali' && isset($_SESSION['nama_guru'])) {
    $stmt_wali_check = $pdo->prepare("SELECT id_kelas, nama_kelas FROM tb_kelas WHERE wali_kelas = ? AND (nama_kelas LIKE '%VI%' OR nama_kelas LIKE '%6%')");
    $stmt_wali_check->execute([$_SESSION['nama_guru']]);
    $classes_to_show = $stmt_wali_check->fetchAll(PDO::FETCH_ASSOC);
    
    // If still empty, maybe they are just authorized to see all grade 6 (e.g. if they teach all grade 6)
    if (empty($classes_to_show)) $classes_to_show = $all_grade6_classes;
}

// Default selection
$id_kelas_selected = isset($_GET['kelas']) ? (int)$_GET['kelas'] : (count($classes_to_show) > 0 ? $classes_to_show[0]['id_kelas'] : 0);
if ($id_kelas_selected == 0 && count($all_grade6_classes) > 0) {
    $id_kelas_selected = $all_grade6_classes[0]['id_kelas'];
}

// Get selected class name
$nama_kelas_selected = '';
foreach ($all_grade6_classes as $cls) {
    if ($cls['id_kelas'] == $id_kelas_selected) {
        $nama_kelas_selected = $cls['nama_kelas'];
        break;
    }
}

// Check if there is a schedule for selected date
$tanggal = isset($_GET['tanggal']) && !empty($_GET['tanggal']) ? $_GET['tanggal'] : date('Y-m-d');
$stmt_check_sched = $pdo->prepare("SELECT COUNT(*) FROM tb_jadwal_les WHERE tanggal = ?");
$stmt_check_sched->execute([$tanggal]);
$has_schedule = $stmt_check_sched->fetchColumn() > 0;

// Handle form submission for attendance
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_attendance'])) {
    if (!$has_schedule) {
        $message = ['type' => 'danger', 'text' => 'Tidak ada jadwal les untuk tanggal ini. Kehadiran tidak dapat disimpan.'];
    } else {
        $id_kelas = (int)$_POST['id_kelas'];
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
                WHERE jl.id_guru = ? 
                AND jl.tanggal = ?
            ");
            $stmt_check_teacher->execute([$current_guru_id, $tanggal]);
            $has_teacher_schedule = $stmt_check_teacher->fetchColumn() > 0;
            
            if (!$has_teacher_schedule) {
                $validation_error = true;
                $message = ['type' => 'danger', 'text' => 'Anda tidak memiliki jadwal les pada tanggal tersebut. Tidak dapat mengisi kehadiran.'];
            }
        }
        
        if (!$validation_error) {
            $saved_count = 0;
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'keterangan_') === 0) {
                    $id_siswa = (int)str_replace('keterangan_', '', $key);
                    $status = $value;

                    $check_stmt = $pdo->prepare("SELECT id_absensi_les FROM tb_absensi_les WHERE id_siswa = ? AND tanggal = ?");
                    $check_stmt->execute([$id_siswa, $tanggal]);
                    $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

                    // Reset ke Belum Absen (hapus record kehadiran jika ada)
                    if ($status === '') {
                        if ($existing) {
                            $delete_stmt = $pdo->prepare("DELETE FROM tb_absensi_les WHERE id_absensi_les = ?");
                            $delete_stmt->execute([$existing['id_absensi_les']]);
                            $saved_count++;
                        }
                        continue;
                    }

                    if (!in_array($status, ['Hadir', 'Sakit', 'Izin', 'Alpa'])) {
                        continue;
                    }
                    
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
            
            $message = ['type' => 'success', 'text' => "Data kehadiran les berhasil disimpan untuk $saved_count siswa!"];
            $username = $_SESSION['username'] ?? 'system';
            
            // Get class name for logging
            $stmt_cn = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas = ?");
            $stmt_cn->execute([$id_kelas]);
            $log_class_name = $stmt_cn->fetchColumn() ?: 'Unknown';
            
            logActivity($pdo, $username, 'Input Absensi Les', "Melakukan input absensi les siswa kelas $log_class_name untuk $saved_count siswa");

            // Add notification for Admin/Kepala
            if (in_array($user_level, ['guru', 'wali'])) {
                $nama_guru = $_SESSION['nama_guru'] ?? $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Guru';
                $role_label = ($user_level == 'wali') ? 'Wali' : 'Guru';
                $msg = "$nama_guru ($role_label) telah mengisi kehadiran les siswa kelas $log_class_name pada " . date('d-m-Y H:i');
                createNotification($pdo, $msg, 'absensi_les_siswa.php');
            }
        }
    }
}

// Get students for selected class
$students = [];
try {
    $stmt = $pdo->prepare("SELECT s.*, a.status as keterangan 
                           FROM tb_siswa s 
                           LEFT JOIN tb_absensi_les a ON s.id_siswa = a.id_siswa AND a.tanggal = ? 
                           WHERE s.id_kelas = ? 
                           ORDER BY s.nama_siswa ASC");
    $stmt->execute([$tanggal, $id_kelas_selected]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $students = [];
}

$page_title = 'Kehadiran Les Siswa';
$css_libs = ['https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Kehadiran Les Siswa</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Form Kehadiran Les Siswa</h4>
                            <?php if (!$has_schedule): ?>
                                <div class="card-header-action">
                                    <span class="badge badge-warning">Tidak Ada Jadwal Les Hari Ini</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="" id="filterForm">
                                <div class="row">
                                    <?php if (count($classes_to_show) > 1): ?>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label>Kelas</label>
                                            <select class="form-control" name="kelas" id="kelasSelect">
                                                <?php foreach ($classes_to_show as $cls): ?>
                                                <option value="<?php echo $cls['id_kelas']; ?>" <?php echo $id_kelas_selected == $cls['id_kelas'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($cls['nama_kelas']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                        <input type="hidden" name="kelas" value="<?php echo $id_kelas_selected; ?>">
                                    <?php endif; ?>
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
                                <input type="hidden" name="id_kelas" value="<?php echo $id_kelas_selected; ?>">
                                <input type="hidden" name="tanggal" value="<?php echo $tanggal; ?>">
                                <input type="hidden" name="save_attendance" value="1">
                                
                                <div class="table-responsive">
                                    <table class="table table-striped" id="table-1">
                                        <thead>
                                            <tr>
                                                <th width="5%">No</th>
                                                <th>Nama Siswa</th>
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
                                                <td>
                                                    <?php $status_now = $student['keterangan'] ?? 'Hadir'; ?>
                                                    <div class="btn-group btn-group-sm attendance-btn-group <?php echo !$has_schedule ? 'disabled' : ''; ?>" role="group">
                                                        <button type="button" class="btn btn-success btn-kehadiran-siswa <?php echo $status_now === 'Hadir' ? 'active' : ''; ?>" data-id="<?php echo $student['id_siswa']; ?>" data-status="Hadir">
                                                            <i class="fas fa-check"></i> Hadir
                                                        </button>
                                                        <button type="button" class="btn btn-warning btn-kehadiran-siswa <?php echo $status_now === 'Sakit' ? 'active' : ''; ?>" data-id="<?php echo $student['id_siswa']; ?>" data-status="Sakit">
                                                            <i class="fas fa-procedures"></i> Sakit
                                                        </button>
                                                        <button type="button" class="btn btn-info btn-kehadiran-siswa <?php echo $status_now === 'Izin' ? 'active' : ''; ?>" data-id="<?php echo $student['id_siswa']; ?>" data-status="Izin">
                                                            <i class="fas fa-envelope-open-text"></i> Izin
                                                        </button>
                                                        <button type="button" class="btn btn-danger btn-kehadiran-siswa <?php echo $status_now === 'Alpa' ? 'active' : ''; ?>" data-id="<?php echo $student['id_siswa']; ?>" data-status="Alpa">
                                                            <i class="fas fa-user-times"></i> Alpa
                                                        </button>
                                                    </div>
                                                    <input type="hidden" class="student-status-input" id="status_<?php echo $student['id_siswa']; ?>" name="keterangan_<?php echo $student['id_siswa']; ?>" value="<?php echo htmlspecialchars($status_now, ENT_QUOTES); ?>">
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="row mt-4">
                                    <div class="col-12 text-center">
                                        <button type="submit" class="btn btn-primary" id="saveAttendanceBtn" <?php echo !$has_schedule ? 'disabled' : ''; ?>>
                                            <i class="fas fa-save"></i> Simpan Kehadiran
                                        </button>
                                    </div>
                                </div>
                            </form>
                            <?php elseif (empty($students)): ?>
                                <div class="alert alert-info text-center">Data siswa tidak ditemukan untuk kelas <?php echo $nama_kelas_selected; ?>.</div>
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
    
    $('#tanggalInput, #kelasSelect').on('change', function() {
        $('#filterForm').submit();
    });

    $('.attendance-btn-group.disabled .btn-kehadiran-siswa').prop('disabled', true);

    // Handle klik tombol status untuk tiap siswa
    $('.btn-kehadiran-siswa').on('click', function() {
        if ($(this).prop('disabled')) {
            return;
        }
        var studentId = $(this).data('id');
        var status = $(this).data('status');
        var input = $('#status_' + studentId);
        var badge = $('#badge_' + studentId);
        var currentStatus = input.val();

        // Klik ulang status yang sama = reset ke Belum Absen
        var nextStatus = (currentStatus === status) ? '' : status;
        var group = $(this).closest('.attendance-btn-group');
        group.find('.btn-kehadiran-siswa').removeClass('active');
        if (nextStatus !== '') {
            group.find('.btn-kehadiran-siswa[data-status=\"' + nextStatus + '\"]').addClass('active');
        }

        input.val(nextStatus);
        badge.text(nextStatus !== '' ? nextStatus : 'Belum Absen');
        badge.removeClass('badge-success badge-warning badge-info badge-danger badge-secondary');

        switch(nextStatus) {
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
