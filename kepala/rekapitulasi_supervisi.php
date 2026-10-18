<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$page_title = 'Rekapitulasi Supervisi';
$current_page = basename(__FILE__);

$school_profile = getSchoolProfile($pdo);
$periode = sv_periode($pdo);

$css_libs = ['https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'];
$js_libs = [

    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
];

$guru_list = sv_guru_list($pdo);
$mapel_list = sv_mapel_list($pdo);
$kelas_list = sv_kelas_list($pdo);

$f = [
    'tahun_ajaran' => trim((string)($_GET['tahun_ajaran'] ?? $periode['tahun_ajaran'])),
    'semester' => trim((string)($_GET['semester'] ?? $periode['semester'])),
    'guru' => trim((string)($_GET['guru'] ?? '')),
    'mapel' => trim((string)($_GET['mapel'] ?? '')),
    'kelas' => trim((string)($_GET['kelas'] ?? '')),
    'jenis' => trim((string)($_GET['jenis'] ?? '')),
    'supervisor' => trim((string)($_GET['supervisor'] ?? '')),
    'predikat' => trim((string)($_GET['predikat'] ?? '')),
    'status' => trim((string)($_GET['status'] ?? '')),
];

$parts = explode('/', $f['tahun_ajaran']);
$ta_start = ($parts[0] ?? date('Y')) . '-07-01';
$ta_end = ($parts[1] ?? ((int)date('Y') + 1)) . '-06-30';

$where = ['p.tanggal BETWEEN ? AND ?'];
$params = [$ta_start, $ta_end];
if ($f['guru'] !== '') {
    $where[] = 'p.id_guru = ?';
    $params[] = (int)$f['guru'];
}
if ($f['jenis'] !== '') {
    $where[] = 'p.jenis_supervisi = ?';
    $params[] = $f['jenis'];
}
if ($f['supervisor'] !== '') {
    $where[] = 'p.supervisor = ?';
    $params[] = $f['supervisor'];
}
if ($f['predikat'] !== '') {
    $where[] = 'p.predikat = ?';
    $params[] = $f['predikat'];
}
if ($f['status'] !== '') {
    $where[] = 'p.status = ?';
    $params[] = $f['status'];
}
$whereSql = ' WHERE ' . implode(' AND ', $where);

$allRows = [];
try {
    $stmt = $pdo->prepare("SELECT p.*, i.nama_instrumen FROM tb_sv_pelaksanaan p
        LEFT JOIN tb_sv_instrumen i ON i.id_instrumen = p.id_instrumen
        {$whereSql} ORDER BY p.nama_guru ASC, p.tanggal DESC");
    $stmt->execute($params);
    $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $allRows = [];
}

// Ambil data kelas per guru berdasarkan Sasaran Supervisi
$kelasGuruAll = [];
$mapelGuruAll = [];
try {
    $kelasGuruAll = sv_sasaran_kelas_map($pdo, $f['tahun_ajaran'], $f['semester']);
    if (empty($kelasGuruAll)) {
        $kelasGuruAll = sv_kelas_guru($pdo);
    }
    $mapelGuruAll = sv_mapel_guru($pdo);
} catch (Throwable $e) {}

// Filter berdasarkan kelas jika dipilih
$rows = [];
foreach ($allRows as $r) {
    $idG = (int)$r['id_guru'];
    $klsList = $kelasGuruAll[$idG] ?? ['-'];
    if ($f['kelas'] !== '') {
        $matchKelas = false;
        foreach ($klsList as $nmKls) {
            if (strcasecmp(trim($nmKls), trim($f['kelas'])) === 0) {
                $matchKelas = true;
                break;
            }
        }
        if (!$matchKelas) continue;
    }
    $rows[] = $r;
}

// Ringkasan
$ringkas = [
    'jumlah_guru' => count($guru_list),
    'sudah' => 0,
    'belum' => 0,
    'jumlah_supervisi' => count($rows),
    'tertinggi' => null,
    'terendah' => null,
    'rata' => null,
    'temuan' => 0,
    'tl_selesai' => 0,
    'tl_belum' => 0,
];
$guruSudah = [];
$nilaiArr = [];
foreach ($rows as $r) {
    if ($r['status'] === 'Selesai') {
        if (!empty($r['id_guru'])) {
            $guruSudah[(int)$r['id_guru']] = true;
        }
        if ($r['nilai'] !== null) {
            $nilaiArr[] = (float)$r['nilai'];
        }
    }
    if (trim((string)$r['temuan']) !== '') {
        $ringkas['temuan']++;
    }
}
$ringkas['sudah'] = count($guruSudah);
$ringkas['belum'] = max(0, $ringkas['jumlah_guru'] - $ringkas['sudah']);
if ($nilaiArr) {
    $ringkas['tertinggi'] = max($nilaiArr);
    $ringkas['terendah'] = min($nilaiArr);
    $ringkas['rata'] = round(array_sum($nilaiArr) / count($nilaiArr), 2);
}
try {
    $ringkas['tl_selesai'] = (int)$pdo->query("SELECT COUNT(*) FROM tb_sv_tindak_lanjut WHERE status = 'Selesai'")->fetchColumn();
    $ringkas['tl_belum'] = (int)$pdo->query("SELECT COUNT(*) FROM tb_sv_tindak_lanjut WHERE status <> 'Selesai'")->fetchColumn();
} catch (Throwable $e) {
}

// Ambil data kelas per guru berdasarkan Sasaran Supervisi
$kelasGuruAll = [];
$mapelGuruAll = [];
try {
    $kelasGuruAll = sv_sasaran_kelas_map($pdo, $f['tahun_ajaran'], $f['semester']);
    if (empty($kelasGuruAll)) {
        $kelasGuruAll = sv_kelas_guru($pdo);
    }
    $mapelGuruAll = sv_mapel_guru($pdo);
} catch (Throwable $e) {}

// Susun baris rekap per pelaksanaan supervisi / guru
$kelompok = [];
foreach ($rows as $r) {
    $idG = (int)$r['id_guru'];
    $label = $r['nama_guru'] ?: ($r['unit_bagian'] ?: '-');
    $mapelAktif = trim((string)($r['mapel_di_supervisi'] ?? '')) ?: '-';
    $kelasAktif = implode(', ', $kelasGuruAll[$idG] ?? ['-']);
    $jenis = (string)$r['jenis_supervisi'];
    $nilai = $r['nilai'] !== null ? (float)$r['nilai'] : null;
    $predikat = $r['predikat'] ?: ($nilai !== null ? sv_predikat($nilai) : '-');

    $kelompok[] = [
        'id_pelaksanaan' => (int)($r['id_pelaksanaan'] ?? 0),
        'id_guru' => $idG,
        'label' => $label,
        'mapel' => $mapelAktif,
        'kelas' => $kelasAktif,
        'jenis' => $jenis,
        'rata' => $nilai,
        'predikat' => $predikat,
        'tanggal' => $r['tanggal'] ?? null,
        'supervisor' => $r['supervisor'] ?? '-',
        'nama_instrumen' => $r['nama_instrumen'] ?? '-',
        'status' => $r['status'] ?? 'Draft',
        'fokus' => $r['fokus'] ?? '',
        'rekomendasi' => $r['rekomendasi'] ?? '',
        'prioritas_perbaikan' => $r['prioritas_perbaikan'] ?? '',
        'raw' => $r,
    ];
}
usort($kelompok, function($a, $b) { return strcasecmp($a['label'], $b['label']); });

$js_page = [];
$js_page[] = <<<'JS'
$(document).ready(function () {
    document.querySelectorAll('form[method="GET"]').forEach(function(f){
        f.querySelectorAll('select, input[type="date"]').forEach(function(el){
            el.addEventListener('change',function(){f.submit();});
        });
    });
    
    function svFilterRekapCards() {
        var q = $.trim($('#filter-search-rekap').val() || '').toLowerCase();
        var visible = 0;
        $('.rekap-card-col').each(function () {
            var searchData = $(this).data('search') || '';
            var matchSearch = !q || searchData.indexOf(q) !== -1;
            if (matchSearch) {
                $(this).removeClass('d-none');
                visible++;
            } else {
                $(this).addClass('d-none');
            }
        });
        $('#empty-search-msg').toggle(visible === 0);
        $('#rekap-count-badge').text(visible + ' Supervisi');
    }
    $('#filter-search-rekap').on('input', svFilterRekapCards);

    $('#btn-excel').on('click', function () {
        var table = document.getElementById('table-rekap');
        if (!table) return;
        if (typeof XLSX !== 'undefined') {
            var clone = table.cloneNode(true);
            var wb = XLSX.utils.table_to_book(clone, { sheet: "Rekapitulasi" });
            XLSX.writeFile(wb, 'rekapitulasi_supervisi.xlsx');
        } else {
            var html = '<table border="1">' + table.innerHTML + '</table>';
            var a = document.createElement('a');
            a.href = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
            a.download = 'rekapitulasi_supervisi.xls';
            a.click();
        }
    });

    $('#btn-pdf').on('click', function () {
        var q = $('form[method=GET]').serialize();
        window.open('cetak_supervisi.php?page=rekapitulasi&' + q, '_blank');
    });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<div class="main-content">
    <style>
    .hover-shadow { transition: all 0.2s ease-in-out; }
    .hover-shadow:hover { box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,0.1) !important; transform: translateY(-2px); }
    .transition { transition: all 0.2s ease-in-out; }
    </style>
    <section class="section">
        <div class="section-header">
            <h1>Rekapitulasi Supervisi</h1>
            <?php echo render_breadcrumb(); ?>
        </div>
        <div class="section-body">
            <input type="hidden" id="svSchoolName" value="<?= htmlspecialchars($school_profile['nama_madrasah'] ?? 'MADRASAH', ENT_QUOTES) ?>">
            <input type="hidden" id="svSchoolLogo" value="<?= !empty($school_profile['logo']) ? '../assets/img/' . htmlspecialchars($school_profile['logo'], ENT_QUOTES) : '' ?>">
            <input type="hidden" id="svAcademicYear" value="<?= htmlspecialchars($f['tahun_ajaran'], ENT_QUOTES) ?>">
            <input type="hidden" id="svSemester" value="<?= htmlspecialchars($f['semester'], ENT_QUOTES) ?>">
            <input type="hidden" id="svHeadName" value="<?= htmlspecialchars($school_profile['nama_kepala'] ?? '-', ENT_QUOTES) ?>">
            <input type="hidden" id="svHeadNip" value="<?= htmlspecialchars($school_profile['nip_kepala'] ?? '-', ENT_QUOTES) ?>">
            <input type="hidden" id="svPrintPlace" value="<?= htmlspecialchars($school_profile['tempat_jadwal'] ?? 'Padang', ENT_QUOTES) ?>">
            <input type="hidden" id="svPrintDate" value="<?= date('d F Y') ?>">

            <div class="card mb-3">
                <div class="card-body py-3">
                    <form method="GET" class="form-row align-items-end">
                        <div class="form-group col-md-3 mb-2">
                            <label class="small font-weight-bold">Tahun Ajaran</label>
                            <select class="form-control form-control-sm" name="tahun_ajaran">
                                <?php foreach (sv_tahun_ajaran_options($pdo) as $ta): ?><option value="<?= htmlspecialchars($ta, ENT_QUOTES) ?>" <?= $ta === $f['tahun_ajaran'] ? 'selected' : '' ?>><?= htmlspecialchars($ta) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-3 mb-2">
                            <label class="small font-weight-bold">Semester</label>
                            <select class="form-control form-control-sm" name="semester">
                                <option value="">Semua Semester</option>
                                <?php foreach (sv_semester_options() as $s): ?><option value="<?= htmlspecialchars($s, ENT_QUOTES) ?>" <?= $s === $f['semester'] ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-3 mb-2">
                            <label class="small font-weight-bold">Guru</label>
                            <select class="form-control form-control-sm" name="guru">
                                <option value="">Semua Guru</option>
                                <?php foreach ($guru_list as $g): ?><option value="<?= (int)$g['id_guru'] ?>" <?= (string)$g['id_guru'] === $f['guru'] ? 'selected' : '' ?>><?= htmlspecialchars($g['nama_guru']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-3 mb-2">
                            <label class="small font-weight-bold">Jenis</label>
                            <select class="form-control form-control-sm" name="jenis">
                                <option value="">Semua Jenis</option>
                                <?php foreach (sv_jenis_list() as $j): ?><option value="<?= $j ?>" <?= $j === $f['jenis'] ? 'selected' : '' ?>><?= $j ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-3 mb-2">
                            <label class="small font-weight-bold">Kelas</label>
                            <select class="form-control form-control-sm" name="kelas">
                                <option value="">Semua Kelas</option>
                                <?php foreach ($kelas_list as $kls): ?>
                                    <option value="<?= htmlspecialchars($kls['nama_kelas'], ENT_QUOTES) ?>" <?= $kls['nama_kelas'] === $f['kelas'] ? 'selected' : '' ?>><?= htmlspecialchars($kls['nama_kelas']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-3 mb-2">
                            <label class="small font-weight-bold">Predikat</label>
                            <select class="form-control form-control-sm" name="predikat">
                                <option value="">Semua Predikat</option>
                                <?php foreach (['A - Amat Baik', 'B - Baik', 'C - Cukup', 'D - Kurang'] as $p): ?>
                                    <option value="<?= $p ?>" <?= $p === $f['predikat'] ? 'selected' : '' ?>><?= $p ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-3 mb-2">
                            <label class="small font-weight-bold">Status</label>
                            <select class="form-control form-control-sm" name="status">
                                <option value="">Semua Status</option>
                                <option value="Selesai" <?= $f['status'] === 'Selesai' ? 'selected' : '' ?>>Selesai</option>
                                <option value="Draft" <?= $f['status'] === 'Draft' ? 'selected' : '' ?>>Draft</option>
                            </select>
                        </div>
                        <div class="form-group col-md-3 mb-2">
                            <label class="small font-weight-bold">Cari Rekapitulasi</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="filter-search-rekap" placeholder="Cari guru, mapel, kelas...">
                                <div class="input-group-append">
                                    <a href="rekapitulasi_supervisi.php" class="btn btn-light border" title="Reset Filter"><i class="fas fa-undo"></i></a>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Stats Chips -->
            <div class="d-flex flex-wrap align-items-center mb-3" style="gap: 8px;">
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-users text-secondary mr-1"></i> Total Guru: <strong><?= (int)$ringkas['jumlah_guru'] ?></strong>
                </span>
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-user-check text-success mr-1"></i> Sudah: <strong><?= (int)$ringkas['sudah'] ?></strong>
                </span>
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-user-clock text-warning mr-1"></i> Belum: <strong><?= (int)$ringkas['belum'] ?></strong>
                </span>
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-clipboard-list text-info mr-1"></i> Supervisi: <strong id="rekap-count-badge"><?= count($kelompok) ?></strong>
                </span>
                <?php if ($ringkas['rata'] !== null): ?>
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-chart-line text-primary mr-1"></i> Rata-rata: <strong><?= number_format($ringkas['rata'], 2) ?></strong>
                </span>
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-arrow-up text-success mr-1"></i> Tertinggi: <strong><?= number_format($ringkas['tertinggi'], 2) ?></strong>
                </span>
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-arrow-down text-danger mr-1"></i> Terendah: <strong><?= number_format($ringkas['terendah'], 2) ?></strong>
                </span>
                <?php endif; ?>
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-check-double text-success mr-1"></i> TL Selesai: <strong><?= (int)$ringkas['tl_selesai'] ?></strong>
                </span>
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-hourglass-half text-warning mr-1"></i> TL Belum: <strong><?= (int)$ringkas['tl_belum'] ?></strong>
                </span>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4>Daftar Rekapitulasi Supervisi <small class="text-muted"><?= htmlspecialchars($f['tahun_ajaran']) ?><?= $f['semester'] ? ' - Semester ' . htmlspecialchars($f['semester']) : '' ?></small></h4>
                    <div class="card-header-action">
                        <button class="btn btn-success" id="btn-excel" type="button"><i class="fas fa-file-excel"></i> Excel</button>
                        <button class="btn btn-warning" id="btn-pdf" type="button"><i class="fas fa-file-pdf"></i> PDF</button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($kelompok)): ?>
                        <div class="alert alert-info text-center py-4">
                            <i class="fas fa-info-circle fa-2x mb-2 d-block text-info"></i>
                            Belum ada data rekapitulasi supervisi pada filter ini.
                        </div>
                    <?php else: ?>
                        <div id="empty-search-msg" class="alert alert-warning text-center" style="display:none;">
                            <i class="fas fa-search mr-1"></i> Tidak ada data rekapitulasi supervisi yang cocok dengan pencarian Anda.
                        </div>
                        <div class="row" id="rekap-cards-container">
                            <?php foreach ($kelompok as $k): ?>
                                <?php
                                $pred = (string)($k['predikat'] ?? '');
                                $predColor = 'secondary';
                                if (stripos($pred, 'Amat Baik') !== false || stripos($pred, 'Sangat Baik') !== false || stripos($pred, 'A') === 0) {
                                    $predColor = 'success';
                                } elseif (stripos($pred, 'Baik') !== false || stripos($pred, 'B') === 0) {
                                    $predColor = 'primary';
                                } elseif (stripos($pred, 'Cukup') !== false || stripos($pred, 'C') === 0) {
                                    $predColor = 'warning';
                                } elseif (stripos($pred, 'Kurang') !== false || stripos($pred, 'D') === 0 || stripos($pred, 'E') === 0) {
                                    $predColor = 'danger';
                                }

                                $isDone = ($k['status'] === 'Selesai');
                                $statusBadge = $isDone ? 'success' : 'secondary';

                                $searchBlob = strtolower(implode(' ', [
                                    $k['label'],
                                    $k['mapel'],
                                    $k['kelas'],
                                    $k['jenis'],
                                    $k['supervisor'],
                                    $k['nama_instrumen'],
                                    $pred,
                                    $k['status'],
                                    $k['rekomendasi'],
                                    $k['prioritas_perbaikan']
                                ]));
                                ?>
                                <div class="col-12 col-md-6 col-lg-4 d-flex align-items-stretch mb-4 rekap-card-col"
                                     data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES) ?>"
                                     data-predikat="<?= htmlspecialchars($pred, ENT_QUOTES) ?>"
                                     data-status="<?= htmlspecialchars($k['status'] ?? '', ENT_QUOTES) ?>">
                                    <div class="card w-100 border-0 shadow-sm hover-shadow transition" style="border-radius: 12px; overflow: hidden; background: #fff; border: 1px solid #e2e8f0 !important;">
                                        <div class="card-body p-3 d-flex flex-column justify-content-between">
                                            <div>
                                                <!-- Top Bar: Jenis & Status -->
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <span class="badge badge-info px-2 py-1 font-weight-bold" style="font-size: 11.5px; border-radius: 6px;">
                                                        <i class="fas fa-clipboard-check mr-1"></i> <?= htmlspecialchars($k['jenis']) ?>
                                                    </span>
                                                    <span class="badge badge-<?= $statusBadge ?> px-2 py-1 font-weight-bold" style="font-size: 11px; border-radius: 6px;">
                                                        <i class="fas <?= $isDone ? 'fa-check-circle' : 'fa-pencil-ruler' ?> mr-1"></i> <?= htmlspecialchars($k['status']) ?>
                                                    </span>
                                                </div>

                                                <!-- Target Guru/Unit -->
                                                <h6 class="font-weight-bold text-dark mb-1" style="font-size: 15px; line-height: 1.4;">
                                                    <?= htmlspecialchars($k['label']) ?>
                                                </h6>

                                                <!-- Mapel, Kelas, Tanggal -->
                                                <div class="text-muted small mb-2 d-flex flex-wrap align-items-center" style="gap: 6px; font-size: 11.5px;">
                                                    <?php if (!empty($k['mapel']) && $k['mapel'] !== '-'): ?>
                                                        <span class="badge badge-light border text-dark font-weight-normal"><i class="fas fa-book text-muted mr-1"></i> <?= htmlspecialchars($k['mapel']) ?></span>
                                                        <span>&bull;</span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($k['kelas']) && $k['kelas'] !== '-'): ?>
                                                        <span class="badge badge-light border text-dark font-weight-normal"><i class="fas fa-chalkboard text-muted mr-1"></i> Kls <?= htmlspecialchars($k['kelas']) ?></span>
                                                        <span>&bull;</span>
                                                    <?php endif; ?>
                                                    <span><i class="far fa-calendar-alt text-muted mr-1"></i> <?= $k['tanggal'] ? date('d/m/Y', strtotime($k['tanggal'])) : '-' ?></span>
                                                </div>

                                                <!-- Supervisor & Instrumen Info Box -->
                                                <div class="p-2 mb-2 rounded" style="background: #f8fafc; font-size: 11.5px; border: 1px dashed #cbd5e1; line-height: 1.4;">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span class="text-muted"><i class="fas fa-user-tie mr-1 text-primary"></i> Supervisor:</span>
                                                        <strong class="text-dark"><?= htmlspecialchars((string)$k['supervisor']) ?></strong>
                                                    </div>
                                                    <?php if (!empty($k['nama_instrumen']) && $k['nama_instrumen'] !== '-'): ?>
                                                    <div class="mt-1 pt-1 border-top text-truncate text-muted" title="<?= htmlspecialchars((string)$k['nama_instrumen']) ?>">
                                                        <i class="fas fa-tasks mr-1 text-info"></i> <?= htmlspecialchars((string)$k['nama_instrumen']) ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>

                                                <!-- Metrik Nilai & Predikat -->
                                                <div class="p-2 mb-2 rounded d-flex justify-content-between align-items-center" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                                                    <div>
                                                        <small class="text-muted d-block" style="font-size: 10.5px;">Nilai Supervisi</small>
                                                        <h5 class="mb-0 text-primary font-weight-bold" style="font-size: 20px;">
                                                            <?= $k['rata'] !== null ? number_format((float)$k['rata'], 2) : '-' ?>
                                                        </h5>
                                                    </div>
                                                    <div class="text-right">
                                                        <small class="text-muted d-block" style="font-size: 10.5px;">Predikat</small>
                                                        <?php if (!empty($pred) && $pred !== '-'): ?>
                                                            <span class="badge badge-<?= $predColor ?> font-weight-bold" style="font-size: 11px; padding: 4px 10px; border-radius: 6px;">
                                                                <?= htmlspecialchars($pred) ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted font-italic" style="font-size: 11px;">Belum dinilai</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>

                                                <!-- Highlight Rekomendasi/Prioritas -->
                                                <?php if (!empty($k['rekomendasi']) || !empty($k['prioritas_perbaikan'])): ?>
                                                    <div class="p-2 rounded mb-2" style="background: #fffdf5; border: 1px solid #fef08a; font-size: 11.5px; line-height: 1.5;">
                                                        <?php if (!empty($k['rekomendasi'])): ?>
                                                            <div class="text-dark mb-1">
                                                                <strong class="text-warning d-block mb-1"><i class="fas fa-lightbulb mr-1"></i> Rekomendasi:</strong>
                                                                <?= nl2br(htmlspecialchars($k['rekomendasi'])) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($k['prioritas_perbaikan'])): ?>
                                                            <div class="text-dark <?= !empty($k['rekomendasi']) ? 'mt-2 pt-2 border-top' : '' ?>">
                                                                <strong class="d-block mb-1"><i class="fas fa-flag mr-1 text-danger"></i> Prioritas:</strong>
                                                                <span class="text-muted"><?= nl2br(htmlspecialchars($k['prioritas_perbaikan'])) ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Footer Aksi -->
                                            <?php if ($k['id_pelaksanaan'] > 0): ?>
                                            <div class="pt-2 mt-2 border-top d-flex justify-content-end" style="gap: 6px;">
                                                <a class="btn btn-outline-primary btn-sm px-3" href="hasil_supervisi_detail.php?id=<?= $k['id_pelaksanaan'] ?>" style="border-radius: 6px; font-size: 12px;">
                                                    <i class="fas fa-eye mr-1"></i> Detail
                                                </a>
                                                <a class="btn btn-outline-info btn-sm px-3" href="cetak_detail_hasil_supervisi.php?id=<?= $k['id_pelaksanaan'] ?>" target="_blank" style="border-radius: 6px; font-size: 12px;">
                                                    <i class="fas fa-print mr-1"></i> Cetak
                                                </a>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Hidden table for Excel export -->
                    <table class="d-none" id="table-rekap">
                        <thead>
                            <tr>
                                <th width="5%">No</th>
                                <th>Guru / Unit</th>
                                <th>Mata Pelajaran</th>
                                <th>Kelas</th>
                                <th>Jenis Supervisi</th>
                                <th>Tanggal</th>
                                <th>Supervisor</th>
                                <th>Nilai Rata-rata</th>
                                <th>Predikat</th>
                                <th>Status</th>
                                <th>Rekomendasi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($kelompok as $idx => $k): ?>
                                <tr>
                                    <td><?= $idx + 1 ?></td>
                                    <td><?= htmlspecialchars($k['label']) ?></td>
                                    <td><?= htmlspecialchars($k['mapel'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($k['kelas'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($k['jenis'] ?? '-') ?></td>
                                    <td><?= $k['tanggal'] ? date('d/m/Y', strtotime($k['tanggal'])) : '-' ?></td>
                                    <td><?= htmlspecialchars((string)($k['supervisor'] ?? '-')) ?></td>
                                    <td><?= $k['rata'] !== null ? htmlspecialchars(number_format((float)$k['rata'], 2)) : '-' ?></td>
                                    <td><?= htmlspecialchars($k['predikat']) ?></td>
                                    <td><?= htmlspecialchars($k['status']) ?></td>
                                    <td><?= htmlspecialchars((string)($k['rekomendasi'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</div>
<?php include '../templates/footer.php'; ?>
