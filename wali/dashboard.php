<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has wali level
if (!isAuthorized(['wali'])) {
    redirect('../login.php');
}

// Get school profile
$school_profile = getSchoolProfile($pdo);

// Get teacher information if needed for wali dashboard
if (isset($_SESSION['nama_guru']) && !empty($_SESSION['nama_guru'])) {
    $teacher_name = $_SESSION['nama_guru'];
} else {
    // For traditional login via tb_pengguna, get teacher name
    if ($_SESSION['level'] == 'wali' || $_SESSION['level'] == 'guru') {
        // Direct login via NUPTK, user_id is actually the id_guru
        $stmt = $pdo->prepare("SELECT id_guru, nama_guru FROM tb_guru WHERE id_guru = ?");
        $stmt->execute([$_SESSION['user_id']]);
    } else {
        // Traditional login via tb_pengguna
        $stmt = $pdo->prepare("SELECT g.id_guru, g.nama_guru FROM tb_guru g JOIN tb_pengguna p ON g.id_guru = p.id_guru WHERE p.id_pengguna = ?");
        $stmt->execute([$_SESSION['user_id']]);
    }
    $teacher_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $teacher = $teacher_result; // Store in $teacher for consistency
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

// Get student count for the wali's class
if ($wali_kelas) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_siswa FROM tb_siswa WHERE id_kelas = ?");
    $stmt->execute([$wali_kelas['id_kelas']]);
    $total_siswa = $stmt->fetch(PDO::FETCH_ASSOC)['total_siswa'];
} else {
    $total_siswa = 0;
}

// Get today's attendance statistics for the wali's class
if ($wali_kelas) {
    $stmt = $pdo->prepare("SELECT a.keterangan, COUNT(*) as jumlah FROM tb_absensi a JOIN tb_siswa s ON a.id_siswa = s.id_siswa WHERE s.id_kelas = ? AND a.tanggal = CURDATE() GROUP BY a.keterangan");
    $stmt->execute([$wali_kelas['id_kelas']]);
    $attendance_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $attendance_stats = [];
}

// Initialize counts
$jumlah_hadir = $jumlah_sakit = $jumlah_izin = $jumlah_alpa = 0;
foreach ($attendance_stats as $stat) {
    switch ($stat['keterangan']) {
        case 'Hadir':
            $jumlah_hadir = $stat['jumlah'];
            break;
        case 'Sakit':
            $jumlah_sakit = $stat['jumlah'];
            break;
        case 'Izin':
            $jumlah_izin = $stat['jumlah'];
            break;
        case 'Alpa':
            $jumlah_alpa = $stat['jumlah'];
            break;
    }
}

// Handle Attendance Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_attendance'])) {
    $attendance_status = $_POST['attendance_status'];
    $attendance_note = $_POST['attendance_note'] ?? '';
    
    // Determine teacher ID based on session
    $current_teacher_id = 0;
    if (isset($teacher['id_guru'])) {
        $current_teacher_id = $teacher['id_guru'];
    } elseif (isset($_SESSION['user_id']) && ($_SESSION['level'] == 'guru' || $_SESSION['level'] == 'wali')) {
         // Fallback if $teacher not set but user is guru/wali directly
         $current_teacher_id = $_SESSION['user_id'];
    }
    
    if ($current_teacher_id > 0) {
        $current_date = date('Y-m-d');
        
        // Check if already attended
        $check_stmt = $pdo->prepare("SELECT id_absensi FROM tb_absensi_guru WHERE id_guru = ? AND tanggal = ?");
        $check_stmt->execute([$current_teacher_id, $current_date]);
        
        if ($check_stmt->rowCount() > 0) {
            // Update existing
             $update_stmt = $pdo->prepare("UPDATE tb_absensi_guru SET status = ?, keterangan = ? WHERE id_guru = ? AND tanggal = ?");
             if ($update_stmt->execute([$attendance_status, $attendance_note, $current_teacher_id, $current_date])) {
                 echo "<script>
                    document.addEventListener('DOMContentLoaded', function() {
                        Swal.fire({
                            title: 'Berhasil!',
                            text: 'Absensi berhasil diperbarui.',
                            icon: 'success',
                            confirmButtonText: 'OK'
                        });
                    });
                 </script>";
             }
        } else {
            // Insert new
            $insert_stmt = $pdo->prepare("INSERT INTO tb_absensi_guru (id_guru, tanggal, status, keterangan) VALUES (?, ?, ?, ?)");
            if ($insert_stmt->execute([$current_teacher_id, $current_date, $attendance_status, $attendance_note])) {
                 echo "<script>
                    document.addEventListener('DOMContentLoaded', function() {
                        Swal.fire({
                            title: 'Berhasil!',
                            text: 'Absensi berhasil disimpan.',
                            icon: 'success',
                            confirmButtonText: 'OK'
                        });
                    });
                 </script>";
            }
        }
    }
}

$page_title = 'Dashboard Wali Kelas';

// Define CSS libraries for this page (only essential ones)
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'
    // Removed JQVMap since files don't exist
];

// Define JS libraries for this page (only essential ones)
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js'
    // Removed JQVMap since files don't exist
];

// Define page-specific JS
$js_page = [
    "
    // Wait for DOM to be fully loaded
    document.addEventListener('DOMContentLoaded', function() {
        // Small delay to ensure Chart.js is ready
        setTimeout(function() {
            // Ensure Chart.js is loaded before configuring
            if (typeof Chart === 'undefined') {
                console.error('Chart.js library not loaded');
                return;
            }
            
            // Configure Chart defaults if they exist (for v3.x)
            if (typeof Chart.defaults !== 'undefined') {
                if (typeof Chart.defaults.font !== 'undefined') {
                    // Chart.js v3+
                    Chart.defaults.font.family = 'Nunito, Segoe UI, Arial';
                    Chart.defaults.font.size = 12;
                    Chart.defaults.color = '#999';
                } else if (typeof Chart.defaults.global !== 'undefined') {
                    // Chart.js v2.x fallback
                    Chart.defaults.global.defaultFontFamily = 'Nunito, Segoe UI, Arial';
                    Chart.defaults.global.defaultFontSize = 12;
                    Chart.defaults.global.defaultFontColor = '#999';
                }
            }
            
            // Daily Attendance Chart
            var ctx = document.getElementById('myChart');
            if (ctx) {
                try {
                    var ctx2d = ctx.getContext('2d');
                    var myChart = new Chart(ctx2d, {
                        type: 'bar',
                        data: {
                            labels: ['Hadir', 'Sakit', 'Izin', 'Alpa'],
                            datasets: [{
                                label: 'Jumlah Siswa',
                                data: [
                                    " . $jumlah_hadir . ",
                                    " . $jumlah_sakit . ",
                                    " . $jumlah_izin . ",
                                    " . $jumlah_alpa . "
                                ],
                                backgroundColor: [
                                    'rgba(54, 162, 235, 0.2)',
                                    'rgba(255, 99, 132, 0.2)',
                                    'rgba(255, 206, 86, 0.2)',
                                    'rgba(153, 102, 255, 0.2)'
                                ],
                                borderColor: [
                                    'rgba(54, 162, 235, 1)',
                                    'rgba(255,99,132,1)',
                                    'rgba(255, 206, 86, 1)',
                                    'rgba(153, 102, 255, 1)'
                                ],
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'top',
                                },
                                title: {
                                    display: true,
                                    text: 'Statistik Kehadiran Harian'
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            if (Number.isInteger(value)) {
                                                return value;
                                            }
                                        }
                                    },
                                    title: {
                                        display: true,
                                        text: 'Jumlah Siswa'
                                    }
                                },
                                x: {
                                    title: {
                                        display: true,
                                        text: 'Status Kehadiran'
                                    }
                                }
                            }
                        }
                    });
                } catch (e) {
                    console.error('Error creating daily attendance chart:', e);
                }
            }
        }, 500);
    });
    "
];

include '../templates/user_header.php';

include '../templates/sidebar.php';

// Start HTML output after including templates
?>

<!-- Main Content -->
<div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Dashboard Wali Kelas</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item active"><a href="#">Dashboard</a></div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-primary">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Nama Wali Kelas</h4>
                                    </div>
                                    <div class="card-body" style="font-size: 0.85rem; line-height: 1.3;">
                                        <?php 
                                        // Clean up teacher name and make it smaller
                                        $nama_wali = trim($teacher_name);
                                        $nama_wali = preg_replace('/\s+/', ' ', $nama_wali);
                                        echo htmlspecialchars($nama_wali); 
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-success">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Total Siswa</h4>
                                    </div>
                                    <div class="card-body">
                                        <?php echo $total_siswa; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-success">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Hadir</h4>
                                    </div>
                                    <div class="card-body">
                                        <?php echo $jumlah_hadir; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-warning">
                                    <i class="fas fa-heartbeat"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Sakit</h4>
                                    </div>
                                    <div class="card-body">
                                        <?php echo $jumlah_sakit; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-info">
                                    <i class="fas fa-file-alt"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Izin</h4>
                                    </div>
                                    <div class="card-body">
                                        <?php echo $jumlah_izin; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-danger">
                                    <i class="fas fa-times-circle"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Alpa</h4>
                                    </div>
                                    <div class="card-body">
                                        <?php echo $jumlah_alpa; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Attendance Box for Teacher -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Absensi Harian & Jurnal Mengajar</h4>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-light alert-has-icon">
                                        <div class="alert-icon"><i class="far fa-bell"></i></div>
                                        <div class="alert-body">
                                            <div class="alert-title">Penting</div>
                                            Jangan lupa untuk mengisi <b>Absensi Kehadiran</b> Anda dan <b>Jurnal Mengajar</b> hari ini.
                                        </div>
                                    </div>

                                    <?php
                                    // Check current attendance status
                                    $today_attendance = null;
                                    if (isset($teacher['id_guru'])) {
                                        $stmt_check = $pdo->prepare("SELECT * FROM tb_absensi_guru WHERE id_guru = ? AND tanggal = CURDATE()");
                                        $stmt_check->execute([$teacher['id_guru']]);
                                        $today_attendance = $stmt_check->fetch(PDO::FETCH_ASSOC);
                                    }
                                    ?>

                                    <form method="POST" action="" id="attendanceForm">
                                        <div class="form-group">
                                            <label class="d-block font-weight-bold">Status Kehadiran Hari Ini (<?php echo date('d-m-Y'); ?>)</label>
                                            <div class="selectgroup selectgroup-pills">
                                                <label class="selectgroup-item">
                                                    <input type="radio" name="attendance_status" value="hadir" class="selectgroup-input" <?php echo ($today_attendance && $today_attendance['status'] == 'hadir') ? 'checked' : ''; ?> required>
                                                    <span class="selectgroup-button selectgroup-button-icon"><i class="fas fa-check"></i> Hadir</span>
                                                </label>
                                                <label class="selectgroup-item">
                                                    <input type="radio" name="attendance_status" value="sakit" class="selectgroup-input" <?php echo ($today_attendance && $today_attendance['status'] == 'sakit') ? 'checked' : ''; ?>>
                                                    <span class="selectgroup-button selectgroup-button-icon"><i class="fas fa-procedures"></i> Sakit</span>
                                                </label>
                                                <label class="selectgroup-item">
                                                    <input type="radio" name="attendance_status" value="izin" class="selectgroup-input" id="radio_izin" <?php echo ($today_attendance && $today_attendance['status'] == 'izin') ? 'checked' : ''; ?>>
                                                    <span class="selectgroup-button selectgroup-button-icon"><i class="fas fa-paper-plane"></i> Izin</span>
                                                </label>
                                            </div>
                                        </div>

                                        <div class="form-group" id="keterangan_box" style="display: <?php echo ($today_attendance && $today_attendance['status'] == 'izin') ? 'block' : 'none'; ?>;">
                                            <label>Keterangan Izin</label>
                                            <textarea name="attendance_note" class="form-control" placeholder="Masukkan alasan izin..."><?php echo $today_attendance ? htmlspecialchars($today_attendance['keterangan']) : ''; ?></textarea>
                                        </div>

                                        <div class="form-group">
                                            <button type="submit" name="submit_attendance" class="btn btn-primary btn-lg btn-icon icon-left"><i class="fas fa-save"></i> Simpan Absensi</button>
                                            <a href="jurnal_mengajar.php" class="btn btn-info btn-lg btn-icon icon-left ml-2"><i class="fas fa-book-open"></i> Isi Jurnal Mengajar</a>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const radioButtons = document.querySelectorAll('input[name="attendance_status"]');
                        const keteranganBox = document.getElementById('keterangan_box');
                        
                        radioButtons.forEach(radio => {
                            radio.addEventListener('change', function() {
                                if (this.value === 'izin') {
                                    keteranganBox.style.display = 'block';
                                    keteranganBox.querySelector('textarea').required = true;
                                } else {
                                    keteranganBox.style.display = 'none';
                                    keteranganBox.querySelector('textarea').required = false;
                                }
                            });
                        });
                    });
                    </script>
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Grafik Kehadiran Hari Ini</h4>
                                </div>
                                <div class="card-body">
                                    <canvas id="myChart" height="158"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Student List Section -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Data Siswa Kelas <?php echo $wali_kelas ? htmlspecialchars($wali_kelas['nama_kelas'] ?? 'Tidak Ada Kelas') : 'Tidak Ada Kelas'; ?></h4>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-striped" id="table-siswa">
                                            <thead>
                                                <tr>
                                                    <th>No</th>
                                                    <th>Nama Siswa</th>
                                                    <th>NISN</th>
                                                    <th>Jenis Kelamin</th>
                                                    <th>Status Hari Ini</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                if ($wali_kelas) {
                                                    $siswa_stmt = $pdo->prepare("SELECT s.*, a.keterangan as attendance_status FROM tb_siswa s LEFT JOIN tb_absensi a ON s.id_siswa = a.id_siswa AND a.tanggal = CURDATE() WHERE s.id_kelas = ? ORDER BY s.nama_siswa ASC");
                                                    $siswa_stmt->execute([$wali_kelas['id_kelas']]);
                                                    $siswa_list = $siswa_stmt->fetchAll(PDO::FETCH_ASSOC);
                                                    
                                                    $no = 1;
                                                    foreach ($siswa_list as $siswa):
                                                ?>
                                                <tr>
                                                    <td><?php echo $no++; ?></td>
                                                    <td><?php echo htmlspecialchars($siswa['nama_siswa']); ?></td>
                                                    <td><?php echo htmlspecialchars($siswa['nisn']); ?></td>
                                                    <td><?php echo $siswa['jenis_kelamin'] == 'L' ? 'Laki-laki' : ($siswa['jenis_kelamin'] == 'P' ? 'Perempuan' : '-'); ?></td>
                                                    <td>
                                                        <div class="badge badge-<?php 
                                                            switch(strtolower($siswa['attendance_status'] ?? 'Belum Absen')) {
                                                                case 'hadir': echo 'success'; break;
                                                                case 'sakit': echo 'warning'; break;
                                                                case 'izin': echo 'info'; break;
                                                                case 'alpa': echo 'danger'; break;
                                                                default: echo 'secondary'; break;
                                                            }
                                                        ?>"><?php echo htmlspecialchars($siswa['attendance_status'] ?? 'Belum Absen'); ?></div>
                                                    </td>
                                                </tr>
                                                <?php 
                                                    endforeach;
                                                } else {
                                                ?>
                                                <tr>
                                                    <td colspan="5" class="text-center">Tidak ada kelas yang diajar</td>
                                                </tr>
                                                <?php } ?>
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
    // Add DataTables initialization for table-siswa
    if (!isset($js_page)) {
        $js_page = [];
    }
    $js_page[] = "
    $(document).ready(function() {
        if ($.fn.DataTable && $('#table-siswa').length > 0) {
            $('#table-siswa').DataTable({
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
    ";
    include '../templates/user_footer.php'; 
    ?>