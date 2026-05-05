<?php
require_once 'config/database.php';
require_once 'config/functions.php';

// Get current user info before destroying session
$current_user = $_SESSION['username'] ?? 'Unknown';
$user_level = $_SESSION['level'] ?? 'Unknown';
// Jika level tidak ada di $_SESSION (data lama/varian sesi), turunkan dari nama cookie sesi aktif
if ($user_level === 'Unknown') {
    $sn = session_name();
    $session_to_level = [
        'SIS_ADMIN' => 'admin',
        'SIS_GURU' => 'guru',
        'SIS_WALI' => 'wali',
        'SIS_SISWA' => 'siswa',
        'SIS_TU' => 'tata_usaha',
        'SIS_KEPALA' => 'kepala_madrasah',
        'SIS_LOGIN' => 'login',
    ];
    if (isset($session_to_level[$sn])) {
        $user_level = $session_to_level[$sn];
    }
}

// Unset all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Clear sticky session
setcookie('LAST_ACTIVE_SESSION', '', time() - 3600, '/');

// Log logout activity
$username = !empty($current_user) && $current_user !== 'Unknown' ? $current_user : 'system';
$session_label = $user_level !== 'Unknown' ? $user_level : 'unidentified';
$log_result = logActivity($pdo, $username, 'Logout', 'User logged out from ' . $session_label . ' session');
if (!$log_result) error_log("Failed to log activity for Logout: $username");

// Redirect to login page
header("Location: login.php");
exit();
?>
