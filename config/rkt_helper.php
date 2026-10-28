<?php
/**
 * RKT (Rencana Kerja Tahunan) helper.
 * Skema, seed template, dan utilitas. Terintegrasi dengan RKJM yang sudah ada.
 * Tidak mengubah tabel/data RKJM.
 */

require_once __DIR__ . '/rkjm_helper.php';

if (!function_exists('rktEnsureSchema')) {

    function rktEnsureSchema(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        if (function_exists('rkjmEnsureSchema')) {
            rkjmEnsureSchema($pdo);
        }

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS rkt_documents (
                id INT PRIMARY KEY AUTO_INCREMENT,
                nama_madrasah VARCHAR(255) NULL,
                nomor_dokumen VARCHAR(100) NULL,
                tahun YEAR NOT NULL,
                rkjm_document_id INT NULL,
                visi TEXT NULL,
                misi TEXT NULL,
                tujuan_pengembangan TEXT NULL,
                prioritas TEXT NULL,
                dasar_penyusunan TEXT NULL,
                tanggal_penyusunan DATE NULL,
                nama_kepala VARCHAR(255) NULL,
                status ENUM('draf','penyusunan','menunggu','disahkan','diarsipkan') NOT NULL DEFAULT 'draf',
                catatan TEXT NULL,
                created_by VARCHAR(100) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_rkt_doc_tahun_madrasah (tahun, nama_madrasah),
                INDEX idx_rkt_doc_tahun (tahun),
                INDEX idx_rkt_doc_status (status),
                INDEX idx_rkt_doc_rkjm (rkjm_document_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS rkt_template_programs (
                id INT PRIMARY KEY AUTO_INCREMENT,
                kode VARCHAR(30) NOT NULL,
                field_id INT NULL,
                bidang_nama VARCHAR(255) NULL,
                nama_program VARCHAR(255) NOT NULL,
                latar_belakang TEXT NULL,
                tujuan TEXT NULL,
                sasaran TEXT NULL,
                indikator TEXT NULL,
                target TEXT NULL,
                penanggung_jawab VARCHAR(255) NULL,
                estimasi_anggaran DECIMAL(15,2) DEFAULT 0,
                sumber_dana VARCHAR(255) NULL,
                output TEXT NULL,
                monitoring_evaluasi TEXT NULL,
                catatan TEXT NULL,
                bulan_mulai TINYINT NULL,
                bulan_selesai TINYINT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_rkt_tpl_kode (kode),
                INDEX idx_rkt_tpl_field (field_id),
                INDEX idx_rkt_tpl_active (is_active),
                INDEX idx_rkt_tpl_nama (nama_program(120))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS rkt_template_activities (
                id INT PRIMARY KEY AUTO_INCREMENT,
                template_program_id INT NOT NULL,
                nama_kegiatan VARCHAR(255) NOT NULL,
                tujuan TEXT NULL,
                indikator TEXT NULL,
                target_volume VARCHAR(100) NULL,
                satuan VARCHAR(50) NULL,
                bulan_mulai TINYINT NULL,
                bulan_selesai TINYINT NULL,
                penanggung_jawab VARCHAR(255) NULL,
                estimasi_biaya DECIMAL(15,2) DEFAULT 0,
                sumber_dana VARCHAR(255) NULL,
                output TEXT NULL,
                urutan INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_rkt_ta_tpl (template_program_id),
                INDEX idx_rkt_ta_urutan (urutan)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS rkt_programs (
                id INT PRIMARY KEY AUTO_INCREMENT,
                document_id INT NOT NULL,
                rkjm_program_id INT NULL,
                rkjm_document_id INT NULL,
                rkjm_referensi VARCHAR(255) NULL,
                template_id INT NULL,
                field_id INT NULL,
                bidang_nama VARCHAR(255) NULL,
                kode_program VARCHAR(30) NULL,
                nama_program VARCHAR(255) NOT NULL,
                kondisi_awal TEXT NULL,
                tujuan TEXT NULL,
                sasaran TEXT NULL,
                indikator TEXT NULL,
                target_tahunan TEXT NULL,
                jadwal_mulai DATE NULL,
                jadwal_selesai DATE NULL,
                penanggung_jawab VARCHAR(255) NULL,
                estimasi_anggaran DECIMAL(15,2) DEFAULT 0,
                sumber_dana VARCHAR(255) NULL,
                output TEXT NULL,
                monitoring_evaluasi TEXT NULL,
                catatan TEXT NULL,
                status ENUM('belum_dimulai','berjalan','selesai','tertunda','tidak_terlaksana') DEFAULT 'belum_dimulai',
                urutan INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_rkt_prog_doc (document_id),
                INDEX idx_rkt_prog_rkjm (rkjm_program_id),
                INDEX idx_rkt_prog_field (field_id),
                INDEX idx_rkt_prog_status (status),
                INDEX idx_rkt_prog_pj (penanggung_jawab(120)),
                INDEX idx_rkt_prog_nama (nama_program(120))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS rkt_activities (
                id INT PRIMARY KEY AUTO_INCREMENT,
                program_id INT NOT NULL,
                template_activity_id INT NULL,
                nama_kegiatan VARCHAR(255) NOT NULL,
                tujuan TEXT NULL,
                indikator TEXT NULL,
                target_volume VARCHAR(100) NULL,
                satuan VARCHAR(50) NULL,
                jadwal_mulai DATE NULL,
                jadwal_selesai DATE NULL,
                penanggung_jawab VARCHAR(255) NULL,
                estimasi_biaya DECIMAL(15,2) DEFAULT 0,
                sumber_dana VARCHAR(255) NULL,
                output TEXT NULL,
                status ENUM('belum_dimulai','berjalan','selesai','tertunda','tidak_terlaksana') DEFAULT 'belum_dimulai',
                urutan INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_rkt_act_prog (program_id),
                INDEX idx_rkt_act_status (status),
                INDEX idx_rkt_act_nama (nama_kegiatan(120))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS rkt_monitoring (
                id INT PRIMARY KEY AUTO_INCREMENT,
                program_id INT NOT NULL,
                activity_id INT NULL,
                tanggal_pelaksanaan DATE NULL,
                target TEXT NULL,
                realisasi TEXT NULL,
                persentase_capaian DECIMAL(5,2) NULL,
                penilaian_kualitatif VARCHAR(50) NULL,
                status_pelaksanaan ENUM('belum_dimulai','berjalan','selesai','tertunda','tidak_terlaksana') DEFAULT 'belum_dimulai',
                kendala TEXT NULL,
                tindak_lanjut TEXT NULL,
                output_aktual TEXT NULL,
                bukti TEXT NULL,
                catatan_evaluasi TEXT NULL,
                petugas_evaluasi VARCHAR(255) NULL,
                realisasi_biaya DECIMAL(15,2) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_rkt_mon_prog (program_id),
                INDEX idx_rkt_mon_act (activity_id),
                INDEX idx_rkt_mon_status (status_pelaksanaan)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $e) {
            error_log('RKT schema error: ' . $e->getMessage());
            return;
        }

        rktSeedTemplates($pdo);
        rktEnsureForeignKeys($pdo);
    }

    /** Tambahkan foreign key bila belum ada (aman untuk database berjalan). */
    function rktEnsureForeignKeys(PDO $pdo): void
    {
        $fks = [
            ['rkt_documents', 'fk_rkt_doc_rkjm', 'rkjm_document_id', 'rkjm_documents', 'id', 'SET NULL'],
            ['rkt_template_programs', 'fk_rkt_tpl_field', 'field_id', 'rkjm_fields', 'id', 'SET NULL'],
            ['rkt_template_activities', 'fk_rkt_ta_tpl', 'template_program_id', 'rkt_template_programs', 'id', 'CASCADE'],
            ['rkt_programs', 'fk_rkt_prog_doc', 'document_id', 'rkt_documents', 'id', 'CASCADE'],
            ['rkt_programs', 'fk_rkt_prog_rkjm', 'rkjm_program_id', 'rkjm_programs', 'id', 'SET NULL'],
            ['rkt_programs', 'fk_rkt_prog_rkjm_doc', 'rkjm_document_id', 'rkjm_documents', 'id', 'SET NULL'],
            ['rkt_programs', 'fk_rkt_prog_field', 'field_id', 'rkjm_fields', 'id', 'SET NULL'],
            ['rkt_programs', 'fk_rkt_prog_tpl', 'template_id', 'rkt_template_programs', 'id', 'SET NULL'],
            ['rkt_activities', 'fk_rkt_act_prog', 'program_id', 'rkt_programs', 'id', 'CASCADE'],
            ['rkt_activities', 'fk_rkt_act_ta', 'template_activity_id', 'rkt_template_activities', 'id', 'SET NULL'],
            ['rkt_monitoring', 'fk_rkt_mon_prog', 'program_id', 'rkt_programs', 'id', 'CASCADE'],
            ['rkt_monitoring', 'fk_rkt_mon_act', 'activity_id', 'rkt_activities', 'id', 'CASCADE'],
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
                error_log('RKT FK skip (' . $name . '): ' . $e->getMessage());
            }
        }
    }

    function rktSeedTemplates(PDO $pdo): void
    {
        $flagKey = 'rkt_templates_seeded';
        try {
            $flagged = $pdo->prepare("SELECT meta_value FROM rkjm_meta WHERE meta_key = ? LIMIT 1");
            $flagged->execute([$flagKey]);
            if ((string)$flagged->fetchColumn() === '1') {
                return;
            }

            $count = (int)$pdo->query("SELECT COUNT(*) FROM rkt_template_programs")->fetchColumn();
            if ($count > 0) {
                $pdo->prepare("INSERT INTO rkjm_meta (meta_key, meta_value) VALUES (?, '1')
                    ON DUPLICATE KEY UPDATE meta_value = '1'")->execute([$flagKey]);
                return;
            }
        } catch (PDOException $e) {
            error_log('RKT seed flag error: ' . $e->getMessage());
            return;
        }

        $fields = [];
        foreach ($pdo->query("SELECT id, kode FROM rkjm_fields")->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $fields[$f['kode']] = (int)$f['id'];
        }

        $insP = $pdo->prepare("INSERT INTO rkt_template_programs
            (kode, field_id, bidang_nama, nama_program, latar_belakang, tujuan, sasaran, indikator, target,
             penanggung_jawab, estimasi_anggaran, sumber_dana, output, monitoring_evaluasi, catatan,
             bulan_mulai, bulan_selesai, is_active)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)");
        $insA = $pdo->prepare("INSERT INTO rkt_template_activities
            (template_program_id, nama_kegiatan, tujuan, indikator, target_volume, satuan,
             bulan_mulai, bulan_selesai, penanggung_jawab, estimasi_biaya, sumber_dana, output, urutan)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");

        $pdo->beginTransaction();
        try {
            foreach (rktDefaultCatalog() as $bidang) {
                $fieldId = $fields[$bidang['kode']] ?? null;
                $def = $bidang['defaults'] ?? [];
                foreach ($bidang['programs'] as $p) {
                    $insP->execute([
                        $p['kode'],
                        $fieldId,
                        $bidang['nama'],
                        $p['nama'],
                        $p['latar'] ?? '',
                        $p['tujuan'] ?? '',
                        $p['sasaran'] ?? '',
                        $p['indikator'] ?? '',
                        $p['target'] ?? '',
                        $p['pj'] ?? ($def['pj'] ?? 'Kepala Madrasah'),
                        (float)($p['anggaran'] ?? 0),
                        $p['sumber'] ?? ($def['sumber'] ?? 'BOS/BOSDA (contoh)'),
                        $p['output'] ?? '',
                        $p['monev'] ?? ($def['monev'] ?? ''),
                        $p['catatan'] ?? ($def['catatan'] ?? 'Nilai contoh, sesuaikan dengan kondisi dan kemampuan madrasah.'),
                        (int)($p['mulai'] ?? 7),
                        (int)($p['selesai'] ?? 12),
                    ]);
                    $pid = (int)$pdo->lastInsertId();
                    $i = 0;
                    foreach (($p['kegiatan'] ?? []) as $a) {
                        $i++;
                        $insA->execute([
                            $pid,
                            $a['nama'],
                            $a['tujuan'] ?? '',
                            $a['indikator'] ?? '',
                            $a['target'] ?? '',
                            $a['satuan'] ?? 'kegiatan',
                            (int)($a['mulai'] ?? ($p['mulai'] ?? 7)),
                            (int)($a['selesai'] ?? ($p['selesai'] ?? 12)),
                            $a['pj'] ?? ($p['pj'] ?? ($def['pj'] ?? 'Kepala Madrasah')),
                            (float)($a['biaya'] ?? 0),
                            $a['sumber'] ?? ($p['sumber'] ?? ($def['sumber'] ?? 'BOS/BOSDA (contoh)')),
                            $a['output'] ?? '',
                            $i,
                        ]);
                    }
                }
            }
            $pdo->commit();
            $pdo->prepare("INSERT INTO rkjm_meta (meta_key, meta_value) VALUES (?, '1')
                ON DUPLICATE KEY UPDATE meta_value = '1'")->execute([$flagKey]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('RKT seed error: ' . $e->getMessage());
        }
    }

    function rktDefaultCatalog(): array
    {
        static $catalog = null;
        if ($catalog === null) {
            $path = __DIR__ . '/rkt_templates_default.php';
            $catalog = is_file($path) ? (require $path) : [];
        }
        return $catalog;
    }

    function rktDocStatusOptions(): array
    {
        return [
            'draf' => 'Draf',
            'penyusunan' => 'Dalam Penyusunan',
            'menunggu' => 'Menunggu Persetujuan',
            'disahkan' => 'Disahkan',
            'diarsipkan' => 'Diarsipkan',
        ];
    }

    function rktProgramStatusOptions(): array
    {
        return [
            'belum_dimulai' => 'Belum Dimulai',
            'berjalan' => 'Berjalan',
            'selesai' => 'Selesai',
            'tertunda' => 'Tertunda',
            'tidak_terlaksana' => 'Tidak Terlaksana',
        ];
    }

    function rktProgramStatusBadge(string $status): string
    {
        $map = [
            'belum_dimulai' => 'badge-secondary',
            'berjalan' => 'badge-warning',
            'selesai' => 'badge-success',
            'tertunda' => 'badge-info',
            'tidak_terlaksana' => 'badge-danger',
        ];
        return $map[$status] ?? 'badge-secondary';
    }

    function rktRupiah($value, bool $withPrefix = true): string
    {
        $n = (float)$value;
        return ($withPrefix ? 'Rp ' : '') . number_format($n, 0, ',', '.');
    }

    function rktTanggal(?string $date): string
    {
        if (!$date) {
            return '-';
        }
        $ts = strtotime($date);
        return $ts ? date('d/m/Y', $ts) : '-';
    }

    /**
     * Sinkronkan rentang jadwal program RKT dari jadwal kegiatan-kegiatan di dalamnya
     * (MIN(jadwal_mulai) s.d. MAX(jadwal_selesai)).
     */
    function rktSyncProgramSchedule(PDO $pdo, int $programId): array
    {
        if ($programId <= 0) {
            return ['jadwal_mulai' => null, 'jadwal_selesai' => null];
        }

        $stmt = $pdo->prepare("SELECT 
                MIN(COALESCE(jadwal_mulai, jadwal_selesai)) AS min_mulai, 
                MAX(COALESCE(jadwal_selesai, jadwal_mulai)) AS max_selesai,
                COUNT(*) AS total_activities
            FROM rkt_activities 
            WHERE program_id = ?");
        $stmt->execute([$programId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $totalAct = (int)($row['total_activities'] ?? 0);
        $minMulai = !empty($row['min_mulai']) ? $row['min_mulai'] : null;
        $maxSelesai = !empty($row['max_selesai']) ? $row['max_selesai'] : null;

        if ($totalAct > 0) {
            $upd = $pdo->prepare("UPDATE rkt_programs SET jadwal_mulai = ?, jadwal_selesai = ? WHERE id = ?");
            $upd->execute([$minMulai, $maxSelesai, $programId]);
        }

        return ['jadwal_mulai' => $minMulai, 'jadwal_selesai' => $maxSelesai];
    }

    /**
     * Sinkronkan seluruh jadwal program RKT dalam suatu dokumen berdasarkan kegiatan aktual.
     */
    function rktSyncAllProgramsSchedule(PDO $pdo, int $documentId = 0): int
    {
        $sql = "SELECT DISTINCT program_id FROM rkt_activities";
        $params = [];
        if ($documentId > 0) {
            $sql = "SELECT DISTINCT a.program_id 
                    FROM rkt_activities a 
                    INNER JOIN rkt_programs p ON p.id = a.program_id 
                    WHERE p.document_id = ?";
            $params = [$documentId];
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $progIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $synced = 0;
        foreach ($progIds as $pid) {
            rktSyncProgramSchedule($pdo, (int)$pid);
            $synced++;
        }
        return $synced;
    }

    /** Dokumen RKT berdasarkan id, tahun, atau terbaru. */
    function rktGetDocument(PDO $pdo, int $id = 0, int $tahun = 0): ?array
    {
        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT * FROM rkt_documents WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        }
        if ($tahun > 0) {
            $stmt = $pdo->prepare("SELECT * FROM rkt_documents WHERE tahun = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$tahun]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        }
        $row = $pdo->query("SELECT * FROM rkt_documents ORDER BY tahun DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    function rktDocuments(PDO $pdo): array
    {
        return $pdo->query("SELECT id, tahun, status, nama_madrasah FROM rkt_documents ORDER BY tahun DESC, id DESC")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Cari dokumen RKJM yang mencakup tahun tertentu. */
    function rktFindRkjmDocForYear(PDO $pdo, int $tahun): ?array
    {
        if ($tahun <= 0) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT * FROM rkjm_documents WHERE tahun_awal <= ? AND tahun_akhir >= ?
            ORDER BY tahun_awal DESC, id DESC LIMIT 1");
        $stmt->execute([$tahun, $tahun]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Target tahunan program RKJM pada tahun kalender tertentu. */
    function rktRkjmTarget(PDO $pdo, int $rkjmProgramId, int $tahun): ?string
    {
        if ($rkjmProgramId <= 0 || $tahun <= 0) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT target FROM rkjm_program_targets
            WHERE program_id = ? AND tahun_kalender = ? ORDER BY tahun_ke ASC LIMIT 1");
        $stmt->execute([$rkjmProgramId, $tahun]);
        $val = $stmt->fetchColumn();
        return $val === false ? null : (string)$val;
    }

    /** Hitung alokasi estimasi anggaran tahunan dari program RKJM berdasarkan target aktif per tahun. */
    function rktCalcAnnualBudget(PDO $pdo, int $rkjmProgramId, float $totalAnggaran): float
    {
        if ($totalAnggaran <= 0 || $rkjmProgramId <= 0) {
            return 0.0;
        }
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM rkjm_program_targets WHERE program_id = " . (int)$rkjmProgramId . " AND COALESCE(target,'') <> ''")->fetchColumn();
        if ($cnt <= 0) {
            $cnt = 1;
        }
        return round($totalAnggaran / $cnt, 2);
    }

    /** Ringkasan dari data aktual. */
    function rktSummary(PDO $pdo, int $documentId): array
    {
        $summary = [
            'program' => 0,
            'kegiatan' => 0,
            'belum' => 0,
            'berjalan' => 0,
            'selesai' => 0,
            'tertunda' => 0,
            'tidak_terlaksana' => 0,
            'anggaran' => 0.0,
            'anggaran_kegiatan' => 0.0,
            'realisasi' => 0.0,
            'per_bidang' => [],
        ];
        if ($documentId <= 0) {
            return $summary;
        }

        $stmt = $pdo->prepare("SELECT status, COUNT(*) AS c, COALESCE(SUM(estimasi_anggaran),0) AS total
            FROM rkt_programs WHERE document_id = ? GROUP BY status");
        $stmt->execute([$documentId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $c = (int)$r['c'];
            $summary['program'] += $c;
            $summary['anggaran'] += (float)$r['total'];
            switch ($r['status']) {
                case 'berjalan':
                    $summary['berjalan'] += $c;
                    break;
                case 'selesai':
                    $summary['selesai'] += $c;
                    break;
                case 'tertunda':
                    $summary['tertunda'] += $c;
                    break;
                case 'tidak_terlaksana':
                    $summary['tidak_terlaksana'] += $c;
                    break;
                default:
                    $summary['belum'] += $c;
            }
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(a.estimasi_biaya),0) AS total
            FROM rkt_activities a INNER JOIN rkt_programs p ON p.id = a.program_id
            WHERE p.document_id = ?");
        $stmt->execute([$documentId]);
        $act = $stmt->fetch(PDO::FETCH_ASSOC);
        $summary['kegiatan'] = (int)($act['c'] ?? 0);
        $summary['anggaran_kegiatan'] = (float)($act['total'] ?? 0);

        // Realisasi anggaran: catatan monitoring terbaru per program (level program).
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(m.realisasi_biaya),0)
            FROM rkt_monitoring m
            INNER JOIN rkt_programs p ON p.id = m.program_id
            INNER JOIN (SELECT program_id, MAX(id) AS mid FROM rkt_monitoring
                        WHERE activity_id IS NULL GROUP BY program_id) lastm ON lastm.mid = m.id
            WHERE p.document_id = ?");
        $stmt->execute([$documentId]);
        $summary['realisasi'] = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(bidang_nama,'Lainnya') AS bidang, COUNT(*) AS c
            FROM rkt_programs WHERE document_id = ? GROUP BY bidang_nama ORDER BY bidang_nama ASC");
        $stmt->execute([$documentId]);
        $summary['per_bidang'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $summary;
    }

    /** Kegiatan per program (map program_id => rows). */
    function rktActivitiesByProgram(PDO $pdo, array $programIds): array
    {
        if (!$programIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($programIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM rkt_activities WHERE program_id IN ($in) ORDER BY urutan ASC, id ASC");
        $stmt->execute(array_map('intval', $programIds));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['program_id']][] = $r;
        }
        return $out;
    }

    /** Hitung tanggal dari bulan contoh template pada tahun RKT. */
    function rktMonthStart(int $tahun, int $bulan): string
    {
        $bulan = max(1, min(12, $bulan));
        return sprintf('%04d-%02d-01', $tahun, $bulan);
    }

    function rktMonthEnd(int $tahun, int $bulan): string
    {
        $bulan = max(1, min(12, $bulan));
        $ts = strtotime(sprintf('%04d-%02d-01', $tahun, $bulan));
        return date('Y-m-t', $ts ?: time());
    }

    /** Apakah target RKT berbeda dari target tahunan RKJM. */
    function rktTargetMismatch(?string $rktTarget, ?string $rkjmTarget): bool
    {
        $a = trim((string)$rktTarget);
        $b = trim((string)$rkjmTarget);
        if ($a === '' || $b === '') {
            return false;
        }
        return mb_strtolower(preg_replace('/\s+/', ' ', $a)) !== mb_strtolower(preg_replace('/\s+/', ' ', $b));
    }
}
