<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check auth
if (!isAuthorized(['guru', 'wali', 'kepala_madrasah', 'tata_usaha', 'admin'])) {
    die('Unauthorized');
}

// Get parameters
$selected_class_id = isset($_GET['kelas']) ? $_GET['kelas'] : null;
$selected_mapel_id = isset($_GET['mapel']) ? $_GET['mapel'] : null;
$jenis_semester = isset($_GET['jenis']) ? $_GET['jenis'] : null;

if (!$selected_class_id || !$selected_mapel_id || !$jenis_semester) {
    die('Parameter tidak lengkap');
}

// Get Active Semester
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'];
$semester_aktif = $school_profile['semester'];

// Get teacher data
// Logic: Try to find the actual teacher of the subject from Schedule -> Daily Grades -> Kokurikuler
// If all fail, and user is a guru, fallback to current user.

// 1. Try Jadwal Pelajaran (Most reliable)
$stmt = $pdo->prepare("SELECT DISTINCT guru_id FROM tb_jadwal_pelajaran WHERE kelas_id = ? AND mapel_id = ? LIMIT 1");
$stmt->execute([$selected_class_id, $selected_mapel_id]);
$id_guru = $stmt->fetchColumn();

// 2. If not found, try Daily Grades
if (!$id_guru) {
    $stmt = $pdo->prepare("SELECT DISTINCT id_guru FROM tb_nilai_harian_header WHERE id_kelas = ? AND id_mapel = ? AND tahun_ajaran = ? AND semester = ? LIMIT 1");
    $stmt->execute([$selected_class_id, $selected_mapel_id, $tahun_ajaran, $semester_aktif]);
    $id_guru = $stmt->fetchColumn();
}

// 3. If not found, try Kokurikuler
if (!$id_guru) {
    $stmt = $pdo->prepare("SELECT DISTINCT id_guru FROM tb_nilai_kokurikuler_header WHERE id_kelas = ? AND id_mapel = ? AND tahun_ajaran = ? AND semester = ? LIMIT 1");
    $stmt->execute([$selected_class_id, $selected_mapel_id, $tahun_ajaran, $semester_aktif]);
    $id_guru = $stmt->fetchColumn();
}

// 4. Fallback for Guru user if still not found
if (!$id_guru && isset($_SESSION['level']) && $_SESSION['level'] == 'guru') {
    $id_guru = $_SESSION['user_id'];
    if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
        $stmt = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $id_guru = $stmt->fetchColumn();
    }
}

$id_guru = $id_guru ? $id_guru : 0;

// Get Class Info
$stmt = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas = ?");
$stmt->execute([$selected_class_id]);
$class_info = $stmt->fetch(PDO::FETCH_ASSOC);

// Get Mapel Info
$stmt = $pdo->prepare("SELECT * FROM tb_mata_pelajaran WHERE id_mapel = ?");
$stmt->execute([$selected_mapel_id]);
$mapel_info = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if mapel is non-academic
if (strpos($mapel_info['nama_mapel'], 'Asmaul Husna') !== false || 
    strpos($mapel_info['nama_mapel'], 'Upacara') !== false ||
    strpos($mapel_info['nama_mapel'], 'Istirahat') !== false ||
    strpos($mapel_info['nama_mapel'], 'Kepramukaan') !== false ||
    strpos($mapel_info['nama_mapel'], 'Ekstrakurikuler') !== false) {
    die('Mata pelajaran Non-Akademik tidak dapat diekspor.');
}

// School profile already fetched above


// Get Students
$stmt = $pdo->prepare("SELECT * FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
$stmt->execute([$selected_class_id]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Grades
$grades_data = [];
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

// Map Jenis Semester to Display Title
$titles = [
    'UTS' => 'NILAI TENGAH SEMESTER',
    'UAS' => 'NILAI AKHIR SEMESTER',
    'PAT' => 'NILAI AKHIR TAHUN',
    'Ujian' => 'NILAI UJIAN',
    'Pra Ujian' => 'NILAI PRA UJIAN'
];
$title = isset($titles[$jenis_semester]) ? $titles[$jenis_semester] : 'NILAI SEMESTER (' . $jenis_semester . ')';

?>
<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($title) ?> - <?= htmlspecialchars($class_info['nama_kelas']) ?></title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; margin: 0; padding: 20px; }
        .header { text-align: center; margin-bottom: 20px; }
        .header h2, .header h3, .header p { margin: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #000; padding: 5px; text-align: center; font-size: 11px; }
        th { background-color: #f2f2f2; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .text-left { text-align: left; }
        
        .no-break {
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        @media print {
            @page { size: portrait; margin: 10mm; }
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none; }
            .no-break { page-break-inside: avoid; }
        }
        
        .print-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        .print-btn:hover { background: #0056b3; }
    </style>
</head>
<body>
    <button class="print-btn no-print" onclick="window.print()">
        Cetak / Simpan PDF
    </button>

    <div class="header">
        <h2><?= htmlspecialchars($title) ?></h2>
        <h3><?= htmlspecialchars($class_info['nama_kelas'] ?? '') ?> - <?= htmlspecialchars($mapel_info['nama_mapel'] ?? '') ?></h3>
        <p>Tahun Ajaran: <?= htmlspecialchars($tahun_ajaran) ?> Semester <?= htmlspecialchars($semester_aktif) ?></p>
        <p>Guru: <?= htmlspecialchars(getGuruName($pdo, $id_guru) ?: '.........................') ?></p>
        <p>Wali Kelas: <?= htmlspecialchars($class_info['wali_kelas'] ?? '.........................') ?></p>
    </div>

    <table>
        <thead>
            <tr>
                <th width="5%">NO</th>
                <th>NAMA SISWA</th>
                <th width="15%">NILAI ASLI</th>
                <th width="15%">REMIDI</th>
                <th width="15%">NILAI JADI</th>
                <th width="15%">RERATA</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            foreach ($students as $student): 
                $id_siswa = $student['id_siswa'];
                $grade = isset($grades_data[$id_siswa]) ? $grades_data[$id_siswa] : null;
                
                $nilai_asli = $grade ? $grade['nilai_asli'] : 0;
                $nilai_remidi = $grade ? $grade['nilai_remidi'] : 0;
                $nilai_jadi = $grade ? $grade['nilai_jadi'] : 0;
                
                // Calculate Rerata logic
                $rerata = ($nilai_remidi > 0) ? ($nilai_asli + $nilai_remidi) / 2 : $nilai_asli;
            ?>
            <tr>
                <td><?= $no++ ?></td>
                <td class="text-left"><?= htmlspecialchars($student['nama_siswa']) ?></td>
                <td><?= $nilai_asli > 0 ? $nilai_asli : '-' ?></td>
                <td><?= $nilai_remidi > 0 ? $nilai_remidi : '-' ?></td>
                <td><?= $nilai_jadi > 0 ? $nilai_jadi : '-' ?></td>
                <td><?= $rerata > 0 ? round($rerata, 1) : '-' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div class="no-break" style="margin-top: 30px;">
        <table style="width: 100%; border: none;">
            <tr style="border: none;">
                <td style="border: none; text-align: center; width: 50%; vertical-align: top;">
                    <!-- Left side empty -->
                </td>
                <td style="border: none; text-align: center; width: 50%; vertical-align: top;">
                    Jepara, <?= date('d F Y') ?><br>
                    Guru Mata Pelajaran
                    <br><br><br><br>
                    <b><?= htmlspecialchars(getGuruName($pdo, $id_guru) ?: '.........................') ?></b>
                </td>
            </tr>
        </table>
    </div>

    <script>
        window.onload = function() {
            window.print();
        }
    </script>
</body>
</html>
