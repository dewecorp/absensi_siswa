<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['siswa'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$id_siswa = (int)($_SESSION['user_id'] ?? 0);
$pesan_id = (int)($_POST['pesan_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pesan_id > 0 && $id_siswa > 0) {
    try {
        $stCls = $pdo->prepare("SELECT id_kelas FROM tb_siswa WHERE id_siswa = ?");
        $stCls->execute([$id_siswa]);
        $student_class_id = (int)$stCls->fetchColumn();

        $pdo->prepare("UPDATE tb_komunikasi_ortu SET status_dibaca = 'Sudah Dibaca' WHERE id = ? AND (id_siswa = ? OR (id_kelas = ? AND jenis_informasi = 'Pengumuman Kelas'))")->execute([$pesan_id, $id_siswa, $student_class_id]);
        echo json_encode(['status' => 'success', 'ok' => true]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
}
