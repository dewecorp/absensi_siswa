<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['guru', 'wali'])) {
    redirect('../login.php');
}

$page_title = 'Nilai Kokurikuler';

// Get teacher data
$id_guru = $_SESSION['user_id'];
if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
    $stmt = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $id_guru = $stmt->fetchColumn();
}

// Get teacher's classes
$stmt = $pdo->prepare("SELECT mengajar FROM tb_guru WHERE id_guru = ?");
$stmt->execute([$id_guru]);
$mengajar_json = $stmt->fetchColumn();
$mengajar_ids = json_decode($mengajar_json, true) ?? [];

$classes = [];
if (!empty($mengajar_ids)) {
    $placeholders = str_repeat('?,', count($mengajar_ids) - 1) . '?';
    $params = array_merge($mengajar_ids, $mengajar_ids);
    $stmt = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas IN ($placeholders) OR nama_kelas IN ($placeholders) ORDER BY nama_kelas ASC");
    $stmt->execute($params);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch subjects
$stmt = $pdo->prepare("
    SELECT DISTINCT mp.* 
    FROM tb_mata_pelajaran mp
    JOIN tb_jadwal_pelajaran jp ON mp.id_mapel = jp.mapel_id
    WHERE jp.guru_id = ?
    AND mp.nama_mapel NOT LIKE '%Asmaul Husna%'
    AND mp.nama_mapel NOT LIKE '%Upacara%'
    AND mp.nama_mapel NOT LIKE '%Istirahat%'
    AND mp.nama_mapel NOT LIKE '%Kepramukaan%'
    ORDER BY mp.nama_mapel ASC
");
$stmt->execute([$id_guru]);
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
    $stmt = $pdo->prepare("SELECT * FROM tb_nilai_kokurikuler_header WHERE id_guru = ? AND id_kelas = ? AND id_mapel = ? ORDER BY created_at ASC");
    $stmt->execute([$id_guru, $selected_class_id, $selected_mapel_id]);
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
                    <h4>Data Nilai Kokurikuler - <?= $selected_class['nama_kelas'] ?> - <?= $selected_mapel['nama_mapel'] ?></h4>
                    <div class="card-header-action">
                        <div class="btn-group mr-2">
                            <a href="export_nilai_kokurikuler_excel.php?kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>" target="_blank" class="btn btn-success">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </a>
                            <a href="export_nilai_kokurikuler_pdf.php?kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>" target="_blank" class="btn btn-danger">
                                <i class="fas fa-file-pdf"></i> Export PDF
                            </a>
                        </div>
                        <button class="btn btn-primary" data-toggle="modal" data-target="#addColumnModal">
                            <i class="fas fa-plus"></i> Tambah Kolom Nilai
                        </button>
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
                                            <div class="mb-2">
                                                <button class="btn btn-sm btn-icon btn-warning edit-col-btn" data-header-id="<?= $header['id_header'] ?>" title="Edit Nilai">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-icon btn-success save-col-btn d-none" data-header-id="<?= $header['id_header'] ?>" title="Simpan Nilai">
                                                    <i class="fas fa-save"></i>
                                                </button>
                                                <button class="btn btn-sm btn-icon btn-info auto-calc-btn d-none" data-header-id="<?= $header['id_header'] ?>" title="Hitung Nilai Jadi (Otomatis)">
                                                    <i class="fas fa-magic"></i>
                                                </button>
                                                <button class="btn btn-sm btn-icon btn-danger delete-col-btn" data-header-id="<?= $header['id_header'] ?>" data-name="<?= $header['nama_penilaian'] ?>" title="Hapus Kolom">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                            <?= htmlspecialchars($header['nama_penilaian']) ?>
                                        </th>
                                    <?php endforeach; ?>
                                    <th style="width: 100px; vertical-align: middle;" rowspan="3">Rerata</th>
                                </tr>
                                <tr>
                                    <?php foreach ($grade_headers as $header): ?>
                                        <th class="text-center bg-white font-weight-normal activity-cell" data-header-id="<?= $header['id_header'] ?>" colspan="2" style="font-size: 0.85em; font-style: italic;">
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
                                                    <input type="number" 
                                                           class="form-control form-control-sm text-center grade-input grade-col-<?= $header['id_header'] ?>" 
                                                           data-student-id="<?= $student['id_siswa'] ?>" 
                                                           data-header-id="<?= $header['id_header'] ?>"
                                                           value="<?= $val ?>" 
                                                           disabled
                                                           min="0" max="100" placeholder="-">
                                                </td>
                                                <td class="text-center p-1">
                                                    <input type="number" 
                                                           class="form-control form-control-sm text-center grade-input-jadi grade-col-jadi-<?= $header['id_header'] ?>" 
                                                           data-student-id="<?= $student['id_siswa'] ?>" 
                                                           data-header-id="<?= $header['id_header'] ?>"
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
                    iziToast.error({title: 'Error', message: response.message, position: 'topRight'});
                }
            }
        });
    });

    // Delete Column
    $('.delete-col-btn').click(function() {
        var id = $(this).data('header-id');
        var name = $(this).data('name');
        
        if (confirm('Yakin ingin menghapus kolom penilaian ' + name + '? Semua nilai siswa di kolom ini akan terhapus.')) {
            $.ajax({
                url: 'ajax_nilai_kokurikuler.php',
                method: 'POST',
                data: { action: 'delete_column', id_header: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        iziToast.error({title: 'Error', message: response.message, position: 'topRight'});
                    }
                }
            });
        }
    });

    // Edit Mode Toggle
    $('.edit-col-btn').click(function() {
        var id = $(this).data('header-id');
        var cell = $('.activity-cell[data-header-id="' + id + '"]');
        
        // Show/Hide buttons
        $(this).addClass('d-none');
        $('.save-col-btn[data-header-id="' + id + '"]').removeClass('d-none');
        $('.auto-calc-btn[data-header-id="' + id + '"]').removeClass('d-none');
        
        // Enable inputs
        $('.grade-col-' + id).prop('disabled', false);
        $('.grade-col-jadi-' + id).prop('disabled', false);
        
        // Toggle Activity/Date display vs input
        cell.find('.activity-display').addClass('d-none');
        cell.find('.activity-input').removeClass('d-none');
        
        cell.find('.date-display').addClass('d-none');
        cell.find('.date-input').removeClass('d-none');
    });

    // Auto Calc Button
    $('.auto-calc-btn').click(function() {
        var id = $(this).data('header-id');
        var kktp = <?= json_encode($selected_mapel['kktp'] ?? 75) ?>; // Default 75
        
        Swal.fire({
            title: 'Hitung Nilai Jadi Otomatis',
            text: 'Nilai di bawah KKTP (' + kktp + ') akan diset menjadi KKTP. Nilai di atas KKTP akan disesuaikan secara proporsional.',
            icon: 'info',
            showCancelButton: true,
            confirmButtonText: 'Ya, Hitung!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                $('.grade-col-' + id).each(function() {
                    var studentId = $(this).data('student-id');
                    var nilaiAwal = parseFloat($(this).val());
                    
                    if (!isNaN(nilaiAwal) && nilaiAwal > 0) {
                        var nilaiJadi;
                        if (nilaiAwal < kktp) {
                            nilaiJadi = kktp;
                        } else {
                            // Curve logic similar to nilai_harian
                            var range = 100 - kktp;
                            if (range > 0) {
                                var ratio = (nilaiAwal - kktp) / range;
                                var ratioBoosted = 1 - Math.pow(1 - ratio, 2);
                                nilaiJadi = kktp + (range * ratioBoosted);
                            } else {
                                nilaiJadi = nilaiAwal;
                            }
                        }
                        nilaiJadi = Math.round(nilaiJadi);
                        if (nilaiJadi > 100) nilaiJadi = 100;
                        
                        // Always update, even if 0
                        $('.grade-col-jadi-' + id + '[data-student-id="' + studentId + '"]').val(nilaiJadi);
                    }
                });
                iziToast.success({message: 'Nilai jadi berhasil dihitung ulang', position: 'topRight'});
            }
        });
    });

    // Real-time updates
    $(document).on('input', '.grade-input', function() {
        var studentId = $(this).data('student-id');
        var val = $(this).val();
        
        // Auto-fill Nilai Jadi if empty or 0
        var jadiInput = $('.grade-col-jadi-' + $(this).data('header-id') + '[data-student-id="' + studentId + '"]');
        if (val !== '' && (jadiInput.val() === '' || jadiInput.val() == 0)) {
            jadiInput.val(val);
        }
        
        updateRowAverage(studentId);
        updateColumnStats($(this).data('header-id'));
    });

    function updateRowAverage(studentId) {
        var total = 0;
        var count = 0;
        
        $('input.grade-input[data-student-id="' + studentId + '"]').each(function() {
            var val = parseFloat($(this).val());
            if (!isNaN(val) && val > 0) {
                total += val;
                count++;
            }
        });
        
        var avg = count > 0 ? (total / count).toFixed(1) : '-';
        
        var row = $('input.grade-input[data-student-id="' + studentId + '"]').first().closest('tr');
        row.find('.student-avg').text(avg);
    }

    function updateColumnStats(headerId) {
        var max = -Infinity;
        var min = Infinity;
        var hasData = false;
        
        $('.grade-col-' + headerId).each(function() {
            var val = parseFloat($(this).val());
            if (!isNaN(val) && val > 0) {
                if (val > max) max = val;
                if (val < min) min = val;
                hasData = true;
            }
        });
        
        var maxText = hasData ? max : '-';
        var minText = hasData ? min : '-';
        
        $('.col-max-' + headerId).text(maxText);
        $('.col-min-' + headerId).text(minText);
    }

    // Save Grades
    $('.save-col-btn').click(function() {
        var id = $(this).data('header-id');
        var btn = $(this);
        var cell = $('.activity-cell[data-header-id="' + id + '"]');
        
        var grades = [];
        $('.grade-col-' + id).each(function() {
            var studentId = $(this).data('student-id');
            var val = $(this).val();
            var valJadi = $('.grade-col-jadi-' + id + '[data-student-id="' + studentId + '"]').val();
            
            grades.push({
                id_siswa: studentId,
                nilai: val,
                nilai_jadi: valJadi
            });
        });

        var jenis_kegiatan = cell.find('.activity-input').val();
        var tgl_kegiatan = cell.find('.date-input').val();

        $.ajax({
            url: 'ajax_nilai_kokurikuler.php',
            method: 'POST',
            data: {
                action: 'save_grades',
                id_header: id,
                grades: grades,
                jenis_kegiatan: jenis_kegiatan,
                tgl_kegiatan: tgl_kegiatan
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Update displays
                    cell.find('.activity-display').text(jenis_kegiatan).removeClass('d-none');
                    cell.find('.activity-input').addClass('d-none');
                    
                    cell.find('.date-display').text(tgl_kegiatan).removeClass('d-none');
                    cell.find('.date-input').addClass('d-none');
                    
                    // Toggle buttons
                    btn.addClass('d-none');
                    $('.edit-col-btn[data-header-id="' + id + '"]').removeClass('d-none');
                    $('.auto-calc-btn[data-header-id="' + id + '"]').addClass('d-none');
                    
                    // Disable inputs
                    $('.grade-col-' + id).prop('disabled', true);
                    $('.grade-col-jadi-' + id).prop('disabled', true);
                    
                    iziToast.success({title: 'Sukses', message: 'Data berhasil disimpan', position: 'topRight'});
                    
                    // Update stats (optional, requires reload or complex JS. Reload is easiest for stats update)
                    // But we can do simple avg update here if we want.
                    // For now, let's just leave it or reload? Reload is safer for consistent stats.
                    // location.reload(); // Uncomment if full refresh needed
                } else {
                    iziToast.error({title: 'Error', message: response.message, position: 'topRight'});
                }
            }
        });
    });
});
</script>
