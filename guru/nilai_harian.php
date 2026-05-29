<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['guru', 'wali', 'kepala_madrasah', 'tata_usaha', 'admin'])) {
    redirect('../login.php');
}

$page_title = 'Nilai Harian';
$user_role = $_SESSION['level'];
$is_admin_view = in_array($user_role, ['kepala_madrasah', 'tata_usaha', 'admin']);
$can_edit = !$is_admin_view;

ensure_nilai_harian_header_minmax($pdo);

// Get teacher data
$id_guru = null;
if (!$is_admin_view) {
    $id_guru = $_SESSION['user_id'];
    
    if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
        $stmt = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $id_guru = $stmt->fetchColumn();
    }
    
    // Fallback for wali
    if ((!$id_guru || $id_guru == 0) && isset($_SESSION['nama_guru'])) {
        $stmt = $pdo->prepare("SELECT id_guru FROM tb_guru WHERE nama_guru = ? LIMIT 1");
        $stmt->execute([$_SESSION['nama_guru']]);
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
    // Fetch subjects from schedule - ONLY academic subjects
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

    // For wali, if no subjects from schedule, get all academic subjects
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
    if ($is_admin_view) {
        $stmt = $pdo->prepare("SELECT * FROM tb_nilai_harian_header WHERE id_kelas = ? AND id_mapel = ? AND tahun_ajaran = ? AND semester = ? ORDER BY created_at ASC");
        $stmt->execute([$selected_class_id, $selected_mapel_id, $tahun_ajaran, $semester_aktif]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM tb_nilai_harian_header WHERE id_guru = ? AND id_kelas = ? AND id_mapel = ? AND tahun_ajaran = ? AND semester = ? ORDER BY created_at ASC");
        $stmt->execute([$id_guru, $selected_class_id, $selected_mapel_id, $tahun_ajaran, $semester_aktif]);
    }
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
        min-width: 250px; /* Kembali lebar untuk desktop */
        max-width: 400px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    /* Sticky Header */
    thead th {
        position: sticky !important;
        background-color: #ffffff !important;
        z-index: 100;
        box-shadow: inset 0 1px 0 #dee2e6, inset 0 -1px 0 #dee2e6;
        vertical-align: middle;
        padding: 8px !important; /* Kembali ke padding normal desktop */
    }

    /* Multi-row Header Sticky Offsets */
    thead tr:nth-child(1) th {
        top: 0;
        z-index: 103;
        height: 80px;
    }
    thead tr:nth-child(2) th {
        top: 80px;
        z-index: 102;
    }
    thead tr:nth-child(3) th {
        top: 120px;
        z-index: 101;
    }
    
    /* Sticky Header + Sticky Column Intersection */
    thead th.sticky-col {
        z-index: 110 !important;
    }

    /* Input styling */
    .grade-input, .grade-input-jadi {
        position: relative;
        z-index: 1;
        min-width: 60px;
    }
    
    /* Mobile specific adjustments */
    @media (max-width: 768px) {
        .sticky-col-1 {
            width: 35px !important;
            min-width: 35px !important;
        }
        .sticky-col-2 {
            left: 35px !important;
            min-width: 110px !important;
            max-width: 110px !important;
            font-size: 0.75em;
        }
        thead th {
            padding: 4px 2px !important;
            font-size: 0.75em !important;
        }
        .header-cell {
            min-width: 110px !important;
        }
        thead tr:nth-child(1) th { height: 55px !important; }
        thead tr:nth-child(2) th { top: 55px !important; height: 32px !important; }
        thead tr:nth-child(3) th { top: 87px !important; height: 28px !important; }
        .grade-input, .grade-input-jadi {
            min-width: 45px !important;
            max-width: 55px !important;
            padding: 2px 1px !important;
            height: 28px !important;
            font-size: 0.85em !important;
        }
        .btn-sm {
            padding: 0.2rem 0.4rem !important;
            font-size: 0.7rem !important;
        }
    }
    
    /* Tambahkan background solid pada sticky columns */
    .sticky-col {
        background-color: #ffffff !important;
    }

    /* Ensure table body is not covered too much */
    table {
        border-collapse: separate !important;
        border-spacing: 0 !important;
    }
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><?= $page_title ?></h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item"><a href="#">Nilai Siswa</a></div>
                <div class="breadcrumb-item">Nilai Harian</div>
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
                    <h4>Data Nilai Harian - <?= $selected_class['nama_kelas'] ?> - <?= $selected_mapel['nama_mapel'] ?></h4>
                    <div class="card-header-action">
                        <div class="btn-group mr-2">
                            <a href="export_nilai_harian_excel?session_type=<?= $_SESSION['level'] ?>&kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>" target="_blank" class="btn btn-success">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </a>
                            <a href="export_nilai_harian_pdf?session_type=<?= $_SESSION['level'] ?>&kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>" target="_blank" class="btn btn-danger">
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
                                    <th class="sticky-col sticky-col-1" style="width: 50px; vertical-align: middle;" rowspan="3">No</th>
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
                                        <th class="text-center font-weight-normal materi-cell" data-header-id="<?= $header['id_header'] ?>" colspan="2" style="font-size: 0.85em; font-style: italic;">
                                            <span class="materi-display"><?= htmlspecialchars($header['materi'] ?? '-') ?></span>
                                            <textarea class="form-control form-control-sm materi-input d-none text-center" rows="2" placeholder="Materi"><?= htmlspecialchars($header['materi'] ?? '') ?></textarea>
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
                                                
                                                if ($val !== '') {
                                                    $val_float = (float)$val;
                                                    if ($val_float > 0) {
                                                        $total_score += $val_float;
                                                        $count_score++;
                                                        
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
                                                    <input type="number" 
                                                           class="form-control form-control-sm text-center grade-input grade-col-<?= $header['id_header'] ?>" 
                                                           data-student-id="<?= $student['id_siswa'] ?>" 
                                                           data-header-id="<?= $header['id_header'] ?>"
                                                           value="<?= $val ?>" 
                                                           disabled
                                                           placeholder="-">
                                                </td>
                                                <td class="text-center p-1">
                                                    <input type="number" 
                                                           class="form-control form-control-sm text-center grade-input-jadi grade-col-jadi-<?= $header['id_header'] ?>" 
                                                           data-student-id="<?= $student['id_siswa'] ?>" 
                                                           data-header-id="<?= $header['id_header'] ?>"
                                                           value="<?= $val_jadi ?>" 
                                                           disabled
                                                           placeholder="-">
                                                </td>
                                            <?php endforeach; ?>
                                            <td class="text-center font-weight-bold rerata-siswa" data-student-id="<?= $student['id_siswa'] ?>">
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
                                            <td class="text-center text-danger col-min-<?= $header['id_header'] ?>">
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
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- Modal Add Column -->
<div class="modal fade" id="addColumnModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Kolom Nilai</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="addColumnForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_column">
                    <input type="hidden" name="id_kelas" value="<?= $selected_class_id ?>">
                    <input type="hidden" name="id_mapel" value="<?= $selected_mapel_id ?>">
                    <div class="form-group">
                        <label>Nama Penilaian</label>
                        <input type="text" class="form-control" name="nama_penilaian" placeholder="Contoh: UH 1, Tugas 1, dll" required>
                    </div>
                    <div class="form-group">
                        <label>Materi</label>
                        <textarea class="form-control" name="materi" placeholder="Deskripsi materi/topik" rows="3"></textarea>
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
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
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

    // Add Column
    $('#addColumnForm').submit(function(e) {
        e.preventDefault();

        // Validation for Min/Max Target
        var minTarget = $(this).find('input[name="nilai_min_target"]').val();
        var maxTarget = $(this).find('input[name="nilai_max_target"]').val();

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

        $.ajax({
            url: 'ajax_nilai_harian.php',
            type: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                if(response.success) {
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
                    url: 'ajax_nilai_harian.php',
                    type: 'POST',
                    data: {
                        action: 'delete_column',
                        id_header: id
                    },
                    success: function(response) {
                        if(response.success) {
                            location.reload();
                        } else {
                            Swal.fire('Gagal', response.message, 'error');
                        }
                    }
                });
            }
        });
    });

    // Edit Column (Enable Inputs)
    $('.edit-col-btn').click(function() {
        var id = $(this).data('header-id');
        // Toggle buttons
        $(this).addClass('d-none');
        $(this).siblings('.save-col-btn').removeClass('d-none');
        $(this).siblings('.delete-col-btn').prop('disabled', true);
        
        // Enable inputs
        $('.grade-col-' + id).prop('disabled', false);
        $('.grade-col-jadi-' + id).prop('disabled', true);

        // Enable Materi Edit
        var materiCell = $('.materi-cell[data-header-id="' + id + '"]');
        materiCell.find('.materi-display').addClass('d-none');
        materiCell.find('.materi-input').removeClass('d-none');

        var headerCell = $('.header-cell[data-header-id="' + id + '"]');
        headerCell.find('.nama-display').addClass('d-none');
        headerCell.find('.nama-input').removeClass('d-none');
        headerCell.find('.range-display').addClass('d-none');
        headerCell.find('.range-inputs').removeClass('d-none');
    });

    // Save Column (Save Values)
    $('.save-col-btn').click(function() {
        var id = $(this).data('header-id');
        var btn = $(this);
        var inputs = $('.grade-col-' + id);
        var grades = [];
        var kktp = <?= json_encode(isset($selected_mapel['kktp']) ? (float)$selected_mapel['kktp'] : 0) ?>;

        // Get Materi Value
        var materiCell = $('.materi-cell[data-header-id="' + id + '"]');
        var materiVal = materiCell.find('.materi-input').val();

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

        if (typeof namaVal === 'string') {
            namaVal = namaVal.trim();
        }
        if (!namaVal) {
            Swal.fire('Gagal', 'Nama penilaian tidak boleh kosong', 'error');
            return;
        }
        if (minTarget !== null) {
            if (!isFinite(minTarget)) {
                Swal.fire('Gagal', 'Nilai terendah tidak valid', 'error');
                return;
            }
            if (kktp > 0 && minTarget < kktp) {
                Swal.fire('Gagal', 'Nilai terendah tidak boleh di bawah KKTP (' + kktp + ')', 'error');
                return;
            }
        }
        if (maxTarget !== null) {
            if (!isFinite(maxTarget)) {
                Swal.fire('Gagal', 'Nilai tertinggi tidak valid', 'error');
                return;
            }
            if (maxTarget > 99) {
                Swal.fire('Gagal', 'Nilai tertinggi tidak boleh lebih dari 99', 'error');
                return;
            }
            if (kktp > 0 && maxTarget < kktp) {
                Swal.fire('Gagal', 'Nilai tertinggi tidak boleh di bawah KKTP (' + kktp + ')', 'error');
                return;
            }
        }
        if (minTarget !== null && maxTarget !== null && minTarget > maxTarget) {
            Swal.fire('Gagal', 'Nilai terendah tidak boleh lebih besar dari nilai tertinggi', 'error');
            return;
        }

        var floorVal = (minTarget !== null) ? minTarget : (kktp > 0 ? kktp : 0);

        inputs.each(function() {
            var val = $(this).val();
            var studentId = $(this).data('student-id');
            if (val !== '') {
                var n = parseFloat(val);
                if (!isFinite(n) || n < 0 || n > 100) {
                    grades = null;
                    return false;
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

        var underCount = 0;
        if (floorVal > 0) {
            inputs.each(function() {
                var v = parseFloat($(this).val());
                if (isFinite(v) && v > 0 && v < floorVal) {
                    underCount++;
                }
            });
        }

        // Show loading state
        var doSave = function() {
            btn.html('<i class="fas fa-spinner fa-spin"></i>');

            $.ajax({
                url: 'ajax_nilai_harian.php',
                type: 'POST',
                data: {
                    action: 'save_grades',
                    id_header: id,
                    grades: grades,
                    materi: materiVal,
                    nama_penilaian: namaVal,
                    nilai_min_target: minTargetRaw,
                    nilai_max_target: maxTargetRaw
                },
                success: function(response) {
                    var res = (typeof response === 'string') ? JSON.parse(response) : response;

                    if(res.success) {
                        if (res.data && Array.isArray(res.data.grades)) {
                            res.data.grades.forEach(function(g) {
                                var selector = '.grade-col-jadi-' + id + '[data-student-id="' + g.id_siswa + '"]';
                                if (g.nilai_jadi === null || typeof g.nilai_jadi === 'undefined') {
                                    $(selector).val('');
                                } else {
                                    $(selector).val(g.nilai_jadi);
                                }
                            });
                        }

                        Swal.fire({
                            title: 'Berhasil',
                            text: 'Data nilai dan materi berhasil disimpan',
                            icon: 'success',
                            timer: 1500,
                            showConfirmButton: false
                        });
                    
                        // Toggle buttons back
                        btn.addClass('d-none');
                        btn.siblings('.edit-col-btn').removeClass('d-none');
                        btn.siblings('.delete-col-btn').prop('disabled', false);
                        btn.html('<i class="fas fa-save"></i>');
                    
                        // Disable inputs
                        inputs.prop('disabled', true);
                        $('.grade-col-jadi-' + id).prop('disabled', true);

                        // Update Materi Display and Toggle Back
                        var displayVal = materiVal ? materiVal : '-';
                        materiCell.find('.materi-display').text(displayVal).removeClass('d-none');
                        materiCell.find('.materi-input').addClass('d-none');

                        headerCell.find('.nama-display').text(namaVal).removeClass('d-none');
                        headerCell.find('.nama-input').addClass('d-none');
                        var minText = (minTargetRaw !== '') ? minTargetRaw : '-';
                        var maxText = (maxTargetRaw !== '') ? maxTargetRaw : '-';
                        headerCell.find('.range-display').text('Min: ' + minText + ' | Max: ' + maxText).removeClass('d-none');
                        headerCell.find('.range-inputs').addClass('d-none');
                        btn.siblings('.delete-col-btn').attr('data-name', namaVal);

                    } else {
                        Swal.fire('Gagal', res.message, 'error');
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
                title: 'Ada Nilai di Bawah KKTP',
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
            var v = parseFloat($(this).val());
            if (!isNaN(v) && v > 0) {
                total += v;
                count++;
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

        $('.grade-col-' + headerId).each(function() {
            var v = parseFloat($(this).val());
            if (!isNaN(v) && v > 0) {
                if (v > max) max = v;
                if (v < min) min = v;
                hasData = true;
            }
        });

        var maxText = hasData ? max : '-';
        var minText = hasData ? min : '-';

        $('.col-max-' + headerId).text(maxText);
        $('.col-min-' + headerId).text(minText);
    }
});
</script>
