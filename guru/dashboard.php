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
$teacher = null;
$user_id = $_SESSION['user_id'] ?? 0;
$login_source = $_SESSION['login_source'] ?? '';

if ($login_source == 'tb_guru') {
    // Direct login via NUPTK
    $stmt = $pdo->prepare("SELECT * FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([$user_id]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($login_source == 'tb_pengguna') {
    // Login via tb_pengguna
    $stmt = $pdo->prepare("SELECT g.* FROM tb_guru g JOIN tb_pengguna p ON g.id_guru = p.id_guru WHERE p.id_pengguna = ?");
    $stmt->execute([$user_id]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    // Fallback
    $stmt = $pdo->prepare("SELECT * FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([$user_id]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Normalisasi teacher data untuk PHP 8
$teacher = $teacher ?: [];

// Ensure nama_guru is set in session
if (!empty($teacher['nama_guru']) && empty($_SESSION['nama_guru'])) {
    $_SESSION['nama_guru'] = $teacher['nama_guru'];
}

// Get classes that this teacher teaches
$teacher_class_ids = [];
$teacher_classes = [];
if (!empty($teacher['mengajar'])) {
    $mengajar_decoded = json_decode((string)$teacher['mengajar'], true);
    if (is_array($mengajar_decoded) && !empty($mengajar_decoded)) {
        // Get all classes first
        $all_classes_stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
        $all_classes = $all_classes_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Filter classes based on mengajar IDs
        foreach ($mengajar_decoded as $kelas_id) {
            // Handle both numeric IDs and string IDs, and also class names
            $kelas_id_int = is_numeric($kelas_id) ? (int)$kelas_id : null;
            
            foreach ($all_classes as $kelas) {
                $match = false;
                
                // Match by ID (numeric or string)
                if ($kelas_id_int !== null && $kelas['id_kelas'] == $kelas_id_int) {
                    $match = true;
                } elseif ((string)$kelas['id_kelas'] == (string)$kelas_id) {
                    $match = true;
                } elseif ($kelas['nama_kelas'] == $kelas_id) {
                    // Also check if mengajar contains class names instead of IDs
                    $match = true;
                }
                
                if ($match) {
                    if (!in_array($kelas['id_kelas'], $teacher_class_ids)) {
                        $teacher_class_ids[] = $kelas['id_kelas'];
                        $teacher_classes[] = $kelas; // Store full class data
                    }
                    break;
                }
            }
        }
    }
}

// Get students with attendance status for each class
$class_students = [];
$today = date('Y-m-d');

// Check if teacher teaches Grade 6 and if there's a tutoring schedule today
$is_grade_6_guru = false;
foreach ($teacher_classes as $kelas) {
    $class_name_check = strtoupper($kelas['nama_kelas']);
    if (strpos($class_name_check, '6') !== false || strpos($class_name_check, 'VI') !== false) {
        $is_grade_6_guru = true;
        break;
    }
}

// Hanya true jika guru ini punya jadwal les pada tanggal tersebut
$has_les_schedule_guru = false;
if ($teacher && !empty($teacher['id_guru'])) {
    $stmt_check_sched_guru = $pdo->prepare("SELECT COUNT(*) FROM tb_jadwal_les WHERE tanggal = ? AND id_guru = ?");
    $stmt_check_sched_guru->execute([$today, $teacher['id_guru']]);
    $has_les_schedule_guru = (int)$stmt_check_sched_guru->fetchColumn() > 0;
}

// Debug: Log the conditions for troubleshooting
// error_log("Guru Dashboard - is_grade_6_guru: " . ($is_grade_6_guru ? 'true' : 'false') . ", has_les_schedule_guru: " . ($has_les_schedule_guru ? 'true' : 'false'));

// Determine which attendance table to use for STUDENTS
// Main dashboard always shows regular attendance (tb_absensi)
$student_attendance_table = 'tb_absensi';

foreach ($teacher_classes as $kelas) {
    $stmt = $pdo->prepare("
        SELECT s.*, a.keterangan 
        FROM tb_siswa s 
        LEFT JOIN $student_attendance_table a ON s.id_siswa = a.id_siswa AND a.tanggal = ? 
        WHERE s.id_kelas = ? 
        ORDER BY s.nama_siswa ASC
    ");
    $stmt->execute([$today, $kelas['id_kelas']]);
    $class_students[$kelas['id_kelas']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Calculate attendance stats from loaded data
$jumlah_hadir = 0;
$jumlah_sakit = 0;
$jumlah_izin = 0;
$jumlah_alpa = 0;
$jumlah_berhalangan = 0;
$total_marked_count = 0;

foreach ($class_students as $class_id => $students) {
    foreach ($students as $student) {
        if (isset($student['keterangan']) && !empty($student['keterangan'])) {
            $total_marked_count++;
            switch ($student['keterangan']) {
                case 'Hadir': $jumlah_hadir++; break;
                case 'Sakit': $jumlah_sakit++; break;
                case 'Izin': $jumlah_izin++; break;
                case 'Alpa': $jumlah_alpa++; break;
                case 'Berhalangan': $jumlah_berhalangan++; break;
            }
        }
    }
}

// Include Berhalangan from sholat data (siswa putri berhalangan) for all classes taught
if (!empty($teacher_class_ids)) {
    $placeholders = str_repeat('?,', count($teacher_class_ids) - 1) . '?';
    $berhalangan_stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT sh.id_siswa) AS jumlah
        FROM tb_sholat sh
        JOIN tb_siswa s ON sh.id_siswa = s.id_siswa
        WHERE s.id_kelas IN ($placeholders)
          AND sh.tanggal = ? 
          AND sh.status = 'Berhalangan'
    ");
    $params = array_merge($teacher_class_ids, [$today]);
    $berhalangan_stmt->execute($params);
    $berhalangan_row = $berhalangan_stmt->fetch(PDO::FETCH_ASSOC);
    $jumlah_berhalangan_sholat = (int)($berhalangan_row['jumlah'] ?? 0);

    if ($jumlah_berhalangan_sholat > $jumlah_berhalangan) {
        $jumlah_berhalangan = $jumlah_berhalangan_sholat;
    }
}

// Get statistics based on classes that teacher teaches
$total_kelas = count($teacher_class_ids);

if (!empty($teacher_class_ids)) {
    // Get total students from classes that teacher teaches
    $placeholders = str_repeat('?,', count($teacher_class_ids) - 1) . '?';
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_siswa FROM tb_siswa WHERE id_kelas IN ($placeholders)");
    $stmt->execute($teacher_class_ids);
    $total_siswa = $stmt->fetch(PDO::FETCH_ASSOC)['total_siswa'];
    
} else {
    $total_siswa = 0;
}

// Calculate percentage
$persentase_hadir = 0;
if ($total_marked_count > 0) {
    $persentase_hadir = round(($jumlah_hadir / $total_marked_count) * 100, 1);
}

// Get attendance trend data for the current month for ALL classes taught
$attendance_trends = [];
if (!empty($teacher_class_ids)) {
    $placeholders = str_repeat('?,', count($teacher_class_ids) - 1) . '?';
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
        WHERE s.id_kelas IN ($placeholders) AND a.tanggal >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
        GROUP BY DATE(a.tanggal)
        ORDER BY DATE(a.tanggal) ASC"
    );
    $trend_stmt->execute($teacher_class_ids);
    $attendance_trends = $trend_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get Berhalangan trend from tb_sholat to merge with attendance
    $sholat_trend_stmt = $pdo->prepare(
        "SELECT 
            DATE(sh.tanggal) as tanggal,
            COUNT(DISTINCT sh.id_siswa) as berhalangan_sholat
        FROM tb_sholat sh
        JOIN tb_siswa s ON sh.id_siswa = s.id_siswa
        WHERE s.id_kelas IN ($placeholders)
          AND sh.tanggal >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND sh.status = 'Berhalangan'
        GROUP BY DATE(sh.tanggal)"
    );
    $sholat_trend_stmt->execute($teacher_class_ids);
    $sholat_trends = $sholat_trend_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Merge sholat trends into attendance trends
    foreach ($attendance_trends as &$trend) {
        $tgl = $trend['tanggal'];
        if (isset($sholat_trends[$tgl])) {
            if ($sholat_trends[$tgl] > $trend['berhalangan']) {
                $trend['berhalangan'] = $sholat_trends[$tgl];
            }
        }
    }
    unset($trend);
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

// Background Image
$hero_bg = !empty($school_profile['dashboard_hero_image']) 
    ? '../assets/img/' . $school_profile['dashboard_hero_image'] 
    : '../assets/img/unsplash/eberhard-grossgasteiger-1207565-unsplash.jpg';

$page_title = 'Dashboard Guru';

// Define CSS libraries for this page (only essential ones)
$css_libs = [];

// Define JS libraries for this page (only essential ones)
$js_libs = [];

// Define page-specific JS
$js_page = [
    "
    // Wait for DOM to be fully loaded
    document.addEventListener('DOMContentLoaded', function() {
        // Small delay to ensure Chart.js is ready
        setTimeout(function() {
            var isMobile = window.matchMedia('(max-width: 576px)').matches;
            // Ensure Chart.js is loaded before configuring
            if (typeof Chart === 'undefined') {
                console.error('Chart.js library not loaded');
                return;
            }
            
            // Configure Chart defaults if they exist (for v3.x)
            if (typeof Chart.defaults !== 'undefined') {
                if (typeof Chart.defaults.font !== 'undefined') {
                    // Chart.js v3+
                    Chart.defaults.font.family = 'Nunito, Segoe UI, Arial';
                    Chart.defaults.font.size = isMobile ? 13 : 12;
                    Chart.defaults.color = '#999';
                } else if (typeof Chart.defaults.global !== 'undefined') {
                    // Chart.js v2.x fallback
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
                                        callback: function(value) {
                                            if (Number.isInteger(value)) {
                                                return value;
                                            }
                                        }
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
        $status_to_save = isset($_POST['attendance_status']) ? ucfirst($_POST['attendance_status']) : '';
        $attendance_note = $_POST['attendance_note'] ?? '';
        $now_time = date('Y-m-d H:i:s');
        $nama_guru = isset($_SESSION['nama_guru']) ? $_SESSION['nama_guru'] : 'Guru';

        if (isset($_POST['submit_attendance'])) {
            // Time validation for teacher attendance: 06:00-15:00
            $now_hour = (int)date('H');
            if ($now_hour < 6 || $now_hour >= 15) {
                echo "<script>document.addEventListener('DOMContentLoaded',function(){Swal.fire({title:'Diluar Jam Kehadiran',text:'Kehadiran guru hanya dapat diisi pukul 06:00 - 15:00 WIB.',icon:'warning',confirmButtonText:'OK'});});</script>";
            } else {
            // Regular attendance checks holidays
            $holiday = isSchoolHoliday($pdo, $current_date);
            if ($holiday['is_holiday']) {
                echo "<script>
                    document.addEventListener('DOMContentLoaded', function() {
                        Swal.fire({
                            title: 'Hari Libur',
                            text: 'Kehadiran harian ditutup pada hari libur: " . addslashes($holiday['name']) . "',
                            icon: 'warning',
                            timer: 4000,
                            showConfirmButton: true
                        });
                    });
                </script>";
            } else {
                // Late check: after 07:10 (hanya berlaku untuk status Hadir)
                $late_time = strtotime('07:10');
                $current_time_ts = time();
                $is_late = $current_time_ts > $late_time;
                $late_note = ($status_to_save === 'Hadir') ? ($is_late ? 'Terlambat' : 'Tepat Waktu') : '';
                $keterangan_final = trim(implode(' | ', array_filter([$attendance_note, $late_note])));
                
                // Check if already attended regular
                $check_stmt = $pdo->prepare("SELECT id_absensi FROM tb_absensi_guru WHERE id_guru = ? AND tanggal = ?");
                $check_stmt->execute([$current_teacher_id, $current_date]);
                
                $late_text = ($status_to_save === 'Hadir' && $is_late) ? ' Maaf anda terlambat.' : '';
                if ($check_stmt->rowCount() > 0) {
                    $update_stmt = $pdo->prepare("UPDATE tb_absensi_guru SET status = ?, keterangan = ?, waktu_input = ? WHERE id_guru = ? AND tanggal = ?");
                    $update_stmt->execute([$status_to_save, $keterangan_final, $now_time, $current_teacher_id, $current_date]);
                    $msg_text = 'Kehadiran harian berhasil diperbarui.' . $late_text;
                } else {
                    $insert_stmt = $pdo->prepare("INSERT INTO tb_absensi_guru (id_guru, tanggal, status, keterangan, waktu_input) VALUES (?, ?, ?, ?, ?)");
                    $insert_stmt->execute([$current_teacher_id, $current_date, $status_to_save, $keterangan_final, $now_time]);
                    $msg_text = 'Kehadiran harian berhasil disimpan.' . $late_text;
                }
                
                $waktu = date('H:i');
                    $tanggal_indo = date('d-m-Y');
                    $notif_msg = "$nama_guru (Guru) telah mengirim kehadiran pada pukul $waktu tanggal $tanggal_indo";
                    createNotification($pdo, $notif_msg, 'absensi_guru.php', 'absensi_guru');
                logActivity($pdo, $nama_guru, 'Absensi Guru', "$nama_guru mengisi kehadiran harian: $status_to_save");
                
                echo "<script>
                    document.addEventListener('DOMContentLoaded', function() {
                        Swal.fire({ title: 'Berhasil!', text: '$msg_text', icon: 'success', timer: 3000, showConfirmButton: false });
                    });
                </script>";
            }
        }
    } elseif (isset($_POST['submit_attendance_les'])) {
            // Tutoring attendance - no holiday check, only schedule check
            $stmt_check_sched = $pdo->prepare("SELECT COUNT(*) FROM tb_jadwal_les WHERE tanggal = ? AND id_guru = ?");
            $stmt_check_sched->execute([$current_date, $current_teacher_id]);
            if ($stmt_check_sched->fetchColumn() > 0) {
                $check_stmt = $pdo->prepare("SELECT id_absensi FROM tb_absensi_les_guru WHERE id_guru = ? AND tanggal = ?");
                $check_stmt->execute([$current_teacher_id, $current_date]);
                
                $status_to_save_les = isset($_POST['attendance_status_les']) ? ucfirst($_POST['attendance_status_les']) : '';
                $attendance_note_les = $_POST['attendance_note_les'] ?? '';

                if ($check_stmt->rowCount() > 0) {
                    $update_stmt = $pdo->prepare("UPDATE tb_absensi_les_guru SET status = ?, keterangan = ?, waktu_input = ? WHERE id_guru = ? AND tanggal = ?");
                    $update_stmt->execute([$status_to_save_les, $attendance_note_les, $now_time, $current_teacher_id, $current_date]);
                    $msg_text = 'Kehadiran les berhasil diperbarui.';
                } else {
                    $insert_stmt = $pdo->prepare("INSERT INTO tb_absensi_les_guru (id_guru, tanggal, status, keterangan, waktu_input) VALUES (?, ?, ?, ?, ?)");
                    $insert_stmt->execute([$current_teacher_id, $current_date, $status_to_save_les, $attendance_note_les, $now_time]);
                    $msg_text = 'Kehadiran les berhasil disimpan.';
                }
                
                createNotification($pdo, "$nama_guru telah mengirim kehadiran les", 'absensi_les_guru.php', 'absensi_les_guru');
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
}
?>

<div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Dashboard Guru</h1>
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
                                            <h2>Selamat Datang, <?php echo isset($teacher['nama_guru']) ? htmlspecialchars($teacher['nama_guru']) : 'Guru'; ?></h2>
                                            <p class="lead">Anda mengajar <b><?php echo $total_kelas; ?></b> kelas dengan total <b><?php echo $total_siswa; ?></b> siswa.</p>
                                            
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
                                                    <div class="col-auto">
                                                        <div class="font-weight-bold text-white-50">TMT</div>
                                                        <div><?php echo !empty($teacher['tmt']) ? date('d-m-Y', strtotime($teacher['tmt'])) : '-'; ?></div>
                                                    </div>
                                                    <div class="col-auto">
                                                        <div class="font-weight-bold text-white-50">Masa Bakti</div>
                                                        <div><?php echo calculateMasaBakti($teacher['tmt'] ?? null); ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mt-2">
                                                <a href="profil.php" class="text-white-50 small" title="Edit Profil"><i class="fas fa-pen mr-1"></i>Edit Profil</a>
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
                        <div class="col-12 mb-4">
                            <div class="card border-left-primary shadow-sm">
                                <div class="card-body py-3">
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <h6 class="font-weight-bold mb-2"><i class="fas fa-info-circle text-primary mr-2"></i>Ketentuan Pengisian Kehadiran dan Jurnal KBM Hari Ini</h6>
                                            <p class="mb-2 font-weight-bold" style="font-size:17px;color:#856404;background:#fff3cd;padding:8px 14px;border-radius:5px;">
                                                <i class="fas fa-exclamation-triangle mr-1"></i>Pastikan mengisi <u>Kehadiran Siswa</u> sebelum memulai KBM hari ini!
                                            </p>
                                            <div class="d-flex flex-wrap" style="gap:12px;">
                                                <div><span class="badge badge-success px-3 py-2"><i class="fas fa-user-check mr-1"></i> Kehadiran Siswa</span> <span class="text-muted" style="font-size:13px;">07:00 - 14:00 WIB</span></div>
                                                <div><span class="badge badge-primary px-3 py-2"><i class="fas fa-chalkboard-teacher mr-1"></i> Kehadiran Guru</span> <span class="text-muted" style="font-size:13px;">06:00 - 15:00 WIB</span></div>
                                                <div><span class="badge badge-warning px-3 py-2"><i class="fas fa-book mr-1"></i> Jurnal Mengajar</span> <span class="text-muted" style="font-size:13px;">07:00 - 14:00 WIB</span></div>
                                            </div>
                                        </div>
                                        <div class="col-md-4 text-md-right mt-2 mt-md-0">
                                            <span class="font-weight-bold" style="font-size:17px;color:#e74c3c;"><i class="fas fa-exclamation-circle mr-1"></i>Isilah tepat waktu agar KBM tercatat rapi!</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Attendance Box for Teacher -->
                    <div class="row">
                        <?php 
                        $holiday = isSchoolHoliday($pdo, $today);
                        // Box les selalu muncul untuk guru kelas 6, cek apakah ada jadwal hari ini
                        $show_les_box = $is_grade_6_guru; // Selalu tampilkan untuk kelas 6
                        $col_class = $show_les_box ? 'col-12 col-md-6' : 'col-12';
                        
                        if (!$holiday['is_holiday']): 
                            // Get current regular attendance
                            $stmt_check_reg = $pdo->prepare("SELECT * FROM tb_absensi_guru WHERE id_guru = ? AND tanggal = ?");
                            $stmt_check_reg->execute([$teacher['id_guru'], $today]);
                            $today_reg_attendance = $stmt_check_reg->fetch(PDO::FETCH_ASSOC);
                        ?>
                        <div class="<?php echo $col_class; ?> mb-4">
                            <div class="card card-primary">
                                <div class="card-header">
                                    <h4>Kehadiran Harian</h4>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-light alert-has-icon shadow-sm border mb-3">
                                        <div class="alert-icon text-primary"><i class="far fa-bell"></i></div>
                                        <div class="alert-body">
                                            <div class="alert-title font-weight-bold">Penting</div>
                                            Jangan lupa untuk mengisi <b>Kehadiran</b> Anda, <b>Kehadiran Siswa</b>, serta <b>Jurnal Mengajar</b> hari ini.
                                        </div>
                                    </div>
                                    <form method="POST" action="" id="attendanceFormReg">
                                        <div class="form-group mb-3 text-center">
                                            <label class="d-block font-weight-bold">Status Kehadiran (<?php echo date('d-m-Y'); ?>)</label>
                                            <div class="row justify-content-center mb-3">
                                                <div class="col-4 mb-2">
                                                    <button type="button" onclick="regAutoAtt('hadir')" class="btn btn-success btn-block py-2" style="<?php echo ($today_reg_attendance && strtolower($today_reg_attendance['status']) == 'hadir') ? 'cursor: not-allowed;' : 'opacity: 0.45;'; ?>">
                                                        <span class="font-weight-bold" style="font-size: 0.8rem;"><i class="fas fa-check mr-1" style="font-size:0.75rem;"></i>Hadir</span>
                                                    </button>
                                                </div>
                                                <div class="col-4 mb-2">
<button type="button" onclick="regAutoAtt('sakit')" class="btn btn-warning btn-block py-2" style="<?php echo ($today_reg_attendance && strtolower($today_reg_attendance['status']) == 'sakit') ? 'cursor: not-allowed;' : 'opacity: 0.45;'; ?>">
                                                        <span class="font-weight-bold" style="font-size: 0.8rem;"><i class="fas fa-procedures mr-1" style="font-size:0.75rem;"></i>Sakit</span>
                                                    </button>
                                                </div>
                                                <div class="col-4 mb-2">
<button type="button" onclick="regAutoAtt('izin')" class="btn btn-info btn-block py-2" style="<?php echo ($today_reg_attendance && strtolower($today_reg_attendance['status']) == 'izin') ? 'cursor: not-allowed;' : 'opacity: 0.45;'; ?>">
                                                        <span class="font-weight-bold" style="font-size: 0.8rem;"><i class="fas fa-paper-plane mr-1" style="font-size:0.75rem;"></i>Izin</span>
                                                    </button>
                                                </div>
                                            </div>
                                            <?php
                                            $reg_att_label = '';
                                            $reg_att_late = false;
                                            if ($today_reg_attendance && strtolower($today_reg_attendance['status']) === 'hadir') {
                                                $reg_att_ts = !empty($today_reg_attendance['waktu_input']) ? strtotime($today_reg_attendance['waktu_input']) : time();
                                                $reg_att_late = $reg_att_ts > strtotime('07:10');
                                                $reg_att_label = $reg_att_late ? 'Terlambat' : 'Tepat Waktu';
                                            }
                                            ?>
                                            <?php if ($reg_att_label !== ''): ?>
                                            <div class="mt-2" id="regAttBadge">
                                                <span class="badge <?php echo $reg_att_late ? 'badge-warning' : 'badge-success'; ?> px-3 py-2">
                                                    <i class="fas fa-<?php echo $reg_att_late ? 'clock' : 'check-circle'; ?> mr-1"></i>
                                                    <?php echo $reg_att_label; ?>
                                                </span>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($today_reg_attendance && strtolower($today_reg_attendance['status']) == 'izin' && !empty($today_reg_attendance['keterangan'])): ?>
                                            <div class="mt-2">
                                                <span class="badge badge-info px-3 py-2">
                                                    <i class="fas fa-envelope mr-1"></i> Izin: <?php echo htmlspecialchars($today_reg_attendance['keterangan']); ?>
                                                </span>
                                            </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="form-group" id="keteranganAreaReg" style="display: none;">
                                            <label class="font-weight-bold">Alasan Izin</label>
                                            <textarea name="attendance_note" id="noteReg" class="form-control" placeholder="Tuliskan alasan izin..."><?php echo $today_reg_attendance ? htmlspecialchars($today_reg_attendance['keterangan']) : ''; ?></textarea>
                                            <button type="submit" class="btn btn-info btn-lg btn-block mt-2"><i class="fas fa-save mr-2"></i> Simpan Izin</button>
                                        </div>

                                        <input type="hidden" name="attendance_status" id="attStatusReg" value="">
                                        <input type="hidden" name="submit_attendance" value="1">
                                    </form>
                                    <script>
                                    function regAutoAtt(status) {
                                        var form = document.getElementById('attendanceFormReg');
                                        var cur = '<?php echo $today_reg_attendance ? strtolower($today_reg_attendance['status']) : ''; ?>';
                                        if (cur !== '' && status === cur) { return; }
                                        var badge = document.getElementById('regAttBadge');
                                        if (badge) badge.style.display = (status === 'hadir') ? '' : 'none';
                                        document.getElementById('attStatusReg').value = status;
                                        var area = document.getElementById('keteranganAreaReg');
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
                        <div class="<?php echo $col_class; ?> mb-4">
                            <div class="card card-warning">
                                <div class="card-header"><h4>Kehadiran Harian Guru</h4></div>
                                <div class="card-body d-flex align-items-center justify-content-center text-center">
                                    <div class="py-2">
                                        <div class="mb-3">
                                            <i class="fas fa-calendar-check text-warning" style="font-size: 60px;"></i>
                                        </div>
                                        <h5 class="font-weight-bold mb-1">Hari Libur Sekolah</h5>
                                        <p class="text-muted mb-2">Hari ini, <strong><?php echo formatDateIndonesia(date('Y-m-d')); ?></strong> adalah <strong><?php echo $holiday['name']; ?></strong>.</p>
                                        <div class="badge badge-warning px-3 py-1" style="font-size: 0.9rem; border-radius: 30px;">
                                            <i class="fas fa-info-circle mr-2"></i> Kehadiran Harian Ditutup
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php 
                        if ($show_les_box): 
                            // Get current les attendance
                            $stmt_check_les = $pdo->prepare("SELECT * FROM tb_absensi_les_guru WHERE id_guru = ? AND tanggal = ?");
                            $stmt_check_les->execute([$teacher['id_guru'], $today]);
                            $today_les_attendance = $stmt_check_les->fetch(PDO::FETCH_ASSOC);
                        ?>
                        <div class="col-12 col-md-6 mb-4">
                            <div class="card card-dark">
                                <div class="card-header">
                                    <h4>Kehadiran Les Guru (Kelas 6)</h4>
                                </div>
                                <div class="card-body">
                                    <?php if ($has_les_schedule_guru): ?>
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
                                        $show_keterangan_info_les_guru = ($today_les_attendance && in_array(strtolower($today_les_attendance['status']), ['sakit', 'izin']) && !empty($today_les_attendance['keterangan']));
                                        ?>

                                        <div class="form-group keterangan-box" id="keterangan_box_les_guru" style="display: <?php echo $show_keterangan_info_les_guru ? 'none' : (($today_les_attendance && in_array(strtolower($today_les_attendance['status']), ['izin', 'sakit'])) ? 'block' : 'none'); ?>;">
                                            <label>Keterangan</label>
                                            <textarea name="attendance_note_les" class="form-control"><?php echo $today_les_attendance ? htmlspecialchars($today_les_attendance['keterangan']) : ''; ?></textarea>
                                        </div>

                                        <?php if ($show_keterangan_info_les_guru): ?>
                                        <div class="form-group" id="keterangan_info_les_guru" style="display: block;">
                                            <label>Keterangan</label>
                                            <div class="alert alert-light border shadow-sm mb-2">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div class="flex-grow-1">
                                                        <i class="fas fa-info-circle text-info mr-2"></i>
                                                        <span><?php echo htmlspecialchars($today_les_attendance['keterangan']); ?></span>
                                                    </div>
                                                    <button type="button" class="btn btn-sm btn-outline-primary btn-edit-keterangan" onclick="editKeterangan('attendanceFormLes', 'keterangan_box_les_guru', 'keterangan_info_les_guru')">
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
                                            <a href="jurnal_les.php" class="btn btn-primary btn-lg btn-block shadow-sm mt-auto"><i class="fas fa-book mr-2"></i> Lihat Jadwal & Jurnal Les</a>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php echo renderDashboardAgendaBulanBerjalan($pdo); ?>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Tautan Cepat</h4>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php 
                                        $btn_col = $is_grade_6_guru ? 'col-12 col-md-4' : 'col-12 col-md-6';
                                        ?>
                                        <div class="<?php echo $btn_col; ?> mb-2">
                                            <a href="jurnal_mengajar.php" class="btn btn-info btn-lg btn-block btn-icon icon-left shadow-sm"><i class="fas fa-book-open"></i> Isi Jurnal Mengajar</a>
                                        </div>
                                        <?php if ($is_grade_6_guru): ?>
                                        <div class="<?php echo $btn_col; ?> mb-2">
                                            <a href="jurnal_les.php" class="btn btn-primary btn-lg btn-block btn-icon icon-left shadow-sm"><i class="fas fa-book"></i> Isi Jurnal Les</a>
                                        </div>
                                        <?php endif; ?>
                                        <div class="<?php echo $btn_col; ?> mb-2">
                                            <button type="button" class="btn btn-warning btn-lg btn-block btn-icon icon-left shadow-sm" data-toggle="modal" data-target="#qrCodeModal"><i class="fas fa-qrcode"></i> Tampilkan QR Code</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

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

                        const alreadyHadirReg = <?php echo ($today_reg_attendance && strtolower($today_reg_attendance['status']) === 'hadir') ? 'true' : 'false'; ?>;
                        const alreadyHadirLes = <?php echo ($today_les_attendance && strtolower($today_les_attendance['status']) === 'hadir') ? 'true' : 'false'; ?>;

                        function updateSubmitBtn(radio) {
                            const form = radio.closest('form');
                            const btn = form ? form.querySelector('button[type="submit"]') : null;
                            if (!btn) return;
                            const alreadyHadir = (radio.name === 'attendance_status') ? alreadyHadirReg : alreadyHadirLes;
                            if (radio.value === 'hadir' && alreadyHadir) {
                                btn.disabled = true;
                            } else {
                                btn.disabled = false;
                            }
                        }

                        function updateKeteranganBox(radio) {
                            const form = radio.closest('form');
                            const keteranganBox = form.querySelector('.keterangan-box');
                            if (!keteranganBox) return;
                            const keteranganTextarea = keteranganBox.querySelector('textarea');
                            
                            const status = radio.value;
                            if (status === 'izin' || status === 'sakit') {
                                keteranganBox.style.display = 'block';
                                keteranganTextarea.required = (status === 'izin');
                            } else {
                                keteranganBox.style.display = 'none';
                                keteranganTextarea.required = false;
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
                                    global $menu_items;
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
                                            <div class="mt-3" id="menu-<?php echo str_replace(' ', '-', $group['title']); ?>">
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
                    document.addEventListener('DOMContentLoaded', function() {
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
                        <div class="col-6 col-md-6 col-lg-4">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-primary">
                                    <i class="fas fa-chalkboard"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Total Kelas Ajar</h4>
                                    </div>
                                    <div class="card-body">
                                        <strong><?php echo $total_kelas; ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-6 col-lg-4">
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
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-6 col-md-6 col-lg-4">
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
                        <div class="col-6 col-md-6 col-lg-3">
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
                        <div class="col-6 col-md-6 col-lg-3">
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
                        <div class="col-6 col-md-6 col-lg-3">
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
                        <div class="col-6 col-md-6 col-lg-3">
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

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Status Kehadiran Siswa Hari Ini</h4>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($teacher_classes)): ?>
                                        <?php $use_class_tabs = count($teacher_classes) >= 2; ?>

                                        <?php if ($use_class_tabs): ?>
                                            <ul class="nav nav-tabs mb-3" id="attendanceClassTabs" role="tablist">
                                                <?php foreach ($teacher_classes as $tab_index => $kelas): ?>
                                                    <?php $is_active_tab = $tab_index === 0; ?>
                                                    <li class="nav-item" role="presentation">
                                                        <a
                                                            class="nav-link <?php echo $is_active_tab ? 'active' : ''; ?>"
                                                            id="kelas-tab-<?php echo (int)$kelas['id_kelas']; ?>"
                                                            data-toggle="tab"
                                                            href="#kelas-panel-<?php echo (int)$kelas['id_kelas']; ?>"
                                                            role="tab"
                                                            aria-controls="kelas-panel-<?php echo (int)$kelas['id_kelas']; ?>"
                                                            aria-selected="<?php echo $is_active_tab ? 'true' : 'false'; ?>"
                                                        >
                                                            Kelas <?php echo htmlspecialchars($kelas['nama_kelas']); ?>
                                                        </a>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>

                                        <div class="tab-content" id="attendanceClassTabsContent">
                                            <?php foreach ($teacher_classes as $panel_index => $kelas): ?>
                                                <?php
                                                $is_active_panel = $panel_index === 0;
                                                $students_in_class = $class_students[$kelas['id_kelas']] ?? [];
                                                ?>
                                                <div
                                                    class="tab-pane fade <?php echo $is_active_panel ? 'show active' : ''; ?>"
                                                    id="kelas-panel-<?php echo (int)$kelas['id_kelas']; ?>"
                                                    role="tabpanel"
                                                    aria-labelledby="kelas-tab-<?php echo (int)$kelas['id_kelas']; ?>"
                                                >
                                                    <div class="table-responsive">
                                                        <table class="table table-striped table-bordered mb-0">
                                                            <thead>
                                                                <tr>
                                                                    <th style="width: 60px;">No</th>
                                                                    <th>Nama Siswa</th>
                                                                    <th style="width: 180px;">NISN</th>
                                                                    <th style="width: 180px;">Status Kehadiran</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php if (!empty($students_in_class)): ?>
                                                                    <?php foreach ($students_in_class as $idx => $siswa): ?>
                                                                        <?php
                                                                        $status = $siswa['keterangan'] ?? 'Belum Absen';
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
                                                                        <tr>
                                                                            <td><?php echo $idx + 1; ?></td>
                                                                            <td><?php echo htmlspecialchars($siswa['nama_siswa']); ?></td>
                                                                            <td><?php echo htmlspecialchars($siswa['nisn']); ?></td>
                                                                            <td>
                                                                                <span class="badge <?php echo $badge_class; ?>">
                                                                                    <?php echo htmlspecialchars($status); ?>
                                                                                </span>
                                                                            </td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                <?php else: ?>
                                                                    <tr>
                                                                        <td colspan="4" class="text-center">Tidak ada siswa dalam kelas ini</td>
                                                                    </tr>
                                                                <?php endif; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center text-muted py-4">Belum ada kelas yang diajar.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal fade" id="qrCodeModal" tabindex="-1" role="dialog" aria-labelledby="qrCodeModalLabel" aria-hidden="true">
                        <div class="modal-dialog" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="qrCodeModalLabel">QR Code Presensi Guru</h5>
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                </div>
                                <div class="modal-body text-center">
                                    <div id="qrcode" class="mb-3"></div>
                                    <p class="font-weight-bold"><?php echo htmlspecialchars($teacher['nama_guru']); ?></p>
                                    <p class="text-muted"><?php echo htmlspecialchars($teacher['nuptk']); ?></p>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        var qrcode = new QRCode(document.getElementById("qrcode"), {
                            text: "<?php echo $teacher['nuptk']; ?>",
                            width: 256,
                            height: 256,
                            colorDark : "#000000",
                            colorLight : "#ffffff",
                            correctLevel : QRCode.CorrectLevel.H
                        });
                    });
                    </script>
                </section>
            </div>

<?php 
include '../templates/user_footer.php'; 
?>
