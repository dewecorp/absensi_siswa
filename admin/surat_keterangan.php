<?php
// Suppress deprecated warnings for PHP 8.1+
error_reporting(E_ALL & ~E_DEPRECATED);

require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

$school_profile = getSchoolProfile($pdo);
$page_title = 'Surat Keterangan';

// DataTables
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

// Fetch tingkat list (exclude Pra Mula)
$tingkat_list = [];
try {
    $tingkat_list = $pdo->query("
            SELECT id_tingkat_barung, nama_tingkat
            FROM tb_tingkat_barung
            WHERE LOWER(REPLACE(nama_tingkat, ' ', '')) NOT IN ('pramula', 'pra-mula') 
              AND LOWER(nama_tingkat) != 'pra mula'
            ORDER BY
                CASE
                    WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('mula') THEN 1
                    WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('bantu') THEN 2
                    WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('tata') THEN 3
                    ELSE 99
                END,
                nama_tingkat ASC
        ")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // ignore
}

$selected_tingkat_id = (int)($_GET['tingkat'] ?? 0);
if ($selected_tingkat_id <= 0 && !empty($tingkat_list)) {
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

// Fetch peserta didik for selected tingkat
$participants = [];
if ($selected_tingkat_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT id_peserta_didik_barung, nama_peserta_didik, nta, tempat_lahir, tanggal_lahir
            FROM tb_peserta_didik_barung
            WHERE id_tingkat_barung = ?
            ORDER BY nama_peserta_didik ASC
        ");
        $stmt->execute([$selected_tingkat_id]);
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // ignore
    }
}

// Fetch print settings (needed for POST handler)
$print_settings_data = [
    'ketua_gudep' => '',
    'nta_ketua_gudep' => '',
    'nomor_gudep' => '',
    'gugus_depan' => '03.016',
    'nomor_surat' => '',
    'tempat_pelantikan' => '',
    'tempat_surat' => '',
    'tanggal_surat' => date('d F Y'),
    'logo_pramuka' => '',
    'template_surat' => ''
];

try {
    $settings_tmp = $pdo->query("SELECT * FROM tb_pengaturan_cetak_barung LIMIT 1")->fetch();
    if ($settings_tmp) {
        $print_settings_data = [
            'ketua_gudep' => $settings_tmp['ketua_gudep'] ?? '',
            'nta_ketua_gudep' => $settings_tmp['nta_ketua_gudep'] ?? '',
            'nomor_gudep' => $settings_tmp['nomor_gudep'] ?? '',
            'gugus_depan' => $settings_tmp['gugus_depan'] ?? '03.016',
            'nomor_surat' => $settings_tmp['nomor_surat'] ?? '',
            'tempat_pelantikan' => $settings_tmp['tempat_pelantikan'] ?? '',
            'tempat_surat' => $settings_tmp['tempat_surat'] ?? '',
            'tanggal_surat' => $settings_tmp['tanggal_surat'] ?? date('d F Y'),
            'logo_pramuka' => $settings_tmp['logo_pramuka'] ?? ($settings_tmp['bingkai_surat'] ?? ''),
            'template_surat' => $settings_tmp['template_surat'] ?? ''
        ];
    }
} catch (Exception $e) {
    // Table doesn't exist yet
}

// Handle save print settings
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_print_settings'])) {
    $ketua_gudep = $_POST['ketua_gudep'] ?? '';
    $nta_ketua_gudep = $_POST['nta_ketua_gudep'] ?? '';
    $nomor_gudep = $_POST['nomor_gudep'] ?? '';
    $gugus_depan = $_POST['gugus_depan'] ?? ($print_settings_data['gugus_depan'] ?? '03.016');
    $nomor_surat = $_POST['nomor_surat'] ?? ($print_settings_data['nomor_surat'] ?? '');
    $tempat_pelantikan = $_POST['tempat_pelantikan'] ?? '';
    $tempat_surat = $_POST['tempat_surat'] ?? '';
    $tanggal_surat = $_POST['tanggal_surat'] ?? '';
    $logo_pramuka = $print_settings_data['logo_pramuka'] ?? ''; // Keep existing by default
    $template_surat = $print_settings_data['template_surat'] ?? '';

    // Ensure columns exist (safe no-op if already exists)
    try {
        $pdo->exec("ALTER TABLE tb_pengaturan_cetak_barung ADD COLUMN nta_ketua_gudep VARCHAR(50) NULL");
    } catch (Exception $e) { /* ignore */ }
    try {
        $pdo->exec("ALTER TABLE tb_pengaturan_cetak_barung ADD COLUMN nomor_gudep VARCHAR(50) NULL");
    } catch (Exception $e) { /* ignore */ }
    try {
        $pdo->exec("ALTER TABLE tb_pengaturan_cetak_barung ADD COLUMN gugus_depan VARCHAR(50) NULL");
    } catch (Exception $e) { /* ignore */ }
    try {
        $pdo->exec("ALTER TABLE tb_pengaturan_cetak_barung ADD COLUMN nomor_surat VARCHAR(100) NULL");
    } catch (Exception $e) { /* ignore */ }
    try {
        $pdo->exec("ALTER TABLE tb_pengaturan_cetak_barung ADD COLUMN logo_pramuka VARCHAR(255) NULL");
    } catch (Exception $e) { /* ignore */ }
    try {
        $pdo->exec("ALTER TABLE tb_pengaturan_cetak_barung ADD COLUMN template_surat VARCHAR(255) NULL");
    } catch (Exception $e) { /* ignore */ }
    
    // Handle file upload for logo pramuka
    if (isset($_FILES['logo_pramuka_file']) && $_FILES['logo_pramuka_file']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['logo_pramuka_file']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $max_size = 2 * 1024 * 1024; // 2MB
            if ($_FILES['logo_pramuka_file']['size'] <= $max_size) {
                $new_filename = 'logo_pramuka_' . time() . '.' . $ext;
                $upload_dir = __DIR__ . '/../uploads/';
                
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                if (move_uploaded_file($_FILES['logo_pramuka_file']['tmp_name'], $upload_dir . $new_filename)) {
                    $logo_pramuka = $new_filename;
                }
            }
        }
    }
    
    // Template surat upload has been removed from the edit modal.
    
    try {
        // Check if settings exist
        $check = $pdo->query("SELECT id FROM tb_pengaturan_cetak_barung LIMIT 1")->fetch();
        
        if ($check) {
            // Update
            $stmt = $pdo->prepare("
                UPDATE tb_pengaturan_cetak_barung 
                SET ketua_gudep = ?, nta_ketua_gudep = ?, nomor_gudep = ?, gugus_depan = ?, nomor_surat = ?, tempat_pelantikan = ?, tempat_surat = ?, tanggal_surat = ?, logo_pramuka = ?, template_surat = ?
            ");
            $stmt->execute([$ketua_gudep, $nta_ketua_gudep, $nomor_gudep, $gugus_depan, $nomor_surat, $tempat_pelantikan, $tempat_surat, $tanggal_surat, $logo_pramuka, $template_surat]);
        } else {
            // Insert
            $stmt = $pdo->prepare("
                INSERT INTO tb_pengaturan_cetak_barung (ketua_gudep, nta_ketua_gudep, nomor_gudep, gugus_depan, nomor_surat, tempat_pelantikan, tempat_surat, tanggal_surat, logo_pramuka, template_surat)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$ketua_gudep, $nta_ketua_gudep, $nomor_gudep, $gugus_depan, $nomor_surat, $tempat_pelantikan, $tempat_surat, $tanggal_surat, $logo_pramuka, $template_surat]);
        }
        
        $message = ['type' => 'success', 'text' => 'Pengaturan cetak berhasil disimpan!'];
    } catch (Exception $e) {
        $message = ['type' => 'error', 'text' => 'Gagal menyimpan pengaturan: ' . $e->getMessage()];
    }
}

// Reload settings after save
try {
    $settings = $pdo->query("SELECT * FROM tb_pengaturan_cetak_barung LIMIT 1")->fetch();
    if ($settings) {
        $print_settings_data = [
            'ketua_gudep' => $settings['ketua_gudep'] ?? '',
            'nta_ketua_gudep' => $settings['nta_ketua_gudep'] ?? '',
            'nomor_gudep' => $settings['nomor_gudep'] ?? '',
            'gugus_depan' => $settings['gugus_depan'] ?? '03.016',
            'nomor_surat' => $settings['nomor_surat'] ?? '',
            'tempat_pelantikan' => $settings['tempat_pelantikan'] ?? '',
            'tempat_surat' => $settings['tempat_surat'] ?? '',
            'tanggal_surat' => $settings['tanggal_surat'] ?? date('d F Y'),
            'logo_pramuka' => $settings['logo_pramuka'] ?? '',
            'template_surat' => $settings['template_surat'] ?? ''
        ];
    }
} catch (Exception $e) {
    // ignore
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
  const url = `print_surat_keterangan.php?mode=single&id=${encodeURIComponent(id)}&auto=1`;
  window.open(url, '_blank');
}

// Print all letters
function printAllLetters() {
  const params = new URLSearchParams(window.location.search);
  const tingkat = params.get('tingkat') || '';
  if (!tingkat) {
    Swal.fire('Peringatan', 'Pilih tingkat terlebih dahulu.', 'warning');
    return;
  }
  const url = `print_surat_keterangan.php?mode=all&tingkat=${encodeURIComponent(tingkat)}&auto=1`;
  window.open(url, '_blank');
}

function exportPDF() {
  const params = new URLSearchParams(window.location.search);
  const tingkat = params.get('tingkat') || '';
  const url = `export_surat_keterangan_pdf.php?tingkat=${encodeURIComponent(tingkat)}`;
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
            <input type="hidden" id="printDate" value="<?= date('d F Y') ?>">

            <!-- Data Cetak Box -->
            <div class="card">
                <div class="card-header">
                    <h4>Data Cetak</h4>
                    <div class="card-header-action">
                        <button class="btn btn-icon icon-left btn-primary" data-toggle="modal" data-target="#editDataCetakModal">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Ketua Gudep:</label>
                                <p class="font-weight-bold"><?= htmlspecialchars($print_settings_data['ketua_gudep'] ?: '-') ?></p>
                            </div>
                            <div class="form-group">
                                <label>NTA Ketua Gudep:</label>
                                <p class="font-weight-bold"><?= htmlspecialchars($print_settings_data['nta_ketua_gudep'] ?: '-') ?></p>
                            </div>
                            <div class="form-group">
                                <label>Gugus Depan:</label>
                                <p class="font-weight-bold"><?= htmlspecialchars(($print_settings_data['nomor_gudep'] ?? '') ?: ($print_settings_data['gugus_depan'] ?? '-') ) ?></p>
                            </div>
                            <div class="form-group">
                                <label>Nomor Surat:</label>
                                <p class="font-weight-bold"><?= htmlspecialchars($print_settings_data['nomor_surat'] ?: '-') ?></p>
                            </div>
                            <div class="form-group">
                                <label>Tempat Pelantikan:</label>
                                <p class="font-weight-bold"><?= htmlspecialchars($print_settings_data['tempat_pelantikan'] ?: '-') ?></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tempat Surat:</label>
                                <p class="font-weight-bold"><?= htmlspecialchars($print_settings_data['tempat_surat'] ?: '-') ?></p>
                            </div>
                            <div class="form-group">
                                <label>Tanggal Surat:</label>
                                <p class="font-weight-bold"><?= htmlspecialchars($print_settings_data['tanggal_surat'] ?: date('d F Y')) ?></p>
                            </div>
                        </div>
                    </div>
                                            
                        <?php if (!empty($print_settings_data['logo_pramuka'])): ?>
                    <div class="form-group">
                        <label>Logo Pramuka:</label>
                        <div class="mt-2">
                            <img src="../uploads/<?= htmlspecialchars($print_settings_data['logo_pramuka']) ?>" alt="Logo Pramuka" class="img-thumbnail" style="max-height: 200px;">
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

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
                        <label>Pilih Tingkat:</label>
                        <select class="form-control select2" onchange="window.location.href='?tingkat=' + this.value">
                            <?php foreach ($tingkat_list as $tingkat): ?>
                                <option value="<?= $tingkat['id_tingkat_barung'] ?>" <?= $selected_tingkat_id == $tingkat['id_tingkat_barung'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($tingkat['nama_tingkat']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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

    <!-- Modal Edit Data Cetak -->
    <div class="modal fade" id="editDataCetakModal" tabindex="-1" role="dialog" aria-labelledby="editDataCetakModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editDataCetakModalLabel">Edit Data Cetak</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="save_print_settings" value="1">
                        
                        <div class="form-group">
                            <label>Ketua Gudep:</label>
                            <input type="text" name="ketua_gudep" class="form-control" value="<?= htmlspecialchars($print_settings_data['ketua_gudep']) ?>" placeholder="Nama Ketua Gudep">
                        </div>

                        <div class="form-group">
                            <label>NTA Ketua Gudep:</label>
                            <input type="text" name="nta_ketua_gudep" class="form-control" value="<?= htmlspecialchars($print_settings_data['nta_ketua_gudep'] ?? '') ?>" placeholder="NTA Ketua Gudep">
                        </div>

                        <div class="form-group">
                            <label>Nomor Gudep:</label>
                            <input type="text" name="nomor_gudep" class="form-control" value="<?= htmlspecialchars($print_settings_data['nomor_gudep'] ?? '') ?>" placeholder="Contoh: 03.016">
                        </div>

                        <!-- Input Gugus Depan dan Nomor Surat dihapus dari modal -->
                        
                        <div class="form-group">
                            <label>Tempat Pelantikan:</label>
                            <input type="text" name="tempat_pelantikan" class="form-control" value="<?= htmlspecialchars($print_settings_data['tempat_pelantikan']) ?>" placeholder="Tempat Pelantikan">
                        </div>
                        
                        <div class="form-group">
                            <label>Tempat Surat:</label>
                            <input type="text" name="tempat_surat" class="form-control" value="<?= htmlspecialchars($print_settings_data['tempat_surat']) ?>" placeholder="Kota/Tempat Surat">
                        </div>
                        
                        <div class="form-group">
                            <label>Tanggal Surat:</label>
                            <input type="date" name="tanggal_surat" class="form-control" value="<?= !empty($print_settings_data['tanggal_surat']) ? date('Y-m-d', strtotime($print_settings_data['tanggal_surat'])) : date('Y-m-d') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Logo Pramuka (gambar):</label>
                            <div class="custom-file">
                                <input type="file" name="logo_pramuka_file" class="custom-file-input" id="logoPramukaFile" accept="image/*">
                                <label class="custom-file-label" for="logoPramukaFile">Pilih logo pramuka...</label>
                            </div>
                            <small class="text-muted">Format: JPG, PNG, maksimal 2MB</small>
                                                    
                        <?php if (!empty($print_settings_data['logo_pramuka'])): ?>
                            <div class="mt-2">
                                <img src="../uploads/<?= htmlspecialchars($print_settings_data['logo_pramuka']) ?>" alt="Logo Pramuka Saat Ini" class="img-thumbnail" style="max-height: 150px;">
                                <p class="text-muted mb-0 mt-1"><small>Logo saat ini</small></p>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Upload Template Surat dihapus sesuai permintaan -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Simpan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Template surat sudah dihapus -->
<?php include '../templates/footer.php'; ?>

