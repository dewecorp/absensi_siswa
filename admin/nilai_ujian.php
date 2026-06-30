<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha'])) {
    redirect('../login.php');
}

ensure_nilai_semester_enum_ujian_praktik($pdo);
$page_title = nilai_ujian_page_title();
$jenis_semester = nilai_ujian_jenis_semester();
$ujian_praktik_tanpa_remidi = nilai_ujian_is_praktik_mode();
$is_admin_view = true;
$can_edit = false;

// Fetch classes - only Kelas 6 for Ujian
$stmt = $pdo->query("SELECT * FROM tb_kelas WHERE nama_kelas LIKE '%6%' OR nama_kelas LIKE '%VI%' ORDER BY nama_kelas ASC");
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get active semester info
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'];
$semester_aktif = $school_profile['semester'];

// Fetch subjects
$subjects = [];
if ($ujian_praktik_tanpa_remidi) {
    // Hanya tampilkan mapel yang sudah ada datanya di tb_nilai_semester untuk Ujian Praktik
    $stmt = $pdo->prepare("
        SELECT DISTINCT mp.* 
        FROM tb_mata_pelajaran mp
        JOIN tb_nilai_semester ns ON mp.id_mapel = ns.id_mapel
        WHERE ns.jenis_semester = 'Ujian Praktik'
        AND ns.tahun_ajaran = ?
        AND ns.semester = ?
        ORDER BY mp.nama_mapel ASC
    ");
    $stmt->execute([$tahun_ajaran, $semester_aktif]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $subjects = getFilteredSubjects($pdo);
}

// Filter subjects for exam types
if (in_array($jenis_semester, ['Pra Ujian', 'Ujian'], true)) {
    $subjects = array_values(array_filter($subjects, function ($m) {
        $nama = strtolower(trim((string)($m['nama_mapel'] ?? '')));
        $nama = preg_replace('/\s+/', ' ', $nama);
        return $nama !== 'tajwid' && $nama !== 'bta';
    }));
}

$selected_class_id = isset($_GET['kelas']) ? $_GET['kelas'] : null;
$selected_mapel_id = isset($_GET['mapel']) ? $_GET['mapel'] : null;
$selected_class = null;
$selected_mapel = null;

if (count($classes) == 1 && !$selected_class_id) {
    $selected_class_id = $classes[0]['id_kelas'];
}

// If no class selected, auto-select the first Kelas 6
if (!$selected_class_id && count($classes) > 0) {
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

// Fetch students and grades
$students = [];
$grades_data = [];

if ($selected_class && $selected_mapel) {
    // Ambil siswa aktif dan siswa historis yang nilainya tersimpan untuk kelas ini.
    $students = getStudentsForNilaiKelas($pdo, (int)$selected_class_id, (string)$tahun_ajaran, (string)$semester_aktif, (string)$jenis_semester, (int)$selected_mapel_id);

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

    /* Mobile adjustments */
    @media (max-width: 768px) {
        thead th, tbody td {
            font-size: 0.75em !important;
            padding: 4px 2px !important;
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
            padding-left: 2px !important;
            padding-right: 2px !important;
        }
        .sticky-col-2 {
            left: 30px;
            width: 65px !important;
            min-width: 65px !important;
            z-index: 11;
            white-space: normal !important;
            line-height: 1.1;
            word-break: break-word;
            padding-left: 4px !important;
            padding-right: 4px !important;
            text-align: left !important;
        }
        .header-asli {
            min-width: 65px !important;
            width: 65px !important;
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
                <div class="breadcrumb-item"><?= htmlspecialchars($page_title) ?></div>
            </div>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-body">
                    <!-- Filter Form -->
                    <form method="GET" action="" class="mb-4">
                        <div class="row">
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
                        <!-- Hidden input for kelas -->
                        <input type="hidden" name="kelas" value="<?= $selected_class_id ?>">
                        <?php if (nilai_ujian_is_praktik_mode()): ?>
                        <input type="hidden" name="nilai_mode" value="praktik">
                        <?php endif; ?>
                    </form>

                    <?php if ($selected_class && $selected_mapel): ?>
                        <div class="mb-3 text-right">
                            <div class="btn-group">
                                <a href="../guru/export_nilai_semester_excel.php?session_type=<?= $_SESSION['level'] ?>&kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>&jenis=<?= urlencode($jenis_semester) ?>" target="_blank" class="btn btn-success">
                                    <i class="fas fa-file-excel"></i> Export Excel
                                </a>
                                <a href="../guru/export_nilai_semester_pdf.php?session_type=<?= $_SESSION['level'] ?>&kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>&jenis=<?= urlencode($jenis_semester) ?>" target="_blank" class="btn btn-danger">
                                    <i class="fas fa-file-pdf"></i> Export PDF
                                </a>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th class="text-center sticky-col sticky-col-1" style="vertical-align: middle;">No</th>
                                        <th class="text-center sticky-col sticky-col-2" style="vertical-align: middle;">Nama Siswa</th>
                                        <th class="text-center header-asli" style="vertical-align: middle;">Nilai Asli</th>
                                        <?php if (!$ujian_praktik_tanpa_remidi): ?>
                                        <th width="15%" class="text-center">Remidi</th>
                                        <?php endif; ?>
                                        <th width="15%" class="text-center">Nilai Jadi</th>
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
                                        $nilai_remidi = $ujian_praktik_tanpa_remidi ? 0 : ($grade ? $grade['nilai_remidi'] : 0);
                                        $nilai_jadi = $grade ? $grade['nilai_jadi'] : 0;
                                        
                                        // Update Min/Max Stats (Only consider non-zero values)
                                        if ($nilai_asli > 0) {
                                            if ($min_asli === null || $nilai_asli < $min_asli) $min_asli = $nilai_asli;
                                            if ($max_asli === null || $nilai_asli > $max_asli) $max_asli = $nilai_asli;
                                        }
                                        if (!$ujian_praktik_tanpa_remidi && $nilai_remidi > 0) {
                                            if ($min_remidi === null || $nilai_remidi < $min_remidi) $min_remidi = $nilai_remidi;
                                            if ($max_remidi === null || $nilai_remidi > $max_remidi) $max_remidi = $nilai_remidi;
                                        }
                                        if ($nilai_jadi > 0) {
                                            if ($min_jadi === null || $nilai_jadi < $min_jadi) $min_jadi = $nilai_jadi;
                                            if ($max_jadi === null || $nilai_jadi > $max_jadi) $max_jadi = $nilai_jadi;
                                        }
                                    ?>
                                        <tr>
                                            <td class="text-center sticky-col sticky-col-1"><?= $no++ ?></td>
                                            <td class="sticky-col sticky-col-2"><?= htmlspecialchars($student['nama_siswa']) ?></td>
                                            <td class="text-center">
                                                <span class="display-nilai-asli"><?= $nilai_asli > 0 ? (float)$nilai_asli : '-' ?></span>
                                            </td>
                                            <?php if (!$ujian_praktik_tanpa_remidi): ?>
                                            <td class="text-center">
                                                <span class="display-nilai-remidi"><?= $nilai_remidi > 0 ? (float)$nilai_remidi : '-' ?></span>
                                            </td>
                                            <?php endif; ?>
                                            <td class="text-center bg-light">
                                                <span class="display-nilai-jadi font-weight-bold"><?= $nilai_jadi > 0 ? (float)$nilai_jadi : '-' ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- Footer Stats -->
                                    <tr class="bg-light font-weight-bold">
                                        <td colspan="2" class="text-right">Nilai Tertinggi</td>
                                        <td class="text-center text-success" id="max-asli"><?= $max_asli !== null ? (float)$max_asli : '-' ?></td>
                                        <?php if (!$ujian_praktik_tanpa_remidi): ?>
                                        <td class="text-center text-success" id="max-remidi"><?= $max_remidi !== null ? (float)$max_remidi : '-' ?></td>
                                        <?php endif; ?>
                                        <td class="text-center text-success" id="max-jadi"><?= $max_jadi !== null ? (float)$max_jadi : '-' ?></td>
                                    </tr>
                                    <tr class="bg-light font-weight-bold">
                                        <td colspan="2" class="text-right">Nilai Terendah</td>
                                        <td class="text-center text-danger" id="min-asli"><?= $min_asli !== null ? (float)$min_asli : '-' ?></td>
                                        <?php if (!$ujian_praktik_tanpa_remidi): ?>
                                        <td class="text-center text-danger" id="min-remidi"><?= $min_remidi !== null ? (float)$min_remidi : '-' ?></td>
                                        <?php endif; ?>
                                        <td class="text-center text-danger" id="min-jadi"><?= $min_jadi !== null ? (float)$min_jadi : '-' ?></td>
                                    </tr>
                                </tbody>
                            </table>
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
