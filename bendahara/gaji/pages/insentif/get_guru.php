<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();
header('Content-Type: application/json');

$insentif_id = $_GET['insentif_id'] ?? 0;

if (empty($insentif_id) || !is_numeric($insentif_id)) {
    echo json_encode(['guru_ids' => []]);
    exit();
}

$conn->query("CREATE TABLE IF NOT EXISTS insentif_detail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guru_id INT NOT NULL,
    insentif_id INT NOT NULL,
    jumlah DECIMAL(15,2) NOT NULL DEFAULT 0,
    periode VARCHAR(7) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_guru_id (guru_id),
    INDEX idx_insentif_id (insentif_id),
    CONSTRAINT fk_insentif_detail_guru FOREIGN KEY (guru_id) REFERENCES guru(id) ON DELETE CASCADE,
    CONSTRAINT fk_insentif_detail_insentif FOREIGN KEY (insentif_id) REFERENCES insentif(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$sql = "SELECT DISTINCT guru_id FROM insentif_detail WHERE insentif_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $insentif_id);
$stmt->execute();
$result = $stmt->get_result();

$guru_ids = [];
while ($row = $result->fetch_assoc()) {
    $guru_ids[] = intval($row['guru_id']);
}

$stmt->close();
echo json_encode(['guru_ids' => $guru_ids]);
?>

