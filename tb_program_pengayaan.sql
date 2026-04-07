-- SQL untuk membuat tabel Program Pengayaan
-- Jalankan di phpMyAdmin > db_absensi > SQL

CREATE TABLE IF NOT EXISTS tb_program_pengayaan (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
