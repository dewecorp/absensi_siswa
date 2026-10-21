<?php
/**
 * Laporan Tabungan (periode).
 * Menu: Tabungan > Laporan.
 */

require_once __DIR__ . '/tabungan/config/config.php';

$page_title = 'Laporan Tabungan';

$dari = trim((string)($_GET['dari'] ?? date('Y-m-01')));
$sampai = trim((string)($_GET['sampai'] ?? date('Y-m-d')));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari)) {
    $dari = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) {
    $sampai = date('Y-m-d');
}
if ($dari > $sampai) {
    $sampai = $dari;
}

$trx = tabungan_transaksi($pdo, ['dari' => $dari, 'sampai' => $sampai]);
$total_setor = 0;
$total_tarik = 0;
foreach ($trx as $t) {
    $total_setor += (int)$t['setor'];
    $total_tarik += (int)$t['tarik'];
}
$saldo = $total_setor - $total_tarik;

include __DIR__ . '/../templates/header.php';
include_once __DIR__ . '/../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Laporan Tabungan</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="tabungan_ringkasan.php">Ringkasan Tabungan</a></div>
                <div class="breadcrumb-item active">Laporan</div>
            </div>
        </div>

        <div class="section-body">

            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-calendar-alt mr-2" style="color:#0d9488;"></i>Atur Periode</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row align-items-end">
                        <div class="col-md-4 mb-2">
                            <label for="dari">Tanggal Awal</label>
                            <input type="date" class="form-control" id="dari" name="dari" value="<?= htmlspecialchars($dari) ?>">
                        </div>
                        <div class="col-md-4 mb-2">
                            <label for="sampai">Tanggal Akhir</label>
                            <input type="date" class="form-control" id="sampai" name="sampai" value="<?= htmlspecialchars($sampai) ?>">
                        </div>
                        <div class="col-md-4 mb-2">
                            <div class="d-flex" style="gap:8px;">
                                <button type="submit" class="btn btn-primary flex-fill"><i class="fas fa-search"></i> Tampilkan</button>
                                <a href="<?= htmlspecialchars(app_base_path() . '/bendahara/tabungan_cetak.php?jenis=laporan&dari=' . urlencode($dari) . '&sampai=' . urlencode($sampai)) ?>" target="_blank" class="btn btn-info"><i class="fas fa-print"></i> Cetak</a>
                                <a href="<?= htmlspecialchars(app_base_path() . '/bendahara/tabungan_cetak.php?jenis=laporan&dari=' . urlencode($dari) . '&sampai=' . urlencode($sampai) . '&export=csv') ?>" class="btn btn-success"><i class="fas fa-file-excel"></i> CSV</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="tb-stat-grid">
                <div class="tb-stat">
                    <div class="lbl">Total Setoran</div>
                    <div class="val green"><?= tabungan_rupiah($total_setor) ?></div>
                    <div class="sub">periode terpilih</div>
                </div>
                <div class="tb-stat">
                    <div class="lbl">Total Penarikan</div>
                    <div class="val rose"><?= tabungan_rupiah($total_tarik) ?></div>
                    <div class="sub">periode terpilih</div>
                </div>
                <div class="tb-stat">
                    <div class="lbl">Saldo Periode</div>
                    <div class="val teal"><?= tabungan_rupiah($saldo) ?></div>
                    <div class="sub">setoran &minus; penarikan</div>
                </div>
                <div class="tb-stat">
                    <div class="lbl">Jumlah Transaksi</div>
                    <div class="val"><?= count($trx) ?></div>
                    <div class="sub"><?= htmlspecialchars(tabungan_tanggal_indo($dari)) ?> s/d <?= htmlspecialchars(tabungan_tanggal_indo($sampai)) ?></div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-file-alt mr-2" style="color:#0d9488;"></i>Rincian Transaksi</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover" id="tableLaporan" style="width:100%;">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Tanggal</th>
                                    <th>NISN</th>
                                    <th>Nama Siswa</th>
                                    <th>Kelas</th>
                                    <th>Pemasukan</th>
                                    <th>Pengeluaran</th>
                                    <th>Admin/Bendahara</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($trx)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">Tidak ada transaksi pada periode ini.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($trx as $i => $t): ?>
                                        <tr>
                                            <td><?= $i + 1 ?></td>
                                            <td><?= htmlspecialchars(tabungan_tanggal_indo((string)$t['tgl'])) ?></td>
                                            <td><?= htmlspecialchars((string)$t['nisn']) ?></td>
                                            <td><?= htmlspecialchars((string)($t['nama_siswa'] ?? '-')) ?></td>
                                            <td><?= htmlspecialchars((string)($t['nama_kelas'] ?? '-')) ?></td>
                                            <td class="text-success"><?= (int)$t['setor'] > 0 ? tabungan_rupiah($t['setor']) : '-' ?></td>
                                            <td class="text-danger"><?= (int)$t['tarik'] > 0 ? tabungan_rupiah($t['tarik']) : '-' ?></td>
                                            <td><?= htmlspecialchars(tabungan_aktor_label($t['petugas'] ?? '-')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <?php if (!empty($trx)): ?>
                                <tfoot>
                                    <tr class="table-light">
                                        <th colspan="5" class="text-end">Total</th>
                                        <th class="text-success"><?= tabungan_rupiah($total_setor) ?></th>
                                        <th class="text-danger"><?= tabungan_rupiah($total_tarik) ?></th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </section>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
