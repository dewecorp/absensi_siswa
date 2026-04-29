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

// Tabel tidak memakai DataTables — hindari ekstra parsing/heap setelah cetak/pemakaian lain

// Fetch semua tingkat dulu (untuk rantai kenaikan), lalu buat list tampilan tanpa Pra Mula
$all_tingkat_list = [];
$tingkat_list = [];
try {
    $all_tingkat_list = $pdo->query("
            SELECT id_tingkat_barung, nama_tingkat
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
    $k = strtolower(str_replace(' ', '', $n));
    return $n === 'pra mula' || $k === 'pramula' || $k === 'pra-mula';
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
            SELECT id_peserta_didik_barung, nama_peserta_didik, nta, tempat_lahir, tanggal_lahir
            FROM tb_peserta_didik_barung
            WHERE IFNULL(status, 'aktif') = 'aktif'
              AND id_tingkat_barung = ?
              AND (
                sku_kecakapan_lulus_at IS NOT NULL
                OR (
                    promoted_at IS NOT NULL
                    AND promoted_from_tingkat_id = ?
                    AND ? > 0
                )
              )
            ORDER BY nama_peserta_didik ASC
        ");
        $stmt->execute([$selected_tingkat_id, $prev_tingkat_id, $prev_tingkat_id]);
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // ignore
    }
}

$custom_script = '';

// Page-specific JS (rendered by templates/footer.php after libraries)
$js_print_letters = <<<'JS'
// Print single letter
function printSingleLetter(id, nama, nta) {
  if (!id) {
    Swal.fire('Error', 'ID peserta didik tidak valid.', 'error');
    return;
  }
  const tingkat = ($('#selectedTingkatId').val() || '').toString();
  const url = `print_surat_keterangan.php?mode=single&id=${encodeURIComponent(id)}&tingkat=${encodeURIComponent(tingkat)}&auto=1`;
  const w = window.open(url, '_blank');
  if (w) {
    w.opener = null;
  }
}

// Print all letters
function printAllLetters() {
  const tingkat = ($('#selectedTingkatId').val() || '').toString();
  if (!tingkat) {
    Swal.fire('Peringatan', 'Pilih tingkat terlebih dahulu.', 'warning');
    return;
  }
  const url = `print_surat_keterangan.php?mode=all&tingkat=${encodeURIComponent(tingkat)}&auto=1`;
  const w = window.open(url, '_blank');
  if (w) {
    w.opener = null;
  }
}

function exportPDF() {
  const tingkat = ($('#selectedTingkatId').val() || '').toString();
  if (!tingkat) {
    Swal.fire('Peringatan', 'Pilih tingkat terlebih dahulu.', 'warning');
    return;
  }
  const url = `export_surat_keterangan_pdf.php?tingkat=${encodeURIComponent(tingkat)}`;
  const w = window.open(url, '_blank');
  if (w) {
    w.opener = null;
  }
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
                    <div class="form-group">
                        <label class="d-block">Pilih Tingkat:</label>
                        <div class="suket-tingkat-pills d-flex flex-wrap align-items-stretch" role="group" aria-label="Filter tingkat surat">
                            <?php foreach ($tingkat_list as $tingkat):
                                $tid = (int)($tingkat['id_tingkat_barung'] ?? 0);
                                $aktif = ($selected_tingkat_id === $tid); ?>
                                <a href="?tingkat=<?= $tid ?>" class="btn btn-sm mb-2 mr-2 <?= $aktif ? 'btn-primary' : 'btn-outline-primary' ?>">
                                    <?= htmlspecialchars($tingkat['nama_tingkat'] ?? '') ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
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
                                        <td><?= !empty($participant['tanggal_lahir']) ? date('d-m-Y', strtotime($participant['tanggal_lahir'])) : '-' ?></td>
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

