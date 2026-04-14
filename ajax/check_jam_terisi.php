<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$tanggal = $_GET['tanggal'] ?? '';
$id_kelas = (int)($_GET['id_kelas'] ?? 0);
$jenis = $_GET['jenis'] ?? 'Reguler';
$id_jurnal_exclude = (int)($_GET['id_jurnal_exclude'] ?? 0);

if (empty($tanggal) || $id_kelas <= 0) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

try {
    $query = "SELECT jam_ke FROM tb_jurnal WHERE tanggal = ? AND id_kelas = ? AND jenis = ?";
    $params = [$tanggal, $id_kelas, $jenis];
    
    if ($id_jurnal_exclude > 0) {
        $query .= " AND id != ?";
        $params[] = $id_jurnal_exclude;
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $filled_jam = [];
    foreach ($rows as $row) {
        if (!empty($row['jam_ke'])) {
            $jam_arr = explode(',', $row['jam_ke']);
            foreach ($jam_arr as $jam) {
                $trimmed = trim($jam);
                if ($trimmed !== '' && !in_array($trimmed, $filled_jam)) {
                    $filled_jam[] = $trimmed;
                }
            }
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($filled_jam);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
