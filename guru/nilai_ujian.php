<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['guru', 'wali', 'kepala_madrasah', 'tata_usaha', 'admin'])) {
    redirect('../login.php');
}

ensure_nilai_semester_enum_ujian_praktik($pdo);
$page_title = nilai_ujian_page_title();
$jenis_semester = nilai_ujian_jenis_semester();
$ujian_praktik_tanpa_remidi = nilai_ujian_is_praktik_mode();
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

// Fetch classes - only Kelas 6 for Ujian
$classes = [];
if ($is_admin_view) {
    $stmt = $pdo->query("SELECT * FROM tb_kelas WHERE nama_kelas LIKE '%6%' OR nama_kelas LIKE '%VI%' ORDER BY nama_kelas ASC");
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $classes = getTeacherAccessibleClasses($pdo, $id_guru, true);
}

// Get active semester info
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'];
$semester_aktif = $school_profile['semester'];

// Fetch subjects
$subjects = [];
if ($is_admin_view) {
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
} else {
    $stmt = $pdo->prepare("
        SELECT DISTINCT mp.* 
        FROM tb_mata_pelajaran mp
        JOIN tb_jadwal_pelajaran jp ON mp.id_mapel = jp.mapel_id
        WHERE jp.guru_id = ?
        AND mp.nama_mapel NOT LIKE '%Asmaul Husna%'
        AND mp.nama_mapel NOT LIKE '%Upacara%'
        AND mp.nama_mapel NOT LIKE '%Istirahat%'
        AND mp.nama_mapel NOT LIKE '%Kepramukaan%'
        AND mp.nama_mapel NOT LIKE '%Ekstrakurikuler%'
        ORDER BY mp.nama_mapel ASC
    ");
    $stmt->execute([$id_guru]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($user_role === 'wali' && empty($subjects)) {
        $subjects = getFilteredSubjects($pdo);
    }
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
        min-width: 250px;
        max-width: 400px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    /* Sticky Header */
    thead th {
        position: sticky !important;
        top: 0;
        background-color: #f8f9fa !important;
        z-index: 15;
        box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.1);
        padding: 8px !important;
    }
    
    /* Sticky Header + Sticky Column Intersection */
    thead th.sticky-col {
        z-index: 25 !important;
    }

    /* Input styling */
    .grade-input {
        position: relative;
        z-index: 1;
        min-width: 60px;
    }

    /* Mobile adjustments */
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
        thead th, tbody td {
            font-size: 0.75em !important;
            padding: 4px 2px !important;
        }
        .grade-input {
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
    
    /* Solid background for sticky columns */
    .sticky-col {
        background-color: #ffffff !important;
    }

    table {
        border-collapse: separate !important;
        border-spacing: 0 !important;
        width: 100%;
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
                            <div class="d-flex justify-content-end align-items-center" style="gap: 10px; flex-wrap: wrap;">
                                <div class="d-flex align-items-center" style="gap: 8px;">
                                    <span class="badge badge-light">KKTP: <?= (float)$kktp ?></span>
                                    <input type="number" class="form-control form-control-sm text-center" id="nilai_min_target" style="width: 90px;" placeholder="Min" value="<?= $nilai_min_target_setting !== null ? (float)$nilai_min_target_setting : '' ?>" <?= $can_edit ? '' : 'disabled' ?>>
                                    <input type="number" class="form-control form-control-sm text-center" id="nilai_max_target" style="width: 90px;" min="0" max="99" placeholder="Max" value="<?= $nilai_max_target_setting !== null ? (float)$nilai_max_target_setting : '' ?>" <?= $can_edit ? '' : 'disabled' ?>>
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
                                        <th class="text-center sticky-col sticky-col-1" style="width: 50px; vertical-align: middle;">No</th>
                                        <th class="text-center sticky-col sticky-col-2" style="width: 250px; vertical-align: middle;">Nama Siswa</th>
                                        <th style="width: 120px; vertical-align: middle;" class="text-center">
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
                                        <?php if (!$ujian_praktik_tanpa_remidi): ?>
                                        <th style="width: 100px; vertical-align: middle;" class="text-center">Remidi</th>
                                        <?php endif; ?>
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
                                        <tr data-id-siswa="<?= $id_siswa ?>">
                                            <td class="text-center sticky-col sticky-col-1"><?= $no++ ?></td>
                                            <td class="sticky-col sticky-col-2"><?= htmlspecialchars($student['nama_siswa']) ?></td>
                                            <td class="text-center">
                                                <input type="number" class="form-control form-control-sm input-nilai-asli text-center"
                                                       value="<?= $nilai_asli > 0 ? (float)$nilai_asli : '' ?>" min="0" max="100" style="max-width: 90px;" disabled>
                                            </td>
                                            <?php if (!$ujian_praktik_tanpa_remidi): ?>
                                            <td class="text-center">
                                                <input type="number" class="form-control form-control-sm input-nilai-remidi text-center"
                                                       value="<?= $nilai_remidi > 0 ? (float)$nilai_remidi : '' ?>" min="0" max="100" style="max-width: 90px;" disabled>
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

<script>
$(document).ready(function() {
    var ujianPraktikTanpaRemidi = <?= $ujian_praktik_tanpa_remidi ? 'true' : 'false' ?>;
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
        $('.input-nilai-asli').prop('disabled', !editing);
        if (!ujianPraktikTanpaRemidi) {
            $('.input-nilai-remidi').prop('disabled', !editing);
        }
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
                nilai_remidi: ujianPraktikTanpaRemidi ? '' : tr.find('.input-nilai-remidi').val()
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
                            if (!ujianPraktikTanpaRemidi) {
                                tr.find('.input-nilai-remidi').val(remidi > 0 ? remidi : '');
                            }
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

    function updateSummaryStats() {
        var min_asli = null, max_asli = null;
        var min_remidi = null, max_remidi = null;
        var min_jadi = null, max_jadi = null;

        $('tbody tr[data-id-siswa]').each(function() {
            var tr = $(this);
            var asli = parseFloat(tr.find('.input-nilai-asli').val()) || 0;
            var remidi = ujianPraktikTanpaRemidi ? 0 : (parseFloat(tr.find('.input-nilai-remidi').val()) || 0);
            var jadiTxt = tr.find('.display-nilai-jadi').text().trim();
            var jadi = jadiTxt === '-' ? 0 : (parseFloat(jadiTxt) || 0);

            if (asli > 0) {
                if (min_asli === null || asli < min_asli) min_asli = asli;
                if (max_asli === null || asli > max_asli) max_asli = asli;
            }
            if (!ujianPraktikTanpaRemidi && remidi > 0) {
                if (min_remidi === null || remidi < min_remidi) min_remidi = remidi;
                if (max_remidi === null || remidi > max_remidi) max_remidi = remidi;
            }
            if (jadi > 0) {
                if (min_jadi === null || jadi < min_jadi) min_jadi = jadi;
                if (max_jadi === null || jadi > max_jadi) max_jadi = jadi;
            }
        });

        $('#max-asli').text(max_asli !== null ? max_asli : '-');
        $('#min-asli').text(min_asli !== null ? min_asli : '-');
        
        if (!ujianPraktikTanpaRemidi) {
            $('#max-remidi').text(max_remidi !== null ? max_remidi : '-');
            $('#min-remidi').text(min_remidi !== null ? min_remidi : '-');
        }
        
        $('#max-jadi').text(max_jadi !== null ? max_jadi : '-');
        $('#min-jadi').text(min_jadi !== null ? min_jadi : '-');
    }
});
</script>
