<?php
// Buffer paling awal: tangkap output liar (notice/warning/BOM) sebelum header file.
ob_start();
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is authorized
if (!isAuthorized(['admin'])) {
    http_response_code(403);
    die('Akses ditolak (403): sesi kedaluwarsa atau bukan admin. Silakan login ulang sebagai admin, lalu unduh lagi.');
}

// Bersihkan SEMUA output buffer (termasuk penerjemah tanggal global di
// functions.php) agar biner XLSX tidak rusak saat diunduh.
while (ob_get_level() > 0) { ob_end_clean(); }

// Load PhpSpreadsheet
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    die('PhpSpreadsheet not found. Please run composer install.');
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

try {
    $type = $_GET['type'] ?? '';
    if (!$type || $type === '') $type = 'siswa';
    $kelas_id = $_GET['kelas_id'] ?? $_GET['class_id'] ?? '';
    $kelas_name = '';

    if ($kelas_id) {
        $stmt = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas = ?");
        $stmt->execute([$kelas_id]);
        $kelas_name = $stmt->fetchColumn();
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    if ($type === 'siswa') {
        // Student Template
        $safe_kelas_name = $kelas_name ? str_replace(' ', '_', strtolower($kelas_name)) : 'semua';
        $filename = 'template_impor_siswa_kelas_' . $safe_kelas_name . '.xlsx';
        $headers = [
            'Nama Siswa',
            'NISN',
            'Jenis Kelamin (L/P)',
            'Tempat Lahir',
            'Tanggal Lahir (YYYY-MM-DD)',
            'Orang Tua/Wali',
            'Kelas ID'
        ];
        
        // Kolom NISN sebagai teks agar tidak dijadikan angka Excel (hilang nol depan → dobel di impor).
        $sheet->getStyle('B:B')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        // Add sample data
        $sheet->setCellValue('A2', 'Budi Santoso');
        $sheet->getCell('B2')->setValueExplicit('1234567890', DataType::TYPE_STRING);
        $sheet->setCellValue('C2', 'L');
        $sheet->setCellValue('D2', 'Jakarta');
        $sheet->setCellValue('E2', '2010-01-01');
        $sheet->setCellValue('F2', 'Slamet');
        $sheet->setCellValue('G2', $kelas_id ?: '1');
        
        $sheet->getComment('C1')->getText()->createTextRun('Isi dengan L untuk Laki-laki atau P untuk Perempuan');
        $sheet->getComment('E1')->getText()->createTextRun('Gunakan format TAHUN-BULAN-HARI (contoh: 2010-05-20)');
        $sheet->getComment('G1')->getText()->createTextRun('ID Kelas bisa dilihat di menu Data Kelas');

    } else if ($type === 'guru') {
        // Teacher Template
        $filename = 'template_impor_guru.xlsx';
        $headers = [
            'Nama Guru',
            'NUPTK',
            'Tempat Lahir',
            'Tanggal Lahir (YYYY-MM-DD)',
            'Jenis Kelamin (L/P)',
            'Pendidikan (SLTA/D1/D2/D3/S1/S2/S3)',
            'Password'
        ];
    } else {
        die('Invalid template type');
    }

    // Set Headers
    $column = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($column . '1', $header);
        $sheet->getColumnDimension($column)->setAutoSize(true);
        $column++;
    }

    // Style Header
    $last_column_index = count($headers) - 1;
    $last_column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($last_column_index + 1);
    
    $styleArray = [
        'font' => ['bold' => true],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'E2EFDA']
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
            ],
        ],
    ];
    $sheet->getStyle('A1:' . $last_column . '1')->applyFromArray($styleArray);

    // Final check for any output
    while (ob_get_level() > 0) { ob_end_clean(); }

    // Render ke memori dulu (tanpa temp file agar tak gagal permission),
    // lalu kirim dengan Content-Length agar browser yakin ini file utuh.
    ob_start();
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    $bin = (string)ob_get_clean();
    if (strlen($bin) < 1000 || substr($bin, 0, 2) !== 'PK') {
        http_response_code(500);
        die('Gagal membuat template (data rusak). Silakan coba lagi.');
    }

    // Output to browser
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: ' . strlen($bin));
    header('Cache-Control: max-age=0, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');

    echo $bin;
    exit;

} catch (Exception $e) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    die('Error creating template: ' . $e->getMessage());
}
