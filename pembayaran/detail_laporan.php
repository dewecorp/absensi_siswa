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

$tahun_ajaran_aktif = get_tahun_ajaran_aktif($koneksi);
$tahun_ajaran_tersimpan = daftar_tahun_ajaran_pembayaran_tersimpan($koneksi, $nisn);
if (!in_array($tahun_ajaran_aktif, $tahun_ajaran_tersimpan, true)) {
    array_unshift($tahun_ajaran_tersimpan, $tahun_ajaran_aktif);
}

$tahun_ajaran_laporan = isset($_GET['tahun_ajaran']) ? trim((string)$_GET['tahun_ajaran']) : $tahun_ajaran_aktif;
if (!in_array($tahun_ajaran_laporan, $tahun_ajaran_tersimpan, true) && $tahun_ajaran_laporan !== $tahun_ajaran_aktif) {
    $tahun_ajaran_laporan = $tahun_ajaran_aktif;
}
?>

<div class="card mb-4">
    <div class="card-body">
            <div class="d-flex flex-wrap align-items-center justify-content-between mb-3" style="gap:12px;">
                <h4 class="mb-0">Laporan Pembayaran Siswa</h4>
                <div class="d-flex align-items-center" style="gap:8px;">
                    <a href="cetak_laporan.php?nisn=<?= $nisn ?>&tahun_ajaran=<?= urlencode($tahun_ajaran_laporan) ?>" target="_blank" class="btn btn-warning">
                        <i class="fas fa-print mr-1"></i> Cetak Laporan
                    </a>
                    <a href="laporan.php?id_kelas=<?= $d_siswa['id_kelas'] ?>" class="btn btn-outline-primary">
                        <i class="fas fa-arrow-left mr-1"></i> Kembali
                    </a>
                </div>
            </div>

            <div class="row bg-light rounded p-3 mb-4 align-items-center">
                <div class="col-md-5">
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
                <div class="col-md-4">
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
                <div class="col-md-3">
                    <?php if (count($tahun_ajaran_tersimpan) > 1): ?>
                        <form method="GET" action="" class="form-inline justify-content-md-end mt-2 mt-md-0">
                            <input type="hidden" name="nisn" value="<?= htmlspecialchars($nisn, ENT_QUOTES, 'UTF-8') ?>">
                            <label for="select_ta" class="sr-only">Tahun Ajaran</label>
                            <select name="tahun_ajaran" id="select_ta" class="form-control form-control-sm" onchange="this.form.submit()">
                                <?php foreach ($tahun_ajaran_tersimpan as $ta_opt): ?>
                                    <option value="<?= htmlspecialchars($ta_opt, ENT_QUOTES, 'UTF-8') ?>" <?= $ta_opt === $tahun_ajaran_laporan ? 'selected' : '' ?>>
                                        TA <?= htmlspecialchars($ta_opt, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php endif; ?>
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
                        <div class="card mb-4 shadow-sm">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0 text-white font-weight-bold"><?= htmlspecialchars($d_jenis['nama_pembayaran'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($d_jenis['tipe_bayar'], ENT_QUOTES, 'UTF-8') ?>) - Rp. <?= number_format($d_jenis['nominal'], 0, ',', '.') ?></h6>
                            </div>
                            <div class="card-body p-3">
                                <?php if ($d_jenis['tipe_bayar'] == 'Bulanan') { ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped mb-0 no-data-table">
                                            <thead>
                                                <tr>
                                                    <th style="width: 25%;">Bulan</th>
                                                    <th style="width: 20%;">Status</th>
                                                    <th style="width: 25%;">Tanggal Bayar</th>
                                                    <th style="width: 30%;">Jumlah</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $bulan = ['Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni'];

                                                $paid_by_month = bulanan_map_pembayaran_per_bulan($koneksi, $nisn, $d_jenis['id_jenis_bayar'], $tahun_ajaran_laporan);
                                                
                                                foreach ($bulan as $bln) {
                                                    $d_bayar = $paid_by_month[$bln] ?? null;
                                                    
                                                    $status = $d_bayar ? '<span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i>Lunas</span>' : '<span class="badge badge-danger"><i class="fas fa-times-circle mr-1"></i>Belum Bayar</span>';
                                                    $tgl = $d_bayar ? date('d/m/Y', strtotime($d_bayar['tgl_bayar'])) : '-';
                                                    $jml = $d_bayar ? 'Rp. ' . number_format($d_bayar['jumlah'], 0, ',', '.') : '-';
                                                ?>
                                                    <tr>
                                                        <td data-label="Bulan" class="font-weight-600"><?= $bln ?></td>
                                                        <td data-label="Status"><?= $status ?></td>
                                                        <td data-label="Tanggal Bayar"><?= $tgl ?></td>
                                                        <td data-label="Jumlah"><?= $jml ?></td>
                                                    </tr>
                                                <?php } ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php } else { 
                                    // Tipe Cicilan / Bebas
                                    $total_bayar = ambil_total_bayar_tersimpan($koneksi, $nisn, $d_jenis['id_jenis_bayar'], $tahun_ajaran_laporan);
                                    $sisa = $d_jenis['nominal'] - $total_bayar;
                                    $status_lunas = ($sisa <= 0) ? '<span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i>Lunas</span>' : '<span class="badge badge-warning"><i class="fas fa-clock mr-1"></i>Belum Lunas</span>';
                                ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered mb-0 no-data-table">
                                            <tbody>
                                                <tr><td style="width: 200px;" class="font-weight-bold bg-light">Total Tagihan</td><td>Rp. <?= number_format($d_jenis['nominal'], 0, ',', '.') ?></td></tr>
                                                <tr><td class="font-weight-bold bg-light">Total Dibayar</td><td>Rp. <?= number_format($total_bayar, 0, ',', '.') ?></td></tr>
                                                <tr><td class="font-weight-bold bg-light">Sisa Tagihan</td><td class="text-danger font-weight-bold">Rp. <?= number_format($sisa > 0 ? $sisa : 0, 0, ',', '.') ?></td></tr>
                                                <tr><td class="font-weight-bold bg-light">Status</td><td><?= $status_lunas ?></td></tr>
                                                <tr>
                                                    <td colspan="2">
                                                        <strong>Riwayat Pembayaran:</strong>
                                                        <ul class="mt-2 pl-4 mb-0">
                                                            <?php
                                                            $tahun_ajaran_laporan_esc = mysqli_real_escape_string($koneksi, $tahun_ajaran_laporan);
                                                            $q_riwayat = mysqli_query($koneksi, "SELECT * FROM pembayaran WHERE nisn = '$nisn' AND id_jenis_bayar = '" . $d_jenis['id_jenis_bayar'] . "' AND tahun_ajaran = '$tahun_ajaran_laporan_esc' ORDER BY tgl_bayar ASC");
                                                            if (mysqli_num_rows($q_riwayat) > 0) {
                                                                while ($r = mysqli_fetch_assoc($q_riwayat)) {
                                                                    echo "<li>" . date('d/m/Y', strtotime($r['tgl_bayar'])) . " - Rp. " . number_format($r['jumlah_bayar'], 0, ',', '.') . "</li>";
                                                                }
                                                            } else {
                                                                echo "<li class=\"text-muted\">Belum ada pembayaran pada tahun ajaran ini</li>";
                                                            }
                                                            ?>
                                                        </ul>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                <?php
                    } // end if applies
                } // end while jenis bayar
                ?>
    </div>
</div>

<?php include __DIR__ . '/../templates/pembayaran_footer.php'; ?>
