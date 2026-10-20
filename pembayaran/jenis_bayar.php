<?php
$title = 'Jenis Pembayaran';
include __DIR__ . '/template/header.php';
include __DIR__ . '/template/sidebar.php';

// Include Select2 CSS
?>
<link rel="stylesheet" href="<?= base_url('assets/vendors/select2/select2.min.css') ?>">
<style>
    /* Fix Select2 width in Modals */
    .select2-container {
        width: 100% !important;
    }
    .select2-search__field {
        width: 100% !important;
    }
</style>

<?php
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

<div class="app-grid">
    <div class="app-col-full app-section-gap app-stretch">
        <div class="app-panel">
            <div class="app-panel-body">
                <h4 class="app-panel-title">Data Jenis Bayar</h4>
                <button type="button" class="app-button app-button-primary mb-3" data-tailwind-modal-target="#modalTambahJenis">
                    <i class="mdi mdi-plus"></i> Tambah Jenis Bayar
                </button>
                <div class="app-table-scroll">
                    <table class="app-data-table app-table-striped">
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
                            $no = 1;
                            $query = mysqli_query($koneksi, "SELECT * FROM jenis_bayar ORDER BY id_jenis_bayar DESC");
                            while ($row = mysqli_fetch_assoc($query)) :
                                // Explode tagihan_kelas for check
                                $selected_kelas = explode(',', $row['tagihan_kelas'] ?? '');
                            ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><?= $row['nama_pembayaran'] ?></td>
                                    <td>Rp <?= number_format($row['nominal'], 0, ',', '.') ?></td>
                                    <td>
                                        <?= $row['tipe_bayar'] ?>
                                        <?php if ($row['tipe_bayar'] == 'Cicilan') : ?>
                                            (<?= $row['kali_cicilan'] ?>x)
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['tagihan_kelas'])) : ?>
                                            <?php if (count($selected_kelas) <= 5) : ?>
                                                <?php foreach ($selected_kelas as $kls) : ?>
                                                    <span class="app-badge app-badge-info mb-1" style="margin-right: 2px;"><?= $kls ?></span>
                                                <?php endforeach; ?>
                                            <?php else : ?>
                                                <span class="app-badge app-badge-info" title="<?= $row['tagihan_kelas'] ?>">
                                                    <?= count($selected_kelas) ?> Kelas
                                                </span>
                                            <?php endif; ?>
                                        <?php else : ?>
                                            <span class="app-badge app-badge-secondary">Semua / Kosong</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['status'] == 'Aktif') : ?>
                                            <span class="app-badge app-badge-success">Aktif</span>
                                        <?php else : ?>
                                            <span class="app-badge app-badge-danger">Tidak Aktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="app-button app-button-warning app-button-sm" data-tailwind-modal-target="#modalEdit<?= $row['id_jenis_bayar'] ?>">
                                            <i class="mdi mdi-pencil"></i>
                                        </button>
                                        <a href="jenis_bayar.php?hapus=<?= $row['id_jenis_bayar'] ?>" class="app-button app-button-danger app-button-sm btn-hapus">
                                            <i class="mdi mdi-delete"></i>
                                        </a>
                                    </td>
                                </tr>

                                <!-- Modal Edit -->
                                <div class="fixed inset-0 z-[1055] hidden overflow-y-auto bg-slate-950/60 px-4 py-6 backdrop-blur-sm" id="modalEdit<?= $row['id_jenis_bayar'] ?>" data-tailwind-modal tabindex="-1" role="dialog" aria-labelledby="labelEdit<?= $row['id_jenis_bayar'] ?>" aria-hidden="true">
                                    <div class="mx-auto flex min-h-full w-full max-w-2xl items-start">
                                        <div class="flex max-h-[calc(100vh-3rem)] w-full flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
                                            <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-6 py-4">
                                                <h5 class="text-base font-extrabold text-slate-900" id="labelEdit<?= $row['id_jenis_bayar'] ?>">Edit Jenis Bayar</h5>
                                                <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" data-tailwind-modal-close aria-label="Close" style="background: transparent; border: none;">
                                                    <i class="mdi mdi-close"></i>
                                                </button>
                                            </div>
                                            <form action="" method="post">
                                                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                                                    <input type="hidden" name="id_jenis_bayar" value="<?= $row['id_jenis_bayar'] ?>">
                                                    <div class="app-field">
                                                        <label>Nama Pembayaran</label>
                                                        <input type="text" name="nama_pembayaran" class="app-control" value="<?= $row['nama_pembayaran'] ?>" required>
                                                    </div>
                                                    <div class="app-field">
                                                        <label>Nominal</label>
                                                        <input type="text" name="nominal" class="app-control nominal-grouping" value="<?= number_format($row['nominal'], 0, ',', '.') ?>" inputmode="numeric" required>
                                                    </div>
                                                    <div class="app-field">
                                                        <label>Waktu Bayar</label>
                                                        <select name="tipe_bayar" class="app-control tipe-bayar" data-target="#cicilanEdit<?= $row['id_jenis_bayar'] ?>" required>
                                                            <option value="Bulanan" <?= ($row['tipe_bayar'] == 'Bulanan') ? 'selected' : '' ?>>Bulanan</option>
                                                            <option value="Cicilan" <?= ($row['tipe_bayar'] == 'Cicilan') ? 'selected' : '' ?>>Cicilan</option>
                                                        </select>
                                                    </div>
                                                    <div class="app-field cicilan-group" id="cicilanEdit<?= $row['id_jenis_bayar'] ?>" style="<?= ($row['tipe_bayar'] == 'Cicilan') ? '' : 'display:none;' ?>">
                                                        <label>Kali Cicilan</label>
                                                        <input type="number" name="kali_cicilan" class="app-control" value="<?= $row['kali_cicilan'] ?>" placeholder="Contoh: 3">
                                                    </div>
                                                    <div class="app-field">
                                                        <label>Tagihan Kepada (Kelas)</label>
                                                        <div>
                                                            <button type="button" class="app-button app-button-sm app-button-info mb-2 btn-pilih-semua" data-target="#selectKelasEdit<?= $row['id_jenis_bayar'] ?>">Pilih Semua</button>
                                                            <button type="button" class="app-button app-button-sm app-button-danger mb-2 btn-batal-semua" data-target="#selectKelasEdit<?= $row['id_jenis_bayar'] ?>">Batal Semua</button>
                                                        </div>
                                                        <select class="app-control select2-multiple" id="selectKelasEdit<?= $row['id_jenis_bayar'] ?>" name="tagihan_kelas[]" multiple="multiple" style="width: 100%;" required>
                                                            <?php foreach ($kelas_list as $kelas) : ?>
                                                                <option value="<?= $kelas['nama_kelas'] ?>" <?= in_array($kelas['nama_kelas'], $selected_kelas) ? 'selected' : '' ?>>
                                                                    <?= $kelas['nama_kelas'] ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="app-field">
                                                        <label>Status</label>
                                                        <select name="status" class="app-control" required>
                                                            <option value="Aktif" <?= ($row['status'] == 'Aktif') ? 'selected' : '' ?>>Aktif</option>
                                                            <option value="Tidak Aktif" <?= ($row['status'] == 'Tidak Aktif') ? 'selected' : '' ?>>Tidak Aktif</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="flex shrink-0 items-center justify-end gap-3 border-t border-slate-200 px-6 py-4">
                                                    <button type="button" class="app-button app-button-secondary" data-tailwind-modal-close>Batal</button>
                                                    <button type="submit" name="edit" class="app-button app-button-primary">Simpan</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Tambah -->
<div class="fixed inset-0 z-[1055] hidden overflow-y-auto bg-slate-950/60 px-4 py-6 backdrop-blur-sm" id="modalTambahJenis" tabindex="-1" role="dialog" aria-labelledby="labelTambahJenis" aria-hidden="true" data-tailwind-modal>
    <div class="mx-auto flex min-h-full w-full max-w-2xl items-start">
        <div class="flex max-h-[calc(100vh-3rem)] w-full flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
            <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-6 py-4">
                <h5 class="text-base font-extrabold text-slate-900" id="labelTambahJenis">Tambah Jenis Bayar</h5>
                <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" data-tailwind-modal-close aria-label="Close" style="background: transparent; border: none;">
                    <i class="mdi mdi-close"></i>
                </button>
            </div>
            <form action="" method="post">
                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                    <div class="app-field">
                        <label>Nama Pembayaran</label>
                        <input type="text" name="nama_pembayaran" class="app-control" placeholder="Contoh: SPP Juli 2024" required>
                    </div>
                    <div class="app-field">
                        <label>Nominal</label>
                        <input type="text" name="nominal" class="app-control nominal-grouping" placeholder="Contoh: 50.000" inputmode="numeric" required>
                    </div>
                    <div class="app-field">
                        <label>Waktu Bayar</label>
                        <select name="tipe_bayar" class="app-control tipe-bayar" data-target="#cicilanTambah" required>
                            <option value="Bulanan">Bulanan</option>
                            <option value="Cicilan">Cicilan</option>
                        </select>
                    </div>
                    <div class="app-field cicilan-group" id="cicilanTambah" style="display:none;">
                        <label>Kali Cicilan</label>
                        <input type="number" name="kali_cicilan" class="app-control" placeholder="Contoh: 3">
                    </div>
                    <div class="app-field">
                        <label>Tagihan Kepada (Kelas)</label>
                        <div>
                            <button type="button" class="app-button app-button-sm app-button-info mb-2 btn-pilih-semua" data-target="#selectKelasTambah">Pilih Semua</button>
                            <button type="button" class="app-button app-button-sm app-button-danger mb-2 btn-batal-semua" data-target="#selectKelasTambah">Batal Semua</button>
                        </div>
                        <select class="app-control select2-multiple" id="selectKelasTambah" name="tagihan_kelas[]" multiple="multiple" style="width: 100%;" required>
                            <?php foreach ($kelas_list as $kelas) : ?>
                                <option value="<?= $kelas['nama_kelas'] ?>"><?= $kelas['nama_kelas'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="app-field">
                        <label>Status</label>
                        <select name="status" class="app-control" required>
                            <option value="Aktif">Aktif</option>
                            <option value="Tidak Aktif">Tidak Aktif</option>
                        </select>
                    </div>
                </div>
                <div class="flex shrink-0 items-center justify-end gap-3 border-t border-slate-200 px-6 py-4">
                    <button type="button" class="app-button app-button-secondary" data-tailwind-modal-close>Batal</button>
                    <button type="submit" name="tambah" class="app-button app-button-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/template/footer.php'; ?>

<!-- Select2 JS -->
<script src="<?= base_url('assets/vendors/select2/select2.min.js') ?>"></script>

<script>
    $(document).ready(function() {
        // Initialize Select2 with dropdownParent fix for Modals
        $('.select2-multiple').each(function() {
            $(this).select2({
                placeholder: "Pilih Kelas",
                allowClear: true,
                dropdownParent: $(this).closest('[data-tailwind-modal]'),
                width: '100%'
            });
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

