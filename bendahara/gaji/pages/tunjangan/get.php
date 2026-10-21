<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
header('Content-Type: application/json');
$id = $_GET['id'] ?? 0;

if (empty($id) || !is_numeric($id)) {
    echo json_encode(['error' => 'ID tidak valid']);
    exit();
}

$sql = "SELECT id, nama_tunjangan, jumlah_tunjangan, aktif FROM tunjangan WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $data = $result->fetch_assoc();
    // Ensure all fields have values
    $data['jumlah_tunjangan'] = isset($data['jumlah_tunjangan']) ? floatval($data['jumlah_tunjangan']) : 0;
    $data['aktif'] = isset($data['aktif']) ? intval($data['aktif']) : 0;
    $data['id'] = intval($data['id']);
    echo json_encode($data);
} else {
    echo json_encode(['error' => 'Data tidak ditemukan']);
}
$stmt->close();
?>

