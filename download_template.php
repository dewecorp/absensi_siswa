<?php
session_start();

// Check if user is authorized
if (!isset($_SESSION['username'])) {
    header('HTTP/1.0 403 Forbidden');
    exit('Access denied');
}

require_once 'config/database.php';
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$template_type = isset($_GET['type']) ? $_GET['type'] : '';

if ($template_type === 'guru') {
    // Generate teacher template dynamically with correct columns (no homeroom teacher column)
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Data Guru');
    
    // Add headers for teacher template (without homeroom teacher column)
    $headers = [
        'Nama Guru',
        'NUPTK', 
        'Tempat Lahir',
        'Tanggal Lahir',
        'Jenis Kelamin',
        'Password'
    ];
    
    // Write headers
    for ($i = 0; $i < count($headers); $i++) {
        $column = chr(65 + $i); // A, B, C, D, E, F
        $sheet->setCellValue($column . '1', $headers[$i]);
    }
    
    // Set column widths for better readability
    $sheet->getColumnDimension('A')->setWidth(20);
    $sheet->getColumnDimension('B')->setWidth(15);
    $sheet->getColumnDimension('C')->setWidth(15);
    $sheet->getColumnDimension('D')->setWidth(15);
    $sheet->getColumnDimension('E')->setWidth(10);
    $sheet->getColumnDimension('F')->setWidth(15);
    
    // Add sample data row
    $sampleData = [
        'John Doe',
        '123456789012345',
        'Jakarta',
        '1980-01-01',
        'Laki-laki',
        'password123'
    ];
    
    for ($i = 0; $i < count($sampleData); $i++) {
        $column = chr(65 + $i);
        $sheet->setCellValue($column . '2', $sampleData[$i]);
    }
    
    // Create the writer and output the file
    $writer = new Xlsx($spreadsheet);

    // Set headers for download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="guru_template.xlsx"');
    header('Cache-Control: max-age=0');

    // Output the file
    $writer->save('php://output');
    exit;
} elseif ($template_type === 'siswa') {
    // Handle student template download
    $template_file = 'templates/siswa_template.xlsx';
    
    // Get class name if class_id is provided
    $class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : null;
    if ($class_id) {
        $stmt = $pdo->prepare('SELECT nama_kelas FROM tb_kelas WHERE id_kelas = ?');
        $stmt->execute([$class_id]);
        $class_info = $stmt->fetch();
        
        if ($class_info) {
            // Sanitize class name for filename
            $sanitized_class_name = preg_replace('/[^a-zA-Z0-9-_]/', '_', $class_info['nama_kelas']);
            $download_filename = 'siswa_template_kelas_' . $sanitized_class_name . '.xlsx';
        } else {
            $download_filename = 'siswa_template.xlsx';
        }
    } else {
        $download_filename = 'siswa_template.xlsx';
    }

    // Check if file exists
    if (!file_exists($template_file)) {
        header('HTTP/1.0 404 Not Found');
        exit('Template file not found');
    }

    // Set headers for Excel file download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $download_filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Expires: 0');
    header('Content-Length: ' . filesize($template_file));

    // Output the file
    readfile($template_file);
    exit;
} else {
    header('HTTP/1.0 400 Bad Request');
    exit('Invalid template type');
}
?>