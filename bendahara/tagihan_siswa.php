<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once __DIR__ . '/_summary.php';

if (!isAuthorized(['bendahara', 'admin'])) {
    redirect('../login.php');
}

$page_title = 'Tagihan Siswa';
$school_profile = getSchoolProfile($pdo);
$display_name = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Bendahara';

$q = trim((string)($_GET['q'] ?? ''));
$filter_kelas = trim((string)($_GET['kelas'] ?? ''));
$filter_status = trim((string)($_GET['status'] ?? 'semua'));
if (!in_array($filter_status, ['semua', 'belum', 'lunas'], true)) {
    $filter_status = 'semua';
}
$per_page = 24;
$page = max(1, (int)($_GET['page'] ?? 1));

$summary = bendahara_fetch_summary();
$rows = $summary['status'] === 'success' ? $summary['data'] : [];
$api_ok = $summary['status'] === 'success';

/* opsi kelas dari data */
$kelas_options = [];
foreach ($rows as $r) {
    $k = trim((string)($r['kelas'] ?? ''));
    if ($k !== '') $kelas_options[$k] = true;
}
$kelas_options = array_keys($kelas_options);
sort($kelas_options, SORT_NATURAL);

/* filter */
$filtered = [];
foreach ($rows as $r) {
    $nama = (string)($r['nama'] ?? '');
    $nisn = (string)($r['nisn'] ?? '');
    $kelas = trim((string)($r['kelas'] ?? ''));
    $lunas = !empty($r['is_lunas']);

    if ($q !== '' && stripos($nama, $q) === false && stripos($nisn, $q) === false) continue;
    if ($filter_kelas !== '' && $kelas !== $filter_kelas) continue;
    if ($filter_status === 'belum' && $lunas) continue;
    if ($filter_status === 'lunas' && !$lunas) continue;

    $r['_tagihan'] = (int)($r['total_tunggakan_aktif'] ?? 0)
        + (int)($r['total_tunggakan_tahun_lama'] ?? 0)
        + (int)($r['total_sudah_bayar'] ?? 0);
    $r['_dibayar'] = (int)($r['total_sudah_bayar'] ?? 0);
    $r['_tunggakan'] = (int)($r['total_tunggakan'] ?? 0);
    $filtered[] = $r;
}

/* urut: belum lunas dulu, tunggakan terbesar */
usort($filtered, static function ($a, $b) {
    $la = !empty($a['is_lunas']) ? 1 : 0;
    $lb = !empty($b['is_lunas']) ? 1 : 0;
    if ($la !== $lb) return $la <=> $lb;
    return $b['_tunggakan'] <=> $a['_tunggakan'];
});

$total_filtered = count($filtered);
$total_pages = max(1, (int)ceil($total_filtered / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;
$paged = array_slice($filtered, $offset, $per_page);

$sum_tagihan = array_sum(array_column($filtered, '_tagihan'));
$sum_dibayar = array_sum(array_column($filtered, '_dibayar'));
$sum_tunggakan = array_sum(array_column($filtered, '_tunggakan'));
$jml_belum = count(array_filter($filtered, static fn($r) => empty($r['is_lunas'])));
$jml_lunas = $total_filtered - $jml_belum;

include '../templates/header.php';
include_once '../templates/sidebar.php';
?>
<style>
.bdh-chip-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-bottom: 14px; }
.bdh-chip {
    display: flex; align-items: center; gap: 8px;
    background: #fff;
    border: 1.5px solid rgba(15, 23, 42, .07);
    border-radius: 12px;
    padding: 8px 10px;
}
.bdh-chip .ico {
    flex: 0 0 auto; width: 30px; height: 30px; border-radius: 9px;
    display: grid; place-items: center; font-size: .74rem;
}
.bdh-chip .lbl { font-size: .62rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; line-height: 1.2; }
.bdh-chip .val { font-size: .84rem; font-weight: 900; color: #0f172a; line-height: 1.25; }

.bdh-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 14px; }
.bdh-search { flex: 1 1 240px; display: flex; align-items: center; gap: 8px; background: #fff; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 9px 13px; box-shadow: 0 2px 8px rgba(15,23,42,.04); }
.bdh-search i { color: #94a3b8; }
.bdh-search input { border: 0; outline: 0; width: 100%; font-size: .88rem; color: #0f172a; background: transparent; }
.bdh-select { height: 42px; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 0 12px; font-size: .84rem; font-weight: 700; color: #334155; background: #fff; box-shadow: 0 2px 8px rgba(15,23,42,.04); }

.bdh-card-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.bdh-siswa {
    background: #fff;
    border: 1.5px solid rgba(15, 23, 42, .07);
    border-radius: 14px;
    padding: 13px 14px;
    transition: box-shadow .18s, transform .18s, border-color .18s;
    text-decoration: none !important;
    display: block;
}
.bdh-siswa:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(15, 23, 42, .09); border-color: #bfdbfe; }
.bdh-siswa.is-lunas { background: #f7fdf9; border-color: rgba(34, 197, 94, .2); }
.bdh-siswa.is-belum { background: #fffafb; border-color: rgba(244, 63, 94, .18); }
.bdh-siswa-top { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.bdh-siswa-ava {
    flex: 0 0 auto; width: 38px; height: 38px; border-radius: 11px;
    display: grid; place-items: center; font-weight: 900; font-size: .84rem;
    background: #eff6ff; color: #2563eb;
}
.bdh-siswa.is-lunas .bdh-siswa-ava { background: #dcfce7; color: #15803d; }
.bdh-siswa.is-belum .bdh-siswa-ava { background: #ffe4e6; color: #be123c; }
.bdh-siswa-nama { font-size: .84rem; font-weight: 800; color: #0f172a; line-height: 1.25; }
.bdh-siswa-meta { font-size: .7rem; color: #94a3b8; font-weight: 600; }
.bdh-badge {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: .64rem; font-weight: 900;
    padding: 2px 8px; border-radius: 999px;
    margin-left: auto; white-space: nowrap;
}
.bdh-badge.ok { background: #dcfce7; color: #15803d; }
.bdh-badge.no { background: #ffe4e6; color: #be123c; }
.bdh-amounts { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; margin-top: 8px; }
.bdh-amt-lbl { font-size: .6rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: .03em; }
.bdh-amt-val { font-size: .76rem; font-weight: 900; color: #0f172a; }
.bdh-amt-val.rose { color: #be123c; }
.bdh-amt-val.green { color: #15803d; }

.bdh-empty { text-align: center; padding: 40px 10px; }
.bdh-empty-ico { width: 56px; height: 56px; border-radius: 16px; background: #eff6ff; display: grid; place-items: center; margin: 0 auto 12px; }
.bdh-empty-ico i { color: #2563eb; font-size: 1.35rem; }
.bdh-pager { display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 16px; flex-wrap: wrap; }
.bdh-pager a, .bdh-pager span {
    min-width: 34px; height: 34px; border-radius: 10px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: .8rem; font-weight: 800; text-decoration: none !important;
    border: 1.5px solid #e2e8f0; background: #fff; color: #334155;
}
.bdh-pager a:hover { border-color: #bfdbfe; color: #2563eb; }
.bdh-pager .is-active { background: #2563eb; border-color: #2563eb; color: #fff; }
.bdh-pager .is-disabled { opacity: .45; pointer-events: none; }
@media (max-width: 991.98px) {
    .bdh-card-grid { grid-template-columns: 1fr; }
}
@media (max-width: 575.98px) {
    .bdh-chip-row { gap: 6px; }
    .bdh-chip { padding: 7px 8px; gap: 6px; }
    .bdh-chip .ico { width: 26px; height: 26px; border-radius: 8px; font-size: .66rem; }
    .bdh-chip .lbl { font-size: .56rem; }
    .bdh-chip .val { font-size: .74rem; }
    .bdh-select { flex: 1 1 45%; }
}
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Tagihan Siswa</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item active">Tagihan Siswa</div>
            </div>
        </div>

        <div class="section-body">
            <?php if (!$api_ok): ?>
                <div class="alert alert-warning" style="border-radius:12px;">
                    <strong>Data Pembayaran belum tersedia.</strong>
                    <div class="small"><?= htmlspecialchars($summary['message'] ?? '') ?></div>
                </div>
            <?php endif; ?>

            <div class="bdh-chip-row">
                <div class="bdh-chip">
                    <span class="ico" style="background:#eff6ff;color:#2563eb;"><i class="fas fa-file-invoice-dollar"></i></span>
                    <span>
                        <span class="lbl d-block">Tagihan</span>
                        <span class="val"><?= bendahara_rupiah($sum_tagihan) ?></span>
                    </span>
                </div>
                <div class="bdh-chip">
                    <span class="ico" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-check-circle"></i></span>
                    <span>
                        <span class="lbl d-block">Dibayar</span>
                        <span class="val"><?= bendahara_rupiah($sum_dibayar) ?></span>
                    </span>
                </div>
                <div class="bdh-chip">
                    <span class="ico" style="background:#fff1f2;color:#be123c;"><i class="fas fa-hourglass-half"></i></span>
                    <span>
                        <span class="lbl d-block">Tunggakan</span>
                        <span class="val"><?= bendahara_rupiah($sum_tunggakan) ?></span>
                    </span>
                </div>
            </div>

            <form method="GET" class="bdh-toolbar">
                <div class="bdh-search">
                    <i class="fas fa-search"></i>
                    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Cari nama atau NISN...">
                </div>
                <select name="kelas" class="bdh-select" onchange="this.form.submit()">
                    <option value="">Semua Kelas</option>
                    <?php foreach ($kelas_options as $k): ?>
                        <option value="<?= htmlspecialchars($k) ?>" <?= $filter_kelas === (string)$k ? 'selected' : '' ?>>Kelas <?= htmlspecialchars($k) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status" class="bdh-select" onchange="this.form.submit()">
                    <option value="semua" <?= $filter_status === 'semua' ? 'selected' : '' ?>>Semua Status</option>
                    <option value="belum" <?= $filter_status === 'belum' ? 'selected' : '' ?>>Belum Lunas (<?= $jml_belum ?>)</option>
                    <option value="lunas" <?= $filter_status === 'lunas' ? 'selected' : '' ?>>Lunas (<?= $jml_lunas ?>)</option>
                </select>
                <?php if ($q !== '' || $filter_kelas !== '' || $filter_status !== 'semua'): ?>
                    <a href="tagihan_siswa.php" class="btn btn-light" style="border-radius:14px;font-weight:800;height:42px;">
                        <i class="fas fa-undo mr-1"></i>Reset
                    </a>
                <?php endif; ?>
            </form>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="small text-muted font-weight-bold">
                    Menampilkan <?= count($paged) ?> dari <?= $total_filtered ?> siswa
                </div>
            </div>

            <?php if (empty($paged)): ?>
                <div class="card">
                    <div class="card-body">
                        <div class="bdh-empty">
                            <div class="bdh-empty-ico"><i class="fas fa-user-slash"></i></div>
                            <div style="font-weight:800;color:#334155;">Tidak ada data siswa</div>
                            <div class="small text-muted mt-1">Coba ubah kata kunci atau filter.</div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="bdh-card-grid">
                    <?php foreach ($paged as $r):
                        $lunas = !empty($r['is_lunas']);
                        $nama = (string)($r['nama'] ?? '-');
                        $nisn = (string)($r['nisn'] ?? '');
                        $kelas = (string)($r['kelas'] ?? '');
                    ?>
                        <a class="bdh-siswa <?= $lunas ? 'is-lunas' : 'is-belum' ?>"
                           href="laporan_pembayaran.php?nisn=<?= urlencode($nisn) ?>">
                            <div class="bdh-siswa-top">
                                <span class="bdh-siswa-ava"><?= htmlspecialchars(strtoupper(mb_substr($nama, 0, 1))) ?></span>
                                <span style="min-width:0;flex:1;">
                                    <span class="bdh-siswa-nama d-block text-truncate"><?= htmlspecialchars($nama) ?></span>
                                    <span class="bdh-siswa-meta"><?= htmlspecialchars(bendahara_kelas_label($kelas)) ?> &bull; <?= htmlspecialchars($nisn) ?></span>
                                </span>
                                <span class="bdh-badge <?= $lunas ? 'ok' : 'no' ?>">
                                    <i class="fas <?= $lunas ? 'fa-check-circle' : 'fa-clock' ?>"></i>
                                    <?= $lunas ? 'LUNAS' : 'BELUM' ?>
                                </span>
                            </div>
                            <div class="bdh-amounts">
                                <div>
                                    <div class="bdh-amt-lbl">Tagihan</div>
                                    <div class="bdh-amt-val"><?= bendahara_rupiah($r['_tagihan']) ?></div>
                                </div>
                                <div>
                                    <div class="bdh-amt-lbl">Dibayar</div>
                                    <div class="bdh-amt-val green"><?= bendahara_rupiah($r['_dibayar']) ?></div>
                                </div>
                                <div>
                                    <div class="bdh-amt-lbl">Sisa</div>
                                    <div class="bdh-amt-val <?= $r['_tunggakan'] > 0 ? 'rose' : 'green' ?>"><?= bendahara_rupiah($r['_tunggakan']) ?></div>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div class="bdh-pager">
                        <a class="<?= $page <= 1 ? 'is-disabled' : '' ?>" href="?<?= http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])) ?>"><i class="fas fa-chevron-left"></i></a>
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($total_pages, $page + 2);
                        for ($i = $start; $i <= $end; $i++):
                        ?>
                            <a class="<?= $i === $page ? 'is-active' : '' ?>" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                        <?php endfor; ?>
                        <a class="<?= $page >= $total_pages ? 'is-disabled' : '' ?>" href="?<?= http_build_query(array_merge($_GET, ['page' => min($total_pages, $page + 1)])) ?>"><i class="fas fa-chevron-right"></i></a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<script>
(function () {
    var form = document.querySelector('form.bdh-toolbar');
    if (!form) return;
    var input = form.querySelector('input[name="q"]');
    if (!input) return;
    var timer = null;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { form.submit(); }, 450);
    });
})();
</script>

<?php include '../templates/footer.php'; ?>
