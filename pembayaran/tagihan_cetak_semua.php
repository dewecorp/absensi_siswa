<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/include/laporan_helper.php';

if (!isset($_SESSION['login'])) {
    header("Location: " . simad_url('login.php'));
    exit;
}

if (!isset($_GET['id_kelas'])) {
    die("Parameter tidak lengkap");
}

$id_kelas = $_GET['id_kelas'];

// Get Data Kelas
$q_kelas = mysqli_query($koneksi, "SELECT nama_kelas FROM kelas WHERE id_kelas = '$id_kelas'");
$d_kelas = mysqli_fetch_assoc($q_kelas);
$nama_kelas = $d_kelas['nama_kelas'];
$is_kelas_alumni = kelas_adalah_alumni($nama_kelas);

// Get Data Siswa
$q_siswa = mysqli_query($koneksi, "SELECT * FROM siswa WHERE id_kelas = '$id_kelas' ORDER BY nama ASC");

// Get School Settings
$q_setting = mysqli_query($koneksi, "SELECT * FROM pengaturan WHERE id_pengaturan = 1");
$d_setting = mysqli_fetch_assoc($q_setting);
$nama_sekolah = $d_setting['nama_sekolah'] ?? 'SMK NEGERI 1 CONTOH';
$nama_bendahara = $d_setting['nama_bendahara'] ?? 'Bendahara Sekolah';
$tahun_ajaran_aktif = $d_setting['tahun_ajaran'] ?? get_tahun_ajaran_aktif($koneksi);
$tahun_ajaran_berjalan = tahun_ajaran_berjalan($koneksi);
$tahun_ajaran_default = $is_kelas_alumni ? (tahun_ajaran_sebelumnya($tahun_ajaran_aktif) ?: $tahun_ajaran_berjalan) : $tahun_ajaran_berjalan;

// Date strings
$tgl = date('d');
$bulan_indo = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April', '05' => 'Mei', '06' => 'Juni',
    '07' => 'Juli', '08' => 'Agustus', '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];
$bln = $bulan_indo[date('m')];
$thn = date('Y');
$tanggal_str = $tgl . ' ' . $bln . ' ' . $thn;

?>
<!DOCTYPE html>
<html>
<head>
    <title>Cetak Tagihan Kelas <?= $nama_kelas ?></title>
    <style>
        /* Atas lebih besar dari samping: aman dari area non-print printer (tepi potong). */
        @page { size: 215mm 330mm; margin: 9mm 2mm 6mm 2mm; }
        body { font-family: sans-serif; font-size: 10pt; margin: 0; padding: 0; }
        
        .container-grid {
            width: 100%;
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-start;
            align-items: flex-start;
            column-gap: 4mm;
            row-gap: 4mm;
        }

        .bill-wrapper {
            width: calc((100% - 4mm) / 2);
            height: 145mm; /* Half page height approx for F4 */
            margin: 0;
            border: 1px solid #999;
            padding: 6px 3px;
            box-sizing: border-box;
            page-break-inside: avoid;
            break-inside: avoid;
            position: relative;
        }
        
        .header { 
            text-align: center; 
            margin-bottom: 5px; 
            position: relative;
            min-height: 50px;
        }
        .header img {
            position: absolute;
            left: 0;
            top: 0;
            max-height: 40px;
            max-width: 40px;
        }
        .header-content {
            margin-left: 45px; /* Space for logo */
        }
        .header h2 { font-size: 14px; margin: 0; }
        .header h3 { font-size: 12px; margin: 2px 0; }
        .header p { font-size: 10px; margin: 0; font-weight: bold; }
        
        .info-siswa { margin-bottom: 5px; }
        .info-siswa table { width: auto; border: none; }
        .info-siswa td { border: none; padding: 1px 5px 1px 0; font-size: 10pt; }
        
        table.data { width: 100%; border-collapse: collapse; margin-top: 2px; }
        table.data th, table.data td { border: 1px solid #000; padding: 2px; text-align: left; vertical-align: top; font-size: 10pt; }
        table.data th { background-color: #f2f2f2; }
        table.data thead th {
            text-align: center;
        }
        table.data tbody tr td:first-child:not([colspan]) {
            text-align: center;
        }
        
        .text-success { color: green; font-weight: bold; }
        .text-danger { color: red; font-weight: bold; }
        
        .signature { margin-top: 5px; float: right; text-align: center; width: 120px; font-size: 10pt; page-break-inside: avoid; break-inside: avoid; }
        .signature p { margin: 1px 0; }
        
        .page-break { page-break-after: always; break-after: page; width: 100%; height: 0; flex-basis: 100%; }
        
        @media print {
            .page-break { page-break-after: always; }
            .bill-wrapper { height: 145mm; overflow: hidden; }
        }
    </style>
</head>
<body onload="window.print()">

<div class="container-grid">

<?php
$counter = 0;
while ($d_siswa = mysqli_fetch_assoc($q_siswa)) {
    $nisn = $d_siswa['nisn'];
    $tahun_ajaran_cetak = $tahun_ajaran_default;
    if ($is_kelas_alumni) {
        $tunggakan_lama = cek_tunggakan_tahun_ajaran_lama($koneksi, $nisn, $tahun_ajaran_aktif);
        if (!empty($tunggakan_lama)) {
            $tahun_ajaran_cetak = (string) array_key_first($tunggakan_lama);
        }
    }

    $tagihan_siswa = cek_tagihan_tunggakan($koneksi, $nisn, $tahun_ajaran_cetak);
    $counter++;
    
    echo '<div class="bill-wrapper">';
    
    // Header
    echo '<div class="header">';
    if (!empty($d_setting['logo'])) {
        echo '<img src="../assets/img/pembayaran/' . $d_setting['logo'] . '" alt="Logo">';
    }
    echo '<div class="header-content">';
    echo '<h2>LAPORAN TAGIHAN SISWA</h2>';
    echo '<h3>' . strtoupper($nama_sekolah) . '</h3>';
    echo '<p>TAHUN AJARAN ' . strtoupper($tahun_ajaran_cetak) . '</p>';
    echo '</div>';
    echo '</div>';
    
    // Info Siswa
    echo '<div class="info-siswa">';
    echo '<table>';
    echo '<tr><td>Nama Siswa</td><td>: ' . $d_siswa['nama'] . '</td></tr>';
    echo '<tr><td>Kelas</td><td>: ' . $nama_kelas . '</td></tr>';
    echo '<tr><td>NISN</td><td>: ' . $d_siswa['nisn'] . '</td></tr>';
    echo '</table>';
    echo '</div>';
    
    // Table
    echo '<table class="data">';
    echo '<thead>';
    echo '<tr>';
    echo '<th width="5%">No</th>';
    echo '<th width="30%">Jenis Pembayaran</th>';
    echo '<th width="20%">Nominal / Tagihan</th>';
    echo '<th>Status Pembayaran</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    $no = 1;
    $total_tagihan = 0;
    $displayed_bills = 0;
    
    if ($tagihan_siswa) {
    // Bulan yang ditampilkan: bulan berjalan saja utk tahun ajaran aktif
    $bulan_tampil = daftar_bulan_tagihan_tampil($koneksi, $tahun_ajaran_cetak);
    foreach ($tagihan_siswa as $tagihan) {
        $unpaid_details = $tagihan['unpaid_details'];
        if ($tagihan['tipe_bayar'] == 'Bulanan' && !empty($bulan_tampil)) {
            $unpaid_details = array_values(array_intersect($unpaid_details, $bulan_tampil));
            if (empty($unpaid_details)) {
                continue;
            }
        }
        $displayed_bills++;
        
        echo "<tr>";
        echo "<td>" . $no++ . "</td>";
        echo "<td>" . $tagihan['nama_pembayaran'] . "</td>";
        echo "<td>";
        echo "Rp " . number_format($tagihan['nominal'], 0, ',', '.');
        echo "</td>";
        echo "<td>";

        if ($tagihan['tipe_bayar'] == 'Bulanan') {
            echo '<div style="display: table; width: 100%;">';
            $month_counter = 0;
            echo '<div style="display: table-row;">';
            foreach ($unpaid_details as $m) {
                echo '<div style="display: table-cell; width: 50%; padding-bottom: 1px; font-size: 10pt;">';
                echo '<span style="color: red;">&#10006;</span> ' . $m;
                echo '</div>';

                $month_counter++;
                if ($month_counter % 2 == 0 && $month_counter < count($unpaid_details)) {
                    echo '</div><div style="display: table-row;">';
                }
            }
            echo '</div></div>';
            $sisa_tampil = array_sum(array_map(static function ($bulan) use ($tagihan) {
                return (int)$tagihan['nominal'];
            }, $unpaid_details));
            $total_tagihan += $sisa_tampil;
            echo '<div class="text-danger" style="margin-top:3px;">Jumlah Tagihan: Rp ' . number_format($sisa_tampil, 0, ',', '.') . '</div>';
            
        } else {
            $total_tagihan += (int)$tagihan['sisa'];
            echo '<span class="text-danger">&#10006; Kurang: Rp ' . number_format($tagihan['sisa'], 0, ',', '.') . '</span>';
        }

        echo "</td>";
        echo "</tr>";
    }
    }
    
    if ($displayed_bills == 0) {
        echo '<tr><td colspan="4" style="text-align: center; font-weight: bold; color: red;">TIDAK ADA TAGIHAN (LUNAS SEMUA)</td></tr>';
    }
    
    echo '<tr>';
    echo '<td colspan="3" style="text-align: right; font-weight: bold;">Total Tagihan Belum Dibayar</td>';
    echo '<td style="font-weight: bold; color: red;">Rp ' . number_format($total_tagihan, 0, ',', '.') . '</td>';
    echo '</tr>';
    
    echo '</tbody>';
    echo '</table>';
    
    echo '<div class="signature">';
    echo '<p>' . $tanggal_str . '</p>';
    echo '<p>Bendahara,</p>';
    $qr_src_bendahara = generate_qr_bendahara($nama_bendahara, $nama_sekolah, 60);
    echo '<img src="' . $qr_src_bendahara . '" alt="QR Bendahara" style="width:60px;height:60px;margin:6px 0;">';
    echo '<p><b>' . $nama_bendahara . '</b></p>';
    echo '</div>';
    
    echo '</div>'; // End bill-wrapper
    
    // Page break every 4 items (2x2 grid)
    if ($counter % 4 == 0) {
        echo '<div class="page-break"></div>';
    }
}
?>

</div>

</body>
</html>
