<?php
// Set default timezone to Asia/Jakarta
date_default_timezone_set('Asia/Jakarta');

if (session_status() == PHP_SESSION_NONE) {
    // Determine session name based on directory or explicit request
    $script_path = $_SERVER['PHP_SELF'];
    $session_name = 'SIS_LOGIN'; // Default for root/login

    // Check for explicit session type request
    if (isset($_REQUEST['session_type'])) {
        $type = $_REQUEST['session_type'];
        if ($type == 'admin') $session_name = 'SIS_ADMIN';
        elseif ($type == 'guru') $session_name = 'SIS_GURU';
        elseif ($type == 'siswa') $session_name = 'SIS_SISWA';
        elseif ($type == 'wali') $session_name = 'SIS_WALI';
        elseif ($type == 'tata_usaha') $session_name = 'SIS_TU';
        elseif ($type == 'kepala_madrasah' || $type == 'kepala') $session_name = 'SIS_KEPALA';
    } 
    // Check directory context
    elseif (strpos($script_path, '/admin/') !== false) {
        $session_name = 'SIS_ADMIN';
        
        // Check LAST_ACTIVE_SESSION
        if (isset($_COOKIE['LAST_ACTIVE_SESSION']) && in_array($_COOKIE['LAST_ACTIVE_SESSION'], ['SIS_TU', 'SIS_KEPALA', 'SIS_WALI', 'SIS_GURU'])) {
             $session_name = $_COOKIE['LAST_ACTIVE_SESSION'];
        }
        // Fallback for TU and Kepala accessing Admin files
        elseif (!isset($_COOKIE['SIS_ADMIN'])) {
            if (isset($_COOKIE['SIS_TU'])) {
                $session_name = 'SIS_TU';
            } elseif (isset($_COOKIE['SIS_KEPALA'])) {
                $session_name = 'SIS_KEPALA';
            } elseif (isset($_COOKIE['SIS_WALI'])) {
                $session_name = 'SIS_WALI';
            } elseif (isset($_COOKIE['SIS_GURU'])) {
                $session_name = 'SIS_GURU';
            }
        }
    } elseif (strpos($script_path, '/guru/') !== false) {
        $session_name = 'SIS_GURU';
        
        // Check LAST_ACTIVE_SESSION
        if (isset($_COOKIE['LAST_ACTIVE_SESSION']) && in_array($_COOKIE['LAST_ACTIVE_SESSION'], ['SIS_WALI', 'SIS_ADMIN', 'SIS_TU', 'SIS_KEPALA'])) {
             $session_name = $_COOKIE['LAST_ACTIVE_SESSION'];
        }
        // Fallback for others accessing Guru files
        elseif (!isset($_COOKIE['SIS_GURU'])) {
            if (isset($_COOKIE['SIS_WALI'])) {
                $session_name = 'SIS_WALI';
            } elseif (isset($_COOKIE['SIS_ADMIN'])) {
                $session_name = 'SIS_ADMIN';
            } elseif (isset($_COOKIE['SIS_TU'])) {
                $session_name = 'SIS_TU';
            } elseif (isset($_COOKIE['SIS_KEPALA'])) {
                $session_name = 'SIS_KEPALA';
            }
        }
    } elseif (strpos($script_path, '/siswa/') !== false) {
        $session_name = 'SIS_SISWA';
    } elseif (strpos($script_path, '/wali/') !== false) {
        $session_name = 'SIS_WALI';
    } elseif (strpos($script_path, '/tata_usaha/') !== false) {
        $session_name = 'SIS_TU';
    } elseif (strpos($script_path, '/kepala/') !== false) {
        $session_name = 'SIS_KEPALA';
    }
    
    // logout.php di root — tanpa nama sesi yang benar $_SESSION kosong dan log jadi "Unknown session"
    if (basename($_SERVER['SCRIPT_NAME']) == 'logout.php') {
        if (isset($_GET['level'])) {
            $lvl = $_GET['level'];
            switch ($lvl) {
                case 'admin': $session_name = 'SIS_ADMIN'; break;
                case 'guru': $session_name = 'SIS_GURU'; break;
                case 'siswa': $session_name = 'SIS_SISWA'; break;
                case 'wali': $session_name = 'SIS_WALI'; break;
                case 'tata_usaha': $session_name = 'SIS_TU'; break;
                case 'kepala_madrasah':
                case 'kepala': $session_name = 'SIS_KEPALA'; break;
            }
        } elseif (!empty($_COOKIE['LAST_ACTIVE_SESSION'])) {
            $lac = $_COOKIE['LAST_ACTIVE_SESSION'];
            $allowed = ['SIS_ADMIN', 'SIS_GURU', 'SIS_SISWA', 'SIS_WALI', 'SIS_TU', 'SIS_KEPALA', 'SIS_LOGIN'];
            if (in_array($lac, $allowed, true)) {
                $session_name = $lac;
            }
        }
    }

    // --- SESSION CONFIGURATION ---
    // Store session files outside the project folder to avoid untracked session files in repo.
    // (Requested: no need to copy/manage sessions folder.)
    $tmp = sys_get_temp_dir();
    if (is_string($tmp) && $tmp !== '') {
        session_save_path($tmp);
    }

    // Set session lifetime to 24 hours (86400 seconds)
    // This ensures the server keeps the session file for at least 24h
    ini_set('session.gc_maxlifetime', 86400);
    
    // Set cookie lifetime to 0 (expires on browser close) OR match maxlifetime
    // Using 0 is standard for "session" cookies, but if user wants persistence against random closures:
    // session_set_cookie_params(86400, '/'); 
    // User asked for "idle" behavior, so standard session cookie is best, 
    // but with LONG server-side lifetime.
    // However, to be safe and avoid "30 min" issues, let's explicitly set parameters.
    session_set_cookie_params([
        'lifetime' => 86400, // 24 hours cookie
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']), // Only secure if HTTPS
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_name($session_name);
    session_start();

    // Jika sesi yang dipilih tidak punya user, coba fallback ke LAST_ACTIVE_SESSION
    if (!isset($_SESSION['user_id']) && isset($_COOKIE['LAST_ACTIVE_SESSION']) && $_COOKIE['LAST_ACTIVE_SESSION'] !== $session_name) {
        session_write_close();
        $fallback_session = $_COOKIE['LAST_ACTIVE_SESSION'];
        session_name($fallback_session);
        session_start();
        $session_name = $fallback_session;
    }
    
    // Update sticky session jika sudah login
    if (isset($_SESSION['user_id'])) {
        setcookie('LAST_ACTIVE_SESSION', $session_name, time() + 86400 * 30, '/');
    }
}

// Function to switch session context (used in login.php)
function startUserSession($level) {
    if (session_status() == PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    
    $session_name = 'SIS_LOGIN';
    switch ($level) {
        case 'admin': $session_name = 'SIS_ADMIN'; break;
        case 'guru': $session_name = 'SIS_GURU'; break;
        case 'siswa': $session_name = 'SIS_SISWA'; break;
        case 'wali': $session_name = 'SIS_WALI'; break;
        case 'tata_usaha': $session_name = 'SIS_TU'; break;
        case 'kepala_madrasah': 
        case 'kepala': $session_name = 'SIS_KEPALA'; break;
    }
    
    session_name($session_name);
    session_set_cookie_params(0, '/');
    session_start();
    session_regenerate_id(true);
    
    // Update sticky session
    setcookie('LAST_ACTIVE_SESSION', $session_name, time() + 86400 * 30, '/');
}

// Function to redirect user
function redirect($page) {
    header("Location: $page");
    exit();
}

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Function to get user level
function getUserLevel() {
    $level = isset($_SESSION['level']) ? $_SESSION['level'] : '';
    if ($level === 'kepala') {
        return 'kepala_madrasah';
    }
    if ($level === 'tu') {
        return 'tata_usaha';
    }
    return $level;
}

// Function to check user authorization
function isAuthorized($allowed_levels = []) {
    if (!isLoggedIn()) {
        return false;
    }
    
    if (empty($allowed_levels)) {
        return true;
    }

    $current_level = getUserLevel();
    $normalized_allowed_levels = [];
    foreach ($allowed_levels as $level) {
        if ($level === 'kepala') {
            $normalized_allowed_levels[] = 'kepala_madrasah';
        } elseif ($level === 'tu') {
            $normalized_allowed_levels[] = 'tata_usaha';
        } else {
            $normalized_allowed_levels[] = $level;
        }
    }

    return in_array($current_level, $normalized_allowed_levels, true);
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
function getSchoolProfile($pdo) {
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
function getFilteredSubjects($pdo, $semuaJenis = false) {
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

function getLoggedInTeacherId($pdo) {
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

function getTeacherAccessibleClasses($pdo, $id_guru, $only_grade_6 = false) {
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
function formatDate($date) {
    $date = new DateTime($date);
    return $date->format('d M Y');
}

// Function to get current date in Indonesian format
function getCurrentDateIndonesia() {
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
function getProfilHariLiburMingguanMeta($pdo) {
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
function isHoliday($pdo, $date) {
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
function formatDateIndonesia($date_string) {
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
function formatSemesterLabelForExport($semester) {
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
    function sort_all_menu_items(&$items) {
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
function logActivity($pdo, $username, $action, $description = '') {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $pdo->prepare("INSERT INTO tb_activity_log (username, action, description, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())");
    return $stmt->execute([$username, $action, $description, $ip_address]);
}

// Function to create notification
function createNotification($pdo, $message, $link, $type = 'info') {
    // Ignoring $type as column doesn't exist in current schema
    $stmt = $pdo->prepare("INSERT INTO tb_notifikasi (message, link, created_at) VALUES (?, ?, NOW())");
    return $stmt->execute([$message, $link]);
}

// Function to get system notifications (auto delete > 24 hours)
function getNotifications($pdo) {
    // Delete notifications older than 24 hours
    $cleanup_stmt = $pdo->prepare("DELETE FROM tb_notifikasi WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $cleanup_stmt->execute();

    // Get all notifications from last 24 hours
    $stmt = $pdo->prepare("SELECT * FROM tb_notifikasi ORDER BY created_at DESC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get unread notifications (Deprecated, alias to getNotifications)
function getUnreadNotifications($pdo) {
    return getNotifications($pdo);
}

// Function to mark notification as read
function markNotificationAsRead($pdo, $id) {
    $stmt = $pdo->prepare("UPDATE tb_notifikasi SET is_read = 1 WHERE id = ?");
    return $stmt->execute([$id]);
}

// Function to calculate time ago
function timeAgo($timestamp) {
    $time_ago = strtotime($timestamp);
    $current_time = time();
    $time_difference = $current_time - $time_ago;
    $seconds = $time_difference;
    $minutes      = round($seconds / 60 );
    $hours           = round($seconds / 3600);
    $days          = round($seconds / 86400);
    $weeks          = round($seconds / 604800);
    $months          = round($seconds / 2629440);
    $years          = round($seconds / 31553280);

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
function updateSholatAttendance($pdo, $id_siswa, $tanggal, $keterangan_absensi) {
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
function getGuruPendidikanChoices() {
    return ['SLTA', 'D1', 'D2', 'D3', 'S1', 'S2', 'S3'];
}

/**
 * @param mixed $raw
 * @return string|null salah satu dari getGuruPendidikanChoices() atau null jika kosong/tidak valid
 */
function normalizeGuruPendidikan($raw) {
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
function ensureTbGuruPendidikanColumn($pdo) {
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
function sanitizeInput($input) {
    $input = trim($input);
    $input = stripslashes($input);
    $input = htmlspecialchars($input);
    return $input;
}

// Function to hash password
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// Function to verify password
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Function to get teacher avatar
function getTeacherAvatarImage($teacher, $size = 30) {
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
function getUserAvatarImage($user, $size = 30) {
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
function getAllKelas($pdo) {
    $stmt = $pdo->prepare("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get students by class
function getStudentsByClass($pdo, $kelas_id) {
    $stmt = $pdo->prepare("SELECT s.*, k.nama_kelas FROM tb_siswa s JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_kelas = ? ORDER BY s.nama_siswa ASC");
    $stmt->execute([$kelas_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get guru name by id
function getGuruName($pdo, $id) {
    $stmt = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([$id]);
    return $stmt->fetchColumn();
}

// Function to get activity color based on action
function getActivityColor($action) {
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
function getHolidays($pdo, $year, $month = null) {
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
function isSchoolHoliday($pdo, $date) {
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
function getActivityIcon($action) {
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

function nilai_semester_allowed_jenis_values(): array {
    return ['UTS', 'UAS', 'PAT', 'Pra Ujian', 'Ujian', 'Ujian Praktik'];
}

/** Validasi nilai jenis_semester dari permintaan eksternal (GET/POST). */
function normalize_jenis_semester_param($jenis): ?string {
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
