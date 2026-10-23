<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin', 'tata_usaha'])) {
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
    
    // Determine mysqldump path
    $mysqldump_path = 'mysqldump'; // Default fallback
    $detected_path = 'D:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqldump.exe';
    
    if (file_exists($detected_path)) {
        $mysqldump_path = '"' . $detected_path . '"';
    }
    
    // Command to backup database
    // Add --column-statistics=0 for compatibility with newer mysql versions if needed, but safe to omit if not sure
    $command = "{$mysqldump_path} --host={$host} --user={$username} --password={$password} {$database} > {$filename} 2>&1";
    
    // Execute backup command
    exec($command, $output, $result);
    
    // Check if file exists and has content
    if (file_exists($filename) && filesize($filename) > 0) {
        // Get file size
        $filesize = filesize($filename);
        $filesize_formatted = formatBytes($filesize);
        
        // Insert backup record to database
        $stmt = $pdo->prepare("INSERT INTO tb_backup_restore (nama_file, tanggal_backup, ukuran_file, keterangan) VALUES (?, NOW(), ?, 'Backup manual')");
        $stmt->execute([basename($filename), $filesize_formatted]);
        
        $message = ['type' => 'success', 'text' => 'Database berhasil dibackup!'];
    } else {
        // If file exists but empty (failed dump), delete it
        if (file_exists($filename)) {
            unlink($filename);
        }
        $error_msg = !empty($output) ? implode("\n", $output) : 'Unknown error';
        error_log("Backup failed: " . $error_msg);
        $message = ['type' => 'danger', 'text' => 'Gagal membuat backup database! Error: ' . ($result !== 0 ? "Exit code $result" : "File empty")];
    }

    // Respons JSON untuk proses backup via AJAX (menampilkan progres di halaman)
    if (!empty($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => $message['type'] === 'success' ? 'success' : 'error',
            'message' => $message['text']
        ]);
        exit;
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
            
            // Determine mysql path
            $mysql_path = 'mysql'; // Default fallback
            $detected_mysql_path = 'D:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe';
            
            if (file_exists($detected_mysql_path)) {
                $mysql_path = '"' . $detected_mysql_path . '"';
            }
            
            // Command to restore database
            $command = "{$mysql_path} --host={$host} --user={$username} --password={$password} {$database} < {$backup_path} 2>&1";
            
            // Execute restore command
            exec($command, $output, $result);
            
            if ($result === 0) {
                $message = ['type' => 'success', 'text' => 'Database berhasil direstore dari file: ' . $backup_file];
                $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                $log_result = logActivity($pdo, $username, 'Restore Database', "Admin " . $username . " melakukan restore database dari file: " . $backup_file);
                if (!$log_result) error_log("Failed to log activity for Restore Database: $backup_file");
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

// Delete backup
if (isset($_POST['delete_backup'])) {
    if (isset($_POST['backup_id']) && !empty($_POST['backup_id'])) {
        $backup_id = (int)$_POST['backup_id'];
        
        // Get backup record
        $stmt = $pdo->prepare("SELECT * FROM tb_backup_restore WHERE id_backup = ?");
        $stmt->execute([$backup_id]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($backup) {
            $backup_file = $backup['nama_file'];
            $backup_path = '../backups/' . $backup_file;
            
            // Delete file if exists
            $file_deleted = true;
            if (file_exists($backup_path)) {
                $file_deleted = @unlink($backup_path);
            }
            
            // Delete record from database
            $stmt = $pdo->prepare("DELETE FROM tb_backup_restore WHERE id_backup = ?");
            $stmt->execute([$backup_id]);
            
            if ($stmt->rowCount() > 0) {
                $message = ['type' => 'success', 'text' => 'Backup berhasil dihapus!'];
                $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                $log_result = logActivity($pdo, $username, 'Hapus Backup', "Admin " . $username . " menghapus backup file: " . $backup_file);
                if (!$log_result) error_log("Failed to log activity for Hapus Backup: $backup_file");
            } else {
                $message = ['type' => 'danger', 'text' => 'Gagal menghapus record backup dari database!'];
            }
        } else {
            $message = ['type' => 'danger', 'text' => 'Record backup tidak ditemukan!'];
        }
    } else {
        $message = ['type' => 'danger', 'text' => 'ID backup tidak valid!'];
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

// Set page title
$page_title = 'Backup & Restore';

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

                    <div class="row align-items-stretch">
                        <div class="col-md-6 d-flex">
                            <div class="card w-100">
                                <div class="card-header">
                                    <h4>Backup Database</h4>
                                </div>
                                <div class="card-body d-flex flex-column">
                                    <p>Lakukan backup database untuk menyimpan salinan data saat ini.</p>
                                    <form method="POST" action="" id="backupForm" class="mt-auto mb-0 text-center">
                                        <button type="submit" name="backup_db" class="btn btn-primary">
                                            <i class="fas fa-download"></i> Buat Backup
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 d-flex">
                            <div class="card w-100">
                                <div class="card-header">
                                    <h4>Restore Database</h4>
                                </div>
                                <div class="card-body d-flex flex-column">
                                    <p>Restore database dari file backup sebelumnya.</p>
                                    <form method="POST" action="" class="d-flex flex-column flex-grow-1 mb-0">
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
                                        <button type="submit" name="restore_db" class="btn btn-warning mt-auto align-self-center">
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
                                                        <a href="../backups/<?php echo $record['nama_file']; ?>" class="btn btn-success btn-sm" target="_blank" title="Download">
                                                            <i class="fas fa-download"></i>
                                                        </a>
                                                        <button type="button" class="btn btn-danger btn-sm delete-backup-btn ml-1" data-id="<?php echo $record['id_backup']; ?>" data-file="<?php echo htmlspecialchars($record['nama_file']); ?>" title="Hapus">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
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
                
                // Handle delete backup button click
                $(document).on('click', '.delete-backup-btn', function() {
                    var backupId = $(this).data('id');
                    var backupFile = $(this).data('file');
                    
                    Swal.fire({
                        title: 'Hapus Backup?',
                        html: 'Apakah Anda yakin ingin menghapus backup file:<br><strong>' + backupFile + '</strong>?<br><br>File yang dihapus tidak dapat dikembalikan!',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#d33',
                        cancelButtonColor: '#3085d6',
                        confirmButtonText: 'Ya, Hapus!',
                        cancelButtonText: 'Batal'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            // Create form and submit
                            var form = $('<form>', {
                                'method': 'POST',
                                'action': ''
                            });
                            
                            form.append($('<input>', {
                                'type': 'hidden',
                                'name': 'delete_backup',
                                'value': '1'
                            }));
                            
                            form.append($('<input>', {
                                'type': 'hidden',
                                'name': 'backup_id',
                                'value': backupId
                            }));
                            
                            $('body').append(form);
                            form.submit();
                        }
                    });
                });
                // Proses backup: konfirmasi -> progres -> hasil
                \$('#backupForm').on('submit', function(e) {
                    e.preventDefault();

                    var formAction = \$(this).attr('action') || window.location.href;

                    Swal.fire({
                        title: 'Konfirmasi Backup',
                        html: 'Proses backup database akan dijalankan sekarang.<br>Pastikan tidak ada proses lain yang sedang menulis data.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: '#6777ef',
                        cancelButtonColor: '#d33',
                        confirmButtonText: 'Ya, Backup Sekarang',
                        cancelButtonText: 'Batal'
                    }).then(function(result) {
                        if (!result.isConfirmed) {
                            return;
                        }

                        var progressValue = 0;
                        var progressTimer = null;

                        function updateBackupProgress(value) {
                            var bar = document.getElementById('backupProgressBar');
                            if (bar) {
                                bar.style.width = value + '%';
                                bar.textContent = value + '%';
                                bar.setAttribute('aria-valuenow', value);
                            }
                            var txt = document.getElementById('backupProgressText');
                            if (txt) {
                                if (value < 30) {
                                    txt.textContent = 'Menyiapkan proses backup...';
                                } else if (value < 70) {
                                    txt.textContent = 'Mengekspor tabel dan data...';
                                } else if (value < 100) {
                                    txt.textContent = 'Menyimpan file backup...';
                                } else {
                                    txt.textContent = 'Selesai.';
                                }
                            }
                        }

                        Swal.fire({
                            title: 'Memproses Backup...',
                            html: '<div style=\"margin-top:12px;\">' +
                                  '<div class=\"progress\" style=\"height:18px;border-radius:9px;overflow:hidden;background:#e9ecef;\">' +
                                  '<div id=\"backupProgressBar\" class=\"progress-bar progress-bar-striped progress-bar-animated bg-primary\" role=\"progressbar\" aria-valuemin=\"0\" aria-valuemax=\"100\" aria-valuenow=\"0\" style=\"width:0%;transition:width .3s ease;\">0%</div>' +
                                  '</div>' +
                                  '<div id=\"backupProgressText\" style=\"margin-top:10px;font-size:13px;color:#6c757d;\">Menyiapkan proses backup...</div>' +
                                  '</div>',
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            showConfirmButton: false,
                            didOpen: function() {
                                progressTimer = setInterval(function() {
                                    if (progressValue < 90) {
                                        progressValue += Math.floor(Math.random() * 8) + 2;
                                        if (progressValue > 90) {
                                            progressValue = 90;
                                        }
                                        updateBackupProgress(progressValue);
                                    }
                                }, 400);
                            }
                        });

                        \$.ajax({
                            url: formAction,
                            type: 'POST',
                            dataType: 'json',
                            data: { backup_db: 1, ajax: 1 },
                            success: function(res) {
                                clearInterval(progressTimer);
                                updateBackupProgress(100);

                                setTimeout(function() {
                                    var ok = res && res.status === 'success';
                                    Swal.fire({
                                        icon: ok ? 'success' : 'error',
                                        title: ok ? 'Backup Berhasil' : 'Backup Gagal',
                                        text: (res && res.message) ? res.message : (ok ? 'Database berhasil dibackup.' : 'Terjadi kesalahan saat backup.'),
                                        confirmButtonColor: '#6777ef'
                                    }).then(function() {
                                        if (ok) {
                                            window.location.reload();
                                        }
                                    });
                                }, 600);
                            },
                            error: function() {
                                clearInterval(progressTimer);
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Backup Gagal',
                                    text: 'Tidak dapat menghubungi server. Silakan coba lagi.',
                                    confirmButtonColor: '#6777ef'
                                });
                            }
                        });
                    });
                });
            });
            ";
            
            include '../templates/footer.php';
            ?>
