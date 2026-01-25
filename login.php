<?php
require_once 'config/database.php';
require_once 'config/functions.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $login_identifier = sanitizeInput($_POST['username']); // This can be username, NUPTK, or other identifier
    $password = $_POST['password'];
    
    // First, try to find the user in tb_pengguna table
    $stmt = $pdo->prepare("SELECT * FROM tb_pengguna WHERE username = ?");
    $stmt->execute([$login_identifier]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If not found in tb_pengguna, try to find in tb_guru using NUPTK
    if (!$user) {
        // Try Guru first
        $stmt = $pdo->prepare("SELECT *, 'guru' as level FROM tb_guru WHERE nuptk = ?");
        $stmt->execute([$login_identifier]);
        $guru_user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($guru_user && $guru_user['password'] && password_verify($password, $guru_user['password'])) {
            // Check if this guru is also a homeroom teacher
            $level = 'guru';
            
            // Check if this teacher is assigned as wali_kelas in tb_kelas table
            $wali_check = $pdo->prepare("SELECT COUNT(*) FROM tb_kelas WHERE wali_kelas = ?");
            $wali_check->execute([$guru_user['nama_guru']]);
            $is_wali_kelas = $wali_check->fetchColumn() > 0;
            
            if ($is_wali_kelas) {
                $level = 'wali';
            }
            
            // Set session variables
            $_SESSION['user_id'] = $guru_user['id_guru'];
            $_SESSION['username'] = $guru_user['nuptk'];
            $_SESSION['level'] = $level;
            $_SESSION['nama_guru'] = $guru_user['nama_guru'];
            
            // Log login activity
            $username = isset($guru_user['nuptk']) ? $guru_user['nuptk'] : 'system';
            $log_result = logActivity($pdo, $username, 'Login', 'Teacher logged in successfully using NUPTK');
            if (!$log_result) error_log("Failed to log activity for Login: Teacher");
            
            // Redirect based on level
            switch ($level) {
                case 'wali':
                    redirect('wali/dashboard.php');
                    break;
                case 'guru':
                    redirect('guru/dashboard.php');
                    break;
                default:
                    $error = "Invalid user level";
            }
        } else {
            // If not found in tb_guru, try tb_siswa using NISN
            $stmt = $pdo->prepare("SELECT *, 'siswa' as level FROM tb_siswa WHERE nisn = ?");
            $stmt->execute([$login_identifier]);
            $siswa_user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($siswa_user && $siswa_user['password'] && password_verify($password, $siswa_user['password'])) {
                // Set session variables
                $_SESSION['user_id'] = $siswa_user['id_siswa'];
                $_SESSION['username'] = $siswa_user['nisn'];
                $_SESSION['level'] = 'siswa';
                $_SESSION['nama_siswa'] = $siswa_user['nama_siswa'];
                $_SESSION['id_kelas'] = $siswa_user['id_kelas'];

                // Log login activity
                $username = isset($siswa_user['nisn']) ? $siswa_user['nisn'] : 'system';
                // logActivity might fail if user is not in expected format, but let's try
                // Actually logActivity takes username, action, description.
                if (function_exists('logActivity')) {
                    logActivity($pdo, $username, 'Login', 'Student logged in successfully using NISN');
                }

                redirect('siswa/dashboard.php');
            } else {
                $error = "Username/NUPTK/NISN atau password salah!";
            }
        }
    } else {
        // User found in tb_pengguna, verify password
        if ($user && verifyPassword($password, $user['password'])) {
            // Set session variables
            $_SESSION['user_id'] = $user['id_pengguna'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['level'] = $user['level'];
            $_SESSION['login_source'] = 'tb_pengguna';
            
            // Prevent Session Fixation
            session_regenerate_id(true);

            // Log login activity
            $username = isset($user['username']) ? $user['username'] : 'system';
            $log_result = logActivity($pdo, $username, 'Login', 'User logged in successfully');
            if (!$log_result) error_log("Failed to log activity for Login: User");
            
            // Redirect based on user level
            switch ($user['level']) {
                case 'admin':
                    redirect('admin/dashboard.php');
                    break;
                case 'guru':
                    redirect('guru/dashboard.php');
                    break;
                case 'wali':
                    redirect('wali/dashboard.php');
                    break;
                case 'kepala_madrasah':
                    redirect('kepala/dashboard.php');
                    break;
                case 'tata_usaha':
                    redirect('tata_usaha/dashboard.php');
                    break;
                default:
                    $error = "Invalid user level";
            }
        } else {
            $error = "Username atau password salah!";
        }
    }
}

// Get school profile
$school_profile = getSchoolProfile($pdo);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, shrink-to-fit=no" name="viewport">
    <title>Login | Sistem Absensi Siswa</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <link rel="apple-touch-icon" href="assets/img/favicon.svg">

    <!-- General CSS Files -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.7.2/css/all.css" integrity="sha384-fnmOCqbTlWIlj8LyTjo7mOUStjsKC4pOpQbqyi7RrhN7udi9RwhKkMHpvLbHG9Sr" crossorigin="anonymous">

    <!-- CSS Libraries -->

    <!-- Template CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/components.css">
    

</head>

<body style="height: 100vh; margin: 0;">
    <div id="app">
        <section class="section d-flex align-items-center justify-content-center" style="min-height: 100vh; padding: 2rem 0;">
                <div class="col-12 col-sm-8 col-md-6 col-lg-4">
                    <div class="card shadow mt-3 mb-3">
                        <div class="card-header text-center bg-primary text-white pt-4 pb-4">
                            <div class="mx-auto text-center">
                                <img src="assets/img/<?php echo $school_profile['logo'] ?: 'logo.png'; ?>" alt="Logo Madrasah" width="80" height="80" class="mb-3 d-block mx-auto">
                                <h5 class="mb-2"><?php echo $school_profile['nama_madrasah']; ?></h5>
                                <h6 class="mb-0">Sistem Absensi Siswa</h6>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (isset($error)): ?>
                                <div class="alert alert-danger alert-dismissible show fade text-left">
                                    <div class="alert-body">
                                        <button class="close" data-dismiss="alert">
                                            <span>&times;</span>
                                        </button>
                                        <?php echo $error; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <form method="POST" action="">
                                <div class="form-group">
                                    <label for="username">Username atau NUPTK</label>
                                    <input id="username" type="text" class="form-control" name="username" placeholder="Admin: Username | Guru/Wali: NUPTK" tabindex="1" required autofocus>
                                    <small class="form-text text-muted">Guru dan Wali Kelas menggunakan NUPTK untuk login</small>
                                </div>

                                <div class="form-group">
                                    <div class="d-block">
                                        <label for="password" class="control-label">Password</label>
                                    </div>
                                    <input id="password" type="password" class="form-control" name="password" tabindex="2" required>
                                </div>

                                <div class="form-group">
                                    <button type="submit" class="btn btn-primary btn-lg btn-block" tabindex="4">
                                        <i class="fas fa-sign-in-alt"></i> Login
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="simple-footer text-center mt-4 mb-2">
                        <small>Copyright &copy; <?php echo date('Y'); ?> <?php echo $school_profile['nama_madrasah']; ?></small>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- General JS Scripts -->
    <script src="https://code.jquery.com/jquery-3.3.1.min.js" integrity="sha256-FgpCb/KJQlLNfOu91ta32o/NMZxltwRo8QtmkMRdAu8=" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.24.0/moment.min.js"></script>
    <script src="assets/js/stisla.js"></script>

    <!-- JS Libraies -->

    <!-- Template JS File -->
    <script src="assets/js/scripts.js"></script>
    <script src="assets/js/custom.js"></script>

    <!-- JS Libraies -->
</body>
</html>