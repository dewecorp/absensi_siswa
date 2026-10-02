<?php
// Skema Database untuk modul Guru & Wali Kelas (19 Fitur Pembelajaran & Pengelolaan Kelas)
// Otomatis membuat tabel-tabel jika belum ada saat di-include.

if (!function_exists('ensure_learning_schema')) {
    function ensure_learning_schema(PDO $pdo): void {
        static $done = false;
        if ($done) return;

        // 1. Perangkat Pembelajaran
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_perangkat_pembelajaran (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_guru INT NOT NULL,
                jenis_perangkat VARCHAR(50) NOT NULL,
                judul VARCHAR(255) NOT NULL,
                id_mapel INT NULL,
                id_kelas INT NULL,
                materi_tp VARCHAR(255) NULL,
                semester VARCHAR(20) NULL,
                tahun_ajaran VARCHAR(30) NULL,
                file_path VARCHAR(255) NULL,
                status ENUM('Draft','Aktif','Arsip') NOT NULL DEFAULT 'Draft',
                cp TEXT NULL,
                tp TEXT NULL,
                materi TEXT NULL,
                tujuan_pembelajaran TEXT NULL,
                indikator TEXT NULL,
                deskripsi TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_guru (id_guru),
                INDEX idx_kelas (id_kelas),
                INDEX idx_mapel (id_mapel)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 2. Tugas
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_tugas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_guru INT NOT NULL,
                judul VARCHAR(255) NOT NULL,
                id_mapel INT NULL,
                id_kelas INT NULL,
                materi_tp VARCHAR(255) NULL,
                jenis_tugas VARCHAR(50) NOT NULL DEFAULT 'Individu',
                instruksi TEXT NULL,
                tgl_mulai DATETIME NULL,
                deadline DATETIME NULL,
                nilai_maksimal INT NOT NULL DEFAULT 100,
                lampiran VARCHAR(255) NULL,
                status ENUM('Draft','Aktif','Selesai','Arsip') NOT NULL DEFAULT 'Aktif',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_guru (id_guru),
                INDEX idx_kelas (id_kelas),
                INDEX idx_mapel (id_mapel)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 3. Pengumpulan Tugas
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_tugas_pengumpulan (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_tugas INT NOT NULL,
                id_siswa INT NOT NULL,
                file_path VARCHAR(255) NULL,
                catatan_siswa TEXT NULL,
                tgl_kumpul DATETIME NULL,
                keterlambatan VARCHAR(100) NULL,
                nilai DECIMAL(5,2) NULL,
                status_periksa ENUM('Belum Diperiksa','Sudah Diperiksa','Perlu Revisi') NOT NULL DEFAULT 'Belum Diperiksa',
                feedback TEXT NULL,
                diperiksa_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_tugas_siswa (id_tugas, id_siswa),
                INDEX idx_tugas (id_tugas),
                INDEX idx_siswa (id_siswa)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 4 & 5. Bank Soal
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_bank_soal (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_guru INT NOT NULL,
                kode_soal VARCHAR(50) NOT NULL,
                jenis_soal ENUM('Pilihan Ganda','Pilihan Ganda Kompleks','Menjodohkan','Isian Singkat','Uraian') NOT NULL DEFAULT 'Pilihan Ganda',
                id_mapel INT NULL,
                id_kelas INT NULL,
                kurikulum VARCHAR(30) NULL DEFAULT 'PERMENDIKDASMEN_046',
                semester VARCHAR(20) NULL,
                jenis_asesmen VARCHAR(60) NULL,
                topik VARCHAR(255) NOT NULL DEFAULT '',
                sub_topik VARCHAR(255) NULL,
                materi_tp VARCHAR(255) NULL,
                cp TEXT NULL,
                tp TEXT NULL,
                atp TEXT NULL,
                indikator TEXT NULL,
                level_kognitif VARCHAR(10) NULL DEFAULT 'L2',
                tingkat_kesulitan ENUM('Mudah','Sedang','Sukar') NOT NULL DEFAULT 'Sedang',
                bobot DECIMAL(5,2) NOT NULL DEFAULT 1.00,
                pertanyaan TEXT NOT NULL,
                pilihan_jawaban TEXT NULL,
                jawaban_benar TEXT NULL,
                pembahasan TEXT NULL,
                status ENUM('Draft','Aktif','Arsip') NOT NULL DEFAULT 'Aktif',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_guru (id_guru),
                INDEX idx_mapel (id_mapel),
                INDEX idx_kelas (id_kelas)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Migrasi bank soal lama -> 5 bentuk + kolom kurikulum/CP/TP/ATP
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM tb_bank_soal")->fetchAll(PDO::FETCH_COLUMN, 0);
            if (in_array('jenis_soal', (array)$cols, true)) {
                $pdo->exec("ALTER TABLE tb_bank_soal MODIFY COLUMN jenis_soal ENUM('Pilihan Ganda','Pilihan Ganda Kompleks','Menjodohkan','Isian Singkat','Uraian') NOT NULL DEFAULT 'Pilihan Ganda'");
                $pdo->exec("UPDATE tb_bank_soal SET jenis_soal = 'Isian Singkat' WHERE jenis_soal = 'Benar/Salah'");
            }
            foreach ([
                "kurikulum VARCHAR(30) NULL DEFAULT 'PERMENDIKDASMEN_046'",
                "semester VARCHAR(20) NULL",
                "jenis_asesmen VARCHAR(60) NULL",
                "topik VARCHAR(255) NOT NULL DEFAULT ''",
                "sub_topik VARCHAR(255) NULL",
                "level_kognitif VARCHAR(10) NULL",
                "cp TEXT NULL",
                "tp TEXT NULL",
                "atp TEXT NULL",
            ] as $colDef) {
                $colName = explode(' ', trim($colDef), 2)[0];
                $has = $pdo->query("SHOW COLUMNS FROM tb_bank_soal LIKE '" . addslashes($colName) . "'")->fetch(PDO::FETCH_ASSOC);
                if (!$has) {
                    $pdo->exec("ALTER TABLE tb_bank_soal ADD COLUMN {$colDef}");
                }
            }
        } catch (Throwable $e) { /* abaikan bila tabel belum ada */
        }

        // 6. Bahan Ajar
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_bahan_ajar (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_guru INT NOT NULL,
                judul VARCHAR(255) NOT NULL,
                jenis ENUM('PDF','Video','Link','Presentasi','LKPD','Dokumen') NOT NULL DEFAULT 'PDF',
                id_mapel INT NULL,
                id_kelas INT NULL,
                materi_tp VARCHAR(255) NULL,
                file_link TEXT NOT NULL,
                semester VARCHAR(20) NULL,
                tahun_ajaran VARCHAR(30) NULL,
                status ENUM('Draft','Aktif','Arsip') NOT NULL DEFAULT 'Aktif',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_guru (id_guru),
                INDEX idx_mapel (id_mapel),
                INDEX idx_kelas (id_kelas)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 7 & 8. Catatan Perkembangan Siswa
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_catatan_perkembangan (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_guru INT NOT NULL,
                id_siswa INT NOT NULL,
                id_kelas INT NULL,
                id_mapel INT NULL,
                tanggal DATE NOT NULL,
                kategori ENUM('Akademik','Sikap','Keterampilan','Keaktifan','Potensi','Kendala Belajar','Catatan Guru') NOT NULL DEFAULT 'Akademik',
                ringkasan TEXT NOT NULL,
                kendala TEXT NULL,
                tindak_lanjut TEXT NULL,
                status ENUM('Aktif','Selesai','Dalam Pemantauan') NOT NULL DEFAULT 'Aktif',
                perkembangan_akademik TEXT NULL,
                perkembangan_sikap TEXT NULL,
                perkembangan_keterampilan TEXT NULL,
                keaktifan TEXT NULL,
                potensi TEXT NULL,
                catatan_guru TEXT NULL,
                rekomendasi TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_siswa (id_siswa),
                INDEX idx_guru (id_guru),
                INDEX idx_kelas (id_kelas),
                INDEX idx_tanggal (tanggal)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 9. Komunikasi Kelas
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_komunikasi_kelas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_guru INT NOT NULL,
                tanggal DATE NOT NULL,
                judul VARCHAR(255) NOT NULL,
                jenis ENUM('Pengumuman','Pesan','Diskusi') NOT NULL DEFAULT 'Pengumuman',
                id_kelas INT NOT NULL,
                isi TEXT NOT NULL,
                lampiran VARCHAR(255) NULL,
                status ENUM('Terkirim','Draft','Arsip') NOT NULL DEFAULT 'Terkirim',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_guru (id_guru),
                INDEX idx_kelas (id_kelas)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_komunikasi_kelas_read (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_komunikasi INT NOT NULL,
                id_siswa INT NOT NULL,
                dibaca_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_baca (id_komunikasi, id_siswa)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 10. Pembinaan Siswa (Level Wali)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_pembinaan_siswa (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_wali INT NOT NULL,
                id_siswa INT NOT NULL,
                id_kelas INT NOT NULL,
                tanggal DATE NOT NULL,
                jenis_pembinaan ENUM('Akademik','Kedisiplinan','Sikap','Kehadiran','Sosial','Lainnya') NOT NULL DEFAULT 'Akademik',
                permasalahan TEXT NOT NULL,
                tindakan TEXT NOT NULL,
                tindak_lanjut TEXT NULL,
                status ENUM('Berjalan','Selesai','Dalam Pemantauan') NOT NULL DEFAULT 'Berjalan',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_wali (id_wali),
                INDEX idx_siswa (id_siswa),
                INDEX idx_kelas (id_kelas)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 11. Pelanggaran Siswa (Level Wali)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_pelanggaran_siswa (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_wali INT NOT NULL,
                id_siswa INT NOT NULL,
                id_kelas INT NOT NULL,
                tanggal DATE NOT NULL,
                jenis_pelanggaran VARCHAR(255) NOT NULL,
                kategori VARCHAR(100) NULL,
                poin INT NOT NULL DEFAULT 0,
                tindakan TEXT NOT NULL,
                orang_tua VARCHAR(255) NULL,
                status ENUM('Dicatat','Ditindaklanjuti','Selesai') NOT NULL DEFAULT 'Dicatat',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_wali (id_wali),
                INDEX idx_siswa (id_siswa),
                INDEX idx_kelas (id_kelas)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 13. Konseling Awal (Level Wali)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_konseling_awal (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_wali INT NOT NULL,
                id_siswa INT NOT NULL,
                id_kelas INT NOT NULL,
                tanggal DATE NOT NULL,
                topik VARCHAR(255) NOT NULL,
                ringkasan_masalah TEXT NOT NULL,
                tindak_lanjut TEXT NULL,
                status ENUM('Terbuka','Proses','Selesai') NOT NULL DEFAULT 'Terbuka',
                follow_up TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_wali (id_wali),
                INDEX idx_siswa (id_siswa),
                INDEX idx_kelas (id_kelas)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 14. Tindak Lanjut (Level Wali)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_tindak_lanjut_wali (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_wali INT NOT NULL,
                id_siswa INT NOT NULL,
                id_kelas INT NOT NULL,
                tanggal DATE NOT NULL,
                sumber ENUM('Pembinaan','Pelanggaran','Konseling','Perkembangan') NOT NULL DEFAULT 'Pembinaan',
                tindakan TEXT NOT NULL,
                penanggung_jawab VARCHAR(100) NOT NULL,
                target_selesai DATE NULL,
                tanggal_selesai DATE NULL,
                status ENUM('Rencana','Proses','Selesai','Dibatalkan') NOT NULL DEFAULT 'Rencana',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_wali (id_wali),
                INDEX idx_siswa (id_siswa),
                INDEX idx_kelas (id_kelas)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 15. Komunikasi Orang Tua (Level Wali)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_komunikasi_ortu (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_wali INT NOT NULL,
                id_siswa INT NOT NULL,
                id_kelas INT NOT NULL,
                tanggal DATE NOT NULL,
                nama_ortu VARCHAR(150) NULL,
                jenis_informasi ENUM('Pengumuman Kelas','Pesan Individu','Informasi Kehadiran','Informasi Tugas','Informasi Perkembangan') NOT NULL DEFAULT 'Pengumuman Kelas',
                judul VARCHAR(255) NOT NULL,
                isi TEXT NOT NULL,
                status_kirim ENUM('Terkirim','Draft','Gagal') NOT NULL DEFAULT 'Terkirim',
                status_dibaca ENUM('Belum Dibaca','Sudah Dibaca') NOT NULL DEFAULT 'Belum Dibaca',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_wali (id_wali),
                INDEX idx_siswa (id_siswa),
                INDEX idx_kelas (id_kelas)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 16. Agenda Kelas (Level Wali)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_agenda_kelas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_wali INT NOT NULL,
                id_kelas INT NOT NULL,
                tanggal DATE NOT NULL,
                waktu_mulai TIME NULL,
                waktu_selesai TIME NULL,
                nama_agenda VARCHAR(255) NOT NULL,
                jenis ENUM('Ujian','Kegiatan Kelas','Kegiatan Madrasah','Piket','Projek','Kokurikuler') NOT NULL DEFAULT 'Kegiatan Kelas',
                tempat VARCHAR(150) NULL,
                penanggung_jawab VARCHAR(100) NULL,
                status ENUM('Rencana','Berjalan','Selesai','Batal') NOT NULL DEFAULT 'Rencana',
                keterangan TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_wali (id_wali),
                INDEX idx_kelas (id_kelas),
                INDEX idx_tanggal (tanggal)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 17. Jadwal Piket Kelas (Level Wali)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_jadwal_piket_kelas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_wali INT NOT NULL,
                id_kelas INT NOT NULL,
                hari VARCHAR(20) NOT NULL,
                id_siswa INT NOT NULL,
                tugas VARCHAR(100) NOT NULL DEFAULT 'Piket Umum',
                urutan INT NOT NULL DEFAULT 1,
                status ENUM('Aktif','Nonaktif') NOT NULL DEFAULT 'Aktif',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_wali (id_wali),
                INDEX idx_kelas (id_kelas),
                INDEX idx_hari (hari),
                INDEX idx_siswa (id_siswa)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 18. Projek / Kokurikuler (Level Wali)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_projek_kokurikuler (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_wali INT NOT NULL,
                nama_projek VARCHAR(255) NOT NULL,
                tema VARCHAR(255) NOT NULL,
                id_kelas INT NOT NULL,
                pembimbing VARCHAR(150) NULL,
                tgl_mulai DATE NULL,
                tgl_selesai DATE NULL,
                deskripsi TEXT NULL,
                status ENUM('Perencanaan','Berjalan','Selesai','Arsip') NOT NULL DEFAULT 'Perencanaan',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_wali (id_wali),
                INDEX idx_kelas (id_kelas)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 19. Anggota Projek (Level Wali)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_projek_anggota (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_projek INT NOT NULL,
                id_siswa INT NOT NULL,
                peran ENUM('Ketua','Sekretaris','Anggota','Presentator','Lainnya') NOT NULL DEFAULT 'Anggota',
                kelompok VARCHAR(100) NULL,
                catatan TEXT NULL,
                status ENUM('Aktif','Selesai','Keluar') NOT NULL DEFAULT 'Aktif',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_projek_siswa (id_projek, id_siswa),
                INDEX idx_projek (id_projek),
                INDEX idx_siswa (id_siswa)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $done = true;
    }
}
