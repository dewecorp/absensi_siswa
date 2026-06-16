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
            if ($up->rowCount() === 0 && $tgl !== '') {
                $pdo->prepare('INSERT INTO tb_sku_kecakapan_nilai (id_peserta_didik_barung, id_butir, tanggal_ujian) VALUES (?, ?, ?)')
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
            SELECT id_peserta_didik_barung, nama_peserta_didik FROM tb_peserta_didik_barung
            WHERE id_tingkat_barung = ? AND IFNULL(status, \'aktif\') = \'aktif\'
            ORDER BY nama_peserta_didik ASC
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
    'tempat_surat' => '',
    'tanggal_surat' => date('Y-m-d'),
];
try {
    $stSkuPrint = $pdo->query("SELECT ketua_gudep, nta_ketua_gudep, tempat_surat, tanggal_surat FROM tb_pengaturan_cetak_barung LIMIT 1");
    $rwSkuPrint = $stSkuPrint ? $stSkuPrint->fetch(PDO::FETCH_ASSOC) : null;
    if (is_array($rwSkuPrint)) {
        $sku_print_settings['ketua_gudep'] = (string)($rwSkuPrint['ketua_gudep'] ?? '');
        $sku_print_settings['nta_ketua_gudep'] = (string)($rwSkuPrint['nta_ketua_gudep'] ?? '');
        $sku_print_settings['tempat_surat'] = (string)($rwSkuPrint['tempat_surat'] ?? '');
        $sku_print_settings['tanggal_surat'] = (string)($rwSkuPrint['tanggal_surat'] ?? date('Y-m-d'));
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
            $school_year_print = (string)($school_profile['tahun_ajaran'] ?? '-');
            $school_logo = !empty($school_profile['logo']) ? ('../assets/img/' . $school_profile['logo']) : '';
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
        .header { display: flex; align-items: center; gap: 10px; border-bottom: 2px solid #333; padding-bottom: 8px; margin-bottom: 10px; }
        .header-logo { width: 56px; height: 56px; object-fit: contain; }
        .header-title h2 { margin: 0; font-size: 20px; text-align: left; }
        .header-title .meta { margin-top: 3px; color: #444; font-size: 13px; text-align: left; }
        h3 { margin: 10px 0 6px 0; }
        .meta { margin-bottom: 8px; color: #555; font-size: 12px; text-align: center; }
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
        <div class="header">
            <?php if ($school_logo): ?>
                <img class="header-logo" src="<?= htmlspecialchars($school_logo, ENT_QUOTES, 'UTF-8') ?>?v=<?= htmlspecialchars($asset_ver, ENT_QUOTES, 'UTF-8') ?>" alt="Logo Sekolah">
            <?php endif; ?>
            <div class="header-title">
                <h2><?= htmlspecialchars($school_name_print, ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="meta">Tahun Ajaran: <strong><?= htmlspecialchars($school_year_print, ENT_QUOTES, 'UTF-8') ?></strong></div>
            </div>
        </div>
        <h3>Rekap Syarat Kecakapan Umum</h3>
        <div class="meta">Tingkat: <strong><?= htmlspecialchars($selected_tingkat_name !== '' ? $selected_tingkat_name : 'Tingkat', ENT_QUOTES, 'UTF-8') ?></strong></div>
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
      $('.sku-status-cell[data-peserta="' + $cb.data('peserta') + '"]')
        .toggleClass('text-success', !!resp.status_ok).toggleClass('text-danger', !resp.status_ok)
        .text(resp.status_label || '—');
      
      if (typeof resp.percentage !== 'undefined') {
        var $pctCell = $('.sku-pct-cell[data-peserta="' + $cb.data('peserta') + '"]');
        var $bar = $pctCell.find('.progress-bar');
        $bar.css('width', resp.percentage + '%').attr('aria-valuenow', resp.percentage).text(resp.percentage + '%');
        $bar.toggleClass('bg-success', resp.percentage >= 100).toggleClass('bg-primary', resp.percentage < 100);
        $pctCell.find('small').text(resp.done + ' / ' + resp.total + ' butir');
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
      if (resp.checked) $('.sku-check' + key).prop('checked', true);
      $('.sku-status-cell[data-peserta="' + peserta + '"]')
        .toggleClass('text-success', !!resp.status_ok).toggleClass('text-danger', !resp.status_ok)
        .text(resp.status_label || '—');
      
      if (typeof resp.percentage !== 'undefined') {
        var $pctCell = $('.sku-pct-cell[data-peserta="' + peserta + '"]');
        var $bar = $pctCell.find('.progress-bar');
        $bar.css('width', resp.percentage + '%').attr('aria-valuenow', resp.percentage).text(resp.percentage + '%');
        $bar.toggleClass('bg-success', resp.percentage >= 100).toggleClass('bg-primary', resp.percentage < 100);
        $pctCell.find('small').text(resp.done + ' / ' + resp.total + ' butir');
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

  var $filterNama = $('#skuFilterNama');
  if ($filterNama.length) {
    function skuApplyNamaFilter() {
      var q = ($filterNama.val() || '').trim().toLowerCase();
      var $rows = $('#skuMainTable tbody tr.sku-data-row');
      var seq = 1;
      $rows.each(function() {
        var $tr = $(this);
        var nama = String($tr.attr('data-nama-normal') || '');
        var show = !q || nama.indexOf(q) !== -1;
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
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <h4 class="mb-0">
                        SKU Pramuka
                        <?= $selected_tingkat_name !== '' ? '<span class="badge badge-light border text-dark ml-2">' . htmlspecialchars($selected_tingkat_name) . '</span>' : '' ?>
                    </h4>
                    <div class="d-flex flex-wrap gap-2">
                        <?php if ($can_manage_sku): ?>
                        <button type="button" class="btn btn-outline-primary btn-sm" data-toggle="modal" data-target="#modalSkuAdd"
                            <?= $selected_tingkat_id <= 0 ? 'disabled' : '' ?>><i class="fas fa-plus"></i> Tambah kolom</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-toggle="modal" data-target="#modalSkuImport"
                            <?= $selected_tingkat_id <= 0 ? 'disabled' : '' ?>><i class="fas fa-file-import"></i> Import SKU</button>
                        <?php endif; ?>
                        <a class="btn btn-outline-success btn-sm <?= $selected_tingkat_id <= 0 ? 'disabled text-muted' : '' ?>"
                            href="<?= $selected_tingkat_id > 0 ? 'syarat_kecakapan_umum.php?tingkat=' . (int)$selected_tingkat_id . '&export=xlsx' : '#' ?>">
                            <i class="fas fa-file-excel"></i> Ekspor Excel</a>
                        <a class="btn btn-outline-danger btn-sm <?= $selected_tingkat_id <= 0 ? 'disabled text-muted' : '' ?>"
                            <?= $selected_tingkat_id > 0 ? 'target="_blank" rel="noopener noreferrer"' : '' ?>
                            href="<?= $selected_tingkat_id > 0 ? 'syarat_kecakapan_umum.php?tingkat=' . (int)$selected_tingkat_id . '&export=pdf' : '#' ?>">
                            <i class="fas fa-file-pdf"></i> Ekspor PDF</a>
                    </div>
                </div>
                <div class="card-body">
                    <span id="skuTingkatId" data-tid="<?= (int)$selected_tingkat_id ?>" class="d-none"></span>

                    <?php if (empty($tingkat_list)): ?>
                        <div class="alert alert-warning mb-0">Belum ada data tingkat Pramuka. Tambahkan di menu <strong>Data Tingkat Barung</strong>.</div>
                    <?php else: ?>
                        <?php if ($can_manage_sku || count($assigned_tingkat_ids) > 1): ?>
                        <ul class="nav nav-pills flex-wrap mb-4" role="tablist">
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
                                    if ($golongan === 'Penggalang') {
                                        $pill_class = $active ? 'nav-link active bg-danger border-danger' : 'nav-link border border-danger text-danger';
                                    } else {
                                        $pill_class = $active ? 'nav-link active bg-success border-success' : 'nav-link border border-success text-success';
                                    }
                                    $is_first = $index === 0;
                                    $is_last = $index === count($filtered_tingkat) - 1;
                                ?>
                                <li class="nav-item" style="margin: 0;">
                                    <a href="?tingkat=<?= $tid ?>" 
                                       class="nav-link py-1 px-3 <?= $pill_class ?>" 
                                       role="tab" 
                                       style="transition: none; <?= !$is_first ? 'border-left: 0; margin-left: -1px;' : '' ?> <?= !$is_last ? 'border-right: 0;' : '' ?> border-radius: 0;<?= $is_first ? ' border-top-left-radius: 4px; border-bottom-left-radius: 4px;' : '' ?><?= $is_last ? ' border-top-right-radius: 4px; border-bottom-right-radius: 4px;' : '' ?>">
                                        <?= htmlspecialchars((string)($t['nama_tingkat'] ?? '')) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>

                        <?php if ($sku_assignment_missing): ?>
                            <div class="alert alert-warning py-2">
                                Tingkat ampuan pembina belum diatur. Silakan set kolom <strong>Pembina Tingkat</strong> pada menu <strong>Data Pembina Pramuka</strong>.
                            </div>
                        <?php endif; ?>

                        <?php if ($selected_tingkat_id > 0): ?>
                            <?php if (!empty($peserta_rows)): ?>
                                <div class="form-row align-items-center mb-3">
                                    <div class="col-auto">
                                        <label for="skuFilterNama" class="sr-only">Cari nama peserta didik</label>
                                        <div class="input-group input-group-sm sku-name-filter">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                            </div>
                                            <input type="search" id="skuFilterNama" class="form-control"
                                                   placeholder="Cari nama peserta didik…" autocomplete="off">
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="sku-table-wrap mb-3">
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
                                                    $nomBaris = $nom;
                                                ?>
                                                <tr class="sku-data-row" data-nama-normal="<?= htmlspecialchars($namaNorm, ENT_QUOTES, 'UTF-8') ?>"
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
