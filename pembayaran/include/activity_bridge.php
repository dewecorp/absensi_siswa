<?php
/**
 * Jembatan log aktivitas SIMAD -> database Pembayaran Siswa.
 *
 * Dipakai login.php/logout.php agar aktivitas login/logout bendahara dan admin
 * ikut tercatat di tabel log_aktivitas milik modul Pembayaran Siswa.
 * Tidak memakai config/config.php karena halaman login belum punya sesi.
 */

if (!function_exists('pembayaran_bridge_log')) {
    function pembayaran_bridge_log(string $jenis, string $deskripsi, string $username = '', string $level = ''): bool
    {
        $level = strtolower(trim($level));
        if (!in_array($level, ['bendahara', 'admin'], true)) {
            return false;
        }

        $http_host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $is_local = ($http_host === 'localhost' || $http_host === '127.0.0.1'
            || strpos($http_host, '.test') !== false || strpos($http_host, '.local') !== false);

        $host = getenv('PEMBAYARAN_DB_HOST') ?: ($is_local ? '127.0.0.1' : 'localhost');
        $user = getenv('PEMBAYARAN_DB_USER') ?: ($is_local ? 'root' : 'kvzveyrg_pembayaran');
        $pass = getenv('PEMBAYARAN_DB_PASS') !== false ? (string) getenv('PEMBAYARAN_DB_PASS') : ($is_local ? '' : 'sultanfattah26');
        $db = getenv('PEMBAYARAN_DB_NAME') ?: ($is_local ? 'spp' : 'kvzveyrg_pembayaran');
        $port = (int) (getenv('PEMBAYARAN_DB_PORT') ?: 3306);

        if (function_exists('mysqli_report')) {
            mysqli_report(MYSQLI_REPORT_OFF);
        }

        $koneksi = @mysqli_connect($host, $user, $pass, $db, $port);
        if (!$koneksi && $is_local) {
            $koneksi = @mysqli_connect('localhost', 'root', '', $db);
        }
        if (!$koneksi) {
            return false;
        }

        @mysqli_query($koneksi, "SET time_zone = '+07:00'");

        $id_pengguna = 0;
        $username = trim($username);
        if ($username !== '') {
            $stmt = @mysqli_prepare($koneksi, 'SELECT id_pengguna FROM pengguna WHERE username = ? LIMIT 1');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 's', $username);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $row = $result ? mysqli_fetch_assoc($result) : null;
                $id_pengguna = (int) ($row['id_pengguna'] ?? 0);
                mysqli_stmt_close($stmt);
            }
        }

        if ($id_pengguna <= 0) {
            $preferred_role = $level === 'admin' ? 'admin' : 'petugas';
            $stmt = @mysqli_prepare($koneksi, 'SELECT id_pengguna FROM pengguna WHERE role = ? ORDER BY id_pengguna ASC LIMIT 1');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 's', $preferred_role);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $row = $result ? mysqli_fetch_assoc($result) : null;
                $id_pengguna = (int) ($row['id_pengguna'] ?? 0);
                mysqli_stmt_close($stmt);
            }
        }

        if ($id_pengguna <= 0) {
            $id_pengguna = 1;
        }

        $stmt = @mysqli_prepare($koneksi, 'INSERT INTO log_aktivitas (id_pengguna, jenis_aktivitas, deskripsi) VALUES (?, ?, ?)');
        if (!$stmt) {
            mysqli_close($koneksi);
            return false;
        }

        mysqli_stmt_bind_param($stmt, 'iss', $id_pengguna, $jenis, $deskripsi);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        mysqli_close($koneksi);

        return (bool) $ok;
    }
}
