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

// Handle form submission for attendance
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_attendance'])) {
    $id_kelas = (int)$_POST['id_kelas'];
    $tanggal = $_POST['tanggal'];
    
    // Only process students that are actually in the POST data
    // This prevents DataTables pagination from affecting students on other pages
    $saved_count = 0;
    foreach ($_POST as $key => $value) {
        // Check if this is a keterangan field (keterangan_[id_siswa])
        if (strpos($key, 'keterangan_') === 0) {
            $id_siswa = (int)str_replace('keterangan_', '', $key);
            $keterangan = $value;
            
            // Validate keterangan value
            if (!in_array($keterangan, ['Hadir', 'Sakit', 'Izin', 'Alpa'])) {
                continue; // Skip invalid values
            }
            
            // Check if attendance already exists for this student and date
            $check_stmt = $pdo->prepare("SELECT * FROM tb_absensi WHERE id_siswa = ? AND tanggal = ?");
            $check_stmt->execute([$id_siswa, $tanggal]);
            
            if ($check_stmt->rowCount() > 0) {
                // Update existing record
                $update_stmt = $pdo->prepare("UPDATE tb_absensi SET keterangan = ? WHERE id_siswa = ? AND tanggal = ?");
                $update_stmt->execute([$keterangan, $id_siswa, $tanggal]);
            } else {
                // Insert new record
                // For admin and tata_usaha users, id_guru should be NULL since they don't have a valid teacher ID
                $id_guru = ($_SESSION['level'] === 'admin' || $_SESSION['level'] === 'tata_usaha') ? NULL : $_SESSION['user_id'];
                $insert_stmt = $pdo->prepare("INSERT INTO tb_absensi (id_siswa, tanggal, keterangan, id_guru) VALUES (?, ?, ?, ?)");
                $insert_stmt->execute([$id_siswa, $tanggal, $keterangan, $id_guru]);
            }
            $saved_count++;
        }
    }
    
    $message = ['type' => 'success', 'text' => "Data absensi berhasil disimpan untuk $saved_count siswa!"];
    $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
    $log_result = logActivity($pdo, $username, 'Input Absensi', "Admin " . $username . " melakukan input absensi harian kelas ID: $id_kelas untuk $saved_count siswa");
    if (!$log_result) error_log("Failed to log activity for Input Absensi: kelas ID $id_kelas");
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

include '../templates/header.php';
?>

            <!-- Main Content -->
            <div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Absensi Harian</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                            <div class="breadcrumb-item">Absensi Harian</div>
                        </div>
                    </div>



                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Form Absensi Harian</h4>
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
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>&nbsp;</label><br>
                                                    <button type="button" class="btn btn-success" onclick="exportToExcel()">Ekspor Excel</button>
                                                    <button type="button" class="btn btn-warning" onclick="exportToPDF()">Ekspor PDF</button>
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
                                                        <th>NISN</th>
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
                                                                        echo 'badge-info';
                                                                        break;
                                                                    case 'Izin':
                                                                        echo 'badge-warning';
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
                                                        <td><?php echo htmlspecialchars($student['nisn']); ?></td>
                                                        <td>
                                                            <select class="form-control" name="keterangan_<?php echo $student['id_siswa']; ?>" onchange="updateBadge(this)">
                                                                <option value="Hadir" <?php echo ($student['keterangan'] ?? 'Hadir') === 'Hadir' ? 'selected' : ''; ?>>Hadir</option>
                                                                <option value="Sakit" <?php echo ($student['keterangan'] ?? '') === 'Sakit' ? 'selected' : ''; ?>>Sakit</option>
                                                                <option value="Izin" <?php echo ($student['keterangan'] ?? '') === 'Izin' ? 'selected' : ''; ?>>Izin</option>
                                                                <option value="Alpa" <?php echo ($student['keterangan'] ?? '') === 'Alpa' ? 'selected' : ''; ?>>Alpa</option>
                                                            </select>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        
                                        <div class="row mt-4">
                                            <div class="col-12 text-center">
                                                <button type="submit" class="btn btn-primary" id="saveAttendanceBtn">Simpan Absensi</button>
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
                                    
                                    <?php if (!empty($debug_info)): ?>
                                    <!-- Debug Info -->
                                    <div class="alert alert-warning" style="display: none;">
                                        <strong>Debug Info:</strong><br>
                                        Total siswa di kelas: <?php echo $debug_info['total_students_in_class']; ?><br>
                                        Siswa ditemukan: <?php echo $debug_info['students_found']; ?><br>
                                        Kelas ID: <?php echo $debug_info['id_kelas']; ?><br>
                                        Tanggal: <?php echo $debug_info['tanggal']; ?>
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
            
            // Prepare school name for JavaScript (escape it properly)
$school_name_js = htmlspecialchars($school_profile['nama_madrasah'], ENT_QUOTES, 'UTF-8');

// Prepare signature names
$madrasah_head = addslashes(htmlspecialchars($school_profile['nama_kepala_madrasah'] ?? 'Kepala Madrasah', ENT_QUOTES, 'UTF-8'));
// Try to get wali_kelas from class_info, fallback to generic
$class_teacher = addslashes(htmlspecialchars($class_info['wali_kelas'] ?? 'Wali Kelas', ENT_QUOTES, 'UTF-8'));

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
// Pass actual names to JavaScript
var madrasahHeadName = '$madrasah_head';
var classTeacherName = '$class_teacher';

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
            
            // Add other page-specific functions
            $js_page[] = "
            function updateBadge(selectElement) {
                // Get the selected option text and value
                var selectedOption = selectElement.options[selectElement.selectedIndex].text;
                var selectedValue = selectElement.options[selectElement.selectedIndex].value;
                
                // Get the student ID from the select name (extract from keterangan_[id])
                var studentId = selectElement.name.replace('keterangan_', '');
                
                // Find the specific badge by ID
                var badge = $('#badge_' + studentId);
                
                // Update the badge text
                badge.text(selectedOption);
                
                // Update the badge class based on the selected value
                badge.removeClass('badge-success badge-info badge-warning badge-danger badge-secondary');
                
                switch(selectedValue) {
                    case 'Hadir':
                        badge.addClass('badge-success');
                        break;
                    case 'Sakit':
                        badge.addClass('badge-info');
                        break;
                    case 'Izin':
                        badge.addClass('badge-warning');
                        break;
                    case 'Alpa':
                        badge.addClass('badge-danger');
                        break;
                    default:
                        badge.addClass('badge-secondary');
                }
            }
            
            function exportToExcel() {
                // Create a container for the full report
                var container = document.createElement('div');
                
                // Add application name and school info
                var headerDiv = document.createElement('div');
                headerDiv.innerHTML = '<img src=\"../assets/img/logo_1768301957.png\" alt=\"Logo\" style=\"max-width: 100px; float: left; margin-right: 20px;\"><div style=\"display: inline-block;\"><h2>Sistem Absensi Siswa</h2>';
                headerDiv.innerHTML += '<h3>" . $school_name_js . "</h3>';
                headerDiv.innerHTML += '<h4>Absensi Kelas ' + document.querySelector('#kelasSelect').options[document.querySelector('#kelasSelect').selectedIndex].text + ' - Tanggal ' + document.querySelector('#tanggalInput').value + '</h4></div><br style=\"clear: both;\">';
                
                // Create a copy of the table to modify
                var table = document.getElementById('table-1');
                var newTable = table.cloneNode(true);
                
                // Update the select elements to show their selected values in the cells
                var rows = newTable.querySelectorAll('tr');
                for (var i = 1; i < rows.length; i++) { // Start from 1 to skip header
                    var row = rows[i];
                    var selectCell = row.cells[3]; // Status Kehadiran column (index 3)
                    var selectElement = selectCell.querySelector('select');
                    
                    if (selectElement) {
                        var selectedText = selectElement.options[selectElement.selectedIndex].text;
                        selectCell.innerHTML = selectedText;
                    }
                }
                
                // Append header and table to container
                container.appendChild(headerDiv);
                container.appendChild(newTable);
                
                var html = container.innerHTML;
                
                // Create download link
                var a = document.createElement('a');
                var data = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
                a.href = data;
                a.download = 'absensi_kelas_' + document.querySelector('#kelasSelect').options[document.querySelector('#kelasSelect').selectedIndex].text.replace(/[^a-zA-Z0-9]/g, '_') + '_' + new Date().toISOString().slice(0,10) + '.xls';
                a.click();
            }
            
            function exportToPDF() {
                // Print the table as PDF (since we don't have jsPDF in this project)
                var printWindow = window.open('', '', 'height=860,width=1300');
                printWindow.document.write('<html><head><title>Export PDF</title>');
                printWindow.document.write('<style>');
                printWindow.document.write('@page { size: legal landscape; margin: 0.5cm; }');
                printWindow.document.write('body { font-family: Arial, sans-serif; }');
                printWindow.document.write('table { border-collapse: collapse; width: 100%; font-size: 11px; margin-bottom: 10px; }');
                printWindow.document.write('tr { page-break-inside: avoid; page-break-after: auto; }');
                printWindow.document.write('th, td { border: 1px solid #000; padding: 4px; text-align: center; }');
                printWindow.document.write('th { background-color: #f2f2f2; font-weight: bold; }');
                printWindow.document.write('td:nth-child(2) { text-align: left; white-space: nowrap; }');
                printWindow.document.write('h2, h3, h4 { margin: 5px 0; text-align: center; }');
                printWindow.document.write('.header-container { text-align: center; margin-bottom: 20px; }');
                printWindow.document.write('.signature-wrapper { margin-top: 10px; display: flex; justify-content: space-between; width: 100%; page-break-inside: avoid; break-inside: avoid; }');
                printWindow.document.write('.signature-box { text-align: center; width: 45%; page-break-inside: avoid; break-inside: avoid; }');
                printWindow.document.write('</style>');
                printWindow.document.write('</head><body>');
                printWindow.document.write('<div class=\"header-container\">');
                printWindow.document.write('<img src=\"../assets/img/logo_1768301957.png\" alt=\"Logo\" style=\"max-width: 100px; float: left; margin-right: 20px;\">');
                printWindow.document.write('<div style=\"display: inline-block;\"><h2>Sistem Absensi Siswa</h2>');
                printWindow.document.write('<h3>" . $school_name_js . "</h3>');
                printWindow.document.write('<h4>Absensi Kelas ' + document.querySelector('#kelasSelect').options[document.querySelector('#kelasSelect').selectedIndex].text + ' - Tanggal ' + document.querySelector('#tanggalInput').value + '</h4></div><br style=\"clear: both;\">');
                printWindow.document.write('</div>');
                
                // Create a copy of the table to modify
                var table = document.getElementById('table-1').cloneNode(true);
                
                // Update the select elements to show their selected values in the cells
                var rows = table.querySelectorAll('tr');
                for (var i = 1; i < rows.length; i++) { // Start from 1 to skip header
                    var row = rows[i];
                    var selectCell = row.cells[3]; // Status Kehadiran column (index 3)
                    var selectElement = selectCell.querySelector('select');
                    
                    if (selectElement) {
                        var selectedText = selectElement.options[selectElement.selectedIndex].text;
                        selectCell.innerHTML = selectedText;
                    }
                }
                
                printWindow.document.write(table.outerHTML);
                
                // Add signatures below the table
                printWindow.document.write('<div class=\"signature-wrapper\">');
                printWindow.document.write('<div class=\"signature-box\">');
                printWindow.document.write('<p>Wali Kelas,</p>');
                printWindow.document.write('<br><br><br>');
                printWindow.document.write('<p><strong>' + classTeacherName + '</strong></p>');
                printWindow.document.write('</div>');
                printWindow.document.write('<div class=\"signature-box\">');
                printWindow.document.write('<p>Kepala Madrasah,</p>');
                printWindow.document.write('<br><br><br>');
                printWindow.document.write('<p><strong>' + madrasahHeadName + '</strong></p>');
                printWindow.document.write('</div>');
                printWindow.document.write('</div>');
                
                printWindow.document.write('</body></html>');
                printWindow.document.close();
                printWindow.print();
                // printWindow.close();
            }
            
            // Initialize DataTable with retry mechanism
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
                
                // Destroy existing DataTable if it exists
                if ($.fn.DataTable.isDataTable('#table-1')) {
                    $('#table-1').DataTable().destroy();
                }
                
                // Initialize DataTable
                $('#table-1').DataTable({
                    \"columnDefs\": [
                        { \"orderable\": false, \"targets\": [3] }
                    ],
                    \"paging\": true,
                    \"lengthChange\": true,
                    \"pageLength\": 10,
                    \"lengthMenu\": [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Semua']],
                    \"dom\": 'lfrtip',
                    \"info\": true,
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
            }
            
            // Handle form submission to ensure all inputs are sent
            $(document).ready(function() {
                initDataTable();
                
                // Store all select values globally as they change
                var globalSelectValues = {};
                
                // Initialize: collect all select values when page loads
                setTimeout(function() {
                    if ($.fn.DataTable.isDataTable('#table-1')) {
                        var dt = $('#table-1').DataTable();
                        var originalPage = dt.page();
                        var originalLength = dt.page.len();
                        
                        // Show all rows to collect initial values
                        dt.page.len(-1).draw(false);
                        
                        setTimeout(function() {
                            $('#table-1').find('select[name^=\"keterangan_\"]').each(function() {
                                var select = $(this);
                                var name = select.attr('name');
                                var value = select.val();
                                if (name && value) {
                                    globalSelectValues[name] = value;
                                }
                            });
                            
                            // Restore pagination
                            dt.page.len(originalLength).page(originalPage).draw(false);
                            
                            console.log('Initialized with ' + Object.keys(globalSelectValues).length + ' values');
                        }, 300);
                    }
                }, 1500);
                
                // Update global values whenever a select changes
                $(document).on('change', '#table-1 select[name^=\"keterangan_\"]', function() {
                    var name = $(this).attr('name');
                    var value = $(this).val();
                    if (name && value) {
                        globalSelectValues[name] = value;
                        console.log('Updated global: ' + name + ' = ' + value);
                    }
                });
                
                // Intercept form submission to collect all select values from all DataTables pages
                $(document).on('submit', 'form', function(e) {
                    var form = $(this);
                    var table = $('#table-1');
                    
                    // Only process attendance form (has save_attendance input)
                    if (!form.find('input[name=\"save_attendance\"]').length) {
                        return; // Let other forms submit normally
                    }
                    
                    // If DataTable is initialized, collect all select values
                    if ($.fn.DataTable.isDataTable('#table-1')) {
                        e.preventDefault(); // Prevent default submission
                        e.stopPropagation(); // Stop event propagation
                        
                        var dt = table.DataTable();
                        var currentPage = dt.page();
                        var currentPageLength = dt.page.len();
                        var pageInfo = dt.page.info();
                        var allSelectValues = {};
                        
                        // Start with stored global values
                        $.extend(allSelectValues, globalSelectValues);
                        
                        console.log('Starting with ' + Object.keys(allSelectValues).length + ' global values');
                        
                        // Temporarily show all rows to collect all current values
                        dt.page.len(-1).draw(false);
                        
                        // Wait for DOM to update, then collect all values
                        setTimeout(function() {
                            // Collect all select values from all rows (now all visible)
                            var collectedCount = 0;
                            table.find('select[name^=\"keterangan_\"]').each(function() {
                                var select = $(this);
                                var name = select.attr('name');
                                var value = select.val();
                                
                                if (name && value) {
                                    allSelectValues[name] = value;
                                    collectedCount++;
                                }
                            });
                            
                            console.log('Collected ' + collectedCount + ' values from DOM');
                            console.log('Total values: ' + Object.keys(allSelectValues).length + ' (expected: ' + pageInfo.recordsTotal + ')');
                            console.log('All values:', allSelectValues);
                            
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
                            
                            // Update global values for next time
                            $.extend(globalSelectValues, allSelectValues);
                            
                            // Verify form has all inputs before submitting
                            var formInputs = form.find('input[name^=\"keterangan_\"]').length;
                            console.log('Form now has ' + formInputs + ' keterangan inputs');
                            
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
            
            include '../templates/footer.php';
            ?>