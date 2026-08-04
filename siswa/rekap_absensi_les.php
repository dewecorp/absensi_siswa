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

// --- 1. Data Kehadiran Les ---
// Fetch all schedules for Class 6 and join with student's attendance
$stmt = $pdo->prepare("
    SELECT 
        jl.tanggal, 
        jl.hari,
        al.status
    FROM tb_jadwal_les jl
    LEFT JOIN tb_absensi_les al ON jl.tanggal = al.tanggal AND al.id_siswa = ?
    ORDER BY jl.tanggal ASC
");
$stmt->execute([$id_siswa]);
$harian_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set page title
$page_title = 'Rekap Kehadiran Les';

// Define JS libraries for this page
$js_libs = [
    "assets/vendor/xlsx/xlsx.full.min.js"
];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Rekap Kehadiran Les</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Rekap Kehadiran Les</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Data Kehadiran Les - Kelas 6</h4>
                            <div class="card-header-action">
                                <button class="btn btn-success" onclick="exportExcel()"><i class="fas fa-file-excel"></i> Excel</button>
                                <a href="cetak_rekap_les.php" target="_blank" class="btn btn-danger"><i class="fas fa-file-pdf"></i> PDF</a>
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
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($harian_data)): ?>
                                            <tr>
                                                <td colspan="3" class="text-center">Belum ada data kehadiran les.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php $no = 1; foreach ($harian_data as $row): ?>
                                                <tr>
                                                    <td class="text-center"><?= $no++ ?></td>
                                                    <td><?= formatDateIndonesia($row['tanggal']) ?></td>
                                                    <td>
                                                        <?php 
                                                        $status = $row['status'] ?? '';
                                                        $display_status = '';
                                                        $badge_class = 'badge-secondary';

                                                        if ($status == 'Hadir') {
                                                            $display_status = 'Hadir';
                                                            $badge_class = 'badge-success';
                                                        } elseif (in_array($status, ['Sakit', 'Izin', 'Alpa'])) {
                                                            $display_status = 'Tidak Hadir (' . $status . ')';
                                                            $badge_class = 'badge-danger';
                                                        } else {
                                                            $display_status = 'Belum Absen';
                                                            $badge_class = 'badge-warning';
                                                        }
                                                        ?>
                                                        <span class="badge <?= $badge_class ?>"><?= $display_status ?></span>
                                                    </td>
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

<script>
function exportExcel() {
    // Create a new workbook
    var wb = XLSX.utils.book_new();
    
    // Header Data
    var data = [
        ['<?= strtoupper($school_profile['nama_yayasan'] ?? 'YAYASAN') ?>'],
        ['<?= strtoupper($school_profile['nama_madrasah'] ?? 'MADRASAH') ?>'],
        ['<?= $school_profile['alamat'] ?? '' ?>'],
        ['Tahun Ajaran: <?= $school_profile['tahun_ajaran'] ?? '-' ?>'],
        [''],
        ['REKAP ABSENSI LES KELAS 6'],
        [''],
        ['Nama Siswa', ': <?= $nama_siswa ?>'],
        ['NISN', ': <?= $nis_siswa ?>'],
        ['Kelas', ': <?= $nama_kelas ?>'],
        [''],
        ['No', 'Hari, Tanggal', 'Status Kehadiran']
    ];

    // Add Table Body
    <?php $no = 1; foreach ($harian_data as $row): ?>
        <?php 
            $status = $row['status'] ?? '';
            if ($status == 'Hadir') $st_text = 'Hadir';
            elseif (in_array($status, ['Sakit', 'Izin', 'Alpa'])) $st_text = 'Tidak Hadir (' . $status . ')';
            else $st_text = 'Belum Absen';
        ?>
        data.push([
            '<?= $no++ ?>',
            '<?= $row['hari'] ?>, <?= formatDateIndonesia($row['tanggal']) ?>',
            '<?= $st_text ?>'
        ]);
    <?php endforeach; ?>
    
    // Add Footer (Signature)
    data.push(['']);
    data.push(['', '', '<?= $school_profile['tempat_jadwal'] ?? 'Sukosono' ?>, <?= formatDateIndonesia(date('Y-m-d')) ?>']);
    data.push(['Mengetahui,', '', 'Wali Kelas <?= $nama_kelas ?>']);
    data.push(['Kepala Madrasah,', '', '']);
    data.push(['']);
    data.push(['']);
    data.push(['( <?= $school_profile['kepala_madrasah'] ?? '-' ?> )', '', '( <?= $waliKelasName ?> )']);

    var ws = XLSX.utils.aoa_to_sheet(data);

    // Column Widths
    ws['!cols'] = [
        { wch: 6 },  // No
        { wch: 35 }, // Tanggal
        { wch: 30 }  // Status
    ];

    // Merges for header
    ws['!merges'] = [
        { s: { r: 0, c: 0 }, e: { r: 0, c: 2 } }, // Yayasan
        { s: { r: 1, c: 0 }, e: { r: 1, c: 2 } }, // Madrasah
        { s: { r: 2, c: 0 }, e: { r: 2, c: 2 } }, // Alamat
        { s: { r: 3, c: 0 }, e: { r: 3, c: 2 } }, // Tahun Ajaran
        { s: { r: 5, c: 0 }, e: { r: 5, c: 2 } }  // Title
    ];

    XLSX.utils.book_append_sheet(wb, ws, "Rekap Kehadiran Les");
    
    // Write file
    XLSX.writeFile(wb, "Rekap_Absensi_Les_<?= str_replace(' ', '_', $nama_siswa) ?>.xlsx");
}
</script>

<?php include '../templates/footer.php'; ?>
