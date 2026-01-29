<?php
require_once 'config/database.php';

try {
    // Check if column 'nama' exists
    $stmt = $pdo->query("SHOW COLUMNS FROM tb_pengguna LIKE 'nama'");
    $exists = $stmt->fetch();

    if (!$exists) {
        // Add column 'nama' after 'username'
        $pdo->exec("ALTER TABLE tb_pengguna ADD COLUMN nama VARCHAR(100) NOT NULL DEFAULT '' AFTER username");
        echo "Column 'nama' added successfully to tb_pengguna.";
        
        // Update existing users to have nama = username temporarily
        $pdo->exec("UPDATE tb_pengguna SET nama = username WHERE nama = ''");
        echo " Updated existing users' nama to match username.";
    } else {
        echo "Column 'nama' already exists in tb_pengguna.";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>