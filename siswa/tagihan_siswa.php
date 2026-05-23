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
$page_title = 'Tagihan Siswa';

// Ambil data dari API Sibayar
$sibayar_response = fetchSibayarData($nisn, 'tagihan');
$tagihan_data = $sibayar_response['data'] ?? [];
$api_status = $sibayar_response['status'] ?? 'error';
$api_message = $sibayar_response['message'] ?? 'Unknown error';

include '../templates/header.php';
include_once '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Tagihan Siswa</h1>
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
                        Sistem tidak dapat mengambil data tagihan terbaru dari server Sibayar. <br>
                        <small class="text-dark"><strong>Detail Teknis:</strong> <?= htmlspecialchars($api_message) ?></small>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <!-- Identitas Siswa -->
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
                                <td>NISN</td>
                                <td>:</td>
                                <td class="font-weight-bold"><?= htmlspecialchars($student['nisn'] ?? '-') ?></td>
                            </tr>
                        </table>
                    </div>

                    <div class="table-responsive">
                        <table class="table text-dark">
                            <thead class="text-center font-weight-bold">
                                <tr>
                                    <th width="50">No</th>
                                    <th>Jenis Pembayaran</th>
                                    <th>Nominal / Tagihan</th>
                                    <th>Status Pembayaran</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($api_status === 'success' && !empty($tagihan_data)): ?>
                                    <?php 
                                    $no = 1;
                                    $grand_total_sisa = 0;
                                    foreach ($tagihan_data as $row): 
                                        $sisa = $row['summary']['sisa_tagihan'] ?? 0;
                                        // Only show in tagihan if sisa > 0
                                        if ($sisa <= 0) continue;
                                        
                                        $grand_total_sisa += $sisa;
                                    ?>
                                        <tr>
                                            <td class="text-center"><?= $no++ ?></td>
                                            <td><?= htmlspecialchars($row['nama_pembayaran'] ?? '-') ?></td>
                                            <td>
                                                Rp <?= number_format($row['nominal'] ?? 0, 0, ',', '.') ?>
                                                <div class="text-danger font-weight-bold">
                                                    Jumlah Tagihan<br><?= htmlspecialchars($row['nama_pembayaran']) ?>: Rp <?= number_format($sisa, 0, ',', '.') ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if (($row['tipe_bayar'] ?? '') === 'Bulanan'): ?>
                                                    <div class="row">
                                                        <?php 
                                                        $half = ceil(count($row['detail']) / 2);
                                                        $chunks = array_chunk($row['detail'], $half);
                                                        ?>
                                                        <?php foreach ($chunks as $chunk): ?>
                                                            <div class="col-6">
                                                                <ul class="list-unstyled mb-0">
                                                                    <?php foreach ($chunk as $d): ?>
                                                                        <li>
                                                                            <?php if ($d['status'] === 'Lunas'): ?>
                                                                                <span class="text-success"><i class="fas fa-check"></i> <?= $d['bulan'] ?></span>
                                                                            <?php else: ?>
                                                                                <span class="text-danger"><i class="fas fa-times"></i> <?= $d['bulan'] ?></span>
                                                                            <?php endif; ?>
                                                                        </li>
                                                                    <?php endforeach; ?>
                                                                </ul>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <div class="text-danger font-weight-bold mt-2">
                                                        Jumlah Tagihan <?= htmlspecialchars($row['nama_pembayaran']) ?>: Rp <?= number_format($sisa, 0, ',', '.') ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-danger font-weight-bold">
                                                        <i class="fas fa-times"></i> Kurang: Rp <?= number_format($sisa, 0, ',', '.') ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="font-weight-bold">
                                        <td colspan="3" class="text-right">Total Tagihan Belum Dibayar</td>
                                        <td class="text-danger">Rp <?= number_format($grand_total_sisa, 0, ',', '.') ?></td>
                                    </tr>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-5">
                                            Tidak ada data tagihan.
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

<style>
/* Remove previous custom styles if any */
</style>

<?php include '../templates/footer.php'; ?>
