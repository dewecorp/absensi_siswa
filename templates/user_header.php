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
$favicon_logo = !empty($school_profile['logo']) ? basename((string)$school_profile['logo']) : 'logo.png';
$favicon_path = __DIR__ . '/../assets/img/' . $favicon_logo;
$favicon_version = is_readable($favicon_path) ? (string)filemtime($favicon_path) : '1';

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
    <link rel="icon" type="image/png" href="../assets/img/<?php echo htmlspecialchars($favicon_logo, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo htmlspecialchars($favicon_version, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="apple-touch-icon" href="../assets/img/<?php echo htmlspecialchars($favicon_logo, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo htmlspecialchars($favicon_version, ENT_QUOTES, 'UTF-8'); ?>">

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

    <!-- Custom Responsive CSS -->
    <style>
        /* Ensure all elements respect box-sizing */
        *, *::before, *::after {
            box-sizing: border-box;
        }

        /* Base responsive settings */
        html {
            font-size: 16px;
            -webkit-text-size-adjust: 100%;
            text-size-adjust: 100%;
        }

        body {
            overflow-x: hidden;
        }

        /* Responsive images */
        img {
            max-width: 100%;
            height: auto;
        }

        /* Responsive containers */
        .container-fluid {
            padding-left: 15px;
            padding-right: 15px;
        }

/* Mobile Header */
        .mobile-header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 56px;
            z-index: 800;
            background: #ffffff;
            padding: 8px 16px;
            box-shadow: 0 3px 12px rgba(47, 110, 240, 0.25);
            display: flex;
            align-items: center;
            border-bottom: 1px solid #e3ebff;
        }
        .mobile-menu-label {
            display: block;
            font-size: 10.5px;
            line-height: 1.3;
            text-align: center;
            width: 100%;
            margin-left: auto;
            margin-right: auto;
            padding: 0 1px;
            white-space: normal;
            overflow: visible;
            word-break: normal;
            overflow-wrap: break-word;
            font-weight: 500;
            color: #334155;
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
        /* Chip tanggal & jam ala mobile app */
        .wb-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 11px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 600;
            line-height: 1.5;
            margin: 0 6px 4px 0;
        }
        .wb-chip i { font-size: 0.9em; }
        .wb-chip-default { background: #eef2f7; color: #334155; }
        .wb-chip-glass {
            background: rgba(255, 255, 255, .24);
            color: #fff;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .28);
        }
        /* Tombol status kehadiran: teks selalu satu baris agar konsisten */
        @media (max-width: 575.98px) {
            #attendanceFormReg .btn,
            #attendanceForm .btn {
                white-space: nowrap;
                padding-left: 2px !important;
                padding-right: 2px !important;
            }
            #attendanceFormReg .btn span,
            #attendanceForm .btn span {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                white-space: nowrap;
            }
            .selectgroup-button {
                white-space: nowrap;
            }
            .selectgroup-button i {
                margin-right: 4px;
            }
        }
        @media (max-width: 374.98px) {
            #attendanceFormReg .btn span,
            #attendanceForm .btn span {
                font-size: .72rem;
            }
        }
        /* Data identitas di hero: 2 kolom rapi saat mobile */
        @media (max-width: 767.98px) {
            .hero .row .col-auto {
                flex: 0 0 50%;
                max-width: 50%;
                padding-left: 8px;
                padding-right: 8px;
                margin-bottom: 14px;
            }
            .hero .row .col-auto > div:last-child {
                word-break: normal;
                overflow-wrap: break-word;
                font-size: .9rem;
                line-height: 1.35;
            }
            /* Angka panjang (NUPTK) tetap boleh pecah per huruf */
            .hero .row .col-auto:first-child > div:last-child {
                word-break: break-all;
            }
        }
        /* Dekorasi dashboard ala mobile app */
        @media (max-width: 991.98px) {
            .main-content { position: relative; }
            .main-content::before {
                content: '';
                position: absolute;
                top: -25px;
                right: -45px;
                width: 210px;
                height: 210px;
                border-radius: 50%;
                background:
                    radial-gradient(circle at 35% 35%, rgba(47, 110, 240, .30), rgba(47, 110, 240, 0) 66%),
                    radial-gradient(circle at 68% 62%, rgba(99, 102, 241, .20), rgba(99, 102, 241, 0) 58%);
                pointer-events: none;
                z-index: -1;
            }
            .main-content::after {
                content: '';
                position: absolute;
                top: 130px;
                left: -60px;
                width: 190px;
                height: 190px;
                border-radius: 50%;
                background:
                    radial-gradient(circle at 40% 38%, rgba(16, 185, 129, .26), rgba(16, 185, 129, 0) 64%),
                    radial-gradient(circle at 66% 70%, rgba(6, 182, 212, .16), rgba(6, 182, 212, 0) 56%);
                pointer-events: none;
                z-index: -1;
            }
        }

        /* Navbar atas mobile dihapus (tampilan mobile app) */
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

        /* Menu grid gaya mobile app */
        .menu-grid-icon {
            width: 62px;
            height: 62px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 8px;
            color: #fff;
            font-size: 30px;
            box-shadow: 0 6px 14px rgba(15, 23, 42, .16);
            transition: transform .15s ease, box-shadow .2s ease;
        }
        .menu-grid-icon i {
            font-size: inherit !important;
        }
        a:active .menu-grid-icon {
            transform: scale(.9);
        }
        .mg-c1 { background: linear-gradient(135deg, #6366f1, #8b5cf6); }
        .mg-c2 { background: linear-gradient(135deg, #0ea5e9, #2563eb); }
        .mg-c3 { background: linear-gradient(135deg, #10b981, #059669); }
        .mg-c4 { background: linear-gradient(135deg, #f59e0b, #ea580c); }
        .mg-c5 { background: linear-gradient(135deg, #ec4899, #db2777); }
        .mg-c6 { background: linear-gradient(135deg, #14b8a6, #0891b2); }
        .mg-c7 { background: linear-gradient(135deg, #f43f5e, #e11d48); }
        .mg-c8 { background: linear-gradient(135deg, #8b5cf6, #d946ef); }

        /* Responsive Breakpoints */

        /* Mobile (<= 575px) */
        @media (max-width: 575.98px) {
            .mobile-header {
                padding: 10px 15px;
            }
            .mobile-header-title {
                flex: 1 1 auto;
                min-width: 0;
            }
            .mobile-header-title .mobile-app-name,
            .mobile-header-title .mobile-school-name {
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                display: block;
            }
            .mobile-header-date {
                flex: 0 0 auto;
            }
            .main-content {
                padding: 15px !important;
                padding-top: 15px !important;
            }
            .card-body {
                padding: 1rem;
            }
            .btn {
                padding: 0.5rem 0.75rem;
                font-size: 0.9rem;
            }
            .table-responsive {
                font-size: 0.85rem;
            }
        }

/* Mobile to Tablet (576px - 767px) */
        @media (min-width: 576px) and (max-width: 767.98px) {
            .main-content {
                padding: 20px !important;
                padding-top: 20px !important;
            }
        }

        /* Tablet (<= 991px) */
        @media (max-width: 991.98px) {
            .main-sidebar {
                display: none !important;
            }
            .main-navbar, .navbar-bg {
                display: none !important;
            }
            .main-content {
                padding-top: 20px !important;
                padding-left: 20px !important;
                padding-right: 20px !important;
            }
            .main-content.has-mobile-app-header {
                padding-top: 56px !important;
            }
        }

        /* Desktop & Proyektor (>= 992px) */
        @media (min-width: 992px) {
            .mobile-header {
                display: none !important;
            }
            .main-sidebar {
                display: block !important;
            }
            .main-navbar, .navbar-bg {
                display: flex !important;
            }
            .main-content {
                padding-left: 280px !important;
                padding-top: 70px !important;
            }
        }

        /* Large Proyektor (>= 1200px) */
        @media (min-width: 1200px) {
            .container-fluid {
                padding-left: 30px;
                padding-right: 30px;
            }
            .card-body {
                padding: 1.5rem;
            }
            h1, .h1 { font-size: 2.5rem; }
            h2, .h2 { font-size: 2rem; }
            h3, .h3 { font-size: 1.75rem; }
            h4, .h4 { font-size: 1.5rem; }
        }

        /* Extra Large Proyektor (>= 1400px) */
        @media (min-width: 1400px) {
            html {
                font-size: 18px;
            }
            .container-fluid {
                padding-left: 40px;
                padding-right: 40px;
            }
        }

        /* Prevent text overflow on small screens */
        .text-truncate {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    </style>
</head>

<body class="<?php echo in_array(basename($_SERVER['PHP_SELF']), ['dashboard.php', 'simple_dashboard.php']) ? 'mobile-no-app-header' : 'mobile-has-app-header'; ?>">
    <div id="app">
        <div class="main-wrapper">

            <?php if (!in_array(basename($_SERVER['PHP_SELF']), ['dashboard.php', 'simple_dashboard.php'])): ?>
            <!-- Mobile App Page Header (mobile only) -->
            <div class="mobile-app-header d-md-none">
                <a href="javascript:history.back()" class="mobile-app-back" aria-label="Kembali">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div class="mobile-app-title"><?php echo htmlspecialchars(trim($page_title ?? '')); ?></div>
            </div>
            <?php endif; ?>
            
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
                                const timeOptions = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false };
                                const pad = (n) => String(n).padStart(2, '0');
                                const hariList = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
                                const bulanList = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
                                const dateStr = `${hariList[now.getDay()]}, ${now.getDate()} ${bulanList[now.getMonth()]} ${now.getFullYear()}`;
                                const timeStr = now.toLocaleTimeString('id-ID', timeOptions).replace(/\./g, ':');
                                const headerDt = document.getElementById('header-date-time');
                                if (headerDt) headerDt.textContent = `${dateStr} - ${timeStr}`;
                                const mobileDate = document.getElementById('wb-date');
                                if (mobileDate) mobileDate.textContent = dateStr;
                                const mobileTime = document.getElementById('wb-time');
                                if (mobileTime) mobileTime.textContent = timeStr;
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
            <?php include_once __DIR__ . '/sidebar.php'; ?>
