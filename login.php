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

    // ===== Proteksi brute force =====
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $brute_max = 5;          // maksimum percobaan gagal sebelum terkunci
    $brute_window = 900;     // jendela waktu 15 menit (detik)
    $brute_blocked = false;
    $brute_wait_sec = 0;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tb_login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            username VARCHAR(191) NULL,
            attempted_at DATETIME NOT NULL,
            INDEX idx_ip (ip_address, attempted_at),
            INDEX idx_user (username, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $stChk = $pdo->prepare("SELECT COUNT(*) FROM tb_login_attempts WHERE ip_address = ? AND attempted_at > (NOW() - INTERVAL $brute_window SECOND)");
        $stChk->execute([$client_ip]);
        $recent_fail = (int)$stChk->fetchColumn();
        if ($recent_fail >= $brute_max) {
            $brute_blocked = true;
            $stOld = $pdo->prepare("SELECT MIN(attempted_at) FROM tb_login_attempts WHERE ip_address = ? AND attempted_at > (NOW() - INTERVAL $brute_window SECOND)");
            $stOld->execute([$client_ip]);
            $oldest = $stOld->fetchColumn();
            if ($oldest) {
                $stDiff = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, ?, NOW())");
                $stDiff->execute([$oldest]);
                $elapsed = (int)$stDiff->fetchColumn();
                $brute_wait_sec = max(0, $brute_window - $elapsed);
            }
        }
    } catch (Exception $e) {
        // jika tabel gagal dibuat, lanjutkan tanpa lockout
    }

    $authenticated = false;
    $user_data = null;
    $user_type = '';

    // CSRF login non-intrusif: hanya menolak bila token dikirim tapi tidak cocok.
    // Jika token tidak ada (sesi baru / form lama), login tetap diproses agar tidak mengganggu.
    $csrf_submitted = isset($_POST['csrf_token']) && trim((string)$_POST['csrf_token']) !== '';
    $csrf_ok = !$csrf_submitted || appVerifyCsrfToken($_POST['csrf_token']);

    if (!$csrf_ok) {
        $login_error_title = 'Sesi Kadaluarsa';
        $login_error_html = '<div class="login-error-box login-error-box-danger">Token keamanan tidak valid. Silakan muat ulang halaman dan coba lagi.</div>';
    } else {
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
        // Reset riwayat percobaan gagal setelah login sukses
        try {
            $pdo->prepare("DELETE FROM tb_login_attempts WHERE ip_address = ? OR username = ?")->execute([$client_ip, $login_identifier]);
        } catch (Exception $e) {}
        $_SESSION['last_activity'] = time();

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
        // Catat percobaan gagal (proteksi brute force) + delay
        try {
            $pdo->prepare("INSERT INTO tb_login_attempts (ip_address, username, attempted_at) VALUES (?, ?, NOW())")->execute([$client_ip, $login_identifier]);
        } catch (Exception $e) {}
        usleep(250000); // 0.25 detik, memperlambat brute force

        if ($brute_blocked) {
            $wait_min = max(1, (int)ceil($brute_wait_sec / 60));
            $login_error_title = 'Terlalu Banyak Percobaan';
            $login_error_html = '<div class="login-error-box login-error-box-danger">Terlalu banyak percobaan login gagal dari perangkat ini. Akun sementara terkunci. Silakan coba lagi dalam sekitar ' . $wait_min . ' menit.</div>';
        } else {
            $login_error_title = 'Login Gagal';
            $login_error_html = '<div class="login-error-box login-error-box-danger">Username/NUPTK/NISN atau password tidak sesuai.</div><div class="login-error-box login-error-box-warning">Untuk siswa, akun hanya berlaku jika masih tercatat di data siswa dan memiliki kelas aktif.</div>';
        }
    }
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

        /* Sembunyikan toggle mata bawaan browser (Edge/IE) di input password */
        #password::-ms-reveal,
        #password::-ms-clear {
            display: none !important;
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

        /* ===== Mobile app style (<= 767px) ===== */
        @media (max-width: 767.98px) {
            body { background: linear-gradient(160deg, #2f6ef0 0%, #5a8ff7 55%, #7ea4f9 100%) !important; }
            .section { padding: 0 22px !important; align-items: center !important; min-height: 100vh; height: 100vh; height: 100dvh; }
            .col-12.col-sm-8 { padding: 0; }
            .card {
                background: transparent !important;
                border: 0 !important;
                box-shadow: none !important;
                margin: 0 !important;
            }
            .card-header {
                background: transparent !important;
                padding-top: 18px !important;
                padding-bottom: 6px !important;
            }
            .card-header img {
                width: 76px !important;
                height: 76px !important;
                margin-bottom: 10px !important;
                filter: drop-shadow(0 8px 18px rgba(0,0,0,.25));
            }
            .card-header h5 { font-size: 1.15rem; letter-spacing: .3px; }
            .card-header h6 { opacity: .85; font-weight: 400; }
            .card-body { padding: 18px 0 0 !important; }
            .card-body label { color: rgba(255,255,255,.9) !important; font-size: .85rem; font-weight: 600; }
            .form-control {
                background: #ffffff !important;
                border: 0 !important;
                border-radius: 14px !important;
                height: 48px;
                box-shadow: 0 6px 16px rgba(13, 40, 106, .18) !important;
                font-size: .95rem;
            }
            .form-text { color: rgba(255,255,255,.75) !important; font-size: .75rem !important; }
            #loginForm button[type="submit"] {
                border-radius: 999px !important;
                height: 50px;
                font-weight: 700;
                letter-spacing: .4px;
                background: #ffffff !important;
                color: #2f6ef0 !important;
                border: 0 !important;
                box-shadow: 0 10px 24px rgba(13, 40, 106, .30) !important;
            }
            #togglePassword { color: #2f6ef0 !important; }
            .simple-footer { color: rgba(255,255,255,.8) !important; margin-top: 14px !important; }
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
                            <form method="POST" action="" id="loginForm">
                                <input type="hidden" name="csrf_token" value="<?php echo appCsrfToken(); ?>">
                                <div class="form-group">
                                    <label for="username">Username / NUPTK / NISN</label>
                                    <input id="username" type="text" class="form-control" name="username" placeholder="Admin: Username | Guru: NUPTK | Siswa: NISN" tabindex="1" required autofocus>
                                    <small class="form-text text-muted">Gunakan NISN (Siswa) atau NUPTK (Guru) untuk login</small>
                                </div>

                                <div class="form-group">
                                    <div class="d-block">
                                        <label for="password" class="control-label">Password</label>
                                    </div>
                                    <div class="position-relative">
                                        <input id="password" type="password" class="form-control" name="password" tabindex="2" required style="padding-right: 42px;">
                                        <button type="button" id="togglePassword" tabindex="3" title="Lihat / Sembunyikan password" aria-label="Lihat / Sembunyikan password" style="display: none; position: absolute; top: 50%; right: 4px; transform: translateY(-50%); border: none; outline: none; box-shadow: none; background: transparent; color: #6c757d; padding: 6px 10px; cursor: pointer; z-index: 2; line-height: 0;">
                                            <svg id="togglePasswordIcon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                                <circle cx="12" cy="12" r="3"></circle>
                                            </svg>
                                        </button>
                                    </div>
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

    <!-- Overlay loading saat memproses login -->
    <div id="loginLoading" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:99999;background:rgba(255,255,255,.55);backdrop-filter:blur(2px);">
        <div class="login-loader">
            <img src="assets/img/<?php echo htmlspecialchars($school_profile['logo'] ?: 'logo.png', ENT_QUOTES, 'UTF-8'); ?>" alt="Logo" class="login-loader-logo">
            <span class="login-loader-ring"></span>
        </div>
        <div class="login-loader-text">Memproses login...</div>
    </div>
    <style>
    .login-loader {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -58%);
    }
    .login-loader-logo {
        display: block;
        width: 86px;
        height: 86px;
        object-fit: contain;
    }
    .login-loader-ring {
        position: absolute;
        top: -10px;
        left: -10px;
        width: calc(100% + 20px);
        height: calc(100% + 20px);
        border-radius: 50%;
        border: 5px solid rgba(47, 110, 240, .15);
        border-top-color: #2f6ef0;
        animation: ringSpin .8s linear infinite;
    }
    .login-loader-text {
        position: absolute;
        top: calc(100% + 26px);
        left: 50%;
        transform: translateX(-50%);
        text-align: center;
        font-size: .95rem;
        font-weight: 600;
        color: #475569;
        white-space: nowrap;
    }
    @keyframes ringSpin { to { transform: rotate(360deg); } }
    @media (max-width: 575.98px) {
        .login-loader-logo { width: 68px; height: 68px; }
        .login-loader-ring { top: -8px; left: -8px; width: calc(100% + 16px); height: calc(100% + 16px); border-width: 4px; }
        .login-loader-text { font-size: .88rem; }
    }
    </style>
    <script>
    (function() {
        var form = document.getElementById('loginForm');
        if (!form) return;
        form.addEventListener('submit', function(e) {
            if (form.dataset.submitting === '1') return;
            e.preventDefault();
            var overlay = document.getElementById('loginLoading');
            var btn = form.querySelector('button[type="submit"]');
            if (overlay) overlay.style.display = 'block';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1"></span> Memproses...';
            }
            // Tahan overlay minimal 1.5 detik sebelum kirim
            setTimeout(function() {
                form.dataset.submitting = '1';
                form.submit();
            }, 1500);
        });
    })();
    </script>

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
        // Alert sukses hanya di desktop; mobile langsung masuk dashboard
        if (window.matchMedia('(min-width: 992px)').matches) {
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
        } else {
            window.location.href = '<?php echo $redirect_url; ?>';
        }
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
<script>
    // Toggle lihat/sembunyikan password
    window.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('togglePassword');
        if (!btn) return;
        var p = document.getElementById('password');
        function syncBtn() {
            btn.style.display = p.value.length > 0 ? '' : 'none';
        }
        ['input', 'keyup', 'change', 'paste'].forEach(function (ev) {
            p.addEventListener(ev, syncBtn);
        });
        syncBtn();
        setInterval(syncBtn, 500);
        btn.addEventListener('click', function () {
            var svg = document.getElementById('togglePasswordIcon');
            if (p.type === 'password') {
                p.type = 'text';
                svg.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
            } else {
                p.type = 'password';
                svg.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
            }
        });
    });
    </script>
</body>
</html>
