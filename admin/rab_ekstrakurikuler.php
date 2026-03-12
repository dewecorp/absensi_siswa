<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check authorization
if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha', 'wali', 'guru'])) {
    redirect('../login.php');
}
$is_admin = isAuthorized(['admin']);

$page_title = 'RAB Ekstrakurikuler';

// Get school profile
$school_profile = getSchoolProfile($pdo);
$school_name = strtoupper($school_profile['nama_madrasah'] ?? 'Sistem Absensi Siswa');

// --- DATABASE MIGRATION START ---
try {
    // Tabel Sumber Anggaran Ekstra
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_sumber_ekstra (
        id_sumber INT PRIMARY KEY AUTO_INCREMENT,
        uraian VARCHAR(255) NOT NULL,
        volume INT NOT NULL DEFAULT 0,
        satuan DECIMAL(15,2) NOT NULL DEFAULT 0,
        jumlah INT NOT NULL DEFAULT 1,
        total DECIMAL(15,2) GENERATED ALWAYS AS (volume * satuan * jumlah) STORED,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Tabel Pengeluaran Ekstra
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_pengeluaran_ekstra (
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

    // Migration for tb_sumber_ekstra
    $cols_sumber = $pdo->query("SHOW COLUMNS FROM tb_sumber_ekstra LIKE 'total'")->fetchAll();
    if (empty($cols_sumber)) {
        // Check if 'jumlah' exists (old version might have it as decimal total)
        $col_jumlah = $pdo->query("SHOW COLUMNS FROM tb_sumber_ekstra LIKE 'jumlah'")->fetchAll();
        if (!empty($col_jumlah)) {
             $pdo->exec("ALTER TABLE tb_sumber_ekstra DROP COLUMN jumlah");
        }
        $pdo->exec("ALTER TABLE tb_sumber_ekstra ADD COLUMN jumlah INT NOT NULL DEFAULT 1 AFTER satuan");
        $pdo->exec("ALTER TABLE tb_sumber_ekstra ADD COLUMN total DECIMAL(15,2) GENERATED ALWAYS AS (volume * satuan * jumlah) STORED AFTER jumlah");
    }

    // Migration for tb_pengeluaran_ekstra
    $cols_pengeluaran = $pdo->query("SHOW COLUMNS FROM tb_pengeluaran_ekstra LIKE 'kategori'")->fetchAll();
    if (empty($cols_pengeluaran)) {
        $pdo->exec("ALTER TABLE tb_pengeluaran_ekstra ADD COLUMN kategori VARCHAR(255) NULL AFTER jumlah");
        $pdo->exec("ALTER TABLE tb_pengeluaran_ekstra ADD COLUMN perkalian INT NOT NULL DEFAULT 1 AFTER jumlah");
        
        // Re-generate total column to include perkalian
        $pdo->exec("ALTER TABLE tb_pengeluaran_ekstra DROP COLUMN total");
        $pdo->exec("ALTER TABLE tb_pengeluaran_ekstra ADD COLUMN total DECIMAL(15,2) GENERATED ALWAYS AS (volume * satuan * jumlah * perkalian) STORED AFTER kategori");
    }
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
    
    // --- SUMBER ANGGARAN CRUD ---
    if (isset($_POST['add_sumber'])) {
        $uraian = trim($_POST['uraian']);
        $volume = (int)$_POST['volume'];
        $satuan = (float)str_replace(['Rp', '.', ' '], '', $_POST['satuan']); 
        $jumlah = (int)$_POST['jumlah'];

        if (!empty($uraian)) {
            $stmt = $pdo->prepare("INSERT INTO tb_sumber_ekstra (uraian, volume, satuan, jumlah) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$uraian, $volume, $satuan, $jumlah])) {
                $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Sumber anggaran berhasil ditambahkan!'];
                logActivity($pdo, $_SESSION['username'] ?? 'system', 'Tambah Sumber Ekstra', "Menambahkan: $uraian");
            } else {
                $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Gagal menambahkan data!'];
            }
        }
    } elseif (isset($_POST['update_sumber'])) {
        $id = $_POST['id_sumber'];
        $uraian = trim($_POST['uraian']);
        $volume = (int)$_POST['volume'];
        $satuan = (float)str_replace(['Rp', '.', ' '], '', $_POST['satuan']);
        $jumlah = (int)$_POST['jumlah'];

        $stmt = $pdo->prepare("UPDATE tb_sumber_ekstra SET uraian=?, volume=?, satuan=?, jumlah=? WHERE id_sumber=?");
        if ($stmt->execute([$uraian, $volume, $satuan, $jumlah, $id])) {
            $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Sumber anggaran berhasil diupdate!'];
            logActivity($pdo, $_SESSION['username'] ?? 'system', 'Update Sumber Ekstra', "Update ID: $id");
        } else {
            $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Gagal update data!'];
        }
    } elseif (isset($_POST['delete_sumber'])) {
        $id = $_POST['id_sumber'];
        $stmt = $pdo->prepare("DELETE FROM tb_sumber_ekstra WHERE id_sumber=?");
        if ($stmt->execute([$id])) {
            $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Data berhasil dihapus!'];
            logActivity($pdo, $_SESSION['username'] ?? 'system', 'Hapus Sumber Ekstra', "Hapus ID: $id");
        } else {
            $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Gagal hapus data!'];
        }
    }

    // --- PENGELUARAN CRUD ---
    elseif (isset($_POST['add_pengeluaran'])) {
        $uraian = trim($_POST['uraian']);
        $volume = (int)$_POST['volume'];
        $satuan = (float)str_replace(['Rp', '.', ' '], '', $_POST['satuan']);
        $jumlah = (int)$_POST['jumlah'];
        $perkalian = (int)$_POST['perkalian'];
        $kategori = trim($_POST['kategori']);

        if (!empty($uraian)) {
            $stmt = $pdo->prepare("INSERT INTO tb_pengeluaran_ekstra (uraian, volume, satuan, jumlah, perkalian, kategori) VALUES (?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$uraian, $volume, $satuan, $jumlah, $perkalian, $kategori])) {
                $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Pengeluaran berhasil ditambahkan!'];
                logActivity($pdo, $_SESSION['username'] ?? 'system', 'Tambah Pengeluaran Ekstra', "Menambahkan: $uraian");
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

        $stmt = $pdo->prepare("UPDATE tb_pengeluaran_ekstra SET uraian=?, volume=?, satuan=?, jumlah=?, perkalian=?, kategori=? WHERE id_pengeluaran=?");
        if ($stmt->execute([$uraian, $volume, $satuan, $jumlah, $perkalian, $kategori, $id])) {
            $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Pengeluaran berhasil diupdate!'];
            logActivity($pdo, $_SESSION['username'] ?? 'system', 'Update Pengeluaran Ekstra', "Update ID: $id");
        } else {
            $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Gagal update data!'];
        }
    } elseif (isset($_POST['delete_pengeluaran'])) {
        $id = $_POST['id_pengeluaran'];
        $stmt = $pdo->prepare("DELETE FROM tb_pengeluaran_ekstra WHERE id_pengeluaran=?");
        if ($stmt->execute([$id])) {
            $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Data berhasil dihapus!'];
            logActivity($pdo, $_SESSION['username'] ?? 'system', 'Hapus Pengeluaran Ekstra', "Hapus ID: $id");
        } else {
            $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Gagal hapus data!'];
        }
    }

    header("Location: $redirect_url");
    exit();
}

// Fetch Data
$sumber_anggaran = $pdo->query("SELECT * FROM tb_sumber_ekstra ORDER BY id_sumber ASC")->fetchAll(PDO::FETCH_ASSOC);
$rencana_pengeluaran = $pdo->query("SELECT * FROM tb_pengeluaran_ekstra ORDER BY kategori ASC, id_pengeluaran ASC")->fetchAll(PDO::FETCH_ASSOC);

// Calculate Totals
$total_sumber = array_sum(array_column($sumber_anggaran, 'total'));
$total_pengeluaran = array_sum(array_column($rencana_pengeluaran, 'total'));
$sisa_anggaran = $total_sumber - $total_pengeluaran;

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
    'https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js' // Mask Plugin for Rupiah
];

// Include header
include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>RAB Ekstrakurikuler</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="#">Dashboard</a></div>
                <div class="breadcrumb-item"><a href="#">Keuangan</a></div>
                <div class="breadcrumb-item">RAB Ekstrakurikuler</div>
            </div>
        </div>

        <div class="section-body">
            
            <!-- Summary Cards -->
            <div class="row">
                <div class="col-lg-4 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1">
                        <div class="card-icon bg-success">
                            <i class="fas fa-wallet"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4>Total Sumber Anggaran</h4>
                            </div>
                            <div class="card-body">
                                Rp <?= number_format($total_sumber, 0, ',', '.') ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1">
                        <div class="card-icon bg-warning">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4>Total Pengeluaran</h4>
                            </div>
                            <div class="card-body">
                                Rp <?= number_format($total_pengeluaran, 0, ',', '.') ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1">
                        <div class="card-icon bg-<?= $sisa_anggaran >= 0 ? 'info' : 'danger' ?>">
                            <i class="fas fa-balance-scale"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4>Sisa Anggaran</h4>
                            </div>
                            <div class="card-body">
                                Rp <?= number_format($sisa_anggaran, 0, ',', '.') ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TABEL 1: SUMBER ANGGARAN -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Sumber Anggaran</h4>
                            <div class="card-header-action">
                                <a href="export_excel_rab_ekstra?session_type=admin" target="_blank" class="btn btn-success mr-2">
                                    <i class="fas fa-file-excel"></i> Export Excel
                                </a>
                                <a href="cetak_rab_ekstra?session_type=admin" target="_blank" class="btn btn-warning mr-2">
                                    <i class="fas fa-print"></i> Cetak Laporan
                                </a>
                                <?php if ($is_admin): ?>
                                <button class="btn btn-primary" data-toggle="modal" data-target="#addSumberModal">
                                    <i class="fas fa-plus"></i> Tambah Sumber
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover" id="table-sumber">
                                    <thead>
                                        <tr>
                                            <th class="text-center" width="5%">No</th>
                                            <th>Uraian</th>
                                            <th class="text-center">Volume</th>
                                            <th class="text-right">Satuan (Rp)</th>
                                            <th class="text-center">Jumlah</th>
                                            <th class="text-right">Total (Rp)</th>
                                            <?php if ($is_admin): ?>
                                            <th width="15%" class="text-center">Aksi</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($sumber_anggaran as $i => $row): ?>
                                        <tr>
                                            <td class="text-center"><?= $i + 1 ?></td>
                                            <td><?= htmlspecialchars($row['uraian']) ?></td>
                                            <td class="text-center"><?= number_format($row['volume'], 0, ',', '.') ?></td>
                                            <td class="text-right"><?= number_format($row['satuan'], 0, ',', '.') ?></td>
                                            <td class="text-center"><?= number_format($row['jumlah'], 0, ',', '.') ?></td>
                                            <td class="text-right font-weight-bold"><?= number_format($row['total'], 0, ',', '.') ?></td>
                                            <?php if ($is_admin): ?>
                                            <td class="text-center">
                                                <button class="btn btn-warning btn-sm edit-sumber-btn" 
                                                    data-id="<?= $row['id_sumber'] ?>"
                                                    data-uraian="<?= htmlspecialchars($row['uraian']) ?>"
                                                    data-volume="<?= $row['volume'] ?>"
                                                    data-satuan="<?= $row['satuan'] ?>"
                                                    data-jumlah="<?= $row['jumlah'] ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-danger btn-sm delete-sumber-btn" 
                                                    data-id="<?= $row['id_sumber'] ?>"
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

            <!-- TABEL 2: RENCANA PENGELUARAN -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Rencana Anggaran (Pengeluaran)</h4>
                            <div class="card-header-action">
                                <?php if ($is_admin): ?>
                                <button class="btn btn-primary" data-toggle="modal" data-target="#addPengeluaranModal">
                                    <i class="fas fa-plus"></i> Tambah Pengeluaran
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
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
                                            <?php if ($is_admin): ?>
                                            <th width="15%" class="text-center">Aksi</th>
                                            <?php endif; ?>
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
                                            <?php if ($is_admin): ?>
                                            <td class="text-center">
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

<!-- MODAL ADD SUMBER -->
<div class="modal fade" id="addSumberModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Sumber Anggaran</h5>
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
                        <input type="text" class="form-control" name="kategori" placeholder="Contoh: Transport, Konsumsi, dll">
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
                    <button type="submit" name="add_sumber" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL EDIT SUMBER -->
<div class="modal fade" id="editSumberModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Sumber Anggaran</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form action="" method="POST">
                <input type="hidden" name="id_sumber" id="edit_sumber_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Uraian</label>
                        <input type="text" class="form-control" name="uraian" id="edit_sumber_uraian" required>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Volume</label>
                                <input type="number" class="form-control input-volume" name="volume" id="edit_sumber_volume" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Satuan (Rp)</label>
                                <input type="text" class="form-control input-satuan uang" name="satuan" id="edit_sumber_satuan" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Jumlah</label>
                                <input type="number" class="form-control input-jumlah-qty" name="jumlah" id="edit_sumber_jumlah_qty" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Total (Otomatis)</label>
                        <input type="text" class="form-control input-total" id="edit_sumber_total" readonly style="font-weight: bold; background-color: #e9ecef;">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" name="update_sumber" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
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
                        <input type="text" class="form-control" name="kategori" placeholder="Contoh: Transport, Konsumsi, dll">
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
                        <input type="text" class="form-control" name="kategori" id="edit_pengeluaran_kategori" placeholder="Contoh: Transport, Konsumsi, dll">
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

<!-- FORM DELETE HIDDEN -->
<form id="deleteSumberForm" action="" method="POST" style="display: none;">
    <input type="hidden" name="id_sumber" id="delete_sumber_id">
    <input type="hidden" name="delete_sumber" value="1">
</form>

<form id="deletePengeluaranForm" action="" method="POST" style="display: none;">
    <input type="hidden" name="id_pengeluaran" id="delete_pengeluaran_id">
    <input type="hidden" name="delete_pengeluaran" value="1">
</form>

<?php include '../templates/footer.php'; ?>

<script>
$(document).ready(function() {
    var isAdmin = <?= $is_admin ? 'true' : 'false' ?>;

    // Init DataTables
    $('#table-sumber').DataTable({ ordering: false });
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

                var row = $('<tr/>')
                    .append( '<td colspan="6" style="background-color:#e2e3e5; font-weight:bold;">'+groupName+'</td>' )
                    .append( '<td style="background-color:#e2e3e5; font-weight:bold; text-align:right;">'+totalStr+'</td>' );
                
                if (isAdmin) {
                    row.append( '<td style="background-color:#e2e3e5;"></td>' );
                }
                
                return row;
            }
        },
        columnDefs: [
            { targets: [2], visible: false } // Hide Kategori column
        ]
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

    // --- SUMBER ANGGARAN EVENTS ---
    $('#table-sumber').on('click', '.edit-sumber-btn', function() {
        var id = $(this).data('id');
        var uraian = $(this).data('uraian');
        var volume = $(this).data('volume');
        var satuan = parseInt($(this).data('satuan')); 
        var jumlah = $(this).data('jumlah');

        $('#edit_sumber_id').val(id);
        $('#edit_sumber_uraian').val(uraian);
        $('#edit_sumber_volume').val(volume);
        $('#edit_sumber_satuan').val(satuan).mask('000.000.000.000', {reverse: true});
        $('#edit_sumber_jumlah_qty').val(jumlah);
        
        calculateTotal($('#editSumberModal .modal-body'));
        $('#editSumberModal').modal('show');
    });

    $('#table-sumber').on('click', '.delete-sumber-btn', function() {
        var id = $(this).data('id');
        var uraian = $(this).data('uraian');
        Swal.fire({
            title: 'Hapus Sumber?',
            text: "Yakin ingin menghapus '" + uraian + "'?",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus!'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#delete_sumber_id').val(id);
                $('#deleteSumberForm').submit();
            }
        });
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
