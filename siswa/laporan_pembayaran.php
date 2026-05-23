<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has siswa level
if (!isAuthorized(['siswa'])) {
    redirect('../login.php');
}

// Get student data
$id_siswa = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT s.*, k.nama_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_siswa = ?");
$stmt->execute([$id_siswa]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Data siswa tidak ditemukan.");
}

$nisn = $student['nisn'];
$page_title = 'Laporan Pembayaran';

// Ambil data dari API Sibayar
$sibayar_response = fetchSibayarData($nisn, 'laporan');
$laporan_data = $sibayar_response['data'] ?? [];
$api_status = $sibayar_response['status'] ?? 'error';
$api_message = $sibayar_response['message'] ?? 'Unknown error';

include '../templates/header.php';
include_once '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Laporan Pembayaran</h1>
            <div class="section-header-breadcrumb">
                <?php if ($api_status === 'success'): ?>
                    <div class="breadcrumb-item"><span class="badge badge-info"><i class="fas fa-sync-alt fa-spin"></i> Data Real-time dari Sibayar</span></div>
                <?php else: ?>
                    <div class="breadcrumb-item"><span class="badge badge-danger"><i class="fas fa-exclamation-triangle"></i> Gagal Terhubung ke Sibayar</span></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="section-body">
            <?php if ($api_status !== 'success'): ?>
                <div class="alert alert-warning alert-has-icon">
                    <div class="alert-icon"><i class="fas fa-plug"></i></div>
                    <div class="alert-body">
                        <div class="alert-title">Koneksi Terputus</div>
                        Sistem tidak dapat mengambil data laporan terbaru dari server Sibayar. <br>
                        <small class="text-dark"><strong>Detail Teknis:</strong> <?= htmlspecialchars($api_message) ?></small>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <!-- Identitas Siswa -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <table class="table-borderless">
                                <tr>
                                    <td width="120">NISN</td>
                                    <td width="20">:</td>
                                    <td class="font-weight-bold"><?= htmlspecialchars($student['nisn'] ?? '-') ?></td>
                                </tr>
                                <tr>
                                    <td>Nama Siswa</td>
                                    <td>:</td>
                                    <td class="font-weight-bold"><?= htmlspecialchars($student['nama_siswa'] ?? '-') ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table-borderless">
                                <tr>
                                    <td width="120">Kelas</td>
                                    <td width="20">:</td>
                                    <td class="font-weight-bold"><?= htmlspecialchars($student['nama_kelas'] ?? '-') ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <?php if ($api_status === 'success' && !empty($laporan_data)): ?>
                        <?php foreach ($laporan_data as $row): ?>
                            <div class="mb-5">
                                <h6 class="font-weight-bold text-dark mb-3"><?= htmlspecialchars($row['nama_pembayaran']) ?> (Rp. <?= number_format($row['nominal'], 0, ',', '.') ?>)</h6>
                                
                                <?php if ($row['tipe_bayar'] === 'Bulanan'): ?>
                                    <div class="table-responsive">
                                        <table class="table text-dark">
                                            <thead class="text-center font-weight-bold">
                                                <tr>
                                                    <th>Bulan</th>
                                                    <th>Status</th>
                                                    <th>Tanggal Bayar</th>
                                                    <th>Jumlah</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($row['detail'] as $d): ?>
                                                    <tr>
                                                        <td><?= $d['bulan'] ?></td>
                                                        <td class="<?= $d['status'] === 'Lunas' ? 'text-success' : 'text-danger' ?>">
                                                            <?= $d['status'] ?>
                                                        </td>
                                                        <td class="text-center"><?= $d['tgl'] !== '-' ? date('d/m/Y', strtotime($d['tgl'])) : '-' ?></td>
                                                        <td class="text-right"><?= $d['jml'] > 0 ? number_format($d['jml'], 0, ',', '.') : '-' ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table text-dark" style="width: 400px; max-width: 100%;">
                                            <tr>
                                                <td width="150">Total Tagihan</td>
                                                <td>Rp. <?= number_format($row['summary']['total_tagihan'], 0, ',', '.') ?></td>
                                            </tr>
                                            <tr>
                                                <td>Total Dibayar</td>
                                                <td>Rp. <?= number_format($row['summary']['total_dibayar'], 0, ',', '.') ?></td>
                                            </tr>
                                            <tr>
                                                <td>Sisa Tagihan</td>
                                                <td>Rp. <?= number_format($row['summary']['sisa_tagihan'], 0, ',', '.') ?></td>
                                            </tr>
                                            <tr>
                                                <td>Status</td>
                                                <td class="<?= $row['summary']['status'] === 'Lunas' ? 'text-success' : 'text-danger' ?>">
                                                    <?= $row['summary']['status'] ?>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    <?php if (!empty($row['detail'])): ?>
                                        <div class="mt-2 small text-muted">
                                            <strong>Riwayat Pembayaran:</strong>
                                            <ul class="mb-0">
                                                <?php foreach ($row['detail'] as $h): ?>
                                                    <li><?= date('d/m/Y', strtotime($h['tgl_bayar'])) ?>: Rp. <?= number_format($h['jumlah_bayar'], 0, ',', '.') ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-info-circle fa-2x mb-3 text-muted"></i><br>
                            Belum ada riwayat pembayaran yang ditemukan.
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
