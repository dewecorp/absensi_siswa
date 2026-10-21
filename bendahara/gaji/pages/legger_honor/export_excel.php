<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

// Get periode from GET or use periode_aktif from settings
$periode = $_GET['periode'] ?? null;
if (empty($periode)) {
    $sql_settings = "SELECT periode_aktif FROM settings LIMIT 1";
    $result_settings = $conn->query($sql_settings);
    if ($result_settings && $row_settings = $result_settings->fetch_assoc()) {
        $periode = $row_settings['periode_aktif'] ?? date('Y-m');
    } else {
        $periode = date('Y-m');
    }
}

// Load PhpSpreadsheet
$vendorPath = dirname(__DIR__, 4) . '/vendor/autoload.php';
if (file_exists($vendorPath)) {
    require_once $vendorPath;
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

function exportLeggerHonorCsv($conn, $periode) {
    $sql = "SELECT lh.*, p.nama_pembina, e.jenis_ekstrakurikuler, h.jabatan, h.jumlah_honor as honor_per_pertemuan
            FROM legger_honor lh
            JOIN pembina p ON lh.pembina_id = p.id
            JOIN ekstrakurikuler e ON lh.ekstrakurikuler_id = e.id
            JOIN honor h ON lh.honor_id = h.id
            WHERE lh.periode = ?
            ORDER BY p.nama_pembina ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $periode);
    $stmt->execute();
    $legger = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $filename = 'Legger_Honor_' . preg_replace('/[^0-9\-]/', '', $periode) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');

    fputcsv($out, ['No', 'Nama Pembina', 'Ekstrakurikuler', 'Jabatan', 'Honor per Pertemuan', 'Jumlah Pertemuan', 'Total Honor'], ';');
    $no = 1;
    foreach ($legger as $l) {
        $row = [
            $no++,
            $l['nama_pembina'] ?? '',
            $l['jenis_ekstrakurikuler'] ?? '',
            $l['jabatan'] ?? '',
            formatRupiahTanpaRp(floatval($l['honor_per_pertemuan'] ?? 0)),
            $l['jumlah_pertemuan'] ?? ($l['jumlah_pertemuan_honor'] ?? ''),
            formatRupiahTanpaRp(floatval($l['total_honor'] ?? 0)),
        ];
        fputcsv($out, $row, ';');
    }

    fclose($out);
}

if (!class_exists(Spreadsheet::class)) {
    exportLeggerHonorCsv($conn, $periode);
    exit();
}

try {
    // Get legger_honor data
    $sql = "SELECT lh.*, p.nama_pembina, e.jenis_ekstrakurikuler, h.jabatan, h.jumlah_honor as honor_per_pertemuan
            FROM legger_honor lh
            JOIN pembina p ON lh.pembina_id = p.id
            JOIN ekstrakurikuler e ON lh.ekstrakurikuler_id = e.id
            JOIN honor h ON lh.honor_id = h.id
            WHERE lh.periode = ?
            ORDER BY p.nama_pembina ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $periode);
    $stmt->execute();
    $legger = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get settings
    $sql_settings = "SELECT * FROM settings LIMIT 1";
    $settings = $conn->query($sql_settings)->fetch_assoc();
    // Identitas madrasah & pejabat mengikuti Pengaturan Admin SIMAD
    $settings = array_merge($settings ?: [], gajiProfilMadrasah($conn));
    
    // Create new Spreadsheet object
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Set sheet title
    $sheet->setTitle('Legger Honor');
    
    // Set header row 1 (Title)
    $sheet->setCellValue('A1', $settings['nama_madrasah'] ?? 'MADRASAH IBTIDAIYAH');
    $sheet->mergeCells('A1:G1');
    $sheet->getStyle('A1')->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 16,
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);
    
    // Set header row 2 (Subtitle)
    $sheet->setCellValue('A2', 'LEGGER HONOR');
    $sheet->mergeCells('A2:G2');
    $sheet->getStyle('A2')->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 14,
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);
    
    // Set header row 3 (Period)
    $sheet->setCellValue('A3', 'Periode: ' . getPeriodLabel($periode));
    $sheet->mergeCells('A3:G3');
    $sheet->getStyle('A3')->applyFromArray([
        'font' => [
            'bold' => true,
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);
    
    // Set header row 4 (empty)
    $sheet->setCellValue('A4', '');
    
    // Set header row 5 (Column headers)
    $headers = ['No', 'Nama Pembina', 'Jabatan', 'Honor per Pertemuan', 'Jumlah Pertemuan', 'Total Honor', 'Tanda Tangan'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . '5', $header);
        $sheet->getStyle($col . '5')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);
        $col++;
    }
    
    // Set data rows
    $row = 6;
    $no = 1;
    $total_honor = 0;
    foreach ($legger as $l) {
        $sheet->setCellValue('A' . $row, $no++);
        $sheet->setCellValue('B' . $row, $l['nama_pembina']);
        $sheet->setCellValue('C' . $row, $l['jabatan']);
        $sheet->setCellValue('D' . $row, $l['jumlah_honor_per_pertemuan']);
        $sheet->setCellValue('E' . $row, $l['jumlah_pertemuan']);
        $sheet->setCellValue('F' . $row, $l['total_honor']);
        $sheet->setCellValue('G' . $row, '');
        
        // Format currency for columns D and F
        $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0');
        
        // Apply borders
        foreach (range('A', 'G') as $col) {
            $sheet->getStyle($col . $row)->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                    ],
                ],
            ]);
        }
        
        // Alignments
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('F' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('G' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $total_honor += $l['total_honor'];
        $row++;
    }
    
    // Add total row
    $sheet->setCellValue('A' . $row, 'TOTAL');
    $sheet->mergeCells('A' . $row . ':E' . $row);
    $sheet->setCellValue('F' . $row, $total_honor);
    $sheet->setCellValue('G' . $row, '');
    
    // Format total row
    $sheet->getStyle('A' . $row . ':G' . $row)->applyFromArray([
        'font' => [
            'bold' => true,
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'E7E6E6'],
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
            ],
        ],
    ]);
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('F' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0');
    
    // Set column widths
    $sheet->getColumnDimension('A')->setWidth(8);
    $sheet->getColumnDimension('B')->setWidth(25);
    $sheet->getColumnDimension('C')->setWidth(25);
    $sheet->getColumnDimension('D')->setWidth(18);
    $sheet->getColumnDimension('E')->setWidth(15);
    $sheet->getColumnDimension('F')->setWidth(18);
    $sheet->getColumnDimension('G')->setWidth(20);
    
    // Set filename
    $filename = 'Legger_Honor_' . str_replace('-', '_', $periode) . '_' . date('Ymd') . '.xlsx';
    
    // Set headers for download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    // Write file to output
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit();
    
} catch (Exception $e) {
    // Fallback: redirect with error
    $_SESSION['error'] = 'Gagal export Excel: ' . $e->getMessage();
    header('Location: ' . BASE_URL . 'pages/legger_honor');
    exit();
}
?>
