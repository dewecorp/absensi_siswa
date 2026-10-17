<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['siswa'])) {
    redirect('../login.php');
}

// Get student data
$id_siswa = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT s.*, k.nama_kelas, k.id_kelas, k.wali_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_siswa = ?");
$stmt->execute([$id_siswa]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    echo "Data siswa tidak ditemukan.";
    exit;
}

$page_title = 'Rekap Nilai ' . trim((string)($student['nama_siswa'] ?? ''));

$id_kelas = $student['id_kelas'];

// Get Active Semester
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'];
$semester_aktif = $school_profile['semester'];
$school_city = $school_profile['tempat_jadwal'] ?? '';
$report_date = formatDateIndonesia(date('Y-m-d'));
$school_name = $school_profile['nama_madrasah'] ?? 'Madrasah';
$madrasah_head_name = $school_profile['kepala_madrasah'] ?? 'Kepala Madrasah';
$madrasah_head_signature = $school_profile['ttd_kepala'] ?? '';

// Parameters â€” Harian/Kokurikuler: asli kolom nilai; PTS/PAS/PAT: MAX(asli, remidi); bukan nilai_jadi
// Pra/Ujian madrasah tidak di sini (ada menu khusus kelas 6)
$rekap_jenis_allowed = ['Harian', 'PTS', 'PAS', 'PAT', 'Kokurikuler'];
$selected_jenis = isset($_GET['jenis']) ? (string)$_GET['jenis'] : 'Harian';
if (!in_array($selected_jenis, $rekap_jenis_allowed, true)) {
    $selected_jenis = 'Harian';
}

// Define JS libraries for this page
$js_libs = [
    "https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"
];

// Get Subjects (Mapel)
$subjects = getFilteredSubjects($pdo);

// Fetch Grades
$rekap_data = [];
$total_nilai = 0;
$count_mapel = 0;

foreach ($subjects as $mapel) {
    $nilai = 0;
    
    if ($selected_jenis == 'Harian') {
        // Rerata harian siswa = rerata kolom `nilai` (nilai asli PH), bukan `nilai_jadi`
        $stmt = $pdo->prepare("
            SELECT ROUND(AVG(CASE WHEN d.nilai IS NOT NULL AND d.nilai > 0 THEN d.nilai END)) AS rerata_asli
            FROM tb_nilai_harian_detail d
            INNER JOIN tb_nilai_harian_header h ON d.id_header = h.id_header
            WHERE h.id_kelas = ? AND h.id_mapel = ?
              AND h.tahun_ajaran = ? AND h.semester = ?
              AND d.id_siswa = ?
        ");
        $stmt->execute([$id_kelas, $mapel['id_mapel'], $tahun_ajaran, $semester_aktif, $id_siswa]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['rerata_asli'] !== null && (float)$row['rerata_asli'] > 0) {
            $nilai = (float)$row['rerata_asli'];
        }
    } elseif ($selected_jenis === 'Kokurikuler') {
        // Kokurikuler: rerata kolom `nilai` (asli), bukan `nilai_jadi`
        $stmt = $pdo->prepare("
            SELECT ROUND(AVG(CASE WHEN d.nilai IS NOT NULL AND d.nilai > 0 THEN d.nilai END)) AS rerata_asli
            FROM tb_nilai_kokurikuler_detail d
            INNER JOIN tb_nilai_kokurikuler_header h ON d.id_header = h.id_header
            WHERE h.id_kelas = ? AND h.id_mapel = ?
              AND h.tahun_ajaran = ? AND h.semester = ?
              AND d.id_siswa = ?
        ");
        $stmt->execute([$id_kelas, $mapel['id_mapel'], $tahun_ajaran, $semester_aktif, $id_siswa]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['rerata_asli'] !== null && (float)$row['rerata_asli'] > 0) {
            $nilai = (float)$row['rerata_asli'];
        }
    } else {
        // PTS, PAS, PAT â€” siswa: MAX(nilai_asli, nilai_remidi); bukan nilai_jadi
        $exam_type_map = [
            'PTS' => 'UTS',
            'PAS' => 'UAS',
            'PAT' => 'PAT',
        ];
        $db_jenis = $exam_type_map[$selected_jenis] ?? $selected_jenis;

        $stmt = $pdo->prepare("
            SELECT nilai_asli, nilai_remidi
            FROM tb_nilai_semester
            WHERE id_kelas = ? AND id_mapel = ?
              AND jenis_semester = ? AND tahun_ajaran = ? AND semester = ?
              AND id_siswa = ?
            LIMIT 1
        ");
        $stmt->execute([$id_kelas, $mapel['id_mapel'], $db_jenis, $tahun_ajaran, $semester_aktif, $id_siswa]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $nilai = nilai_tampilan_siswa_semester($row['nilai_asli'] ?? null, $row['nilai_remidi'] ?? null, false);
        }
    }

    $rekap_data[$mapel['id_mapel']] = $nilai;
    
    if ($nilai > 0) {
        $total_nilai += $nilai;
        $count_mapel++;
    }
}

$rerata = $count_mapel > 0 ? round($total_nilai / $count_mapel, 1) : 0;

$progress_total = count($subjects);
$progress_filled = $count_mapel;
$progress_percent = $progress_total > 0 ? round(($progress_filled / $progress_total) * 100, 1) : 0;

require_once '../templates/header.php';
require_once '../templates/sidebar.php';

function get_nilai_badge_class($val) {
    if ($val <= 0) return 'snilai-badge-muted';
    if ($val >= 85) return 'snilai-badge-success';
    if ($val >= 75) return 'snilai-badge-primary';
    if ($val >= 60) return 'snilai-badge-warning';
    return 'snilai-badge-danger';
}

function get_nilai_status_text($val) {
    if ($val <= 0) return 'Belum Diisi';
    if ($val >= 85) return 'Sangat Baik';
    if ($val >= 75) return 'Baik';
    if ($val >= 60) return 'Cukup';
    return 'Perlu Belajar';
}
?>

<link rel="stylesheet" href="../assets/css/siswa-nilai-mobile.css">

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><?= $page_title ?></h1>
        </div>

        <div class="section-body">
            <!-- Hero Card Ringkasan Nilai Siswa -->
            <div class="snilai-hero-card">
                <div class="snilai-hero-top">
                    <div class="snilai-student-info">
                        <div class="snilai-student-name">
                            <i class="fas fa-award mr-1"></i> <?= htmlspecialchars($student['nama_siswa']) ?>
                        </div>
                        <div class="snilai-student-sub">
                            <span>Kelas <?= htmlspecialchars($student['nama_kelas']) ?></span>
                            <span>•</span>
                            <span class="snilai-badge-ta">TA <?= htmlspecialchars($tahun_ajaran) ?> (<?= htmlspecialchars(formatSemesterLabelForExport($semester_aktif)) ?>)</span>
                        </div>
                    </div>
                </div>

                <div class="snilai-stats-grid">
                    <div class="snilai-stat-box">
                        <div class="snilai-stat-val gold"><?= $rerata ?></div>
                        <div class="snilai-stat-lbl">Rerata Nilai</div>
                    </div>
                    <div class="snilai-stat-box">
                        <div class="snilai-stat-val"><?= $total_nilai ?></div>
                        <div class="snilai-stat-lbl">Total Nilai</div>
                    </div>
                    <div class="snilai-stat-box">
                        <div class="snilai-stat-val"><?= (int)$progress_filled ?>/<?= (int)$progress_total ?></div>
                        <div class="snilai-stat-lbl">Mapel Terisi</div>
                    </div>
                </div>

                <div class="snilai-hero-progress">
                    <div class="snilai-hero-progress-head">
                        <span>Capaian Penilaian (<?= htmlspecialchars($selected_jenis) ?>)</span>
                        <span><?= (float)$progress_percent ?>%</span>
                    </div>
                    <div class="snilai-progress-bar-bg">
                        <div class="snilai-progress-bar-fill" style="width: <?= (float)$progress_percent ?>%;"></div>
                    </div>
                </div>
            </div>

            <!-- Tombol Aksi Ekspor -->
            <div class="snilai-actions-bar">
                <button type="button" class="snilai-chip-btn btn-excel" onclick="exportToExcel()">
                    <i class="fas fa-file-excel"></i> Ekspor Excel
                </button>
                <button type="button" class="snilai-chip-btn btn-pdf" onclick="exportToPDF()">
                    <i class="fas fa-file-pdf"></i> Ekspor PDF
                </button>
            </div>

            <!-- Navigasi Jenis Penilaian (Pill Tabs Horizontal Scroll) -->
            <div class="snilai-pills-container">
                <div class="snilai-pills-scroll">
                    <a href="?jenis=Harian" class="snilai-pill-item <?= $selected_jenis === 'Harian' ? 'active' : '' ?>">
                        <i class="fas fa-book-reader"></i> Nilai Harian
                    </a>
                    <a href="?jenis=PTS" class="snilai-pill-item <?= $selected_jenis === 'PTS' ? 'active' : '' ?>">
                        <i class="fas fa-clipboard-check"></i> PTS (Tengah Sem.)
                    </a>
                    <a href="?jenis=PAS" class="snilai-pill-item <?= $selected_jenis === 'PAS' ? 'active' : '' ?>">
                        <i class="fas fa-file-alt"></i> PAS (Akhir Sem.)
                    </a>
                    <a href="?jenis=PAT" class="snilai-pill-item <?= $selected_jenis === 'PAT' ? 'active' : '' ?>">
                        <i class="fas fa-calendar-check"></i> PAT (Akhir Thn)
                    </a>
                    <a href="?jenis=Kokurikuler" class="snilai-pill-item <?= $selected_jenis === 'Kokurikuler' ? 'active' : '' ?>">
                        <i class="fas fa-palette"></i> Kokurikuler
                    </a>
                </div>
            </div>

            <!-- Daftar Kartu Nilai Per Mata Pelajaran (Mobile Apps View) -->
            <div class="snilai-grade-list">
                <?php if (empty($subjects)): ?>
                    <div class="card border-0 shadow-sm text-center py-4" style="border-radius: 14px;">
                        <div class="text-muted small">Tidak ada mata pelajaran yang ditemukan.</div>
                    </div>
                <?php else: ?>
                    <?php 
                    $no = 1;
                    foreach ($subjects as $mapel): 
                        $val = $rekap_data[$mapel['id_mapel']] ?? 0;
                        $badge_class = get_nilai_badge_class($val);
                        $status_text = get_nilai_status_text($val);
                    ?>
                        <div class="snilai-grade-card">
                            <div class="snilai-grade-left">
                                <div class="snilai-grade-icon <?= $val > 0 ? 'tone-green' : 'tone-slate' ?>">
                                    <?= $no++ ?>
                                </div>
                                <div class="min-w-0">
                                    <div class="snilai-grade-title"><?= htmlspecialchars($mapel['nama_mapel']) ?></div>
                                    <div class="snilai-grade-sub">
                                        <i class="fas fa-tag mr-1 text-muted"></i> <?= htmlspecialchars($selected_jenis) ?>
                                    </div>
                                </div>
                            </div>
                            <div class="snilai-grade-badge <?= $badge_class ?>">
                                <div class="val"><?= $val > 0 ? $val : '-' ?></div>
                                <div class="lbl"><?= $status_text ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Tabel Tersembunyi (Untuk kompatibilitas Ekspor Excel & PDF) -->
            <div class="d-none">
                <table class="table table-bordered table-striped" id="rekapNilaiTable">
                    <thead>
                        <tr>
                            <th width="50" class="text-center">No</th>
                            <th>Mata Pelajaran</th>
                            <th width="150" class="text-center">Nilai</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no_t = 1;
                        foreach ($subjects as $mapel): 
                            $val = $rekap_data[$mapel['id_mapel']] ?? 0;
                        ?>
                            <tr>
                                <td class="text-center"><?= $no_t++ ?></td>
                                <td><?= htmlspecialchars($mapel['nama_mapel']) ?></td>
                                <td class="text-center font-weight-bold">
                                    <?= $val > 0 ? $val : '-' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="bg-light">
                            <th colspan="2" class="text-right">Total Nilai</th>
                            <th class="text-center"><?= $total_nilai ?></th>
                        </tr>
                        <tr class="bg-light">
                            <th colspan="2" class="text-right">Rerata</th>
                            <th class="text-center"><?= $rerata ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </section>
</div>

<?php require_once '../templates/footer.php'; ?>

<script>
var schoolCity = '<?php echo addslashes($school_city); ?>';
var reportDate = '<?php echo addslashes($report_date); ?>';
var schoolName = '<?php echo addslashes($school_name); ?>';
var madrasahHeadName = '<?php echo addslashes($madrasah_head_name); ?>';
var madrasahHeadSignature = '<?php echo addslashes($madrasah_head_signature); ?>';
var waliKelasName = '<?php echo addslashes($student["wali_kelas"] ?? "Wali Kelas"); ?>';
var studentName = '<?php echo addslashes($student["nama_siswa"]); ?>';
var studentKelas = '<?php echo addslashes($student["nama_kelas"]); ?>';
var tahunAjaran = '<?php echo addslashes($tahun_ajaran); ?>';
var semesterAktif = '<?php echo addslashes($semester_aktif); ?>';
var jenisPenilaian = '<?php echo addslashes($selected_jenis); ?>';

function exportToExcel() {
    var container = document.createElement('div');
    var headerDiv = document.createElement('div');
    headerDiv.innerHTML = '<img src="../assets/img/logo_1782822263.png" alt="Logo" style="max-width: 100px; float: left; margin-right: 20px;"><div style="display: inline-block;"><h2>Sistem Informasi Madrasah</h2>';
    headerDiv.innerHTML += '<h3>' + schoolName + '</h3>';
    headerDiv.innerHTML += '<h4>Rekap Nilai: ' + studentName + ' (' + studentKelas + ')</h4>';
    headerDiv.innerHTML += '<h4>Jenis: ' + jenisPenilaian + ' | Tahun: ' + tahunAjaran + ' | Semester: ' + semesterAktif + '</h4></div><br style="clear: both;">';
    
    var table = document.getElementById('rekapNilaiTable');
    if (!table) return;
    var newTable = table.cloneNode(true);
    
    container.appendChild(headerDiv);
    container.appendChild(newTable);
    
    if (typeof XLSX !== 'undefined') {
        var wb = XLSX.utils.book_new();
        var ws = XLSX.utils.table_to_sheet(newTable);
        XLSX.utils.book_append_sheet(wb, ws, "Rekap Nilai");
        XLSX.writeFile(wb, 'rekap_nilai_' + studentName.replace(/\s+/g, '_') + '.xlsx');
    } else {
        var a = document.createElement('a');
        a.href = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(container.innerHTML);
        a.download = 'rekap_nilai_' + studentName.replace(/\s+/g, '_') + '.xls';
        a.click();
    }
}

function exportToPDF() {
    var printWindow = window.open('', '_blank');
    printWindow.document.write('<html><head><title>Rekap Nilai Siswa</title>');
    printWindow.document.write('<style>');
    printWindow.document.write('@page { size: legal portrait; margin: 0.5cm; }');
    printWindow.document.write('body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }');
    printWindow.document.write('table { border-collapse: collapse; width: 100%; font-size: 11px; margin-bottom: 20px; }');
    printWindow.document.write('th, td { border: 1px solid #ddd; padding: 8px; text-align: center; }');
    printWindow.document.write('th { background-color: #f2f2f2; font-weight: bold; }');
    printWindow.document.write('.header { text-align: center; margin-bottom: 20px; position: relative; }');
    printWindow.document.write('.logo { max-width: 80px; position: absolute; left: 0; top: 0; }');
    printWindow.document.write('.signature-wrapper { margin-top: 30px; display: flex; justify-content: space-between; width: 100%; page-break-inside: avoid; }');
    printWindow.document.write('.signature-box { text-align: center; width: 45%; }');
    printWindow.document.write('.print-btn { position: fixed; top: 20px; right: 20px; padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; z-index: 9999; }');
    printWindow.document.write('@media print { .no-print { display: none !important; } }');
    printWindow.document.write('</style></head><body>');
    printWindow.document.write('<button class="print-btn no-print" onclick="window.print()">Cetak / Simpan PDF</button>');
    printWindow.document.write('<div class="header">');
    printWindow.document.write('<img src="../assets/img/logo_1782822263.png" alt="Logo" class="logo">');
    printWindow.document.write('<h2>Sistem Informasi Madrasah</h2>');
    printWindow.document.write('<h3>' + schoolName + '</h3>');
    printWindow.document.write('<h4>Rekap Nilai Siswa: ' + studentName + ' (' + studentKelas + ')</h4>');
    printWindow.document.write('<h4>Jenis: ' + jenisPenilaian + ' | Tahun: ' + tahunAjaran + ' | Semester: ' + semesterAktif + '</h4>');
    printWindow.document.write('</div>');
    
    var table = document.getElementById('rekapNilaiTable').cloneNode(true);
    printWindow.document.write(table.outerHTML);
    
    printWindow.document.write('<div class="signature-wrapper">');
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p>' + schoolCity + ', ' + reportDate + '</p>');
    printWindow.document.write('<p>Wali Kelas,</p>');
    if (waliKelasName) {
        var qr = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' + encodeURIComponent('Validasi Tanda Tangan Digital: ' + waliKelasName + ' - ' + schoolName);
        printWindow.document.write('<img src="' + qr + '" style="width: 80px; height: 80px; margin: 10px auto; display: block;">');
        printWindow.document.write('<p style="font-size: 10px;"></p>');
    } else {
        printWindow.document.write('<br><br><br><br><br>');
    }
    printWindow.document.write('<p><strong>' + waliKelasName + '</strong></p>');
    printWindow.document.write('</div>');
    
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p>' + schoolCity + ', ' + reportDate + '</p>');
    printWindow.document.write('<p>Kepala Madrasah,</p>');
    if (madrasahHeadName) {
        var qrHead = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' + encodeURIComponent('Validasi Tanda Tangan Digital: ' + madrasahHeadName + ' - ' + schoolName);
        printWindow.document.write('<img src="' + qrHead + '" style="width: 80px; height: 80px; margin: 10px auto; display: block;">');
        printWindow.document.write('<p style="font-size: 10px;"></p>');
    } else {
        printWindow.document.write('<br><br><br><br><br>');
    }
    printWindow.document.write('<p><strong>' + madrasahHeadName + '</strong></p>');
    printWindow.document.write('</div>');
    printWindow.document.write('</div>');
    
    printWindow.document.write('</body></html>');
    printWindow.document.close();
}
</script>
