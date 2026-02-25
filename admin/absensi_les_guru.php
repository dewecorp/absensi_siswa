<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has allowed level
if (!isAuthorized(['admin', 'tata_usaha', 'guru', 'wali', 'kepala_madrasah'])) {
    redirect('../login.php');
}

// Get school profile
$school_profile = getSchoolProfile($pdo);
$school_name = strtoupper($school_profile['nama_madrasah'] ?? 'Sistem Absensi Siswa');

// Set page title
$page_title = 'Absensi Les Guru';

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

// Check if there is a schedule for today
$today = date('Y-m-d');
$stmt_check_sched = $pdo->prepare("SELECT COUNT(*) FROM tb_jadwal_les WHERE tanggal = ?");
$stmt_check_sched->execute([$today]);
$has_schedule = $stmt_check_sched->fetchColumn() > 0;

// Handle single teacher submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['single_absensi'])) {
    if (!$has_schedule) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Tidak ada jadwal les untuk hari ini.']);
        exit;
    }
    $tanggal = date('Y-m-d');
    $id_guru = (int)($_POST['id_guru'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');
    $waktu_time = $_POST['waktu_input'] ?? null;
    $waktu_dt = $waktu_time ? ($tanggal . ' ' . $waktu_time) : date('Y-m-d H:i:s');
    $result = ['success' => false];
    
    $holiday = isSchoolHoliday($pdo, $tanggal);
    if ($holiday['is_holiday'] && $status !== '') {
        $result['error'] = 'Hari libur: ' . $holiday['name'] . '. Absensi untuk tanggal ini ditutup.';
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
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
    // Get current attendance status for today
    $stmt_att = $pdo->prepare("SELECT status, keterangan, waktu_input FROM tb_absensi_les_guru WHERE id_guru = ? AND tanggal = ?");
    $stmt_att->execute([$teacher['id_guru'], date('Y-m-d')]);
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
        $('.btn-absensi').attr('disabled', true).addClass('disabled');
        $('.keterangan-input').attr('disabled', true);
        Swal.fire({
            icon: 'info',
            title: 'Tidak Ada Jadwal',
            text: 'Tidak ada jadwal les untuk hari ini (" . formatDateIndonesia($today) . "). Absensi tidak dapat diisi.',
            confirmButtonText: 'Tutup'
        });
    });
    ";
}

$js_page[] = "
$(document).ready(function() {
    $('.select2').select2();
";

if (!$is_single_view) {
    $js_page[count($js_page)-1] .= "
    var table = $('#table-1').DataTable({
        \"columnDefs\": [
            { \"sortable\": false, \"targets\": [2, 3] }
        ],
        \"language\": {
            \"url\": \"//cdn.datatables.net/plug-ins/1.10.25/i18n/Indonesian.json\"
        },
        \"drawCallback\": function() {
            $('.status-input').each(function() {
                var id = $(this).data('id');
                var status = $(this).val();
                if (status) {
                    var statusLower = status.toLowerCase();
                    $('.btn-absensi[data-id=\' + id + \'][data-status=\' + statusLower + \']').addClass('active').css('opacity', '1');
                    if (statusLower === 'izin' || statusLower === 'sakit') {
                        $('#keterangan_container_' + id).show();
                    }
                }
            });
        }
    });
    ";
} else {
    $js_page[count($js_page)-1] .= "
        // Single view active state handling
        $('.status-input').each(function() {
            var id = $(this).data('id');
            var status = $(this).val();
            if (status) {
                var statusLower = status.toLowerCase();
                var btn = $('.btn-absensi[data-id=\' + id + \'][data-status=\' + statusLower + \']');
                btn.addClass('active').css('opacity', '1');
                
                var btnClass = '';
                if (statusLower === 'hadir') btnClass = 'btn-success';
                else if (statusLower === 'sakit') btnClass = 'btn-info';
                else if (statusLower === 'izin') btnClass = 'btn-warning';
                
                btn.removeClass('btn-outline-success btn-outline-info btn-outline-warning').addClass(btnClass);
            }
        });
    ";
}

$js_page[count($js_page)-1] .= "
    $(document).on('click', '.btn-absensi', function() {
        var id = $(this).data('id');
        var status = $(this).data('status');
        var row = $(this).closest('tr');
        
        var current = ($('#status_' + id).val() || '').toLowerCase();
        var isToggleCancel = current === String(status).toLowerCase();
        var newStatus = isToggleCancel ? '' : status;

        var rowButtons = $('.btn-absensi[data-id=\' + id + \']');
        rowButtons.removeClass('active').css('opacity', '0.6');
        
        // Handle visual feedback for new buttons
        rowButtons.each(function() {
            var btnStatus = $(this).data('status');
            var btnClass = '';
            if (btnStatus === 'hadir') btnClass = 'btn-success';
            else if (btnStatus === 'sakit') btnClass = 'btn-info';
            else if (btnStatus === 'izin') btnClass = 'btn-warning';
            
            $(this).removeClass(btnClass).addClass('btn-outline-' + btnStatus.replace('hadir', 'success').replace('sakit', 'info').replace('izin', 'warning'));
        });

        if (!isToggleCancel) {
            $(this).addClass('active').css('opacity', '1');
            var btnClass = '';
            if (status === 'hadir') btnClass = 'btn-success';
            else if (status === 'sakit') btnClass = 'btn-info';
            else if (status === 'izin') btnClass = 'btn-warning';
            $(this).removeClass('btn-outline-success btn-outline-info btn-outline-warning').addClass(btnClass);
        }
        
        $('#status_' + id).val(newStatus);
        var now = new Date();
        var timeStr = now.getHours().toString().padStart(2,'0') + ':' + 
                      now.getMinutes().toString().padStart(2,'0') + ':' + 
                      now.getSeconds().toString().padStart(2,'0');
        $('#waktu_' + id).val(timeStr);
        
        var badge = $('#badge_' + id);
        var statusLabel = newStatus ? (newStatus.charAt(0).toUpperCase() + newStatus.slice(1)) : 'Belum Absen';
        var badgeClass = 'badge-secondary';
        var bgColor = '';

        if (newStatus === 'hadir') {
            badgeClass = 'badge-success';
            bgColor = 'rgba(40, 167, 69, 0.1)';
        } else if (newStatus === 'sakit') {
            badgeClass = 'badge-info';
            bgColor = 'rgba(23, 162, 184, 0.1)';
        } else if (newStatus === 'izin') {
            badgeClass = 'badge-warning';
            bgColor = 'rgba(255, 193, 7, 0.1)';
        }

        badge.attr('class', 'badge ' + badgeClass).text(statusLabel);
        if (!<?= $is_single_view ? 'true' : 'false' ?>) {
            row.css('background-color', bgColor);
        }
        
        if (newStatus === 'izin' || newStatus === 'sakit') {
            $('#keterangan_container_' + id).show();
            $('#keterangan_' + id).focus();
        } else {
            $('#keterangan_container_' + id).hide();
            $('#keterangan_' + id).val('');
        }
        
        var ketVal = $('#keterangan_' + id).val() || '';
        var waktuVal = $('#waktu_' + id).val() || '';
        
        $.ajax({
            type: 'POST',
            url: window.location.href,
            data: { 
                single_absensi: 1, 
                id_guru: id, 
                status: newStatus, 
                keterangan: ketVal, 
                waktu_input: waktuVal 
            },
            success: function(resp) {
                if (resp && resp.success) {
                    Swal.fire({ icon: 'success', title: 'Tersimpan', text: (isToggleCancel ? 'Absensi dibatalkan' : 'Absensi les diperbarui'), timer: 1200, showConfirmButton: false });
                } else if (resp && resp.error) {
                    Swal.fire({ icon: 'warning', title: 'Hari Libur', text: resp.error, confirmButtonText: 'OK' }).then(function() {
                        window.location.reload();
                    });
                }
            }
        });
    });

    $(document).on('change', '.keterangan-input', function() {
        var id = $(this).attr('id').replace('keterangan_', '');
        var status = $('#status_' + id).val();
        var ketVal = $(this).val();
        var waktuVal = $('#waktu_' + id).val();
        
        if (status) {
            $.ajax({
                type: 'POST',
                url: window.location.href,
                data: { 
                    single_absensi: 1, 
                    id_guru: id, 
                    status: status, 
                    keterangan: ketVal, 
                    waktu_input: waktuVal 
                },
                success: function(resp) {
                    if (resp && resp.success) {
                        Swal.fire({ icon: 'success', title: 'Keterangan Tersimpan', timer: 1000, showConfirmButton: false });
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

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Absensi Les Guru (Kelas 6)</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Absensi</div>
                <div class="breadcrumb-item">Absensi Les Guru</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Data Absensi Les Guru - <?= formatDateIndonesia(date('Y-m-d')) ?></h4>
                            <?php if (!$has_schedule): ?>
                                <div class="card-header-action">
                                    <span class="badge badge-warning">Tidak Ada Jadwal Hari Ini</span>
                                </div>
                            <?php endif; ?>
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
                                                <button type="button" class="btn btn-outline-success btn-block btn-lg py-3 btn-absensi d-flex flex-column align-items-center" data-id="<?= $teacher['id_guru'] ?>" data-status="hadir">
                                                    <i class="fas fa-check-circle fa-2x mb-2"></i>
                                                    <span>Hadir</span>
                                                </button>
                                            </div>
                                            <div class="col-4 col-md-3">
                                                <button type="button" class="btn btn-outline-info btn-block btn-lg py-3 btn-absensi d-flex flex-column align-items-center" data-id="<?= $teacher['id_guru'] ?>" data-status="sakit">
                                                    <i class="fas fa-procedures fa-2x mb-2"></i>
                                                    <span>Sakit</span>
                                                </button>
                                            </div>
                                            <div class="col-4 col-md-3">
                                                <button type="button" class="btn btn-outline-warning btn-block btn-lg py-3 btn-absensi d-flex flex-column align-items-center" data-id="<?= $teacher['id_guru'] ?>" data-status="izin">
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
                                                        <button type="button" class="btn btn-success btn-absensi" data-id="<?= $teacher['id_guru'] ?>" data-status="hadir" style="opacity: 0.6;">
                                                            <i class="fas fa-check"></i> Hadir
                                                        </button>
                                                        <button type="button" class="btn btn-info btn-absensi" data-id="<?= $teacher['id_guru'] ?>" data-status="sakit" style="opacity: 0.6;">
                                                            <i class="fas fa-procedures"></i> Sakit
                                                        </button>
                                                        <button type="button" class="btn btn-warning btn-absensi" data-id="<?= $teacher['id_guru'] ?>" data-status="izin" style="opacity: 0.6;">
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
