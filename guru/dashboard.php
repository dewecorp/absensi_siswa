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

// Ensure nama_guru is set in session for consistent navbar display
if ($teacher && (!isset($_SESSION['nama_guru']) || empty($_SESSION['nama_guru']))) {
    $_SESSION['nama_guru'] = $teacher['nama_guru'];
}

// Get classes that this teacher teaches (from mengajar field)
$teacher_class_ids = [];
$teacher_classes = []; // Store full class data
if (!empty($teacher['mengajar'])) {
    $mengajar_decoded = json_decode($teacher['mengajar'], true);
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
foreach ($teacher_classes as $kelas) {
    $stmt = $pdo->prepare("
        SELECT s.*, a.keterangan 
        FROM tb_siswa s 
        LEFT JOIN tb_absensi a ON s.id_siswa = a.id_siswa AND a.tanggal = ? 
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
                                    'rgba(54, 162, 235, 0.2)',
                                    'rgba(255, 99, 132, 0.2)',
                                    'rgba(255, 206, 86, 0.2)',
                                    'rgba(153, 102, 255, 0.2)',
                                    'rgba(220, 53, 69, 0.2)'
                                ],
                                borderColor: [
                                    'rgba(54, 162, 235, 1)',
                                    'rgba(255,99,132,1)',
                                    'rgba(255, 206, 86, 1)',
                                    'rgba(153, 102, 255, 1)',
                                    'rgba(220, 53, 69, 1)'
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
                                borderColor: 'rgb(54, 162, 235)',
                                backgroundColor: 'rgba(54, 162, 235, 0.2)',
                                fill: false
                            }, {
                                label: 'Sakit',
                                data: " . $sakit_data_json . ",
                                borderColor: 'rgb(255, 99, 132)',
                                backgroundColor: 'rgba(255, 99, 132, 0.2)',
                                fill: false
                            }, {
                                label: 'Izin',
                                data: " . $izin_data_json . ",
                                borderColor: 'rgb(255, 206, 86)',
                                backgroundColor: 'rgba(255, 206, 86, 0.2)',
                                fill: false
                            }, {
                                label: 'Alpa',
                                data: " . $alpa_data_json . ",
                                borderColor: 'rgb(153, 102, 255)',
                                backgroundColor: 'rgba(153, 102, 255, 0.2)',
                                fill: false
                            }, {
                                label: 'Berhalangan',
                                data: " . $berhalangan_data_json . ",
                                borderColor: 'rgb(220, 53, 69)',
                                backgroundColor: 'rgba(220, 53, 69, 0.2)',
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
include '../templates/sidebar.php';

// Handle Attendance Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_attendance'])) {
    $attendance_status = $_POST['attendance_status'];
    $attendance_note = $_POST['attendance_note'] ?? '';
    
    // Determine teacher ID based on session
    $current_teacher_id = 0;
    if (isset($teacher['id_guru'])) {
        $current_teacher_id = $teacher['id_guru'];
    } elseif (isset($_SESSION['user_id']) && ($_SESSION['level'] == 'guru' || $_SESSION['level'] == 'wali')) {
         // Fallback if $teacher not set but user is guru/wali directly
         $current_teacher_id = $_SESSION['user_id'];
    }
    
    if ($current_teacher_id > 0) {
        $current_date = date('Y-m-d');
        
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
            // Stop processing on holidays
            goto after_submission;
        }
        
        // Check if already attended
        $check_stmt = $pdo->prepare("SELECT id_absensi FROM tb_absensi_guru WHERE id_guru = ? AND tanggal = ?");
        $check_stmt->execute([$current_teacher_id, $current_date]);
        
        if ($check_stmt->rowCount() > 0) {
            // Update existing
             $update_stmt = $pdo->prepare("UPDATE tb_absensi_guru SET status = ?, keterangan = ? WHERE id_guru = ? AND tanggal = ?");
             if ($update_stmt->execute([$attendance_status, $attendance_note, $current_teacher_id, $current_date])) {
                 
                 // Send notification to admin
                 $nama_guru = isset($_SESSION['nama_guru']) ? $_SESSION['nama_guru'] : 'Guru';
                 $waktu = date('H:i');
                 $tanggal = date('d-m-Y');
                 $notif_msg = "$nama_guru telah mengirim kehadiran pada pukul $waktu tanggal $tanggal";
                 createNotification($pdo, $notif_msg, 'absensi_guru.php', 'absensi_guru');
                 
                 // Log activity
                 $log_desc = "$nama_guru memperbarui kehadiran: $attendance_status";
                 if ($attendance_note) $log_desc .= " ($attendance_note)";
                 logActivity($pdo, $nama_guru, 'Absensi Guru', $log_desc);
                 
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
            $insert_stmt = $pdo->prepare("INSERT INTO tb_absensi_guru (id_guru, tanggal, status, keterangan) VALUES (?, ?, ?, ?)");
            if ($insert_stmt->execute([$current_teacher_id, $current_date, $attendance_status, $attendance_note])) {
                 
                 // Send notification to admin
                 $nama_guru = isset($_SESSION['nama_guru']) ? $_SESSION['nama_guru'] : 'Guru';
                 $waktu = date('H:i');
                 $tanggal = date('d-m-Y');
                 $notif_msg = "$nama_guru telah mengirim kehadiran pada pukul $waktu tanggal $tanggal";
                 createNotification($pdo, $notif_msg, 'absensi_guru.php', 'absensi_guru');
                 
                 // Log activity
                 $log_desc = "$nama_guru mengisi kehadiran: $attendance_status";
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
    }
    after_submission:
}
?>

<div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Dashboard Guru</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item active"><a href="#">Dashboard</a></div>
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
                                                </div>
                                            </div>
                                        </div>
                                    </div>
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
                        <div class="col-lg-4 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-primary">
                                    <i class="far fa-user"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Total Siswa</h4>
                                    </div>
                                    <div class="card-body">
                                        <?php echo $total_siswa; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-warning">
                                    <i class="fas fa-school"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Total Kelas</h4>
                                    </div>
                                    <div class="card-body">
                                        <?php echo $total_kelas; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-info">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Kehadiran Hari Ini</h4>
                                    </div>
                                    <div class="card-body">
                                        <?php echo $persentase_hadir; ?>%
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                    </div>
                    
                    <div class="row">
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-success">
                                    <i class="fas fa-user-check"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Hadir</h4>
                                    </div>
                                    <div class="card-body">
                                        <?php echo $jumlah_hadir; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-warning">
                                    <i class="fas fa-heartbeat"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Sakit</h4>
                                    </div>
                                    <div class="card-body">
                                        <?php echo $jumlah_sakit; ?>
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
                                        <?php echo $jumlah_izin; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-danger">
                                    <i class="fas fa-ban"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Berhalangan</h4>
                                    </div>
                                    <div class="card-body">
                                        <?php echo $jumlah_berhalangan; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Statistik Kehadiran Siswa Hari Ini</h4>
                                </div>
                                <div class="card-body">
                                    <canvas id="myChart" height="220" style="width:100%; display:block; max-width:100%;"></canvas>
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
                                    <canvas id="trendChart" height="240" style="width:100%; display:block; max-width:100%;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Attendance Box for Teacher -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Absensi Harian & Jurnal Mengajar</h4>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-light alert-has-icon">
                                        <div class="alert-icon"><i class="far fa-bell"></i></div>
                                        <div class="alert-body">
                                            <div class="alert-title">Penting</div>
                                            Jangan lupa untuk mengisi <b>Absensi Kehadiran</b> Anda, <b>Absensi Siswa</b>, serta <b>Jurnal Mengajar</b> hari ini.
                                        </div>
                                    </div>

                                    <?php
                                    // Check current attendance status
                                    $today_attendance = null;
                                    if (isset($teacher['id_guru'])) {
                                        $stmt_check = $pdo->prepare("SELECT * FROM tb_absensi_guru WHERE id_guru = ? AND tanggal = CURDATE()");
                                        $stmt_check->execute([$teacher['id_guru']]);
                                        $today_attendance = $stmt_check->fetch(PDO::FETCH_ASSOC);
                                    }
                                    ?>

                                    <form method="POST" action="" id="attendanceForm">
                                        <div class="form-group">
                                            <label class="d-block font-weight-bold">Status Kehadiran Hari Ini (<?php echo date('d-m-Y'); ?>)</label>
                                            <div class="selectgroup selectgroup-pills">
                                                <label class="selectgroup-item">
                                                    <input type="radio" name="attendance_status" value="hadir" class="selectgroup-input" <?php echo ($today_attendance && strtolower($today_attendance['status']) == 'hadir') ? 'checked' : ''; ?> required>
                                                    <span class="selectgroup-button selectgroup-button-icon"><i class="fas fa-check"></i> Hadir</span>
                                                </label>
                                                <label class="selectgroup-item">
                                                    <input type="radio" name="attendance_status" value="sakit" class="selectgroup-input" <?php echo ($today_attendance && strtolower($today_attendance['status']) == 'sakit') ? 'checked' : ''; ?>>
                                                    <span class="selectgroup-button selectgroup-button-icon"><i class="fas fa-procedures"></i> Sakit</span>
                                                </label>
                                                <label class="selectgroup-item">
                                                    <input type="radio" name="attendance_status" value="izin" class="selectgroup-input" id="radio_izin" <?php echo ($today_attendance && strtolower($today_attendance['status']) == 'izin') ? 'checked' : ''; ?>>
                                                    <span class="selectgroup-button selectgroup-button-icon"><i class="fas fa-paper-plane"></i> Izin</span>
                                                </label>
                                            </div>
                                        </div>

                                        <div class="form-group" id="keterangan_box" style="display: <?php echo ($today_attendance && in_array(strtolower($today_attendance['status']), ['izin', 'sakit'])) ? 'block' : 'none'; ?>;">
                                            <label>Keterangan</label>
                                            <textarea name="attendance_note" class="form-control" placeholder="Masukkan keterangan..."><?php echo $today_attendance ? htmlspecialchars($today_attendance['keterangan']) : ''; ?></textarea>
                                        </div>

                                        <div class="form-group">
                                            <div class="row">
                                                <div class="col-12 col-md-4 mb-2">
                                                    <button type="submit" name="submit_attendance" class="btn btn-primary btn-lg btn-block btn-icon icon-left"><i class="fas fa-save"></i> Simpan Absensi</button>
                                                </div>
                                                <div class="col-12 col-md-4 mb-2">
                                                    <a href="jurnal_mengajar.php" class="btn btn-info btn-lg btn-block btn-icon icon-left"><i class="fas fa-book-open"></i> Isi Jurnal Mengajar</a>
                                                </div>
                                                <div class="col-12 col-md-4 mb-2">
                                                    <button type="button" class="btn btn-warning btn-lg btn-block btn-icon icon-left" data-toggle="modal" data-target="#qrCodeModal"><i class="fas fa-qrcode"></i> Tampilkan QR Code</button>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const radioButtons = document.querySelectorAll('input[name="attendance_status"]');
                        const keteranganBox = document.getElementById('keterangan_box');
                        const keteranganTextarea = keteranganBox.querySelector('textarea');
                        
                        function updateKeteranganBox() {
                            const selectedRadio = document.querySelector('input[name="attendance_status"]:checked');
                            if (selectedRadio) {
                                const status = selectedRadio.value;
                                if (status === 'izin' || status === 'sakit') {
                                    keteranganBox.style.display = 'block';
                                    keteranganTextarea.required = (status === 'izin');
                                } else {
                                    keteranganBox.style.display = 'none';
                                    keteranganTextarea.required = false;
                                }
                            }
                        }

                        // Run on load to set initial state
                        updateKeteranganBox();
                        
                        radioButtons.forEach(radio => {
                            radio.addEventListener('change', updateKeteranganBox);
                        });
                    });
                    </script>
                    
                    <?php if (!empty($teacher_classes)): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Status Kehadiran Siswa Hari Ini</h4>
                                </div>
                                <div class="card-body">
                                    <?php if (count($teacher_classes) > 1): ?>
                                    <!-- Tabs for multiple classes -->
                                    <ul class="nav nav-tabs" id="classTabs" role="tablist">
                                        <?php foreach ($teacher_classes as $index => $kelas): ?>
                                        <li class="nav-item">
                                            <a class="nav-link <?php echo $index === 0 ? 'active' : ''; ?>" 
                                               id="tab-<?php echo $kelas['id_kelas']; ?>" 
                                               data-toggle="tab" 
                                               href="#content-<?php echo $kelas['id_kelas']; ?>" 
                                               role="tab">
                                                <?php echo htmlspecialchars($kelas['nama_kelas']); ?>
                                            </a>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <div class="tab-content" id="classTabContent">
                                        <?php foreach ($teacher_classes as $index => $kelas): ?>
                                        <div class="tab-pane fade <?php echo $index === 0 ? 'show active' : ''; ?>" 
                                             id="content-<?php echo $kelas['id_kelas']; ?>" 
                                             role="tabpanel">
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
                                                        <?php 
                                                        $students = $class_students[$kelas['id_kelas']] ?? [];
                                                        if (empty($students)): ?>
                                                        <tr>
                                                            <td colspan="4" class="text-center">Tidak ada siswa dalam kelas ini</td>
                                                        </tr>
                                                        <?php else: ?>
                                                        <?php foreach ($students as $idx => $student): ?>
                                                        <tr>
                                                            <td><?php echo $idx + 1; ?></td>
                                                            <td><?php echo htmlspecialchars($student['nama_siswa']); ?></td>
                                                            <td><?php echo htmlspecialchars($student['nisn']); ?></td>
                                                            <td>
                                                                <?php 
                                                                $status = $student['keterangan'] ?? 'Belum Diisi';
                                                                $badge_class = '';
                                                                switch($status) {
                                                                    case 'Hadir':
                                                                        $badge_class = 'badge-success';
                                                                        break;
                                                                    case 'Sakit':
                                                                        $badge_class = 'badge-info';
                                                                        break;
                                                                    case 'Izin':
                                                                        $badge_class = 'badge-warning';
                                                                        break;
                                                                    case 'Alpa':
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
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php else: ?>
                                    <!-- Single class - no tabs needed -->
                                    <?php $kelas = $teacher_classes[0]; ?>
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
                                                <?php 
                                                $students = $class_students[$kelas['id_kelas']] ?? [];
                                                if (empty($students)): ?>
                                                <tr>
                                                    <td colspan="4" class="text-center">Tidak ada siswa dalam kelas ini</td>
                                                </tr>
                                                <?php else: ?>
                                                <?php foreach ($students as $idx => $student): ?>
                                                <tr>
                                                    <td><?php echo $idx + 1; ?></td>
                                                    <td><?php echo htmlspecialchars($student['nama_siswa']); ?></td>
                                                    <td><?php echo htmlspecialchars($student['nisn']); ?></td>
                                                    <td>
                                                        <?php 
                                                        $status = $student['keterangan'] ?? 'Belum Diisi';
                                                        $badge_class = '';
                                                        switch($status) {
                                                            case 'Hadir':
                                                                $badge_class = 'badge-success';
                                                                break;
                                                            case 'Sakit':
                                                                $badge_class = 'badge-info';
                                                                break;
                                                            case 'Izin':
                                                                $badge_class = 'badge-warning';
                                                                break;
                                                            case 'Alpa':
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
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </section>
            </div>
</div>

<?php
?>
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
?>
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
