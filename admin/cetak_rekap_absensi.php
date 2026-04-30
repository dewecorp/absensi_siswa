<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirect('../login.php');
}

// Get parameters
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$filter_type = $_GET['type'] ?? 'daily';
$attendance_date = $_GET['date'] ?? '';
$month_picker = $_GET['month'] ?? '';
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

if ($class_id <= 0) {
    die('Error: Class ID is required');
}

// Get school profile
$school_profile = getSchoolProfile($pdo);
$school_name = $school_profile['nama_madrasah'] ?? 'Madrasah Ibtidaiyah';
$school_city = $school_profile['tempat_jadwal'] ?? 'Sukosono';
$active_semester = $school_profile['semester'] ?? 'Semester 1';
$academic_year = $school_profile['tahun_ajaran'] ?? (date('Y') . '/' . (date('Y') + 1));
$madrasah_head_name = $school_profile['kepala_madrasah'] ?? '';
$madrasah_head_nip = $school_profile['nip_kepala'] ?? '-';
$report_date = formatDateIndonesia(date('Y-m-d'));

// Get class info and wali kelas
$stmt = $pdo->prepare("SELECT nama_kelas, wali_kelas FROM tb_kelas WHERE id_kelas = ?");
$stmt->execute([$class_id]);
$class_info = $stmt->fetch(PDO::FETCH_ASSOC);
$class_name = $class_info['nama_kelas'] ?? '';
$wali_kelas_name = $class_info['wali_kelas'] ?? '';

// Fetch data based on filter type
$results = [];
$title = '';

if ($filter_type == 'daily') {
    $title = 'Rekap Absensi Harian - Tanggal: ' . formatDateIndonesia($attendance_date);
    $stmt = $pdo->prepare("
        SELECT s.nama_siswa, s.nisn, a.keterangan, a.jam_masuk
        FROM tb_siswa s
        LEFT JOIN tb_absensi a ON s.id_siswa = a.id_siswa AND a.tanggal = ?
        WHERE s.id_kelas = ?
        ORDER BY s.nama_siswa ASC
    ");
    $stmt->execute([$attendance_date, $class_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($filter_type == 'monthly') {
    $year = substr($month_picker, 0, 4);
    $month = substr($month_picker, 5, 2);
    $month_names = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $title = 'Rekap Absensi Bulanan - ' . $month_names[(int)$month] . ' ' . $year;
    
    // Get all students
    $stmt = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
    $stmt->execute([$class_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get attendance data
    $stmt = $pdo->prepare("
        SELECT id_siswa, keterangan, DAY(tanggal) as day
        FROM tb_absensi
        WHERE YEAR(tanggal) = ? AND MONTH(tanggal) = ? AND id_siswa IN (SELECT id_siswa FROM tb_siswa WHERE id_kelas = ?)
    ");
    $stmt->execute([$year, $month, $class_id]);
    $attendance_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $attendance_map = [];
    foreach ($attendance_data as $row) {
        $attendance_map[$row['id_siswa']][$row['day']] = $row['keterangan'];
    }
    
    // Get holidays for the month
    $holidays = getHolidays($pdo, $year, $month);
    $num_days = cal_days_in_month(CAL_GREGORIAN, (int)$month, (int)$year);
    
    foreach ($students as $student) {
        $row = $student;
        $row['days'] = [];
        $summary = ['H' => 0, 'S' => 0, 'I' => 0, 'A' => 0];
        for ($i = 1; $i <= 31; $i++) {
            if ($i > $num_days) {
                $row['days'][$i] = '-'; // Outside month range
                continue;
            }
            
            $date_str = sprintf('%04d-%02d-%02d', $year, $month, $i);
            $status = $attendance_map[$student['id_siswa']][$i] ?? '';
            $display = '';
            
            if ($status == 'Hadir') { 
                $display = 'H'; 
                $summary['H']++; 
            } elseif ($status == 'Sakit') { 
                $display = 'S'; 
                $summary['S']++; 
            } elseif ($status == 'Izin') { 
                $display = 'I'; 
                $summary['I']++; 
            } elseif ($status == 'Alpa') { 
                $display = 'A'; 
                $summary['A']++; 
            } elseif (isset($holidays[$date_str])) {
                $display = 'L'; // Holiday
            }
            
            $row['days'][$i] = $display;
        }
        $row['summary'] = $summary;
        $results[] = $row;
    }
} elseif ($filter_type == 'semester') {
    $title = 'Rekap Absensi Semester - ' . $active_semester;
    
    // Get all students
    $stmt = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
    $stmt->execute([$class_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $years = explode('/', $academic_year);
    $start_year = (int)$years[0];
    $end_year = (int)$years[1];
    
    if ($active_semester == 'Semester 1') {
        $m_start = 7; $m_end = 12; $y_start = $start_year; $y_end = $start_year;
    } else {
        $m_start = 1; $m_end = 6; $y_start = $end_year; $y_end = $end_year;
    }
    
    foreach ($students as $student) {
        $row = $student;
        $row['monthly'] = [];
        $summary = ['H' => 0, 'S' => 0, 'I' => 0, 'A' => 0, 'B' => 0];
        
        for ($m = $m_start; $m <= $m_end; $m++) {
            $stmt = $pdo->prepare("
                SELECT keterangan, COUNT(*) as count 
                FROM tb_absensi 
                WHERE id_siswa = ? AND YEAR(tanggal) = ? AND MONTH(tanggal) = ? 
                GROUP BY keterangan
            ");
            $stmt->execute([$student['id_siswa'], ($m >= 7 ? $start_year : $end_year), $m]);
            $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $row['monthly'][$m] = [
                'H' => $counts['Hadir'] ?? 0,
                'S' => $counts['Sakit'] ?? 0,
                'I' => $counts['Izin'] ?? 0,
                'A' => $counts['Alpa'] ?? 0,
                'B' => $counts['Berhalangan'] ?? 0
            ];
            
            foreach ($row['monthly'][$m] as $k => $v) {
                $summary[$k] += $v;
            }
        }
        $row['summary'] = $summary;
        $results[] = $row;
    }
} elseif ($filter_type == 'student') {
    $stmt = $pdo->prepare("SELECT nama_siswa, nisn FROM tb_siswa WHERE id_siswa = ?");
    $stmt->execute([$student_id]);
    $student_info = $stmt->fetch(PDO::FETCH_ASSOC);
    $title = 'Rekap Absensi Siswa: ' . ($student_info['nama_siswa'] ?? '');
    
    $stmt = $pdo->prepare("
        SELECT tanggal, keterangan 
        FROM tb_absensi 
        WHERE id_siswa = ? 
        ORDER BY tanggal DESC
    ");
    $stmt->execute([$student_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Rekap Absensi</title>
    <style>
        @page { size: legal landscape; margin: 0cm 0.5cm 0.5cm 0.5cm; }
        body { font-family: Arial, sans-serif; font-size: 11px; margin: 0; padding: 10px; }
        .header { text-align: center; margin-bottom: 10px; position: relative; }
        .logo { position: absolute; left: 0; top: 0; max-width: 60px; }
        h2, h3, h4 { margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 5px; table-layout: fixed; }
        th, td { border: 1px solid #000; padding: 2px; text-align: center; overflow: hidden; text-overflow: ellipsis; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .text-left { text-align: left; padding-left: 5px; }
        .col-no { width: 25px; }
        .col-nama { width: 180px; }
        .col-tgl { width: 20px; }
        .col-stat { width: 22px; }
        .signature-wrapper { margin-top: 20px; display: flex; justify-content: space-between; }
        .signature-box { text-align: center; width: 40%; }
        .signature-space { height: 80px; margin: 5px 0; }
        .qr-code { width: 75px; height: 75px; margin: 0 auto; display: block; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="header">
        <img src="../assets/img/logo_1768301957.png" alt="Logo" class="logo">
        <h2>Sistem Informasi Madrasah</h2>
        <h3><?= htmlspecialchars($school_name) ?></h3>
        <h4><?= htmlspecialchars($title) ?></h4>
        <p>Kelas: <?= htmlspecialchars($class_name) ?> | Tahun Ajaran: <?= htmlspecialchars($academic_year) ?> | Semester: <?= htmlspecialchars($active_semester) ?></p>
    </div>

    <?php if ($filter_type == 'daily'): ?>
        <table>
            <thead>
                <tr>
                    <th class="col-no">No</th>
                    <th class="col-nama">Nama Siswa</th>
                    <th width="100">NISN</th>
                    <th width="80">Waktu</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $no=1; foreach($results as $row): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td class="text-left"><?= htmlspecialchars($row['nama_siswa']) ?></td>
                    <td><?= htmlspecialchars($row['nisn']) ?></td>
                    <td><?= $row['jam_masuk'] ? date('H:i', strtotime($row['jam_masuk'])) : '-' ?></td>
                    <td><?= htmlspecialchars($row['keterangan'] ?? 'Belum Absen') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($filter_type == 'monthly'): ?>
        <table>
            <thead>
                <tr>
                    <th rowspan="2" class="col-no">No</th>
                    <th rowspan="2" class="col-nama">Nama Siswa</th>
                    <th colspan="31">Tanggal</th>
                    <th colspan="4">Total</th>
                </tr>
                <tr>
                    <?php for($i=1; $i<=31; $i++) echo "<th class='col-tgl'>$i</th>"; ?>
                    <th class="col-stat">H</th><th class="col-stat">S</th><th class="col-stat">I</th><th class="col-stat">A</th>
                </tr>
            </thead>
            <tbody>
                <?php $no=1; foreach($results as $row): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td class="text-left" style="white-space: nowrap;"><?= htmlspecialchars($row['nama_siswa']) ?></td>
                    <?php for($i=1; $i<=31; $i++): 
                        $val = $row['days'][$i];
                        $style = ($val == 'L') ? 'color: red; font-weight: bold;' : '';
                    ?>
                        <td style="<?= $style ?>"><?= $val ?></td>
                    <?php endfor; ?>
                    <td><?= $row['summary']['H'] ?></td><td><?= $row['summary']['S'] ?></td><td><?= $row['summary']['I'] ?></td><td><?= $row['summary']['A'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($filter_type == 'semester'): ?>
        <table>
            <thead>
                <tr>
                    <th rowspan="2" class="col-no">No</th>
                    <th rowspan="2" class="col-nama">Nama Siswa</th>
                    <?php 
                    $month_names = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                    for($m=$m_start; $m<=$m_end; $m++) echo "<th colspan='4'>{$month_names[$m]}</th>"; 
                    ?>
                    <th colspan="4">Total</th>
                </tr>
                <tr>
                    <?php for($m=$m_start; $m<=$m_end; $m++) echo "<th>H</th><th>S</th><th>I</th><th>A</th>"; ?>
                    <th>H</th><th>S</th><th>I</th><th>A</th>
                </tr>
            </thead>
            <tbody>
                <?php $no=1; foreach($results as $row): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td class="text-left"><?= htmlspecialchars($row['nama_siswa']) ?></td>
                    <?php for($m=$m_start; $m<=$m_end; $m++): ?>
                        <td><?= $row['monthly'][$m]['H'] ?: '-' ?></td><td><?= $row['monthly'][$m]['S'] ?: '-' ?></td><td><?= $row['monthly'][$m]['I'] ?: '-' ?></td><td><?= $row['monthly'][$m]['A'] ?: '-' ?></td>
                    <?php endfor; ?>
                    <td><?= $row['summary']['H'] ?></td><td><?= $row['summary']['S'] ?></td><td><?= $row['summary']['I'] ?></td><td><?= $row['summary']['A'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($filter_type == 'student'): ?>
        <table style="width: 50%; margin: 0 auto;">
            <thead>
                <tr>
                    <th class="col-no">No</th>
                    <th width="150">Tanggal</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $no=1; foreach($results as $row): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td><?= date('d-m-Y', strtotime($row['tanggal'])) ?></td>
                    <td><?= htmlspecialchars($row['keterangan']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="signature-wrapper">
        <div class="signature-box">
            <p><br>Wali Kelas,</p>
            <div class="signature-space">
                <?php if($wali_kelas_name): ?>
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?= urlencode("Validasi Tanda Tangan: $wali_kelas_name") ?>" class="qr-code">
                <?php endif; ?>
            </div>
            <p><strong><?= htmlspecialchars($wali_kelas_name) ?></strong></p>
        </div>
        <div class="signature-box">
            <p><?= htmlspecialchars($school_city) ?>, <?= $report_date ?><br>Kepala Madrasah,</p>
            <div class="signature-space">
                <?php if($madrasah_head_name): ?>
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?= urlencode("Validasi Tanda Tangan: $madrasah_head_name") ?>" class="qr-code">
                <?php endif; ?>
            </div>
            <p><strong><?= htmlspecialchars($madrasah_head_name) ?></strong><br>NIP. <?= htmlspecialchars($madrasah_head_nip) ?></p>
        </div>
    </div>

    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        }
    </script>
</body>
</html>
