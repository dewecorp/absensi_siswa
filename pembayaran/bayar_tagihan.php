<?php
$title = 'Bayar Tagihan';
include __DIR__ . '/../templates/pembayaran_header.php';

// Proses pembayaran tagihan
if (isset($_POST['bayar_tagihan'])) {
    ensure_pembayaran_tahun_ajaran_column($koneksi);
    $id_petugas = isset($_SESSION['id_pengguna']) ? (int)$_SESSION['id_pengguna'] : 0;
    $nisn = isset($_POST['nisn']) ? trim($_POST['nisn']) : '';
    $tgl_bayar = isset($_POST['tgl_bayar']) ? $_POST['tgl_bayar'] : '';
    $tahun_bayar = date('Y', strtotime($tgl_bayar));
    $tahun_ajaran = isset($_POST['tahun_ajaran']) && trim((string)$_POST['tahun_ajaran']) !== ''
        ? trim((string)$_POST['tahun_ajaran'])
        : get_tahun_ajaran_aktif($koneksi);
    $tahun_ajaran_aktif = get_tahun_ajaran_aktif($koneksi);
    
    $error = '';
    $success_count = 0;
    
    if ($tahun_ajaran === $tahun_ajaran_aktif) {
        $error = 'Pembayaran tahun ajaran berjalan dilakukan melalui menu Transaksi.';
    } elseif ($id_petugas <= 0 || $nisn === '' || $tgl_bayar === '') {
        $error = 'Data tidak lengkap!';
    } else {
        // Cek tagihan tunggakan
        $tagihan_tunggakan = cek_tagihan_tunggakan($koneksi, $nisn, $tahun_ajaran);
        if (!$tagihan_tunggakan) {
            $error = 'Siswa tidak memiliki tagihan tunggakan!';
        } else {
            mysqli_begin_transaction($koneksi);
            try {
                // Generate no transaksi
                $prefix_trx = 'TRX-TAG-' . date('Ym', strtotime($tgl_bayar)) . '-';
                $prefix_len = strlen($prefix_trx) + 1;
                $safe_prefix = mysqli_real_escape_string($koneksi, $prefix_trx);
                $q_last_trx = mysqli_query($koneksi, "SELECT COALESCE(MAX(CAST(SUBSTRING(no_transaksi, $prefix_len) AS UNSIGNED)), 0) AS last_urut FROM pembayaran WHERE no_transaksi LIKE '$safe_prefix%'");

                if (!$q_last_trx) {
                    throw new Exception('Gagal membaca nomor transaksi terakhir: ' . mysqli_error($koneksi));
                }

                $d_last_trx = mysqli_fetch_assoc($q_last_trx);
                $next_urut = ((int)$d_last_trx['last_urut']) + 1;
                $no_transaksi = $prefix_trx . sprintf("%03d", $next_urut);
                
                // Proses pembayaran tagihan tunggakan
                foreach ($tagihan_tunggakan as $tagihan) {
                    $id_jenis_bayar = $tagihan['id_jenis_bayar'];
                    $jumlah_bayar = $tagihan['sisa'];
                    $ket = 'Pembayaran Tagihan Tunggakan';
                    
                    if ($tagihan['tipe_bayar'] == 'Bulanan') {
                        $bulan_bayar = implode(', ', $tagihan['unpaid_details']);
                    } else {
                        $bulan_bayar = '';
                    }
                    
                    $stmt_insert = mysqli_prepare($koneksi, "INSERT INTO pembayaran (id_petugas, nisn, tgl_bayar, id_jenis_bayar, jumlah_bayar, ket, bulan_bayar, tahun_bayar, tahun_ajaran, no_transaksi) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    mysqli_stmt_bind_param($stmt_insert, 'issiisssss', $id_petugas, $nisn, $tgl_bayar, $id_jenis_bayar, $jumlah_bayar, $ket, $bulan_bayar, $tahun_bayar, $tahun_ajaran, $no_transaksi);
                    mysqli_stmt_execute($stmt_insert);
                    mysqli_stmt_close($stmt_insert);
                    
                    $success_count++;
                }
                
                pembayaran_log_activity($koneksi, 'Create', "Membayar tagihan tunggakan NISN: $nisn, Tahun Ajaran: $tahun_ajaran, No Transaksi: $no_transaksi");
                mysqli_commit($koneksi);
                echo "<script>
                    Swal.fire({
                        title: 'Berhasil',
                        text: 'Tagihan tunggakan berhasil dibayar!',
                        icon: 'success',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.href = 'tagihan.php?id_kelas=" . (isset($_GET['id_kelas']) ? $_GET['id_kelas'] : '') . "';
                    });
                </script>";
            } catch (Exception $e) {
                mysqli_rollback($koneksi);
                $error = $e->getMessage();
            }
        }
    }
    
    if ($error) {
        echo "<script>Swal.fire('Gagal', '" . htmlspecialchars($error) . "', 'error');</script>";
    }
}

// Jika ada NISN yang dipilih
$selected_nisn = isset($_GET['nisn']) ? trim((string)$_GET['nisn']) : '';
$tahun_ajaran = isset($_GET['tahun_ajaran']) && trim((string)$_GET['tahun_ajaran']) !== ''
    ? trim((string)$_GET['tahun_ajaran'])
    : get_tahun_ajaran_aktif($koneksi);
$tahun_ajaran_aktif = get_tahun_ajaran_aktif($koneksi);
$is_tahun_ajaran_aktif = $tahun_ajaran === $tahun_ajaran_aktif;
$selected_siswa = null;
$tagihan = null;
$id_kelas = isset($_GET['id_kelas']) ? trim((string)$_GET['id_kelas']) : '';

if ($selected_nisn) {
    $q_siswa = mysqli_query($koneksi, "SELECT s.*, k.nama_kelas FROM siswa s JOIN kelas k ON s.id_kelas = k.id_kelas WHERE s.nisn = '$selected_nisn'");
    $selected_siswa = mysqli_fetch_assoc($q_siswa);
    if ($id_kelas === '' && $selected_siswa) {
        $id_kelas = (string)$selected_siswa['id_kelas'];
    }
    $tagihan = cek_tagihan_tunggakan($koneksi, $selected_nisn, $tahun_ajaran);
}

// Dapatkan daftar siswa berdasarkan kelas (jika dipilih)
$has_id_kelas = $id_kelas !== '';
$q_siswa_list = null;
if ($has_id_kelas) {
    $q_siswa_list = mysqli_query($koneksi, "SELECT s.*, k.nama_kelas FROM siswa s JOIN kelas k ON s.id_kelas = k.id_kelas WHERE s.id_kelas = '$id_kelas' ORDER BY s.nama ASC");
}

// Dapatkan daftar kelas untuk dropdown
$q_kelas = mysqli_query($koneksi, "SELECT * FROM kelas ORDER BY nama_kelas ASC");
?>

<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center mb-3" style="gap:12px;">
            <a href="tagihan.php?id_kelas=<?= $id_kelas ?>" class="btn btn-outline-primary">
                <i class="fas fa-arrow-left mr-1"></i> Kembali
            </a>
            <h4 class="mb-0">Bayar Tagihan Tunggakan</h4>
            <span class="badge badge-info">Tahun Ajaran <?= htmlspecialchars($tahun_ajaran, ENT_QUOTES, 'UTF-8') ?></span>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="get" action="">
                    <?php if (!$has_id_kelas): ?>
                    <input type="hidden" name="tahun_ajaran" value="<?= htmlspecialchars($tahun_ajaran, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-group">
                        <label class="font-weight-bold">Pilih Kelas</label>
                        <select name="id_kelas" class="form-control" required onchange="this.form.submit()">
                            <option value="">-- Pilih Kelas --</option>
                            <?php while ($k = mysqli_fetch_assoc($q_kelas)): ?>
                                <option value="<?= $k['id_kelas'] ?>"><?= $k['nama_kelas'] ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <?php else: ?>
                        <input type="hidden" name="id_kelas" value="<?= $id_kelas ?>">
                    <?php endif; ?>
                </form>

                <?php if ($has_id_kelas): ?>
                <form method="get" action="">
                    <input type="hidden" name="id_kelas" value="<?= $id_kelas ?>">
                    <input type="hidden" name="tahun_ajaran" value="<?= htmlspecialchars($tahun_ajaran, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-group">
                        <label class="font-weight-bold">Pilih Siswa</label>
                        <select name="nisn" class="form-control" required onchange="this.form.submit()">
                            <option value="">-- Pilih Siswa --</option>
                            <?php while ($s = mysqli_fetch_assoc($q_siswa_list)):
                                $nm_kelas = trim($s['nama_kelas'] ?? '');
                                $has_tagihan = ($nm_kelas !== '1' && $nm_kelas !== 'I') ? (cek_tagihan_tunggakan($koneksi, $s['nisn'], $tahun_ajaran) ? true : false) : false;
                            ?>
                                <option value="<?= $s['nisn'] ?>" <?= ($selected_nisn == $s['nisn']) ? 'selected' : '' ?>>
                                    <?= $s['nama'] ?> - <?= $s['nama_kelas'] ?>
                                    <?= $has_tagihan ? '(Ada Tagihan)' : '' ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </form>

                <?php if ($selected_siswa && $tagihan): ?>
                    <div class="card bg-light mb-4">
                        <div class="card-body">
                        <h5 class="font-weight-bold mb-2">Data Siswa</h5>
                        <p><strong>NISN:</strong> <?= $selected_siswa['nisn'] ?></p>
                        <p><strong>Nama:</strong> <?= $selected_siswa['nama'] ?></p>
                        <p><strong>Kelas:</strong> <?= $selected_siswa['nama_kelas'] ?></p>
                        <p class="mb-0"><strong>Tahun Ajaran:</strong> <?= htmlspecialchars($tahun_ajaran, ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </div>

                    <div class="mb-4">
                        <h5 class="font-weight-bold mb-3">Detail Tagihan Tunggakan</h5>
                        <div class="table-responsive">
                            <table class="table table-bordered pembayaran-table">
                                <thead>
                                    <tr>
                                        <th>No</th>
                                        <th>Jenis Pembayaran</th>
                                        <th>Tipe</th>
                                        <th>Detail Tagihan</th>
                                        <th>Jumlah Tagihan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $total_tagihan = 0; $no = 1; foreach ($tagihan as $t): $total_tagihan += $t['sisa']; ?>
                                    <tr>
                                        <td data-label="No"><?= $no++ ?></td>
                                        <td data-label="Jenis Pembayaran"><?= $t['nama_pembayaran'] ?></td>
                                        <td data-label="Tipe"><?= $t['tipe_bayar'] ?></td>
                                        <td data-label="Detail Tagihan">
                                            <?php foreach ($t['unpaid_details'] as $d): ?>
                                                <span class="badge badge-warning mr-1 mb-1"><?= $d ?></span>
                                            <?php endforeach; ?>
                                        </td>
                                        <td data-label="Jumlah Tagihan" class="text-right font-weight-bold">Rp <?= number_format($t['sisa'], 0, ',', '.') ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr class="table-light">
                                        <td colspan="4" class="text-right font-weight-bold">Total:</td>
                                        <td class="text-right font-weight-bold text-danger">Rp <?= number_format($total_tagihan, 0, ',', '.') ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php if ($is_tahun_ajaran_aktif): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-info-circle mr-2"></i> Tagihan tahun ajaran berjalan hanya untuk dilihat. Pembayaran dilakukan melalui menu Transaksi.
                        </div>
                    <?php else: ?>
                        <form method="post" action="">
                            <input type="hidden" name="id_kelas" value="<?= $id_kelas ?>">
                            <input type="hidden" name="nisn" value="<?= $selected_nisn ?>">
                            <input type="hidden" name="tahun_ajaran" value="<?= htmlspecialchars($tahun_ajaran, ENT_QUOTES, 'UTF-8') ?>">

                            <div class="form-group">
                                <label class="font-weight-bold">Tanggal Bayar</label>
                                <input type="date" name="tgl_bayar" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>

                            <div class="d-flex align-items-center mt-4" style="gap:12px;">
                                <button type="submit" name="bayar_tagihan" class="btn btn-success">
                                    <i class="fas fa-check-circle mr-1"></i> Bayar Semua Tagihan
                                </button>
                                <a href="tagihan.php?id_kelas=<?= $id_kelas ?>" class="btn btn-secondary">
                                    Batal
                                </a>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php elseif ($selected_siswa && !$tagihan): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle mr-2"></i> Siswa ini tidak memiliki tagihan tunggakan!
                    </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../templates/pembayaran_footer.php'; ?>
