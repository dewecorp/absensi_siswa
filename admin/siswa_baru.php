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

// Get current academic year from school profile. Untuk halaman ini harus murni
// mengikuti Pengaturan, bukan fallback dari data nilai/absensi.
$school_profile = getSchoolProfile($pdo);
$current_tahun_ajaran = '';
$current_tahun_ajaran_from_settings = false;
try {
    $stmtTa = $pdo->query("SELECT tahun_ajaran FROM tb_profil_madrasah ORDER BY id ASC LIMIT 1");
    $current_tahun_ajaran = trim((string)($stmtTa ? $stmtTa->fetchColumn() : ''));
    $current_tahun_ajaran_from_settings = $current_tahun_ajaran !== '';
} catch (Exception $e) {
    $current_tahun_ajaran = '';
}
if ($current_tahun_ajaran === '') {
    $tahun_berjalan = getTahunAjaranBerjalanStartYear();
    $current_tahun_ajaran = $tahun_berjalan . '/' . ($tahun_berjalan + 1);
}

$tahunAjaranStartYear = static function (?string $tahun_ajaran): ?int {
    $tahun_ajaran = trim((string)$tahun_ajaran);
    if (preg_match('/^(\d{4})\s*\/\s*\d{4}$/', $tahun_ajaran, $m)) {
        return (int)$m[1];
    }

    return null;
};

$has_refresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
ensureSiswaBaruSnapshotForActiveYear($pdo, $has_refresh);

$pesan_sukses = '';
if ($has_refresh) {
    $pesan_sukses = 'Snapshot siswa baru untuk tahun ajaran ' . htmlspecialchars($current_tahun_ajaran) . ' berhasil diperbarui.';
}

// Get all historical data for chart and table. Tampilkan hanya sampai tahun ajaran
// yang dipilih; default-nya tahun ajaran aktif di Pengaturan.
$stmt = $pdo->query("SELECT * FROM tb_siswa_baru ORDER BY tahun_ajaran DESC");
$raw_siswa_baru_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tahun_ajaran_options = [];
foreach ($raw_siswa_baru_data as $row) {
    $ta = trim((string)($row['tahun_ajaran'] ?? ''));
    if ($ta !== '') {
        $tahun_ajaran_options[] = $ta;
    }
}
if ($current_tahun_ajaran !== '') {
    $tahun_ajaran_options[] = $current_tahun_ajaran;
}
$tahun_ajaran_options = array_values(array_unique(array_filter($tahun_ajaran_options, static function ($ta) use ($tahunAjaranStartYear): bool {
    return $tahunAjaranStartYear((string)$ta) !== null;
})));
usort($tahun_ajaran_options, static function ($a, $b) use ($tahunAjaranStartYear): int {
    return ($tahunAjaranStartYear((string)$a) ?? 0) <=> ($tahunAjaranStartYear((string)$b) ?? 0);
});

$selected_tahun_akhir = trim((string)($_GET['sampai_tahun_ajaran'] ?? $current_tahun_ajaran));
if ($tahunAjaranStartYear($selected_tahun_akhir) === null) {
    $selected_tahun_akhir = $current_tahun_ajaran;
}
$selected_ta_start_year = $tahunAjaranStartYear($selected_tahun_akhir);

$siswa_baru_data = array_values(array_filter($raw_siswa_baru_data, static function (array $row) use ($tahunAjaranStartYear, $selected_ta_start_year): bool {
    if ($selected_ta_start_year === null) {
        return true;
    }

    $row_start_year = $tahunAjaranStartYear($row['tahun_ajaran'] ?? null);
    return $row_start_year !== null && $row_start_year <= $selected_ta_start_year;
}));

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
            <?php echo render_breadcrumb(); ?>
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
                            <?php $pesan_sukses_js = json_encode($pesan_sukses); ?>
                            <div class="alert alert-light border mb-3 py-2 d-flex justify-content-between align-items-center">
                                <span>Tahun ajaran aktif: <strong><?php echo htmlspecialchars($current_tahun_ajaran_from_settings ? $current_tahun_ajaran : 'Belum diatur'); ?></strong></span>
                                <button class="btn btn-sm btn-warning" id="btn-refresh-snapshot"><i class="fas fa-sync-alt"></i> Refresh Data</button>
                            </div>
                            <form method="GET" class="form-inline mb-3">
                                <label for="sampai_tahun_ajaran" class="mr-2 mb-2 mb-sm-0">Tampilkan sampai tahun ajaran</label>
                                <select name="sampai_tahun_ajaran" id="sampai_tahun_ajaran" class="form-control form-control-sm" onchange="this.form.submit()">
                                    <?php foreach ($tahun_ajaran_options as $tahun_ajaran_option): ?>
                                        <option value="<?php echo htmlspecialchars($tahun_ajaran_option); ?>" <?php echo $tahun_ajaran_option === $selected_tahun_akhir ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($tahun_ajaran_option); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
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
    f.action = 'export_siswa_baru_table_pdf.php';
    f.target = '_blank';
    var i1 = document.createElement('input');
    i1.type = 'hidden';
    i1.name = 'table_data';
    i1.value = html;
    f.appendChild(i1);
    document.body.appendChild(f);
    f.submit();
    document.body.removeChild(f);
}

// Export chart to PDF
function exportChartToPDF() {
    var canvas = document.getElementById('siswaBaruChart');
    var imgData = canvas.toDataURL('image/png', 1.0);
    var tableHtml = document.getElementById('exportTableContainer').innerHTML;
    var f = document.createElement('form');
    f.method = 'POST';
    f.action = 'export_siswa_baru_pdf.php';
    f.target = '_blank';
    var i1 = document.createElement('input');
    i1.type = 'hidden';
    i1.name = 'chart_image';
    i1.value = imgData;
    var i2 = document.createElement('input');
    i2.type = 'hidden';
    i2.name = 'table_data';
    i2.value = tableHtml;
    f.appendChild(i1);
    f.appendChild(i2);
    document.body.appendChild(f);
    f.submit();
    document.body.removeChild(f);
}

// Refresh snapshot with SweetAlert
var pesanSukses = " . json_encode($pesan_sukses) . ";
if (pesanSukses) {
    Swal.fire({ icon: 'success', title: 'Berhasil', text: pesanSukses, timer: 3000, showConfirmButton: false });
}
document.getElementById('btn-refresh-snapshot').addEventListener('click', function() {
    Swal.fire({
        title: 'Refresh Data?',
        text: 'Snapshot siswa baru untuk tahun ajaran " . htmlspecialchars($current_tahun_ajaran) . " akan diperbarui.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Ya, Refresh!',
        cancelButtonText: 'Batal'
    }).then(function(result) {
        if (result.isConfirmed) {
            window.location.href = '?refresh=1';
        }
    });
});
";

include '../templates/footer.php';
?>
