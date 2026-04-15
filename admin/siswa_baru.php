<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has allowed level
if (!isAuthorized(['admin', 'tata_usaha', 'kepala_madrasah', 'kepala'])) {
    redirect('../login.php');
}

// Set page title
$page_title = 'Data Siswa Baru';
$export_session_type = $_SESSION['level'] ?? 'admin';

// Get current academic year from school profile
$school_profile = getSchoolProfile($pdo);
$current_tahun_ajaran = $school_profile['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1);

// Create table if not exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_siswa_baru (
        id INT PRIMARY KEY AUTO_INCREMENT,
        tahun_ajaran VARCHAR(20) NOT NULL UNIQUE,
        jumlah_laki INT DEFAULT 0,
        jumlah_perempuan INT DEFAULT 0,
        total INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    error_log("Error creating tb_siswa_baru table: " . $e->getMessage());
}

// Get Class 1 ID (Kelas I)
$stmt = $pdo->prepare("SELECT id_kelas FROM tb_kelas WHERE nama_kelas = 'I' OR nama_kelas LIKE '%I%' LIMIT 1");
$stmt->execute();
$kelas_1 = $stmt->fetch(PDO::FETCH_ASSOC);

// Get current Class 1 student count by gender
$current_data = [
    'jumlah_laki' => 0,
    'jumlah_perempuan' => 0,
    'total' => 0
];

if ($kelas_1) {
    $stmt = $pdo->prepare("SELECT 
        COUNT(CASE WHEN jenis_kelamin = 'L' THEN 1 END) as jumlah_laki,
        COUNT(CASE WHEN jenis_kelamin = 'P' THEN 1 END) as jumlah_perempuan,
        COUNT(*) as total
        FROM tb_siswa WHERE id_kelas = ?");
    $stmt->execute([$kelas_1['id_kelas']]);
    $current_data = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Check if current academic year data exists in tb_siswa_baru
$stmt = $pdo->prepare("SELECT * FROM tb_siswa_baru WHERE tahun_ajaran = ?");
$stmt->execute([$current_tahun_ajaran]);
$existing_data = $stmt->fetch(PDO::FETCH_ASSOC);

// Only insert if not exists - DON'T update existing historical data
// This ensures previous years' data is preserved
if (!$existing_data) {
    // Insert new record for new academic year only
    $stmt = $pdo->prepare("INSERT INTO tb_siswa_baru (tahun_ajaran, jumlah_laki, jumlah_perempuan, total) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        $current_tahun_ajaran,
        $current_data['jumlah_laki'] ?? 0,
        $current_data['jumlah_perempuan'] ?? 0,
        $current_data['total'] ?? 0
    ]);
}

// Get all historical data for chart and table
$stmt = $pdo->query("SELECT * FROM tb_siswa_baru ORDER BY tahun_ajaran ASC");
$siswa_baru_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare data for Chart.js
$chart_labels = [];
$chart_laki = [];
$chart_perempuan = [];
$chart_total = [];

foreach ($siswa_baru_data as $data) {
    $chart_labels[] = $data['tahun_ajaran'];
    $chart_laki[] = (int)$data['jumlah_laki'];
    $chart_perempuan[] = (int)$data['jumlah_perempuan'];
    $chart_total[] = (int)$data['total'];
}

// Define CSS libraries
$css_libs = [];

// Define JS libraries
$js_libs = [
    'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js'
];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Data Siswa Baru</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item"><a href="#">Master Data</a></div>
                <div class="breadcrumb-item">Data Siswa Baru</div>
            </div>
        </div>

        <div class="section-body">
            <!-- Chart Section -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Grafik Siswa Baru Per Tahun Ajaran</h4>
                            <div class="card-header-action">
                                <button type="button" class="btn btn-success btn-sm" onclick="exportChartToPDF()">
                                    <i class="fas fa-file-pdf"></i> Ekspor PDF
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div style="height: 400px; position: relative;">
                                <canvas id="siswaBaruChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Table Section -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Tabel Data Siswa Baru</h4>
                            <div class="card-header-action">
                                <button type="button" class="btn btn-success btn-sm mr-2" onclick="exportTableToExcel()">
                                    <i class="fas fa-file-excel"></i> Ekspor Excel
                                </button>
                                <button type="button" class="btn btn-danger btn-sm" onclick="exportTableToPDF()">
                                    <i class="fas fa-file-pdf"></i> Ekspor PDF
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover" id="table-siswa-baru">
                                    <thead>
                                        <tr>
                                            <th class="text-center" style="width: 60px;">No</th>
                                            <th>Tahun Ajaran</th>
                                            <th class="text-center">Jumlah Laki-laki</th>
                                            <th class="text-center">Jumlah Perempuan</th>
                                            <th class="text-center">Total Siswa</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($siswa_baru_data)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-4">Belum ada data siswa baru</td>
                                        </tr>
                                        <?php else: ?>
                                            <?php $no = 1; foreach ($siswa_baru_data as $data): ?>
                                            <tr>
                                                <td class="text-center"><?php echo $no++; ?></td>
                                                <td><?php echo htmlspecialchars($data['tahun_ajaran']); ?></td>
                                                <td class="text-center">
                                                    <span class="badge badge-primary"><?php echo $data['jumlah_laki']; ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-danger"><?php echo $data['jumlah_perempuan']; ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <strong><?php echo $data['total']; ?></strong>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <!-- Hidden table for export -->
                            <div id="exportTableContainer" style="display:none;">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>No</th>
                                            <th>Tahun Ajaran</th>
                                            <th>Jumlah Laki-laki</th>
                                            <th>Jumlah Perempuan</th>
                                            <th>Total Siswa</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $no = 1; foreach ($siswa_baru_data as $data): ?>
                                        <tr>
                                            <td><?php echo $no++; ?></td>
                                            <td><?php echo htmlspecialchars($data['tahun_ajaran']); ?></td>
                                            <td><?php echo $data['jumlah_laki']; ?></td>
                                            <td><?php echo $data['jumlah_perempuan']; ?></td>
                                            <td><?php echo $data['total']; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>
</div>

<?php
// Add JavaScript for Chart.js and Export functions
if (!isset($js_page)) {
    $js_page = [];
}
$js_page[] = "
document.addEventListener('DOMContentLoaded', function() {
    var ctx = document.getElementById('siswaBaruChart').getContext('2d');
    var siswaBaruChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: " . json_encode($chart_labels) . ",
            datasets: [
                {
                    label: 'Laki-laki',
                    data: " . json_encode($chart_laki) . ",
                    backgroundColor: 'rgba(54, 162, 235, 0.8)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Perempuan',
                    data: " . json_encode($chart_perempuan) . ",
                    backgroundColor: 'rgba(255, 99, 132, 0.8)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Total',
                    data: " . json_encode($chart_total) . ",
                    backgroundColor: 'rgba(75, 192, 192, 0.8)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                }
            ]
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
                    text: 'Jumlah Siswa Baru per Tahun Ajaran'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                }
            }
        }
    });
    
    // Store chart instance for export
    window.siswaBaruChartInstance = siswaBaruChart;
});

// Export table to Excel
function exportTableToExcel() {
    var c = document.getElementById('exportTableContainer');
    if (!c) return;
    var html = c.innerHTML;
    var f = document.createElement('form');
    f.method = 'POST';
    f.action = '../config/excel_export.php?session_type=" . urlencode($export_session_type) . "';
    f.target = '_blank';
    var i1 = document.createElement('input');
    i1.type = 'hidden';
    i1.name = 'table_data';
    i1.value = html;
    var i2 = document.createElement('input');
    i2.type = 'hidden';
    i2.name = 'export_type';
    i2.value = 'siswa_baru';
    var i3 = document.createElement('input');
    i3.type = 'hidden';
    i3.name = 'report_title';
    i3.value = 'Data Siswa Baru Per Tahun Ajaran';
    var i4 = document.createElement('input');
    i4.type = 'hidden';
    i4.name = 'filename';
    i4.value = 'data_siswa_baru';
    f.appendChild(i1);
    f.appendChild(i2);
    f.appendChild(i3);
    f.appendChild(i4);
    document.body.appendChild(f);
    f.submit();
    document.body.removeChild(f);
}

// Export table to PDF
function exportTableToPDF() {
    var c = document.getElementById('exportTableContainer');
    if (!c) return;
    var html = c.innerHTML;
    var f = document.createElement('form');
    f.method = 'POST';
    f.action = '../config/pdf_export.php?session_type=" . urlencode($export_session_type) . "';
    f.target = '_blank';
    var i1 = document.createElement('input');
    i1.type = 'hidden';
    i1.name = 'table_data';
    i1.value = html;
    var i2 = document.createElement('input');
    i2.type = 'hidden';
    i2.name = 'export_type';
    i2.value = 'siswa_baru';
    var i3 = document.createElement('input');
    i3.type = 'hidden';
    i3.name = 'report_title';
    i3.value = 'Data Siswa Baru Per Tahun Ajaran';
    var i4 = document.createElement('input');
    i4.type = 'hidden';
    i4.name = 'filename';
    i4.value = 'data_siswa_baru';
    f.appendChild(i1);
    f.appendChild(i2);
    f.appendChild(i3);
    f.appendChild(i4);
    document.body.appendChild(f);
    f.submit();
    document.body.removeChild(f);
}

// Export chart to PDF
function exportChartToPDF() {
    var canvas = document.getElementById('siswaBaruChart');
    var imgData = canvas.toDataURL('image/png', 1.0);
    var f = document.createElement('form');
    f.method = 'POST';
    f.action = '../config/pdf_export.php?session_type=" . urlencode($export_session_type) . "';
    f.target = '_blank';
    var i1 = document.createElement('input');
    i1.type = 'hidden';
    i1.name = 'chart_image';
    i1.value = imgData;
    var i2 = document.createElement('input');
    i2.type = 'hidden';
    i2.name = 'export_type';
    i2.value = 'chart';
    var i3 = document.createElement('input');
    i3.type = 'hidden';
    i3.name = 'report_title';
    i3.value = 'Grafik Siswa Baru Per Tahun Ajaran';
    var i4 = document.createElement('input');
    i4.type = 'hidden';
    i4.name = 'filename';
    i4.value = 'grafik_siswa_baru';
    f.appendChild(i1);
    f.appendChild(i2);
    f.appendChild(i3);
    f.appendChild(i4);
    document.body.appendChild(f);
    f.submit();
    document.body.removeChild(f);
}
";

include '../templates/footer.php';
?>
