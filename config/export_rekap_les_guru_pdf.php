<?php
require_once 'database.php';
require_once 'functions.php';

if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha', 'wali', 'guru'])) {
    die("Unauthorized access");
}

$school_profile = getSchoolProfile($pdo);

$semester_export = trim((string) ($school_profile['semester'] ?? '-'));
if ($semester_export !== '-' && !preg_match('/^semester\b/i', $semester_export)) {
    $semester_export = 'Semester ' . $semester_export;
}

// Get Grade 6 Class IDs for filtering
$stmt_grade6 = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas WHERE nama_kelas LIKE '%6%' OR nama_kelas LIKE '%VI%'");
$grade6_classes = $stmt_grade6->fetchAll(PDO::FETCH_ASSOC);
$grade6_ids = array_column($grade6_classes, 'id_kelas');
$grade6_names = array_column($grade6_classes, 'nama_kelas');

// Get filter parameters
$filter_type = $_GET['filter_type'] ?? 'all';
$selected_date = $_GET['date'] ?? date('Y-m-d');

// Get all scheduled dates based on filter
if ($filter_type == 'daily') {
    $scheduled_dates = [$selected_date];
    $page_title = "Rekap Absensi Les Guru Harian - " . formatDateIndonesia($selected_date);
    $page_size = "A4 portrait";
} else {
    $stmt_sched = $pdo->query("SELECT DISTINCT tanggal FROM tb_jadwal_les ORDER BY tanggal ASC");
    $scheduled_dates = $stmt_sched->fetchAll(PDO::FETCH_COLUMN);
    $page_title = "Rekap Absensi Les Guru";
    $page_size = "legal landscape";
}

// Get all teachers filtered by Grade 6
$stmt = $pdo->query("SELECT id_guru, nama_guru, nuptk, mengajar FROM tb_guru ORDER BY nama_guru ASC");
$teachers_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$teachers = [];
$grade6_teacher_ids = [];
foreach ($teachers_raw as $t) {
    $mengajar = json_decode($t['mengajar'], true) ?? [];
    $is_grade6 = false;
    foreach ($mengajar as $m) {
        if (in_array($m, $grade6_ids) || in_array($m, $grade6_names)) {
            $is_grade6 = true;
            break;
        }
    }
    if ($is_grade6) {
        $teachers[] = $t;
        $grade6_teacher_ids[] = $t['id_guru'];
    }
}

// Get attendance data for filtered teachers
$attendance = [];
if (!empty($grade6_teacher_ids)) {
    $placeholders = str_repeat('?,', count($grade6_teacher_ids) - 1) . '?';
    $stmt = $pdo->prepare("SELECT id_guru, status, tanggal FROM tb_absensi_les_guru WHERE id_guru IN ($placeholders)");
    $stmt->execute($grade6_teacher_ids);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($records as $r) {
        $attendance[$r['id_guru']][$r['tanggal']] = $r['status'];
    }
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
        
        .signature-wrapper {
            margin-top: 20px;
            width: 100%;
            page-break-inside: avoid;
            text-align: right;
        }
        .signature-block {
            display: inline-block;
            text-align: center;
            max-width: 260px;
            vertical-align: top;
        }
        .qr-code { width: 65px; height: 60px; margin: 2px auto; display: block; }
        
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
        <?php if (isset($school_profile['logo']) && $school_profile['logo']): ?>
            <img src="../assets/img/<?= $school_profile['logo'] ?>" alt="Logo">
        <?php endif; ?>
        <h3><?= strtoupper($school_profile['nama_yayasan'] ?? 'YAYASAN') ?></h3>
        <h2><?= strtoupper($school_profile['nama_madrasah'] ?? 'MADRASAH') ?></h2>
        <p><?= $school_profile['alamat'] ?? '' ?></p>
        <p>Tahun Ajaran: <?= htmlspecialchars($school_profile['tahun_ajaran'] ?? '-') ?> | <?= htmlspecialchars($semester_export) ?></p>
    </div>

    <div class="title">
        <h4>REKAP ABSENSI LES GURU</h4>
        <?php if ($filter_type == 'daily'): ?>
            <p style="margin-top: 5px;">Tanggal: <?= formatDateIndonesia($selected_date) ?></p>
        <?php endif; ?>
    </div>

    <table>
        <thead>
            <tr>
                <th rowspan="2" width="30">No</th>
                <th rowspan="2">Nama Guru</th>
                <?php if ($filter_type == 'daily'): ?>
                    <th rowspan="2">NUPTK</th>
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
            <?php foreach ($teachers as $i => $t): 
                $h=$s_count=$iz=$a=0;
            ?>
            <tr>
                <td><?= $i+1 ?></td>
                <td class="text-left" style="white-space: nowrap;"><?= htmlspecialchars($t['nama_guru']) ?></td>
                <?php if ($filter_type == 'daily'): 
                    $st = $attendance[$t['id_guru']][$selected_date] ?? 'Belum Absen';
                ?>
                    <td><?= htmlspecialchars($t['nuptk'] ?: '-') ?></td>
                    <td><strong><?= $st ?></strong></td>
                <?php else: ?>
                    <?php foreach($scheduled_dates as $d): 
                        $st = $attendance[$t['id_guru']][$d] ?? '';
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
        <div class="signature-block">
            <?= htmlspecialchars($school_profile['tempat_jadwal'] ?? 'Sukosono') ?>, <?= formatDateIndonesia(date('Y-m-d')) ?><br>
            Mengetahui,<br>
            Kepala Madrasah,<br>
            <div style="margin-top: 5px; margin-bottom: 5px;">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?= urlencode('Validasi Kepala Madrasah: ' . ($school_profile['kepala_madrasah'] ?? '-')) ?>" alt="" class="qr-code">
            </div>
            <strong><u><?= htmlspecialchars($school_profile['kepala_madrasah'] ?? '-') ?></u></strong><br>
            NIP. <?= htmlspecialchars((string) ($school_profile['nip_kepala'] ?? '-')) ?>
        </div>
    </div>
    <script>
        window.onload = function() {
            window.print();
        }
    </script>
</body>
</html>
