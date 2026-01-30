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
            
            $stmt = $pdo->prepare("INSERT INTO tb_nilai_harian_header (id_guru, id_kelas, id_mapel, nama_penilaian) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$id_guru, $id_kelas, $id_mapel, $nama])) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error']);
            }
        } 
        elseif ($action == 'delete_column') {
            $id_header = $_POST['id_header'];
            
            // Verify ownership
            $check = $pdo->prepare("SELECT id_header FROM tb_nilai_harian_header WHERE id_header = ? AND id_guru = ?");
            $check->execute([$id_header, $id_guru]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM tb_nilai_harian_header WHERE id_header = ?");
            if ($stmt->execute([$id_header])) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error']);
            }
        }
        elseif ($action == 'save_grades') {
            $id_header = $_POST['id_header'];
            $grades = isset($_POST['grades']) ? $_POST['grades'] : [];
            
            // Verify ownership
            $check = $pdo->prepare("SELECT id_header FROM tb_nilai_harian_header WHERE id_header = ? AND id_guru = ?");
            $check->execute([$id_header, $id_guru]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            
            $pdo->beginTransaction();
            
            // Delete existing grades for this header (simplest way to handle updates/removals if we sent all, 
            // but here we only send non-empty. So better to upsert)
            // Actually, for simplicity, let's just loop and UPSERT
            
            $stmt = $pdo->prepare("
                INSERT INTO tb_nilai_harian_detail (id_header, id_siswa, nilai) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE nilai = VALUES(nilai)
            ");
            
            foreach ($grades as $g) {
                $stmt->execute([$id_header, $g['id_siswa'], $g['nilai']]);
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
