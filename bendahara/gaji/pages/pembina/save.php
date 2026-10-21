<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

header('Content-Type: application/json');

// Check if table exists, if not create it
$check_table = "SHOW TABLES LIKE 'pembina'";
$result = $conn->query($check_table);
if ($result->num_rows == 0) {
    // Create table
    $create_table = "CREATE TABLE IF NOT EXISTS pembina (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama_pembina VARCHAR(100) NOT NULL,
        ekstrakurikuler_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (ekstrakurikuler_id) REFERENCES ekstrakurikuler(id) ON DELETE CASCADE,
        INDEX idx_ekstrakurikuler (ekstrakurikuler_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($create_table);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success' => false, 'message' => 'Token tidak valid. Silakan refresh halaman.']); exit(); }
    try {
        $id = isset($_POST['id']) && $_POST['id'] != '' && $_POST['id'] != '0' ? intval($_POST['id']) : null;
        $nama_pembina = trim($_POST['nama_pembina'] ?? '');
        $ekstrakurikuler_id = intval($_POST['ekstrakurikuler_id'] ?? 0);
        
        if (empty($nama_pembina)) {
            echo json_encode(['success' => false, 'message' => 'Nama pembina tidak boleh kosong']);
            exit();
        }
        
        if ($ekstrakurikuler_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Pilih ekstrakurikuler']);
            exit();
        }
        
        if ($id) {
            $sql = "UPDATE pembina SET nama_pembina=?, ekstrakurikuler_id=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Gagal prepare query: ' . $conn->error);
            }
            $stmt->bind_param("sii", $nama_pembina, $ekstrakurikuler_id, $id);
        } else {
            $sql = "INSERT INTO pembina (nama_pembina, ekstrakurikuler_id) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Gagal prepare query: ' . $conn->error);
            }
            $stmt->bind_param("si", $nama_pembina, $ekstrakurikuler_id);
        }
        
        if ($stmt->execute()) {
            gajiLogActivity($conn, ($id ? 'Mengubah' : 'Menambah') . ' data pembina: ' . $nama_pembina, 'success');
            echo json_encode(['success' => true, 'message' => 'Data berhasil ' . ($id ? 'diubah' : 'ditambahkan')]);
        } else {
            throw new Exception('Gagal menyimpan data: ' . $stmt->error);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>

