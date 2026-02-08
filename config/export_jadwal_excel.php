<?php
// Set session name based on request BEFORE including functions.php
if (isset($_REQUEST['session_type'])) {
    $type = $_REQUEST['session_type'];
    if ($type == 'admin') session_name('SIS_ADMIN');
    elseif ($type == 'guru') session_name('SIS_GURU');
    elseif ($type == 'siswa') session_name('SIS_SISWA');
    elseif ($type == 'wali') session_name('SIS_WALI');
    elseif ($type == 'tata_usaha') session_name('SIS_TU');
    elseif ($type == 'kepala_madrasah' || $type == 'kepala') session_name('SIS_KEPALA');
    
    session_start();
}

require_once 'database.php';
require_once 'functions.php';

// Check auth
if (!isAuthorized(['admin', 'tata_usaha', 'guru', 'kepala_madrasah', 'wali', 'siswa'])) {
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
// Sort by Kode Mapel naturally (1, 2, 10 instead of 1, 10, 2)
$stmt = $pdo->query("SELECT * FROM tb_mata_pelajaran ORDER BY LENGTH(kode_mapel), kode_mapel ASC");
$mapels = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filter Non-Academic Subjects
// Filter removed for Main Schedule content, but applied for Legend
// Removed 'Kepramukaan' and 'Ekstrakurikuler' from filter as requested (they have numeric codes)
$non_academic_keywords = ['Asmaul Husna', 'Upacara', 'Istirahat', 'Tadarrus'];

$mapel_map = [];
$mapel_legend = [];
$i = 1;
foreach ($mapels as $m) {
    // Use kode_mapel from DB if available, otherwise auto-increment
    $code = !empty($m['kode_mapel']) ? $m['kode_mapel'] : $i;
    $m['display_code'] = $code;
    $mapel_map[$m['id_mapel']] = $m;
    
    // Check filter for Legend ONLY
    $is_non_academic = false;
    foreach ($non_academic_keywords as $keyword) {
        if (stripos($m['nama_mapel'], $keyword) !== false) {
            $is_non_academic = true;
            break;
        }
    }
    
    if (!$is_non_academic) {
        $mapel_legend[] = ['code' => $code, 'name' => $m['nama_mapel']];
    }
    $i++;
}

// Get Guru Map
$stmt = $pdo->query("SELECT * FROM tb_guru ORDER BY kode_guru ASC, nama_guru ASC");
$gurus = $stmt->fetchAll(PDO::FETCH_ASSOC);
$guru_map = [];
$guru_legend = [];
$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
$i = 0;
foreach ($gurus as $g) {
    // Use kode_guru from DB if available, otherwise generate letter
    if (!empty($g['kode_guru'])) {
        $code = $g['kode_guru'];
    } else {
        if ($i < 26) {
            $code = $chars[$i];
        } else {
            $code = $chars[floor($i / 26) - 1] . $chars[$i % 26];
        }
    }
    $g['display_code'] = $code;
    $guru_map[$g['id_guru']] = $g;
    $guru_legend[] = ['code' => $code, 'name' => $g['nama_guru']];
    $i++;
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
    // Filter non-academic schedules
    if (!isset($mapel_map[$row['mapel_id']])) {
        continue;
    }

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
        <p>TAHUN PELAJARAN: <?= $school_profile['tahun_ajaran'] ?? '' ?> SEMESTER: <?= $school_profile['semester'] ?? '' ?></p>
        <br>
        <h3><?= $page_title ?></h3>
    </div>

    <table>
        <thead>
            <tr>
                <th rowspan="2" style="width: 50px;">JAM<br>KE</th>
                <th rowspan="2" style="width: 100px;">WAKTU</th>
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
                                        $content = $m['display_code'];
                                    }
                                }
                            }
                        ?>
                        <td><?= $kelas_id ? htmlspecialchars($content) : '<b>'.htmlspecialchars($content).'</b>' ?></td>
                    <?php endforeach; endif; ?>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <br><br>
    <?php 
    $tempat = !empty($school_profile['tempat_jadwal']) ? $school_profile['tempat_jadwal'] : 'Jakarta';
    $tanggal = !empty($school_profile['tanggal_jadwal']) 
        ? formatDateIndonesia($school_profile['tanggal_jadwal']) 
        : formatDateIndonesia(date('Y-m-d'));
    $date_str = $tempat . ', ' . $tanggal;
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
                <?= $date_str ?><br>
                Wali Kelas <?= $classes[0]['nama_kelas'] ?><br>
                <br><br><br><br>
                <b><u><?= htmlspecialchars($wali_kelas) ?></u></b>
            </td>
            <?php else: ?>
            <!-- Legend and Signature for Main Schedule -->
            <td style="border: none; vertical-align: top;" width="70%">
                <table style="border: none;">
                    <tr style="border: none;">
                        <td valign="top" style="border:none">
                            <table style="border: 1px solid black; border-collapse: collapse;">
                                <tr><th colspan="2" style="background-color: #f0f0f0; border: 1px solid black;">KODE MATA PELAJARAN</th></tr>
                                <?php foreach ($mapel_legend as $item): ?>
                                <tr>
                                    <td style="text-align: center; border: 1px solid black;"><?= $item['code'] ?></td>
                                    <td style="text-align: left; border: 1px solid black;"><?= htmlspecialchars($item['name']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </table>
                        </td>
                        <td style="border:none; width: 20px;"></td>
                        <td valign="top" style="border:none">
                            <table style="border: 1px solid black; border-collapse: collapse;">
                                <tr><th colspan="2" style="background-color: #f0f0f0; border: 1px solid black;">KODE GURU</th></tr>
                                <?php foreach ($guru_legend as $item): ?>
                                <tr>
                                    <td style="text-align: center; border: 1px solid black;"><?= $item['code'] ?></td>
                                    <td style="text-align: left; border: 1px solid black;"><?= htmlspecialchars($item['name']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
            <td style="border: none; text-align: center; vertical-align: top;" width="30%">
                <?= $date_str ?><br>
                Kepala Madrasah<br>
                <br><br><br><br>
                <b><u><?= htmlspecialchars($kepala_madrasah) ?></u></b>
            </td>
            <?php endif; ?>
        </tr>
    </table>
</body>
</html>
