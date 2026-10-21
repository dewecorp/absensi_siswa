<?php
/**
 * Data Tabungan Siswa (daftar saldo + rincian transaksi siswa).
 * Menu: Tabungan > Data Tabungan.
 */

require_once __DIR__ . '/tabungan/config/config.php';

$page_title = 'Data Tabungan Siswa';

$q = trim((string)($_GET['q'] ?? ''));
$id_kelas = trim((string)($_GET['id_kelas'] ?? ''));
$nisn = trim((string)($_GET['nisn'] ?? ''));

$kelas_list = tabungan_kelas($pdo);

$detail = null;
$trx_siswa = [];
$stat_siswa = ['saldo' => 0, 'setor' => 0, 'tarik' => 0];

if ($nisn !== '') {
    $detail = tabungan_siswa($pdo, $nisn);
    if ($detail) {
        $trx_siswa = tabungan_transaksi($pdo, ['nisn' => $nisn]);
        foreach ($trx_siswa as $t) {
            $stat_siswa['setor'] += (int)$t['setor'];
            $stat_siswa['tarik'] += (int)$t['tarik'];
        }
        $stat_siswa['saldo'] = $stat_siswa['setor'] - $stat_siswa['tarik'];
    }
}

$daftar = tabungan_daftar_siswa($pdo, [
    'q' => $q,
    'id_kelas' => $id_kelas,
    'punya_transaksi' => true,
]);

include __DIR__ . '/../templates/header.php';
include_once __DIR__ . '/../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Data Tabungan Siswa</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="tabungan_ringkasan.php">Ringkasan Tabungan</a></div>
                <div class="breadcrumb-item active">Data Tabungan</div>
            </div>
        </div>

        <div class="section-body">

            <?php if ($nisn !== ''): ?>
                <!-- ================= RINCIAN SISWA ================= -->
                <div class="d-flex justify-content-between align-items-center mb-3" style="gap:8px;flex-wrap:wrap;">
                    <a href="tabungan_data.php" class="btn btn-light" style="font-weight:800;">
                        <i class="fas fa-arrow-left mr-1"></i>Daftar Siswa
                    </a>
                    <div class="d-flex" style="gap:8px;">
                        <a href="<?= htmlspecialchars(app_base_path() . '/bendahara/tabungan_setor.php') ?>" class="btn btn-success" style="font-weight:800;">
                            <i class="fas fa-plus mr-1"></i>Setoran
                        </a>
                        <a href="<?= htmlspecialchars(app_base_path() . '/bendahara/tabungan_tarik.php') ?>" class="btn btn-danger" style="font-weight:800;">
                            <i class="fas fa-money-bill-transfer mr-1"></i>Penarikan
                        </a>
                        <a href="<?= htmlspecialchars(app_base_path() . '/bendahara/tabungan_cetak.php?jenis=siswa&nisn=' . urlencode($nisn)) ?>" target="_blank" class="btn btn-primary" style="font-weight:800;">
                            <i class="fas fa-print mr-1"></i>Cetak
                        </a>
                    </div>
                </div>

                <?php if (!$detail): ?>
                    <div class="alert alert-warning">Data siswa dengan NISN <?= htmlspecialchars($nisn) ?> tidak ditemukan.</div>
                <?php else: ?>
                    <div class="tb-hero">
                        <div class="tb-hero-ava"><?= htmlspecialchars(mb_strtoupper(mb_substr((string)$detail['nama_siswa'], 0, 1))) ?></div>
                        <div style="min-width:0;">
                            <div class="tb-hero-nama"><?= htmlspecialchars((string)$detail['nama_siswa']) ?></div>
                            <div class="tb-hero-meta">
                                <?= htmlspecialchars((string)($detail['nama_kelas'] ?? '-')) ?>
                                &bull; NISN <?= htmlspecialchars((string)$detail['nisn']) ?>
                            </div>
                        </div>
                        <div class="tb-hero-saldo">
                            <div class="lbl">Saldo Tabungan</div>
                            <div class="val"><?= tabungan_rupiah($stat_siswa['saldo']) ?></div>
                        </div>
                    </div>

                    <div class="tb-stat-grid">
                        <div class="tb-stat">
                            <div class="lbl">Saldo</div>
                            <div class="val teal"><?= tabungan_rupiah($stat_siswa['saldo']) ?></div>
                            <div class="sub">setoran &minus; penarikan</div>
                        </div>
                        <div class="tb-stat">
                            <div class="lbl">Total Setoran</div>
                            <div class="val green"><?= tabungan_rupiah($stat_siswa['setor']) ?></div>
                            <div class="sub"><?= count(array_filter($trx_siswa, static fn($t) => (string)$t['jenis'] === 'ST')) ?> kali setoran</div>
                        </div>
                        <div class="tb-stat">
                            <div class="lbl">Total Tarikan</div>
                            <div class="val rose"><?= tabungan_rupiah($stat_siswa['tarik']) ?></div>
                            <div class="sub"><?= count(array_filter($trx_siswa, static fn($t) => (string)$t['jenis'] === 'TR')) ?> kali penarikan</div>
                        </div>
                        <div class="tb-stat">
                            <div class="lbl">Status Siswa</div>
                            <div class="val"><?= htmlspecialchars((string)($detail['status'] ?? '-')) ?></div>
                            <div class="sub">data induk SIMAD</div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-list mr-2" style="color:#0d9488;"></i>Riwayat Transaksi</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($trx_siswa)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="fas fa-wallet fa-2x mb-2 d-block" style="color:#cbd5e1;"></i>
                                    Belum ada riwayat transaksi tabungan.
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover" id="tableTrxSiswa" style="width:100%;">
                                        <thead>
                                            <tr>
                                                <th>No</th>
                                                <th>Tanggal</th>
                                                <th>Jenis</th>
                                                <th>Setoran</th>
                                                <th>Penarikan</th>
                                                <th>Keterangan</th>
                                                <th>Admin/Bendahara</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($trx_siswa as $i => $t):
                                                $masuk = (string)$t['jenis'] === 'ST';
                                            ?>
                                                <tr>
                                                    <td><?= $i + 1 ?></td>
                                                    <td><?= htmlspecialchars(tabungan_tanggal_indo((string)$t['tgl'])) ?></td>
                                                    <td>
                                                        <?php if ($masuk): ?>
                                                            <span class="badge badge-success">Setoran</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-danger">Penarikan</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="font-weight-bold text-success"><?= $masuk ? tabungan_rupiah($t['setor']) : '-' ?></td>
                                                    <td class="font-weight-bold text-danger"><?= $masuk ? '-' : tabungan_rupiah($t['tarik']) ?></td>
                                                    <td>
                                                        <?php
                                                        $ket = trim((string)($t['jenis_bayar'] ?? ''));
                                                        if ($ket === '') {
                                                            $ket = trim((string)($t['keterangan_tarik'] ?? ''));
                                                        }
                                                        echo htmlspecialchars($ket !== '' ? $ket : '-');
                                                        ?>
                                                    </td>
                                                    <td><?= htmlspecialchars(tabungan_aktor_label($t['petugas'] ?? '-')) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- ================= DAFTAR SISWA ================= -->
                <form method="GET" class="mb-3">
                    <div class="row">
                        <div class="col-md-7 mb-2">
                            <div class="tb-search">
                                <i class="fas fa-search"></i>
                                <input type="text" name="q" id="cariSiswa" value="<?= htmlspecialchars($q) ?>" placeholder="Cari nama atau NISN siswa...">
                            </div>
                        </div>
                        <div class="col-md-3 mb-2">
                            <select class="form-control" name="id_kelas" onchange="this.form.submit()">
                                <option value="">Semua Kelas</option>
                                <?php foreach ($kelas_list as $k): ?>
                                    <option value="<?= (int)$k['id_kelas'] ?>" <?= (string)$k['id_kelas'] === $id_kelas ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string)$k['nama_kelas']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-search"></i> Cari</button>
                        </div>
                    </div>
                </form>

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-users mr-2" style="color:#0d9488;"></i>Daftar Saldo Siswa</h5>
                        <div class="card-header-action">
                            <span class="badge badge-info"><?= count($daftar) ?> siswa</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($daftar)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-user-slash fa-2x mb-2 d-block" style="color:#cbd5e1;"></i>
                                Siswa tidak ditemukan.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover" id="tableSaldo" style="width:100%;">
                                    <thead>
                                        <tr>
                                            <th>No</th>
                                            <th>NISN</th>
                                            <th>Nama Siswa</th>
                                            <th>Kelas</th>
                                            <th>Total Setoran</th>
                                            <th>Total Tarikan</th>
                                            <th>Saldo</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($daftar as $i => $s): ?>
                                            <tr>
                                                <td><?= $i + 1 ?></td>
                                                <td><?= htmlspecialchars((string)$s['nisn']) ?></td>
                                                <td><?= htmlspecialchars((string)$s['nama_siswa']) ?></td>
                                                <td><?= htmlspecialchars((string)($s['nama_kelas'] ?? '-')) ?></td>
                                                <td class="text-success"><?= tabungan_rupiah($s['total_setor']) ?></td>
                                                <td class="text-danger"><?= tabungan_rupiah($s['total_tarik']) ?></td>
                                                <td class="font-weight-bold <?= (int)$s['saldo'] > 0 ? 'text-primary' : '' ?>"><?= tabungan_rupiah($s['saldo']) ?></td>
                                                <td>
                                                    <div class="tb-actions">
                                                        <a href="tabungan_data.php?nisn=<?= urlencode((string)$s['nisn']) ?>" class="btn btn-info btn-sm" title="Detail Tabungan">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </section>
</div>

<script>
(function () {
    var input = document.getElementById('cariSiswa');
    if (!input) return;
    var form = input.closest('form');
    var timer = null;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { form.submit(); }, 500);
    });
})();
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
