<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['siswa'])) {
    redirect('../login.php');
}

$id_siswa = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT s.*, k.nama_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_siswa = ?");
$stmt->execute([$id_siswa]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Data siswa tidak ditemukan.");
}

$nis = $student['nis'] ?? $student['nisn'] ?? '';
$page_title = 'Tabungan Siswa';

$tabunganSummary = fetchEtabsData($nis, 'summary');
$riwayatTransaksi = fetchEtabsData($nis, 'riwayat');

include '../templates/header.php';
include_once '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Tabungan Siswa</h1>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-body">
                    <div class="mb-4">
                        <table class="table-borderless">
                            <tr>
                                <td width="120">Nama Siswa</td>
                                <td width="20">:</td>
                                <td class="font-weight-bold"><?= htmlspecialchars($student['nama_siswa'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <td>Kelas</td>
                                <td>:</td>
                                <td class="font-weight-bold"><?= htmlspecialchars($student['nama_kelas'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <td>NIS</td>
                                <td>:</td>
                                <td class="font-weight-bold"><?= htmlspecialchars($nis ?? '-') ?></td>
                            </tr>
                        </table>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-4">
                            <div class="card card-statistic-1 bg-primary">
                                <div class="card-icon">
                                    <i class="fas fa-piggy-bank"></i>
                                </div>
                                <div class="card-wrap text-white">
                                    <div class="card-header">
                                        <h4 class="text-white font-weight-bold">Total Setoran</h4>
                                    </div>
                                    <div class="card-body text-white font-weight-bold">
                                        <?php if ($tabunganSummary['success'] ?? false): ?>
                                            Rp <?= number_format($tabunganSummary['data']['total_setor'] ?? 0, 0, ',', '.') ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card card-statistic-1 bg-warning">
                                <div class="card-icon">
                                    <i class="fas fa-hand-holding-usd"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4 class="text-dark font-weight-bold">Total Tarikan</h4>
                                    </div>
                                    <div class="card-body text-dark font-weight-bold">
                                        <?php if ($tabunganSummary['success'] ?? false): ?>
                                            Rp <?= number_format($tabunganSummary['data']['total_tarik'] ?? 0, 0, ',', '.') ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card card-statistic-1 bg-success">
                                <div class="card-icon">
                                    <i class="fas fa-wallet"></i>
                                </div>
                                <div class="card-wrap text-white">
                                    <div class="card-header">
                                        <h4 class="text-white font-weight-bold">Saldo</h4>
                                    </div>
                                    <div class="card-body text-white font-weight-bold">
                                        <?php if ($tabunganSummary['success'] ?? false): ?>
                                            Rp <?= number_format($tabunganSummary['data']['saldo'] ?? 0, 0, ',', '.') ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <h5 class="mb-3">Riwayat Transaksi</h5>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Jenis</th>
                                    <th>Nominal</th>
                                    <th>Keterangan</th>
                                    <th>Petugas</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $transaksinya = $riwayatTransaksi['data']['transaksi'] ?? [];
                                if (($riwayatTransaksi['success'] ?? false) && !empty($transaksinya)): ?>
                                    <?php foreach ($transaksinya as $transaksi): ?>
                                        <?php 
                                        $keterangan = '';
                                        if ($transaksi['jenis'] === 'setoran') {
                                            $keterangan = 'Setoran Tunai';
                                        } else {
                                            if (!empty($transaksi['keterangan'])) {
                                                $keterangan = $transaksi['keterangan'];
                                            } else {
                                                $keterangan = 'Penarikan Tunai';
                                            }
                                        }
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars(date('d-m-Y', strtotime($transaksi['tanggal'] ?? '-'))) ?></td>
                                            <td>
                                                <?php if ($transaksi['jenis'] === 'setoran'): ?>
                                                    <span class="badge badge-success"><?= htmlspecialchars($transaksi['jenis']) ?></span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger"><?= htmlspecialchars($transaksi['jenis']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>Rp <?= number_format($transaksi['nominal'] ?? 0, 0, ',', '.') ?></td>
                                            <td><?= htmlspecialchars($keterangan) ?></td>
                                            <td><?= htmlspecialchars($transaksi['petugas'] ?? '-') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4">
                                            <?php if (!($tabunganSummary['success'] ?? false) || !($riwayatTransaksi['success'] ?? false)): ?>
                                                <div class="alert alert-warning">
                                                    <i class="fas fa-exclamation-triangle"></i> 
                                                    <?= htmlspecialchars($tabunganSummary['message'] ?? $riwayatTransaksi['message'] ?? 'Tidak dapat mengambil data dari server.') ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">Belum ada data transaksi.</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
