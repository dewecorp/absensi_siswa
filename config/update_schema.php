<?php
/**
 * Database Migration Script
 * This file handles database schema updates
 */

require_once __DIR__ . '/database.php';

try {
    // 1. Update tb_siswa schema
    $columns_to_add_siswa = [
        'tempat_lahir' => "ALTER TABLE tb_siswa ADD COLUMN tempat_lahir VARCHAR(100) AFTER jenis_kelamin",
        'tanggal_lahir' => "ALTER TABLE tb_siswa ADD COLUMN tanggal_lahir DATE AFTER tempat_lahir",
        'wali' => "ALTER TABLE tb_siswa ADD COLUMN wali VARCHAR(100) AFTER tanggal_lahir"
    ];

    foreach ($columns_to_add_siswa as $col => $sql) {
        $check = $pdo->query("SHOW COLUMNS FROM tb_siswa LIKE '$col'");
        if ($check && $check->rowCount() == 0) {
            $pdo->exec($sql);
            echo "Added column '$col' to tb_siswa.\n";
        }
    }

    // 2. Update tb_mata_pelajaran schema
    $colCheckMapel = $pdo->query("SHOW COLUMNS FROM tb_mata_pelajaran LIKE 'jenis_mapel'");
    if ($colCheckMapel && $colCheckMapel->rowCount() == 0) {
        $pdo->exec("ALTER TABLE tb_mata_pelajaran ADD COLUMN jenis_mapel VARCHAR(20) NULL DEFAULT 'Akademik' AFTER kode_mapel");
        echo "Added column 'jenis_mapel' to tb_mata_pelajaran.\n";
    }

    echo "Database migration completed successfully.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
