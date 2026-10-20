<?php
$title = 'Pengaturan';
include __DIR__ . '/template/header.php';
include __DIR__ . '/template/sidebar.php';

ensure_pengaturan_tanggal_mulai_column($koneksi);

// Ambil data pengaturan
$query = mysqli_query($koneksi, "SELECT * FROM pengaturan WHERE id_pengaturan = 1");
$data = mysqli_fetch_assoc($query);

if (isset($_POST['simpan'])) {
    $nama_sekolah = $_POST['nama_sekolah'];
    $alamat_sekolah = $_POST['alamat_sekolah'];
    $nama_bendahara = $_POST['nama_bendahara'];
    $tahun_ajaran = $_POST['tahun_ajaran'];
    $tanggal_mulai_tahun_ajaran = trim((string)($_POST['tanggal_mulai_tahun_ajaran'] ?? ''));
    if ($tanggal_mulai_tahun_ajaran === '') {
        $tanggal_mulai_tahun_ajaran = default_tanggal_mulai_tahun_ajaran($tahun_ajaran);
    }
    
    // Default Query
    $query_update = "UPDATE pengaturan SET nama_sekolah='$nama_sekolah', alamat_sekolah='$alamat_sekolah', nama_bendahara='$nama_bendahara', tahun_ajaran='$tahun_ajaran', tanggal_mulai_tahun_ajaran='$tanggal_mulai_tahun_ajaran'";

    // Upload Logo
    if (!empty($_FILES['logo']['name'])) {
        $file_name = $_FILES['logo']['name'];
        $file_tmp = $_FILES['logo']['tmp_name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png'];

        if (in_array($file_ext, $allowed_ext)) {
            $new_name = "logo." . $file_ext;
            $upload_path = __DIR__ . "/assets/images/" . $new_name;

            if (move_uploaded_file($file_tmp, $upload_path)) {
                $query_update .= ", logo='$new_name'";
            } else {
                echo "<script>Swal.fire('Gagal', 'Gagal mengupload logo', 'error');</script>";
            }
        } else {
            echo "<script>Swal.fire('Gagal', 'Format logo harus JPG/JPEG/PNG', 'error');</script>";
        }
    }
    
    // Upload Background Login
    if (!empty($_FILES['bg_login']['name'])) {
        $bg_name = $_FILES['bg_login']['name'];
        $bg_tmp = $_FILES['bg_login']['tmp_name'];
        $bg_ext = strtolower(pathinfo($bg_name, PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png'];

        if (in_array($bg_ext, $allowed_ext)) {
            $new_bg_name = "bg_login." . $bg_ext;
            $bg_upload_path = __DIR__ . "/assets/images/" . $new_bg_name;

            if (move_uploaded_file($bg_tmp, $bg_upload_path)) {
                $query_update .= ", bg_login='$new_bg_name'";
            } else {
                echo "<script>Swal.fire('Gagal', 'Gagal mengupload background login', 'error');</script>";
            }
        } else {
            echo "<script>Swal.fire('Gagal', 'Format background harus JPG/JPEG/PNG', 'error');</script>";
        }
    }
    
    $query_update .= " WHERE id_pengaturan = 1";
    
    if (mysqli_query($koneksi, $query_update)) {
        pembayaran_log_activity($koneksi, 'Update', "Memperbarui pengaturan sekolah");
        echo "<script>
            Swal.fire({
                title: 'Berhasil',
                text: 'Pengaturan sekolah berhasil diperbarui',
                icon: 'success',
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
                window.location.href = 'pengaturan.php?v=1';
            });
        </script>";
    } else {
        echo "<script>Swal.fire('Gagal', 'Gagal memperbarui pengaturan: " . mysqli_error($koneksi) . "', 'error');</script>";
    }
}

// Logic Reset Data Transaksi
if (isset($_POST['reset_data'])) {
    if ($_SESSION['role'] == 'admin') {
        $tahun_ajaran_aktif = get_tahun_ajaran_aktif($koneksi);
        $hasil_reset = arsipkan_dan_hapus_transaksi_tahun_ajaran_lama($koneksi, $tahun_ajaran_aktif);

        if (!empty($hasil_reset['ok'])) {
            $jumlah_hapus = (int)($hasil_reset['deleted'] ?? 0);
            $tahun_dihapus = !empty($hasil_reset['years']) ? implode(', ', $hasil_reset['years']) : 'tidak ada';
            pembayaran_log_activity($koneksi, 'Reset', "Mengarsipkan dan menghapus $jumlah_hapus transaksi lama. Tahun ajaran: $tahun_dihapus");
             echo "<script>
                Swal.fire({
                    title: 'Berhasil',
                    text: 'Transaksi lama berhasil diarsipkan. $jumlah_hapus data transaksi detail dihapus. Tahun ajaran: $tahun_dihapus',
                    icon: 'success',
                    timer: 3000,
                    showConfirmButton: false
                }).then(() => {
                    window.location.href = 'pengaturan.php?v=1';
                });
            </script>";
        } else {
            $pesan_error = htmlspecialchars($hasil_reset['message'] ?? mysqli_error($koneksi), ENT_QUOTES);
             echo "<script>Swal.fire('Gagal', 'Gagal mereset data: $pesan_error', 'error');</script>";
        }
    } else {
         echo "<script>Swal.fire('Akses Ditolak', 'Hanya Admin yang dapat melakukan reset data!', 'warning');</script>";
    }
}
?>

<div class="app-grid">
    <div class="app-col-full app-section-gap app-stretch">
        <div class="app-panel">
            <div class="app-panel-body">
                <h4 class="app-panel-title">Pengaturan Sekolah</h4>
                <p class="app-description">
                    Ubah informasi sekolah
                </p>
                <form method="post" enctype="multipart/form-data">
                    <div class="grid gap-5 lg:grid-cols-2">
                        <div class="app-field">
                            <label for="nama_sekolah">Nama Sekolah</label>
                            <input type="text" class="app-control" id="nama_sekolah" name="nama_sekolah" value="<?= $data['nama_sekolah'] ?>" required>
                        </div>
                        <div class="app-field">
                            <label for="nama_bendahara">Nama Bendahara</label>
                            <input type="text" class="app-control" id="nama_bendahara" name="nama_bendahara" value="<?= $data['nama_bendahara'] ?? '' ?>" required>
                        </div>
                        <div class="app-field">
                            <label for="tahun_ajaran">Tahun Ajaran Aktif</label>
                            <input type="text" class="app-control" id="tahun_ajaran" name="tahun_ajaran" value="<?= $data['tahun_ajaran'] ?? '' ?>" placeholder="Contoh: 2024/2025" required>
                        </div>
                        <div class="app-field">
                            <label for="tanggal_mulai_tahun_ajaran">Tanggal Mulai Tahun Ajaran</label>
                            <input type="date" class="app-control" id="tanggal_mulai_tahun_ajaran" name="tanggal_mulai_tahun_ajaran" value="<?= $data['tanggal_mulai_tahun_ajaran'] ?? default_tanggal_mulai_tahun_ajaran($data['tahun_ajaran'] ?? '') ?>" required>
                        </div>
                        <div class="app-field lg:row-span-2">
                            <label for="alamat_sekolah">Alamat Sekolah</label>
                            <textarea class="app-control h-[128px] resize-none" id="alamat_sekolah" name="alamat_sekolah" rows="4" required><?= $data['alamat_sekolah'] ?></textarea>
                        </div>
                    </div>

                    <div class="mt-6 grid gap-5 lg:grid-cols-2">
                        <div class="rounded-lg border border-slate-200 bg-slate-50/70 p-4">
                            <label>Logo Sekolah</label>
                            <?php if (!empty($data['logo'])) : ?>
                                <div class="mt-2 mb-4 flex h-32 items-center justify-center rounded-lg border border-slate-200 bg-white p-3">
                                    <img src="assets/images/<?= $data['logo'] ?>" alt="Logo Sekolah" class="max-h-full max-w-full object-contain">
                                </div>
                            <?php endif; ?>
                            <input type="file" name="logo" class="file-upload-default" id="logoInput" accept=".jpg, .jpeg, .png" style="display:none">
                            <input type="text" class="app-control file-upload-info" disabled placeholder="Upload Logo">
                            <button class="file-upload-browse app-button app-button-primary mt-3" type="button" onclick="document.getElementById('logoInput').click()">Upload</button>
                            <small class="mt-3 block text-slate-500">Format: JPG, JPEG, PNG.</small>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-slate-50/70 p-4">
                            <label>Background Login</label>
                            <?php if (!empty($data['bg_login'])) : ?>
                                <div class="mt-2 mb-4 flex h-32 items-center justify-center rounded-lg border border-slate-200 bg-white p-3">
                                    <img src="assets/images/<?= $data['bg_login'] ?>" alt="Background Login" class="max-h-full max-w-full object-contain">
                                </div>
                            <?php endif; ?>
                            <input type="file" name="bg_login" class="file-upload-default" id="bgInput" accept=".jpg, .jpeg, .png" style="display:none">
                            <input type="text" class="app-control file-upload-info-bg" disabled placeholder="Upload Background Login">
                            <button class="file-upload-browse app-button app-button-primary mt-3" type="button" onclick="document.getElementById('bgInput').click()">Upload</button>
                            <small class="mt-3 block text-slate-500">Format: JPG, JPEG, PNG.</small>
                        </div>
                    </div>

                    <script>
                        document.getElementById('logoInput').addEventListener('change', function() {
                            var fileName = this.files[0] ? this.files[0].name : '';
                            document.querySelector('.file-upload-info').value = fileName;
                        });
                        document.getElementById('bgInput').addEventListener('change', function() {
                            var fileName = this.files[0] ? this.files[0].name : '';
                            document.querySelector('.file-upload-info-bg').value = fileName;
                        });
                    </script>
                    <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                        <button type="submit" name="simpan" class="app-button app-button-primary">Simpan Perubahan</button>
                        <button type="button" class="app-button app-button-light" onclick="window.history.back()">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="app-col-full app-section-gap app-stretch">
        <div class="app-panel app-panel-danger">
            <div class="app-panel-body">
                <h4 class="app-panel-title text-red-600">Arsip & Hapus Transaksi Lama</h4>
                <p class="app-description">
                    Transaksi pembayaran tahun ajaran lama akan diringkas ke arsip tagihan, lalu data transaksi detailnya dihapus agar database tidak penuh. Tagihan dan status bulan yang sudah dibayar tetap dipertahankan.
                    <br><strong class="text-red-600">PERINGATAN: Riwayat detail transaksi lama akan hilang dari menu transaksi setelah dihapus.</strong>
                </p>
                <form method="post" id="form-reset">
                    <button type="button" class="app-button app-button-danger" onclick="confirmReset()">Arsip & Hapus Transaksi Lama</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmReset() {
    Swal.fire({
        title: 'Apakah Anda Yakin?',
        text: "Transaksi tahun ajaran lama akan diarsipkan ringkas lalu dihapus dari data transaksi. Tagihan tetap bisa dihitung, tetapi riwayat detail transaksi lama tidak tampil lagi. Pastikan Anda sudah backup/export data terlebih dahulu.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Ya, Arsipkan & Hapus!'
    }).then((result) => {
        if (result.isConfirmed) {
            // Create a hidden input to simulate the button click
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'reset_data';
            input.value = 'true';
            document.getElementById('form-reset').appendChild(input);
            document.getElementById('form-reset').submit();
        }
    })
}
</script>

<?php include __DIR__ . '/template/footer.php'; ?>
