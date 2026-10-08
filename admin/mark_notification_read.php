<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in (samakan akses lonceng: admin/kepala + guru/wali)
if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha', 'guru', 'wali'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lvl = getUserLevel();
    if (isset($_POST['action']) && $_POST['action'] === 'mark_all') {
        try {
            $ukey = function_exists('get_current_user_key') ? get_current_user_key() : 'user_' . ($_SESSION['user_id'] ?? 0);
            $st = $pdo->prepare("SELECT id FROM tb_notifikasi WHERE created_at >= NOW() - INTERVAL 24 HOUR");
            $st->execute();
            $all_ids = $st->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($all_ids)) {
                $stIns = $pdo->prepare("INSERT IGNORE INTO tb_notifikasi_read (notif_id, user_key) VALUES (?, ?)");
                foreach ($all_ids as $nid) {
                    $stIns->execute([(int)$nid, $ukey]);
                }
            }
            echo json_encode(['status' => 'success']);
            exit();
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit();
        }
    } elseif (isset($_POST['id'])) {
        // Mark single as read
        $id = (int)$_POST['id'];
        if (markNotificationAsRead($pdo, $id)) {
            echo json_encode(['status' => 'success']);
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to update']);
        }
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
    }
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
}
?>
