<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/ekstra_kehadiran.php';

if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha', 'guru', 'wali'])) {
    redirect('../login.php');
}

try {
    ekstraKehadiranEnsureSchema($pdo);
} catch (Throwable $e) {
}

$filter_type = $_GET['type'] ?? 'daily';
if (!in_array($filter_type, ['daily', 'monthly', 'semester', 'student'], true)) {
    $filter_type = 'daily';
}
$ekskul_id = isset($_GET['ekskul']) ? (int)$_GET['ekskul'] : 0;
$attendance_date = $_GET['date'] ?? date('Y-m-d');
$month_picker = $_GET['month'] ?? date('Y-m');
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

$ekskul = ekstraKehadiranResolve($pdo, $ekskul_id);
if (!$ekskul) {
    die('Error: Ekstrakurikuler tidak ditemukan.');
}
$ekskul_name = $ekskul['nama_ekstrakurikuler'];

$school_profile = getSchoolProfile($pdo);
$school_name = $school_profile['nama_madrasah'] ?? 'Madrasah Ibtidaiyah';
$school_city = $school_profile['tempat_jadwal'] ?? 'Sukosono';
$active_semester = $school_profile['semester'] ?? 'Semester 1';
$academic_year = $school_profile['tahun_ajaran'] ?? (date('Y') . '/' . (date('Y') + 1));
$madrasah_head_name = $school_profile['kepala_madrasah'] ?? '';
$madrasah_head_nip = $school_profile['nip_kepala'] ?? '-';
$report_date = formatDateIndonesia(date('Y-m-d'));
$periode_ta = getRentangTanggalTahunAjaran($academic_year);

$pembina_name = '';
try {
    $st = $pdo->prepare("SELECT pb.nama_pembina
        FROM tb_pembina_ekstrakurikuler pe
        JOIN tb_pembina pb ON pb.id_pembina = pe.id_pembina
        WHERE pe.id_ekstrakurikuler = ?
        ORDER BY pe.id_pembina_ekstrakurikuler ASC
        LIMIT 1");
    $st->execute([$ekskul_id]);
    $pembina_name = (string)($st->fetchColumn() ?: '');
} catch (Throwable $e) {
}
if ($pembina_name === '') {
    $pembina_name = (string)($_SESSION['nama_guru'] ?? ($_SESSION['username'] ?? 'Pembina Ekstrakurikuler'));
}

$anggota = ekstraKehadiranAnggota($pdo, $ekskul_id);
$results = [];
$title = '';
$m_start = 0;
$m_end = 0;
$schedule_days = [];
$month_names = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

if ($filter_type === 'daily') {
    $title = 'Rekap Kehadiran ' . $ekskul_name . ' Harian - Tanggal: ' . formatDateIndonesia($attendance_date);
    $map = [];
    try {
        $st = $pdo->prepare("SELECT id_siswa, keterangan, jam_masuk FROM tb_kehadiran_ekstra WHERE id_ekstrakurikuler = ? AND tanggal = ?");
        $st->execute([$ekskul_id, $attendance_date]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(int)$r['id_siswa']] = $r;
        }
    } catch (Throwable $e) {
        $map = [];
    }
    foreach ($anggota as $a) {
        $sid = (int)$a['id_siswa'];
        $results[] = [
            'nama_siswa' => $a['nama_siswa'],
            'nama_kelas' => $a['nama_kelas'] ?? '',
            'keterangan' => $map[$sid]['keterangan'] ?? 'Belum Mengisi Kehadiran',
            'jam_masuk' => $map[$sid]['jam_masuk'] ?? null,
        ];
    }
} elseif ($filter_type === 'monthly') {
    if (!preg_match('/^\d{4}-\d{2}$/', (string)$month_picker)) {
        $month_picker = date('Y-m');
    }
    $year = (int)substr($month_picker, 0, 4);
    $month = (int)substr($month_picker, 5, 2);
    $title = 'Rekap Kehadiran ' . $ekskul_name . ' Bulanan - ' . ($month_names[$month] ?? '') . ' ' . $year;
    $schedule_days = ekstraKehadiranScheduleDates($ekskul, $year, $month);
    $map = [];
    try {
        $sql = "SELECT id_siswa, keterangan, DAY(tanggal) AS day FROM tb_kehadiran_ekstra
            WHERE id_ekstrakurikuler = ? AND YEAR(tanggal) = ? AND MONTH(tanggal) = ?";
        $bind = [$ekskul_id, $year, $month];
        if ($periode_ta) {
            $sql .= " AND tanggal >= ? AND tanggal <= ?";
            $bind[] = $periode_ta['mulai'];
            $bind[] = $periode_ta['sampai'];
        }
        $st = $pdo->prepare($sql);
        $st->execute($bind);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(int)$r['id_siswa']][(int)$r['day']] = (string)$r['keterangan'];
        }
    } catch (Throwable $e) {
        $map = [];
    }
    $holidays = getHolidays($pdo, $year, $month);
    foreach ($anggota as $a) {
        $sid = (int)$a['id_siswa'];
        $days = [];
        $summary = ['H' => 0, 'S' => 0, 'I' => 0, 'A' => 0];
        foreach ($schedule_days as $i) {
            $date_str = sprintf('%04d-%02d-%02d', $year, $month, $i);
            $status = $map[$sid][$i] ?? '';
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
            $days[$i] = $display;
        }
        $results[] = [
            'nama_siswa' => $a['nama_siswa'],
            'nama_kelas' => $a['nama_kelas'] ?? '',
            'days' => $days,
            'summary' => $summary,
        ];
    }
} elseif ($filter_type === 'semester') {
    $title = 'Rekap Kehadiran ' . $ekskul_name . ' ' . $active_semester;
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

    $map = [];
    try {
        $st = $pdo->prepare("SELECT id_siswa, keterangan, MONTH(tanggal) AS month, COUNT(*) AS jumlah
            FROM tb_kehadiran_ekstra
            WHERE id_ekstrakurikuler = ? AND YEAR(tanggal) = ? AND MONTH(tanggal) BETWEEN ? AND ?
            GROUP BY id_siswa, keterangan, MONTH(tanggal)");
        $st->execute([$ekskul_id, $query_year, $m_start, $m_end]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sid = (int)$r['id_siswa'];
            $m = (int)$r['month'];
            $key = $r['keterangan'] === 'Terlambat' ? 'H' : (string)$r['keterangan'];
            if (!isset($map[$sid][$m])) {
                $map[$sid][$m] = ['H' => 0, 'S' => 0, 'I' => 0, 'A' => 0];
            }
            if (isset($map[$sid][$m][$key])) {
                $map[$sid][$m][$key] += (int)$r['jumlah'];
            }
        }
    } catch (Throwable $e) {
        $map = [];
    }

    foreach ($anggota as $a) {
        $sid = (int)$a['id_siswa'];
        $monthly = [];
        $summary = ['H' => 0, 'S' => 0, 'I' => 0, 'A' => 0];
        for ($m = $m_start; $m <= $m_end; $m++) {
            $monthly[$m] = $map[$sid][$m] ?? ['H' => 0, 'S' => 0, 'I' => 0, 'A' => 0];
            foreach ($monthly[$m] as $k => $v) {
                $summary[$k] += (int)$v;
            }
        }
        $results[] = [
            'nama_siswa' => $a['nama_siswa'],
            'nama_kelas' => $a['nama_kelas'] ?? '',
            'monthly' => $monthly,
            'summary' => $summary,
        ];
    }
} elseif ($filter_type === 'student') {
    $student_info = null;
    foreach ($anggota as $a) {
        if ((int)$a['id_siswa'] === $student_id) {
            $student_info = $a;
            break;
        }
    }
    if (!$student_info) {
        die('Error: Data siswa tidak ditemukan atau sudah tidak aktif.');
    }
    $title = 'Rekap Kehadiran ' . $ekskul_name . ': ' . ($student_info['nama_siswa'] ?? '');
    try {
        $sql = "SELECT tanggal, keterangan FROM tb_kehadiran_ekstra WHERE id_ekstrakurikuler = ? AND id_siswa = ?";
        $bind = [$ekskul_id, $student_id];
        if ($periode_ta) {
            $sql .= " AND tanggal >= ? AND tanggal <= ?";
            $bind[] = $periode_ta['mulai'];
            $bind[] = $periode_ta['sampai'];
        }
        $sql .= " ORDER BY tanggal DESC";
        $st = $pdo->prepare($sql);
        $st->execute($bind);
        $results = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $results = [];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($title) ?></title>
    <style>
        @page { size: <?= $filter_type === 'monthly' ? '210mm 330mm' : '330mm 210mm' ?>; margin: 0cm 0.5cm 0.2cm 0.5cm; }
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
        <p>Ekstrakurikuler: <?= htmlspecialchars($ekskul_name) ?> | Tahun Ajaran: <?= htmlspecialchars($academic_year) ?><?php if ($filter_type !== 'semester'): ?> | Semester: <?= htmlspecialchars($active_semester) ?><?php endif; ?></p>
    </div>

    <?php if ($filter_type === 'daily'): ?>
        <table>
            <thead>
                <tr>
                    <th class="col-no">No</th>
                    <th class="col-nama">Nama Siswa</th>
                    <th width="100">Kelas</th>
                    <th width="80">Waktu</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1; foreach ($results as $row): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td class="text-left"><?= htmlspecialchars((string)$row['nama_siswa']) ?></td>
                    <td><?= htmlspecialchars((string)($row['nama_kelas'] ?? '')) ?></td>
                    <td><?= !empty($row['jam_masuk']) ? date('H:i', strtotime((string)$row['jam_masuk'])) : '-' ?></td>
                    <td><?= htmlspecialchars((string)($row['keterangan'] ?? 'Belum Mengisi Kehadiran')) ?></td>
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
                    <th colspan="<?= count($schedule_days) ?>">Tanggal Latihan</th>
                    <th colspan="4">Total</th>
                </tr>
                <tr>
                    <?php foreach ($schedule_days as $day): ?><th class="col-tgl"><?= $day ?></th><?php endforeach; ?>
                    <th class="col-stat">H</th><th class="col-stat">S</th><th class="col-stat">I</th><th class="col-stat">A</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1; foreach ($results as $row): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td class="text-left" style="white-space: nowrap;"><?= htmlspecialchars((string)$row['nama_siswa']) ?></td>
                    <?php foreach ($schedule_days as $day):
                        $val = $row['days'][$day] ?? '';
                        $style = ($val === 'L') ? 'color: red; font-weight: bold;' : '';
                    ?>
                        <td style="<?= $style ?>"><?= htmlspecialchars((string)$val) ?></td>
                    <?php endforeach; ?>
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
                    <td class="text-left"><?= htmlspecialchars((string)$row['nama_siswa']) ?></td>
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
        <table style="width: 60%; margin: 0 auto;">
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
            <p><br>Pembina Ekstrakurikuler,</p>
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
