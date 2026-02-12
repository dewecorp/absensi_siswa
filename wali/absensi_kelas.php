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
        // Direct login via NUPTK, user_id is actually the id_guru
        $stmt = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
        $stmt->execute([$_SESSION['user_id']]);
    } else {
        // Traditional login via tb_pengguna
        $stmt = $pdo->prepare("SELECT g.nama_guru FROM tb_guru g JOIN tb_pengguna p ON g.id_guru = p.id_guru WHERE p.id_pengguna = ?");
        $stmt->execute([$_SESSION['user_id']]);
    }
    $teacher_result = $stmt->fetch(PDO::FETCH_ASSOC);
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
    $id_kelas = (int)$wali_kelas['id_kelas']; // Use the wali's class
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
            if (!in_array($keterangan, ['Hadir', 'Sakit', 'Izin', 'Alpa', 'Berhalangan'])) {
                continue; // Skip invalid values
            }
            
            // Check if attendance already exists for this student and date
            $check_stmt = $pdo->prepare("SELECT * FROM tb_absensi WHERE id_siswa = ? AND tanggal = ?");
            $check_stmt->execute([$id_siswa, $tanggal]);
            
            $current_time = date('H:i:s');
            
            if ($check_stmt->rowCount() > 0) {
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
    
    $message = ['type' => 'success', 'text' => "Data absensi berhasil disimpan untuk $saved_count siswa!"];
    
    // Send notification to admin
    if ($saved_count > 0) {
        $nama_wali = $_SESSION['nama_guru'] ?? 'Wali Kelas';
        $nama_kelas_notif = $wali_kelas ? $wali_kelas['nama_kelas'] : 'Kelas';
        $notif_msg = "$nama_wali telah melakukan input absensi siswa $nama_kelas_notif ($saved_count siswa)";
        createNotification($pdo, $notif_msg, 'rekap_absensi.php', 'absensi_siswa');
    }

    $username = isset($_SESSION['username']) ? $_SESSION['username'] : (isset($teacher['nuptk']) ? $teacher['nuptk'] : 'system');
    $log_result = logActivity($pdo, $username, 'Input Absensi', "Wali " . $username . " melakukan input absensi harian kelas ID: $id_kelas untuk $saved_count siswa");
    if (!$log_result) error_log("Failed to log activity for Input Absensi: kelas ID $id_kelas");
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
$page_title = 'Absensi Harian';

// Define CSS libraries for this page
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'
];

include '../templates/user_header.php';
?>

            <!-- Main Content -->
            <div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Absensi Harian Kelas <?php echo $wali_kelas ? htmlspecialchars($wali_kelas['nama_kelas']) : 'Tidak Ada'; ?></h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                            <div class="breadcrumb-item">Absensi Harian Kelas Terpilih</div>
                        </div>
                    </div>



                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Form Absensi Harian Kelas <?php echo $wali_kelas ? htmlspecialchars($wali_kelas['nama_kelas']) : 'Tidak Ada'; ?></h4>
                                </div>
                                <div class="card-body">
                                    <form method="GET" action="" id="filterForm">
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>Kelas</label>
                                                    <input type="text" class="form-control" value="<?php echo $wali_kelas ? htmlspecialchars($wali_kelas['nama_kelas']) : 'Tidak ada kelas yang diajar'; ?>" readonly />
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
                                                                <option value="Berhalangan" <?php echo ($student['keterangan'] ?? '') === 'Berhalangan' ? 'selected' : ''; ?>>Berhalangan</option>
                                                            </select>
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
    'node_modules/datatables/media/js/jquery.dataTables.min.js',
    'node_modules/datatables.net-bs4/js/dataTables.bootstrap4.min.js',
    'node_modules/datatables.net-select-bs4/js/select.bootstrap4.min.js'
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
        headerDiv.innerHTML += '<h3>" . addslashes($school_profile['nama_madrasah']) . "</h3>';
        headerDiv.innerHTML += '<h4>Absensi Kelas " . ($wali_kelas ? addslashes($wali_kelas['nama_kelas']) : 'Tidak Diketahui') . " - Tanggal " . $tanggal . "</h4></div><br style=\"clear: both;\">';
        
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
        a.download = 'absensi_kelas_" . ($wali_kelas ? str_replace("'", "", $wali_kelas['nama_kelas']) : 'tidak_diketahui') . "_' + new Date().toISOString().slice(0,10) + '.xls';
        a.click();
    }
    
    function exportToPDF() {
        // Print the table as PDF
        var printWindow = window.open('', '_blank');
        printWindow.document.write('<html><head><title>Export PDF</title>');
        printWindow.document.write('<style>');
        printWindow.document.write('@page { size: legal landscape; margin: 0.5cm; }');
        printWindow.document.write('@media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } .no-print { display: none !important; } }');
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
        printWindow.document.write('.print-btn { position: fixed; top: 20px; right: 20px; padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.2); z-index: 9999; }');
        printWindow.document.write('.print-btn:hover { background: #0056b3; }');
        printWindow.document.write('</style>');
        printWindow.document.write('</head><body>');
        
        printWindow.document.write('<button class=\"print-btn no-print\" onclick=\"window.print()\">Cetak / Simpan PDF</button>');

        printWindow.document.write('<div class=\"header-container\">');
        printWindow.document.write('<img src=\"../assets/img/logo_1768301957.png\" alt=\"Logo\" style=\"max-width: 100px; float: left; margin-right: 20px;\">');
        printWindow.document.write('<div style=\"display: inline-block;\"><h2>Sistem Absensi Siswa</h2>');
        printWindow.document.write('<h3>" . addslashes($school_profile['nama_madrasah']) . "</h3>');
        printWindow.document.write('<h4>Absensi Kelas " . ($wali_kelas ? addslashes($wali_kelas['nama_kelas']) : 'Tidak Diketahui') . " - Tanggal " . $tanggal . "</h4></div><br style=\"clear: both;\">');
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
        if (classTeacherName) {
            var qrContentWali = 'Validasi Tanda Tangan Digital: ' + classTeacherName + ' - ' + schoolName;
            var qrUrlWali = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' + encodeURIComponent(qrContentWali);
            printWindow.document.write('<img src=\"' + qrUrlWali + '\" alt=\"QR Signature\" style=\"width: 80px; height: 80px; margin: 10px auto; display: block;\">');
            printWindow.document.write('<p style=\"font-size: 10px; margin-top: 0;\">(Ditandatangani secara digital)</p>');
        } else {
            printWindow.document.write('<br><br><br>');
        }
        printWindow.document.write('<p><strong>' + classTeacherName + '</strong></p>');
        printWindow.document.write('</div>');
        printWindow.document.write('<div class=\"signature-box\">');
        printWindow.document.write('<p>Kepala Madrasah,</p>');
        if (madrasahHeadSignature) {
            var qrContentHead = 'Validasi Tanda Tangan Digital: ' + madrasahHeadName + ' - ' + schoolName;
            var qrUrlHead = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' + encodeURIComponent(qrContentHead);
            printWindow.document.write('<img src=\"' + qrUrlHead + '\" alt=\"QR Signature\" style=\"width: 80px; height: 80px; margin: 10px auto; display: block;\">');
            printWindow.document.write('<p style=\"font-size: 10px; margin-top: 0;\">(Ditandatangani secara digital)</p>');
        } else {
            printWindow.document.write('<br><br><br>');
        }
        printWindow.document.write('<p><strong>' + madrasahHeadName + '</strong></p>');
        printWindow.document.write('</div>');
        printWindow.document.write('</div>');
        
        printWindow.document.write('<script>window.onload = function() { window.print(); }<\/script>');
        printWindow.document.write('</body></html>');
        printWindow.document.close();
    }
    
    $(document).ready(function() {
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
        
        // Handle form submission to ensure all inputs are sent
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
                
                // Temporarily show all rows to collect all current values
                dt.page.len(-1).draw(false);
                
                // Wait for DOM to update, then collect all values
                setTimeout(function() {
                    // Collect all select values from all rows (now all visible)
                    var collectedCount = 0;
                    table.find('tbody select[name^=\"keterangan_\"]').each(function() {
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
