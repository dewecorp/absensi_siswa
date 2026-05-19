<?php
// Hindari output PHP notice/warning agar tidak merusak file biner xlsx
@ini_set('display_errors', '0');
error_reporting(0);

require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

// Check auth
if (!isAuthorized(['guru', 'wali', 'kepala_madrasah', 'tata_usaha', 'admin'])) {
    die('Unauthorized');
}

// Get parameters
$selected_class_id = isset($_GET['kelas']) ? $_GET['kelas'] : null;
$selected_jenis    = isset($_GET['jenis']) ? $_GET['jenis'] : null;
$selected_tipe     = isset($_GET['tipe'])  ? $_GET['tipe']  : 'nilai_jadi';

if (!$selected_class_id || !$selected_jenis) {
    die('Parameter tidak lengkap');
}

$user_role = $_SESSION['level'] ?? '';
$is_admin_view = in_array($user_role, ['kepala_madrasah', 'tata_usaha', 'admin'], true);

// Get Class Info
$stmt = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas = ?");
$stmt->execute([$selected_class_id]);
$class_info = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$class_info) {
    die('Data kelas tidak ditemukan');
}

$kelas_name = (string)($class_info['nama_kelas'] ?? '');
$is_grade_6 = $kelas_name !== '' && preg_match('/\b(6|vi)\b/i', $kelas_name);

$jenis_exam = ['Pra Ujian Madrasah', 'Ujian Madrasah', 'Ujian Praktik', 'Pra Ujian', 'Ujian'];
if (in_array($selected_jenis, $jenis_exam, true) && !$is_grade_6) {
    die('Unauthorized');
}

if (!$is_admin_view) {
    $id_guru = $_SESSION['user_id'] ?? null;
    if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
        $stmt = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $id_guru = $stmt->fetchColumn();
    }

    $stmt = $pdo->prepare("SELECT nama_guru, mengajar FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([$id_guru]);
    $guru_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $nama_guru = $guru_data['nama_guru'] ?? '';
    $mengajar_json = $guru_data['mengajar'] ?? '[]';
    $mengajar_ids = json_decode($mengajar_json, true) ?? [];

    $stmt = $pdo->prepare("SELECT id_kelas FROM tb_kelas WHERE wali_kelas = ?");
    $stmt->execute([$nama_guru]);
    $wali_class_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $all_class_ids = array_unique(array_merge(is_array($mengajar_ids) ? $mengajar_ids : [], is_array($wali_class_ids) ? $wali_class_ids : []));
    if (empty($all_class_ids) || !in_array((string)$selected_class_id, array_map('strval', $all_class_ids), true)) {
        die('Unauthorized');
    }
}

// Use Wali Kelas name for signature (null-safe)
$nama_guru = $class_info['wali_kelas'] ?? '';

// Get filtered academic subjects only
$subjects = getFilteredSubjects($pdo);

// Get Active Semester
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran   = $school_profile['tahun_ajaran'] ?? '';
$semester_aktif = $school_profile['semester'] ?? '';

// Data Fetching
$students   = [];
$rekap_data = [];

// Map new exam type names to database values
$exam_type_map = [
    'PTS' => 'UTS',
    'PAS' => 'UAS',
    'PAT' => 'PAT',
    'Pra Ujian Madrasah' => 'Pra Ujian',
    'Ujian Madrasah' => 'Ujian',
    'Ujian Praktik' => 'Ujian Praktik'
];
$db_jenis = $exam_type_map[$selected_jenis] ?? $selected_jenis;

// Filter subjects for exam types
if (in_array($db_jenis, ['Pra Ujian', 'Ujian'], true)) {
    $subjects = array_values(array_filter($subjects, function ($m) {
        $nama = strtolower(trim((string)($m['nama_mapel'] ?? '')));
        $nama = preg_replace('/\s+/', ' ', $nama);
        return $nama !== 'tajwid' && $nama !== 'bta';
    }));
}

// Filter subjects for Ujian Praktik - only show subjects with grades
if ($db_jenis === 'Ujian Praktik') {
    $stmt = $pdo->prepare("
        SELECT DISTINCT id_mapel
        FROM tb_nilai_semester
        WHERE id_kelas = ?
          AND jenis_semester = ?
          AND tahun_ajaran = ?
          AND semester = ?
          AND (
            COALESCE(nilai_asli, 0) > 0
            OR COALESCE(nilai_remidi, 0) > 0
            OR COALESCE(nilai_jadi, 0) > 0
          )
    ");
    $stmt->execute([$selected_class_id, $db_jenis, $tahun_ajaran, $semester_aktif]);
    $filled_mapel_ids = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $subjects = array_values(array_filter($subjects, function ($m) use ($filled_mapel_ids) {
        return in_array((string)($m['id_mapel'] ?? ''), $filled_mapel_ids, true);
    }));
}

// Get Students
$stmt = $pdo->prepare("SELECT * FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
$stmt->execute([$selected_class_id]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Grades (Logic from rekap_nilai.php)
$kelas_sum = [];
$kelas_count = [];
foreach ($subjects as $mapel) {
    $kelas_sum[$mapel['id_mapel']] = 0;
    $kelas_count[$mapel['id_mapel']] = 0;
}

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
                AND h.tahun_ajaran = ? AND h.semester = ?
                AND d.id_siswa = ?
            ");
            $stmt->execute([$selected_class_id, $mapel['id_mapel'], $tahun_ajaran, $semester_aktif, $student['id_siswa']]);
            $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($details)) {
                $sum   = 0;
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
            $stmt->execute([$selected_class_id, $mapel['id_mapel'], $db_jenis, $tahun_ajaran, $semester_aktif, $student['id_siswa']]);
            $grade = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($grade) {
                $val   = ($selected_tipe == 'nilai_asli') ? $grade['nilai_asli'] : $grade['nilai_jadi'];
                $nilai = $val > 0 ? (float)$val : 0;
            }
        }

        $rekap_data[$student['id_siswa']][$mapel['id_mapel']] = $nilai;

        if ($nilai > 0) {
            $kelas_sum[$mapel['id_mapel']] += $nilai;
            $kelas_count[$mapel['id_mapel']]++;
            $total_nilai += $nilai;
            $count_mapel++;
        }
    }

    $rekap_data[$student['id_siswa']]['total']  = $total_nilai;
    $rekap_data[$student['id_siswa']]['rerata'] = $count_mapel > 0 ? round($total_nilai / $count_mapel, 1) : 0;
}

// Calculate Ranking
$averages = [];
foreach ($students as $student) {
    $averages[$student['id_siswa']] = $rekap_data[$student['id_siswa']]['rerata'];
}
arsort($averages);

$rank      = 1;
$prev_avg  = -1;
$real_rank = 1;

foreach ($averages as $id_siswa => $avg) {
    if ($avg != $prev_avg) {
        $rank = $real_rank;
    }
    $rekap_data[$id_siswa]['ranking'] = $rank;
    $prev_avg = $avg;
    $real_rank++;
}

$kelas_avg = [];
foreach ($subjects as $mapel) {
    $id_mapel = $mapel['id_mapel'];
    if (($kelas_count[$id_mapel] ?? 0) > 0) {
        $avg_mapel = round(($kelas_sum[$id_mapel] ?? 0) / $kelas_count[$id_mapel], 1);
        $kelas_avg[$id_mapel] = $avg_mapel;
    } else {
        $kelas_avg[$id_mapel] = 0;
    }
}

// Create Spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Title
$title = "REKAP NILAI " . strtoupper($selected_jenis);
$spreadsheet->getProperties()->setTitle($title);

// Hitung kolom terakhir berdasarkan jumlah mapel:
// A = NO, B = NAMA SISWA, C..(C+n-1) = mapel, lalu JUMLAH, RERATA, RANK
$numSubjects   = count($subjects);
$lastColIndex  = 2 + $numSubjects + 3; // 1-based: A=1, B=2, mapel..., +3 untuk Jumlah/Rerata/Rank
$lastCol       = Coordinate::stringFromColumnIndex($lastColIndex);

// Header Info
$sheet->setCellValue('A1', strtoupper($school_profile['nama_yayasan'] ?? ''));
$sheet->setCellValue('A2', strtoupper($school_profile['nama_madrasah'] ?? ''));
$sheet->setCellValue('A3', $school_profile['alamat'] ?? '');

$sheet->setCellValue('A5', $title);
$sheet->setCellValue('A6', 'KELAS: ' . ($class_info['nama_kelas'] ?? '-'));
$sheet->setCellValue('A7', 'TIPE: ' . ($selected_tipe == 'nilai_asli' ? 'NILAI ASLI' : 'NILAI JADI'));
$sheet->setCellValue('A8', 'TAHUN AJARAN ' . $tahun_ajaran . ' - Semester ' . $semester_aktif);

// Style School Header
$sheet->getStyle('A1')->getFont()->setSize(12);
$sheet->getStyle('A2')->getFont()->setSize(14)->setBold(true);
$sheet->getStyle('A1:A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A5')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('A5:A8')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Merge Header Cells across full table width
$sheet->mergeCells('A1:' . $lastCol . '1');
$sheet->mergeCells('A2:' . $lastCol . '2');
$sheet->mergeCells('A3:' . $lastCol . '3');
$sheet->mergeCells('A5:' . $lastCol . '5');
$sheet->mergeCells('A6:' . $lastCol . '6');
$sheet->mergeCells('A7:' . $lastCol . '7');
$sheet->mergeCells('A8:' . $lastCol . '8');

// Table Header (row 10)
$headerRow = 10;
$sheet->setCellValue('A' . $headerRow, 'NO');
$sheet->setCellValue('B' . $headerRow, 'NAMA SISWA');

$colIdx = 3; // C
foreach ($subjects as $mapel) {
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIdx) . $headerRow, $mapel['nama_mapel']);
    $colIdx++;
}
$sheet->setCellValue(Coordinate::stringFromColumnIndex($colIdx) . $headerRow, 'JUMLAH'); $colIdx++;
$sheet->setCellValue(Coordinate::stringFromColumnIndex($colIdx) . $headerRow, 'RERATA'); $colIdx++;
$sheet->setCellValue(Coordinate::stringFromColumnIndex($colIdx) . $headerRow, 'RANK');

// Style Header
$headerStyle = [
    'font' => ['bold' => true],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical'   => Alignment::VERTICAL_CENTER,
        'wrapText'   => true,
    ],
    'borders' => [
        'allBorders' => ['borderStyle' => Border::BORDER_THIN],
    ],
    'fill' => [
        'fillType'   => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'E0E0E0'],
    ],
];
$sheet->getStyle('A' . $headerRow . ':' . $lastCol . $headerRow)->applyFromArray($headerStyle);
$sheet->getRowDimension($headerRow)->setRowHeight(35);

// Set Column Widths
$sheet->getColumnDimension('A')->setWidth(5);
$sheet->getColumnDimension('B')->setWidth(30);
for ($i = 3; $i < $lastColIndex; $i++) {
    $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(15);
}
$sheet->getColumnDimension($lastCol)->setWidth(8); // Rank column

// Data Rows
$row = $headerRow + 1;
$no  = 1;
foreach ($students as $student) {
    $data = $rekap_data[$student['id_siswa']] ?? [];

    $sheet->setCellValue('A' . $row, $no++);
    $sheet->setCellValue('B' . $row, $student['nama_siswa']);

    $cIdx = 3;
    foreach ($subjects as $mapel) {
        $val        = $data[$mapel['id_mapel']] ?? 0;
        $displayVal = $val > 0 ? $val : '-';
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($cIdx) . $row, $displayVal);
        $cIdx++;
    }

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($cIdx) . $row, $data['total']  ?? 0); $cIdx++;
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($cIdx) . $row, $data['rerata'] ?? 0); $cIdx++;
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($cIdx) . $row, $data['ranking'] ?? '-');

    $row++;
}

if (!empty($students)) {
    $sheet->setCellValue('A' . $row, '');
    $sheet->setCellValue('B' . $row, 'Rerata Kelas');

    $cIdx = 3;
    foreach ($subjects as $mapel) {
        $id_mapel = $mapel['id_mapel'];
        $val = $kelas_avg[$id_mapel] ?? 0;
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($cIdx) . $row, $val > 0 ? $val : '-');
        $cIdx++;
    }

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($cIdx) . $row, '-'); $cIdx++;
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($cIdx) . $row, '-'); $cIdx++;
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($cIdx) . $row, '-');

    $sheet->getStyle('A' . $row . ':' . $lastCol . $row)->getFont()->setBold(true);
    $sheet->getStyle('A' . $row . ':' . $lastCol . $row)->getFill()
          ->setFillType(Fill::FILL_SOLID)
          ->getStartColor()->setRGB('E9ECEF');
    $row++;
}

// Border for Data
$lastRow = $row - 1;
if ($lastRow >= $headerRow + 1) {
    $dataStyle = [
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN],
        ],
        'alignment' => [
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ];
    $sheet->getStyle('A' . ($headerRow + 1) . ':' . $lastCol . $lastRow)->applyFromArray($dataStyle);

    // Center alignment for scores & no
    $sheet->getStyle('C' . ($headerRow + 1) . ':' . $lastCol . $lastRow)
          ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A' . ($headerRow + 1) . ':A' . $lastRow)
          ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

// Footer / Signature di kolom terakhir
$row += 2;
$sheet->setCellValue($lastCol . $row, 'Jepara, ' . date('d F Y'));
$row++;
$sheet->setCellValue($lastCol . $row, 'Wali Kelas');
$row += 4;
$sheet->setCellValue($lastCol . $row, $nama_guru !== '' ? $nama_guru : '.........................');
$sheet->getStyle($lastCol . ($row - 6) . ':' . $lastCol . $row)
      ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Bersihkan setiap output buffer agar file biner tidak terkontaminasi
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Sanitasi nama file
$safeJenis = preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string)$selected_jenis);
$safeKelas = preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string)($class_info['nama_kelas'] ?? 'Kelas'));
$filename  = 'Rekap_Nilai_' . $safeJenis . '_' . $safeKelas . '.xlsx';

// Output
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
