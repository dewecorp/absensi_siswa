<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['siswa'])) {
    redirect('../login.php');
}

ensure_nilai_semester_enum_ujian_praktik($pdo);
$page_title = nilai_ujian_page_title();

// Get student data
$id_siswa = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT s.*, k.nama_kelas, k.id_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_siswa = ?");
$stmt->execute([$id_siswa]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    echo "Data siswa tidak ditemukan.";
    exit;
}

// Security Check for Grade 6
$nk = strtoupper($student['nama_kelas']);
if (strpos($nk, '6') === false && strpos($nk, 'VI') === false) {
    echo "<script>alert('Halaman ini hanya untuk siswa Kelas 6'); window.location='dashboard.php';</script>";
    exit;
}

$id_kelas = $student['id_kelas'];

// Get Active Semester
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'];
$semester_aktif = $school_profile['semester'];

// Jenis nilai ujian / ujian praktik (kolom jenis_semester)
$selected_jenis = nilai_ujian_jenis_semester();
$selected_tipe = isset($_GET['tipe']) ? $_GET['tipe'] : 'nilai_jadi';
if ($selected_tipe !== 'nilai_asli' && $selected_tipe !== 'nilai_jadi') {
    $selected_tipe = 'nilai_jadi';
}

// Get Subjects (Mapel)
$subjects = getFilteredSubjects($pdo);

// Fetch Grades
$rekap_data = [];
$total_nilai = 0;
$count_mapel = 0;

foreach ($subjects as $mapel) {
    $nilai = 0;
    
    $stmt = $pdo->prepare("
        SELECT * FROM tb_nilai_semester 
        WHERE id_kelas = ? AND id_mapel = ? 
        AND jenis_semester = ? AND tahun_ajaran = ? AND semester = ?
        AND id_siswa = ?
    ");
    $stmt->execute([$id_kelas, $mapel['id_mapel'], $selected_jenis, $tahun_ajaran, $semester_aktif, $id_siswa]);
    $grade = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($grade) {
        $val = ($selected_tipe == 'nilai_asli') ? $grade['nilai_asli'] : $grade['nilai_jadi'];
        $nilai = $val > 0 ? (float)$val : 0;
    }

    $rekap_data[$mapel['id_mapel']] = $nilai;
    
    if ($nilai > 0) {
        $total_nilai += $nilai;
        $count_mapel++;
    }
}

$rerata = $count_mapel > 0 ? round($total_nilai / $count_mapel, 1) : 0;

require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><?= $page_title ?></h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item active"><?= htmlspecialchars($page_title) ?></div>
            </div>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th width="150">Nama Siswa</th>
                                    <td>: <?= htmlspecialchars($student['nama_siswa']) ?></td>
                                </tr>
                                <tr>
                                    <th>Kelas</th>
                                    <td>: <?= htmlspecialchars($student['nama_kelas']) ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6 text-right">
                             <form method="GET" action="" class="d-inline-block text-right">
                                <?php if (nilai_ujian_is_praktik_mode()): ?>
                                <input type="hidden" name="nilai_mode" value="praktik">
                                <?php endif; ?>
                                <div class="form-group mb-0">
                                    <label for="tipe-nilai-ujian" class="d-block text-right mb-1">Tipe Nilai</label>
                                    <select id="tipe-nilai-ujian" name="tipe" class="form-control form-control-sm" onchange="this.form.submit()">
                                    <option value="nilai_jadi" <?= $selected_tipe == 'nilai_jadi' ? 'selected' : '' ?>>Nilai Jadi</option>
                                    <option value="nilai_asli" <?= $selected_tipe == 'nilai_asli' ? 'selected' : '' ?>>Nilai Asli</option>
                                </select>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th width="50" class="text-center">No</th>
                                    <th>Mata Pelajaran</th>
                                    <th width="150" class="text-center">Nilai</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $no = 1;
                                foreach ($subjects as $mapel): 
                                    $val = $rekap_data[$mapel['id_mapel']] ?? 0;
                                ?>
                                    <tr>
                                        <td class="text-center"><?= $no++ ?></td>
                                        <td><?= htmlspecialchars($mapel['nama_mapel']) ?></td>
                                        <td class="text-center font-weight-bold">
                                            <?= $val > 0 ? $val : '-' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="bg-light">
                                    <th colspan="2" class="text-right">Total Nilai</th>
                                    <th class="text-center"><?= $total_nilai ?></th>
                                </tr>
                                <tr class="bg-light">
                                    <th colspan="2" class="text-right">Rerata</th>
                                    <th class="text-center"><?= $rerata ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php require_once '../templates/footer.php'; ?>
