<?php
$title = 'Laporan Pembayaran';
include __DIR__ . '/../templates/pembayaran_header.php';

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
<div class="card mb-4">
    <div class="card-header">
        <h4>Filter Laporan</h4>
    </div>
    <div class="card-body">
        <form action="" method="get">
            <div class="row align-items-end">
                <div class="col-md-5">
                    <div class="form-group mb-md-0">
                        <label class="font-weight-bold" for="id_kelas_laporan">Pilih Kelas</label>
                        <select id="id_kelas_laporan" name="id_kelas" class="form-control select2 select2-filter-laporan filter-kelas" style="width: 100%;" required onchange="this.form.submit()" data-placeholder="-- Pilih Kelas --">
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
                </div>
                <?php if (!empty($id_kelas) && !$is_kelas_alumni): ?>
                    <div class="col-md-7 mb-3 mb-md-0">
                        <a href="laporan_cetak_semua.php?id_kelas=<?= $id_kelas ?>" target="_blank" class="btn btn-primary">
                            <i class="fas fa-print mr-1"></i>
                            <span>Cetak Semua Laporan</span>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

    <?php if (!empty($id_kelas) && $is_kelas_alumni): ?>
        <div class="alert alert-warning font-weight-bold">
            <i class="fas fa-info-circle mr-1"></i> Kelas Alumni tidak memiliki laporan tahun ajaran berjalan.
        </div>
    <?php elseif (!empty($id_kelas)): ?>
        <div class="card">
            <div class="card-header">
                <h4>Data Siswa</h4>
            </div>
            <div class="card-body">
            <?php
            $siswa_rows = [];
            $no = 1;
            $q_siswa = mysqli_query($koneksi, "SELECT * FROM siswa JOIN kelas ON siswa.id_kelas = kelas.id_kelas WHERE siswa.id_kelas = '$id_kelas' ORDER BY nama ASC");
            while ($d_siswa = mysqli_fetch_assoc($q_siswa)) {
                $siswa_rows[] = [
                    'no' => $no++,
                    'nisn' => $d_siswa['nisn'],
                    'nama' => $d_siswa['nama'],
                    'kelas' => $d_siswa['nama_kelas'],
                ];
            }
            ?>
            <!-- Desktop: tabel -->
            <div class="d-none d-lg-block">
            <div class="table-responsive">
                <table class="table table-striped table-bordered pembayaran-table" id="table-laporan">
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
                        <?php if (empty($siswa_rows)): ?>
                            <tr><td colspan="5" class="text-center">Tidak ada data siswa</td></tr>
                        <?php else: ?>
                            <?php foreach ($siswa_rows as $row): ?>
                                <tr>
                                    <td data-label="No"><?= $row['no'] ?></td>
                                    <td data-label="NISN"><?= $row['nisn'] ?></td>
                                    <td data-label="Nama Siswa"><?= $row['nama'] ?></td>
                                    <td data-label="Kelas"><?= $row['kelas'] ?></td>
                                    <td data-label="Aksi">
                                        <div class="pb-actions">
                                            <a href="detail_laporan.php?nisn=<?= $row['nisn'] ?>" class="pb-chip pb-chip-info">
                                                <i class="fas fa-eye"></i> <span class="pb-chip-label">Detail</span>
                                            </a>
                                            <a href="cetak_laporan.php?nisn=<?= $row['nisn'] ?>" target="_blank" class="pb-chip pb-chip-warning">
                                                <i class="fas fa-print"></i> <span class="pb-chip-label">Cetak</span>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </div>

            <!-- Mobile: kartu informatif -->
            <div class="pb-cards d-lg-none">
                <?php if (empty($siswa_rows)): ?>
                    <div class="pb-card">
                        <div class="pb-card-name">Tidak ada data siswa</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($siswa_rows as $row): ?>
                        <div class="pb-card">
                            <div class="pb-card-head">
                                <div class="pb-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr((string)$row['nama'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="pb-card-id">
                                    <div class="pb-card-name"><?= htmlspecialchars($row['nama']) ?></div>
                                    <div class="pb-card-meta">
                                        <span><i class="far fa-id-card"></i> <?= htmlspecialchars($row['nisn']) ?></span>
                                        <span><i class="fas fa-users"></i> Kelas <?= htmlspecialchars($row['kelas']) ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="pb-card-actions">
                                <a href="detail_laporan.php?nisn=<?= $row['nisn'] ?>" class="pb-chip pb-chip-info">
                                    <i class="fas fa-eye"></i> <span class="pb-chip-label">Detail</span>
                                </a>
                                <a href="cetak_laporan.php?nisn=<?= $row['nisn'] ?>" target="_blank" class="pb-chip pb-chip-warning">
                                    <i class="fas fa-print"></i> <span class="pb-chip-label">Cetak</span>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            </div>
        </div>
    <?php endif; ?>

<?php include __DIR__ . '/../templates/pembayaran_footer.php'; ?>
<script>
    $(document).ready(function() {
        if ($('.select2-filter-laporan').length && !$('.select2-filter-laporan').hasClass('select2-hidden-accessible')) {
            $('.select2-filter-laporan').select2({
                width: '100%',
                placeholder: $('.select2-filter-laporan').data('placeholder') || '-- Pilih Kelas --',
                allowClear: false
            });
        }
    });
</script>
