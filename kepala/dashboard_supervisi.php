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

    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
];

$parts = explode('/', $filter_ta);
$ta_start = ($parts[0] ?? date('Y')) . '-07-01';
$ta_end = ($parts[1] ?? ((int)date('Y') + 1)) . '-06-30';

// Nilai per guru (top 10) -- tidak dipakai lagi (diganti timeline progres)

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

// Tahap supervisi untuk timeline progres
$sv_prog_count = 0;
$sv_mon_count = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tb_sv_program WHERE tahun_ajaran = ? AND semester = ?");
    $stmt->execute([$filter_ta, $filter_sem]);
    $sv_prog_count = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
}
try {
    $sv_mon_count = (int)$pdo->query("SELECT COUNT(*) FROM tb_sv_monitoring")->fetchColumn();
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

$js_page = [];
$js_page[] = "var svChart = {
    progres: {sudah: " . (int)$stats['sudah_diperiksa'] . ", belum: " . (int)$stats['belum_diperiksa'] . "},
    perJenis: {labels: " . json_encode(array_keys($per_jenis)) . ", data: " . json_encode(array_values($per_jenis)) . "},
    tlStatus: {labels: " . json_encode(array_keys($tl_status)) . ", data: " . json_encode(array_values($tl_status)) . "},
    perBulan: {labels: " . json_encode($bulan_labels) . ", data: " . json_encode($bulan_data) . "}
};";
$js_page[] = <<<'JS'
$(document).ready(function () {
    document.querySelectorAll('form[method="GET"]').forEach(function(f){f.querySelectorAll('select, input[type="date"]').forEach(function(el){el.addEventListener('change',function(){f.submit();});});});
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
    makeDoughnut('chartPerJenis', svChart.perJenis.labels, svChart.perJenis.data);
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
                        <?php
                        $sv_persen_sudah = ((int)$stats['sudah_diperiksa'] + (int)$stats['belum_diperiksa']) > 0
                            ? round((int)$stats['sudah_diperiksa'] / ((int)$stats['sudah_diperiksa'] + (int)$stats['belum_diperiksa']) * 100)
                            : 0;
                        ?>
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4>Persentase Supervisi</h4>
                            <span class="badge badge-success font-weight-bold" style="font-size: 13px; padding: 6px 14px; border-radius: 20px;"><?= $sv_persen_sudah ?>%</span>
                        </div>
                        <div class="card-body"><div style="height:280px;"><canvas id="chartProgres"></canvas></div></div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header"><h4>Progres Supervisi</h4></div>
                        <div class="card-body">
                            <div class="sv-scroll" style="height: 280px; overflow-y: auto; overflow-x: hidden; padding-right: 6px; scroll-behavior: smooth; overscroll-behavior: contain; scrollbar-width: thin; scrollbar-color: #b9c4d8 transparent;">
                            <?php
                            $sv_tl_total = (int)$stats['tl_belum'] + (int)$stats['tl_selesai'];
                            $sv_stages = [
                                [
                                    'label' => 'Program Supervisi', 'desc' => $sv_prog_count . ' program tersusun', 'url' => 'program_supervisi.php',
                                    'icon' => 'fas fa-clipboard-list', 'color' => '#6777ef',
                                    'state' => $sv_prog_count > 0 ? 'done' : 'wait',
                                ],
                                [
                                    'label' => 'Sasaran Guru / PTK', 'desc' => (int)$stats['total_sasaran'] . ' sasaran ditetapkan', 'url' => 'sasaran_supervisi.php',
                                    'icon' => 'fas fa-bullseye', 'color' => '#3abaf4',
                                    'state' => (int)$stats['total_sasaran'] > 0 ? 'done' : ($sv_prog_count > 0 ? 'run' : 'wait'),
                                ],
                                [
                                    'label' => 'Penjadwalan', 'desc' => (int)$stats['terjadwal'] . ' jadwal terjadwal', 'url' => 'jadwal_supervisi.php',
                                    'icon' => 'fas fa-calendar-alt', 'color' => '#ffa426',
                                    'state' => (int)$stats['selesai'] > 0 ? 'done' : ((int)$stats['terjadwal'] > 0 ? 'run' : ((int)$stats['total_sasaran'] > 0 ? 'wait' : 'wait')),
                                ],
                                [
                                    'label' => 'Pelaksanaan & Penilaian', 'desc' => (int)$stats['selesai'] . ' selesai, ' . (int)$stats['belum_diperiksa'] . ' guru belum disupervisi', 'url' => 'supervisi_akademik.php',
                                    'icon' => 'fas fa-chalkboard-teacher', 'color' => '#47c363',
                                    'state' => ((int)$stats['selesai'] > 0 && (int)$stats['belum_diperiksa'] <= 0) ? 'done' : ((int)$stats['selesai'] > 0 || (int)$stats['terjadwal'] > 0 ? 'run' : 'wait'),
                                ],
                                [
                                    'label' => 'Tindak Lanjut', 'desc' => (int)$stats['tl_selesai'] . ' selesai dari ' . $sv_tl_total . ' temuan', 'url' => 'monitoring_tindak_lanjut.php',
                                    'icon' => 'fas fa-tasks', 'color' => '#9467ef',
                                    'state' => ($sv_tl_total > 0 && (int)$stats['tl_belum'] <= 0) ? 'done' : ($sv_tl_total > 0 ? 'run' : 'wait'),
                                ],
                                [
                                    'label' => 'Monitoring', 'desc' => $sv_mon_count . ' kunjungan monitoring', 'url' => 'data_monitoring.php',
                                    'icon' => 'fas fa-search', 'color' => '#20c997',
                                    'state' => $sv_mon_count > 0 ? 'run' : 'wait',
                                ],
                            ];
                            $sv_done_n = 0;
                            foreach ($sv_stages as $sg) { if ($sg['state'] === 'done') $sv_done_n++; }
                            $sv_run_n = 0;
                            foreach ($sv_stages as $sg) { if ($sg['state'] === 'run') $sv_run_n++; }
                            $sv_pct = round($sv_done_n / count($sv_stages) * 100);
                            ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between small text-muted mb-1">
                                    <span><strong class="text-dark"><?= $sv_done_n ?>/<?= count($sv_stages) ?></strong> tahap selesai<?= $sv_run_n > 0 ? ', <strong class="text-primary">' . $sv_run_n . '</strong> berjalan' : '' ?></span>
                                    <span class="font-weight-bold text-success"><?= $sv_pct ?>%</span>
                                </div>
                                <div class="progress" style="height: 10px; border-radius: 10px;">
                                    <div class="progress-bar bg-success" role="progressbar" style="width: <?= $sv_pct ?>%;" aria-valuenow="<?= $sv_pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                            </div>
                            <style>
                            .sv-timeline { position: relative; padding-left: 26px; }
                            .sv-timeline::before { content: ''; position: absolute; left: 11px; top: 8px; bottom: 8px; width: 3px; border-radius: 3px; background: #e9edf5; }
                            .sv-stage { position: relative; padding-bottom: 14px; }
                            .sv-stage:last-child { padding-bottom: 2px; }
                            .sv-dot { position: absolute; left: -26px; top: 1px; width: 25px; height: 25px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; color: #fff; background: var(--sc, #6777ef); box-shadow: 0 2px 6px rgba(0,0,0,.18); }
                            .sv-stage[data-state="wait"] .sv-dot { background: #cbd5e1 !important; }
                            .sv-stage[data-state="run"] .sv-dot { animation: sv-pulse 1.6s infinite; }
                            @keyframes sv-pulse { 0% { box-shadow: 0 0 0 0 rgba(103,119,239,.45); } 70% { box-shadow: 0 0 0 8px rgba(103,119,239,0); } 100% { box-shadow: 0 0 0 0 rgba(103,119,239,0); } }
                            .sv-scroll::-webkit-scrollbar { width: 6px; }
                            .sv-scroll::-webkit-scrollbar-track { background: transparent; }
                            .sv-scroll::-webkit-scrollbar-thumb { background: #b9c4d8; border-radius: 999px; }
                            .sv-scroll::-webkit-scrollbar-thumb:hover { background: #6777ef; }
                            </style>
                            <div class="sv-timeline">
                                <?php foreach ($sv_stages as $sg): ?>
                                    <div class="sv-stage" data-state="<?= $sg['state'] ?>">
                                        <span class="sv-dot" style="--sc: <?= $sg['color'] ?>;">
                                            <?php if ($sg['state'] === 'done'): ?><i class="fas fa-check"></i>
                                            <?php elseif ($sg['state'] === 'run'): ?><i class="fas <?= $sg['icon'] ?>"></i>
                                            <?php else: ?><i class="far fa-circle"></i><?php endif; ?>
                                        </span>
                                        <div class="d-flex justify-content-between align-items-center flex-wrap" style="gap: 6px;">
                                            <a href="<?= $sg['url'] ?>" class="font-weight-bold text-dark" style="font-size: 13.5px;"><?= htmlspecialchars($sg['label']) ?></a>
                                            <?php if ($sg['state'] === 'done'): ?>
                                                <span class="badge badge-success">Selesai</span>
                                            <?php elseif ($sg['state'] === 'run'): ?>
                                                <span class="badge badge-primary">Berjalan</span>
                                            <?php else: ?>
                                                <span class="badge badge-light border text-muted">Menunggu</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="small text-muted"><?= htmlspecialchars($sg['desc']) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            </div>
                        </div>
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
                        <div class="card-header"><h4>Status Tindak Lanjut</h4></div>
                        <div class="card-body"><div style="height:280px;"><canvas id="chartTlStatus"></canvas></div></div>
                    </div>
                </div>
                <div class="col-lg-6 d-flex">
                    <div class="card flex-fill">
                        <div class="card-header"><h4>Progres Supervisi per Bulan</h4></div>
                        <div class="card-body"><div style="height:280px;"><canvas id="chartPerBulan"></canvas></div></div>
                    </div>
                </div>
                <div class="col-lg-6 d-flex">
                    <div class="card flex-fill">
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
            </div>
        </div>
    </section>
</div>
<?php include '../templates/footer.php'; ?>
