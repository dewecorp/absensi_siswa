<?php
require_once __DIR__ . '/../config/database.php';

try {
    // Check if column exists
    $check = $pdo->query("SHOW COLUMNS FROM tb_nilai_harian_detail LIKE 'nilai_jadi'");
    if ($check->rowCount() == 0) {
        // Add column
        $pdo->exec("ALTER TABLE tb_nilai_harian_detail ADD COLUMN nilai_jadi INT NULL AFTER nilai");
        echo "Column 'nilai_jadi' added successfully.\n";
    } else {
        echo "Column 'nilai_jadi' already exists.\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>