<?php
// Set session lifetime to 24 hours (86400 seconds) if session is not active
if (session_status() == PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 86400);
    ini_set('session.cookie_lifetime', 86400);
    session_set_cookie_params(86400);
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'db_absensi');

date_default_timezone_set('Asia/Jakarta');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS, [
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+07:00'"
    ]);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Set Timezone PHP
    date_default_timezone_set('Asia/Jakarta');
    
    // Set Timezone MySQL
    $pdo->exec("SET time_zone = '+07:00'");

    try {
        $colCheck = $pdo->query("SHOW COLUMNS FROM tb_mata_pelajaran LIKE 'jenis_mapel'");
        if ($colCheck && $colCheck->rowCount() == 0) {
            $pdo->exec("ALTER TABLE tb_mata_pelajaran ADD COLUMN jenis_mapel VARCHAR(20) NULL DEFAULT 'Akademik' AFTER kode_mapel");
        }
    } catch (Exception $e) {
    }
} catch(PDOException $e) {
    // Log error instead of showing it to user
    error_log("Connection failed: " . $e->getMessage());
    die("Koneksi database gagal. Silakan hubungi administrator.");
}
