<?php
/**
 * RKJM (Rencana Kerja Jangka Menengah) helper.
 * Skema, seed template bawaan, dan utilitas.
 */

if (!function_exists('rkjmEnsureSchema')) {

    function rkjmEnsureSchema(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS rkjm_fields (
                id INT PRIMARY KEY AUTO_INCREMENT,
                kode VARCHAR(20) NOT NULL,
                nama VARCHAR(255) NOT NULL,
                urutan INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_rkjm_fields_urutan (urutan)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS rkjm_documents (
                id INT PRIMARY KEY AUTO_INCREMENT,
                nama_madrasah VARCHAR(255) NULL,
                nomor_dokumen VARCHAR(100) NULL,
                tahun_awal YEAR NOT NULL,
                tahun_akhir YEAR NOT NULL,
                visi TEXT NULL,
                misi TEXT NULL,
                tujuan_pengembangan TEXT NULL,
                kondisi_awal TEXT NULL,
                dasar_penyusunan TEXT NULL,
                tanggal_penyusunan DATE NULL,
                nama_kepala VARCHAR(255) NULL,
                status ENUM('draf','penyusunan','final') NOT NULL DEFAULT 'draf',
                created_by VARCHAR(100) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_rkjm_doc_periode (tahun_awal, tahun_akhir),
                INDEX idx_rkjm_doc_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS rkjm_template_programs (
                id INT PRIMARY KEY AUTO_INCREMENT,
                kode VARCHAR(30) NOT NULL,
                field_id INT NULL,
                bidang_nama VARCHAR(255) NULL,
                nama_program VARCHAR(255) NOT NULL,
                latar_belakang TEXT NULL,
                permasalahan TEXT NULL,
                tujuan TEXT NULL,
                sasaran TEXT NULL,
                indikator TEXT NULL,
                target_akhir TEXT NULL,
                target_tahun1 TEXT NULL,
                target_tahun2 TEXT NULL,
                target_tahun3 TEXT NULL,
                target_tahun4 TEXT NULL,
                kegiatan_utama TEXT NULL,
                penanggung_jawab VARCHAR(255) NULL,
                estimasi_anggaran DECIMAL(15,2) DEFAULT 0,
                sumber_dana VARCHAR(255) NULL,
                dokumen_bukti TEXT NULL,
                monitoring_evaluasi TEXT NULL,
                catatan TEXT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_rkjm_tpl_field (field_id),
                INDEX idx_rkjm_tpl_active (is_active),
                INDEX idx_rkjm_tpl_kode (kode)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS rkjm_programs (
                id INT PRIMARY KEY AUTO_INCREMENT,
                document_id INT NOT NULL,
                template_id INT NULL,
                field_id INT NULL,
                bidang_nama VARCHAR(255) NULL,
                kode_program VARCHAR(30) NULL,
                nama_program VARCHAR(255) NOT NULL,
                kondisi_awal TEXT NULL,
                permasalahan TEXT NULL,
                tujuan TEXT NULL,
                sasaran TEXT NULL,
                indikator TEXT NULL,
                target_akhir TEXT NULL,
                penanggung_jawab VARCHAR(255) NULL,
                estimasi_anggaran DECIMAL(15,2) DEFAULT 0,
                sumber_dana VARCHAR(255) NULL,
                dokumen_bukti TEXT NULL,
                monitoring_evaluasi TEXT NULL,
                kegiatan_utama TEXT NULL,
                catatan TEXT NULL,
                status ENUM('belum_dimulai','berjalan','tercapai','tidak_tercapai','ditunda') DEFAULT 'belum_dimulai',
                urutan INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_rkjm_prog_doc (document_id),
                INDEX idx_rkjm_prog_field (field_id),
                INDEX idx_rkjm_prog_status (status),
                INDEX idx_rkjm_prog_tpl (template_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS rkjm_program_targets (
                id INT PRIMARY KEY AUTO_INCREMENT,
                program_id INT NOT NULL,
                tahun_ke TINYINT NOT NULL,
                tahun_kalender YEAR NULL,
                target TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_rkjm_target (program_id, tahun_ke),
                INDEX idx_rkjm_target_prog (program_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS rkjm_monitoring (
                id INT PRIMARY KEY AUTO_INCREMENT,
                program_id INT NOT NULL,
                tahun_kalender YEAR NULL,
                target_tahun_berjalan TEXT NULL,
                realisasi TEXT NULL,
                persentase_capaian DECIMAL(5,2) NULL,
                penilaian_kualitatif VARCHAR(50) NULL,
                status_pelaksanaan ENUM('belum_dimulai','berjalan','tercapai','tidak_tercapai','ditunda') DEFAULT 'belum_dimulai',
                kendala TEXT NULL,
                tindak_lanjut TEXT NULL,
                tanggal_evaluasi DATE NULL,
                petugas_evaluasi VARCHAR(255) NULL,
                catatan_evaluasi TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_rkjm_mon_prog (program_id),
                INDEX idx_rkjm_mon_tahun (tahun_kalender)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS rkjm_meta (
                meta_key VARCHAR(64) PRIMARY KEY,
                meta_value TEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $e) {
            error_log('RKJM schema error: ' . $e->getMessage());
            return;
        }

        rkjmEnsureColumns($pdo);
        rkjmSeedFields($pdo);
        rkjmSeedTemplates($pdo);
        rkjmSeedMonitoringExamples($pdo);
        rkjmEnsureForeignKeys($pdo);
    }

    /** Tambahkan kolom baru pada instalasi lama tanpa mereset data. */
    function rkjmEnsureColumns(PDO $pdo): void
    {
        $columns = [
            ['kondisi_awal', 'TEXT NULL'],
            ['dasar_penyusunan', 'TEXT NULL'],
        ];
        foreach ($columns as [$column, $definition]) {
            try {
                $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rkjm_documents' AND COLUMN_NAME = ?");
                $check->execute([$column]);
                if ((int)$check->fetchColumn() > 0) {
                    continue;
                }
                $pdo->exec("ALTER TABLE `rkjm_documents` ADD COLUMN `$column` $definition");
            } catch (PDOException $e) {
                error_log('RKJM column skip (' . $column . '): ' . $e->getMessage());
            }
        }

        // Kolom tambahan rkjm_monitoring agar setara dengan rkt_monitoring
        $monCols = [
            ['tanggal_pelaksanaan', 'DATE NULL AFTER `tahun_kalender`'],
            ['realisasi_biaya',     'DECIMAL(15,2) NULL AFTER `tindak_lanjut`'],
        ];
        foreach ($monCols as [$col, $def]) {
            try {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rkjm_monitoring' AND COLUMN_NAME = ?");
                $chk->execute([$col]);
                if ((int)$chk->fetchColumn() > 0) continue;
                $pdo->exec("ALTER TABLE `rkjm_monitoring` ADD COLUMN `$col` $def");
            } catch (PDOException $e) {
                error_log('RKJM monitoring column skip (' . $col . '): ' . $e->getMessage());
            }
        }
    }

    /** Tambahkan foreign key bila belum ada (aman untuk database berjalan). */
    function rkjmEnsureForeignKeys(PDO $pdo): void
    {
        $fks = [
            ['rkjm_template_programs', 'fk_rkjm_tpl_field', 'field_id', 'rkjm_fields', 'id', 'SET NULL'],
            ['rkjm_programs', 'fk_rkjm_prog_doc', 'document_id', 'rkjm_documents', 'id', 'CASCADE'],
            ['rkjm_programs', 'fk_rkjm_prog_field', 'field_id', 'rkjm_fields', 'id', 'SET NULL'],
            ['rkjm_programs', 'fk_rkjm_prog_tpl', 'template_id', 'rkjm_template_programs', 'id', 'SET NULL'],
            ['rkjm_program_targets', 'fk_rkjm_target_prog', 'program_id', 'rkjm_programs', 'id', 'CASCADE'],
            ['rkjm_monitoring', 'fk_rkjm_mon_prog', 'program_id', 'rkjm_programs', 'id', 'CASCADE'],
        ];
        foreach ($fks as [$table, $name, $column, $refTable, $refColumn, $onDelete]) {
            try {
                $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
                $check->execute([$table, $name]);
                if ((int)$check->fetchColumn() > 0) {
                    continue;
                }
                $pdo->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` FOREIGN KEY (`$column`)
                    REFERENCES `$refTable` (`$refColumn`) ON DELETE $onDelete ON UPDATE CASCADE");
            } catch (PDOException $e) {
                error_log('RKJM FK skip (' . $name . '): ' . $e->getMessage());
            }
        }
    }

    function rkjmFields(PDO $pdo): array
    {
        $rows = $pdo->query("SELECT * FROM rkjm_fields ORDER BY urutan ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['id']] = $r;
        }
        return $out;
    }

    function rkjmSeedFields(PDO $pdo): void
    {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM rkjm_fields")->fetchColumn();
        if ($count > 0) {
            return;
        }
        $defaults = rkjmDefaultCatalog();
        $stmt = $pdo->prepare("INSERT INTO rkjm_fields (kode, nama, urutan) VALUES (?,?,?)");
        $i = 0;
        foreach ($defaults as $bidang) {
            $i++;
            $stmt->execute([$bidang['kode'], $bidang['nama'], $i]);
        }
    }

    function rkjmSeedTemplates(PDO $pdo): void
    {
        $flagged = $pdo->prepare("SELECT meta_value FROM rkjm_meta WHERE meta_key = 'templates_seeded' LIMIT 1");
        $flagged->execute();
        if ((string)$flagged->fetchColumn() === '1') {
            return;
        }

        $count = (int)$pdo->query("SELECT COUNT(*) FROM rkjm_template_programs")->fetchColumn();
        if ($count > 0) {
            // Tabel sudah berisi data (mis. hasil impor manual); jangan timpa.
            $pdo->prepare("INSERT INTO rkjm_meta (meta_key, meta_value) VALUES ('templates_seeded','1')
                ON DUPLICATE KEY UPDATE meta_value = '1'")->execute();
            return;
        }
        $fields = rkjmFields($pdo);
        $fieldIdByKode = [];
        foreach ($fields as $f) {
            $fieldIdByKode[$f['kode']] = (int)$f['id'];
        }

        $sql = "INSERT INTO rkjm_template_programs
            (kode, field_id, bidang_nama, nama_program, latar_belakang, permasalahan, tujuan, sasaran,
             indikator, target_akhir, target_tahun1, target_tahun2, target_tahun3, target_tahun4,
             kegiatan_utama, penanggung_jawab, estimasi_anggaran, sumber_dana, dokumen_bukti,
             monitoring_evaluasi, catatan, is_active)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)";
        $stmt = $pdo->prepare($sql);

        $pdo->beginTransaction();
        try {
            foreach (rkjmDefaultCatalog() as $bidang) {
                $fieldId = $fieldIdByKode[$bidang['kode']] ?? null;
                $defaults = $bidang['defaults'];
                foreach ($bidang['programs'] as $p) {
                    $stmt->execute([
                        $p['kode'],
                        $fieldId,
                        $bidang['nama'],
                        $p['nama'],
                        $p['kondisi'] ?? ($defaults['kondisi'] ?? ''),
                        $p['masalah'] ?? ($defaults['masalah'] ?? ''),
                        $p['tujuan'] ?? '',
                        $p['sasaran'] ?? ($defaults['sasaran'] ?? ''),
                        $p['indikator'] ?? '',
                        $p['ta'] ?? '',
                        $p['t1'] ?? '',
                        $p['t2'] ?? '',
                        $p['t3'] ?? '',
                        $p['t4'] ?? '',
                        $p['kegiatan'] ?? ($defaults['kegiatan'] ?? ''),
                        $p['pj'] ?? ($defaults['pj'] ?? 'Kepala Madrasah'),
                        (float)($p['a'] ?? 0),
                        $p['sumber'] ?? ($defaults['sumber'] ?? 'BOS/BOSDA (contoh)'),
                        $p['bukti'] ?? ($defaults['bukti'] ?? ''),
                        $p['monev'] ?? ($defaults['monev'] ?? ''),
                        $p['catatan'] ?? ($defaults['catatan'] ?? 'Nilai contoh, sesuaikan dengan kondisi dan kemampuan madrasah.'),
                    ]);
                }
            }
            $pdo->commit();
            $pdo->prepare("INSERT INTO rkjm_meta (meta_key, meta_value) VALUES ('templates_seeded','1')
                ON DUPLICATE KEY UPDATE meta_value = '1'")->execute();
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('RKJM seed error: ' . $e->getMessage());
        }
    }

    /**
     * Mengisi contoh catatan monitoring/evaluasi untuk setiap program RKJM
     * sebagai data referensi awal (mirip rktSeedMonitoringExamples).
     * Kepala madrasah dapat mengedit atau menghapusnya kapan saja.
     */
    function rkjmSeedMonitoringExamples(PDO $pdo): int
    {
        try {
            $monevByBidang = [
                'Pengembangan Kurikulum dan Pembelajaran' => [
                    'target'              => 'Terlaksana sesuai target tahunan RKJM dan indikator keberhasilan',
                    'realisasi'           => 'Kegiatan pembelajaran dan kurikulum berjalan sesuai peta jalan tahun berjalan.',
                    'penilaian_kualitatif'=> 'Baik',
                    'kendala'             => 'Penyesuaian jadwal guru pembina pada awal semester.',
                    'tindak_lanjut'       => 'Lakukan evaluasi berkala dan koordinasi kurikulum setiap triwulan.',
                    'catatan_evaluasi'    => 'Data referensi awal RKJM. Silakan edit atau sesuaikan dengan realisasi madrasah.',
                ],
                'Pengembangan Kompetensi Pendidik dan Tenaga Kependidikan' => [
                    'target'              => 'Minimal 80% pendidik dan tendik mengikuti pengembangan keprofesian berkelanjutan',
                    'realisasi'           => 'Sebagian guru telah mengikuti workshop/MGMP dan pelatihan mandiri.',
                    'penilaian_kualitatif'=> 'Cukup Baik',
                    'kendala'             => 'Alokasi waktu bersamaan dengan agenda penilaian semester.',
                    'tindak_lanjut'       => 'Penyusunan jadwal diklat bergantian dan pendampingan pasca pelatihan.',
                    'catatan_evaluasi'    => 'Data referensi awal RKJM. Silakan edit atau sesuaikan dengan realisasi madrasah.',
                ],
                'Pengembangan Peserta Didik' => [
                    'target'              => 'Peningkatan prestasi akademik dan non-akademik sesuai target 4 tahunan',
                    'realisasi'           => 'Pembinaan ekstrakurikuler dan bimbingan prestasi terlaksana rutin.',
                    'penilaian_kualitatif'=> 'Baik',
                    'kendala'             => 'Fasilitas latihan beberapa cabang ekskul perlu peremajaan.',
                    'tindak_lanjut'       => 'Optimalkan sarana yang ada dan jalin kemitraan untuk pembinaan intensif.',
                    'catatan_evaluasi'    => 'Data referensi awal RKJM. Silakan edit atau sesuaikan dengan realisasi madrasah.',
                ],
                'Pengembangan Sarana dan Prasarana' => [
                    'target'              => 'Pemenuhan standar sarana prasarana bertahap sesuai skala prioritas',
                    'realisasi'           => 'Pemeliharaan dan perbaikan berkala sarana utama madrasah telah berjalan.',
                    'penilaian_kualitatif'=> 'Cukup',
                    'kendala'             => 'Keterbatasan anggaran untuk renovasi skala besar.',
                    'tindak_lanjut'       => 'Ajukan usulan bantuan sarpras dan optimalkan dana pemeliharaan rutin.',
                    'catatan_evaluasi'    => 'Data referensi awal RKJM. Silakan edit atau sesuaikan dengan realisasi madrasah.',
                ],
                'Pengembangan Tata Kelola dan Manajemen Madrasah' => [
                    'target'              => 'Tata kelola administrasi transparan, akuntabel, dan berbasis digital',
                    'realisasi'           => 'Penerapan sistem administrasi digital dan evaluasi SOP terlaksana berkala.',
                    'penilaian_kualitatif'=> 'Baik',
                    'kendala'             => 'Perlu pembiasaan digitalisasi bagi sebagian tenaga administrasi.',
                    'tindak_lanjut'       => 'Bimtek lanjutan penggunaan sistem dan review SOP operasional.',
                    'catatan_evaluasi'    => 'Data referensi awal RKJM. Silakan edit atau sesuaikan dengan realisasi madrasah.',
                ],
                'Pengembangan Keuangan dan Pembiayaan' => [
                    'target'              => 'Pengelolaan keuangan tertib, efisien, dan transparan sesuai juknis',
                    'realisasi'           => 'Penyusunan RAPBM dan laporan pertanggungjawaban selesai tepat waktu.',
                    'penilaian_kualitatif'=> 'Baik',
                    'kendala'             => 'Pencairan dana tahap tertentu mengalami pergeseran jadwal.',
                    'tindak_lanjut'       => 'Perketat cashflow dan prioritaskan kebutuhan esensial madrasah.',
                    'catatan_evaluasi'    => 'Data referensi awal RKJM. Silakan edit atau sesuaikan dengan realisasi madrasah.',
                ],
                'Pengembangan Budaya dan Lingkungan Madrasah' => [
                    'target'              => 'Terwujudnya lingkungan madrasah yang islami, bersih, sehat, dan ramah anak',
                    'realisasi'           => 'Pembiasaan sholat berjamaah, dhuha, dan jumat bersih berjalan konsisten.',
                    'penilaian_kualitatif'=> 'Baik',
                    'kendala'             => 'Konsistensi kesadaran kebersihan pada sudut tertentu lingkungan.',
                    'tindak_lanjut'       => 'Bentuk satgas madrasah hijau dan berikan penghargaan kelas teladan.',
                    'catatan_evaluasi'    => 'Data referensi awal RKJM. Silakan edit atau sesuaikan dengan realisasi madrasah.',
                ],
                'Pengembangan Hubungan Masyarakat dan Kemitraan' => [
                    'target'              => 'Kemitraan strategis dengan komite, wali murid, dan instansi terkait meningkat',
                    'realisasi'           => 'Pertemuan rutin komite dan pelibatan orang tua terlaksana sesuai kalender.',
                    'penilaian_kualitatif'=> 'Baik',
                    'kendala'             => 'Tingkat kehadiran wali murid pada pertemuan kerja hari dinas variatif.',
                    'tindak_lanjut'       => 'Kombinasikan pertemuan tatap muka dan laporan berkala melalui media digital.',
                    'catatan_evaluasi'    => 'Data referensi awal RKJM. Silakan edit atau sesuaikan dengan realisasi madrasah.',
                ],
                'default' => [
                    'target'              => 'Ketercapaian program RKJM berjalan sesuai target tahun berjalan',
                    'realisasi'           => 'Pelaksanaan kegiatan pada program ini berjalan sesuai rencana kerja.',
                    'penilaian_kualitatif'=> 'Baik',
                    'kendala'             => 'Penyesuaian ritme operasional madrasah.',
                    'tindak_lanjut'       => 'Lakukan evaluasi capaian semesteran dan koordinasi tim pelaksana.',
                    'catatan_evaluasi'    => 'Data referensi awal RKJM. Silakan edit atau sesuaikan dengan realisasi madrasah.',
                ],
            ];

            // Cari program RKJM yang belum memiliki catatan monitoring sama sekali
            $stmtProg = $pdo->query("
                SELECT p.id AS prog_id, p.document_id, p.bidang_nama, p.nama_program, p.penanggung_jawab,
                       d.tahun_awal, d.tahun_akhir
                FROM rkjm_programs p
                JOIN rkjm_documents d ON d.id = p.document_id
                WHERE (SELECT COUNT(*) FROM rkjm_monitoring m WHERE m.program_id = p.id) = 0
            ");
            $programs = $stmtProg->fetchAll(PDO::FETCH_ASSOC);

            if (empty($programs)) {
                return 0;
            }

            $ins = $pdo->prepare("
                INSERT INTO rkjm_monitoring
                    (program_id, tahun_kalender, tanggal_pelaksanaan, target_tahun_berjalan, realisasi,
                     persentase_capaian, penilaian_kualitatif, status_pelaksanaan,
                     kendala, tindak_lanjut, realisasi_biaya, tanggal_evaluasi, petugas_evaluasi, catatan_evaluasi)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");

            $count = 0;
            foreach ($programs as $p) {
                $bidang = $p['bidang_nama'] ?? '';
                $tmpl = $monevByBidang[$bidang] ?? $monevByBidang['default'];
                $tahunAwal = (int)($p['tahun_awal'] ?: date('Y'));
                $prog = trim($p['nama_program'] ?? '');
                $target_txt = "Tahun 1: " . $tmpl['target'];
                $realisasi_txt = "Tahun {$tahunAwal}: " . $tmpl['realisasi'];

                $ins->execute([
                    $p['prog_id'],
                    $tahunAwal,
                    $tahunAwal . '-06-30',
                    $target_txt,
                    $realisasi_txt,
                    75.00,
                    $tmpl['penilaian_kualitatif'],
                    'berjalan',
                    $tmpl['kendala'],
                    $tmpl['tindak_lanjut'],
                    null,
                    $tahunAwal . '-06-30',
                    $p['penanggung_jawab'] ?: 'Kepala Madrasah',
                    $tmpl['catatan_evaluasi'],
                ]);
                $count++;
            }

            return $count;
        } catch (PDOException $e) {
            error_log('rkjmSeedMonitoringExamples error: ' . $e->getMessage());
            return 0;
        }
    }

    function rkjmDefaultCatalog(): array
    {
        static $catalog = null;
        if ($catalog === null) {
            $path = __DIR__ . '/rkjm_templates_default.php';
            $catalog = is_file($path) ? (require $path) : [];
        }
        return $catalog;
    }

    function rkjmDocStatusOptions(): array
    {
        return ['draf' => 'Draf', 'penyusunan' => 'Dalam Penyusunan', 'final' => 'Final'];
    }

    function rkjmProgramStatusOptions(): array
    {
        return [
            'belum_dimulai' => 'Belum Dimulai',
            'berjalan' => 'Berjalan',
            'tercapai' => 'Tercapai',
            'tidak_tercapai' => 'Tidak Tercapai',
            'ditunda' => 'Ditunda',
        ];
    }

    function rkjmProgramStatusBadge(string $status): string
    {
        $map = [
            'belum_dimulai' => 'badge-secondary',
            'berjalan' => 'badge-warning',
            'tercapai' => 'badge-success',
            'tidak_tercapai' => 'badge-danger',
            'ditunda' => 'badge-info',
        ];
        return $map[$status] ?? 'badge-secondary';
    }

    function rkjmRupiah($value, bool $withPrefix = true): string
    {
        $n = (float)$value;
        return ($withPrefix ? 'Rp ' : '') . number_format($n, 0, ',', '.');
    }

    /** Ambil dokumen RKJM berdasarkan id, atau dokumen terbaru. */
    function rkjmGetDocument(PDO $pdo, int $id = 0): ?array
    {
        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT * FROM rkjm_documents WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        }
        $row = $pdo->query("SELECT * FROM rkjm_documents ORDER BY tahun_awal DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Daftar tahun pelaksanaan (1..4) berdasarkan dokumen. */
    function rkjmYears(array $doc): array
    {
        $awal = (int)($doc['tahun_awal'] ?? 0);
        $akhir = (int)($doc['tahun_akhir'] ?? 0);
        if ($awal <= 0 || $akhir < $awal) {
            return [];
        }
        $years = [];
        for ($i = 0; $i < 4; $i++) {
            $tahun = $awal + $i;
            if ($tahun > $akhir) {
                break;
            }
            $years[$i + 1] = $tahun;
        }
        return $years;
    }

    /** Ringkasan dari data aktual. */
    function rkjmSummary(PDO $pdo, int $documentId): array
    {
        $summary = [
            'total' => 0,
            'terlaksana' => 0,
            'berjalan' => 0,
            'belum' => 0,
            'tidak_tercapai' => 0,
            'ditunda' => 0,
            'anggaran' => 0.0,
            'per_bidang' => [],
        ];
        if ($documentId <= 0) {
            return $summary;
        }
        $stmt = $pdo->prepare("SELECT status, COUNT(*) AS c, COALESCE(SUM(estimasi_anggaran),0) AS total_anggaran
            FROM rkjm_programs WHERE document_id = ? GROUP BY status");
        $stmt->execute([$documentId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $c = (int)$r['c'];
            $summary['total'] += $c;
            $summary['anggaran'] += (float)$r['total_anggaran'];
            switch ($r['status']) {
                case 'tercapai':
                    $summary['terlaksana'] += $c;
                    break;
                case 'berjalan':
                    $summary['berjalan'] += $c;
                    break;
                case 'tidak_tercapai':
                    $summary['tidak_tercapai'] += $c;
                    break;
                case 'ditunda':
                    $summary['ditunda'] += $c;
                    break;
                default:
                    $summary['belum'] += $c;
            }
        }
        $stmt = $pdo->prepare("SELECT COALESCE(bidang_nama,'Lainnya') AS bidang, COUNT(*) AS c
            FROM rkjm_programs WHERE document_id = ? GROUP BY bidang_nama ORDER BY bidang_nama ASC");
        $stmt->execute([$documentId]);
        $summary['per_bidang'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $summary;
    }

    /** Target tahunan tersimpan untuk sekumpulan program. */
    function rkjmTargetsByProgram(PDO $pdo, array $programIds): array
    {
        if (!$programIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($programIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM rkjm_program_targets WHERE program_id IN ($in) ORDER BY tahun_ke ASC");
        $stmt->execute(array_map('intval', $programIds));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['program_id']][(int)$r['tahun_ke']] = $r;
        }
        return $out;
    }
}
