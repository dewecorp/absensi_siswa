<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has wali level
if (!isAuthorized(['wali'])) {
    redirect('../login.php');
}

// Get school profile
$school_profile = getSchoolProfile($pdo);

// Get teacher information
if ($_SESSION['level'] == 'guru' || $_SESSION['level'] == 'wali') {
    // Direct login via NUPTK, user_id is actually the id_guru
    $stmt = $pdo->prepare("SELECT * FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    // Traditional login via tb_pengguna
    $stmt = $pdo->prepare("SELECT g.* FROM tb_guru g JOIN tb_pengguna p ON g.id_guru = p.id_guru WHERE p.id_pengguna = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$teacher) {
    redirect('../login.php');
}

// Ensure nama_guru is set in session for consistent navbar display
if (!isset($_SESSION['nama_guru']) || empty($_SESSION['nama_guru'])) {
    $_SESSION['nama_guru'] = $teacher['nama_guru'];
}

$message = null;

// Handle password change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ubah_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validate
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $message = ['type' => 'warning', 'text' => 'Harap lengkapi semua field!'];
    } elseif ($new_password !== $confirm_password) {
        $message = ['type' => 'warning', 'text' => 'Password baru dan konfirmasi password tidak sama!'];
    } elseif (strlen($new_password) < 6) {
        $message = ['type' => 'warning', 'text' => 'Password baru minimal 6 karakter!'];
    } else {
        // Verify current password
        if ($teacher['password'] && password_verify($current_password, $teacher['password'])) {
            // Update password
            $hashed_password = hashPassword($new_password);
            $stmt = $pdo->prepare("UPDATE tb_guru SET password = ?, password_plain = ? WHERE id_guru = ?");
            if ($stmt->execute([$hashed_password, $new_password, $teacher['id_guru']])) {
                $message = ['type' => 'success', 'text' => 'Password berhasil diubah!'];
                logActivity($pdo, $teacher['nuptk'], 'Ubah Password', 'Wali mengubah password sendiri');
                // Refresh teacher data
                $stmt = $pdo->prepare("SELECT * FROM tb_guru WHERE id_guru = ?");
                $stmt->execute([$teacher['id_guru']]);
                $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $message = ['type' => 'danger', 'text' => 'Gagal mengubah password!'];
            }
        } else {
            $message = ['type' => 'danger', 'text' => 'Password lama tidak benar!'];
        }
    }
}

// Set page title
$page_title = 'Profil & Pengaturan';

// Include header
include '../templates/user_header.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Profil & Pengaturan</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Profil & Pengaturan</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12 col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h4>Informasi Profil</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label>Nama Guru</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($teacher['nama_guru']); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label>NUPTK</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($teacher['nuptk']); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label>Jenis Kelamin</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($teacher['jenis_kelamin']); ?>" readonly>
                            </div>
                            <?php if (!empty($teacher['tempat_lahir']) || !empty($teacher['tanggal_lahir'])): ?>
                            <div class="form-group">
                                <label>Tempat, Tanggal Lahir</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($teacher['tempat_lahir']); ?><?php echo !empty($teacher['tempat_lahir']) && !empty($teacher['tanggal_lahir']) ? ', ' : ''; ?><?php echo $teacher['tanggal_lahir'] ? date('d-m-Y', strtotime($teacher['tanggal_lahir'])) : ''; ?>" readonly>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-12 col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h4>Ubah Password</h4>
                        </div>
                        <div class="card-body">
                            <?php if ($message): ?>
                            <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible show fade">
                                <div class="alert-body">
                                    <button class="close" data-dismiss="alert">
                                        <span>&times;</span>
                                    </button>
                                    <?php echo $message['text']; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <form method="POST" action="">
                                <div class="form-group">
                                    <label>Password Lama</label>
                                    <input type="password" class="form-control" name="current_password" required>
                                </div>
                                
                                <div class="form-group">
                                    <label>Password Baru</label>
                                    <input type="password" class="form-control" name="new_password" minlength="6" required>
                                    <small class="form-text text-muted">Minimal 6 karakter</small>
                                </div>
                                
                                <div class="form-group">
                                    <label>Konfirmasi Password Baru</label>
                                    <input type="password" class="form-control" name="confirm_password" minlength="6" required>
                                </div>
                                
                                <div class="form-group">
                                    <button type="submit" name="ubah_password" class="btn btn-primary">
                                        <i class="fas fa-key"></i> Ubah Password
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php
// Add JavaScript for SweetAlert
$js_page = [];
if ($message) {
    $js_page[] = "
    $(document).ready(function() {
        Swal.fire({
            title: '" . ($message['type'] === 'success' ? 'Berhasil!' : 'Perhatian!') . "',
            text: '" . addslashes($message['text']) . "',
            icon: '" . ($message['type'] === 'success' ? 'success' : ($message['type'] === 'danger' ? 'error' : 'warning')) . "',
            timer: " . ($message['type'] === 'success' ? '3000' : '5000') . ",
            timerProgressBar: true,
            showConfirmButton: false
        });
    });
    ";
}

include '../templates/user_footer.php';
?>
