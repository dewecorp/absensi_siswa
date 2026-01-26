<?php
require_once __DIR__ . '/../config/database.php';
try {
    $stmt = $pdo->query("DESCRIBE tb_mata_pelajaran");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo $col['Field'] . "\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>