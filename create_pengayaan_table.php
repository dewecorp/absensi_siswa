<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'db_absensi');

echo "<!DOCTYPE html><html><head><title>Create Table</title></head><body>";
echo "<h2>Creating Table: tb_program_pengayaan</h2>";

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create table for program pengayaan
    $sql = "CREATE TABLE IF NOT EXISTS tb_program_pengayaan (
        id_pengayaan INT AUTO_INCREMENT PRIMARY KEY,
        id_siswa INT NOT NULL,
        id_mapel INT NOT NULL,
        id_kelas INT NOT NULL,
        id_guru INT NOT NULL,
        jenis_ulangan VARCHAR(50) NOT NULL,
        tahun_ajaran VARCHAR(20) NOT NULL,
        semester VARCHAR(20) NOT NULL,
        bentuk_pengayaan VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_siswa (id_siswa),
        INDEX idx_mapel (id_mapel),
        INDEX idx_kelas (id_kelas),
        INDEX idx_jenis (jenis_ulangan)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $pdo->exec($sql);
    
    echo "<div style='background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; border-radius: 5px; margin: 10px 0;'>";
    echo "✅ <strong>Success!</strong> Table tb_program_pengayaan berhasil dibuat!";
    echo "</div>";
    echo "<p><a href='guru/program_pengayaan.php'>Go to Program Pengayaan</a></p>";
    
} catch (PDOException $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border: 1px solid #f5c6cb; border-radius: 5px; margin: 10px 0;'>";
    echo "❌ <strong>Error:</strong> " . $e->getMessage();
    echo "</div>";
}

echo "</body></html>";
