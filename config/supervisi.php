<?php
/**
 * Modul SUPERVISI - Level Kepala Madrasah
 * Bootstrap bersama: skema tabel, helper akses, helper data master & perhitungan nilai.
 *
 * File ini TIDAK membuat master data baru. Semua data guru/PTK, kelas, mata pelajaran,
 * tahun ajaran, semester, pengguna, dan profil madrasah diambil dari tabel SIMAD yang sudah ada.
 */

if (!function_exists('sv_require_access')) {
    function sv_require_access(PDO $pdo): void
    {
        $level = getUserLevel();
        $via = isset($_GET['session_type']) ? strtolower(trim((string)$_GET['session_type'])) : '';
        if ($level === 'admin' && ($via === 'admin' || $via === '')) {
            if (!isAuthorized(['admin', 'kepala_madrasah'])) {
                redirect('../login.php');
            }
            return;
        }
        if (!isAuthorized(['kepala_madrasah'])) {
            redirect('../login.php');
        }
    }
}

if (!function_exists('sv_is_supervisor')) {
    function sv_is_supervisor(PDO $pdo): bool
    {
        if (isAuthorized(['admin'])) {
            return true;
        }
        return isAuthorized(['kepala_madrasah']);
    }
}

if (!function_exists('sv_now')) {
    function sv_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('sv_current_user_name')) {
    function sv_current_user_name(PDO $pdo): string
    {
        $name = trim((string)($_SESSION['nama'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $name = trim((string)($_SESSION['nama_guru'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $name = trim((string)($_SESSION['username'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $idGuru = getCurrentGuruId($pdo);
        if ($idGuru > 0) {
            try {
                $stmt = $pdo->prepare('SELECT nama_guru FROM tb_guru WHERE id_guru = ? LIMIT 1');
                $stmt->execute([$idGuru]);
                $nama = trim((string)($stmt->fetchColumn() ?: ''));
                if ($nama !== '') {
                    return $nama;
                }
            } catch (Throwable $e) {
            }
        }

        return 'Kepala Madrasah';
    }
}

if (!function_exists('sv_jenis_list')) {
    function sv_jenis_list(): array
    {
        return ['Akademik', 'Administrasi', 'Manajerial'];
    }
}

if (!function_exists('sv_status_jadwal_list')) {
    function sv_status_jadwal_list(): array
    {
        return ['Terjadwal', 'Terlaksana', 'Ditunda', 'Dibatalkan'];
    }
}

if (!function_exists('sv_bentuk_tindak_lanjut_list')) {
    function sv_bentuk_tindak_lanjut_list(): array
    {
        return ['Pembinaan', 'Pendampingan', 'Coaching', 'Konsultasi', 'Pelatihan', 'Workshop', 'Penugasan', 'Supervisi ulang', 'Lainnya'];
    }
}

if (!function_exists('sv_status_tindak_lanjut_list')) {
    function sv_status_tindak_lanjut_list(): array
    {
        return ['Belum Ditindaklanjuti', 'Dalam Proses', 'Selesai', 'Perlu Supervisi Ulang'];
    }
}

if (!function_exists('sv_jenis_dokumen_list')) {
    function sv_jenis_dokumen_list(): array
    {
        return ['Instrumen', 'Foto supervisi', 'Dokumen administrasi', 'Hasil observasi', 'Berita acara', 'Surat tugas', 'Bukti tindak lanjut', 'Dokumen pendukung'];
    }
}

if (!function_exists('sv_skala_list')) {
    function sv_skala_list(): array
    {
        return ['1-4', '1-5', '0-100'];
    }
}

if (!function_exists('sv_skala_max')) {
    function sv_skala_max(string $skala): float
    {
        switch ($skala) {
            case '1-4':
                return 4.0;
            case '0-100':
                return 100.0;
            case '1-5':
            default:
                return 5.0;
        }
    }
}

if (!function_exists('sv_predikat')) {
    function sv_predikat(?float $nilai): string
    {
        if ($nilai === null) {
            return '-';
        }
        if ($nilai >= 91) {
            return 'A - Amat Baik';
        }
        if ($nilai >= 76) {
            return 'B - Baik';
        }
        if ($nilai >= 61) {
            return 'C - Cukup';
        }
        return 'D - Kurang';
    }
}

if (!function_exists('sv_hitung_nilai')) {
    /**
     * Hitung nilai akhir 0-100 dari daftar indikator.
     * Skor dibulatkan (integer), nilai = Σ(bobot × skor/max) / Σbobot × 100.
     * Alur: pilih Instrumen → render indikator sesuai instrumen → isi Skor bulat per indikator → Nilai preview live → simpan → hitung ulang di server → simpan ke tb_sv_pelaksanaan.nilai + tb_sv_penilaian.nilai + predikat.
     * Jika instrumen tidak dipilih atau tanpa indikator, nilai 0.
     */
    function sv_hitung_nilai(array $items, float $skalaMax): array
    {
        $totalBobot = 0.0;
        $totalNilai = 0.0;
        foreach ($items as $it) {
            $bobot = (float)($it['bobot'] ?? 0);
            if ($bobot <= 0) {
                continue;
            }
            $skorMaks = (float)($it['skor_maksimal'] ?? $skalaMax);
            if ($skorMaks <= 0) {
                $skorMaks = $skalaMax > 0 ? $skalaMax : 4;
            }
            $skor = (int)round((float)($it['skor'] ?? 0));
            $skor = max(0, min((int)round($skorMaks), $skor));
            $rasio = $skorMaks > 0 ? ($skor / $skorMaks) : 0;
            $totalNilai += $bobot * $rasio;
            $totalBobot += $bobot;
        }

        if ($totalBobot <= 0) {
            return ['nilai' => 0.0, 'predikat' => sv_predikat(0.0)];
        }

        $nilai = round(($totalNilai / $totalBobot) * 100, 2);
        return ['nilai' => $nilai, 'predikat' => sv_predikat($nilai)];
    }
}

if (!function_exists('sv_ensure_schema')) {
    function sv_ensure_schema(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $sql = [];

        $sql['tb_sv_program'] = "CREATE TABLE IF NOT EXISTS tb_sv_program (
            id_program INT AUTO_INCREMENT PRIMARY KEY,
            kode_program VARCHAR(50) NULL,
            tahun_ajaran VARCHAR(20) NOT NULL,
            semester VARCHAR(20) NOT NULL,
            jenis_supervisi VARCHAR(30) NOT NULL,
            nama_program VARCHAR(200) NOT NULL,
            tujuan TEXT NULL,
            sasaran TEXT NULL,
            fokus_supervisi TEXT NULL,
            target TEXT NULL,
            indikator_keberhasilan TEXT NULL,
            waktu_pelaksanaan VARCHAR(150) NULL,
            tanggal_mulai DATE NULL,
            tanggal_selesai DATE NULL,
            penanggung_jawab VARCHAR(150) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'Aktif',
            keterangan TEXT NULL,
            created_by VARCHAR(150) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sv_program_periode (tahun_ajaran, semester),
            INDEX idx_sv_program_jenis (jenis_supervisi)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sql['tb_sv_sasaran'] = "CREATE TABLE IF NOT EXISTS tb_sv_sasaran (
            id_sasaran INT AUTO_INCREMENT PRIMARY KEY,
            id_program INT NULL,
            id_guru INT NULL,
            nama_guru VARCHAR(150) NOT NULL,
            nip_npk VARCHAR(60) NULL,
            jabatan VARCHAR(150) NULL,
            mata_pelajaran TEXT NULL,
            kelas TEXT NULL,
            jenis_supervisi VARCHAR(30) NOT NULL,
            tahun_ajaran VARCHAR(20) NOT NULL,
            semester VARCHAR(20) NOT NULL,
            status_supervisi VARCHAR(30) NOT NULL DEFAULT 'Belum Disupervisi',
            keterangan TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sv_sasaran_guru (id_guru),
            INDEX idx_sv_sasaran_program (id_program),
            INDEX idx_sv_sasaran_periode (tahun_ajaran, semester)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sql['tb_sv_instrumen'] = "CREATE TABLE IF NOT EXISTS tb_sv_instrumen (
            id_instrumen INT AUTO_INCREMENT PRIMARY KEY,
            kode_instrumen VARCHAR(50) NOT NULL,
            nama_instrumen VARCHAR(200) NOT NULL,
            jenis_supervisi VARCHAR(30) NOT NULL,
            tujuan TEXT NULL,
            sasaran TEXT NULL,
            skala_penilaian VARCHAR(20) NOT NULL DEFAULT '1-4',
            status VARCHAR(20) NOT NULL DEFAULT 'Aktif',
            keterangan TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sv_instrumen_jenis (jenis_supervisi)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sql['tb_sv_komponen'] = "CREATE TABLE IF NOT EXISTS tb_sv_komponen (
            id_komponen INT AUTO_INCREMENT PRIMARY KEY,
            id_instrumen INT NOT NULL,
            kode_komponen VARCHAR(50) NULL,
            nama_komponen VARCHAR(200) NOT NULL,
            bobot DECIMAL(8,2) NOT NULL DEFAULT 1,
            urutan INT NOT NULL DEFAULT 0,
            keterangan TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sv_komponen_instrumen (id_instrumen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sql['tb_sv_indikator'] = "CREATE TABLE IF NOT EXISTS tb_sv_indikator (
            id_indikator INT AUTO_INCREMENT PRIMARY KEY,
            id_komponen INT NOT NULL,
            kode_indikator VARCHAR(50) NULL,
            indikator VARCHAR(255) NOT NULL,
            deskripsi TEXT NULL,
            bobot DECIMAL(8,2) NOT NULL DEFAULT 1,
            skor_minimal DECIMAL(8,2) NOT NULL DEFAULT 1,
            skor_maksimal DECIMAL(8,2) NOT NULL DEFAULT 4,
            urutan INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sv_indikator_komponen (id_komponen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sql['tb_sv_jadwal'] = "CREATE TABLE IF NOT EXISTS tb_sv_jadwal (
            id_jadwal INT AUTO_INCREMENT PRIMARY KEY,
            id_program INT NULL,
            id_sasaran INT NULL,
            id_instrumen INT NULL,
            id_guru INT NULL,
            nama_guru VARCHAR(150) NULL,
            jenis_supervisi VARCHAR(30) NOT NULL,
            supervisor VARCHAR(150) NULL,
            tanggal DATE NULL,
            jam_mulai TIME NULL,
            jam_selesai TIME NULL,
            tempat VARCHAR(150) NULL,
            fokus TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'Terjadwal',
            keterangan TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sv_jadwal_tanggal (tanggal),
            INDEX idx_sv_jadwal_guru (id_guru),
            INDEX idx_sv_jadwal_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sql['tb_sv_pelaksanaan'] = "CREATE TABLE IF NOT EXISTS tb_sv_pelaksanaan (
            id_pelaksanaan INT AUTO_INCREMENT PRIMARY KEY,
            id_jadwal INT NULL,
            id_program INT NULL,
            id_instrumen INT NULL,
            id_guru INT NULL,
            nama_guru VARCHAR(150) NULL,
            unit_bagian VARCHAR(200) NULL,
            penanggung_jawab VARCHAR(150) NULL,
            mapel_di_supervisi VARCHAR(150) NULL,
            jenis_supervisi VARCHAR(30) NOT NULL,
            tanggal DATE NULL,
            supervisor VARCHAR(150) NULL,
            nilai DECIMAL(6,2) NULL,
            predikat VARCHAR(50) NULL,
            fokus TEXT NULL,
            kekuatan TEXT NULL,
            kelemahan TEXT NULL,
            temuan TEXT NULL,
            rekomendasi TEXT NULL,
            prioritas_perbaikan VARCHAR(150) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'Draft',
            keterangan TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sv_pelaksanaan_guru (id_guru),
            INDEX idx_sv_pelaksanaan_jenis (jenis_supervisi),
            INDEX idx_sv_pelaksanaan_tanggal (tanggal)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sql['tb_sv_penilaian'] = "CREATE TABLE IF NOT EXISTS tb_sv_penilaian (
            id_penilaian INT AUTO_INCREMENT PRIMARY KEY,
            id_pelaksanaan INT NOT NULL,
            id_komponen INT NULL,
            id_indikator INT NULL,
            komponen VARCHAR(200) NULL,
            indikator VARCHAR(255) NULL,
            bobot DECIMAL(8,2) NOT NULL DEFAULT 1,
            skor DECIMAL(8,2) NOT NULL DEFAULT 0,
            skor_minimal DECIMAL(8,2) NOT NULL DEFAULT 1,
            skor_maksimal DECIMAL(8,2) NOT NULL DEFAULT 4,
            nilai DECIMAL(8,2) NOT NULL DEFAULT 0,
            catatan TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sv_penilaian_pelaksanaan (id_pelaksanaan)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sql['tb_sv_manajerial_detail'] = "CREATE TABLE IF NOT EXISTS tb_sv_manajerial_detail (
            id_detail INT AUTO_INCREMENT PRIMARY KEY,
            id_pelaksanaan INT NOT NULL,
            unit_bagian VARCHAR(200) NULL,
            penanggung_jawab VARCHAR(150) NULL,
            program VARCHAR(200) NULL,
            indikator VARCHAR(255) NULL,
            target VARCHAR(255) NULL,
            realisasi VARCHAR(255) NULL,
            skor DECIMAL(8,2) NULL,
            temuan TEXT NULL,
            kendala TEXT NULL,
            rekomendasi TEXT NULL,
            status VARCHAR(30) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sv_mjd_pelaksanaan (id_pelaksanaan)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sql['tb_sv_tindak_lanjut'] = "CREATE TABLE IF NOT EXISTS tb_sv_tindak_lanjut (
            id_tindak_lanjut INT AUTO_INCREMENT PRIMARY KEY,
            id_pelaksanaan INT NULL,
            id_guru INT NULL,
            nama_guru VARCHAR(150) NULL,
            unit_bagian VARCHAR(200) NULL,
            temuan TEXT NULL,
            rekomendasi TEXT NULL,
            bentuk_tindak_lanjut VARCHAR(50) NULL,
            rencana_tindakan TEXT NULL,
            penanggung_jawab VARCHAR(150) NULL,
            target_selesai DATE NULL,
            realisasi DATE NULL,
            bukti VARCHAR(255) NULL,
            bukti_file VARCHAR(255) NULL,
            tautan_dokumen VARCHAR(500) NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'Belum Ditindaklanjuti',
            tanggal_selesai DATE NULL,
            catatan TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sv_tl_pelaksanaan (id_pelaksanaan),
            INDEX idx_sv_tl_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sql['tb_sv_monitoring'] = "CREATE TABLE IF NOT EXISTS tb_sv_monitoring (
            id_monitoring INT AUTO_INCREMENT PRIMARY KEY,
            id_tindak_lanjut INT NOT NULL,
            id_guru INT NULL,
            nama_guru VARCHAR(150) NULL,
            unit_bagian VARCHAR(200) NULL,
            temuan_awal TEXT NULL,
            tindakan TEXT NULL,
            target_perbaikan VARCHAR(255) NULL,
            tanggal_monitoring DATE NULL,
            monitoring_ke INT NOT NULL DEFAULT 1,
            hasil_monitoring TEXT NULL,
            perubahan VARCHAR(255) NULL,
            nilai_sebelum DECIMAL(6,2) NULL,
            nilai_sesudah DECIMAL(6,2) NULL,
            status VARCHAR(40) NULL,
            catatan TEXT NULL,
            bukti VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sv_monitoring_tl (id_tindak_lanjut)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sql['tb_sv_hasil_master'] = "CREATE TABLE IF NOT EXISTS tb_sv_hasil_master (
            id_master INT AUTO_INCREMENT PRIMARY KEY,
            kategori VARCHAR(30) NOT NULL,
            teks TEXT NOT NULL,
            urutan INT NOT NULL DEFAULT 0,
            is_aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sv_hasil_kategori (kategori)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $sql['tb_sv_arsip'] = "CREATE TABLE IF NOT EXISTS tb_sv_arsip (
            id_arsip INT AUTO_INCREMENT PRIMARY KEY,
            id_pelaksanaan INT NULL,
            jenis_dokumen VARCHAR(50) NULL,
            nama_dokumen VARCHAR(200) NULL,
            file VARCHAR(255) NULL,
            tautan_dokumen VARCHAR(500) NULL,
            tanggal_upload DATETIME NULL,
            pengunggah VARCHAR(150) NULL,
            keterangan TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sv_arsip_pelaksanaan (id_pelaksanaan)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        foreach ($sql as $table => $ddl) {
            try {
                $pdo->exec($ddl);
            } catch (Throwable $e) {
                error_log("Supervisi schema error ({$table}): " . $e->getMessage());
            }
        }

        // Migrasi kolom tambahan yang aman diulang (idempoten).
        $columns = [
            'tb_sv_program' => [
                'kode_program' => "VARCHAR(50) NULL AFTER id_program",
                'tanggal_mulai' => "DATE NULL AFTER waktu_pelaksanaan",
                'tanggal_selesai' => "DATE NULL AFTER tanggal_mulai",
            ],
            'tb_sv_pelaksanaan' => [
                'mapel_di_supervisi' => "VARCHAR(150) NULL AFTER penanggung_jawab",
                'fokus' => "TEXT NULL AFTER predikat",
            ],
            'tb_sv_arsip' => [
                'tautan_dokumen' => "VARCHAR(500) NULL AFTER file",
            ],
            'tb_sv_tindak_lanjut' => [
                'bukti_file' => "VARCHAR(255) NULL AFTER bukti",
                'tautan_dokumen' => "VARCHAR(500) NULL AFTER bukti_file",
            ],
        ];
        foreach ($columns as $table => $cols) {
            foreach ($cols as $col => $ddl) {
                try {
                    if (dbTableExists($pdo, $table) && !dbColumnExists($pdo, $table, $col)) {
                        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$ddl}");
                    }
                } catch (Throwable $e) {
                    error_log("Supervisi migration error ({$table}.{$col}): " . $e->getMessage());
                }
            }
        }

        try {
            if (dbTableExists($pdo, 'tb_sv_sasaran')) {
                $pdo->exec("ALTER TABLE tb_sv_sasaran MODIFY mata_pelajaran TEXT NULL");
                $pdo->exec("ALTER TABLE tb_sv_sasaran MODIFY kelas TEXT NULL");
            }
        } catch (Throwable $e) {
            error_log("Supervisi migrate sas TEXT: " . $e->getMessage());
        }
        // Hapus mapel non-akademik yang terlanjur tersimpan di tb_sv_sasaran.
        // Jangan timpa pilihan tunggal kepala (mis. Akidah Akhlak) menjadi semua mapel guru.
        try {
            if (dbTableExists($pdo, 'tb_sv_sasaran')) {
                $non = $pdo->query("SELECT nama_mapel FROM tb_mata_pelajaran WHERE jenis_mapel <> 'Akademik'")->fetchAll(PDO::FETCH_COLUMN);
                if ($non) {
                    $nonLower = array_map(function ($v) { return mb_strtolower(trim((string)$v)); }, $non);
                    $rows = $pdo->query("SELECT id_sasaran, mata_pelajaran FROM tb_sv_sasaran")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $s) {
                        $raw = (string)($s['mata_pelajaran'] ?? '');
                        if (trim($raw) === '' || trim($raw) === '-') {
                            continue;
                        }
                        $parts = array_map('trim', explode(',', $raw));
                        $filtered = [];
                        $changed = false;
                        foreach ($parts as $p) {
                            if ($p === '') {
                                $changed = true;
                                continue;
                            }
                            if (in_array(mb_strtolower($p), $nonLower, true)) {
                                $changed = true;
                                continue;
                            }
                            $filtered[] = $p;
                        }
                        if (!$changed) {
                            continue;
                        }
                        $migrated = implode(', ', $filtered);
                        $migrated = trim(preg_replace('/\s*,\s*,+/', ', ', $migrated), " ,\t\n\r\0\x0B");
                        if ($migrated === '') {
                            $migrated = '-';
                        }
                        $pd = $pdo->prepare("UPDATE tb_sv_sasaran SET mata_pelajaran = ? WHERE id_sasaran = ?");
                        $pd->execute([$migrated, (int)$s['id_sasaran']]);
                    }
                }
            }
        } catch (Throwable $e) {
            error_log("Supervisi migrate akademic filter: " . $e->getMessage());
        }
        try {
            if (dbTableExists($pdo, 'tb_sv_program')) {
                $pdo->exec("ALTER TABLE tb_sv_program MODIFY target TEXT NULL");
            }
        } catch (Throwable $e) {
            error_log("Supervisi migrate program target TEXT: " . $e->getMessage());
        }
        try {
            if (dbTableExists($pdo, 'tb_sv_hasil_master')) {
                $cnt = (int)$pdo->query("SELECT COUNT(*) FROM tb_sv_hasil_master")->fetchColumn();
                if ($cnt === 0) {
                    sv_seed_hasil_master($pdo);
                }
            }
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('sv_parse_fokus')) {
    function sv_parse_fokus(?string $text): array
    {
        $text = trim((string)$text);
        if ($text === '') {
            return [];
        }
        $parts = preg_split('/[,\n;]+/', $text);
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('sv_parse_indikator_text')) {
    /**
     * Ubah teks indikator tersimpan menjadi peta [fokus => [indikator,...]].
     * Format: "Fokus:\n- indikator\n- indikator\nFokus2:\n- ...".
     */
    function sv_parse_indikator_text(?string $text): array
    {
        $text = (string)$text;
        if (trim($text) === '') {
            return [];
        }
        $map = [];
        $current = null;
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (substr($line, -1) === ':') {
                $current = trim(substr($line, 0, -1));
                if ($current !== '' && !isset($map[$current])) {
                    $map[$current] = [];
                }
                continue;
            }
            if (substr($line, 0, 1) === '-') {
                $ind = trim(substr($line, 1));
                if ($ind !== '') {
                    if ($current === null) {
                        $current = '(Umum)';
                        if (!isset($map[$current])) {
                            $map[$current] = [];
                        }
                    }
                    $map[$current][] = $ind;
                }
            }
        }
        return $map;
    }
}

if (!function_exists('sv_build_indikator_text')) {
    function sv_build_indikator_text(array $map): string
    {
        $lines = [];
        foreach ($map as $fokus => $indikators) {
            if (empty($indikators)) {
                continue;
            }
            $lines[] = $fokus . ':';
            foreach ($indikators as $ind) {
                $lines[] = '- ' . $ind;
            }
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('sv_format_rentang')) {
    function sv_format_rentang(?string $mulai, ?string $selesai): string
    {
        $mulai = trim((string)$mulai);
        $selesai = trim((string)$selesai);
        if ($mulai === '' && $selesai === '') {
            return '';
        }
        $fmt = function ($d) {
            $ts = strtotime($d);
            return $ts ? date('d/m/Y', $ts) : $d;
        };
        if ($mulai !== '' && $selesai !== '') {
            return $fmt($mulai) . ' - ' . $fmt($selesai);
        }
        return $fmt($mulai !== '' ? $mulai : $selesai);
    }
}

if (!function_exists('sv_guru_list')) {
    function sv_guru_list(PDO $pdo): array
    {
        try {
            $stmt = $pdo->query("SELECT id_guru, kode_guru, nama_guru, nuptk, jenis_kelamin, wali_kelas, mengajar
                                 FROM tb_guru ORDER BY nama_guru ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('sv_guru_map')) {
    function sv_guru_map(PDO $pdo): array
    {
        $map = [];
        foreach (sv_guru_list($pdo) as $g) {
            $map[(int)$g['id_guru']] = $g;
        }
        return $map;
    }
}

if (!function_exists('sv_kelas_list')) {
    function sv_kelas_list(PDO $pdo): array
    {
        try {
            return $pdo->query("SELECT id_kelas, nama_kelas, wali_kelas FROM tb_kelas ORDER BY nama_kelas ASC")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('sv_mapel_list')) {
    function sv_mapel_list(PDO $pdo): array
    {
        try {
            return $pdo->query("SELECT id_mapel, kode_mapel, nama_mapel FROM tb_mata_pelajaran ORDER BY nama_mapel ASC")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('sv_mapel_guru')) {
    /**
     * Petakan id_guru => daftar nama mapel AKADEMIK saja (dari jadwal pelajaran).
     * Hanya jenis_mapel = 'Akademik' yang disupervisi.
     */
    function sv_mapel_guru(PDO $pdo): array
    {
        $out = [];
        try {
            $stmt = $pdo->query("SELECT j.guru_id, m.nama_mapel
                                 FROM tb_jadwal_pelajaran j
                                 JOIN tb_mata_pelajaran m ON m.id_mapel = j.mapel_id
                                 WHERE j.guru_id IS NOT NULL AND m.jenis_mapel = 'Akademik'
                                 GROUP BY j.guru_id, m.nama_mapel
                                 ORDER BY m.nama_mapel ASC");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int)$row['guru_id'];
                $out[$id][] = $row['nama_mapel'];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }
}

if (!function_exists('sv_kelas_guru')) {
    function sv_kelas_guru(PDO $pdo): array
    {
        $out = [];
        try {
            $stmt = $pdo->query("SELECT j.guru_id, k.nama_kelas
                                 FROM tb_jadwal_pelajaran j
                                 JOIN tb_kelas k ON k.id_kelas = j.kelas_id
                                 WHERE j.guru_id IS NOT NULL
                                 GROUP BY j.guru_id, k.nama_kelas
                                 ORDER BY k.nama_kelas ASC");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int)$row['guru_id'];
                $out[$id][] = $row['nama_kelas'];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }
}

if (!function_exists('sv_periode')) {
    function sv_periode(PDO $pdo): array
    {
        $profile = getSchoolProfile($pdo);
        $ta = trim((string)($profile['tahun_ajaran'] ?? ''));
        $sem = trim((string)($profile['semester'] ?? ''));
        if ($ta === '') {
            $start = getTahunAjaranBerjalanStartYear();
            $ta = $start . '/' . ($start + 1);
        }
        if ($sem === '') {
            $sem = 'Semester 1';
        }
        return ['tahun_ajaran' => $ta, 'semester' => $sem];
    }
}

if (!function_exists('sv_semester_options')) {
    function sv_semester_options(): array
    {
        return ['Semester 1', 'Semester 2'];
    }
}

if (!function_exists('sv_tahun_ajaran_options')) {
    function sv_tahun_ajaran_options(PDO $pdo): array
    {
        $years = [];
        $current = sv_periode($pdo)['tahun_ajaran'];
        if ($current !== '') {
            $years[$current] = true;
        }
        try {
            $stmt = $pdo->query("SELECT DISTINCT tahun_ajaran FROM tb_sv_program WHERE tahun_ajaran IS NOT NULL AND tahun_ajaran <> ''");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ta) {
                $years[trim((string)$ta)] = true;
            }
        } catch (Throwable $e) {
        }
        $start = getTahunAjaranBerjalanStartYear();
        for ($i = -1; $i <= 2; $i++) {
            $s = $start + $i;
            $years[$s . '/' . ($s + 1)] = true;
        }
        $list = array_keys($years);
        usort($list, function ($a, $b) {
            return strcmp($b, $a);
        });
        return $list;
    }
}

if (!function_exists('sv_rentang_tahun_ajaran')) {
    function sv_rentang_tahun_ajaran(string $tahunAjaran): ?array
    {
        return getRentangTanggalTahunAjaran($tahunAjaran);
    }
}

if (!function_exists('sv_prev_tahun_ajaran')) {
    function sv_prev_tahun_ajaran(string $tahunAjaran): string
    {
        if (!isTahunAjaranFormatValid($tahunAjaran)) return '';
        $y = (int)explode('/', trim($tahunAjaran))[0];
        return ($y - 1) . '/' . $y;
    }
}

if (!function_exists('sv_next_tahun_ajaran')) {
    function sv_next_tahun_ajaran(string $tahunAjaran): string
    {
        if (!isTahunAjaranFormatValid($tahunAjaran)) return '';
        $y = (int)explode('/', trim($tahunAjaran))[0];
        return ($y + 1) . '/' . ($y + 2);
    }
}

if (!function_exists('sv_hitung_statistik')) {
    function sv_hitung_statistik(PDO $pdo, array $filter = []): array
    {
        $ta = trim((string)($filter['tahun_ajaran'] ?? ''));
        $sem = trim((string)($filter['semester'] ?? ''));

        $where = [];
        $params = [];
        if ($ta !== '') {
            $where[] = 'tahun_ajaran = ?';
            $params[] = $ta;
        }
        if ($sem !== '') {
            $where[] = 'semester = ?';
            $params[] = $sem;
        }
        $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

        $stats = [
            'total_guru' => 0,
            'total_sasaran' => 0,
            'sudah_diperiksa' => 0,
            'belum_diperiksa' => 0,
            'terjadwal' => 0,
            'selesai' => 0,
            'rata_nilai' => 0.0,
            'jumlah_temuan' => 0,
            'tl_belum' => 0,
            'tl_selesai' => 0,
        ];

        try {
            $stats['total_guru'] = (int)$pdo->query("SELECT COUNT(*) FROM tb_guru")->fetchColumn();
        } catch (Throwable $e) {
        }

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tb_sv_sasaran" . $whereSql);
            $stmt->execute($params);
            $stats['total_sasaran'] = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
        }

        try {
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT COALESCE(id_guru, 0)) FROM tb_sv_pelaksanaan WHERE status = 'Selesai'"
                . ($ta !== '' ? " AND tanggal BETWEEN ? AND ?" : ''));
            if ($ta !== '') {
                $parts = explode('/', $ta);
                $start = $parts[0] . '-07-01';
                $end = ($parts[1] ?? ((int)$parts[0] + 1)) . '-06-30';
                $stmt->execute([$start, $end]);
            } else {
                $stmt->execute();
            }
            $stats['sudah_diperiksa'] = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
        }

        $stats['belum_diperiksa'] = max(0, $stats['total_sasaran'] - $stats['sudah_diperiksa']);

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tb_sv_jadwal WHERE status = 'Terjadwal'"
                . ($ta !== '' ? " AND tanggal BETWEEN ? AND ?" : ''));
            if ($ta !== '') {
                $parts = explode('/', $ta);
                $start = $parts[0] . '-07-01';
                $end = ($parts[1] ?? ((int)$parts[0] + 1)) . '-06-30';
                $stmt->execute([$start, $end]);
            } else {
                $stmt->execute();
            }
            $stats['terjadwal'] = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
        }

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*), AVG(nilai) FROM tb_sv_pelaksanaan WHERE status = 'Selesai'"
                . ($ta !== '' ? " AND tanggal BETWEEN ? AND ?" : ''));
            if ($ta !== '') {
                $parts = explode('/', $ta);
                $start = $parts[0] . '-07-01';
                $end = ($parts[1] ?? ((int)$parts[0] + 1)) . '-06-30';
                $stmt->execute([$start, $end]);
            } else {
                $stmt->execute();
            }
            $row = $stmt->fetch(PDO::FETCH_NUM);
            $stats['selesai'] = (int)($row[0] ?? 0);
            $stats['rata_nilai'] = round((float)($row[1] ?? 0), 2);
        } catch (Throwable $e) {
        }

        try {
            $stats['jumlah_temuan'] = (int)$pdo->query("SELECT COUNT(*) FROM tb_sv_pelaksanaan WHERE temuan IS NOT NULL AND TRIM(temuan) <> ''")->fetchColumn();
        } catch (Throwable $e) {
        }

        try {
            $stats['tl_belum'] = (int)$pdo->query("SELECT COUNT(*) FROM tb_sv_tindak_lanjut WHERE status IN ('Belum Ditindaklanjuti', 'Dalam Proses', 'Perlu Supervisi Ulang')")->fetchColumn();
            $stats['tl_selesai'] = (int)$pdo->query("SELECT COUNT(*) FROM tb_sv_tindak_lanjut WHERE status = 'Selesai'")->fetchColumn();
        } catch (Throwable $e) {
        }

        return $stats;
    }
}

if (!function_exists('sv_sync_sasaran_status')) {
    function sv_sync_sasaran_status(PDO $pdo, int $idSasaran): void
    {
        if ($idSasaran <= 0) {
            return;
        }
        try {
            $stmt = $pdo->prepare("SELECT id_pelaksanaan, nilai FROM tb_sv_pelaksanaan
                                   WHERE status = 'Selesai' AND id_guru = (SELECT id_guru FROM tb_sv_sasaran WHERE id_sasaran = ? LIMIT 1)
                                   ORDER BY tanggal DESC, id_pelaksanaan DESC LIMIT 1");
            $stmt->execute([$idSasaran]);
            $last = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($last) {
                $upd = $pdo->prepare("UPDATE tb_sv_sasaran SET status_supervisi = 'Sudah Disupervisi' WHERE id_sasaran = ?");
                $upd->execute([$idSasaran]);
            } else {
                $upd = $pdo->prepare("UPDATE tb_sv_sasaran SET status_supervisi = 'Belum Disupervisi' WHERE id_sasaran = ?");
                $upd->execute([$idSasaran]);
            }
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('sv_instrumen_options')) {
    function sv_instrumen_options(PDO $pdo, string $jenis = ''): array
    {
        try {
            if ($jenis !== '') {
                $stmt = $pdo->prepare("SELECT id_instrumen, kode_instrumen, nama_instrumen, jenis_supervisi, skala_penilaian
                                       FROM tb_sv_instrumen WHERE status = 'Aktif' AND jenis_supervisi = ? ORDER BY nama_instrumen ASC");
                $stmt->execute([$jenis]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            return $pdo->query("SELECT id_instrumen, kode_instrumen, nama_instrumen, jenis_supervisi, skala_penilaian
                                FROM tb_sv_instrumen WHERE status = 'Aktif' ORDER BY nama_instrumen ASC")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('sv_program_options')) {
    function sv_program_options(PDO $pdo): array
    {
        try {
            return $pdo->query("SELECT id_program, nama_program, jenis_supervisi, tahun_ajaran, semester
                                FROM tb_sv_program ORDER BY tahun_ajaran DESC, nama_program ASC")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('sv_detail_pelaksanaan')) {
    function sv_detail_pelaksanaan(PDO $pdo, int $idPelaksanaan): ?array
    {
        try {
            $stmt = $pdo->prepare("SELECT p.*, i.nama_instrumen, i.kode_instrumen, i.skala_penilaian
                                   FROM tb_sv_pelaksanaan p
                                   LEFT JOIN tb_sv_instrumen i ON i.id_instrumen = p.id_instrumen
                                   WHERE p.id_pelaksanaan = ? LIMIT 1");
            $stmt->execute([$idPelaksanaan]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('sv_penilaian_rows')) {
    function sv_penilaian_rows(PDO $pdo, int $idPelaksanaan): array
    {
        try {
            $stmt = $pdo->prepare("SELECT p.*, k.nama_komponen AS komponen_master, k.kode_komponen FROM tb_sv_penilaian p LEFT JOIN tb_sv_komponen k ON k.id_komponen = p.id_komponen WHERE p.id_pelaksanaan = ? ORDER BY p.id_penilaian ASC");
            $stmt->execute([$idPelaksanaan]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                if (trim((string)($r['komponen'] ?? '')) === '' && trim((string)($r['komponen_master'] ?? '')) !== '') {
                    $r['komponen'] = $r['komponen_master'];
                }
            }
            unset($r);
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('sv_manajerial_rows')) {
    function sv_manajerial_rows(PDO $pdo, int $idPelaksanaan): array
    {
        try {
            $stmt = $pdo->prepare("SELECT * FROM tb_sv_manajerial_detail WHERE id_pelaksanaan = ? ORDER BY id_detail ASC");
            $stmt->execute([$idPelaksanaan]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('sv_tindak_lanjut_by_pelaksanaan')) {
    function sv_tindak_lanjut_by_pelaksanaan(PDO $pdo, int $idPelaksanaan): array
    {
        try {
            $stmt = $pdo->prepare("SELECT * FROM tb_sv_tindak_lanjut WHERE id_pelaksanaan = ? ORDER BY id_tindak_lanjut ASC");
            $stmt->execute([$idPelaksanaan]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('sv_arsip_by_pelaksanaan')) {
    function sv_arsip_by_pelaksanaan(PDO $pdo, int $idPelaksanaan): array
    {
        try {
            $stmt = $pdo->prepare("SELECT * FROM tb_sv_arsip WHERE id_pelaksanaan = ? ORDER BY id_arsip DESC");
            $stmt->execute([$idPelaksanaan]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('sv_log')) {
    function sv_log(PDO $pdo, string $action, string $description = ''): void
    {
        $username = (string)($_SESSION['username'] ?? 'system');
        logActivity($pdo, $username, 'Supervisi: ' . $action, $description);
    }
}

if (!function_exists('sv_upload_dir')) {
    function sv_upload_dir(): string
    {
        return dirname(__DIR__) . '/uploads/supervisi';
    }
}

if (!function_exists('sv_upload_url')) {
    function sv_upload_url(string $file): string
    {
        return '../uploads/supervisi/' . rawurlencode($file);
    }
}

if (!function_exists('sv_handle_upload')) {
    /**
     * Validasi & simpan file arsip supervisi.
     * @return array{ok:bool,file?:string,error?:string}
     */
    function sv_handle_upload(array $file, string $prefix = 'sv'): array
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            return ['ok' => false, 'error' => 'Parameter file tidak valid.'];
        }
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'Tidak ada file diunggah.'];
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Gagal mengunggah file (kode ' . (int)$file['error'] . ').'];
        }

        $maxSize = 5 * 1024 * 1024;
        if ((int)$file['size'] > $maxSize) {
            return ['ok' => false, 'error' => 'Ukuran file melebihi 5MB.'];
        }

        $allowed = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if ($ext === '' || !isset($allowed[$ext])) {
            return ['ok' => false, 'error' => 'Ekstensi file tidak diizinkan (pdf, jpg, jpeg, png, gif, doc, docx, xls, xlsx).'];
        }

        $mime = '';
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file($file['tmp_name']);
        }
        if ($mime !== '' && $mime !== $allowed[$ext] && !($ext === 'jpg' && $mime === 'image/jpeg')) {
            $isImageExt = in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true);
            if (!($isImageExt && strpos($mime, 'image/') === 0)) {
                return ['ok' => false, 'error' => 'Tipe file tidak sesuai dengan ekstensinya.'];
            }
        }

        $dir = sv_upload_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['ok' => false, 'error' => 'Folder penyimpanan tidak dapat dibuat.'];
        }

        $safeBase = preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo((string)$file['name'], PATHINFO_FILENAME));
        $safeBase = trim((string)$safeBase, '_');
        if ($safeBase === '') {
            $safeBase = 'dokumen';
        }
        $safeBase = substr($safeBase, 0, 60);
        $newName = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '_' . $safeBase . '.' . $ext;

        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $newName)) {
            return ['ok' => false, 'error' => 'Gagal menyimpan file ke folder upload.'];
        }
        @chmod($dir . '/' . $newName, 0644);

        return ['ok' => true, 'file' => $newName];
    }
}

if (!function_exists('sv_require_post')) {
    function sv_require_post(): void
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            redirect('dashboard_supervisi.php');
        }
    }
}

if (!function_exists('sv_flash')) {
    function sv_flash(string $type, string $text): void
    {
        $_SESSION['sv_flash'] = ['type' => $type, 'text' => $text];
    }
}

if (!function_exists('sv_take_flash')) {
    function sv_take_flash(): ?array
    {
        if (!empty($_SESSION['sv_flash'])) {
            $flash = $_SESSION['sv_flash'];
            unset($_SESSION['sv_flash']);
            return $flash;
        }
        return null;
    }
}

if (!function_exists('sv_render_flash_js')) {
    function sv_render_flash_js(): string
    {
        $flash = sv_take_flash();
        if (!$flash) {
            return '';
        }
        $icon = $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'error');
        $title = $flash['type'] === 'success' ? 'Berhasil!' : 'Perhatian!';
        return "Swal.fire({icon:'{$icon}',title:'{$title}',text:" . json_encode($flash['text']) . ",timer:2200,showConfirmButton:false});";
    }
}

if (!function_exists('sv_program_templates')) {
    /**
     * Template program supervisi lengkap: Jenis -> Program -> Fokus -> Indikator.
     * Dipakai untuk auto-isi form dan seeding data awal (idempoten, tidak menimpa data benar).
     */
    function sv_program_templates(): array
    {
        return [
            'Akademik' => [
                [
                    'kode' => 'AKD-01',
                    'nama' => 'Supervisi Akademik Proses Pembelajaran',
                    'tujuan' => 'Meningkatkan kualitas proses pembelajaran melalui observasi, pembinaan, pendampingan, dan pemberian umpan balik kepada guru agar proses pembelajaran berjalan efektif, aktif, bermakna, dan sesuai dengan tujuan pembelajaran.',
                    'sasaran' => 'Seluruh guru kelas dan guru mata pelajaran.',
                    'target' => 'Seluruh guru memperoleh supervisi akademik sesuai jadwal, memperoleh umpan balik, dan mendapatkan tindak lanjut untuk peningkatan kualitas pembelajaran.',
                    'fokus' => [
                        'Persiapan Pembelajaran' => [
                            'Guru menyusun rencana pembelajaran sebelum mengajar.',
                            'Guru menyiapkan bahan ajar dan sumber belajar yang relevan.',
                            'Guru menyiapkan media dan alat pembelajaran yang akan digunakan.',
                            'Guru menyiapkan instrumen asesmen yang sesuai tujuan pembelajaran.',
                        ],
                        'Pembukaan Pembelajaran' => [
                            'Guru membuka pembelajaran dengan salam dan doa.',
                            'Guru melakukan apersepsi untuk mengaitkan materi sebelumnya.',
                            'Guru memotivasi peserta didik sebelum pembelajaran dimulai.',
                            'Guru menyampaikan gambaran kegiatan pembelajaran yang akan dilakukan.',
                        ],
                        'Penyampaian Tujuan Pembelajaran' => [
                            'Guru menyampaikan tujuan pembelajaran kepada peserta didik.',
                            'Tujuan pembelajaran disampaikan secara jelas dan mudah dipahami.',
                            'Guru mengaitkan tujuan pembelajaran dengan pengalaman peserta didik.',
                            'Guru memastikan peserta didik memahami tujuan pembelajaran.',
                        ],
                        'Penguasaan Materi' => [
                            'Guru menguasai materi pembelajaran yang disampaikan.',
                            'Guru menyampaikan materi secara sistematis dan benar.',
                            'Guru menjawab pertanyaan peserta didik dengan tepat.',
                            'Guru mengaitkan materi dengan kehidupan sehari-hari.',
                        ],
                        'Metode Pembelajaran' => [
                            'Guru menggunakan metode yang sesuai dengan tujuan pembelajaran.',
                            'Guru menerapkan metode secara bervariasi.',
                            'Guru melibatkan peserta didik secara aktif dalam pembelajaran.',
                            'Guru menyesuaikan metode dengan karakteristik peserta didik.',
                        ],
                        'Media Pembelajaran' => [
                            'Guru menggunakan media yang sesuai dengan materi.',
                            'Media membantu pemahaman peserta didik.',
                            'Media digunakan secara efektif dan efisien.',
                            'Guru memanfaatkan media berbasis teknologi bila tersedia.',
                        ],
                        'Pengelolaan Kelas' => [
                            'Guru menciptakan suasana kelas yang kondusif.',
                            'Guru mengelola waktu pembelajaran secara efektif.',
                            'Guru menangani gangguan pembelajaran dengan tepat.',
                            'Guru memastikan peserta didik tertib mengikuti pembelajaran.',
                        ],
                        'Keaktifan Peserta Didik' => [
                            'Peserta didik terlibat aktif dalam pembelajaran.',
                            'Guru memberikan kesempatan kepada peserta didik untuk bertanya.',
                            'Guru memberikan kesempatan kepada peserta didik untuk berpendapat.',
                            'Guru mendorong kerja sama antar peserta didik.',
                        ],
                        'Interaksi Guru dan Peserta Didik' => [
                            'Guru berkomunikasi dengan bahasa yang jelas dan santun.',
                            'Guru memberikan perhatian kepada seluruh peserta didik.',
                            'Guru menanggapi pertanyaan dan pendapat peserta didik dengan baik.',
                            'Guru membangun hubungan yang hangat dan menghargai peserta didik.',
                        ],
                        'Asesmen Pembelajaran' => [
                            'Guru melaksanakan asesmen sesuai tujuan pembelajaran.',
                            'Guru menggunakan instrumen asesmen yang sesuai.',
                            'Guru memberikan umpan balik terhadap hasil belajar peserta didik.',
                            'Guru memanfaatkan hasil asesmen untuk perbaikan pembelajaran.',
                        ],
                        'Penguatan Karakter' => [
                            'Guru menanamkan nilai religius dalam pembelajaran.',
                            'Guru menanamkan nilai kejujuran dan tanggung jawab.',
                            'Guru memberikan keteladanan sikap kepada peserta didik.',
                            'Guru membiasakan peserta didik bersikap santun dan disiplin.',
                        ],
                        'Integrasi Kurikulum Berbasis Cinta (KBC)' => [
                            'Guru mengintegrasikan nilai cinta kepada Allah dalam pembelajaran.',
                            'Guru mengintegrasikan nilai cinta kepada diri sendiri.',
                            'Guru mengintegrasikan nilai cinta kepada sesama.',
                            'Guru mengintegrasikan nilai cinta kepada lingkungan.',
                        ],
                        'Penutup dan Refleksi' => [
                            'Guru menyimpulkan materi bersama peserta didik.',
                            'Guru melakukan refleksi pembelajaran bersama peserta didik.',
                            'Guru memberikan tugas atau tindak lanjut yang relevan.',
                            'Guru menutup pembelajaran dengan doa dan salam.',
                        ],
                    ],
                ],
                [
                    'kode' => 'AKD-02',
                    'nama' => 'Supervisi Perencanaan Pembelajaran',
                    'tujuan' => 'Memastikan guru memiliki dan menggunakan perencanaan pembelajaran yang lengkap, sesuai kurikulum, tujuan pembelajaran, karakteristik peserta didik, serta kebutuhan pembelajaran.',
                    'sasaran' => 'Seluruh guru kelas dan guru mata pelajaran.',
                    'target' => 'Seluruh guru memiliki perencanaan pembelajaran yang lengkap, relevan, terdokumentasi, dan digunakan dalam pelaksanaan pembelajaran.',
                    'fokus' => [
                        'ATP/Perencanaan Pembelajaran' => [
                            'Guru memiliki ATP yang sesuai dengan kurikulum.',
                            'ATP disusun secara sistematis dan berurutan.',
                            'ATP memuat capaian dan tujuan pembelajaran yang jelas.',
                            'ATP diperbarui sesuai kebutuhan pembelajaran.',
                        ],
                        'Modul Ajar/RPP' => [
                            'Guru memiliki modul ajar/RPP untuk setiap materi.',
                            'Modul ajar memuat komponen pembelajaran secara lengkap.',
                            'Modul ajar disusun sesuai format yang berlaku.',
                            'Modul ajar digunakan dalam pelaksanaan pembelajaran.',
                        ],
                        'Tujuan Pembelajaran' => [
                            'Tujuan pembelajaran dirumuskan secara spesifik.',
                            'Tujuan pembelajaran terukur dan dapat dicapai.',
                            'Tujuan pembelajaran sesuai karakteristik peserta didik.',
                            'Tujuan pembelajaran mendukung capaian pembelajaran.',
                        ],
                        'Materi Pembelajaran' => [
                            'Materi pembelajaran sesuai dengan tujuan pembelajaran.',
                            'Materi disusun secara sistematis.',
                            'Materi sesuai tingkat perkembangan peserta didik.',
                            'Materi didukung sumber belajar yang relevan.',
                        ],
                        'Metode Pembelajaran' => [
                            'Metode pembelajaran direncanakan sesuai tujuan.',
                            'Metode mendorong keaktifan peserta didik.',
                            'Metode bervariasi dan sesuai karakteristik peserta didik.',
                            'Metode mempertimbangkan alokasi waktu pembelajaran.',
                        ],
                        'Media Pembelajaran' => [
                            'Media pembelajaran direncanakan sesuai materi.',
                            'Media mendukung pencapaian tujuan pembelajaran.',
                            'Media memanfaatkan sumber daya yang tersedia.',
                            'Media direncanakan mudah digunakan peserta didik.',
                        ],
                        'Asesmen Pembelajaran' => [
                            'Asesmen direncanakan sesuai tujuan pembelajaran.',
                            'Instrumen asesmen disusun secara jelas.',
                            'Asesmen mencakup aspek sikap, pengetahuan, dan keterampilan.',
                            'Asesmen mencantumkan kriteria ketercapaian.',
                        ],
                        'Kegiatan Pembelajaran' => [
                            'Kegiatan pendahuluan direncanakan dengan jelas.',
                            'Kegiatan inti direncanakan secara runtut.',
                            'Kegiatan penutup direncanakan dengan jelas.',
                            'Alokasi waktu setiap kegiatan proporsional.',
                        ],
                        'Penguatan Karakter' => [
                            'Perencanaan memuat penguatan nilai religius.',
                            'Perencanaan memuat penguatan nilai kejujuran.',
                            'Perencanaan memuat penguatan nilai tanggung jawab.',
                            'Perencanaan memuat penguatan nilai disiplin.',
                        ],
                        'Integrasi KBC' => [
                            'Perencanaan memuat integrasi cinta kepada Allah.',
                            'Perencanaan memuat integrasi cinta kepada diri sendiri.',
                            'Perencanaan memuat integrasi cinta kepada sesama.',
                            'Perencanaan memuat integrasi cinta kepada lingkungan.',
                        ],
                    ],
                ],
                [
                    'kode' => 'AKD-03',
                    'nama' => 'Program Peningkatan Kualitas Pembelajaran',
                    'tujuan' => 'Meningkatkan efektivitas dan kualitas pembelajaran melalui supervisi, pembinaan, pendampingan, refleksi, dan pengembangan praktik pembelajaran.',
                    'sasaran' => 'Guru kelas dan guru mata pelajaran.',
                    'target' => 'Terjadi peningkatan kualitas pembelajaran berdasarkan hasil supervisi dan tindak lanjut yang dilakukan.',
                    'fokus' => [
                        'Strategi Pembelajaran' => [
                            'Guru menerapkan strategi pembelajaran yang sesuai tujuan.',
                            'Strategi mendorong peserta didik berpikir kritis.',
                            'Strategi mendorong peserta didik menyelesaikan masalah.',
                            'Strategi disesuaikan dengan kebutuhan pembelajaran.',
                        ],
                        'Pembelajaran Aktif' => [
                            'Guru melibatkan peserta didik dalam kegiatan pembelajaran.',
                            'Guru mendorong peserta didik belajar melalui pengalaman langsung.',
                            'Guru memfasilitasi diskusi dan kolaborasi peserta didik.',
                            'Guru memberikan kesempatan peserta didik mempresentasikan hasil kerja.',
                        ],
                        'Pembelajaran Berdiferensiasi' => [
                            'Guru mengidentifikasi kebutuhan belajar peserta didik.',
                            'Guru menyesuaikan materi dengan kemampuan peserta didik.',
                            'Guru menyediakan variasi kegiatan pembelajaran.',
                            'Guru memberikan pendampingan bagi peserta didik yang membutuhkan.',
                        ],
                        'Media Pembelajaran' => [
                            'Guru menggunakan media yang mendukung pemahaman peserta didik.',
                            'Media digunakan secara bervariasi.',
                            'Media memanfaatkan teknologi pembelajaran.',
                            'Media mendorong keterlibatan peserta didik.',
                        ],
                        'Pengelolaan Kelas' => [
                            'Guru menciptakan suasana belajar yang menyenangkan.',
                            'Guru mengelola waktu pembelajaran secara efektif.',
                            'Guru mengelola perilaku peserta didik dengan baik.',
                            'Guru menciptakan lingkungan kelas yang aman dan nyaman.',
                        ],
                        'Asesmen Pembelajaran' => [
                            'Guru melaksanakan asesmen secara berkelanjutan.',
                            'Guru menggunakan hasil asesmen untuk perbaikan pembelajaran.',
                            'Guru memberikan umpan balik yang membangun.',
                            'Guru melibatkan peserta didik dalam penilaian diri.',
                        ],
                        'Refleksi Pembelajaran' => [
                            'Guru melakukan refleksi setelah pembelajaran.',
                            'Guru mengidentifikasi kekuatan dan kelemahan pembelajaran.',
                            'Guru menyusun rencana perbaikan pembelajaran.',
                            'Guru melibatkan peserta didik dalam refleksi.',
                        ],
                        'Penguatan Karakter' => [
                            'Guru menanamkan nilai religius dalam pembelajaran.',
                            'Guru menanamkan nilai kejujuran dan tanggung jawab.',
                            'Guru menanamkan nilai disiplin dan santun.',
                            'Guru memberikan keteladanan sikap.',
                        ],
                        'Integrasi KBC' => [
                            'Guru mengintegrasikan cinta kepada Allah.',
                            'Guru mengintegrasikan cinta kepada diri sendiri.',
                            'Guru mengintegrasikan cinta kepada sesama.',
                            'Guru mengintegrasikan cinta kepada lingkungan.',
                        ],
                    ],
                ],
            ],
            'Administrasi' => [
                [
                    'kode' => 'ADM-01',
                    'nama' => 'Supervisi Administrasi Guru',
                    'tujuan' => 'Memastikan administrasi guru lengkap, tertib, akurat, terdokumentasi, dan mendukung pelaksanaan pembelajaran.',
                    'sasaran' => 'Seluruh guru.',
                    'target' => 'Seluruh guru memiliki administrasi yang lengkap, tertib, diperbarui secara berkala, dan dapat dipertanggungjawabkan.',
                    'fokus' => [
                        'Administrasi Pembelajaran' => [
                            'Guru memiliki administrasi pembelajaran yang lengkap.',
                            'Administrasi disusun sesuai ketentuan yang berlaku.',
                            'Administrasi diperbarui secara berkala.',
                            'Administrasi tersimpan dengan rapi.',
                        ],
                        'Perencanaan Pembelajaran' => [
                            'Guru memiliki perencanaan pembelajaran yang lengkap.',
                            'Perencanaan sesuai kurikulum yang berlaku.',
                            'Perencanaan memuat tujuan pembelajaran yang jelas.',
                            'Perencanaan digunakan dalam pelaksanaan pembelajaran.',
                        ],
                        'Jurnal Pembelajaran' => [
                            'Guru mengisi jurnal pembelajaran setiap pertemuan.',
                            'Jurnal memuat materi yang diajarkan.',
                            'Jurnal memuat catatan kegiatan pembelajaran.',
                            'Jurnal diisi secara tertib dan berkelanjutan.',
                        ],
                        'Daftar Hadir' => [
                            'Guru memiliki daftar hadir peserta didik.',
                            'Daftar hadir diisi setiap pertemuan.',
                            'Daftar hadir memuat keterangan kehadiran yang jelas.',
                            'Rekap kehadiran terdokumentasi dengan baik.',
                        ],
                        'Administrasi Asesmen' => [
                            'Guru memiliki administrasi asesmen yang lengkap.',
                            'Instrumen asesmen tersimpan dengan rapi.',
                            'Hasil asesmen terdokumentasi.',
                            'Administrasi asesmen diperbarui sesuai kebutuhan.',
                        ],
                        'Daftar Nilai' => [
                            'Guru memiliki daftar nilai yang lengkap.',
                            'Daftar nilai diisi secara akurat.',
                            'Daftar nilai diperbarui setiap penilaian.',
                            'Daftar nilai mudah ditelusuri.',
                        ],
                        'Analisis Hasil Belajar' => [
                            'Guru melakukan analisis hasil belajar.',
                            'Analisis memuat capaian peserta didik.',
                            'Analisis mengidentifikasi peserta didik yang perlu bantuan.',
                            'Analisis digunakan sebagai dasar tindak lanjut.',
                        ],
                        'Remedial' => [
                            'Guru menyusun program remedial.',
                            'Remedial dilaksanakan bagi peserta didik yang belum tuntas.',
                            'Pelaksanaan remedial terdokumentasi.',
                            'Hasil remedial dicatat dengan baik.',
                        ],
                        'Pengayaan' => [
                            'Guru menyusun program pengayaan.',
                            'Pengayaan dilaksanakan bagi peserta didik yang telah tuntas.',
                            'Pelaksanaan pengayaan terdokumentasi.',
                            'Hasil pengayaan dicatat dengan baik.',
                        ],
                        'Dokumentasi Pembelajaran' => [
                            'Guru mendokumentasikan kegiatan pembelajaran.',
                            'Dokumen tersimpan secara tertib.',
                            'Dokumen mudah diakses saat diperlukan.',
                            'Dokumen diperbarui sesuai perkembangan.',
                        ],
                    ],
                ],
                [
                    'kode' => 'ADM-02',
                    'nama' => 'Supervisi Administrasi Penilaian',
                    'tujuan' => 'Memastikan administrasi penilaian dilaksanakan secara tertib, objektif, terdokumentasi, dan sesuai ketentuan yang berlaku.',
                    'sasaran' => 'Seluruh guru.',
                    'target' => 'Administrasi penilaian seluruh guru lengkap, tertib, terdokumentasi, dan dapat digunakan sebagai dasar tindak lanjut pembelajaran.',
                    'fokus' => [
                        'Perencanaan Asesmen' => [
                            'Guru menyusun rencana asesmen sesuai tujuan pembelajaran.',
                            'Rencana asesmen memuat bentuk dan teknik penilaian.',
                            'Rencana asesmen memuat kriteria ketercapaian.',
                            'Rencana asesmen disusun sebelum pelaksanaan.',
                        ],
                        'Instrumen Penilaian' => [
                            'Guru menyusun instrumen penilaian yang sesuai.',
                            'Instrumen memuat kisi-kisi yang jelas.',
                            'Instrumen sesuai tingkat kemampuan peserta didik.',
                            'Instrumen ditelaah sebelum digunakan.',
                        ],
                        'Pelaksanaan Penilaian' => [
                            'Penilaian dilaksanakan sesuai jadwal.',
                            'Penilaian dilaksanakan secara objektif.',
                            'Guru mengawasi pelaksanaan penilaian dengan tertib.',
                            'Pelaksanaan penilaian terdokumentasi.',
                        ],
                        'Pengolahan Nilai' => [
                            'Guru mengolah nilai sesuai pedoman.',
                            'Pengolahan nilai dilakukan secara akurat.',
                            'Guru mempertimbangkan bobot penilaian.',
                            'Hasil pengolahan nilai terdokumentasi.',
                        ],
                        'Daftar Nilai' => [
                            'Guru memiliki daftar nilai yang lengkap.',
                            'Daftar nilai diisi secara tertib.',
                            'Daftar nilai diperbarui setiap penilaian.',
                            'Daftar nilai mudah ditelusuri.',
                        ],
                        'Analisis Hasil Belajar' => [
                            'Guru menganalisis hasil belajar peserta didik.',
                            'Analisis memuat ketuntasan belajar.',
                            'Analisis mengidentifikasi kebutuhan perbaikan.',
                            'Analisis digunakan sebagai dasar tindak lanjut.',
                        ],
                        'Remedial' => [
                            'Guru melaksanakan remedial bagi peserta didik belum tuntas.',
                            'Program remedial terdokumentasi.',
                            'Hasil remedial dicatat dengan baik.',
                            'Remedial dilaksanakan sesuai kebutuhan.',
                        ],
                        'Pengayaan' => [
                            'Guru melaksanakan pengayaan bagi peserta didik tuntas.',
                            'Program pengayaan terdokumentasi.',
                            'Hasil pengayaan dicatat dengan baik.',
                            'Pengayaan mendorong pengembangan potensi peserta didik.',
                        ],
                        'Pelaporan Hasil Belajar' => [
                            'Guru menyusun laporan hasil belajar.',
                            'Laporan disampaikan kepada pihak terkait.',
                            'Laporan memuat capaian peserta didik.',
                            'Laporan disusun tepat waktu.',
                        ],
                    ],
                ],
                [
                    'kode' => 'ADM-03',
                    'nama' => 'Supervisi Kelengkapan Administrasi PTK',
                    'tujuan' => 'Meningkatkan ketertiban, kelengkapan, dan akurasi administrasi pendidik dan tenaga kependidikan.',
                    'sasaran' => 'Guru dan tenaga kependidikan.',
                    'target' => 'Administrasi PTK lengkap, akurat, tertib, dan terdokumentasi.',
                    'fokus' => [
                        'Kelengkapan Dokumen' => [
                            'PTK memiliki dokumen administrasi yang lengkap.',
                            'Dokumen sesuai ketentuan yang berlaku.',
                            'Dokumen tersimpan dengan rapi.',
                            'Dokumen mudah ditelusuri saat diperlukan.',
                        ],
                        'Ketepatan Data' => [
                            'Data PTK terisi secara akurat.',
                            'Data sesuai dengan kondisi sebenarnya.',
                            'Data diperbarui secara berkala.',
                            'Data tidak mengandung kekeliruan.',
                        ],
                        'Keteraturan Arsip' => [
                            'Arsip PTK tersusun secara sistematis.',
                            'Arsip diberi penomoran dan penanda yang jelas.',
                            'Arsip tersimpan pada tempat yang aman.',
                            'Arsip mudah ditemukan kembali.',
                        ],
                        'Pembaruan Dokumen' => [
                            'Dokumen PTK diperbarui sesuai kebutuhan.',
                            'Pembaruan dilakukan secara berkala.',
                            'Dokumen lama diganti dengan dokumen terbaru.',
                            'Pembaruan terdokumentasi.',
                        ],
                        'Administrasi Tugas' => [
                            'PTK memiliki administrasi tugas yang jelas.',
                            'Uraian tugas terdokumentasi.',
                            'Laporan pelaksanaan tugas tersedia.',
                            'Administrasi tugas sesuai ketentuan.',
                        ],
                        'Dokumentasi Pekerjaan' => [
                            'PTK mendokumentasikan hasil pekerjaan.',
                            'Dokumentasi tersimpan secara tertib.',
                            'Dokumentasi mudah diakses.',
                            'Dokumentasi diperbarui sesuai perkembangan.',
                        ],
                    ],
                ],
            ],
            'Manajerial' => [
                [
                    'kode' => 'MJR-01',
                    'nama' => 'Supervisi Pengelolaan Program Madrasah',
                    'tujuan' => 'Memastikan program madrasah direncanakan, dilaksanakan, dimonitor, dievaluasi, dan ditindaklanjuti secara efektif.',
                    'sasaran' => 'Waka, koordinator, penanggung jawab program, dan unit kerja terkait.',
                    'target' => 'Program madrasah berjalan sesuai rencana, memiliki dokumentasi yang lengkap, serta dilakukan evaluasi dan tindak lanjut.',
                    'fokus' => [
                        'Perencanaan Program' => [
                            'Program memiliki rencana yang jelas.',
                            'Rencana memuat tujuan dan target program.',
                            'Rencana memuat jadwal pelaksanaan.',
                            'Rencana disusun melibatkan pihak terkait.',
                        ],
                        'Pembagian Tugas' => [
                            'Pembagian tugas diuraikan dengan jelas.',
                            'Setiap pelaksana memahami tugasnya.',
                            'Pembagian tugas mempertimbangkan kompetensi.',
                            'Pembagian tugas terdokumentasi.',
                        ],
                        'Pelaksanaan Program' => [
                            'Program dilaksanakan sesuai rencana.',
                            'Pelaksanaan program berjalan tepat waktu.',
                            'Pelaksanaan melibatkan pihak terkait.',
                            'Pelaksanaan terdokumentasi.',
                        ],
                        'Administrasi Program' => [
                            'Program memiliki administrasi yang lengkap.',
                            'Administrasi tersimpan dengan tertib.',
                            'Administrasi diperbarui sesuai perkembangan.',
                            'Administrasi mudah ditelusuri.',
                        ],
                        'Pengelolaan Sumber Daya' => [
                            'Sumber daya program dikelola secara efektif.',
                            'Pemanfaatan sumber daya sesuai kebutuhan.',
                            'Pengelolaan sumber daya dapat dipertanggungjawabkan.',
                            'Sumber daya dimanfaatkan secara efisien.',
                        ],
                        'Monitoring' => [
                            'Program dimonitor secara berkala.',
                            'Monitoring memuat capaian pelaksanaan.',
                            'Monitoring mengidentifikasi kendala.',
                            'Hasil monitoring terdokumentasi.',
                        ],
                        'Evaluasi' => [
                            'Program dievaluasi secara berkala.',
                            'Evaluasi memuat capaian target.',
                            'Evaluasi mengidentifikasi kekuatan dan kelemahan.',
                            'Hasil evaluasi terdokumentasi.',
                        ],
                        'Pelaporan' => [
                            'Program memiliki laporan pelaksanaan.',
                            'Laporan disusun tepat waktu.',
                            'Laporan memuat capaian dan kendala.',
                            'Laporan disampaikan kepada pihak terkait.',
                        ],
                        'Tindak Lanjut' => [
                            'Hasil evaluasi ditindaklanjuti.',
                            'Tindak lanjut disusun secara terencana.',
                            'Tindak lanjut dilaksanakan sesuai jadwal.',
                            'Tindak lanjut terdokumentasi.',
                        ],
                    ],
                ],
                [
                    'kode' => 'MJR-02',
                    'nama' => 'Supervisi Pengelolaan Unit Kerja',
                    'tujuan' => 'Meningkatkan efektivitas pengelolaan unit kerja melalui monitoring tugas, administrasi, pelayanan, kinerja, dan pencapaian target.',
                    'sasaran' => 'Unit kerja dan penanggung jawab unit kerja.',
                    'target' => 'Setiap unit kerja melaksanakan tugas dan tanggung jawabnya secara efektif serta memiliki administrasi dan pelaporan yang lengkap.',
                    'fokus' => [
                        'Struktur dan Pembagian Tugas' => [
                            'Unit kerja memiliki struktur yang jelas.',
                            'Pembagian tugas diuraikan dengan rinci.',
                            'Setiap petugas memahami tanggung jawabnya.',
                            'Struktur dan tugas terdokumentasi.',
                        ],
                        'Perencanaan' => [
                            'Unit kerja memiliki rencana kerja.',
                            'Rencana memuat target yang jelas.',
                            'Rencana memuat jadwal pelaksanaan.',
                            'Rencana disusun sesuai kebutuhan.',
                        ],
                        'Pelaksanaan' => [
                            'Kegiatan unit kerja dilaksanakan sesuai rencana.',
                            'Pelaksanaan berjalan tertib.',
                            'Pelaksanaan tepat waktu.',
                            'Pelaksanaan terdokumentasi.',
                        ],
                        'Administrasi' => [
                            'Unit kerja memiliki administrasi yang lengkap.',
                            'Administrasi tersimpan dengan rapi.',
                            'Administrasi diperbarui secara berkala.',
                            'Administrasi mudah ditelusuri.',
                        ],
                        'Pelayanan' => [
                            'Unit kerja memberikan pelayanan yang baik.',
                            'Pelayanan dilakukan secara cepat dan tepat.',
                            'Pelayanan mengutamakan kepuasan pengguna.',
                            'Pelayanan sesuai standar yang berlaku.',
                        ],
                        'Sarana dan Prasarana' => [
                            'Sarana unit kerja tersedia dan layak.',
                            'Sarana dimanfaatkan secara efektif.',
                            'Sarana dipelihara dengan baik.',
                            'Kondisi sarana terdokumentasi.',
                        ],
                        'Kinerja' => [
                            'Kinerja unit kerja mencapai target.',
                            'Kinerja diukur secara berkala.',
                            'Kinerja memenuhi standar yang ditetapkan.',
                            'Hasil kinerja terdokumentasi.',
                        ],
                        'Pelaporan' => [
                            'Unit kerja menyusun laporan berkala.',
                            'Laporan memuat capaian kegiatan.',
                            'Laporan disampaikan tepat waktu.',
                            'Laporan terdokumentasi dengan baik.',
                        ],
                        'Evaluasi' => [
                            'Unit kerja melakukan evaluasi berkala.',
                            'Evaluasi memuat capaian target.',
                            'Evaluasi mengidentifikasi kendala.',
                            'Hasil evaluasi digunakan untuk perbaikan.',
                        ],
                    ],
                ],
                [
                    'kode' => 'MJR-03',
                    'nama' => 'Supervisi Kinerja Penanggung Jawab Program',
                    'tujuan' => 'Menilai dan meningkatkan efektivitas kinerja penanggung jawab atau koordinator program dalam melaksanakan tugas dan mencapai target program.',
                    'sasaran' => 'Penanggung jawab program dan koordinator kegiatan.',
                    'target' => 'Penanggung jawab program mampu melaksanakan tugas, mencapai target, menyusun administrasi, dan menyampaikan laporan pertanggungjawaban.',
                    'fokus' => [
                        'Pemahaman Tugas' => [
                            'Penanggung jawab memahami tugas pokoknya.',
                            'Penanggung jawab memahami target program.',
                            'Penanggung jawab memahami mekanisme pelaksanaan.',
                            'Pemahaman tugas sesuai ketentuan.',
                        ],
                        'Perencanaan' => [
                            'Penanggung jawab menyusun rencana kerja.',
                            'Rencana memuat langkah pelaksanaan yang jelas.',
                            'Rencana memuat jadwal kegiatan.',
                            'Rencana disusun secara realistis.',
                        ],
                        'Pelaksanaan' => [
                            'Penanggung jawab melaksanakan tugas sesuai rencana.',
                            'Pelaksanaan berjalan tertib.',
                            'Pelaksanaan tepat waktu.',
                            'Pelaksanaan terdokumentasi.',
                        ],
                        'Koordinasi' => [
                            'Penanggung jawab berkoordinasi dengan pihak terkait.',
                            'Koordinasi berjalan efektif.',
                            'Penanggung jawab melibatkan tim dalam pengambilan keputusan.',
                            'Koordinasi terdokumentasi.',
                        ],
                        'Administrasi' => [
                            'Penanggung jawab menyusun administrasi program.',
                            'Administrasi lengkap dan tertib.',
                            'Administrasi diperbarui sesuai perkembangan.',
                            'Administrasi mudah ditelusuri.',
                        ],
                        'Pencapaian Target' => [
                            'Target program tercapai sesuai rencana.',
                            'Capaian target terukur.',
                            'Penanggung jawab mengatasi kendala pencapaian.',
                            'Capaian target terdokumentasi.',
                        ],
                        'Pelaporan' => [
                            'Penanggung jawab menyusun laporan.',
                            'Laporan memuat capaian dan kendala.',
                            'Laporan disampaikan tepat waktu.',
                            'Laporan terdokumentasi.',
                        ],
                        'Evaluasi' => [
                            'Penanggung jawab melakukan evaluasi program.',
                            'Evaluasi memuat capaian target.',
                            'Evaluasi mengidentifikasi kekuatan dan kelemahan.',
                            'Hasil evaluasi digunakan untuk perbaikan.',
                        ],
                        'Tindak Lanjut' => [
                            'Hasil evaluasi ditindaklanjuti.',
                            'Tindak lanjut disusun secara terencana.',
                            'Tindak lanjut dilaksanakan sesuai jadwal.',
                            'Tindak lanjut terdokumentasi.',
                        ],
                    ],
                ],
            ],
        ];
    }
}

if (!function_exists('sv_template_by_kode')) {
    function sv_template_by_kode(string $kode): ?array
    {
        foreach (sv_program_templates() as $jenis => $programs) {
            foreach ($programs as $p) {
                if (strcasecmp($p['kode'], $kode) === 0) {
                    $p['jenis_supervisi'] = $jenis;
                    return $p;
                }
            }
        }
        return null;
    }
}

if (!function_exists('sv_seed_program_templates')) {
    /**
     * Isi data awal program supervisi secara idempoten.
     * - Tidak menimpa data yang sudah terisi (hanya lengkapi yang kosong).
     * - Mencegah duplikat berdasarkan kode_program / nama_program.
     * @return array{inserted:int,updated:int,skipped:int}
     */
    function sv_seed_program_templates(PDO $pdo, ?string $tahunAjaran = null, ?string $semester = null): array
    {
        $periode = sv_periode($pdo);
        $ta = $tahunAjaran ?: $periode['tahun_ajaran'];
        $sem = $semester ?: $periode['semester'];
        $result = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];

        foreach (sv_program_templates() as $jenis => $programs) {
            foreach ($programs as $tpl) {
                $fokusList = array_keys($tpl['fokus']);
                $fokusText = implode(', ', $fokusList);
                $indikatorText = [];
                foreach ($tpl['fokus'] as $fokus => $indikators) {
                    $indikatorText[] = $fokus . ':';
                    foreach ($indikators as $ind) {
                        $indikatorText[] = '- ' . $ind;
                    }
                }
                $indikatorText = implode("\n", $indikatorText);

                try {
                    $existing = null;
                    if (dbColumnExists($pdo, 'tb_sv_program', 'kode_program')) {
                        $stmt = $pdo->prepare("SELECT * FROM tb_sv_program WHERE kode_program = ? LIMIT 1");
                        $stmt->execute([$tpl['kode']]);
                        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    }
                    if (!$existing) {
                        $stmt = $pdo->prepare("SELECT * FROM tb_sv_program WHERE nama_program = ? LIMIT 1");
                        $stmt->execute([$tpl['nama']]);
                        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    }

                    if (!$existing) {
                        $stmt = $pdo->prepare("INSERT INTO tb_sv_program
                            (kode_program, tahun_ajaran, semester, jenis_supervisi, nama_program, tujuan, sasaran, fokus_supervisi,
                             target, indikator_keberhasilan, penanggung_jawab, status, created_by)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?, 'Aktif', ?)");
                        $stmt->execute([
                            $tpl['kode'],
                            $ta,
                            $sem,
                            $jenis,
                            $tpl['nama'],
                            $tpl['tujuan'],
                            $tpl['sasaran'],
                            $fokusText,
                            $tpl['target'],
                            $indikatorText,
                            'Kepala Madrasah',
                            'system-seed',
                        ]);
                        $result['inserted']++;
                        continue;
                    }

                    // Lengkapi hanya kolom yang kosong; jangan timpa data yang sudah benar.
                    $updates = [];
                    $params = [];
                    $fillMap = [
                        'kode_program' => $tpl['kode'],
                        'jenis_supervisi' => $jenis,
                        'tujuan' => $tpl['tujuan'],
                        'sasaran' => $tpl['sasaran'],
                        'fokus_supervisi' => $fokusText,
                        'target' => $tpl['target'],
                        'indikator_keberhasilan' => $indikatorText,
                    ];
                    foreach ($fillMap as $col => $val) {
                        if (!array_key_exists($col, $existing)) {
                            continue;
                        }
                        if (trim((string)$existing[$col]) === '') {
                            $updates[] = "`{$col}` = ?";
                            $params[] = $val;
                        }
                    }
                    if ($updates) {
                        $params[] = (int)$existing['id_program'];
                        $stmt = $pdo->prepare("UPDATE tb_sv_program SET " . implode(', ', $updates) . " WHERE id_program = ?");
                        $stmt->execute($params);
                        $result['updated']++;
                    } else {
                        $result['skipped']++;
                    }
                } catch (Throwable $e) {
                    error_log('Supervisi seed error (' . $tpl['kode'] . '): ' . $e->getMessage());
                }
            }
        }

        return $result;
    }
}

if (!function_exists('sv_instrumen_templates')) {
    /**
     * Template instrumen default: jenis -> daftar instrumen (kode/nama/tujuan/sasaran/skala/komponen->indikator).
     * Dipakai untuk auto-isi Daftar Instrumen, lalu turun ke Komponen & Indikator.
     */
    function sv_instrumen_templates(): array
    {
        return [
            'Akademik' => [
                [
                    'kode' => 'INS-AKD-01',
                    'nama' => 'Instrumen Supervisi Akademik Proses Pembelajaran',
                    'tujuan' => 'Menilai dan meningkatkan kualitas pelaksanaan pembelajaran melalui observasi terhadap perencanaan, pelaksanaan, asesmen, pengelolaan kelas, karakter, dan refleksi pembelajaran.',
                    'sasaran' => 'Seluruh guru kelas dan guru mata pelajaran',
                    'skala' => '1-4',
                    'komponen' => [
                        ['kode' => 'K1', 'nama' => 'Persiapan Pembelajaran', 'bobot' => 1.00, 'urutan' => 1, 'indikator' => [
                            ['kode' => 'K1.01', 'nama' => 'Dokumen perencanaan pembelajaran tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.02', 'nama' => 'Tujuan pembelajaran tercantum dengan jelas', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.03', 'nama' => 'Materi pembelajaran sesuai dengan tujuan pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.04', 'nama' => 'Metode pembelajaran telah dipersiapkan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.05', 'nama' => 'Media dan sumber belajar telah dipersiapkan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K2', 'nama' => 'Pembukaan Pembelajaran', 'bobot' => 1.00, 'urutan' => 2, 'indikator' => [
                            ['kode' => 'K2.01', 'nama' => 'Guru membuka pembelajaran dengan tertib', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.02', 'nama' => 'Guru menciptakan suasana belajar yang positif', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.03', 'nama' => 'Guru melakukan apersepsi atau mengaitkan materi dengan pengalaman peserta didik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.04', 'nama' => 'Guru memberikan motivasi kepada peserta didik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K3', 'nama' => 'Penyampaian Tujuan Pembelajaran', 'bobot' => 1.00, 'urutan' => 3, 'indikator' => [
                            ['kode' => 'K3.01', 'nama' => 'Guru menyampaikan tujuan pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.02', 'nama' => 'Tujuan disampaikan dengan bahasa yang mudah dipahami peserta didik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.03', 'nama' => 'Kegiatan pembelajaran sesuai dengan tujuan yang disampaikan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K4', 'nama' => 'Penguasaan Materi', 'bobot' => 1.00, 'urutan' => 4, 'indikator' => [
                            ['kode' => 'K4.01', 'nama' => 'Guru menguasai materi pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.02', 'nama' => 'Materi disampaikan secara sistematis', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.03', 'nama' => 'Guru memberikan contoh yang relevan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.04', 'nama' => 'Guru mampu menjawab pertanyaan peserta didik dengan tepat', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K5', 'nama' => 'Metode Pembelajaran', 'bobot' => 1.00, 'urutan' => 5, 'indikator' => [
                            ['kode' => 'K5.01', 'nama' => 'Metode sesuai dengan tujuan pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.02', 'nama' => 'Metode sesuai dengan karakteristik materi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.03', 'nama' => 'Metode melibatkan peserta didik secara aktif', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.04', 'nama' => 'Guru menggunakan variasi metode yang sesuai kebutuhan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K6', 'nama' => 'Media Pembelajaran', 'bobot' => 1.00, 'urutan' => 6, 'indikator' => [
                            ['kode' => 'K6.01', 'nama' => 'Media sesuai dengan materi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.02', 'nama' => 'Media membantu peserta didik memahami materi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.03', 'nama' => 'Media digunakan secara efektif', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.04', 'nama' => 'Sumber belajar dimanfaatkan dengan tepat', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K7', 'nama' => 'Pengelolaan Kelas', 'bobot' => 1.00, 'urutan' => 7, 'indikator' => [
                            ['kode' => 'K7.01', 'nama' => 'Suasana kelas kondusif', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.02', 'nama' => 'Guru mengelola waktu secara efektif', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.03', 'nama' => 'Peserta didik mengikuti pembelajaran dengan tertib', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.04', 'nama' => 'Guru menangani gangguan pembelajaran dengan tepat', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K8', 'nama' => 'Keaktifan Peserta Didik', 'bobot' => 1.00, 'urutan' => 8, 'indikator' => [
                            ['kode' => 'K8.01', 'nama' => 'Peserta didik terlibat dalam kegiatan pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.02', 'nama' => 'Guru memberikan kesempatan kepada peserta didik untuk bertanya', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.03', 'nama' => 'Guru memberikan kesempatan untuk menyampaikan pendapat', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.04', 'nama' => 'Peserta didik terlibat dalam aktivitas pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K9', 'nama' => 'Interaksi Guru dan Peserta Didik', 'bobot' => 1.00, 'urutan' => 9, 'indikator' => [
                            ['kode' => 'K9.01', 'nama' => 'Guru berkomunikasi secara positif', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.02', 'nama' => 'Guru menghargai pendapat peserta didik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.03', 'nama' => 'Guru memberikan kesempatan yang adil kepada peserta didik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.04', 'nama' => 'Guru memberikan umpan balik terhadap proses belajar', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K10', 'nama' => 'Asesmen Pembelajaran', 'bobot' => 1.00, 'urutan' => 10, 'indikator' => [
                            ['kode' => 'K10.01', 'nama' => 'Asesmen sesuai dengan tujuan pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K10.02', 'nama' => 'Teknik asesmen sesuai dengan karakteristik pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K10.03', 'nama' => 'Guru melaksanakan asesmen sesuai rencana', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K10.04', 'nama' => 'Guru memberikan umpan balik terhadap hasil asesmen', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K11', 'nama' => 'Penguatan Karakter', 'bobot' => 1.00, 'urutan' => 11, 'indikator' => [
                            ['kode' => 'K11.01', 'nama' => 'Guru menunjukkan keteladanan dalam sikap dan perilaku', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K11.02', 'nama' => 'Nilai karakter diintegrasikan dalam pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K11.03', 'nama' => 'Guru membiasakan sikap disiplin dan tanggung jawab', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K11.04', 'nama' => 'Guru menciptakan interaksi yang saling menghargai', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K12', 'nama' => 'Integrasi KBC', 'bobot' => 1.00, 'urutan' => 12, 'indikator' => [
                            ['kode' => 'K12.01', 'nama' => 'Nilai cinta diintegrasikan dalam pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K12.02', 'nama' => 'Guru menunjukkan sikap humanis kepada peserta didik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K12.03', 'nama' => 'Peserta didik dibiasakan saling menghargai', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K12.04', 'nama' => 'Pembelajaran memperhatikan kebutuhan dan kondisi peserta didik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K13', 'nama' => 'Penutup dan Refleksi', 'bobot' => 1.00, 'urutan' => 13, 'indikator' => [
                            ['kode' => 'K13.01', 'nama' => 'Guru menyimpulkan materi pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K13.02', 'nama' => 'Guru melakukan refleksi pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K13.03', 'nama' => 'Guru memberikan kesempatan kepada peserta didik menyampaikan kesan atau pemahaman', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K13.04', 'nama' => 'Guru menyampaikan tindak lanjut atau tugas', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K13.05', 'nama' => 'Guru menutup pembelajaran dengan tertib', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                    ],
                ],
                [
                    'kode' => 'INS-AKD-02',
                    'nama' => 'Instrumen Supervisi Perencanaan Pembelajaran',
                    'tujuan' => 'Menilai kelengkapan dan kesesuaian perencanaan pembelajaran yang dibuat dan digunakan guru.',
                    'sasaran' => 'Seluruh guru kelas dan guru mata pelajaran',
                    'skala' => '1-4',
                    'komponen' => [
                        ['kode' => 'K1', 'nama' => 'ATP/Perencanaan Pembelajaran', 'bobot' => 1.00, 'urutan' => 1, 'indikator' => [
                            ['kode' => 'K1.01', 'nama' => 'Dokumen perencanaan tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.02', 'nama' => 'Perencanaan sesuai kurikulum yang berlaku', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.03', 'nama' => 'Urutan pembelajaran tersusun secara sistematis', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.04', 'nama' => 'Perencanaan sesuai kebutuhan peserta didik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K2', 'nama' => 'Modul Ajar/RPP', 'bobot' => 1.00, 'urutan' => 2, 'indikator' => [
                            ['kode' => 'K2.01', 'nama' => 'Modul ajar/RPP tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.02', 'nama' => 'Komponen utama tersusun lengkap', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.03', 'nama' => 'Isi dokumen sesuai dengan pembelajaran yang dilaksanakan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.04', 'nama' => 'Dokumen diperbarui sesuai kebutuhan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K3', 'nama' => 'Tujuan Pembelajaran', 'bobot' => 1.00, 'urutan' => 3, 'indikator' => [
                            ['kode' => 'K3.01', 'nama' => 'Tujuan pembelajaran tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.02', 'nama' => 'Tujuan dirumuskan dengan jelas', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.03', 'nama' => 'Tujuan sesuai dengan kompetensi yang ingin dicapai', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.04', 'nama' => 'Tujuan dapat digunakan sebagai dasar asesmen', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K4', 'nama' => 'Materi Pembelajaran', 'bobot' => 1.00, 'urutan' => 4, 'indikator' => [
                            ['kode' => 'K4.01', 'nama' => 'Materi sesuai tujuan pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.02', 'nama' => 'Materi sesuai tingkat perkembangan peserta didik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.03', 'nama' => 'Materi disusun secara sistematis', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.04', 'nama' => 'Sumber materi tersedia dan relevan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K5', 'nama' => 'Metode Pembelajaran', 'bobot' => 1.00, 'urutan' => 5, 'indikator' => [
                            ['kode' => 'K5.01', 'nama' => 'Metode sesuai tujuan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.02', 'nama' => 'Metode sesuai karakteristik peserta didik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.03', 'nama' => 'Metode mendorong keterlibatan peserta didik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.04', 'nama' => 'Metode mendukung pencapaian tujuan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K6', 'nama' => 'Media Pembelajaran', 'bobot' => 1.00, 'urutan' => 6, 'indikator' => [
                            ['kode' => 'K6.01', 'nama' => 'Media direncanakan dalam pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.02', 'nama' => 'Media sesuai materi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.03', 'nama' => 'Media sesuai kondisi madrasah', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.04', 'nama' => 'Media membantu pencapaian tujuan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K7', 'nama' => 'Asesmen Pembelajaran', 'bobot' => 1.00, 'urutan' => 7, 'indikator' => [
                            ['kode' => 'K7.01', 'nama' => 'Asesmen direncanakan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.02', 'nama' => 'Teknik asesmen sesuai tujuan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.03', 'nama' => 'Instrumen asesmen tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.04', 'nama' => 'Asesmen mencakup aspek yang relevan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K8', 'nama' => 'Kegiatan Pembelajaran', 'bobot' => 1.00, 'urutan' => 8, 'indikator' => [
                            ['kode' => 'K8.01', 'nama' => 'Kegiatan pendahuluan direncanakan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.02', 'nama' => 'Kegiatan inti tersusun jelas', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.03', 'nama' => 'Kegiatan penutup direncanakan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.04', 'nama' => 'Kegiatan mendorong aktivitas peserta didik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K9', 'nama' => 'Penguatan Karakter', 'bobot' => 1.00, 'urutan' => 9, 'indikator' => [
                            ['kode' => 'K9.01', 'nama' => 'Nilai karakter tercantum dalam perencanaan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.02', 'nama' => 'Aktivitas pembelajaran mendukung pembentukan karakter', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.03', 'nama' => 'Nilai disiplin dan tanggung jawab diintegrasikan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K10', 'nama' => 'Integrasi KBC', 'bobot' => 1.00, 'urutan' => 10, 'indikator' => [
                            ['kode' => 'K10.01', 'nama' => 'Nilai KBC tercermin dalam perencanaan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K10.02', 'nama' => 'Kegiatan pembelajaran mengembangkan sikap saling menghargai', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K10.03', 'nama' => 'Pembelajaran memperhatikan aspek kemanusiaan dan kepedulian', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                    ],
                ],
                [
                    'kode' => 'INS-AKD-03',
                    'nama' => 'Instrumen Peningkatan Kualitas Pembelajaran',
                    'tujuan' => 'Mengidentifikasi aspek pembelajaran yang perlu dipertahankan, ditingkatkan, atau diperbaiki melalui supervisi dan tindak lanjut.',
                    'sasaran' => 'Guru kelas dan guru mata pelajaran',
                    'skala' => '1-4',
                    'komponen' => [
                        ['kode' => 'K1', 'nama' => 'Strategi Pembelajaran', 'bobot' => 1.00, 'urutan' => 1, 'indikator' => [
                            ['kode' => 'K1.01', 'nama' => 'Strategi sesuai tujuan pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.02', 'nama' => 'Strategi disesuaikan dengan kondisi peserta didik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.03', 'nama' => 'Strategi mendorong keterlibatan peserta didik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.04', 'nama' => 'Guru melakukan penyesuaian strategi bila diperlukan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K2', 'nama' => 'Pembelajaran Aktif', 'bobot' => 1.00, 'urutan' => 2, 'indikator' => [
                            ['kode' => 'K2.01', 'nama' => 'Peserta didik aktif mengikuti pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.02', 'nama' => 'Guru memberikan kesempatan bertanya', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.03', 'nama' => 'Guru memberikan kesempatan berdiskusi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.04', 'nama' => 'Peserta didik terlibat dalam pemecahan masalah', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K3', 'nama' => 'Pembelajaran Berdiferensiasi', 'bobot' => 1.00, 'urutan' => 3, 'indikator' => [
                            ['kode' => 'K3.01', 'nama' => 'Guru memperhatikan perbedaan kemampuan peserta didik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.02', 'nama' => 'Guru memberikan dukungan sesuai kebutuhan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.03', 'nama' => 'Kegiatan pembelajaran memberikan kesempatan belajar yang sesuai', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.04', 'nama' => 'Guru melakukan penyesuaian pembelajaran berdasarkan kondisi peserta didik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K4', 'nama' => 'Media Pembelajaran', 'bobot' => 1.00, 'urutan' => 4, 'indikator' => [
                            ['kode' => 'K4.01', 'nama' => 'Media digunakan secara tepat', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.02', 'nama' => 'Media membantu pemahaman', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.03', 'nama' => 'Media bervariasi sesuai kebutuhan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.04', 'nama' => 'Guru memanfaatkan sumber belajar yang tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K5', 'nama' => 'Pengelolaan Kelas', 'bobot' => 1.00, 'urutan' => 5, 'indikator' => [
                            ['kode' => 'K5.01', 'nama' => 'Kelas dikelola secara kondusif', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.02', 'nama' => 'Waktu pembelajaran digunakan efektif', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.03', 'nama' => 'Aturan kelas diterapkan secara konsisten', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.04', 'nama' => 'Gangguan pembelajaran ditangani dengan tepat', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K6', 'nama' => 'Asesmen Pembelajaran', 'bobot' => 1.00, 'urutan' => 6, 'indikator' => [
                            ['kode' => 'K6.01', 'nama' => 'Asesmen dilakukan secara berkala', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.02', 'nama' => 'Asesmen sesuai tujuan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.03', 'nama' => 'Hasil asesmen digunakan untuk mengetahui perkembangan peserta didik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.04', 'nama' => 'Hasil asesmen digunakan untuk perbaikan pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K7', 'nama' => 'Refleksi Pembelajaran', 'bobot' => 1.00, 'urutan' => 7, 'indikator' => [
                            ['kode' => 'K7.01', 'nama' => 'Guru melakukan refleksi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.02', 'nama' => 'Guru mengidentifikasi kendala pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.03', 'nama' => 'Guru mengidentifikasi keberhasilan pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.04', 'nama' => 'Hasil refleksi digunakan untuk perbaikan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K8', 'nama' => 'Penguatan Karakter', 'bobot' => 1.00, 'urutan' => 8, 'indikator' => [
                            ['kode' => 'K8.01', 'nama' => 'Pembelajaran mengembangkan karakter positif', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.02', 'nama' => 'Guru memberikan keteladanan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.03', 'nama' => 'Peserta didik dibiasakan bertanggung jawab', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.04', 'nama' => 'Peserta didik dibiasakan bekerja sama dan menghargai orang lain', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K9', 'nama' => 'Integrasi KBC', 'bobot' => 1.00, 'urutan' => 9, 'indikator' => [
                            ['kode' => 'K9.01', 'nama' => 'Pembelajaran mencerminkan nilai cinta', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.02', 'nama' => 'Guru memperlakukan peserta didik secara humanis', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.03', 'nama' => 'Pembelajaran membangun kepedulian', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.04', 'nama' => 'Interaksi pembelajaran berlangsung dengan saling menghargai', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                    ],
                ],
            ],
            'Administrasi' => [
                [
                    'kode' => 'INS-ADM-01',
                    'nama' => 'Instrumen Supervisi Administrasi Guru',
                    'tujuan' => 'Memastikan administrasi guru lengkap, tertib, akurat, dan diperbarui.',
                    'sasaran' => 'Seluruh guru',
                    'skala' => '1-4',
                    'komponen' => [
                        ['kode' => 'K1', 'nama' => 'Administrasi Pembelajaran', 'bobot' => 1.00, 'urutan' => 1, 'indikator' => [
                            ['kode' => 'K1.01', 'nama' => 'Administrasi pembelajaran tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.02', 'nama' => 'Dokumen tersusun dengan tertib', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.03', 'nama' => 'Dokumen diperbarui secara berkala', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.04', 'nama' => 'Dokumen sesuai dengan pelaksanaan pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K2', 'nama' => 'Perencanaan Pembelajaran', 'bobot' => 1.00, 'urutan' => 2, 'indikator' => [
                            ['kode' => 'K2.01', 'nama' => 'Perencanaan tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.02', 'nama' => 'Perencanaan sesuai kurikulum', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.03', 'nama' => 'Perencanaan digunakan dalam pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.04', 'nama' => 'Dokumen perencanaan terdokumentasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K3', 'nama' => 'Jurnal Pembelajaran', 'bobot' => 1.00, 'urutan' => 3, 'indikator' => [
                            ['kode' => 'K3.01', 'nama' => 'Jurnal pembelajaran tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.02', 'nama' => 'Jurnal diisi secara rutin', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.03', 'nama' => 'Isi jurnal sesuai pelaksanaan pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.04', 'nama' => 'Jurnal diperbarui secara tertib', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K4', 'nama' => 'Daftar Hadir', 'bobot' => 1.00, 'urutan' => 4, 'indikator' => [
                            ['kode' => 'K4.01', 'nama' => 'Daftar hadir tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.02', 'nama' => 'Daftar hadir diisi secara rutin', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.03', 'nama' => 'Data kehadiran akurat', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.04', 'nama' => 'Dokumen kehadiran tersimpan dengan baik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K5', 'nama' => 'Administrasi Asesmen', 'bobot' => 1.00, 'urutan' => 5, 'indikator' => [
                            ['kode' => 'K5.01', 'nama' => 'Perencanaan asesmen tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.02', 'nama' => 'Instrumen asesmen tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.03', 'nama' => 'Pelaksanaan asesmen terdokumentasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.04', 'nama' => 'Administrasi asesmen tersusun dengan tertib', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K6', 'nama' => 'Daftar Nilai', 'bobot' => 1.00, 'urutan' => 6, 'indikator' => [
                            ['kode' => 'K6.01', 'nama' => 'Daftar nilai tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.02', 'nama' => 'Nilai diisi secara lengkap', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.03', 'nama' => 'Nilai sesuai hasil asesmen', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.04', 'nama' => 'Daftar nilai diperbarui', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K7', 'nama' => 'Analisis Hasil Belajar', 'bobot' => 1.00, 'urutan' => 7, 'indikator' => [
                            ['kode' => 'K7.01', 'nama' => 'Analisis hasil belajar tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.02', 'nama' => 'Analisis dilakukan berdasarkan hasil asesmen', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.03', 'nama' => 'Peserta didik yang memerlukan tindak lanjut teridentifikasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.04', 'nama' => 'Hasil analisis digunakan untuk perbaikan pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K8', 'nama' => 'Remedial', 'bobot' => 1.00, 'urutan' => 8, 'indikator' => [
                            ['kode' => 'K8.01', 'nama' => 'Program remedial tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.02', 'nama' => 'Peserta didik yang membutuhkan remedial teridentifikasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.03', 'nama' => 'Pelaksanaan remedial terdokumentasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.04', 'nama' => 'Hasil remedial dicatat', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K9', 'nama' => 'Pengayaan', 'bobot' => 1.00, 'urutan' => 9, 'indikator' => [
                            ['kode' => 'K9.01', 'nama' => 'Program pengayaan tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.02', 'nama' => 'Peserta didik sasaran pengayaan teridentifikasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.03', 'nama' => 'Pengayaan dilaksanakan sesuai kebutuhan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.04', 'nama' => 'Pelaksanaan pengayaan terdokumentasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K10', 'nama' => 'Dokumentasi Pembelajaran', 'bobot' => 1.00, 'urutan' => 10, 'indikator' => [
                            ['kode' => 'K10.01', 'nama' => 'Dokumen pembelajaran tersimpan dengan baik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K10.02', 'nama' => 'Dokumen mudah ditemukan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K10.03', 'nama' => 'Dokumentasi diperbarui', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K10.04', 'nama' => 'Arsip tersusun secara sistematis', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                    ],
                ],
                [
                    'kode' => 'INS-ADM-02',
                    'nama' => 'Instrumen Supervisi Administrasi Penilaian',
                    'tujuan' => 'Memastikan seluruh proses administrasi penilaian dilaksanakan secara tertib, objektif, terdokumentasi, dan dapat dipertanggungjawabkan.',
                    'sasaran' => 'Seluruh guru',
                    'skala' => '1-4',
                    'komponen' => [
                        ['kode' => 'K1', 'nama' => 'Perencanaan Asesmen', 'bobot' => 1.00, 'urutan' => 1, 'indikator' => [
                            ['kode' => 'K1.01', 'nama' => 'Perencanaan asesmen tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.02', 'nama' => 'Asesmen sesuai tujuan pembelajaran', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.03', 'nama' => 'Teknik asesmen ditentukan dengan tepat', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.04', 'nama' => 'Jadwal atau rencana asesmen terdokumentasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K2', 'nama' => 'Instrumen Penilaian', 'bobot' => 1.00, 'urutan' => 2, 'indikator' => [
                            ['kode' => 'K2.01', 'nama' => 'Instrumen penilaian tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.02', 'nama' => 'Instrumen sesuai tujuan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.03', 'nama' => 'Instrumen memiliki kriteria penilaian yang jelas', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.04', 'nama' => 'Instrumen terdokumentasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K3', 'nama' => 'Pelaksanaan Penilaian', 'bobot' => 1.00, 'urutan' => 3, 'indikator' => [
                            ['kode' => 'K3.01', 'nama' => 'Penilaian dilaksanakan sesuai rencana', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.02', 'nama' => 'Penilaian dilaksanakan secara tertib', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.03', 'nama' => 'Bukti pelaksanaan penilaian tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.04', 'nama' => 'Hasil penilaian terdokumentasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K4', 'nama' => 'Pengolahan Nilai', 'bobot' => 1.00, 'urutan' => 4, 'indikator' => [
                            ['kode' => 'K4.01', 'nama' => 'Nilai diolah secara sistematis', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.02', 'nama' => 'Pengolahan menggunakan data yang benar', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.03', 'nama' => 'Perhitungan nilai dapat dipertanggungjawabkan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.04', 'nama' => 'Hasil pengolahan terdokumentasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K5', 'nama' => 'Daftar Nilai', 'bobot' => 1.00, 'urutan' => 5, 'indikator' => [
                            ['kode' => 'K5.01', 'nama' => 'Daftar nilai lengkap', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.02', 'nama' => 'Nilai sesuai hasil asesmen', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.03', 'nama' => 'Data peserta didik sesuai', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.04', 'nama' => 'Daftar nilai diperbarui', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K6', 'nama' => 'Analisis Hasil Belajar', 'bobot' => 1.00, 'urutan' => 6, 'indikator' => [
                            ['kode' => 'K6.01', 'nama' => 'Analisis dilakukan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.02', 'nama' => 'Hasil belajar dianalisis berdasarkan kriteria yang digunakan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.03', 'nama' => 'Peserta didik yang memerlukan tindak lanjut teridentifikasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.04', 'nama' => 'Hasil analisis digunakan untuk menentukan tindak lanjut', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K7', 'nama' => 'Remedial', 'bobot' => 1.00, 'urutan' => 7, 'indikator' => [
                            ['kode' => 'K7.01', 'nama' => 'Peserta didik yang belum mencapai kriteria teridentifikasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.02', 'nama' => 'Program remedial disusun', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.03', 'nama' => 'Remedial dilaksanakan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.04', 'nama' => 'Hasil remedial dicatat', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K8', 'nama' => 'Pengayaan', 'bobot' => 1.00, 'urutan' => 8, 'indikator' => [
                            ['kode' => 'K8.01', 'nama' => 'Peserta didik yang memerlukan pengayaan teridentifikasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.02', 'nama' => 'Program pengayaan disusun', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.03', 'nama' => 'Pengayaan dilaksanakan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.04', 'nama' => 'Hasil pengayaan terdokumentasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K9', 'nama' => 'Pelaporan Hasil Belajar', 'bobot' => 1.00, 'urutan' => 9, 'indikator' => [
                            ['kode' => 'K9.01', 'nama' => 'Hasil belajar dilaporkan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.02', 'nama' => 'Data laporan sesuai dengan hasil penilaian', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.03', 'nama' => 'Laporan tersusun secara lengkap', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.04', 'nama' => 'Dokumen pelaporan tersimpan dengan baik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                    ],
                ],
                [
                    'kode' => 'INS-ADM-03',
                    'nama' => 'Instrumen Supervisi Administrasi PTK',
                    'tujuan' => 'Memastikan administrasi pendidik dan tenaga kependidikan lengkap, akurat, tertib, dan diperbarui.',
                    'sasaran' => 'Guru dan tenaga kependidikan',
                    'skala' => '1-4',
                    'komponen' => [
                        ['kode' => 'K1', 'nama' => 'Kelengkapan Dokumen', 'bobot' => 1.00, 'urutan' => 1, 'indikator' => [
                            ['kode' => 'K1.01', 'nama' => 'Dokumen administrasi PTK tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.02', 'nama' => 'Dokumen utama lengkap', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.03', 'nama' => 'Dokumen tersusun dengan baik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.04', 'nama' => 'Dokumen pendukung tersedia sesuai kebutuhan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K2', 'nama' => 'Ketepatan Data', 'bobot' => 1.00, 'urutan' => 2, 'indikator' => [
                            ['kode' => 'K2.01', 'nama' => 'Data PTK sesuai kondisi sebenarnya', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.02', 'nama' => 'Identitas PTK tercatat dengan benar', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.03', 'nama' => 'Data tugas/jabatan sesuai', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.04', 'nama' => 'Tidak terdapat data yang tidak sesuai', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K3', 'nama' => 'Keteraturan Arsip', 'bobot' => 1.00, 'urutan' => 3, 'indikator' => [
                            ['kode' => 'K3.01', 'nama' => 'Arsip tersusun sistematis', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.02', 'nama' => 'Dokumen mudah ditemukan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.03', 'nama' => 'Arsip dikelompokkan berdasarkan jenis dokumen', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.04', 'nama' => 'Arsip terjaga dengan baik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K4', 'nama' => 'Pembaruan Dokumen', 'bobot' => 1.00, 'urutan' => 4, 'indikator' => [
                            ['kode' => 'K4.01', 'nama' => 'Dokumen diperbarui ketika terdapat perubahan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.02', 'nama' => 'Data terbaru digunakan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.03', 'nama' => 'Dokumen lama dikelola dengan baik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.04', 'nama' => 'Pembaruan terdokumentasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K5', 'nama' => 'Administrasi Tugas', 'bobot' => 1.00, 'urutan' => 5, 'indikator' => [
                            ['kode' => 'K5.01', 'nama' => 'Pembagian tugas terdokumentasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.02', 'nama' => 'Tugas sesuai dengan tanggung jawab', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.03', 'nama' => 'Bukti pelaksanaan tugas tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.04', 'nama' => 'Administrasi tugas diperbarui', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K6', 'nama' => 'Dokumentasi Pekerjaan', 'bobot' => 1.00, 'urutan' => 6, 'indikator' => [
                            ['kode' => 'K6.01', 'nama' => 'Bukti pelaksanaan pekerjaan tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.02', 'nama' => 'Dokumentasi tersusun', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.03', 'nama' => 'Dokumentasi sesuai kegiatan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.04', 'nama' => 'Dokumentasi dapat digunakan sebagai bukti pelaksanaan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                    ],
                ],
            ],
            'Manajerial' => [
                [
                    'kode' => 'INS-MJR-01',
                    'nama' => 'Instrumen Supervisi Pengelolaan Program Madrasah',
                    'tujuan' => 'Menilai efektivitas pengelolaan program mulai dari perencanaan sampai tindak lanjut.',
                    'sasaran' => 'Waka, koordinator, penanggung jawab program, dan unit kerja terkait',
                    'skala' => '1-4',
                    'komponen' => [
                        ['kode' => 'K1', 'nama' => 'Perencanaan Program', 'bobot' => 1.00, 'urutan' => 1, 'indikator' => [
                            ['kode' => 'K1.01', 'nama' => 'Program memiliki tujuan yang jelas', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.02', 'nama' => 'Program memiliki sasaran yang jelas', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.03', 'nama' => 'Program memiliki jadwal pelaksanaan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.04', 'nama' => 'Program memiliki target yang terukur', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K2', 'nama' => 'Pembagian Tugas', 'bobot' => 1.00, 'urutan' => 2, 'indikator' => [
                            ['kode' => 'K2.01', 'nama' => 'Penanggung jawab program ditetapkan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.02', 'nama' => 'Tugas dan tanggung jawab jelas', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.03', 'nama' => 'Pembagian tugas terdokumentasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.04', 'nama' => 'Pelaksana memahami tugasnya', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K3', 'nama' => 'Pelaksanaan Program', 'bobot' => 1.00, 'urutan' => 3, 'indikator' => [
                            ['kode' => 'K3.01', 'nama' => 'Program dilaksanakan sesuai rencana', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.02', 'nama' => 'Kegiatan berjalan sesuai jadwal', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.03', 'nama' => 'Pelaksanaan terdokumentasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.04', 'nama' => 'Kendala pelaksanaan ditangani', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K4', 'nama' => 'Administrasi Program', 'bobot' => 1.00, 'urutan' => 4, 'indikator' => [
                            ['kode' => 'K4.01', 'nama' => 'Dokumen program tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.02', 'nama' => 'Administrasi kegiatan lengkap', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.03', 'nama' => 'Bukti pelaksanaan tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.04', 'nama' => 'Arsip program tertata', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K5', 'nama' => 'Pengelolaan Sumber Daya', 'bobot' => 1.00, 'urutan' => 5, 'indikator' => [
                            ['kode' => 'K5.01', 'nama' => 'Sumber daya digunakan sesuai kebutuhan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.02', 'nama' => 'Sarana pendukung tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.03', 'nama' => 'Sumber daya dimanfaatkan secara efektif', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.04', 'nama' => 'Penggunaan sumber daya terdokumentasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K6', 'nama' => 'Monitoring', 'bobot' => 1.00, 'urutan' => 6, 'indikator' => [
                            ['kode' => 'K6.01', 'nama' => 'Monitoring dilaksanakan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.02', 'nama' => 'Hasil monitoring dicatat', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.03', 'nama' => 'Kendala teridentifikasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.04', 'nama' => 'Hasil monitoring digunakan untuk perbaikan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K7', 'nama' => 'Evaluasi', 'bobot' => 1.00, 'urutan' => 7, 'indikator' => [
                            ['kode' => 'K7.01', 'nama' => 'Evaluasi program dilaksanakan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.02', 'nama' => 'Pencapaian target dianalisis', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.03', 'nama' => 'Hambatan diidentifikasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.04', 'nama' => 'Hasil evaluasi terdokumentasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K8', 'nama' => 'Pelaporan', 'bobot' => 1.00, 'urutan' => 8, 'indikator' => [
                            ['kode' => 'K8.01', 'nama' => 'Laporan program tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.02', 'nama' => 'Laporan sesuai pelaksanaan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.03', 'nama' => 'Pencapaian program dicantumkan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.04', 'nama' => 'Kendala dan hasil evaluasi dilaporkan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K9', 'nama' => 'Tindak Lanjut', 'bobot' => 1.00, 'urutan' => 9, 'indikator' => [
                            ['kode' => 'K9.01', 'nama' => 'Rekomendasi perbaikan tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.02', 'nama' => 'Tindak lanjut ditetapkan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.03', 'nama' => 'Penanggung jawab tindak lanjut ditentukan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.04', 'nama' => 'Pelaksanaan tindak lanjut dimonitor', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                    ],
                ],
                [
                    'kode' => 'INS-MJR-02',
                    'nama' => 'Instrumen Supervisi Pengelolaan Unit Kerja',
                    'tujuan' => 'Menilai efektivitas pengelolaan unit kerja dalam menjalankan tugas dan mencapai target.',
                    'sasaran' => 'Unit kerja dan penanggung jawab unit kerja',
                    'skala' => '1-4',
                    'komponen' => [
                        ['kode' => 'K1', 'nama' => 'Struktur dan Pembagian Tugas', 'bobot' => 1.00, 'urutan' => 1, 'indikator' => [
                            ['kode' => 'K1.01', 'nama' => 'Struktur unit kerja jelas', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.02', 'nama' => 'Tugas setiap personel ditetapkan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.03', 'nama' => 'Tanggung jawab terdokumentasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.04', 'nama' => 'Personel memahami tugasnya', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K2', 'nama' => 'Perencanaan', 'bobot' => 1.00, 'urutan' => 2, 'indikator' => [
                            ['kode' => 'K2.01', 'nama' => 'Unit kerja memiliki rencana kerja', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.02', 'nama' => 'Target kerja ditetapkan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.03', 'nama' => 'Jadwal kegiatan tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.04', 'nama' => 'Rencana kerja terdokumentasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K3', 'nama' => 'Pelaksanaan', 'bobot' => 1.00, 'urutan' => 3, 'indikator' => [
                            ['kode' => 'K3.01', 'nama' => 'Kegiatan dilaksanakan sesuai rencana', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.02', 'nama' => 'Tugas dilaksanakan sesuai tanggung jawab', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.03', 'nama' => 'Pelaksanaan terdokumentasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.04', 'nama' => 'Kendala ditangani', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K4', 'nama' => 'Administrasi', 'bobot' => 1.00, 'urutan' => 4, 'indikator' => [
                            ['kode' => 'K4.01', 'nama' => 'Administrasi unit kerja tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.02', 'nama' => 'Dokumen tersusun tertib', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.03', 'nama' => 'Data diperbarui', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.04', 'nama' => 'Arsip mudah ditemukan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K5', 'nama' => 'Pelayanan', 'bobot' => 1.00, 'urutan' => 5, 'indikator' => [
                            ['kode' => 'K5.01', 'nama' => 'Pelayanan dilaksanakan sesuai tugas', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.02', 'nama' => 'Pelayanan diberikan secara tertib', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.03', 'nama' => 'Pengguna layanan mendapatkan informasi yang diperlukan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.04', 'nama' => 'Keluhan atau kendala ditindaklanjuti', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K6', 'nama' => 'Sarana dan Prasarana', 'bobot' => 1.00, 'urutan' => 6, 'indikator' => [
                            ['kode' => 'K6.01', 'nama' => 'Sarana pendukung tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.02', 'nama' => 'Sarana digunakan sesuai fungsi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.03', 'nama' => 'Kondisi sarana dipantau', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.04', 'nama' => 'Kebutuhan sarana teridentifikasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K7', 'nama' => 'Kinerja', 'bobot' => 1.00, 'urutan' => 7, 'indikator' => [
                            ['kode' => 'K7.01', 'nama' => 'Target kerja ditetapkan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.02', 'nama' => 'Pelaksanaan tugas dapat dipantau', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.03', 'nama' => 'Pencapaian target dicatat', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.04', 'nama' => 'Kendala kinerja ditindaklanjuti', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K8', 'nama' => 'Pelaporan', 'bobot' => 1.00, 'urutan' => 8, 'indikator' => [
                            ['kode' => 'K8.01', 'nama' => 'Laporan unit kerja tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.02', 'nama' => 'Laporan sesuai kegiatan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.03', 'nama' => 'Data pendukung tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.04', 'nama' => 'Laporan disampaikan sesuai ketentuan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K9', 'nama' => 'Evaluasi', 'bobot' => 1.00, 'urutan' => 9, 'indikator' => [
                            ['kode' => 'K9.01', 'nama' => 'Evaluasi unit kerja dilaksanakan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.02', 'nama' => 'Hasil evaluasi dicatat', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.03', 'nama' => 'Permasalahan diidentifikasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.04', 'nama' => 'Perbaikan ditindaklanjuti', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                    ],
                ],
                [
                    'kode' => 'INS-MJR-03',
                    'nama' => 'Instrumen Supervisi Kinerja Penanggung Jawab Program',
                    'tujuan' => 'Menilai efektivitas pelaksanaan tugas dan tanggung jawab penanggung jawab atau koordinator program.',
                    'sasaran' => 'Penanggung jawab program dan koordinator kegiatan',
                    'skala' => '1-4',
                    'komponen' => [
                        ['kode' => 'K1', 'nama' => 'Pemahaman Tugas', 'bobot' => 1.00, 'urutan' => 1, 'indikator' => [
                            ['kode' => 'K1.01', 'nama' => 'Penanggung jawab memahami tugasnya', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.02', 'nama' => 'Penanggung jawab memahami target program', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.03', 'nama' => 'Tanggung jawab dilaksanakan sesuai ketentuan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K1.04', 'nama' => 'Penanggung jawab memahami batas kewenangannya', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K2', 'nama' => 'Perencanaan', 'bobot' => 1.00, 'urutan' => 2, 'indikator' => [
                            ['kode' => 'K2.01', 'nama' => 'Rencana kegiatan tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.02', 'nama' => 'Target ditetapkan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.03', 'nama' => 'Jadwal kegiatan tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K2.04', 'nama' => 'Kebutuhan sumber daya diidentifikasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K3', 'nama' => 'Pelaksanaan', 'bobot' => 1.00, 'urutan' => 3, 'indikator' => [
                            ['kode' => 'K3.01', 'nama' => 'Program dilaksanakan sesuai rencana', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.02', 'nama' => 'Tugas dilaksanakan tepat waktu', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.03', 'nama' => 'Kegiatan terdokumentasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K3.04', 'nama' => 'Kendala pelaksanaan ditangani', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K4', 'nama' => 'Koordinasi', 'bobot' => 1.00, 'urutan' => 4, 'indikator' => [
                            ['kode' => 'K4.01', 'nama' => 'Koordinasi dengan pihak terkait dilakukan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.02', 'nama' => 'Informasi kegiatan disampaikan dengan baik', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.03', 'nama' => 'Permasalahan dikomunikasikan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K4.04', 'nama' => 'Hasil koordinasi ditindaklanjuti', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K5', 'nama' => 'Administrasi', 'bobot' => 1.00, 'urutan' => 5, 'indikator' => [
                            ['kode' => 'K5.01', 'nama' => 'Administrasi program lengkap', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.02', 'nama' => 'Dokumen kegiatan tersusun', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.03', 'nama' => 'Data program diperbarui', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K5.04', 'nama' => 'Bukti pelaksanaan tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K6', 'nama' => 'Pencapaian Target', 'bobot' => 1.00, 'urutan' => 6, 'indikator' => [
                            ['kode' => 'K6.01', 'nama' => 'Target program ditetapkan dengan jelas', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.02', 'nama' => 'Pencapaian target dapat diukur', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.03', 'nama' => 'Hasil pelaksanaan dibandingkan dengan target', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K6.04', 'nama' => 'Kekurangan pencapaian ditindaklanjuti', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K7', 'nama' => 'Pelaporan', 'bobot' => 1.00, 'urutan' => 7, 'indikator' => [
                            ['kode' => 'K7.01', 'nama' => 'Laporan kegiatan tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.02', 'nama' => 'Laporan sesuai pelaksanaan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.03', 'nama' => 'Data pendukung lengkap', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K7.04', 'nama' => 'Laporan disampaikan tepat waktu', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K8', 'nama' => 'Evaluasi', 'bobot' => 1.00, 'urutan' => 8, 'indikator' => [
                            ['kode' => 'K8.01', 'nama' => 'Evaluasi program dilakukan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.02', 'nama' => 'Keberhasilan diidentifikasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.03', 'nama' => 'Kendala diidentifikasi', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K8.04', 'nama' => 'Hasil evaluasi digunakan untuk perbaikan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                        ['kode' => 'K9', 'nama' => 'Tindak Lanjut', 'bobot' => 1.00, 'urutan' => 9, 'indikator' => [
                            ['kode' => 'K9.01', 'nama' => 'Rekomendasi perbaikan tersedia', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.02', 'nama' => 'Tindak lanjut ditetapkan', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.03', 'nama' => 'Penanggung jawab tindak lanjut jelas', 'bobot' => 1, 'min' => 1, 'max' => 4],
                            ['kode' => 'K9.04', 'nama' => 'Pelaksanaan tindak lanjut dimonitor', 'bobot' => 1, 'min' => 1, 'max' => 4],
                        ]],
                    ],
                ],
            ],
        ];
    }
}

if (!function_exists('sv_seed_instrumen_templates')) {
    /**
     * @return array{ins:int,komp:int,ind:int}
     */
    function sv_seed_instrumen_templates(PDO $pdo): array
    {
        $out = ['ins' => 0, 'komp' => 0, 'ind' => 0];
        foreach (sv_instrumen_templates() as $jenis => $daftar) {
            foreach ($daftar as $tpl) {
                $insId = null;
                try {
                    $stmt = $pdo->prepare("SELECT id_instrumen FROM tb_sv_instrumen WHERE kode_instrumen = ? LIMIT 1");
                    $stmt->execute([$tpl['kode']]);
                    $raw = $stmt->fetchColumn();
                    $insId = ($raw !== false && $raw !== null) ? (int)$raw : null;
                } catch (Throwable $e) {
                }
                if (!$insId) {
                    try {
                        $stmt = $pdo->prepare("SELECT id_instrumen FROM tb_sv_instrumen WHERE nama_instrumen = ? LIMIT 1");
                        $stmt->execute([$tpl['nama']]);
                        $raw = $stmt->fetchColumn();
                        $insId = ($raw !== false && $raw !== null) ? (int)$raw : null;
                    } catch (Throwable $e) {
                    }
                }
                if (!$insId) {
                    $stmt = $pdo->prepare("INSERT INTO tb_sv_instrumen (kode_instrumen, nama_instrumen, jenis_supervisi, tujuan, sasaran, skala_penilaian, status) VALUES (?,?,?,?,?,?,?)");
                    $stmt->execute([$tpl['kode'], $tpl['nama'], $jenis, $tpl['tujuan'], $tpl['sasaran'], $tpl['skala'], 'Aktif']);
                    $insId = (int)$pdo->lastInsertId();
                    $out['ins']++;
                } else {
                    $pdo->prepare("UPDATE tb_sv_instrumen SET nama_instrumen = ?, jenis_supervisi = ?, tujuan = ?, sasaran = ?, skala_penilaian = ? WHERE id_instrumen = ?")
                        ->execute([$tpl['nama'], $jenis, $tpl['tujuan'], $tpl['sasaran'], $tpl['skala'], $insId]);
                }
                foreach ($tpl['komponen'] as $k) {
                    $kompId = null;
                    try {
                        $stmt = $pdo->prepare("SELECT id_komponen FROM tb_sv_komponen WHERE id_instrumen = ? AND kode_komponen = ? LIMIT 1");
                        $stmt->execute([$insId, $k['kode']]);
                        $raw = $stmt->fetchColumn();
                        $kompId = ($raw !== false && $raw !== null) ? (int)$raw : null;
                    } catch (Throwable $e) {
                    }
                    if (!$kompId) {
                        try {
                            $stmt = $pdo->prepare("SELECT id_komponen FROM tb_sv_komponen WHERE id_instrumen = ? AND nama_komponen = ? LIMIT 1");
                            $stmt->execute([$insId, $k['nama']]);
                            $raw = $stmt->fetchColumn();
                            $kompId = ($raw !== false && $raw !== null) ? (int)$raw : null;
                        } catch (Throwable $e) {
                        }
                    }
                    if (!$kompId) {
                        $stmt = $pdo->prepare("INSERT INTO tb_sv_komponen (id_instrumen, kode_komponen, nama_komponen, bobot, urutan) VALUES (?,?,?,?,?)");
                        $stmt->execute([$insId, $k['kode'], $k['nama'], $k['bobot'], $k['urutan']]);
                        $kompId = (int)$pdo->lastInsertId();
                        $out['komp']++;
                    }
                    foreach ($k['indikator'] as $idx => $ind) {
                        $urutan = $idx + 1;
                        $indId = null;
                        try {
                            $stmt = $pdo->prepare("SELECT id_indikator FROM tb_sv_indikator WHERE id_komponen = ? AND kode_indikator = ? LIMIT 1");
                            $stmt->execute([$kompId, $ind['kode']]);
                            $raw = $stmt->fetchColumn();
                            $indId = ($raw !== false && $raw !== null) ? (int)$raw : null;
                        } catch (Throwable $e) {
                        }
                        if ($indId) {
                            continue;
                        }
                        try {
                            $stmt = $pdo->prepare("SELECT id_indikator FROM tb_sv_indikator WHERE id_komponen = ? AND indikator = ? LIMIT 1");
                            $stmt->execute([$kompId, $ind['nama']]);
                            $raw = $stmt->fetchColumn();
                            $indId = ($raw !== false && $raw !== null) ? (int)$raw : null;
                        } catch (Throwable $e) {
                        }
                        if ($indId) {
                            continue;
                        }
                        $stmt = $pdo->prepare("INSERT INTO tb_sv_indikator (id_komponen, kode_indikator, indikator, bobot, skor_minimal, skor_maksimal, urutan) VALUES (?,?,?,?,?,?,?)");
                        $stmt->execute([$kompId, $ind['kode'], $ind['nama'], $ind['bobot'], $ind['min'], $ind['max'], $urutan]);
                        $out['ind']++;
                    }
                }
            }
        }
        return $out;
    }
}

if (!function_exists('sv_laporan_jenis_list')) {
    function sv_laporan_jenis_list(): array
    {
        return [
            'program_supervisi' => 'Program Supervisi',
            'jadwal_supervisi' => 'Jadwal Supervisi',
            'hasil_supervisi_guru' => 'Hasil Supervisi Guru',
            'supervisi_administrasi' => 'Supervisi Administrasi',
            'supervisi_akademik' => 'Supervisi Akademik',
            'supervisi_manajerial' => 'Supervisi Manajerial',
            'rekap_nilai' => 'Rekap Nilai',
            'rekap_temuan' => 'Rekap Temuan',
            'rekap_tindak_lanjut' => 'Rekap Tindak Lanjut',
            'monitoring_tindak_lanjut' => 'Monitoring Tindak Lanjut',
            'laporan_semester' => 'Laporan Semester',
            'laporan_tahunan' => 'Laporan Tahunan',
            'berita_acara_supervisi' => 'Berita Acara Supervisi',
            'catatan_pembinaan' => 'Catatan Pembinaan',
            'rekomendasi_hasil_supervisi' => 'Rekomendasi Hasil Supervisi',
        ];
    }
}

if (!function_exists('sv_laporan_data')) {
    /**
     * Bangun data laporan supervisi.
     * @return array{title:string,headers:array,rows:array}|null
     */
    function sv_laporan_data(PDO $pdo, string $jenis, array $opt = []): ?array
    {
        $ta = trim((string)($opt['tahun_ajaran'] ?? ''));
        $sem = trim((string)($opt['semester'] ?? ''));
        $parts = explode('/', $ta);
        $taStart = ($parts[0] ?? date('Y')) . '-07-01';
        $taEnd = ($parts[1] ?? ((int)date('Y') + 1)) . '-06-30';
        $range = [$taStart, $taEnd];

        $title = sv_laporan_jenis_list()[$jenis] ?? 'Laporan Supervisi';

        try {
            switch ($jenis) {
                case 'program_supervisi':
                    $stmt = $pdo->prepare("SELECT tahun_ajaran, semester, jenis_supervisi, nama_program, tujuan, sasaran, target, penanggung_jawab, status
                        FROM tb_sv_program WHERE tahun_ajaran = ?" . ($sem !== '' ? " AND semester = ?" : "") . " ORDER BY nama_program ASC");
                    $stmt->execute($sem !== '' ? [$ta, $sem] : [$ta]);
                    return [
                        'title' => $title,
                        'headers' => ['Tahun Ajaran', 'Semester', 'Jenis', 'Nama Program', 'Tujuan', 'Sasaran', 'Target', 'Penanggung Jawab', 'Status'],
                        'rows' => $stmt->fetchAll(PDO::FETCH_NUM),
                    ];

                case 'jadwal_supervisi':
                    $stmt = $pdo->prepare("SELECT j.tanggal, j.jam_mulai, j.jam_selesai, j.nama_guru, j.jenis_supervisi, j.supervisor, j.tempat, j.status
                        FROM tb_sv_jadwal j WHERE j.tanggal BETWEEN ? AND ? ORDER BY j.tanggal ASC");
                    $stmt->execute($range);
                    return [
                        'title' => $title,
                        'headers' => ['Tanggal', 'Jam Mulai', 'Jam Selesai', 'Guru/PTK', 'Jenis', 'Supervisor', 'Tempat', 'Status'],
                        'rows' => $stmt->fetchAll(PDO::FETCH_NUM),
                    ];

                case 'hasil_supervisi_guru':
                    $stmt = $pdo->prepare("SELECT p.tanggal, p.nama_guru, p.jenis_supervisi, p.supervisor, p.nilai, p.predikat, p.temuan, p.rekomendasi, p.status
                        FROM tb_sv_pelaksanaan p WHERE p.tanggal BETWEEN ? AND ? AND p.jenis_supervisi <> 'Manajerial' ORDER BY p.nama_guru ASC");
                    $stmt->execute($range);
                    return [
                        'title' => $title,
                        'headers' => ['Tanggal', 'Guru', 'Jenis', 'Supervisor', 'Nilai', 'Predikat', 'Temuan', 'Rekomendasi', 'Status'],
                        'rows' => $stmt->fetchAll(PDO::FETCH_NUM),
                    ];

                case 'supervisi_administrasi':
                case 'supervisi_akademik':
                case 'supervisi_manajerial':
                    $map = [
                        'supervisi_administrasi' => 'Administrasi',
                        'supervisi_akademik' => 'Akademik',
                        'supervisi_manajerial' => 'Manajerial',
                    ];
                    $stmt = $pdo->prepare("SELECT p.tanggal, COALESCE(NULLIF(p.nama_guru,''), p.unit_bagian) AS subjek, p.supervisor, i.nama_instrumen, p.nilai, p.predikat, p.temuan, p.rekomendasi, p.status
                        FROM tb_sv_pelaksanaan p LEFT JOIN tb_sv_instrumen i ON i.id_instrumen = p.id_instrumen
                        WHERE p.tanggal BETWEEN ? AND ? AND p.jenis_supervisi = ? ORDER BY p.tanggal ASC");
                    $stmt->execute(array_merge($range, [$map[$jenis]]));
                    return [
                        'title' => $title,
                        'headers' => ['Tanggal', 'Guru/Unit', 'Supervisor', 'Instrumen', 'Nilai', 'Predikat', 'Temuan', 'Rekomendasi', 'Status'],
                        'rows' => $stmt->fetchAll(PDO::FETCH_NUM),
                    ];

                case 'rekap_nilai':
                    $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(p.nama_guru,''), p.unit_bagian) AS subjek, COUNT(*) AS jml, ROUND(AVG(p.nilai),2) AS rata, MAX(p.nilai) AS maxi, MIN(p.nilai) AS mini
                        FROM tb_sv_pelaksanaan p WHERE p.tanggal BETWEEN ? AND ? AND p.status = 'Selesai'
                        GROUP BY subjek ORDER BY rata DESC");
                    $stmt->execute($range);
                    return [
                        'title' => $title,
                        'headers' => ['Guru/Unit', 'Jumlah Supervisi', 'Nilai Rata-rata', 'Tertinggi', 'Terendah'],
                        'rows' => $stmt->fetchAll(PDO::FETCH_NUM),
                    ];

                case 'rekap_temuan':
                    $stmt = $pdo->prepare("SELECT p.tanggal, COALESCE(NULLIF(p.nama_guru,''), p.unit_bagian) AS subjek, p.jenis_supervisi, p.temuan, p.rekomendasi
                        FROM tb_sv_pelaksanaan p WHERE p.tanggal BETWEEN ? AND ? AND p.temuan IS NOT NULL AND TRIM(p.temuan) <> '' ORDER BY p.tanggal DESC");
                    $stmt->execute($range);
                    return [
                        'title' => $title,
                        'headers' => ['Tanggal', 'Guru/Unit', 'Jenis', 'Temuan', 'Rekomendasi'],
                        'rows' => $stmt->fetchAll(PDO::FETCH_NUM),
                    ];

                case 'rekap_tindak_lanjut':
                    $stmt = $pdo->prepare("SELECT t.nama_guru, t.unit_bagian, t.temuan, t.bentuk_tindak_lanjut, t.rencana_tindakan, t.target_selesai, t.status, t.tanggal_selesai
                        FROM tb_sv_tindak_lanjut t ORDER BY t.target_selesai ASC");
                    $stmt->execute();
                    return [
                        'title' => $title,
                        'headers' => ['Guru', 'Unit', 'Temuan', 'Bentuk', 'Rencana', 'Target Selesai', 'Status', 'Tanggal Selesai'],
                        'rows' => $stmt->fetchAll(PDO::FETCH_NUM),
                    ];

                case 'monitoring_tindak_lanjut':
                    $stmt = $pdo->prepare("SELECT m.tanggal_monitoring, COALESCE(NULLIF(m.nama_guru,''), m.unit_bagian) AS subjek, m.monitoring_ke, m.hasil_monitoring, m.perubahan, m.nilai_sebelum, m.nilai_sesudah, m.status
                        FROM tb_sv_monitoring m ORDER BY m.tanggal_monitoring DESC");
                    $stmt->execute();
                    return [
                        'title' => $title,
                        'headers' => ['Tanggal', 'Guru/Unit', 'Monitoring Ke', 'Hasil', 'Perubahan', 'Nilai Sebelum', 'Nilai Sesudah', 'Status'],
                        'rows' => $stmt->fetchAll(PDO::FETCH_NUM),
                    ];

                case 'laporan_semester':
                    $stmt = $pdo->prepare("SELECT p.jenis_supervisi, COUNT(*) AS jml, ROUND(AVG(p.nilai),2) AS rata,
                            SUM(CASE WHEN p.temuan IS NOT NULL AND TRIM(p.temuan) <> '' THEN 1 ELSE 0 END) AS temuan
                        FROM tb_sv_pelaksanaan p WHERE p.tanggal BETWEEN ? AND ? GROUP BY p.jenis_supervisi");
                    $stmt->execute($range);
                    return [
                        'title' => $title,
                        'headers' => ['Jenis Supervisi', 'Jumlah', 'Nilai Rata-rata', 'Jumlah Temuan'],
                        'rows' => $stmt->fetchAll(PDO::FETCH_NUM),
                    ];

                case 'laporan_tahunan':
                    $stmt = $pdo->prepare("SELECT DATE_FORMAT(p.tanggal, '%Y-%m') AS bulan, COUNT(*) AS jml, ROUND(AVG(p.nilai),2) AS rata,
                            SUM(CASE WHEN p.temuan IS NOT NULL AND TRIM(p.temuan) <> '' THEN 1 ELSE 0 END) AS temuan
                        FROM tb_sv_pelaksanaan p WHERE p.tanggal BETWEEN ? AND ? GROUP BY bulan ORDER BY bulan ASC");
                    $stmt->execute($range);
                    return [
                        'title' => $title,
                        'headers' => ['Bulan', 'Jumlah', 'Nilai Rata-rata', 'Jumlah Temuan'],
                        'rows' => $stmt->fetchAll(PDO::FETCH_NUM),
                    ];

                case 'berita_acara_supervisi':
                    $stmt = $pdo->prepare("SELECT p.tanggal, COALESCE(NULLIF(p.nama_guru,''), p.unit_bagian) AS subjek, p.jenis_supervisi, p.supervisor, p.nilai, p.predikat, p.temuan, p.rekomendasi, p.keterangan
                        FROM tb_sv_pelaksanaan p WHERE p.tanggal BETWEEN ? AND ? AND p.status = 'Selesai' ORDER BY p.tanggal DESC");
                    $stmt->execute($range);
                    return [
                        'title' => $title,
                        'headers' => ['Tanggal', 'Guru/Unit', 'Jenis', 'Supervisor', 'Nilai', 'Predikat', 'Temuan', 'Rekomendasi', 'Keterangan'],
                        'rows' => $stmt->fetchAll(PDO::FETCH_NUM),
                    ];

                case 'catatan_pembinaan':
                    $stmt = $pdo->prepare("SELECT t.nama_guru, t.unit_bagian, t.bentuk_tindak_lanjut, t.rencana_tindakan, t.penanggung_jawab, t.catatan, t.status
                        FROM tb_sv_tindak_lanjut t WHERE t.bentuk_tindak_lanjut IN ('Pembinaan','Pendampingan','Coaching','Konsultasi') ORDER BY t.nama_guru ASC");
                    $stmt->execute();
                    return [
                        'title' => $title,
                        'headers' => ['Guru', 'Unit', 'Bentuk', 'Rencana', 'Penanggung Jawab', 'Catatan', 'Status'],
                        'rows' => $stmt->fetchAll(PDO::FETCH_NUM),
                    ];

                case 'rekomendasi_hasil_supervisi':
                    $stmt = $pdo->prepare("SELECT p.tanggal, COALESCE(NULLIF(p.nama_guru,''), p.unit_bagian) AS subjek, p.jenis_supervisi, p.nilai, p.predikat, p.rekomendasi, p.prioritas_perbaikan
                        FROM tb_sv_pelaksanaan p WHERE p.tanggal BETWEEN ? AND ? AND p.rekomendasi IS NOT NULL AND TRIM(p.rekomendasi) <> '' ORDER BY p.tanggal DESC");
                    $stmt->execute($range);
                    return [
                        'title' => $title,
                        'headers' => ['Tanggal', 'Guru/Unit', 'Jenis', 'Nilai', 'Predikat', 'Rekomendasi', 'Prioritas Perbaikan'],
                        'rows' => $stmt->fetchAll(PDO::FETCH_NUM),
                    ];
            }
        } catch (Throwable $e) {
            return null;
        }

        return null;
    }
}

if (!function_exists('sv_filter_where')) {
    function sv_filter_where(array $filters, array $allowed): array
    {
        $where = [];
        $params = [];
        foreach ($filters as $col => $val) {
            if (!in_array($col, $allowed, true)) {
                continue;
            }
            $val = trim((string)$val);
            if ($val === '') {
                continue;
            }
            $where[] = "`{$col}` = ?";
            $params[] = $val;
        }
        return [$where ? (' WHERE ' . implode(' AND ', $where)) : '', $params];
    }
}

if (!function_exists('sv_hasil_kategori_list')) {
    function sv_hasil_kategori_list(): array
    {
        return ['kekuatan' => 'Kekuatan', 'kelemahan' => 'Kelemahan', 'rekomendasi' => 'Rekomendasi', 'prioritas' => 'Prioritas Perbaikan'];
    }
}

if (!function_exists('sv_seed_hasil_master')) {
    function sv_seed_hasil_master(PDO $pdo): void
    {
        $data = [
            'kekuatan' => [
                'Penguasaan materi pembelajaran sangat baik dan sistematis',
                'Pengelolaan kelas efektif, kondusif dan menyenangkan',
                'Penggunaan media pembelajaran variatif dan relevan',
                'Interaksi dengan peserta didik sangat baik dan komunikatif',
                'Perencanaan pembelajaran lengkap dan sesuai tujuan',
                'Motivasi dan apersepsi dilakukan dengan baik',
                'Asesmen dilaksanakan sesuai tujuan pembelajaran',
                'Integrasi nilai karakter dan KBC berjalan baik',
            ],
            'kelemahan' => [
                'Pemanfaatan media pembelajaran masih terbatas',
                'Pengelolaan waktu pembelajaran belum optimal',
                'Apersepsi kurang mengaitkan materi sebelumnya',
                'Variasi metode pembelajaran masih minim',
                'Asesmen belum mencakup sikap, pengetahuan dan keterampilan',
                'Umpan balik terhadap hasil belajar belum maksimal',
                'Pengelolaan kelas perlu ditingkatkan',
                'Dokumentasi pembelajaran belum tertib',
            ],
            'rekomendasi' => [
                'Tingkatkan variasi metode pembelajaran yang aktif dan menyenangkan',
                'Optimalkan penggunaan media berbasis teknologi',
                'Lakukan refleksi pembelajaran secara rutin bersama peserta didik',
                'Ikuti workshop dan pendampingan peningkatan kompetensi',
                'Susun asesmen yang bervariasi sesuai karakteristik peserta didik',
                'Tingkatkan pengelolaan waktu dan pengelolaan kelas',
                'Perkuat integrasi penguatan karakter dan KBC',
                'Lengkapi dan tertibkan dokumentasi pembelajaran',
            ],
            'prioritas' => [
                'Perbaikan RPP / Modul Ajar',
                'Penguatan Pengelolaan Kelas',
                'Peningkatan Asesmen Pembelajaran',
                'Pengembangan Media Pembelajaran',
                'Penguatan Karakter Peserta Didik',
                'Integrasi Kurikulum Berbasis Cinta (KBC)',
                'Optimalisasi Waktu Pembelajaran',
                'Pendampingan dan Pembinaan Berkelanjutan',
            ],
        ];
        foreach ($data as $kategori => $list) {
            $urut = 1;
            foreach ($list as $teks) {
                try {
                    $chk = $pdo->prepare("SELECT id_master FROM tb_sv_hasil_master WHERE kategori = ? AND teks = ? LIMIT 1");
                    $chk->execute([$kategori, $teks]);
                    if ($chk->fetch()) continue;
                    $ins = $pdo->prepare("INSERT INTO tb_sv_hasil_master (kategori, teks, urutan, is_aktif) VALUES (?,?,?,1)");
                    $ins->execute([$kategori, $teks, $urut]);
                } catch (Throwable $e) {}
                $urut++;
            }
        }
    }
}

if (!function_exists('sv_hasil_options')) {
    function sv_hasil_options(PDO $pdo, string $kategori): array
    {
        try {
            $stmt = $pdo->prepare("SELECT teks FROM tb_sv_hasil_master WHERE kategori = ? AND is_aktif = 1 ORDER BY urutan ASC, teks ASC");
            $stmt->execute([$kategori]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('sv_hasil_master_rows')) {
    function sv_hasil_master_rows(PDO $pdo): array
    {
        try {
            return $pdo->query("SELECT * FROM tb_sv_hasil_master ORDER BY kategori ASC, urutan ASC, teks ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}
