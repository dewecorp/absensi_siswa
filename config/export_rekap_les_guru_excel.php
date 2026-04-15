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
        $save_path = sys_get_temp_dir();
        if (is_string($save_path) && $save_path !== '') {
            session_save_path($save_path);
        }
        session_name($session_name);
        session_start();
    }
}

require_once 'database.php';
require_once 'functions.php';

if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha', 'wali', 'guru'])) {
    die("Unauthorized access");
}

$school_profile = getSchoolProfile($pdo);

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
    $report_title = "REKAP ABSENSI LES GURU HARIAN";
} else {
    $stmt_sched = $pdo->query("SELECT DISTINCT tanggal FROM tb_jadwal_les ORDER BY tanggal ASC");
    $scheduled_dates = $stmt_sched->fetchAll(PDO::FETCH_COLUMN);
    $report_title = "REKAP ABSENSI LES GURU";
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

$filename = "Rekap_Absensi_Les_Guru_" . ($filter_type == 'daily' ? $selected_date : 'All') . ".xls";
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");
?>
<style>
    .text-center { text-align: center; }
    .text-left { text-align: left; }
    .font-bold { font-weight: bold; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid black; padding: 5px; color: black; }
</style>

<table>
    <tr>
        <td colspan="<?= count($scheduled_dates) + ($filter_type == 'daily' ? 4 : 6) ?>" class="text-center font-bold" style="font-size: 14pt;"><?= strtoupper($school_profile['nama_yayasan'] ?? 'YAYASAN') ?></td>
    </tr>
    <tr>
        <td colspan="<?= count($scheduled_dates) + ($filter_type == 'daily' ? 4 : 6) ?>" class="text-center font-bold" style="font-size: 16pt;"><?= strtoupper($school_profile['nama_madrasah'] ?? 'MADRASAH') ?></td>
    </tr>
    <tr>
        <td colspan="<?= count($scheduled_dates) + ($filter_type == 'daily' ? 4 : 6) ?>" class="text-center"><?= $school_profile['alamat'] ?? '' ?></td>
    </tr>
    <tr><td colspan="<?= count($scheduled_dates) + ($filter_type == 'daily' ? 4 : 6) ?>"></td></tr>
    <tr>
        <td colspan="<?= count($scheduled_dates) + ($filter_type == 'daily' ? 4 : 6) ?>" class="text-center font-bold" style="text-decoration: underline;"><?= $report_title ?></td>
    </tr>
    <?php if ($filter_type == 'daily'): ?>
    <tr>
        <td colspan="<?= count($scheduled_dates) + 4 ?>" class="text-center">Tanggal: <?= formatDateIndonesia($selected_date) ?></td>
    </tr>
    <?php endif; ?>
    <tr><td colspan="<?= count($scheduled_dates) + ($filter_type == 'daily' ? 4 : 6) ?>"></td></tr>
    <thead>
        <tr>
            <th rowspan="2" style="background-color: #f0f0f0;">No</th>
            <th rowspan="2" style="background-color: #f0f0f0;">Nama Guru</th>
            <?php if ($filter_type == 'daily'): ?>
                <th rowspan="2" style="background-color: #f0f0f0;">NUPTK</th>
                <th rowspan="2" style="background-color: #f0f0f0;">Status Kehadiran</th>
            <?php else: ?>
                <th colspan="<?= count($scheduled_dates) ?: 1 ?>" style="background-color: #f0f0f0;">Tanggal Pelaksanaan Les</th>
                <th colspan="4" style="background-color: #f0f0f0;">Total</th>
            <?php endif; ?>
        </tr>
        <?php if ($filter_type != 'daily'): ?>
        <tr>
            <?php foreach($scheduled_dates as $d): ?>
                <th style="background-color: #f0f0f0;"><?= date('d/m', strtotime($d)) ?></th>
            <?php endforeach; if(empty($scheduled_dates)) echo "<th style='background-color: #f0f0f0;'>-</th>"; ?>
            <th style="background-color: #f0f0f0;">H</th>
            <th style="background-color: #f0f0f0;">S</th>
            <th style="background-color: #f0f0f0;">I</th>
            <th style="background-color: #f0f0f0;">A</th>
        </tr>
        <?php endif; ?>
    </thead>
    <tbody>
        <?php foreach ($teachers as $i => $t): 
            $h=$s_count=$iz=$a=0;
        ?>
        <tr>
            <td class="text-center"><?= $i+1 ?></td>
            <td class="text-left"><?= htmlspecialchars($t['nama_guru']) ?></td>
            <?php if ($filter_type == 'daily'): 
                $st = $attendance[$t['id_guru']][$selected_date] ?? 'Belum Absen';
            ?>
                <td class="text-center"><?= htmlspecialchars($t['nuptk'] ?: '-') ?></td>
                <td class="text-center"><strong><?= $st ?></strong></td>
            <?php else: ?>
                <?php foreach($scheduled_dates as $d): 
                    $st = $attendance[$t['id_guru']][$d] ?? '';
                    $val = '-';
                    if($st == 'Hadir') { $val = 'H'; $h++; }
                    elseif($st == 'Sakit') { $val = 'S'; $s_count++; }
                    elseif($st == 'Izin') { $val = 'I'; $iz++; }
                    elseif($st == 'Alpa') { $val = 'A'; $a++; }
                ?>
                    <td class="text-center"><?= $val ?></td>
                <?php endforeach; if(empty($scheduled_dates)) echo "<td class='text-center'>-</td>"; ?>
                <td class="text-center"><?= $h ?></td>
                <td class="text-center"><?= $s_count ?></td>
                <td class="text-center"><?= $iz ?></td>
                <td class="text-center"><?= $a ?></td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </tbody>
    <tr><td colspan="<?= count($scheduled_dates) + ($filter_type == 'daily' ? 4 : 6) ?>"></td></tr>
    <tr>
        <td colspan="<?= count($scheduled_dates) + ($filter_type == 'daily' ? 4 : 6) ?>" class="text-center">
            <?= $school_profile['tempat_jadwal'] ?? 'Sukosono' ?>, <?= formatDateIndonesia(date('Y-m-d')) ?><br>
            Mengetahui,<br>
            Kepala Madrasah,<br><br><br><br>
            <strong><u><?= $school_profile['kepala_madrasah'] ?? '-' ?></u></strong><br>
            NIP. <?= $school_profile['nip_kepala'] ?? '-' ?>
        </td>
    </tr>
</table>
