<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin, wali, or guru level
if (!isAuthorized(['admin', 'wali', 'guru'])) {
    redirect('../login.php');
}

// Get school profile
$school_profile = getSchoolProfile($pdo);

// Get teacher information
if (isset($_SESSION['nama_guru']) && !empty($_SESSION['nama_guru'])) {
    $teacher_name = $_SESSION['nama_guru'];
} else {
    // For traditional login via tb_pengguna, get teacher name
    if ($_SESSION['level'] == 'wali' || $_SESSION['level'] == 'guru') {
        // Check if user_id is id_guru or id_pengguna
        $stmt = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $teacher_result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$teacher_result) {
            // Try to find via tb_pengguna
            $stmt = $pdo->prepare("SELECT g.nama_guru FROM tb_guru g JOIN tb_pengguna p ON g.id_guru = p.id_guru WHERE p.id_pengguna = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $teacher_result = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } else {
        // Traditional login via tb_pengguna
        $stmt = $pdo->prepare("SELECT g.nama_guru FROM tb_guru g JOIN tb_pengguna p ON g.id_guru = p.id_guru WHERE p.id_pengguna = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $teacher_result = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    $teacher_name = $teacher_result['nama_guru'] ?? $_SESSION['username'];
    
    // Ensure nama_guru is set in session for consistent navbar display
    if ($teacher_result && isset($teacher_result['nama_guru'])) {
        $_SESSION['nama_guru'] = $teacher_result['nama_guru'];
    }
}

// Get the class that the wali teaches
$wali_kelas_stmt = $pdo->prepare("SELECT id_kelas, nama_kelas FROM tb_kelas WHERE wali_kelas = ?");
$wali_kelas_stmt->execute([$teacher_name]);
$wali_kelas = $wali_kelas_stmt->fetch(PDO::FETCH_ASSOC);

// Handle form submission for attendance
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_attendance'])) {
    // Time validation: only 07:00-14:00 for student attendance
    $now_hour = (int)date('H');
    if ($now_hour < 7 || $now_hour >= 14) {
        $message = ['type' => 'danger', 'text' => 'Kehadiran siswa hanya dapat diisi pukul 07:00 - 14:00 WIB.'];
    } else {
    $id_kelas = (int)$wali_kelas['id_kelas']; // Use the wali's class
    $tanggal = $_POST['tanggal'];
    $holiday = isSchoolHoliday($pdo, $tanggal);
    if ($holiday['is_holiday']) {
        $message = ['type' => 'danger', 'text' => 'Hari libur: ' . $holiday['name'] . '. Kehadiran siswa tidak dapat disimpan untuk tanggal ini.'];
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
                    // For admin users logging in as wali, id_guru should be NULL
                    $id_guru = ($_SESSION['level'] === 'admin') ? NULL : $_SESSION['user_id'];
                    $jam_masuk = ($keterangan == 'Hadir') ? $current_time : NULL;
                    $insert_stmt = $pdo->prepare("INSERT INTO tb_absensi (id_siswa, tanggal, keterangan, id_guru, jam_masuk) VALUES (?, ?, ?, ?, ?)");
                    $insert_stmt->execute([$id_siswa, $tanggal, $keterangan, $id_guru, $jam_masuk]);
                }
                $saved_count++;
            }
        }
        
        $message = ['type' => 'success', 'text' => "Data kehadiran berhasil disimpan untuk $saved_count siswa!"];
        
        // Send notification to admin
        if ($saved_count > 0) {
            $nama_wali = $_SESSION['nama_guru'] ?? 'Wali Kelas';
            $nama_kelas_notif = $wali_kelas ? $wali_kelas['nama_kelas'] : 'Kelas';
            $notif_msg = "$nama_wali telah melakukan input kehadiran siswa $nama_kelas_notif ($saved_count siswa)";
            createNotification($pdo, $notif_msg, 'rekap_absensi.php', 'absensi_siswa');
        }

        $username = isset($_SESSION['username']) ? $_SESSION['username'] : (isset($teacher['nuptk']) ? $teacher['nuptk'] : 'system');
        $log_result = logActivity($pdo, $username, 'Input Absensi', "Wali " . $username . " melakukan input absensi harian kelas ID: $id_kelas untuk $saved_count siswa");
        if (!$log_result) error_log("Failed to log activity for Input Kehadiran: kelas ID $id_kelas");
    }
    }
}

// Get students for the wali's class
$students = [];
if ($wali_kelas) {
    $id_kelas = $wali_kelas['id_kelas'];
    $tanggal = $_GET['tanggal'] ?? date('Y-m-d');
    
    $stmt = $pdo->prepare("SELECT s.*, a.keterangan FROM tb_siswa s LEFT JOIN tb_absensi a ON s.id_siswa = a.id_siswa AND a.tanggal = ? WHERE s.id_kelas = ? ORDER BY s.nama_siswa ASC");
    $stmt->execute([$tanggal, $id_kelas]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $tanggal = date('Y-m-d');
}

// Set page title
$page_title = 'Kehadiran Harian';

// Define CSS libraries for this page
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'
];

include '../templates/user_header.php';
?>
<?php if (!empty($tanggal)) :
    $todayHoliday = isSchoolHoliday($pdo, $tanggal);
    if ($todayHoliday['is_holiday']) :
        $holiday_name = addslashes($todayHoliday['name']); ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: 'warning',
                title: 'Hari Libur',
                text: 'Hari ini adalah hari libur: <?php echo $holiday_name; ?>. Kehadiran siswa ditutup untuk tanggal ini.',
                confirmButtonText: 'OK'
            });
        });
        </script>
<?php
    endif;
endif;
?>

            <!-- Main Content -->
            <div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Kehadiran Harian Kelas <?php echo $wali_kelas ? htmlspecialchars($wali_kelas['nama_kelas']) : 'Tidak Ada'; ?></h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                            <div class="breadcrumb-item">Kehadiran Harian Kelas Terpilih</div>
                        </div>
                    </div>



                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Form Kehadiran Harian Kelas <?php echo $wali_kelas ? htmlspecialchars($wali_kelas['nama_kelas']) : 'Tidak Ada'; ?></h4>
                                </div>
                                <div class="card-body">
                                    <form method="GET" action="" id="filterForm">
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>Tanggal</label>
                                                    <input type="date" class="form-control" name="tanggal" id="tanggalInput" value="<?php echo $tanggal; ?>" required>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                    
                                    <script>
                                        document.getElementById('tanggalInput').addEventListener('change', function() {
                                            document.getElementById('filterForm').submit();
                                        });
                                    </script>
                                    
                                    <?php if (!empty($students)): ?>
                                    <form method="POST" action="">
                                        <input type="hidden" name="id_kelas" value="<?php echo $wali_kelas['id_kelas']; ?>">
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
                                                                $status = $student['keterangan'] ?? 'Hadir'; // Set default to 'Hadir'
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
                                                                <button type="button" class="btn btn-success btn-kehadiran-siswa <?php echo $status_now === 'Hadir' ? 'active' : ''; ?>" data-id="<?php echo $student['id_siswa']; ?>" data-status="Hadir"><i class="fas fa-check"></i> Hadir</button>
                                                                <button type="button" class="btn btn-warning btn-kehadiran-siswa <?php echo $status_now === 'Sakit' ? 'active' : ''; ?>" data-id="<?php echo $student['id_siswa']; ?>" data-status="Sakit"><i class="fas fa-procedures"></i> Sakit</button>
                                                                <button type="button" class="btn btn-info btn-kehadiran-siswa <?php echo $status_now === 'Izin' ? 'active' : ''; ?>" data-id="<?php echo $student['id_siswa']; ?>" data-status="Izin"><i class="fas fa-envelope-open-text"></i> Izin</button>
                                                                <button type="button" class="btn btn-danger btn-kehadiran-siswa <?php echo $status_now === 'Alpa' ? 'active' : ''; ?>" data-id="<?php echo $student['id_siswa']; ?>" data-status="Alpa"><i class="fas fa-user-times"></i> Alpa</button>
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
                                                <button type="submit" class="btn btn-primary">Simpan Kehadiran</button>
                                            </div>
                                        </div>
                                    </form>
                                    <?php elseif ($wali_kelas): ?>
                                    <div class="alert alert-info">
                                        <p class="text-center mb-0">Belum ada siswa dalam kelas ini.</p>
                                    </div>
                                    <?php else: ?>
                                    <div class="alert alert-warning">
                                        <p class="text-center mb-0">Anda belum ditugaskan sebagai wali kelas untuk kelas apapun.</p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

<?php
// Prepare JS Libraries
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.datatables.net/select/1.3.3/js/dataTables.select.min.js'
];

// Prepare Page Specific JS
$js_page = [];

// SweetAlert logic
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

// Main Page JS
$js_page[] = "
    // Pass actual names to JavaScript
    var madrasahHeadName = '" . addslashes(htmlspecialchars($school_profile['kepala_madrasah'] ?? 'Kepala Madrasah', ENT_QUOTES, 'UTF-8')) . "';
    var classTeacherName = '" . addslashes(htmlspecialchars($teacher_name ?? 'Wali Kelas', ENT_QUOTES, 'UTF-8')) . "';
    var madrasahHeadSignature = '" . ($school_profile['ttd_kepala'] ?? '') . "';
    var schoolName = '" . addslashes($school_profile['nama_madrasah'] ?? 'Madrasah') . "';

    function updateBadgeByValue(studentId, selectedValue) {
        var badge = $('#badge_' + studentId);
        badge.text(selectedValue ? selectedValue : 'Belum Absen');
        badge.removeClass('badge-success badge-info badge-warning badge-danger badge-secondary');
        
        switch(selectedValue) {
            case 'Hadir':
                badge.addClass('badge-success');
                break;
            case 'Sakit':
                badge.addClass('badge-warning');
                break;
            case 'Izin':
                badge.addClass('badge-info');
                break;
            case 'Alpa':
                badge.addClass('badge-danger');
                break;
            default:
                badge.addClass('badge-secondary');
        }
    }
    
    $(document).ready(function() {
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

        $(document).on('click', '.btn-kehadiran-siswa', function() {
            var studentId = $(this).data('id');
            var status = $(this).data('status');
            var input = $('#status_' + studentId);
            var currentStatus = input.val();
            var nextStatus = (currentStatus === status) ? '' : status;
            var group = $(this).closest('.attendance-btn-group');

            group.find('.btn-kehadiran-siswa').removeClass('active');
            if (nextStatus !== '') {
                group.find('.btn-kehadiran-siswa[data-status=\"' + nextStatus + '\"]').addClass('active');
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

include '../templates/user_footer.php';
?>
