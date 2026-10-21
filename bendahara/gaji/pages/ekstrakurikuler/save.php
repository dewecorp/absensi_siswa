<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

header('Content-Type: application/json');

// Check if table exists, if not create it
$check_table = "SHOW TABLES LIKE 'ekstrakurikuler'";
$result = $conn->query($check_table);
if ($result->num_rows == 0) {
    // Create table
    $create_table = "CREATE TABLE IF NOT EXISTS ekstrakurikuler (
        id INT AUTO_INCREMENT PRIMARY KEY,
        jenis_ekstrakurikuler VARCHAR(100) NOT NULL,
        waktu VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($create_table);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success' => false, 'message' => 'Token tidak valid. Silakan refresh halaman.']); exit(); }
    try {
        $id = isset($_POST['id']) && $_POST['id'] != '' && $_POST['id'] != '0' ? intval($_POST['id']) : null;
        $jenis_ekstrakurikuler = trim($_POST['jenis_ekstrakurikuler'] ?? '');
        $waktu = trim($_POST['waktu'] ?? '');
        
        if (empty($jenis_ekstrakurikuler)) {
            echo json_encode(['success' => false, 'message' => 'Jenis ekstrakurikuler tidak boleh kosong']);
            exit();
        }
        
        if (empty($waktu)) {
            echo json_encode(['success' => false, 'message' => 'Waktu tidak boleh kosong']);
            exit();
        }
        
        if ($id) {
            // Update
            $sql = "UPDATE ekstrakurikuler SET jenis_ekstrakurikuler=?, waktu=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Gagal prepare query: ' . $conn->error);
            }
            $stmt->bind_param("ssi", $jenis_ekstrakurikuler, $waktu, $id);
        } else {
            // Insert
            $sql = "INSERT INTO ekstrakurikuler (jenis_ekstrakurikuler, waktu) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Gagal prepare query: ' . $conn->error);
            }
            $stmt->bind_param("ss", $jenis_ekstrakurikuler, $waktu);
        }
        
        if ($stmt->execute()) {
            gajiLogActivity($conn, ($id ? 'Mengubah' : 'Menambah') . ' data ekstrakurikuler: ' . $jenis_ekstrakurikuler, 'success');
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

