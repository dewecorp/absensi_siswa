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
$hadir_count = 0;
$total_marked_count = 0;
foreach ($class_students as $class_id => $students) {
    foreach ($students as $student) {
        if (isset($student['keterangan']) && !empty($student['keterangan'])) {
            $total_marked_count++;
            if ($student['keterangan'] == 'Hadir') {
                $hadir_count++;
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
    $persentase_hadir = round(($hadir_count / $total_marked_count) * 100, 1);
}

// Initialize counts (kept for backward compatibility if used elsewhere, though seemingly unused now)
$jumlah_hadir = $hadir_count; 
$jumlah_sakit = $jumlah_izin = $jumlah_alpa = 0; // simplified

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
$js_page = [];

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
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
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
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-success">
                                    <i class="fas fa-chalkboard-teacher"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Nama Guru</h4>
                                    </div>
                                    <div class="card-body" style="font-size: 0.9rem; line-height: 1.4;">
                                        <?php 
                                        if ($teacher) {
                                            // Display teacher name - ensure it's displayed on one line
                                            $nama_guru = trim($teacher['nama_guru']);
                                            // Clean up any extra spaces
                                            $nama_guru = preg_replace('/\s+/', ' ', $nama_guru);
                                            echo htmlspecialchars($nama_guru);
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
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
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
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
include '../templates/user_footer.php';
?>