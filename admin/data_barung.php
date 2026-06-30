<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$can_manage_barung = isAuthorized(['admin', 'tata_usaha']);
$can_view_barung = $can_manage_barung || isAuthorized(['kepala_madrasah', 'wali', 'guru']);
if (!$can_view_barung) {
    redirect('../login.php');
}

$school_profile = getSchoolProfile($pdo);
$page_title = 'Data Anggota Pramuka';

// Print signature settings (Ketua Gudep)
$print_settings_data = [
    'ketua_gudep' => $school_profile['nama_kepala'] ?? '-',
    'nta_ketua_gudep' => $school_profile['nip_kepala'] ?? '-',
    'tempat_surat' => $school_profile['tempat_jadwal'] ?? 'Padang',
    'tanggal_surat' => date('d F Y'),
];
try {
    $settings = $pdo->query("SELECT * FROM tb_pengaturan_cetak_barung LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($settings) {
        $print_settings_data['ketua_gudep'] = $settings['ketua_gudep'] ?? $print_settings_data['ketua_gudep'];
        $print_settings_data['nta_ketua_gudep'] = $settings['nta_ketua_gudep'] ?? $print_settings_data['nta_ketua_gudep'];
        $print_settings_data['tempat_surat'] = $settings['tempat_surat'] ?? $print_settings_data['tempat_surat'];
        $print_settings_data['tanggal_surat'] = $settings['tanggal_surat'] ?? $print_settings_data['tanggal_surat'];
    }
} catch (Exception $e) {
    // ignore: keep fallback from school profile
}

// DataTables
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
];

// --- Ensure schema (best-effort) ---
$schema_error = null;
try {
    // tb_tingkat_barung (created by data_tingkat_barung.php, but ensure here too)
    $stmt = $pdo->query("SHOW TABLES LIKE 'tb_tingkat_barung'");
    $exists = (bool)$stmt->fetch(PDO::FETCH_NUM);
    if (!$exists) {
        $pdo->exec("
            CREATE TABLE tb_tingkat_barung (
                id_tingkat_barung INT AUTO_INCREMENT PRIMARY KEY,
                nama_tingkat VARCHAR(100) NOT NULL,
                golongan VARCHAR(50) NOT NULL DEFAULT 'Siaga'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } else {
        $colStmt = $pdo->query("SHOW COLUMNS FROM tb_tingkat_barung LIKE 'golongan'");
        $has_col = (bool)$colStmt->fetch(PDO::FETCH_ASSOC);
        if (!$has_col) {
            $pdo->exec("ALTER TABLE tb_tingkat_barung ADD COLUMN golongan VARCHAR(50) NOT NULL DEFAULT 'Siaga'");
        }
    }
    $pdo->exec("
        UPDATE tb_tingkat_barung
        SET golongan = CASE
            WHEN LOWER(REPLACE(REPLACE(nama_tingkat, ' ', ''), '-', '')) IN ('pramula', 'mula', 'bantu', 'tata') THEN 'Siaga'
            WHEN LOWER(REPLACE(REPLACE(nama_tingkat, ' ', ''), '-', '')) IN ('praramu', 'ramu') THEN 'Penggalang'
            ELSE golongan
        END
    ");

    // tb_peserta_didik_barung (peserta didik per tingkat)
    $stmt = $pdo->query("SHOW TABLES LIKE 'tb_peserta_didik_barung'");
    $exists = (bool)$stmt->fetch(PDO::FETCH_NUM);
    if (!$exists) {
        $pdo->exec("
            CREATE TABLE tb_peserta_didik_barung (
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
    } else {
        // Ensure new columns exist
        $required_cols = [
            'tempat_lahir' => "VARCHAR(120) NULL",
            'tanggal_lahir' => "DATE NULL",
            'id_siswa' => "INT NULL",
            'status' => "ENUM('aktif','keluar') NOT NULL DEFAULT 'aktif'",
            'tanggal_masuk' => "DATETIME NULL",
            'tanggal_keluar' => "DATETIME NULL",
        ];
        foreach ($required_cols as $col => $typeDef) {
            $colStmt = $pdo->query("SHOW COLUMNS FROM tb_peserta_didik_barung LIKE '" . addslashes($col) . "'");
            $has_col = (bool)$colStmt->fetch(PDO::FETCH_ASSOC);
            if (!$has_col) {
                $pdo->exec("ALTER TABLE tb_peserta_didik_barung ADD COLUMN {$col} {$typeDef}");
                if ($col === 'id_siswa') {
                    try {
                        $pdo->exec("
                            UPDATE tb_peserta_didik_barung p
                            INNER JOIN tb_siswa s ON TRIM(IFNULL(p.nta, '')) <> ''
                              AND TRIM(IFNULL(p.nta, '')) = TRIM(IFNULL(s.nisn, ''))
                            SET p.id_siswa = s.id_siswa
                            WHERE p.id_siswa IS NULL
                        ");
                    } catch (Exception $ignored) {
                    }
                }
            }
        }
        // Pastikan data lama dianggap aktif
        try {
            $pdo->exec("UPDATE tb_peserta_didik_barung SET status = 'aktif' WHERE status IS NULL OR TRIM(status) = ''");
        } catch (Exception $ignored) {
        }
    }
} catch (Exception $e) {
    $schema_error = $e->getMessage();
}

function ensureInt($v): int {
    return (int)($v ?? 0);
}

/**
 * Mengenali tingkat Pramuka (tab) menjadi slug untuk pemetaan kelas.
 */
function barung_resolve_tingkat_slug(?string $nama_tingkat): ?string
{
    $raw = trim((string)$nama_tingkat);
    if ($raw === '') {
        return null;
    }
    /** Mengabaikan spasi/hyphen/unicode hyphen — "Pra Mula"/"pra‑mula" → pramula */
    $compact = strtolower(preg_replace('/[^a-z]/u', '', $raw));

    /** Cadangan pola lama tanpa menghapus spasi dalam string */
    $n = strtolower(preg_replace('/\s+/u', ' ', $raw));
    $k = str_replace([' ', '_', '-'], '', $n);

    $try = [$compact !== '' ? $compact : null, $k !== '' ? $k : null];
    foreach ($try as $t) {
        if ($t === null || $t === '') {
            continue;
        }
        if ($t === 'pramula') {
            return 'pra_mula';
        }
        if ($t === 'mula') {
            return 'mula';
        }
        if ($t === 'bantu') {
            return 'bantu';
        }
        if ($t === 'tata') {
            return 'tata';
        }
        if ($t === 'praramu') {
            return 'pra_ramu';
        }
        if ($t === 'ramu') {
            return 'ramu';
        }
        if ($t === 'garuda') {
            return 'garuda';
        }
    }
    return null;
}

/** Kelas nominal (MI 1–6) berdasarkan tab tingkat aktif */
function barung_kelas_nomor_for_slug(?string $slug): array
{
    switch ($slug) {
        case 'pra_mula':
            return [1, 2];
        case 'mula':
            return [2, 3, 4, 5, 6];
        case 'bantu':
            return [2, 3, 4, 5, 6];
        case 'tata':
            return [4, 5, 6];
        case 'pra_ramu':
        case 'ramu':
            return [1, 2, 3, 4, 5, 6];
        case 'garuda':
            return [3, 4, 5, 6];
        default:
            return [];
    }
}

function barung_golongan_for_slug(?string $slug): ?string
{
    if (in_array($slug, ['pra_mula', 'mula', 'bantu', 'tata'], true)) {
        return 'Siaga';
    }
    if (in_array($slug, ['pra_ramu', 'ramu'], true)) {
        return 'Penggalang';
    }

    return null;
}

function barung_golongan_for_tingkat(?string $nama_tingkat, ?string $fallback = null): string
{
    $slug = barung_resolve_tingkat_slug($nama_tingkat);
    $golongan = barung_golongan_for_slug($slug);

    return $golongan ?? (trim((string)$fallback) !== '' ? (string)$fallback : '-');
}

function barung_umur_bulan(?string $tanggal_lahir): ?int
{
    $raw = trim((string)$tanggal_lahir);
    if ($raw === '' || $raw === '0000-00-00') {
        return null;
    }

    try {
        $lahir = new DateTime(substr($raw, 0, 10));
        $hari_ini = new DateTime('today');
        if ($lahir > $hari_ini) {
            return null;
        }
        $diff = $lahir->diff($hari_ini);

        return ($diff->y * 12) + $diff->m;
    } catch (Exception $e) {
        return null;
    }
}

function barung_format_usia(?string $tanggal_lahir): string
{
    $umur_bulan = barung_umur_bulan($tanggal_lahir);
    if ($umur_bulan === null) {
        return '-';
    }

    $tahun = intdiv($umur_bulan, 12);
    $bulan = $umur_bulan % 12;

    return $tahun . ' tahun ' . $bulan . ' bulan';
}

function barung_siswa_lolos_usia_tingkat(?string $slug, ?string $tanggal_lahir): bool
{
    $golongan = barung_golongan_for_slug($slug);
    $umur_bulan = barung_umur_bulan($tanggal_lahir);
    if ($golongan === null || $umur_bulan === null) {
        return true;
    }

    $batas_penggalang_bulan = 11 * 12;
    if ($golongan === 'Siaga') {
        return $umur_bulan < $batas_penggalang_bulan;
    }
    if ($golongan === 'Penggalang') {
        return $umur_bulan >= $batas_penggalang_bulan;
    }

    return true;
}

/**
 * Nomor kelas MI 1–6 dari baris tb_kelas.
 * Banyak madrasah menyimpan nama "I"–"VI" atau id_kelas 1–6 = kelas 1–6.
 */
function barung_nomor_mi_dari_tb_kelas_row(int $id_kelas, string $nama_kelas): ?int
{
    $t = trim($nama_kelas);
    /** Normalisasi digit Arab (Asia) → Latin */
    static $digitsAr = [
        "\u{0660}" => '0', "\u{0661}" => '1', "\u{0662}" => '2', "\u{0663}" => '3', "\u{0664}" => '4',
        "\u{0665}" => '5', "\u{0666}" => '6', "\u{0667}" => '7', "\u{0668}" => '8', "\u{0669}" => '9',
    ];
    if ($t !== '') {
        $t = strtr($t, $digitsAr);
    }
    /** Buang awalan «Kelas» agar "Kelas I" → "I" (bukan "KELASI" yang tidak dikenali) */
    $t = preg_replace('/^kelas[\h:.\-_\/]*/iu', '', $t);
    $t = trim($t);
    $compact = $t !== '' ? preg_replace('/\s+/u', '', mb_strtoupper($t, 'UTF-8')) : '';

    /** Persis satu token Romawi */
    $romanToken = [
        'I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6,
    ];
    if ($compact !== '' && isset($romanToken[$compact])) {
        return $romanToken[$compact];
    }

    /** Awalan Romawi; urutan VI|IV|III|II|V|I di regex (bukan prefix "V" untuk "VI") */
    if ($compact !== '' && preg_match('/^(VI|IV|III|II|V|I)/u', $compact, $mr)) {
        $mapRom = ['VI' => 6, 'IV' => 4, 'III' => 3, 'II' => 2, 'V' => 5, 'I' => 1];

        return $mapRom[$mr[1]];
    }

    if ($t !== '') {
        if (preg_match('/\b(?:0*)([1-6])\b/u', $t, $m)) {
            return (int)$m[1];
        }
        if (preg_match('/(?:^|[^\d])([1-6])(?:[^\d]|$)/u', $t, $m)) {
            return (int)$m[1];
        }
        /** Sisa token terakhir (mis. "I A" / "B - I") */
        if (preg_match('/(?:^|[\s\-\/])(VI|IV|III|II|V|I)(?:$|[^\p{L}])/u', $t, $m2)) {
            $mapRom = ['VI' => 6, 'IV' => 4, 'III' => 3, 'II' => 2, 'V' => 5, 'I' => 1];

            return $mapRom[strtoupper($m2[1])];
        }
    }

    /** Fallback: skema lama id_kelas 1..6 = kelas 1..6 */
    if ($id_kelas >= 1 && $id_kelas <= 6) {
        return $id_kelas;
    }

    return null;
}

/** Hanya dari teks (tanpa fallback id) — dipakai bila konteks tidak punya id_kelas */
function barung_nama_kelas_ke_nomor(string $nama_kelas): ?int
{
    return barung_nomor_mi_dari_tb_kelas_row(0, $nama_kelas);
}

function barung_ensure_tingkat_pra_ramu(PDO $pdo): int
{
    $stmt = $pdo->query("
        SELECT id_tingkat_barung
        FROM tb_tingkat_barung
        WHERE LOWER(REPLACE(REPLACE(nama_tingkat, ' ', ''), '-', '')) = 'praramu'
        ORDER BY id_tingkat_barung ASC
        LIMIT 1
    ");
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        $pdo->prepare("UPDATE tb_tingkat_barung SET golongan = 'Penggalang' WHERE id_tingkat_barung = ?")->execute([$id]);

        return $id;
    }

    $stmt = $pdo->prepare("INSERT INTO tb_tingkat_barung (nama_tingkat, golongan) VALUES ('Pra Ramu', 'Penggalang')");
    $stmt->execute();

    return (int)$pdo->lastInsertId();
}

function barung_auto_assign_pra_ramu_usia_11(PDO $pdo): array
{
    $pra_ramu_id = barung_ensure_tingkat_pra_ramu($pdo);
    if ($pra_ramu_id <= 0) {
        return ['added' => 0, 'closed_siaga' => 0, 'skipped_active_penggalang' => 0];
    }

    $stmt = $pdo->query("
        SELECT s.id_siswa, s.nisn, s.nama_siswa, s.tempat_lahir, s.tanggal_lahir
        FROM tb_siswa s
        WHERE s.tanggal_lahir IS NOT NULL
          AND YEAR(s.tanggal_lahir) > 0
          AND s.tanggal_lahir <= DATE_SUB(CURDATE(), INTERVAL 11 YEAR)
        ORDER BY s.nama_siswa ASC
    ");
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtActive = $pdo->prepare("
        SELECT p.id_peserta_didik_barung, p.id_tingkat_barung, t.nama_tingkat
        FROM tb_peserta_didik_barung p
        LEFT JOIN tb_tingkat_barung t ON t.id_tingkat_barung = p.id_tingkat_barung
        WHERE IFNULL(p.status, 'aktif') = 'aktif'
          AND (
            (p.id_siswa IS NOT NULL AND p.id_siswa = ?)
            OR (p.id_siswa IS NULL AND TRIM(IFNULL(p.nta, '')) <> '' AND TRIM(p.nta) = ? AND ? <> '')
            OR (p.id_siswa IS NULL AND TRIM(IFNULL(p.nta, '')) = '' AND LOWER(TRIM(p.nama_peserta_didik)) = LOWER(TRIM(?)))
          )
        ORDER BY p.id_peserta_didik_barung ASC
    ");
    $stmtClose = $pdo->prepare("
        UPDATE tb_peserta_didik_barung
        SET status = 'keluar', tanggal_keluar = NOW()
        WHERE id_peserta_didik_barung = ?
    ");
    $stmtInsert = $pdo->prepare("
        INSERT INTO tb_peserta_didik_barung
            (id_tingkat_barung, id_siswa, nama_peserta_didik, nta, tempat_lahir, tanggal_lahir, status, tanggal_masuk, tanggal_keluar)
        VALUES (?, ?, ?, '', ?, ?, 'aktif', NOW(), NULL)
    ");

    $added = 0;
    $closed_siaga = 0;
    $skipped_active_penggalang = 0;

    foreach ($students as $student) {
        $id_siswa = ensureInt($student['id_siswa'] ?? 0);
        if ($id_siswa <= 0 || !barung_siswa_lolos_usia_tingkat('pra_ramu', $student['tanggal_lahir'] ?? null)) {
            continue;
        }

        $nisn = trim((string)($student['nisn'] ?? ''));
        $nama_siswa_raw = trim((string)($student['nama_siswa'] ?? ''));
        $stmtActive->execute([$id_siswa, $nisn, $nisn, $nama_siswa_raw]);
        $activeRows = $stmtActive->fetchAll(PDO::FETCH_ASSOC);

        $hasPraRamuOrRamu = false;
        $hasOtherActive = false;
        foreach ($activeRows as $active) {
            $slug = barung_resolve_tingkat_slug($active['nama_tingkat'] ?? '');
            if (in_array($slug, ['pra_ramu', 'ramu'], true)) {
                $hasPraRamuOrRamu = true;
                break;
            }
            if (in_array($slug, ['pra_mula', 'mula', 'bantu', 'tata'], true)) {
                continue;
            }
            $hasOtherActive = true;
        }

        if ($hasPraRamuOrRamu) {
            continue;
        }
        if ($hasOtherActive) {
            $skipped_active_penggalang++;
            continue;
        }

        foreach ($activeRows as $active) {
            $slug = barung_resolve_tingkat_slug($active['nama_tingkat'] ?? '');
            if (in_array($slug, ['pra_mula', 'mula', 'bantu', 'tata'], true)) {
                $stmtClose->execute([(int)$active['id_peserta_didik_barung']]);
                $closed_siaga++;
            }
        }

        $nama = sanitizeInput((string)($student['nama_siswa'] ?? ''));
        if ($nama === '') {
            continue;
        }
        $tempat = sanitizeInput((string)($student['tempat_lahir'] ?? ''));
        $tanggal = substr((string)$student['tanggal_lahir'], 0, 10);
        $stmtInsert->execute([
            $pra_ramu_id,
            $id_siswa,
            $nama,
            $tempat !== '' ? $tempat : null,
            $tanggal !== '' ? $tanggal : null,
        ]);
        $added++;
    }

    return ['added' => $added, 'closed_siaga' => $closed_siaga, 'skipped_active_penggalang' => $skipped_active_penggalang];
}

$auto_pra_ramu_result = ['added' => 0, 'closed_siaga' => 0, 'skipped_active_penggalang' => 0];
if ($can_manage_barung && empty($_POST)) {
    try {
        $auto_pra_ramu_result = barung_auto_assign_pra_ramu_usia_11($pdo);
    } catch (Exception $e) {
        $schema_error = trim((string)$schema_error) !== '' ? $schema_error . ' | Auto Pra Ramu: ' . $e->getMessage() : 'Auto Pra Ramu: ' . $e->getMessage();
    }
}

// --- Fetch tingkat list ---
$tingkat_list = [];
$fetch_error = null;
try {
    $tingkat_list = $pdo->query("
            SELECT id_tingkat_barung, nama_tingkat, golongan
            FROM tb_tingkat_barung
            ORDER BY
                CASE
                    WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('pramula', 'pra-mula') OR LOWER(nama_tingkat) = 'pra mula' THEN 1
                    WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('mula') THEN 2
                    WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('bantu') THEN 3
                    WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('tata') THEN 4
                    WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('praramu', 'pra-ramu') OR LOWER(nama_tingkat) = 'pra ramu' THEN 5
                    WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('ramu') THEN 6
                    WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('garuda') THEN 7
                    ELSE 99
                END,
                nama_tingkat ASC
        ")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $fetch_error = $e->getMessage();
}

$selected_tingkat_id = ensureInt($_GET['tingkat'] ?? 0);
if ($selected_tingkat_id <= 0 && !empty($tingkat_list)) {
    $selected_tingkat_id = (int)($tingkat_list[0]['id_tingkat_barung'] ?? 0);
}

// Resolve selected tingkat name early (used for template filename too)
$selected_tingkat_name = '';
foreach ($tingkat_list as $t) {
    if ((int)($t['id_tingkat_barung'] ?? 0) === $selected_tingkat_id) {
        $selected_tingkat_name = (string)($t['nama_tingkat'] ?? '');
        break;
    }
}

// --- Download import template (Excel/CSV) ---
if (isset($_GET['download_template'])) {
    $format = strtolower((string)($_GET['format'] ?? 'xlsx'));
    if (!in_array($format, ['xlsx', 'csv'], true)) {
        $format = 'xlsx';
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        http_response_code(500);
        echo 'Dependensi Excel belum terpasang. Jalankan composer install.';
        exit;
    }
    require_once $autoload;

    $safeTingkat = trim(preg_replace('/[^a-zA-Z0-9_-]+/', '_', $selected_tingkat_name !== '' ? $selected_tingkat_name : 'tingkat'));
    $filenameBase = 'template_import_barung_' . ($safeTingkat !== '' ? $safeTingkat : 'tingkat');

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Template');

    $sheet->setCellValue('A1', 'Nama Peserta Didik');
    $sheet->setCellValue('B1', 'NTA');
    $sheet->setCellValue('C1', 'Tempat Lahir');
    $sheet->setCellValue('D1', 'Tanggal Lahir (YYYY-MM-DD)');
    $sheet->setCellValue('A2', 'Contoh: Ahmad');
    $sheet->setCellValue('B2', '12345');
    $sheet->setCellValue('C2', 'Padang');
    $sheet->setCellValue('D2', '2013-01-15');
    $sheet->setCellValue('A3', 'Contoh: Siti');
    $sheet->setCellValue('B3', '67890');
    $sheet->setCellValue('C3', 'Bukittinggi');
    $sheet->setCellValue('D3', '2013-08-20');

    // Basic styling for header
    $sheet->getStyle('A1:D1')->getFont()->setBold(true);
    $sheet->getColumnDimension('A')->setWidth(28);
    $sheet->getColumnDimension('B')->setWidth(18);
    $sheet->getColumnDimension('C')->setWidth(22);
    $sheet->getColumnDimension('D')->setWidth(24);

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Csv($spreadsheet);
        $writer->setDelimiter(',');
        $writer->setEnclosure('"');
        $writer->setUseBOM(true);
        $writer->save('php://output');
        exit;
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.xlsx"');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// --- CRUD handling ---
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage_barung) {
    // Import peserta didik from Excel/CSV
    if (isset($_POST['import_peserta_didik'])) {
        $is_ajax = isset($_POST['ajax']) && (string)$_POST['ajax'] === '1';
        $id_tingkat = ensureInt($_POST['id_tingkat_barung'] ?? 0);
        if ($id_tingkat <= 0) {
            $message = ['type' => 'warning', 'text' => 'Pilih tingkat barung untuk import.'];
        } elseif (!isset($_FILES['import_file']) || !is_array($_FILES['import_file'])) {
            $message = ['type' => 'warning', 'text' => 'File import tidak ditemukan.'];
        } else {
            $file = $_FILES['import_file'];
            $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) {
                $message = ['type' => 'danger', 'text' => 'Gagal upload file (error code: ' . $err . ').'];
            } else {
                $tmpPath = (string)($file['tmp_name'] ?? '');
                $origName = (string)($file['name'] ?? '');
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

                if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
                    $message = ['type' => 'warning', 'text' => 'Format file tidak didukung. Gunakan .xlsx, .xls, atau .csv'];
                } else {
                    try {
                        $autoload = __DIR__ . '/../vendor/autoload.php';
                        if (!file_exists($autoload)) {
                            throw new Exception('Dependensi Excel belum terpasang. Jalankan composer install.');
                        }
                        require_once $autoload;

                        $reader = null;
                        if ($ext === 'csv') {
                            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
                            $reader->setDelimiter(',');
                            $reader->setEnclosure('"');
                            $reader->setSheetIndex(0);
                        } else {
                            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmpPath);
                        }
                        $reader->setReadDataOnly(true);
                        $spreadsheet = $reader->load($tmpPath);
                        $sheet = $spreadsheet->getSheet(0);
                        $highestRow = (int)$sheet->getHighestRow();
                        $highestCol = $sheet->getHighestColumn();

                        // Detect header (row 1) for columns
                        $headerMap = ['nama' => null, 'nta' => null, 'tempat' => null, 'tanggal' => null];
                        $headerRow = $sheet->rangeToArray("A1:{$highestCol}1", null, true, true, true);
                        $headerRow = $headerRow[1] ?? [];
                        foreach ($headerRow as $col => $val) {
                            $txt = strtolower(trim((string)$val));
                            if ($txt === '') continue;
                            if ($headerMap['nama'] === null && (str_contains($txt, 'nama') || str_contains($txt, 'peserta'))) {
                                $headerMap['nama'] = $col;
                                continue;
                            }
                            if ($headerMap['nta'] === null && str_contains($txt, 'nta')) {
                                $headerMap['nta'] = $col;
                                continue;
                            }
                            if ($headerMap['tempat'] === null && (str_contains($txt, 'tempat') && str_contains($txt, 'lahir'))) {
                                $headerMap['tempat'] = $col;
                                continue;
                            }
                            if ($headerMap['tanggal'] === null && (str_contains($txt, 'tanggal') && str_contains($txt, 'lahir'))) {
                                $headerMap['tanggal'] = $col;
                                continue;
                            }
                        }

                        $startRow = ($headerMap['nama'] !== null || $headerMap['nta'] !== null || $headerMap['tempat'] !== null || $headerMap['tanggal'] !== null) ? 2 : 1;
                        if ($headerMap['nama'] === null) $headerMap['nama'] = 'A';
                        if ($headerMap['nta'] === null) $headerMap['nta'] = 'B';
                        if ($headerMap['tempat'] === null) $headerMap['tempat'] = 'C';
                        if ($headerMap['tanggal'] === null) $headerMap['tanggal'] = 'D';

                        $inserted = 0;
                        $skipped = 0;

                        $pdo->beginTransaction();
                        $stmtIns = $pdo->prepare("
                            INSERT INTO tb_peserta_didik_barung
                                (id_tingkat_barung, nama_peserta_didik, nta, tempat_lahir, tanggal_lahir, status, tanggal_masuk, tanggal_keluar)
                            VALUES (?, ?, ?, ?, ?, 'aktif', NOW(), NULL)
                        ");

                        for ($r = $startRow; $r <= $highestRow; $r++) {
                            $nama = trim((string)$sheet->getCell($headerMap['nama'] . $r)->getValue());
                            $nta = trim((string)$sheet->getCell($headerMap['nta'] . $r)->getValue());
                            $tempat = trim((string)$sheet->getCell($headerMap['tempat'] . $r)->getValue());
                            $tglRaw = $sheet->getCell($headerMap['tanggal'] . $r)->getValue();
                            $tgl = '';
                            if ($tglRaw !== null && $tglRaw !== '') {
                                if (is_numeric($tglRaw)) {
                                    try {
                                        $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$tglRaw);
                                        $tgl = $dt->format('Y-m-d');
                                    } catch (Exception $e) {
                                        $tgl = '';
                                    }
                                } else {
                                    $tgl = trim((string)$tglRaw);
                                }
                            }
                            $tgl = $tgl !== '' ? substr($tgl, 0, 10) : null;

                            if ($nama === '' && trim((string)$nta) === '') {
                                $skipped++;
                                continue;
                            }
                            if ($nama === '') {
                                $skipped++;
                                continue;
                            }
                            $nta = trim((string)$nta);

                            $stmtIns->execute([$id_tingkat, $nama, $nta, ($tempat !== '' ? $tempat : null), $tgl]);
                            $inserted++;
                        }

                        $pdo->commit();

                        $username = $_SESSION['username'] ?? 'system';
                        logActivity($pdo, $username, 'Import Peserta Didik Barung', "Tingkat ID {$id_tingkat}: inserted {$inserted}, skipped {$skipped}, file {$origName}");

                        $message = ['type' => 'success', 'text' => "Import selesai. Berhasil: {$inserted} baris, dilewati: {$skipped} baris."];
                        $selected_tingkat_id = $id_tingkat;
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $message = ['type' => 'danger', 'text' => 'Gagal import: ' . $e->getMessage()];
                    }
                }
            }
        }

        if ($is_ajax) {
            // Return JSON response for AJAX import
            $type = $message['type'] ?? 'danger';
            $text = $message['text'] ?? 'Terjadi kesalahan.';
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => $type === 'success',
                'type' => $type,
                'message' => $text,
                'selected_tingkat_id' => $selected_tingkat_id,
            ]);
            exit;
        }
    }

    // Tambah kolektif dari data siswa (per pemetaan kelas tingkat tab)
    if (isset($_POST['add_peserta_kolektif'])) {
        $id_tingkat = ensureInt($_POST['id_tingkat_barung'] ?? 0);
        $picked = isset($_POST['selected_siswa']) && is_array($_POST['selected_siswa']) ? $_POST['selected_siswa'] : [];
        $picked = array_values(array_filter(array_map('intval', $picked), static function ($v) {
            return $v > 0;
        }));

        $nama_thr = '';
        try {
            $tt = $pdo->prepare('SELECT nama_tingkat FROM tb_tingkat_barung WHERE id_tingkat_barung = ? LIMIT 1');
            $tt->execute([$id_tingkat]);
            $nama_thr = (string)($tt->fetchColumn() ?: '');
        } catch (Exception $e) {
            $nama_thr = '';
        }
        $slug_thr = barung_resolve_tingkat_slug($nama_thr);
        $allowed_nums = barung_kelas_nomor_for_slug($slug_thr);

        if ($id_tingkat <= 0) {
            $message = ['type' => 'warning', 'text' => 'Tingkat tidak valid.'];
        } elseif ($slug_thr === null || $allowed_nums === []) {
            $message = ['type' => 'warning', 'text' => 'Pemetaan otomatis hanya untuk tingkat Pra Mula, Mula, Bantu, Tata, Pra Ramu, Ramu, atau Garuda.'];
        } elseif (empty($picked)) {
            $message = ['type' => 'warning', 'text' => 'Pilih minimal satu siswa.'];
        } else {
            try {
                $kelas_rows = $pdo->query('SELECT id_kelas, nama_kelas FROM tb_kelas')->fetchAll(PDO::FETCH_ASSOC);
                $id_kelas_ok = [];
                foreach ($kelas_rows as $kr) {
                    $nom = barung_nomor_mi_dari_tb_kelas_row((int)($kr['id_kelas'] ?? 0), (string)($kr['nama_kelas'] ?? ''));
                    if ($nom !== null && in_array($nom, $allowed_nums, true)) {
                        $id_kelas_ok[(int)$kr['id_kelas']] = true;
                    }
                }
                $id_kelas_ok = array_keys($id_kelas_ok);

                $stmt_siswa = $pdo->prepare('
                    SELECT s.id_siswa, s.nisn, s.nama_siswa, s.tempat_lahir, s.tanggal_lahir, s.id_kelas
                    FROM tb_siswa s
                    WHERE s.id_siswa = ?
                    LIMIT 1
                ');
                $ins = $pdo->prepare('
                    INSERT INTO tb_peserta_didik_barung
                        (id_tingkat_barung, id_siswa, nama_peserta_didik, nta, tempat_lahir, tanggal_lahir, status, tanggal_masuk, tanggal_keluar)
                    VALUES (?, ?, ?, ?, ?, ?, \'aktif\', NOW(), NULL)
                ');
                $chkOtherActive = $pdo->prepare('
                    SELECT t.nama_tingkat
                    FROM tb_peserta_didik_barung p
                    LEFT JOIN tb_tingkat_barung t ON t.id_tingkat_barung = p.id_tingkat_barung
                    WHERE IFNULL(p.status, \'aktif\') = \'aktif\'
                      AND p.id_tingkat_barung <> ?
                      AND p.id_siswa = ?
                    LIMIT 1
                ');
                $pdo->beginTransaction();
                $added = 0;
                $blocked_other_tingkat = 0;
                $blocked_age = 0;
                foreach ($picked as $sid) {
                    $stmt_siswa->execute([$sid]);
                    $row = $stmt_siswa->fetch(PDO::FETCH_ASSOC);
                    if (!$row) {
                        continue;
                    }
                    $id_kelas = (int)($row['id_kelas'] ?? 0);
                    if ($id_kelas <= 0 || !in_array($id_kelas, $id_kelas_ok, true)) {
                        continue;
                    }
                    if (!barung_siswa_lolos_usia_tingkat($slug_thr, $row['tanggal_lahir'] ?? null)) {
                        $blocked_age++;
                        continue;
                    }
                    /** NTA disimpan kosong; bisa diisi lewat Edit */
                    $nta = '';
                    $nama = sanitizeInput((string)($row['nama_siswa'] ?? ''));
                    if ($nama === '') {
                        continue;
                    }
                    $id_s_insert = ensureInt($row['id_siswa'] ?? 0);
                    if ($id_s_insert > 0) {
                        $chkOtherActive->execute([$id_tingkat, $id_s_insert]);
                        if ($chkOtherActive->fetchColumn()) {
                            $blocked_other_tingkat++;
                            continue;
                        }
                    }
                    $nisn_tr = trim((string)($row['nisn'] ?? ''));
                    $chkDup = $pdo->prepare('
                        SELECT 1 FROM tb_peserta_didik_barung
                        WHERE id_tingkat_barung = ?
                          AND IFNULL(status, \'aktif\') = \'aktif\'
                          AND (
                            (id_siswa IS NOT NULL AND id_siswa = ?)
                            OR (id_siswa IS NULL AND TRIM(IFNULL(nta, \'\')) <> \'\'
                                AND TRIM(nta) = ? AND ? <> \'\')
                            OR (id_siswa IS NULL AND TRIM(IFNULL(nta, \'\')) = \'\'
                                AND LOWER(TRIM(nama_peserta_didik)) = LOWER(TRIM(?)))
                          )
                        LIMIT 1
                    ');
                    $chkDup->execute([$id_tingkat, $id_s_insert, $nisn_tr, $nisn_tr, $nama]);
                    if ($chkDup->fetchColumn()) {
                        continue;
                    }
                    $tp = trim((string)($row['tempat_lahir'] ?? ''));
                    $tempat_sql = sanitizeInput($tp);
                    $tgl_raw = !empty($row['tanggal_lahir']) ? trim((string)$row['tanggal_lahir']) : '';
                    $tgl_sql = $tgl_raw !== '' ? substr($tgl_raw, 0, 10) : null;

                    $ins->execute([
                        $id_tingkat,
                        ($id_s_insert > 0 ? $id_s_insert : null),
                        $nama,
                        $nta,
                        ($tempat_sql !== '' ? $tempat_sql : null),
                        $tgl_sql,
                    ]);
                    $added++;
                }
                $pdo->commit();

                $username = $_SESSION['username'] ?? 'system';
                logActivity($pdo, $username, 'Tambah Peserta Didik Barung (Kolektif)', "Tingkat ID {$id_tingkat}: ditambahkan {$added} dari data siswa");
                if ($blocked_other_tingkat > 0 || $blocked_age > 0) {
                    $notes = [];
                    if ($blocked_other_tingkat > 0) {
                        $notes[] = "{$blocked_other_tingkat} siswa dilewati karena masih aktif di tingkat pramuka lain";
                    }
                    if ($blocked_age > 0) {
                        $notes[] = "{$blocked_age} siswa dilewati karena tidak sesuai batas usia golongan";
                    }
                    $message = ['type' => 'warning', 'text' => "Berhasil menambahkan {$added} peserta. " . implode('; ', $notes) . '.'];
                } else {
                    $message = ['type' => 'success', 'text' => "Berhasil menambahkan {$added} peserta dari data siswa."];
                }
                $selected_tingkat_id = $id_tingkat;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = ['type' => 'danger', 'text' => 'Gagal tambah kolektif: ' . $e->getMessage()];
                $selected_tingkat_id = $id_tingkat > 0 ? $id_tingkat : $selected_tingkat_id;
            }
        }
    }

    // Add peserta manual
    if (isset($_POST['add_peserta_didik'])) {
        $id_tingkat = ensureInt($_POST['id_tingkat_barung'] ?? 0);
        $nama = sanitizeInput($_POST['nama_peserta_didik'] ?? '');
        $nta = sanitizeInput($_POST['nta'] ?? '');
        $tempat = sanitizeInput($_POST['tempat_lahir'] ?? '');
        $tgl = sanitizeInput($_POST['tanggal_lahir'] ?? '');
        $tgl = $tgl !== '' ? substr($tgl, 0, 10) : null;

        if ($id_tingkat <= 0 || $nama === '') {
            $message = ['type' => 'warning', 'text' => 'Harap lengkapi tingkat dan nama peserta didik. NTA bisa dikosongkan dulu.'];
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO tb_peserta_didik_barung
                        (id_tingkat_barung, nama_peserta_didik, nta, tempat_lahir, tanggal_lahir, status, tanggal_masuk, tanggal_keluar)
                    VALUES (?, ?, ?, ?, ?, 'aktif', NOW(), NULL)
                ");
                $ok = $stmt->execute([$id_tingkat, $nama, $nta, ($tempat !== '' ? $tempat : null), $tgl]);
                if ($ok) {
                    $username = $_SESSION['username'] ?? 'system';
                    logActivity($pdo, $username, 'Tambah Peserta Didik Barung', "{$nama} ({$nta}) tingkat ID {$id_tingkat}");
                    $message = ['type' => 'success', 'text' => 'Peserta didik berhasil ditambahkan!'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal menambahkan peserta didik.'];
                }
                $selected_tingkat_id = $id_tingkat;
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Error DB: ' . $e->getMessage()];
            }
        }
    }

    // Update peserta
    if (isset($_POST['update_peserta_didik'])) {
        $id_peserta = ensureInt($_POST['id_peserta_didik_barung'] ?? 0);
        $id_tingkat = ensureInt($_POST['id_tingkat_barung'] ?? 0);
        $nama = sanitizeInput($_POST['nama_peserta_didik'] ?? '');
        $nta = sanitizeInput($_POST['nta'] ?? '');
        $tempat = sanitizeInput($_POST['tempat_lahir'] ?? '');
        $tgl = sanitizeInput($_POST['tanggal_lahir'] ?? '');
        $tgl = $tgl !== '' ? substr($tgl, 0, 10) : null;

        if ($id_peserta <= 0 || $id_tingkat <= 0 || $nama === '') {
            $message = ['type' => 'warning', 'text' => 'Nama peserta tidak boleh kosong. NTA bisa dikosongkan.'];
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE tb_peserta_didik_barung
                    SET id_tingkat_barung = ?, nama_peserta_didik = ?, nta = ?, tempat_lahir = ?, tanggal_lahir = ?
                    WHERE id_peserta_didik_barung = ?
                ");
                $ok = $stmt->execute([$id_tingkat, $nama, $nta, ($tempat !== '' ? $tempat : null), $tgl, $id_peserta]);
                if ($ok) {
                    $username = $_SESSION['username'] ?? 'system';
                    logActivity($pdo, $username, 'Update Peserta Didik Barung', "ID {$id_peserta}: {$nama} ({$nta}) tingkat ID {$id_tingkat}");
                    $message = ['type' => 'success', 'text' => 'Data peserta didik berhasil diperbarui!'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal memperbarui data.'];
                }
                $selected_tingkat_id = $id_tingkat;
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Error DB: ' . $e->getMessage()];
            }
        }
    }

    // Keluarkan peserta (soft delete)
    if (isset($_POST['keluarkan_peserta_didik'])) {
        $id_peserta = ensureInt($_POST['id_peserta_didik_barung'] ?? 0);
        $id_tingkat = ensureInt($_POST['id_tingkat_barung'] ?? 0);

        if ($id_peserta <= 0) {
            $message = ['type' => 'warning', 'text' => 'ID tidak valid.'];
        } else {
            try {
                $nameStmt = $pdo->prepare("SELECT nama_peserta_didik FROM tb_peserta_didik_barung WHERE id_peserta_didik_barung = ?");
                $nameStmt->execute([$id_peserta]);
                $nama = (string)($nameStmt->fetchColumn() ?: '-');

                $stmt = $pdo->prepare("
                    UPDATE tb_peserta_didik_barung
                    SET status = 'keluar', tanggal_keluar = NOW()
                    WHERE id_peserta_didik_barung = ?
                    LIMIT 1
                ");
                $ok = $stmt->execute([$id_peserta]);
                if ($ok) {
                    $username = $_SESSION['username'] ?? 'system';
                    logActivity($pdo, $username, 'Keluarkan Peserta Didik Barung', "ID {$id_peserta}: {$nama}");
                    $message = ['type' => 'success', 'text' => 'Peserta didik berhasil dikeluarkan dari daftar.'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal mengeluarkan peserta didik.'];
                }
                if ($id_tingkat > 0) $selected_tingkat_id = $id_tingkat;
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Error DB: ' . $e->getMessage()];
            }
        }
    }

    // Multiple keluarkan peserta (by selected checkboxes)
    if (isset($_POST['keluarkan_peserta_didik_multiple'])) {
        $id_tingkat = ensureInt($_POST['id_tingkat_barung'] ?? 0);
        $selected = $_POST['selected_ids'] ?? [];
        if (!is_array($selected)) $selected = [];
        $selected = array_values(array_filter(array_map('intval', $selected), fn($v) => $v > 0));

        if ($id_tingkat <= 0 || empty($selected)) {
            $message = ['type' => 'warning', 'text' => 'Pilih minimal 1 peserta didik untuk dikeluarkan.'];
        } else {
            try {
                $pdo->beginTransaction();
                $placeholders = str_repeat('?,', count($selected) - 1) . '?';
                $sql = "
                    UPDATE tb_peserta_didik_barung
                    SET status = 'keluar', tanggal_keluar = NOW()
                    WHERE id_peserta_didik_barung IN ($placeholders)
                      AND id_tingkat_barung = ?
                ";
                $params = array_merge($selected, [$id_tingkat]);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $deleted = (int)$stmt->rowCount();

                $username = $_SESSION['username'] ?? 'system';
                logActivity($pdo, $username, 'Keluarkan Peserta Didik Barung (Multiple)', "Tingkat ID {$id_tingkat}: {$deleted} peserta");
                $pdo->commit();

                $message = ['type' => 'success', 'text' => "Berhasil mengeluarkan {$deleted} peserta didik dari daftar."];
                $selected_tingkat_id = $id_tingkat;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $message = ['type' => 'danger', 'text' => 'Gagal mengeluarkan data: ' . $e->getMessage()];
            }
        }
    }

    // Update banyak sekaligus (dari modal tabel edit)
    if (isset($_POST['update_peserta_bulk'])) {
        $id_tingkat = ensureInt($_POST['id_tingkat_barung'] ?? 0);
        $bulk_id = $_POST['bulk_id'] ?? [];
        $bulk_nama = $_POST['bulk_nama'] ?? [];
        $bulk_nta = $_POST['bulk_nta'] ?? [];
        $bulk_tempat = $_POST['bulk_tempat'] ?? [];
        $bulk_tgl = $_POST['bulk_tanggal'] ?? [];
        if (!is_array($bulk_id)) {
            $bulk_id = [];
        }

        if ($id_tingkat <= 0 || empty($bulk_id)) {
            $message = ['type' => 'warning', 'text' => 'Tidak ada data yang dikirim untuk diperbarui.'];
        } else {
            try {
                $stmtUp = $pdo->prepare('
                    UPDATE tb_peserta_didik_barung
                    SET nama_peserta_didik = ?, nta = ?, tempat_lahir = ?, tanggal_lahir = ?
                    WHERE id_peserta_didik_barung = ? AND id_tingkat_barung = ?
                ');
                $pdo->beginTransaction();
                $updated = 0;
                $n = count($bulk_id);
                for ($i = 0; $i < $n; $i++) {
                    $idp = ensureInt($bulk_id[$i] ?? 0);
                    $nama = sanitizeInput($bulk_nama[$i] ?? '');
                    $nta = sanitizeInput($bulk_nta[$i] ?? '');
                    $tempat = sanitizeInput($bulk_tempat[$i] ?? '');
                    $tgl = sanitizeInput($bulk_tgl[$i] ?? '');
                    $tgl = $tgl !== '' ? substr($tgl, 0, 10) : null;
                    if ($idp <= 0 || $nama === '') {
                        continue;
                    }
                    $stmtUp->execute([
                        $nama,
                        $nta,
                        ($tempat !== '' ? $tempat : null),
                        $tgl,
                        $idp,
                        $id_tingkat,
                    ]);
                    $updated++;
                }
                if ($updated === 0) {
                    $pdo->rollBack();
                    $message = ['type' => 'warning', 'text' => 'Tidak ada baris valid (nama wajib; NTA boleh kosong).'];
                } else {
                    $pdo->commit();

                    $username = $_SESSION['username'] ?? 'system';
                    logActivity($pdo, $username, 'Update Peserta Didik Barung (Multiple)', "Tingkat ID {$id_tingkat}: {$updated} baris");
                    $message = ['type' => 'success', 'text' => "Berhasil memperbarui {$updated} peserta didik."];
                    $selected_tingkat_id = $id_tingkat;
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = ['type' => 'danger', 'text' => 'Gagal memperbarui data: ' . $e->getMessage()];
            }
        }
    }
}

// --- Fetch peserta list by tingkat ---
$peserta_rows = [];
$table_error = null;
if ($selected_tingkat_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                p.id_peserta_didik_barung,
                p.nama_peserta_didik,
                p.nta,
                COALESCE(NULLIF(TRIM(k.nama_kelas), ''), '-') AS nama_kelas,
                COALESCE(NULLIF(TRIM(s.tempat_lahir), ''), NULLIF(TRIM(p.tempat_lahir), '')) AS tempat_lahir,
                COALESCE(s.tanggal_lahir, p.tanggal_lahir) AS tanggal_lahir,
                p.id_tingkat_barung
            FROM tb_peserta_didik_barung p
            LEFT JOIN tb_siswa s ON (
                s.id_siswa = p.id_siswa
                OR (
                    p.id_siswa IS NULL
                    AND TRIM(IFNULL(p.nta, '')) <> ''
                    AND CONVERT(TRIM(IFNULL(s.nisn, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                        = CONVERT(TRIM(IFNULL(p.nta, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                )
            )
            LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas
            WHERE p.id_tingkat_barung = ?
              AND IFNULL(p.status, 'aktif') = 'aktif'
              AND (s.id_kelas IS NOT NULL OR p.id_siswa IS NULL)
            ORDER BY k.nama_kelas ASC, p.nama_peserta_didik ASC
        ");
        $stmt->execute([$selected_tingkat_id]);
        $peserta_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $table_error = $e->getMessage();
    }
}

$kelas_summary_barung = [];
$kelas_summary_unmapped = 0;
foreach ($peserta_rows as $row) {
    $nama_kelas_summary = trim((string)($row['nama_kelas'] ?? ''));
    if ($nama_kelas_summary === '' || $nama_kelas_summary === '-') {
        $kelas_summary_unmapped++;
        continue;
    }
    if (!isset($kelas_summary_barung[$nama_kelas_summary])) {
        $kelas_summary_barung[$nama_kelas_summary] = 0;
    }
    $kelas_summary_barung[$nama_kelas_summary]++;
}
ksort($kelas_summary_barung, SORT_NATURAL | SORT_FLAG_CASE);

/** Siswa di tb_kelas sesuai tab (untuk modal tambah kolektif); belum ada di tingkat ini */
$barung_tingkat_slug = barung_resolve_tingkat_slug($selected_tingkat_name);
$barung_kelas_allowed = barung_kelas_nomor_for_slug($barung_tingkat_slug);
$available_siswa_barung = [];
$barung_avail_kelas_tidak_terpetakan = false;
$barung_avail_sql_error = null;
$barung_avail_usia_filtered = 0;
if ($selected_tingkat_id > 0 && $barung_tingkat_slug !== null && $barung_kelas_allowed !== []) {
    try {
        $kelas_all = $pdo->query('SELECT id_kelas, nama_kelas FROM tb_kelas')->fetchAll(PDO::FETCH_ASSOC);
        $id_kelas_nomor_map = static function (array $row) use ($barung_kelas_allowed): bool {
            $nom_k = barung_nomor_mi_dari_tb_kelas_row((int)($row['id_kelas'] ?? 0), (string)($row['nama_kelas'] ?? ''));

            return $nom_k !== null && in_array($nom_k, $barung_kelas_allowed, true);
        };

        $master_ids = [];
        foreach ($kelas_all as $kr) {
            if ($id_kelas_nomor_map([
                'id_kelas' => (int)($kr['id_kelas'] ?? 0),
                'nama_kelas' => (string)($kr['nama_kelas'] ?? ''),
            ])) {
                $master_ids[] = (int)($kr['id_kelas']);
            }
        }
        $pairs_siswa = $pdo->query('
            SELECT DISTINCT s.id_kelas AS id_kelas, k.nama_kelas AS nama_kelas
            FROM tb_siswa s
            LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas
            WHERE s.id_kelas IS NOT NULL AND s.id_kelas > 0
        ')->fetchAll(PDO::FETCH_ASSOC);
        $aktif_ids = [];
        foreach ($pairs_siswa as $pr) {
            if ($id_kelas_nomor_map([
                'id_kelas' => (int)($pr['id_kelas'] ?? 0),
                'nama_kelas' => (string)($pr['nama_kelas'] ?? ''),
            ])) {
                $aktif_ids[] = (int)$pr['id_kelas'];
            }
        }
        $id_kelas_allow = array_values(array_unique(array_filter(array_merge($master_ids, $aktif_ids), static function ($x) {
            return $x > 0;
        })));
        $barung_avail_kelas_tidak_terpetakan = $id_kelas_allow === [];

        /** Kolom penghubung (opsional; query lama gagal diam-diam bila ALTER belum jalan). */
        $pdd_has_id_siswa = false;
        try {
            $pdd_has_id_siswa = (bool)$pdo->query("SHOW COLUMNS FROM tb_peserta_didik_barung LIKE 'id_siswa'")->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $ignoreCol) {
            $pdd_has_id_siswa = false;
        }

        $caseSudahTd = $pdd_has_id_siswa ? <<<'SQL_TD_WITH_ID'
CASE
    WHEN EXISTS (
        SELECT 1 FROM tb_peserta_didik_barung p
        WHERE p.id_tingkat_barung = ?
          AND IFNULL(p.status, 'aktif') = 'aktif'
          AND (
            (p.id_siswa IS NOT NULL AND p.id_siswa = s.id_siswa)
            OR (p.id_siswa IS NULL AND TRIM(IFNULL(p.nta, '')) <> ''
                AND NULLIF(TRIM(s.nisn), '') IS NOT NULL
                AND CONVERT(TRIM(p.nta) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                    = CONVERT(TRIM(s.nisn) USING utf8mb4) COLLATE utf8mb4_unicode_ci)
          )
    ) THEN 1
    ELSE 0
END AS sudah_terdaftar
SQL_TD_WITH_ID
            : <<<'SQL_TD_NO_ID'
CASE
    WHEN EXISTS (
        SELECT 1 FROM tb_peserta_didik_barung p
        WHERE p.id_tingkat_barung = ?
          AND IFNULL(p.status, 'aktif') = 'aktif'
          AND TRIM(IFNULL(p.nta, '')) <> ''
          AND NULLIF(TRIM(s.nisn), '') IS NOT NULL
          AND CONVERT(TRIM(p.nta) USING utf8mb4) COLLATE utf8mb4_unicode_ci
              = CONVERT(TRIM(s.nisn) USING utf8mb4) COLLATE utf8mb4_unicode_ci
    ) THEN 1
    ELSE 0
END AS sudah_terdaftar
SQL_TD_NO_ID;

        $caseTingkatLain = $pdd_has_id_siswa ? <<<'SQL_OTH_WITH_ID'
CASE
    WHEN EXISTS (
        SELECT 1 FROM tb_peserta_didik_barung p2
        WHERE IFNULL(p2.status, 'aktif') = 'aktif'
          AND p2.id_tingkat_barung <> ?
          AND (
            (p2.id_siswa IS NOT NULL AND p2.id_siswa = s.id_siswa)
            OR (p2.id_siswa IS NULL AND TRIM(IFNULL(p2.nta, '')) <> ''
                AND NULLIF(TRIM(s.nisn), '') IS NOT NULL
                AND CONVERT(TRIM(p2.nta) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                    = CONVERT(TRIM(s.nisn) USING utf8mb4) COLLATE utf8mb4_unicode_ci)
          )
    ) THEN 1
    ELSE 0
END AS sudah_di_tingkat_lain,
(
    SELECT COALESCE(NULLIF(TRIM(t2.nama_tingkat), ''), '-')
    FROM tb_peserta_didik_barung p2
    LEFT JOIN tb_tingkat_barung t2 ON t2.id_tingkat_barung = p2.id_tingkat_barung
    WHERE IFNULL(p2.status, 'aktif') = 'aktif'
      AND p2.id_tingkat_barung <> ?
      AND (
        (p2.id_siswa IS NOT NULL AND p2.id_siswa = s.id_siswa)
        OR (p2.id_siswa IS NULL AND TRIM(IFNULL(p2.nta, '')) <> ''
            AND NULLIF(TRIM(s.nisn), '') IS NOT NULL
            AND CONVERT(TRIM(p2.nta) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                = CONVERT(TRIM(s.nisn) USING utf8mb4) COLLATE utf8mb4_unicode_ci)
      )
    ORDER BY p2.id_peserta_didik_barung DESC
    LIMIT 1
) AS tingkat_aktif_lain
SQL_OTH_WITH_ID
            : <<<'SQL_OTH_NO_ID'
CASE
    WHEN EXISTS (
        SELECT 1 FROM tb_peserta_didik_barung p2
        WHERE IFNULL(p2.status, 'aktif') = 'aktif'
          AND p2.id_tingkat_barung <> ?
          AND TRIM(IFNULL(p2.nta, '')) <> ''
          AND NULLIF(TRIM(s.nisn), '') IS NOT NULL
          AND CONVERT(TRIM(p2.nta) USING utf8mb4) COLLATE utf8mb4_unicode_ci
              = CONVERT(TRIM(s.nisn) USING utf8mb4) COLLATE utf8mb4_unicode_ci
    ) THEN 1
    ELSE 0
END AS sudah_di_tingkat_lain,
(
    SELECT COALESCE(NULLIF(TRIM(t2.nama_tingkat), ''), '-')
    FROM tb_peserta_didik_barung p2
    LEFT JOIN tb_tingkat_barung t2 ON t2.id_tingkat_barung = p2.id_tingkat_barung
    WHERE IFNULL(p2.status, 'aktif') = 'aktif'
      AND p2.id_tingkat_barung <> ?
      AND TRIM(IFNULL(p2.nta, '')) <> ''
      AND NULLIF(TRIM(s.nisn), '') IS NOT NULL
      AND CONVERT(TRIM(p2.nta) USING utf8mb4) COLLATE utf8mb4_unicode_ci
          = CONVERT(TRIM(s.nisn) USING utf8mb4) COLLATE utf8mb4_unicode_ci
    ORDER BY p2.id_peserta_didik_barung DESC
    LIMIT 1
) AS tingkat_aktif_lain
SQL_OTH_NO_ID;

        if (!empty($id_kelas_allow)) {
            $placeholders = implode(',', array_fill(0, count($id_kelas_allow), '?'));
            $sql_avail =
                'SELECT s.id_siswa, s.nisn, s.nama_siswa, s.tanggal_lahir,' .
                " COALESCE(NULLIF(TRIM(k.nama_kelas), ''), CONCAT('#id ', CAST(s.id_kelas AS CHAR))) AS nama_kelas," .
                trim($caseSudahTd) . ',' . trim($caseTingkatLain) .
                ' FROM tb_siswa s' .
                ' LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas' .
                ' WHERE s.id_kelas IN (' . $placeholders . ')' .
                ' ORDER BY nama_kelas ASC, s.nama_siswa ASC';
            $params_avail = array_merge([$selected_tingkat_id, $selected_tingkat_id, $selected_tingkat_id], $id_kelas_allow);
            $st_avail = $pdo->prepare($sql_avail);
            $st_avail->execute($params_avail);
            $available_siswa_barung = $st_avail->fetchAll(PDO::FETCH_ASSOC);
            $available_siswa_barung = array_values(array_filter($available_siswa_barung, static function (array $row) use ($barung_tingkat_slug, &$barung_avail_usia_filtered): bool {
                $ok = barung_siswa_lolos_usia_tingkat($barung_tingkat_slug, $row['tanggal_lahir'] ?? null);
                if (!$ok) {
                    $barung_avail_usia_filtered++;
                }

                return $ok;
            }));
        }
    } catch (Exception $e) {
        $available_siswa_barung = [];
        $barung_avail_sql_error = $e->getMessage();
    }
}

/** Kelompok siswa untuk modal (tab per nama kelas jika >1 kelas) */
$available_siswa_by_kelas = [];
$available_siswa_selectable_count = 0;
foreach ($available_siswa_barung as $rowSb) {
    $is_di_tingkat_ini = (int)($rowSb['sudah_terdaftar'] ?? 0) === 1;
    $is_di_tingkat_lain = (int)($rowSb['sudah_di_tingkat_lain'] ?? 0) === 1;
    if (!$is_di_tingkat_ini && !$is_di_tingkat_lain) {
        $available_siswa_selectable_count++;
    }
    $nk = (string)($rowSb['nama_kelas'] ?? '-');
    if (!isset($available_siswa_by_kelas[$nk])) {
        $available_siswa_by_kelas[$nk] = [];
    }
    $available_siswa_by_kelas[$nk][] = $rowSb;
}
ksort($available_siswa_by_kelas, SORT_NATURAL | SORT_FLAG_CASE);
$barung_modal_tabs_kelas = count($available_siswa_by_kelas) > 1;

$barung_kelas_hint = '';
$barung_golongan_hint = barung_golongan_for_slug($barung_tingkat_slug) ?? '';
switch ($barung_tingkat_slug) {
    case 'pra_mula':
        $barung_kelas_hint = 'Kelas 1–2, usia kurang dari 11 tahun';
        break;
    case 'mula':
        $barung_kelas_hint = 'Kelas 2–6, usia kurang dari 11 tahun';
        break;
    case 'bantu':
        $barung_kelas_hint = 'Kelas 2–6, usia kurang dari 11 tahun';
        break;
    case 'tata':
        $barung_kelas_hint = 'Kelas 4–6, usia kurang dari 11 tahun';
        break;
    case 'pra_ramu':
        $barung_kelas_hint = 'Siswa usia 11 tahun ke atas';
        break;
    case 'ramu':
        $barung_kelas_hint = 'Siswa usia 11 tahun ke atas';
        break;
    case 'garuda':
        $barung_kelas_hint = 'Kelas 3–6';
        break;
    default:
        $barung_kelas_hint = '';
}
$barung_bisa_modal_tambah = $selected_tingkat_id > 0 && $barung_tingkat_slug !== null && $barung_kelas_allowed !== [];

// Page-specific JS
$js_page = [];
if ($message) {
    $swal_icon = $message['type'] === 'success' ? 'success' : ($message['type'] === 'warning' ? 'warning' : 'error');
    $swal_title = $message['type'] === 'success' ? 'Berhasil!' : 'Perhatian!';
    $swal_text = json_encode($message['text']);
    $js_page[] = "
        Swal.fire({
            icon: '{$swal_icon}',
            title: '{$swal_title}',
            text: {$swal_text},
            timer: " . ($message['type'] === 'success' ? 1800 : 2500) . ",
            showConfirmButton: false
        });
    ";
}

$js_page[] = <<<'JS_BLOCK'
$(document).ready(function() {
    var hasManageColumn = $('#checkAllRows').length > 0;
    var numberColumnIndex = hasManageColumn ? 1 : 0;
    var nameColumnIndex = hasManageColumn ? 2 : 1;
    var actionColumnIndex = hasManageColumn ? ($('#table-1 thead th').length - 1) : null;
    var unsortableColumns = [numberColumnIndex];
    if (hasManageColumn) {
        unsortableColumns.push(0, actionColumnIndex);
    }

    var table = $('#table-1').DataTable({
        'order': [[nameColumnIndex, 'asc']],
        'paging': false,
        'columnDefs': [
            { 'sortable': false, 'targets': unsortableColumns }
        ],
        'language': {
            'zeroRecords': 'Tidak ada data yang ditemukan',
            'search': 'Cari:'
        }
    });

    table.on('order.dt search.dt draw.dt', function() {
        var info = table.page.info();
        var start = info.page * info.length;
        table.column(numberColumnIndex, { search: 'applied', order: 'applied' }).nodes().each(function(cell, i) {
            $(cell).text(start + i + 1);
        });
    }).draw();

    // Edit modal fill
    $(document).on('click', '.edit-btn', function() {
        $('#edit_id_peserta_didik_barung').val($(this).data('id'));
        $('#edit_id_tingkat_barung').val($(this).data('tingkat'));
        $('#edit_nama_peserta_didik').val($(this).data('nama') || '');
        $('#edit_nta').val($(this).data('nta') || '');
        $('#edit_tempat_lahir').val($(this).data('tempat') || '');
        $('#edit_tanggal_lahir').val($(this).data('tanggal') || '');
        $('#editModal').modal('show');
    });

    // Keluarkan confirmation
    $(document).on('click', '.delete-btn', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var nama = $(this).data('nama') || '-';
        var tingkat = $(this).data('tingkat') || '';
        Swal.fire({
            title: 'Konfirmasi Keluarkan',
            text: 'Apakah Anda yakin ingin mengeluarkan "' + nama + '" dari daftar?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, Keluarkan',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                var form = $('<form method="POST" action="">' +
                    '<input type="hidden" name="id_peserta_didik_barung" value="' + id + '">' +
                    '<input type="hidden" name="id_tingkat_barung" value="' + tingkat + '">' +
                    '<input type="hidden" name="keluarkan_peserta_didik" value="1">' +
                    '</form>');
                $('body').append(form);
                form.submit();
            }
        });
    });

    function updateDeleteSelectedUI() {
        var checkedCount = $('.row-check:checked').length;
        if (checkedCount > 0) {
            $('#btn-delete-selected').removeClass('d-none').text('Keluarkan Terpilih (' + checkedCount + ')');
            $('#btn-edit-selected').removeClass('d-none').text('Edit Terpilih (' + checkedCount + ')');
        } else {
            $('#btn-delete-selected').addClass('d-none').text('Keluarkan Terpilih');
            $('#btn-edit-selected').addClass('d-none').text('Edit Terpilih');
        }
    }

    // Select all (only visible rows under current filter)
    $('#checkAllRows').on('change', function() {
        var isChecked = $(this).prop('checked');
        var rows = table.rows({ search: 'applied' }).nodes();
        $('.row-check', rows).prop('checked', isChecked);
        updateDeleteSelectedUI();
    });

    // Row checkbox handler
    $('#table-1 tbody').on('change', '.row-check', function() {
        var rows = table.rows({ search: 'applied' }).nodes();
        var total = $('.row-check', rows).length;
        var checked = $('.row-check:checked', rows).length;
        $('#checkAllRows').prop('checked', total > 0 && total === checked);
        updateDeleteSelectedUI();
    });

    // Reset when table redraws (paging/search)
    table.on('draw.dt', function() {
        $('#checkAllRows').prop('checked', false);
        updateDeleteSelectedUI();
    });

    // Edit banyak (isi tabel dari baris tercentang)
    $('#btn-edit-selected').on('click', function (e) {
        e.preventDefault();
        var tingkat = $('#id_tingkat_barung_hidden').val() || '';
        var $tbody = $('#bulkEditTableBody');
        $tbody.empty();

        var rows = [];
        $('.row-check:checked').each(function () {
            var $cb = $(this);
            rows.push({
                id: $cb.val(),
                nama: ($cb.attr('data-nama') !== undefined) ? $cb.attr('data-nama') : ($cb.data('nama') || ''),
                nta: ($cb.attr('data-nta') !== undefined) ? $cb.attr('data-nta') : ($cb.data('nta') || ''),
                tempat: ($cb.attr('data-tempat') !== undefined) ? $cb.attr('data-tempat') : ($cb.data('tempat') || ''),
                tanggal: ($cb.attr('data-tanggal') !== undefined) ? $cb.attr('data-tanggal') : ($cb.data('tanggal') || '')
            });
        });

        if (!rows.length) return;

        $('#bulk_edit_id_tingkat').val(tingkat);

        rows.forEach(function (r, idx) {
            var $tr = $('<tr>');
            $tr.append($('<td class="text-center text-muted align-middle">').text(idx + 1));
            $tr.append($('<td>').append(
                $('<input>', { type: 'hidden', name: 'bulk_id[]', value: r.id }),
                $('<input>', {
                    type: 'text',
                    name: 'bulk_nama[]',
                    class: 'form-control form-control-sm',
                    required: true,
                    value: r.nama
                })
            ));
            $tr.append($('<td>').append(
                $('<input>', {
                    type: 'text',
                    name: 'bulk_nta[]',
                    class: 'form-control form-control-sm',
                    value: r.nta
                })
            ));
            $tr.append($('<td>').append(
                $('<input>', {
                    type: 'text',
                    name: 'bulk_tempat[]',
                    class: 'form-control form-control-sm',
                    value: r.tempat
                })
            ));
            $tr.append($('<td>').append(
                $('<input>', {
                    type: 'date',
                    name: 'bulk_tanggal[]',
                    class: 'form-control form-control-sm',
                    value: r.tanggal || ''
                })
            ));
            $tbody.append($tr);
        });

        $('#editBulkModal').modal('show');
    });

    // Multiple keluarkan button
    $('#btn-delete-selected').on('click', function(e) {
        e.preventDefault();
        var ids = $('.row-check:checked').map(function() { return $(this).val(); }).get();
        if (!ids || ids.length === 0) return;

        Swal.fire({
            title: 'Konfirmasi Keluarkan',
            text: 'Keluarkan ' + ids.length + ' peserta didik terpilih dari daftar?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Keluarkan',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                var form = $('<form method="POST" action="">' +
                    '<input type="hidden" name="id_tingkat_barung" value="' + $('#id_tingkat_barung_hidden').val() + '">' +
                    '<input type="hidden" name="keluarkan_peserta_didik_multiple" value="1">' +
                    '</form>');
                ids.forEach(function(id) {
                    form.append('<input type="hidden" name="selected_ids[]" value="' + id + '">');
                });
                $('body').append(form);
                form.submit();
            }
        });
    });

    updateDeleteSelectedUI();

    $('#checkAllBarungSiswa').on('change', function () {
        var on = $(this).is(':checked');
        $('#modalTambahAnggotaBarung .check-siswa-barung:visible').prop('checked', on);
        $('.check-all-barung-pane').prop('checked', on);
    });

    $(document).on('change', '.check-all-barung-pane', function () {
        var on = $(this).is(':checked');
        $(this).closest('.tab-pane').find('.check-siswa-barung:visible').prop('checked', on);
        syncGlobalCheckBarungModal();
    });

    $(document).on('change', '#modalTambahAnggotaBarung .check-siswa-barung', function () {
        var $pane = $(this).closest('.tab-pane');
        if (!$pane.length) {
            syncGlobalCheckBarungModal();
            return;
        }
        var $checks = $pane.find('.check-siswa-barung');
        var $allOn = $pane.find('.check-all-barung-pane');
        var ok = $checks.length && $checks.length === $checks.filter(':checked').length;
        $allOn.prop('checked', ok);
        syncGlobalCheckBarungModal();
    });

    function syncGlobalCheckBarungModal() {
        var $all = $('#modalTambahAnggotaBarung .check-siswa-barung:visible');
        if (!$all.length) {
            $('#checkAllBarungSiswa').prop('checked', false);
            return;
        }
        $('#checkAllBarungSiswa').prop(
            'checked',
            $all.length === $all.filter(':checked').length
        );
    }

    function applySelectableOnlyFilter() {
        var onlySelectable = $('#filterSelectableOnlyBarung').is(':checked');
        var $rows = $('#modalTambahAnggotaBarung tr.row-siswa-barung');
        if (onlySelectable) {
            $rows.each(function() {
                var blocked = $(this).data('blocked') === 1 || $(this).data('blocked') === '1';
                $(this).toggleClass('d-none', blocked);
                if (blocked) {
                    $(this).find('.check-siswa-barung').prop('checked', false);
                }
            });
        } else {
            $rows.removeClass('d-none');
        }
        syncGlobalCheckBarungModal();
    }

    $('#filterSelectableOnlyBarung').on('change', applySelectableOnlyFilter);
    $('#modalTambahAnggotaBarung').on('shown.bs.modal', function() {
        applySelectableOnlyFilter();
    });

    // AJAX Import with progress bar (upload progress + processing state)
    $('#importForm').on('submit', function(e) {
        e.preventDefault();
        var form = this;
        var formData = new FormData(form);
        formData.append('ajax', '1');

        // UI state
        $('#importProgressWrap').removeClass('d-none');
        $('#importProgressBar').css('width', '0%').attr('aria-valuenow', 0).text('0%');
        $('#importStatusText').text('Mengunggah file...');
        $('#importSubmitBtn').prop('disabled', true);
        $('#importCloseBtn').prop('disabled', true);
        $('.import-template-btn').addClass('disabled').attr('aria-disabled', 'true');

        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.location.href, true);

        xhr.upload.onprogress = function(ev) {
            if (!ev.lengthComputable) return;
            var pct = Math.round((ev.loaded / ev.total) * 100);
            if (pct > 100) pct = 100;
            $('#importProgressBar').css('width', pct + '%').attr('aria-valuenow', pct).text(pct + '%');
            if (pct >= 100) {
                $('#importStatusText').text('Memproses data...');
            }
        };

        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;

            $('#importSubmitBtn').prop('disabled', false);
            $('#importCloseBtn').prop('disabled', false);
            $('.import-template-btn').removeClass('disabled').removeAttr('aria-disabled');

            var resp = null;
            try { resp = JSON.parse(xhr.responseText); } catch (e) {}

            if (xhr.status !== 200 || !resp) {
                Swal.fire({ icon: 'error', title: 'Gagal', text: 'Import gagal. Silakan coba lagi.' });
                return;
            }

            var icon = (resp.type === 'success') ? 'success' : (resp.type === 'warning' ? 'warning' : 'error');
            Swal.fire({ icon: icon, title: resp.ok ? 'Berhasil!' : 'Perhatian!', text: resp.message })
                .then(function() {
                    var tingkat = resp.selected_tingkat_id || $('select[name="id_tingkat_barung"]', form).val() || '';
                    var url = new URL(window.location.href);
                    if (tingkat) url.searchParams.set('tingkat', tingkat);
                    url.searchParams.delete('download_template');
                    url.searchParams.delete('format');
                    window.location.href = url.toString();
                });
        };

        xhr.send(formData);
    });
});

function cleanBarungExportTable(table) {
    var clonedTable = table.cloneNode(true);
    var hasManageColumn = table.querySelector('#checkAllRows') !== null;
    var actionIndex = hasManageColumn && table.rows.length ? table.rows[0].cells.length - 1 : -1;
    var rows = clonedTable.rows;

    for (var i = 0; i < rows.length; i++) {
        if (actionIndex >= 0 && rows[i].cells.length > actionIndex) {
            rows[i].deleteCell(actionIndex);
        }
        if (hasManageColumn && rows[i].cells.length > 0) {
            rows[i].deleteCell(0);
        }
    }

    return clonedTable;
}

function exportToExcel() {
    var table = document.getElementById('table-1');
    if (!table) return;
    
    var schoolName = $('#schoolName').val() || 'MADRASAH';
    var academicYear = $('#academicYear').val() || '-';
    var tingkatName = $('#tingkatName').val() || '';
    
    var newTable = cleanBarungExportTable(table);
    
    if (typeof XLSX !== 'undefined') {
        var wb = XLSX.utils.book_new();
        
        var headerAOA = [
            [schoolName.toUpperCase()],
            ["DATA ANGGOTA PRAMUKA"],
            ["TINGKAT: " + tingkatName.toUpperCase()],
            ["TAHUN AJARAN: " + academicYear],
            []
        ];
        var finalWS = XLSX.utils.aoa_to_sheet(headerAOA);
        XLSX.utils.sheet_add_dom(finalWS, newTable, { origin: -1 });
        
        XLSX.utils.book_append_sheet(wb, finalWS, "Anggota Pramuka");
        XLSX.writeFile(wb, 'data_peserta_didik_barung_' + tingkatName.replace(/\s+/g, '_') + '_' + academicYear.replace(/\//g, '-') + '.xlsx');
    } else {
        var html = newTable.outerHTML;
        var a = document.createElement('a');
        a.href = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
        a.download = 'data_peserta_didik_barung.xls';
        a.click();
    }
}

function exportToPDF() {
    var table = document.getElementById('table-1');
    if (!table) return;
    
    var schoolName = $('#schoolName').val() || 'MADRASAH';
    var schoolLogo = $('#schoolLogo').val() || '';
    var academicYear = $('#academicYear').val() || '-';
    var tingkatName = $('#tingkatName').val() || '';
    var ketuaGudep = $('#ketuaGudep').val() || '-';
    var ntaKetuaGudep = $('#ntaKetuaGudep').val() || '-';
    var printPlace = $('#printPlace').val() || 'Padang';
    var printDate = $('#printDate').val() || '';
    
    // Generate QR Code content
    var qrContent = "Dokumen Sah: " + schoolName + "\nKetua Gudep: " + ketuaGudep + "\nNTA: " + ntaKetuaGudep;
    var qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=" + encodeURIComponent(qrContent);
    
    // Create a new window for printing
    var printWindow = window.open('', '_blank');
    printWindow.document.write('<html><head><title>Data Peserta Didik Barung ' + academicYear + '</title>');
    printWindow.document.write('<style>');
    printWindow.document.write('table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }');
    printWindow.document.write('th, td { border: 1px solid #000; padding: 8px; text-align: left; }');
    printWindow.document.write('th { background-color: #f2f2f2; }');
    printWindow.document.write('h2, h3 { text-align: center; margin: 2px 0; }');
    printWindow.document.write('.header-container { display: flex; align-items: center; justify-content: center; margin-bottom: 20px; position: relative; }');
    printWindow.document.write('.logo { position: absolute; left: 0; top: 0; height: 70px; }');
    printWindow.document.write('.header-text { text-align: center; width: 100%; }');
    printWindow.document.write('.signature-container { margin-top: 40px; float: right; text-align: left; width: 280px; }');
    printWindow.document.write('.signature-header { text-align: left; margin-bottom: 5px; }');
    printWindow.document.write('.signature-space { height: 90px; display: flex; align-items: flex-end; justify-content: flex-start; margin-bottom: 5px; }');
    printWindow.document.write('.qr-code { height: 80px; width: 80px; margin-right: 10px; }');
    printWindow.document.write('.signature-info { text-align: left; }');
    printWindow.document.write('.no-print { display: none; }');
    printWindow.document.write('</style></head><body>');
    
    printWindow.document.write('<div class="header-container">');
    if (schoolLogo) {
        printWindow.document.write('<img src="' + schoolLogo + '" class="logo">');
    }
    printWindow.document.write('<div class="header-text">');
    printWindow.document.write('<h2>' + schoolName.toUpperCase() + '</h2>');
    printWindow.document.write('<h3>DATA ANGGOTA PRAMUKA</h3>');
    printWindow.document.write('<h3>TINGKAT: ' + tingkatName.toUpperCase() + '</h3>');
    printWindow.document.write('<h3>TAHUN AJARAN: ' + academicYear + '</h3>');
    printWindow.document.write('</div>');
    printWindow.document.write('</div>');
    printWindow.document.write('<hr style="border: 1px solid #000; margin-bottom: 20px;">');
    
    var cleanTable = cleanBarungExportTable(table);
    
    printWindow.document.write(cleanTable.outerHTML);
    
    // Add signature section
    printWindow.document.write('<div class="signature-container">');
    printWindow.document.write('<div class="signature-header">');
    printWindow.document.write('<p>' + printPlace + ', ' + printDate + '</p>');
    printWindow.document.write('<p>Ketua Gudep,</p>');
    printWindow.document.write('</div>');
    printWindow.document.write('<div class="signature-space">');
    printWindow.document.write('<img src="' + qrUrl + '" class="qr-code">');
    printWindow.document.write('</div>');
    printWindow.document.write('<div class="signature-info">');
    printWindow.document.write('<p><strong>' + ketuaGudep + '</strong></p>');
    printWindow.document.write('<p>NTA. ' + ntaKetuaGudep + '</p>');
    printWindow.document.write('</div>');
    printWindow.document.write('</div>');
    
    printWindow.document.write('<script>window.onload = function() { setTimeout(function() { window.print(); window.close(); }, 500); }<\/script>');
    printWindow.document.write('</body></html>');
    printWindow.document.close();
}
JS_BLOCK;

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Data Anggota Pramuka</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Ekstrakurikuler</div>
                <div class="breadcrumb-item">Pramuka</div>
            </div>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4>Data Anggota Pramuka (<?= htmlspecialchars($selected_tingkat_name !== '' ? $selected_tingkat_name : 'Semua') ?>)</h4>
                    <div class="card-header-action">
                        <button type="button" class="btn btn-success" onclick="exportToExcel()" <?php echo $selected_tingkat_id > 0 ? '' : 'disabled'; ?>>
                            <i class="fas fa-file-excel"></i> Excel
                        </button>
                        <button type="button" class="btn btn-warning ml-1" onclick="exportToPDF()" <?php echo $selected_tingkat_id > 0 ? '' : 'disabled'; ?>>
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                        <?php if ($can_manage_barung): ?>
                        <button class="btn btn-primary ml-1" data-toggle="modal" data-target="#modalTambahAnggotaBarung" type="button" <?php echo $barung_bisa_modal_tambah ? '' : 'disabled'; ?> title="<?= $barung_bisa_modal_tambah ? '' : 'Pilih tab Pra Mula / Mula / Bantu / Tata / Pra Ramu / Ramu untuk menambah dari data siswa' ?>">
                            <i class="fas fa-plus"></i> Tambah
                        </button>
                        <button class="btn btn-info ml-1" data-toggle="modal" data-target="#importModal" type="button" <?php echo $selected_tingkat_id > 0 ? '' : 'disabled'; ?>>
                            <i class="fas fa-file-import"></i> Import
                        </button>
                        <button class="btn btn-warning ml-1 d-none" id="btn-edit-selected" type="button" <?php echo $selected_tingkat_id > 0 ? '' : 'disabled'; ?>>
                            <i class="fas fa-edit"></i> Edit Terpilih
                        </button>
                        <button class="btn btn-danger ml-1 d-none" id="btn-delete-selected" type="button" <?php echo $selected_tingkat_id > 0 ? '' : 'disabled'; ?>>
                            <i class="fas fa-sign-out-alt"></i> Keluarkan Terpilih
                        </button>
                        <a href="cleanup_pramuka_alumni.php" class="btn btn-secondary ml-1" title="Membersihkan data alumni dari pramuka">
                            <i class="fas fa-broom"></i> Cleanup Alumni
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-body">
                    <input type="hidden" id="schoolName" value="<?= htmlspecialchars($school_profile['nama_madrasah'] ?? 'MADRASAH') ?>">
                    <input type="hidden" id="schoolLogo" value="<?= !empty($school_profile['logo']) ? '../assets/img/' . $school_profile['logo'] : '' ?>">
                    <input type="hidden" id="academicYear" value="<?= htmlspecialchars($school_profile['tahun_ajaran'] ?? '-') ?>">
                    <input type="hidden" id="ketuaGudep" value="<?= htmlspecialchars($print_settings_data['ketua_gudep'] ?? '-') ?>">
                    <input type="hidden" id="ntaKetuaGudep" value="<?= htmlspecialchars($print_settings_data['nta_ketua_gudep'] ?? '-') ?>">
                    <input type="hidden" id="printPlace" value="<?= htmlspecialchars($print_settings_data['tempat_surat'] ?? 'Padang') ?>">
                    <input type="hidden" id="printDate" value="<?= htmlspecialchars($print_settings_data['tanggal_surat'] ?? date('d F Y')) ?>">
                    <input type="hidden" id="tingkatName" value="<?= htmlspecialchars($selected_tingkat_name) ?>">
                    <input type="hidden" id="id_tingkat_barung_hidden" value="<?= (int)$selected_tingkat_id ?>">
                    <?php if ($can_manage_barung && ((int)($auto_pra_ramu_result['added'] ?? 0) > 0 || (int)($auto_pra_ramu_result['closed_siaga'] ?? 0) > 0)): ?>
                        <div class="alert alert-info">
                            Sistem otomatis memasukkan <?= (int)($auto_pra_ramu_result['added'] ?? 0) ?> siswa usia 11 tahun ke tingkat <strong>Pra Ramu</strong>
                            <?php if ((int)($auto_pra_ramu_result['closed_siaga'] ?? 0) > 0): ?>
                                dan menutup <?= (int)($auto_pra_ramu_result['closed_siaga'] ?? 0) ?> catatan aktif di golongan Siaga.
                            <?php else: ?>
                                .
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($schema_error || $fetch_error || $table_error): ?>
                        <div class="alert alert-danger">
                            <strong>Terjadi masalah pada database.</strong><br>
                            <?php if (!empty($schema_error)) echo htmlspecialchars($schema_error) . '<br>'; ?>
                            <?php if (!empty($fetch_error)) echo htmlspecialchars($fetch_error) . '<br>'; ?>
                            <?php if (!empty($table_error)) echo htmlspecialchars($table_error); ?>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <ul class="nav nav-pills flex-wrap" role="tablist">
                            <?php if (!empty($tingkat_list)): ?>
                                <?php foreach ($tingkat_list as $index => $t): ?>
                                    <?php
                                        $tid = (int)($t['id_tingkat_barung'] ?? 0);
                                        $active = $tid === $selected_tingkat_id;
                                        $golongan = barung_golongan_for_tingkat($t['nama_tingkat'] ?? '', $t['golongan'] ?? 'Siaga');
                                        if ($golongan === 'Penggalang') {
                                            $pill_class = $active ? 'nav-link active bg-danger border-danger' : 'nav-link border border-danger text-danger';
                                            $badge_class = $active ? 'badge-light' : 'badge-danger';
                                        } else {
                                            $pill_class = $active ? 'nav-link active bg-success border-success' : 'nav-link border border-success text-success';
                                            $badge_class = $active ? 'badge-light' : 'badge-success';
                                        }
                                        $is_first = $index === 0;
                                        $is_last = $index === count($tingkat_list) - 1;
                                    ?>
                                    <li class="nav-item" style="margin: 0;">
                                        <a href="?tingkat=<?= $tid ?>" 
                                           class="nav-link py-1 px-3 <?= $pill_class ?>" 
                                           role="tab" 
                                           style="pointer-events: auto; transition: none; <?= !$is_first ? 'border-left: 0; margin-left: -1px;' : '' ?> <?= !$is_last ? 'border-right: 0;' : '' ?> border-radius: 0;<?= $is_first ? ' border-top-left-radius: 4px; border-bottom-left-radius: 4px;' : '' ?><?= $is_last ? ' border-top-right-radius: 4px; border-bottom-right-radius: 4px;' : '' ?>">
                                            <?= htmlspecialchars($t['nama_tingkat']) ?>
                                            <span class="badge <?= $badge_class ?> ml-1"><?= htmlspecialchars($golongan) ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li class="nav-item">
                                    <span class="nav-link disabled text-muted py-1 px-3">
                                        Belum ada tingkat barung. Silakan buat di menu <strong>Data Tingkat Barung</strong>.
                                    </span>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <div class="row mb-3">
                        <div class="col-12 col-md-4 col-lg-3 mb-2">
                            <div class="border rounded bg-light px-3 py-2 h-100">
                                <div class="text-muted small">Total Anggota</div>
                                <div class="h5 mb-0"><?= (int)count($peserta_rows) ?> siswa</div>
                            </div>
                        </div>
                        <?php foreach ($kelas_summary_barung as $nama_kelas_info => $jumlah_kelas_info): ?>
                            <div class="col-6 col-md-3 col-lg-2 mb-2">
                                <div class="border rounded px-3 py-2 h-100">
                                    <div class="text-muted small">Kelas <?= htmlspecialchars($nama_kelas_info, ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="h6 mb-0"><?= (int)$jumlah_kelas_info ?> siswa</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($kelas_summary_unmapped > 0): ?>
                            <div class="col-6 col-md-3 col-lg-2 mb-2">
                                <div class="border rounded px-3 py-2 h-100">
                                    <div class="text-muted small">Belum Terkait Kelas</div>
                                    <div class="h6 mb-0"><?= (int)$kelas_summary_unmapped ?> siswa</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-striped" id="table-1">
                            <thead>
                                <tr>
                                    <?php if ($can_manage_barung): ?><th class="text-center" width="36px"><input type="checkbox" id="checkAllRows"></th><?php endif; ?>
                                    <th class="text-center" width="6%">No</th>
                                    <th>Nama Peserta Didik</th>
                                    <th width="10%">Kelas</th>
                                    <th width="14%">NTA</th>
                                    <th>Tempat Lahir</th>
                                    <th width="14%">Tanggal Lahir</th>
                                    <th width="14%">Usia</th>
                                    <?php if ($can_manage_barung): ?><th width="15%">Aksi</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($peserta_rows)): ?>
                                    <?php foreach ($peserta_rows as $idx => $row): ?>
                                        <tr>
                                            <?php if ($can_manage_barung): ?><td class="text-center">
                                                <input type="checkbox" class="row-check" value="<?= (int)($row['id_peserta_didik_barung'] ?? 0) ?>"
                                                    data-nama="<?= htmlspecialchars(htmlspecialchars_decode($row['nama_peserta_didik'] ?? '', ENT_QUOTES), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-nta="<?= htmlspecialchars($row['nta'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                    data-tempat="<?= htmlspecialchars($row['tempat_lahir'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                    data-tanggal="<?= htmlspecialchars(!empty($row['tanggal_lahir']) ? substr((string)$row['tanggal_lahir'], 0, 10) : '', ENT_QUOTES, 'UTF-8') ?>">
                                            </td><?php endif; ?>
                                            <td class="text-center"><?= (int)($idx + 1) ?></td>
                                            <td><?= htmlspecialchars(htmlspecialchars_decode($row['nama_peserta_didik'] ?? '', ENT_QUOTES)) ?></td>
                                            <td><?= htmlspecialchars($row['nama_kelas'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($row['nta'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['tempat_lahir'] ?? '') ?></td>
                                            <td><?= !empty($row['tanggal_lahir']) ? htmlspecialchars(date('d-m-Y', strtotime((string)$row['tanggal_lahir']))) : '' ?></td>
                                            <td><?= htmlspecialchars(barung_format_usia($row['tanggal_lahir'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                                            <?php if ($can_manage_barung): ?><td>
                                                <button class="btn btn-warning btn-sm edit-btn"
                                                    data-id="<?= (int)$row['id_peserta_didik_barung'] ?>"
                                                    data-tingkat="<?= (int)$row['id_tingkat_barung'] ?>"
                                                    data-nama="<?= htmlspecialchars(htmlspecialchars_decode($row['nama_peserta_didik'] ?? '', ENT_QUOTES), ENT_QUOTES) ?>"
                                                    data-nta="<?= htmlspecialchars($row['nta'] ?? '', ENT_QUOTES) ?>"
                                                    data-tempat="<?= htmlspecialchars($row['tempat_lahir'] ?? '', ENT_QUOTES) ?>"
                                                    data-tanggal="<?= htmlspecialchars($row['tanggal_lahir'] ?? '', ENT_QUOTES) ?>"
                                                    type="button">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-danger btn-sm delete-btn"
                                                    data-id="<?= (int)$row['id_peserta_didik_barung'] ?>"
                                                    data-tingkat="<?= (int)$row['id_tingkat_barung'] ?>"
                                                    data-nama="<?= htmlspecialchars($row['nama_peserta_didik'] ?? '', ENT_QUOTES) ?>"
                                                    type="button">
                                                    <i class="fas fa-sign-out-alt"></i>
                                                </button>
                                            </td><?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php if ($can_manage_barung): ?>
<!-- Add Modal: tambah dari data siswa (filter kelas sesuai tab tingkat) -->
<div class="modal fade" id="modalTambahAnggotaBarung" tabindex="-1" role="dialog" aria-labelledby="modalTambahAnggotaBarungLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="add_peserta_kolektif" value="1">
                <input type="hidden" name="id_tingkat_barung" value="<?= (int)$selected_tingkat_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTambahAnggotaBarungLabel">
                        Tambah Anggota — <?= htmlspecialchars($selected_tingkat_name !== '' ? $selected_tingkat_name : '—') ?>
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <?php if (!$barung_bisa_modal_tambah): ?>
                        <div class="alert alert-warning mb-0">
                            Pemilihan siswa dari data kelas otomatis hanya untuk tingkat <strong>Pra Mula</strong>, <strong>Mula</strong>, <strong>Bantu</strong>, <strong>Tata</strong>, <strong>Pra Ramu</strong>, <strong>Ramu</strong>, atau <strong>Garuda</strong>. Gunakan tombol Import untuk tingkat lain.
                        </div>
                    <?php else: ?>
                        <?php if (!empty($barung_avail_sql_error)): ?>
                            <div class="alert alert-danger mb-3 small">
                                <strong>Gagal memuat daftar siswa:</strong>
                                <?= htmlspecialchars($barung_avail_sql_error, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>
                        <p class="text-muted mb-3">
                            Menampilkan siswa dari <strong><?= htmlspecialchars($barung_kelas_hint !== '' ? $barung_kelas_hint : 'kelas sesuai aturan tingkat') ?></strong>
                            untuk tingkat ini<?= $barung_golongan_hint !== '' ? ' (' . htmlspecialchars($barung_golongan_hint, ENT_QUOTES, 'UTF-8') . ')' : '' ?>.
                            Batas usia <strong>Siaga</strong> maksimal 10 tahun 11 bulan; siswa yang sudah 11 tahun masuk <strong>Penggalang</strong> mulai tingkat <strong>Pra Ramu</strong>. Yang sudah terdaftar ditandai dan tidak bisa dipilih ulang. Kolom <strong>NTA</strong> bisa dikosongkan dulu dan diisi kemudian lewat tombol Edit.
                        </p>
                        <?php if ($barung_avail_usia_filtered > 0): ?>
                            <div class="alert alert-light border mb-3 small">
                                <?= (int)$barung_avail_usia_filtered ?> siswa tidak ditampilkan karena tidak sesuai batas usia golongan tingkat ini.
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($available_siswa_barung)): ?>
                            <div class="form-group mb-2">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="checkAllBarungSiswa">
                                    <label class="custom-control-label" for="checkAllBarungSiswa"><?= !empty($barung_modal_tabs_kelas) ? 'Pilih semua (semua kelas)' : 'Pilih semua' ?></label>
                                </div>
                            </div>
                            <div class="form-group mb-2">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="filterSelectableOnlyBarung">
                                    <label class="custom-control-label" for="filterSelectableOnlyBarung">Tampilkan hanya siswa yang bisa dipilih</label>
                                </div>
                            </div>
                            <?php if (!empty($barung_modal_tabs_kelas)): ?>
                                <ul class="nav nav-tabs flex-wrap barung-modal-tabs-kelas" role="tablist">
                                    <?php
                                    $ti = 0;
                                    foreach ($available_siswa_by_kelas as $nama_kelas_tab => $rows_tab):
                                        $cnt_tab = count($rows_tab);
                                        ?>
                                        <li class="nav-item">
                                            <a class="nav-link <?= $ti === 0 ? 'active' : '' ?>" id="barung-tab-kelas-<?= $ti ?>" data-toggle="tab" href="#barung-pane-kelas-<?= $ti ?>" role="tab">
                                                <?= htmlspecialchars($nama_kelas_tab) ?>
                                                <span class="badge badge-secondary ml-1"><?= (int)$cnt_tab ?></span>
                                            </a>
                                        </li>
                                        <?php
                                        $ti++;
                                    endforeach;
                                    ?>
                                </ul>
                                <div class="tab-content border border-top-0 rounded-bottom bg-white p-3" style="max-height: 380px; overflow-y: auto;">
                                    <?php
                                    $ti = 0;
                                    foreach ($available_siswa_by_kelas as $nama_kelas_tab => $rows_tab):
                                        ?>
                                        <div class="tab-pane fade <?= $ti === 0 ? 'show active' : '' ?>" id="barung-pane-kelas-<?= $ti ?>" role="tabpanel">
                                            <div class="custom-control custom-checkbox mb-2">
                                                <input type="checkbox" class="custom-control-input check-all-barung-pane" id="check-pane-barung-<?= $ti ?>">
                                                <label class="custom-control-label" for="check-pane-barung-<?= $ti ?>">Pilih semua di kelas ini</label>
                                            </div>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-bordered mb-0">
                                                    <thead class="thead-light">
                                                        <tr>
                                                            <th style="width: 40px;">#</th>
                                                            <th>NISN</th>
                                                            <th>Nama Siswa</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($rows_tab as $s): ?>
                                                            <?php
                                                            $sudah_terdaftar = (int)($s['sudah_terdaftar'] ?? 0) === 1;
                                                            $terdaftar_lain = (int)($s['sudah_di_tingkat_lain'] ?? 0) === 1;
                                                            $label_tingkat_lain = trim((string)($s['tingkat_aktif_lain'] ?? ''));
                                                            $is_disabled_pick = $sudah_terdaftar || $terdaftar_lain;
                                                            ?>
                                                            <tr class="row-siswa-barung" data-blocked="<?= $is_disabled_pick ? '1' : '0' ?>">
                                                                <td class="text-center">
                                                                    <?php if ($is_disabled_pick): ?>
                                                                        <input type="checkbox" disabled>
                                                                    <?php else: ?>
                                                                        <input type="checkbox" class="check-siswa-barung" name="selected_siswa[]" value="<?= (int)$s['id_siswa'] ?>">
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td><?= htmlspecialchars($s['nisn'] ?? '-') ?></td>
                                                                <td>
                                                                    <?= htmlspecialchars($s['nama_siswa'] ?? '') ?>
                                                                    <?php if ($sudah_terdaftar): ?>
                                                                        <span class="badge badge-secondary ml-1">sudah terdaftar</span>
                                                                    <?php elseif ($terdaftar_lain): ?>
                                                                        <span class="badge badge-warning ml-1">aktif di tingkat <?= htmlspecialchars($label_tingkat_lain !== '' ? $label_tingkat_lain : 'lain') ?></span>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <?php
                                        $ti++;
                                    endforeach;
                                    ?>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
                                    <table class="table table-sm table-bordered mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th style="width: 40px;">#</th>
                                                <th>Kelas</th>
                                                <th>NISN</th>
                                                <th>Nama Siswa</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($available_siswa_barung as $s): ?>
                                                <?php
                                                $sudah_terdaftar = (int)($s['sudah_terdaftar'] ?? 0) === 1;
                                                $terdaftar_lain = (int)($s['sudah_di_tingkat_lain'] ?? 0) === 1;
                                                $label_tingkat_lain = trim((string)($s['tingkat_aktif_lain'] ?? ''));
                                                $is_disabled_pick = $sudah_terdaftar || $terdaftar_lain;
                                                ?>
                                                <tr class="row-siswa-barung" data-blocked="<?= $is_disabled_pick ? '1' : '0' ?>">
                                                    <td class="text-center">
                                                        <?php if ($is_disabled_pick): ?>
                                                            <input type="checkbox" disabled>
                                                        <?php else: ?>
                                                            <input type="checkbox" class="check-siswa-barung" name="selected_siswa[]" value="<?= (int)$s['id_siswa'] ?>">
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= htmlspecialchars($s['nama_kelas'] ?? '-') ?></td>
                                                    <td><?= htmlspecialchars($s['nisn'] ?? '-') ?></td>
                                                    <td>
                                                        <?= htmlspecialchars($s['nama_siswa'] ?? '') ?>
                                                        <?php if ($sudah_terdaftar): ?>
                                                            <span class="badge badge-secondary ml-1">sudah terdaftar</span>
                                                        <?php elseif ($terdaftar_lain): ?>
                                                            <span class="badge badge-warning ml-1">aktif di tingkat <?= htmlspecialchars($label_tingkat_lain !== '' ? $label_tingkat_lain : 'lain') ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <?php if (!empty($barung_avail_kelas_tidak_terpetakan)): ?>
                                    <strong>Belum ada kelas yang dikenali sebagai bagian tingkat ini.</strong> Nama kelas di master harus bisa dipetakan ke kelas 1–6 (biasanya «I», «II», … «VI», atau angka «1», «2», … atau <code>id_kelas</code> bernilai 1–6 untuk kelas 1–6). Perbarui nama kelas di menu Kelas atau pastikan siswa sudah ada di kelas yang sesuai.
                                <?php else: ?>
                                    Tidak ada siswa baru yang dapat ditambahkan pada rentang kelas/usia ini (semua siswa sudah terdaftar di tingkat ini/tingkat lain, tidak ada siswa pada kelas tersebut, atau tidak sesuai batas usia golongan).
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" <?= ($barung_bisa_modal_tambah && !empty($available_siswa_barung) && $available_siswa_selectable_count > 0) ? '' : 'disabled' ?>>
                        <i class="fas fa-save"></i> Tambahkan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editModalLabel">Edit Peserta Didik</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="update_peserta_didik" value="1">
                <input type="hidden" name="id_peserta_didik_barung" id="edit_id_peserta_didik_barung" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Tingkat</label>
                        <select class="form-control" name="id_tingkat_barung" id="edit_id_tingkat_barung" required>
                            <option value="">Pilih Tingkat</option>
                            <?php foreach ($tingkat_list as $t): ?>
                                <option value="<?= (int)($t['id_tingkat_barung'] ?? 0) ?>">
                                    <?= htmlspecialchars($t['nama_tingkat'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nama Peserta Didik</label>
                        <input type="text" class="form-control" name="nama_peserta_didik" id="edit_nama_peserta_didik" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>NTA</label>
                        <input type="text" class="form-control" name="nta" id="edit_nta" autocomplete="off" placeholder="Opsional">
                    </div>
                    <div class="form-group">
                        <label>Tempat Lahir</label>
                        <input type="text" class="form-control" name="tempat_lahir" id="edit_tempat_lahir" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Tanggal Lahir</label>
                        <input type="date" class="form-control" name="tanggal_lahir" id="edit_tanggal_lahir">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit banyak: tabel dalam modal -->
<div class="modal fade" id="editBulkModal" tabindex="-1" role="dialog" aria-labelledby="editBulkModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <form method="POST" action="" id="bulkEditForm">
                <input type="hidden" name="update_peserta_bulk" value="1">
                <input type="hidden" name="id_tingkat_barung" id="bulk_edit_id_tingkat" value="<?= (int)$selected_tingkat_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="editBulkModalLabel">Edit beberapa peserta didik</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body py-2">
                    <p class="text-muted small mb-2">Ubah data pada tabel di bawah, lalu simpan. Baris tanpa nama akan dilewati; NTA boleh kosong.</p>
                    <div class="table-responsive" style="max-height: min(65vh, 520px); overflow-y: auto;">
                        <table class="table table-sm table-bordered mb-0 align-middle">
                            <thead class="thead-light">
                                <tr>
                                    <th style="width: 48px;">No</th>
                                    <th>Nama Peserta Didik</th>
                                    <th style="min-width: 120px;">NTA</th>
                                    <th>Tempat Lahir</th>
                                    <th style="min-width: 150px;">Tanggal Lahir</th>
                                </tr>
                            </thead>
                            <tbody id="bulkEditTableBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Simpan semua
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" role="dialog" aria-labelledby="importModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="importModalLabel">Import Peserta Didik (Excel/CSV)</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data" id="importForm">
                <input type="hidden" name="import_peserta_didik" value="1">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Tingkat</label>
                        <select class="form-control" name="id_tingkat_barung" required>
                            <option value="">Pilih Tingkat</option>
                            <?php foreach ($tingkat_list as $t): ?>
                                <option value="<?= (int)($t['id_tingkat_barung'] ?? 0) ?>" <?= ((int)($t['id_tingkat_barung'] ?? 0) === (int)$selected_tingkat_id) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($t['nama_tingkat'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">File akan di-import ke tingkat yang dipilih.</small>
                    </div>

                    <div class="form-group">
                        <label>File (.xlsx / .xls / .csv)</label>
                        <input type="file" class="form-control" name="import_file" accept=".xlsx,.xls,.csv" required>
                        <small class="text-muted">
                            Kolom yang didukung: <strong>Nama</strong>, <strong>NTA</strong>, <strong>Tempat Lahir</strong>, <strong>Tanggal Lahir</strong>.
                            Baris pertama boleh header (mis. "Nama Peserta Didik", "NTA").
                        </small>
                    </div>

                    <div class="d-none" id="importProgressWrap">
                        <div class="progress" style="height: 18px;">
                            <div id="importProgressBar" class="progress-bar progress-bar-striped progress-bar-animated"
                                 role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                        </div>
                        <small class="text-muted d-block mt-2" id="importStatusText">Mengunggah file...</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <a class="btn btn-outline-primary import-template-btn" target="_blank"
                       href="?tingkat=<?= (int)$selected_tingkat_id ?>&download_template=1&format=xlsx">
                        Download Template (XLSX)
                    </a>
                    <button type="button" class="btn btn-secondary" id="importCloseBtn" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="importSubmitBtn">Import Data</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
include '../templates/footer.php';
?>
