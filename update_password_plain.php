<?php
/**
 * Script to add password_plain column and set default passwords for existing teachers
 * Run this script once to update the database
 */

require_once 'config/database.php';

// Check if column exists
try {
    $check = $pdo->query("SHOW COLUMNS FROM tb_guru LIKE 'password_plain'");
    if ($check->rowCount() == 0) {
        // Column doesn't exist, add it
        $pdo->exec("ALTER TABLE `tb_guru` ADD COLUMN `password_plain` VARCHAR(255) NULL AFTER `password`");
        echo "✓ Kolom password_plain berhasil ditambahkan\n";
    } else {
        echo "✓ Kolom password_plain sudah ada\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit;
}

// Update existing teachers that don't have password_plain
// Set default password for teachers without password_plain
$default_password = 'default123';

try {
    $stmt = $pdo->prepare("UPDATE tb_guru SET password_plain = ? WHERE password_plain IS NULL OR password_plain = ''");
    $stmt->execute([$default_password]);
    $updated = $stmt->rowCount();
    echo "✓ Berhasil mengupdate $updated data guru dengan password default: $default_password\n";
    echo "\n";
    echo "Catatan:\n";
    echo "- Password default untuk semua guru yang belum memiliki password_plain adalah: $default_password\n";
    echo "- Untuk mengubah password, gunakan fitur Edit di halaman Data Guru\n";
    echo "- Password baru yang ditambahkan/edit akan otomatis menyimpan plain text\n";
} catch (PDOException $e) {
    echo "Error updating passwords: " . $e->getMessage() . "\n";
}
