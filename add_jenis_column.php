<?php
require_once 'config/database.php';

try {
    // Check if column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM tb_jurnal LIKE 'jenis'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE tb_jurnal ADD COLUMN jenis ENUM('Reguler', 'Ramadhan') DEFAULT 'Reguler' AFTER jam_ke");
        echo "Column 'jenis' added successfully.";
    } else {
        echo "Column 'jenis' already exists.";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
