<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

// Check auth
if (!isAuthorized(['guru', 'wali', 'kepala_madrasah', 'tata_usaha', 'admin'])) {
    die('Unauthorized');
}

// Get parameters
$selected_class_id = isset($_GET['kelas']) ? $_GET['kelas'] : null;
$selected_mapel_id = isset($_GET['mapel']) ? $_GET['mapel'] : null;

if (!$selected_class_id || !$selected_mapel_id) {
    die('Parameter tidak lengkap');
}

// Get Active Semester
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'];
$semester_aktif = $school_profile['semester'];

// Get teacher data
// Logic: Try to find the actual teacher of the subject from Schedule -> Daily Grades -> Kokurikuler
// If all fail, and user is a guru, fallback to current user.

// 1. Try Jadwal Pelajaran (Most reliable)
$stmt = $pdo->prepare("SELECT DISTINCT guru_id FROM tb_jadwal_pelajaran WHERE kelas_id = ? AND mapel_id = ? LIMIT 1");
$stmt->execute([$selected_class_id, $selected_mapel_id]);
$id_guru = $stmt->fetchColumn();

// 2. If not found, try Daily Grades
if (!$id_guru) {
    $stmt = $pdo->prepare("SELECT DISTINCT id_guru FROM tb_nilai_harian_header WHERE id_kelas = ? AND id_mapel = ? AND tahun_ajaran = ? AND semester = ? LIMIT 1");
    $stmt->execute([$selected_class_id, $selected_mapel_id, $tahun_ajaran, $semester_aktif]);
    $id_guru = $stmt->fetchColumn();
}

// 3. If not found, try Kokurikuler
if (!$id_guru) {
    $stmt = $pdo->prepare("SELECT DISTINCT id_guru FROM tb_nilai_kokurikuler_header WHERE id_kelas = ? AND id_mapel = ? AND tahun_ajaran = ? AND semester = ? LIMIT 1");
    $stmt->execute([$selected_class_id, $selected_mapel_id, $tahun_ajaran, $semester_aktif]);
    $id_guru = $stmt->fetchColumn();
}

// 4. Fallback for Guru user if still not found
if (!$id_guru && isset($_SESSION['level']) && $_SESSION['level'] == 'guru') {
    $id_guru = $_SESSION['user_id'];
    if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
        $stmt = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $id_guru = $stmt->fetchColumn();
    }
}

$id_guru = $id_guru ? $id_guru : 0;

// Get Class Info
$stmt = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas = ?");
$stmt->execute([$selected_class_id]);
$class_info = $stmt->fetch(PDO::FETCH_ASSOC);

// Get Mapel Info
$stmt = $pdo->prepare("SELECT * FROM tb_mata_pelajaran WHERE id_mapel = ?");
$stmt->execute([$selected_mapel_id]);
$mapel_info = $stmt->fetch(PDO::FETCH_ASSOC);

// Get Students
$stmt = $pdo->prepare("SELECT * FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
$stmt->execute([$selected_class_id]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Grade Headers
// School profile already fetched above

// Fetch headers without id_guru first to ensure we get data if it exists (especially for Admin view)
$stmt = $pdo->prepare("SELECT * FROM tb_nilai_harian_header WHERE id_kelas = ? AND id_mapel = ? AND tahun_ajaran = ? AND semester = ? ORDER BY created_at ASC");
$stmt->execute([$selected_class_id, $selected_mapel_id, $tahun_ajaran, $semester_aktif]);
$grade_headers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If headers exist, update id_guru from the actual data
if (!empty($grade_headers)) {
    $id_guru_from_data = $grade_headers[0]['id_guru'];
    if ($id_guru == 0 || $id_guru != $id_guru_from_data) {
        $id_guru = $id_guru_from_data;
    }
}

// Get Grades
$grades_data = [];
if (!empty($grade_headers)) {
    $header_ids = array_column($grade_headers, 'id_header');
    $placeholders = str_repeat('?,', count($header_ids) - 1) . '?';
    
    $stmt = $pdo->prepare("SELECT * FROM tb_nilai_harian_detail WHERE id_header IN ($placeholders)");
    $stmt->execute($header_ids);
    $all_grades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($all_grades as $g) {
        $grades_data[$g['id_siswa']][$g['id_header']] = [
            'nilai' => $g['nilai'],
            'nilai_jadi' => $g['nilai_jadi']
        ];
    }
}

// Create Spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set Properties
$spreadsheet->getProperties()
    ->setCreator("Sistem Absensi Siswa")
    ->setTitle("Nilai Harian " . $class_info['nama_kelas']);

// Header Info
$sheet->setCellValue('A1', 'DAFTAR NILAI HARIAN');
$sheet->setCellValue('A2', 'KELAS: ' . $class_info['nama_kelas']);
$sheet->setCellValue('A3', 'MATA PELAJARAN: ' . $mapel_info['nama_mapel']);
$sheet->setCellValue('A4', 'GURU: ' . (getGuruName($pdo, $id_guru) ?: '.........................'));
$sheet->setCellValue('A5', 'WALI KELAS: ' . ($class_info['wali_kelas'] ?? '.........................'));

// Merge Header Cells
$sheet->mergeCells('A1:H1');
$sheet->mergeCells('A2:H2');
$sheet->mergeCells('A3:H3');
$sheet->mergeCells('A4:H4');
$sheet->mergeCells('A5:H5');

// Table Headers
$row = 7;
$sheet->setCellValue('A' . $row, 'NO');
$sheet->mergeCells('A' . $row . ':A' . ($row + 2));
$sheet->setCellValue('B' . $row, 'NAMA SISWA');
$sheet->mergeCells('B' . $row . ':B' . ($row + 2));

$col = 'C';
foreach ($grade_headers as $header) {
    // Determine next column letter for merge
    $currentCol = $col;
    $nextCol = ++$col; // This increments $col, so $col is now D (if start was C)
    
    // Merge for Title (e.g., UH 1)
    $sheet->setCellValue($currentCol . $row, $header['nama_penilaian']);
    $sheet->mergeCells($currentCol . $row . ':' . $nextCol . $row);
    
    // Merge for Materi (New Row)
    $materi = isset($header['materi']) ? $header['materi'] : '-';
    $sheet->setCellValue($currentCol . ($row + 1), $materi);
    $sheet->mergeCells($currentCol . ($row + 1) . ':' . $nextCol . ($row + 1));
    $sheet->getStyle($currentCol . ($row + 1))->getFont()->setItalic(true);
    
    // Sub-headers
    $sheet->setCellValue($currentCol . ($row + 2), 'Nilai');
    $sheet->setCellValue($nextCol . ($row + 2), 'Jadi');
    
    $col++; // Move to next available column for next loop (e.g., E)
}

$sheet->setCellValue($col . $row, 'RERATA');
$sheet->mergeCells($col . $row . ':' . $col . ($row + 2));
$lastCol = $col;

// Style for Headers
$headerStyle = [
    'font' => ['bold' => true],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER
    ],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
];

$sheet->getStyle('A' . $row . ':' . $lastCol . ($row + 2))->applyFromArray($headerStyle);

// Data Rows
$row += 3; // Move past the 3 header rows
$no = 1;
foreach ($students as $student) {
    $sheet->setCellValue('A' . $row, $no++);
    $sheet->setCellValue('B' . $row, $student['nama_siswa']);
    
    $col = 'C';
    $total = 0;
    $count = 0;
    
    foreach ($grade_headers as $header) {
        $data = isset($grades_data[$student['id_siswa']][$header['id_header']]) 
                ? $grades_data[$student['id_siswa']][$header['id_header']] 
                : ['nilai' => '', 'nilai_jadi' => ''];
        
        $nilai = $data['nilai'];
        $nilai_jadi = $data['nilai_jadi'];
        
        // Output Nilai
        $sheet->setCellValue($col . $row, $nilai);
        $col++;
        
        // Output Nilai Jadi
        $sheet->setCellValue($col . $row, $nilai_jadi);
        $col++;
        
        // For average, use nilai (original) to match table
        $valForAvg = $nilai;
        
        if ($valForAvg !== '' && $valForAvg !== null) {
            $total += (float)$valForAvg;
            $count++;
        }
    }
    
    $avg = $count > 0 ? round($total / $count, 1) : '-';
    $sheet->setCellValue($col . $row, $avg);
    $row++;
}

// Style for Data
$dataStyle = [
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
];
$sheet->getStyle('A' . ($row - $no) . ':' . $lastCol . ($row - 1))->applyFromArray($dataStyle);

// Auto Size Columns
foreach (range('A', 'B') as $colID) {
    $sheet->getColumnDimension($colID)->setAutoSize(true);
}
// For data columns, maybe set fixed width or auto
// Since range() only works for single characters A-Z, we need a smarter loop if columns exceed Z
// But for now let's just loop a bit or let it be.
// Better:
$curr = 'A';
while ($curr != $lastCol) {
    $sheet->getColumnDimension($curr)->setAutoSize(true);
    $curr++;
}
$sheet->getColumnDimension($lastCol)->setAutoSize(true);

// Signatures
$row += 2;
$sheet->setCellValue($lastCol . $row, 'Jepara, ' . date('d F Y'));
$row++;
$sheet->setCellValue($lastCol . $row, 'Guru Mata Pelajaran');
$row += 4;
$sheet->setCellValue($lastCol . $row, getGuruName($pdo, $id_guru) ?: '.........................');

// Output
$filename = 'Nilai_Harian_' . str_replace(' ', '_', $class_info['nama_kelas']) . '_' . str_replace(' ', '_', $mapel_info['nama_mapel']) . '_' . date('Y-m-d') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
