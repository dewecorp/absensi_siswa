<?php
// Database configuration with environment detection
$host = $_SERVER['HTTP_HOST'] ?? '';
if ($host == 'localhost' || $host == '127.0.0.1' || strpos($host, '.test') !== false || strpos($host, '.local') !== false) {
    // Local environment (Laragon, XAMPP, etc.)
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'db_absensi');
} else {
    // Hosting environment
    define('DB_HOST', 'localhost');
    define('DB_USER', 'kvzveyrg_simad');
    define('DB_PASS', 'sultanfattah26');
    define('DB_NAME', 'kvzveyrg_simad');
}

date_default_timezone_set('Asia/Jakarta');

try {
    // Tambahkan charset utf8mb4 untuk stabilitas data di hosting Linux
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+07:00'",
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    // Set Timezone PHP
    date_default_timezone_set('Asia/Jakarta');
} catch(PDOException $e) {
    // Log error instead of showing it to user
    error_log("Connection failed: " . $e->getMessage());
    die("Koneksi database gagal. Silakan hubungi administrator.");
}
