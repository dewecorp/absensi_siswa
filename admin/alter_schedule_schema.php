<?php
require_once __DIR__ . '/../config/database.php';

try {
    // Modify columns to allow NULL
    $sql = "ALTER TABLE tb_jadwal_pelajaran MODIFY mapel_id INT NULL";
    $pdo->exec($sql);
    echo "mapel_id modified to NULL.\n";

    $sql = "ALTER TABLE tb_jadwal_pelajaran MODIFY guru_id INT NULL";
    $pdo->exec($sql);
    echo "guru_id modified to NULL.\n";

    echo "Schema updated successfully.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
