<?php
/**
 * Bootstrap modul Gaji (hasil integrasi SIGaji ke SIMAD).
 *
 * - Satu sesi login dengan SIMAD.
 * - Satu database dengan SIMAD (nama tabel gaji dipertahankan).
 * - Tidak ada lagi aplikasi/DB terpisah.
 */

if (defined('SIMAD_GAJI_BOOTSTRAPPED')) {
    return;
}
define('SIMAD_GAJI_BOOTSTRAPPED', true);

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if (!isAuthorized(['bendahara', 'admin', 'kepala_madrasah'])) {
    redirect(app_base_path() . '/login.php');
}

if (!defined('SIMAD_BASE_URL')) {
    define('SIMAD_BASE_URL', app_base_path());
}
if (!defined('BASE_URL')) {
    define('BASE_URL', SIMAD_BASE_URL . '/bendahara/gaji/');
}
if (!defined('APP_NAME')) {
    define('APP_NAME', 'SIMAD - Sistem Gaji');
}
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '2.0.0');
}

date_default_timezone_set('Asia/Jakarta');

// Tandai halaman ini sebagai halaman Modul Gaji/Honor.
// Dipakai templates/header.php untuk mengaktifkan gaya mobile app
// (assets/css/gaji-app.css + assets/js/gaji-app.js).
if (!isset($gaji_module_page)) {
    $gaji_module_page = true;
}

// Koneksi mysqli untuk kode halaman gaji (warisan SIGaji).
if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        exit('Koneksi database SIMAD gagal.');
    }
    $conn->set_charset('utf8mb4');
}

// Kompatibilitas variabel sesi lama SIGaji.
if (empty($_SESSION['nama_lengkap'])) {
    $_SESSION['nama_lengkap'] = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Pengguna';
}
if (empty($_SESSION['role']) && function_exists('getUserLevel')) {
    $_SESSION['role'] = getUserLevel();
}

require_once __DIR__ . '/helpers.php';
