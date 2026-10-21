<?php
// Prevent any output before JSON
ob_start();

require_once __DIR__ . '/../../config/config.php';
requireLogin();

// Clear any output buffer
ob_clean();

header('Content-Type: application/json');

// Check if table exists, if not create it
$check_table = "SHOW TABLES LIKE 'honor'";
$result = $conn->query($check_table);
if ($result->num_rows == 0) {
    // Create table
    $create_table = "CREATE TABLE IF NOT EXISTS honor (
        id INT AUTO_INCREMENT PRIMARY KEY,
        jabatan VARCHAR(100) NOT NULL,
        pembina_id INT NULL,
        jumlah_honor DECIMAL(15,2) NOT NULL DEFAULT 0,
        jumlah_pertemuan INT NOT NULL DEFAULT 0,
        aktif TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (pembina_id) REFERENCES pembina(id) ON DELETE SET NULL,
        INDEX idx_pembina (pembina_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($create_table);
} else {
    // Ensure columns exist (for backward compatibility)
    $db_name = DB_NAME;
    
    // Check and add pembina_id column if it doesn't exist
    $check_pembina_id = $conn->query("SELECT COUNT(*) as count 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = '$db_name' 
        AND TABLE_NAME = 'honor' 
        AND COLUMN_NAME = 'pembina_id'");
    if ($check_pembina_id && $check_pembina_id->fetch_assoc()['count'] == 0) {
        $conn->query("ALTER TABLE honor ADD COLUMN pembina_id INT NULL AFTER jabatan");
        // Try to add index (foreign key will be added separately if needed)
        $conn->query("ALTER TABLE honor ADD INDEX idx_pembina (pembina_id)");
    }
    
    // Check and add jumlah_pertemuan column if it doesn't exist
    $check_jumlah_pertemuan = $conn->query("SELECT COUNT(*) as count 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = '$db_name' 
        AND TABLE_NAME = 'honor' 
        AND COLUMN_NAME = 'jumlah_pertemuan'");
    if ($check_jumlah_pertemuan && $check_jumlah_pertemuan->fetch_assoc()['count'] == 0) {
        $conn->query("ALTER TABLE honor ADD COLUMN jumlah_pertemuan INT NOT NULL DEFAULT 0 AFTER jumlah_honor");
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success' => false, 'message' => 'Token tidak valid. Silakan refresh halaman.']); exit(); }
    try {
        // Check both 'id' and 'honor_id' for backward compatibility
        $id = null;
        if (isset($_POST['id']) && $_POST['id'] != '' && $_POST['id'] != '0') {
            $id = intval($_POST['id']);
        } elseif (isset($_POST['honor_id']) && $_POST['honor_id'] != '' && $_POST['honor_id'] != '0') {
            $id = intval($_POST['honor_id']);
        }
        $pembina_id = isset($_POST['pembina_id']) && $_POST['pembina_id'] != '' ? intval($_POST['pembina_id']) : null;
        $jabatan = trim($_POST['jabatan'] ?? '');
        // Get jumlah_honor from hidden input if available, otherwise from jumlah_honor field
        $jumlah_honor_raw = $_POST['jumlah_honor_hidden'] ?? $_POST['jumlah_honor'] ?? '0';
        // Clean number: remove dots and commas
        $jumlah_honor_cleaned = preg_replace('/[^0-9]/', '', $jumlah_honor_raw);
        $jumlah_honor = floatval($jumlah_honor_cleaned);
        $jumlah_pertemuan = intval($_POST['jumlah_pertemuan'] ?? 0);
        $aktif = isset($_POST['aktif']) ? 1 : 0;
        
        if (empty($jabatan)) {
            echo json_encode(['success' => false, 'message' => 'Jabatan tidak boleh kosong']);
            exit();
        }
        
        if ($id) {
            $sql = "UPDATE honor SET pembina_id=?, jabatan=?, jumlah_honor=?, jumlah_pertemuan=?, aktif=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Gagal prepare query: ' . $conn->error);
            }
            $stmt->bind_param("isdiii", $pembina_id, $jabatan, $jumlah_honor, $jumlah_pertemuan, $aktif, $id);
        } else {
            $sql = "INSERT INTO honor (pembina_id, jabatan, jumlah_honor, jumlah_pertemuan, aktif) VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Gagal prepare query: ' . $conn->error);
            }
            $stmt->bind_param("isdii", $pembina_id, $jabatan, $jumlah_honor, $jumlah_pertemuan, $aktif);
        }
        
        if ($stmt->execute()) {
            gajiLogActivity($conn, ($id ? 'Mengubah' : 'Menambah') . ' data honor: ' . $jabatan, 'success');
            $stmt->close();
            
            // Clear output buffer before sending JSON
            ob_clean();
            echo json_encode([
                'success' => true, 
                'message' => 'Data berhasil ' . ($id ? 'diubah' : 'ditambahkan')
            ]);
            exit();
        } else {
            $error_msg = $stmt->error ? $stmt->error : "Database error";
            $stmt->close();
            
            // Clear output buffer before sending JSON
            ob_clean();
            echo json_encode([
                'success' => false,
                'message' => 'Gagal menyimpan data: ' . $error_msg
            ]);
            exit();
        }
    } catch (Exception $e) {
        // Clear output buffer before sending JSON
        ob_clean();
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage()
        ]);
        exit();
    }
} else {
    // Clear output buffer before sending JSON
    ob_clean();
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid request method'
    ]);
    exit();
}
?>

