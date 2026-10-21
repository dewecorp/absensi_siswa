<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

if (!verifyCsrfToken($_REQUEST['csrf_token'] ?? '')) { $_SESSION['error'] = 'Sesi berakhir. Silakan muat ulang halaman dan coba lagi.'; header('Location: ' . BASE_URL . 'pages/tunjangan'); exit(); }

$id = $_REQUEST['id'] ?? 0;
$tunjangan_id = $_REQUEST['tunjangan_id'] ?? 0;

$sql = "DELETE FROM tunjangan_detail WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
if ($stmt->execute()) {
    gajiLogActivity($conn, "Menghapus detail tunjangan", 'danger');
    $_SESSION['success'] = "Data berhasil dihapus";
} else {
    $_SESSION['error'] = "Gagal menghapus data";
}

if ($tunjangan_id) {
    header('Location: ' . BASE_URL . 'pages/tunjangan/detail?tunjangan_id=' . $tunjangan_id);
} else {
    header('Location: ' . BASE_URL . 'pages/tunjangan');
}
exit();
?>




