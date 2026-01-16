<?php
require_once 'database.php';
require_once 'functions.php';

// Check if user is logged in and authorized
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../admin/data_guru.php');
}

// Get the table data from POST
$table_data = $_POST['table_data'] ?? '';
$export_type = $_POST['export_type'] ?? '';

// For debugging - uncomment to log the received data
// error_log('Received table data: ' . substr($table_data, 0, 200));

if (empty($table_data)) {
    // If no table data, fetch from database and create basic table
    $stmt = $pdo->query("
        SELECT g.*, 
               GROUP_CONCAT(k.nama_kelas ORDER BY k.nama_kelas SEPARATOR ', ') as kelas_wali
        FROM tb_guru g
        LEFT JOIN tb_kelas k ON k.wali_kelas = g.nama_guru
        GROUP BY g.id_guru
        ORDER BY g.nama_guru ASC
    ");
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Decode mengajar JSON
    $classes_stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
    $classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
    $class_lookup = [];
    foreach ($classes as $kelas) {
        $class_lookup[$kelas['id_kelas']] = $kelas['nama_kelas'];
    }
    
    // Build a basic HTML table
    $table_data = '<table><thead><tr><th>No</th><th>Nama Guru</th><th>NUPTK</th><th>Tempat Lahir</th><th>Tanggal Lahir</th><th>Jenis Kelamin</th><th>Mengajar</th><th>Wali Kelas</th></tr></thead><tbody>';
    $no = 1;
    foreach ($teachers as $teacher) {
        // Decode mengajar JSON and get class names
        $mengajar_names = [];
        if (!empty($teacher['mengajar'])) {
            $decoded = json_decode($teacher['mengajar'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $kelas_id) {
                    $kelas_id = (string)$kelas_id;
                    if (isset($class_lookup[(int)$kelas_id])) {
                        $mengajar_names[] = $class_lookup[(int)$kelas_id];
                    } elseif (isset($class_lookup[$kelas_id])) {
                        $mengajar_names[] = $class_lookup[$kelas_id];
                    }
                }
            }
        }
        $mengajar_display = !empty($mengajar_names) ? implode(', ', $mengajar_names) : '-';
        
        $table_data .= '<tr>';
        $table_data .= '<td>' . $no++ . '</td>';
        $table_data .= '<td>' . htmlspecialchars($teacher['nama_guru']) . '</td>';
        $table_data .= '<td>' . htmlspecialchars($teacher['nuptk']) . '</td>';
        $table_data .= '<td>' . htmlspecialchars($teacher['tempat_lahir']) . '</td>';
        $table_data .= '<td>' . ($teacher['tanggal_lahir'] ? date('d-m-Y', strtotime($teacher['tanggal_lahir'])) : '-') . '</td>';
        $table_data .= '<td>' . htmlspecialchars($teacher['jenis_kelamin']) . '</td>';
        $table_data .= '<td>' . htmlspecialchars($mengajar_display) . '</td>';
        $table_data .= '<td>' . htmlspecialchars($teacher['kelas_wali'] ?? '-') . '</td>';
        $table_data .= '</tr>';
    }
    $table_data .= '</tbody></table>';
}

// Include PhpSpreadsheet
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Create new Spreadsheet object
$spreadsheet = new Spreadsheet();

// Set document properties
$spreadsheet->getProperties()
    ->setCreator("Sistem Absensi Siswa")
    ->setLastModifiedBy("Sistem Absensi Siswa")
    ->setTitle("Data Guru - " . date('Y-m-d'))
    ->setSubject("Data Guru")
    ->setDescription("Data guru dari sistem absensi siswa");

// Add some basic styles
$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF']
    ],
    'fill' => [
        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
        'startColor' => ['rgb' => '368DBC']
    ],
    'alignment' => [
        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
    ]
];

// Set worksheet title
$spreadsheet->getActiveSheet()->setTitle('Data Guru');

// Extract data from HTML table
$dom = new DOMDocument();
libxml_use_internal_errors(true); // Suppress warnings for malformed HTML
$dom->loadHTML('<?xml encoding="UTF-8">' . $table_data);
libxml_clear_errors();

$rows = $dom->getElementsByTagName('tr');

$rowIndex = 1;
foreach ($rows as $row) {
    $cols = $row->getElementsByTagName('th');
    $cellIndex = 'A';
    
    // Process header cells (th)
    foreach ($cols as $col) {
        $value = trim($col->textContent);
        $spreadsheet->getActiveSheet()->setCellValue($cellIndex . $rowIndex, $value);
        $spreadsheet->getActiveSheet()->getStyle($cellIndex . $rowIndex)->applyFromArray($headerStyle);
        $cellIndex++;
    }
    
    // Process data cells (td)
    $cols = $row->getElementsByTagName('td');
    foreach ($cols as $col) {
        $value = trim($col->textContent);
        $spreadsheet->getActiveSheet()->setCellValue($cellIndex . $rowIndex, $value);
        
        // Apply borders to all cells
        $spreadsheet->getActiveSheet()->getStyle($cellIndex . $rowIndex)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                ],
            ],
        ]);
        
        $cellIndex++;
    }
    
    $rowIndex++;
}

// Auto-size columns
$spreadsheet->getActiveSheet()->getColumnDimension('A')->setAutoSize(true);
$spreadsheet->getActiveSheet()->getColumnDimension('B')->setAutoSize(true);
$spreadsheet->getActiveSheet()->getColumnDimension('C')->setAutoSize(true);
$spreadsheet->getActiveSheet()->getColumnDimension('D')->setAutoSize(true);
$spreadsheet->getActiveSheet()->getColumnDimension('E')->setAutoSize(true);
$spreadsheet->getActiveSheet()->getColumnDimension('F')->setAutoSize(true);
$spreadsheet->getActiveSheet()->getColumnDimension('G')->setAutoSize(true);
$spreadsheet->getActiveSheet()->getColumnDimension('H')->setAutoSize(true);
$spreadsheet->getActiveSheet()->getColumnDimension('I')->setAutoSize(true);

// Create writer and save to output
$writer = new Xlsx($spreadsheet);

// Output headers for download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="data_guru_' . date('Y-m-d_H-i-s') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer->save('php://output');
exit();
?>