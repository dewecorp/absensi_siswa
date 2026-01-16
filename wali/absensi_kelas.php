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
if (isset($_SESSION['nama_guru'])) {
    $teacher_name = $_SESSION['nama_guru'];
} else {
    // For traditional login via tb_pengguna, get teacher name
    $stmt = $pdo->prepare("SELECT g.nama_guru FROM tb_guru g JOIN tb_pengguna p ON g.id_guru = p.id_guru WHERE p.id_pengguna = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $teacher_name = $teacher_result['nama_guru'] ?? $_SESSION['username'];
}

// Get the class that the wali teaches
$wali_kelas_stmt = $pdo->prepare("SELECT id_kelas, nama_kelas FROM tb_kelas WHERE wali_kelas = ?");
$wali_kelas_stmt->execute([$teacher_name]);
$wali_kelas = $wali_kelas_stmt->fetch(PDO::FETCH_ASSOC);

// Handle form submission for attendance
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_attendance'])) {
    $id_kelas = (int)$wali_kelas['id_kelas']; // Use the wali's class
    $tanggal = $_POST['tanggal'];
    
    // Get students in the wali's class
    $stmt = $pdo->prepare("SELECT * FROM tb_siswa WHERE id_kelas = ?");
    $stmt->execute([$id_kelas]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Save attendance for each student
    foreach ($students as $student) {
        $id_siswa = $student['id_siswa'];
        $keterangan = $_POST['keterangan_' . $id_siswa] ?? 'Alpa'; // Default to Alpa if not selected
        
        // Check if attendance already exists for this student and date
        $check_stmt = $pdo->prepare("SELECT * FROM tb_absensi WHERE id_siswa = ? AND tanggal = ?");
        $check_stmt->execute([$id_siswa, $tanggal]);
        
        if ($check_stmt->rowCount() > 0) {
            // Update existing record
            $update_stmt = $pdo->prepare("UPDATE tb_absensi SET keterangan = ? WHERE id_siswa = ? AND tanggal = ?");
            $update_stmt->execute([$keterangan, $id_siswa, $tanggal]);
        } else {
            // Insert new record
            // For admin users logging in as wali, id_guru should be NULL
            $id_guru = ($_SESSION['level'] === 'admin') ? NULL : $_SESSION['user_id'];
            $insert_stmt = $pdo->prepare("INSERT INTO tb_absensi (id_siswa, tanggal, keterangan, id_guru) VALUES (?, ?, ?, ?)");
            $insert_stmt->execute([$id_siswa, $tanggal, $keterangan, $id_guru]);
        }
    }
    
    $message = ['type' => 'success', 'text' => 'Data absensi berhasil disimpan!'];
    logActivity($pdo, $_SESSION['username'], 'Input Absensi', "Wali " . $_SESSION['username'] . " melakukan input absensi harian kelas ID: $id_kelas");
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, shrink-to-fit=no" name="viewport">
    <title>Absensi Harian | Sistem Absensi Siswa</title>

    <!-- General CSS Files -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.7.2/css/all.css" integrity="sha384-fnmOCqbTlWIlj8LyTjo7mOUStjsKC4pOpQbqyi7RrhN7udi9RwhKkMHpvLbHG9Sr" crossorigin="anonymous">

    <!-- CSS Libraries -->
        <link rel="stylesheet" href="../node_modules/datatables.net-bs4/css/dataTables.bootstrap4.min.css">
        <link rel="stylesheet" href="../node_modules/datatables.net-select-bs4/css/select.bootstrap4.min.css">

    <!-- Template CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/components.css">
</head>

<body>
    <div id="app">
        <div class="main-wrapper">
            <div class="navbar-bg"></div>
            <nav class="navbar navbar-expand-lg main-navbar">
                <form class="form-inline mr-auto">
                    <ul class="navbar-nav mr-3">
                        <li><a href="#" data-toggle="sidebar" class="nav-link nav-link-lg"><i class="fas fa-bars"></i></a></li>
                    </ul>
                </form>
                <ul class="navbar-nav navbar-right">
                    <li class="dropdown">
                        <?php
                        // Get user data to display personalized avatar
                        $user_stmt = $pdo->prepare("SELECT * FROM tb_pengguna WHERE username = ?");
                        $user_stmt->execute([$_SESSION['username']]);
                        $current_user = $user_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        // Fallback if teacher logged in directly (not through tb_pengguna)
                        if (!$current_user) {
                            // For direct login via NUPTK, create a mock user object
                            $current_user = ['username' => $_SESSION['username'], 'foto' => null];
                        }
                        ?>
                        <a href="#" data-toggle="dropdown" class="nav-link dropdown-toggle nav-link-lg nav-link-user">
                            <?php echo getUserAvatarImage($current_user, 30); ?>
                            <div class="d-sm-none d-lg-inline-block">Hi, <?php echo $_SESSION['username']; ?></div>
                        </a>
                        <div class="dropdown-menu dropdown-menu-right">
                            <a href="features-profile.html" class="dropdown-item has-icon">
                                <i class="far fa-user"></i> Profile
                            </a>
                            <a href="features-settings.html" class="dropdown-item has-icon">
                                <i class="fas fa-cog"></i> Settings
                            </a>
                            <div class="dropdown-divider"></div>
                            <a href="../logout.php" class="dropdown-item has-icon text-danger">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </div>
                    </li>
                </ul>
            </nav>
            <div class="main-sidebar">
                <aside id="sidebar-wrapper">
                    <div class="sidebar-brand">
                        <a href="dashboard.php">Sistem Absensi Siswa</a>
                    </div>
                    <div class="sidebar-brand sidebar-brand-sm">
                        <a href="dashboard.php">SA</a>
                    </div>
                    <ul class="sidebar-menu">
                        <li class="menu-header">Dashboard</li>
                        <li><a class="nav-link" href="dashboard.php"><i class="fas fa-fire"></i> <span>Dashboard</span></a></li>
                        
                        <li><a class="nav-link" href="absensi_kelas.php"><i class="fas fa-calendar-check"></i> <span>Absensi Harian</span></a></li>
                        
                        <li class="menu-header">Rekap</li>
                        <li><a class="nav-link" href="rekap_absensi.php"><i class="fas fa-book"></i> <span>Rekap Absensi</span></a></li>
                    </ul>
                </aside>
            </div>

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
            
            <footer class="main-footer">
                <div class="footer-left">
                    Copyright &copy; <?php echo date('Y'); ?> <div class="bullet"></div> <a href="#"><?php echo $school_profile['nama_madrasah']; ?></a>
                </div>
                <div class="footer-right">
                    2.3.0
                </div>
            </footer>
        </div>
    </div>

    <!-- General JS Scripts -->
    <script src="https://code.jquery.com/jquery-3.3.1.min.js" integrity="sha256-FgpCb/KJQlLNfOu91ta32o/NMZxltwRo8QtmkMRdAu8=" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.nicescroll/3.7.6/jquery.nicescroll.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.24.0/moment.min.js"></script>
    <script src="../assets/js/stisla.js"></script>

    <!-- JS Libraies -->
        <script src="../node_modules/datatables/media/js/jquery.dataTables.min.js"></script>
        <script src="../node_modules/datatables.net-bs4/js/dataTables.bootstrap4.min.js"></script>
        <script src="../node_modules/datatables.net-select-bs4/js/select.bootstrap4.min.js"></script>

    <!-- Template JS File -->
    <script src="../assets/js/scripts.js"></script>
    <script src="../assets/js/custom.js"></script>

    <!-- Page Specific JS File -->
        <script>
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
                headerDiv.innerHTML = '<img src="../assets/img/logo_1768301957.png" alt="Logo" style="max-width: 100px; float: left; margin-right: 20px;"><div style="display: inline-block;"><h2>Sistem Absensi Siswa</h2>';
                headerDiv.innerHTML += '<h3><?php echo $school_profile['nama_madrasah']; ?></h3>';
                headerDiv.innerHTML += '<h4>Absensi Kelas <?php echo $wali_kelas ? $wali_kelas['nama_kelas'] : 'Tidak Diketahui'; ?> - Tanggal <?php echo $tanggal; ?></h4></div><br style="clear: both;">';
                
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
                a.download = 'absensi_kelas_<?php echo $wali_kelas ? str_replace("'", "", $wali_kelas['nama_kelas']) : 'tidak_diketahui'; ?>_' + new Date().toISOString().slice(0,10) + '.xls';
                a.click();
            }
            
            function exportToPDF() {
                // Print the table as PDF (since we don't have jsPDF in this project)
                var printWindow = window.open('', '', 'height=600,width=800');
                printWindow.document.write('<html><head><title>Export PDF</title>');
                printWindow.document.write('<style>');
                printWindow.document.write('table { border-collapse: collapse; width: 100%; }');
                printWindow.document.write('th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }');
                printWindow.document.write('</style>');
                printWindow.document.write('</head><body>');
                printWindow.document.write('<div style="text-align: center;">');
                printWindow.document.write('<img src="../assets/img/logo_1768301957.png" alt="Logo" style="max-width: 100px; float: left; margin: 0 20px 20px 0;">');
                printWindow.document.write('<div style="display: inline-block;"><h2>Sistem Absensi Siswa</h2>');
                printWindow.document.write('<h3><?php echo $school_profile['nama_madrasah']; ?></h3>');
                printWindow.document.write('<h4>Absensi Kelas <?php echo $wali_kelas ? $wali_kelas['nama_kelas'] : 'Tidak Diketahui'; ?> - Tanggal <?php echo $tanggal; ?></h4></div><br style="clear: both;">');
                
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
                printWindow.document.write('</div>');
                printWindow.document.write('</body></html>');
                printWindow.document.close();
                printWindow.print();
            }
            
            $(document).ready(function() {
                $('#table-1').DataTable({
                    "columnDefs": [
                        { "orderable": false, "targets": [3] }
                    ],
                    "paging": true,
                    "lengthChange": true,
                    "pageLength": 10,
                    "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Semua']],
                    "dom": 'lfrtip',
                    "info": true,
                    "language": {
                        "lengthMenu": "Tampilkan _MENU_ entri",
                        "zeroRecords": "Tidak ada data yang ditemukan",
                        "info": "Menampilkan _START_ sampai _END_ dari _TOTAL_ entri",
                        "infoEmpty": "Menampilkan 0 sampai 0 dari 0 entri",
                        "infoFiltered": "(disaring dari _MAX_ total entri)",
                        "search": "Cari:",
                        "paginate": {
                            "first": "Pertama",
                            "last": "Terakhir",
                            "next": "Selanjutnya",
                            "previous": "Sebelumnya"
                        }
                    }
                });
            });
        </script>
</body>
</html>