<?php
require_once 'config/database.php';

try {
    $sql = "ALTER TABLE tb_jam_mengajar ADD COLUMN jenis ENUM('Reguler', 'Ramadhan') DEFAULT 'Reguler'";
    $pdo->exec($sql);
    echo "Column 'jenis' added successfully.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
