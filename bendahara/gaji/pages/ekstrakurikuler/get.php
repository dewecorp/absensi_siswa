<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

header('Content-Type: application/json');

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit();
}

$sql = "SELECT * FROM ekstrakurikuler WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode(['success' => true, 'data' => $row]);
} else {
    echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
}
$stmt->close();
?>


