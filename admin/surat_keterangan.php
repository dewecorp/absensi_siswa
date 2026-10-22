<?php
// Suppress deprecated warnings for PHP 8.1+
error_reporting(E_ALL & ~E_DEPRECATED);

require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

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
                $ng = trim((string)$stG->fetchColumn());
                if ($ng !== '') {
                    $candidateNames[] = $ng;
                }
            } catch (Exception $e) {
            }
        }

        $normalizedCandidates = [];
        foreach ($candidateNames as $cn) {
            $norm = normalize_person_name_for_match($cn);
            if ($norm !== '') {
                $normalizedCandidates[] = $norm;
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

$can_manage_suket = isAuthorized(['admin', 'tata_usaha']);
$is_pembina_pramuka_user = is_current_guru_pembina_pramuka($pdo);
$can_view_suket = $can_manage_suket || ($is_pembina_pramuka_user && isAuthorized(['guru', 'wali']));

if (!$can_view_suket) {
    redirect('../login.php');
}

// Session type query preservation
$session_type = trim((string)($_GET['session_type'] ?? ''));
if ($session_type === '' && !$can_manage_suket) {
    $session_type = (string)($_SESSION['level'] ?? 'guru');
}
$session_type_param = $session_type !== '' ? ('&session_type=' . urlencode($session_type)) : '';

// Resolusi tingkat yang dibina jika pembina pramuka (non-admin)
$assigned_tingkat_ids = [];
$suket_assignment_missing = false;
if (!$can_manage_suket) {
    $idGuruSuket = 0;
    if (isset($_SESSION['user_id'])) {
        $idGuruSuket = (int)$_SESSION['user_id'];
        if (isset($_SESSION['login_source']) && $_SESSION['login_source'] === 'tb_pengguna') {
            try {
                $st = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ? LIMIT 1");
                $st->execute([$idGuruSuket]);
                $idGuruSuket = (int)($st->fetchColumn() ?: 0);
            } catch (Exception $e) {
                $idGuruSuket = 0;
            }
        }
    }

    $id_pembina_suket = 0;
    if ($idGuruSuket > 0) {
        $stPb = $pdo->prepare("SELECT id_pembina_pramuka FROM tb_pembina_pramuka WHERE id_guru = ? LIMIT 1");
        $stPb->execute([$idGuruSuket]);
        $id_pembina_suket = (int)($stPb->fetchColumn() ?: 0);
    }
    if ($id_pembina_suket <= 0) {
        $cands = [(string)($_SESSION['nama'] ?? ''), (string)($_SESSION['nama_guru'] ?? ''), (string)($_SESSION['username'] ?? '')];
        $pbRows = $pdo->query("SELECT id_pembina_pramuka, nama_pembina FROM tb_pembina_pramuka")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cands as $c) {
            $cn = normalize_person_name_for_match($c);
            if ($cn === '') continue;
            foreach ($pbRows as $pbr) {
                $pbn = normalize_person_name_for_match($pbr['nama_pembina'] ?? '');
                if ($pbn !== '' && ($pbn === $cn || strpos($pbn, $cn) !== false || strpos($cn, $pbn) !== false)) {
                    $id_pembina_suket = (int)$pbr['id_pembina_pramuka'];
                    break 2;
                }
            }
        }
    }

    if ($id_pembina_suket > 0) {
        try {
            $stAssign = $pdo->prepare("SELECT id_tingkat_barung FROM tb_pembina_tingkat WHERE id_pembina_pramuka = ?");
            $stAssign->execute([$id_pembina_suket]);
            $assigned_tingkat_ids = array_map('intval', $stAssign->fetchAll(PDO::FETCH_COLUMN));
        } catch (Exception $e) {
            $assigned_tingkat_ids = [];
        }
        if (empty($assigned_tingkat_ids)) {
            $stLegacy = $pdo->prepare("SELECT id_tingkat_barung FROM tb_pembina_pramuka WHERE id_pembina_pramuka = ? LIMIT 1");
            $stLegacy->execute([$id_pembina_suket]);
            $singleT = (int)$stLegacy->fetchColumn();
            if ($singleT > 0) {
                $assigned_tingkat_ids = [$singleT];
            }
        }
    }

    if (empty($assigned_tingkat_ids)) {
        $suket_assignment_missing = true;
    }
}

if (!$can_manage_suket && $suket_assignment_missing) {
    // Tidak ada tingkatan yang dibina
    $page_title = 'Surat Keterangan';
    include '../templates/header.php';
    include '../templates/sidebar.php';
    echo '<div class="main-content"><section class="section"><div class="section-header"><h1>Surat Keterangan</h1></div>';
    echo '<div class="section-body"><div class="alert alert-warning"><i class="fas fa-exclamation-triangle mr-2"></i>Anda terdaftar sebagai Pembina Pramuka tetapi belum ditugaskan pada tingkatan mana pun. Silakan hubungi Admin.</div></div></section></div>';
    include '../templates/footer.php';
    exit;
}

// Aksi batalkan kelulusan: kembali ke tingkat sebelumnya + kosongkan SKU lama
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['suket_ajax'] ?? '') === '1' && ($_POST['action'] ?? '') === 'batalkan') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id_p = (int)($_POST['id_peserta_didik_barung'] ?? 0);
        if ($id_p <= 0) {
            echo json_encode(['ok' => false, 'msg' => 'ID peserta tidak valid.']);
            exit;
        }
        $st = $pdo->prepare("SELECT id_peserta_didik_barung, nama_peserta_didik, id_tingkat_barung, promoted_from_tingkat_id, promoted_at, sku_kecakapan_lulus_at FROM tb_peserta_didik_barung WHERE id_peserta_didik_barung = ? LIMIT 1");
        $st->execute([$id_p]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            echo json_encode(['ok' => false, 'msg' => 'Data peserta tidak ditemukan.']);
            exit;
        }
        $cur_tingkat = (int)($cur['id_tingkat_barung'] ?? 0);

        if (!$can_manage_suket && !empty($assigned_tingkat_ids) && !in_array($cur_tingkat, $assigned_tingkat_ids)) {
            echo json_encode(['ok' => false, 'msg' => 'Anda tidak memiliki hak akses untuk tingkatan ini.']);
            exit;
        }

        // Tentukan tingkat asal: prioritas promoted_from, fallback tingkat sebelumnya di urutan
        $asal_tingkat = (int)($cur['promoted_from_tingkat_id'] ?? 0);
        $ordered = $pdo->query("
            SELECT id_tingkat_barung FROM tb_tingkat_barung
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
        $ids = array_values(array_map('intval', is_array($ordered) ? $ordered : []));
        $pos = array_search($cur_tingkat, $ids, true);
        if ($asal_tingkat <= 0 && $pos !== false && $pos > 0) {
            $asal_tingkat = (int)($ids[$pos - 1] ?? 0);
        }
        if ($asal_tingkat <= 0) {
            echo json_encode(['ok' => false, 'msg' => 'Tingkat sebelumnya tidak ditemukan.']);
            exit;
        }

        // Kembalikan ke tingkat asal + reset penanda lulus/naik
        $pdo->prepare("
            UPDATE tb_peserta_didik_barung
            SET id_tingkat_barung = ?,
                promoted_from_tingkat_id = NULL,
                promoted_at = NULL,
                sku_kecakapan_lulus_at = NULL
            WHERE id_peserta_didik_barung = ?
            LIMIT 1
        ")->execute([$asal_tingkat, $id_p]);

        // Kosongkan centang SKU di tingkat asal (SKU sebelumnya menjadi kosong)
        try {
            $pdo->prepare("
                DELETE n FROM tb_sku_kecakapan_nilai n
                INNER JOIN tb_sku_kecakapan_butir b ON b.id_butir = n.id_butir AND b.id_tingkat_barung = ?
                WHERE n.id_peserta_didik_barung = ?
            ")->execute([$asal_tingkat, $id_p]);
        } catch (Exception $e) {
            // tabel nilai mungkin belum ada — abaikan, penanda sudah direset
        }

        $nm = $pdo->prepare("SELECT nama_tingkat FROM tb_tingkat_barung WHERE id_tingkat_barung = ? LIMIT 1");
        $nm->execute([$asal_tingkat]);
        $nama_asal = (string)($nm->fetchColumn() ?: ('ID ' . $asal_tingkat));
        logActivity($pdo, (string)($_SESSION['username'] ?? 'admin'), 'Batalkan Suket', 'Membatalkan kelulusan ' . ($cur['nama_peserta_didik'] ?? '') . ' kembali ke tingkat ' . $nama_asal);
        echo json_encode(['ok' => true, 'msg' => 'Dibatalkan. Kembali ke tingkat ' . $nama_asal . ' dan SKU dikosongkan.', 'tingkat_asal' => $nama_asal]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => 'Gagal membatalkan: ' . $e->getMessage()]);
        exit;
    }
}

// Ensure kolom kelulusan SKU ada (sumber data surat)
try {
    $required_cols = [
        'promoted_from_tingkat_id' => "INT NULL",
        'promoted_at' => "DATETIME NULL",
        'sku_kecakapan_lulus_at' => "DATETIME NULL",
        'status' => "ENUM('aktif','keluar') NOT NULL DEFAULT 'aktif'",
    ];
    foreach ($required_cols as $col => $typeDef) {
        $colStmt = $pdo->query("SHOW COLUMNS FROM tb_peserta_didik_barung LIKE '" . addslashes($col) . "'");
        $has_col = (bool)$colStmt->fetch(PDO::FETCH_ASSOC);
        if (!$has_col) {
            $pdo->exec("ALTER TABLE tb_peserta_didik_barung ADD COLUMN {$col} {$typeDef}");
        }
    }
} catch (Exception $e) {
    // best effort
}

$school_profile = getSchoolProfile($pdo);
$page_title = 'Surat Keterangan';

// === Tahun Ajaran filter logic ===
$current_tahun_ajaran = (string)($school_profile['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1));

// Build list of available academic years:
// Always include current TA + 1 year ahead + all past TAs that have records
$tahun_ajaran_set = [$current_tahun_ajaran];

// Add 1 year ahead dynamically
preg_match('/^(\d{4})\//', $current_tahun_ajaran, $_m);
$_cur_y = (int)($_m[1] ?? date('Y'));
$next_ta = ($_cur_y + 1) . '/' . ($_cur_y + 2);
if (!in_array($next_ta, $tahun_ajaran_set)) {
    $tahun_ajaran_set[] = $next_ta;
}

try {
    $ta_stmt = $pdo->query("
        SELECT DISTINCT
            IF(MONTH(COALESCE(sku_kecakapan_lulus_at, promoted_at)) >= 7,
               CONCAT(YEAR(COALESCE(sku_kecakapan_lulus_at, promoted_at)), '/', YEAR(COALESCE(sku_kecakapan_lulus_at, promoted_at)) + 1),
               CONCAT(YEAR(COALESCE(sku_kecakapan_lulus_at, promoted_at)) - 1, '/', YEAR(COALESCE(sku_kecakapan_lulus_at, promoted_at)))
            ) AS ta
        FROM tb_peserta_didik_barung
        WHERE sku_kecakapan_lulus_at IS NOT NULL OR promoted_at IS NOT NULL
    ");
    while ($row = $ta_stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['ta']) && !in_array($row['ta'], $tahun_ajaran_set)) {
            $tahun_ajaran_set[] = $row['ta'];
        }
    }
} catch (Exception $e) { /* ignore */ }

// Sort descending (newest first)
usort($tahun_ajaran_set, function ($a, $b) {
    $ya = (int)explode('/', $a)[0];
    $yb = (int)explode('/', $b)[0];
    return $yb - $ya;
});

$selected_tahun_ajaran = (string)($_GET['tahun_ajaran'] ?? $current_tahun_ajaran);
if (!in_array($selected_tahun_ajaran, $tahun_ajaran_set)) {
    $selected_tahun_ajaran = $current_tahun_ajaran;
}

// Derive date range for selected tahun ajaran (July y0 - June y0+1)
preg_match('/^(\d{4})\//', $selected_tahun_ajaran, $_tam);
$_ta_y0 = (int)($_tam[1] ?? date('Y'));
$ta_start_date = sprintf('%04d-07-01', $_ta_y0);
$ta_end_date   = sprintf('%04d-06-30', $_ta_y0 + 1);
$is_current_ta = ($selected_tahun_ajaran === $current_tahun_ajaran);


// Tabel tidak memakai DataTables — hindari ekstra parsing/heap setelah cetak/pemakaian lain

// Fetch semua tingkat dulu (untuk rantai kenaikan), lalu buat list tampilan tanpa Pra Mula
$all_tingkat_list = [];
$tingkat_list = [];
try {
    $all_tingkat_list = $pdo->query("
            SELECT id_tingkat_barung, nama_tingkat, golongan
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
        ")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // ignore
}

$isPraMula = static function (string $name): bool {
    $n = strtolower(trim($name));
    $k = strtolower(str_replace([' ', '-'], '', $n));
    // Blok semua tingkat yang berawalan "pra" (Pra Mula, Pra-..., dst.)
    return strpos($k, 'pra') === 0 || strpos($n, 'pra ') === 0 || strpos($n, 'pra-') === 0;
};
$isMula = static function (string $name): bool {
    $n = strtolower(trim($name));
    $k = strtolower(str_replace(' ', '', $n));
    return $n === 'mula' || $k === 'mula';
};

foreach ($all_tingkat_list as $t) {
    $nm = (string)($t['nama_tingkat'] ?? '');
    if (!$isPraMula($nm)) {
        if (!$can_manage_suket && !empty($assigned_tingkat_ids)) {
            if (in_array((int)($t['id_tingkat_barung'] ?? 0), $assigned_tingkat_ids, true)) {
                $tingkat_list[] = $t;
            }
        } else {
            $tingkat_list[] = $t;
        }
    }
}

$selected_tingkat_id = (int)($_GET['tingkat'] ?? 0);
// Jika URL masih membawa tingkat Pra Mula, fallback ke tingkat pertama yang tampil
$is_selected_visible = false;
foreach ($tingkat_list as $t) {
    if ((int)($t['id_tingkat_barung'] ?? 0) === $selected_tingkat_id) {
        $is_selected_visible = true;
        break;
    }
}
if (($selected_tingkat_id <= 0 || !$is_selected_visible) && !empty($tingkat_list)) {
    $selected_tingkat_id = (int)($tingkat_list[0]['id_tingkat_barung'] ?? 0);
}

// Resolve selected tingkat name
$selected_tingkat_name = '';
foreach ($tingkat_list as $t) {
    if ((int)($t['id_tingkat_barung'] ?? 0) === $selected_tingkat_id) {
        $selected_tingkat_name = (string)($t['nama_tingkat'] ?? '');
        break;
    }
}

// Tingkat sebelumnya untuk validasi kenaikan otomatis SKU
$prev_tingkat_id = 0;
if ($selected_tingkat_id > 0) {
    $idx_selected = null;
    foreach ($all_tingkat_list as $idx => $t) {
        if ((int)($t['id_tingkat_barung'] ?? 0) === $selected_tingkat_id) {
            $idx_selected = $idx;
            break;
        }
    }
    if ($idx_selected !== null && $idx_selected > 0) {
        $prev_tingkat_id = (int)($all_tingkat_list[$idx_selected - 1]['id_tingkat_barung'] ?? 0);
    }
}

// Fetch peserta didik for selected tingkat
$participants = [];
$suket_rows_sql = "
    SELECT id_peserta_didik_barung, nama_peserta_didik, nta, tempat_lahir, tanggal_lahir, foto, jenis_kelamin
    FROM (
        SELECT p.id_peserta_didik_barung, p.nama_peserta_didik, p.nta,
               s.foto, s.jenis_kelamin,
               COALESCE(NULLIF(TRIM(s.tempat_lahir), ''), NULLIF(TRIM(p.tempat_lahir), '')) AS tempat_lahir,
               COALESCE(
                 CASE
                   WHEN s.tanggal_lahir IS NULL THEN NULL
                   WHEN LEFT(s.tanggal_lahir, 10) = '0000-00-00' THEN NULL
                   WHEN CAST(LEFT(s.tanggal_lahir, 4) AS UNSIGNED) < 1900 THEN NULL
                   ELSE LEFT(s.tanggal_lahir, 10)
                 END,
                 CASE
                   WHEN p.tanggal_lahir IS NULL THEN NULL
                   WHEN LEFT(p.tanggal_lahir, 10) = '0000-00-00' THEN NULL
                   WHEN CAST(LEFT(p.tanggal_lahir, 4) AS UNSIGNED) < 1900 THEN NULL
                   ELSE LEFT(p.tanggal_lahir, 10)
                 END
               ) AS tanggal_lahir,
               ROW_NUMBER() OVER (
                 PARTITION BY COALESCE(NULLIF(p.id_siswa, 0), 0), COALESCE(NULLIF(TRIM(p.nta), ''), '')
                 ORDER BY CASE WHEN IFNULL(p.status, 'aktif') = 'aktif' THEN 0 ELSE 1 END, p.id_peserta_didik_barung ASC
               ) AS rn
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
        WHERE p.id_tingkat_barung = ?
          AND (
            (
                p.sku_kecakapan_lulus_at IS NOT NULL
                AND DATE(p.sku_kecakapan_lulus_at) BETWEEN ? AND ?
            )
            OR (
                p.promoted_at IS NOT NULL
                AND DATE(p.promoted_at) BETWEEN ? AND ?
                AND p.promoted_from_tingkat_id = ?
                AND ? > 0
            )
          )
    ) suket_rows
    WHERE rn = 1
    ORDER BY nama_peserta_didik ASC
";
if ($selected_tingkat_id > 0) {
    try {
        $stmt = $pdo->prepare($suket_rows_sql);
        $stmt->execute([
            $selected_tingkat_id,
            $ta_start_date, $ta_end_date,
            $ta_start_date, $ta_end_date,
            $prev_tingkat_id, $prev_tingkat_id,
        ]);
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // ignore
    }
}

// Jumlah surat per tingkat (badge pada tab)
$suket_counts = [];
foreach ($tingkat_list as $t_count) {
    $tid_count = (int)($t_count['id_tingkat_barung'] ?? 0);
    if ($tid_count <= 0) {
        continue;
    }
    $prev_count_id = 0;
    foreach ($all_tingkat_list as $idx_count => $at_count) {
        if ((int)($at_count['id_tingkat_barung'] ?? 0) === $tid_count) {
            if ($idx_count > 0) {
                $prev_count_id = (int)($all_tingkat_list[$idx_count - 1]['id_tingkat_barung'] ?? 0);
            }
            break;
        }
    }
    try {
        $stmt_count = $pdo->prepare($suket_rows_sql);
        $stmt_count->execute([
            $tid_count,
            $ta_start_date, $ta_end_date,
            $ta_start_date, $ta_end_date,
            $prev_count_id, $prev_count_id,
        ]);
        $suket_counts[$tid_count] = count($stmt_count->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        $suket_counts[$tid_count] = 0;
    }
}

$custom_script = '';

// Page-specific JS (rendered by templates/footer.php after libraries)
$js_print_letters = <<<'JS'
// Filter kartu berdasarkan nama / NTA
$(document).on('input', '#suketSearchInput', function () {
  var q = ($(this).val() || '').toLowerCase().trim();
  var shown = 0;
  $('#suketCardsContainer .suket-card-col').each(function () {
    var hay = String($(this).attr('data-search') || '');
    var ok = q === '' || hay.indexOf(q) !== -1;
    $(this).toggle(ok);
    if (ok) { shown++; }
  });
  $('#suketVisibleCount').text(shown);
});

// Print single letter — opens preview without auto-print to avoid browser freeze
function printSingleLetter(id, nama, nta) {
  if (!id) {
    Swal.fire('Error', 'ID peserta didik tidak valid.', 'error');
    return;
  }
  const tingkat = ($('#selectedTingkatId').val() || '').toString();
  const tahunAjaran = ($('#selectedTahunAjaran').val() || '').toString();
  const sessionType = ($('#sessionType').val() || '').toString();
  const sessionParam = sessionType ? `&session_type=${encodeURIComponent(sessionType)}` : '';
  const url = `print_surat_keterangan.php?mode=single&id=${encodeURIComponent(id)}&tingkat=${encodeURIComponent(tingkat)}&tahun_ajaran=${encodeURIComponent(tahunAjaran)}${sessionParam}`;
  window.open(url, '_blank');
}

// Print all letters
function printAllLetters() {
  const tingkat = ($('#selectedTingkatId').val() || '').toString();
  const tahunAjaran = ($('#selectedTahunAjaran').val() || '').toString();
  const sessionType = ($('#sessionType').val() || '').toString();
  const sessionParam = sessionType ? `&session_type=${encodeURIComponent(sessionType)}` : '';
  if (!tingkat) {
    Swal.fire('Peringatan', 'Pilih tingkat terlebih dahulu.', 'warning');
    return;
  }
  const url = `print_surat_keterangan.php?mode=all&tingkat=${encodeURIComponent(tingkat)}&tahun_ajaran=${encodeURIComponent(tahunAjaran)}${sessionParam}`;
  window.open(url, '_blank');
}

function exportPDF() {
  const tingkat = ($('#selectedTingkatId').val() || '').toString();
  const tahunAjaran = ($('#selectedTahunAjaran').val() || '').toString();
  const sessionType = ($('#sessionType').val() || '').toString();
  const sessionParam = sessionType ? `&session_type=${encodeURIComponent(sessionType)}` : '';
  if (!tingkat) {
    Swal.fire('Peringatan', 'Pilih tingkat terlebih dahulu.', 'warning');
    return;
  }
  const url = `export_surat_keterangan_pdf.php?tingkat=${encodeURIComponent(tingkat)}&tahun_ajaran=${encodeURIComponent(tahunAjaran)}${sessionParam}`;
  window.open(url, '_blank');
}

// Event listener for print buttons using data attributes
$(document).on('click', '.btn-print-single', function (e) {
  e.preventDefault();

  const id = $(this).data('id');
  const nama = $(this).data('nama');
  const nta = $(this).data('nta');

  if (!id || !nama) {
    Swal.fire('Error', 'Data peserta didik tidak lengkap!', 'error');
    console.error('Missing data:', { id, nama, nta });
    return;
  }

  printSingleLetter(id, nama, nta);
});

// Batalkan kelulusan: konfirmasi + info akibat, kembalikan tingkat + kosongkan SKU
$(document).on('click', '.btn-cancel-single', function (e) {
  e.preventDefault();
  const id = $(this).data('id');
  const nama = $(this).data('nama') || '-';
  if (!id) {
    Swal.fire('Error', 'ID peserta didik tidak valid.', 'error');
    return;
  }
  Swal.fire({
    title: 'Batalkan kelulusan?',
    html: 'Batalkan kelulusan <strong>' + $('<div>').text(nama).html() + '</strong>?<br><br>'
      + '<div style="text-align:left">Akibat pembatalan:<ul style="margin:6px 0 0 18px;padding:0">'
      + '<li>Siswa kembali ke <strong>tingkat sebelumnya</strong>.</li>'
      + '<li>Centang <strong>SKU sebelumnya dikosongkan</strong> dan harus diisi ulang.</li>'
      + '<li>Surat keterangan tingkat ini <strong>tidak berlaku lagi</strong>.</li>'
      + '</ul></div>',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#d33',
    cancelButtonColor: '#6c757d',
    confirmButtonText: 'Ya, Batalkan',
    cancelButtonText: 'Tidak'
  }).then((result) => {
    if (!result.isConfirmed) return;
    const btn = $(this);
    btn.prop('disabled', true);
    $.ajax({
      url: 'surat_keterangan.php',
      type: 'POST',
      data: { suket_ajax: '1', action: 'batalkan', id_peserta_didik_barung: id },
      dataType: 'json'
    }).done(function (res) {
      if (res && res.ok) {
        Swal.fire({ title: 'Dibatalkan', text: res.msg || 'Kelulusan dibatalkan.', icon: 'success', timer: 1800, timerProgressBar: true, showConfirmButton: false, allowOutsideClick: false }).then(() => location.reload(), () => location.reload());
        setTimeout(() => location.reload(), 1900);
      } else {
        Swal.fire('Gagal', (res && res.msg) || 'Gagal membatalkan.', 'error');
        btn.prop('disabled', false);
      }
    }).fail(function () {
      Swal.fire('Error', 'Terjadi kesalahan server.', 'error');
      btn.prop('disabled', false);
    });
  });
});
JS;

$js_page = [$js_print_letters];

// Settings for print
$print_settings = [
    'school_name' => $school_profile['nama_madrasah'] ?? 'MADRASAH',
    'school_logo' => !empty($school_profile['logo']) ? '../assets/img/' . $school_profile['logo'] : '',
    'academic_year' => $school_profile['tahun_ajaran'] ?? '-',
    'head_name' => $school_profile['nama_kepala'] ?? '-',
    'head_nip' => $school_profile['nip_kepala'] ?? '-',
    'print_place' => $school_profile['tempat_jadwal'] ?? 'Padang',
];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<?php
$selected_tingkat_golongan = 'Siaga';
foreach ($tingkat_list as $_t) {
    if ((int)($_t['id_tingkat_barung'] ?? 0) === $selected_tingkat_id) {
        $selected_tingkat_golongan = (string)($_t['golongan'] ?? 'Siaga');
        break;
    }
}
$is_penggalang_sel = strcasecmp($selected_tingkat_golongan, 'Penggalang') === 0;
$suket_total = count($participants);
?>
<style>
.suket-tabs-wrap {
    display: flex; align-items: center; gap: 8px;
    overflow-x: auto; padding: 4px 2px 10px;
    -webkit-overflow-scrolling: touch; scrollbar-width: thin;
}
.suket-tabs-wrap::-webkit-scrollbar { height: 4px; }
.suket-tabs-wrap::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
.suket-tab-pill {
    display: inline-flex; align-items: center; gap: 7px;
    flex: 0 0 auto; padding: 9px 15px; border-radius: 999px !important;
    font-size: .86rem; font-weight: 700; white-space: nowrap;
    text-decoration: none !important; border: 1.5px solid #e2e8f0;
    background: #fff; color: #475569; box-shadow: 0 2px 6px rgba(15, 23, 42, .05);
    transition: all .2s ease;
}
.suket-tab-pill:hover { color: #2563eb; border-color: #bfdbfe; }
.suket-tab-pill.active.siaga {
    background: linear-gradient(135deg, #0f766e 0%, #15803d 100%);
    border-color: transparent; color: #fff;
    box-shadow: 0 6px 16px rgba(21, 128, 61, .28);
}
.suket-tab-pill.active.penggalang {
    background: linear-gradient(135deg, #9f1239 0%, #be123c 100%);
    border-color: transparent; color: #fff;
    box-shadow: 0 6px 16px rgba(190, 18, 60, .28);
}
.suket-tab-count {
    font-size: .7rem; font-weight: 800; padding: 2px 7px;
    border-radius: 999px; background: #f1f5f9; color: #475569;
}
.suket-tab-pill.active .suket-tab-count { background: rgba(255, 255, 255, .28); color: #fff; }
.suket-toolbar .suket-search { flex: 0 0 260px; width: 260px; }
.suket-toolbar .app-select-wrap { flex: 0 0 200px; }
.suket-toolbar .app-select-btn { width: 200px; }
.suket-toolbar .app-select-wrap .dropdown-menu { left: auto; right: 0; }
.suket-toolbar-actions { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.suket-toolbar-actions .btn {
    border-radius: 12px; font-size: .8rem; font-weight: 700;
    display: inline-flex; align-items: center; gap: 6px; padding: 8px 13px;
}
.suket-kta-card {
    position: relative; display: flex; flex-direction: column;
    height: 100%; background: #fff; border: 1px solid #e2e8f0;
    border-radius: 16px; overflow: hidden;
    box-shadow: 0 6px 18px rgba(15, 23, 42, .06);
    transition: transform .18s, box-shadow .18s;
}
.suket-kta-card:hover { transform: translateY(-3px); box-shadow: 0 12px 28px rgba(15, 23, 42, .12); }
.suket-kta-top {
    position: relative; padding: 12px 14px; color: #fff;
    display: flex; align-items: center; gap: 10px; overflow: hidden;
    background: linear-gradient(135deg, #1d4ed8 0%, #0891b2 100%);
}
.suket-kta-top::after {
    content: ""; position: absolute; right: -26px; top: -30px;
    width: 110px; height: 110px; border-radius: 50%; background: rgba(255, 255, 255, .12);
}
.suket-kta-emblem {
    width: 34px; height: 34px; border-radius: 50%; flex: 0 0 auto;
    background: rgba(255, 255, 255, .2); border: 1.5px solid rgba(255, 255, 255, .35);
    display: grid; place-items: center; font-size: 1rem;
}
.suket-kta-top-text { min-width: 0; line-height: 1.15; }
.suket-kta-top-text .org {
    font-size: .66rem; letter-spacing: .09em; font-weight: 800;
    text-transform: uppercase; opacity: .9;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 46vw;
}
.suket-kta-top-text .title { font-size: .86rem; font-weight: 800; letter-spacing: .02em; }
.suket-kta-serial {
    margin-left: auto; font-size: .66rem; font-weight: 800;
    padding: 4px 9px; border-radius: 999px; white-space: nowrap;
    background: rgba(255, 255, 255, .22); border: 1px solid rgba(255, 255, 255, .35);
}
.suket-kta-body { position: relative; padding: 14px; display: flex; gap: 13px; flex: 1 1 auto; }
.suket-kta-body::before {
    content: "\f2a3"; font-family: "Font Awesome 5 Free"; font-weight: 900;
    position: absolute; right: 6px; bottom: -6px; font-size: 5.5rem;
    color: rgba(15, 23, 42, .035); pointer-events: none;
}
.suket-kta-photo { position: relative; flex: 0 0 auto; }
.suket-kta-photo .photo-frame {
    width: 72px; height: 88px; border-radius: 12px; overflow: hidden;
    background: linear-gradient(135deg, #dbeafe, #e0f2fe); border: 2px solid #fff;
    box-shadow: 0 3px 10px rgba(15, 23, 42, .15); display: grid; place-items: center;
}
.suket-kta-photo img, .suket-kta-photo .suket-initials {
    width: 100%; height: 100%; object-fit: cover;
    display: grid; place-items: center; font-size: 1.4rem; font-weight: 800; color: #1e40af;
    background: linear-gradient(135deg, #dbeafe, #e0f2fe);
}
.suket-kta-nta {
    position: absolute; left: 50%; transform: translateX(-50%); bottom: -9px;
    background: #0f172a; color: #fff; font-size: .6rem; font-weight: 800;
    padding: 3px 8px; border-radius: 999px; white-space: nowrap; border: 2px solid #fff;
}
.suket-kta-info { min-width: 0; flex: 1; }
.suket-kta-name {
    font-size: .98rem; font-weight: 800; color: #0f172a;
    line-height: 1.25; margin: 0 0 5px; overflow-wrap: anywhere;
}
.suket-kta-meta { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 8px; }
.suket-kta-pill {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: .68rem; font-weight: 700; padding: 3px 8px;
    border-radius: 999px; background: #f1f5f9; color: #334155;
}
.suket-kta-pill.blue { background: #eff6ff; color: #1d4ed8; }
.suket-kta-pill.green { background: #ecfdf5; color: #15803d; }
.suket-kta-pill.red { background: #fff1f2; color: #be123c; }
.suket-kta-detail {
    display: grid; grid-template-columns: 1fr 1fr; gap: 4px 10px;
    font-size: .72rem; color: #475569;
}
.suket-kta-detail .lbl {
    display: block; font-size: .62rem; color: #94a3b8;
    text-transform: uppercase; letter-spacing: .05em; font-weight: 800;
}
.suket-kta-detail .val { font-weight: 700; color: #1e293b; overflow-wrap: anywhere; }
.suket-kta-foot {
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    padding: 9px 14px; border-top: 1px dashed #e2e8f0; background: #fbfdff;
}
.suket-kta-status {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: .7rem; font-weight: 800; color: #15803d; margin: 0;
}
.suket-kta-status .dot {
    width: 7px; height: 7px; border-radius: 50%; background: #22c55e;
    box-shadow: 0 0 0 3px rgba(34, 197, 94, .16);
}
.suket-kta-actions { display: inline-flex; gap: 6px; }
.suket-kta-actions .btn { border-radius: 10px; font-size: .72rem; font-weight: 700; padding: 6px 10px; }
@media (max-width: 575.98px) {
    .suket-kta-body { gap: 11px; padding: 13px 12px; }
    .suket-kta-photo .photo-frame { width: 64px; height: 78px; }
    .suket-kta-name { font-size: .92rem; }
    .suket-kta-detail { font-size: .69rem; }
    .suket-kta-top { padding: 10px 12px; }
    .suket-kta-top-text .title { font-size: .78rem; }
    .suket-kta-serial { font-size: .6rem; padding: 3px 7px; }
    .suket-toolbar .suket-search,
    .suket-toolbar .app-select-wrap,
    .suket-toolbar .app-select-btn { flex: 1 1 100%; width: 100%; }
    .suket-toolbar-actions { width: 100%; }
    .suket-toolbar-actions .btn { flex: 1 1 0; justify-content: center; }
    .suket-kta-actions .btn { flex: 1 1 0; justify-content: center; }
}
@media (max-width: 991.98px) {
    #suketCardsView { padding-bottom: 12px; }
}
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Surat Keterangan</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <!-- Hidden inputs for print settings -->
            <input type="hidden" id="schoolName" value="<?= htmlspecialchars($print_settings['school_name']) ?>">
            <input type="hidden" id="schoolLogo" value="<?= htmlspecialchars($print_settings['school_logo']) ?>">
            <input type="hidden" id="academicYear" value="<?= htmlspecialchars($print_settings['academic_year']) ?>">
            <input type="hidden" id="headName" value="<?= htmlspecialchars($print_settings['head_name']) ?>">
            <input type="hidden" id="headNip" value="<?= htmlspecialchars($print_settings['head_nip']) ?>">
            <input type="hidden" id="printPlace" value="<?= htmlspecialchars($print_settings['print_place']) ?>">
            <input type="hidden" id="tingkatName" value="<?= htmlspecialchars($selected_tingkat_name) ?>">
            <input type="hidden" id="selectedTingkatId" value="<?= (int)$selected_tingkat_id ?>">
            <input type="hidden" id="sessionType" value="<?= htmlspecialchars($session_type) ?>">
            <input type="hidden" id="printDate" value="<?= date('d F Y') ?>">

            <div class="app-summary-row">
                <div class="app-summary-chip">
                    <div class="ico" style="background:#eff6ff;color:#1d4ed8;"><i class="fas fa-file-alt"></i></div>
                    <div>
                        <div class="lbl">Total Surat</div>
                        <div class="val"><?= (int)$suket_total ?> surat</div>
                    </div>
                </div>
                <div class="app-summary-chip">
                    <div class="ico" style="<?= $is_penggalang_sel ? 'background:#fff1f2;color:#be123c;' : 'background:#ecfdf5;color:#15803d;' ?>"><i class="fas fa-layer-group"></i></div>
                    <div>
                        <div class="lbl">Tingkat</div>
                        <div class="val"><?= htmlspecialchars($selected_tingkat_name !== '' ? $selected_tingkat_name : '-') ?></div>
                    </div>
                </div>
                <div class="app-summary-chip">
                    <div class="ico" style="background:#eef2ff;color:#4338ca;"><i class="fas fa-calendar-alt"></i></div>
                    <div>
                        <div class="lbl">Tahun Ajaran</div>
                        <div class="val"><?= htmlspecialchars($selected_tahun_ajaran) ?></div>
                    </div>
                </div>
            </div>

            <div class="suket-tabs-wrap">
                <?php foreach ($tingkat_list as $tingkat):
                    $tid = (int)($tingkat['id_tingkat_barung'] ?? 0);
                    $aktif = ($selected_tingkat_id === $tid);
                    $penggalang = (($tingkat['golongan'] ?? 'Siaga') === 'Penggalang');
                ?>
                    <a href="?tingkat=<?= $tid ?>&tahun_ajaran=<?= urlencode($selected_tahun_ajaran) ?><?= $session_type_param ?>"
                       class="suket-tab-pill <?= $penggalang ? 'penggalang' : 'siaga' ?> <?= $aktif ? 'active' : '' ?>"
                       aria-selected="<?= $aktif ? 'true' : 'false' ?>">
                        <i class="fas <?= $penggalang ? 'fa-compass' : 'fa-seedling' ?>"></i>
                        <span><?= htmlspecialchars($tingkat['nama_tingkat'] ?? '') ?></span>
                        <span class="suket-tab-count"><?= (int)($suket_counts[$tid] ?? 0) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="app-toolbar suket-toolbar">
                <div class="app-search suket-search">
                    <i class="fas fa-search"></i>
                    <input type="search" id="suketSearchInput" placeholder="Cari nama atau NTA…" autocomplete="off">
                </div>

                <div class="dropdown app-select-wrap">
                    <button class="app-select-btn" type="button" id="dropdownTahunAjaran" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <i class="fas fa-calendar-alt"></i>
                        <span><?= htmlspecialchars($selected_tahun_ajaran) ?></span>
                        <i class="fas fa-chevron-down app-select-caret"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-right" aria-labelledby="dropdownTahunAjaran">
                        <?php foreach ($tahun_ajaran_set as $_ta): ?>
                            <?php $ta_selected = ($_ta === $selected_tahun_ajaran); ?>
                            <a class="dropdown-item <?= $ta_selected ? 'app-select-selected' : '' ?>"
                               href="?tingkat=<?= (int)$selected_tingkat_id ?>&tahun_ajaran=<?= urlencode($_ta) ?><?= $session_type_param ?>">
                                <i class="fas fa-calendar-alt mr-2"></i><?= htmlspecialchars($_ta) ?><?= ($_ta === $current_tahun_ajaran) ? ' (Aktif)' : '' ?>
                                <?php if ($ta_selected): ?><i class="fas fa-check app-select-check"></i><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="suket-toolbar-actions">
                    <button class="btn btn-info" onclick="printAllLetters()" id="btnPrintAll" <?= empty($participants) ? 'disabled' : '' ?>>
                        <i class="fas fa-print"></i><span>Cetak Semua</span>
                    </button>
                    <button class="btn btn-primary" onclick="exportPDF()">
                        <i class="fas fa-file-pdf"></i><span>Cetak PDF</span>
                    </button>
                </div>
            </div>

            <?php if (!$is_current_ta): ?>
                <div class="alert alert-light border small mb-3">
                    <i class="fas fa-lock text-muted"></i> Tahun ajaran sebelumnya — tanggal surat terkunci.
                </div>
            <?php endif; ?>

            <div id="suketCardsView">
                <?php if (empty($participants)): ?>
                    <div class="alert alert-light border text-center py-5">
                        <i class="fas fa-file-alt fa-3x text-muted mb-3 d-block"></i>
                        <div class="font-weight-bold text-dark">Belum ada surat keterangan untuk tingkat ini.</div>
                        <div class="small text-muted mt-1">Surat muncul setelah peserta didik dinyatakan lulus SKU.</div>
                    </div>
                <?php else: ?>
                    <div class="row app-cards-row" id="suketCardsContainer">
                        <?php $suket_no = 1; foreach ($participants as $participant): ?>
                            <?php
                                $p_id = (int)($participant['id_peserta_didik_barung'] ?? 0);
                                $p_nama = (string)($participant['nama_peserta_didik'] ?? '');
                                $p_nta = trim((string)($participant['nta'] ?? ''));
                                $p_tempat = trim((string)($participant['tempat_lahir'] ?? ''));
                                $p_tgl = formatDateDMY($participant['tanggal_lahir'] ?? null);
                                $p_jk = strtoupper(trim((string)($participant['jenis_kelamin'] ?? '')));
                                $p_jk_label = ($p_jk === 'P' || $p_jk === 'PEREMPUAN') ? 'Putri' : ($p_jk === '' ? '' : 'Putra');
                                $p_initials = mb_strtoupper(mb_substr(trim($p_nama), 0, 2));
                                $p_fallback = '<div class="suket-initials">' . htmlspecialchars($p_initials, ENT_QUOTES, 'UTF-8') . '</div>';
                                $p_avatar = getPersonAvatarHtml((string)($participant['foto'] ?? ''), $p_fallback, 'suket-photo-img');
                                $p_search = mb_strtolower($p_nama . ' ' . $p_nta . ' ' . $p_tempat);
                            ?>
                            <div class="col-12 col-md-6 mb-3 suket-card-col"
                                 data-search="<?= htmlspecialchars($p_search, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="suket-kta-card">
                                    <div class="suket-kta-top">
                                        <div class="suket-kta-emblem"><i class="fas fa-certificate"></i></div>
                                        <div class="suket-kta-top-text">
                                            <div class="org"><?= htmlspecialchars($print_settings['school_name']) ?></div>
                                            <div class="title">Surat Keterangan</div>
                                        </div>
                                        <div class="suket-kta-serial">#<?= $suket_no++ ?></div>
                                    </div>
                                    <div class="suket-kta-body">
                                        <div class="suket-kta-photo">
                                            <div class="photo-frame"><?= $p_avatar ?></div>
                                            <div class="suket-kta-nta"><?= $p_nta !== '' ? htmlspecialchars($p_nta, ENT_QUOTES, 'UTF-8') : 'NTA -' ?></div>
                                        </div>
                                        <div class="suket-kta-info">
                                            <div class="suket-kta-name"><?= htmlspecialchars($p_nama, ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="suket-kta-meta">
                                                <span class="suket-kta-pill <?= $is_penggalang_sel ? 'red' : 'green' ?>">
                                                    <i class="fas <?= $is_penggalang_sel ? 'fa-compass' : 'fa-seedling' ?>"></i>
                                                    <?= htmlspecialchars($selected_tingkat_name !== '' ? $selected_tingkat_name : '-') ?>
                                                </span>
                                                <span class="suket-kta-pill blue"><i class="fas fa-calendar-alt"></i> <?= htmlspecialchars($selected_tahun_ajaran) ?></span>
                                                <?php if ($p_jk_label !== ''): ?>
                                                    <span class="suket-kta-pill"><?= $p_jk_label ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="suket-kta-detail">
                                                <div>
                                                    <span class="lbl">NTA</span>
                                                    <span class="val"><?= $p_nta !== '' ? htmlspecialchars($p_nta, ENT_QUOTES, 'UTF-8') : '-' ?></span>
                                                </div>
                                                <div>
                                                    <span class="lbl">Golongan</span>
                                                    <span class="val"><?= htmlspecialchars($selected_tingkat_golongan) ?></span>
                                                </div>
                                                <div>
                                                    <span class="lbl">Tempat Lahir</span>
                                                    <span class="val"><?= $p_tempat !== '' ? htmlspecialchars($p_tempat, ENT_QUOTES, 'UTF-8') : '-' ?></span>
                                                </div>
                                                <div>
                                                    <span class="lbl">Tanggal Lahir</span>
                                                    <span class="val"><?= htmlspecialchars($p_tgl !== '' ? $p_tgl : '-', ENT_QUOTES, 'UTF-8') ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="suket-kta-foot">
                                        <span class="suket-kta-status"><span class="dot"></span> Lulus</span>
                                        <div class="suket-kta-actions">
                                            <button class="btn btn-success btn-print-single"
                                                title="Cetak Surat"
                                                data-id="<?= $p_id ?>"
                                                data-nama="<?= htmlspecialchars($p_nama, ENT_QUOTES, 'UTF-8') ?>"
                                                data-nta="<?= htmlspecialchars($p_nta, ENT_QUOTES, 'UTF-8') ?>">
                                                <i class="fas fa-print"></i> Cetak
                                            </button>
                                            <button class="btn btn-outline-danger btn-cancel-single"
                                                title="Batalkan kelulusan"
                                                data-id="<?= $p_id ?>"
                                                data-nama="<?= htmlspecialchars($p_nama, ENT_QUOTES, 'UTF-8') ?>">
                                                <i class="fas fa-times"></i> Batalkan
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-muted small mt-1">
                        Menampilkan <span id="suketVisibleCount"><?= (int)$suket_total ?></span> dari <?= (int)$suket_total ?> surat
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
