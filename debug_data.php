<?php
require_once 'config/database.php';

echo "TB_JAM_MENGAJAR:\n";
$stmt = $pdo->query("SELECT * FROM tb_jam_mengajar WHERE jenis='Reguler' ORDER BY jam_ke");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo json_encode($r) . "\n";
}

echo "\nTB_JADWAL_PELAJARAN (Sample):\n";
$stmt = $pdo->query("SELECT * FROM tb_jadwal_pelajaran WHERE jenis='Reguler' LIMIT 5");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo json_encode($r) . "\n";
}
?>