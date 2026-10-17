<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['siswa'])) {
    redirect('../login.php');
}

ensure_nilai_semester_enum_ujian_praktik($pdo);
$page_title = nilai_ujian_page_title();

// Get student data
$id_siswa = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT s.*, k.nama_kelas, k.id_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_siswa = ?");
$stmt->execute([$id_siswa]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    echo "Data siswa tidak ditemukan.";
    exit;
}

// Security Check for Grade 6
$nk = strtoupper($student['nama_kelas']);
if (strpos($nk, '6') === false && strpos($nk, 'VI') === false) {
    echo "<script>alert('Halaman ini hanya untuk siswa Kelas 6'); window.location='dashboard.php';</script>";
    exit;
}

$id_kelas = $student['id_kelas'];

// Get Active Semester
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'];
$semester_aktif = $school_profile['semester'];

// Jenis nilai ujian / ujian praktik — siswa: MAX(asli, remidi); ujian praktik: asli saja
$selected_jenis = nilai_ujian_jenis_semester();

if ($selected_jenis === 'Ujian') {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tb_data_nilai_ujian (
            id_nilai_ujian INT NOT NULL AUTO_INCREMENT,
            id_siswa INT NOT NULL,
            id_kelas INT DEFAULT NULL,
            tahun_ajaran VARCHAR(30) NOT NULL,
            nilai_ujian DECIMAL(5,2) NOT NULL DEFAULT 0,
            keterangan VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id_nilai_ujian),
            UNIQUE KEY uniq_data_nilai_ujian_siswa_ta (id_siswa, tahun_ajaran),
            KEY idx_data_nilai_ujian_ta (tahun_ajaran),
            KEY idx_data_nilai_ujian_siswa (id_siswa),
            KEY idx_data_nilai_ujian_kelas (id_kelas)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        error_log('tb_data_nilai_ujian siswa: ' . $e->getMessage());
    }

    $stmt = $pdo->prepare("SELECT nilai_ujian, keterangan, tahun_ajaran, updated_at
        FROM tb_data_nilai_ujian
        WHERE id_siswa = ? AND tahun_ajaran = ?
        LIMIT 1");
    $stmt->execute([$id_siswa, $tahun_ajaran]);
    $nilai_resmi = $stmt->fetch(PDO::FETCH_ASSOC);

    require_once '../templates/header.php';
    require_once '../templates/sidebar.php';
    ?>

    <link rel="stylesheet" href="../assets/css/siswa-nilai-mobile.css">

    <div class="main-content">
        <section class="section">
            <div class="section-header">
                <h1><?= $page_title ?></h1>
                <div class="section-header-breadcrumb">
                    <div class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></div>
                    <div class="breadcrumb-item active"><?= htmlspecialchars($page_title) ?></div>
                </div>
            </div>

            <div class="section-body">
                <!-- Hero Siswa Info -->
                <div class="snilai-hero-card">
                    <div class="snilai-hero-top">
                        <div class="snilai-student-info">
                            <div class="snilai-student-name">
                                <i class="fas fa-certificate mr-1"></i> <?= htmlspecialchars($student['nama_siswa']) ?>
                            </div>
                            <div class="snilai-student-sub">
                                <span>Kelas <?= htmlspecialchars($student['nama_kelas']) ?></span>
                                <span>•</span>
                                <span class="snilai-badge-ta">Tahun Ajaran <?= htmlspecialchars($tahun_ajaran) ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Kartu Nilai Ujian Resmi Ala Mobile Apps -->
                <?php if ($nilai_resmi): ?>
                    <div class="snilai-official-card">
                        <div class="snilai-official-ribbon">RESMI</div>
                        <div class="text-muted small text-uppercase font-weight-bold letter-spacing-1">
                            <i class="fas fa-medal text-warning mr-1"></i> Nilai Ujian Resmi
                        </div>
                        <div class="snilai-official-score">
                            <?= number_format((float)$nilai_resmi['nilai_ujian'], 2) ?>
                        </div>
                        <div class="mb-2">
                            <span class="snilai-official-badge">
                                <i class="fas fa-check-circle"></i> Terverifikasi Madrasah
                            </span>
                        </div>
                        <?php if (!empty($nilai_resmi['keterangan'])): ?>
                            <div class="snilai-official-note">
                                <i class="fas fa-info-circle text-primary mr-1"></i> <?= htmlspecialchars($nilai_resmi['keterangan']) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($nilai_resmi['updated_at'])): ?>
                            <div class="text-muted small mt-3" style="font-size: 11px;">
                                <i class="far fa-clock mr-1"></i> Diperbarui: <?= date('d M Y, H:i', strtotime($nilai_resmi['updated_at'])) ?> WIB
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="card border-0 shadow-sm text-center py-5" style="border-radius: 18px;">
                        <div class="card-body">
                            <div class="text-warning mb-3">
                                <i class="fas fa-clock fa-3x"></i>
                            </div>
                            <h6 class="font-weight-bold text-dark mb-1">Nilai Ujian Belum Diterbitkan</h6>
                            <p class="text-muted small mb-0 px-3">
                                Nilai ujian resmi untuk Tahun Ajaran <?= htmlspecialchars($tahun_ajaran) ?> belum diinput atau masih dalam proses rekapitulasi madrasah.
                            </p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <?php require_once '../templates/footer.php'; ?>
    <?php
    exit;
}

// Get Subjects (Mapel)
$subjects = getFilteredSubjects($pdo);

// Filter subjects for exam types
if (in_array($selected_jenis, ['Pra Ujian', 'Ujian'], true)) {
    $subjects = array_values(array_filter($subjects, function ($m) {
        $nama = strtolower(trim((string)($m['nama_mapel'] ?? '')));
        $nama = preg_replace('/\s+/', ' ', $nama);
        return $nama !== 'tajwid' && $nama !== 'bta';
    }));
}

// Filter subjects for Ujian Praktik - only show subjects with grades for this student
if ($selected_jenis === 'Ujian Praktik') {
    $stmt = $pdo->prepare("
        SELECT id_mapel
        FROM tb_nilai_semester
        WHERE id_siswa = ?
          AND jenis_semester = ?
          AND tahun_ajaran = ?
          AND semester = ?
          AND (
            COALESCE(nilai_asli, 0) > 0
            OR COALESCE(nilai_remidi, 0) > 0
            OR COALESCE(nilai_jadi, 0) > 0
          )
    ");
    $stmt->execute([$id_siswa, $selected_jenis, $tahun_ajaran, $semester_aktif]);
    $filled_mapel_ids = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $subjects = array_values(array_filter($subjects, function ($m) use ($filled_mapel_ids) {
        return in_array((string)($m['id_mapel'] ?? ''), $filled_mapel_ids, true);
    }));
}

// Fetch Grades
$rekap_data = [];
$total_nilai = 0;
$count_mapel = 0;

foreach ($subjects as $mapel) {
    $nilai = 0;
    
    $stmt = $pdo->prepare("
        SELECT nilai_asli, nilai_remidi
        FROM tb_nilai_semester
        WHERE id_kelas = ? AND id_mapel = ?
          AND jenis_semester = ? AND tahun_ajaran = ? AND semester = ?
          AND id_siswa = ?
        LIMIT 1
    ");
    $stmt->execute([$id_kelas, $mapel['id_mapel'], $selected_jenis, $tahun_ajaran, $semester_aktif, $id_siswa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $nilai = nilai_tampilan_siswa_semester(
            $row['nilai_asli'] ?? null,
            $row['nilai_remidi'] ?? null,
            nilai_ujian_is_praktik_mode()
        );
    }

    $rekap_data[$mapel['id_mapel']] = $nilai;
    
    if ($nilai > 0) {
        $total_nilai += $nilai;
        $count_mapel++;
    }
}

$rerata = $count_mapel > 0 ? round($total_nilai / $count_mapel, 1) : 0;
$progress_total = count($subjects);
$progress_filled = $count_mapel;
$progress_percent = $progress_total > 0 ? round(($progress_filled / $progress_total) * 100, 1) : 0;

require_once '../templates/header.php';
require_once '../templates/sidebar.php';

if (!function_exists('get_nilai_badge_class')) {
    function get_nilai_badge_class($val) {
        if ($val <= 0) return 'snilai-badge-muted';
        if ($val >= 85) return 'snilai-badge-success';
        if ($val >= 75) return 'snilai-badge-primary';
        if ($val >= 60) return 'snilai-badge-warning';
        return 'snilai-badge-danger';
    }
}

if (!function_exists('get_nilai_status_text')) {
    function get_nilai_status_text($val) {
        if ($val <= 0) return 'Belum Diisi';
        if ($val >= 85) return 'Sangat Baik';
        if ($val >= 75) return 'Baik';
        if ($val >= 60) return 'Cukup';
        return 'Perlu Belajar';
    }
}
?>

<link rel="stylesheet" href="../assets/css/siswa-nilai-mobile.css">

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><?= $page_title ?></h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item active"><?= htmlspecialchars($page_title) ?></div>
            </div>
        </div>

        <div class="section-body">
            <!-- Hero Card Ringkasan Nilai Ujian Praktik -->
            <div class="snilai-hero-card">
                <div class="snilai-hero-top">
                    <div class="snilai-student-info">
                        <div class="snilai-student-name">
                            <i class="fas fa-flask mr-1"></i> <?= htmlspecialchars($student['nama_siswa']) ?>
                        </div>
                        <div class="snilai-student-sub">
                            <span>Kelas <?= htmlspecialchars($student['nama_kelas']) ?></span>
                            <span>•</span>
                            <span class="snilai-badge-ta">TA <?= htmlspecialchars($tahun_ajaran) ?> (<?= htmlspecialchars(formatSemesterLabelForExport($semester_aktif)) ?>)</span>
                        </div>
                    </div>
                </div>

                <div class="snilai-stats-grid">
                    <div class="snilai-stat-box">
                        <div class="snilai-stat-val gold"><?= $rerata ?></div>
                        <div class="snilai-stat-lbl">Rerata Nilai</div>
                    </div>
                    <div class="snilai-stat-box">
                        <div class="snilai-stat-val"><?= $total_nilai ?></div>
                        <div class="snilai-stat-lbl">Total Nilai</div>
                    </div>
                    <div class="snilai-stat-box">
                        <div class="snilai-stat-val"><?= (int)$progress_filled ?>/<?= (int)$progress_total ?></div>
                        <div class="snilai-stat-lbl">Mapel Terisi</div>
                    </div>
                </div>

                <div class="snilai-hero-progress">
                    <div class="snilai-hero-progress-head">
                        <span>Capaian Penilaian (Ujian Praktik)</span>
                        <span><?= (float)$progress_percent ?>%</span>
                    </div>
                    <div class="snilai-progress-bar-bg">
                        <div class="snilai-progress-bar-fill" style="width: <?= (float)$progress_percent ?>%;"></div>
                    </div>
                </div>
            </div>

            <!-- Daftar Kartu Nilai Per Mata Pelajaran (Mobile Apps View) -->
            <div class="snilai-grade-list">
                <?php if (empty($subjects)): ?>
                    <div class="card border-0 shadow-sm text-center py-4" style="border-radius: 14px;">
                        <div class="text-muted small">Tidak ada mata pelajaran Ujian Praktik yang terdaftar.</div>
                    </div>
                <?php else: ?>
                    <?php 
                    $no = 1;
                    foreach ($subjects as $mapel): 
                        $val = $rekap_data[$mapel['id_mapel']] ?? 0;
                        $badge_class = get_nilai_badge_class($val);
                        $status_text = get_nilai_status_text($val);
                    ?>
                        <div class="snilai-grade-card">
                            <div class="snilai-grade-left">
                                <div class="snilai-grade-icon <?= $val > 0 ? 'tone-green' : 'tone-slate' ?>">
                                    <?= $no++ ?>
                                </div>
                                <div class="min-w-0">
                                    <div class="snilai-grade-title"><?= htmlspecialchars($mapel['nama_mapel']) ?></div>
                                    <div class="snilai-grade-sub">
                                        <i class="fas fa-tag mr-1 text-muted"></i> Ujian Praktik
                                    </div>
                                </div>
                            </div>
                            <div class="snilai-grade-badge <?= $badge_class ?>">
                                <div class="val"><?= $val > 0 ? $val : '-' ?></div>
                                <div class="lbl"><?= $status_text ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Tabel Fallback Desktop / Export -->
            <div class="d-none">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th width="50" class="text-center">No</th>
                            <th>Mata Pelajaran</th>
                            <th width="150" class="text-center">Nilai</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no_t = 1;
                        foreach ($subjects as $mapel): 
                            $val = $rekap_data[$mapel['id_mapel']] ?? 0;
                        ?>
                            <tr>
                                <td class="text-center"><?= $no_t++ ?></td>
                                <td><?= htmlspecialchars($mapel['nama_mapel']) ?></td>
                                <td class="text-center font-weight-bold">
                                    <?= $val > 0 ? $val : '-' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="bg-light">
                            <th colspan="2" class="text-right">Total Nilai</th>
                            <th class="text-center"><?= $total_nilai ?></th>
                        </tr>
                        <tr class="bg-light">
                            <th colspan="2" class="text-right">Rerata</th>
                            <th class="text-center"><?= $rerata ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </section>
</div>

<?php require_once '../templates/footer.php'; ?>
