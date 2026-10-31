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

$ekskul_list = ekstraKehadiranList($pdo, true);
$ekskul_id = isset($_POST['ekskul_id']) ? (int)$_POST['ekskul_id'] : (isset($_GET['ekskul']) ? (int)$_GET['ekskul'] : 0);
$valid_ids = array_map(static fn($e) => (int)$e['id_ekstrakurikuler'], $ekskul_list);
if ($ekskul_id <= 0 || !in_array($ekskul_id, $valid_ids, true)) {
    $ekskul_id = $valid_ids[0] ?? 0;
}
$ekskul = ekstraKehadiranResolve($pdo, $ekskul_id);
if (!$ekskul) {
    die('Belum ada data ekstrakurikuler. Tambahkan dulu di menu Data Ekstrakurikuler.');
}

$filter_type = $_POST['filter_type'] ?? 'daily';
if (!in_array($filter_type, ['daily', 'monthly', 'semester', 'student'], true)) {
    $filter_type = 'daily';
}
$selected_date = $_POST['attendance_date'] ?? date('Y-m-d');
$selected_month = $_POST['month_picker'] ?? date('Y-m');
$selected_student = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;

$school_profile = getSchoolProfile($pdo);
$active_semester = $school_profile['semester'] ?? 'Semester 1';
$academic_year = $school_profile['tahun_ajaran'] ?? (date('Y') . '/' . (date('Y') + 1));
$periode_ta = getRentangTanggalTahunAjaran($academic_year);
$month_names = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

$daily_results = [];
$monthly_results = [];
$semester_results = [];
$student_results = [];
$student_info = [];
$student_summary = ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0];
$holidays = [];
$year = (int)substr((string)$selected_month, 0, 4);
$month = (int)substr((string)$selected_month, 5, 2);
$month_label = $month_names[$month] ?? '';

$anggota = ekstraKehadiranAnggota($pdo, $ekskul_id);
$anggota_ids = array_map(static fn($r) => (int)$r['id_siswa'], $anggota);

if ($filter_type === 'daily' && $selected_date !== '') {
    $map = [];
    try {
        $st = $pdo->prepare("SELECT id_siswa, keterangan, jam_masuk FROM tb_kehadiran_ekstra WHERE id_ekstrakurikuler = ? AND tanggal = ?");
        $st->execute([$ekskul_id, $selected_date]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(int)$r['id_siswa']] = $r;
        }
    } catch (Throwable $e) {
        $map = [];
    }
    foreach ($anggota as $a) {
        $sid = (int)$a['id_siswa'];
        $daily_results[] = [
            'id_siswa' => $sid,
            'nama_siswa' => $a['nama_siswa'],
            'nama_kelas' => $a['nama_kelas'],
            'keterangan' => $map[$sid]['keterangan'] ?? 'Belum Mengisi Kehadiran',
            'jam_masuk' => $map[$sid]['jam_masuk'] ?? null,
        ];
    }
} elseif ($filter_type === 'monthly' && $selected_month !== '') {
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
    foreach ($anggota as $a) {
        $sid = (int)$a['id_siswa'];
        $summary = ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0];
        $days = array_fill(1, 31, '');
        foreach (($map[$sid] ?? []) as $d => $status) {
            $days[$d] = $status;
            if (!in_array((int)$d, $schedule_days, true)) {
                continue;
            }
            $key = $status === 'Terlambat' ? 'Hadir' : $status;
            if (isset($summary[$key])) {
                $summary[$key]++;
            }
        }
        $monthly_results[] = [
            'id_siswa' => $sid,
            'nama_siswa' => $a['nama_siswa'],
            'nama_kelas' => $a['nama_kelas'],
            'foto' => $a['foto'] ?? '',
            'days' => $days,
            'summary' => $summary,
        ];
    }
    $holidays = getHolidays($pdo, $year, $month);
} elseif ($filter_type === 'semester') {
    $years = explode('/', (string)$academic_year);
    $start_year = (int)($years[0] ?? date('Y'));
    $end_year = (int)($years[1] ?? (date('Y') + 1));
    if ($active_semester === 'Semester 1') {
        $query_year = $start_year;
        $start_month = 7;
        $end_month = 12;
    } else {
        $query_year = $end_year;
        $start_month = 1;
        $end_month = 6;
    }

    $map = [];
    try {
        $sql = "SELECT id_siswa, keterangan, MONTH(tanggal) AS month, COUNT(*) AS jumlah
            FROM tb_kehadiran_ekstra
            WHERE id_ekstrakurikuler = ? AND YEAR(tanggal) = ? AND MONTH(tanggal) BETWEEN ? AND ?
            GROUP BY id_siswa, keterangan, MONTH(tanggal)";
        $st = $pdo->prepare($sql);
        $st->execute([$ekskul_id, $query_year, $start_month, $end_month]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sid = (int)$r['id_siswa'];
            $m = (int)$r['month'];
            $key = $r['keterangan'] === 'Terlambat' ? 'Hadir' : (string)$r['keterangan'];
            if (!isset($map[$sid][$m])) {
                $map[$sid][$m] = ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0];
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
        $totals = [];
        $summary = ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0];
        for ($m = $start_month; $m <= $end_month; $m++) {
            $totals[$m] = $map[$sid][$m] ?? ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0];
            foreach ($totals[$m] as $k => $v) {
                $summary[$k] += (int)$v;
            }
        }
        $semester_results[] = [
            'id_siswa' => $sid,
            'nama_siswa' => $a['nama_siswa'],
            'nama_kelas' => $a['nama_kelas'],
            'monthly_totals' => $totals,
            'summary' => $summary,
        ];
    }
    $GLOBALS['semester_start_month'] = $start_month;
    $GLOBALS['semester_end_month'] = $end_month;
} elseif ($filter_type === 'student' && $selected_student > 0 && in_array($selected_student, $anggota_ids, true)) {
    foreach ($anggota as $a) {
        if ((int)$a['id_siswa'] === $selected_student) {
            $student_info = $a;
            break;
        }
    }
    try {
        $sql = "SELECT tanggal, keterangan FROM tb_kehadiran_ekstra WHERE id_ekstrakurikuler = ? AND id_siswa = ?";
        $bind = [$ekskul_id, $selected_student];
        if ($periode_ta) {
            $sql .= " AND tanggal >= ? AND tanggal <= ?";
            $bind[] = $periode_ta['mulai'];
            $bind[] = $periode_ta['sampai'];
        }
        $sql .= " ORDER BY tanggal DESC";
        $st = $pdo->prepare($sql);
        $st->execute($bind);
        $student_results = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $student_results = [];
    }
    foreach ($student_results as $r) {
        $key = $r['keterangan'] === 'Terlambat' ? 'Hadir' : (string)$r['keterangan'];
        if (isset($student_summary[$key])) {
            $student_summary[$key]++;
        }
    }
}

$page_title = 'Rekap Kehadiran ' . $ekskul['nama_ekstrakurikuler'];

$css_libs = [
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.full.min.js',
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$ekskul_id_js = (int)$ekskul_id;
$print_params = [
    'type' => $filter_type,
    'ekskul' => $ekskul_id,
    'date' => $selected_date,
    'month' => $selected_month,
    'student_id' => $selected_student,
];
$print_url = 'cetak_rekap_kehadiran_ekstra.php?' . http_build_query($print_params);

$js_page = [];
$js_page[] = "
function toggleEkstraFilterFields() {
    var v = $('#filterType').val();
    $('.daily-filter').toggle(v === 'daily');
    $('.monthly-filter').toggle(v === 'monthly');
    $('.student-filter').toggle(v === 'student');
}
function openCetakRekapEkstra() {
    window.open('" . addslashes($print_url) . "', '_blank', 'noopener');
}
$(document).ready(function () {
    toggleEkstraFilterFields();
    $('#filterType').on('change', function () {
        toggleEkstraFilterFields();
        var form = document.getElementById('ekstraFilterForm');
        if (form) {
            if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
        }
    });
    if ($.fn.select2) {
        $('#studentSelect').select2({ placeholder: 'Pilih Siswa...', allowClear: true, width: '100%' });
    }
    if ($.fn.DataTable && $('#dailyTable').length) {
        $('#dailyTable').DataTable({ paging: true, pageLength: 25, lengthMenu: [[10, 25, 50, -1], [10, 25, 50, 'Semua']], order: [], language: { search: 'Cari:', lengthMenu: 'Tampilkan _MENU_ entri', info: 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri', infoEmpty: 'Menampilkan 0 entri', zeroRecords: 'Tidak ada data', paginate: { first: 'Awal', last: 'Akhir', next: 'Berikutnya', previous: 'Sebelumnya' } } });
    }
    if ($.fn.DataTable && $('#studentTable').length) {
        $('#studentTable').DataTable({ paging: true, pageLength: 25, lengthMenu: [[10, 25, 50, -1], [10, 25, 50, 'Semua']], order: [], language: { search: 'Cari:', lengthMenu: 'Tampilkan _MENU_ entri', info: 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri', infoEmpty: 'Menampilkan 0 entri', zeroRecords: 'Tidak ada data', paginate: { first: 'Awal', last: 'Akhir', next: 'Berikutnya', previous: 'Sebelumnya' } } });
    }
});
";

$_GET['ekskul'] = $ekskul_id;

include '../templates/header.php';
?>

<style>
#semesterTable { font-size: 10pt; color: black; }
#semesterTable thead th { text-align: center; vertical-align: middle; background-color: #f8f9fa; font-weight: bold; color: black; }
#semesterTable tbody td { color: black; white-space: nowrap; }
#semesterTable tbody td:nth-child(2) { white-space: normal; text-align: left; }
.rekap-mobile-list { display: none; }
@media (max-width: 767.98px) {
    .rekap-table-wrap { display: none !important; }
    .rekap-mobile-list { display: block !important; }
}
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Rekap Kehadiran <?php echo htmlspecialchars($ekskul['nama_ekstrakurikuler']); ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Filter Rekap Kehadiran</h4>
                            <div class="card-header-action">
                                <button type="button" class="btn btn-warning" onclick="openCetakRekapEkstra()"><i class="fas fa-file-pdf"></i> Ekspor PDF</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="" id="ekstraFilterForm">
                                <input type="hidden" name="ekskul_id" value="<?php echo (int)$ekskul_id; ?>">
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label>Ekstrakurikuler</label>
                                            <select name="ekskul_id" class="form-control" onchange="this.form.submit()">
                                                <?php foreach ($ekskul_list as $e): ?>
                                                    <option value="<?php echo (int)$e['id_ekstrakurikuler']; ?>" <?php echo $ekskul_id === (int)$e['id_ekstrakurikuler'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($e['nama_ekstrakurikuler']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="form-group">
                                            <label>Jenis Filter</label>
                                            <select name="filter_type" class="form-control" id="filterType">
                                                <option value="daily" <?php echo $filter_type === 'daily' ? 'selected' : ''; ?>>Harian</option>
                                                <option value="monthly" <?php echo $filter_type === 'monthly' ? 'selected' : ''; ?>>Bulanan</option>
                                                <option value="semester" <?php echo $filter_type === 'semester' ? 'selected' : ''; ?>>Per Semester</option>
                                                <option value="student" <?php echo $filter_type === 'student' ? 'selected' : ''; ?>>Per Siswa</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3 daily-filter" style="<?php echo $filter_type === 'daily' ? '' : 'display:none;'; ?>">
                                        <div class="form-group">
                                            <label>Pilih Tanggal</label>
                                            <input type="date" name="attendance_date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>" onchange="this.form.submit()">
                                        </div>
                                    </div>
                                    <div class="col-md-3 monthly-filter" style="<?php echo $filter_type === 'monthly' ? '' : 'display:none;'; ?>">
                                        <div class="form-group">
                                            <label>Pilih Bulan</label>
                                            <input type="month" name="month_picker" class="form-control" value="<?php echo htmlspecialchars($selected_month); ?>" onchange="this.form.submit()">
                                        </div>
                                    </div>
                                    <div class="col-md-4 student-filter" style="<?php echo $filter_type === 'student' ? '' : 'display:none;'; ?>">
                                        <div class="form-group">
                                            <label>Pilih Siswa</label>
                                            <select name="student_id" class="form-control" id="studentSelect" onchange="this.form.submit()">
                                                <option value="">Pilih Siswa...</option>
                                                <?php foreach ($anggota as $a): ?>
                                                    <option value="<?php echo (int)$a['id_siswa']; ?>" <?php echo $selected_student === (int)$a['id_siswa'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($a['nama_siswa']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </form>

                            <?php if ($filter_type === 'daily'): ?>
                                <div class="alert alert-info alert-has-icon">
                                    <div class="alert-icon"><i class="far fa-calendar-check"></i></div>
                                    <div class="alert-body">
                                        <div class="alert-title">Kehadiran Harian</div>
                                        <?php echo formatDateIndonesia($selected_date); ?> · <?php echo count($daily_results); ?> siswa
                                    </div>
                                </div>
                                <div class="table-responsive rekap-table-wrap">
                                    <table class="table table-bordered table-striped" id="dailyTable">
                                        <thead>
                                            <tr><th>No</th><th>Nama Siswa</th><th>Kelas</th><th>Waktu</th><th>Status</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($daily_results as $i => $r): ?>
                                                <tr>
                                                    <td><?php echo $i + 1; ?></td>
                                                    <td class="text-left"><?php echo htmlspecialchars((string)$r['nama_siswa']); ?></td>
                                                    <td><?php echo htmlspecialchars((string)($r['nama_kelas'] ?? '')); ?></td>
                                                    <td><?php echo !empty($r['jam_masuk']) ? date('H:i', strtotime((string)$r['jam_masuk'])) : '-'; ?></td>
                                                    <td><?php echo htmlspecialchars((string)$r['keterangan']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="att-rekap-timeline rekap-mobile-list">
                                    <?php $no_mobile = 1; foreach ($daily_results as $r):
                                        $status_text = (string)($r['keterangan'] ?? 'Belum Mengisi Kehadiran');
                                        $status_class = 'badge-secondary';
                                        $tone_color = '#64748b';
                                        switch ($status_text) {
                                            case 'Hadir': $status_class = 'badge-success'; $tone_color = '#10b981'; break;
                                            case 'Sakit': $status_class = 'badge-warning'; $tone_color = '#f59e0b'; break;
                                            case 'Izin': $status_class = 'badge-info'; $tone_color = '#0ea5e9'; break;
                                            case 'Alpa':
                                            case 'Berhalangan': $status_class = 'badge-danger'; $tone_color = '#ef4444'; break;
                                        }
                                        $jam_mobile = !empty($r['jam_masuk']) ? date('H:i', strtotime((string)$r['jam_masuk'])) : '-';
                                    ?>
                                        <div class="att-rekap-item" style="--att-tone-color: <?= $tone_color ?>;">
                                            <div class="att-rekap-num">
                                                <span class="seq"><?= $no_mobile++ ?></span>
                                                <span class="sub"><?= htmlspecialchars((string)($r['nama_kelas'] ?? '')) ?></span>
                                            </div>
                                            <div class="att-rekap-card">
                                                <div class="att-rekap-main">
                                                    <div class="att-rekap-name"><?= htmlspecialchars((string)$r['nama_siswa']) ?></div>
                                                    <div class="att-rekap-meta">
                                                        <span><i class="far fa-clock"></i> <?= $jam_mobile ?></span>
                                                        <?php if (!empty($r['nama_kelas'])): ?>
                                                            <span><i class="fas fa-school"></i> <?= htmlspecialchars((string)$r['nama_kelas']) ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="att-rekap-badge">
                                                    <span class="badge <?= $status_class ?>"><?= htmlspecialchars($status_text) ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif ($filter_type === 'monthly'): ?>
                                <div class="alert alert-info alert-has-icon">
                                    <div class="alert-icon"><i class="far fa-calendar-alt"></i></div>
                                    <div class="alert-body">
                                        <div class="alert-title">Kehadiran Bulanan</div>
                                        <?php echo htmlspecialchars($month_label . ' ' . $year); ?> · <?php echo count($monthly_results); ?> siswa
                                    </div>
                                </div>
                                <div class="att-monthly-grid">
                                    <?php foreach ($monthly_results as $i => $r): ?>
                                        <div class="att-monthly-card">
                                            <div class="att-monthly-head">
                                                <div class="att-monthly-avatar"><?= getPersonAvatarHtml((string)($r['foto'] ?? ''), (string)($i + 1), 'att-monthly-avatar-img') ?></div>
                                                <div class="att-monthly-title">
                                                    <div class="att-monthly-name"><?= htmlspecialchars((string)$r['nama_siswa']) ?></div>
                                                    <div class="att-monthly-sub">
                                                        <i class="far fa-calendar-alt"></i>
                                                        <?= htmlspecialchars($month_label . ' ' . $year) ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="att-monthly-stats">
                                                <div class="att-monthly-stat-box stat-hadir">
                                                    <div class="att-monthly-stat-val"><?= (int)($r['summary']['Hadir'] ?? 0) ?></div>
                                                    <div class="att-monthly-stat-lbl">Hadir</div>
                                                </div>
                                                <div class="att-monthly-stat-box stat-sakit">
                                                    <div class="att-monthly-stat-val"><?= (int)($r['summary']['Sakit'] ?? 0) ?></div>
                                                    <div class="att-monthly-stat-lbl">Sakit</div>
                                                </div>
                                                <div class="att-monthly-stat-box stat-izin">
                                                    <div class="att-monthly-stat-val"><?= (int)($r['summary']['Izin'] ?? 0) ?></div>
                                                    <div class="att-monthly-stat-lbl">Izin</div>
                                                </div>
                                                <div class="att-monthly-stat-box stat-alpa">
                                                    <div class="att-monthly-stat-val"><?= (int)($r['summary']['Alpa'] ?? 0) ?></div>
                                                    <div class="att-monthly-stat-lbl">Alpa</div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif ($filter_type === 'semester'): ?>
                                <?php $start_month = (int)($GLOBALS['semester_start_month'] ?? 7); $end_month = (int)($GLOBALS['semester_end_month'] ?? 12); ?>
                                <div class="alert alert-info alert-has-icon">
                                    <div class="alert-icon"><i class="far fa-check-circle"></i></div>
                                    <div class="alert-body">
                                        <div class="alert-title">Rekap Per Semester</div>
                                        <?php echo htmlspecialchars($active_semester); ?> · <?php echo htmlspecialchars($academic_year); ?> · <?php echo count($semester_results); ?> siswa
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-bordered" id="semesterTable" style="min-width: 1200px;">
                                        <thead>
                                            <tr>
                                                <th rowspan="2">No</th>
                                                <th rowspan="2">Nama Siswa</th>
                                                <?php for ($m = $start_month; $m <= $end_month; $m++): ?><th colspan="4"><?php echo htmlspecialchars($month_names[$m] ?? ''); ?></th><?php endfor; ?>
                                                <th colspan="4">Total</th>
                                            </tr>
                                            <tr>
                                                <?php for ($m = $start_month; $m <= $end_month; $m++): ?><th>H</th><th>S</th><th>I</th><th>A</th><?php endfor; ?>
                                                <th>H</th><th>S</th><th>I</th><th>A</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($semester_results as $i => $r): ?>
                                                <tr>
                                                    <td><?php echo $i + 1; ?></td>
                                                    <td class="text-left"><?php echo htmlspecialchars((string)$r['nama_siswa']); ?></td>
                                                    <?php for ($m = $start_month; $m <= $end_month; $m++): ?>
                                                        <td><?php echo (int)($r['monthly_totals'][$m]['Hadir'] ?? 0) ?: '-'; ?></td>
                                                        <td><?php echo (int)($r['monthly_totals'][$m]['Sakit'] ?? 0) ?: '-'; ?></td>
                                                        <td><?php echo (int)($r['monthly_totals'][$m]['Izin'] ?? 0) ?: '-'; ?></td>
                                                        <td><?php echo (int)($r['monthly_totals'][$m]['Alpa'] ?? 0) ?: '-'; ?></td>
                                                    <?php endfor; ?>
                                                    <td><?php echo (int)$r['summary']['Hadir']; ?></td>
                                                    <td><?php echo (int)$r['summary']['Sakit']; ?></td>
                                                    <td><?php echo (int)$r['summary']['Izin']; ?></td>
                                                    <td><?php echo (int)$r['summary']['Alpa']; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php elseif ($filter_type === 'student'): ?>
                                <?php if (!empty($student_info)): ?>
                                    <div class="alert alert-info alert-has-icon">
                                        <div class="alert-icon"><i class="far fa-user"></i></div>
                                        <div class="alert-body">
                                            <div class="alert-title"><?php echo htmlspecialchars((string)$student_info['nama_siswa']); ?></div>
                                            <?php echo htmlspecialchars((string)($student_info['nama_kelas'] ?? '')); ?>
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <?php foreach ([['Hadir', 'bg-success'], ['Sakit', 'bg-warning'], ['Izin', 'bg-info'], ['Alpa', 'bg-danger']] as $c): ?>
                                            <div class="col-6 col-md-3">
                                                <div class="card card-statistic-1">
                                                    <div class="card-icon <?php echo $c[1]; ?>"><i class="fas fa-circle"></i></div>
                                                    <div class="card-wrap">
                                                        <div class="card-header"><h4><?php echo $c[0]; ?></h4></div>
                                                        <div class="card-body"><?php echo (int)($student_summary[$c[0]] ?? 0); ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped" id="studentTable">
                                            <thead><tr><th>No</th><th>Tanggal</th><th>Status</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($student_results as $i => $r): ?>
                                                    <tr>
                                                        <td><?php echo $i + 1; ?></td>
                                                        <td><?php echo date('d-m-Y', strtotime((string)$r['tanggal'])); ?></td>
                                                        <td><?php echo htmlspecialchars((string)$r['keterangan']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-warning">Pilih siswa terlebih dahulu.</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
