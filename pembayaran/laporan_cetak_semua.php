<?php
require_once __DIR__ . '/config/config.php';

if (!isset($_GET['id_kelas'])) {
    die("ID Kelas tidak ditemukan!");
}

$id_kelas = mysqli_real_escape_string($koneksi, $_GET['id_kelas']);
$q_kelas = mysqli_query($koneksi, "SELECT * FROM kelas WHERE id_kelas = '$id_kelas'");
$d_kelas = mysqli_fetch_assoc($q_kelas);

if (!$d_kelas) {
    die("Data kelas tidak ditemukan!");
}

if (kelas_adalah_alumni($d_kelas['nama_kelas'] ?? '')) {
    die("Kelas Alumni tidak memiliki laporan tahun ajaran berjalan.");
}

// Ambil info sekolah
$q_info = mysqli_query($koneksi, "SELECT * FROM pengaturan LIMIT 1");
$d_info = mysqli_fetch_assoc($q_info);
$nama_bendahara = $d_info['nama_bendahara'] ?? 'Bendahara';
$nama_sekolah = $d_info['nama_sekolah'] ?? '';
$tahun_ajaran_laporan = trim((string)($d_info['tahun_ajaran'] ?? get_tahun_ajaran_aktif($koneksi)));

// Ambil semua siswa di kelas ini
$q_siswa_all = mysqli_query($koneksi, "SELECT * FROM siswa WHERE id_kelas = '$id_kelas' ORDER BY nama ASC");

// Data Tanggal
$bulan_indo = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April', '05' => 'Mei', '06' => 'Juni',
    '07' => 'Juli', '08' => 'Agustus', '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];
$tgl_cetak = date('d') . ' ' . $bulan_indo[date('m')] . ' ' . date('Y');

$bulan_col1 = ['Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$bulan_col2 = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Cetak Semua Laporan - <?= htmlspecialchars($d_kelas['nama_kelas']) ?></title>
    <style>
        @page { 
            size: 215mm 330mm;
            margin: 9mm 2mm 6mm 2mm;
        }
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
            height: 145mm;
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
            border-bottom: 1px solid #000;
            padding-bottom: 4px;
        }
        .header img {
            position: absolute;
            left: 0;
            top: 0;
            max-height: 40px;
            max-width: 40px;
        }
        .header-content {
            margin-left: 45px;
        }
        .header h2 { font-size: 14px; margin: 0; font-weight: bold; }
        .header h3 { font-size: 12px; margin: 2px 0; }
        
        .info-siswa { margin-bottom: 5px; }
        .info-siswa table { width: 100%; font-size: 10pt; border-collapse: collapse; }
        .info-siswa td { padding: 1px 2px; }

        .payment-section { margin-bottom: 5px; }
        .payment-section h4 { margin: 2px 0; font-size: 10pt; background: #eee; padding: 2px 4px; font-weight: bold; }

        table.print-data-table { width: 100%; border-collapse: collapse; margin-bottom: 3px; }
        table.print-data-table th, table.print-data-table td { border: 1px solid #000; padding: 2px; font-size: 10pt; text-align: center; }
        table.print-data-table th { background-color: #f2f2f2; font-weight: bold; }
        
        .badge-lunas { color: green; font-weight: bold; }
        .badge-belum { color: red; font-weight: bold; }

        .signature { margin-top: 5px; float: right; text-align: center; width: 120px; font-size: 10pt; page-break-inside: avoid; break-inside: avoid; }
        .signature p { margin: 1px 0; }
        .signature img { width: 60px; height: 60px; margin: 6px 0; }
        
        .text-left { text-align: left !important; }
        .text-right { text-align: right !important; }
        
        .page-break { page-break-after: always; break-after: page; width: 100%; height: 0; flex-basis: 100%; }
        
        @media print {
            .page-break { page-break-after: always; }
            .bill-wrapper { height: 145mm; overflow: hidden; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body onload="window.print()">

    <div class="container-grid">
    <?php
    $counter = 0;
    while ($d_siswa = mysqli_fetch_assoc($q_siswa_all)) {
        $nisn = $d_siswa['nisn'];
        $nama_kelas = $d_kelas['nama_kelas'];
        $counter++;
    ?>
    <div class="bill-wrapper">
        <div class="header">
            <?php if (!empty($d_info['logo'])): ?>
                <img src="../assets/img/pembayaran/<?= $d_info['logo'] ?>" alt="Logo">
            <?php endif; ?>
            <div class="header-content">
                <h2>LAPORAN PEMBAYARAN SISWA</h2>
                <h3><?= strtoupper($nama_sekolah) ?></h3>
            </div>
        </div>

        <div class="info-siswa">
            <table>
                <tr>
                    <td width="55"><strong>Nama</strong></td>
                    <td>: <strong><?= $d_siswa['nama'] ?></strong></td>
                </tr>
                <tr>
                    <td><strong>Kelas/NISN</strong></td>
                    <td>: <?= $nama_kelas ?> / <?= $d_siswa['nisn'] ?></td>
                </tr>
                <tr>
                    <td><strong>Th. Ajaran</strong></td>
                    <td>: <?= htmlspecialchars($tahun_ajaran_laporan, ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            </table>
        </div>

        <?php
        $id_kelas_siswa = $d_siswa['id_kelas'];
        $q_jenis = mysqli_query($koneksi, "SELECT * FROM jenis_bayar WHERE status = 'Aktif' ORDER BY tipe_bayar ASC");
        
        $has_data = false;
        while ($d_jenis = mysqli_fetch_assoc($q_jenis)) {
            $applies = jenis_bayar_berlaku_untuk_kelas($d_jenis['tagihan_kelas'] ?? '', $id_kelas_siswa, $nama_kelas);

            if ($applies) {
                $has_data = true;
        ?>
                <div class="payment-section">
                    <h4><?= $d_jenis['nama_pembayaran'] ?> (Rp <?= number_format($d_jenis['nominal'], 0, ',', '.') ?>)</h4>
                    
                    <?php if ($d_jenis['tipe_bayar'] == 'Bulanan') { 
                        $paid_by_month = bulanan_map_pembayaran_per_bulan($koneksi, $nisn, $d_jenis['id_jenis_bayar'], $tahun_ajaran_laporan);
                    ?>
                        <table class="print-data-table">
                            <thead>
                                <tr>
                                    <th>Bln</th>
                                    <th>Sts</th>
                                    <th>Tgl</th>
                                    <th>Bln</th>
                                    <th>Sts</th>
                                    <th>Tgl</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for ($i = 0; $i < 6; $i++): 
                                    $b1 = $bulan_col1[$i];
                                    $d1 = $paid_by_month[$b1] ?? null;
                                    $s1 = $d1 ? '<span class="badge-lunas">&#10004;</span>' : '-';
                                    $t1 = $d1 ? date('d/m', strtotime($d1['tgl_bayar'])) : '-';

                                    $b2 = $bulan_col2[$i];
                                    $d2 = $paid_by_month[$b2] ?? null;
                                    $s2 = $d2 ? '<span class="badge-lunas">&#10004;</span>' : '-';
                                    $t2 = $d2 ? date('d/m', strtotime($d2['tgl_bayar'])) : '-';
                                ?>
                                <tr>
                                    <td class="text-left"><?= $b1 ?></td>
                                    <td><?= $s1 ?></td>
                                    <td><?= $t1 ?></td>
                                    <td class="text-left"><?= $b2 ?></td>
                                    <td><?= $s2 ?></td>
                                    <td><?= $t2 ?></td>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    <?php } else { 
                        $total_bayar = ambil_total_bayar_tersimpan($koneksi, $nisn, $d_jenis['id_jenis_bayar'], $tahun_ajaran_laporan);
                        $sisa = $d_jenis['nominal'] - $total_bayar;
                        $status_lunas = ($sisa <= 0) ? '<span class="badge-lunas">Lunas</span>' : '<span class="badge-belum">Belum Lunas</span>';
                    ?>
                        <table class="print-data-table">
                            <tr>
                                <td class="text-left">Dibayar</td>
                                <td class="text-right">Rp <?= number_format($total_bayar, 0, ',', '.') ?></td>
                            </tr>
                            <tr>
                                <td class="text-left">Sisa</td>
                                <td class="text-right">Rp <?= number_format($sisa > 0 ? $sisa : 0, 0, ',', '.') ?></td>
                            </tr>
                            <tr>
                                <td class="text-left">Status</td>
                                <td class="text-right"><?= $status_lunas ?></td>
                            </tr>
                        </table>
                    <?php } ?>
                </div>
        <?php
            }
        }
        
        if (!$has_data) {
            echo "<p style='text-align:center; font-style:italic;'>Tidak ada tagihan untuk kelas ini.</p>";
        }
        ?>
        
        <div class="signature">
            <p><?= $tgl_cetak ?></p>
            <p>Bendahara,</p>
            <?php $qr_src_bendahara = generate_qr_bendahara($nama_bendahara, $nama_sekolah, 60); ?>
            <img src="<?= $qr_src_bendahara ?>" alt="QR Bendahara" style="width:55px;height:55px;margin:4px 0;">
            <p><b><?= $d_info['nama_bendahara'] ?></b></p>
        </div>
    </div>
    <?php
        // Page break setiap 4 laporan (2 kolom x 2 baris per halaman F4)
        if ($counter % 4 == 0) {
            echo '<div class="page-break"></div>';
        }
    } ?>
    </div>

</body>
</html>
