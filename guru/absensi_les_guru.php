<?php
require_once '../config/functions.php';
require_once '../config/database.php';

// Check if user is logged in and has allowed level
if (!isAuthorized(['guru'])) {
    redirect('../login.php');
}

// Logic to check if teacher is Grade 6 teacher
$is_grade_6_guru = false;
$id_guru_check = $_SESSION['user_id'];
if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
    $stmt_uid = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
    $stmt_uid->execute([$_SESSION['user_id']]);
    $id_guru_check = $stmt_uid->fetchColumn();
}

if ($id_guru_check) {
    $stmt_g = $pdo->prepare("SELECT mengajar FROM tb_guru WHERE id_guru = ?");
    $stmt_g->execute([$id_guru_check]);
    $mengajar_json = $stmt_g->fetchColumn();
    $mengajar_arr = json_decode($mengajar_json, true) ?? [];
    
    if (!empty($mengajar_arr)) {
        $placeholders = str_repeat('?,', count($mengajar_arr) - 1) . '?';
        $params = array_merge($mengajar_arr, $mengajar_arr);
        $stmt_cls = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas IN ($placeholders) OR nama_kelas IN ($placeholders)");
        $stmt_cls->execute($params);
        $classes_taught = $stmt_cls->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($classes_taught as $nk) {
            $nk = strtoupper($nk);
            if (strpos($nk, '6') !== false || strpos($nk, 'VI') !== false) {
                $is_grade_6_guru = true;
                break;
            }
        }
    }
}

if (!$is_grade_6_guru) {
    die("Akses ditolak. Menu ini hanya untuk Guru Kelas 6.");
}

require_once '../admin/absensi_les_guru.php';
