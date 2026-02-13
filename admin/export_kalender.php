<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

// Hanya izinkan admin yang menjalankan script ini
if (!isAuthorized(['admin'])) {
    die("Akses ditolak. Anda harus login sebagai Admin untuk mengekspor data ini.");
}

try {
    $stmt = $pdo->query("SELECT * FROM tb_kalender_pendidikan");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        die("Tidak ada data kalender di database lokal.");
    }

    $sql_inserts = [];
    foreach ($rows as $row) {
        $fields = array_keys($row);
        $values = array_map(function($v) use ($pdo) {
            if ($v === null) return 'NULL';
            return $pdo->quote($v);
        }, array_values($row));
        
        $sql_inserts[] = "INSERT INTO `tb_kalender_pendidikan` (`" . implode("`, `", $fields) . "`) VALUES (" . implode(", ", $values) . ");";
    }

    $filename = "export_kalender_" . date('Ymd_His') . ".sql";
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    echo "-- Export Data Kalender Pendidikan\n";
    echo "-- Tanggal: " . date('Y-m-d H:i:s') . "\n\n";
    echo "TRUNCATE TABLE `tb_kalender_pendidikan`;\n\n";
    echo implode("\n", $sql_inserts);
    exit;

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
