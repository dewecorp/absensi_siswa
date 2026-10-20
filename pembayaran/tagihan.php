<?php
$title = 'Cetak Tagihan';
include __DIR__ . '/template/header.php';
include __DIR__ . '/template/sidebar.php';

// Include Select2 CSS
?>
<link rel="stylesheet" href="<?= base_url('assets/vendors/select2/select2.min.css') ?>">
<style><?php include __DIR__ . '/assets/css/select2-kelas-filter.css'; ?></style>

<div class="mb-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
    <h4 class="mb-5 text-base font-extrabold tracking-normal text-slate-950">Filter Tagihan Siswa</h4>
    <form action="" method="get">
        <div class="grid items-end gap-4 md:grid-cols-[minmax(240px,320px)_auto]">
            <div class="min-w-0">
                <label for="id_kelas" class="mb-2 block text-sm font-bold text-slate-700">Pilih Kelas</label>
                <select name="id_kelas" id="id_kelas" class="app-control select2 filter-kelas" style="width: 100%;" required onchange="this.form.submit()">
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
            <?php if (isset($_GET['id_kelas']) && $_GET['id_kelas'] !== ''): ?>
                <div class="flex gap-3 md:pb-0">
                    <a href="tagihan_cetak_semua.php?id_kelas=<?= $_GET['id_kelas'] ?>" class="app-button app-button-primary app-button-with-text h-[46px] w-full md:w-auto md:px-5" target="_blank">
                        <i class="mdi mdi-printer app-button-icon"></i>
                        <span>Cetak Tagihan Semua Siswa</span>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </form>
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
    $tahun_ajaran_sebelumnya = tahun_ajaran_sebelumnya($tahun_ajaran_aktif);
    $hapus_alumni_lunas_tagihan = null;
    if ($is_kelas_alumni && isset($_POST['hapus_alumni_lunas'])) {
        $hapus_alumni_lunas_tagihan = hapus_semua_siswa_alumni_lunas($koneksi, $tahun_ajaran_aktif);
    }
    $alumni_lunas_tagihan = $is_kelas_alumni ? daftar_siswa_alumni_lunas($koneksi, $tahun_ajaran_aktif) : [];
    $tahun_aktif_boleh_ditagihkan = tahun_ajaran_boleh_ditagihkan($koneksi, $tahun_ajaran_aktif);
    $is_kelas_satu = in_array(trim($d_kelas['nama_kelas'] ?? ''), ['1', 'I'], true);
    $tahun_ajaran_opsi = (!$is_kelas_alumni && $tahun_aktif_boleh_ditagihkan) ? [$tahun_ajaran_aktif] : [];
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

<div class="app-grid">
    <div class="app-col-full app-section-gap app-stretch">
        <div class="app-panel">
            <div class="app-panel-body">
                <h4 class="app-panel-title mb-3">Data Siswa Kelas: <?= $d_kelas['nama_kelas'] ?></h4>
                <div class="app-table-scroll">
                    <table class="app-data-table app-table-bordered app-table-striped">
                        <thead>
                            <tr>
                                <th width="5%">No</th>
                                <th>NISN</th>
                                <th>Nama Siswa</th>
                                <th width="15%">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $no = 1;
                            while ($s = mysqli_fetch_assoc($q_siswa)) :
                                $tagihan_per_tahun = [];
                                foreach ($tahun_ajaran_opsi as $tahun_ajaran_item) {
                                    $is_tahun_aktif_item = $tahun_ajaran_item === $tahun_ajaran_aktif;
                                    if ($is_kelas_alumni && $is_tahun_aktif_item) {
                                        continue;
                                    }

                                    $tagihan_tunggakan = cek_tagihan_tunggakan($koneksi, $s['nisn'], $tahun_ajaran_item);
                                    if (($is_tahun_aktif_item && !$is_kelas_alumni && $tahun_aktif_boleh_ditagihkan) || $tagihan_tunggakan) {
                                        $tagihan_per_tahun[$tahun_ajaran_item] = [
                                            'is_tahun_aktif' => $is_tahun_aktif_item,
                                            'tagihan' => $tagihan_tunggakan,
                                        ];
                                    }
                                }
                            ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><?= $s['nisn'] ?></td>
                                    <td><?= $s['nama'] ?></td>
                                    <td>
                                        <?php if (!empty($tagihan_per_tahun)): ?>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <?php foreach ($tagihan_per_tahun as $tahun_ajaran_item => $tagihan_info): ?>
                                                    <?php
                                                    $is_tahun_aktif = $tagihan_info['is_tahun_aktif'];
                                                    $has_tagihan = !empty($tagihan_info['tagihan']);
                                                    $button_class = $is_tahun_aktif ? 'app-button-info' : 'app-button-warning';
                                                    $tahun_query = urlencode($tahun_ajaran_item);
                                                    ?>
                                                    <a href="tagihan_detail.php?nisn=<?= $s['nisn'] ?>&id_kelas=<?= $id_kelas ?>&tahun_ajaran=<?= $tahun_query ?>" class="app-button <?= $button_class ?> app-button-sm flex-col gap-0 py-2 leading-tight">
                                                        <span><i class="mdi mdi-eye"></i> Tagihan</span>
                                                        <small class="font-bold opacity-90"><?= htmlspecialchars($tahun_ajaran_item, ENT_QUOTES, 'UTF-8') ?></small>
                                                    </a>
                                                    <?php if (!$is_tahun_aktif && $has_tagihan): ?>
                                                        <a href="bayar_tagihan.php?nisn=<?= $s['nisn'] ?>&id_kelas=<?= $id_kelas ?>&tahun_ajaran=<?= $tahun_query ?>" class="app-button app-button-success app-button-sm flex-col gap-0 py-2 leading-tight">
                                                            <span><i class="mdi mdi-cash"></i> Bayar</span>
                                                            <small class="font-bold opacity-90"><?= htmlspecialchars($tahun_ajaran_item, ENT_QUOTES, 'UTF-8') ?></small>
                                                        </a>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="app-badge app-badge-success">
                                                <i class="mdi mdi-check-circle mr-1"></i> Lunas
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
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
<?php include __DIR__ . '/template/footer.php'; ?>
<!-- Select2 JS -->
<script src="<?= base_url('assets/vendors/select2/select2.min.js') ?>"></script>
<script>
    $(document).ready(function() {
        $('#id_kelas').select2({
            width: '100%',
            placeholder: '-- Pilih Kelas --',
            allowClear: false
        });

        $('#id_kelas').on('select2:open', function () {
            $('.select2-dropdown').last().addClass('select2-kelas-filter-dropdown');
        });
        $('#id_kelas').on('select2:close', function () {
            $('.select2-dropdown').removeClass('select2-kelas-filter-dropdown');
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
