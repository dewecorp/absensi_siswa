<?php
// Script to generate Excel templates for importing data

require_once 'config/database.php';
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!isAuthorized(['admin'])) {
    die('Unauthorized access');
}

// Create a new Spreadsheet object
$spreadsheet = new Spreadsheet();

if (isset($_GET['type']) && $_GET['type'] === 'guru') {
    // Set the active sheet
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
    
    // Set the filename
    $filename = 'guru_template.xlsx';
} else {
    // Default to student template if no type specified or invalid type
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Data Siswa');
    
    // Add headers for student template
    $headers = [
        'Nama Siswa',
        'NISN',
        'Jenis Kelamin',
        'ID Kelas'
    ];
    
    // Write headers
    for ($i = 0; $i < count($headers); $i++) {
        $column = chr(65 + $i);
        $sheet->setCellValue($column . '1', $headers[$i]);
    }
    
    // Set column widths
    $sheet->getColumnDimension('A')->setWidth(20);
    $sheet->getColumnDimension('B')->setWidth(15);
    $sheet->getColumnDimension('C')->setWidth(10);
    $sheet->getColumnDimension('D')->setWidth(10);
    
    // Add sample data row
    $sampleData = [
        'Siswa Satu',
        '1234567890',
        'L',
        '1'
    ];
    
    for ($i = 0; $i < count($sampleData); $i++) {
        $column = chr(65 + $i);
        $sheet->setCellValue($column . '2', $sampleData[$i]);
    }
    
    // Set the filename
    $filename = 'siswa_template.xlsx';
}

// Create the writer and output the file
$writer = new Xlsx($spreadsheet);

// Set headers for download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Output the file
$writer->save('php://output');
?>