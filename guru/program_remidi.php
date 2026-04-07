<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has guru or wali level
if (!isAuthorized(['guru', 'wali'])) {
    redirect('../login.php');
}

$page_title = 'Program Remidial';
$user_role = $_SESSION['level'];

// Get teacher data
$id_guru = $_SESSION['user_id'];
if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
    $stmt = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $id_guru = $stmt->fetchColumn();
}

// Fetch classes
$classes = getTeacherAccessibleClasses($pdo, $id_guru);

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
    AND mp.nama_mapel NOT LIKE '%Ekstrakurikuler%'
    ORDER BY mp.nama_mapel ASC
");
$stmt->execute([$id_guru]);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($user_role === 'wali' && empty($subjects)) {
    $subjects = getFilteredSubjects($pdo);
}

// Filter exam types
$exam_types = ['UTS', 'UAS', 'PAT', 'Pra Ujian', 'Ujian'];

// Selected filters
$selected_class_id = isset($_GET['kelas']) ? $_GET['kelas'] : (count($classes) == 1 ? $classes[0]['id_kelas'] : null);
$selected_mapel_id = isset($_GET['mapel']) ? $_GET['mapel'] : null;
$selected_exam_type = isset($_GET['jenis']) ? $_GET['jenis'] : null;

// Get school profile
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'];
$semester_aktif = $school_profile['semester'];

// Handle AJAX: Get students needing remedial
if (isset($_GET['action']) && $_GET['action'] == 'get_remedial_students') {
    header('Content-Type: application/json');
    try {
        $id_kelas = $_GET['id_kelas'];
        $id_mapel = $_GET['id_mapel'];
        $jenis = $_GET['jenis'];
        
        // Get KKM for the subject
        $stmt = $pdo->prepare("SELECT kktp FROM tb_mata_pelajaran WHERE id_mapel = ?");
        $stmt->execute([$id_mapel]);
        $kkm = $stmt->fetchColumn() ?: 75;

        // Get students with grades < KKM
        $stmt = $pdo->prepare("
            SELECT s.id_siswa, s.nama_siswa, n.nilai_asli
            FROM tb_siswa s
            JOIN tb_nilai_semester n ON s.id_siswa = n.id_siswa
            WHERE s.id_kelas = ? 
            AND n.id_mapel = ? 
            AND n.jenis_semester = ? 
            AND n.tahun_ajaran = ? 
            AND n.semester = ?
            AND n.nilai_asli < ?
            AND s.id_siswa NOT IN (
                SELECT id_siswa FROM tb_program_remidial 
                WHERE id_mapel = ? AND jenis_ulangan = ? AND tahun_ajaran = ? AND semester = ?
            )
            ORDER BY s.nama_siswa ASC
        ");
        $stmt->execute([$id_kelas, $id_mapel, $jenis, $tahun_ajaran, $semester_aktif, $kkm, $id_mapel, $jenis, $tahun_ajaran, $semester_aktif]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'students' => $students, 'kkm' => $kkm]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle AJAX: Save/Update Remedial
if (isset($_POST['action']) && ($_POST['action'] == 'save' || $_POST['action'] == 'update')) {
    header('Content-Type: application/json');
    try {
        $id_siswa = $_POST['id_siswa'];
        $id_mapel = $_POST['id_mapel'];
        $id_kelas = $_POST['id_kelas'];
        $jenis = $_POST['jenis_ulangan'];
        $kkm = $_POST['kkm'];
        $nilai_ulangan = $_POST['nilai_ulangan'];
        $indikator = $_POST['indikator'];
        $bentuk = $_POST['bentuk'];
        $nomor_soal = $_POST['nomor_soal'];
        $nilai_tes = $_POST['nilai_tes'];
        
        // Auto-determine status
        $keterangan = ($nilai_tes >= $kkm) ? 'Tuntas' : 'Tidak Tuntas';

        // START: Update to tb_nilai_semester
        // Logic from ajax_nilai_semester.php
        $temp_jadi = ($nilai_tes > $nilai_ulangan) ? $nilai_tes : $nilai_ulangan;
        $nilai_jadi = $temp_jadi;
        
        if ($kkm > 0 && $temp_jadi > 0) {
            if ($temp_jadi < $kkm) {
                $nilai_jadi = $kkm;
            } else {
                $maxVal = 99;
                $range = $maxVal - $kkm;
                $inputRange = 100 - $kkm;
                if ($range > 0) {
                    $ratio = ($temp_jadi - $kkm) / $inputRange;
                    $ratioBoosted = 1 - pow(1 - $ratio, 2);
                    $nilai_jadi = $kkm + ($range * $ratioBoosted);
                }
            }
            $nilai_jadi = round($nilai_jadi);
            if ($nilai_jadi > 99) $nilai_jadi = 99;
        }

        // Check if grade record exists
        $stmt_check = $pdo->prepare("
            SELECT id_nilai FROM tb_nilai_semester 
            WHERE id_siswa = ? AND id_mapel = ? AND jenis_semester = ? AND tahun_ajaran = ? AND semester = ?
        ");
        $stmt_check->execute([$id_siswa, $id_mapel, $jenis, $tahun_ajaran, $semester_aktif]);
        $existing_grade = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if ($existing_grade) {
            $stmt_update_grade = $pdo->prepare("
                UPDATE tb_nilai_semester SET nilai_remidi = ?, nilai_jadi = ? WHERE id_nilai = ?
            ");
            $stmt_update_grade->execute([$nilai_tes, $nilai_jadi, $existing_grade['id_nilai']]);
        } else {
            $stmt_insert_grade = $pdo->prepare("
                INSERT INTO tb_nilai_semester (id_siswa, id_mapel, id_kelas, id_guru, jenis_semester, tahun_ajaran, semester, nilai_asli, nilai_remidi, nilai_jadi)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt_insert_grade->execute([$id_siswa, $id_mapel, $id_kelas, $id_guru, $jenis, $tahun_ajaran, $semester_aktif, $nilai_ulangan, $nilai_tes, $nilai_jadi]);
        }
        // END: Update to tb_nilai_semester

        if ($_POST['action'] == 'save') {
            $stmt = $pdo->prepare("
                INSERT INTO tb_program_remidial 
                (id_siswa, id_mapel, id_kelas, id_guru, jenis_ulangan, tahun_ajaran, semester, kkm, nilai_ulangan, indikator_tidak_dikuasai, bentuk_remidial, nomor_soal, nilai_tes_remidi, keterangan) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$id_siswa, $id_mapel, $id_kelas, $id_guru, $jenis, $tahun_ajaran, $semester_aktif, $kkm, $nilai_ulangan, $indikator, $bentuk, $nomor_soal, $nilai_tes, $keterangan]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE tb_program_remidial SET 
                kkm = ?, nilai_ulangan = ?, indikator_tidak_dikuasai = ?, bentuk_remidial = ?, nomor_soal = ?, nilai_tes_remidi = ?, keterangan = ?
                WHERE id_remidi = ?
            ");
            $stmt->execute([$kkm, $nilai_ulangan, $indikator, $bentuk, $nomor_soal, $nilai_tes, $keterangan, $_POST['id_remidi']]);
        }
        
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle AJAX: Delete
if (isset($_POST['action']) && $_POST['action'] == 'delete') {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("DELETE FROM tb_program_remidial WHERE id_remidi = ?");
        $stmt->execute([$_POST['id_remidi']]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Fetch existing remedial data for the table
$remedial_list = [];
if ($selected_class_id && $selected_mapel_id && $selected_exam_type) {
    $stmt = $pdo->prepare("
        SELECT r.*, s.nama_siswa 
        FROM tb_program_remidial r
        JOIN tb_siswa s ON r.id_siswa = s.id_siswa
        WHERE r.id_kelas = ? AND r.id_mapel = ? AND r.jenis_ulangan = ? 
        AND r.tahun_ajaran = ? AND r.semester = ?
        ORDER BY s.nama_siswa ASC
    ");
    $stmt->execute([$selected_class_id, $selected_mapel_id, $selected_exam_type, $tahun_ajaran, $semester_aktif]);
    $remedial_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$css_libs = ['https://cdnjs.cloudflare.com/ajax/libs/izitoast/1.4.0/css/iziToast.min.css'];
$js_libs = ['https://cdnjs.cloudflare.com/ajax/libs/izitoast/1.4.0/js/iziToast.min.js'];

// Put the page script into $js_page so it's loaded after jQuery in footer.php
ob_start();
?>
$(document).ready(function() {
    $('#btn-add-remedial').on('click', function() {
        $('#form-remedial')[0].reset();
        $('#form-action').val('save');
        $('#select-siswa').prop('disabled', false);
        
        // Fetch students who need remedial
        $.ajax({
            url: 'program_remidi.php',
            data: {
                action: 'get_remedial_students',
                id_kelas: '<?= $selected_class_id ?>',
                id_mapel: '<?= $selected_mapel_id ?>',
                jenis: '<?= $selected_exam_type ?>'
            },
            success: function(res) {
                if (res.status === 'success') {
                    let html = '<option value="">Pilih Siswa</option>';
                    res.students.forEach(s => {
                        html += `<option value="${s.id_siswa}" data-grade="${s.nilai_asli}">${s.nama_siswa} (Nilai: ${parseFloat(s.nilai_asli)})</option>`;
                    });
                    $('#select-siswa').html(html);
                    $('#input-kkm').val(res.kkm);
                    $('#modal-remedial').modal('show');
                }
            }
        });
    });

    $('#select-siswa').on('change', function() {
        const selected = $(this).find('option:selected');
        $('#input-nilai-asli').val(selected.data('grade') || 0);
    });

    $('.btn-edit').on('click', function() {
        const data = $(this).data('data');
        $('#form-remedial')[0].reset();
        $('#form-action').val('update');
        $('#form-id-remidi').val(data.id_remidi);
        
        // In edit mode, we just show the one student
        $('#select-siswa').html(`<option value="${data.id_siswa}" selected>${data.nama_siswa}</option>`).prop('disabled', true);
        $('#input-kkm').val(data.kkm);
        $('#input-nilai-asli').val(data.nilai_ulangan);
        $('[name="indikator"]').val(data.indikator_tidak_dikuasai);
        $('[name="bentuk"]').val(data.bentuk_remidial);
        $('[name="nomor_soal"]').val(data.nomor_soal);
        $('#input-nilai-remidi').val(data.nilai_tes_remidi);
        
        $('#modal-remedial').modal('show');
    });

    $('#form-remedial').on('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        if ($('#form-action').val() === 'update') {
            formData.append('id_siswa', $('#select-siswa').val()); // Append because disabled field isn't sent
        }

        $.ajax({
            url: 'program_remidi.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
                if (res.status === 'success') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil',
                        text: 'Data remedial berhasil disimpan dan nilai otomatis diperbarui',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    iziToast.error({ title: 'Gagal', message: res.message, position: 'topRight' });
                }
            }
        });
    });

    $('.btn-delete').on('click', function() {
        const id = $(this).data('id');
        Swal.fire({
            title: 'Hapus data remedial?',
            text: "Data yang dihapus tidak dapat dikembalikan!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'program_remidi.php',
                    method: 'POST',
                    data: { action: 'delete', id_remidi: id },
                    success: function(res) {
                        if (res.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Terhapus!',
                                text: 'Data remedial telah dihapus.',
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                location.reload();
                            });
                        }
                    }
                });
            }
        });
    });
});
<?php
$js_page = [ob_get_clean()];

require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><?= $page_title ?></h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Remidial</div>
                <div class="breadcrumb-item"><?= $page_title ?></div>
            </div>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="" class="mb-4">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Kelas</label>
                                    <?php if (count($classes) > 1): ?>
                                        <select name="kelas" class="form-control" onchange="this.form.submit()">
                                            <option value="">Pilih Kelas</option>
                                            <?php foreach ($classes as $cls): ?>
                                                <option value="<?= $cls['id_kelas'] ?>" <?= $selected_class_id == $cls['id_kelas'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($cls['nama_kelas']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($classes[0]['nama_kelas'] ?? '') ?>" readonly>
                                        <input type="hidden" name="kelas" value="<?= $classes[0]['id_kelas'] ?? '' ?>">
                                    <?php endif; ?>
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
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Jenis Ulangan</label>
                                    <select name="jenis" class="form-control" onchange="this.form.submit()">
                                        <option value="">Pilih Jenis Ulangan</option>
                                        <?php foreach ($exam_types as $type): ?>
                                            <option value="<?= $type ?>" <?= $selected_exam_type == $type ? 'selected' : '' ?>>
                                                <?= $type ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </form>

                    <?php if ($selected_class_id && $selected_mapel_id && $selected_exam_type): ?>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <button type="button" class="btn btn-primary" id="btn-add-remedial">
                                    <i class="fas fa-plus"></i> Tambah Data Remidi
                                </button>
                            </div>
                            <div class="col-md-6 text-right">
                                <div class="btn-group">
                                    <a href="export_program_remidi_excel.php?kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>&jenis=<?= urlencode($selected_exam_type) ?>" target="_blank" class="btn btn-success">
                                        <i class="fas fa-file-excel"></i> Export Excel
                                    </a>
                                    <a href="export_program_remidi_pdf.php?kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>&jenis=<?= urlencode($selected_exam_type) ?>" target="_blank" class="btn btn-danger">
                                        <i class="fas fa-file-pdf"></i> Export PDF
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($selected_class_id && $selected_mapel_id && $selected_exam_type): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th class="text-center" width="5%">No</th>
                                        <th>Nama Siswa</th>
                                        <th class="text-center">KKM</th>
                                        <th class="text-center">Nilai Asli</th>
                                        <th>Indikator</th>
                                        <th>Bentuk Remidial</th>
                                        <th class="text-center">No. Soal</th>
                                        <th class="text-center">Nilai Remidi</th>
                                        <th class="text-center">Keterangan</th>
                                        <th class="text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($remedial_list)): ?>
                                        <tr><td colspan="10" class="text-center">Belum ada data remedial.</td></tr>
                                    <?php else: $no = 1; foreach ($remedial_list as $r): ?>
                                        <tr>
                                            <td class="text-center"><?= $no++ ?></td>
                                            <td><?= htmlspecialchars($r['nama_siswa']) ?></td>
                                            <td class="text-center"><?= (float)$r['kkm'] ?></td>
                                            <td class="text-center"><?= (float)$r['nilai_ulangan'] ?></td>
                                            <td><?= htmlspecialchars($r['indikator_tidak_dikuasai']) ?></td>
                                            <td><?= htmlspecialchars($r['bentuk_remidial']) ?></td>
                                            <td class="text-center"><?= htmlspecialchars($r['nomor_soal']) ?></td>
                                            <td class="text-center"><?= (float)$r['nilai_tes_remidi'] ?></td>
                                            <td class="text-center">
                                                <span class="badge badge-<?= $r['keterangan'] == 'Tuntas' ? 'success' : 'danger' ?>">
                                                    <?= $r['keterangan'] ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <button class="btn btn-warning btn-sm btn-edit" data-data='<?= json_encode($r) ?>'>
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-danger btn-sm btn-delete" data-id="<?= $r['id_remidi'] ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">Pilih filter di atas untuk melihat data remedial.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Modal Form -->
<div class="modal fade" tabindex="-1" role="dialog" id="modal-remedial">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Form Data Remedial</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="form-remedial">
                <input type="hidden" name="action" id="form-action" value="save">
                <input type="hidden" name="id_remidi" id="form-id-remidi">
                <input type="hidden" name="id_kelas" value="<?= $selected_class_id ?>">
                <input type="hidden" name="id_mapel" value="<?= $selected_mapel_id ?>">
                <input type="hidden" name="jenis_ulangan" value="<?= $selected_exam_type ?>">
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Pilih Siswa (Hanya yang di bawah KKM)</label>
                                <select name="id_siswa" id="select-siswa" class="form-control" required>
                                    <option value="">Pilih Siswa</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>KKM/KKTP</label>
                                <input type="number" name="kkm" id="input-kkm" class="form-control" readonly>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Nilai Asli</label>
                                <input type="number" name="nilai_ulangan" id="input-nilai-asli" class="form-control" readonly>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Indikator yang tidak dikuasai</label>
                                <input type="text" name="indikator" class="form-control" placeholder="Contoh: No. Indikator 1.2" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Bentuk Remidial</label>
                                <select name="bentuk" class="form-control" required>
                                    <option value="">Pilih Bentuk Remidial</option>
                                    <option value="Tes Ulang">Tes Ulang</option>
                                    <option value="Penugasan">Penugasan</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Nomor Soal yang dikerjakan</label>
                                <input type="text" name="nomor_soal" class="form-control" placeholder="Contoh: 1, 3, 5" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Nilai Tes Remidi</label>
                                <input type="number" step="0.01" name="nilai_tes" id="input-nilai-remidi" class="form-control" required>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-whitesmoke br">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Data</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../templates/footer.php'; ?>
