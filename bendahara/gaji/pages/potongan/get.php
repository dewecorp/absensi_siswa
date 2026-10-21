<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
header('Content-Type: application/json');
$id = $_GET['id'] ?? 0;

if (empty($id) || !is_numeric($id)) {
    echo json_encode(['error' => 'ID tidak valid']);
    exit();
}

$sql = "SELECT id, nama_potongan, jumlah_potongan, aktif FROM potongan WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $data = $result->fetch_assoc();
    // Ensure all fields have values
    $data['jumlah_potongan'] = isset($data['jumlah_potongan']) ? floatval($data['jumlah_potongan']) : 0;
    $data['aktif'] = isset($data['aktif']) ? intval($data['aktif']) : 0;
    $data['id'] = intval($data['id']);
    echo json_encode($data);
} else {
    echo json_encode(['error' => 'Data tidak ditemukan']);
}
$stmt->close();
?>

