<?php
require_once 'config/database.php';

try {
    // Check if status column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM tb_siswa LIKE 'status'");
    if ($stmt->rowCount() === 0) {
        // Add status column
        $pdo->exec("
            ALTER TABLE tb_siswa 
            ADD COLUMN status ENUM('aktif', 'non-aktif', 'alumni') NOT NULL DEFAULT 'aktif' AFTER password
        ");
        echo "Status column added successfully!\n";
    } else {
        echo "Status column already exists.\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
