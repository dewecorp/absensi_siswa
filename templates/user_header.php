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
    <title><?php echo isset($page_title) ? $page_title : 'Dashboard'; ?> | Sistem Absensi Siswa</title>
    
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
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/components.css">
    <!-- Modal Fix CSS -->
    <link rel="stylesheet" href="../assets/css/modal_fix.css">
    
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
        @media (max-width: 991.98px) {
            .main-navbar, .navbar-bg {
                display: none !important;
            }
            .main-content {
                padding-top: 120px !important;
            }
        }
    </style>
</head>

<body>
    <div id="app">
        <div class="main-wrapper">
            <!-- Mobile Header -->
            <div class="mobile-header d-lg-none">
                <div class="d-flex align-items-center">
                    <img src="../assets/img/logo_1768301957.png" alt="logo" class="mr-3" style="height: 45px;">
                    <div style="line-height: 1.2;">
                        <h6 class="mb-0 text-success font-weight-bold" style="font-size: 1.1rem;">Sistem Informasi Madrasah</h6>
                        <small class="text-dark font-weight-bold" style="font-size: 0.85rem;"><?php echo isset($school_profile['nama_sekolah']) ? $school_profile['nama_sekolah'] : 'MI Sultan Fattah Sukosono'; ?></small>
                    </div>
                </div>
            </div>
            
            <div class="navbar-bg"></div>
            <nav class="navbar navbar-expand-lg main-navbar">
                <form class="form-inline mr-auto">
                    <ul class="navbar-nav mr-3">
                        <li><a href="#" data-toggle="sidebar" class="nav-link nav-link-lg"><i class="fas fa-bars"></i></a></li>
                    </ul>
                </form>
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
                            <div class="d-sm-none d-lg-inline-block">Hi, <?php echo htmlspecialchars($display_name); ?></div>
                        </a>
                        <div class="dropdown-menu dropdown-menu-right">
                            <?php
                            // Determine profile URL based on user level
                            $profile_url = '';
                            if ($user_level === 'guru') {
                                $profile_url = 'profil.php';
                            } elseif ($user_level === 'wali') {
                                $profile_url = 'profil.php';
                            } else {
                                $profile_url = 'features-profile.html';
                            }
                            ?>
                            <a href="<?php echo $profile_url; ?>" class="dropdown-item has-icon">
                                <i class="fas fa-cog"></i> Pengaturan
                            </a>
                            <div class="dropdown-divider"></div>
                            <a href="#" onclick="confirmLogoutInline('../logout.php?level=<?php echo getUserLevel(); ?>'); return false;" class="dropdown-item has-icon text-danger">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </div>
                    </li>
                </ul>
            </nav>
            <?php include 'sidebar.php'; ?>
