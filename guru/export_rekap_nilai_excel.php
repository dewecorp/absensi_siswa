<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// Check auth
if (!isAuthorized(['guru', 'wali'])) {
    die('Unauthorized');
}

// Get parameters
$selected_class_id = isset($_GET['kelas']) ? $_GET['kelas'] : null;
$selected_jenis = isset($_GET['jenis']) ? $_GET['jenis'] : null;
$selected_tipe = isset($_GET['tipe']) ? $_GET['tipe'] : 'nilai_jadi';

if (!$selected_class_id || !$selected_jenis) {
    die('Parameter tidak lengkap');
}

// Get teacher data (for header info)
$id_guru = $_SESSION['user_id'];
if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
    $stmt = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $id_guru = $stmt->fetchColumn();
}
// Get Guru Name
$stmt = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
$stmt->execute([$id_guru]);
$nama_guru = $stmt->fetchColumn();


// Get Class Info
$stmt = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas = ?");
$stmt->execute([$selected_class_id]);
$class_info = $stmt->fetch(PDO::FETCH_ASSOC);

// Get All Subjects
$subjects = [];
$stmt = $pdo->query("SELECT * FROM tb_mata_pelajaran 
    WHERE nama_mapel NOT LIKE '%Asmaul Husna%'
    AND nama_mapel NOT LIKE '%Upacara%'
    AND nama_mapel NOT LIKE '%Istirahat%'
    AND nama_mapel NOT LIKE '%Kepramukaan%'
    ORDER BY nama_mapel ASC");
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Active Semester
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'];
$semester_aktif = $school_profile['semester'];

// Data Fetching
$students = [];
$rekap_data = [];

// Get Students
$stmt = $pdo->prepare("SELECT * FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
$stmt->execute([$selected_class_id]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Grades (Logic from rekap_nilai.php)
foreach ($students as $student) {
    $total_nilai = 0;
    $count_mapel = 0;
    
    foreach ($subjects as $mapel) {
        $nilai = 0;
        
        if ($selected_jenis == 'Harian') {
            $stmt = $pdo->prepare("
                SELECT d.* 
                FROM tb_nilai_harian_detail d
                JOIN tb_nilai_harian_header h ON d.id_header = h.id_header
                WHERE h.id_kelas = ? AND h.id_mapel = ?
                AND d.id_siswa = ?
            ");
            $stmt->execute([$selected_class_id, $mapel['id_mapel'], $student['id_siswa']]);
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
            $stmt = $pdo->prepare("
                SELECT * FROM tb_nilai_semester 
                WHERE id_kelas = ? AND id_mapel = ? 
                AND jenis_semester = ? AND tahun_ajaran = ? AND semester = ?
                AND id_siswa = ?
            ");
            $stmt->execute([$selected_class_id, $mapel['id_mapel'], $selected_jenis, $tahun_ajaran, $semester_aktif, $student['id_siswa']]);
            $grade = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($grade) {
                $val = ($selected_tipe == 'nilai_asli') ? $grade['nilai_asli'] : $grade['nilai_jadi'];
                $nilai = $val > 0 ? (float)$val : 0;
            }
        }

        $rekap_data[$student['id_siswa']][$mapel['id_mapel']] = $nilai;
        
        if ($nilai > 0) {
            $total_nilai += $nilai;
            $count_mapel++;
        }
    }
    
    $rekap_data[$student['id_siswa']]['total'] = $total_nilai;
    $rekap_data[$student['id_siswa']]['rerata'] = $count_mapel > 0 ? round($total_nilai / $count_mapel, 1) : 0;
}

// Calculate Ranking
$averages = [];
foreach ($students as $student) {
    $averages[$student['id_siswa']] = $rekap_data[$student['id_siswa']]['rerata'];
}
arsort($averages);

$rank = 1;
$prev_avg = -1;
$real_rank = 1;

foreach ($averages as $id_siswa => $avg) {
    if ($avg != $prev_avg) {
        $rank = $real_rank;
    }
    $rekap_data[$id_siswa]['ranking'] = $rank;
    $prev_avg = $avg;
    $real_rank++;
}

// Create Spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Title
$title = "REKAP NILAI " . strtoupper($selected_jenis);
$spreadsheet->getProperties()->setTitle($title);

// Header Info
$sheet->setCellValue('A1', $title);
$sheet->setCellValue('A2', 'KELAS: ' . $class_info['nama_kelas']);
$sheet->setCellValue('A3', 'TIPE: ' . ($selected_tipe == 'nilai_asli' ? 'NILAI ASLI' : 'NILAI JADI'));
$sheet->setCellValue('A4', 'TAHUN AJARAN: ' . $tahun_ajaran . ' - Semester ' . $semester_aktif);

// Table Header
$row = 6;
$sheet->setCellValue('A' . $row, 'NO');
$sheet->setCellValue('B' . $row, 'NAMA SISWA');

$col = 'C';
foreach ($subjects as $mapel) {
    $sheet->setCellValue($col . $row, $mapel['nama_mapel']);
    $col++;
}
$sheet->setCellValue($col . $row, 'JUMLAH');
$col++;
$sheet->setCellValue($col . $row, 'RERATA');
$col++;
$sheet->setCellValue($col . $row, 'RANK');

// Style Header
$lastCol = $col;
// Decrement lastCol because loop increments it one past the end
$lastColCheck = $col;
// Logic: 'C' + N subjects + 3 columns. 
// Actually, PHP handles string increment correctly (Z -> AA).
// But to apply style range we need the last column letter.
// The loop does $col++ at the end, so $col is currently one past the last column.
// We need to calculate previous column letter.
// Or just track it inside the loop.
// Let's rely on simple string logic or just use Coordinate::stringFromColumnIndex if needed, but here simple logic:
// Actually, let's just use the current $col for next items, but for styling we need the range A6:LastCol6.
// Let's re-calculate last column or just keep track.

// Better way:
$colIndex = 3; // C
foreach ($subjects as $mapel) {
    $colIndex++;
}
$colIndex += 3; // Jumlah, Rerata, Rank
// Wait, string increment is easier.
$col = 'C';
foreach ($subjects as $mapel) { $col++; }
$col++; // After Jumlah
$col++; // After Rerata
$finalCol = $col; // After Rank... wait.
// Let's trace carefully.
// Start C. Loop subjects. Set value. Increment.
// End loop. Set Jumlah. Increment. Set Rerata. Increment. Set Rank. Increment.
// So $col is now the column AFTER Rank.
// We need the column OF Rank.

// Let's redo the variable naming to be safe.
$col = 'C';
foreach ($subjects as $mapel) {
    $col++;
}
$col++; // for Jumlah
$col++; // for Rerata
// Rank is at current $col? No.
// Let's just do it manually in the code execution flow.
// A, B, C...
// Last one used was Rank.
// I will just use the code logic in the script:
// $sheet->setCellValue($col . $row, 'RANK');
// $lastCol = $col;
// This works.

$headerStyle = [
    'font' => ['bold' => true],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
        'wrapText' => true
    ],
    'borders' => [
        'allBorders' => ['borderStyle' => Border::BORDER_THIN]
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'E0E0E0']
    ]
];
// We need to apply this AFTER we finish setting values, so $col will be pointing to 'RANK' column if we didn't increment it yet.
// In my previous block:
// $sheet->setCellValue($col . $row, 'RANK');
// $lastCol = $col;
// That assumes I didn't increment after Rank.

// Let's fix the specific section in the file content I'm writing.

// Style Header
// Calculate last column properly
// $col is currently at RANK column (because we incremented before setting it... wait)
// Logic trace:
// $col = 'C';
// foreach: set 'C', inc to 'D'. set 'D', inc to 'E'...
// end foreach. $col is at next empty column.
// set JUMLAH at $col. inc.
// set RERATA at $col. inc.
// set RANK at $col.
// So $col IS the column letter for RANK.
$lastCol = $col;

$headerStyle = [
    'font' => ['bold' => true],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
        'wrapText' => true
    ],
    'borders' => [
        'allBorders' => ['borderStyle' => Border::BORDER_THIN]
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'E0E0E0']
    ]
];

$sheet->getStyle('A' . $row . ':' . $lastCol . $row)->applyFromArray($headerStyle);

// Set Column Widths
$sheet->getColumnDimension('A')->setWidth(5);
$sheet->getColumnDimension('B')->setWidth(30);
// Auto width for others
$c = 'C';
while ($c != $lastCol) {
    $sheet->getColumnDimension($c)->setWidth(15);
    $c++;
}
$sheet->getColumnDimension($lastCol)->setWidth(8); // Rank column

// Data Rows
$row++;
foreach ($students as $student) {
    $data = $rekap_data[$student['id_siswa']] ?? [];
    
    $sheet->setCellValue('A' . $row, $no++);
    $sheet->setCellValue('B' . $row, $student['nama_siswa']);
    
    $col = 'C';
    foreach ($subjects as $mapel) {
        $val = $data[$mapel['id_mapel']] ?? 0;
        // Display '-' if 0
        $displayVal = $val > 0 ? $val : '-';
        $sheet->setCellValue($col . $row, $displayVal);
        $col++;
    }
    
    $sheet->setCellValue($col . $row, $data['total']);
    $col++;
    $sheet->setCellValue($col . $row, $data['rerata']);
    $col++;
    $sheet->setCellValue($col . $row, $data['ranking']);
    
    $row++;
}

// Border for Data
$lastRow = $row - 1;
$dataStyle = [
    'borders' => [
        'allBorders' => ['borderStyle' => Border::BORDER_THIN]
    ],
    'alignment' => [
        'vertical' => Alignment::VERTICAL_CENTER
    ]
];
$sheet->getStyle('A6:' . $lastCol . $lastRow)->applyFromArray($dataStyle);

// Center alignment for scores
$sheet->getStyle('C6:' . $lastCol . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A6:A' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Footer
$row += 2;
$sheet->setCellValue('B' . $row, 'Jepara, ' . date('d F Y'));
$row++;
$sheet->setCellValue('B' . $row, 'Wali Kelas / Guru');
$row += 4;
$sheet->setCellValue('B' . $row, $nama_guru);
$sheet->getStyle('B' . ($row-4) . ':B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);


// Output
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="Rekap_Nilai_' . $selected_jenis . '_' . $class_info['nama_kelas'] . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
