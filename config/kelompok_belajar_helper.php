<?php
/**
 * Helper & Schema Kelompok Belajar
 */
function ensure_kelompok_belajar_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_kelompok_belajar (
                id_kelompok INT AUTO_INCREMENT PRIMARY KEY,
                nama_kelompok VARCHAR(150) NOT NULL,
                id_kelas INT NULL,
                id_ketua INT NULL,
                id_guru INT NULL,
                keterangan TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_kb_kelas (id_kelas),
                INDEX idx_kb_guru (id_guru),
                INDEX idx_kb_ketua (id_ketua)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Jika kolom id_mapel masih ada dari versi lama, hapus
        try {
            $checkMapel = $pdo->query("SHOW COLUMNS FROM tb_kelompok_belajar LIKE 'id_mapel'")->fetch();
            if ($checkMapel) {
                $pdo->exec("ALTER TABLE tb_kelompok_belajar DROP COLUMN id_mapel");
            }
        } catch (Throwable $e) {}

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_anggota_kelompok_belajar (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_kelompok INT NOT NULL,
                id_siswa INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_kelompok_siswa (id_kelompok, id_siswa),
                INDEX idx_akb_kelompok (id_kelompok),
                INDEX idx_akb_siswa (id_siswa),
                CONSTRAINT fk_akb_kelompok FOREIGN KEY (id_kelompok) REFERENCES tb_kelompok_belajar(id_kelompok) ON DELETE CASCADE,
                CONSTRAINT fk_akb_siswa FOREIGN KEY (id_siswa) REFERENCES tb_siswa(id_siswa) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        error_log('ensure_kelompok_belajar_schema: ' . $e->getMessage());
    }

    $done = true;
}
