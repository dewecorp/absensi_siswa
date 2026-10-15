<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['siswa'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$id_siswa = (int)($_SESSION['user_id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
$pesan_id = (int)($_POST['pesan_id'] ?? 0);
$msg_type = trim((string)($_POST['msg_type'] ?? 'ortu'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id_siswa > 0) {
    try {
        $stCls = $pdo->prepare("SELECT id_kelas FROM tb_siswa WHERE id_siswa = ?");
        $stCls->execute([$id_siswa]);
        $student_class_id = (int)$stCls->fetchColumn();

        if ($action === 'mark_all') {
            // Tandai semua komunikasi ortu milik siswa / pengumuman kelas sebagai Sudah Dibaca
            if ($student_class_id > 0) {
                $pdo->prepare("UPDATE tb_komunikasi_ortu SET status_dibaca = 'Sudah Dibaca' WHERE (id_siswa = ? OR (id_kelas = ? AND jenis_informasi = 'Pengumuman Kelas')) AND status_kirim = 'Terkirim'")->execute([$id_siswa, $student_class_id]);

                // Tandai semua komunikasi kelas yang belum dibaca
                $stKelasMsg = $pdo->prepare("SELECT id FROM tb_komunikasi_kelas WHERE id_kelas = ? AND status = 'Terkirim'");
                $stKelasMsg->execute([$student_class_id]);
                $allKm = $stKelasMsg->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($allKm)) {
                    $insRead = $pdo->prepare("INSERT INTO tb_komunikasi_kelas_read (id_komunikasi, id_siswa, dibaca_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE dibaca_at = NOW()");
                    foreach ($allKm as $kId) {
                        $insRead->execute([(int)$kId, $id_siswa]);
                    }
                }
            } else {
                $pdo->prepare("UPDATE tb_komunikasi_ortu SET status_dibaca = 'Sudah Dibaca' WHERE id_siswa = ? AND status_kirim = 'Terkirim'")->execute([$id_siswa]);
            }
            echo json_encode(['status' => 'success', 'ok' => true]);
            exit();
        }

        if ($pesan_id > 0) {
            if ($msg_type === 'kelas') {
                $pdo->prepare("INSERT INTO tb_komunikasi_kelas_read (id_komunikasi, id_siswa, dibaca_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE dibaca_at = NOW()")->execute([$pesan_id, $id_siswa]);
            } else {
                $pdo->prepare("UPDATE tb_komunikasi_ortu SET status_dibaca = 'Sudah Dibaca' WHERE id = ? AND (id_siswa = ? OR (id_kelas = ? AND jenis_informasi = 'Pengumuman Kelas'))")->execute([$pesan_id, $id_siswa, $student_class_id]);
            }
            echo json_encode(['status' => 'success', 'ok' => true]);
            exit();
        }

        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
}
