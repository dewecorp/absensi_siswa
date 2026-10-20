<?php
require_once __DIR__ . '/config/config.php';

if (!isset($_SESSION['login'])) {
    header("Location: " . simad_url('login.php'));
    exit;
}

$query = mysqli_query($koneksi, "SELECT pembayaran.*, siswa.nama AS nama_siswa, kelas.nama_kelas, jenis_bayar.nama_pembayaran, jenis_bayar.tipe_bayar 
                                 FROM pembayaran 
                                 JOIN siswa ON pembayaran.nisn = siswa.nisn 
                                 JOIN kelas ON siswa.id_kelas = kelas.id_kelas 
                                 JOIN jenis_bayar ON pembayaran.id_jenis_bayar = jenis_bayar.id_jenis_bayar 
                                 ORDER BY pembayaran.created_at DESC, pembayaran.id_pembayaran DESC");

$q_setting = mysqli_query($koneksi, "SELECT * FROM pengaturan WHERE id_pengaturan = 1");
$d_setting = mysqli_fetch_assoc($q_setting);
$nama_bendahara = $d_setting['nama_bendahara'] ?? 'Bendahara';
$nama_sekolah = $d_setting['nama_sekolah'] ?? '';
$bulan_indo = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April', '05' => 'Mei', '06' => 'Juni',
    '07' => 'Juli', '08' => 'Agustus', '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];
$tgl_cetak = date('d') . ' ' . $bulan_indo[date('m')] . ' ' . date('Y');

?>
<!DOCTYPE html>
<html>
<head>
    <title>Export PDF - Transaksi Pembayaran</title>
    <style>
        body { font-family: sans-serif; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #000; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        h2 { text-align: center; }
        .signature { margin-top: 40px; text-align: right; page-break-inside: avoid; break-inside: avoid; }
    </style>
</head>
<body onload="window.print()">
    <h2>LAPORAN TRANSAKSI PEMBAYARAN</h2>
    <p>Tanggal Cetak: <?= date('d/m/Y H:i:s') ?></p>
    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Nama Siswa</th>
                <th>Kelas</th>
                <th>Jenis Bayar</th>
                <th>Cicilan Ke</th>
                <th>Nominal</th>
                <th>Tanggal</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $no = 1;
            while($row = mysqli_fetch_assoc($query)) :
            ?>
            <tr>
                <td><?= $no++ ?></td>
                <td><?= $row['nama_siswa'] ?></td>
                <td><?= $row['nama_kelas'] ?></td>
                <td><?= $row['nama_pembayaran'] ?></td>
                <td><?= ($row['tipe_bayar'] == 'Cicilan' ? $row['cicilan_ke'] : '-') ?></td>
                <td>Rp <?= number_format($row['jumlah_bayar'], 0, ',', '.') ?></td>
                <td><?= date('d/m/Y', strtotime($row['tgl_bayar'])) ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

    <?php $qr_src_bendahara = generate_qr_bendahara($nama_bendahara, $nama_sekolah, 60); ?>
    <div class="signature">
        <p><?= $tgl_cetak ?></p>
        <p>Bendahara</p>
        <img src="<?= $qr_src_bendahara ?>" alt="QR Bendahara" style="width:60px;height:60px;margin:6px 0;">
        <p><b><?= $nama_bendahara ?></b></p>
    </div>
</body>
</html>
