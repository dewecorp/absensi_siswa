<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

// Set page title
$page_title = 'Dashboard';

// Get statistics
$stmt = $pdo->query("SELECT COUNT(*) as total_siswa FROM tb_siswa");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$total_siswa = isset($result['total_siswa']) ? (int)$result['total_siswa'] : 0;

$stmt = $pdo->query("SELECT COUNT(*) as total_kelas FROM tb_kelas");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$total_kelas = isset($result['total_kelas']) ? (int)$result['total_kelas'] : 0;

$stmt = $pdo->query("SELECT COUNT(*) as total_guru FROM tb_guru");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$total_guru = isset($result['total_guru']) ? (int)$result['total_guru'] : 0;

$stmt = $pdo->prepare("SELECT COUNT(*) as hadir FROM tb_absensi WHERE keterangan = 'Hadir' AND tanggal = CURDATE()");
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$jumlah_hadir = isset($result['hadir']) ? (int)$result['hadir'] : 0;

$stmt = $pdo->prepare("SELECT COUNT(*) as sakit FROM tb_absensi WHERE keterangan = 'Sakit' AND tanggal = CURDATE()");
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$jumlah_sakit = isset($result['sakit']) ? (int)$result['sakit'] : 0;

$stmt = $pdo->prepare("SELECT COUNT(*) as izin FROM tb_absensi WHERE keterangan = 'Izin' AND tanggal = CURDATE()");
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$jumlah_izin = isset($result['izin']) ? (int)$result['izin'] : 0;

$stmt = $pdo->prepare("SELECT COUNT(*) as alpa FROM tb_absensi WHERE keterangan = 'Alpa' AND tanggal = CURDATE()");
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$jumlah_alpa = isset($result['alpa']) ? (int)$result['alpa'] : 0;

// Delete old activities first (older than 25 hours to avoid deleting recent activities)
try {
    $delete_stmt = $pdo->prepare("DELETE FROM tb_activity_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $delete_stmt->execute();
} catch (Exception $e) {
    error_log("Error deleting old activities: " . $e->getMessage());
}

// Get recent activities from the activity log with teacher names (only last 24 hours)
$activities = []; // Initialize as empty array
$total_activities = 0;
try {
    // Get activities from last 24 hours with teacher names
    $activity_stmt = $pdo->query("
        SELECT 
            a.username, 
            a.action, 
            a.description, 
            a.created_at,
            COALESCE(g.nama_guru, a.username) as display_name
        FROM tb_activity_log a
        LEFT JOIN tb_guru g ON a.username = g.nuptk OR a.username = g.nama_guru OR a.username = CAST(g.id_guru AS CHAR)
        WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ORDER BY a.created_at DESC 
        LIMIT 10
    ");
    $activities = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count of activities (only last 24 hours)
    $count_stmt = $pdo->query("SELECT COUNT(*) as total FROM tb_activity_log a
        LEFT JOIN tb_guru g ON a.username = g.nuptk OR a.username = g.nama_guru OR a.username = CAST(g.id_guru AS CHAR)
        WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
    $total_activities = isset($count_result['total']) ? (int)$count_result['total'] : 0;
} catch (Exception $e) {
    // If there's an error (e.g., table doesn't exist), keep the array empty
    $activities = [];
    $total_activities = 0;
    error_log("Error fetching activity log: " . $e->getMessage());
}

// Get attendance trend data for the last 7 days
$trend_stmt = $pdo->prepare(
    "SELECT 
        DATE(tanggal) as tanggal,
        SUM(CASE WHEN keterangan = 'Hadir' THEN 1 ELSE 0 END) as hadir,
        SUM(CASE WHEN keterangan = 'Sakit' THEN 1 ELSE 0 END) as sakit,
        SUM(CASE WHEN keterangan = 'Izin' THEN 1 ELSE 0 END) as izin,
        SUM(CASE WHEN keterangan = 'Alpa' THEN 1 ELSE 0 END) as alpa
    FROM tb_absensi 
    WHERE tanggal >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(tanggal)
    ORDER BY tanggal ASC"
);
$trend_stmt->execute();
$attendance_trends = $trend_stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare data for chart
$dates = [];
$hadir_data = [];
$sakit_data = [];
$izin_data = [];
$alpa_data = [];

foreach ($attendance_trends as $trend) {
    $dates[] = $trend['tanggal'] ? date('d M', strtotime($trend['tanggal'])) : '';
    $hadir_data[] = isset($trend['hadir']) ? (int)$trend['hadir'] : 0;
    $sakit_data[] = isset($trend['sakit']) ? (int)$trend['sakit'] : 0;
    $izin_data[] = isset($trend['izin']) ? (int)$trend['izin'] : 0;
    $alpa_data[] = isset($trend['alpa']) ? (int)$trend['alpa'] : 0;
}

// Convert arrays to JSON-safe format
$dates_json = json_encode($dates);
$hadir_data_json = json_encode($hadir_data);
$sakit_data_json = json_encode($sakit_data);
$izin_data_json = json_encode($izin_data);
$alpa_data_json = json_encode($alpa_data);

// Define CSS libraries for this page (only essential ones)
$css_libs = [
    // Removed JQVMap since files don't exist
];

// Define JS libraries for this page (only essential ones)
$js_libs = [
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
            
            // Trend Chart
            var trendCtx = document.getElementById('trendChart');
            if (trendCtx) {
                try {
                    var trendCtx2d = trendCtx.getContext('2d');
                    var trendChart = new Chart(trendCtx2d, {
                        type: 'line',
                        data: {
                            labels: " . $dates_json . ",
                            datasets: [{
                                label: 'Hadir',
                                data: " . $hadir_data_json . ",
                                borderColor: 'rgb(54, 162, 235)',
                                backgroundColor: 'rgba(54, 162, 235, 0.2)',
                                fill: false
                            }, {
                                label: 'Sakit',
                                data: " . $sakit_data_json . ",
                                borderColor: 'rgb(255, 99, 132)',
                                backgroundColor: 'rgba(255, 99, 132, 0.2)',
                                fill: false
                            }, {
                                label: 'Izin',
                                data: " . $izin_data_json . ",
                                borderColor: 'rgb(255, 206, 86)',
                                backgroundColor: 'rgba(255, 206, 86, 0.2)',
                                fill: false
                            }, {
                                label: 'Alpa',
                                data: " . $alpa_data_json . ",
                                borderColor: 'rgb(153, 102, 255)',
                                backgroundColor: 'rgba(153, 102, 255, 0.2)',
                                fill: false
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Trend Kehadiran 7 Hari Terakhir'
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Jumlah Siswa'
                                    }
                                },
                                x: {
                                    title: {
                                        display: true,
                                        text: 'Tanggal'
                                    }
                                }
                            }
                        }
                    });
                } catch (e) {
                    console.error('Error creating trend chart:', e);
                }
            }
        }, 500);
    });
    "
];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

            <!-- Main Content -->
            <div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Dashboard</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item active"><a href="#">Dashboard</a></div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-primary">
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
                                    <i class="far fa-user"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Total Siswa Hadir</h4>
                                    </div>
                                    <div class="card-body">
                                        <?php echo $jumlah_hadir; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-danger">
                                    <i class="far fa-times-circle"></i>
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
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-warning">
                                    <i class="far fa-flag"></i>
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
                    </div>
                    
                    <div class="row">
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-secondary">
                                    <i class="fas fa-user-slash"></i>
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
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-info">
                                    <i class="fas fa-chalkboard-teacher"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Total Guru</h4>
                                    </div>
                                    <div class="card-body">
                                        <?php echo $total_guru; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-warning">
                                    <i class="fas fa-school"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Total Kelas</h4>
                                    </div>
                                    <div class="card-body">
                                        <?php echo $total_kelas; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Grafik Kehadiran Hari Ini</h4>
                                </div>
                                <div class="card-body">
                                    <canvas id="myChart" width="400" height="150" style="width:100%; max-height:400px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Grafik Trend Kehadiran 7 Hari Terakhir</h4>
                                </div>
                                <div class="card-body">
                                    <canvas id="trendChart" width="400" height="150" style="width:100%; max-height:400px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Ringkasan Kehadiran</h4>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-info">
                                        <strong>Statistik Hari Ini (<?php echo date('d M Y'); ?>):</strong><br>
                                        Hadir: <?php echo $jumlah_hadir; ?> siswa<br>
                                        Sakit: <?php echo $jumlah_sakit; ?> siswa<br>
                                        Izin: <?php echo $jumlah_izin; ?> siswa<br>
                                        Alpa: <?php echo $jumlah_alpa; ?> siswa
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Statistik Minggu Ini</h4>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-light">
                                        <?php
                                        $stmt = $pdo->prepare("
                                            SELECT 
                                                SUM(CASE WHEN keterangan = 'Hadir' THEN 1 ELSE 0 END) as total_hadir,
                                                SUM(CASE WHEN keterangan = 'Sakit' THEN 1 ELSE 0 END) as total_sakit,
                                                SUM(CASE WHEN keterangan = 'Izin' THEN 1 ELSE 0 END) as total_izin,
                                                SUM(CASE WHEN keterangan = 'Alpa' THEN 1 ELSE 0 END) as total_alpa
                                            FROM tb_absensi 
                                            WHERE tanggal >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                                        ");
                                        $stmt->execute();
                                        $weekly_stats = $stmt->fetch(PDO::FETCH_ASSOC);
                                        
                                        echo "7 hari terakhir:<br>";
                                        echo "Hadir: " . (isset($weekly_stats['total_hadir']) ? $weekly_stats['total_hadir'] : 0) . " siswa<br>";
                                        echo "Sakit: " . (isset($weekly_stats['total_sakit']) ? $weekly_stats['total_sakit'] : 0) . " siswa<br>";
                                        echo "Izin: " . (isset($weekly_stats['total_izin']) ? $weekly_stats['total_izin'] : 0) . " siswa<br>";
                                        echo "Alpa: " . (isset($weekly_stats['total_alpa']) ? $weekly_stats['total_alpa'] : 0) . " siswa";
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Aktivitas Pengguna <span class="badge badge-primary"><?php echo $total_activities; ?></span></h4>
                                </div>
                                <div class="card-body">
                                    <div class="activities" style="max-height: 400px; overflow-y: auto;">
                                        <?php 
                                        if (!empty($activities)):
                                            foreach ($activities as $activity): 
                                        ?>
                                        <div class="activity">
                                            <div class="activity-icon bg-primary text-white shadow-primary">
                                                <i class="<?php 
                                                    if (function_exists('getActivityIcon')) {
                                                        echo getActivityIcon(htmlspecialchars($activity['action']));
                                                    } else {
                                                        echo 'fas fa-info-circle';
                                                    }
                                                ?>"></i>
                                            </div>
                                            <div class="activity-detail">
                                                <div class="mb-2">
                                                    <span class="text-job text-primary text-capitalize"><?php echo htmlspecialchars($activity['display_name'] ?? $activity['username']); ?></span>
                                                    <span class="text-muted"><?php 
                                                        if (function_exists('timeAgo')) {
                                                            echo timeAgo($activity['created_at']);
                                                        } else {
                                                            echo $activity['created_at'];
                                                        }
                                                    ?></span>
                                                </div>
                                                <p>
                                                    <strong><?php echo htmlspecialchars($activity['action']); ?></strong>: 
                                                    <?php echo htmlspecialchars($activity['description']); ?>
                                                </p>
                                            </div>
                                        </div>
                                        <?php 
                                            endforeach; 
                                        else: 
                                        ?>
                                        <div class="text-center py-4">
                                            <p class="text-muted">Tidak ada aktivitas terbaru</p>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
            

<?php
include '../templates/footer.php';
?>