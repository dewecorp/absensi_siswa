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

if (!isAuthorized(['guru', 'wali', 'admin', 'tata_usaha', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$id_siswa = (int)($_GET['id_siswa'] ?? 0);
$id_catatan = (int)($_GET['id'] ?? 0);
$f_kelas = (int)($_GET['f_kelas'] ?? 0);
$f_mapel = (int)($_GET['f_mapel'] ?? 0);
$f_kategori = trim((string)($_GET['f_kategori'] ?? ''));
$f_status = trim((string)($_GET['f_status'] ?? ''));

// Profil Madrasah
$school = getSchoolProfile($pdo);
$nama_madrasah = $school['nama_madrasah'] ?? 'Madrasah Ibtidaiyah';
$tahun_ajaran = $school['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1);
$semester = $school['semester'] ?? 'Semester 1';
$tempat_jadwal = $school['tempat_jadwal'] ?? 'Jepara';
$kepala_madrasah = $school['kepala_madrasah'] ?? '-';
$nip_kepala = $school['nip_kepala'] ?? '-';

// Profil Guru Login
$stG = $pdo->prepare("SELECT nama_guru, nuptk FROM tb_guru WHERE id_guru = ?");
$stG->execute([$guru_id]);
$guru_info = $stG->fetch(PDO::FETCH_ASSOC);
$nama_guru = $guru_info['nama_guru'] ?? ($_SESSION['nama_guru'] ?? 'Guru Pengampu');
$nip_guru = $guru_info['nuptk'] ?? '-';

// Query Catatan
$where = ["c.id_guru = ?"];
$params = [$guru_id];

if ($id_catatan > 0) {
    $where[] = "c.id = ?";
    $params[] = $id_catatan;
} elseif ($id_siswa > 0) {
    $where[] = "c.id_siswa = ?";
    $params[] = $id_siswa;
} else {
    if ($f_kelas > 0) {
        $where[] = "c.id_kelas = ?";
        $params[] = $f_kelas;
    }
    if ($f_mapel > 0) {
        $where[] = "c.id_mapel = ?";
        $params[] = $f_mapel;
    }
    if ($f_kategori !== '') {
        $where[] = "c.kategori = ?";
        $params[] = $f_kategori;
    }
    if ($f_status !== '') {
        $where[] = "c.status = ?";
        $params[] = $f_status;
    }
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT c.*, s.nama_siswa, s.nisn, s.jenis_kelamin, k.nama_kelas, m.nama_mapel, g.nama_guru
    FROM tb_catatan_perkembangan c
    JOIN tb_siswa s ON s.id_siswa = c.id_siswa
    LEFT JOIN tb_kelas k ON k.id_kelas = c.id_kelas
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = c.id_mapel
    LEFT JOIN tb_guru g ON g.id_guru = c.id_guru
    WHERE $where_sql
    ORDER BY s.nama_siswa ASC, c.tanggal DESC, c.id DESC
");
$stmt->execute($params);
$catatan_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($catatan_list)) {
    echo "<script>alert('Tidak ada data catatan perkembangan untuk diekspor.'); window.history.back();</script>";
    exit;
}

$is_single = ($id_siswa > 0 || $id_catatan > 0) && count(array_unique(array_column($catatan_list, 'id_siswa'))) === 1;
$first_item = $catatan_list[0];

$ta_file = preg_replace('/[^A-Za-z0-9-]+/', '', str_replace('/', '-', $tahun_ajaran));
if ($is_single) {
    $nama_safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $first_item['nama_siswa']);
    $title = "LAPORAN PERKEMBANGAN SISWA - " . strtoupper($first_item['nama_siswa']) . " - TAHUN AJARAN " . $tahun_ajaran;
    $filename = "Laporan_Perkembangan_" . $nama_safe . "_TA" . $ta_file . "_" . date('Ymd') . ".xlsx";
} else {
    $title = "REKAPITULASI CATATAN PERKEMBANGAN SISWA - TAHUN AJARAN " . $tahun_ajaran;
    $filename = "Rekap_Catatan_Perkembangan_TA" . $ta_file . "_" . date('Ymd') . ".xlsx";
}

$ss = new Spreadsheet();
$sh = $ss->getActiveSheet();
$sh->setTitle('Catatan Perkembangan');

// Header Dokumen
$sh->setCellValue('A1', strtoupper($nama_madrasah));
$sh->setCellValue('A2', $title);
$sh->setCellValue('A3', 'Tahun Ajaran: ' . $tahun_ajaran . ' (' . $semester . ') | Guru: ' . $nama_guru . ' | Dicetak: ' . date('d/m/Y H:i'));

$sh->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sh->getStyle('A2')->getFont()->setBold(true)->setSize(12);
$sh->getStyle('A3')->getFont()->setItalic(true)->setSize(10);

$row = 5;

// Header Kolom Tabel (tanpa kolom Status)
$headers = [
    'No',
    'Tanggal',
    'Nama Siswa',
    'NISN',
    'Kelas',
    'Mata Pelajaran',
    'Aspek Perkembangan',
    'Ringkasan Perkembangan',
    'Kendala Belajar',
    'Tindak Lanjut Guru',
    'Perkembangan Akademik',
    'Perkembangan Sikap & Karakter',
    'Perkembangan Keterampilan',
    'Keaktifan Partisipasi',
    'Bakat / Potensi',
    'Rekomendasi / Catatan Guru'
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

foreach ($catatan_list as $c) {
    $sh->setCellValue('A' . $row, $no++);
    $sh->setCellValue('B' . $row, date('d/m/Y', strtotime($c['tanggal'])));
    $sh->setCellValue('C' . $row, $c['nama_siswa']);
    $sh->setCellValue('D' . $row, !empty($c['nisn']) ? ' ' . $c['nisn'] : '-');
    $sh->setCellValue('E' . $row, 'Kelas ' . ($c['nama_kelas'] ?? '-'));
    $sh->setCellValue('F' . $row, $c['nama_mapel'] ?? 'Umum');
    $sh->setCellValue('G' . $row, $c['kategori']);
    $sh->setCellValue('H' . $row, $c['ringkasan']);
    $sh->setCellValue('I' . $row, !empty($c['kendala']) ? $c['kendala'] : '-');
    $sh->setCellValue('J' . $row, !empty($c['tindak_lanjut']) ? $c['tindak_lanjut'] : '-');
    $sh->setCellValue('K' . $row, !empty($c['perkembangan_akademik']) ? $c['perkembangan_akademik'] : '-');
    $sh->setCellValue('L' . $row, !empty($c['perkembangan_sikap']) ? $c['perkembangan_sikap'] : '-');
    $sh->setCellValue('M' . $row, !empty($c['perkembangan_keterampilan']) ? $c['perkembangan_keterampilan'] : '-');
    $sh->setCellValue('N' . $row, !empty($c['keaktifan']) ? $c['keaktifan'] : '-');
    $sh->setCellValue('O' . $row, !empty($c['potensi']) ? $c['potensi'] : '-');
    $sh->setCellValue('P' . $row, !empty($c['rekomendasi']) ? $c['rekomendasi'] : '-');

    $sh->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('G' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

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
foreach (['H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P'] as $cWrap) {
    $sh->getColumnDimension($cWrap)->setAutoSize(false);
    $sh->getColumnDimension($cWrap)->setWidth(35);
    $sh->getStyle($cWrap . $startDataRow . ':' . $cWrap . $endDataRow)->getAlignment()->setWrapText(true);
}

// Tanda Tangan
$row += 2;
$sigCol = 'N';
$sh->setCellValue($sigCol . $row, $tempat_jadwal . ', ' . date('d F Y'));
$row++;
$sh->setCellValue($sigCol . $row, 'Guru Pengampu / Wali Kelas,');
$row += 4;
$sh->setCellValue($sigCol . $row, $nama_guru);
$sh->getStyle($sigCol . $row)->getFont()->setBold(true)->setUnderline(true);

while (ob_get_level()) { ob_end_clean(); }

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($ss);
$writer->save('php://output');
exit;
