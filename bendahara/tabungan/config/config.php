<?php
/**
 * Bootstrap Modul Tabungan Siswa (hasil integrasi e-Tabs ke SIMAD).
 *
 * - Satu sesi login dengan SIMAD (role bendahara / admin).
 * - Satu database dengan SIMAD (db_absensi).
 * - Data siswa & kelas memakai tabel induk SIMAD (tb_siswa / tb_kelas).
 * - Transaksi tabungan & riwayat disimpan di tabel tb_tabungan & tb_tabungan_riwayat.
 */

if (defined('SIMAD_TABUNGAN_BOOTSTRAPPED')) {
    return;
}
define('SIMAD_TABUNGAN_BOOTSTRAPPED', true);

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if (!isAuthorized(['bendahara', 'admin'])) {
    redirect(app_base_path() . '/login.php');
}

if (!defined('SIMAD_BASE_URL')) {
    define('SIMAD_BASE_URL', app_base_path());
}
if (!defined('BASE_URL')) {
    define('BASE_URL', SIMAD_BASE_URL . '/bendahara/tabungan/');
}
if (!defined('APP_NAME')) {
    define('APP_NAME', 'SIMAD - Tabungan Siswa');
}

date_default_timezone_set('Asia/Jakarta');

// Tandai halaman ini sebagai halaman Modul Tabungan.
// Dipakai templates/header.php untuk mengaktifkan gaya mobile app
// (assets/css/tabungan-app.css + assets/js/tabungan-app.js).
if (!isset($tabungan_module_page)) {
    $tabungan_module_page = true;
}

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/helpers.php';

// Pastikan tabel tabungan tersedia (self-healing).
tabungan_ensure_schema($pdo);

// Jembatan ke modul Pembayaran (data pembayaran & tagihan lokal).
require_once __DIR__ . '/pembayaran_bridge.php';

/**
 * URL halaman modul tabungan.
 */
if (!function_exists('tabungan_url')) {
    function tabungan_url(string $path = ''): string
    {
        $base = rtrim(app_base_path(), '/') . '/bendahara';
        return $base . '/' . ltrim($path, '/');
    }
}
