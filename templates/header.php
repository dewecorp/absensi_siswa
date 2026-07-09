<?php
// Header template for the attendance system
if (!isset($_SESSION)) {
    session_start();
}

// Include functions and database connection
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) && !isset($_SESSION['username'])) {
    // If not logged in, redirect to login page (optional, depending on page logic)
    // redirect('../login.php'); 
}

// Get user level
$user_level = function_exists('getUserLevel') ? getUserLevel() : null;

// Get school profile
$school_profile = getSchoolProfile($pdo);
$favicon_logo = !empty($school_profile['logo']) ? basename((string)$school_profile['logo']) : 'logo.png';
$favicon_path = __DIR__ . '/../assets/img/' . $favicon_logo;
$favicon_version = is_readable($favicon_path) ? (string)filemtime($favicon_path) : '1';

// Check if user is logged in
// TEMPORARY: Bypass authentication for testing
/*
if (!isLoggedIn()) {
    redirect('../login.php');
}
*/

// Get current page title
$page_title = isset($page_title) ? $page_title : 'Dashboard';

// Pre-fetch notifications if user is admin or kepala
$unread_notifs = [];
$unread_count = 0;
$unread_count_label = '0';
if (getUserLevel() === 'admin' || getUserLevel() === 'kepala_madrasah') {
    $unread_notifs = getUnreadNotifications($pdo);
    foreach($unread_notifs as $n) {
        if(!$n['is_read']) $unread_count++;
    }
    $unread_count_label = $unread_count > 99 ? '99+' : (string)$unread_count;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, shrink-to-fit=no" name="viewport">
    <title><?php echo $page_title; ?> | Sistem Informasi Madrasah</title>
    
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

    <!-- Dynamic breadcrumb rendering will be handled in the page content -->
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
        .notif-count-badge {
            position: absolute;
            top: -6px;
            right: -8px;
            min-width: 18px;
            height: 18px;
            padding: 0 4px;
            border-radius: 999px;
            border: 2px solid #fff;
            background: #fc544b;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            line-height: 14px;
            text-align: center;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            z-index: 2;
            box-shadow: 0 1px 3px rgba(0,0,0,.25);
        }

        /* Responsive Breakpoints */

        /* Mobile (<= 575px) */
        @media (max-width: 575.98px) {
            .mobile-header {
                padding: 10px 15px;
            }
            .main-content {
                padding: 15px !important;
                padding-top: 90px !important;
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
                padding-top: 100px !important;
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
                padding-top: 100px !important;
                padding-left: 20px !important;
                padding-right: 20px !important;
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

<body>
    <div id="app">
        <div class="main-wrapper">
            <!-- Mobile Header -->
            <div class="mobile-header d-md-none">
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
                    <img src="../assets/img/<?php echo htmlspecialchars($favicon_logo, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo htmlspecialchars($favicon_version, ENT_QUOTES, 'UTF-8'); ?>" alt="logo" class="mr-2" style="height: 40px;">
                    <div style="line-height: 1.2;">
                        <h6 class="mb-0 text-success font-weight-bold" style="font-size: 1rem;">Sistem Informasi Madrasah</h6>
                        <small class="text-dark font-weight-bold" style="font-size: 0.8rem;"><?php echo isset($school_profile['nama_sekolah']) ? $school_profile['nama_sekolah'] : 'MI Sultan Fattah Sukosono'; ?></small>
                    </div>
                </div>
            </div>
            
            <div class="navbar-bg"></div>
            <nav class="navbar navbar-expand-lg main-navbar">
                <div class="mr-auto"></div>
                <ul class="navbar-nav mr-auto">
                    <!-- Academic Year and Semester Info -->
                    <li class="nav-item d-none d-lg-flex align-items-center">
                        <div class="text-white small font-weight-bold">
                            <span class="mr-2"><?php echo htmlspecialchars($school_profile['tahun_ajaran'] ?? '-'); ?></span>
                            <span class="mx-2">|</span>
                            <span><?php echo htmlspecialchars($school_profile['semester'] ?? '-'); ?></span>
                        </div>
                    </li>
                    <!-- Date and Time Info -->
                    <li class="nav-item d-none d-lg-flex align-items-center ml-2">
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
                    
                    <?php if (getUserLevel() === 'admin' || getUserLevel() === 'kepala_madrasah'): ?>
                    <li class="dropdown dropdown-list-toggle d-none d-lg-block">
                        <a href="#" data-toggle="dropdown" class="nav-link nav-link-lg notification-toggle <?php echo $unread_count > 0 ? 'beep' : ''; ?>">
                            <i class="far fa-bell"></i>
                            <?php if ($unread_count > 0): ?>
                                <span class="notif-count-badge" data-count="<?php echo (int)$unread_count; ?>"><?php echo htmlspecialchars($unread_count_label, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </a>
                        <div class="dropdown-menu dropdown-list dropdown-menu-right">
                            <div class="dropdown-header">Notifikasi
                                <div class="float-right">
                                    <a href="#" id="mark-all-read">Tandai semua dibaca</a>
                                </div>
                            </div>
                            <div class="dropdown-list-content dropdown-list-icons navbar-notifikasi-scroll" style="height: 300px; overflow-y: auto;">
                                <?php if (count($unread_notifs) > 0): ?>
                                    <?php foreach ($unread_notifs as $notif): ?>
                                        <?php
                                            $notif_link = $notif['link'];
                                            if (getUserLevel() === 'kepala_madrasah') {
                                                if ($notif_link === 'absensi_guru.php') {
                                                    $notif_link = 'rekap_absensi_guru.php';
                                                }
                                            }
                                        ?>
                                        <a href="#" onclick="readNotification(<?php echo $notif['id']; ?>, '<?php echo $notif_link; ?>', this); return false;" class="dropdown-item dropdown-item-unread" style="<?php echo $notif['is_read'] ? '' : 'font-weight: bold; background-color: #f9f9f9;'; ?>">
                                            <div class="dropdown-item-icon bg-primary text-white">
                                                <i class="fas fa-info"></i>
                                            </div>
                                            <div class="dropdown-item-desc">
                                                <span style="<?php echo $notif['is_read'] ? '' : 'font-weight: bold; color: #333;'; ?>">
                                                    <?php echo htmlspecialchars($notif['message']); ?>
                                                </span>
                                                <div class="time text-primary"><?php echo timeAgo($notif['created_at']); ?></div>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="p-3 text-center text-muted">
                                        Tidak ada notifikasi baru
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </li>
                    <?php endif; ?>

                    <li class="dropdown d-none d-lg-block">
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
                        } elseif ($user_level === 'siswa') {
                            // Siswa logic
                            $display_name = $_SESSION['nama_siswa'] ?? $_SESSION['username'] ?? 'Siswa';
                            // Use generic avatar or student avatar if available
                            $avatar_html = '<img alt="image" src="../assets/img/avatar/avatar-1.png" class="rounded-circle mr-1">'; 
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
                            <div class="d-none d-lg-inline-block"><?php echo htmlspecialchars($display_name); ?></div>
                        </a>
                        <div class="dropdown-menu dropdown-menu-right">
                            <?php if (getUserLevel() === 'admin'): ?>
                            <a href="#" id="btn-toggle-self-update" class="dropdown-item has-icon <?php echo APP_SELF_UPDATE_ENABLED ? 'text-warning' : 'text-muted'; ?>" data-enabled="<?php echo APP_SELF_UPDATE_ENABLED ? '1' : '0'; ?>">
                                <i class="fas <?php echo APP_SELF_UPDATE_ENABLED ? 'fa-toggle-on' : 'fa-toggle-off'; ?>"></i> <?php echo APP_SELF_UPDATE_ENABLED ? 'Nonaktifkan Update Sistem' : 'Aktifkan Update Sistem'; ?>
                            </a>
                            <a href="#" id="btn-update-github" class="dropdown-item has-icon text-primary">
                                <i class="fas fa-sync-alt"></i> Update Sistem
                            </a>
                            <div class="dropdown-divider"></div>
                            <?php endif; ?>
                            <a href="#" onclick="confirmLogout('../logout.php?level=<?php echo getUserLevel(); ?>'); return false;" class="dropdown-item has-icon text-danger">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </div>
                    </li>
                </ul>
            </nav>

            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const updateCsrfToken = <?php echo json_encode(appCsrfToken()); ?>;
                const btnToggleUpdate = document.getElementById('btn-toggle-self-update');
                const btnUpdate = document.getElementById('btn-update-github');
                let selfUpdateEnabled = btnToggleUpdate ? btnToggleUpdate.getAttribute('data-enabled') === '1' : false;

                function refreshUpdateToggle(enabled) {
                    selfUpdateEnabled = enabled;
                    if (!btnToggleUpdate) return;
                    btnToggleUpdate.setAttribute('data-enabled', enabled ? '1' : '0');
                    btnToggleUpdate.classList.toggle('text-warning', enabled);
                    btnToggleUpdate.classList.toggle('text-muted', !enabled);
                    btnToggleUpdate.innerHTML = enabled
                        ? '<i class="fas fa-toggle-on"></i> Nonaktifkan Update Sistem'
                        : '<i class="fas fa-toggle-off"></i> Aktifkan Update Sistem';
                }

                if (btnToggleUpdate) {
                    btnToggleUpdate.addEventListener('click', function(e) {
                        e.preventDefault();
                        const nextEnabled = !selfUpdateEnabled;
                        Swal.fire({
                            title: nextEnabled ? 'Aktifkan Update Sistem?' : 'Nonaktifkan Update Sistem?',
                            text: nextEnabled ? 'Aktifkan hanya saat maintenance dan setelah backup tersedia.' : 'Update dari web akan dikunci kembali.',
                            icon: nextEnabled ? 'warning' : 'question',
                            showCancelButton: true,
                            confirmButtonColor: nextEnabled ? '#f59e0b' : '#6777ef',
                            cancelButtonColor: '#6c757d',
                            confirmButtonText: nextEnabled ? 'Ya, Aktifkan' : 'Ya, Nonaktifkan',
                            cancelButtonText: 'Batal'
                        }).then((result) => {
                            if (!result.isConfirmed) return;

                            $.ajax({
                                url: 'update_github.php',
                                type: 'POST',
                                data: { action: 'set_self_update', enabled: nextEnabled ? '1' : '0', csrf_token: updateCsrfToken },
                                dataType: 'json',
                                success: function(response) {
                                    if (response.success) {
                                        refreshUpdateToggle(!!response.enabled);
                                        Swal.fire({ icon: 'success', title: 'Berhasil', text: response.message });
                                    } else {
                                        Swal.fire({ icon: 'error', title: 'Gagal', text: response.message });
                                    }
                                },
                                error: function() {
                                    Swal.fire({ icon: 'error', title: 'Error', text: 'Gagal menyimpan pengaturan update.' });
                                }
                            });
                        });
                    });
                }

                if (btnUpdate) {
                    btnUpdate.addEventListener('click', function(e) {
                        e.preventDefault();

                        if (!selfUpdateEnabled) {
                            Swal.fire({
                                icon: 'info',
                                title: 'Update Sistem Nonaktif',
                                text: 'Aktifkan Update Sistem dulu dari menu akun saat maintenance.'
                            });
                            return;
                        }
                        
                        Swal.fire({
                            title: 'Update Aplikasi?',
                            text: "Sistem akan mengambil perubahan terbaru dari GitHub. Pastikan koneksi internet stabil.",
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonColor: '#3085d6',
                            cancelButtonColor: '#d33',
                            confirmButtonText: 'Ya, Update!',
                            cancelButtonText: 'Batal'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                Swal.fire({
                                    title: 'Sedang Update...',
                                    text: 'Mohon tunggu sebentar.',
                                    allowOutsideClick: false,
                                    didOpen: () => {
                                        Swal.showLoading();
                                    }
                                });

                                $.ajax({
                                    url: 'update_github.php',
                                    type: 'POST',
                                    data: { action: 'update_from_github', csrf_token: updateCsrfToken },
                                    dataType: 'json',
                                    success: function(response) {
                                        if (response.success) {
                                            Swal.fire({
                                                icon: 'success',
                                                title: 'Berhasil!',
                                                text: response.message
                                            }).then(() => {
                                                window.location.reload();
                                            });
                                        } else {
                                            Swal.fire({
                                                icon: 'error',
                                                title: 'Gagal Update',
                                                text: response.message
                                            });
                                        }
                                    },
                                    error: function(xhr, status, error) {
                                        Swal.fire({
                                            icon: 'error',
                                            title: 'Error',
                                            text: 'Terjadi kesalahan sistem saat menghubungi server.'
                                        });
                                    }
                                });
                            }
                        });
                    });
                }
            });
            </script>
            <?php if (getUserLevel() === 'admin' || getUserLevel() === 'kepala_madrasah'): ?>
            <!-- Mobile Floating Notification Button -->
            <a href="#" data-toggle="modal" data-target="#mobileNotificationModal" class="btn btn-primary btn-lg rounded-circle shadow-lg d-lg-none" style="position: fixed; bottom: 80px; right: 20px; z-index: 1040; width: 60px; height: 60px; display: flex; align-items: center; justify-content: center;">
                <i class="far fa-bell fa-lg"></i>
                <?php if ($unread_count > 0): ?>
                    <span class="notif-count-badge" data-count="<?php echo (int)$unread_count; ?>"><?php echo htmlspecialchars($unread_count_label, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </a>

            <!-- Mobile Notification Modal -->
            <div class="modal fade" id="mobileNotificationModal" tabindex="-1" role="dialog" aria-labelledby="mobileNotificationModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="mobileNotificationModalLabel">Notifikasi</h5>
                            <div class="ml-auto">
                                <a href="#" id="mark-all-read-mobile" class="text-small">Tandai semua dibaca</a>
                            </div>
                            <button type="button" class="close ml-2" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body p-0">
                            <div class="list-group list-group-flush" style="max-height: 400px; overflow-y: auto;">
                                <?php if (count($unread_notifs) > 0): ?>
                                    <?php foreach ($unread_notifs as $notif): ?>
                                        <?php
                                            $notif_link = $notif['link'];
                                            if (getUserLevel() === 'kepala_madrasah') {
                                                if ($notif_link === 'absensi_guru.php') {
                                                    $notif_link = 'rekap_absensi_guru.php';
                                                }
                                            }
                                        ?>
                                        <a href="#" onclick="readNotification(<?php echo $notif['id']; ?>, '<?php echo $notif_link; ?>', this); return false;" class="list-group-item list-group-item-action flex-column align-items-start <?php echo $notif['is_read'] ? '' : 'bg-light'; ?>">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1 text-primary"><i class="fas fa-info-circle mr-1"></i> Info</h6>
                                                <small class="text-muted"><?php echo timeAgo($notif['created_at']); ?></small>
                                            </div>
                                            <p class="mb-1" style="<?php echo $notif['is_read'] ? '' : 'font-weight: bold;'; ?>"><?php echo htmlspecialchars($notif['message']); ?></p>
                                        </a>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="p-4 text-center text-muted">
                                        <i class="far fa-bell-slash fa-3x mb-3"></i><br>
                                        Tidak ada notifikasi baru
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-block" data-dismiss="modal">Tutup</button>
                        </div>
                    </div>
                </div>
            </div>
            
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Handle mobile mark all read
                const markAllReadMobile = document.getElementById('mark-all-read-mobile');
                if(markAllReadMobile) {
                    markAllReadMobile.addEventListener('click', function(e) {
                        e.preventDefault();
                        // Use jQuery as existing project uses it
                        if(typeof $ !== 'undefined') {
                            $.ajax({
                                url: '../admin/mark_notification_read.php',
                                type: 'POST',
                                data: { action: 'mark_all' },
                                success: function(response) {
                                    window.location.reload();
                                }
                            });
                        }
                    });
                }
            });
            </script>
            <?php endif; ?>

            <?php include_once 'sidebar.php'; ?>
