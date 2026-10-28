<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/rkjm_helper.php';

if (session_status() == PHP_SESSION_NONE) session_start();
if (!isAuthorized(['admin', 'kepala_madrasah'])) redirect('../login.php');

$user_level = getUserLevel();
$is_editable = in_array($user_level, ['admin', 'kepala_madrasah'], true);
$page_title = 'Rencana Kerja Jangka Menengah (RKJM)';

rkjmEnsureSchema($pdo);

$school_profile = getSchoolProfile($pdo);
$fields = rkjmFields($pdo);

$profil_madrasah = trim((string)($school_profile['nama_madrasah'] ?? ''));
$profil_kepala = trim((string)($school_profile['nama_kepala'] ?? ''));
if ($profil_kepala === '') {
    $profil_kepala = trim((string)($school_profile['kepala_madrasah'] ?? ''));
}

$docId = (int)($_GET['doc'] ?? 0);
$doc = rkjmGetDocument($pdo, $docId);
$docs = $pdo->query("SELECT id, tahun_awal, tahun_akhir, status FROM rkjm_documents ORDER BY tahun_awal DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
$years = $doc ? rkjmYears($doc) : [];
$summary = $doc ? rkjmSummary($pdo, (int)$doc['id']) : ['total' => 0, 'terlaksana' => 0, 'berjalan' => 0, 'belum' => 0, 'tidak_tercapai' => 0, 'ditunda' => 0, 'anggaran' => 0, 'per_bidang' => []];
$doc_status_opts = rkjmDocStatusOptions();
$prog_status_opts = rkjmProgramStatusOptions();

$csrf = appCsrfToken();

// Filter & sorting
$q = trim((string)($_GET['q'] ?? ''));
$f_field = (int)($_GET['bidang'] ?? 0);
$f_tahun = (int)($_GET['tahun'] ?? 0);
$f_status = trim((string)($_GET['status'] ?? ''));
if ($f_status !== '' && !isset($prog_status_opts[$f_status])) $f_status = '';
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
];
$order_by = $sort_map[$sort] ?? 'p.urutan';
if (($sort_map[$sort] ?? '') === 'p.urutan') $dir = 'ASC';

$rows = [];
$total_rows = 0;
$total_pages = 1;

if ($doc) {
    $where = ['p.document_id = ?'];
    $params = [(int)$doc['id']];
    if ($q !== '') {
        $where[] = "(p.nama_program LIKE ? OR p.kode_program LIKE ? OR p.bidang_nama LIKE ? OR p.indikator LIKE ?)";
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($f_field > 0) {
        $where[] = "p.field_id = ?";
        $params[] = $f_field;
    }
    if ($f_tahun > 0) {
        $where[] = "EXISTS (SELECT 1 FROM rkjm_program_targets t WHERE t.program_id = p.id AND t.tahun_kalender = ? AND COALESCE(t.target,'') <> '')";
        $params[] = $f_tahun;
    }
    if ($f_status !== '') {
        $where[] = "p.status = ?";
        $params[] = $f_status;
    }
    $where_sql = implode(' AND ', $where);

    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM rkjm_programs p WHERE $where_sql");
    $count_stmt->execute($params);
    $total_rows = (int)$count_stmt->fetchColumn();
    $total_pages = max(1, (int)ceil($total_rows / $per_page));
    if ($page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;

    $list_stmt = $pdo->prepare("SELECT p.* FROM rkjm_programs p WHERE $where_sql ORDER BY $order_by $dir, p.id ASC LIMIT $per_page OFFSET $offset");
    $list_stmt->execute($params);
    $rows = $list_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$row_ids = array_map(static fn($r) => (int)$r['id'], $rows);
$targets_map = $row_ids ? rkjmTargetsByProgram($pdo, $row_ids) : [];

$total_templates = (int)$pdo->query("SELECT COUNT(*) FROM rkjm_template_programs")->fetchColumn();
$active_templates = (int)$pdo->query("SELECT COUNT(*) FROM rkjm_template_programs WHERE is_active = 1")->fetchColumn();

$templates_by_field = [];
$tpl_rows = $pdo->query("SELECT id, kode, field_id, bidang_nama, nama_program, indikator, estimasi_anggaran, is_active FROM rkjm_template_programs WHERE is_active = 1 ORDER BY bidang_nama ASC, kode ASC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($tpl_rows as $t) {
    $templates_by_field[$t['bidang_nama'] ?: 'Lainnya'][] = $t;
}

function rkjm_excerpt(?string $text, int $len = 60): string
{
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));
    if ($text === '') return '-';
    if (function_exists('mb_strlen') && mb_strlen($text) > $len) {
        return mb_substr($text, 0, $len) . '…';
    }
    return strlen($text) > $len ? substr($text, 0, $len) . '…' : $text;
}

function rkjm_qs(array $override = []): string
{
    $params = array_merge($_GET, $override);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) unset($params[$k]);
    }
    $qs = http_build_query($params);
    return $qs !== '' ? '?' . $qs : '';
}

// Editor kaya teks (TinyMCE) untuk Visi, Misi, Tujuan, Profil Singkat, dan Dasar Penyusunan.
$js_libs = [
    'https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js',
];

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<style>
.rkjm-hero{background:linear-gradient(135deg,#4f5bd5 0%,#6a4fd8 55%,#8e54e9 100%);border-radius:18px;color:#fff;padding:24px;box-shadow:0 10px 30px rgba(79,91,213,.25);position:relative;overflow:hidden}
.rkjm-hero::after{content:'';position:absolute;right:-30px;top:-30px;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.08);pointer-events:none}
.rkjm-hero h1{color:#fff;font-size:1.45rem;font-weight:800;margin-bottom:6px;letter-spacing:-.01em}
.rkjm-hero p{color:rgba(255,255,255,.88);margin-bottom:12px;font-size:.9rem}
.rkjm-hero-badges{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px}
.rkjm-hero .badge-soft{background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.3);color:#fff;font-weight:600;border-radius:999px;padding:5px 13px;font-size:.78rem;display:inline-flex;align-items:center}
.rkjm-hero-toolbar{background:rgba(0,0,0,.15);border:1px solid rgba(255,255,255,.18);border-radius:14px;padding:12px 14px;display:flex;flex-wrap:wrap;align-items:center;gap:10px}
.rkjm-hero-toolbar .btn{border-radius:10px;font-weight:600;font-size:.82rem;padding:7px 14px;display:inline-flex;align-items:center;gap:6px;border:none;box-shadow:0 2px 6px rgba(0,0,0,.12);transition:transform .15s,box-shadow .15s}
.rkjm-hero-toolbar .btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.18)}
.rkjm-hero-toolbar .form-control{border-radius:10px;height:36px;font-size:.82rem;font-weight:600;border:1px solid rgba(255,255,255,.3);background:#fff;color:#2b3252}
.rkjm-chip{display:inline-flex;align-items:center;gap:6px;background:#eef1ff;border:1px solid #d9def8;color:#4b54a8;border-radius:999px;padding:4px 10px;font-size:.75rem;margin:0 6px 6px 0}
/* Program cards – RKJM (3 kolom di desktop, 2 di tablet, 1 di mobile) */
.rkjm-card-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
.rkjm-card{background:#fff;border:none;border-radius:16px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 8px 24px rgba(15,23,42,.07),0 2px 6px rgba(15,23,42,.03);transition:transform .18s,box-shadow .18s;position:relative}
.rkjm-card:hover{transform:translateY(-3px);box-shadow:0 14px 32px rgba(15,23,42,.12)}
.rkjm-card-head{padding:14px 16px 12px;background:#f8faff;border-bottom:1px solid rgba(15,23,42,.05)}
.rkjm-card-head-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px}
.rkjm-card-head .rkjm-kode{font-size:.72rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#4f5bd5}
.rkjm-card-head .rkjm-nama{font-weight:800;font-size:.95rem;color:#0f172a;line-height:1.35}
.rkjm-card-head .rkjm-bidang{display:inline-block;margin-top:6px;font-size:.7rem;font-weight:700;background:rgba(79,91,213,.12);color:#4338ca;border-radius:999px;padding:3px 10px}
.rkjm-status-pill{display:inline-flex;align-items:center;gap:5px;font-size:.7rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase;padding:3px 10px;border-radius:999px;line-height:1.4}
.rkjm-status-pill.status-belum_dimulai{background:#f1f5f9;color:#475569;border:1px solid #cbd5e1}
.rkjm-status-pill.status-berjalan{background:#fef3c7;color:#b45309;border:1px solid #fde68a}
.rkjm-status-pill.status-tercapai{background:#dcfce7;color:#15803d;border:1px solid #bbf7d0}
.rkjm-status-pill.status-ditunda{background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd}
.rkjm-status-pill.status-tidak_tercapai{background:#fee2e2;color:#b91c1c;border:1px solid #fecaca}
.rkjm-card-body{padding:14px 16px;flex:1;display:flex;flex-direction:column;gap:10px;font-size:.8rem}
.rkjm-card-body .rkjm-field-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.rkjm-card-body .rkjm-field-label{font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin-bottom:2px}
.rkjm-card-body .rkjm-field-val{color:#1e293b;line-height:1.4;word-break:break-word}
.rkjm-card-body .rkjm-field-val.ellipsis{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.rkjm-card-foot{padding:12px 16px;background:#fafbfe;border-top:1px solid rgba(15,23,42,.05);display:flex;flex-direction:column;gap:10px}
.rkjm-card-foot .rkjm-tahun-chip{font-size:.7rem;font-weight:700;background:#dcfce7;color:#15803d;border-radius:999px;padding:3px 9px;align-self:flex-start}
.rkjm-card-foot .rkjm-actions{display:flex;gap:8px;align-items:center;width:100%}
.rkjm-card-foot .rkjm-actions .btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;font-size:.78rem;font-weight:600;white-space:nowrap;height:34px;padding:0 12px;border-radius:10px;border:none;box-shadow:0 1px 3px rgba(0,0,0,.08);transition:transform .12s,box-shadow .12s}
.rkjm-card-foot .rkjm-actions .btn:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.15)}
.rkjm-card-foot .rkjm-actions .btn-rkjm-detail{flex:1 1 0;background:#0ea5e9;color:#fff}
.rkjm-card-foot .rkjm-actions .btn-rkjm-detail:hover{background:#0284c7;color:#fff}
.rkjm-card-foot .rkjm-actions .btn-rkjm-edit{flex:1 1 0;background:#f59e0b;color:#fff}
.rkjm-card-foot .rkjm-actions .btn-rkjm-edit:hover{background:#d97706;color:#fff}
.rkjm-card-foot .rkjm-actions .btn-icon-only{flex:0 0 34px;width:34px;padding:0}
.rkjm-card-foot .rkjm-actions .btn-rkjm-duplicate{background:#e2e8f0;color:#334155}
.rkjm-card-foot .rkjm-actions .btn-rkjm-duplicate:hover{background:#cbd5e1;color:#1e293b}
.rkjm-card-foot .rkjm-actions .btn-rkjm-delete{background:#ef4444;color:#fff}
.rkjm-card-foot .rkjm-actions .btn-rkjm-delete:hover{background:#dc2626;color:#fff}
.rkjm-card-foot .rkjm-actions .btn-text{display:inline}
.rkjm-card-anggaran{font-weight:800;color:#0f172a;font-size:.88rem}
.rkjm-card-sumber{font-size:.72rem;color:#64748b}
@media(max-width:1199.98px){
  .rkjm-card-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:767.98px){
  .rkjm-card-grid{grid-template-columns:1fr}
}
@media(max-width:575.98px){
  .rkjm-hero{padding:16px}
  .rkjm-hero-toolbar{flex-direction:column;align-items:stretch;gap:8px}
  .rkjm-hero-toolbar .btn{justify-content:center;width:100%}
  .rkjm-card-body .rkjm-field-row{grid-template-columns:1fr}
}
.rkjm-section-title{font-weight:700;color:#3b4266;font-size:.85rem;text-transform:uppercase;letter-spacing:.04em;margin:18px 0 10px;padding-bottom:6px;border-bottom:1px solid #eceff7}
.rkjm-modal textarea{min-height:70px;resize:vertical}
/* TinyMCE: toolbar penuh, daftar bullet/numbering rapi, tampil di atas modal */
.rkjm-modal .tox-tinymce{border-radius:8px;border-color:#dfe3f0}
.rkjm-modal .tox .tox-toolbar__group{padding:0 4px}
.rkjm-modal .tox .tox-toolbar-overlord{background:#f7f8fc}
.rkjm-modal .tox .tox-edit-area__iframe{background:#fff}
.tox .tox-dialog-wrap{z-index:2070}
.tox-fullscreen{z-index:2075 !important}
/* Pemilih template: kartu berubah warna saat dipilih */
.rkjm-tpl-item{background:#fff;cursor:pointer;transition:background .15s ease,border-color .15s ease,box-shadow .15s ease;padding:.55rem .85rem .55rem 2.4rem !important}
.rkjm-tpl-item:hover{background:#f6f8ff;border-color:#c9d2f2 !important}
.rkjm-tpl-item .custom-control-label{padding-left:0;cursor:pointer}
.rkjm-tpl-item .custom-control-label::before{left:-1.65rem;top:.15rem;width:1.15rem;height:1.15rem;border:2px solid #b9c2dc;background:#fff;border-radius:4px}
.rkjm-tpl-item .custom-control-label::after{left:-1.65rem;top:.15rem;width:1.15rem;height:1.15rem}
.rkjm-tpl-item.is-selected{background:#e9eeff;border-color:#6777ef !important;box-shadow:0 0 0 3px rgba(103,119,239,.16)}
.rkjm-tpl-item.is-selected .custom-control-label::before{background-color:#6777ef;border-color:#6777ef}
.rkjm-tpl-item.is-selected strong{color:#3f49a8}
.rkjm-tpl-item.is-selected .small{color:#5a63a8 !important}
.rkjm-empty{border:2px dashed #dfe3f0;border-radius:14px;padding:36px;text-align:center;background:#fbfcff}
</style>
<div class="main-content">
<section class="section">
    <div class="section-header">
        <h1>Rencana Kerja Jangka Menengah (RKJM)</h1>
        <div class="section-header-breadcrumb">
            <div class="breadcrumb-item active"><a href="<?= htmlspecialchars(app_url('kepala/dashboard.php')) ?>">Dashboard</a></div>
            <div class="breadcrumb-item">Program Kerja</div>
            <div class="breadcrumb-item">RKJM</div>
        </div>
    </div>

    <div class="section-body">
        <div class="rkjm-hero mb-3">
            <h1>Rencana Kerja Jangka Menengah (RKJM)</h1>
            <p>Menyusun, memantau, dan mengevaluasi rencana pengembangan madrasah dalam periode empat tahun.</p>
            <div class="rkjm-hero-badges">
                <?php if ($doc): ?>
                    <span class="badge-soft"><i class="fas fa-calendar-alt mr-1"></i>Periode <?= (int)$doc['tahun_awal'] ?>–<?= (int)$doc['tahun_akhir'] ?> (<?= count($years) ?> tahun)</span>
                    <span class="badge-soft"><i class="fas fa-file-signature mr-1"></i><?= htmlspecialchars($doc_status_opts[$doc['status']] ?? $doc['status']) ?></span>
                    <?php if (!empty($doc['nomor_dokumen'])): ?><span class="badge-soft"><i class="fas fa-hashtag mr-1"></i><?= htmlspecialchars($doc['nomor_dokumen']) ?></span><?php endif; ?>
                <?php else: ?>
                    <span class="badge-soft"><i class="fas fa-info-circle mr-1"></i>Dokumen RKJM belum dibuat</span>
                <?php endif; ?>
            </div>
            <div class="rkjm-hero-toolbar">
                <?php if (count($docs) > 1): ?>
                    <div style="min-width: 170px;">
                        <select id="rkjm-doc-switch" class="form-control">
                            <?php foreach ($docs as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" <?= $doc && (int)$doc['id'] === (int)$d['id'] ? 'selected' : '' ?>>
                                    Periode <?= (int)$d['tahun_awal'] ?>–<?= (int)$d['tahun_akhir'] ?> (<?= htmlspecialchars($doc_status_opts[$d['status']] ?? $d['status']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <button type="button" class="btn btn-light" id="btn-doc-settings"><i class="fas fa-cog"></i><?= $doc ? 'Pengaturan RKJM' : 'Buat Dokumen' ?></button>
                <?php if ($doc): ?>
                    <button type="button" class="btn btn-warning" id="btn-add-program"><i class="fas fa-plus"></i>Tambah Program</button>
                    <button type="button" class="btn btn-success" id="btn-use-template"><i class="fas fa-copy"></i>Gunakan Template</button>
                    <a href="rkjm_template.php" class="btn btn-info"><i class="fas fa-layer-group"></i>Kelola Template</a>
                    <a href="cetak_rkjm.php?doc=<?= (int)$doc['id'] ?>" target="_blank" class="btn btn-primary"><i class="fas fa-print"></i>Cetak RKJM</a>
                <?php else: ?>
                    <a href="rkjm_template.php" class="btn btn-info"><i class="fas fa-layer-group"></i>Kelola Template</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$doc): ?>
            <div class="rkjm-empty">
                <i class="fas fa-calendar-plus fa-3x text-muted mb-3"></i>
                <h5>Belum ada dokumen RKJM</h5>
                <p class="text-muted mb-3">Tentukan periode empat tahun terlebih dahulu, lalu gunakan template bawaan sebagai titik awal.</p>
                <button type="button" class="btn btn-primary" id="btn-create-doc"><i class="fas fa-plus mr-1"></i>Buat Dokumen RKJM</button>
            </div>
        <?php else: ?>
            <div class="row dash-stat-row">
                <div class="col-12 col-sm-6 col-lg-4 mb-3">
                    <div class="dash-stat-card tint-blue">
                        <div class="dash-stat-ico dsi-blue"><i class="fas fa-tasks"></i></div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-label">Total Program</div>
                            <div class="dash-stat-value"><?= (int)$summary['total'] ?></div>
                            <div class="dash-stat-sub"><span><i class="fas fa-layer-group" style="color:#2563eb;"></i> Periode 4 tahun</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-lg-4 mb-3">
                    <div class="dash-stat-card tint-green">
                        <div class="dash-stat-ico dsi-green"><i class="fas fa-check-circle"></i></div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-label">Terlaksana</div>
                            <div class="dash-stat-value"><?= (int)$summary['terlaksana'] ?></div>
                            <div class="dash-stat-sub"><span style="color:#15803d;"><?= (int)$summary['total'] > 0 ? round((int)$summary['terlaksana'] / (int)$summary['total'] * 100) : 0 ?>% dari total</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-lg-4 mb-3">
                    <div class="dash-stat-card tint-amber">
                        <div class="dash-stat-ico dsi-amber"><i class="fas fa-sync"></i></div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-label">Sedang Berjalan</div>
                            <div class="dash-stat-value"><?= (int)$summary['berjalan'] ?></div>
                            <div class="dash-stat-sub"><span style="color:#d97706;">dalam pelaksanaan</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-lg-4 mb-3">
                    <div class="dash-stat-card tint-indigo">
                        <div class="dash-stat-ico dsi-indigo"><i class="fas fa-clock"></i></div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-label">Belum Dimulai</div>
                            <div class="dash-stat-value"><?= (int)$summary['belum'] ?></div>
                            <div class="dash-stat-sub"><span style="color:#4338ca;">rencana tindak lanjut</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-lg-4 mb-3">
                    <div class="dash-stat-card tint-violet">
                        <div class="dash-stat-ico dsi-violet"><i class="fas fa-money-bill-wave"></i></div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-label">Estimasi Anggaran</div>
                            <div class="dash-stat-value dash-stat-value-sm" style="font-size:1.15rem;"><?= rkjmRupiah($summary['anggaran']) ?></div>
                            <div class="dash-stat-sub"><span style="color:#7c3aed;">pagu 4 tahun</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-lg-4 mb-3">
                    <div class="dash-stat-card tint-teal">
                        <div class="dash-stat-ico dsi-teal"><i class="fas fa-sitemap"></i></div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-label">Bidang Terisi</div>
                            <div class="dash-stat-value"><?= count($summary['per_bidang']) ?></div>
                            <div class="dash-stat-sub"><span style="color:#0891b2;">bidang pengembangan</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($summary['per_bidang'])): ?>
            <div class="mb-3">
                <?php foreach ($summary['per_bidang'] as $pb): ?>
                    <span class="rkjm-chip"><i class="fas fa-tag"></i><?= htmlspecialchars($pb['bidang']) ?> <strong>(<?= (int)$pb['c'] ?>)</strong></span>
                <?php endforeach; ?>
                <?php if ($summary['tidak_tercapai'] > 0): ?><span class="rkjm-chip" style="background:#fdecec;border-color:#f7c6c6;color:#c0392b"><i class="fas fa-times-circle"></i>Tidak Tercapai <strong>(<?= (int)$summary['tidak_tercapai'] ?>)</strong></span><?php endif; ?>
                <?php if ($summary['ditunda'] > 0): ?><span class="rkjm-chip"><i class="fas fa-pause-circle"></i>Ditunda <strong>(<?= (int)$summary['ditunda'] ?>)</strong></span><?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
                    <h4 class="mb-2 mb-md-0">Daftar Program RKJM <?= (int)$doc['tahun_awal'] ?>–<?= (int)$doc['tahun_akhir'] ?></h4>
                    <div class="text-muted small"><?= (int)$active_templates ?> dari <?= (int)$total_templates ?> template aktif</div>
                </div>
                <div class="card-body">
                    <form method="GET" class="mb-3" id="rkjm-filter-form">
                        <input type="hidden" name="doc" value="<?= (int)$doc['id'] ?>">
                        <div class="row">
                            <div class="col-12 col-md-4 mb-2">
                                <input type="search" name="q" class="form-control" placeholder="Cari program, kode, indikator..." value="<?= htmlspecialchars($q) ?>">
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
                                <select name="tahun" class="form-control">
                                    <option value="">Semua Tahun</option>
                                    <?php foreach ($years as $tk => $th): ?>
                                        <option value="<?= (int)$th ?>" <?= $f_tahun === (int)$th ? 'selected' : '' ?>>Tahun <?= (int)$tk ?> (<?= (int)$th ?>)</option>
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
                                <select name="per_page" class="form-control">
                                    <?php foreach ([10, 25, 50, 100] as $pp): ?>
                                        <option value="<?= $pp ?>" <?= $per_page === $pp ? 'selected' : '' ?>><?= $pp ?> / halaman</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap">
                            <button class="btn btn-primary mr-2 mb-1" type="submit"><i class="fas fa-filter mr-1"></i>Terapkan</button>
                            <a class="btn btn-light mb-1" href="rkjm.php?doc=<?= (int)$doc['id'] ?>"><i class="fas fa-undo mr-1"></i>Reset</a>
                        </div>
                    </form>


                    <?php if (!$rows): ?>
                    <div class="rkjm-empty my-2">
                        <i class="fas fa-inbox fa-2x text-muted mb-2 d-block"></i>
                        <div class="text-muted">Belum ada program pada filter ini. Tambah program manual atau gunakan template.</div>
                    </div>
                    <?php else: ?>
                    <div class="rkjm-card-grid mb-3">
                    <?php $no = ($page - 1) * $per_page; foreach ($rows as $r): $no++;
                        $rt = $targets_map[(int)$r['id']] ?? [];
                        $tahun_label = [];
                        foreach ($years as $tk => $th) {
                            if (!empty($rt[$tk]['target'])) $tahun_label[] = 'Y'.$tk.'('.$th.')';
                        }
                        $status_key = $r['status'];
                    ?>
                    <div class="rkjm-card">
                        <div class="rkjm-card-head">
                            <div class="rkjm-card-head-top">
                                <span class="rkjm-kode"><?= htmlspecialchars($r['kode_program'] ?: '—') ?></span>
                                <span class="rkjm-status-pill status-<?= htmlspecialchars($r['status']) ?>">
                                    <i class="fas fa-circle" style="font-size:.5rem;"></i>
                                    <?= htmlspecialchars($prog_status_opts[$r['status']] ?? $r['status']) ?>
                                </span>
                            </div>
                            <div class="rkjm-nama"><?= htmlspecialchars($r['nama_program']) ?></div>
                            <span class="rkjm-bidang"><?= htmlspecialchars(rkjm_excerpt($r['bidang_nama'], 40)) ?></span>
                        </div>
                        <div class="rkjm-card-body">
                            <div class="rkjm-field-row">
                                <div>
                                    <div class="rkjm-field-label">Kondisi Awal</div>
                                    <div class="rkjm-field-val ellipsis" title="<?= htmlspecialchars((string)$r['kondisi_awal']) ?>"><?= htmlspecialchars(rkjm_excerpt($r['kondisi_awal'], 120)) ?></div>
                                </div>
                                <div>
                                    <div class="rkjm-field-label">Tujuan</div>
                                    <div class="rkjm-field-val ellipsis" title="<?= htmlspecialchars((string)$r['tujuan']) ?>"><?= htmlspecialchars(rkjm_excerpt($r['tujuan'], 120)) ?></div>
                                </div>
                            </div>
                            <div>
                                <div class="rkjm-field-label">Indikator Keberhasilan</div>
                                <div class="rkjm-field-val ellipsis" title="<?= htmlspecialchars((string)$r['indikator']) ?>"><?= htmlspecialchars(rkjm_excerpt($r['indikator'], 160)) ?></div>
                            </div>
                            <div class="rkjm-field-row">
                                <div>
                                    <div class="rkjm-field-label">Penanggung Jawab</div>
                                    <div class="rkjm-field-val"><?= htmlspecialchars(rkjm_excerpt($r['penanggung_jawab'], 36)) ?></div>
                                </div>
                                <div>
                                    <div class="rkjm-field-label">Anggaran</div>
                                    <div class="rkjm-card-anggaran"><?= rkjmRupiah($r['estimasi_anggaran']) ?></div>
                                    <div class="rkjm-card-sumber"><?= htmlspecialchars(rkjm_excerpt($r['sumber_dana'], 30)) ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="rkjm-card-foot">
                            <?php if ($tahun_label): ?>
                                <span class="rkjm-tahun-chip"><i class="fas fa-calendar-alt mr-1"></i><?= htmlspecialchars(implode(' · ', $tahun_label)) ?></span>
                            <?php else: ?>
                                <span class="rkjm-tahun-chip" style="background:#fdf0e6;border-color:#f4c07a;color:#9a5d0a"><i class="fas fa-exclamation-triangle mr-1"></i>Belum ada target</span>
                            <?php endif; ?>
                            <div class="rkjm-actions">
                                <button type="button" class="btn btn-rkjm-detail btn-sm" data-id="<?= (int)$r['id'] ?>" title="Lihat detail">
                                    <i class="fas fa-eye"></i><span class="btn-text"> Detail</span>
                                </button>
                                <?php if ($is_editable): ?>
                                    <button type="button" class="btn btn-rkjm-edit btn-sm" data-id="<?= (int)$r['id'] ?>" title="Edit">
                                        <i class="fas fa-edit"></i><span class="btn-text"> Edit</span>
                                    </button>
                                    <button type="button" class="btn btn-rkjm-duplicate btn-icon-only btn-sm" data-id="<?= (int)$r['id'] ?>" title="Duplikasi">
                                        <i class="fas fa-clone"></i>
                                    </button>
                                    <button type="button" class="btn btn-rkjm-delete btn-icon-only btn-sm" data-id="<?= (int)$r['id'] ?>" data-name="<?= htmlspecialchars($r['nama_program'], ENT_QUOTES) ?>" title="Hapus">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex flex-wrap justify-content-between align-items-center">
                        <div class="text-muted small mb-2">Menampilkan <?= $total_rows ? (($page - 1) * $per_page + 1) : 0 ?>–<?= min($total_rows, $page * $per_page) ?> dari <?= $total_rows ?> program</div>
                        <?php if ($total_pages > 1): ?>
                        <nav class="mb-2">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars(rkjm_qs(['page' => max(1, $page - 1)])) ?>">&laquo;</a></li>
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= htmlspecialchars(rkjm_qs(['page' => $i])) ?>"><?= $i ?></a></li>
                                <?php endfor; ?>
                                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars(rkjm_qs(['page' => min($total_pages, $page + 1)])) ?>">&raquo;</a></li>
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

<!-- Modal Pengaturan Dokumen -->
<div class="modal fade" id="docModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content rkjm-modal">
            <form id="docForm">
                <div class="modal-header">
                    <h5 class="modal-title">Pengaturan RKJM</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" value="<?= $doc ? (int)$doc['id'] : 0 ?>">
                    <div class="rkjm-section-title">Identitas Dokumen</div>
                    <div class="row">
                        <div class="col-md-8 mb-2">
                            <label>Nama Madrasah</label>
                            <input type="text" class="form-control" name="nama_madrasah" value="<?= htmlspecialchars($profil_madrasah !== '' ? $profil_madrasah : ($doc['nama_madrasah'] ?? '')) ?>" readonly>
                            <small class="text-muted"><i class="fas fa-lock mr-1"></i>Diambil dari Pengaturan Admin (Profil Madrasah).</small>
                        </div>
                        <div class="col-md-4 mb-2"><label>Nomor Dokumen</label><input type="text" class="form-control" name="nomor_dokumen" value="<?= htmlspecialchars($doc['nomor_dokumen'] ?? '') ?>" placeholder="Opsional"></div>
                        <div class="col-md-3 mb-2"><label>Tahun Awal</label><input type="number" class="form-control" name="tahun_awal" min="1901" max="2155" value="<?= (int)($doc['tahun_awal'] ?? date('Y')) ?>" required></div>
                        <div class="col-md-3 mb-2"><label>Tahun Akhir</label><input type="number" class="form-control" name="tahun_akhir" min="1901" max="2155" value="<?= (int)($doc['tahun_akhir'] ?? (date('Y') + 3)) ?>" required><small class="text-muted">Periode 4 tahun: tahun akhir = tahun awal + 3.</small></div>
                        <div class="col-md-3 mb-2"><label>Tanggal Penyusunan</label><input type="date" class="form-control" name="tanggal_penyusunan" value="<?= htmlspecialchars($doc['tanggal_penyusunan'] ?? date('Y-m-d')) ?>"></div>
                        <div class="col-md-3 mb-2"><label>Status Dokumen</label>
                            <select class="form-control" name="status">
                                <?php foreach ($doc_status_opts as $k => $v): ?>
                                    <option value="<?= htmlspecialchars($k) ?>" <?= ($doc['status'] ?? 'draf') === $k ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12 mb-2">
                            <label>Nama Kepala Madrasah</label>
                            <input type="text" class="form-control" name="nama_kepala" value="<?= htmlspecialchars($profil_kepala !== '' ? $profil_kepala : ($doc['nama_kepala'] ?? '')) ?>" readonly>
                            <small class="text-muted"><i class="fas fa-lock mr-1"></i>Diambil dari Pengaturan Admin (Profil Madrasah).</small>
                        </div>
                    </div>

                    <div class="rkjm-section-title">Visi, Misi, dan Tujuan</div>
                    <div class="mb-2"><label>Visi Madrasah</label><textarea class="form-control rkjm-editor" name="visi" rows="3" placeholder="Tuliskan visi madrasah..."><?= htmlspecialchars($doc['visi'] ?? '') ?></textarea></div>
                    <div class="mb-2"><label>Misi Madrasah</label><textarea class="form-control rkjm-editor" name="misi" rows="4" placeholder="Tuliskan misi madrasah..."><?= htmlspecialchars($doc['misi'] ?? '') ?></textarea></div>
                    <div class="mb-2"><label>Tujuan Pengembangan Madrasah</label><textarea class="form-control rkjm-editor" name="tujuan_pengembangan" rows="3" placeholder="Tuliskan tujuan pengembangan..."><?= htmlspecialchars($doc['tujuan_pengembangan'] ?? '') ?></textarea></div>

                    <div class="rkjm-section-title">Profil dan Dasar Penyusunan</div>
                    <div class="mb-2"><label>Kondisi Awal / Profil Singkat Madrasah</label><textarea class="form-control rkjm-editor" name="kondisi_awal" rows="4" placeholder="Gambaran singkat kondisi madrasah..."><?= htmlspecialchars($doc['kondisi_awal'] ?? '') ?></textarea></div>
                    <div class="mb-2"><label>Dasar Penyusunan RKJM</label><textarea class="form-control rkjm-editor" name="dasar_penyusunan" rows="3" placeholder="Contoh: regulasi, hasil evaluasi diri, rapat kerja madrasah"><?= htmlspecialchars($doc['dasar_penyusunan'] ?? '') ?></textarea></div>
                    <input type="hidden" name="existing_created_by" value="<?= htmlspecialchars($doc['created_by'] ?? '') ?>">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($doc): ?>
<!-- Modal Program -->
<div class="modal fade" id="programModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
        <div class="modal-content rkjm-modal">
            <form id="programForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="programModalTitle">Tambah Program RKJM</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" value="0">
                    <div class="rkjm-section-title">Identitas Program</div>
                    <div class="row">
                        <div class="col-md-4 mb-2"><label>Bidang Pengembangan</label>
                            <select class="form-control" name="field_id" id="prog_field">
                                <option value="">-- Pilih Bidang --</option>
                                <?php foreach ($fields as $f): ?>
                                    <option value="<?= (int)$f['id'] ?>" data-nama="<?= htmlspecialchars($f['nama'], ENT_QUOTES) ?>"><?= htmlspecialchars($f['nama']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2"><label>Kode</label><input type="text" class="form-control" name="kode_program" placeholder="Contoh: KUR-01"></div>
                        <div class="col-md-6 mb-2"><label>Nama Program</label><input type="text" class="form-control" name="nama_program" required></div>
                        <div class="col-md-4 mb-2"><label>Status Pelaksanaan</label>
                            <select class="form-control" name="status">
                                <?php foreach ($prog_status_opts as $k => $v): ?>
                                    <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2"><label>Penanggung Jawab</label><input type="text" class="form-control" name="penanggung_jawab"></div>
                        <div class="col-md-4 mb-2"><label>Urutan Tampil</label><input type="number" class="form-control" name="urutan" value="0" min="0"></div>
                    </div>

                    <div class="rkjm-section-title">Kondisi Awal, Tujuan, dan Sasaran</div>
                    <div class="row">
                        <div class="col-md-6 mb-2"><label>Kondisi Awal</label><textarea class="form-control" name="kondisi_awal" rows="2"></textarea></div>
                        <div class="col-md-6 mb-2"><label>Permasalahan yang Diselesaikan</label><textarea class="form-control" name="permasalahan" rows="2"></textarea></div>
                        <div class="col-md-6 mb-2"><label>Tujuan Program</label><textarea class="form-control" name="tujuan" rows="2"></textarea></div>
                        <div class="col-md-6 mb-2"><label>Sasaran Program</label><textarea class="form-control" name="sasaran" rows="2"></textarea></div>
                        <div class="col-md-6 mb-2"><label>Indikator Keberhasilan</label><textarea class="form-control" name="indikator" rows="2" placeholder="Harus terukur"></textarea></div>
                        <div class="col-md-6 mb-2"><label>Target Akhir Periode</label><textarea class="form-control" name="target_akhir" rows="2"></textarea></div>
                    </div>

                    <div class="rkjm-section-title">Target Tahunan</div>
                    <div class="row">
                        <?php $yi = 0; foreach ($years as $tk => $th): $yi++; ?>
                            <div class="col-md-6 mb-2">
                                <label>Tahun <?= (int)$tk ?> (<?= (int)$th ?>)</label>
                                <textarea class="form-control" name="target_tahun<?= (int)$tk ?>" rows="2"></textarea>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$years): ?><div class="col-12 text-muted small">Atur periode dokumen terlebih dahulu.</div><?php endif; ?>
                    </div>

                    <div class="rkjm-section-title">Kegiatan dan Pembiayaan</div>
                    <div class="row">
                        <div class="col-md-12 mb-2"><label>Rincian Kegiatan Utama</label><textarea class="form-control" name="kegiatan_utama" rows="2"></textarea></div>
                        <div class="col-md-4 mb-2"><label>Estimasi Anggaran (Rp)</label><input type="text" class="form-control rkjm-rupiah" name="estimasi_anggaran" placeholder="0"></div>
                        <div class="col-md-4 mb-2"><label>Sumber Dana</label><input type="text" class="form-control" name="sumber_dana"></div>
                        <div class="col-md-4 mb-2"><label>Dokumen/Bukti Diperlukan</label><input type="text" class="form-control" name="dokumen_bukti"></div>
                    </div>

                    <div class="rkjm-section-title">Monitoring dan Catatan</div>
                    <div class="row">
                        <div class="col-md-6 mb-2"><label>Metode Monitoring dan Evaluasi</label><textarea class="form-control" name="monitoring_evaluasi" rows="2"></textarea></div>
                        <div class="col-md-6 mb-2"><label>Catatan Tambahan</label><textarea class="form-control" name="catatan" rows="2"></textarea></div>
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
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Detail Program</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body" id="detailBody">
                <div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin mr-2"></i>Memuat...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
                <?php if ($is_editable): ?><button type="button" class="btn btn-primary" id="btn-detail-edit"><i class="fas fa-edit mr-1"></i>Edit Program</button><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal Monitoring -->
<div class="modal fade" id="monitoringModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content rkjm-modal">
            <form id="monitoringForm">
                <div class="modal-header">
                    <h5 class="modal-title">Catat Monitoring dan Evaluasi</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" value="0">
                    <input type="hidden" name="program_id" value="0">
                    <div class="row">
                        <div class="col-md-4 mb-2"><label>Tahun Pelaksanaan</label>
                            <select class="form-control" name="tahun_kalender">
                                <?php foreach ($years as $tk => $th): ?>
                                    <option value="<?= (int)$th ?>"><?= (int)$th ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2"><label>Status Pelaksanaan</label>
                            <select class="form-control" name="status_pelaksanaan">
                                <?php foreach ($prog_status_opts as $k => $v): ?>
                                    <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2"><label>Tanggal Evaluasi</label><input type="date" class="form-control" name="tanggal_evaluasi"></div>
                        <div class="col-md-6 mb-2"><label>Target Tahun Berjalan</label><textarea class="form-control" name="target_tahun_berjalan" rows="2"></textarea></div>
                        <div class="col-md-6 mb-2"><label>Realisasi</label><textarea class="form-control" name="realisasi" rows="2"></textarea></div>
                        <div class="col-md-4 mb-2"><label>Persentase Capaian (%)</label><input type="number" step="0.01" min="0" max="100" class="form-control" name="persentase_capaian" placeholder="Kosongkan jika kualitatif"></div>
                        <div class="col-md-4 mb-2"><label>Penilaian Kualitatif</label>
                            <select class="form-control" name="penilaian_kualitatif">
                                <option value="">-</option>
                                <option>Sangat Baik</option><option>Baik</option><option>Cukup</option><option>Kurang</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2"><label>Petugas Evaluasi</label><input type="text" class="form-control" name="petugas_evaluasi"></div>
                        <div class="col-md-6 mb-2"><label>Kendala</label><textarea class="form-control" name="kendala" rows="2"></textarea></div>
                        <div class="col-md-6 mb-2"><label>Tindak Lanjut</label><textarea class="form-control" name="tindak_lanjut" rows="2"></textarea></div>
                        <div class="col-12 mb-2"><label>Catatan Evaluasi</label><textarea class="form-control" name="catatan_evaluasi" rows="2"></textarea></div>
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

<!-- Modal Gunakan Template -->
<div class="modal fade" id="templateModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Gunakan Template RKJM</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 mb-3"><i class="fas fa-info-circle mr-1"></i>Template hanya menjadi data awal. Program hasil salinan dapat diedit secara independen dan tidak berubah saat template diperbarui.</div>
                <div class="row mb-2">
                    <div class="col-md-8 mb-2"><input type="search" class="form-control" id="tpl-search" placeholder="Cari nama program atau kode template..."></div>
                    <div class="col-md-4 mb-2">
                        <select class="form-control" id="tpl-filter-field">
                            <option value="">Semua Bidang</option>
                            <?php foreach ($templates_by_field as $bn => $tp): ?>
                                <option value="<?= htmlspecialchars($bn, ENT_QUOTES) ?>"><?= htmlspecialchars($bn) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="max-height:48vh;overflow:auto" id="tpl-list">
                    <?php if (!$templates_by_field): ?>
                        <div class="text-center text-muted py-4">Belum ada template aktif. <a href="rkjm_template.php">Kelola template</a>.</div>
                    <?php endif; ?>
                    <?php foreach ($templates_by_field as $bn => $tp): ?>
                        <div class="rkjm-tpl-group mb-2" data-field="<?= htmlspecialchars($bn, ENT_QUOTES) ?>">
                            <div class="rkjm-section-title mt-2"><?= htmlspecialchars($bn) ?> <span class="badge badge-light border"><?= count($tp) ?></span></div>
                            <?php foreach ($tp as $t): ?>
                                <div class="custom-control custom-checkbox border rounded p-2 mb-1 rkjm-tpl-item" data-text="<?= htmlspecialchars(strtolower($t['kode'] . ' ' . $t['nama_program'] . ' ' . $bn), ENT_QUOTES) ?>">
                                    <input type="checkbox" class="custom-control-input rkjm-tpl-check" id="tpl_<?= (int)$t['id'] ?>" value="<?= (int)$t['id'] ?>">
                                    <label class="custom-control-label d-block" for="tpl_<?= (int)$t['id'] ?>">
                                        <strong><?= htmlspecialchars($t['kode']) ?></strong> — <?= htmlspecialchars($t['nama_program']) ?>
                                        <div class="small text-muted"><?= htmlspecialchars(rkjm_excerpt($t['indikator'], 110)) ?> · Estimasi <?= rkjmRupiah($t['estimasi_anggaran']) ?></div>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="alert alert-secondary py-2 mt-3 mb-0 d-flex flex-wrap justify-content-between align-items-center">
                    <span>Dipilih: <strong id="tpl-selected-count">0</strong> program</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-1" id="tpl-clear"><i class="fas fa-eraser mr-1"></i>Bersihkan Pilihan</button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-success" id="tpl-apply"><i class="fas fa-copy mr-1"></i>Terapkan ke RKJM</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include '../templates/footer.php'; ?>
<script>
(function(){
    var RKJM = {
        csrf: <?= json_encode($csrf) ?>,
        docId: <?= $doc ? (int)$doc['id'] : 0 ?>,
        years: <?= json_encode(array_values($years)) ?>,
        editable: <?= $is_editable ? 'true' : 'false' ?>,
        ajax: 'rkjm_ajax.php'
    };
    window.RKJM = RKJM;

    // Ubah hasil $(form).serialize() menjadi objek agar tidak dipecah per karakter oleh Object.assign.
    function serializeToObject(str){
        var out = {};
        (str || '').split('&').forEach(function(pair){
            if (!pair) return;
            var idx = pair.indexOf('=');
            var key = idx < 0 ? pair : pair.slice(0, idx);
            var val = idx < 0 ? '' : pair.slice(idx + 1);
            key = decodeURIComponent(key.replace(/\+/g, ' '));
            val = decodeURIComponent(val.replace(/\+/g, ' '));
            if (!Object.prototype.hasOwnProperty.call(out, key)) {
                out[key] = val;
            } else {
                if (!Array.isArray(out[key])) out[key] = [out[key]];
                out[key].push(val);
            }
        });
        return out;
    }

    function post(action, data){
        var extra = typeof data === 'string' ? serializeToObject(data) : (data || {});
        var payload = $.extend({action: action, csrf_token: RKJM.csrf, document_id: RKJM.docId}, extra);
        return $.ajax({
            url: RKJM.ajax,
            type: 'POST',
            data: payload,
            dataType: 'json',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': RKJM.csrf}
        });
    }
    window.RKJMpost = post;

    function swalOk(msg){ Swal.fire({icon:'success', title:msg, timer:1800, showConfirmButton:false}); }
    function swalErr(msg){ Swal.fire({icon:'error', title:'Gagal', text: msg || 'Terjadi kesalahan'}); }
    function reload(){ setTimeout(function(){ location.reload(); }, 600); }

    function fmtRupiah(v){
        var s=(v+'').trim();
        s=s.replace(/\D/g,'').replace(/^0+(?=\d)/,'');
        return s ? s.replace(/\B(?=(\d{3})+(?!\d))/g,'.') : '';
    }
    // Format nilai numerik dari database (mis. 3000000.00) tanpa mencampur bagian desimal.
    function fmtRupiahValue(v){
        var n = parseFloat(v);
        if (!isFinite(n)) n = 0;
        n = Math.round(n);
        return n ? String(n).replace(/\B(?=(\d{3})+(?!\d))/g,'.') : '0';
    }
    $(document).on('input', 'input.rkjm-rupiah', function(){ this.value = fmtRupiah(this.value); });

    $('#rkjm-doc-switch').on('change', function(){ location.href = 'rkjm.php?doc=' + $(this).val(); });

    $('#btn-create-doc, #btn-doc-settings').on('click', function(){ $('#docModal').modal('show'); });

    // Editor kaya teks (TinyMCE) untuk Visi, Misi, Tujuan, Profil Singkat, dan Dasar Penyusunan.
    var rkjmEditorsReady = false;
    function initRkjmEditors(){
        if (rkjmEditorsReady || typeof tinymce === 'undefined') return;
        rkjmEditorsReady = true;
        tinymce.init({
            selector: '.rkjm-editor',
            height: 220,
            menubar: false,
            branding: false,
            promotion: false,
            license_key: 'gpl',
            plugins: 'lists link code fullscreen',
            toolbar: 'undo redo | blocks | bold italic underline strikethrough | bullist numlist | alignleft aligncenter alignright alignjustify | link hr | removeformat | code fullscreen',
            block_formats: 'Paragraf=p; Heading 3=h3; Heading 4=h4; Kutipan=blockquote',
            formats: {
                underline: { inline: 'u', exact: true },
                strikethrough: { inline: 's', exact: true }
            },
            content_style: "body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;font-size:14px;line-height:1.55;margin:10px 12px;color:#2b3252}"
                + " p{margin:0 0 8px}"
                + " ul{list-style:disc outside;padding-left:24px;margin:0 0 8px}"
                + " ol{list-style:decimal outside;padding-left:24px;margin:0 0 8px}"
                + " ul ul{list-style:circle outside} ul ul ul{list-style:square outside}"
                + " ol ol{list-style:lower-alpha outside}"
                + " li{margin-bottom:2px}"
                + " blockquote{border-left:3px solid #d9def8;margin:0 0 8px;padding:4px 12px;color:#4b54a6}",
            setup: function(editor){
                editor.on('change keyup', function(){
                    editor.save();
                });
            }
        });
    }
    function syncRkjmEditors(){
        if (typeof tinymce === 'undefined') return;
        tinymce.triggerSave();
    }
    // Inisialisasi saat modal benar-benar tampil supaya lebar toolbar terhitung benar.
    $('#docModal').on('shown.bs.modal', initRkjmEditors);

    if (RKJM.editable) {
        // Simpan dokumen
        $('#docForm').on('submit', function(e){
            e.preventDefault();
            syncRkjmEditors();
            var $btn = $(this).find('button[type=submit]').prop('disabled', true);
            post('save_document', $(this).serialize()).done(function(res){
                if (res.success) { swalOk(res.message || 'Dokumen disimpan'); setTimeout(function(){ var id = res.document_id || RKJM.docId; location.href = 'rkjm.php?doc=' + id; }, 700); }
                else { swalErr(res.message); $btn.prop('disabled', false); }
            }).fail(function(xhr){ swalErr((xhr.responseJSON && xhr.responseJSON.message) || 'Gagal menyimpan dokumen'); $btn.prop('disabled', false); });
        });

        function fieldName(id){
            var opt = $('#prog_field option[value="'+id+'"]');
            return opt.length ? opt.data('nama') : '';
        }

        function fillProgram(r){
            var f = $('#programForm');
            f.find('[name=id]').val(r.id || 0);
            f.find('[name=field_id]').val(r.field_id || '');
            f.find('[name=kode_program]').val(r.kode_program || '');
            f.find('[name=nama_program]').val(r.nama_program || '');
            f.find('[name=status]').val(r.status || 'belum_dimulai');
            f.find('[name=penanggung_jawab]').val(r.penanggung_jawab || '');
            f.find('[name=urutan]').val(r.urutan || 0);
            ['kondisi_awal','permasalahan','tujuan','sasaran','indikator','target_akhir','kegiatan_utama','sumber_dana','dokumen_bukti','monitoring_evaluasi','catatan'].forEach(function(k){ f.find('[name='+k+']').val(r[k] || ''); });
            f.find('[name=estimasi_anggaran]').val(fmtRupiahValue(r.estimasi_anggaran || 0));
            [1,2,3,4].forEach(function(i){
                var t = (r.targets && r.targets[i]) ? r.targets[i].target : '';
                f.find('[name=target_tahun'+i+']').val(t || '');
            });
            $('#programModalTitle').text(r.id ? 'Edit Program RKJM' : 'Tambah Program RKJM');
        }

        $('#btn-add-program').on('click', function(){
            $('#programForm')[0].reset();
            fillProgram({});
            $('#programModal').modal('show');
        });

        $('#programForm').on('submit', function(e){
            e.preventDefault();
            var $btn = $(this).find('button[type=submit]').prop('disabled', true);
            post('save_program', $(this).serialize()).done(function(res){
                if (res.success) { $('#programModal').modal('hide'); swalOk(res.message || 'Program disimpan'); reload(); }
                else { swalErr(res.message); $btn.prop('disabled', false); }
            }).fail(function(xhr){ swalErr((xhr.responseJSON && xhr.responseJSON.message) || 'Gagal menyimpan program'); $btn.prop('disabled', false); });
        });

        $(document).on('click', '.btn-rkjm-edit', function(){
            var id = $(this).data('id');
            post('get_program', {id: id}).done(function(res){
                if (res.success) { fillProgram(res.data); $('#programModal').modal('show'); }
                else swalErr(res.message);
            }).fail(function(){ swalErr('Gagal memuat program'); });
        });

        $(document).on('change', '.rkjm-status-sel', function(){
            var $s = $(this);
            post('update_status', {id: $s.data('id'), status: $s.val()}).done(function(res){
                if (res.success) { swalOk('Status diperbarui'); reload(); }
                else { swalErr(res.message); reload(); }
            }).fail(function(){ swalErr('Gagal memperbarui status'); });
        });

        $(document).on('click', '.btn-rkjm-delete', function(){
            var id = $(this).data('id'), nm = $(this).data('name');
            Swal.fire({title:'Hapus program?', text:'Program "'+nm+'" akan dihapus dari RKJM.', icon:'warning', showCancelButton:true, confirmButtonColor:'#d33', confirmButtonText:'Ya, Hapus', cancelButtonText:'Batal'}).then(function(r){
                if (!r.isConfirmed) return;
                post('delete_program', {id: id}).done(function(res){
                    if (res.success) { swalOk('Program dihapus'); reload(); } else swalErr(res.message);
                }).fail(function(){ swalErr('Gagal menghapus program'); });
            });
        });

        $(document).on('click', '.btn-rkjm-duplicate', function(){
            var id = $(this).data('id');
            post('duplicate_program', {id: id}).done(function(res){
                if (res.success) { swalOk('Program diduplikasi'); reload(); } else swalErr(res.message);
            }).fail(function(){ swalErr('Gagal menduplikasi program'); });
        });

        // Detail
        var currentDetailId = 0;
        $(document).on('click', '.btn-rkjm-detail', function(){
            currentDetailId = $(this).data('id');
            $('#detailBody').html('<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin mr-2"></i>Memuat...</div>');
            $('#detailModal').modal('show');
            post('get_program', {id: currentDetailId}).done(function(res){
                if (!res.success) { $('#detailBody').html('<div class="text-danger">'+$('<div>').text(res.message || 'Gagal memuat').html()+'</div>'); return; }
                lastDetail = res.data;
                renderDetail(res.data);
            });
        });
        $('#btn-detail-edit').on('click', function(){
            if (!currentDetailId) return;
            $('#detailModal').modal('hide');
            post('get_program', {id: currentDetailId}).done(function(res){ if (res.success) { fillProgram(res.data); $('#programModal').modal('show'); } });
        });

        function esc(s){ return $('<div>').text(s === null || s === undefined ? '' : s).html(); }
        function row(label, val){ return '<tr><th style="width:32%;vertical-align:top">'+label+'</th><td style="white-space:pre-line">'+esc(val || '-')+'</td></tr>'; }

        function renderDetail(d){
            var html = '<h6 class="font-weight-bold">'+esc(d.nama_program)+'</h6><div class="text-muted small mb-2">'+esc(d.kode_program||'-')+' · '+esc(d.bidang_nama||'-')+'</div>';
            html += '<div class="table-responsive"><table class="table table-sm table-bordered mb-0">';
            html += row('Kondisi Awal', d.kondisi_awal);
            html += row('Permasalahan', d.permasalahan);
            html += row('Tujuan', d.tujuan);
            html += row('Sasaran', d.sasaran);
            html += row('Indikator Keberhasilan', d.indikator);
            html += row('Target Akhir', d.target_akhir);
            html += row('Kegiatan Utama', d.kegiatan_utama);
            html += row('Penanggung Jawab', d.penanggung_jawab);
            html += row('Estimasi Anggaran', 'Rp ' + fmtRupiahValue(d.estimasi_anggaran || 0));
            html += row('Sumber Dana', d.sumber_dana);
            html += row('Dokumen/Bukti', d.dokumen_bukti);
            html += row('Monev', d.monitoring_evaluasi);
            html += row('Catatan', d.catatan);
            html += row('Status', d.status_label);
            html += '</table></div>';

            html += '<h6 class="font-weight-bold mt-3">Target Tahunan</h6><div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>Tahun</th><th>Target</th></tr></thead><tbody>';
            [1,2,3,4].forEach(function(i){
                var y = RKJM.years[i-1] || '';
                var t = (d.targets && d.targets[i]) ? d.targets[i].target : '';
                html += '<tr><td class="text-nowrap">Tahun '+i+(y?' ('+y+')':'')+'</td><td style="white-space:pre-line">'+esc(t||'-')+'</td></tr>';
            });
            html += '</tbody></table></div>';

            html += '<div class="d-flex justify-content-between align-items-center mt-3"><h6 class="font-weight-bold mb-0">Monitoring & Evaluasi</h6>';
            html += '<button type="button" class="btn btn-sm btn-primary" id="btn-add-monitoring" data-id="'+d.id+'"><i class="fas fa-plus mr-1"></i>Catat</button></div>';
            html += '<div class="table-responsive mt-2"><table class="table table-sm table-bordered"><thead><tr><th>Tahun</th><th>Status</th><th>Capaian</th><th>Realisasi</th><th>Kendala</th><th>Tindak Lanjut</th><th></th></tr></thead><tbody>';
            if (!d.monitoring || !d.monitoring.length) { html += '<tr><td colspan="7" class="text-center text-muted">Belum ada catatan monitoring.</td></tr>'; }
            (d.monitoring||[]).forEach(function(m){
                var cap = m.persentase_capaian !== null && m.persentase_capaian !== undefined && m.persentase_capaian !== '' ? (m.persentase_capaian+'%') : (m.penilaian_kualitatif || '-');
                html += '<tr><td>'+esc(m.tahun_kalender||'-')+'</td><td>'+esc(m.status_label||'-')+'</td><td>'+esc(cap)+'</td><td style="white-space:pre-line">'+esc(m.realisasi||'-')+'</td><td style="white-space:pre-line">'+esc(m.kendala||'-')+'</td><td style="white-space:pre-line">'+esc(m.tindak_lanjut||'-')+'</td><td class="text-nowrap">'+(RKJM.editable ? '<button type="button" class="btn btn-warning btn-sm px-2 mr-1 btn-mon-edit" data-id="'+esc(m.id)+'" title="Edit"><i class="fas fa-edit"></i></button> <button type="button" class="btn btn-danger btn-sm px-2 btn-mon-delete" data-id="'+esc(m.id)+'" title="Hapus"><i class="fas fa-trash"></i></button>' : '')+'</td></tr>';
            });
            html += '</tbody></table></div>';
            $('#detailBody').html(html);
        }

        // Monitoring
        $(document).on('click', '#btn-add-monitoring', function(){
            var pid = $(this).data('id');
            $('#monitoringForm')[0].reset();
            $('#monitoringForm').find('[name=id]').val(0).end().find('[name=program_id]').val(pid);
            $('#monitoringModal').modal('show');
        });
        var lastDetail = null;
        $(document).on('click', '.btn-mon-edit', function(){
            if (!lastDetail) return;
            var mid = parseInt($(this).data('id'), 10);
            var m = null;
            (lastDetail.monitoring || []).forEach(function(x){ if (parseInt(x.id, 10) === mid) m = x; });
            if (!m) return;
            var f = $('#monitoringForm');
            f[0].reset();
            f.find('[name=id]').val(m.id);
            f.find('[name=program_id]').val(m.program_id);
            f.find('[name=tahun_kalender]').val(m.tahun_kalender || '');
            f.find('[name=status_pelaksanaan]').val(m.status_pelaksanaan || 'belum_dimulai');
            f.find('[name=tanggal_evaluasi]').val(m.tanggal_evaluasi || '');
            ['target_tahun_berjalan','realisasi','kendala','tindak_lanjut','petugas_evaluasi','catatan_evaluasi'].forEach(function(k){ f.find('[name='+k+']').val(m[k] || ''); });
            f.find('[name=persentase_capaian]').val(m.persentase_capaian !== null ? m.persentase_capaian : '');
            f.find('[name=penilaian_kualitatif]').val(m.penilaian_kualitatif || '');
            $('#monitoringModal').modal('show');
        });
        $('#monitoringForm').on('submit', function(e){
            e.preventDefault();
            var $btn = $(this).find('button[type=submit]').prop('disabled', true);
            post('save_monitoring', $(this).serialize()).done(function(res){
                if (res.success) { $('#monitoringModal').modal('hide'); swalOk('Monitoring disimpan'); $btn.prop('disabled', false); if (currentDetailId) { $('.btn-rkjm-detail[data-id="'+currentDetailId+'"]').trigger('click'); } }
                else { swalErr(res.message); $btn.prop('disabled', false); }
            }).fail(function(xhr){ swalErr((xhr.responseJSON && xhr.responseJSON.message) || 'Gagal menyimpan monitoring'); $btn.prop('disabled', false); });
        });

        $(document).on('click', '.btn-mon-delete', function(){
            var mid = parseInt($(this).data('id'), 10);
            Swal.fire({title:'Hapus catatan monitoring?', icon:'warning', showCancelButton:true, confirmButtonColor:'#d33', confirmButtonText:'Ya, Hapus', cancelButtonText:'Batal'}).then(function(r){
                if (!r.isConfirmed) return;
                post('delete_monitoring', {id: mid}).done(function(res){
                    if (res.success) { swalOk('Catatan monitoring dihapus'); if (currentDetailId) { $('.btn-rkjm-detail[data-id="'+currentDetailId+'"]').trigger('click'); } }
                    else swalErr(res.message);
                }).fail(function(){ swalErr('Gagal menghapus monitoring'); });
            });
        });

        // Template
        $('#btn-use-template').on('click', function(){ $('#templateModal').modal('show'); });
        function refreshTplCount(){
            var n = $('.rkjm-tpl-check:checked').length;
            $('#tpl-selected-count').text(n);
            $('.rkjm-tpl-item').each(function(){
                $(this).toggleClass('is-selected', $(this).find('.rkjm-tpl-check').prop('checked'));
            });
        }
        $(document).on('change', '.rkjm-tpl-check', refreshTplCount);
        // Klik di area kartu (selain kotak centang/label/tautan) ikut memilih.
        $(document).on('click', '.rkjm-tpl-item', function(e){
            if ($(e.target).closest('input, label, a').length) return;
            var $c = $(this).find('.rkjm-tpl-check');
            $c.prop('checked', !$c.prop('checked')).trigger('change');
        });
        $('#tpl-clear').on('click', function(){ $('.rkjm-tpl-check').prop('checked', false); refreshTplCount(); });
        $('#templateModal').on('shown.bs.modal', refreshTplCount);
        function filterTemplates(){
            var q = ($('#tpl-search').val()||'').toLowerCase(), f = $('#tpl-filter-field').val();
            $('.rkjm-tpl-item').each(function(){
                var okQ = !q || $(this).data('text').indexOf(q) !== -1;
                var group = $(this).closest('.rkjm-tpl-group');
                var okF = !f || group.data('field') === f;
                $(this).toggle(okQ && okF);
            });
            $('.rkjm-tpl-group').each(function(){ $(this).toggle($(this).find('.rkjm-tpl-item:visible').length > 0); });
        }
        $('#tpl-search').on('input', filterTemplates);
        $('#tpl-filter-field').on('change', filterTemplates);

        $('#tpl-apply').on('click', function(){
            var ids = $('.rkjm-tpl-check:checked').map(function(){ return $(this).val(); }).get();
            if (!ids.length) { Swal.fire({icon:'warning', title:'Pilih minimal satu template'}); return; }
            Swal.fire({
                title: 'Terapkan '+ids.length+' program?',
                text: 'Program akan ditambahkan ke RKJM periode ini. Program yang sudah ada tidak ditimpa.',
                icon: 'question', showCancelButton: true, confirmButtonText: 'Ya, Terapkan', cancelButtonText: 'Batal'
            }).then(function(r){
                if (!r.isConfirmed) return;
                var $btn = $('#tpl-apply').prop('disabled', true);
                post('apply_templates', {template_ids: ids}).done(function(res){
                    $btn.prop('disabled', false);
                    if (res.success) {
                        $('#templateModal').modal('hide');
                        var extra = res.skipped ? ' ('+res.skipped+' duplikat dilewati)' : '';
                        swalOk((res.added||0)+' program ditambahkan'+extra);
                        reload();
                    } else swalErr(res.message);
                }).fail(function(xhr){ $btn.prop('disabled', false); swalErr((xhr.responseJSON && xhr.responseJSON.message) || 'Gagal menerapkan template'); });
            });
        });
    }
})();
</script>
