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
logActivity($pdo, $current_user, 'Logout', 'User logged out from ' . $user_level . ' session');

// Redirect to login page
header("Location: login.php");
exit();
?>