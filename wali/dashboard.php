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
$holiday = isSchoolHoliday($pdo, $today);

// Check if there is a tutoring schedule today (for Grade 6)
$is_grade_6_wali = false;
if ($wali_kelas) {
    $class_name = strtoupper($wali_kelas['nama_kelas']);
    if (strpos($class_name, '6') !== false || strpos($class_name, 'VI') !== false) {
        $is_grade_6_wali = true;
    }
}

// Hanya true jika guru ini punya jadwal les pada tanggal tersebut (bukan sekadar ada jadwal untuk guru lain)
$has_les_schedule = false;
if ($teacher && !empty($teacher['id_guru'])) {
    $stmt_check_sched = $pdo->prepare("SELECT COUNT(*) FROM tb_jadwal_les WHERE tanggal = ? AND id_guru = ?");
    $stmt_check_sched->execute([$today, $teacher['id_guru']]);
    $has_les_schedule = (int)$stmt_check_sched->fetchColumn() > 0;
}

// Get tutoring attendance if schedule exists
$today_les_attendance = null;
if ($has_les_schedule && $teacher && isset($teacher['id_guru'])) {
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
            // Time validation for teacher attendance: 06:00-15:00
            $now_hour_wali = (int)date('H');
            if ($now_hour_wali < 6 || $now_hour_wali >= 15) {
                echo "<script>document.addEventListener('DOMContentLoaded',function(){Swal.fire({title:'Diluar Jam Kehadiran',text:'Kehadiran guru hanya dapat diisi pukul 06:00 - 15:00 WIB.',icon:'warning',confirmButtonText:'OK'});});</script>";
            } else {
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
                            text: 'Kehadiran ditutup pada hari libur: " . addslashes($holiday['name']) . "',
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
            
            // Late check: after 07:10 (hanya berlaku untuk status Hadir)
            $is_late_wali = time() > strtotime('07:10');
            $late_note_wali = ($status_to_save === 'Hadir') ? ($is_late_wali ? 'Terlambat' : 'Tepat Waktu') : '';
            $keterangan_wali = trim(implode(' | ', array_filter([$attendance_note, $late_note_wali])));
            $late_text_wali = ($status_to_save === 'Hadir' && $is_late_wali) ? ' Maaf anda terlambat.' : '';
            
            if ($check_stmt->rowCount() > 0) {
                // Update existing
                 $update_stmt = $pdo->prepare("UPDATE tb_absensi_guru SET status = ?, keterangan = ?, waktu_input = ? WHERE id_guru = ? AND tanggal = ?");
                 if ($update_stmt->execute([$status_to_save, $keterangan_wali, $now_time, $current_teacher_id, $current_date])) {
                     echo "<script>
                        document.addEventListener('DOMContentLoaded', function() {
                            Swal.fire({
                                title: 'Berhasil!',
                                text: 'Kehadiran berhasil diperbarui.$late_text_wali',
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
                if ($insert_stmt->execute([$current_teacher_id, $current_date, $status_to_save, $keterangan_wali, $now_time])) {
                     
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
                                text: 'Kehadiran berhasil disimpan.$late_text_wali',
                                icon: 'success',
                                timer: 3000,
                                timerProgressBar: true,
                                showConfirmButton: false
                            });
                        });
                     </script>";
                }
            }
        }
        } elseif (isset($_POST['submit_attendance_les'])) {
            // Tutoring attendance - specific for Grade 6
            // Check if there is a schedule today
            $stmt_check_sched_post = $pdo->prepare("SELECT COUNT(*) FROM tb_jadwal_les WHERE tanggal = ? AND id_guru = ?");
            $stmt_check_sched_post->execute([$current_date, $current_teacher_id]);
            
            if ($stmt_check_sched_post->fetchColumn() > 0) {
                $status_to_save_les = isset($_POST['attendance_status_les']) ? ucfirst($_POST['attendance_status_les']) : '';
                $attendance_note_les = $_POST['attendance_note_les'] ?? '';

                $check_stmt = $pdo->prepare("SELECT id_absensi FROM tb_absensi_les_guru WHERE id_guru = ? AND tanggal = ?");
                $check_stmt->execute([$current_teacher_id, $current_date]);
                
                if ($check_stmt->rowCount() > 0) {
                    $update_stmt = $pdo->prepare("UPDATE tb_absensi_les_guru SET status = ?, keterangan = ?, waktu_input = ? WHERE id_guru = ? AND tanggal = ?");
                    $update_stmt->execute([$status_to_save_les, $attendance_note_les, $now_time, $current_teacher_id, $current_date]);
                    $msg_text = 'Kehadiran les berhasil diperbarui.';
                } else {
                    $insert_stmt = $pdo->prepare("INSERT INTO tb_absensi_les_guru (id_guru, tanggal, status, keterangan, waktu_input) VALUES (?, ?, ?, ?, ?)");
                    $insert_stmt->execute([$current_teacher_id, $current_date, $status_to_save_les, $attendance_note_les, $now_time]);
                    $msg_text = 'Kehadiran les berhasil disimpan.';
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
                <?php include '../templates/mobile_navbar.php'; ?>
                <?php
                $mapp_greet_name = isset($teacher_name) ? $teacher_name : 'Wali Kelas';
                $mapp_greet_role = 'Wali Kelas' . (isset($wali_kelas['nama_kelas']) && $wali_kelas['nama_kelas'] !== '' ? ' ' . $wali_kelas['nama_kelas'] : '');
                include '../templates/mobile_greeting.php';
                ?>

                <section class="section mapp-ordered">
                    <div class="section-header">
                        <h1>Dashboard Wali Kelas</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                        </div>
                    </div>

                    <!-- Profile Box -->
                    <div class="row mapp-row-profile">
                        <div class="col-12 mb-3">
                            <div class="card border-0 shadow-lg" style="background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 50%, #06b6d4 100%); border-radius: 12px; overflow: hidden; color: #fff; position: relative;">
                                <div style="position: absolute; right: -20px; bottom: -20px; width: 180px; height: 180px; background: rgba(255,255,255,0.07); border-radius: 50%; pointer-events: none;"></div>
                                <div class="card-body p-4 p-md-4">
                                    <div class="row align-items-center">
                                        <div class="col-12 col-md-auto text-center mb-3 mb-md-0">
                                            <div class="d-inline-block position-relative text-center">
                                                <?php
                                                $avatar_img = getTeacherAvatarImage($teacher, 96);
                                                $avatar_img = str_replace("class='rounded-circle'", "class='rounded-circle shadow-lg border border-white' style='border-width: 3px !important; object-fit: cover; width: 96px; height: 96px;'", $avatar_img);
                                                echo $avatar_img;
                                                ?>
                                                <div class="mt-2">
                                                    <span class="badge badge-success px-3 py-1 shadow-sm" style="font-size: 11px; font-weight: 800; background: #16a34a !important; color: #ffffff !important; border: 1px solid #4ade80;"><i class="fas fa-circle mr-1" style="font-size: 6px; color: #4ade80;"></i> Aktif</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-12 col-md text-center text-md-left">
                                            <div class="d-flex flex-column flex-md-row align-items-center justify-content-between mb-3" style="gap: 12px;">
                                                <div class="text-center text-md-left mb-2 mb-md-0">
                                                    <div class="text-uppercase mb-1" style="font-size: 10.5px; letter-spacing: 1.5px; font-weight: 800; color: #93c5fd;">Selamat Datang Kembali</div>
                                                    <h3 class="font-weight-bold text-white mb-2" style="font-size: 1.45rem; text-shadow: 0 2px 4px rgba(0,0,0,0.2); word-break: break-word;">Assalamualaikum, <?php echo htmlspecialchars($teacher_name); ?></h3>
                                                    <div class="d-inline-block px-3 py-1 rounded-pill" style="background: rgba(255,255,255,0.2) !important; backdrop-filter: blur(6px); border: 1px solid rgba(255,255,255,0.3); color: #ffffff !important;">
                                                        <i class="fas fa-user-graduate mr-1" style="color: #fde047;"></i>
                                                        <span style="font-size: 12px; font-weight: 600; color: #ffffff !important;">Wali Kelas <b><?php echo $wali_kelas ? htmlspecialchars($wali_kelas['nama_kelas']) : '-'; ?></b> (<b><?php echo $total_siswa; ?></b> Siswa)</span>
                                                    </div>
                                                </div>
                                                <div class="text-center text-md-right flex-shrink-0">
                                                    <a href="../guru/profil.php" class="btn btn-light font-weight-bold px-3.5 py-2 shadow-sm" style="border-radius: 10px; font-size: 12px; color: #1e3a8a; white-space: nowrap;">
                                                        <i class="fas fa-user-cog mr-2" style="color: #2563eb;"></i> Profil & Pengaturan
                                                    </a>
                                                </div>
                                            </div>

                                            <div class="p-3 mt-3 rounded d-none d-md-block" style="background: rgba(15, 23, 42, 0.25); border: 1px solid rgba(255, 255, 255, 0.25); backdrop-filter: blur(8px); box-shadow: inset 0 1px 2px rgba(0,0,0,0.1); border-radius: 10px !important;">
                                                <div class="row text-center text-md-left align-items-center">
                                                    <div class="col mb-2 mb-md-0 pr-md-3 border-right border-light border-opacity-15">
                                                        <span class="d-block" style="color: #93c5fd; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px;">NUPTK</span>
                                                        <span class="font-weight-bold text-white" style="font-size: 12.5px;"><?php echo !empty($teacher['nuptk']) ? htmlspecialchars($teacher['nuptk']) : '-'; ?></span>
                                                    </div>
                                                    <div class="col mb-2 mb-md-0 px-md-3 border-right border-light border-opacity-15">
                                                        <span class="d-block" style="color: #93c5fd; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px;">Status</span>
                                                        <span class="badge badge-success px-2 py-0.5 mt-1" style="font-size: 10px; font-weight: 800; background: #16a34a;"><?php echo !empty($teacher['status_kepegawaian']) ? htmlspecialchars($teacher['status_kepegawaian']) : 'Aktif'; ?></span>
                                                    </div>
                                                    <div class="col mb-2 mb-md-0 px-md-3 border-right border-light border-opacity-15">
                                                        <span class="d-block" style="color: #93c5fd; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px;">TMT</span>
                                                        <span class="font-weight-bold text-white" style="font-size: 12.5px;"><?php echo !empty($teacher['tmt']) ? date('d/m/Y', strtotime($teacher['tmt'])) : '-'; ?></span>
                                                    </div>
                                                    <div class="col mb-2 mb-md-0 px-md-3 border-right border-light border-opacity-15">
                                                        <span class="d-block" style="color: #93c5fd; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px;">Masa Bakti</span>
                                                        <span class="font-weight-bold" style="color: #fef08a; font-size: 12.5px;"><?php echo calculateMasaBakti($teacher['tmt'] ?? null); ?></span>
                                                    </div>
                                                    <div class="col mt-2 mt-md-0 pl-md-3">
                                                        <span class="d-block" style="color: #93c5fd; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px;">Tempat, Tgl Lahir</span>
                                                        <span class="font-weight-bold text-white" style="font-size: 12px;">
                                                            <?php
                                                            $ttl = [];
                                                            if (!empty($teacher['tempat_lahir'])) $ttl[] = $teacher['tempat_lahir'];
                                                            if (!empty($teacher['tanggal_lahir'])) $ttl[] = date('d/m/Y', strtotime($teacher['tanggal_lahir']));
                                                            echo !empty($ttl) ? implode(', ', $ttl) : '-';
                                                            ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- KBM Info -->
                    <?php if (!$holiday['is_holiday']): ?>
                    <div class="row">
                        <div class="col-12 mb-3">
                            <div class="card mapp-kbm-card">
                                <div class="mapp-kbm-header">
                                    <div class="mapp-kbm-title-group">
                                        <div class="mapp-kbm-icon-pulse">
                                            <i class="fas fa-bell"></i>
                                        </div>
                                        <div>
                                            <h5 class="mapp-kbm-title">Informasi &amp; Jadwal KBM Hari Ini</h5>
                                            <p class="mapp-kbm-subtitle">Pastikan aktivitas harian tercatat tepat waktu demi kelancaran proses pembelajaran.</p>
                                        </div>
                                    </div>
                                    <span class="badge badge-primary px-2.5 py-1.5 font-weight-bold d-none d-sm-inline-block" style="border-radius: 8px; font-size: 11px;">Aktif</span>
                                </div>

                                <div class="mapp-kbm-notice">
                                    <div class="mapp-kbm-notice-icon">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </div>
                                    <p class="mapp-kbm-notice-text">
                                        <b>Catatan Penting:</b> Mohon segera lakukan pengisian <b>Kehadiran Siswa</b> sebelum memulai jam pelajaran pertama.
                                    </p>
                                </div>

                                <div class="mapp-kbm-badges-grid">
                                    <div class="mapp-kbm-pill-card">
                                        <div class="mapp-kbm-pill-icon tone-green">
                                            <i class="fas fa-user-check"></i>
                                        </div>
                                        <div class="mapp-kbm-pill-label">Kehadiran Siswa</div>
                                        <span class="mapp-kbm-pill-time time-green">07:00 - 14:00</span>
                                    </div>
                                    <div class="mapp-kbm-pill-card">
                                        <div class="mapp-kbm-pill-icon tone-blue">
                                            <i class="fas fa-chalkboard-teacher"></i>
                                        </div>
                                        <div class="mapp-kbm-pill-label">Kehadiran Guru</div>
                                        <span class="mapp-kbm-pill-time time-blue">06:00 - 15:00</span>
                                    </div>
                                    <div class="mapp-kbm-pill-card">
                                        <div class="mapp-kbm-pill-icon tone-amber">
                                            <i class="fas fa-book-open"></i>
                                        </div>
                                        <div class="mapp-kbm-pill-label">Jurnal Mengajar</div>
                                        <span class="mapp-kbm-pill-time time-amber">07:00 - 14:00</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php
                    // Box Jadwal Reguler Hari Ini (khusus wali/guru login)
                    $jd_hari_map = ['Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu', 'Sunday' => 'Ahad'];
                    $jd_hari_ini = $jd_hari_map[date('l')] ?? date('l');
                    $jd_tanggal_ini = date('d F Y');
                    $jd_guru_id = (int)($teacher['id_guru'] ?? 0);
                    $jadwal_hari_ini = [];
                    if ($jd_guru_id > 0) {
                        try {
                            $jd_cari = [$jd_hari_ini];
                            if ($jd_hari_ini === 'Ahad') { $jd_cari[] = 'Minggu'; }
                            if ($jd_hari_ini === 'Jumat') { $jd_cari[] = "Jum'at"; }
                            $jd_in = implode(',', array_fill(0, count($jd_cari), '?'));
                            $stJd = $pdo->prepare("
                                SELECT j.jam_ke, j.hari, k.nama_kelas, m.nama_mapel,
                                       jm.waktu_mulai, jm.waktu_selesai
                                FROM tb_jadwal_pelajaran j
                                LEFT JOIN tb_kelas k ON k.id_kelas = j.kelas_id
                                LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = j.mapel_id
                                LEFT JOIN tb_jam_mengajar jm ON jm.jam_ke = j.jam_ke AND jm.jenis = 'Reguler'
                                WHERE j.guru_id = ? AND j.jenis = 'Reguler' AND j.hari IN ($jd_in)
                                ORDER BY jm.waktu_mulai ASC, j.jam_ke ASC
                            ");
                            $stJd->execute(array_merge([$jd_guru_id], $jd_cari));
                            $jadwal_hari_ini = array_values(array_filter($stJd->fetchAll(PDO::FETCH_ASSOC), static function ($jd) {
                                return preg_match('/^[0-9]+$/', (string)($jd['jam_ke'] ?? ''));
                            }));
                            usort($jadwal_hari_ini, static function ($a, $b) {
                                $d = ((int)$a['jam_ke']) <=> ((int)$b['jam_ke']);
                                if ($d !== 0) return $d;
                                return strcmp((string)($a['waktu_mulai'] ?? ''), (string)($b['waktu_selesai'] ?? ''));
                            });
                        } catch (Throwable $e) {
                            $jadwal_hari_ini = [];
                        }
                    }

                    // Kelompokkan jam pelajaran berurutan untuk mapel & kelas yang sama
                    $jadwal_grouped = [];
                    if (!empty($jadwal_hari_ini)) {
                        $cur = null;
                        foreach ($jadwal_hari_ini as $jd) {
                            $jam = (int)($jd['jam_ke'] ?? 0);
                            $mapel = (string)($jd['nama_mapel'] ?? '');
                            $kelas = (string)($jd['nama_kelas'] ?? '');
                            $wm = (string)($jd['waktu_mulai'] ?? '');
                            $ws = (string)($jd['waktu_selesai'] ?? '');

                            if ($cur !== null && $cur['nama_mapel'] === $mapel && $cur['nama_kelas'] === $kelas && $jam === $cur['jam_akhir'] + 1) {
                                $cur['jam_akhir'] = $jam;
                                if ($ws !== '') {
                                    $cur['waktu_selesai'] = $ws;
                                }
                                $cur['jumlah_jam']++;
                            } else {
                                if ($cur !== null) {
                                    $jadwal_grouped[] = $cur;
                                }
                                $cur = [
                                    'nama_mapel' => $mapel,
                                    'nama_kelas' => $kelas,
                                    'jam_awal' => $jam,
                                    'jam_akhir' => $jam,
                                    'waktu_mulai' => $wm,
                                    'waktu_selesai' => $ws,
                                    'jumlah_jam' => 1
                                ];
                            }
                        }
                        if ($cur !== null) {
                            $jadwal_grouped[] = $cur;
                        }
                    }
                    ?>
                    <!-- Jadwal + Kehadiran sejajar 2 kolom -->
                    <div class="row align-items-stretch">
                        <div class="col-12 col-md-6 mb-4 d-flex">
                            <div class="card w-100 h-100 mapp-jadwal-card border-0 shadow-sm">
                                <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                                    <h4 class="mb-0"><i class="fas fa-calendar-day text-primary mr-2"></i>Jadwal Mengajar Hari Ini</h4>
                                    <a href="jadwal_reguler.php?session_type=wali" class="btn btn-light btn-sm font-weight-bold"><i class="fas fa-table mr-1"></i> Lihat Jadwal Penuh</a>
                                </div>
                                <div class="card-body p-3">
                                    <?php
                                    $mapp_bulan = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                                    $mapp_day_sub = htmlspecialchars($jd_tanggal_ini);
                                    if ($wali_kelas) { $mapp_day_sub .= ' • Wali Kelas ' . htmlspecialchars($wali_kelas['nama_kelas']); }
                                    ?>
                                    <div class="mapp-day-banner">
                                        <div class="mapp-day-date">
                                            <span class="d"><?= date('d') ?></span>
                                            <span class="m"><?= $mapp_bulan[(int)date('n')] ?></span>
                                        </div>
                                        <div class="mapp-day-info">
                                            <div class="mapp-day-name"><?= htmlspecialchars($jd_hari_ini) ?></div>
                                            <div class="mapp-day-sub"><?= $mapp_day_sub ?></div>
                                        </div>
                                        <div class="mapp-day-stats">
                                            <div class="mapp-day-stat">
                                                <span class="num"><?= count($jadwal_hari_ini) ?></span>
                                                <span class="lbl">Jam</span>
                                            </div>
                                            <div class="mapp-day-stat">
                                                <span class="num"><?= count($jadwal_grouped) ?></span>
                                                <span class="lbl">Sesi</span>
                                            </div>
                                        </div>
                                    </div>

                                    <?php if (!empty($jadwal_grouped)): ?>
                                        <div class="mapp-agenda-list">
                                            <?php foreach ($jadwal_grouped as $g): ?>
                                                <div class="mapp-agenda-item">
                                                    <div class="mapp-agenda-clock">
                                                        <span class="jam">Jam</span>
                                                        <span class="val"><?= $g['jam_awal'] == $g['jam_akhir'] ? $g['jam_awal'] : $g['jam_awal'] . '–' . $g['jam_akhir'] ?></span>
                                                    </div>
                                                    <div class="mapp-agenda-body">
                                                        <div class="mapp-agenda-mapel"><?= htmlspecialchars($g['nama_mapel'] ?? '-') ?></div>
                                                        <div class="mapp-agenda-meta">
                                                            <span class="mapp-chip"><i class="fas fa-door-open"></i> Kelas <?= htmlspecialchars($g['nama_kelas'] ?? '-') ?></span>
                                                            <?php if (!empty($g['waktu_mulai'])): ?>
                                                            <span class="mapp-chip"><i class="far fa-clock"></i> <?= str_replace(':', '.', substr($g['waktu_mulai'], 0, 5)) ?><?= !empty($g['waktu_selesai']) ? '–' . str_replace(':', '.', substr($g['waktu_selesai'], 0, 5)) : '' ?> WIB</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="mapp-empty-state">
                                            <h6>Tidak Ada Jadwal Mengajar Hari <?= htmlspecialchars($jd_hari_ini) ?></h6>
                                            <p>Hari ini (<?= htmlspecialchars($jd_tanggal_ini) ?>) Anda tidak terjadwal mengajar.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <!-- Attendance Box: kolom kanan, satu baris dengan box jadwal -->
                        <?php
                        // Box les selalu muncul untuk kelas 6, cek apakah ada jadwal hari ini
                        $show_les_box_wali = $is_grade_6_wali && $has_les_schedule; // Hanya tampilkan jika ada jadwal les hari ini
                        $col_class_wali = 'col-12 col-md-6 mb-4 d-flex';

                        if (!$holiday['is_holiday']):
                        ?>
                        <div class="<?php echo $col_class_wali; ?> mapp-col-absen">
                            <div class="card w-100 h-100 mapp-absen-card border-0 shadow-sm">
                                <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                                    <h4 class="mb-0"><i class="fas fa-user-check text-primary mr-2"></i>Kehadiran Hari Ini</h4>
                                    <span class="badge badge-light border text-muted font-weight-bold"><?php echo date('d M Y'); ?></span>
                                </div>
                                <div class="card-body">
                                    <?php
                                    // Check current attendance status - ONLY use regular table for dashboard
                                    $today_attendance = null;
                                    if (isset($teacher['id_guru'])) {
                                        $stmt_check = $pdo->prepare("SELECT * FROM tb_absensi_guru WHERE id_guru = ? AND tanggal = CURDATE()");
                                        $stmt_check->execute([$teacher['id_guru']]);
                                        $today_attendance = $stmt_check->fetch(PDO::FETCH_ASSOC);
                                    }
                                    $wali_status = $today_attendance ? strtolower($today_attendance['status']) : '';
                                    $wali_time = ($today_attendance && !empty($today_attendance['waktu_input'])) ? date('H.i', strtotime($today_attendance['waktu_input'])) . ' WIB' : '';
                                    $wali_hero_cls = 'is-empty';
                                    $wali_hero_icon = 'fas fa-hourglass-half';
                                    $wali_hero_title = 'Belum Absen';
                                    $wali_hero_sub = 'Silakan pilih status kehadiran Anda hari ini.';
                                    if ($wali_status === 'hadir') {
                                        $wali_att_ts = !empty($today_attendance['waktu_input']) ? strtotime($today_attendance['waktu_input']) : time();
                                        $wali_att_late = $wali_att_ts > strtotime('07:10');
                                        $wali_hero_cls = $wali_att_late ? 'is-late' : 'is-hadir';
                                        $wali_hero_icon = $wali_att_late ? 'fas fa-clock' : 'fas fa-check-circle';
                                        $wali_hero_title = $wali_att_late ? 'Hadir - Terlambat' : 'Hadir - Tepat Waktu';
                                        $wali_hero_sub = $wali_time !== '' ? 'Kehadiran dicatat pukul ' . $wali_time : 'Kehadiran Anda sudah tercatat.';
                                    } elseif ($wali_status === 'sakit') {
                                        $wali_hero_cls = 'is-sakit';
                                        $wali_hero_icon = 'fas fa-procedures';
                                        $wali_hero_title = 'Sakit';
                                        $wali_hero_sub = !empty($today_attendance['keterangan']) ? htmlspecialchars($today_attendance['keterangan']) : 'Semoga lekas sembuh.';
                                    } elseif ($wali_status === 'izin') {
                                        $wali_hero_cls = 'is-izin';
                                        $wali_hero_icon = 'fas fa-paper-plane';
                                        $wali_hero_title = 'Izin';
                                        $wali_hero_sub = !empty($today_attendance['keterangan']) ? htmlspecialchars($today_attendance['keterangan']) : 'Izin Anda sudah tercatat.';
                                    }
                                    ?>
                                    <div class="mapp-att-hero <?= $wali_hero_cls ?>">
                                        <div class="mapp-att-hero-ico"><i class="<?= $wali_hero_icon ?>"></i></div>
                                        <div class="mapp-att-hero-txt">
                                            <div class="mapp-att-hero-title"><?= $wali_hero_title ?></div>
                                            <div class="mapp-att-hero-sub"><?= $wali_hero_sub ?></div>
                                        </div>
                                    </div>

                                    <div class="mapp-att-hint">
                                        <i class="far fa-bell"></i>
                                        <span>Jangan lupa mengisi <b>Kehadiran</b>, <b>Kehadiran Siswa</b>, dan <b>Jurnal Mengajar</b> hari ini.</span>
                                    </div>

                                    <form method="POST" action="" id="attendanceForm">
                                        <div class="mapp-att-label">Pilih Status Kehadiran</div>
                                        <div class="mapp-att-actions">
                                            <button type="button" onclick="waliAutoAtt('hadir')" class="mapp-att-btn is-hadir <?php echo ($wali_status === 'hadir') ? 'active' : ''; ?>">
                                                <i class="fas fa-check"></i><span>Hadir</span>
                                            </button>
                                            <button type="button" onclick="waliAutoAtt('sakit')" class="mapp-att-btn is-sakit <?php echo ($wali_status === 'sakit') ? 'active' : ''; ?>">
                                                <i class="fas fa-procedures"></i><span>Sakit</span>
                                            </button>
                                            <button type="button" onclick="waliAutoAtt('izin')" class="mapp-att-btn is-izin <?php echo ($wali_status === 'izin') ? 'active' : ''; ?>">
                                                <i class="fas fa-paper-plane"></i><span>Izin</span>
                                            </button>
                                        </div>

                                        <div id="waliAttBadge" style="display:none;"></div>

                                        <div class="form-group mapp-att-note" id="keteranganArea" style="display: <?php echo ($wali_status === 'izin') ? 'block' : 'none'; ?>; margin-top: 14px;">
                                            <label class="font-weight-bold">Alasan Izin</label>
                                            <textarea name="attendance_note" id="noteWali" class="form-control" placeholder="Tuliskan alasan izin..."><?php echo $today_attendance ? htmlspecialchars($today_attendance['keterangan']) : ''; ?></textarea>
                                            <button type="submit" class="btn btn-info btn-lg btn-block mt-2"><i class="fas fa-save mr-2"></i> Simpan Izin</button>
                                        </div>

                                        <input type="hidden" name="attendance_status" id="attStatusWali" value="">
                                        <input type="hidden" name="submit_attendance" value="1">
                                    </form>
                                    <script>
                                    function waliAutoAtt(status) {
                                        var form = document.getElementById('attendanceForm');
                                        var cur = '<?php echo $today_attendance ? strtolower($today_attendance['status']) : ''; ?>';
                                        if (cur !== '' && status === cur) { return; }
                                        var badge = document.getElementById('waliAttBadge');
                                        if (badge) badge.style.display = (status === 'hadir') ? '' : 'none';
                                        document.getElementById('attStatusWali').value = status;
                                        var area = document.getElementById('keteranganArea');
                                        if (status === 'izin') {
                                            area.style.display = 'block';
                                            return;
                                        }
                                        area.style.display = 'none';
                                        form.submit();
                                    }
                                    </script>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="<?php echo $col_class_wali; ?> mapp-col-absen">
                            <div class="card w-100 h-100 mapp-absen-card border-0 shadow-sm">
                                <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                                    <h4 class="mb-0"><i class="fas fa-user-check text-danger mr-2"></i>Kehadiran Hari Ini</h4>
                                </div>
                                <div class="card-body">
                                    <div class="mapp-att-hero is-holiday">
                                        <div class="mapp-att-hero-ico"><i class="fas fa-calendar-check"></i></div>
                                        <div class="mapp-att-hero-txt">
                                            <div class="mapp-att-hero-title">Hari Libur Sekolah</div>
                                            <div class="mapp-att-hero-sub">Hari ini, <strong><?php echo formatDateIndonesia(date('Y-m-d')); ?></strong> adalah <strong><?php echo $holiday['name']; ?></strong>. Kehadiran harian ditutup.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="row mapp-row-les">
                        <?php if ($show_les_box_wali): ?>
                        <div class="col-12 col-md-6 mb-4">
                            <div class="card card-dark">
                                <div class="card-header">
                                    <h4>Kehadiran Les Guru (Kelas 6)</h4>
                                </div>
                                <div class="card-body">
                                    <?php if ($has_les_schedule): ?>
                                    <div class="alert alert-light alert-has-icon shadow-sm border mb-3">
                                        <div class="alert-icon text-dark"><i class="far fa-bell"></i></div>
                                        <div class="alert-body">
                                            <div class="alert-title font-weight-bold">Penting</div>
                                            Jangan lupa untuk mengisi <b>Kehadiran Les</b> Anda, <b>Kehadiran Siswa Les</b>, serta <b>Jurnal Les</b> sesuai jadwal Anda.
                                        </div>
                                    </div>
                                    <form method="POST" action="" id="attendanceFormLes">
                                        <div class="form-group mb-4 text-center">
                                            <label class="d-block font-weight-bold">Status Kehadiran Les (<?php echo date('d-m-Y'); ?>)</label>
                                            <div class="selectgroup selectgroup-pills justify-content-center">
                                                <label class="selectgroup-item">
                                                    <input type="radio" name="attendance_status_les" value="hadir" class="selectgroup-input" <?php echo ($today_les_attendance && strtolower($today_les_attendance['status']) == 'hadir') ? 'checked' : ''; ?> required>
                                                    <span class="selectgroup-button selectgroup-button-icon btn-outline-success <?php echo ($today_les_attendance && strtolower($today_les_attendance['status']) == 'hadir') ? 'active-hadir' : ''; ?>" data-status="hadir"><i class="fas fa-check"></i> Hadir</span>
                                                </label>
                                                <label class="selectgroup-item">
                                                    <input type="radio" name="attendance_status_les" value="sakit" class="selectgroup-input" <?php echo ($today_les_attendance && strtolower($today_les_attendance['status']) == 'sakit') ? 'checked' : ''; ?>>
                                                    <span class="selectgroup-button selectgroup-button-icon btn-outline-warning <?php echo ($today_les_attendance && strtolower($today_les_attendance['status']) == 'sakit') ? 'active-sakit' : ''; ?>" data-status="sakit"><i class="fas fa-procedures"></i> Sakit</span>
                                                </label>
                                                <label class="selectgroup-item">
                                                    <input type="radio" name="attendance_status_les" value="izin" class="selectgroup-input" <?php echo ($today_les_attendance && strtolower($today_les_attendance['status']) == 'izin') ? 'checked' : ''; ?>>
                                                    <span class="selectgroup-button selectgroup-button-icon btn-outline-info <?php echo ($today_les_attendance && strtolower($today_les_attendance['status']) == 'izin') ? 'active-izin' : ''; ?>" data-status="izin"><i class="fas fa-paper-plane"></i> Izin</span>
                                                </label>
                                            </div>
                                        </div>

                                        <?php 
                                        // Show keterangan as info if already saved with sakit/izin
                                        $show_keterangan_info_les = ($today_les_attendance && in_array(strtolower($today_les_attendance['status']), ['sakit', 'izin']) && !empty($today_les_attendance['keterangan']));
                                        ?>

                                        <div class="form-group keterangan-box-les" id="keterangan_box_les" style="display: <?php echo $show_keterangan_info_les ? 'none' : (($today_les_attendance && in_array(strtolower($today_les_attendance['status']), ['izin', 'sakit'])) ? 'block' : 'none'); ?>;">
                                            <label>Keterangan</label>
                                            <textarea name="attendance_note_les" class="form-control"><?php echo $today_les_attendance ? htmlspecialchars($today_les_attendance['keterangan']) : ''; ?></textarea>
                                        </div>

                                        <?php if ($show_keterangan_info_les): ?>
                                        <div class="form-group" id="keterangan_info_les" style="display: block;">
                                            <label>Keterangan</label>
                                            <div class="alert alert-light border shadow-sm mb-2">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div class="flex-grow-1">
                                                        <i class="fas fa-info-circle text-info mr-2"></i>
                                                        <span><?php echo htmlspecialchars($today_les_attendance['keterangan']); ?></span>
                                                    </div>
                                                    <button type="button" class="btn btn-sm btn-outline-primary btn-edit-keterangan" onclick="editKeterangan('attendanceFormLes', 'keterangan_box_les', 'keterangan_info_les')">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                </div>
                                            </div>
                                            <input type="hidden" name="attendance_note_les" value="<?php echo htmlspecialchars($today_les_attendance['keterangan']); ?>">
                                        </div>
                                        <?php endif; ?>
                                        
                                        <button type="submit" name="submit_attendance_les" class="btn btn-primary btn-lg btn-block shadow-sm"><i class="fas fa-save mr-2"></i> Simpan Kehadiran Les</button>
                                    </form>
                                    <?php else: ?>
                                    <div class="alert alert-info shadow-sm d-flex flex-column justify-content-center mb-0">
                                        <div class="alert-body text-center py-3">
                                            <div class="alert-title font-weight-bold mb-1">Informasi</div>
                                            <p class="mb-3">Tidak ada jadwal les untuk hari ini (<?php echo date('d-m-Y'); ?>).</p>
                                            <a href="../guru/jurnal_les.php?session_type=wali" class="btn btn-primary btn-lg btn-block shadow-sm mt-auto"><i class="fas fa-book mr-2"></i> Lihat Jurnal Les</a>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card mapp-link-card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h4 class="mb-0"><i class="fas fa-bolt text-warning mr-2"></i>Tautan Cepat</h4>
                                    <span class="badge badge-light border text-muted">Aksi Cepat</span>
                                </div>
                                <div class="card-body">
                                    <div class="quick-app-grid">
                                        <a href="../guru/jurnal_mengajar.php?session_type=wali" class="quick-app-card">
                                            <div class="quick-app-icon tone-cyan">
                                                <i class="bi bi-book-half"></i>
                                            </div>
                                            <div class="quick-app-info">
                                                <div class="quick-app-title">Jurnal Mengajar</div>
                                                <div class="quick-app-sub">Catat materi harian</div>
                                            </div>
                                            <div class="quick-app-arrow">
                                                <i class="bi bi-chevron-right"></i>
                                            </div>
                                        </a>

                                        <?php if ($is_grade_6_wali): ?>
                                        <a href="../guru/jurnal_les.php?session_type=wali" class="quick-app-card">
                                            <div class="quick-app-icon tone-blue">
                                                <i class="bi bi-journal-bookmark-fill"></i>
                                            </div>
                                            <div class="quick-app-info">
                                                <div class="quick-app-title">Jurnal Les</div>
                                                <div class="quick-app-sub">Kelas 6 intensif</div>
                                            </div>
                                            <div class="quick-app-arrow">
                                                <i class="bi bi-chevron-right"></i>
                                            </div>
                                        </a>
                                        <?php endif; ?>

                                        <button type="button" class="quick-app-card border-0" data-toggle="modal" data-target="#qrCodeModal">
                                            <div class="quick-app-icon tone-amber">
                                                <i class="bi bi-qr-code"></i>
                                            </div>
                                            <div class="quick-app-info">
                                                <div class="quick-app-title">QR Code Saya</div>
                                                <div class="quick-app-sub">Scan presensi cepat</div>
                                            </div>
                                            <div class="quick-app-arrow">
                                                <i class="bi bi-chevron-right"></i>
                                            </div>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php echo renderDashboardAgendaBulanBerjalan($pdo); ?>

                    <!-- Mobile Menu -->
                    <div class="row d-lg-none">
                        <div class="col-12 mb-4">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h6 class="mb-0 font-weight-bold">Menu Utama</h6>
                                        <span class="badge badge-success badge-pill">Semua Fitur</span>
                                    </div>
                                    <?php
                                    global $menu_items;
                                    $mobile_menu_groups = function_exists('get_mobile_menu_groups') ? get_mobile_menu_groups($menu_items) : ['single' => [], 'grouped' => []];
                                    $single_items = $mobile_menu_groups['single'];
                                    $grouped_items = $mobile_menu_groups['grouped'];
                                    ?>
                                    <?php if (!empty($single_items) || !empty($grouped_items)): ?>
                                        <div class="row mapp-menu-grid">
                                            <?php if (!empty($single_items)): ?>
                                                <?php foreach ($single_items as $mg_idx => $item): ?>
                                                    <div class="col-3 mb-3">
                                                        <a href="<?php echo $item['url']; ?>" class="text-decoration-none text-center d-block">
                                                            <div class="mx-auto mb-2 menu-grid-icon mg-c<?php echo ($mg_idx % 8) + 1; ?>">
                                                                <i class="<?php echo $item['icon']; ?>"></i>
                                                            </div>
                                                            <div class="mobile-menu-label small text-dark"><?php echo $item['title']; ?></div>
                                                        </a>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            <?php if (!empty($grouped_items)): ?>
                                                <?php $tile_base = count($single_items); ?>
                                                <?php foreach ($grouped_items as $g_idx => $group): ?>
                                                    <div class="col-3 mb-3">
                                                        <a href="<?php echo htmlspecialchars(mapp_tile_url($group['title']), ENT_QUOTES, 'UTF-8'); ?>" class="text-decoration-none text-center d-block">
                                                            <div class="mx-auto mb-2 menu-grid-icon mg-c<?php echo (($tile_base + $g_idx) % 8) + 1; ?>">
                                                                <i class="<?php echo $group['icon']; ?>"></i>
                                                            </div>
                                                            <div class="mobile-menu-label small text-dark"><?php echo $group['title']; ?></div>
                                                        </a>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                        </div>
                    </div>

                    <style>
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
                        background-color: #ffc107 !important;
                        border-color: #ffc107 !important;
                        color: #212529 !important;
                    }
                    
                    .selectgroup-input:checked + .selectgroup-button-icon[data-status="izin"],
                    .selectgroup-button-icon.active-izin {
                        background-color: #17a2b8 !important;
                        border-color: #17a2b8 !important;
                        color: #fff !important;
                    }
                    </style>

                    <script>
                    // Function to edit keterangan
                    function editKeterangan(formId, boxId, infoId) {
                        const keteranganBox = document.getElementById(boxId);
                        const keteranganInfo = document.getElementById(infoId);
                        
                        if (keteranganBox && keteranganInfo) {
                            keteranganBox.style.display = 'block';
                            keteranganInfo.style.display = 'none';
                            
                            // Focus on textarea
                            const textarea = keteranganBox.querySelector('textarea');
                            if (textarea) {
                                textarea.focus();
                            }
                        }
                    }

                    document.addEventListener('DOMContentLoaded', function() {
                        const radioButtons = document.querySelectorAll('input[name="attendance_status"], input[name="attendance_status_les"]');
                        const statusButtons = document.querySelectorAll('.selectgroup-button-icon');
                        const keteranganBox = document.getElementById('keterangan_box');
                        const keteranganBoxLes = document.getElementById('keterangan_box_les');

                        const alreadyHadirReg = <?php echo ($today_attendance && strtolower($today_attendance['status']) === 'hadir') ? 'true' : 'false'; ?>;
                        const alreadyHadirLes = <?php echo ($today_les_attendance && strtolower($today_les_attendance['status']) === 'hadir') ? 'true' : 'false'; ?>;

                        function updateSubmitBtn(radio) {
                            const form = radio.closest('form');
                            const btn = form ? form.querySelector('button[type="submit"]') : null;
                            if (!btn) return;
                            const alreadyHadir = (form && form.id === 'attendanceFormLes') ? alreadyHadirLes : alreadyHadirReg;
                            if (radio.value === 'hadir' && alreadyHadir) {
                                btn.disabled = true;
                            } else {
                                btn.disabled = false;
                            }
                        }
                        
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
                                updateSubmitBtn(this);
                            });
                        });

                        // Inisialisasi status tombol simpan untuk radio yang sudah terpilih
                        radioButtons.forEach(radio => {
                            if (radio.checked) updateSubmitBtn(radio);
                        });
                    });
                    </script>

                    <div class="row">
                        <div class="col-4 col-md-6 col-lg-4 mapp-stat-full">
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
                        <div class="col-4 col-md-6 col-lg-4">
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
                        <div class="col-4 col-md-6 col-lg-4">
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
                        <div class="col-3 col-md-6 col-lg-3">
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
                        <div class="col-3 col-md-6 col-lg-3">
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
                        <div class="col-3 col-md-6 col-lg-3">
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
                        <div class="col-3 col-md-6 col-lg-3">
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
                            <div class="card mapp-hide-mobile">
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
                                                                        $badge_class = 'badge-warning';
                                                                        break;
                                                                    case 'izin':
                                                                        $badge_class = 'badge-info';
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
                    <p>Gunakan QR Code ini untuk kehadiran.</p>
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
