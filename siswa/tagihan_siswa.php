<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['siswa'])) {
    redirect('../login.php');
}

function rupiahPembayaran($amount): string {
    return 'Rp ' . number_format((int)$amount, 0, ',', '.');
}

function normalizePembayaranItems($items): array {
    return is_array($items) ? array_values(array_filter($items, static function ($item): bool {
        return is_array($item) && (int)($item['sisa_tagihan'] ?? 0) > 0;
    })) : [];
}

function renderPembayaranDetailTagihan(array $row): string {
    $items = $row['item_belum_bayar'] ?? [];
    if (!is_array($items) || empty($items)) {
        return '<span class="text-muted small">Tidak ada rincian tunggakan.</span>';
    }

    $html = '<div class="sfin-detail-chips">';
    foreach ($items as $item) {
        $html .= '<span class="sfin-detail-chip"><i class="fas fa-clock mr-1"></i>' . htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    $html .= '</div>';
    return $html;
}

function renderPembayaranTagihanCards(array $items): void {
    ?>
    <div class="sfin-card-list">
        <?php foreach ($items as $row): ?>
            <?php 
            $sisa = (int)($row['sisa_tagihan'] ?? 0); 
            $total_nom = (int)($row['total_nominal'] ?? 0);
            $total_bay = isset($row['total_bayar']) ? (int)$row['total_bayar'] : ($total_nom - $sisa);
            ?>
            <div class="sfin-bill-card">
                <div class="sfin-bill-head">
                    <div>
                        <div class="sfin-bill-title"><?php echo htmlspecialchars((string)($row['nama_pembayaran'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="sfin-bill-type">
                            <i class="fas fa-tag"></i> <?php echo htmlspecialchars((string)($row['tipe_bayar'] ?? 'Tagihan'), ENT_QUOTES, 'UTF-8'); ?>
                            <span>•</span>
                            <span>TA <?php echo htmlspecialchars((string)($row['tahun_ajaran'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </div>
                    <div class="text-right">
                        <span class="badge badge-<?php echo $sisa > 0 ? 'danger' : 'success'; ?> px-2 py-1" style="font-size: 10px; font-weight: 800;">
                            <?php echo $sisa > 0 ? 'Belum Lunas' : 'Lunas'; ?>
                        </span>
                    </div>
                </div>

                <div class="sfin-bill-meta-grid">
                    <div class="sfin-bill-meta-item">
                        <div class="meta-lbl">Total Biaya</div>
                        <div class="meta-val"><?php echo rupiahPembayaran($total_nom); ?></div>
                    </div>
                    <div class="sfin-bill-meta-item">
                        <div class="meta-lbl">Sudah Dibayar</div>
                        <div class="meta-val text-success"><?php echo rupiahPembayaran($total_bay); ?></div>
                    </div>
                    <div class="sfin-bill-meta-item" style="grid-column: span 2; border-top: 1px dashed #e2e8f0; padding-top: 6px; margin-top: 2px;">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="meta-lbl font-weight-bold">Sisa Tagihan</div>
                            <div class="meta-val text-danger" style="font-size: 15px;"><?php echo rupiahPembayaran($sisa); ?></div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($row['item_belum_bayar'])): ?>
                    <div class="mt-2">
                        <div class="small text-muted font-weight-bold" style="font-size: 10.5px;">Rincian Tunggakan:</div>
                        <?php echo renderPembayaranDetailTagihan($row); ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}

function renderPembayaranTagihanTable(array $items): void {
    ?>
    <!-- Mobile Cards View -->
    <div class="d-md-none">
        <?php renderPembayaranTagihanCards($items); ?>
    </div>

    <!-- Desktop Table View -->
    <div class="table-responsive d-none d-md-block">
        <table class="table table-bordered table-hover text-dark mb-0">
            <thead class="bg-light">
                <tr>
                    <th width="56" class="text-center">No</th>
                    <th>Jenis Pembayaran</th>
                    <th width="110">Tipe</th>
                    <th width="130">Tahun Ajaran</th>
                    <th>Detail Tagihan</th>
                    <th width="160" class="text-right">Sisa Tagihan</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1; $total = 0; ?>
                <?php foreach ($items as $row): ?>
                    <?php $sisa = (int)($row['sisa_tagihan'] ?? 0); $total += $sisa; ?>
                    <tr>
                        <td class="text-center"><?php echo $no++; ?></td>
                        <td>
                            <div class="font-weight-bold"><?php echo htmlspecialchars((string)($row['nama_pembayaran'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
                            <small class="text-muted">
                                Nominal: <?php echo rupiahPembayaran($row['total_nominal'] ?? 0); ?>
                                <?php if (isset($row['total_bayar'])): ?>
                                    · Dibayar: <?php echo rupiahPembayaran($row['total_bayar']); ?>
                                <?php endif; ?>
                            </small>
                        </td>
                        <td><?php echo htmlspecialchars((string)($row['tipe_bayar'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['tahun_ajaran'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo renderPembayaranDetailTagihan($row); ?></td>
                        <td class="text-right font-weight-bold text-danger"><?php echo rupiahPembayaran($sisa); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="font-weight-bold bg-light">
                    <td colspan="5" class="text-right">Total</td>
                    <td class="text-right text-danger"><?php echo rupiahPembayaran($total); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php
}

$id_siswa = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT s.*, k.nama_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_siswa = ?");
$stmt->execute([$id_siswa]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die('Data siswa tidak ditemukan.');
}

$nisn = (string)($student['nisn'] ?? '');
$page_title = 'Tagihan Siswa';
$school_profile = getSchoolProfile($pdo);

// Auto-create tanggal_masuk column if not exists
try {
    $pdo->exec("ALTER TABLE tb_siswa ADD COLUMN IF NOT EXISTS tanggal_masuk DATE DEFAULT NULL AFTER tanggal_lahir");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE tb_siswa ADD COLUMN tanggal_masuk DATE DEFAULT NULL AFTER tanggal_lahir"); } catch (PDOException $e2) {}
}

// Auto-set tanggal_masuk from created_at for existing students if null
if (empty($student['tanggal_masuk']) && !empty($student['created_at'])) {
    $stmt_upd = $pdo->prepare("UPDATE tb_siswa SET tanggal_masuk = DATE(created_at) WHERE id_siswa = ? AND tanggal_masuk IS NULL");
    $stmt_upd->execute([$id_siswa]);
    $student['tanggal_masuk'] = date('Y-m-d', strtotime($student['created_at']));
}

$pembayaran_response = fetchPembayaranData($nisn, 'tagihan');
$api_status = $pembayaran_response['status'] ?? 'error';
$api_message = $pembayaran_response['message'] ?? 'Unknown error';

// Get student entry date to filter bills before enrollment
$tanggal_masuk = $student['tanggal_masuk'] ?? '';
$tahun_masuk = $tanggal_masuk ? (int)substr($tanggal_masuk, 0, 4) : 0;

$api_student = is_array($pembayaran_response['student'] ?? null) ? $pembayaran_response['student'] : [];
$billing_data = normalizePembayaranItems($pembayaran_response['billing'] ?? ($pembayaran_response['data'] ?? []));
$old_arrears = is_array($pembayaran_response['tunggakan_tahun_ajaran_lama'] ?? null) ? $pembayaran_response['tunggakan_tahun_ajaran_lama'] : [];
$summary = is_array($pembayaran_response['summary'] ?? null) ? $pembayaran_response['summary'] : [];
$tahun_ajaran_aktif = (string)($pembayaran_response['tahun_ajaran'] ?? $pembayaran_response['tahun_ajaran_aktif'] ?? $school_profile['tahun_ajaran'] ?? '-');

// Helper: extract start year from "YYYY/YYYY" format
$ta_start_year = function($ta) { return $ta && preg_match('/^(\d{4})\//', $ta, $m) ? (int)$m[1] : 0; };

// Filter billing: exclude items whose tahun_ajaran is before student's entry year
if ($tahun_masuk > 0) {
    $billing_data = array_values(array_filter($billing_data, function($item) use ($ta_start_year, $tahun_masuk) {
        return $ta_start_year($item['tahun_ajaran'] ?? '') >= $tahun_masuk;
    }));
}

$old_arrears_normalized = [];
$computed_old_total = 0;
foreach ($old_arrears as $tahun => $group) {
    if (!is_array($group)) continue;
    $ta = (string)($group['tahun_ajaran'] ?? $tahun);
    // Skip arrears from before student's entry year
    if ($tahun_masuk > 0 && $ta_start_year($ta) < $tahun_masuk) continue;
    $items = normalizePembayaranItems($group['items'] ?? []);
    $total = (int)($group['total_tunggakan'] ?? array_sum(array_map(static fn($item) => (int)($item['sisa_tagihan'] ?? 0), $items)));
    if ($total <= 0 && empty($items)) continue;
    $computed_old_total += $total;
    $old_arrears_normalized[$ta] = ['total' => $total, 'items' => $items];
}

$computed_active_total = array_sum(array_map(static fn($item) => (int)($item['sisa_tagihan'] ?? 0), $billing_data));
$total_active = (int)($summary['total_tunggakan_aktif'] ?? $computed_active_total);
$total_old = (int)($summary['total_tunggakan_tahun_lama'] ?? $computed_old_total);
$total_all = (int)($summary['total_tunggakan'] ?? ($total_active + $total_old));
// Status: tagihan aktif > 0 = "Ada Tagihan", tunggakan lama > 0 = "Ada Tunggakan", lainnya "Lunas"
$status_text = 'Lunas';
$status_icon = 'check-circle';
$status_color = 'success';
if ($total_active > 0) {
    $status_text = 'Ada Tagihan';
    $status_icon = 'exclamation-circle';
    $status_color = 'danger';
} elseif ($total_old > 0) {
    $status_text = 'Ada Tunggakan';
    $status_icon = 'clock';
    $status_color = 'warning';
}
$tahun_tunggakan = $summary['tahun_ajaran_tunggakan'] ?? array_keys($old_arrears_normalized);
$tahun_tunggakan = is_array($tahun_tunggakan) ? $tahun_tunggakan : [];

include '../templates/header.php';
include_once '../templates/sidebar.php';
?>

<link rel="stylesheet" href="../assets/css/siswa-keuangan-mobile.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/siswa-keuangan-mobile.css') ?>">

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Tagihan Siswa</h1>
        </div>

        <div class="section-body">
            <?php if ($api_status !== 'success'): ?>
                <div class="alert alert-warning alert-has-icon mb-3" style="border-radius: 14px;">
                    <div class="alert-icon"><i class="fas fa-plug"></i></div>
                    <div class="alert-body">
                        <div class="alert-title font-weight-bold">Koneksi Server Pembayaran</div>
                        Sistem menampilkan data tagihan lokal. Data realtime belum tersambung.<br>
                        <small class="text-dark"><strong>Catatan:</strong> <?php echo htmlspecialchars((string)$api_message, ENT_QUOTES, 'UTF-8'); ?></small>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Hero Card Tagihan Siswa Ala Mobile Apps -->
            <div class="sfin-hero-card <?php echo $total_all > 0 ? 'theme-warning' : 'theme-success'; ?>">
                <div class="sfin-hero-top">
                    <div class="sfin-student-info">
                        <div class="sfin-student-name">
                            <i class="fas fa-receipt mr-1"></i> <?php echo htmlspecialchars((string)($api_student['nama'] ?? $student['nama_siswa'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="sfin-student-sub">
                            <span>NISN: <?php echo htmlspecialchars((string)($api_student['nisn'] ?? $student['nisn'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
                            <span>•</span>
                            <span>Kelas <?php echo htmlspecialchars((string)($api_student['kelas'] ?? $student['nama_kelas'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </div>
                </div>

                <div class="mt-2">
                    <div class="sfin-hero-main-title">Total Seluruh Tunggakan</div>
                    <div class="sfin-hero-amount <?php echo $total_all > 0 ? 'gold' : ''; ?>">
                        <?php echo rupiahPembayaran($total_all); ?>
                    </div>
                </div>

                <div class="sfin-stats-grid cols-3">
                    <div class="sfin-stat-box">
                        <div class="lbl">Tahun Aktif</div>
                        <div class="val"><?php echo rupiahPembayaran($total_active); ?></div>
                    </div>
                    <div class="sfin-stat-box">
                        <div class="lbl">Tahun Lama</div>
                        <div class="val"><?php echo rupiahPembayaran($total_old); ?></div>
                    </div>
                    <div class="sfin-stat-box">
                        <div class="lbl">Status</div>
                        <div class="val">
                            <i class="fas fa-<?= $status_icon ?> mr-1"></i> <?= $status_text ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Konten Tagihan Aktif -->
            <div class="sfin-section-head">
                <div class="sfin-section-title">
                    <i class="fas fa-calendar-alt text-primary"></i> Tagihan Tahun Ajaran Aktif
                </div>
                <div class="sfin-section-count"><?php echo htmlspecialchars($tahun_ajaran_aktif, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>

            <?php if (!empty($billing_data)): ?>
                <?php renderPembayaranTagihanTable($billing_data); ?>
            <?php else: ?>
                <div class="card border-0 shadow-sm text-center py-4 mb-4" style="border-radius: 14px;">
                    <div class="text-success font-weight-bold">
                        <i class="fas fa-check-circle fa-2x mb-2 d-block"></i>
                        Tidak ada tagihan aktif yang belum dibayar.
                    </div>
                </div>
            <?php endif; ?>

            <!-- Konten Tunggakan Tahun Lama -->
            <div class="sfin-section-head mt-4">
                <div class="sfin-section-title">
                    <i class="fas fa-history text-warning"></i> Tunggakan Tahun Ajaran Lama
                </div>
                <div class="sfin-section-count"><?php echo count($old_arrears_normalized); ?> Periode</div>
            </div>

            <?php if (!empty($old_arrears_normalized)): ?>
                <?php foreach ($old_arrears_normalized as $tahun => $group): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center bg-white border px-3 py-2" style="border-radius: 12px 12px 0 0; border-bottom: 2px solid #e2e8f0 !important;">
                            <span class="font-weight-bold text-dark"><i class="fas fa-folder-open text-warning mr-1"></i> TA <?php echo htmlspecialchars($tahun, ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="text-danger font-weight-bold"><?php echo rupiahPembayaran($group['total']); ?></span>
                        </div>
                        <div class="bg-white border border-top-0 p-2" style="border-radius: 0 0 12px 12px;">
                            <?php if (!empty($group['items'])): ?>
                                <?php renderPembayaranTagihanTable($group['items']); ?>
                            <?php else: ?>
                                <div class="text-muted small p-2">Rincian item tagihan tidak dikirim oleh Pembayaran.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="card border-0 shadow-sm text-center py-4 mb-4" style="border-radius: 14px;">
                    <div class="text-muted small">
                        <i class="fas fa-clipboard-check fa-2x mb-2 d-block text-success"></i>
                        Tidak ada tunggakan tahun ajaran lama.
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
