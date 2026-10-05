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

$f_kelas = (int)($_GET['kelas'] ?? 0);
$days_order = function_exists('getUrutanHariJadwalSekolah') ? getUrutanHariJadwalSekolah($pdo) : ['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];

// Profil Madrasah
$school = getSchoolProfile($pdo);
$nama_madrasah = $school['nama_madrasah'] ?? 'Madrasah Ibtidaiyah';
$tahun_ajaran = $school['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1);
$semester = $school['semester'] ?? 'Semester 1';
$tempat_jadwal = $school['tempat_jadwal'] ?? 'Jepara';

// Wali + nama kelas dari data kelas (bukan nama login)
$nama_wali = 'Wali Kelas';
$nama_kelas = '-';
if ($f_kelas > 0) {
    $stK = $pdo->prepare("SELECT nama_kelas, wali_kelas FROM tb_kelas WHERE id_kelas = ?");
    $stK->execute([$f_kelas]);
    if ($rk = $stK->fetch(PDO::FETCH_ASSOC)) {
        $nama_kelas = $rk['nama_kelas'] ?: '-';
        $nama_wali = trim((string)($rk['wali_kelas'] ?? ''));
        if ($nama_wali === '') $nama_wali = 'Wali Kelas';
    }
} else {
    $stG = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
    $stG->execute([$guru_id]);
    $nama_wali = $stG->fetchColumn() ?: ($_SESSION['nama_guru'] ?? 'Wali Kelas');
}

// Query Piket
$where = ["1=1"];
$params = [];
if ($f_kelas > 0) {
    $where[] = "p.id_kelas = ?";
    $params[] = $f_kelas;
}
if ($user_level !== 'admin') {
    $where[] = "p.id_wali = ?";
    $params[] = $guru_id;
}
$where_sql = implode(' AND ', $where);
$field_hari = implode(',', array_map([$pdo, 'quote'], $days_order));
$stmt = $pdo->prepare("
    SELECT p.*, s.nama_siswa, s.nisn, k.nama_kelas
    FROM tb_jadwal_piket_kelas p
    JOIN tb_siswa s ON s.id_siswa = p.id_siswa
    LEFT JOIN tb_kelas k ON k.id_kelas = p.id_kelas
    WHERE $where_sql
    ORDER BY FIELD(p.hari, $field_hari), p.urutan ASC, s.nama_siswa ASC
");
$stmt->execute($params);
$piket_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($piket_list)) {
    echo "<script>alert('Tidak ada data piket untuk diekspor.'); window.history.back();</script>";
    exit;
}

$ta_file = preg_replace('/[^A-Za-z0-9-]+/', '', str_replace('/', '-', $tahun_ajaran));
$kelas_safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$nama_kelas);
$title = "JADWAL PIKET KELAS " . strtoupper((string)$nama_kelas) . " - TAHUN AJARAN " . $tahun_ajaran;
$filename = "Jadwal_Piket_Kelas_" . $kelas_safe . "_TA" . $ta_file . "_" . date('Ymd') . ".xlsx";

$ss = new Spreadsheet();
$sh = $ss->getActiveSheet();
$sh->setTitle('Jadwal Piket');

$sh->setCellValue('A1', strtoupper($nama_madrasah));
$sh->setCellValue('A2', $title);
$sh->setCellValue('A3', 'Tahun Ajaran: ' . $tahun_ajaran . ' (' . $semester . ') | Wali Kelas: ' . $nama_wali . ' | Dicetak: ' . date('d/m/Y H:i'));

$sh->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sh->getStyle('A2')->getFont()->setBold(true)->setSize(12);
$sh->getStyle('A3')->getFont()->setItalic(true)->setSize(10);

$row = 5;
$headers = ['No', 'Hari', 'Nama Siswa', 'NISN', 'Kelas', 'Status'];
$lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
foreach ($headers as $idx => $h) {
    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
    $sh->setCellValue($colLetter . $row, $h);
}
$sh->getStyle('A' . $row . ':' . $lastCol . $row)->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);

$row++;
$startDataRow = $row;
$no = 1;
foreach ($piket_list as $c) {
    $sh->setCellValue('A' . $row, $no++);
    $sh->setCellValue('B' . $row, $c['hari']);
    $sh->setCellValue('C' . $row, $c['nama_siswa']);
    $sh->setCellValue('D' . $row, !empty($c['nisn']) ? ' ' . $c['nisn'] : '-');
    $sh->setCellValue('E' . $row, 'Kelas ' . ($c['nama_kelas'] ?? '-'));
    $sh->setCellValue('F' . $row, $c['status']);
    $sh->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('F' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $row++;
}
$endDataRow = $row - 1;
$sh->getStyle('A' . ($startDataRow - 1) . ':' . $lastCol . $endDataRow)->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']]],
]);
for ($i = 1; $i <= count($headers); $i++) {
    $sh->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
}
$sh->getColumnDimension('F')->setAutoSize(false);
$sh->getColumnDimension('F')->setWidth(40);
$sh->getStyle('F' . $startDataRow . ':F' . $endDataRow)->getAlignment()->setWrapText(true);

$row += 2;
$sigCol = 'F';
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
