<?php
/**
 * Koneksi langsung ke database Pembayaran Siswa (DB `spp`).
 *
 * Tujuan: modul SPP di SIMAD memakai data asli Pembayaran tanpa memanggil
 * endpoint HTTP dan tanpa perlu ganti base URL di Pengaturan Endpoint.
 *
 * Kredensial dapat dioverride lewat environment:
 *   PEMBAYARAN_DB_HOST, PEMBAYARAN_DB_PORT, PEMBAYARAN_DB_NAME,
 *   PEMBAYARAN_DB_USER, PEMBAYARAN_DB_PASS
 */

if (!function_exists('pembayaran_db_config')) {
    function pembayaran_db_config(): array
    {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $isLocal = $host === '' || $host === 'localhost' || $host === '127.0.0.1'
            || strpos($host, '.test') !== false || strpos($host, '.local') !== false;

        $cfg = [
            'host' => getenv('PEMBAYARAN_DB_HOST') ?: ($isLocal ? '127.0.0.1' : 'localhost'),
            'port' => getenv('PEMBAYARAN_DB_PORT') ?: '3306',
            'name' => getenv('PEMBAYARAN_DB_NAME') ?: 'spp',
            'user' => getenv('PEMBAYARAN_DB_USER') ?: ($isLocal ? 'root' : 'root'),
            'pass' => getenv('PEMBAYARAN_DB_PASS') !== false ? (string)getenv('PEMBAYARAN_DB_PASS') : ($isLocal ? '' : ''),
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
        static $pdo = null;
        static $tried = false;
        if ($tried) return $pdo;
        $tried = true;

        $cfg = pembayaran_db_config();
        if ($cfg['user'] === '' || $cfg['name'] === '') {
            return null;
        }

        try {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'], $cfg['name']);
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $pdo->exec("SET time_zone = '+07:00'");
        } catch (Throwable $e) {
            error_log('Pembayaran DB connection failed: ' . $e->getMessage());
            $pdo = null;
        }

        return $pdo;
    }
}

if (!function_exists('pembayaran_db_error')) {
    function pembayaran_db_error(): string
    {
        $cfg = pembayaran_db_config();
        return 'Koneksi database Pembayaran Siswa gagal. Pastikan database `' . $cfg['name'] . '` dapat diakses oleh SIMAD. '
            . 'Set environment PEMBAYARAN_DB_HOST/USER/PASS bila server berbeda.';
    }
}
