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

// Data penggajian lokal (modul gaji SIMAD) - tanpa API/cache terpisah.
$salary_data = null;
$api_error = null;
$is_dev_data = false;

try {
    $settings = $pdo->query("SELECT * FROM settings WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $periode_aktif = trim((string)($settings['periode_aktif'] ?? '')) ?: date('Y-m');
    $jumlah_periode = max(1, (int)($settings['jumlah_periode'] ?? 1));
    $periode_mulai = trim((string)($settings['periode_mulai'] ?? ''));
    $periode_akhir = trim((string)($settings['periode_akhir'] ?? ''));

    $periods = [];
    if ($jumlah_periode > 1 && $periode_mulai !== '' && $periode_akhir !== '') {
        $start = new DateTime($periode_mulai . '-01');
        $end = new DateTime($periode_akhir . '-01');
        while ($start <= $end) {
            $periods[] = $start->format('Y-m');
            $start->modify('+1 month');
        }
    }
    if (!$periods) {
        $periods[] = $periode_aktif;
    }

    $guruRowStmt = $pdo->prepare("SELECT * FROM guru WHERE simad_id_guru = ? LIMIT 1");
    $guruRowStmt->execute([(int)$guru_id]);
    $gaji_guru = $guruRowStmt->fetch(PDO::FETCH_ASSOC);
    if (!$gaji_guru) {
        $guruRowStmt = $pdo->prepare("SELECT * FROM guru WHERE nip = ? LIMIT 1");
        $guruRowStmt->execute([(string)($teacher['nuptk'] ?? '')]);
        $gaji_guru = $guruRowStmt->fetch(PDO::FETCH_ASSOC);
    }

    $entry = [
        'guru_id' => $gaji_guru ? (int)$gaji_guru['id'] : 0,
        'simad_id_guru' => (int)$guru_id,
        'nama_lengkap' => (string)($teacher['nama_guru'] ?? ''),
        'nip' => (string)($teacher['nuptk'] ?? ''),
        'total_gaji_pokok' => 0.0,
        'total_tunjangan' => 0.0,
        'total_potongan' => 0.0,
        'gaji_bersih' => 0.0,
        'gaji_pokok' => ['jumlah_bulanan' => 0.0],
        'tunjangan' => [],
        'potongan' => [],
    ];

    if ($gaji_guru) {
        $placeholders = implode(',', array_fill(0, count($periods), '?'));
        $params = array_merge([(int)$gaji_guru['id']], $periods);
        $stmt = $pdo->prepare("SELECT * FROM legger_gaji WHERE guru_id = ? AND periode IN ($placeholders) ORDER BY periode ASC");
        $stmt->execute($params);
        $leggers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($leggers) {
            $detailStmt = $pdo->prepare("SELECT jenis, nama_item, jumlah FROM legger_detail WHERE legger_id = ? ORDER BY id ASC");
            foreach ($leggers as $lg) {
                $entry['total_gaji_pokok'] += (float)($lg['gaji_pokok'] ?? 0);
                $entry['total_tunjangan'] += (float)($lg['total_tunjangan'] ?? 0);
                $entry['total_potongan'] += (float)($lg['total_potongan'] ?? 0);
                $entry['gaji_bersih'] += (float)($lg['gaji_bersih'] ?? 0);
                $detailStmt->execute([(int)$lg['id']]);
                foreach ($detailStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
                    $nominal = (float)($d['jumlah'] ?? 0);
                    if (($d['jenis'] ?? '') === 'tunjangan') {
                        $entry['tunjangan'][] = ['nama_tunjangan' => (string)($d['nama_item'] ?? ''), 'jumlah_bulanan' => $nominal];
                    } elseif (($d['jenis'] ?? '') === 'potongan') {
                        $entry['potongan'][] = ['nama_potongan' => (string)($d['nama_item'] ?? ''), 'jumlah_bulanan' => $nominal];
                    }
                }
            }
            $entry['gaji_pokok']['jumlah_bulanan'] = $jumlah_periode > 0 ? $entry['total_gaji_pokok'] / $jumlah_periode : $entry['total_gaji_pokok'];
        } else {
            // Belum ada legger: hitung dari data master periode aktif.
            $periode = $periods[0];
            $stmt = $pdo->prepare("SELECT jumlah FROM gaji_pokok WHERE guru_id = ? LIMIT 1");
            $stmt->execute([(int)$gaji_guru['id']]);
            $pokok = (float)($stmt->fetchColumn() ?: 0);

            $stmt = $pdo->prepare("SELECT t.nama_tunjangan, td.jumlah FROM tunjangan_detail td JOIN tunjangan t ON t.id = td.tunjangan_id WHERE td.guru_id = ? AND td.periode = ? AND t.aktif = 1");
            $stmt->execute([(int)$gaji_guru['id'], $periode]);
            $tunjDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("SELECT p.nama_potongan, pd.jumlah FROM potongan_detail pd JOIN potongan p ON p.id = pd.potongan_id WHERE pd.guru_id = ? AND pd.periode = ? AND p.aktif = 1");
            $stmt->execute([(int)$gaji_guru['id'], $periode]);
            $potDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $entry['total_gaji_pokok'] = $pokok;
            $entry['gaji_pokok']['jumlah_bulanan'] = $pokok;
            foreach ($tunjDetails as $t) {
                $entry['total_tunjangan'] += (float)($t['jumlah'] ?? 0);
                $entry['tunjangan'][] = ['nama_tunjangan' => (string)($t['nama_tunjangan'] ?? ''), 'jumlah_bulanan' => (float)($t['jumlah'] ?? 0)];
            }
            foreach ($potDetails as $pt) {
                $entry['total_potongan'] += (float)($pt['jumlah'] ?? 0);
                $entry['potongan'][] = ['nama_potongan' => (string)($pt['nama_potongan'] ?? ''), 'jumlah_bulanan' => (float)($pt['jumlah'] ?? 0)];
            }
            $entry['gaji_bersih'] = $entry['total_gaji_pokok'] + $entry['total_tunjangan'] - $entry['total_potongan'];
        }
    }

    $salary_data = [
        'status' => 'success',
        'period_info' => [
            'periode_aktif' => $periode_aktif,
            'jumlah_periode' => $jumlah_periode,
            'periode_mulai' => $jumlah_periode > 1 ? $periode_mulai : $periode_aktif,
            'periode_akhir' => $jumlah_periode > 1 ? $periode_akhir : $periode_aktif,
        ],
        'guru' => [$entry],
    ];
} catch (Throwable $e) {
    $api_error = 'Gagal memuat data penggajian: ' . $e->getMessage();
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
