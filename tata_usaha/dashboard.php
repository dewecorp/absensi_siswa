<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has tata_usaha level
if (!isAuthorized(['tata_usaha'])) {
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

// Get recent activities from the activity log
$activities = []; // Initialize as empty array
$total_activities = 0;
try {
    // Get total count of all activities first
    $count_stmt = $pdo->query("SELECT COUNT(*) as total FROM tb_activity_log");
    $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
    $total_activities = isset($count_result['total']) ? (int)$count_result['total'] : 0;
    
    if ($total_activities > 0) {
        // Create teacher mapping to avoid JOIN collation issues
        $teacher_map = [];
        $guru_stmt = $pdo->query("SELECT nuptk, nama_guru, id_guru FROM tb_guru");
        $gurus = $guru_stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($gurus as $guru) {
            $teacher_map[$guru['nuptk']] = $guru['nama_guru'];
            $teacher_map[$guru['nama_guru']] = $guru['nama_guru'];
            $teacher_map[$guru['id_guru']] = $guru['nama_guru'];
        }
        
        // Get latest 20 activities
        $activity_stmt = $pdo->query(
            "SELECT 
                username, 
                action, 
                description, 
                created_at
            FROM tb_activity_log 
            ORDER BY created_at DESC 
            LIMIT 20"
        );
        $activities = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add display_name to each activity
        foreach ($activities as &$activity) {
            $display_name = $teacher_map[$activity['username']] ?? $activity['username'];
            $activity['display_name'] = $display_name;
        }
    }
} catch (Exception $e) {
    // If there's an error (e.g., table doesn't exist), keep the array empty
    $activities = [];
    $total_activities = 0;
    error_log("Error fetching activity log: " . $e->getMessage());
}

// Get attendance trend data for the current month
$trend_stmt = $pdo->prepare(
    "SELECT 
        DATE(tanggal) as tanggal,
        SUM(CASE WHEN keterangan = 'Hadir' THEN 1 ELSE 0 END) as hadir,
        SUM(CASE WHEN keterangan = 'Sakit' THEN 1 ELSE 0 END) as sakit,
        SUM(CASE WHEN keterangan = 'Izin' THEN 1 ELSE 0 END) as izin,
        SUM(CASE WHEN keterangan = 'Alpa' THEN 1 ELSE 0 END) as alpa
    FROM tb_absensi 
    WHERE tanggal >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
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

// Get teacher attendance stats
$stmt = $pdo->prepare("SELECT COUNT(*) as hadir FROM tb_absensi_guru WHERE status = 'Hadir' AND tanggal = CURDATE()");
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$guru_hadir = isset($result['hadir']) ? (int)$result['hadir'] : 0;

$stmt = $pdo->prepare("SELECT COUNT(*) as sakit FROM tb_absensi_guru WHERE status = 'Sakit' AND tanggal = CURDATE()");
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$guru_sakit = isset($result['sakit']) ? (int)$result['sakit'] : 0;

$stmt = $pdo->prepare("SELECT COUNT(*) as izin FROM tb_absensi_guru WHERE status = 'Izin' AND tanggal = CURDATE()");
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$guru_izin = isset($result['izin']) ? (int)$result['izin'] : 0;

// Get teacher attendance trend data for the current month
$stmt = $pdo->prepare(
    "SELECT 
        DATE(tanggal) as tanggal,
        SUM(CASE WHEN status = 'Hadir' THEN 1 ELSE 0 END) as hadir,
        SUM(CASE WHEN status = 'Sakit' THEN 1 ELSE 0 END) as sakit,
        SUM(CASE WHEN status = 'Izin' THEN 1 ELSE 0 END) as izin
    FROM tb_absensi_guru 
    WHERE tanggal >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
    GROUP BY DATE(tanggal)
    ORDER BY tanggal ASC"
);
$stmt->execute();
$guru_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);

$guru_dates = [];
$guru_hadir_data = [];
$guru_sakit_data = [];
$guru_izin_data = [];

foreach ($guru_trends as $trend) {
    $guru_dates[] = $trend['tanggal'] ? date('d M', strtotime($trend['tanggal'])) : '';
    $h = isset($trend['hadir']) ? (int)$trend['hadir'] : 0;
    $s = isset($trend['sakit']) ? (int)$trend['sakit'] : 0;
    $i = isset($trend['izin']) ? (int)$trend['izin'] : 0;
    
    $guru_hadir_data[] = $h;
    $guru_sakit_data[] = $s;
    $guru_izin_data[] = $i;
}

$guru_dates_json = json_encode($guru_dates);
$guru_hadir_data_json = json_encode($guru_hadir_data);
$guru_sakit_data_json = json_encode($guru_sakit_data);
$guru_izin_data_json = json_encode($guru_izin_data);

// Get latest teacher attendance (today)
$stmt = $pdo->prepare("
    SELECT g.nama_guru, a.status, a.waktu_input
    FROM tb_absensi_guru a
    JOIN tb_guru g ON a.id_guru = g.id_guru
    WHERE a.tanggal = CURDATE()
    ORDER BY a.waktu_input DESC
    LIMIT 10
");
$stmt->execute();
$latest_guru_attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get latest student attendance (today)
$stmt = $pdo->prepare("
    SELECT s.nama_siswa, k.nama_kelas, a.keterangan, a.jam_masuk
    FROM tb_absensi a
    JOIN tb_siswa s ON a.id_siswa = s.id_siswa
    JOIN tb_kelas k ON s.id_kelas = k.id_kelas
    WHERE a.tanggal = CURDATE()
    ORDER BY a.jam_masuk DESC
    LIMIT 10
");
$stmt->execute();
$latest_siswa_attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            var isMobile = window.matchMedia('(max-width: 576px)').matches;
            if (typeof Chart === 'undefined') {
                console.error('Chart.js library not loaded');
                return;
            }
            if (typeof Chart.defaults !== 'undefined') {
                if (typeof Chart.defaults.font !== 'undefined') {
                    Chart.defaults.font.family = 'Nunito, Segoe UI, Arial';
                    Chart.defaults.font.size = isMobile ? 13 : 12;
                    Chart.defaults.color = '#999';
                } else if (typeof Chart.defaults.global !== 'undefined') {
                    Chart.defaults.global.defaultFontFamily = 'Nunito, Segoe UI, Arial';
                    Chart.defaults.global.defaultFontSize = isMobile ? 13 : 12;
                    Chart.defaults.global.defaultFontColor = '#999';
                }
            }
            
            // Daily Attendance Chart
            var ctx = document.getElementById('myChart');
            if (ctx) {
                try {
                    var ctx2d = ctx.getContext('2d');
                    ctx.style.height = isMobile ? '280px' : '220px';
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
                                        callback: function(value) { if (Number.isInteger(value)) { return value; } },
                                        font: { size: isMobile ? 12 : 11 }
                                    },
                                    title: {
                                        display: true,
                                        text: 'Jumlah Siswa'
                                    }
                                },
                                x: {
                                    ticks: { maxRotation: 0, autoSkip: true, font: { size: isMobile ? 12 : 11 } },
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
                    trendCtx.style.height = isMobile ? '300px' : '240px';
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
                                    text: 'Trend Kehadiran Bulan Ini'
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: { font: { size: isMobile ? 12 : 11 } },
                                    title: {
                                        display: true,
                                        text: 'Jumlah Siswa'
                                    }
                                },
                                x: {
                                    ticks: { maxRotation: 0, autoSkip: true, font: { size: isMobile ? 12 : 11 } },
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
            
            // Guru Daily Chart
            var guruDailyCtx = document.getElementById('guruDailyChart');
            if (guruDailyCtx) {
                try {
                    var guruDailyCtx2d = guruDailyCtx.getContext('2d');
                    guruDailyCtx.style.height = isMobile ? '280px' : '220px';
                    var guruDailyChart = new Chart(guruDailyCtx2d, {
                        type: 'bar',
                        data: {
                            labels: ['Hadir', 'Sakit', 'Izin'],
                            datasets: [{
                                label: 'Jumlah Guru',
                                data: [
                                    " . $guru_hadir . ",
                                    " . $guru_sakit . ",
                                    " . $guru_izin . "
                                ],
                                backgroundColor: [
                                    'rgba(54, 162, 235, 0.2)',
                                    'rgba(255, 99, 132, 0.2)',
                                    'rgba(255, 206, 86, 0.2)'
                                ],
                                borderColor: [
                                    'rgba(54, 162, 235, 1)',
                                    'rgba(255,99,132,1)',
                                    'rgba(255, 206, 86, 1)'
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
                                    text: 'Statistik Kehadiran Guru Hari Ini'
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1,
                                        font: { size: isMobile ? 12 : 11 }
                                    },
                                    title: {
                                        display: true,
                                        text: 'Jumlah Guru'
                                    }
                                },
                                x: {
                                    ticks: { maxRotation: 0, autoSkip: true, font: { size: isMobile ? 12 : 11 } },
                                    title: {
                                        display: true,
                                        text: 'Status Kehadiran'
                                    }
                                }
                            }
                        }
                    });
                } catch (e) {
                    console.error('Error creating guru daily chart:', e);
                }
            }
            
            // Guru Trend Chart
            var guruCtx = document.getElementById('guruChart');
            if (guruCtx) {
                try {
                    var guruCtx2d = guruCtx.getContext('2d');
                    guruCtx.style.height = isMobile ? '300px' : '240px';
                    var guruChart = new Chart(guruCtx2d, {
                        type: 'line',
                        data: {
                            labels: " . $guru_dates_json . ",
                            datasets: [{
                                label: 'Hadir',
                                data: " . $guru_hadir_data_json . ",
                                borderColor: 'rgb(54, 162, 235)',
                                backgroundColor: 'rgba(54, 162, 235, 0.2)',
                                fill: false,
                                tension: 0.4
                            }, {
                                label: 'Sakit',
                                data: " . $guru_sakit_data_json . ",
                                borderColor: 'rgb(255, 99, 132)',
                                backgroundColor: 'rgba(255, 99, 132, 0.2)',
                                fill: false,
                                tension: 0.4
                            }, {
                                label: 'Izin',
                                data: " . $guru_izin_data_json . ",
                                borderColor: 'rgb(255, 206, 86)',
                                backgroundColor: 'rgba(255, 206, 86, 0.2)',
                                fill: false,
                                tension: 0.4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Trend Kehadiran Guru Bulan Ini'
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Jumlah Guru'
                                    },
                                    ticks: {
                                        stepSize: 1,
                                        font: { size: isMobile ? 12 : 11 }
                                    }
                                },
                                x: {
                                    ticks: { maxRotation: 0, autoSkip: true, font: { size: isMobile ? 12 : 11 } },
                                    title: {
                                        display: true,
                                        text: 'Tanggal'
                                    }
                                }
                            }
                        }
                    });
                } catch (e) {
                    console.error('Error creating guru trend chart:', e);
                }
            }
        }, 500);
    });
    "
];

// Check for old data (suggestion for cleanup)
$show_cleanup_alert = false;
$cleanup_date_threshold = date('Y-m-d', strtotime('-1 year'));
try {
    $check_old_stmt = $pdo->prepare("SELECT COUNT(*) FROM tb_absensi WHERE tanggal < ?");
    $check_old_stmt->execute([$cleanup_date_threshold]);
    if ($check_old_stmt->fetchColumn() > 0) {
        $show_cleanup_alert = true;
    }
} catch (Exception $e) {
    // Ignore error
}

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
                        <div class="col-lg-4 col-md-6 col-sm-6 col-12">
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
                        <div class="col-lg-4 col-md-6 col-sm-6 col-12">
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
                        <div class="col-lg-4 col-md-6 col-sm-6 col-12">
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
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-success">
                                    <i class="fas fa-user-check"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Siswa Hadir</h4>
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
                                    <i class="fas fa-user-injured"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Siswa Sakit</h4>
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
                                    <i class="fas fa-user-clock"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Siswa Izin</h4>
                                    </div>
                                    <div class="card-body">
                                        <?php echo $jumlah_izin; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-secondary">
                                    <i class="fas fa-user-slash"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Siswa Alpa</h4>
                                    </div>
                                    <div class="card-body">
                                        <?php echo $jumlah_alpa; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-4 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-success">
                                    <i class="fas fa-user-check"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Guru Hadir</h4>
                                    </div>
                                    <div class="card-body">
                                        <?php echo $guru_hadir; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-danger">
                                    <i class="fas fa-user-injured"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Guru Sakit</h4>
                                    </div>
                                    <div class="card-body">
                                        <?php echo $guru_sakit; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 col-md-6 col-sm-6 col-12">
                            <div class="card card-statistic-1">
                                <div class="card-icon bg-warning">
                                    <i class="fas fa-user-clock"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Guru Izin</h4>
                                    </div>
                                    <div class="card-body">
                                        <?php echo $guru_izin; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-6 col-md-12 col-12 col-sm-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Grafik Kehadiran Guru Hari Ini</h4>
                                </div>
                                <div class="card-body">
                                    <canvas id="guruDailyChart" width="400" height="150" style="width:100%; max-height:400px;"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 col-md-12 col-12 col-sm-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Grafik Trend Kehadiran Guru (Bulan Ini)</h4>
                                </div>
                                <div class="card-body">
                                    <canvas id="guruChart" width="400" height="150" style="width:100%; max-height:400px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-6 col-md-12 col-12 col-sm-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Grafik Kehadiran Siswa Hari Ini</h4>
                                </div>
                                <div class="card-body">
                                    <canvas id="myChart" width="400" height="150" style="width:100%; max-height:400px;"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 col-md-12 col-12 col-sm-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Grafik Trend Kehadiran Siswa (Bulan Ini)</h4>
                                </div>
                                <div class="card-body">
                                    <canvas id="trendChart" width="400" height="150" style="width:100%; max-height:400px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-lg-6 col-md-12 col-12 col-sm-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Rekap Absensi Guru Hari Ini (Terbaru)</h4>
                                    <div class="card-header-action">
                                        <a href="../admin/rekap_absensi_guru.php" class="btn btn-primary">Lihat Semua</a>
                                    </div>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-striped mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Nama Guru</th>
                                                    <th>Status</th>
                                                    <th>Waktu</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($latest_guru_attendance) > 0): ?>
                                                    <?php foreach ($latest_guru_attendance as $row): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($row['nama_guru']); ?></td>
                                                            <td>
                                                                <?php 
                                                                $badge_class = 'badge-secondary';
                                                                if ($row['status'] == 'Hadir') $badge_class = 'badge-success';
                                                                elseif ($row['status'] == 'Sakit') $badge_class = 'badge-danger';
                                                                elseif ($row['status'] == 'Izin') $badge_class = 'badge-warning';
                                                                ?>
                                                                <div class="badge <?php echo $badge_class; ?>"><?php echo $row['status']; ?></div>
                                                            </td>
                                                            <td><?php echo isset($row['waktu_input']) && $row['waktu_input'] ? date('H:i:s', strtotime($row['waktu_input'])) : '-'; ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="3" class="text-center">Belum ada data absensi guru hari ini.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 col-md-12 col-12 col-sm-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Rekap Absensi Siswa Hari Ini (Terbaru)</h4>
                                    <div class="card-header-action">
                                        <a href="../admin/rekap_absensi.php" class="btn btn-primary">Lihat Semua</a>
                                    </div>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-striped mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Nama Siswa</th>
                                                    <th>Kelas</th>
                                                    <th>Status</th>
                                                    <th>Waktu</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($latest_siswa_attendance) > 0): ?>
                                                    <?php foreach ($latest_siswa_attendance as $row): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($row['nama_siswa']); ?></td>
                                                            <td><?php echo htmlspecialchars($row['nama_kelas']); ?></td>
                                                            <td>
                                                                <?php 
                                                                $badge_class = 'badge-secondary';
                                                                if ($row['keterangan'] == 'Hadir') $badge_class = 'badge-success';
                                                                elseif ($row['keterangan'] == 'Sakit') $badge_class = 'badge-danger';
                                                                elseif ($row['keterangan'] == 'Izin') $badge_class = 'badge-warning';
                                                                elseif ($row['keterangan'] == 'Alpa') $badge_class = 'badge-danger';
                                                                ?>
                                                                <div class="badge <?php echo $badge_class; ?>"><?php echo $row['keterangan']; ?></div>
                                                            </td>
                                                            <td><?php echo isset($row['jam_masuk']) && $row['jam_masuk'] ? date('H:i:s', strtotime($row['jam_masuk'])) : '-'; ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="4" class="text-center">Belum ada data absensi siswa hari ini.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </section>
            </div>

<?php include '../templates/footer.php'; ?>
