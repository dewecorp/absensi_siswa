<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['siswa'])) {
    redirect('../login.php');
}

$id_siswa = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT s.*, k.nama_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_siswa = ?");
$stmt->execute([$id_siswa]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    echo "Data siswa tidak ditemukan.";
    exit;
}

$foto_profil = !empty($student['foto']) && file_exists('../assets/img/siswa/' . $student['foto'])
    ? '../assets/img/siswa/' . $student['foto']
    : null;
$inisial = strtoupper(substr((string)($student['nama_siswa'] ?? 'S'), 0, 1));

$page_title = 'Profil Saya';

include '../templates/header.php';
include_once '../templates/sidebar.php';
?>

            <div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Profil Saya</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></div>
                            <div class="breadcrumb-item active">Profil Saya</div>
                        </div>
                    </div>

                    <!-- Banner profil full width -->
                    <div class="row">
                        <div class="col-12 mb-4">
                            <div class="card border-0 shadow-sm" style="border-radius:18px;overflow:hidden;background:linear-gradient(135deg,#6777ef 0%,#3abaf4 100%);color:#fff;">
                                <div class="card-body p-4">
                                    <div class="d-flex flex-column flex-md-row align-items-center" style="gap:20px;">
                                        <div class="position-relative flex-shrink-0">
                                            <?php if ($foto_profil): ?>
                                                <img alt="foto" src="<?php echo $foto_profil; ?>" class="rounded-circle shadow" style="width:110px;height:110px;object-fit:cover;border:4px solid #fff;">
                                            <?php else: ?>
                                                <div class="rounded-circle bg-white text-primary d-flex align-items-center justify-content-center shadow" style="width:110px;height:110px;font-weight:800;font-size:2.8rem;">
                                                    <?php echo $inisial; ?>
                                                </div>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-light btn-sm position-absolute" style="bottom:2px;right:2px;border-radius:50%;width:34px;height:34px;padding:0;color:#6777ef;" data-toggle="modal" data-target="#modalUploadFoto" title="Ubah foto">
                                                <i class="fas fa-camera"></i>
                                            </button>
                                        </div>
                                        <div class="text-center text-md-left flex-grow-1">
                                            <h4 class="font-weight-bold text-white mb-1"><?php echo htmlspecialchars($student['nama_siswa']); ?></h4>
                                            <div class="mb-2">
                                                <span class="badge badge-light px-3 py-2 mr-1"><i class="fas fa-chalkboard mr-1"></i>Kelas <?php echo htmlspecialchars($student['nama_kelas'] ?? '-'); ?></span>
                                                <span class="badge px-3 py-2 <?php echo $student['jenis_kelamin'] == 'L' ? 'badge-info' : 'badge-danger'; ?>">
                                                    <i class="fas fa-<?php echo $student['jenis_kelamin'] == 'L' ? 'mars' : 'venus'; ?> mr-1"></i>
                                                    <?php echo $student['jenis_kelamin'] == 'L' ? 'Laki-laki' : 'Perempuan'; ?>
                                                </span>
                                            </div>
                                            <div style="color:#f1f5f9;font-size:13px;">
                                                NISN <?php echo htmlspecialchars($student['nisn'] ?? '-'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <!-- Data diri -->
                        <div class="col-lg-7 col-md-12 mb-4">
                            <div class="card h-100">
                                <div class="card-header">
                                    <h4><i class="fas fa-id-card mr-2 text-primary"></i>Data Diri</h4>
                                </div>
                                <div class="card-body p-0">
                                    <ul class="list-group list-group-flush">
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="text-muted"><i class="fas fa-fingerprint mr-2"></i>NISN</span>
                                            <strong><?php echo htmlspecialchars($student['nisn'] ?? '-'); ?></strong>
                                        </li>

                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="text-muted"><i class="fas fa-chalkboard mr-2"></i>Kelas</span>
                                            <strong><span class="badge badge-info px-2 py-1"><?php echo htmlspecialchars($student['nama_kelas'] ?? '-'); ?></span></strong>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="text-muted"><i class="fas fa-map-marker-alt mr-2"></i>Tempat Lahir</span>
                                            <strong><?php echo htmlspecialchars($student['tempat_lahir'] ?? '-'); ?></strong>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="text-muted"><i class="fas fa-birthday-cake mr-2"></i>Tanggal Lahir</span>
                                            <strong><?php echo !empty($student['tanggal_lahir']) ? date('d-m-Y', strtotime($student['tanggal_lahir'])) : '-'; ?></strong>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="text-muted"><i class="fas fa-user-tie mr-2"></i>Orang Tua / Wali</span>
                                            <strong><?php echo htmlspecialchars($student['wali'] ?? $student['nama_ortu'] ?? '-'); ?></strong>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <!-- Jalan pintas -->
                        <div class="col-lg-5 col-md-12 mb-4">
                            <div class="card h-100">
                                <div class="card-header">
                                    <h4><i class="fas fa-bolt mr-2 text-warning"></i>Jalan Pintas</h4>
                                </div>
                                <div class="card-body">
                                    <a href="tugas.php" class="btn btn-info btn-block mb-2 text-left"><i class="fas fa-tasks mr-2"></i>Tugas Saya</a>
                                    <a href="rekap_nilai.php" class="btn btn-success btn-block mb-2 text-left"><i class="fas fa-chart-bar mr-2"></i>Nilai Saya</a>
                                    <a href="tabungan_siswa.php" class="btn btn-warning btn-block mb-2 text-left text-white"><i class="fas fa-piggy-bank mr-2"></i>Tabungan Saya</a>
                                    <a href="tagihan_siswa.php" class="btn btn-danger btn-block mb-2 text-left"><i class="fas fa-file-invoice-dollar mr-2"></i>Tagihan Saya</a>
                                    <a href="rekap_absensi.php" class="btn btn-secondary btn-block mb-0 text-left"><i class="fas fa-history mr-2"></i>Rekap Kehadiran</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

<!-- Modal Upload Foto -->
<div class="modal fade" id="modalUploadFoto" tabindex="-1" role="dialog" aria-labelledby="modalUploadFotoLabel" aria-hidden="true">
    <div class="modal-dialog" role="dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalUploadFotoLabel">Ubah Foto Profil</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="formUploadFoto" enctype="multipart/form-data">
                <div class="modal-body text-center">
                    <div class="mb-3">
                        <div id="containerPreview">
                            <?php if ($foto_profil): ?>
                                <img id="previewFoto" src="<?php echo $foto_profil; ?>" class="rounded-circle shadow" style="width: 150px; height: 150px; object-fit: cover; border: 4px solid #6777ef;">
                            <?php else: ?>
                                <div id="previewAvatar" class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center shadow mx-auto" style="width: 150px; height: 150px; font-weight: 700; font-size: 4rem; border: 4px solid #fff;">
                                    <?php echo $inisial; ?>
                                </div>
                                <img id="previewFoto" src="" class="rounded-circle shadow d-none" style="width: 150px; height: 150px; object-fit: cover; border: 4px solid #6777ef;">
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-group text-left">
                        <label>Pilih Foto Baru</label>
                        <input type="file" name="foto" id="inputFoto" class="form-control" accept="image/*" required>
                        <small class="text-muted">Format: JPG, JPEG, PNG. Maksimal 2MB.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="btnSimpanFoto">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
include '../templates/footer.php';
?>

<script>
// Preview foto sebelum upload
document.getElementById('inputFoto').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const previewImg = document.getElementById('previewFoto');
            const previewAvatar = document.getElementById('previewAvatar');

            previewImg.src = e.target.result;
            previewImg.classList.remove('d-none');

            if (previewAvatar) {
                previewAvatar.classList.add('d-none');
            }
        }
        reader.readAsDataURL(file);
    }
});

// Handle upload foto via AJAX
document.getElementById('formUploadFoto').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const btn = document.getElementById('btnSimpanFoto');

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Menyimpan...';

    fetch('upload_foto.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            Swal.fire({
                title: 'Berhasil!',
                text: data.message,
                icon: 'success',
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire('Gagal!', data.message, 'error');
            btn.disabled = false;
            btn.innerHTML = 'Simpan Perubahan';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('Error!', 'Terjadi kesalahan sistem.', 'error');
        btn.disabled = false;
        btn.innerHTML = 'Simpan Perubahan';
    });
});
</script>
