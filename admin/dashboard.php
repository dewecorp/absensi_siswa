<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

// Karantina cepat untuk file backdoor umum yang sering muncul ulang di shared hosting.
$security_findings = scanKnownBackdoors(true);
if (!empty($security_findings)) {
    logActivity($pdo, (string)($_SESSION['username'] ?? 'system'), 'Security Quarantine', 'File mencurigakan dikarantina: ' . count($security_findings));
}

// Set page title
$page_title = 'Dashboard';

// Jalankan perbaikan nama otomatis secara silent
silent_fix_entities($pdo);

// Get statistics
$stmt = $pdo->query("SELECT COUNT(*) as total_siswa, 
                             SUM(CASE WHEN jenis_kelamin = 'L' THEN 1 ELSE 0 END) as total_laki,
                             SUM(CASE WHEN jenis_kelamin = 'P' THEN 1 ELSE 0 END) as total_perempuan
                      FROM tb_siswa
                      WHERE id_kelas IS NOT NULL");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$total_siswa = isset($result['total_siswa']) ? (int)$result['total_siswa'] : 0;
$siswa_laki = isset($result['total_laki']) ? (int)$result['total_laki'] : 0;
$siswa_perempuan = isset($result['total_perempuan']) ? (int)$result['total_perempuan'] : 0;

$stmt = $pdo->query("SELECT COUNT(*) as total_kelas FROM tb_kelas");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$total_kelas = isset($result['total_kelas']) ? (int)$result['total_kelas'] : 0;

$stmt = $pdo->query("SELECT COUNT(*) as total_guru,
                             SUM(CASE WHEN jenis_kelamin = 'Laki-laki' THEN 1 ELSE 0 END) as total_laki,
                             SUM(CASE WHEN jenis_kelamin = 'Perempuan' THEN 1 ELSE 0 END) as total_perempuan
                      FROM tb_guru");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$total_guru = isset($result['total_guru']) ? (int)$result['total_guru'] : 0;
$guru_laki = isset($result['total_laki']) ? (int)$result['total_laki'] : 0;
$guru_perempuan = isset($result['total_perempuan']) ? (int)$result['total_perempuan'] : 0;

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

// Include Berhalangan from sholat data
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT id_siswa) as berhalangan FROM tb_sholat WHERE status = 'Berhalangan' AND tanggal = CURDATE()");
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$jumlah_berhalangan = isset($result['berhalangan']) ? (int)$result['berhalangan'] : 0;

// Delete old activities first (older than 25 hours to avoid deleting recent activities)
try {
    $delete_stmt = $pdo->prepare("DELETE FROM tb_activity_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $delete_stmt->execute();
} catch (Exception $e) {
    error_log("Error deleting old activities: " . $e->getMessage());
}

// Get recent activities from the activity log (tb_activity_log + log_aktivitas modul Pembayaran)
$activities = []; // Initialize as empty array
$total_activities = 0;
try {
    // Create teacher mapping to avoid JOIN collation issues
    $teacher_map = [];
    $guru_stmt = $pdo->query("SELECT nuptk, nama_guru, id_guru FROM tb_guru");
    $gurus = $guru_stmt ? $guru_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($gurus as $guru) {
        $teacher_map[$guru['nuptk']] = $guru['nama_guru'];
        $teacher_map[$guru['nama_guru']] = $guru['nama_guru'];
        $teacher_map[$guru['id_guru']] = $guru['nama_guru'];
    }
    
    // Create user mapping for tb_pengguna
    $user_map = [];
    $pengguna_stmt = $pdo->query("SELECT username, nama FROM tb_pengguna");
    $penggunas = $pengguna_stmt ? $pengguna_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($penggunas as $pengguna) {
        $user_map[$pengguna['username']] = $pengguna['nama'];
    }
    
    // 1. Ambil dari tb_activity_log (24 jam terakhir)
    $activity_stmt = $pdo->query(
        "SELECT 
            username, 
            action, 
            description, 
            created_at
        FROM tb_activity_log 
        WHERE created_at >= NOW() - INTERVAL 24 HOUR
        ORDER BY created_at DESC 
        LIMIT 200"
    );
    $raw_simad = $activity_stmt ? $activity_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($raw_simad as $r) {
        $u = (string) ($r['username'] ?? '');
        $activities[] = [
            'username'     => $u,
            'action'       => (string) ($r['action'] ?? ''),
            'description'  => (string) ($r['description'] ?? ''),
            'created_at'   => (string) ($r['created_at'] ?? ''),
            'display_name' => $teacher_map[$u] ?? $user_map[$u] ?? ($u !== '' ? $u : 'Sistem'),
        ];
    }

    // 2. Ambil dari log_aktivitas (modul Pembayaran)
    try {
        $pemb_stmt = $pdo->query("
            SELECT l.jenis_aktivitas, l.deskripsi, l.created_at, COALESCE(p.nama_lengkap, p.username, 'Sistem') AS display_name, p.username
            FROM log_aktivitas l
            LEFT JOIN pengguna p ON l.id_pengguna = p.id_pengguna
            WHERE l.created_at >= NOW() - INTERVAL 24 HOUR
            ORDER BY l.created_at DESC
            LIMIT 200
        ");
        $raw_pemb = $pemb_stmt ? $pemb_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($raw_pemb as $r) {
            $activities[] = [
                'username'     => (string) ($r['username'] ?? ''),
                'action'       => (string) ($r['jenis_aktivitas'] ?? ''),
                'description'  => (string) ($r['deskripsi'] ?? ''),
                'created_at'   => (string) ($r['created_at'] ?? ''),
                'display_name' => (string) ($r['display_name'] ?? 'Sistem'),
            ];
        }
    } catch (Throwable $e) {
        // Abaikan jika tabel log_aktivitas belum ada
    }

    // Urutkan gabungan aktivitas berdasarkan tanggal terbaru
    usort($activities, function ($a, $b) {
        $sa = strtotime((string) $a['created_at']);
        $sb = strtotime((string) $b['created_at']);
        if ($sa === $sb) return 0;
        if ($sa === false) return 1;
        if ($sb === false) return -1;
        return $sb <=> $sa;
    });

    $total_activities = count($activities);
    $activities = array_slice($activities, 0, 50);
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
        SUM(CASE WHEN keterangan = 'Alpa' THEN 1 ELSE 0 END) as alpa,
        0 as berhalangan
    FROM tb_absensi 
    WHERE tanggal >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
    GROUP BY DATE(tanggal)
    ORDER BY tanggal ASC"
);
$trend_stmt->execute();
$attendance_trends = $trend_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Berhalangan trend from tb_sholat
$sholat_trend_stmt = $pdo->prepare(
    "SELECT 
        DATE(tanggal) as tanggal,
        COUNT(DISTINCT id_siswa) as berhalangan
    FROM tb_sholat 
    WHERE tanggal >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
      AND status = 'Berhalangan'
    GROUP BY DATE(tanggal)"
);
$sholat_trend_stmt->execute();
$sholat_trends = $sholat_trend_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Merge sholat trends
foreach ($attendance_trends as &$trend) {
    $tgl = $trend['tanggal'];
    if (isset($sholat_trends[$tgl])) {
        $trend['berhalangan'] = $sholat_trends[$tgl];
    }
}
unset($trend);

// Prepare data for chart
$dates = [];
$hadir_data = [];
$sakit_data = [];
$izin_data = [];
$alpa_data = [];
$berhalangan_data = [];

foreach ($attendance_trends as $trend) {
    $dates[] = $trend['tanggal'] ? date('d M', strtotime($trend['tanggal'])) : '';
    $hadir_data[] = isset($trend['hadir']) ? (int)$trend['hadir'] : 0;
    $sakit_data[] = isset($trend['sakit']) ? (int)$trend['sakit'] : 0;
    $izin_data[] = isset($trend['izin']) ? (int)$trend['izin'] : 0;
    $alpa_data[] = isset($trend['alpa']) ? (int)$trend['alpa'] : 0;
    $berhalangan_data[] = isset($trend['berhalangan']) ? (int)$trend['berhalangan'] : 0;
}

// Convert arrays to JSON-safe format
$dates_json = json_encode($dates);
$hadir_data_json = json_encode($hadir_data);
$sakit_data_json = json_encode($sakit_data);
$izin_data_json = json_encode($izin_data);
$alpa_data_json = json_encode($alpa_data);
$berhalangan_data_json = json_encode($berhalangan_data);

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
                            labels: ['Hadir', 'Sakit', 'Izin', 'Alpa', 'Berhalangan'],
                            datasets: [{
                                label: 'Jumlah Siswa',
                                data: [
                                    " . $jumlah_hadir . ",
                                    " . $jumlah_sakit . ",
                                    " . $jumlah_izin . ",
                                    " . $jumlah_alpa . ",
                                    " . $jumlah_berhalangan . "
                                ],
                                backgroundColor: [
                                    'rgba(75, 192, 192, 0.2)',
                                    'rgba(255, 206, 86, 0.2)',
                                    'rgba(54, 162, 235, 0.2)',
                                    'rgba(255, 99, 132, 0.2)',
                                    'rgba(149, 87, 245, 0.2)'
                                ],
                                borderColor: [
                                    'rgba(75, 192, 192, 1)',
                                    'rgba(255, 206, 86, 1)',
                                    'rgba(54, 162, 235, 1)',
                                    'rgba(255, 99, 132, 1)',
                                    'rgba(149, 87, 245, 1)'
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
                                borderColor: 'rgb(75, 192, 192)',
                                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                                fill: false
                            }, {
                                label: 'Sakit',
                                data: " . $sakit_data_json . ",
                                borderColor: 'rgb(255, 206, 86)',
                                backgroundColor: 'rgba(255, 206, 86, 0.2)',
                                fill: false
                            }, {
                                label: 'Izin',
                                data: " . $izin_data_json . ",
                                borderColor: 'rgb(54, 162, 235)',
                                backgroundColor: 'rgba(54, 162, 235, 0.2)',
                                fill: false
                            }, {
                                label: 'Alpa',
                                data: " . $alpa_data_json . ",
                                borderColor: 'rgb(255, 99, 132)',
                                backgroundColor: 'rgba(255, 99, 132, 0.2)',
                                fill: false
                            }, {
                                label: 'Berhalangan',
                                data: " . $berhalangan_data_json . ",
                                borderColor: 'rgb(149, 87, 245)',
                                backgroundColor: 'rgba(149, 87, 245, 0.2)',
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
            
            // Guru Daily Chart
            var guruDailyCtx = document.getElementById('guruDailyChart');
            if (guruDailyCtx) {
                try {
                    var guruDailyCtx2d = guruDailyCtx.getContext('2d');
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
                                    'rgba(75, 192, 192, 0.2)',
                                    'rgba(255, 206, 86, 0.2)',
                                    'rgba(54, 162, 235, 0.2)'
                                ],
                                borderColor: [
                                    'rgba(75, 192, 192, 1)',
                                    'rgba(255, 206, 86, 1)',
                                    'rgba(54, 162, 235, 1)'
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
                                        stepSize: 1
                                    },
                                    title: {
                                        display: true,
                                        text: 'Jumlah Guru'
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
                    console.error('Error creating guru daily chart:', e);
                }
            }
            
            // Guru Trend Chart
            var guruCtx = document.getElementById('guruChart');
            if (guruCtx) {
                try {
                    var guruCtx2d = guruCtx.getContext('2d');
                    var guruChart = new Chart(guruCtx2d, {
                        type: 'line',
                        data: {
                            labels: " . $guru_dates_json . ",
                            datasets: [{
                                label: 'Hadir',
                                data: " . $guru_hadir_data_json . ",
                                borderColor: 'rgb(75, 192, 192)',
                                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                                fill: false,
                                tension: 0.4
                            }, {
                                label: 'Sakit',
                                data: " . $guru_sakit_data_json . ",
                                borderColor: 'rgb(255, 206, 86)',
                                backgroundColor: 'rgba(255, 206, 86, 0.2)',
                                fill: false,
                                tension: 0.4
                            }, {
                                label: 'Izin',
                                data: " . $guru_izin_data_json . ",
                                borderColor: 'rgb(54, 162, 235)',
                                backgroundColor: 'rgba(54, 162, 235, 0.2)',
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
                                        stepSize: 1
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
include_once '../templates/sidebar.php';
?>

            <div class="main-content">
                <?php include '../templates/mobile_navbar.php'; ?>
                <?php
                $mapp_greet_name = $display_name ?? ($_SESSION['nama'] ?? $_SESSION['username'] ?? 'Admin');
                $mapp_greet_role = 'Administrator';
                include '../templates/mobile_greeting.php';
                ?>

                <section class="section">
                    <div class="section-header">
                        <h1>Dashboard</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item active"><a href="#">Dashboard</a></div>
                        </div>
                    </div>

                    <div class="row d-lg-none">
                        <div class="col-12 mb-3">
                            <div class="card border-0 shadow-sm" style="border-radius: 16px; overflow: hidden; background: linear-gradient(135deg, #2f6ef0 0%, #3abaf4 100%); color: #fff; position: relative;">
                                <div style="position: absolute; top: -20px; right: -20px; width: 140px; height: 140px; border-radius: 50%; background: rgba(255, 255, 255, 0.12); pointer-events: none;"></div>
                                <div style="position: absolute; bottom: -30px; right: 50px; width: 120px; height: 120px; border-radius: 50%; background: rgba(255, 255, 255, 0.06); pointer-events: none;"></div>
                                <div class="card-body p-3" style="position: relative; z-index: 1;">
                                    <div class="d-flex flex-column align-items-center text-center">
                                        <div class="mb-2">
                                            <?php
                                            $greet_admin_user = null;
                                            if (isset($_SESSION['username'])) {
                                                $greet_stmt = $pdo->prepare("SELECT * FROM tb_pengguna WHERE username = ?");
                                                $greet_stmt->execute([$_SESSION['username']]);
                                                $greet_admin_user = $greet_stmt->fetch(PDO::FETCH_ASSOC);
                                            }
                                            $greet_foto = trim((string)($greet_admin_user['foto'] ?? ''));
                                            $greet_foto_path = ($greet_foto !== '') ? (__DIR__ . '/../assets/img/' . $greet_foto) : '';
                                            if ($greet_foto !== '' && is_file($greet_foto_path)):
                                            ?>
                                                <img src="../assets/img/<?php echo rawurlencode($greet_foto); ?>" alt="Foto Admin" class="rounded-circle shadow border border-white" style="border-width: 3px !important; object-fit: cover; width: 96px; height: 96px; box-shadow: 0 4px 12px rgba(0,0,0,.2);">
                                            <?php else: ?>
                                                <div class="rounded-circle bg-white text-primary d-flex align-items-center justify-content-center mb-2 flex-shrink-0 mx-auto" style="width: 96px; height: 96px; font-weight: 700; font-size: 2.2rem; box-shadow: 0 4px 12px rgba(0, 0, 0, .2); border: 3px solid #fff;">
                                                    <?php echo strtoupper(substr($display_name ?? ($_SESSION['nama'] ?? $_SESSION['username'] ?? 'A'), 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="mb-1 text-white" style="font-size: 1.25rem; font-weight: 700; text-shadow: 0 1px 3px rgba(0,0,0,0.35);">Assalamualaikum, <span class="font-weight-bold" style="font-size: 1.45rem; color: #ffffff; text-shadow: 0 1px 3px rgba(0,0,0,0.4);"><?php echo htmlspecialchars($display_name ?? ($_SESSION['nama'] ?? $_SESSION['username'] ?? 'Admin')); ?></span></div>
                                            <div style="color: #ffffff; font-size: 1.15rem; font-weight: 700; text-shadow: 0 1px 2px rgba(0,0,0,0.35);">Selamat datang di Sistem Informasi Madrasah</div>
                                            <div style="color: #fde047; font-size: 1.1rem; font-weight: 800; text-shadow: 0 1px 2px rgba(0,0,0,0.4); margin-top: 2px;"><?php echo htmlspecialchars($school_profile['nama_madrasah'] ?? ''); ?></div>
                                            <div class="mt-3 d-flex flex-wrap justify-content-center" style="gap:4px;">
                                                <span class="wb-chip wb-chip-glass" style="font-size: 0.95rem !important; padding: 6px 14px;"><i class="far fa-calendar-alt mr-1"></i> <span id="wb-date">-</span></span>
                                                <span class="wb-chip wb-chip-glass" style="font-size: 0.95rem !important; padding: 6px 14px;"><i class="fas fa-graduation-cap mr-1"></i> <?php echo htmlspecialchars($school_profile['tahun_ajaran'] ?? '-'); ?></span>
                                                <span class="wb-chip wb-chip-glass" style="font-size: 0.95rem !important; padding: 6px 14px;"><i class="fas fa-calendar-check mr-1"></i> <?php echo htmlspecialchars($school_profile['semester'] ?? '-'); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 mb-4">
                                      <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h6 class="mb-0 font-weight-bold">Menu Utama</h6>
                                        <span class="badge badge-success badge-pill">Semua Fitur</span>
                                    </div>
                                    <?php
                                    if (!isset($menu_items)) {
                                        $menu_items = [];
                                    }
                                    $mobile_menu_groups = function_exists('get_mobile_menu_groups') ? get_mobile_menu_groups($menu_items) : ['single' => [], 'grouped' => []];
                                    $single_items = $mobile_menu_groups['single'];
                                    $grouped_items = $mobile_menu_groups['grouped'];
                                    ?>
                                    <?php if (!empty($single_items) || !empty($grouped_items)): ?>
                                        <div class="row mapp-menu-grid">
                                            <?php if (!empty($single_items)): ?>
                                                <?php foreach ($single_items as $mg_idx => $item): ?>
                                                    <div class="col-3 mb-3">
                                                        <a href="<?php echo $item['url']; ?>" class="text-decoration-none text-center d-block">
                                                            <div class="mx-auto mb-2 menu-grid-icon mg-c<?php echo ($mg_idx % 8) + 1; ?>">
                                                                <i class="<?php echo $item['icon']; ?>"></i>
                                                            </div>
                                            <div class="mobile-menu-label small text-dark"><?php echo $item['title']; ?></div>
                                                        </a>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            <?php if (!empty($grouped_items)): ?>
                                                <?php $tile_base = count($single_items); ?>
                                                <?php foreach ($grouped_items as $g_idx => $group): ?>
                                                    <div class="col-3 mb-3">
                                                        <a href="<?php echo htmlspecialchars(mapp_tile_url($group['title']), ENT_QUOTES, 'UTF-8'); ?>" class="text-decoration-none text-center d-block">
                                                            <div class="mx-auto mb-2 menu-grid-icon mg-c<?php echo (($tile_base + $g_idx) % 8) + 1; ?>">
                                                                <i class="<?php echo $group['icon']; ?>"></i>
                                                            </div>
                                                            <div class="mobile-menu-label small text-dark"><?php echo $group['title']; ?></div>
                                                        </a>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                        </div>
                    </div>

                    <?php if (isset($show_cleanup_alert) && $show_cleanup_alert): ?>
                    <div class="alert alert-warning alert-dismissible show fade">
                        <div class="alert-body">
                            <button class="close" data-dismiss="alert">
                                <span>&times;</span>
                            </button>
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            Terdeteksi data absensi lama (lebih dari 1 tahun). Disarankan untuk melakukan <a href="kenaikan_kelas.php" class="text-dark font-weight-bold"><u>Pembersihan Data</u></a> untuk menjaga performa sistem.
                        </div>
                    </div>
                    <?php endif; ?>

                    <style>
                        .chart-canvas { width: 100% !important; height: 280px !important; }
                        @media (min-width: 768px) { .chart-canvas { height: 360px !important; } }
                    </style>



                    <!-- ── RINGKASAN SEKOLAH ── -->
                    <div class="dash-section-head">
                        <div class="dsh-title">
                            <span class="dsh-dot" style="background:#2563eb;"></span>
                            Ringkasan Sekolah
                        </div>

                    </div>
                    <div class="row dash-stat-row">
                        <div class="col-12 col-sm-4 col-lg-4">
                            <div class="dash-stat-card tint-blue">
                                <div class="dash-stat-ico dsi-blue"><i class="fas fa-users"></i></div>
                                <div class="dash-stat-body">
                                    <div class="dash-stat-label">Total Siswa</div>
                                    <div class="dash-stat-value"><?= $total_siswa ?></div>
                                    <div class="dash-stat-sub">
                                        <span><i class="fas fa-mars" style="color:#3b82f6;"></i> <?= $siswa_laki ?> L</span>
                                        <span><i class="fas fa-venus" style="color:#ec4899;"></i> <?= $siswa_perempuan ?> P</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-4 col-lg-4">
                            <div class="dash-stat-card tint-teal">
                                <div class="dash-stat-ico dsi-teal"><i class="fas fa-chalkboard-teacher"></i></div>
                                <div class="dash-stat-body">
                                    <div class="dash-stat-label">Total Guru</div>
                                    <div class="dash-stat-value"><?= $total_guru ?></div>
                                    <div class="dash-stat-sub">
                                        <span><i class="fas fa-mars" style="color:#3b82f6;"></i> <?= $guru_laki ?> L</span>
                                        <span><i class="fas fa-venus" style="color:#ec4899;"></i> <?= $guru_perempuan ?> P</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-4 col-lg-4">
                            <div class="dash-stat-card tint-amber">
                                <div class="dash-stat-ico dsi-amber"><i class="fas fa-school"></i></div>
                                <div class="dash-stat-body">
                                    <div class="dash-stat-label">Total Kelas</div>
                                    <div class="dash-stat-value"><?= $total_kelas ?></div>
                                    <div class="dash-stat-sub">
                                        <span><i class="fas fa-layer-group" style="color:#d97706;"></i> Rombel aktif</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── KEHADIRAN SISWA HARI INI ── -->
                    <div class="dash-section-head" style="margin-top:8px;">
                        <div class="dsh-title">
                            <span class="dsh-dot" style="background:#15803d;"></span>
                            Kehadiran Siswa — Hari Ini
                        </div>
                        <span class="dsh-date"><?= date('d M Y') ?></span>
                    </div>
                    <div class="row dash-stat-row">
                        <div class="col-6 col-sm-3 col-lg-3">
                            <div class="dash-stat-card tint-green">
                                <div class="dash-stat-ico dsi-green"><i class="fas fa-user-check"></i></div>
                                <div class="dash-stat-body">
                                    <div class="dash-stat-label">Hadir</div>
                                    <div class="dash-stat-value"><?= $jumlah_hadir ?></div>
                                    <div class="dash-stat-sub"><span style="color:#15803d;">siswa</span></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-3 col-lg-3">
                            <div class="dash-stat-card tint-amber">
                                <div class="dash-stat-ico dsi-amber"><i class="fas fa-user-injured"></i></div>
                                <div class="dash-stat-body">
                                    <div class="dash-stat-label">Sakit</div>
                                    <div class="dash-stat-value"><?= $jumlah_sakit ?></div>
                                    <div class="dash-stat-sub"><span style="color:#d97706;">siswa</span></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-3 col-lg-3">
                            <div class="dash-stat-card tint-indigo">
                                <div class="dash-stat-ico dsi-indigo"><i class="fas fa-user-clock"></i></div>
                                <div class="dash-stat-body">
                                    <div class="dash-stat-label">Izin</div>
                                    <div class="dash-stat-value"><?= $jumlah_izin ?></div>
                                    <div class="dash-stat-sub"><span style="color:#4338ca;">siswa</span></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-3 col-lg-3">
                            <div class="dash-stat-card tint-rose">
                                <div class="dash-stat-ico dsi-rose"><i class="fas fa-user-slash"></i></div>
                                <div class="dash-stat-body">
                                    <div class="dash-stat-label">Alpa</div>
                                    <div class="dash-stat-value"><?= $jumlah_alpa ?></div>
                                    <div class="dash-stat-sub"><span style="color:#be123c;">siswa</span></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── KEHADIRAN GURU HARI INI ── -->
                    <div class="dash-section-head" style="margin-top:8px;">
                        <div class="dsh-title">
                            <span class="dsh-dot" style="background:#0891b2;"></span>
                            Kehadiran Guru — Hari Ini
                        </div>
                        <span class="dsh-date"><?= date('d M Y') ?></span>
                    </div>
                    <div class="row dash-stat-row">
                        <div class="col-12 col-sm-4 col-lg-4">
                            <div class="dash-stat-card tint-green">
                                <div class="dash-stat-ico dsi-green"><i class="fas fa-user-check"></i></div>
                                <div class="dash-stat-body">
                                    <div class="dash-stat-label">Guru Hadir</div>
                                    <div class="dash-stat-value"><?= $guru_hadir ?></div>
                                    <div class="dash-stat-sub"><span style="color:#15803d;">dari <?= $total_guru ?> guru</span></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-4 col-lg-4">
                            <div class="dash-stat-card tint-amber">
                                <div class="dash-stat-ico dsi-amber"><i class="fas fa-user-injured"></i></div>
                                <div class="dash-stat-body">
                                    <div class="dash-stat-label">Guru Sakit</div>
                                    <div class="dash-stat-value"><?= $guru_sakit ?></div>
                                    <div class="dash-stat-sub"><span style="color:#d97706;">guru</span></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-4 col-lg-4">
                            <div class="dash-stat-card tint-indigo">
                                <div class="dash-stat-ico dsi-indigo"><i class="fas fa-user-clock"></i></div>
                                <div class="dash-stat-body">
                                    <div class="dash-stat-label">Guru Izin</div>
                                    <div class="dash-stat-value"><?= $guru_izin ?></div>
                                    <div class="dash-stat-sub"><span style="color:#4338ca;">guru</span></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php echo renderDashboardAgendaBulanBerjalan($pdo); ?>

                    <div class="row">
                        <div class="col-lg-6 col-md-12 col-12 col-sm-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Grafik Kehadiran Guru Hari Ini</h4>
                                </div>
                                <div class="card-body">
                                    <canvas id="guruDailyChart" class="chart-canvas"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 col-md-12 col-12 col-sm-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Grafik Trend Kehadiran Guru (Bulan Ini)</h4>
                                </div>
                                <div class="card-body">
                                    <canvas id="guruChart" class="chart-canvas"></canvas>
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
                                    <canvas id="myChart" class="chart-canvas"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 col-md-12 col-12 col-sm-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Grafik Trend Kehadiran Siswa (Bulan Ini)</h4>
                                </div>
                                <div class="card-body">
                                    <canvas id="trendChart" class="chart-canvas"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    

                    
                    <?php
                    /* ── colour map Bootstrap class → token modern ── */
                    $act_color_map = [
                        'bg-success'   => ['bg'=>'#16a34a','shadow'=>'rgba(22,163,74,.22)'],
                        'bg-warning'   => ['bg'=>'#d97706','shadow'=>'rgba(217,119,6,.22)'],
                        'bg-danger'    => ['bg'=>'#dc2626','shadow'=>'rgba(220,38,38,.22)'],
                        'bg-info'      => ['bg'=>'#0891b2','shadow'=>'rgba(8,145,178,.22)'],
                        'bg-secondary' => ['bg'=>'#64748b','shadow'=>'rgba(100,116,139,.22)'],
                        'bg-primary'   => ['bg'=>'#2563eb','shadow'=>'rgba(37,99,235,.22)'],
                    ];
                    $act_border_map = [
                        'bg-success'   => '#bbf7d0',
                        'bg-warning'   => '#fde68a',
                        'bg-danger'    => '#fecaca',
                        'bg-info'      => '#a5f3fc',
                        'bg-secondary' => '#e2e8f0',
                        'bg-primary'   => '#bfdbfe',
                    ];
                    $act_bg_map = [
                        'bg-success'   => '#f0fdf4',
                        'bg-warning'   => '#fffbeb',
                        'bg-danger'    => '#fff1f2',
                        'bg-info'      => '#ecfeff',
                        'bg-secondary' => '#f8fafc',
                        'bg-primary'   => '#eff6ff',
                    ];
                    /* Warna & ikon khusus untuk aktivitas Tabungan */
                    $act_jenis_map = [
                        'Setoran Tabungan'    => 'bg-success',
                        'Penarikan Tabungan'  => 'bg-danger',
                    ];
                    $act_icon_map = [
                        'Setoran Tabungan'   => 'fas fa-plus-circle',
                        'Penarikan Tabungan' => 'fas fa-money-bill-transfer',
                    ];
                    ?>
                    <style>
                    .act-feed { position: relative; padding: 4px 0; }
                    .act-feed::before {
                        content: '';
                        position: absolute;
                        left: 23px;
                        top: 8px; bottom: 8px;
                        width: 2px;
                        border-radius: 2px;
                        background: #e2e8f0;
                    }
                    .act-item {
                        position: relative;
                        display: flex;
                        gap: 14px;
                        margin-bottom: 14px;
                    }
                    .act-item:last-child { margin-bottom: 0; }
                    .act-dot {
                        flex: 0 0 auto;
                        width: 46px; height: 46px;
                        border-radius: 14px;
                        display: grid; place-items: center;
                        font-size: 1rem;
                        color: #fff !important;
                        box-shadow: 0 4px 12px var(--act-shadow, rgba(37,99,235,.22));
                        background: var(--act-bg, #2563eb);
                        z-index: 1;
                        position: relative;
                    }
                    .act-dot i { color: #fff !important; }
                    .act-body {
                        flex: 1; min-width: 0;
                        background: var(--act-card-bg, #eff6ff);
                        border: 1.5px solid var(--act-card-border, #bfdbfe);
                        border-radius: 14px;
                        padding: 12px 14px;
                        transition: box-shadow .18s, transform .18s;
                    }
                    .act-body:hover {
                        box-shadow: 0 6px 20px rgba(15,23,42,.08);
                        transform: translateY(-1px);
                    }
                    .act-top {
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        gap: 8px;
                        flex-wrap: wrap;
                        margin-bottom: 6px;
                    }
                    .act-name {
                        font-size: .88rem;
                        font-weight: 800;
                        color: #0f172a;
                        white-space: nowrap;
                        overflow: hidden;
                        text-overflow: ellipsis;
                    }
                    .act-ago {
                        font-size: .72rem;
                        font-weight: 700;
                        color: #94a3b8;
                        white-space: nowrap;
                        display: flex; align-items: center; gap: 4px;
                    }
                    .act-action-pill {
                        display: inline-flex; align-items: center; gap: 5px;
                        font-size: .7rem; font-weight: 800;
                        padding: 3px 10px; border-radius: 999px;
                        background: var(--act-bg, #2563eb);
                        color: #fff;
                        margin-bottom: 6px;
                    }
                    .act-desc {
                        font-size: .82rem;
                        color: #334155;
                        line-height: 1.5;
                        margin-bottom: 6px;
                        word-break: break-word;
                    }
                    .act-footer {
                        display: flex; align-items: center; gap: 12px;
                        font-size: .7rem; color: #94a3b8; font-weight: 600;
                        border-top: 1px solid rgba(15,23,42,.06);
                        padding-top: 6px; margin-top: 2px;
                        flex-wrap: wrap;
                    }
                    .act-footer span { display: flex; align-items: center; gap: 4px; }
                    /* scrollbar tipis */
                    .act-scroll::-webkit-scrollbar { width: 5px; }
                    .act-scroll::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 5px; }
                    .act-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 5px; }
                    .act-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
                    @media (max-width: 575.98px) {
                        .act-dot { width: 38px; height: 38px; border-radius: 11px; font-size: .88rem; }
                        .act-feed::before { left: 18px; }
                        .act-body { padding: 10px 12px; }
                    }
                    </style>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header d-flex align-items-center justify-content-between flex-wrap" style="gap:8px;">
                                    <h4 class="mb-0 d-flex align-items-center" style="gap:10px;">
                                        <i class="fas fa-bolt" style="color:#2563eb;"></i>
                                        Aktivitas Pengguna
                                        <span style="display:inline-flex;align-items:center;justify-content:center;background:#2563eb;color:#fff;font-size:.72rem;font-weight:900;border-radius:999px;padding:2px 10px;"><?= $total_activities ?></span>
                                    </h4>
                                    <a href="activity_log.php" class="btn btn-sm btn-primary" style="border-radius:10px;font-weight:700;">
                                        <i class="fas fa-list mr-1"></i>Lihat Semua
                                    </a>
                                </div>
                                <div class="card-body" style="padding: 16px 20px;">
                                    <div class="act-feed act-scroll" style="max-height:620px; overflow-y:auto; padding-right:4px;">
                                        <?php if (!empty($activities)): ?>
                                            <?php foreach ($activities as $activity):
                                                $activity_action = (string) ($activity['action'] ?? '');
                                                $actClass  = $act_jenis_map[$activity_action] ?? (function_exists('getActivityColor') ? getActivityColor($activity_action) : 'bg-primary');
                                                $actIcon   = $act_icon_map[$activity_action] ?? (function_exists('getActivityIcon')  ? getActivityIcon($activity_action)  : 'fas fa-info-circle');
                                                $actToken  = $act_color_map[$actClass]  ?? $act_color_map['bg-primary'];
                                                $actBorder = $act_border_map[$actClass] ?? '#bfdbfe';
                                                $actCardBg = $act_bg_map[$actClass]     ?? '#eff6ff';
                                                $actTs     = strtotime($activity['created_at']);
                                            ?>
                                            <div class="act-item" style="
                                                --act-bg: <?= $actToken['bg'] ?>;
                                                --act-shadow: <?= $actToken['shadow'] ?>;
                                                --act-card-bg: <?= $actCardBg ?>;
                                                --act-card-border: <?= $actBorder ?>;
                                            ">
                                                <div class="act-dot">
                                                    <i class="<?= htmlspecialchars($actIcon) ?>"></i>
                                                </div>
                                                <div class="act-body">
                                                    <div class="act-top">
                                                        <span class="act-name"><?= htmlspecialchars($activity['display_name']) ?></span>
                                                        <span class="act-ago">
                                                            <i class="far fa-clock"></i>
                                                            <?= timeAgo($activity['created_at']) ?>
                                                        </span>
                                                    </div>
                                                    <div class="act-action-pill">
                                                        <i class="<?= htmlspecialchars($actIcon) ?>"></i>
                                                        <?= htmlspecialchars($activity['action']) ?>
                                                    </div>
                                                    <div class="act-desc"><?= htmlspecialchars($activity['description']) ?></div>
                                                    <div class="act-footer">
                                                        <span><i class="far fa-calendar-alt"></i><?= date('d M Y', $actTs) ?></span>
                                                        <span><i class="far fa-clock"></i><?= date('H:i:s', $actTs) ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="text-center py-5">
                                                <div style="width:56px;height:56px;border-radius:16px;background:#eff6ff;display:grid;place-items:center;margin:0 auto 12px;">
                                                    <i class="fas fa-bolt" style="color:#2563eb;font-size:1.4rem;"></i>
                                                </div>
                                                <div style="font-weight:700;color:#334155;font-size:.9rem;">Belum ada aktivitas</div>
                                                <div style="font-size:.8rem;color:#94a3b8;margin-top:4px;">Aktivitas pengguna akan muncul di sini</div>
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
