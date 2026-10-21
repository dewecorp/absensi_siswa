<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

if (!verifyCsrfToken($_REQUEST['csrf_token'] ?? '')) { $_SESSION['error'] = 'Sesi berakhir. Silakan muat ulang halaman dan coba lagi.'; header('Location: ' . BASE_URL . 'pages/pembina'); exit(); }

$id = intval($_REQUEST['id'] ?? 0);
if ($id > 0) {
    $sql = "SELECT nama_pembina FROM pembina WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $pembina = $result->fetch_assoc();
    $stmt->close();
    
    $sql = "DELETE FROM pembina WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        gajiLogActivity($conn, 'Menghapus data pembina: ' . ($pembina['nama_pembina'] ?? ''), 'danger');
        $_SESSION['success'] = 'Data berhasil dihapus';
    } else {
        $_SESSION['error'] = 'Gagal menghapus data';
    }
    $stmt->close();
}

header('Location: ' . BASE_URL . 'pages/pembina');
exit();
?>


