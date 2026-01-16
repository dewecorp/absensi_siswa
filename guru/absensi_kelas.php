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

// Get classes assigned to this teacher
$stmt = $pdo->prepare("SELECT * FROM tb_kelas WHERE wali_kelas = ?");
$stmt->execute([$teacher['nama_guru']]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission for attendance
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_attendance'])) {
    $id_kelas = (int)$_POST['id_kelas'];
    $tanggal = $_POST['tanggal'];
    
    // Get students in selected class
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
            // For admin users logging in as guru, id_guru should be NULL
            $id_guru = ($_SESSION['level'] === 'admin') ? NULL : $_SESSION['user_id'];
            $insert_stmt = $pdo->prepare("INSERT INTO tb_absensi (id_siswa, tanggal, keterangan, id_guru) VALUES (?, ?, ?, ?)");
            $insert_stmt->execute([$id_siswa, $tanggal, $keterangan, $id_guru]);
        }
    }
    
    $message = ['type' => 'success', 'text' => 'Data absensi berhasil disimpan!'];
    logActivity($pdo, $_SESSION['username'], 'Input Absensi', "Guru " . $_SESSION['username'] . " melakukan input absensi kelas ID: $id_kelas");
}

// Get students for selected class
$students = [];
if (isset($_GET['kelas']) && !empty($_GET['kelas'])) {
    $id_kelas = (int)$_GET['kelas'];
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
    <title>Absensi Kelas | Sistem Absensi Siswa</title>

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
                        ?>
                        <a href="#" data-toggle="dropdown" class="nav-link dropdown-toggle nav-link-lg nav-link-user">
                            <?php echo getUserAvatarImage($current_user ?? ['username' => $_SESSION['username']], 30); ?>
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
                        
                        <li class="menu-header">Absensi</li>
                        <li class="active"><a class="nav-link" href="absensi_kelas.php"><i class="fas fa-calendar-check"></i> <span>Absensi</span></a></li>
                    </ul>
                </aside>
            </div>

            <!-- Main Content -->
            <div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Absensi Kelas</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                            <div class="breadcrumb-item">Absensi</div>
                        </div>
                    </div>

                    <?php if (isset($message)): ?>
                    <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible show fade">
                        <div class="alert-body">
                            <button class="close" data-dismiss="alert">
                                <span>&times;</span>
                            </button>
                            <?php echo $message['text']; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Form Absensi Kelas</h4>
                                </div>
                                <div class="card-body">
                                    <form method="GET" action="">
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>Kelas</label>
                                                    <select class="form-control" name="kelas" <?php echo (!empty($students)) ? 'disabled' : 'required'; ?>>
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
                                                    <input type="date" class="form-control" name="tanggal" value="<?php echo $tanggal; ?>" <?php echo (!empty($students)) ? 'disabled' : 'required'; ?> />
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>&nbsp;</label><br>
                                                    <?php if (!empty($students)): ?>
                                                    <a href="?" class="btn btn-secondary">
                                                        <i class="fas fa-sync"></i> Ganti Kelas
                                                    </a>
                                                    <?php else: ?>
                                                    <button type="submit" class="btn btn-primary">Tampilkan Siswa</button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                    
                                    <script>
                                        // Auto-submit handler - attach immediately, not waiting for document ready
                                        $(document).on('change', 'select[name="kelas"]', function() {
                                            var kelasId = $(this).val();
                                            console.log('Class selected:', kelasId);
                                            if (kelasId && kelasId !== '') {
                                                console.log('Auto-submitting form...');
                                                $(this).closest('form').submit();
                                            }
                                        });
                                        
                                        $(document).on('change', 'input[name="tanggal"]', function() {
                                            var tanggal = $(this).val();
                                            var kelasId = $('select[name="kelas"]').val();
                                            console.log('Date changed:', tanggal, 'Class:', kelasId);
                                            if (tanggal && tanggal !== '' && kelasId && kelasId !== '') {
                                                console.log('Auto-submitting form...');
                                                $(this).closest('form').submit();
                                            }
                                        });
                                    </script>
                                    
                                    <?php if (!empty($students)): ?>
                                    <form method="POST" action="">
                                        <input type="hidden" name="id_kelas" value="<?php echo $_GET['kelas']; ?>">
                                        <input type="hidden" name="tanggal" value="<?php echo $tanggal; ?>">
                                        <input type="hidden" name="save_attendance" value="1">
                                        
                                        <div class="row mb-3">
                                            <div class="col-12">
                                                <a href="?" class="btn btn-secondary">
                                                    <i class="fas fa-arrow-left"></i> Ganti Kelas
                                                </a>
                                            </div>
                                        </div>
                                        
                                        <div class="table-responsive">
                                            <table class="table table-striped" id="table-1">
                                                <thead>
                                                    <tr>
                                                        <th>No</th>
                                                        <th>Nama Siswa</th>
                                                        <th>NISN</th>
                                                        <th>Jenis Kelamin</th>
                                                        <th>Status Kehadiran</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($students as $index => $student): ?>
                                                    <tr>
                                                        <td><?php echo $index + 1; ?></td>
                                                        <td><?php echo htmlspecialchars($student['nama_siswa']); ?></td>
                                                        <td><?php echo htmlspecialchars($student['nisn']); ?></td>
                                                        <td><?php echo $student['jenis_kelamin'] == 'L' ? 'Laki-laki' : ($student['jenis_kelamin'] == 'P' ? 'Perempuan' : '-'); ?></td>
                                                        <td>
                                                            <select class="form-control" name="keterangan_<?php echo $student['id_siswa']; ?>">
                                                                <option value="Hadir" <?php echo ($student['keterangan'] ?? '') === 'Hadir' ? 'selected' : ''; ?>>Hadir</option>
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
                                    <?php elseif (isset($_GET['kelas'])): ?>
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
            
            <footer class="main-footer">
                <div class="footer-left">
                    Copyright &copy; <?php echo date('Y'); ?> <div class="bullet"></div> <a href="#">Sistem Absensi Siswa</a>
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
            $(document).ready(function() {
                $('#table-1').DataTable({
                    "columnDefs": [
                        { "orderable": false, "targets": [4] }
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
                
                // Disable kelas filter when students are displayed
                <?php if (!empty($students)): ?>
                $('select[name="kelas"]').prop('disabled', true);
                <?php endif; ?>
            });
        </script>
</body>
</html>