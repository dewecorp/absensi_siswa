<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check auth
if (!isAuthorized(['guru', 'wali', 'kepala_madrasah', 'tata_usaha', 'admin'])) {
    die('Unauthorized');
}

// Get parameters
$selected_class_id = isset($_GET['kelas']) ? $_GET['kelas'] : null;
$selected_jenis = isset($_GET['jenis']) ? $_GET['jenis'] : null;
$selected_tipe = isset($_GET['tipe']) ? $_GET['tipe'] : 'nilai_jadi';

if (!$selected_class_id || !$selected_jenis) {
    die('Parameter tidak lengkap');
}

// Get teacher data
$id_guru = $_SESSION['user_id'];
if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
    $stmt = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $id_guru = $stmt->fetchColumn();
}
// Get Guru Name
$stmt = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
$stmt->execute([$id_guru]);
$nama_guru = $stmt->fetchColumn();

// Get Class Info
$stmt = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas = ?");
$stmt->execute([$selected_class_id]);
$class_info = $stmt->fetch(PDO::FETCH_ASSOC);

// Get All Subjects
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

$title = "REKAP NILAI " . strtoupper($selected_jenis);
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($title) ?> - <?= htmlspecialchars($class_info['nama_kelas']) ?></title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 10px; margin: 0; padding: 10px; }
        .header { text-align: center; margin-bottom: 20px; }
        .header h2, .header h3, .header p { margin: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #000; padding: 4px; text-align: center; }
        th { background-color: #f2f2f2; }
        .text-left { text-align: left; }
        
        @page { size: landscape; margin: 10mm; }
        
        .no-break {
            page-break-inside: avoid;
            break-inside: avoid;
        }
    </style>
</head>
<body>
    <div class="header">
        <h3><?= $title ?></h3>
        <p>KELAS: <?= htmlspecialchars($class_info['nama_kelas']) ?></p>
        <p>TIPE: <?= $selected_tipe == 'nilai_asli' ? 'NILAI ASLI' : 'NILAI JADI' ?></p>
        <p>TAHUN AJARAN: <?= $tahun_ajaran ?> - Semester <?= $semester_aktif ?></p>
    </div>

    <table>
        <thead>
            <tr>
                <th width="3%">No</th>
                <th width="15%">Nama Siswa</th>
                <?php foreach ($subjects as $mapel): ?>
                    <th><?= htmlspecialchars($mapel['nama_mapel']) ?></th>
                <?php endforeach; ?>
                <th width="5%">Jml</th>
                <th width="5%">Rata</th>
                <th width="5%">Rank</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            foreach ($students as $student): 
                $data = $rekap_data[$student['id_siswa']] ?? [];
            ?>
            <tr>
                <td><?= $no++ ?></td>
                <td class="text-left"><?= htmlspecialchars($student['nama_siswa']) ?></td>
                <?php foreach ($subjects as $mapel): ?>
                    <td>
                        <?php 
                        $val = $data[$mapel['id_mapel']] ?? 0;
                        echo $val > 0 ? $val : '-';
                        ?>
                    </td>
                <?php endforeach; ?>
                <td><?= $data['total'] ?></td>
                <td><?= $data['rerata'] ?></td>
                <td><?= $data['ranking'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div class="no-break" style="margin-top: 30px; text-align: right; margin-right: 50px;">
        <p>Jepara, <?= date('d F Y') ?></p>
        <p>Wali Kelas / Guru</p>
        <br><br><br>
        <p><b><?= htmlspecialchars($nama_guru) ?></b></p>
    </div>

    <script>
        window.onload = function() {
            window.print();
        }
    </script>
</body>
</html>
