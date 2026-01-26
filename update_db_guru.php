<?php
require_once 'config/database.php';

try {
    // Check if column exists first to avoid error
    $check = $pdo->query("SHOW COLUMNS FROM tb_guru LIKE 'kode_guru'");
    if ($check->rowCount() == 0) {
        $sql = "ALTER TABLE tb_guru ADD COLUMN kode_guru VARCHAR(50) AFTER id_guru";
        $pdo->exec($sql);
        echo "Column 'kode_guru' added successfully.\n";
    } else {
        echo "Column 'kode_guru' already exists.\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>