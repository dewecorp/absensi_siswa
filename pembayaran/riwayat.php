<?php
$title = 'Riwayat Pembayaran';
include __DIR__ . '/template/header.php';
include __DIR__ . '/template/sidebar.php';
?>

<div class="flex flex-wrap -mx-4">
    <div class="w-full px-4 mb-6">
        <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6">
            <h4 class="text-xl font-semibold text-gray-800 mb-4">Riwayat Pembayaran Global</h4>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200" id="table-riwayat">
                    <thead>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">No</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">No Transaksi</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Siswa</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kelas</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tanggal</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Waktu</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Jenis Pembayaran</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Jumlah</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Petugas</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php
                        $no = 1;
                        $query = mysqli_query($koneksi, "SELECT pembayaran.*, siswa.nama, kelas.nama_kelas, jenis_bayar.nama_pembayaran, pengguna.nama_lengkap FROM pembayaran 
                            JOIN siswa ON pembayaran.nisn = siswa.nisn 
                            JOIN kelas ON siswa.id_kelas = kelas.id_kelas
                            JOIN jenis_bayar ON pembayaran.id_jenis_bayar = jenis_bayar.id_jenis_bayar 
                            JOIN pengguna ON pembayaran.id_petugas = pengguna.id_pengguna 
                            ORDER BY created_at DESC");
                        while ($row = mysqli_fetch_assoc($query)) :
                        ?>
                            <tr>
                                <td class="px-4 py-3 whitespace-nowrap"><?= $no++ ?></td>
                                <td class="px-4 py-3 whitespace-nowrap"><?= $row['no_transaksi'] ? $row['no_transaksi'] : '<span class="inline-flex px-2 py-0.5 text-xs font-medium rounded-full bg-amber-100 text-amber-800">Old/Null</span>' ?></td>
                                <td class="px-4 py-3"><?= $row['nama'] ?> <br> <small class="text-gray-500"><?= $row['nisn'] ?></small></td>
                                <td class="px-4 py-3 whitespace-nowrap"><?= $row['nama_kelas'] ?></td>
                                <td class="px-4 py-3 whitespace-nowrap"><?= date('d/m/Y', strtotime($row['tgl_bayar'])) ?></td>
                                <td class="px-4 py-3 whitespace-nowrap"><?= date('H:i', strtotime($row['created_at'])) ?></td>
                                <td class="px-4 py-3"><?= $row['nama_pembayaran'] ?> <br> <small class="text-gray-500"><?= $row['bulan_bayar'] ?> <?= $row['tahun_bayar'] ?></small></td>
                                <td class="px-4 py-3 whitespace-nowrap font-semibold">Rp <?= number_format($row['jumlah_bayar'], 0, ',', '.') ?></td>
                                <td class="px-4 py-3 whitespace-nowrap"><?= $row['nama_lengkap'] ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/template/footer.php'; ?>
