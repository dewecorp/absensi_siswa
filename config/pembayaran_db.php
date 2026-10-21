<?php
/**
 * Koneksi database modul Pembayaran Siswa.
 *
 * Modul Pembayaran menyatu dengan database utama SIMAD. Kredensial diambil dari
 * config/database.php (DB_HOST, DB_USER, DB_PASS, DB_NAME). Environment variable
 * PEMBAYARAN_DB_* hanya dipakai bila server database memang berbeda.
 */

require_once __DIR__ . '/database.php';

if (!function_exists('pembayaran_db_config')) {
    function pembayaran_db_config(): array
    {
        $cfg = [
            'host' => getenv('PEMBAYARAN_DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : 'localhost'),
            'port' => getenv('PEMBAYARAN_DB_PORT') ?: '3306',
            'name' => getenv('PEMBAYARAN_DB_NAME') ?: (defined('DB_NAME') ? DB_NAME : ''),
            'user' => getenv('PEMBAYARAN_DB_USER') ?: (defined('DB_USER') ? DB_USER : ''),
            'pass' => getenv('PEMBAYARAN_DB_PASS') !== false
                ? (string)getenv('PEMBAYARAN_DB_PASS')
                : (defined('DB_PASS') ? DB_PASS : ''),
        ];

        return $cfg;
    }
}

if (!function_exists('pembayaran_db')) {
    /**
     * @return PDO|null Null bila koneksi gagal / belum dikonfigurasi.
     */
    function pembayaran_db(): ?PDO
    {
        static $pdb = null;
        static $tried = false;
        if ($tried) return $pdb;
        $tried = true;

        $cfg = pembayaran_db_config();
        if ($cfg['user'] === '' || $cfg['name'] === '') {
            return null;
        }

        try {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'], $cfg['name']);
            $pdb = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $pdb->exec("SET time_zone = '+07:00'");
        } catch (Throwable $e) {
            error_log('Pembayaran DB connection failed: ' . $e->getMessage());
            $pdb = null;
        }

        return $pdb;
    }
}

if (!function_exists('pembayaran_db_error')) {
    function pembayaran_db_error(): string
    {
        $cfg = pembayaran_db_config();
        return 'Koneksi database SIMAD gagal. Pastikan database `' . $cfg['name'] . '` dapat diakses. '
            . 'Periksa konfigurasi database pada config/database.php.';
    }
}
