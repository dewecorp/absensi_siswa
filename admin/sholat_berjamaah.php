<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin, wali, or guru level
if (!isAuthorized(['admin', 'wali', 'guru', 'tata_usaha'])) {
    redirect('../login.php');
}

// Get school profile
$school_profile = getSchoolProfile($pdo);

// Get all classes
$stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Auto-save kehadiran sholat satu siswa (tanpa tombol simpan)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['single_absensi_siswa'])) {
    header('Content-Type: application/json');
    $tanggal_js = $_POST['tanggal'] ?? date('Y-m-d');
    $res = simadSaveSingleAttendance($pdo, 'sholat_berjamaah', (int)($_POST['id_siswa'] ?? 0), $tanggal_js, (string)($_POST['status'] ?? ''), null, false);
    echo json_encode($res);
    exit;
}

// Handle form submission for attendance
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_attendance'])) {
    $id_kelas = (int)$_POST['id_kelas'];
    $tanggal = $_POST['tanggal'];
    
    // Only process students that are actually in the POST data
    $saved_count = 0;
    foreach ($_POST as $key => $value) {
        // Check if this is a status field (status_[id_siswa])
        if (strpos($key, 'status_') === 0) {
            $id_siswa = (int)str_replace('status_', '', $key);
            $status = $value;
            
            // Validate status value
            if (!in_array($status, ['Hadir', 'Tidak Hadir', 'Berhalangan'])) {
                continue; // Skip invalid values
            }
            
            // Check if attendance already exists for this student and date
            $check_stmt = $pdo->prepare("SELECT * FROM tb_sholat WHERE id_siswa = ? AND tanggal = ?");
            $check_stmt->execute([$id_siswa, $tanggal]);
            
            if ($check_stmt->rowCount() > 0) {
                // Update existing record
                $update_stmt = $pdo->prepare("UPDATE tb_sholat SET status = ? WHERE id_siswa = ? AND tanggal = ?");
                $update_stmt->execute([$status, $id_siswa, $tanggal]);
            } else {
                // Insert new record
                $insert_stmt = $pdo->prepare("INSERT INTO tb_sholat (id_siswa, tanggal, status) VALUES (?, ?, ?)");
                $insert_stmt->execute([$id_siswa, $tanggal, $status]);
            }
            $saved_count++;
        }
    }
    
    $message = ['type' => 'success', 'text' => "Data sholat berjamaah berhasil disimpan untuk $saved_count siswa!"];
    $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
    logActivity($pdo, $username, 'Input Sholat Berjamaah', "Admin " . $username . " melakukan input sholat berjamaah kelas ID: $id_kelas untuk $saved_count siswa");
}

// Get students for selected class
$students = [];
$debug_info = [];
$class_info = []; // Initialize class info
if (isset($_GET['kelas']) && !empty($_GET['kelas'])) {
    $id_kelas = (int)$_GET['kelas'];
    
    // Get class info
    $stmt_class = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas = ?");
    $stmt_class->execute([$id_kelas]);
    $class_info = $stmt_class->fetch(PDO::FETCH_ASSOC) ?: [];
    
    $tanggal = isset($_GET['tanggal']) && !empty($_GET['tanggal']) ? $_GET['tanggal'] : date('Y-m-d');
    
    // Get all students in the class, with their attendance status if exists
    try {
        $stmt = $pdo->prepare("SELECT s.*, sh.status as status_sholat, ab.keterangan as status_absensi 
                               FROM tb_siswa s 
                               LEFT JOIN tb_sholat sh ON s.id_siswa = sh.id_siswa AND sh.tanggal = ? 
                               LEFT JOIN tb_absensi ab ON s.id_siswa = ab.id_siswa AND ab.tanggal = ?
                               WHERE s.id_kelas = ? 
                               ORDER BY s.nama_siswa ASC");
        $stmt->execute([$tanggal, $tanggal, $id_kelas]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $debug_info['students_found'] = count($students);
        $debug_info['id_kelas'] = $id_kelas;
        $debug_info['tanggal'] = $tanggal;
    } catch (Exception $e) {
        $debug_info['query_error'] = $e->getMessage();
        $students = [];
    }
} else {
    $tanggal = date('Y-m-d');
}

// Set page title
$page_title = 'Sholat Berjamaah';

// Define CSS libraries for this page
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'
];

include '../templates/header.php';
?>

<!-- Main Content -->
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Sholat Berjamaah</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4>Form Kehadiran Sholat Berjamaah</h4>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="<?php echo $_SERVER['PHP_SELF']; ?>" id="filterForm">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Kelas</label>
                                        <select class="form-control" name="kelas" id="kelasSelect" required>
                                            <option value="">Pilih Kelas</option>
                                            <?php foreach ($classes as $class): ?>
                                            <option value="<?php echo $class['id_kelas']; ?>" <?php echo (isset($_GET['kelas']) && $_GET['kelas'] == $class['id_kelas']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($class['nama_kelas']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Tanggal</label>
                                        <input type="date" class="form-control" name="tanggal" id="tanggalInput" value="<?php echo $tanggal; ?>" required>
                                    </div>
                                </div>
                            </div>
                        </form>
                        
                        <?php if (!empty($students)): ?>
                        <form method="POST" action="">
                            <input type="hidden" name="id_kelas" value="<?php echo $_GET['kelas']; ?>">
                            <input type="hidden" name="tanggal" value="<?php echo $tanggal; ?>">
                            <input type="hidden" name="save_attendance" value="1">
                            
                            <div class="att-grid">
                                <?php foreach ($students as $index => $student): ?>
                                <?php
                                    $status_sholat = $student['status_sholat'] ?? null;
                                    $status_absensi = $student['status_absensi'] ?? null;
                                    $is_absent_daily = in_array($status_absensi, ['Sakit', 'Izin', 'Alpa']);
                                    if ($is_absent_daily) { $current_status = 'Tidak Hadir'; }
                                    elseif ($status_sholat) { $current_status = $status_sholat; }
                                    else { $current_status = ''; }
                                    $is_locked = !empty($current_status);
                                    $stat_slug = $current_status ? strtolower(str_replace(' ', '-', $current_status)) : 'belum-absen';
                                    if ($current_status == 'Hadir') $badgeClass = 'badge-success';
                                    elseif ($current_status == 'Berhalangan') $badgeClass = 'badge-warning';
                                    elseif ($current_status == 'Tidak Hadir') $badgeClass = 'badge-danger';
                                    else $badgeClass = 'badge-secondary';
                                    $nm = trim((string)($student['nama_siswa'] ?? ''));
                                    $parts = preg_split('/\s+/', $nm);
                                    $ini = '';
                                    foreach (array_slice($parts, 0, 2) as $w) { if ($w !== '') $ini .= strtoupper(substr($w, 0, 1)); }
                                ?>
                                <div class="att-card">
                                    <div class="att-card-head">
                                        <div class="att-avatar att-avatar--<?= htmlspecialchars($stat_slug) ?>"><?= getPersonAvatarHtml($student['foto'] ?? '', htmlspecialchars($ini !== '' ? $ini : '?')) ?></div>
                                        <div class="att-card-id">
                                            <div class="att-name"><?= htmlspecialchars($nm) ?></div>
                                            <div class="att-sub">
                                                <span class="att-num">No <?= $index + 1 ?></span>
                                                <span class="badge <?= $badgeClass ?>" id="badge_<?= $student['id_siswa'] ?>"><?= htmlspecialchars($current_status ?: 'Belum Absen') ?></span>
                                            </div>
                                            <?php if ($is_absent_daily): ?>
                                                <small class="att-note-daily">(Absensi: <?= htmlspecialchars($status_absensi) ?>)</small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="att-actions">
                                        <label class="att-opt is-hadir <?= $current_status === 'Hadir' ? 'active' : '' ?>" onclick="updateBadgeLocal(this, <?= $student['id_siswa'] ?>, 'Hadir')">
                                            <input type="radio" name="status_<?= $student['id_siswa'] ?>" value="Hadir" <?= $current_status === 'Hadir' ? 'checked' : '' ?>><i class="fas fa-check"></i><span>Hadir</span>
                                        </label>
                                        <label class="att-opt is-tidakhadir <?= $current_status === 'Tidak Hadir' ? 'active' : '' ?>" onclick="updateBadgeLocal(this, <?= $student['id_siswa'] ?>, 'Tidak Hadir')">
                                            <input type="radio" name="status_<?= $student['id_siswa'] ?>" value="Tidak Hadir" <?= $current_status === 'Tidak Hadir' ? 'checked' : '' ?>><i class="fas fa-times"></i><span>Tidak Hadir</span>
                                        </label>
                                        <?php if ($student['jenis_kelamin'] == 'P'): ?>
                                        <label class="att-opt is-berhalangan <?= $current_status === 'Berhalangan' ? 'active' : '' ?>" onclick="updateBadgeLocal(this, <?= $student['id_siswa'] ?>, 'Berhalangan')">
                                            <input type="radio" name="status_<?= $student['id_siswa'] ?>" value="Berhalangan" <?= $current_status === 'Berhalangan' ? 'checked' : '' ?>><i class="fas fa-female"></i><span>Berhalangan</span>
                                        </label>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <p class="text-center text-muted mb-0"><i class="fas fa-info-circle"></i> Perubahan tersimpan otomatis saat memilih status.</p>
                        </form>
                        <?php elseif (isset($_GET['kelas']) && !empty($_GET['kelas'])): ?>
                        <div class="alert alert-info">
                            <p class="text-center mb-0">Belum ada siswa dalam kelas ini.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php
// Add DataTables JS libraries
$js_libs = [];
$js_libs[] = 'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js';
$js_libs[] = 'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js';

// Add page-specific JavaScript
$js_page = [];

// SweetAlert logic
if (isset($message)) {
    $js_page[] = "
    Swal.fire({
        title: '" . ($message['type'] === 'success' ? 'Sukses!' : 'Info!') . "',
        text: '" . addslashes($message['text']) . "',
        icon: '" . $message['type'] . "',
        timer: " . ($message['type'] === 'success' ? '3000' : '5000') . ",
        timerProgressBar: true,
        showConfirmButton: false
    });
    ";
}

$js_page[] = "
$(document).ready(function() {
    // Auto-submit when class is selected
    $('#kelasSelect').on('change', function() {
        var kelasId = $(this).val();
        if (kelasId && kelasId !== '') {
            $('#filterForm').submit();
        }
    });
    
    // Auto-submit when date is selected
    $('#tanggalInput').on('change', function() {
        var tanggal = $(this).val();
        var kelasId = $('#kelasSelect').val();
        if (tanggal && tanggal !== '' && kelasId && kelasId !== '') {
            $('#filterForm').submit();
        }
    });
    
    // Initialize DataTable (hanya jika tabel tersedia)
    if ($('#table-1').length) {
        $('#table-1').DataTable({
            'paging': false,
            'ordering': false,
            'info': false,
            'searching': true
        });
    }
});

function updateBadgeLocal(el, id, status) {
    if (!el) return;
    if ($(el).hasClass('active')) return false;

    var badge = $('#badge_' + id);
    badge.removeClass('badge-danger badge-success badge-warning badge-secondary');
    if (status === 'Hadir') {
        badge.addClass('badge-success').text('Hadir');
    } else if (status === 'Berhalangan') {
        badge.addClass('badge-warning').text('Berhalangan');
    } else {
        badge.addClass('badge-danger').text('Tidak Hadir');
    }

    var group = $(el).closest('.att-actions');
    if (group) {
        group.find('.att-opt').removeClass('active');
        $(el).addClass('active');
        $(el).find('input[type=\"radio\"]').prop('checked', true);
    }

    var card = el ? $(el).closest('.att-card') : null;
    if (card) {
        var avatar = card.find('.att-avatar');
        avatar.removeClass(function(i, c) { return (c.match(/att-avatar--\S+/g) || []).join(' '); });
        var slug = status.toLowerCase().replace(/\s+/g, '-');
        avatar.addClass('att-avatar--' + slug);
    }

    if (typeof simadAutoSaveAttendance === 'function') {
        simadAutoSaveAttendance(id, status);
    }
}
";

include '../templates/footer.php';
?>
