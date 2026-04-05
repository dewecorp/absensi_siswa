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

// Ensure tb_alumni table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `tb_alumni` (
        `id_alumni` int NOT NULL AUTO_INCREMENT,
        `nama_siswa` varchar(100) NOT NULL,
        `nisn` varchar(20) NOT NULL,
        `jenis_kelamin` enum('L','P') DEFAULT NULL,
        `tahun_lulus` varchar(20) NOT NULL,
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id_alumni`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {
    // If table creation fails, it will likely throw again below, but we log it
    error_log("Error creating tb_alumni: " . $e->getMessage());
}

// Get available graduation years for filter
$stmt_years = $pdo->query("SELECT DISTINCT tahun_lulus FROM tb_alumni ORDER BY tahun_lulus DESC");
$years = $stmt_years->fetchAll(PDO::FETCH_COLUMN);

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
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item"><a href="#">Master Data</a></div>
                <div class="breadcrumb-item">Data Alumni</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Data Alumni</h4>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="mb-4">
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
                                                <td><?= htmlspecialchars($a['tahun_lulus']) ?></td>
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

<?php include '../templates/footer.php'; ?>