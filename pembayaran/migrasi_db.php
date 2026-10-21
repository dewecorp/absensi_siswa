<?php
/**
 * Migrasi satu-kali: menyatukan database modul Pembayaran ke database utama SIMAD.
 *
 * Sebelumnya modul Pembayaran memakai DB terpisah (`spp` lokal / `kvzveyrg_pembayaran`
 * di hosting). Setelah migrasi:
 *   - tabel pembayaran, pembayaran_arsip, jenis_bayar, pengaturan, pengguna, log_aktivitas
 *     berada di DB utama SIMAD;
 *   - tabel `siswa` dan `kelas` menjadi VIEW ke `tb_siswa` / `tb_kelas` (satu sumber data).
 *
 * Jalankan sebagai admin SIMAD sekali, lalu hapus file ini.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (!function_exists('isAuthorized') || !isAuthorized(['admin'])) {
    http_response_code(403);
    exit('Akses ditolak. Login sebagai admin SIMAD.');
}

header('Content-Type: text/plain; charset=utf-8');

$host = (string)($_SERVER['HTTP_HOST'] ?? '');
$isLocal = ($host === '' || $host === 'localhost' || $host === '127.0.0.1'
    || strpos($host, '.test') !== false || strpos($host, '.local') !== false);

$targetHost = DB_HOST;
$targetName = DB_NAME;
$targetUser = DB_USER;
$targetPass = DB_PASS;

$sourceHost = getenv('PEMBAYARAN_DB_HOST') ?: $targetHost;
$sourceName = getenv('PEMBAYARAN_DB_NAME') ?: ($isLocal ? 'spp' : 'kvzveyrg_pembayaran');
$sourceUser = getenv('PEMBAYARAN_DB_USER') ?: ($isLocal ? $targetUser : 'kvzveyrg_pembayaran');
$sourcePass = getenv('PEMBAYARAN_DB_PASS') !== false
    ? (string)getenv('PEMBAYARAN_DB_PASS')
    : ($isLocal ? $targetPass : 'sultanfattah26');

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

$db = mysqli_connect($targetHost, $targetUser, $targetPass, $targetName);
if (!$db) {
    exit('Gagal koneksi ke DB utama: ' . mysqli_connect_error());
}
mysqli_set_charset($db, 'utf8mb4');
mysqli_query($db, "SET time_zone = '+07:00'");

$sameDb = ($sourceHost === $targetHost && $sourceName === $targetName);
$src = $sameDb ? $db : @mysqli_connect($sourceHost, $sourceUser, $sourcePass, $sourceName);
if (!$src) {
    echo "PERINGATAN: DB lama ($sourceName) tidak bisa diakses. Tabel baru akan dibuat,\n";
    echo "tetapi data lama tidak disalin. Error: " . mysqli_connect_error() . "\n\n";
}
if ($src) {
    mysqli_set_charset($src, 'utf8mb4');
}

$tables = ['pengguna', 'jenis_bayar', 'pengaturan', 'pembayaran', 'pembayaran_arsip', 'log_aktivitas'];
$log = [];

function mig_log(array &$log, string $step, bool $ok, string $note = ''): void
{
    $log[] = [$step, $ok, $note];
    echo ($ok ? '[OK]   ' : '[SKIP] ') . $step . ($note !== '' ? ' — ' . $note : '') . "\n";
}

function table_obj_type(mysqli $db, string $name): ?string
{
    $stmt = mysqli_prepare($db, 'SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, 's', $name);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_row($res) : null;
    mysqli_stmt_close($stmt);
    return $row ? (string)$row[0] : null;
}

function count_rows(mysqli $db, string $table): int
{
    $r = mysqli_query($db, 'SELECT COUNT(*) AS c FROM `' . str_replace('`', '', $table) . '`');
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return (int)($row['c'] ?? 0);
}

function fk_exists(mysqli $db, string $table, string $constraint): bool
{
    $stmt = mysqli_prepare($db, 'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1');
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 'ss', $table, $constraint);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_row($res) : null;
    mysqli_stmt_close($stmt);
    return (bool)$row;
}

/**
 * Hitung baris sumber yang tidak punya pasangan di target (untuk validasi FK).
 */
function count_orphan(mysqli $db, string $sql): int
{
    $r = mysqli_query($db, $sql);
    $row = $r ? mysqli_fetch_row($r) : null;
    return (int)($row[0] ?? 0);
}

echo "=== MIGRASI DB PEMBAYARAN -> DB UTAMA SIMAD ===\n";
echo "Sumber  : {$sourceHost}/{$sourceName}\n";
echo "Tujuan  : {$targetHost}/{$targetName}\n";
echo "Sama DB : " . ($sameDb ? 'ya (hanya melengkapi tabel/views)' : 'tidak') . "\n\n";

// 1. Tabel data pembayaran.
foreach ($tables as $t) {
    $type = table_obj_type($db, $t);
    if ($type === 'VIEW') {
        mig_log($log, "Tabel $t", false, 'nama dipakai VIEW, dilewati');
        continue;
    }
    if ($type === 'BASE TABLE') {
        mig_log($log, "Tabel $t", true, 'sudah ada, ' . count_rows($db, $t) . ' baris');
    } elseif ($src && table_obj_type($src, $t) === 'BASE TABLE') {
        $ok = (bool)mysqli_query($db, "CREATE TABLE `$t` LIKE `{$sourceName}`.`$t`");
        mig_log($log, "Buat tabel $t", $ok, $ok ? 'dari DB lama' : mysqli_error($db));
    } else {
        mig_log($log, "Buat tabel $t", false, 'tidak ditemukan di DB lama');
    }
}

echo "\n";

// 2. Salin data dari DB lama.
if ($src && !$sameDb) {
    foreach ($tables as $t) {
        if (table_obj_type($db, $t) !== 'BASE TABLE' || table_obj_type($src, $t) !== 'BASE TABLE') {
            continue;
        }
        $before = count_rows($db, $t);
        $ok = (bool)mysqli_query($db, "INSERT IGNORE INTO `$t` SELECT * FROM `{$sourceName}`.`$t`");
        $after = count_rows($db, $t);
        mig_log($log, "Salin data $t", $ok, ($after - $before) . " baris baru (total $after)");
    }
}

echo "\n";

// 3. Foreign key (aman: hanya bila relasi valid).
$fkPlan = [
    ['pembayaran', 'fk_pembayaran_pengguna', 'FOREIGN KEY (`id_petugas`) REFERENCES `pengguna` (`id_pengguna`)',
        "SELECT COUNT(*) FROM `pembayaran` p LEFT JOIN `pengguna` u ON u.id_pengguna = p.id_petugas WHERE u.id_pengguna IS NULL"],
    ['pembayaran', 'fk_pembayaran_jenis_bayar', 'FOREIGN KEY (`id_jenis_bayar`) REFERENCES `jenis_bayar` (`id_jenis_bayar`)',
        "SELECT COUNT(*) FROM `pembayaran` p LEFT JOIN `jenis_bayar` j ON j.id_jenis_bayar = p.id_jenis_bayar WHERE j.id_jenis_bayar IS NULL"],
    ['log_aktivitas', 'fk_log_pengguna', 'FOREIGN KEY (`id_pengguna`) REFERENCES `pengguna` (`id_pengguna`) ON DELETE SET NULL',
        "SELECT COUNT(*) FROM `log_aktivitas` l LEFT JOIN `pengguna` u ON u.id_pengguna = l.id_pengguna WHERE l.id_pengguna IS NOT NULL AND u.id_pengguna IS NULL"],
];
foreach ($fkPlan as $fk) {
    [$t, $name, $clause, $orphanSql] = $fk;
    if (fk_exists($db, $t, $name)) {
        mig_log($log, "FK $name", true, 'sudah ada');
        continue;
    }
    if (table_obj_type($db, $t) !== 'BASE TABLE') {
        mig_log($log, "FK $name", false, "tabel $t tidak ada");
        continue;
    }
    if ($orphanSql !== null && count_orphan($db, $orphanSql) > 0) {
        mig_log($log, "FK $name", false, 'ada data yatim, FK dilewati');
        continue;
    }
    $ok = (bool)mysqli_query($db, "ALTER TABLE `$t` ADD CONSTRAINT `$name` $clause");
    mig_log($log, "FK $name", $ok, $ok ? '' : mysqli_error($db));
}

echo "\n";

// 4. VIEW siswa & kelas (satu sumber data: tb_siswa / tb_kelas).
$viewSiswa = "CREATE OR REPLACE VIEW `siswa` AS
    SELECT s.`nisn` AS `nisn`,
           s.`nama_siswa` AS `nama`,
           COALESCE(s.`id_kelas`, 0) AS `id_kelas`,
           NULL AS `alamat`,
           NULL AS `no_telp`,
           s.`jenis_kelamin` AS `jenis_kelamin`,
           s.`tempat_lahir` AS `tempat_lahir`,
           s.`tanggal_lahir` AS `tgl_lahir`,
           COALESCE(NULLIF(s.`wali`, ''), '-') AS `nama_wali`,
           CASE WHEN s.`tanggal_masuk` IS NULL THEN '' ELSE DATE_FORMAT(s.`tanggal_masuk`, '%Y') END AS `tahun_masuk`,
           s.`tanggal_masuk` AS `tanggal_masuk`
    FROM `tb_siswa` s";

$viewKelas = "CREATE OR REPLACE VIEW `kelas` AS
    SELECT k.`id_kelas` AS `id_kelas`, k.`nama_kelas` AS `nama_kelas`
    FROM `tb_kelas` k
    UNION ALL
    SELECT 0 AS `id_kelas`, 'Alumni' AS `nama_kelas`";

foreach (['siswa' => $viewSiswa, 'kelas' => $viewKelas] as $name => $sql) {
    $type = table_obj_type($db, $name);
    if ($type === 'BASE TABLE') {
        mig_log($log, "VIEW $name", false, 'nama sudah dipakai tabel biasa, dilewati');
        continue;
    }
    $ok = (bool)mysqli_query($db, $sql);
    mig_log($log, "VIEW $name", $ok, $ok ? '' : mysqli_error($db));
}

echo "\n";

// 5. Normalisasi nama kelas pada jenis_bayar (data lama memakai angka 1-6).
$kelasMap = [];
$qMap = mysqli_query($db, 'SELECT id_kelas, nama_kelas FROM tb_kelas');
while ($qMap && $rowMap = mysqli_fetch_assoc($qMap)) {
    $kelasMap[(string)$rowMap['id_kelas']] = (string)$rowMap['nama_kelas'];
}
$qJenis = mysqli_query($db, 'SELECT id_jenis_bayar, tagihan_kelas FROM jenis_bayar');
$diubah = 0;
while ($qJenis && $rowJenis = mysqli_fetch_assoc($qJenis)) {
    $raw = (string)$rowJenis['tagihan_kelas'];
    if (trim($raw) === '') {
        continue;
    }
    $tokens = [];
    foreach (array_map('trim', explode(',', $raw)) as $token) {
        if ($token === '') continue;
        $tokens[] = $kelasMap[$token] ?? $token;
    }
    $baru = implode(',', array_values(array_unique($tokens)));
    if ($baru !== $raw) {
        $stmtUp = mysqli_prepare($db, 'UPDATE jenis_bayar SET tagihan_kelas = ? WHERE id_jenis_bayar = ?');
        mysqli_stmt_bind_param($stmtUp, 'si', $baru, $rowJenis['id_jenis_bayar']);
        mysqli_stmt_execute($stmtUp);
        mysqli_stmt_close($stmtUp);
        $diubah++;
    }
}
mig_log($log, 'Normalisasi kelas pada jenis_bayar', true, $diubah . ' baris disesuaikan');

echo "\n=== VERIFIKASI ===\n";
foreach (array_merge($tables, ['siswa', 'kelas']) as $t) {
    $type = table_obj_type($db, $t);
    $count = $type ? count_rows($db, $t) : 0;
    echo str_pad($t, 20) . ($type ?? 'TIDAK ADA') . ' | ' . $count . " baris\n";
}

echo "\nSiswa SIMAD tanpa kelas: " . count_orphan($db, "SELECT COUNT(*) FROM tb_siswa WHERE id_kelas IS NULL OR id_kelas = 0") . "\n";
echo "Pembayaran tanpa siswa SIMAD: " . count_orphan($db, "SELECT COUNT(DISTINCT p.nisn) FROM pembayaran p LEFT JOIN tb_siswa s ON s.nisn = p.nisn WHERE s.nisn IS NULL") . "\n";
echo "Arsip tanpa siswa SIMAD: " . count_orphan($db, "SELECT COUNT(DISTINCT a.nisn) FROM pembayaran_arsip a LEFT JOIN tb_siswa s ON s.nisn = a.nisn WHERE s.nisn IS NULL") . "\n";

if (!$sameDb && $src) {
    echo "\nSiswa hanya ada di DB pembayaran (tidak ada di SIMAD):\n";
    $q = mysqli_query($db, "SELECT s.nisn, s.nama, s.id_kelas FROM `{$sourceName}`.`siswa` s LEFT JOIN tb_siswa t ON t.nisn = s.nisn WHERE t.nisn IS NULL ORDER BY s.nama");
    if ($q && mysqli_num_rows($q) > 0) {
        while ($row = mysqli_fetch_assoc($q)) {
            echo ' - ' . $row['nisn'] . ' | ' . $row['nama'] . ' | kelas ' . $row['id_kelas'] . "\n";
        }
    } else {
        echo " - (tidak ada)\n";
    }
}

echo "\nSelesai. Hapus file migrasi ini setelah diverifikasi.\n";
echo "Jumlah langkah: " . count($log) . "\n";
