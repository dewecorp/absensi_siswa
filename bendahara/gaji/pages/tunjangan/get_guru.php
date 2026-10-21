<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
header('Content-Type: application/json');

$tunjangan_id = $_GET['tunjangan_id'] ?? 0;

if (empty($tunjangan_id) || !is_numeric($tunjangan_id)) {
    echo json_encode(['guru_ids' => []]);
    exit();
}

// Get semua guru yang pernah memiliki tunjangan ini (dari periode manapun)
$sql = "SELECT DISTINCT guru_id FROM tunjangan_detail WHERE tunjangan_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $tunjangan_id);
$stmt->execute();
$result = $stmt->get_result();

$guru_ids = [];
while ($row = $result->fetch_assoc()) {
    $guru_ids[] = intval($row['guru_id']);
}

$stmt->close();

echo json_encode(['guru_ids' => $guru_ids]);
?>



