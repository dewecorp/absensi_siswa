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

$tagihan_summary_response = !empty($student['nisn']) ? fetchSibayarData((string)$student['nisn'], 'tagihan') : ['status' => 'error'];
$tagihan_summary = is_array($tagihan_summary_response['summary'] ?? null) ? $tagihan_summary_response['summary'] : [];
$tagihan_total_aktif = (int)($tagihan_summary['total_tunggakan_aktif'] ?? 0);
$tagihan_total_lama = (int)($tagihan_summary['total_tunggakan_tahun_lama'] ?? 0);
$tagihan_total_semua = (int)($tagihan_summary['total_tunggakan'] ?? ($tagihan_total_aktif + $tagihan_total_lama));
$tagihan_tahun_aktif = (string)($tagihan_summary_response['tahun_ajaran'] ?? $tagihan_summary_response['tahun_ajaran_aktif'] ?? '-');
$tagihan_tahun_lama = $tagihan_summary['tahun_ajaran_tunggakan'] ?? [];
$tagihan_tahun_lama = is_array($tagihan_tahun_lama) ? $tagihan_tahun_lama : [];
$tagihan_status_ok = ($tagihan_summary_response['status'] ?? 'error') === 'success';

// Get today's attendance status
$today = date('Y-m-d');
$holiday = isSchoolHoliday($pdo, $today);
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
                'text' => 'Kehadiran ditutup pada hari libur: ' . $holiday['name'],
                'icon' => 'warning'
            ];
        } else {
            // Always allow update (INSERT or UPDATE)
            $jam_masuk = $attendance ? $attendance['jam_masuk'] : date('H:i:s');
            
            // Validasi keterlambatan: kehadiran dari dashboard siswa yang melebihi 07:15 dinyatakan terlambat
            if ($status === 'Hadir') {
                $jam_cek = $jam_masuk ? strtotime($jam_masuk) : time();
                if ($jam_cek > strtotime('07:15:00')) {
                    $status = 'Terlambat';
                }
            }
            if (in_array($status, ['Hadir', 'Terlambat']) && empty($jam_masuk)) {
                $jam_masuk = date('H:i:s');
            }
            
            if ($attendance) {
                // Update existing attendance
                $stmt = $pdo->prepare("UPDATE tb_absensi SET keterangan = ?, jam_masuk = ? WHERE id_siswa = ? AND tanggal = ?");
                if ($stmt->execute([$status, $jam_masuk, $id_siswa, $today])) {
                    $swal_message = [
                        'title' => ($status === 'Terlambat') ? 'Maaf, Anda Terlambat!' : 'Berhasil!',
                        'text' => ($status === 'Terlambat') ? 'Maaf anda terlambat. Status kehadiran berhasil diubah!' : 'Status kehadiran berhasil diubah!',
                        'icon' => 'success'
                    ];
                    // Refresh attendance data
                    $stmt = $pdo->prepare("SELECT * FROM tb_absensi WHERE id_siswa = ? AND tanggal = ?");
                    $stmt->execute([$id_siswa, $today]);
                    $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $swal_message = [
                        'title' => 'Gagal!',
                        'text' => 'Gagal memperbarui kehadiran!',
                        'icon' => 'error'
                    ];
                }
            } else {
                // Insert new attendance
                $stmt = $pdo->prepare("INSERT INTO tb_absensi (id_siswa, tanggal, jam_masuk, keterangan) VALUES (?, ?, ?, ?)");
                if ($stmt->execute([$id_siswa, $today, $jam_masuk, $status])) {
                    $swal_message = [
                        'title' => ($status === 'Terlambat') ? 'Maaf, Anda Terlambat!' : 'Berhasil!',
                        'text' => ($status === 'Terlambat') ? 'Maaf anda terlambat. Kehadiran berhasil disimpan!' : 'Kehadiran berhasil disimpan!',
                        'icon' => 'success'
                    ];
                    // Refresh attendance data
                    $stmt = $pdo->prepare("SELECT * FROM tb_absensi WHERE id_siswa = ? AND tanggal = ?");
                    $stmt->execute([$id_siswa, $today]);
                    $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $swal_message = [
                        'title' => 'Gagal!',
                        'text' => 'Gagal menyimpan kehadiran!',
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
                                <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center mr-3 flex-shrink-0" style="width: 48px; height: 48px; font-weight: 600; font-size: 1.25rem; box-shadow: 0 4px 12px rgba(37, 99, 235, .45);">
                                    <?php echo strtoupper(substr($student['nama_siswa'], 0, 1)); ?>
                                </div>
                                <div>
                                    <div class="text-dark mb-1" style="font-size: 0.95rem;">Assalamualaikum, <span class="font-weight-bold" style="font-size: 1.1rem;"><?php echo htmlspecialchars($student['nama_siswa']); ?></span></div>
                                    <div class="text-dark" style="font-size: 0.95rem;">Selamat datang di Sistem Informasi Madrasah</div>
                                    <div class="mt-1">
                                        <span class="wb-chip wb-chip-default"><i class="far fa-calendar-alt"></i> <span id="wb-date">-</span></span>
                                        <span class="wb-chip wb-chip-default"><i class="far fa-clock"></i> <span id="wb-time">--:--:--</span></span>
                                        <span class="wb-chip wb-chip-default"><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($school_profile['tahun_ajaran'] ?? '-'); ?></span>
                                        <span class="wb-chip wb-chip-default"><i class="fas fa-calendar-check"></i> <?php echo htmlspecialchars($school_profile['semester'] ?? '-'); ?></span>
                                    </div>
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
                <div class="card card-primary">
                    <div class="card-header py-3">
                        <h4 class="mb-0"><i class="fas fa-user-graduate mr-2"></i>Identitas Siswa</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4 text-center mb-3 mb-md-0">
                                <div class="position-relative d-inline-block">
                                    <?php 
                                    $foto_profil = !empty($student['foto']) && file_exists('../assets/img/siswa/' . $student['foto']) 
                                        ? '../assets/img/siswa/' . $student['foto'] 
                                        : null;
                                    ?>
                                    <?php if ($foto_profil): ?>
                                        <img alt="image" src="<?php echo $foto_profil; ?>" class="rounded-circle profile-widget-picture shadow" style="width: 120px; height: 120px; object-fit: cover; border: 4px solid #6777ef;">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center shadow" style="width: 120px; height: 120px; font-weight: 700; font-size: 3rem; border: 4px solid #fff;">
                                            <?php echo strtoupper(substr($student['nama_siswa'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-primary btn-sm position-absolute" style="bottom: 5px; right: 5px; border-radius: 50%; width: 35px; height: 35px; padding: 0;" data-toggle="modal" data-target="#modalUploadFoto">
                                        <i class="fas fa-camera"></i>
                                    </button>
                                </div>
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

            <!-- Box Kehadiran Manual -->
            <div class="col-lg-6 col-md-12 col-12 col-sm-12 mb-4">
                <div class="card card-warning">
                    <div class="card-header py-3">
                        <h4 class="mb-0">Kehadiran Hari Ini</h4>
                        <div class="card-header-action">
                            <span class="badge badge-primary"><?php echo getCurrentDateIndonesia(); ?></span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!$holiday['is_holiday']): ?>
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
                                    <?php $siswa_sudah_hadir = ($attendance && in_array($attendance['keterangan'], ['Hadir', 'Terlambat'])); ?>
                                    <button type="submit" name="absen_status" value="Hadir" class="btn btn-success btn-block py-2" style="<?php echo $siswa_sudah_hadir ? 'cursor: not-allowed;' : 'opacity: 0.45;'; ?>" onclick="return <?php echo $siswa_sudah_hadir ? 'false' : 'true'; ?>;">
                                        <span class="font-weight-bold" style="font-size: 0.8rem;"><i class="fas fa-check mr-1" style="font-size:0.75rem;"></i>Hadir</span>
                                    </button>
                                </form>
                            </div>
                            <div class="col-4 mb-2">
                                <form method="POST" action="" class="mb-0">
                                    <button type="submit" name="absen_status" value="Sakit" class="btn btn-warning btn-block py-2" style="<?php echo ($attendance && $attendance['keterangan'] == 'Sakit') ? 'cursor: not-allowed;' : 'opacity: 0.45;'; ?>" onclick="return <?php echo ($attendance && $attendance['keterangan'] == 'Sakit') ? 'false' : 'true'; ?>;">
                                        <span class="font-weight-bold" style="font-size: 0.8rem;"><i class="fas fa-procedures mr-1" style="font-size:0.75rem;"></i>Sakit</span>
                                    </button>
                                </form>
                            </div>
                            <div class="col-4 mb-2">
                                <form method="POST" action="" class="mb-0">
                                    <button type="submit" name="absen_status" value="Izin" class="btn btn-info btn-block py-2" style="<?php echo ($attendance && $attendance['keterangan'] == 'Izin') ? 'cursor: not-allowed;' : 'opacity: 0.45;'; ?>" onclick="return <?php echo ($attendance && $attendance['keterangan'] == 'Izin') ? 'false' : 'true'; ?>;">
                                        <span class="font-weight-bold" style="font-size: 0.8rem;"><i class="fas fa-envelope mr-1" style="font-size:0.75rem;"></i>Izin</span>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <?php if ($attendance && in_array($attendance['keterangan'], ['Hadir', 'Terlambat'])): ?>
                        <div class="text-center mb-3">
                            <span class="badge badge-<?= $attendance['keterangan'] === 'Terlambat' ? 'warning' : 'success'; ?> px-4 py-2">
                                <i class="fas fa-<?= $attendance['keterangan'] === 'Terlambat' ? 'clock' : 'check-circle'; ?> mr-2"></i>
                                <?= $attendance['keterangan'] === 'Terlambat' ? 'Terlambat' : 'Tepat Waktu'; ?>
                            </span>
                        </div>
                        <?php endif; ?>

                        <?php if ($attendance): ?>
                        <div class="text-center">
                            <div class="badge badge-<?php 
                                echo $attendance['keterangan'] == 'Hadir' ? 'success' : 
                                    ($attendance['keterangan'] == 'Terlambat' ? 'warning' : 
                                    ($attendance['keterangan'] == 'Sakit' ? 'warning' : 
                                    ($attendance['keterangan'] == 'Izin' ? 'info' : 'danger'))); 
                            ?> px-4 py-2" style="font-size: 1.1rem;">
                                <i class="fas fa-<?php 
                                    echo $attendance['keterangan'] == 'Hadir' ? 'check' : 
                                        ($attendance['keterangan'] == 'Terlambat' ? 'clock' : 
                                        ($attendance['keterangan'] == 'Sakit' ? 'procedures' : 
                                        ($attendance['keterangan'] == 'Izin' ? 'envelope' : 'times'))); 
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
                        <?php else: ?>
                            <div class="d-flex align-items-center justify-content-center text-center py-2">
                                <div>
                                    <div class="mb-3">
                                        <i class="fas fa-umbrella-beach text-warning" style="font-size: 60px;"></i>
                                    </div>
                                    <h5 class="font-weight-bold mb-1">Hari Libur Sekolah</h5>
                                    <p class="text-muted mb-2">Hari ini, <strong><?php echo formatDateIndonesia(date('Y-m-d')); ?></strong> adalah <strong><?php echo $holiday['name']; ?></strong>.</p>
                                    <div class="badge badge-warning px-3 py-1" style="font-size: 0.9rem; border-radius: 30px;">
                                        <i class="fas fa-info-circle mr-2"></i> Kehadiran Harian Ditutup
                                    </div>
                                </div>
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

        <div class="row">
            <div class="col-12 mb-4">
                <div class="card card-warning">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h4 class="mb-0"><i class="fas fa-file-invoice-dollar mr-2"></i>Ringkasan Tagihan Sibayar</h4>
                        <a href="tagihan_siswa.php" class="btn btn-sm btn-warning text-white">
                            Detail Tagihan
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if ($tagihan_status_ok): ?>
                            <div class="row">
                                <div class="col-md-4 col-12 mb-3 mb-md-0">
                                    <div class="border rounded bg-light p-3 h-100">
                                        <div class="small text-muted">Total Tunggakan</div>
                                        <div class="h5 mb-0 <?php echo $tagihan_total_semua > 0 ? 'text-danger' : 'text-success'; ?> font-weight-bold">
                                            Rp <?php echo number_format($tagihan_total_semua, 0, ',', '.'); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-4 col-md-4 mb-2">
                                    <div class="border rounded p-3 h-100">
                                        <div class="small text-muted">Tahun Ajaran Aktif</div>
                                        <div class="font-weight-bold"><?php echo htmlspecialchars($tagihan_tahun_aktif); ?></div>
                                        <div class="text-danger font-weight-bold">Rp <?php echo number_format($tagihan_total_aktif, 0, ',', '.'); ?></div>
                                    </div>
                                </div>
                                <div class="col-4 col-md-4 mb-2">
                                    <div class="border rounded p-3 h-100">
                                        <div class="small text-muted">Tahun Ajaran Lama</div>
                                        <div class="font-weight-bold"><?php echo empty($tagihan_tahun_lama) ? '-' : htmlspecialchars(implode(', ', $tagihan_tahun_lama)); ?></div>
                                        <div class="text-danger font-weight-bold">Rp <?php echo number_format($tagihan_total_lama, 0, ',', '.'); ?></div>
                                    </div>
                                </div>
                            </div>
                            <?php if ($tagihan_total_semua <= 0): ?>
                                <div class="alert alert-success mb-0 mt-3">
                                    <i class="fas fa-check-circle mr-2"></i> Tidak ada tagihan yang belum dibayar menurut data Sibayar.
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-warning mb-0">
                                <i class="fas fa-plug mr-2"></i> Ringkasan tagihan belum bisa diambil dari Sibayar. Buka detail tagihan untuk mencoba lagi.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
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
                                    <i class="fas fa-history mr-2"></i> Rekap Kehadiran Les
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
            <!-- Box Barcode Kehadiran -->
            <div class="col-12">
                <div class="card card-info">
                    <div class="card-header d-flex flex-column align-items-center flex-md-row justify-content-md-between py-3 h-auto">
                        <h4 class="mb-0">Barcode Kehadiran</h4>
                    </div>
                    <div class="card-body text-center">
                        <p>Gunakan barcode ini untuk kehadiran di perangkat sekolah (jika tersedia).</p>
                        <div class="barcode-container mb-3">
                            <!-- Generate QR Code using Google Charts API or similar -->
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo $student['nisn']; ?>" alt="QR Code NISN" class="img-fluid border p-2">
                        </div>
                        <div class="font-weight-bold text-xl"><?php echo htmlspecialchars($student['nisn']); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php echo renderDashboardAgendaBulanBerjalan($pdo); ?>

        <!-- Mobile Menu Utama - Last Position -->
        <div class="row d-lg-none mt-4">
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
                            <?php if (!empty($single_items)): ?>
                                <div class="row">
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
                                </div>
                            <?php endif; ?>
                            <?php foreach ($grouped_items as $group): ?>
                                <?php $group_anchor_id = 'menu-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', trim((string)$group['title'])); ?>
                                <div class="mt-3" id="<?php echo htmlspecialchars($group_anchor_id, ENT_QUOTES, 'UTF-8'); ?>" style="scroll-margin-top: 90px;">
                                    <div class="small text-muted font-weight-bold mb-2"><?php echo $group['title']; ?></div>
                                    <div class="row">
                                        <?php foreach ($group['items'] as $mg_idx => $subitem): ?>
                                            <div class="col-3 mb-3">
                                                <a href="<?php echo $subitem['url']; ?>" class="text-decoration-none text-center d-block">
                                                    <div class="mx-auto mb-2 menu-grid-icon mg-c<?php echo ($mg_idx % 8) + 1; ?>">
                                                        <i class="<?php echo $group['icon']; ?>"></i>
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
    </section>
</div>

<!-- Modal Upload Foto -->
<div class="modal fade" id="modalUploadFoto" tabindex="-1" role="dialog" aria-labelledby="modalUploadFotoLabel" aria-hidden="true">
    <div class="modal-dialog" role="dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalUploadFotoLabel">Ubah Foto Profil</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="formUploadFoto" enctype="multipart/form-data">
                <div class="modal-body text-center">
                    <div class="mb-3">
                        <div id="containerPreview">
                            <?php if ($foto_profil): ?>
                                <img id="previewFoto" src="<?php echo $foto_profil; ?>" class="rounded-circle shadow" style="width: 150px; height: 150px; object-fit: cover; border: 4px solid #6777ef;">
                            <?php else: ?>
                                <div id="previewAvatar" class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center shadow mx-auto" style="width: 150px; height: 150px; font-weight: 700; font-size: 4rem; border: 4px solid #fff;">
                                    <?php echo strtoupper(substr($student['nama_siswa'], 0, 1)); ?>
                                </div>
                                <img id="previewFoto" src="" class="rounded-circle shadow d-none" style="width: 150px; height: 150px; object-fit: cover; border: 4px solid #6777ef;">
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-group text-left">
                        <label>Pilih Foto Baru</label>
                        <input type="file" name="foto" id="inputFoto" class="form-control" accept="image/*" required>
                        <small class="text-muted">Format: JPG, JPEG, PNG. Maksimal 2MB.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="btnSimpanFoto">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
include '../templates/footer.php';
?>

<script>
 // Preview foto sebelum upload
 document.getElementById('inputFoto').addEventListener('change', function(e) {
     const file = e.target.files[0];
     if (file) {
         const reader = new FileReader();
         reader.onload = function(e) {
             const previewImg = document.getElementById('previewFoto');
             const previewAvatar = document.getElementById('previewAvatar');
             
             previewImg.src = e.target.result;
             previewImg.classList.remove('d-none');
             
             if (previewAvatar) {
                 previewAvatar.classList.add('d-none');
             }
         }
         reader.readAsDataURL(file);
     }
 });

// Handle upload foto via AJAX
document.getElementById('formUploadFoto').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const btn = document.getElementById('btnSimpanFoto');
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Menyimpan...';
    
    fetch('upload_foto.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            Swal.fire({
                title: 'Berhasil!',
                text: data.message,
                icon: 'success',
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire('Gagal!', data.message, 'error');
            btn.disabled = false;
            btn.innerHTML = 'Simpan Perubahan';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error!', 'Terjadi kesalahan sistem.', 'error');
        btn.disabled = false;
        btn.innerHTML = 'Simpan Perubahan';
    });
});
</script>

<?php
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
