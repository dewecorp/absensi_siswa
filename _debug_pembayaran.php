<?php
require __DIR__ . '/pembayaran/config/config.php';
header('Content-Type: text/plain');

$q = mysqli_query($koneksi, "SELECT p.id_pembayaran, p.nisn, p.id_jenis_bayar, p.bulan_bayar, p.tahun_bayar, p.tahun_ajaran, p.tgl_bayar, p.jumlah_bayar, jb.tipe_bayar, jb.nama_pembayaran 
FROM pembayaran p 
JOIN jenis_bayar jb ON p.id_jenis_bayar = jb.id_jenis_bayar 
WHERE jb.tipe_bayar = 'Bulanan' 
ORDER BY p.id_pembayaran DESC LIMIT 15");

while ($r = mysqli_fetch_assoc($q)) {
    print_r($r);
}

echo "\n--- PENGATURAN TAHUN AJARAN ---\n";
$qp = mysqli_query($koneksi, "SELECT * FROM pengaturan LIMIT 1");
print_r(mysqli_fetch_assoc($qp));
@unlink(__FILE__);
