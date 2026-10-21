<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
header('Content-Type: application/json');

$potongan_id = $_GET['potongan_id'] ?? 0;

if (empty($potongan_id) || !is_numeric($potongan_id)) {
    echo json_encode(['guru_ids' => []]);
    exit();
}

// Get semua guru yang pernah memiliki potongan ini (dari periode manapun)
$sql = "SELECT DISTINCT guru_id FROM potongan_detail WHERE potongan_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $potongan_id);
$stmt->execute();
$result = $stmt->get_result();

$guru_ids = [];
while ($row = $result->fetch_assoc()) {
    $guru_ids[] = intval($row['guru_id']);
}

$stmt->close();

echo json_encode(['guru_ids' => $guru_ids]);
?>
