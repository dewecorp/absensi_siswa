<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

// Get school profile
$school_profile = getSchoolProfile($pdo);

// Handle form submissions
$message = '';

// Backup database
if (isset($_POST['backup_db'])) {
    $host = DB_HOST;
    $username = DB_USER;
    $password = DB_PASS;
    $database = DB_NAME;
    
    // Create backup directory if not exists
    $backup_dir = '../backups/';
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }
    
    // Generate backup filename
    $filename = $backup_dir . 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    
    // Command to backup database
    $command = "mysqldump --host={$host} --user={$username} --password={$password} {$database} > {$filename}";
    
    // Execute backup command
    exec($command, $output, $result);
    
    if ($result === 0 && file_exists($filename)) {
        // Get file size
        $filesize = filesize($filename);
        $filesize_formatted = formatBytes($filesize);
        
        // Insert backup record to database
        $stmt = $pdo->prepare("INSERT INTO tb_backup_restore (nama_file, tanggal_backup, ukuran_file, keterangan) VALUES (?, NOW(), ?, 'Backup manual')");
        $stmt->execute([basename($filename), $filesize_formatted]);
        
        $message = ['type' => 'success', 'text' => 'Database berhasil dibackup!'];
    } else {
        $message = ['type' => 'danger', 'text' => 'Gagal membuat backup database!'];
    }
}

// Restore database
if (isset($_POST['restore_db'])) {
    if (isset($_POST['backup_file']) && !empty($_POST['backup_file'])) {
        $backup_file = basename($_POST['backup_file']);
        $backup_path = '../backups/' . $backup_file;
        
        if (file_exists($backup_path)) {
            $host = DB_HOST;
            $username = DB_USER;
            $password = DB_PASS;
            $database = DB_NAME;
            
            // Command to restore database
            $command = "mysql --host={$host} --user={$username} --password={$password} {$database} < {$backup_path}";
            
            // Execute restore command
            exec($command, $output, $result);
            
            if ($result === 0) {
                $message = ['type' => 'success', 'text' => 'Database berhasil direstore dari file: ' . $backup_file];
            } else {
                $message = ['type' => 'danger', 'text' => 'Gagal merestore database dari file: ' . $backup_file];
            }
        } else {
            $message = ['type' => 'danger', 'text' => 'File backup tidak ditemukan!'];
        }
    } else {
        $message = ['type' => 'danger', 'text' => 'Silakan pilih file backup untuk direstore!'];
    }
}

// Get all backup files from database
$stmt = $pdo->query("SELECT * FROM tb_backup_restore ORDER BY tanggal_backup DESC");
$backup_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function to format bytes
function formatBytes($size, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    
    return round($size, $precision) . ' ' . $units[$i];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, shrink-to-fit=no" name="viewport">
    <title>Backup & Restore | Sistem Absensi Siswa</title>

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
                        <a href="#" data-toggle="dropdown" class="nav-link dropdown-toggle nav-link-lg nav-link-user">
                            <img alt="image" src="../assets/img/avatar/avatar-1.png" class="rounded-circle mr-1">
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
                        
                        <li class="menu-header">Master Data</li>
                        <li class="nav-item dropdown">
                            <a href="#" class="nav-link has-dropdown"><i class="fas fa-database"></i><span>Master Data</span></a>
                            <ul class="dropdown-menu">
                                <li><a class="nav-link" href="data_guru.php">Data Guru</a></li>
                                <li><a class="nav-link" href="data_kelas.php">Data Kelas</a></li>
                                <li><a class="nav-link" href="data_siswa.php">Data Siswa</a></li>
                            </ul>
                        </li>
                        
                        <li class="menu-header">Absensi</li>
                        <li class="nav-item dropdown">
                            <a href="#" class="nav-link has-dropdown"><i class="fas fa-calendar-check"></i><span>Absensi</span></a>
                            <ul class="dropdown-menu">
                                <li><a class="nav-link" href="absensi_harian.php">Absensi Harian</a></li>
                                <li><a class="nav-link" href="rekap_absensi.php">Rekap Absensi</a></li>
                            </ul>
                        </li>
                        
                        <li class="menu-header">Pengaturan</li>
                        <li><a class="nav-link" href="profil_madrasah.php"><i class="fas fa-school"></i> <span>Profil Madrasah</span></a></li>
                        
                        <li class="menu-header">Pengguna</li>
                        <li><a class="nav-link" href="pengguna.php"><i class="fas fa-users"></i> <span>Data Pengguna</span></a></li>
                        
                        <li class="menu-header">Backup & Restore</li>
                        <li class="active"><a class="nav-link" href="backup_restore.php"><i class="fas fa-hdd"></i> <span>Backup & Restore</span></a></li>
                    </ul>
                </aside>
            </div>

            <!-- Main Content -->
            <div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Backup & Restore</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                            <div class="breadcrumb-item">Backup & Restore</div>
                        </div>
                    </div>

                    <?php if ($message): ?>
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
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Backup Database</h4>
                                </div>
                                <div class="card-body">
                                    <p>Lakukan backup database untuk menyimpan salinan data saat ini.</p>
                                    <form method="POST" action="">
                                        <button type="submit" name="backup_db" class="btn btn-primary">
                                            <i class="fas fa-download"></i> Buat Backup
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Restore Database</h4>
                                </div>
                                <div class="card-body">
                                    <p>Restore database dari file backup sebelumnya.</p>
                                    <form method="POST" action="">
                                        <div class="form-group">
                                            <label>Pilih File Backup</label>
                                            <select class="form-control" name="backup_file" required>
                                                <option value="">Pilih File Backup</option>
                                                <?php foreach ($backup_records as $record): ?>
                                                <option value="<?php echo htmlspecialchars($record['nama_file']); ?>">
                                                    <?php echo htmlspecialchars($record['nama_file']); ?> (<?php echo $record['ukuran_file']; ?>)
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button type="submit" name="restore_db" class="btn btn-warning">
                                            <i class="fas fa-upload"></i> Restore Database
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Riwayat Backup</h4>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-striped" id="table-1">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Nama File</th>
                                                    <th>Tanggal Backup</th>
                                                    <th>Ukuran File</th>
                                                    <th>Keterangan</th>
                                                    <th>Aksi</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $no = 1; foreach ($backup_records as $record): ?>
                                                <tr>
                                                    <td><?php echo $no++; ?></td>
                                                    <td><?php echo htmlspecialchars($record['nama_file']); ?></td>
                                                    <td><?php echo $record['tanggal_backup'] ? date('d M Y H:i:s', strtotime($record['tanggal_backup'])) : '-'; ?></td>
                                                    <td><?php echo $record['ukuran_file']; ?></td>
                                                    <td><?php echo htmlspecialchars($record['keterangan'] ?? '-'); ?></td>
                                                    <td>
                                                        <a href="../backups/<?php echo $record['nama_file']; ?>" class="btn btn-success btn-sm" target="_blank">
                                                            <i class="fas fa-download"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
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
            $js_page[] = "
            $(document).ready(function() {
                if (typeof $.fn.DataTable !== 'undefined') {
                    $('#table-1').DataTable({
                        'columnDefs': [
                            { 'sortable': false, 'targets': [5] }
                        ],
                        'paging': true,
                        'lengthChange': true,
                        'pageLength': 10,
                        'lengthMenu': [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Semua']],
                        'dom': 'lfrtip',
                        'info': true,
                        'language': {
                            'lengthMenu': 'Tampilkan _MENU_ entri',
                            'zeroRecords': 'Tidak ada data yang ditemukan',
                            'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                            'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                            'infoFiltered': '(disaring dari _MAX_ total entri)',
                            'search': 'Cari:',
                            'paginate': {
                                'first': 'Pertama',
                                'last': 'Terakhir',
                                'next': 'Selanjutnya',
                                'previous': 'Sebelumnya'
                            }
                        }
                    });
                }
            });
            ";
            
            include '../templates/footer.php';
            ?>