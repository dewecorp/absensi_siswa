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

// --- 1. Harian Data ---
$stmt = $pdo->prepare("SELECT * FROM tb_absensi WHERE id_siswa = ? ORDER BY tanggal DESC");
$stmt->execute([$id_siswa]);
$harian_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 2. Bulanan Data ---
$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(tanggal, '%Y-%m') as periode,
        SUM(CASE WHEN keterangan = 'Hadir' THEN 1 ELSE 0 END) as hadir,
        SUM(CASE WHEN keterangan = 'Sakit' THEN 1 ELSE 0 END) as sakit,
        SUM(CASE WHEN keterangan = 'Izin' THEN 1 ELSE 0 END) as izin,
        SUM(CASE WHEN keterangan = 'Alpa' THEN 1 ELSE 0 END) as alpa,
        SUM(CASE WHEN keterangan = 'Terlambat' THEN 1 ELSE 0 END) as terlambat
    FROM tb_absensi 
    WHERE id_siswa = ? 
    GROUP BY periode 
    ORDER BY periode DESC
");
$stmt->execute([$id_siswa]);
$bulanan_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 3. Semester Data ---
// Determine date range based on active semester
// Assuming format "2024/2025" or similar. Fallback to current year if invalid.
$tahun_parts = explode('/', $tahun_ajaran);
if (count($tahun_parts) >= 2) {
    $start_year = intval($tahun_parts[0]);
    $end_year = intval($tahun_parts[1]);
} else {
    $start_year = date('Y');
    $end_year = date('Y') + 1;
}

$semester_start = '';
$semester_end = '';

// Simple logic: if '2' or 'Genap' in semester string, it's 2nd semester (Jan-Jun of end_year)
// Else it's 1st semester (Jul-Dec of start_year)
if (stripos($semester_aktif, '2') !== false || stripos($semester_aktif, 'Genap') !== false) {
    $semester_start = "$end_year-01-01";
    $semester_end = "$end_year-06-30";
    $semester_label = "Semester Genap $tahun_ajaran";
} else {
    $semester_start = "$start_year-07-01";
    $semester_end = "$start_year-12-31";
    $semester_label = "Semester Ganjil $tahun_ajaran";
}

$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_hari,
        SUM(CASE WHEN keterangan = 'Hadir' THEN 1 ELSE 0 END) as hadir,
        SUM(CASE WHEN keterangan = 'Sakit' THEN 1 ELSE 0 END) as sakit,
        SUM(CASE WHEN keterangan = 'Izin' THEN 1 ELSE 0 END) as izin,
        SUM(CASE WHEN keterangan = 'Alpa' THEN 1 ELSE 0 END) as alpa,
        SUM(CASE WHEN keterangan = 'Terlambat' THEN 1 ELSE 0 END) as terlambat
    FROM tb_absensi 
    WHERE id_siswa = ? AND tanggal BETWEEN ? AND ?
");
$stmt->execute([$id_siswa, $semester_start, $semester_end]);
$semester_data = $stmt->fetch(PDO::FETCH_ASSOC);

// Helper function for Indonesian Month Name
function getIndonesianMonth($periode) {
    $parts = explode('-', $periode);
    $year = $parts[0];
    $month = (int)$parts[1];
    
    $months = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    
    return $months[$month] . ' ' . $year;
}

$page_title = 'Rekap Absensi';
include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Rekap Absensi Siswa</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item active">Rekap Absensi</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Data Kehadiran Anda</h4>
                        </div>
                        <div class="card-body">
                            <ul class="nav nav-tabs" id="myTab" role="tablist">
                                <li class="nav-item">
                                    <a class="nav-link active" id="harian-tab" data-toggle="tab" href="#harian" role="tab" aria-controls="harian" aria-selected="true">Harian</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="bulanan-tab" data-toggle="tab" href="#bulanan" role="tab" aria-controls="bulanan" aria-selected="false">Bulanan</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="semester-tab" data-toggle="tab" href="#semester" role="tab" aria-controls="semester" aria-selected="false">Semester</a>
                                </li>
                            </ul>
                            <div class="tab-content" id="myTabContent">
                                <!-- TAB HARIAN -->
                                <div class="tab-pane fade show active" id="harian" role="tabpanel" aria-labelledby="harian-tab">
                                    <div class="table-responsive mt-3">
                                        <table class="table table-striped" id="table-harian">
                                            <thead>
                                                <tr>
                                                    <th>Tanggal</th>
                                                    <th>Jam Masuk</th>
                                                    <th>Keterangan</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($harian_data) > 0): ?>
                                                    <?php foreach ($harian_data as $row): ?>
                                                        <tr>
                                                            <td><?php echo formatDate($row['tanggal']); ?></td>
                                                            <td><?php echo $row['jam_masuk'] ?: '-'; ?></td>
                                                            <td>
                                                                <?php
                                                                $badge_class = 'secondary';
                                                                if ($row['keterangan'] == 'Hadir') $badge_class = 'success';
                                                                elseif ($row['keterangan'] == 'Sakit') $badge_class = 'warning';
                                                                elseif ($row['keterangan'] == 'Izin') $badge_class = 'info';
                                                                elseif ($row['keterangan'] == 'Alpa') $badge_class = 'danger';
                                                                elseif ($row['keterangan'] == 'Terlambat') $badge_class = 'secondary';
                                                                ?>
                                                                <div class="badge badge-<?php echo $badge_class; ?>"><?php echo $row['keterangan']; ?></div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="3" class="text-center">Belum ada data absensi.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <!-- TAB BULANAN -->
                                <div class="tab-pane fade" id="bulanan" role="tabpanel" aria-labelledby="bulanan-tab">
                                    <div class="table-responsive mt-3">
                                        <table class="table table-bordered table-md">
                                            <thead>
                                                <tr>
                                                    <th>Periode</th>
                                                    <th class="text-center text-success">Hadir</th>
                                                    <th class="text-center text-warning">Sakit</th>
                                                    <th class="text-center text-info">Izin</th>
                                                    <th class="text-center text-danger">Alpa</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($bulanan_data) > 0): ?>
                                                    <?php foreach ($bulanan_data as $row): ?>
                                                        <tr>
                                                            <td><?php echo getIndonesianMonth($row['periode']); ?></td>
                                                            <td class="text-center"><?php echo $row['hadir']; ?></td>
                                                            <td class="text-center"><?php echo $row['sakit']; ?></td>
                                                            <td class="text-center"><?php echo $row['izin']; ?></td>
                                                            <td class="text-center"><?php echo $row['alpa']; ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="5" class="text-center">Belum ada data absensi.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <!-- TAB SEMESTER -->
                                <div class="tab-pane fade" id="semester" role="tabpanel" aria-labelledby="semester-tab">
                                    <div class="row mt-3">
                                        <div class="col-12 mb-4">
                                            <div class="alert alert-light border">
                                                <i class="fas fa-info-circle mr-2"></i> Menampilkan data untuk <b><?php echo $semester_label; ?></b> (<?php echo formatDate($semester_start) . ' s.d. ' . formatDate($semester_end); ?>)
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                                            <div class="card card-statistic-1">
                                                <div class="card-icon bg-success">
                                                    <i class="fas fa-check"></i>
                                                </div>
                                                <div class="card-wrap">
                                                    <div class="card-header">
                                                        <h4>Hadir</h4>
                                                    </div>
                                                    <div class="card-body">
                                                        <?php echo $semester_data['hadir'] ?? 0; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                                            <div class="card card-statistic-1">
                                                <div class="card-icon bg-warning">
                                                    <i class="fas fa-procedures"></i>
                                                </div>
                                                <div class="card-wrap">
                                                    <div class="card-header">
                                                        <h4>Sakit</h4>
                                                    </div>
                                                    <div class="card-body">
                                                        <?php echo $semester_data['sakit'] ?? 0; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                                            <div class="card card-statistic-1">
                                                <div class="card-icon bg-info">
                                                    <i class="fas fa-envelope"></i>
                                                </div>
                                                <div class="card-wrap">
                                                    <div class="card-header">
                                                        <h4>Izin</h4>
                                                    </div>
                                                    <div class="card-body">
                                                        <?php echo $semester_data['izin'] ?? 0; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                                            <div class="card card-statistic-1">
                                                <div class="card-icon bg-danger">
                                                    <i class="fas fa-times"></i>
                                                </div>
                                                <div class="card-wrap">
                                                    <div class="card-header">
                                                        <h4>Alpa</h4>
                                                    </div>
                                                    <div class="card-body">
                                                        <?php echo $semester_data['alpa'] ?? 0; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-12 text-center mt-3">
                                            <div class="font-weight-bold text-muted">
                                                Total Hari Efektif (Data Masuk): <?php echo $semester_data['total_hari'] ?? 0; ?> Hari
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php
include '../templates/footer.php';
?>