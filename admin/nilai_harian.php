<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha'])) {
    redirect('../login.php');
}

$page_title = 'Nilai Harian';
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

// Fetch students and grades if class AND mapel is selected
$students = [];
$grade_headers = [];
$grades_data = [];

if ($selected_class && $selected_mapel) {
    // Get students
    $stmt = $pdo->prepare("SELECT * FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
    $stmt->execute([$selected_class_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get grade headers
    $stmt = $pdo->prepare("SELECT * FROM tb_nilai_harian_header WHERE id_kelas = ? AND id_mapel = ? AND tahun_ajaran = ? AND semester = ? ORDER BY created_at ASC");
    $stmt->execute([$selected_class_id, $selected_mapel_id, $tahun_ajaran, $semester_aktif]);
    $grade_headers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get grade values
    if (!empty($grade_headers)) {
        $header_ids = array_column($grade_headers, 'id_header');
        $placeholders = str_repeat('?,', count($header_ids) - 1) . '?';
        
        $stmt = $pdo->prepare("SELECT * FROM tb_nilai_harian_detail WHERE id_header IN ($placeholders)");
        $stmt->execute($header_ids);
        $all_grades = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Organize grades by student_id and header_id
        foreach ($all_grades as $g) {
            $grades_data[$g['id_siswa']][$g['id_header']] = [
                'nilai' => $g['nilai'],
                'nilai_jadi' => $g['nilai_jadi']
            ];
        }
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
                    <h4>Data Nilai Harian - <?= $selected_class['nama_kelas'] ?> - <?= $selected_mapel['nama_mapel'] ?></h4>
                    <div class="card-header-action">
                        <div class="btn-group mr-2">
                            <a href="../guru/export_nilai_harian_excel.php?kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>" target="_blank" class="btn btn-success">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </a>
                            <a href="../guru/export_nilai_harian_pdf.php?kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>" target="_blank" class="btn btn-danger">
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
                                        <th class="text-center position-relative" colspan="2" style="min-width: 200px;">
                                            <?= htmlspecialchars($header['nama_penilaian']) ?>
                                        </th>
                                    <?php endforeach; ?>
                                    <th style="width: 100px; vertical-align: middle;" rowspan="3">Rerata</th>
                                </tr>
                                <tr>
                                    <?php foreach ($grade_headers as $header): ?>
                                        <th class="text-center bg-white font-weight-normal materi-cell" data-header-id="<?= $header['id_header'] ?>" colspan="2" style="font-size: 0.85em; font-style: italic;">
                                            <span class="materi-display"><?= htmlspecialchars($header['materi'] ?? '-') ?></span>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                                <tr>
                                    <?php foreach ($grade_headers as $header): ?>
                                        <th class="text-center bg-light" style="font-size: 0.85em;">Nilai</th>
                                        <th class="text-center bg-light" style="font-size: 0.85em;">Jadi</th>
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
                                                    $total_score += (float)$val;
                                                    $count_score++;
                                                    
                                                    // Track min/max
                                                    if (!isset($col_min[$header['id_header']]) || $val < $col_min[$header['id_header']]) {
                                                        $col_min[$header['id_header']] = $val;
                                                    }
                                                    if (!isset($col_max[$header['id_header']]) || $val > $col_max[$header['id_header']]) {
                                                        $col_max[$header['id_header']] = $val;
                                                    }
                                                }
                                                ?>
                                                <td class="text-center p-1">
                                                    <input type="number" 
                                                           class="form-control form-control-sm text-center grade-input grade-col-<?= $header['id_header'] ?>" 
                                                           value="<?= $val ?>" 
                                                           disabled
                                                           min="0" max="100" placeholder="-">
                                                </td>
                                                <td class="text-center p-1">
                                                    <input type="number" 
                                                           class="form-control form-control-sm text-center grade-input-jadi grade-col-jadi-<?= $header['id_header'] ?>" 
                                                           value="<?= $val_jadi ?>" 
                                                           disabled
                                                           min="0" max="100" placeholder="-">
                                                </td>
                                            <?php endforeach; ?>
                                            <td class="text-center font-weight-bold student-avg">
                                                <?= $count_score > 0 ? round($total_score / $count_score, 1) : '-' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- Footer Stats -->
                                    <tr class="bg-light font-weight-bold">
                                        <td colspan="2" class="text-right">Nilai Tertinggi</td>
                                        <?php foreach ($grade_headers as $header): ?>
                                            <td class="text-center text-success col-max-<?= $header['id_header'] ?>">
                                                <?= isset($col_max[$header['id_header']]) ? $col_max[$header['id_header']] : '-' ?>
                                            </td>
                                            <td></td>
                                        <?php endforeach; ?>
                                        <td></td>
                                    </tr>
                                    <tr class="bg-light font-weight-bold">
                                        <td colspan="2" class="text-right">Nilai Terendah</td>
                                        <?php foreach ($grade_headers as $header): ?>
                                            <td class="text-center text-danger col-min-<?= $header['id_header'] ?>">
                                                <?= isset($col_min[$header['id_header']]) ? $col_min[$header['id_header']] : '-' ?>
                                            </td>
                                            <td></td>
                                        <?php endforeach; ?>
                                        <td></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php require_once '../templates/footer.php'; ?>

<script>
$(document).ready(function() {
    // Filter logic
    function applyFilter() {
        var kelasId = $('#filter_kelas').val();
        var mapelId = $('#filter_mapel').val();
        var url = 'nilai_harian.php?';
        var params = [];
        
        if(kelasId) params.push('kelas=' + kelasId);
        if(mapelId) params.push('mapel=' + mapelId);
        
        if(params.length > 0) {
            window.location.href = url + params.join('&');
        } else {
            window.location.href = 'nilai_harian.php';
        }
    }

    $('#filter_kelas, #filter_mapel').change(function() {
        applyFilter();
    });
});
</script>