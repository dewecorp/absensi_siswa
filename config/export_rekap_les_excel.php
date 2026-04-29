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
$absent_summary = [];

// Get all scheduled dates based on filter
if ($filter_type == 'daily') {
    $scheduled_dates = [$selected_date];
    $report_title = "REKAP ABSENSI LES HARIAN SISWA KELAS " . $nama_kelas_fixed;

    $stmt_abs = $pdo->prepare("
        SELECT s.nama_siswa, s.nisn, al.status AS keterangan
        FROM tb_absensi_les al
        JOIN tb_siswa s ON s.id_siswa = al.id_siswa
        WHERE al.tanggal = ?
          AND s.id_kelas = ?
          AND al.status IN ('Sakit', 'Izin', 'Alpa')
        ORDER BY s.nama_siswa ASC
    ");
    $stmt_abs->execute([$selected_date, $id_kelas_fixed]);
    $absent_summary = $stmt_abs->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt_sched = $pdo->query("SELECT DISTINCT tanggal FROM tb_jadwal_les ORDER BY tanggal ASC");
    $scheduled_dates = $stmt_sched->fetchAll(PDO::FETCH_COLUMN);
    $report_title = "REKAP ABSENSI LES SISWA KELAS " . $nama_kelas_fixed;
}

$filename = "Rekap_Absensi_Les_Kelas_" . $nama_kelas_fixed . "_" . ($filter_type == 'daily' ? $selected_date : 'All') . ".xls";
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
    .header-row td { border: none; font-size: 14pt; }
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
    <?php
        $counts_abs = ['Sakit' => 0, 'Izin' => 0, 'Alpa' => 0];
        foreach ($absent_summary as $abx) {
            if (isset($counts_abs[$abx['keterangan']])) {
                $counts_abs[$abx['keterangan']]++;
            }
        }
    ?>
    <tr>
        <td colspan="<?= count($scheduled_dates) + 4 ?>" class="font-bold">Ringkasan Ketidakhadiran Les: Sakit <?= (int)$counts_abs['Sakit'] ?> | Izin <?= (int)$counts_abs['Izin'] ?> | Alpa <?= (int)$counts_abs['Alpa'] ?></td>
    </tr>
    <?php if (!empty($absent_summary)): ?>
    <tr>
        <td colspan="<?= count($scheduled_dates) + 4 ?>" class="font-bold">Detail Ketidakhadiran:</td>
    </tr>
    <tr>
        <td class="font-bold text-center">No</td>
        <td class="font-bold">Nama Siswa</td>
        <td class="font-bold text-center">NISN</td>
        <td class="font-bold text-center">Keterangan</td>
        <td colspan="<?= count($scheduled_dates) ?>"></td>
    </tr>
    <?php foreach ($absent_summary as $idx_abs => $abs): ?>
    <tr>
        <td class="text-center"><?= (int)($idx_abs + 1) ?></td>
        <td><?= htmlspecialchars($abs['nama_siswa']) ?></td>
        <td class="text-center"><?= htmlspecialchars($abs['nisn']) ?></td>
        <td class="text-center"><?= htmlspecialchars($abs['keterangan']) ?></td>
        <td colspan="<?= count($scheduled_dates) ?>"></td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php endif; ?>
    <tr><td colspan="<?= count($scheduled_dates) + ($filter_type == 'daily' ? 4 : 6) ?>"></td></tr>
    <thead>
        <tr>
            <th rowspan="2" style="background-color: #f0f0f0;">No</th>
            <th rowspan="2" style="background-color: #f0f0f0;">Nama Siswa</th>
            <?php if ($filter_type == 'daily'): ?>
                <th rowspan="2" style="background-color: #f0f0f0;">NISN</th>
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
        <?php foreach ($students as $i => $s): 
            $h=$s_count=$iz=$a=0;
        ?>
        <tr>
            <td class="text-center"><?= $i+1 ?></td>
            <td class="text-left"><?= htmlspecialchars($s['nama_siswa']) ?></td>
            <?php if ($filter_type == 'daily'): 
                $st = $attendance[$s['id_siswa']][$selected_date] ?? 'Belum Absen';
            ?>
                <td class="text-center"><?= htmlspecialchars($s['nisn']) ?></td>
                <td class="text-center"><strong><?= $st ?></strong></td>
            <?php else: ?>
                <?php foreach($scheduled_dates as $d): 
                    $st = $attendance[$s['id_siswa']][$d] ?? '';
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
        <td colspan="3" class="text-center">
            <?= $school_profile['tempat_jadwal'] ?? 'Sukosono' ?>, <?= formatDateIndonesia(date('Y-m-d')) ?><br>
            Wali Kelas <?= $nama_kelas_fixed ?>,<br><br><br><br>
            <strong><u><?= $wali_kelas_fixed ?></u></strong>
        </td>
        <td colspan="<?= count($scheduled_dates) - ($filter_type == 'daily' ? 1 : 1) ?>"></td>
        <td colspan="<?= $filter_type == 'daily' ? 2 : 4 ?>" class="text-center">
            Mengetahui,<br>
            Kepala Madrasah,<br><br><br><br>
            <strong><u><?= $school_profile['kepala_madrasah'] ?? '-' ?></u></strong><br>
            NIP. <?= $school_profile['nip_kepala'] ?? '-' ?>
        </td>
    </tr>
</table>
