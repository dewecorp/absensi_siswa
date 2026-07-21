<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['guru', 'wali', 'kepala_madrasah', 'tata_usaha', 'admin'])) {
    redirect('../login.php');
}

$page_title = 'Nilai Kokurikuler';
$user_role = $_SESSION['level'];
$is_admin_view = in_array($user_role, ['kepala_madrasah', 'tata_usaha', 'admin']);
$can_edit = !$is_admin_view;

ensure_nilai_kokurikuler_header_minmax($pdo);

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
    if ($is_admin_view) {
        $stmt = $pdo->prepare("SELECT * FROM tb_nilai_kokurikuler_header WHERE id_kelas = ? AND id_mapel = ? AND tahun_ajaran = ? AND semester = ? ORDER BY created_at ASC");
        $stmt->execute([$selected_class_id, $selected_mapel_id, $tahun_ajaran, $semester_aktif]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM tb_nilai_kokurikuler_header WHERE id_guru = ? AND id_kelas = ? AND id_mapel = ? AND tahun_ajaran = ? AND semester = ? ORDER BY created_at ASC");
        $stmt->execute([$id_guru, $selected_class_id, $selected_mapel_id, $tahun_ajaran, $semester_aktif]);
    }
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
    /* Sticky Column Styling */
    .sticky-col {
        position: sticky !important;
        background-color: #ffffff !important;
        z-index: 10;
        box-shadow: 1px 0 3px rgba(0,0,0,0.1);
    }
    
    .sticky-col-1 {
        left: 0;
        width: 50px;
        min-width: 50px;
    }
    
    .sticky-col-2 {
        left: 50px; /* Width of col-1 */
        width: 180px;
        min-width: 180px;
        z-index: 11;
    }

    .sticky-col-footer {
        left: 0;
        width: 230px; /* 50 + 180 */
        min-width: 230px;
        z-index: 11;
    }

    thead th.sticky-col {
        z-index: 20 !important;
        background-color: #f8f9fa !important;
    }

    /* Reset Table Styling */
    .table-responsive {
        overflow-x: auto !important;
        overflow-y: visible !important;
    }

    table {
        width: 100% !important;
        border-collapse: separate !important;
        border-spacing: 0;
    }

    thead th {
        background-color: #f8f9fa !important;
        vertical-align: middle;
        padding: 8px !important;
        border: 1px solid #dee2e6 !important;
        text-align: center;
        position: sticky;
        top: 0;
        z-index: 15;
    }

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

        .sticky-col-1 {
            width: 30px !important;
            min-width: 30px !important;
        }
        .sticky-col-2 {
            left: 30px;
            width: 65px !important;
            min-width: 65px !important;
            white-space: normal !important;
            line-height: 1.1;
            word-break: break-word;
            padding: 4px !important;
        }

        .sticky-col-footer {
            width: 95px !important;
            min-width: 95px !important;
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
                        <?php if (count($classes) > 1): ?>
                        <div class="col-md-4">
                            <label>Kelas</label>
                            <select class="form-control select2" id="filter_kelas">
                                <option value="">-- Pilih Kelas --</option>
                                <?php foreach ($classes as $kelas): ?>
                                    <option value="<?= $kelas['id_kelas'] ?>" <?= $selected_class_id == $kelas['id_kelas'] ? 'selected' : '' ?>>
                                        <?= $kelas['nama_kelas'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php else: ?>
                            <input type="hidden" id="filter_kelas" value="<?= $classes[0]['id_kelas'] ?? '' ?>">
                        <?php endif; ?>
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
                            <a href="export_nilai_kokurikuler_excel?session_type=<?= $_SESSION['level'] ?>&kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>" target="_blank" class="btn btn-success">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </a>
                            <a href="export_nilai_kokurikuler_pdf?session_type=<?= $_SESSION['level'] ?>&kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>" target="_blank" class="btn btn-danger">
                                <i class="fas fa-file-pdf"></i> Export PDF
                            </a>
                        </div>
                        <?php if ($can_edit): ?>
                        <button class="btn btn-primary" data-toggle="modal" data-target="#addColumnModal">
                            <i class="fas fa-plus"></i> Tambah Kolom Nilai
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-sm" id="gradesTable">
                            <thead>
                                <tr>
                                        <th class="text-center sticky-col sticky-col-1" style="width: 50px; vertical-align: middle;" rowspan="3">No</th>
                                        <th class="sticky-col sticky-col-2" style="vertical-align: middle;" rowspan="3">Nama Siswa</th>
                                        <?php foreach ($grade_headers as $header): ?>
                                            <th class="text-center header-cell" data-header-id="<?= $header['id_header'] ?>" colspan="2" style="min-width: 220px;">
                                            <?php if ($can_edit): ?>
                                            <div class="mb-2">
                                                <button class="btn btn-sm btn-icon btn-warning edit-col-btn" data-header-id="<?= $header['id_header'] ?>" title="Edit Nilai">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-icon btn-success save-col-btn d-none" data-header-id="<?= $header['id_header'] ?>" title="Simpan Nilai">
                                                    <i class="fas fa-save"></i>
                                                </button>
                                                <button class="btn btn-sm btn-icon btn-danger delete-col-btn" data-header-id="<?= $header['id_header'] ?>" data-name="<?= $header['nama_penilaian'] ?>" title="Hapus Kolom">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                            <?php endif; ?>
                                            <div>
                                                <span class="nama-display"><?= htmlspecialchars($header['nama_penilaian']) ?></span>
                                                <input type="text" class="form-control form-control-sm text-center nama-input d-none" value="<?= htmlspecialchars($header['nama_penilaian']) ?>" placeholder="Nama Penilaian">
                                            </div>
                                            <div class="small text-muted range-display">
                                                Min: <?= isset($header['nilai_min_target']) && $header['nilai_min_target'] !== null ? htmlspecialchars($header['nilai_min_target']) : '-' ?>
                                                |
                                                Max: <?= isset($header['nilai_max_target']) && $header['nilai_max_target'] !== null ? htmlspecialchars($header['nilai_max_target']) : '-' ?>
                                            </div>
                                            <div class="d-none range-inputs" style="margin-top: 6px;">
                                                <div class="d-flex" style="gap: 6px;">
                                                    <input type="number" class="form-control form-control-sm text-center range-min" placeholder="Min" value="<?= isset($header['nilai_min_target']) && $header['nilai_min_target'] !== null ? htmlspecialchars($header['nilai_min_target']) : '' ?>">
                                                    <input type="number" class="form-control form-control-sm text-center range-max" placeholder="Max" min="0" max="99" value="<?= isset($header['nilai_max_target']) && $header['nilai_max_target'] !== null ? htmlspecialchars($header['nilai_max_target']) : '' ?>">
                                                </div>
                                            </div>
                                        </th>
                                    <?php endforeach; ?>
                                    <th style="width: 100px; vertical-align: middle;" rowspan="3" class="text-center">Rerata</th>
                                </tr>
                                <tr>
                                    <?php foreach ($grade_headers as $header): ?>
                                        <th class="text-center font-weight-normal activity-cell" data-header-id="<?= $header['id_header'] ?>" colspan="2" style="font-size: 0.85em; font-style: italic;">
                                            <div class="mb-1">
                                                <span class="activity-display font-weight-bold d-block"><?= htmlspecialchars($header['jenis_kegiatan'] ?? '-') ?></span>
                                                <textarea class="form-control form-control-sm activity-input d-none text-center" rows="2" placeholder="Jenis Kegiatan"><?= htmlspecialchars($header['jenis_kegiatan'] ?? '') ?></textarea>
                                            </div>
                                            <div>
                                                <span class="date-display small text-muted"><?= htmlspecialchars($header['tgl_kegiatan'] ?? '-') ?></span>
                                                <input type="date" class="form-control form-control-sm date-input d-none text-center" value="<?= htmlspecialchars($header['tgl_kegiatan'] ?? '') ?>">
                                            </div>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                                <tr>
                                    <?php foreach ($grade_headers as $header): ?>
                                        <th class="text-center sticky-header-col" style="font-size: 0.85em;">Nilai</th>
                                        <th class="text-center sticky-header-col" style="font-size: 0.85em;">Jadi</th>
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
                                                
                                                $val_float = $val !== '' ? (float)$val : 0;
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
                                                ?>
                                                <td class="text-center p-1">
                                                    <input type="number" 
                                                           class="form-control form-control-sm text-center grade-input grade-col-<?= $header['id_header'] ?>" 
                                                           data-student-id="<?= $student['id_siswa'] ?>" 
                                                           data-header-id="<?= $header['id_header'] ?>"
                                                            value="<?= $val_float > 0 ? (float)$val : '' ?>" 
                                                            disabled
                                                            placeholder="-">
                                                </td>
                                                <td class="text-center p-1">
                                                    <input type="number" 
                                                           class="form-control form-control-sm text-center grade-input-jadi grade-col-jadi-<?= $header['id_header'] ?>" 
                                                           data-student-id="<?= $student['id_siswa'] ?>" 
                                                           data-header-id="<?= $header['id_header'] ?>"
                                                           value="<?= $val_jadi !== '' && (float)$val_jadi > 0 ? (float)$val_jadi : '' ?>" 
                                                           disabled
                                                           placeholder="-">
                                                </td>
                                            <?php endforeach; ?>
                                            <td class="text-center font-weight-bold student-avg">
                                                <?= $count_score > 0 ? round($total_score / $count_score, 1) : '-' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- Footer Stats -->
                                    <tr class="bg-light font-weight-bold">
                                        <td colspan="2" class="text-right sticky-col sticky-col-footer">Nilai Tertinggi</td>
                                        <?php foreach ($grade_headers as $header): ?>
                                            <td class="text-center text-success col-max-<?= $header['id_header'] ?>">
                                                <?= isset($col_max[$header['id_header']]) ? $col_max[$header['id_header']] : '-' ?>
                                            </td>
                                            <td class="text-center text-success col-max-jadi-<?= $header['id_header'] ?>">
                                                <?php 
                                                $max_jadi = -INF;
                                                $found_jadi = false;
                                                foreach ($students as $s) {
                                                    $val_j = isset($grades_data[$s['id_siswa']][$header['id_header']]['nilai_jadi']) ? $grades_data[$s['id_siswa']][$header['id_header']]['nilai_jadi'] : '';
                                                    $val_j_f = $val_j !== '' ? (float)$val_j : 0;
                                                    if ($val_j_f > 0) {
                                                        if ($val_j_f > $max_jadi) $max_jadi = $val_j_f;
                                                        $found_jadi = true;
                                                    }
                                                }
                                                echo $found_jadi ? $max_jadi : '-';
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td class="text-center"></td>
                                    </tr>
                                    <tr class="bg-light font-weight-bold">
                                        <td colspan="2" class="text-right sticky-col sticky-col-footer">Nilai Terendah</td>
                                        <?php foreach ($grade_headers as $header): ?>
                                            <td class="text-center text-danger col-min-<?= $header['id_header'] ?>">
                                                <?= isset($col_min[$header['id_header']]) ? $col_min[$header['id_header']] : '-' ?>
                                            </td>
                                            <td class="text-center text-danger col-min-jadi-<?= $header['id_header'] ?>">
                                                <?php 
                                                $min_jadi = INF;
                                                $found_jadi = false;
                                                foreach ($students as $s) {
                                                    $val_j = isset($grades_data[$s['id_siswa']][$header['id_header']]['nilai_jadi']) ? $grades_data[$s['id_siswa']][$header['id_header']]['nilai_jadi'] : '';
                                                    $val_j_f = $val_j !== '' ? (float)$val_j : 0;
                                                    if ($val_j_f > 0) {
                                                        if ($val_j_f < $min_jadi) $min_jadi = $val_j_f;
                                                        $found_jadi = true;
                                                    }
                                                }
                                                echo $found_jadi ? $min_jadi : '-';
                                                ?>
                                            </td>
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

<!-- Modal Tambah Kolom -->
<div class="modal fade" id="addColumnModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Kolom Nilai Kokurikuler</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="addColumnForm">
                    <div class="form-group">
                        <label>Nama Penilaian (Contoh: K1, K2)</label>
                        <input type="text" class="form-control" name="nama_penilaian" required>
                    </div>
                    <div class="form-group">
                        <label>Jenis Kegiatan</label>
                        <textarea class="form-control" name="jenis_kegiatan" rows="3" required></textarea>
                    </div>
                    <div class="form-group">
                        <label>Waktu Kegiatan</label>
                        <input type="date" class="form-control" name="tgl_kegiatan" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label>Nilai Terendah</label>
                            <input type="number" class="form-control" name="nilai_min_target" placeholder="Kosongkan = KKTP">
                        </div>
                        <div class="form-group col-6">
                            <label>Nilai Tertinggi</label>
                            <input type="number" class="form-control" name="nilai_max_target" min="0" max="99" placeholder="Kosongkan = 99">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary" id="saveNewColumn">Simpan</button>
            </div>
        </div>
    </div>
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

    // Add Column
    $('#saveNewColumn').click(function() {
        var form = $('#addColumnForm');
        
        // Validation for Min/Max Target
        var minTarget = form.find('input[name="nilai_min_target"]').val();
        var maxTarget = form.find('input[name="nilai_max_target"]').val();

        if (!minTarget || !maxTarget) {
            Swal.fire({
                icon: 'warning',
                title: 'Validasi Gagal',
                text: 'Silakan masukkan nilai Terendah (Min) dan Tertinggi (Max) yang diinginkan terlebih dahulu.',
                confirmButtonColor: '#6777ef'
            });
            return;
        }

        var kktp = <?= json_encode(isset($selected_mapel['kktp']) ? (float)$selected_mapel['kktp'] : 0) ?>;
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

        var data = form.serialize() + '&action=add_column&id_kelas=<?= $selected_class_id ?>&id_mapel=<?= $selected_mapel_id ?>';
        
        $.ajax({
            url: 'ajax_nilai_kokurikuler.php',
            method: 'POST',
            data: data,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    Swal.fire('Gagal', response.message, 'error');
                }
            }
        });
    });

    // Delete Column
    $('.delete-col-btn').click(function() {
        var id = $(this).data('header-id');
        var name = $(this).data('name');
        
        Swal.fire({
            title: 'Hapus Kolom?',
            text: 'Yakin ingin menghapus kolom nilai "' + name + '"? Semua data nilai di kolom ini akan terhapus.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'ajax_nilai_kokurikuler.php',
                    method: 'POST',
                    data: { action: 'delete_column', id_header: id },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            Swal.fire('Gagal', response.message, 'error');
                        }
                    }
                });
            }
        });
    });

    // Edit Mode Toggle
    $('.edit-col-btn').click(function() {
        var id = $(this).data('header-id');
        
        // Show/Hide buttons
        $(this).addClass('d-none');
        $('.save-col-btn[data-header-id="' + id + '"]').removeClass('d-none');
        $('.delete-col-btn[data-header-id="' + id + '"]').prop('disabled', true);
        
        // Enable inputs
        $('.grade-col-' + id).prop('disabled', false);
        $('.grade-col-jadi-' + id).prop('disabled', true);
        
        // Toggle Header displays vs inputs
        var headerCell = $('.header-cell[data-header-id="' + id + '"]');
        headerCell.find('.nama-display').addClass('d-none');
        headerCell.find('.nama-input').removeClass('d-none');
        headerCell.find('.range-display').addClass('d-none');
        headerCell.find('.range-inputs').removeClass('d-none');

        // Toggle Activity/Date display vs input
        var activityCell = $('.activity-cell[data-header-id="' + id + '"]');
        activityCell.find('.activity-display').addClass('d-none');
        activityCell.find('.activity-input').removeClass('d-none');
        activityCell.find('.date-display').addClass('d-none');
        activityCell.find('.date-input').removeClass('d-none');
    });

    // Real-time updates
    $(document).on('input', '.grade-input', function() {
        var studentId = $(this).data('student-id');
        var headerId = $(this).data('header-id');
        
        updateRowAverage(studentId);
        updateColumnStats(headerId);
    });

    function updateRowAverage(studentId) {
        var total = 0;
        var count = 0;
        
        $('input.grade-input[data-student-id="' + studentId + '"]').each(function() {
            var val = $(this).val();
            if (val !== '') {
                var v = parseFloat(val);
                if (!isNaN(v) && v > 0) {
                    total += v;
                    count++;
                }
            }
        });
        
        var avg = count > 0 ? (total / count).toFixed(1) : '-';
        if (avg !== '-' && avg.endsWith('.0')) {
            avg = parseInt(avg, 10).toString();
        }
        
        var row = $('input.grade-input[data-student-id="' + studentId + '"]').first().closest('tr');
        row.find('.student-avg').text(avg);
    }

    function updateColumnStats(headerId) {
        var max = -Infinity;
        var min = Infinity;
        var hasData = false;
        
        var maxJadi = -Infinity;
        var minJadi = Infinity;
        var hasDataJadi = false;
        
        $('.grade-col-' + headerId).each(function() {
            var val = $(this).val();
            if (val !== '') {
                var v = parseFloat(val);
                if (!isNaN(v) && v > 0) {
                    if (v > max) max = v;
                    if (v < min) min = v;
                    hasData = true;
                }
            }
        });
        
        $('.grade-col-jadi-' + headerId).each(function() {
            var val = $(this).val();
            if (val !== '') {
                var v = parseFloat(val);
                if (!isNaN(v) && v > 0) {
                    if (v > maxJadi) maxJadi = v;
                    if (v < minJadi) minJadi = v;
                    hasDataJadi = true;
                }
            }
        });
        
        $('.col-max-' + headerId).text(hasData ? max : '-');
        $('.col-min-' + headerId).text(hasData ? min : '-');
        
        $('.col-max-jadi-' + headerId).text(hasDataJadi ? maxJadi : '-');
        $('.col-min-jadi-' + headerId).text(hasDataJadi ? minJadi : '-');
    }

    // Save Grades
    $('.save-col-btn').click(function() {
        var id = $(this).data('header-id');
        var btn = $(this);
        var kktp = <?= json_encode(isset($selected_mapel['kktp']) ? (float)$selected_mapel['kktp'] : 0) ?>;

        var headerCell = $('.header-cell[data-header-id="' + id + '"]');
        var namaVal = headerCell.find('.nama-input').val();
        var minTargetRaw = headerCell.find('.range-min').val();
        var maxTargetRaw = headerCell.find('.range-max').val();

        if (!minTargetRaw || !maxTargetRaw) {
            Swal.fire({
                icon: 'warning',
                title: 'Validasi Gagal',
                text: 'Silakan masukkan nilai Terendah (Min) dan Tertinggi (Max) yang diinginkan terlebih dahulu.',
                confirmButtonColor: '#6777ef'
            });
            return;
        }

        var kktp = <?= json_encode(isset($selected_mapel['kktp']) ? (float)$selected_mapel['kktp'] : 0) ?>;
        if (parseFloat(minTargetRaw) < kktp) {
            Swal.fire({
                icon: 'error',
                title: 'Nilai Tidak Valid',
                text: 'Nilai Minimal (Min) tidak boleh di bawah KKTP/KKM (' + kktp + ').',
                confirmButtonColor: '#6777ef'
            });
            return;
        }

        if (parseFloat(maxTargetRaw) > 99) {
            Swal.fire({
                icon: 'error',
                title: 'Nilai Tidak Valid',
                text: 'Nilai Maksimal (Max) tidak boleh lebih dari 99.',
                confirmButtonColor: '#6777ef'
            });
            return;
        }

        var minTarget = minTargetRaw !== '' ? parseFloat(minTargetRaw) : null;
        var maxTarget = maxTargetRaw !== '' ? parseFloat(maxTargetRaw) : null;

        var activityCell = $('.activity-cell[data-header-id="' + id + '"]');
        var jenis_kegiatan = activityCell.find('.activity-input').val();
        var tgl_kegiatan = activityCell.find('.date-input').val();

        if (typeof namaVal === 'string') {
            namaVal = namaVal.trim();
        }
        if (!namaVal) {
            Swal.fire('Gagal', 'Nama penilaian tidak boleh kosong', 'error');
            return;
        }

        if (minTarget !== null) {
            if (kktp > 0 && minTarget < kktp) {
                Swal.fire('Gagal', 'Nilai terendah tidak boleh di bawah KKTP (' + kktp + ')', 'error');
                return;
            }
        }
        if (maxTarget !== null) {
            if (maxTarget > 99) {
                Swal.fire('Gagal', 'Nilai tertinggi tidak boleh lebih dari 99', 'error');
                return;
            }
        }
        if (minTarget !== null && maxTarget !== null && minTarget > maxTarget) {
            Swal.fire('Gagal', 'Nilai terendah tidak boleh lebih besar dari nilai tertinggi', 'error');
            return;
        }

        var floorVal = (minTarget !== null) ? minTarget : (kktp > 0 ? kktp : 0);
        var inputs = $('.grade-col-' + id);
        var grades = [];
        var underCount = 0;

        inputs.each(function() {
            var val = $(this).val();
            var studentId = $(this).data('student-id');
            if (val !== '') {
                var n = parseFloat(val);
                if (!isFinite(n) || n < 0 || n > 100) {
                    grades = null;
                    return false;
                }
                if (floorVal > 0 && n > 0 && n < floorVal) {
                    underCount++;
                }
            }
            grades.push({
                id_siswa: studentId,
                nilai: val
            });
        });

        if (grades === null) {
            Swal.fire('Gagal', 'Pastikan semua nilai valid (0 s.d 100)', 'error');
            return;
        }

        var doSave = function() {
            btn.html('<i class="fas fa-spinner fa-spin"></i>');

            $.ajax({
                url: 'ajax_nilai_kokurikuler.php',
                method: 'POST',
                data: {
                    action: 'save_grades',
                    id_header: id,
                    grades: grades,
                    nama_penilaian: namaVal,
                    jenis_kegiatan: jenis_kegiatan,
                    tgl_kegiatan: tgl_kegiatan,
                    nilai_min_target: minTargetRaw,
                    nilai_max_target: maxTargetRaw
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        if (response.data && Array.isArray(response.data.grades)) {
                            response.data.grades.forEach(function(g) {
                                var selector = '.grade-col-jadi-' + id + '[data-student-id="' + g.id_siswa + '"]';
                                if (g.nilai_jadi === null || typeof g.nilai_jadi === 'undefined' || g.nilai_jadi <= 0) {
                                    $(selector).val('');
                                } else {
                                    $(selector).val(g.nilai_jadi);
                                }
                                updateRowAverage(g.id_siswa);
                            });
                            updateColumnStats(id);
                        }

                        // Update displays
                        headerCell.find('.nama-display').text(namaVal).removeClass('d-none');
                        headerCell.find('.nama-input').addClass('d-none');
                        var minText = (minTargetRaw !== '') ? minTargetRaw : '-';
                        var maxText = (maxTargetRaw !== '') ? maxTargetRaw : '-';
                        headerCell.find('.range-display').text('Min: ' + minText + ' | Max: ' + maxText).removeClass('d-none');
                        headerCell.find('.range-inputs').addClass('d-none');

                        activityCell.find('.activity-display').text(jenis_kegiatan ? jenis_kegiatan : '-').removeClass('d-none');
                        activityCell.find('.activity-input').addClass('d-none');
                        activityCell.find('.date-display').text(tgl_kegiatan ? tgl_kegiatan : '-').removeClass('d-none');
                        activityCell.find('.date-input').addClass('d-none');
                        
                        // Toggle buttons back
                        btn.addClass('d-none');
                        $('.edit-col-btn[data-header-id="' + id + '"]').removeClass('d-none');
                        $('.delete-col-btn[data-header-id="' + id + '"]').prop('disabled', false);
                        btn.html('<i class="fas fa-save"></i>');
                        
                        // Disable inputs
                        $('.grade-col-' + id).prop('disabled', true);
                        $('.grade-col-jadi-' + id).prop('disabled', true);
                        
                        Swal.fire({
                            title: 'Berhasil',
                            text: 'Data nilai dan kegiatan berhasil disimpan',
                            icon: 'success',
                            timer: 1500,
                            showConfirmButton: false
                        });
                    } else {
                        Swal.fire('Gagal', response.message, 'error');
                        btn.html('<i class="fas fa-save"></i>');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Terjadi kesalahan server', 'error');
                    btn.html('<i class="fas fa-save"></i>');
                }
            });
        };

        if (underCount > 0) {
            Swal.fire({
                title: 'Ada Nilai di Bawah Batas',
                text: underCount + ' nilai di bawah batas minimal (' + floorVal + '). Nilai jadi akan otomatis dikatrol minimal ' + floorVal + '. Lanjut simpan?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Lanjut',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    doSave();
                }
            });
        } else {
            doSave();
        }
    });
});
</script>
