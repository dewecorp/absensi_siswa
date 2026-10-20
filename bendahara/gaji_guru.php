<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once __DIR__ . '/_summary.php';
require_once __DIR__ . '/_gaji.php';

if (!isAuthorized(['bendahara', 'admin'])) {
    redirect('../login.php');
}

$page_title = 'Gaji Guru';
$school_profile = getSchoolProfile($pdo);
$display_name = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Bendahara';

$q = trim((string)($_GET['q'] ?? ''));
$id_guru = (int)($_GET['guru'] ?? 0);

$gaji = bendahara_fetch_gaji();
$api_ok = ($gaji['status'] === 'success');
$guru_map = bendahara_gaji_map_guru($pdo);
$guru_rows = $gaji['guru'];

/* lengkapi nama guru + filter pencarian */
$list = [];
foreach ($guru_rows as $g) {
    $sid = (int)($g['simad_id_guru'] ?? 0);
    $info = $guru_map[$sid] ?? null;
    $nama = $info['nama_guru'] ?? ('Guru #' . $sid);
    $nip = $info['nip'] ?? '';
    if ($q !== '' && stripos($nama, $q) === false && stripos($nip, $q) === false) continue;
    $g['_id'] = $sid;
    $g['_nama'] = $nama;
    $g['_nip'] = $nip;
    $g['_pokok'] = (float)($g['total_gaji_pokok'] ?? 0);
    $g['_tunj'] = (float)($g['total_tunjangan'] ?? 0);
    $g['_pot'] = (float)($g['total_potongan'] ?? 0);
    $g['_bersih'] = (float)($g['gaji_bersih'] ?? 0);
    $list[] = $g;
}
usort($list, static fn($a, $b) => strcasecmp($a['_nama'], $b['_nama']));

$sum_pokok = array_sum(array_column($list, '_pokok'));
$sum_tunj = array_sum(array_column($list, '_tunj'));
$sum_pot = array_sum(array_column($list, '_pot'));
$sum_bersih = array_sum(array_column($list, '_bersih'));

$detail = null;
if ($id_guru > 0) {
    foreach ($list as $g) {
        if ((int)$g['_id'] === $id_guru) {
            $detail = $g;
            break;
        }
    }
}

$period_info = $gaji['period_info'];
$period_label = '';
$periode_acuan = (string)($period_info['periode_aktif'] ?? ($period_info['target_periode'] ?? ''));
if (!empty($period_info['periode_mulai']) && !empty($period_info['periode_akhir'])) {
    $periode_mulai = (string)$period_info['periode_mulai'];
    $periode_akhir = (string)$period_info['periode_akhir'];
    $period_label = $periode_mulai === $periode_akhir
        ? bendahara_periode_label($periode_mulai)
        : bendahara_periode_label($periode_mulai) . ' — ' . bendahara_periode_label($periode_akhir);
} elseif ($periode_acuan !== '') {
    $period_label = bendahara_periode_label($periode_acuan);
}

$tunj_items = [];
$pot_items = [];
if ($detail) {
    foreach (($detail['tunjangan'] ?? []) as $t) {
        $tunj_items[] = ['nama' => (string)($t['nama_tunjangan'] ?? 'Tunjangan'), 'nominal' => (float)($t['jumlah_bulanan'] ?? 0)];
    }
    foreach (($detail['potongan'] ?? []) as $p) {
        $pot_items[] = ['nama' => (string)($p['nama_potongan'] ?? 'Potongan'), 'nominal' => (float)($p['jumlah_bulanan'] ?? 0)];
    }
}

include '../templates/header.php';
include_once '../templates/sidebar.php';
?>
<style>
.bdh-chip-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-bottom: 14px; }
.bdh-chip {
    display: flex; align-items: center; gap: 8px;
    background: #fff; border: 1.5px solid rgba(15, 23, 42, .07);
    border-radius: 12px; padding: 8px 10px;
}
.bdh-chip .ico { flex: 0 0 auto; width: 30px; height: 30px; border-radius: 9px; display: grid; place-items: center; font-size: .74rem; }
.bdh-chip .lbl { font-size: .62rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; line-height: 1.2; }
.bdh-chip .val { font-size: .84rem; font-weight: 900; color: #0f172a; line-height: 1.25; }

.bdh-search { display: flex; align-items: center; gap: 8px; background: #fff; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 9px 13px; box-shadow: 0 2px 8px rgba(15,23,42,.04); margin-bottom: 14px; }
.bdh-search i { color: #94a3b8; }
.bdh-search input { border: 0; outline: 0; width: 100%; font-size: .88rem; background: transparent; }

.bdh-prof {
    display: flex; align-items: center; gap: 14px;
    background: linear-gradient(135deg, #0f766e, #0891b2);
    border-radius: 16px; padding: 16px 18px; color: #fff;
    box-shadow: 0 8px 24px rgba(8,145,178,.24);
    margin-bottom: 14px;
}
.bdh-prof-ava {
    flex: 0 0 auto; width: 52px; height: 52px; border-radius: 15px;
    background: rgba(255,255,255,.2); border: 1px solid rgba(255,255,255,.3);
    display: grid; place-items: center; font-weight: 900; font-size: 1.2rem;
}
.bdh-prof-nama { font-size: 1rem; font-weight: 900; line-height: 1.25; }
.bdh-prof-meta { font-size: .74rem; opacity: .92; font-weight: 600; }
.bdh-prof-saldo { margin-left: auto; text-align: right; }
.bdh-prof-saldo .lbl { font-size: .62rem; text-transform: uppercase; letter-spacing: .04em; opacity: .85; font-weight: 800; }
.bdh-prof-saldo .val { font-size: 1.15rem; font-weight: 900; }

.bdh-sum-grid { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 8px; margin-bottom: 16px; }
.bdh-sum { background: #fff; border: 1.5px solid rgba(15,23,42,.07); border-radius: 13px; padding: 10px 12px; }
.bdh-sum .lbl { font-size: .62rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; }
.bdh-sum .val { font-size: .88rem; font-weight: 900; color: #0f172a; line-height: 1.25; }
.bdh-sum .val.rose { color: #be123c; }
.bdh-sum .val.green { color: #15803d; }

.bdh-section-title { display: flex; align-items: center; gap: 8px; font-size: .78rem; font-weight: 900; color: #0f172a; margin: 4px 0 10px; }
.bdh-section-title .dot { width: 8px; height: 8px; border-radius: 50%; }

.bdh-gaji {
    display: flex; align-items: center; gap: 11px;
    background: #fff; border: 1.5px solid rgba(15,23,42,.07);
    border-radius: 13px; padding: 11px 13px; margin-bottom: 8px;
    text-decoration: none !important;
    transition: box-shadow .16s, transform .16s, border-color .16s;
}
.bdh-gaji:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(15,23,42,.08); border-color: #bfdbfe; }
.bdh-gaji-ava { flex: 0 0 auto; width: 36px; height: 36px; border-radius: 11px; display: grid; place-items: center; background: #eff6ff; color: #2563eb; font-weight: 900; font-size: .8rem; }
.bdh-gaji-nama { font-size: .82rem; font-weight: 800; color: #0f172a; line-height: 1.25; }
.bdh-gaji-meta { font-size: .68rem; color: #94a3b8; font-weight: 600; }
.bdh-gaji-nom { font-size: .84rem; font-weight: 900; color: #15803d; white-space: nowrap; margin-left: auto; text-align: right; }

.bdh-line {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    background: #fff; border: 1.5px solid rgba(15,23,42,.07);
    border-radius: 12px; padding: 10px 12px; margin-bottom: 8px;
    font-size: .8rem;
}
.bdh-line .nama { font-weight: 800; color: #0f172a; }
.bdh-line .nom { font-weight: 900; color: #0f172a; white-space: nowrap; }
.bdh-line .nom.rose { color: #be123c; }
.bdh-line .nom.green { color: #15803d; }

.bdh-table { background: #fff; border-radius: 14px; overflow: hidden; border: 1.5px solid rgba(15,23,42,.07); }
.bdh-table table { margin: 0; }
.bdh-table th { font-size: .68rem; text-transform: uppercase; letter-spacing: .04em; color: #64748b; font-weight: 800; border-top: 0; }
.bdh-table td { font-size: .8rem; vertical-align: middle; }
.bdh-table td.num { text-align: right; font-weight: 800; white-space: nowrap; }

@media (max-width: 575.98px) {
    .bdh-chip-row { gap: 6px; }
    .bdh-chip { padding: 7px 8px; gap: 6px; }
    .bdh-chip .ico { width: 26px; height: 26px; border-radius: 8px; font-size: .66rem; }
    .bdh-chip .lbl { font-size: .56rem; }
    .bdh-chip .val { font-size: .72rem; }
    .bdh-sum-grid { grid-template-columns: repeat(2, minmax(0,1fr)); gap: 6px; }
    .bdh-sum { padding: 8px 9px; }
    .bdh-sum .val { font-size: .8rem; }
    .bdh-prof { padding: 13px 14px; gap: 11px; flex-wrap: wrap; }
    .bdh-prof-ava { width: 44px; height: 44px; border-radius: 13px; font-size: 1rem; }
    .bdh-prof-nama { font-size: .9rem; }
    .bdh-prof-saldo { margin-left: 0; text-align: left; width: 100%; }
}
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Gaji Guru</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item active">Gaji Guru</div>
            </div>
        </div>

        <div class="section-body">
            <?php if (!$api_ok): ?>
                <div class="alert alert-warning" style="border-radius:12px;">
                    <strong>Data penggajian belum tersedia.</strong>
                    <div class="small"><?= htmlspecialchars((string)($gaji['message'] ?? '')) ?></div>
                </div>
            <?php elseif (!empty($gaji['stale'])): ?>
                <div class="alert alert-light" style="border-radius:12px;border:1px solid #e2e8f0;">
                    <i class="fas fa-clock mr-1"></i> Menampilkan data cache terakhir<?= $period_label !== '' ? ' periode ' . htmlspecialchars($period_label) : '' ?>.
                </div>
            <?php endif; ?>

            <?php if ($id_guru === 0): ?>
                <div class="bdh-chip-row">
                    <div class="bdh-chip">
                        <span class="ico" style="background:#eff6ff;color:#2563eb;"><i class="fas fa-users"></i></span>
                        <span>
                            <span class="lbl d-block">Guru</span>
                            <span class="val"><?= count($list) ?> orang</span>
                        </span>
                    </div>
                    <div class="bdh-chip">
                        <span class="ico" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-wallet"></i></span>
                        <span>
                            <span class="lbl d-block">Tunjangan</span>
                            <span class="val"><?= bendahara_rupiah($sum_tunj) ?></span>
                        </span>
                    </div>
                    <div class="bdh-chip">
                        <span class="ico" style="background:#fff1f2;color:#be123c;"><i class="fas fa-minus-circle"></i></span>
                        <span>
                            <span class="lbl d-block">Potongan</span>
                            <span class="val"><?= bendahara_rupiah($sum_pot) ?></span>
                        </span>
                    </div>
                </div>

                <form method="GET" id="bdhGajiSearchForm">
                    <div class="bdh-search">
                        <i class="fas fa-search"></i>
                        <input type="text" name="q" id="bdhGajiSearch" value="<?= htmlspecialchars($q) ?>" placeholder="Cari nama atau NUPTK guru...">
                    </div>
                </form>

                <div class="bdh-section-title">
                    <span class="dot" style="background:#0d9488;"></span>
                    Daftar Gaji<?= $period_label !== '' ? ' — ' . htmlspecialchars($period_label) : '' ?>
                    <span class="ml-auto small text-muted font-weight-bold">Total bersih <?= bendahara_rupiah($sum_bersih) ?></span>
                </div>

                <?php if (empty($list)): ?>
                    <div class="card"><div class="card-body text-center py-5">
                        <i class="fas fa-user-slash fa-2x mb-3" style="color:#cbd5e1;"></i>
                        <div class="font-weight-bold" style="color:#334155;">Data gaji tidak ditemukan</div>
                        <div class="small text-muted mt-1">Coba kata kunci lain.</div>
                    </div></div>
                <?php else: ?>
                    <div class="bdh-table d-none d-md-block">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Guru</th>
                                        <th class="text-right">Gaji Pokok</th>
                                        <th class="text-right">Tunjangan</th>
                                        <th class="text-right">Potongan</th>
                                        <th class="text-right">Bersih</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($list as $g): ?>
                                        <tr>
                                            <td>
                                                <div class="font-weight-bold"><?= htmlspecialchars($g['_nama']) ?></div>
                                                <div class="small text-muted"><?= htmlspecialchars($g['_nip'] !== '' ? $g['_nip'] : '-') ?></div>                                            </td>
                                            <td class="num"><?= bendahara_rupiah($g['_pokok']) ?></td>
                                            <td class="num" style="color:#15803d;"><?= bendahara_rupiah($g['_tunj']) ?></td>
                                            <td class="num" style="color:#be123c;"><?= bendahara_rupiah($g['_pot']) ?></td>
                                            <td class="num"><?= bendahara_rupiah($g['_bersih']) ?></td>
                                            <td class="text-right">
                                                <a href="gaji_guru.php?guru=<?= (int)$g['_id'] ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="btn btn-sm btn-light" style="border-radius:9px;font-weight:800;">Detail</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="d-md-none">
                        <?php foreach ($list as $g): ?>
                            <a class="bdh-gaji" href="gaji_guru.php?guru=<?= (int)$g['_id'] ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">
                                <span class="bdh-gaji-ava"><?= htmlspecialchars(strtoupper(mb_substr($g['_nama'], 0, 1))) ?></span>
                                <span style="min-width:0;">
                                    <span class="bdh-gaji-nama d-block text-truncate"><?= htmlspecialchars($g['_nama']) ?></span>
                                    <span class="bdh-gaji-meta"><?= htmlspecialchars($g['_nip'] !== '' ? $g['_nip'] : 'Tanpa NIP') ?></span>
                                </span>
                                <span class="bdh-gaji-nom">
                                    <?= bendahara_rupiah($g['_bersih']) ?>
                                    <span class="d-block small font-weight-normal text-muted">bersih</span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <!-- Detail gaji guru -->
                <div class="mb-3">
                    <a href="gaji_guru.php<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>" class="btn btn-light" style="border-radius:12px;font-weight:800;">
                        <i class="fas fa-arrow-left mr-1"></i>Kembali ke Daftar
                    </a>
                </div>

                <?php if (!$detail): ?>
                    <div class="alert alert-warning" style="border-radius:12px;">Data guru tidak ditemukan pada periode ini.</div>
                <?php else: ?>
                    <div class="bdh-prof">
                        <div class="bdh-prof-ava"><?= htmlspecialchars(strtoupper(mb_substr($detail['_nama'], 0, 1))) ?></div>
                        <div style="min-width:0;">
                            <div class="bdh-prof-nama"><?= htmlspecialchars($detail['_nama']) ?></div>
                            <div class="bdh-prof-meta">NUPTK / Kode: <?= htmlspecialchars($detail['_nip'] !== '' ? $detail['_nip'] : '-') ?></div>
                            <div class="bdh-prof-meta" style="margin-top:2px;">Periode <?= htmlspecialchars($period_label !== '' ? $period_label : '-') ?></div>
                        </div>
                        <div class="bdh-prof-saldo">
                            <div class="lbl">Gaji Bersih</div>
                            <div class="val"><?= bendahara_rupiah($detail['_bersih']) ?></div>
                        </div>
                    </div>

                    <div class="bdh-sum-grid">
                        <div class="bdh-sum">
                            <div class="lbl">Gaji Pokok</div>
                            <div class="val"><?= bendahara_rupiah($detail['_pokok']) ?></div>
                        </div>
                        <div class="bdh-sum">
                            <div class="lbl">Tunjangan</div>
                            <div class="val green"><?= bendahara_rupiah($detail['_tunj']) ?></div>
                        </div>
                        <div class="bdh-sum">
                            <div class="lbl">Potongan</div>
                            <div class="val rose"><?= bendahara_rupiah($detail['_pot']) ?></div>
                        </div>
                        <div class="bdh-sum">
                            <div class="lbl">Bersih</div>
                            <div class="val"><?= bendahara_rupiah($detail['_bersih']) ?></div>
                        </div>
                    </div>

                    <div class="bdh-section-title">
                        <span class="dot" style="background:#16a34a;"></span>
                        Rincian Tunjangan
                    </div>
                    <?php if (empty($tunj_items)): ?>
                        <div class="bdh-line"><span class="nama">Tidak ada tunjangan</span><span class="nom">-</span></div>
                    <?php else: ?>
                        <?php foreach ($tunj_items as $t): ?>
                            <div class="bdh-line">
                                <span class="nama"><?= htmlspecialchars($t['nama']) ?></span>
                                <span class="nom green"><?= bendahara_rupiah($t['nominal']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="bdh-section-title mt-3">
                        <span class="dot" style="background:#be123c;"></span>
                        Rincian Potongan
                    </div>
                    <?php if (empty($pot_items)): ?>
                        <div class="bdh-line"><span class="nama">Tidak ada potongan</span><span class="nom">-</span></div>
                    <?php else: ?>
                        <?php foreach ($pot_items as $p): ?>
                            <div class="bdh-line">
                                <span class="nama"><?= htmlspecialchars($p['nama']) ?></span>
                                <span class="nom rose"><?= bendahara_rupiah($p['nominal']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<script>
(function () {
    var form = document.getElementById('bdhGajiSearchForm');
    var input = document.getElementById('bdhGajiSearch');
    if (!form || !input) return;
    var timer = null;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { form.submit(); }, 450);
    });
})();
</script>

<?php include '../templates/footer.php'; ?>
