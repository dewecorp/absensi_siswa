<?php
require_once '../config/functions.php';
require_once '../config/database.php';

// Check if user is logged in and has allowed level
if (!isAuthorized(['wali'])) {
    redirect('../login.php');
}

// Logic to check if wali is Grade 6 wali
$is_grade_6_wali = false;
$stmt_w = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE wali_kelas = ?");
$stmt_w->execute([$_SESSION['nama_guru']]);
$class_name = $stmt_w->fetchColumn();

if ($class_name) {
    $class_name = strtoupper($class_name);
    if (strpos($class_name, '6') !== false || strpos($class_name, 'VI') !== false) {
        $is_grade_6_wali = true;
    }
}

if (!$is_grade_6_wali) {
    die("Akses ditolak. Menu ini hanya untuk Wali Kelas 6.");
}

require_once '../admin/absensi_les_guru.php';
