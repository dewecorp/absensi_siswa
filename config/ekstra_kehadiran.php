<?php
/**
 * Helper kehadiran ekstrakurikuler (selain Pramuka).
 *
 * Data anggota diambil dari tb_anggota_ekskul, jadwal dari tb_ekstrakurikuler.
 * Kehadiran disimpan di tb_kehadiran_ekstra.
 */

if (!function_exists('ekstraKehadiranEnsureSchema')) {
    function ekstraKehadiranEnsureSchema(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_kehadiran_ekstra (
                id_kehadiran_ekstra INT AUTO_INCREMENT PRIMARY KEY,
                id_ekstrakurikuler INT NOT NULL,
                id_siswa INT NOT NULL,
                tanggal DATE NOT NULL,
                keterangan VARCHAR(20) NOT NULL,
                jam_masuk TIME NULL,
                waktu_input DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_ekskul_siswa_tanggal (id_ekstrakurikuler, id_siswa, tanggal),
                INDEX idx_ekskul_tanggal (id_ekstrakurikuler, tanggal),
                INDEX idx_siswa_tanggal (id_siswa, tanggal)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

if (!function_exists('ekstraKehadiranHariKeN')) {
    function ekstraKehadiranHariKeN(string $hari): int
    {
        $h = strtolower(trim($hari));
        $h = str_replace(["'", "’", ".", " ", "-"], '', $h);
        switch ($h) {
            case 'senin': return 1;
            case 'selasa': return 2;
            case 'rabu': return 3;
            case 'kamis': return 4;
            case 'jumat': return 5;
            case 'sabtu': return 6;
            case 'minggu':
            case 'ahad': return 7;
        }
        return 0;
    }
}

if (!function_exists('ekstraKehadiranList')) {
    /**
     * @return array<int,array{id_ekstrakurikuler:int,nama_ekstrakurikuler:string,hari:string,waktu:string,waktu_selesai:string}>
     */
    function ekstraKehadiranList(PDO $pdo, bool $excludePramuka = true): array
    {
        $sql = "SELECT id_ekstrakurikuler, nama_ekstrakurikuler, hari, waktu, waktu_selesai
            FROM tb_ekstrakurikuler";
        if ($excludePramuka) {
            $sql .= " WHERE LOWER(nama_ekstrakurikuler) NOT LIKE '%pramuka%'";
        }
        $sql .= " ORDER BY nama_ekstrakurikuler ASC";
        try {
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id_ekstrakurikuler' => (int)($r['id_ekstrakurikuler'] ?? 0),
                'nama_ekstrakurikuler' => (string)($r['nama_ekstrakurikuler'] ?? ''),
                'hari' => (string)($r['hari'] ?? ''),
                'waktu' => (string)($r['waktu'] ?? ''),
                'waktu_selesai' => (string)($r['waktu_selesai'] ?? ''),
            ];
        }
        return $out;
    }
}

if (!function_exists('ekstraKehadiranResolve')) {
    function ekstraKehadiranResolve(PDO $pdo, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $st = $pdo->prepare("SELECT id_ekstrakurikuler, nama_ekstrakurikuler, hari, waktu, waktu_selesai
                FROM tb_ekstrakurikuler
                WHERE id_ekstrakurikuler = ?
                LIMIT 1");
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null;
        }
        if (!$r) {
            return null;
        }
        return [
            'id_ekstrakurikuler' => (int)($r['id_ekstrakurikuler'] ?? 0),
            'nama_ekstrakurikuler' => (string)($r['nama_ekstrakurikuler'] ?? ''),
            'hari' => (string)($r['hari'] ?? ''),
            'waktu' => (string)($r['waktu'] ?? ''),
            'waktu_selesai' => (string)($r['waktu_selesai'] ?? ''),
        ];
    }
}

if (!function_exists('ekstraKehadiranIsScheduleDay')) {
    function ekstraKehadiranIsScheduleDay(array $ekskul, string $tanggal): bool
    {
        $ts = strtotime($tanggal);
        if ($ts === false) {
            return false;
        }
        $dayNum = ekstraKehadiranHariKeN((string)($ekskul['hari'] ?? ''));
        if ($dayNum <= 0) {
            return false;
        }
        return (int)date('N', $ts) === $dayNum;
    }
}

if (!function_exists('ekstraKehadiranScheduleDates')) {
    /**
     * Tanggal-tanggal yang jatuh pada hari jadwal ekstra dalam satu bulan.
     *
     * @return int[]
     */
    function ekstraKehadiranScheduleDates(array $ekskul, int $year, int $month): array
    {
        if ($year < 1970 || $month < 1 || $month > 12) {
            return [];
        }
        $dayNum = ekstraKehadiranHariKeN((string)($ekskul['hari'] ?? ''));
        if ($dayNum <= 0) {
            return [];
        }
        $numDays = (int)cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $dates = [];
        for ($d = 1; $d <= $numDays; $d++) {
            $ts = strtotime(sprintf('%04d-%02d-%02d', $year, $month, $d));
            if ($ts !== false && (int)date('N', $ts) === $dayNum) {
                $dates[] = $d;
            }
        }
        return $dates;
    }
}

if (!function_exists('ekstraKehadiranScheduleLabel')) {
    function ekstraKehadiranScheduleLabel(array $ekskul): string
    {
        $label = trim((string)($ekskul['hari'] ?? ''));
        if ($label === '') {
            $label = 'Sesuai jadwal';
        }
        $jam = trim((string)($ekskul['waktu'] ?? ''));
        if ($jam !== '') {
            $jam = substr($jam, 0, 5);
            $selesai = trim((string)($ekskul['waktu_selesai'] ?? ''));
            if ($selesai !== '') {
                $selesai = substr($selesai, 0, 5);
                return $label . ', ' . $jam . '–' . $selesai;
            }
            return $label . ', ' . $jam;
        }
        return $label;
    }
}

if (!function_exists('ekstraKehadiranSiswaAktifSql')) {
    function ekstraKehadiranSiswaAktifSql(string $alias = 's'): string
    {
        $a = preg_replace('/[^A-Za-z0-9_]/', '', $alias);
        if ($a === '') {
            $a = 's';
        }
        return "
            {$a}.id_kelas IS NOT NULL
            AND {$a}.id_kelas > 0
            AND NOT EXISTS (
                SELECT 1 FROM tb_alumni al WHERE al.original_id_siswa = {$a}.id_siswa
            )
        ";
    }
}

if (!function_exists('ekstraKehadiranAnggota')) {
    function ekstraKehadiranAnggota(PDO $pdo, int $ekskulId, string $tanggal = ''): array
    {
        if ($ekskulId <= 0) {
            return [];
        }
        $sql = "
            SELECT s.id_siswa, s.nama_siswa, s.nisn, s.foto, s.jenis_kelamin,
                   k.nama_kelas,
                   h.keterangan, h.jam_masuk
            FROM tb_anggota_ekskul a
            INNER JOIN tb_siswa s ON s.id_siswa = a.id_siswa
            LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas
            LEFT JOIN tb_kehadiran_ekstra h
                   ON h.id_siswa = s.id_siswa
                  AND h.id_ekstrakurikuler = a.id_ekstrakurikuler
                  AND h.tanggal = ?
            WHERE a.id_ekstrakurikuler = ?
              AND a.status = 'aktif'
              AND " . ekstraKehadiranSiswaAktifSql('s') . "
            ORDER BY s.nama_siswa ASC
        ";
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$tanggal !== '' ? $tanggal : null, $ekskulId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('ekstraKehadiranSaveSingle')) {
    /**
     * @return array{success:bool,error?:string,status?:string}
     */
    function ekstraKehadiranSaveSingle(PDO $pdo, int $ekskulId, int $idSiswa, string $tanggal, string $status): array
    {
        $allowed = ['', 'Hadir', 'Sakit', 'Izin', 'Alpa', 'Terlambat'];
        if (!in_array($status, $allowed, true)) {
            return ['success' => false, 'error' => 'Status kehadiran tidak valid.'];
        }
        $ekskul = ekstraKehadiranResolve($pdo, $ekskulId);
        if (!$ekskul) {
            return ['success' => false, 'error' => 'Ekstrakurikuler tidak ditemukan.'];
        }
        if (!ekstraKehadiranIsScheduleDay($ekskul, $tanggal)) {
            return ['success' => false, 'error' => 'Kehadiran hanya dibuka sesuai jadwal ' . $ekskul['nama_ekstrakurikuler'] . ' (' . ekstraKehadiranScheduleLabel($ekskul) . ').'];
        }

        try {
            $st = $pdo->prepare("
                SELECT 1
                FROM tb_anggota_ekskul a
                INNER JOIN tb_siswa s ON s.id_siswa = a.id_siswa
                WHERE a.id_ekstrakurikuler = ?
                  AND a.id_siswa = ?
                  AND a.status = 'aktif'
                  AND " . ekstraKehadiranSiswaAktifSql('s') . "
                LIMIT 1
            ");
            $st->execute([$ekskulId, $idSiswa]);
            if (!$st->fetchColumn()) {
                return ['success' => false, 'error' => 'Siswa bukan anggota aktif ekstrakurikuler ini.'];
            }

            if ($status === '') {
                $st = $pdo->prepare("DELETE FROM tb_kehadiran_ekstra WHERE id_ekstrakurikuler = ? AND id_siswa = ? AND tanggal = ?");
                $st->execute([$ekskulId, $idSiswa, $tanggal]);
                return ['success' => true, 'status' => ''];
            }

            $jam = in_array($status, ['Hadir', 'Terlambat'], true) ? date('H:i:s') : null;
            $st = $pdo->prepare("
                INSERT INTO tb_kehadiran_ekstra (id_ekstrakurikuler, id_siswa, tanggal, keterangan, jam_masuk, waktu_input)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    keterangan = VALUES(keterangan),
                    jam_masuk = VALUES(jam_masuk),
                    updated_at = NOW()
            ");
            $st->execute([$ekskulId, $idSiswa, $tanggal, $status, $jam]);
            return ['success' => true, 'status' => $status];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Gagal menyimpan kehadiran: ' . $e->getMessage()];
        }
    }
}
