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
    $kelas_id = (int)$_POST['kelas_id'];
    $hari = $_POST['hari'];
    $jam_ke = $_POST['jam_ke']; // Removed (int) cast to support alphanumeric
    $jenis = $_POST['jenis'];
    $guru_id = isset($_POST['guru_id']) && !empty($_POST['guru_id']) ? (int)$_POST['guru_id'] : null;

    if (empty($kelas_id) || empty($hari) || empty($jam_ke) || empty($jenis)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
        exit;
    }

    try {
        // Check duplicate
        $stmt = $pdo->prepare("SELECT id_jadwal FROM tb_jadwal_pelajaran WHERE kelas_id = ? AND hari = ? AND jam_ke = ? AND jenis = ?");
        $stmt->execute([$kelas_id, $hari, $jam_ke, $jenis]);
        if ($stmt->fetch()) {
            http_response_code(409); // Conflict
            echo json_encode(['status' => 'error', 'message' => 'Jadwal jam ke-' . $jam_ke . ' sudah ada']);
            exit;
        }

        // Insert
        $insert = $pdo->prepare("INSERT INTO tb_jadwal_pelajaran (kelas_id, hari, jam_ke, jenis, guru_id) VALUES (?, ?, ?, ?, ?)");
        $insert->execute([$kelas_id, $hari, $jam_ke, $jenis, $guru_id]);
        $id = $pdo->lastInsertId();

        echo json_encode(['status' => 'success', 'id_jadwal' => $id]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
?>