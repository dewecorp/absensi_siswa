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
        // Guru/wali: hanya tandai notif tugas miliknya, jangan sentuh notif admin
        try {
            if (in_array($lvl, ['guru', 'wali'], true)) {
                $gid = function_exists('getCurrentGuruId') ? (int)getCurrentGuruId($pdo) : 0;
                $mine = function_exists('getTeacherTaskNotifications') ? getTeacherTaskNotifications($pdo, $gid, 200) : [];
                $ids = [];
                foreach ($mine as $m) {
                    if (empty($m['is_read'])) $ids[] = (int)$m['id'];
                }
                if (!empty($ids)) {
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $pdo->prepare("UPDATE tb_notifikasi SET is_read = 1 WHERE id IN ($in)");
                    $stmt->execute($ids);
                }
            } else {
                $stmt = $pdo->prepare("UPDATE tb_notifikasi SET is_read = 1");
                $stmt->execute();
            }
            echo json_encode(['status' => 'success']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    } elseif (isset($_POST['id'])) {
        // Mark single as read
        $id = (int)$_POST['id'];
        if (in_array($lvl, ['guru', 'wali'], true)) {
            $gid = function_exists('getCurrentGuruId') ? (int)getCurrentGuruId($pdo) : 0;
            $mine = function_exists('getTeacherTaskNotifications') ? getTeacherTaskNotifications($pdo, $gid, 200) : [];
            $allowed = false;
            foreach ($mine as $m) {
                if ((int)$m['id'] === $id) { $allowed = true; break; }
            }
            if (!$allowed) {
                echo json_encode(['status' => 'success']);
                exit();
            }
        }
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
