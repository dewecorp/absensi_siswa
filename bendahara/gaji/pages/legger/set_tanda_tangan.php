<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

$id = $_GET['id'] ?? 0;
$sql = "UPDATE legger_gaji SET tanda_tangan = 1 WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
if ($stmt->execute()) {
    gajiLogActivity($conn, "Menandatangani legger gaji", 'success');
    $_SESSION['success'] = "Legger gaji berhasil ditandatangani";
} else {
    $_SESSION['error'] = "Gagal menandatangani legger gaji";
}
header('Location: ' . BASE_URL . 'pages/legger');
exit();
?>




