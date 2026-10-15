<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['siswa'])) {
    redirect('../login.php');
}

$id_siswa = (int)($_SESSION['user_id'] ?? 0);
$stmt = $pdo->prepare("SELECT s.*, k.nama_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_siswa = ?");
$stmt->execute([$id_siswa]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student || empty($student['id_kelas'])) {
    echo "Data kelas siswa tidak ditemukan.";
    exit;
}

$id_kelas = (int)$student['id_kelas'];
$nama_kelas = (string)$student['nama_kelas'];

$school_profile = getSchoolProfile($pdo);
$tahun_ajaran_aktif = $school_profile['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1);
$semester_aktif = $school_profile['semester'] ?? 'Semester 1';

// Filters
$f_mapel = (int)($_GET['f_mapel'] ?? 0);
$f_jenis = trim((string)($_GET['f_jenis'] ?? ''));

// Fetch mapel list for student's class
$mapel_list = $pdo->query("SELECT id_mapel, nama_mapel FROM tb_mata_pelajaran WHERE (jenis_mapel IS NULL OR jenis_mapel = 'Akademik') AND nama_mapel NOT LIKE '%Asmaul Husna%' AND nama_mapel NOT LIKE '%Upacara%' AND nama_mapel NOT LIKE '%Istirahat%' ORDER BY nama_mapel ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Bahan Ajar for this class (or all classes) with status 'Aktif'
$where = ["(b.id_kelas = ? OR b.id_kelas IS NULL)", "b.status = 'Aktif'"];
$params = [$id_kelas];

if ($f_mapel > 0) {
    $where[] = "b.id_mapel = ?";
    $params[] = $f_mapel;
}
if ($f_jenis !== '') {
    $where[] = "b.jenis = ?";
    $params[] = $f_jenis;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT b.*, m.nama_mapel, k.nama_kelas, g.nama_guru
    FROM tb_bahan_ajar b
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = b.id_mapel
    LEFT JOIN tb_kelas k ON k.id_kelas = b.id_kelas
    LEFT JOIN tb_guru g ON g.id_guru = b.id_guru
    WHERE $where_sql
    ORDER BY b.id DESC
");
$stmt->execute($params);
$bahan_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$jenis_options = ['PDF', 'Video', 'Link', 'Presentasi', 'LKPD', 'Dokumen'];

$page_title = 'Bahan Ajar & Materi Pembelajaran';
$css_libs = [];
$js_libs = [];
$js_page = [];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><i class="fas fa-book-reader text-primary mr-2"></i>Bahan Ajar & Materi Pembelajaran</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item"><a href="tugas.php">Tugas Siswa</a></div>
                <div class="breadcrumb-item">Bahan Ajar</div>
            </div>
        </div>

        <div class="section-body">
            <!-- Nav Tabs Belajar (Tugas & Bahan Ajar) -->
            <div class="card mb-3 border-0 shadow-sm" style="border-radius: 12px;">
                <div class="card-body p-2">
                    <ul class="nav nav-pills">
                        <li class="nav-item">
                            <a class="nav-link" href="tugas.php">
                                <i class="fas fa-tasks mr-1"></i> Tugas Siswa
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="bahan_ajar.php">
                                <i class="fas fa-book-open mr-1"></i> Bahan Ajar & Materi (<?= count($bahan_rows) ?>)
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Filter & Search Bar -->
            <div class="card mb-3 border-0 shadow-sm" style="border-radius: 12px;">
                <div class="card-body p-3">
                    <form method="GET" class="row align-items-center">
                        <div class="col-12 col-md-5 mb-2 mb-md-0">
                            <label class="small font-weight-bold mb-1">Mata Pelajaran</label>
                            <select name="f_mapel" class="form-control form-control-sm" onchange="this.form.submit()" style="border-radius: 8px;">
                                <option value="">-- Semua Mata Pelajaran --</option>
                                <?php foreach ($mapel_list as $m): ?>
                                    <option value="<?= (int)$m['id_mapel'] ?>" <?= $f_mapel === (int)$m['id_mapel'] ? 'selected' : '' ?>><?= htmlspecialchars($m['nama_mapel']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4 mb-2 mb-md-0">
                            <label class="small font-weight-bold mb-1">Jenis Bahan</label>
                            <select name="f_jenis" class="form-control form-control-sm" onchange="this.form.submit()" style="border-radius: 8px;">
                                <option value="">-- Semua Jenis Bahan --</option>
                                <?php foreach ($jenis_options as $j): ?>
                                    <option value="<?= $j ?>" <?= $f_jenis === $j ? 'selected' : '' ?>><?= $j ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-3 mt-md-4 d-flex" style="gap: 6px;">
                            <a href="bahan_ajar.php" class="btn btn-secondary btn-sm flex-fill" style="border-radius: 8px;"><i class="fas fa-undo mr-1"></i> Reset</a>
                            <a href="tugas.php" class="btn btn-outline-primary btn-sm flex-fill" style="border-radius: 8px;"><i class="fas fa-tasks mr-1"></i> Ke Tugas</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- List Card Bahan Ajar Siswa -->
            <div class="card border-0 shadow-sm" style="border-radius: 14px;">
                <div class="card-header bg-white py-3" style="border-radius: 14px 14px 0 0; border-bottom: 1px solid #f1f5f9;">
                    <h4 class="mb-0 font-weight-bold text-dark"><i class="fas fa-graduation-cap text-primary mr-2"></i>Bahan Belajar Kelas <?= htmlspecialchars($nama_kelas) ?></h4>
                </div>
                <div class="card-body bg-light p-3 p-md-4" style="border-radius: 0 0 14px 14px;">
                    <?php if (empty($bahan_rows)): ?>
                        <div class="text-center py-5 bg-white rounded shadow-sm border">
                            <i class="fas fa-book-reader fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted font-weight-bold">Belum ada bahan ajar yang dibagikan</h5>
                            <p class="text-muted small mb-0">Guru Anda belum mengunggah bahan ajar atau materi untuk kelas ini.</p>
                        </div>
                    <?php else: ?>
                        <div class="row" style="row-gap: 16px;">
                            <?php foreach ($bahan_rows as $r): ?>
                                <?php
                                $is_url = (strpos($r['file_link'], 'http://') === 0 || strpos($r['file_link'], 'https://') === 0);
                                $ext = strtolower(pathinfo($r['file_link'], PATHINFO_EXTENSION));

                                $icon_map = [
                                    'PDF' => ['icon' => 'fas fa-file-pdf', 'color' => '#ef4444', 'bg' => '#fee2e2'],
                                    'Video' => ['icon' => 'fas fa-video', 'color' => '#8b5cf6', 'bg' => '#ede9fe'],
                                    'Link' => ['icon' => 'fas fa-link', 'color' => '#06b6d4', 'bg' => '#cffafe'],
                                    'Presentasi' => ['icon' => 'fas fa-file-powerpoint', 'color' => '#f97316', 'bg' => '#ffedd5'],
                                    'LKPD' => ['icon' => 'fas fa-clipboard-list', 'color' => '#10b981', 'bg' => '#d1fae5'],
                                    'Dokumen' => ['icon' => 'fas fa-file-word', 'color' => '#2563eb', 'bg' => '#dbeafe']
                                ];
                                $t_info = $icon_map[$r['jenis']] ?? ['icon' => 'fas fa-file-alt', 'color' => '#64748b', 'bg' => '#f1f5f9'];
                                ?>
                                <div class="col-12 col-md-6 col-lg-4 d-flex align-items-stretch">
                                    <div class="card w-100 border-0 shadow-sm" style="border-radius: 12px; overflow: hidden; background: #fff; border: 1px solid #e2e8f0 !important;">
                                        <div class="card-body p-3 p-md-3.5 d-flex flex-column justify-content-between">
                                            <div>
                                                <!-- Top Bar Badge -->
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <span class="badge px-2.5 py-1 font-weight-bold" style="background: <?= $t_info['bg'] ?>; color: <?= $t_info['color'] ?>; font-size: 11px; border-radius: 6px;">
                                                        <i class="<?= $t_info['icon'] ?> mr-1"></i> <?= htmlspecialchars($r['jenis']) ?>
                                                    </span>
                                                    <span class="badge badge-light border text-dark font-weight-bold px-2 py-0.5" style="border-radius: 4px; font-size: 11px;">
                                                        <i class="fas fa-book text-muted mr-1"></i> <?= htmlspecialchars($r['nama_mapel'] ?? '-') ?>
                                                    </span>
                                                </div>

                                                <!-- Judul Bahan Ajar -->
                                                <h6 class="font-weight-bold text-dark mb-1" title="<?= htmlspecialchars($r['judul']) ?>" style="font-size: 14.5px; line-height: 1.35; min-height: 38px;">
                                                    <?= htmlspecialchars($r['judul']) ?>
                                                </h6>

                                                <!-- Info Guru Pengampu -->
                                                <div class="small text-muted mb-2">
                                                    <i class="fas fa-chalkboard-teacher mr-1 text-primary"></i> <?= htmlspecialchars($r['nama_guru'] ?? 'Guru Pengampu') ?>
                                                </div>

                                                <!-- Materi / Catatan -->
                                                <?php if (!empty($r['materi_tp'])): ?>
                                                    <div class="p-2 mb-2 rounded bg-light border-0 small text-muted" style="font-size: 11.5px; line-height: 1.35; background: #f8fafc !important;">
                                                        <i class="fas fa-info-circle mr-1 text-info"></i> <?= htmlspecialchars(mb_strimwidth($r['materi_tp'], 0, 80, '...')) ?>
                                                    </div>
                                                <?php endif; ?>

                                                <div class="small text-muted mb-3 d-flex justify-content-between" style="font-size: 11px;">
                                                    <span><i class="far fa-calendar-alt mr-1"></i> <?= htmlspecialchars($r['semester'] ?? '-') ?></span>
                                                    <span><?= date('d M Y', strtotime($r['created_at'])) ?></span>
                                                </div>
                                            </div>

                                            <!-- Tombol Akses / Baca -->
                                            <div class="pt-2 border-top d-flex align-items-center justify-content-between" style="border-color: #f1f5f9 !important;">
                                                <a href="../guru/preview_bahan.php?id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-primary btn-block btn-sm font-weight-bold py-1.5 shadow-sm" style="border-radius: 8px; font-size: 12px;">
                                                    <i class="fas fa-book-reader mr-1.5"></i> Pelajari & Buka Materi
                                                </a>
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

<?php include '../templates/footer.php'; ?>
