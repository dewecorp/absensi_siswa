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
        
        // Handle Delete Grades
        if (isset($_POST['action']) && $_POST['action'] == 'delete_grades') {
            $del_tahun = $_POST['del_tahun_ajaran'];
            $del_semester = $_POST['del_semester'];
            $del_types = isset($_POST['del_types']) ? $_POST['del_types'] : [];
            
            if (empty($del_tahun) || empty($del_semester) || empty($del_types)) {
                $message = ['type' => 'danger', 'text' => 'Mohon lengkapi data yang akan dihapus!'];
            } else {
                try {
                    $pdo->beginTransaction();
                    $count = 0;
                    
                    if (in_array('harian', $del_types)) {
                        // Delete Nilai Harian
                        $stmt = $pdo->prepare("SELECT id_header FROM tb_nilai_harian_header WHERE tahun_ajaran = ? AND semester = ?");
                        $stmt->execute([$del_tahun, $del_semester]);
                        $headers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        
                        if (!empty($headers)) {
                            $placeholders = implode(',', array_fill(0, count($headers), '?'));
                            $pdo->prepare("DELETE FROM tb_nilai_harian_detail WHERE id_header IN ($placeholders)")->execute($headers);
                            $pdo->prepare("DELETE FROM tb_nilai_harian_header WHERE id_header IN ($placeholders)")->execute($headers);
                            $count += count($headers);
                        }
                    }
                    
                    if (in_array('kokurikuler', $del_types)) {
                        // Delete Nilai Kokurikuler
                        $stmt = $pdo->prepare("SELECT id_header FROM tb_nilai_kokurikuler_header WHERE tahun_ajaran = ? AND semester = ?");
                        $stmt->execute([$del_tahun, $del_semester]);
                        $headers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        
                        if (!empty($headers)) {
                            $placeholders = implode(',', array_fill(0, count($headers), '?'));
                            $pdo->prepare("DELETE FROM tb_nilai_kokurikuler_detail WHERE id_header IN ($placeholders)")->execute($headers);
                            $pdo->prepare("DELETE FROM tb_nilai_kokurikuler_header WHERE id_header IN ($placeholders)")->execute($headers);
                            $count += count($headers);
                        }
                    }
                    
                    if (in_array('semester', $del_types)) {
                        // Delete Nilai Semester (UTS, UAS, PAT, etc)
                        $stmt = $pdo->prepare("DELETE FROM tb_nilai_semester WHERE tahun_ajaran = ? AND semester = ?");
                        $stmt->execute([$del_tahun, $del_semester]);
                        $count += $stmt->rowCount(); // This might not be exact "records" count comparable to headers, but okay.
                    }
                    
                    $pdo->commit();
                    $message = ['type' => 'success', 'text' => "Data nilai berhasil dihapus ($del_tahun - $del_semester)!"];
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = ['type' => 'danger', 'text' => 'Gagal menghapus data: ' . $e->getMessage()];
                }
            }
        }
        // Handle Cleanup Data (Maintenance)
        elseif (isset($_POST['cleanup_data'])) {
             $confirm_cleanup = $_POST['confirm_cleanup'] ?? false;
             $cutoff_date = $_POST['cutoff_date'];
        
             if ($confirm_cleanup && $cutoff_date) {
                 try {
                     $messages = [];
        
                     // 1. Cleanup Attendance (Students)
                     if (isset($_POST['cleanup_attendance_student'])) {
                         $stmt = $pdo->prepare("DELETE FROM tb_absensi WHERE tanggal < ?");
                         $stmt->execute([$cutoff_date]);
                         $messages[] = $stmt->rowCount() . " data absensi siswa";
                     }
        
                     // 2. Cleanup Attendance (Teachers)
                     if (isset($_POST['cleanup_attendance_teacher'])) {
                         $stmt = $pdo->prepare("DELETE FROM tb_absensi_guru WHERE tanggal < ?");
                         $stmt->execute([$cutoff_date]);
                         $messages[] = $stmt->rowCount() . " data absensi guru";
                     }
        
                     // 3. Cleanup Journals
                     if (isset($_POST['cleanup_journals'])) {
                         $stmt = $pdo->prepare("DELETE FROM tb_jurnal WHERE tanggal < ?");
                         $stmt->execute([$cutoff_date]);
                         $messages[] = $stmt->rowCount() . " data jurnal mengajar";
                     }
        
                     // 4. Cleanup Activity Log
                     if (isset($_POST['cleanup_logs'])) {
                         $stmt = $pdo->prepare("DELETE FROM tb_activity_log WHERE created_at < ?");
                         $stmt->execute([$cutoff_date . ' 00:00:00']);
                         $messages[] = $stmt->rowCount() . " log aktivitas";
                     }
        
                     if (!empty($messages)) {
                         $msg_text = "Berhasil menghapus: " . implode(", ", $messages) . " (sebelum $cutoff_date).";
                         logActivity($pdo, $_SESSION['username'] ?? 'system', 'Pembersihan Data', $msg_text);
                         $message = ['type' => 'success', 'text' => $msg_text];
                     } else {
                         $message = ['type' => 'warning', 'text' => 'Tidak ada opsi pembersihan yang dipilih.'];
                     }
        
                 } catch (Exception $e) {
                     $message = ['type' => 'danger', 'text' => 'Gagal menghapus data: ' . $e->getMessage()];
                 }
             } else {
                 $message = ['type' => 'warning', 'text' => 'Harap lengkapi tanggal batas dan konfirmasi pembersihan.'];
             }
        }
        // Handle Reset Data Tahunan
        elseif (isset($_POST['reset_annual_data'])) {
            $tahun_ajaran = isset($_POST['tahun_ajaran']) ? $_POST['tahun_ajaran'] : $school_profile['tahun_ajaran'];
            
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
                    $message = ['type' => 'success', 'text' => 'Data kehadiran berhasil direset!'];
                } catch (Exception $e) {
                    // If TRUNCATE fails (e.g. FK constraints), try DELETE
                    $pdo->exec("DELETE FROM tb_absensi");
                    $pdo->exec("DELETE FROM tb_absensi_guru");
                    $message = ['type' => 'warning', 'text' => 'Data kehadiran direset menggunakan metode DELETE (bukan TRUNCATE).'];
                }
            }

            // Handle reset journal data
            if (isset($_POST['reset_jurnal']) && $_POST['reset_jurnal'] == '1') {
                try {
                    // Delete all journal data
                    $pdo->exec("TRUNCATE TABLE tb_jurnal");
                    
                    // Log the action
                    if (function_exists('logActivity')) {
                        logActivity($pdo, $_SESSION['username'] ?? 'admin', 'Hapus Data Jurnal', 'Mereset data jurnal mengajar untuk tahun ajaran baru ' . $tahun_ajaran);
                    }
                    // Append message if both are reset
                    if (isset($message) && $message['type'] == 'success') {
                         $message['text'] .= ' Data jurnal berhasil direset!';
                    } else {
                         $message = ['type' => 'success', 'text' => 'Data jurnal berhasil direset!'];
                    }
                } catch (Exception $e) {
                    // If TRUNCATE fails (e.g. FK constraints), try DELETE
                    $pdo->exec("DELETE FROM tb_jurnal");
                    if (isset($message)) {
                         $message['text'] .= ' (Jurnal: DELETE)';
                    } else {
                         $message = ['type' => 'warning', 'text' => 'Data jurnal direset menggunakan metode DELETE.'];
                    }
                }
            }
            
            if (!isset($_POST['reset_data']) && !isset($_POST['reset_jurnal'])) {
                 $message = ['type' => 'warning', 'text' => 'Tidak ada opsi reset yang dipilih.'];
            }
        }
        
        // Handle Update Profile
        elseif (isset($_POST['nama_yayasan'])) {
            $nama_yayasan = sanitizeInput($_POST['nama_yayasan']);
        $nama_madrasah = sanitizeInput($_POST['nama_madrasah']);
        $kepala_madrasah = sanitizeInput($_POST['kepala_madrasah']);
        $tahun_ajaran = sanitizeInput($_POST['tahun_ajaran']);
        $semester = sanitizeInput($_POST['semester']);
        $tanggal_jadwal = sanitizeInput($_POST['tanggal_jadwal']);
        $tempat_jadwal = sanitizeInput($_POST['tempat_jadwal']);
        
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

    // Handle ttd kepala upload
    $ttd_kepala = $school_profile['ttd_kepala'] ?? null;
    if (isset($_FILES['ttd_kepala']) && $_FILES['ttd_kepala']['error'] == 0) {
        $allowed_extensions = ['jpg', 'jpeg', 'png'];
        $file_extension = strtolower(pathinfo($_FILES['ttd_kepala']['name'], PATHINFO_EXTENSION));
        
        if (in_array($file_extension, $allowed_extensions)) {
            $new_ttd_name = 'ttd_kepala_' . time() . '.' . $file_extension;
            $target_dir = '../assets/img/';
            $target_file = $target_dir . $new_ttd_name;
            
            if (move_uploaded_file($_FILES['ttd_kepala']['tmp_name'], $target_file)) {
                // Delete old ttd if it exists
                if (!empty($school_profile['ttd_kepala']) && file_exists($target_dir . $school_profile['ttd_kepala'])) {
                    unlink($target_dir . $school_profile['ttd_kepala']);
                }
                $ttd_kepala = $new_ttd_name;
            } else {
                $message = ['type' => 'danger', 'text' => 'Gagal mengupload tanda tangan!'];
            }
        } else {
            $message = ['type' => 'danger', 'text' => 'Format file tanda tangan tidak didukung!'];
        }
    }
    
    if (empty($message)) {
        $stmt = $pdo->prepare("UPDATE tb_profil_madrasah SET nama_yayasan=?, nama_madrasah=?, kepala_madrasah=?, tahun_ajaran=?, semester=?, tanggal_jadwal=?, tempat_jadwal=?, logo=?, dashboard_hero_image=?, ttd_kepala=? WHERE id=1");
        if ($stmt->execute([$nama_yayasan, $nama_madrasah, $kepala_madrasah, $tahun_ajaran, $semester, $tanggal_jadwal, $tempat_jadwal, $logo, $hero_image, $ttd_kepala])) {
            $message = ['type' => 'success', 'text' => 'Profil madrasah berhasil diperbarui!'];
            // Refresh school profile
            $school_profile = getSchoolProfile($pdo);
        } else {
            $message = ['type' => 'danger', 'text' => 'Gagal memperbarui profil madrasah!'];
        }
    }
    }
}

// Get distinct academic years for deletion form
$years = [];
$stmts = [
    "SELECT DISTINCT tahun_ajaran FROM tb_nilai_harian_header",
    "SELECT DISTINCT tahun_ajaran FROM tb_nilai_kokurikuler_header",
    "SELECT DISTINCT tahun_ajaran FROM tb_nilai_semester"
];
foreach ($stmts as $sql) {
    try {
        $stmt = $pdo->query($sql);
        if ($stmt) {
            $res = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $years = array_merge($years, $res);
        }
    } catch (Exception $e) {}
}
$years = array_unique($years);
rsort($years); // Sort descending

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
                                            <div class="col-12">
                                                <div class="form-group">
                                                    <label>Nama Yayasan</label>
                                                    <input type="text" class="form-control" name="nama_yayasan" value="<?php echo htmlspecialchars($school_profile['nama_yayasan'] ?? 'YAYASAN PENDIDIKAN ISLAM'); ?>" placeholder="Contoh: YAYASAN PENDIDIKAN ISLAM">
                                                </div>
                                            </div>
                                        </div>

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

                                        <div class="row mt-3">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Tanggal Jadwal Pelajaran</label>
                                                    <input type="date" class="form-control" name="tanggal_jadwal" value="<?php echo htmlspecialchars($school_profile['tanggal_jadwal'] ?? ''); ?>">
                                                    <small class="text-muted">Tanggal yang akan muncul pada cetakan jadwal pelajaran.</small>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Tempat Jadwal</label>
                                                    <input type="text" class="form-control" name="tempat_jadwal" value="<?php echo htmlspecialchars($school_profile['tempat_jadwal'] ?? ''); ?>" placeholder="Contoh: Jakarta">
                                                    <small class="text-muted">Tempat yang akan muncul pada cetakan jadwal pelajaran.</small>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-12">
                                                <!-- Reset Data options removed from here -->
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

                                        <div class="row mt-3">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Tanda Tangan Kepala Madrasah</label>
                                                    <div class="mb-2">
                                                        <?php if (!empty($school_profile['ttd_kepala'])): ?>
                                                        <img src="../assets/img/<?php echo $school_profile['ttd_kepala']; ?>" alt="TTD Kepala" height="100" class="img-thumbnail" style="object-fit: contain;">
                                                        <?php else: ?>
                                                        <p class="text-muted">Tanda tangan belum diupload</p>
                                                        <?php endif; ?>
                                                    </div>
                                                    <input type="file" class="form-control" name="ttd_kepala">
                                                    <small class="text-muted">Format: JPG, PNG. Background transparan disarankan.</small>
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
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="card shadow border-left-danger">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-danger">Reset Data (Pergantian Tahun Ajaran)</h6>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-danger">
                                        <i class="fas fa-exclamation-triangle"></i> <b>PERHATIAN PENTING!</b><br>
                                        Fitur ini digunakan untuk <b>MENGHAPUS TOTAL</b> seluruh data untuk memulai Tahun Ajaran Baru. 
                                        Data yang dihapus <b>TIDAK DAPAT DIKEMBALIKAN</b>. Pastikan Anda sudah melakukan backup data jika diperlukan.
                                    </div>
                                    
                                    <form method="POST" onsubmit="return confirm('PERINGATAN KERAS: Anda akan menghapus SELURUH DATA yang dipilih. Tindakan ini TIDAK DAPAT DIBATALKAN. Apakah Anda yakin ingin melanjutkan?');">
                                        <input type="hidden" name="reset_annual_data" value="1">
                                        
                                        <div class="form-group">
                                            <div class="custom-control custom-checkbox mb-3">
                                                <input type="checkbox" class="custom-control-input" id="reset_data" name="reset_data" value="1">
                                                <label class="custom-control-label font-weight-bold text-danger" for="reset_data">Reset Total Data Kehadiran</label>
                                                <small class="d-block text-muted">Menghapus SELURUH data kehadiran Siswa dan Guru dari database (Truncate).</small>
                                            </div>
                                            
                                            <div class="custom-control custom-checkbox mb-3">
                                                <input type="checkbox" class="custom-control-input" id="reset_jurnal" name="reset_jurnal" value="1">
                                                <label class="custom-control-label font-weight-bold text-danger" for="reset_jurnal">Reset Total Jurnal Mengajar</label>
                                                <small class="d-block text-muted">Menghapus SELURUH data Jurnal Mengajar guru dari database (Truncate).</small>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <div class="custom-control custom-checkbox my-1 mr-sm-2">
                                                <input type="checkbox" class="custom-control-input" id="confirmReset" name="confirm_reset" value="1" required>
                                                <label class="custom-control-label" for="confirmReset">Saya sadar sepenuhnya bahwa data akan hilang permanen</label>
                                            </div>
                                            <button type="submit" class="btn btn-danger mt-2">
                                                <i class="fas fa-bomb"></i> Proses Reset Data Tahunan
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card card-danger">
                                <div class="card-header">
                                    <h4>Manajemen Data Nilai (Hapus Data Lama)</h4>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-light">
                                        <i class="fas fa-info-circle"></i> Fitur ini digunakan untuk menghapus data nilai lama yang sudah tidak diperlukan.
                                        Data yang dihapus <b>TIDAK DAPAT DIKEMBALIKAN</b>. Pastikan Anda memilih Tahun Ajaran dan Semester dengan benar.
                                    </div>
                                    <form method="POST" action="" onsubmit="return confirm('PERINGATAN: Apakah Anda yakin ingin menghapus data nilai yang dipilih? Tindakan ini tidak dapat dibatalkan!');">
                                        <input type="hidden" name="action" value="delete_grades">
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Tahun Ajaran</label>
                                                    <select class="form-control" name="del_tahun_ajaran" required>
                                                        <option value="">Pilih Tahun Ajaran</option>
                                                        <?php foreach ($years as $y): ?>
                                                            <?php if (!empty($y)): ?>
                                                            <option value="<?= htmlspecialchars($y) ?>"><?= htmlspecialchars($y) ?></option>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Semester</label>
                                                    <select class="form-control" name="del_semester" required>
                                                        <option value="">Pilih Semester</option>
                                                        <option value="Semester 1">Semester 1</option>
                                                        <option value="Semester 2">Semester 2</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="d-block font-weight-bold">Pilih Data yang Akan Dihapus:</label>
                                            <div class="custom-control custom-checkbox custom-control-inline">
                                                <input type="checkbox" class="custom-control-input" name="del_types[]" value="harian" id="del_harian">
                                                <label class="custom-control-label" for="del_harian">Nilai Harian</label>
                                            </div>
                                            <div class="custom-control custom-checkbox custom-control-inline">
                                                <input type="checkbox" class="custom-control-input" name="del_types[]" value="kokurikuler" id="del_kokurikuler">
                                                <label class="custom-control-label" for="del_kokurikuler">Nilai Kokurikuler</label>
                                            </div>
                                            <div class="custom-control custom-checkbox custom-control-inline">
                                                <input type="checkbox" class="custom-control-input" name="del_types[]" value="semester" id="del_semester">
                                                <label class="custom-control-label" for="del_semester">Nilai Semester (UTS, UAS, PAT, Pra Ujian)</label>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <button type="submit" class="btn btn-danger">Hapus Data Terpilih</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12">
                            <div class="card shadow border-left-warning">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-warning">Pembersihan Data (Maintenance)</h6>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-light">
                                        <i class="fas fa-exclamation-triangle"></i> Fitur ini digunakan untuk menghapus data lama (sebelum tanggal tertentu) yang sudah tidak diperlukan. Harap berhati-hati karena data yang dihapus tidak dapat dikembalikan.
                                    </div>
                                    
                                    <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus data ini? Tindakan ini tidak dapat dibatalkan!');">
                                        <input type="hidden" name="cleanup_type" value="bulk_cleanup">
                                        
                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Hapus Data Sebelum Tanggal</label>
                                            <div class="col-sm-4">
                                                <input type="date" name="cutoff_date" class="form-control" required>
                                                <small class="text-muted">Semua data sebelum tanggal ini akan dihapus.</small>
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <label class="col-sm-3 col-form-label">Pilih Data untuk Dihapus</label>
                                            <div class="col-sm-9">
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" class="custom-control-input" id="cleanupAttendanceStudent" name="cleanup_attendance_student" value="1" checked>
                                                    <label class="custom-control-label" for="cleanupAttendanceStudent">Absensi Siswa</label>
                                                </div>
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" class="custom-control-input" id="cleanupAttendanceTeacher" name="cleanup_attendance_teacher" value="1" checked>
                                                    <label class="custom-control-label" for="cleanupAttendanceTeacher">Absensi Guru</label>
                                                </div>
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" class="custom-control-input" id="cleanupJournals" name="cleanup_journals" value="1">
                                                    <label class="custom-control-label" for="cleanupJournals">Jurnal Mengajar</label>
                                                </div>
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" class="custom-control-input" id="cleanupLogs" name="cleanup_logs" value="1">
                                                    <label class="custom-control-label" for="cleanupLogs">Log Aktivitas</label>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="form-group row">
                                            <div class="col-sm-3"></div>
                                            <div class="col-sm-9">
                                                <div class="custom-control custom-checkbox my-1 mr-sm-2">
                                                    <input type="checkbox" class="custom-control-input" id="confirmCleanup" name="confirm_cleanup" value="1" required>
                                                    <label class="custom-control-label" for="confirmCleanup">Saya mengerti konsekuensinya dan ingin melanjutkan</label>
                                                </div>
                                                <button type="submit" name="cleanup_data" class="btn btn-warning mt-2">
                                                    <i class="fas fa-trash"></i> Hapus Data Terpilih
                                                </button>
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