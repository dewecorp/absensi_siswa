<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// Check auth
if (!isAuthorized(['admin'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$kelas_id = isset($_POST['kelas_id']) ? (int)$_POST['kelas_id'] : null;
$hari = isset($_POST['hari']) ? $_POST['hari'] : null;
$guru_id = isset($_POST['guru_id']) ? (int)$_POST['guru_id'] : null;
$jenis = isset($_POST['jenis']) ? $_POST['jenis'] : 'Reguler';

if (!$kelas_id || !$hari) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit;
}

try {
    // Validate guru exists if provided
    if ($guru_id) {
        $stmt = $pdo->prepare("SELECT id_guru FROM tb_guru WHERE id_guru = ?");
        $stmt->execute([$guru_id]);
        if (!$stmt->fetch()) {
            throw new Exception("Guru not found");
        }
    }

    // Update all schedules for this class, day, and type
    $sql = "UPDATE tb_jadwal_pelajaran SET guru_id = ? WHERE kelas_id = ? AND hari = ? AND jenis = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$guru_id, $kelas_id, $hari, $jenis]);

    echo json_encode(['status' => 'success', 'message' => 'Guru updated for the day']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
