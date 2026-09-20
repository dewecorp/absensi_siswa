<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/supervisi.php';

header('Content-Type: application/json; charset=utf-8');

if (!isAuthorized(['kepala_madrasah'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Unauthorized']);
    exit;
}

sv_ensure_schema($pdo);

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($action === 'get_penilaian') {
    $id = (int)($_GET['id_pelaksanaan'] ?? $_POST['id_pelaksanaan'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => true, 'rows' => []]);
        exit;
    }
    try {
        $stmt = $pdo->prepare("SELECT id_indikator, skor, catatan FROM tb_sv_penilaian WHERE id_pelaksanaan = ? ORDER BY id_penilaian ASC");
        $stmt->execute([$id]);
        echo json_encode(['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Unknown action']);
