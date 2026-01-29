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
$stmt = $pdo->prepare("
    SELECT a.tanggal, COALESCE(s.status, 'Melaksanakan') as status 
    FROM tb_absensi a 
    LEFT JOIN tb_sholat s ON a.id_siswa = s.id_siswa AND a.tanggal = s.tanggal 
    WHERE a.id_siswa = ? AND a.keterangan IN ('Hadir', 'Terlambat') 
    ORDER BY a.tanggal DESC
");
$stmt->execute([$id_siswa]);
$harian_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 2. Bulanan Data ---
$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(a.tanggal, '%Y-%m') as periode,
        SUM(CASE 
            WHEN s.status = 'Melaksanakan' THEN 1 
            WHEN s.status IS NULL AND a.keterangan IN ('Hadir', 'Terlambat') THEN 1 
            ELSE 0 
        END) as melaksanakan,
        SUM(CASE 
            WHEN s.status = 'Tidak Melaksanakan' THEN 1 
            WHEN s.status IS NULL AND a.keterangan IN ('Sakit', 'Izin', 'Alpa') THEN 1
            ELSE 0 
        END) as tidak_melaksanakan,
        SUM(CASE WHEN s.status = 'Berhalangan' THEN 1 ELSE 0 END) as berhalangan
    FROM tb_absensi a
    LEFT JOIN tb_sholat s ON a.id_siswa = s.id_siswa AND a.tanggal = s.tanggal
    WHERE a.id_siswa = ? 
    GROUP BY periode 
    ORDER BY periode DESC
");
$stmt->execute([$id_siswa]);
$bulanan_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 3. Semester Data ---
// Determine date range based on active semester
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
        SUM(CASE 
            WHEN s.status = 'Melaksanakan' THEN 1 
            WHEN s.status IS NULL AND a.keterangan IN ('Hadir', 'Terlambat') THEN 1 
            ELSE 0 
        END) as melaksanakan,
        SUM(CASE 
            WHEN s.status = 'Tidak Melaksanakan' THEN 1 
            WHEN s.status IS NULL AND a.keterangan IN ('Sakit', 'Izin', 'Alpa') THEN 1
            ELSE 0 
        END) as tidak_melaksanakan,
        SUM(CASE WHEN s.status = 'Berhalangan' THEN 1 ELSE 0 END) as berhalangan
    FROM tb_absensi a
    LEFT JOIN tb_sholat s ON a.id_siswa = s.id_siswa AND a.tanggal = s.tanggal
    WHERE a.id_siswa = ? AND a.tanggal BETWEEN ? AND ?
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

$page_title = 'Rekap Sholat Berjamaah';
include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Rekap Sholat Berjamaah</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item active">Rekap Sholat Berjamaah</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Data Sholat Berjamaah Anda</h4>
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
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($harian_data) > 0): ?>
                                                    <?php foreach ($harian_data as $row): ?>
                                                        <tr>
                                                            <td><?php echo formatDate($row['tanggal']); ?></td>
                                                            <td>
                                                                <?php
                                                                $badge_class = 'secondary';
                                                                if ($row['status'] == 'Melaksanakan') $badge_class = 'success';
                                                                elseif ($row['status'] == 'Tidak Melaksanakan') $badge_class = 'danger';
                                                                elseif ($row['status'] == 'Berhalangan') $badge_class = 'warning';
                                                                elseif ($row['status'] == 'Belum Diisi') $badge_class = 'secondary';
                                                                ?>
                                                                <div class="badge badge-<?php echo $badge_class; ?>"><?php echo $row['status']; ?></div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="2" class="text-center">Belum ada data sholat.</td>
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
                                                    <th class="text-center text-success">Melaksanakan</th>
                                                    <th class="text-center text-danger">Tidak Melaksanakan</th>
                                                    <th class="text-center text-warning">Berhalangan</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($bulanan_data) > 0): ?>
                                                    <?php foreach ($bulanan_data as $row): ?>
                                                        <tr>
                                                            <td><?php echo getIndonesianMonth($row['periode']); ?></td>
                                                            <td class="text-center"><?php echo $row['melaksanakan']; ?></td>
                                                            <td class="text-center"><?php echo $row['tidak_melaksanakan']; ?></td>
                                                            <td class="text-center"><?php echo $row['berhalangan']; ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="4" class="text-center">Belum ada data sholat.</td>
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
                                        <div class="col-lg-4 col-md-4 col-sm-12 col-12">
                                            <div class="card card-statistic-1">
                                                <div class="card-icon bg-success">
                                                    <i class="fas fa-check"></i>
                                                </div>
                                                <div class="card-wrap">
                                                    <div class="card-header">
                                                        <h4>Melaksanakan</h4>
                                                    </div>
                                                    <div class="card-body">
                                                        <?php echo $semester_data['melaksanakan'] ?? 0; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-lg-4 col-md-4 col-sm-12 col-12">
                                            <div class="card card-statistic-1">
                                                <div class="card-icon bg-danger">
                                                    <i class="fas fa-times"></i>
                                                </div>
                                                <div class="card-wrap">
                                                    <div class="card-header">
                                                        <h4>Tidak Melaksanakan</h4>
                                                    </div>
                                                    <div class="card-body">
                                                        <?php echo $semester_data['tidak_melaksanakan'] ?? 0; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-lg-4 col-md-4 col-sm-12 col-12">
                                            <div class="card card-statistic-1">
                                                <div class="card-icon bg-warning">
                                                    <i class="fas fa-female"></i>
                                                </div>
                                                <div class="card-wrap">
                                                    <div class="card-header">
                                                        <h4>Berhalangan</h4>
                                                    </div>
                                                    <div class="card-body">
                                                        <?php echo $semester_data['berhalangan'] ?? 0; ?>
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
