<?php
// Unified User Header template for all dashboard types (admin, guru, wali)
if (!isset($_SESSION)) {
    session_start();
}

// Include functions and database connection
require_once '../config/database.php';
require_once '../config/functions.php';

// Get school profile
$school_profile = getSchoolProfile($pdo);

// Check if user is logged in
if (!isLoggedIn()) {
    redirect('../login.php');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, shrink-to-fit=no" name="viewport">
    <title><?php echo isset($page_title) ? $page_title : 'Dashboard'; ?> | Sistem Informasi Madrasah</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../assets/img/logo_1768301957.png">
    <link rel="apple-touch-icon" href="../assets/img/logo_1768301957.png">

    <!-- General CSS Files -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.7.2/css/all.css" integrity="sha384-fnmOCqbTlWIlj8LyTjo7mOUStjsKC4pOpQbqyi7RrhN7udi9RwhKkMHpvLbHG9Sr" crossorigin="anonymous">

    <!-- CSS Libraries -->
    <?php if (isset($css_libs) && is_array($css_libs)): ?>
        <?php foreach ($css_libs as $css): ?>
            <?php if (strpos($css, 'http://') === 0 || strpos($css, 'https://') === 0): ?>
                <link rel="stylesheet" href="<?php echo $css; ?>">
            <?php else: ?>
                <link rel="stylesheet" href="../<?php echo $css; ?>">
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Template CSS -->
    <?php
    $_style_path = __DIR__ . '/../assets/css/style.css';
    $_style_v = is_readable($_style_path) ? (string) filemtime($_style_path) : '1';
    $_components_path = __DIR__ . '/../assets/css/components.css';
    $_components_v = is_readable($_components_path) ? (string) filemtime($_components_path) : '1';
    ?>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo htmlspecialchars($_style_v, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="../assets/css/components.css?v=<?php echo htmlspecialchars($_components_v, ENT_QUOTES, 'UTF-8'); ?>">
    <!-- Modal Fix CSS -->
    <?php
    $_modal_fix_path = __DIR__ . '/../assets/css/modal_fix.css';
    $_modal_fix_v = is_readable($_modal_fix_path) ? (string) filemtime($_modal_fix_path) : '1';
    ?>
    <link rel="stylesheet" href="../assets/css/modal_fix.css?v=<?php echo htmlspecialchars($_modal_fix_v, ENT_QUOTES, 'UTF-8'); ?>">
    
    <!-- Additional CSS for this specific page -->
    <?php if (isset($css_page) && is_array($css_page)): ?>
        <?php foreach ($css_page as $css): ?>
            <style><?php echo $css; ?></style>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Custom Mobile Layout CSS -->
    <style>
        .mobile-header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 70px;
            z-index: 800;
            background: #ffffff;
            padding: 10px 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            border-bottom: 1px solid #f0f0f0;
        }
        .mobile-menu-label {
            font-size: 0.78rem;
            line-height: 1.2;
            text-align: center;
            display: block;
            width: 56px;
            max-width: 56px;
            margin: 2px auto 0;
            white-space: normal;
            word-break: normal;
        }
        .bottom-nav-label {
            font-size: 11px !important;
            line-height: 1.3 !important;
            text-align: center;
            display: block;
            margin-top: 0;
            max-width: 84px;
            margin-left: auto;
            margin-right: auto;
            white-space: normal;
            word-break: normal;
        }
        #table-siswa_wrapper {
            max-width: 100%;
            overflow-x: auto;
        }
        #table-siswa_wrapper .row {
            margin-left: 0;
            margin-right: 0;
        }
        #table-siswa_wrapper .col-sm-12,
        #table-siswa_wrapper .col-md-6 {
            padding-left: 0;
            padding-right: 0;
        }
        @media (max-width: 991.98px) {
            .main-navbar, .navbar-bg {
                display: none !important;
            }
            .main-content {
                padding-top: 120px !important;
            }
            .main-sidebar {
                display: none !important;
            }
        }
    </style>
</head>

<body>
    <div id="app">
        <div class="main-wrapper">
            <!-- Mobile Header -->
            <div class="mobile-header d-lg-none">
                <div class="d-flex align-items-center w-100">
                    <?php 
                    $current_page = basename($_SERVER['PHP_SELF']);
                    $is_dashboard = ($current_page === 'dashboard.php' || $current_page === 'simple_dashboard.php');
                    if (!$is_dashboard): 
                    ?>
                        <a href="javascript:history.back()" class="mr-3 text-dark d-flex align-items-center justify-content-center" style="width: 35px; height: 35px; background: #f8f9fa; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-decoration: none;">
                            <i class="fas fa-arrow-left"></i>
                        </a>
                    <?php endif; ?>
                    <img src="../assets/img/logo_1768301957.png" alt="logo" class="mr-2" style="height: 40px;">
                    <div style="line-height: 1.2;">
                        <h6 class="mb-0 text-success font-weight-bold" style="font-size: 1rem;">Sistem Informasi Madrasah</h6>
                        <small class="text-dark font-weight-bold" style="font-size: 0.8rem;"><?php echo isset($school_profile['nama_sekolah']) ? $school_profile['nama_sekolah'] : 'MI Sultan Fattah Sukosono'; ?></small>
                    </div>
                </div>
            </div>
            
            <div class="navbar-bg"></div>
            <nav class="navbar navbar-expand-lg main-navbar">
                <div class="mr-auto d-flex align-items-center"></div>
                <ul class="navbar-nav mr-auto">
                    <!-- Academic Year and Semester Info -->
                    <li class="nav-item d-flex align-items-center">
                        <div class="bg-primary text-white px-3 py-2 rounded small">
                            <span class="mr-2"><?php echo htmlspecialchars($school_profile['tahun_ajaran'] ?? '-'); ?></span>
                            <span class="mx-2">|</span>
                            <span><?php echo htmlspecialchars($school_profile['semester'] ?? '-'); ?></span>
                        </div>
                    </li>
                    <!-- Date and Time Info -->
                    <li class="nav-item d-flex align-items-center ml-2">
                        <div class="text-white small font-weight-bold">
                            <i class="far fa-calendar-alt mr-1"></i>
                            <span id="header-date-time"></span>
                        </div>
                        <script>
                            function updateHeaderDateTime() {
                                const now = new Date();
                                const dateOptions = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
                                const timeOptions = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false };
                                const dateStr = now.toLocaleDateString('id-ID', dateOptions);
                                const timeStr = now.toLocaleTimeString('id-ID', timeOptions).replace(/\./g, ':');
                                document.getElementById('header-date-time').textContent = `${dateStr} - ${timeStr}`;
                            }
                            setInterval(updateHeaderDateTime, 1000);
                            document.addEventListener('DOMContentLoaded', updateHeaderDateTime);
                        </script>
                    </li>
                </ul>
                <ul class="navbar-nav navbar-right">
                    <li class="dropdown">
                        <?php
                        // Get user data to display personalized avatar
                        $user_level = getUserLevel();
                        
                        if ($user_level === 'guru' || $user_level === 'wali') {
                            // For guru/wali, get teacher data to show teacher avatar
                            $current_user = null;
                            $display_name = '';
                            
                            // First, try to get by nama_guru from session (most reliable)
                            if (isset($_SESSION['nama_guru']) && !empty($_SESSION['nama_guru'])) {
                                $teacher_stmt = $pdo->prepare("SELECT * FROM tb_guru WHERE nama_guru = ?");
                                $teacher_stmt->execute([$_SESSION['nama_guru']]);
                                $current_user = $teacher_stmt->fetch(PDO::FETCH_ASSOC);
                                $display_name = $_SESSION['nama_guru'];
                            }
                            
                            // If not found, try to get by user_id (id_guru)
                            if (!$current_user && isset($_SESSION['user_id'])) {
                                $teacher_stmt = $pdo->prepare("SELECT * FROM tb_guru WHERE id_guru = ?");
                                $teacher_stmt->execute([$_SESSION['user_id']]);
                                $current_user = $teacher_stmt->fetch(PDO::FETCH_ASSOC);
                                if ($current_user) {
                                    $display_name = $current_user['nama_guru'];
                                }
                            }
                            
                            // If still not found, try by NUPTK (username might be NUPTK)
                            if (!$current_user && isset($_SESSION['username'])) {
                                $teacher_stmt = $pdo->prepare("SELECT * FROM tb_guru WHERE nuptk = ?");
                                $teacher_stmt->execute([$_SESSION['username']]);
                                $current_user = $teacher_stmt->fetch(PDO::FETCH_ASSOC);
                                if ($current_user) {
                                    $display_name = $current_user['nama_guru'];
                                }
                            }
                            
                            // Fallback: use nama_guru from session or username
                            if (empty($display_name)) {
                                $display_name = $_SESSION['nama_guru'] ?? $_SESSION['username'] ?? 'User';
                            }
                            
                            $avatar_html = getTeacherAvatarImage($current_user ?? ['nama_guru' => $display_name], 30);
                        } else {
                            // For admin, get user data
                            $user_stmt = $pdo->prepare("SELECT * FROM tb_pengguna WHERE username = ?");
                            $user_stmt->execute([$_SESSION['username']]);
                            $current_user = $user_stmt->fetch(PDO::FETCH_ASSOC);
                            
                            $avatar_html = getUserAvatarImage($current_user ?? ['username' => $_SESSION['username']], 30);
                            $display_name = ($current_user && !empty($current_user['nama'])) ? $current_user['nama'] : $_SESSION['username'];
                        }
                        ?>
                        <a href="#" data-toggle="dropdown" class="nav-link dropdown-toggle nav-link-lg nav-link-user">
                            <?php echo $avatar_html; ?>
                            <div class="d-sm-none d-lg-inline-block"><?php echo htmlspecialchars($display_name); ?></div>
                        </a>
                        <div class="dropdown-menu dropdown-menu-right">
                            <a href="#" onclick="confirmLogoutInline('../logout.php?level=<?php echo getUserLevel(); ?>'); return false;" class="dropdown-item has-icon text-danger">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </div>
                    </li>
                </ul>
            </nav>
            <?php include_once 'sidebar.php'; ?>
