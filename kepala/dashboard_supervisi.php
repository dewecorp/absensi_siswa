<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$page_title = 'Dashboard Supervisi';
$current_page = basename(__FILE__);

$school_profile = getSchoolProfile($pdo);
$periode = sv_periode($pdo);
$tahun_ajaran = $periode['tahun_ajaran'];
$semester = $periode['semester'];

$filter_ta = trim((string)($_GET['tahun_ajaran'] ?? $tahun_ajaran));
$filter_sem = trim((string)($_GET['semester'] ?? $semester));

$stats = sv_hitung_statistik($pdo, ['tahun_ajaran' => $filter_ta, 'semester' => $filter_sem]);

$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'assets/js/supervisi.js',
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
];

$parts = explode('/', $filter_ta);
$ta_start = ($parts[0] ?? date('Y')) . '-07-01';
$ta_end = ($parts[1] ?? ((int)date('Y') + 1)) . '-06-30';

// Nilai per guru (top 10)
$nilai_per_guru = [];
try {
    $stmt = $pdo->prepare("SELECT nama_guru, AVG(nilai) AS rata, COUNT(*) AS jumlah
                           FROM tb_sv_pelaksanaan
                           WHERE status = 'Selesai' AND tanggal BETWEEN ? AND ?
                           GROUP BY nama_guru ORDER BY rata DESC LIMIT 10");
    $stmt->execute([$ta_start, $ta_end]);
    $nilai_per_guru = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

// Rekap per jenis
$per_jenis = ['Akademik' => 0, 'Administrasi' => 0, 'Manajerial' => 0];
try {
    $stmt = $pdo->prepare("SELECT jenis_supervisi, COUNT(*) AS jml FROM tb_sv_pelaksanaan
                           WHERE status = 'Selesai' AND tanggal BETWEEN ? AND ? GROUP BY jenis_supervisi");
    $stmt->execute([$ta_start, $ta_end]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $per_jenis[$r['jenis_supervisi']] = (int)$r['jml'];
    }
} catch (Throwable $e) {
}

// Temuan per jenis
$temuan_jenis = ['Akademik' => 0, 'Administrasi' => 0, 'Manajerial' => 0];
try {
    $stmt = $pdo->prepare("SELECT jenis_supervisi, COUNT(*) AS jml FROM tb_sv_pelaksanaan
                           WHERE temuan IS NOT NULL AND TRIM(temuan) <> '' AND tanggal BETWEEN ? AND ?
                           GROUP BY jenis_supervisi");
    $stmt->execute([$ta_start, $ta_end]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $temuan_jenis[$r['jenis_supervisi']] = (int)$r['jml'];
    }
} catch (Throwable $e) {
}

// Status tindak lanjut
$tl_status = [];
try {
    $stmt = $pdo->query("SELECT status, COUNT(*) AS jml FROM tb_sv_tindak_lanjut GROUP BY status");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tl_status[$r['status']] = (int)$r['jml'];
    }
} catch (Throwable $e) {
}

// Progres per bulan
$bulan_labels = [];
$bulan_data = [];
try {
    $stmt = $pdo->prepare("SELECT DATE_FORMAT(tanggal, '%Y-%m') AS bln, COUNT(*) AS jml
                           FROM tb_sv_pelaksanaan WHERE status = 'Selesai' AND tanggal BETWEEN ? AND ?
                           GROUP BY bln ORDER BY bln ASC");
    $stmt->execute([$ta_start, $ta_end]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $bulan_labels[] = date('M Y', strtotime($r['bln'] . '-01'));
        $bulan_data[] = (int)$r['jml'];
    }
} catch (Throwable $e) {
}

// Jadwal terdekat
$jadwal_terdekat = [];
try {
    $stmt = $pdo->prepare("SELECT j.*, i.nama_instrumen FROM tb_sv_jadwal j
                           LEFT JOIN tb_sv_instrumen i ON i.id_instrumen = j.id_instrumen
                           WHERE j.status = 'Terjadwal' AND j.tanggal >= CURDATE()
                           ORDER BY j.tanggal ASC, j.jam_mulai ASC LIMIT 5");
    $stmt->execute();
    $jadwal_terdekat = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

// Tindak lanjut jatuh tempo
$tl_jatuh_tempo = [];
try {
    $stmt = $pdo->query("SELECT * FROM tb_sv_tindak_lanjut
                         WHERE status <> 'Selesai' AND target_selesai IS NOT NULL
                           AND target_selesai <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
                         ORDER BY target_selesai ASC LIMIT 5");
    $tl_jatuh_tempo = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$js_page = [];
$js_page[] = "var svChart = {
    progres: {sudah: " . (int)$stats['sudah_diperiksa'] . ", belum: " . (int)$stats['belum_diperiksa'] . "},
    nilaiGuru: {labels: " . json_encode(array_column($nilai_per_guru, 'nama_guru')) . ", data: " . json_encode(array_map(function ($r) { return round((float)$r['rata'], 2); }, $nilai_per_guru)) . "},
    perJenis: {labels: " . json_encode(array_keys($per_jenis)) . ", data: " . json_encode(array_values($per_jenis)) . "},
    temuan: {labels: " . json_encode(array_keys($temuan_jenis)) . ", data: " . json_encode(array_values($temuan_jenis)) . "},
    tlStatus: {labels: " . json_encode(array_keys($tl_status)) . ", data: " . json_encode(array_values($tl_status)) . "},
    perBulan: {labels: " . json_encode($bulan_labels) . ", data: " . json_encode($bulan_data) . "}
};";
$js_page[] = <<<'JS'
$(document).ready(function () {
    SV.autoSubmitFilters('form');
    if (typeof Chart === 'undefined') { return; }
    var palette = ['#6777ef', '#47c363', '#ffa426', '#fc544b', '#3abaf4', '#63ed7a', '#ffc107', '#a55eea'];

    function makeDoughnut(id, labels, data) {
        var el = document.getElementById(id);
        if (!el) return;
        new Chart(el, {
            type: 'doughnut',
            data: { labels: labels, datasets: [{ data: data, backgroundColor: palette }] },
            options: { responsive: true, maintainAspectRatio: false, legend: { position: 'bottom' } }
        });
    }
    function makeBar(id, labels, data, horizontal) {
        var el = document.getElementById(id);
        if (!el) return;
        new Chart(el, {
            type: 'bar',
            data: { labels: labels, datasets: [{ label: 'Jumlah', data: data, backgroundColor: '#6777ef' }] },
            options: { responsive: true, maintainAspectRatio: false, indexAxis: horizontal ? 'y' : 'x', legend: { display: false }, scales: { y: { beginAtZero: true } } }
        });
    }
    function makeLine(id, labels, data) {
        var el = document.getElementById(id);
        if (!el) return;
        new Chart(el, {
            type: 'line',
            data: { labels: labels, datasets: [{ label: 'Supervisi Selesai', data: data, borderColor: '#47c363', backgroundColor: 'rgba(71,195,99,.15)', fill: true, tension: .3 }] },
            options: { responsive: true, maintainAspectRatio: false, legend: { display: false }, scales: { y: { beginAtZero: true } } }
        });
    }

    makeDoughnut('chartProgres', ['Sudah Disupervisi', 'Belum Disupervisi'], [svChart.progres.sudah, svChart.progres.belum]);
    makeBar('chartNilaiGuru', svChart.nilaiGuru.labels, svChart.nilaiGuru.data, true);
    makeDoughnut('chartPerJenis', svChart.perJenis.labels, svChart.perJenis.data);
    makeBar('chartTemuan', svChart.temuan.labels, svChart.temuan.data, false);
    makeDoughnut('chartTlStatus', svChart.tlStatus.labels, svChart.tlStatus.data);
    makeLine('chartPerBulan', svChart.perBulan.labels, svChart.perBulan.data);
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Dashboard Supervisi</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <input type="hidden" id="svSchoolName" value="<?= htmlspecialchars($school_profile['nama_madrasah'] ?? 'MADRASAH', ENT_QUOTES) ?>">
            <input type="hidden" id="svSchoolLogo" value="<?= !empty($school_profile['logo']) ? '../assets/img/' . htmlspecialchars($school_profile['logo'], ENT_QUOTES) : '' ?>">
            <input type="hidden" id="svAcademicYear" value="<?= htmlspecialchars($filter_ta, ENT_QUOTES) ?>">
            <input type="hidden" id="svSemester" value="<?= htmlspecialchars($filter_sem, ENT_QUOTES) ?>">
            <input type="hidden" id="svHeadName" value="<?= htmlspecialchars($school_profile['nama_kepala'] ?? '-', ENT_QUOTES) ?>">
            <input type="hidden" id="svHeadNip" value="<?= htmlspecialchars($school_profile['nip_kepala'] ?? '-', ENT_QUOTES) ?>">
            <input type="hidden" id="svPrintPlace" value="<?= htmlspecialchars($school_profile['tempat_jadwal'] ?? 'Padang', ENT_QUOTES) ?>">
            <input type="hidden" id="svPrintDate" value="<?= date('d F Y') ?>">

            <div class="card">
                <div class="card-body">
                    <form method="GET" class="form-row align-items-end">
                        <div class="form-group col-md-4 mb-2">
                            <label class="small font-weight-bold">Tahun Ajaran</label>
                            <select name="tahun_ajaran" class="form-control">
                                <?php foreach (sv_tahun_ajaran_options($pdo) as $ta): ?>
                                    <option value="<?= htmlspecialchars($ta, ENT_QUOTES) ?>" <?= $ta === $filter_ta ? 'selected' : '' ?>><?= htmlspecialchars($ta) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-4 mb-2">
                            <label class="small font-weight-bold">Semester</label>
                            <select name="semester" class="form-control">
                                <?php foreach (sv_semester_options() as $s): ?>
                                    <option value="<?= htmlspecialchars($s, ENT_QUOTES) ?>" <?= $s === $filter_sem ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-4 mb-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Terapkan</button>
                            <a href="dashboard_supervisi.php" class="btn btn-light">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="row">
                <?php
                $cards = [
                    ['Total Guru/PTK', $stats['total_guru'], 'fas fa-users', 'primary'],
                    ['Sasaran Supervisi', $stats['total_sasaran'], 'fas fa-bullseye', 'info'],
                    ['Sudah Disupervisi', $stats['sudah_diperiksa'], 'fas fa-check-circle', 'success'],
                    ['Belum Disupervisi', $stats['belum_diperiksa'], 'fas fa-hourglass-half', 'warning'],
                    ['Supervisi Terjadwal', $stats['terjadwal'], 'fas fa-calendar-alt', 'info'],
                    ['Supervisi Selesai', $stats['selesai'], 'fas fa-flag-checkered', 'success'],
                    ['Rata-rata Nilai', $stats['rata_nilai'], 'fas fa-star', 'primary'],
                    ['Jumlah Temuan', $stats['jumlah_temuan'], 'fas fa-exclamation-triangle', 'danger'],
                    ['Tindak Lanjut Belum Selesai', $stats['tl_belum'], 'fas fa-tasks', 'warning'],
                    ['Tindak Lanjut Selesai', $stats['tl_selesai'], 'fas fa-clipboard-check', 'success'],
                ];
                foreach ($cards as $c): ?>
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="card card-statistic-1">
                            <div class="card-icon bg-<?= $c[3] ?>">
                                <i class="<?= $c[2] ?>"></i>
                            </div>
                            <div class="card-wrap">
                                <div class="card-header"><h4><?= htmlspecialchars($c[0]) ?></h4></div>
                                <div class="card-body"><?= htmlspecialchars((string)$c[1]) ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="row">
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header"><h4>Progres Supervisi</h4></div>
                        <div class="card-body"><div style="height:280px;"><canvas id="chartProgres"></canvas></div></div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header"><h4>Nilai Supervisi per Guru</h4></div>
                        <div class="card-body"><div style="height:280px;"><canvas id="chartNilaiGuru"></canvas></div></div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header"><h4>Rekap Berdasarkan Jenis Supervisi</h4></div>
                        <div class="card-body"><div style="height:280px;"><canvas id="chartPerJenis"></canvas></div></div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header"><h4>Temuan Supervisi</h4></div>
                        <div class="card-body"><div style="height:280px;"><canvas id="chartTemuan"></canvas></div></div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header"><h4>Status Tindak Lanjut</h4></div>
                        <div class="card-body"><div style="height:280px;"><canvas id="chartTlStatus"></canvas></div></div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header"><h4>Progres Supervisi per Bulan</h4></div>
                        <div class="card-body"><div style="height:280px;"><canvas id="chartPerBulan"></canvas></div></div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h4>Jadwal Supervisi Terdekat</h4>
                            <div class="card-header-action"><a href="jadwal_supervisi.php" class="btn btn-sm btn-primary">Lihat Semua</a></div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped mb-0">
                                    <thead><tr><th>Tanggal</th><th>Guru/Unit</th><th>Jenis</th><th>Jam</th></tr></thead>
                                    <tbody>
                                    <?php if ($jadwal_terdekat): foreach ($jadwal_terdekat as $j): ?>
                                        <tr>
                                            <td><?= $j['tanggal'] ? date('d/m/Y', strtotime($j['tanggal'])) : '-' ?></td>
                                            <td><?= htmlspecialchars($j['nama_guru'] ?? '-') ?></td>
                                            <td><span class="badge badge-info"><?= htmlspecialchars($j['jenis_supervisi']) ?></span></td>
                                            <td><?= $j['jam_mulai'] ? substr($j['jam_mulai'], 0, 5) : '-' ?></td>
                                        </tr>
                                    <?php endforeach; else: ?>
                                        <tr><td colspan="4" class="text-center text-muted">Belum ada jadwal terdekat.</td></tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h4>Tindak Lanjut yang Jatuh Tempo</h4>
                            <div class="card-header-action"><a href="tindak_lanjut.php" class="btn btn-sm btn-warning">Kelola</a></div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped mb-0">
                                    <thead><tr><th>Guru/Unit</th><th>Bentuk</th><th>Target</th><th>Status</th></tr></thead>
                                    <tbody>
                                    <?php if ($tl_jatuh_tempo): foreach ($tl_jatuh_tempo as $tl): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($tl['nama_guru'] ?: ($tl['unit_bagian'] ?: '-')) ?></td>
                                            <td><?= htmlspecialchars($tl['bentuk_tindak_lanjut'] ?? '-') ?></td>
                                            <td><?= $tl['target_selesai'] ? date('d/m/Y', strtotime($tl['target_selesai'])) : '-' ?></td>
                                            <td><span class="badge badge-warning"><?= htmlspecialchars($tl['status']) ?></span></td>
                                        </tr>
                                    <?php endforeach; else: ?>
                                        <tr><td colspan="4" class="text-center text-muted">Tidak ada tindak lanjut jatuh tempo.</td></tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
<?php include '../templates/footer.php'; ?>
