<?php
ob_start();
require_once 'config/database.php';
require_once 'config/functions.php';

ensureStudentPasswords($pdo);
ensureGuruDefaultPasswords($pdo);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $login_identifier = trim(sanitizeInput($_POST['username'] ?? ''));
    $password = trim($_POST['password'] ?? '');
    
    $authenticated = false;
    $user_data = null;
    $user_type = '';

    // 1. Try Admin/Staff (tb_pengguna)
    $stmt = $pdo->prepare("SELECT * FROM tb_pengguna WHERE username = ?");
    $stmt->execute([$login_identifier]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && verifyPassword($password, $user['password'])) {
        $authenticated = true;
        $user_data = $user;
        $user_type = 'pengguna';
    }

    // 2. Try Guru (tb_guru) if not authenticated
    if (!$authenticated) {
        $stmt = $pdo->prepare("SELECT * FROM tb_guru WHERE TRIM(nuptk) = ?");
        $stmt->execute([$login_identifier]);
        $guru = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($guru) {
            $auth_guru = false;
            // A. Cek Password Hash
            if (!empty($guru['password']) && password_verify($password, $guru['password'])) {
                $auth_guru = true;
            }
            // B. Password default guru hanya untuk akun yang belum pernah diganti password.
            if (!$auth_guru && empty($guru['password']) && $password === DEFAULT_GURU_PASSWORD) {
                $auth_guru = true;
            }
            
            if ($auth_guru) {
                $authenticated = true;
                $user_data = $guru;
                $user_type = 'guru';
            }
        }
    }

    // 3. Try Student (tb_siswa) if not authenticated
    if (!$authenticated) {
        $stmt = $pdo->prepare("SELECT * FROM tb_siswa WHERE TRIM(nisn) = ? AND id_kelas IS NOT NULL");
        $stmt->execute([$login_identifier]);
        $siswa = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($siswa) {
            $auth_siswa = false;
            if (!empty($siswa['password']) && password_verify($password, $siswa['password'])) $auth_siswa = true;
            
            if ($auth_siswa) {
                $authenticated = true;
                $user_data = $siswa;
                $user_type = 'siswa';
            }
        }
    }

    if ($authenticated) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }

        // Normalisasi data untuk menghindari error null di PHP 8
        $user_data = $user_data ?? [];
        
        if ($user_type === 'pengguna') {
            $level = strtolower(trim((string)($user_data['level'] ?? '')));
            
            // Normalize level aliases
            if ($level === 'kepala') $level = 'kepala_madrasah';
            if ($level === 'tu') $level = 'tata_usaha';
            
            $_SESSION['user_id'] = $user_data['id_pengguna'] ?? 0;
            $_SESSION['username'] = $user_data['username'] ?? '';
            $_SESSION['level'] = $level;
            $_SESSION['login_source'] = 'tb_pengguna';
            $display_name = !empty($user_data['nama']) ? $user_data['nama'] : ($user_data['username'] ?? 'User');
            $_SESSION['login_success_msg'] = "Selamat datang, " . $display_name . "!";

            $redirect_url = '';
            switch ($level) {
                case 'admin': $redirect_url = app_url('admin/dashboard.php'); break;
                case 'guru': $redirect_url = app_url('guru/dashboard.php'); break;
                case 'wali': $redirect_url = app_url('wali/dashboard.php'); break;
                case 'kepala_madrasah': $redirect_url = app_url('kepala/dashboard.php'); break;
                case 'tata_usaha': $redirect_url = app_url('tata_usaha/dashboard.php'); break;
                default: $redirect_url = app_url('index.php'); break;
            }
            $show_swal = true;
            logActivity($pdo, (string)($_SESSION['username']), 'Login', 'User logged in successfully');
        } elseif ($user_type === 'guru') {
            $level = 'guru';
            $nama_guru = (string)($user_data['nama_guru'] ?? '');
            
            $wali_check = $pdo->prepare("SELECT COUNT(*) FROM tb_kelas WHERE TRIM(wali_kelas) = ?");
            $wali_check->execute([$nama_guru]);
            if ($wali_check->fetchColumn() > 0) $level = 'wali';
            
            $_SESSION['user_id'] = $user_data['id_guru'] ?? 0;
            $_SESSION['username'] = $user_data['nuptk'] ?? '';
            $_SESSION['level'] = $level;
            $_SESSION['nama_guru'] = $nama_guru;
            $_SESSION['login_source'] = 'tb_guru';
            $_SESSION['login_success_msg'] = "Selamat datang, " . $nama_guru . "!";
            
            $redirect_url = ($level === 'wali') ? app_url('wali/dashboard.php') : app_url('guru/dashboard.php');
            $show_swal = true;
            logActivity($pdo, (string)($_SESSION['username']), 'Login', 'Teacher logged in successfully');
        } elseif ($user_type === 'siswa') {
            $_SESSION['user_id'] = $user_data['id_siswa'] ?? 0;
            $_SESSION['username'] = $user_data['nisn'] ?? '';
            $_SESSION['level'] = 'siswa';
            $_SESSION['nama_siswa'] = $user_data['nama_siswa'] ?? '';
            $_SESSION['id_kelas'] = $user_data['id_kelas'] ?? 0;
            $_SESSION['login_source'] = 'tb_siswa';
            $_SESSION['login_success_msg'] = "Selamat datang, " . ($_SESSION['nama_siswa']) . "!";

            $redirect_url = app_url('siswa/dashboard.php');
            $show_swal = true;
            logActivity($pdo, (string)($_SESSION['username']), 'Login', 'Student logged in successfully');
        }
        
        // Pastikan sesi tersimpan permanen sebelum redireksi
        @session_write_close();
    } else {
        $login_error_title = 'Login Gagal';
        $login_error_html = '<div class="login-error-box login-error-box-danger">Username/NUPTK/NISN atau password tidak sesuai.</div><div class="login-error-box login-error-box-warning">Untuk siswa, akun hanya berlaku jika masih tercatat di data siswa dan memiliki kelas aktif.</div>';
    }
}

// Get school profile
$school_profile = getSchoolProfile($pdo);
$favicon_logo = !empty($school_profile['logo']) ? basename((string)$school_profile['logo']) : 'logo.png';
$favicon_path = __DIR__ . '/assets/img/' . $favicon_logo;
$favicon_version = is_readable($favicon_path) ? (string)filemtime($favicon_path) : '1';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, shrink-to-fit=no" name="viewport">
    <title>Login | Sistem Informasi Madrasah</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/img/<?php echo htmlspecialchars($favicon_logo, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo htmlspecialchars($favicon_version, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="apple-touch-icon" href="assets/img/<?php echo htmlspecialchars($favicon_logo, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo htmlspecialchars($favicon_version, ENT_QUOTES, 'UTF-8'); ?>">

    <!-- General CSS Files -->
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
<link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.7.2/css/all.css" integrity="sha384-fnmOCqbTlWIlj8LyTjo7mOUStjsKC4pOpQbqyi7RrhN7udi9RwhKkMHpvLbHG9Sr" crossorigin="anonymous">

    <!-- CSS Libraries -->

    <!-- Template CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/components.css">
    
    <!-- Custom Responsive CSS -->
    <style>
        /* Ensure all elements respect box-sizing */
        *, *::before, *::after {
            box-sizing: border-box;
        }

        /* Base responsive settings */
        html {
            font-size: 16px;
        }

        body {
            overflow-x: hidden;
        }

        /* Responsive images */
        img {
            max-width: 100%;
            height: auto;
        }

        /* Login page specific responsive */
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }

        .login-error-popup {
            width: 360px !important;
            max-width: calc(100vw - 32px) !important;
            padding: 1.2rem 1.35rem 1.35rem !important;
            border-radius: 8px !important;
        }

        .login-error-title {
            font-size: 16px !important;
            line-height: 1.35 !important;
            font-weight: 600 !important;
            margin: 0.45rem 0 0.35rem !important;
            letter-spacing: 0 !important;
        }

        .login-error-text {
            font-size: 14px !important;
            line-height: 1.45 !important;
            margin: 0 !important;
        }

        .login-error-box {
            padding: 0.55rem 0.7rem;
            border-radius: 6px;
            font-size: 14px;
            line-height: 1.45;
            text-align: center;
        }

        .login-error-box + .login-error-box {
            margin-top: 0.5rem;
        }

        .login-error-box-danger {
            color: #8f1d1d;
            background: #fff1f1;
            border: 1px solid #f5b8b8;
        }

        .login-error-box-warning {
            color: #795200;
            background: #fff8df;
            border: 1px solid #efd37a;
        }

        .login-error-button {
            font-size: 14px !important;
            padding: 0.45rem 0.9rem !important;
            border-radius: 6px !important;
        }

        .login-error-popup .swal2-icon {
            width: 52px !important;
            height: 52px !important;
            margin: 0.4rem auto 0.7rem !important;
        }

        .login-error-popup .swal2-icon .swal2-x-mark-line-left,
        .login-error-popup .swal2-icon .swal2-x-mark-line-right {
            top: 24px !important;
            width: 30px !important;
        }

        .login-error-popup .swal2-actions {
            margin-top: 1rem !important;
        }

        /* Mobile (<= 575px) */
        @media (max-width: 575.98px) {
            body {
                overflow-y: auto;
            }
            section {
                min-height: auto;
                padding: 1rem !important;
            }
            .card {
                margin: 1rem 0;
            }
            .card-header h5 {
                font-size: 1.1rem;
            }
            .card-header h6 {
                font-size: 0.9rem;
            }
        }

        /* Tablet (576px - 991px) */
        @media (min-width: 576px) and (max-width: 991.98px) {
            .card {
                margin: 2rem 0;
            }
        }

        /* Large Proyektor (>= 1200px) */
        @media (min-width: 1200px) {
            html {
                font-size: 18px;
            }
            .card {
                max-width: 100%;
            }
        }

        /* Extra Large Proyektor (>= 1400px) */
        @media (min-width: 1400px) {
            html {
                font-size: 20px;
            }
        }
    </style>
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body style="height: 100vh; margin: 0; overflow: hidden;">
    <div id="app">
        <section class="section d-flex align-items-center justify-content-center" style="min-height: 100vh; padding: 0;">
                <div class="col-12 col-sm-8 col-md-6 col-lg-4">
                    <div class="card shadow mt-3 mb-3">
                        <div class="card-header text-center bg-primary text-white pt-4 pb-4">
                            <div class="mx-auto text-center">
                                <img src="assets/img/<?php echo $school_profile['logo'] ?: 'logo.png'; ?>" alt="Logo Madrasah" width="80" height="80" class="mb-3 d-block mx-auto">
                                <h5 class="mb-2"><?php echo strtoupper($school_profile['nama_madrasah']); ?></h5>
                                <h6 class="mb-0">Sistem Informasi Madrasah</h6>
                            </div>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <div class="form-group">
                                    <label for="username">Username / NUPTK / NISN</label>
                                    <input id="username" type="text" class="form-control" name="username" placeholder="Admin: Username | Guru: NUPTK | Siswa: NISN" tabindex="1" required autofocus>
                                    <small class="form-text text-muted">Gunakan NISN (Siswa) atau NUPTK (Guru) untuk login</small>
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

    <?php if (isset($show_swal) && $show_swal): ?>
    <script>
        Swal.fire({
            title: 'Login Berhasil!',
            text: '<?php echo $_SESSION['login_success_msg']; ?>',
            icon: 'success',
            timer: 2000,
            timerProgressBar: true,
            showConfirmButton: false,
            allowOutsideClick: false
        }).then(() => {
            window.location.href = '<?php echo $redirect_url; ?>';
        });
    </script>
    <?php unset($_SESSION['login_success_msg']); ?>
    <?php endif; ?>

    <?php if (isset($login_error_title, $login_error_html)): ?>
    <script>
        Swal.fire({
            title: <?php echo json_encode($login_error_title); ?>,
            html: <?php echo json_encode($login_error_html); ?>,
            icon: 'error',
            confirmButtonText: 'Coba Lagi',
            confirmButtonColor: '#6777ef',
            customClass: {
                popup: 'login-error-popup',
                title: 'login-error-title',
                htmlContainer: 'login-error-text',
                confirmButton: 'login-error-button'
            }
        }).then(() => {
            const usernameInput = document.getElementById('username');
            if (usernameInput) {
                usernameInput.focus();
                usernameInput.select();
            }
        });
    </script>
    <?php endif; ?>

    <!-- JS Libraies -->
</body>
</html>
