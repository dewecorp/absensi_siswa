<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['siswa'])) {
    redirect('../login.php');
}

$page_title = 'Rekap Nilai Saya';

// Get student data
$id_siswa = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT s.*, k.nama_kelas, k.id_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_siswa = ?");
$stmt->execute([$id_siswa]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    echo "Data siswa tidak ditemukan.";
    exit;
}

$id_kelas = $student['id_kelas'];

// Get Active Semester
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'];
$semester_aktif = $school_profile['semester'];

// Parameters
$selected_jenis = isset($_GET['jenis']) ? $_GET['jenis'] : 'Harian';
$selected_tipe = isset($_GET['tipe']) ? $_GET['tipe'] : 'nilai_jadi'; // nilai_asli or nilai_jadi

// Get Subjects (Mapel) - Filtered (Non-Academic)
$stmt = $pdo->query("SELECT * FROM tb_mata_pelajaran 
    WHERE nama_mapel NOT LIKE '%Asmaul Husna%'
    AND nama_mapel NOT LIKE '%Upacara%'
    AND nama_mapel NOT LIKE '%Istirahat%'
    AND nama_mapel NOT LIKE '%Kepramukaan%'
    AND nama_mapel NOT LIKE '%Ekstrakurikuler%'
    ORDER BY nama_mapel ASC");
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Grades
$rekap_data = [];
$total_nilai = 0;
$count_mapel = 0;

foreach ($subjects as $mapel) {
    $nilai = 0;
    
    if ($selected_jenis == 'Harian') {
        // Logic for Nilai Harian (Average of all PH columns)
        $stmt = $pdo->prepare("
            SELECT d.* 
            FROM tb_nilai_harian_detail d
            JOIN tb_nilai_harian_header h ON d.id_header = h.id_header
            WHERE h.id_kelas = ? AND h.id_mapel = ?
            AND h.tahun_ajaran = ? AND h.semester = ?
            AND d.id_siswa = ?
        ");
        $stmt->execute([$id_kelas, $mapel['id_mapel'], $tahun_ajaran, $semester_aktif, $id_siswa]);
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($details)) {
            $sum = 0;
            $count = 0;
            foreach ($details as $d) {
                $val = ($selected_tipe == 'nilai_asli') ? $d['nilai'] : $d['nilai_jadi'];
                if ($val > 0) {
                    $sum += $val;
                    $count++;
                }
            }
            if ($count > 0) {
                $nilai = round($sum / $count);
            }
        }
    } else {
        // Logic for Semester (UTS, UAS, PAT, Pra Ujian, Ujian, Kokurikuler)
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
                                <tr>
                                    <th>Tahun Ajaran</th>
                                    <td>: <?= htmlspecialchars($tahun_ajaran) ?> (Semester <?= htmlspecialchars($semester_aktif) ?>)</td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <form method="GET" action="" class="mb-4">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Jenis Penilaian</label>
                                    <select name="jenis" class="form-control" onchange="this.form.submit()">
                                        <option value="Harian" <?= $selected_jenis == 'Harian' ? 'selected' : '' ?>>Nilai Harian (Rerata)</option>
                                        <option value="UTS" <?= $selected_jenis == 'UTS' ? 'selected' : '' ?>>Nilai Tengah Semester (UTS)</option>
                                        <option value="UAS" <?= $selected_jenis == 'UAS' ? 'selected' : '' ?>>Nilai Akhir Semester (UAS)</option>
                                        <option value="PAT" <?= $selected_jenis == 'PAT' ? 'selected' : '' ?>>Nilai Akhir Tahun (PAT)</option>
                                        <option value="Kokurikuler" <?= $selected_jenis == 'Kokurikuler' ? 'selected' : '' ?>>Nilai Kokurikuler</option>
                                        
                                        <?php
                                        // Check Grade 6 for additional options
                                        $nk = strtoupper($student['nama_kelas']);
                                        if (strpos($nk, '6') !== false || strpos($nk, 'VI') !== false):
                                        ?>
                                            <option value="Pra Ujian" <?= $selected_jenis == 'Pra Ujian' ? 'selected' : '' ?>>Nilai Pra Ujian</option>
                                            <option value="Ujian" <?= $selected_jenis == 'Ujian' ? 'selected' : '' ?>>Nilai Ujian</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Tipe Nilai</label>
                                    <select name="tipe" class="form-control" onchange="this.form.submit()">
                                        <option value="nilai_jadi" <?= $selected_tipe == 'nilai_jadi' ? 'selected' : '' ?>>Nilai Jadi (Raport)</option>
                                        <option value="nilai_asli" <?= $selected_tipe == 'nilai_asli' ? 'selected' : '' ?>>Nilai Asli</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </form>

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
