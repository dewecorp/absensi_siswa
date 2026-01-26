<?php
require_once 'database.php';
require_once 'functions.php';

// Check auth
if (!isAuthorized(['admin', 'tata_usaha', 'guru', 'kepala_madrasah'])) {
    die("Unauthorized access");
}

$kelas_id = isset($_POST['kelas_id']) ? (int)$_POST['kelas_id'] : null;
$jenis = isset($_POST['jenis']) ? $_POST['jenis'] : 'Reguler'; // Reguler or Ramadhan
$title_jenis = strtoupper($jenis);

// Get School Profile
$school_profile = getSchoolProfile($pdo);
$kepala_madrasah = $school_profile['kepala_madrasah'] ?? '-';

// Prepare Data
$days = ['Sabtu', 'Ahad', 'Senin', 'Selasa', 'Rabu', 'Kamis'];

// Get Classes
if ($kelas_id) {
    $stmt = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas = ?");
    $stmt->execute([$kelas_id]);
    $kelas_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $classes = [$kelas_data];
    $page_title = "JADWAL PELAJARAN " . $title_jenis . " KELAS " . strtoupper($kelas_data['nama_kelas']);
    $wali_kelas = $kelas_data['wali_kelas'];
} else {
    $stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // For Main Schedule
    if ($jenis === 'Ramadhan') {
        $page_title = "JADWAL PELAJARAN RAMADHAN";
    } else {
        $page_title = "JADWAL PELAJARAN";
    }
}

// Get Mapel Map
$stmt = $pdo->query("SELECT * FROM tb_mata_pelajaran ORDER BY nama_mapel ASC");
$mapels = $stmt->fetchAll(PDO::FETCH_ASSOC);
$mapel_map = [];
foreach ($mapels as $m) {
    $mapel_map[$m['id_mapel']] = $m;
}

// Get Guru Map
$stmt = $pdo->query("SELECT * FROM tb_guru ORDER BY nama_guru ASC");
$gurus = $stmt->fetchAll(PDO::FETCH_ASSOC);
$guru_map = [];
foreach ($gurus as $g) {
    $guru_map[$g['id_guru']] = $g;
}

// Get Jam Mengajar (Ordered by Waktu Mulai)
$stmt = $pdo->prepare("SELECT * FROM tb_jam_mengajar WHERE jenis = ? ORDER BY waktu_mulai ASC");
$stmt->execute([$jenis]);
$jam_mengajar = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Schedule Data
$stmt = $pdo->prepare("
    SELECT * FROM tb_jadwal_pelajaran 
    WHERE jenis = ?
");
$stmt->execute([$jenis]);
$all_schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

$main_schedule = [];
$used_jam_ke = [];
foreach ($all_schedules as $row) {
    $main_schedule[$row['hari']][$row['jam_ke']][$row['kelas_id']] = $row;
    foreach ($classes as $c) {
        if ($c['id_kelas'] == $row['kelas_id']) {
            $used_jam_ke[$row['jam_ke']] = true;
        }
    }
}

// Filter Jam Mengajar
$jam_display = array_filter($jam_mengajar, function($jam) use ($used_jam_ke) {
    return isset($used_jam_ke[$jam['jam_ke']]);
});

// Set Headers for Excel Export
$filename = "Jadwal_" . $jenis . "_" . date('YmdHis') . ".xls";
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

?>
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid black; padding: 5px; text-align: center; vertical-align: middle; }
        .header { text-align: center; margin-bottom: 20px; font-weight: bold; }
        .special-slot { background-color: #f0f0f0; font-weight: bold; }
        .no-border { border: none !important; }
    </style>
</head>
<body>
    <div class="header">
        <h3><?= strtoupper($school_profile['nama_yayasan'] ?? 'YAYASAN PENDIDIKAN ISLAM') ?></h3>
        <h2><?= strtoupper($school_profile['nama_madrasah']) ?></h2>
        <p>Tahun Pelajaran: <?= $school_profile['tahun_ajaran'] ?? '' ?> Semester: <?= $school_profile['semester'] ?? '' ?></p>
        <br>
        <h3><?= $page_title ?></h3>
    </div>

    <table>
        <thead>
            <tr>
                <th rowspan="3" style="width: 50px;">JAM<br>KE</th>
                <th rowspan="3" style="width: 100px;">WAKTU</th>
                <?php foreach ($days as $day): ?>
                    <th colspan="<?= count($classes) ?>" style="background-color: #e0e0e0;"><?= strtoupper($day) ?></th>
                <?php endforeach; ?>
            </tr>
            <tr>
                <?php foreach ($days as $day): ?>
                    <?php foreach ($classes as $c): ?>
                        <th><?= $c['nama_kelas'] ?></th>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tr>
            <tr>
                <?php foreach ($days as $day): ?>
                    <?php foreach ($classes as $c): 
                        $code = '-';
                        if (isset($main_schedule[$day])) {
                            foreach ($main_schedule[$day] as $jam_data) {
                                if (isset($jam_data[$c['id_kelas']])) {
                                    $sched = $jam_data[$c['id_kelas']];
                                    if (!empty($sched['guru_id']) && isset($guru_map[$sched['guru_id']])) {
                                        $g = $guru_map[$sched['guru_id']];
                                        $code = $g['kode_guru'] ?? substr($g['nama_guru'], 0, 3);
                                        break; 
                                    }
                                }
                            }
                        }
                    ?>
                        <th><?= $code ?></th>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($jam_display as $jam): 
                $jam_label = $jam['jam_ke'];
                $is_special = in_array(strtoupper((string)$jam_label), ['A', 'B', 'C', 'D']);
                $waktu = date('H.i', strtotime($jam['waktu_mulai'])) . '-' . date('H.i', strtotime($jam['waktu_selesai']));
            ?>
            <tr>
                <td><?= $jam_label ?></td>
                <td><?= $waktu ?></td>
                
                <?php foreach ($days as $day): 
                    $special_text = '';
                    if ($is_special) {
                        if (isset($main_schedule[$day][$jam_label])) {
                            foreach ($main_schedule[$day][$jam_label] as $sched) {
                                if (isset($mapel_map[$sched['mapel_id']])) {
                                    $special_text = $mapel_map[$sched['mapel_id']]['nama_mapel'];
                                    break;
                                }
                            }
                        }
                    }
                    
                    if ($is_special): ?>
                        <td colspan="<?= count($classes) ?>" class="special-slot"><?= htmlspecialchars($special_text) ?></td>
                    <?php else: 
                        foreach ($classes as $c): 
                            $content = '';
                            if (isset($main_schedule[$day][$jam_label][$c['id_kelas']])) {
                                $sched = $main_schedule[$day][$jam_label][$c['id_kelas']];
                                if (isset($mapel_map[$sched['mapel_id']])) {
                                    $m = $mapel_map[$sched['mapel_id']];
                                    if ($kelas_id) {
                                        $content = $m['nama_mapel'];
                                    } else {
                                        $content = $m['kode_mapel'] ?: $m['nama_mapel'];
                                    }
                                }
                            }
                        ?>
                        <td><?= htmlspecialchars($content) ?></td>
                    <?php endforeach; endif; ?>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <br><br>
    <?php 
    $date_str = !empty($school_profile['tanggal_jadwal']) 
        ? formatDateIndonesia($school_profile['tanggal_jadwal']) 
        : formatDateIndonesia(date('Y-m-d'));
    ?>
    <table style="border: none;">
        <tr style="border: none;">
            <?php if ($kelas_id): ?>
            <td style="border: none; text-align: center;" width="50%">
                Mengetahui,<br>
                Kepala Madrasah<br>
                <br><br><br><br>
                <b><u><?= htmlspecialchars($kepala_madrasah) ?></u></b>
            </td>
            <td style="border: none; text-align: center;" width="50%">
                Jepara, <?= $date_str ?><br>
                Wali Kelas <?= $classes[0]['nama_kelas'] ?><br>
                <br><br><br><br>
                <b><u><?= htmlspecialchars($wali_kelas) ?></u></b>
            </td>
            <?php else: ?>
            <td style="border: none;" width="50%"></td>
            <td style="border: none; text-align: center;" width="50%">
                Jepara, <?= $date_str ?><br>
                Kepala Madrasah<br>
                <br><br><br><br>
                <b><u><?= htmlspecialchars($kepala_madrasah) ?></u></b>
            </td>
            <?php endif; ?>
        </tr>
    </table>
</body>
</html>
