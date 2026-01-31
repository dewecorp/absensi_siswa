<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check auth
if (!isAuthorized(['guru', 'wali'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Get logged in teacher ID
    $id_guru = $_SESSION['user_id'];
    if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
        $stmt = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $id_guru = $stmt->fetchColumn();
    }

    try {
        if ($action == 'add_column') {
            $id_kelas = $_POST['id_kelas'];
            $id_mapel = $_POST['id_mapel'];
            $nama = $_POST['nama_penilaian'];
            $jenis_kegiatan = $_POST['jenis_kegiatan'] ?? null;
            $tgl_kegiatan = !empty($_POST['tgl_kegiatan']) ? $_POST['tgl_kegiatan'] : null;
            
            // Get active semester info
            $school_profile = getSchoolProfile($pdo);
            $tahun_ajaran = $school_profile['tahun_ajaran'];
            $semester = $school_profile['semester'];
            
            $stmt = $pdo->prepare("INSERT INTO tb_nilai_kokurikuler_header (id_guru, id_kelas, id_mapel, nama_penilaian, jenis_kegiatan, tgl_kegiatan, tahun_ajaran, semester) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$id_guru, $id_kelas, $id_mapel, $nama, $jenis_kegiatan, $tgl_kegiatan, $tahun_ajaran, $semester])) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error']);
            }
        } 
        elseif ($action == 'delete_column') {
            $id_header = $_POST['id_header'];
            
            // Verify ownership
            $check = $pdo->prepare("SELECT id_header FROM tb_nilai_kokurikuler_header WHERE id_header = ? AND id_guru = ?");
            $check->execute([$id_header, $id_guru]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM tb_nilai_kokurikuler_header WHERE id_header = ?");
            if ($stmt->execute([$id_header])) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error']);
            }
        }
        elseif ($action == 'save_grades') {
            $id_header = $_POST['id_header'];
            $grades = isset($_POST['grades']) ? $_POST['grades'] : [];
            $jenis_kegiatan = isset($_POST['jenis_kegiatan']) ? $_POST['jenis_kegiatan'] : null;
            $tgl_kegiatan = !empty($_POST['tgl_kegiatan']) ? $_POST['tgl_kegiatan'] : null;
            
            // Verify ownership
            $check = $pdo->prepare("SELECT id_header FROM tb_nilai_kokurikuler_header WHERE id_header = ? AND id_guru = ?");
            $check->execute([$id_header, $id_guru]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            
            $pdo->beginTransaction();
            
            // Update Header info if provided
            // We update if they are not null (checking specific update request)
            if ($jenis_kegiatan !== null || $tgl_kegiatan !== null) {
                // If only one is provided, we fetch existing to not overwrite with null?
                // Actually the frontend should send both if editing the header.
                // But let's handle them carefully.
                
                $updateFields = [];
                $params = [];
                
                if (isset($_POST['jenis_kegiatan'])) {
                    $updateFields[] = "jenis_kegiatan = ?";
                    $params[] = $jenis_kegiatan;
                }
                
                if (isset($_POST['tgl_kegiatan'])) {
                    $updateFields[] = "tgl_kegiatan = ?";
                    $params[] = $tgl_kegiatan;
                }
                
                if (!empty($updateFields)) {
                    $params[] = $id_header;
                    $sql = "UPDATE tb_nilai_kokurikuler_header SET " . implode(', ', $updateFields) . " WHERE id_header = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                }
            }
            
            // Upsert grades
            // We need to check if there is a unique constraint on (id_header, id_siswa).
            // Usually detail tables should have unique(id_header, id_siswa).
            // Let's assume the previous schema setup or standard practice allows ON DUPLICATE KEY UPDATE 
            // BUT wait, I created the table with just `INDEX (id_siswa)`, not UNIQUE.
            // Let me check my table creation script.
            
            // In setup_kokurikuler_tables.php:
            // id_detail INT AUTO_INCREMENT PRIMARY KEY
            // id_header ...
            // id_siswa ...
            // No UNIQUE constraint on (id_header, id_siswa).
            // This means ON DUPLICATE KEY UPDATE won't work based on id_header+id_siswa unless I add a unique index.
            
            // Fix: Add unique index if not exists or handle via SELECT then INSERT/UPDATE.
            // Better: Add unique index now.
            
            // However, to be safe without altering table now (though I should), I will do check-insert-update.
            // OR I can add the unique index. I'll add the unique index in a separate step or just handle it in code.
            // Handling in code is safer if I can't guarantee schema changes.
            
            $stmtCheck = $pdo->prepare("SELECT id_detail FROM tb_nilai_kokurikuler_detail WHERE id_header = ? AND id_siswa = ?");
            $stmtUpdate = $pdo->prepare("UPDATE tb_nilai_kokurikuler_detail SET nilai = ?, nilai_jadi = ? WHERE id_detail = ?");
            $stmtInsert = $pdo->prepare("INSERT INTO tb_nilai_kokurikuler_detail (id_header, id_siswa, nilai, nilai_jadi) VALUES (?, ?, ?, ?)");
            
            foreach ($grades as $g) {
                $nilai = isset($g['nilai']) && $g['nilai'] !== '' ? $g['nilai'] : 0;
                $nilai_jadi = isset($g['nilai_jadi']) && $g['nilai_jadi'] !== '' ? $g['nilai_jadi'] : 0;
                
                $stmtCheck->execute([$id_header, $g['id_siswa']]);
                $existingId = $stmtCheck->fetchColumn();
                
                if ($existingId) {
                    $stmtUpdate->execute([$nilai, $nilai_jadi, $existingId]);
                } else {
                    $stmtInsert->execute([$id_header, $g['id_siswa'], $nilai, $nilai_jadi]);
                }
            }
            
            $pdo->commit();
            echo json_encode(['success' => true]);
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
?>
