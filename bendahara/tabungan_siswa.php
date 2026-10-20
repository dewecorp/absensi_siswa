<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once __DIR__ . '/_summary.php';

if (!isAuthorized(['bendahara', 'admin'])) {
    redirect('../login.php');
}

$page_title = 'Tabungan Siswa';
$school_profile = getSchoolProfile($pdo);
$display_name = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Bendahara';

$q = trim((string)($_GET['q'] ?? ''));
$nisn = trim((string)($_GET['nisn'] ?? ''));

$summary = bendahara_fetch_summary();
$rows = $summary['status'] === 'success' ? $summary['data'] : [];
$api_ok = $summary['status'] === 'success';

/* Detail tabungan (eTabs) */
$student_info = [];
$tabs_ok = false;
$tabs_msg = '';
$saldo = 0;
$total_setor = 0;
$total_tarik = 0;
$transaksi = [];

if ($nisn !== '') {
    $tabs_summary = fetchEtabsData($nisn, 'summary');
    $tabs_riwayat = fetchEtabsData($nisn, 'riwayat');

    $tabs_ok = !empty($tabs_summary['success']);
    if (!$tabs_ok) {
        $tabs_msg = (string)($tabs_summary['message'] ?? 'Tidak dapat mengambil data tabungan.');
    }

    $sdata = is_array($tabs_summary['data'] ?? null) ? $tabs_summary['data'] : $tabs_summary;
    $saldo = (int)($sdata['saldo'] ?? 0);
    $total_setor = (int)($sdata['total_setor'] ?? $sdata['total_setoran'] ?? 0);
    $total_tarik = (int)($sdata['total_tarik'] ?? $sdata['total_penarikan'] ?? 0);

    $rdata = $tabs_riwayat['data'] ?? [];
    $transaksi = is_array($rdata['transaksi'] ?? null) ? $rdata['transaksi'] : (is_array($rdata) ? $rdata : []);

    /* identitas dari ringkasan Pembayaran */
    foreach ($rows as $r) {
        if ((string)($r['nisn'] ?? '') === $nisn) {
            $student_info = ['nama' => $r['nama'] ?? '-', 'nisn' => $r['nisn'] ?? '-', 'kelas' => $r['kelas'] ?? ''];
            break;
        }
    }
    if (empty($student_info)) {
        $student_info = ['nama' => '-', 'nisn' => $nisn, 'kelas' => ''];
    }
}

/* daftar siswa untuk pemilihan */
$list = [];
foreach ($rows as $r) {
    $nama = (string)($r['nama'] ?? '');
    $n = (string)($r['nisn'] ?? '');
    if ($q !== '' && stripos($nama, $q) === false && stripos($n, $q) === false) continue;
    $list[] = $r;
}
usort($list, static fn($a, $b) => strcasecmp((string)($a['nama'] ?? ''), (string)($b['nama'] ?? '')));
$list = array_slice($list, 0, 40);

include '../templates/header.php';
include_once '../templates/sidebar.php';
?>
<style>
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

.bdh-sum-grid { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 8px; margin-bottom: 16px; }
.bdh-sum {
    background: #fff; border: 1.5px solid rgba(15,23,42,.07);
    border-radius: 13px; padding: 10px 12px;
}
.bdh-sum .lbl { font-size: .62rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; }
.bdh-sum .val { font-size: .9rem; font-weight: 900; color: #0f172a; line-height: 1.25; }
.bdh-sum .val.rose { color: #be123c; }
.bdh-sum .val.green { color: #15803d; }

.bdh-section-title {
    display: flex; align-items: center; gap: 8px;
    font-size: .78rem; font-weight: 900; color: #0f172a;
    margin: 4px 0 10px;
}
.bdh-section-title .dot { width: 8px; height: 8px; border-radius: 50%; }

.bdh-pick {
    display: flex; align-items: center; gap: 11px;
    background: #fff; border: 1.5px solid rgba(15,23,42,.07);
    border-radius: 13px; padding: 11px 13px; margin-bottom: 8px;
    text-decoration: none !important;
    transition: box-shadow .16s, transform .16s, border-color .16s;
}
.bdh-pick:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(15,23,42,.08); border-color: #bfdbfe; }
.bdh-pick-ava { flex: 0 0 auto; width: 36px; height: 36px; border-radius: 11px; display: grid; place-items: center; background: #eff6ff; color: #2563eb; font-weight: 900; font-size: .8rem; }
.bdh-pick-nama { font-size: .82rem; font-weight: 800; color: #0f172a; line-height: 1.25; }
.bdh-pick-meta { font-size: .68rem; color: #94a3b8; font-weight: 600; }
.bdh-pick-nom { font-size: .8rem; font-weight: 900; color: #0f172a; white-space: nowrap; margin-left: auto; }

.bdh-trx {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    background: #fff; border: 1.5px solid rgba(15,23,42,.07);
    border-radius: 12px; padding: 10px 12px; margin-bottom: 8px;
}
.bdh-trx-l { display: flex; align-items: center; gap: 10px; min-width: 0; }
.bdh-trx-ico { flex: 0 0 auto; width: 34px; height: 34px; border-radius: 10px; display: grid; place-items: center; font-size: .78rem; }
.bdh-trx-ico.in { background: #dcfce7; color: #15803d; }
.bdh-trx-ico.out { background: #ffe4e6; color: #be123c; }
.bdh-trx-t { font-size: .8rem; font-weight: 800; color: #0f172a; line-height: 1.25; }
.bdh-trx-s { font-size: .68rem; color: #94a3b8; font-weight: 600; }
.bdh-trx-amt { font-size: .82rem; font-weight: 900; white-space: nowrap; }
.bdh-trx-amt.in { color: #15803d; }
.bdh-trx-amt.out { color: #be123c; }

@media (max-width: 575.98px) {
    .bdh-sum-grid { gap: 6px; }
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
            <h1>Tabungan Siswa</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item active">Tabungan Siswa</div>
            </div>
        </div>

        <div class="section-body">
            <?php if ($nisn === ''): ?>
                <!-- Pilih siswa -->
                <form method="GET" id="bdhTabunganSearchForm">
                    <div class="bdh-search">
                        <i class="fas fa-search"></i>
                        <input type="text" name="q" id="bdhTabunganSearch" value="<?= htmlspecialchars($q) ?>" placeholder="Cari nama atau NISN siswa...">
                    </div>
                </form>

                <?php if (!$api_ok): ?>
                    <div class="alert alert-warning" style="border-radius:12px;">
                        <strong>Data siswa belum tersedia.</strong>
                        <div class="small"><?= htmlspecialchars($summary['message'] ?? '') ?></div>
                    </div>
                <?php endif; ?>

                <div class="bdh-section-title">
                    <span class="dot" style="background:#0d9488;"></span>
                    Pilih Siswa
                </div>

                <?php if (empty($list)): ?>
                    <div class="card"><div class="card-body text-center py-5">
                        <i class="fas fa-user-slash fa-2x mb-3" style="color:#cbd5e1;"></i>
                        <div class="font-weight-bold" style="color:#334155;">Siswa tidak ditemukan</div>
                        <div class="small text-muted mt-1">Coba kata kunci lain.</div>
                    </div></div>
                <?php else: ?>
                    <?php foreach ($list as $r):
                        $nama = (string)($r['nama'] ?? '-');
                        $n = (string)($r['nisn'] ?? '');
                    ?>
                        <a class="bdh-pick" href="tabungan_siswa.php?nisn=<?= urlencode($n) ?>">
                            <span class="bdh-pick-ava"><?= htmlspecialchars(strtoupper(mb_substr($nama, 0, 1))) ?></span>
                            <span style="min-width:0;">
                                <span class="bdh-pick-nama d-block text-truncate"><?= htmlspecialchars($nama) ?></span>
                                <span class="bdh-pick-meta"><?= htmlspecialchars(bendahara_kelas_label((string)($r['kelas'] ?? ''))) ?> &bull; <?= htmlspecialchars($n) ?></span>
                            </span>
                            <span class="bdh-pick-nom"><i class="fas fa-piggy-bank" style="color:#0d9488;"></i></span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php else: ?>
                <!-- Detail tabungan -->
                <div class="d-flex justify-content-between align-items-center mb-3" style="gap:8px;flex-wrap:wrap;">
                    <a href="tabungan_siswa.php" class="btn btn-light" style="border-radius:12px;font-weight:800;">
                        <i class="fas fa-arrow-left mr-1"></i>Pilih Siswa Lain
                    </a>
                    <button type="button" onclick="window.print()" class="btn btn-primary" style="border-radius:12px;font-weight:800;">
                        <i class="fas fa-print mr-1"></i>Cetak
                    </button>
                </div>

                <?php if (!$tabs_ok): ?>
                    <div class="alert alert-warning" style="border-radius:12px;">
                        <strong>Data tabungan belum tersedia.</strong>
                        <div class="small"><?= htmlspecialchars($tabs_msg !== '' ? $tabs_msg : 'Periksa koneksi ke server tabungan (eTabs).') ?></div>
                    </div>
                <?php endif; ?>

                <div class="bdh-prof">
                    <div class="bdh-prof-ava"><i class="fas fa-piggy-bank"></i></div>
                    <div style="min-width:0;">
                        <div class="bdh-prof-nama"><?= htmlspecialchars((string)($student_info['nama'] ?? '-')) ?></div>
                        <div class="bdh-prof-meta">
                            <?= htmlspecialchars(bendahara_kelas_label((string)($student_info['kelas'] ?? ''))) ?>
                            &bull; NISN <?= htmlspecialchars((string)($student_info['nisn'] ?? $nisn)) ?>
                        </div>
                    </div>
                    <div class="bdh-prof-saldo">
                        <div class="lbl">Saldo Tabungan</div>
                        <div class="val"><?= bendahara_rupiah($saldo) ?></div>
                    </div>
                </div>

                <div class="bdh-sum-grid">
                    <div class="bdh-sum">
                        <div class="lbl">Saldo</div>
                        <div class="val"><?= bendahara_rupiah($saldo) ?></div>
                    </div>
                    <div class="bdh-sum">
                        <div class="lbl">Total Setoran</div>
                        <div class="val green"><?= bendahara_rupiah($total_setor) ?></div>
                    </div>
                    <div class="bdh-sum">
                        <div class="lbl">Total Tarikan</div>
                        <div class="val rose"><?= bendahara_rupiah($total_tarik) ?></div>
                    </div>
                </div>

                <div class="bdh-section-title">
                    <span class="dot" style="background:#0d9488;"></span>
                    Riwayat Transaksi
                    <span class="ml-auto small text-muted font-weight-bold"><?= count($transaksi) ?> transaksi</span>
                </div>

                <?php if (!empty($transaksi)): ?>
                    <?php foreach ($transaksi as $trx):
                        $jenis = strtolower((string)($trx['jenis'] ?? ''));
                        $masuk = ($jenis === 'setoran' || $jenis === 'setor');
                        $nominal = (int)($trx['nominal'] ?? $trx['jumlah'] ?? 0);
                        $ket = $masuk ? 'Setoran Tunai' : (!empty($trx['keterangan']) ? (string)$trx['keterangan'] : 'Penarikan Tunai');
                        $tgl = (string)($trx['tanggal'] ?? '');
                    ?>
                        <div class="bdh-trx">
                            <div class="bdh-trx-l">
                                <div class="bdh-trx-ico <?= $masuk ? 'in' : 'out' ?>">
                                    <i class="fas fa-arrow-<?= $masuk ? 'down' : 'up' ?>"></i>
                                </div>
                                <div style="min-width:0;">
                                    <div class="bdh-trx-t text-truncate"><?= htmlspecialchars($ket) ?></div>
                                    <div class="bdh-trx-s">
                                        <?= $tgl !== '' ? htmlspecialchars(date('d M Y', strtotime($tgl))) : '-' ?>
                                        <span>&bull;</span>
                                        <span><?= htmlspecialchars((string)($trx['petugas'] ?? 'Petugas')) ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="bdh-trx-amt <?= $masuk ? 'in' : 'out' ?>">
                                <?= $masuk ? '+' : '-' ?> <?= bendahara_rupiah($nominal) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="card"><div class="card-body text-center py-5">
                        <i class="fas fa-wallet fa-2x mb-3 text-muted"></i>
                        <div class="font-weight-bold" style="color:#334155;">Belum ada riwayat transaksi tabungan.</div>
                    </div></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<script>
(function () {
    var form = document.getElementById('bdhTabunganSearchForm');
    var input = document.getElementById('bdhTabunganSearch');
    if (!form || !input) return;
    var timer = null;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { form.submit(); }, 450);
    });
})();
</script>

<?php include '../templates/footer.php'; ?>
