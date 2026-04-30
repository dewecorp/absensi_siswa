<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has authorized level
if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha'])) {
    redirect('../login.php');
}

$type = $_GET['type'] ?? 'berjamaah'; // berjamaah or dhuha
$filter_type = $_GET['filter'] ?? 'monthly';
$class_id = (int)($_GET['class_id'] ?? 0);
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_month = $_GET['month'] ?? date('Y-m');
$selected_student = (int)($_GET['student_id'] ?? 0);

if ($class_id <= 0) {
    echo "Pilih kelas terlebih dahulu.";
    exit;
}

$school_profile = getSchoolProfile($pdo);
$active_semester = $school_profile['semester'] ?? 'Semester 1';
$academic_year = $school_profile['tahun_ajaran'] ?? '-';
$school_city = $school_profile['tempat_jadwal'] ?? 'Sukosono';
$report_date = formatDateIndonesia(date('Y-m-d'));

// Get class info
$stmt = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas = ?");
$stmt->execute([$class_id]);
$class_info = $stmt->fetch(PDO::FETCH_ASSOC);

$title = ($type == 'dhuha') ? "Rekap Sholat Dhuha" : "Rekap Sholat Berjamaah";
$table_name = ($type == 'dhuha') ? "tb_sholat_dhuha" : "tb_sholat";

$results = [];
$month_names = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

// --- DATA FETCHING ---
if ($filter_type == 'daily') {
    $stmt = $pdo->prepare("SELECT s.id_siswa, s.nama_siswa, s.nisn FROM tb_siswa s WHERE s.id_kelas = ? ORDER BY s.nama_siswa ASC");
    $stmt->execute([$class_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("SELECT id_siswa, status FROM $table_name WHERE tanggal = ?");
    $stmt->execute([$selected_date]);
    $sholat_map = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $stmt = $pdo->prepare("SELECT id_siswa, keterangan FROM tb_absensi WHERE tanggal = ?");
    $stmt->execute([$selected_date]);
    $abs_map = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    foreach ($students as $student) {
        $sid = $student['id_siswa'];
        $abs = $abs_map[$sid] ?? null;
        $sho = $sholat_map[$sid] ?? null;
        if (in_array($abs, ['Sakit', 'Izin', 'Alpa'])) $status = 'Tidak Hadir';
        elseif ($sho) $status = $sho;
        elseif ($abs == 'Hadir') $status = 'Hadir';
        else $status = 'Belum Absen';
        
        $results[] = ['nama' => $student['nama_siswa'], 'nisn' => $student['nisn'], 'status' => $status];
    }
} elseif ($filter_type == 'monthly') {
    $year = substr($selected_month, 0, 4);
    $month = substr($selected_month, 5, 2);
    $month_name = $month_names[(int)$month] . " " . $year;
    
    $stmt = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
    $stmt->execute([$class_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("SELECT id_siswa, status, DAY(tanggal) as day FROM $table_name WHERE YEAR(tanggal) = ? AND MONTH(tanggal) = ?");
    $stmt->execute([$year, $month]);
    $sholat_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $sholat_map = [];
    foreach ($sholat_raw as $r) $sholat_map[$r['id_siswa']][$r['day']] = $r['status'];
    
    $stmt = $pdo->prepare("SELECT id_siswa, keterangan, DAY(tanggal) as day FROM tb_absensi WHERE YEAR(tanggal) = ? AND MONTH(tanggal) = ?");
    $stmt->execute([$year, $month]);
    $abs_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $abs_map = [];
    foreach ($abs_raw as $r) $abs_map[$r['id_siswa']][$r['day']] = $r['keterangan'];
    $holidays = getHolidays($pdo, $year, $month);

    foreach ($students as $student) {
        $sid = $student['id_siswa'];
        $days = array_fill(1, 31, '');
        $sum = ['H' => 0, 'TH' => 0, 'B' => 0];
        for ($d = 1; $d <= 31; $d++) {
            $current_date = $year . '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
            if (!empty($holidays[$current_date])) {
                $days[$d] = 'L';
                continue;
            }
            $abs = $abs_map[$sid][$d] ?? null;
            $sho = $sholat_map[$sid][$d] ?? null;
            if (in_array($abs, ['Sakit', 'Izin', 'Alpa'])) $status = 'x';
            elseif ($sho == 'Melaksanakan' || $sho == 'Hadir') $status = 'v';
            elseif ($sho == 'Tidak Melaksanakan' || $sho == 'Tidak Hadir') $status = 'x';
            elseif ($sho == 'Berhalangan') $status = 'b';
            elseif ($abs == 'Hadir') $status = 'v';
            else $status = '';
            
            $days[$d] = $status;
            if ($status == 'v') $sum['H']++;
            if ($status == 'x') $sum['TH']++;
            if ($status == 'b') $sum['B']++;
        }
        $results[] = ['nama' => $student['nama_siswa'], 'days' => $days, 'sum' => $sum];
    }
} elseif ($filter_type == 'semester') {
    $years = explode('/', $academic_year);
    $start_year = (int)$years[0];
    $end_year = (int)($years[1] ?? ($start_year + 1));
    $query_year = ($active_semester == 'Semester 1') ? $start_year : $end_year;
    $m_start = ($active_semester == 'Semester 1') ? 7 : 1;
    $m_end = ($active_semester == 'Semester 1') ? 12 : 6;

    $stmt = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
    $stmt->execute([$class_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT id_siswa, status, MONTH(tanggal) as month, DAY(tanggal) as day FROM $table_name WHERE YEAR(tanggal) = ? AND MONTH(tanggal) BETWEEN ? AND ?");
    $stmt->execute([$query_year, $m_start, $m_end]);
    $sho_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $sho_map = [];
    foreach ($sho_raw as $r) $sho_map[$r['id_siswa']][$r['month']][$r['day']] = $r['status'];

    $stmt = $pdo->prepare("SELECT id_siswa, keterangan, MONTH(tanggal) as month, DAY(tanggal) as day FROM tb_absensi WHERE YEAR(tanggal) = ? AND MONTH(tanggal) BETWEEN ? AND ?");
    $stmt->execute([$query_year, $m_start, $m_end]);
    $abs_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $abs_map = [];
    foreach ($abs_raw as $r) $abs_map[$r['id_siswa']][$r['month']][$r['day']] = $r['keterangan'];

    foreach ($students as $student) {
        $sid = $student['id_siswa'];
        $m_totals = [];
        $sem_sum = ['H' => 0, 'TH' => 0, 'B' => 0];
        for ($m = $m_start; $m <= $m_end; $m++) {
            $m_totals[$m] = ['H' => 0, 'TH' => 0, 'B' => 0];
            for ($d = 1; $d <= 31; $d++) {
                $abs = $abs_map[$sid][$m][$d] ?? null;
                $sho = $sho_map[$sid][$m][$d] ?? null;
                if (in_array($abs, ['Sakit', 'Izin', 'Alpa'])) $st = 'x';
                elseif ($sho == 'Melaksanakan' || $sho == 'Hadir') $st = 'v';
                elseif ($sho == 'Tidak Melaksanakan' || $sho == 'Tidak Hadir') $st = 'x';
                elseif ($sho == 'Berhalangan') $st = 'b';
                elseif ($abs == 'Hadir') $st = 'v';
                else $st = null;

                if ($st == 'v') { $m_totals[$m]['H']++; $sem_sum['H']++; }
                elseif ($st == 'x') { $m_totals[$m]['TH']++; $sem_sum['TH']++; }
                elseif ($st == 'b') { $m_totals[$m]['B']++; $sem_sum['B']++; }
            }
        }
        $results[] = ['nama' => $student['nama_siswa'], 'm_totals' => $m_totals, 'sem_sum' => $sem_sum];
    }
} elseif ($filter_type == 'student') {
    $stmt = $pdo->prepare("SELECT nama_siswa, nisn FROM tb_siswa WHERE id_siswa = ?");
    $stmt->execute([$selected_student]);
    $student_info = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT status, tanggal FROM $table_name WHERE id_siswa = ? ORDER BY tanggal DESC");
    $stmt->execute([$selected_student]);
    $sho_recs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT keterangan, tanggal FROM tb_absensi WHERE id_siswa = ? ORDER BY tanggal DESC");
    $stmt->execute([$selected_student]);
    $abs_recs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $dates = [];
    foreach ($sho_recs as $r) { $dates[$r['tanggal']]['sho'] = $r['status']; }
    foreach ($abs_recs as $r) { $dates[$r['tanggal']]['abs'] = $r['keterangan']; }
    krsort($dates);

    $sem_sum = ['H' => 0, 'TH' => 0, 'B' => 0];
    foreach ($dates as $date => $val) {
        $abs = $val['abs'] ?? null;
        $sho = $val['sho'] ?? null;
        if (in_array($abs, ['Sakit', 'Izin', 'Alpa'])) $st = 'Tidak Hadir';
        elseif ($sho) $st = $sho;
        elseif ($abs == 'Hadir') $st = 'Hadir';
        else continue;

        $results[] = ['tanggal' => $date, 'status' => $st];
        if ($st == 'Hadir' || $st == 'Melaksanakan') $sem_sum['H']++;
        elseif ($st == 'Tidak Hadir' || $st == 'Tidak Melaksanakan') $sem_sum['TH']++;
        elseif ($st == 'Berhalangan') $sem_sum['B']++;
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak <?= $title ?></title>
    <style>
        @page { size: <?= ($filter_type == 'student' || $filter_type == 'daily') ? 'legal portrait' : 'legal landscape' ?>; margin: 0.5cm; }
        @media print { 
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
        }
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; font-size: 10px; color: #000; }
        .header { text-align: center; margin-bottom: 10px; position: relative; padding-top: 0; }
        .header img { position: absolute; left: 50px; top: 0; width: 50px; height: auto; }
        .header h2 { margin: 0; font-size: 14px; text-transform: uppercase; }
        .header h3 { margin: 2px 0; font-size: 12px; }
        .header h4 { margin: 2px 0; font-size: 11px; text-decoration: underline; }
        .header p { margin: 1px 0; font-size: 9px; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 5px; table-layout: fixed; }
        th, td { border: 1px solid #000; padding: 2px; text-align: center; overflow: hidden; text-overflow: ellipsis; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .col-no { width: 25px; }
        .col-nama { width: 200px; text-align: left; }
        .col-tanggal { width: 22px; }
        .col-total { width: 25px; }
        .text-left { text-align: left; padding-left: 5px; }
        .font-bold { font-weight: bold; }
        
        .signature-wrapper { margin-top: 20px; display: flex; justify-content: space-between; page-break-inside: avoid; }
        .signature-box { text-align: center; width: 35%; page-break-inside: avoid; }
        .signature-space { height: 85px; margin: 5px 0; position: relative; }
        .signature-space img { width: 75px; height: 75px; position: absolute; left: 50%; transform: translateX(-50%); top: 0; }
        
        .btn-print { position: fixed; top: 20px; right: 20px; padding: 10px 20px; background: #4b49ac; color: #fff; border: none; border-radius: 5px; cursor: pointer; z-index: 1000; }
    </style>
</head>
<body>
    <button class="btn-print no-print" onclick="window.print()">Cetak / Simpan PDF</button>

    <div class="header">
        <?php if (!empty($school_profile['logo'])): ?>
            <img src="../assets/img/<?= $school_profile['logo'] ?>" alt="Logo">
        <?php endif; ?>
        <h2>Sistem Informasi Madrasah</h2>
        <h3><?= htmlspecialchars($school_profile['nama_madrasah'] ?? 'MADRASAH') ?></h3>
        <h4><?= strtoupper($title) ?> - 
            <?php 
                if ($filter_type == 'daily') echo date('d-m-Y', strtotime($selected_date));
                elseif ($filter_type == 'monthly') echo $month_name;
                elseif ($filter_type == 'semester') echo $active_semester;
                elseif ($filter_type == 'student') echo htmlspecialchars($student_info['nama_siswa']);
            ?>
        </h4>
        <p>Tahun Ajaran: <?= $academic_year ?> | Semester: <?= $active_semester ?></p>
        <?php if ($filter_type == 'student'): ?>
            <p>NISN: <?= htmlspecialchars($student_info['nisn']) ?> | Kelas: <?= htmlspecialchars($class_info['nama_kelas']) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($filter_type == 'daily'): ?>
        <table>
            <thead>
                <tr>
                    <th class="col-no">No</th>
                    <th class="col-nama">Nama Siswa</th>
                    <th width="100">NISN</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $no=1; foreach($results as $row): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td class="text-left"><?= htmlspecialchars($row['nama']) ?></td>
                    <td><?= htmlspecialchars($row['nisn']) ?></td>
                    <td><?= $row['status'] ?></td>
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
                    <th colspan="3">Total</th>
                </tr>
                <tr>
                    <?php for($i=1; $i<=31; $i++) echo "<th class='col-tanggal'>$i</th>"; ?>
                    <th class="col-total">H</th>
                    <th class="col-total">TH</th>
                    <th class="col-total">B</th>
                </tr>
            </thead>
            <tbody>
                <?php $no=1; foreach($results as $row): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td class="text-left"><?= htmlspecialchars($row['nama']) ?></td>
                    <?php for($i=1; $i<=31; $i++) echo "<td>{$row['days'][$i]}</td>"; ?>
                    <td><?= $row['sum']['H'] ?></td>
                    <td><?= $row['sum']['TH'] ?></td>
                    <td><?= $row['sum']['B'] ?></td>
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
                    <?php for($m=$m_start; $m<=$m_end; $m++) echo "<th colspan='2'>{$month_names[$m]}</th>"; ?>
                    <th colspan="3">Total</th>
                </tr>
                <tr>
                    <?php for($m=$m_start; $m<=$m_end; $m++) echo "<th class='col-total'>H</th><th class='col-total'>TH</th>"; ?>
                    <th class="col-total">H</th><th class="col-total">TH</th><th class="col-total">B</th>
                </tr>
            </thead>
            <tbody>
                <?php $no=1; foreach($results as $row): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td class="text-left"><?= htmlspecialchars($row['nama']) ?></td>
                    <?php for($m=$m_start; $m<=$m_end; $m++): ?>
                        <td><?= $row['m_totals'][$m]['H'] ?: '-' ?></td>
                        <td><?= $row['m_totals'][$m]['TH'] ?: '-' ?></td>
                    <?php endfor; ?>
                    <td class="font-bold"><?= $row['sem_sum']['H'] ?></td>
                    <td class="font-bold"><?= $row['sem_sum']['TH'] ?></td>
                    <td class="font-bold"><?= $row['sem_sum']['B'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($filter_type == 'student'): ?>
        <table style="width: 50%; margin: 5px 0; table-layout: auto;">
            <tr><th colspan="2">Ringkasan Kehadiran</th></tr>
            <tr><td class="text-left">Total Hadir</td><td class="font-bold"><?= $sem_sum['H'] ?></td></tr>
            <tr><td class="text-left">Total Tidak Hadir</td><td class="font-bold"><?= $sem_sum['TH'] ?></td></tr>
            <tr><td class="text-left">Berhalangan</td><td class="font-bold"><?= $sem_sum['B'] ?></td></tr>
        </table>
        <table>
            <thead>
                <tr>
                    <th class="col-no">No</th>
                    <th class="col-nama" style="width: 150px;">Tanggal</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $no=1; foreach($results as $row): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td style="text-align: left; padding-left: 10px;"><?= date('d-m-Y', strtotime($row['tanggal'])) ?></td>
                    <td><?= $row['status'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="signature-wrapper">
        <div class="signature-box">
            <p>Wali Kelas,</p>
            <div class="signature-space">
                <?php 
                $wali = $class_info['wali_kelas'] ?? '-';
                $qr_wali = 'Validasi Wali Kelas: ' . $wali . ' - ' . ($school_profile['nama_madrasah'] ?? 'Madrasah');
                $qr_url_wali = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qr_wali);
                ?>
                <img src="<?= $qr_url_wali ?>" alt="QR">
            </div>
            <p><strong><u><?= htmlspecialchars($wali) ?></u></strong></p>
        </div>

        <div class="signature-box">
            <p><?= $school_city ?>, <?= $report_date ?><br>Kepala Madrasah,</p>
            <div class="signature-space">
                <?php 
                $kepala = $school_profile['kepala_madrasah'] ?? '-';
                $qr_kepala = 'Validasi Kepala Madrasah: ' . $kepala . ' - ' . ($school_profile['nama_madrasah'] ?? 'Madrasah');
                $qr_url_kepala = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qr_kepala);
                ?>
                <img src="<?= $qr_url_kepala ?>" alt="QR">
            </div>
            <p><strong><u><?= htmlspecialchars($kepala) ?></u></strong><br>NIP. <?= htmlspecialchars($school_profile['nip_kepala'] ?? '-') ?></p>
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
