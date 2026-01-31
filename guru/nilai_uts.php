<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['guru', 'wali'])) {
    redirect('../login.php');
}

$page_title = 'Nilai Tengah Semester';
$jenis_semester = 'UTS'; // Set this based on the file type

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
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th width="5%" class="text-center">No</th>
                                        <th class="text-center">Nama Siswa</th>
                                        <th width="15%" class="text-center">Nilai Asli</th>
                                        <th width="15%" class="text-center">Remidi</th>
                                        <th width="15%" class="text-center">Nilai Jadi</th>
                                        <th width="15%" class="text-center">Rerata</th>
                                        <th width="10%" class="text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $no = 1;
                                    foreach ($students as $student): 
                                        $id_siswa = $student['id_siswa'];
                                        $grade = $grades_data[$id_siswa] ?? null;
                                        $nilai_asli = $grade ? $grade['nilai_asli'] : 0;
                                        $nilai_remidi = $grade ? $grade['nilai_remidi'] : 0;
                                        $nilai_jadi = $grade ? $grade['nilai_jadi'] : 0;
                                        
                                        // Calculate Rerata logic: (Asli + Remidi) / 2 if Remidi > 0, else Asli
                                        $rerata = ($nilai_remidi > 0) ? ($nilai_asli + $nilai_remidi) / 2 : $nilai_asli;
                                    ?>
                                        <tr data-id-siswa="<?= $id_siswa ?>">
                                            <td class="text-center"><?= $no++ ?></td>
                                            <td><?= htmlspecialchars($student['nama_siswa']) ?></td>
                                            <td class="text-center">
                                                <span class="display-nilai-asli"><?= $nilai_asli > 0 ? $nilai_asli : '-' ?></span>
                                                <input type="number" class="form-control form-control-sm input-nilai-asli d-none" 
                                                       value="<?= $nilai_asli ?>" min="0" max="100">
                                            </td>
                                            <td class="text-center">
                                                <span class="display-nilai-remidi"><?= $nilai_remidi > 0 ? $nilai_remidi : '-' ?></span>
                                                <input type="number" class="form-control form-control-sm input-nilai-remidi d-none" 
                                                       value="<?= $nilai_remidi ?>" min="0" max="100">
                                            </td>
                                            <td class="text-center bg-light">
                                                <span class="display-nilai-jadi font-weight-bold"><?= $nilai_jadi > 0 ? $nilai_jadi : '-' ?></span>
                                            </td>
                                            <td class="text-center bg-light">
                                                <span class="display-rerata"><?= $rerata > 0 ? number_format($rerata, 1) : '-' ?></span>
                                            </td>
                                            <td class="text-center">
                                                <button class="btn btn-sm btn-warning btn-edit" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-success btn-save d-none" title="Simpan">
                                                    <i class="fas fa-save"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
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
    // Edit Button Click
    $('.btn-edit').click(function() {
        var tr = $(this).closest('tr');
        tr.find('.display-nilai-asli, .display-nilai-remidi').addClass('d-none');
        tr.find('.input-nilai-asli, .input-nilai-remidi').removeClass('d-none');
        tr.find('.btn-edit').addClass('d-none');
        tr.find('.btn-save').removeClass('d-none');
    });

    // Save Button Click
    $('.btn-save').click(function() {
        var tr = $(this).closest('tr');
        var id_siswa = tr.data('id-siswa');
        var nilai_asli = tr.find('.input-nilai-asli').val();
        var nilai_remidi = tr.find('.input-nilai-remidi').val();
        
        // Optimistic UI update
        var n_asli = parseFloat(nilai_asli) || 0;
        var n_remidi = parseFloat(nilai_remidi) || 0;
        
        var n_jadi = (n_remidi > n_asli) ? n_remidi : n_asli;
        var n_rerata = (n_remidi > 0) ? (n_asli + n_remidi) / 2 : n_asli;

        $.ajax({
            url: 'ajax_nilai_semester.php',
            method: 'POST',
            data: {
                action: 'save_grade',
                id_siswa: id_siswa,
                id_kelas: '<?= $selected_class_id ?>',
                id_mapel: '<?= $selected_mapel_id ?>',
                jenis_semester: '<?= $jenis_semester ?>',
                nilai_asli: nilai_asli,
                nilai_remidi: nilai_remidi
            },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    // Update displays
                    tr.find('.display-nilai-asli').text(n_asli > 0 ? n_asli : '-');
                    tr.find('.display-nilai-remidi').text(n_remidi > 0 ? n_remidi : '-');
                    tr.find('.display-nilai-jadi').text(n_jadi > 0 ? n_jadi : '-');
                    tr.find('.display-rerata').text(n_rerata > 0 ? n_rerata.toFixed(1) : '-');
                    
                    // Toggle view
                    tr.find('.display-nilai-asli, .display-nilai-remidi').removeClass('d-none');
                    tr.find('.input-nilai-asli, .input-nilai-remidi').addClass('d-none');
                    tr.find('.btn-edit').removeClass('d-none');
                    tr.find('.btn-save').addClass('d-none');
                    
                    // Show toast
                    iziToast.success({
                        title: 'Sukses',
                        message: 'Nilai berhasil disimpan',
                        position: 'topRight'
                    });
                } else {
                    iziToast.error({
                        title: 'Error',
                        message: response.message,
                        position: 'topRight'
                    });
                }
            },
            error: function() {
                iziToast.error({
                    title: 'Error',
                    message: 'Terjadi kesalahan server',
                    position: 'topRight'
                });
            }
        });
    });
});
</script>