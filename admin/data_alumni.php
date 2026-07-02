<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Ensure session is started for activity logging
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

// Define CSS libraries for this page
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];

// Define JS libraries for this page
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js',
];

// Set page title
$page_title = 'Data Alumni';

// Define page-specific JS
$js_page = ["
$(document).ready(function() {
    if ($.fn.DataTable.isDataTable('#table-1')) {
        $('#table-1').DataTable().destroy();
    }
    $('#table-1').DataTable({
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
});
"];

// Ensure tb_alumni table exists and has all required columns
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `tb_alumni` (
        `id_alumni` int NOT NULL AUTO_INCREMENT,
        `original_id_siswa` int DEFAULT NULL,
        `nama_siswa` varchar(100) NOT NULL,
        `nisn` varchar(20) NOT NULL,
        `jenis_kelamin` enum('L','P') DEFAULT NULL,
        `tempat_lahir` varchar(100) DEFAULT NULL,
        `tanggal_lahir` date DEFAULT NULL,
        `wali` varchar(100) DEFAULT NULL,
        `tahun_lulus` varchar(20) NOT NULL,
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id_alumni`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Check for missing columns and add them if they don't exist
    $columns_to_check = [
        'original_id_siswa' => "INT DEFAULT NULL AFTER id_alumni",
        'tempat_lahir' => "VARCHAR(100) DEFAULT NULL AFTER jenis_kelamin",
        'tanggal_lahir' => "DATE DEFAULT NULL AFTER tempat_lahir",
        'wali' => "VARCHAR(100) DEFAULT NULL AFTER tanggal_lahir"
    ];

    foreach ($columns_to_check as $col => $definition) {
        $check = $pdo->query("SHOW COLUMNS FROM `tb_alumni` LIKE '$col'");
        if ($check->rowCount() == 0) {
            $pdo->exec("ALTER TABLE `tb_alumni` ADD COLUMN `$col` $definition");
        }
    }
} catch (PDOException $e) {
    error_log("Error updating tb_alumni: " . $e->getMessage());
}

// Get available graduation years for filter
$stmt_years = $pdo->query("SELECT DISTINCT tahun_lulus FROM tb_alumni ORDER BY tahun_lulus DESC");
$years = $stmt_years->fetchAll(PDO::FETCH_COLUMN);

// Data grafik alumni per tahun ajaran/tahun lulus.
$stmt_chart = $pdo->query("SELECT tahun_lulus,
    COUNT(CASE WHEN jenis_kelamin = 'L' THEN 1 END) AS jumlah_laki,
    COUNT(CASE WHEN jenis_kelamin = 'P' THEN 1 END) AS jumlah_perempuan,
    COUNT(*) AS total
    FROM tb_alumni
    GROUP BY tahun_lulus
    ORDER BY tahun_lulus ASC");
$alumni_chart_data = $stmt_chart->fetchAll(PDO::FETCH_ASSOC);
$chart_labels = [];
$chart_laki = [];
$chart_perempuan = [];
$chart_total = [];
foreach ($alumni_chart_data as $row) {
    $chart_labels[] = (string)$row['tahun_lulus'];
    $chart_laki[] = (int)$row['jumlah_laki'];
    $chart_perempuan[] = (int)$row['jumlah_perempuan'];
    $chart_total[] = (int)$row['total'];
}

// Handle filter
$selected_year = $_GET['tahun_lulus'] ?? ($years[0] ?? '');

// Fetch alumni data
if ($selected_year) {
    $stmt = $pdo->prepare("SELECT * FROM tb_alumni WHERE tahun_lulus = ? ORDER BY nama_siswa ASC");
    $stmt->execute([$selected_year]);
    $alumni = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $alumni = [];
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Data Alumni</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Grafik Alumni Per Tahun Ajaran</h4>
                            <div class="card-header-action">
                                <button type="button" class="btn btn-danger btn-sm" onclick="exportGrafikAlumniToPDF()" <?= empty($alumni_chart_data) ? 'disabled' : '' ?>>
                                    <i class="fas fa-file-pdf"></i> Cetak PDF
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($alumni_chart_data)): ?>
                                <div class="alert alert-light border mb-0 text-center text-muted">Belum ada data alumni untuk ditampilkan pada grafik.</div>
                            <?php else: ?>
                                <div style="height: 380px; position: relative;">
                                    <canvas id="alumniChart"></canvas>
                                </div>
                                <div class="table-responsive mt-4">
                                    <table class="table table-sm table-bordered table-striped mb-0">
                                        <thead>
                                            <tr>
                                                <th class="text-center" style="width: 60px;">No</th>
                                                <th>Tahun Ajaran</th>
                                                <th class="text-center">Laki-laki</th>
                                                <th class="text-center">Perempuan</th>
                                                <th class="text-center">Total Alumni</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $no_chart = 1; foreach ($alumni_chart_data as $row): ?>
                                                <tr>
                                                    <td class="text-center"><?= $no_chart++ ?></td>
                                                    <td><?= htmlspecialchars((string)$row['tahun_lulus']) ?></td>
                                                    <td class="text-center"><span class="badge badge-primary"><?= (int)$row['jumlah_laki'] ?></span></td>
                                                    <td class="text-center"><span class="badge badge-danger"><?= (int)$row['jumlah_perempuan'] ?></span></td>
                                                    <td class="text-center"><strong><?= (int)$row['total'] ?></strong></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <form id="exportGrafikAlumniForm" method="POST" action="../config/export_grafik_alumni_pdf.php" target="_blank" style="display:none;">
                                    <input type="hidden" name="chart_image" id="alumni_chart_image">
                                    <input type="hidden" name="chart_data" id="alumni_chart_data">
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Data Alumni</h4>
                            <?php if ($selected_year): ?>
                            <div class="card-header-action">
                                <div class="btn-group btn-pill overflow-hidden" style="border-radius: 30px;">
                                    <button type="button" class="btn btn-danger px-3" onclick="exportAlumniToPDF()" style="background-color: #ff5e5e; border: none; border-top-left-radius: 30px; border-bottom-left-radius: 30px;">
                                        <i class="fas fa-file-pdf"></i> PDF
                                    </button>
                                    <button type="button" class="btn btn-success px-3" onclick="exportAlumniToExcel()" style="background-color: #47c363; border: none; border-top-right-radius: 30px; border-bottom-right-radius: 30px;">
                                        <i class="fas fa-file-excel"></i> Excel
                                    </button>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="mb-4" id="filterForm">
                                <div class="form-row align-items-end">
                                    <div class="col-md-4">
                                        <label>Filter Tahun Lulus</label>
                                        <select name="tahun_lulus" class="form-control" onchange="this.form.submit()">
                                            <option value="">-- Pilih Tahun Lulus --</option>
                                            <?php foreach ($years as $yr): ?>
                                                <option value="<?= htmlspecialchars($yr) ?>" <?= $selected_year == $yr ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($yr) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php if ($selected_year): ?>
                                    <div class="col-md-4">
                                        <div class="alert alert-info mb-0 py-2">
                                            <i class="fas fa-users"></i> Jumlah Alumni: <strong><?= count($alumni) ?></strong> Siswa
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </form>

                            <div class="table-responsive">
                                <table class="table table-striped" id="table-1">
                                    <thead>
                                        <tr>
                                            <th>No</th>
                                            <th>Nama Siswa</th>
                                            <th>NISN</th>
                                            <th>Jenis Kelamin</th>
                                            <th>Tempat, Tgl Lahir</th>
                                            <th>Orang Tua/Wali</th>
                                            <th>Tahun Lulus</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $no = 1; foreach ($alumni as $a): ?>
                                            <tr>
                                                <td><?= $no++ ?></td>
                                                <td><?= htmlspecialchars($a['nama_siswa']) ?></td>
                                                <td><?= htmlspecialchars($a['nisn']) ?></td>
                                                <td><?= $a['jenis_kelamin'] == 'L' ? 'Laki-laki' : 'Perempuan' ?></td>
                                                <td><?= htmlspecialchars($a['tempat_lahir'] ?? '-') . ', ' . ($a['tanggal_lahir'] ? date('d-m-Y', strtotime($a['tanggal_lahir'])) : '-') ?></td>
                                                <td><?= htmlspecialchars($a['wali'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($a['tahun_lulus']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Hidden Table for Export -->
                            <?php if ($selected_year): ?>
                            <div id="exportTableContainer" style="display:none;">
                                <table border="1">
                                    <thead>
                                        <tr>
                                            <th colspan="7" style="text-align: center; font-size: 16px; font-weight: bold;">DATA ALUMNI TAHUN LULUS <?= $selected_year ?></th>
                                        </tr>
                                        <tr>
                                            <th>No</th>
                                            <th>Nama Siswa</th>
                                            <th>NISN</th>
                                            <th>Jenis Kelamin</th>
                                            <th>Tempat Lahir</th>
                                            <th>Tanggal Lahir</th>
                                            <th>Orang Tua/Wali</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $no = 1; foreach ($alumni as $a): ?>
                                            <tr>
                                                <td><?= $no++ ?></td>
                                                <td><?= htmlspecialchars($a['nama_siswa']) ?></td>
                                                <td><?= htmlspecialchars($a['nisn']) ?></td>
                                                <td><?= $a['jenis_kelamin'] == 'L' ? 'Laki-laki' : 'Perempuan' ?></td>
                                                <td><?= htmlspecialchars($a['tempat_lahir'] ?? '-') ?></td>
                                                <td><?= $a['tanggal_lahir'] ? date('d-m-Y', strtotime($a['tanggal_lahir'])) : '-' ?></td>
                                                <td><?= htmlspecialchars($a['wali'] ?? '-') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Form for Export -->
                            <form id="exportForm" method="POST" action="" target="_blank">
                                <input type="hidden" name="table_data" id="table_data">
                                <input type="hidden" name="report_title" id="report_title" value="DATA ALUMNI">
                                <input type="hidden" name="filename" id="filename" value="data_alumni_<?= $selected_year ?>">
                                <input type="hidden" name="tahun_lulus_export" value="<?= $selected_year ?>">
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php
// Add JavaScript for this page
$js_page[] = "
var alumniChartInstance = null;

document.addEventListener('DOMContentLoaded', function() {
    var chartCanvas = document.getElementById('alumniChart');
    if (!chartCanvas || typeof Chart === 'undefined') {
        return;
    }

    alumniChartInstance = new Chart(chartCanvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: " . json_encode($chart_labels) . ",
            datasets: [
                {
                    label: 'Laki-laki',
                    data: " . json_encode($chart_laki) . ",
                    backgroundColor: 'rgba(54, 162, 235, 0.72)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Perempuan',
                    data: " . json_encode($chart_perempuan) . ",
                    backgroundColor: 'rgba(255, 99, 132, 0.72)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Total',
                    data: " . json_encode($chart_total) . ",
                    type: 'line',
                    backgroundColor: 'rgba(71, 195, 99, 0.12)',
                    borderColor: 'rgba(71, 195, 99, 1)',
                    borderWidth: 3,
                    pointRadius: 4,
                    tension: 0.25,
                    fill: false
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' },
                title: {
                    display: true,
                    text: 'Jumlah Alumni per Tahun Ajaran'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 }
                }
            }
        }
    });
});

function exportGrafikAlumniToPDF() {
    var canvas = document.getElementById('alumniChart');
    var form = document.getElementById('exportGrafikAlumniForm');
    if (!canvas || !form) return;

    document.getElementById('alumni_chart_image').value = canvas.toDataURL('image/png', 1.0);
    document.getElementById('alumni_chart_data').value = JSON.stringify(" . json_encode($alumni_chart_data) . ");
    form.submit();
}

function exportAlumniToPDF() {
    const tableData = document.getElementById('exportTableContainer').innerHTML;
    document.getElementById('table_data').value = tableData;
    document.getElementById('exportForm').action = '../config/pdf_export.php?session_type=admin';
    document.getElementById('exportForm').submit();
}

function exportAlumniToExcel() {
    const tableData = document.getElementById('exportTableContainer').innerHTML;
    document.getElementById('table_data').value = tableData;
    document.getElementById('exportForm').action = '../config/excel_export.php?session_type=admin';
    document.getElementById('exportForm').submit();
}
";
?>

<?php include '../templates/footer.php'; ?>
