<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['siswa'])) {
    redirect('../login.php');
}

$page_title = 'Rekap Nilai Saya';

// Get student data
$id_siswa = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT s.*, k.nama_kelas, k.id_kelas, k.wali_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_siswa = ?");
$stmt->execute([$id_siswa]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    echo "Data siswa tidak ditemukan.";
    exit;
}

$id_kelas = $student['id_kelas'];

// Get Active Semester
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'];
$semester_aktif = $school_profile['semester'];
$school_city = $school_profile['tempat_jadwal'] ?? 'Kota Padang';
$report_date = formatDateIndonesia(date('Y-m-d'));
$school_name = $school_profile['nama_madrasah'] ?? 'Madrasah';
$madrasah_head_name = $school_profile['kepala_madrasah'] ?? 'Kepala Madrasah';
$madrasah_head_signature = $school_profile['ttd_kepala'] ?? '';

// Parameters
$selected_jenis = isset($_GET['jenis']) ? $_GET['jenis'] : 'Harian';
$selected_tipe = isset($_GET['tipe']) ? $_GET['tipe'] : 'nilai_jadi'; // nilai_asli or nilai_jadi

// Define JS libraries for this page
$js_libs = [
    "https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"
];

// Get Subjects (Mapel) - Filtered (Non-Academic)
$stmt = $pdo->query("SELECT * FROM tb_mata_pelajaran 
    WHERE nama_mapel NOT LIKE '%Asmaul Husna%'
    AND nama_mapel NOT LIKE '%Upacara%'
    AND nama_mapel NOT LIKE '%Istirahat%'
    AND nama_mapel NOT LIKE '%Kepramukaan%'
    AND nama_mapel NOT LIKE '%Ekstrakurikuler%'
    ORDER BY nama_mapel ASC");
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Grades
$rekap_data = [];
$total_nilai = 0;
$count_mapel = 0;

foreach ($subjects as $mapel) {
    $nilai = 0;
    
    if ($selected_jenis == 'Harian') {
        // Logic for Nilai Harian (Average of all PH columns)
        $stmt = $pdo->prepare("
            SELECT d.* 
            FROM tb_nilai_harian_detail d
            JOIN tb_nilai_harian_header h ON d.id_header = h.id_header
            WHERE h.id_kelas = ? AND h.id_mapel = ?
            AND h.tahun_ajaran = ? AND h.semester = ?
            AND d.id_siswa = ?
        ");
        $stmt->execute([$id_kelas, $mapel['id_mapel'], $tahun_ajaran, $semester_aktif, $id_siswa]);
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($details)) {
            $sum = 0;
            $count = 0;
            foreach ($details as $d) {
                $val = ($selected_tipe == 'nilai_asli') ? $d['nilai'] : $d['nilai_jadi'];
                if ($val > 0) {
                    $sum += $val;
                    $count++;
                }
            }
            if ($count > 0) {
                $nilai = round($sum / $count);
            }
        }
    } else {
        // Logic for Semester (UTS, UAS, PAT, Pra Ujian, Ujian, Kokurikuler)
        $stmt = $pdo->prepare("
            SELECT * FROM tb_nilai_semester 
            WHERE id_kelas = ? AND id_mapel = ? 
            AND jenis_semester = ? AND tahun_ajaran = ? AND semester = ?
            AND id_siswa = ?
        ");
        $stmt->execute([$id_kelas, $mapel['id_mapel'], $selected_jenis, $tahun_ajaran, $semester_aktif, $id_siswa]);
        $grade = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($grade) {
            $val = ($selected_tipe == 'nilai_asli') ? $grade['nilai_asli'] : $grade['nilai_jadi'];
            $nilai = $val > 0 ? (float)$val : 0;
        }
    }

    $rekap_data[$mapel['id_mapel']] = $nilai;
    
    if ($nilai > 0) {
        $total_nilai += $nilai;
        $count_mapel++;
    }
}

$rerata = $count_mapel > 0 ? round($total_nilai / $count_mapel, 1) : 0;

require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><?= $page_title ?></h1>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th width="150">Nama Siswa</th>
                                    <td>: <?= htmlspecialchars($student['nama_siswa']) ?></td>
                                </tr>
                                <tr>
                                    <th>Kelas</th>
                                    <td>: <?= htmlspecialchars($student['nama_kelas']) ?></td>
                                </tr>
                                <tr>
                                    <th>Tahun Ajaran</th>
                                    <td>: <?= htmlspecialchars($tahun_ajaran) ?> (Semester <?= htmlspecialchars($semester_aktif) ?>)</td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6 text-right">
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-success" onclick="exportToExcel()">
                                    <i class="fas fa-file-excel"></i> Ekspor Excel
                                </button>
                                <button type="button" class="btn btn-warning" onclick="exportToPDF()">
                                    <i class="fas fa-file-pdf"></i> Ekspor PDF
                                </button>
                            </div>
                        </div>
                    </div>

                    <form method="GET" action="" class="mb-4">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Jenis Penilaian</label>
                                    <select name="jenis" class="form-control" onchange="this.form.submit()">
                                        <option value="Harian" <?= $selected_jenis == 'Harian' ? 'selected' : '' ?>>Nilai Harian (Rerata)</option>
                                        <option value="UTS" <?= $selected_jenis == 'UTS' ? 'selected' : '' ?>>Nilai Tengah Semester (UTS)</option>
                                        <option value="UAS" <?= $selected_jenis == 'UAS' ? 'selected' : '' ?>>Nilai Akhir Semester (UAS)</option>
                                        <option value="PAT" <?= $selected_jenis == 'PAT' ? 'selected' : '' ?>>Nilai Akhir Tahun (PAT)</option>
                                        <option value="Kokurikuler" <?= $selected_jenis == 'Kokurikuler' ? 'selected' : '' ?>>Nilai Kokurikuler</option>
                                        
                                        <?php
                                        // Check Grade 6 for additional options
                                        $nk = strtoupper($student['nama_kelas']);
                                        if (strpos($nk, '6') !== false || strpos($nk, 'VI') !== false):
                                        ?>
                                            <option value="Pra Ujian" <?= $selected_jenis == 'Pra Ujian' ? 'selected' : '' ?>>Nilai Pra Ujian</option>
                                            <option value="Ujian" <?= $selected_jenis == 'Ujian' ? 'selected' : '' ?>>Nilai Ujian</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Tipe Nilai</label>
                                    <select name="tipe" class="form-control" onchange="this.form.submit()">
                                        <option value="nilai_jadi" <?= $selected_tipe == 'nilai_jadi' ? 'selected' : '' ?>>Nilai Jadi (Raport)</option>
                                        <option value="nilai_asli" <?= $selected_tipe == 'nilai_asli' ? 'selected' : '' ?>>Nilai Asli</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </form>

                    <div class="table-responsive">
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
                                $no = 1;
                                foreach ($subjects as $mapel): 
                                    $val = $rekap_data[$mapel['id_mapel']] ?? 0;
                                ?>
                                    <tr>
                                        <td class="text-center"><?= $no++ ?></td>
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
    headerDiv.innerHTML = '<img src="../assets/img/logo_1768301957.png" alt="Logo" style="max-width: 100px; float: left; margin-right: 20px;"><div style="display: inline-block;"><h2>Sistem Absensi Siswa</h2>';
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
    printWindow.document.write('<img src="../assets/img/logo_1768301957.png" alt="Logo" class="logo">');
    printWindow.document.write('<h2>Sistem Absensi Siswa</h2>');
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
        printWindow.document.write('<p style="font-size: 10px;">(Ditandatangani secara digital)</p>');
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
        printWindow.document.write('<p style="font-size: 10px;">(Ditandatangani secara digital)</p>');
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
