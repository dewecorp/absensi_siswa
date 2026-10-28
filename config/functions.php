<?php
// Start output buffering with Indonesian date translation.
// PENTING: callback ini hanya untuk output HTML. Unduhan biner
// (XLSX/PDF/DOCX/CSV/gambar/ZIP) wajib dilewati agar file tidak corrupt.
ob_start(function($buffer) {
    // Deteksi biner: ZIP/XLSX (PK..), PDF (%PDF), DOC lama (D0CF11E0),
    // gambar umum, gzip, dan RAR — kembalikan apa adanya.
    if ($buffer === '' || !is_string($buffer)) {
        return $buffer;
    }
    $head = substr($buffer, 0, 8);
    if (strncmp($head, "PK\x03\x04", 4) === 0
        || strncmp($head, '%PDF', 4) === 0
        || strncmp($head, "\xD0\xCF\x11\xE0", 4) === 0
        || strncmp($head, "\xFF\xD8\xFF", 3) === 0
        || strncmp($head, "\x89PNG", 4) === 0
        || strncmp($head, "GIF8", 4) === 0
        || strncmp($head, "\x1F\x8B", 2) === 0
        || strncmp($head, "Rar!", 4) === 0
    ) {
        return $buffer;
    }
    // Lewati juga bila header unduhan file sudah dikirim.
    if (!empty($GLOBALS['SIMAD_SKIP_DATE_TRANSLATE'])) {
        return $buffer;
    }
    if (function_exists('headers_list')) {
        foreach (headers_list() as $h) {
            $hl = strtolower($h);
            if (strpos($hl, 'content-disposition: attachment') !== false
                || (strpos($hl, 'content-type:') !== false
                    && preg_match('#(spreadsheet|excel|ms-excel|officedocument|msword|wordprocessingml|pdf|csv|octet-stream|zip)#', $hl))) {
                return $buffer;
            }
        }
    }
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

// Keamanan: header HTTP dasar
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 1; mode=block');
}

// Harden session cookie
if (session_status() !== PHP_SESSION_ACTIVE) {
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_samesite', 'Lax');
    @ini_set('session.use_strict_mode', '1');
}

if (!defined('ALLOW_LEGACY_DEFAULT_LOGIN')) {
    define('ALLOW_LEGACY_DEFAULT_LOGIN', false);
}
if (!defined('DEFAULT_GURU_PASSWORD')) {
    define('DEFAULT_GURU_PASSWORD', 'sultanfattah26');
}
if (!defined('STUDENT_RANDOM_PASSWORD_LENGTH')) {
    define('STUDENT_RANDOM_PASSWORD_LENGTH', 6);
}

function appRuntimeSettingsPath(): string {
    return __DIR__ . '/runtime_settings.php';
}

function appLoadRuntimeSettings(): array {
    $path = appRuntimeSettingsPath();
    if (!is_file($path)) {
        return [];
    }

    $settings = include $path;
    return is_array($settings) ? $settings : [];
}

function appSaveRuntimeSettings(array $settings): bool {
    $path = appRuntimeSettingsPath();
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        return false;
    }

    $allowed = [
        'self_update_enabled' => !empty($settings['self_update_enabled']),
    ];
    $php = "<?php\nreturn " . var_export($allowed, true) . ";\n";
    return @file_put_contents($path, $php, LOCK_EX) !== false;
}

function appRuntimeBool(string $key, bool $default = false): bool {
    $settings = appLoadRuntimeSettings();
    if (array_key_exists($key, $settings)) {
        return filter_var($settings[$key], FILTER_VALIDATE_BOOLEAN);
    }
    return $default;
}

function appEnvBool(string $key, bool $default = false): bool {
    $value = getenv($key);
    if (($value === false || $value === '') && isset($_SERVER[$key])) {
        $value = $_SERVER[$key];
    }
    if (($value === false || $value === '') && isset($_ENV[$key])) {
        $value = $_ENV[$key];
    }
    if ($value === false || $value === '') {
        return $default;
    }
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

if (!defined('APP_SELF_UPDATE_ENABLED')) {
    define('APP_SELF_UPDATE_ENABLED', appRuntimeBool('self_update_enabled', appEnvBool('APP_SELF_UPDATE_ENABLED', false)));
}
function sendSecurityHeaders(): void {
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
}

function appCleanQueryString(string $query): string {
    if ($query === '') {
        return '';
    }

    parse_str($query, $params);
    unset($params['simad'], $params['_simad']);
    return http_build_query($params);
}

function app_base_path(): string {
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $knownDirs = ['admin', 'guru', 'wali', 'kepala', 'tata_usaha', 'bendahara', 'siswa', 'ajax', 'api', 'config', 'pembayaran'];

    foreach ($knownDirs as $dir) {
        $needle = '/' . $dir . '/';
        $pos = strpos($scriptName, $needle);
        if ($pos !== false) {
            return rtrim(substr($scriptName, 0, $pos), '/');
        }
    }

    $base = rtrim(str_replace('/index.php', '', str_replace('/login.php', '', $scriptName)), '/');
    return $base === '/' ? '' : $base;
}

function app_url(string $path): string {
    $path = trim($path);
    if ($path === '' || $path === '#') {
        return $path;
    }
    if (preg_match('#^(https?:)?//#i', $path) || strpos($path, 'javascript:') === 0 || strpos($path, 'mailto:') === 0 || strpos($path, 'tel:') === 0) {
        return $path;
    }

    $originalPath = $path;
    $hadParentTraversal = strpos($path, '../') === 0;
    $hadLeadingSlash = strpos($path, '/') === 0 || preg_match('#^[A-Z]:/#i', $path);
    $fragment = '';
    $hashPos = strpos($path, '#');
    if ($hashPos !== false) {
        $fragment = substr($path, $hashPos);
        $path = substr($path, 0, $hashPos);
    }

    $query = '';
    $queryPos = strpos($path, '?');
    if ($queryPos !== false) {
        $query = substr($path, $queryPos);
        $path = substr($path, 0, $queryPos);
    }

    if ($query !== '') {
        $cleanQuery = appCleanQueryString(ltrim($query, '?'));
        $query = $cleanQuery !== '' ? '?' . $cleanQuery : '';
    }

    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#^[A-Z]:/.*/absen_siswa/#i', '', $path);
    while (strpos($path, '../') === 0) {
        $path = substr($path, 3);
    }
    $path = ltrim($path, '/');

    $knownDirs = ['admin', 'guru', 'wali', 'kepala', 'tata_usaha', 'bendahara', 'siswa', 'ajax', 'api', 'config'];
    $firstSegment = explode('/', $path, 2)[0] ?? '';
    if (!$hadParentTraversal && !$hadLeadingSlash && $path !== '' && !in_array($firstSegment, $knownDirs, true)) {
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        foreach ($knownDirs as $dir) {
            if (strpos($scriptName, '/' . $dir . '/') !== false) {
                $path = $dir . '/' . $path;
                break;
            }
        }
    }

    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }

    $cleanPath = implode('/', $segments);
    $base = app_base_path();
    return ($base !== '' ? $base : '') . '/' . $cleanPath . $query . $fragment;
}

sendSecurityHeaders();

if (session_status() == PHP_SESSION_NONE) {
    // --- SESSION CONFIGURATION PHP 8+ ---
    
    // Tentukan folder sesi lokal untuk menghindari limitasi server
    $session_dir = dirname(__DIR__) . '/sessions';
    if (!is_dir($session_dir)) {
        @mkdir($session_dir, 0755, true);
    }

    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_samesite', 'Lax');
    if ($is_https) {
        @ini_set('session.cookie_secure', '1');
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

    $session_lifetime = 604800; // 7 hari
    @ini_set('session.gc_maxlifetime', $session_lifetime);
    @ini_set('session.cookie_lifetime', $session_lifetime);

    if (PHP_VERSION_ID >= 70300) {
        @session_set_cookie_params([
            'lifetime' => $session_lifetime,
            'path' => '/',
            'secure' => $is_https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
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

function appCsrfToken(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function appVerifyCsrfToken(?string $token): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    return is_string($token) && isset($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $token);
}

// Function to redirect user
function redirect(string $page): void {
    $target = app_url($page);
    if (!headers_sent()) {
        header("Location: $target");
        exit();
    } else {
        echo "<script>window.location.href=" . json_encode($target) . ";</script>";
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

function getCurrentGuruId(PDO $pdo): int {
    if (isset($_SESSION['id_guru']) && (int)$_SESSION['id_guru'] > 0) {
        return (int)$_SESSION['id_guru'];
    }
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return 0;
    }

    $loginSource = $_SESSION['login_source'] ?? '';
    if ($loginSource === 'tb_guru' || ($_SESSION['level'] ?? '') === 'guru' || ($_SESSION['level'] ?? '') === 'wali') {
        return $userId;
    }

    if ($loginSource === 'tb_pengguna') {
        try {
            if (!dbColumnExists($pdo, 'tb_pengguna', 'id_guru')) {
                return 0;
            }
            $stmt = $pdo->prepare('SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ? LIMIT 1');
            $stmt->execute([$userId]);
            return (int)($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    return 0;
}

function isBendahara(PDO $pdo): bool {
    try {
        if (!dbTableExists($pdo, 'tb_profil_madrasah') || !dbColumnExists($pdo, 'tb_profil_madrasah', 'id_bendahara')) {
            return false;
        }

        $idGuru = getCurrentGuruId($pdo);
        if ($idGuru <= 0) {
            return false;
        }

        $stmt = $pdo->query('SELECT id_bendahara FROM tb_profil_madrasah ORDER BY id ASC LIMIT 1');
        $idBendahara = (int)($stmt ? ($stmt->fetchColumn() ?: 0) : 0);
        return $idBendahara > 0 && $idBendahara === $idGuru;
    } catch (Throwable $e) {
        return false;
    }
}

function isActiveStudentSession(): bool {
    if (($_SESSION['login_source'] ?? '') !== 'tb_siswa' || getUserLevel() !== 'siswa') {
        return true;
    }

    $idSiswa = (int)($_SESSION['user_id'] ?? 0);
    if ($idSiswa <= 0) {
        return false;
    }

    global $pdo;
    if (!$pdo instanceof PDO) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('SELECT id_kelas FROM tb_siswa WHERE id_siswa = ? AND id_kelas IS NOT NULL LIMIT 1');
        $stmt->execute([$idSiswa]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

// Function to check user authorization
function isAuthorized(array $allowed_levels = []): bool {
    if (!isLoggedIn()) {
        return false;
    }

    // Idle timeout: lebih dari 2 jam tanpa aktivitas -> logout otomatis
    $idle_limit = 2 * 3600; // 2 jam
    $now_ts = time();
    if (isset($_SESSION['last_activity']) && ($now_ts - (int)$_SESSION['last_activity'] > $idle_limit)) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }
        return false;
    }
    $_SESSION['last_activity'] = $now_ts;

    if (!isActiveStudentSession()) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }
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

/** Tahun ajaran aktif yang benar-benar disimpan di Pengaturan. */
function getTahunAjaranAktifDariPengaturan(PDO $pdo): string {
    try {
        $stmt = $pdo->query("SELECT tahun_ajaran FROM tb_profil_madrasah ORDER BY id ASC LIMIT 1");
        return trim((string)($stmt ? $stmt->fetchColumn() : ''));
    } catch (Throwable $e) {
        return '';
    }
}

/** Simpan snapshot siswa baru tahun ajaran aktif sekali saja, agar tidak berubah saat kelas I naik. */
function ensureSiswaBaruSnapshotForActiveYear(PDO $pdo, bool $forceRefresh = false): void {
    $tahunAjaran = getTahunAjaranAktifDariPengaturan($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_siswa_baru (
        id INT PRIMARY KEY AUTO_INCREMENT,
        tahun_ajaran VARCHAR(20) NOT NULL UNIQUE,
        jumlah_laki INT DEFAULT 0,
        jumlah_perempuan INT DEFAULT 0,
        total INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    if (!isTahunAjaranFormatValid($tahunAjaran)) {
        return;
    }

    if (!$forceRefresh) {
        $stmt = $pdo->prepare("SELECT id FROM tb_siswa_baru WHERE tahun_ajaran = ? LIMIT 1");
        $stmt->execute([$tahunAjaran]);
        if ($stmt->fetchColumn()) {
            return;
        }
    }

    $stmt = $pdo->prepare("
        SELECT id_kelas
        FROM tb_kelas
        WHERE TRIM(UPPER(nama_kelas)) IN ('I', '1', 'KELAS I', 'KELAS 1')
           OR UPPER(nama_kelas) REGEXP '(^|[^A-Z0-9])(I|1)([^A-Z0-9]|$)'
        ORDER BY
            CASE
                WHEN TRIM(UPPER(nama_kelas)) IN ('I', '1') THEN 0
                WHEN TRIM(UPPER(nama_kelas)) IN ('KELAS I', 'KELAS 1') THEN 1
                ELSE 2
            END,
            nama_kelas ASC
        LIMIT 1
    ");
    $stmt->execute();
    $kelas1Id = (int)($stmt->fetchColumn() ?: 0);

    $currentData = [
        'jumlah_laki' => 0,
        'jumlah_perempuan' => 0,
        'total' => 0,
    ];
    if ($kelas1Id > 0) {
        $stmt = $pdo->prepare("SELECT
            COUNT(CASE WHEN jenis_kelamin = 'L' THEN 1 END) as jumlah_laki,
            COUNT(CASE WHEN jenis_kelamin = 'P' THEN 1 END) as jumlah_perempuan,
            COUNT(*) as total
            FROM tb_siswa WHERE id_kelas = ?");
        $stmt->execute([$kelas1Id]);
        $currentData = $stmt->fetch(PDO::FETCH_ASSOC) ?: $currentData;
    }

    $stmt = $pdo->prepare($forceRefresh
        ? "REPLACE INTO tb_siswa_baru (tahun_ajaran, jumlah_laki, jumlah_perempuan, total) VALUES (?, ?, ?, ?)"
        : "INSERT IGNORE INTO tb_siswa_baru (tahun_ajaran, jumlah_laki, jumlah_perempuan, total) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        $tahunAjaran,
        $currentData['jumlah_laki'] ?? 0,
        $currentData['jumlah_perempuan'] ?? 0,
        $currentData['total'] ?? 0,
    ]);
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

/** Kumpulkan string TA dari kolom tanggal (kehadiran, jurnal) untuk dropdown / arsip. */
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
        'id_kepala' => null,
        'kepala_madrasah' => '',
        'nama_kepala' => '',
        'nip_kepala' => '',
        'id_bendahara' => null,
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
 * digabung TA dari profil/nilai/kehadiran/jurnal agar bisa kembali melihat arsip.
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

    $sql .= ' ORDER BY 
        CASE WHEN jenis_mapel = \'Tambahan\' THEN 1 ELSE 0 END,
        LOWER(nama_mapel) ASC';

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

/**
 * Mengambil daftar kelas yang diajar oleh guru yang login
 * Menggabungkan tb_jadwal_pelajaran (jenis = 'Reguler'), tb_guru.mengajar, dan wali_kelas
 */
function getGuruTaughtClasses(PDO $pdo, int $guru_id): array {
    if ($guru_id <= 0) {
        return $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    $classes = [];
    $seen = [];

    // 1. Dari jadwal pelajaran (hanya jenis Reguler)
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT k.id_kelas, k.nama_kelas
            FROM tb_jadwal_pelajaran j
            JOIN tb_kelas k ON k.id_kelas = j.kelas_id
            WHERE j.guru_id = ?
              AND j.jenis = 'Reguler'
            ORDER BY k.nama_kelas ASC
        ");
        $stmt->execute([$guru_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $id = (int)$c['id_kelas'];
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $classes[] = $c;
            }
        }
    } catch (Throwable $e) {}

    // 2. Dari tb_guru.mengajar & wali kelas
    try {
        $stmtG = $pdo->prepare("SELECT nama_guru, mengajar FROM tb_guru WHERE id_guru = ?");
        $stmtG->execute([$guru_id]);
        $g = $stmtG->fetch(PDO::FETCH_ASSOC);
        if ($g && !empty($g['mengajar'])) {
            $all_classes = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
            $list = json_decode((string)$g['mengajar'], true);
            if (!is_array($list)) {
                $list = array_map('trim', explode(',', (string)$g['mengajar']));
            }
            foreach ($list as $item) {
                foreach ($all_classes as $k) {
                    if ((is_numeric($item) && (int)$item === (int)$k['id_kelas']) ||
                        (string)$k['id_kelas'] === (string)$item ||
                        strcasecmp((string)$k['nama_kelas'], (string)$item) === 0) {
                        $id = (int)$k['id_kelas'];
                        if (!isset($seen[$id])) {
                            $seen[$id] = true;
                            $classes[] = $k;
                        }
                    }
                }
            }
        }

        if ($g && !empty($g['nama_guru'])) {
            $stW = $pdo->prepare("SELECT id_kelas, nama_kelas FROM tb_kelas WHERE wali_kelas = ?");
            $stW->execute([$g['nama_guru']]);
            foreach ($stW->fetchAll(PDO::FETCH_ASSOC) as $w) {
                $id = (int)$w['id_kelas'];
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $classes[] = $w;
                }
            }
        }
    } catch (Throwable $e) {}

    if (empty($classes)) {
        $classes = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        usort($classes, static function($a, $b) {
            return strcasecmp((string)($a['nama_kelas'] ?? ''), (string)($b['nama_kelas'] ?? ''));
        });
    }

    return $classes;
}

/**
 * Mengambil daftar mata pelajaran yang diajar oleh guru yang login
 * Dari tb_jadwal_pelajaran KHUSUS jadwal Reguler (bukan Ramadhan), disaring mapel non-akademik
 */
function getGuruTaughtMapels(PDO $pdo, int $guru_id): array {
    if ($guru_id <= 0) {
        return getFilteredSubjects($pdo);
    }

    $mapels = [];
    $seen = [];

    // Dari tb_jadwal_pelajaran KHUSUS jenis = 'Reguler'
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT m.id_mapel, m.nama_mapel
            FROM tb_jadwal_pelajaran j
            JOIN tb_mata_pelajaran m ON m.id_mapel = j.mapel_id
            WHERE j.guru_id = ?
              AND j.jenis = 'Reguler'
              AND (m.jenis_mapel IS NULL OR m.jenis_mapel = 'Akademik')
              AND m.nama_mapel NOT LIKE '%Asmaul Husna%'
              AND m.nama_mapel NOT LIKE '%Upacara%'
              AND m.nama_mapel NOT LIKE '%Istirahat%'
              AND m.nama_mapel NOT LIKE '%Kepramukaan%'
              AND m.nama_mapel NOT LIKE '%Ekstrakurikuler%'
              AND m.nama_mapel NOT LIKE '%Ramadhanku%'
            ORDER BY m.nama_mapel ASC
        ");
        $stmt->execute([$guru_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $id = (int)$m['id_mapel'];
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $mapels[] = $m;
            }
        }
    } catch (Throwable $e) {}

    if (empty($mapels)) {
        $mapels = getFilteredSubjects($pdo);
    } else {
        usort($mapels, static function($a, $b) {
            return strcasecmp((string)($a['nama_mapel'] ?? ''), (string)($b['nama_mapel'] ?? ''));
        });
    }

    return $mapels;
}

/**
 * Nama folder aman untuk direktori upload per guru.
 * Contoh: "Budi Santoso, S.Pd" -> "Budi_Santoso_S_Pd".
 */
function guru_folder_name(PDO $pdo, int $guru_id): string {
    $name = '';
    try {
        $st = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
        $st->execute([$guru_id]);
        $name = trim((string)$st->fetchColumn());
    } catch (Throwable $e) {}
    if ($name === '') {
        $name = 'guru_' . $guru_id;
    }
    $slug = preg_replace('/[^A-Za-z0-9]+/', '_', $name);
    $slug = trim((string)$slug, '_');
    if ($slug === '') {
        $slug = 'guru_' . $guru_id;
    }
    return $slug;
}

/**
 * Direktori upload per guru: uploads/{kategori}/{Nama_Guru}/ (dibuat otomatis).
 * File lama tanpa folder guru tetap terbaca (fallback ke uploads/{kategori}/).
 */
function guru_upload_dir(PDO $pdo, int $guru_id, string $kategori): string {
    $base = dirname(__DIR__) . '/uploads/' . trim($kategori, '/') . '/';
    $dir = $base . guru_folder_name($pdo, $guru_id) . '/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * URL web untuk berkas guru. Terima nilai DB lama (nama file saja)
 * maupun baru (Nama_Guru/namafile).
 */
function guru_file_url(string $kategori, ?string $stored): ?string {
    $stored = trim((string)$stored);
    if ($stored === '') {
        return null;
    }
    if (strpos($stored, 'http://') === 0 || strpos($stored, 'https://') === 0) {
        return $stored;
    }
    $rel = ltrim(str_replace('\\', '/', $stored), '/');
    return '../uploads/' . trim($kategori, '/') . '/' . $rel;
}

/**
 * URL siap pakai di atribut href (tiap segmen path di-encode).
 */
function guru_file_href(string $kategori, ?string $stored): ?string {
    $url = guru_file_url($kategori, $stored);
    if ($url === null) {
        return null;
    }
    $parts = explode('?', $url, 2);
    $segs = explode('/', $parts[0]);
    foreach ($segs as $i => $s) {
        if ($s !== '' && $s !== '.' && $s !== '..') {
            $segs[$i] = rawurlencode(rawurldecode($s));
        }
    }
    $out = implode('/', $segs);
    if (isset($parts[1])) {
        $out .= '?' . $parts[1];
    }
    return $out;
}

/**
 * Cari path fisik berkas guru: folder guru dulu, lalu folder kategori lama.
 * Menerima nilai DB lama (nama file saja) maupun baru (Nama_Guru/namafile).
 */
function resolve_guru_file_path(string $kategori, ?string $stored): ?string {
    $stored = trim((string)$stored);
    if ($stored === '') {
        return null;
    }
    if (strpos($stored, 'http://') === 0 || strpos($stored, 'https://') === 0) {
        return null;
    }
    $base = dirname(__DIR__) . '/uploads/' . trim($kategori, '/') . '/';
    $rel = ltrim(str_replace('\\', '/', $stored), '/');
    $cand = $base . $rel;
    if (is_file($cand)) {
        return $cand;
    }
    $cand = $base . basename($rel);
    if (is_file($cand)) {
        return $cand;
    }
    return null;
}

// Function to format date
function formatDate(string $date): string {
    $v = trim($date);
    if ($v === '' || strpos($v, '0000-00-00') === 0) return '-';
    try {
        $dateObj = new DateTime($v);
    } catch (Throwable $e) {
        return '-';
    }
    $yearInt = (int)$dateObj->format('Y');
    if ($yearInt < 1900) return '-';
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

function formatDateDMY(?string $date_string, string $fallback = '-'): string {
    $v = trim((string)$date_string);
    if ($v === '' || strpos($v, '0000-00-00') === 0) return $fallback;
    try {
        $dt = new DateTime($v);
    } catch (Throwable $e) {
        return $fallback;
    }
    $yearInt = (int)$dt->format('Y');
    if ($yearInt < 1900) return $fallback;
    return $dt->format('d-m-Y');
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
 * Kelompokkan daftar kelas per tingkat untuk ekspor jadwal utama paralel.
 * Tingkat dikenali dari angka (1-6) atau romawi (I-VI) pada nama kelas;
 * nama tanpa pola masuk grup 'Lainnya'. Urutan grup: 1-6 lalu Lainnya.
 *
 * @param list<array> $classes baris tb_kelas (wajib id_kelas, nama_kelas)
 * @return list<array{label:string, classes:list<array>}>
 */
function groupKelasPerTingkat(array $classes): array {
    $roman_map = ['VI' => 6, 'V' => 5, 'IV' => 4, 'III' => 3, 'II' => 2, 'I' => 1];
    $groups = [];
    foreach ($classes as $c) {
        $name = strtoupper(trim((string)($c['nama_kelas'] ?? '')));
        $level = 0;
        foreach ($roman_map as $roman => $num) {
            if (preg_match('/\b' . $roman . '\b/', $name) || $name === $roman) { $level = $num; break; }
        }
        if ($level === 0 && preg_match('/(\d)/', $name, $m)) {
            $level = (int)$m[1];
        }
        if ($level === 0 && preg_match('/^([IVX]{1,4})/', $name, $m) && isset($roman_map[$m[1]])) {
            $level = $roman_map[$m[1]];
        }
        $label = $level > 0 ? ('Kelas ' . $level) : 'Lainnya';
        if (!isset($groups[$label])) {
            $groups[$label] = ['label' => $label, 'order' => $level > 0 ? $level : 99, 'classes' => []];
        }
        $groups[$label]['classes'][] = $c;
    }
    usort($groups, static function ($a, $b) {
        if ($a['order'] === $b['order']) {
            return strcasecmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
        }
        return $a['order'] <=> $b['order'];
    });
    return array_values($groups);
}

/**
 * Orientasi kertas ekspor jadwal utama berdasar lebar kolom paralel.
 * <=4 paralel: portrait 1 tabel. 5-6 paralel: landscape 1 tabel.
 * >6 paralel: landscape, potong per 6 kelas.
 *
 * @return array{mode:string, chunk:int}
 */
function jadwalUtamaLayoutMode(int $class_count, int $day_count = 6): array {
    unset($day_count);
    if ($class_count <= 4) {
        return ['mode' => 'portrait', 'chunk' => $class_count];
    }
    if ($class_count <= 6) {
        return ['mode' => 'landscape', 'chunk' => $class_count];
    }
    return ['mode' => 'landscape', 'chunk' => 6];
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
        '/idul\s*fitri|idul\s*adha|lebaran|hari\s+raya|natal|tahun\s+baru|akhir\s+tahun|libur\s+akhir\s+tahun|tahun\s+ajaran|tahun\s+pelajaran|(?:m)?aulid|isra\s*\'?\s*mi\'?raj|waisak|vesak|nyepi|imlek|toleransi|pancasila|kemerdekaan|merdeka|17\s+agustus|agustusan|cuti\s+bersama|hari\s+besar|hari\s+olah\s+raga|may\s+day|buruh|paskah|jumat\s+agung|good\s+friday|wafat\s+yesus|kenaikan|isa\s+almasih|ascension|kristus\s+raja|rebo\s*wekasan|sumpah\s+pemuda|hari\s+ibu|hari\s+guru|silaturahmi|tahun\s+baru\s+islam|tahun\s+baru\s+masehi|suro|tujuh\s+belas|harlah|hgn/i',
        $t
    )) {
        return true;
    }
    if (preg_match(
        '/\b(semester|rapor|libur\s+semester|libur\s+madrasah|libur\s+sekolah|pekan|ujian|usbn|ujian\s+nasional|assessment|wisuda|prakerin|mos|study\s*tour|study\s*band|pts|pat|susulan|evaluasi)\b/i',
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
 * Meta hari libur mingguan dari profil madrasah (dipakai kehadiran, rekap, kalender).
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
    $v = trim((string)$date_string);
    if ($v === '' || strpos($v, '0000-00-00') === 0) return '-';
    try {
        $date = new DateTime($v);
    } catch (Throwable $e) {
        return '-';
    }
    $yearInt = (int)$date->format('Y');
    if ($yearInt < 1900) return '-';
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
    $month_en = $date->format('F');
    $month = $bulan[$month_en] ?? $month_en;
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
                // Do not sort submenu A-Z. Only move "Scan Kehadiran" to the very top
                // while preserving the existing order of the other items.
                if (isset($item['submenu']) && is_array($item['submenu']) && (strpos($normalized_title, 'Kehadiran') !== false)) {
                    $scan_index = null;
                    foreach ($item['submenu'] as $idx => $sub) {
                        $t = trim(strip_tags($sub['title'] ?? ''));
                        if (strcasecmp($t, 'Scan Kehadiran') === 0) {
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
 * Ambil base_url + api_key aplikasi dari Pengaturan Endpoint (tb_endpoint_masuk).
 * Kembalikan ['base_url' => path tanpa query, 'api_key' => ...]. Kosong bila belum dikonfigurasi.
 */
function getInboundEndpointConfig(string $app): array {
    $out = ['base_url' => '', 'api_key' => ''];
    try {
        $pdo = $GLOBALS['pdo'] ?? null;
        if ($pdo instanceof PDO) {
            $st = $pdo->prepare("SELECT base_url, api_key FROM tb_endpoint_masuk WHERE nama_aplikasi = ? AND aktif = 1 LIMIT 1");
            $st->execute([strtolower(trim($app))]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $base = trim((string)($row['base_url'] ?? ''));
                $q = strpos($base, '?');
                if ($q !== false) $base = substr($base, 0, $q);
                $out['base_url'] = rtrim($base, '/');
                $out['api_key'] = trim((string)($row['api_key'] ?? ''));
            }
        }
    } catch (Throwable $e) { /* abaikan, pakai fallback */ }
    return $out;
}

function endpoint_referer_headers(string $apiUrl, string $apiKey): array {
    $host = (string)parse_url($apiUrl, PHP_URL_HOST);
    $ref = $host !== '' ? ((string)parse_url($apiUrl, PHP_URL_SCHEME) ?: 'http') . '://' . $host . '/' : '';
    return [
        'X-API-KEY: ' . $apiKey,
        'Accept: application/json, text/plain, */*',
        'Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7',
        'Referer: ' . $ref,
        'Origin: ' . rtrim($ref, '/'),
        'Connection: keep-alive'
    ];
}

/**
 * PEMBAYARAN SPP - INTEGRATION CLIENT FOR SIMAD
 * Versi: 1.2.0 (URL + key dinamis dari Pengaturan Endpoint, tanpa hardcode domain)
 */
class PembayaranClient {
    private string $apiUrl;
    private string $apiKey;

    public function __construct(string $apiKey = '', string $apiUrl = '') {
        $cfg = getInboundEndpointConfig('sibayar');
        if ($apiUrl === '') $apiUrl = $cfg['base_url'];
        if ($apiKey === '') $apiKey = $cfg['api_key'] !== '' ? $cfg['api_key'] : 'SPP_SECRET_KEY_2026';
        $this->apiKey = $apiKey;
        $this->apiUrl = $apiUrl;
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
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_ENCODING, '');
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, endpoint_referer_headers($this->apiUrl, $this->apiKey));

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
            $msg = 'Invalid JSON response';
            if (stripos($response, 'imunify') !== false || stripos($response, 'bot-protection') !== false) {
                $msg = 'Server Pembayaran memblokir koneksi (Imunify360). Hubungi administrator server.';
            }
            return [
                'status' => 'error',
                'message' => $msg,
                'raw_response' => substr((string)$response, 0, 500)
            ];
        }

        return $result;
    }

    public function checkConnection(): array {
        if (!function_exists('pembayaran_local_connection')) {
            require_once __DIR__ . '/pembayaran_local.php';
        }
        $koneksi = function_exists('pembayaran_local_connection') ? pembayaran_local_connection() : null;
        if ($koneksi) {
            return [
                'status' => 'success',
                'message' => 'Pembayaran Siswa (database SIMAD) aktif',
                'env' => 'simad-local',
                'tahun_ajaran_aktif' => pembayaran_local_tahun_ajaran_aktif($koneksi),
                'server_time' => date('Y-m-d H:i:s'),
            ];
        }
        return $this->request('check');
    }

    public function getTahunAjaran(): array {
        if (!function_exists('pembayaran_local_get_tahun_ajaran')) {
            require_once __DIR__ . '/pembayaran_local.php';
        }
        $koneksi = function_exists('pembayaran_local_connection') ? pembayaran_local_connection() : null;
        if ($koneksi) {
            return [
                'status' => 'success',
                'data' => pembayaran_local_get_tahun_ajaran($koneksi),
                'tahun_ajaran_aktif' => pembayaran_local_tahun_ajaran_aktif($koneksi),
                'env' => 'simad-local',
            ];
        }
        return $this->request('get_tahun_ajaran');
    }

    public function getAllStudentSummary(?string $tahunAjaran = null): array {
        if (!function_exists('pembayaran_local_get_all_summary')) {
            require_once __DIR__ . '/pembayaran_local.php';
        }
        if (function_exists('pembayaran_local_get_all_summary') && pembayaran_local_connection()) {
            return pembayaran_local_get_all_summary((string)($tahunAjaran ?? ''));
        }
        $params = [];
        if ($tahunAjaran !== null && trim($tahunAjaran) !== '') {
            $params['tahun_ajaran'] = trim($tahunAjaran);
        }
        return $this->request('get_all_summary', $params);
    }

    public function getStudentDetail(string $nisn, ?string $tahunAjaran = null): array {
        if (!function_exists('pembayaran_local_get_student_data')) {
            require_once __DIR__ . '/pembayaran_local.php';
        }
        if (function_exists('pembayaran_local_get_student_data') && pembayaran_local_connection()) {
            return pembayaran_local_get_student_data($nisn, (string)($tahunAjaran ?? ''));
        }
        $params = ['nisn' => $nisn];
        if ($tahunAjaran !== null && trim($tahunAjaran) !== '') {
            $params['tahun_ajaran'] = trim($tahunAjaran);
        }
        return $this->request('get_student_data', $params);
    }
}

function fetchPembayaranData(string $nisn, string $type = 'tagihan', ?string $tahunAjaran = null): array {
     $client = new PembayaranClient();
     $response = $client->getStudentDetail($nisn, $tahunAjaran);

     // Cache successful response
     if (isset($response['status']) && $response['status'] === 'success') {
         $cache_file = dirname(__DIR__) . '/cache/pembayaran_' . $nisn . '.json';
         @file_put_contents($cache_file, json_encode($response));

         // Map response based on request type
         if ($type === 'tagihan') {
             return array_merge($response, [
                 'status' => 'success',
                 'data' => $response['billing'] ?? []
             ]);
         } elseif ($type === 'laporan') {
             return $response;
         } else {
             return [
                 'status' => 'success',
                 'data' => $response['payments'] ?? []
             ];
         }
     }

     // Fallback to cache if API failed (hanya cache dari DB Pembayaran lokal, bukan cache era SiBayar)
     $cache_file = dirname(__DIR__) . '/cache/pembayaran_' . $nisn . '.json';
     if (is_file($cache_file)) {
         $cached = @json_decode(@file_get_contents($cache_file), true);
         if ($cached && isset($cached['status']) && $cached['status'] === 'success' && ($cached['env'] ?? '') === 'simad-local') {
             if ($type === 'tagihan') {
                 return array_merge($cached, [
                     'status' => 'success',
                     'data' => $cached['billing'] ?? []
                 ]);
             } elseif ($type === 'laporan') {
                 return $cached;
             } else {
                 return [
                     'status' => 'success',
                     'data' => $cached['payments'] ?? []
                 ];
             }
         }
     }

     // Dev fallback: sample data when both API and cache fail
     if (isset($_GET['dev_pembayaran'])) {
         $ta_aktif = date('Y') . '/' . (date('Y') + 1);
         $ta_lama = (date('Y') - 1) . '/' . date('Y');
         return [
             'status' => 'success',
             'tahun_ajaran' => $ta_aktif,
             'student' => ['nama' => 'Siswa Contoh', 'nisn' => $nisn, 'kelas' => '1 A'],
             'summary' => ['total_tunggakan_aktif' => 200000, 'total_tunggakan_tahun_lama' => 150000],
             'billing' => [
                 ['nama_pembayaran' => 'SPP Bulan Juli', 'tipe_bayar' => 'Bulanan', 'tahun_ajaran' => $ta_aktif, 'total_nominal' => 100000, 'total_bayar' => 0, 'sisa_tagihan' => 100000, 'item_belum_bayar' => ['Juli']],
                 ['nama_pembayaran' => 'SPP Bulan Agustus', 'tipe_bayar' => 'Bulanan', 'tahun_ajaran' => $ta_aktif, 'total_nominal' => 100000, 'total_bayar' => 0, 'sisa_tagihan' => 100000, 'item_belum_bayar' => ['Agustus']]
             ],
             'tunggakan_tahun_ajaran_lama' => [
                 $ta_lama => [
                     'tahun_ajaran' => $ta_lama,
                     'total_tunggakan' => 150000,
                     'items' => [
                         ['nama_pembayaran' => 'SPP Bulan Juni', 'tipe_bayar' => 'Bulanan', 'tahun_ajaran' => $ta_lama, 'total_nominal' => 150000, 'total_bayar' => 0, 'sisa_tagihan' => 150000, 'item_belum_bayar' => ['Juni']]
                     ]
                 ]
             ]
         ];
     }

     return $response;
 }

function get_current_user_key(): string {
    if (isset($_SESSION['user_id'])) {
        $lvl = function_exists('getUserLevel') ? getUserLevel() : ($_SESSION['level'] ?? '');
        if ($lvl === 'siswa') {
            return 'siswa_' . (int)$_SESSION['user_id'];
        }
        $gid = function_exists('getCurrentGuruId') ? (int)getCurrentGuruId($GLOBALS['pdo'] ?? null) : 0;
        if ($gid > 0) {
            return 'guru_' . $gid;
        }
        return 'user_' . (int)$_SESSION['user_id'];
    }
    return 'guest';
}

// ============================================================
// Notifikasi bertarget (audience)
// tb_notifikasi.audience menyimpan token penerima:
//   'admin_kepala'          -> admin & kepala madrasah saja
//   'guru_wali'             -> semua guru & wali
//   'guru_wali_kelas_<id>'  -> guru/wali pengampu kelas tsb
// Baris tanpa audience (NULL/'') tetap terlihat oleh semua peran.
// ============================================================
if (!function_exists('simadEnsureNotifAudienceColumn')) {
    function simadEnsureNotifAudienceColumn(PDO $pdo): bool {
        static $checked = null;
        if ($checked !== null) {
            return $checked;
        }
        $checked = false;
        try {
            $has = function_exists('dbColumnExists') ? dbColumnExists($pdo, 'tb_notifikasi', 'audience') : null;
            if ($has === null) {
                $has = (bool)$pdo->query("SHOW COLUMNS FROM tb_notifikasi LIKE 'audience'")->fetchAll();
            }
            if (!$has) {
                $pdo->exec("ALTER TABLE tb_notifikasi ADD COLUMN audience VARCHAR(64) NULL DEFAULT NULL");
            }
            $checked = true;
        } catch (Throwable $e) {
            $checked = false;
        }
        return $checked;
    }
}

// Token audience yang boleh dilihat pengguna saat ini
if (!function_exists('simadNotifAudienceTokens')) {
    function simadNotifAudienceTokens(PDO $pdo): array {
        $level  = function_exists('getUserLevel') ? getUserLevel() : strtolower(trim((string)($_SESSION['level'] ?? '')));
        $tokens = [];
        if (in_array($level, ['admin', 'kepala_madrasah'], true)) {
            $tokens[] = 'admin_kepala';
        }
        if (in_array($level, ['guru', 'wali'], true)) {
            $tokens[] = 'guru_wali';
            $gid = function_exists('getCurrentGuruId') ? (int)getCurrentGuruId($pdo) : 0;
            foreach (simadKelasIdsForGuru($pdo, $gid) as $kid) {
                $tokens[] = 'guru_wali_kelas_' . (int)$kid;
            }
        }
        return array_values(array_unique($tokens));
    }
}

// Daftar id kelas yang diampu guru (wali kelas maupun pengajar mapel)
if (!function_exists('simadKelasIdsForGuru')) {
    function simadKelasIdsForGuru(PDO $pdo, int $guru_id): array {
        if ($guru_id <= 0) {
            return [];
        }
        $ids = [];
        try {
            $st = $pdo->prepare("SELECT nama_guru, mengajar FROM tb_guru WHERE id_guru = ? LIMIT 1");
            $st->execute([$guru_id]);
            $row  = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $nama = trim((string)($row['nama_guru'] ?? ''));
            if ($nama !== '') {
                $st2 = $pdo->prepare("SELECT id_kelas FROM tb_kelas WHERE wali_kelas = ?");
                $st2->execute([$nama]);
                foreach ($st2->fetchAll(PDO::FETCH_COLUMN) as $k) {
                    $ids[] = (int)$k;
                }
            }
            $mengajar = trim((string)($row['mengajar'] ?? ''));
            if ($mengajar !== '') {
                $decoded = json_decode($mengajar, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $k) {
                        $ids[] = (int)(is_array($k) ? ($k['id_kelas'] ?? 0) : $k);
                    }
                } else {
                    foreach (preg_split('/[^0-9]+/', $mengajar) as $k) {
                        if ($k !== '') {
                            $ids[] = (int)$k;
                        }
                    }
                }
            }
        } catch (Throwable $e) {}
        return array_values(array_unique(array_filter($ids, function ($v) { return $v > 0; })));
    }
}

// Notifikasi kehadiran (yang punya audience) untuk pengguna saat ini
if (!function_exists('getAttendanceNotifications')) {
    function getAttendanceNotifications(PDO $pdo): array {
        $rows = function_exists('getNotifications') ? getNotifications($pdo) : [];
        $out  = [];
        foreach ($rows as $r) {
            if (!empty($r['audience'])) {
                $out[] = $r;
            }
        }
        return $out;
    }
}

// Function to create notification
function createNotification(PDO $pdo, string $message, string $link, string $type = 'info', ?string $actor_key = null, ?string $audience = null): bool {
    if ($actor_key === null) {
        $actor_key = get_current_user_key();
    }
    $hasAudience = simadEnsureNotifAudienceColumn($pdo);
    try {
        if ($hasAudience) {
            $stmt = $pdo->prepare("INSERT INTO tb_notifikasi (message, link, actor_key, audience, created_at) VALUES (?, ?, ?, ?, NOW())");
            return $stmt->execute([$message, $link, $actor_key, $audience]);
        }
        $stmt = $pdo->prepare("INSERT INTO tb_notifikasi (message, link, actor_key, created_at) VALUES (?, ?, ?, NOW())");
        return $stmt->execute([$message, $link, $actor_key]);
    } catch (Throwable $e) {
        $stmt = $pdo->prepare("INSERT INTO tb_notifikasi (message, link, created_at) VALUES (?, ?, NOW())");
        return $stmt->execute([$message, $link]);
    }
}

// Function to get system notifications (auto delete > 24 hours)
function getNotifications(PDO $pdo): array {
    try {
        $pdo->exec("DELETE FROM tb_notifikasi WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $pdo->exec("DELETE FROM tb_notifikasi_read WHERE read_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    } catch (Throwable $e) {}

    $ukey   = get_current_user_key();
    $tokens = function_exists('simadNotifAudienceTokens') ? simadNotifAudienceTokens($pdo) : [];

    // Baris tanpa audience = notifikasi lama (semua peran). Baris ber-audience hanya
    // untuk token yang cocok dengan peran pengguna.
    $audSql = "(n.audience IS NULL OR n.audience = ''";
    if (!empty($tokens)) {
        $audSql .= " OR n.audience IN (" . implode(',', array_fill(0, count($tokens), '?')) . ")";
    }
    $audSql .= ")";

    try {
        $stmt = $pdo->prepare("
            SELECT n.*, (CASE WHEN r.notif_id IS NOT NULL THEN 1 ELSE 0 END) AS is_read
            FROM tb_notifikasi n
            LEFT JOIN tb_notifikasi_read r ON r.notif_id = n.id AND r.user_key = ?
            WHERE (n.actor_key IS NULL OR n.actor_key != ?)
              AND $audSql
            ORDER BY n.created_at DESC
        ");
        $stmt->execute(array_merge([$ukey, $ukey], $tokens));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $stmt = $pdo->prepare("SELECT * FROM tb_notifikasi ORDER BY created_at DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Function to get unread notifications
function getUnreadNotifications(PDO $pdo): array {
    try {
        $pdo->exec("DELETE FROM tb_notifikasi WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $pdo->exec("DELETE FROM tb_notifikasi_read WHERE read_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    } catch (Throwable $e) {}

    $ukey   = get_current_user_key();
    $tokens = function_exists('simadNotifAudienceTokens') ? simadNotifAudienceTokens($pdo) : [];

    $audSql = "(n.audience IS NULL OR n.audience = ''";
    if (!empty($tokens)) {
        $audSql .= " OR n.audience IN (" . implode(',', array_fill(0, count($tokens), '?')) . ")";
    }
    $audSql .= ")";

    try {
        $stmt = $pdo->prepare("
            SELECT n.*
            FROM tb_notifikasi n
            LEFT JOIN tb_notifikasi_read r ON r.notif_id = n.id AND r.user_key = ?
            WHERE r.notif_id IS NULL
              AND (n.actor_key IS NULL OR n.actor_key != ?)
              AND $audSql
            ORDER BY n.created_at DESC
        ");
        $stmt->execute(array_merge([$ukey, $ukey], $tokens));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return getNotifications($pdo);
    }
}

function getTeacherTaskNotifications(PDO $pdo, int $guru_id, int $limit = 15): array {
    try {
        $pdo->exec("DELETE FROM tb_notifikasi WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $pdo->exec("DELETE FROM tb_notifikasi_read WHERE read_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    } catch (Throwable $e) {}

    $ukey = get_current_user_key();
    try {
        $st = $pdo->prepare("
            SELECT n.*, (CASE WHEN r.notif_id IS NOT NULL THEN 1 ELSE 0 END) AS is_read
            FROM tb_notifikasi n
            LEFT JOIN tb_notifikasi_read r ON r.notif_id = n.id AND r.user_key = ?
            WHERE (n.actor_key IS NULL OR n.actor_key != ?)
              AND (n.message LIKE 'Tugas dikumpulkan:%' OR n.message LIKE 'Forum Guru:%' OR n.message LIKE 'Diskusi Kelas:%' OR n.message LIKE '%mencolek%')
            ORDER BY n.created_at DESC LIMIT 50
        ");
        $st->execute([$ukey, $ukey]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    if (empty($rows)) return [];

    // Petakan link -> id tugas
    $ids = [];
    $task_of = [];
    foreach ($rows as $r) {
        if (preg_match('/[?&]id=(\d+)/', (string)($r['link'] ?? ''), $m)) {
            $tid = (int)$m[1];
            if ($tid > 0) {
                $ids[] = $tid;
                $task_of[(int)$r['id']] = $tid;
            }
        }
    }
    $tasks = [];
    if (!empty($ids)) {
        $ids = array_values(array_unique($ids));
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stT = $pdo->prepare("SELECT id, id_guru, id_kelas FROM tb_tugas WHERE id IN ($in)");
            $stT->execute($ids);
            foreach ($stT->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $tasks[(int)$t['id']] = $t;
            }
        } catch (Throwable $e) {}
    }

    $taught = [];
    if ($guru_id > 0 && function_exists('getGuruTaughtClasses')) {
        try {
            foreach (getGuruTaughtClasses($pdo, $guru_id) as $c) {
                $taught[(int)($c['id_kelas'] ?? 0)] = true;
            }
        } catch (Throwable $e) {}
    }

    $out = [];
    foreach ($rows as $r) {
        $msg = (string)($r['message'] ?? '');
        if (strpos($msg, 'Tugas dikumpulkan:') === 0) {
            $tid = $task_of[(int)$r['id']] ?? 0;
            $t = $tasks[$tid] ?? null;
            if (!$t) continue;
            $mine = ($guru_id > 0 && (int)($t['id_guru'] ?? 0) === $guru_id) || isset($taught[(int)($t['id_kelas'] ?? 0)]);
            if (!$mine) continue;
        }
        $out[] = $r;
        if (count($out) >= $limit) break;
    }
    return $out;
}

// Function to mark notification as read (per-user, global row tetap 24 jam)
function markNotificationAsRead(PDO $pdo, int $id, ?string $user_key = null): bool {
    if ($user_key === null) {
        $user_key = get_current_user_key();
    }
    try {
        $st = $pdo->prepare("INSERT IGNORE INTO tb_notifikasi_read (notif_id, user_key) VALUES (?, ?)");
        return $st->execute([$id, $user_key]);
    } catch (Throwable $e) {
        return false;
    }
}

// Format "Selasa, 6 Oktober 2026 - 17:10:23" untuk label waktu notifikasi
function formatHariTanggalWaktu(?string $ts): string {
    if (trim((string)$ts) === '' || strpos((string)$ts, '0000-00-00') === 0) return '-';
    $time = strtotime((string)$ts);
    if (!$time) return '-';
    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return $hari[(int)date('w', $time)] . ', ' . (int)date('j', $time) . ' ' . $bulan[(int)date('n', $time)] . ' ' . date('Y', $time) . ' - ' . date('H:i:s', $time);
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
    // If keterangan is empty (reset attendance), delete sholat records unless marked 'Berhalangan'
    if ($keterangan_absensi === '') {
        $pdo->prepare("DELETE FROM tb_sholat WHERE id_siswa = ? AND tanggal = ? AND status != 'Berhalangan'")
            ->execute([$id_siswa, $tanggal]);
        $pdo->prepare("DELETE FROM tb_sholat_dhuha WHERE id_siswa = ? AND tanggal = ? AND status != 'Berhalangan'")
            ->execute([$id_siswa, $tanggal]);
        return;
    }

    $status_sholat = '';
    // Determine status matching ENUM('Hadir','Tidak Hadir','Berhalangan')
    if ($keterangan_absensi == 'Hadir' || $keterangan_absensi == 'Terlambat') {
        $status_sholat = 'Hadir';
    } elseif (in_array($keterangan_absensi, ['Sakit', 'Izin', 'Alpa'])) {
        $status_sholat = 'Tidak Hadir';
    } elseif ($keterangan_absensi == 'Berhalangan') {
        $status_sholat = 'Berhalangan';
    }
    
    if ($status_sholat) {
        // Update or Insert tb_sholat (Sholat Berjamaah)
        $stmt = $pdo->prepare("SELECT id_sholat, status FROM tb_sholat WHERE id_siswa = ? AND tanggal = ?");
        $stmt->execute([$id_siswa, $tanggal]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Skip update if status is 'Berhalangan' - manual override takes precedence unless new status is also specific
        if (!$existing || $existing['status'] != 'Berhalangan' || $status_sholat == 'Berhalangan') {
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
        if (!$existing_dhuha || $existing_dhuha['status'] != 'Berhalangan' || $status_sholat == 'Berhalangan') {
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

function dbTableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function dbColumnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function generateStudentPassword(int $length = STUDENT_RANDOM_PASSWORD_LENGTH): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $password = '';

    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }

    return $password;
}

function ensureStudentPasswordColumns(PDO $pdo): void {
    try {
        if (!dbColumnExists($pdo, 'tb_siswa', 'password')) {
            $pdo->exec("ALTER TABLE tb_siswa ADD COLUMN password VARCHAR(255) NULL AFTER nisn");
        }
        if (!dbColumnExists($pdo, 'tb_siswa', 'password_plain')) {
            $pdo->exec("ALTER TABLE tb_siswa ADD COLUMN password_plain VARCHAR(32) NULL AFTER password");
        }
    } catch (Throwable $e) {
        error_log('ensureStudentPasswordColumns: ' . $e->getMessage());
    }
}

function ensureStudentPasswords(PDO $pdo): int {
    ensureStudentPasswordColumns($pdo);

    try {
        $stmt = $pdo->query("SELECT id_siswa FROM tb_siswa WHERE password IS NULL OR password = '' OR password_plain IS NULL OR password_plain = ''");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $update = $pdo->prepare('UPDATE tb_siswa SET password = ?, password_plain = ? WHERE id_siswa = ?');
        $count = 0;

        foreach ($rows as $idSiswa) {
            $plain = generateStudentPassword();
            if ($update->execute([hashPassword($plain), $plain, (int)$idSiswa])) {
                $count++;
            }
        }

        return $count;
    } catch (Throwable $e) {
        error_log('ensureStudentPasswords: ' . $e->getMessage());
        return 0;
    }
}

function ensureGuruDefaultPasswords(PDO $pdo): int {
    try {
        if (!dbColumnExists($pdo, 'tb_guru', 'password')) {
            $pdo->exec("ALTER TABLE tb_guru ADD COLUMN password VARCHAR(255) NULL AFTER wali_kelas");
        }
        if (!dbColumnExists($pdo, 'tb_guru', 'password_plain')) {
            $pdo->exec("ALTER TABLE tb_guru ADD COLUMN password_plain VARCHAR(100) NULL AFTER password");
        }

        $stmt = $pdo->query("SELECT id_guru FROM tb_guru WHERE password IS NULL OR password = '' OR password_plain IS NULL OR password_plain = '' OR password_plain = '123456'");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $update = $pdo->prepare('UPDATE tb_guru SET password = ?, password_plain = ? WHERE id_guru = ?');
        $hash = hashPassword(DEFAULT_GURU_PASSWORD);
        $count = 0;

        foreach ($rows as $idGuru) {
            if ($update->execute([$hash, DEFAULT_GURU_PASSWORD, (int)$idGuru])) {
                $count++;
            }
        }

        return $count;
    } catch (Throwable $e) {
        error_log('ensureGuruDefaultPasswords: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Hapus data Pramuka yang melekat pada siswa aktif/lulus/alumni.
 * Sumber surat keterangan adalah tb_peserta_didik_barung, jadi penghapusan
 * anggota otomatis membersihkan daftar surat keterangan juga.
 *
 * @param int[] $siswaIds
 * @param string[] $nisns
 * @param string[] $names
 * @return array{peserta:int, sku:int}
 */
function cleanupPramukaDataForSiswa(PDO $pdo, array $siswaIds = [], array $nisns = [], array $names = []): array {
    if (!dbTableExists($pdo, 'tb_peserta_didik_barung')) {
        return ['peserta' => 0, 'sku' => 0];
    }

    $siswaIds = array_values(array_unique(array_filter(array_map('intval', $siswaIds), static function ($v) {
        return $v > 0;
    })));
    $nisns = array_values(array_unique(array_filter(array_map(static function ($v) {
        return trim((string)$v);
    }, $nisns), static function ($v) {
        return $v !== '';
    })));
    $names = array_values(array_unique(array_filter(array_map(static function ($v) {
        return strtolower(trim((string)$v));
    }, $names), static function ($v) {
        return $v !== '';
    })));

    if (empty($siswaIds) && empty($nisns) && empty($names)) {
        return ['peserta' => 0, 'sku' => 0];
    }

    $hasIdSiswa = dbColumnExists($pdo, 'tb_peserta_didik_barung', 'id_siswa');
    $stmt = $pdo->query('SELECT id_peserta_didik_barung, ' . ($hasIdSiswa ? 'id_siswa' : 'NULL AS id_siswa') . ', nta, nama_peserta_didik FROM tb_peserta_didik_barung WHERE status != \'keluar\'');
    $participantIds = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rowIdSiswa = (int)($row['id_siswa'] ?? 0);
        $rowNta = trim((string)($row['nta'] ?? ''));
        $rowName = strtolower(trim((string)($row['nama_peserta_didik'] ?? '')));
        $rowNameNormalized = preg_replace('/[^a-z0-9]+/', '', $rowName);

        // Match by id_siswa (most reliable)
        $matchById = $rowIdSiswa > 0 && in_array($rowIdSiswa, $siswaIds, true);
        
        // Match by NISN (nta column often contains nisn, or we try both ways)
        $matchByNisn = false;
        if ($rowNta !== '') {
            $matchByNisn = in_array($rowNta, $nisns, true);
        }
        
        // Match by name with normalization (more lenient)
        $matchByName = false;
        if ($rowNameNormalized !== '') {
            foreach ($names as $searchName) {
                $searchNameNormalized = preg_replace('/[^a-z0-9]+/', '', strtolower(trim($searchName)));
                if ($searchNameNormalized !== '' && (
                    $rowNameNormalized === $searchNameNormalized ||
                    strpos($rowNameNormalized, $searchNameNormalized) !== false ||
                    strpos($searchNameNormalized, $rowNameNormalized) !== false
                )) {
                    $matchByName = true;
                    break;
                }
            }
        }

        if ($matchById || $matchByNisn || $matchByName) {
            $participantIds[] = (int)$row['id_peserta_didik_barung'];
        }
    }

    $participantIds = array_values(array_unique(array_filter($participantIds, static function ($v) {
        return $v > 0;
    })));
    if (empty($participantIds)) {
        return ['peserta' => 0, 'sku' => 0];
    }

    $pesertaDeleted = 0;
    $skuDeleted = 0;
    
    foreach (array_chunk($participantIds, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        
        // Delete SKU data first
        if (dbTableExists($pdo, 'tb_sku_kecakapan_nilai')) {
            $delSku = $pdo->prepare("DELETE FROM tb_sku_kecakapan_nilai WHERE id_peserta_didik_barung IN ($placeholders)");
            $delSku->execute($chunk);
            $skuDeleted += $delSku->rowCount();
        }

        // Then delete pramuka members
        $delPeserta = $pdo->prepare("DELETE FROM tb_peserta_didik_barung WHERE id_peserta_didik_barung IN ($placeholders)");
        $delPeserta->execute($chunk);
        $pesertaDeleted += $delPeserta->rowCount();
    }

    return ['peserta' => $pesertaDeleted, 'sku' => $skuDeleted];
}

function ensureAlumniOriginalIdColumn(PDO $pdo): void {
    if (!dbTableExists($pdo, 'tb_alumni')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `tb_alumni` (
            `id_alumni` int NOT NULL AUTO_INCREMENT,
            `original_id_siswa` int DEFAULT NULL,
            `nama_siswa` varchar(100) NOT NULL,
            `nisn` varchar(20) NOT NULL,
            `jenis_kelamin` enum('L','P') DEFAULT NULL,
            `tempat_lahir` varchar(100) DEFAULT NULL,
            `tanggal_lahir` date DEFAULT NULL,
            `wali` varchar(100) DEFAULT NULL,
            `tahun_lulus` varchar(20) NOT NULL,
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_alumni`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!dbColumnExists($pdo, 'tb_alumni', 'original_id_siswa')) {
        $pdo->exec('ALTER TABLE tb_alumni ADD COLUMN original_id_siswa INT NULL AFTER id_alumni');
    }
}

function getStudentsForNilaiKelas(PDO $pdo, int $id_kelas, string $tahun_ajaran, string $semester, ?string $jenis_semester = null, ?int $id_mapel = null): array {
    $extra = '';
    if ($jenis_semester !== null && $jenis_semester !== '') {
        $extra .= ' AND ns.jenis_semester = ?';
    }
    if ($id_mapel !== null && $id_mapel > 0) {
        $extra .= ' AND ns.id_mapel = ?';
    }

    $stmt = $pdo->prepare("
        SELECT DISTINCT s.*
        FROM tb_siswa s
        LEFT JOIN tb_nilai_semester ns ON ns.id_siswa = s.id_siswa
            AND ns.id_kelas = ?
            AND ns.tahun_ajaran = ?
            AND ns.semester = ?
            $extra
        WHERE s.id_kelas = ? OR ns.id_siswa IS NOT NULL
        ORDER BY s.nama_siswa ASC
    ");

    $orderedParams = [$id_kelas, $tahun_ajaran, $semester];
    if ($jenis_semester !== null && $jenis_semester !== '') {
        $orderedParams[] = $jenis_semester;
    }
    if ($id_mapel !== null && $id_mapel > 0) {
        $orderedParams[] = $id_mapel;
    }
    $orderedParams[] = $id_kelas;

    $stmt->execute($orderedParams);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

/**
 * Tambah kolom tmt (Tanggal Mulai Tugas) ke tb_guru jika belum ada.
 */
function ensureTbGuruTmtColumn(PDO $pdo): bool {
    static $checked = false;
    if ($checked) {
        return true;
    }
    $checked = true;
    try {
        $row = $pdo->query("SHOW COLUMNS FROM tb_guru LIKE 'tmt'")->fetch();
        if (!$row) {
            $pdo->exec("ALTER TABLE tb_guru ADD COLUMN tmt DATE DEFAULT NULL AFTER pendidikan");
        }
        return true;
    } catch (PDOException $e) {
        error_log('ensureTbGuruTmtColumn: ' . $e->getMessage());
        return false;
    }
}

function ensureTbJabatanMaster(PDO $pdo): bool
{
    static $checked = false;
    if ($checked) {
        return true;
    }
    $checked = true;
    try {
        $exists = (bool)$pdo->query("SHOW TABLES LIKE 'tb_jabatan'")->fetch(PDO::FETCH_NUM);
        if (!$exists) {
            $pdo->exec("CREATE TABLE tb_jabatan (
                id_jabatan INT AUTO_INCREMENT PRIMARY KEY,
                nama_jabatan VARCHAR(120) NOT NULL UNIQUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM tb_jabatan")->fetchColumn();
        if ($cnt === 0) {
            $defaults = ['Guru Kelas', 'Guru Mapel', 'Wali Kelas', 'Kepala Madrasah', 'Wakamad Kurikulum', 'Wakamad Kesiswaan', 'Wakamad Sarpras', 'Guru BK', 'Tenaga Kependidikan', 'Tata Usaha'];
            $stmt = $pdo->prepare("INSERT IGNORE INTO tb_jabatan (nama_jabatan) VALUES (?)");
            foreach ($defaults as $nm) {
                $stmt->execute([$nm]);
            }
        }
        return true;
    } catch (PDOException $e) {
        error_log('ensureTbJabatanMaster: ' . $e->getMessage());
        return false;
    }
}

function ensureTbGuruJabatanColumn(PDO $pdo): bool
{
    static $checked = false;
    if ($checked) {
        return true;
    }
    $checked = true;
    try {
        $row = $pdo->query("SHOW COLUMNS FROM tb_guru LIKE 'jabatan'")->fetch();
        if (!$row) {
            $pdo->exec("ALTER TABLE tb_guru ADD COLUMN jabatan VARCHAR(120) DEFAULT NULL AFTER mengajar");
        }
        return true;
    } catch (PDOException $e) {
        error_log('ensureTbGuruJabatanColumn: ' . $e->getMessage());
        return false;
    }
}

function getJabatanList(PDO $pdo): array
{
    try {
        return $pdo->query("SELECT id_jabatan, nama_jabatan FROM tb_jabatan ORDER BY nama_jabatan ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Generate kode guru selanjutnya secara urut abjad.
 * Contoh: A, B, ..., Z, AA, AB, ...
 */
function generateNextKodeGuru(PDO $pdo): string {
    try {
        $stmt = $pdo->query("SELECT kode_guru FROM tb_guru WHERE kode_guru IS NOT NULL AND kode_guru != '' ORDER BY LENGTH(kode_guru) DESC, kode_guru DESC LIMIT 1");
        $last = $stmt->fetchColumn();
        if (!$last) return 'A';

        $chars = str_split(strtoupper($last));
        $i = count($chars) - 1;
        while ($i >= 0) {
            if ($chars[$i] === 'Z') {
                $chars[$i] = 'A';
                $i--;
            } else {
                $chars[$i] = chr(ord($chars[$i]) + 1);
                break;
            }
        }
        if ($i < 0) {
            array_unshift($chars, 'A');
        }
        return implode('', $chars);
    } catch (Exception $e) {
        error_log('generateNextKodeGuru: ' . $e->getMessage());
        return 'A';
    }
}

/**
 * Hitung masa bakti dari TMT ke tanggal sekarang.
 * Format: "X tahun Y bulan" atau "-" jika TMT kosong.
 */
function calculateMasaBakti(?string $tmt): string {
    if (empty($tmt)) return '-';
    try {
        $start = new DateTime($tmt);
        $end = new DateTime();
        $diff = $start->diff($end);
        return $diff->y . ' tahun ' . $diff->m . ' bulan';
    } catch (Exception $e) {
        return '-';
    }
}

// --- Helper Functions for Security ---

// Function to sanitize user input
function sanitizeInput(string $input): string {
    $input = trim($input);
    $input = stripslashes($input);
    return $input;
}

function securityQuarantinePath(): string {
    $dir = dirname(__DIR__) . '/backups/security_quarantine';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function securityIsInsideProject(string $path): bool {
    $root = realpath(dirname(__DIR__));
    $real = realpath($path);
    if ($root === false || $real === false) {
        return false;
    }
    return strpos($real, $root . DIRECTORY_SEPARATOR) === 0 || $real === $root;
}

function quarantineSuspiciousFile(string $path, string $reason = 'suspicious'): bool {
    if (!is_file($path) || !securityIsInsideProject($path)) {
        return false;
    }

    $quarantineDir = securityQuarantinePath();
    if (!is_dir($quarantineDir) || !is_writable($quarantineDir)) {
        return false;
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($path));
    $target = $quarantineDir . '/' . date('Ymd_His') . '_' . $safeName . '.quarantine';
    $ok = @rename($path, $target);
    if ($ok) {
        @file_put_contents(
            $quarantineDir . '/security.log',
            '[' . date('Y-m-d H:i:s') . '] quarantined=' . $path . ' target=' . $target . ' reason=' . $reason . PHP_EOL,
            FILE_APPEND
        );
    }
    return $ok;
}

function scanKnownBackdoors(bool $quarantine = false): array {
    $root = dirname(__DIR__);
    $findings = [];
    $exactRootFiles = [
        'wp-blog-header.php', 'wp-cron.php', 'wp-config.php', 'wp-login.php', 'wp-load.php',
        'wp-mail.php', 'wp-settings.php', 'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php'
    ];

    foreach ($exactRootFiles as $name) {
        $path = $root . '/' . $name;
        if (is_file($path)) {
            $findings[] = ['path' => $path, 'reason' => 'unexpected-wordpress-file'];
            if ($quarantine) {
                quarantineSuspiciousFile($path, 'unexpected-wordpress-file');
            }
        }
    }

    foreach (glob($root . '/tmp_*.php') ?: [] as $path) {
        if (is_file($path)) {
            $findings[] = ['path' => $path, 'reason' => 'unexpected-root-temp-php'];
            if ($quarantine) {
                quarantineSuspiciousFile($path, 'unexpected-root-temp-php');
            }
        }
    }

    $writableDirs = [$root . '/uploads', $root . '/sessions', $root . '/backups'];
    foreach ($writableDirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (preg_match('/\.(php|php[0-9]|phtml|phar)$/i', $path)) {
                $findings[] = ['path' => $path, 'reason' => 'executable-in-writable-directory'];
                if ($quarantine) {
                    quarantineSuspiciousFile($path, 'executable-in-writable-directory');
                }
            }
        }
    }

    return $findings;
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

/**
 * Render avatar content (img jika foto ada, fallback jika tidak).
 * Dipakai kartu/timeline yang menampilkan avatar siswa/guru.
 */
function getPersonAvatarHtml($foto, string $fallbackHtml = '', string $imgClass = 'att-avatar-img'): string {
    $foto = trim((string)$foto);
    if ($foto !== '') {
        $file = basename(str_replace('\\', '/', $foto));
        if ($file !== '') {
            // Guru: uploads/ | Siswa: assets/img/siswa/
            $locations = [
                ['uploads/' . $file, '../uploads/' . rawurlencode($file)],
                ['assets/img/siswa/' . $file, '../assets/img/siswa/' . rawurlencode($file)],
            ];
            foreach ($locations as $loc) {
                if (is_file(dirname(__DIR__) . '/' . $loc[0])) {
                    return '<img class="' . htmlspecialchars($imgClass, ENT_QUOTES, 'UTF-8') . '" src="'
                        . $loc[1] . '" alt="">';
                }
            }
        }
    }
    return $fallbackHtml;
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

function normalizeAgendaDashboardName(string $name): string {
    $name = strtolower(trim($name));
    $name = preg_replace('/\b(kegiatan|agenda|acara)\b/', ' ', $name);
    $name = preg_replace('/\b20\d{2}\s*\/\s*20\d{2}\b/', '', $name);
    $name = preg_replace('/\b20\d{2}\b/', '', $name);
    $name = preg_replace('/[^a-z0-9]+/u', ' ', (string)$name);
    return trim((string)preg_replace('/\s+/', ' ', (string)$name));
}

function getAgendaDashboardTahunAjaranScore($tahunAjaran): int {
    if (preg_match('/\b(20\d{2})\s*\/\s*(20\d{2})\b/', (string)$tahunAjaran, $m)) {
        return ((int)$m[1] * 10000) + (int)$m[2];
    }

    if (preg_match('/\b(20\d{2})\b/', (string)$tahunAjaran, $m)) {
        return (int)$m[1] * 10000;
    }

    return 0;
}

function shouldReplaceDashboardAgenda(array $incoming, array $existing): bool {
    $incomingScore = getAgendaDashboardTahunAjaranScore($incoming['tahun_ajaran'] ?? '');
    $existingScore = getAgendaDashboardTahunAjaranScore($existing['tahun_ajaran'] ?? '');

    if ($incomingScore !== $existingScore) {
        return $incomingScore > $existingScore;
    }

    $incomingId = (int)($incoming['id_kalender'] ?? 0);
    $existingId = (int)($existing['id_kalender'] ?? 0);
    if ($incomingId !== $existingId) {
        return $incomingId > $existingId;
    }

    return strlen((string)($incoming['nama_kegiatan'] ?? '')) > strlen((string)($existing['nama_kegiatan'] ?? ''));
}

function getAgendaBulanBerjalan(PDO $pdo, ?string $date = null): array {
    $date = $date ?: date('Y-m-d');
    $timestamp = strtotime($date) ?: time();
    $rangeStart = date('Y-m-01', $timestamp);
    $rangeEnd = date('Y-m-t', $timestamp);

    try {
        $stmt = $pdo->prepare(
            "SELECT id_kalender, nama_kegiatan, tgl_mulai, tgl_selesai, tahun_ajaran, warna
             FROM tb_kalender_pendidikan
             WHERE tgl_mulai <= ? AND tgl_selesai >= ?
             ORDER BY tgl_mulai ASC, tgl_selesai ASC, nama_kegiatan ASC"
        );
        $stmt->execute([$rangeEnd, $rangeStart]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tahunAjaranAktif = trim((string)(getSchoolProfile($pdo)['tahun_ajaran'] ?? ''));

        $deduped = [];
        foreach ($rows as $row) {
            $start = (string)($row['tgl_mulai'] ?? '');
            $end = (string)($row['tgl_selesai'] ?? $start);
            if ($start === '' || $end === '') {
                continue;
            }

            $name = (string)($row['nama_kegiatan'] ?? '');

            if (preg_match('/\b(20\d{2}\s*\/\s*20\d{2})\b/', $name, $m)) {
                $row['tahun_ajaran'] = str_replace(' ', '', $m[1]);
            }

            $rowTA = trim((string)($row['tahun_ajaran'] ?? ''));
            if ($tahunAjaranAktif !== '' && $rowTA !== '' && $rowTA !== $tahunAjaranAktif) {
                continue;
            }

            $displayStart = max($start, $rangeStart);
            $displayEnd = min($end, $rangeEnd);
            $key = normalizeAgendaDashboardName($name);
            if ($key === '') {
                $key = strtolower(trim($name));
            }

            if (!isset($deduped[$key])) {
                $row['_display_start'] = $displayStart;
                $row['_display_end'] = $displayEnd;
                $deduped[$key] = $row;
                continue;
            }

            $existing = $deduped[$key];
            $overlap = $displayStart <= (string)$existing['_display_end'] && $displayEnd >= (string)$existing['_display_start'];
            if (!$overlap) {
                $deduped[$key . '#' . count($deduped)] = array_merge($row, [
                    '_display_start' => $displayStart,
                    '_display_end' => $displayEnd,
                ]);
                continue;
            }

            $existingName = (string)($existing['nama_kegiatan'] ?? '');
            $keepIncomingText = strlen($name) > strlen($existingName);
            $merged = $keepIncomingText ? $row : $existing;
            $merged['_display_start'] = min((string)$existing['_display_start'], $displayStart);
            $merged['_display_end'] = max((string)$existing['_display_end'], $displayEnd);
            $merged['tgl_mulai'] = min((string)$existing['tgl_mulai'], $start);
            $merged['tgl_selesai'] = max((string)$existing['tgl_selesai'], $end);
            if (empty($merged['tahun_ajaran']) && !empty($existing['tahun_ajaran'])) {
                $merged['tahun_ajaran'] = $existing['tahun_ajaran'];
            }
            $deduped[$key] = $merged;
        }

        $rangeDeduped = [];
        foreach ($deduped as $row) {
            $rangeKey = (string)($row['_display_start'] ?? $row['tgl_mulai'] ?? '') . '|' . (string)($row['_display_end'] ?? $row['tgl_selesai'] ?? '');
            if ($rangeKey === '|') {
                $rangeDeduped[] = $row;
                continue;
            }

            if (!isset($rangeDeduped[$rangeKey]) || shouldReplaceDashboardAgenda($row, $rangeDeduped[$rangeKey])) {
                $rangeDeduped[$rangeKey] = $row;
            }
        }

        $deduped = array_values($rangeDeduped);

        usort($deduped, static function ($a, $b) {
            return strcmp((string)($a['_display_start'] ?? $a['tgl_mulai'] ?? ''), (string)($b['_display_start'] ?? $b['tgl_mulai'] ?? ''))
                ?: strcmp((string)($a['_display_end'] ?? $a['tgl_selesai'] ?? ''), (string)($b['_display_end'] ?? $b['tgl_selesai'] ?? ''))
                ?: strcmp((string)($a['nama_kegiatan'] ?? ''), (string)($b['nama_kegiatan'] ?? ''));
        });

        return array_values($deduped);
    } catch (Throwable $e) {
        return [];
    }
}

function renderDashboardAgendaBulanBerjalan(PDO $pdo, ?string $date = null): string {
    $date = $date ?: date('Y-m-d');
    $timestamp = strtotime($date) ?: time();
    $rangeStart = date('Y-m-01', $timestamp);
    $rangeEnd = date('Y-m-t', $timestamp);
    $bulanIndonesia = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $monthName = $bulanIndonesia[(int)date('n', $timestamp)] ?? date('F', $timestamp);
    $monthTitle = $monthName . ' ' . date('Y', $timestamp);
    $agenda = getAgendaBulanBerjalan($pdo, $date);
    $today = date('Y-m-d', $timestamp);

    ob_start();
    ?>
    <div class="row">
        <div class="col-12">
            <div class="card dashboard-agenda-card">
                <div class="dash-agenda-head">
                    <div class="dash-agenda-headicon"><i class="fas fa-calendar-alt"></i></div>
                    <div class="dash-agenda-headtext">
                        <div class="dash-agenda-headtitle">Agenda Bulan <?php echo htmlspecialchars($monthName, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="dash-agenda-headsub"><?php echo htmlspecialchars($monthTitle, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <?php if (!empty($agenda)): ?>
                        <span class="dash-agenda-count"><?php echo count($agenda); ?> kegiatan</span>
                    <?php endif; ?>
                </div>
                <div class="dash-agenda-body">
                    <?php if (empty($agenda)): ?>
                        <div class="dash-agenda-empty">
                            <div class="ico"><i class="fas fa-calendar-check"></i></div>
                            <div class="ttl">Belum ada agenda bulan ini</div>
                            <div class="sub">Agenda sekolah akan tampil di sini.</div>
                        </div>
                    <?php else: ?>
                        <?php
                        $hariIndonesia = ['Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'];
                        $formatHariTanggal = static function (string $d) use ($hariIndonesia, $bulanIndonesia): string {
                            $ts = strtotime($d);
                            if (!$ts) {
                                return $d;
                            }
                            $namaHari = $hariIndonesia[date('l', $ts)] ?? date('l', $ts);
                            $namaBulan = $bulanIndonesia[(int)date('n', $ts)] ?? date('F', $ts);
                            return $namaHari . ', ' . date('j', $ts) . ' ' . $namaBulan . ' ' . date('Y', $ts);
                        };
                        ?>
                        <div class="dashboard-agenda-list">
                            <?php foreach ($agenda as $item): ?>
                                <?php
                                $start = (string)($item['tgl_mulai'] ?? '');
                                $end = (string)($item['tgl_selesai'] ?? $start);
                                $displayStart = (string)($item['_display_start'] ?? max($start, $rangeStart));
                                $displayEnd = (string)($item['_display_end'] ?? min($end, $rangeEnd));
                                $isToday = $start <= $today && $end >= $today;
                                $dateBoxDate = $isToday ? $today : $displayStart;
                                $dateLabel = $displayStart === $displayEnd
                                    ? $formatHariTanggal($displayStart)
                                    : $formatHariTanggal($displayStart) . ' s.d. ' . $formatHariTanggal($displayEnd);
                                $bulanPendek = [1 => 'JAN', 'FEB', 'MAR', 'APR', 'MEI', 'JUN', 'JUL', 'AGS', 'SEP', 'OKT', 'NOV', 'DES'];
                                $monthShort = $bulanPendek[(int)date('n', strtotime($dateBoxDate))] ?? strtoupper(date('M', strtotime($dateBoxDate)));
                                $warnaMap = [
                                    'danger'  => '#fc544b',
                                    'success' => '#47c363',
                                    'primary' => '#6777ef',
                                    'warning' => '#ffa426',
                                    'info'    => '#3abaf4',
                                ];
                                $itemWarna = (string)($item['warna'] ?? 'info');
                                $itemColor = $warnaMap[$itemWarna] ?? $warnaMap['info'];
                                ?>
                                <div class="dashboard-agenda-item <?php echo $isToday ? 'is-today' : ''; ?>" style="--tone: <?php echo htmlspecialchars($itemColor, ENT_QUOTES, 'UTF-8'); ?>; border: none !important; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12), 0 2px 6px rgba(15, 23, 42, 0.06) !important;">
                                    <div class="agenda-date-box" style="background: <?php echo htmlspecialchars($itemColor, ENT_QUOTES, 'UTF-8'); ?>14; border: none !important;">
                                        <div class="agenda-day"><?php echo htmlspecialchars(date('d', strtotime($dateBoxDate)), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="agenda-month" style="background: <?php echo htmlspecialchars($itemColor, ENT_QUOTES, 'UTF-8'); ?>; color: #fff;"><?php echo htmlspecialchars($monthShort, ENT_QUOTES, 'UTF-8'); ?></div>
                                    </div>
                                    <div class="agenda-content">
                                        <div class="agenda-title">
                                            <span class="agenda-title-text"><?php echo htmlspecialchars((string)$item['nama_kegiatan'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php if ($isToday): ?>
                                                <span class="agenda-today-chip">Hari ini</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="agenda-meta">
                                            <span class="agenda-chip"><i class="far fa-clock"></i> <?php echo htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php if (!empty($item['tahun_ajaran'])): ?>
                                                <span class="agenda-chip"><i class="fas fa-graduation-cap"></i> Tahun Ajaran <?php echo htmlspecialchars((string)$item['tahun_ajaran'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <style>
        .dashboard-agenda-card { border-radius: 14px !important; border: none !important; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08), 0 2px 6px rgba(15, 23, 42, 0.04) !important; overflow: hidden; position: relative; }
        .dashboard-agenda-card::before { display: none !important; }
        .dash-agenda-head { display: flex; align-items: center; gap: 12px; padding: 16px 18px 12px; }
        .dash-agenda-headicon { width: 44px; height: 44px; flex: 0 0 44px; border-radius: 13px; background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 18px; box-shadow: 0 4px 12px rgba(217, 119, 6, .25); }
        .dash-agenda-headtext { flex: 1 1 auto; min-width: 0; }
        .dash-agenda-headtitle { font-size: 15px; font-weight: 800; color: #0f172a; line-height: 1.25; }
        .dash-agenda-headsub { font-size: 11.5px; color: #64748b; margin-top: 2px; line-height: 1.3; }
        .dash-agenda-count { flex: 0 0 auto; font-size: 10.5px; font-weight: 800; color: #b45309; background: #fffbeb; border: 1px solid #fde68a; border-radius: 999px; padding: 5px 10px; white-space: nowrap; }
        .dash-agenda-body { padding: 0 18px 16px; }
        .dashboard-agenda-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 10px; }
        .dashboard-agenda-item { display: flex; align-items: center; gap: 12px; background: #fff; border: none !important; border-left: none !important; border-radius: 14px; padding: 10px 12px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12), 0 2px 6px rgba(15, 23, 42, 0.06) !important; transition: transform .2s ease, box-shadow .2s ease; }
        .dashboard-agenda-item:hover { transform: translateY(-3px); box-shadow: 0 14px 30px rgba(15, 23, 42, 0.18) !important; }
        .dashboard-agenda-item.is-today { background: #f8fff9; border: none !important; border-left: none !important; box-shadow: 0 8px 24px rgba(16, 185, 129, 0.22), 0 2px 6px rgba(15, 23, 42, 0.06) !important; }
        .agenda-date-box { flex: 0 0 56px; min-width: 56px; border-radius: 13px; overflow: hidden; text-align: center; border: none !important; }
        .agenda-day { font-size: 19px; line-height: 1.25; font-weight: 900; padding-top: 5px; color: #0f172a; letter-spacing: -0.5px; }
        .agenda-month { font-size: 9.5px; font-weight: 800; letter-spacing: .5px; padding: 3px 0 4px; }
        .agenda-content { min-width: 0; flex: 1 1 auto; }
        .agenda-title { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
        .agenda-title-text { font-size: 13.5px; font-weight: 800; color: #0f172a; line-height: 1.3; }
        .agenda-today-chip { font-size: 9.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .4px; color: #047857; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 999px; padding: 2px 8px; line-height: 1.4; }
        .agenda-meta { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 5px; }
        .agenda-chip { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 700; color: #475569; background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 8px; padding: 2.5px 8px; line-height: 1.4; }
        .agenda-chip i { font-size: 9.5px; color: var(--tone, #3b82f6); }
        .dash-agenda-empty { text-align: center; padding: 24px 16px; background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 14px; }
        .dash-agenda-empty .ico { width: 48px; height: 48px; margin: 0 auto 10px; border-radius: 14px; background: #fff7ed; color: #f59e0b; display: flex; align-items: center; justify-content: center; font-size: 22px; }
        .dash-agenda-empty .ttl { font-size: 13px; font-weight: 800; color: #0f172a; }
        .dash-agenda-empty .sub { font-size: 11.5px; color: #64748b; margin-top: 3px; }
        @media (max-width: 767.98px) {
            .dash-agenda-head { padding: 12px 14px 10px; }
            .dash-agenda-headicon { width: 38px; height: 38px; flex: 0 0 38px; border-radius: 11px; font-size: 16px; }
            .dash-agenda-headtitle { font-size: 13.5px; }
            .dash-agenda-body { padding: 0 14px 14px; }
            .agenda-title-text { font-size: 12.5px; }
        }
        @media (max-width: 575.98px) {
            .dashboard-agenda-list { grid-template-columns: 1fr; }
        }
    </style>
    <?php
    return ob_get_clean();
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
        $rangeStart = sprintf('%04d-%02d-01', $year, $month);
        $rangeEnd = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, (int)$month, (int)$year));
        $query .= " AND tgl_mulai <= ? AND tgl_selesai >= ?";
        $params = [$rangeEnd, $rangeStart];
    } else {
        $rangeStart = sprintf('%04d-01-01', $year);
        $rangeEnd = sprintf('%04d-12-31', $year);
        $query .= " AND tgl_mulai <= ? AND tgl_selesai >= ?";
        $params = [$rangeEnd, $rangeStart];
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

/**
 * Batas jam masuk untuk menentukan status "Tepat Waktu" / "Terlambat".
 * Default 07:10:00 (selaras dengan pengisian kehadiran di dashboard guru). Bisa
 * dioverride lewat kolom tb_profil_madrasah.batas_jam_masuk bila tersedia.
 */
function getTeacherLateThreshold(PDO $pdo): string {
    $default = '07:10:00';
    try {
        if (dbTableExists($pdo, 'tb_profil_madrasah') && dbColumnExists($pdo, 'tb_profil_madrasah', 'batas_jam_masuk')) {
            $stmt = $pdo->query('SELECT batas_jam_masuk FROM tb_profil_madrasah ORDER BY id ASC LIMIT 1');
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            $val = trim((string)($row['batas_jam_masuk'] ?? ''));
            if ($val !== '' && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $val)) {
                if (strlen($val) === 4) { $val = '0' . $val; }
                return strlen($val) === 5 ? $val . ':00' : $val;
            }
        }
    } catch (Throwable $e) {}
    return $default;
}

/**
 * Label ketepatan waktu guru dari waktu input.
 * Menerima 'HH:MM:SS' maupun 'YYYY-MM-DD HH:MM:SS'.
 *
 * @return array{label:string,late:bool}|null null bila waktu kosong/tidak valid
 */
function getTeacherPunctuality(PDO $pdo, ?string $waktu_input): ?array {
    if ($waktu_input === null || trim($waktu_input) === '') {
        return null;
    }
    $ts = strtotime($waktu_input);
    if ($ts === false) {
        return null;
    }
    $threshold = getTeacherLateThreshold($pdo);
    $threshold_ts = strtotime(date('Y-m-d', $ts) . ' ' . $threshold);
    if ($threshold_ts === false) {
        return null;
    }
    $late = $ts > $threshold_ts;
    return [
        'label' => $late ? 'Terlambat' : 'Tepat Waktu',
        'late'  => $late,
    ];
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

function get_forum_notifications(PDO $pdo, int $limit = 10, ?string $user_key = null): array {
    if ($user_key === null) {
        $user_key = function_exists('get_current_user_key') ? get_current_user_key() : 'user_' . ($_SESSION['user_id'] ?? 0);
    }
    try {
        $st = $pdo->prepare("
            SELECT n.*, (CASE WHEN r.notif_id IS NOT NULL THEN 1 ELSE 0 END) AS is_read
            FROM tb_notifikasi n
            LEFT JOIN tb_notifikasi_read r ON r.notif_id = n.id AND r.user_key = ?
            WHERE n.created_at >= NOW() - INTERVAL 24 HOUR
              AND (n.actor_key IS NULL OR n.actor_key != ?)
              AND (n.message LIKE 'Forum Guru:%' OR (n.message LIKE '%mencolek%' AND n.message LIKE '%[Forum%'))
            ORDER BY n.created_at DESC
            LIMIT ?
        ");
        $st->bindValue(1, $user_key, PDO::PARAM_STR);
        $st->bindValue(2, $user_key, PDO::PARAM_STR);
        $st->bindValue(3, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function get_diskusi_notifications(PDO $pdo, int $limit = 10, ?string $user_key = null): array {
    if ($user_key === null) {
        $user_key = function_exists('get_current_user_key') ? get_current_user_key() : 'user_' . ($_SESSION['user_id'] ?? 0);
    }
    try {
        $st = $pdo->prepare("
            SELECT n.*, (CASE WHEN r.notif_id IS NOT NULL THEN 1 ELSE 0 END) AS is_read
            FROM tb_notifikasi n
            LEFT JOIN tb_notifikasi_read r ON r.notif_id = n.id AND r.user_key = ?
            WHERE n.created_at >= NOW() - INTERVAL 24 HOUR
              AND (n.actor_key IS NULL OR n.actor_key != ?)
              AND (n.message LIKE 'Diskusi Kelas:%' OR (n.message LIKE '%mencolek%' AND n.message LIKE '%[Diskusi%'))
            ORDER BY n.created_at DESC
            LIMIT ?
        ");
        $st->bindValue(1, $user_key, PDO::PARAM_STR);
        $st->bindValue(2, $user_key, PDO::PARAM_STR);
        $st->bindValue(3, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function get_forum_unread_notifications(PDO $pdo, int $limit = 10, ?string $user_key = null): array {
    return get_forum_notifications($pdo, $limit, $user_key);
}

function get_diskusi_unread_notifications(PDO $pdo, int $limit = 10, ?string $user_key = null): array {
    return get_diskusi_notifications($pdo, $limit, $user_key);
}

function get_forum_new_count(PDO $pdo): int {
    $list = get_forum_notifications($pdo, 50);
    $cnt = 0;
    foreach ($list as $item) {
        if (empty($item['is_read'])) $cnt++;
    }
    return $cnt;
}

function get_diskusi_new_count(PDO $pdo, int $id_kelas = 0): int {
    $list = get_diskusi_notifications($pdo, 50);
    $cnt = 0;
    foreach ($list as $item) {
        if (empty($item['is_read'])) $cnt++;
    }
    return $cnt;
}

/**
 * Sinkronisasi otomatis status agenda kelas:
 * 1. 'Rencana': Hari ini < tanggal_mulai.
 * 2. 'Berjalan': tanggal_mulai <= hari ini < tanggal_selesai (atau mulai <= hari ini bila tanpa selesai).
 * 3. 'Selesai': hari ini >= tanggal_selesai (berlaku sejak pukul 00.00).
 */
function sync_agenda_statuses(PDO $pdo): void {
    try {
        $pdo->exec("
            UPDATE tb_agenda_kelas
            SET status = 'Selesai'
            WHERE COALESCE(tanggal_selesai, tanggal_mulai, tanggal, CURRENT_DATE()) <= CURRENT_DATE()
              AND (status IS NULL OR status != 'Selesai')
        ");
        $pdo->exec("
            UPDATE tb_agenda_kelas
            SET status = 'Berjalan'
            WHERE COALESCE(tanggal_mulai, tanggal, CURRENT_DATE()) <= CURRENT_DATE()
              AND COALESCE(tanggal_selesai, tanggal_mulai, tanggal, DATE_ADD(CURRENT_DATE(), INTERVAL 1 DAY)) > CURRENT_DATE()
        ");
        $pdo->exec("
            UPDATE tb_agenda_kelas
            SET status = 'Rencana'
            WHERE COALESCE(tanggal_mulai, tanggal, CURRENT_DATE()) > CURRENT_DATE()
        ");
    } catch (Throwable $e) {}
}

/**
 * Auto-update status tindak lanjut:
 * Jika tanggal_selesai IS NOT NULL dan tanggal_selesai <= CURRENT_DATE()
 * dan status belum 'Selesai' (dan tidak 'Dibatalkan'), maka status otomatis menjadi 'Selesai'.
 */
function sync_tindak_lanjut_statuses(PDO $pdo): void {
    try {
        $today = date('Y-m-d');
        $st = $pdo->prepare("
            UPDATE tb_tindak_lanjut_wali
            SET status = 'Selesai'
            WHERE status NOT IN ('Selesai','Dibatalkan')
              AND (
                (tanggal_selesai IS NOT NULL AND CAST(tanggal_selesai AS CHAR) NOT IN ('', '0000-00-00') AND tanggal_selesai <= ?)
                OR ((tanggal_selesai IS NULL OR CAST(tanggal_selesai AS CHAR) IN ('', '0000-00-00')) AND target_selesai IS NOT NULL AND CAST(target_selesai AS CHAR) NOT IN ('', '0000-00-00') AND target_selesai <= ?)
              )
        ");
        $st->execute([$today, $today]);
    } catch (Throwable $e) {
        error_log('sync_tindak_lanjut_statuses: ' . $e->getMessage());
    }
}

function tl_wali_effective_status(array $row, ?string $today = null): string {
    $today = $today ?: date('Y-m-d');
    $st = trim((string)($row['status'] ?? ''));
    if ($st === 'Dibatalkan' || $st === 'Selesai') {
        return $st;
    }
    $tgl = trim((string)($row['tanggal_selesai'] ?? ''));
    $tgt = trim((string)($row['target_selesai'] ?? ''));
    if ($tgl === '0000-00-00') $tgl = '';
    if ($tgt === '0000-00-00') $tgt = '';
    if ($tgl !== '' && $tgl <= $today) {
        return 'Selesai';
    }
    if ($tgl === '' && $tgt !== '' && $tgt <= $today) {
        return 'Selesai';
    }
    return $st !== '' ? $st : 'Proses';
}

/**
 * Sinkronisasi otomatis status pembinaan siswa:
 * 1. 'Berjalan': Belum masuk tindak lanjut.
 * 2. 'Dalam Pemantauan': Sudah masuk tindak lanjut (status 'Rencana' atau 'Proses').
 * 3. 'Selesai': Status tindak lanjut berstatus 'Selesai'.
 */
function sync_pembinaan_statuses(PDO $pdo): void {
    try {
        sync_tindak_lanjut_statuses($pdo);
        $pdo->exec("
            UPDATE tb_tindak_lanjut_wali t
            JOIN tb_pembinaan_siswa b ON (t.id_konseling IS NOT NULL AND t.id_konseling = b.id_konseling)
            SET t.id_pembinaan = b.id
            WHERE t.id_pembinaan IS NULL
        ");
        $pdo->exec("
            UPDATE tb_pembinaan_siswa b
            LEFT JOIN tb_tindak_lanjut_wali t ON (t.id_pembinaan = b.id OR (b.id_konseling IS NOT NULL AND t.id_konseling = b.id_konseling))
            SET b.status = 'Berjalan'
            WHERE t.id IS NULL
        ");
        $pdo->exec("
            UPDATE tb_pembinaan_siswa b
            JOIN tb_tindak_lanjut_wali t ON (t.id_pembinaan = b.id OR (b.id_konseling IS NOT NULL AND t.id_konseling = b.id_konseling))
            SET b.status = 'Dalam Pemantauan'
            WHERE (t.status IS NULL OR t.status != 'Selesai')
        ");
        $pdo->exec("
            UPDATE tb_pembinaan_siswa b
            JOIN tb_tindak_lanjut_wali t ON (t.id_pembinaan = b.id OR (b.id_konseling IS NOT NULL AND t.id_konseling = b.id_konseling))
            SET b.status = 'Selesai'
            WHERE t.status = 'Selesai'
        ");
    } catch (Throwable $e) {}
}

/**
 * Sinkronisasi status konseling:
 * - 'Pembinaan' bila sudah dibuatkan data pembinaan dan belum Selesai.
 * - 'Selesai' bila belum masuk pembinaan (ringan) atau pembinaan/TL terkait sudah Selesai.
 */
function sync_konseling_statuses(PDO $pdo): void {
    try {
        sync_pembinaan_statuses($pdo);
        $pdo->exec("
            UPDATE tb_konseling_awal k
            JOIN tb_pembinaan_siswa b ON b.id_konseling = k.id
            LEFT JOIN tb_tindak_lanjut_wali t ON t.id_pembinaan = b.id
            SET k.status = 'Selesai'
            WHERE b.status = 'Selesai' OR t.status = 'Selesai'
        ");
        $pdo->exec("
            UPDATE tb_konseling_awal k
            JOIN tb_pembinaan_siswa b ON b.id_konseling = k.id
            LEFT JOIN tb_tindak_lanjut_wali t ON t.id_pembinaan = b.id
            SET k.status = 'Pembinaan'
            WHERE (b.status IS NULL OR b.status != 'Selesai')
              AND (t.status IS NULL OR t.status != 'Selesai')
        ");
    } catch (Throwable $e) {}
}

/**
 * Sinkronisasi otomatis status pelanggaran siswa:
 * 1. 'Dicatat': Belum masuk pembinaan.
 * 2. 'Ditindaklanjuti': Sudah masuk pembinaan ATAU tindak lanjut yang belum status 'Selesai'.
 * 3. 'Selesai': Status tindak lanjut (atau pembinaan) berstatus 'Selesai'.
 */
function sync_pelanggaran_statuses(PDO $pdo): void {
    try {
        sync_pembinaan_statuses($pdo);
        $pdo->exec("
            UPDATE tb_pelanggaran_siswa p
            LEFT JOIN tb_pembinaan_siswa b ON b.id_pelanggaran = p.id
            SET p.status = 'Dicatat'
            WHERE b.id IS NULL
        ");
        $pdo->exec("
            UPDATE tb_pelanggaran_siswa p
            JOIN tb_pembinaan_siswa b ON b.id_pelanggaran = p.id
            LEFT JOIN tb_tindak_lanjut_wali t ON t.id_pembinaan = b.id
            SET p.status = 'Ditindaklanjuti'
            WHERE (t.status IS NULL OR t.status != 'Selesai')
              AND (b.status IS NULL OR b.status != 'Selesai')
        ");
        $pdo->exec("
            UPDATE tb_pelanggaran_siswa p
            JOIN tb_pembinaan_siswa b ON b.id_pelanggaran = p.id
            LEFT JOIN tb_tindak_lanjut_wali t ON t.id_pembinaan = b.id
            SET p.status = 'Selesai'
            WHERE t.status = 'Selesai' OR b.status = 'Selesai'
        ");
    } catch (Throwable $e) {}
}

// ============================================================
// Simpan kehadiran satu siswa (auto-save tanpa tombol simpan)
// $context: harian | les | sholat_dhuha | sholat_berjamaah
// ============================================================
if (!function_exists('simadSaveSingleAttendance')) {
    function simadSaveSingleAttendance(PDO $pdo, string $context, int $id_siswa, string $tanggal, string $status, $id_guru = null, bool $sync = true): array {
        $id_siswa = (int)$id_siswa;
        $tanggal  = trim((string)$tanggal);
        $status   = trim((string)$status);

        if ($id_siswa <= 0 || $tanggal === '') {
            return ['success' => false, 'error' => 'Data tidak lengkap.'];
        }

        try {
            if ($context === 'harian') {
                if (!in_array($status, ['', 'Hadir', 'Sakit', 'Izin', 'Alpa', 'Terlambat'], true)) {
                    return ['success' => false, 'error' => 'Status tidak valid.'];
                }
                $check = $pdo->prepare("SELECT * FROM tb_absensi WHERE id_siswa = ? AND tanggal = ?");
                $check->execute([$id_siswa, $tanggal]);
                $existing = $check->fetch(PDO::FETCH_ASSOC);

                if ($status === '') {
                    if ($existing) {
                        $d = $pdo->prepare("DELETE FROM tb_absensi WHERE id_siswa = ? AND tanggal = ?");
                        $d->execute([$id_siswa, $tanggal]);
                    }
                    return ['success' => true];
                }

                $now = date('H:i:s');
                if ($existing) {
                    $u = $pdo->prepare("UPDATE tb_absensi SET keterangan = ?, jam_masuk = IF(? = 'Hadir', IF(jam_masuk IS NULL, ?, jam_masuk), NULL) WHERE id_siswa = ? AND tanggal = ?");
                    $u->execute([$status, $status, $now, $id_siswa, $tanggal]);
                } else {
                    $jam = ($status === 'Hadir') ? $now : null;
                    $i = $pdo->prepare("INSERT INTO tb_absensi (id_siswa, tanggal, keterangan, id_guru, jam_masuk) VALUES (?, ?, ?, ?, ?)");
                    $i->execute([$id_siswa, $tanggal, $status, $id_guru, $jam]);
                }
                return ['success' => true];
            }

            if ($context === 'les') {
                if (!in_array($status, ['', 'Hadir', 'Sakit', 'Izin', 'Alpa'], true)) {
                    return ['success' => false, 'error' => 'Status tidak valid.'];
                }
                $check = $pdo->prepare("SELECT id_absensi_les FROM tb_absensi_les WHERE id_siswa = ? AND tanggal = ?");
                $check->execute([$id_siswa, $tanggal]);
                $existing = $check->fetch(PDO::FETCH_ASSOC);

                if ($status === '') {
                    if ($existing) {
                        $d = $pdo->prepare("DELETE FROM tb_absensi_les WHERE id_absensi_les = ?");
                        $d->execute([$existing['id_absensi_les']]);
                    }
                    return ['success' => true];
                }

                if ($existing) {
                    $u = $pdo->prepare("UPDATE tb_absensi_les SET status = ?, waktu_input = NOW() WHERE id_absensi_les = ?");
                    $u->execute([$status, $existing['id_absensi_les']]);
                } else {
                    $i = $pdo->prepare("INSERT INTO tb_absensi_les (id_siswa, tanggal, status) VALUES (?, ?, ?)");
                    $i->execute([$id_siswa, $tanggal, $status]);
                }
                return ['success' => true];
            }

            if ($context === 'sholat_dhuha' || $context === 'sholat_berjamaah') {
                if (!in_array($status, ['Hadir', 'Tidak Hadir', 'Berhalangan'], true)) {
                    return ['success' => false, 'error' => 'Status tidak valid.'];
                }
                $primary = ($context === 'sholat_dhuha') ? 'tb_sholat_dhuha' : 'tb_sholat';
                $tables  = [$primary];
                if ($sync) {
                    $tables[] = ($context === 'sholat_dhuha') ? 'tb_sholat' : 'tb_sholat_dhuha';
                }
                foreach ($tables as $tbl) {
                    $check = $pdo->prepare("SELECT * FROM $tbl WHERE id_siswa = ? AND tanggal = ?");
                    $check->execute([$id_siswa, $tanggal]);
                    if ($check->rowCount() > 0) {
                        $u = $pdo->prepare("UPDATE $tbl SET status = ? WHERE id_siswa = ? AND tanggal = ?");
                        $u->execute([$status, $id_siswa, $tanggal]);
                    } else {
                        $i = $pdo->prepare("INSERT INTO $tbl (id_siswa, tanggal, status) VALUES (?, ?, ?)");
                        $i->execute([$id_siswa, $tanggal, $status]);
                    }
                }
                return ['success' => true];
            }

            return ['success' => false, 'error' => 'Konteks tidak dikenal.'];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Gagal menyimpan kehadiran: ' . $e->getMessage()];
        }
    }
}

// ============================================================
// Label aktor penginput kehadiran harian (guru/wali/admin/kepala/tu)
// ============================================================
if (!function_exists('simadAktorKehadiranLabel')) {
    function simadAktorKehadiranLabel(PDO $pdo, string $nama_kelas = ''): string {
        $level = function_exists('getUserLevel') ? getUserLevel() : strtolower(trim((string)($_SESSION['level'] ?? '')));

        if ($level === 'guru' || $level === 'wali') {
            $peran = ($level === 'wali') ? 'Wali Kelas' : 'Guru Kelas';

            $nama = '';
            $gid = function_exists('getCurrentGuruId') ? (int)getCurrentGuruId($pdo) : 0;
            if ($gid > 0) {
                try {
                    $s = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ? LIMIT 1");
                    $s->execute([$gid]);
                    $nama = trim((string)$s->fetchColumn());
                } catch (Throwable $e) {}
            }
            if ($nama === '') {
                $nama = trim((string)($_SESSION['nama_guru'] ?? ''));
            }

            $kelas = trim($nama_kelas);
            // Wali: pakai kelas yang diampu wali tersebut
            if ($level === 'wali' && $nama !== '') {
                try {
                    $s = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE wali_kelas = ? LIMIT 1");
                    $s->execute([$nama]);
                    $k = trim((string)$s->fetchColumn());
                    if ($k !== '') {
                        $kelas = $k;
                    }
                } catch (Throwable $e) {}
            }

            if ($nama === '') {
                return $peran . ($kelas !== '' ? ' ' . $kelas : '');
            }
            return $nama . ' (' . $peran . ($kelas !== '' ? ' ' . $kelas : '') . ')';
        }

        if ($level === 'admin') {
            return 'Admin';
        }
        if ($level === 'tata_usaha') {
            return 'Tata Usaha';
        }
        if ($level === 'bendahara') {
            return 'Bendahara';
        }
        if ($level === 'kepala_madrasah') {
            return 'Kepala Madrasah';
        }
        return $level !== '' ? ucfirst($level) : 'Pengguna';
    }
}

// ============================================================
// Progres presensi harian per kelas (untuk notifikasi admin & kepala)
// Mengembalikan: ['kelas' => nama, 'total' => n, 'terisi' => n, 'belum' => [nama kelas]]
// ============================================================
if (!function_exists('simadProgresPresensiHarian')) {
    function simadProgresPresensiHarian(PDO $pdo, string $tanggal, int $id_kelas = 0): array {
        $out = ['kelas' => '', 'total' => 0, 'terisi' => 0, 'belum' => []];
        try {
            $st = $pdo->prepare("
                SELECT k.id_kelas, k.nama_kelas,
                       (SELECT COUNT(*) FROM tb_siswa s WHERE s.id_kelas = k.id_kelas) AS total,
                       (SELECT COUNT(*) FROM tb_absensi a
                          JOIN tb_siswa s2 ON s2.id_siswa = a.id_siswa
                         WHERE s2.id_kelas = k.id_kelas
                           AND a.tanggal = ?
                           AND a.keterangan IS NOT NULL
                           AND a.keterangan <> '') AS terisi
                FROM tb_kelas k
                ORDER BY k.id_kelas
            ");
            $st->execute([$tanggal]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return $out;
        }

        foreach ($rows as $r) {
            $total  = (int)($r['total'] ?? 0);
            $terisi = (int)($r['terisi'] ?? 0);
            $nama   = trim((string)($r['nama_kelas'] ?? ''));
            if ($nama === '') {
                $nama = 'Kelas #' . (int)$r['id_kelas'];
            }
            if ((int)$r['id_kelas'] === $id_kelas) {
                $out['kelas']  = $nama;
                $out['total']  = $total;
                $out['terisi'] = $terisi;
            }
            if ($total > 0 && $terisi === 0) {
                $label = (stripos($nama, 'kelas') === false) ? ('Kelas ' . $nama) : $nama;
                $out['belum'][] = $label;
            }
        }
        return $out;
    }
}

// ============================================================
// Kirim notifikasi perubahan kehadiran harian SATU siswa.
// $source: 'siswa'  -> siswa mengisi sendiri dari dashboard
//          'petugas'-> guru/wali/admin menginput kehadiran
// Notifikasi bersifat global (semua peran melihat), aktor asal
// tidak melihat notifikasinya sendiri.
// ============================================================
if (!function_exists('simadNotifKehadiranHarian')) {
    function simadNotifKehadiranHarian(PDO $pdo, int $id_siswa, string $tanggal, string $status, string $source = 'petugas'): void {
        $id_siswa = (int)$id_siswa;
        $status   = trim($status);
        if ($id_siswa <= 0 || $status === '') {
            return;
        }

        $row = [];
        try {
            $st = $pdo->prepare("SELECT s.nama_siswa, s.nisn, s.id_kelas, k.nama_kelas
                                 FROM tb_siswa s
                                 LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas
                                 WHERE s.id_siswa = ? LIMIT 1");
            $st->execute([$id_siswa]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $row = [];
        }

        $nama_siswa = trim((string)($row['nama_siswa'] ?? '')) ?: ('Siswa #' . $id_siswa);
        $nama_kelas = trim((string)($row['nama_kelas'] ?? ''));
        $nisn       = trim((string)($row['nisn'] ?? ''));
        $id_kelas   = (int)($row['id_kelas'] ?? 0);

        // Identitas memakai NISN (bukan NIS)
        $identitas = $nama_siswa;
        if ($nama_kelas !== '') {
            $identitas .= " ($nama_kelas)";
        }
        if ($nisn !== '') {
            $identitas .= " - NISN $nisn";
        }

        $ts          = strtotime($tanggal) ?: time();
        $tanggal_txt = date('d-m-Y', $ts);
        $jam         = date('H:i');
        $status_txt  = ucfirst($status);

        if ($source === 'siswa') {
            // Siswa mengisi sendiri -> guru & wali perlu tahu
            $message = "$identitas mengisi kehadiran sendiri: $status_txt pukul $jam WIB, $tanggal_txt";
        } else {
            // Input oleh guru/wali/admin -> admin & kepala perlu tahu
            // sekaligus melihat kelas mana yang belum dipresensi
            $aktor   = simadAktorKehadiranLabel($pdo, $nama_kelas);
            $progres = simadProgresPresensiHarian($pdo, $tanggal, $id_kelas);
            $message = "$aktor mengisi kehadiran $identitas: $status_txt pukul $jam WIB, $tanggal_txt.";
            if ($progres['total'] > 0) {
                $label = $progres['kelas'] !== '' ? $progres['kelas'] : 'kelas';
                if (stripos($label, 'kelas') === false) {
                    $label = 'Kelas ' . $label;
                }
                $message .= " Progres $label: " . $progres['terisi'] . '/' . $progres['total'] . ' siswa.';
            }
            if (!empty($progres['belum'])) {
                $message .= ' Kelas belum dipresensi: ' . implode(', ', $progres['belum']) . '.';
            } else {
                $message .= ' Semua kelas sudah dipresensi.';
            }
        }

        try {
            // Hindari notifikasi kembar dalam 2 menit
            $dup = $pdo->prepare("SELECT COUNT(*) FROM tb_notifikasi WHERE message = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)");
            $dup->execute([$message]);
            if ((int)$dup->fetchColumn() > 0) {
                return;
            }
        } catch (Throwable $e) {
            // Abaikan bila kolom/tabel tidak sesuai, lanjut kirim
        }

        // Penerima: siswa mengisi sendiri -> guru/wali kelas tsb.
        //            input guru/wali/admin  -> admin & kepala madrasah.
        $audience = ($source === 'siswa')
            ? ($id_kelas > 0 ? 'guru_wali_kelas_' . $id_kelas : 'guru_wali')
            : 'admin_kepala';

        try {
            createNotification($pdo, $message, 'rekap_absensi.php', 'absensi_siswa', null, $audience);
        } catch (Throwable $e) {
            // Notifikasi tidak boleh menggagalkan penyimpanan kehadiran
        }
    }
}

// ============================================================
// Notifikasi SATU KALI per penyimpanan kehadiran satu kelas.
// Dipakai guru/wali (tombol "Simpan Kehadiran Kelas").
// Tujuannya: admin & kepala bisa melihat kelas mana yang
// belum dipresensi, tanpa dibanjiri notif per siswa.
// ============================================================
if (!function_exists('simadNotifKehadiranKelas')) {
    function simadNotifKehadiranKelas(PDO $pdo, int $id_kelas, string $tanggal, int $saved_count = 0): void {
        $id_kelas = (int)$id_kelas;
        if ($id_kelas <= 0) {
            return;
        }

        $progres = simadProgresPresensiHarian($pdo, $tanggal, $id_kelas);

        $nama_kelas = $progres['kelas'];
        if ($nama_kelas === '') {
            try {
                $s = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas = ? LIMIT 1");
                $s->execute([$id_kelas]);
                $nama_kelas = trim((string)$s->fetchColumn());
            } catch (Throwable $e) {}
        }
        if ($nama_kelas === '') {
            $nama_kelas = 'Kelas #' . $id_kelas;
        }

        $aktor = simadAktorKehadiranLabel($pdo, $nama_kelas);

        // Nama kelas tanpa awalan "Kelas" agar tidak dobel ("kelas kelas VI")
        $raw_kelas = preg_replace('/^kelas\s+/i', '', $nama_kelas);

        $jam         = date('H:i');
        $tanggal_txt = date('d-m-Y', strtotime($tanggal) ?: time());

        $message = "$aktor telah mengirim kehadiran kelas $raw_kelas pada pukul $jam WIB, $tanggal_txt.";

        if (!empty($progres['belum'])) {
            $message .= ' Kelas belum dipresensi: ' . implode(', ', $progres['belum']) . '.';
        } else {
            $message .= ' Semua kelas sudah dipresensi.';
        }

        // Hindari notifikasi kembar dalam 2 menit (mis. klik simpan ganda)
        try {
            $dup = $pdo->prepare("SELECT COUNT(*) FROM tb_notifikasi WHERE message = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)");
            $dup->execute([$message]);
            if ((int)$dup->fetchColumn() > 0) {
                return;
            }
        } catch (Throwable $e) {}

        // Penerima: admin & kepala madrasah (untuk cek kelas yang belum presensi)
        try {
            createNotification($pdo, $message, 'rekap_absensi.php', 'absensi_siswa', null, 'admin_kepala');
        } catch (Throwable $e) {
            // Notifikasi tidak boleh menggagalkan penyimpanan kehadiran
        }
    }
}

// ============================================================
// Notifikasi Simpan Kehadiran Les Siswa ke Admin & Kepala
// ============================================================
if (!function_exists('simadNotifKehadiranLesSiswa')) {
    function simadNotifKehadiranLesSiswa(PDO $pdo, int $id_kelas, string $tanggal, int $saved_count = 0): void {
        $id_kelas = (int)$id_kelas;
        $nama_kelas = '';
        if ($id_kelas > 0) {
            try {
                $s = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas = ? LIMIT 1");
                $s->execute([$id_kelas]);
                $nama_kelas = trim((string)$s->fetchColumn());
            } catch (Throwable $e) {}
        }
        if ($nama_kelas === '') {
            $nama_kelas = 'Kelas VI';
        }

        $aktor = 'Petugas';
        $user_level = function_exists('getUserLevel') ? getUserLevel() : ($_SESSION['level'] ?? '');
        if ($user_level === 'wali') {
            $aktor = 'Wali Kelas ' . ($_SESSION['nama_guru'] ?? $_SESSION['nama'] ?? '');
        } elseif ($user_level === 'guru') {
            $aktor = 'Guru Pengampu ' . ($_SESSION['nama_guru'] ?? $_SESSION['nama'] ?? '');
        } elseif ($user_level === 'admin' || $user_level === 'tata_usaha') {
            $aktor = ucfirst($user_level) . ' (' . ($_SESSION['username'] ?? '') . ')';
        }

        $raw_kelas   = preg_replace('/^kelas\s+/i', '', $nama_kelas);
        $jam         = date('H:i');
        $tanggal_txt = date('d-m-Y', strtotime($tanggal) ?: time());

        $message = "$aktor telah menyimpan kehadiran les siswa kelas $raw_kelas ($saved_count siswa) pada pukul $jam WIB, $tanggal_txt.";

        // Cegah duplikasi notifikasi dalam 2 menit
        try {
            $dup = $pdo->prepare("SELECT COUNT(*) FROM tb_notifikasi WHERE message = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)");
            $dup->execute([$message]);
            if ((int)$dup->fetchColumn() > 0) {
                return;
            }
        } catch (Throwable $e) {}

        try {
            createNotification($pdo, $message, 'rekap_absensi_les_siswa.php', 'absensi_les', null, 'admin_kepala');
        } catch (Throwable $e) {
            // Notifikasi tidak boleh menggagalkan proses penyimpanan
        }
    }
}

// Sanitasi HTML dari editor kaya teks (whitelist tag/atribut).
if (!function_exists('sanitizeRichText')) {
    function sanitizeRichText(?string $html): string
    {
        $html = trim((string)$html);
        if ($html === '') {
            return '';
        }

        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text === '' && stripos($html, '<hr') === false) {
            return '';
        }

        if (!class_exists('DOMDocument')) {
            return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        }

        $allowed = [
            'p' => ['style'], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
            'ul' => [], 'ol' => [], 'li' => ['style'], 'blockquote' => ['style'], 'h1' => ['style'], 'h2' => ['style'], 'h3' => ['style'],
            'h4' => ['style'], 'h5' => ['style'], 'h6' => ['style'], 'hr' => [], 'sub' => [], 'sup' => [], 'span' => [],
            'a' => ['href', 'title', 'target', 'rel'],
            'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['style'], 'td' => ['style'],
        ];
        $drop = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'link', 'meta', 'svg', 'math'];

        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="UTF-8"><div data-rich-root="1">' . $html . '</div>';
        $loaded = $doc->loadHTML($wrapped, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded) {
            return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        }

        $xp = new DOMXPath($doc);
        $root = $xp->query('//div[@data-rich-root="1"]')->item(0);
        if (!$root) {
            return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        }

        $walk = function (DOMNode $node) use (&$walk, $allowed, $drop) {
            $children = [];
            foreach ($node->childNodes as $child) {
                $children[] = $child;
            }
            foreach ($children as $child) {
                if ($child instanceof DOMComment) {
                    $node->removeChild($child);
                    continue;
                }
                if (!$child instanceof DOMElement) {
                    continue;
                }
                $tag = strtolower($child->nodeName);
                if (in_array($tag, $drop, true)) {
                    $node->removeChild($child);
                    continue;
                }
                if (!isset($allowed[$tag])) {
                    $walk($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }

                $names = [];
                foreach ($child->attributes as $attr) {
                    $names[] = $attr->nodeName;
                }
                foreach ($names as $name) {
                    $lower = strtolower($name);
                    if (!in_array($lower, $allowed[$tag], true)) {
                        $child->removeAttribute($name);
                        continue;
                    }
                    if ($lower === 'href') {
                        $href = trim((string)$child->getAttribute('href'));
                        if ($href !== '' && !preg_match('#^(https?:|mailto:|tel:|/|\#|\?|\.{0,2}/)#i', $href)) {
                            $child->removeAttribute('href');
                        }
                    }
                    if ($lower === 'style') {
                        $style = (string)$child->getAttribute('style');
                        $safe = [];
                        if (preg_match_all('/(text-align)\s*:\s*(left|right|center|justify)/i', $style, $sm, PREG_SET_ORDER)) {
                            foreach ($sm as $hit) {
                                $safe['text-align'] = strtolower($hit[2]);
                            }
                        }
                        if ($safe) {
                            $child->setAttribute('style', 'text-align:' . $safe['text-align'] . ';');
                        } else {
                            $child->removeAttribute('style');
                        }
                    }
                }
                if ($tag === 'a' && $child->getAttribute('target') === '_blank') {
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
                $walk($child);
            }
        };
        $walk($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        $out = trim($out);
        $blankP = '<p>(?:\s|&nbsp;|<br\s*/?>)*</p>';
        $out = preg_replace('#^(?:\s*' . $blankP . ')+#i', '', $out);
        $out = preg_replace('#(?:\s*' . $blankP . ')+\s*$#i', '', $out);
        return trim((string)$out);
    }
}

// Ubah HTML editor menjadi teks polos (untuk cetak/ekspor yang tidak mendukung HTML).
if (!function_exists('richTextToPlain')) {
    function richTextToPlain(?string $html): string
    {
        $html = (string)$html;
        if ($html === '') {
            return '';
        }
        $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $html);
        $text = preg_replace('/<\/(p|div|li|h[1-6]|blockquote|tr)>/i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+\n/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }
}
