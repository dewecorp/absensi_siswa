<?php
$title = 'Detail Laporan Pembayaran';
include __DIR__ . '/template/header.php';
include __DIR__ . '/template/sidebar.php';

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

<div class="app-page">
    <div class="app-surface">
            <div class="app-titlebar">
                <h4 class="text-xl font-extrabold tracking-normal text-slate-950">Laporan Pembayaran Siswa</h4>
                <div class="flex items-center gap-2">
                    <a href="cetak_laporan.php?nisn=<?= $nisn ?>" target="_blank" class="inline-flex items-center gap-2 rounded-lg bg-amber-500 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-amber-600">
                        <i class="mdi mdi-printer"></i> Cetak Laporan
                    </a>
                    <a href="laporan.php?id_kelas=<?= $d_siswa['id_kelas'] ?>" class="inline-flex items-center gap-2 rounded-lg border border-primary px-4 py-2 text-sm font-bold text-primary shadow-sm transition hover:bg-primary hover:text-white">
                        <i class="mdi mdi-arrow-left"></i> Kembali
                    </a>
                </div>
            </div>

            <div class="mb-6 grid grid-cols-1 gap-4 rounded-lg border border-slate-200 bg-slate-50 p-4 md:grid-cols-2">
                <div>
                    <table>
                        <tr>
                            <th class="w-[150px] text-left py-1">NISN</th>
                            <td class="py-1">: <?= $d_siswa['nisn'] ?></td>
                        </tr>
                        <tr>
                            <th class="text-left py-1">Nama Siswa</th>
                            <td class="py-1">: <?= $d_siswa['nama'] ?></td>
                        </tr>
                    </table>
                </div>
                <div>
                    <table>
                        <tr>
                            <th class="w-[150px] text-left py-1">Kelas</th>
                            <td class="py-1">: <?= $d_siswa['nama_kelas'] ?></td>
                        </tr>
                        <tr>
                            <th class="text-left py-1">Tahun Ajaran</th>
                            <td class="py-1">: <?= htmlspecialchars($tahun_ajaran_laporan, ENT_QUOTES, 'UTF-8') ?></td>
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
                        <div class="mb-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                            <div class="bg-primary p-4 text-white">
                                <h6 class="font-semibold text-white"><?= $d_jenis['nama_pembayaran'] ?> (<?= $d_jenis['tipe_bayar'] ?>) - Rp. <?= number_format($d_jenis['nominal'], 0, ',', '.') ?></h6>
                            </div>
                            <div class="p-0">
                                <table class="app-table min-w-full">
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
                                                
                                                $status = $d_bayar ? '<span class="inline-flex px-2 py-0.5 text-xs font-medium rounded-full bg-emerald-100 text-emerald-800">Lunas</span>' : '<span class="inline-flex px-2 py-0.5 text-xs font-medium rounded-full bg-red-100 text-red-800">Belum Bayar</span>';
                                                $tgl = $d_bayar ? date('d/m/Y', strtotime($d_bayar['tgl_bayar'])) : '-';
                                                $jml = $d_bayar ? 'Rp. ' . number_format($d_bayar['jumlah'], 0, ',', '.') : '-';
                                            ?>
                                                <tr>
                                                    <td><?= $bln ?></td>
                                                    <td><?= $status ?></td>
                                                    <td><?= $tgl ?></td>
                                                    <td><?= $jml ?></td>
                                                </tr>
                                            <?php } ?>
                                        </tbody>
                                    <?php } else { 
                                        // Tipe Cicilan / Bebas
                                        $total_bayar = ambil_total_bayar_tersimpan($koneksi, $nisn, $d_jenis['id_jenis_bayar'], $tahun_ajaran_laporan);
                                        $sisa = $d_jenis['nominal'] - $total_bayar;
                                        $status_lunas = ($sisa <= 0) ? '<span class="inline-flex px-2 py-0.5 text-xs font-medium rounded-full bg-emerald-100 text-emerald-800">Lunas</span>' : '<span class="inline-flex px-2 py-0.5 text-xs font-medium rounded-full bg-amber-100 text-amber-800">Belum Lunas</span>';
                                    ?>
                                        <tbody>
                                            <tr><td class="w-[200px]">Total Tagihan</td><td>Rp. <?= number_format($d_jenis['nominal'], 0, ',', '.') ?></td></tr>
                                            <tr><td>Total Dibayar</td><td>Rp. <?= number_format($total_bayar, 0, ',', '.') ?></td></tr>
                                            <tr><td>Sisa Tagihan</td><td class="text-red-500 font-bold">Rp. <?= number_format($sisa > 0 ? $sisa : 0, 0, ',', '.') ?></td></tr>
                                            <tr><td>Status</td><td><?= $status_lunas ?></td></tr>
                                            <tr>
                                                <td colspan="2">
                                                    <strong>Riwayat Pembayaran:</strong>
                                                    <ul class="mt-2 ml-4 list-disc">
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

<?php include __DIR__ . '/template/footer.php'; ?>
