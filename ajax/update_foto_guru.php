<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check if request is POST and has file
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['foto'])) {
    
    // Get teacher ID from session
    $teacher_id = 0;
    
    // Try to get teacher ID from different session variables
    if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_guru') {
        $teacher_id = $_SESSION['user_id'];
    } elseif (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
        // If logged in as user, find linked teacher
        $stmt = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $teacher_id = $stmt->fetchColumn();
    } elseif (isset($_SESSION['level']) && ($_SESSION['level'] == 'guru' || $_SESSION['level'] == 'wali')) {
        // Fallback for legacy sessions
        $teacher_id = $_SESSION['user_id']; // Assuming user_id is teacher_id for these roles
    }
    
    if (!$teacher_id) {
        echo json_encode(['success' => false, 'message' => 'Teacher not found']);
        exit;
    }

    $file = $_FILES['foto'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
    $max_size = 2 * 1024 * 1024; // 2MB

    // Validate file
    if (!in_array($file['type'], $allowed_types)) {
        echo json_encode(['success' => false, 'message' => 'Hanya file JPG, JPEG, dan PNG yang diperbolehkan.']);
        exit;
    }

    if ($file['size'] > $max_size) {
        echo json_encode(['success' => false, 'message' => 'Ukuran file maksimal 2MB.']);
        exit;
    }

    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'guru_' . $teacher_id . '_' . time() . '.' . $extension;
    $upload_dir = '../uploads/';
    $target_file = $upload_dir . $filename;

    // Create directory if not exists
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    // Get old photo to delete
    $stmt = $pdo->prepare("SELECT foto FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([$teacher_id]);
    $old_foto = $stmt->fetchColumn();

    if (move_uploaded_file($file['tmp_name'], $target_file)) {
        // Update database
        $update_stmt = $pdo->prepare("UPDATE tb_guru SET foto = ? WHERE id_guru = ?");
        if ($update_stmt->execute([$filename, $teacher_id])) {
            
            // Delete old photo if exists and is not default
            if ($old_foto && file_exists($upload_dir . $old_foto)) {
                unlink($upload_dir . $old_foto);
            }

            echo json_encode([
                'success' => true, 
                'message' => 'Foto berhasil diperbarui.',
                'new_image_url' => '../uploads/' . $filename
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal memperbarui database.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal mengupload file.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
}
?>