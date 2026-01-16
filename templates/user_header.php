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
    
    <!-- Additional CSS for this specific page -->
    <?php if (isset($css_page) && is_array($css_page)): ?>
        <?php foreach ($css_page as $css): ?>
            <style><?php echo $css; ?></style>
        <?php endforeach; ?>
    <?php endif; ?>

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
                                $profile_url = 'features-profile.html';
                            }
                            ?>
                            <a href="<?php echo $profile_url; ?>" class="dropdown-item has-icon">
                                <i class="fas fa-cog"></i> Pengaturan
                            </a>
                            <div class="dropdown-divider"></div>
                            <a href="#" onclick="confirmLogoutInline(); return false;" class="dropdown-item has-icon text-danger">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </div>
                    </li>
                </ul>
            </nav>
            <?php include 'sidebar.php'; ?>