<?php
/**
 * Helper & Schema Agenda untuk SIMAD
 */

function ensureAgendaTables(PDO $pdo): bool {
    static $checked = false;
    if ($checked) return true;
    $checked = true;

    try {
        // 1. Table Jenis Agenda
        $pdo->exec("CREATE TABLE IF NOT EXISTS tb_agenda_jenis (
            id_jenis INT AUTO_INCREMENT PRIMARY KEY,
            jenis_agenda VARCHAR(100) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Populate default jenis agenda if empty
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM tb_agenda_jenis")->fetchColumn();
        if ($cnt === 0) {
            $defaults = ['Internal', 'Kedinasan / Kemenag', 'Undangan Yayasan', 'Rapat Koordinasi', 'Luar Kota'];
            $stmt = $pdo->prepare("INSERT IGNORE INTO tb_agenda_jenis (jenis_agenda) VALUES (?)");
            foreach ($defaults as $d) {
                $stmt->execute([$d]);
            }
        }

        // 2. Table Agenda Kepala
        $pdo->exec("CREATE TABLE IF NOT EXISTS tb_agenda_kepala (
            id_agenda INT AUTO_INCREMENT PRIMARY KEY,
            nama_agenda VARCHAR(255) NOT NULL,
            id_jenis INT NOT NULL,
            hari_tanggal DATE NOT NULL,
            waktu VARCHAR(100) DEFAULT NULL,
            tempat VARCHAR(255) DEFAULT NULL,
            uraian_kegiatan LONGTEXT DEFAULT NULL,
            keterangan TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (id_jenis) REFERENCES tb_agenda_jenis(id_jenis) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Ensure column uraian_kegiatan exists for existing installations
        $checkColAk = $pdo->query("SHOW COLUMNS FROM tb_agenda_kepala LIKE 'uraian_kegiatan'")->fetch();
        if (!$checkColAk) {
            $pdo->exec("ALTER TABLE tb_agenda_kepala ADD COLUMN uraian_kegiatan LONGTEXT DEFAULT NULL AFTER tempat");
        }

        // Ensure column waktu exists for existing installations
        $checkColWaktu = $pdo->query("SHOW COLUMNS FROM tb_agenda_kepala LIKE 'waktu'")->fetch();
        if (!$checkColWaktu) {
            $pdo->exec("ALTER TABLE tb_agenda_kepala ADD COLUMN waktu VARCHAR(100) DEFAULT NULL AFTER hari_tanggal");
        }

        // 3. Table Rapat
        $pdo->exec("CREATE TABLE IF NOT EXISTS tb_agenda_rapat (
            id_rapat INT AUTO_INCREMENT PRIMARY KEY,
            nama_rapat VARCHAR(255) NOT NULL,
            id_jenis INT DEFAULT NULL,
            hari_tanggal DATE NOT NULL,
            waktu VARCHAR(100) DEFAULT NULL,
            agenda_rapat LONGTEXT DEFAULT NULL,
            notulensi LONGTEXT DEFAULT NULL,
            pemimpin_rapat VARCHAR(150) DEFAULT NULL,
            tempat VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Ensure column notulensi exists for existing installations
        $checkCol = $pdo->query("SHOW COLUMNS FROM tb_agenda_rapat LIKE 'notulensi'")->fetch();
        if (!$checkCol) {
            $pdo->exec("ALTER TABLE tb_agenda_rapat ADD COLUMN notulensi LONGTEXT DEFAULT NULL AFTER agenda_rapat");
        }

        // Ensure column id_jenis exists in tb_agenda_rapat for existing installations
        $checkColRapatJenis = $pdo->query("SHOW COLUMNS FROM tb_agenda_rapat LIKE 'id_jenis'")->fetch();
        if (!$checkColRapatJenis) {
            $pdo->exec("ALTER TABLE tb_agenda_rapat ADD COLUMN id_jenis INT DEFAULT NULL AFTER nama_rapat");
        }

        // 4. Table Jenis Rapat
        $pdo->exec("CREATE TABLE IF NOT EXISTS tb_rapat_jenis (
            id_jenis INT AUTO_INCREMENT PRIMARY KEY,
            jenis_rapat VARCHAR(100) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $cntRapatJenis = (int)$pdo->query("SELECT COUNT(*) FROM tb_rapat_jenis")->fetchColumn();
        if ($cntRapatJenis === 0) {
            $defaultsRapat = ['Rapat Dinas', 'Rapat Koordinasi', 'Rapat Pleno', 'Rapat Evaluasi', 'Rapat Panitia'];
            $stmtR = $pdo->prepare("INSERT IGNORE INTO tb_rapat_jenis (jenis_rapat) VALUES (?)");
            foreach ($defaultsRapat as $dr) {
                $stmtR->execute([$dr]);
            }
        }

        return true;
    } catch (PDOException $e) {
        error_log('ensureAgendaTables error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Format tanggal Indonesia (contoh: Senin, 12 Oktober 2026)
 */
function formatHariTanggalIndo(?string $dateStr): string {
    if (!$dateStr || $dateStr === '0000-00-00') return '-';
    $time = strtotime($dateStr);
    if (!$time) return '-';

    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    $numHari = (int)date('w', $time);
    $tgl = (int)date('j', $time);
    $numBulan = (int)date('n', $time);
    $thn = date('Y', $time);

    return $hari[$numHari] . ', ' . $tgl . ' ' . $bulan[$numBulan] . ' ' . $thn;
}
