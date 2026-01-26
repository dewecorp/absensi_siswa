<?php
require_once 'config/database.php';

try {
    $stmt = $pdo->query("DESCRIBE tb_guru");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Columns in tb_guru: " . implode(", ", $columns) . "\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>