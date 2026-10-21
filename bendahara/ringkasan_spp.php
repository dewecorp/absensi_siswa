<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/pembayaran_db.php';
require_once __DIR__ . '/_summary.php';

if (!isAuthorized(['bendahara', 'admin'])) {
    redirect('../login.php');
}

$page_title = 'Ringkasan SPP';
$school_profile = getSchoolProfile($pdo);
$display_name = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Bendahara';

$spp = pembayaran_db();
$db_ok = $spp instanceof PDO;
$db_error = $db_ok ? '' : pembayaran_db_error();

$tahun_aktif = '';
$tahun_options = [];
$total_siswa = 0;
$total_jenis = 0;
$total_bayar = 0;
$total_trx = 0;
$per_jenis = [];
$per_kelas = [];
$terbaru = [];

$filter_ta = trim((string)($_GET['ta'] ?? ''));

if ($db_ok) {
    try {
        $tahun_aktif = trim((string)$spp->query("SELECT tahun_ajaran FROM pengaturan WHERE id_pengaturan = 1 LIMIT 1")->fetchColumn());
        foreach ($spp->query("SELECT DISTINCT tahun_ajaran FROM pembayaran WHERE tahun_ajaran IS NOT NULL AND tahun_ajaran <> '' ORDER BY tahun_ajaran DESC") as $r) {
            $tahun_options[] = (string)$r['tahun_ajaran'];
        }
        if ($tahun_aktif !== '' && !in_array($tahun_aktif, $tahun_options, true)) {
            array_unshift($tahun_options, $tahun_aktif);
        }
        if ($filter_ta === '' || !preg_match('#^\d{4}/\d{4}$#', $filter_ta)) {
            $filter_ta = $tahun_aktif;
        }
        if ($filter_ta === '') $filter_ta = $tahun_options[0] ?? '';

        $total_siswa = (int)$spp->query("SELECT COUNT(*) FROM siswa s JOIN kelas k ON s.id_kelas = k.id_kelas WHERE LOWER(k.nama_kelas) NOT LIKE '%alumni%'")->fetchColumn();
        $total_jenis = (int)$spp->query("SELECT COUNT(*) FROM jenis_bayar WHERE status = 'Aktif'")->fetchColumn();

        $stmt = $spp->prepare("SELECT COALESCE(SUM(jumlah_bayar),0), COUNT(*) FROM pembayaran WHERE tahun_ajaran = ?");
        $stmt->execute([$filter_ta]);
        [$total_bayar, $total_trx] = array_map('intval', $stmt->fetch(PDO::FETCH_NUM));

        $stmt = $spp->prepare("
            SELECT jb.id_jenis_bayar, jb.nama_pembayaran, jb.tipe_bayar,
                   COALESCE(SUM(p.jumlah_bayar),0) AS total
            FROM jenis_bayar jb
            LEFT JOIN pembayaran p ON p.id_jenis_bayar = jb.id_jenis_bayar AND p.tahun_ajaran = ?
            WHERE jb.status = 'Aktif'
            GROUP BY jb.id_jenis_bayar, jb.nama_pembayaran, jb.tipe_bayar
            ORDER BY total DESC, jb.nama_pembayaran ASC
        ");
        $stmt->execute([$filter_ta]);
        $per_jenis = $stmt->fetchAll();

        $stmt = $spp->prepare("
            SELECT k.nama_kelas, COUNT(DISTINCT s.nisn) AS jumlah_siswa,
                   COALESCE(SUM(p.jumlah_bayar),0) AS total
            FROM kelas k
            LEFT JOIN siswa s ON s.id_kelas = k.id_kelas
            LEFT JOIN pembayaran p ON p.nisn = s.nisn AND p.tahun_ajaran = ?
            WHERE LOWER(k.nama_kelas) NOT LIKE '%alumni%'
            GROUP BY k.id_kelas, k.nama_kelas
            ORDER BY k.nama_kelas ASC
        ");
        $stmt->execute([$filter_ta]);
        $per_kelas = $stmt->fetchAll();

        $stmt = $spp->prepare("
            SELECT p.no_transaksi, p.tgl_bayar, p.jumlah_bayar, p.tahun_ajaran,
                   s.nama, k.nama_kelas, jb.nama_pembayaran
            FROM pembayaran p
            LEFT JOIN siswa s ON s.nisn = p.nisn
            LEFT JOIN kelas k ON s.id_kelas = k.id_kelas
            LEFT JOIN jenis_bayar jb ON jb.id_jenis_bayar = p.id_jenis_bayar
            WHERE p.tahun_ajaran = ?
            ORDER BY p.tgl_bayar DESC, p.id_pembayaran DESC
            LIMIT 12
        ");
        $stmt->execute([$filter_ta]);
        $terbaru = $stmt->fetchAll();
    } catch (Throwable $e) {
        $db_ok = false;
        $db_error = 'Gagal membaca data Pembayaran Siswa: ' . $e->getMessage();
    }
}

include '../templates/header.php';
include_once '../templates/sidebar.php';
?>
<style>
.sby-card {
    background: #fff; border: 1.5px solid rgba(15, 23, 42, .07);
    border-radius: 14px; padding: 13px 14px; height: 100%;
}
.sby-card .lbl { font-size: .64rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; }
.sby-card .val { font-size: 1.05rem; font-weight: 900; color: #0f172a; line-height: 1.3; }
.sby-card .sub { font-size: .68rem; color: #94a3b8; font-weight: 600; }
.sby-ico { width: 34px; height: 34px; border-radius: 10px; display: grid; place-items: center; font-size: .82rem; margin-bottom: 8px; }
.sby-section { font-size: .78rem; font-weight: 900; color: #0f172a; display: flex; align-items: center; gap: 8px; margin: 0 0 10px; }
.sby-section .dot { width: 8px; height: 8px; border-radius: 50%; }
.sby-list { background: #fff; border: 1.5px solid rgba(15, 23, 42, .07); border-radius: 14px; overflow: hidden; }
.sby-row { display: flex; align-items: center; gap: 10px; padding: 10px 13px; border-bottom: 1px dashed rgba(15, 23, 42, .08); }
.sby-row:last-child { border-bottom: 0; }
.sby-row .nm { font-size: .8rem; font-weight: 800; color: #0f172a; line-height: 1.25; }
.sby-row .mt { font-size: .67rem; color: #94a3b8; font-weight: 600; }
.sby-row .amt { margin-left: auto; font-size: .8rem; font-weight: 900; color: #15803d; white-space: nowrap; }
.sby-bar { height: 7px; border-radius: 999px; background: #f1f5f9; overflow: hidden; margin-top: 7px; }
.sby-bar > span { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #2563eb, #38bdf8); }
.sby-select { height: 40px; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 0 12px; font-size: .82rem; font-weight: 700; color: #334155; background: #fff; }
.sby-tag { font-size: .6rem; font-weight: 900; padding: 2px 8px; border-radius: 999px; background: #eff6ff; color: #2563eb; }
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Ringkasan SPP</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Pembayaran Siswa</div>
                <div class="breadcrumb-item active">Ringkasan SPP</div>
            </div>
        </div>

        <div class="section-body">
            <?php if (!$db_ok): ?>
                <div class="alert alert-warning" style="border-radius:12px;">
                    <strong>Data Pembayaran Siswa belum terhubung.</strong>
                    <div class="small"><?= htmlspecialchars($db_error) ?></div>
                </div>
            <?php else: ?>
                <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap" style="gap:8px;">
                    <div class="small text-muted font-weight-bold">
                        Sumber data: database SIMAD (langsung) • Tahun ajaran aktif
                        <span class="sby-tag"><?= htmlspecialchars($tahun_aktif !== '' ? $tahun_aktif : '-') ?></span>
                    </div>
                    <form method="GET">
                        <select name="ta" class="sby-select" onchange="this.form.submit()">
                            <?php foreach ($tahun_options as $ta): ?>
                                <option value="<?= htmlspecialchars($ta) ?>" <?= $ta === $filter_ta ? 'selected' : '' ?>>TA <?= htmlspecialchars($ta) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>

                <div class="row">
                    <div class="col-6 col-lg-3 mb-3">
                        <div class="sby-card">
                            <div class="sby-ico" style="background:#eff6ff;color:#2563eb;"><i class="fas fa-users"></i></div>
                            <div class="lbl">Siswa Aktif</div>
                            <div class="val"><?= number_format($total_siswa, 0, ',', '.') ?> siswa</div>
                            <div class="sub">di luar kelas alumni</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3 mb-3">
                        <div class="sby-card">
                            <div class="sby-ico" style="background:#ecfeff;color:#0d9488;"><i class="fas fa-list-check"></i></div>
                            <div class="lbl">Jenis Bayar Aktif</div>
                            <div class="val"><?= number_format($total_jenis, 0, ',', '.') ?> jenis</div>
                            <div class="sub">tagihan yang berjalan</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3 mb-3">
                        <div class="sby-card">
                            <div class="sby-ico" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-money-bill-wave"></i></div>
                            <div class="lbl">Pembayaran TA</div>
                            <div class="val"><?= bendahara_rupiah($total_bayar) ?></div>
                            <div class="sub"><?= htmlspecialchars($filter_ta) ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3 mb-3">
                        <div class="sby-card">
                            <div class="sby-ico" style="background:#fff7ed;color:#c2410c;"><i class="fas fa-receipt"></i></div>
                            <div class="lbl">Transaksi</div>
                            <div class="val"><?= number_format($total_trx, 0, ',', '.') ?> transaksi</div>
                            <div class="sub">tercatat di periode ini</div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12 col-xl-5 mb-3">
                        <div class="sby-section"><span class="dot" style="background:#2563eb;"></span>Pembayaran per Jenis</div>
                        <div class="sby-list">
                            <?php if (empty($per_jenis)): ?>
                                <div class="sby-row"><span class="mt">Belum ada jenis bayar aktif.</span></div>
                            <?php else: ?>
                                <?php
                                $max_jenis = 0;
                                foreach ($per_jenis as $pj) $max_jenis = max($max_jenis, (int)$pj['total']);
                                foreach ($per_jenis as $pj):
                                    $val = (int)$pj['total'];
                                    $pct = $max_jenis > 0 ? round(($val / $max_jenis) * 100) : 0;
                                ?>
                                    <div class="sby-row" style="display:block;">
                                        <div class="d-flex align-items-center justify-content-between" style="gap:8px;">
                                            <span class="nm"><?= htmlspecialchars((string)$pj['nama_pembayaran']) ?></span>
                                            <span class="amt" style="color:#0f172a;"><?= bendahara_rupiah($val) ?></span>
                                        </div>
                                        <div class="mt"><?= htmlspecialchars((string)$pj['tipe_bayar']) ?></div>
                                        <div class="sby-bar"><span style="width:<?= $pct ?>%"></span></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-12 col-xl-7 mb-3">
                        <div class="sby-section"><span class="dot" style="background:#16a34a;"></span>Pembayaran per Kelas</div>
                        <div class="sby-list">
                            <?php foreach ($per_kelas as $pk):
                                $val = (int)$pk['total'];
                                $max_kelas = 0;
                                foreach ($per_kelas as $x) $max_kelas = max($max_kelas, (int)$x['total']);
                                $pct = $max_kelas > 0 ? round(($val / $max_kelas) * 100) : 0;
                            ?>
                                <div class="sby-row" style="display:block;">
                                    <div class="d-flex align-items-center justify-content-between" style="gap:8px;">
                                        <span class="nm">Kelas <?= htmlspecialchars((string)$pk['nama_kelas']) ?>
                                            <span class="mt">• <?= (int)$pk['jumlah_siswa'] ?> siswa</span>
                                        </span>
                                        <span class="amt" style="color:#0f172a;"><?= bendahara_rupiah($val) ?></span>
                                    </div>
                                    <div class="sby-bar"><span style="width:<?= $pct ?>%;background:linear-gradient(90deg,#16a34a,#22c55e);"></span></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="sby-section"><span class="dot" style="background:#c2410c;"></span>Transaksi Terbaru</div>
                <div class="sby-list">
                    <?php if (empty($terbaru)): ?>
                        <div class="sby-row"><span class="mt">Belum ada transaksi pada TA ini.</span></div>
                    <?php else: ?>
                        <?php foreach ($terbaru as $t): ?>
                            <div class="sby-row">
                                <span class="sby-ico" style="margin:0;background:#f0fdf4;color:#16a34a;width:32px;height:32px;">
                                    <i class="fas fa-arrow-down"></i>
                                </span>
                                <span style="min-width:0;">
                                    <span class="nm d-block text-truncate"><?= htmlspecialchars((string)($t['nama'] ?? $t['no_transaksi'])) ?></span>
                                    <span class="mt">
                                        <?= htmlspecialchars((string)($t['nama_pembayaran'] ?? '-')) ?>
                                        • <?= htmlspecialchars((string)($t['nama_kelas'] ?? '-')) ?>
                                        • <?= !empty($t['tgl_bayar']) ? date('d/m/Y', strtotime((string)$t['tgl_bayar'])) : '-' ?>
                                    </span>
                                </span>
                                <span class="amt">+ <?= bendahara_rupiah((int)$t['jumlah_bayar']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
