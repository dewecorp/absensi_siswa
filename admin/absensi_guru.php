<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

// Get school profile
$school_profile = getSchoolProfile($pdo);
$school_name = strtoupper($school_profile['nama_madrasah'] ?? 'Sistem Absensi Siswa');
$logo_file = $school_profile['logo'] ?? 'logo.png';
$web_root = dirname(dirname($_SERVER['PHP_SELF']));
$web_root = $web_root == '/' || $web_root == '\\' ? '' : $web_root;
$logo_url = $web_root . '/assets/img/' . $logo_file;

// Set page title
$page_title = 'Absensi Guru';

// Handle form submissions (Save Attendance)
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['simpan_absensi'])) {
    $tanggal = date('Y-m-d');
    $id_kelas = $_POST['id_kelas'];
    $guru_data = $_POST['status']; // Array of id_guru => status
    $keterangan_data = $_POST['keterangan'] ?? []; // Array of id_guru => keterangan

    $success_count = 0;
    $error_count = 0;

    foreach ($guru_data as $id_guru => $status) {
        $keterangan = $keterangan_data[$id_guru] ?? '';
        
        // Default to 'Alpa' if status is empty
        if (empty($status)) {
            $status = 'Alpa';
        } else {
            // Ensure Title Case (Hadir, Sakit, Izin, Alpa)
            $status = ucfirst($status);
        }
        
        // Check if attendance already exists for this teacher on this day
        $check = $pdo->prepare("SELECT id_absensi FROM tb_absensi_guru WHERE id_guru = ? AND tanggal = ?");
        $check->execute([$id_guru, $tanggal]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Update
            $stmt = $pdo->prepare("UPDATE tb_absensi_guru SET status = ?, keterangan = ?, waktu_input = NOW() WHERE id_absensi = ?");
            if ($stmt->execute([$status, $keterangan, $existing['id_absensi']])) {
                $success_count++;
            } else {
                $error_count++;
            }
        } else {
            // Insert
            $stmt = $pdo->prepare("INSERT INTO tb_absensi_guru (id_guru, tanggal, status, keterangan, waktu_input) VALUES (?, ?, ?, ?, NOW())");
            if ($stmt->execute([$id_guru, $tanggal, $status, $keterangan])) {
                $success_count++;
            } else {
                $error_count++;
            }
        }
    }

    if ($success_count > 0) {
        $message = ['type' => 'success', 'text' => "Berhasil menyimpan $success_count data absensi guru."];
        $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
        logActivity($pdo, $username, 'Input Absensi Guru', "Menyimpan absensi guru untuk tanggal $tanggal");
    } else {
        $message = ['type' => 'danger', 'text' => 'Gagal menyimpan data absensi guru!'];
    }
}

// Get all classes for dropdown
$stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
$kelas_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get selected class
$selected_kelas_id = isset($_GET['kelas_id']) ? $_GET['kelas_id'] : '';
$teachers = [];

if ($selected_kelas_id) {
    $selected_kelas_nama = '';
    $selected_wali_kelas = '';

    // Only fetch class data if a specific class is selected (not 'all')
    if ($selected_kelas_id != 'all') {
        $stmt_cls = $pdo->prepare("SELECT nama_kelas, wali_kelas FROM tb_kelas WHERE id_kelas = ?");
        $stmt_cls->execute([$selected_kelas_id]);
        $cls_data = $stmt_cls->fetch(PDO::FETCH_ASSOC);
        $selected_kelas_nama = $cls_data ? $cls_data['nama_kelas'] : '';
        $selected_wali_kelas = $cls_data ? $cls_data['wali_kelas'] : '';
    }

    // Query to get teachers
    $stmt = $pdo->query("SELECT * FROM tb_guru ORDER BY nama_guru ASC");
    $all_teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($all_teachers as $teacher) {
        $include = false;
        
        // If 'all' is selected, include everyone
        if ($selected_kelas_id == 'all') {
            $include = true;
        } else {
            // Check 1: Is teacher the wali kelas?
            // Normalize strings for comparison (trim and lowercase) to handle whitespace issues
            $norm_wali = trim(strtolower($selected_wali_kelas));
            $norm_guru = trim(strtolower($teacher['nama_guru']));
            
            if ($norm_wali && $norm_wali === $norm_guru) {
                $include = true;
            }

            // Check 2: Does teacher teach in this class? (mengajar column)
            if (!$include) {
                // Simplified Logic: Check if Class ID or Class Name exists in 'mengajar' column
                // This covers JSON array, comma-separated string, or single value
                $mengajar_raw = $teacher['mengajar'] ?? '';
                
                if (!empty($mengajar_raw)) {
                    // Normalize inputs
                    $search_id = (string)$selected_kelas_id;
                    $search_name = trim($selected_kelas_nama);
                    
                    // Decode JSON if possible
                    $mengajar_list = json_decode($mengajar_raw, true);
                    
                    if (is_array($mengajar_list)) {
                        // Iterate through array items
                        foreach ($mengajar_list as $item) {
                            $item_str = trim((string)$item);
                            // Exact match for ID or case-insensitive match for Name
                            if ($item_str === $search_id || strcasecmp($item_str, $search_name) === 0) {
                                $include = true;
                                break;
                            }
                        }
                    } else {
                        // Raw string search (robust fallback)
                        // Check if ID exists as a standalone word/token (to avoid '1' matching '10', '11')
                        // Or check if Name exists
                        if (strpos($mengajar_raw, $search_id) !== false || stripos($mengajar_raw, $search_name) !== false) {
                            $include = true;
                        }
                    }
                }
            }
        }
        
        if ($include) {
            // Get current attendance status for today
            $stmt_att = $pdo->prepare("SELECT status, keterangan FROM tb_absensi_guru WHERE id_guru = ? AND tanggal = ?");
            $stmt_att->execute([$teacher['id_guru'], date('Y-m-d')]);
            $attendance = $stmt_att->fetch(PDO::FETCH_ASSOC);
            
            $teacher['status_kehadiran'] = $attendance['status'] ?? ''; // Default empty
            $teacher['keterangan'] = $attendance['keterangan'] ?? '';
            
            $teachers[] = $teacher;
        }
    }
}

// Define CSS libraries
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'https://cdn.datatables.net/select/1.3.3/css/select.bootstrap4.min.css', // CDN for datatables-select
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css' // CDN for Select2
];

// Define JS libraries
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.datatables.net/select/1.3.3/js/dataTables.select.min.js', // CDN for datatables-select
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js' // CDN for Select2
];

// Define page-specific JS
$js_page = [];

$js_page[] = "
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2();

    // Initialize DataTable
    $('#table-1').DataTable({
        \"columnDefs\": [
            { \"sortable\": false, \"targets\": [4] }
        ],
        \"language\": {
            \"lengthMenu\": \"Tampilkan _MENU_ entri\",
            \"zeroRecords\": \"Tidak ada data yang ditemukan\",
            \"info\": \"Menampilkan _START_ sampai _END_ dari _TOTAL_ entri\",
            \"infoEmpty\": \"Menampilkan 0 sampai 0 dari 0 entri\",
            \"infoFiltered\": \"(disaring dari _MAX_ total entri)\",
            \"search\": \"Cari:\",
            \"paginate\": {
                \"first\": \"Pertama\",
                \"last\": \"Terakhir\",
                \"next\": \"Selanjutnya\",
                \"previous\": \"Sebelumnya\"
            }
        }
    });

    // Handle Class Change
    $('#filter_kelas').change(function() {
        var kelasId = $(this).val();
        if (kelasId) {
            window.location.href = 'absensi_guru.php?kelas_id=' + kelasId;
        }
    });

    // Handle Attendance Buttons
    $(document).on('click', '.btn-absensi', function() {
        var id = $(this).data('id');
        var status = $(this).data('status');
        
        // Reset buttons for this row
        $('.btn-absensi[data-id=\"' + id + '\"]').removeClass('active').css('opacity', '0.6');
        
        // Highlight clicked button
        $(this).addClass('active').css('opacity', '1');
        
        // Set hidden input value
        $('#status_' + id).val(status);
        
        // Show/Hide Keterangan based on status 'izin' or 'sakit' (optional logic, user said 'jika izin muncul kolom keterangan')
        // User requirement: 'jika izin muncul kolom keterangan'
        if (status === 'izin' || status === 'sakit') {
            $('#keterangan_container_' + id).show();
            $('#keterangan_' + id).focus();
        } else {
            $('#keterangan_container_' + id).hide();
            $('#keterangan_' + id).val(''); // Clear text if present? Or keep it? Let's clear to avoid confusion.
        }
    });

    // Set initial state based on existing data
    $('.status-input').each(function() {
        var id = $(this).data('id');
        var status = $(this).val();
        if (status) {
            var statusLower = status.toLowerCase();
            $('.btn-absensi[data-id=\"' + id + '\"][data-status=\"' + statusLower + '\"]').addClass('active').css('opacity', '1');
            if (statusLower === 'izin' || statusLower === 'sakit') {
                $('#keterangan_container_' + id).show();
            }
        }
    });
});
";

// Add SweetAlert
if ($message) {
    $swal_icon = $message['type'] == 'success' ? 'success' : 'error';
    $swal_title = $message['type'] == 'success' ? 'Berhasil!' : 'Gagal!';
    $swal_text = json_encode($message['text']);
    
    $js_page[] = "
    Swal.fire({
        icon: '$swal_icon',
        title: '$swal_title',
        text: $swal_text,
        timer: 1500,
        showConfirmButton: false
    });";
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<!-- Main Content -->
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Absensi Guru</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="#">Dashboard</a></div>
                <div class="breadcrumb-item"><a href="#">Absensi</a></div>
                <div class="breadcrumb-item">Absensi Guru</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Filter Kelas</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Pilih Kelas</label>
                                        <select class="form-control select2" id="filter_kelas">
                                            <option value="">-- Pilih Kelas --</option>
                                            <option value="all" <?= $selected_kelas_id == 'all' ? 'selected' : '' ?>>Semua Guru</option>
                                            <?php foreach ($kelas_list as $kelas): ?>
                                                <option value="<?= $kelas['id_kelas'] ?>" <?= $selected_kelas_id == $kelas['id_kelas'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($kelas['nama_kelas']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($selected_kelas_id): ?>
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Data Absensi Guru - <?= date('d F Y') ?></h4>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="id_kelas" value="<?= $selected_kelas_id ?>">
                                <div class="table-responsive">
                                    <table class="table table-striped" id="table-1">
                                        <thead>
                                            <tr>
                                                <th class="text-center" width="5%">No</th>
                                                <th>Nama Guru</th>
                                                <th width="30%">Status Kehadiran</th>
                                                <th width="25%">Keterangan</th>
                                                <th width="10%">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $no = 1;
                                            foreach ($teachers as $teacher): 
                                                $bg_color = '';
                                                $status_lower = strtolower($teacher['status_kehadiran']);
                                                if ($status_lower == 'hadir') $bg_color = 'rgba(40, 167, 69, 0.1)';
                                                elseif ($status_lower == 'sakit') $bg_color = 'rgba(23, 162, 184, 0.1)';
                                                elseif ($status_lower == 'izin') $bg_color = 'rgba(255, 193, 7, 0.1)';
                                                elseif ($status_lower == 'alpa') $bg_color = 'rgba(220, 53, 69, 0.1)';
                                            ?>
                                            <tr style="background-color: <?= $bg_color ?>">
                                                <td class="text-center"><?= $no++ ?></td>
                                                <td><?= htmlspecialchars($teacher['nama_guru']) ?></td>
                                                <td>
                                                    <div class="btn-group" role="group">
                                                        <button type="button" class="btn btn-success btn-absensi" data-id="<?= $teacher['id_guru'] ?>" data-status="hadir" style="opacity: 0.6;">
                                                            <i class="fas fa-check"></i> Hadir
                                                        </button>
                                                        <button type="button" class="btn btn-info btn-absensi" data-id="<?= $teacher['id_guru'] ?>" data-status="sakit" style="opacity: 0.6;">
                                                            <i class="fas fa-procedures"></i> Sakit
                                                        </button>
                                                        <button type="button" class="btn btn-warning btn-absensi" data-id="<?= $teacher['id_guru'] ?>" data-status="izin" style="opacity: 0.6;">
                                                            <i class="fas fa-envelope-open-text"></i> Izin
                                                        </button>
                                                        <button type="button" class="btn btn-danger btn-absensi" data-id="<?= $teacher['id_guru'] ?>" data-status="alpa" style="opacity: 0.6;">
                                                            <i class="fas fa-times"></i> Alpa
                                                        </button>
                                                    </div>
                                                    <input type="hidden" name="status[<?= $teacher['id_guru'] ?>]" id="status_<?= $teacher['id_guru'] ?>" class="status-input" data-id="<?= $teacher['id_guru'] ?>" value="<?= $teacher['status_kehadiran'] ?>">
                                                </td>
                                                <td>
                                                    <div id="keterangan_container_<?= $teacher['id_guru'] ?>" style="display: none;">
                                                        <input type="text" class="form-control" name="keterangan[<?= $teacher['id_guru'] ?>]" id="keterangan_<?= $teacher['id_guru'] ?>" value="<?= htmlspecialchars($teacher['keterangan']) ?>" placeholder="Tulis keterangan...">
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($teacher['status_kehadiran']): ?>
                                                        <span class="badge badge-<?= $status_lower == 'hadir' ? 'success' : ($status_lower == 'sakit' ? 'info' : ($status_lower == 'izin' ? 'warning' : ($status_lower == 'alpa' ? 'danger' : 'secondary'))) ?>">
                                                            <?= ucfirst($teacher['status_kehadiran']) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary">Belum Absen</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-right mt-4">
                                    <button type="submit" name="simpan_absensi" class="btn btn-primary btn-lg">
                                        <i class="fas fa-save"></i> Simpan Absensi
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
