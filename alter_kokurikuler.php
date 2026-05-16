<?php
require_once 'config/database.php';
try {
    $pdo->exec("ALTER TABLE tb_nilai_kokurikuler_header ADD COLUMN nilai_min_target DECIMAL(5,2) DEFAULT NULL AFTER tgl_kegiatan");
    $pdo->exec("ALTER TABLE tb_nilai_kokurikuler_header ADD COLUMN nilai_max_target DECIMAL(5,2) DEFAULT NULL AFTER nilai_min_target");
    echo "Columns added successfully.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
