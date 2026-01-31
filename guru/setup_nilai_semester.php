<?php
require_once __DIR__ . '/../config/database.php';

try {
    // Create tb_nilai_semester table
    $sql = "CREATE TABLE IF NOT EXISTS tb_nilai_semester (
        id_nilai INT AUTO_INCREMENT PRIMARY KEY,
        id_siswa INT NOT NULL,
        id_mapel INT NOT NULL,
        id_kelas INT NOT NULL,
        id_guru INT NOT NULL,
        jenis_semester ENUM('UTS', 'UAS', 'PAT', 'Pra Ujian', 'Ujian') NOT NULL,
        tahun_ajaran VARCHAR(20) NOT NULL,
        semester VARCHAR(20) NOT NULL,
        nilai_asli DECIMAL(5, 2) DEFAULT 0,
        nilai_remidi DECIMAL(5, 2) DEFAULT 0,
        nilai_jadi DECIMAL(5, 2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_nilai (id_siswa, id_mapel, jenis_semester, tahun_ajaran, semester),
        FOREIGN KEY (id_siswa) REFERENCES tb_siswa(id_siswa) ON DELETE CASCADE,
        FOREIGN KEY (id_mapel) REFERENCES tb_mata_pelajaran(id_mapel) ON DELETE CASCADE,
        FOREIGN KEY (id_kelas) REFERENCES tb_kelas(id_kelas) ON DELETE CASCADE,
        FOREIGN KEY (id_guru) REFERENCES tb_guru(id_guru) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $pdo->exec($sql);
    echo "Table tb_nilai_semester created successfully.<br>";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>