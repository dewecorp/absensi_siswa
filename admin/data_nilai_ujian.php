<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha', 'wali'])) {
    redirect('../login.php');
}

$page_title = 'Data Nilai Ujian';

// Get all academic years with ujian data
$stmt = $pdo->query("
    SELECT DISTINCT tahun_ajaran 
    FROM tb_nilai_semester 
    WHERE jenis_semester = 'Ujian'
    ORDER BY tahun_ajaran ASC
");
$tahun_ajaran_list = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get min and max nilai_jadi per tahun ajaran
$nilai_data = [];
foreach ($tahun_ajaran_list as $tahun) {
    $stmt = $pdo->prepare("
        SELECT 
            MIN(nilai_jadi) as nilai_terendah,
            MAX(nilai_jadi) as nilai_tertinggi
        FROM tb_nilai_semester 
        WHERE jenis_semester = 'Ujian' 
        AND tahun_ajaran = ?
        AND nilai_jadi > 0
    ");
    $stmt->execute([$tahun]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $nilai_data[] = [
        'tahun_ajaran' => $tahun,
        'nilai_terendah' => $result['nilai_terendah'] ?? 0,
        'nilai_tertinggi' => $result['nilai_tertinggi'] ?? 0
    ];
}

// Prepare data for Chart.js
$chart_labels = [];
$chart_terendah = [];
$chart_tertinggi = [];

foreach ($nilai_data as $data) {
    $chart_labels[] = $data['tahun_ajaran'];
    $chart_terendah[] = (float)$data['nilai_terendah'];
    $chart_tertinggi[] = (float)$data['nilai_tertinggi'];
}

// Define JS libraries
$js_libs = [
    'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js'
];

require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><?= $page_title ?></h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item"><a href="#">Nilai Siswa</a></div>
                <div class="breadcrumb-item">Data Nilai Ujian</div>
            </div>
        </div>

        <div class="section-body">
            <!-- Chart Section -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Grafik Nilai Ujian Per Tahun Ajaran</h4>
                            <div class="card-header-action">
                                <button type="button" class="btn btn-success btn-sm" onclick="exportChartToPDF()">
                                    <i class="fas fa-file-pdf"></i> Ekspor PDF
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div style="height: 400px; position: relative;">
                                <canvas id="nilaiUjianChart"></canvas>
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
                            <h4>Tabel Data Nilai Ujian</h4>
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
                                <table class="table table-striped table-hover" id="table-nilai-ujian">
                                    <thead>
                                        <tr>
                                            <th class="text-center" style="width: 60px;">No</th>
                                            <th>Tahun Ajaran</th>
                                            <th class="text-center">Nilai Terendah</th>
                                            <th class="text-center">Nilai Tertinggi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($nilai_data)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">Belum ada data nilai ujian</td>
                                        </tr>
                                        <?php else: ?>
                                            <?php $no = 1; foreach ($nilai_data as $data): ?>
                                            <tr>
                                                <td class="text-center"><?= $no++ ?></td>
                                                <td><?= htmlspecialchars($data['tahun_ajaran']) ?></td>
                                                <td class="text-center">
                                                    <span class="badge badge-danger"><?= $data['nilai_terendah'] > 0 ? number_format($data['nilai_terendah'], 1) : '-' ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-success"><?= $data['nilai_tertinggi'] > 0 ? number_format($data['nilai_tertinggi'], 1) : '-' ?></span>
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
                                            <th>Nilai Terendah</th>
                                            <th>Nilai Tertinggi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $no = 1; foreach ($nilai_data as $data): ?>
                                        <tr>
                                            <td><?= $no++ ?></td>
                                            <td><?= htmlspecialchars($data['tahun_ajaran']) ?></td>
                                            <td><?= $data['nilai_terendah'] > 0 ? number_format($data['nilai_terendah'], 1) : '-' ?></td>
                                            <td><?= $data['nilai_tertinggi'] > 0 ? number_format($data['nilai_tertinggi'], 1) : '-' ?></td>
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
$session_type_js = $_SESSION['level'] ?? 'admin';
if (!isset($js_page)) {
    $js_page = [];
}
$js_page[] = "
document.addEventListener('DOMContentLoaded', function() {
    var ctx = document.getElementById('nilaiUjianChart').getContext('2d');
    var nilaiUjianChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: " . json_encode($chart_labels) . ",
            datasets: [
                {
                    label: 'Nilai Terendah',
                    data: " . json_encode($chart_terendah) . ",
                    backgroundColor: 'rgba(255, 99, 132, 0.8)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Nilai Tertinggi',
                    data: " . json_encode($chart_tertinggi) . ",
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
                    text: 'Nilai Ujian Tertinggi dan Terendah per Tahun Ajaran'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        stepSize: 10
                    }
                }
            }
        }
    });
    
    // Store chart instance for export
    window.nilaiUjianChartInstance = nilaiUjianChart;
});

// Export table to Excel
function exportTableToExcel() {
    var c = document.getElementById('exportTableContainer');
    if (!c) return;
    var html = c.innerHTML;
    var f = document.createElement('form');
    f.method = 'POST';
    f.action = '../config/excel_export.php?session_type=' + '$session_type_js';
    f.target = '_blank';
    var i1 = document.createElement('input');
    i1.type = 'hidden';
    i1.name = 'table_data';
    i1.value = html;
    var i2 = document.createElement('input');
    i2.type = 'hidden';
    i2.name = 'export_type';
    i2.value = 'data_nilai_ujian';
    var i3 = document.createElement('input');
    i3.type = 'hidden';
    i3.name = 'report_title';
    i3.value = 'Data Nilai Ujian Per Tahun Ajaran';
    var i4 = document.createElement('input');
    i4.type = 'hidden';
    i4.name = 'filename';
    i4.value = 'data_nilai_ujian';
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
    f.action = '../config/pdf_export.php?session_type=' + '$session_type_js';
    f.target = '_blank';
    var i1 = document.createElement('input');
    i1.type = 'hidden';
    i1.name = 'table_data';
    i1.value = html;
    var i2 = document.createElement('input');
    i2.type = 'hidden';
    i2.name = 'export_type';
    i2.value = 'data_nilai_ujian';
    var i3 = document.createElement('input');
    i3.type = 'hidden';
    i3.name = 'report_title';
    i3.value = 'Data Nilai Ujian Per Tahun Ajaran';
    var i4 = document.createElement('input');
    i4.type = 'hidden';
    i4.name = 'filename';
    i4.value = 'data_nilai_ujian';
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
    var canvas = document.getElementById('nilaiUjianChart');
    var imgData = canvas.toDataURL('image/png', 1.0);
    var f = document.createElement('form');
    f.method = 'POST';
    f.action = '../config/pdf_export.php?session_type=' + '$session_type_js';
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
    i3.value = 'Grafik Nilai Ujian Per Tahun Ajaran';
    var i4 = document.createElement('input');
    i4.type = 'hidden';
    i4.name = 'filename';
    i4.value = 'grafik_nilai_ujian';
    f.appendChild(i1);
    f.appendChild(i2);
    f.appendChild(i3);
    f.appendChild(i4);
    document.body.appendChild(f);
    f.submit();
    document.body.removeChild(f);
}
";

require_once '../templates/footer.php';
?>
