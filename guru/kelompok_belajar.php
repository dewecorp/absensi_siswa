<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/kelompok_belajar_helper.php';

ensure_kelompok_belajar_schema($pdo);

if (!isAuthorized(['wali', 'admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$session_type = (string)($_GET['session_type'] ?? '');
$is_admin = ($user_level === 'admin') || ($session_type === 'admin');
$is_kepala = ($user_level === 'kepala_madrasah') || ($session_type === 'kepala_madrasah');
$is_admin_or_kepala = $is_admin || $is_kepala;
$can_crud = in_array($user_level, ['admin', 'wali'], true) && $session_type !== 'kepala_madrasah';
$dash_url = $is_admin ? '../admin/dashboard.php' : ($is_kepala ? '../kepala/dashboard.php' : '../wali/dashboard.php');

$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

// Ambil daftar kelas:
// - Admin/Kepala: semua kelas
// - Wali: kelas yang diampu sebagai wali kelas (fallback ke kelas yang diajar)
$all_kelas_master = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY id_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
if ($is_admin_or_kepala) {
    $daftar_kelas = $all_kelas_master;
} else {
    $stmtWali = $pdo->prepare("SELECT id_kelas, nama_kelas FROM tb_kelas WHERE wali_kelas = ? OR wali_kelas = (SELECT nama_guru FROM tb_guru WHERE id_guru = ?) ORDER BY id_kelas ASC");
    $stmtWali->execute([$guru_id, $guru_id]);
    $daftar_kelas = $stmtWali->fetchAll(PDO::FETCH_ASSOC);
    if (empty($daftar_kelas)) {
        $daftar_kelas = getGuruTaughtClasses($pdo, $guru_id);
    }
    if (empty($daftar_kelas)) {
        $daftar_kelas = $all_kelas_master;
    }
}
$allowed_kelas_ids = array_map('intval', array_column($daftar_kelas, 'id_kelas'));

// Filter
$f_kelas = isset($_GET['kelas']) && $_GET['kelas'] !== '' ? (int)$_GET['kelas'] : null;
$f_search = trim((string)($_GET['q'] ?? ''));

$buildRedirectUrl = static function (?int $kelasId = null) use ($session_type, $f_kelas): string {
    $q = [];
    if ($session_type !== '') {
        $q['session_type'] = $session_type;
    }
    $targetKelas = $kelasId ?: $f_kelas;
    if ($targetKelas) {
        $q['kelas'] = $targetKelas;
    }
    return 'kelompok_belajar.php' . (!empty($q) ? '?' . http_build_query($q) : '');
};

// Handle CRUD POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_crud) {
        $_SESSION['swal_toast'] = [
            'type' => 'error',
            'title' => 'Akses Ditolak',
            'text' => 'Hanya Wali Kelas dan Admin yang berhak mengelola kelompok belajar.'
        ];
        header("Location: " . $buildRedirectUrl());
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id_kelompok   = (int)($_POST['id_kelompok'] ?? 0);
        $nama_kelompok = trim((string)($_POST['nama_kelompok'] ?? ''));
        $id_kelas      = (int)($_POST['id_kelas'] ?? 0) ?: null;
        $id_ketua      = (int)($_POST['id_ketua'] ?? 0) ?: null;
        $keterangan    = trim((string)($_POST['keterangan'] ?? ''));
        $anggota_raw   = $_POST['anggota'] ?? [];
        if (!is_array($anggota_raw)) {
            $anggota_raw = [];
        }
        $anggota_ids = [];
        foreach ($anggota_raw as $a) {
            $aid = (int)$a;
            if ($aid > 0) $anggota_ids[] = $aid;
        }
        $anggota_ids = array_values(array_unique($anggota_ids));

        // Jika ketua dipilih tapi belum ada di list anggota, otomatis tambahkan
        if ($id_ketua && !in_array($id_ketua, $anggota_ids, true)) {
            $anggota_ids[] = $id_ketua;
        }

        // Validasi akses kelas untuk guru (hanya boleh pilih kelas yang diajar)
        if (!$is_admin_or_kepala && $id_kelas && !in_array($id_kelas, $allowed_kelas_ids, true)) {
            $_SESSION['swal_toast'] = [
                'type' => 'error',
                'title' => 'Akses Ditolak',
                'text' => 'Anda tidak memiliki hak akses untuk kelas yang dipilih.'
            ];
            header("Location: " . $buildRedirectUrl());
            exit;
        }

        if ($nama_kelompok === '') {
            $_SESSION['swal_toast'] = [
                'type' => 'warning',
                'title' => 'Perhatian',
                'text' => 'Nama kelompok belajar wajib diisi.'
            ];
        } else {
            try {
                $pdo->beginTransaction();
                if ($action === 'tambah') {
                    $st = $pdo->prepare("
                        INSERT INTO tb_kelompok_belajar (nama_kelompok, id_kelas, id_ketua, id_guru, keterangan)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $st->execute([$nama_kelompok, $id_kelas, $id_ketua, $guru_id ?: null, $keterangan]);
                    $id_kelompok = (int)$pdo->lastInsertId();
                    $action_text = 'berhasil ditambahkan';
                } else {
                    $st = $pdo->prepare("
                        UPDATE tb_kelompok_belajar
                        SET nama_kelompok = ?, id_kelas = ?, id_ketua = ?, keterangan = ?
                        WHERE id_kelompok = ?
                    ");
                    $st->execute([$nama_kelompok, $id_kelas, $id_ketua, $keterangan, $id_kelompok]);
                    $action_text = 'berhasil diperbarui';

                    // Hapus anggota lama
                    $del = $pdo->prepare("DELETE FROM tb_anggota_kelompok_belajar WHERE id_kelompok = ?");
                    $del->execute([$id_kelompok]);
                }

                // Masukkan anggota baru
                if (!empty($anggota_ids)) {
                    $ins = $pdo->prepare("INSERT INTO tb_anggota_kelompok_belajar (id_kelompok, id_siswa) VALUES (?, ?)");
                    foreach ($anggota_ids as $sid) {
                        $ins->execute([$id_kelompok, $sid]);
                    }
                }

                $pdo->commit();
                $_SESSION['swal_toast'] = [
                    'type' => 'success',
                    'title' => 'Berhasil!',
                    'text' => "Kelompok belajar \"$nama_kelompok\" $action_text."
                ];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['swal_toast'] = [
                    'type' => 'error',
                    'title' => 'Gagal Menyimpan',
                    'text' => $e->getMessage()
                ];
            }
        }
        header("Location: " . $buildRedirectUrl($id_kelas));
        exit;
    } elseif ($action === 'hapus') {
        $id_kelompok = (int)($_POST['id_kelompok'] ?? 0);
        try {
            $st = $pdo->prepare("DELETE FROM tb_kelompok_belajar WHERE id_kelompok = ?");
            $st->execute([$id_kelompok]);
            $_SESSION['swal_toast'] = [
                'type' => 'success',
                'title' => 'Berhasil Dihapus!',
                'text' => 'Kelompok belajar telah dihapus permanen.'
            ];
        } catch (Throwable $e) {
            $_SESSION['swal_toast'] = [
                'type' => 'error',
                'title' => 'Gagal Menghapus',
                'text' => $e->getMessage()
            ];
        }
        header("Location: " . $buildRedirectUrl());
        exit;
    }
}

// Flash toast via session
$swal_toast = $_SESSION['swal_toast'] ?? null;
unset($_SESSION['swal_toast']);

// Siswa hanya untuk kelas yang diajar guru (atau semua bila admin/kepala)
if ($is_admin_or_kepala) {
    $all_siswa = $pdo->query("SELECT id_siswa, nama_siswa, id_kelas FROM tb_siswa ORDER BY nama_siswa ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    if (!empty($allowed_kelas_ids)) {
        $ph = implode(',', array_fill(0, count($allowed_kelas_ids), '?'));
        $stS = $pdo->prepare("SELECT id_siswa, nama_siswa, id_kelas FROM tb_siswa WHERE id_kelas IN ($ph) ORDER BY nama_siswa ASC");
        $stS->execute($allowed_kelas_ids);
        $all_siswa = $stS->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $all_siswa = [];
    }
}

// Query data kelompok
// Untuk Admin/Kepala: wajib pilih kelas terlebih dahulu. Jika belum memilih kelas, data dikosongkan.
if ($is_admin_or_kepala && !$f_kelas) {
    $kelompok_list = [];
} else {
    $sql = "
        SELECT 
            kb.id_kelompok,
            kb.nama_kelompok,
            kb.id_kelas,
            kb.id_ketua,
            kb.keterangan,
            kb.created_at,
            k.nama_kelas,
            sk.nama_siswa AS nama_ketua,
            sk.foto AS foto_ketua
        FROM tb_kelompok_belajar kb
        LEFT JOIN tb_kelas k ON kb.id_kelas = k.id_kelas
        LEFT JOIN tb_siswa sk ON kb.id_ketua = sk.id_siswa
        WHERE 1=1
    ";
    $params = [];

    // Pembatasan data untuk Wali: hanya kelompok dari kelas yang diampu atau yang dibuat
    if (!$is_admin_or_kepala) {
        if (!empty($allowed_kelas_ids)) {
            $ph = implode(',', array_fill(0, count($allowed_kelas_ids), '?'));
            $sql .= " AND (kb.id_kelas IN ($ph) OR kb.id_guru = ?)";
            $params = array_merge($params, $allowed_kelas_ids, [$guru_id]);
        } else {
            $sql .= " AND kb.id_guru = ?";
            $params[] = $guru_id;
        }
    }

    if ($f_kelas) {
        $sql .= " AND kb.id_kelas = ?";
        $params[] = $f_kelas;
    }
    if ($f_search !== '') {
        $sql .= " AND (kb.nama_kelompok LIKE ? OR sk.nama_siswa LIKE ?)";
        $params[] = "%$f_search%";
        $params[] = "%$f_search%";
    }
    $sql .= " ORDER BY kb.id_kelompok DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $kelompok_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Ambil anggota per kelompok
$anggota_map = [];
if (!empty($kelompok_list)) {
    $ids = array_column($kelompok_list, 'id_kelompok');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stA = $pdo->prepare("
        SELECT 
            akb.id_kelompok,
            s.id_siswa,
            s.nama_siswa,
            s.nisn,
            s.jenis_kelamin,
            s.foto
        FROM tb_anggota_kelompok_belajar akb
        JOIN tb_siswa s ON akb.id_siswa = s.id_siswa
        WHERE akb.id_kelompok IN ($placeholders)
        ORDER BY s.nama_siswa ASC
    ");
    $stA->execute($ids);
    foreach ($stA->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $anggota_map[$row['id_kelompok']][] = $row;
    }
}

$page_title = 'Kelompok Belajar';
$css_libs = [
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
];
$js_libs = [
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
    'https://cdn.jsdelivr.net/npm/sweetalert2@11',
];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<style>
/* Grid Kartu Kelompok Belajar */
.kb-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
}
.kb-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.05);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    transition: transform .2s ease, box-shadow .2s ease;
    position: relative;
}
.kb-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(37, 99, 235, 0.12);
    border-color: #cbd5e1;
}
.kb-card-header {
    padding: 16px 18px 12px;
    background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}
.kb-card-no {
    width: 32px;
    height: 32px;
    min-width: 32px;
    border-radius: 8px;
    background: #eef2ff;
    color: #4f46e5;
    font-weight: 700;
    font-size: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.kb-card-title {
    font-size: 16px;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.3;
    margin: 0;
}
.kb-card-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 6px;
}
.kb-card-body {
    padding: 16px 18px;
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.kb-section-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #64748b;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.kb-ketua-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 10px 12px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.kb-ketua-avatar {
    width: 38px;
    height: 38px;
    min-width: 38px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    color: #ffffff;
    font-weight: 700;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
}
.kb-ketua-info {
    flex: 1 1 auto;
    min-width: 0;
}
.kb-ketua-name {
    font-size: 14px;
    font-weight: 700;
    color: #1e293b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.kb-members-wrapper {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    max-height: 140px;
    overflow-y: auto;
    padding-right: 2px;
}
.kb-member-chip {
    font-size: 12px;
    padding: 4px 9px;
    border-radius: 8px;
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #e2e8f0;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    line-height: 1.3;
}
.kb-member-chip.is-leader {
    background: #eef2ff;
    color: #3730a3;
    border-color: #c7d2fe;
    font-weight: 600;
}
.kb-card-footer {
    padding: 12px 18px;
    background: #fafafa;
    border-top: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.kb-card-desc {
    font-size: 12px;
    color: #64748b;
    font-style: italic;
    background: #fffbeb;
    border-left: 3px solid #f59e0b;
    padding: 6px 10px;
    border-radius: 4px;
    margin-top: 4px;
}

/* Penyesuaian Select2 Multi-Select */
.select2-container--default .select2-selection--multiple {
    border: 1px solid #dcdfe6 !important;
    border-radius: 8px !important;
    padding: 4px 6px !important;
    min-height: 42px !important;
}
.select2-container--default.select2-container--focus .select2-selection--multiple {
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15) !important;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice {
    background-color: #3b82f6 !important;
    border: none !important;
    color: #fff !important;
    border-radius: 6px !important;
    padding: 3px 8px !important;
    font-size: 13px !important;
    margin: 2px 4px 2px 0 !important;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
    color: #fff !important;
    margin-right: 6px !important;
    font-weight: bold !important;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
    color: #fee2e2 !important;
}
.select2-container--default .select2-selection--single {
    border: 1px solid #dcdfe6 !important;
    border-radius: 8px !important;
    height: 42px !important;
    padding: 6px 12px !important;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 28px !important;
    color: #334155 !important;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 40px !important;
}
.select2-dropdown {
    border: 1px solid #e2e8f0 !important;
    border-radius: 8px !important;
    box-shadow: 0 8px 24px rgba(0,0,0,0.12) !important;
    z-index: 9999 !important;
}
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header d-flex justify-content-between align-items-center">
            <div>
                <h1>Kelompok Belajar</h1>
                <div class="section-header-breadcrumb">
                    <div class="breadcrumb-item"><a href="<?= htmlspecialchars($dash_url) ?>">Dashboard</a></div>
                    <div class="breadcrumb-item">Pengelolaan Kelas</div>
                    <div class="breadcrumb-item active">Kelompok Belajar</div>
                </div>
            </div>
            <?php if ($can_crud): ?>
            <div>
                <button type="button" class="btn btn-primary" onclick="bukaModalTambah()">
                    <i class="fas fa-plus mr-1"></i> Tambah Kelompok
                </button>
            </div>
            <?php endif; ?>
        </div>

        <div class="section-body">
            <!-- Filter & Search Card -->
            <div class="card mb-4" style="border-radius:14px;border:none;box-shadow:0 4px 18px rgba(0,0,0,0.04);">
                <div class="card-body p-3">
                    <form method="GET" class="form-row align-items-center">
                        <?php if ($session_type !== ''): ?>
                            <input type="hidden" name="session_type" value="<?= htmlspecialchars($session_type) ?>">
                        <?php endif; ?>
                        <div class="col-md-4 col-sm-6 mb-2 mb-md-0">
                            <label class="sr-only">Kelas</label>
                            <select name="kelas" class="form-control" onchange="this.form.submit()">
                                <option value=""><?= $is_admin_or_kepala ? '-- Pilih Kelas --' : 'Semua Kelas Diampu' ?></option>
                                <?php foreach ($daftar_kelas as $k): ?>
                                    <option value="<?= (int)$k['id_kelas'] ?>" <?= $f_kelas === (int)$k['id_kelas'] ? 'selected' : '' ?>>
                                        Kelas <?= htmlspecialchars($k['nama_kelas']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5 col-sm-6 mb-2 mb-md-0">
                            <label class="sr-only">Cari</label>
                            <input type="text" name="q" class="form-control" placeholder="Cari nama kelompok atau ketua..." value="<?= htmlspecialchars($f_search) ?>">
                        </div>
                        <div class="col-md-3 col-sm-12 text-md-right">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-search mr-1"></i> Filter</button>
                            <?php if ($f_kelas || $f_search !== ''): ?>
                                <a href="kelompok_belajar.php<?= $session_type ? '?session_type=' . urlencode($session_type) : '' ?>" class="btn btn-outline-secondary ml-1" title="Reset filter"><i class="fas fa-redo"></i></a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Daftar Kartu Kelompok Belajar -->
            <?php if (!$is_admin_or_kepala || $f_kelas): ?>
            <?php if (empty($kelompok_list)): ?>
                <div class="card text-center py-5" style="border-radius:16px;border:none;box-shadow:0 4px 18px rgba(0,0,0,0.04);">
                    <div class="card-body">
                        <i class="fas fa-users-slash fa-3x text-secondary mb-3" style="opacity:0.35;"></i>
                        <h5 class="text-dark font-weight-bold">Belum Ada Kelompok Belajar</h5>
                        <?php if ($can_crud): ?>
                            <p class="text-muted mb-3">Klik tombol di bawah untuk membuat kelompok belajar baru.</p>
                            <button type="button" class="btn btn-primary" onclick="bukaModalTambah()">
                                <i class="fas fa-plus mr-1"></i> Buat Kelompok Sekarang
                            </button>
                        <?php else: ?>
                            <p class="text-muted mb-0">Belum ada data kelompok belajar yang tercatat.</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="kb-grid">
                    <?php foreach ($kelompok_list as $i => $row): ?>
                        <?php 
                        $kid = (int)$row['id_kelompok'];
                        $anggota = $anggota_map[$kid] ?? [];
                        $anggota_ids_json = json_encode(array_map('intval', array_column($anggota, 'id_siswa')));
                        ?>
                        <div class="kb-card">
                            <!-- Card Header: No + Nama Kelompok -->
                            <div class="kb-card-header">
                                <div class="d-flex align-items-start" style="gap:10px;min-width:0;flex:1 1 auto;">
                                    <div class="kb-card-no">#<?= $i + 1 ?></div>
                                    <div style="min-width:0;flex:1 1 auto;">
                                        <h5 class="kb-card-title"><?= htmlspecialchars($row['nama_kelompok']) ?></h5>
                                        <div class="kb-card-meta">
                                            <?php if (!empty($row['nama_kelas'])): ?>
                                                <span class="badge badge-primary badge-pill px-2 py-1" style="font-size:11px;">
                                                    <i class="fas fa-graduation-cap mr-1"></i>Kelas <?= htmlspecialchars($row['nama_kelas']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Card Body: Ketua + Anggota + Catatan -->
                            <div class="kb-card-body">
                                <!-- Ketua -->
                                <div>
                                    <div class="kb-section-label">
                                        <i class="fas fa-crown text-warning"></i> Ketua Kelompok
                                    </div>
                                    <div class="kb-ketua-box">
                                        <?php if (!empty($row['nama_ketua'])): ?>
                                            <div class="kb-ketua-avatar">
                                                <?= htmlspecialchars(strtoupper(substr($row['nama_ketua'], 0, 1))) ?>
                                            </div>
                                            <div class="kb-ketua-info">
                                                <div class="kb-ketua-name"><?= htmlspecialchars($row['nama_ketua']) ?></div>
                                                <small class="text-success font-weight-bold"><i class="fas fa-check-circle mr-1"></i>Ketua Terpilih</small>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small font-italic">- Belum ada ketua -</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Anggota -->
                                <div>
                                    <div class="kb-section-label d-flex justify-content-between align-items-center">
                                        <span><i class="fas fa-user-friends text-primary"></i> Anggota Kelompok</span>
                                        <span class="badge badge-light border text-dark font-weight-bold" style="font-size:10px;">
                                            <?= count($anggota) ?> Siswa
                                        </span>
                                    </div>
                                    <?php if (!empty($anggota)): ?>
                                        <div class="kb-members-wrapper">
                                            <?php foreach ($anggota as $a): ?>
                                                <?php $is_leader = ((int)$a['id_siswa'] === (int)$row['id_ketua']); ?>
                                                <span class="kb-member-chip <?= $is_leader ? 'is-leader' : '' ?>" title="<?= htmlspecialchars($a['nama_siswa']) ?>">
                                                    <?php if ($is_leader): ?>
                                                        <i class="fas fa-crown text-warning" style="font-size:9px;"></i>
                                                    <?php else: ?>
                                                        <i class="fas fa-user text-muted" style="font-size:9px;"></i>
                                                    <?php endif; ?>
                                                    <?= htmlspecialchars($a['nama_siswa']) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-muted small font-italic p-2 bg-light rounded text-center">
                                            - Belum ada anggota -
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($row['keterangan'])): ?>
                                    <div class="kb-card-desc">
                                        <i class="fas fa-sticky-note mr-1 text-warning"></i>
                                        <?= htmlspecialchars($row['keterangan']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Card Footer: Aksi -->
                            <div class="kb-card-footer">
                                <small class="text-muted">
                                    <i class="far fa-clock mr-1"></i><?= date('d/m/Y', strtotime($row['created_at'])) ?>
                                </small>
                                <?php if ($can_crud): ?>
                                <div class="btn-group" role="group">
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            title="Edit Kelompok"
                                            onclick='bukaModalEdit(<?= json_encode($row) ?>, <?= $anggota_ids_json ?>)'>
                                        <i class="fas fa-edit mr-1"></i> Edit
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            title="Hapus Kelompok"
                                            onclick="konfirmasiHapus(<?= $kid ?>, <?= json_encode($row['nama_kelompok']) ?>)">
                                        <i class="fas fa-trash-alt mr-1"></i> Hapus
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- Modal Form Kelompok Belajar -->
<div class="modal fade" id="modalKelompok" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius:16px;border:none;box-shadow:0 10px 30px rgba(0,0,0,0.15);overflow:hidden;">
            <form method="POST" id="formKelompok">
                <input type="hidden" name="action" id="form_action" value="tambah">
                <input type="hidden" name="id_kelompok" id="form_id_kelompok" value="0">
                <div class="modal-header bg-primary text-white py-3">
                    <h5 class="modal-title font-weight-bold" id="modalTitle">
                        <i class="fas fa-users mr-2"></i> Tambah Kelompok Belajar
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-4">
                    <div class="form-group">
                        <label class="font-weight-bold">Nama Kelompok <span class="text-danger">*</span></label>
                        <input type="text" name="nama_kelompok" id="inp_nama_kelompok" class="form-control" placeholder="Contoh: Kelompok 1 - Garuda" required>
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold">Kelas</label>
                        <select name="id_kelas" id="inp_kelas" class="form-control" onchange="muatSiswaBerdasarkanKelas()">
                            <option value="">-- Pilih Kelas --</option>
                            <?php foreach ($daftar_kelas as $k): ?>
                                <option value="<?= (int)$k['id_kelas'] ?>">Kelas <?= htmlspecialchars($k['nama_kelas']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted"><?= $is_admin_or_kepala ? 'Pilih kelas untuk memfilter daftar siswa.' : 'Menampilkan kelas yang Anda ampu sebagai wali kelas.' ?></small>
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold">Ketua Kelompok</label>
                        <select name="id_ketua" id="inp_ketua" class="form-control" style="width:100%;">
                            <option value="">-- Pilih Ketua Kelompok --</option>
                        </select>
                        <small class="text-muted">Ketua otomatis masuk ke daftar anggota kelompok.</small>
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold">Anggota Kelompok <span class="badge badge-info ml-1">Multi-Select</span></label>
                        <select name="anggota[]" id="inp_anggota" class="form-control" multiple="multiple" style="width:100%;">
                        </select>
                        <small class="text-muted">Bisa memilih lebih dari satu siswa sekaligus.</small>
                    </div>

                    <div class="form-group mb-0">
                        <label class="font-weight-bold">Keterangan / Catatan (Opsional)</label>
                        <textarea name="keterangan" id="inp_keterangan" class="form-control" rows="2" placeholder="Tuliskan catatan tambahan mengenai kelompok belajar ini..."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="btnSimpan">
                        <i class="fas fa-save mr-1"></i> Simpan Kelompok
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Form tersembunyi untuk hapus -->
<form method="POST" id="formHapus" style="display:none;">
    <input type="hidden" name="action" value="hapus">
    <input type="hidden" name="id_kelompok" id="hapus_id_kelompok" value="0">
</form>

<?php include '../templates/footer.php'; ?>

<script>
var allSiswaList = <?= json_encode($all_siswa) ?>;

function initSelect2() {
    if (!$.fn || !$.fn.select2) return;
    
    $('#inp_ketua').select2({
        dropdownParent: $('#modalKelompok'),
        width: '100%',
        placeholder: '-- Pilih Ketua Kelompok --',
        allowClear: true
    });

    $('#inp_anggota').select2({
        dropdownParent: $('#modalKelompok'),
        width: '100%',
        placeholder: 'Pilih siswa sebagai anggota kelompok...'
    });
}

function muatSiswaBerdasarkanKelas(selectedKetua, selectedAnggota) {
    var idKelas = $('#inp_kelas').val();
    var filtered = allSiswaList;
    if (idKelas) {
        filtered = allSiswaList.filter(function(s) {
            return String(s.id_kelas) === String(idKelas);
        });
    }

    // Update opsi Ketua
    var $ketua = $('#inp_ketua');
    $ketua.empty().append('<option value="">-- Pilih Ketua Kelompok --</option>');
    filtered.forEach(function(s) {
        $ketua.append($('<option>', {
            value: s.id_siswa,
            text: s.nama_siswa
        }));
    });
    if (selectedKetua) {
        $ketua.val(String(selectedKetua));
    }
    $ketua.trigger('change');

    // Update opsi Anggota
    var $anggota = $('#inp_anggota');
    $anggota.empty();
    filtered.forEach(function(s) {
        $anggota.append($('<option>', {
            value: s.id_siswa,
            text: s.nama_siswa
        }));
    });
    if (selectedAnggota && Array.isArray(selectedAnggota)) {
        $anggota.val(selectedAnggota.map(String));
    }
    $anggota.trigger('change');
}

var defaultKelasId = <?= $f_kelas ? json_encode((string)$f_kelas) : (count($daftar_kelas) === 1 ? json_encode((string)$daftar_kelas[0]['id_kelas']) : '""') ?>;

function bukaModalTambah() {
    $('#form_action').val('tambah');
    $('#form_id_kelompok').val('0');
    $('#modalTitle').html('<i class="fas fa-users mr-2"></i> Tambah Kelompok Belajar');
    $('#btnSimpan').html('<i class="fas fa-save mr-1"></i> Simpan Kelompok');
    
    $('#inp_nama_kelompok').val('');
    $('#inp_kelas').val(defaultKelasId);
    $('#inp_keterangan').val('');
    
    muatSiswaBerdasarkanKelas();
    $('#modalKelompok').modal('show');
}

function bukaModalEdit(data, anggotaIds) {
    $('#form_action').val('edit');
    $('#form_id_kelompok').val(data.id_kelompok);
    $('#modalTitle').html('<i class="fas fa-edit mr-2"></i> Edit Kelompok Belajar');
    $('#btnSimpan').html('<i class="fas fa-save mr-1"></i> Perbarui Kelompok');
    
    $('#inp_nama_kelompok').val(data.nama_kelompok || '');
    $('#inp_kelas').val(data.id_kelas || '');
    $('#inp_keterangan').val(data.keterangan || '');
    
    muatSiswaBerdasarkanKelas(data.id_ketua, anggotaIds);
    $('#modalKelompok').modal('show');
}

function konfirmasiHapus(id, nama) {
    Swal.fire({
        title: 'Hapus Kelompok?',
        text: 'Kelompok "' + nama + '" akan dihapus secara permanen.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: '<i class="fas fa-trash-alt mr-1"></i> Ya, Hapus!',
        cancelButtonText: 'Batal'
    }).then(function(res) {
        if (res.isConfirmed) {
            $('#hapus_id_kelompok').val(id);
            $('#formHapus').submit();
        }
    });
}

$(document).ready(function() {
    initSelect2();

    <?php if ($swal_toast): ?>
    Swal.fire({
        icon: <?= json_encode($swal_toast['type']) ?>,
        title: <?= json_encode($swal_toast['title']) ?>,
        text: <?= json_encode($swal_toast['text']) ?>,
        timer: 2500,
        showConfirmButton: false
    });
    <?php endif; ?>
});
</script>
