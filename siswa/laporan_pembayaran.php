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
$billing_data = $sibayar_response['billing'] ?? [];
$payments_data = $sibayar_response['payments'] ?? [];
$api_status = $sibayar_response['status'] ?? 'error';
$api_message = $sibayar_response['message'] ?? 'Unknown error';

// Get school profile for Tahun Ajaran
$school_profile = getSchoolProfile($pdo);

include '../templates/header.php';
include_once '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Laporan Pembayaran</h1>
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
                        <div class="col-6">
                            <table class="table-sm table-borderless w-100">
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
                        <div class="col-6">
                            <table class="table-sm table-borderless w-100">
                                <tr>
                                    <td width="120">Kelas</td>
                                    <td width="20">:</td>
                                    <td class="font-weight-bold"><?= htmlspecialchars($student['nama_kelas'] ?? '-') ?></td>
                                </tr>
                                <tr>
                                    <td>Tahun Ajaran</td>
                                    <td>:</td>
                                    <td class="font-weight-bold"><?= htmlspecialchars($school_profile['tahun_ajaran'] ?? '-') ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <?php if ($api_status === 'success' && !empty($billing_data)): ?>
                        <?php 
                        $months = ['Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni'];
                        
                        foreach ($billing_data as $category): 
                            $is_bulanan = ($category['tipe_bayar'] === 'Bulanan');
                        ?>
                            <div class="mb-5">
                                <h6 class="font-weight-bold text-dark mb-2">
                                    <?= htmlspecialchars($category['nama_pembayaran']) ?> (Rp. <?= number_format($category['total_nominal'], 0, ',', '.') ?>)
                                </h6>
                                
                                <?php if ($is_bulanan): ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-sm text-dark">
                                            <thead class="bg-light text-center">
                                                <tr>
                                                    <th width="200">Bulan</th>
                                                    <th width="200">Status</th>
                                                    <th width="200">Tanggal Bayar</th>
                                                    <th width="200">Jumlah</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($months as $m): 
                                                    $is_unpaid = in_array($m, $category['item_belum_bayar'] ?? []);
                                                    $payment_info = null;
                                                    
                                                    if (!$is_unpaid) {
                                                        // Search for this month in payments
                                                        foreach ($payments_data as $p) {
                                                            if ($p['nama_pembayaran'] === $category['nama_pembayaran'] && strpos($p['ket'], $m) !== false) {
                                                                $payment_info = $p;
                                                                break;
                                                            }
                                                        }
                                                    }
                                                ?>
                                                    <tr>
                                                        <td class="pl-3"><?= $m ?></td>
                                                        <td class="text-center">
                                                            <?= $is_unpaid ? 'Belum Bayar' : '<span class="text-success">Lunas</span>' ?>
                                                        </td>
                                                        <td class="text-center">
                                                            <?= $payment_info ? date('d/m/Y', strtotime($payment_info['tgl_bayar'])) : '-' ?>
                                                        </td>
                                                        <td class="text-right pr-3">
                                                            <?= $payment_info ? number_format($category['total_nominal'], 0, ',', '.') : '-' ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-sm text-dark w-100">
                                            <tr>
                                                <td width="200" class="bg-light pl-3">Total Tagihan</td>
                                                <td class="pl-3">Rp. <?= number_format($category['total_nominal'], 0, ',', '.') ?></td>
                                            </tr>
                                            <tr>
                                                <td class="bg-light pl-3">Total Dibayar</td>
                                                <td class="pl-3">Rp. <?= number_format($category['total_nominal'] - $category['sisa_tagihan'], 0, ',', '.') ?></td>
                                            </tr>
                                            <tr>
                                                <td class="bg-light pl-3">Sisa Tagihan</td>
                                                <td class="pl-3 font-weight-bold text-danger">Rp. <?= number_format($category['sisa_tagihan'], 0, ',', '.') ?></td>
                                            </tr>
                                            <tr>
                                                <td class="bg-light pl-3">Status</td>
                                                <td class="pl-3">
                                                    <?php if ($category['is_lunas']): ?>
                                                        <span class="badge badge-success">Lunas</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-warning">Belum Lunas</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>

                                    <?php 
                                    // Get payment history for this specific category
                                    $category_history = array_filter($payments_data, function($p) use ($category) {
                                        return $p['nama_pembayaran'] === $category['nama_pembayaran'];
                                    });
                                    ?>
                                    
                                    <?php if (!empty($category_history)): ?>
                                        <div class="mt-3">
                                            <div class="font-weight-bold text-dark">Riwayat Pembayaran:</div>
                                            <ul class="list-unstyled mb-0 pl-3">
                                                <?php foreach ($category_history as $h): ?>
                                                    <li><i class="fas fa-circle" style="font-size: 8px; vertical-align: middle;"></i> <?= date('d/m/Y', strtotime($h['tgl_bayar'])) ?> - Rp. <?= number_format($h['jumlah_bayar'], 0, ',', '.') ?></li>
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
