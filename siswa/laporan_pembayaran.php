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

// Ambil data dari API Pembayaran
$pembayaran_response = fetchPembayaranData($nisn, 'laporan');
$billing_data = $pembayaran_response['billing'] ?? [];
$payments_data = $pembayaran_response['payments'] ?? [];
$api_status = $pembayaran_response['status'] ?? 'error';
$api_message = $pembayaran_response['message'] ?? 'Unknown error';
$tahun_ajaran_pembayaran = (string)($pembayaran_response['tahun_ajaran'] ?? $pembayaran_response['tahun_ajaran_aktif'] ?? '');

// Get school profile for Tahun Ajaran
$school_profile = getSchoolProfile($pdo);

include '../templates/header.php';
include_once '../templates/sidebar.php';
?>

<link rel="stylesheet" href="../assets/css/siswa-keuangan-mobile.css">

<style>
.sfin-month-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 8px;
    margin-top: 10px;
}
.sfin-month-pill {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 9px 10px;
    display: flex;
    flex-direction: column;
    gap: 2px;
    transition: all .15s ease;
}
.sfin-month-pill.is-paid {
    background: #f0fdf4;
    border-color: #bbf7d0;
}
.sfin-month-pill.is-unpaid {
    background: #fff1f2;
    border-color: #fecdd3;
}
.sfin-month-name {
    font-size: 13px;
    font-weight: 800;
    color: #1e293b;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.sfin-month-status {
    font-size: 9.5px;
    font-weight: 700;
    text-transform: uppercase;
}
.sfin-month-meta {
    font-size: 10.5px;
    color: #64748b;
    font-weight: 600;
    margin-top: 2px;
}
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Laporan Pembayaran</h1>
        </div>

        <div class="section-body">
            <?php if ($api_status !== 'success'): ?>
                <div class="alert alert-warning alert-has-icon mb-3" style="border-radius: 14px;">
                    <div class="alert-icon"><i class="fas fa-plug"></i></div>
                    <div class="alert-body">
                        <div class="alert-title font-weight-bold">Koneksi Server Pembayaran</div>
                        Sistem menampilkan data riwayat lokal. Data realtime belum tersambung.<br>
                        <small class="text-dark"><strong>Catatan:</strong> <?= htmlspecialchars($api_message) ?></small>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Hero Card Laporan Pembayaran Siswa -->
            <div class="sfin-hero-card theme-primary">
                <div class="sfin-hero-top">
                    <div class="sfin-student-info">
                        <div class="sfin-student-name">
                            <i class="fas fa-file-invoice-dollar mr-1"></i> <?= htmlspecialchars($student['nama_siswa'] ?? '-') ?>
                        </div>
                        <div class="sfin-student-sub">
                            <span>NISN: <?= htmlspecialchars($student['nisn'] ?? '-') ?></span>
                            <span>•</span>
                            <span>Kelas <?= htmlspecialchars($student['nama_kelas'] ?? '-') ?></span>
                        </div>
                    </div>
                    <div>
                        <span class="sfin-badge-pill">
                            TA <?= htmlspecialchars($tahun_ajaran_pembayaran !== '' ? $tahun_ajaran_pembayaran : ($school_profile['tahun_ajaran'] ?? '-')) ?>
                        </span>
                    </div>
                </div>

                <div class="mt-2">
                    <div class="sfin-hero-main-title">Status Laporan Pembayaran</div>
                    <div class="sfin-hero-amount">
                        <?= !empty($billing_data) ? count($billing_data) . ' Kategori Tagihan' : 'Belum Ada Tagihan' ?>
                    </div>
                </div>
            </div>

            <!-- Daftar Kategori Pembayaran -->
            <?php if ($api_status === 'success' && !empty($billing_data)): ?>
                <?php 
                foreach ($billing_data as $category): 
                    $is_bulanan = ($category['tipe_bayar'] === 'Bulanan');
                    $category_tahun_ajaran = (string)($category['tahun_ajaran'] ?? $tahun_ajaran_pembayaran);
                    $months = bulanTahunAjaranBerjalan($category_tahun_ajaran);
                ?>
                    <div class="card border-0 shadow-sm mb-4" style="border-radius: 16px; overflow: hidden;">
                        <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="font-weight-bold text-dark mb-0" style="font-size: 15px;">
                                    <i class="fas fa-wallet text-primary mr-1"></i> <?= htmlspecialchars($category['nama_pembayaran']) ?>
                                </h6>
                                <small class="text-muted font-weight-bold">
                                    Tarif: <?= rupiahLaporan($category['total_nominal'] ?? 0) ?> · TA <?= htmlspecialchars($category_tahun_ajaran ?: '-') ?>
                                </small>
                            </div>
                            <span class="badge badge-light border font-weight-bold px-2 py-1">
                                <?= htmlspecialchars($category['tipe_bayar']) ?>
                            </span>
                        </div>
                        <div class="card-body p-3">
                            <?php if ($is_bulanan): ?>
                                <!-- Mobile Grid Bulan SPP -->
                                <div class="sfin-month-grid">
                                    <?php if (empty($months)): ?>
                                        <div class="text-muted small py-2">Belum ada bulan berjalan untuk tahun ajaran ini.</div>
                                    <?php endif; ?>
                                    <?php foreach ($months as $m): ?>
                                        <?php
                                        $unpaid_months = $category['item_belum_bayar'] ?? [];
                                        $is_unpaid_by_pembayaran = is_array($unpaid_months) && in_array($m, $unpaid_months, true);
                                        $payment_info = cariPembayaranBulanan($payments_data, (string)$category['nama_pembayaran'], $m, $category_tahun_ajaran);
                                        $is_paid = $payment_info !== null && !$is_unpaid_by_pembayaran;
                                        ?>
                                        <div class="sfin-month-pill <?= $is_paid ? 'is-paid' : 'is-unpaid' ?>">
                                            <div class="sfin-month-name">
                                                <span><?= $m ?></span>
                                                <span class="sfin-month-status <?= $is_paid ? 'text-success' : 'text-danger' ?>">
                                                    <i class="fas fa-<?= $is_paid ? 'check-circle' : 'times-circle' ?>"></i>
                                                    <?= $is_paid ? 'Lunas' : 'Belum' ?>
                                                </span>
                                            </div>
                                            <div class="sfin-month-meta">
                                                <?php if ($is_paid && $payment_info): ?>
                                                    <?= date('d/m/y', strtotime($payment_info['tgl_bayar'])) ?> · <?= rupiahLaporan($payment_info['jumlah_bayar'] ?? $category['total_nominal'] ?? 0) ?>
                                                <?php else: ?>
                                                    <span class="text-danger">Belum Bayar</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <!-- Tipe Bebas / Non-Bulanan -->
                                <div class="sfin-bill-meta-grid mb-3">
                                    <div class="sfin-bill-meta-item">
                                        <div class="meta-lbl">Total Tagihan</div>
                                        <div class="meta-val"><?= rupiahLaporan($category['total_nominal'] ?? 0) ?></div>
                                    </div>
                                    <div class="sfin-bill-meta-item">
                                        <div class="meta-lbl">Total Dibayar</div>
                                        <div class="meta-val text-success"><?= rupiahLaporan(($category['total_nominal'] ?? 0) - ($category['sisa_tagihan'] ?? 0)) ?></div>
                                    </div>
                                    <div class="sfin-bill-meta-item" style="grid-column: span 2; border-top: 1px dashed #e2e8f0; padding-top: 6px; margin-top: 2px;">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="meta-lbl font-weight-bold">Sisa Tagihan</div>
                                            <div class="meta-val text-danger" style="font-size: 15px;"><?= rupiahLaporan($category['sisa_tagihan'] ?? 0) ?></div>
                                        </div>
                                    </div>
                                </div>

                                <?php 
                                $category_history = filterPembayaranKategori($payments_data, (string)$category['nama_pembayaran'], $category_tahun_ajaran);
                                ?>
                                <?php if (!empty($category_history)): ?>
                                    <div>
                                        <div class="small font-weight-bold text-dark mb-2"><i class="fas fa-history text-muted mr-1"></i> Riwayat Pembayaran:</div>
                                        <div class="d-flex flex-column" style="gap: 6px;">
                                            <?php foreach ($category_history as $h): ?>
                                                <div class="d-flex justify-content-between align-items-center bg-light px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                                                    <span><i class="far fa-calendar-alt text-muted mr-1"></i> <?= date('d/m/Y', strtotime($h['tgl_bayar'])) ?></span>
                                                    <span class="font-weight-bold text-success">+ <?= rupiahLaporan($h['jumlah_bayar'] ?? 0) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="card border-0 shadow-sm text-center py-5" style="border-radius: 16px;">
                    <div class="card-body">
                        <i class="fas fa-info-circle fa-2x mb-3 text-muted"></i><br>
                        <div class="text-muted font-weight-bold">Belum ada riwayat pembayaran yang ditemukan.</div>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </section>
</div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
