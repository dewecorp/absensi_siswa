<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
header('Content-Type: application/json');

$id = $_GET['id'] ?? 0;

if (empty($id) || !is_numeric($id)) {
    echo json_encode(['error' => 'ID tidak valid']);
    exit();
}

$conn->query("CREATE TABLE IF NOT EXISTS insentif (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_insentif VARCHAR(100) NOT NULL,
    jumlah_insentif DECIMAL(15,2) NOT NULL DEFAULT 0,
    kali INT UNSIGNED NOT NULL DEFAULT 1,
    aktif TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$chk_kali = $conn->query("SHOW COLUMNS FROM insentif LIKE 'kali'");
if ($chk_kali && $chk_kali->num_rows === 0) {
    $conn->query("ALTER TABLE insentif ADD COLUMN kali INT UNSIGNED NOT NULL DEFAULT 1 AFTER jumlah_insentif");
}

$sql = "SELECT id, nama_insentif, jumlah_insentif, kali, aktif FROM insentif WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $data = $result->fetch_assoc();
    $data['jumlah_insentif'] = isset($data['jumlah_insentif']) ? floatval($data['jumlah_insentif']) : 0;
    $data['kali'] = isset($data['kali']) ? max(1, (int)$data['kali']) : 1;
    $data['aktif'] = isset($data['aktif']) ? intval($data['aktif']) : 0;
    $data['id'] = intval($data['id']);
    echo json_encode($data);
} else {
    echo json_encode(['error' => 'Data tidak ditemukan']);
}
$stmt->close();
?>

