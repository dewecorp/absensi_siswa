<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) session_start();

if (!isAuthorized(['admin', 'tata_usaha'])) {
    redirect('../login.php');
}

// Success message from query param
$success_msg = '';
if (isset($_GET['success'])) {
    $msgs = [
        'add' => 'Prestasi berhasil ditambahkan.',
        'edit' => 'Prestasi berhasil diubah.',
        'del' => 'Prestasi berhasil dihapus.'
    ];
    $success_msg = $msgs[$_GET['success']] ?? '';
}

$css_libs = [];
$js_libs = [];
$page_title = 'Data Prestasi Siswa';
$js_page = ["
var successMsg = " . json_encode($success_msg) . ";

$(document).ready(function() {
    if (successMsg) {
        Swal.fire({ icon: 'success', title: 'Berhasil', text: successMsg, timer: 2000, showConfirmButton: false });
    }
});
"];

// Create table if not exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_prestasi_siswa (
        id_prestasi INT AUTO_INCREMENT PRIMARY KEY,
        nama_siswa VARCHAR(100) NOT NULL,
        prestasi VARCHAR(255) NOT NULL,
        tahun VARCHAR(4) NOT NULL,
        tingkat ENUM('Kecamatan','Kabupaten','Provinsi','Nasional') NOT NULL DEFAULT 'Kecamatan',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Migrasi: jika kolom nama_siswa belum ada (tabel lama pake id_siswa)
    $check = $pdo->query("SHOW COLUMNS FROM tb_prestasi_siswa LIKE 'nama_siswa'");
    if ($check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE tb_prestasi_siswa ADD COLUMN nama_siswa VARCHAR(100) NOT NULL AFTER id_prestasi");
        $pdo->exec("ALTER TABLE tb_prestasi_siswa DROP FOREIGN KEY tb_prestasi_siswa_ibfk_1");
        $pdo->exec("ALTER TABLE tb_prestasi_siswa DROP COLUMN id_siswa");
    }
} catch (PDOException $e) {
    error_log("Setup tb_prestasi_siswa error: " . $e->getMessage());
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $nama_siswa = trim($_POST['nama_siswa']);
        $prestasi = trim($_POST['prestasi']);
        $tahun = trim($_POST['tahun']);
        $tingkat = $_POST['tingkat'];

        if (!$nama_siswa || !$prestasi || !$tahun || !in_array($tingkat, ['Kecamatan', 'Kabupaten', 'Provinsi', 'Nasional'])) {
            $error = 'Data tidak lengkap.';
        } else {
            if ($action === 'add') {
                $stmt = $pdo->prepare("INSERT INTO tb_prestasi_siswa (nama_siswa, prestasi, tahun, tingkat) VALUES (?, ?, ?, ?)");
                $stmt->execute([$nama_siswa, $prestasi, $tahun, $tingkat]);
                logActivity($pdo, $_SESSION['username'] ?? 'admin', 'Prestasi Siswa', "Tambah prestasi: $prestasi");
            } else {
                $id = (int)$_POST['id_prestasi'];
                $stmt = $pdo->prepare("UPDATE tb_prestasi_siswa SET nama_siswa=?, prestasi=?, tahun=?, tingkat=? WHERE id_prestasi=?");
                $stmt->execute([$nama_siswa, $prestasi, $tahun, $tingkat, $id]);
                logActivity($pdo, $_SESSION['username'] ?? 'admin', 'Prestasi Siswa', "Edit prestasi ID: $id");
            }
            $s = $action === 'add' ? 'add' : 'edit';
            $loc = strtok($_SERVER['REQUEST_URI'], '?');
            header('Location: ' . $loc . '?tahun=' . urlencode($tahun) . '&success=' . $s);
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id_prestasi'];
        $stmt = $pdo->prepare("DELETE FROM tb_prestasi_siswa WHERE id_prestasi=?");
        $stmt->execute([$id]);
        logActivity($pdo, $_SESSION['username'] ?? 'admin', 'Prestasi Siswa', "Hapus prestasi ID: $id");
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tahun=' . urlencode($_POST['tahun'] ?? '') . '&success=del');
        exit;
    }
}

// Fetch years for filter
$stmt_years = $pdo->query("SELECT DISTINCT tahun FROM tb_prestasi_siswa ORDER BY tahun DESC");
$filter_years = $stmt_years->fetchAll(PDO::FETCH_COLUMN);
$selected_tahun = $_GET['tahun'] ?? '';

// Fetch prestasi data with student photo if matches
$query_sql = "
    SELECT p.*, s.foto AS foto_siswa, k.nama_kelas
    FROM tb_prestasi_siswa p
    LEFT JOIN tb_siswa s ON LOWER(TRIM(s.nama_siswa)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(p.nama_siswa)) COLLATE utf8mb4_unicode_ci
    LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas
";
if ($selected_tahun) {
    $stmt = $pdo->prepare($query_sql . " WHERE p.tahun = ? ORDER BY p.nama_siswa ASC");
    $stmt->execute([$selected_tahun]);
} else {
    $stmt = $pdo->query($query_sql . " ORDER BY p.tahun DESC, p.nama_siswa ASC");
}
$prestasi_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary counts per tingkat
$summary = ['Kecamatan' => 0, 'Kabupaten' => 0, 'Provinsi' => 0, 'Nasional' => 0];
foreach ($prestasi_list as $p) {
    if (isset($summary[$p['tingkat']])) $summary[$p['tingkat']]++;
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<style>
/* Modern Mobile App Style - Kartu Prestasi Siswa */
:root {
    --pres-nasional: #f59e0b;
    --pres-provinsi: #3b82f6;
    --pres-kabupaten: #06b6d4;
    --pres-kecamatan: #64748b;
}

.pres-summary-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}
.pres-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    color: #1e293b;
    box-shadow: 0 1px 3px rgba(15, 23, 42, .04);
}
.pres-chip-count {
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 11px;
    color: #ffffff;
    font-weight: 800;
}
.pres-chip-count.c-total { background: #0f172a; }
.pres-chip-count.c-nasional { background: #f59e0b; }
.pres-chip-count.c-provinsi { background: #3b82f6; }
.pres-chip-count.c-kabupaten { background: #06b6d4; }
.pres-chip-count.c-kecamatan { background: #64748b; }

.pres-search-box {
    position: relative;
    max-width: 320px;
    width: 100%;
}
.pres-search-box input {
    width: 100%;
    padding: 9px 16px 9px 38px;
    border-radius: 999px;
    border: 1.5px solid #cbd5e1;
    font-size: 13px;
    font-weight: 600;
    color: #0f172a;
    background: #ffffff;
    outline: none;
    transition: all .2s ease;
}
.pres-search-box input:focus {
    border-color: #4f46e5;
    box-shadow: 0 0 0 3px rgba(79, 70, 229, .15);
}
.pres-search-box i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #64748b;
    font-size: 13px;
}

/* App Card Layout */
.pres-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    margin-top: 18px;
}
@media (max-width: 767.98px) {
    .pres-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }
}

.pres-app-card {
    position: relative;
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    border-radius: 20px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-shadow: 0 4px 18px rgba(15, 23, 42, .04);
    transition: transform .18s cubic-bezier(0.4, 0, 0.2, 1), box-shadow .18s ease, border-color .18s ease;
}
.pres-app-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 28px rgba(15, 23, 42, .08);
    border-color: #cbd5e1;
}

/* Accent Strip Sesuai Legenda */
.pres-app-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
}
.pres-app-card.tingkat-Nasional::before { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
.pres-app-card.tingkat-Provinsi::before { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
.pres-app-card.tingkat-Kabupaten::before { background: linear-gradient(90deg, #06b6d4, #22d3ee); }
.pres-app-card.tingkat-Kecamatan::before { background: linear-gradient(90deg, #64748b, #94a3b8); }

/* Header Kartu */
.pres-card-top {
    padding: 14px 16px 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    background: #ffffff;
}

/* Badge Tingkat - Warna Sinkron Sempurna Dengan Legenda */
.pres-badge-tingkat {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 800;
    letter-spacing: .2px;
    color: #ffffff !important;
}
.pres-badge-tingkat.tingkat-Nasional { background: #f59e0b; box-shadow: 0 2px 8px rgba(245, 158, 11, .28); }
.pres-badge-tingkat.tingkat-Provinsi { background: #3b82f6; box-shadow: 0 2px 8px rgba(59, 130, 246, .28); }
.pres-badge-tingkat.tingkat-Kabupaten { background: #06b6d4; box-shadow: 0 2px 8px rgba(6, 182, 212, .28); }
.pres-badge-tingkat.tingkat-Kecamatan { background: #64748b; box-shadow: 0 2px 8px rgba(100, 116, 139, .28); }

.pres-pill-meta {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 999px;
    background: #f1f5f9;
    color: #1e293b !important;
    font-size: 11.5px;
    font-weight: 800;
}

/* Card Main Area */
.pres-card-main {
    padding: 0 16px 16px;
    display: flex;
    align-items: center;
    gap: 14px;
}
.pres-avatar-wrap {
    position: relative;
    width: 54px;
    height: 54px;
    flex-shrink: 0;
}
.pres-avatar-img,
.pres-avatar-fallback {
    width: 54px;
    height: 54px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    object-fit: cover;
    box-shadow: 0 4px 12px rgba(15, 23, 42, .08);
}
.pres-avatar-fallback {
    color: #ffffff;
    font-weight: 800;
    font-size: 18px;
    letter-spacing: .5px;
}
.pres-avatar-fallback.tingkat-Nasional { background: linear-gradient(135deg, #f59e0b, #d97706); }
.pres-avatar-fallback.tingkat-Provinsi { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
.pres-avatar-fallback.tingkat-Kabupaten { background: linear-gradient(135deg, #06b6d4, #0e7490); }
.pres-avatar-fallback.tingkat-Kecamatan { background: linear-gradient(135deg, #64748b, #475569); }

.pres-avatar-trophy {
    position: absolute;
    bottom: -3px;
    right: -3px;
    width: 20px;
    height: 20px;
    border-radius: 999px;
    background: #ffffff;
    box-shadow: 0 2px 6px rgba(0,0,0,.15);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
}
.pres-app-card.tingkat-Nasional .pres-avatar-trophy { color: #f59e0b; }
.pres-app-card.tingkat-Provinsi .pres-avatar-trophy { color: #3b82f6; }
.pres-app-card.tingkat-Kabupaten .pres-avatar-trophy { color: #06b6d4; }
.pres-app-card.tingkat-Kecamatan .pres-avatar-trophy { color: #64748b; }

.pres-student-detail {
    min-width: 0;
    flex: 1 1 auto;
}
.pres-student-name {
    font-size: 15px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.3;
    margin-bottom: 2px;
    word-break: break-word;
}
.pres-student-class {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11.5px;
    font-weight: 700;
    color: #64748b;
    margin-bottom: 4px;
}

/* Achievement Highlight Pill */
.pres-achievement-box {
    margin: 0 16px 14px;
    padding: 10px 14px;
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 12px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
}
.pres-trophy-icon {
    font-size: 15px;
    margin-top: 2px;
    flex-shrink: 0;
}
.pres-app-card.tingkat-Nasional .pres-trophy-icon { color: #f59e0b; }
.pres-app-card.tingkat-Provinsi .pres-trophy-icon { color: #3b82f6; }
.pres-app-card.tingkat-Kabupaten .pres-trophy-icon { color: #06b6d4; }
.pres-app-card.tingkat-Kecamatan .pres-trophy-icon { color: #64748b; }

.pres-achievement-text {
    font-size: 13px;
    font-weight: 700;
    color: #1e293b;
    line-height: 1.4;
    word-break: break-word;
}

/* Card Bottom Actions */
.pres-card-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 10px 16px;
    background: #f8fafc;
    border-top: 1px solid #f1f5f9;
}
.pres-index-chip {
    font-size: 11.5px;
    font-weight: 800;
    color: #94a3b8;
}

.pres-app-btns {
    display: flex;
    align-items: center;
    gap: 8px;
}
.pres-app-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    border: 0;
    cursor: pointer;
    transition: all .15s ease;
    text-decoration: none !important;
}
.pres-app-btn:active { transform: scale(0.96); }

.pres-btn-edit-app {
    background: linear-gradient(135deg, #4f46e5, #4338ca);
    color: #ffffff !important;
    box-shadow: 0 2px 8px rgba(79, 70, 229, .25);
}
.pres-btn-edit-app:hover {
    background: linear-gradient(135deg, #4338ca, #3730a3);
    box-shadow: 0 4px 12px rgba(79, 70, 229, .35);
    color: #ffffff !important;
    transform: translateY(-1px);
}

.pres-btn-del-app {
    background: #ffffff;
    color: #ef4444 !important;
    border: 1.5px solid #fecaca !important;
}
.pres-btn-del-app:hover {
    background: #fef2f2;
    border-color: #f87171 !important;
    color: #dc2626 !important;
    transform: translateY(-1px);
}
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><i class="fas fa-trophy mr-2"></i>Data Prestasi Siswa</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4>Daftar Prestasi Siswa</h4>
                    <div class="card-header-action">
                        <button class="btn btn-primary" data-toggle="modal" data-target="#addModal"><i class="fas fa-plus"></i> Tambah Prestasi</button>
                        <?php if (!empty($prestasi_list)): ?>
                        <div class="btn-group btn-pill ml-2 overflow-hidden" style="border-radius: 30px;">
                            <button type="button" class="btn btn-danger px-3" onclick="exportToPDF()" style="background-color: #ff5e5e; border: none; border-top-left-radius: 30px; border-bottom-left-radius: 30px;">
                                <i class="fas fa-file-pdf"></i> PDF
                            </button>
                            <button type="button" class="btn btn-success px-3" onclick="exportToExcel()" style="background-color: #47c363; border: none; border-top-right-radius: 30px; border-bottom-right-radius: 30px;">
                                <i class="fas fa-file-excel"></i> Excel
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3" style="gap: 12px;">
                        <form method="GET" class="d-flex align-items-center flex-wrap" style="gap: 10px; margin: 0;">
                            <label class="mb-0 font-weight-bold" style="color: #0f172a; font-size: 13px;">Tahun:</label>
                            <select name="tahun" class="form-control" style="border-radius: 999px; font-weight: 700; width: auto; min-width: 150px; height: auto; padding: 7px 16px;" onchange="this.form.submit()">
                                <option value="">-- Semua Tahun --</option>
                                <?php foreach ($filter_years as $th): ?>
                                    <option value="<?= htmlspecialchars($th) ?>" <?= $selected_tahun == $th ? 'selected' : '' ?>><?= htmlspecialchars($th) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>

                        <div class="pres-search-box">
                            <i class="fas fa-search"></i>
                            <input type="search" id="pres-search-input" placeholder="Cari siswa atau nama prestasi..." autocomplete="off">
                        </div>
                    </div>

                    <?php if (!empty($prestasi_list)): ?>
                    <div class="pres-summary-chips mb-3">
                        <span class="pres-chip">Total <span class="pres-chip-count c-total" id="pres-total-count"><?= count($prestasi_list) ?></span></span>
                        <span class="pres-chip">Nasional <span class="pres-chip-count c-nasional"><?= $summary['Nasional'] ?></span></span>
                        <span class="pres-chip">Provinsi <span class="pres-chip-count c-provinsi"><?= $summary['Provinsi'] ?></span></span>
                        <span class="pres-chip">Kabupaten <span class="pres-chip-count c-kabupaten"><?= $summary['Kabupaten'] ?></span></span>
                        <span class="pres-chip">Kecamatan <span class="pres-chip-count c-kecamatan"><?= $summary['Kecamatan'] ?></span></span>
                    </div>
                    <?php endif; ?>

                    <?php if (empty($prestasi_list)): ?>
                        <div class="text-center py-5" style="color: #64748b;">
                            <i class="fas fa-trophy fa-3x mb-3 text-muted"></i>
                            <p class="mb-0 font-weight-bold">Belum ada data prestasi untuk filter yang dipilih.</p>
                        </div>
                    <?php else: ?>
                        <div class="pres-grid" id="pres-grid">
                            <?php $no = 1; foreach ($prestasi_list as $p): 
                                $tingkat = $p['tingkat'];
                                $initials = '';
                                $words = preg_split('/\s+/', trim((string)$p['nama_siswa']));
                                foreach (array_slice($words, 0, 2) as $w) {
                                    if ($w !== '') $initials .= strtoupper(substr($w, 0, 1));
                                }
                                if ($initials === '') $initials = 'S';

                                $fallbackHtml = '<div class="pres-avatar-fallback tingkat-' . htmlspecialchars($tingkat) . '">' . htmlspecialchars($initials) . '</div>';
                                $avatarHtml = getPersonAvatarHtml($p['foto_siswa'] ?? '', $fallbackHtml, 'pres-avatar-img');
                            ?>
                            <div class="pres-app-card tingkat-<?= htmlspecialchars($tingkat) ?>">
                                <div>
                                    <div class="pres-card-top">
                                        <span class="pres-badge-tingkat tingkat-<?= htmlspecialchars($tingkat) ?>">
                                            <i class="fas <?= $tingkat === 'Nasional' ? 'fa-crown' : ($tingkat === 'Provinsi' ? 'fa-medal' : ($tingkat === 'Kabupaten' ? 'fa-award' : 'fa-certificate')) ?>"></i>
                                            Tingkat <?= htmlspecialchars($tingkat) ?>
                                        </span>
                                        <span class="pres-pill-meta">
                                            <i class="far fa-calendar-alt text-primary"></i> <?= htmlspecialchars($p['tahun']) ?>
                                        </span>
                                    </div>
                                    <div class="pres-card-main">
                                        <div class="pres-avatar-wrap">
                                            <?= $avatarHtml ?>
                                            <div class="pres-avatar-trophy">
                                                <i class="fas <?= $tingkat === 'Nasional' ? 'fa-crown' : ($tingkat === 'Provinsi' ? 'fa-medal' : ($tingkat === 'Kabupaten' ? 'fa-award' : 'fa-certificate')) ?>"></i>
                                            </div>
                                        </div>
                                        <div class="pres-student-detail">
                                            <div class="pres-student-name"><?= htmlspecialchars($p['nama_siswa']) ?></div>
                                            <?php if (!empty($p['nama_kelas'])): ?>
                                                <div class="pres-student-class">
                                                    <i class="fas fa-graduation-cap text-primary"></i> Kelas <?= htmlspecialchars($p['nama_kelas']) ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="pres-student-class">
                                                    <i class="fas fa-user-graduate text-muted"></i> Prestasi Siswa
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="pres-achievement-box">
                                        <i class="fas fa-trophy pres-trophy-icon"></i>
                                        <div class="pres-achievement-text"><?= htmlspecialchars($p['prestasi']) ?></div>
                                    </div>
                                </div>
                                <div class="pres-card-bottom">
                                    <span class="pres-index-chip">#<?= $no++ ?></span>
                                    <div class="pres-app-btns">
                                        <button class="pres-app-btn pres-btn-edit-app" data-toggle="modal" data-target="#editModal"
                                            data-id="<?= $p['id_prestasi'] ?>"
                                            data-nama="<?= htmlspecialchars($p['nama_siswa'], ENT_QUOTES) ?>"
                                            data-prestasi="<?= htmlspecialchars($p['prestasi'], ENT_QUOTES) ?>"
                                            data-tahun="<?= $p['tahun'] ?>"
                                            data-tingkat="<?= $p['tingkat'] ?>" title="Edit Prestasi">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <form method="POST" class="delete-form m-0" style="display:inline">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id_prestasi" value="<?= $p['id_prestasi'] ?>">
                                            <input type="hidden" name="tahun" value="<?= $selected_tahun ?>">
                                            <button class="pres-app-btn pres-btn-del-app delete-btn" type="button" title="Hapus Prestasi">
                                                <i class="fas fa-trash"></i> Hapus
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Hidden export table -->
                    <?php if (!empty($prestasi_list)): ?>
                    <div id="exportTableContainer" style="display:none">
                        <table border="1">
                            <thead>
                                <tr><th colspan="5" style="text-align:center;font-size:16px;font-weight:bold;">DATA PRESTASI SISWA</th></tr>
                                <tr><th>No</th><th>Nama Siswa</th><th>Prestasi</th><th>Tahun</th><th>Tingkat</th></tr>
                            </thead>
                            <tbody>
                                <?php $no2 = 1; foreach ($prestasi_list as $p): ?>
                                <tr><td><?= $no2++ ?></td><td><?= htmlspecialchars($p['nama_siswa']) ?></td><td><?= htmlspecialchars($p['prestasi']) ?></td><td><?= $p['tahun'] ?></td><td><?= $p['tingkat'] ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <form id="exportForm" method="GET" action="export_prestasi_siswa_pdf.php" target="_blank">
                        <input type="hidden" name="tahun" value="<?= $selected_tahun ?>">
                        <input type="hidden" name="table_data" id="table_data">
                        <input type="hidden" name="report_title" value="DATA PRESTASI SISWA">
                        <input type="hidden" name="filename" value="data_prestasi_siswa_<?= $selected_tahun ?>">
                        <input type="hidden" name="tahun_export" value="<?= $selected_tahun ?>">
                        <input type="hidden" name="session_type" value="admin">
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Modal Add -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Tambah Prestasi</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label>Nama</label>
                        <input type="text" name="nama_siswa" class="form-control" placeholder="Nama siswa/kelompok" required>
                    </div>
                    <div class="form-group">
                        <label>Prestasi</label>
                        <input type="text" name="prestasi" class="form-control" placeholder="Contoh: Juara 1 OSN Matematika" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Tahun</label>
                            <input type="text" name="tahun" class="form-control" placeholder="2026" maxlength="4" pattern="\d{4}" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Tingkat</label>
                            <select name="tingkat" class="form-control" required>
                                <option value="Kecamatan">Kecamatan</option>
                                <option value="Kabupaten">Kabupaten</option>
                                <option value="Provinsi">Provinsi</option>
                                <option value="Nasional">Nasional</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Edit Prestasi</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id_prestasi" id="edit_id">
                    <div class="form-group">
                        <label>Nama</label>
                        <input type="text" name="nama_siswa" id="edit_nama" class="form-control" placeholder="Nama siswa/kelompok" required>
                    </div>
                    <div class="form-group">
                        <label>Prestasi</label>
                        <input type="text" name="prestasi" id="edit_prestasi" class="form-control" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Tahun</label>
                            <input type="text" name="tahun" id="edit_tahun" class="form-control" maxlength="4" pattern="\d{4}" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Tingkat</label>
                            <select name="tingkat" id="edit_tingkat" class="form-control" required>
                                <option value="Kecamatan">Kecamatan</option>
                                <option value="Kabupaten">Kabupaten</option>
                                <option value="Provinsi">Provinsi</option>
                                <option value="Nasional">Nasional</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php
$js_page[] = "
$(document).ready(function() {
    $('#editModal').on('show.bs.modal', function(e) {
        var btn = $(e.relatedTarget);
        $('#edit_id').val(btn.data('id'));
        $('#edit_nama').val(btn.data('nama'));
        $('#edit_prestasi').val(btn.data('prestasi'));
        $('#edit_tahun').val(btn.data('tahun'));
        $('#edit_tingkat').val(btn.data('tingkat'));
    });

    $(document).on('click', '.delete-btn', function() {
        var form = $(this).closest('form');
        Swal.fire({
            title: 'Hapus Prestasi?',
            text: 'Data yang dihapus tidak bisa dikembalikan.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then(function(result) {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });

    // Live search filter kartu prestasi
    $('#pres-search-input').on('input', function() {
        var q = $(this).val().toLowerCase().trim();
        var visible = 0;
        $('#pres-grid .pres-app-card').each(function() {
            var text = $(this).text().toLowerCase();
            var match = (q === '' || text.indexOf(q) !== -1);
            $(this).toggle(match);
            if (match) visible++;
        });
        $('#pres-total-count').text(visible);
    });
});
function exportToPDF() {
    document.getElementById('exportForm').submit();
}
function exportToExcel() {
    var html = document.getElementById('exportTableContainer').innerHTML;
    document.getElementById('table_data').value = html;
    var f = document.getElementById('exportForm');
    f.action = 'export_prestasi_siswa_excel.php';
    f.method = 'POST';
    f.submit();
}
";
include '../templates/footer.php';
?>
