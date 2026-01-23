<?php
// Header template for the attendance system
if (!isset($_SESSION)) {
    session_start();
}

// Include functions and database connection
require_once '../config/database.php';
require_once '../config/functions.php';

// Get school profile
$school_profile = getSchoolProfile($pdo);

// Check if user is logged in
// TEMPORARY: Bypass authentication for testing
/*
if (!isLoggedIn()) {
    redirect('../login.php');
}
*/

// Get current page title
$page_title = isset($page_title) ? $page_title : 'Dashboard';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, shrink-to-fit=no" name="viewport">
    <title><?php echo $page_title; ?> | Sistem Informasi Madrasah</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="../assets/img/favicon.svg">
    <link rel="apple-touch-icon" href="../assets/img/favicon.svg">

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

</head>

<body>
    <div id="app">
        <div class="main-wrapper">
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
                    
                    <?php if (getUserLevel() === 'admin' || getUserLevel() === 'kepala_madrasah'): ?>
                    <?php 
                        $unread_notifs = getUnreadNotifications($pdo);
                        $unread_count = 0;
                        foreach($unread_notifs as $n) {
                            if(!$n['is_read']) $unread_count++;
                        }
                    ?>
                    <li class="dropdown dropdown-list-toggle">
                        <a href="#" data-toggle="dropdown" class="nav-link nav-link-lg message-toggle <?php echo $unread_count > 0 ? 'beep' : ''; ?>">
                            <i class="far fa-bell"></i>
                            <?php if ($unread_count > 0): ?>
                                <span class="badge badge-danger" style="position: absolute; top: 0; right: 0; padding: 3px 6px; font-size: 10px;"><?php echo $unread_count; ?></span>
                            <?php endif; ?>
                        </a>
                        <div class="dropdown-menu dropdown-list dropdown-menu-right">
                            <div class="dropdown-header">Notifikasi
                                <div class="float-right">
                                    <a href="#" id="mark-all-read">Tandai semua dibaca</a>
                                </div>
                            </div>
                            <div class="dropdown-list-content dropdown-list-icons" style="height: 300px; overflow-y: auto;">
                                <?php if (count($unread_notifs) > 0): ?>
                                    <?php foreach ($unread_notifs as $notif): ?>
                                        <a href="#" onclick="readNotification(<?php echo $notif['id']; ?>, '<?php echo $notif['link']; ?>'); return false;" class="dropdown-item dropdown-item-unread" style="<?php echo $notif['is_read'] ? '' : 'font-weight: bold; background-color: #f9f9f9;'; ?>">
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
                    <script>
                    function readNotification(id, link) {
                        // Mark as read via AJAX
                        $.ajax({
                            url: '../admin/mark_notification_read.php',
                            type: 'POST',
                            data: { id: id },
                            success: function(response) {
                                // Redirect to link
                                if (link && link !== '#') {
                                    window.location.href = link;
                                } else {
                                    // Just reload or update UI
                                    window.location.reload();
                                }
                            }
                        });
                    }
                    
                    $(document).ready(function() {
                        $('#mark-all-read').click(function(e) {
                            e.preventDefault();
                            $.ajax({
                                url: '../admin/mark_notification_read.php',
                                type: 'POST',
                                data: { action: 'mark_all' },
                                success: function(response) {
                                    window.location.reload();
                                }
                            });
                        });
                    });
                    </script>
                    <?php endif; ?>

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
                            $display_name = $_SESSION['username'];
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
                                $profile_url = 'profil_madrasah.php';
                            }
                            ?>
                            <a href="<?php echo $profile_url; ?>" class="dropdown-item has-icon">
                                <i class="fas fa-cog"></i> Pengaturan
                            </a>
                            <div class="dropdown-divider"></div>
                            <a href="#" onclick="confirmLogout(); return false;" class="dropdown-item has-icon text-danger">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </div>
                    </li>
                </ul>
            </nav>
            <?php include 'sidebar.php'; ?>