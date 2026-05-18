<?php
require_once 'config/database.php';
require_once 'config/functions.php';

// Get current user info before destroying session
$current_user = $_SESSION['username'] ?? 'Unknown';
$user_level = $_SESSION['level'] ?? 'Unknown';

// Unset all session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie.
// Note: This will destroy the session, and not just the session data!
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

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
