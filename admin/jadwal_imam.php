<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

// Set page title
$page_title = 'Jadwal Imam Dhuha';

// Get school profile for signature
$school_profile = getSchoolProfile($pdo);
$logo_file = $school_profile['logo'] ?? '';
$logo_path = '../assets/img/logo_madrasah.png'; // Default
if ($logo_file && file_exists(__DIR__ . '/../assets/img/' . $logo_file)) {
    $logo_path = '../assets/img/' . $logo_file;
}

// Handle Form Submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        try {
            if ($_POST['action'] == 'add') {
                $hari = $_POST['hari'];
                $id_guru = $_POST['id_guru'];
                
                $stmt = $pdo->prepare("INSERT INTO tb_jadwal_imam (hari, id_guru) VALUES (?, ?)");
                $stmt->execute([$hari, $id_guru]);
                $message = ['type' => 'success', 'text' => 'Jadwal berhasil ditambahkan!'];
            } elseif ($_POST['action'] == 'edit') {
                $id = $_POST['id'];
                $hari = $_POST['hari'];
                $id_guru = $_POST['id_guru'];
                
                $stmt = $pdo->prepare("UPDATE tb_jadwal_imam SET hari = ?, id_guru = ? WHERE id = ?");
                $stmt->execute([$hari, $id_guru, $id]);
                $message = ['type' => 'success', 'text' => 'Jadwal berhasil diperbarui!'];
            } elseif ($_POST['action'] == 'delete') {
                $id = $_POST['id'];
                
                $stmt = $pdo->prepare("DELETE FROM tb_jadwal_imam WHERE id = ?");
                $stmt->execute([$id]);
                $message = ['type' => 'success', 'text' => 'Jadwal berhasil dihapus!'];
            }
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Terjadi kesalahan: ' . $e->getMessage()];
        }
    }
}

// Get Male Teachers
$stmt = $pdo->query("SELECT id_guru, nama_guru FROM tb_guru WHERE jenis_kelamin = 'Laki-laki' ORDER BY nama_guru ASC");
$male_teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Schedule Data
$stmt = $pdo->query("
    SELECT j.*, g.nama_guru 
    FROM tb_jadwal_imam j 
    JOIN tb_guru g ON j.id_guru = g.id_guru 
    ORDER BY FIELD(j.hari, 'Sabtu', 'Ahad', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat')
");
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Indonesian Days
$days = ['Sabtu', 'Ahad', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];

// Add Select2 CSS and JS
if (!isset($css_libs)) {
    $css_libs = [];
}
$css_libs[] = 'node_modules/select2/dist/css/select2.min.css';

if (!isset($js_libs)) {
    $js_libs = [];
}
$js_libs[] = 'node_modules/select2/dist/js/select2.full.min.js';

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Jadwal Imam Shalat Dhuha</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="#">Dashboard</a></div>
                <div class="breadcrumb-item"><a href="#">Master Data</a></div>
                <div class="breadcrumb-item">Jadwal Imam Dhuha</div>
            </div>
        </div>

        <div class="section-body">
            
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Daftar Jadwal Imam</h4>
                            <div class="card-header-action">
                                <button class="btn btn-primary" data-toggle="modal" data-target="#modalAdd">
                                    <i class="fas fa-plus"></i> Tambah Jadwal
                                </button>
                                <button class="btn btn-info" onclick="printSchedule()">
                                    <i class="fas fa-print"></i> Cetak
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- Printable Area -->
                            <div id="printableArea">
                                <div class="d-none d-print-block" style="border-bottom: 2px solid #000; margin-bottom: 20px; padding-bottom: 15px;">
                                    <div style="display: flex; align-items: center;">
                                        <div style="flex: 0 0 100px; text-align: left;">
                                            <img src="<?php echo $logo_path; ?>" alt="Logo" style="height: 80px; width: auto;">
                                        </div>
                                        <div style="flex: 1; text-align: center;">
                                            <h5 class="mb-0" style="font-weight: bold; margin: 0; font-size: 18px;">JADWAL IMAM SHALAT DHUHA</h5>
                                            <h5 class="mb-0" style="font-weight: bold; margin: 0; font-size: 18px;"><?php echo strtoupper($school_profile['nama_madrasah']); ?></h5>
                                            <h6 class="mb-0" style="font-weight: normal; margin: 0; font-size: 14px;">Tahun Ajaran <?php echo $school_profile['tahun_ajaran']; ?></h6>
                                        </div>
                                        <div style="flex: 0 0 100px;"></div>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-striped table-bordered table-md">
                                        <thead>
                                            <tr>
                                                <th class="text-center" width="10%">NO</th>
                                                <th class="text-center" width="30%">HARI</th>
                                                <th class="text-center">NAMA GURU</th>
                                                <th class="text-center d-print-none" width="15%">AKSI</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($schedules) > 0): ?>
                                                <?php $no = 1; foreach ($schedules as $row): ?>
                                                <tr>
                                                    <td><?php echo $no++; ?></td>
                                                    <td><?php echo strtoupper($row['hari']); ?></td>
                                                    <td>
                                                        <?php 
                                                        echo $row['nama_guru'];
                                                        ?>
                                                    </td>
                                                    <td class="text-center d-print-none">
                                                        <button class="btn btn-warning btn-sm" 
                                                                data-toggle="modal" 
                                                                data-target="#modalEdit<?php echo $row['id']; ?>">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn btn-danger btn-sm" 
                                                                data-toggle="modal" 
                                                                data-target="#modalDelete<?php echo $row['id']; ?>">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center">Belum ada jadwal.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Signature Section (Visible in Print) -->
                                <div class="d-none d-print-block mt-5">
                                    <div class="row">
                                        <div class="col-6"></div>
                                        <div class="col-6 text-center">
                                            <p class="mb-0">
                                                <?php echo $school_profile['tempat_jadwal'] ?? 'Jepara'; ?>, 
                                                <?php echo isset($school_profile['tanggal_jadwal']) ? formatDate($school_profile['tanggal_jadwal']) : date('d M Y'); ?>
                                            </p>
                                            <p class="mb-5">Kepala <?php echo $school_profile['nama_madrasah']; ?>,</p>
                                            <br>
                                            <p class="font-weight-bold mb-0"><u><?php echo $school_profile['kepala_madrasah']; ?></u></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Modal Add -->
<div class="modal fade" id="modalAdd" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Jadwal</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form action="" method="POST">
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
                        <label>Nama Guru (Laki-laki)</label>
                        <select class="form-control select2" name="id_guru" required style="width: 100%;">
                            <option value="">Pilih Guru</option>
                            <?php foreach ($male_teachers as $guru): ?>
                                <option value="<?php echo $guru['id_guru']; ?>">
                    <?php echo $guru['nama_guru']; ?>
                </option>
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

<!-- Modals Edit & Delete -->
<?php foreach ($schedules as $row): ?>
<div class="modal fade" id="modalEdit<?php echo $row['id']; ?>" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Jadwal</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form action="" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                    <div class="form-group">
                        <label>Hari</label>
                        <select class="form-control" name="hari" required>
                            <?php foreach ($days as $day): ?>
                                <option value="<?php echo $day; ?>" <?php echo ($row['hari'] == $day) ? 'selected' : ''; ?>>
                                    <?php echo $day; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nama Guru (Laki-laki)</label>
                        <select class="form-control select2" name="id_guru" required style="width: 100%;">
                            <?php foreach ($male_teachers as $guru): ?>
                                <option value="<?php echo $guru['id_guru']; ?>" <?php echo ($row['id_guru'] == $guru['id_guru']) ? 'selected' : ''; ?>>
                                    <?php echo $guru['nama_guru']; ?>
                                </option>
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
            <div class="modal-header">
                <h5 class="modal-title">Hapus Jadwal</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form action="" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                    <p>Apakah Anda yakin ingin menghapus jadwal hari <strong><?php echo $row['hari']; ?></strong> dengan imam <strong><?php echo $row['nama_guru']; ?></strong>?</p>
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

<?php include '../templates/footer.php'; ?>

<script>
<?php if ($message): ?>
Swal.fire({
    icon: '<?php echo $message['type'] == 'danger' ? 'error' : 'success'; ?>',
    title: '<?php echo $message['type'] == 'danger' ? 'Gagal' : 'Berhasil'; ?>',
    text: '<?php echo $message['text']; ?>',
    timer: 2000,
    showConfirmButton: false
});
<?php endif; ?>

function printSchedule() {
    window.print();
}

// Initialize Select2 in Modals
$(document).ready(function() {
    $('.select2').select2({
        dropdownParent: $('.modal')
    });
    
    // Fix for Select2 inside modal
    $('.modal').on('shown.bs.modal', function (e) {
        $(this).find('.select2').select2({
            dropdownParent: $(this)
        });
    });
});
</script>

<style>
@media print {
    .main-content {
        padding-left: 0;
        padding-top: 0;
    }
    .navbar, .main-sidebar, .main-footer, .section-header, .card-header, .alert {
        display: none !important;
    }
    .card {
        box-shadow: none;
        border: none;
    }
    .card-body {
        padding: 0;
    }
    /* Ensure table borders are visible in print */
    .table-bordered th, .table-bordered td {
        border: 1px solid #000 !important;
    }

    /* Force full width for table in print */
    table {
        width: 100% !important;
    }
    
    /* Centering logic for print */
    .text-center {
        text-align: center !important;
    }
}
</style>