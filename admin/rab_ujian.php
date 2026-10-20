<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check authorization
if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha', 'bendahara', 'wali', 'guru'])) {
    redirect('../login.php');
}
$is_admin = isAuthorized(['admin', 'tata_usaha', 'bendahara']);
$is_bendahara = isBendahara($pdo);
if ($is_bendahara) {
    $is_admin = true;
}

$page_title = 'RAB Ujian';

// Get school profile
$school_profile = getSchoolProfile($pdo);
$school_name = strtoupper($school_profile['nama_madrasah'] ?? 'Sistem Informasi Madrasah');
$tahun_ajaran = $school_profile['tahun_ajaran'] ?? date('Y');

// --- DATABASE MIGRATION START ---
try {
    // Tabel Pengaturan Aplikasi (Settings)
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_pengaturan_aplikasi (
        id INT PRIMARY KEY AUTO_INCREMENT,
        kunci VARCHAR(50) UNIQUE NOT NULL,
        nilai TEXT,
        keterangan VARCHAR(255),
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Insert default setting for biaya_ujian_visibility if not exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tb_pengaturan_aplikasi WHERE kunci = 'biaya_ujian_visibility'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO tb_pengaturan_aplikasi (kunci, nilai, keterangan) VALUES ('biaya_ujian_visibility', 'closed', 'Visibility of Exam Fees for Students (open/closed)')");
    }

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

// Handle Form Submissions
$message = '';
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!$is_admin) {
        die('Unauthorized');
    }
    $redirect_url = $_SERVER['PHP_SELF'];
    $redirect_hash = '';

    // --- SETTING VISIBILITY ---
    if (isset($_POST['update_visibility'])) {
        $visibility = $_POST['biaya_ujian_visibility']; // open or closed
        $stmt = $pdo->prepare("UPDATE tb_pengaturan_aplikasi SET nilai = ? WHERE kunci = 'biaya_ujian_visibility'");
        if ($stmt->execute([$visibility])) {
            $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Pengaturan visibilitas berhasil diupdate!'];
            logActivity($pdo, $_SESSION['username'] ?? 'system', 'Update Visibilitas Biaya Ujian', "Set to: $visibility");
        } else {
            $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Gagal update pengaturan!'];
        }
    }
    
    // --- PENGELUARAN CRUD ---
    if (isset($_POST['add_pengeluaran'])) {
        $uraian = trim($_POST['uraian']);
        $volume = (int)$_POST['volume'];
        $satuan = (float)str_replace(['Rp', '.', ' '], '', $_POST['satuan']);
        $jumlah = (int)$_POST['jumlah'];
        $perkalian = (int)$_POST['perkalian'];
        $kategori = trim($_POST['kategori']);

        if (!empty($uraian)) {
            $stmt = $pdo->prepare("INSERT INTO tb_pengeluaran_ujian (uraian, volume, satuan, jumlah, perkalian, kategori) VALUES (?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$uraian, $volume, $satuan, $jumlah, $perkalian, $kategori])) {
                $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Pengeluaran berhasil ditambahkan!'];
                logActivity($pdo, $_SESSION['username'] ?? 'system', 'Tambah Pengeluaran Ujian', "Menambahkan: $uraian");
            } else {
                $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Gagal menambahkan data!'];
            }
        }
    } elseif (isset($_POST['update_pengeluaran'])) {
        $id = $_POST['id_pengeluaran'];
        $uraian = trim($_POST['uraian']);
        $volume = (int)$_POST['volume'];
        $satuan = (float)str_replace(['Rp', '.', ' '], '', $_POST['satuan']);
        $jumlah = (int)$_POST['jumlah'];
        $perkalian = (int)$_POST['perkalian'];
        $kategori = trim($_POST['kategori']);

        $stmt = $pdo->prepare("UPDATE tb_pengeluaran_ujian SET uraian=?, volume=?, satuan=?, jumlah=?, perkalian=?, kategori=? WHERE id_pengeluaran=?");
        if ($stmt->execute([$uraian, $volume, $satuan, $jumlah, $perkalian, $kategori, $id])) {
            $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Pengeluaran berhasil diupdate!'];
            $redirect_hash = '#pengeluaran-' . (int)$id;
            logActivity($pdo, $_SESSION['username'] ?? 'system', 'Update Pengeluaran Ujian', "Update ID: $id");
        } else {
            $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Gagal update data!'];
        }
    } elseif (isset($_POST['delete_pengeluaran'])) {
        $id = $_POST['id_pengeluaran'];
        $stmt = $pdo->prepare("DELETE FROM tb_pengeluaran_ujian WHERE id_pengeluaran=?");
        if ($stmt->execute([$id])) {
            $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Data berhasil dihapus!'];
            logActivity($pdo, $_SESSION['username'] ?? 'system', 'Hapus Pengeluaran Ujian', "Hapus ID: $id");
        } else {
            $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Gagal hapus data!'];
        }
    }

    header('Location: ' . $redirect_url . $redirect_hash);
    exit();
}

// Fetch Data
$rencana_pengeluaran = $pdo->query("SELECT * FROM tb_pengeluaran_ujian ORDER BY kategori ASC, id_pengeluaran ASC")->fetchAll(PDO::FETCH_ASSOC);

// Get Visibility Setting
$stmt_setting = $pdo->prepare("SELECT nilai FROM tb_pengaturan_aplikasi WHERE kunci = 'biaya_ujian_visibility'");
$stmt_setting->execute();
$visibility_setting = $stmt_setting->fetchColumn();
if ($visibility_setting === false) $visibility_setting = 'closed';

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
$total_pengeluaran = array_sum(array_column($rencana_pengeluaran, 'total'));
$biaya_per_siswa = $jumlah_siswa > 0 ? $total_pengeluaran / $jumlah_siswa : 0;

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
    'https://cdn.datatables.net/rowgroup/1.1.2/js/dataTables.rowGroup.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js', // Mask Plugin for Rupiah
    'https://cdn.jsdelivr.net/npm/sweetalert2@11'
];

// Include header
include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>RAB Ujian <small style="font-size: 62%; font-weight: 700; margin-left: 10px; vertical-align: middle; color: #5f6fb4; background: #eef1ff; border: 1px solid #d6dcff; border-radius: 999px; padding: 4px 10px;">Tahun Ajaran: <?= htmlspecialchars($tahun_ajaran) ?></small></h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="#">Dashboard</a></div>
                <div class="breadcrumb-item"><a href="#">Keuangan</a></div>
                <div class="breadcrumb-item">RAB Ujian</div>
            </div>
        </div>

        <div class="section-body">
            
            <?php if ($is_admin): ?>
            <div class="rab-card mb-3">
                <div class="rab-card-header">
                    <h4 class="rab-card-title"><i class="fas fa-user-shield"></i>Pengaturan Tampilan Siswa</h4>
                </div>
                <div class="card-body py-3">
                    <form method="POST" action="">
                        <div class="d-flex align-items-center justify-content-between flex-wrap" style="gap: 12px;">
                            <div>
                                <div class="font-weight-bold text-dark mb-1">Status Biaya Ujian di Akun Siswa</div>
                                <div class="text-muted small">Atur apakah rincian biaya ujian dapat dilihat langsung oleh siswa/wali murid.</div>
                            </div>
                            <div class="d-flex align-items-center flex-wrap" style="gap: 12px;">
                                <div class="custom-control custom-radio custom-control-inline mb-0">
                                    <input type="radio" id="visibility_open" name="biaya_ujian_visibility" class="custom-control-input" value="open" <?= $visibility_setting == 'open' ? 'checked' : '' ?>>
                                    <label class="custom-control-label font-weight-bold text-success" for="visibility_open">
                                        <i class="fas fa-eye mr-1"></i>Dibuka (Terlihat)
                                    </label>
                                </div>
                                <div class="custom-control custom-radio custom-control-inline mb-0">
                                    <input type="radio" id="visibility_closed" name="biaya_ujian_visibility" class="custom-control-input" value="closed" <?= $visibility_setting == 'closed' ? 'checked' : '' ?>>
                                    <label class="custom-control-label font-weight-bold text-danger" for="visibility_closed">
                                        <i class="fas fa-eye-slash mr-1"></i>Ditutup (Disembunyikan)
                                    </label>
                                </div>
                                <button type="submit" name="update_visibility" class="rab-chip-btn chip-primary border-0">Simpan Pengaturan</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Hero Budget Overview Card -->
            <div class="rab-hero-card theme-ujian">
                <div class="rab-hero-top">
                    <div class="rab-hero-meta">
                        <div class="rab-hero-icon">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <div>
                            <div class="rab-hero-title">RAB Ujian</div>
                            <div class="rab-hero-sub">Rencana Anggaran Biaya Ujian Madrasah</div>
                        </div>
                    </div>
                    <div class="rab-hero-badge">
                        <i class="far fa-calendar-alt"></i>
                        <span>TA <?= htmlspecialchars($tahun_ajaran) ?></span>
                    </div>
                </div>

                <div class="rab-hero-balance-wrap">
                    <div class="rab-hero-balance-lbl">Total Biaya Anggaran Ujian</div>
                    <div class="rab-hero-balance">Rp <?= number_format($total_pengeluaran, 0, ',', '.') ?></div>
                    <div class="rab-hero-balance-sub">
                        <span class="pill-sisa">
                            <i class="fas fa-user-graduate mr-1"></i>Rp <?= number_format($biaya_per_siswa, 0, ',', '.') ?> / siswa
                        </span>
                        <span>&bull; <?= $jumlah_siswa ?> Siswa Kelas 6</span>
                    </div>
                </div>

                <div class="rab-hero-stats two-cols">
                    <div class="rab-hero-stat-pill">
                        <div class="lbl">Total Kebutuhan</div>
                        <div class="val">Rp <?= number_format($total_pengeluaran, 0, ',', '.') ?></div>
                    </div>
                    <div class="rab-hero-stat-pill">
                        <div class="lbl">Beban Per Siswa</div>
                        <div class="val">Rp <?= number_format($biaya_per_siswa, 0, ',', '.') ?></div>
                    </div>
                </div>
            </div>

            <!-- App Stat Grid (2 KPI Cards) -->
            <div class="rab-stat-grid two-cards">
                <div class="rab-stat-card">
                    <div class="rab-stat-top">
                        <span class="rab-stat-title">Total Biaya Ujian</span>
                        <div class="rab-stat-ico tone-purple"><i class="fas fa-money-bill-wave"></i></div>
                    </div>
                    <div class="rab-stat-val c-purple">Rp <?= number_format($total_pengeluaran, 0, ',', '.') ?></div>
                    <div class="rab-stat-sub">Seluruh pos pengeluaran ujian</div>
                </div>

                <div class="rab-stat-card">
                    <div class="rab-stat-top">
                        <span class="rab-stat-title">Biaya Per Siswa</span>
                        <div class="rab-stat-ico tone-blue"><i class="fas fa-users"></i></div>
                    </div>
                    <div class="rab-stat-val c-blue">Rp <?= number_format($biaya_per_siswa, 0, ',', '.') ?></div>
                    <div class="rab-stat-sub"><?= $jumlah_siswa ?> Siswa Tingkat Akhir</div>
                </div>
            </div>

            <!-- TABEL 2: RENCANA PENGELUARAN -->
            <div class="rab-card">
                <div class="rab-card-header">
                    <h4 class="rab-card-title"><i class="fas fa-file-invoice-dollar"></i>Rencana Anggaran (Pengeluaran)</h4>
                    <div class="rab-actions-bar mb-0">
                        <a href="export_excel_rab_ujian?session_type=<?= $_SESSION['level'] ?>" target="_blank" class="rab-chip-btn chip-success">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </a>
                        <a href="cetak_rab_ujian?session_type=<?= $_SESSION['level'] ?>" target="_blank" class="rab-chip-btn chip-warning">
                            <i class="fas fa-print"></i> Cetak Laporan
                        </a>
                        <?php if ($is_admin): ?>
                        <button type="button" class="rab-chip-btn chip-primary" data-toggle="modal" data-target="#addPengeluaranModal">
                            <i class="fas fa-plus"></i> Tambah Pengeluaran
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                                <table class="table table-striped table-hover rab-table-responsive" id="table-pengeluaran">
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
                                            <?php if ($is_admin): ?>
                                            <th width="15%" class="text-center">Aksi</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rencana_pengeluaran as $i => $row): ?>
                                        <tr id="pengeluaran-<?= (int)$row['id_pengeluaran'] ?>">
                                            <td class="cell-no text-center" data-label="No"><?= $i + 1 ?></td>
                                            <td class="cell-uraian font-weight-bold" data-label="Uraian"><?= htmlspecialchars($row['uraian']) ?></td>
                                            <td class="cell-kategori" data-label="Kategori"><?= htmlspecialchars($row['kategori'] ?? '-') ?></td>
                                            <td class="cell-calc cell-volume text-center" data-label="Volume"><span class="cell-val"><?= number_format($row['volume'], 0, ',', '.') ?></span></td>
                                            <td class="cell-calc cell-satuan text-right" data-label="Satuan (Rp)"><span class="cell-val"><?= number_format($row['satuan'], 0, ',', '.') ?></span></td>
                                            <td class="cell-calc cell-jumlah text-center" data-label="Jumlah"><span class="cell-val"><?= number_format($row['jumlah'], 0, ',', '.') ?></span></td>
                                            <td class="cell-calc cell-perkalian text-center" data-label="Perkalian (X)"><span class="cell-val"><?= number_format($row['perkalian'] ?? 1, 0, ',', '.') ?></span></td>
                                            <td class="cell-total text-right font-weight-bold" data-label="Total (Rp)"><span class="cell-val font-weight-bold"><?= number_format($row['total'], 0, ',', '.') ?></span></td>
                                            <?php if ($is_admin): ?>
                                            <td class="cell-aksi text-center" data-label="Aksi">
                                                <button class="btn btn-warning btn-sm edit-pengeluaran-btn" 
                                                    data-id="<?= $row['id_pengeluaran'] ?>"
                                                    data-uraian="<?= htmlspecialchars($row['uraian']) ?>"
                                                    data-volume="<?= $row['volume'] ?>"
                                                    data-satuan="<?= $row['satuan'] ?>"
                                                    data-jumlah="<?= $row['jumlah'] ?>"
                                                    data-perkalian="<?= $row['perkalian'] ?? 1 ?>"
                                                    data-kategori="<?= htmlspecialchars($row['kategori'] ?? '') ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-danger btn-sm delete-pengeluaran-btn" 
                                                    data-id="<?= $row['id_pengeluaran'] ?>"
                                                    data-uraian="<?= htmlspecialchars($row['uraian']) ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                            <?php endif; ?>
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

<!-- MODAL ADD PENGELUARAN -->
<div class="modal fade" id="addPengeluaranModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Pengeluaran</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form action="" method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Uraian</label>
                        <input type="text" class="form-control" name="uraian" required>
                    </div>
                    <div class="form-group">
                        <label>Kategori (Manual)</label>
                        <input type="text" class="form-control" name="kategori" placeholder="Contoh: Honorarium, Konsumsi, dll">
                    </div>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Volume</label>
                                <input type="number" class="form-control input-volume" name="volume" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Satuan (Rp)</label>
                                <input type="text" class="form-control input-satuan uang" name="satuan" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Jumlah</label>
                                <input type="number" class="form-control input-jumlah-qty" name="jumlah" value="1" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Perkalian (X)</label>
                                <input type="number" class="form-control input-perkalian" name="perkalian" value="1" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Total (Otomatis)</label>
                        <input type="text" class="form-control input-total" readonly style="font-weight: bold; background-color: #e9ecef;">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" name="add_pengeluaran" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL EDIT PENGELUARAN -->
<div class="modal fade" id="editPengeluaranModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Pengeluaran</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form action="" method="POST">
                <input type="hidden" name="id_pengeluaran" id="edit_pengeluaran_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Uraian</label>
                        <input type="text" class="form-control" name="uraian" id="edit_pengeluaran_uraian" required>
                    </div>
                    <div class="form-group">
                        <label>Kategori (Manual)</label>
                        <input type="text" class="form-control" name="kategori" id="edit_pengeluaran_kategori" placeholder="Contoh: Honorarium, Konsumsi, dll">
                    </div>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Volume</label>
                                <input type="number" class="form-control input-volume" name="volume" id="edit_pengeluaran_volume" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Satuan (Rp)</label>
                                <input type="text" class="form-control input-satuan uang" name="satuan" id="edit_pengeluaran_satuan" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Jumlah</label>
                                <input type="number" class="form-control input-jumlah-qty" name="jumlah" id="edit_pengeluaran_jumlah_qty" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Perkalian (X)</label>
                                <input type="number" class="form-control input-perkalian" name="perkalian" id="edit_pengeluaran_perkalian" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Total (Otomatis)</label>
                        <input type="text" class="form-control input-total" id="edit_pengeluaran_total" readonly style="font-weight: bold; background-color: #e9ecef;">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" name="update_pengeluaran" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form id="deletePengeluaranForm" action="" method="POST" style="display: none;">
    <input type="hidden" name="id_pengeluaran" id="delete_pengeluaran_id">
    <input type="hidden" name="delete_pengeluaran" value="1">
</form>

<?php include '../templates/footer.php'; ?>

<script>
$(document).ready(function() {
    var isAdmin = <?= $is_admin ? 'true' : 'false' ?>;

    // Init DataTables
    $('#table-pengeluaran').DataTable({
        autoWidth: false,
        ordering: false,
        paging: false,
        lengthChange: false,
        info: false,
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

                var row = $('<tr class="rab-group-row"/>')
                    .append( '<td colspan="6" class="rab-group-title"><i class="fas fa-layer-group mr-2 text-primary"></i>'+groupName+'</td>' )
                    .append( '<td class="rab-group-total text-right font-weight-bold">'+totalStr+'</td>' );
                
                if (isAdmin) {
                    row.append( '<td class="rab-group-empty"></td>' );
                }
                
                return row;
            }
        },
        columnDefs: [
            { targets: [2], visible: false } // Hide Kategori column
        ],
        initComplete: function () {
            var h = window.location.hash;
            if (!h || !/^#pengeluaran-\d+$/.test(h)) {
                return;
            }
            setTimeout(function () {
                var el = document.querySelector(h);
                if (el) {
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }, 50);
        }
    });

    // Init Rupiah Mask
    $('.uang').mask('000.000.000.000', {reverse: true});

    // Alert Handling
    <?php if ($message): ?>
    var msgType = '<?= $message['type'] == 'success' ? 'success' : 'error' ?>';
    var msgTitle = '<?= $message['type'] == 'success' ? 'Berhasil' : 'Gagal' ?>';
    var msgText = '<?= addslashes($message['text']) ?>';
    if (typeof Swal !== 'undefined') {
        Swal.fire({ icon: msgType, title: msgTitle, text: msgText, showConfirmButton: false, timer: 1500 });
    } else {
        alert(msgTitle + ': ' + msgText);
    }
    <?php endif; ?>

    // --- AUTO CALCULATE FUNCTION ---
    function calculateTotal(container) {
        var volume = parseInt(container.find('.input-volume').val()) || 0;
        var satuanStr = container.find('.input-satuan').val().replace(/\./g, '');
        var satuan = parseInt(satuanStr) || 0;
        var jumlah = parseInt(container.find('.input-jumlah-qty').val()) || 0;
        var perkalian = parseInt(container.find('.input-perkalian').val()) || 1;
        
        var total = volume * satuan * jumlah * perkalian;
        
        var totalStr = total.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        container.find('.input-total').val(totalStr);
    }

    $(document).on('keyup change', '.input-volume, .input-satuan, .input-jumlah-qty, .input-perkalian', function() {
        calculateTotal($(this).closest('.modal-body'));
    });

    // --- PENGELUARAN EVENTS ---
    $('#table-pengeluaran').on('click', '.edit-pengeluaran-btn', function() {
        var id = $(this).data('id');
        var uraian = $(this).data('uraian');
        var volume = $(this).data('volume');
        var satuan = parseInt($(this).data('satuan'));
        var jumlah = $(this).data('jumlah');
        var perkalian = $(this).data('perkalian');
        var kategori = $(this).data('kategori');

        $('#edit_pengeluaran_id').val(id);
        $('#edit_pengeluaran_uraian').val(uraian);
        $('#edit_pengeluaran_volume').val(volume);
        $('#edit_pengeluaran_satuan').val(satuan).mask('000.000.000.000', {reverse: true});
        $('#edit_pengeluaran_jumlah_qty').val(jumlah);
        $('#edit_pengeluaran_perkalian').val(perkalian);
        $('#edit_pengeluaran_kategori').val(kategori);
        
        calculateTotal($('#editPengeluaranModal .modal-body'));
        $('#editPengeluaranModal').modal('show');
    });

    $('#table-pengeluaran').on('click', '.delete-pengeluaran-btn', function() {
        var id = $(this).data('id');
        var uraian = $(this).data('uraian');
        Swal.fire({
            title: 'Hapus Pengeluaran?',
            text: "Yakin ingin menghapus '" + uraian + "'?",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus!'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#delete_pengeluaran_id').val(id);
                $('#deletePengeluaranForm').submit();
            }
        });
    });
});
</script>
