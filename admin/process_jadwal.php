<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check auth
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_schedule'])) {
    $kelas_id = (int)$_POST['kelas_id'];
    $jenis = $_POST['jenis']; // Reguler or Ramadhan
    $schedules = $_POST['schedule']; // Array [day][jam_ke] => [mapel, guru]

    // Begin transaction
    $pdo->beginTransaction();

    try {
        // Prepare statements
        $deleteStmt = $pdo->prepare("DELETE FROM tb_jadwal_pelajaran WHERE kelas_id = ? AND jenis = ?");
        $insertStmt = $pdo->prepare("INSERT INTO tb_jadwal_pelajaran (kelas_id, hari, jam_ke, mapel_id, guru_id, jenis) VALUES (?, ?, ?, ?, ?, ?)");

        // Clear existing schedule for this class/jenis (Simplest approach: delete all and re-insert non-empty)
        // Or better: UPSERT. But deleting all for the class is cleaner if we are submitting the full grid.
        $deleteStmt->execute([$kelas_id, $jenis]);

        foreach ($schedules as $day => $jams) {
            foreach ($jams as $jam_ke => $data) {
                $mapel_id = $data['mapel'] ?? '';
                $guru_id = $data['guru'] ?? '';

                if (!empty($mapel_id) && !empty($guru_id)) {
                    $insertStmt->execute([
                        $kelas_id,
                        $day,
                        $jam_ke,
                        $mapel_id,
                        $guru_id,
                        $jenis
                    ]);
                }
            }
        }

        $pdo->commit();
        
        $redirect_url = ($jenis == 'Reguler') ? 'jadwal_reguler.php' : 'jadwal_ramadhan.php';
        header("Location: $redirect_url?kelas_id=$kelas_id&status=success");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        echo "Error: " . $e->getMessage();
        // In production, redirect with error message
        $redirect_url = ($jenis == 'Reguler') ? 'jadwal_reguler.php' : 'jadwal_ramadhan.php';
        header("Location: $redirect_url?kelas_id=$kelas_id&status=error&msg=" . urlencode($e->getMessage()));
        exit;
    }
} else {
    redirect('dashboard.php');
}
?>