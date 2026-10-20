<?php
/**
 * Bootstrap modul Pembayaran Siswa (Pembayaran) di dalam SIMAD.
 *
 * - Login & otorisasi memakai sesi SIMAD (bukan login Pembayaran).
 * - Database tetap database Pembayaran (`spp`), dapat dioverride via environment.
 * - Semua URL dihitung dari lokasi folder ini di dalam SIMAD.
 */

date_default_timezone_set('Asia/Jakarta');

$simad_root = dirname(__DIR__, 2);
$pembayaran_script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$is_api_request = strpos($pembayaran_script, '/api/') !== false;
$is_logout_request = strpos($pembayaran_script, '/auth/logout.php') !== false;

// Pakai sesi SIMAD (folder & cookie yang sama), tanpa meng-include functions.php
// agar fungsi logActivity milik Pembayaran tidak bentrok.
if (!$is_api_request) {
    if (session_status() === PHP_SESSION_NONE) {
        $session_dir = $simad_root . '/sessions';
        if (is_dir($session_dir) && is_writable($session_dir)) {
            @session_save_path($session_dir);
        }
        $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        if (PHP_VERSION_ID >= 70300) {
            @session_set_cookie_params([
                'lifetime' => 604800,
                'path' => '/',
                'secure' => $is_https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        @session_start();
    }

    if (!$is_logout_request) {
        $simad_level = strtolower(trim((string)($_SESSION['level'] ?? '')));
        $authorized = isset($_SESSION['user_id']) && in_array($simad_level, ['bendahara', 'admin'], true);
        if ($authorized && isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity'] > 7200)) {
            $authorized = false;
        }

        if (!$authorized) {
            $simad_base_path = preg_replace('#/pembayaran(?:/.*)?$#', '', str_replace('\\', '/', dirname($pembayaran_script)));
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            header('Location: ' . $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $simad_base_path . '/login.php');
            exit;
        }
        $_SESSION['last_activity'] = time();

        // Jembatan sesi: halaman Pembayaran memakai kunci sesi SIMAD.
        $_SESSION['login'] = true;
        $_SESSION['role'] = $simad_level === 'admin' ? 'admin' : 'bendahara';
        $_SESSION['nama_lengkap'] = (string)($_SESSION['nama'] ?? $_SESSION['username'] ?? 'Pengguna SIMAD');
    }
}

// === KONEKSI DATABASE PEMBAYARAN (mysqli untuk logika asli Pembayaran) ===
$is_local = ($_SERVER['HTTP_HOST'] == 'localhost' || $_SERVER['HTTP_HOST'] == '127.0.0.1' || strpos($_SERVER['HTTP_HOST'], '.test') !== false || strpos($_SERVER['HTTP_HOST'], '.local') !== false);

$host = getenv('PEMBAYARAN_DB_HOST') ?: ($is_local ? '127.0.0.1' : 'localhost');
$user = getenv('PEMBAYARAN_DB_USER') ?: ($is_local ? 'root' : 'kvzveyrg_pembayaran');
$pass = getenv('PEMBAYARAN_DB_PASS') !== false ? (string)getenv('PEMBAYARAN_DB_PASS') : ($is_local ? '' : 'sultanfattah26');
$db   = getenv('PEMBAYARAN_DB_NAME') ?: ($is_local ? 'spp' : 'kvzveyrg_pembayaran');
$port = (int)(getenv('PEMBAYARAN_DB_PORT') ?: 3306);

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}
$koneksi = mysqli_connect($host, $user, $pass, $db, $port);
if (!$koneksi && $is_local) {
    $koneksi = mysqli_connect('localhost', 'root', '', $db);
}
if (!$koneksi) {
    die('Koneksi database Pembayaran Siswa gagal. Periksa environment PEMBAYARAN_DB_HOST, PEMBAYARAN_DB_USER, PEMBAYARAN_DB_PASS, PEMBAYARAN_DB_NAME.');
}
mysqli_query($koneksi, "SET time_zone = '+07:00'");

// Petakan akun SIMAD ke akun petugas Pembayaran bila username-nya sama (untuk audit pembayaran).
if (!$is_api_request && isset($_SESSION['username'])) {
    $username_simad = (string)$_SESSION['username'];
    $stmt_petugas = mysqli_prepare($koneksi, 'SELECT id_pengguna FROM pengguna WHERE username = ? LIMIT 1');
    if ($stmt_petugas) {
        mysqli_stmt_bind_param($stmt_petugas, 's', $username_simad);
        mysqli_stmt_execute($stmt_petugas);
        $res_petugas = mysqli_stmt_get_result($stmt_petugas);
        $row_petugas = $res_petugas ? mysqli_fetch_assoc($res_petugas) : null;
        $_SESSION['id_pengguna'] = (int)($row_petugas['id_pengguna'] ?? 0);
        mysqli_stmt_close($stmt_petugas);
    } else {
        $_SESSION['id_pengguna'] = 0;
    }

    // Fallback: akun SIMAD belum ada di tabel pengguna Pembayaran.
    // Pilih petugas/admin yang valid agar foreign key pembayaran tidak gagal.
    if ((int)$_SESSION['id_pengguna'] <= 0) {
        $preferred_role = (($_SESSION['role'] ?? '') === 'admin') ? 'admin' : 'petugas';
        $stmt_fallback = mysqli_prepare($koneksi, 'SELECT id_pengguna FROM pengguna WHERE role = ? ORDER BY id_pengguna ASC LIMIT 1');
        if ($stmt_fallback) {
            mysqli_stmt_bind_param($stmt_fallback, 's', $preferred_role);
            mysqli_stmt_execute($stmt_fallback);
            $res_fallback = mysqli_stmt_get_result($stmt_fallback);
            $row_fallback = $res_fallback ? mysqli_fetch_assoc($res_fallback) : null;
            $_SESSION['id_pengguna'] = (int)($row_fallback['id_pengguna'] ?? 1);
            mysqli_stmt_close($stmt_fallback);
        } else {
            $_SESSION['id_pengguna'] = 1;
        }
    }
}

// === BASE URL: selalu mengarah ke folder /pembayaran di dalam SIMAD ===
if (!isset($base_url)) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $doc_root = str_replace('\\', '/', realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '');
    $app_root = str_replace('\\', '/', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
    $relative = $doc_root !== '' && strpos($app_root, $doc_root) === 0
        ? substr($app_root, strlen($doc_root))
        : '/' . basename($app_root);
    $base_url = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/' . trim($relative, '/');
}

function base_url($path = '') {
    global $base_url;
    return rtrim($base_url, '/') . '/' . ltrim((string)$path, '/');
}

if (!function_exists('simad_url')) {
    function simad_url($path = ''): string
    {
        $root = preg_replace('#/pembayaran/?$#', '', rtrim(base_url(), '/'));
        return $root . '/' . ltrim((string)$path, '/');
    }
}

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}
include_once __DIR__ . '/../include/activity_helper.php';
include_once __DIR__ . '/../include/qr_helper.php';
include_once __DIR__ . '/../include/laporan_helper.php';
