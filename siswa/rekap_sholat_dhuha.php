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
    LEFT JOIN tb_sholat_dhuha s ON a.id_siswa = s.id_siswa AND a.tanggal = s.tanggal 
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
    LEFT JOIN tb_sholat_dhuha s ON a.id_siswa = s.id_siswa AND a.tanggal = s.tanggal
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
    LEFT JOIN tb_sholat_dhuha s ON a.id_siswa = s.id_siswa AND a.tanggal = s.tanggal
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

// Define JS libraries for this page
$js_libs = [
    "https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"
];

// Get student profile with class and wali info
$stmt = $pdo->prepare("
    SELECT s.nama_siswa, s.nisn, k.nama_kelas, k.wali_kelas 
    FROM tb_siswa s 
    LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas 
    WHERE s.id_siswa = ?
");
$stmt->execute([$id_siswa]);
$student_profile = $stmt->fetch(PDO::FETCH_ASSOC);

$page_title = 'Rekap Sholat Dhuha';
include '../templates/header.php';
include '../templates/sidebar.php';

echo "<script>
    var schoolLogo = " . json_encode($school_profile['logo'] ?? 'logo.png') . ";
    var schoolName = " . json_encode($school_profile['nama_madrasah'] ?? 'Madrasah Ibtidaiyah Negeri Pembina Kota Padang') . ";
    var studentName = " . json_encode($student_profile['nama_siswa'] ?? '') . ";
    var studentClass = " . json_encode($student_profile['nama_kelas'] ?? '') . ";
    var classTeacherName = " . json_encode($student_profile['wali_kelas'] ?? '') . ";
    var madrasahHeadName = " . json_encode($school_profile['nama_kepala_madrasah'] ?? '') . ";
    var academicYear = " . json_encode($tahun_ajaran) . ";
    var activeSemester = " . json_encode($semester_label ?? $semester_aktif) . ";
</script>";
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Rekap Sholat Dhuha</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item active">Rekap Sholat Dhuha</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Data Sholat Dhuha Anda</h4>
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
                                    <div class="row mt-3">
                                        <div class="col-md-12">
                                            <div class="btn-group float-right" role="group">
                                                <button type="button" class="btn btn-success" onclick="exportHarianToExcel()">
                                                    <i class="fas fa-file-excel"></i> Excel
                                                </button>
                                                <button type="button" class="btn btn-warning" onclick="exportHarianToPDF()">
                                                    <i class="fas fa-file-pdf"></i> PDF
                                                </button>
                                            </div>
                                        </div>
                                    </div>
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
                                        <div class="col-12">
                                            <div class="btn-group float-right mb-3" role="group">
                                                <button type="button" class="btn btn-success" onclick="exportSemesterToExcel()">
                                                    <i class="fas fa-file-excel"></i> Excel
                                                </button>
                                                <button type="button" class="btn btn-warning" onclick="exportSemesterToPDF()">
                                                    <i class="fas fa-file-pdf"></i> PDF
                                                </button>
                                            </div>
                                        </div>
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

<script>
function exportHarianToExcel() {
    var container = document.createElement('div');
    var headerDiv = document.createElement('div');
    headerDiv.innerHTML = '<img src="../assets/img/' + schoolLogo + '" alt="Logo" style="max-width: 100px; float: left; margin-right: 20px;"><div style="display: inline-block;"><h2>Sistem Absensi Siswa</h2>';
    headerDiv.innerHTML += '<h3>' + schoolName + '</h3>';
    headerDiv.innerHTML += '<p style="margin: 5px 0;">Tahun Ajaran: ' + academicYear + ' | Semester: ' + activeSemester + '</p>';
    headerDiv.innerHTML += '<h4>Rekap Harian Sholat Dhuha - ' + studentName + '</h4></div><br style="clear: both;">';
    
    var table = document.getElementById('table-harian');
    if (!table) { alert('Tabel tidak ditemukan'); return; }
    
    var newTable = table.cloneNode(true);
    
    // Convert badges to text
    var badges = newTable.querySelectorAll('.badge');
    badges.forEach(function(badge) {
        var text = badge.textContent;
        var textNode = document.createTextNode(text);
        badge.parentNode.replaceChild(textNode, badge);
    });

    container.appendChild(headerDiv);
    container.appendChild(newTable);

    if (typeof XLSX !== 'undefined') {
        var wb = XLSX.utils.book_new();
        var ws = XLSX.utils.table_to_sheet(newTable);
        XLSX.utils.book_append_sheet(wb, ws, "Rekap Harian");
        XLSX.writeFile(wb, 'rekap_harian_' + studentName.replace(/[^a-z0-9]/gi, '_').toLowerCase() + '.xlsx');
    }
}

function exportHarianToPDF() {
    var printWindow = window.open('', '', 'height=860,width=1300');
    printWindow.document.write('<html><head><title>Rekap Harian Sholat Dhuha</title>');
    printWindow.document.write('<style>');
    printWindow.document.write('@page { size: A4 portrait; margin: 1cm; }');
    printWindow.document.write('body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }');
    printWindow.document.write('table { border-collapse: collapse; width: 100%; font-size: 12px; margin-bottom: 20px; }');
    printWindow.document.write('th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }');
    printWindow.document.write('th { background-color: #f2f2f2; font-weight: bold; }');
    printWindow.document.write('.header { text-align: center; margin-bottom: 20px; }');
    printWindow.document.write('.badge { padding: 0; color: black !important; background-color: transparent !important; border: none; font-weight: bold; }');
    printWindow.document.write('.signature-wrapper { margin-top: 30px; display: flex; justify-content: space-between; width: 100%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.signature-box { text-align: center; width: 45%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body>');
    
    printWindow.document.write('<div class="header">');
    printWindow.document.write('<img src="../assets/img/' + schoolLogo + '" alt="Logo" style="max-width: 80px; vertical-align: middle; margin-right: 15px;">');
    printWindow.document.write('<div style="display: inline-block; vertical-align: middle;">');
    printWindow.document.write('<h2 style="margin: 0;">Sistem Absensi Siswa</h2>');
    printWindow.document.write('<h3 style="margin: 5px 0;">' + schoolName + '</h3>');
    printWindow.document.write('<p style="margin: 5px 0;">Tahun Ajaran: ' + academicYear + ' | Semester: ' + activeSemester + '</p>');
    printWindow.document.write('<h4 style="margin: 0;">Rekap Harian Sholat Dhuha - ' + studentName + '</h4>');
    printWindow.document.write('</div></div>');
    
    var table = document.getElementById('table-harian');
    if (table) {
        var tableHTML = table.outerHTML;
        printWindow.document.write(tableHTML);
    }
    
    printWindow.document.write('<div class="signature-wrapper">');
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p>&nbsp;</p>');
    printWindow.document.write('<p>Wali Kelas,</p>');
    printWindow.document.write('<br><br><br>');
    printWindow.document.write('<p><strong>' + classTeacherName + '</strong></p>');
    printWindow.document.write('</div>');
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p>' + new Date().toLocaleDateString('id-ID', {day: 'numeric', month: 'long', year: 'numeric'}) + '</p>');
    printWindow.document.write('<p>Kepala Madrasah,</p>');
    printWindow.document.write('<br><br><br>');
    printWindow.document.write('<p><strong>' + madrasahHeadName + '</strong></p>');
    printWindow.document.write('</div>');
    printWindow.document.write('</div>');
    
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.focus();
    setTimeout(function() { printWindow.print(); }, 500);
}

function exportBulananToExcel() {
    var container = document.createElement('div');
    var headerDiv = document.createElement('div');
    headerDiv.innerHTML = '<img src="../assets/img/' + schoolLogo + '" alt="Logo" style="max-width: 100px; float: left; margin-right: 20px;"><div style="display: inline-block;"><h2>Sistem Absensi Siswa</h2>';
    headerDiv.innerHTML += '<h3>' + schoolName + '</h3>';
    headerDiv.innerHTML += '<p style="margin: 5px 0;">Tahun Ajaran: ' + academicYear + ' | Semester: ' + activeSemester + '</p>';
    headerDiv.innerHTML += '<h4>Rekap Bulanan Sholat Dhuha - ' + studentName + '</h4></div><br style="clear: both;">';
    
    var table = document.getElementById('table-bulanan');
    if (!table) { alert('Tabel tidak ditemukan'); return; }
    
    var newTable = table.cloneNode(true);
    
    container.appendChild(headerDiv);
    container.appendChild(newTable);

    if (typeof XLSX !== 'undefined') {
        var wb = XLSX.utils.book_new();
        var ws = XLSX.utils.table_to_sheet(newTable);
        XLSX.utils.book_append_sheet(wb, ws, "Rekap Bulanan");
        XLSX.writeFile(wb, 'rekap_bulanan_' + studentName.replace(/[^a-z0-9]/gi, '_').toLowerCase() + '.xlsx');
    }
}

function exportBulananToPDF() {
    var printWindow = window.open('', '', 'height=860,width=1300');
    printWindow.document.write('<html><head><title>Rekap Bulanan Sholat Dhuha</title>');
    printWindow.document.write('<style>');
    printWindow.document.write('@page { size: A4 portrait; margin: 1cm; }');
    printWindow.document.write('body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }');
    printWindow.document.write('table { border-collapse: collapse; width: 100%; font-size: 12px; margin-bottom: 20px; }');
    printWindow.document.write('th, td { border: 1px solid #ddd; padding: 8px; text-align: center; }');
    printWindow.document.write('th { background-color: #f2f2f2; font-weight: bold; }');
    printWindow.document.write('.header { text-align: center; margin-bottom: 20px; }');
    printWindow.document.write('.signature-wrapper { margin-top: 30px; display: flex; justify-content: space-between; width: 100%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.signature-box { text-align: center; width: 45%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body>');
    
    printWindow.document.write('<div class="header">');
    printWindow.document.write('<img src="../assets/img/' + schoolLogo + '" alt="Logo" style="max-width: 80px; vertical-align: middle; margin-right: 15px;">');
    printWindow.document.write('<div style="display: inline-block; vertical-align: middle;">');
    printWindow.document.write('<h2 style="margin: 0;">Sistem Absensi Siswa</h2>');
    printWindow.document.write('<h3 style="margin: 5px 0;">' + schoolName + '</h3>');
    printWindow.document.write('<h4 style="margin: 0;">Rekap Bulanan Sholat Dhuha - ' + studentName + '</h4>');
    printWindow.document.write('</div></div>');
    
    var table = document.getElementById('table-bulanan');
    if (table) {
        var tableHTML = table.outerHTML;
        printWindow.document.write(tableHTML);
    }
    
    printWindow.document.write('<div class="signature-wrapper">');
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p>&nbsp;</p>');
    printWindow.document.write('<p>Wali Kelas,</p>');
    printWindow.document.write('<br><br><br>');
    printWindow.document.write('<p><strong>' + classTeacherName + '</strong></p>');
    printWindow.document.write('</div>');
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p>' + new Date().toLocaleDateString('id-ID', {day: 'numeric', month: 'long', year: 'numeric'}) + '</p>');
    printWindow.document.write('<p>Kepala Madrasah,</p>');
    printWindow.document.write('<br><br><br>');
    printWindow.document.write('<p><strong>' + madrasahHeadName + '</strong></p>');
    printWindow.document.write('</div>');
    printWindow.document.write('</div>');
    
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.focus();
    setTimeout(function() { printWindow.print(); }, 500);
}

function exportSemesterToExcel() {
    // Construct data manually from the PHP-generated content in the page
    // Since there is no table, we will grab the values from the cards
    var melaksanakan = document.querySelector('#semester .card-icon.bg-success').nextElementSibling.querySelector('.card-body').textContent.trim();
    var tidakMelaksanakan = document.querySelector('#semester .card-icon.bg-danger').nextElementSibling.querySelector('.card-body').textContent.trim();
    var berhalangan = document.querySelector('#semester .card-icon.bg-warning').nextElementSibling.querySelector('.card-body').textContent.trim();
    
    var wb = XLSX.utils.book_new();
    var ws_data = [
        ["Rekap Semester Sholat Dhuha"],
        ["Nama Siswa", studentName],
        ["Semester", activeSemester],
        ["Tahun Ajaran", academicYear],
        [""],
        ["Status", "Jumlah"],
        ["Melaksanakan", parseInt(melaksanakan)],
        ["Tidak Melaksanakan", parseInt(tidakMelaksanakan)],
        ["Berhalangan", parseInt(berhalangan)]
    ];
    
    var ws = XLSX.utils.aoa_to_sheet(ws_data);
    XLSX.utils.book_append_sheet(wb, ws, "Rekap Semester");
    XLSX.writeFile(wb, 'rekap_semester_' + studentName.replace(/[^a-z0-9]/gi, '_').toLowerCase() + '.xlsx');
}

function exportSemesterToPDF() {
    var melaksanakan = document.querySelector('#semester .card-icon.bg-success').nextElementSibling.querySelector('.card-body').textContent.trim();
    var tidakMelaksanakan = document.querySelector('#semester .card-icon.bg-danger').nextElementSibling.querySelector('.card-body').textContent.trim();
    var berhalangan = document.querySelector('#semester .card-icon.bg-warning').nextElementSibling.querySelector('.card-body').textContent.trim();

    var printWindow = window.open('', '', 'height=860,width=1300');
    printWindow.document.write('<html><head><title>Rekap Semester Sholat Dhuha</title>');
    printWindow.document.write('<style>');
    printWindow.document.write('@page { size: A4 portrait; margin: 1cm; }');
    printWindow.document.write('body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }');
    printWindow.document.write('table { border-collapse: collapse; width: 100%; font-size: 12px; margin-bottom: 20px; }');
    printWindow.document.write('th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }');
    printWindow.document.write('th { background-color: #f2f2f2; font-weight: bold; }');
    printWindow.document.write('.header { text-align: center; margin-bottom: 20px; }');
    printWindow.document.write('.signature-wrapper { margin-top: 30px; display: flex; justify-content: space-between; width: 100%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.signature-box { text-align: center; width: 45%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body>');
    
    printWindow.document.write('<div class="header">');
    printWindow.document.write('<img src="../assets/img/' + schoolLogo + '" alt="Logo" style="max-width: 80px; vertical-align: middle; margin-right: 15px;">');
    printWindow.document.write('<div style="display: inline-block; vertical-align: middle;">');
    printWindow.document.write('<h2 style="margin: 0;">Sistem Absensi Siswa</h2>');
    printWindow.document.write('<h3 style="margin: 5px 0;">' + schoolName + '</h3>');
    printWindow.document.write('<h4 style="margin: 0;">Rekap Semester Sholat Dhuha - ' + activeSemester + ' ' + academicYear + '</h4>');
    printWindow.document.write('<h5 style="margin: 0;">Siswa: ' + studentName + ' (' + studentClass + ')</h5>');
    printWindow.document.write('</div></div>');
    
    printWindow.document.write('<table>');
    printWindow.document.write('<thead><tr><th>Status</th><th>Jumlah</th></tr></thead>');
    printWindow.document.write('<tbody>');
    printWindow.document.write('<tr><td>Melaksanakan</td><td>' + melaksanakan + '</td></tr>');
    printWindow.document.write('<tr><td>Tidak Melaksanakan</td><td>' + tidakMelaksanakan + '</td></tr>');
    printWindow.document.write('<tr><td>Berhalangan</td><td>' + berhalangan + '</td></tr>');
    printWindow.document.write('</tbody></table>');
    
    printWindow.document.write('<div class="signature-wrapper">');
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p>&nbsp;</p>');
    printWindow.document.write('<p>Wali Kelas,</p>');
    printWindow.document.write('<br><br><br>');
    printWindow.document.write('<p><strong>' + classTeacherName + '</strong></p>');
    printWindow.document.write('</div>');
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p>' + new Date().toLocaleDateString('id-ID', {day: 'numeric', month: 'long', year: 'numeric'}) + '</p>');
    printWindow.document.write('<p>Kepala Madrasah,</p>');
    printWindow.document.write('<br><br><br>');
    printWindow.document.write('<p><strong>' + madrasahHeadName + '</strong></p>');
    printWindow.document.write('</div>');
    printWindow.document.write('</div>');
    
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.focus();
    setTimeout(function() { printWindow.print(); }, 500);
}
</script>

<?php include '../templates/footer.php'; ?>
