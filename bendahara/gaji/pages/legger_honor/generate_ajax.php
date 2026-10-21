<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success' => false, 'message' => 'Token tidak valid. Silakan refresh halaman.']); exit(); }

// Get periode from POST or use current month (legger honor tidak ikut settingan periode gaji)
$periode = $_POST['periode'] ?? date('Y-m');

try {
    // Check if legger_honor table exists, if not create it
    $check_table = "SHOW TABLES LIKE 'legger_honor'";
    $result = $conn->query($check_table);
    if ($result->num_rows == 0) {
        $create_table = "CREATE TABLE IF NOT EXISTS legger_honor (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pembina_id INT NOT NULL,
            ekstrakurikuler_id INT NOT NULL,
            honor_id INT NOT NULL,
            jumlah_pertemuan INT NOT NULL DEFAULT 0,
            jumlah_honor_per_pertemuan DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_honor DECIMAL(15,2) NOT NULL DEFAULT 0,
            periode VARCHAR(7) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (pembina_id) REFERENCES pembina(id) ON DELETE CASCADE,
            FOREIGN KEY (ekstrakurikuler_id) REFERENCES ekstrakurikuler(id) ON DELETE CASCADE,
            FOREIGN KEY (honor_id) REFERENCES honor(id) ON DELETE CASCADE,
            INDEX idx_periode (periode),
            UNIQUE KEY unique_pembina_ekstra_periode (pembina_id, ekstrakurikuler_id, periode)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $conn->query($create_table);
    }
    
    // Get all pembina with their ekstrakurikuler
    $sql = "SELECT p.*, e.id as ekstrakurikuler_id
            FROM pembina p
            JOIN ekstrakurikuler e ON p.ekstrakurikuler_id = e.id";
    $result_pembina = $conn->query($sql);
    if (!$result_pembina) {
        throw new Exception('Gagal query pembina: ' . $conn->error);
    }
    $pembina = $result_pembina->fetch_all(MYSQLI_ASSOC);
    
    if (empty($pembina)) {
        throw new Exception('Tidak ada data pembina. Silakan tambahkan data pembina terlebih dahulu.');
    }
    
    $processed = 0;
    $errors = [];
    
    foreach ($pembina as $p) {
        try {
            // Get honor for this pembina (from honor table where pembina_id matches)
            // Get active honor first, if not found, get any honor for this pembina
            $sql_honor = "SELECT * FROM honor WHERE pembina_id = ? AND aktif = 1 LIMIT 1";
            $stmt_honor = $conn->prepare($sql_honor);
            if (!$stmt_honor) {
                throw new Exception('Gagal prepare query honor: ' . $conn->error);
            }
            $stmt_honor->bind_param("i", $p['id']);
            $stmt_honor->execute();
            $result_honor = $stmt_honor->get_result();
            $honor_data = $result_honor->fetch_assoc();
            $stmt_honor->close();
            
            // If no active honor found, try to get any honor for this pembina
            if (!$honor_data) {
                $sql_honor2 = "SELECT * FROM honor WHERE pembina_id = ? LIMIT 1";
                $stmt_honor2 = $conn->prepare($sql_honor2);
                if ($stmt_honor2) {
                    $stmt_honor2->bind_param("i", $p['id']);
                    $stmt_honor2->execute();
                    $result_honor2 = $stmt_honor2->get_result();
                    $honor_data = $result_honor2->fetch_assoc();
                    $stmt_honor2->close();
                }
            }
            
            if (!$honor_data) {
                throw new Exception('Tidak ada data honor untuk pembina ' . $p['nama_pembina'] . '. Silakan tambahkan data honor untuk pembina ini.');
            }
            
            // Get jumlah pertemuan from honor
            $jumlah_pertemuan = intval($honor_data['jumlah_pertemuan'] ?? 0);
            
            if ($jumlah_pertemuan <= 0) {
                throw new Exception('Jumlah pertemuan tidak valid untuk honor pembina ' . $p['nama_pembina']);
            }
            
            // Get honor amount per pertemuan
            $jumlah_honor_per_pertemuan = floatval($honor_data['jumlah_honor'] ?? 0);
            
            if ($jumlah_honor_per_pertemuan <= 0) {
                throw new Exception('Jumlah honor tidak valid untuk pembina ' . $p['nama_pembina']);
            }
            
            // Calculate total honor: jumlah_honor × jumlah_pertemuan
            $total_honor = $jumlah_honor_per_pertemuan * $jumlah_pertemuan;
            
            // Insert or update legger_honor
            $sql = "INSERT INTO legger_honor (pembina_id, ekstrakurikuler_id, honor_id, jumlah_pertemuan, jumlah_honor_per_pertemuan, total_honor, periode) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    jumlah_pertemuan=VALUES(jumlah_pertemuan),
                    jumlah_honor_per_pertemuan=VALUES(jumlah_honor_per_pertemuan),
                    total_honor=VALUES(total_honor)";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Gagal prepare query: ' . $conn->error);
            }
            
            // Store values in variables for bind_param (cannot pass function results directly)
            $pembina_id = intval($p['id']);
            $ekstrakurikuler_id = intval($p['ekstrakurikuler_id']);
            $honor_id = intval($honor_data['id']);
            $jml_pertemuan = intval($jumlah_pertemuan);
            $jml_honor_per_pertemuan = floatval($jumlah_honor_per_pertemuan);
            $total_honor_val = floatval($total_honor);
            
            $stmt->bind_param("iiiidds", 
                $pembina_id, 
                $ekstrakurikuler_id, 
                $honor_id,
                $jml_pertemuan,
                $jml_honor_per_pertemuan,
                $total_honor_val,
                $periode
            );
            
            if (!$stmt->execute()) {
                throw new Exception('Gagal insert/update untuk pembina ' . $p['nama_pembina'] . ': ' . $stmt->error);
            }
            $processed++;
            $stmt->close();
            
        } catch (Exception $e) {
            $errors[] = $p['nama_pembina'] . ': ' . $e->getMessage();
        }
    }
    
    if ($processed > 0) {
        gajiLogActivity($conn, "Generate legger honor periode {$periode} untuk {$processed} pembina", 'success');
    }
    
    $period_label = function_exists('getPeriodLabel') ? getPeriodLabel($periode) : $periode;
    
    if ($processed > 0) {
        $message = "Legger honor berhasil digenerate untuk {$processed} pembina pada periode {$period_label}";
        if (!empty($errors)) {
            $message .= ". Terdapat " . count($errors) . " error: " . implode(', ', array_slice($errors, 0, 3));
            if (count($errors) > 3) {
                $message .= " dan " . (count($errors) - 3) . " error lainnya";
            }
        }
    } else {
        $message = "Tidak ada data yang berhasil digenerate. ";
        if (!empty($errors)) {
            $message .= "Error: " . implode(', ', array_slice($errors, 0, 5));
            if (count($errors) > 5) {
                $message .= " dan " . (count($errors) - 5) . " error lainnya";
            }
        } else {
            $message .= "Pastikan data pembina dan honor sudah lengkap.";
        }
    }
    
    echo json_encode([
        'success' => $processed > 0,
        'message' => $message,
        'processed' => $processed,
        'errors' => $errors
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>

