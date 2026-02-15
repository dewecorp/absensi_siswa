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

// Handle Manual Attendance
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['absen_status'])) {
    $status = $_POST['absen_status']; // Hadir, Sakit, Izin
    
    $holiday = isSchoolHoliday($pdo, $today);
    if ($holiday['is_holiday']) {
        $swal_message = [
            'title' => 'Hari Libur',
            'text' => 'Absensi ditutup pada hari libur: ' . $holiday['name'],
            'icon' => 'warning'
        ];
    } else {
        if ($attendance) {
            $swal_message = [
                'title' => 'Peringatan!',
                'text' => 'Anda sudah melakukan absensi hari ini!',
                'icon' => 'warning'
            ];
        } else {
            $jam_masuk = date('H:i:s');
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
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Dashboard Siswa</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="#">Dashboard</a></div>
            </div>
        </div>

        <div class="row">
            <!-- Box Identitas Siswa -->
            <div class="col-lg-6 col-md-12 col-12 col-sm-12">
                <div class="card card-primary">
                    <div class="card-header">
                        <h4>Identitas Siswa</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4 text-center">
                                <img alt="image" src="../assets/img/avatar/avatar-1.png" class="rounded-circle profile-widget-picture" style="width: 100px; height: 100px; object-fit: cover;">
                            </div>
                            <div class="col-md-8">
                                <div class="user-item">
                                    <div class="user-details">
                                        <div class="user-name font-weight-bold"><?php echo htmlspecialchars($student['nama_siswa']); ?></div>
                                        <div class="text-job text-muted"><?php echo htmlspecialchars($student['nisn']); ?></div>
                                        <div class="user-cta">
                                            <p class="mb-0">Kelas: <span class="badge badge-info"><?php echo htmlspecialchars($student['nama_kelas']); ?></span></p>
                                            <p class="mb-0">Jenis Kelamin: <?php echo $student['jenis_kelamin'] == 'L' ? 'Laki-laki' : 'Perempuan'; ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Box Absensi Manual -->
            <div class="col-lg-6 col-md-12 col-12 col-sm-12">
                <div class="card card-warning">
                    <div class="card-header">
                        <h4>Absensi Hari Ini</h4>
                        <div class="card-header-action">
                            <span class="badge badge-primary"><?php echo getCurrentDateIndonesia(); ?></span>
                        </div>
                    </div>
                    <div class="card-body text-center">
                        <?php if ($attendance): ?>
                            <div class="empty-state" data-height="150">
                                <div class="empty-state-icon bg-<?php 
                                    echo $attendance['keterangan'] == 'Hadir' ? 'success' : 
                                        ($attendance['keterangan'] == 'Sakit' ? 'warning' : 
                                        ($attendance['keterangan'] == 'Izin' ? 'info' : 'danger')); 
                                ?>">
                                    <i class="fas fa-<?php 
                                        echo $attendance['keterangan'] == 'Hadir' ? 'check' : 
                                            ($attendance['keterangan'] == 'Sakit' ? 'procedures' : 
                                            ($attendance['keterangan'] == 'Izin' ? 'envelope' : 'times')); 
                                    ?>"></i>
                                </div>
                                <h2><?php echo htmlspecialchars($attendance['keterangan']); ?></h2>
                                <p class="lead">
                                    Anda sudah melakukan absensi pada pukul <?php echo $attendance['jam_masuk']; ?>
                                </p>
                            </div>
                        <?php else: ?>
                            <p class="mb-4">Silakan pilih status kehadiran Anda hari ini:</p>
                            <form method="POST" action="">
                                <div class="row justify-content-center">
                                    <div class="col-4">
                                        <button type="submit" name="absen_status" value="Hadir" class="btn btn-success btn-lg btn-block btn-icon-split p-3" style="height: auto;">
                                            <i class="fas fa-check fa-2x d-block mb-2"></i> Hadir
                                        </button>
                                    </div>
                                    <div class="col-4">
                                        <button type="submit" name="absen_status" value="Sakit" class="btn btn-warning btn-lg btn-block btn-icon-split p-3" style="height: auto;">
                                            <i class="fas fa-procedures fa-2x d-block mb-2"></i> Sakit
                                        </button>
                                    </div>
                                    <div class="col-4">
                                        <button type="submit" name="absen_status" value="Izin" class="btn btn-info btn-lg btn-block btn-icon-split p-3" style="height: auto;">
                                            <i class="fas fa-envelope fa-2x d-block mb-2"></i> Izin
                                        </button>
                                    </div>
                                </div>
                                <div class="mt-3 text-muted">
                                    <small>* Jika tidak memilih, status otomatis dianggap <b>Alpa</b>.</small>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($student['jenis_kelamin'] == 'P'): ?>
            <div class="card card-danger mt-4">
                <div class="card-header">
                    <h4>Laporan Berhalangan (Haid)</h4>
                </div>
                <div class="card-body text-center">
                    <?php if ($sholat_status == 'Berhalangan'): ?>
                        <div class="alert alert-info mb-4">
                            Status saat ini: <b>Sedang Berhalangan</b>
                        </div>
                        <form method="POST" action="">
                            <button type="submit" name="toggle_berhalangan" value="unset" class="btn btn-outline-danger btn-lg btn-block">
                                <i class="fas fa-check-circle mr-2"></i> Saya sudah suci / Batalkan
                            </button>
                        </form>
                    <?php else: ?>
                        <p class="mb-4">Jika Anda sedang berhalangan (haid), silakan klik tombol di bawah ini:</p>
                        <form method="POST" action="">
                            <button type="submit" name="toggle_berhalangan" value="set" class="btn btn-danger btn-lg btn-block">
                                <i class="fas fa-female mr-2"></i> Saya sedang berhalangan
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="row">
            <!-- Box Barcode Absensi -->
            <div class="col-12">
                <div class="card card-info">
                    <div class="card-header">
                        <h4>Barcode Absensi</h4>
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
