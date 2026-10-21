<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

if (!verifyCsrfToken($_REQUEST['csrf_token'] ?? '')) { $_SESSION['error'] = 'Sesi berakhir. Silakan muat ulang halaman dan coba lagi.'; header('Location: ' . BASE_URL . 'pages/potongan'); exit(); }

$id = $_REQUEST['id'] ?? 0;
$potongan_id = $_REQUEST['potongan_id'] ?? 0;

$sql = "DELETE FROM potongan_detail WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
if ($stmt->execute()) {
    gajiLogActivity($conn, "Menghapus detail potongan", 'danger');
    $_SESSION['success'] = "Data berhasil dihapus";
} else {
    $_SESSION['error'] = "Gagal menghapus data";
}

if ($potongan_id) {
    header('Location: ' . BASE_URL . 'pages/potongan/detail?potongan_id=' . $potongan_id);
} else {
    header('Location: ' . BASE_URL . 'pages/potongan');
}
exit();
?>




