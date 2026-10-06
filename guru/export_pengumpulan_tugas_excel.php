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

if (!isAuthorized(['guru', 'wali', 'admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$id_tugas = (int)($_GET['id'] ?? 0);
if ($id_tugas <= 0) {
    echo "<script>alert('ID Tugas tidak valid.'); window.history.back();</script>";
    exit;
}

$wali_kelas_id = 0;
if ($user_level !== 'admin' && $user_level !== 'kepala_madrasah') {
    try {
        $stWali = $pdo->prepare("SELECT id_kelas FROM tb_kelas WHERE wali_kelas = ? OR wali_kelas = (SELECT nama_guru FROM tb_guru WHERE id_guru = ?)");
        $stWali->execute([$guru_id, $guru_id]);
        $wali_kelas_id = (int)$stWali->fetchColumn() ?: 0;
    } catch (Throwable $e) {}
}

if ($user_level === 'admin' || $user_level === 'kepala_madrasah') {
    $auth_sql = "t.id = ?";
    $auth_params = [$id_tugas];
} else {
    $auth_sql = "t.id = ? AND (t.id_guru = ? " . ($wali_kelas_id > 0 ? "OR t.id_kelas = $wali_kelas_id" : "") . ")";
    $auth_params = [$id_tugas, $guru_id];
}

$st = $pdo->prepare("
    SELECT t.*, m.nama_mapel, k.nama_kelas, g.nama_guru
    FROM tb_tugas t
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = t.id_mapel
    LEFT JOIN tb_kelas k ON k.id_kelas = t.id_kelas
    LEFT JOIN tb_guru g ON g.id_guru = t.id_guru
    WHERE $auth_sql
    LIMIT 1
");
$st->execute($auth_params);
$tugas = $st->fetch(PDO::FETCH_ASSOC);
if (!$tugas) {
    echo "<script>alert('Tugas tidak ditemukan atau Anda tidak berhak mengaksesnya.'); window.history.back();</script>";
    exit;
}

$stS = $pdo->prepare("
    SELECT s.nama_siswa, s.nisn,
           tp.tgl_kumpul, tp.nilai, tp.status_periksa
    FROM tb_siswa s
    LEFT JOIN tb_tugas_pengumpulan tp ON tp.id_siswa = s.id_siswa AND tp.id_tugas = ?
    WHERE s.id_kelas = ?
    ORDER BY s.nama_siswa ASC
");
$stS->execute([$id_tugas, $tugas['id_kelas']]);
$rows = $stS->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "<script>alert('Tidak ada data siswa untuk diekspor.'); window.history.back();</script>";
    exit;
}

$deadline_ts = !empty($tugas['deadline']) ? strtotime($tugas['deadline']) : null;

$school = getSchoolProfile($pdo);
$nama_madrasah = $school['nama_madrasah'] ?? 'Madrasah Ibtidaiyah';
$tahun_ajaran = $school['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1);
$semester = $school['semester'] ?? 'Semester 1';
$tempat_jadwal = $school['tempat_jadwal'] ?? 'Jepara';
$guru_mapel = $tugas['nama_guru'] ?? '-';

$ta_file = preg_replace('/[^A-Za-z0-9-]+/', '', str_replace('/', '-', $tahun_ajaran));
$title = "REKAPITULASI PENGUMPULAN TUGAS - " . strtoupper((string)$tugas['judul']);
$filename = "Rekap_Pengumpulan_Tugas_" . (int)$tugas['id'] . "_TA" . $ta_file . "_" . date('Ymd') . ".xlsx";

$ss = new Spreadsheet();
$sh = $ss->getActiveSheet();
$sh->setTitle('Pengumpulan Tugas');

$sh->setCellValue('A1', strtoupper($nama_madrasah));
$sh->setCellValue('A2', $title);
$sh->setCellValue('A3', 'Tugas: ' . $tugas['judul'] . ' | Mapel: ' . ($tugas['nama_mapel'] ?? '-') . ' | Kelas: ' . ($tugas['nama_kelas'] ?? '-') . ' | Guru: ' . $guru_mapel);
$sh->setCellValue('A4', 'Tahun Ajaran: ' . $tahun_ajaran . ' (' . $semester . ') | Deadline: ' . (!empty($tugas['deadline']) ? date('d/m/Y H:i', strtotime($tugas['deadline'])) : '-') . ' | Dicetak: ' . date('d/m/Y H:i'));

$sh->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sh->getStyle('A2')->getFont()->setBold(true)->setSize(12);
$sh->getStyle('A3')->getFont()->setSize(10);
$sh->getStyle('A4')->getFont()->setItalic(true)->setSize(10);

$row = 6;
$headers = ['No', 'Nama Siswa', 'NISN', 'Status Kumpul', 'Tanggal Kumpul', 'Keterlambatan', 'Nilai', 'Status Pemeriksaan'];
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
foreach ($rows as $r) {
    $is_kumpul = !empty($r['tgl_kumpul']);
    $late = '-';
    if ($is_kumpul && $deadline_ts) {
        $diff = strtotime($r['tgl_kumpul']) - $deadline_ts;
        if ($diff > 0) {
            $jam = floor($diff / 3600);
            $late = $jam > 24 ? ('Telat ' . floor($jam / 24) . ' hari') : ('Telat ' . $jam . ' jam');
        } else {
            $late = 'Tepat Waktu';
        }
    }
    $sh->setCellValue('A' . $row, $no++);
    $sh->setCellValue('B' . $row, $r['nama_siswa']);
    $sh->setCellValue('C' . $row, !empty($r['nisn']) ? ' ' . $r['nisn'] : '-');
    $sh->setCellValue('D' . $row, $is_kumpul ? 'Sudah' : 'Belum');
    $sh->setCellValue('E' . $row, $is_kumpul ? date('d/m/Y H:i', strtotime($r['tgl_kumpul'])) : '-');
    $sh->setCellValue('F' . $row, $late);
    $sh->setCellValue('G' . $row, ($r['nilai'] !== null && $r['nilai'] !== '') ? (float)$r['nilai'] : '-');
    $sh->setCellValue('H' . $row, $r['status_periksa'] ?? 'Belum Diperiksa');
    $sh->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('F' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('G' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('H' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $row++;
}
$endDataRow = $row - 1;
$sh->getStyle('A' . ($startDataRow - 1) . ':' . $lastCol . $endDataRow)->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']]],
]);
for ($i = 1; $i <= count($headers); $i++) {
    $sh->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
}

$row += 2;
$sigCol = 'H';
$sh->setCellValue($sigCol . $row, $tempat_jadwal . ', ' . date('d F Y'));
$row++;
$sh->setCellValue($sigCol . $row, 'Guru Pengampu,');
$row += 4;
$sh->setCellValue($sigCol . $row, $guru_mapel);
$sh->getStyle($sigCol . $row)->getFont()->setBold(true)->setUnderline(true);

while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');
$writer = new Xlsx($ss);
$writer->save('php://output');
exit;
