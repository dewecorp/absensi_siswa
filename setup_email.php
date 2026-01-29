<?php
require_once 'config/database.php';

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Add email column to tb_guru
    try {
        $pdo->exec("ALTER TABLE tb_guru ADD COLUMN email VARCHAR(100) AFTER nama_guru");
        echo "Added email column to tb_guru.<br>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "Email column already exists in tb_guru.<br>";
        } else {
            throw $e;
        }
    }

    // Add email column to tb_siswa
    try {
        $pdo->exec("ALTER TABLE tb_siswa ADD COLUMN email VARCHAR(100) AFTER nama_siswa");
        echo "Added email column to tb_siswa.<br>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "Email column already exists in tb_siswa.<br>";
        } else {
            throw $e;
        }
    }

    // Add email column to tb_pengguna
    try {
        $pdo->exec("ALTER TABLE tb_pengguna ADD COLUMN email VARCHAR(100) AFTER username");
        echo "Added email column to tb_pengguna.<br>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "Email column already exists in tb_pengguna.<br>";
        } else {
            throw $e;
        }
    }

    // Create tb_password_resets table
    $sql = "CREATE TABLE IF NOT EXISTS tb_password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(100) NOT NULL,
        token VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (email),
        INDEX (token)
    )";
    $pdo->exec($sql);
    echo "Created tb_password_resets table.<br>";

    echo "Database setup for password reset completed successfully.";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>