<?php
// Start output buffering with Indonesian date translation
ob_start(function($buffer) {
    $months_full = [
        'January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret', 'May' => 'Mei',
        'June' => 'Juni', 'July' => 'Juli', 'August' => 'Agustus', 'October' => 'Oktober',
        'December' => 'Desember'
    ];
    $months_short = [
        'Jan' => 'Jan', 'Feb' => 'Feb', 'Mar' => 'Mar', 'Apr' => 'Apr', 'May' => 'Mei',
        'Jun' => 'Jun', 'Jul' => 'Jul', 'Aug' => 'Agu', 'Sep' => 'Sep', 'Oct' => 'Okt',
        'Nov' => 'Nov', 'Dec' => 'Des'
    ];
    $days_full = [
        'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
    ];
    
    // Replace full month and day names
    $buffer = str_replace(array_keys($months_full), array_values($months_full), $buffer);
    $buffer = str_replace(array_keys($days_full), array_values($days_full), $buffer);
    
    // Replace short month names with word boundaries to avoid corruption
    foreach ($months_short as $en => $id) {
        if ($en !== $id) {
            $buffer = preg_replace('/\b' . $en . '\b/', $id, $buffer);
        }
    }
    
    return $buffer;
});

// Set default timezone to Asia/Jakarta
date_default_timezone_set('Asia/Jakarta');

if (session_status() == PHP_SESSION_NONE) {
    // --- SESSION CONFIGURATION PHP 8+ ---
    
    // Tentukan folder sesi lokal untuk menghindari limitasi server
    $session_dir = dirname(__DIR__) . '/sessions';
    if (!is_dir($session_dir)) {
        @mkdir($session_dir, 0755, true);
    }

    // Gunakan folder lokal jika writable
    if (is_writable($session_dir)) {
        @session_save_path($session_dir);
        
        // Bersihkan file sesi yang lebih dari 24 jam (86400 detik)
        // Probabilitas 5% untuk menghindari beban server di setiap request
        if (rand(1, 100) <= 5) {
            $files = glob($session_dir . '/sess_*');
            $now = time();
            foreach ($files as $file) {
                if (is_file($file) && ($now - filemtime($file) >= 86400)) {
                    @unlink($file);
                }
            }
        }
    }

    @session_start();
}

// Function to switch session context
function startUserSession(string $level): void {
    if (session_status() == PHP_SESSION_NONE) {
        $session_dir = dirname(__DIR__) . '/sessions';
        if (is_dir($session_dir) && is_writable($session_dir)) {
            @session_save_path($session_dir);
        }
        @session_start();
    }
}

// Function to redirect user
function redirect(string $page): void {
    if (!headers_sent()) {
        header("Location: $page");
        exit();
    } else {
        echo "<script>window.location.href='$page';</script>";
        exit();
    }
}

// Function to check if user is logged in
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

// Function to get user level
function getUserLevel(): string {
    $level = isset($_SESSION['level']) ? strtolower(trim($_SESSION['level'])) : '';
    if ($level === 'kepala') {
        return 'kepala_madrasah';
    }
    if ($level === 'tu') {
        return 'tata_usaha';
    }
    return $level;
}

// Function to check user authorization
function isAuthorized(array $allowed_levels = []): bool {
    if (!isLoggedIn()) {
        return false;
    }
    
    if (empty($allowed_levels)) {
        return true;
    }

    $current_level = getUserLevel();
    $login_source = $_SESSION['login_source'] ?? '';
    
    $normalized_allowed_levels = [];
    foreach ($allowed_levels as $level) {
        $level = strtolower(trim($level));
        if ($level === 'kepala') {
            $normalized_allowed_levels[] = 'kepala_madrasah';
        } elseif ($level === 'tu') {
            $normalized_allowed_levels[] = 'tata_usaha';
        } else {
            $normalized_allowed_levels[] = $level;
        }
    }

    // KHUSUS GURU & WALI: Jika login berasal dari tb_guru, berikan akses ke semua halaman guru/wali
    if ($login_source === 'tb_guru') {
        if (in_array('guru', $normalized_allowed_levels, true) || in_array('wali', $normalized_allowed_levels, true)) {
            return true;
        }
    }

    // Pengecekan standar untuk level lainnya
    if (in_array($current_level, $normalized_allowed_levels, true)) {
        return true;
    }

    // Fallback: Wali juga dianggap Guru
    if ($current_level === 'wali' && in_array('guru', $normalized_allowed_levels, true)) {
        return true;
    }

    return false;
}

/**
 * Tahun mulai tahun ajaran berjalan (Indonesia: Juli-Juni).
 * Contoh: Agustus 2026 -> 2026; Juni 2026 -> 2025.
 */
function getTahunAjaranBerjalanStartYear(?int $timestamp = null): int {
    $ts = $timestamp ?? time();
    $y = (int)date('Y', $ts);
    $m = (int)date('n', $ts);
    return ($m >= 7) ? $y : ($y - 1);
}

/** Format YYYY/(YYYY+1) benar (tanpa batasan tahun). */
function isTahunAjaranFormatValid(?string $ta): bool {
    if ($ta === null || $ta === '') {
        return false;
    }
    $ta = trim($ta);
    if (!preg_match('/^(\d{4})\/(\d{4})$/', $ta, $m)) {
        return false;
    }
    return (int)$m[2] === (int)$m[1] + 1;
}

/** Dua digit akhir tahun kedua pada tahun ajaran "YYYY/YYYY+1" (contoh 2025/2026 → "26"). */
function tahunAjaranSuffix2(?string $tahunAjaran): string {
    $tahunAjaran = trim((string)$tahunAjaran);
    if (preg_match('/\/(\d{4})$/', $tahunAjaran, $m)) {
        return substr($m[1], -2);
    }
    return substr((string)((int) date('Y') % 100), -2);
}

/** Kode sekolah untuk nomor ujian: hanya angka, tepat 4 digit (hingga 9999). */
function normalizeKodeNomorUjian4(?string $raw): string {
    $d = preg_replace('/\D/', '', (string) $raw);
    if ($d === '') {
        return '';
    }
    $d = substr($d, -4);
    return str_pad($d, 4, '0', STR_PAD_LEFT);
}

/** Kode wilayah kecamatan dalam nomor ujian (hardcode). */
function nomorUjianKodeKecamatanTetap(): int {
    return 11;
}

/** Kode wilayah kabupaten dalam nomor ujian (hardcode). */
function nomorUjianKodeKabupatenTetap(): int {
    return 20;
}

/** Tingkat dalam nomor ujian (hardcode). */
function nomorUjianTingkatTetap(): int {
    return 1;
}

/** Kode madrasah empat digit dalam nomor ujian (hardcode). */
function nomorUjianKodeMadrasahTetap(): string {
    return normalizeKodeNomorUjian4('0140');
}

/**
 * Susun nomor ujian: YY-kecamatan-kabupaten-tingkat-kode madrasah-urut.
 * Hanya dua digit tahun (dari TA) dan urutan yang bersifat dinamis per generate.
 */
function susunNomorUjianFormal(string $yy2, int $urut): string {
    $kec = nomorUjianKodeKecamatanTetap();
    $kab = nomorUjianKodeKabupatenTetap();
    $tingkat = nomorUjianTingkatTetap();
    $kode4 = nomorUjianKodeMadrasahTetap();
    $yy2 = preg_replace('/\D/', '', $yy2);
    $yy2 = str_pad(substr($yy2 !== '' ? $yy2 : '0', -2), 2, '0', STR_PAD_LEFT);
    $urut = max(1, min(9999, $urut));
    return sprintf('%s-%02d-%02d-%d-%s-%04d', $yy2, $kec, $kab, $tingkat, $kode4, $urut);
}

/**
 * Rentang tanggal (inclusive) untuk tahun ajaran: Juli y0 - Juni (y0+1).
 *
 * @return array{mulai: string, sampai: string}|null
 */
function getRentangTanggalTahunAjaran(?string $tahunAjaran): ?array {
    if (!isTahunAjaranFormatValid($tahunAjaran)) {
        return null;
    }
    preg_match('/^(\d{4})\//', trim($tahunAjaran), $m);
    $y0 = (int)$m[1];
    return [
        'mulai' => sprintf('%04d-07-01', $y0),
        'sampai' => sprintf('%04d-06-30', $y0 + 1),
    ];
}

/** Kumpulkan string TA dari kolom tanggal (absensi, jurnal) untuk dropdown / arsip. */
function gatherTahunAjaranDariTabelDenganTanggal(PDO $pdo): array {
    $sql = 'SELECT DISTINCT CONCAT(
        IF(MONTH(tanggal) >= 7, YEAR(tanggal), YEAR(tanggal) - 1),
        \'/\',
        IF(MONTH(tanggal) >= 7, YEAR(tanggal) + 1, YEAR(tanggal))
    ) AS ta FROM %s';
    $tables = ['tb_absensi', 'tb_absensi_guru', 'tb_jurnal'];
    $out = [];
    foreach ($tables as $t) {
        try {
            $stmt = $pdo->query(sprintf($sql, $t));
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!empty($row['ta']) && isTahunAjaranFormatValid($row['ta'])) {
                        $out[] = $row['ta'];
                    }
                }
            }
        } catch (Throwable $e) {
        }
    }
    return array_values(array_unique($out));
}

// Function to get school profile
function getSchoolProfile(PDO $pdo): array {
    $defaults = [
        'id' => null,
        'nama_yayasan' => '',
        'nama_madrasah' => 'Madrasah',
        'alamat' => '',
        'email_madrasah' => '',
        'website_madrasah' => '',
        'kepala_madrasah' => '',
        'nama_kepala' => '',
        'nip_kepala' => '',
        'logo' => '',
        'ttd_kepala' => '',
        'dashboard_hero_image' => '',
        'tahun_ajaran' => null,
        'semester' => null,
        'tanggal_jadwal' => null,
        'tempat_jadwal' => '',
        'hari_libur_mingguan' => 'jumat'
    ];

    $profile = null;
    try {
        $stmt = $pdo->query("SELECT * FROM tb_profil_madrasah LIMIT 1");
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $profile = null;
    }

    $result = $profile ? array_merge($defaults, $profile) : $defaults;

    if (!empty($result['semester'])) {
        if ($result['semester'] === 'Ganjil') $result['semester'] = 'Semester 1';
        if ($result['semester'] === 'Genap') $result['semester'] = 'Semester 2';
    }

    if (empty($result['tahun_ajaran']) || empty($result['semester'])) {
        $period = null;

        try {
            $stmt = $pdo->query("SELECT tahun_ajaran, semester FROM tb_nilai_semester ORDER BY id_nilai DESC LIMIT 1");
            $period = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $period = null;
        }

        if (!$period || empty($period['tahun_ajaran']) || empty($period['semester'])) {
            try {
                $stmt = $pdo->query("SELECT tahun_ajaran, semester FROM tb_nilai_harian_header ORDER BY id_header DESC LIMIT 1");
                $period = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $period = null;
            }
        }

        if (!$period || empty($period['tahun_ajaran']) || empty($period['semester'])) {
            try {
                $stmt = $pdo->query("SELECT tahun_ajaran, semester FROM tb_nilai_kokurikuler_header ORDER BY id_header DESC LIMIT 1");
                $period = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $period = null;
            }
        }

        if ($period && isTahunAjaranFormatValid($period['tahun_ajaran'] ?? null)) {
            if (empty($result['tahun_ajaran']) && !empty($period['tahun_ajaran'])) {
                $result['tahun_ajaran'] = trim((string)$period['tahun_ajaran']);
            }
            if (empty($result['semester']) && !empty($period['semester'])) {
                $result['semester'] = $period['semester'];
            }
        }

        if (empty($result['tahun_ajaran'])) {
            $a = getTahunAjaranBerjalanStartYear();
            $result['tahun_ajaran'] = $a . '/' . ($a + 1);
        }

        if (empty($result['semester'])) {
            $result['semester'] = ((int)date('n') <= 6) ? 'Semester 2' : 'Semester 1';
        }
    }

    if (!empty($result['tahun_ajaran'])) $result['tahun_ajaran'] = trim((string)$result['tahun_ajaran']);
    if (!empty($result['semester'])) $result['semester'] = trim((string)$result['semester']);

    // Jangan override periode dari profil dengan periode data nilai terakhir.
    // Admin harus bisa memilih TA/Semester aktif meskipun data nilainya belum ada.

    if (empty($result['nama_kepala']) && !empty($result['kepala_madrasah'])) {
        $result['nama_kepala'] = $result['kepala_madrasah'];
    }

    $hlRaw = strtolower(trim((string)($result['hari_libur_mingguan'] ?? '')));
    $result['hari_libur_mingguan'] = ($hlRaw === 'minggu' || $hlRaw === 'ahad') ? 'minggu' : 'jumat';

    return $result;
}

/**
 * Opsi tahun ajaran untuk dropdown profil: TA berjalan + 3 tahun ke depan,
 * digabung TA dari profil/nilai/absensi/jurnal agar bisa kembali melihat arsip.
 *
 * @param array $additionalFromDb mis. tahun dari tb_nilai_* atau gatherTahunAjaranDariTabelDenganTanggal
 */
function buildTahunAjaranProfilOptions(?string $profileTahunAjaran = null, array $additionalFromDb = []): array {
    $anchor = getTahunAjaranBerjalanStartYear();
    $starts = [];
    for ($i = $anchor; $i <= $anchor + 3; $i++) {
        $starts[$i] = true;
    }
    $add = function (?string $ta) use (&$starts) {
        if (!isTahunAjaranFormatValid($ta)) {
            return;
        }
        preg_match('/^(\d{4})\//', trim($ta), $m);
        $starts[(int)$m[1]] = true;
    };
    $add($profileTahunAjaran);
    foreach ($additionalFromDb as $yt) {
        $add(is_string($yt) ? $yt : null);
    }
    ksort($starts, SORT_NUMERIC);
    $out = [];
    foreach (array_keys($starts) as $start) {
        $out[] = $start . '/' . ($start + 1);
    }
    return $out;
}

/**
 * Daftar mapel untuk modul selain jadwal (nilai, rekap nilai, jurnal, dll.).
 * Default: hanya jenis_mapel Akademik atau NULL. Non-Akademik untuk jadwal saja (query terpisah di modul jadwal).
 *
 * @param bool $semuaJenis false = hanya akademik (nilai), true = semua jenis (tetap kecuali slot struktural di nama).
 */
function getFilteredSubjects(PDO $pdo, bool $semuaJenis = false): array {
    static $has_jenis_mapel = null;

    if ($has_jenis_mapel === null) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM tb_mata_pelajaran LIKE 'jenis_mapel'");
            $has_jenis_mapel = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $has_jenis_mapel = false;
        }
    }

    $sql = 'SELECT * FROM tb_mata_pelajaran';
    $conditions = [];
    foreach ([
        '%Asmaul Husna%',
        '%Upacara%',
        '%Istirahat%',
        '%Kepramukaan%',
        '%Ekstrakurikuler%',
        '%Ramadhanku%',
    ] as $pattern) {
        $conditions[] = 'nama_mapel NOT LIKE ' . $pdo->quote($pattern);
    }

    if (!$semuaJenis && $has_jenis_mapel) {
        $conditions[] = '(jenis_mapel IS NULL OR jenis_mapel = ' . $pdo->quote('Akademik') . ')';
    }

    if (!empty($conditions)) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' ORDER BY CAST(kode_mapel AS UNSIGNED), kode_mapel ASC';

    try {
        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $stmt = $pdo->query('SELECT * FROM tb_mata_pelajaran ORDER BY CAST(kode_mapel AS UNSIGNED), kode_mapel ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

function getLoggedInTeacherId(PDO $pdo): ?int {
    $user_id = $_SESSION['user_id'] ?? null;
    if (!$user_id) return null;

    if (isset($_SESSION['login_source']) && $_SESSION['login_source'] === 'tb_pengguna') {
        try {
            $stmt = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
            $stmt->execute([$user_id]);
            $id_guru = $stmt->fetchColumn();
            return $id_guru ? (int)$id_guru : null;
        } catch (PDOException $e) {
            return null;
        }
    }

    return (int)$user_id;
}

function getTeacherAccessibleClasses(PDO $pdo, ?int $id_guru, bool $only_grade_6 = false): array {
    if (!$id_guru) return [];

    $stmt = $pdo->prepare("SELECT nama_guru, mengajar FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([$id_guru]);
    $guru = $stmt->fetch(PDO::FETCH_ASSOC);

    $nama_guru = $guru['nama_guru'] ?? '';
    $mengajar_ids = [];
    if (!empty($guru['mengajar'])) {
        $decoded = json_decode($guru['mengajar'], true);
        if (is_array($decoded)) $mengajar_ids = $decoded;
    }

    $wali_ids = [];
    if ($nama_guru !== '') {
        // Try exact match first
        $stmt = $pdo->prepare("SELECT id_kelas FROM tb_kelas WHERE wali_kelas = ?");
        $stmt->execute([$nama_guru]);
        $wali_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // If no match, try partial match (without gelar)
        if (empty($wali_ids)) {
            $base_name = explode(',', $nama_guru)[0];
            $stmt = $pdo->prepare("SELECT id_kelas FROM tb_kelas WHERE wali_kelas LIKE ?");
            $stmt->execute([$base_name . '%']);
            $wali_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }

    $all_ids = array_values(array_unique(array_filter(array_merge($mengajar_ids, $wali_ids), function ($v) {
        return $v !== null && $v !== '';
    })));

    if (empty($all_ids)) return [];

    $placeholders = implode(',', array_fill(0, count($all_ids), '?'));
    $params = array_merge($all_ids, $all_ids);
    $stmt = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas IN ($placeholders) OR nama_kelas IN ($placeholders) ORDER BY nama_kelas ASC");
    $stmt->execute($params);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$only_grade_6) return $classes;

    $filtered = [];
    foreach ($classes as $c) {
        $nk = strtoupper($c['nama_kelas'] ?? '');
        if (strpos($nk, '6') !== false || strpos($nk, 'VI') !== false) {
            $filtered[] = $c;
        }
    }
    return $filtered;
}

// Function to format date
function formatDate(string $date): string {
    if (empty($date)) return '-';
    $dateObj = new DateTime($date);
    $bulan = array(
        'Jan' => 'Jan', 'Feb' => 'Feb', 'Mar' => 'Mar', 'Apr' => 'Apr', 'May' => 'Mei',
        'Jun' => 'Jun', 'Jul' => 'Jul', 'Aug' => 'Agu', 'Sep' => 'Sep', 'Oct' => 'Okt',
        'Nov' => 'Nov', 'Des' => 'Des'
    );
    $day = $dateObj->format('d');
    $month_en = $dateObj->format('M');
    $month = $bulan[$month_en] ?? $month_en;
    $year = $dateObj->format('Y');
    return "$day $month $year";
}

// Function to get current date in Indonesian format
function getCurrentDateIndonesia(): string {
    $hari = array(
        'Sunday' => 'Minggu',
        'Monday' => 'Senin',
        'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis',
        'Friday' => 'Jumat',
        'Saturday' => 'Sabtu'
    );
    
    $bulan = array(
        'January' => 'Januari',
        'February' => 'Februari',
        'March' => 'Maret',
        'April' => 'April',
        'May' => 'Mei',
        'June' => 'Juni',
        'July' => 'Juli',
        'August' => 'Agustus',
        'September' => 'September',
        'October' => 'Oktober',
        'November' => 'November',
        'December' => 'Desember'
    );
    
    $day = $hari[date('l')];
    $date = date('d');
    $month = $bulan[date('F')];
    $year = date('Y');
    
    return "$day, $date $month $year";
}

/**
 * Kode hari libur mingguan dari DB (sumber utama; tidak bergantung pada merge getSchoolProfile).
 *
 * @return 'jumat'|'minggu'
 */
function resolveHariLiburMingguanKode(PDO $pdo): string {
    try {
        $stmt = $pdo->query('SELECT hari_libur_mingguan FROM tb_profil_madrasah ORDER BY id ASC LIMIT 1');
        if (!$stmt) {
            return 'jumat';
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !array_key_exists('hari_libur_mingguan', $row)) {
            return 'jumat';
        }
        $raw = $row['hari_libur_mingguan'];
        if ($raw === null || $raw === '') {
            return 'jumat';
        }
        $v = strtolower(trim((string)$raw));
        if ($v === 'minggu' || $v === 'ahad') {
            return 'minggu';
        }
        return 'jumat';
    } catch (Throwable $e) {
        return 'jumat';
    }
}

/**
 * Urutan 6 hari sekolah untuk tampilan/export jadwal (tanpa kolom hari libur mingguan).
 * Libur mingguan Jumat → minggu efektif Sabtu–Kamis (mulai Sabtu).
 * Libur mingguan Ahad → Senin–Sabtu (mulai Senin).
 *
 * @return list<string>
 */
function getUrutanHariJadwalSekolah(PDO $pdo): array {
    if (resolveHariLiburMingguanKode($pdo) === 'minggu') {
        return ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    }
    return ['Sabtu', 'Ahad', 'Senin', 'Selasa', 'Rabu', 'Kamis'];
}

/**
 * Urutan 7 hari untuk dropdown modal (jadwal imam dhuha, seragam guru/siswa).
 * Hari libur mingguan di urutan terakhir: Jumat atau Ahad.
 *
 * @return list<string>
 */
function getUrutanHariPilihanModal7Hari(PDO $pdo): array {
    if (resolveHariLiburMingguanKode($pdo) === 'minggu') {
        return ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Ahad'];
    }
    return ['Sabtu', 'Ahad', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
}

/**
 * Argumen FIELD(hari, …) untuk ORDER BY — nilai sudah di-quote lewat PDO.
 */
function getSqlFieldUrutanHari7(PDO $pdo): string {
    $parts = [];
    foreach (getUrutanHariPilihanModal7Hari($pdo) as $d) {
        $parts[] = $pdo->quote($d);
    }
    return implode(',', $parts);
}

/**
 * Apakah label libur dari kalender tampak seperti libur mingguan Jumat (bukan hari besar nasional).
 */
function isKalenderLabelLiburMingguanJumat(string $label): bool {
    $t = mb_strtolower(trim($label));
    if ($t === '') {
        return false;
    }
    if (preg_match('/idul|natal|tahun\s+baru|kemerdekaan|waisak|imlek|merdeka|nasional|cuti\s+bersama|nyepi|vesak|isra\s*\'?|mi\'?raj|rebo\s*wekasan|lebaran|hari\s+raya/i', $t)) {
        return false;
    }
    $exact = [
        'hari libur (jumat)',
        "hari libur (jum'at)",
        'hari jumat (libur mingguan)',
        'libur jumat',
        'libur mingguan jumat',
    ];
    if (in_array($t, $exact, true)) {
        return true;
    }
    if (preg_match('/^hari\s+libur\s*\(?\s*jum/i', $t)) {
        return true;
    }
    if (mb_strlen($t) <= 48 && preg_match('/\bjumat\b/i', $t) && preg_match('/\blibur\b/i', $t)) {
        return true;
    }
    return false;
}

/**
 * Apakah label libur dari kalender tampak seperti libur mingguan Ahad.
 */
function isKalenderLabelLiburMingguanAhad(string $label): bool {
    $t = mb_strtolower(trim($label));
    if ($t === '') {
        return false;
    }
    if (preg_match('/idul|natal|tahun\s+baru|kemerdekaan|waisak|imlek|merdeka|nasional|cuti\s+bersama|nyepi|vesak|lebaran|hari\s+raya/i', $t)) {
        return false;
    }
    $exact = [
        'hari libur (ahad/minggu)',
        'hari libur (minggu)',
        'hari ahad/minggu (libur mingguan)',
        'libur minggu',
        'libur ahad',
    ];
    if (in_array($t, $exact, true)) {
        return true;
    }
    if (mb_strlen($t) <= 52 && preg_match('/\b(ahad|minggu)\b/i', $t) && preg_match('/\blibur\b/i', $t)) {
        return true;
    }
    return false;
}

/**
 * Libur dari kalender yang bukan sekadar pola mingguan (tetap ditampilkan walau bentrok hari dengan profil).
 * Dipakai saat profil libur mingguan = Ahad: semua Jumat dari kalender dihapus KECUALI yang cocok pola ini.
 */
function isKalenderEntriLiburLuarPolaMingguan(string $label): bool {
    $t = mb_strtolower(trim($label));
    if ($t === '') {
        return false;
    }
    if (preg_match(
        '/idul\s*fitri|idul\s*adha|lebaran|hari\s+raya|natal|tahun\s+baru|(?:m)?aulid|isra\s*\'?\s*mi\'?raj|waisak|vesak|nyepi|imlek|toleransi|pancasila|kemerdekaan|merdeka|17\s+agustus|agustusan|cuti\s+bersama|hari\s+besar|hari\s+olah\s+raga|may\s+day|buruh|paskah|jumat\s+agung|good\s+friday|wafat\s+yesus|kenaikan|isa\s+almasih|ascension|kristus\s+raja|rebo\s*wekasan|sumpah\s+pemuda|hari\s+ibu|hari\s+guru|silaturahmi|tahun\s+baru\s+islam|tahun\s+baru\s+masehi|suro|tujuh\s+belas|harlah|hgn/i',
        $t
    )) {
        return true;
    }
    if (preg_match(
        '/\b(semester|pekan|ujian|usbn|ujian\s+nasional|assessment|wisuda|prakerin|mos|study\s*tour|study\s*band|pts|pat|susulan|evaluasi)\b/i',
        $t
    )) {
        return true;
    }
    return false;
}

/**
 * Hapus dari peta libur tanggal yang bentrok: mis. kalender masih berisi pola "libur Jumat"
 * padahal profil memilih Ahad (supaya tidak dobel dengan libur mingguan sistem).
 *
 * @param array<string,string> $holidays
 */
function stripKalenderLiburMingguanYangBentrokDenganProfil(array &$holidays, int $profilNIso): void {
    foreach (array_keys($holidays) as $d) {
        $n = (int)date('N', strtotime($d));
        $name = (string)$holidays[$d];
        if ($profilNIso === 7 && $n === 5 && !isKalenderEntriLiburLuarPolaMingguan($name)) {
            unset($holidays[$d]);
        } elseif ($profilNIso === 5 && $n === 7 && !isKalenderEntriLiburLuarPolaMingguan($name)) {
            unset($holidays[$d]);
        }
    }
}

/**
 * Meta hari libur mingguan dari profil madrasah (dipakai absensi, rekap, kalender).
 *
 * @return array{n: int, w: int, nama_holiday: string, nama_rekap: string, kode: string}
 *   n = weekday ISO-8601 (1=Senin … 5=Jumat, 7=Minggu); w = PHP date('w') (0=Minggu … 5=Jumat)
 */
function getProfilHariLiburMingguanMeta(PDO $pdo): array {
    $kode = resolveHariLiburMingguanKode($pdo);
    if ($kode === 'minggu') {
        return [
            'n' => 7,
            'w' => 0,
            'nama_holiday' => 'Hari Ahad (Libur Mingguan)',
            'nama_rekap' => 'Hari Libur (Ahad)',
            'kode' => 'minggu',
        ];
    }
    return [
        'n' => 5,
        'w' => 5,
        'nama_holiday' => 'Hari Jumat (Libur Mingguan)',
        'nama_rekap' => 'Hari Libur (Jum\'at)',
        'kode' => 'jumat',
    ];
}

// Function to check if a date is a holiday based on kalender pendidikan
function isHoliday(PDO $pdo, string $date): array {
    $weekly = getProfilHariLiburMingguanMeta($pdo);
    $dayOfWeek = (int)date('w', strtotime($date));
    if ($dayOfWeek === $weekly['w']) {
        return ['is_holiday' => true, 'name' => $weekly['nama_holiday']];
    }

    // 2. Check in tb_kalender_pendidikan for events with warna = 'danger' (Libur)
    try {
        $stmt = $pdo->prepare("
            SELECT nama_kegiatan 
            FROM tb_kalender_pendidikan 
            WHERE ? BETWEEN tgl_mulai AND tgl_selesai 
            AND warna = 'danger' 
            LIMIT 1
        ");
        $stmt->execute([$date]);
        $holiday = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($holiday) {
            $nama = (string)$holiday['nama_kegiatan'];
            $nDate = (int)date('N', strtotime($date));
            if ((int)$weekly['n'] === 7 && $nDate === 5) {
                if (isKalenderEntriLiburLuarPolaMingguan($nama)) {
                    return ['is_holiday' => true, 'name' => $nama];
                }
            } elseif ((int)$weekly['n'] === 5 && $nDate === 7) {
                if (isKalenderEntriLiburLuarPolaMingguan($nama)) {
                    return ['is_holiday' => true, 'name' => $nama];
                }
            } else {
                return ['is_holiday' => true, 'name' => $nama];
            }
        }
    } catch (PDOException $e) {
        // Fallback if table doesn't exist or other DB error
    }

    return ['is_holiday' => false, 'name' => ''];
}

// Function to format specific date in Indonesian format
function formatDateIndonesia(?string $date_string): string {
    if (empty($date_string)) return '-';
    $date = new DateTime($date_string);
    $bulan = array(
        'January' => 'Januari',
        'February' => 'Februari',
        'March' => 'Maret',
        'April' => 'April',
        'May' => 'Mei',
        'June' => 'Juni',
        'July' => 'Juli',
        'August' => 'Agustus',
        'September' => 'September',
        'October' => 'Oktober',
        'November' => 'November',
        'December' => 'Desember'
    );
    $day = $date->format('d');
    $month = $bulan[$date->format('F')];
    $year = $date->format('Y');
    return "$day $month $year";
}

/** Label semester untuk cetak/ekspor: selalu kata "Semester …" lengkap (bukan singkatan). */
function formatSemesterLabelForExport(?string $semester): string {
    $s = trim((string)$semester);
    if ($s === '') {
        return '';
    }
    if (preg_match('/^semester\b/iu', $s)) {
        return $s;
    }
    return 'Semester ' . $s;
}

/**
 * Helper function to sort menu items alphabetically, keeping Dashboard first and Logout last
 * and deduplicating by normalized title.
 */
if (!function_exists('sort_all_menu_items')) {
    function sort_all_menu_items(array &$items): void {
        if (empty($items)) return;
        
        $dashboard = null;
        $logout = null;
        $middle = [];
        $seen_titles = [];

        foreach ($items as $item) {
            $title = isset($item['title']) ? trim(strip_tags($item['title'])) : 'Untitled';
            
            // Normalize title for deduplication (e.g. Profil & Pengaturan vs Profil &amp; Pengaturan)
            $normalized_title = html_entity_decode($title);
            
            if (isset($seen_titles[$normalized_title])) continue;
            $seen_titles[$normalized_title] = true;

            if (strcasecmp($normalized_title, 'Dashboard') === 0) {
                $dashboard = $item;
            } elseif (strcasecmp($normalized_title, 'Logout') === 0) {
                $logout = $item;
            } else {
                // Do not sort submenu A-Z. Only move "Scan Absensi" to the very top
                // while preserving the existing order of the other items.
                if (isset($item['submenu']) && is_array($item['submenu']) && (strpos($normalized_title, 'Absensi') !== false)) {
                    $scan_index = null;
                    foreach ($item['submenu'] as $idx => $sub) {
                        $t = trim(strip_tags($sub['title'] ?? ''));
                        if (strcasecmp($t, 'Scan Absensi') === 0) {
                            $scan_index = $idx;
                            break;
                        }
                    }
                    if ($scan_index !== null) {
                        $scan_item = $item['submenu'][$scan_index];
                        array_splice($item['submenu'], $scan_index, 1);
                        array_unshift($item['submenu'], $scan_item);
                    }
                }
                $middle[] = $item;
            }
        }
        
        // Reconstruct
        $new_items = [];
        if ($dashboard) $new_items[] = $dashboard;
        foreach ($middle as $m) $new_items[] = $m;
        if ($logout) $new_items[] = $logout;
        
        $items = $new_items;
    }
}

// Function to log activity
function logActivity(PDO $pdo, string $username, string $action, string $description = ''): bool {
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $pdo->prepare("INSERT INTO tb_activity_log (username, action, description, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())");
        return $stmt->execute([$username, $action, $description, $ip_address]);
    } catch (Exception $e) {
        // Jangan biarkan error log aktivitas menghentikan proses utama (seperti login)
        error_log("Activity log failed: " . $e->getMessage());
        return false;
    }
}

/**
 * SIBAYAR SPP - INTEGRATION CLIENT FOR SIMAD
 * Versi: 1.0.0
 */
class SibayarClient {
    private string $apiUrl;
    private string $apiKey;

    public function __construct(string $apiKey = 'SPP_SECRET_KEY_2026') {
        $this->apiKey = $apiKey;
        $this->apiUrl = "https://sibayar.misultanfattah.sch.id/api/simad.php";
    }

    private function request(string $action, array $params = []): array {
        $queryParams = array_merge([
            'api_key' => $this->apiKey,
            'action' => $action
        ], $params);

        $url = $this->apiUrl . '?' . http_build_query($queryParams);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-API-KEY: ' . $this->apiKey,
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'status' => 'error',
                'message' => 'Connection failed: ' . $error
            ];
        }

        $result = json_decode((string)$response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'status' => 'error',
                'message' => 'Invalid JSON response',
                'raw_response' => (string)$response
            ];
        }

        return $result;
    }

    public function getStudentDetail(string $nisn): array {
        return $this->request('get_student_data', ['nisn' => $nisn]);
    }
}

function fetchSibayarData(string $nisn, string $type = 'tagihan'): array {
     $client = new SibayarClient();
     $response = $client->getStudentDetail($nisn);
 
     if ($response['status'] === 'success') {
         // Map response based on request type
         if ($type === 'tagihan') {
             return [
                 'status' => 'success',
                 'data' => $response['billing'] ?? []
             ];
         } elseif ($type === 'laporan') {
             // Return everything for detailed report page
             return $response;
         } else {
             return [
                 'status' => 'success',
                 'data' => $response['payments'] ?? []
             ];
         }
     }
 
     return $response;
 }

// Function to create notification
function createNotification(PDO $pdo, string $message, string $link, string $type = 'info'): bool {
    // Ignoring $type as column doesn't exist in current schema
    $stmt = $pdo->prepare("INSERT INTO tb_notifikasi (message, link, created_at) VALUES (?, ?, NOW())");
    return $stmt->execute([$message, $link]);
}

// Function to get system notifications (auto delete > 24 hours)
function getNotifications(PDO $pdo): array {
    // Delete notifications older than 24 hours
    $cleanup_stmt = $pdo->prepare("DELETE FROM tb_notifikasi WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $cleanup_stmt->execute();

    // Get all notifications from last 24 hours
    $stmt = $pdo->prepare("SELECT * FROM tb_notifikasi ORDER BY created_at DESC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get unread notifications (Deprecated, alias to getNotifications)
function getUnreadNotifications(PDO $pdo): array {
    return getNotifications($pdo);
}

// Function to mark notification as read
function markNotificationAsRead(PDO $pdo, int $id): bool {
    $stmt = $pdo->prepare("UPDATE tb_notifikasi SET is_read = 1 WHERE id = ?");
    return $stmt->execute([$id]);
}

// Function to calculate time ago
function timeAgo(string $timestamp): string {
    $time_ago = strtotime($timestamp);
    $current_time = time();
    $time_difference = $current_time - $time_ago;
    $seconds = $time_difference;
    $minutes      = (int)round($seconds / 60 );
    $hours           = (int)round($seconds / 3600);
    $days          = (int)round($seconds / 86400);
    $weeks          = (int)round($seconds / 604800);
    $months          = (int)round($seconds / 2629440);
    $years          = (int)round($seconds / 31553280);

    if($seconds <= 60) {
        return "Baru saja";
    } else if($minutes <=60) {
        return "$minutes menit yang lalu";
    } else if($hours <=24) {
        return "$hours jam yang lalu";
    } else if($days <= 7) {
        return "$days hari yang lalu";
    } else if($weeks <= 4.3) {
        return "$weeks minggu yang lalu";
    } else if($months <=12) {
        return "$months bulan yang lalu";
    } else {
        return "$years tahun yang lalu";
    }
}

// Function to automatically update sholat attendance based on daily attendance
function updateSholatAttendance(PDO $pdo, int $id_siswa, string $tanggal, string $keterangan_absensi): void {
    $status_sholat = '';
    
    // Determine status
    if ($keterangan_absensi == 'Hadir' || $keterangan_absensi == 'Terlambat') {
        $status_sholat = 'Melaksanakan';
    } elseif (in_array($keterangan_absensi, ['Sakit', 'Izin', 'Alpa'])) {
        $status_sholat = 'Tidak Melaksanakan';
    }
    
    if ($status_sholat) {
        // Update or Insert tb_sholat (Sholat Berjamaah)
        $stmt = $pdo->prepare("SELECT id_sholat, status FROM tb_sholat WHERE id_siswa = ? AND tanggal = ?");
        $stmt->execute([$id_siswa, $tanggal]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Skip update if status is 'Berhalangan' - manual override takes precedence
        if (!$existing || $existing['status'] != 'Berhalangan') {
            if ($existing) {
                $pdo->prepare("UPDATE tb_sholat SET status = ? WHERE id_siswa = ? AND tanggal = ?")
                    ->execute([$status_sholat, $id_siswa, $tanggal]);
            } else {
                $pdo->prepare("INSERT INTO tb_sholat (id_siswa, tanggal, status) VALUES (?, ?, ?)")
                    ->execute([$id_siswa, $tanggal, $status_sholat]);
            }
        }
        
        // Update or Insert tb_sholat_dhuha (Sholat Dhuha)
        $stmt = $pdo->prepare("SELECT id_sholat, status FROM tb_sholat_dhuha WHERE id_siswa = ? AND tanggal = ?");
        $stmt->execute([$id_siswa, $tanggal]);
        $existing_dhuha = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Skip update if status is 'Berhalangan'
        if (!$existing_dhuha || $existing_dhuha['status'] != 'Berhalangan') {
            if ($existing_dhuha) {
                $pdo->prepare("UPDATE tb_sholat_dhuha SET status = ? WHERE id_siswa = ? AND tanggal = ?")
                    ->execute([$status_sholat, $id_siswa, $tanggal]);
            } else {
                $pdo->prepare("INSERT INTO tb_sholat_dhuha (id_siswa, tanggal, status) VALUES (?, ?, ?)")
                    ->execute([$id_siswa, $tanggal, $status_sholat]);
            }
        }
    }
}

/**
 * Jenjang pendidikan formal (kolom tb_guru.pendidikan).
 *
 * @return string[]
 */
function getGuruPendidikanChoices(): array {
    return ['SLTA', 'D1', 'D2', 'D3', 'S1', 'S2', 'S3'];
}

/**
 * @param mixed $raw
 * @return string|null salah satu dari getGuruPendidikanChoices() atau null jika kosong/tidak valid
 */
function normalizeGuruPendidikan($raw): ?string {
    $v = is_string($raw) ? trim($raw) : '';
    if ($v === '') {
        return null;
    }
    return in_array($v, getGuruPendidikanChoices(), true) ? $v : null;
}

/**
 * Tambah kolom pendidikan ke tb_guru jika belum ada (mis. impor Excel sebelum buka halaman admin).
 *
 * @return bool false jika gagal
 */
function ensureTbGuruPendidikanColumn(PDO $pdo): bool {
    static $checked = false;
    if ($checked) {
        return true;
    }
    $checked = true;
    try {
        $row = $pdo->query("SHOW COLUMNS FROM tb_guru LIKE 'pendidikan'")->fetch();
        if (!$row) {
            $pdo->exec("ALTER TABLE tb_guru ADD COLUMN pendidikan VARCHAR(10) DEFAULT NULL AFTER jenis_kelamin");
        }
        return true;
    } catch (PDOException $e) {
        error_log('ensureTbGuruPendidikanColumn: ' . $e->getMessage());
        return false;
    }
}

// --- Helper Functions for Security ---

// Function to sanitize user input
function sanitizeInput(string $input): string {
    $input = trim($input);
    $input = stripslashes($input);
    return $input;
}

/**
 * Memperbaiki data yang ter-encode HTML entities di database secara otomatis (silent).
 * Digunakan untuk menangani masalah tanda petik yang berubah jadi kode aneh.
 */
function silent_fix_entities(PDO $pdo): int {
    $tables = [
        'tb_siswa' => ['nama_siswa', 'tempat_lahir', 'wali'],
        'tb_peserta_didik_barung' => ['nama_peserta_didik', 'tempat_lahir'],
        'tb_guru' => ['nama_guru'],
        'tb_pembina_pramuka' => ['nama_pembina'],
        'tb_kelas' => ['nama_kelas'],
        'tb_pengguna' => ['nama_lengkap'],
        'tb_tingkat_barung' => ['nama_tingkat']
    ];

    $total_updated = 0;
    foreach ($tables as $table => $columns) {
        try {
            $stmt = $pdo->query("SELECT * FROM $table");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $pk_query = $pdo->query("SHOW KEYS FROM $table WHERE Key_name = 'PRIMARY'");
            $pk_row = $pk_query->fetch(PDO::FETCH_ASSOC);
            $pk = $pk_row['Column_name'] ?? null;
            if (!$pk) continue;

            foreach ($rows as $row) {
                $updates = [];
                $params = [];
                foreach ($columns as $col) {
                    if (isset($row[$col])) {
                        $original = (string)$row[$col];
                        $decoded = $original;
                        $limit = 5;
                        while ($limit > 0 && strpos($decoded, '&') !== false && ($tmp = htmlspecialchars_decode($decoded, ENT_QUOTES)) !== $decoded) {
                            $decoded = $tmp;
                            $limit--;
                        }
                        if ($decoded !== $original) {
                            $updates[] = "$col = ?";
                            $params[] = $decoded;
                        }
                    }
                }
                if (!empty($updates)) {
                    $params[] = $row[$pk];
                    $update_sql = "UPDATE $table SET " . implode(', ', $updates) . " WHERE $pk = ?";
                    $pdo->prepare($update_sql)->execute($params);
                    $total_updated++;
                }
            }
        } catch (Exception $e) {
            error_log("Silent fix error in $table: " . $e->getMessage());
        }
    }
    return $total_updated;
}

// Function to hash password
function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_DEFAULT);
}

// Function to verify password
function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

// Function to get teacher avatar
/** @param mixed $teacher */
function getTeacherAvatarImage($teacher, int $size = 30): string {
    $img_dir = '../uploads/';
    $base_path = dirname(__DIR__) . '/uploads/';
    
    // Check if teacher has custom photo and file exists
    if (is_array($teacher) && isset($teacher['foto']) && !empty($teacher['foto'])) {
        if (file_exists($base_path . $teacher['foto'])) {
            return '<img alt="image" src="' . $img_dir . $teacher['foto'] . '" class="rounded-circle mr-1" width="' . $size . '" style="object-fit: cover; height: ' . $size . 'px;">';
        }
    }
    
    // Fallback to initials
    $name = 'Guru';
    if (is_array($teacher) && isset($teacher['nama_guru'])) {
        $name = $teacher['nama_guru'];
    } elseif (is_array($teacher) && isset($teacher['username'])) {
        $name = $teacher['username'];
    }
    
    $initials_url = 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&background=random&color=fff&size=128';
    
    return '<img alt="image" src="' . $initials_url . '" class="rounded-circle mr-1" width="' . $size . '" style="object-fit: cover; height: ' . $size . 'px;">';
}

// Function to get user avatar
/** @param mixed $user */
function getUserAvatarImage($user, int $size = 30): string {
    $img_dir = '../assets/img/';
    $base_path = dirname(__DIR__) . '/assets/img/';
    
    if (is_array($user) && isset($user['foto']) && !empty($user['foto'])) {
        if (file_exists($base_path . $user['foto'])) {
            return '<img alt="image" src="' . $img_dir . $user['foto'] . '" class="rounded-circle mr-1" width="' . $size . '" style="object-fit: cover; height: ' . $size . 'px;">';
        }
    }
    
    // Fallback to initials
    $name = 'User';
    if (is_array($user)) {
        if (isset($user['nama']) && !empty($user['nama'])) {
            $name = $user['nama'];
        } elseif (isset($user['username']) && !empty($user['username'])) {
            $name = $user['username'];
        }
    }
    
    $initials_url = 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&background=random&color=fff&size=128';
    
    return '<img alt="image" src="' . $initials_url . '" class="rounded-circle mr-1" width="' . $size . '" style="object-fit: cover; height: ' . $size . 'px;">';
}

// Function to get all classes
function getAllKelas(PDO $pdo): array {
    $stmt = $pdo->prepare("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get students by class
function getStudentsByClass(PDO $pdo, int $kelas_id): array {
    $stmt = $pdo->prepare("SELECT s.*, k.nama_kelas FROM tb_siswa s JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_kelas = ? ORDER BY s.nama_siswa ASC");
    $stmt->execute([$kelas_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get guru name by id
function getGuruName(PDO $pdo, int $id): ?string {
    $stmt = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([$id]);
    $res = $stmt->fetchColumn();
    return $res !== false ? (string)$res : null;
}

// Function to get activity color based on action
function getActivityColor(string $action): string {
    $action = strtolower($action);
    if (strpos($action, 'tambah') !== false || strpos($action, 'add') !== false || strpos($action, 'create') !== false || strpos($action, 'insert') !== false) {
        return 'bg-success';
    } elseif (strpos($action, 'edit') !== false || strpos($action, 'update') !== false || strpos($action, 'ubah') !== false) {
        return 'bg-warning';
    } elseif (strpos($action, 'hapus') !== false || strpos($action, 'delete') !== false || strpos($action, 'remove') !== false) {
        return 'bg-danger';
    } elseif (strpos($action, 'login') !== false || strpos($action, 'masuk') !== false) {
        return 'bg-info';
    } elseif (strpos($action, 'logout') !== false || strpos($action, 'keluar') !== false) {
        return 'bg-secondary';
    } else {
        return 'bg-primary';
    }
}

// Function to get holidays from kalender pendidikan
function getHolidays(PDO $pdo, int $year, ?int $month = null): array {
    $holidays = [];
    $query = "SELECT tgl_mulai, tgl_selesai, nama_kegiatan, warna FROM tb_kalender_pendidikan WHERE warna = 'danger'";
    $params = [];

    if ($month) {
        $query .= " AND (
            (MONTH(tgl_mulai) = ? AND YEAR(tgl_mulai) = ?) OR 
            (MONTH(tgl_selesai) = ? AND YEAR(tgl_selesai) = ?) OR
            (? BETWEEN MONTH(tgl_mulai) AND MONTH(tgl_selesai) AND ? BETWEEN YEAR(tgl_mulai) AND YEAR(tgl_selesai))
        )";
        $params = [$month, $year, $month, $year, $month, $year];
    } else {
        $query .= " AND (YEAR(tgl_mulai) = ? OR YEAR(tgl_selesai) = ?)";
        $params = [$year, $year];
    }

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $start = new DateTime($row['tgl_mulai']);
            $end = new DateTime($row['tgl_selesai']);
            $end->modify('+1 day');

            $period = new DatePeriod($start, new DateInterval('P1D'), $end);
            foreach ($period as $date) {
                $holidays[$date->format('Y-m-d')] = $row['nama_kegiatan'];
            }
        }
    } catch (PDOException $e) {
        // Table might not exist yet
    }

    $weekly = getProfilHariLiburMingguanMeta($pdo);
    stripKalenderLiburMingguanYangBentrokDenganProfil($holidays, (int)$weekly['n']);

    $n_libur = (int)$weekly['n'];
    $label_libur = $weekly['nama_rekap'];

    // Tambahkan hari libur mingguan (Jumat atau Ahad) sesuai profil
    if ($month) {
        $num_days = cal_days_in_month(CAL_GREGORIAN, (int)$month, (int)$year);
        for ($d = 1; $d <= $num_days; $d++) {
            $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $day_of_week = (int)date('N', strtotime($date_str));
            if ($day_of_week === $n_libur) {
                if (!isset($holidays[$date_str])) {
                    $holidays[$date_str] = $label_libur;
                }
            }
        }
    } else {
        // Jika hanya tahun, loop seluruh bulan
        for ($m = 1; $m <= 12; $m++) {
            $num_days = cal_days_in_month(CAL_GREGORIAN, $m, (int)$year);
            for ($d = 1; $d <= $num_days; $d++) {
                $date_str = sprintf('%04d-%02d-%02d', $year, $m, $d);
                $day_of_week = (int)date('N', strtotime($date_str));
                if ($day_of_week === $n_libur) {
                    if (!isset($holidays[$date_str])) {
                        $holidays[$date_str] = $label_libur;
                    }
                }
            }
        }
    }

    return $holidays;
}

// Check if a specific date is a holiday based on Kalender Pendidikan
function isSchoolHoliday(PDO $pdo, string $date): array {
    try {
        $year = (int)date('Y', strtotime($date));
        $month = (int)date('m', strtotime($date));
        $holidays = getHolidays($pdo, $year, $month);
        if (isset($holidays[$date])) {
            return ['is_holiday' => true, 'name' => $holidays[$date]];
        }
        return ['is_holiday' => false, 'name' => ''];
    } catch (Exception $e) {
        return ['is_holiday' => false, 'name' => ''];
    }
}
// Function to get activity icon based on action
function getActivityIcon(string $action): string {
    $action = strtolower($action);
    if (strpos($action, 'tambah') !== false || strpos($action, 'add') !== false || strpos($action, 'create') !== false || strpos($action, 'insert') !== false) {
        return 'fas fa-plus';
    } elseif (strpos($action, 'edit') !== false || strpos($action, 'update') !== false || strpos($action, 'ubah') !== false) {
        return 'fas fa-pen';
    } elseif (strpos($action, 'hapus') !== false || strpos($action, 'delete') !== false || strpos($action, 'remove') !== false) {
        return 'fas fa-trash';
    } elseif (strpos($action, 'login') !== false || strpos($action, 'masuk') !== false) {
        return 'fas fa-sign-in-alt';
    } elseif (strpos($action, 'logout') !== false || strpos($action, 'keluar') !== false) {
        return 'fas fa-sign-out-alt';
    } else {
        return 'fas fa-info';
    }
}

/** Query ?nilai_mode=praktik membedakan nilai ujian teori vs ujian praktik (kolom jenis_semester). */
function nilai_ujian_is_praktik_mode(): bool {
    return isset($_GET['nilai_mode']) && $_GET['nilai_mode'] === 'praktik';
}

function nilai_ujian_jenis_semester(): string {
    return nilai_ujian_is_praktik_mode() ? 'Ujian Praktik' : 'Ujian';
}

function nilai_ujian_page_title(): string {
    return nilai_ujian_is_praktik_mode() ? 'Nilai Ujian Praktik' : 'Nilai Ujian';
}

/**
 * Nilai semester untuk tampilan siswa: MAX(nilai_asli, nilai_remidi) — ambil yang tertinggi;
 * jika asli lebih tinggi dari remidi tetap asli. Ujian Praktik: hanya nilai_asli (remidi tidak dipakai).
 */
function nilai_tampilan_siswa_semester(?float $nilai_asli, ?float $nilai_remidi, bool $abaikan_remidi = false): float {
    $a = (float)($nilai_asli ?? 0);
    if ($abaikan_remidi) {
        return $a > 0 ? $a : 0.0;
    }
    $r = (float)($nilai_remidi ?? 0);
    $m = max($a, $r);
    return $m > 0 ? $m : 0.0;
}

function nilai_semester_allowed_jenis_values(): array {
    return ['UTS', 'UAS', 'PAT', 'Pra Ujian', 'Ujian', 'Ujian Praktik'];
}

/** Validasi nilai jenis_semester dari permintaan eksternal (GET/POST). */
function normalize_jenis_semester_param(?string $jenis): ?string {
    if (!is_string($jenis) || $jenis === '') {
        return null;
    }
    return in_array($jenis, nilai_semester_allowed_jenis_values(), true) ? $jenis : null;
}

/**
 * Pastikan ENUM tb_nilai_semester mendukung 'Ujian Praktik' (sekali per request, aman dipanggil berulang).
 */
function ensure_nilai_semester_enum_ujian_praktik(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    try {
        $r = $pdo->query("SHOW COLUMNS FROM tb_nilai_semester LIKE 'jenis_semester'");
        $row = $r ? $r->fetch(PDO::FETCH_ASSOC) : null;
        $type = isset($row['Type']) ? (string)$row['Type'] : '';
        if ($type !== '' && stripos($type, 'Ujian Praktik') === false) {
            $pdo->exec("ALTER TABLE tb_nilai_semester MODIFY COLUMN jenis_semester ENUM('UTS','UAS','PAT','Pra Ujian','Ujian','Ujian Praktik') NOT NULL");
        }
    } catch (Throwable $e) {
        // Tabel belum ada atau tidak ada hak ALTER — abaikan
    }
}

function ensure_nilai_harian_header_minmax(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    try {
        $rMin = $pdo->query("SHOW COLUMNS FROM tb_nilai_harian_header LIKE 'nilai_min_target'");
        $hasMin = $rMin ? (bool)$rMin->fetch(PDO::FETCH_ASSOC) : false;
        if (!$hasMin) {
            $pdo->exec("ALTER TABLE tb_nilai_harian_header ADD COLUMN nilai_min_target FLOAT NULL AFTER materi");
        }
        $rMax = $pdo->query("SHOW COLUMNS FROM tb_nilai_harian_header LIKE 'nilai_max_target'");
        $hasMax = $rMax ? (bool)$rMax->fetch(PDO::FETCH_ASSOC) : false;
        if (!$hasMax) {
            $pdo->exec("ALTER TABLE tb_nilai_harian_header ADD COLUMN nilai_max_target FLOAT NULL AFTER nilai_min_target");
        }
    } catch (Throwable $e) {
    }
}

function ensure_nilai_kokurikuler_header_minmax(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    try {
        $rMin = $pdo->query("SHOW COLUMNS FROM tb_nilai_kokurikuler_header LIKE 'nilai_min_target'");
        $hasMin = $rMin ? (bool)$rMin->fetch(PDO::FETCH_ASSOC) : false;
        if (!$hasMin) {
            $pdo->exec("ALTER TABLE tb_nilai_kokurikuler_header ADD COLUMN nilai_min_target FLOAT NULL AFTER tgl_kegiatan");
        }
        $rMax = $pdo->query("SHOW COLUMNS FROM tb_nilai_kokurikuler_header LIKE 'nilai_max_target'");
        $hasMax = $rMax ? (bool)$rMax->fetch(PDO::FETCH_ASSOC) : false;
        if (!$hasMax) {
            $pdo->exec("ALTER TABLE tb_nilai_kokurikuler_header ADD COLUMN nilai_max_target FLOAT NULL AFTER nilai_min_target");
        }
    } catch (Throwable $e) {
    }
}

function ensure_nilai_semester_setting_minmax(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_nilai_semester_setting (
                id_setting INT NOT NULL AUTO_INCREMENT,
                id_kelas INT NOT NULL,
                id_mapel INT NOT NULL,
                jenis_semester VARCHAR(20) NOT NULL,
                tahun_ajaran VARCHAR(20) NOT NULL,
                semester VARCHAR(20) NOT NULL,
                nilai_min_target FLOAT NULL,
                nilai_max_target FLOAT NULL,
                updated_by INT NULL,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id_setting),
                UNIQUE KEY uniq_setting (id_kelas, id_mapel, jenis_semester, tahun_ajaran, semester),
                KEY idx_kelas (id_kelas),
                KEY idx_mapel (id_mapel)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
    }
}

function get_nilai_semester_setting_minmax(PDO $pdo, int $id_kelas, int $id_mapel, string $jenis_semester, string $tahun_ajaran, string $semester): array {
    ensure_nilai_semester_setting_minmax($pdo);
    try {
        $stmt = $pdo->prepare("
            SELECT nilai_min_target, nilai_max_target
            FROM tb_nilai_semester_setting
            WHERE id_kelas = ? AND id_mapel = ? AND jenis_semester = ? AND tahun_ajaran = ? AND semester = ?
            LIMIT 1
        ");
        $stmt->execute([$id_kelas, $id_mapel, $jenis_semester, $tahun_ajaran, $semester]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['nilai_min_target' => null, 'nilai_max_target' => null];
        }
        return [
            'nilai_min_target' => isset($row['nilai_min_target']) ? ($row['nilai_min_target'] !== null ? (float)$row['nilai_min_target'] : null) : null,
            'nilai_max_target' => isset($row['nilai_max_target']) ? ($row['nilai_max_target'] !== null ? (float)$row['nilai_max_target'] : null) : null,
        ];
    } catch (Throwable $e) {
        return ['nilai_min_target' => null, 'nilai_max_target' => null];
    }
}

function upsert_nilai_semester_setting_minmax(PDO $pdo, int $id_kelas, int $id_mapel, string $jenis_semester, string $tahun_ajaran, string $semester, ?float $min_target, ?float $max_target, ?int $updated_by): void {
    ensure_nilai_semester_setting_minmax($pdo);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO tb_nilai_semester_setting
                (id_kelas, id_mapel, jenis_semester, tahun_ajaran, semester, nilai_min_target, nilai_max_target, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                nilai_min_target = VALUES(nilai_min_target),
                nilai_max_target = VALUES(nilai_max_target),
                updated_by = VALUES(updated_by)
        ");
        $stmt->execute([$id_kelas, $id_mapel, $jenis_semester, $tahun_ajaran, $semester, $min_target, $max_target, $updated_by]);
    } catch (Throwable $e) {
    }
}
