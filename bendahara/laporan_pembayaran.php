<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once __DIR__ . '/_summary.php';

if (!isAuthorized(['bendahara', 'admin'])) {
    redirect('../login.php');
}

$page_title = 'Laporan Pembayaran';
$school_profile = getSchoolProfile($pdo);
$display_name = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Bendahara';
$nisn = trim((string)($_GET['nisn'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));

$summary = bendahara_fetch_summary();
$rows = $summary['status'] === 'success' ? $summary['data'] : [];
$api_ok = $summary['status'] === 'success';

$detail = null;
$detail_ok = false;
$detail_msg = '';
$billing_data = [];
$payments_data = [];
$arrears_data = [];
$student_info = [];

if ($nisn !== '') {
    $detail = fetchSibayarData($nisn, 'laporan');
    $detail_ok = (($detail['status'] ?? '') === 'success');
    $detail_msg = (string)($detail['message'] ?? '');
    $billing_data = is_array($detail['billing'] ?? null) ? $detail['billing'] : [];
    $payments_data = is_array($detail['payments'] ?? null) ? $detail['payments'] : [];
    $arrears_data = is_array($detail['tunggakan_tahun_ajaran_lama'] ?? null) ? $detail['tunggakan_tahun_ajaran_lama'] : [];
    $student_info = is_array($detail['student'] ?? null) ? $detail['student'] : [];

    // fallback identitas dari ringkasan
    if (empty($student_info)) {
        foreach ($rows as $r) {
            if ((string)($r['nisn'] ?? '') === $nisn) {
                $student_info = ['nama' => $r['nama'] ?? '-', 'nisn' => $r['nisn'] ?? '-', 'kelas' => $r['kelas'] ?? ''];
                break;
            }
        }
    }
}

/* daftar untuk pemilihan siswa */
$list = [];
foreach ($rows as $r) {
    $nama = (string)($r['nama'] ?? '');
    $n = (string)($r['nisn'] ?? '');
    if ($q !== '' && stripos($nama, $q) === false && stripos($n, $q) === false) continue;
    $r['_tunggakan'] = (int)($r['total_tunggakan'] ?? 0);
    $list[] = $r;
}
usort($list, static fn($a, $b) => $b['_tunggakan'] <=> $a['_tunggakan']);
$list = array_slice($list, 0, 40);

$total_tagihan_detail = 0;
$total_bayar_detail = 0;
$total_sisa_detail = 0;
foreach ($billing_data as $b) {
    $total_tagihan_detail += (int)($b['total_nominal'] ?? 0);
    $total_bayar_detail += (int)($b['total_bayar'] ?? 0);
    $total_sisa_detail += (int)($b['sisa_tagihan'] ?? 0);
}

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

.bdh-sum-grid { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 8px; margin-bottom: 16px; }
.bdh-sum {
    background: #fff; border: 1.5px solid rgba(15,23,42,.07);
    border-radius: 13px; padding: 10px 12px;
}
.bdh-sum .lbl { font-size: .62rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; }
.bdh-sum .val { font-size: .9rem; font-weight: 900; color: #0f172a; line-height: 1.25; }
.bdh-sum .val.rose { color: #be123c; }
.bdh-sum .val.green { color: #15803d; }

.bdh-bill {
    background: #fff; border: 1.5px solid rgba(15,23,42,.07);
    border-radius: 14px; padding: 13px 14px; margin-bottom: 10px;
}
.bdh-bill-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 6px; }
.bdh-bill-name { font-size: .86rem; font-weight: 800; color: #0f172a; line-height: 1.3; }
.bdh-bill-ta { font-size: .68rem; color: #94a3b8; font-weight: 600; }
.bdh-bill-badge { font-size: .62rem; font-weight: 900; padding: 3px 9px; border-radius: 999px; white-space: nowrap; }
.bdh-bill-badge.ok { background: #dcfce7; color: #15803d; }
.bdh-bill-badge.no { background: #ffe4e6; color: #be123c; }
.bdh-bill-amt { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 6px; }
.bdh-bill-amt .lbl { font-size: .6rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; }
.bdh-bill-amt .val { font-size: .78rem; font-weight: 900; color: #0f172a; }
.bdh-bill-amt .val.rose { color: #be123c; }
.bdh-bill-amt .val.green { color: #15803d; }

.bdh-pay-row {
    display: flex; align-items: center; justify-content: space-between;
    gap: 10px; padding: 8px 12px;
    background: #f8fafc; border: 1px solid #eef2f7;
    border-radius: 10px; margin-bottom: 6px;
    font-size: .76rem;
}
.bdh-pay-row .dt { color: #475569; font-weight: 700; }
.bdh-pay-row .amt { color: #15803d; font-weight: 900; }

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
.bdh-pick-nom { font-size: .8rem; font-weight: 900; color: #be123c; white-space: nowrap; margin-left: auto; }

@media (max-width: 575.98px) {
    .bdh-sum-grid { gap: 6px; }
    .bdh-sum { padding: 8px 9px; }
    .bdh-sum .val { font-size: .8rem; }
    .bdh-bill-amt .val { font-size: .72rem; }
    .bdh-prof { padding: 13px 14px; gap: 11px; }
    .bdh-prof-ava { width: 44px; height: 44px; border-radius: 13px; font-size: 1rem; }
    .bdh-prof-nama { font-size: .9rem; }
}
@media print {
    .main-sidebar, .navbar, .main-footer, .bdh-search, .section-header,
    .bdh-print-hide, .breadcrumb-item, .mobile-app-header { display: none !important; }
    .main-content { padding: 0 !important; }
    .bdh-prof { box-shadow: none; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .bdh-bill, .bdh-sum { break-inside: avoid; }
}
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Laporan Pembayaran</h1>
            <div class="section-header-breadcrumb bdh-print-hide">
                <div class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item"><a href="tagihan_siswa.php">Tagihan Siswa</a></div>
                <div class="breadcrumb-item active">Laporan Pembayaran</div>
            </div>
        </div>

        <div class="section-body">
            <?php if ($nisn === ''): ?>
                <!-- Pilih siswa -->
                <div class="bdh-print-hide">
                    <form method="GET" id="bdhLaporanSearchForm">
                        <div class="bdh-search">
                            <i class="fas fa-search"></i>
                            <input type="text" name="q" id="bdhLaporanSearch" value="<?= htmlspecialchars($q) ?>" placeholder="Cari nama atau NISN siswa...">
                        </div>
                    </form>

                    <?php if (!$api_ok): ?>
                        <div class="alert alert-warning" style="border-radius:12px;">
                            <strong>Data Sibayar belum tersedia.</strong>
                            <div class="small"><?= htmlspecialchars($summary['message'] ?? '') ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="bdh-section-title">
                        <span class="dot" style="background:#2563eb;"></span>
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
                            $lunas = !empty($r['is_lunas']);
                        ?>
                            <a class="bdh-pick" href="laporan_pembayaran.php?nisn=<?= urlencode($n) ?>">
                                <span class="bdh-pick-ava" style="<?= $lunas ? 'background:#dcfce7;color:#15803d;' : '' ?>"><?= htmlspecialchars(strtoupper(mb_substr($nama, 0, 1))) ?></span>
                                <span style="min-width:0;">
                                    <span class="bdh-pick-nama d-block text-truncate"><?= htmlspecialchars($nama) ?></span>
                                    <span class="bdh-pick-meta"><?= htmlspecialchars(bendahara_kelas_label((string)($r['kelas'] ?? ''))) ?> &bull; <?= htmlspecialchars($n) ?></span>
                                </span>
                                <?php if ($lunas): ?>
                                    <span class="bdh-pick-nom" style="color:#15803d;">LUNAS</span>
                                <?php else: ?>
                                    <span class="bdh-pick-nom"><?= bendahara_rupiah($r['_tunggakan']) ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- Detail siswa -->
                <div class="d-flex justify-content-between align-items-center mb-3 bdh-print-hide" style="gap:8px;flex-wrap:wrap;">
                    <a href="laporan_pembayaran.php" class="btn btn-light" style="border-radius:12px;font-weight:800;">
                        <i class="fas fa-arrow-left mr-1"></i>Pilih Siswa Lain
                    </a>
                    <?php if ($detail_ok): ?>
                        <button type="button" onclick="window.print()" class="btn btn-primary" style="border-radius:12px;font-weight:800;">
                            <i class="fas fa-print mr-1"></i>Cetak
                        </button>
                    <?php endif; ?>
                </div>

                <?php if (!$detail_ok): ?>
                    <div class="alert alert-warning" style="border-radius:12px;">
                        <strong>Detail pembayaran belum tersedia.</strong>
                        <div class="small"><?= htmlspecialchars($detail_msg !== '' ? $detail_msg : 'Periksa koneksi ke server Sibayar.') ?></div>
                    </div>
                <?php endif; ?>

                <div class="bdh-prof">
                    <div class="bdh-prof-ava"><?= htmlspecialchars(strtoupper(mb_substr((string)($student_info['nama'] ?? '-'), 0, 1))) ?></div>
                    <div style="min-width:0;">
                        <div class="bdh-prof-nama"><?= htmlspecialchars((string)($student_info['nama'] ?? '-')) ?></div>
                        <div class="bdh-prof-meta">
                            <?= htmlspecialchars(bendahara_kelas_label((string)($student_info['kelas'] ?? ''))) ?>
                            &bull; NISN <?= htmlspecialchars((string)($student_info['nisn'] ?? $nisn)) ?>
                        </div>
                        <div class="bdh-prof-meta" style="margin-top:2px;">
                            TA <?= htmlspecialchars((string)($detail['tahun_ajaran'] ?? '-')) ?>
                        </div>
                    </div>
                </div>

                <div class="bdh-sum-grid">
                    <div class="bdh-sum">
                        <div class="lbl">Total Tagihan</div>
                        <div class="val"><?= bendahara_rupiah($total_tagihan_detail) ?></div>
                    </div>
                    <div class="bdh-sum">
                        <div class="lbl">Sudah Dibayar</div>
                        <div class="val green"><?= bendahara_rupiah($total_bayar_detail) ?></div>
                    </div>
                    <div class="bdh-sum">
                        <div class="lbl">Sisa Tagihan</div>
                        <div class="val <?= $total_sisa_detail > 0 ? 'rose' : 'green' ?>"><?= bendahara_rupiah($total_sisa_detail) ?></div>
                    </div>
                </div>

                <div class="bdh-section-title">
                    <span class="dot" style="background:#2563eb;"></span>
                    Rincian Tagihan
                </div>

                <?php if (empty($billing_data)): ?>
                    <div class="card"><div class="card-body text-center py-4">
                        <i class="fas fa-file-invoice fa-2x mb-2" style="color:#cbd5e1;"></i>
                        <div class="font-weight-bold" style="color:#334155;">Tidak ada tagihan</div>
                    </div></div>
                <?php else: ?>
                    <?php foreach ($billing_data as $b):
                        $sisa = (int)($b['sisa_tagihan'] ?? 0);
                        $lunas = $sisa <= 0;
                    ?>
                        <div class="bdh-bill">
                            <div class="bdh-bill-head">
                                <div style="min-width:0;">
                                    <div class="bdh-bill-name"><?= htmlspecialchars((string)($b['nama_pembayaran'] ?? '-')) ?></div>
                                    <div class="bdh-bill-ta">
                                        <?= htmlspecialchars((string)($b['tipe_bayar'] ?? '')) ?>
                                        <?php if (!empty($b['tahun_ajaran'])): ?> &bull; TA <?= htmlspecialchars((string)$b['tahun_ajaran']) ?><?php endif; ?>
                                    </div>
                                </div>
                                <span class="bdh-bill-badge <?= $lunas ? 'ok' : 'no' ?>"><?= $lunas ? 'LUNAS' : 'BELUM' ?></span>
                            </div>
                            <div class="bdh-bill-amt">
                                <div>
                                    <div class="lbl">Nominal</div>
                                    <div class="val"><?= bendahara_rupiah($b['total_nominal'] ?? 0) ?></div>
                                </div>
                                <div>
                                    <div class="lbl">Dibayar</div>
                                    <div class="val green"><?= bendahara_rupiah($b['total_bayar'] ?? 0) ?></div>
                                </div>
                                <div>
                                    <div class="lbl">Sisa</div>
                                    <div class="val <?= $sisa > 0 ? 'rose' : 'green' ?>"><?= bendahara_rupiah($sisa) ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($arrears_data)): ?>
                    <div class="bdh-section-title" style="margin-top:14px;">
                        <span class="dot" style="background:#be123c;"></span>
                        Tunggakan Tahun Ajaran Lama
                    </div>
                    <?php foreach ($arrears_data as $ta_label => $ar):
                        $items = is_array($ar['items'] ?? null) ? $ar['items'] : [];
                    ?>
                        <div class="bdh-bill" style="background:#fffafb;border-color:rgba(244,63,94,.18);">
                            <div class="bdh-bill-head">
                                <div class="bdh-bill-name">TA <?= htmlspecialchars((string)($ar['tahun_ajaran'] ?? $ta_label)) ?></div>
                                <span class="bdh-bill-badge no"><?= bendahara_rupiah($ar['total_tunggakan'] ?? 0) ?></span>
                            </div>
                            <?php foreach ($items as $it): ?>
                                <div class="bdh-pay-row" style="background:#fff5f6;border-color:#fecdd3;">
                                    <span class="dt"><?= htmlspecialchars((string)($it['nama_pembayaran'] ?? '-')) ?></span>
                                    <span class="amt" style="color:#be123c;"><?= bendahara_rupiah($it['sisa_tagihan'] ?? 0) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="bdh-section-title" style="margin-top:14px;">
                    <span class="dot" style="background:#16a34a;"></span>
                    Riwayat Pembayaran
                </div>

                <?php if (empty($payments_data)): ?>
                    <div class="card"><div class="card-body text-center py-4">
                        <i class="fas fa-receipt fa-2x mb-2" style="color:#cbd5e1;"></i>
                        <div class="font-weight-bold" style="color:#334155;">Belum ada riwayat pembayaran</div>
                    </div></div>
                <?php else: ?>
                    <?php
                    usort($payments_data, static function ($a, $b) {
                        return strtotime((string)($b['tgl_bayar'] ?? 'now')) <=> strtotime((string)($a['tgl_bayar'] ?? 'now'));
                    });
                    foreach ($payments_data as $h):
                    ?>
                        <div class="bdh-pay-row">
                            <span class="dt">
                                <i class="far fa-calendar-alt mr-1"></i>
                                <?= !empty($h['tgl_bayar']) ? date('d/m/Y', strtotime((string)$h['tgl_bayar'])) : '-' ?>
                                <?php if (!empty($h['nama_pembayaran'])): ?>
                                    <span style="color:#94a3b8;font-weight:600;"> &bull; <?= htmlspecialchars((string)$h['nama_pembayaran']) ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="amt">+ <?= bendahara_rupiah($h['jumlah_bayar'] ?? 0) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<script>
(function () {
    var form = document.getElementById('bdhLaporanSearchForm');
    var input = document.getElementById('bdhLaporanSearch');
    if (!form || !input) return;
    var timer = null;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { form.submit(); }, 450);
    });
})();
</script>

<?php include '../templates/footer.php'; ?>
