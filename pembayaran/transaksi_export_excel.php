<?php
require_once __DIR__ . '/config/config.php';

if (!isset($_SESSION['login'])) {
    header("Location: " . simad_url('login.php'));
    exit;
}

// Autoload for PHP 7.4 compatibility
if (file_exists(__DIR__ . '/vendor/autoload_simple.php')) {
    require __DIR__ . '/vendor/autoload_simple.php';
} elseif (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Header
$sheet->setCellValue('A1', 'No');
$sheet->setCellValue('B1', 'Nama Siswa');
$sheet->setCellValue('C1', 'Kelas');
$sheet->setCellValue('D1', 'Jenis Bayar');
$sheet->setCellValue('E1', 'Cicilan Ke');
$sheet->setCellValue('F1', 'Nominal');
$sheet->setCellValue('G1', 'Tanggal');

$query = mysqli_query($koneksi, "SELECT pembayaran.*, siswa.nama AS nama_siswa, kelas.nama_kelas, jenis_bayar.nama_pembayaran, jenis_bayar.tipe_bayar 
                                 FROM pembayaran 
                                 JOIN siswa ON pembayaran.nisn = siswa.nisn 
                                 JOIN kelas ON siswa.id_kelas = kelas.id_kelas 
                                 JOIN jenis_bayar ON pembayaran.id_jenis_bayar = jenis_bayar.id_jenis_bayar 
                                 ORDER BY pembayaran.created_at DESC, pembayaran.id_pembayaran DESC");

$i = 2;
$no = 1;
while($row = mysqli_fetch_assoc($query)) {
    $sheet->setCellValue('A' . $i, $no++);
    $sheet->setCellValue('B' . $i, $row['nama_siswa']);
    $sheet->setCellValue('C' . $i, $row['nama_kelas']);
    $sheet->setCellValue('D' . $i, $row['nama_pembayaran']);
    $sheet->setCellValue('E' . $i, ($row['tipe_bayar'] == 'Cicilan' ? $row['cicilan_ke'] : '-'));
    $sheet->setCellValue('F' . $i, $row['jumlah_bayar']);
    $sheet->setCellValue('G' . $i, date('d/m/Y', strtotime($row['tgl_bayar'])));
    $i++;
}

$writer = new Xlsx($spreadsheet);
$filename = 'Data_Transaksi_SPP_' . date('YmdHis') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="'. $filename .'"');
header('Cache-Control: max-age=0');

$writer->save('php://output');
exit;
?>