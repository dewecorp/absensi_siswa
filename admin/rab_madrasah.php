<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check authorization
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

$page_title = 'RAB Madrasah';

// Get school profile
$school_profile = getSchoolProfile($pdo);
$school_name = strtoupper($school_profile['nama_madrasah'] ?? 'Sistem Absensi Siswa');

// --- DATABASE MIGRATION START ---
try {
    // Tabel Sumber Anggaran
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_sumber_anggaran (
        id_sumber INT PRIMARY KEY AUTO_INCREMENT,
        uraian VARCHAR(255) NOT NULL,
        volume INT NOT NULL DEFAULT 0,
        satuan DECIMAL(15,2) NOT NULL DEFAULT 0,
        jumlah INT NOT NULL DEFAULT 1,
        total DECIMAL(15,2) GENERATED ALWAYS AS (volume * satuan * jumlah) STORED,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Tabel Rencana Pengeluaran
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_rencana_pengeluaran (
        id_pengeluaran INT PRIMARY KEY AUTO_INCREMENT,
        uraian VARCHAR(255) NOT NULL,
        volume INT NOT NULL DEFAULT 0,
        satuan DECIMAL(15,2) NOT NULL DEFAULT 0,
        jumlah INT NOT NULL DEFAULT 1,
        total DECIMAL(15,2) GENERATED ALWAYS AS (volume * satuan * jumlah) STORED,
        id_kategori INT NULL,
        sub_kategori VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Add sub_kategori column if not exists
    $columns = $pdo->query("SHOW COLUMNS FROM tb_rencana_pengeluaran LIKE 'sub_kategori'")->fetchAll();
    if (empty($columns)) {
        $pdo->exec("ALTER TABLE tb_rencana_pengeluaran ADD COLUMN sub_kategori VARCHAR(255) NULL AFTER id_kategori");
    }

    // Migrate 'jumlah' column to 'total' and recreate 'jumlah' as INT for existing tables
    // Check if 'total' exists in tb_sumber_anggaran
    $cols_sumber = $pdo->query("SHOW COLUMNS FROM tb_sumber_anggaran LIKE 'total'")->fetchAll();
    if (empty($cols_sumber)) {
        $pdo->exec("ALTER TABLE tb_sumber_anggaran DROP COLUMN jumlah");
        $pdo->exec("ALTER TABLE tb_sumber_anggaran ADD COLUMN jumlah INT NOT NULL DEFAULT 1 AFTER satuan");
        $pdo->exec("ALTER TABLE tb_sumber_anggaran ADD COLUMN total DECIMAL(15,2) GENERATED ALWAYS AS (volume * satuan * jumlah) STORED AFTER jumlah");
    }

    // Check if 'total' exists in tb_rencana_pengeluaran
    $cols_pengeluaran = $pdo->query("SHOW COLUMNS FROM tb_rencana_pengeluaran LIKE 'total'")->fetchAll();
    if (empty($cols_pengeluaran)) {
        $pdo->exec("ALTER TABLE tb_rencana_pengeluaran DROP COLUMN jumlah");
        $pdo->exec("ALTER TABLE tb_rencana_pengeluaran ADD COLUMN jumlah INT NOT NULL DEFAULT 1 AFTER satuan");
        $pdo->exec("ALTER TABLE tb_rencana_pengeluaran ADD COLUMN total DECIMAL(15,2) GENERATED ALWAYS AS (volume * satuan * jumlah) STORED AFTER jumlah");
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
    $redirect_url = $_SERVER['PHP_SELF'];
    
    // --- SUMBER ANGGARAN CRUD ---
    if (isset($_POST['add_sumber'])) {
        $uraian = trim($_POST['uraian']);
        $volume = (int)$_POST['volume'];
        $satuan = (float)str_replace(['Rp', '.', ' '], '', $_POST['satuan']); 
        $jumlah = (int)$_POST['jumlah'];

        if (!empty($uraian)) {
            $stmt = $pdo->prepare("INSERT INTO tb_sumber_anggaran (uraian, volume, satuan, jumlah) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$uraian, $volume, $satuan, $jumlah])) {
                $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Sumber anggaran berhasil ditambahkan!'];
                logActivity($pdo, $_SESSION['username'] ?? 'system', 'Tambah Sumber Anggaran', "Menambahkan: $uraian");
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

        $stmt = $pdo->prepare("UPDATE tb_sumber_anggaran SET uraian=?, volume=?, satuan=?, jumlah=? WHERE id_sumber=?");
        if ($stmt->execute([$uraian, $volume, $satuan, $jumlah, $id])) {
            $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Sumber anggaran berhasil diupdate!'];
            logActivity($pdo, $_SESSION['username'] ?? 'system', 'Update Sumber Anggaran', "Update ID: $id");
        } else {
            $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Gagal update data!'];
        }
    } elseif (isset($_POST['delete_sumber'])) {
        $id = $_POST['id_sumber'];
        $stmt = $pdo->prepare("DELETE FROM tb_sumber_anggaran WHERE id_sumber=?");
        if ($stmt->execute([$id])) {
            $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Data berhasil dihapus!'];
            logActivity($pdo, $_SESSION['username'] ?? 'system', 'Hapus Sumber Anggaran', "Hapus ID: $id");
        } else {
            $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Gagal hapus data!'];
        }
    }

    // --- RENCANA PENGELUARAN CRUD ---
    elseif (isset($_POST['add_pengeluaran'])) {
        $uraian = trim($_POST['uraian']);
        $volume = (int)$_POST['volume'];
        $satuan = (float)str_replace(['Rp', '.', ' '], '', $_POST['satuan']);
        $jumlah = (int)$_POST['jumlah'];
        $id_kategori = !empty($_POST['id_kategori']) ? (int)$_POST['id_kategori'] : null;
        $sub_kategori = !empty($_POST['sub_kategori']) ? trim($_POST['sub_kategori']) : null;

        if (!empty($uraian)) {
            $stmt = $pdo->prepare("INSERT INTO tb_rencana_pengeluaran (uraian, volume, satuan, jumlah, id_kategori, sub_kategori) VALUES (?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$uraian, $volume, $satuan, $jumlah, $id_kategori, $sub_kategori])) {
                $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Rencana pengeluaran berhasil ditambahkan!'];
                logActivity($pdo, $_SESSION['username'] ?? 'system', 'Tambah Rencana Pengeluaran', "Menambahkan: $uraian");
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
        $id_kategori = !empty($_POST['id_kategori']) ? (int)$_POST['id_kategori'] : null;
        $sub_kategori = !empty($_POST['sub_kategori']) ? trim($_POST['sub_kategori']) : null;

        $stmt = $pdo->prepare("UPDATE tb_rencana_pengeluaran SET uraian=?, volume=?, satuan=?, jumlah=?, id_kategori=?, sub_kategori=? WHERE id_pengeluaran=?");
        if ($stmt->execute([$uraian, $volume, $satuan, $jumlah, $id_kategori, $sub_kategori, $id])) {
            $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Rencana pengeluaran berhasil diupdate!'];
            logActivity($pdo, $_SESSION['username'] ?? 'system', 'Update Rencana Pengeluaran', "Update ID: $id");
        } else {
            $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Gagal update data!'];
        }
    } elseif (isset($_POST['delete_pengeluaran'])) {
        $id = $_POST['id_pengeluaran'];
        $stmt = $pdo->prepare("DELETE FROM tb_rencana_pengeluaran WHERE id_pengeluaran=?");
        if ($stmt->execute([$id])) {
            $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Data berhasil dihapus!'];
            logActivity($pdo, $_SESSION['username'] ?? 'system', 'Hapus Rencana Pengeluaran', "Hapus ID: $id");
        } else {
            $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Gagal hapus data!'];
        }
    }

    header("Location: $redirect_url");
    exit();
}

// Fetch Data
$sumber_anggaran = $pdo->query("SELECT * FROM tb_sumber_anggaran ORDER BY id_sumber ASC")->fetchAll(PDO::FETCH_ASSOC);
$rencana_pengeluaran = $pdo->query("SELECT p.*, k.nama_kategori FROM tb_rencana_pengeluaran p LEFT JOIN tb_kategori_anggaran k ON p.id_kategori = k.id_kategori ORDER BY k.nama_kategori ASC, p.id_pengeluaran ASC")->fetchAll(PDO::FETCH_ASSOC);
$kategori_anggaran = $pdo->query("SELECT * FROM tb_kategori_anggaran ORDER BY nama_kategori ASC")->fetchAll(PDO::FETCH_ASSOC);

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
            <h1>RAB Madrasah</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="#">Dashboard</a></div>
                <div class="breadcrumb-item"><a href="#">Keuangan</a></div>
                <div class="breadcrumb-item">RAB Madrasah</div>
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
                                <h4>Total Rencana Pengeluaran</h4>
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
                                <button class="btn btn-primary" data-toggle="modal" data-target="#addSumberModal">
                                    <i class="fas fa-plus"></i> Tambah Sumber
                                </button>
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
                                            <th width="15%" class="text-center">Aksi</th>
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
                                <button class="btn btn-primary" data-toggle="modal" data-target="#addPengeluaranModal">
                                    <i class="fas fa-plus"></i> Tambah Pengeluaran
                                </button>
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
                                            <th>Sub Kategori</th>
                                            <th class="text-center">Volume</th>
                                            <th class="text-right">Satuan (Rp)</th>
                                            <th class="text-center">Jumlah</th>
                                            <th class="text-right">Total (Rp)</th>
                                            <th width="15%" class="text-center">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rencana_pengeluaran as $i => $row): ?>
                                        <tr>
                                            <td class="text-center"><?= $i + 1 ?></td>
                                            <td><?= htmlspecialchars($row['uraian']) ?></td>
                                            <td><?= htmlspecialchars($row['nama_kategori'] ?? 'Tanpa Kategori') ?></td>
                                            <td><?= htmlspecialchars($row['sub_kategori'] ?? '-') ?></td>
                                            <td class="text-center"><?= number_format($row['volume'], 0, ',', '.') ?></td>
                                            <td class="text-right"><?= number_format($row['satuan'], 0, ',', '.') ?></td>
                                            <td class="text-center"><?= number_format($row['jumlah'], 0, ',', '.') ?></td>
                                            <td class="text-right font-weight-bold"><?= number_format($row['total'], 0, ',', '.') ?></td>
                                            <td class="text-center">
                                                <button class="btn btn-warning btn-sm edit-pengeluaran-btn" 
                                                    data-id="<?= $row['id_pengeluaran'] ?>"
                                                    data-uraian="<?= htmlspecialchars($row['uraian']) ?>"
                                                    data-kategori="<?= $row['id_kategori'] ?>"
                                                    data-sub_kategori="<?= htmlspecialchars($row['sub_kategori'] ?? '') ?>"
                                                    data-volume="<?= $row['volume'] ?>"
                                                    data-satuan="<?= $row['satuan'] ?>"
                                                    data-jumlah="<?= $row['jumlah'] ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-danger btn-sm delete-pengeluaran-btn" 
                                                    data-id="<?= $row['id_pengeluaran'] ?>"
                                                    data-uraian="<?= htmlspecialchars($row['uraian']) ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
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
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Volume</label>
                                <input type="number" class="form-control input-volume" name="volume" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Satuan (Rp)</label>
                                <input type="text" class="form-control input-satuan uang" name="satuan" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Jumlah</label>
                                <input type="number" class="form-control input-jumlah-qty" name="jumlah" value="1" required>
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
                <h5 class="modal-title">Tambah Rencana Pengeluaran</h5>
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
                        <label>Kategori Anggaran</label>
                        <select class="form-control" name="id_kategori">
                            <option value="">-- Pilih Kategori --</option>
                            <?php foreach ($kategori_anggaran as $kat): ?>
                                <option value="<?= $kat['id_kategori'] ?>"><?= htmlspecialchars($kat['nama_kategori']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Sub Kategori (Opsional)</label>
                        <input type="text" class="form-control" name="sub_kategori" placeholder="Contoh: Kegiatan KSM">
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Volume</label>
                                <input type="number" class="form-control input-volume" name="volume" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Satuan (Rp)</label>
                                <input type="text" class="form-control input-satuan uang" name="satuan" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Jumlah</label>
                                <input type="number" class="form-control input-jumlah-qty" name="jumlah" value="1" required>
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
                <h5 class="modal-title">Edit Rencana Pengeluaran</h5>
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
                        <label>Kategori Anggaran</label>
                        <select class="form-control" name="id_kategori" id="edit_pengeluaran_kategori">
                            <option value="">-- Pilih Kategori --</option>
                            <?php foreach ($kategori_anggaran as $kat): ?>
                                <option value="<?= $kat['id_kategori'] ?>"><?= htmlspecialchars($kat['nama_kategori']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Sub Kategori (Opsional)</label>
                        <input type="text" class="form-control" name="sub_kategori" id="edit_pengeluaran_sub_kategori" placeholder="Contoh: Kegiatan KSM">
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Volume</label>
                                <input type="number" class="form-control input-volume" name="volume" id="edit_pengeluaran_volume" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Satuan (Rp)</label>
                                <input type="text" class="form-control input-satuan uang" name="satuan" id="edit_pengeluaran_satuan" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Jumlah</label>
                                <input type="number" class="form-control input-jumlah-qty" name="jumlah" id="edit_pengeluaran_jumlah_qty" required>
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
    // Init DataTables
    $('#table-sumber').DataTable();
    $('#table-pengeluaran').DataTable({
        ordering: false, // Matikan fitur sorting agar sesuai urutan database
        rowGroup: {
            dataSrc: [2, 3],
            startRender: function ( rows, group, level ) {
                // If sub-category is empty, don't show the header row for it
                if (level === 1 && (!group || group === '-')) {
                    return null;
                }

                var total = rows
                    .data()
                    .pluck(7) // Index 7 is Total column (Rp)
                    .reduce( function (a, b) {
                        return a + b.replace(/[^\d]/g, '')*1;
                    }, 0);
                
                var totalStr = total.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
                var groupName = group ? group : (level === 0 ? 'Tanpa Kategori' : 'Tanpa Sub Kategori');

                var backgroundColor = level === 0 ? '#e2e3e5' : '#f8f9fa';
                var fontWeight = level === 0 ? 'bold' : 'normal';
                var paddingLeft = level === 0 ? '10px' : '30px';
                
                var label;
                if (level === 0) {
                    label = '<span class="badge badge-primary" style="font-size: 14px;">' + groupName + '</span>';
                } else {
                    label = '<i class="fas fa-angle-right mr-2"></i> <span class="badge badge-success border" style="font-size: 13px;">' + groupName + '</span>';
                }

                return $('<tr/>')
                    .append( '<td colspan="7" style="background-color:'+backgroundColor+'; font-weight:'+fontWeight+'; padding-left:'+paddingLeft+';">'+label+'</td>' )
                    .append( '<td style="background-color:'+backgroundColor+'; font-weight:bold; text-align:right;">'+totalStr+'</td>' )
                    .append( '<td style="background-color:'+backgroundColor+';"></td>' );
            }
        },
        columnDefs: [
            { targets: [2, 3], visible: false }
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
        
        var total = volume * satuan * jumlah;
        
        // Format to Rupiah
        var totalStr = total.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        container.find('.input-total').val(totalStr);
    }

    // Bind calculation events
    $(document).on('keyup change', '.input-volume, .input-satuan, .input-jumlah-qty', function() {
        calculateTotal($(this).closest('.modal-body'));
    });

    // --- SUMBER ANGGARAN EVENTS ---
    
    // Edit Sumber
    $('#table-sumber').on('click', '.edit-sumber-btn', function() {
        var id = $(this).data('id');
        var uraian = $(this).data('uraian');
        var volume = $(this).data('volume');
        var satuan = parseInt($(this).data('satuan')); // Parse as Int to remove .00
        var jumlah = $(this).data('jumlah');

        $('#edit_sumber_id').val(id);
        $('#edit_sumber_uraian').val(uraian);
        $('#edit_sumber_volume').val(volume);
        $('#edit_sumber_satuan').val(satuan).mask('000.000.000.000', {reverse: true});
        $('#edit_sumber_jumlah_qty').val(jumlah);
        
        // Calculate manually
        calculateTotal($('#editSumberModal .modal-body'));
        $('#editSumberModal').modal('show');
    });

    // Delete Sumber
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

    // --- RENCANA PENGELUARAN EVENTS ---

    // Edit Pengeluaran
    $('#table-pengeluaran').on('click', '.edit-pengeluaran-btn', function() {
        var id = $(this).data('id');
        var uraian = $(this).data('uraian');
        var volume = $(this).data('volume');
        var satuan = parseInt($(this).data('satuan')); // Parse as Int to remove .00
        var jumlah = $(this).data('jumlah');
        var kategori = $(this).data('kategori');
        var sub_kategori = $(this).data('sub_kategori');

        $('#edit_pengeluaran_id').val(id);
        $('#edit_pengeluaran_uraian').val(uraian);
        $('#edit_pengeluaran_volume').val(volume);
        $('#edit_pengeluaran_satuan').val(satuan).mask('000.000.000.000', {reverse: true});
        $('#edit_pengeluaran_jumlah_qty').val(jumlah);
        $('#edit_pengeluaran_kategori').val(kategori);
        $('#edit_pengeluaran_sub_kategori').val(sub_kategori);
        
        // Calculate manually
        calculateTotal($('#editPengeluaranModal .modal-body'));
        $('#editPengeluaranModal').modal('show');
    });

    // Delete Pengeluaran
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
