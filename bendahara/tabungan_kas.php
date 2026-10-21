<?php
/**
 * Info Kas Tabungan.
 * Menu: Tabungan > Info Kas.
 */

require_once __DIR__ . '/tabungan/config/config.php';

$page_title = 'Info Kas Tabungan';

$total = tabungan_total($pdo);
$setor = (int)$total['total_setor'];
$tarik = (int)$total['total_tarik'];
$saldo = (int)$total['saldo'];

$pct_tarik = $setor > 0 ? (int)round($tarik / $setor * 100) : 0;
$pct_saldo = $setor > 0 ? (int)round($saldo / $setor * 100) : 0;

$warna_tarik = $pct_tarik < 50 ? 'emerald' : ($pct_tarik < 80 ? 'amber' : 'rose');
$warna_saldo = $pct_saldo > 50 ? 'emerald' : ($pct_saldo > 20 ? 'amber' : 'rose');

$terbaru = tabungan_transaksi($pdo, ['limit' => 20]);

include __DIR__ . '/../templates/header.php';
include_once __DIR__ . '/../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Info Kas Tabungan</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="tabungan_ringkasan.php">Ringkasan Tabungan</a></div>
                <div class="breadcrumb-item active">Info Kas</div>
            </div>
        </div>

        <div class="section-body">

            <div class="row">
                <div class="col-md-4 mb-3">
                    <div class="tb-kas">
                        <div class="tb-kas-head">
                            <div class="tb-kas-ico in"><i class="fas fa-arrow-down"></i></div>
                            <div>
                                <div class="tb-kas-title">Total Setoran</div>
                                <div class="tb-kas-val"><?= tabungan_rupiah($setor) ?></div>
                            </div>
                        </div>
                        <div class="tb-kas-bar"><span class="emerald" style="width:100%"></span></div>
                        <div class="tb-kas-meta">Basis total setoran &bull; 100%</div>
                    </div>
                </div>

                <div class="col-md-4 mb-3">
                    <div class="tb-kas">
                        <div class="tb-kas-head">
                            <div class="tb-kas-ico out"><i class="fas fa-arrow-up"></i></div>
                            <div>
                                <div class="tb-kas-title">Total Penarikan</div>
                                <div class="tb-kas-val"><?= tabungan_rupiah($tarik) ?></div>
                            </div>
                        </div>
                        <div class="tb-kas-bar"><span class="<?= $warna_tarik ?>" style="width:<?= min(100, $pct_tarik) ?>%"></span></div>
                        <div class="tb-kas-meta">Dari total setoran &bull; <?= $pct_tarik ?>%</div>
                    </div>
                </div>

                <div class="col-md-4 mb-3">
                    <div class="tb-kas">
                        <div class="tb-kas-head">
                            <div class="tb-kas-ico saldo"><i class="fas fa-wallet"></i></div>
                            <div>
                                <div class="tb-kas-title">Saldo Akhir Kas</div>
                                <div class="tb-kas-val"><?= tabungan_rupiah($saldo) ?></div>
                            </div>
                        </div>
                        <div class="tb-kas-bar"><span class="<?= $warna_saldo ?>" style="width:<?= min(100, max(0, $pct_saldo)) ?>%"></span></div>
                        <div class="tb-kas-meta">Sisa dari total setoran &bull; <?= $pct_saldo ?>%</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-clock mr-2" style="color:#0d9488;"></i>Transaksi Terbaru</h5>
                    <div class="card-header-action">
                        <a href="<?= htmlspecialchars(app_base_path() . '/bendahara/tabungan_setor.php') ?>" class="btn btn-success btn-sm">
                            <i class="fas fa-plus"></i> Setoran
                        </a>
                        <a href="<?= htmlspecialchars(app_base_path() . '/bendahara/tabungan_tarik.php') ?>" class="btn btn-danger btn-sm">
                            <i class="fas fa-money-bill-transfer"></i> Penarikan
                        </a>
                        <a href="<?= htmlspecialchars(app_base_path() . '/bendahara/tabungan_cetak.php?jenis=kas') ?>" target="_blank" class="btn btn-primary btn-sm">
                            <i class="fas fa-print"></i> Cetak
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($terbaru)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-inbox fa-2x mb-2 d-block" style="color:#cbd5e1;"></i>
                            Belum ada transaksi tabungan.
                        </div>
                    <?php else: ?>
                        <?php foreach ($terbaru as $t):
                            $masuk = (string)$t['jenis'] === 'ST';
                            $nominal = $masuk ? (int)$t['setor'] : (int)$t['tarik'];
                            $ket = $masuk ? 'Setoran Tunai' : (trim((string)($t['keterangan_tarik'] ?? '')) !== '' ? (string)$t['keterangan_tarik'] : 'Penarikan Tunai');
                        ?>
                            <div class="tb-trx">
                                <div class="tb-trx-l">
                                    <div class="tb-trx-ico <?= $masuk ? 'in' : 'out' ?>">
                                        <i class="fas fa-arrow-<?= $masuk ? 'down' : 'up' ?>"></i>
                                    </div>
                                    <div style="min-width:0;">
                                        <div class="tb-trx-t text-truncate"><?= htmlspecialchars($ket) ?> &mdash; <?= htmlspecialchars((string)($t['nama_siswa'] ?? '-')) ?></div>
                                        <div class="tb-trx-s">
                                            <?= htmlspecialchars(tabungan_tanggal_indo((string)$t['tgl'])) ?>
                                            <span>&bull;</span>
                                            <span><?= htmlspecialchars(tabungan_aktor_label($t['petugas'] ?? '-')) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="tb-trx-amt <?= $masuk ? 'in' : 'out' ?>">
                                    <?= $masuk ? '+' : '-' ?> <?= tabungan_rupiah($nominal) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <div class="text-muted small mt-2" style="font-weight:600;">
                        Terakhir diperbarui: <?= htmlspecialchars(tabungan_tanggal_indo(date('Y-m-d H:i:s'), true)) ?>
                    </div>
                </div>
            </div>

        </div>
    </section>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
