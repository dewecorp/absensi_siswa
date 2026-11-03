<?php
/**
 * Export Data Buku Ajar — Excel (.xlsx) via PhpSpreadsheet
 */
@ini_set('display_errors', '0');
error_reporting(0);

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

$user_level = getUserLevel();
$session_type = $_GET['session_type'] ?? '';
$effective_level = $session_type ?: $user_level;
$is_admin_or_viewer = in_array($effective_level, ['admin', 'kepala_madrasah', 'tata_usaha'], true);
$is_wali = ($effective_level === 'wali');

$school_profile = getSchoolProfile($pdo);
$tahun_ajaran_aktif = $school_profile['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1);
$semester_aktif = $school_profile['semester'] ?? 'Semester 1';

$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

// Cari kelas wali
$wali_kelas_id = null;
$nama_guru_val = '';
if ($guru_id > 0) {
    $stmt_g = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
    $stmt_g->execute([$guru_id]);
    $nama_guru_val = $stmt_g->fetchColumn() ?: '';
}
if ($is_wali && $nama_guru_val) {
    $stmt_wali = $pdo->prepare("SELECT id_kelas FROM tb_kelas WHERE LOWER(TRIM(wali_kelas)) = LOWER(TRIM(?)) LIMIT 1");
    $stmt_wali->execute([$nama_guru_val]);
    $wali_kelas_id = $stmt_wali->fetchColumn() ?: null;
}

// Filters
$f_mapel = (int)($_GET['f_mapel'] ?? 0);
$f_kelas = (int)($_GET['f_kelas'] ?? 0);
$f_jenis = trim((string)($_GET['f_jenis'] ?? ''));
$f_semester = trim((string)($_GET['f_semester'] ?? ''));

$where = [];
$params = [];

if ($is_admin_or_viewer) {
    if ($f_kelas > 0) {
        $where[] = "b.id_kelas = ?";
        $params[] = $f_kelas;
    }
} elseif ($is_wali && $wali_kelas_id) {
    if ($f_kelas > 0) {
        $where[] = "b.id_kelas = ? AND (b.id_kelas = ? OR b.id_guru = ?)";
        $params[] = $f_kelas;
        $params[] = $wali_kelas_id;
        $params[] = $guru_id;
    } else {
        $where[] = "(b.id_kelas = ? OR b.id_guru = ?)";
        $params[] = $wali_kelas_id;
        $params[] = $guru_id;
    }
} else {
    $where[] = "b.id_guru = ?";
    $params[] = $guru_id;
    if ($f_kelas > 0) {
        $where[] = "b.id_kelas = ?";
        $params[] = $f_kelas;
    }
}

if ($f_mapel > 0) { $where[] = "b.id_mapel = ?"; $params[] = $f_mapel; }
if ($f_jenis !== '') { $where[] = "b.jenis_buku = ?"; $params[] = $f_jenis; }
if ($f_semester !== '') { $where[] = "b.semester = ?"; $params[] = $f_semester; }

$where_sql = !empty($where) ? implode(' AND ', $where) : "1=1";
$stmt = $pdo->prepare("
    SELECT b.*, m.nama_mapel, k.nama_kelas, g.nama_guru
    FROM tb_buku_ajar b
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = b.id_mapel
    LEFT JOIN tb_kelas k ON k.id_kelas = b.id_kelas
    LEFT JOIN tb_guru g ON g.id_guru = b.id_guru
    WHERE $where_sql
    ORDER BY m.nama_mapel ASC, b.judul ASC
");
$stmt->execute($params);
$buku_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Kelas name
$kelas_name = '';
if ($f_kelas > 0) {
    $stmt_k = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas = ? LIMIT 1");
    $stmt_k->execute([$f_kelas]);
    $kelas_name = (string)($stmt_k->fetchColumn() ?: '');
} elseif ($is_wali && $wali_kelas_id) {
    $stmt_k = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas = ? LIMIT 1");
    $stmt_k->execute([$wali_kelas_id]);
    $kelas_name = (string)($stmt_k->fetchColumn() ?: '');
} else {
    $buku_kelas_list = array_values(array_unique(array_filter(array_map(static fn($r) => trim((string)($r['nama_kelas'] ?? '')), $buku_rows))));
    if (!empty($buku_kelas_list)) {
        $kelas_name = implode(', ', $buku_kelas_list);
    } elseif ($guru_id > 0 && function_exists('getGuruTaughtClasses')) {
        $taught = getGuruTaughtClasses($pdo, $guru_id);
        $taught_names = array_values(array_unique(array_filter(array_map(static fn($c) => trim((string)($c['nama_kelas'] ?? '')), $taught))));
        if (!empty($taught_names)) {
            $kelas_name = implode(', ', $taught_names);
        }
    }
}

// Spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Data Buku Ajar');

// Jumlah kolom
$colCount = $is_admin_or_viewer ? 7 : 6; // A=No, B=Mapel, C=Judul, D=Penyusun, E=Jenis, F=Kelas (+G=Guru)
$lastColLetters = ['','A','B','C','D','E','F','G'];
$lastCol = $lastColLetters[$colCount];

// ---- HEADER SEKOLAH ----
$sheet->setCellValue('A1', strtoupper($school_profile['nama_yayasan'] ?? ''));
$sheet->setCellValue('A2', strtoupper($school_profile['nama_madrasah'] ?? ''));
$sheet->setCellValue('A3', $school_profile['alamat'] ?? '');

$sheet->getStyle('A1')->getFont()->setSize(11);
$sheet->getStyle('A2')->getFont()->setSize(13)->setBold(true);
$sheet->getStyle('A1:A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->mergeCells('A1:' . $lastCol . '1');
$sheet->mergeCells('A2:' . $lastCol . '2');
$sheet->mergeCells('A3:' . $lastCol . '3');

// ---- JUDUL ----
$row = 5;
$sheet->setCellValue('A' . $row, 'DATA BUKU AJAR');
$sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12);
$sheet->mergeCells('A' . $row . ':' . $lastCol . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

$sheet->setCellValue('A' . $row, 'Tahun Ajaran: ' . $tahun_ajaran_aktif . ' — ' . $semester_aktif);
$sheet->mergeCells('A' . $row . ':' . $lastCol . $row);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

if ($kelas_name) {
    $sheet->setCellValue('A' . $row, 'Kelas: ' . $kelas_name . ($f_jenis ? ' | Jenis: ' . $f_jenis : ''));
    $sheet->mergeCells('A' . $row . ':' . $lastCol . $row);
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $row++;
}

$row++; // spasi

// ---- TABLE HEADER ----
$headerRow = $row;
$sheet->setCellValue('A' . $headerRow, 'NO');
$sheet->setCellValue('B' . $headerRow, 'MATA PELAJARAN');
$sheet->setCellValue('C' . $headerRow, 'JUDUL BUKU');
$sheet->setCellValue('D' . $headerRow, 'PENYUSUN');
$sheet->setCellValue('E' . $headerRow, 'JENIS BUKU');
$sheet->setCellValue('F' . $headerRow, 'KELAS');

if ($is_admin_or_viewer) {
    $sheet->setCellValue('G' . $headerRow, 'GURU PENGINPUT');
}

$headerStyle = [
    'font' => ['bold' => true, 'size' => 10],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9EAD3']],
];
$sheet->getStyle('A' . $headerRow . ':' . $lastCol . $headerRow)->applyFromArray($headerStyle);
$sheet->getRowDimension($headerRow)->setRowHeight(28);

// ---- COLUMN WIDTH ----
$sheet->getColumnDimension('A')->setWidth(5);
$sheet->getColumnDimension('B')->setWidth(18);
$sheet->getColumnDimension('C')->setWidth(40);
$sheet->getColumnDimension('D')->setWidth(25);
$sheet->getColumnDimension('E')->setWidth(12);
$sheet->getColumnDimension('F')->setWidth(14);
if ($is_admin_or_viewer) {
    $sheet->getColumnDimension('G')->setWidth(22);
}

// ---- DATA ROWS ----
$row = $headerRow + 1;
$no = 1;

if (empty($buku_rows)) {
    $sheet->setCellValue('A' . $row, 'Tidak ada data buku ajar.');
    $sheet->mergeCells('A' . $row . ':' . $lastCol . $row);
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $row++;
} else {
    foreach ($buku_rows as $r) {
        $sheet->setCellValue('A' . $row, $no++);
        $sheet->setCellValue('B' . $row, $r['nama_mapel'] ?? '-');
        $sheet->setCellValue('C' . $row, $r['judul']);
        $sheet->setCellValue('D' . $row, $r['penyusun'] ?: '-');
        $sheet->setCellValue('E' . $row, $r['jenis_buku']);
        $sheet->setCellValue('F' . $row, $r['nama_kelas'] ?? '-');
        if ($is_admin_or_viewer) {
            $sheet->setCellValue('G' . $row, $r['nama_guru'] ?? '-');
        }

        // Warna selang-seling & border
        $bgColor = ($no % 2 === 0) ? 'F3F8F0' : 'FFFFFF';
        $dataStyle = [
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bgColor]],
        ];
        $sheet->getStyle('A' . $row . ':' . $lastCol . $row)->applyFromArray($dataStyle);
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('F' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;
    }
}

// ---- TOTAL ----
$row++;
$totalLabel = 'Total: ' . count($buku_rows) . ' buku ajar';
$sheet->setCellValue('A' . $row, $totalLabel);
$sheet->mergeCells('A' . $row . ':' . $lastCol . $row);
$sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(10);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

// ---- OUTPUT ----
while (ob_get_level() > 0) {
    ob_end_clean();
}

$filename = 'Data_Buku_Ajar';
if ($kelas_name) $filename .= '_' . preg_replace('/[^A-Za-z0-9]/', '_', $kelas_name);
$filename .= '_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;