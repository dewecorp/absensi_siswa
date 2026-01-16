<?php
/**
 * Script to update all existing teacher passwords to 123456
 * Run this script to change all passwords from default123 to 123456
 */

require_once 'config/database.php';
require_once 'config/functions.php';

$new_password = '123456';
$hashed_password = hashPassword($new_password);

try {
    // Update all teachers' passwords
    $stmt = $pdo->prepare("UPDATE tb_guru SET password = ?, password_plain = ?");
    $stmt->execute([$hashed_password, $new_password]);
    $updated = $stmt->rowCount();
    
    echo "✓ Berhasil mengupdate $updated data guru dengan password baru: $new_password\n";
    echo "\n";
    echo "Semua password guru telah diubah menjadi: $new_password\n";
    echo "Password ini akan terlihat di kolom Password di halaman Data Guru\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
