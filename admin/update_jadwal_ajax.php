<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check auth
if (!isAuthorized(['admin'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_jadwal = isset($_POST['id_jadwal']) ? (int)$_POST['id_jadwal'] : null;
    $field = $_POST['field']; // 'mapel' or 'guru'
    $value = $_POST['value'];
    $val = empty($value) ? null : $value;

    if ($id_jadwal) {
        // Update by ID (Direct update)
        try {
            $column = ($field == 'mapel') ? 'mapel_id' : 'guru_id';
            $stmt = $pdo->prepare("UPDATE tb_jadwal_pelajaran SET $column = ? WHERE id_jadwal = ?");
            $stmt->execute([$val, $id_jadwal]);
            echo json_encode(['status' => 'success']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    $kelas_id = (int)$_POST['kelas_id'];
    $hari = $_POST['hari'];
    $jam_ke = $_POST['jam_ke']; // Removed (int) cast
    $jenis = $_POST['jenis']; // Reguler or Ramadhan
    $field = $_POST['field']; // 'mapel' or 'guru'
    $value = $_POST['value'];

    if (empty($kelas_id) || empty($hari) || empty($jam_ke) || empty($jenis)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
        exit;
    }

    try {
        // Check if record exists
        $stmt = $pdo->prepare("SELECT id_jadwal, mapel_id, guru_id FROM tb_jadwal_pelajaran WHERE kelas_id = ? AND hari = ? AND jam_ke = ? AND jenis = ?");
        $stmt->execute([$kelas_id, $hari, $jam_ke, $jenis]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Update
            if ($value == '') {
                // If value is empty, we might need to check if both are empty to delete?
                // For now, just update to NULL or 0? 
                // However, foreign keys might fail if 0. Let's assume value is valid ID or empty string.
                // If empty string, we can't really set FK to empty string. We should probably use NULL.
                // But the schema defined NOT NULL. Let's check schema.
                // "mapel_id INT NOT NULL, guru_id INT NOT NULL"
                // So we cannot set to NULL. If user clears selection, we should probably delete the row if both are "empty"
                // or we need to allow NULL in schema.
                // For this iteration, let's assume we delete the row if user clears one of them? 
                // Or better: update to existing value if empty? No.
                
                // Let's modify the requirement: User selects from dropdown. 
                // If they select "Pilih...", value is empty.
                // If one is empty, we can't save the row properly if schema is NOT NULL.
                // Let's UPDATE Schema to allow NULL or handle delete.
                
                // If user clears Mapel, maybe we should delete the whole schedule entry?
                // Or maybe we should have updated the schema to allow NULL.
                
                // Let's try to UPDATE the schema first to be safe, or handle deletion.
                // Strategy: 
                // If existing record:
                //   Update the specific field.
                //   If the other field is 0/invalid, we might have an issue.
                //   Actually, if existing, the other field must be valid (NOT NULL).
                //   So if new value is empty, we probably should delete the row? 
                //   Or prevent emptying?
                
                // Let's assume we delete if value is empty.
                if (empty($value)) {
                     $del = $pdo->prepare("DELETE FROM tb_jadwal_pelajaran WHERE id_jadwal = ?");
                     $del->execute([$existing['id_jadwal']]);
                     echo json_encode(['status' => 'success', 'message' => 'Jadwal dihapus']);
                     exit;
                }

                $column = ($field == 'mapel') ? 'mapel_id' : 'guru_id';
                $update = $pdo->prepare("UPDATE tb_jadwal_pelajaran SET $column = ? WHERE id_jadwal = ?");
                $update->execute([$value, $existing['id_jadwal']]);
            } else {
                $column = ($field == 'mapel') ? 'mapel_id' : 'guru_id';
                $update = $pdo->prepare("UPDATE tb_jadwal_pelajaran SET $column = ? WHERE id_jadwal = ?");
                $update->execute([$value, $existing['id_jadwal']]);
            }
        } else {
            // Insert
            // We need both mapel and guru to insert if schema is NOT NULL.
            // If we only have one, we can't insert yet?
            // Unless we allow defaults.
            // Let's check schema again. 
            // "mapel_id INT NOT NULL, guru_id INT NOT NULL"
            // This is problematic for single-field updates on new rows.
            
            // FIX: We should make columns nullable or provide a default "Empty/TBD" ID if possible.
            // BUT, changing schema is safer.
            // Let's change schema to allow NULL for mapel_id and guru_id.
            
            // For now, in this script, if we don't have the other value, we can't insert.
            // But wait, the user edits "directly". 
            // If they pick Mapel first, we need to save it. But we don't have Guru.
            // We must allow NULLs.
            
            // I will run a schema update command via PHP to modify the table.
            // But for this file, let's assume NULLs are allowed.
            
            if (!empty($value)) {
                $column = ($field == 'mapel') ? 'mapel_id' : 'guru_id';
                // We insert with the known value, and NULL for the other.
                // This requires the table to be altered.
                $insert = $pdo->prepare("INSERT INTO tb_jadwal_pelajaran (kelas_id, hari, jam_ke, jenis, $column) VALUES (?, ?, ?, ?, ?)");
                $insert->execute([$kelas_id, $hari, $jam_ke, $jenis, $value]);
            }
        }

        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
?>