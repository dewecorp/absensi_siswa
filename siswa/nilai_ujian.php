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

// Jenis nilai ujian / ujian praktik — siswa: MAX(asli, remidi); ujian praktik: asli saja
$selected_jenis = nilai_ujian_jenis_semester();

if ($selected_jenis === 'Ujian') {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tb_data_nilai_ujian (
            id_nilai_ujian INT NOT NULL AUTO_INCREMENT,
            id_siswa INT NOT NULL,
            id_kelas INT DEFAULT NULL,
            tahun_ajaran VARCHAR(30) NOT NULL,
            nilai_ujian DECIMAL(5,2) NOT NULL DEFAULT 0,
            keterangan VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id_nilai_ujian),
            UNIQUE KEY uniq_data_nilai_ujian_siswa_ta (id_siswa, tahun_ajaran),
            KEY idx_data_nilai_ujian_ta (tahun_ajaran),
            KEY idx_data_nilai_ujian_siswa (id_siswa),
            KEY idx_data_nilai_ujian_kelas (id_kelas)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        error_log('tb_data_nilai_ujian siswa: ' . $e->getMessage());
    }

    $stmt = $pdo->prepare("SELECT nilai_ujian, keterangan, tahun_ajaran, updated_at
        FROM tb_data_nilai_ujian
        WHERE id_siswa = ? AND tahun_ajaran = ?
        LIMIT 1");
    $stmt->execute([$id_siswa, $tahun_ajaran]);
    $nilai_resmi = $stmt->fetch(PDO::FETCH_ASSOC);

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
                            <div class="col-md-12">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th width="150">Nama Siswa</th>
                                        <td>: <?= htmlspecialchars($student['nama_siswa']) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Kelas</th>
                                        <td>: <?= htmlspecialchars($student['nama_kelas']) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Tahun Ajaran</th>
                                        <td>: <?= htmlspecialchars($tahun_ajaran) ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <?php if ($nilai_resmi): ?>
                            <div class="text-center py-4">
                                <div class="text-muted mb-2">Nilai Ujian Resmi</div>
                                <div class="display-4 font-weight-bold text-primary"><?= number_format((float)$nilai_resmi['nilai_ujian'], 2) ?></div>
                                <?php if (!empty($nilai_resmi['keterangan'])): ?>
                                    <div class="mt-3"><?= htmlspecialchars($nilai_resmi['keterangan']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-light text-center mb-0">
                                Nilai ujian resmi tahun ajaran ini belum diinput oleh madrasah.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <?php require_once '../templates/footer.php'; ?>
    <?php
    exit;
}

// Get Subjects (Mapel)
$subjects = getFilteredSubjects($pdo);

// Filter subjects for exam types
if (in_array($selected_jenis, ['Pra Ujian', 'Ujian'], true)) {
    $subjects = array_values(array_filter($subjects, function ($m) {
        $nama = strtolower(trim((string)($m['nama_mapel'] ?? '')));
        $nama = preg_replace('/\s+/', ' ', $nama);
        return $nama !== 'tajwid' && $nama !== 'bta';
    }));
}

// Filter subjects for Ujian Praktik - only show subjects with grades for this student
if ($selected_jenis === 'Ujian Praktik') {
    $stmt = $pdo->prepare("
        SELECT id_mapel
        FROM tb_nilai_semester
        WHERE id_siswa = ?
          AND jenis_semester = ?
          AND tahun_ajaran = ?
          AND semester = ?
          AND (
            COALESCE(nilai_asli, 0) > 0
            OR COALESCE(nilai_remidi, 0) > 0
            OR COALESCE(nilai_jadi, 0) > 0
          )
    ");
    $stmt->execute([$id_siswa, $selected_jenis, $tahun_ajaran, $semester_aktif]);
    $filled_mapel_ids = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $subjects = array_values(array_filter($subjects, function ($m) use ($filled_mapel_ids) {
        return in_array((string)($m['id_mapel'] ?? ''), $filled_mapel_ids, true);
    }));
}

// Fetch Grades
$rekap_data = [];
$total_nilai = 0;
$count_mapel = 0;

foreach ($subjects as $mapel) {
    $nilai = 0;
    
    $stmt = $pdo->prepare("
        SELECT nilai_asli, nilai_remidi
        FROM tb_nilai_semester
        WHERE id_kelas = ? AND id_mapel = ?
          AND jenis_semester = ? AND tahun_ajaran = ? AND semester = ?
          AND id_siswa = ?
        LIMIT 1
    ");
    $stmt->execute([$id_kelas, $mapel['id_mapel'], $selected_jenis, $tahun_ajaran, $semester_aktif, $id_siswa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $nilai = nilai_tampilan_siswa_semester(
            $row['nilai_asli'] ?? null,
            $row['nilai_remidi'] ?? null,
            nilai_ujian_is_praktik_mode()
        );
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
                        <div class="col-md-12">
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
