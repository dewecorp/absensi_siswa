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

$page_title = 'Pengaturan Cetak Suket';

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
    'template_surat' => '',
    'tanda_tangan_ketua_gudep' => ''
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
            'template_surat' => $settings_tmp['template_surat'] ?? '',
            'tanda_tangan_ketua_gudep' => $settings_tmp['tanda_tangan_ketua_gudep'] ?? ''
        ];
    }
} catch (Exception $e) {
    // Table doesn't exist yet
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_print_settings'])) {
    $ketua_gudep = $_POST['ketua_gudep'] ?? '';
    $nta_ketua_gudep = $_POST['nta_ketua_gudep'] ?? '';
    $nomor_gudep = $_POST['nomor_gudep'] ?? '';
    $gugus_depan = $_POST['gugus_depan'] ?? ($print_settings_data['gugus_depan'] ?? '03.016');
    $nomor_surat = $_POST['nomor_surat'] ?? ($print_settings_data['nomor_surat'] ?? '');
    $tempat_pelantikan = $_POST['tempat_pelantikan'] ?? '';
    $tempat_surat = $_POST['tempat_surat'] ?? '';
    $tanggal_surat = $_POST['tanggal_surat'] ?? '';
    $logo_pramuka = $print_settings_data['logo_pramuka'] ?? '';
    $template_surat = $print_settings_data['template_surat'] ?? '';
    $tanda_tangan_ketua_gudep = $print_settings_data['tanda_tangan_ketua_gudep'] ?? '';

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
    try {
        $pdo->exec("ALTER TABLE tb_pengaturan_cetak_barung ADD COLUMN tanda_tangan_ketua_gudep VARCHAR(255) NULL");
    } catch (Exception $e) { /* ignore */ }

    if (isset($_FILES['logo_pramuka_file']) && $_FILES['logo_pramuka_file']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['logo_pramuka_file']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($ext, $allowed)) {
            $max_size = 2 * 1024 * 1024;
            if ($_FILES['logo_pramuka_file']['size'] <= $max_size) {
                $new_filename = 'logo_pramuka_' . time() . '.' . $ext;
                $upload_dir = __DIR__ . '/../uploads/';

                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                if (move_uploaded_file($_FILES['logo_pramuka_file']['tmp_name'], $upload_dir . $new_filename)) {
                    // Remove old logo file to avoid unused files piling up.
                    $old_logo = trim((string)($print_settings_data['logo_pramuka'] ?? ''));
                    if ($old_logo !== '' && $old_logo !== $new_filename) {
                        $old_logo_path = $upload_dir . basename($old_logo);
                        if (is_file($old_logo_path)) {
                            @unlink($old_logo_path);
                        }
                    }
                    $logo_pramuka = $new_filename;
                }
            }
        }
    }

    if (isset($_FILES['tanda_tangan_ketua_gudep_file']) && $_FILES['tanda_tangan_ketua_gudep_file']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['tanda_tangan_ketua_gudep_file']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($ext, $allowed)) {
            $max_size = 2 * 1024 * 1024;
            if ($_FILES['tanda_tangan_ketua_gudep_file']['size'] <= $max_size) {
                $new_filename = 'tanda_tangan_ketua_gudep_' . time() . '.' . $ext;
                $upload_dir = __DIR__ . '/../uploads/';

                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                if (move_uploaded_file($_FILES['tanda_tangan_ketua_gudep_file']['tmp_name'], $upload_dir . $new_filename)) {
                    // Remove old signature file to avoid unused files piling up.
                    $old_tanda_tangan = trim((string)($print_settings_data['tanda_tangan_ketua_gudep'] ?? ''));
                    if ($old_tanda_tangan !== '' && $old_tanda_tangan !== $new_filename) {
                        $old_tanda_tangan_path = $upload_dir . basename($old_tanda_tangan);
                        if (is_file($old_tanda_tangan_path)) {
                            @unlink($old_tanda_tangan_path);
                        }
                    }
                    $tanda_tangan_ketua_gudep = $new_filename;
                }
            }
        }
    }

    try {
        $check = $pdo->query("SELECT id FROM tb_pengaturan_cetak_barung LIMIT 1")->fetch();

        if ($check) {
            $stmt = $pdo->prepare("
                UPDATE tb_pengaturan_cetak_barung
                SET ketua_gudep = ?, nta_ketua_gudep = ?, nomor_gudep = ?, gugus_depan = ?, nomor_surat = ?, tempat_pelantikan = ?, tempat_surat = ?, tanggal_surat = ?, logo_pramuka = ?, template_surat = ?, tanda_tangan_ketua_gudep = ?
            ");
            $stmt->execute([$ketua_gudep, $nta_ketua_gudep, $nomor_gudep, $gugus_depan, $nomor_surat, $tempat_pelantikan, $tempat_surat, $tanggal_surat, $logo_pramuka, $template_surat, $tanda_tangan_ketua_gudep]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO tb_pengaturan_cetak_barung (ketua_gudep, nta_ketua_gudep, nomor_gudep, gugus_depan, nomor_surat, tempat_pelantikan, tempat_surat, tanggal_surat, logo_pramuka, template_surat, tanda_tangan_ketua_gudep)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$ketua_gudep, $nta_ketua_gudep, $nomor_gudep, $gugus_depan, $nomor_surat, $tempat_pelantikan, $tempat_surat, $tanggal_surat, $logo_pramuka, $template_surat, $tanda_tangan_ketua_gudep]);
        }

        // Freeze tanggal_surat and tempat_surat for the current academic year
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS tb_tahun_ajaran_suket (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tahun_ajaran VARCHAR(20) NOT NULL UNIQUE,
                tanggal_surat VARCHAR(50) NULL,
                tempat_surat VARCHAR(100) NULL,
                updated_at DATETIME NULL
            )");
            $school_profile_tmp = getSchoolProfile($pdo);
            $current_ta_tmp = (string)($school_profile_tmp['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1));
            $pdo->prepare("
                INSERT INTO tb_tahun_ajaran_suket (tahun_ajaran, tanggal_surat, tempat_surat, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE tanggal_surat = VALUES(tanggal_surat), tempat_surat = VALUES(tempat_surat), updated_at = NOW()
            ")->execute([$current_ta_tmp, $tanggal_surat, $tempat_surat]);
        } catch (Exception $e) { /* best effort */ }

        $message = ['type' => 'success', 'text' => 'Pengaturan cetak suket berhasil disimpan.'];
    } catch (Exception $e) {
        $message = ['type' => 'danger', 'text' => 'Gagal menyimpan pengaturan: ' . $e->getMessage()];
    }
}

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
            'template_surat' => $settings['template_surat'] ?? '',
            'tanda_tangan_ketua_gudep' => $settings['tanda_tangan_ketua_gudep'] ?? ''
        ];
    }
} catch (Exception $e) {
    // ignore
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Pengaturan Cetak Suket</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4>Data Cetak Surat Keterangan</h4>
                    <div class="card-header-action">
                        <a href="surat_keterangan.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Kembali ke Surat Keterangan
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="save_print_settings" value="1">

                        <div class="row">
                            <div class="col-md-6">
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

                                <div class="form-group">
                                    <label>Tempat Pelantikan:</label>
                                    <input type="text" name="tempat_pelantikan" class="form-control" value="<?= htmlspecialchars($print_settings_data['tempat_pelantikan']) ?>" placeholder="Tempat Pelantikan">
                                </div>
                            </div>

                            <div class="col-md-6">
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
                                </div>

                                <div class="form-group">
                                    <label>Tanda Tangan Ketua Gudep (gambar):</label>
                                    <div class="custom-file">
                                        <input type="file" name="tanda_tangan_ketua_gudep_file" class="custom-file-input" id="tandaTanganKetuaGudepFile" accept="image/*">
                                        <label class="custom-file-label" for="tandaTanganKetuaGudepFile">Pilih tanda tangan ketua gudep...</label>
                                    </div>
                                    <small class="text-muted">Format: JPG, PNG, maksimal 2MB</small>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($print_settings_data['logo_pramuka']) || !empty($print_settings_data['tanda_tangan_ketua_gudep'])): ?>
                        <div class="form-group">
                            <label>Preview:</label>
                            <div class="row mt-3">
                                <?php if (!empty($print_settings_data['logo_pramuka'])): ?>
                                <div class="col-md-4 mb-3">
                                    <div class="card shadow-sm">
                                        <div class="card-header bg-light text-center font-weight-bold py-2">
                                            Logo Pramuka
                                        </div>
                                        <div class="card-body d-flex align-items-center justify-content-center" style="min-height: 220px;">
                                            <img src="../uploads/<?= htmlspecialchars($print_settings_data['logo_pramuka']) ?>" alt="Logo Pramuka" class="img-fluid" style="max-width: 100%; max-height: 200px; object-fit: contain;">
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($print_settings_data['tanda_tangan_ketua_gudep'])): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="card shadow-sm">
                                        <div class="card-header bg-light text-center font-weight-bold py-2">
                                            Tanda Tangan Ketua Gudep
                                        </div>
                                        <div class="card-body d-flex align-items-center justify-content-center" style="min-height: 150px;">
                                            <img src="../uploads/<?= htmlspecialchars($print_settings_data['tanda_tangan_ketua_gudep']) ?>" alt="Tanda Tangan Ketua Gudep" class="img-fluid" style="max-width: 100%; max-height: 130px; object-fit: contain;">
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Simpan Pengaturan
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
<?php if (!empty($message)): ?>
<script type="text/javascript">
Swal.fire({
    icon: <?= json_encode(($message['type'] ?? '') === 'success' ? 'success' : 'error') ?>,
    title: <?= json_encode(($message['type'] ?? '') === 'success' ? 'Berhasil' : 'Gagal') ?>,
    text: <?= json_encode($message['text'] ?? '') ?>,
    timer: 2200,
    showConfirmButton: false
});
</script>
<?php endif; ?>
