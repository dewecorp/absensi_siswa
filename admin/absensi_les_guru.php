<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has allowed level
if (!isAuthorized(['admin', 'tata_usaha', 'guru', 'wali'])) {
    redirect('../login.php');
}

// Get school profile
$school_profile = getSchoolProfile($pdo);
$school_name = strtoupper($school_profile['nama_madrasah'] ?? 'Sistem Informasi Madrasah');

// Set page title
$page_title = 'Kehadiran Les Guru';

// Get current user's guru ID if applicable
$current_guru_id = null;
$user_level = getUserLevel();
if (in_array($user_level, ['guru', 'wali'])) {
    if (isset($_SESSION['user_id'])) {
        $id_check = $_SESSION['user_id'];
        if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
            $stmt_uid = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
            $stmt_uid->execute([$id_check]);
            $current_guru_id = $stmt_uid->fetchColumn();
        } else {
            $current_guru_id = $id_check;
        }
    }
}

// Get current date or from GET
$selected_date = isset($_GET['tanggal']) ? $_GET['tanggal'] : date('Y-m-d');
$is_admin_exclusive = in_array($user_level, ['admin', 'tata_usaha', 'kepala_madrasah']);

// Check if there is a schedule for selected date
$stmt_check_sched = $pdo->prepare("SELECT COUNT(*) FROM tb_jadwal_les WHERE tanggal = ?");
$stmt_check_sched->execute([$selected_date]);
$has_schedule = $stmt_check_sched->fetchColumn() > 0;

// Handle single teacher submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['single_absensi'])) {
    $tanggal = $_POST['tanggal'] ?? date('Y-m-d');
    
    // Only admin can fill for dates other than today
    if ($tanggal !== date('Y-m-d') && !$is_admin_exclusive) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Hanya Admin yang dapat mengisi kehadiran untuk tanggal yang sudah lewat.']);
        exit;
    }

    $stmt_check_sched_post = $pdo->prepare("SELECT COUNT(*) FROM tb_jadwal_les WHERE tanggal = ?");
    $stmt_check_sched_post->execute([$tanggal]);
    if ($stmt_check_sched_post->fetchColumn() <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Tidak ada jadwal les untuk tanggal ini.']);
        exit;
    }

    $id_guru = (int)($_POST['id_guru'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');
    $waktu_time = $_POST['waktu_input'] ?? null;
    $waktu_dt = $waktu_time ? ($tanggal . ' ' . $waktu_time) : date('Y-m-d H:i:s');
    $result = ['success' => false];
    
    // Validate if teacher is marking attendance for their own scheduled class
    if ($id_guru > 0 && in_array($user_level, ['guru', 'wali']) && !$is_admin_exclusive) {
        // Check if the teacher is trying to mark attendance for themselves
        if ($current_guru_id != $id_guru) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Anda hanya dapat mengisi kehadiran untuk diri sendiri.']);
            exit;
        }
        
        // Check if teacher has a les schedule for that date
        $stmt_check_schedule = $pdo->prepare("
            SELECT COUNT(*) 
            FROM tb_jadwal_les jl
            WHERE jl.id_guru = ? 
            AND jl.tanggal = ?
        ");
        $stmt_check_schedule->execute([$id_guru, $tanggal]);
        $has_teacher_les_schedule = $stmt_check_schedule->fetchColumn() > 0;
        
        if (!$has_teacher_les_schedule) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Anda tidak memiliki jadwal les untuk tanggal ini.']);
            exit;
        }
    }
    
    if ($id_guru > 0) {
        $check = $pdo->prepare("SELECT id_absensi FROM tb_absensi_les_guru WHERE id_guru = ? AND tanggal = ?");
        $check->execute([$id_guru, $tanggal]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);
        try {
            if ($existing) {
                if ($status !== '') {
                    $stmt = $pdo->prepare("UPDATE tb_absensi_les_guru SET status = ?, keterangan = ?, waktu_input = ? WHERE id_absensi = ?");
                    if ($stmt->execute([ucfirst($status), $keterangan, $waktu_dt, $existing['id_absensi']])) {
                        $result['success'] = true;
                        
                        // Add notification for Admin/Kepala
                        if (in_array($user_level, ['guru', 'wali'])) {
                            $nama_guru = $_SESSION['nama_guru'] ?? $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Guru';
                            $role_label = ($user_level == 'wali') ? 'Wali' : 'Guru';
                            $msg = "$nama_guru ($role_label) telah memperbarui kehadiran les pada " . date('d-m-Y H:i');
                            createNotification($pdo, $msg, 'absensi_les_guru.php');
                        }
                    }
                } else {
                    $stmt = $pdo->prepare("DELETE FROM tb_absensi_les_guru WHERE id_absensi = ?");
                    if ($stmt->execute([$existing['id_absensi']])) {
                        $result['success'] = true;
                    }
                }
            } else {
                if ($status !== '') {
                    $stmt = $pdo->prepare("INSERT INTO tb_absensi_les_guru (id_guru, tanggal, status, keterangan, waktu_input) VALUES (?, ?, ?, ?, ?)");
                    if ($stmt->execute([$id_guru, $tanggal, ucfirst($status), $keterangan, $waktu_dt])) {
                        $result['success'] = true;
                        
                        // Add notification for Admin/Kepala
                        if (in_array($user_level, ['guru', 'wali'])) {
                            $nama_guru = $_SESSION['nama_guru'] ?? $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Guru';
                            $role_label = ($user_level == 'wali') ? 'Wali' : 'Guru';
                            $msg = "$nama_guru ($role_label) telah mengisi kehadiran les (dirinya) pada " . date('d-m-Y H:i');
                            createNotification($pdo, $msg, 'absensi_les_guru.php');
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $result['success'] = false;
        }
    }
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// Get Grade 6 Teachers
$stmt = $pdo->prepare("
    SELECT DISTINCT g.id_guru, g.nama_guru 
    FROM tb_guru g
    JOIN tb_jadwal_pelajaran j ON g.id_guru = j.guru_id
    WHERE j.kelas_id = 6
    UNION
    SELECT id_guru, nama_guru
    FROM tb_guru
    WHERE nama_guru IN (SELECT wali_kelas FROM tb_kelas WHERE id_kelas = 6)
    ORDER BY nama_guru ASC
");
$stmt->execute();
$teachers_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filter teachers list if it's a teacher/wali
$is_single_view = false;
if ($current_guru_id && !in_array($user_level, ['admin', 'tata_usaha', 'kepala_madrasah'])) {
    $is_single_view = true;
    $teachers_list = array_filter($teachers_list, function($t) use ($current_guru_id) {
        return $t['id_guru'] == $current_guru_id;
    });
}

$teachers = [];
foreach ($teachers_list as $teacher) {
    // Get current attendance status for selected date
    $stmt_att = $pdo->prepare("SELECT status, keterangan, waktu_input FROM tb_absensi_les_guru WHERE id_guru = ? AND tanggal = ?");
    $stmt_att->execute([$teacher['id_guru'], $selected_date]);
    $attendance = $stmt_att->fetch(PDO::FETCH_ASSOC);
    
    $teacher['status_kehadiran'] = $attendance['status'] ?? ''; 
    $teacher['keterangan'] = $attendance['keterangan'] ?? '';
    $teacher['waktu_input'] = $attendance['waktu_input'] ?? '';
    
    $teachers[] = $teacher;
}

// Define CSS libraries
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'https://cdn.datatables.net/select/1.3.3/css/select.bootstrap4.min.css',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css'
];

// Define JS libraries
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.datatables.net/select/1.3.3/js/dataTables.select.min.js',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js'
];

// Define page-specific JS
$js_page = [];

if (!$has_schedule) {
    $js_page[] = "
    $(document).ready(function() {
        $('.btn-kehadiran').attr('disabled', true).addClass('disabled');
        $('.keterangan-input').attr('disabled', true);
        Swal.fire({
            icon: 'info',
            title: 'Tidak Ada Jadwal',
            text: 'Tidak ada jadwal les untuk tanggal " . formatDateIndonesia($selected_date) . ". Kehadiran tidak dapat diisi.',
            confirmButtonText: 'Tutup'
        });
    });
    ";
}

$js_page[] = "
$(document).ready(function() {
    $('.select2').select2();
    
    // Variable dari PHP
    var isSingleView = " . ($is_single_view ? 'true' : 'false') . ";
    var selectedDate = '" . $selected_date . "';
    
    console.log('isSingleView:', isSingleView);
    
    // Button click handler
    $(document).on('click', '.btn-kehadiran', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var id = $(this).attr('data-id');
        var status = $(this).attr('data-status');
        var row = $(this).closest('tr');
        
        console.log('Button clicked - ID:', id, 'Status:', status);
        
        // Get current status
        var currentStatus = $('#status_' + id).val() || '';
        var isToggle = currentStatus.toLowerCase() === status.toLowerCase();
        var newStatus = isToggle ? '' : status;
        
        console.log('Current:', currentStatus, 'Is Toggle:', isToggle, 'New Status:', newStatus);
        
        // Reset all buttons
        $('.btn-kehadiran[data-id=' + id + ']').each(function() {
            var btnStat = $(this).attr('data-status');
            $(this).removeClass('active btn-success btn-info btn-warning')
                    .addClass('btn-outline-' + (btnStat == 'hadir' ? 'success' : (btnStat == 'sakit' ? 'info' : 'warning')))
                    .css('opacity', '0.6');
        });
        
        // Set active button if not toggling off
        if (!isToggle && newStatus) {
            var activeBtn = $('.btn-kehadiran[data-id=' + id + '][data-status=' + newStatus + ']');
            activeBtn.addClass('active').css('opacity', '1');
            
            if (newStatus == 'hadir') {
                activeBtn.removeClass('btn-outline-success').addClass('btn-success');
            } else if (newStatus == 'sakit') {
                activeBtn.removeClass('btn-outline-info').addClass('btn-info');
            } else if (newStatus == 'izin') {
                activeBtn.removeClass('btn-outline-warning').addClass('btn-warning');
            }
        }
        
        // Update hidden field
        $('#status_' + id).val(newStatus);
        
        // Update time
        var now = new Date();
        var timeStr = String(now.getHours()).padStart(2,'0') + ':' + 
                      String(now.getMinutes()).padStart(2,'0') + ':' + 
                      String(now.getSeconds()).padStart(2,'0');
        $('#waktu_' + id).val(timeStr);
        
        // Update badge
        var badgeText = newStatus ? (newStatus.charAt(0).toUpperCase() + newStatus.slice(1)) : 'Belum Absen';
        var badgeClass = newStatus == 'hadir' ? 'badge-success' : 
                        (newStatus == 'sakit' ? 'badge-info' : 
                        (newStatus == 'izin' ? 'badge-warning' : 'badge-secondary'));
        $('#badge_' + id).attr('class', 'badge ' + badgeClass).text(badgeText);
        
        // Update row background
        if (!isSingleView && row.length > 0) {
            var bgColor = '';
            if (newStatus == 'hadir') bgColor = 'rgba(40, 167, 69, 0.1)';
            else if (newStatus == 'sakit') bgColor = 'rgba(23, 162, 184, 0.1)';
            else if (newStatus == 'izin') bgColor = 'rgba(255, 193, 7, 0.1)';
            row.css('background-color', bgColor);
        }
        
        // Show/hide keterangan
        if (newStatus == 'izin' || newStatus == 'sakit') {
            $('#keterangan_container_' + id).show();
            $('#keterangan_' + id).focus();
        } else {
            $('#keterangan_container_' + id).hide();
            $('#keterangan_' + id).val('');
        }
        
        // AJAX request
        var ketVal = $('#keterangan_' + id).val() || '';
        var waktuVal = $('#waktu_' + id).val() || '';
        
        console.log('Sending AJAX - ID:', id, 'Status:', newStatus, 'Ket:', ketVal);
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                single_absensi: 1,
                id_guru: id,
                status: newStatus,
                keterangan: ketVal,
                waktu_input: waktuVal,
                tanggal: selectedDate
            },
            dataType: 'json',
            success: function(response) {
                console.log('Response:', response);
                if (response && response.success) {
                    var msg = isToggle ? 'Kehadiran les berhasil dibatalkan' : 'Kehadiran les berhasil disimpan';
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil',
                        text: msg,
                        timer: 2000,
                        showConfirmButton: false
                    });
                } else if (response && response.error) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal!',
                        text: response.error,
                        confirmButtonText: 'OK'
                    }).then(function() {
                        location.reload();
                    });
                }
            },
            error: function(xhr, status, err) {
                console.error('AJAX Error:', status, err);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Gagal menyimpan kehadiran. Silakan coba lagi.'
                });
            }
        });
    });
    
    // Keterangan change handler
    $(document).on('change', '.keterangan-input', function() {
        var id = $(this).attr('id').replace('keterangan_', '');
        var status = $('#status_' + id).val();
        var val = $(this).val();
        var timeVal = $('#waktu_' + id).val();
        
        if (status) {
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: {
                    single_absensi: 1,
                    id_guru: id,
                    status: status,
                    keterangan: val,
                    waktu_input: timeVal,
                    tanggal: selectedDate
                },
                dataType: 'json',
                success: function(resp) {
                    if (resp && resp.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Keterangan Tersimpan',
                            timer: 1000,
                            showConfirmButton: false
                        });
                    }
                }
            });
        }
    });
});
";

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<style>
.btn-kehadiran {
    font-size: 1.15rem;
    font-weight: 600;
    border-width: 2px;
    transition: all .15s ease-in-out;
}
.btn-kehadiran i { font-size: 1.55rem; }
.btn-kehadiran:hover { transform: translateY(-1px); }

/* Filled color for single-view buttons */
.btn-kehadiran.btn-outline-success {
    background-color: #28a745;
    color: #fff;
    border-color: #28a745;
}
.btn-kehadiran.btn-outline-info {
    background-color: #17a2b8;
    color: #fff;
    border-color: #17a2b8;
}
.btn-kehadiran.btn-outline-warning {
    background-color: #ffc107;
    color: #212529;
    border-color: #ffc107;
}

/* Table action buttons: stronger colors and bigger text */
.table .btn-absensi {
    font-size: 1rem;
    font-weight: 600;
}
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Kehadiran Les Guru (Kelas 6)</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Data Kehadiran Les Guru - <?= formatDateIndonesia($selected_date) ?></h4>
                            <div class="card-header-action">
                                <?php if ($is_admin_exclusive): ?>
                                    <form method="GET" action="" class="form-inline">
                                        <div class="input-group">
                                            <input type="date" name="tanggal" class="form-control" value="<?= $selected_date ?>" onchange="this.form.submit()">
                                        </div>
                                    </form>
                                <?php elseif (!$has_schedule): ?>
                                    <span class="badge badge-warning">Tidak Ada Jadwal Hari Ini</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if ($is_single_view): ?>
                                <!-- Single Teacher View (Dashboard Style) -->
                                <?php 
                                $teacher = reset($teachers); 
                                if ($teacher):
                                    $status_lower = strtolower($teacher['status_kehadiran']);
                                ?>
                                    <div class="form-group text-center">
                                        <label class="d-block font-weight-bold mb-3">Status Kehadiran Les Hari Ini (<?php echo formatDateIndonesia(date('Y-m-d')); ?>)</label>
                                        <div class="row justify-content-center">
                                            <div class="col-4 col-md-3">
                                                <button type="button" class="btn btn-outline-success btn-block btn-lg py-3 btn-kehadiran d-flex flex-column align-items-center" data-id="<?= $teacher['id_guru'] ?>" data-status="hadir">
                                                    <i class="fas fa-check-circle fa-2x mb-2"></i>
                                                    <span>Hadir</span>
                                                </button>
                                            </div>
                                            <div class="col-4 col-md-3">
                                                <button type="button" class="btn btn-outline-info btn-block btn-lg py-3 btn-kehadiran d-flex flex-column align-items-center" data-id="<?= $teacher['id_guru'] ?>" data-status="sakit">
                                                    <i class="fas fa-procedures fa-2x mb-2"></i>
                                                    <span>Sakit</span>
                                                </button>
                                            </div>
                                            <div class="col-4 col-md-3">
                                                <button type="button" class="btn btn-outline-warning btn-block btn-lg py-3 btn-kehadiran d-flex flex-column align-items-center" data-id="<?= $teacher['id_guru'] ?>" data-status="izin">
                                                    <i class="fas fa-envelope-open-text fa-2x mb-2"></i>
                                                    <span>Izin</span>
                                                </button>
                                            </div>
                                        </div>
                                        <input type="hidden" id="status_<?= $teacher['id_guru'] ?>" class="status-input" data-id="<?= $teacher['id_guru'] ?>" value="<?= $teacher['status_kehadiran'] ?>">
                                        <input type="hidden" id="waktu_<?= $teacher['id_guru'] ?>" value="<?= $teacher['waktu_input'] ? date('H:i:s', strtotime($teacher['waktu_input'])) : '' ?>">
                                    </div>

                                    <div class="form-group" id="keterangan_container_<?= $teacher['id_guru'] ?>" style="display: <?php echo in_array($status_lower, ['izin', 'sakit']) ? 'block' : 'none'; ?>;">
                                        <label class="font-weight-bold">Keterangan</label>
                                        <textarea class="form-control keterangan-input" id="keterangan_<?= $teacher['id_guru'] ?>" placeholder="Tulis alasan jika sakit atau izin..."><?= htmlspecialchars($teacher['keterangan']) ?></textarea>
                                    </div>

                                    <div class="mt-3">
                                        <span class="text-muted">Status: </span>
                                        <span id="badge_<?= $teacher['id_guru'] ?>" class="badge badge-<?= $status_lower == 'hadir' ? 'success' : ($status_lower == 'sakit' ? 'info' : ($status_lower == 'izin' ? 'warning' : 'secondary')) ?>">
                                            <?= $teacher['status_kehadiran'] ?: 'Belum Absen' ?>
                                        </span>
                                    </div>
                                <?php endif; ?>

                            <?php else: ?>
                                <!-- Admin View (Table) -->
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
                                            ?>
                                            <tr style="background-color: <?= $bg_color ?>">
                                                <td class="text-center"><?= $no++ ?></td>
                                                <td><?= htmlspecialchars($teacher['nama_guru']) ?></td>
                                                <td>
                                                    <div class="btn-group" role="group">
                                                        <button type="button" class="btn btn-success btn-kehadiran" data-id="<?= $teacher['id_guru'] ?>" data-status="hadir">
                                                            <i class="fas fa-check"></i> Hadir
                                                        </button>
                                                        <button type="button" class="btn btn-info btn-kehadiran" data-id="<?= $teacher['id_guru'] ?>" data-status="sakit">
                                                            <i class="fas fa-procedures"></i> Sakit
                                                        </button>
                                                        <button type="button" class="btn btn-warning btn-kehadiran" data-id="<?= $teacher['id_guru'] ?>" data-status="izin">
                                                            <i class="fas fa-envelope-open-text"></i> Izin
                                                        </button>
                                                    </div>
                                                    <input type="hidden" id="status_<?= $teacher['id_guru'] ?>" class="status-input" data-id="<?= $teacher['id_guru'] ?>" value="<?= $teacher['status_kehadiran'] ?>">
                                                    <input type="hidden" id="waktu_<?= $teacher['id_guru'] ?>" value="<?= $teacher['waktu_input'] ? date('H:i:s', strtotime($teacher['waktu_input'])) : '' ?>">
                                                </td>
                                                <td>
                                                    <div id="keterangan_container_<?= $teacher['id_guru'] ?>" style="display: none;">
                                                        <input type="text" class="form-control keterangan-input" id="keterangan_<?= $teacher['id_guru'] ?>" value="<?= htmlspecialchars($teacher['keterangan']) ?>" placeholder="Tulis keterangan...">
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($teacher['status_kehadiran']): ?>
                                                        <span id="badge_<?= $teacher['id_guru'] ?>" class="badge badge-<?= $status_lower == 'hadir' ? 'success' : ($status_lower == 'sakit' ? 'info' : ($status_lower == 'izin' ? 'warning' : 'secondary')) ?>">
                                                            <?= ucfirst($teacher['status_kehadiran']) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span id="badge_<?= $teacher['id_guru'] ?>" class="badge badge-secondary">Belum Absen</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
