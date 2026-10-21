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

/* ── Data tabungan dari modul Tabungan (database SIMAD) ────── */
require_once __DIR__ . '/tabungan/config/schema.php';
require_once __DIR__ . '/tabungan/config/helpers.php';
tabungan_ensure_schema($pdo);

$api_ok = true;
$tabs_ok = true;
$tabs_msg = '';

/* Detail tabungan siswa */
$student_info = ['nama' => '-', 'nisn' => $nisn, 'kelas' => ''];
$saldo = 0;
$total_setor = 0;
$total_tarik = 0;
$transaksi = [];

if ($nisn !== '') {
    $siswa_tabungan = tabungan_siswa($pdo, $nisn);
    if ($siswa_tabungan) {
        $student_info = [
            'nama' => (string)($siswa_tabungan['nama_siswa'] ?? '-'),
            'nisn' => (string)($siswa_tabungan['nisn'] ?? $nisn),
            'kelas' => (string)($siswa_tabungan['nama_kelas'] ?? ''),
        ];
    }

    $transaksi = tabungan_transaksi($pdo, ['nisn' => $nisn]);
    foreach ($transaksi as $trx) {
        $total_setor += (int)$trx['setor'];
        $total_tarik += (int)$trx['tarik'];
    }
    $saldo = $total_setor - $total_tarik;
}

/* daftar siswa untuk pemilihan */
$list = tabungan_daftar_siswa($pdo, [
    'q' => $q,
    'hanya_aktif' => true,
]);
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
                        <div class="small">Data siswa diambil dari data induk SIMAD.</div>
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
                        $nama = (string)($r['nama_siswa'] ?? '-');
                        $n = (string)($r['nisn'] ?? '');
                        $saldo_siswa = (int)($r['saldo'] ?? 0);
                    ?>
                        <a class="bdh-pick" href="tabungan_siswa.php?nisn=<?= urlencode($n) ?>">
                            <span class="bdh-pick-ava"><?= htmlspecialchars(strtoupper(mb_substr($nama, 0, 1))) ?></span>
                            <span style="min-width:0;">
                                <span class="bdh-pick-nama d-block text-truncate"><?= htmlspecialchars($nama) ?></span>
                                <span class="bdh-pick-meta"><?= htmlspecialchars((string)($r['nama_kelas'] ?? '-')) ?> &bull; <?= htmlspecialchars($n) ?></span>
                            </span>
                            <span class="bdh-pick-nom">
                                <?= $saldo_siswa > 0 ? bendahara_rupiah($saldo_siswa) : '<i class="fas fa-piggy-bank" style="color:#cbd5e1;"></i>' ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php else: ?>
                <!-- Detail tabungan -->
                <div class="d-flex justify-content-between align-items-center mb-3" style="gap:8px;flex-wrap:wrap;">
                    <a href="tabungan_siswa.php" class="btn btn-light" style="border-radius:12px;font-weight:800;">
                        <i class="fas fa-arrow-left mr-1"></i>Pilih Siswa Lain
                    </a>
                    <div class="d-flex" style="gap:8px;flex-wrap:wrap;">
                        <a href="tabungan_setor.php" class="btn btn-success" style="border-radius:12px;font-weight:800;">
                            <i class="fas fa-plus mr-1"></i>Setoran
                        </a>
                        <a href="tabungan_tarik.php" class="btn btn-danger" style="border-radius:12px;font-weight:800;">
                            <i class="fas fa-money-bill-transfer mr-1"></i>Penarikan
                        </a>
                        <a href="tabungan_cetak.php?jenis=siswa&nisn=<?= urlencode($nisn) ?>" target="_blank" class="btn btn-primary" style="border-radius:12px;font-weight:800;">
                            <i class="fas fa-print mr-1"></i>Cetak
                        </a>
                    </div>
                </div>

                <?php if (!$tabs_ok): ?>
                    <div class="alert alert-warning" style="border-radius:12px;">
                        <strong>Data tabungan belum tersedia.</strong>
                        <div class="small"><?= htmlspecialchars($tabs_msg !== '' ? $tabs_msg : 'Periksa data tabungan siswa di database SIMAD.') ?></div>
                    </div>
                <?php endif; ?>

                <div class="bdh-prof">
                    <div class="bdh-prof-ava"><i class="fas fa-piggy-bank"></i></div>
                    <div style="min-width:0;">
                        <div class="bdh-prof-nama"><?= htmlspecialchars((string)($student_info['nama'] ?? '-')) ?></div>
                        <div class="bdh-prof-meta">
                            <?= htmlspecialchars((string)($student_info['kelas'] ?? '-')) ?>
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
                        $masuk = (string)($trx['jenis'] ?? '') === 'ST';
                        $nominal = $masuk ? (int)$trx['setor'] : (int)$trx['tarik'];
                        $ket = $masuk ? 'Setoran Tunai' : 'Penarikan Tunai';
                        if (!$masuk) {
                            $ket_extra = trim((string)($trx['jenis_bayar'] ?? ''));
                            if ($ket_extra === '') {
                                $ket_extra = trim((string)($trx['keterangan_tarik'] ?? ''));
                            }
                            if ($ket_extra !== '') {
                                $ket = $ket_extra;
                            }
                        }
                        $tgl = (string)($trx['tgl'] ?? '');
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
