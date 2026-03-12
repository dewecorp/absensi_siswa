<?php
// Determine session name before including functions.php
if (isset($_GET['session_type'])) {
    $type = $_GET['session_type'];
    $session_name = 'SIS_LOGIN';
    if ($type == 'admin') $session_name = 'SIS_ADMIN';
    elseif ($type == 'guru') $session_name = 'SIS_GURU';
    elseif ($type == 'siswa') $session_name = 'SIS_SISWA';
    elseif ($type == 'wali') $session_name = 'SIS_WALI';
    elseif ($type == 'tata_usaha') $session_name = 'SIS_TU';
    elseif ($type == 'kepala_madrasah' || $type == 'kepala') $session_name = 'SIS_KEPALA';
    
    if (session_status() == PHP_SESSION_NONE) {
        $save_path = __DIR__ . '/../sessions';
        if (!file_exists($save_path)) mkdir($save_path, 0777, true);
        session_save_path($save_path);
        session_name($session_name);
        session_start();
    }
}

require_once 'database.php';
require_once 'functions.php';

if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha', 'wali', 'guru'])) {
    die("Unauthorized access");
}

// Get Grade 6 Class ID
$stmt_grade6 = $pdo->query("SELECT id_kelas, nama_kelas, wali_kelas FROM tb_kelas WHERE nama_kelas = 'VI' OR nama_kelas = '6' LIMIT 1");
$class_grade6 = $stmt_grade6->fetch(PDO::FETCH_ASSOC);
$id_kelas_fixed = $class_grade6 ? $class_grade6['id_kelas'] : 6;
$nama_kelas_fixed = $class_grade6 ? $class_grade6['nama_kelas'] : 'VI';
$wali_kelas_fixed = $class_grade6 ? $class_grade6['wali_kelas'] : '-';

$school_profile = getSchoolProfile($pdo);

// Get filter parameters
$filter_type = $_GET['filter_type'] ?? 'all';
$selected_date = $_GET['date'] ?? date('Y-m-d');

// Get all scheduled dates based on filter
if ($filter_type == 'daily') {
    $scheduled_dates = [$selected_date];
    $page_title = "Rekap Absensi Les Harian Kelas " . $nama_kelas_fixed . " - " . formatDateIndonesia($selected_date);
    $page_size = "A4 portrait";
} else {
    $stmt_sched = $pdo->query("SELECT DISTINCT tanggal FROM tb_jadwal_les ORDER BY tanggal ASC");
    $scheduled_dates = $stmt_sched->fetchAll(PDO::FETCH_COLUMN);
    $page_title = "Rekap Absensi Les Kelas " . $nama_kelas_fixed;
    $page_size = "legal landscape";
}

// Get all students
$stmt = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
$stmt->execute([$id_kelas_fixed]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get attendance data
$stmt = $pdo->prepare("SELECT id_siswa, status, tanggal FROM tb_absensi_les WHERE id_siswa IN (SELECT id_siswa FROM tb_siswa WHERE id_kelas = ?)");
$stmt->execute([$id_kelas_fixed]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$attendance = [];
foreach ($records as $r) {
    $attendance[$r['id_siswa']][$r['tanggal']] = $r['status'];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= $page_title ?></title>
    <style>
        @page { size: <?= $page_size ?>; margin: 5mm 10mm 10mm 10mm; }
        body { font-family: Arial, sans-serif; font-size: 10pt; color: black; line-height: 1.3; margin: 0; }
        .header { text-align: center; margin-bottom: 10px; border-bottom: 3px double black; padding-bottom: 5px; position: relative; }
        .header img { position: absolute; left: 0; top: 0; width: 70px; }
        .header h2, .header h3, .header p { margin: 1px 0; }
        .title { text-align: center; margin-bottom: 10px; }
        .title h4 { margin: 0; text-decoration: underline; text-transform: uppercase; }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid black; padding: 4px 2px; text-align: center; }
        th { background-color: #f0f0f0; font-weight: bold; }
        .text-left { text-align: left; padding-left: 5px; }
        
        .signature-wrapper { margin-top: 20px; width: 100%; page-break-inside: avoid; }
        .signature-table { width: 100%; border: none !important; }
        .signature-table td { border: none !important; width: 33%; vertical-align: top; text-align: center; padding: 0; }
        .qr-code { width: 65px; height: 60px; margin: 2px auto; }
        
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 20px;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #d9534f; color: white; border: none; border-radius: 4px; cursor: pointer;">Cetak PDF</button>
    </div>

    <div class="header">
        <?php if ($school_profile['logo']): ?>
            <img src="../assets/img/<?= $school_profile['logo'] ?>" alt="Logo">
        <?php endif; ?>
        <h3><?= strtoupper($school_profile['nama_yayasan'] ?? 'YAYASAN') ?></h3>
        <h2><?= strtoupper($school_profile['nama_madrasah'] ?? 'MADRASAH') ?></h2>
        <p><?= $school_profile['alamat'] ?? '' ?></p>
        <p>Tahun Ajaran: <?= $school_profile['tahun_ajaran'] ?? '-' ?> | Semester: <?= $school_profile['semester'] ?? '-' ?></p>
    </div>

    <div class="title">
        <h4>REKAP ABSENSI LES SISWA KELAS <?= $nama_kelas_fixed ?></h4>
        <?php if ($filter_type == 'daily'): ?>
            <p style="margin-top: 5px;">Tanggal: <?= formatDateIndonesia($selected_date) ?></p>
        <?php endif; ?>
    </div>

    <table>
        <thead>
            <tr>
                <th rowspan="2" width="30">No</th>
                <th rowspan="2">Nama Siswa</th>
                <?php if ($filter_type == 'daily'): ?>
                    <th rowspan="2">NISN</th>
                    <th rowspan="2">Status Kehadiran</th>
                <?php else: ?>
                    <th colspan="<?= count($scheduled_dates) ?: 1 ?>">Tanggal Pelaksanaan Les</th>
                    <th colspan="4">Total</th>
                <?php endif; ?>
            </tr>
            <?php if ($filter_type != 'daily'): ?>
            <tr>
                <?php foreach($scheduled_dates as $d): ?>
                    <th style="font-size: 7pt;"><?= date('d/m', strtotime($d)) ?></th>
                <?php endforeach; if(empty($scheduled_dates)) echo "<th>-</th>"; ?>
                <th width="20">H</th><th width="20">S</th><th width="20">I</th><th width="20">A</th>
            </tr>
            <?php endif; ?>
        </thead>
        <tbody>
            <?php foreach ($students as $i => $s): 
                $h=$s_count=$iz=$a=0;
            ?>
            <tr>
                <td><?= $i+1 ?></td>
                <td class="text-left" style="white-space: nowrap;"><?= htmlspecialchars($s['nama_siswa']) ?></td>
                <?php if ($filter_type == 'daily'): 
                    $st = $attendance[$s['id_siswa']][$selected_date] ?? 'Belum Absen';
                ?>
                    <td><?= htmlspecialchars($s['nisn']) ?></td>
                    <td><strong><?= $st ?></strong></td>
                <?php else: ?>
                    <?php foreach($scheduled_dates as $d): 
                        $st = $attendance[$s['id_siswa']][$d] ?? '';
                        $val = '-';
                        if($st == 'Hadir') { $val = 'H'; $h++; }
                        elseif($st == 'Sakit') { $val = 'S'; $s_count++; }
                        elseif($st == 'Izin') { $val = 'I'; $iz++; }
                        elseif($st == 'Alpa') { $val = 'A'; $a++; }
                    ?>
                        <td style="font-size: 7pt;"><?= $val ?></td>
                    <?php endforeach; if(empty($scheduled_dates)) echo "<td>-</td>"; ?>
                    <td><?= $h ?></td><td><?= $s_count ?></td><td><?= $iz ?></td><td><?= $a ?></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="signature-wrapper">
        <table class="signature-table" style="width: 100%; border: none;">
            <tr>
                <td style="width: 35%;"></td>
                <td style="width: 30%;"></td>
                <td style="width: 35%; text-align: center; vertical-align: top;">
                    <?= $school_profile['tempat_jadwal'] ?? 'Sukosono' ?>, <?= formatDateIndonesia(date('Y-m-d')) ?>
                </td>
            </tr>
            <tr>
                <td></td>
                <td></td>
                <td style="text-align: center; vertical-align: top;">Mengetahui,</td>
            </tr>
            <tr>
                <td style="text-align: center; vertical-align: top;">Wali Kelas <?= $nama_kelas_fixed ?>,</td>
                <td></td>
                <td style="text-align: center; vertical-align: top;">Kepala Madrasah,</td>
            </tr>
            <tr>
                <td style="text-align: center; vertical-align: top;">
                    <div style="margin-top: 5px; margin-bottom: 5px;">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?= urlencode('Validasi Wali Kelas: ' . $wali_kelas_fixed) ?>" class="qr-code">
                    </div>
                    <strong><u><?= $wali_kelas_fixed ?></u></strong>
                </td>
                <td></td>
                <td style="text-align: center; vertical-align: top;">
                    <div style="margin-top: 5px; margin-bottom: 5px;">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?= urlencode('Validasi Kepala Madrasah: ' . ($school_profile['kepala_madrasah'] ?? '-')) ?>" class="qr-code">
                    </div>
                    <strong><u><?= $school_profile['kepala_madrasah'] ?? '-' ?></u></strong><br>
                    NIP. <?= $school_profile['nip_kepala'] ?? '-' ?>
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
