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
$jenis_semester = isset($_GET['jenis']) ? $_GET['jenis'] : null;

if (!$selected_class_id || !$selected_mapel_id || !$jenis_semester) {
    die('Parameter tidak lengkap');
}

// Get Active Semester
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'];
$semester_aktif = $school_profile['semester'];

// Determine Guru for Header
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

// Check if mapel is non-academic
if (strpos($mapel_info['nama_mapel'], 'Asmaul Husna') !== false || 
    strpos($mapel_info['nama_mapel'], 'Upacara') !== false ||
    strpos($mapel_info['nama_mapel'], 'Istirahat') !== false ||
    strpos($mapel_info['nama_mapel'], 'Kepramukaan') !== false ||
    strpos($mapel_info['nama_mapel'], 'Ekstrakurikuler') !== false) {
    die('Mata pelajaran Non-Akademik tidak dapat diekspor.');
}

// School profile already fetched above

// Get Students
$stmt = $pdo->prepare("SELECT * FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
$stmt->execute([$selected_class_id]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Grades
$grades_data = [];
$stmt = $pdo->prepare("
    SELECT * FROM tb_nilai_semester 
    WHERE id_mapel = ? 
    AND id_kelas = ? 
    AND jenis_semester = ? 
    AND tahun_ajaran = ? 
    AND semester = ?
");
$stmt->execute([$selected_mapel_id, $selected_class_id, $jenis_semester, $tahun_ajaran, $semester_aktif]);
$fetched_grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($fetched_grades as $g) {
    $grades_data[$g['id_siswa']] = $g;
}

// Map Jenis Semester to Display Title
$titles = [
    'UTS' => 'NILAI TENGAH SEMESTER',
    'UAS' => 'NILAI AKHIR SEMESTER',
    'PAT' => 'NILAI AKHIR TAHUN',
    'Ujian' => 'NILAI UJIAN',
    'Pra Ujian' => 'NILAI PRA UJIAN'
];
$title = isset($titles[$jenis_semester]) ? $titles[$jenis_semester] : 'NILAI SEMESTER (' . $jenis_semester . ')';

// Create Spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set Properties
$spreadsheet->getProperties()
    ->setCreator("Sistem Absensi Siswa")
    ->setTitle($title . " " . $class_info['nama_kelas']);

// Header Info
$sheet->setCellValue('A1', strtoupper($school_profile['nama_yayasan'] ?? ''));
$sheet->setCellValue('A2', strtoupper($school_profile['nama_madrasah'] ?? ''));
$sheet->setCellValue('A3', $school_profile['alamat'] ?? '');

$sheet->setCellValue('A5', $title);
$sheet->setCellValue('A6', 'KELAS: ' . $class_info['nama_kelas']);
$sheet->setCellValue('A7', 'MATA PELAJARAN: ' . $mapel_info['nama_mapel']);
$sheet->setCellValue('A8', 'TAHUN AJARAN: ' . $tahun_ajaran . ' - Semester ' . $semester_aktif);
$sheet->setCellValue('A9', 'GURU: ' . (getGuruName($pdo, $id_guru) ?: '.........................'));

// Style School Header
$sheet->getStyle('A1')->getFont()->setSize(12);
$sheet->getStyle('A2')->getFont()->setSize(14)->setBold(true);
$sheet->getStyle('A1:A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Merge Header Cells
$sheet->mergeCells('A1:F1');
$sheet->mergeCells('A2:F2');
$sheet->mergeCells('A3:F3');

$sheet->mergeCells('A5:F5');
$sheet->mergeCells('A6:F6');
$sheet->mergeCells('A7:F7');
$sheet->mergeCells('A8:F8');
$sheet->mergeCells('A9:F9');

// Table Headers
$row = 11;
$sheet->setCellValue('A' . $row, 'NO');
$sheet->setCellValue('B' . $row, 'NAMA SISWA');
$sheet->setCellValue('C' . $row, 'NILAI ASLI');
$sheet->setCellValue('D' . $row, 'REMIDI');
$sheet->setCellValue('E' . $row, 'NILAI JADI');
$sheet->setCellValue('F' . $row, 'RERATA');

// Style for Headers
$headerStyle = [
    'font' => ['bold' => true],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER
    ],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    'fill' => [
        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'E0E0E0']
    ]
];

$sheet->getStyle('A' . $row . ':F' . $row)->applyFromArray($headerStyle);

// Data Rows
$row++;
$no = 1;
foreach ($students as $student) {
    $id_siswa = $student['id_siswa'];
    $grade = isset($grades_data[$id_siswa]) ? $grades_data[$id_siswa] : null;
    
    $nilai_asli = $grade ? $grade['nilai_asli'] : 0;
    $nilai_remidi = $grade ? $grade['nilai_remidi'] : 0;
    $nilai_jadi = $grade ? $grade['nilai_jadi'] : 0;
    
    // Calculate Rerata logic: (Asli + Remidi) / 2 if Remidi > 0, else Asli
    $rerata = ($nilai_remidi > 0) ? ($nilai_asli + $nilai_remidi) / 2 : $nilai_asli;
    
    $sheet->setCellValue('A' . $row, $no++);
    $sheet->setCellValue('B' . $row, $student['nama_siswa']);
    $sheet->setCellValue('C' . $row, $nilai_asli > 0 ? $nilai_asli : '-');
    $sheet->setCellValue('D' . $row, $nilai_remidi > 0 ? $nilai_remidi : '-');
    $sheet->setCellValue('E' . $row, $nilai_jadi > 0 ? $nilai_jadi : '-');
    $sheet->setCellValue('F' . $row, $rerata > 0 ? round($rerata, 1) : '-');
    
    $row++;
}

// Style for Data
$dataStyle = [
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
];
$sheet->getStyle('A' . 8 . ':F' . ($row - 1))->applyFromArray($dataStyle);

// Alignment for numbers
$sheet->getStyle('A' . 8 . ':A' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('C' . 8 . ':F' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Auto Size Columns
foreach (range('A', 'F') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Signatures
$row += 2;
$sheet->setCellValue('E' . $row, 'Jepara, ' . date('d F Y'));
$row++;
$sheet->setCellValue('E' . $row, 'Guru Mata Pelajaran');
$row += 4;
$sheet->setCellValue('E' . $row, getGuruName($pdo, $id_guru) ?: '.........................');

// Output
$filename = str_replace(' ', '_', $title) . '_' . str_replace(' ', '_', $class_info['nama_kelas']) . '_' . str_replace(' ', '_', $mapel_info['nama_mapel']) . '_' . date('Y-m-d') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
