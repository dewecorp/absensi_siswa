<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['guru', 'wali'])) {
    redirect('../login.php');
}

$school_profile = getSchoolProfile($pdo);

if ($_SESSION['level'] == 'guru' || $_SESSION['level'] == 'wali') {
    $stmt = $pdo->prepare("SELECT * FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT g.* FROM tb_guru g JOIN tb_pengguna p ON g.id_guru = p.id_guru WHERE p.id_pengguna = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$teacher) {
    die('Error: Data guru tidak ditemukan');
}

if (!isset($_SESSION['nama_guru']) || empty($_SESSION['nama_guru'])) {
    $_SESSION['nama_guru'] = $teacher['nama_guru'];
}

$guru_id = $teacher['id_guru'];
$page_title = 'Data Gaji Guru';

// URL + key sigaji 100% dari Pengaturan Endpoint (tanpa hardcode domain).
$api_url = '';
try {
    $sig_ep = $pdo->prepare("SELECT base_url, api_key FROM tb_endpoint_masuk WHERE nama_aplikasi = 'sigaji' AND aktif = 1 LIMIT 1");
    $sig_ep->execute();
    $sig_row = $sig_ep->fetch(PDO::FETCH_ASSOC);
    $sig_base = trim((string)($sig_row['base_url'] ?? ''));
    $sig_key = trim((string)($sig_row['api_key'] ?? ''));
    if ($sig_base !== '') {
        $api_url = rtrim($sig_base, '/');
        if ($sig_key !== '') {
            $api_url .= (strpos($api_url, '?') === false ? '?' : '&') . 'api_key=' . urlencode($sig_key);
        }
    }
} catch (Exception $e) { /* biarkan kosong, ditangani di bawah */ }

$salary_data = null;
$api_error = null;
$is_dev_data = false;

$cache_dir = __DIR__ . '/../cache';
$cache_file = $cache_dir . '/sigaji_gaji.json';

if (!is_dir($cache_dir)) {
    @mkdir($cache_dir, 0755, true);
}

// Seed cache with sample data for development
if (isset($_GET['seed']) && $_GET['seed'] === '1') {
    $stmt = $pdo->query("SELECT id_guru, nama_guru FROM tb_guru ORDER BY nama_guru ASC");
    $all_guru = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $guru_sample = [];
    foreach ($all_guru as $g) {
        $gp = rand(1500000, 5000000);
        $tunj = rand(200000, 1000000);
        $pot = rand(50000, 300000);
        $bersih = $gp + $tunj - $pot;
        $guru_sample[] = [
            'simad_id_guru' => (int)$g['id_guru'],
            'total_gaji_pokok' => $gp,
            'total_tunjangan' => $tunj,
            'total_potongan' => $pot,
            'gaji_bersih' => $bersih,
            'gaji_pokok' => ['jumlah_bulanan' => $gp],
            'tunjangan' => [['nama_tunjangan' => 'Tunjangan Sertifikasi', 'jumlah_bulanan' => $tunj]],
            'potongan' => [['nama_potongan' => 'Potongan BPJS', 'jumlah_bulanan' => $pot]]
        ];
    }
    $sample = [
        'status' => 'success',
        'period_info' => [
            'jumlah_periode' => 1,
            'periode_mulai' => date('Y-m'),
            'periode_akhir' => date('Y-m'),
            'periode_aktif' => date('Y-m')
        ],
        'guru' => $guru_sample
    ];
    @file_put_contents($cache_file, json_encode($sample));
    $redirect = strtok($_SERVER['REQUEST_URI'], '?');
    header('Location: ' . $redirect);
    exit;
}

if ($api_url === '') {
    $api_error = 'Endpoint Sigaji belum dikonfigurasi. Isi base URL + API key di menu Integrasi > Pengaturan Endpoint.';
} elseif (function_exists('curl_init')) {
    require_once __DIR__ . '/../config/endpoint_registry.php';
    $gheaders = ['Accept: application/json', 'Content-Type: application/json'];
    if (isset($sig_key) && $sig_key !== '') $gheaders[] = 'X-API-KEY: ' . $sig_key;
    [$response, $http_code, $curl_error] = endpoint_fetch_url($api_url, $gheaders);

    if ($response === false) {
        $api_error = 'Gagal terhubung ke server penggajian: ' . $curl_error;
    } elseif ($http_code !== 200) {
        $api_error = 'Server penggajian merespon dengan kode: ' . $http_code . ' — ' . substr((string)$response, 0, 120);
    } else {
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $api_error = 'Gagal memproses data penggajian: ' . json_last_error_msg();
        } elseif (!isset($decoded['status']) || $decoded['status'] !== 'success') {
            $msg = $decoded['message'] ?? 'Server penggajian mengembalikan status gagal.';
            $api_error = $msg;
        } else {
            $salary_data = $decoded;
            @file_put_contents($cache_file, json_encode($decoded));
        }
    }
}

// Fallback to cache if API failed
if ($salary_data === null && is_file($cache_file)) {
    $cached = @json_decode(@file_get_contents($cache_file), true);
    if ($cached && isset($cached['status']) && $cached['status'] === 'success') {
        $salary_data = $cached;
        $api_error = null;
    }
}

// Dev fallback: generate sample data for current teacher so page renders
if ($salary_data === null) {
    $dev_data = [
        'status' => 'success',
        'period_info' => [
            'jumlah_periode' => 1,
            'periode_mulai' => date('Y-m'),
            'periode_akhir' => date('Y-m'),
            'periode_aktif' => date('Y-m')
        ],
        'guru' => [
            [
                'simad_id_guru' => (int)$guru_id,
                'total_gaji_pokok' => 0,
                'total_tunjangan' => 0,
                'total_potongan' => 0,
                'gaji_bersih' => 0,
                'gaji_pokok' => ['jumlah_bulanan' => 0],
                'tunjangan' => [],
                'potongan' => []
            ]
        ]
    ];
    $salary_data = $dev_data;
    $api_error = null;
    $is_dev_data = true;
}

$period_info = $salary_data['period_info'] ?? null;
$guru_list = $salary_data['guru'] ?? [];

$bulan_names = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret',
    '04' => 'April', '05' => 'Mei', '06' => 'Juni',
    '07' => 'Juli', '08' => 'Agustus', '09' => 'September',
    '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];

function periodeLabel($periode, $names) {
    if (preg_match('/^(\d{4})-(\d{2})$/', $periode, $m)) {
        return ($names[$m[2]] ?? $m[2]) . ' ' . $m[1];
    }
    return $periode;
}

$num_periods = 1;
$range_start = '';
$range_end = '';
$latest_period = '';

if ($period_info && isset($period_info['periode_mulai'], $period_info['periode_akhir'], $period_info['jumlah_periode'])) {
    $num_periods = (int)$period_info['jumlah_periode'];
    $range_start = $period_info['periode_mulai'];
    $range_end = $period_info['periode_akhir'];
    $latest_period = $period_info['periode_aktif'];
}

$period_label = '';
if ($range_start && $range_end) {
    if ($range_start === $range_end || $num_periods <= 1) {
        $period_label = periodeLabel($range_start, $bulan_names);
    } else {
        $period_label = periodeLabel($range_start, $bulan_names) . ' — ' . periodeLabel($range_end, $bulan_names);
    }
}

$teacher_data = null;
foreach ($guru_list as $g) {
    if ((int)$g['simad_id_guru'] === (int)$guru_id) {
        $teacher_data = $g;
        break;
    }
}

$total_gp = 0;
$total_tunj = 0;
$total_pot = 0;
$total_akhir = 0;
$gp_per_bulan = 0;
$sum_tunj_per_bulan = 0;
$sum_pot_per_bulan = 0;
$per_bulan_all = 0;
$tunjangan_items = [];
$potongan_items = [];

if ($teacher_data) {
    $total_gp = (float)$teacher_data['total_gaji_pokok'];
    $total_tunj = (float)$teacher_data['total_tunjangan'];
    $total_pot = (float)$teacher_data['total_potongan'];
    $total_akhir = (float)$teacher_data['gaji_bersih'];

    if (isset($teacher_data['gaji_pokok']['jumlah_bulanan'])) {
        $gp_per_bulan = (float)$teacher_data['gaji_pokok']['jumlah_bulanan'];
    }

    $sum_tunj_per_bulan = $num_periods > 0 ? $total_tunj / $num_periods : 0;
    $sum_pot_per_bulan = $num_periods > 0 ? $total_pot / $num_periods : 0;
    $per_bulan_all = $num_periods > 0 ? $total_akhir / $num_periods : 0;

    foreach ($teacher_data['tunjangan'] as $t) {
        $tunjangan_items[] = ['nama' => $t['nama_tunjangan'], 'per_bulan' => (float)$t['jumlah_bulanan']];
    }

    foreach ($teacher_data['potongan'] as $p) {
        $potongan_items[] = ['nama' => $p['nama_potongan'], 'per_bulan' => (float)$p['jumlah_bulanan']];
    }
}

function formatRupiah($amount) {
    return 'Rp ' . number_format((float)$amount, 0, ',', '.');
}


?>
<?php include '../templates/user_header.php'; ?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><i class="fas fa-money-bill-wave mr-2"></i>Data Gaji Guru</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Keuangan</div>
                <div class="breadcrumb-item">Data Gaji</div>
            </div>
        </div>

        <div class="section-body">
            <?php if ($api_error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle mr-2"></i><?php echo htmlspecialchars($api_error); ?>
                </div>
            <?php elseif ($is_dev_data): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-database mr-2"></i>Data penggajian tidak tersedia (API lokal terblokir). Gunakan data sampel untuk development.
                    <a href="?seed=1" class="alert-link">Klik di sini</a> untuk membuat data sampel dari database lokal.
                </div>
            <?php elseif (!$teacher_data): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle mr-2"></i>Belum ada data penggajian untuk Anda.
                </div>
            <?php else: ?>
                <!-- Hero Salary Card (E-Wallet Style) -->
                <div class="gaji-hero-card">
                    <div class="gaji-hero-top">
                        <div class="gaji-hero-user">
                            <div class="gaji-hero-avatar">
                                <i class="fas fa-wallet"></i>
                            </div>
                            <div>
                                <div class="gaji-hero-name"><?php echo htmlspecialchars($teacher['nama_guru'] ?? 'Guru'); ?></div>
                                <div class="gaji-hero-sub">
                                    <?php if (!empty($teacher['nip'])): ?>
                                        <span>NIP. <?php echo htmlspecialchars($teacher['nip']); ?></span>
                                    <?php elseif (!empty($teacher['nuptk'])): ?>
                                        <span>NUPTK. <?php echo htmlspecialchars($teacher['nuptk']); ?></span>
                                    <?php else: ?>
                                        <span>Tenaga Pendidik</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="gaji-hero-badge">
                            <i class="far fa-calendar-alt"></i>
                            <span><?= htmlspecialchars($period_label ?: 'Bulan Berjalan'); ?></span>
                        </div>
                    </div>

                    <div class="gaji-hero-balance-wrap">
                        <div class="gaji-hero-balance-lbl">Total Gaji Bersih Diterima</div>
                        <div class="gaji-hero-balance"><?php echo formatRupiah($total_akhir); ?></div>
                        <div class="gaji-hero-balance-sub">
                            <i class="fas fa-check-circle mr-1"></i><?php echo formatRupiah($per_bulan_all); ?> / bulan &bull; <?= $num_periods; ?> periode
                        </div>
                    </div>

                    <div class="gaji-hero-stats">
                        <div class="gaji-hero-stat-pill tone-pokok">
                            <div class="lbl">Gaji Pokok</div>
                            <div class="val"><?php echo formatRupiah($total_gp); ?></div>
                        </div>
                        <div class="gaji-hero-stat-pill tone-tunj">
                            <div class="lbl">+ Tunjangan</div>
                            <div class="val"><?php echo formatRupiah($total_tunj); ?></div>
                        </div>
                        <div class="gaji-hero-stat-pill tone-pot">
                            <div class="lbl">- Potongan</div>
                            <div class="val"><?php echo formatRupiah($total_pot); ?></div>
                        </div>
                    </div>
                </div>

                <!-- 4 KPI Stat Cards Grid -->
                <div class="gaji-stat-grid">
                    <div class="gaji-stat-card">
                        <div class="gaji-stat-header">
                            <span class="gaji-stat-title">Gaji Pokok</span>
                            <div class="gaji-stat-ico tone-blue"><i class="fas fa-coins"></i></div>
                        </div>
                        <div class="gaji-stat-val c-blue"><?php echo formatRupiah($total_gp); ?></div>
                        <div class="gaji-stat-sub"><?php echo formatRupiah($gp_per_bulan); ?>/bln &bull; <?= $num_periods ?> bln</div>
                    </div>

                    <div class="gaji-stat-card">
                        <div class="gaji-stat-header">
                            <span class="gaji-stat-title">Tunjangan</span>
                            <div class="gaji-stat-ico tone-green"><i class="fas fa-plus-circle"></i></div>
                        </div>
                        <div class="gaji-stat-val c-green"><?php echo formatRupiah($total_tunj); ?></div>
                        <div class="gaji-stat-sub"><?php echo formatRupiah($sum_tunj_per_bulan); ?>/bln &bull; <?= $num_periods ?> bln</div>
                    </div>

                    <div class="gaji-stat-card">
                        <div class="gaji-stat-header">
                            <span class="gaji-stat-title">Potongan</span>
                            <div class="gaji-stat-ico tone-red"><i class="fas fa-minus-circle"></i></div>
                        </div>
                        <div class="gaji-stat-val c-red"><?php echo formatRupiah($total_pot); ?></div>
                        <div class="gaji-stat-sub"><?php echo formatRupiah($sum_pot_per_bulan); ?>/bln &bull; <?= $num_periods ?> bln</div>
                    </div>

                    <div class="gaji-stat-card">
                        <div class="gaji-stat-header">
                            <span class="gaji-stat-title">Gaji Bersih</span>
                            <div class="gaji-stat-ico tone-teal"><i class="fas fa-receipt"></i></div>
                        </div>
                        <div class="gaji-stat-val c-teal"><?php echo formatRupiah($total_akhir); ?></div>
                        <div class="gaji-stat-sub"><?php echo formatRupiah($per_bulan_all); ?>/bln &bull; <?= $num_periods ?> bln</div>
                    </div>
                </div>

                <!-- Rincian Slip Gaji Ala Modern App List -->
                <div class="gaji-breakdown-card">
                    <div class="gaji-breakdown-header">
                        <h4 class="gaji-breakdown-title">
                            <i class="fas fa-file-invoice-dollar"></i>Rincian Komponen Gaji
                        </h4>
                        <span class="badge badge-light border text-muted font-weight-bold" style="border-radius: 8px;">
                            <?= htmlspecialchars($period_label); ?>
                        </span>
                    </div>

                    <!-- Kategori Gaji Pokok -->
                    <div class="gaji-section-tag">
                        <span><i class="fas fa-coins text-primary mr-1.5"></i> Gaji Pokok</span>
                        <span>Total (× <?= $num_periods ?> bln)</span>
                    </div>
                    <div class="gaji-item-row">
                        <div class="gaji-item-info">
                            <div class="gaji-item-bullet tone-blue"><i class="fas fa-money-bill-wave"></i></div>
                            <div>
                                <div class="gaji-item-name">Gaji Pokok Guru</div>
                                <div class="gaji-item-sub">Besaran pokok bulanan</div>
                            </div>
                        </div>
                        <div class="gaji-item-values">
                            <div class="gaji-item-total"><?php echo formatRupiah($total_gp); ?></div>
                            <div class="gaji-item-perbulan"><?php echo formatRupiah($gp_per_bulan); ?> / bln</div>
                        </div>
                    </div>

                    <!-- Kategori Tunjangan -->
                    <div class="gaji-section-tag">
                        <span><i class="fas fa-plus-circle text-success mr-1.5"></i> Tunjangan Tambahan</span>
                        <span class="text-success font-weight-bold"><?php echo formatRupiah($total_tunj); ?></span>
                    </div>
                    <?php if (!empty($tunjangan_items)): ?>
                        <?php $has_tunj_nonzero = false; foreach ($tunjangan_items as $ti): ?>
                            <?php if ($ti['per_bulan'] > 0): $has_tunj_nonzero = true; ?>
                            <div class="gaji-item-row">
                                <div class="gaji-item-info">
                                    <div class="gaji-item-bullet tone-green"><i class="fas fa-arrow-down"></i></div>
                                    <div>
                                        <div class="gaji-item-name"><?php echo htmlspecialchars($ti['nama']); ?></div>
                                        <div class="gaji-item-sub">Komponen penambah</div>
                                    </div>
                                </div>
                                <div class="gaji-item-values">
                                    <div class="gaji-item-total c-green">+ <?php echo formatRupiah($ti['per_bulan'] * $num_periods); ?></div>
                                    <div class="gaji-item-perbulan"><?php echo formatRupiah($ti['per_bulan']); ?> / bln</div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if (!$has_tunj_nonzero): ?>
                            <div class="p-3 text-center text-muted small"><em>Belum ada tunjangan yang tercatat.</em></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="p-3 text-center text-muted small"><em>Belum ada tunjangan yang tercatat.</em></div>
                    <?php endif; ?>

                    <!-- Kategori Potongan -->
                    <?php if (!empty($potongan_items) && array_sum(array_column($potongan_items, 'per_bulan')) > 0): ?>
                        <div class="gaji-section-tag">
                            <span><i class="fas fa-minus-circle text-danger mr-1.5"></i> Potongan Gaji</span>
                            <span class="text-danger font-weight-bold">- <?php echo formatRupiah($total_pot); ?></span>
                        </div>
                        <?php foreach ($potongan_items as $pi): ?>
                            <?php if ($pi['per_bulan'] > 0): ?>
                            <div class="gaji-item-row">
                                <div class="gaji-item-info">
                                    <div class="gaji-item-bullet tone-red"><i class="fas fa-arrow-up"></i></div>
                                    <div>
                                        <div class="gaji-item-name"><?php echo htmlspecialchars($pi['nama']); ?></div>
                                        <div class="gaji-item-sub">Iuran / potongan wajib</div>
                                    </div>
                                </div>
                                <div class="gaji-item-values">
                                    <div class="gaji-item-total c-red">- <?php echo formatRupiah($pi['per_bulan'] * $num_periods); ?></div>
                                    <div class="gaji-item-perbulan"><?php echo formatRupiah($pi['per_bulan']); ?> / bln</div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Total Bar Gaji Bersih -->
                    <div class="gaji-total-bar">
                        <div class="lbl">
                            <i class="fas fa-check-double"></i>
                            <span>Total Gaji Bersih Diterima</span>
                        </div>
                        <div class="val-wrap">
                            <div class="val"><?php echo formatRupiah($total_akhir); ?></div>
                            <div class="val-sub"><?php echo formatRupiah($per_bulan_all); ?> / bln</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php include '../templates/user_footer.php'; ?>
