<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['admin', 'tata_usaha'])) redirect('../login.php');

$tahun = $_POST['tahun_export'] ?? '';
$table_data = $_POST['table_data'] ?? '';

if (!$table_data) {
    header('Content-Type: text/html; charset=utf-8');
    echo 'Tidak ada data untuk diexport.';
    exit;
}

$filename = 'data_prestasi_siswa' . ($tahun ? '_' . $tahun : '') . '.xls';
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

echo '<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40">
<head><meta charset="UTF-8">
<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>
<x:Name>Prestasi</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->
<style>td,th{mso-number-format:"\@";padding:4px 8px;border:1px solid #000;font-size:11px;font-family:Calibri,sans-serif}
th{background:#4472C4;color:#fff;font-weight:bold;text-align:center}
</style></head><body>';
echo $table_data;
echo '</body></html>';
