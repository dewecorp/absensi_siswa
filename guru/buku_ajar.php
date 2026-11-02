<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['guru', 'wali', 'admin', 'kepala_madrasah', 'tata_usaha'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$session_type = $_GET['session_type'] ?? '';
$effective_level = $session_type ?: $user_level;

$is_admin_or_viewer = in_array($effective_level, ['admin', 'kepala_madrasah', 'tata_usaha'], true);

// Deteksi wali: wali kelas bisa lihat semua buku di kelasnya
$is_wali = ($effective_level === 'wali');
$wali_kelas_id = null;
if ($is_wali && $guru_id > 0) {
    $nama_guru = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
    $nama_guru->execute([$guru_id]);
    $nama_guru_val = $nama_guru->fetchColumn();
    if ($nama_guru_val) {
        $stmt_wali = $pdo->prepare("SELECT id_kelas FROM tb_kelas WHERE wali_kelas = ?");
        $stmt_wali->execute([$nama_guru_val]);
        $wali_kelas_id = $stmt_wali->fetchColumn() ?: null;
    }
}

$can_crud = !$is_admin_or_viewer;

$school_profile = getSchoolProfile($pdo);
$tahun_ajaran_aktif = $school_profile['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1);
$semester_aktif = $school_profile['semester'] ?? 'Semester 1';

$message = null;

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_crud) {
        $message = ['type' => 'danger', 'text' => 'Anda tidak memiliki hak akses untuk mengubah data ini.'];
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'tambah' || $action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $judul = trim((string)($_POST['judul'] ?? ''));
            $penyusun = trim((string)($_POST['penyusun'] ?? ''));
            $jenis_buku = in_array($_POST['jenis_buku'] ?? '', ['Digital', 'Cetak'], true) ? $_POST['jenis_buku'] : 'Cetak';
            $id_mapel = (int)($_POST['id_mapel'] ?? 0) ?: null;
            $id_kelas = (int)($_POST['id_kelas'] ?? 0) ?: null;
            $semester = trim((string)($_POST['semester'] ?? $semester_aktif));
            $tahun_ajaran = trim((string)($_POST['tahun_ajaran'] ?? $tahun_ajaran_aktif));
            $status = in_array($_POST['status'] ?? '', ['Draft', 'Aktif', 'Arsip'], true) ? $_POST['status'] : 'Aktif';

            if ($judul === '') {
                $message = ['type' => 'warning', 'text' => 'Judul buku ajar wajib diisi.'];
            } elseif ($penyusun === '') {
                $message = ['type' => 'warning', 'text' => 'Nama penyusun wajib diisi.'];
            } else {
                try {
                    if ($action === 'tambah') {
                        $stmt = $pdo->prepare("
                            INSERT INTO tb_buku_ajar (
                                id_guru, judul, penyusun, jenis_buku, id_mapel, id_kelas,
                                semester, tahun_ajaran, status
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $guru_id, $judul, $penyusun, $jenis_buku, $id_mapel, $id_kelas,
                            $semester, $tahun_ajaran, $status
                        ]);
                        $message = ['type' => 'success', 'text' => 'Buku ajar berhasil ditambahkan.'];
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE tb_buku_ajar SET
                                judul = ?, penyusun = ?, jenis_buku = ?, id_mapel = ?,
                                id_kelas = ?, semester = ?, tahun_ajaran = ?, status = ?
                            WHERE id = ? AND id_guru = ?
                        ");
                        $stmt->execute([
                            $judul, $penyusun, $jenis_buku, $id_mapel,
                            $id_kelas, $semester, $tahun_ajaran, $status,
                            $id, $guru_id
                        ]);
                        $message = ['type' => 'success', 'text' => 'Buku ajar berhasil diperbarui.'];
                    }
                } catch (Exception $e) {
                    $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
                }
            }
        } elseif ($action === 'hapus') {
            $id = (int)($_POST['id'] ?? 0);
            try {
                $pdo->prepare("DELETE FROM tb_buku_ajar WHERE id = ? AND id_guru = ?")->execute([$id, $guru_id]);
                $message = ['type' => 'success', 'text' => 'Buku ajar berhasil dihapus.'];
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
            }
        }
    }
}

// Master lists
$all_mapel_modal = $pdo->query("
    SELECT id_mapel, nama_mapel FROM tb_mata_pelajaran
    WHERE (jenis_mapel IS NULL OR jenis_mapel = 'Akademik')
      AND nama_mapel NOT LIKE '%Asmaul Husna%'
      AND nama_mapel NOT LIKE '%Upacara%'
      AND nama_mapel NOT LIKE '%Istirahat%'
      AND nama_mapel NOT LIKE '%Kepramukaan%'
      AND nama_mapel NOT LIKE '%Ekstrakurikuler%'
    ORDER BY nama_mapel ASC
")->fetchAll(PDO::FETCH_ASSOC);

$all_kelas_modal = $pdo->query("
    SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC
")->fetchAll(PDO::FETCH_ASSOC);

if ($is_admin_or_viewer) {
    $mapel_list = $all_mapel_modal;
    $kelas_list = $all_kelas_modal;
} else {
    $mapel_list = getGuruTaughtMapels($pdo, $guru_id);
    $kelas_list = getGuruTaughtClasses($pdo, $guru_id);
    if (empty($mapel_list)) $mapel_list = $all_mapel_modal;
    if (empty($kelas_list)) $kelas_list = $all_kelas_modal;
}

$semester_options = ['Semester 1', 'Semester 2'];

// Filters
$f_mapel = (int)($_GET['f_mapel'] ?? 0);
$f_kelas = (int)($_GET['f_kelas'] ?? 0);
$f_jenis = trim((string)($_GET['f_jenis'] ?? ''));
$f_semester = trim((string)($_GET['f_semester'] ?? ''));

if ($is_admin_or_viewer) {
    $where = ["1=1"];
    $params = [];
} elseif ($is_wali && $wali_kelas_id) {
    // Wali: tampilkan semua buku di kelasnya (dari semua guru)
    $where = ["b.id_kelas = ?"];
    $params = [$wali_kelas_id];
    // Pre-filter kelas ke kelas walinya
    if ($f_kelas <= 0) $f_kelas = (int)$wali_kelas_id;
} else {
    // Guru: hanya buku miliknya sendiri
    $where = ["b.id_guru = ?"];
    $params = [$guru_id];
}

if ($f_mapel > 0) { $where[] = "b.id_mapel = ?"; $params[] = $f_mapel; }
if ($f_kelas > 0) { $where[] = "b.id_kelas = ?"; $params[] = $f_kelas; }
if ($f_jenis !== '') { $where[] = "b.jenis_buku = ?"; $params[] = $f_jenis; }
if ($f_semester !== '') { $where[] = "b.semester = ?"; $params[] = $f_semester; }

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT b.*, m.nama_mapel, k.nama_kelas, g.nama_guru
    FROM tb_buku_ajar b
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = b.id_mapel
    LEFT JOIN tb_kelas k ON k.id_kelas = b.id_kelas
    LEFT JOIN tb_guru g ON g.id_guru = b.id_guru
    WHERE $where_sql
    ORDER BY b.id DESC
");
$stmt->execute($params);
$buku_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Data Buku Ajar';
$no = 1;

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><?= htmlspecialchars($page_title) ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <!-- Filter Card -->
            <div class="card mb-3">
                <div class="card-header">
                    <h4><i class="fas fa-filter mr-2"></i>Filter Buku Ajar</h4>
                </div>
                <div class="card-body">
                    <form method="GET" class="row">
                        <?php if (isset($_GET['session_type'])): ?>
                            <input type="hidden" name="session_type" value="<?= htmlspecialchars($_GET['session_type']) ?>">
                        <?php endif; ?>
                        <div class="col-md-3 mb-2">
                            <label class="small font-weight-bold">Mata Pelajaran</label>
                            <select name="f_mapel" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Mapel --</option>
                                <?php foreach ($mapel_list as $m): ?>
                                    <option value="<?= (int)$m['id_mapel'] ?>" <?= $f_mapel === (int)$m['id_mapel'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($m['nama_mapel']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2">
                            <label class="small font-weight-bold">Kelas</label>
                            <select name="f_kelas" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Kelas --</option>
                                <?php foreach ($kelas_list as $k): ?>
                                    <option value="<?= (int)$k['id_kelas'] ?>" <?= $f_kelas === (int)$k['id_kelas'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($k['nama_kelas']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2">
                            <label class="small font-weight-bold">Jenis Buku</label>
                            <select name="f_jenis" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Jenis --</option>
                                <option value="Digital" <?= $f_jenis === 'Digital' ? 'selected' : '' ?>>Digital</option>
                                <option value="Cetak" <?= $f_jenis === 'Cetak' ? 'selected' : '' ?>>Cetak</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2">
                            <label class="small font-weight-bold">Semester</label>
                            <select name="f_semester" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua --</option>
                                <?php foreach ($semester_options as $s): ?>
                                    <option value="<?= htmlspecialchars($s) ?>" <?= $f_semester === $s ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mt-2 d-flex">
                            <a href="buku_ajar.php<?= isset($_GET['session_type']) ? '?session_type=' . urlencode($_GET['session_type']) : '' ?>" class="btn btn-secondary btn-sm">
                                <i class="fas fa-undo"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Card Grid Buku Ajar -->
            <div class="card mb-4 border-0 shadow-sm" style="border-radius: 14px;">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center py-3" style="border-radius: 14px 14px 0 0; border-bottom: 1px solid #f1f5f9;">
                    <div>
                        <h4 class="mb-0 font-weight-bold text-dark"><i class="fas fa-book-open text-primary mr-2"></i>Katalog Buku Ajar</h4>
                        <small class="text-muted">Total <?= count($buku_rows) ?> buku ajar tersedia</small>
                    </div>
                    <div class="d-flex flex-wrap align-items-center" style="gap: 8px;">
                        <?php
                        // Bangun query string filter untuk export
                        $qs_parts = [];
                        if (isset($_GET['session_type'])) $qs_parts[] = 'session_type=' . urlencode($_GET['session_type']);
                        if ($f_mapel > 0)   $qs_parts[] = 'f_mapel=' . $f_mapel;
                        if ($f_kelas > 0)   $qs_parts[] = 'f_kelas=' . $f_kelas;
                        if ($f_jenis !== '') $qs_parts[] = 'f_jenis=' . urlencode($f_jenis);
                        if ($f_semester !== '') $qs_parts[] = 'f_semester=' . urlencode($f_semester);
                        $export_qs = $qs_parts ? '?' . implode('&', $qs_parts) : '';
                        ?>
                        <a href="export_buku_ajar_excel.php<?= $export_qs ?>"
                           class="btn btn-success btn-sm px-3 shadow-sm" style="border-radius: 8px;">
                            <i class="fas fa-file-excel mr-1"></i> Excel
                        </a>
                        <button type="button" class="btn btn-danger btn-sm px-3 shadow-sm" style="border-radius: 8px;"
                                onclick="window.open('cetak_buku_ajar.php<?= $export_qs ?>', '_blank')">
                            <i class="fas fa-print mr-1"></i> Cetak PDF
                        </button>
                        <?php if ($can_crud): ?>
                        <button type="button" class="btn btn-primary px-3 shadow-sm" id="btnTambah" style="border-radius: 8px;">
                            <i class="fas fa-plus mr-1"></i> Tambah Buku Ajar
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body bg-light p-3 p-md-4" style="border-radius: 0 0 14px 14px;">
                    <?php if (empty($buku_rows)): ?>
                        <div class="text-center py-5 bg-white rounded shadow-sm border">
                            <i class="fas fa-book fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted font-weight-bold">Belum ada data buku ajar</h5>
                            <p class="text-muted small mb-0">
                                <?= $can_crud ? 'Silakan tambahkan buku ajar baru atau sesuaikan filter pencarian Anda.' : 'Sesuaikan filter kelas untuk menampilkan buku ajar yang tersedia.' ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="row" style="row-gap: 16px;">
                            <?php foreach ($buku_rows as $r): ?>
                                <?php
                                $jenis_color = $r['jenis_buku'] === 'Digital' ? '#7c3aed' : '#059669';
                                $jenis_bg = $r['jenis_buku'] === 'Digital' ? '#ede9fe' : '#d1fae5';
                                $jenis_icon = $r['jenis_buku'] === 'Digital' ? 'fas fa-tablet-alt' : 'fas fa-book';
                                $st_badge = $r['status'] === 'Aktif' ? 'success' : ($r['status'] === 'Draft' ? 'warning' : 'secondary');
                                ?>
                                <div class="col-12 col-md-6 col-lg-4 d-flex align-items-stretch">
                                    <div class="card w-100 border-0 shadow-sm transition" style="border-radius: 12px; overflow: hidden; background: #fff; border: 1px solid #e2e8f0 !important;">
                                        <div class="card-body p-3 d-flex flex-column justify-content-between">
                                            <div>
                                                <!-- Header: Jenis & Status -->
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <span class="badge px-2 py-1 font-weight-bold" style="background: <?= $jenis_bg ?>; color: <?= $jenis_color ?>; font-size: 11px; border-radius: 6px;">
                                                        <i class="<?= $jenis_icon ?> mr-1"></i> <?= htmlspecialchars($r['jenis_buku']) ?>
                                                    </span>
                                                    <span class="badge badge-<?= $st_badge ?> px-2 py-1 font-weight-bold" style="font-size: 10px; border-radius: 6px;">
                                                        <?= htmlspecialchars($r['status']) ?>
                                                    </span>
                                                </div>

                                                <!-- Nomor & Judul -->
                                                <div class="d-flex align-items-start mb-2" style="gap: 8px;">
                                                    <span class="badge badge-secondary px-2 py-1" style="font-size: 11px; border-radius: 6px; flex-shrink: 0;">
                                                        <?= $no++ ?>
                                                    </span>
                                                    <h6 class="font-weight-bold text-dark mb-0" style="font-size: 14px; line-height: 1.35;">
                                                        <?= htmlspecialchars($r['judul']) ?>
                                                    </h6>
                                                </div>

                                                <!-- Info Mapel & Kelas -->
                                                <div class="d-flex flex-wrap align-items-center mb-2 small" style="gap: 6px;">
                                                    <span class="badge badge-light border text-dark font-weight-bold px-2 py-0.5" style="border-radius: 4px; font-size: 11px;">
                                                        <i class="fas fa-book text-muted mr-1"></i> <?= htmlspecialchars($r['nama_mapel'] ?? '-') ?>
                                                    </span>
                                                    <span class="badge badge-light border text-dark font-weight-bold px-2 py-0.5" style="border-radius: 4px; font-size: 11px;">
                                                        <i class="fas fa-chalkboard text-muted mr-1"></i> <?= htmlspecialchars($r['nama_kelas'] ?? 'Semua') ?>
                                                    </span>
                                                </div>

                                                <!-- Penyusun -->
                                                <div class="small text-muted mb-2" style="font-size: 11.5px;">
                                                    <i class="fas fa-user-edit mr-1"></i>
                                                    <strong>Penyusun:</strong> <?= htmlspecialchars($r['penyusun'] ?: '-') ?>
                                                </div>

                                                <!-- Metadata -->
                                                <div class="small text-muted mb-2 d-flex justify-content-between" style="font-size: 11px;">
                                                    <span><i class="far fa-calendar-alt mr-1"></i> <?= htmlspecialchars($r['semester'] ?? '-') ?> (<?= htmlspecialchars($r['tahun_ajaran'] ?? '-') ?>)</span>
                                                    <?php if ($is_admin_or_viewer && !empty($r['nama_guru'])): ?>
                                                        <span title="Diinput oleh"><i class="fas fa-user mr-1"></i><?= htmlspecialchars($r['nama_guru']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <!-- Actions -->
                                            <div class="pt-2 border-top d-flex align-items-center justify-content-end" style="border-color: #f1f5f9 !important; gap: 6px;">
                                                <?php
                                                // Guru/wali: tombol edit/hapus hanya untuk buku milik sendiri
                                                $is_own_book = ($can_crud && (int)$r['id_guru'] === $guru_id);
                                                ?>
                                                <?php if ($is_own_book): ?>
                                                    <button type="button" class="btn btn-outline-warning btn-sm px-2 btn-edit"
                                                            data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>'
                                                            title="Edit" style="border-radius: 6px;">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    <button type="button" class="btn btn-outline-danger btn-sm px-2 btn-hapus"
                                                            data-id="<?= (int)$r['id'] ?>"
                                                            data-judul="<?= htmlspecialchars($r['judul'], ENT_QUOTES) ?>"
                                                            title="Hapus" style="border-radius: 6px;">
                                                        <i class="fas fa-trash"></i> Hapus
                                                    </button>
                                                <?php elseif ($is_admin_or_viewer): ?>
                                                    <span class="small text-muted"><i class="fas fa-eye mr-1"></i>Hanya lihat</span>
                                                <?php else: ?>
                                                    <span class="small text-muted"><i class="fas fa-info-circle mr-1"></i>Buku guru lain</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Modal Form Tambah / Edit Buku Ajar -->
<div class="modal fade" id="modalBuku" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formBuku">
                <input type="hidden" name="action" id="formAction" value="tambah">
                <input type="hidden" name="id" id="bukuId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Tambah Buku Ajar Baru</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-12 form-group">
                            <label>Judul Buku <span class="text-danger">*</span></label>
                            <input type="text" name="judul" id="inp_judul" class="form-control" required placeholder="Contoh: Matematika Kelas 4 Kurikulum Merdeka">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Penyusun <span class="text-danger">*</span></label>
                            <input type="text" name="penyusun" id="inp_penyusun" class="form-control" required placeholder="Contoh: Tim Kemdikbudristek">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Jenis Buku</label>
                            <select name="jenis_buku" id="inp_jenis" class="form-control">
                                <option value="Cetak">Cetak</option>
                                <option value="Digital">Digital</option>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Mata Pelajaran</label>
                            <select name="id_mapel" id="inp_mapel" class="form-control">
                                <option value="">-- Pilih Mapel --</option>
                                <?php foreach ($mapel_list as $m): ?>
                                    <option value="<?= (int)$m['id_mapel'] ?>"><?= htmlspecialchars($m['nama_mapel']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Kelas</label>
                            <select name="id_kelas" id="inp_kelas" class="form-control">
                                <option value="">-- Pilih Kelas --</option>
                                <?php foreach ($kelas_list as $k): ?>
                                    <option value="<?= (int)$k['id_kelas'] ?>"><?= htmlspecialchars($k['nama_kelas']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Semester</label>
                            <select name="semester" id="inp_semester" class="form-control">
                                <?php foreach ($semester_options as $s): ?>
                                    <option value="<?= htmlspecialchars($s) ?>" <?= $s === $semester_aktif ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Tahun Ajaran</label>
                            <input type="text" name="tahun_ajaran" id="inp_tahun" class="form-control" value="<?= htmlspecialchars($tahun_ajaran_aktif) ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Status</label>
                            <select name="status" id="inp_status" class="form-control">
                                <option value="Aktif">Aktif</option>
                                <option value="Draft">Draft</option>
                                <option value="Arsip">Arsip</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden Form Hapus -->
<form method="POST" id="formHapus" class="d-none">
    <input type="hidden" name="action" value="hapus">
    <input type="hidden" name="id" id="formHapusId">
</form>

<!-- JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Modal Tambah
    var btnTambah = document.getElementById('btnTambah');
    if (btnTambah) {
        btnTambah.addEventListener('click', function() {
            document.getElementById('formAction').value = 'tambah';
            document.getElementById('bukuId').value = '';
            document.getElementById('modalTitle').textContent = 'Tambah Buku Ajar Baru';
            document.getElementById('formBuku').reset();
            $('#modalBuku').modal('show');
        });
    }

    // Modal Edit
    document.querySelectorAll('.btn-edit').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var data = JSON.parse(this.getAttribute('data-json'));
            document.getElementById('formAction').value = 'edit';
            document.getElementById('bukuId').value = data.id;
            document.getElementById('modalTitle').textContent = 'Edit Buku Ajar';
            document.getElementById('inp_judul').value = data.judul || '';
            document.getElementById('inp_penyusun').value = data.penyusun || '';
            document.getElementById('inp_jenis').value = data.jenis_buku || 'Cetak';
            document.getElementById('inp_mapel').value = data.id_mapel || '';
            document.getElementById('inp_kelas').value = data.id_kelas || '';
            document.getElementById('inp_semester').value = data.semester || 'Semester 1';
            document.getElementById('inp_tahun').value = data.tahun_ajaran || '';
            document.getElementById('inp_status').value = data.status || 'Aktif';
            $('#modalBuku').modal('show');
        });
    });

    // Hapus
    document.querySelectorAll('.btn-hapus').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            var judul = this.getAttribute('data-judul');
            Swal.fire({
                title: 'Hapus Buku Ajar?',
                text: 'Buku "' + judul + '" akan dihapus permanen.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: 'Ya, Hapus',
                cancelButtonText: 'Batal'
            }).then(function(res) {
                if (res.isConfirmed) {
                    document.getElementById('formHapusId').value = id;
                    document.getElementById('formHapus').submit();
                }
            });
        });
    });
});

<?php if (!empty($message)): ?>
Swal.fire({
    icon: '<?= $message['type'] === 'danger' ? 'error' : $message['type'] ?>',
    title: '<?= $message['type'] === 'success' ? 'Berhasil' : 'Perhatian' ?>',
    text: <?= json_encode($message['text']) ?>,
    timer: 2200,
    showConfirmButton: false
});
<?php endif; ?>
</script>

<?php include '../templates/footer.php'; ?>