<?php
/**
 * Ringkasan Tabungan (dashboard modul tabungan).
 * Menu: Tabungan > Ringkasan Tabungan.
 * Data diambil langsung dari tabel tabungan di database SIMAD.
 */

require_once __DIR__ . '/tabungan/config/config.php';

$page_title = 'Ringkasan Tabungan';
$display_name = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Pengguna';

$total = tabungan_total($pdo);

$siswa_aktif = tabungan_siswa_aktif($pdo);

$tahun = (int)date('Y');
$stat_setor = tabungan_statistik_bulanan($pdo, $tahun, 'ST');
$stat_tarik = tabungan_statistik_bulanan($pdo, $tahun, 'TR');

// Data grafik: bulan berjalan saja
$bulan_sekarang = (int)date('n');
$labels = [];
$data_setor = [];
$data_tarik = [];
for ($b = 1; $b <= $bulan_sekarang; $b++) {
    $labels[] = tabungan_nama_bulan($b);
    $data_setor[] = $stat_setor[$b]['jumlah'];
    $data_tarik[] = $stat_tarik[$b]['jumlah'];
}

include __DIR__ . '/../templates/header.php';
include_once __DIR__ . '/../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Ringkasan Tabungan</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item active">Ringkasan Tabungan</div>
            </div>
        </div>

        <div class="section-body">

            <div class="tb-hero">
                <div class="tb-hero-ava"><i class="fas fa-piggy-bank"></i></div>
                <div style="min-width:0;">
                    <div class="tb-hero-nama">Selamat Datang, <?= htmlspecialchars((string)$display_name) ?>!</div>
                    <div class="tb-hero-meta">Ringkasan tabungan siswa tahun <?= $tahun ?> &bull; <?= htmlspecialchars(tabungan_tahun_ajaran_aktif()) ?></div>
                </div>
                <div class="tb-hero-saldo">
                    <div class="lbl">Saldo Kas Tabungan</div>
                    <div class="val"><?= tabungan_rupiah($total['saldo']) ?></div>
                </div>
            </div>

            <div class="tb-stat-grid">
                <div class="tb-stat">
                    <div class="lbl">Siswa Aktif</div>
                    <div class="val teal"><?= number_format($siswa_aktif, 0, ',', '.') ?></div>
                    <div class="sub">siswa terdaftar</div>
                </div>
                <div class="tb-stat">
                    <div class="lbl">Total Setoran</div>
                    <div class="val green"><?= tabungan_rupiah($total['total_setor']) ?></div>
                    <div class="sub"><?= number_format($total['total_transaksi'], 0, ',', '.') ?> transaksi</div>
                </div>
                <div class="tb-stat">
                    <div class="lbl">Total Penarikan</div>
                    <div class="val rose"><?= tabungan_rupiah($total['total_tarik']) ?></div>
                    <div class="sub"><?= number_format($total['siswa_menabung'], 0, ',', '.') ?> siswa menabung</div>
                </div>
                <div class="tb-stat">
                    <div class="lbl">Saldo Akhir</div>
                    <div class="val"><?= tabungan_rupiah($total['saldo']) ?></div>
                    <div class="sub">setoran &minus; penarikan</div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-chart-bar mr-2" style="color:#0d9488;"></i>Statistik Tabungan <?= $tahun ?></h5>
                </div>
                <div class="card-body">
                    <div style="position:relative;height:260px;">
                        <canvas id="tabunganChart"></canvas>
                    </div>
                    <div class="text-muted small mt-2" style="font-weight:600;">
                        Perbandingan jumlah transaksi setoran dan penarikan per bulan.
                    </div>
                </div>
            </div>

            <div class="card mb-3 d-md-none">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-bolt mr-2" style="color:#d97706;"></i>Aksi Cepat</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-6 col-md-3 mb-2">
                            <a href="<?= htmlspecialchars(app_base_path() . '/bendahara/tabungan_setor.php') ?>" class="btn btn-success btn-block">
                                <i class="fas fa-plus-circle"></i> Setoran
                            </a>
                        </div>
                        <div class="col-6 col-md-3 mb-2">
                            <a href="<?= htmlspecialchars(app_base_path() . '/bendahara/tabungan_tarik.php') ?>" class="btn btn-danger btn-block">
                                <i class="fas fa-money-bill-transfer"></i> Penarikan
                            </a>
                        </div>
                        <div class="col-6 col-md-3 mb-2">
                            <a href="<?= htmlspecialchars(app_base_path() . '/bendahara/tabungan_data.php') ?>" class="btn btn-info btn-block">
                                <i class="fas fa-wallet"></i> Saldo Siswa
                            </a>
                        </div>
                        <div class="col-6 col-md-3 mb-2">
                            <a href="<?= htmlspecialchars(app_base_path() . '/bendahara/tabungan_laporan.php') ?>" class="btn btn-primary btn-block">
                                <i class="fas fa-file-alt"></i> Laporan
                            </a>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>
</div>

<?php /* Chart.js dimuat di templates/footer.php, jadi skrip grafik dijalankan setelah seluruh halaman selesai dimuat. */ ?>
<script>
(function () {
    function buatGrafik() {
        var canvas = document.getElementById('tabunganChart');
        if (!canvas || typeof Chart === 'undefined') return;

        new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($labels) ?>,
                datasets: [
                    {
                        label: 'Setoran',
                        data: <?= json_encode($data_setor) ?>,
                        backgroundColor: 'rgba(13, 148, 136, .85)',
                        borderRadius: 6
                    },
                    {
                        label: 'Penarikan',
                        data: <?= json_encode($data_tarik) ?>,
                        backgroundColor: 'rgba(244, 63, 94, .85)',
                        borderRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { font: { weight: '700' } } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    if (document.readyState === 'complete') {
        buatGrafik();
    } else {
        window.addEventListener('load', buatGrafik);
    }
})();
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
