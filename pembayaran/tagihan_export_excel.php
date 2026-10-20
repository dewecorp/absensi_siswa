<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/include/laporan_helper.php';

if (!isset($_SESSION['login'])) {
    header("Location: " . simad_url('login.php'));
    exit;
}

if (!isset($_GET['nisn']) || !isset($_GET['id_kelas'])) {
    die("Parameter tidak lengkap");
}

$nisn = $_GET['nisn'];
$id_kelas = $_GET['id_kelas'];

// Get Data Siswa
$q_siswa = mysqli_query($koneksi, "SELECT siswa.*, kelas.nama_kelas FROM siswa JOIN kelas ON siswa.id_kelas = kelas.id_kelas WHERE siswa.nisn = '$nisn'");
$d_siswa = mysqli_fetch_assoc($q_siswa);
$nama_kelas = $d_siswa['nama_kelas'];

// Autoload for PHP 7.4 compatibility
if (file_exists(__DIR__ . '/vendor/autoload_simple.php')) {
    require __DIR__ . '/vendor/autoload_simple.php';
} elseif (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

// Get School Settings
$q_setting = mysqli_query($koneksi, "SELECT * FROM pengaturan WHERE id_pengaturan = 1");
$d_setting = mysqli_fetch_assoc($q_setting);
$nama_sekolah = $d_setting['nama_sekolah'] ?? 'SMK NEGERI 1 CONTOH';
$nama_bendahara = $d_setting['nama_bendahara'] ?? 'Bendahara Sekolah';
$tahun_ajaran = $d_setting['tahun_ajaran'] ?? '';
$boleh_ditagihkan = tahun_ajaran_boleh_ditagihkan($koneksi, $tahun_ajaran);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Info Siswa
$sheet->setCellValue('A1', 'LAPORAN TAGIHAN SISWA');
$sheet->setCellValue('A2', strtoupper($nama_sekolah));
$sheet->setCellValue('A3', 'TAHUN AJARAN ' . strtoupper($tahun_ajaran));
$sheet->mergeCells('A1:E1');
$sheet->mergeCells('A2:E2');
$sheet->mergeCells('A3:E3');
$sheet->getStyle('A1:A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A1:A2')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A3')->getFont()->setBold(true)->setSize(12);

$row_info = 5;
$sheet->setCellValue('A' . $row_info, 'Nama Siswa');
$sheet->setCellValue('B' . $row_info, ': ' . $d_siswa['nama']);
$row_info++;
$sheet->setCellValue('A' . $row_info, 'Kelas');
$sheet->setCellValue('B' . $row_info, ': ' . $nama_kelas);
$row_info++;
$sheet->setCellValue('A' . $row_info, 'NISN');
$sheet->setCellValue('B' . $row_info, ': ' . $d_siswa['nisn']);

// Header Tabel
$row = $row_info + 2;
$sheet->setCellValue('A' . $row, 'No');
$sheet->setCellValue('B' . $row, 'Jenis Pembayaran');
$sheet->setCellValue('C' . $row, 'Tipe');
$sheet->setCellValue('D' . $row, 'Nominal / Tagihan');
$sheet->setCellValue('E' . $row, 'Status Pembayaran');

// Style Header
$sheet->getStyle('A'.$row.':E'.$row)->getFont()->setBold(true);
$sheet->getStyle('A'.$row.':E'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A'.$row.':E'.$row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle('A'.$row.':E'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF2F2F2');

$sheet->getColumnDimension('A')->setWidth(5);
$sheet->getColumnDimension('B')->setWidth(25);
$sheet->getColumnDimension('C')->setWidth(15);
$sheet->getColumnDimension('D')->setWidth(20);
$sheet->getColumnDimension('E')->setWidth(50);

$row++;
$no = 1;
$total_tagihan = 0;

$q_jb = mysqli_query($koneksi, "SELECT * FROM jenis_bayar WHERE status = 'Aktif' ORDER BY tipe_bayar ASC, nama_pembayaran ASC");

// Bulan yang ditampilkan: bulan berjalan saja utk tahun ajaran aktif
$months = daftar_bulan_tagihan_tampil($koneksi, $tahun_ajaran);

// Calculate current month index (relative to school year starting July)
$limit_index = limit_index_bulan_tahun_ajaran($koneksi, $tahun_ajaran);

while ($jb = mysqli_fetch_assoc($q_jb)) {
    if (!$boleh_ditagihkan) {
        continue;
    }

    // Filter by Class
    if (!empty($jb['tagihan_kelas'])) {
        $allowed_kelas = array_map('trim', explode(',', $jb['tagihan_kelas']));
        if (!in_array($nama_kelas, $allowed_kelas)) {
            continue;
        }
    }

    $sheet->setCellValue('A' . $row, $no++);
    $sheet->setCellValue('B' . $row, $jb['nama_pembayaran']);
    $sheet->setCellValue('C' . $row, $jb['tipe_bayar']);
    $sheet->setCellValue('D' . $row, 'Rp ' . number_format($jb['nominal'], 0, ',', '.'));
    
    $status_text = "";
    
    if ($jb['tipe_bayar'] == 'Bulanan') {
        $q_bayar = mysqli_query($koneksi, "SELECT bulan_bayar FROM pembayaran WHERE nisn='$nisn' AND id_jenis_bayar='" . $jb['id_jenis_bayar'] . "'");
        $paid_months = [];
        while ($r = mysqli_fetch_assoc($q_bayar)) {
            if (!empty($r['bulan_bayar'])) {
                $ms = array_map('trim', explode(',', $r['bulan_bayar']));
                $paid_months = array_merge($paid_months, $ms);
            }
        }
        
        $status_parts = [];
        foreach ($months as $m) {
            $is_paid = in_array($m, $paid_months);
            
            if (!$is_paid) {
                $total_tagihan += $jb['nominal'];
            }

            if ($is_paid) {
                $status_parts[] = "[V] " . $m;
            } else {
                $status_parts[] = "[X] " . $m;
            }
        }
        $status_text = implode(", ", $status_parts);
        
    } else {
        $q_total = mysqli_query($koneksi, "SELECT SUM(jumlah_bayar) as total FROM pembayaran WHERE nisn='$nisn' AND id_jenis_bayar='" . $jb['id_jenis_bayar'] . "'");
        $d_total = mysqli_fetch_assoc($q_total);
        $total_bayar = $d_total['total'] ?? 0;
        $sisa = $jb['nominal'] - $total_bayar;
        
        if ($sisa > 0) {
            $total_tagihan += $sisa;
        }

        if ($sisa <= 0) {
            $status_text = "LUNAS";
        } else {
            $status_text = "Kurang: Rp " . number_format($sisa, 0, ',', '.');
        }
    }
    
    $sheet->setCellValue('E' . $row, $status_text);
    $sheet->getStyle('E' . $row)->getAlignment()->setWrapText(true);
    $sheet->getStyle('A' . $row . ':E' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $row++;
}

// Total Row
$sheet->setCellValue('A' . $row, 'Total Tagihan Belum Dibayar');
$sheet->mergeCells('A' . $row . ':D' . $row);
$sheet->setCellValue('E' . $row, 'Rp ' . number_format($total_tagihan, 0, ',', '.'));

$sheet->getStyle('A' . $row . ':E' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row . ':E' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle('E' . $row)->getFont()->getColor()->setARGB(Color::COLOR_RED);

// Signature
$row += 2;
$tgl = date('d');
$bulan_indo = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April', '05' => 'Mei', '06' => 'Juni',
    '07' => 'Juli', '08' => 'Agustus', '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];
$bln = $bulan_indo[date('m')];
$thn = date('Y');
$tanggal_str = $tgl . ' ' . $bln . ' ' . $thn;

$sheet->setCellValue('E' . $row, $tanggal_str);
$sheet->getStyle('E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;
$sheet->setCellValue('E' . $row, 'Bendahara,');
$sheet->getStyle('E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row += 4;
$sheet->setCellValue('E' . $row, $nama_bendahara);
$sheet->getStyle('E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('E' . $row)->getFont()->setBold(true)->setUnderline(true);

$writer = new Xlsx($spreadsheet);
$filename = 'Tagihan_' . str_replace(' ', '_', $d_siswa['nama']) . '_' . date('YmdHis') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="'. $filename .'"');
header('Cache-Control: max-age=0');

$writer->save('php://output');
?>
