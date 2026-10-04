<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

ensure_learning_schema($pdo);

if (!isAuthorized(['wali', 'admin'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$id_siswa = (int)($_GET['id_siswa'] ?? 0);
$id_catatan = (int)($_GET['id'] ?? 0);
$f_sumber = trim((string)($_GET['f_sumber'] ?? ''));
$f_kelas = (int)($_GET['kelas'] ?? 0);
$f_status = trim((string)($_GET['f_status'] ?? ''));

// Profil Madrasah
$school = getSchoolProfile($pdo);
$nama_madrasah = $school['nama_madrasah'] ?? 'Madrasah Ibtidaiyah';
$tahun_ajaran = $school['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1);
$semester = $school['semester'] ?? 'Semester 1';
$tempat_jadwal = $school['tempat_jadwal'] ?? 'Jepara';

// Profil Wali Login
$stG = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
$stG->execute([$guru_id]);
$nama_wali = $stG->fetchColumn() ?: ($_SESSION['nama_guru'] ?? 'Wali Kelas');

// Query Tindak Lanjut
$where = ["1=1"];
$params = [];

if ($id_catatan > 0) {
    $where[] = "t.id = ?";
    $params[] = $id_catatan;
} elseif ($id_siswa > 0) {
    $where[] = "t.id_siswa = ?";
    $params[] = $id_siswa;
} else {
    if ($f_kelas > 0) {
        $where[] = "t.id_kelas = ?";
        $params[] = $f_kelas;
    }
    if ($f_sumber !== '') {
        $where[] = "t.sumber = ?";
        $params[] = $f_sumber;
    }
    if ($f_status !== '') {
        $where[] = "t.status = ?";
        $params[] = $f_status;
    }
}
if ($user_level !== 'admin') {
    $where[] = "t.id_wali = ?";
    $params[] = $guru_id;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT t.*, s.nama_siswa, s.nisn, k.nama_kelas, g.nama_guru,
           b.permasalahan AS b_masalah, b.tanggal AS b_tanggal,
           c.topik AS c_topik, c.ringkasan_masalah AS c_masalah, c.tanggal AS c_tanggal
    FROM tb_tindak_lanjut_wali t
    JOIN tb_siswa s ON s.id_siswa = t.id_siswa
    LEFT JOIN tb_kelas k ON k.id_kelas = t.id_kelas
    LEFT JOIN tb_guru g ON g.id_guru = t.id_wali
    LEFT JOIN tb_pembinaan_siswa b ON b.id = t.id_pembinaan
    LEFT JOIN tb_konseling_awal c ON c.id = t.id_konseling
    WHERE $where_sql
    ORDER BY s.nama_siswa ASC, t.tanggal DESC, t.id DESC
");
$stmt->execute($params);
$tl_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($tl_list)) {
    echo "<script>alert('Tidak ada data tindak lanjut untuk diekspor.'); window.history.back();</script>";
    exit;
}

$is_single = ($id_siswa > 0 || $id_catatan > 0) && count(array_unique(array_column($tl_list, 'id_siswa'))) === 1;
$first_item = $tl_list[0];

$ta_file = preg_replace('/[^A-Za-z0-9-]+/', '', str_replace('/', '-', $tahun_ajaran));
if ($is_single) {
    $nama_safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $first_item['nama_siswa']);
    $title = "LAPORAN TINDAK LANJUT SISWA - " . strtoupper($first_item['nama_siswa']) . " - TAHUN AJARAN " . $tahun_ajaran;
    $filename = "Laporan_Tindak_Lanjut_" . $nama_safe . "_TA" . $ta_file . "_" . date('Ymd') . ".xlsx";
} else {
    $title = "REKAPITULASI TINDAK LANJUT SISWA - TAHUN AJARAN " . $tahun_ajaran;
    $filename = "Rekap_Tindak_Lanjut_TA" . $ta_file . "_" . date('Ymd') . ".xlsx";
}

$ss = new Spreadsheet();
$sh = $ss->getActiveSheet();
$sh->setTitle('Tindak Lanjut');

// Header Dokumen
$sh->setCellValue('A1', strtoupper($nama_madrasah));
$sh->setCellValue('A2', $title);
$sh->setCellValue('A3', 'Tahun Ajaran: ' . $tahun_ajaran . ' (' . $semester . ') | Wali Kelas: ' . $nama_wali . ' | Dicetak: ' . date('d/m/Y H:i'));

$sh->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sh->getStyle('A2')->getFont()->setBold(true)->setSize(12);
$sh->getStyle('A3')->getFont()->setItalic(true)->setSize(10);

$row = 5;

// Header Kolom Tabel
$headers = [
    'No',
    'Tanggal',
    'Nama Siswa',
    'NISN',
    'Kelas',
    'Sumber',
    'Rincian Sumber (Pembinaan / Konseling)',
    'Tindakan / Langkah Perbaikan',
    'Penanggung Jawab',
    'Target Selesai',
    'Tanggal Selesai',
    'Status'
];

$lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));

foreach ($headers as $idx => $h) {
    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
    $sh->setCellValue($colLetter . $row, $h);
}

$sh->getStyle('A' . $row . ':' . $lastCol . $row)->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A8A']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);

$row++;
$startDataRow = $row;
$no = 1;

foreach ($tl_list as $c) {
    $srcNote = '-';
    if ($c['sumber'] === 'Konseling' && !empty($c['c_topik'])) {
        $srcNote = $c['c_topik'] . ' - ' . ($c['c_masalah'] ?? '') . ' (' . ($c['c_tanggal'] ?? '-') . ')';
    } elseif (!empty($c['b_masalah'])) {
        $srcNote = $c['b_masalah'] . ' (' . ($c['b_tanggal'] ?? '-') . ')';
    }
    $sh->setCellValue('A' . $row, $no++);
    $sh->setCellValue('B' . $row, date('d/m/Y', strtotime($c['tanggal'])));
    $sh->setCellValue('C' . $row, $c['nama_siswa']);
    $sh->setCellValue('D' . $row, !empty($c['nisn']) ? ' ' . $c['nisn'] : '-');
    $sh->setCellValue('E' . $row, 'Kelas ' . ($c['nama_kelas'] ?? '-'));
    $sh->setCellValue('F' . $row, $c['sumber']);
    $sh->setCellValue('G' . $row, $srcNote);
    $sh->setCellValue('H' . $row, $c['tindakan']);
    $sh->setCellValue('I' . $row, $c['penanggung_jawab']);
    $sh->setCellValue('J' . $row, !empty($c['target_selesai']) ? date('d/m/Y', strtotime($c['target_selesai'])) : '-');
    $sh->setCellValue('K' . $row, !empty($c['tanggal_selesai']) ? date('d/m/Y', strtotime($c['tanggal_selesai'])) : '-');
    $sh->setCellValue('L' . $row, $c['status']);

    $sh->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('F' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('J' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('K' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('L' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $row++;
}

$endDataRow = $row - 1;

// Border Tabel
$sh->getStyle('A' . ($startDataRow - 1) . ':' . $lastCol . $endDataRow)->applyFromArray([
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '94A3B8'],
        ],
    ],
]);

// Set auto size columns
for ($i = 1; $i <= count($headers); $i++) {
    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
    $sh->getColumnDimension($colLetter)->setAutoSize(true);
}
// Lebar maksimum untuk teks panjang
foreach (['G', 'H'] as $cWrap) {
    $sh->getColumnDimension($cWrap)->setAutoSize(false);
    $sh->getColumnDimension($cWrap)->setWidth(45);
    $sh->getStyle($cWrap . $startDataRow . ':' . $cWrap . $endDataRow)->getAlignment()->setWrapText(true);
}

// Tanda Tangan
$row += 2;
$sigCol = 'H';
$sh->setCellValue($sigCol . $row, $tempat_jadwal . ', ' . date('d F Y'));
$row++;
$sh->setCellValue($sigCol . $row, 'Wali Kelas,');
$row += 4;
$sh->setCellValue($sigCol . $row, $nama_wali);
$sh->getStyle($sigCol . $row)->getFont()->setBold(true)->setUnderline(true);

while (ob_get_level()) { ob_end_clean(); }

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($ss);
$writer->save('php://output');
exit;
