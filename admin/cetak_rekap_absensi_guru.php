<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha'])) {
    redirect('../login.php');
}

// Server-rendered printable recap so the browser can reload the tab without losing content.
$filter_type = $_GET['filter_type'] ?? 'daily';
$selected_date = $_GET['attendance_date'] ?? date('Y-m-d');
$selected_month = $_GET['month_picker'] ?? date('Y-m');
$selected_teacher = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;

$month_names = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

$school_profile = getSchoolProfile($pdo);
$active_semester = $school_profile['semester'] ?? 'Semester 1';
$madrasah_head_name = $school_profile['kepala_madrasah'] ?? '.........................';
$madrasah_head_signature = $school_profile['ttd_kepala'] ?? '';
$school_name = $school_profile['nama_madrasah'] ?? 'Madrasah';
$school_city = $school_profile['tempat_jadwal'] ?? 'Padang';
$report_date = formatDateIndonesia(date('Y-m-d'));

$daily_results = [];
$monthly_results = [];
$semester_results = [];
$teacher_results = [];
$teacher_attendance_summary = [];
$holidays = [];
$start_month = 1;
$end_month = 12;
$year = substr($selected_month, 0, 4);
$month = substr($selected_month, 5, 2);

if ($filter_type == 'daily' && !empty($selected_date)) {
    $stmt = $pdo->query("SELECT id_guru, nama_guru, nuptk FROM tb_guru ORDER BY nama_guru ASC");
    $all_daily_teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT g.id_guru, g.nama_guru, g.nuptk, a.status as keterangan, a.keterangan as catatan, a.tanggal, a.waktu_input
        FROM tb_absensi_guru a
        LEFT JOIN tb_guru g ON a.id_guru = g.id_guru  
        WHERE a.tanggal = ?
        ORDER BY g.nama_guru ASC
    ");
    $stmt->execute([$selected_date]);
    $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $attendance_by_teacher = [];
    foreach ($attendance_records as $record) {
        $attendance_by_teacher[$record['id_guru']] = $record;
    }

    foreach ($all_daily_teachers as $teacher) {
        if (isset($attendance_by_teacher[$teacher['id_guru']])) {
            $daily_results[] = $attendance_by_teacher[$teacher['id_guru']];
        } else {
            $daily_results[] = [
                'nama_guru' => $teacher['nama_guru'],
                'nuptk' => $teacher['nuptk'],
                'keterangan' => 'Belum Absen',
                'catatan' => '',
                'tanggal' => $selected_date,
                'waktu_input' => null
            ];
        }
    }
} elseif ($filter_type == 'monthly' && !empty($selected_month)) {
    $stmt = $pdo->query("SELECT id_guru, nama_guru, nuptk FROM tb_guru ORDER BY nama_guru ASC");
    $all_teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT g.id_guru, g.nama_guru, g.nuptk, a.status as keterangan, DAY(a.tanggal) as day
        FROM tb_absensi_guru a
        LEFT JOIN tb_guru g ON a.id_guru = g.id_guru
        WHERE YEAR(a.tanggal) = ? AND MONTH(a.tanggal) = ?
        ORDER BY g.nama_guru, a.tanggal
    ");
    $stmt->execute([$year, $month]);
    $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $attendance_by_teacher = [];
    foreach ($attendance_records as $record) {
        $teacher_id = $record['id_guru'];
        if (!isset($attendance_by_teacher[$teacher_id])) {
            $attendance_by_teacher[$teacher_id] = [
                'days' => array_fill(1, 31, ''),
                'summary' => ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0]
            ];
        }
        $day = (int)$record['day'];
        $attendance_by_teacher[$teacher_id]['days'][$day] = $record['keterangan'];
        $status_key = ucfirst($record['keterangan']);
        if (isset($attendance_by_teacher[$teacher_id]['summary'][$status_key])) {
            $attendance_by_teacher[$teacher_id]['summary'][$status_key]++;
        }
    }

    $teacher_attendance = [];
    foreach ($all_teachers as $teacher) {
        $teacher_id = $teacher['id_guru'];
        $teacher_data = [
            'nama_guru' => $teacher['nama_guru'],
            'nuptk' => $teacher['nuptk'],
            'days' => array_fill(1, 31, ''),
            'summary' => ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0]
        ];
        if (isset($attendance_by_teacher[$teacher_id])) {
            $teacher_data['days'] = $attendance_by_teacher[$teacher_id]['days'];
            $teacher_data['summary'] = $attendance_by_teacher[$teacher_id]['summary'];
        }
        $teacher_attendance[$teacher_id] = $teacher_data;
    }

    $holidays = getHolidays($pdo, (int)$year, (int)$month);
    $monthly_results = array_values($teacher_attendance);
} elseif ($filter_type == 'teacher' && $selected_teacher > 0) {
    $stmt = $pdo->prepare("
        SELECT g.nama_guru, g.nuptk, a.status as keterangan, a.keterangan as catatan, a.tanggal
        FROM tb_absensi_guru a
        LEFT JOIN tb_guru g ON a.id_guru = g.id_guru
        WHERE g.id_guru = ?
        ORDER BY a.tanggal DESC
    ");
    $stmt->execute([$selected_teacher]);
    $teacher_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $summary = ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0];
    foreach ($teacher_results as $record) {
        $status_key = ucfirst($record['keterangan']);
        if (isset($summary[$status_key])) {
            $summary[$status_key]++;
        }
    }
    $teacher_attendance_summary = $summary;

    $stmt = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([$selected_teacher]);
    $teacher_info = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($filter_type == 'semester') {
    $academic_year = $school_profile['tahun_ajaran'] ?? (date('Y') . '/' . (date('Y') + 1));
    $years = explode('/', $academic_year);
    $start_year = (int)($years[0] ?? date('Y'));
    $end_year = (int)($years[1] ?? (date('Y') + 1));

    if ($active_semester == 'Semester 1') {
        $query_year = $start_year;
        $start_month = 7;
        $end_month = 12;
    } else {
        $query_year = $end_year;
        $start_month = 1;
        $end_month = 6;
    }

    $stmt = $pdo->query("SELECT id_guru, nama_guru, nuptk FROM tb_guru ORDER BY nama_guru ASC");
    $all_teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT g.id_guru, g.nama_guru, g.nuptk, a.status as keterangan, a.tanggal,
               MONTH(a.tanggal) as month, DAY(a.tanggal) as day
        FROM tb_absensi_guru a
        LEFT JOIN tb_guru g ON a.id_guru = g.id_guru
        WHERE YEAR(a.tanggal) = ? AND MONTH(a.tanggal) BETWEEN ? AND ?
        ORDER BY g.nama_guru, a.tanggal
    ");
    $stmt->execute([$query_year, $start_month, $end_month]);
    $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $attendance_by_teacher = [];
    foreach ($attendance_records as $record) {
        $teacher_id = $record['id_guru'];
        $m = (int)$record['month'];
        $status = $record['keterangan'];

        if (!isset($attendance_by_teacher[$teacher_id])) {
            $attendance_by_teacher[$teacher_id] = [
                'monthly_totals' => [],
                'summary' => ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0]
            ];
            for ($i = $start_month; $i <= $end_month; $i++) {
                $attendance_by_teacher[$teacher_id]['monthly_totals'][$i] = ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0];
            }
        }

        $status_key = ucfirst($status);
        if (isset($attendance_by_teacher[$teacher_id]['monthly_totals'][$m][$status_key])) {
            $attendance_by_teacher[$teacher_id]['monthly_totals'][$m][$status_key]++;
            $attendance_by_teacher[$teacher_id]['summary'][$status_key]++;
        }
    }

    $teacher_attendance = [];
    foreach ($all_teachers as $teacher) {
        $teacher_id = $teacher['id_guru'];
        $teacher_data = [
            'nama_guru' => $teacher['nama_guru'],
            'nuptk' => $teacher['nuptk'],
            'monthly_totals' => [],
            'summary' => ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0]
        ];
        for ($i = $start_month; $i <= $end_month; $i++) {
            $teacher_data['monthly_totals'][$i] = ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0];
        }
        if (isset($attendance_by_teacher[$teacher_id])) {
            $teacher_data['monthly_totals'] = $attendance_by_teacher[$teacher_id]['monthly_totals'];
            $teacher_data['summary'] = $attendance_by_teacher[$teacher_id]['summary'];
        }
        $teacher_attendance[$teacher_id] = $teacher_data;
    }

    $semester_results = array_values($teacher_attendance);
}

// Build title per filter type
if ($filter_type == 'daily') {
    $title = 'Rekap Kehadiran Guru - Harian (' . date('d F Y', strtotime($selected_date)) . ')';
} elseif ($filter_type == 'monthly') {
    $title = 'Rekap Kehadiran Guru - Bulanan (' . $month_names[(int)$month] . ' ' . $year . ')';
} elseif ($filter_type == 'semester') {
    $title = 'Rekap Kehadiran Guru - ' . $active_semester . ' (' . ($school_profile['tahun_ajaran'] ?? '') . ')';
} elseif ($filter_type == 'teacher') {
    $title = 'Rekap Kehadiran Guru - Per Guru' . (!empty($teacher_info['nama_guru']) ? ' (' . $teacher_info['nama_guru'] . ')' : '');
} else {
    $title = 'Rekap Kehadiran Guru';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($title); ?></title>
<style>
@page { size: 330mm 215mm; margin: 10mm; } /* F4 Landscape */
@media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } .no-print { display: none !important; } }
body { font-family: Arial, sans-serif; margin: 20px; }
.header { text-align: center; margin-bottom: 20px; }
.header h2 { margin: 0; color: #333; }
.header p { margin: 5px 0; color: #666; }
table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 11px; }
th, td { border: 1px solid #000; padding: 5px; text-align: center; }
td:nth-child(2) { text-align: left; white-space: normal; }
th { background-color: #368DBC !important; color: white !important; font-weight: bold; }
tr:nth-child(even) { background-color: #f2f2f2; }
.bg-success { background-color: #28a745 !important; }
.bg-primary { background-color: #007bff !important; }
.bg-warning { background-color: #ffc107 !important; background-color: #ffc107; color: #000 !important; }
.bg-danger { background-color: #dc3545 !important; }
.bg-light { background-color: #f8f9fa !important; }
.text-white { color: #fff !important; }
.font-weight-bold { font-weight: bold; }
.print-btn { position: fixed; top: 20px; right: 20px; padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.2); z-index: 9999; }
.print-btn:hover { background: #0056b3; }
.signature-block { margin-top: 30px; display: flex; justify-content: flex-end; width: 100%; page-break-inside: avoid; }
.signature-box { text-align: center; width: 300px; }
</style>
</head>
<body>

<button class="print-btn no-print" onclick="window.print()"><i>&#128424;</i> Cetak / Simpan PDF</button>

<div class="header">
    <h2><?php echo htmlspecialchars($title); ?></h2>
    <p><?php echo htmlspecialchars($school_name); ?></p>
    <p>Tahun Ajaran: <?php echo htmlspecialchars($school_profile['tahun_ajaran'] ?? '-'); ?><?php if ($filter_type != 'semester'): ?> | <?php echo htmlspecialchars($active_semester); ?><?php endif; ?></p>
    <p>Dicetak pada: <?php echo date('d-m-Y H:i:s'); ?></p>
</div>

<?php if ($filter_type == 'daily'): ?>
<table>
    <thead>
        <tr>
            <th width="5%">No</th>
            <th>Nama Guru</th>
            <th>NUPTK</th>
            <th>Status</th>
            <th>Waktu</th>
            <th>Keterangan</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($daily_results as $index => $row): ?>
        <tr>
            <td><?php echo $index + 1; ?></td>
            <td><?php echo htmlspecialchars($row['nama_guru']); ?></td>
            <td><?php echo htmlspecialchars($row['nuptk']); ?></td>
            <td><?php echo ucfirst(strtolower($row['keterangan'])); ?></td>
            <td><?php echo !empty($row['waktu_input']) ? date('H:i:s', strtotime($row['waktu_input'])) : '-'; ?></td>
            <td style="text-align: left;"><?php echo htmlspecialchars($row['catatan'] ?? '-'); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php elseif ($filter_type == 'monthly'): ?>
<table>
    <thead>
        <tr>
            <th rowspan="2">No</th>
            <th rowspan="2" style="min-width: 200px;">Nama Guru</th>
            <th colspan="31">Tanggal</th>
            <th colspan="3">Total</th>
        </tr>
        <tr>
            <?php for ($i = 1; $i <= 31; $i++): ?>
            <th style="min-width: 25px; font-size: 10px;"><?php echo $i; ?></th>
            <?php endfor; ?>
            <th class="bg-success text-white" title="Hadir">H</th>
            <th class="bg-primary text-white" title="Sakit">S</th>
            <th class="bg-warning" title="Izin">I</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($monthly_results as $index => $row): ?>
        <tr>
            <td><?php echo $index + 1; ?></td>
            <td><?php echo htmlspecialchars($row['nama_guru']); ?></td>
            <?php for ($i = 1; $i <= 31; $i++):
                $status = isset($row['days'][$i]) ? strtolower($row['days'][$i]) : '';
                $code = '';
                $bg = '';
                $current_date = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-' . str_pad($i, 2, '0', STR_PAD_LEFT);
                $is_holiday = isset($holidays[$current_date]);
                if ($is_holiday) {
                    $code = 'L'; $bg = 'bg-danger text-white';
                } elseif ($status == 'hadir') {
                    $code = 'H'; $bg = 'bg-success text-white';
                } elseif ($status == 'sakit') {
                    $code = 'S'; $bg = 'bg-primary text-white';
                } elseif ($status == 'izin') {
                    $code = 'I'; $bg = 'bg-warning';
                }
                $tip = $is_holiday ? ' title="' . htmlspecialchars($holidays[$current_date]) . '"' : '';
            ?>
            <td class="<?php echo $bg; ?>" style="padding: 2px;"<?php echo $tip; ?>><?php echo $code; ?></td>
            <?php endfor; ?>
            <td class="font-weight-bold"><?php echo $row['summary']['Hadir']; ?></td>
            <td class="font-weight-bold"><?php echo $row['summary']['Sakit']; ?></td>
            <td class="font-weight-bold"><?php echo $row['summary']['Izin']; ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php elseif ($filter_type == 'semester'): ?>
<table>
    <thead>
        <tr>
            <th rowspan="2">No</th>
            <th rowspan="2" style="min-width: 200px;">Nama Guru</th>
            <?php for ($m = $start_month; $m <= $end_month; $m++): ?>
            <th colspan="3"><?php echo $month_names[$m]; ?></th>
            <?php endfor; ?>
            <th colspan="3" class="bg-light" style="color:#000 !important;">Total Semester</th>
        </tr>
        <tr>
            <?php
            for ($m = $start_month; $m <= $end_month; $m++) {
                echo '<th class="bg-success text-white" style="font-size: 9px; padding: 2px;">H</th>';
                echo '<th class="bg-primary text-white" style="font-size: 9px; padding: 2px;">S</th>';
                echo '<th class="bg-warning" style="font-size: 9px; padding: 2px;">I</th>';
            }
            echo '<th class="bg-success text-white" style="font-size: 9px; padding: 2px;">H</th>';
            echo '<th class="bg-primary text-white" style="font-size: 9px; padding: 2px;">S</th>';
            echo '<th class="bg-warning" style="font-size: 9px; padding: 2px;">I</th>';
            ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($semester_results as $index => $row): ?>
        <tr>
            <td><?php echo $index + 1; ?></td>
            <td><?php echo htmlspecialchars($row['nama_guru']); ?></td>
            <?php for ($m = $start_month; $m <= $end_month; $m++):
                $monthly = $row['monthly_totals'][$m]; ?>
                <td style="padding: 2px;"><?php echo $monthly['Hadir'] > 0 ? $monthly['Hadir'] : '-'; ?></td>
                <td style="padding: 2px;"><?php echo $monthly['Sakit'] > 0 ? $monthly['Sakit'] : '-'; ?></td>
                <td style="padding: 2px;"><?php echo $monthly['Izin'] > 0 ? $monthly['Izin'] : '-'; ?></td>
            <?php endfor; ?>
            <td class="font-weight-bold bg-light"><?php echo $row['summary']['Hadir']; ?></td>
            <td class="font-weight-bold bg-light"><?php echo $row['summary']['Sakit']; ?></td>
            <td class="font-weight-bold bg-light"><?php echo $row['summary']['Izin']; ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php elseif ($filter_type == 'teacher'): ?>
<table>
    <thead>
        <tr>
            <th width="5%">No</th>
            <th>Tanggal</th>
            <th>Status</th>
            <th>Keterangan</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($teacher_results as $index => $row): ?>
        <tr>
            <td><?php echo $index + 1; ?></td>
            <td><?php echo date('d F Y', strtotime($row['tanggal'])); ?></td>
            <td><?php echo ucfirst(strtolower($row['keterangan'])); ?></td>
            <td style="text-align: left;"><?php echo htmlspecialchars($row['catatan'] ?? '-'); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<p style="margin-top: 15px;"><strong>Total: Hadir <?php echo $teacher_attendance_summary['Hadir']; ?> | Sakit <?php echo $teacher_attendance_summary['Sakit']; ?> | Izin <?php echo $teacher_attendance_summary['Izin']; ?></strong></p>
<?php endif; ?>

<div class="signature-block">
    <div class="signature-box">
        <p><?php echo htmlspecialchars($school_city); ?>, <?php echo htmlspecialchars($report_date); ?><br>Kepala Madrasah,</p>
        <?php if ($madrasah_head_signature):
            $qr_content = 'Validasi Tanda Tangan Digital: ' . $madrasah_head_name . ' - ' . $school_name;
            $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qr_content);
        ?>
        <img src="<?php echo htmlspecialchars($qr_url); ?>" alt="QR Signature" style="width: 80px; height: 80px; margin: 10px auto; display: block;">
        <?php else: ?>
        <br><br><br>
        <?php endif; ?>
        <p><strong><?php echo htmlspecialchars($madrasah_head_name); ?></strong></p>
    </div>
</div>

<script>window.onload = function() { setTimeout(function() { window.print(); }, 300); };</script>
</body>
</html>
