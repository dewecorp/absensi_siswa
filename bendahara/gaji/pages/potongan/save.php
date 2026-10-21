<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

// Set JSON header for AJAX response
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$request = $_POST;

if ($method !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
    exit();
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success' => false, 'message' => 'Token tidak valid. Silakan refresh halaman.']); exit(); }

if (!empty($request)) {
    // Parse ID - handle both string and integer
    $id_raw = $request['id'] ?? null;
    $id = null;
    if ($id_raw !== null && $id_raw !== '' && $id_raw !== '0') {
        $id = intval($id_raw);
        if ($id <= 0) {
            $id = null;
        }
    }
    
    $nama_potongan = trim($request['nama_potongan'] ?? '');
    
    // Get jumlah_potongan from hidden input first, fallback to regular input
    $jumlah_potongan_raw = $request['jumlah_potongan_hidden'] ?? $request['jumlah_potongan'] ?? '0';
    
    // Clean and convert to float
    // Remove all non-numeric characters except dot
    $jumlah_potongan_cleaned = preg_replace('/[^0-9.]/', '', $jumlah_potongan_raw);
    // Replace comma with dot if any
    $jumlah_potongan_cleaned = str_replace(',', '.', $jumlah_potongan_cleaned);
    // Convert to float
    $jumlah_potongan = floatval($jumlah_potongan_cleaned);
    
    $aktif = !empty($request['aktif']) ? 1 : 0;
    
    // Validation
    if (empty($nama_potongan)) {
        echo json_encode([
            'success' => false,
            'message' => 'Nama potongan tidak boleh kosong'
        ]);
        exit();
    }
    
    // Ensure jumlah_potongan column exists
    $sql = "SHOW COLUMNS FROM potongan LIKE 'jumlah_potongan'";
    $result = $conn->query($sql);
    if ($result->num_rows == 0) {
        $conn->query("ALTER TABLE potongan ADD COLUMN jumlah_potongan DECIMAL(15,2) DEFAULT 0");
    }
    
    if ($id && $id > 0) {
        // Update existing record
        $sql = "UPDATE potongan SET nama_potongan=?, jumlah_potongan=?, aktif=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode([
                'success' => false,
                'message' => 'Gagal menyiapkan query: ' . $conn->error
            ]);
            exit();
        }
        $stmt->bind_param("sdii", $nama_potongan, $jumlah_potongan, $aktif, $id);
        $action = 'mengubah';
    } else {
        // Insert new record
        $sql = "INSERT INTO potongan (nama_potongan, jumlah_potongan, aktif) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode([
                'success' => false,
                'message' => 'Gagal menyiapkan query: ' . $conn->error
            ]);
            exit();
        }
        $stmt->bind_param("sdi", $nama_potongan, $jumlah_potongan, $aktif);
        $action = 'menambah';
    }
    
    $execute_result = $stmt->execute();
    
    if (!$execute_result) {
        $error_msg = $stmt->error ? $stmt->error : "Database error";
        echo json_encode([
            'success' => false,
            'message' => 'Gagal ' . ($id ? 'mengubah' : 'menambah') . ' data potongan: ' . $error_msg
        ]);
        $stmt->close();
        exit();
    }
    
    if ($execute_result) {
        $potongan_id = $id ? $id : $conn->insert_id;
        
        // Get periode from request (do not depend on settings.periode_aktif)
        $periode = $request['periode'] ?? date('Y-m');
        
        // Get selected guru IDs - handle both array and indexed array formats
        $guru_ids = [];
        
        // Check for guru_ids[] array format (standard PHP array)
        if (isset($request['guru_ids']) && is_array($request['guru_ids'])) {
            $guru_ids = $request['guru_ids'];
        }
        
        // If still empty, try to find any guru_ids keys
        if (empty($guru_ids)) {
            foreach ($request as $key => $value) {
                if (strpos($key, 'guru_ids') !== false) {
                    if (is_array($value)) {
                        $guru_ids = array_merge($guru_ids, $value);
                    } else {
                        $guru_ids[] = $value;
                    }
                }
            }
        }
        
        // Clean and validate guru IDs
        $guru_ids = array_filter(array_map('intval', $guru_ids), function($id) {
            return $id > 0;
        });
        $guru_ids = array_values($guru_ids); // Re-index array
        
        // Validate: at least one guru must be selected
        if (empty($guru_ids)) {
            echo json_encode([
                'success' => false,
                'message' => 'Pilih minimal satu guru untuk potongan ini'
            ]);
            $stmt->close();
            exit();
        }
        
        // Delete existing potongan_detail untuk potongan ini (semua periode)
        // Karena potongan sekarang tidak bergantung periode, kita reset semua detail
        $sql = "DELETE FROM potongan_detail WHERE potongan_id = ?";
        $delete_stmt = $conn->prepare($sql);
        $delete_stmt->bind_param("i", $potongan_id);
        $delete_stmt->execute();
        $delete_stmt->close();
        
        // Insert potongan_detail for each selected guru
        if (!empty($guru_ids)) {
            $sql = "INSERT INTO potongan_detail (guru_id, potongan_id, jumlah, periode) VALUES (?, ?, ?, ?)";
            $detail_stmt = $conn->prepare($sql);
            
            $inserted_count = 0;
            foreach ($guru_ids as $guru_id) {
                $guru_id = intval($guru_id);
                if ($guru_id > 0) {
                    $detail_stmt->bind_param("iids", $guru_id, $potongan_id, $jumlah_potongan, $periode);
                    if ($detail_stmt->execute()) {
                        $inserted_count++;
                    }
                }
            }
            $detail_stmt->close();
        }
        
        gajiLogActivity($conn, "{$action} potongan: {$nama_potongan}", 'success');
        echo json_encode([
            'success' => true,
            'message' => 'Data potongan berhasil ' . ($id ? 'diubah' : 'ditambahkan')
        ]);
    } else {
        $error_msg = $stmt->error ? $stmt->error : "Database error";
        echo json_encode([
            'success' => false,
            'message' => 'Gagal ' . ($id ? 'mengubah' : 'menambah') . ' data potongan: ' . $error_msg
        ]);
    }
    $stmt->close();
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
}
exit();
?>
