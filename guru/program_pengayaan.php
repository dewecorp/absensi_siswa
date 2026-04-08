<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has guru or wali level
if (!isAuthorized(['guru', 'wali'])) {
    redirect('../login.php');
}

$page_title = 'Program Pengayaan';
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
    AND (mp.jenis_mapel IS NULL OR mp.jenis_mapel = 'Akademik')
    ORDER BY mp.nama_mapel ASC
");
$stmt->execute([$id_guru]);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($user_role === 'wali' && empty($subjects)) {
    $subjects = getFilteredSubjects($pdo);
}

// Selected filters - MUST BE BEFORE grade 6 check
$selected_class_id = isset($_GET['kelas']) ? $_GET['kelas'] : (count($classes) == 1 ? $classes[0]['id_kelas'] : null);
$selected_mapel_id = isset($_GET['mapel']) ? $_GET['mapel'] : null;
$selected_exam_type = isset($_GET['jenis']) ? $_GET['jenis'] : null;

// Check if selected class is grade 6 (kelas 6)
$is_grade_6 = false;
if ($selected_class_id) {
    $stmt = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas = ?");
    $stmt->execute([$selected_class_id]);
    $kelas_name = $stmt->fetchColumn();
    if ($kelas_name && (strpos(strtolower($kelas_name), '6') !== false || strpos(strtolower($kelas_name), 'vi') !== false)) {
        $is_grade_6 = true;
    }
}

// Filter exam types - Pra Ujian Madrasah and Ujian Madrasah only for grade 6
$exam_types = ['PTS', 'PAS', 'PAT'];
if ($is_grade_6) {
    $exam_types[] = 'Pra Ujian Madrasah';
    $exam_types[] = 'Ujian Madrasah';
}

// Get school profile
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'];
$semester_aktif = $school_profile['semester'];

// Handle AJAX: Get students eligible for enrichment (nilai >= KKTP)
if (isset($_GET['action']) && $_GET['action'] == 'get_enrichment_students') {
    header('Content-Type: application/json');
    try {
        $id_kelas = $_GET['id_kelas'];
        $id_mapel = $_GET['id_mapel'];
        $jenis = $_GET['jenis'];
        
        // Map new exam type names to database values
        $exam_type_map = [
            'PTS' => 'UTS',
            'PAS' => 'UAS',
            'PAT' => 'PAT',
            'Pra Ujian Madrasah' => 'Pra Ujian',
            'Ujian Madrasah' => 'Ujian'
        ];
        $db_jenis = $exam_type_map[$jenis] ?? $jenis;
        
        // Get KKTP for the subject
        $stmt = $pdo->prepare("SELECT kktp FROM tb_mata_pelajaran WHERE id_mapel = ?");
        $stmt->execute([$id_mapel]);
        $kktp = $stmt->fetchColumn() ?: 75;

        // Get students with grades >= KKTP
        $stmt = $pdo->prepare("
            SELECT s.id_siswa, s.nama_siswa, n.nilai_asli
            FROM tb_siswa s
            JOIN tb_nilai_semester n ON s.id_siswa = n.id_siswa
            WHERE s.id_kelas = ? 
            AND n.id_mapel = ? 
            AND n.jenis_semester = ? 
            AND n.tahun_ajaran = ? 
            AND n.semester = ?
            AND n.nilai_asli >= ?
            AND s.id_siswa NOT IN (
                SELECT id_siswa FROM tb_program_pengayaan 
                WHERE id_mapel = ? AND jenis_ulangan = ? AND tahun_ajaran = ? AND semester = ?
            )
            ORDER BY s.nama_siswa ASC
        ");
        // Use $jenis (display name) for tb_program_pengayaan, $db_jenis for tb_nilai_semester
        $stmt->execute([$id_kelas, $id_mapel, $db_jenis, $tahun_ajaran, $semester_aktif, $kktp, $id_mapel, $jenis, $tahun_ajaran, $semester_aktif]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'students' => $students, 'kktp' => $kktp]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle AJAX: Save/Update Enrichment
if (isset($_POST['action']) && ($_POST['action'] == 'save' || $_POST['action'] == 'update')) {
    header('Content-Type: application/json');
    try {
        $id_siswa = $_POST['id_siswa'];
        $id_mapel = $_POST['id_mapel'];
        $id_kelas = $_POST['id_kelas'];
        $jenis = $_POST['jenis_ulangan'];
        $bentuk = $_POST['bentuk'];
        
        if ($_POST['action'] == 'save') {
            $stmt = $pdo->prepare("
                INSERT INTO tb_program_pengayaan 
                (id_siswa, id_mapel, id_kelas, id_guru, jenis_ulangan, tahun_ajaran, semester, bentuk_pengayaan, tanggal) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$id_siswa, $id_mapel, $id_kelas, $id_guru, $jenis, $tahun_ajaran, $semester_aktif, $bentuk, date('Y-m-d')]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE tb_program_pengayaan SET bentuk_pengayaan = ?, tanggal = ? WHERE id_pengayaan = ?
            ");
            $stmt->execute([$bentuk, date('Y-m-d'), $_POST['id_pengayaan']]);
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
        $stmt = $pdo->prepare("DELETE FROM tb_program_pengayaan WHERE id_pengayaan = ?");
        $stmt->execute([$_POST['id_pengayaan']]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Fetch existing enrichment data for the table
$enrichment_list = [];
if ($selected_class_id && $selected_mapel_id && $selected_exam_type) {
    // Map display name to database value for JOIN
    $exam_type_map = [
        'PTS' => 'UTS',
        'PAS' => 'UAS',
        'PAT' => 'PAT',
        'Pra Ujian Madrasah' => 'Pra Ujian',
        'Ujian Madrasah' => 'Ujian'
    ];
    $db_exam_type = $exam_type_map[$selected_exam_type] ?? $selected_exam_type;
    
    $stmt = $pdo->prepare("
        SELECT p.*, s.nama_siswa, n.nilai_asli
        FROM tb_program_pengayaan p
        JOIN tb_siswa s ON p.id_siswa = s.id_siswa
        LEFT JOIN tb_nilai_semester n ON s.id_siswa = n.id_siswa 
            AND n.id_mapel = p.id_mapel 
            AND n.jenis_semester = ?
            AND n.tahun_ajaran = p.tahun_ajaran
            AND n.semester = p.semester
        WHERE p.id_kelas = ? AND p.id_mapel = ? AND p.jenis_ulangan = ? 
        AND p.tahun_ajaran = ? AND p.semester = ?
        ORDER BY s.nama_siswa ASC
    ");
    $stmt->execute([$db_exam_type, $selected_class_id, $selected_mapel_id, $selected_exam_type, $tahun_ajaran, $semester_aktif]);
    $enrichment_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$css_libs = ['https://cdnjs.cloudflare.com/ajax/libs/izitoast/1.4.0/css/iziToast.min.css'];
$js_libs = ['https://cdnjs.cloudflare.com/ajax/libs/izitoast/1.4.0/js/iziToast.min.js'];

// Put the page script into $js_page so it's loaded after jQuery in footer.php
ob_start();
?>
$(document).ready(function() {
    $('#btn-add-enrichment').on('click', function() {
        $('#form-enrichment')[0].reset();
        $('#form-action').val('save');
        $('#select-siswa').prop('disabled', false);
        
        // Fetch students who are eligible for enrichment
        $.ajax({
            url: 'program_pengayaan.php',
            data: {
                action: 'get_enrichment_students',
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
                    $('#modal-enrichment').modal('show');
                }
            }
        });
    });

    $('.btn-edit').on('click', function() {
        const data = $(this).data('data');
        $('#form-enrichment')[0].reset();
        $('#form-action').val('update');
        $('#form-id-pengayaan').val(data.id_pengayaan);
        
        // In edit mode, we just show the one student
        $('#select-siswa').html(`<option value="${data.id_siswa}" selected>${data.nama_siswa} (Nilai: ${parseFloat(data.nilai_asli)})</option>`).prop('disabled', true);
        $('[name="bentuk"]').val(data.bentuk_pengayaan);
        
        $('#modal-enrichment').modal('show');
    });

    $('#form-enrichment').on('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        if ($('#form-action').val() === 'update') {
            formData.append('id_siswa', $('#select-siswa').val());
        }

        $.ajax({
            url: 'program_pengayaan.php',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
                if (res.status === 'success') {
                    iziToast.success({ title: 'Berhasil', message: 'Data pengayaan berhasil disimpan', position: 'topRight' });
                    setTimeout(() => location.reload(), 1000);
                } else {
                    iziToast.error({ title: 'Gagal', message: res.message, position: 'topRight' });
                }
            }
        });
    });

    $('.btn-delete').on('click', function() {
        const id = $(this).data('id');
        Swal.fire({
            title: 'Hapus data pengayaan?',
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
                    url: 'program_pengayaan.php',
                    method: 'POST',
                    data: { action: 'delete', id_pengayaan: id },
                    success: function(res) {
                        if (res.status === 'success') {
                            iziToast.success({ title: 'Terhapus', message: 'Data pengayaan telah dihapus', position: 'topRight' });
                            setTimeout(() => location.reload(), 1000);
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
                <div class="breadcrumb-item">Pengayaan</div>
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
                                <button type="button" class="btn btn-primary" id="btn-add-enrichment">
                                    <i class="fas fa-plus"></i> Tambah Data Pengayaan
                                </button>
                            </div>
                            <div class="col-md-6 text-right">
                                <div class="btn-group">
                                    <a href="export_program_pengayaan_excel.php?kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>&jenis=<?= urlencode($selected_exam_type) ?>" target="_blank" class="btn btn-success">
                                        <i class="fas fa-file-excel"></i> Export Excel
                                    </a>
                                    <a href="export_program_pengayaan_pdf.php?kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>&jenis=<?= urlencode($selected_exam_type) ?>" target="_blank" class="btn btn-danger">
                                        <i class="fas fa-file-pdf"></i> Export PDF
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th class="text-center" width="4%">No</th>
                                        <th class="text-center" width="10%">Tanggal</th>
                                        <th>Nama Siswa</th>
                                        <th class="text-center" width="10%">Nilai Ulangan</th>
                                        <th>Bentuk Pengayaan</th>
                                        <th class="text-center" width="15%">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($enrichment_list)): ?>
                                        <tr><td colspan="6" class="text-center">Belum ada data pengayaan.</td></tr>
                                    <?php else: $no = 1; foreach ($enrichment_list as $p): ?>
                                        <tr>
                                            <td class="text-center"><?= $no++ ?></td>
                                            <td class="text-center"><?= date('d/m/Y', strtotime($p['tanggal'])) ?></td>
                                            <td><?= htmlspecialchars($p['nama_siswa']) ?></td>
                                            <td class="text-center"><?= (float)$p['nilai_asli'] ?></td>
                                            <td><?= htmlspecialchars($p['bentuk_pengayaan']) ?></td>
                                            <td class="text-center">
                                                <button class="btn btn-warning btn-sm btn-edit" data-data='<?= json_encode($p) ?>'>
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-danger btn-sm btn-delete" data-id="<?= $p['id_pengayaan'] ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">Pilih filter di atas untuk melihat data pengayaan.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Modal Form -->
<div class="modal fade" tabindex="-1" role="dialog" id="modal-enrichment">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Form Data Pengayaan</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="form-enrichment">
                <input type="hidden" name="action" id="form-action" value="save">
                <input type="hidden" name="id_pengayaan" id="form-id-pengayaan">
                <input type="hidden" name="id_kelas" value="<?= $selected_class_id ?>">
                <input type="hidden" name="id_mapel" value="<?= $selected_mapel_id ?>">
                <input type="hidden" name="jenis_ulangan" value="<?= $selected_exam_type ?>">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Pilih Siswa (Nilai >= KKTP)</label>
                        <select name="id_siswa" id="select-siswa" class="form-control" required>
                            <option value="">Pilih Siswa</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Bentuk Pengayaan</label>
                        <select name="bentuk" class="form-control" required>
                            <option value="">Pilih Bentuk Pengayaan</option>
                            <option value="Pendalaman Materi">Pendalaman Materi</option>
                            <option value="Pengerjaan Soal HOTS">Pengerjaan Soal HOTS</option>
                            <option value="Tugas Proyek">Tugas Proyek</option>
                            <option value="Mentoring Teman Sebaya">Mentoring Teman Sebaya</option>
                        </select>
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
