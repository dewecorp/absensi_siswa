<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin, kepala_madrasah, tata_usaha, guru, or wali level
if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha', 'guru', 'wali'])) {
    redirect('../login.php');
}

// Get school profile
$school_profile = getSchoolProfile($pdo);

// Get Schedule Data
$fieldHari7 = getSqlFieldUrutanHari7($pdo);
$stmt = $pdo->query("
    SELECT j.*, g.nama_guru 
    FROM tb_jadwal_imam j 
    JOIN tb_guru g ON j.id_guru = g.id_guru 
    ORDER BY FIELD(j.hari, $fieldHari7)
");
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle Print View
if (isset($_GET['print'])) {
    $tahun_ajaran = $school_profile['tahun_ajaran'] ?? (date('Y') . '/' . (date('Y') + 1));
    $logo_file = $school_profile['logo'] ?? '';
    $logo_path = '../assets/img/logo_madrasah.png';
    if ($logo_file && file_exists(__DIR__ . '/../assets/img/' . $logo_file)) {
        $logo_path = '../assets/img/' . $logo_file;
    }
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Jadwal Imam Dhuha_<?php echo str_replace('/', '-', $tahun_ajaran); ?></title>
        <style>
            @page { size: 215mm 330mm landscape; margin: 5mm 20mm 20mm 20mm; }
            body { font-family: "Bookman Old Style", "Georgia", serif; padding: 0 30px 30px 30px; background: white; }
            .header { border-bottom: 3px double #000; padding-bottom: 10px; margin-bottom: 30px; }
            .header table { width: 100%; border: none !important; margin: 0 !important; }
            .header td { border: none !important; padding: 0 !important; vertical-align: middle; }
            .header .logo-cell { width: 80px; text-align: left; }
            .header .text-cell { text-align: center; padding-right: 80px !important; }
            .header img { height: 80px; }
            .header h3 { font-size: 12pt; margin: 2px 0; text-transform: uppercase; }
            .header h2 { font-size: 14pt; margin: 2px 0; text-transform: uppercase; white-space: nowrap; }
            .header p { font-size: 10pt; margin: 2px 0; text-transform: uppercase; }
            .table-bordered { border-collapse: collapse; width: 100%; margin-top: 20px; }
            .table-bordered th, .table-bordered td { border: 1px solid #000; padding: 12px; text-align: center; }
            .table-bordered th { background-color: #f2f2f2; font-weight: bold; }
            .signature-area { margin-top: 50px; float: right; width: 300px; text-align: center; page-break-inside: avoid; }
            img.qr-code { width: 80px; height: 80px; margin: 10px auto; display: block; }
        </style>
    </head>
    <body onload="window.print()">
        <div class="header">
            <table>
                <tr>
                    <td class="logo-cell">
                        <img src="<?php echo $logo_path; ?>" alt="Logo">
                    </td>
                    <td class="text-cell">
                        <h3>JADWAL IMAM SHALAT DHUHA</h3>
                        <h2><?php echo strtoupper($school_profile['nama_sekolah'] ?? $school_profile['nama_madrasah'] ?? 'MI SULTAN FATTAH SUKOSONO'); ?></h2>
                        <p>Tahun Ajaran <?php echo $tahun_ajaran; ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <table class="table-bordered">
            <thead>
                <tr>
                    <th width="10%">NO</th>
                    <th width="30%">HARI</th>
                    <th width="60%">NAMA GURU</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($schedules as $idx => $row): ?>
                    <tr>
                        <td><?php echo $idx + 1; ?></td>
                        <td><?php echo strtoupper($row['hari']); ?></td>
                        <td><?php echo $row['nama_guru']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="signature-area">
            <?php
            // Use exact same logic as export_jadwal_pdf.php for consistency
            $tempat = !empty($school_profile['tempat_jadwal']) ? $school_profile['tempat_jadwal'] : 'Jakarta';
            $tanggal = !empty($school_profile['tanggal_jadwal']) 
                ? formatDateIndonesia($school_profile['tanggal_jadwal']) 
                : formatDateIndonesia(date('Y-m-d'));
            $date_str = $tempat . ', ' . $tanggal;
            ?>
            <p><?php echo $date_str; ?></p>
            <p>Kepala <?php echo $school_profile['nama_sekolah'] ?? $school_profile['nama_madrasah'] ?? 'Madrasah'; ?>,</p>
            <?php 
            $kepala = $school_profile['kepala_madrasah'] ?? 'Musriah, S.Pd.I.';
            $qr_content = "Validasi Jadwal Imam Dhuha: " . $kepala . " - " . ($school_profile['nama_madrasah'] ?? 'MI Sultan Fattah');
            $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=" . urlencode($qr_content);
            ?>
            <img src="<?php echo $qr_url; ?>" alt="QR Code" class="qr-code">
            <p><strong><?php echo $kepala; ?></strong></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Set page title
$page_title = 'Jadwal Imam Dhuha';

// Get user level
$user_level = getUserLevel();
$is_editable = in_array($user_level, ['admin', 'tata_usaha']);

// Handle Form Submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $is_editable) {
    if (isset($_POST['action'])) {
        try {
            if ($_POST['action'] == 'add') {
                $stmt = $pdo->prepare("INSERT INTO tb_jadwal_imam (hari, id_guru) VALUES (?, ?)");
                $stmt->execute([$_POST['hari'], $_POST['id_guru']]);
                $message = ['type' => 'success', 'text' => 'Jadwal berhasil ditambahkan!'];
            } elseif ($_POST['action'] == 'edit') {
                $stmt = $pdo->prepare("UPDATE tb_jadwal_imam SET hari = ?, id_guru = ? WHERE id = ?");
                $stmt->execute([$_POST['hari'], $_POST['id_guru'], $_POST['id']]);
                $message = ['type' => 'success', 'text' => 'Jadwal berhasil diperbarui!'];
            } elseif ($_POST['action'] == 'delete') {
                $stmt = $pdo->prepare("DELETE FROM tb_jadwal_imam WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                $message = ['type' => 'success', 'text' => 'Jadwal berhasil dihapus!'];
            }
            // Refresh data after change
            header("Location: jadwal_imam.php?msg=" . urlencode($message['text']));
            exit;
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Terjadi kesalahan: ' . $e->getMessage()];
        }
    }
}

if (isset($_GET['msg'])) {
    $message = ['type' => 'success', 'text' => $_GET['msg']];
}

// Get Male Teachers
$stmt = $pdo->query("SELECT id_guru, nama_guru FROM tb_guru WHERE jenis_kelamin = 'Laki-laki' ORDER BY nama_guru ASC");
$male_teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$days = getUrutanHariPilihanModal7Hari($pdo);

// Add Select2 CSS and JS
if (!isset($css_libs)) $css_libs = [];
$css_libs[] = 'assets/vendor/select2/css/select2.min.css';
if (!isset($js_libs)) $js_libs = [];
$js_libs[] = 'assets/vendor/select2/js/select2.full.min.js';

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<!-- Custom CSS for Select2 in modal -->
<style>
.select2-container--default .select2-selection--single {
    height: 38px;
    border: 1px solid #d4d4d4;
    border-radius: 4px;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 36px;
}
.select2-dropdown {
    z-index: 1060 !important;
}
.select2-container--open .select2-dropdown {
    z-index: 1060 !important;
}
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Jadwal Imam Shalat Dhuha</h1>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Daftar Jadwal Imam <?php echo $school_profile['tahun_ajaran'] ?? ''; ?></h4>
                            <div class="card-header-action">
                                <?php if ($is_editable): ?>
                                <button class="btn btn-primary" data-toggle="modal" data-target="#modalAdd">
                                    <i class="fas fa-plus"></i> Tambah Jadwal
                                </button>
                                <?php endif; ?>
                                <a href="jadwal_imam.php?print=1" target="_blank" class="btn btn-info">
                                    <i class="fas fa-print"></i> Cetak
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-bordered">
                                    <thead>
                                        <tr>
                                            <th class="text-center" width="10%">NO</th>
                                            <th class="text-center" width="30%">HARI</th>
                                            <th class="text-center">NAMA GURU</th>
                                            <?php if ($is_editable): ?>
                                            <th class="text-center" width="15%">AKSI</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($schedules as $idx => $row): ?>
                                        <tr>
                                            <td class="text-center"><?php echo $idx + 1; ?></td>
                                            <td class="text-center"><?php echo strtoupper($row['hari']); ?></td>
                                            <td><?php echo $row['nama_guru']; ?></td>
                                            <?php if ($is_editable): ?>
                                            <td class="text-center">
                                                <button class="btn btn-warning btn-sm" data-toggle="modal" data-target="#modalEdit<?php echo $row['id']; ?>"><i class="fas fa-edit"></i></button>
                                                <button class="btn btn-danger btn-sm" data-toggle="modal" data-target="#modalDelete<?php echo $row['id']; ?>"><i class="fas fa-trash"></i></button>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Modals ... -->
<?php if ($is_editable): ?>
<div class="modal fade" id="modalAdd" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="" method="POST">
                <div class="modal-header"><h5 class="modal-title">Tambah Jadwal</h5></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label>Hari</label>
                        <select class="form-control" name="hari" required>
                            <option value="">Pilih Hari</option>
                            <?php foreach ($days as $day): ?>
                                <option value="<?php echo $day; ?>"><?php echo $day; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Guru</label>
                        <select class="form-control select2" name="id_guru" required style="width: 100%;">
                            <option value="">Pilih Guru</option>
                            <?php foreach ($male_teachers as $guru): ?>
                                <option value="<?php echo $guru['id_guru']; ?>"><?php echo $guru['nama_guru']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach ($schedules as $row): ?>
<div class="modal fade" id="modalEdit<?php echo $row['id']; ?>" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="" method="POST">
                <div class="modal-header"><h5 class="modal-title">Edit Jadwal</h5></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                    <div class="form-group">
                        <label>Hari</label>
                        <select class="form-control" name="hari" required>
                            <?php foreach ($days as $day): ?>
                                <option value="<?php echo $day; ?>" <?php echo ($row['hari'] == $day) ? 'selected' : ''; ?>><?php echo $day; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Guru</label>
                        <select class="form-control select2" name="id_guru" required style="width: 100%;">
                            <?php foreach ($male_teachers as $guru): ?>
                                <option value="<?php echo $guru['id_guru']; ?>" <?php echo ($row['id_guru'] == $guru['id_guru']) ? 'selected' : ''; ?>><?php echo $guru['nama_guru']; ?></option>
                            <?php endforeach; ?>
                        </select>
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
<div class="modal fade" id="modalDelete<?php echo $row['id']; ?>" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="" method="POST">
                <div class="modal-header"><h5 class="modal-title">Hapus Jadwal</h5></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                    <p>Hapus jadwal hari <?php echo $row['hari']; ?>?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger">Hapus</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php include '../templates/footer.php'; ?>

<script>
<?php if ($message): ?>
Swal.fire({ icon: '<?php echo $message['type'] == 'danger' ? 'error' : 'success'; ?>', title: '<?php echo $message['text']; ?>', timer: 2000, showConfirmButton: false });
<?php endif; ?>
$(document).ready(function() {
    // Initialize Select2 for add modal
    $('#modalAdd select.select2').select2({
        dropdownParent: $('#modalAdd'),
        placeholder: 'Pilih Guru',
        allowClear: true
    });
    
    // Initialize Select2 for each edit modal
    <?php foreach ($schedules as $row): ?>
    $('#modalEdit<?php echo $row['id']; ?> select.select2').select2({
        dropdownParent: $('#modalEdit<?php echo $row['id']; ?>'),
        placeholder: 'Pilih Guru',
        allowClear: true
    });
    <?php endforeach; ?>
});
</script>
