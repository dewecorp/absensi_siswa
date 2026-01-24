<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

// Set page title
$page_title = 'Profil Madrasah';

// Get school profile
$school_profile = getSchoolProfile($pdo);

// Handle form submission
    $message = '';
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $nama_madrasah = sanitizeInput($_POST['nama_madrasah']);
        $kepala_madrasah = sanitizeInput($_POST['kepala_madrasah']);
        $tahun_ajaran = sanitizeInput($_POST['tahun_ajaran']);
        $semester = sanitizeInput($_POST['semester']);
        
        // Handle reset data (Annual Reset)
        if (isset($_POST['reset_data']) && $_POST['reset_data'] == '1') {
            try {
                // Delete all attendance data
                $pdo->exec("TRUNCATE TABLE tb_absensi");
                $pdo->exec("TRUNCATE TABLE tb_absensi_guru");
                
                // Log the action
                if (function_exists('logActivity')) {
                    logActivity($pdo, $_SESSION['username'] ?? 'admin', 'Hapus Data Tahunan', 'Mereset data kehadiran untuk tahun ajaran baru ' . $tahun_ajaran);
                }
            } catch (Exception $e) {
                // If TRUNCATE fails (e.g. FK constraints), try DELETE
                $pdo->exec("DELETE FROM tb_absensi");
                $pdo->exec("DELETE FROM tb_absensi_guru");
            }
        }
        
        // Handle logo upload
    $logo = $school_profile['logo']; // Keep existing logo if no new file is uploaded
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        $file_extension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        
        if (in_array($file_extension, $allowed_extensions)) {
            $new_logo_name = 'logo_' . time() . '.' . $file_extension;
            $target_dir = '../assets/img/';
            $target_file = $target_dir . $new_logo_name;
            
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_file)) {
                // Delete old logo if it's not the default
                if ($school_profile['logo'] != 'logo.png' && file_exists($target_dir . $school_profile['logo'])) {
                    unlink($target_dir . $school_profile['logo']);
                }
                $logo = $new_logo_name;
            } else {
                $message = ['type' => 'danger', 'text' => 'Gagal mengupload logo!'];
            }
        } else {
            $message = ['type' => 'danger', 'text' => 'Format file logo tidak didukung!'];
        }
    }

    // Handle hero image upload
    $hero_image = $school_profile['dashboard_hero_image'];
    if (isset($_FILES['hero_image']) && $_FILES['hero_image']['error'] == 0) {
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        $file_extension = strtolower(pathinfo($_FILES['hero_image']['name'], PATHINFO_EXTENSION));
        
        if (in_array($file_extension, $allowed_extensions)) {
            $new_hero_name = 'hero_' . time() . '.' . $file_extension;
            $target_dir = '../assets/img/';
            $target_file = $target_dir . $new_hero_name;
            
            if (move_uploaded_file($_FILES['hero_image']['tmp_name'], $target_file)) {
                // Delete old hero image if it exists
                if (!empty($school_profile['dashboard_hero_image']) && file_exists($target_dir . $school_profile['dashboard_hero_image'])) {
                    unlink($target_dir . $school_profile['dashboard_hero_image']);
                }
                $hero_image = $new_hero_name;
            } else {
                $message = ['type' => 'danger', 'text' => 'Gagal mengupload background hero!'];
            }
        } else {
            $message = ['type' => 'danger', 'text' => 'Format file background tidak didukung!'];
        }
    }
    
    if (empty($message)) {
        $stmt = $pdo->prepare("UPDATE tb_profil_madrasah SET nama_madrasah=?, kepala_madrasah=?, tahun_ajaran=?, semester=?, logo=?, dashboard_hero_image=? WHERE id=1");
        if ($stmt->execute([$nama_madrasah, $kepala_madrasah, $tahun_ajaran, $semester, $logo, $hero_image])) {
            $message = ['type' => 'success', 'text' => 'Profil madrasah berhasil diperbarui!'];
            // Refresh school profile
            $school_profile = getSchoolProfile($pdo);
        } else {
            $message = ['type' => 'danger', 'text' => 'Gagal memperbarui profil madrasah!'];
        }
    }
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>
            <!-- Main Content -->
            <div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Profil Madrasah</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                            <div class="breadcrumb-item">Profil Madrasah</div>
                        </div>
                    </div>

                    <?php if ($message): ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            Swal.fire({
                                title: '<?php echo $message['type'] === 'success' ? 'Sukses!' : 'Error!'; ?>',
                                text: '<?php echo addslashes($message['text']); ?>',
                                icon: '<?php echo $message['type']; ?>',
                                timer: <?php echo $message['type'] === 'success' ? '3000' : '5000'; ?>,
                                timerProgressBar: true,
                                showConfirmButton: false
                            });
                        });
                    </script>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Profil Madrasah</h4>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="" enctype="multipart/form-data">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Nama Madrasah</label>
                                                    <input type="text" class="form-control" name="nama_madrasah" value="<?php echo htmlspecialchars($school_profile['nama_madrasah']); ?>" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Nama Kepala Madrasah</label>
                                                    <input type="text" class="form-control" name="kepala_madrasah" value="<?php echo htmlspecialchars($school_profile['kepala_madrasah'] ?? ''); ?>" required>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row mt-3">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Tahun Ajaran</label>
                                                    <input type="text" class="form-control" name="tahun_ajaran" value="<?php echo htmlspecialchars($school_profile['tahun_ajaran'] ?? ''); ?>" placeholder="Contoh: 2026/2027" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Semester</label>
                                                    <select class="form-control" name="semester" required>
                                                        <option value="">Pilih Semester</option>
                                                        <option value="Semester 1" <?php echo (isset($school_profile['semester']) && $school_profile['semester'] == 'Semester 1') ? 'selected' : ''; ?>>Semester 1</option>
                                                        <option value="Semester 2" <?php echo (isset($school_profile['semester']) && $school_profile['semester'] == 'Semester 2') ? 'selected' : ''; ?>>Semester 2</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-12">
                                                <div class="alert alert-warning">
                                                    <div class="custom-control custom-checkbox">
                                                        <input type="checkbox" class="custom-control-input" id="reset_data" name="reset_data" value="1">
                                                        <label class="custom-control-label font-weight-bold" for="reset_data">Reset Data Kehadiran (Pergantian Tahun Ajaran)</label>
                                                        <small class="d-block mt-1">Centang opsi ini <b>HANYA</b> jika Anda ingin menghapus seluruh data kehadiran Siswa dan Guru (misal: saat memulai tahun ajaran baru). Data yang dihapus tidak dapat dikembalikan.</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row mt-3">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Logo Madrasah</label>
                                                    <div class="mb-2">
                                                        <?php if ($school_profile['logo']): ?>
                                                        <img src="../assets/img/<?php echo $school_profile['logo']; ?>" alt="Logo Madrasah" width="100" height="100" class="img-thumbnail">
                                                        <?php else: ?>
                                                        <p class="text-muted">Logo belum diupload</p>
                                                        <?php endif; ?>
                                                    </div>
                                                    <input type="file" class="form-control" name="logo">
                                                    <small class="text-muted">Format: JPG, PNG, GIF. Ukuran maksimal: 2MB</small>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Background Dashboard Guru/Wali</label>
                                                    <div class="mb-2">
                                                        <?php if (!empty($school_profile['dashboard_hero_image'])): ?>
                                                        <img src="../assets/img/<?php echo $school_profile['dashboard_hero_image']; ?>" alt="Hero Image" height="100" class="img-thumbnail" style="object-fit: cover; width: 100%;">
                                                        <?php else: ?>
                                                        <img src="../assets/img/unsplash/eberhard-grossgasteiger-1207565-unsplash.jpg" alt="Default Hero" height="100" class="img-thumbnail" style="object-fit: cover; width: 100%;">
                                                        <p class="text-muted small">Menggunakan gambar default</p>
                                                        <?php endif; ?>
                                                    </div>
                                                    <input type="file" class="form-control" name="hero_image">
                                                    <small class="text-muted">Format: JPG, PNG, GIF. Ukuran maksimal: 2MB. Disarankan gambar landscape.</small>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row mt-4">
                                            <div class="col-12 text-center">
                                                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
<?php
include '../templates/footer.php';
?>