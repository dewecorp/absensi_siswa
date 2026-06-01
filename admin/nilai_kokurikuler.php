<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha'])) {
    redirect('../login.php');
}

$page_title = 'Nilai Kokurikuler';
$is_admin_view = true;
$can_edit = false;

// Fetch classes
$stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch subjects
$subjects = getFilteredSubjects($pdo);

// Determine selected class & mapel
$selected_class_id = isset($_GET['kelas']) ? $_GET['kelas'] : null;
$selected_mapel_id = isset($_GET['mapel']) ? $_GET['mapel'] : null;
$selected_class = null;
$selected_mapel = null;

// Get active semester info
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'];
$semester_aktif = $school_profile['semester'];

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

// Fetch students and grades
$students = [];
$grade_headers = [];
$grades_data = [];

if ($selected_class && $selected_mapel) {
    // Get students
    $stmt = $pdo->prepare("SELECT * FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
    $stmt->execute([$selected_class_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get grade headers
    $stmt = $pdo->prepare("SELECT * FROM tb_nilai_kokurikuler_header WHERE id_kelas = ? AND id_mapel = ? AND tahun_ajaran = ? AND semester = ? ORDER BY created_at ASC");
    $stmt->execute([$selected_class_id, $selected_mapel_id, $tahun_ajaran, $semester_aktif]);
    $grade_headers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get grade values
    if (!empty($grade_headers)) {
        $header_ids = array_column($grade_headers, 'id_header');
        $placeholders = str_repeat('?,', count($header_ids) - 1) . '?';
        
        $stmt = $pdo->prepare("SELECT * FROM tb_nilai_kokurikuler_detail WHERE id_header IN ($placeholders)");
        $stmt->execute($header_ids);
        $all_grades = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($all_grades as $g) {
            $grades_data[$g['id_siswa']][$g['id_header']] = [
                'nilai' => $g['nilai'],
                'nilai_jadi' => $g['nilai_jadi']
            ];
        }
    }
}

$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css'
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/sweetalert2@11',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js'
];

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

    /* Input styling */
    .grade-input, .grade-input-jadi {
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
        .grade-input, .grade-input-jadi {
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
                <div class="breadcrumb-item">Nilai Kokurikuler</div>
            </div>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <label>Pilih Kelas</label>
                            <select class="form-control select2" id="filter_kelas" <?= count($classes) == 1 ? 'disabled' : '' ?>>
                                <option value="">-- Pilih Kelas --</option>
                                <?php foreach ($classes as $kelas): ?>
                                    <option value="<?= $kelas['id_kelas'] ?>" <?= $selected_class_id == $kelas['id_kelas'] ? 'selected' : '' ?>>
                                        <?= $kelas['nama_kelas'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label>Pilih Mata Pelajaran</label>
                            <select class="form-control select2" id="filter_mapel">
                                <option value="">-- Pilih Mata Pelajaran --</option>
                                <?php foreach ($subjects as $mapel): ?>
                                    <option value="<?= $mapel['id_mapel'] ?>" <?= $selected_mapel_id == $mapel['id_mapel'] ? 'selected' : '' ?>>
                                        <?= $mapel['nama_mapel'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($selected_class && $selected_mapel): ?>
            <div class="card">
                <div class="card-header">
                    <h4>Data Nilai Kokurikuler - <?= $selected_class['nama_kelas'] ?> - <?= $selected_mapel['nama_mapel'] ?></h4>
                    <div class="card-header-action">
                        <div class="btn-group mr-2">
                            <a href="../guru/export_nilai_kokurikuler_excel?session_type=<?= $_SESSION['level'] ?>&kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>" target="_blank" class="btn btn-success">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </a>
                            <a href="../guru/export_nilai_kokurikuler_pdf?session_type=<?= $_SESSION['level'] ?>&kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>" target="_blank" class="btn btn-danger">
                                <i class="fas fa-file-pdf"></i> Export PDF
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-sm" id="gradesTable">
                            <thead>
                                <tr>
                                    <th class="sticky-col sticky-col-1" style="width: 50px; vertical-align: middle;" rowspan="3">No</th>
                                    <th class="sticky-col sticky-col-2" style="vertical-align: middle;" rowspan="3">Nama Siswa</th>
                                    <?php foreach ($grade_headers as $header): ?>
                                        <th class="text-center" colspan="2" style="min-width: 200px;">
                                            <?= htmlspecialchars($header['nama_penilaian']) ?>
                                        </th>
                                                <?php endforeach; ?>
                                                <th style="width: 100px; vertical-align: middle;" rowspan="3" class="text-center">Rerata</th>
                                            </tr>
                                <tr>
                                    <?php foreach ($grade_headers as $header): ?>
                                        <th class="text-center font-weight-normal activity-cell" data-header-id="<?= $header['id_header'] ?>" colspan="2" style="font-size: 0.85em; font-style: italic;">
                                            <div class="mb-1">
                                                <span class="activity-display font-weight-bold d-block"><?= htmlspecialchars($header['jenis_kegiatan'] ?? '-') ?></span>
                                            </div>
                                            <div>
                                                <span class="date-display small text-muted"><?= htmlspecialchars($header['tgl_kegiatan'] ?? '-') ?></span>
                                            </div>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                                <tr>
                                    <?php foreach ($grade_headers as $header): ?>
                                        <th class="text-center" style="font-size: 0.85em;">Nilai</th>
                                        <th class="text-center" style="font-size: 0.85em;">Jadi</th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($students)): ?>
                                    <tr>
                                        <td colspan="<?= count($grade_headers) + 3 ?>" class="text-center">Belum ada data siswa di kelas ini.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php 
                                    $col_min = [];
                                    $col_max = [];
                                    $i = 1; 
                                    ?>
                                    <?php foreach ($students as $student): ?>
                                        <tr>
                                            <td class="text-center sticky-col sticky-col-1"><?= $i++ ?></td>
                                            <td class="sticky-col sticky-col-2"><?= htmlspecialchars($student['nama_siswa']) ?></td>
                                            <?php 
                                            $total_score = 0;
                                            $count_score = 0;
                                            ?>
                                            <?php foreach ($grade_headers as $header): ?>
                                                <?php 
                                                $data_nilai = isset($grades_data[$student['id_siswa']][$header['id_header']]) ? $grades_data[$student['id_siswa']][$header['id_header']] : [];
                                                $val = isset($data_nilai['nilai']) ? $data_nilai['nilai'] : '';
                                                $val_jadi = isset($data_nilai['nilai_jadi']) ? $data_nilai['nilai_jadi'] : '';
                                                
                                                if ($val !== '') {
                                                    $val_float = (float)$val;
                                                    // Ignore 0 for stats
                                                    if ($val_float > 0) {
                                                        $total_score += $val_float;
                                                        $count_score++;
                                                        
                                                        // Track min/max
                                                        if (!isset($col_min[$header['id_header']]) || $val_float < $col_min[$header['id_header']]) {
                                                            $col_min[$header['id_header']] = $val_float;
                                                        }
                                                        if (!isset($col_max[$header['id_header']]) || $val_float > $col_max[$header['id_header']]) {
                                                            $col_max[$header['id_header']] = $val_float;
                                                        }
                                                    }
                                                }
                                                ?>
                                                <td class="text-center p-1">
                                                    <?= $val !== '' ? $val : '-' ?>
                                                </td>
                                                <td class="text-center p-1">
                                                    <?= $val_jadi !== '' ? $val_jadi : '-' ?>
                                                </td>
                                            <?php endforeach; ?>
                                            <td class="text-center font-weight-bold student-avg">
                                                <?= $count_score > 0 ? round($total_score / $count_score, 1) : '-' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- Footer Stats -->
                                    <tr class="bg-light font-weight-bold">
                                        <td colspan="2" class="text-right sticky-col sticky-col-1" style="left: 0;">Nilai Tertinggi</td>
                                        <?php foreach ($grade_headers as $header): ?>
                                            <td class="text-center text-success col-max-<?= $header['id_header'] ?>">
                                                <?= isset($col_max[$header['id_header']]) ? $col_max[$header['id_header']] : '-' ?>
                                            </td>
                                            <td></td>
                                        <?php endforeach; ?>
                                        <td class="text-center"></td>
                                    </tr>
                                    <tr class="bg-light font-weight-bold">
                                        <td colspan="2" class="text-right sticky-col sticky-col-1" style="left: 0;">Nilai Terendah</td>
                                        <?php foreach ($grade_headers as $header): ?>
                                            <td class="text-center text-danger">
                                                <?= isset($col_min[$header['id_header']]) ? $col_min[$header['id_header']] : '-' ?>
                                            </td>
                                            <td></td>
                                        <?php endforeach; ?>
                                        <td class="text-center"></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php else: ?>
                <div class="alert alert-info">
                    Silakan pilih Kelas dan Mata Pelajaran terlebih dahulu.
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php require_once '../templates/footer.php'; ?>

<script>
$(document).ready(function() {
    $('.select2').select2();

    // Auto-filter when class/mapel changes
    $('#filter_kelas, #filter_mapel').change(function() {
        var kelas = $('#filter_kelas').val();
        var mapel = $('#filter_mapel').val();
        
        if (kelas && mapel) {
            window.location.href = '?kelas=' + kelas + '&mapel=' + mapel;
        }
    });
});
</script>
