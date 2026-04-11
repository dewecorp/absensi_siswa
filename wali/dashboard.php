<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has wali level
if (!isAuthorized(['wali'])) {
    redirect('../login.php');
}

// Get school profile
$school_profile = getSchoolProfile($pdo);

// Get teacher information
$teacher = null;
if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_guru') {
    // Direct login via NUPTK
    $stmt = $pdo->prepare("SELECT * FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
    // Login via tb_pengguna
    $stmt = $pdo->prepare("SELECT g.* FROM tb_guru g JOIN tb_pengguna p ON g.id_guru = p.id_guru WHERE p.id_pengguna = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    // Fallback for sessions without login_source (legacy/existing sessions)
    // Try direct first
    $stmt = $pdo->prepare("SELECT * FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If not found, try join
    if (!$teacher) {
        $stmt = $pdo->prepare("SELECT g.* FROM tb_guru g JOIN tb_pengguna p ON g.id_guru = p.id_guru WHERE p.id_pengguna = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if ($teacher) {
    $teacher_name = $teacher['nama_guru'];
    if (!isset($_SESSION['nama_guru']) || empty($_SESSION['nama_guru'])) {
        $_SESSION['nama_guru'] = $teacher['nama_guru'];
    }
} else {
    $teacher_name = $_SESSION['username'];
}

// Get the class that the wali teaches
$wali_kelas_stmt = $pdo->prepare("SELECT id_kelas, nama_kelas FROM tb_kelas WHERE wali_kelas = ?");
$wali_kelas_stmt->execute([$teacher_name]);
$wali_kelas = $wali_kelas_stmt->fetch(PDO::FETCH_ASSOC);

// Get student count for the wali's class
if ($wali_kelas) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_siswa, 
                                 SUM(CASE WHEN jenis_kelamin = 'L' THEN 1 ELSE 0 END) as total_laki,
                                 SUM(CASE WHEN jenis_kelamin = 'P' THEN 1 ELSE 0 END) as total_perempuan
                          FROM tb_siswa WHERE id_kelas = ?");
    $stmt->execute([$wali_kelas['id_kelas']]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_siswa = $stats['total_siswa'] ?? 0;
    $total_laki = $stats['total_laki'] ?? 0;
    $total_perempuan = $stats['total_perempuan'] ?? 0;
} else {
    $total_siswa = 0;
    $total_laki = 0;
    $total_perempuan = 0;
}

// Get today's attendance statistics for the wali's class
$today = date('Y-m-d');

// Check if there is a tutoring schedule today (for Grade 6)
$is_grade_6_wali = false;
if ($wali_kelas) {
    $class_name = strtoupper($wali_kelas['nama_kelas']);
    if (strpos($class_name, '6') !== false || strpos($class_name, 'VI') !== false) {
        $is_grade_6_wali = true;
    }
}

$stmt_check_sched = $pdo->prepare("SELECT COUNT(*) FROM tb_jadwal_les WHERE tanggal = ?");
$stmt_check_sched->execute([$today]);
$has_les_schedule = $stmt_check_sched->fetchColumn() > 0;

// Get tutoring attendance if schedule exists
$today_les_attendance = null;
if ($has_les_schedule && $teacher) {
    $stmt_check_les = $pdo->prepare("SELECT * FROM tb_absensi_les_guru WHERE id_guru = ? AND tanggal = ?");
    $stmt_check_les->execute([$teacher['id_guru'], $today]);
    $today_les_attendance = $stmt_check_les->fetch(PDO::FETCH_ASSOC);
}

// Determine which attendance table to use for STUDENTS
// Main dashboard always shows regular attendance (tb_absensi)
$student_attendance_table = 'tb_absensi';

if ($wali_kelas) {
    $stmt = $pdo->prepare("
        SELECT a.keterangan, COUNT(*) as jumlah 
        FROM $student_attendance_table a 
        JOIN tb_siswa s ON a.id_siswa = s.id_siswa 
        WHERE s.id_kelas = ? AND a.tanggal = ? 
        GROUP BY a.keterangan
    ");
    $stmt->execute([$wali_kelas['id_kelas'], $today]);
    $attendance_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $attendance_stats = [];
}

// Initialize counts
$jumlah_hadir = 0;
$jumlah_sakit = 0;
$jumlah_izin = 0;
$jumlah_alpa = 0;
$jumlah_berhalangan = 0;
foreach ($attendance_stats as $stat) {
    switch ($stat['keterangan']) {
        case 'Hadir':
            $jumlah_hadir = $stat['jumlah'];
            break;
        case 'Sakit':
            $jumlah_sakit = $stat['jumlah'];
            break;
        case 'Izin':
            $jumlah_izin = $stat['jumlah'];
            break;
        case 'Alpa':
            $jumlah_alpa = $stat['jumlah'];
            break;
        case 'Berhalangan':
            $jumlah_berhalangan = $stat['jumlah'];
            break;
    }
}

// Include Berhalangan from sholat data (siswa putri berhalangan)
if ($wali_kelas) {
    $berhalangan_stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT s.id_siswa) AS jumlah
        FROM tb_sholat sh
        JOIN tb_siswa s ON sh.id_siswa = s.id_siswa
        WHERE s.id_kelas = ? 
          AND sh.tanggal = ? 
          AND sh.status = 'Berhalangan'
    ");
    $berhalangan_stmt->execute([$wali_kelas['id_kelas'], $today]);
    $berhalangan_row = $berhalangan_stmt->fetch(PDO::FETCH_ASSOC);
    $jumlah_berhalangan_sholat = (int)($berhalangan_row['jumlah'] ?? 0);

    if ($jumlah_berhalangan_sholat > $jumlah_berhalangan) {
        $jumlah_berhalangan = $jumlah_berhalangan_sholat;
    }
}

// Get attendance trend data for the current month
$trend_stmt = null;
$attendance_trends = [];

if ($wali_kelas) {
    $trend_stmt = $pdo->prepare(
        "SELECT 
            DATE(a.tanggal) as tanggal,
            SUM(CASE WHEN a.keterangan = 'Hadir' THEN 1 ELSE 0 END) as hadir,
            SUM(CASE WHEN a.keterangan = 'Sakit' THEN 1 ELSE 0 END) as sakit,
            SUM(CASE WHEN a.keterangan = 'Izin' THEN 1 ELSE 0 END) as izin,
            SUM(CASE WHEN a.keterangan = 'Alpa' THEN 1 ELSE 0 END) as alpa,
            SUM(CASE WHEN a.keterangan = 'Berhalangan' THEN 1 ELSE 0 END) as berhalangan
        FROM tb_absensi a
        JOIN tb_siswa s ON a.id_siswa = s.id_siswa
        WHERE s.id_kelas = ? AND a.tanggal >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
        GROUP BY DATE(a.tanggal)
        ORDER BY DATE(a.tanggal) ASC"
    );
    $trend_stmt->execute([$wali_kelas['id_kelas']]);
    $attendance_trends = $trend_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get Berhalangan trend from tb_sholat to merge with attendance
    $sholat_trend_stmt = $pdo->prepare(
        "SELECT 
            DATE(sh.tanggal) as tanggal,
            COUNT(DISTINCT sh.id_siswa) as berhalangan_sholat
        FROM tb_sholat sh
        JOIN tb_siswa s ON sh.id_siswa = s.id_siswa
        WHERE s.id_kelas = ? 
          AND sh.tanggal >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND sh.status = 'Berhalangan'
        GROUP BY DATE(sh.tanggal)"
    );
    $sholat_trend_stmt->execute([$wali_kelas['id_kelas']]);
    $sholat_trends = $sholat_trend_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Merge sholat trends into attendance trends
    foreach ($attendance_trends as &$trend) {
        $tgl = $trend['tanggal'];
        if (isset($sholat_trends[$tgl])) {
            // Take the higher count (similar to daily logic)
            if ($sholat_trends[$tgl] > $trend['berhalangan']) {
                $trend['berhalangan'] = $sholat_trends[$tgl];
            }
        }
    }
    unset($trend); // break reference
}

// Prepare data for chart
$dates = [];
$hadir_data = [];
$sakit_data = [];
$izin_data = [];
$alpa_data = [];
$berhalangan_data = [];

foreach ($attendance_trends as $trend) {
    $dates[] = $trend['tanggal'] ? date('d M', strtotime($trend['tanggal'])) : '';
    $hadir_data[] = isset($trend['hadir']) ? (int)$trend['hadir'] : 0;
    $sakit_data[] = isset($trend['sakit']) ? (int)$trend['sakit'] : 0;
    $izin_data[] = isset($trend['izin']) ? (int)$trend['izin'] : 0;
    $alpa_data[] = isset($trend['alpa']) ? (int)$trend['alpa'] : 0;
    $berhalangan_data[] = isset($trend['berhalangan']) ? (int)$trend['berhalangan'] : 0;
}

// Convert arrays to JSON-safe format
$dates_json = json_encode($dates);
$hadir_data_json = json_encode($hadir_data);
$sakit_data_json = json_encode($sakit_data);
$izin_data_json = json_encode($izin_data);
$alpa_data_json = json_encode($alpa_data);
$berhalangan_data_json = json_encode($berhalangan_data);

// Calculate total classes taught by this wali (as a teacher)
$total_kelas_ajar = 0;
if ($teacher && !empty($teacher['mengajar'])) {
    $mengajar_decoded = json_decode($teacher['mengajar'], true);
    if (is_array($mengajar_decoded)) {
        $total_kelas_ajar = count($mengajar_decoded);
    }
}

// Background Image
$hero_bg = !empty($school_profile['dashboard_hero_image']) 
    ? '../assets/img/' . $school_profile['dashboard_hero_image'] 
    : '../assets/img/unsplash/eberhard-grossgasteiger-1207565-unsplash.jpg';

// Handle Attendance Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_teacher_id = 0;
    if (isset($teacher['id_guru'])) {
        $current_teacher_id = $teacher['id_guru'];
    } elseif (isset($_SESSION['user_id']) && ($_SESSION['level'] == 'guru' || $_SESSION['level'] == 'wali')) {
         $current_teacher_id = $_SESSION['user_id'];
    }
    
    if ($current_teacher_id > 0) {
        $current_date = date('Y-m-d');
        $now_time = date('Y-m-d H:i:s');
        $nama_guru = $_SESSION['nama_guru'] ?? 'Wali Kelas';

        if (isset($_POST['submit_attendance'])) {
            $attendance_status = $_POST['attendance_status'];
            $attendance_note = $_POST['attendance_note'] ?? '';
            // Dashboard attendance is ALWAYS regular (tb_absensi_guru)
            // Regular attendance still checks holidays
            $holiday = isSchoolHoliday($pdo, $current_date);
            if ($holiday['is_holiday']) {
                echo "<script>
                    document.addEventListener('DOMContentLoaded', function() {
                        Swal.fire({
                            title: 'Hari Libur',
                            text: 'Absensi ditutup pada hari libur: " . addslashes($holiday['name']) . "',
                            icon: 'warning',
                            timer: 4000,
                            showConfirmButton: true
                        });
                    });
                </script>";
                goto after_submission_wali;
            }
            
            // Check if already attended regular
            $check_stmt = $pdo->prepare("SELECT id_absensi FROM tb_absensi_guru WHERE id_guru = ? AND tanggal = ?");
            $check_stmt->execute([$current_teacher_id, $current_date]);
            
            $status_to_save = ucfirst($_POST['attendance_status']);
            $attendance_note = $_POST['attendance_note'] ?? '';
            
            if ($check_stmt->rowCount() > 0) {
                // Update existing
                 $update_stmt = $pdo->prepare("UPDATE tb_absensi_guru SET status = ?, keterangan = ?, waktu_input = ? WHERE id_guru = ? AND tanggal = ?");
                 if ($update_stmt->execute([$status_to_save, $attendance_note, $now_time, $current_teacher_id, $current_date])) {
                     echo "<script>
                        document.addEventListener('DOMContentLoaded', function() {
                            Swal.fire({
                                title: 'Berhasil!',
                                text: 'Absensi berhasil diperbarui.',
                                icon: 'success',
                                timer: 3000,
                                timerProgressBar: true,
                                showConfirmButton: false
                            });
                        });
                     </script>";
                 }
            } else {
                // Insert new
                $insert_stmt = $pdo->prepare("INSERT INTO tb_absensi_guru (id_guru, tanggal, status, keterangan, waktu_input) VALUES (?, ?, ?, ?, ?)");
                if ($insert_stmt->execute([$current_teacher_id, $current_date, $status_to_save, $attendance_note, $now_time])) {
                     
                     // Send notification to admin
                     $waktu = date('H:i');
                     $tanggal_indo = date('d-m-Y');
                     $notif_msg = "$nama_guru (Wali) telah mengirim kehadiran pada pukul $waktu tanggal $tanggal_indo";
                     createNotification($pdo, $notif_msg, 'absensi_guru.php', 'absensi_guru');

                     // Log activity
                     $log_desc = "$nama_guru (Wali) memperbarui kehadiran: " . $_POST['attendance_status'];
                     if ($attendance_note) $log_desc .= " ($attendance_note)";
                     logActivity($pdo, $nama_guru, 'Absensi Guru', $log_desc);

                     echo "<script>
                        document.addEventListener('DOMContentLoaded', function() {
                            Swal.fire({
                                title: 'Berhasil!',
                                text: 'Absensi berhasil disimpan.',
                                icon: 'success',
                                timer: 3000,
                                timerProgressBar: true,
                                showConfirmButton: false
                            });
                        });
                     </script>";
                }
            }
        } elseif (isset($_POST['submit_attendance_les'])) {
            // Tutoring attendance - specific for Grade 6
            // Check if there is a schedule today
            $stmt_check_sched_post = $pdo->prepare("SELECT COUNT(*) FROM tb_jadwal_les WHERE tanggal = ?");
            $stmt_check_sched_post->execute([$current_date]);
            
            if ($stmt_check_sched_post->fetchColumn() > 0) {
                $status_to_save_les = isset($_POST['attendance_status_les']) ? ucfirst($_POST['attendance_status_les']) : '';
                $attendance_note_les = $_POST['attendance_note_les'] ?? '';

                $check_stmt = $pdo->prepare("SELECT id_absensi FROM tb_absensi_les_guru WHERE id_guru = ? AND tanggal = ?");
                $check_stmt->execute([$current_teacher_id, $current_date]);
                
                if ($check_stmt->rowCount() > 0) {
                    $update_stmt = $pdo->prepare("UPDATE tb_absensi_les_guru SET status = ?, keterangan = ?, waktu_input = ? WHERE id_guru = ? AND tanggal = ?");
                    $update_stmt->execute([$status_to_save_les, $attendance_note_les, $now_time, $current_teacher_id, $current_date]);
                    $msg_text = 'Absensi les berhasil diperbarui.';
                } else {
                    $insert_stmt = $pdo->prepare("INSERT INTO tb_absensi_les_guru (id_guru, tanggal, status, keterangan, waktu_input) VALUES (?, ?, ?, ?, ?)");
                    $insert_stmt->execute([$current_teacher_id, $current_date, $status_to_save_les, $attendance_note_les, $now_time]);
                    $msg_text = 'Absensi les berhasil disimpan.';
                }
                
                createNotification($pdo, "$nama_guru (Wali) telah mengirim kehadiran les", 'absensi_les_guru.php', 'absensi_les_guru');
                logActivity($pdo, $nama_guru, 'Absensi Les Guru', "$nama_guru mengisi kehadiran les: $status_to_save_les");
                
                echo "<script>
                    document.addEventListener('DOMContentLoaded', function() {
                        Swal.fire({ title: 'Berhasil!', text: '$msg_text', icon: 'success', timer: 3000, showConfirmButton: false });
                    });
                </script>";
            } else {
                echo "<script>
                    document.addEventListener('DOMContentLoaded', function() {
                        Swal.fire({ title: 'Gagal', text: 'Tidak ada jadwal les untuk hari ini.', icon: 'error' });
                    });
                </script>";
            }
        }
    }
    after_submission_wali:
}

$page_title = 'Dashboard Wali Kelas';

// Define CSS libraries for this page (only essential ones)
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'
    // Removed JQVMap since files don't exist
];

// Define JS libraries for this page (only essential ones)
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js'
    // Removed JQVMap since files don't exist
];

// Define page-specific JS
$js_page = [
    "
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            var isMobile = window.matchMedia('(max-width: 576px)').matches;
            if (typeof Chart === 'undefined') {
                console.error('Chart.js library not loaded');
                return;
            }
            if (typeof Chart.defaults !== 'undefined') {
                if (typeof Chart.defaults.font !== 'undefined') {
                    Chart.defaults.font.family = 'Nunito, Segoe UI, Arial';
                    Chart.defaults.font.size = isMobile ? 13 : 12;
                    Chart.defaults.color = '#999';
                } else if (typeof Chart.defaults.global !== 'undefined') {
                    Chart.defaults.global.defaultFontFamily = 'Nunito, Segoe UI, Arial';
                    Chart.defaults.global.defaultFontSize = isMobile ? 13 : 12;
                    Chart.defaults.global.defaultFontColor = '#999';
                }
            }
            
            // Daily Attendance Chart
            var ctx = document.getElementById('myChart');
            if (ctx) {
                try {
                    var ctx2d = ctx.getContext('2d');
                    ctx.style.height = isMobile ? '280px' : '220px';
                    var myChart = new Chart(ctx2d, {
                        type: 'bar',
                        data: {
                            labels: ['Hadir', 'Sakit', 'Izin', 'Alpa', 'Berhalangan'],
                            datasets: [{
                                label: 'Jumlah Siswa',
                                data: [
                                    " . $jumlah_hadir . ",
                                    " . $jumlah_sakit . ",
                                    " . $jumlah_izin . ",
                                    " . $jumlah_alpa . ",
                                    " . $jumlah_berhalangan . "
                                ],
                                backgroundColor: [
                                    'rgba(75, 192, 192, 0.2)',
                                    'rgba(255, 206, 86, 0.2)',
                                    'rgba(54, 162, 235, 0.2)',
                                    'rgba(255, 99, 132, 0.2)',
                                    'rgba(149, 87, 245, 0.2)'
                                ],
                                borderColor: [
                                    'rgba(75, 192, 192, 1)',
                                    'rgba(255, 206, 86, 1)',
                                    'rgba(54, 162, 235, 1)',
                                    'rgba(255, 99, 132, 1)',
                                    'rgba(149, 87, 245, 1)'
                                ],
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'top',
                                },
                                title: {
                                    display: true,
                                    text: 'Statistik Kehadiran Harian'
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) { if (Number.isInteger(value)) { return value; } },
                                        font: { size: isMobile ? 12 : 11 }
                                    },
                                    title: {
                                        display: true,
                                        text: 'Jumlah Siswa'
                                    }
                                },
                                x: {
                                    ticks: { maxRotation: 0, autoSkip: true, font: { size: isMobile ? 12 : 11 } },
                                    title: {
                                        display: true,
                                        text: 'Status Kehadiran'
                                    }
                                }
                            }
                        }
                    });
                } catch (e) {
                    console.error('Error creating daily attendance chart:', e);
                }
            }
            
            // Trend Chart
            var trendCtx = document.getElementById('trendChart');
            if (trendCtx) {
                try {
                    var trendCtx2d = trendCtx.getContext('2d');
                    trendCtx.style.height = isMobile ? '300px' : '240px';
                    var trendChart = new Chart(trendCtx2d, {
                        type: 'line',
                        data: {
                            labels: " . $dates_json . ",
                            datasets: [{
                                label: 'Hadir',
                                data: " . $hadir_data_json . ",
                                borderColor: 'rgb(75, 192, 192)',
                                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                                fill: false
                            }, {
                                label: 'Sakit',
                                data: " . $sakit_data_json . ",
                                borderColor: 'rgb(255, 206, 86)',
                                backgroundColor: 'rgba(255, 206, 86, 0.2)',
                                fill: false
                            }, {
                                label: 'Izin',
                                data: " . $izin_data_json . ",
                                borderColor: 'rgb(54, 162, 235)',
                                backgroundColor: 'rgba(54, 162, 235, 0.2)',
                                fill: false
                            }, {
                                label: 'Alpa',
                                data: " . $alpa_data_json . ",
                                borderColor: 'rgb(255, 99, 132)',
                                backgroundColor: 'rgba(255, 99, 132, 0.2)',
                                fill: false
                            }, {
                                label: 'Berhalangan',
                                data: " . $berhalangan_data_json . ",
                                borderColor: 'rgb(149, 87, 245)',
                                backgroundColor: 'rgba(149, 87, 245, 0.2)',
                                fill: false
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Trend Kehadiran Bulan Ini'
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: { font: { size: isMobile ? 12 : 11 } },
                                    title: {
                                        display: true,
                                        text: 'Jumlah Siswa'
                                    }
                                },
                                x: {
                                    ticks: { maxRotation: 40, minRotation: 40, autoSkip: false, includeBounds: true, font: { size: isMobile ? 12 : 11 } },
                                    title: {
                                        display: true,
                                        text: 'Tanggal'
                                    }
                                }
                            }
                        }
                    });
                } catch (e) {
                    console.error('Error creating trend chart:', e);
                }
            }
        }, 500);
    });
    "
];

include '../templates/user_header.php';

include_once '../templates/sidebar.php';

// Start HTML output after including templates
?>

<!-- Main Content -->
<div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Dashboard Wali Kelas</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                        </div>
                    </div>

                    <!-- Profile Box -->
                    <div class="row">
                        <div class="col-12 mb-4">
                            <div class="hero text-white hero-bg-image hero-bg-parallax" style="background-image: url('<?php echo $hero_bg; ?>'); background-position: center; background-size: cover; position: relative;">
                                <div class="hero-overlay" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.6);"></div>
                                <div class="hero-inner" style="position: relative; z-index: 1;">
                                    <div class="row align-items-center">
                                        <div class="col-md-3 text-center position-relative">
                                            <div class="d-inline-block position-relative my-3">
                                                <?php 
                                                // Wrapper to ensure image style
                                                $avatar_img = getTeacherAvatarImage($teacher, 120);
                                                // Add border and shadow to image
                                                $avatar_img = str_replace('class=\'rounded-circle\'', 'class=\'rounded-circle shadow-lg border border-white\' style=\'border-width: 3px !important;\'', $avatar_img);
                                                echo $avatar_img; 
                                                ?>
                                                <div class="camera-icon-overlay" onclick="document.getElementById('foto_upload').click()">
                                                    <i class="fas fa-camera"></i>
                                                </div>
                                                <input type="file" id="foto_upload" name="foto" style="display: none;" accept="image/*">
                                            </div>
                                        </div>
                                        <div class="col-md-9">
                                            <h2>Selamat Datang, <?php echo htmlspecialchars($teacher_name); ?></h2>
                                            <p class="lead">Anda login sebagai Wali Kelas <b><?php echo $wali_kelas ? htmlspecialchars($wali_kelas['nama_kelas']) : '-'; ?></b>.</p>
                                            
                                            <div class="mt-4">
                                                <div class="row">
                                                    <div class="col-auto">
                                                        <div class="font-weight-bold text-white-50">NUPTK</div>
                                                        <div><?php echo !empty($teacher['nuptk']) ? htmlspecialchars($teacher['nuptk']) : '-'; ?></div>
                                                    </div>
                                                    <div class="col-auto">
                                                        <div class="font-weight-bold text-white-50">Tempat, Tanggal Lahir</div>
                                                        <div>
                                                            <?php 
                                                            $ttl = [];
                                                            if (!empty($teacher['tempat_lahir'])) $ttl[] = $teacher['tempat_lahir'];
                                                            if (!empty($teacher['tanggal_lahir'])) $ttl[] = date('d-m-Y', strtotime($teacher['tanggal_lahir']));
                                                            echo !empty($ttl) ? implode(', ', $ttl) : '-';
                                                            ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-auto">
                                                        <div class="font-weight-bold text-white-50">Status</div>
                                                        <div><?php echo !empty($teacher['status_kepegawaian']) ? htmlspecialchars($teacher['status_kepegawaian']) : 'Aktif'; ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Attendance Box for Teacher -->
                    <div class="row">
                        <?php
                        $show_les_box_wali = ($is_grade_6_wali && $has_les_schedule);
                        $col_class_wali = $show_les_box_wali ? 'col-12 col-md-6' : 'col-12';
                        ?>
                        <div class="<?php echo $col_class_wali; ?>">
                            <div class="card card-primary">
                                <div class="card-header">
                                    <h4>Absensi Harian & Jurnal Mengajar</h4>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-light alert-has-icon shadow-sm border">
                                        <div class="alert-icon text-primary"><i class="far fa-bell"></i></div>
                                        <div class="alert-body">
                                            <div class="alert-title font-weight-bold">Penting</div>
                                            Jangan lupa untuk mengisi <b>Absensi Kehadiran</b> Anda, <b>Absensi Siswa</b>, serta <b>Jurnal Mengajar</b> hari ini.
                                        </div>
                                    </div>

                                    <?php
                                    // Check current attendance status - ONLY use regular table for dashboard
                                    $today_attendance = null;
                                    if (isset($teacher['id_guru'])) {
                                        $stmt_check = $pdo->prepare("SELECT * FROM tb_absensi_guru WHERE id_guru = ? AND tanggal = CURDATE()");
                                        $stmt_check->execute([$teacher['id_guru']]);
                                        $today_attendance = $stmt_check->fetch(PDO::FETCH_ASSOC);
                                    }
                                    ?>

                                    <form method="POST" action="" id="attendanceForm">
                                        <div class="form-group mb-4">
                                            <label class="d-block font-weight-bold">Status Kehadiran Hari Ini (<?php echo date('d-m-Y'); ?>)</label>
                                            <div class="selectgroup selectgroup-pills">
                                                <label class="selectgroup-item">
                                                    <input type="radio" name="attendance_status" value="hadir" class="selectgroup-input" <?php echo ($today_attendance && strtolower($today_attendance['status']) == 'hadir') ? 'checked' : ''; ?> required>
                                                    <span class="selectgroup-button selectgroup-button-icon btn-outline-success <?php echo ($today_attendance && strtolower($today_attendance['status']) == 'hadir') ? 'active-hadir' : ''; ?>" data-status="hadir"><i class="fas fa-check"></i> Hadir</span>
                                                </label>
                                                <label class="selectgroup-item">
                                                    <input type="radio" name="attendance_status" value="sakit" class="selectgroup-input" <?php echo ($today_attendance && strtolower($today_attendance['status']) == 'sakit') ? 'checked' : ''; ?>>
                                                    <span class="selectgroup-button selectgroup-button-icon btn-outline-info <?php echo ($today_attendance && strtolower($today_attendance['status']) == 'sakit') ? 'active-sakit' : ''; ?>" data-status="sakit"><i class="fas fa-procedures"></i> Sakit</span>
                                                </label>
                                                <label class="selectgroup-item">
                                                    <input type="radio" name="attendance_status" value="izin" class="selectgroup-input" id="radio_izin" <?php echo ($today_attendance && strtolower($today_attendance['status']) == 'izin') ? 'checked' : ''; ?>>
                                                    <span class="selectgroup-button selectgroup-button-icon btn-outline-warning <?php echo ($today_attendance && strtolower($today_attendance['status']) == 'izin') ? 'active-izin' : ''; ?>" data-status="izin"><i class="fas fa-paper-plane"></i> Izin</span>
                                                </label>
                                            </div>
                                        </div>

                                        <div class="form-group" id="keterangan_box" style="display: <?php echo ($today_attendance && in_array($today_attendance['status'], ['izin', 'sakit'])) ? 'block' : 'none'; ?>;">
                                            <label>Keterangan</label>
                                            <textarea name="attendance_note" class="form-control" placeholder="Masukkan keterangan..."><?php echo $today_attendance ? htmlspecialchars($today_attendance['keterangan']) : ''; ?></textarea>
                                        </div>

                                        <button type="submit" name="submit_attendance" class="btn btn-primary btn-lg btn-block shadow-sm"><i class="fas fa-save mr-2"></i> Simpan Absensi</button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <?php if ($show_les_box_wali): ?>
                        <div class="col-12 col-md-6">
                            <div class="card card-dark">
                                <div class="card-header">
                                    <h4>Absensi Les & Jurnal Les (Kelas 6)</h4>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-light alert-has-icon shadow-sm border">
                                        <div class="alert-icon text-dark"><i class="far fa-bell"></i></div>
                                        <div class="alert-body">
                                            <div class="alert-title font-weight-bold">Penting</div>
                                            Jangan lupa untuk mengisi <b>Absensi Les</b> Anda, <b>Absensi Siswa Les</b>, serta <b>Jurnal Les</b> sesuai jadwal Anda.
                                        </div>
                                    </div>
                                    <form method="POST" action="" id="attendanceFormLes">
                                        <div class="form-group mb-4">
                                            <label class="d-block font-weight-bold">Status Kehadiran Les (<?php echo date('d-m-Y'); ?>)</label>
                                            <div class="selectgroup selectgroup-pills">
                                                <label class="selectgroup-item">
                                                    <input type="radio" name="attendance_status_les" value="hadir" class="selectgroup-input" <?php echo ($today_les_attendance && strtolower($today_les_attendance['status']) == 'hadir') ? 'checked' : ''; ?> required>
                                                    <span class="selectgroup-button selectgroup-button-icon btn-outline-success <?php echo ($today_les_attendance && strtolower($today_les_attendance['status']) == 'hadir') ? 'active-hadir' : ''; ?>" data-status="hadir"><i class="fas fa-check"></i> Hadir</span>
                                                </label>
                                                <label class="selectgroup-item">
                                                    <input type="radio" name="attendance_status_les" value="sakit" class="selectgroup-input" <?php echo ($today_les_attendance && strtolower($today_les_attendance['status']) == 'sakit') ? 'checked' : ''; ?>>
                                                    <span class="selectgroup-button selectgroup-button-icon btn-outline-info <?php echo ($today_les_attendance && strtolower($today_les_attendance['status']) == 'sakit') ? 'active-sakit' : ''; ?>" data-status="sakit"><i class="fas fa-procedures"></i> Sakit</span>
                                                </label>
                                                <label class="selectgroup-item">
                                                    <input type="radio" name="attendance_status_les" value="izin" class="selectgroup-input" <?php echo ($today_les_attendance && strtolower($today_les_attendance['status']) == 'izin') ? 'checked' : ''; ?>>
                                                    <span class="selectgroup-button selectgroup-button-icon btn-outline-warning <?php echo ($today_les_attendance && strtolower($today_les_attendance['status']) == 'izin') ? 'active-izin' : ''; ?>" data-status="izin"><i class="fas fa-paper-plane"></i> Izin</span>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="form-group keterangan-box-les" style="display: <?php echo ($today_les_attendance && in_array(strtolower($today_les_attendance['status']), ['izin', 'sakit'])) ? 'block' : 'none'; ?>;">
                                            <label>Keterangan</label>
                                            <textarea name="attendance_note_les" class="form-control"><?php echo $today_les_attendance ? htmlspecialchars($today_les_attendance['keterangan']) : ''; ?></textarea>
                                        </div>
                                        
                                        <button type="submit" name="submit_attendance_les" class="btn btn-primary btn-lg btn-block shadow-sm"><i class="fas fa-save mr-2"></i> Simpan Absensi Les</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Tautan Cepat</h4>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php 
                                        $btn_col_wali = $is_grade_6_wali ? 'col-12 col-md-4' : 'col-12 col-md-6';
                                        ?>
                                        <div class="<?php echo $btn_col_wali; ?> mb-2">
                                            <a href="jurnal_mengajar.php" class="btn btn-info btn-lg btn-block btn-icon icon-left shadow-sm"><i class="fas fa-book-open"></i> Isi Jurnal Mengajar</a>
                                        </div>
                                        <?php if ($is_grade_6_wali): ?>
                                        <div class="<?php echo $btn_col_wali; ?> mb-2">
                                            <a href="jurnal_les.php" class="btn btn-primary btn-lg btn-block btn-icon icon-left shadow-sm"><i class="fas fa-book"></i> Isi Jurnal Les</a>
                                        </div>
                                        <?php endif; ?>
                                        <div class="<?php echo $btn_col_wali; ?> mb-2">
                                            <button type="button" class="btn btn-warning btn-lg btn-block btn-icon icon-left shadow-sm" data-toggle="modal" data-target="#qrCodeModal"><i class="fas fa-qrcode"></i> Tampilkan QR Code</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Mobile Menu -->
                    <div class="row d-lg-none">
                        <div class="col-12 mb-4">
                            <div class="card border-0 shadow-sm" style="border-radius: 16px;">
                                <div class="card-body pb-2">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h6 class="mb-0 font-weight-bold">Menu Utama</h6>
                                        <span class="badge badge-success badge-pill">Semua Fitur</span>
                                    </div>
                                    <?php
                                    $mobile_menu_groups = function_exists('get_mobile_menu_groups') ? get_mobile_menu_groups($menu_items) : ['single' => [], 'grouped' => []];
                                    $single_items = $mobile_menu_groups['single'];
                                    $grouped_items = $mobile_menu_groups['grouped'];
                                    ?>
                                    <?php if (!empty($single_items) || !empty($grouped_items)): ?>
                                        <?php if (!empty($single_items)): ?>
                                            <div class="row">
                                                <?php foreach ($single_items as $item): ?>
                                                    <div class="col-3 mb-3">
                                                        <a href="<?php echo $item['url']; ?>" class="text-decoration-none text-center d-block">
                                                            <div class="mx-auto mb-2 d-flex align-items-center justify-content-center" style="width: 56px; height: 56px; border-radius: 18px; background: #f3f8f3;">
                                                                <i class="<?php echo $item['icon']; ?> text-primary" style="font-size: 1.4rem;"></i>
                                                            </div>
                                                            <div class="mobile-menu-label small text-dark"><?php echo $item['title']; ?></div>
                                                        </a>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php foreach ($grouped_items as $group): ?>
                                            <div class="mt-3">
                                                <div class="small text-muted font-weight-bold mb-2"><?php echo $group['title']; ?></div>
                                                <div class="row">
                                                    <?php foreach ($group['items'] as $subitem): ?>
                                                        <div class="col-3 mb-3">
                                                            <a href="<?php echo $subitem['url']; ?>" class="text-decoration-none text-center d-block">
                                                                <div class="mx-auto mb-2 d-flex align-items-center justify-content-center" style="width: 56px; height: 56px; border-radius: 18px; background: #f3f8f3;">
                                                                    <i class="<?php echo $group['icon']; ?> text-primary" style="font-size: 1.4rem;"></i>
                                                                </div>
                                                                <div class="mobile-menu-label small text-dark"><?php echo $subitem['title']; ?></div>
                                                            </a>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <style>
                    .camera-icon-overlay {
                        position: absolute;
                        bottom: 5px;
                        right: 5px;
                        background: #fff;
                        color: #6777ef;
                        border-radius: 50%;
                        width: 36px;
                        height: 36px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        cursor: pointer;
                        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
                        transition: all 0.3s;
                        z-index: 10;
                    }
                    .camera-icon-overlay:hover {
                        background: #6777ef;
                        color: #fff;
                        transform: scale(1.1);
                    }
                    
                    /* Custom styles for attendance buttons */
                    .selectgroup-button-icon {
                        border: 1px solid #e4e6fc !important;
                        background-color: #fff !important;
                        color: #6c757d !important;
                        transition: all 0.3s ease;
                    }
                    
                    .selectgroup-input:checked + .selectgroup-button-icon[data-status="hadir"],
                    .selectgroup-button-icon.active-hadir {
                        background-color: #28a745 !important;
                        border-color: #28a745 !important;
                        color: #fff !important;
                    }
                    
                    .selectgroup-input:checked + .selectgroup-button-icon[data-status="sakit"],
                    .selectgroup-button-icon.active-sakit {
                        background-color: #17a2b8 !important;
                        border-color: #17a2b8 !important;
                        color: #fff !important;
                    }
                    
                    .selectgroup-input:checked + .selectgroup-button-icon[data-status="izin"],
                    .selectgroup-button-icon.active-izin {
                        background-color: #ffc107 !important;
                        border-color: #ffc107 !important;
                        color: #212529 !important;
                    }
                    </style>

                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const radioButtons = document.querySelectorAll('input[name="attendance_status"], input[name="attendance_status_les"]');
                        const statusButtons = document.querySelectorAll('.selectgroup-button-icon');
                        const keteranganBox = document.getElementById('keterangan_box');
                        const keteranganBoxLes = document.querySelector('.keterangan-box-les');
                        
                        function updateKeteranganBox(radio) {
                            const form = radio.closest('form');
                            const status = radio.value;
                            
                            if (form.id === 'attendanceForm') {
                                if (keteranganBox) {
                                    const keteranganTextarea = keteranganBox.querySelector('textarea');
                                    if (status === 'izin' || status === 'sakit') {
                                        keteranganBox.style.display = 'block';
                                        keteranganTextarea.required = (status === 'izin');
                                    } else {
                                        keteranganBox.style.display = 'none';
                                        keteranganTextarea.required = false;
                                    }
                                }
                            } else if (form.id === 'attendanceFormLes') {
                                if (keteranganBoxLes) {
                                    const keteranganTextarea = keteranganBoxLes.querySelector('textarea');
                                    if (status === 'izin' || status === 'sakit') {
                                        keteranganBoxLes.style.display = 'block';
                                        keteranganTextarea.required = (status === 'izin');
                                    } else {
                                        keteranganBoxLes.style.display = 'none';
                                        keteranganTextarea.required = false;
                                    }
                                }
                            }
                        }

                        radioButtons.forEach(radio => {
                            radio.addEventListener('change', function() {
                                const form = this.closest('form');
                                form.querySelectorAll('.selectgroup-button-icon').forEach(btn => {
                                    btn.classList.remove('active-hadir', 'active-sakit', 'active-izin');
                                });
                                
                                // Add active class to clicked button
                                const label = this.closest('label');
                                const btn = label.querySelector('.selectgroup-button-icon');
                                if (this.value === 'hadir') btn.classList.add('active-hadir');
                                if (this.value === 'sakit') btn.classList.add('active-sakit');
                                if (this.value === 'izin') btn.classList.add('active-izin');
                                
                                updateKeteranganBox(this);
                            });
                        });
                        
                        const fotoUpload = document.getElementById('foto_upload');
                        if(fotoUpload) {
                            fotoUpload.addEventListener('change', function() {
                                if (this.files && this.files[0]) {
                                    var formData = new FormData();
                                    formData.append('foto', this.files[0]);
                                    
                                    Swal.fire({
                                        title: 'Mengupload...',
                                        text: 'Mohon tunggu sebentar',
                                        allowOutsideClick: false,
                                        didOpen: () => {
                                            Swal.showLoading();
                                        }
                                    });

                                    fetch('../ajax/update_foto_guru.php', {
                                        method: 'POST',
                                        body: formData
                                    })
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.success) {
                                            Swal.fire({
                                                icon: 'success',
                                                title: 'Berhasil',
                                                text: data.message,
                                                timer: 2000,
                                                showConfirmButton: false
                                            }).then(() => {
                                                location.reload();
                                            });
                                        } else {
                                            Swal.fire({
                                                icon: 'error',
                                                title: 'Gagal',
                                                text: data.message
                                            });
                                        }
                                    })
                                    .catch(error => {
                                        console.error('Error:', error);
                                        Swal.fire({
                                            icon: 'error',
                                            title: 'Error',
                                            text: 'Terjadi kesalahan saat mengupload foto.'
                                        });
                                    });
                                }
                            });
                        }
                    });
                    </script>

                    <div class="row">
                        <div class="col-lg-4 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-primary">
                                    <i class="fas fa-chalkboard"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Total Kelas Ajar</h4>
                                    </div>
                                    <div class="card-body">
                                        <strong><?php echo $total_kelas_ajar; ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-success">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Total Siswa</h4>
                                    </div>
                                    <div class="card-body">
                                        <strong><?php echo $total_siswa; ?></strong>
                                        <div class="text-muted small mt-1">
                                            <span title="Laki-laki"><i class="fas fa-mars text-info"></i> <strong><?php echo $total_laki; ?></strong></span> &nbsp; 
                                            <span title="Perempuan"><i class="fas fa-venus text-warning"></i> <strong><?php echo $total_perempuan; ?></strong></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-success">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Siswa Hadir</h4>
                                    </div>
                                    <div class="card-body">
                                        <strong><?php echo $jumlah_hadir; ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-warning">
                                    <i class="fas fa-heartbeat"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Siswa Sakit</h4>
                                    </div>
                                    <div class="card-body">
                                        <strong><?php echo $jumlah_sakit; ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-info">
                                    <i class="fas fa-file-alt"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Izin</h4>
                                    </div>
                                    <div class="card-body">
                                        <strong><?php echo $jumlah_izin; ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-danger">
                                    <i class="fas fa-times-circle"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Alpa</h4>
                                    </div>
                                    <div class="card-body">
                                        <strong><?php echo $jumlah_alpa; ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon" style="background-color: #9557f5; color: #fff;">
                                    <i class="fas fa-ban"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Berhalangan</h4>
                                    </div>
                                    <div class="card-body">
                                        <strong><?php echo $jumlah_berhalangan; ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Grafik Kehadiran Siswa Hari Ini</h4>
                                </div>
                                <div class="card-body">
                                    <canvas id="myChart" style="width:100%; height: 220px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Trend Kehadiran Bulan Ini</h4>
                                </div>
                                <div class="card-body">
                                    <canvas id="trendChart" style="width:100%; height: 240px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php
                    $siswa_list = [];
                    if ($wali_kelas) {
                        $siswa_stmt = $pdo->prepare("SELECT s.*, a.keterangan as attendance_status FROM tb_siswa s LEFT JOIN tb_absensi a ON s.id_siswa = a.id_siswa AND a.tanggal = CURDATE() WHERE s.id_kelas = ? ORDER BY s.nama_siswa ASC");
                        $siswa_stmt->execute([$wali_kelas['id_kelas']]);
                        $siswa_list = $siswa_stmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                    ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Status Kehadiran Siswa Hari Ini<?php echo $wali_kelas ? ' - Kelas ' . htmlspecialchars($wali_kelas['nama_kelas'] ?? '') : ''; ?></h4>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive mt-3">
                                        <table class="table table-striped table-bordered">
                                            <thead>
                                                <tr>
                                                    <th>No</th>
                                                    <th>Nama Siswa</th>
                                                    <th>NISN</th>
                                                    <th>Status Kehadiran</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if ($wali_kelas && !empty($siswa_list)): ?>
                                                    <?php foreach ($siswa_list as $idx => $siswa): ?>
                                                        <tr>
                                                            <td><?php echo $idx + 1; ?></td>
                                                            <td><?php echo htmlspecialchars($siswa['nama_siswa']); ?></td>
                                                            <td><?php echo htmlspecialchars($siswa['nisn']); ?></td>
                                                            <td>
                                                                <?php
                                                                $status = $siswa['attendance_status'] ?? 'Belum Absen';
                                                                $badge_class = '';
                                                                switch (strtolower($status)) {
                                                                    case 'hadir':
                                                                        $badge_class = 'badge-success';
                                                                        break;
                                                                    case 'sakit':
                                                                        $badge_class = 'badge-info';
                                                                        break;
                                                                    case 'izin':
                                                                        $badge_class = 'badge-warning';
                                                                        break;
                                                                    case 'alpa':
                                                                    case 'berhalangan':
                                                                        $badge_class = 'badge-danger';
                                                                        break;
                                                                    default:
                                                                        $badge_class = 'badge-secondary';
                                                                }
                                                                ?>
                                                                <span class="badge <?php echo $badge_class; ?>">
                                                                    <?php echo htmlspecialchars($status); ?>
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php elseif ($wali_kelas): ?>
                                                    <tr>
                                                        <td colspan="4" class="text-center">Tidak ada siswa dalam kelas ini</td>
                                                    </tr>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="4" class="text-center">Tidak ada kelas yang diajar</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
            
    <!-- Modal QR Code -->
    <div class="modal fade" id="qrCodeModal" tabindex="-1" role="dialog" aria-labelledby="qrCodeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="qrCodeModalLabel">QR Code Guru</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body text-center">
                    <p>Gunakan QR Code ini untuk absensi kehadiran.</p>
                    <?php if (!empty($teacher['nuptk'])): ?>
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=<?php echo $teacher['nuptk']; ?>" alt="QR Code" class="img-fluid" style="width: 250px; height: 250px;">
                        <h5 class="mt-3"><?php echo htmlspecialchars($teacher['nama_guru']); ?></h5>
                        <p class="text-muted">NUPTK: <?php echo htmlspecialchars($teacher['nuptk']); ?></p>
                    <?php else: ?>
                        <div class="alert alert-warning">NUPTK belum tersedia. Silakan hubungi admin.</div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>
    <?php
    include '../templates/user_footer.php'; 
    ?>
