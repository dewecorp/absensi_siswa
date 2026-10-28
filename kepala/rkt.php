<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/rkt_helper.php';

if (session_status() == PHP_SESSION_NONE) session_start();
if (!isAuthorized(['admin', 'kepala_madrasah'])) redirect('../login.php');

$user_level = getUserLevel();
$is_editable = in_array($user_level, ['admin', 'kepala_madrasah'], true);
$page_title = 'Rencana Kerja Tahunan (RKT)';

rktEnsureSchema($pdo);

$school = getSchoolProfile($pdo);
$fields = rkjmFields($pdo);
$doc_status_opts = rktDocStatusOptions();
$prog_status_opts = rktProgramStatusOptions();

$docId = (int)($_GET['doc'] ?? 0);
$tahunFilter = (int)($_GET['tahun'] ?? 0);
if ($docId > 0) {
    $doc = rktGetDocument($pdo, $docId);
} elseif ($tahunFilter > 0) {
    $doc = rktGetDocument($pdo, 0, $tahunFilter);
} else {
    $doc = rktGetDocument($pdo);
}
$docs = rktDocuments($pdo);
$activeYear = $doc ? (int)$doc['tahun'] : ($tahunFilter > 0 ? $tahunFilter : (int)date('Y'));

$yearsList = [];
foreach ($docs as $d) $yearsList[(int)$d['tahun']] = (int)$d['tahun'];
$yearsList[(int)date('Y')] = (int)date('Y');
if ($activeYear > 0) $yearsList[$activeYear] = $activeYear;
krsort($yearsList);

$rkjmDocs = $pdo->query("SELECT id, nomor_dokumen, tahun_awal, tahun_akhir, status FROM rkjm_documents ORDER BY tahun_awal DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
$rkjmMap = [];
foreach ($rkjmDocs as $rd) {
    $rkjmMap[(int)$rd['id']] = [
        'tahun_awal' => (int)$rd['tahun_awal'],
        'tahun_akhir' => (int)$rd['tahun_akhir'],
    ];
}
$defaultRkjm = rktFindRkjmDocForYear($pdo, $activeYear);
$defaultRkjmId = $defaultRkjm ? (int)$defaultRkjm['id'] : 0;

$summary = $doc ? rktSummary($pdo, (int)$doc['id']) : [
    'program' => 0, 'kegiatan' => 0, 'belum' => 0, 'berjalan' => 0, 'selesai' => 0,
    'tertunda' => 0, 'tidak_terlaksana' => 0, 'anggaran' => 0.0, 'anggaran_kegiatan' => 0.0,
    'realisasi' => 0.0, 'per_bidang' => [],
];

$csrf = appCsrfToken();

$q = trim((string)($_GET['q'] ?? ''));
$f_field = (int)($_GET['bidang'] ?? 0);
$f_status = trim((string)($_GET['status'] ?? ''));
if ($f_status !== '' && !isset($prog_status_opts[$f_status])) $f_status = '';
$f_pj = trim((string)($_GET['pj'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'urutan');
$dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = (int)($_GET['per_page'] ?? 10);
if (!in_array($per_page, [10, 25, 50, 100], true)) $per_page = 10;

$sort_map = [
    'urutan' => 'p.urutan',
    'nama' => 'p.nama_program',
    'bidang' => 'p.bidang_nama',
    'anggaran' => 'p.estimasi_anggaran',
    'status' => 'p.status',
    'target' => 'p.target_tahunan',
];
$order_by = $sort_map[$sort] ?? 'p.urutan';
if (($sort_map[$sort] ?? '') === 'p.urutan') $dir = 'ASC';

$rows = [];
$total_rows = 0;
$total_pages = 1;
$pj_options = [];

if ($doc) {
    $pj_stmt = $pdo->prepare("SELECT DISTINCT penanggung_jawab FROM rkt_programs
        WHERE document_id = ? AND COALESCE(penanggung_jawab,'') <> '' ORDER BY penanggung_jawab ASC");
    $pj_stmt->execute([(int)$doc['id']]);
    $pj_options = $pj_stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    $where = ['p.document_id = ?'];
    $params = [(int)$doc['id']];
    if ($q !== '') {
        $where[] = "(p.nama_program LIKE ? OR p.kode_program LIKE ? OR p.bidang_nama LIKE ? OR p.indikator LIKE ? OR p.rkjm_referensi LIKE ?)";
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if ($f_field > 0) {
        $where[] = "p.field_id = ?";
        $params[] = $f_field;
    }
    if ($f_status !== '') {
        $where[] = "p.status = ?";
        $params[] = $f_status;
    }
    if ($f_pj !== '') {
        $where[] = "p.penanggung_jawab = ?";
        $params[] = $f_pj;
    }
    $where_sql = implode(' AND ', $where);

    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM rkt_programs p WHERE $where_sql");
    $count_stmt->execute($params);
    $total_rows = (int)$count_stmt->fetchColumn();
    $total_pages = max(1, (int)ceil($total_rows / $per_page));
    if ($page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;

    $list_stmt = $pdo->prepare("SELECT p.*,
            (SELECT COUNT(*) FROM rkt_activities a WHERE a.program_id = p.id) AS activity_count
        FROM rkt_programs p WHERE $where_sql ORDER BY $order_by $dir, p.id ASC LIMIT $per_page OFFSET $offset");
    $list_stmt->execute($params);
    $rows = $list_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$total_templates = (int)$pdo->query("SELECT COUNT(*) FROM rkt_template_programs")->fetchColumn();
$active_templates = (int)$pdo->query("SELECT COUNT(*) FROM rkt_template_programs WHERE is_active = 1")->fetchColumn();
$tpl_rows = $pdo->query("SELECT t.*,
        (SELECT COUNT(*) FROM rkt_template_activities a WHERE a.template_program_id = t.id) AS activity_count
        FROM rkt_template_programs t WHERE t.is_active = 1
        ORDER BY t.bidang_nama ASC, t.kode ASC")->fetchAll(PDO::FETCH_ASSOC);

$docDefaults = [
    'id' => $doc ? (int)$doc['id'] : 0,
    'nama_madrasah' => $doc['nama_madrasah'] ?? (string)($school['nama_madrasah'] ?? ''),
    'nomor_dokumen' => $doc['nomor_dokumen'] ?? '',
    'tahun' => $activeYear,
    'rkjm_document_id' => $doc['rkjm_document_id'] ?? ($defaultRkjmId ?: ''),
    'visi' => $doc['visi'] ?? '',
    'misi' => $doc['misi'] ?? '',
    'tujuan_pengembangan' => $doc['tujuan_pengembangan'] ?? '',
    'prioritas' => $doc['prioritas'] ?? '',
    'dasar_penyusunan' => $doc['dasar_penyusunan'] ?? '',
    'tanggal_penyusunan' => $doc['tanggal_penyusunan'] ?? '',
    'nama_kepala' => $doc['nama_kepala'] ?? (string)($school['nama_kepala'] ?: ($school['kepala_madrasah'] ?? '')),
    'status' => $doc['status'] ?? 'draf',
    'catatan' => $doc['catatan'] ?? '',
];

function rkt_excerpt(?string $text, int $len = 60): string
{
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));
    if ($text === '') return '-';
    if (function_exists('mb_strlen') && mb_strlen($text) > $len) {
        return mb_substr($text, 0, $len) . '...';
    }
    return strlen($text) > $len ? substr($text, 0, $len) . '...' : $text;
}

function rkt_qs(array $override = []): string
{
    $params = array_merge($_GET, $override);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) unset($params[$k]);
    }
    $qs = http_build_query($params);
    return $qs !== '' ? '?' . $qs : '';
}

function rkt_rentang(?string $mulai, ?string $selesai): string
{
    if (!$mulai && !$selesai) return '-';
    if ($mulai && $selesai) return rktTanggal($mulai) . ' s.d. ' . rktTanggal($selesai);
    return rktTanggal($mulai ?: $selesai);
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<style>
.rkt-hero{background:linear-gradient(135deg,#1f6f5c 0%,#2d9a7a 55%,#4bbf95 100%);border-radius:14px;color:#fff;padding:22px}
.rkt-hero h1{color:#fff;font-size:1.45rem;margin-bottom:4px}
.rkt-hero p{color:rgba(255,255,255,.88);margin-bottom:14px}
.rkt-hero .badge-soft{background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.34);color:#fff;font-weight:600;border-radius:999px;padding:5px 12px;font-size:.78rem}
.rkt-stat{border-radius:12px;border:1px solid #e5e8f0;background:#fff;height:100%}
.rkt-stat .rkt-stat-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex:0 0 38px}
.rkt-stat .rkt-stat-value{font-size:1.08rem;font-weight:700;color:#23302c;line-height:1.2}
.rkt-stat .rkt-stat-label{font-size:.72rem;color:#7b839c;text-transform:uppercase;letter-spacing:.03em}
.rkt-chip{display:inline-flex;align-items:center;gap:6px;background:#eaf8f2;border:1px solid #c9ecdc;color:#1f6f5c;border-radius:999px;padding:4px 10px;font-size:.75rem;margin:0 6px 6px 0}
.rkt-chip.warn{background:#fff6e5;border-color:#f4dcae;color:#a56a00}
.rkt-table th{font-size:.73rem;text-transform:uppercase;letter-spacing:.02em;color:#6b7391;white-space:nowrap;vertical-align:middle}
.rkt-table td{font-size:.84rem;vertical-align:middle}
.rkt-table .rkt-nama{font-weight:600;color:#23302c;min-width:170px}
.rkt-table .rkt-clip{max-width:190px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rkt-table .btn-icon{width:31px;height:31px;padding:0;line-height:31px;border-radius:8px}
.rkt-section-title{font-weight:700;color:#3b4266;font-size:.85rem;text-transform:uppercase;letter-spacing:.04em;margin:18px 0 10px;padding-bottom:6px;border-bottom:1px solid #eceff7}
.rkt-modal textarea{min-height:68px;resize:vertical}
.rkt-empty{border:2px dashed #dfe3f0;border-radius:14px;padding:36px;text-align:center;background:#fbfcff}
.rkt-src-chip{display:inline-block;background:#eef1ff;border:1px solid #d9def8;color:#4b54a8;border-radius:6px;padding:1px 6px;font-size:.72rem}
.rkt-unlinked{display:inline-block;background:#f4f5f9;border:1px dashed #cfd4e3;color:#7b839c;border-radius:6px;padding:1px 6px;font-size:.72rem}
.rkt-tpl-row td{vertical-align:middle}
.rkt-mismatch{display:none}
#rkt-tpl-list{max-height:52vh;overflow:auto}
#rkt-rkjm-list{max-height:52vh;overflow:auto}
.rkt-detail-table th{width:31%;vertical-align:top;background:#fafbfe}
.rkt-detail-table td{white-space:pre-line}
@media (max-width:767.98px){
 .rkt-hero{padding:16px}
 .rkt-hero .btn{width:100%;margin-bottom:6px}
 .rkt-hero .rkt-hero-actions{display:flex;flex-direction:column}
}
</style>
<div class="main-content">
<section class="section">
    <div class="section-header">
        <h1>Rencana Kerja Tahunan (RKT)</h1>
        <div class="section-header-breadcrumb">
            <div class="breadcrumb-item active"><a href="<?= htmlspecialchars(app_url('kepala/dashboard.php')) ?>">Dashboard</a></div>
            <div class="breadcrumb-item">Program Kerja</div>
            <div class="breadcrumb-item">RKT</div>
        </div>
    </div>

    <div class="section-body">
        <div class="rkt-hero mb-3">
            <div class="d-flex flex-wrap justify-content-between align-items-start">
                <div class="pr-3">
                    <h1>Rencana Kerja Tahunan (RKT)</h1>
                    <p class="mb-2">Menjabarkan prioritas RKJM menjadi rencana program dan kegiatan madrasah selama satu tahun pelaksanaan.</p>
                    <div class="mb-1">
                        <?php if ($doc): ?>
                            <span class="badge-soft mr-1"><i class="fas fa-calendar-alt mr-1"></i>Tahun <?= (int)$doc['tahun'] ?></span>
                            <span class="badge-soft mr-1"><i class="fas fa-file-signature mr-1"></i><?= htmlspecialchars($doc_status_opts[$doc['status']] ?? $doc['status']) ?></span>
                            <?php if (!empty($doc['nomor_dokumen'])): ?><span class="badge-soft mr-1"><i class="fas fa-hashtag mr-1"></i><?= htmlspecialchars($doc['nomor_dokumen']) ?></span><?php endif; ?>
                            <span class="badge-soft"><i class="fas fa-school mr-1"></i><?= htmlspecialchars($doc['nama_madrasah'] ?: ($school['nama_madrasah'] ?? 'Madrasah')) ?></span>
                        <?php else: ?>
                            <span class="badge-soft"><i class="fas fa-info-circle mr-1"></i>Belum ada dokumen RKT untuk tahun <?= (int)$activeYear ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="rkt-hero-actions mt-2 mt-md-0">
                    <div class="form-group mb-2">
                        <select id="rkt-year-switch" class="form-control form-control-sm">
                            <?php foreach ($yearsList as $th): ?>
                                <option value="<?= (int)$th ?>" <?= $activeYear === (int)$th ? 'selected' : '' ?>>Tahun <?= (int)$th ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="button" class="btn btn-light btn-sm mb-1" id="btn-doc-form"><i class="fas fa-cog mr-1"></i><?= $doc ? 'Pengaturan RKT' : 'Buat RKT' ?></button>
                    <?php if ($doc): ?>
                        <button type="button" class="btn btn-warning btn-sm mb-1" id="btn-add-program"><i class="fas fa-plus mr-1"></i>Tambah Program</button>
                        <button type="button" class="btn btn-success btn-sm mb-1" id="btn-use-template"><i class="fas fa-copy mr-1"></i>Gunakan Template</button>
                        <button type="button" class="btn btn-info btn-sm mb-1" id="btn-import-rkjm"><i class="fas fa-project-diagram mr-1"></i>Ambil dari RKJM</button>
                        <a href="rkt_template.php" class="btn btn-light btn-sm mb-1"><i class="fas fa-layer-group mr-1"></i>Kelola Template</a>
                        <a href="cetak_rkt.php?doc=<?= (int)$doc['id'] ?>" target="_blank" class="btn btn-primary btn-sm mb-1"><i class="fas fa-print mr-1"></i>Cetak RKT</a>
                    <?php else: ?>
                        <a href="rkt_template.php" class="btn btn-light btn-sm mb-1"><i class="fas fa-layer-group mr-1"></i>Kelola Template</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!$doc): ?>
            <div class="rkt-empty">
                <i class="fas fa-calendar-plus fa-3x text-muted mb-3"></i>
                <h5>Belum ada dokumen RKT tahun <?= (int)$activeYear ?></h5>
                <p class="text-muted mb-3">Buat dokumen RKT untuk tahun pelaksanaan ini, lalu ambil program dari RKJM atau gunakan template bawaan.</p>
                <button type="button" class="btn btn-primary" id="btn-create-doc"><i class="fas fa-plus mr-1"></i>Buat RKT Tahun <?= (int)$activeYear ?></button>
            </div>
        <?php else: ?>
            <div class="row mb-2">
                <div class="col-6 col-md-3 mb-2">
                    <div class="rkt-stat p-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div><div class="rkt-stat-value"><?= (int)$summary['program'] ?></div><div class="rkt-stat-label">Total Program</div></div>
                            <div class="rkt-stat-icon" style="background:#eaf8f2;color:#1f6f5c"><i class="fas fa-tasks"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3 mb-2">
                    <div class="rkt-stat p-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div><div class="rkt-stat-value"><?= (int)$summary['kegiatan'] ?></div><div class="rkt-stat-label">Total Kegiatan</div></div>
                            <div class="rkt-stat-icon" style="background:#e8f6fb;color:#1b87ab"><i class="fas fa-list-check"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3 mb-2">
                    <div class="rkt-stat p-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div><div class="rkt-stat-value"><?= (int)$summary['belum'] ?></div><div class="rkt-stat-label">Belum Dimulai</div></div>
                            <div class="rkt-stat-icon" style="background:#f1f2f6;color:#6b7391"><i class="fas fa-clock"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3 mb-2">
                    <div class="rkt-stat p-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div><div class="rkt-stat-value"><?= (int)$summary['berjalan'] ?></div><div class="rkt-stat-label">Berjalan</div></div>
                            <div class="rkt-stat-icon" style="background:#fff6e5;color:#d68a00"><i class="fas fa-sync"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3 mb-2">
                    <div class="rkt-stat p-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div><div class="rkt-stat-value"><?= (int)$summary['selesai'] ?></div><div class="rkt-stat-label">Selesai</div></div>
                            <div class="rkt-stat-icon" style="background:#e7f8ee;color:#1f9d55"><i class="fas fa-check-circle"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3 mb-2">
                    <div class="rkt-stat p-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div><div class="rkt-stat-value"><?= (int)$summary['tertunda'] + (int)$summary['tidak_terlaksana'] ?></div><div class="rkt-stat-label">Tertunda / Tidak Terlaksana</div></div>
                            <div class="rkt-stat-icon" style="background:#fdecec;color:#c0392b"><i class="fas fa-pause-circle"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6 mb-2">
                    <div class="rkt-stat p-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <div class="rkt-stat-value" style="font-size:1rem"><?= rktRupiah($summary['anggaran']) ?></div>
                                <div class="rkt-stat-label">Total Estimasi Anggaran (bukan realisasi)</div>
                            </div>
                            <div class="rkt-stat-icon" style="background:#fdeef6;color:#c0327f"><i class="fas fa-money-bill-wave"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6 mb-2">
                    <div class="rkt-stat p-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <div class="rkt-stat-value" style="font-size:1rem"><?= rktRupiah($summary['realisasi']) ?></div>
                                <div class="rkt-stat-label">Realisasi Anggaran Tercatat</div>
                            </div>
                            <div class="rkt-stat-icon" style="background:#eef1ff;color:#4b54a8"><i class="fas fa-receipt"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($summary['per_bidang'])): ?>
            <div class="mb-3">
                <?php foreach ($summary['per_bidang'] as $pb): ?>
                    <span class="rkt-chip"><i class="fas fa-tag"></i><?= htmlspecialchars($pb['bidang']) ?> <strong>(<?= (int)$pb['c'] ?>)</strong></span>
                <?php endforeach; ?>
                <span class="rkt-chip warn"><i class="fas fa-info-circle"></i>Estimasi anggaran tidak sama dengan realisasi</span>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
                    <h4 class="mb-2 mb-md-0">Daftar Program RKT Tahun <?= (int)$doc['tahun'] ?></h4>
                    <div class="text-muted small"><?= (int)$active_templates ?> dari <?= (int)$total_templates ?> template aktif</div>
                </div>
                <div class="card-body">
                    <form method="GET" class="mb-3">
                        <input type="hidden" name="doc" value="<?= (int)$doc['id'] ?>">
                        <div class="row">
                            <div class="col-12 col-md-4 mb-2">
                                <input type="search" name="q" class="form-control" placeholder="Cari program, kode, indikator, referensi..." value="<?= htmlspecialchars($q) ?>">
                            </div>
                            <div class="col-6 col-md-2 mb-2">
                                <select name="bidang" class="form-control">
                                    <option value="">Semua Bidang</option>
                                    <?php foreach ($fields as $f): ?>
                                        <option value="<?= (int)$f['id'] ?>" <?= $f_field === (int)$f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['nama']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-2 mb-2">
                                <select name="status" class="form-control">
                                    <option value="">Semua Status</option>
                                    <?php foreach ($prog_status_opts as $k => $v): ?>
                                        <option value="<?= htmlspecialchars($k) ?>" <?= $f_status === $k ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-2 mb-2">
                                <select name="pj" class="form-control">
                                    <option value="">Semua PJ</option>
                                    <?php foreach ($pj_options as $pj): ?>
                                        <option value="<?= htmlspecialchars($pj) ?>" <?= $f_pj === $pj ? 'selected' : '' ?>><?= htmlspecialchars(rkt_excerpt($pj, 28)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-2 mb-2">
                                <select name="per_page" class="form-control">
                                    <?php foreach ([10, 25, 50, 100] as $pp): ?>
                                        <option value="<?= $pp ?>" <?= $per_page === $pp ? 'selected' : '' ?>><?= $pp ?> / halaman</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap">
                            <button class="btn btn-primary mr-2 mb-1" type="submit"><i class="fas fa-filter mr-1"></i>Terapkan</button>
                            <a class="btn btn-light mb-1" href="rkt.php?doc=<?= (int)$doc['id'] ?>"><i class="fas fa-undo mr-1"></i>Reset</a>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover rkt-table">
                            <thead>
                                <tr>
                                    <th>No.</th>
                                    <th><a href="<?= htmlspecialchars(rkt_qs(['sort' => 'bidang', 'dir' => ($sort === 'bidang' && $dir === 'ASC') ? 'desc' : 'asc', 'page' => 1])) ?>">Bidang</a></th>
                                    <th><a href="<?= htmlspecialchars(rkt_qs(['sort' => 'nama', 'dir' => ($sort === 'nama' && $dir === 'ASC') ? 'desc' : 'asc', 'page' => 1])) ?>">Nama Program</a></th>
                                    <th>Referensi RKJM</th>
                                    <th>Tujuan</th>
                                    <th>Indikator</th>
                                    <th><a href="<?= htmlspecialchars(rkt_qs(['sort' => 'target', 'dir' => ($sort === 'target' && $dir === 'ASC') ? 'desc' : 'asc', 'page' => 1])) ?>">Target Tahunan</a></th>
                                    <th>Kegiatan</th>
                                    <th>Jadwal</th>
                                    <th>PJ</th>
                                    <th><a href="<?= htmlspecialchars(rkt_qs(['sort' => 'anggaran', 'dir' => ($sort === 'anggaran' && $dir === 'ASC') ? 'desc' : 'asc', 'page' => 1])) ?>">Estimasi Anggaran</a></th>
                                    <th>Sumber Dana</th>
                                    <th><a href="<?= htmlspecialchars(rkt_qs(['sort' => 'status', 'dir' => ($sort === 'status' && $dir === 'ASC') ? 'desc' : 'asc', 'page' => 1])) ?>">Status</a></th>
                                    <th class="text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$rows): ?>
                                    <tr><td colspan="14" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x d-block mb-2"></i>Belum ada program pada filter ini. Tambah program, ambil dari RKJM, atau gunakan template.</td></tr>
                                <?php endif; ?>
                                <?php $no = ($page - 1) * $per_page; foreach ($rows as $r): $no++; ?>
                                    <tr>
                                        <td><?= $no ?></td>
                                        <td><span class="badge badge-light border"><?= htmlspecialchars(rkt_excerpt($r['bidang_nama'], 30)) ?></span></td>
                                        <td>
                                            <div class="rkt-nama"><?= htmlspecialchars($r['nama_program']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($r['kode_program'] ?: '-') ?></small>
                                        </td>
                                        <td>
                                            <?php if (!empty($r['rkjm_referensi'])): ?>
                                                <span class="rkt-src-chip" title="<?= htmlspecialchars($r['rkjm_referensi']) ?>"><?= htmlspecialchars(rkt_excerpt($r['rkjm_referensi'], 34)) ?></span>
                                            <?php else: ?>
                                                <span class="rkt-unlinked">Belum Terhubung ke RKJM</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><div class="rkt-clip" title="<?= htmlspecialchars((string)$r['tujuan']) ?>"><?= htmlspecialchars(rkt_excerpt($r['tujuan'], 48)) ?></div></td>
                                        <td><div class="rkt-clip" title="<?= htmlspecialchars((string)$r['indikator']) ?>"><?= htmlspecialchars(rkt_excerpt($r['indikator'], 48)) ?></div></td>
                                        <td><div class="rkt-clip" title="<?= htmlspecialchars((string)$r['target_tahunan']) ?>"><?= htmlspecialchars(rkt_excerpt($r['target_tahunan'], 42)) ?></div></td>
                                        <td class="text-center"><span class="badge badge-light border"><?= (int)$r['activity_count'] ?></span></td>
                                        <td><small><?= htmlspecialchars(rkt_rentang($r['jadwal_mulai'], $r['jadwal_selesai'])) ?></small></td>
                                        <td><small><?= htmlspecialchars(rkt_excerpt($r['penanggung_jawab'], 24)) ?></small></td>
                                        <td class="text-nowrap"><?= rktRupiah($r['estimasi_anggaran']) ?></td>
                                        <td><small><?= htmlspecialchars(rkt_excerpt($r['sumber_dana'], 22)) ?></small></td>
                                        <td>
                                            <select class="form-control form-control-sm rkt-status-sel" data-id="<?= (int)$r['id'] ?>" <?= $is_editable ? '' : 'disabled' ?> style="min-width:128px">
                                                <?php foreach ($prog_status_opts as $k => $v): ?>
                                                    <option value="<?= htmlspecialchars($k) ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="text-right text-nowrap">
                                            <button type="button" class="btn btn-sm btn-light btn-icon btn-rkt-detail" data-id="<?= (int)$r['id'] ?>" title="Lihat detail"><i class="fas fa-eye"></i></button>
                                            <?php if ($is_editable): ?>
                                                <button type="button" class="btn btn-sm btn-light btn-icon btn-rkt-edit" data-id="<?= (int)$r['id'] ?>" title="Edit"><i class="fas fa-edit"></i></button>
                                                <button type="button" class="btn btn-sm btn-light btn-icon btn-rkt-duplicate" data-id="<?= (int)$r['id'] ?>" title="Duplikasi"><i class="fas fa-clone"></i></button>
                                                <button type="button" class="btn btn-sm btn-light btn-icon text-danger btn-rkt-delete" data-id="<?= (int)$r['id'] ?>" data-name="<?= htmlspecialchars($r['nama_program'], ENT_QUOTES) ?>" title="Hapus"><i class="fas fa-trash"></i></button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex flex-wrap justify-content-between align-items-center">
                        <div class="text-muted small mb-2">Menampilkan <?= $total_rows ? (($page - 1) * $per_page + 1) : 0 ?>-<?= min($total_rows, $page * $per_page) ?> dari <?= $total_rows ?> program</div>
                        <?php if ($total_pages > 1): ?>
                        <nav class="mb-2">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars(rkt_qs(['page' => max(1, $page - 1)])) ?>">&laquo;</a></li>
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= htmlspecialchars(rkt_qs(['page' => $i])) ?>"><?= $i ?></a></li>
                                <?php endfor; ?>
                                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars(rkt_qs(['page' => min($total_pages, $page + 1)])) ?>">&raquo;</a></li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
</div>

<!-- Modal Dokumen -->
<div class="modal fade" id="docModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <form id="docForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="docModalTitle">Dokumen RKT</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body rkt-modal">
                    <input type="hidden" name="id" value="<?= (int)$docDefaults['id'] ?>">
                    <div class="row">
                        <div class="col-md-7 form-group">
                            <label>Nama Madrasah</label>
                            <input type="text" name="nama_madrasah" class="form-control" value="<?= htmlspecialchars($docDefaults['nama_madrasah']) ?>" required>
                        </div>
                        <div class="col-md-5 form-group">
                            <label>Tahun Pelaksanaan</label>
                            <input type="number" name="tahun" class="form-control" min="1901" max="2155" value="<?= (int)$docDefaults['tahun'] ?>" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Nomor Dokumen</label>
                            <input type="text" name="nomor_dokumen" class="form-control" value="<?= htmlspecialchars($docDefaults['nomor_dokumen']) ?>" placeholder="Opsional">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Status Dokumen</label>
                            <select name="status" class="form-control">
                                <?php foreach ($doc_status_opts as $k => $v): ?>
                                    <option value="<?= htmlspecialchars($k) ?>" <?= $docDefaults['status'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Referensi Dokumen RKJM</label>
                        <select name="rkjm_document_id" class="form-control" id="doc-rkjm-select">
                            <option value="">Tanpa referensi RKJM (RKT mandiri)</option>
                            <?php foreach ($rkjmDocs as $rd): ?>
                                <option value="<?= (int)$rd['id'] ?>" <?= (int)$docDefaults['rkjm_document_id'] === (int)$rd['id'] ? 'selected' : '' ?>>
                                    <?= (int)$rd['tahun_awal'] ?>-<?= (int)$rd['tahun_akhir'] ?><?= !empty($rd['nomor_dokumen']) ? ' | ' . htmlspecialchars($rd['nomor_dokumen']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Visi, misi, dan tujuan yang kosong akan diambil dari RKJM saat disimpan, tanpa mengubah RKJM.</small>
                    </div>
                    <div class="form-group">
                        <label>Visi Madrasah</label>
                        <textarea name="visi" class="form-control" rows="2"><?= htmlspecialchars($docDefaults['visi']) ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Misi Madrasah</label>
                        <textarea name="misi" class="form-control" rows="3"><?= htmlspecialchars($docDefaults['misi']) ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Tujuan Pengembangan</label>
                        <textarea name="tujuan_pengembangan" class="form-control" rows="2"><?= htmlspecialchars($docDefaults['tujuan_pengembangan']) ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Prioritas Pengembangan Tahun Berjalan</label>
                        <textarea name="prioritas" class="form-control" rows="2"><?= htmlspecialchars($docDefaults['prioritas']) ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Dasar Penyusunan</label>
                        <textarea name="dasar_penyusunan" class="form-control" rows="2"><?= htmlspecialchars($docDefaults['dasar_penyusunan']) ?></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label>Tanggal Penyusunan</label>
                            <input type="date" name="tanggal_penyusunan" class="form-control" value="<?= htmlspecialchars((string)$docDefaults['tanggal_penyusunan']) ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Nama Kepala Madrasah</label>
                            <input type="text" name="nama_kepala" class="form-control" value="<?= htmlspecialchars($docDefaults['nama_kepala']) ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Catatan Tambahan</label>
                            <input type="text" name="catatan" class="form-control" value="<?= htmlspecialchars($docDefaults['catatan']) ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Simpan Dokumen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($doc): ?>
<!-- Modal Program -->
<div class="modal fade" id="programModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <form id="programForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="programModalTitle">Program RKT</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body rkt-modal">
                    <input type="hidden" name="id" value="0">
                    <input type="hidden" name="urutan" value="0">
                    <div class="alert alert-info py-2 rkt-mismatch" id="program-rkjm-info"></div>
                    <div class="row">
                        <div class="col-md-7 form-group">
                            <label>Nama Program</label>
                            <input type="text" name="nama_program" class="form-control" required>
                        </div>
                        <div class="col-md-5 form-group">
                            <label>Kode Program</label>
                            <input type="text" name="kode_program" class="form-control" placeholder="Opsional">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Bidang Pengembangan</label>
                            <select name="field_id" class="form-control">
                                <option value="">Lainnya / belum ditentukan</option>
                                <?php foreach ($fields as $f): ?>
                                    <option value="<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['nama']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Status Pelaksanaan</label>
                            <select name="status" class="form-control">
                                <?php foreach ($prog_status_opts as $k => $v): ?>
                                    <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Jadwal Mulai</label>
                            <input type="date" name="jadwal_mulai" class="form-control">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Jadwal Selesai</label>
                            <input type="date" name="jadwal_selesai" class="form-control">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Kondisi Awal / Latar Belakang</label>
                        <textarea name="kondisi_awal" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Tujuan Program</label>
                        <textarea name="tujuan" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Sasaran</label>
                        <textarea name="sasaran" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Indikator Keberhasilan</label>
                        <textarea name="indikator" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Target Tahunan</label>
                        <textarea name="target_tahunan" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Penanggung Jawab</label>
                            <input type="text" name="penanggung_jawab" class="form-control">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Estimasi Anggaran (tahunan)</label>
                            <input type="text" name="estimasi_anggaran" class="form-control rkt-rupiah" inputmode="numeric" placeholder="0">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Sumber Dana</label>
                            <input type="text" name="sumber_dana" class="form-control">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Output yang Diharapkan</label>
                            <input type="text" name="output" class="form-control">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Monitoring dan Evaluasi</label>
                        <textarea name="monitoring_evaluasi" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Catatan</label>
                        <textarea name="catatan" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Simpan Program</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Detail -->
<div class="modal fade" id="detailModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Detail Program RKT</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body" id="detailBody">
                <div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin mr-2"></i>Memuat...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
                <button type="button" class="btn btn-primary" id="btn-detail-edit"><i class="fas fa-edit mr-1"></i>Edit Program</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Kegiatan -->
<div class="modal fade" id="activityModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <form id="activityForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="activityModalTitle">Kegiatan Program</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body rkt-modal">
                    <input type="hidden" name="id" value="0">
                    <input type="hidden" name="program_id" value="0">
                    <input type="hidden" name="urutan" value="0">
                    <div class="form-group">
                        <label>Nama Kegiatan</label>
                        <input type="text" name="nama_kegiatan" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Tujuan Kegiatan</label>
                        <textarea name="tujuan" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Indikator Keberhasilan</label>
                        <textarea name="indikator" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label>Target / Volume</label>
                            <input type="text" name="target_volume" class="form-control">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Satuan</label>
                            <input type="text" name="satuan" class="form-control" placeholder="kegiatan, orang, persen...">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Status</label>
                            <select name="status" class="form-control">
                                <?php foreach ($prog_status_opts as $k => $v): ?>
                                    <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Jadwal Mulai</label>
                            <input type="date" name="jadwal_mulai" class="form-control">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Jadwal Selesai</label>
                            <input type="date" name="jadwal_selesai" class="form-control">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Penanggung Jawab</label>
                            <input type="text" name="penanggung_jawab" class="form-control">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Estimasi Biaya</label>
                            <input type="text" name="estimasi_biaya" class="form-control rkt-rupiah" inputmode="numeric" placeholder="0">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Sumber Dana</label>
                            <input type="text" name="sumber_dana" class="form-control">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Output yang Diharapkan</label>
                            <input type="text" name="output" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Simpan Kegiatan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Monitoring -->
<div class="modal fade" id="monitoringModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <form id="monitoringForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="monitoringModalTitle">Monitoring dan Evaluasi</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body rkt-modal">
                    <input type="hidden" name="id" value="0">
                    <input type="hidden" name="program_id" value="0">
                    <input type="hidden" name="activity_id" value="0">
                    <div class="alert alert-light border py-2" id="monitoring-scope"></div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Tanggal Pelaksanaan / Evaluasi</label>
                            <input type="date" name="tanggal_pelaksanaan" class="form-control">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Status Pelaksanaan</label>
                            <select name="status_pelaksanaan" class="form-control">
                                <?php foreach ($prog_status_opts as $k => $v): ?>
                                    <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Target</label>
                        <textarea name="target" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Realisasi</label>
                        <textarea name="realisasi" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label>Persentase Capaian (%)</label>
                            <input type="number" name="persentase_capaian" class="form-control" min="0" max="100" step="0.01" placeholder="Opsional">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Penilaian Kualitatif</label>
                            <select name="penilaian_kualitatif" class="form-control">
                                <option value="">-</option>
                                <option value="Sangat Baik">Sangat Baik</option>
                                <option value="Baik">Baik</option>
                                <option value="Cukup">Cukup</option>
                                <option value="Kurang">Kurang</option>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Realisasi Biaya</label>
                            <input type="text" name="realisasi_biaya" class="form-control rkt-rupiah" inputmode="numeric" placeholder="0">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Kendala</label>
                        <textarea name="kendala" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Tindak Lanjut</label>
                        <textarea name="tindak_lanjut" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Output Aktual</label>
                        <textarea name="output_aktual" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Bukti Pelaksanaan / Dokumen Pendukung</label>
                        <textarea name="bukti" class="form-control" rows="2" placeholder="Nama dokumen, tautan, atau nomor arsip"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Catatan Evaluasi</label>
                        <textarea name="catatan_evaluasi" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Penanggung Jawab Evaluasi</label>
                        <input type="text" name="petugas_evaluasi" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Simpan Monitoring</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Template -->
<div class="modal fade" id="templateModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Gunakan Template RKT</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="row mb-2">
                    <div class="col-md-7 mb-2"><input type="search" id="tpl-search" class="form-control" placeholder="Cari kode, nama program, atau bidang..."></div>
                    <div class="col-md-5 mb-2">
                        <select id="tpl-field" class="form-control">
                            <option value="">Semua Bidang</option>
                            <?php foreach ($fields as $f): ?>
                                <option value="<?= htmlspecialchars($f['nama']) ?>"><?= htmlspecialchars($f['nama']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="alert alert-light border py-2 d-flex flex-wrap justify-content-between align-items-center">
                    <span>Template aktif: <strong><?= (int)$active_templates ?></strong>. Program dan kegiatan disalin sebagai data RKT yang dapat diedit terpisah.</span>
                    <a href="rkt_template.php" target="_blank" class="btn btn-sm btn-outline-secondary mt-1"><i class="fas fa-layer-group mr-1"></i>Kelola Template</a>
                </div>
                <div class="table-responsive" id="rkt-tpl-list">
                    <table class="table table-sm table-hover rkt-table">
                        <thead>
                            <tr>
                                <th style="width:34px"><input type="checkbox" id="tpl-check-all"></th>
                                <th>Kode</th>
                                <th>Bidang</th>
                                <th>Nama Program</th>
                                <th>Kegiatan</th>
                                <th>Target</th>
                                <th>Anggaran</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$tpl_rows): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">Belum ada template aktif. Tambahkan melalui Kelola Template RKT.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($tpl_rows as $t): ?>
                                <tr class="rkt-tpl-row" data-bidang="<?= htmlspecialchars($t['bidang_nama']) ?>" data-search="<?= htmlspecialchars(strtolower($t['kode'] . ' ' . $t['nama_program'] . ' ' . $t['bidang_nama'])) ?>">
                                    <td><input type="checkbox" class="tpl-check" value="<?= (int)$t['id'] ?>"></td>
                                    <td><small><?= htmlspecialchars($t['kode']) ?></small></td>
                                    <td><small><?= htmlspecialchars(rkt_excerpt($t['bidang_nama'], 30)) ?></small></td>
                                    <td>
                                        <div class="rkt-nama"><?= htmlspecialchars($t['nama_program']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars(rkt_excerpt($t['indikator'], 70)) ?></small>
                                    </td>
                                    <td class="text-center"><span class="badge badge-light border"><?= (int)$t['activity_count'] ?></span></td>
                                    <td><small><?= htmlspecialchars(rkt_excerpt($t['target'], 34)) ?></small></td>
                                    <td class="text-nowrap"><small><?= rktRupiah($t['estimasi_anggaran']) ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="alert alert-secondary py-2 mt-3 mb-0 d-flex flex-wrap justify-content-between align-items-center">
                    <span>Dipilih: <strong id="tpl-selected-count">0</strong> program</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-1" id="tpl-clear"><i class="fas fa-eraser mr-1"></i>Bersihkan Pilihan</button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-success" id="tpl-apply"><i class="fas fa-copy mr-1"></i>Terapkan ke RKT</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal RKJM -->
<div class="modal fade" id="rkjmModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ambil Program dari RKJM Tahun <?= (int)$doc['tahun'] ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border py-2">
                    Hanya program RKJM yang memiliki target pada tahun <strong><?= (int)$doc['tahun'] ?></strong> yang ditampilkan. Anggaran empat tahunan RKJM tidak disalin; anggaran tahunan RKT diisi terpisah.
                </div>
                <div class="form-group">
                    <label>Dokumen RKJM Sumber</label>
                    <select id="rkjm-doc-select" class="form-control">
                        <?php if (!$rkjmDocs): ?><option value="">Belum ada dokumen RKJM</option><?php endif; ?>
                        <?php foreach ($rkjmDocs as $rd): ?>
                            <option value="<?= (int)$rd['id'] ?>" <?= (int)($doc['rkjm_document_id'] ?? 0) === (int)$rd['id'] ? 'selected' : '' ?>>
                                RKJM <?= (int)$rd['tahun_awal'] ?>-<?= (int)$rd['tahun_akhir'] ?><?= !empty($rd['nomor_dokumen']) ? ' | ' . htmlspecialchars($rd['nomor_dokumen']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="table-responsive" id="rkt-rkjm-list">
                    <div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin mr-2"></i>Memuat program RKJM...</div>
                </div>
                <div class="alert alert-secondary py-2 mt-3 mb-0 d-flex flex-wrap justify-content-between align-items-center">
                    <span>Dipilih: <strong id="rkjm-selected-count">0</strong> program</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-1" id="rkjm-clear"><i class="fas fa-eraser mr-1"></i>Bersihkan Pilihan</button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-success" id="rkjm-apply"><i class="fas fa-project-diagram mr-1"></i>Tambahkan ke RKT</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include '../templates/footer.php'; ?>
<script>
(function(){
    var RKT = {
        csrf: <?= json_encode($csrf) ?>,
        docId: <?= $doc ? (int)$doc['id'] : 0 ?>,
        tahun: <?= (int)$activeYear ?>,
        editable: <?= $is_editable ? 'true' : 'false' ?>,
        hasDoc: <?= $doc ? 'true' : 'false' ?>,
        ajax: 'rkt_ajax.php',
        doc: <?= json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        defaults: <?= json_encode($docDefaults, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        school: {
            nama_madrasah: <?= json_encode((string)($school['nama_madrasah'] ?? '')) ?>,
            nama_kepala: <?= json_encode((string)($school['nama_kepala'] ?: ($school['kepala_madrasah'] ?? ''))) ?>
        }
    };
    window.RKT = RKT;

    function post(action, data){
        var payload = Object.assign({action: action, csrf_token: RKT.csrf, document_id: RKT.docId}, data || {});
        return $.ajax({
            url: RKT.ajax,
            type: 'POST',
            data: payload,
            dataType: 'json',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': RKT.csrf}
        });
    }
    window.RKTpost = post;

    function swalOk(msg){ Swal.fire({icon:'success', title:msg, timer:1800, showConfirmButton:false}); }
    function swalErr(msg){ Swal.fire({icon:'error', title:'Gagal', text: msg || 'Terjadi kesalahan'}); }
    function swalWarn(title, text){ Swal.fire({icon:'warning', title:title, text:text}); }
    function reload(){ setTimeout(function(){ location.reload(); }, 650); }
    function esc(s){ return $('<div>').text(s === null || s === undefined ? '' : s).html(); }

    function fmtRupiah(v){
        var s=(v+'').trim().replace(/[^0-9]/g,'').replace(/^0+(?=\d)/,'');
        return s ? s.replace(/\B(?=(\d{3})+(?!\d))/g,'.') : '';
    }
    $(document).on('input', 'input.rkt-rupiah', function(){ this.value = fmtRupiah(this.value); });

    $('#rkt-year-switch').on('change', function(){ location.href = 'rkt.php?tahun=' + $(this).val(); });

    function openDocForm(isNew){
        var d = RKT.doc || {};
        var f = $('#docForm');
        f[0].reset();
        f.find('[name=id]').val(isNew ? 0 : (d.id || 0));
        f.find('[name=nama_madrasah]').val(isNew ? (RKT.defaults.nama_madrasah || RKT.school.nama_madrasah) : (d.nama_madrasah || ''));
        f.find('[name=nomor_dokumen]').val(isNew ? '' : (d.nomor_dokumen || ''));
        f.find('[name=tahun]').val(isNew ? RKT.tahun : (d.tahun || RKT.tahun));
        f.find('[name=rkjm_document_id]').val(isNew ? (RKT.defaults.rkjm_document_id || '') : (d.rkjm_document_id || ''));
        f.find('[name=visi]').val(isNew ? '' : (d.visi || ''));
        f.find('[name=misi]').val(isNew ? '' : (d.misi || ''));
        f.find('[name=tujuan_pengembangan]').val(isNew ? '' : (d.tujuan_pengembangan || ''));
        f.find('[name=prioritas]').val(isNew ? '' : (d.prioritas || ''));
        f.find('[name=dasar_penyusunan]').val(isNew ? '' : (d.dasar_penyusunan || ''));
        f.find('[name=tanggal_penyusunan]').val(isNew ? '' : (d.tanggal_penyusunan || ''));
        f.find('[name=nama_kepala]').val(isNew ? (RKT.defaults.nama_kepala || RKT.school.nama_kepala) : (d.nama_kepala || ''));
        f.find('[name=status]').val(isNew ? 'draf' : (d.status || 'draf'));
        f.find('[name=catatan]').val(isNew ? '' : (d.catatan || ''));
        $('#docModalTitle').text(isNew ? 'Buat Dokumen RKT' : 'Pengaturan Dokumen RKT');
        $('#docModal').modal('show');
    }
    $('#btn-doc-form').on('click', function(){ openDocForm(!RKT.hasDoc); });
    $('#btn-create-doc').on('click', function(){ openDocForm(true); });

    $('#docForm').on('submit', function(e){
        e.preventDefault();
        var $btn = $(this).find('button[type=submit]').prop('disabled', true);
        post('save_document', $(this).serialize()).done(function(res){
            if (res.success) {
                swalOk(res.message || 'Dokumen disimpan');
                setTimeout(function(){ location.href = 'rkt.php?doc=' + (res.document_id || RKT.docId); }, 700);
            } else {
                swalErr(res.message);
                $btn.prop('disabled', false);
            }
        }).fail(function(xhr){
            swalErr((xhr.responseJSON && xhr.responseJSON.message) || 'Gagal menyimpan dokumen');
            $btn.prop('disabled', false);
        });
    });

    if (!RKT.editable || !RKT.hasDoc) return;

    function fillProgram(r){
        var f = $('#programForm');
        f.find('[name=id]').val(r.id || 0);
        f.find('[name=urutan]').val(r.urutan || 0);
        f.find('[name=field_id]').val(r.field_id || '');
        ['kode_program','nama_program','kondisi_awal','tujuan','sasaran','indikator','target_tahunan','penanggung_jawab','sumber_dana','output','monitoring_evaluasi','catatan'].forEach(function(k){
            f.find('[name='+k+']').val(r[k] || '');
        });
        f.find('[name=status]').val(r.status || 'belum_dimulai');
        f.find('[name=jadwal_mulai]').val(r.jadwal_mulai || '');
        f.find('[name=jadwal_selesai]').val(r.jadwal_selesai || '');
        f.find('[name=estimasi_anggaran]').val(r.estimasi_anggaran ? fmtRupiah(r.estimasi_anggaran) : '');

        var info = '';
        if (r.rkjm_program_id) {
            info = '<i class="fas fa-link mr-1"></i>Terhubung ke RKJM. Target tahunan RKJM: <strong>' + esc(r.rkjm_target || '-') + '</strong>.';
            if (r.target_mismatch) {
                info += ' <span class="text-danger font-weight-bold">Target RKT berbeda dari target RKJM.</span>';
            }
        } else {
            info = '<i class="fas fa-unlink mr-1"></i>Program ini belum terhubung ke RKJM.';
        }
        $('#program-rkjm-info').html(info).show();
        $('#programModalTitle').text(r.id ? 'Edit Program RKT' : 'Tambah Program RKT');
    }

    $('#btn-add-program').on('click', function(){
        $('#programForm')[0].reset();
        fillProgram({status:'belum_dimulai'});
        $('#programModal').modal('show');
    });

    $('#programForm').on('submit', function(e){
        e.preventDefault();
        var $btn = $(this).find('button[type=submit]').prop('disabled', true);
        post('save_program', $(this).serialize()).done(function(res){
            if (res.success) {
                $('#programModal').modal('hide');
                if (res.warning) {
                    Swal.fire({icon:'warning', title:'Target berbeda dari RKJM', text:res.warning, confirmButtonText:'Mengerti'}).then(function(){ reload(); });
                } else {
                    swalOk(res.message || 'Program disimpan');
                    reload();
                }
            } else {
                swalErr(res.message);
                $btn.prop('disabled', false);
            }
        }).fail(function(xhr){
            swalErr((xhr.responseJSON && xhr.responseJSON.message) || 'Gagal menyimpan program');
            $btn.prop('disabled', false);
        });
    });

    $(document).on('click', '.btn-rkt-edit', function(){
        post('get_program', {id: $(this).data('id')}).done(function(res){
            if (res.success) { fillProgram(res.data); $('#programModal').modal('show'); }
            else swalErr(res.message);
        }).fail(function(){ swalErr('Gagal memuat program'); });
    });

    $(document).on('change', '.rkt-status-sel', function(){
        var $s = $(this);
        post('update_status', {id: $s.data('id'), status: $s.val()}).done(function(res){
            if (res.success) { swalOk('Status diperbarui'); reload(); }
            else { swalErr(res.message); reload(); }
        }).fail(function(){ swalErr('Gagal memperbarui status'); });
    });

    $(document).on('click', '.btn-rkt-delete', function(){
        var id = $(this).data('id'), nm = $(this).data('name');
        Swal.fire({title:'Hapus program?', text:'Program "'+nm+'" beserta kegiatan dan monitoringnya akan dihapus.', icon:'warning', showCancelButton:true, confirmButtonColor:'#d33', confirmButtonText:'Ya, Hapus', cancelButtonText:'Batal'}).then(function(r){
            if (!r.isConfirmed) return;
            post('delete_program', {id: id}).done(function(res){
                if (res.success) { swalOk('Program dihapus'); reload(); } else swalErr(res.message);
            }).fail(function(){ swalErr('Gagal menghapus program'); });
        });
    });

    $(document).on('click', '.btn-rkt-duplicate', function(){
        post('duplicate_program', {id: $(this).data('id')}).done(function(res){
            if (res.success) { swalOk('Program diduplikasi'); reload(); } else swalErr(res.message);
        }).fail(function(){ swalErr('Gagal menduplikasi program'); });
    });

    // Detail program, kegiatan, monitoring
    var currentDetailId = 0;
    var lastDetail = null;

    function row(label, val){ return '<tr><th>'+label+'</th><td>'+esc(val || '-')+'</td></tr>'; }

    function renderDetail(d){
        lastDetail = d;
        var html = '<h6 class="font-weight-bold mb-1">'+esc(d.nama_program)+'</h6>';
        html += '<div class="text-muted small mb-2">'+esc(d.kode_program || '-')+' | '+esc(d.bidang_nama || '-')+'</div>';
        if (d.rkjm_referensi) {
            html += '<div class="alert alert-light border py-2"><i class="fas fa-link mr-1"></i>Referensi RKJM: '+esc(d.rkjm_referensi)+'</div>';
        } else {
            html += '<div class="alert alert-light border py-2"><i class="fas fa-unlink mr-1"></i>Belum Terhubung ke RKJM</div>';
        }
        html += '<div class="table-responsive"><table class="table table-sm table-bordered rkt-detail-table mb-0">';
        html += row('Kondisi Awal', d.kondisi_awal);
        html += row('Tujuan', d.tujuan);
        html += row('Sasaran', d.sasaran);
        html += row('Indikator Keberhasilan', d.indikator);
        html += row('Target Tahunan', d.target_tahunan);
        html += row('Jadwal', (d.jadwal_mulai || d.jadwal_selesai) ? (d.jadwal_mulai || '-') + ' s.d. ' + (d.jadwal_selesai || '-') : '-');
        html += row('Penanggung Jawab', d.penanggung_jawab);
        html += row('Estimasi Anggaran', 'Rp ' + fmtRupiah(d.estimasi_anggaran || 0));
        html += row('Sumber Dana', d.sumber_dana);
        html += row('Output', d.output);
        html += row('Monitoring dan Evaluasi', d.monitoring_evaluasi);
        html += row('Catatan', d.catatan);
        html += row('Status', d.status_label);
        if (d.rkjm_program_id) {
            html += row('Target Tahunan RKJM', d.rkjm_target || '-');
        }
        html += '</table></div>';

        html += '<div class="d-flex justify-content-between align-items-center mt-3 mb-2">';
        html += '<h6 class="font-weight-bold mb-0">Kegiatan</h6>';
        html += '<button type="button" class="btn btn-sm btn-primary btn-add-activity" data-program="'+d.id+'"><i class="fas fa-plus mr-1"></i>Tambah Kegiatan</button>';
        html += '</div>';
        html += '<div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr>';
        html += '<th>No.</th><th>Kegiatan</th><th>Target</th><th>Jadwal</th><th>PJ</th><th>Biaya</th><th>Sumber</th><th>Status</th><th></th>';
        html += '</tr></thead><tbody>';
        if (!d.activities || !d.activities.length) {
            html += '<tr><td colspan="9" class="text-center text-muted">Belum ada kegiatan.</td></tr>';
        }
        (d.activities || []).forEach(function(a, idx){
            html += '<tr>';
            html += '<td>'+(idx+1)+'</td>';
            html += '<td>'+esc(a.nama_kegiatan)+'</td>';
            html += '<td>'+esc((a.target_volume || '-') + (a.satuan ? ' ' + a.satuan : ''))+'</td>';
            html += '<td><small>'+esc((a.jadwal_mulai || '-') + ' s.d. ' + (a.jadwal_selesai || '-'))+'</small></td>';
            html += '<td><small>'+esc(a.penanggung_jawab || '-')+'</small></td>';
            html += '<td class="text-nowrap">'+ (a.estimasi_biaya ? 'Rp ' + fmtRupiah(a.estimasi_biaya) : '-') +'</td>';
            html += '<td><small>'+esc(a.sumber_dana || '-')+'</small></td>';
            html += '<td><span class="badge badge-light border">'+esc(a.status_label)+'</span></td>';
            html += '<td class="text-nowrap">';
            html += '<button type="button" class="btn btn-sm btn-light btn-act-edit" data-id="'+a.id+'" title="Edit"><i class="fas fa-edit"></i></button> ';
            html += '<button type="button" class="btn btn-sm btn-light btn-act-delete" data-id="'+a.id+'" data-name="'+esc(a.nama_kegiatan)+'" title="Hapus"><i class="fas fa-trash text-danger"></i></button> ';
            html += '<button type="button" class="btn btn-sm btn-light btn-act-monitor" data-id="'+a.id+'" data-name="'+esc(a.nama_kegiatan)+'" title="Monitoring"><i class="fas fa-clipboard-check"></i></button>';
            html += '</td></tr>';
        });
        html += '</tbody></table></div>';

        html += '<div class="d-flex justify-content-between align-items-center mt-3 mb-2">';
        html += '<h6 class="font-weight-bold mb-0">Monitoring dan Evaluasi</h6>';
        html += '<button type="button" class="btn btn-sm btn-info btn-program-monitor" data-id="'+d.id+'"><i class="fas fa-plus mr-1"></i>Catat Monitoring Program</button>';
        html += '</div>';
        html += '<div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr>';
        html += '<th>Tanggal</th><th>Cakupan</th><th>Status</th><th>Target</th><th>Realisasi</th><th>Capaian</th><th>Biaya</th><th>Kendala</th><th>Tindak Lanjut</th><th></th>';
        html += '</tr></thead><tbody>';
        if (!d.monitoring || !d.monitoring.length) {
            html += '<tr><td colspan="10" class="text-center text-muted">Belum ada catatan monitoring.</td></tr>';
        }
        (d.monitoring || []).forEach(function(m){
            var cap = (m.persentase_capaian !== null && m.persentase_capaian !== undefined && m.persentase_capaian !== '') ? (m.persentase_capaian + '%') : (m.penilaian_kualitatif || '-');
            html += '<tr>';
            html += '<td><small>'+esc(m.tanggal_pelaksanaan || '-')+'</small></td>';
            html += '<td><small>'+esc(m.activity_id ? (m.nama_kegiatan || 'Kegiatan') : 'Program')+'</small></td>';
            html += '<td><small>'+esc(m.status_label || '-')+'</small></td>';
            html += '<td style="white-space:pre-line">'+esc(m.target || '-')+'</td>';
            html += '<td style="white-space:pre-line">'+esc(m.realisasi || '-')+'</td>';
            html += '<td>'+esc(cap)+'</td>';
            html += '<td class="text-nowrap">'+ (m.realisasi_biaya ? 'Rp ' + fmtRupiah(m.realisasi_biaya) : '-') +'</td>';
            html += '<td style="white-space:pre-line">'+esc(m.kendala || '-')+'</td>';
            html += '<td style="white-space:pre-line">'+esc(m.tindak_lanjut || '-')+'</td>';
            html += '<td class="text-nowrap">';
            html += '<button type="button" class="btn btn-sm btn-light btn-mon-edit" data-id="'+m.id+'" title="Edit"><i class="fas fa-edit"></i></button> ';
            html += '<button type="button" class="btn btn-sm btn-light btn-mon-delete" data-id="'+m.id+'" title="Hapus"><i class="fas fa-trash text-danger"></i></button>';
            html += '</td></tr>';
        });
        html += '</tbody></table></div>';
        $('#detailBody').html(html);
    }

    function loadDetail(id, keepOpen){
        currentDetailId = id;
        if (!keepOpen) {
            $('#detailBody').html('<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin mr-2"></i>Memuat...</div>');
            $('#detailModal').modal('show');
        }
        post('get_program', {id: id}).done(function(res){
            if (!res.success) {
                $('#detailBody').html('<div class="text-danger">'+esc(res.message || 'Gagal memuat')+'</div>');
                return;
            }
            renderDetail(res.data);
        }).fail(function(){ $('#detailBody').html('<div class="text-danger">Gagal memuat detail program.</div>'); });
    }

    $(document).on('click', '.btn-rkt-detail', function(){ loadDetail($(this).data('id')); });

    $('#btn-detail-edit').on('click', function(){
        if (!lastDetail) return;
        $('#detailModal').modal('hide');
        fillProgram(lastDetail);
        $('#programModal').modal('show');
    });

    // Kegiatan
    function fillActivity(a){
        var f = $('#activityForm');
        f.find('[name=id]').val(a.id || 0);
        f.find('[name=program_id]').val(a.program_id || currentDetailId || 0);
        f.find('[name=urutan]').val(a.urutan || 0);
        ['nama_kegiatan','tujuan','indikator','target_volume','satuan','jadwal_mulai','jadwal_selesai','penanggung_jawab','sumber_dana','output'].forEach(function(k){
            f.find('[name='+k+']').val(a[k] || '');
        });
        f.find('[name=status]').val(a.status || 'belum_dimulai');
        f.find('[name=estimasi_biaya]').val(a.estimasi_biaya ? fmtRupiah(a.estimasi_biaya) : '');
        $('#activityModalTitle').text(a.id ? 'Edit Kegiatan' : 'Tambah Kegiatan');
    }

    $(document).on('click', '.btn-add-activity', function(){
        var pid = $(this).data('program');
        currentDetailId = pid;
        $('#activityForm')[0].reset();
        fillActivity({program_id: pid, status:'belum_dimulai'});
        $('#activityModal').modal('show');
    });

    $(document).on('click', '.btn-act-edit', function(){
        post('get_activity', {id: $(this).data('id')}).done(function(res){
            if (res.success) { fillActivity(res.data); $('#activityModal').modal('show'); }
            else swalErr(res.message);
        }).fail(function(){ swalErr('Gagal memuat kegiatan'); });
    });

    $('#activityForm').on('submit', function(e){
        e.preventDefault();
        var $btn = $(this).find('button[type=submit]').prop('disabled', true);
        post('save_activity', $(this).serialize()).done(function(res){
            if (res.success) {
                $('#activityModal').modal('hide');
                swalOk(res.message || 'Kegiatan disimpan');
                loadDetail(currentDetailId, true);
            } else {
                swalErr(res.message);
                $btn.prop('disabled', false);
            }
        }).fail(function(xhr){
            swalErr((xhr.responseJSON && xhr.responseJSON.message) || 'Gagal menyimpan kegiatan');
            $btn.prop('disabled', false);
        });
    });

    $(document).on('click', '.btn-act-delete', function(){
        var id = $(this).data('id'), nm = $(this).data('name');
        Swal.fire({title:'Hapus kegiatan?', text:'Kegiatan "'+nm+'" akan dihapus.', icon:'warning', showCancelButton:true, confirmButtonColor:'#d33', confirmButtonText:'Ya, Hapus', cancelButtonText:'Batal'}).then(function(r){
            if (!r.isConfirmed) return;
            post('delete_activity', {id: id}).done(function(res){
                if (res.success) { swalOk('Kegiatan dihapus'); loadDetail(currentDetailId, true); } else swalErr(res.message);
            }).fail(function(){ swalErr('Gagal menghapus kegiatan'); });
        });
    });

    // Monitoring
    function fillMonitoring(m, scopeLabel){
        var f = $('#monitoringForm');
        f[0].reset();
        f.find('[name=id]').val(m.id || 0);
        f.find('[name=program_id]').val(m.program_id || currentDetailId || 0);
        f.find('[name=activity_id]').val(m.activity_id || 0);
        f.find('[name=target]').val(m.target || '');
        f.find('[name=realisasi]').val(m.realisasi || '');
        f.find('[name=persentase_capaian]').val(m.persentase_capaian !== null && m.persentase_capaian !== undefined ? m.persentase_capaian : '');
        f.find('[name=penilaian_kualitatif]').val(m.penilaian_kualitatif || '');
        f.find('[name=status_pelaksanaan]').val(m.status_pelaksanaan || 'belum_dimulai');
        f.find('[name=tanggal_pelaksanaan]').val(m.tanggal_pelaksanaan || '');
        ['kendala','tindak_lanjut','output_aktual','bukti','catatan_evaluasi','petugas_evaluasi'].forEach(function(k){ f.find('[name='+k+']').val(m[k] || ''); });
        f.find('[name=realisasi_biaya]').val(m.realisasi_biaya ? fmtRupiah(m.realisasi_biaya) : '');
        $('#monitoring-scope').html('<i class="fas fa-info-circle mr-1"></i>Cakupan: <strong>'+esc(scopeLabel)+'</strong>. Persentase hanya diisi jika indikator kuantitatif dan target jelas.');
        $('#monitoringModalTitle').text(m.id ? 'Edit Monitoring' : 'Catat Monitoring');
    }

    $(document).on('click', '.btn-program-monitor', function(){
        if (!lastDetail) return;
        fillMonitoring({
            program_id: lastDetail.id,
            target: lastDetail.target_tahunan || '',
            status_pelaksanaan: lastDetail.status || 'belum_dimulai'
        }, 'Program: ' + lastDetail.nama_program);
        $('#monitoringModal').modal('show');
    });

    $(document).on('click', '.btn-act-monitor', function(){
        var aid = $(this).data('id');
        var name = $(this).data('name');
        var act = null;
        if (lastDetail && lastDetail.activities) {
            lastDetail.activities.forEach(function(a){ if (parseInt(a.id, 10) === parseInt(aid, 10)) act = a; });
        }
        fillMonitoring({
            program_id: lastDetail ? lastDetail.id : currentDetailId,
            activity_id: aid,
            target: act ? ((act.target_volume || '') + (act.satuan ? ' ' + act.satuan : '')) : '',
            status_pelaksanaan: act ? act.status : 'belum_dimulai'
        }, 'Kegiatan: ' + name);
        $('#monitoringModal').modal('show');
    });

    $(document).on('click', '.btn-mon-edit', function(){
        var id = $(this).data('id');
        var m = null;
        if (lastDetail && lastDetail.monitoring) {
            lastDetail.monitoring.forEach(function(x){ if (parseInt(x.id, 10) === parseInt(id, 10)) m = x; });
        }
        if (!m) { swalErr('Data monitoring tidak ditemukan'); return; }
        var scope = m.activity_id ? ('Kegiatan: ' + (m.nama_kegiatan || '')) : ('Program: ' + (lastDetail ? lastDetail.nama_program : ''));
        fillMonitoring(m, scope);
        $('#monitoringModal').modal('show');
    });

    $('#monitoringForm').on('submit', function(e){
        e.preventDefault();
        var $btn = $(this).find('button[type=submit]').prop('disabled', true);
        post('save_monitoring', $(this).serialize()).done(function(res){
            if (res.success) {
                $('#monitoringModal').modal('hide');
                swalOk(res.message || 'Monitoring disimpan');
                loadDetail(currentDetailId, true);
            } else {
                swalErr(res.message);
                $btn.prop('disabled', false);
            }
        }).fail(function(xhr){
            swalErr((xhr.responseJSON && xhr.responseJSON.message) || 'Gagal menyimpan monitoring');
            $btn.prop('disabled', false);
        });
    });

    $(document).on('click', '.btn-mon-delete', function(){
        var mid = $(this).data('id');
        Swal.fire({title:'Hapus catatan monitoring?', icon:'warning', showCancelButton:true, confirmButtonColor:'#d33', confirmButtonText:'Ya, Hapus', cancelButtonText:'Batal'}).then(function(r){
            if (!r.isConfirmed) return;
            post('delete_monitoring', {id: mid}).done(function(res){
                if (res.success) { swalOk('Monitoring dihapus'); loadDetail(currentDetailId, true); } else swalErr(res.message);
            }).fail(function(){ swalErr('Gagal menghapus monitoring'); });
        });
    });

    // Template
    function filterTemplates(){
        var q = ($('#tpl-search').val() || '').toLowerCase();
        var f = $('#tpl-field').val() || '';
        $('.rkt-tpl-row').each(function(){
            var okQ = !q || ($(this).data('search') || '').indexOf(q) >= 0;
            var okF = !f || $(this).data('bidang') === f;
            $(this).toggle(okQ && okF);
        });
        updateTplCount();
    }
    function updateTplCount(){
        $('#tpl-selected-count').text($('.tpl-check:checked').length);
    }
    $('#tpl-search, #tpl-field').on('input change', filterTemplates);
    $(document).on('change', '.tpl-check', updateTplCount);
    $('#tpl-check-all').on('change', function(){
        var on = this.checked;
        $('.rkt-tpl-row:visible .tpl-check').prop('checked', on);
        updateTplCount();
    });
    $('#tpl-clear').on('click', function(){
        $('.tpl-check').prop('checked', false);
        $('#tpl-check-all').prop('checked', false);
        updateTplCount();
    });
    $('#btn-use-template').on('click', function(){ $('#templateModal').modal('show'); });
    $('#tpl-apply').on('click', function(){
        var ids = $('.tpl-check:checked').map(function(){ return $(this).val(); }).get();
        if (!ids.length) { swalWarn('Belum ada pilihan', 'Pilih minimal satu template.'); return; }
        Swal.fire({title:'Terapkan template?', text:ids.length + ' program beserta kegiatannya akan ditambahkan ke RKT. Data RKT yang sudah ada tidak ditimpa.', icon:'question', showCancelButton:true, confirmButtonText:'Ya, Terapkan', cancelButtonText:'Batal'}).then(function(r){
            if (!r.isConfirmed) return;
            var $btn = $('#tpl-apply').prop('disabled', true);
            post('apply_templates', {template_ids: ids}).done(function(res){
                $btn.prop('disabled', false);
                if (res.success) { $('#templateModal').modal('hide'); swalOk(res.message || 'Template diterapkan'); reload(); }
                else swalErr(res.message);
            }).fail(function(xhr){ $btn.prop('disabled', false); swalErr((xhr.responseJSON && xhr.responseJSON.message) || 'Gagal menerapkan template'); });
        });
    });

    // RKJM
    function renderRkjmRows(rows){
        var html = '<table class="table table-sm table-hover"><thead><tr><th style="width:34px"></th><th>Bidang</th><th>Program</th><th>Target <?= (int)$activeYear ?></th><th>Indikator</th><th>Status</th></tr></thead><tbody>';
        if (!rows || !rows.length) {
            html += '<tr><td colspan="6" class="text-center text-muted py-4">Tidak ada program RKJM dengan target pada tahun ini.</td></tr>';
        }
        (rows || []).forEach(function(r){
            html += '<tr class="'+(r.imported ? 'table-light' : '')+'">';
            html += '<td><input type="checkbox" class="rkjm-check" value="'+r.id+'" '+(r.imported ? 'disabled' : '')+'></td>';
            html += '<td><small>'+esc(r.bidang_nama || '-')+'</small></td>';
            html += '<td><div class="font-weight-bold">'+esc(r.nama_program)+'</div><small class="text-muted">'+esc(r.kode_program || '-')+'</small></td>';
            html += '<td>'+esc(r.target_tahunan || '-')+'</td>';
            html += '<td><small>'+esc(r.indikator || '-')+'</small></td>';
            html += '<td>'+ (r.imported ? '<span class="badge badge-light border">Sudah di RKT</span>' : '<span class="badge badge-success">Tersedia</span>') +'</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        $('#rkt-rkjm-list').html(html);
        updateRkjmCount();
    }
    function updateRkjmCount(){
        $('#rkjm-selected-count').text($('.rkjm-check:checked').length);
    }
    function loadRkjmPrograms(){
        $('#rkt-rkjm-list').html('<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin mr-2"></i>Memuat program RKJM...</div>');
        post('rkjm_programs', {rkjm_document_id: $('#rkjm-doc-select').val() || 0}).done(function(res){
            if (!res.success) { $('#rkt-rkjm-list').html('<div class="text-danger">'+esc(res.message || 'Gagal memuat')+'</div>'); return; }
            renderRkjmRows(res.data || []);
        }).fail(function(xhr){ $('#rkt-rkjm-list').html('<div class="text-danger">'+esc((xhr.responseJSON && xhr.responseJSON.message) || 'Gagal memuat program RKJM')+'</div>'); });
    }
    $('#btn-import-rkjm').on('click', function(){ $('#rkjmModal').modal('show'); loadRkjmPrograms(); });
    $('#rkjm-doc-select').on('change', loadRkjmPrograms);
    $(document).on('change', '.rkjm-check', updateRkjmCount);
    $('#rkjm-clear').on('click', function(){ $('.rkjm-check').prop('checked', false); updateRkjmCount(); });
    $('#rkjm-apply').on('click', function(){
        var ids = $('.rkjm-check:checked').map(function(){ return $(this).val(); }).get();
        if (!ids.length) { swalWarn('Belum ada pilihan', 'Pilih minimal satu program RKJM.'); return; }
        Swal.fire({title:'Ambil program RKJM?', text:ids.length + ' program akan disalin ke RKT tahun ' + RKT.tahun + '. Anggaran tahunan tidak disalin otomatis.', icon:'question', showCancelButton:true, confirmButtonText:'Ya, Ambil', cancelButtonText:'Batal'}).then(function(r){
            if (!r.isConfirmed) return;
            var $btn = $('#rkjm-apply').prop('disabled', true);
            post('apply_rkjm', {rkjm_document_id: $('#rkjm-doc-select').val() || 0, rkjm_program_ids: ids}).done(function(res){
                $btn.prop('disabled', false);
                if (res.success) { $('#rkjmModal').modal('hide'); swalOk(res.message || 'Program RKJM ditambahkan'); reload(); }
                else swalErr(res.message);
            }).fail(function(xhr){ $btn.prop('disabled', false); swalErr((xhr.responseJSON && xhr.responseJSON.message) || 'Gagal mengambil program RKJM'); });
        });
    });
})();
</script>
