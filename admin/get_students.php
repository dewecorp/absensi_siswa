<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    header('HTTP/1.1 403 Forbidden');
    exit();
}

// Get class ID from GET parameter
$kelas_id = (int)($_GET['kelas_id'] ?? 0);

if ($kelas_id <= 0) {
    echo json_encode([]);
    exit();
}

try {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
    $stmt->execute([$kelas_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($students);
} catch (Exception $e) {
    echo json_encode([]);
}
?>