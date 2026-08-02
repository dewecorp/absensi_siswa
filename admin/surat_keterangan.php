<?php
// Suppress deprecated warnings for PHP 8.1+
error_reporting(E_ALL & ~E_DEPRECATED);

require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAuthorized(['admin', 'tata_usaha'])) {
    redirect('../login.php');
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
        $tingkat_list[] = $t;
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
if ($selected_tingkat_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT p.id_peserta_didik_barung, p.nama_peserta_didik, p.nta,
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
                   ) AS tanggal_lahir
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
                    IFNULL(p.status, 'aktif') = 'aktif'
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
                )
                OR (
                    p.status = 'keluar'
                    AND DATE(p.tanggal_masuk) <= ?
                    AND DATE(p.tanggal_keluar) >= ?
                )
              )
            ORDER BY p.nama_peserta_didik ASC
        ");
        $stmt->execute([
            $selected_tingkat_id,
            $ta_start_date, $ta_end_date,
            $ta_start_date, $ta_end_date,
            $prev_tingkat_id, $prev_tingkat_id,
            $ta_end_date, $ta_start_date,
        ]);
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // ignore
    }
}

$custom_script = '';

// Page-specific JS (rendered by templates/footer.php after libraries)
$js_print_letters = <<<'JS'
// Tahun Ajaran filter: navigate on change
$('#filterTahunAjaran').on('change', function () {
  var val = $(this).val();
  var tingkat = ($('#selectedTingkatId').val() || '').toString();
  window.location.href = '?tingkat=' + encodeURIComponent(tingkat) + '&tahun_ajaran=' + encodeURIComponent(val);
});

// Print single letter — opens preview without auto-print to avoid browser freeze
function printSingleLetter(id, nama, nta) {
  if (!id) {
    Swal.fire('Error', 'ID peserta didik tidak valid.', 'error');
    return;
  }
  const tingkat = ($('#selectedTingkatId').val() || '').toString();
  const tahunAjaran = ($('#selectedTahunAjaran').val() || '').toString();
  const url = `print_surat_keterangan.php?mode=single&id=${encodeURIComponent(id)}&tingkat=${encodeURIComponent(tingkat)}&tahun_ajaran=${encodeURIComponent(tahunAjaran)}`;
  window.open(url, '_blank');
}

// Print all letters
function printAllLetters() {
  const tingkat = ($('#selectedTingkatId').val() || '').toString();
  const tahunAjaran = ($('#selectedTahunAjaran').val() || '').toString();
  if (!tingkat) {
    Swal.fire('Peringatan', 'Pilih tingkat terlebih dahulu.', 'warning');
    return;
  }
  const url = `print_surat_keterangan.php?mode=all&tingkat=${encodeURIComponent(tingkat)}&tahun_ajaran=${encodeURIComponent(tahunAjaran)}`;
  window.open(url, '_blank');
}

function exportPDF() {
  const tingkat = ($('#selectedTingkatId').val() || '').toString();
  const tahunAjaran = ($('#selectedTahunAjaran').val() || '').toString();
  if (!tingkat) {
    Swal.fire('Peringatan', 'Pilih tingkat terlebih dahulu.', 'warning');
    return;
  }
  const url = `export_surat_keterangan_pdf.php?tingkat=${encodeURIComponent(tingkat)}&tahun_ajaran=${encodeURIComponent(tahunAjaran)}`;
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
            <input type="hidden" id="selectedTahunAjaran" value="<?= htmlspecialchars($selected_tahun_ajaran) ?>">
            <input type="hidden" id="printDate" value="<?= date('d F Y') ?>">

            <div class="card">
                <div class="card-header">
                    <h4>Surat Keterangan Kenaikan Tingkat</h4>
                    <div class="card-header-action">
                        <button class="btn btn-info" onclick="printAllLetters()" id="btnPrintAll" <?= empty($participants) ? 'disabled' : '' ?>>
                            <i class="fas fa-print"></i> Cetak Semua Surat
                        </button>
                        <button class="btn btn-primary" onclick="exportPDF()">
                            <i class="fas fa-print"></i> Cetak PDF Data
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Tahun Ajaran filter -->
                    <div class="form-group row align-items-center">
                        <label class="col-sm-2 col-form-label">Tahun Ajaran:</label>
                        <div class="col-sm-5">
                            <select class="form-control" id="filterTahunAjaran">
                                <?php foreach ($tahun_ajaran_set as $_ta): ?>
                                    <option value="<?= htmlspecialchars($_ta) ?>"
                                        <?= ($_ta === $selected_tahun_ajaran) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($_ta) ?><?= ($_ta === $current_tahun_ajaran) ? ' (Aktif)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$is_current_ta): ?>
                                <small class="form-text text-muted">
                                    <i class="fas fa-lock"></i> Tahun ajaran sebelumnya — tanggal surat terkunci.
                                </small>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="d-block">Pilih Tingkat:</label>
                        <ul class="nav nav-pills flex-wrap mb-2" role="tablist">
                            <?php foreach ($tingkat_list as $index => $tingkat):
                                $tid = (int)($tingkat['id_tingkat_barung'] ?? 0);
                                $aktif = ($selected_tingkat_id === $tid);
                                $golongan = $tingkat['golongan'] ?? 'Siaga';
                                if ($golongan === 'Penggalang') {
                                    $pill_class = $aktif ? 'nav-link active bg-danger border-danger' : 'nav-link border border-danger text-danger';
                                } else {
                                    $pill_class = $aktif ? 'nav-link active bg-success border-success' : 'nav-link border border-success text-success';
                                }
                                $is_first = $index === 0;
                                $is_last = $index === count($tingkat_list) - 1;
                                ?>
                                <li class="nav-item" style="margin: 0;">
                                    <a href="?tingkat=<?= $tid ?>&tahun_ajaran=<?= urlencode($selected_tahun_ajaran) ?>" 
                                       class="nav-link py-1 px-3 <?= $pill_class ?>" 
                                       role="tab" 
                                       style="transition: none; <?= !$is_first ? 'border-left: 0; margin-left: -1px;' : '' ?> <?= !$is_last ? 'border-right: 0;' : '' ?> border-radius: 0;<?= $is_first ? ' border-top-left-radius: 4px; border-bottom-left-radius: 4px;' : '' ?><?= $is_last ? ' border-top-right-radius: 4px; border-bottom-right-radius: 4px;' : '' ?>">
                                        <?= htmlspecialchars($tingkat['nama_tingkat'] ?? '') ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <?php if (!empty($participants)): ?>
                        <div class="table-responsive">
                            <table class="table table-striped" id="table-1">
                                <thead>
                                    <tr>
                                        <th>No</th>
                                        <th>Nama Peserta Didik</th>
                                        <th>NTA</th>
                                        <th>Tempat Lahir</th>
                                        <th>Tanggal Lahir</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $no = 1; foreach ($participants as $participant): ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><?= htmlspecialchars($participant['nama_peserta_didik']) ?></td>
                                        <td><?= htmlspecialchars($participant['nta']) ?></td>
                                        <td><?= htmlspecialchars($participant['tempat_lahir'] ?? '-') ?></td>
                                        <td><?= formatDateDMY($participant['tanggal_lahir'] ?? null) ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-success btn-print-single" data-id="<?= htmlspecialchars($participant['id_peserta_didik_barung'] ?? $participant['id_peserta_didik'] ?? '') ?>" data-nama="<?= htmlspecialchars($participant['nama_peserta_didik'] ?? '') ?>" data-nta="<?= htmlspecialchars($participant['nta'] ?? '') ?>">
                                                <i class="fas fa-print"></i> Cetak Surat
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Tidak ada data peserta didik untuk tingkat ini.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
