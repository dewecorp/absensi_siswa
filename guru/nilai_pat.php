<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['guru', 'wali', 'kepala_madrasah', 'tata_usaha', 'admin'])) {
    redirect('../login.php');
}

$page_title = 'Nilai Akhir Tahun';
$jenis_semester = 'PAT'; // Set this based on the file type
$user_role = $_SESSION['level'];
$is_admin_view = in_array($user_role, ['kepala_madrasah', 'tata_usaha', 'admin']);
$can_edit = !$is_admin_view;

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
    $classes = getTeacherAccessibleClasses($pdo, $id_guru);
}

// Fetch subjects
$subjects = [];
if ($is_admin_view) {
    $subjects = getFilteredSubjects($pdo);
} else {
    $stmt = $pdo->prepare("
        SELECT DISTINCT mp.* 
        FROM tb_mata_pelajaran mp
        JOIN tb_jadwal_pelajaran jp ON mp.id_mapel = jp.mapel_id
        WHERE jp.guru_id = ?
        AND jp.jenis = 'Reguler'
        AND mp.jenis_mapel = 'Akademik'
        AND mp.nama_mapel NOT LIKE '%Asmaul Husna%'
        AND mp.nama_mapel NOT LIKE '%Upacara%'
        AND mp.nama_mapel NOT LIKE '%Istirahat%'
        AND mp.nama_mapel NOT LIKE '%Kepramukaan%'
        AND mp.nama_mapel NOT LIKE '%Ekstrakurikuler%'
        AND mp.nama_mapel NOT LIKE '%Ramadhanku%'
        ORDER BY mp.nama_mapel ASC
    ");
    $stmt->execute([$id_guru]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($user_role === 'wali' && empty($subjects)) {
        $subjects = getFilteredSubjects($pdo);
    }
}

$selected_class_id = isset($_GET['kelas']) ? $_GET['kelas'] : null;
$selected_mapel_id = isset($_GET['mapel']) ? $_GET['mapel'] : null;
$selected_class = null;
$selected_mapel = null;

if (count($classes) == 1 && !$selected_class_id) {
    $selected_class_id = $classes[0]['id_kelas'];
}

if ($selected_class_id) {
    foreach ($classes as $cls) {
        if ($cls['id_kelas'] == $selected_class_id) {
            $selected_class = $cls;
            break;
        }
    }
}

if ($selected_mapel_id) {
    foreach ($subjects as $mpl) {
        if ($mpl['id_mapel'] == $selected_mapel_id) {
            $selected_mapel = $mpl;
            break;
        }
    }
}

// Get KKTP
$kktp = isset($selected_mapel['kktp']) ? $selected_mapel['kktp'] : 0;

// Get active semester info
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'];
$semester_aktif = $school_profile['semester'];

// Fetch students and grades
$students = [];
$grades_data = [];
$nilai_min_target_setting = null;
$nilai_max_target_setting = null;

if ($selected_class && $selected_mapel) {
    // Get students
    $stmt = $pdo->prepare("SELECT * FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
    $stmt->execute([$selected_class_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get grades
    $stmt = $pdo->prepare("
        SELECT * FROM tb_nilai_semester 
        WHERE id_mapel = ? 
        AND id_kelas = ? 
        AND jenis_semester = ? 
        AND tahun_ajaran = ? 
        AND semester = ?
    ");
    $stmt->execute([$selected_mapel_id, $selected_class_id, $jenis_semester, $tahun_ajaran, $semester_aktif]);
    $fetched_grades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($fetched_grades as $g) {
        $grades_data[$g['id_siswa']] = $g;
    }

    $setting = get_nilai_semester_setting_minmax($pdo, (int)$selected_class_id, (int)$selected_mapel_id, (string)$jenis_semester, (string)$tahun_ajaran, (string)$semester_aktif);
    $nilai_min_target_setting = $setting['nilai_min_target'];
    $nilai_max_target_setting = $setting['nilai_max_target'];
}

require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<style>
    /* Reset Table Styling - No Internal Scroll */
    .table-responsive {
        overflow-x: auto !important;
        overflow-y: visible !important;
        max-height: none !important;
    }

    table {
        width: 100% !important;
        border-collapse: collapse !important;
    }

    thead th {
        background-color: #f8f9fa !important;
        vertical-align: middle;
        padding: 8px !important;
        border: 1px solid #dee2e6 !important;
        text-align: center;
    }

    /* Desktop Column Widths */
    .sticky-col-1 { width: 50px; min-width: 50px; }
    .sticky-col-2 { width: 180px; min-width: 180px; }
    .header-asli { width: 120px; }

    /* Input styling */
    .grade-input, .input-nilai-asli, .input-nilai-remidi {
        min-width: 60px;
        max-width: 80px;
        display: inline-block !important;
        margin: 0 auto;
    }

    /* Mobile adjustments */
    @media (max-width: 768px) {
        thead th, tbody td {
            font-size: 0.8em !important;
            padding: 4px 2px !important;
        }
        .grade-input {
            min-width: 40px !important;
            max-width: 50px !important;
            height: 28px !important;
            font-size: 0.9em !important;
        }

        /* Sticky Name Column for Mobile */
        .sticky-col {
            position: sticky !important;
            background-color: #ffffff !important;
            z-index: 10;
            box-shadow: 1px 0 3px rgba(0,0,0,0.1);
        }
        .sticky-col-1 {
            left: 0;
            width: 30px !important;
            min-width: 30px !important;
        }
        .sticky-col-2 {
            left: 30px;
            width: 65px !important;
            min-width: 65px !important;
            z-index: 11;
            white-space: normal !important;
            line-height: 1.1;
            word-break: break-word;
            padding: 4px !important;
        }
        .header-asli {
            min-width: 50px !important;
            width: 50px !important;
            padding: 2px !important;
        }
        .input-nilai-asli, .input-nilai-remidi {
            max-width: 40px !important;
            padding: 2px !important;
            height: 26px !important;
        }
        thead th.sticky-col {
            z-index: 20 !important;
            background-color: #f8f9fa !important;
            text-align: center !important;
        }
    }
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><?= $page_title ?></h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item"><a href="#">Nilai Siswa</a></div>
                <div class="breadcrumb-item">Nilai Akhir Tahun</div>
            </div>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-body">
                    <!-- Filter Form -->
                    <form method="GET" action="" class="mb-4">
                        <div class="row">
                            <?php if (count($classes) > 1): ?>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Kelas</label>
                                    <select name="kelas" class="form-control" onchange="this.form.submit()">
                                        <option value="">Pilih Kelas</option>
                                        <?php foreach ($classes as $cls): ?>
                                            <option value="<?= $cls['id_kelas'] ?>" <?= $selected_class_id == $cls['id_kelas'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cls['nama_kelas']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <?php else: ?>
                                <input type="hidden" name="kelas" value="<?= $selected_class_id ?>">
                            <?php endif; ?>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Mata Pelajaran</label>
                                    <select name="mapel" class="form-control" onchange="this.form.submit()">
                                        <option value="">Pilih Mata Pelajaran</option>
                                        <?php foreach ($subjects as $mpl): ?>
                                            <option value="<?= $mpl['id_mapel'] ?>" <?= $selected_mapel_id == $mpl['id_mapel'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($mpl['nama_mapel']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </form>

                    <?php if ($selected_class && $selected_mapel): ?>
                        <!-- ==========================================
                             DESKTOP VIEW (TABLE UNCHANGED)
                             ========================================== -->
                        <div class="nilai-desktop-view">
                        <div class="mb-3 text-right">
                            <div class="d-flex justify-content-end align-items-center" style="gap: 10px; flex-wrap: wrap;">
                                <div class="d-flex align-items-center" style="gap: 8px;">
                                    <span class="badge badge-light">KKTP: <?= (float)$kktp ?></span>
                                    <input type="number" class="form-control form-control-sm text-center" id="nilai_min_target" style="width: 90px;" placeholder="Min" value="<?= $nilai_min_target_setting !== null ? (float)$nilai_min_target_setting : '' ?>" <?= $can_edit ? '' : 'disabled' ?>>
                                    <input type="number" class="form-control form-control-sm text-center" id="nilai_max_target" style="width: 90px;" placeholder="Max" value="<?= $nilai_max_target_setting !== null ? (float)$nilai_max_target_setting : '' ?>" <?= $can_edit ? '' : 'disabled' ?>>
                                </div>
                                <div class="btn-group">
                                <a href="export_nilai_semester_excel?session_type=<?= $_SESSION['level'] ?>&kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>&jenis=<?= urlencode($jenis_semester) ?>" target="_blank" class="btn btn-success">
                                    <i class="fas fa-file-excel"></i> Export Excel
                                </a>
                                <a href="export_nilai_semester_pdf?session_type=<?= $_SESSION['level'] ?>&kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>&jenis=<?= urlencode($jenis_semester) ?>" target="_blank" class="btn btn-danger">
                                    <i class="fas fa-file-pdf"></i> Export PDF
                                </a>
                                </div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th class="text-center sticky-col sticky-col-1" style="vertical-align: middle;">No</th>
                                        <th class="text-center sticky-col sticky-col-2" style="vertical-align: middle;">Nama Siswa</th>
                                        <th class="text-center header-asli" style="vertical-align: middle;">
                                            <div class="d-flex align-items-center justify-content-center" style="gap: 4px;">
                                                <span>Asli</span>
                                                <?php if ($can_edit): ?>
                                                    <button class="btn btn-sm btn-warning" id="btn-edit-all" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-success d-none" id="btn-save-all" title="Simpan">
                                                        <i class="fas fa-save"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </th>
                                        <th style="width: 100px; vertical-align: middle;" class="text-center">Remidi</th>
                                        <th style="width: 100px; vertical-align: middle;" class="text-center">Jadi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $no = 1;
                                    
                                    // Initialize Min/Max variables
                                    $min_asli = null; $max_asli = null;
                                    $min_remidi = null; $max_remidi = null;
                                    $min_jadi = null; $max_jadi = null;

                                    foreach ($students as $student): 
                                        $id_siswa = $student['id_siswa'];
                                        $grade = $grades_data[$id_siswa] ?? null;
                                        $nilai_asli = $grade ? $grade['nilai_asli'] : 0;
                                        $nilai_remidi = $grade ? $grade['nilai_remidi'] : 0;
                                        $nilai_jadi = $grade ? $grade['nilai_jadi'] : 0;
                                        
                                        // Update Min/Max Stats (Only consider non-zero values)
                                        if ($nilai_asli > 0) {
                                            if ($min_asli === null || $nilai_asli < $min_asli) $min_asli = $nilai_asli;
                                            if ($max_asli === null || $nilai_asli > $max_asli) $max_asli = $nilai_asli;
                                        }
                                        if ($nilai_remidi > 0) {
                                            if ($min_remidi === null || $nilai_remidi < $min_remidi) $min_remidi = $nilai_remidi;
                                            if ($max_remidi === null || $nilai_remidi > $max_remidi) $max_remidi = $nilai_remidi;
                                        }
                                        if ($nilai_jadi > 0) {
                                            if ($min_jadi === null || $nilai_jadi < $min_jadi) $min_jadi = $nilai_jadi;
                                            if ($max_jadi === null || $nilai_jadi > $max_jadi) $max_jadi = $nilai_jadi;
                                        }
                                    ?>
                                        <tr data-id-siswa="<?= $id_siswa ?>">
                                            <td class="text-center sticky-col sticky-col-1"><?= $no++ ?></td>
                                            <td class="sticky-col sticky-col-2"><?= htmlspecialchars($student['nama_siswa']) ?></td>
                                            <td class="text-center">
                                                <input type="number" class="form-control form-control-sm input-nilai-asli text-center"
                                                       value="<?= $nilai_asli > 0 ? (float)$nilai_asli : '' ?>" style="max-width: 90px;" disabled>
                                            </td>
                                            <td class="text-center">
                                                <input type="number" class="form-control form-control-sm input-nilai-remidi text-center"
                                                       value="<?= $nilai_remidi > 0 ? (float)$nilai_remidi : '' ?>" style="max-width: 90px;" disabled>
                                            </td>
                                            <td class="text-center bg-light">
                                                <span class="display-nilai-jadi font-weight-bold"><?= $nilai_jadi > 0 ? (float)$nilai_jadi : '-' ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- Footer Stats -->
                                    <tr class="bg-light font-weight-bold">
                                        <td colspan="2" class="text-right">Nilai Tertinggi</td>
                                        <td class="text-center text-success" id="max-asli"><?= $max_asli !== null ? (float)$max_asli : '-' ?></td>
                                        <td class="text-center text-success" id="max-remidi"><?= $max_remidi !== null ? (float)$max_remidi : '-' ?></td>
                                        <td class="text-center text-success" id="max-jadi"><?= $max_jadi !== null ? (float)$max_jadi : '-' ?></td>
                                    </tr>
                                    <tr class="bg-light font-weight-bold">
                                        <td colspan="2" class="text-right">Nilai Terendah</td>
                                        <td class="text-center text-danger" id="min-asli"><?= $min_asli !== null ? (float)$min_asli : '-' ?></td>
                                        <td class="text-center text-danger" id="min-remidi"><?= $min_remidi !== null ? (float)$min_remidi : '-' ?></td>
                                        <td class="text-center text-danger" id="min-jadi"><?= $min_jadi !== null ? (float)$min_jadi : '-' ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        </div> <!-- End .nilai-desktop-view -->

                        <!-- ==========================================
                             MOBILE APP VIEW (NO HORIZONTAL SCROLL)
                             ========================================== -->
                        <div class="nilai-mobile-app">
                            <!-- Hero Info Card -->
                            <div class="nilai-hero-card">
                                <div class="nilai-hero-top">
                                    <span class="nilai-hero-badge">
                                        <i class="fas fa-book-open"></i> TA <?= htmlspecialchars($tahun_ajaran) ?> &bull; Sem <?= htmlspecialchars($semester_aktif) ?>
                                    </span>
                                    <?php if ($kktp > 0): ?>
                                    <span class="nilai-kktp-badge">
                                        <i class="fas fa-bullseye mr-1"></i>KKTP: <?= (float)$kktp ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <div class="nilai-hero-title"><?= htmlspecialchars($selected_mapel['nama_mapel']) ?></div>
                                <div class="nilai-hero-sub">
                                    <span><i class="fas fa-chalkboard mr-1"></i><?= htmlspecialchars($selected_class['nama_kelas']) ?></span>
                                    <span>&bull;</span>
                                    <span><?= count($students) ?> Siswa</span>
                                    <span>&bull;</span>
                                    <span><?= htmlspecialchars($page_title) ?></span>
                                </div>

                                <div class="nilai-actions-bar">
                                    <a href="export_nilai_semester_excel?session_type=<?= $_SESSION['level'] ?>&kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>&jenis=<?= urlencode($jenis_semester) ?>" target="_blank" class="nilai-action-chip">
                                        <i class="fas fa-file-excel text-success"></i> Excel
                                    </a>
                                    <a href="export_nilai_semester_pdf?session_type=<?= $_SESSION['level'] ?>&kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>&jenis=<?= urlencode($jenis_semester) ?>" target="_blank" class="nilai-action-chip">
                                        <i class="fas fa-file-pdf text-danger"></i> PDF
                                    </a>
                                </div>
                            </div>

                            <!-- Target Settings Banner -->
                            <div class="nilai-banner-info">
                                <div class="nilai-banner-header">
                                    <div class="nilai-banner-name">
                                        <i class="fas fa-sliders-h text-primary mr-1"></i>
                                        <span>Target Rentang Nilai</span>
                                    </div>
                                </div>
                                <div class="form-row mt-2">
                                    <div class="col-6">
                                        <label class="small font-weight-bold text-muted mb-1">Target Terendah (Min)</label>
                                        <input type="number" class="form-control form-control-sm text-center font-weight-bold" id="mobile_min_target" placeholder="Min >= KKTP" value="<?= $nilai_min_target_setting !== null ? (float)$nilai_min_target_setting : '' ?>" <?= $can_edit ? '' : 'disabled' ?>>
                                    </div>
                                    <div class="col-6">
                                        <label class="small font-weight-bold text-muted mb-1">Target Tertinggi (Max)</label>
                                        <input type="number" class="form-control form-control-sm text-center font-weight-bold" id="mobile_max_target" min="0" max="99" placeholder="Max <= 99" value="<?= $nilai_max_target_setting !== null ? (float)$nilai_max_target_setting : '' ?>" <?= $can_edit ? '' : 'disabled' ?>>
                                    </div>
                                </div>
                                <div class="nilai-banner-meta mt-2">
                                    <span class="nilai-banner-pill">
                                        Tertinggi Jadi: <strong id="mobileBannerStatMax" class="val-max"><?= $max_jadi !== null ? (float)$max_jadi : ($max_asli !== null ? (float)$max_asli : '-') ?></strong>
                                    </span>
                                    <span class="nilai-banner-pill">
                                        Terendah Jadi: <strong id="mobileBannerStatMin" class="val-min"><?= $min_jadi !== null ? (float)$min_jadi : ($min_asli !== null ? (float)$min_asli : '-') ?></strong>
                                    </span>
                                </div>
                            </div>

                            <!-- Student List Cards -->
                            <div class="nilai-student-list">
                                <?php $sNo = 1; foreach ($students as $student): ?>
                                    <?php 
                                    $sId = $student['id_siswa'];
                                    $g = $grades_data[$sId] ?? null;
                                    $nAsli = ($g && (float)$g['nilai_asli'] > 0) ? (float)$g['nilai_asli'] : '';
                                    $nRemidi = ($g && (float)$g['nilai_remidi'] > 0) ? (float)$g['nilai_remidi'] : '';
                                    $nJadi = ($g && (float)$g['nilai_jadi'] > 0) ? (float)$g['nilai_jadi'] : '';
                                    ?>
                                    <div class="nilai-student-card" data-student-id="<?= $sId ?>">
                                        <div class="nilai-student-left">
                                            <div class="nilai-student-num"><?= $sNo++ ?></div>
                                            <div class="nilai-student-info">
                                                <div class="nilai-student-name" title="<?= htmlspecialchars($student['nama_siswa']) ?>">
                                                    <?= htmlspecialchars($student['nama_siswa']) ?>
                                                </div>
                                                <div class="nilai-student-sub">
                                                    <span class="nilai-badge-jadi mobile-badge-jadi-<?= $sId ?> <?= $nJadi !== '' ? '' : 'd-none' ?>">
                                                        Jadi: <strong class="val-jadi"><?= $nJadi ?></strong>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="nilai-student-right">
                                            <div class="mobile-dual-inputs">
                                                <div class="mobile-dual-col">
                                                    <label>Asli</label>
                                                    <input type="number" 
                                                           inputmode="numeric" 
                                                           pattern="[0-9]*" 
                                                           class="mobile-grade-input mobile-input-asli <?= $nAsli !== '' ? 'is-filled' : '' ?>" 
                                                           data-student-id="<?= $sId ?>" 
                                                           value="<?= $nAsli ?>" 
                                                           min="0" 
                                                           max="100" 
                                                           placeholder="-"
                                                           <?= $can_edit ? '' : 'disabled' ?>>
                                                </div>
                                                <div class="mobile-dual-col">
                                                    <label>Remidi</label>
                                                    <input type="number" 
                                                           inputmode="numeric" 
                                                           pattern="[0-9]*" 
                                                           class="mobile-grade-input mobile-input-remidi <?= $nRemidi !== '' ? 'is-filled' : '' ?>" 
                                                           data-student-id="<?= $sId ?>" 
                                                           value="<?= $nRemidi ?>" 
                                                           min="0" 
                                                           max="100" 
                                                           placeholder="-"
                                                           <?= $can_edit ? '' : 'disabled' ?>>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Summary Stats Card (Footer Nilai Tertinggi & Terendah Kelas) -->
                            <div class="nilai-summary-card">
                                <div class="nilai-summary-header">
                                    <span class="nilai-summary-title">
                                        <i class="fas fa-chart-line text-primary mr-1"></i>
                                        Rekap Nilai Kelas (<?= htmlspecialchars($page_title) ?>)
                                    </span>
                                </div>
                                <div class="nilai-summary-grid">
                                    <div class="nilai-summary-box box-max">
                                        <div class="box-label">Nilai Tertinggi</div>
                                        <div class="box-value text-success" id="summaryStatMax"><?= $max_asli !== null ? (float)$max_asli : '-' ?></div>
                                        <div class="box-sub">Jadi: <strong id="summaryStatMaxJadi"><?= $max_jadi !== null ? (float)$max_jadi : '-' ?></strong></div>
                                    </div>
                                    <div class="nilai-summary-box box-min">
                                        <div class="box-label">Nilai Terendah</div>
                                        <div class="box-value text-danger" id="summaryStatMin"><?= $min_asli !== null ? (float)$min_asli : '-' ?></div>
                                        <div class="box-sub">Jadi: <strong id="summaryStatMinJadi"><?= $min_jadi !== null ? (float)$min_jadi : '-' ?></strong></div>
                                    </div>
                                    <div class="nilai-summary-box box-avg">
                                        <div class="box-label">Remidi Tertinggi</div>
                                        <div class="box-value text-primary" id="summaryStatRemidi"><?= $max_remidi !== null ? (float)$max_remidi : '-' ?></div>
                                        <div class="box-sub">Total Siswa: <strong><?= count($students) ?></strong></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Floating Bottom Action Bar -->
                            <?php if ($can_edit): ?>
                            <div class="nilai-bottom-bar">
                                <div class="nilai-bar-info">
                                    <span class="nilai-bar-title"><?= htmlspecialchars($page_title) ?></span>
                                    <span class="nilai-bar-sub"><?= count($students) ?> Siswa Terdaftar</span>
                                </div>
                                <button type="button" class="nilai-btn-save" id="btnSaveMobileSemester">
                                    <i class="fas fa-save"></i>
                                    <span>Simpan Nilai</span>
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            Silakan pilih Kelas dan Mata Pelajaran terlebih dahulu.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<?php require_once '../templates/footer.php'; ?>

<script>
$(document).ready(function() {
    var canEdit = <?= $can_edit ? 'true' : 'false' ?>;
    var inFlight = null;

    function notifySuccess(message) {
        if (window.iziToast) {
            iziToast.success({ title: 'Sukses', message: message, position: 'topRight' });
            return;
        }
        if (window.Swal && Swal.fire) {
            Swal.fire({ icon: 'success', title: 'Sukses', text: message });
            return;
        }
        alert(message);
    }

    function notifyError(message) {
        if (window.iziToast) {
            iziToast.error({ title: 'Error', message: message, position: 'topRight' });
            return;
        }
        if (window.Swal && Swal.fire) {
            Swal.fire({ icon: 'error', title: 'Error', text: message });
            return;
        }
        alert(message);
    }

    function setEditingAll(editing) {
        if (!canEdit) return;
        $('.input-nilai-asli, .input-nilai-remidi').prop('disabled', !editing);
        $('#btn-edit-all').toggleClass('d-none', editing);
        $('#btn-save-all').toggleClass('d-none', !editing);
    }

    $('#btn-edit-all').on('click', function() {
        setEditingAll(true);
        $('.input-nilai-asli:enabled').first().focus().select();
    });

    $('#btn-save-all').on('click', function() {
        if (!canEdit) return;

        // Validation for Min/Max Target
        var minTarget = $('#nilai_min_target').val();
        var maxTarget = $('#nilai_max_target').val();

        if (!minTarget || !maxTarget) {
            Swal.fire({
                icon: 'warning',
                title: 'Validasi Gagal',
                text: 'Silakan masukkan nilai Minimal (Min) dan Maksimal (Max) yang diinginkan terlebih dahulu.',
                confirmButtonColor: '#6777ef'
            });
            return;
        }

        var kktp = <?= json_encode((float)$kktp) ?>;
        if (parseFloat(minTarget) < kktp) {
            Swal.fire({
                icon: 'error',
                title: 'Nilai Tidak Valid',
                text: 'Nilai Minimal (Min) tidak boleh di bawah KKTP/KKM (' + kktp + ').',
                confirmButtonColor: '#6777ef'
            });
            return;
        }

        if (parseFloat(maxTarget) > 99) {
            Swal.fire({
                icon: 'error',
                title: 'Nilai Tidak Valid',
                text: 'Nilai Maksimal (Max) tidak boleh lebih dari 99.',
                confirmButtonColor: '#6777ef'
            });
            return;
        }

        if (inFlight) {
            try { inFlight.abort(); } catch (e) {}
        }

        var btn = $(this);
        btn.prop('disabled', true);

        var grades = [];
        $('tbody tr[data-id-siswa]').each(function() {
            var tr = $(this);
            grades.push({
                id_siswa: tr.data('id-siswa'),
                nilai_asli: tr.find('.input-nilai-asli').val(),
                nilai_remidi: tr.find('.input-nilai-remidi').val()
            });
        });

        inFlight = $.ajax({
            url: 'ajax_nilai_semester.php',
            method: 'POST',
            data: {
                action: 'save_grades',
                id_kelas: '<?= $selected_class_id ?>',
                id_mapel: '<?= $selected_mapel_id ?>',
                jenis_semester: '<?= $jenis_semester ?>',
                nilai_min_target: $('#nilai_min_target').val(),
                nilai_max_target: $('#nilai_max_target').val(),
                grades: JSON.stringify(grades)
            },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    var data = response.data || {};
                    if (Array.isArray(data.grades)) {
                        data.grades.forEach(function(g) {
                            var tr = $('tbody tr[data-id-siswa="' + g.id_siswa + '"]');
                            if (!tr.length) return;
                            var asli = parseFloat(g.nilai_asli) || 0;
                            var remidi = parseFloat(g.nilai_remidi) || 0;
                            var jadi = parseFloat(g.nilai_jadi) || 0;
                            tr.find('.input-nilai-asli').val(asli > 0 ? asli : '');
                            tr.find('.input-nilai-remidi').val(remidi > 0 ? remidi : '');
                            tr.find('.display-nilai-jadi').text(jadi > 0 ? jadi : '-');
                        });
                    }
                    updateSummaryStats();
                    setEditingAll(false);
                    notifySuccess('Nilai berhasil disimpan');
                } else {
                    notifyError(response.message || 'Gagal menyimpan');
                }
            },
            error: function() {
                if (!canEdit) return;
                notifyError('Terjadi kesalahan sistem');
            },
            complete: function() {
                btn.prop('disabled', false);
            }
        });
    });

    // Function to update summary stats
    function updateSummaryStats() {
        var min_asli = null, max_asli = null;
        var min_remidi = null, max_remidi = null;
        var min_jadi = null, max_jadi = null;

        $('tbody tr[data-id-siswa]').each(function() {
            var tr = $(this);
            var asli = parseFloat(tr.find('.input-nilai-asli').val()) || 0;
            var remidi = parseFloat(tr.find('.input-nilai-remidi').val()) || 0;
            var jadiTxt = tr.find('.display-nilai-jadi').text().trim();
            var jadi = jadiTxt === '-' ? 0 : (parseFloat(jadiTxt) || 0);

            // Logic: Only consider non-zero values for min/max
            if (asli > 0) {
                if (min_asli === null || asli < min_asli) min_asli = asli;
                if (max_asli === null || asli > max_asli) max_asli = asli;
            }
            if (remidi > 0) {
                if (min_remidi === null || remidi < min_remidi) min_remidi = remidi;
                if (max_remidi === null || remidi > max_remidi) max_remidi = remidi;
            }
            if (jadi > 0) {
                if (min_jadi === null || jadi < min_jadi) min_jadi = jadi;
                if (max_jadi === null || jadi > max_jadi) max_jadi = jadi;
            }
        });

        // Update DOM
        $('#max-asli').text(max_asli !== null ? max_asli : '-');
        $('#min-asli').text(min_asli !== null ? min_asli : '-');
        
        $('#max-remidi').text(max_remidi !== null ? max_remidi : '-');
        $('#min-remidi').text(min_remidi !== null ? min_remidi : '-');
        
        $('#max-jadi').text(max_jadi !== null ? max_jadi : '-');
        $('#min-jadi').text(min_jadi !== null ? min_jadi : '-');

        // Mobile stats update
        var bannerMax = max_jadi !== null ? max_jadi : (max_asli !== null ? max_asli : '-');
        var bannerMin = min_jadi !== null ? min_jadi : (min_asli !== null ? min_asli : '-');
        $('#mobileBannerStatMax').text(bannerMax);
        $('#mobileBannerStatMin').text(bannerMin);

        $('#summaryStatMax').text(max_asli !== null ? max_asli : '-');
        $('#summaryStatMaxJadi').text(max_jadi !== null ? max_jadi : '-');
        $('#summaryStatMin').text(min_asli !== null ? min_asli : '-');
        $('#summaryStatMinJadi').text(min_jadi !== null ? min_jadi : '-');
        $('#summaryStatRemidi').text(max_remidi !== null ? max_remidi : '-');
    }

    // ==========================================
    // MOBILE APP INTERACTION (SEMESTER/EXAM)
    // ==========================================
    // Sync Target Min/Max between Mobile and Desktop
    $('#mobile_min_target').on('input change', function() {
        $('#nilai_min_target').val($(this).val());
    });
    $('#mobile_max_target').on('input change', function() {
        $('#nilai_max_target').val($(this).val());
    });
    $('#nilai_min_target').on('input change', function() {
        $('#mobile_min_target').val($(this).val());
    });
    $('#nilai_max_target').on('input change', function() {
        $('#mobile_max_target').val($(this).val());
    });

    // Mobile inputs key navigation
    $(document).on('keydown', '.mobile-grade-input', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            var isAsli = $(this).hasClass('mobile-input-asli');
            if (isAsli) {
                var remidi = $(this).closest('.mobile-dual-inputs').find('.mobile-input-remidi');
                if (remidi.length) remidi.focus().select();
            } else {
                var nextCard = $(this).closest('.nilai-student-card').next('.nilai-student-card');
                if (nextCard.length) {
                    nextCard.find('.mobile-input-asli').focus().select();
                } else {
                    $(this).blur();
                }
            }
        }
    });

    // Input sync from Mobile to Desktop Table
    $(document).on('input', '.mobile-grade-input', function() {
        var val = $(this).val();
        if (val !== '' && parseFloat(val) > 0) {
            $(this).addClass('is-filled');
        } else {
            $(this).removeClass('is-filled');
        }

        var studentId = $(this).data('student-id');
        var tr = $('tbody tr[data-id-siswa="' + studentId + '"]');
        if ($(this).hasClass('mobile-input-asli')) {
            tr.find('.input-nilai-asli').val(val);
        } else if ($(this).hasClass('mobile-input-remidi')) {
            tr.find('.input-nilai-remidi').val(val);
        }
        updateSummaryStats();
    });

    // Mobile Save Button
    $('#btnSaveMobileSemester').on('click', function() {
        if (!canEdit) return;

        var minTarget = $('#mobile_min_target').val() || $('#nilai_min_target').val();
        var maxTarget = $('#mobile_max_target').val() || $('#nilai_max_target').val();

        if (!minTarget || !maxTarget) {
            Swal.fire({
                icon: 'warning',
                title: 'Validasi Gagal',
                text: 'Silakan masukkan nilai Minimal (Min) dan Maksimal (Max) yang diinginkan terlebih dahulu.',
                confirmButtonColor: '#2563eb'
            });
            return;
        }

        var kktp = <?= json_encode(isset($selected_mapel['kktp']) ? (float)$selected_mapel['kktp'] : 0) ?>;
        if (parseFloat(minTarget) < kktp) {
            Swal.fire({
                icon: 'error',
                title: 'Nilai Tidak Valid',
                text: 'Nilai Minimal (Min) tidak boleh di bawah KKTP/KKM (' + kktp + ').',
                confirmButtonColor: '#2563eb'
            });
            return;
        }

        if (parseFloat(maxTarget) > 99) {
            Swal.fire({
                icon: 'error',
                title: 'Nilai Tidak Valid',
                text: 'Nilai Maksimal (Max) tidak boleh lebih dari 99.',
                confirmButtonColor: '#2563eb'
            });
            return;
        }

        var grades = [];
        $('.nilai-student-card[data-student-id]').each(function() {
            var card = $(this);
            var stId = card.data('student-id');
            grades.push({
                id_siswa: stId,
                nilai_asli: card.find('.mobile-input-asli').val(),
                nilai_remidi: card.find('.mobile-input-remidi').val()
            });
        });

        var btn = $(this);
        var originalHtml = btn.html();
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Menyimpan...');

        $.ajax({
            url: 'ajax_nilai_semester.php',
            method: 'POST',
            data: {
                action: 'save_grades',
                id_kelas: '<?= $selected_class_id ?>',
                id_mapel: '<?= $selected_mapel_id ?>',
                jenis_semester: '<?= $jenis_semester ?>',
                nilai_min_target: minTarget,
                nilai_max_target: maxTarget,
                grades: JSON.stringify(grades)
            },
            dataType: 'json',
            success: function(response) {
                btn.prop('disabled', false).html(originalHtml);
                if (response.status === 'success') {
                    var data = response.data || {};
                    if (Array.isArray(data.grades)) {
                        data.grades.forEach(function(g) {
                            var card = $('.nilai-student-card[data-student-id="' + g.id_siswa + '"]');
                            var tr = $('tbody tr[data-id-siswa="' + g.id_siswa + '"]');
                            var asli = parseFloat(g.nilai_asli) || 0;
                            var remidi = parseFloat(g.nilai_remidi) || 0;
                            var jadi = parseFloat(g.nilai_jadi) || 0;

                            // Update Mobile card
                            card.find('.mobile-input-asli').val(asli > 0 ? asli : '');
                            card.find('.mobile-input-remidi').val(remidi > 0 ? remidi : '');
                            var badge = card.find('.mobile-badge-jadi-' + g.id_siswa);
                            if (jadi > 0) {
                                badge.find('.val-jadi').text(jadi);
                                badge.removeClass('d-none');
                            } else {
                                badge.addClass('d-none');
                            }

                            // Update Desktop table
                            tr.find('.input-nilai-asli').val(asli > 0 ? asli : '');
                            tr.find('.input-nilai-remidi').val(remidi > 0 ? remidi : '');
                            tr.find('.display-nilai-jadi').text(jadi > 0 ? jadi : '-');
                        });
                    }
                    updateSummaryStats();
                    Swal.fire({
                        icon: 'success',
                        title: 'Tersimpan!',
                        text: 'Nilai berhasil disimpan.',
                        timer: 1400,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire('Gagal', response.message || 'Gagal menyimpan', 'error');
                }
            },
            error: function() {
                btn.prop('disabled', false).html(originalHtml);
                Swal.fire('Error', 'Terjadi kesalahan sistem', 'error');
            }
        });
    });
});
</script>
