<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has siswa level
if (!isAuthorized(['siswa'])) {
    redirect('../login.php');
}

// Get student data
$id_siswa = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT s.*, k.nama_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_siswa = ?");
$stmt->execute([$id_siswa]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

$is_grade_6 = false;
if ($student && $student['nama_kelas']) {
    $cls_name = strtoupper($student['nama_kelas']);
    if (strpos($cls_name, '6') !== false || strpos($cls_name, 'VI') !== false) {
        $is_grade_6 = true;
    }
}

if (!$student) {
    echo "Data siswa tidak ditemukan.";
    exit;
}

// Set page title
$page_title = 'Dashboard Siswa';

// Get today's attendance status
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT * FROM tb_absensi WHERE id_siswa = ? AND tanggal = ?");
$stmt->execute([$id_siswa, $today]);
$attendance = $stmt->fetch(PDO::FETCH_ASSOC);

// Get sholat status for female students
$sholat_status = '';
if ($student['jenis_kelamin'] == 'P') {
    $stmt = $pdo->prepare("SELECT status FROM tb_sholat WHERE id_siswa = ? AND tanggal = ?");
    $stmt->execute([$id_siswa, $today]);
    $sholat_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $sholat_status = $sholat_data ? $sholat_data['status'] : '';
}

// Handle Berhalangan (Menstruation) Toggle
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toggle_berhalangan']) && $student['jenis_kelamin'] == 'P') {
    $action = $_POST['toggle_berhalangan']; // 'set' or 'unset'
    
    if ($action == 'set') {
        $new_status = 'Berhalangan';
        $swal_message = [
            'title' => 'Berhasil!',
            'text' => 'Status berhalangan berhasil dicatat.',
            'icon' => 'success'
        ];
    } else {
        // Revert status based on attendance
        $new_status = 'Tidak Melaksanakan'; // Default
        if ($attendance) {
            if (in_array($attendance['keterangan'], ['Hadir', 'Terlambat'])) {
                $new_status = 'Melaksanakan';
            }
        }
        $swal_message = [
            'title' => 'Berhasil!',
            'text' => 'Status berhalangan dibatalkan.',
            'icon' => 'success'
        ];
    }

    // Update tb_sholat
    $stmt = $pdo->prepare("SELECT id_sholat FROM tb_sholat WHERE id_siswa = ? AND tanggal = ?");
    $stmt->execute([$id_siswa, $today]);
    if ($stmt->fetch()) {
        $pdo->prepare("UPDATE tb_sholat SET status = ? WHERE id_siswa = ? AND tanggal = ?")->execute([$new_status, $id_siswa, $today]);
    } else {
        $pdo->prepare("INSERT INTO tb_sholat (id_siswa, tanggal, status) VALUES (?, ?, ?)")->execute([$id_siswa, $today, $new_status]);
    }

    // Update tb_sholat_dhuha
    $stmt = $pdo->prepare("SELECT id_sholat FROM tb_sholat_dhuha WHERE id_siswa = ? AND tanggal = ?");
    $stmt->execute([$id_siswa, $today]);
    if ($stmt->fetch()) {
        $pdo->prepare("UPDATE tb_sholat_dhuha SET status = ? WHERE id_siswa = ? AND tanggal = ?")->execute([$new_status, $id_siswa, $today]);
    } else {
        $pdo->prepare("INSERT INTO tb_sholat_dhuha (id_siswa, tanggal, status) VALUES (?, ?, ?)")->execute([$id_siswa, $today, $new_status]);
    }
    
    // Refresh status variable
    $sholat_status = $new_status;
}

// Handle Manual Attendance - Simple Click Like Guru
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['absen_status'])) {
        $status = $_POST['absen_status']; // Hadir, Sakit, Izin
        $keterangan = '';
        
        // Only get keterangan if status is Izin
        if ($status === 'Izin') {
            $keterangan = $_POST['keterangan'] ?? '';
        }
        
        $holiday = isSchoolHoliday($pdo, $today);
        if ($holiday['is_holiday']) {
            $swal_message = [
                'title' => 'Hari Libur',
                'text' => 'Absensi ditutup pada hari libur: ' . $holiday['name'],
                'icon' => 'warning'
            ];
        } else {
            // Always allow update (INSERT or UPDATE)
            $jam_masuk = $attendance ? $attendance['jam_masuk'] : date('H:i:s');
            
            if ($attendance) {
                // Update existing attendance
                $stmt = $pdo->prepare("UPDATE tb_absensi SET keterangan = ?, jam_masuk = ? WHERE id_siswa = ? AND tanggal = ?");
                if ($stmt->execute([$status, $jam_masuk, $id_siswa, $today])) {
                    $swal_message = [
                        'title' => 'Berhasil!',
                        'text' => 'Status absensi berhasil diubah!',
                        'icon' => 'success'
                    ];
                    // Refresh attendance data
                    $stmt = $pdo->prepare("SELECT * FROM tb_absensi WHERE id_siswa = ? AND tanggal = ?");
                    $stmt->execute([$id_siswa, $today]);
                    $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $swal_message = [
                        'title' => 'Gagal!',
                        'text' => 'Gagal memperbarui absensi!',
                        'icon' => 'error'
                    ];
                }
            } else {
                // Insert new attendance
                $stmt = $pdo->prepare("INSERT INTO tb_absensi (id_siswa, tanggal, jam_masuk, keterangan) VALUES (?, ?, ?, ?)");
                if ($stmt->execute([$id_siswa, $today, $jam_masuk, $status])) {
                    $swal_message = [
                        'title' => 'Berhasil!',
                        'text' => 'Absensi berhasil disimpan!',
                        'icon' => 'success'
                    ];
                    // Refresh attendance data
                    $stmt = $pdo->prepare("SELECT * FROM tb_absensi WHERE id_siswa = ? AND tanggal = ?");
                    $stmt->execute([$id_siswa, $today]);
                    $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $swal_message = [
                        'title' => 'Gagal!',
                        'text' => 'Gagal menyimpan absensi!',
                        'icon' => 'error'
                    ];
                }
            }
        }
    } elseif (isset($_POST['submit_keterangan'])) {
        // Only update keterangan for Izin status
        $keterangan = $_POST['keterangan'] ?? '';
        if ($attendance && $attendance['keterangan'] === 'Izin') {
            $stmt = $pdo->prepare("UPDATE tb_absensi SET keterangan = ? WHERE id_siswa = ? AND tanggal = ?");
            if ($stmt->execute(['Izin', $id_siswa, $today])) {
                $swal_message = [
                    'title' => 'Berhasil!',
                    'text' => 'Keterangan berhasil disimpan!',
                    'icon' => 'success'
                ];
                $stmt = $pdo->prepare("SELECT * FROM tb_absensi WHERE id_siswa = ? AND tanggal = ?");
                $stmt->execute([$id_siswa, $today]);
                $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
    }
}

include '../templates/header.php';
include_once '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Dashboard Siswa</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="#">Dashboard</a></div>
            </div>
        </div>

        <div class="row d-lg-none">
            <div class="col-12 mb-3">
                <div class="card border-0 shadow-sm" style="border-radius: 16px; overflow: hidden;">
                    <div class="card-body p-3">
                            <div class="d-flex align-items-center mb-3">
                                <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center mr-3" style="width: 48px; height: 48px; font-weight: 600; font-size: 1.25rem;">
                                    <?php echo strtoupper(substr($student['nama_siswa'], 0, 1)); ?>
                                </div>
                                <div>
                                    <div class="text-dark mb-1" style="font-size: 0.95rem;">Hai,</div>
                                    <div class="font-weight-bold" style="font-size: 1.1rem;"><?php echo htmlspecialchars($student['nama_siswa']); ?></div>
                                    <div class="text-dark" style="font-size: 0.95rem;">Selamat datang di Sistem Informasi Madrasah</div>
                                </div>
                            </div>
                        <?php
                        $hero_image = !empty($school_profile['dashboard_hero_image'])
                            ? '../assets/img/' . $school_profile['dashboard_hero_image']
                            : '../assets/img/unsplash/eberhard-grossgasteiger-1207565-unsplash.jpg';
                        ?>
                        <div class="rounded-lg overflow-hidden">
                            <img src="<?php echo $hero_image; ?>" alt="Hero" class="img-fluid" style="width: 100%; height: 170px; object-fit: cover;">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Box Identitas Siswa -->
            <div class="col-lg-6 col-md-12 col-12 col-sm-12 mb-4">
                <div class="card card-primary h-100">
                    <div class="card-header py-3">
                        <h4 class="mb-0"><i class="fas fa-user-graduate mr-2"></i>Identitas Siswa</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4 text-center mb-3 mb-md-0">
                                <img alt="image" src="../assets/img/avatar/avatar-1.png" class="rounded-circle profile-widget-picture shadow" style="width: 120px; height: 120px; object-fit: cover; border: 4px solid #6777ef;">
                                <div class="mt-2">
                                    <span class="badge badge-<?php echo $student['jenis_kelamin'] == 'L' ? 'info' : 'danger'; ?> px-3 py-2">
                                        <i class="fas fa-<?php echo $student['jenis_kelamin'] == 'L' ? 'mars' : 'venus'; ?> mr-1"></i>
                                        <?php echo $student['jenis_kelamin'] == 'L' ? 'Laki-laki' : 'Perempuan'; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <h5 class="font-weight-bold text-primary mb-3"><?php echo htmlspecialchars($student['nama_siswa']); ?></h5>
                                <div class="row mb-2">
                                    <div class="col-5 text-muted"><small><i class="fas fa-id-card mr-2"></i>NISN</small></div>
                                    <div class="col-7"><small class="font-weight-bold"><?php echo htmlspecialchars($student['nisn'] ?? '-'); ?></small></div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-5 text-muted"><small><i class="fas fa-chalkboard mr-2"></i>Kelas</small></div>
                                    <div class="col-7"><small class="font-weight-bold"><span class="badge badge-info px-2 py-1"><?php echo htmlspecialchars($student['nama_kelas'] ?? '-'); ?></span></small></div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-5 text-muted"><small><i class="fas fa-calendar mr-2"></i>Tempat Lahir</small></div>
                                    <div class="col-7"><small class="font-weight-bold"><?php echo htmlspecialchars($student['tempat_lahir'] ?? '-'); ?></small></div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-5 text-muted"><small><i class="fas fa-birthday-cake mr-2"></i>Tanggal Lahir</small></div>
                                    <div class="col-7"><small class="font-weight-bold">
                                        <?php 
                                        echo !empty($student['tanggal_lahir']) ? date('d-m-Y', strtotime($student['tanggal_lahir'])) : '-';
                                        ?>
                                    </small></div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-5 text-muted"><small><i class="fas fa-user-tie mr-2"></i>Wali</small></div>
                                    <div class="col-7"><small class="font-weight-bold"><?php echo htmlspecialchars($student['wali'] ?? '-'); ?></small></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Box Absensi Manual -->
            <div class="col-lg-6 col-md-12 col-12 col-sm-12 mb-4">
                <div class="card card-warning h-100">
                    <div class="card-header py-3">
                        <h4 class="mb-0">Absensi Hari Ini</h4>
                        <div class="card-header-action">
                            <span class="badge badge-primary"><?php echo getCurrentDateIndonesia(); ?></span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-light alert-has-icon shadow-sm border mb-3">
                            <div class="alert-icon text-warning"><i class="far fa-bell"></i></div>
                            <div class="alert-body">
                                <div class="alert-title font-weight-bold">Penting</div>
                                Silakan pilih status kehadiran Anda hari ini. Jika tidak memilih, status otomatis dianggap <b>Alpa</b>.
                            </div>
                        </div>

                        <!-- Status Buttons - Simple Click Like Guru -->
                        <div class="row justify-content-center mb-3">
                            <div class="col-4 mb-2">
                                <form method="POST" action="" class="mb-0">
                                    <button type="submit" name="absen_status" value="Hadir" class="btn btn-<?php echo ($attendance && $attendance['keterangan'] == 'Hadir') ? 'success' : 'outline-success'; ?> btn-block btn-icon-split py-2">
                                        <i class="fas fa-check d-block mb-1" style="font-size: 1.5rem;"></i>
                                        <span class="font-weight-bold">Hadir</span>
                                    </button>
                                </form>
                            </div>
                            <div class="col-4 mb-2">
                                <form method="POST" action="" class="mb-0">
                                    <button type="submit" name="absen_status" value="Sakit" class="btn btn-<?php echo ($attendance && $attendance['keterangan'] == 'Sakit') ? 'warning' : 'outline-warning'; ?> btn-block btn-icon-split py-2">
                                        <i class="fas fa-procedures d-block mb-1" style="font-size: 1.5rem;"></i>
                                        <span class="font-weight-bold">Sakit</span>
                                    </button>
                                </form>
                            </div>
                            <div class="col-4 mb-2">
                                <form method="POST" action="" class="mb-0">
                                    <button type="submit" name="absen_status" value="Izin" class="btn btn-<?php echo ($attendance && $attendance['keterangan'] == 'Izin') ? 'info' : 'outline-info'; ?> btn-block btn-icon-split py-2">
                                        <i class="fas fa-envelope d-block mb-1" style="font-size: 1.5rem;"></i>
                                        <span class="font-weight-bold">Izin</span>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <?php if ($attendance): ?>
                        <div class="text-center">
                            <div class="badge badge-<?php 
                                echo $attendance['keterangan'] == 'Hadir' ? 'success' : 
                                    ($attendance['keterangan'] == 'Sakit' ? 'warning' : 
                                    ($attendance['keterangan'] == 'Izin' ? 'info' : 'danger')); 
                            ?> px-4 py-2" style="font-size: 1.1rem;">
                                <i class="fas fa-<?php 
                                    echo $attendance['keterangan'] == 'Hadir' ? 'check' : 
                                        ($attendance['keterangan'] == 'Sakit' ? 'procedures' : 
                                        ($attendance['keterangan'] == 'Izin' ? 'envelope' : 'times')); 
                                ?> mr-2"></i>
                                Status: <?php echo htmlspecialchars($attendance['keterangan']); ?> pada <?php echo $attendance['jam_masuk']; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($attendance && $attendance['keterangan'] == 'Izin'): ?>
                        <!-- Keterangan for Izin only -->
                        <?php 
                        $show_keterangan_form = empty($attendance['keterangan']);
                        ?>
                        <div class="mt-4">
                            <?php if ($show_keterangan_form): ?>
                            <form method="POST" action="">
                                <div class="form-group">
                                    <label class="font-weight-bold">Keterangan Izin</label>
                                    <textarea name="keterangan" class="form-control" rows="3" placeholder="Masukkan keterangan izin..." required></textarea>
                                </div>
                                <button type="submit" name="submit_keterangan" class="btn btn-info btn-block">
                                    <i class="fas fa-save mr-2"></i> Simpan Keterangan
                                </button>
                            </form>
                            <?php else: ?>
                            <div class="form-group">
                                <label class="font-weight-bold">Keterangan Izin</label>
                                <div class="alert alert-light border shadow-sm mb-2">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <i class="fas fa-info-circle text-info mr-2"></i>
                                            <span><?php echo htmlspecialchars($attendance['keterangan']); ?></span>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="editKeteranganSiswa()">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($student['jenis_kelamin'] == 'P'): ?>
            <div class="col-12 mb-4">
                <div class="card card-danger">
                    <div class="card-header py-3">
                        <h4 class="mb-0"><i class="fas fa-female mr-2"></i>Laporan Berhalangan (Haid)</h4>
                    </div>
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <?php if ($sholat_status == 'Berhalangan'): ?>
                                    <div class="alert alert-info mb-0">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-info-circle fa-2x mr-3"></i>
                                            <div>
                                                <h6 class="font-weight-bold mb-1">Status Saat Ini</h6>
                                                <p class="mb-0">Anda sedang <b>Berhalangan</b>. Sistem akan otomatis mencatat ketidakhadiran sholat berjamaah.</p>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-light border mb-0">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-check-circle fa-2x text-success mr-3"></i>
                                            <div>
                                                <h6 class="font-weight-bold mb-1">Status Saat Ini</h6>
                                                <p class="mb-0">Anda <b>Tidak Berhalangan</b>. Silakan lapor jika sedang berhalangan.</p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4 text-center">
                                <?php if ($sholat_status == 'Berhalangan'): ?>
                                    <form method="POST" action="">
                                        <button type="submit" name="toggle_berhalangan" value="unset" class="btn btn-outline-danger btn-lg btn-block">
                                            <i class="fas fa-check-circle mr-2"></i> Sudah Suci / Batalkan
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" action="">
                                        <button type="submit" name="toggle_berhalangan" value="set" class="btn btn-danger btn-lg btn-block">
                                            <i class="fas fa-female mr-2"></i> Lapor Berhalangan
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($is_grade_6): ?>
        <div class="row">
            <!-- Box Khusus Kelas 6 -->
            <div class="col-12">
                <div class="card card-info">
                    <div class="card-header">
                        <h4>Fitur Khusus Kelas 6</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 col-sm-6 col-12 mb-3">
                                <a href="jadwal_les.php" class="btn btn-primary btn-lg btn-block py-3">
                                    <i class="fas fa-calendar-alt mr-2"></i> Jadwal Les Kelas 6
                                </a>
                            </div>
                            <div class="col-md-6 col-sm-6 col-12 mb-3">
                                <a href="rekap_absensi_les.php" class="btn btn-success btn-lg btn-block py-3">
                                    <i class="fas fa-history mr-2"></i> Rekap Absensi Les
                                </a>
                            </div>
                            <div class="col-md-6 col-sm-6 col-12 mb-3">
                                <a href="biaya_ujian.php" class="btn btn-warning btn-lg btn-block py-3 text-white">
                                    <i class="fas fa-money-bill-wave mr-2"></i> Biaya Ujian
                                </a>
                            </div>
                            <div class="col-md-6 col-sm-6 col-12 mb-3">
                                <a href="nilai_ujian.php" class="btn btn-info btn-lg btn-block py-3">
                                    <i class="fas fa-graduation-cap mr-2"></i> Nilai Ujian
                                </a>
                            </div>
                            <div class="col-md-6 col-sm-6 col-12 mb-3">
                                <a href="nilai_ujian.php?nilai_mode=praktik" class="btn btn-outline-info btn-lg btn-block py-3">
                                    <i class="fas fa-flask mr-2"></i> Nilai Ujian Praktik
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- Box Barcode Absensi -->
            <div class="col-12">
                <div class="card card-info">
                    <div class="card-header d-flex flex-column align-items-center flex-md-row justify-content-md-between py-3 h-auto">
                        <h4 class="mb-0">Barcode Absensi</h4>
                    </div>
                    <div class="card-body text-center">
                        <p>Gunakan barcode ini untuk absensi di perangkat sekolah (jika tersedia).</p>
                        <div class="barcode-container mb-3">
                            <!-- Generate QR Code using Google Charts API or similar -->
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo $student['nisn']; ?>" alt="QR Code NISN" class="img-fluid border p-2">
                        </div>
                        <div class="font-weight-bold text-xl"><?php echo htmlspecialchars($student['nisn']); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mobile Menu Utama - Last Position -->
        <div class="row d-lg-none mt-4">
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
                                                    <i class="<?php echo $item['icon']; ?> text-success" style="font-size: 1.4rem;"></i>
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
                                                        <i class="<?php echo $group['icon']; ?> text-success" style="font-size: 1.4rem;"></i>
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
    </section>
</div>

<?php
include '../templates/footer.php';

if (isset($swal_message)): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            title: '<?php echo $swal_message['title']; ?>',
            text: '<?php echo $swal_message['text']; ?>',
            icon: '<?php echo $swal_message['icon']; ?>',
            timer: 3000,
            timerProgressBar: true,
            showConfirmButton: false
        });
    });
</script>
<?php endif; ?>

<script>
// Function to edit keterangan for Izin status
function editKeteranganSiswa() {
    // Simple prompt for editing
    const currentKeterangan = '<?php echo addslashes($attendance['keterangan'] ?? ''); ?>';
    const newKeterangan = prompt('Masukkan keterangan izin:', currentKeterangan);
    
    if (newKeterangan !== null && newKeterangan.trim() !== '') {
        // Create a temporary form and submit
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'keterangan';
        input.value = newKeterangan.trim();
        form.appendChild(input);
        
        const submitBtn = document.createElement('input');
        submitBtn.type = 'hidden';
        submitBtn.name = 'submit_keterangan';
        submitBtn.value = '1';
        form.appendChild(submitBtn);
        
        document.body.appendChild(form);
        form.submit();
    }
}
</script>
