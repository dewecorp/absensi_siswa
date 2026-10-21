<?php
$title = 'Tagihan & Cetak';
include __DIR__ . '/../templates/pembayaran_header.php';

?>
<div class="card mb-4">
    <div class="card-header">
        <h4>Filter Tagihan Siswa</h4>
    </div>
    <div class="card-body">
        <form action="" method="get">
            <div class="row align-items-end">
                <div class="col-md-5">
                    <div class="form-group mb-md-0">
                        <label class="font-weight-bold" for="id_kelas">Pilih Kelas</label>
                        <select name="id_kelas" id="id_kelas" class="form-control select2 filter-kelas" style="width: 100%;" required onchange="this.form.submit()">
                            <option value="">-- Pilih Kelas --</option>
                            <?php
                            $q_kelas = mysqli_query($koneksi, "SELECT * FROM kelas ORDER BY nama_kelas ASC");
                            while ($k = mysqli_fetch_assoc($q_kelas)) {
                                $selected = (isset($_GET['id_kelas']) && $_GET['id_kelas'] == $k['id_kelas']) ? 'selected' : '';
                                echo '<option value="' . $k['id_kelas'] . '" ' . $selected . '>' . $k['nama_kelas'] . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <?php if (isset($_GET['id_kelas']) && $_GET['id_kelas'] !== ''): ?>
                    <div class="col-md-7 mb-3 mb-md-0">
                        <a href="tagihan_cetak_semua.php?id_kelas=<?= $_GET['id_kelas'] ?>" class="btn btn-primary" target="_blank">
                            <i class="fas fa-print mr-1"></i>
                            <span>Cetak Tagihan Semua Siswa</span>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php
if (isset($_GET['id_kelas'])) {
    $id_kelas = $_GET['id_kelas'];
    ensure_pembayaran_tahun_ajaran_column($koneksi);
    ensure_pembayaran_arsip_table($koneksi);
    ensure_siswa_tanggal_masuk_column($koneksi);
    $d_kelas = mysqli_fetch_assoc(mysqli_query($koneksi, "SELECT nama_kelas FROM kelas WHERE id_kelas = '$id_kelas'"));
    $is_kelas_alumni = kelas_adalah_alumni($d_kelas['nama_kelas'] ?? '');
    $tahun_ajaran_aktif = get_tahun_ajaran_aktif($koneksi);
    $tahun_ajaran_berjalan = tahun_ajaran_berjalan($koneksi);
    $tahun_ajaran_sebelumnya = tahun_ajaran_sebelumnya($tahun_ajaran_berjalan);
    $hapus_alumni_lunas_tagihan = null;
    if ($is_kelas_alumni && isset($_POST['hapus_alumni_lunas'])) {
        $hapus_alumni_lunas_tagihan = hapus_semua_siswa_alumni_lunas($koneksi, $tahun_ajaran_aktif);
    }
    $alumni_lunas_tagihan = $is_kelas_alumni ? daftar_siswa_alumni_lunas($koneksi, $tahun_ajaran_aktif) : [];
    $tahun_aktif_boleh_ditagihkan = tahun_ajaran_boleh_ditagihkan($koneksi, $tahun_ajaran_aktif);
    $is_kelas_satu = in_array(trim($d_kelas['nama_kelas'] ?? ''), ['1', 'I'], true);
    // Tahun berjalan (acuan tagihan) mengikuti Tanggal Mulai Tahun Ajaran.
    $tahun_ajaran_opsi = [$tahun_ajaran_berjalan];
    if ($tahun_ajaran_sebelumnya !== '' && !$is_kelas_satu) {
        $tahun_ajaran_opsi[] = $tahun_ajaran_sebelumnya;
    }
    $tahun_queries = [
        "SELECT DISTINCT tahun_ajaran FROM pembayaran WHERE tahun_ajaran IS NOT NULL AND tahun_ajaran <> ''",
        "SELECT DISTINCT tahun_ajaran FROM pembayaran_arsip WHERE tahun_ajaran IS NOT NULL AND tahun_ajaran <> ''",
    ];
    if (!$is_kelas_satu) {
        foreach ($tahun_queries as $tahun_sql) {
            $q_tahun_pembayaran = mysqli_query($koneksi, $tahun_sql);
            while ($row_tahun = $q_tahun_pembayaran ? mysqli_fetch_assoc($q_tahun_pembayaran) : null) {
                $tahun_row = trim((string)($row_tahun['tahun_ajaran'] ?? ''));
                if ($tahun_row !== '' && (!$is_kelas_alumni || $tahun_row !== $tahun_ajaran_aktif)) {
                    $tahun_ajaran_opsi[] = $tahun_row;
                }
            }
        }
    }
    $tahun_ajaran_opsi = array_values(array_unique($tahun_ajaran_opsi));

    $q_siswa = mysqli_query($koneksi, "SELECT * FROM siswa WHERE id_kelas = '$id_kelas' ORDER BY nama ASC");
?>

<div class="card">
    <div class="card-header">
        <h4>Data Siswa Kelas: <?= $d_kelas['nama_kelas'] ?></h4>
    </div>
    <div class="card-body">
        <?php
        $siswa_rows = [];
        $no = 1;
        while ($s = mysqli_fetch_assoc($q_siswa)) {
            $tagihan_per_tahun = [];
            foreach ($tahun_ajaran_opsi as $tahun_ajaran_item) {
                $is_tahun_aktif_item = $tahun_ajaran_item === $tahun_ajaran_aktif;
                $is_tahun_berjalan_item = $tahun_ajaran_item === $tahun_ajaran_berjalan;
                if ($is_kelas_alumni && $is_tahun_aktif_item) {
                    continue;
                }

                $tagihan_tunggakan = cek_tagihan_tunggakan($koneksi, $s['nisn'], $tahun_ajaran_item);
                if ($is_tahun_berjalan_item || $tagihan_tunggakan) {
                    $tagihan_per_tahun[$tahun_ajaran_item] = [
                        'is_tahun_aktif' => $is_tahun_aktif_item,
                        'is_tahun_berjalan' => $is_tahun_berjalan_item,
                        'tagihan' => $tagihan_tunggakan,
                    ];
                }
            }
            $siswa_rows[] = [
                'no' => $no++,
                'nisn' => $s['nisn'],
                'nama' => $s['nama'],
                'tagihan' => $tagihan_per_tahun,
            ];
        }

        // Chip aksi dipakai di tabel (desktop) dan kartu (mobile)
        $render_aksi_tagihan = function (array $row) use ($id_kelas) {
            if (empty($row['tagihan'])) {
                return '<span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i> Lunas</span>';
            }
            ob_start();
            ?>
            <div class="pb-actions">
                <?php foreach ($row['tagihan'] as $tahun_ajaran_item => $tagihan_info): ?>
                    <?php
                    $is_tahun_aktif = $tagihan_info['is_tahun_aktif'];
                    $is_tahun_berjalan = $tagihan_info['is_tahun_berjalan'];
                    $has_tagihan = !empty($tagihan_info['tagihan']);
                    $chip_class = $is_tahun_berjalan ? 'pb-chip-info' : 'pb-chip-warning';
                    $icon_chip = $is_tahun_berjalan ? 'fa-file-invoice-dollar' : 'fa-exclamation-circle';
                    $label_tombol = $is_tahun_berjalan ? 'Tagihan' : 'Tunggakan';
                    $tahun_query = urlencode($tahun_ajaran_item);
                    ?>
                    <a href="tagihan_detail.php?nisn=<?= $row['nisn'] ?>&id_kelas=<?= $id_kelas ?>&tahun_ajaran=<?= $tahun_query ?>" class="pb-chip <?= $chip_class ?>">
                        <i class="fas <?= $icon_chip ?>"></i>
                        <span class="pb-chip-label"><?= $label_tombol ?></span>
                        <span class="pb-chip-year"><?= htmlspecialchars($tahun_ajaran_item, ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                    <?php if (!$is_tahun_aktif && $has_tagihan): ?>
                        <a href="bayar_tagihan.php?nisn=<?= $row['nisn'] ?>&id_kelas=<?= $id_kelas ?>&tahun_ajaran=<?= $tahun_query ?>" class="pb-chip pb-chip-success">
                            <i class="fas fa-money-bill-wave"></i>
                            <span class="pb-chip-label">Bayar</span>
                            <span class="pb-chip-year"><?= htmlspecialchars($tahun_ajaran_item, ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php
            return ob_get_clean();
        };
        ?>

        <!-- Desktop: tabel -->
        <div class="d-none d-lg-block">
            <div class="table-responsive">
                <table class="table table-bordered table-striped pembayaran-table">
                            <thead>
                                <tr>
                                    <th style="width: 92px;">No</th>
                                    <th>NISN</th>
                                    <th>Nama Siswa</th>
                                    <th style="width: 360px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($siswa_rows as $row): ?>
                                    <tr>
                                        <td data-label="No"><?= $row['no'] ?></td>
                                        <td data-label="NISN"><?= $row['nisn'] ?></td>
                                        <td data-label="Nama Siswa"><?= $row['nama'] ?></td>
                                        <td data-label="Aksi"><?= $render_aksi_tagihan($row) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                </table>
            </div>
        </div>

        <!-- Mobile: kartu informatif -->
        <div class="pb-cards d-lg-none">
            <?php foreach ($siswa_rows as $row): ?>
                <div class="pb-card">
                    <div class="pb-card-head">
                        <div class="pb-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr((string)$row['nama'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="pb-card-id">
                            <div class="pb-card-name"><?= htmlspecialchars($row['nama']) ?></div>
                            <div class="pb-card-meta">
                                <span><i class="far fa-id-card"></i> <?= htmlspecialchars($row['nisn']) ?></span>
                                <span><i class="fas fa-users"></i> Kelas <?= htmlspecialchars($d_kelas['nama_kelas'] ?? '') ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="pb-card-lines">
                        <?php if (empty($row['tagihan'])): ?>
                            <div class="pb-line is-lunas">
                                <span class="pb-line-label"><i class="fas fa-check-circle"></i> Semua tagihan</span>
                                <span class="pb-line-value">Lunas</span>
                            </div>
                        <?php else: ?>
                            <?php foreach ($row['tagihan'] as $tahun_ajaran_item => $tagihan_info): ?>
                                <?php
                                $total_tahun = 0;
                                if (!empty($tagihan_info['tagihan'])) {
                                    foreach ($tagihan_info['tagihan'] as $item_tagihan) {
                                        $total_tahun += (int)($item_tagihan['sisa'] ?? 0);
                                    }
                                }
                                ?>
                                <div class="pb-line <?= $tagihan_info['is_tahun_berjalan'] ? 'is-berjalan' : 'is-tunggakan' ?>">
                                    <span class="pb-line-label">
                                        <i class="fas <?= $tagihan_info['is_tahun_berjalan'] ? 'fa-file-invoice-dollar' : 'fa-exclamation-circle' ?>"></i>
                                        <?= $tagihan_info['is_tahun_berjalan'] ? 'Tagihan' : 'Tunggakan' ?> <?= htmlspecialchars($tahun_ajaran_item, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <span class="pb-line-value">
                                        <?= $total_tahun > 0 ? 'Rp ' . number_format($total_tahun, 0, ',', '.') : 'Lunas' ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="pb-card-actions">
                        <?= $render_aksi_tagihan($row) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php
}
?>

<?php if (isset($alumni_lunas_tagihan) && !empty($alumni_lunas_tagihan)) : ?>
<form method="post" id="formHapusAlumniLunas" style="display: none;">
    <input type="hidden" name="hapus_alumni_lunas" value="1">
</form>
<?php endif; ?>
<?php include __DIR__ . '/../templates/pembayaran_footer.php'; ?>
<script>
    $(document).ready(function() {
        $('#id_kelas').select2({
            width: '100%',
            placeholder: '-- Pilih Kelas --',
            allowClear: false
        });

        // Auto submit form when class is selected
        $('#id_kelas').on('change', function() {
            if ($(this).val()) {
                $(this).closest('form').submit();
            }
        });

        <?php if (isset($hapus_alumni_lunas_tagihan) && $hapus_alumni_lunas_tagihan !== null): ?>
        Swal.fire({
            title: 'Berhasil',
            text: '<?= (int) $hapus_alumni_lunas_tagihan ?> data alumni lunas berhasil dihapus.',
            icon: 'success',
            timer: 1800,
            showConfirmButton: false
        });
        <?php elseif (isset($alumni_lunas_tagihan) && !empty($alumni_lunas_tagihan)): ?>
        Swal.fire({
            title: 'Hapus data alumni lunas?',
            text: 'Ada <?= count($alumni_lunas_tagihan) ?> data alumni yang semua tagihannya sudah lunas. Data siswa alumninya bisa dihapus, riwayat pembayaran tetap tersimpan.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Hapus sekarang',
            cancelButtonText: 'Nanti saja'
        }).then(function(result) {
            if (result.isConfirmed) {
                document.getElementById('formHapusAlumniLunas').submit();
            }
        });
        <?php endif; ?>
    });
</script>
