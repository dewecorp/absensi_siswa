<?php
require_once 'config/database.php';
require_once 'config/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['nama'] = 'Admin';
$_SESSION['level'] = 'admin';
$_SESSION['logged_in'] = true;
header('Location: admin/data_alumni.php');
exit;
