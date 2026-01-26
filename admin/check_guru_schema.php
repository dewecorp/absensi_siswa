<?php
require_once __DIR__ . '/../config/database.php';

try {
    $stmt = $pdo->query("DESCRIBE tb_guru");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo $col['Field'] . " - " . $col['Type'] . "\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
