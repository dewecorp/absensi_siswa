<?php
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
} catch(PDOException $e) {
    // Log error instead of showing it to user
    error_log("Connection failed: " . $e->getMessage());
    die("Koneksi database gagal. Silakan hubungi administrator.");
}
?>