<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
header('Content-Type: application/json');
$id = $_GET['id'] ?? 0;

if (empty($id) || !is_numeric($id)) {
    echo json_encode(['error' => 'ID tidak valid']);
    exit();
}

$sql = "SELECT id, guru_id, jumlah FROM gaji_pokok WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $data = $result->fetch_assoc();
    // Ensure all fields have values
    $data['guru_id'] = $data['guru_id'] ?? 0;
    $data['jumlah'] = $data['jumlah'] ?? 0;
    echo json_encode($data);
} else {
    echo json_encode(['error' => 'Data tidak ditemukan']);
}
?>

