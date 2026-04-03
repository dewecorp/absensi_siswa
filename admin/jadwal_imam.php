<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin, kepala_madrasah, tata_usaha, guru, or wali level
if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha', 'guru', 'wali'])) {
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

// Get user level to determine permissions
$user_level = getUserLevel();
$is_admin = ($user_level === 'admin');

// Handle Form Submission (Admin only)
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $is_admin) {
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
                            <?php if ($is_admin): ?>
                            <div class="card-header-action">
                                <button class="btn btn-primary" data-toggle="modal" data-target="#modalAdd">
                                    <i class="fas fa-plus"></i> Tambah Jadwal
                                </button>
                                <button class="btn btn-info" onclick="printSchedule(event)">
                                    <i class="fas fa-print"></i> Cetak
                                </button>
                            </div>
                            <?php else: ?>
                            <div class="card-header-action">
                                <button class="btn btn-info" onclick="printSchedule(event)">
                                    <i class="fas fa-print"></i> Cetak
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <!-- Printable Area -->
                            <div id="printableArea">
                                <div class="d-none d-print-block" style="border-bottom: 2px solid #000; margin-bottom: 20px; padding-bottom: 15px;">
                                    <div style="display: flex; align-items: center; justify-content: space-between;">
                                        <div style="flex: 0 0 80px; text-align: left;">
                                            <img src="<?php echo $logo_path; ?>" alt="Logo" style="height: 60px; width: auto;">
                                        </div>
                                        <div style="flex: 1; text-align: center;">
                                            <h3 class="mb-0" style="font-weight: bold; margin: 0; font-size: 20px;">JADWAL IMAM SHALAT DHUHA</h3>
                                            <h4 class="mb-0" style="font-weight: bold; margin: 0; font-size: 16px;"><?php echo strtoupper($school_profile['nama_madrasah']); ?></h4>
                                            <p class="mb-0" style="font-weight: normal; margin: 0; font-size: 12px;">Tahun Ajaran <?php echo $school_profile['tahun_ajaran']; ?></p>
                                        </div>
                                        <div style="flex: 0 0 80px;"></div>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-striped table-bordered table-md">
                                        <thead>
                                            <tr>
                                                <th class="text-center" width="10%">NO</th>
                                                <th class="text-center" width="30%">HARI</th>
                                                <th class="text-center">NAMA GURU</th>
                                                <?php if ($is_admin): ?>
                                                <th class="text-center d-print-none" width="15%">AKSI</th>
                                                <?php endif; ?>
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
                                                    <?php if ($is_admin): ?>
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
                                                    <?php endif; ?>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="<?php echo $is_admin ? '4' : '3'; ?>" class="text-center">Belum ada jadwal.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Signature Section (Visible in Print) -->
                                <div class="d-none d-print-block mt-5">
                                    <div class="row">
                                        <div class="col-6"></div>
                                        <div class="col-6">
                                            <div style="text-align: center;">
                                                <p class="mb-0">
                                                    <?php echo $school_profile['tempat_jadwal'] ?? 'Jepara'; ?>, 
                                                    <?php 
                                                    // Format tanggal dengan nama bulan lengkap bahasa Indonesia
                                                    if (isset($school_profile['tanggal_jadwal']) && !empty($school_profile['tanggal_jadwal'])) {
                                                        $bulan_indonesia = [
                                                            'January' => 'Januari',
                                                            'February' => 'Februari',
                                                            'March' => 'Maret',
                                                            'April' => 'April',
                                                            'May' => 'Mei',
                                                            'June' => 'Juni',
                                                            'July' => 'Juli',
                                                            'August' => 'Agustus',
                                                            'September' => 'September',
                                                            'October' => 'Oktober',
                                                            'November' => 'November',
                                                            'December' => 'Desember'
                                                        ];
                                                        $date_obj = new DateTime($school_profile['tanggal_jadwal']);
                                                        $month_en = $date_obj->format('F');
                                                        $month_id = $bulan_indonesia[$month_en];
                                                        echo $date_obj->format('d') . ' ' . $month_id . ' ' . $date_obj->format('Y');
                                                    } else {
                                                        echo date('d M Y');
                                                    }
                                                    ?>
                                                </p>
                                                <p class="mb-1">Kepala <?php echo $school_profile['nama_madrasah']; ?>,</p>
                                                <div style="height: 5px;"></div>
                                                <?php 
                                                // Check if digital signature exists
                                                $signature_path = '';
                                                if (!empty($school_profile['ttd_digital']) && file_exists(__DIR__ . '/../uploads/ttd/' . $school_profile['ttd_digital'])) {
                                                    $signature_path = '../uploads/ttd/' . $school_profile['ttd_digital'];
                                                }
                                                // Generate QR Code for verification
                                                $verification_data = [
                                                    'nama' => $school_profile['kepala_madrasah'],
                                                    'jabatan' => 'Kepala Madrasah',
                                                    'madrasah' => $school_profile['nama_madrasah'],
                                                    'tanggal_cetak' => date('Y-m-d H:i:s')
                                                ];
                                                $qr_data = json_encode($verification_data);
                                                $qrcode_url = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($qr_data);
                                                ?>
                                                <div style="display: inline-block; line-height: 0;">
                                                    <div style="display: flex; align-items: center; justify-content: center; gap: 6px; line-height: 0;">
                                                        <?php if ($signature_path): ?>
                                                        <img src="<?php echo $signature_path; ?>" alt="Tanda Tangan" style="height: 70px; width: auto; display: block; margin: 0; padding: 0; line-height: 0;">
                                                        <?php endif; ?>
                                                        <img src="<?php echo $qrcode_url; ?>" alt="QR Code" style="height: 60px; width: 60px; display: block; margin: 0; padding: 0; line-height: 0;">
                                                    </div>
                                                </div>
                                                <p class="font-weight-bold mb-0"><?php echo $school_profile['kepala_madrasah']; ?></p>

                                            </div>
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
<?php if ($is_admin): ?>
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
<?php endif; ?>

<!-- Modals Edit & Delete -->
<?php foreach ($schedules as $row): ?>
<?php if ($is_admin): ?>
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
<?php endif; ?>
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

// Print function - open new tab
var printWindow = null; // Store reference to print window

function printSchedule(event) {
    event.preventDefault();
    event.stopPropagation();
    
    var printContents = document.getElementById('printableArea').innerHTML;
    
    // Get tahun ajaran for filename
    var tahunAjaran = '<?php echo $school_profile['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1); ?>';
    var filename = 'jadwal_imam_dhuha_' + tahunAjaran.replace(/\//g, '_') + '.pdf';
    
    // Check if window is already open and not closed
    if (printWindow && !printWindow.closed) {
        // Update content in existing window
        updatePrintWindow(printWindow, printContents, tahunAjaran);
        printWindow.focus();
    } else {
        // Create a completely new window/tab
        printWindow = window.open('', '_blank');
        
        if (!printWindow) {
            alert('Browser memblokir popup. Izinkan popup untuk mencetak.');
            return false;
        }
        
        // Write complete HTML document
        writePrintContent(printWindow, printContents, tahunAjaran);
        
        // Wait for content to load then print
        printWindow.onload = function() {
            setTimeout(function() {
                printWindow.focus();
                printWindow.print();
            }, 300);
        };
    }
    
    return false;
}

// Function to write content to print window
function writePrintContent(window, content, tahunAjaran) {
    // Store initial content in window for reload reference
    window.initialContent = content;
    window.tahunAjaran = tahunAjaran;
    
    window.document.write('<!DOCTYPE html>');
    window.document.write('<html lang="id">');
    window.document.write('<head>');
    window.document.write('<meta charset="UTF-8">');
    window.document.write('<meta name="viewport" content="width=device-width, initial-scale=1.0">');
    window.document.write('<title>Jadwal Imam Dhuha ' + tahunAjaran + '</title>');
    window.document.write('<style>');
    window.document.write('@page { size: A4 landscape; margin: 20mm; }');
    window.document.write('body { font-family: "Bookman Old Style", "Georgia", serif; padding: 30px; background: white; }');
    window.document.write('.table-bordered { border-collapse: collapse !important; width: 100%; }');
    window.document.write('.table-bordered th, .table-bordered td { border: 1px solid #000 !important; padding: 8px; }');
    window.document.write('.table-striped tbody tr:nth-of-type(odd) { background-color: rgba(0,0,0,.05); }');
    window.document.write('h3, h4, p { font-family: "Bookman Old Style", "Georgia", serif; text-align: center; }');
    window.document.write('img { margin: 0 !important; padding: 0 !important; vertical-align: middle; }');
    window.document.write('* { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }');
    window.document.write('</style>');
    window.document.write('</head>');
    window.document.write('<body>');
    window.document.write(content);
    window.document.write('</body>');
    window.document.write('</html>');
    window.document.close();
    
    // Prevent default reload by intercepting it
    window.addEventListener('beforeunload', function(e) {
        // Restore content after reload
        setTimeout(function() {
            if (window.opener && !window.opener.closed) {
                var freshContent = window.opener.document.getElementById('printableArea').innerHTML;
                updatePrintWindow(window, freshContent, window.tahunAjaran);
            } else {
                // Fallback to last known content
                updatePrintWindow(window, window.initialContent, window.tahunAjaran);
            }
        }, 100);
    });
}

// Function to update print window with fresh content
function updatePrintWindow(pWin, content, tahunAjaran) {
    pWin.document.open();
    pWin.document.write('<!DOCTYPE html>');
    pWin.document.write('<html lang="id">');
    pWin.document.write('<head>');
    pWin.document.write('<meta charset="UTF-8">');
    pWin.document.write('<meta name="viewport" content="width=device-width, initial-scale=1.0">');
    pWin.document.write('<title>Jadwal Imam Dhuha ' + tahunAjaran + '</title>');
    pWin.document.write('<style>');
    pWin.document.write('@page { size: A4 landscape; margin: 20mm; }');
    pWin.document.write('body { font-family: "Bookman Old Style", "Georgia", serif; padding: 30px; background: white; }');
    pWin.document.write('.table-bordered { border-collapse: collapse !important; width: 100%; }');
    pWin.document.write('.table-bordered th, .table-bordered td { border: 1px solid #000 !important; padding: 8px; }');
    pWin.document.write('.table-striped tbody tr:nth-of-type(odd) { background-color: rgba(0,0,0,.05); }');
    pWin.document.write('h3, h4, p { font-family: "Bookman Old Style", "Georgia", serif; text-align: center; }');
    pWin.document.write('img { margin: 0 !important; padding: 0 !important; vertical-align: middle; }');
    pWin.document.write('* { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }');
    pWin.document.write('</style>');
    pWin.document.write('</head>');
    pWin.document.write('<body>');
    pWin.document.write(content);
    pWin.document.write('</body>');
    pWin.document.write('</html>');
    pWin.document.close();
}

</script>

<style>
@media print {
    body * {
        visibility: hidden;
    }
    #printableArea, #printableArea * {
        visibility: visible;
    }
    #printableArea {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
    }
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
