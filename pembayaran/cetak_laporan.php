<?php
require_once __DIR__ . '/config/config.php';

if (!isset($_GET['nisn'])) {
    die("NISN tidak ditemukan!");
}

$nisn = mysqli_real_escape_string($koneksi, $_GET['nisn']);
$q_siswa = mysqli_query($koneksi, "SELECT s.*, k.nama_kelas FROM siswa s JOIN kelas k ON s.id_kelas = k.id_kelas WHERE s.nisn = '$nisn'");
$d_siswa = mysqli_fetch_assoc($q_siswa);

if (!$d_siswa) {
    die("Data siswa tidak ditemukan!");
}

if (kelas_adalah_alumni($d_siswa['nama_kelas'] ?? '')) {
    die("Kelas Alumni tidak memiliki laporan tahun ajaran berjalan.");
}

// Ambil info sekolah
$q_info = mysqli_query($koneksi, "SELECT * FROM pengaturan LIMIT 1");
$d_info = mysqli_fetch_assoc($q_info);
$nama_bendahara = $d_info['nama_bendahara'] ?? 'Bendahara';
$nama_sekolah = $d_info['nama_sekolah'] ?? '';
$tahun_ajaran_laporan = isset($_GET['tahun_ajaran']) && trim((string)$_GET['tahun_ajaran']) !== ''
    ? trim((string)$_GET['tahun_ajaran'])
    : trim((string)($d_info['tahun_ajaran'] ?? get_tahun_ajaran_aktif($koneksi)));
$bulan_indo = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April', '05' => 'Mei', '06' => 'Juni',
    '07' => 'Juli', '08' => 'Agustus', '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];
$tgl_cetak = date('d') . ' ' . $bulan_indo[date('m')] . ' ' . date('Y');

$id_kelas_siswa = $d_siswa['id_kelas'];
$nama_kelas_siswa = $d_siswa['nama_kelas'];
$q_jenis = mysqli_query($koneksi, "SELECT * FROM jenis_bayar WHERE status = 'Aktif' ORDER BY tipe_bayar ASC");
$list_jenis = [];
while ($r = mysqli_fetch_assoc($q_jenis)) {
    if (jenis_bayar_berlaku_untuk_kelas($r['tagihan_kelas'] ?? '', $id_kelas_siswa, $nama_kelas_siswa)) {
        $list_jenis[] = $r;
    }
}

$bulan_col1 = ['Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$bulan_col2 = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Laporan Pembayaran - <?= htmlspecialchars($d_siswa['nama']) ?></title>
    <style>
        @page { size: 215mm 330mm; margin: 9mm 2mm 6mm 2mm; }
        body { font-family: sans-serif; font-size: 10pt; margin: 0; padding: 0; }
        .bill-wrapper {
            width: 100%;
            height: 145mm;
            margin: 0 0 4mm 0;
            border: 1px solid #999;
            padding: 6px 3px;
            box-sizing: border-box;
            page-break-inside: avoid;
            break-inside: avoid;
            position: relative;
            overflow: hidden;
        }
        .bill-wrapper:last-child { margin-bottom: 0; }
        .copy-tag {
            position: absolute;
            top: 4px;
            right: 6px;
            font-size: 8pt;
            font-weight: bold;
            color: #555;
            border: 1px solid #aaa;
            padding: 1px 5px;
            background: #fafafa;
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
        .header-content { margin-left: 45px; }
        .header h2 { font-size: 14px; margin: 0; }
        .header h3 { font-size: 12px; margin: 2px 0; }
        .info-siswa { margin-bottom: 5px; }
        .info-siswa table { width: 100%; border: none; border-collapse: collapse; }
        .info-siswa td { border: none; padding: 1px 4px 1px 0; font-size: 10pt; }
        .payment-section { margin-bottom: 5px; }
        .payment-section h4 { margin: 2px 0; font-size: 10pt; background: #eee; padding: 2px 4px; font-weight: bold; }
        table.print-data-table { width: 100%; border-collapse: collapse; margin-bottom: 3px; }
        table.print-data-table th, table.print-data-table td { border: 1px solid #000; padding: 2px; font-size: 10pt; text-align: center; }
        table.print-data-table th { background-color: #f2f2f2; }
        .text-left { text-align: left !important; }
        .text-right { text-align: right !important; }
        .badge-lunas { color: green; font-weight: bold; }
        .badge-belum { color: red; font-weight: bold; }
        .signature { margin-top: 5px; float: right; text-align: center; width: 120px; font-size: 10pt; page-break-inside: avoid; break-inside: avoid; }
        .signature p { margin: 1px 0; }
        .cut-line { border-top: 1px dashed #888; margin: 0 0 4mm 0; }
        @media print {
            .no-print { display: none; }
            .bill-wrapper { height: 145mm; overflow: hidden; }
        }
    </style>
</head>
<body onload="window.print()">
<div class="no-print" style="margin-bottom:10px;text-align:center;">
    <button onclick="window.print()" style="padding:8px 24px;font-size:14px;cursor:pointer;">Cetak Laporan</button>
</div>

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
                <td width="90">Nama Siswa</td><td width="10">:</td><td><strong><?= $d_siswa['nama'] ?></strong></td>
                <td width="90">Kelas</td><td width="10">:</td><td><?= $nama_kelas_siswa ?></td>
            </tr>
            <tr>
                <td>NISN</td><td>:</td><td><?= $d_siswa['nisn'] ?></td>
                <td>Th. Ajaran</td><td>:</td><td><?= htmlspecialchars($tahun_ajaran_laporan, ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        </table>
    </div>

    <?php if (empty($list_jenis)): ?>
        <p style="text-align:center;font-style:italic;">Tidak ada tagihan untuk kelas ini.</p>
    <?php endif; ?>

    <?php foreach ($list_jenis as $d_jenis): ?>
    <div class="payment-section">
        <h4><?= $d_jenis['nama_pembayaran'] ?> (Rp <?= number_format($d_jenis['nominal'], 0, ',', '.') ?>)</h4>
        <?php if ($d_jenis['tipe_bayar'] == 'Bulanan'):
            $paid_by_month = bulanan_map_pembayaran_per_bulan($koneksi, $nisn, $d_jenis['id_jenis_bayar'], $tahun_ajaran_laporan);
        ?>
        <table class="print-data-table">
            <thead>
                <tr><th>Bln</th><th>Status</th><th>Tgl</th><th>Bln</th><th>Status</th><th>Tgl</th></tr>
            </thead>
            <tbody>
                <?php for ($i = 0; $i < 6; $i++):
                    $b1 = $bulan_col1[$i];
                    $d1 = $paid_by_month[$b1] ?? null;
                    $s1 = $d1 ? '<span class="badge-lunas">&#10004;</span>' : '-';
                    $t1 = $d1 ? date('d/m/Y', strtotime($d1['tgl_bayar'])) : '-';
                    $b2 = $bulan_col2[$i];
                    $d2 = $paid_by_month[$b2] ?? null;
                    $s2 = $d2 ? '<span class="badge-lunas">&#10004;</span>' : '-';
                    $t2 = $d2 ? date('d/m/Y', strtotime($d2['tgl_bayar'])) : '-';
                ?>
                <tr>
                    <td class="text-left"><?= $b1 ?></td><td><?= $s1 ?></td><td><?= $t1 ?></td>
                    <td class="text-left"><?= $b2 ?></td><td><?= $s2 ?></td><td><?= $t2 ?></td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
        <?php else:
            $total_bayar = ambil_total_bayar_tersimpan($koneksi, $nisn, $d_jenis['id_jenis_bayar'], $tahun_ajaran_laporan);
            $sisa = $d_jenis['nominal'] - $total_bayar;
            $status_lunas = ($sisa <= 0) ? '<span class="badge-lunas">Lunas</span>' : '<span class="badge-belum">Belum Lunas</span>';
        ?>
        <table class="print-data-table">
            <tr><td class="text-left">Dibayar</td><td class="text-right">Rp <?= number_format($total_bayar, 0, ',', '.') ?></td></tr>
            <tr><td class="text-left">Sisa</td><td class="text-right">Rp <?= number_format($sisa > 0 ? $sisa : 0, 0, ',', '.') ?></td></tr>
            <tr><td class="text-left">Status</td><td class="text-right"><?= $status_lunas ?></td></tr>
        </table>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="signature">
        <p><?= $tgl_cetak ?></p>
        <p>Bendahara,</p>
        <?php $qr_src_bendahara = generate_qr_bendahara($nama_bendahara, $nama_sekolah, 60); ?>
        <img src="<?= $qr_src_bendahara ?>" alt="QR Bendahara" style="width:60px;height:60px;margin:6px 0;">
        <p><b><?= $d_info['nama_bendahara'] ?></b></p>
    </div>
</div>
<div class="cut-line"></div>

</body>
</html>
