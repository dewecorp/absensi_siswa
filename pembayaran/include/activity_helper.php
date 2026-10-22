<?php
date_default_timezone_set('Asia/Jakarta');

function pembayaran_log_activity($koneksi, $jenis, $deskripsi) {
    if (isset($_SESSION['id_pengguna'])) {
        $id_pengguna = $_SESSION['id_pengguna'];
        $deskripsi_esc = mysqli_real_escape_string($koneksi, $deskripsi);
        $jenis_esc = mysqli_real_escape_string($koneksi, $jenis);
        
        $query = "INSERT INTO log_aktivitas (id_pengguna, jenis_aktivitas, deskripsi) VALUES ('$id_pengguna', '$jenis_esc', '$deskripsi_esc')";
        mysqli_query($koneksi, $query);

        // Catat juga ke tb_activity_log SIMAD sebagai sumber tunggal
        try {
            global $pdo;
            if (!$pdo && file_exists(__DIR__ . '/../../config/database.php')) {
                require_once __DIR__ . '/../../config/database.php';
            }
            if ($pdo) {
                $username = (string)($_SESSION['username'] ?? 'bendahara');
                $action_label = 'Pembayaran ' . ucfirst($jenis);
                $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $stmt = $pdo->prepare("INSERT INTO tb_activity_log (username, action, description, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$username, $action_label, $deskripsi, $ip]);
            }
        } catch (Throwable $e) {}
    }
}

function time_ago($timestamp) {
    $time_ago = strtotime($timestamp);
    $current_time = time();
    $time_difference = $current_time - $time_ago;
    $seconds = $time_difference;
    $minutes      = round($seconds / 60);           // value 60 is seconds
    $hours        = round($seconds / 3600);         // value 3600 is 60 minutes * 60 sec
    $days         = round($seconds / 86400);        // value 86400 is 24 hours * 60 minutes * 60 sec
    $weeks        = round($seconds / 604800);       // value 604800 is 7 days * 86400 sec
    $months       = round($seconds / 2629440);      // value 2629440 is ((365+365+365+365+366)/5/12) * 86400
    $years        = round($seconds / 31553280);     // value 31553280 is (365+365+365+365+366)/5 * 86400

    if ($seconds <= 60) {
        return "Baru saja";
    } else if ($minutes <= 60) {
        if ($minutes == 1) {
            return "satu menit yang lalu";
        } else {
            return "$minutes menit yang lalu";
        }
    } else if ($hours <= 24) {
        if ($hours == 1) {
            return "satu jam yang lalu";
        } else {
            return "$hours jam yang lalu";
        }
    } else if ($days <= 7) {
        if ($days == 1) {
            return "kemarin";
        } else {
            return "$days hari yang lalu";
        }
    } else if ($weeks <= 4.3) {
        if ($weeks == 1) {
            return "satu minggu yang lalu";
        } else {
            return "$weeks minggu yang lalu";
        }
    } else if ($months <= 12) {
        if ($months == 1) {
            return "satu bulan yang lalu";
        } else {
            return "$months bulan yang lalu";
        }
    } else {
        if ($years == 1) {
            return "satu tahun yang lalu";
        } else {
            return "$years tahun yang lalu";
        }
    }
}
?>