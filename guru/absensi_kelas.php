<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has guru level
if (!isAuthorized(['guru'])) {
    redirect('../login.php');
}

// Get school profile
$school_profile = getSchoolProfile($pdo);

// Get teacher information
if ($_SESSION['level'] == 'guru' || $_SESSION['level'] == 'wali') {
    // Direct login via NUPTK, user_id is actually the id_guru
    $stmt = $pdo->prepare("SELECT * FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    // Traditional login via tb_pengguna
    $stmt = $pdo->prepare("SELECT g.* FROM tb_guru g JOIN tb_pengguna p ON g.id_guru = p.id_guru WHERE p.id_pengguna = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Check if teacher was found
if (!$teacher) {
    die('Error: Teacher data not found');
}

// Ensure nama_guru is set in session for consistent navbar display
if (!isset($_SESSION['nama_guru']) || empty($_SESSION['nama_guru'])) {
    $_SESSION['nama_guru'] = $teacher['nama_guru'];
}

// Get classes that this teacher teaches (from mengajar field)
$classes = [];
if (!empty($teacher['mengajar'])) {
    $mengajar_decoded = json_decode($teacher['mengajar'], true);
    
    // Fallback: If not a valid JSON array, but contains comma separated values
    if ($mengajar_decoded === null && !empty($teacher['mengajar'])) {
        $mengajar_decoded = array_map('trim', explode(',', $teacher['mengajar']));
    }
    
    if (is_array($mengajar_decoded) && !empty($mengajar_decoded)) {
        // Get all classes first
        $all_classes_stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
        $all_classes = $all_classes_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Filter classes based on mengajar IDs
        foreach ($mengajar_decoded as $kelas_id) {
            // Handle both numeric IDs and string IDs, and also class names
            $kelas_id_int = is_numeric($kelas_id) ? (int)$kelas_id : null;
            
            foreach ($all_classes as $kelas) {
                $match = false;
                
                // Match by ID (numeric or string)
                if ($kelas_id_int !== null && $kelas['id_kelas'] == $kelas_id_int) {
                    $match = true;
                } elseif ((string)$kelas['id_kelas'] == (string)$kelas_id) {
                    $match = true;
                } elseif (strcasecmp($kelas['nama_kelas'], $kelas_id) === 0) {
                    // Also check if mengajar contains class names instead of IDs (case-insensitive)
                    $match = true;
                }
                
                if ($match) {
                    // Check if already added
                    $exists = false;
                    foreach ($classes as $existing_class) {
                        if ($existing_class['id_kelas'] == $kelas['id_kelas']) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $classes[] = $kelas;
                    }
                    break;
                }
            }
        }
    }
}

// Fallback: If teacher is a wali kelas (homeroom teacher), add their class
$stmt_wali = $pdo->prepare("SELECT * FROM tb_kelas WHERE wali_kelas = ?");
$stmt_wali->execute([$teacher['nama_guru']]);
$wali_class = $stmt_wali->fetch(PDO::FETCH_ASSOC);
if ($wali_class) {
    $exists = false;
    foreach ($classes as $existing_class) {
        if ($existing_class['id_kelas'] == $wali_class['id_kelas']) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        $classes[] = $wali_class;
    }
}

// Debug: Log classes found
error_log("Guru " . $teacher['nama_guru'] . " classes found: " . count($classes));

// If teacher only has one class, auto-select it if not already selected
if (count($classes) === 1 && (!isset($_GET['kelas']) || empty($_GET['kelas']))) {
    $_GET['kelas'] = $classes[0]['id_kelas'];
}

// Handle form submission for attendance
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_attendance'])) {
    $id_kelas = (int)$_POST['id_kelas'];
    $tanggal = $_POST['tanggal'];
    $holiday = isSchoolHoliday($pdo, $tanggal);
    if ($holiday['is_holiday']) {
        $message = ['type' => 'danger', 'text' => 'Hari libur: ' . $holiday['name'] . '. Absensi siswa tidak dapat disimpan untuk tanggal ini.'];
    } else {
        // Only process students that are actually in the POST data
        // This prevents DataTables pagination from affecting students on other pages
        $saved_count = 0;
        foreach ($_POST as $key => $value) {
            // Check if this is a keterangan field (keterangan_[id_siswa])
            if (strpos($key, 'keterangan_') === 0) {
                $id_siswa = (int)str_replace('keterangan_', '', $key);
                $keterangan = $value;

                // Check if attendance already exists for this student and date
                $check_stmt = $pdo->prepare("SELECT * FROM tb_absensi WHERE id_siswa = ? AND tanggal = ?");
                $check_stmt->execute([$id_siswa, $tanggal]);
                $existing_row = $check_stmt->fetch(PDO::FETCH_ASSOC);

                // Reset ke Belum Absen (klik ulang tombol aktif)
                if ($keterangan === '') {
                    if ($existing_row) {
                        $delete_stmt = $pdo->prepare("DELETE FROM tb_absensi WHERE id_siswa = ? AND tanggal = ?");
                        $delete_stmt->execute([$id_siswa, $tanggal]);
                        $saved_count++;
                    }
                    continue;
                }

                // Validate keterangan value
                if (!in_array($keterangan, ['Hadir', 'Sakit', 'Izin', 'Alpa'])) {
                    continue; // Skip invalid values
                }
                
                $current_time = date('H:i:s');
                
                if ($existing_row) {
                    // Update existing record
                    $update_stmt = $pdo->prepare("UPDATE tb_absensi SET keterangan = ?, jam_masuk = IF(? = 'Hadir', IF(jam_masuk IS NULL, ?, jam_masuk), NULL) WHERE id_siswa = ? AND tanggal = ?");
                    $update_stmt->execute([$keterangan, $keterangan, $current_time, $id_siswa, $tanggal]);
                } else {
                    // Insert new record
                    $id_guru = $_SESSION['user_id'];
                    $jam_masuk = ($keterangan == 'Hadir') ? $current_time : NULL;
                    $insert_stmt = $pdo->prepare("INSERT INTO tb_absensi (id_siswa, tanggal, keterangan, id_guru, jam_masuk) VALUES (?, ?, ?, ?, ?)");
                    $insert_stmt->execute([$id_siswa, $tanggal, $keterangan, $id_guru, $jam_masuk]);
                }
                $saved_count++;
            }
        }
        
        $message = ['type' => 'success', 'text' => "Data absensi berhasil disimpan untuk $saved_count siswa!"];
        logActivity($pdo, $teacher['nuptk'], 'Input Absensi', "Guru " . $teacher['nama_guru'] . " melakukan input absensi kelas ID: $id_kelas untuk $saved_count siswa");

        // Send notification to admin if data was saved
        if ($saved_count > 0) {
            $nama_guru = $teacher['nama_guru'];
            
            // Get class name
            $stmt_kelas = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas = ?");
            $stmt_kelas->execute([$id_kelas]);
            $kelas_data = $stmt_kelas->fetch(PDO::FETCH_ASSOC);
            $nama_kelas = $kelas_data ? $kelas_data['nama_kelas'] : 'Kelas ID ' . $id_kelas;
            
            $waktu = date('H:i');
            $tanggal_notif = date('d-m-Y');
            
            $notif_msg = "$nama_guru telah mengirim kehadiran siswa kelas $nama_kelas pada pukul $waktu tanggal $tanggal_notif";
            createNotification($pdo, $notif_msg, 'absensi_harian.php?kelas=' . $id_kelas . '&tanggal=' . $tanggal, 'absensi_siswa');
        }
    }
}

// Get students for selected class
$students = [];
$debug_info = [];
if (isset($_GET['kelas']) && !empty($_GET['kelas'])) {
    $id_kelas = (int)$_GET['kelas'];
    $tanggal = isset($_GET['tanggal']) && !empty($_GET['tanggal']) ? $_GET['tanggal'] : date('Y-m-d');
    
    // Debug: Check if class has students
    $check_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM tb_siswa WHERE id_kelas = ?");
    $check_stmt->execute([$id_kelas]);
    $class_check = $check_stmt->fetch(PDO::FETCH_ASSOC);
    $debug_info['total_students_in_class'] = $class_check['total'];
    
    // Get all students in the class, with their attendance status if exists
    try {
        $stmt = $pdo->prepare("SELECT s.*, a.keterangan 
                               FROM tb_siswa s 
                               LEFT JOIN tb_absensi a ON s.id_siswa = a.id_siswa AND a.tanggal = ? 
                               WHERE s.id_kelas = ? 
                               ORDER BY s.nama_siswa ASC");
        $stmt->execute([$tanggal, $id_kelas]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $debug_info['students_found'] = count($students);
        $debug_info['id_kelas'] = $id_kelas;
        $debug_info['tanggal'] = $tanggal;
        $debug_info['query_success'] = true;
    } catch (Exception $e) {
        $debug_info['query_error'] = $e->getMessage();
        $debug_info['query_success'] = false;
        $students = [];
    }
} else {
    $tanggal = date('Y-m-d');
}

// Set page title
$page_title = 'Absensi Harian';

// Define CSS libraries for this page
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'
];

// Define JS libraries for this page
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js'
];

// Prepare school name for JavaScript (escape it properly)
$school_name_js = htmlspecialchars($school_profile['nama_madrasah'], ENT_QUOTES, 'UTF-8');

// Add page-specific JavaScript
$js_page = [];
$js_page[] = "
// Auto-submit handler - ensure jQuery is loaded first
$(document).ready(function() {
    console.log('=== Absensi Harian Page Loaded ===');
    console.log('jQuery loaded:', typeof $ !== 'undefined');
    console.log('Form exists:', $('#filterForm').length > 0);
    console.log('Class select exists:', $('#kelasSelect').length > 0);
    console.log('Date input exists:', $('#tanggalInput').length > 0);
    console.log('Current GET kelas:', '" . (isset($_GET['kelas']) ? htmlspecialchars($_GET['kelas'], ENT_QUOTES, 'UTF-8') : '') . "');
    console.log('Current students count:', " . count($students) . ");
    
    // Auto-submit when class is selected
    $('#kelasSelect').on('change', function() {
        var kelasId = $(this).val();
        console.log('=== Class selected:', kelasId, '===');
        if (kelasId && kelasId !== '') {
            console.log('Auto-submitting form...');
            var form = $('#filterForm');
            if (form.length > 0) {
                console.log('Form found, submitting...');
                form.submit();
            } else {
                console.error('Form not found!');
            }
        }
    });
    
    // Auto-submit when date is selected
    $('#tanggalInput').on('change', function() {
        var tanggal = $(this).val();
        var kelasId = $('#kelasSelect').val();
        console.log('=== Date changed:', tanggal, 'Class:', kelasId, '===');
        if (tanggal && tanggal !== '' && kelasId && kelasId !== '') {
            console.log('Auto-submitting form...');
            $('#filterForm').submit();
        }
    });
});
";

// Tampilkan peringatan jika tanggal yang dipilih adalah hari libur
if (!empty($tanggal)) {
    $todayHoliday = isSchoolHoliday($pdo, $tanggal);
    if ($todayHoliday['is_holiday']) {
        $holiday_name = addslashes($todayHoliday['name']);
        $js_page[] = "
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: 'warning',
                title: 'Hari Libur',
                text: 'Hari ini adalah hari libur: $holiday_name. Absensi siswa ditutup untuk tanggal ini.',
                confirmButtonText: 'OK'
            });
        });
        ";
    }
}

// Add SweetAlert if message exists
if (isset($message)) {
    $js_page[] = "
    Swal.fire({
        title: '" . ($message['type'] === 'success' ? 'Sukses!' : 'Info!') . "',
        text: '" . addslashes($message['text']) . "',
        icon: '" . $message['type'] . "',
        timer: " . ($message['type'] === 'success' ? '1500' : '5000') . ",
        timerProgressBar: true,
        showConfirmButton: false
    });
    ";
}

// Add other page-specific functions
$js_page[] = "
// Pass actual names to JavaScript
var madrasahHeadName = '" . addslashes(htmlspecialchars($school_profile['kepala_madrasah'] ?? 'Kepala Madrasah', ENT_QUOTES, 'UTF-8')) . "';
var classTeacherName = '" . addslashes(htmlspecialchars($teacher['nama_guru'] ?? 'Guru Kelas', ENT_QUOTES, 'UTF-8')) . "';
var madrasahHeadSignature = '" . ($school_profile['ttd_kepala'] ?? '') . "';

function updateBadgeByValue(studentId, selectedValue) {
    var badge = $('#badge_' + studentId);
    badge.text(selectedValue ? selectedValue : 'Belum Absen');
    badge.removeClass('badge-success badge-info badge-warning badge-danger badge-secondary');
    switch(selectedValue) {
        case 'Hadir': badge.addClass('badge-success'); break;
        case 'Sakit': badge.addClass('badge-warning'); break;
        case 'Izin': badge.addClass('badge-info'); break;
        case 'Alpa': badge.addClass('badge-danger'); break;
        default: badge.addClass('badge-secondary');
    }
}

function initDataTable() {
    if (typeof $ === 'undefined' || typeof jQuery === 'undefined') {
        console.warn('jQuery not loaded, retrying...');
        setTimeout(initDataTable, 100);
        return;
    }
    if (typeof $.fn.DataTable === 'undefined') {
        console.warn('DataTables library not loaded, retrying...');
        setTimeout(initDataTable, 100);
        return;
    }
    if ($.fn.DataTable.isDataTable('#table-1')) {
        $('#table-1').DataTable().destroy();
    }
    $('#table-1').DataTable({
        \"columnDefs\": [
            { \"orderable\": false, \"targets\": [2] }
        ],
        \"paging\": false,
        \"dom\": 'lfrtip',
        \"info\": true,
        \"language\": {
            \"lengthMenu\": \"Tampilkan _MENU_ entri\",
            \"zeroRecords\": \"Tidak ada data yang ditemukan\",
            \"info\": \"Menampilkan _TOTAL_ siswa\",
            \"infoEmpty\": \"Menampilkan 0 sampai 0 dari 0 entri\",
            \"infoFiltered\": \"(disaring dari _MAX_ total entri)\",
            \"search\": \"Cari:\",
        }
    });
}
$(document).ready(function() {
    initDataTable();

    $(document).on('click', '.btn-absensi-siswa', function() {
        var studentId = $(this).data('id');
        var status = $(this).data('status');
        var input = $('#status_' + studentId);
        var currentStatus = input.val();
        var nextStatus = (currentStatus === status) ? '' : status;
        var group = $(this).closest('.attendance-btn-group');

        group.find('.btn-absensi-siswa').removeClass('active');
        if (nextStatus !== '') {
            group.find('.btn-absensi-siswa[data-status=\"' + nextStatus + '\"]').addClass('active');
        }

        input.val(nextStatus);
        updateBadgeByValue(studentId, nextStatus);
    });
    
    // Handle form submission to ensure all inputs are sent
    // Intercept form submission to collect all select values from all DataTables pages
    $(document).on('submit', 'form', function(e) {
        var form = $(this);
        var table = $('#table-1');
        
        // Only process attendance form (has save_attendance input)
        if (!form.find('input[name=\"save_attendance\"]').length) {
            return; // Let other forms submit normally
        }
        
        // If DataTable is initialized, collect all status values
        if ($.fn.DataTable.isDataTable('#table-1')) {
            e.preventDefault(); // Prevent default submission
            e.stopPropagation(); // Stop event propagation
            
            var dt = table.DataTable();
            var currentPage = dt.page();
            var currentPageLength = dt.page.len();
            var pageInfo = dt.page.info();
            var allSelectValues = {};
            
            // Temporarily show all rows to collect all current values
            dt.page.len(-1).draw(false);
            
            // Wait for DOM to update, then collect all values
            setTimeout(function() {
                // Collect all status hidden values from all rows (now all visible)
                var collectedCount = 0;
                table.find('tbody input.student-status-input[name^=\"keterangan_\"]').each(function() {
                    var select = $(this);
                    var name = select.attr('name');
                    var value = select.val();
                    
                    if (name) {
                        allSelectValues[name] = value;
                        collectedCount++;
                    }
                });
                
                console.log('Collected ' + collectedCount + ' values from DOM');
                console.log('Total values: ' + Object.keys(allSelectValues).length + ' (expected: ' + pageInfo.recordsTotal + ')');
                
                // Verify we have all values
                if (Object.keys(allSelectValues).length < pageInfo.recordsTotal) {
                    console.warn('Warning: Not all values collected! Expected ' + pageInfo.recordsTotal + ', got ' + Object.keys(allSelectValues).length);
                }
                
                // Restore pagination
                dt.page.len(currentPageLength).page(currentPage).draw(false);
                
                // Remove any existing hidden inputs with the same names
                form.find('input[type=\"hidden\"][name^=\"keterangan_\"]').remove();
                
                // Add hidden inputs for all select values
                var inputCount = 0;
                $.each(allSelectValues, function(name, value) {
                    var hiddenInput = $('<input>').attr({
                        type: 'hidden',
                        name: name,
                        value: value
                    });
                    form.append(hiddenInput);
                    inputCount++;
                });
                
                console.log('Added ' + inputCount + ' hidden inputs to form');
                
                // Submit the form using native submit
                form.off('submit'); // Remove this handler to avoid infinite loop
                
                // Use native form submit
                var formElement = form[0];
                if (formElement && formElement.submit) {
                    formElement.submit();
                } else {
                    form.submit();
                }
            }, 500);
        }
    });
});
";

include '../templates/user_header.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Absensi Harian</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Absensi Harian</div>
            </div>
        </div>

        <?php if (isset($message)): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    title: '<?php echo $message['type'] === 'success' ? 'Sukses!' : 'Info!'; ?>',
                    text: '<?php echo addslashes($message['text']); ?>',
                    icon: '<?php echo $message['type']; ?>',
                    timer: <?php echo $message['type'] === 'success' ? '3000' : '5000'; ?>,
                    timerProgressBar: true,
                    showConfirmButton: false
                });
            });
        </script>
        <?php endif; ?>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4>Form Absensi Harian</h4>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="<?php echo $_SERVER['PHP_SELF']; ?>" id="filterForm">
                            <div class="row">
                                <?php if (count($classes) > 1): ?>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Kelas</label>
                                        <select class="form-control" name="kelas" id="kelasSelect" required>
                                            <option value="">Pilih Kelas</option>
                                            <?php if (empty($classes)): ?>
                                            <option value="" disabled>Anda belum terdaftar mengajar di kelas manapun. Silakan hubungi Admin.</option>
                                            <?php else: ?>
                                            <?php foreach ($classes as $class): ?>
                                            <option value="<?php echo $class['id_kelas']; ?>" <?php echo (isset($_GET['kelas']) && $_GET['kelas'] == $class['id_kelas']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($class['nama_kelas']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                        <?php if (empty($classes)): ?>
                                        <small class="form-text text-danger">Guru ini belum memiliki kelas yang diajar. Silakan hubungi admin untuk mengatur kelas yang diajar.</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Kelas</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($classes[0]['nama_kelas'] ?? ''); ?>" readonly>
                                        <input type="hidden" name="kelas" id="kelasSelect" value="<?php echo $classes[0]['id_kelas'] ?? ''; ?>">
                                    </div>
                                </div>
                                <?php endif; ?>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Tanggal</label>
                                        <input type="date" class="form-control" name="tanggal" id="tanggalInput" value="<?php echo $tanggal; ?>" required>
                                    </div>
                                </div>
                            </div>
                        </form>
                        
                        <?php 
                        // Debug output
                        if (isset($_GET['kelas']) && !empty($_GET['kelas'])) {
                            echo '<!-- Debug: GET kelas = ' . htmlspecialchars($_GET['kelas']) . ' -->';
                            echo '<!-- Debug: Students count = ' . count($students) . ' -->';
                            echo '<!-- Debug: Total students in class = ' . ($debug_info['total_students_in_class'] ?? 'N/A') . ' -->';
                        }
                        ?>
                        <?php if (!empty($students)): ?>
                        <form method="POST" action="">
                            <input type="hidden" name="id_kelas" value="<?php echo $_GET['kelas']; ?>">
                            <input type="hidden" name="tanggal" value="<?php echo $tanggal; ?>">
                            <input type="hidden" name="save_attendance" value="1">
                            
                            <div class="table-responsive">
                                <table class="table table-striped" id="table-1">
                                    <thead>
                                        <tr>
                                            <th>No</th>
                                            <th>Nama Siswa</th>
                                            <th>Status Kehadiran</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $index => $student): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($student['nama_siswa']); ?>
                                                <span class="ml-2 badge <?php 
                                                    $status = $student['keterangan'] ?? 'Hadir'; 
                                                    switch($status) {
                                                        case 'Hadir':
                                                            echo 'badge-success';
                                                            break;
                                                        case 'Sakit':
                                                            echo 'badge-warning';
                                                            break;
                                                        case 'Izin':
                                                            echo 'badge-info';
                                                            break;
                                                        case 'Alpa':
                                                            echo 'badge-danger';
                                                            break;
                                                        default:
                                                            echo 'badge-secondary';
                                                    }
                                                ?>" id="badge_<?php echo $student['id_siswa']; ?>">
                                                    <?php echo $status; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php $status_now = $student['keterangan'] ?? 'Hadir'; ?>
                                                <div class="btn-group btn-group-sm attendance-btn-group" role="group">
                                                    <button type="button" class="btn btn-success btn-absensi-siswa <?php echo $status_now === 'Hadir' ? 'active' : ''; ?>" data-id="<?php echo $student['id_siswa']; ?>" data-status="Hadir"><i class="fas fa-check"></i> Hadir</button>
                                                    <button type="button" class="btn btn-warning btn-absensi-siswa <?php echo $status_now === 'Sakit' ? 'active' : ''; ?>" data-id="<?php echo $student['id_siswa']; ?>" data-status="Sakit"><i class="fas fa-procedures"></i> Sakit</button>
                                                    <button type="button" class="btn btn-info btn-absensi-siswa <?php echo $status_now === 'Izin' ? 'active' : ''; ?>" data-id="<?php echo $student['id_siswa']; ?>" data-status="Izin"><i class="fas fa-envelope-open-text"></i> Izin</button>
                                                    <button type="button" class="btn btn-danger btn-absensi-siswa <?php echo $status_now === 'Alpa' ? 'active' : ''; ?>" data-id="<?php echo $student['id_siswa']; ?>" data-status="Alpa"><i class="fas fa-user-times"></i> Alpa</button>
                                                </div>
                                                <input type="hidden" class="student-status-input" name="keterangan_<?php echo $student['id_siswa']; ?>" id="status_<?php echo $student['id_siswa']; ?>" value="<?php echo htmlspecialchars($status_now, ENT_QUOTES); ?>">
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="row mt-4">
                                <div class="col-12 text-center">
                                    <button type="submit" class="btn btn-primary">Simpan Absensi</button>
                                </div>
                            </div>
                        </form>
                        <?php elseif (isset($_GET['kelas']) && !empty($_GET['kelas'])): ?>
                        <div class="alert alert-info">
                            <p class="text-center mb-0">
                                <?php 
                                // Check if class exists and has students
                                $check_class = $pdo->prepare("SELECT COUNT(*) as total FROM tb_siswa WHERE id_kelas = ?");
                                $check_class->execute([(int)$_GET['kelas']]);
                                $class_info = $check_class->fetch(PDO::FETCH_ASSOC);
                                
                                if ($class_info['total'] == 0) {
                                    echo 'Belum ada siswa dalam kelas ini.';
                                } else {
                                    echo 'Data siswa ditemukan (' . $class_info['total'] . ' siswa), tetapi query tidak mengembalikan hasil. ';
                                    echo 'Kelas ID: ' . (int)$_GET['kelas'] . ', Tanggal: ' . htmlspecialchars($tanggal);
                                }
                                ?>
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php
include '../templates/user_footer.php';
?>
