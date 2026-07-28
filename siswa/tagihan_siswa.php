<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['siswa'])) {
    redirect('../login.php');
}

function rupiahSibayar($amount): string {
    return 'Rp ' . number_format((int)$amount, 0, ',', '.');
}

function normalizeSibayarItems($items): array {
    return is_array($items) ? array_values(array_filter($items, static function ($item): bool {
        return is_array($item) && (int)($item['sisa_tagihan'] ?? 0) > 0;
    })) : [];
}

function renderSibayarDetailTagihan(array $row): string {
    $items = $row['item_belum_bayar'] ?? [];
    if (!is_array($items) || empty($items)) {
        return '<span class="text-muted">Tidak ada rincian tunggakan.</span>';
    }

    $html = '<div class="d-flex flex-wrap" style="gap: 6px;">';
    foreach ($items as $item) {
        $html .= '<span class="badge badge-warning px-2 py-1">' . htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    $html .= '</div>';
    return $html;
}

function renderSibayarTagihanTable(array $items): void {
    ?>
    <div class="table-responsive">
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
                                Nominal: <?php echo rupiahSibayar($row['total_nominal'] ?? 0); ?>
                                <?php if (isset($row['total_bayar'])): ?>
                                    · Dibayar: <?php echo rupiahSibayar($row['total_bayar']); ?>
                                <?php endif; ?>
                            </small>
                        </td>
                        <td><?php echo htmlspecialchars((string)($row['tipe_bayar'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($row['tahun_ajaran'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo renderSibayarDetailTagihan($row); ?></td>
                        <td class="text-right font-weight-bold text-danger"><?php echo rupiahSibayar($sisa); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="font-weight-bold bg-light">
                    <td colspan="5" class="text-right">Total</td>
                    <td class="text-right text-danger"><?php echo rupiahSibayar($total); ?></td>
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

$sibayar_response = fetchSibayarData($nisn, 'tagihan');
$api_status = $sibayar_response['status'] ?? 'error';
$api_message = $sibayar_response['message'] ?? 'Unknown error';

// Get student entry date to filter bills before enrollment
$tanggal_masuk = $student['tanggal_masuk'] ?? '';
$tahun_masuk = $tanggal_masuk ? (int)substr($tanggal_masuk, 0, 4) : 0;

$api_student = is_array($sibayar_response['student'] ?? null) ? $sibayar_response['student'] : [];
$billing_data = normalizeSibayarItems($sibayar_response['billing'] ?? ($sibayar_response['data'] ?? []));
$old_arrears = is_array($sibayar_response['tunggakan_tahun_ajaran_lama'] ?? null) ? $sibayar_response['tunggakan_tahun_ajaran_lama'] : [];
$summary = is_array($sibayar_response['summary'] ?? null) ? $sibayar_response['summary'] : [];
$tahun_ajaran_aktif = (string)($sibayar_response['tahun_ajaran'] ?? $sibayar_response['tahun_ajaran_aktif'] ?? $school_profile['tahun_ajaran'] ?? '-');

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
    $items = normalizeSibayarItems($group['items'] ?? []);
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

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Tagihan Siswa</h1>
        </div>

        <div class="section-body">
            <?php if ($api_status !== 'success'): ?>
                <div class="alert alert-warning alert-has-icon">
                    <div class="alert-icon"><i class="fas fa-plug"></i></div>
                    <div class="alert-body">
                        <div class="alert-title">Koneksi Terputus</div>
                        Sistem tidak dapat mengambil data tagihan terbaru dari server Sibayar.<br>
                        <small class="text-dark"><strong>Detail Teknis:</strong> <?php echo htmlspecialchars((string)$api_message, ENT_QUOTES, 'UTF-8'); ?></small>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start mb-4">
                        <div class="mb-3 mb-md-0">
                            <h5 class="font-weight-bold mb-2"><?php echo htmlspecialchars((string)($api_student['nama'] ?? $student['nama_siswa'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></h5>
                            <div class="text-muted">NISN: <strong class="text-dark"><?php echo htmlspecialchars((string)($api_student['nisn'] ?? $student['nisn'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div class="text-muted">Kelas: <strong class="text-dark"><?php echo htmlspecialchars((string)($api_student['kelas'] ?? $student['nama_kelas'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div class="text-muted">Tahun ajaran aktif Sibayar: <strong class="text-dark"><?php echo htmlspecialchars($tahun_ajaran_aktif, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        </div>
                        <span class="badge badge-<?php echo $api_status === 'success' ? 'success' : 'warning'; ?> px-3 py-2">
                            <i class="fas fa-<?php echo $api_status === 'success' ? 'sync-alt' : 'exclamation-triangle'; ?> mr-1"></i>
                            <?php echo $api_status === 'success' ? 'Data realtime Sibayar' : 'Data belum tersambung'; ?>
                        </span>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-3 col-6 mb-3">
                            <div class="border rounded bg-light p-3 h-100">
                                <div class="small text-muted">Total Tunggakan</div>
                                <div class="h5 mb-0 text-danger font-weight-bold"><?php echo rupiahSibayar($total_all); ?></div>
                            </div>
                        </div>
                        <div class="col-md-3 col-6 mb-3">
                            <div class="border rounded p-3 h-100">
                                <div class="small text-muted">Tahun Ajaran Aktif</div>
                                <div class="h6 mb-0 text-dark"><?php echo htmlspecialchars($tahun_ajaran_aktif, ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="font-weight-bold text-danger"><?php echo rupiahSibayar($total_active); ?></div>
                            </div>
                        </div>
                        <div class="col-md-3 col-6 mb-3">
                            <div class="border rounded p-3 h-100">
                                <div class="small text-muted">Tahun Ajaran Lama</div>
                                <div class="h6 mb-0 text-dark"><?php echo empty($tahun_tunggakan) ? '-' : htmlspecialchars(implode(', ', $tahun_tunggakan), ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="font-weight-bold text-danger"><?php echo rupiahSibayar($total_old); ?></div>
                            </div>
                        </div>
                        <div class="col-md-3 col-6 mb-3">
                            <div class="border rounded p-3 h-100">
                                <div class="small text-muted">Status</div>
                                <span class="badge badge-<?= $status_color ?> mt-1"><i class="fas fa-<?= $status_icon ?> mr-1"></i> <?= $status_text ?></span>
                            </div>
                        </div>
                    </div>

                    <?php if ($api_status === 'success' && $status_text === 'Lunas'): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle mr-2"></i> Tidak ada tagihan yang belum dibayar.
                        </div>
                    <?php endif; ?>

                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="font-weight-bold mb-0">Tagihan Tahun Ajaran Aktif</h6>
                            <span class="badge badge-light border"><?php echo htmlspecialchars($tahun_ajaran_aktif, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <?php if (!empty($billing_data)): ?>
                            <?php renderSibayarTagihanTable($billing_data); ?>
                        <?php else: ?>
                            <div class="alert alert-light border mb-0">Tidak ada tagihan aktif yang belum dibayar.</div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="font-weight-bold mb-0">Tunggakan Tahun Ajaran Lama</h6>
                            <span class="badge badge-warning border">Data dari Sibayar</span>
                        </div>
                        <?php if (!empty($old_arrears_normalized)): ?>
                            <?php foreach ($old_arrears_normalized as $tahun => $group): ?>
                                <div class="border rounded mb-3">
                                    <div class="bg-light px-3 py-2 d-flex justify-content-between align-items-center">
                                        <strong><?php echo htmlspecialchars($tahun, ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <span class="text-danger font-weight-bold"><?php echo rupiahSibayar($group['total']); ?></span>
                                    </div>
                                    <div class="p-3">
                                        <?php if (!empty($group['items'])): ?>
                                            <?php renderSibayarTagihanTable($group['items']); ?>
                                        <?php else: ?>
                                            <div class="alert alert-warning mb-0">Ada tunggakan tahun ajaran ini, tetapi rincian item tidak dikirim oleh Sibayar.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="alert alert-light border mb-0">Tidak ada tunggakan tahun ajaran lama.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
