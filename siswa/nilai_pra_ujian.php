<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['siswa'])) {
    redirect('../login.php');
}

$page_title = 'Nilai Pra Ujian';

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

// Parameters — siswa: MAX(nilai asli, nilai remidi); bukan nilai_jadi
$selected_jenis = 'Pra Ujian';

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
        $nilai = nilai_tampilan_siswa_semester($row['nilai_asli'] ?? null, $row['nilai_remidi'] ?? null, false);
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
        </div>

        <div class="section-body">
            <!-- Hero Card Ringkasan Nilai Pra Ujian -->
            <div class="snilai-hero-card">
                <div class="snilai-hero-top">
                    <div class="snilai-student-info">
                        <div class="snilai-student-name">
                            <i class="fas fa-graduation-cap mr-1"></i> <?= htmlspecialchars($student['nama_siswa']) ?>
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
                        <span>Capaian Penilaian (Pra Ujian)</span>
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
                        <div class="text-muted small">Tidak ada mata pelajaran Pra Ujian.</div>
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
                                        <i class="fas fa-tag mr-1 text-muted"></i> Pra Ujian
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
