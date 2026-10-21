<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { $_SESSION['error'] = 'Sesi berakhir. Silakan muat ulang halaman dan coba lagi.'; header('Location: ' . BASE_URL . 'pages/gaji_pokok'); exit(); }
    $id = $_POST['id'] ?? null;
    $guru_id = $_POST['guru_id'] ?? 0;
    $jumlah = str_replace(['.', ','], '', $_POST['jumlah'] ?? 0);
    
    if ($id) {
        $sql = "UPDATE gaji_pokok SET guru_id=?, jumlah=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("idi", $guru_id, $jumlah, $id);
        $action = 'mengubah';
    } else {
        // Check if record already exists for this guru
        $check_sql = "SELECT id FROM gaji_pokok WHERE guru_id = ? LIMIT 1";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $guru_id);
        $check_stmt->execute();
        $existing = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        
        if ($existing) {
            // Update existing record
            $sql = "UPDATE gaji_pokok SET jumlah=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("di", $jumlah, $existing['id']);
            $action = 'mengubah';
        } else {
            // Insert new record (tanpa periode karena gaji pokok tetap)
            $sql = "INSERT INTO gaji_pokok (guru_id, jumlah, periode) VALUES (?, ?, '')";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("id", $guru_id, $jumlah);
            $action = 'menambah';
        }
    }
    
    if ($stmt->execute()) {
        $guru_stmt = $conn->prepare("SELECT nama_lengkap FROM guru WHERE id = ?");
        $guru_stmt->bind_param("i", $guru_id);
        $guru_stmt->execute();
        $guru_name = $guru_stmt->get_result()->fetch_assoc()['nama_lengkap'];
        $guru_stmt->close();
        gajiLogActivity($conn, "{$action} gaji pokok untuk {$guru_name}", 'success');
        $_SESSION['success'] = "Data gaji pokok berhasil " . ($id ? 'diubah' : 'ditambahkan');
    } else {
        $_SESSION['error'] = "Gagal " . ($id ? 'mengubah' : 'menambah') . " data gaji pokok";
    }
}

header('Location: ' . BASE_URL . 'pages/gaji_pokok');
exit();
?>



