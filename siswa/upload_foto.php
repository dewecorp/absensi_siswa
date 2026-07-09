<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['siswa'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['foto'])) {
    $id_siswa = $_SESSION['user_id'];
    $file = $_FILES['foto'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        echo json_encode(['status' => 'error', 'message' => 'Upload tidak valid.']);
        exit;
    }
    
    // Validate file
    $allowed_extensions = ['jpg', 'jpeg', 'png'];
    $allowed_mimes = ['image/jpeg', 'image/png'];
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $finfo->file($file['tmp_name']);
    
    if (!in_array($file_extension, $allowed_extensions, true) || !in_array($mime_type, $allowed_mimes, true)) {
        echo json_encode(['status' => 'error', 'message' => 'Format file tidak didukung. Gunakan JPG atau PNG.']);
        exit;
    }
    
    if ($file['size'] > 2 * 1024 * 1024) { // 2MB limit
        echo json_encode(['status' => 'error', 'message' => 'Ukuran file terlalu besar. Maksimal 2MB.']);
        exit;
    }
    
    // Create directory if not exists
    $upload_dir = '../assets/img/siswa/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Generate new filename
    $new_filename = 'siswa_' . $id_siswa . '_' . time() . '.' . $file_extension;
    $target_path = $upload_dir . $new_filename;
    
    // Get old photo to delete later
    $stmt = $pdo->prepare("SELECT foto FROM tb_siswa WHERE id_siswa = ?");
    $stmt->execute([$id_siswa]);
    $old_foto = $stmt->fetchColumn();
    
    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        // Update database
        $stmt = $pdo->prepare("UPDATE tb_siswa SET foto = ? WHERE id_siswa = ?");
        if ($stmt->execute([$new_filename, $id_siswa])) {
            // Delete old photo if exists and is not default
            if ($old_foto && file_exists($upload_dir . $old_foto)) {
                @unlink($upload_dir . $old_foto);
            }
            echo json_encode(['status' => 'success', 'message' => 'Foto profil berhasil diperbarui!', 'new_foto' => $new_filename]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Gagal memperbarui database.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Gagal mengupload file.']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Request tidak valid.']);
}
