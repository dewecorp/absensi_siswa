<?php
$title = 'Pengaturan';
include __DIR__ . '/../templates/pembayaran_header.php';

ensure_pengaturan_tanggal_mulai_column($koneksi);

$upload_dir = __DIR__ . '/../assets/img/pembayaran';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0775, true);
}

// Ambil data pengaturan
$query = mysqli_query($koneksi, "SELECT * FROM pengaturan WHERE id_pengaturan = 1");
$data = mysqli_fetch_assoc($query);

if (isset($_POST['simpan'])) {
    // Data sekolah (nama, bendahara, tahun ajaran, tanggal mulai, alamat) dikelola di
    // Admin -> Profil Madrasah dan disinkronkan otomatis. Di sini hanya logo & background login.
    $query_update = "UPDATE pengaturan SET id_pengaturan = id_pengaturan";

    // Upload Logo
    if (!empty($_FILES['logo']['name'])) {
        $file_name = $_FILES['logo']['name'];
        $file_tmp = $_FILES['logo']['tmp_name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png'];

        if (in_array($file_ext, $allowed_ext)) {
            $new_name = "logo." . $file_ext;
            $upload_path = $upload_dir . '/' . $new_name;

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
            $bg_upload_path = $upload_dir . '/' . $new_bg_name;

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

?>

<div class="row">
    <div class="col-12 mb-4">
        <div class="card">
            <div class="card-header">
                <h4>Pengaturan Sekolah</h4>
            </div>
            <div class="card-body">
                <p class="text-muted">Data sekolah dikelola dari Admin &rarr; Profil Madrasah. Di halaman ini hanya logo &amp; background login modul Pembayaran.</p>
                <form method="post" enctype="multipart/form-data">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold" for="nama_sekolah">Nama Sekolah</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($data['nama_sekolah'] ?? '') ?>" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold" for="nama_bendahara">Nama Bendahara</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($data['nama_bendahara'] ?? '') ?>" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold" for="tahun_ajaran">Tahun Ajaran Aktif</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($data['tahun_ajaran'] ?? '') ?>" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold" for="tanggal_mulai_tahun_ajaran">Tanggal Mulai Tahun Ajaran</label>
                                <input type="date" class="form-control" value="<?= htmlspecialchars($data['tanggal_mulai_tahun_ajaran'] ?? default_tanggal_mulai_tahun_ajaran($data['tahun_ajaran'] ?? '')) ?>" readonly>
                                <small class="text-muted">Batas munculnya tagihan tahun ajaran sebelumnya.</small>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-group">
                                <label class="font-weight-bold" for="alamat_sekolah">Alamat Sekolah</label>
                                <textarea class="form-control" rows="3" readonly><?= htmlspecialchars($data['alamat_sekolah'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <label class="font-weight-bold">Logo Sekolah</label>
                                    <?php if (!empty($data['logo'])) : ?>
                                        <div class="d-flex align-items-center justify-content-center bg-white border rounded p-3 mb-3" style="height:128px;">
                                            <img src="../assets/img/pembayaran/<?= $data['logo'] ?>" alt="Logo Sekolah" class="img-fluid" style="max-height:100%;">
                                        </div>
                                    <?php endif; ?>
                                    <input type="file" name="logo" class="file-upload-default" id="logoInput" accept=".jpg, .jpeg, .png" style="display:none">
                                    <input type="text" class="form-control file-upload-info" disabled placeholder="Upload Logo">
                                    <button class="btn btn-primary mt-3" type="button" onclick="document.getElementById('logoInput').click()">Upload</button>
                                    <small class="mt-3 d-block text-muted">Format: JPG, JPEG, PNG.</small>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <label class="font-weight-bold">Background Login</label>
                                    <?php if (!empty($data['bg_login'])) : ?>
                                        <div class="d-flex align-items-center justify-content-center bg-white border rounded p-3 mb-3" style="height:128px;">
                                            <img src="../assets/img/pembayaran/<?= $data['bg_login'] ?>" alt="Background Login" class="img-fluid" style="max-height:100%;">
                                        </div>
                                    <?php endif; ?>
                                    <input type="file" name="bg_login" class="file-upload-default" id="bgInput" accept=".jpg, .jpeg, .png" style="display:none">
                                    <input type="text" class="form-control file-upload-info-bg" disabled placeholder="Upload Background Login">
                                    <button class="btn btn-primary mt-3" type="button" onclick="document.getElementById('bgInput').click()">Upload</button>
                                    <small class="mt-3 d-block text-muted">Format: JPG, JPEG, PNG.</small>
                                </div>
                            </div>
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
                    <div class="d-flex flex-wrap mt-4" style="gap:12px;">
                        <button type="submit" name="simpan" class="btn btn-primary">Simpan Perubahan</button>
                        <button type="button" class="btn btn-light" onclick="window.history.back()">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>

<?php include __DIR__ . '/../templates/pembayaran_footer.php'; ?>
