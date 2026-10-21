<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

if (!verifyCsrfToken($_REQUEST['csrf_token'] ?? '')) { $_SESSION['error'] = 'Sesi berakhir. Silakan muat ulang halaman dan coba lagi.'; header('Location: ' . BASE_URL . 'pages/tunjangan'); exit(); }

$id = $_REQUEST['id'] ?? 0;
$sql = "SELECT nama_tunjangan FROM tunjangan WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$tunjangan = $result->fetch_assoc();

if ($tunjangan) {
    $sql = "DELETE FROM tunjangan WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        gajiLogActivity($conn, "Menghapus tunjangan: {$tunjangan['nama_tunjangan']}", 'danger');
        $_SESSION['success'] = "Data tunjangan berhasil dihapus";
    } else {
        $_SESSION['error'] = "Gagal menghapus data tunjangan";
    }
} else {
    $_SESSION['error'] = "Data tidak ditemukan";
}
header('Location: ' . BASE_URL . 'pages/tunjangan');
exit();
?>




