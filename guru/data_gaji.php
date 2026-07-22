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

$api_url = 'https://sigaji.misultanfattah.sch.id/api/v1/salary.php?api_key=SIS_CENTRAL_HUB_SECRET_2026';

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

if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $api_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'SIMadrasah/1.0',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json'
        ]
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        $api_error = 'Gagal terhubung ke server penggajian: ' . $curl_error;
    } elseif ($http_code !== 200) {
        $api_error = 'Server penggajian merespon dengan kode: ' . $http_code;
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
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body py-3">
                                <div class="d-flex align-items-center justify-content-between flex-wrap" style="gap: 10px;">
                                    <div>
                                        <h5 class="mb-1"><i class="fas fa-user-tie mr-2"></i><?php echo htmlspecialchars($teacher['nama_guru'] ?? 'Guru'); ?></h5>
                                        <?php if (!empty($teacher['nip'])): ?>
                                            <small class="text-muted">NIP. <?php echo htmlspecialchars($teacher['nip']); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($teacher['nuptk'])): ?>
                                            <small class="text-muted ml-3">NUPTK. <?php echo htmlspecialchars($teacher['nuptk']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-right">
                                        <span class="badge badge-info p-2"><i class="fas fa-calendar-alt mr-1"></i>Periode: <strong><?php echo htmlspecialchars($period_label ?: 'Juli 2026'); ?></strong></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12 col-md-3">
                        <div class="card h-100">
                            <div class="card-body text-center d-flex flex-column justify-content-center" style="padding:0.6rem 0.5rem">
                                <div class="h5 font-weight-bold mb-1"><i class="fas fa-coins mr-1"></i>Gaji Pokok</div>
                                <div class="h2 font-weight-bold text-primary mb-0"><?php echo formatRupiah($total_gp); ?></div>
                                <div class="text-muted" style="font-size:0.9rem"><?php echo formatRupiah($gp_per_bulan); ?>/bln × <?php echo $num_periods; ?> bln</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="card h-100">
                            <div class="card-body text-center d-flex flex-column justify-content-center" style="padding:0.6rem 0.5rem">
                                <div class="h5 font-weight-bold mb-1"><i class="fas fa-plus-circle mr-1"></i>Tunjangan</div>
                                <div class="h2 font-weight-bold text-success mb-0"><?php echo formatRupiah($total_tunj); ?></div>
                                <div class="text-muted" style="font-size:0.9rem"><?php echo formatRupiah($sum_tunj_per_bulan); ?>/bln × <?php echo $num_periods; ?> bln</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="card h-100">
                            <div class="card-body text-center d-flex flex-column justify-content-center" style="padding:0.6rem 0.5rem">
                                <div class="h5 font-weight-bold mb-1"><i class="fas fa-minus-circle mr-1"></i>Potongan</div>
                                <div class="h2 font-weight-bold text-danger mb-0"><?php echo formatRupiah($total_pot); ?></div>
                                <div class="text-muted" style="font-size:0.9rem"><?php echo formatRupiah($sum_pot_per_bulan); ?>/bln × <?php echo $num_periods; ?> bln</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="card h-100">
                            <div class="card-body text-center d-flex flex-column justify-content-center" style="padding:0.6rem 0.5rem">
                                <div class="h5 font-weight-bold mb-1"><i class="fas fa-file-invoice mr-1"></i>Gaji Bersih</div>
                                <div class="h2 font-weight-bold text-dark mb-0"><?php echo formatRupiah($total_akhir); ?></div>
                                <div class="text-muted" style="font-size:0.9rem"><?php echo formatRupiah($per_bulan_all); ?>/bln × <?php echo $num_periods; ?> bln</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mt-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h4><i class="fas fa-list mr-2"></i>Rincian Gaji</h4>
                                <div class="card-header-action text-muted small">
                                    <?php echo htmlspecialchars($period_label); ?>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-striped mb-0">
                                        <thead>
                                            <tr>
                                                <th>Keterangan</th>
                                                <th class="text-right">Per Bulan</th>
                                                <th class="text-right">Total (× <?php echo $num_periods; ?>)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr class="bg-primary text-white">
                                                <td><strong>Gaji Pokok</strong></td>
                                                <td class="text-right"><?php echo formatRupiah($gp_per_bulan); ?></td>
                                                <td class="text-right"><?php echo formatRupiah($total_gp); ?></td>
                                            </tr>
                                            <tr class="bg-light">
                                                <td colspan="3"><strong class="text-success"><i class="fas fa-plus-circle mr-1"></i>Tunjangan</strong></td>
                                            </tr>
                                            <?php if (!empty($tunjangan_items)): ?>
                                                <?php $has_tunj_nonzero = false; foreach ($tunjangan_items as $ti): ?>
                                                    <?php if ($ti['per_bulan'] > 0): $has_tunj_nonzero = true; ?>
                                                        <tr>
                                                            <td class="pl-4"><?php echo htmlspecialchars($ti['nama']); ?></td>
                                                            <td class="text-right text-success"><?php echo formatRupiah($ti['per_bulan']); ?></td>
                                                            <td class="text-right text-success"><?php echo formatRupiah($ti['per_bulan'] * $num_periods); ?></td>
                                                        </tr>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                                <?php if (!$has_tunj_nonzero): ?>
                                                    <tr><td class="pl-4 text-muted" colspan="3"><em>Belum ada tunjangan</em></td></tr>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <tr><td class="pl-4 text-muted" colspan="3"><em>Belum ada tunjangan</em></td></tr>
                                            <?php endif; ?>
                                            <?php if (!empty($potongan_items) && array_sum(array_column($potongan_items, 'per_bulan')) > 0): ?>
                                                <tr class="bg-light">
                                                    <td colspan="3"><strong class="text-danger"><i class="fas fa-minus-circle mr-1"></i>Potongan</strong></td>
                                                </tr>
                                                <?php foreach ($potongan_items as $pi): ?>
                                                    <?php if ($pi['per_bulan'] > 0): ?>
                                                        <tr>
                                                            <td class="pl-4"><?php echo htmlspecialchars($pi['nama']); ?></td>
                                                            <td class="text-right text-danger"><?php echo formatRupiah($pi['per_bulan']); ?></td>
                                                            <td class="text-right text-danger"><?php echo formatRupiah($pi['per_bulan'] * $num_periods); ?></td>
                                                        </tr>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            <tr class="bg-dark text-white font-weight-bold">
                                                <td><i class="fas fa-file-invoice mr-1"></i>Total Gaji Bersih</td>
                                                <td class="text-right"><?php echo formatRupiah($per_bulan_all); ?></td>
                                                <td class="text-right"><?php echo formatRupiah($total_akhir); ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php include '../templates/user_footer.php'; ?>
