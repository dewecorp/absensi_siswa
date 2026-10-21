<?php
$title = 'Detail Laporan Pembayaran';
include __DIR__ . '/../templates/pembayaran_header.php';

$nisn = $_GET['nisn'];
$q_siswa = mysqli_query($koneksi, "SELECT * FROM siswa JOIN kelas ON siswa.id_kelas = kelas.id_kelas WHERE nisn = '$nisn'");
$d_siswa = mysqli_fetch_assoc($q_siswa);

if (!$d_siswa) {
    echo "<script>alert('Data siswa tidak ditemukan!'); window.location='laporan.php';</script>";
    exit;
}

if (kelas_adalah_alumni($d_siswa['nama_kelas'] ?? '')) {
    echo "<script>alert('Kelas Alumni tidak memiliki laporan tahun ajaran berjalan.'); window.location='laporan.php';</script>";
    exit;
}

$tahun_ajaran_laporan = get_tahun_ajaran_aktif($koneksi);
?>

<div class="card mb-4">
    <div class="card-body">
            <div class="d-flex flex-wrap align-items-center justify-content-between mb-3" style="gap:12px;">
                <h4 class="mb-0">Laporan Pembayaran Siswa</h4>
                <div class="d-flex align-items-center" style="gap:8px;">
                    <a href="cetak_laporan.php?nisn=<?= $nisn ?>" target="_blank" class="btn btn-warning">
                        <i class="fas fa-print mr-1"></i> Cetak Laporan
                    </a>
                    <a href="laporan.php?id_kelas=<?= $d_siswa['id_kelas'] ?>" class="btn btn-outline-primary">
                        <i class="fas fa-arrow-left mr-1"></i> Kembali
                    </a>
                </div>
            </div>

            <div class="row bg-light rounded p-3 mb-4">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <th class="w-50">NISN</th>
                            <td>: <?= $d_siswa['nisn'] ?></td>
                        </tr>
                        <tr>
                            <th>Nama Siswa</th>
                            <td>: <?= $d_siswa['nama'] ?></td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <th class="w-50">Kelas</th>
                            <td>: <?= $d_siswa['nama_kelas'] ?></td>
                        </tr>
                        <tr>
                            <th>Tahun Ajaran</th>
                            <td>: <?= htmlspecialchars($tahun_ajaran_laporan, ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    </table>
                </div>
            </div>

                <?php
                // Ambil semua jenis pembayaran yang berlaku untuk kelas siswa ini
                // Logic: Check tagihan_kelas contains id_kelas OR tagihan_kelas is NULL/Empty (Assuming applies to all if logic dictates, but for now stick to explicit list if available)
                // Note: Based on previous check, some types have tagihan_kelas, some don't.
                // Assuming if tagihan_kelas is empty/null, it might apply to all? Or none?
                // Let's assume if column exists and has values, we use FIND_IN_SET.
                // If id_jenis_bayar 1 has no tagihan_kelas printed, let's assume it applies to all for now or check logic later.
                
                $id_kelas_siswa = $d_siswa['id_kelas'];
                $nama_kelas_siswa = $d_siswa['nama_kelas'];
                $q_jenis = mysqli_query($koneksi, "SELECT * FROM jenis_bayar WHERE status = 'Aktif' ORDER BY tipe_bayar ASC");
                
                while ($d_jenis = mysqli_fetch_assoc($q_jenis)) {
                    $applies = jenis_bayar_berlaku_untuk_kelas($d_jenis['tagihan_kelas'] ?? '', $id_kelas_siswa, $nama_kelas_siswa);

                    if ($applies) {
                ?>
                        <div class="card mb-4">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0 text-white"><?= $d_jenis['nama_pembayaran'] ?> (<?= $d_jenis['tipe_bayar'] ?>) - Rp. <?= number_format($d_jenis['nominal'], 0, ',', '.') ?></h6>
                            </div>
                            <div class="card-body p-0">
                                <table class="table table-bordered mb-0 pembayaran-table">
                                            <?php if ($d_jenis['tipe_bayar'] == 'Bulanan') { ?>
                                        <thead>
                                            <tr>
                                                <th>Bulan</th>
                                                <th>Status</th>
                                                <th>Tanggal Bayar</th>
                                                <th>Jumlah</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $bulan = ['Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni'];

                                            $paid_by_month = bulanan_map_pembayaran_per_bulan($koneksi, $nisn, $d_jenis['id_jenis_bayar'], $tahun_ajaran_laporan);
                                            
                                            foreach ($bulan as $bln) {
                                                $d_bayar = $paid_by_month[$bln] ?? null;
                                                
                                                $status = $d_bayar ? '<span class="badge badge-success">Lunas</span>' : '<span class="badge badge-danger">Belum Bayar</span>';
                                                $tgl = $d_bayar ? date('d/m/Y', strtotime($d_bayar['tgl_bayar'])) : '-';
                                                $jml = $d_bayar ? 'Rp. ' . number_format($d_bayar['jumlah'], 0, ',', '.') : '-';
                                            ?>
                                                <tr>
                                                    <td data-label="Bulan"><?= $bln ?></td>
                                                    <td data-label="Status"><?= $status ?></td>
                                                    <td data-label="Tanggal Bayar"><?= $tgl ?></td>
                                                    <td data-label="Jumlah"><?= $jml ?></td>
                                                </tr>
                                            <?php } ?>
                                        </tbody>
                                    <?php } else { 
                                        // Tipe Cicilan / Bebas
                                        $total_bayar = ambil_total_bayar_tersimpan($koneksi, $nisn, $d_jenis['id_jenis_bayar'], $tahun_ajaran_laporan);
                                        $sisa = $d_jenis['nominal'] - $total_bayar;
                                        $status_lunas = ($sisa <= 0) ? '<span class="badge badge-success">Lunas</span>' : '<span class="badge badge-warning">Belum Lunas</span>';
                                    ?>
                                        <tbody>
                                            <tr><td class="w-[200px]">Total Tagihan</td><td>Rp. <?= number_format($d_jenis['nominal'], 0, ',', '.') ?></td></tr>
                                            <tr><td>Total Dibayar</td><td>Rp. <?= number_format($total_bayar, 0, ',', '.') ?></td></tr>
                                            <tr><td>Sisa Tagihan</td><td class="text-danger font-weight-bold">Rp. <?= number_format($sisa > 0 ? $sisa : 0, 0, ',', '.') ?></td></tr>
                                            <tr><td>Status</td><td><?= $status_lunas ?></td></tr>
                                            <tr>
                                                <td colspan="2">
                                                    <strong>Riwayat Pembayaran:</strong>
                                                    <ul class="mt-2 pl-4">
                                                        <?php
                                                        $tahun_ajaran_laporan_esc = mysqli_real_escape_string($koneksi, $tahun_ajaran_laporan);
                                                        $q_riwayat = mysqli_query($koneksi, "SELECT * FROM pembayaran WHERE nisn = '$nisn' AND id_jenis_bayar = '" . $d_jenis['id_jenis_bayar'] . "' AND tahun_ajaran = '$tahun_ajaran_laporan_esc' ORDER BY tgl_bayar ASC");
                                                        if (mysqli_num_rows($q_riwayat) > 0) {
                                                            while ($r = mysqli_fetch_assoc($q_riwayat)) {
                                                                echo "<li>" . date('d/m/Y', strtotime($r['tgl_bayar'])) . " - Rp. " . number_format($r['jumlah_bayar'], 0, ',', '.') . "</li>";
                                                            }
                                                        } else {
                                                            echo "<li>Belum ada pembayaran</li>";
                                                        }
                                                        ?>
                                                    </ul>
                                                </td>
                                            </tr>
                                        </tbody>
                                    <?php } ?>
                                </table>
                            </div>
                        </div>
                <?php
                    } // end if applies
                } // end while jenis bayar
                ?>
    </div>
</div>

<?php include __DIR__ . '/../templates/pembayaran_footer.php'; ?>
