<?php
require_once 'config/database.php';
require_once 'config/functions.php';

session_start();

// Get current user info before destroying session
$current_user = $_SESSION['username'] ?? 'Unknown';
$user_level = $_SESSION['level'] ?? 'Unknown';

// Unset all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Log logout activity
$username = !empty($current_user) && $current_user !== 'Unknown' ? $current_user : 'system';
$log_result = logActivity($pdo, $username, 'Logout', 'User logged out from ' . $user_level . ' session');
if (!$log_result) error_log("Failed to log activity for Logout: $username");

// Redirect to login page
header("Location: login.php");
exit();
?>