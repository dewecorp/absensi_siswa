<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

if (!verifyCsrfToken($_REQUEST['csrf_token'] ?? '')) { $_SESSION['error'] = 'Sesi berakhir. Silakan muat ulang halaman dan coba lagi.'; header('Location: ' . BASE_URL . 'pages/gaji_pokok'); exit(); }

$id = $_REQUEST['id'] ?? 0;
$sql = "DELETE FROM gaji_pokok WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
if ($stmt->execute()) {
    gajiLogActivity($conn, "Menghapus data gaji pokok", 'danger');
    $_SESSION['success'] = "Data gaji pokok berhasil dihapus";
} else {
    $_SESSION['error'] = "Gagal menghapus data gaji pokok";
}
header('Location: ' . BASE_URL . 'pages/gaji_pokok');
exit();
?>




