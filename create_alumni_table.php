<?php
require_once 'config/database.php';
try {
    $sql = "CREATE TABLE IF NOT EXISTS `tb_alumni` (
        `id_alumni` int NOT NULL AUTO_INCREMENT,
        `nama_siswa` varchar(100) NOT NULL,
        `nisn` varchar(20) NOT NULL,
        `jenis_kelamin` enum('L','P') DEFAULT NULL,
        `tahun_lulus` varchar(20) NOT NULL,
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id_alumni`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;";
    $pdo->exec($sql);
    echo "Table tb_alumni created successfully or already exists.";
} catch (PDOException $e) {
    echo "Error creating table: " . $e->getMessage();
}
unlink(__FILE__);
?>