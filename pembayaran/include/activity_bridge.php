<?php
/**
 * Jembatan log aktivitas SIMAD -> tabel log_aktivitas modul Pembayaran.
 *
 * Modul Pembayaran memakai database yang sama dengan SIMAD, jadi koneksi
 * memakai kredensial config/database.php.
 */

require_once dirname(__DIR__, 2) . '/config/database.php';

if (!function_exists('pembayaran_bridge_log')) {
    function pembayaran_bridge_log(string $jenis, string $deskripsi, string $username = '', string $level = ''): bool
    {
        $level = strtolower(trim($level));
        if (!in_array($level, ['bendahara', 'admin'], true)) {
            return false;
        }

        if (function_exists('mysqli_report')) {
            mysqli_report(MYSQLI_REPORT_OFF);
        }

        $koneksi = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if (!$koneksi) {
            return false;
        }

        @mysqli_query($koneksi, "SET time_zone = '+07:00'");
        if (function_exists('mysqli_set_charset')) {
            @mysqli_set_charset($koneksi, 'utf8mb4');
        }

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
