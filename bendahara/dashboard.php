<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once __DIR__ . '/_summary.php';

if (!isAuthorized(['bendahara', 'admin'])) {
    redirect('../login.php');
}

require_once __DIR__ . '/_pembayaran_summary.php';

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

/* ── Ringkasan pembayaran siswa ───────────────────────────── */
$pembayaran_summary = bendahara_pembayaran_summary($koneksi);

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
.bdh-payment-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}
@media (min-width: 1200px) {
    .bdh-payment-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
}
@media (max-width: 575.98px) {
    .bdh-hero { padding: 16px 16px; border-radius: 16px; }
    .bdh-payment-grid { gap: 10px; }
    .bdh-payment-grid .dash-stat-card { padding: 11px 12px; gap: 10px; }
    .bdh-payment-grid .dash-stat-ico { width: 40px; height: 40px; border-radius: 12px; font-size: .95rem; }
    .bdh-payment-grid .dash-stat-value { font-size: 1.15rem; }
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

            <!-- Pembayaran -->
            <div class="dash-section-head" style="margin-top:28px;">
                <div class="dsh-title">
                    <span class="dsh-dot" style="background:#16a34a;"></span>
                    Pembayaran
                </div>
                <a href="../pembayaran/index.php" class="small font-weight-bold" style="color:#2563eb;">Lihat detail</a>
            </div>

            <?php
            $payment_cards = [
                [
                    'label' => 'Siswa Aktif',
                    'value' => number_format((int) $pembayaran_summary['jml_siswa'], 0, ',', '.'),
                    'sub' => 'Di luar kelas alumni',
                    'icon' => 'fas fa-users',
                    'tone' => 'blue',
                    'compact' => false,
                ],
                [
                    'label' => 'Jenis Bayar',
                    'value' => number_format((int) $pembayaran_summary['jml_jenis'], 0, ',', '.') . ' jenis',
                    'sub' => 'Tagihan berjalan',
                    'icon' => 'fas fa-tags',
                    'tone' => 'teal',
                    'compact' => false,
                ],
                [
                    'label' => 'Total Pembayaran',
                    'value' => bendahara_rupiah($pembayaran_summary['total_bayar']),
                    'sub' => 'Tahun ajaran aktif',
                    'icon' => 'fas fa-money-bill-wave',
                    'tone' => 'green',
                    'compact' => true,
                ],
                [
                    'label' => 'Total Tagihan',
                    'value' => bendahara_rupiah($pembayaran_summary['total_tagihan_aktif']),
                    'sub' => 'Sisa tahun aktif',
                    'icon' => 'fas fa-file-invoice-dollar',
                    'tone' => 'amber',
                    'compact' => true,
                ],
            ];
            ?>
            <div class="bdh-payment-grid mb-3">
                <?php foreach ($payment_cards as $payment_card) : ?>
                    <div class="dash-stat-card tint-<?= htmlspecialchars($payment_card['tone'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="dash-stat-ico dsi-<?= htmlspecialchars($payment_card['tone'], ENT_QUOTES, 'UTF-8') ?>">
                            <i class="<?= htmlspecialchars($payment_card['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                        </div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-label"><?= htmlspecialchars($payment_card['label'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="dash-stat-value"<?= $payment_card['compact'] ? ' style="font-size:1.15rem;"' : '' ?>><?= htmlspecialchars($payment_card['value'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="dash-stat-sub"><span><?= htmlspecialchars($payment_card['sub'], ENT_QUOTES, 'UTF-8') ?></span></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card mb-0">
                <?php if (empty($pembayaran_summary['jenis_totals'])) : ?>
                    <div class="card-body text-center text-muted py-4">Belum ada jenis pembayaran aktif.</div>
                <?php else : ?>
                    <div class="list-group list-group-flush">
                        <?php
                        $payment_jenis_icons = [
                            'ekstrakurikuler' => 'fas fa-futbol',
                            'lks' => 'fas fa-book-open',
                            'ujian' => 'fas fa-file-alt',
                            'rekreasi' => 'fas fa-bus',
                        ];
                        $payment_jenis_tones = ['green', 'blue', 'amber', 'indigo'];
                        foreach ($pembayaran_summary['jenis_totals'] as $payment_jenis_index => $payment_jenis) :
                            $payment_jenis_nama = (string) $payment_jenis['nama_pembayaran'];
                            $payment_jenis_tampil = preg_replace('/ekstrakurikuler/i', 'Ekskul', $payment_jenis_nama);
                            $payment_jenis_lower = strtolower($payment_jenis_nama);
                            $payment_jenis_icon = 'fas fa-money-bill';
                            foreach ($payment_jenis_icons as $payment_keyword => $payment_icon) {
                                if (strpos($payment_jenis_lower, $payment_keyword) !== false) {
                                    $payment_jenis_icon = $payment_icon;
                                    break;
                                }
                            }
                            $payment_jenis_tone = $payment_jenis_tones[$payment_jenis_index % count($payment_jenis_tones)];
                        ?>
                            <div class="list-group-item d-flex align-items-center" style="gap:12px;padding:12px 16px;">
                                <span class="dash-stat-ico dsi-<?= htmlspecialchars($payment_jenis_tone, ENT_QUOTES, 'UTF-8') ?>" style="width:38px;height:38px;border-radius:12px;font-size:.9rem;flex:0 0 auto;">
                                    <i class="<?= htmlspecialchars($payment_jenis_icon, ENT_QUOTES, 'UTF-8') ?>"></i>
                                </span>
                                <div class="flex-grow-1" style="min-width:0;">
                                    <div class="font-weight-bold text-truncate" title="Total Bayar <?= htmlspecialchars($payment_jenis_tampil, ENT_QUOTES, 'UTF-8') ?>">Total Bayar <?= htmlspecialchars($payment_jenis_tampil, ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="small text-muted">Jenis pembayaran</div>
                                </div>
                                <div class="font-weight-bold text-right text-nowrap"><?= bendahara_rupiah($payment_jenis['total_bayar_jenis']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
