<?php
require_once __DIR__ . '/config/config.php';

if (!isset($_GET['no_transaksi'])) {
    echo "No Transaksi tidak ditemukan.";
    exit;
}

$no_transaksi = mysqli_real_escape_string($koneksi, $_GET['no_transaksi']);

// Fetch Transaction Data
$query = mysqli_query($koneksi, "SELECT 
                                    p.*,
                                    s.nama as nama_siswa,
                                    k.nama_kelas,
                                    jb.nama_pembayaran,
                                    jb.tipe_bayar
                                 FROM pembayaran p
                                 JOIN siswa s ON p.nisn = s.nisn
                                 JOIN kelas k ON s.id_kelas = k.id_kelas
                                 JOIN jenis_bayar jb ON p.id_jenis_bayar = jb.id_jenis_bayar
                                 WHERE p.no_transaksi = '$no_transaksi'");

if (mysqli_num_rows($query) == 0) {
    echo "Data transaksi tidak ditemukan.";
    exit;
}

// Fetch first row for header info
$data = [];
while ($row = mysqli_fetch_assoc($query)) {
    $data[] = $row;
}

$header = $data[0];

// Fetch School Settings
$q_setting = mysqli_query($koneksi, "SELECT * FROM pengaturan WHERE id_pengaturan = 1");
$setting = mysqli_fetch_assoc($q_setting);
$nama_bendahara = $setting['nama_bendahara'] ?? 'Bendahara';
$nama_sekolah = $setting['nama_sekolah'] ?? '';
$bulan_indo = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April', '05' => 'Mei', '06' => 'Juni',
    '07' => 'Juli', '08' => 'Agustus', '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];
$tgl_cetak = date('d') . ' ' . $bulan_indo[date('m')] . ' ' . date('Y');

// Filename-style title for print/PDF: kwitansi_nama_siswa_tanggalbayar
$nama_siswa_clean = preg_replace('/[^A-Za-z0-9 ]/', '', strtoupper($header['nama_siswa']));
$nama_siswa_slug = str_replace(' ', '_', $nama_siswa_clean);
$tgl_bayar_slug = str_replace('-', '', $header['tgl_bayar']);
$page_title = "kwitansi_" . $nama_siswa_slug . "_" . $tgl_bayar_slug;

// Helper terbilang
if (!function_exists('penyebut')) {
    function penyebut($nilai) {
        $nilai = abs($nilai);
        $huruf = array("", "satu", "dua", "tiga", "empat", "lima", "enam", "tujuh", "delapan", "sembilan", "sepuluh", "sebelas");
        $temp = "";
        if ($nilai < 12) {
            $temp = " " . $huruf[$nilai];
        } else if ($nilai < 20) {
            $temp = penyebut($nilai - 10) . " belas";
        } else if ($nilai < 100) {
            $temp = penyebut(floor($nilai / 10)) . " puluh" . penyebut($nilai % 10);
        } else if ($nilai < 200) {
            $temp = " seratus" . penyebut($nilai - 100);
        } else if ($nilai < 1000) {
            $temp = penyebut(floor($nilai / 100)) . " ratus" . penyebut($nilai % 100);
        } else if ($nilai < 2000) {
            $temp = " seribu" . penyebut($nilai - 1000);
        } else if ($nilai < 1000000) {
            $temp = penyebut(floor($nilai / 1000)) . " ribu" . penyebut($nilai % 1000);
        } else if ($nilai < 1000000000) {
            $temp = penyebut(floor($nilai / 1000000)) . " juta" . penyebut($nilai % 1000000);
        } else if ($nilai < 1000000000000) {
            $temp = penyebut(floor($nilai / 1000000000)) . " milyar" . penyebut(fmod($nilai, 1000000000));
        }
        return $temp;
    }
}

if (!function_exists('terbilang')) {
    function terbilang($nilai) {
        if ($nilai < 0) {
            $hasil = "minus " . trim(penyebut($nilai));
        } else {
            $hasil = trim(penyebut($nilai));
        }
        return ucwords($hasil) . " Rupiah";
    }
}

$total_bayar = 0;
foreach ($data as $d) {
    $total_bayar += (int)$d['jumlah_bayar'];
}
$terbilang_total = terbilang($total_bayar);
$qr_src_bendahara = generate_qr_bendahara($nama_bendahara, $nama_sekolah, 40);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <style>
        @page {
            size: 215mm 330mm portrait; /* F4 Portrait */
            margin: 4mm 6mm;
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            color: #000;
            margin: 0;
            padding: 5px;
            background-color: #f4f4f4;
        }
        .page-container {
            width: 100%;
            max-width: 205mm;
            margin: 0 auto;
        }
        .kwitansi-item {
            min-height: 78mm;
            background: #fff;
            border: 1.5px solid #333;
            padding: 10px 14px;
            position: relative;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .header-section {
            display: flex;
            align-items: center;
            border-bottom: 2px solid #000;
            padding-bottom: 5px;
            margin-bottom: 8px;
        }
        .header-section img {
            max-height: 45px;
            max-width: 45px;
            margin-right: 12px;
        }
        .header-title-box {
            flex-grow: 1;
        }
        .header-title-box h2 {
            margin: 0;
            font-size: 12pt;
            font-weight: bold;
            text-transform: uppercase;
            line-height: 1.1;
        }
        .header-title-box p {
            margin: 2px 0 0 0;
            font-size: 8.5pt;
            color: #333;
        }
        .kwitansi-meta-right {
            text-align: right;
            white-space: nowrap;
        }
        .kwitansi-meta-right .doc-title {
            font-size: 12pt;
            font-weight: bold;
            text-decoration: underline;
            margin-bottom: 3px;
        }
        .kwitansi-meta-right .doc-no {
            font-size: 9.5pt;
        }
        .info-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
            font-size: 10.5pt;
        }
        .info-grid td {
            padding: 3px 4px;
            vertical-align: top;
        }
        .terbilang-text {
            background-color: #f1f1f1;
            border: 1px dashed #888;
            padding: 4px 10px;
            font-style: italic;
            font-weight: bold;
            font-size: 10pt;
        }
        .detail-table {
            width: 100%;
            border-collapse: collapse;
            margin: 6px 0;
            font-size: 10pt;
        }
        .detail-table th, .detail-table td {
            border: 1px solid #444;
            padding: 5px 8px;
            font-size: 10pt;
        }
        .detail-table th {
            background-color: #e9ecef;
            text-align: center;
            font-weight: bold;
            font-size: 10pt;
        }
        .footer-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 8px;
        }
        .nominal-badge {
            border: 1.5px solid #000;
            padding: 5px 12px;
            font-size: 12.5pt;
            font-weight: bold;
            background: #fff;
            display: inline-block;
        }
        .sign-block {
            text-align: center;
            font-size: 9pt;
            line-height: 1.2;
        }
        .sign-block img {
            width: 40px;
            height: 40px;
            margin: 2px 0;
        }
        .cut-line {
            border-bottom: 1.5px dashed #888;
            margin-top: 6mm;
        }
        @media print {
            body {
                background: #fff;
                padding: 0;
            }
            .page-container {
                max-width: none;
                width: 100%;
            }
            .kwitansi-item {
                border: 1.5px solid #000;
                box-shadow: none;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 12px; text-align: center;">
        <button onclick="window.print()" style="padding: 8px 24px; font-size: 14px; cursor: pointer; font-weight: bold; background: #0d6efd; color: #fff; border: none; border-radius: 4px;">Cetak Kwitansi</button>
    </div>

    <div class="page-container">
        <div class="kwitansi-item">
            <div>
                <div class="header-section">
                    <?php if (!empty($setting['logo'])): ?>
                        <img src="../assets/img/pembayaran/<?= $setting['logo'] ?>" alt="Logo">
                    <?php endif; ?>
                    <div class="header-title-box">
                        <h2><?= strtoupper($setting['nama_sekolah']) ?></h2>
                        <p><?= $setting['alamat_sekolah'] ?></p>
                    </div>
                    <div class="kwitansi-meta-right">
                        <div class="doc-title">KWITANSI PEMBAYARAN</div>
                        <div class="doc-no">No: <strong><?= $header['no_transaksi'] ?></strong> | Tgl: <?= date('d/m/Y', strtotime($header['tgl_bayar'])) ?></div>
                    </div>
                </div>

                <table class="info-grid">
                    <tr>
                        <td width="155" style="white-space: nowrap;"><strong>Telah Terima Dari</strong></td>
                        <td width="8">:</td>
                        <td><strong><?= $header['nama_siswa'] ?></strong> (NISN: <?= $header['nisn'] ?> | Kelas: <?= $header['nama_kelas'] ?>)</td>
                    </tr>
                    <tr>
                        <td style="white-space: nowrap;"><strong>Uang Sejumlah</strong></td>
                        <td>:</td>
                        <td>
                            <div class="terbilang-text">
                                # <?= $terbilang_total ?> #
                            </div>
                        </td>
                    </tr>
                </table>

                <table class="detail-table">
                    <thead>
                        <tr>
                            <th width="5%">No</th>
                            <th>Jenis Pembayaran</th>
                            <th>Keterangan</th>
                            <th width="22%">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = 1;
                        foreach ($data as $d): 
                            $ket = '';
                            if ($d['tipe_bayar'] == 'Bulanan') {
                                $ket = "Bulan: " . $d['bulan_bayar'];
                            } else {
                                $ket = "Cicilan ke-" . $d['cicilan_ke'];
                            }
                            if (!empty($d['tahun_ajaran'])) {
                                $ket .= " (T.A " . $d['tahun_ajaran'] . ")";
                            }
                        ?>
                        <tr>
                            <td style="text-align: center;"><?= $no++ ?></td>
                            <td><?= $d['nama_pembayaran'] ?></td>
                            <td><?= $ket ?></td>
                            <td style="text-align: right;">Rp <?= number_format($d['jumlah_bayar'], 0, ',', '.') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="footer-section">
                <div>
                    <div style="font-size: 7.5pt; font-weight: bold; margin-bottom: 2px;">Total Pembayaran:</div>
                    <div class="nominal-badge">
                        Rp <?= number_format($total_bayar, 0, ',', '.') ?>,-
                    </div>
                </div>

                <div class="sign-block">
                    <div><?= $tgl_cetak ?></div>
                    <div style="font-weight: bold;">Bendahara,</div>
                    <img src="<?= $qr_src_bendahara ?>" alt="QR">
                    <div><u><strong><?= $setting['nama_bendahara'] ?></strong></u></div>
                </div>
            </div>
        </div>
        <div class="cut-line"></div>
    </div>

    <script>
        window.onload = function() {
            window.print();
        }
    </script>
</body>
</html>
