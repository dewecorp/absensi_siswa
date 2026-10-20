<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once __DIR__ . '/_summary.php';

if (!isAuthorized(['bendahara', 'admin'])) {
    redirect('../login.php');
}

$page_title = 'Dashboard Bendahara';
$school_profile = getSchoolProfile($pdo);
$display_name = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Bendahara';

/* ── Anggaran madrasah ─────────────────────────────────────── */
$total_sumber = 0;
$total_pengeluaran = 0;
$jumlah_kategori = 0;
try {
    $total_sumber = (int)$pdo->query("SELECT COALESCE(SUM(total),0) FROM tb_sumber_anggaran")->fetchColumn();
} catch (Throwable $e) {}
try {
    $total_pengeluaran = (int)$pdo->query("SELECT COALESCE(SUM(total),0) FROM tb_rencana_pengeluaran")->fetchColumn();
} catch (Throwable $e) {}
try {
    $jumlah_kategori = (int)$pdo->query("SELECT COUNT(*) FROM tb_kategori_anggaran")->fetchColumn();
} catch (Throwable $e) {}
$sisa_anggaran = $total_sumber - $total_pengeluaran;

/* ── Ringkasan tagihan siswa (Sibayar) ─────────────────────── */
$summary = bendahara_fetch_summary();
$siswa_rows = $summary['status'] === 'success' ? $summary['data'] : [];
$api_ok = $summary['status'] === 'success';

$total_tagihan = 0;
$total_dibayar = 0;
$total_tunggakan = 0;
$jml_siswa = count($siswa_rows);
$jml_belum = 0;
$jml_lunas = 0;
$top_tunggakan = [];

foreach ($siswa_rows as $row) {
    $tagihan = (int)($row['total_tunggakan_aktif'] ?? 0)
        + (int)($row['total_tunggakan_tahun_lama'] ?? 0)
        + (int)($row['total_sudah_bayar'] ?? 0);
    $dibayar = (int)($row['total_sudah_bayar'] ?? 0);
    $tunggakan = (int)($row['total_tunggakan'] ?? 0);

    $total_tagihan += $tagihan;
    $total_dibayar += $dibayar;
    $total_tunggakan += $tunggakan;

    if (!empty($row['is_lunas'])) {
        $jml_lunas++;
    } else {
        $jml_belum++;
        $top_tunggakan[] = [
            'nama' => (string)($row['nama'] ?? '-'),
            'nisn' => (string)($row['nisn'] ?? ''),
            'kelas' => (string)($row['kelas'] ?? ''),
            'tagihan' => $tagihan,
            'dibayar' => $dibayar,
            'tunggakan' => $tunggakan,
        ];
    }
}
usort($top_tunggakan, static fn($a, $b) => $b['tunggakan'] <=> $a['tunggakan']);
$top_tunggakan = array_slice($top_tunggakan, 0, 8);

$persen_bayar = $total_tagihan > 0 ? round(($total_dibayar / $total_tagihan) * 100, 1) : 0;

include '../templates/header.php';
include_once '../templates/sidebar.php';
?>
<style>
.bdh-hero {
    background: linear-gradient(135deg, #0f766e, #0891b2 55%, #2563eb);
    border-radius: 18px;
    padding: 20px 22px;
    color: #fff;
    box-shadow: 0 10px 30px rgba(8, 145, 178, .25);
    position: relative;
    overflow: hidden;
}
.bdh-hero::after {
    content: '';
    position: absolute;
    right: -40px; top: -40px;
    width: 190px; height: 190px;
    border-radius: 50%;
    background: rgba(255, 255, 255, .12);
}
.bdh-hero h3 { font-size: 1.15rem; font-weight: 900; margin: 0 0 2px; color: #fff !important; }
.bdh-hero p { margin: 0; font-size: .84rem; opacity: .95; color: #fff !important; }
.bdh-hero-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; position: relative; z-index: 1; color: #fff; }
.bdh-chip {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(255, 255, 255, .18);
    border: 1px solid rgba(255, 255, 255, .25);
    border-radius: 999px;
    padding: 5px 12px;
    font-size: .74rem; font-weight: 700;
    color: #fff !important;
}
.bdh-chip i { color: #fff !important; }
.bdh-progress {
    height: 8px; border-radius: 999px; background: #e2e8f0; overflow: hidden; margin-top: 6px;
}
.bdh-progress > span {
    display: block; height: 100%; border-radius: 999px;
    background: linear-gradient(90deg, #16a34a, #22c55e);
}
.bdh-tcard {
    display: block;
    height: 100%;
    background: #fff;
    border: 1.5px solid rgba(15, 23, 42, .07);
    border-radius: 14px;
    padding: 13px 14px;
    text-decoration: none !important;
    transition: box-shadow .18s, transform .18s, border-color .18s;
}
.bdh-tcard:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(15, 23, 42, .09); border-color: #fecdd3; }
.bdh-tcard-top { display: flex; align-items: flex-start; gap: 10px; }
.bdh-tcard-ava {
    flex: 0 0 auto; width: 38px; height: 38px; border-radius: 11px;
    display: grid; place-items: center;
    background: #fff1f2; color: #be123c; font-weight: 900; font-size: .84rem;
}
.bdh-tcard-id { flex: 1; min-width: 0; }
.bdh-tcard-nama { font-size: .82rem; font-weight: 800; color: #0f172a; line-height: 1.25; }
.bdh-tcard-meta { font-size: .68rem; color: #94a3b8; font-weight: 600; }
.bdh-tcard-badge {
    flex: 0 0 auto; font-size: .58rem; font-weight: 900; letter-spacing: .03em;
    padding: 3px 8px; border-radius: 999px; background: #ffe4e6; color: #be123c;
    text-transform: uppercase; white-space: nowrap;
}
.bdh-tcard-amt { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; margin-top: 11px; }
.bdh-tcard-amt .lbl { font-size: .62rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; }
.bdh-tcard-amt .val { font-size: .98rem; font-weight: 900; color: #be123c; white-space: nowrap; }
.bdh-tcard-bar { height: 7px; border-radius: 999px; background: #f1f5f9; overflow: hidden; margin: 7px 0 9px; }
.bdh-tcard-bar > span { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #16a34a, #22c55e); }
.bdh-tcard-foot { display: flex; justify-content: space-between; gap: 8px; font-size: .66rem; color: #94a3b8; font-weight: 600; }
.bdh-tcard-foot b { display: block; font-size: .74rem; color: #334155; font-weight: 800; }
@media (max-width: 575.98px) {
    .bdh-tcard { padding: 12px 13px; }
    .bdh-tcard-amt .val { font-size: .92rem; }
    .bdh-hero { padding: 16px 16px; border-radius: 16px; }
}
</style>

<div class="main-content">
    <?php include '../templates/mobile_navbar.php'; ?>
    <section class="section">
        <div class="section-header">
            <h1>Dashboard Bendahara</h1>
        </div>

        <div class="section-body">
            <!-- Hero -->
            <div class="bdh-hero mb-4">
                <h3>Assalamualaikum, <?= htmlspecialchars($display_name) ?></h3>
                <p>Ringkasan keuangan <?= htmlspecialchars($school_profile['nama_madrasah'] ?? 'Madrasah') ?></p>
                <div class="bdh-hero-chips">
                    <span class="bdh-chip"><i class="far fa-calendar-alt"></i><?= date('d M Y') ?></span>
                    <span class="bdh-chip"><i class="fas fa-graduation-cap"></i>TA <?= htmlspecialchars($school_profile['tahun_ajaran'] ?? '-') ?></span>
                    <span class="bdh-chip"><i class="fas fa-calendar-check"></i><?= htmlspecialchars($school_profile['semester'] ?? '-') ?></span>
                </div>
            </div>

            <!-- Menu mobile -->
            <div class="d-lg-none mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0 font-weight-bold">Menu</h6>
                    <span class="badge badge-primary badge-pill">Semua Fitur</span>
                </div>
                <?php
                if (!isset($menu_items)) {
                    $menu_items = [];
                }
                $mobile_menu_groups = function_exists('get_mobile_menu_groups') ? get_mobile_menu_groups($menu_items) : ['single' => [], 'grouped' => []];
                $single_items = $mobile_menu_groups['single'];
                $grouped_items = $mobile_menu_groups['grouped'];
                ?>
                <?php if (!empty($single_items) || !empty($grouped_items)): ?>
                    <div class="row mapp-menu-grid">
                        <?php foreach ($single_items as $mg_idx => $item): ?>
                            <div class="col-3 mb-3">
                                <a href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none text-center d-block">
                                    <div class="mx-auto mb-2 menu-grid-icon mg-c<?= ($mg_idx % 8) + 1 ?>">
                                        <i class="<?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                                    </div>
                                    <div class="mobile-menu-label small text-dark"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                        <?php $tile_base = count($single_items); ?>
                        <?php foreach ($grouped_items as $g_idx => $group): ?>
                            <div class="col-3 mb-3">
                                <a href="<?= htmlspecialchars(mapp_tile_url($group['title']), ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none text-center d-block">
                                    <div class="mx-auto mb-2 menu-grid-icon mg-c<?= (($tile_base + $g_idx) % 8) + 1 ?>">
                                        <i class="<?= htmlspecialchars($group['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                                    </div>
                                    <div class="mobile-menu-label small text-dark"><?= htmlspecialchars($group['title'], ENT_QUOTES, 'UTF-8') ?></div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!$api_ok): ?>
                <div class="alert alert-warning d-flex align-items-center" style="gap:10px;border-radius:12px;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Data tagihan belum tersambung.</strong>
                        <div class="small"><?= htmlspecialchars($summary['message'] ?? 'Periksa pengaturan endpoint Sibayar.') ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Ringkasan Tagihan -->
            <div class="dash-section-head">
                <div class="dsh-title">
                    <span class="dsh-dot" style="background:#2563eb;"></span>
                    Ringkasan Tagihan Siswa
                </div>
                <span class="dsh-date"><?= date('d M Y') ?></span>
            </div>
            <div class="row dash-stat-row">
                <div class="col-6 col-xl-3">
                    <div class="dash-stat-card tint-blue">
                        <div class="dash-stat-ico dsi-blue"><i class="fas fa-file-invoice-dollar"></i></div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-label">Tagihan</div>
                            <div class="dash-stat-value" style="font-size:1.15rem;" title="<?= htmlspecialchars(bendahara_rupiah($total_tagihan)) ?>"><?= bendahara_rupiah_short($total_tagihan) ?></div>
                            <div class="dash-stat-sub"><span style="color:#2563eb;"><?= $jml_siswa ?> siswa</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-xl-3">
                    <div class="dash-stat-card tint-green">
                        <div class="dash-stat-ico dsi-green"><i class="fas fa-check-circle"></i></div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-label">Dibayar</div>
                            <div class="dash-stat-value" style="font-size:1.15rem;" title="<?= htmlspecialchars(bendahara_rupiah($total_dibayar)) ?>"><?= bendahara_rupiah_short($total_dibayar) ?></div>
                            <div class="dash-stat-sub"><span style="color:#15803d;"><?= $persen_bayar ?>% dari tagihan</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-xl-3">
                    <div class="dash-stat-card tint-rose">
                        <div class="dash-stat-ico dsi-rose"><i class="fas fa-hourglass-half"></i></div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-label">Tunggakan</div>
                            <div class="dash-stat-value" style="font-size:1.15rem;" title="<?= htmlspecialchars(bendahara_rupiah($total_tunggakan)) ?>"><?= bendahara_rupiah_short($total_tunggakan) ?></div>
                            <div class="dash-stat-sub"><span style="color:#be123c;"><?= $jml_belum ?> siswa belum lunas</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-xl-3">
                    <div class="dash-stat-card tint-teal">
                        <div class="dash-stat-ico dsi-teal"><i class="fas fa-user-check"></i></div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-label">Lunas</div>
                            <div class="dash-stat-value"><?= $jml_lunas ?></div>
                            <div class="dash-stat-sub"><span style="color:#0891b2;">dari <?= $jml_siswa ?> siswa</span></div>
                            <div class="bdh-progress"><span style="width:<?= $jml_siswa > 0 ? round(($jml_lunas / $jml_siswa) * 100) : 0 ?>%"></span></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Anggaran -->
            <div class="dash-section-head" style="margin-top:8px;">
                <div class="dsh-title">
                    <span class="dsh-dot" style="background:#d97706;"></span>
                    Anggaran Madrasah
                </div>
                <a href="../admin/rab_madrasah.php?session_type=bendahara" class="small font-weight-bold" style="color:#2563eb;">Kelola RAB</a>
            </div>
            <div class="row dash-stat-row">
                <div class="col-12 col-sm-4 col-lg-4">
                    <div class="dash-stat-card tint-indigo">
                        <div class="dash-stat-ico dsi-indigo"><i class="fas fa-wallet"></i></div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-label">Sumber Dana</div>
                            <div class="dash-stat-value" style="font-size:1.15rem;" title="<?= htmlspecialchars(bendahara_rupiah($total_sumber)) ?>"><?= bendahara_rupiah_short($total_sumber) ?></div>
                            <div class="dash-stat-sub"><span style="color:#4338ca;"><?= $jumlah_kategori ?> kategori</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-sm-4 col-lg-4">
                    <div class="dash-stat-card tint-amber">
                        <div class="dash-stat-ico dsi-amber"><i class="fas fa-exchange-alt"></i></div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-label">Pengeluaran</div>
                            <div class="dash-stat-value" style="font-size:1.15rem;" title="<?= htmlspecialchars(bendahara_rupiah($total_pengeluaran)) ?>"><?= bendahara_rupiah_short($total_pengeluaran) ?></div>
                            <div class="dash-stat-sub"><span style="color:#b45309;">RAB tersusun</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-sm-4 col-lg-4">
                    <div class="dash-stat-card <?= $sisa_anggaran >= 0 ? 'tint-green' : 'tint-rose' ?>">
                        <div class="dash-stat-ico <?= $sisa_anggaran >= 0 ? 'dsi-green' : 'dsi-rose' ?>"><i class="fas fa-balance-scale"></i></div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-label">Sisa Anggaran</div>
                            <div class="dash-stat-value" style="font-size:1.15rem;" title="<?= htmlspecialchars(bendahara_rupiah($sisa_anggaran)) ?>"><?= bendahara_rupiah_short($sisa_anggaran) ?></div>
                            <div class="dash-stat-sub"><span style="color:<?= $sisa_anggaran >= 0 ? '#15803d' : '#be123c' ?>;"><?= $sisa_anggaran >= 0 ? 'Surplus' : 'Defisit' ?></span></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dash-section-head" style="margin-top:8px;">
                <div class="dsh-title">
                    <span class="dsh-dot" style="background:#be123c;"></span>
                    Tunggakan Terbesar
                </div>
                <a href="tagihan_siswa.php?status=belum" class="small font-weight-bold" style="color:#2563eb;">Lihat Semua</a>
            </div>

            <?php if (empty($top_tunggakan)): ?>
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="text-center py-4">
                            <div style="width:52px;height:52px;border-radius:15px;background:#f0fdf4;display:grid;place-items:center;margin:0 auto 10px;">
                                <i class="fas fa-check-circle" style="color:#16a34a;font-size:1.3rem;"></i>
                            </div>
                            <div style="font-weight:800;color:#334155;font-size:.88rem;">
                                <?= $api_ok ? 'Tidak ada tunggakan' : 'Data belum tersedia' ?>
                            </div>
                            <div style="font-size:.76rem;color:#94a3b8;margin-top:2px;">
                                <?= $api_ok ? 'Semua siswa sudah lunas.' : 'Sambungkan endpoint Sibayar terlebih dahulu.' ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($top_tunggakan as $t): ?>
                        <?php
                        $t_tagihan = max(0, (int)($t['tagihan'] ?? 0));
                        $t_dibayar = max(0, (int)($t['dibayar'] ?? 0));
                        $t_persen = $t_tagihan > 0 ? min(100, round(($t_dibayar / $t_tagihan) * 100)) : 0;
                        ?>
                        <div class="col-12 col-md-6 col-xl-4 mb-3">
                            <a class="bdh-tcard" href="laporan_pembayaran.php?nisn=<?= urlencode($t['nisn']) ?>">
                                <div class="bdh-tcard-top">
                                    <span class="bdh-tcard-ava"><?= htmlspecialchars(strtoupper(mb_substr($t['nama'], 0, 1))) ?></span>
                                    <span class="bdh-tcard-id">
                                        <span class="bdh-tcard-nama d-block text-truncate"><?= htmlspecialchars($t['nama']) ?></span>
                                        <span class="bdh-tcard-meta"><?= htmlspecialchars(bendahara_kelas_label($t['kelas'])) ?> &bull; NISN <?= htmlspecialchars($t['nisn']) ?></span>
                                    </span>
                                    <span class="bdh-tcard-badge">Belum lunas</span>
                                </div>
                                <div class="bdh-tcard-amt">
                                    <span class="lbl">Sisa Tunggakan</span>
                                    <span class="val"><?= bendahara_rupiah($t['tunggakan']) ?></span>
                                </div>
                                <div class="bdh-tcard-bar"><span style="width:<?= $t_persen ?>%"></span></div>
                                <div class="bdh-tcard-foot">
                                    <span>Dibayar <?= $t_persen ?>%<b><?= bendahara_rupiah($t_dibayar) ?></b></span>
                                    <span class="text-right">Total Tagihan<b><?= bendahara_rupiah($t_tagihan) ?></b></span>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
