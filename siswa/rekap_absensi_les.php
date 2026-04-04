<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has siswa level
if (!isAuthorized(['siswa'])) {
    redirect('../login.php');
}

$id_siswa = $_SESSION['user_id'];
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'] ?? date('Y/Y', strtotime('+1 year'));
$semester_aktif = $school_profile['semester'] ?? '1';

// Ambil data profil madrasah untuk tanda tangan
$schoolCity = $school_profile['tempat_jadwal'] ?? 'Jakarta';
$schoolName = $school_profile['nama_madrasah'] ?? 'Madrasah';
$kepalaMadrasah = $school_profile['kepala_madrasah'] ?? '';
$reportDate = formatDateIndonesia(date('Y-m-d'));

// Ambil data siswa dan kelas
$stmt = $pdo->prepare("
    SELECT s.nama_siswa, s.nisn, k.nama_kelas, k.wali_kelas, k.id_kelas
    FROM tb_siswa s
    LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas
    WHERE s.id_siswa = ?
");
$stmt->execute([$id_siswa]);
$student_info = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student_info || $student_info['id_kelas'] != 6) {
    echo "<script>alert('Menu ini hanya tersedia untuk siswa kelas 6.'); window.location.href='dashboard.php';</script>";
    exit;
}

$nama_siswa = $student_info['nama_siswa'] ?? '';
$nis_siswa = $student_info['nisn'] ?? '';
$nama_kelas = $student_info['nama_kelas'] ?? '';
$waliKelasName = $student_info['wali_kelas'] ?? '';

// --- 1. Data Absensi Les ---
$stmt = $pdo->prepare("
    SELECT al.*, jl.waktu, jl.mapel, jl.materi
    FROM tb_absensi_les al
    LEFT JOIN tb_jadwal_les jl ON al.id_siswa = ? AND al.tanggal = jl.tanggal
    WHERE al.id_siswa = ?
    ORDER BY al.tanggal DESC
");
// Note: Logic joining might vary based on your schema, 
// usually we join by date and maybe mapel if exists in attendance table.
// For now, let's simplify based on common student dashboard patterns.
$stmt = $pdo->prepare("SELECT * FROM tb_absensi_les WHERE id_siswa = ? ORDER BY tanggal DESC");
$stmt->execute([$id_siswa]);
$harian_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set page title
$page_title = 'Rekap Absensi Les';

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Rekap Absensi Les</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Rekap Absensi Les</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Data Kehadiran Les - Kelas 6</h4>
                            <div class="card-header-action">
                                <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Cetak</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped" id="table-1">
                                    <thead>
                                        <tr>
                                            <th class="text-center">No</th>
                                            <th>Tanggal</th>
                                            <th>Status</th>
                                            <th>Keterangan</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($harian_data)): ?>
                                            <tr>
                                                <td colspan="4" class="text-center">Belum ada data absensi les.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php $no = 1; foreach ($harian_data as $row): ?>
                                                <tr>
                                                    <td class="text-center"><?= $no++ ?></td>
                                                    <td><?= formatDateIndonesia($row['tanggal']) ?></td>
                                                    <td>
                                                        <?php 
                                                        $status = $row['keterangan'];
                                                        $badge_class = 'badge-secondary';
                                                        if ($status == 'Hadir') $badge_class = 'badge-success';
                                                        elseif ($status == 'Sakit') $badge_class = 'badge-warning';
                                                        elseif ($status == 'Izin') $badge_class = 'badge-info';
                                                        elseif ($status == 'Alpa') $badge_class = 'badge-danger';
                                                        ?>
                                                        <span class="badge <?= $badge_class ?>"><?= $status ?></span>
                                                    </td>
                                                    <td><?= htmlspecialchars($row['catatan'] ?? '-') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
