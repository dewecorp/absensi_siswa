<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAuthorized(['admin'])) {
    die('Unauthorized');
}

$tables = [
    'tb_kategori_anggaran',
    'tb_sumber_anggaran',
    'tb_rencana_pengeluaran',
    'tb_sumber_ekstra',
    'tb_pengeluaran_ekstra',
    'tb_pengeluaran_ujian'
];

$sql_dump = "-- RAB Database Dump\n";
$sql_dump .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n\n";
$sql_dump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

foreach ($tables as $table) {
    // Check if table exists
    try {
        $check = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($check->rowCount() == 0) {
            continue;
        }
    } catch (Exception $e) {
        continue;
    }

    // Get Create Table structure
    $stmt = $pdo->query("SHOW CREATE TABLE $table");
    $row = $stmt->fetch(PDO::FETCH_NUM);
    
    $sql_dump .= "-- Table structure for table `$table`\n";
    $sql_dump .= "DROP TABLE IF EXISTS `$table`;\n";
    $sql_dump .= $row[1] . ";\n\n";

    // Get Table Data
    $stmt = $pdo->query("SELECT * FROM $table");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) > 0) {
        $sql_dump .= "-- Dumping data for table `$table`\n";
        $sql_dump .= "INSERT INTO `$table` (";
        
        // Column names
        $columns = array_keys($rows[0]);
        $sql_dump .= implode(', ', array_map(function($col) { return "`$col`"; }, $columns));
        $sql_dump .= ") VALUES \n";

        $values = [];
        foreach ($rows as $row) {
            $row_values = [];
            foreach ($row as $value) {
                if (is_null($value)) {
                    $row_values[] = "NULL";
                } else {
                    $row_values[] = "'" . addslashes($value) . "'";
                }
            }
            $values[] = "(" . implode(', ', $row_values) . ")";
        }
        $sql_dump .= implode(",\n", $values) . ";\n\n";
    }
}

$sql_dump .= "SET FOREIGN_KEY_CHECKS=1;\n";

// Download file
$filename = 'db_rab_backup_' . date('Ymd_His') . '.sql';

header('Content-Type: application/octet-stream');
header("Content-Transfer-Encoding: Binary"); 
header("Content-disposition: attachment; filename=\"$filename\""); 
echo $sql_dump;
exit;
