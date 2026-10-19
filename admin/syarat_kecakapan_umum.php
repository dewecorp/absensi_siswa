<?php
/**
 * Syarat Kecakapan Umum (SKU) — checklist per butir untuk anggota Pramuka per tingkat.
 * Lulus SKU → sku_kecakapan_lulus_at sehingga muncul di Surat Keterangan (bersama jalur kenaikan).
 */

require_once '../config/database.php';
require_once '../config/functions.php';

if (!function_exists('normalize_person_name_for_match')) {
    /** @param mixed $name */
    function normalize_person_name_for_match($name): string {
        $v = strtolower(trim((string)$name));
        $v = preg_replace('/[^a-z0-9]+/u', '', $v);
        return (string)$v;
    }
}
if (!function_exists('is_current_guru_pembina_pramuka')) {
    function is_current_guru_pembina_pramuka(PDO $pdo): bool
    {
        $idGuru = 0;
        $candidateNames = [];

        $sessionNames = [
            (string)($_SESSION['nama'] ?? ''),
            (string)($_SESSION['nama_guru'] ?? ''),
            (string)($_SESSION['username'] ?? ''),
        ];
        foreach ($sessionNames as $nm) {
            $nm = trim($nm);
            if ($nm !== '') {
                $candidateNames[] = $nm;
            }
        }

        if (isset($_SESSION['user_id'])) {
            $idGuru = (int)$_SESSION['user_id'];
            if (isset($_SESSION['login_source']) && $_SESSION['login_source'] === 'tb_pengguna') {
                try {
                    $st = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ? LIMIT 1");
                    $st->execute([$idGuru]);
                    $idGuru = (int)($st->fetchColumn() ?: 0);
                } catch (Exception $e) {
                    $idGuru = 0;
                }
            }
        }

        if ($idGuru > 0) {
            try {
                $stG = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ? LIMIT 1");
                $stG->execute([$idGuru]);
                $nmGuru = trim((string)($stG->fetchColumn() ?: ''));
                if ($nmGuru !== '') {
                    $candidateNames[] = $nmGuru;
                }
            } catch (Exception $e) {
            }
        }

        $normalizedCandidates = [];
        foreach ($candidateNames as $raw) {
            $n = normalize_person_name_for_match($raw);
            if ($n !== '') {
                $normalizedCandidates[] = $n;
            }
            $parts = preg_split('/\s+/', trim((string)$raw));
            if (is_array($parts) && count($parts) >= 2) {
                $firstTwo = normalize_person_name_for_match($parts[0] . ' ' . $parts[1]);
                if ($firstTwo !== '') {
                    $normalizedCandidates[] = $firstTwo;
                }
            }
        }
        $normalizedCandidates = array_values(array_unique($normalizedCandidates));

        try {
            if ($idGuru > 0) {
                $stId = $pdo->prepare("SELECT COUNT(*) FROM tb_pembina_pramuka WHERE id_guru = ?");
                $stId->execute([$idGuru]);
                if ((int)$stId->fetchColumn() > 0) {
                    return true;
                }
            }

            if ($normalizedCandidates === []) {
                return false;
            }

            $rows = $pdo->query("SELECT nama_pembina FROM tb_pembina_pramuka")->fetchAll(PDO::FETCH_COLUMN, 0);
            foreach ($rows as $nmPbRaw) {
                $nmPb = normalize_person_name_for_match((string)$nmPbRaw);
                if ($nmPb === '') {
                    continue;
                }
                foreach ($normalizedCandidates as $cand) {
                    if (
                        $cand === $nmPb ||
                        strpos($nmPb, $cand) !== false ||
                        strpos($cand, $nmPb) !== false
                    ) {
                        return true;
                    }
                }
            }
        } catch (Exception $e) {
            return false;
        }

        return false;
    }
}
if (!function_exists('resolve_current_id_guru_for_sku')) {
    function resolve_current_id_guru_for_sku(PDO $pdo): int
    {
        $idGuru = (int)($_SESSION['user_id'] ?? 0);
        if ($idGuru <= 0) {
            return 0;
        }
        if (isset($_SESSION['login_source']) && $_SESSION['login_source'] === 'tb_pengguna') {
            try {
                $st = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ? LIMIT 1");
                $st->execute([$idGuru]);
                $idGuru = (int)($st->fetchColumn() ?: 0);
            } catch (Exception $e) {
                return 0;
            }
        }
        return $idGuru;
    }
}

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$can_manage_sku = isAuthorized(['admin', 'tata_usaha']);
$can_view_sku = $can_manage_sku;
$is_pembina_pramuka_login = false;
$assigned_tingkat_ids = []; // Array of assigned levels
$sku_assignment_missing = false;
if (!$can_view_sku && (isAuthorized(['guru']) || isAuthorized(['wali']))) {
    $is_pembina_pramuka_login = is_current_guru_pembina_pramuka($pdo);
    $can_view_sku = $is_pembina_pramuka_login;
    if ($can_view_sku) {
        $idGuruLogin = resolve_current_id_guru_for_sku($pdo);
        if ($idGuruLogin > 0) {
            try {
                // Fetch all assigned levels from junction table
                $stAssign = $pdo->prepare("
                    SELECT pt.id_tingkat_barung 
                    FROM tb_pembina_tingkat pt
                    JOIN tb_pembina_pramuka p ON p.id_pembina_pramuka = pt.id_pembina_pramuka
                    WHERE p.id_guru = ?
                ");
                $stAssign->execute([$idGuruLogin]);
                $assigned_tingkat_ids = $stAssign->fetchAll(PDO::FETCH_COLUMN);
                $assigned_tingkat_ids = array_map('intval', $assigned_tingkat_ids);
            } catch (Exception $e) {
                $assigned_tingkat_ids = [];
            }
        }
        if (empty($assigned_tingkat_ids)) {
            $sku_assignment_missing = true;
        }
    }
}
if (!$can_view_sku) {
    redirect('../login.php');
}
$can_interact_sku = $can_manage_sku || (!$sku_assignment_missing && !empty($assigned_tingkat_ids));

$page_title = 'Syarat Kecakapan Umum';

$css_libs = [
    'https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css',
];
$js_libs = [
    'https://cdn.jsdelivr.net/npm/sweetalert2@11',
    'https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js',
];

$ensureSkuSchema = static function () use ($pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tb_sku_kecakapan_butir (
            id_butir INT AUTO_INCREMENT PRIMARY KEY,
            id_tingkat_barung INT NOT NULL,
            teks_butir VARCHAR(500) NOT NULL,
            urutan INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sku_butir_tingkat (id_tingkat_barung)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tb_sku_kecakapan_nilai (
            id_peserta_didik_barung INT NOT NULL,
            id_butir INT NOT NULL,
            PRIMARY KEY (id_peserta_didik_barung, id_butir),
            INDEX idx_sku_nilai_butir (id_butir)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tb_pembina_tingkat (
            id_pembina_pramuka INT NOT NULL,
            id_tingkat_barung INT NOT NULL,
            PRIMARY KEY (id_pembina_pramuka, id_tingkat_barung)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $colNilaiDate = $pdo->query("SHOW COLUMNS FROM tb_sku_kecakapan_nilai LIKE 'tanggal_ujian'");
    if (!$colNilaiDate || !$colNilaiDate->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE tb_sku_kecakapan_nilai ADD COLUMN tanggal_ujian DATE NULL AFTER id_butir");
    }

    $colStmt = $pdo->query("SHOW COLUMNS FROM tb_peserta_didik_barung LIKE 'sku_kecakapan_lulus_at'");
    if (!$colStmt || !$colStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec('ALTER TABLE tb_peserta_didik_barung ADD COLUMN sku_kecakapan_lulus_at DATETIME NULL');
    }
    $colPromFrom = $pdo->query("SHOW COLUMNS FROM tb_peserta_didik_barung LIKE 'promoted_from_tingkat_id'");
    if (!$colPromFrom || !$colPromFrom->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec('ALTER TABLE tb_peserta_didik_barung ADD COLUMN promoted_from_tingkat_id INT NULL');
    }
    $colPromAt = $pdo->query("SHOW COLUMNS FROM tb_peserta_didik_barung LIKE 'promoted_at'");
    if (!$colPromAt || !$colPromAt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec('ALTER TABLE tb_peserta_didik_barung ADD COLUMN promoted_at DATETIME NULL');
    }
};

try {
    $ensureSkuSchema();
} catch (Exception $e) {
    // best effort — halaman bisa menampilkan error kosong query
}

// Unduh template import butir SKU (baris 1 = header per kolom) — format Excel (.xlsx)
if (isset($_GET['download_template_sku'])) {
    $filename = 'template_butir_sku.xlsx';
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        header('HTTP/1.1 500 Internal Server Error');
        exit('Composer autoload tidak ditemukan. Jalankan: composer install');
    }
    require_once $autoload;
    try {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Butir SKU');
        $contoh = ['Hafal rukun iman', 'Tertib ibadah harian', 'Mampu membaca doa'];
        $col = 1;
        foreach ($contoh as $label) {
            $sheet->setCellValueByColumnAndRow($col, 1, $label);
            $col++;
        }
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
    } catch (Throwable $e) {
        if (!headers_sent()) {
            header('HTTP/1.1 500 Internal Server Error');
        }
        exit('Gagal membuat template Excel.');
    }
    exit;
}

/** Nama di DB kadang berisi literal entitas HTML (mis. &#039;); dipakai sebelum htmlspecialchars / ekspor. */
function sku_plain_person_name(string $name): string
{
    $t = trim($name);
    if ($t === '') {
        return '';
    }
    // Fallback decode berulang untuk data ganda
    while (strpos($t, '&') !== false && ($tmp = htmlspecialchars_decode($t, ENT_QUOTES)) !== $t) {
        $t = $tmp;
    }
    return $t;
}

function sku_html_person_name(string $name): string
{
    return htmlspecialchars(sku_plain_person_name($name), ENT_QUOTES, 'UTF-8');
}

/**
 * Hitung label status satu peserta untuk satu tingkat.
 *
 * @return array{label: string, ok: bool, percentage: float, done: int, total: int}
 */
function sku_compute_status_cell(PDO $pdo, int $id_peserta, int $id_tingkat): array
{
    $stTot = $pdo->prepare('SELECT COUNT(*) FROM tb_sku_kecakapan_butir WHERE id_tingkat_barung = ?');
    $stTot->execute([$id_tingkat]);
    $total = (int)$stTot->fetchColumn();
    if ($total <= 0) {
        return ['label' => '—', 'ok' => false, 'percentage' => 0, 'done' => 0, 'total' => 0];
    }
    $stDone = $pdo->prepare('
        SELECT COUNT(*)
        FROM tb_sku_kecakapan_nilai n
        INNER JOIN tb_sku_kecakapan_butir b ON b.id_butir = n.id_butir AND b.id_tingkat_barung = ?
        WHERE n.id_peserta_didik_barung = ?
    ');
    $stDone->execute([$id_tingkat, $id_peserta]);
    $done = (int)$stDone->fetchColumn();

    $percentage = ($total > 0) ? round(($done / $total) * 100, 1) : 0;

    return [
        'label' => ($done >= $total) ? 'Lulus' : 'Tidak Lulus',
        'ok' => ($done >= $total),
        'done' => $done,
        'total' => $total,
        'percentage' => $percentage,
    ];
}

/** Set flag SKU lulus satu peserta. */
function sku_next_tingkat_id(PDO $pdo, int $id_tingkat): int
{
    static $ordered = null;
    if ($ordered === null) {
        $ordered = $pdo->query("
            SELECT id_tingkat_barung
            FROM tb_tingkat_barung
            ORDER BY
                CASE
                    WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('pramula', 'pra-mula') OR LOWER(nama_tingkat) = 'pra mula' THEN 0
                    WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('mula') THEN 1
                    WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('bantu') THEN 2
                    WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('tata') THEN 3
                    WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('garuda') THEN 4
                    ELSE 99
                END,
                nama_tingkat ASC
        ")->fetchAll(PDO::FETCH_COLUMN, 0);
    }
    $ids = array_values(array_map('intval', is_array($ordered) ? $ordered : []));
    $pos = array_search($id_tingkat, $ids, true);
    if ($pos === false) {
        return 0;
    }
    return (int)($ids[$pos + 1] ?? 0);
}

function sku_recompute_single(PDO $pdo, int $id_peserta, int $id_tingkat): void
{
    $stTot = $pdo->prepare('SELECT COUNT(*) FROM tb_sku_kecakapan_butir WHERE id_tingkat_barung = ?');
    $stTot->execute([$id_tingkat]);
    $total = (int)$stTot->fetchColumn();
    if ($total <= 0) {
        $upd = $pdo->prepare('UPDATE tb_peserta_didik_barung SET sku_kecakapan_lulus_at = NULL WHERE id_peserta_didik_barung = ? LIMIT 1');
        $upd->execute([$id_peserta]);

        return;
    }
    $stDone = $pdo->prepare('
        SELECT COUNT(*)
        FROM tb_sku_kecakapan_nilai n
        INNER JOIN tb_sku_kecakapan_butir b ON b.id_butir = n.id_butir AND b.id_tingkat_barung = ?
        WHERE n.id_peserta_didik_barung = ?
    ');
    $stDone->execute([$id_tingkat, $id_peserta]);
    $done = (int)$stDone->fetchColumn();
    if ($done === $total && $done > 0) {
        $pdo->prepare('UPDATE tb_peserta_didik_barung SET sku_kecakapan_lulus_at = NOW() WHERE id_peserta_didik_barung = ? LIMIT 1')
            ->execute([$id_peserta]);
        $next_tingkat_id = sku_next_tingkat_id($pdo, $id_tingkat);
        if ($next_tingkat_id > 0) {
            $pdo->prepare("
                UPDATE tb_peserta_didik_barung
                SET id_tingkat_barung = ?,
                    promoted_from_tingkat_id = ?,
                    promoted_at = NOW(),
                    sku_kecakapan_lulus_at = NULL
                WHERE id_peserta_didik_barung = ?
                  AND id_tingkat_barung = ?
                  AND IFNULL(status, 'aktif') = 'aktif'
                LIMIT 1
            ")->execute([$next_tingkat_id, $id_tingkat, $id_peserta, $id_tingkat]);
        }
    } else {
        $pdo->prepare('UPDATE tb_peserta_didik_barung SET sku_kecakapan_lulus_at = NULL WHERE id_peserta_didik_barung = ? LIMIT 1')
            ->execute([$id_peserta]);
    }
}

function sku_recompute_tingkat(PDO $pdo, int $id_tingkat): void
{
    $st = $pdo->prepare('
        SELECT id_peserta_didik_barung FROM tb_peserta_didik_barung
        WHERE id_tingkat_barung = ? AND IFNULL(status, \'aktif\') = \'aktif\'
    ');
    $st->execute([$id_tingkat]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN, 0) as $pid) {
        sku_recompute_single($pdo, (int)$pid, $id_tingkat);
    }
}

/** Urutkan nomor butir menjadi 1..n sesuai urutan sekarang (setelah hapus kolom dll.). */
function sku_renumber_butir_urutan(PDO $pdo, int $id_tingkat): void
{
    if ($id_tingkat <= 0) {
        return;
    }
    $st = $pdo->prepare('SELECT id_butir FROM tb_sku_kecakapan_butir WHERE id_tingkat_barung = ? ORDER BY urutan ASC, id_butir ASC');
    $st->execute([$id_tingkat]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN, 0);
    $upd = $pdo->prepare('UPDATE tb_sku_kecakapan_butir SET urutan = ? WHERE id_butir = ? AND id_tingkat_barung = ?');
    $n = 1;
    foreach ($ids as $idButir) {
        $upd->execute([$n++, (int)$idButir, $id_tingkat]);
    }
}

/** ----- AJAX ----- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sku_ajax']) && $_POST['sku_ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $action = (string)($_POST['action'] ?? '');
    $id_tingkat = (int)($_POST['id_tingkat_barung'] ?? 0);

    try {
        $ensureSkuSchema();
        if (!$can_manage_sku && !$can_interact_sku) {
            echo json_encode(['ok' => false, 'msg' => 'Tingkat ampuan pembina belum diatur.']);
            exit;
        }
        if (!$can_manage_sku && !empty($assigned_tingkat_ids) && !in_array($id_tingkat, $assigned_tingkat_ids)) {
            echo json_encode(['ok' => false, 'msg' => 'Anda hanya boleh mengisi SKU pada tingkat yang diampu.']);
            exit;
        }
        if ($id_tingkat <= 0) {
            echo json_encode(['ok' => false, 'msg' => 'Tingkat tidak valid.']);
            exit;
        }

        if ($action === 'toggle') {
            $id_p = (int)($_POST['id_peserta_didik_barung'] ?? 0);
            $id_b = (int)($_POST['id_butir'] ?? 0);
            $on = isset($_POST['checked']) && (trim((string)$_POST['checked']) === '1'
                || strtolower((string)$_POST['checked']) === 'true');
            if ($id_p <= 0 || $id_b <= 0) {
                echo json_encode(['ok' => false, 'msg' => 'Data tidak lengkap.']);
                exit;
            }
            $chk = $pdo->prepare('SELECT id_tingkat_barung FROM tb_sku_kecakapan_butir WHERE id_butir = ? LIMIT 1');
            $chk->execute([$id_b]);
            if ((int)$chk->fetchColumn() !== $id_tingkat) {
                echo json_encode(['ok' => false, 'msg' => 'Butir tidak sesuai tingkat.']);
                exit;
            }
            $ps = $pdo->prepare('SELECT id_tingkat_barung FROM tb_peserta_didik_barung WHERE id_peserta_didik_barung = ? LIMIT 1');
            $ps->execute([$id_p]);
            if ((int)$ps->fetchColumn() !== $id_tingkat) {
                echo json_encode(['ok' => false, 'msg' => 'Peserta tidak sesuai tingkat.']);
                exit;
            }
            if ($on) {
                $ins = $pdo->prepare('INSERT INTO tb_sku_kecakapan_nilai (id_peserta_didik_barung, id_butir, tanggal_ujian) VALUES (?, ?, ?)');
                try {
                    $ins->execute([$id_p, $id_b, date('Y-m-d')]);
                } catch (Exception $ignored) {
                    // duplicate
                }
                $pdo->prepare('UPDATE tb_sku_kecakapan_nilai SET tanggal_ujian = COALESCE(tanggal_ujian, ?) WHERE id_peserta_didik_barung = ? AND id_butir = ?')
                    ->execute([date('Y-m-d'), $id_p, $id_b]);
            } else {
                $pdo->prepare('DELETE FROM tb_sku_kecakapan_nilai WHERE id_peserta_didik_barung = ? AND id_butir = ?')
                    ->execute([$id_p, $id_b]);
            }
            sku_recompute_single($pdo, $id_p, $id_tingkat);
            $stPes = $pdo->prepare('SELECT id_tingkat_barung FROM tb_peserta_didik_barung WHERE id_peserta_didik_barung = ? LIMIT 1');
            $stPes->execute([$id_p]);
            $new_tingkat_id = (int)($stPes->fetchColumn() ?: 0);
            $promoted = $new_tingkat_id > 0 && $new_tingkat_id !== $id_tingkat;
            $status = sku_compute_status_cell($pdo, $id_p, $id_tingkat);
            $stDt = $pdo->prepare('SELECT tanggal_ujian FROM tb_sku_kecakapan_nilai WHERE id_peserta_didik_barung = ? AND id_butir = ? LIMIT 1');
            $stDt->execute([$id_p, $id_b]);
            $tgl = (string)($stDt->fetchColumn() ?? '');
            echo json_encode([
                'ok' => true,
                'status_label' => $status['label'],
                'status_ok' => $status['ok'],
                'percentage' => $status['percentage'],
                'done' => $status['done'],
                'total' => $status['total'],
                'tanggal_ujian' => $tgl,
                'promoted' => $promoted,
                'new_tingkat_id' => $new_tingkat_id,
            ]);
            exit;
        }

        if ($action === 'set_tanggal') {
            $id_p = (int)($_POST['id_peserta_didik_barung'] ?? 0);
            $id_b = (int)($_POST['id_butir'] ?? 0);
            $tgl = trim((string)($_POST['tanggal_ujian'] ?? ''));
            if ($id_p <= 0 || $id_b <= 0) {
                echo json_encode(['ok' => false, 'msg' => 'Data tidak lengkap.']);
                exit;
            }
            if ($tgl !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) {
                echo json_encode(['ok' => false, 'msg' => 'Format tanggal tidak valid.']);
                exit;
            }
            $chk = $pdo->prepare('SELECT id_tingkat_barung FROM tb_sku_kecakapan_butir WHERE id_butir = ? LIMIT 1');
            $chk->execute([$id_b]);
            if ((int)$chk->fetchColumn() !== $id_tingkat) {
                echo json_encode(['ok' => false, 'msg' => 'Butir tidak sesuai tingkat.']);
                exit;
            }
            $ps = $pdo->prepare('SELECT id_tingkat_barung FROM tb_peserta_didik_barung WHERE id_peserta_didik_barung = ? LIMIT 1');
            $ps->execute([$id_p]);
            if ((int)$ps->fetchColumn() !== $id_tingkat) {
                echo json_encode(['ok' => false, 'msg' => 'Peserta tidak sesuai tingkat.']);
                exit;
            }
            $up = $pdo->prepare('UPDATE tb_sku_kecakapan_nilai SET tanggal_ujian = ? WHERE id_peserta_didik_barung = ? AND id_butir = ?');
            $up->execute([$tgl !== '' ? $tgl : null, $id_p, $id_b]);
            if ($tgl !== '') {
                $pdo->prepare('INSERT INTO tb_sku_kecakapan_nilai (id_peserta_didik_barung, id_butir, tanggal_ujian) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE tanggal_ujian = VALUES(tanggal_ujian)')
                    ->execute([$id_p, $id_b, $tgl]);
            }
            $existsSt = $pdo->prepare('SELECT COUNT(*) FROM tb_sku_kecakapan_nilai WHERE id_peserta_didik_barung = ? AND id_butir = ?');
            $existsSt->execute([$id_p, $id_b]);
            $checkedNow = ((int)$existsSt->fetchColumn()) > 0;
            sku_recompute_single($pdo, $id_p, $id_tingkat);
            $stPes = $pdo->prepare('SELECT id_tingkat_barung FROM tb_peserta_didik_barung WHERE id_peserta_didik_barung = ? LIMIT 1');
            $stPes->execute([$id_p]);
            $new_tingkat_id = (int)($stPes->fetchColumn() ?: 0);
            $promoted = $new_tingkat_id > 0 && $new_tingkat_id !== $id_tingkat;
            $status = sku_compute_status_cell($pdo, $id_p, $id_tingkat);
            echo json_encode([
                'ok' => true,
                'status_label' => $status['label'],
                'status_ok' => $status['ok'],
                'percentage' => $status['percentage'],
                'done' => $status['done'],
                'total' => $status['total'],
                'tanggal_ujian' => $tgl,
                'checked' => $checkedNow,
                'promoted' => $promoted,
                'new_tingkat_id' => $new_tingkat_id,
            ]);
            exit;
        }

        if ($action === 'add_butir') {
            if (!$can_manage_sku) {
                echo json_encode(['ok' => false, 'msg' => 'Akses ditolak.']);
                exit;
            }
            $teks = trim((string)($_POST['teks_butir'] ?? ''));
            if ($teks === '') {
                echo json_encode(['ok' => false, 'msg' => 'Teks butir tidak boleh kosong.']);
                exit;
            }
            if (mb_strlen($teks) > 500) {
                echo json_encode(['ok' => false, 'msg' => 'Teks butir maksimal 500 karakter.']);
                exit;
            }
            $mx = $pdo->prepare('SELECT COALESCE(MAX(urutan), 0) FROM tb_sku_kecakapan_butir WHERE id_tingkat_barung = ?');
            $mx->execute([$id_tingkat]);
            $next = ((int)$mx->fetchColumn()) + 1;
            $pdo->prepare('INSERT INTO tb_sku_kecakapan_butir (id_tingkat_barung, teks_butir, urutan) VALUES (?, ?, ?)')
                ->execute([$id_tingkat, $teks, $next]);
            sku_recompute_tingkat($pdo, $id_tingkat);
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($action === 'edit_butir') {
            if (!$can_manage_sku) {
                echo json_encode(['ok' => false, 'msg' => 'Akses ditolak.']);
                exit;
            }
            $id_b = (int)($_POST['id_butir'] ?? 0);
            $teks = trim((string)($_POST['teks_butir'] ?? ''));
            if ($id_b <= 0 || $teks === '') {
                echo json_encode(['ok' => false, 'msg' => 'Data tidak lengkap.']);
                exit;
            }
            if (mb_strlen($teks) > 500) {
                echo json_encode(['ok' => false, 'msg' => 'Teks butir maksimal 500 karakter.']);
                exit;
            }
            $own = $pdo->prepare('SELECT id_butir FROM tb_sku_kecakapan_butir WHERE id_butir = ? AND id_tingkat_barung = ? LIMIT 1');
            $own->execute([$id_b, $id_tingkat]);
            if (!$own->fetch(PDO::FETCH_ASSOC)) {
                echo json_encode(['ok' => false, 'msg' => 'Butir tidak ditemukan di tingkat ini.']);
                exit;
            }
            $pdo->prepare('UPDATE tb_sku_kecakapan_butir SET teks_butir = ? WHERE id_butir = ? AND id_tingkat_barung = ?')
                ->execute([$teks, $id_b, $id_tingkat]);
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($action === 'delete_butir') {
            if (!$can_manage_sku) {
                echo json_encode(['ok' => false, 'msg' => 'Akses ditolak.']);
                exit;
            }
            $id_b = (int)($_POST['id_butir'] ?? 0);
            if ($id_b <= 0) {
                echo json_encode(['ok' => false, 'msg' => 'ID tidak valid.']);
                exit;
            }
            $pdo->prepare('DELETE FROM tb_sku_kecakapan_nilai WHERE id_butir = ?')->execute([$id_b]);
            $pdo->prepare('DELETE FROM tb_sku_kecakapan_butir WHERE id_butir = ? AND id_tingkat_barung = ?')
                ->execute([$id_b, $id_tingkat]);
            sku_renumber_butir_urutan($pdo, $id_tingkat);
            sku_recompute_tingkat($pdo, $id_tingkat);
            echo json_encode(['ok' => true]);
            exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'Aksi tidak dikenal.']);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_sku_butir'])) {
    if (!$can_manage_sku) {
        header('Location: syarat_kecakapan_umum.php?tingkat=' . (int)($_POST['id_tingkat_barung'] ?? 0));
        exit;
    }
    $is_ajax_import = isset($_POST['ajax']) && (string)$_POST['ajax'] === '1';
    $id_tingkat = (int)($_POST['id_tingkat_barung'] ?? 0);
    $import_ok = false;
    $import_msg = 'Tidak ada file yang diproses.';
    if ($id_tingkat > 0 && isset($_FILES['file_import']) && is_array($_FILES['file_import']) && (int)$_FILES['file_import']['error'] === UPLOAD_ERR_OK) {
        try {
            $ensureSkuSchema();
            $tmp = (string)$_FILES['file_import']['tmp_name'];
            $name = (string)$_FILES['file_import']['name'];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $rows = [];
            $isSkuHeader = static function (string $text): bool {
                $t = strtolower(trim($text));
                if ($t === '') {
                    return false;
                }
                $t = preg_replace('/\s+/u', ' ', $t);
                if (in_array($t, ['no', 'nomor', 'nama', 'nama peserta didik', 'status', 'lulus/tidak lulus', 'lulus / tidak lulus'], true)) {
                    return false;
                }
                if (preg_match('/^\d+$/', $t)) {
                    return false;
                }
                return true;
            };

            if (in_array($ext, ['csv', 'txt'], true)) {
                $fh = fopen($tmp, 'rb');
                if ($fh) {
                    $header = fgetcsv($fh, 0, ',', '"');
                    if (is_array($header)) {
                        foreach ($header as $cell) {
                            $t = trim((string)$cell);
                            if ($isSkuHeader($t)) {
                                $rows[] = $t;
                            }
                        }
                    }
                    fclose($fh);
                }
            } elseif (in_array($ext, ['xlsx', 'xls'], true)) {
                $autoload = __DIR__ . '/../vendor/autoload.php';
                if (file_exists($autoload)) {
                    require_once $autoload;
                    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmp);
                    $reader->setReadDataOnly(true);
                    $sheet = $reader->load($tmp)->getSheet(0);
                    $highestCol = (string)$sheet->getHighestColumn();
                    $maxIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);
                    for ($c = 1; $c <= $maxIdx; $c++) {
                        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . '1';
                        $v = trim((string)$sheet->getCell($cell)->getValue());
                        if ($isSkuHeader($v)) {
                            $rows[] = $v;
                        }
                    }
                }
            }
            $rows = array_values(array_unique(array_filter($rows)));
            $mx = $pdo->prepare('SELECT COALESCE(MAX(urutan), 0) FROM tb_sku_kecakapan_butir WHERE id_tingkat_barung = ?');
            $mx->execute([$id_tingkat]);
            $base = (int)$mx->fetchColumn();
            $ins = $pdo->prepare('INSERT INTO tb_sku_kecakapan_butir (id_tingkat_barung, teks_butir, urutan) VALUES (?, ?, ?)');
            foreach ($rows as $i => $txt) {
                $ins->execute([$id_tingkat, $txt, $base + $i + 1]);
            }
            sku_recompute_tingkat($pdo, $id_tingkat);
            $import_ok = true;
            $import_msg = 'Import: ' . count($rows) . ' butir ditambahkan.';
            $_SESSION['sku_flash_ok'] = $import_msg;
        } catch (Exception $e) {
            $import_ok = false;
            $import_msg = $e->getMessage();
            $_SESSION['sku_flash_err'] = $import_msg;
        }
    } else {
        $import_ok = false;
        $import_msg = 'File import tidak valid.';
    }

    if ($is_ajax_import) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => $import_ok,
            'msg' => $import_msg,
            'tingkat' => $id_tingkat,
        ]);
        exit;
    }

    header('Location: syarat_kecakapan_umum.php?tingkat=' . $id_tingkat);
    exit;
}

$tingkat_list = [];
try {
    $tingkat_list = $pdo->query('
        SELECT id_tingkat_barung, nama_tingkat, golongan
        FROM tb_tingkat_barung
        ORDER BY
            CASE
                WHEN LOWER(REPLACE(nama_tingkat, \' \', \'\')) IN (\'pramula\', \'pra-mula\')
                  OR LOWER(nama_tingkat) = \'pra mula\' THEN 0
                WHEN LOWER(REPLACE(nama_tingkat, \' \', \'\')) IN (\'mula\') THEN 1
                WHEN LOWER(REPLACE(nama_tingkat, \' \', \'\')) IN (\'bantu\') THEN 2
                WHEN LOWER(REPLACE(nama_tingkat, \' \', \'\')) IN (\'tata\') THEN 3
                WHEN LOWER(REPLACE(nama_tingkat, \' \', \'\')) IN (\'garuda\') THEN 4
                ELSE 99
            END,
            nama_tingkat ASC
    ')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

$tingkat_counts = [];
try {
    $stCount = $pdo->query('
        SELECT id_tingkat_barung, COUNT(*) as jml
        FROM tb_peserta_didik_barung
        WHERE IFNULL(status, "aktif") = "aktif"
        GROUP BY id_tingkat_barung
    ');
    while ($rc = $stCount->fetch(PDO::FETCH_ASSOC)) {
        $tingkat_counts[(int)$rc['id_tingkat_barung']] = (int)$rc['jml'];
    }
} catch (Exception $e) {
}

$selected_tingkat_id = (int)($_GET['tingkat'] ?? 0);
if (!$can_manage_sku && !empty($assigned_tingkat_ids)) {
    if ($selected_tingkat_id <= 0 || !in_array($selected_tingkat_id, $assigned_tingkat_ids)) {
        $selected_tingkat_id = $assigned_tingkat_ids[0];
    }
}
if ($selected_tingkat_id <= 0 && !empty($tingkat_list)) {
    $selected_tingkat_id = (int)($tingkat_list[0]['id_tingkat_barung'] ?? 0);
}

$selected_tingkat_name = '';
foreach ($tingkat_list as $t) {
    if ((int)($t['id_tingkat_barung'] ?? 0) === $selected_tingkat_id) {
        $selected_tingkat_name = (string)($t['nama_tingkat'] ?? '');
        break;
    }
}
$assigned_tingkat_names = [];
if (!empty($assigned_tingkat_ids)) {
    foreach ($tingkat_list as $t) {
        if (in_array((int)($t['id_tingkat_barung'] ?? 0), $assigned_tingkat_ids)) {
            $assigned_tingkat_names[] = (string)($t['nama_tingkat'] ?? '');
        }
    }
}

$sku_butir_rows = [];
$peserta_rows = [];
$checks_map = [];
$tanggal_map = [];
if ($selected_tingkat_id > 0) {
    try {
        sku_renumber_butir_urutan($pdo, $selected_tingkat_id);
        $b = $pdo->prepare('
            SELECT id_butir, teks_butir, urutan FROM tb_sku_kecakapan_butir
            WHERE id_tingkat_barung = ? ORDER BY urutan ASC, id_butir ASC
        ');
        $b->execute([$selected_tingkat_id]);
        $sku_butir_rows = $b->fetchAll(PDO::FETCH_ASSOC);

        $stP = $pdo->prepare('
            SELECT p.id_peserta_didik_barung, p.nama_peserta_didik, p.nta,
                   s.id_siswa, s.nisn, s.foto, k.nama_kelas
            FROM tb_peserta_didik_barung p
            LEFT JOIN tb_siswa s ON (
                s.id_siswa = p.id_siswa
                OR (
                    p.id_siswa IS NULL
                    AND TRIM(IFNULL(p.nta, \'\')) <> \'\'
                    AND CONVERT(TRIM(IFNULL(s.nisn, \'\')) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                        = CONVERT(TRIM(IFNULL(p.nta, \'\')) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                )
            )
            LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas
            WHERE p.id_tingkat_barung = ? AND IFNULL(p.status, \'aktif\') = \'aktif\'
              AND (s.id_kelas IS NOT NULL OR p.id_siswa IS NULL)
            ORDER BY p.nama_peserta_didik ASC
        ');
        $stP->execute([$selected_tingkat_id]);
        $peserta_rows = $stP->fetchAll(PDO::FETCH_ASSOC);

        $ids_p = [];
        foreach ($peserta_rows as $pr) {
            $ids_p[] = (int)$pr['id_peserta_didik_barung'];
        }
        if ($ids_p !== []) {
            $ph = implode(',', array_fill(0, count($ids_p), '?'));
            $stN = $pdo->prepare(
                'SELECT id_peserta_didik_barung, id_butir, tanggal_ujian FROM tb_sku_kecakapan_nilai WHERE id_peserta_didik_barung IN (' . $ph . ')'
            );
            $stN->execute($ids_p);
            while ($rw = $stN->fetch(PDO::FETCH_ASSOC)) {
                $pid = (int)$rw['id_peserta_didik_barung'];
                $bid = (int)$rw['id_butir'];
                $checks_map[$pid][$bid] = true;
                $tanggal_map[$pid][$bid] = (string)($rw['tanggal_ujian'] ?? '');
            }
        }
    } catch (Exception $e) {
        $sku_butir_rows = [];
        $peserta_rows = [];
    }
}

$school_profile = getSchoolProfile($pdo);
$sku_print_settings = [
    'ketua_gudep' => '',
    'nta_ketua_gudep' => '',
    'nomor_gudep' => '',
    'gugus_depan' => '03.016',
    'tempat_surat' => '',
    'tanggal_surat' => date('Y-m-d'),
    'logo_pramuka' => '',
    'logo_wosm' => '',
];
try {
    $stSkuPrint = $pdo->query("SELECT * FROM tb_pengaturan_cetak_barung LIMIT 1");
    $rwSkuPrint = $stSkuPrint ? $stSkuPrint->fetch(PDO::FETCH_ASSOC) : null;
    if (is_array($rwSkuPrint)) {
        $sku_print_settings['ketua_gudep'] = (string)($rwSkuPrint['ketua_gudep'] ?? '');
        $sku_print_settings['nta_ketua_gudep'] = (string)($rwSkuPrint['nta_ketua_gudep'] ?? '');
        $sku_print_settings['nomor_gudep'] = (string)($rwSkuPrint['nomor_gudep'] ?? '');
        $sku_print_settings['gugus_depan'] = (string)($rwSkuPrint['gugus_depan'] ?? '03.016');
        $sku_print_settings['tempat_surat'] = (string)($rwSkuPrint['tempat_surat'] ?? '');
        $sku_print_settings['tanggal_surat'] = (string)($rwSkuPrint['tanggal_surat'] ?? date('Y-m-d'));
        $sku_print_settings['logo_pramuka'] = (string)($rwSkuPrint['logo_pramuka'] ?? '');
        $sku_print_settings['logo_wosm'] = (string)($rwSkuPrint['logo_wosm'] ?? '');
    }
} catch (Exception $e) {
    // best effort
}

/** Ekspor data rekap SKU (Excel / PDF); membutuhkan data tingkat dipilih yang sudah di-load di atas */
if (isset($_GET['export'])) {
    $export_fmt = strtolower(trim((string)($_GET['export'] ?? '')));
    if (($export_fmt === 'xlsx' || $export_fmt === 'pdf') && $selected_tingkat_id > 0) {
        $school_name = htmlspecialchars((string)($school_profile['nama_sekolah'] ?? ''), ENT_QUOTES, 'UTF-8');
        $tingkat_esc = htmlspecialchars($selected_tingkat_name, ENT_QUOTES, 'UTF-8');
        $tingkat_slug = preg_replace('/[^a-z0-9]+/i', '_', trim((string)$selected_tingkat_name));
        $tingkat_slug = trim((string)$tingkat_slug, '_');
        if ($tingkat_slug === '') {
            $tingkat_slug = 'tingkat';
        }
        $tahun_ajaran_raw = (string)($school_profile['tahun_ajaran'] ?? date('Y'));
        $tahun_ajaran_slug = preg_replace('/[^a-z0-9]+/i', '_', strtolower($tahun_ajaran_raw));
        $tahun_ajaran_slug = trim((string)$tahun_ajaran_slug, '_');
        if ($tahun_ajaran_slug === '') {
            $tahun_ajaran_slug = date('Y');
        }
        $sku_export_base = 'Data_SKU_' . $tingkat_slug . '_' . $tahun_ajaran_slug;
        while (ob_get_level()) {
            ob_end_clean();
        }
        if ($export_fmt === 'xlsx') {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (!file_exists($autoload)) {
                header('HTTP/1.1 500 Internal Server Error');
                exit('Jalankan: composer install');
            }
            require_once $autoload;
            try {
                $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('SKU');
                $sheet->setCellValue('A1', (string)($school_profile['nama_sekolah'] ?? 'Syarat Kecakapan Umum'));
                $sheet->setCellValue('A2', 'SKU Pramuka — ' . ($selected_tingkat_name ?: 'Tingkat'));
                $sheet->setCellValue('A3', 'Dicetak: ' . date('d/m/Y H:i'));
                $hdrRow = 5;
                $sheet->setCellValueByColumnAndRow(1, $hdrRow, 'No.');
                $sheet->setCellValueByColumnAndRow(2, $hdrRow, 'Nama Peserta Didik');
                $c = 3;
                foreach ($sku_butir_rows as $bb) {
                    $label = '#' . (int)$bb['urutan'] . ' ' . mb_substr((string)$bb['teks_butir'], 0, 80);
                    $sheet->setCellValueByColumnAndRow($c, $hdrRow, $label);
                    $c++;
                }
                $nb = count($sku_butir_rows);
                $statusCol = 3 + $nb;
                $pctCol = 4 + $nb;
                $sheet->setCellValueByColumnAndRow($statusCol, $hdrRow, 'Status');
                $sheet->setCellValueByColumnAndRow($pctCol, $hdrRow, 'Persentase Ujian');
                $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($pctCol);
                $sheet->getStyle('A' . $hdrRow . ':' . $lastColLetter . $hdrRow)->getFont()->setBold(true);
                $row = $hdrRow + 1;
                $nom = 1;
                foreach ($peserta_rows as $p) {
                    $pid = (int)$p['id_peserta_didik_barung'];
                    $sheet->setCellValueByColumnAndRow(1, $row, $nom++);
                    $sheet->setCellValueByColumnAndRow(2, $row, sku_plain_person_name((string)$p['nama_peserta_didik']));
                    $cc = 3;
                    foreach ($sku_butir_rows as $bb) {
                        $bid = (int)$bb['id_butir'];
                        $on = !empty($checks_map[$pid][$bid]);
                        $tgl = (string)($tanggal_map[$pid][$bid] ?? '');
                        if ($on) {
                            $val = ($tgl !== '') ? ('Ya (' . date('d-m-Y', strtotime($tgl)) . ')') : 'Ya';
                        } else {
                            $val = ($tgl !== '') ? ('Tanggal: ' . date('d-m-Y', strtotime($tgl))) : 'Tidak';
                        }
                        $sheet->setCellValueByColumnAndRow($cc, $row, $val);
                        $cc++;
                    }
                    $inf = sku_compute_status_cell($pdo, $pid, $selected_tingkat_id);
                    $sheet->setCellValueByColumnAndRow($statusCol, $row, $inf['label']);
                    $sheet->setCellValueByColumnAndRow($pctCol, $row, $inf['percentage'] . '% (' . $inf['done'] . '/' . $inf['total'] . ')');
                    $row++;
                }
                $spreadsheet->getActiveSheet()->getColumnDimension('A')->setWidth(6);
                $spreadsheet->getActiveSheet()->getColumnDimension('B')->setWidth(38);
                for ($ci = 3; $ci <= $pctCol; ++$ci) {
                    $spreadsheet->getActiveSheet()->getColumnDimension(
                        \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci)
                    )->setWidth($ci < $statusCol ? 16 : 18);
                }
                $fname = $sku_export_base . '.xlsx';
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $fname . '"');
                header('Cache-Control: max-age=0');
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
                $writer->save('php://output');
            } catch (Throwable $e) {
                if (!headers_sent()) {
                    header('HTTP/1.1 500 Internal Server Error');
                }
                exit('Gagal mengekspor Excel.');
            }
            exit;
        }
        if ($export_fmt === 'pdf') {
            $print_now = !isset($_GET['autoprint']) || (string)$_GET['autoprint'] !== '0';
            $preview_title = $sku_export_base;
            $school_name_print = (string)($school_profile['nama_madrasah'] ?? $school_profile['nama_sekolah'] ?? 'MADRASAH');
            $school_address_print = (string)($school_profile['alamat'] ?? '');
            $email_print = (string)($school_profile['email_madrasah'] ?? '');
            $website_print = (string)($school_profile['website_madrasah'] ?? '');
            $school_year_print = (string)($school_profile['tahun_ajaran'] ?? '-');

            $logo_pramuka_val = trim((string)($sku_print_settings['logo_pramuka'] ?? ''));
            $logo_pramuka_url = $logo_pramuka_val !== '' && is_file(__DIR__ . '/../uploads/' . basename($logo_pramuka_val)) ? ('../uploads/' . basename($logo_pramuka_val)) : '';

            $logo_wosm_val = trim((string)($sku_print_settings['logo_wosm'] ?? ''));
            $logo_wosm_url = $logo_wosm_val !== '' && is_file(__DIR__ . '/../uploads/' . basename($logo_wosm_val)) ? ('../uploads/' . basename($logo_wosm_val)) : '';

            $nomor_gudep = trim((string)(($sku_print_settings['nomor_gudep'] ?? '') ?: ($sku_print_settings['gugus_depan'] ?? '03.016')));

            $golongan_str = strtoupper(trim((string)($selected_golongan_name ?? 'SIAGA')));
            $tingkat_str = strtoupper(trim((string)($selected_tingkat_name ?? '')));

            $asset_ver = (string)time();
            $tempat_surat = trim((string)($sku_print_settings['tempat_surat'] ?? '')) ?: '................';
            $tanggal_surat = trim((string)($sku_print_settings['tanggal_surat'] ?? ''));
            $tanggal_surat = $tanggal_surat !== '' && strtotime($tanggal_surat) !== false ? date('d-m-Y', strtotime($tanggal_surat)) : date('d-m-Y');
            $ketua_gudep = trim((string)($sku_print_settings['ketua_gudep'] ?? '')) ?: '........................';
            $nta_ketua_gudep = trim((string)($sku_print_settings['nta_ketua_gudep'] ?? '')) ?: '-';
            $qr_payload = "Ketua Gudep: {$ketua_gudep}\nNTA: {$nta_ketua_gudep}\nTanggal: {$tanggal_surat}\nDokumen: Rekap SKU {$selected_tingkat_name}";
            $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . rawurlencode($qr_payload);
            header('Content-Type: text/html; charset=UTF-8');
            ?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($preview_title, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        @page { size: 330mm 215mm; margin: 5mm 8mm 8mm 8mm; } /* F4 landscape */
        body { font-family: Arial, sans-serif; margin: 0; background: #f3f4f6; color: #111; }
        .toolbar {
            position: sticky; top: 0; z-index: 5;
            display: flex; gap: 8px; align-items: center; justify-content: space-between;
            padding: 10px 12px; background: #fff; border-bottom: 1px solid #e5e7eb;
        }
        .toolbar .left { display: flex; gap: 8px; align-items: center; }
        .toolbar button, .toolbar a {
            font-size: 14px; padding: 8px 10px; border-radius: 8px;
            border: 1px solid #d1d5db; background: #fff; cursor: pointer; text-decoration: none; color: #111827;
        }
        .toolbar button.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
        .hint { font-size: 12px; color: #6b7280; }
        .wrap { max-width: 1600px; margin: 10px auto; background: #fff; box-shadow: 0 8px 24px rgba(0,0,0,.08); }
        .content { padding: 8mm; }
        table.kop-header-pramuka { width: 60%; margin-left: auto; margin-right: auto; border-collapse: collapse; border-bottom: 2px solid #000; margin-bottom: 12px; padding-bottom: 6px; }
        table.kop-header-pramuka td { border: none !important; padding: 0 !important; vertical-align: middle; }
        td.kop-logo-left { width: 80px; text-align: center; }
        td.kop-logo-left img { height: 65px; width: auto; max-width: 80px; object-fit: contain; }
        td.kop-logo-right { width: 80px; text-align: center; }
        td.kop-logo-right img { height: 65px; width: auto; max-width: 80px; object-fit: contain; }
        td.kop-text { text-align: center; }
        td.kop-text h2 { margin: 0; font-size: 12pt; font-weight: bold; color: #000; letter-spacing: 0.3px; }
        td.kop-text h1 { margin: 2px 0; font-size: 13.5pt; font-weight: bold; color: #000; letter-spacing: 0.3px; }
        td.kop-text p.alamat { margin: 2px 0; font-size: 9.5pt; color: #000; }
        td.kop-text p.kontak { margin: 2px 0 0; font-size: 9pt; color: #000; }
        .title { text-align: center; font-weight: bold; font-size: 13pt; text-decoration: underline; margin: 14px 0 2px; text-transform: uppercase; }
        .subtitle-golongan { text-align: center; font-weight: bold; font-size: 11pt; color: #111; margin-bottom: 2px; text-transform: uppercase; }
        .subtitle { text-align: center; font-size: 10pt; color: #333; margin-bottom: 15px; }
        .tanggal { text-align: right; font-size: 11px; color: #555; margin-bottom: 6px; }
        table { border-collapse: collapse; width: 100%; table-layout: fixed; font-size: 10px; }
        th, td { border: 1px solid #444; padding: 3px; text-align: center; vertical-align: middle; word-wrap: break-word; }
        th { background: #eaeaea; font-weight: 700; }
        td.nama, th.nama { text-align: left; padding-left: 6px; }
        th.sku-print-th-vertical {
            width: 24px;
            min-width: 24px;
            height: 165px;
            vertical-align: bottom;
            padding: 2px 1px;
        }
        .sku-print-vtext {
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            display: inline-block;
            max-height: 155px;
            overflow: hidden;
            line-height: 1.05;
            font-size: 9px;
            text-align: left;
        }
        .signature-wrap {
            margin-top: 6mm;
            display: flex;
            justify-content: flex-end;
            page-break-inside: avoid;
            break-inside: avoid-page;
        }
        .signature-box {
            width: 250px;
            text-align: center;
            page-break-inside: avoid;
            break-inside: avoid-page;
        }
        .signature-meta { text-align: left; margin-bottom: 8px; font-size: 12px; }
        .signature-name { font-weight: 700; text-decoration: underline; margin-top: 6px; }
        .signature-nta { margin-top: 2px; font-size: 12px; }
        .signature-qr { width: 82px; height: 82px; margin: 4px auto; display: block; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none !important; }
            .wrap { max-width: none; margin: 0; box-shadow: none; }
            .signature-wrap, .signature-box { page-break-inside: avoid !important; break-inside: avoid-page !important; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <div class="left">
        <button class="primary" type="button" onclick="window.print()">Print</button>
        <button type="button" onclick="window.location.reload()">Reload</button>
        <a href="syarat_kecakapan_umum.php?tingkat=<?= (int)$selected_tingkat_id ?>">Kembali</a>
    </div>
    <div class="hint">Preview cetak SKU F4 landscape. Reload tetap didukung.</div>
</div>
<div class="wrap">
    <div class="content">
        <div class="tanggal">Dicetak: <?= htmlspecialchars(date('d/m/Y H:i'), ENT_QUOTES, 'UTF-8') ?></div>
        <table class="kop-header-pramuka">
            <tr>
                <td class="kop-logo-left">
                    <?php if (!empty($logo_pramuka_url)): ?>
                        <img src="<?= htmlspecialchars($logo_pramuka_url, ENT_QUOTES, 'UTF-8') ?>" alt="Logo Pramuka">
                    <?php endif; ?>
                </td>
                <td class="kop-text">
                    <h2>GERAKAN PRAMUKA GUGUS DEPAN <?= htmlspecialchars(strtoupper($nomor_gudep), ENT_QUOTES, 'UTF-8') ?></h2>
                    <h1>BERPANGKALAN PADA <?= htmlspecialchars(strtoupper($school_name_print), ENT_QUOTES, 'UTF-8') ?></h1>
                    <?php if ($school_address_print !== ''): ?>
                        <p class="alamat"><?= htmlspecialchars($school_address_print, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <?php if ($website_print !== '' || $email_print !== ''): ?>
                        <p class="kontak">
                            <?= $website_print !== '' ? 'Website: <span style="color:#00f;text-decoration:underline;">' . htmlspecialchars($website_print, ENT_QUOTES, 'UTF-8') . '</span>' : '' ?>
                            <?= ($website_print !== '' && $email_print !== '') ? '&nbsp;&nbsp;&nbsp;&nbsp;' : '' ?>
                            <?= $email_print !== '' ? 'email: <span style="color:#00f;text-decoration:underline;">' . htmlspecialchars($email_print, ENT_QUOTES, 'UTF-8') . '</span>' : '' ?>
                        </p>
                    <?php endif; ?>
                </td>
                <td class="kop-logo-right">
                    <?php if (!empty($logo_wosm_url)): ?>
                        <img src="<?= htmlspecialchars($logo_wosm_url, ENT_QUOTES, 'UTF-8') ?>" alt="Logo WOSM">
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <div class="title">REKAP SYARAT KECAKAPAN UMUM</div>
        <div class="subtitle-golongan">GOLONGAN <?= htmlspecialchars($golongan_str, ENT_QUOTES, 'UTF-8') ?><?= $tingkat_str !== '' ? ' TINGKAT ' . htmlspecialchars($tingkat_str, ENT_QUOTES, 'UTF-8') : '' ?></div>
        <?php if ($school_year_print !== ''): ?>
            <div class="subtitle">Tahun Ajaran <?= htmlspecialchars($school_year_print, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <table>
            <thead>
            <tr>
                <th style="width:28px;">No</th>
                <th class="nama" style="width:160px;">Nama Peserta Didik</th>
                <?php foreach ($sku_butir_rows as $bb): ?>
                    <th class="sku-print-th-vertical">
                        <span class="sku-print-vtext">#<?= (int)$bb['urutan'] ?> <?= htmlspecialchars((string)$bb['teks_butir'], ENT_QUOTES, 'UTF-8') ?></span>
                    </th>
                <?php endforeach; ?>
                <th style="width:64px;">Status</th>
                <th style="width:70px;">Persentase</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($peserta_rows === []): ?>
                <tr>
                    <td colspan="<?= 4 + count($sku_butir_rows) ?>">Belum ada anggota aktif untuk tingkat ini.</td>
                </tr>
            <?php else: ?>
                <?php $nomPrint = 1; foreach ($peserta_rows as $p): ?>
                    <?php
                        $pid = (int)$p['id_peserta_didik_barung'];
                        $inf = sku_compute_status_cell($pdo, $pid, $selected_tingkat_id);
                    ?>
                    <tr>
                        <td><?= $nomPrint++ ?></td>
                        <td class="nama"><?= sku_html_person_name((string)$p['nama_peserta_didik']) ?></td>
                        <?php foreach ($sku_butir_rows as $bb): ?>
                            <?php
                                $bid = (int)$bb['id_butir'];
                                $on = !empty($checks_map[$pid][$bid]);
                                $tgl = (string)($tanggal_map[$pid][$bid] ?? '');
                            ?>
                            <td>
                                <?= $on ? '✓' : '—' ?>
                                <?php if ($tgl !== ''): ?><br><small><?= htmlspecialchars(date('d-m-Y', strtotime($tgl)), ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td><?= htmlspecialchars($inf['label'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $inf['percentage'] ?>%<br><small><?= $inf['done'] ?>/<?= $inf['total'] ?></small></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="signature-wrap">
            <div class="signature-box">
                <div class="signature-meta">
                    <div>Dikeluarkan di: <?= htmlspecialchars($tempat_surat, ENT_QUOTES, 'UTF-8') ?></div>
                    <div>Tanggal: <?= htmlspecialchars($tanggal_surat, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div>Ketua Gudep,</div>
                <img class="signature-qr" src="<?= htmlspecialchars($qr_url, ENT_QUOTES, 'UTF-8') ?>" alt="QR Tanda Tangan" referrerpolicy="no-referrer">
                <div class="signature-name"><?= htmlspecialchars($ketua_gudep, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="signature-nta">NTA: <?= htmlspecialchars($nta_ketua_gudep, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
    </div>
</div>
<?php if ($print_now): ?>
<script>
window.addEventListener('load', function() {
    setTimeout(function() { window.print(); }, 250);
});
</script>
<?php endif; ?>
</body>
</html>
<?php
            exit;
        }
    }
}

$flash_ok = isset($_SESSION['sku_flash_ok']) ? (string)$_SESSION['sku_flash_ok'] : '';
$flash_err = isset($_SESSION['sku_flash_err']) ? (string)$_SESSION['sku_flash_err'] : '';
unset($_SESSION['sku_flash_ok'], $_SESSION['sku_flash_err']);

$js_page = [];
if ($flash_ok !== '') {
    $js_page[] = 'Swal.fire({icon:\'success\',title:\'Berhasil\',text:'
        . json_encode($flash_ok) . ',timer:2200,showConfirmButton:false});';
}
if ($flash_err !== '') {
    $js_page[] = 'Swal.fire({icon:\'error\',title:\'Import\',text:' . json_encode($flash_err) . '});';
}

$js_page[] = <<< 'SKUJS'
$(function(){
  if (!document.getElementById('skuToastrIconFix')) {
    var styleFix = document.createElement('style');
    styleFix.id = 'skuToastrIconFix';
    styleFix.textContent = '.toast-success:before{content:"✓"!important;font-family:Arial,sans-serif!important;font-weight:700!important;}';
    document.head.appendChild(styleFix);
  }
  var TID = parseInt($('#skuTingkatId').data('tid'), 10) || 0;
  if (!TID) return;
  function notifyPromotedAndReload() {
    if (typeof toastr !== 'undefined' && toastr) {
      toastr.options = {
        closeButton: true,
        progressBar: true,
        timeOut: 1400,
        extendedTimeOut: 300,
        positionClass: 'toast-top-right'
      };
      toastr.success('Siswa lulus SKU dan dipindahkan ke tingkat berikutnya.', 'Naik tingkat otomatis');
    }
    setTimeout(function(){ window.location.reload(); }, 1200);
  }
  $('.sku-check').on('change', function() {
    var $cb = $(this);
    var fd = new FormData();
    fd.append('sku_ajax','1');
    fd.append('action','toggle');
    fd.append('id_tingkat_barung', String(TID));
    fd.append('id_peserta_didik_barung', String($cb.data('peserta')));
    fd.append('id_butir', String($cb.data('butir')));
    fd.append('checked', $cb.is(':checked') ? '1' : '0');
    $.ajax({ url: window.location.pathname, method: 'POST', data: fd, processData:false, contentType:false, dataType:'json' })
    .done(function(resp){
      if (!resp || !resp.ok){ Swal.fire('Error', (resp && resp.msg) ? resp.msg : 'Gagal menyimpan', 'error'); $cb.prop('checked', !$cb.is(':checked')); return; }
      if (resp.promoted) {
        notifyPromotedAndReload();
        return;
      }
    var key = '[data-peserta="' + $cb.data('peserta') + '"][data-butir="' + $cb.data('butir') + '"]';
    var $date = $('.sku-date' + key);
    if ($date.length) {
      if ($cb.is(':checked')) {
        if (resp.tanggal_ujian) $date.val(resp.tanggal_ujian);
      } else {
        $date.val('');
      }
    }
    // Sync all checkboxes with same peserta & butir (card and table)
    $('.sku-check' + key).prop('checked', $cb.is(':checked'));
    $('.sku-item-row' + key).toggleClass('border-success', $cb.is(':checked'));

    // Sync status badges
    var $statusCells = $('.sku-status-cell[data-peserta="' + $cb.data('peserta') + '"]');
    $statusCells.each(function() {
      var $el = $(this);
      if ($el.hasClass('badge')) {
        $el.toggleClass('badge-success', !!resp.status_ok).toggleClass('badge-danger', !resp.status_ok);
      } else {
        $el.toggleClass('text-success', !!resp.status_ok).toggleClass('text-danger', !resp.status_ok);
      }
      $el.text(resp.status_label || '—');
    });
    
    if (typeof resp.percentage !== 'undefined') {
      var $pctCells = $('.sku-pct-cell[data-peserta="' + $cb.data('peserta') + '"]');
      $pctCells.each(function() {
        var $bar = $(this).find('.progress-bar');
        if ($bar.length) {
          $bar.css('width', resp.percentage + '%').attr('aria-valuenow', resp.percentage);
          if ($bar.parent().height() > 15) {
            $bar.text(resp.percentage + '%');
          }
          $bar.toggleClass('bg-success', resp.percentage >= 100).toggleClass('bg-primary', resp.percentage < 100);
        }
        $(this).find('small').text(resp.done + ' / ' + resp.total + ' butir');
      });
      var $pctText = $('.sku-pct-text[data-peserta="' + $cb.data('peserta') + '"]');
      if ($pctText.length) {
        $pctText.find('.sku-pct-val').text(resp.percentage + '%');
        $pctText.html('<span class="sku-pct-val">' + resp.percentage + '%</span> (' + resp.done + '/' + resp.total + ')');
      }
    }
    }).fail(function(){ Swal.fire('Error', 'Permintaan gagal', 'error'); $cb.prop('checked', !$cb.is(':checked')); });
  });

  $('.sku-date').on('change', function() {
    var $dt = $(this);
    var peserta = parseInt($dt.data('peserta'), 10);
    var butir = parseInt($dt.data('butir'), 10);
    if (!peserta || !butir || !TID) return;
    var fd = new FormData();
    fd.append('sku_ajax','1');
    fd.append('action','set_tanggal');
    fd.append('id_tingkat_barung', String(TID));
    fd.append('id_peserta_didik_barung', String(peserta));
    fd.append('id_butir', String(butir));
    fd.append('tanggal_ujian', $dt.val() || '');
    $.ajax({ url: window.location.pathname, method: 'POST', data: fd, processData:false, contentType:false, dataType:'json' })
    .done(function(resp){
      if (!resp || !resp.ok) { Swal.fire('Error', (resp && resp.msg) ? resp.msg : 'Gagal menyimpan tanggal', 'error'); return; }
      if (resp.promoted) {
        notifyPromotedAndReload();
        return;
      }
      var key = '[data-peserta="' + peserta + '"][data-butir="' + butir + '"]';
      if (resp.checked) {
        $('.sku-check' + key).prop('checked', true);
        $('.sku-item-row' + key).addClass('border-success');
      } else {
        $('.sku-check' + key).prop('checked', false);
        $('.sku-item-row' + key).removeClass('border-success');
      }
      $('.sku-date' + key).val($dt.val() || '');

      var $statusCells = $('.sku-status-cell[data-peserta="' + peserta + '"]');
      $statusCells.each(function() {
        var $el = $(this);
        if ($el.hasClass('badge')) {
          $el.toggleClass('badge-success', !!resp.status_ok).toggleClass('badge-danger', !resp.status_ok);
        } else {
          $el.toggleClass('text-success', !!resp.status_ok).toggleClass('text-danger', !resp.status_ok);
        }
        $el.text(resp.status_label || '—');
      });
      
      if (typeof resp.percentage !== 'undefined') {
        var $pctCells = $('.sku-pct-cell[data-peserta="' + peserta + '"]');
        $pctCells.each(function() {
          var $bar = $(this).find('.progress-bar');
          if ($bar.length) {
            $bar.css('width', resp.percentage + '%').attr('aria-valuenow', resp.percentage);
            if ($bar.parent().height() > 15) {
              $bar.text(resp.percentage + '%');
            }
            $bar.toggleClass('bg-success', resp.percentage >= 100).toggleClass('bg-primary', resp.percentage < 100);
          }
          $(this).find('small').text(resp.done + ' / ' + resp.total + ' butir');
        });
        var $pctText = $('.sku-pct-text[data-peserta="' + peserta + '"]');
        if ($pctText.length) {
          $pctText.html('<span class="sku-pct-val">' + resp.percentage + '%</span> (' + resp.done + '/' + resp.total + ')');
        }
      }
    }).fail(function(){
      Swal.fire('Error', 'Permintaan gagal', 'error');
    });
  });

  $('.sku-date').on('click focus', function() {
    if (typeof this.showPicker === 'function') {
      try { this.showPicker(); } catch (e) {}
    }
  });

  $('#btnSkuSaveButir').on('click', function(){
    var teks = ($('#teks_butir_baru').val() || '').trim();
    if (!teks || !TID) return;
    var fd = new FormData();
    fd.append('sku_ajax','1'); fd.append('action','add_butir'); fd.append('id_tingkat_barung', String(TID)); fd.append('teks_butir', teks);
    $.ajax({ url: window.location.pathname, method: 'POST', data: fd, processData:false, contentType:false, dataType:'json' })
    .done(function(resp){ if (resp && resp.ok) window.location.reload(); else Swal.fire('Perhatian', (resp&&resp.msg)||'Gagal', 'warning'); });
  });

  $('.btn-edit-butir').on('click', function(){
    var $b = $(this);
    var bid = parseInt($b.data('butir'), 10);
    if (!bid || !TID) return;
    var teks = $b.attr('data-sku-teks') || '';
    $('#sku_edit_butir_id').val(String(bid));
    $('#sku_edit_butir_text').val(teks);
    $('#modalSkuEdit').modal('show');
  });
  $('#btnSkuUpdateButir').on('click', function(){
    var bid = parseInt($('#sku_edit_butir_id').val(), 10);
    var teks = ($('#sku_edit_butir_text').val() || '').trim();
    if (!bid || !TID || !teks) return;
    var fd = new FormData();
    fd.append('sku_ajax','1'); fd.append('action','edit_butir'); fd.append('id_tingkat_barung', String(TID));
    fd.append('id_butir', String(bid)); fd.append('teks_butir', teks);
    $.ajax({ url: window.location.pathname, method: 'POST', data: fd, processData:false, contentType:false, dataType:'json' })
    .done(function(resp){
      if (resp && resp.ok) { $('#modalSkuEdit').modal('hide'); window.location.reload(); }
      else Swal.fire('Perhatian', (resp&&resp.msg)||'Gagal menyimpan', 'warning');
    });
  });

  // Quick today date fill
  $(document).on('click', '.btn-today', function() {
    var $btn = $(this);
    var p = $btn.data('peserta');
    var b = $btn.data('butir');
    var today = new Date().toISOString().split('T')[0];
    var $dateInput = $('.sku-date[data-peserta="' + p + '"][data-butir="' + b + '"]');
    if ($dateInput.length) {
      $dateInput.val(today).trigger('change');
    }
  });

  // Switch between Cards and Table View
  var currentSkuView = localStorage.getItem('simad_sku_view') || 'cards';
  function applySkuView(view) {
    if (view === 'table') {
      $('#skuCardsView').addClass('d-none');
      $('#skuTableView').removeClass('d-none');
      $('#btnViewTable').addClass('active');
      $('#btnViewCards').removeClass('active');
    } else {
      $('#skuTableView').addClass('d-none');
      $('#skuCardsView').removeClass('d-none');
      $('#btnViewCards').addClass('active');
      $('#btnViewTable').removeClass('active');
    }
    localStorage.setItem('simad_sku_view', view);
  }
  $('#btnViewCards').on('click', function() { applySkuView('cards'); });
  $('#btnViewTable').on('click', function() { applySkuView('table'); });
  applySkuView(currentSkuView);

  // Chevron animation for card collapse
  $('.sku-btn-expand').on('click', function() {
    var $icon = $(this).find('.sku-expand-icon i');
    setTimeout(function() {
      var isExpanded = $(this).attr('aria-expanded') === 'true';
      $icon.toggleClass('fa-chevron-up', isExpanded).toggleClass('fa-chevron-down', !isExpanded);
    }.bind(this), 20);
  });

  var $filterNama = $('#skuFilterNama');
  if ($filterNama.length) {
    function skuApplyNamaFilter() {
      var q = ($filterNama.val() || '').trim().toLowerCase();
      
      // Filter Table View
      var $rows = $('#skuMainTable tbody tr.sku-data-row');
      var seq = 1;
      $rows.each(function() {
        var $tr = $(this);
        var nama = String($tr.attr('data-nama-normal') || '');
        var nisn = String($tr.attr('data-nisn-normal') || '');
        var show = !q || (nama.indexOf(q) !== -1) || (nisn.indexOf(q) !== -1);
        $tr.toggle(show);
        if (show) {
          var $no = $tr.find('td.sku-row-no');
          if (q) {
            $no.text(seq++);
          } else {
            $no.text($tr.attr('data-urut-asli') || '');
          }
        }
      });

      // Filter Cards View
      var $cards = $('#skuCardsContainer .sku-card-item');
      $cards.each(function() {
        var $card = $(this);
        var nama = String($card.attr('data-nama-normal') || '');
        var nisn = String($card.attr('data-nisn-normal') || '');
        var show = !q || (nama.indexOf(q) !== -1) || (nisn.indexOf(q) !== -1);
        $card.toggle(show);
      });
    }
    $filterNama.on('input', skuApplyNamaFilter);
  }

  $('.btn-del-butir').on('click', function(e){
    e.stopPropagation();
    var bid = parseInt($(this).data('butir'), 10);
    if (!bid || !TID) return;
    Swal.fire({title:'Hapus kolom SKU?', text:'Data centang siswa untuk butir ini ikut hilang.', icon:'warning', showCancelButton:true, confirmButtonColor:'#d33', confirmButtonText:'Ya'})
    .then(function(r){
      if (!r.isConfirmed) return;
      var fd = new FormData();
      fd.append('sku_ajax','1'); fd.append('action','delete_butir'); fd.append('id_tingkat_barung', String(TID)); fd.append('id_butir', String(bid));
      $.ajax({ url: window.location.pathname, method: 'POST', data: fd, processData:false, contentType:false, dataType:'json' })
      .done(function(resp){ if (resp && resp.ok) window.location.reload(); else Swal.fire('Gagal', (resp&&resp.msg)||'', 'error'); });
    });
  });

  // Upload import butir SKU dengan progress bar
  $('#skuImportForm').on('submit', function(e){
    e.preventDefault();
    var form = this;
    var fd = new FormData(form);
    fd.append('ajax', '1');
    var $barWrap = $('#skuImportProgressWrap');
    var $bar = $('#skuImportProgressBar');
    var $status = $('#skuImportStatus');
    var $btnSubmit = $('#skuImportSubmitBtn');
    var $btnClose = $('#skuImportCloseBtn');
    $barWrap.removeClass('d-none');
    $bar.css('width', '0%').attr('aria-valuenow', 0).text('0%');
    $status.text('Mengunggah file...');
    $btnSubmit.prop('disabled', true);
    $btnClose.prop('disabled', true);

    $.ajax({
      url: window.location.pathname,
      method: 'POST',
      data: fd,
      processData: false,
      contentType: false,
      dataType: 'json',
      xhr: function() {
        var xhr = new window.XMLHttpRequest();
        xhr.upload.addEventListener('progress', function(evt) {
          if (!evt.lengthComputable) return;
          var pct = Math.round((evt.loaded / evt.total) * 100);
          if (pct > 100) pct = 100;
          $bar.css('width', pct + '%').attr('aria-valuenow', pct).text(pct + '%');
          if (pct >= 100) $status.text('Memproses butir SKU...');
        }, false);
        return xhr;
      }
    }).done(function(resp){
      if (resp && resp.ok) {
        Swal.fire({icon:'success', title:'Berhasil', text:resp.msg || 'Import selesai'})
          .then(function(){ window.location.href = 'syarat_kecakapan_umum.php?tingkat=' + encodeURIComponent(resp.tingkat || TID); });
      } else {
        Swal.fire('Gagal', (resp && resp.msg) ? resp.msg : 'Import gagal', 'error');
      }
    }).fail(function(){
      Swal.fire('Gagal', 'Upload gagal. Coba lagi.', 'error');
    }).always(function(){
      $btnSubmit.prop('disabled', false);
      $btnClose.prop('disabled', false);
    });
  });
});
SKUJS;

require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>
<style>
/* Prevent FOUC (Flash of Unstyled Content) on SKU pill tabs */
.sku-tab-pill {
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 8px 16px !important;
    border-radius: 999px !important;
    font-size: 0.88rem !important;
    font-weight: 700 !important;
    text-decoration: none !important;
    white-space: nowrap !important;
    outline: none !important;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04) !important;
}
.sku-tab-pill:focus, .sku-tab-pill:active {
    outline: none !important;
    box-shadow: none !important;
}
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Syarat Kecakapan Umum</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Ekstrakurikuler</div>
                <div class="breadcrumb-item">SKU</div>
            </div>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between" style="gap:10px;">
                    <h4 class="mb-0">
                        SKU Pramuka
                        <?= $selected_tingkat_name !== '' ? '<span class="badge badge-light border text-dark ml-2">' . htmlspecialchars($selected_tingkat_name) . '</span>' : '' ?>
                    </h4>
                    <div class="d-flex flex-wrap align-items-center" style="gap:6px;">
                        <?php if ($can_manage_sku): ?>
                        <button type="button" class="btn btn-outline-primary btn-sm" data-toggle="modal" data-target="#modalSkuAdd" title="Tambah kolom butir SKU"
                            <?= $selected_tingkat_id <= 0 ? 'disabled' : '' ?>><i class="fas fa-plus"></i><span class="d-none d-sm-inline"> Tambah kolom</span></button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-toggle="modal" data-target="#modalSkuImport" title="Import butir SKU"
                            <?= $selected_tingkat_id <= 0 ? 'disabled' : '' ?>><i class="fas fa-file-import"></i><span class="d-none d-sm-inline"> Import SKU</span></button>
                        <?php endif; ?>
                        <a class="btn btn-outline-success btn-sm <?= $selected_tingkat_id <= 0 ? 'disabled text-muted' : '' ?>" title="Ekspor Excel"
                            href="<?= $selected_tingkat_id > 0 ? 'syarat_kecakapan_umum.php?tingkat=' . (int)$selected_tingkat_id . '&export=xlsx' : '#' ?>">
                            <i class="fas fa-file-excel"></i><span class="d-none d-sm-inline"> Ekspor Excel</span></a>
                        <a class="btn btn-outline-danger btn-sm <?= $selected_tingkat_id <= 0 ? 'disabled text-muted' : '' ?>" title="Ekspor PDF"
                            <?= $selected_tingkat_id > 0 ? 'target="_blank" rel="noopener noreferrer"' : '' ?>
                            href="<?= $selected_tingkat_id > 0 ? 'syarat_kecakapan_umum.php?tingkat=' . (int)$selected_tingkat_id . '&export=pdf' : '#' ?>">
                            <i class="fas fa-file-pdf"></i><span class="d-none d-sm-inline"> Ekspor PDF</span></a>
                    </div>
                </div>
                <div class="card-body">
                    <span id="skuTingkatId" data-tid="<?= (int)$selected_tingkat_id ?>" class="d-none"></span>

                    <?php if (empty($tingkat_list)): ?>
                        <div class="alert alert-warning mb-0">Belum ada data tingkat Pramuka. Tambahkan di menu <strong>Data Tingkat Barung</strong>.</div>
                    <?php else: ?>
                        <?php if ($can_manage_sku || count($assigned_tingkat_ids) > 1): ?>
                        <div class="sku-tingkat-tabs-wrapper mb-4">
                            <div class="sku-tingkat-tabs-scroll">
                                <?php 
                                    $filtered_tingkat = [];
                                    foreach ($tingkat_list as $t) {
                                        if (!$can_manage_sku && !in_array((int)($t['id_tingkat_barung'] ?? 0), $assigned_tingkat_ids)) continue;
                                        $filtered_tingkat[] = $t;
                                    }
                                    foreach ($filtered_tingkat as $index => $t):
                                    ?>
                                    <?php
                                        $tid = (int)($t['id_tingkat_barung'] ?? 0);
                                        $active = ($tid === $selected_tingkat_id);
                                        $golongan = $t['golongan'] ?? 'Siaga';
                                        $count_siswa = (int)($tingkat_counts[$tid] ?? 0);
                                        $is_penggalang = ($golongan === 'Penggalang');
                                    ?>
                                    <a href="?tingkat=<?= $tid ?>" 
                                       class="sku-tab-pill <?= $active ? 'active' : '' ?> <?= $is_penggalang ? 'sku-tab-penggalang' : 'sku-tab-siaga' ?>">
                                        <span class="sku-tab-icon">
                                            <i class="fas <?= $is_penggalang ? 'fa-compass' : 'fa-seedling' ?>"></i>
                                        </span>
                                        <span class="sku-tab-text"><?= htmlspecialchars((string)($t['nama_tingkat'] ?? '')) ?></span>
                                        <span class="sku-tab-count"><?= $count_siswa ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($sku_assignment_missing): ?>
                            <div class="alert alert-warning py-2">
                                Tingkat ampuan pembina belum diatur. Silakan set kolom <strong>Pembina Tingkat</strong> pada menu <strong>Data Pembina Pramuka</strong>.
                            </div>
                        <?php endif; ?>

                        <?php if ($selected_tingkat_id > 0): ?>
                            <?php
                                $sku_stats = ['total' => 0, 'lulus' => 0, 'proses' => 0];
                                $sku_status_cache = [];
                                foreach ($peserta_rows as $prStat) {
                                    $pidStat = (int)($prStat['id_peserta_didik_barung'] ?? 0);
                                    $infStat = sku_compute_status_cell($pdo, $pidStat, $selected_tingkat_id);
                                    $sku_status_cache[$pidStat] = $infStat;
                                    $sku_stats['total']++;
                                    if (!empty($infStat['ok'])) {
                                        $sku_stats['lulus']++;
                                    } else {
                                        $sku_stats['proses']++;
                                    }
                                }
                            ?>
                            <div class="app-toolbar">
                                <div class="app-search">
                                    <i class="fas fa-search"></i>
                                    <input type="search" id="skuFilterNama" placeholder="Cari nama atau NISN…" autocomplete="off">
                                </div>
                                <div class="app-view-switch" role="group" aria-label="Mode tampilan">
                                    <button type="button" class="active" id="btnViewCards" title="Tampilan Kartu">
                                        <i class="fas fa-th-large"></i> Kartu
                                    </button>
                                    <button type="button" id="btnViewTable" title="Tampilan Tabel">
                                        <i class="fas fa-table"></i> Tabel
                                    </button>
                                </div>
                            </div>

                            <div class="app-summary-row">
                                <div class="app-summary-chip">
                                    <div class="ico" style="background:#eff6ff;color:#1d4ed8;"><i class="fas fa-users"></i></div>
                                    <div>
                                        <div class="lbl">Total Anggota</div>
                                        <div class="val"><?= (int)$sku_stats['total'] ?> siswa</div>
                                    </div>
                                </div>
                                <div class="app-summary-chip">
                                    <div class="ico" style="background:#ecfdf5;color:#15803d;"><i class="fas fa-check-circle"></i></div>
                                    <div>
                                        <div class="lbl">Tuntas SKU</div>
                                        <div class="val"><?= (int)$sku_stats['lulus'] ?> siswa</div>
                                    </div>
                                </div>
                                <div class="app-summary-chip">
                                    <div class="ico" style="background:#fff7ed;color:#c2410c;"><i class="fas fa-hourglass-half"></i></div>
                                    <div>
                                        <div class="lbl">Masih Proses</div>
                                        <div class="val"><?= (int)$sku_stats['proses'] ?> siswa</div>
                                    </div>
                                </div>
                            </div>

                            <!-- SKU Cards View (Mobile & Desktop Friendly - Tanpa Geser Horizontal) -->
                            <div id="skuCardsView" class="mb-4">
                                <?php if (empty($peserta_rows)): ?>
                                    <div class="alert alert-light text-center py-5 border">
                                        <i class="fas fa-users-slash fa-3x text-muted mb-3 d-block"></i>
                                        Tidak ada anggota aktif untuk tingkat ini. Tambahkan di <strong>Data Anggota Pramuka</strong>.
                                    </div>
                                <?php else: ?>
                                    <div class="row app-cards-row" id="skuCardsContainer">
                                        <?php $cardIndex = 1; foreach ($peserta_rows as $p): ?>
                                            <?php
                                                $pid = (int)$p['id_peserta_didik_barung'];
                                                $inf = $sku_status_cache[$pid] ?? sku_compute_status_cell($pdo, $pid, $selected_tingkat_id);
                                                $namaNorm = mb_strtolower(sku_plain_person_name((string)$p['nama_peserta_didik']));
                                                $nisnNorm = mb_strtolower(trim((string)($p['nisn'] ?? $p['nta'] ?? '')));
                                                $namaLengkap = (string)$p['nama_peserta_didik'];
                                                $namaKelas = (string)($p['nama_kelas'] ?? '-');
                                                $fotoSiswa = (string)($p['foto'] ?? '');
                                                $initials = strtoupper(mb_substr(trim($namaLengkap), 0, 1));
                                                $fallbackHtml = '<div class="sku-avatar-initials">' . htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') . '</div>';
                                                $avatarHtml = getPersonAvatarHtml($fotoSiswa, $fallbackHtml, 'sku-avatar-img');
                                            ?>
                                            <div class="col-12 col-md-6 mb-3 sku-card-item"
                                                 data-nama-normal="<?= htmlspecialchars($namaNorm, ENT_QUOTES, 'UTF-8') ?>"
                                                 data-nisn-normal="<?= htmlspecialchars($nisnNorm, ENT_QUOTES, 'UTF-8') ?>"
                                                 data-peserta="<?= $pid ?>">
                                                <div class="card sku-student-card h-100 shadow-sm border-0 mb-0">
                                                    <div class="card-body p-3">
                                                        <!-- Header Siswa Mobile-App Style -->
                                                        <div class="sku-card-head mb-3">
                                                            <div class="sku-card-left">
                                                                <div class="sku-student-no mr-2" title="Nomor Urut">
                                                                    <?= $cardIndex++ ?>
                                                                </div>
                                                                <div class="mr-3 flex-shrink-0">
                                                                    <div class="sku-avatar-wrap">
                                                                        <?= $avatarHtml ?>
                                                                    </div>
                                                                </div>
                                                                <div class="sku-card-id">
                                                                    <div class="sku-student-name">
                                                                        <?= sku_html_person_name($namaLengkap) ?>
                                                                    </div>
                                                                    <div class="sku-student-meta">
                                                                        <span><i class="fas fa-id-card-alt mr-1 text-primary"></i><?= htmlspecialchars($p['nisn'] ?? $p['nta'] ?? '-') ?></span>
                                                                        <span class="meta-dot">•</span>
                                                                        <span><i class="fas fa-graduation-cap mr-1 text-info"></i>Kelas <?= htmlspecialchars($namaKelas) ?></span>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="sku-card-right">
                                                                <span class="sku-app-badge sku-status-cell <?= $inf['ok'] ? 'sku-badge-lulus' : 'sku-badge-proses' ?>"
                                                                      data-peserta="<?= $pid ?>">
                                                                    <i class="fas <?= $inf['ok'] ? 'fa-check-circle' : 'fa-clock' ?> mr-1"></i>
                                                                    <?= htmlspecialchars($inf['label']) ?>
                                                                </span>
                                                                <div class="sku-pct-pill sku-pct-text" data-peserta="<?= $pid ?>">
                                                                    <i class="fas fa-chart-line mr-1 text-primary"></i>
                                                                    <span class="sku-pct-val font-weight-bold"><?= $inf['percentage'] ?>%</span>
                                                                    <span class="text-muted ml-1 font-weight-normal">(<?= $inf['done'] ?>/<?= $inf['total'] ?>)</span>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <!-- Progress Bar Siswa -->
                                                        <div class="sku-pct-cell mb-3" data-peserta="<?= $pid ?>">
                                                            <div class="progress sku-progress-track">
                                                                <div class="progress-bar <?= $inf['percentage'] >= 100 ? 'bg-success' : 'bg-primary' ?>"
                                                                     role="progressbar"
                                                                     style="width: <?= $inf['percentage'] ?>%; transition: width .3s ease;"
                                                                     aria-valuenow="<?= $inf['percentage'] ?>"
                                                                     aria-valuemin="0"
                                                                     aria-valuemax="100"></div>
                                                            </div>
                                                        </div>

                                                        <!-- Accordion Toggle Butir SKU (Mobile App Pill Button) -->
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <button class="btn sku-app-btn-toggle btn-block d-flex align-items-center justify-content-between py-2 px-3 sku-btn-expand"
                                                                    type="button"
                                                                    data-toggle="collapse"
                                                                    data-target="#skuCollapse<?= $pid ?>"
                                                                    aria-expanded="false"
                                                                    aria-controls="skuCollapse<?= $pid ?>">
                                                                <span class="d-flex align-items-center">
                                                                    <span class="sku-icon-circle mr-2"><i class="fas fa-tasks"></i></span>
                                                                    <span>Lembar Ujian SKU <strong class="text-primary">(<?= count($sku_butir_rows) ?> Butir)</strong></span>
                                                                </span>
                                                                <span class="sku-expand-icon"><i class="fas fa-chevron-down"></i></span>
                                                            </button>
                                                        </div>

                                                        <!-- Konten Butir SKU Vertikal -->
                                                        <div class="collapse mt-3" id="skuCollapse<?= $pid ?>">
                                                            <div class="sku-butir-list rounded-xl p-2">
                                                                <?php if (empty($sku_butir_rows)): ?>
                                                                    <div class="text-center text-muted py-3 small">Belum ada butir SKU pada tingkat ini.</div>
                                                                <?php else: ?>
                                                                    <?php foreach ($sku_butir_rows as $bb): ?>
                                                                        <?php
                                                                            $bid = (int)$bb['id_butir'];
                                                                            $on = !empty($checks_map[$pid][$bid]);
                                                                            $tgl_ujian = (string)($tanggal_map[$pid][$bid] ?? '');
                                                                        ?>
                                                                        <div class="sku-item-row p-2 mb-2 rounded-lg d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2 <?= $on ? 'sku-item-completed' : '' ?>"
                                                                             data-peserta="<?= $pid ?>" data-butir="<?= $bid ?>">
                                                                            <div class="d-flex align-items-start pr-sm-2 flex-grow-1">
                                                                                <div class="custom-control custom-checkbox mr-2 pt-1">
                                                                                    <input type="checkbox"
                                                                                           class="custom-control-input sku-check"
                                                                                           id="chk_card_<?= $pid ?>_<?= $bid ?>"
                                                                                           data-peserta="<?= $pid ?>"
                                                                                           data-butir="<?= $bid ?>"
                                                                                           <?= $on ? 'checked' : '' ?>
                                                                                           <?= $can_interact_sku ? '' : 'disabled' ?>>
                                                                                    <label class="custom-control-label" for="chk_card_<?= $pid ?>_<?= $bid ?>"></label>
                                                                                </div>
                                                                                <div style="font-size: 0.9rem; line-height: 1.4;">
                                                                                    <span class="sku-num-pill">#<?= (int)$bb['urutan'] ?></span>
                                                                                    <span class="text-dark font-weight-500"><?= nl2br(htmlspecialchars($bb['teks_butir'])) ?></span>
                                                                                </div>
                                                                            </div>
                                                                            <!-- Input Tanggal Ujian dengan Tombol Khas Mobile Apps -->
                                                                            <div class="d-flex align-items-center flex-shrink-0 mt-2 mt-sm-0 pt-1 pt-sm-0 border-top border-top-sm-0 justify-content-end sku-date-wrapper">
                                                                                <div class="input-group input-group-sm">
                                                                                    <input type="date"
                                                                                           class="form-control form-control-sm sku-date"
                                                                                           data-peserta="<?= $pid ?>"
                                                                                           data-butir="<?= $bid ?>"
                                                                                           value="<?= htmlspecialchars($tgl_ujian, ENT_QUOTES, 'UTF-8') ?>"
                                                                                           title="Tanggal Ujian"
                                                                                           <?= $can_interact_sku ? '' : 'disabled' ?>>
                                                                                    <?php if ($can_interact_sku): ?>
                                                                                    <div class="input-group-append">
                                                                                        <button type="button" class="btn btn-today"
                                                                                                title="Isi tanggal hari ini"
                                                                                                data-peserta="<?= $pid ?>"
                                                                                                data-butir="<?= $bid ?>">
                                                                                            <i class="fas fa-magic mr-1"></i>Hari Ini
                                                                                        </button>
                                                                                    </div>
                                                                                    <?php endif; ?>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- SKU Table Matrix View (Disembunyikan default / Tampilan Alternatif) -->
                            <div class="sku-table-wrap mb-3 d-none" id="skuTableView">
                                <table class="table table-sm table-bordered mb-0 align-middle sku-main-table" id="skuMainTable">
                                    <thead class="sku-thead-stick">
                                        <tr>
                                            <th rowspan="3" class="sticky-sku-cell sku-th-no text-center">NO</th>
                                            <th rowspan="3" class="sticky-sku-cell sku-th-nama text-center">NAMA PESERTA DIDIK</th>
                                            <th colspan="<?= max(1, count($sku_butir_rows)) ?>" class="text-center py-1 border sku-meta-title-cell">
                                                <small class="text-uppercase font-weight-bold">Syarat kecakapan umum — per butir SKU</small>
                                            </th>
                                            <th rowspan="3" class="sticky-sku-cell-r-2 sku-th-status text-center bg-light">STATUS</th>
                                            <th rowspan="3" class="sticky-sku-cell-r sku-th-pct text-center bg-light">PERSENTASE UJIAN</th>
                                        </tr>
                                        <tr class="sku-th-num-row">
                                            <?php foreach ($sku_butir_rows as $bb): ?>
                                                <th class="text-center sku-col sku-th-butir-num font-weight-bold text-primary px-2 py-1" title="<?= htmlspecialchars($bb['teks_butir']) ?>">
                                                    <?= (int)$bb['urutan'] ?>
                                                </th>
                                            <?php endforeach; ?>
                                            <?php if (empty($sku_butir_rows)): ?>
                                                <th class="text-center text-muted sku-col sku-th-butir-num py-2">—</th>
                                            <?php endif; ?>
                                        </tr>
                                        <tr>
                                            <?php foreach ($sku_butir_rows as $bb): ?>
                                                <th class="sku-th-vertical text-center sku-col py-2" title="<?= htmlspecialchars($bb['teks_butir']) ?>">
                                                    <span class="sku-vtext"><?= nl2br(htmlspecialchars($bb['teks_butir'])) ?></span>
                                                    <div class="mt-1 d-flex justify-content-center align-items-center sku-col-actions" style="gap:4px;">
                                                        <?php if ($can_manage_sku): ?>
                                                        <button type="button" class="btn btn-xxs btn-outline-primary btn-edit-butir px-1"
                                                                data-butir="<?= (int)$bb['id_butir'] ?>"
                                                                data-sku-teks="<?= htmlspecialchars((string)$bb['teks_butir'], ENT_QUOTES, 'UTF-8') ?>"
                                                                title="Ubah kolom"><i class="fas fa-edit"></i></button>
                                                        <button type="button" class="btn btn-xxs btn-outline-danger btn-del-butir px-1"
                                                                data-butir="<?= (int)$bb['id_butir'] ?>" title="Hapus kolom"><i class="fas fa-times"></i></button>
                                                        <?php endif; ?>
                                                    </div>
                                                </th>
                                            <?php endforeach; ?>
                                            <?php if (empty($sku_butir_rows)): ?>
                                                <th class="text-center text-muted sku-col py-4">Belum ada butir SKU</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($peserta_rows)): ?>
                                            <tr>
                                                <td colspan="<?= 4 + max(1, count($sku_butir_rows)) ?>" class="text-center text-muted py-5">
                                                    Tidak ada anggota aktif untuk tingkat ini. Tambahkan di <strong>Data Anggota Pramuka</strong>.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php $nom = 1; foreach ($peserta_rows as $p): ?>
                                                <?php
                                                    $pid = (int)$p['id_peserta_didik_barung'];
                                                    $inf = sku_compute_status_cell($pdo, $pid, $selected_tingkat_id);
                                                    $namaNorm = mb_strtolower(sku_plain_person_name((string)$p['nama_peserta_didik']));
                                                    $nisnNorm = mb_strtolower(trim((string)($p['nisn'] ?? $p['nta'] ?? '')));
                                                    $nomBaris = $nom;
                                                ?>
                                                <tr class="sku-data-row" data-nama-normal="<?= htmlspecialchars($namaNorm, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-nisn-normal="<?= htmlspecialchars($nisnNorm, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-urut-asli="<?= (int)$nomBaris ?>">
                                                    <td class="text-center sticky-sku-cell sku-th-no sku-row-no"><?= $nom++ ?></td>
                                                    <td class="sticky-sku-cell sku-th-nama font-weight-bold text-left sku-td-nama"><?= sku_html_person_name((string)$p['nama_peserta_didik']) ?></td>
                                                    <?php foreach ($sku_butir_rows as $bb): ?>
                                                        <?php
                                                            $bid = (int)$bb['id_butir'];
                                                            $on = !empty($checks_map[$pid][$bid]);
                                                            $tgl_ujian = (string)($tanggal_map[$pid][$bid] ?? '');
                                                        ?>
                                                        <td class="text-center sku-col align-middle">
                                                            <label class="mb-0">
                                                                <input type="checkbox" class="sku-check" data-peserta="<?= $pid ?>" data-butir="<?= $bid ?>" <?= $on ? 'checked' : '' ?> <?= $can_interact_sku ? '' : 'disabled' ?> />
                                                            </label>
                                                            <input type="date"
                                                                   class="form-control form-control-sm mt-1 sku-date"
                                                                   data-peserta="<?= $pid ?>"
                                                                   data-butir="<?= $bid ?>"
                                                                   value="<?= htmlspecialchars($tgl_ujian, ENT_QUOTES, 'UTF-8') ?>" <?= $can_interact_sku ? '' : 'disabled' ?> />
                                                        </td>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($sku_butir_rows)): ?>
                                                        <td class="sku-col">&nbsp;</td>
                                                    <?php endif; ?>
                                                    <td class="text-center sku-status-cell sticky-sku-cell-r-2 font-weight-bold <?= $inf['ok'] ? 'text-success' : 'text-danger' ?>"
                                                        data-peserta="<?= $pid ?>">
                                                        <?= htmlspecialchars($inf['label']) ?>
                                                    </td>
                                                    <td class="text-center sku-pct-cell sticky-sku-cell-r font-weight-bold" data-peserta="<?= $pid ?>">
                                                        <div class="progress" style="height: 20px; min-width: 100px;">
                                                            <div class="progress-bar <?= $inf['percentage'] >= 100 ? 'bg-success' : 'bg-primary' ?>" 
                                                                 role="progressbar" 
                                                                 style="width: <?= $inf['percentage'] ?>%;" 
                                                                 aria-valuenow="<?= $inf['percentage'] ?>" 
                                                                 aria-valuemin="0" 
                                                                 aria-valuemax="100">
                                                                <?= $inf['percentage'] ?>%
                                                            </div>
                                                        </div>
                                                        <small class="text-muted"><?= $inf['done'] ?> / <?= $inf['total'] ?> butir</small>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<?php if ($can_manage_sku): ?>
<div class="modal fade" id="modalSkuAdd" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tambah kolom SKU</h5><button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body"><textarea class="form-control" id="teks_butir_baru" rows="4" placeholder="Contoh: Hafal rukun iman"></textarea></div>
            <div class="modal-footer">
                <button class="btn btn-secondary" type="button" data-dismiss="modal">Batal</button>
                <button class="btn btn-primary" type="button" id="btnSkuSaveButir">Simpan</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalSkuEdit" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ubah kolom SKU</h5><button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="sku_edit_butir_id" value="">
                <textarea class="form-control" id="sku_edit_butir_text" rows="5" maxlength="500" placeholder="Teks butir SKU"></textarea>
                <small class="text-muted">Maks. 500 karakter.</small>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" type="button" data-dismiss="modal">Batal</button>
                <button class="btn btn-primary" type="button" id="btnSkuUpdateButir">Simpan perubahan</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalSkuImport" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="post" enctype="multipart/form-data" action="" id="skuImportForm">
            <input type="hidden" name="import_sku_butir" value="1">
            <input type="hidden" name="id_tingkat_barung" value="<?= (int)$selected_tingkat_id ?>">
            <div class="modal-header">
                <h5 class="modal-title">Import butir SKU</h5><button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <label>Excel (.xlsx, .xls) atau CSV / TXT</label>
                <input type="file" name="file_import" accept=".csv,.txt,.xlsx,.xls" required class="form-control-file">
                <small class="form-text text-muted">Import membaca <strong>baris pertama</strong> sebagai judul kolom butir SKU. Template Excel (.xlsx) unduhan hanya berisi contoh butir per kolom; kolom No/Nama/Status (jika ada) diabaikan.</small>
                <div class="mt-3 d-none" id="skuImportProgressWrap">
                    <div class="progress" style="height:18px;">
                        <div id="skuImportProgressBar" class="progress-bar progress-bar-striped progress-bar-animated"
                             role="progressbar" style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                    </div>
                    <small class="text-muted d-block mt-2" id="skuImportStatus">Menunggu upload...</small>
                </div>
            </div>
            <div class="modal-footer">
                <a class="btn btn-outline-primary" href="syarat_kecakapan_umum.php?download_template_sku=1">
                    <i class="fas fa-download"></i> Unduh Template
                </a>
                <button class="btn btn-secondary" type="button" data-dismiss="modal" id="skuImportCloseBtn">Batal</button>
                <button class="btn btn-primary" type="submit" id="skuImportSubmitBtn"><i class="fas fa-upload"></i> Unggah</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
/* SKU Mobile Tabs Navigation (Pill Segments) */
.sku-tingkat-tabs-wrapper {
    width: 100%;
    overflow: hidden;
}
.sku-tingkat-tabs-scroll {
    display: flex;
    align-items: center;
    gap: 10px;
    overflow-x: auto;
    padding-bottom: 6px;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
}
.sku-tingkat-tabs-scroll::-webkit-scrollbar {
    height: 4px;
}
.sku-tingkat-tabs-scroll::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 999px;
}
.sku-tab-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    flex: 0 0 auto;
    padding: 8px 16px;
    border-radius: 999px !important;
    font-size: 0.88rem;
    font-weight: 700;
    text-decoration: none !important;
    white-space: nowrap;
    outline: none !important;
    transition: all .2s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
}
.sku-tab-pill:focus,
.sku-tab-pill:active {
    outline: none !important;
    border-radius: 999px !important;
}
.sku-tab-icon {
    font-size: 0.82rem;
}
.sku-tab-count {
    background: rgba(0, 0, 0, 0.07);
    padding: 2px 7px;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 800;
}

/* Siaga Pill Style (Green Tone) */
.sku-tab-siaga {
    background: #ffffff;
    color: #15803d;
    border: 1.5px solid #bbf7d0;
}
.sku-tab-siaga:hover {
    background: #f0fdf4;
    color: #166534;
    border-color: #86efac;
    transform: translateY(-1px);
}
.sku-tab-siaga.active,
.sku-tab-siaga.active span,
.sku-tab-siaga.active i {
    background: linear-gradient(135deg, #16a34a, #15803d) !important;
    color: #ffffff !important;
    border-color: #15803d !important;
    box-shadow: 0 4px 12px rgba(22, 163, 74, 0.3) !important;
}
.sku-tab-siaga.active .sku-tab-count {
    background: rgba(255, 255, 255, 0.28) !important;
    color: #ffffff !important;
}

/* Penggalang Pill Style (Red Tone) */
.sku-tab-penggalang {
    background: #ffffff;
    color: #be123c;
    border: 1.5px solid #fecdd3;
}
.sku-tab-penggalang:hover {
    background: #fff1f2;
    color: #9f1239;
    border-color: #fda4af;
    transform: translateY(-1px);
}
.sku-tab-penggalang.active,
.sku-tab-penggalang.active span,
.sku-tab-penggalang.active i {
    background: linear-gradient(135deg, #e11d48, #be123c) !important;
    color: #ffffff !important;
    border-color: #be123c !important;
    box-shadow: 0 4px 12px rgba(225, 29, 72, 0.3) !important;
}
.sku-tab-penggalang.active .sku-tab-count {
    background: rgba(255, 255, 255, 0.28) !important;
    color: #ffffff !important;
}

/* Summary Badge */
.sku-summary-badge {
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    padding: 6px 14px;
    font-size: 0.82rem;
    font-weight: 600;
    color: #475569;
    display: inline-flex;
    align-items: center;
}

/* Nomor Urut Siswa */
.sku-student-no {
    width: 28px;
    height: 28px;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    color: #475569;
    font-size: 0.78rem;
    font-weight: 800;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

/* SKU Mobile App Cards Design */
.sku-student-card {
    border-radius: 20px !important;
    border: 1px solid #edf2f7 !important;
    background: #ffffff !important;
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.05) !important;
    transition: transform .2s cubic-bezier(0.4, 0, 0.2, 1), box-shadow .2s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}
.sku-student-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 5px;
    height: 100%;
    background: linear-gradient(180deg, #6366f1, #3b82f6);
    border-top-left-radius: 20px;
    border-bottom-left-radius: 20px;
}
.sku-student-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.09) !important;
}

/* Avatar Styling Mobile-App */
.sku-avatar-wrap {
    width: 54px;
    height: 54px;
    border-radius: 18px;
    overflow: hidden;
    position: relative;
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.18);
    background: #f1f5f9;
}
.sku-avatar-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 18px;
}
.sku-avatar-initials {
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, #6366f1 0%, #3b82f6 50%, #06b6d4 100%);
    color: #ffffff;
    font-size: 1.35rem;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 18px;
    text-transform: uppercase;
    letter-spacing: -0.5px;
    box-shadow: inset 0 2px 4px rgba(255, 255, 255, 0.3);
}

.sku-student-name {
    font-size: 1.1rem;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.3px;
    line-height: 1.3;
}
.sku-student-meta {
    font-size: 0.82rem;
    color: #64748b;
    font-weight: 600;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 4px;
}
.meta-dot {
    color: #cbd5e1;
    font-size: 0.7rem;
}

/* Card header layout (mobile-app friendly, no overlap) */
.sku-card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
}
.sku-card-left {
    display: flex;
    align-items: center;
    flex: 1 1 220px;
    min-width: 0;
}
.sku-card-id {
    min-width: 0;
}
.sku-card-id .sku-student-name {
    overflow-wrap: anywhere;
}
.sku-card-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 6px;
    flex: 0 0 auto;
}
@media (max-width: 575.98px) {
    .sku-card-right {
        flex-direction: row;
        flex-wrap: wrap;
        align-items: center;
        justify-content: flex-start;
        width: 100%;
        margin-top: 10px;
    }
    .sku-student-name {
        font-size: 1rem;
    }
    .sku-avatar-wrap {
        width: 48px;
        height: 48px;
        border-radius: 15px;
    }
    .sku-avatar-initials,
    .sku-avatar-img {
        border-radius: 15px;
    }
}

/* Modern App Status Badges */
.sku-app-badge {
    display: inline-flex;
    align-items: center;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 800;
    letter-spacing: 0.4px;
    text-transform: uppercase;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
}
.sku-badge-lulus {
    background: linear-gradient(135deg, #ecfdf5, #d1fae5) !important;
    color: #047857 !important;
    border: 1.5px solid #a7f3d0 !important;
}
.sku-badge-proses {
    background: linear-gradient(135deg, #fff1f2, #ffe4e6) !important;
    color: #be123c !important;
    border: 1.5px solid #fecdd3 !important;
}

.sku-pct-pill {
    font-size: 0.82rem;
    color: #0f172a;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    padding: 3px 12px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
}

.sku-progress-track {
    height: 9px !important;
    background-color: #f1f5f9;
    border-radius: 999px;
    overflow: hidden;
    box-shadow: inset 0 1px 2px rgba(0,0,0,0.06);
}
.sku-progress-track .progress-bar {
    border-radius: 999px;
    background: linear-gradient(90deg, #6366f1, #3b82f6) !important;
}
.sku-progress-track .progress-bar.bg-success {
    background: linear-gradient(90deg, #10b981, #059669) !important;
}

/* Mobile App Expand Toggle Button */
.sku-app-btn-toggle {
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    border-radius: 14px;
    color: #1e293b;
    font-size: 0.9rem;
    font-weight: 700;
    padding: 10px 16px;
    transition: all .2s ease;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.02);
}
.sku-app-btn-toggle:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
    color: #0f172a;
    box-shadow: 0 4px 10px rgba(15, 23, 42, 0.05);
}
.sku-icon-circle {
    width: 28px;
    height: 28px;
    background: linear-gradient(135deg, #e0e7ff, #c7d2fe);
    color: #4338ca;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.82rem;
    box-shadow: 0 2px 4px rgba(79, 70, 229, 0.15);
}

/* Checklist Items inside Card */
.sku-butir-list {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 10px;
}
.sku-item-row {
    background: #ffffff;
    border: 1.5px solid #edf2f7;
    border-radius: 14px;
    transition: all .2s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 2px 5px rgba(15, 23, 42, 0.03);
    margin-bottom: 8px;
    gap: 10px;
}
.sku-date-wrapper {
    width: 100%;
}
.sku-date-wrapper .input-group {
    width: 100%;
}
@media (min-width: 576px) {
    .sku-date-wrapper {
        width: auto;
    }
    .sku-date-wrapper .input-group {
        width: auto;
    }
}
.sku-item-row:hover {
    border-color: #cbd5e1;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
    transform: translateY(-1px);
}
.sku-item-row.sku-item-completed {
    background: linear-gradient(to right, #f0fdf4, #ffffff) !important;
    border-color: #86efac !important;
}

.sku-num-pill {
    background: linear-gradient(135deg, #e0e7ff, #c7d2fe);
    color: #3730a3;
    font-size: 0.78rem;
    font-weight: 800;
    padding: 3px 8px;
    border-radius: 8px;
    margin-right: 8px;
    display: inline-block;
}
.font-weight-500 {
    font-weight: 550;
    color: #0f172a;
}

/* Custom Checkbox App Style */
.custom-checkbox .custom-control-input:checked ~ .custom-control-label::before {
    background-color: #10b981 !important;
    border-color: #10b981 !important;
    box-shadow: 0 2px 6px rgba(16, 185, 129, 0.35) !important;
}
.custom-checkbox .custom-control-label::before {
    border-radius: 6px !important;
    width: 20px;
    height: 20px;
    border: 2px solid #cbd5e1;
}
.custom-checkbox .custom-control-label::after {
    width: 20px;
    height: 20px;
}

/* Mobile App Quick Action Buttons (Hari Ini) */
.btn-today {
    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%) !important;
    color: #ffffff !important;
    font-size: 0.75rem !important;
    font-weight: 700 !important;
    padding: 6px 14px !important;
    border: none !important;
    border-top-right-radius: 10px !important;
    border-bottom-right-radius: 10px !important;
    box-shadow: 0 2px 8px rgba(79, 70, 229, 0.3) !important;
    transition: all .15s ease;
    display: inline-flex;
    align-items: center;
    letter-spacing: .2px;
}
.btn-today:hover, .btn-today:active {
    background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%) !important;
    transform: scale(0.97);
    color: #ffffff !important;
}
.sku-date-wrapper .input-group {
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.05);
    border-radius: 10px;
}
.sku-date-wrapper .form-control.sku-date {
    border-color: #e2e8f0;
    font-size: 0.85rem;
    font-weight: 600;
    color: #0f172a;
    background: #fff;
    height: calc(1.5em + .75rem + 4px);
    border-top-left-radius: 10px !important;
    border-bottom-left-radius: 10px !important;
    border-top-right-radius: 0 !important;
    border-bottom-right-radius: 0 !important;
}
.sku-date-wrapper .form-control.sku-date:last-child {
    border-top-right-radius: 10px !important;
    border-bottom-right-radius: 10px !important;
}
.sku-date-wrapper .form-control.sku-date::-webkit-calendar-picker-indicator {
    cursor: pointer;
    opacity: .5;
    padding: 2px;
}
.sku-date-wrapper .form-control.sku-date::-webkit-calendar-picker-indicator:hover {
    opacity: .9;
}
.sku-date-wrapper .input-group-text {
    background: #f8fafc;
    border-color: #e2e8f0;
    color: #6366f1;
    border-top-left-radius: 10px;
    border-bottom-left-radius: 10px;
}

/* View Switch Button Group */
.sku-view-switch .btn {
    border-radius: 8px !important;
    padding: 6px 14px;
    font-weight: 700;
    letter-spacing: .2px;
}

/* Reset and Base Styles for SKU Table */
.sku-table-wrap {
    overflow: auto !important;
    position: relative;
    -webkit-overflow-scrolling: touch;
    max-height: 650px;
    border: 1px solid #dee2e6; /* Border luar container */
}

.sku-main-table {
    min-width: 100%;
    border-collapse: separate !important;
    border-spacing: 0;
    table-layout: auto;
    border: none;
}

/* Base border style for all cells */
.sku-main-table th,
.sku-main-table td {
    border-right: 1px solid #dee2e6 !important;
    border-bottom: 1px solid #dee2e6 !important;
    padding: 10px 8px !important;
    background-color: #fff;
}

/* Vertical Sticky Header */
.sku-thead-stick th {
    position: -webkit-sticky !important;
    position: sticky !important;
    background-color: #f8f9fa !important;
    z-index: 100 !important;
    top: 0;
    /* Gunakan box-shadow untuk border agar tidak ada celah saat scroll */
    box-shadow: inset 0 -1px 0 #dee2e6, inset -1px 0 0 #dee2e6;
    border: none !important;
    vertical-align: middle !important; /* Agar teks di tengah secara vertikal */
    text-align: center; /* Default center untuk header */
}

/* Row 1 Sticky Offsets */
.sku-thead-stick tr:nth-child(1) th {
    top: 0;
    z-index: 110 !important;
}
/* Row 2 Sticky Offsets (Nomor butir) */
.sku-thead-stick tr:nth-child(2) th {
    top: 41px; /* Disesuaikan agar rapat */
    z-index: 105 !important;
}
/* Row 3 Sticky Offsets (Teks vertikal) */
.sku-thead-stick tr:nth-child(3) th {
    top: 73px; /* Disesuaikan agar rapat */
    z-index: 104 !important;
}

/* Horizontal Sticky - Left (No & Nama) */
.sticky-sku-cell {
    position: -webkit-sticky !important;
    position: sticky !important;
    left: 0 !important;
    z-index: 90 !important;
    background-color: #fff !important;
    box-shadow: inset -1px 0 0 #dee2e6, inset 0 -1px 0 #dee2e6;
    border: none !important;
}

/* Intersection: Top-Left Corner (Header NO & NAMA) */
.sku-thead-stick th.sticky-sku-cell {
    z-index: 150 !important;
    background-color: #f8f9fa !important;
    box-shadow: inset -1px 0 0 #dee2e6, inset 0 -1px 0 #dee2e6, inset 0 1px 0 #dee2e6;
}

/* No Column */
.sku-th-no, .sku-row-no {
    left: 0 !important;
    min-width: 50px;
    width: 50px;
}

/* Nama Column */
.sku-th-nama, .sku-td-nama {
    left: 50px !important;
    min-width: 200px;
    width: 200px;
    /* Beri border kanan lebih tebal sedikit sebagai visual separator tanpa box-shadow blur */
    box-shadow: inset -2px 0 0 #dee2e6, inset 0 -1px 0 #dee2e6;
}

/* Horizontal Sticky - Right (Status & Pct) */
@media (min-width: 992px) {
    .sticky-sku-cell-r {
        position: -webkit-sticky !important;
        position: sticky !important;
        right: 0 !important;
        z-index: 90 !important;
        background-color: #f8f9fa !important;
        box-shadow: inset 1px 0 0 #dee2e6, inset 0 -1px 0 #dee2e6;
        border: none !important;
    }
    .sticky-sku-cell-r-2 {
        position: -webkit-sticky !important;
        position: sticky !important;
        right: 120px !important;
        z-index: 90 !important;
        background-color: #f8f9fa !important;
        box-shadow: inset 1px 0 0 #dee2e6, inset 0 -1px 0 #dee2e6;
        border: none !important;
    }
    .sku-thead-stick th.sticky-sku-cell-r,
    .sku-thead-stick th.sticky-sku-cell-r-2 {
        z-index: 150 !important;
        box-shadow: inset 1px 0 0 #dee2e6, inset 0 -1px 0 #dee2e6, inset 0 1px 0 #dee2e6;
    }
}

/* Teks Vertikal Header */
.sku-vtext {
    writing-mode: vertical-rl;
    transform: rotate(180deg);
    display: inline-block;
    max-height: 150px;
    min-height: 120px;
    overflow: hidden;
    font-size: .8rem;
    line-height: 1.1;
    padding: 10px 2px;
    text-align: left;
}

/* Mobile Adjustments */
@media (max-width: 991.98px) {
    .sku-table-wrap {
        margin: 0 -15px;
        max-height: 500px;
    }
    .sku-main-table {
        min-width: 850px;
    }
    .sku-th-nama, .sku-td-nama {
        min-width: 150px;
        width: 150px;
    }
    .sticky-sku-cell-r, .sticky-sku-cell-r-2 {
        position: static !important;
        box-shadow: none !important;
        border-right: 1px solid #dee2e6 !important;
        border-bottom: 1px solid #dee2e6 !important;
    }
}

/* Stisla Admin Fix */
.main-content, .section, .section-body, .card, .card-body {
    overflow: visible !important;
}
</style>

:target {
    scroll-margin-top: 150px;
}
</style>

<?php require_once '../templates/footer.php'; ?>
