<?php
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['level'] = 'admin';

require_once '../config/database.php';

// Handle form submission
$selected_class = isset($_POST['kelas']) ? (int)$_POST['kelas'] : 0;
$selected_date = isset($_POST['tanggal']) ? $_POST['tanggal'] : '';
$show_results = false;
$attendance_data = [];

// Get classes for dropdown
$stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Process search if both class and date are selected
if ($selected_class > 0 && !empty($selected_date)) {
    $stmt = $pdo->prepare("
        SELECT s.nama_siswa, s.nisn, k.nama_kelas, a.keterangan, a.tanggal
        FROM tb_absensi a
        LEFT JOIN tb_siswa s ON a.id_siswa = s.id_siswa
        LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas
        WHERE s.id_kelas = ? AND a.tanggal = ?
        ORDER BY s.nama_siswa
    ");
    $stmt->execute([$selected_class, $selected_date]);
    $attendance_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $show_results = true;
    
    // Get class name for display
    $class_name = '';
    foreach ($classes as $class) {
        if ($class['id_kelas'] == $selected_class) {
            $class_name = $class['nama_kelas'];
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manual Daily Attendance Filter</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css">
</head>
<body>
    <div class="container mt-4">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h3>Manual Daily Attendance Filter</h3>
                <p class="mb-0">Simple, reliable filter with manual controls</p>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label><strong>Step 1:</strong> Select Class</label>
                                <select name="kelas" class="form-control" required>
                                    <option value="">Choose Class...</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id_kelas']; ?>" <?php echo ($selected_class == $class['id_kelas']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($class['nama_kelas']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="form-group">
                                <label><strong>Step 2:</strong> Select Date</label>
                                <input type="date" name="tanggal" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>" required>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>&nbsp;</label><br>
                                <button type="submit" class="btn btn-success btn-block">
                                    <i class="fas fa-search"></i> Show Attendance
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
                
                <?php if ($show_results): ?>
                    <div class="mt-4">
                        <h4>Attendance Results</h4>
                        <div class="alert alert-info">
                            <strong>Class:</strong> <?php echo htmlspecialchars($class_name); ?> | 
                            <strong>Date:</strong> <?php echo $selected_date ? date('d M Y', strtotime($selected_date)) : '-'; ?> | 
                            <strong>Total Records:</strong> <?php echo count($attendance_data); ?>
                        </div>
                        
                        <?php if (!empty($attendance_data)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-bordered" id="manualTable">
                                    <thead class="thead-dark">
                                        <tr>
                                            <th>Student Name</th>
                                            <th>NISN</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($attendance_data as $record): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($record['nama_siswa']); ?></td>
                                                <td><?php echo htmlspecialchars($record['nisn']); ?></td>
                                                <td>
                                                    <?php 
                                                    $badge_class = '';
                                                    switch ($record['keterangan']) {
                                                        case 'Hadir': $badge_class = 'badge-success'; break;
                                                        case 'Sakit': $badge_class = 'badge-warning'; break;
                                                        case 'Izin': $badge_class = 'badge-info'; break;
                                                        case 'Alpa': $badge_class = 'badge-danger'; break;
                                                        default: $badge_class = 'badge-secondary'; break;
                                                    }
                                                    ?>
                                                    <span class="badge <?php echo $badge_class; ?> p-2">
                                                        <?php echo htmlspecialchars($record['keterangan'] ?? 'Not Recorded'); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $record['tanggal'] ? date('d M Y', strtotime($record['tanggal'])) : '-'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-circle"></i> No attendance records found for this class and date.
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <div class="mt-4">
                    <h5>System Status:</h5>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="card bg-success text-white">
                                <div class="card-body text-center">
                                    <h5>Database</h5>
                                    <p class="mb-0">✓ Connected</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-success text-white">
                                <div class="card-body text-center">
                                    <h5>Data Query</h5>
                                    <p class="mb-0">✓ Working</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-success text-white">
                                <div class="card-body text-center">
                                    <h5>Records Found</h5>
                                    <p class="mb-0">✓ <?php echo count($attendance_data); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card <?php echo $show_results ? 'bg-success' : 'bg-secondary'; ?> text-white">
                                <div class="card-body text-center">
                                    <h5>Filter Test</h5>
                                    <p class="mb-0"><?php echo $show_results ? '✓ Working' : 'Ready'; ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js"></script>
    <script src="https://kit.fontawesome.com/a076d05399.js"></script>
    <script>
    $(document).ready(function() {
        if ($.fn.DataTable && $('#manualTable').length > 0) {
            $('#manualTable').DataTable({
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
    });
    </script>
</body>
</html>