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

$saldo_nominal = $tabunganSummary['data']['saldo'] ?? 0;
$setor_nominal = $tabunganSummary['data']['total_setor'] ?? 0;
$tarik_nominal = $tabunganSummary['data']['total_tarik'] ?? 0;
$transaksinya = $riwayatTransaksi['data']['transaksi'] ?? [];
?>

<link rel="stylesheet" href="../assets/css/siswa-keuangan-mobile.css">

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Tabungan Siswa</h1>
        </div>

        <div class="section-body">
            <!-- Hero Card Tabungan Siswa Ala Mobile Apps -->
            <div class="sfin-hero-card theme-success">
                <div class="sfin-hero-top">
                    <div class="sfin-student-info">
                        <div class="sfin-student-name">
                            <i class="fas fa-piggy-bank mr-1"></i> <?= htmlspecialchars($student['nama_siswa'] ?? '-') ?>
                        </div>
                        <div class="sfin-student-sub">
                            <span>NIS: <?= htmlspecialchars($nis ?? '-') ?></span>
                            <span>•</span>
                            <span>Kelas <?= htmlspecialchars($student['nama_kelas'] ?? '-') ?></span>
                        </div>
                    </div>
                    <div>
                        <span class="sfin-badge-pill">
                            <i class="fas fa-shield-alt mr-1"></i> E-Tabs Simad
                        </span>
                    </div>
                </div>

                <div class="mt-2">
                    <div class="sfin-hero-main-title">Saldo Tabungan Saat Ini</div>
                    <div class="sfin-hero-amount gold">
                        <?php if ($tabunganSummary['success'] ?? false): ?>
                            Rp <?= number_format($saldo_nominal, 0, ',', '.') ?>
                        <?php else: ?>
                            Rp 0
                        <?php endif; ?>
                    </div>
                </div>

                <div class="sfin-stats-grid">
                    <div class="sfin-stat-box">
                        <div class="lbl">Total Setoran</div>
                        <div class="val text-white">
                            <?php if ($tabunganSummary['success'] ?? false): ?>
                                Rp <?= number_format($setor_nominal, 0, ',', '.') ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="sfin-stat-box">
                        <div class="lbl">Total Tarikan</div>
                        <div class="val text-white">
                            <?php if ($tabunganSummary['success'] ?? false): ?>
                                Rp <?= number_format($tarik_nominal, 0, ',', '.') ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Riwayat Transaksi -->
            <div class="sfin-section-head">
                <div class="sfin-section-title">
                    <i class="fas fa-history text-primary"></i> Riwayat Transaksi Tabungan
                </div>
                <div class="sfin-section-count"><?= count($transaksinya) ?> Transaksi</div>
            </div>

            <?php if (($riwayatTransaksi['success'] ?? false) && !empty($transaksinya)): ?>
                <!-- Mobile Cards List View -->
                <div class="sfin-card-list d-md-none">
                    <?php foreach ($transaksinya as $transaksi): ?>
                        <?php 
                        $is_setor = ($transaksi['jenis'] === 'setoran');
                        $keterangan = $is_setor ? 'Setoran Tunai' : (!empty($transaksi['keterangan']) ? $transaksi['keterangan'] : 'Penarikan Tunai');
                        ?>
                        <div class="sfin-trx-card">
                            <div class="sfin-trx-left">
                                <div class="sfin-trx-icon <?= $is_setor ? 'setor' : 'tarik' ?>">
                                    <i class="fas fa-arrow-<?= $is_setor ? 'down' : 'up' ?>"></i>
                                </div>
                                <div class="min-w-0">
                                    <div class="sfin-trx-title"><?= htmlspecialchars($keterangan) ?></div>
                                    <div class="sfin-trx-sub">
                                        <i class="far fa-calendar-alt text-muted"></i> <?= htmlspecialchars(date('d M Y', strtotime($transaksi['tanggal'] ?? '-'))) ?>
                                        <span>•</span>
                                        <span><?= htmlspecialchars($transaksi['petugas'] ?? 'Petugas') ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="sfin-trx-amount">
                                <div class="val <?= $is_setor ? 'plus' : 'minus' ?>">
                                    <?= $is_setor ? '+' : '-' ?> Rp <?= number_format($transaksi['nominal'] ?? 0, 0, ',', '.') ?>
                                </div>
                                <div class="badge-txt text-<?= $is_setor ? 'success' : 'danger' ?>">
                                    <?= htmlspecialchars($transaksi['jenis']) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Desktop Table View -->
                <div class="table-responsive d-none d-md-block">
                    <table class="table table-striped table-hover bg-white border" style="border-radius: 12px; overflow: hidden;">
                        <thead class="bg-light">
                            <tr>
                                <th>Tanggal</th>
                                <th>Jenis</th>
                                <th>Nominal</th>
                                <th>Keterangan</th>
                                <th>Petugas</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transaksinya as $transaksi): ?>
                                <?php 
                                $is_setor = ($transaksi['jenis'] === 'setoran');
                                $keterangan = $is_setor ? 'Setoran Tunai' : (!empty($transaksi['keterangan']) ? $transaksi['keterangan'] : 'Penarikan Tunai');
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars(date('d-m-Y', strtotime($transaksi['tanggal'] ?? '-'))) ?></td>
                                    <td>
                                        <span class="badge badge-<?= $is_setor ? 'success' : 'danger' ?>">
                                            <?= htmlspecialchars($transaksi['jenis']) ?>
                                        </span>
                                    </td>
                                    <td class="font-weight-bold text-<?= $is_setor ? 'success' : 'danger' ?>">
                                        <?= $is_setor ? '+' : '-' ?> Rp <?= number_format($transaksi['nominal'] ?? 0, 0, ',', '.') ?>
                                    </td>
                                    <td><?= htmlspecialchars($keterangan) ?></td>
                                    <td><?= htmlspecialchars($transaksi['petugas'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="card border-0 shadow-sm text-center py-5" style="border-radius: 16px;">
                    <div class="card-body">
                        <?php if (!($tabunganSummary['success'] ?? false) || !($riwayatTransaksi['success'] ?? false)): ?>
                            <i class="fas fa-exclamation-triangle fa-2x mb-3 text-warning"></i><br>
                            <div class="text-dark font-weight-bold">
                                <?= htmlspecialchars($tabunganSummary['message'] ?? $riwayatTransaksi['message'] ?? 'Tidak dapat mengambil data dari server tabungan.') ?>
                            </div>
                        <?php else: ?>
                            <i class="fas fa-wallet fa-2x mb-3 text-muted"></i><br>
                            <div class="text-muted font-weight-bold">Belum ada riwayat transaksi tabungan.</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
