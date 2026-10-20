<?php
$title = 'Laporan Pembayaran';
include __DIR__ . '/template/header.php';
include __DIR__ . '/template/sidebar.php';

$id_kelas = isset($_GET['id_kelas']) ? $_GET['id_kelas'] : '';
$kelas_terpilih = null;
$is_kelas_alumni = false;
if ($id_kelas !== '') {
    $id_kelas_esc = mysqli_real_escape_string($koneksi, (string) $id_kelas);
    $q_kelas_terpilih = mysqli_query($koneksi, "SELECT * FROM kelas WHERE id_kelas = '$id_kelas_esc' LIMIT 1");
    $kelas_terpilih = $q_kelas_terpilih ? mysqli_fetch_assoc($q_kelas_terpilih) : null;
    $is_kelas_alumni = $kelas_terpilih ? kelas_adalah_alumni($kelas_terpilih['nama_kelas'] ?? '') : false;
}
?>
<link rel="stylesheet" href="<?= base_url('assets/vendors/select2/select2.min.css') ?>">
<style><?php include __DIR__ . '/assets/css/select2-kelas-filter.css'; ?></style>

<div class="space-y-6">
    <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <h4 class="mb-5 text-base font-extrabold tracking-normal text-slate-950">Filter Laporan</h4>
        <form action="" method="get">
            <div class="grid items-end gap-4 md:grid-cols-[minmax(240px,320px)_auto]">
                <div class="min-w-0">
                    <label class="mb-2 block text-sm font-bold text-slate-700" for="id_kelas_laporan">Pilih Kelas</label>
                    <select id="id_kelas_laporan" name="id_kelas" class="app-control select2 select2-filter-laporan filter-kelas" style="width: 100%;" required onchange="this.form.submit()" data-placeholder="-- Pilih Kelas --">
                        <option value="">-- Pilih Kelas --</option>
                        <?php
                        $q_kelas = mysqli_query($koneksi, "SELECT * FROM kelas WHERE LOWER(nama_kelas) NOT LIKE '%alumni%' ORDER BY nama_kelas ASC");
                        while ($d_kelas = mysqli_fetch_assoc($q_kelas)) {
                            $selected = ($id_kelas == $d_kelas['id_kelas']) ? 'selected' : '';
                            echo "<option value='" . $d_kelas['id_kelas'] . "' $selected>" . $d_kelas['nama_kelas'] . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <?php if (!empty($id_kelas) && !$is_kelas_alumni): ?>
                    <div class="flex md:pb-0">
                        <a href="laporan_cetak_semua.php?id_kelas=<?= $id_kelas ?>" target="_blank" class="app-button app-button-primary app-button-with-text h-[46px] w-full md:w-auto md:px-5">
                            <i class="mdi mdi-printer app-button-icon"></i>
                            <span>Cetak Semua Laporan</span>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (!empty($id_kelas) && $is_kelas_alumni): ?>
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-5 text-sm font-bold text-amber-700">
            <i class="mdi mdi-information-outline mr-1"></i> Kelas Alumni tidak memiliki laporan tahun ajaran berjalan.
        </div>
    <?php elseif (!empty($id_kelas)): ?>
        <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <h4 class="mb-5 text-base font-extrabold tracking-normal text-slate-950">Data Siswa</h4>
            <div class="app-table-scroll">
                <table class="app-data-table app-table-striped" id="table-laporan">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>NISN</th>
                            <th>Nama Siswa</th>
                            <th>Kelas</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $no = 1;
                        $q_siswa = mysqli_query($koneksi, "SELECT * FROM siswa JOIN kelas ON siswa.id_kelas = kelas.id_kelas WHERE siswa.id_kelas = '$id_kelas' ORDER BY nama ASC");
                        if (mysqli_num_rows($q_siswa) > 0) {
                            while ($d_siswa = mysqli_fetch_assoc($q_siswa)) {
                        ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><?= $d_siswa['nisn'] ?></td>
                                    <td><?= $d_siswa['nama'] ?></td>
                                    <td><?= $d_siswa['nama_kelas'] ?></td>
                                    <td>
                                        <div class="flex flex-wrap gap-2">
                                            <a href="detail_laporan.php?nisn=<?= $d_siswa['nisn'] ?>" class="app-button app-button-info app-button-sm app-button-with-text">
                                                <i class="mdi mdi-eye app-button-icon"></i> Detail
                                            </a>
                                            <a href="cetak_laporan.php?nisn=<?= $d_siswa['nisn'] ?>" target="_blank" class="app-button app-button-warning app-button-sm app-button-with-text">
                                                <i class="mdi mdi-printer app-button-icon"></i> Cetak
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                        <?php
                            }
                        } else {
                            echo "<tr><td colspan='5' class='text-center'>Tidak ada data siswa</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/template/footer.php'; ?>
<script src="<?= base_url('assets/vendors/select2/select2.min.js') ?>"></script>
<script>
    $(document).ready(function() {
        if ($('.select2-filter-laporan').length) {
            $('.select2-filter-laporan').select2({
                width: '100%',
                placeholder: $('.select2-filter-laporan').data('placeholder') || '-- Pilih Kelas --',
                allowClear: false
            });

            $('#id_kelas_laporan').on('select2:open', function () {
                $('.select2-dropdown').last().addClass('select2-kelas-filter-dropdown');
            });
            $('#id_kelas_laporan').on('select2:close', function () {
                $('.select2-dropdown').removeClass('select2-kelas-filter-dropdown');
            });
        }
    });
</script>
