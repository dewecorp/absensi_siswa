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
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_siswa FROM tb_siswa WHERE id_kelas = ?");
    $stmt->execute([$wali_kelas['id_kelas']]);
    $total_siswa = $stmt->fetch(PDO::FETCH_ASSOC)['total_siswa'];
} else {
    $total_siswa = 0;
}

// Get today's attendance statistics for the wali's class
if ($wali_kelas) {
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT a.keterangan, COUNT(*) as jumlah FROM tb_absensi a JOIN tb_siswa s ON a.id_siswa = s.id_siswa WHERE s.id_kelas = ? AND a.tanggal = ? GROUP BY a.keterangan");
    $stmt->execute([$wali_kelas['id_kelas'], $today]);
    $attendance_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $attendance_stats = [];
}

// Initialize counts
$jumlah_hadir = $jumlah_sakit = $jumlah_izin = $jumlah_alpa = 0;
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
    }
}

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
        
        // Check if already attended
        $check_stmt = $pdo->prepare("SELECT id_absensi FROM tb_absensi_guru WHERE id_guru = ? AND tanggal = ?");
        $check_stmt->execute([$current_teacher_id, $current_date]);
        
        if ($check_stmt->rowCount() > 0) {
            // Update existing
             $update_stmt = $pdo->prepare("UPDATE tb_absensi_guru SET status = ?, keterangan = ? WHERE id_guru = ? AND tanggal = ?");
             if ($update_stmt->execute([$attendance_status, $attendance_note, $current_teacher_id, $current_date])) {
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
                 $nama_guru = $_SESSION['nama_guru'] ?? 'Wali Kelas';
                 $waktu = date('H:i');
                 $tanggal_indo = date('d-m-Y');
                 $notif_msg = "$nama_guru (Wali) telah mengirim kehadiran pada pukul $waktu tanggal $tanggal_indo";
                 createNotification($pdo, $notif_msg, 'absensi_guru.php', 'absensi_guru');

                 // Log activity
                 $log_desc = "$nama_guru (Wali) memperbarui kehadiran: $attendance_status";
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
    // Wait for DOM to be fully loaded
    document.addEventListener('DOMContentLoaded', function() {
        // Small delay to ensure Chart.js is ready
        setTimeout(function() {
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
                    Chart.defaults.font.size = 12;
                    Chart.defaults.color = '#999';
                } else if (typeof Chart.defaults.global !== 'undefined') {
                    // Chart.js v2.x fallback
                    Chart.defaults.global.defaultFontFamily = 'Nunito, Segoe UI, Arial';
                    Chart.defaults.global.defaultFontSize = 12;
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
                            labels: ['Hadir', 'Sakit', 'Izin', 'Alpa'],
                            datasets: [{
                                label: 'Jumlah Siswa',
                                data: [
                                    " . $jumlah_hadir . ",
                                    " . $jumlah_sakit . ",
                                    " . $jumlah_izin . ",
                                    " . $jumlah_alpa . "
                                ],
                                backgroundColor: [
                                    'rgba(54, 162, 235, 0.2)',
                                    'rgba(255, 99, 132, 0.2)',
                                    'rgba(255, 206, 86, 0.2)',
                                    'rgba(153, 102, 255, 0.2)'
                                ],
                                borderColor: [
                                    'rgba(54, 162, 235, 1)',
                                    'rgba(255,99,132,1)',
                                    'rgba(255, 206, 86, 1)',
                                    'rgba(153, 102, 255, 1)'
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
        }, 500);
    });
    "
];

include '../templates/user_header.php';

include '../templates/sidebar.php';

// Start HTML output after including templates
?>

<!-- Main Content -->
<div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Dashboard Wali Kelas</h1>
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
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-primary">
                                    <i class="fas fa-chalkboard"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Total Kelas Ajar</h4>
                                    </div>
                                    <div class="card-body">
                                        <?php echo $total_kelas_ajar; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-success">
                                    <i class="fas fa-users"></i>
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
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-success">
                                    <i class="fas fa-check-circle"></i>
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
                    </div>
                    
                    <div class="row">
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
                                    <i class="fas fa-times-circle"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Alpa</h4>
                                    </div>
                                    <div class="card-body">
                                        <?php echo $jumlah_alpa; ?>
                                    </div>
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
                                                    <input type="radio" name="attendance_status" value="hadir" class="selectgroup-input" <?php echo ($today_attendance && $today_attendance['status'] == 'hadir') ? 'checked' : ''; ?> required>
                                                    <span class="selectgroup-button selectgroup-button-icon"><i class="fas fa-check"></i> Hadir</span>
                                                </label>
                                                <label class="selectgroup-item">
                                                    <input type="radio" name="attendance_status" value="sakit" class="selectgroup-input" <?php echo ($today_attendance && $today_attendance['status'] == 'sakit') ? 'checked' : ''; ?>>
                                                    <span class="selectgroup-button selectgroup-button-icon"><i class="fas fa-procedures"></i> Sakit</span>
                                                </label>
                                                <label class="selectgroup-item">
                                                    <input type="radio" name="attendance_status" value="izin" class="selectgroup-input" id="radio_izin" <?php echo ($today_attendance && $today_attendance['status'] == 'izin') ? 'checked' : ''; ?>>
                                                    <span class="selectgroup-button selectgroup-button-icon"><i class="fas fa-paper-plane"></i> Izin</span>
                                                </label>
                                            </div>
                                        </div>

                                        <div class="form-group" id="keterangan_box" style="display: <?php echo ($today_attendance && in_array($today_attendance['status'], ['izin', 'sakit'])) ? 'block' : 'none'; ?>;">
                                            <label>Keterangan</label>
                                            <textarea name="attendance_note" class="form-control" placeholder="Masukkan keterangan..."><?php echo $today_attendance ? htmlspecialchars($today_attendance['keterangan']) : ''; ?></textarea>
                                        </div>

                                        <div class="form-group">
                                            <button type="submit" name="submit_attendance" class="btn btn-primary btn-lg btn-icon icon-left"><i class="fas fa-save"></i> Simpan Absensi</button>
                                            <a href="jurnal_mengajar.php" class="btn btn-info btn-lg btn-icon icon-left ml-2"><i class="fas fa-book-open"></i> Isi Jurnal Mengajar</a>
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
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Grafik Kehadiran Siswa Hari Ini</h4>
                                </div>
                                <div class="card-body">
                                    <canvas id="myChart" height="158"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Student List Section -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Data Siswa Kelas <?php echo $wali_kelas ? htmlspecialchars($wali_kelas['nama_kelas'] ?? 'Tidak Ada Kelas') : 'Tidak Ada Kelas'; ?></h4>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-striped" id="table-siswa">
                                            <thead>
                                                <tr>
                                                    <th>No</th>
                                                    <th>Nama Siswa</th>
                                                    <th>NISN</th>
                                                    <th>Jenis Kelamin</th>
                                                    <th>Status Hari Ini</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                if ($wali_kelas) {
                                                    $siswa_stmt = $pdo->prepare("SELECT s.*, a.keterangan as attendance_status FROM tb_siswa s LEFT JOIN tb_absensi a ON s.id_siswa = a.id_siswa AND a.tanggal = CURDATE() WHERE s.id_kelas = ? ORDER BY s.nama_siswa ASC");
                                                    $siswa_stmt->execute([$wali_kelas['id_kelas']]);
                                                    $siswa_list = $siswa_stmt->fetchAll(PDO::FETCH_ASSOC);
                                                    
                                                    $no = 1;
                                                    foreach ($siswa_list as $siswa):
                                                ?>
                                                <tr>
                                                    <td><?php echo $no++; ?></td>
                                                    <td><?php echo htmlspecialchars($siswa['nama_siswa']); ?></td>
                                                    <td><?php echo htmlspecialchars($siswa['nisn']); ?></td>
                                                    <td><?php echo $siswa['jenis_kelamin'] == 'L' ? 'Laki-laki' : ($siswa['jenis_kelamin'] == 'P' ? 'Perempuan' : '-'); ?></td>
                                                    <td>
                                                        <div class="badge badge-<?php 
                                                            switch(strtolower($siswa['attendance_status'] ?? 'Belum Absen')) {
                                                                case 'hadir': echo 'success'; break;
                                                                case 'sakit': echo 'warning'; break;
                                                                case 'izin': echo 'info'; break;
                                                                case 'alpa': echo 'danger'; break;
                                                                default: echo 'secondary'; break;
                                                            }
                                                        ?>"><?php echo htmlspecialchars($siswa['attendance_status'] ?? 'Belum Absen'); ?></div>
                                                    </td>
                                                </tr>
                                                <?php 
                                                    endforeach;
                                                } else {
                                                ?>
                                                <tr>
                                                    <td colspan="5" class="text-center">Tidak ada kelas yang diajar</td>
                                                </tr>
                                                <?php } ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
            
    <?php 
    // Add DataTables initialization for table-siswa
    if (!isset($js_page)) {
        $js_page = [];
    }
    $js_page[] = "
    $(document).ready(function() {
        if ($.fn.DataTable && $('#table-siswa').length > 0) {
            $('#table-siswa').DataTable({
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
    include '../templates/user_footer.php'; 
    ?>