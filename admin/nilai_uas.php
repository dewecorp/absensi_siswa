<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha'])) {
    redirect('../login.php');
}

$page_title = 'Nilai Akhir Semester';
$jenis_semester = 'UAS';
$is_admin_view = true;
$can_edit = false;

// Fetch classes (Admin sees all)
$stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch subjects (Admin sees all, filtered)
$stmt = $pdo->query("SELECT * FROM tb_mata_pelajaran 
    WHERE nama_mapel NOT LIKE '%Asmaul Husna%'
    AND nama_mapel NOT LIKE '%Upacara%'
    AND nama_mapel NOT LIKE '%Istirahat%'
    AND nama_mapel NOT LIKE '%Kepramukaan%'
    AND nama_mapel NOT LIKE '%Ekstrakurikuler%'
    ORDER BY nama_mapel ASC");
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
}

require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<style>
    /* Sticky Columns and Header */
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
    
    /* Sticky Header + Sticky Column Intersection */
    thead th.sticky-col {
        z-index: 25 !important;
    }
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><?= $page_title ?></h1>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-body">
                    <!-- Filter Form -->
                    <form method="GET" action="" class="mb-4">
                        <div class="row">
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
                        <div class="mb-3 text-right">
                            <div class="btn-group">
                                <a href="../guru/export_nilai_semester_excel.php?kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>&jenis=<?= urlencode($jenis_semester) ?>" target="_blank" class="btn btn-success">
                                    <i class="fas fa-file-excel"></i> Export Excel
                                </a>
                                <a href="../guru/export_nilai_semester_pdf.php?kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>&jenis=<?= urlencode($jenis_semester) ?>" target="_blank" class="btn btn-danger">
                                    <i class="fas fa-file-pdf"></i> Export PDF
                                </a>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th class="text-center sticky-col sticky-col-1" width="5%">No</th>
                                        <th class="text-center sticky-col sticky-col-2">Nama Siswa</th>
                                        <th width="15%" class="text-center">Nilai Asli</th>
                                        <th width="15%" class="text-center">Remidi</th>
                                        <th width="15%" class="text-center">Nilai Jadi</th>
                                        <th width="15%" class="text-center">Rerata</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $no = 1;
                                    
                                    // Initialize Min/Max variables
                                    $min_asli = null; $max_asli = null;
                                    $min_remidi = null; $max_remidi = null;
                                    $min_jadi = null; $max_jadi = null;
                                    $min_rerata = null; $max_rerata = null;

                                    foreach ($students as $student): 
                                        $id_siswa = $student['id_siswa'];
                                        $grade = $grades_data[$id_siswa] ?? null;
                                        $nilai_asli = $grade ? $grade['nilai_asli'] : 0;
                                        $nilai_remidi = $grade ? $grade['nilai_remidi'] : 0;
                                        $nilai_jadi = $grade ? $grade['nilai_jadi'] : 0;
                                        
                                        // Calculate Rerata logic: (Asli + Remidi) / 2 if Remidi > 0, else Asli
                                        $rerata = ($nilai_remidi > 0) ? ($nilai_asli + $nilai_remidi) / 2 : $nilai_asli;
                                        
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
                                        if ($rerata > 0) {
                                            if ($min_rerata === null || $rerata < $min_rerata) $min_rerata = $rerata;
                                            if ($max_rerata === null || $rerata > $max_rerata) $max_rerata = $rerata;
                                        }
                                    ?>
                                        <tr>
                                            <td class="text-center sticky-col sticky-col-1"><?= $no++ ?></td>
                                            <td class="sticky-col sticky-col-2"><?= htmlspecialchars($student['nama_siswa']) ?></td>
                                            <td class="text-center">
                                                <span class="display-nilai-asli"><?= $nilai_asli > 0 ? (float)$nilai_asli : '-' ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="display-nilai-remidi"><?= $nilai_remidi > 0 ? (float)$nilai_remidi : '-' ?></span>
                                            </td>
                                            <td class="text-center bg-light">
                                                <span class="display-nilai-jadi font-weight-bold"><?= $nilai_jadi > 0 ? (float)$nilai_jadi : '-' ?></span>
                                            </td>
                                            <td class="text-center bg-light">
                                                <span class="display-rerata"><?= $rerata > 0 ? (float)number_format($rerata, 1) : '-' ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- Footer Stats -->
                                    <tr class="bg-light font-weight-bold">
                                        <td colspan="2" class="text-right">Nilai Tertinggi</td>
                                        <td class="text-center text-success" id="max-asli"><?= $max_asli !== null ? (float)$max_asli : '-' ?></td>
                                        <td class="text-center text-success" id="max-remidi"><?= $max_remidi !== null ? (float)$max_remidi : '-' ?></td>
                                        <td class="text-center text-success" id="max-jadi"><?= $max_jadi !== null ? (float)$max_jadi : '-' ?></td>
                                        <td class="text-center text-success" id="max-rerata"><?= $max_rerata !== null ? (float)number_format($max_rerata, 1) : '-' ?></td>
                                    </tr>
                                    <tr class="bg-light font-weight-bold">
                                        <td colspan="2" class="text-right">Nilai Terendah</td>
                                        <td class="text-center text-danger" id="min-asli"><?= $min_asli !== null ? (float)$min_asli : '-' ?></td>
                                        <td class="text-center text-danger" id="min-remidi"><?= $min_remidi !== null ? (float)$min_remidi : '-' ?></td>
                                        <td class="text-center text-danger" id="min-jadi"><?= $min_jadi !== null ? (float)$min_jadi : '-' ?></td>
                                        <td class="text-center text-danger" id="min-rerata"><?= $min_rerata !== null ? (float)number_format($min_rerata, 1) : '-' ?></td>
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
