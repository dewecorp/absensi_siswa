<?php
$title = 'Jenis Pembayaran';
include __DIR__ . '/../templates/pembayaran_header.php';

// Fetch Kelas Data
$q_kelas = mysqli_query($koneksi, "SELECT * FROM kelas ORDER BY nama_kelas ASC");
$kelas_list = [];
while ($k = mysqli_fetch_assoc($q_kelas)) {
    $kelas_list[] = $k;
}

// Proses Tambah
if (isset($_POST['tambah'])) {
    $nama_pembayaran = $_POST['nama_pembayaran'];
    $nominal = preg_replace('/\D/', '', $_POST['nominal'] ?? '0');
    $tipe_bayar = $_POST['tipe_bayar'];
    $kali_cicilan = ($tipe_bayar == 'Cicilan') ? $_POST['kali_cicilan'] : 0;
    
    // Handle Tagihan Kepada (Array to String)
    $tagihan_kelas = isset($_POST['tagihan_kelas']) ? implode(',', $_POST['tagihan_kelas']) : '';
    $status = $_POST['status'];
    
    $query = mysqli_query($koneksi, "INSERT INTO jenis_bayar (nama_pembayaran, nominal, tipe_bayar, kali_cicilan, tagihan_kelas, status) VALUES ('$nama_pembayaran', '$nominal', '$tipe_bayar', '$kali_cicilan', '$tagihan_kelas', '$status')");
    if ($query) {
        pembayaran_log_activity($koneksi, 'Create', "Menambah jenis bayar: $nama_pembayaran ($tipe_bayar)");
        echo "<script>
            Swal.fire({
                title: 'Berhasil',
                text: 'Data berhasil ditambahkan',
                icon: 'success',
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
                window.location='jenis_bayar.php';
            });
        </script>";
    } else {
        echo "<script>Swal.fire('Gagal', 'Data gagal ditambahkan', 'error');</script>";
    }
}

// Proses Edit
if (isset($_POST['edit'])) {
    $id_jenis_bayar = $_POST['id_jenis_bayar'];
    $nama_pembayaran = $_POST['nama_pembayaran'];
    $nominal = preg_replace('/\D/', '', $_POST['nominal'] ?? '0');
    $tipe_bayar = $_POST['tipe_bayar'];
    $kali_cicilan = ($tipe_bayar == 'Cicilan') ? $_POST['kali_cicilan'] : 0;

    // Handle Tagihan Kepada (Array to String)
    $tagihan_kelas = isset($_POST['tagihan_kelas']) ? implode(',', $_POST['tagihan_kelas']) : '';
    $status = $_POST['status'];

    $query = mysqli_query($koneksi, "UPDATE jenis_bayar SET nama_pembayaran='$nama_pembayaran', nominal='$nominal', tipe_bayar='$tipe_bayar', kali_cicilan='$kali_cicilan', tagihan_kelas='$tagihan_kelas', status='$status' WHERE id_jenis_bayar='$id_jenis_bayar'");
    if ($query) {
        pembayaran_log_activity($koneksi, 'Update', "Mengedit jenis bayar: $nama_pembayaran ($tipe_bayar)");
        echo "<script>
            Swal.fire({
                title: 'Berhasil',
                text: 'Data berhasil diupdate',
                icon: 'success',
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
                window.location='jenis_bayar.php';
            });
        </script>";
    } else {
        echo "<script>Swal.fire('Gagal', 'Data gagal diupdate', 'error');</script>";
    }
}

// Proses Hapus
if (isset($_GET['hapus'])) {
    $id_jenis_bayar = $_GET['hapus'];
    $query = mysqli_query($koneksi, "DELETE FROM jenis_bayar WHERE id_jenis_bayar='$id_jenis_bayar'");
    if ($query) {
        pembayaran_log_activity($koneksi, 'Delete', "Menghapus jenis bayar ID: $id_jenis_bayar");
        echo "<script>
            Swal.fire({
                title: 'Berhasil',
                text: 'Data berhasil dihapus',
                icon: 'success',
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
                window.location='jenis_bayar.php';
            });
        </script>";
    } else {
        echo "<script>Swal.fire('Gagal', 'Data gagal dihapus', 'error');</script>";
    }
}
?>

<div class="card">
    <div class="card-header">
        <h4>Data Jenis Bayar</h4>
    </div>
    <div class="card-body">
                <button type="button" class="btn btn-primary mb-3" data-toggle="modal" data-target="#modalTambahJenis">
                    <i class="fas fa-plus mr-1"></i> Tambah Jenis Bayar
                </button>
                <div class="table-responsive">
                    <table class="table table-striped table-bordered pembayaran-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nama Pembayaran</th>
                                <th>Nominal</th>
                                <th>Waktu Bayar</th>
                                <th>Tagihan Kepada</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $jenis_bayar_rows = [];
                            $query = mysqli_query($koneksi, "SELECT * FROM jenis_bayar ORDER BY id_jenis_bayar DESC");
                            while ($row = mysqli_fetch_assoc($query)) {
                                $jenis_bayar_rows[] = $row;
                            }
                            $no = 1;
                            foreach ($jenis_bayar_rows as $row) :
                                // Explode tagihan_kelas for check
                                $selected_kelas = explode(',', $row['tagihan_kelas'] ?? '');
                            ?>
                                <tr>
                                    <td data-label="No"><?= $no++ ?></td>
                                    <td data-label="Nama Pembayaran"><?= $row['nama_pembayaran'] ?></td>
                                    <td data-label="Nominal">Rp <?= number_format($row['nominal'], 0, ',', '.') ?></td>
                                    <td data-label="Waktu Bayar">
                                        <?= $row['tipe_bayar'] ?>
                                        <?php if ($row['tipe_bayar'] == 'Cicilan') : ?>
                                            (<?= $row['kali_cicilan'] ?>x)
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Tagihan Kepada">
                                        <?php if (!empty($row['tagihan_kelas'])) : ?>
                                            <?php if (count($selected_kelas) <= 5) : ?>
                                                <?php foreach ($selected_kelas as $kls) : ?>
                                                    <span class="badge badge-info mb-1" style="margin-right: 2px;"><?= $kls ?></span>
                                                <?php endforeach; ?>
                                            <?php else : ?>
                                                <span class="badge badge-info" title="<?= $row['tagihan_kelas'] ?>">
                                                    <?= count($selected_kelas) ?> Kelas
                                                </span>
                                            <?php endif; ?>
                                        <?php else : ?>
                                            <span class="badge badge-secondary">Semua / Kosong</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Status">
                                        <?php if ($row['status'] == 'Aktif') : ?>
                                            <span class="badge badge-success">Aktif</span>
                                        <?php else : ?>
                                            <span class="badge badge-danger">Tidak Aktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Aksi">
                                        <div class="pb-actions">
                                            <button type="button" class="pb-chip pb-chip-warning" data-toggle="modal" data-target="#modalEdit<?= $row['id_jenis_bayar'] ?>" title="Edit jenis bayar">
                                                <i class="fas fa-edit"></i> <span class="pb-chip-label">Edit</span>
                                            </button>
                                            <a href="jenis_bayar.php?hapus=<?= $row['id_jenis_bayar'] ?>" class="pb-chip pb-chip-danger btn-hapus" title="Hapus jenis bayar">
                                                <i class="fas fa-trash"></i> <span class="pb-chip-label">Hapus</span>
                                            </a>
                                        </div>
                                    </td>
                                </tr>

                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
    </div>
</div>

<!-- Modal Edit -->
<?php foreach ($jenis_bayar_rows as $row) :
    $selected_kelas = explode(',', $row['tagihan_kelas'] ?? '');
?>
<div class="modal fade" id="modalEdit<?= $row['id_jenis_bayar'] ?>" tabindex="-1" role="dialog" aria-labelledby="labelEdit<?= $row['id_jenis_bayar'] ?>" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <form action="" method="post">
                <div class="modal-header">
                    <h5 class="modal-title" id="labelEdit<?= $row['id_jenis_bayar'] ?>">Edit Jenis Bayar</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_jenis_bayar" value="<?= $row['id_jenis_bayar'] ?>">
                    <div class="form-group">
                        <label class="font-weight-bold" for="nama_pembayaran_edit<?= $row['id_jenis_bayar'] ?>">Nama Pembayaran</label>
                        <input type="text" name="nama_pembayaran" id="nama_pembayaran_edit<?= $row['id_jenis_bayar'] ?>" class="form-control" value="<?= $row['nama_pembayaran'] ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold" for="nominal_edit<?= $row['id_jenis_bayar'] ?>">Nominal</label>
                        <input type="text" name="nominal" id="nominal_edit<?= $row['id_jenis_bayar'] ?>" class="form-control nominal-grouping" value="<?= number_format($row['nominal'], 0, ',', '.') ?>" inputmode="numeric" required>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold" for="tipe_bayar_edit<?= $row['id_jenis_bayar'] ?>">Waktu Bayar</label>
                        <select name="tipe_bayar" id="tipe_bayar_edit<?= $row['id_jenis_bayar'] ?>" class="form-control tipe-bayar" data-target="#cicilanEdit<?= $row['id_jenis_bayar'] ?>" required>
                            <option value="Bulanan" <?= ($row['tipe_bayar'] == 'Bulanan') ? 'selected' : '' ?>>Bulanan</option>
                            <option value="Cicilan" <?= ($row['tipe_bayar'] == 'Cicilan') ? 'selected' : '' ?>>Cicilan</option>
                        </select>
                    </div>
                    <div class="form-group cicilan-group" id="cicilanEdit<?= $row['id_jenis_bayar'] ?>" style="<?= ($row['tipe_bayar'] == 'Cicilan') ? '' : 'display:none;' ?>">
                        <label class="font-weight-bold" for="kali_cicilan_edit<?= $row['id_jenis_bayar'] ?>">Kali Cicilan</label>
                        <input type="number" name="kali_cicilan" id="kali_cicilan_edit<?= $row['id_jenis_bayar'] ?>" class="form-control" value="<?= $row['kali_cicilan'] ?>" placeholder="Contoh: 3">
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold" for="selectKelasEdit<?= $row['id_jenis_bayar'] ?>">Tagihan Kepada (Kelas)</label>
                        <div class="mb-2">
                            <button type="button" class="btn btn-sm btn-info btn-pilih-semua" data-target="#selectKelasEdit<?= $row['id_jenis_bayar'] ?>">Pilih Semua</button>
                            <button type="button" class="btn btn-sm btn-danger btn-batal-semua" data-target="#selectKelasEdit<?= $row['id_jenis_bayar'] ?>">Batal Semua</button>
                        </div>
                        <select class="form-control select2-multiple" id="selectKelasEdit<?= $row['id_jenis_bayar'] ?>" name="tagihan_kelas[]" multiple="multiple" style="width: 100%;" required>
                            <?php foreach ($kelas_list as $kelas) : ?>
                                <option value="<?= $kelas['nama_kelas'] ?>" <?= in_array($kelas['nama_kelas'], $selected_kelas) ? 'selected' : '' ?>>
                                    <?= $kelas['nama_kelas'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold" for="status_edit<?= $row['id_jenis_bayar'] ?>">Status</label>
                        <select name="status" id="status_edit<?= $row['id_jenis_bayar'] ?>" class="form-control" required>
                            <option value="Aktif" <?= ($row['status'] == 'Aktif') ? 'selected' : '' ?>>Aktif</option>
                            <option value="Tidak Aktif" <?= ($row['status'] == 'Tidak Aktif') ? 'selected' : '' ?>>Tidak Aktif</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" name="edit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Modal Tambah -->
<div class="modal fade" id="modalTambahJenis" tabindex="-1" role="dialog" aria-labelledby="labelTambahJenis" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <form action="" method="post">
                <div class="modal-header">
                    <h5 class="modal-title" id="labelTambahJenis">Tambah Jenis Bayar</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label class="font-weight-bold" for="nama_pembayaran_tambah">Nama Pembayaran</label>
                        <input type="text" name="nama_pembayaran" id="nama_pembayaran_tambah" class="form-control" placeholder="Contoh: SPP Juli 2024" required>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold" for="nominal_tambah">Nominal</label>
                        <input type="text" name="nominal" id="nominal_tambah" class="form-control nominal-grouping" placeholder="Contoh: 50.000" inputmode="numeric" required>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold" for="tipe_bayar_tambah">Waktu Bayar</label>
                        <select name="tipe_bayar" id="tipe_bayar_tambah" class="form-control tipe-bayar" data-target="#cicilanTambah" required>
                            <option value="Bulanan">Bulanan</option>
                            <option value="Cicilan">Cicilan</option>
                        </select>
                    </div>
                    <div class="form-group cicilan-group" id="cicilanTambah" style="display:none;">
                        <label class="font-weight-bold" for="kali_cicilan_tambah">Kali Cicilan</label>
                        <input type="number" name="kali_cicilan" id="kali_cicilan_tambah" class="form-control" placeholder="Contoh: 3">
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold" for="selectKelasTambah">Tagihan Kepada (Kelas)</label>
                        <div class="mb-2">
                            <button type="button" class="btn btn-sm btn-info btn-pilih-semua" data-target="#selectKelasTambah">Pilih Semua</button>
                            <button type="button" class="btn btn-sm btn-danger btn-batal-semua" data-target="#selectKelasTambah">Batal Semua</button>
                        </div>
                        <select class="form-control select2-multiple" id="selectKelasTambah" name="tagihan_kelas[]" multiple="multiple" style="width: 100%;" required>
                            <?php foreach ($kelas_list as $kelas) : ?>
                                <option value="<?= $kelas['nama_kelas'] ?>"><?= $kelas['nama_kelas'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold" for="status_tambah">Status</label>
                        <select name="status" id="status_tambah" class="form-control" required>
                            <option value="Aktif">Aktif</option>
                            <option value="Tidak Aktif">Tidak Aktif</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" name="tambah" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../templates/pembayaran_footer.php'; ?>

<script>
    $(document).ready(function() {
        function initKelasSelect($element) {
            if (!$.fn.select2 || !$element.length) return;
            if ($element.hasClass('select2-hidden-accessible')) {
                $element.select2('destroy');
            }
            $element.select2({
                placeholder: "Pilih Kelas",
                allowClear: true,
                dropdownParent: $element.closest('.modal').length ? $element.closest('.modal') : $(document.body),
                width: '100%'
            });
        }

        // Initialize Select2 for select fields inside Bootstrap modals.
        $('.select2-multiple').each(function() {
            initKelasSelect($(this));
        });

        $('.modal').on('shown.bs.modal', function() {
            var $modal = $(this);
            $modal.find('.select2-multiple').each(function() {
                initKelasSelect($(this));
            });
            $modal.find('.tipe-bayar').trigger('change');
        });

        $('.btn-hapus').on('click', function(e) {
            e.preventDefault();
            const href = $(this).attr('href');
            Swal.fire({
                title: 'Apakah anda yakin?',
                text: "Data yang dihapus tidak dapat dikembalikan!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Ya, hapus!'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = href;
                }
            })
        });

        // Toggle Input Cicilan
        $('.tipe-bayar').on('change', function() {
            const target = $(this).data('target');
            if ($(this).val() === 'Cicilan') {
                $(target).show();
                $(target).find('input').prop('required', true);
            } else {
                $(target).hide();
                $(target).find('input').prop('required', false);
            }
        });

        // Pilih Semua Kelas
        $('.btn-pilih-semua').click(function() {
            var target = $(this).data('target');
            $(target + ' > option').prop("selected", true);
            $(target).trigger("change");
        });

        // Batal Semua Kelas
        $('.btn-batal-semua').click(function() {
            var target = $(this).data('target');
            $(target + ' > option').prop("selected", false);
            $(target).trigger("change");
        });

        // Format digit grouping untuk input nominal.
        function formatNominal(value) {
            const digitsOnly = (value || '').toString().replace(/\D/g, '');
            if (!digitsOnly) return '';
            return digitsOnly.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }

        $('.nominal-grouping').on('input', function() {
            $(this).val(formatNominal($(this).val()));
        });

        // Pastikan nilai submit tetap angka murni.
        $('form').on('submit', function() {
            $(this).find('.nominal-grouping').each(function() {
                const raw = ($(this).val() || '').replace(/\D/g, '');
                $(this).val(raw);
            });
        });
    });
</script>

