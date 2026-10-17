<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check authorization
if (!isAuthorized(['siswa', 'admin', 'guru', 'wali', 'kepala_madrasah', 'tata_usaha'])) {
    redirect('../login.php');
}

$is_student = ($_SESSION['level'] ?? '') === 'siswa';
$page_title = 'Biaya Ujian';

// Get school profile
$school_profile = getSchoolProfile($pdo);
$school_name = strtoupper($school_profile['nama_madrasah'] ?? 'Sistem Informasi Madrasah');

// --- DATABASE MIGRATION START ---
try {
    // Tabel Pengeluaran Ujian
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_pengeluaran_ujian (
        id_pengeluaran INT PRIMARY KEY AUTO_INCREMENT,
        uraian VARCHAR(255) NOT NULL,
        volume INT NOT NULL DEFAULT 0,
        satuan DECIMAL(15,2) NOT NULL DEFAULT 0,
        jumlah INT NOT NULL DEFAULT 1,
        perkalian INT NOT NULL DEFAULT 1,
        kategori VARCHAR(255) NULL,
        total DECIMAL(15,2) GENERATED ALWAYS AS (volume * satuan * jumlah * perkalian) STORED,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    error_log("Error creating tables: " . $e->getMessage());
}
// --- DATABASE MIGRATION END ---

// Get Visibility Setting
$stmt_setting = $pdo->prepare("SELECT nilai FROM tb_pengaturan_aplikasi WHERE kunci = 'biaya_ujian_visibility'");
$stmt_setting->execute();
$visibility_setting = $stmt_setting->fetchColumn();
if ($visibility_setting === false) $visibility_setting = 'closed';

// Fetch Data
$rencana_pengeluaran = $pdo->query("SELECT * FROM tb_pengeluaran_ujian ORDER BY kategori ASC, id_pengeluaran ASC")->fetchAll(PDO::FETCH_ASSOC);

// If visibility is closed AND user is a student, mask the amounts
// But if user is Admin/Guru/Wali, show full data
if ($visibility_setting == 'closed' && $is_student) {
    foreach ($rencana_pengeluaran as &$row) {
        $row['volume'] = 0;
        $row['satuan'] = 0;
        $row['jumlah'] = 0;
        $row['perkalian'] = 0;
        $row['total'] = 0;
    }
}

// Get Student Count (Kelas 6)
// Using tb_siswa and tb_kelas relation.
try {
    $stmt_siswa = $pdo->query("
        SELECT COUNT(*) 
        FROM tb_siswa s 
        JOIN tb_kelas k ON s.id_kelas = k.id_kelas 
        WHERE k.nama_kelas LIKE '6%' OR k.nama_kelas LIKE 'VI%'
    ");
    $jumlah_siswa = $stmt_siswa->fetchColumn();
} catch (PDOException $e) {
    // Fallback or log error
    error_log("Error counting students: " . $e->getMessage());
    $jumlah_siswa = 0; 
}

// Calculate Totals
$total_pengeluaran = 0;
$biaya_per_siswa = 0;

// Show totals only if open OR if not a student
if ($visibility_setting == 'open' || !$is_student) {
    $total_pengeluaran = array_sum(array_column($rencana_pengeluaran, 'total'));
    $biaya_per_siswa = $jumlah_siswa > 0 ? $total_pengeluaran / $jumlah_siswa : 0;
}

// Define CSS libraries
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'https://cdn.datatables.net/select/1.3.3/css/select.bootstrap4.min.css',
    'https://cdn.datatables.net/rowgroup/1.1.2/css/rowGroup.bootstrap4.min.css'
];

// Define JS libraries
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.datatables.net/select/1.3.3/js/dataTables.select.min.js',
    'https://cdn.datatables.net/rowgroup/1.1.2/js/dataTables.rowGroup.min.js'
];

// Include header
include '../templates/header.php';
include '../templates/sidebar.php';
?>

<link rel="stylesheet" href="../assets/css/siswa-keuangan-mobile.css">

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Biaya Ujian</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="#">Dashboard</a></div>
                <div class="breadcrumb-item">Biaya Ujian</div>
            </div>
        </div>

        <div class="section-body">
            <!-- Hero Card Biaya Ujian Siswa Ala Mobile Apps -->
            <div class="sfin-hero-card theme-amber">
                <div class="sfin-hero-top">
                    <div class="sfin-student-info">
                        <div class="sfin-student-name">
                            <i class="fas fa-file-invoice mr-1"></i> Rencana Biaya Ujian
                        </div>
                        <div class="sfin-student-sub">
                            <span>Kelas VI</span>
                            <span>•</span>
                            <span>TA <?= htmlspecialchars($school_profile['tahun_ajaran'] ?? '-') ?></span>
                        </div>
                    </div>
                    <div>
                        <span class="sfin-badge-pill">
                            <?= $jumlah_siswa ?> Siswa Kelas 6
                        </span>
                    </div>
                </div>

                <div class="mt-2">
                    <div class="sfin-hero-main-title">Biaya Per Siswa</div>
                    <div class="sfin-hero-amount gold">
                        Rp <?= number_format($biaya_per_siswa, 0, ',', '.') ?>
                    </div>
                </div>

                <div class="sfin-stats-grid">
                    <div class="sfin-stat-box">
                        <div class="lbl">Total Anggaran</div>
                        <div class="val text-white">
                            Rp <?= number_format($total_pengeluaran, 0, ',', '.') ?>
                        </div>
                    </div>
                    <div class="sfin-stat-box">
                        <div class="lbl">Status Publikasi</div>
                        <div class="val text-white">
                            <?= ($visibility_setting === 'open' || !$is_student) ? 'Terbuka' : 'Ditutup' ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($visibility_setting == 'closed' && $is_student): ?>
                <div class="card border-0 shadow-sm text-center py-5" style="border-radius: 16px;">
                    <div class="card-body">
                        <i class="fas fa-lock fa-3x mb-3 text-warning"></i>
                        <h6 class="font-weight-bold text-dark mb-1">Rincian Belum Dipublikasikan</h6>
                        <p class="text-muted small mb-0 px-3">
                            Rincian komponen biaya ujian kelas 6 masih dalam tahap penyusunan oleh madrasah dan belum dibuka untuk siswa.
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <!-- Rincian Biaya Ujian -->
                <div class="sfin-section-head">
                    <div class="sfin-section-title">
                        <i class="fas fa-list-alt text-primary"></i> Rincian Komponen Biaya Ujian
                    </div>
                    <div class="sfin-section-count"><?= count($rencana_pengeluaran) ?> Komponen</div>
                </div>

                <!-- Mobile Cards View -->
                <div class="sfin-card-list d-md-none">
                    <?php if (empty($rencana_pengeluaran)): ?>
                        <div class="card border-0 shadow-sm text-center py-4" style="border-radius: 14px;">
                            <div class="text-muted small">Belum ada rincian pengeluaran ujian.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($rencana_pengeluaran as $row): ?>
                            <div class="sfin-exam-card">
                                <div class="min-w-0 flex-grow-1 pr-2">
                                    <div class="sfin-exam-uraian"><?= htmlspecialchars($row['uraian']) ?></div>
                                    <div class="sfin-exam-kategori">
                                        <span class="badge badge-light border mr-1 font-weight-bold" style="font-size: 9.5px;"><?= htmlspecialchars($row['kategori'] ?? 'Umum') ?></span>
                                        <?= (int)$row['volume'] ?> x Rp <?= number_format($row['satuan'], 0, ',', '.') ?>
                                    </div>
                                </div>
                                <div class="sfin-exam-total">
                                    Rp <?= number_format($row['total'], 0, ',', '.') ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Desktop Table View -->
                <div class="row d-none d-md-flex">
                    <div class="col-12">
                        <div class="card border-0 shadow-sm" style="border-radius: 14px;">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover" id="table-pengeluaran">
                                        <thead>
                                            <tr>
                                                <th class="text-center" width="5%">No</th>
                                                <th>Uraian</th>
                                                <th>Kategori</th>
                                                <th class="text-center">Volume</th>
                                                <th class="text-right">Satuan (Rp)</th>
                                                <th class="text-center">Jumlah</th>
                                                <th class="text-center">X</th>
                                                <th class="text-right">Total (Rp)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($rencana_pengeluaran as $i => $row): ?>
                                            <tr>
                                                <td class="text-center"><?= $i + 1 ?></td>
                                                <td><?= htmlspecialchars($row['uraian']) ?></td>
                                                <td><?= htmlspecialchars($row['kategori'] ?? '-') ?></td>
                                                <td class="text-center"><?= number_format($row['volume'], 0, ',', '.') ?></td>
                                                <td class="text-right"><?= number_format($row['satuan'], 0, ',', '.') ?></td>
                                                <td class="text-center"><?= number_format($row['jumlah'], 0, ',', '.') ?></td>
                                                <td class="text-center"><?= number_format($row['perkalian'] ?? 1, 0, ',', '.') ?></td>
                                                <td class="text-right font-weight-bold"><?= number_format($row['total'], 0, ',', '.') ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>

<script>
$(document).ready(function() {
    // Init DataTables
    $('#table-pengeluaran').DataTable({
        ordering: false,
        rowGroup: {
            dataSrc: 2, // Group by Kategori (index 2)
            startRender: function ( rows, group ) {
                var total = rows
                    .data()
                    .pluck(7) // Index 7 is Total column
                    .reduce( function (a, b) {
                        return a + b.replace(/[^\d]/g, '')*1;
                    }, 0);
                
                var totalStr = total.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
                var groupName = group ? group : 'Tanpa Kategori';

                return $('<tr/>')
                    .append( '<td colspan="6" style="background-color:#e2e3e5; font-weight:bold;">'+groupName+'</td>' )
                    .append( '<td style="background-color:#e2e3e5; font-weight:bold; text-align:right;">'+totalStr+'</td>' );
            }
        },
        columnDefs: [
            { targets: [2], visible: false } // Hide Kategori column
        ]
    });
});
</script>
