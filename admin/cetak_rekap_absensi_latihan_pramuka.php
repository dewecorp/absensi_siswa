<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/pramuka_latihan.php';

if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha', 'guru', 'wali'])) {
    redirect('../login.php');
}

try {
    pramukaLatihanEnsureSchema($pdo);
} catch (Throwable $e) {
}

$scope = pramukaLatihanScope($pdo);
$tingkat_id = isset($_GET['tingkat_id']) ? (int)$_GET['tingkat_id'] : 0;
$filter_type = $_GET['type'] ?? 'daily';
$attendance_date = $_GET['date'] ?? date('Y-m-d');
$month_picker = $_GET['month'] ?? date('Y-m');
$peserta_id = isset($_GET['peserta_id']) ? (int)$_GET['peserta_id'] : 0;

if ($tingkat_id <= 0) {
    die('Error: Tingkatan wajib dipilih.');
}
if (!pramukaLatihanCanAccessTingkat($scope, $tingkat_id)) {
    die('Error: Tingkatan ini bukan binaan Anda.');
}

$school_profile = getSchoolProfile($pdo);
$school_name = $school_profile['nama_madrasah'] ?? 'Madrasah Ibtidaiyah';
$school_city = $school_profile['tempat_jadwal'] ?? 'Sukosono';
$active_semester = $school_profile['semester'] ?? 'Semester 1';
$academic_year = $school_profile['tahun_ajaran'] ?? (date('Y') . '/' . (date('Y') + 1));
$madrasah_head_name = $school_profile['kepala_madrasah'] ?? '';
$madrasah_head_nip = $school_profile['nip_kepala'] ?? '-';
$report_date = formatDateIndonesia(date('Y-m-d'));

$stmt = $pdo->prepare("SELECT nama_tingkat FROM tb_tingkat_barung WHERE id_tingkat_barung = ? LIMIT 1");
$stmt->execute([$tingkat_id]);
$tingkat_name = (string)($stmt->fetchColumn() ?: '');

$pembina_name = '';
try {
    $stmt = $pdo->prepare("SELECT pp.nama_pembina
        FROM tb_pembina_tingkat pt
        JOIN tb_pembina_pramuka pp ON pp.id_pembina_pramuka = pt.id_pembina_pramuka
        WHERE pt.id_tingkat_barung = ?
        ORDER BY pp.id_pembina_pramuka ASC
        LIMIT 1");
    $stmt->execute([$tingkat_id]);
    $pembina_name = (string)($stmt->fetchColumn() ?: '');
} catch (Throwable $e) {
}
if ($pembina_name === '') {
    $pembina_name = (string)($_SESSION['nama_guru'] ?? ($_SESSION['username'] ?? 'Pembina Pramuka'));
}

$results = [];
$title = '';
$m_start = 0;
$m_end = 0;

if ($filter_type === 'daily') {
    $title = 'Rekap Kehadiran Latihan Harian - Tanggal: ' . formatDateIndonesia($attendance_date);
    $stmt = $pdo->prepare("
        SELECT p.nama_peserta_didik AS nama_siswa, a.keterangan, a.jam_masuk
        FROM tb_peserta_didik_barung p
        LEFT JOIN tb_absensi_latihan_pramuka a
            ON a.id_peserta_didik_barung = p.id_peserta_didik_barung AND a.tanggal = ?
        WHERE p.id_tingkat_barung = ?
          AND " . pramukaLatihanPesertaAktifSql('p') . "
        ORDER BY p.nama_peserta_didik ASC
    ");
    $stmt->execute([$attendance_date, $tingkat_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($filter_type === 'monthly') {
    if (!preg_match('/^\d{4}-\d{2}$/', (string)$month_picker)) {
        $month_picker = date('Y-m');
    }
    $year = (int)substr($month_picker, 0, 4);
    $month = (int)substr($month_picker, 5, 2);
    $month_names = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $title = 'Rekap Kehadiran Latihan Bulanan - ' . ($month_names[$month] ?? '') . ' ' . $year;

    $stmt = $pdo->prepare("
        SELECT p.id_peserta_didik_barung, p.nama_peserta_didik AS nama_siswa, p.nta AS nisn
        FROM tb_peserta_didik_barung p
        WHERE p.id_tingkat_barung = ?
          AND " . pramukaLatihanPesertaAktifSql('p') . "
        ORDER BY p.nama_peserta_didik ASC
    ");
    $stmt->execute([$tingkat_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT a.id_peserta_didik_barung, a.keterangan, DAY(a.tanggal) AS day
        FROM tb_absensi_latihan_pramuka a
        JOIN tb_peserta_didik_barung p ON p.id_peserta_didik_barung = a.id_peserta_didik_barung
        WHERE p.id_tingkat_barung = ?
          AND YEAR(a.tanggal) = ? AND MONTH(a.tanggal) = ?
          AND " . pramukaLatihanPesertaAktifSql('p') . "
    ");
    $stmt->execute([$tingkat_id, $year, $month]);
    $attendance_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $attendance_map = [];
    foreach ($attendance_data as $row) {
        $attendance_map[(int)$row['id_peserta_didik_barung']][(int)$row['day']] = (string)$row['keterangan'];
    }

    $holidays = getHolidays($pdo, $year, $month);
    $num_days = cal_days_in_month(CAL_GREGORIAN, $month, $year);

    foreach ($students as $student) {
        $pid = (int)$student['id_peserta_didik_barung'];
        $row = $student;
        $row['days'] = [];
        $summary = ['H' => 0, 'S' => 0, 'I' => 0, 'A' => 0];
        for ($i = 1; $i <= 31; $i++) {
            if ($i > $num_days) {
                $row['days'][$i] = '-';
                continue;
            }
            $date_str = sprintf('%04d-%02d-%02d', $year, $month, $i);
            $status = $attendance_map[$pid][$i] ?? '';
            $display = '';
            if ($status === 'Hadir' || $status === 'Terlambat') {
                $display = 'H';
                $summary['H']++;
            } elseif ($status === 'Sakit') {
                $display = 'S';
                $summary['S']++;
            } elseif ($status === 'Izin') {
                $display = 'I';
                $summary['I']++;
            } elseif ($status === 'Alpa') {
                $display = 'A';
                $summary['A']++;
            } elseif (isset($holidays[$date_str])) {
                $display = 'L';
            }
            $row['days'][$i] = $display;
        }
        $row['summary'] = $summary;
        $results[] = $row;
    }
} elseif ($filter_type === 'semester') {
    $title = 'Rekap Kehadiran Latihan ' . $active_semester;

    $stmt = $pdo->prepare("
        SELECT p.id_peserta_didik_barung, p.nama_peserta_didik AS nama_siswa, p.nta AS nisn
        FROM tb_peserta_didik_barung p
        WHERE p.id_tingkat_barung = ?
          AND " . pramukaLatihanPesertaAktifSql('p') . "
        ORDER BY p.nama_peserta_didik ASC
    ");
    $stmt->execute([$tingkat_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $years = explode('/', (string)$academic_year);
    $start_year = (int)($years[0] ?? date('Y'));
    $end_year = (int)($years[1] ?? (date('Y') + 1));

    if ($active_semester === 'Semester 1') {
        $m_start = 7;
        $m_end = 12;
        $query_year = $start_year;
    } else {
        $m_start = 1;
        $m_end = 6;
        $query_year = $end_year;
    }

    $stmt = $pdo->prepare("
        SELECT a.id_peserta_didik_barung, a.keterangan, MONTH(a.tanggal) AS month, COUNT(*) AS jumlah
        FROM tb_absensi_latihan_pramuka a
        JOIN tb_peserta_didik_barung p ON p.id_peserta_didik_barung = a.id_peserta_didik_barung
        WHERE p.id_tingkat_barung = ?
          AND YEAR(a.tanggal) = ? AND MONTH(a.tanggal) BETWEEN ? AND ?
          AND " . pramukaLatihanPesertaAktifSql('p') . "
        GROUP BY a.id_peserta_didik_barung, a.keterangan, MONTH(a.tanggal)
    ");
    $stmt->execute([$tingkat_id, $query_year, $m_start, $m_end]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($records as $r) {
        $pid = (int)$r['id_peserta_didik_barung'];
        $m = (int)$r['month'];
        $key = ($r['keterangan'] === 'Terlambat') ? 'H' : (string)$r['keterangan'];
        if (!isset($map[$pid][$m])) {
            $map[$pid][$m] = ['H' => 0, 'S' => 0, 'I' => 0, 'A' => 0, 'B' => 0];
        }
        if (isset($map[$pid][$m][$key])) {
            $map[$pid][$m][$key] += (int)$r['jumlah'];
        }
    }

    foreach ($students as $student) {
        $pid = (int)$student['id_peserta_didik_barung'];
        $row = $student;
        $row['monthly'] = [];
        $summary = ['H' => 0, 'S' => 0, 'I' => 0, 'A' => 0, 'B' => 0];
        for ($m = $m_start; $m <= $m_end; $m++) {
            $row['monthly'][$m] = $map[$pid][$m] ?? ['H' => 0, 'S' => 0, 'I' => 0, 'A' => 0, 'B' => 0];
            foreach ($row['monthly'][$m] as $k => $v) {
                $summary[$k] += (int)$v;
            }
        }
        $row['summary'] = $summary;
        $results[] = $row;
    }
} elseif ($filter_type === 'student') {
    $stmt = $pdo->prepare("
        SELECT p.id_peserta_didik_barung, p.nama_peserta_didik AS nama_siswa, p.nta AS nisn
        FROM tb_peserta_didik_barung p
        WHERE p.id_peserta_didik_barung = ? AND p.id_tingkat_barung = ?
          AND " . pramukaLatihanPesertaAktifSql('p') . "
        LIMIT 1
    ");
    $stmt->execute([$peserta_id, $tingkat_id]);
    $peserta_info = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$peserta_info) {
        die('Error: Data siswa tidak ditemukan atau sudah tidak aktif.');
    }

    $title = 'Rekap Kehadiran Latihan Siswa: ' . ($peserta_info['nama_siswa'] ?? '');

    $stmt = $pdo->prepare("
        SELECT tanggal, keterangan
        FROM tb_absensi_latihan_pramuka
        WHERE id_peserta_didik_barung = ?
        ORDER BY tanggal DESC
    ");
    $stmt->execute([$peserta_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($title) ?></title>
    <style>
        @page { size: legal landscape; margin: 0cm 0.5cm 0.2cm 0.5cm; }
        body { font-family: Arial, sans-serif; font-size: 11px; margin: 0; padding: 2px 10px 0; }
        .header { text-align: center; margin-bottom: 4px; position: relative; }
        .logo { position: absolute; left: 0; top: 0; max-width: 60px; }
        h2, h3, h4 { margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 3px; table-layout: fixed; }
        th, td { border: 1px solid #000; padding: 2px; text-align: center; overflow: hidden; text-overflow: ellipsis; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .text-left { text-align: left; padding-left: 5px; }
        .col-no { width: 25px; }
        .col-nama { width: 180px; }
        .col-tgl { width: 20px; }
        .col-stat { width: 22px; }
        .signature-block { page-break-inside: avoid; break-inside: avoid; }
        .signature-wrapper { margin-top: 6px; display: table; width: 100%; page-break-inside: avoid; break-inside: avoid; }
        .signature-box { display: table-cell; width: 40%; text-align: center; vertical-align: top; page-break-inside: avoid; break-inside: avoid; }
        .signature-gap { display: table-cell; width: 20%; }
        .signature-space { height: 80px; margin: 5px 0; page-break-inside: avoid; break-inside: avoid; }
        .qr-code { width: 75px; height: 75px; margin: 0 auto; display: block; page-break-inside: avoid; break-inside: avoid; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="header">
        <img src="../assets/img/logo_1782822263.png" alt="Logo" class="logo">
        <h2>Sistem Informasi Madrasah</h2>
        <h3><?= htmlspecialchars($school_name) ?></h3>
        <h4><?= htmlspecialchars($title) ?></h4>
        <p>Tingkatan: <?= htmlspecialchars($tingkat_name) ?> | Tahun Ajaran: <?= htmlspecialchars($academic_year) ?><?php if ($filter_type !== 'semester'): ?> | Semester: <?= htmlspecialchars($active_semester) ?><?php endif; ?></p>
    </div>

    <?php if ($filter_type === 'daily'): ?>
        <table>
            <thead>
                <tr>
                    <th class="col-no">No</th>
                    <th class="col-nama">Nama Siswa</th>
                    <th width="80">Waktu</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1; foreach ($results as $row): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td class="text-left"><?= htmlspecialchars($row['nama_siswa']) ?></td>
                    <td><?= $row['jam_masuk'] ? date('H:i', strtotime($row['jam_masuk'])) : '-' ?></td>
                    <td><?= htmlspecialchars($row['keterangan'] ?? 'Belum Mengisi Kehadiran') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($filter_type === 'monthly'): ?>
        <table>
            <thead>
                <tr>
                    <th rowspan="2" class="col-no">No</th>
                    <th rowspan="2" class="col-nama">Nama Siswa</th>
                    <th colspan="31">Tanggal</th>
                    <th colspan="4">Total</th>
                </tr>
                <tr>
                    <?php for ($i = 1; $i <= 31; $i++) echo "<th class='col-tgl'>$i</th>"; ?>
                    <th class="col-stat">H</th><th class="col-stat">S</th><th class="col-stat">I</th><th class="col-stat">A</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1; foreach ($results as $row): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td class="text-left" style="white-space: nowrap;"><?= htmlspecialchars($row['nama_siswa']) ?></td>
                    <?php for ($i = 1; $i <= 31; $i++):
                        $val = $row['days'][$i] ?? '';
                        $style = ($val === 'L') ? 'color: red; font-weight: bold;' : '';
                    ?>
                        <td style="<?= $style ?>"><?= htmlspecialchars((string)$val) ?></td>
                    <?php endfor; ?>
                    <td><?= (int)$row['summary']['H'] ?></td><td><?= (int)$row['summary']['S'] ?></td><td><?= (int)$row['summary']['I'] ?></td><td><?= (int)$row['summary']['A'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($filter_type === 'semester'): ?>
        <table>
            <thead>
                <tr>
                    <th rowspan="2" class="col-no">No</th>
                    <th rowspan="2" class="col-nama">Nama Siswa</th>
                    <?php
                    $month_names_short = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                    for ($m = $m_start; $m <= $m_end; $m++) echo "<th colspan='4'>" . ($month_names_short[$m] ?? '') . "</th>";
                    ?>
                    <th colspan="4">Total</th>
                </tr>
                <tr>
                    <?php for ($m = $m_start; $m <= $m_end; $m++) echo "<th>H</th><th>S</th><th>I</th><th>A</th>"; ?>
                    <th>H</th><th>S</th><th>I</th><th>A</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1; foreach ($results as $row): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td class="text-left"><?= htmlspecialchars($row['nama_siswa']) ?></td>
                    <?php for ($m = $m_start; $m <= $m_end; $m++): ?>
                        <td><?= (int)($row['monthly'][$m]['H'] ?? 0) ?: '-' ?></td>
                        <td><?= (int)($row['monthly'][$m]['S'] ?? 0) ?: '-' ?></td>
                        <td><?= (int)($row['monthly'][$m]['I'] ?? 0) ?: '-' ?></td>
                        <td><?= (int)($row['monthly'][$m]['A'] ?? 0) ?: '-' ?></td>
                    <?php endfor; ?>
                    <td><?= (int)($row['summary']['H'] ?? 0) ?></td>
                    <td><?= (int)($row['summary']['S'] ?? 0) ?></td>
                    <td><?= (int)($row['summary']['I'] ?? 0) ?></td>
                    <td><?= (int)($row['summary']['A'] ?? 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($filter_type === 'student'): ?>
        <table style="width: 50%; margin: 0 auto;">
            <thead>
                <tr>
                    <th class="col-no">No</th>
                    <th width="150">Tanggal</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1; foreach ($results as $row): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td><?= date('d-m-Y', strtotime((string)$row['tanggal'])) ?></td>
                    <td><?= htmlspecialchars((string)$row['keterangan']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="signature-block">
    <div class="signature-wrapper">
        <div class="signature-box">
            <p><br>Pembina Pramuka,</p>
            <div class="signature-space">
                <?php if ($pembina_name !== ''): ?>
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?= urlencode("Validasi Tanda Tangan: $pembina_name") ?>" class="qr-code">
                <?php endif; ?>
            </div>
            <p><strong><?= htmlspecialchars($pembina_name) ?></strong></p>
        </div>
        <div class="signature-gap"></div>
        <div class="signature-box">
            <p><?= htmlspecialchars($school_city) ?>, <?= $report_date ?><br>Kepala Madrasah,</p>
            <div class="signature-space">
                <?php if ($madrasah_head_name !== ''): ?>
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?= urlencode("Validasi Tanda Tangan: $madrasah_head_name") ?>" class="qr-code">
                <?php endif; ?>
            </div>
            <p><strong><?= htmlspecialchars($madrasah_head_name) ?></strong><br>NIP. <?= htmlspecialchars($madrasah_head_nip) ?></p>
        </div>
    </div>
    </div>

    <script>
        window.onload = function () {
            setTimeout(function () { window.print(); }, 500);
        };
    </script>
</body>
</html>
