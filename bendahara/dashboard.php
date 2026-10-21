<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once __DIR__ . '/_summary.php';

if (!isAuthorized(['bendahara', 'admin'])) {
    redirect('../login.php');
}

require_once __DIR__ . '/_pembayaran_summary.php';

/* ── Ringkasan gaji & honor (modul Gaji) ───────────────────── */
require_once __DIR__ . '/gaji/config/helpers.php';
require_once __DIR__ . '/gaji/config/summary.php';
try {
    $gaji_ringkasan = gaji_ringkasan_data($koneksi);
} catch (Throwable $e) {
    $gaji_ringkasan = ['stats' => [], 'periode_aktif_label' => ''];
}
$gaji_stats = $gaji_ringkasan['stats'] ?? [];
$gaji_periode_label = $gaji_ringkasan['periode_aktif_label'] ?? '';

$page_title = 'Dashboard Bendahara';
$school_profile = getSchoolProfile($pdo);
$display_name = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Bendahara';

/* ── Anggaran madrasah ─────────────────────────────────────── */
$total_sumber = 0;
$total_pengeluaran = 0;
$jumlah_sumber = 0;
$jumlah_rencana = 0;
$nama_sumber = [];
try {
    $rows_sumber = $pdo->query("SELECT uraian FROM tb_sumber_anggaran ORDER BY id_sumber ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows_sumber as $row_sumber) {
        $uraian = trim((string)($row_sumber['uraian'] ?? ''));
        if ($uraian !== '') {
            $nama_sumber[] = $uraian;
        }
    }
    $jumlah_sumber = count($rows_sumber);
    $total_sumber = (int)$pdo->query("SELECT COALESCE(SUM(total),0) FROM tb_sumber_anggaran")->fetchColumn();
} catch (Throwable $e) {}
try {
    $total_pengeluaran = (int)$pdo->query("SELECT COALESCE(SUM(total),0) FROM tb_rencana_pengeluaran")->fetchColumn();
    $jumlah_rencana = (int)$pdo->query("SELECT COUNT(*) FROM tb_rencana_pengeluaran")->fetchColumn();
} catch (Throwable $e) {}
$sisa_anggaran = $total_sumber - $total_pengeluaran;

/* ── Ringkasan pembayaran siswa ───────────────────────────── */
$pembayaran_summary = bendahara_pembayaran_summary($koneksi);

/* ── Ringkasan tabungan siswa (modul Tabungan) ────────────── */
require_once __DIR__ . '/tabungan/config/schema.php';
require_once __DIR__ . '/tabungan/config/helpers.php';
tabungan_ensure_schema($pdo);
$tabungan_summary = tabungan_total($pdo);
$tabungan_siswa_aktif = tabungan_siswa_aktif($pdo);

/* ── Aktivitas Pembayaran (CRUD, login, logout) ───────────── */
if (!isset($_SESSION['last_log_cleanup']) || (time() - (int) $_SESSION['last_log_cleanup']) > 3600) {
    mysqli_query($koneksi, "DELETE FROM log_aktivitas WHERE created_at < NOW() - INTERVAL 24 HOUR");
    $_SESSION['last_log_cleanup'] = time();
}
$q_aktivitas_count = mysqli_query($koneksi, "SELECT COUNT(*) AS total FROM log_aktivitas WHERE created_at >= NOW() - INTERVAL 24 HOUR");
$d_aktivitas_count = $q_aktivitas_count ? mysqli_fetch_assoc($q_aktivitas_count) : null;
$jml_aktivitas = (int) ($d_aktivitas_count['total'] ?? 0);
$q_aktivitas = mysqli_query($koneksi, "
    SELECT l.*, p.nama_lengkap
    FROM log_aktivitas l
    LEFT JOIN pengguna p ON l.id_pengguna = p.id_pengguna
    WHERE l.created_at >= NOW() - INTERVAL 24 HOUR
    ORDER BY l.created_at DESC
    LIMIT 200
");

/* ── Aktivitas CRUD SIMAD (Tabungan, Gaji, dsb) dari tb_activity_log ── */
$aktivitas_simad = [];
try {
    $st_aktivitas_simad = $pdo->prepare("
        SELECT username, action, description, created_at
        FROM tb_activity_log
        WHERE created_at >= NOW() - INTERVAL 24 HOUR
          AND action NOT IN ('Login', 'Logout')
        ORDER BY created_at DESC
        LIMIT 200
    ");
    $st_aktivitas_simad->execute();
    $aktivitas_simad = $st_aktivitas_simad->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $aktivitas_simad = [];
}

/* ── Gabungkan kedua sumber menjadi satu daftar aktivitas (terbaru di atas) ── */
$aktivitas_gabung = [];
if ($q_aktivitas) {
    while ($row_aktivitas = mysqli_fetch_assoc($q_aktivitas)) {
        $aktivitas_gabung[] = [
            'created_at' => (string) ($row_aktivitas['created_at'] ?? ''),
            'jenis' => (string) ($row_aktivitas['jenis_aktivitas'] ?? ''),
            'deskripsi' => (string) ($row_aktivitas['deskripsi'] ?? ''),
            'nama' => (string) ($row_aktivitas['nama_lengkap'] ?? 'Sistem'),
        ];
    }
}
foreach ($aktivitas_simad as $row_aktivitas) {
    $aktivitas_gabung[] = [
        'created_at' => (string) ($row_aktivitas['created_at'] ?? ''),
        'jenis' => (string) ($row_aktivitas['action'] ?? ''),
        'deskripsi' => (string) ($row_aktivitas['description'] ?? ''),
        'nama' => (string) ($row_aktivitas['username'] ?? 'Sistem'),
    ];
}
usort($aktivitas_gabung, function ($a, $b) {
    $sa = strtotime((string) $a['created_at']);
    $sb = strtotime((string) $b['created_at']);
    if ($sa === $sb) {
        return 0;
    }
    if ($sa === false) {
        return 1;
    }
    if ($sb === false) {
        return -1;
    }
    return $sb <=> $sa;
});
$aktivitas_gabung = array_slice($aktivitas_gabung, 0, 200);
$jml_aktivitas = count($aktivitas_gabung);

include '../templates/header.php';
include_once '../templates/sidebar.php';
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(app_base_path() . '/assets/css/gaji-module.css?v=' . (is_readable(__DIR__ . '/../assets/css/gaji-module.css') ? (string) filemtime(__DIR__ . '/../assets/css/gaji-module.css') : '1'), ENT_QUOTES, 'UTF-8'); ?>">
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
    .bdh-payment-grid .dash-stat-card { padding: 11px 12px; gap: 10px; flex-direction: column; align-items: flex-start; }
    .bdh-payment-grid .dash-stat-ico { width: 40px; height: 40px; border-radius: 12px; font-size: .95rem; }
    .bdh-payment-grid .dash-stat-value { font-size: 1.05rem; white-space: nowrap; }
}

/* ── Kartu jenis bayar (gaya mobile app) ───────────────────── */
.bdh-jenis-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 12px; }
.bdh-jenis {
    display: flex; align-items: center; gap: 12px;
    background: #fff;
    border: 1.5px solid rgba(15, 23, 42, .07);
    border-radius: 16px;
    padding: 13px 14px;
    box-shadow: 0 4px 14px rgba(15, 23, 42, .05);
    transition: transform .18s, box-shadow .18s, border-color .18s;
}
.bdh-jenis:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(15, 23, 42, .1); border-color: #bfdbfe; }
.bdh-jenis-ico {
    flex: 0 0 auto; width: 42px; height: 42px; border-radius: 13px;
    display: grid; place-items: center; font-size: .95rem;
}
.bdh-jenis-body { flex: 1 1 auto; min-width: 0; }
.bdh-jenis-nama {
    font-size: .84rem; font-weight: 800; color: #0f172a; line-height: 1.25;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.bdh-jenis-meta { font-size: .68rem; font-weight: 600; color: #94a3b8; }
.bdh-jenis-total { flex: 0 0 auto; font-size: .88rem; font-weight: 900; color: #0f172a; white-space: nowrap; }
.bdh-jenis.tint-blue   { background: #f0f7ff; border-color: rgba(59,130,246,.18); }
.bdh-jenis.tint-teal   { background: #f0fdff; border-color: rgba(6,182,212,.18); }
.bdh-jenis.tint-amber  { background: #fffdf0; border-color: rgba(245,158,11,.18); }
.bdh-jenis.tint-green  { background: #f3fdf5; border-color: rgba(34,197,94,.18); }
.bdh-jenis.tint-indigo { background: #f3f4ff; border-color: rgba(99,102,241,.18); }
.bdh-jenis.tint-violet { background: #f7f5ff; border-color: rgba(167,139,250,.18); }
@media (max-width: 575.98px) {
    .bdh-jenis-grid { gap: 10px; }
    .bdh-jenis { padding: 11px 12px; gap: 10px; }
    .bdh-jenis-ico { width: 36px; height: 36px; border-radius: 11px; font-size: .82rem; }
    .bdh-jenis-total { font-size: .8rem; }
}

/* ── Timeline aktivitas (gaya sama dengan Dashboard Admin) ── */
.act-feed { position: relative; padding: 4px 0; }
.act-feed::before {
    content: '';
    position: absolute;
    left: 23px;
    top: 8px; bottom: 8px;
    width: 2px;
    border-radius: 2px;
    background: #e2e8f0;
}
.act-item {
    position: relative;
    display: flex;
    gap: 14px;
    margin-bottom: 14px;
}
.act-item:last-child { margin-bottom: 0; }
.act-dot {
    flex: 0 0 auto;
    width: 46px; height: 46px;
    border-radius: 14px;
    display: grid; place-items: center;
    font-size: 1rem;
    color: #fff !important;
    box-shadow: 0 4px 12px var(--act-shadow, rgba(37,99,235,.22));
    background: var(--act-bg, #2563eb);
    z-index: 1;
    position: relative;
}
.act-dot i { color: #fff !important; }
.act-body {
    flex: 1; min-width: 0;
    background: var(--act-card-bg, #eff6ff);
    border: 1.5px solid var(--act-card-border, #bfdbfe);
    border-radius: 14px;
    padding: 12px 14px;
    transition: box-shadow .18s, transform .18s;
}
.act-body:hover {
    box-shadow: 0 6px 20px rgba(15,23,42,.08);
    transform: translateY(-1px);
}
.act-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 6px;
}
.act-name {
    font-size: .88rem;
    font-weight: 800;
    color: #0f172a;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.act-ago {
    font-size: .72rem;
    font-weight: 700;
    color: #94a3b8;
    white-space: nowrap;
    display: flex; align-items: center; gap: 4px;
}
.act-action-pill {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: .7rem; font-weight: 800;
    padding: 3px 10px; border-radius: 999px;
    background: var(--act-bg, #2563eb);
    color: #fff;
    margin-bottom: 6px;
}
.act-desc {
    font-size: .82rem;
    color: #334155;
    line-height: 1.5;
    margin-bottom: 6px;
    word-break: break-word;
}
.act-footer {
    display: flex; align-items: center; gap: 12px;
    font-size: .7rem; color: #94a3b8; font-weight: 600;
    border-top: 1px solid rgba(15,23,42,.06);
    padding-top: 6px; margin-top: 2px;
    flex-wrap: wrap;
}
.act-footer span { display: flex; align-items: center; gap: 4px; }
.act-scroll::-webkit-scrollbar { width: 5px; }
.act-scroll::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 5px; }
.act-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 5px; }
.act-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
@media (max-width: 575.98px) {
    .act-dot { width: 38px; height: 38px; border-radius: 11px; font-size: .88rem; }
    .act-feed::before { left: 18px; }
    .act-body { padding: 10px 12px; }
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
            <div class="row dash-stat-row stack-mobile">
                <div class="col-12 col-sm-4 col-lg-4">
                    <div class="dash-stat-card tint-indigo">
                        <div class="dash-stat-ico dsi-indigo"><i class="fas fa-wallet"></i></div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-label">Sumber Dana</div>
                            <div class="dash-stat-value dash-stat-value-sm" title="<?= htmlspecialchars(implode(', ', $nama_sumber)) ?>"><?= bendahara_rupiah($total_sumber) ?></div>
                            <div class="dash-stat-sub"><span style="color:#4338ca;"><?= $jumlah_sumber ?> sumber dana</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-sm-4 col-lg-4">
                    <div class="dash-stat-card tint-amber">
                        <div class="dash-stat-ico dsi-amber"><i class="fas fa-exchange-alt"></i></div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-label">Pengeluaran</div>
                            <div class="dash-stat-value dash-stat-value-sm" title="<?= htmlspecialchars(bendahara_rupiah($total_pengeluaran)) ?>"><?= bendahara_rupiah($total_pengeluaran) ?></div>
                            <div class="dash-stat-sub"><span style="color:#b45309;"><?= $jumlah_rencana ?> rencana pengeluaran</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-sm-4 col-lg-4">
                    <div class="dash-stat-card <?= $sisa_anggaran >= 0 ? 'tint-green' : 'tint-rose' ?>">
                        <div class="dash-stat-ico <?= $sisa_anggaran >= 0 ? 'dsi-green' : 'dsi-rose' ?>"><i class="fas fa-balance-scale"></i></div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-label">Sisa Anggaran</div>
                            <div class="dash-stat-value dash-stat-value-sm" title="<?= htmlspecialchars(bendahara_rupiah($sisa_anggaran)) ?>"><?= bendahara_rupiah($sisa_anggaran) ?></div>
                            <div class="dash-stat-sub"><span style="color:<?= $sisa_anggaran >= 0 ? '#15803d' : '#be123c' ?>;"><?= $sisa_anggaran >= 0 ? 'Surplus' : 'Defisit' ?></span></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ringkasan Gaji -->
            <div class="dash-section-head" style="margin-top:28px;">
                <div class="dsh-title">
                    <span class="dsh-dot" style="background:#2563eb;"></span>
                    Ringkasan Gaji
                    <span style="display:inline-flex;align-items:center;justify-content:center;background:#2563eb;color:#fff;font-size:.72rem;font-weight:900;border-radius:999px;padding:2px 10px;"><?= htmlspecialchars($gaji_periode_label, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <a href="<?= htmlspecialchars(app_base_path() . '/bendahara/gaji_ringkasan.php', ENT_QUOTES, 'UTF-8') ?>" class="small font-weight-bold" style="color:#2563eb;">Lihat detail</a>
            </div>
            <div class="row dash-stat-row stack-mobile">
                <?php foreach (gaji_ringkasan_cards($gaji_stats) as $gaji_card) : ?>
                    <div class="col-lg-3 col-md-6 col-sm-6 col-6">
                        <div class="dash-stat-card tint-<?= htmlspecialchars($gaji_card['tone'], ENT_QUOTES, 'UTF-8') ?>">
                            <div class="dash-stat-ico dsi-<?= htmlspecialchars($gaji_card['tone'], ENT_QUOTES, 'UTF-8') ?>">
                                <i class="fas <?= htmlspecialchars($gaji_card['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                            </div>
                            <div class="dash-stat-body">
                                <div class="dash-stat-label"><?= htmlspecialchars($gaji_card['label'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="dash-stat-value dash-stat-value-sm" title="<?= htmlspecialchars($gaji_card['value'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($gaji_card['value'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="dash-stat-sub"><span><?= htmlspecialchars($gaji_card['sub'], ENT_QUOTES, 'UTF-8') ?></span></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
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
                            <div class="dash-stat-value<?= $payment_card['compact'] ? ' dash-stat-value-sm' : '' ?>"<?= $payment_card['compact'] ? ' title="' . htmlspecialchars($payment_card['value'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= htmlspecialchars($payment_card['value'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="dash-stat-sub"><span><?= htmlspecialchars($payment_card['sub'], ENT_QUOTES, 'UTF-8') ?></span></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Total Bayar per Jenis Pembayaran -->
            <div class="dash-section-head" style="margin-top:24px;">
                <div class="dsh-title">
                    <span class="dsh-dot" style="background:#10b981;"></span>
                    Total Bayar per Jenis
                </div>
                <span class="badge badge-primary"><?= number_format(count($pembayaran_summary['jenis_totals'] ?? []), 0, ',', '.') ?> jenis pembayaran</span>
            </div>
            <?php if (empty($pembayaran_summary['jenis_totals'])) : ?>
                <div class="card mb-0">
                    <div class="card-body text-center text-muted py-4">Belum ada jenis pembayaran aktif.</div>
                </div>
            <?php else : ?>
                <div class="bdh-jenis-grid">
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
                        <div class="bdh-jenis tint-<?= htmlspecialchars($payment_jenis_tone, ENT_QUOTES, 'UTF-8') ?>">
                            <span class="bdh-jenis-ico dsi-<?= htmlspecialchars($payment_jenis_tone, ENT_QUOTES, 'UTF-8') ?>">
                                <i class="<?= htmlspecialchars($payment_jenis_icon, ENT_QUOTES, 'UTF-8') ?>"></i>
                            </span>
                            <div class="bdh-jenis-body">
                                <div class="bdh-jenis-nama" title="<?= htmlspecialchars($payment_jenis_tampil, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($payment_jenis_tampil, ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="bdh-jenis-meta">Total dibayar</div>
                            </div>
                            <div class="bdh-jenis-total"><?= bendahara_rupiah($payment_jenis['total_bayar_jenis']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Tabungan Siswa -->
            <div class="dash-section-head" style="margin-top:28px;">
                <div class="dsh-title">
                    <span class="dsh-dot" style="background:#0d9488;"></span>
                    Tabungan Siswa
                </div>
                <a href="<?= htmlspecialchars(app_base_path() . '/bendahara/tabungan_ringkasan.php', ENT_QUOTES, 'UTF-8') ?>" class="small font-weight-bold" style="color:#2563eb;">Lihat detail</a>
            </div>
            <div class="row dash-stat-row stack-mobile">
                <div class="col-6 col-lg-3 col-md-6 col-sm-6">
                    <div class="dash-stat-card tint-teal">
                        <div class="dash-stat-ico dsi-teal"><i class="fas fa-piggy-bank"></i></div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-label">Saldo Kas Tabungan</div>
                            <div class="dash-stat-value dash-stat-value-sm" title="<?= htmlspecialchars(bendahara_rupiah($tabungan_summary['saldo']), ENT_QUOTES, 'UTF-8') ?>"><?= bendahara_rupiah($tabungan_summary['saldo']) ?></div>
                            <div class="dash-stat-sub"><span style="color:#0f766e;">Setoran &minus; penarikan</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3 col-md-6 col-sm-6">
                    <div class="dash-stat-card tint-green">
                        <div class="dash-stat-ico dsi-green"><i class="fas fa-arrow-down"></i></div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-label">Total Setoran</div>
                            <div class="dash-stat-value dash-stat-value-sm" title="<?= htmlspecialchars(bendahara_rupiah($tabungan_summary['total_setor']), ENT_QUOTES, 'UTF-8') ?>"><?= bendahara_rupiah($tabungan_summary['total_setor']) ?></div>
                            <div class="dash-stat-sub"><span style="color:#15803d;"><?= number_format((int) $tabungan_summary['total_transaksi'], 0, ',', '.') ?> transaksi</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3 col-md-6 col-sm-6">
                    <div class="dash-stat-card tint-rose">
                        <div class="dash-stat-ico dsi-rose"><i class="fas fa-arrow-up"></i></div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-label">Total Penarikan</div>
                            <div class="dash-stat-value dash-stat-value-sm" title="<?= htmlspecialchars(bendahara_rupiah($tabungan_summary['total_tarik']), ENT_QUOTES, 'UTF-8') ?>"><?= bendahara_rupiah($tabungan_summary['total_tarik']) ?></div>
                            <div class="dash-stat-sub"><span style="color:#be123c;">Potongan &amp; penarikan lain</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3 col-md-6 col-sm-6">
                    <div class="dash-stat-card tint-blue">
                        <div class="dash-stat-ico dsi-blue"><i class="fas fa-users"></i></div>
                        <div class="dash-stat-body">
                            <div class="dash-stat-label">Siswa Menabung</div>
                            <div class="dash-stat-value dash-stat-value-sm"><?= number_format((int) $tabungan_summary['siswa_menabung'], 0, ',', '.') ?></div>
                            <div class="dash-stat-sub"><span style="color:#2563eb;">Dari <?= number_format((int) $tabungan_siswa_aktif, 0, ',', '.') ?> siswa aktif</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Aktivitas Pembayaran -->
            <?php
            /* ── colour map Bootstrap class → token modern (sama dengan Dashboard Admin) ── */
            $act_color_map = [
                'bg-success'   => ['bg'=>'#16a34a','shadow'=>'rgba(22,163,74,.22)'],
                'bg-warning'   => ['bg'=>'#d97706','shadow'=>'rgba(217,119,6,.22)'],
                'bg-danger'    => ['bg'=>'#dc2626','shadow'=>'rgba(220,38,38,.22)'],
                'bg-info'      => ['bg'=>'#0891b2','shadow'=>'rgba(8,145,178,.22)'],
                'bg-secondary' => ['bg'=>'#64748b','shadow'=>'rgba(100,116,139,.22)'],
                'bg-primary'   => ['bg'=>'#2563eb','shadow'=>'rgba(37,99,235,.22)'],
            ];
            $act_border_map = [
                'bg-success'   => '#bbf7d0',
                'bg-warning'   => '#fde68a',
                'bg-danger'    => '#fecaca',
                'bg-info'      => '#a5f3fc',
                'bg-secondary' => '#e2e8f0',
                'bg-primary'   => '#bfdbfe',
            ];
            $act_bg_map = [
                'bg-success'   => '#f0fdf4',
                'bg-warning'   => '#fffbeb',
                'bg-danger'    => '#fff1f2',
                'bg-info'      => '#ecfeff',
                'bg-secondary' => '#f8fafc',
                'bg-primary'   => '#eff6ff',
            ];
            /* Warna & ikon khusus untuk aktivitas Tabungan */
            $act_jenis_map = [
                'Setoran Tabungan'    => 'bg-success',
                'Penarikan Tabungan'  => 'bg-danger',
            ];
            $act_icon_map = [
                'Setoran Tabungan'   => 'fas fa-plus-circle',
                'Penarikan Tabungan' => 'fas fa-money-bill-transfer',
            ];
            ?>
            <div class="dash-section-head" style="margin-top:28px;">
                <div class="dsh-title">
                    <span class="dsh-dot" style="background:#6366f1;"></span>
                    Aktivitas
                    <span style="display:inline-flex;align-items:center;justify-content:center;background:#2563eb;color:#fff;font-size:.72rem;font-weight:900;border-radius:999px;padding:2px 10px;"><?= number_format($jml_aktivitas, 0, ',', '.') ?></span>
                </div>
                <span class="badge badge-primary">24 jam terakhir</span>
            </div>
            <div class="card mb-0">
                <div class="card-body" style="padding:16px 20px;">
                    <div class="act-feed act-scroll" style="max-height:620px;overflow-y:auto;padding-right:4px;">
                        <?php if ($jml_aktivitas > 0) : ?>
                            <?php foreach ($aktivitas_gabung as $activity_row) :
                                $activity_jenis = (string) ($activity_row['jenis'] ?? '');
                                $actClass  = $act_jenis_map[$activity_jenis] ?? (function_exists('getActivityColor') ? getActivityColor($activity_jenis) : 'bg-primary');
                                $actIcon   = $act_icon_map[$activity_jenis] ?? (function_exists('getActivityIcon') ? getActivityIcon($activity_jenis) : 'fas fa-info-circle');
                                $actToken  = $act_color_map[$actClass]   ?? $act_color_map['bg-primary'];
                                $actBorder = $act_border_map[$actClass] ?? '#bfdbfe';
                                $actCardBg = $act_bg_map[$actClass]      ?? '#eff6ff';
                                $activity_nama = (string) ($activity_row['nama'] ?? 'Sistem');
                                $actTs     = strtotime((string) $activity_row['created_at']);
                                $actTs     = $actTs ? $actTs : time();
                            ?>
                                <div class="act-item" style="
                                    --act-bg: <?= $actToken['bg'] ?>;
                                    --act-shadow: <?= $actToken['shadow'] ?>;
                                    --act-card-bg: <?= $actCardBg ?>;
                                    --act-card-border: <?= $actBorder ?>;
                                ">
                                    <div class="act-dot">
                                        <i class="<?= htmlspecialchars($actIcon, ENT_QUOTES, 'UTF-8') ?>"></i>
                                    </div>
                                    <div class="act-body">
                                        <div class="act-top">
                                            <span class="act-name"><?= htmlspecialchars($activity_nama, ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="act-ago">
                                                <i class="far fa-clock"></i>
                                                <?= timeAgo((string) $activity_row['created_at']) ?>
                                            </span>
                                        </div>
                                        <div class="act-action-pill">
                                            <i class="<?= htmlspecialchars($actIcon, ENT_QUOTES, 'UTF-8') ?>"></i>
                                            <?= htmlspecialchars($activity_jenis, ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                        <div class="act-desc"><?= htmlspecialchars((string) ($activity_row['deskripsi'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="act-footer">
                                            <span><i class="far fa-calendar-alt"></i><?= date('d M Y', $actTs) ?></span>
                                            <span><i class="far fa-clock"></i><?= date('H:i:s', $actTs) ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <div class="text-center py-5">
                                <div style="width:56px;height:56px;border-radius:16px;background:#eff6ff;display:grid;place-items:center;margin:0 auto 12px;">
                                    <i class="fas fa-bolt" style="color:#2563eb;font-size:1.4rem;"></i>
                                </div>
                                <div style="font-weight:700;color:#334155;font-size:.9rem;">Belum ada aktivitas</div>
                                <div style="font-size:.8rem;color:#94a3b8;margin-top:4px;">Aktivitas pembayaran, tabungan, gaji dan honor akan muncul di sini</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
