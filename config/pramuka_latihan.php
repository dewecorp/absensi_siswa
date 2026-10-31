<?php
/**
 * Helper kehadiran latihan Pramuka.
 *
 * Dipakai oleh:
 * - admin/absensi_latihan_pramuka.php
 * - admin/rekap_absensi_latihan_pramuka.php
 *
 * Cakupan tingkatan mengikuti pembina yang login (tb_pembina_tingkat).
 * Admin, tata usaha, dan kepala madrasah melihat semua tingkatan.
 */

if (!function_exists('pramukaLatihanEnsureSchema')) {
    function pramukaLatihanEnsureSchema(PDO $pdo): void
    {
        // Tingkat
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_tingkat_barung (
                id_tingkat_barung INT AUTO_INCREMENT PRIMARY KEY,
                nama_tingkat VARCHAR(100) NOT NULL,
                golongan VARCHAR(50) NOT NULL DEFAULT 'Siaga'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Peserta didik Pramuka
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_peserta_didik_barung (
                id_peserta_didik_barung INT AUTO_INCREMENT PRIMARY KEY,
                id_tingkat_barung INT NOT NULL,
                id_siswa INT NULL,
                nama_peserta_didik VARCHAR(120) NOT NULL,
                nta VARCHAR(50) NOT NULL,
                tempat_lahir VARCHAR(120) NULL,
                tanggal_lahir DATE NULL,
                status ENUM('aktif','keluar') NOT NULL DEFAULT 'aktif',
                tanggal_masuk DATETIME NULL,
                tanggal_keluar DATETIME NULL,
                INDEX idx_tingkat (id_tingkat_barung)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Pembina -> tingkat (junction)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_pembina_tingkat (
                id_pembina_pramuka INT NOT NULL,
                id_tingkat_barung INT NOT NULL,
                PRIMARY KEY (id_pembina_pramuka, id_tingkat_barung)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Absensi latihan
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_absensi_latihan_pramuka (
                id_absensi_latihan INT AUTO_INCREMENT PRIMARY KEY,
                id_peserta_didik_barung INT NOT NULL,
                id_tingkat_barung INT NULL,
                tanggal DATE NOT NULL,
                keterangan VARCHAR(20) NOT NULL,
                id_guru INT NULL,
                jam_masuk TIME NULL,
                waktu_input DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_peserta_tanggal (id_peserta_didik_barung, tanggal),
                INDEX idx_tingkat_tanggal (id_tingkat_barung, tanggal)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

if (!function_exists('pramukaLatihanPesertaAktifSql')) {
    /**
     * Syarat peserta latihan yang benar-benar siswa aktif.
     *
     * Meniru filter daftar anggota di admin/data_barung.php:
     * - status peserta pramuka harus aktif;
     * - harus terhubung ke tb_siswa yang masih punya kelas (bukan alumni);
     * - siswa yang sudah masuk tb_alumni selalu ditolak.
     *
     * @param string $alias Alias tabel tb_peserta_didik_barung (huruf/angka/underscore).
     */
    function pramukaLatihanPesertaAktifSql(string $alias = 'p'): string
    {
        $a = preg_replace('/[^A-Za-z0-9_]/', '', $alias);
        if ($a === '') {
            $a = 'p';
        }

        return "
            IFNULL({$a}.status, 'aktif') = 'aktif'
            AND EXISTS (
                SELECT 1
                FROM tb_siswa s
                WHERE (
                    ({$a}.id_siswa IS NOT NULL AND s.id_siswa = {$a}.id_siswa)
                    OR (
                        {$a}.id_siswa IS NULL
                        AND TRIM(IFNULL({$a}.nta, '')) <> ''
                        AND NULLIF(TRIM(IFNULL(s.nisn, '')), '') IS NOT NULL
                        AND CONVERT(TRIM(IFNULL(s.nisn, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                            = CONVERT(TRIM(IFNULL({$a}.nta, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                    )
                )
                AND s.id_kelas IS NOT NULL
                AND s.id_kelas > 0
                AND NOT EXISTS (
                    SELECT 1 FROM tb_alumni al WHERE al.original_id_siswa = s.id_siswa
                )
            )
        ";
    }
}

if (!function_exists('pramukaLatihanHariKeN')) {
    function pramukaLatihanHariKeN(string $hari): int
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

if (!function_exists('pramukaLatihanSchedule')) {
    /**
     * Jadwal latihan Pramuka dari tb_ekstrakurikuler (baris Pramuka).
     *
     * @return array{id:int,nama:string,hari:string,day_num:int,waktu:string,waktu_selesai:string}
     */
    function pramukaLatihanSchedule(PDO $pdo): array
    {
        $fallback = [
            'id' => 0,
            'nama' => 'Pramuka',
            'hari' => 'Ahad',
            'day_num' => 7,
            'waktu' => '',
            'waktu_selesai' => '',
        ];
        try {
            $row = $pdo->query("SELECT id_ekstrakurikuler, nama_ekstrakurikuler, hari, waktu, waktu_selesai
                FROM tb_ekstrakurikuler
                WHERE LOWER(nama_ekstrakurikuler) LIKE '%pramuka%'
                ORDER BY id_ekstrakurikuler ASC
                LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return $fallback;
            }
            $dayNum = pramukaLatihanHariKeN((string)($row['hari'] ?? ''));
            return [
                'id' => (int)($row['id_ekstrakurikuler'] ?? 0),
                'nama' => (string)($row['nama_ekstrakurikuler'] ?? 'Pramuka'),
                'hari' => (string)($row['hari'] ?? ''),
                'day_num' => $dayNum > 0 ? $dayNum : 7,
                'waktu' => (string)($row['waktu'] ?? ''),
                'waktu_selesai' => (string)($row['waktu_selesai'] ?? ''),
            ];
        } catch (Throwable $e) {
            return $fallback;
        }
    }
}

if (!function_exists('pramukaLatihanIsScheduleDay')) {
    function pramukaLatihanIsScheduleDay(PDO $pdo, string $tanggal): bool
    {
        $ts = strtotime($tanggal);
        if ($ts === false) {
            return false;
        }
        $schedule = pramukaLatihanSchedule($pdo);
        return (int)date('N', $ts) === (int)$schedule['day_num'];
    }
}

if (!function_exists('pramukaLatihanScheduleDates')) {
    /**
     * Tanggal-tanggal yang jatuh pada hari latihan Pramuka dalam satu bulan.
     *
     * @return int[]
     */
    function pramukaLatihanScheduleDates(PDO $pdo, int $year, int $month): array
    {
        if ($year < 1970 || $month < 1 || $month > 12) {
            return [];
        }
        $schedule = pramukaLatihanSchedule($pdo);
        $dayNum = (int)($schedule['day_num'] ?? 0);
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

if (!function_exists('pramukaLatihanScheduleLabel')) {
    function pramukaLatihanScheduleLabel(PDO $pdo): string
    {
        $schedule = pramukaLatihanSchedule($pdo);
        $label = trim((string)$schedule['hari']);
        if ($label === '') {
            $label = 'Ahad';
        }
        $jam = trim((string)$schedule['waktu']);
        if ($jam !== '') {
            $jam = substr($jam, 0, 5);
            $selesai = trim((string)$schedule['waktu_selesai']);
            if ($selesai !== '') {
                $selesai = substr($selesai, 0, 5);
                return $label . ', ' . $jam . '–' . $selesai;
            }
            return $label . ', ' . $jam;
        }
        return $label;
    }
}

if (!function_exists('pramukaLatihanManualInputIsLate')) {
    /**
     * True bila input kehadiran dilakukan setelah jam latihan pada tanggal tersebut lewat.
     */
    function pramukaLatihanManualInputIsLate(PDO $pdo, string $tanggal): bool
    {
        $ts = strtotime($tanggal);
        if ($ts === false) {
            return false;
        }
        $schedule = pramukaLatihanSchedule($pdo);
        $selesai = trim((string)($schedule['waktu_selesai'] ?? ''));
        if ($selesai === '') {
            $selesai = trim((string)($schedule['waktu'] ?? ''));
        }
        if ($selesai === '') {
            return $ts < strtotime(date('Y-m-d'));
        }
        $endTs = strtotime(date('Y-m-d', $ts) . ' ' . substr($selesai, 0, 5) . ':00');
        return $endTs !== false && time() > $endTs;
    }
}

if (!function_exists('pramukaLatihanNotifManualLate')) {
    /**
     * Notifikasi khusus input manual kehadiran Pramuka yang sudah lewat jadwal.
     * Dibuat berbeda dari notifikasi kehadiran harian agar tidak tertukar.
     */
    function pramukaLatihanNotifManualLate(PDO $pdo, int $idPeserta, string $tanggal): void
    {
        $idPeserta = (int)$idPeserta;
        $tanggal = trim($tanggal);
        if ($idPeserta <= 0 || $tanggal === '') {
            return;
        }

        $tingkat = '';
        try {
            $st = $pdo->prepare("
                SELECT t.nama_tingkat
                FROM tb_peserta_didik_barung p
                LEFT JOIN tb_tingkat_barung t ON t.id_tingkat_barung = p.id_tingkat_barung
                WHERE p.id_peserta_didik_barung = ?
                LIMIT 1
            ");
            $st->execute([$idPeserta]);
            $tingkat = trim((string)($st->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            $tingkat = '';
        }
        if ($tingkat === '') {
            $tingkat = 'Pramuka';
        }

        $aktor = function_exists('simadAktorKehadiranLabel')
            ? simadAktorKehadiranLabel($pdo, '')
            : trim((string)($_SESSION['nama_guru'] ?? ($_SESSION['username'] ?? 'Pengguna')));

        $tanggal_txt = date('d-m-Y', strtotime($tanggal) ?: time());
        $jam = date('H:i');
        $message = "$aktor mengisi kehadiran Latihan Pramuka tingkat $tingkat untuk $tanggal_txt secara manual karena jadwal latihan sudah lewat (diinput pukul $jam WIB).";

        // Cegah notifikasi berulang saat input banyak peserta dalam waktu singkat.
        try {
            $dup = $pdo->prepare("
                SELECT COUNT(*)
                FROM tb_notifikasi
                WHERE link = ?
                  AND message LIKE ?
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
            ");
            $dup->execute(['rekap_absensi_latihan_pramuka.php', "$aktor mengisi kehadiran Latihan Pramuka tingkat $tingkat untuk $tanggal_txt%"]);
            if ((int)$dup->fetchColumn() > 0) {
                return;
            }
        } catch (Throwable $e) {
            // Lanjut kirim bila pengecekan duplikat gagal.
        }

        try {
            createNotification($pdo, $message, 'rekap_absensi_latihan_pramuka.php', 'absensi_pramuka', null, 'admin_kepala');
        } catch (Throwable $e) {
            // Notifikasi tidak boleh menggagalkan penyimpanan kehadiran.
        }
    }
}

if (!function_exists('pramukaLatihanResolveSiswaId')) {
    function pramukaLatihanResolveSiswaId(PDO $pdo, int $idPeserta): int
    {
        $st = $pdo->prepare("SELECT id_siswa, nta FROM tb_peserta_didik_barung WHERE id_peserta_didik_barung = ? LIMIT 1");
        $st->execute([$idPeserta]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return 0;
        }
        $idSiswa = (int)($row['id_siswa'] ?? 0);
        if ($idSiswa > 0) {
            return $idSiswa;
        }
        $nta = trim((string)($row['nta'] ?? ''));
        if ($nta !== '') {
            $st = $pdo->prepare("SELECT id_siswa FROM tb_siswa WHERE CONVERT(TRIM(IFNULL(nisn, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci LIMIT 1");
            $st->execute([$nta]);
            return (int)($st->fetchColumn() ?: 0);
        }
        return 0;
    }
}

if (!function_exists('pramukaLatihanSyncToDaily')) {
    /**
     * Sinkron kehadiran latihan -> kehadiran siswa (tb_absensi).
     * Sama seperti updateSholatAttendance(): status latihan dipetakan langsung
     * ke tb_absensi dan status kosong menghapus baris.
     */
    function pramukaLatihanSyncToDaily(PDO $pdo, int $idPeserta, string $tanggal, string $status, $idGuru = null): void
    {
        try {
            if (!in_array($status, ['', 'Hadir', 'Sakit', 'Izin', 'Alpa', 'Terlambat'], true)) {
                return;
            }
            $tanggal = trim($tanggal);
            if ($tanggal === '') {
                return;
            }
            if (!pramukaLatihanIsScheduleDay($pdo, $tanggal)) {
                return;
            }
            $idSiswa = pramukaLatihanResolveSiswaId($pdo, $idPeserta);
            if ($idSiswa <= 0) {
                return;
            }
            $idGuru = ($idGuru !== null && (int)$idGuru > 0) ? (int)$idGuru : null;

            $check = $pdo->prepare("SELECT id_absensi FROM tb_absensi WHERE id_siswa = ? AND tanggal = ? ORDER BY id_absensi ASC LIMIT 1");
            $check->execute([$idSiswa, $tanggal]);
            $existingId = (int)($check->fetchColumn() ?: 0);

            if ($status === '') {
                if ($existingId > 0) {
                    $pdo->prepare("DELETE FROM tb_absensi WHERE id_absensi = ?")->execute([$existingId]);
                }
                return;
            }

            $now = date('H:i:s');
            if ($existingId > 0) {
                $pdo->prepare("UPDATE tb_absensi
                    SET keterangan = ?,
                        jam_masuk = IF(? = 'Hadir', IF(jam_masuk IS NULL, ?, jam_masuk), NULL),
                        id_guru = IFNULL(?, id_guru)
                    WHERE id_absensi = ?")
                    ->execute([$status, $status, $now, $idGuru, $existingId]);
            } else {
                $jam = ($status === 'Hadir') ? $now : null;
                $pdo->prepare("INSERT INTO tb_absensi (id_siswa, tanggal, keterangan, id_guru, jam_masuk) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$idSiswa, $tanggal, $status, $idGuru, $jam]);
            }
        } catch (Throwable $e) {
            // Sinkronisasi bersifat best-effort; simpan latihan tetap sukses.
        }
    }
}

if (!function_exists('pramukaLatihanSyncFromDaily')) {
    /**
     * Sinkron kehadiran siswa (tb_absensi) -> kehadiran latihan.
     * Hanya berjalan pada hari latihan sesuai jadwal Pramuka di tb_ekstrakurikuler.
     */
    function pramukaLatihanSyncFromDaily(PDO $pdo, int $idSiswa, string $tanggal, string $status, $idGuru = null): void
    {
        try {
            $tanggal = trim($tanggal);
            if ($idSiswa <= 0 || $tanggal === '' || !pramukaLatihanIsScheduleDay($pdo, $tanggal)) {
                return;
            }
            if (!in_array($status, ['', 'Hadir', 'Sakit', 'Izin', 'Alpa', 'Terlambat'], true)) {
                return;
            }

            $st = $pdo->prepare("SELECT p.id_peserta_didik_barung, p.id_tingkat_barung
                FROM tb_peserta_didik_barung p
                WHERE p.id_siswa = ?
                  AND " . pramukaLatihanPesertaAktifSql('p') . "
                ORDER BY p.id_peserta_didik_barung DESC
                LIMIT 1");
            $st->execute([$idSiswa]);
            $peserta = $st->fetch(PDO::FETCH_ASSOC);
            if (!$peserta) {
                $stN = $pdo->prepare("SELECT nisn FROM tb_siswa WHERE id_siswa = ? LIMIT 1");
                $stN->execute([$idSiswa]);
                $nisn = trim((string)($stN->fetchColumn() ?: ''));
                if ($nisn !== '') {
                    $st = $pdo->prepare("SELECT p.id_peserta_didik_barung, p.id_tingkat_barung
                        FROM tb_peserta_didik_barung p
                        WHERE p.id_siswa IS NULL
                          AND CONVERT(TRIM(IFNULL(p.nta, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                              = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
                          AND " . pramukaLatihanPesertaAktifSql('p') . "
                        ORDER BY p.id_peserta_didik_barung DESC
                        LIMIT 1");
                    $st->execute([$nisn]);
                    $peserta = $st->fetch(PDO::FETCH_ASSOC);
                }
            }
            if (!$peserta) {
                return;
            }

            $idPeserta = (int)$peserta['id_peserta_didik_barung'];
            $idTingkat = (int)($peserta['id_tingkat_barung'] ?? 0);
            $idGuru = ($idGuru !== null && (int)$idGuru > 0) ? (int)$idGuru : null;

            $check = $pdo->prepare("SELECT id_absensi_latihan FROM tb_absensi_latihan_pramuka WHERE id_peserta_didik_barung = ? AND tanggal = ? LIMIT 1");
            $check->execute([$idPeserta, $tanggal]);
            $existingId = (int)($check->fetchColumn() ?: 0);

            if ($status === '') {
                if ($existingId > 0) {
                    $pdo->prepare("DELETE FROM tb_absensi_latihan_pramuka WHERE id_absensi_latihan = ?")->execute([$existingId]);
                }
                return;
            }

            $now = date('H:i:s');
            if ($existingId > 0) {
                $pdo->prepare("UPDATE tb_absensi_latihan_pramuka
                    SET keterangan = ?, id_tingkat_barung = ?,
                        jam_masuk = IF(? = 'Hadir', IF(jam_masuk IS NULL, ?, jam_masuk), NULL),
                        id_guru = IFNULL(?, id_guru), waktu_input = NOW()
                    WHERE id_absensi_latihan = ?")
                    ->execute([$status, $idTingkat, $status, $now, $idGuru, $existingId]);
            } else {
                $jam = ($status === 'Hadir') ? $now : null;
                $pdo->prepare("INSERT INTO tb_absensi_latihan_pramuka
                    (id_peserta_didik_barung, id_tingkat_barung, tanggal, keterangan, id_guru, jam_masuk, waktu_input)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())")
                    ->execute([$idPeserta, $idTingkat, $tanggal, $status, $idGuru, $jam]);
            }
        } catch (Throwable $e) {
            // Sinkronisasi bersifat best-effort.
        }
    }
}

if (!function_exists('pramukaLatihanScope')) {
    /**
     * @return array{all: bool, ids: int[], guru_id: int}
     */
    function pramukaLatihanScope(PDO $pdo): array
    {
        $level = getUserLevel();
        if (in_array($level, ['admin', 'tata_usaha', 'kepala_madrasah'], true)) {
            return ['all' => true, 'ids' => [], 'guru_id' => 0];
        }

        $guruId = getCurrentGuruId($pdo);
        $ids = [];
        if ($guruId > 0) {
            try {
                $st = $pdo->prepare("
                    SELECT DISTINCT pt.id_tingkat_barung
                    FROM tb_pembina_tingkat pt
                    JOIN tb_pembina_pramuka p ON p.id_pembina_pramuka = pt.id_pembina_pramuka
                    WHERE p.id_guru = ?
                ");
                $st->execute([$guruId]);
                $ids = array_values(array_unique(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))));
            } catch (Throwable $e) {
                $ids = [];
            }

            if (empty($ids)) {
                // Fallback kolom lama tb_pembina_pramuka.id_tingkat_barung
                try {
                    $st = $pdo->prepare("SELECT id_tingkat_barung FROM tb_pembina_pramuka WHERE id_guru = ? AND id_tingkat_barung IS NOT NULL");
                    $st->execute([$guruId]);
                    $ids = array_values(array_unique(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))));
                    $ids = array_values(array_filter($ids, static fn($v) => $v > 0));
                } catch (Throwable $e) {
                    $ids = [];
                }
            }
        }

        return ['all' => false, 'ids' => $ids, 'guru_id' => $guruId];
    }
}

if (!function_exists('pramukaLatihanTingkatList')) {
    /**
     * @param array{all: bool, ids: int[], guru_id: int} $scope
     * @return array<int, array<string, mixed>>
     */
    function pramukaLatihanTingkatList(PDO $pdo, array $scope): array
    {
        if (!empty($scope['all'])) {
            return $pdo->query("SELECT * FROM tb_tingkat_barung ORDER BY nama_tingkat ASC")->fetchAll(PDO::FETCH_ASSOC);
        }
        $ids = array_values(array_filter(array_map('intval', $scope['ids'] ?? []), static fn($v) => $v > 0));
        if (empty($ids)) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT * FROM tb_tingkat_barung WHERE id_tingkat_barung IN ($in) ORDER BY nama_tingkat ASC");
        $st->execute($ids);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('pramukaLatihanCanAccessTingkat')) {
    /**
     * @param array{all: bool, ids: int[], guru_id: int} $scope
     */
    function pramukaLatihanCanAccessTingkat(array $scope, int $idTingkat): bool
    {
        if (!empty($scope['all'])) {
            return true;
        }
        return in_array($idTingkat, array_map('intval', $scope['ids'] ?? []), true);
    }
}

if (!function_exists('pramukaLatihanSaveSingle')) {
    /**
     * Simpan/perbarui satu baris kehadiran latihan.
     *
     * @param array{all: bool, ids: int[], guru_id: int} $scope
     * @return array{success: bool, error?: string}
     */
    function pramukaLatihanSaveSingle(PDO $pdo, array $scope, int $idPeserta, string $tanggal, string $status): array
    {
        $idPeserta = (int)$idPeserta;
        $tanggal = trim($tanggal);
        $status = trim($status);

        if ($idPeserta <= 0 || $tanggal === '') {
            return ['success' => false, 'error' => 'Data tidak lengkap.'];
        }
        if (!in_array($status, ['', 'Hadir', 'Sakit', 'Izin', 'Alpa', 'Terlambat'], true)) {
            return ['success' => false, 'error' => 'Status tidak valid.'];
        }

        try {
            $st = $pdo->prepare("SELECT p.id_peserta_didik_barung, p.id_tingkat_barung, IFNULL(p.status, 'aktif') AS status
                FROM tb_peserta_didik_barung p
                WHERE p.id_peserta_didik_barung = ?
                  AND " . pramukaLatihanPesertaAktifSql('p') . "
                LIMIT 1");
            $st->execute([$idPeserta]);
            $peserta = $st->fetch(PDO::FETCH_ASSOC);
            if (!$peserta) {
                return ['success' => false, 'error' => 'Peserta bukan siswa aktif atau sudah alumni.'];
            }
            if (($peserta['status'] ?? 'aktif') !== 'aktif') {
                return ['success' => false, 'error' => 'Peserta sudah tidak aktif.'];
            }
            $idTingkat = (int)($peserta['id_tingkat_barung'] ?? 0);
            if (!pramukaLatihanCanAccessTingkat($scope, $idTingkat)) {
                return ['success' => false, 'error' => 'Tingkatan ini bukan binaan Anda.'];
            }

            $check = $pdo->prepare("SELECT id_absensi_latihan FROM tb_absensi_latihan_pramuka WHERE id_peserta_didik_barung = ? AND tanggal = ? LIMIT 1");
            $check->execute([$idPeserta, $tanggal]);
            $existingId = (int)($check->fetchColumn() ?: 0);

            if ($status === '') {
                if ($existingId > 0) {
                    $d = $pdo->prepare("DELETE FROM tb_absensi_latihan_pramuka WHERE id_absensi_latihan = ?");
                    $d->execute([$existingId]);
                }
                $idGuruReset = ((int)($scope['guru_id'] ?? 0)) > 0 ? (int)$scope['guru_id'] : null;
                pramukaLatihanSyncToDaily($pdo, $idPeserta, $tanggal, '', $idGuruReset);
                return ['success' => true];
            }

            $now = date('H:i:s');
            $idGuru = ((int)($scope['guru_id'] ?? 0)) > 0 ? (int)$scope['guru_id'] : null;
            if ($existingId > 0) {
                $u = $pdo->prepare("UPDATE tb_absensi_latihan_pramuka
                    SET keterangan = ?, id_tingkat_barung = ?,
                        jam_masuk = IF(? = 'Hadir', IF(jam_masuk IS NULL, ?, jam_masuk), NULL),
                        waktu_input = NOW()
                    WHERE id_absensi_latihan = ?");
                $u->execute([$status, $idTingkat, $status, $now, $existingId]);
            } else {
                $jam = ($status === 'Hadir') ? $now : null;
                $i = $pdo->prepare("INSERT INTO tb_absensi_latihan_pramuka
                    (id_peserta_didik_barung, id_tingkat_barung, tanggal, keterangan, id_guru, jam_masuk, waktu_input)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $i->execute([$idPeserta, $idTingkat, $tanggal, $status, $idGuru, $jam]);
            }

            pramukaLatihanSyncToDaily($pdo, $idPeserta, $tanggal, $status, $idGuru);

            return ['success' => true];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Gagal menyimpan kehadiran: ' . $e->getMessage()];
        }
    }
}
