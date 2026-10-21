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

$nis = (string)($student['nisn'] ?? $student['nis'] ?? '');
$page_title = 'Tabungan Siswa';

/* ── Data tabungan dari modul Tabungan (database SIMAD) ────── */
require_once __DIR__ . '/../bendahara/tabungan/config/schema.php';
require_once __DIR__ . '/../bendahara/tabungan/config/helpers.php';
tabungan_ensure_schema($pdo);

$transaksinya = tabungan_transaksi($pdo, ['nisn' => $nis]);
$setor_nominal = 0;
$tarik_nominal = 0;
foreach ($transaksinya as $trx) {
    $setor_nominal += (int)$trx['setor'];
    $tarik_nominal += (int)$trx['tarik'];
}
$saldo_nominal = $setor_nominal - $tarik_nominal;

include '../templates/header.php';
include_once '../templates/sidebar.php';
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
                            <i class="fas fa-shield-alt mr-1"></i> Tabungan SIMAD
                        </span>
                    </div>
                </div>

                <div class="mt-2">
                    <div class="sfin-hero-main-title">Saldo Tabungan Saat Ini</div>
                    <div class="sfin-hero-amount gold">
                        Rp <?= number_format($saldo_nominal, 0, ',', '.') ?>
                    </div>
                </div>

                <div class="sfin-stats-grid">
                    <div class="sfin-stat-box">
                        <div class="lbl">Total Setoran</div>
                        <div class="val text-white">
                            Rp <?= number_format($setor_nominal, 0, ',', '.') ?>
                        </div>
                    </div>
                    <div class="sfin-stat-box">
                        <div class="lbl">Total Tarikan</div>
                        <div class="val text-white">
                            Rp <?= number_format($tarik_nominal, 0, ',', '.') ?>
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

            <?php if (!empty($transaksinya)): ?>
                <!-- Mobile Cards List View -->
                <div class="sfin-card-list d-md-none">
                    <?php foreach ($transaksinya as $transaksi): ?>
                        <?php 
                        $is_setor = ((string)($transaksi['jenis'] ?? '')) === 'ST';
                        $nominal_trx = $is_setor ? (int)($transaksi['setor'] ?? 0) : (int)($transaksi['tarik'] ?? 0);
                        $keterangan = $is_setor ? 'Setoran Tunai' : 'Penarikan Tunai';
                        if (!$is_setor) {
                            $ket_extra = trim((string)($transaksi['jenis_bayar'] ?? ''));
                            if ($ket_extra === '') {
                                $ket_extra = trim((string)($transaksi['keterangan_tarik'] ?? ''));
                            }
                            if ($ket_extra !== '') {
                                $keterangan = $ket_extra;
                            }
                        }
                        $tgl_trx = (string)($transaksi['tgl'] ?? '');
                        ?>
                        <div class="sfin-trx-card">
                            <div class="sfin-trx-left">
                                <div class="sfin-trx-icon <?= $is_setor ? 'setor' : 'tarik' ?>">
                                    <i class="fas fa-arrow-<?= $is_setor ? 'down' : 'up' ?>"></i>
                                </div>
                                <div class="min-w-0">
                                    <div class="sfin-trx-title"><?= htmlspecialchars($keterangan) ?></div>
                                    <div class="sfin-trx-sub">
                                        <i class="far fa-calendar-alt text-muted"></i> <?= htmlspecialchars($tgl_trx !== '' ? date('d M Y', strtotime($tgl_trx)) : '-') ?>
                                        <span>•</span>
                                        <span><?= htmlspecialchars(tabungan_aktor_label($transaksi['petugas'] ?? '-')) ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="sfin-trx-amount">
                                <div class="val <?= $is_setor ? 'plus' : 'minus' ?>">
                                    <?= $is_setor ? '+' : '-' ?> Rp <?= number_format($nominal_trx, 0, ',', '.') ?>
                                </div>
                                <div class="badge-txt text-<?= $is_setor ? 'success' : 'danger' ?>">
                                    <?= $is_setor ? 'Setoran' : 'Penarikan' ?>
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
                                <th>Admin/Bendahara</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transaksinya as $transaksi): ?>
                                <?php 
                                $is_setor = ((string)($transaksi['jenis'] ?? '')) === 'ST';
                                $nominal_trx = $is_setor ? (int)($transaksi['setor'] ?? 0) : (int)($transaksi['tarik'] ?? 0);
                                $keterangan = $is_setor ? 'Setoran Tunai' : 'Penarikan Tunai';
                                if (!$is_setor) {
                                    $ket_extra = trim((string)($transaksi['jenis_bayar'] ?? ''));
                                    if ($ket_extra === '') {
                                        $ket_extra = trim((string)($transaksi['keterangan_tarik'] ?? ''));
                                    }
                                    if ($ket_extra !== '') {
                                        $keterangan = $ket_extra;
                                    }
                                }
                                $tgl_trx = (string)($transaksi['tgl'] ?? '');
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($tgl_trx !== '' ? date('d-m-Y', strtotime($tgl_trx)) : '-') ?></td>
                                    <td>
                                        <span class="badge badge-<?= $is_setor ? 'success' : 'danger' ?>">
                                            <?= $is_setor ? 'Setoran' : 'Penarikan' ?>
                                        </span>
                                    </td>
                                    <td class="font-weight-bold text-<?= $is_setor ? 'success' : 'danger' ?>">
                                        <?= $is_setor ? '+' : '-' ?> Rp <?= number_format($nominal_trx, 0, ',', '.') ?>
                                    </td>
                                    <td><?= htmlspecialchars($keterangan) ?></td>
                                    <td><?= htmlspecialchars(tabungan_aktor_label($transaksi['petugas'] ?? '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="card border-0 shadow-sm text-center py-5" style="border-radius: 16px;">
                    <div class="card-body">
                        <i class="fas fa-wallet fa-2x mb-3 text-muted"></i><br>
                        <div class="text-muted font-weight-bold">Belum ada riwayat transaksi tabungan.</div>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
