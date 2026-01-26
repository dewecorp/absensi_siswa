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
    $id_jadwal = (int)$_POST['id_jadwal'];

    if (empty($id_jadwal)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM tb_jadwal_pelajaran WHERE id_jadwal = ?");
        $stmt->execute([$id_jadwal]);

        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
?>