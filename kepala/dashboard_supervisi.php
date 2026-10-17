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

// Jadwal terdekat (prioritas status Terjadwal terdekat, fallback riwayat jadwal aktif)
$jadwal_terdekat = [];
try {
    // 1. Coba ambil yang status Terjadwal dan tanggal >= hari ini
    $stmt = $pdo->prepare("SELECT j.*, i.nama_instrumen, p.nama_program 
                           FROM tb_sv_jadwal j
                           LEFT JOIN tb_sv_instrumen i ON i.id_instrumen = j.id_instrumen
                           LEFT JOIN tb_sv_program p ON p.id_program = j.id_program
                           WHERE j.status = 'Terjadwal' AND j.tanggal >= CURDATE()
                           ORDER BY j.tanggal ASC, j.jam_mulai ASC LIMIT 6");
    $stmt->execute();
    $jadwal_terdekat = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Jika tidak ada jadwal masa depan yang berstatus Terjadwal, tampilkan jadwal terbaru (seluruh status) agar timeline informatif
    if (empty($jadwal_terdekat)) {
        $stmt = $pdo->prepare("SELECT j.*, i.nama_instrumen, p.nama_program 
                               FROM tb_sv_jadwal j
                               LEFT JOIN tb_sv_instrumen i ON i.id_instrumen = j.id_instrumen
                               LEFT JOIN tb_sv_program p ON p.id_program = j.id_program
                               ORDER BY j.tanggal DESC, j.jam_mulai DESC LIMIT 6");
        $stmt->execute();
        $jadwal_terdekat = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
}

// Timeline tahapan supervisi per guru (periode berjalan)
$sv_guru_timeline = [];
try {
    $stmt = $pdo->prepare("SELECT nama_guru, COUNT(*) AS jml, MAX(tanggal) AS terakhir, AVG(nilai) AS rata
                           FROM tb_sv_pelaksanaan
                           WHERE status = 'Selesai' AND tanggal BETWEEN ? AND ?
                           GROUP BY nama_guru ORDER BY terakhir DESC LIMIT 12");
    $stmt->execute([$ta_start, $ta_end]);
    $sv_guru_timeline = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sv_guru_timeline as &$sg) {
        $sg['tl_selesai'] = 0;
        $sg['tl_total'] = 0;
        $sg['mon'] = 0;
        try {
            $st = $pdo->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN t.status = 'Selesai' THEN 1 ELSE 0 END) AS selesai
                                 FROM tb_sv_tindak_lanjut t
                                 JOIN tb_sv_pelaksanaan p ON p.id_pelaksanaan = t.id_pelaksanaan
                                 WHERE p.nama_guru = ? AND p.tanggal BETWEEN ? AND ?");
            $st->execute([$sg['nama_guru'], $ta_start, $ta_end]);
            if ($tr = $st->fetch(PDO::FETCH_ASSOC)) {
                $sg['tl_total'] = (int)($tr['total'] ?? 0);
                $sg['tl_selesai'] = (int)($tr['selesai'] ?? 0);
            }
        } catch (Throwable $e) {}
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM tb_sv_monitoring m
                                 JOIN tb_sv_tindak_lanjut t ON t.id_tindak_lanjut = m.id_tindak_lanjut
                                 JOIN tb_sv_pelaksanaan p ON p.id_pelaksanaan = t.id_pelaksanaan
                                 WHERE p.nama_guru = ? AND p.tanggal BETWEEN ? AND ?");
            $st->execute([$sg['nama_guru'], $ta_start, $ta_end]);
            $sg['mon'] = (int)$st->fetchColumn();
        } catch (Throwable $e) {}
    }
    unset($sg);
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
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4><i class="far fa-calendar-alt text-primary mr-2"></i>Timeline Jadwal Terdekat</h4>
                            <div class="card-header-action"><a href="jadwal_supervisi.php" class="btn btn-sm btn-primary"><i class="fas fa-list mr-1"></i>Lihat Semua</a></div>
                        </div>
                        <div class="card-body">
                            <style>
                            .sv-jd-timeline {
                                position: relative;
                                padding-left: 28px;
                            }
                            .sv-jd-timeline::before {
                                content: '';
                                position: absolute;
                                left: 11px;
                                top: 8px;
                                bottom: 8px;
                                width: 3px;
                                border-radius: 3px;
                                background: #e9edf5;
                            }
                            .sv-jd-item {
                                position: relative;
                                padding-bottom: 16px;
                            }
                            .sv-jd-item:last-child {
                                padding-bottom: 0;
                            }
                            .sv-jd-dot {
                                position: absolute;
                                left: -28px;
                                top: 2px;
                                width: 25px;
                                height: 25px;
                                border-radius: 50%;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                font-size: 11px;
                                color: #fff;
                                background: var(--dot-color, #6777ef);
                                box-shadow: 0 2px 6px rgba(0,0,0,.15);
                            }
                            .sv-jd-card {
                                background: #f8fafc;
                                border: 1px solid #e2e8f0;
                                border-radius: 12px;
                                padding: 11px 14px;
                                transition: all .2s ease;
                            }
                            .sv-jd-card:hover {
                                background: #ffffff;
                                border-color: #cbd5e1;
                                box-shadow: 0 4px 12px rgba(15,23,42,.06);
                                transform: translateY(-1px);
                            }
                            .sv-jd-date-pill {
                                font-size: 11px;
                                font-weight: 700;
                                padding: 3px 8px;
                                border-radius: 6px;
                                background: #e2e8f0;
                                color: #334155;
                                display: inline-flex;
                                align-items: center;
                                gap: 4px;
                            }
                            .sv-jd-date-pill.today {
                                background: #fee2e2;
                                color: #dc2626;
                                animation: sv-pulse 1.6s infinite;
                            }
                            .sv-jd-meta {
                                font-size: 11.5px;
                                color: #64748b;
                                display: flex;
                                flex-wrap: wrap;
                                gap: 10px;
                                margin-top: 5px;
                            }
                            .sv-jd-meta-item {
                                display: inline-flex;
                                align-items: center;
                                gap: 4px;
                            }
                            </style>

                            <div class="sv-scroll" style="max-height: 280px; overflow-y: auto; overflow-x: hidden; padding-right: 6px; scrollbar-width: thin; scrollbar-color: #b9c4d8 transparent;">
                                <?php if (!empty($jadwal_terdekat)): ?>
                                    <div class="sv-jd-timeline">
                                        <?php 
                                        $today_str = date('Y-m-d');
                                        foreach ($jadwal_terdekat as $j): 
                                            $st_val = $j['status'] ?? 'Terjadwal';
                                            $st_badge = 'badge-primary';
                                            $dot_color = '#6777ef';
                                            $dot_icon = 'far fa-calendar-check';

                                            if ($st_val === 'Terlaksana') {
                                                $st_badge = 'badge-success';
                                                $dot_color = '#47c363';
                                                $dot_icon = 'fas fa-check';
                                            } elseif ($st_val === 'Ditunda') {
                                                $st_badge = 'badge-warning';
                                                $dot_color = '#ffa426';
                                                $dot_icon = 'fas fa-clock';
                                            } elseif ($st_val === 'Dibatalkan') {
                                                $st_badge = 'badge-danger';
                                                $dot_color = '#fc544b';
                                                $dot_icon = 'fas fa-times';
                                            }

                                            $is_today = ($j['tanggal'] === $today_str);
                                            $tgl_display = $j['tanggal'] ? date('d/m/Y', strtotime($j['tanggal'])) : '-';
                                            $jam_str = $j['jam_mulai'] ? substr($j['jam_mulai'], 0, 5) : '';
                                            if ($jam_str && !empty($j['jam_selesai'])) {
                                                $jam_str .= ' - ' . substr($j['jam_selesai'], 0, 5);
                                            }
                                        ?>
                                            <div class="sv-jd-item">
                                                <span class="sv-jd-dot" style="--dot-color: <?= $dot_color ?>;">
                                                    <i class="<?= $dot_icon ?>"></i>
                                                </span>
                                                <div class="sv-jd-card">
                                                    <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap" style="gap: 6px;">
                                                        <div class="d-flex align-items-center flex-wrap" style="gap: 6px;">
                                                            <span class="sv-jd-date-pill <?= $is_today ? 'today' : '' ?>">
                                                                <i class="far fa-calendar"></i> <?= $tgl_display ?><?= $is_today ? ' (Hari ini)' : '' ?>
                                                            </span>
                                                            <span class="badge badge-info" style="font-size: 10px; padding: 3px 7px;"><?= htmlspecialchars($j['jenis_supervisi']) ?></span>
                                                        </div>
                                                        <span class="badge <?= $st_badge ?>" style="font-size: 10.5px;"><?= htmlspecialchars($st_val) ?></span>
                                                    </div>

                                                    <div class="font-weight-bold text-dark" style="font-size: 13.5px; line-height: 1.35;">
                                                        <?= htmlspecialchars($j['nama_guru'] ?: 'Unit / Sasaran') ?>
                                                    </div>
                                                    
                                                    <?php if (!empty($j['nama_program'])): ?>
                                                        <div class="small text-muted text-truncate" style="font-size: 11.5px;" title="<?= htmlspecialchars($j['nama_program']) ?>">
                                                            <i class="fas fa-clipboard-list text-muted mr-1"></i><?= htmlspecialchars($j['nama_program']) ?>
                                                        </div>
                                                    <?php endif; ?>

                                                    <div class="sv-jd-meta">
                                                        <?php if ($jam_str): ?>
                                                            <span class="sv-jd-meta-item"><i class="far fa-clock text-info"></i> <?= $jam_str ?></span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($j['tempat'])): ?>
                                                            <span class="sv-jd-meta-item"><i class="fas fa-map-marker-alt text-danger"></i> <?= htmlspecialchars($j['tempat']) ?></span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($j['supervisor'])): ?>
                                                            <span class="sv-jd-meta-item"><i class="fas fa-user-shield text-primary"></i> <?= htmlspecialchars($j['supervisor']) ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center text-muted py-4">
                                        <i class="far fa-calendar-times fa-3x mb-2 text-muted"></i>
                                        <div class="font-weight-bold">Belum ada jadwal terdekat</div>
                                        <div class="small">Silakan buat jadwal di modul Jadwal Supervisi.</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4><i class="fas fa-user-check mr-2"></i>Timeline Tahapan Supervisi per Guru</h4>
                            <span class="badge badge-primary font-weight-bold" style="font-size: 12px; padding: 6px 14px; border-radius: 20px;"><?= count($sv_guru_timeline) ?> guru</span>
                        </div>
                        <div class="card-body">
                            <?php if (empty($sv_guru_timeline)): ?>
                                <div class="text-center text-muted py-4">
                                    <i class="fas fa-user-clock fa-3x mb-3"></i><br>
                                    <strong>Belum ada supervisi selesai pada periode ini.</strong>
                                </div>
                            <?php else: ?>
                                <style>
                                .sv-guru-step { display: flex; align-items: flex-start; }
                                .sv-guru-node { flex: 1; min-width: 0; text-align: center; position: relative; padding-top: 30px; }
                                .sv-guru-node::before { content: ''; position: absolute; top: 12px; left: -50%; width: 100%; height: 3px; border-radius: 3px; background: #e9edf5; }
                                .sv-guru-node:first-child::before { display: none; }
                                .sv-guru-node.done::before { background: #47c363; }
                                .sv-guru-dot { position: absolute; top: 0; left: 50%; transform: translateX(-50%); width: 27px; height: 27px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; color: #fff; background: #cbd5e1; box-shadow: 0 2px 6px rgba(0,0,0,.15); z-index: 1; }
                                .sv-guru-node.done .sv-guru-dot { background: #47c363; }
                                .sv-guru-node.run .sv-guru-dot { background: #6777ef; animation: sv-pulse 1.6s infinite; }
                                .sv-guru-lbl { font-size: 10.5px; font-weight: 800; color: #0f172a; line-height: 1.3; }
                                .sv-guru-node:not(.done):not(.run) .sv-guru-lbl { color: #94a3b8; }
                                .sv-guru-sub { font-size: 10px; color: #64748b; }
                                </style>
                                <div class="row">
                                    <?php foreach ($sv_guru_timeline as $sg):
                                        $sg_jml = (int)$sg['jml'];
                                        $sg_rata = $sg['rata'] !== null ? round((float)$sg['rata'], 1) : null;
                                        $sg_steps = [
                                            ['label' => 'Dijadwalkan', 'sub' => $sg_jml . ' supervisi', 'icon' => 'fas fa-calendar-check', 'state' => $sg_jml > 0 ? 'done' : ''],
                                            ['label' => 'Dilaksanakan', 'sub' => $sg['terakhir'] ? date('d/m/Y', strtotime($sg['terakhir'])) : '-', 'icon' => 'fas fa-chalkboard-teacher', 'state' => $sg_jml > 0 ? 'done' : ''],
                                            ['label' => 'Dinilai', 'sub' => $sg_rata !== null ? 'Rata-rata ' . $sg_rata : 'Belum ada nilai', 'icon' => 'fas fa-star', 'state' => $sg_rata !== null ? 'done' : ($sg_jml > 0 ? 'run' : '')],
                                            ['label' => 'Ditindaklanjuti', 'sub' => (int)$sg['tl_selesai'] . '/' . (int)$sg['tl_total'] . ' selesai', 'icon' => 'fas fa-tasks', 'state' => ((int)$sg['tl_total'] > 0 && (int)$sg['tl_selesai'] >= (int)$sg['tl_total']) ? 'done' : ((int)$sg['tl_total'] > 0 ? 'run' : '')],
                                            ['label' => 'Dimonitoring', 'sub' => (int)$sg['mon'] . ' kunjungan', 'icon' => 'fas fa-search', 'state' => (int)$sg['mon'] > 0 ? 'done' : (((int)$sg['tl_total'] > 0) ? 'run' : '')],
                                        ];
                                        $inisial_g = strtoupper(mb_substr(trim((string)$sg['nama_guru']), 0, 1, 'UTF-8'));
                                    ?>
                                        <div class="col-12 col-xl-6 mb-3">
                                            <div class="border rounded p-3 h-100" style="border-radius: 12px;">
                                                <div class="d-flex align-items-center mb-3">
                                                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center mr-2 font-weight-bold" style="width: 40px; height: 40px; min-width: 40px; font-size: 16px;">
                                                        <?= htmlspecialchars($inisial_g) ?>
                                                    </div>
                                                    <div class="min-width-0">
                                                        <div class="font-weight-bold text-dark" style="font-size: 14px;"><?= htmlspecialchars($sg['nama_guru']) ?></div>
                                                        <div class="small text-muted"><?= $sg_jml ?> supervisi selesai<?= $sg_rata !== null ? ' &bull; Rata-rata <strong class="text-primary">' . $sg_rata . '</strong>' : '' ?></div>
                                                    </div>
                                                </div>
                                                <div class="sv-guru-step">
                                                    <?php foreach ($sg_steps as $st): ?>
                                                        <div class="sv-guru-node <?= $st['state'] ?>">
                                                            <span class="sv-guru-dot"><i class="<?= $st['state'] === 'done' ? 'fas fa-check' : $st['icon'] ?>"></i></span>
                                                            <div class="sv-guru-lbl"><?= $st['label'] ?></div>
                                                            <div class="sv-guru-sub"><?= htmlspecialchars($st['sub']) ?></div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
<?php include '../templates/footer.php'; ?>
