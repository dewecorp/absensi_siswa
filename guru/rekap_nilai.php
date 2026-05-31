<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['guru', 'wali', 'kepala_madrasah', 'tata_usaha', 'admin'])) {
    redirect('../login.php');
}

$page_title = 'Rekap Nilai Siswa';
$user_role = $_SESSION['level'];
$is_admin_view = in_array($user_role, ['kepala_madrasah', 'tata_usaha', 'admin']);

// Get teacher data
$id_guru = null;
if (!$is_admin_view) {
    $id_guru = $_SESSION['user_id'];
    if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
        $stmt = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $id_guru = $stmt->fetchColumn();
    }
}

// Fetch classes
$classes = [];
if ($is_admin_view) {
    $stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Get teacher name and teaching assignments
    $stmt = $pdo->prepare("SELECT nama_guru, mengajar FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([$id_guru]);
    $guru_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $nama_guru = $guru_data['nama_guru'] ?? '';
    $mengajar_json = $guru_data['mengajar'] ?? '[]';
    $mengajar_ids = json_decode($mengajar_json, true) ?? [];

    // Check if user is Wali Kelas
    $stmt = $pdo->prepare("SELECT * FROM tb_kelas WHERE wali_kelas = ?");
    $stmt->execute([$nama_guru]);
    $wali_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $wali_class_ids = array_column($wali_classes, 'id_kelas');

    // Merge classes
    $all_class_ids = array_unique(array_merge($mengajar_ids, $wali_class_ids));

    if (!empty($all_class_ids)) {
        $placeholders = str_repeat('?,', count($all_class_ids) - 1) . '?';
        $stmt = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas IN ($placeholders) ORDER BY nama_kelas ASC");
        $stmt->execute($all_class_ids);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Parameters
$selected_class_id = isset($_GET['kelas']) ? $_GET['kelas'] : null;

// Auto-select class if teacher only has one class
if (!$is_admin_view && count($classes) === 1 && !$selected_class_id) {
    $selected_class_id = $classes[0]['id_kelas'];
}

$selected_jenis = isset($_GET['jenis']) ? $_GET['jenis'] : null;
$selected_tipe = isset($_GET['tipe']) ? $_GET['tipe'] : 'nilai_jadi'; // nilai_asli or nilai_jadi

// Validate selected class
$selected_class = null;
if ($selected_class_id) {
    foreach ($classes as $cls) {
        if ($cls['id_kelas'] == $selected_class_id) {
            $selected_class = $cls;
            break;
        }
    }
}

// Get All Subjects (Mapel)
$subjects = getFilteredSubjects($pdo);

// Get Active Semester
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'];
$semester_aktif = $school_profile['semester'];

// Check if selected class is grade 6 (kelas 6)
$is_grade_6 = false;
if ($selected_class_id) {
    $stmt = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas = ?");
    $stmt->execute([$selected_class_id]);
    $kelas_name = $stmt->fetchColumn();
    if ($kelas_name && preg_match('/\b(6|vi)\b/i', (string)$kelas_name)) {
        $is_grade_6 = true;
    }
}

if ($selected_jenis) {
    $jenis_exam = ['Pra Ujian Madrasah', 'Ujian Madrasah', 'Ujian Praktik', 'Pra Ujian', 'Ujian'];
    $is_exam_jenis = in_array($selected_jenis, $jenis_exam, true);
    $can_view_exam_rekap = $is_admin_view || ($user_role === 'guru' || $user_role === 'wali');
    if ($is_exam_jenis && (!$is_grade_6 || !$can_view_exam_rekap)) {
        $q = [];
        if (isset($_GET['session_type'])) $q['session_type'] = $_GET['session_type'];
        if (isset($_GET['kelas'])) $q['kelas'] = $_GET['kelas'];
        if (isset($_GET['tipe'])) $q['tipe'] = $_GET['tipe'];
        header('Location: rekap_nilai.php' . (!empty($q) ? ('?' . http_build_query($q)) : ''));
        exit;
    }
}

// Data Fetching
$students = [];
$rekap_data = [];
$progress_total = 0;
$progress_filled = 0;
$progress_missing = 0;
$progress_percent = 0;
$total_filled_cells = 0;
$total_possible_cells = 0;
$cell_progress_percent = 0;

if ($selected_class && $selected_jenis) {
    // Map new exam type names to database values
    $exam_type_map = [
        'PTS' => 'UTS',
        'PAS' => 'UAS',
        'PAT' => 'PAT',
        'Pra Ujian Madrasah' => 'Pra Ujian',
        'Ujian Madrasah' => 'Ujian',
        'Ujian Praktik' => 'Ujian Praktik'
    ];
    $db_jenis = $exam_type_map[$selected_jenis] ?? $selected_jenis;

    if (in_array($db_jenis, ['Pra Ujian', 'Ujian'], true)) {
        $subjects = array_values(array_filter($subjects, function ($m) {
            $nama = strtolower(trim((string)($m['nama_mapel'] ?? '')));
            $nama = preg_replace('/\s+/', ' ', $nama);
            return $nama !== 'tajwid' && $nama !== 'bta';
        }));
    }

    if ($db_jenis === 'Ujian Praktik') {
        $stmt = $pdo->prepare("
            SELECT DISTINCT id_mapel
            FROM tb_nilai_semester
            WHERE id_kelas = ?
              AND jenis_semester = ?
              AND tahun_ajaran = ?
              AND semester = ?
              AND (
                COALESCE(nilai_asli, 0) > 0
                OR COALESCE(nilai_remidi, 0) > 0
                OR COALESCE(nilai_jadi, 0) > 0
              )
        ");
        $stmt->execute([$selected_class_id, $db_jenis, $tahun_ajaran, $semester_aktif]);
        $filled_mapel_ids = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $subjects = array_values(array_filter($subjects, function ($m) use ($filled_mapel_ids) {
            return in_array((string)($m['id_mapel'] ?? ''), $filled_mapel_ids, true);
        }));
    }
    
    // Get Students
    $stmt = $pdo->prepare("SELECT * FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
    $stmt->execute([$selected_class_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $progress_total = count($subjects);
    $mapel_has_data = [];
    foreach ($subjects as $mapel) {
        $mapel_has_data[$mapel['id_mapel']] = false;
    }

    // Fetch Grades
    $kelas_sum = [];
    $kelas_count = [];
    foreach ($subjects as $mapel) {
        $kelas_sum[$mapel['id_mapel']] = 0;
        $kelas_count[$mapel['id_mapel']] = 0;
    }

    foreach ($students as $student) {
        $total_nilai = 0;
        $count_mapel = 0;
        
        foreach ($subjects as $mapel) {
            $nilai = 0;
            $is_filled = false;
            
            if ($selected_jenis == 'Harian') {
                // Logic for Nilai Harian (Average of all PH columns)
                $stmt = $pdo->prepare("
                    SELECT d.* 
                    FROM tb_nilai_harian_detail d
                    JOIN tb_nilai_harian_header h ON d.id_header = h.id_header
                    WHERE h.id_kelas = ? AND h.id_mapel = ?
                    AND h.tahun_ajaran = ? AND h.semester = ?
                    AND d.id_siswa = ?
                ");
                $stmt->execute([$selected_class_id, $mapel['id_mapel'], $tahun_ajaran, $semester_aktif, $student['id_siswa']]);
                $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($details)) {
                    $anyFilled = false;
                    $sum = 0;
                    $count = 0;
                    foreach ($details as $d) {
                        $val = ($selected_tipe == 'nilai_asli') ? $d['nilai'] : $d['nilai_jadi'];
                        $anyVal = max((float)($d['nilai'] ?? 0), (float)($d['nilai_jadi'] ?? 0));
                        if ($anyVal > 0) {
                            $anyFilled = true;
                        }
                        if ($val > 0) {
                            $sum += $val;
                            $count++;
                        }
                    }
                    if ($count > 0) {
                        $is_filled = $anyFilled;
                        $nilai = round($sum / $count);
                    }
                }
            } else {
                // Logic for Semester (PTS, PAS, PAT, etc)
                $stmt = $pdo->prepare("
                    SELECT * FROM tb_nilai_semester 
                    WHERE id_kelas = ? AND id_mapel = ? 
                    AND jenis_semester = ? AND tahun_ajaran = ? AND semester = ?
                    AND id_siswa = ?
                ");
                $stmt->execute([$selected_class_id, $mapel['id_mapel'], $db_jenis, $tahun_ajaran, $semester_aktif, $student['id_siswa']]);
                $grade = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($grade) {
                    $a = (float)($grade['nilai_asli'] ?? 0);
                    $r = (float)($grade['nilai_remidi'] ?? 0);
                    $j = (float)($grade['nilai_jadi'] ?? 0);
                    $is_filled = max($a, $r, $j) > 0;
                    $val = ($selected_tipe == 'nilai_asli') ? $grade['nilai_asli'] : $grade['nilai_jadi'];
                    $nilai = $val > 0 ? (float)$val : 0;
                }
            }

            $rekap_data[$student['id_siswa']][$mapel['id_mapel']] = $nilai;
            if ($is_filled) {
                $mapel_has_data[$mapel['id_mapel']] = true;
            }
            
            if ($nilai > 0) {
                $kelas_sum[$mapel['id_mapel']] += $nilai;
                $kelas_count[$mapel['id_mapel']]++;
                $total_nilai += $nilai;
                $count_mapel++;
            }
        }
        
        $rekap_data[$student['id_siswa']]['total'] = $total_nilai;
        $rekap_data[$student['id_siswa']]['rerata'] = $count_mapel > 0 ? round($total_nilai / $count_mapel, 1) : 0;
    }
    
    // Calculate Ranking
    $averages = [];
    foreach ($students as $student) {
        $averages[$student['id_siswa']] = $rekap_data[$student['id_siswa']]['rerata'];
    }
    arsort($averages);
    
    $rank = 1;
    $prev_avg = -1;
    $real_rank = 1;
    
    foreach ($averages as $id_siswa => $avg) {
        if ($avg != $prev_avg) {
            $rank = $real_rank;
        }
        $rekap_data[$id_siswa]['ranking'] = $rank;
        $prev_avg = $avg;
        $real_rank++;
    }

    $kelas_avg = [];
    foreach ($subjects as $mapel) {
        $id_mapel = $mapel['id_mapel'];
        if (($kelas_count[$id_mapel] ?? 0) > 0) {
            $avg_mapel = round(($kelas_sum[$id_mapel] ?? 0) / $kelas_count[$id_mapel], 1);
            $kelas_avg[$id_mapel] = $avg_mapel;
        } else {
            $kelas_avg[$id_mapel] = 0;
        }
    }

    $progress_filled = 0;
    foreach ($mapel_has_data as $has) {
        if ($has) $progress_filled++;
    }
    $progress_missing = max(0, $progress_total - $progress_filled);
    $progress_percent = $progress_total > 0 ? round(($progress_filled / $progress_total) * 100, 1) : 0;

    // Detailed progress: Total cells filled vs Total cells possible
    $total_possible_cells = count($students) * count($subjects);
    $total_filled_cells = 0;
    foreach ($rekap_data as $student_id => $student_grades) {
        if (!is_array($student_grades)) continue;
        foreach ($subjects as $mapel) {
            if (isset($student_grades[$mapel['id_mapel']]) && $student_grades[$mapel['id_mapel']] > 0) {
                $total_filled_cells++;
            }
        }
    }
    $cell_progress_percent = $total_possible_cells > 0 ? round(($total_filled_cells / $total_possible_cells) * 100, 1) : 0;
}

// Calculate Summary Progress for all types (shown when no class is selected)
$summary_progress = [];
if (!$selected_class_id || !$selected_jenis) {
    $accessible_class_ids = array_column($classes, 'id_kelas');
    if (!empty($accessible_class_ids)) {
        $placeholders = str_repeat('?,', count($accessible_class_ids) - 1) . '?';
        
        // 1. Get total academic subjects
        $stmt = $pdo->query("SELECT COUNT(*) FROM tb_mata_pelajaran WHERE jenis_mapel = 'Akademik'");
        $total_academic_subjects = (int)$stmt->fetchColumn();
        
        // Count for Ujian (excluding Tajwid & BTA)
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM tb_mata_pelajaran 
            WHERE jenis_mapel = 'Akademik' 
            AND LOWER(TRIM(nama_mapel)) NOT IN ('tajwid', 'bta')
        ");
        $total_ujian_subjects = (int)$stmt->fetchColumn();

        $total_possible_mapel_kelas = $total_academic_subjects * count($accessible_class_ids);
        $total_possible_ujian_kelas = $total_ujian_subjects * count($accessible_class_ids);

        // 2. Get filled counts for Semester types (Fetch for all semesters in current TA)
        $stmt = $pdo->prepare("
            SELECT jenis_semester, semester, COUNT(DISTINCT id_mapel, id_kelas) as filled 
            FROM tb_nilai_semester 
            WHERE id_kelas IN ($placeholders) AND tahun_ajaran = ?
            AND (COALESCE(nilai_asli, 0) > 0 OR COALESCE(nilai_remidi, 0) > 0 OR COALESCE(nilai_jadi, 0) > 0)
            GROUP BY jenis_semester, semester
        ");
        $params_ta = array_merge($accessible_class_ids, [$tahun_ajaran]);
        $stmt->execute($params_ta);
        $semester_data_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $semester_filled = [];
        foreach ($semester_data_raw as $row) {
            $semester_filled[$row['jenis_semester']][$row['semester']] = $row['filled'];
        }

        // 3. Get filled counts for Harian (Current Semester)
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT id_mapel, id_kelas) 
            FROM tb_nilai_harian_header 
            WHERE id_kelas IN ($placeholders) AND tahun_ajaran = ? AND semester = ?
        ");
        $params_cur = array_merge($accessible_class_ids, [$tahun_ajaran, $semester_aktif]);
        $stmt->execute($params_cur);
        $harian_filled = (int)$stmt->fetchColumn();

        // 4. Get filled counts for Kokurikuler (Current Semester)
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT id_mapel, id_kelas) 
            FROM tb_nilai_kokurikuler_header 
            WHERE id_kelas IN ($placeholders) AND tahun_ajaran = ? AND semester = ?
        ");
        $stmt->execute($params_cur);
        $kokurikuler_filled = (int)$stmt->fetchColumn();

        // Map to display types
        $display_types = [
            'Harian' => ['label' => 'Nilai Harian', 'filled' => $harian_filled, 'total' => $total_possible_mapel_kelas, 'color' => 'bg-info'],
            'PTS' => ['label' => 'UTS/PTS', 'filled' => $semester_filled['UTS'][$semester_aktif] ?? 0, 'total' => $total_possible_mapel_kelas, 'color' => 'bg-primary'],
        ];

        // Always show PAS if it has data or if we are in Semester 2 (to see progress of previous semester)
        // Or just always show it as it's a major category
        $display_types['PAS'] = [
            'label' => 'UAS/PAS', 
            'filled' => $semester_filled['UAS']['Semester 1'] ?? 0, 
            'total' => $total_possible_mapel_kelas, 
            'color' => 'bg-success'
        ];

        // Show PAT only if we are in Semester 2
        if ($semester_aktif == 'Semester 2' || !empty($semester_filled['PAT']['Semester 2'])) {
            $display_types['PAT'] = [
                'label' => 'UKK/PAT', 
                'filled' => $semester_filled['PAT']['Semester 2'] ?? 0, 
                'total' => $total_possible_mapel_kelas, 
                'color' => 'bg-success'
            ];
        }

        $display_types['Kokurikuler'] = ['label' => 'Kokurikuler', 'filled' => $kokurikuler_filled, 'total' => $total_possible_mapel_kelas, 'color' => 'bg-warning'];

        // Add Grade 6 specific types if any accessible class is Grade 6
        $grade_6_class_ids = [];
        foreach ($classes as $cls) {
            if (preg_match('/\b(6|vi)\b/i', (string)$cls['nama_kelas'])) {
                $grade_6_class_ids[] = $cls['id_kelas'];
            }
        }

        if (!empty($grade_6_class_ids)) {
            $total_possible_ujian_6 = $total_ujian_subjects * count($grade_6_class_ids);
            
            $display_types['Pra Ujian'] = ['label' => 'Pra Ujian', 'filled' => $semester_filled['Pra Ujian'][$semester_aktif] ?? 0, 'total' => $total_possible_ujian_6, 'color' => 'bg-secondary'];
            $display_types['Ujian'] = ['label' => 'Ujian Madrasah', 'filled' => $semester_filled['Ujian'][$semester_aktif] ?? 0, 'total' => $total_possible_ujian_6, 'color' => 'bg-dark'];
            
            // Ujian Praktik is special - total is the same as filled for summary, as it only shows what's filled
            $filled_praktik = $semester_filled['Ujian Praktik'][$semester_aktif] ?? 0;
            $display_types['Ujian Praktik'] = ['label' => 'Ujian Praktik', 'filled' => $filled_praktik, 'total' => $filled_praktik, 'color' => 'bg-danger'];
        }

        foreach ($display_types as $key => $data) {
            $percent = $data['total'] > 0 ? round(($data['filled'] / $data['total']) * 100, 1) : ($data['filled'] > 0 ? 100 : 0);
            $summary_progress[$key] = array_merge($data, ['percent' => $percent]);
        }
    }
}

require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<style>
    /* Sticky Columns and Header for Rekap Table */
    .table-responsive {
        max-height: 80vh;
        overflow: auto;
    }
    .sticky-col {
        position: sticky !important;
        background-color: #fff !important;
        z-index: 10;
        border-right: 1px solid #dee2e6;
    }
    .sticky-col-1 {
        left: 0;
        width: 50px;
        min-width: 50px;
    }
    .sticky-col-2 {
        left: 50px;
        min-width: 200px;
        max-width: 250px;
    }
    
    /* Sticky Header */
    thead th {
        position: sticky !important;
        top: 0;
        background-color: #f8f9fa !important;
        z-index: 15;
        box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.1);
    }
    
    /* Sticky Header + Sticky Column Intersection (Top Left Corners) */
    thead th.sticky-col {
        z-index: 25 !important; /* Highest priority */
    }
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><?= $page_title ?></h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item"><a href="#">Nilai Siswa</a></div>
                <div class="breadcrumb-item">Rekap Nilai Siswa</div>
            </div>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="" class="mb-4">
                        <div class="row">
                            <?php if (count($classes) > 1): ?>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Kelas</label>
                                    <select name="kelas" class="form-control select2" required onchange="this.form.submit()">
                                        <option value="">-- Pilih Kelas --</option>
                                        <?php foreach ($classes as $cls): ?>
                                            <option value="<?= $cls['id_kelas'] ?>" <?= $selected_class_id == $cls['id_kelas'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cls['nama_kelas']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <?php else: ?>
                                <input type="hidden" name="kelas" value="<?= $classes[0]['id_kelas'] ?? '' ?>">
                            <?php endif; ?>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Jenis Penilaian</label>
                                    <select name="jenis" class="form-control" required onchange="this.form.submit()">
                                        <option value="">-- Pilih Jenis --</option>
                                        <option value="Harian" <?= $selected_jenis == 'Harian' ? 'selected' : '' ?>>Nilai Harian (Rerata)</option>
                                        <option value="PTS" <?= $selected_jenis == 'PTS' ? 'selected' : '' ?>>Penilaian Tengah Semester (PTS)</option>
                                        <option value="PAS" <?= $selected_jenis == 'PAS' ? 'selected' : '' ?>>Penilaian Akhir Semester (PAS)</option>
                                        <option value="PAT" <?= $selected_jenis == 'PAT' ? 'selected' : '' ?>>Penilaian Akhir Tahun (PAT)</option>
                                        <?php if ($is_grade_6 && ($is_admin_view || $user_role === 'guru' || $user_role === 'wali')): ?>
                                        <option value="Pra Ujian Madrasah" <?= $selected_jenis == 'Pra Ujian Madrasah' ? 'selected' : '' ?>>Nilai Pra Ujian Madrasah</option>
                                        <option value="Ujian Madrasah" <?= $selected_jenis == 'Ujian Madrasah' ? 'selected' : '' ?>>Nilai Ujian Madrasah</option>
                                        <option value="Ujian Praktik" <?= $selected_jenis == 'Ujian Praktik' ? 'selected' : '' ?>>Nilai Ujian Praktik</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Tipe Data</label>
                                    <select name="tipe" class="form-control" onchange="this.form.submit()">
                                        <option value="nilai_asli" <?= $selected_tipe == 'nilai_asli' ? 'selected' : '' ?>>Nilai Asli</option>
                                        <option value="nilai_jadi" <?= $selected_tipe == 'nilai_jadi' ? 'selected' : '' ?>>Nilai Jadi</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </form>

                    <?php if ($selected_class && $selected_jenis): ?>
                        <div class="row mb-3">
                            <div class="col-md-8">
                                <div class="card border mb-0">
                                    <div class="card-body p-3">
                                        <div class="row align-items-center">
                                            <div class="col-md-6 border-right">
                                                <div class="d-flex justify-content-between mb-1">
                                                    <strong>Progres Mapel:</strong>
                                                    <span class="text-muted small"><?= (int)$progress_filled ?>/<?= (int)$progress_total ?> Mapel Terisi</span>
                                                </div>
                                                <div class="progress" style="height: 12px;">
                                                    <div class="progress-bar bg-info" role="progressbar" style="width: <?= (float)$progress_percent ?>%;" aria-valuenow="<?= (float)$progress_percent ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                </div>
                                                <div class="text-right small mt-1"><?= (float)$progress_percent ?>%</div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="d-flex justify-content-between mb-1">
                                                    <strong>Progres Nilai Siswa:</strong>
                                                    <span class="text-muted small"><?= (int)$total_filled_cells ?>/<?= (int)$total_possible_cells ?> Nilai</span>
                                                </div>
                                                <div class="progress" style="height: 12px;">
                                                    <div class="progress-bar bg-success" role="progressbar" style="width: <?= (float)$cell_progress_percent ?>%;" aria-valuenow="<?= (float)$cell_progress_percent ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                </div>
                                                <div class="text-right small mt-1"><?= (float)$cell_progress_percent ?>%</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-right d-flex align-items-center justify-content-end">
                                <?php if (count($subjects) > 0): ?>
                                    <div class="btn-group">
                                        <a href="export_rekap_nilai_excel?session_type=<?= $_SESSION['level'] ?>&kelas=<?= $selected_class_id ?>&jenis=<?= urlencode($selected_jenis) ?>&tipe=<?= $selected_tipe ?>" target="_blank" class="btn btn-success">
                                            <i class="fas fa-file-excel"></i> Export Excel
                                        </a>
                                        <a href="export_rekap_nilai_pdf?session_type=<?= $_SESSION['level'] ?>&kelas=<?= $selected_class_id ?>&jenis=<?= urlencode($selected_jenis) ?>&tipe=<?= $selected_tipe ?>" target="_blank" class="btn btn-danger">
                                            <i class="fas fa-file-pdf"></i> Export PDF
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (count($subjects) === 0): ?>
                            <div class="alert alert-warning mb-0">
                                Belum ada mata pelajaran yang terisi untuk jenis penilaian ini.
                            </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-sm" id="rekapTable">
                                <thead>
                                    <tr>
                                        <th class="text-center align-middle sticky-col sticky-col-1" rowspan="2">No</th>
                                        <th class="align-middle sticky-col sticky-col-2" rowspan="2">Nama Siswa</th>
                                        <th class="text-center" colspan="<?= count($subjects) ?>">Mata Pelajaran</th>
                                        <th class="text-center align-middle" rowspan="2" width="7%">Jumlah</th>
                                        <th class="text-center align-middle" rowspan="2" width="7%">Rerata</th>
                                        <th class="text-center align-middle" rowspan="2" width="7%">Rank</th>
                                    </tr>
                                    <tr>
                                        <?php foreach ($subjects as $mapel): ?>
                                            <th class="text-center align-bottom" style="font-size: 11px; min-width: 80px; height: auto; white-space: normal;">
                                                <?= htmlspecialchars($mapel['nama_mapel']) ?>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $no = 1;
                                    foreach ($students as $student): 
                                        $data = $rekap_data[$student['id_siswa']] ?? [];
                                    ?>
                                        <tr>
                                            <td class="text-center sticky-col sticky-col-1"><?= $no++ ?></td>
                                            <td class="sticky-col sticky-col-2"><?= htmlspecialchars($student['nama_siswa']) ?></td>
                                            <?php foreach ($subjects as $mapel): ?>
                                                <td class="text-center">
                                                    <?php 
                                                    $val = $data[$mapel['id_mapel']] ?? 0;
                                                    echo $val > 0 ? $val : '-';
                                                    ?>
                                                </td>
                                            <?php endforeach; ?>
                                            <td class="text-center font-weight-bold"><?= $data['total'] ?? 0 ?></td>
                                            <td class="text-center font-weight-bold"><?= $data['rerata'] ?? 0 ?></td>
                                            <td class="text-center font-weight-bold badge-secondary"><?= $data['ranking'] ?? '-' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!empty($students)): ?>
                                        <tr class="table-secondary font-weight-bold">
                                            <td class="text-center sticky-col sticky-col-1"></td>
                                            <td class="sticky-col sticky-col-2">Rerata Kelas</td>
                                            <?php foreach ($subjects as $mapel): ?>
                                                <td class="text-center">
                                                    <?php
                                                    $id_mapel = $mapel['id_mapel'];
                                                    $val = $kelas_avg[$id_mapel] ?? 0;
                                                    echo $val > 0 ? $val : '-';
                                                    ?>
                                                </td>
                                            <?php endforeach; ?>
                                            <td class="text-center">-</td>
                                            <td class="text-center">-</td>
                                            <td class="text-center">-</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-info mb-4">
                            Silakan pilih <strong>Kelas</strong> dan <strong>Jenis Penilaian</strong> untuk menampilkan rekap nilai secara detail.
                        </div>

                        <?php if (!empty($summary_progress)): ?>
                            <div class="row">
                                <div class="col-12 mb-3">
                                    <h6 class="text-muted text-uppercase small font-weight-bold">Ringkasan Progres Pengisian Nilai (Semua Kelas Anda)</h6>
                                </div>
                                <?php foreach ($summary_progress as $type => $stats): ?>
                                    <div class="col-md-6 col-lg-4 mb-4">
                                        <div class="card card-statistic-1 border shadow-none mb-0">
                                            <div class="card-icon <?= $stats['color'] ?>">
                                                <i class="fas fa-chart-line"></i>
                                            </div>
                                            <div class="card-wrap">
                                                <div class="card-header pb-1">
                                                    <h4><?= htmlspecialchars($stats['label']) ?></h4>
                                                </div>
                                                <div class="card-body pt-0">
                                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                                        <span class="font-weight-bold" style="font-size: 16px;"><?= $stats['percent'] ?>%</span>
                                                        <span class="text-muted small"><?= $stats['filled'] ?>/<?= $stats['total'] ?> Mapel</span>
                                                    </div>
                                                    <div class="progress" style="height: 6px;">
                                                        <div class="progress-bar <?= $stats['color'] ?>" role="progressbar" style="width: <?= $stats['percent'] ?>%" aria-valuenow="<?= $stats['percent'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<?php require_once '../templates/footer.php'; ?>

<script>
$(document).ready(function() {
    $('.select2').select2();
    if ($('#rekapTable').length) {
        $('#rekapTable').DataTable({
            "pageLength": 50,
            "scrollX": true,
            "fixedColumns": {
                "leftColumns": 2
            }
        });
    }
});
</script>
