<?php
$title = 'Riwayat Pembayaran';
include __DIR__ . '/../templates/pembayaran_header.php';
?>

<div class="row">
    <div class="col-12 mb-4">
        <div class="card">
            <div class="card-header">
                <h4>Riwayat Pembayaran Global</h4>
            </div>
            <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-bordered pembayaran-table" id="table-riwayat">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>No Transaksi</th>
                            <th>Siswa</th>
                            <th>Kelas</th>
                            <th>Tanggal</th>
                            <th>Waktu</th>
                            <th>Jenis Pembayaran</th>
                            <th>Jumlah</th>
                            <th>Petugas</th>
                        </tr>
                    </thead>
                    <tbody>
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
                                <td data-label="No" class="text-nowrap"><?= $no++ ?></td>
                                <td data-label="No Transaksi" class="text-nowrap"><?= $row['no_transaksi'] ? $row['no_transaksi'] : '<span class="badge badge-warning">Old/Null</span>' ?></td>
                                <td data-label="Siswa"><?= $row['nama'] ?> <br> <small class="text-muted"><?= $row['nisn'] ?></small></td>
                                <td data-label="Kelas" class="text-nowrap"><?= $row['nama_kelas'] ?></td>
                                <td data-label="Tanggal" class="text-nowrap"><?= date('d/m/Y', strtotime($row['tgl_bayar'])) ?></td>
                                <td data-label="Waktu" class="text-nowrap"><?= date('H:i', strtotime($row['created_at'])) ?></td>
                                <td data-label="Jenis Pembayaran"><?= $row['nama_pembayaran'] ?> <br> <small class="text-muted"><?= $row['bulan_bayar'] ?> <?= $row['tahun_bayar'] ?></small></td>
                                <td data-label="Jumlah" class="text-nowrap font-weight-bold">Rp <?= number_format($row['jumlah_bayar'], 0, ',', '.') ?></td>
                                <td data-label="Petugas" class="text-nowrap"><?= $row['nama_lengkap'] ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../templates/pembayaran_footer.php'; ?>
