<?php
require_once '../config/database.php';
require_once '../config/functions.php';

function rupiahLaporan($amount): string {
    return 'Rp. ' . number_format((int)$amount, 0, ',', '.');
}

function bulanTahunAjaranBerjalan(string $tahunAjaran): array {
    $bulan = [
        ['nama' => 'Juli', 'bulan' => 7, 'offset' => 0],
        ['nama' => 'Agustus', 'bulan' => 8, 'offset' => 0],
        ['nama' => 'September', 'bulan' => 9, 'offset' => 0],
        ['nama' => 'Oktober', 'bulan' => 10, 'offset' => 0],
        ['nama' => 'November', 'bulan' => 11, 'offset' => 0],
        ['nama' => 'Desember', 'bulan' => 12, 'offset' => 0],
        ['nama' => 'Januari', 'bulan' => 1, 'offset' => 1],
        ['nama' => 'Februari', 'bulan' => 2, 'offset' => 1],
        ['nama' => 'Maret', 'bulan' => 3, 'offset' => 1],
        ['nama' => 'April', 'bulan' => 4, 'offset' => 1],
        ['nama' => 'Mei', 'bulan' => 5, 'offset' => 1],
        ['nama' => 'Juni', 'bulan' => 6, 'offset' => 1],
    ];

    if (!preg_match('/^(\d{4})\/(\d{4})$/', trim($tahunAjaran), $m)) {
        return $bulan;
    }

    $startYear = (int)$m[1];
    $todayYm = (int)date('Ym');
    $out = [];
    foreach ($bulan as $item) {
        $ym = (($startYear + (int)$item['offset']) * 100) + (int)$item['bulan'];
        if ($ym <= $todayYm) {
            $out[] = $item['nama'];
        }
    }
    return $out;
}

function cariPembayaranBulanan(array $payments, string $namaPembayaran, string $bulan, string $tahunAjaran): ?array {
    foreach ($payments as $p) {
        if (!is_array($p)) {
            continue;
        }
        if ((string)($p['nama_pembayaran'] ?? '') !== $namaPembayaran) {
            continue;
        }
        if (!empty($p['tahun_ajaran']) && (string)$p['tahun_ajaran'] !== $tahunAjaran) {
            continue;
        }
        $ket = (string)($p['ket'] ?? '');
        if (stripos($ket, $bulan) !== false) {
            return $p;
        }
    }
    return null;
}

function filterPembayaranKategori(array $payments, string $namaPembayaran, string $tahunAjaran): array {
    return array_values(array_filter($payments, static function ($p) use ($namaPembayaran, $tahunAjaran): bool {
        if (!is_array($p) || (string)($p['nama_pembayaran'] ?? '') !== $namaPembayaran) {
            return false;
        }
        return empty($p['tahun_ajaran']) || (string)$p['tahun_ajaran'] === $tahunAjaran;
    }));
}

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
$tahun_ajaran_sibayar = (string)($sibayar_response['tahun_ajaran'] ?? $sibayar_response['tahun_ajaran_aktif'] ?? '');

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
                                    <td class="font-weight-bold"><?= htmlspecialchars($tahun_ajaran_sibayar !== '' ? $tahun_ajaran_sibayar : ($school_profile['tahun_ajaran'] ?? '-')) ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <?php if ($api_status === 'success' && !empty($billing_data)): ?>
                        <?php 
                        foreach ($billing_data as $category): 
                            $is_bulanan = ($category['tipe_bayar'] === 'Bulanan');
                            $category_tahun_ajaran = (string)($category['tahun_ajaran'] ?? $tahun_ajaran_sibayar);
                            $months = bulanTahunAjaranBerjalan($category_tahun_ajaran);
                        ?>
                            <div class="mb-5">
                                <h6 class="font-weight-bold text-dark mb-2">
                                    <?= htmlspecialchars($category['nama_pembayaran']) ?> (<?= rupiahLaporan($category['total_nominal'] ?? 0) ?>)
                                    <span class="badge badge-light border ml-2"><?= htmlspecialchars($category_tahun_ajaran ?: '-') ?></span>
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
                                                <?php if (empty($months)): ?>
                                                    <tr>
                                                        <td colspan="4" class="text-center text-muted py-3">Belum ada bulan berjalan untuk tahun ajaran ini.</td>
                                                    </tr>
                                                <?php endif; ?>
                                                <?php foreach ($months as $m): ?>
                                                    <?php
                                                    $unpaid_months = $category['item_belum_bayar'] ?? [];
                                                    $is_unpaid_by_sibayar = is_array($unpaid_months) && in_array($m, $unpaid_months, true);
                                                    $payment_info = cariPembayaranBulanan($payments_data, (string)$category['nama_pembayaran'], $m, $category_tahun_ajaran);
                                                    $is_paid = $payment_info !== null;
                                                    ?>
                                                    <tr>
                                                        <td class="pl-3"><?= $m ?></td>
                                                        <td class="text-center">
                                                            <?php if ($is_paid && !$is_unpaid_by_sibayar): ?>
                                                                <span class="text-success">Lunas</span>
                                                            <?php else: ?>
                                                                <span class="text-danger">Belum Bayar</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-center">
                                                            <?= $payment_info ? date('d/m/Y', strtotime($payment_info['tgl_bayar'])) : '-' ?>
                                                        </td>
                                                        <td class="text-right pr-3">
                                                            <?= $payment_info ? rupiahLaporan($payment_info['jumlah_bayar'] ?? $category['total_nominal'] ?? 0) : '-' ?>
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
                                                <td class="pl-3"><?= rupiahLaporan($category['total_nominal'] ?? 0) ?></td>
                                            </tr>
                                            <tr>
                                                <td class="bg-light pl-3">Total Dibayar</td>
                                                <td class="pl-3"><?= rupiahLaporan(($category['total_nominal'] ?? 0) - ($category['sisa_tagihan'] ?? 0)) ?></td>
                                            </tr>
                                            <tr>
                                                <td class="bg-light pl-3">Sisa Tagihan</td>
                                                <td class="pl-3 font-weight-bold text-danger"><?= rupiahLaporan($category['sisa_tagihan'] ?? 0) ?></td>
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
                                    $category_history = filterPembayaranKategori($payments_data, (string)$category['nama_pembayaran'], $category_tahun_ajaran);
                                    ?>
                                    
                                    <?php if (!empty($category_history)): ?>
                                        <div class="mt-3">
                                            <div class="font-weight-bold text-dark">Riwayat Pembayaran:</div>
                                            <ul class="list-unstyled mb-0 pl-3">
                                                <?php foreach ($category_history as $h): ?>
                                                    <li><i class="fas fa-circle" style="font-size: 8px; vertical-align: middle;"></i> <?= date('d/m/Y', strtotime($h['tgl_bayar'])) ?> - <?= rupiahLaporan($h['jumlah_bayar'] ?? 0) ?></li>
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
