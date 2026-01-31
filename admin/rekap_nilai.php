<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha'])) {
    redirect('../login.php');
}

$page_title = 'Rekap Nilai Siswa';

// Fetch classes (Admin sees all)
$stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Parameters
$selected_class_id = isset($_GET['kelas']) ? $_GET['kelas'] : null;
$selected_jenis = isset($_GET['jenis']) ? $_GET['jenis'] : null;
$selected_tipe = isset($_GET['tipe']) ? $_GET['tipe'] : 'nilai_jadi'; // nilai_asli or nilai_jadi

// Validate selected class
$selected_class = null;
if ($selected_class_id) {
    foreach ($classes as $cls) {
        if ($cls['id_kelas'] == $selected_class_id) {
            $selected_class = $cls;
            break;
        }
    }
}

// Get All Subjects (Mapel) - Filtered
$subjects = [];
$stmt = $pdo->query("SELECT * FROM tb_mata_pelajaran 
    WHERE nama_mapel NOT LIKE '%Asmaul Husna%'
    AND nama_mapel NOT LIKE '%Upacara%'
    AND nama_mapel NOT LIKE '%Istirahat%'
    AND nama_mapel NOT LIKE '%Kepramukaan%'
    AND nama_mapel NOT LIKE '%Ekstrakurikuler%'
    ORDER BY nama_mapel ASC");
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Active Semester
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'];
$semester_aktif = $school_profile['semester'];

// Data Fetching
$students = [];
$rekap_data = [];

if ($selected_class && $selected_jenis) {
    // Get Students
    $stmt = $pdo->prepare("SELECT * FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
    $stmt->execute([$selected_class_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Grades
    foreach ($students as $student) {
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
                $stmt->execute([$selected_class_id, $mapel['id_mapel'], $tahun_ajaran, $semester_aktif, $student['id_siswa']]);
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
                // Logic for Semester (UTS, UAS, PAT, etc)
                $stmt = $pdo->prepare("
                    SELECT * FROM tb_nilai_semester 
                    WHERE id_kelas = ? AND id_mapel = ? 
                    AND jenis_semester = ? AND tahun_ajaran = ? AND semester = ?
                    AND id_siswa = ?
                ");
                $stmt->execute([$selected_class_id, $mapel['id_mapel'], $selected_jenis, $tahun_ajaran, $semester_aktif, $student['id_siswa']]);
                $grade = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($grade) {
                    $val = ($selected_tipe == 'nilai_asli') ? $grade['nilai_asli'] : $grade['nilai_jadi'];
                    $nilai = $val > 0 ? (float)$val : 0;
                }
            }

            $rekap_data[$student['id_siswa']][$mapel['id_mapel']] = $nilai;
            
            if ($nilai > 0) {
                $total_nilai += $nilai;
                $count_mapel++;
            }
        }
        
        $rekap_data[$student['id_siswa']]['total'] = $total_nilai;
        $rekap_data[$student['id_siswa']]['rerata'] = $count_mapel > 0 ? round($total_nilai / $count_mapel, 1) : 0;
    }
    
    // Calculate Ranking
    $averages = [];
    foreach ($students as $student) {
        $averages[$student['id_siswa']] = $rekap_data[$student['id_siswa']]['rerata'];
    }
    arsort($averages);
    
    $rank = 1;
    $prev_avg = -1;
    $real_rank = 1;
    
    foreach ($averages as $id_siswa => $avg) {
        if ($avg != $prev_avg) {
            $rank = $real_rank;
        }
        $rekap_data[$id_siswa]['ranking'] = $rank;
        $prev_avg = $avg;
        $real_rank++;
    }
}

require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<style>
    /* Sticky Columns and Header for Rekap Table */
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
    
    /* Sticky Header + Sticky Column Intersection (Top Left Corners) */
    thead th.sticky-col {
        z-index: 25 !important; /* Highest priority */
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
                    <form method="GET" action="" class="mb-4">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Kelas</label>
                                    <select name="kelas" class="form-control select2" required onchange="this.form.submit()">
                                        <option value="">-- Pilih Kelas --</option>
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
                                    <label>Jenis Penilaian</label>
                                    <select name="jenis" class="form-control" required onchange="this.form.submit()">
                                        <option value="">-- Pilih Jenis --</option>
                                        <option value="Harian" <?= $selected_jenis == 'Harian' ? 'selected' : '' ?>>Nilai Harian (Rerata)</option>
                                        <option value="UTS" <?= $selected_jenis == 'UTS' ? 'selected' : '' ?>>Nilai Tengah Semester (UTS)</option>
                                        <option value="UAS" <?= $selected_jenis == 'UAS' ? 'selected' : '' ?>>Nilai Akhir Semester (UAS)</option>
                                        <option value="PAT" <?= $selected_jenis == 'PAT' ? 'selected' : '' ?>>Nilai Akhir Tahun (PAT)</option>
                                        <option value="Pra Ujian" <?= $selected_jenis == 'Pra Ujian' ? 'selected' : '' ?>>Nilai Pra Ujian</option>
                                        <option value="Ujian" <?= $selected_jenis == 'Ujian' ? 'selected' : '' ?>>Nilai Ujian</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Tipe Data</label>
                                    <select name="tipe" class="form-control" onchange="this.form.submit()">
                                        <option value="nilai_asli" <?= $selected_tipe == 'nilai_asli' ? 'selected' : '' ?>>Nilai Asli</option>
                                        <option value="nilai_jadi" <?= $selected_tipe == 'nilai_jadi' ? 'selected' : '' ?>>Nilai Jadi</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </form>

                    <?php if ($selected_class && $selected_jenis): ?>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="alert alert-light border mb-0">
                                    <strong>Info:</strong> Menampilkan 
                                    <span class="badge badge-primary"><?= $selected_jenis ?></span> 
                                    tipe 
                                    <span class="badge badge-info"><?= $selected_tipe == 'nilai_asli' ? 'Nilai Asli' : 'Nilai Jadi' ?></span>
                                </div>
                            </div>
                            <div class="col-md-6 text-right">
                                <div class="btn-group">
                                    <a href="../guru/export_rekap_nilai_excel.php?kelas=<?= $selected_class_id ?>&jenis=<?= urlencode($selected_jenis) ?>&tipe=<?= $selected_tipe ?>" target="_blank" class="btn btn-success">
                                        <i class="fas fa-file-excel"></i> Export Excel
                                    </a>
                                    <a href="../guru/export_rekap_nilai_pdf.php?kelas=<?= $selected_class_id ?>&jenis=<?= urlencode($selected_jenis) ?>&tipe=<?= $selected_tipe ?>" target="_blank" class="btn btn-danger">
                                        <i class="fas fa-file-pdf"></i> Export PDF
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-sm" id="rekapTable">
                                <thead>
                                    <tr>
                                        <th class="text-center align-middle sticky-col sticky-col-1" rowspan="2">No</th>
                                        <th class="align-middle sticky-col sticky-col-2" rowspan="2">Nama Siswa</th>
                                        <th class="text-center" colspan="<?= count($subjects) ?>">Mata Pelajaran</th>
                                        <th class="text-center align-middle" rowspan="2" width="7%">Jumlah</th>
                                        <th class="text-center align-middle" rowspan="2" width="7%">Rerata</th>
                                        <th class="text-center align-middle" rowspan="2" width="7%">Rank</th>
                                    </tr>
                                    <tr>
                                        <?php foreach ($subjects as $mapel): ?>
                                            <th class="text-center align-bottom" style="font-size: 11px; min-width: 80px; height: auto; white-space: normal;">
                                                <?= htmlspecialchars($mapel['nama_mapel']) ?>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $no = 1;
                                    foreach ($students as $student): 
                                        $data = $rekap_data[$student['id_siswa']] ?? [];
                                    ?>
                                        <tr>
                                            <td class="text-center sticky-col sticky-col-1"><?= $no++ ?></td>
                                            <td class="sticky-col sticky-col-2"><?= htmlspecialchars($student['nama_siswa']) ?></td>
                                            <?php foreach ($subjects as $mapel): ?>
                                                <td class="text-center">
                                                    <?php 
                                                    $val = $data[$mapel['id_mapel']] ?? 0;
                                                    echo $val > 0 ? $val : '-';
                                                    ?>
                                                </td>
                                            <?php endforeach; ?>
                                            <td class="text-center font-weight-bold"><?= $data['total'] ?? 0 ?></td>
                                            <td class="text-center font-weight-bold"><?= $data['rerata'] ?? 0 ?></td>
                                            <td class="text-center font-weight-bold badge-secondary"><?= $data['ranking'] ?? '-' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            Silakan pilih <strong>Kelas</strong> dan <strong>Jenis Penilaian</strong> untuk menampilkan rekap nilai.
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
    if ($.fn.DataTable) {
        $('#rekapTable').DataTable({
            "pageLength": 50,
            "scrollX": true,
            "fixedColumns": {
                "leftColumns": 2
            }
        });
    }
});
</script>
