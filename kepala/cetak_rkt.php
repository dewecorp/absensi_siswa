<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/rkt_helper.php';

if (session_status() == PHP_SESSION_NONE) session_start();
if (!isAuthorized(['admin', 'kepala_madrasah'])) redirect('../login.php');

rktEnsureSchema($pdo);

$docId = (int)($_GET['doc'] ?? 0);
$tahun = (int)($_GET['tahun'] ?? 0);
$doc = $docId > 0 ? rktGetDocument($pdo, $docId) : ($tahun > 0 ? rktGetDocument($pdo, 0, $tahun) : rktGetDocument($pdo));
if (!$doc) {
    http_response_code(404);
    echo 'Dokumen RKT tidak ditemukan.';
    exit;
}

$fields = rkjmFields($pdo);
$f_field = (int)($_GET['bidang'] ?? 0);
$statusOpts = rktProgramStatusOptions();
$docStatusOpts = rktDocStatusOptions();
$school = getSchoolProfile($pdo);
$csrf = appCsrfToken();

$where = ['p.document_id = ?'];
$params = [(int)$doc['id']];
if ($f_field > 0) {
    $where[] = 'p.field_id = ?';
    $params[] = $f_field;
}
$stmt = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM rkt_activities a WHERE a.program_id = p.id) AS activity_count
    FROM rkt_programs p WHERE " . implode(' AND ', $where) . "
    ORDER BY p.bidang_nama ASC, p.urutan ASC, p.id ASC");
$stmt->execute($params);
$programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
$programIds = array_map(static fn($p) => (int)$p['id'], $programs);
$activities = $programIds ? rktActivitiesByProgram($pdo, $programIds) : [];

$monitoring = [];
$programMonev = [];
$activityMonev = [];
if ($programIds) {
    $in = implode(',', array_fill(0, count($programIds), '?'));
    $ms = $pdo->prepare("SELECT m.*, a.nama_kegiatan FROM rkt_monitoring m
        LEFT JOIN rkt_activities a ON a.id = m.activity_id
        WHERE m.program_id IN ($in) ORDER BY m.tanggal_pelaksanaan ASC, m.id ASC");
    $ms->execute($programIds);
    foreach ($ms->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $monitoring[(int)$m['program_id']][] = $m;
        if (empty($m['activity_id'])) $programMonev[(int)$m['program_id']][] = $m;
        else $activityMonev[(int)$m['activity_id']][] = $m;
    }
}

$summary = rktSummary($pdo, (int)$doc['id']);
$rkjm = !empty($doc['rkjm_document_id']) ? rkjmGetDocument($pdo, (int)$doc['rkjm_document_id']) : null;
$kota = trim((string)($school['kota'] ?? ($school['tempat_jadwal'] ?? '')));
$nama_kepala = trim((string)($doc['nama_kepala'] ?? ''));
if ($nama_kepala === '') $nama_kepala = trim((string)($school['nama_kepala'] ?? ''));
if ($nama_kepala === '') $nama_kepala = trim((string)($school['kepala_madrasah'] ?? ''));
$nip_kepala = trim((string)($school['nip_kepala'] ?? ''));

$total_program_anggaran = 0.0;
$total_activity_anggaran = 0.0;
$total_realisasi = 0.0;
$by_field = [];
foreach ($programs as $p) {
    $total_program_anggaran += (float)$p['estimasi_anggaran'];
    $bn = $p['bidang_nama'] ?: 'Lainnya';
    if (!isset($by_field[$bn])) $by_field[$bn] = ['count' => 0, 'anggaran' => 0.0];
    $by_field[$bn]['count']++;
    $by_field[$bn]['anggaran'] += (float)$p['estimasi_anggaran'];
    foreach (($activities[(int)$p['id']] ?? []) as $a) {
        $total_activity_anggaran += (float)$a['estimasi_biaya'];
    }
    foreach (($programMonev[(int)$p['id']] ?? []) as $m) {
        $total_realisasi += (float)($m['realisasi_biaya'] ?? 0);
    }
}

function cetak_rkt_nl(?string $text): string
{
    $text = trim((string)$text);
    return $text === '' ? '-' : nl2br(htmlspecialchars($text));
}

function cetak_rkt_status(string $status, array $opts): string
{
    return htmlspecialchars($opts[$status] ?? $status);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>RKT <?= (int)$doc['tahun'] ?> - <?= htmlspecialchars($doc['nama_madrasah'] ?: ($school['nama_madrasah'] ?? 'Madrasah')) ?></title>
<style>
@page { size: A4; margin: 18mm 16mm; }
* { box-sizing: border-box; }
body { font-family: "Times New Roman", Georgia, serif; color:#111; font-size:11.5pt; line-height:1.5; margin:0; background:#f5f6f8; }
.sheet { background:#fff; max-width:210mm; margin:0 auto; padding:16mm; box-shadow:0 0 10px rgba(0,0,0,.08); }
h1,h2,h3,h4 { font-family: "Times New Roman", Georgia, serif; margin:0 0 8px; }
h1 { font-size:20pt; text-align:center; }
h2 { font-size:14pt; border-bottom:2px solid #222; padding-bottom:4px; margin:22px 0 10px; }
h3 { font-size:12pt; margin:16px 0 6px; }
p { margin:0 0 8px; text-align:justify; }
table { width:100%; border-collapse:collapse; font-size:9.5pt; margin:6px 0 12px; }
th,td { border:1px solid #444; padding:4px 6px; vertical-align:top; }
th { background:#eee; text-align:center; }
.center { text-align:center; }
.right { text-align:right; }
.muted { color:#555; }
.cover { text-align:center; padding:24mm 8mm 10mm; page-break-after:always; }
.cover .logo { max-height:90px; margin-bottom:12px; }
.cover h1 { font-size:24pt; letter-spacing:.5px; margin-bottom:6px; }
.cover h2 { border:none; font-size:16pt; margin:0 0 6px; }
.cover .periode { font-size:15pt; font-weight:bold; margin:14px 0 4px; }
.cover .instansi { margin-top:40mm; font-size:14pt; font-weight:bold; }
.cover .alamat { font-size:11pt; margin-top:4px; }
.page-break { page-break-before:always; }
.sign { margin-top:26px; width:100%; }
.sign td { border:none; text-align:center; padding-top:4px; }
.sign .space { height:62px; }
.toolbar { max-width:210mm; margin:0 auto 8px; display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; padding:8px 0; }
.toolbar button, .toolbar a { font-family:Arial, sans-serif; font-size:12px; padding:7px 14px; border-radius:6px; border:1px solid #333; background:#fff; cursor:pointer; text-decoration:none; color:#111; }
.toolbar .primary { background:#1f6f5c; border-color:#1f6f5c; color:#fff; }
.summary-table td { border:none; padding:2px 4px; }
.badge { display:inline-block; padding:2px 8px; border:1px solid #555; border-radius:10px; font-size:9pt; }
.note { font-size:9.5pt; color:#444; font-style:italic; }
.sub { font-size:9pt; }
@media print {
    body { background:#fff; }
    .sheet { box-shadow:none; max-width:none; padding:0; margin:0; }
    .toolbar { display:none !important; }
    h2 { page-break-after:avoid; }
    table { page-break-inside:auto; }
    tr { page-break-inside:avoid; }
    .cover { padding-top:16mm; }
}
</style>
</head>
<body>
<?php
$logo = trim((string)($school['logo'] ?? ''));
$logoPath = $logo !== '' ? '../assets/img/' . basename($logo) : '';
?>
<div class="toolbar">
    <form method="GET" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="doc" value="<?= (int)$doc['id'] ?>">
        <select name="bidang" class="form-control form-control-sm" style="width:auto">
            <option value="">Semua Bidang</option>
            <?php foreach ($fields as $f): ?>
                <option value="<?= (int)$f['id'] ?>" <?= $f_field === (int)$f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['nama']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Terapkan</button>
    </form>
    <a href="rkt.php?doc=<?= (int)$doc['id'] ?>">Kembali ke RKT</a>
    <button type="button" class="primary" onclick="window.print()">Cetak / Simpan PDF</button>
</div>

<div class="sheet">
    <!-- 1. Sampul -->
    <div class="cover">
        <?php if ($logoPath && is_file(__DIR__ . '/' . $logoPath)): ?>
            <img src="<?= htmlspecialchars($logoPath) ?>" class="logo" alt="Logo Madrasah">
        <?php endif; ?>
        <div style="font-size:12pt;font-weight:bold;margin-bottom:24mm"><?= htmlspecialchars($school['nama_yayasan'] ?? '') ?></div>
        <h1>RENCANA KERJA TAHUNAN</h1>
        <h2>(RKT)</h2>
        <div class="periode">TAHUN <?= (int)$doc['tahun'] ?></div>
        <?php if (!empty($doc['nomor_dokumen'])): ?><div>Nomor: <?= htmlspecialchars($doc['nomor_dokumen']) ?></div><?php endif; ?>
        <div class="instansi"><?= htmlspecialchars($doc['nama_madrasah'] ?: ($school['nama_madrasah'] ?? 'Madrasah')) ?></div>
        <div class="alamat"><?= htmlspecialchars($school['alamat'] ?? '') ?></div>
        <?php if (!empty($school['email_madrasah'])): ?><div class="alamat"><?= htmlspecialchars($school['email_madrasah']) ?></div><?php endif; ?>
        <?php if (!empty($school['website_madrasah'])): ?><div class="alamat"><?= htmlspecialchars($school['website_madrasah']) ?></div><?php endif; ?>
    </div>

    <!-- 2. Identitas -->
    <h2>Identitas Dokumen</h2>
    <table>
        <tr><th style="width:32%">Nama Madrasah</th><td><?= htmlspecialchars($doc['nama_madrasah'] ?: ($school['nama_madrasah'] ?? 'Madrasah')) ?></td></tr>
        <tr><th>Alamat</th><td><?= htmlspecialchars($school['alamat'] ?? '-') ?></td></tr>
        <tr><th>Nomor Dokumen</th><td><?= htmlspecialchars($doc['nomor_dokumen'] ?: '-') ?></td></tr>
        <tr><th>Tahun Pelaksanaan</th><td><?= (int)$doc['tahun'] ?></td></tr>
        <tr><th>Referensi RKJM</th><td><?= $rkjm ? 'RKJM ' . (int)$rkjm['tahun_awal'] . '-' . (int)$rkjm['tahun_akhir'] . (!empty($rkjm['nomor_dokumen']) ? ' | ' . htmlspecialchars($rkjm['nomor_dokumen']) : '') : 'Belum terhubung ke RKJM' ?></td></tr>
        <tr><th>Status Dokumen</th><td><?= cetak_rkt_status($doc['status'], $docStatusOpts) ?></td></tr>
        <tr><th>Tanggal Penyusunan</th><td><?= htmlspecialchars(rktTanggal($doc['tanggal_penyusunan'])) ?></td></tr>
        <tr><th>Kepala Madrasah</th><td><?= htmlspecialchars($nama_kepala ?: '-') ?></td></tr>
    </table>

    <!-- 3. Pendahuluan -->
    <h2>Pendahuluan</h2>
    <p>Rencana Kerja Tahunan (RKT) ini disusun sebagai penjabaran tahunan dari Rencana Kerja Jangka Menengah (RKJM) madrasah. Dokumen ini memuat prioritas program, kegiatan, indikator, target, jadwal, penanggung jawab, dan rencana anggaran untuk tahun pelaksanaan <?= (int)$doc['tahun'] ?>.</p>
    <p>Program yang tercantum dapat berasal dari RKJM, template RKT, atau disusun secara mandiri. Program yang belum memiliki referensi sumber ditandai sebagai belum terhubung ke RKJM. Seluruh target, jadwal, penanggung jawab, dan anggaran perlu ditinjau sesuai kondisi aktual madrasah.</p>
    <?php if (!empty($doc['catatan'])): ?><p><strong>Catatan:</strong> <?= cetak_rkt_nl($doc['catatan']) ?></p><?php endif; ?>
    <p class="note">Status dokumen saat ini: <?= cetak_rkt_status($doc['status'], $docStatusOpts) ?>. Dokumen tidak dianggap telah disahkan hanya karena telah dicetak atau disimpan sebagai PDF.</p>

    <!-- 4. Dasar Penyusunan -->
    <h2>Dasar Penyusunan</h2>
    <div><?= cetak_rkt_nl($doc['dasar_penyusunan']) ?></div>

    <!-- 5. Visi, Misi, Tujuan -->
    <h2>Visi, Misi, dan Tujuan</h2>
    <h3>Visi</h3>
    <div><?= cetak_rkt_nl($doc['visi']) ?></div>
    <h3>Misi</h3>
    <div><?= cetak_rkt_nl($doc['misi']) ?></div>
    <h3>Tujuan Pengembangan</h3>
    <div><?= cetak_rkt_nl($doc['tujuan_pengembangan']) ?></div>

    <!-- 6. Prioritas -->
    <h2>Prioritas Program Tahun Berjalan</h2>
    <div><?= cetak_rkt_nl($doc['prioritas']) ?></div>

    <!-- 7. Program dan Kegiatan -->
    <h2 class="page-break">Rencana Program dan Kegiatan</h2>
    <?php if (!$programs): ?>
        <p class="muted">Belum ada program pada filter ini.</p>
    <?php endif; ?>
    <?php $no = 0; foreach ($programs as $p): $no++; ?>
        <h3><?= $no ?>. <?= htmlspecialchars($p['nama_program']) ?></h3>
        <table>
            <tr><th style="width:26%">Bidang</th><td><?= htmlspecialchars($p['bidang_nama'] ?: '-') ?></td></tr>
            <tr><th>Referensi RKJM</th><td><?= htmlspecialchars($p['rkjm_referensi'] ?: 'Belum Terhubung ke RKJM') ?></td></tr>
            <tr><th>Tujuan</th><td><?= cetak_rkt_nl($p['tujuan']) ?></td></tr>
            <tr><th>Sasaran</th><td><?= cetak_rkt_nl($p['sasaran']) ?></td></tr>
            <tr><th>Indikator Keberhasilan</th><td><?= cetak_rkt_nl($p['indikator']) ?></td></tr>
            <tr><th>Target Tahunan</th><td><?= cetak_rkt_nl($p['target_tahunan']) ?></td></tr>
            <tr><th>Jadwal</th><td><?= htmlspecialchars(rktTanggal($p['jadwal_mulai'])) ?> s.d. <?= htmlspecialchars(rktTanggal($p['jadwal_selesai'])) ?></td></tr>
            <tr><th>Penanggung Jawab</th><td><?= htmlspecialchars($p['penanggung_jawab'] ?: '-') ?></td></tr>
            <tr><th>Estimasi Anggaran</th><td><?= rktRupiah($p['estimasi_anggaran']) ?> (estimasi tahunan, bukan realisasi)</td></tr>
            <tr><th>Sumber Dana</th><td><?= htmlspecialchars($p['sumber_dana'] ?: '-') ?></td></tr>
            <tr><th>Output</th><td><?= cetak_rkt_nl($p['output']) ?></td></tr>
            <tr><th>Status</th><td><?= cetak_rkt_status($p['status'], $statusOpts) ?></td></tr>
        </table>
        <h4>Kegiatan</h4>
        <table>
            <thead>
                <tr><th>No.</th><th>Nama Kegiatan</th><th>Target / Volume</th><th>Jadwal</th><th>PJ</th><th>Estimasi Biaya</th><th>Sumber</th><th>Output</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php $acts = $activities[(int)$p['id']] ?? []; ?>
                <?php if (!$acts): ?><tr><td colspan="9" class="center muted">Belum ada kegiatan.</td></tr><?php endif; ?>
                <?php $an = 0; foreach ($acts as $a): $an++; ?>
                    <tr>
                        <td class="center"><?= $an ?></td>
                        <td><?= htmlspecialchars($a['nama_kegiatan']) ?></td>
                        <td><?= htmlspecialchars(trim((string)$a['target_volume'] . ' ' . (string)$a['satuan'])) ?: '-' ?></td>
                        <td class="sub"><?= htmlspecialchars(rktTanggal($a['jadwal_mulai'])) ?> s.d. <?= htmlspecialchars(rktTanggal($a['jadwal_selesai'])) ?></td>
                        <td><?= htmlspecialchars($a['penanggung_jawab'] ?: '-') ?></td>
                        <td class="right"><?= rktRupiah($a['estimasi_biaya']) ?></td>
                        <td><?= htmlspecialchars($a['sumber_dana'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($a['output'] ?: '-') ?></td>
                        <td><?= cetak_rkt_status($a['status'], $statusOpts) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>

    <!-- 8. Indikator dan Target -->
    <h2 class="page-break">Indikator dan Target</h2>
    <table>
        <thead><tr><th>No.</th><th>Program</th><th>Indikator Keberhasilan</th><th>Target Tahunan</th><th>Status</th></tr></thead>
        <tbody>
            <?php $n = 0; foreach ($programs as $p): $n++; ?>
                <tr>
                    <td class="center"><?= $n ?></td>
                    <td><?= htmlspecialchars($p['nama_program']) ?></td>
                    <td><?= cetak_rkt_nl($p['indikator']) ?></td>
                    <td><?= cetak_rkt_nl($p['target_tahunan']) ?></td>
                    <td><?= cetak_rkt_status($p['status'], $statusOpts) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$programs): ?><tr><td colspan="5" class="center muted">Belum ada data.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <!-- 9. Jadwal -->
    <h2>Jadwal Pelaksanaan</h2>
    <table>
        <thead><tr><th>No.</th><th>Cakupan</th><th>Nama</th><th>Mulai</th><th>Selesai</th><th>PJ</th></tr></thead>
        <tbody>
            <?php $n = 0; foreach ($programs as $p): $n++; ?>
                <tr>
                    <td class="center"><?= $n ?></td>
                    <td>Program</td>
                    <td><?= htmlspecialchars($p['nama_program']) ?></td>
                    <td><?= htmlspecialchars(rktTanggal($p['jadwal_mulai'])) ?></td>
                    <td><?= htmlspecialchars(rktTanggal($p['jadwal_selesai'])) ?></td>
                    <td><?= htmlspecialchars($p['penanggung_jawab'] ?: '-') ?></td>
                </tr>
                <?php foreach (($activities[(int)$p['id']] ?? []) as $a): $n++; ?>
                    <tr>
                        <td class="center"><?= $n ?></td>
                        <td>Kegiatan</td>
                        <td><?= htmlspecialchars($a['nama_kegiatan']) ?></td>
                        <td><?= htmlspecialchars(rktTanggal($a['jadwal_mulai'])) ?></td>
                        <td><?= htmlspecialchars(rktTanggal($a['jadwal_selesai'])) ?></td>
                        <td><?= htmlspecialchars($a['penanggung_jawab'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if (!$programs): ?><tr><td colspan="6" class="center muted">Belum ada data.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <!-- 10. Penanggung Jawab -->
    <h2>Penanggung Jawab</h2>
    <table>
        <thead><tr><th>No.</th><th>Cakupan</th><th>Nama</th><th>Penanggung Jawab</th></tr></thead>
        <tbody>
            <?php $n = 0; foreach ($programs as $p): $n++; ?>
                <tr><td class="center"><?= $n ?></td><td>Program</td><td><?= htmlspecialchars($p['nama_program']) ?></td><td><?= htmlspecialchars($p['penanggung_jawab'] ?: '-') ?></td></tr>
                <?php foreach (($activities[(int)$p['id']] ?? []) as $a): $n++; ?>
                    <tr><td class="center"><?= $n ?></td><td>Kegiatan</td><td><?= htmlspecialchars($a['nama_kegiatan']) ?></td><td><?= htmlspecialchars($a['penanggung_jawab'] ?: '-') ?></td></tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- 11. Anggaran -->
    <h2 class="page-break">Rencana Anggaran</h2>
    <table>
        <thead><tr><th>No.</th><th>Bidang</th><th>Program</th><th>Estimasi Anggaran Program</th><th>Jumlah Estimasi Kegiatan</th><th>Sumber Dana</th></tr></thead>
        <tbody>
            <?php $n = 0; foreach ($programs as $p): $n++; $acts = $activities[(int)$p['id']] ?? []; $sumAct = 0.0; foreach ($acts as $a) $sumAct += (float)$a['estimasi_biaya']; ?>
                <tr>
                    <td class="center"><?= $n ?></td>
                    <td><?= htmlspecialchars($p['bidang_nama'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($p['nama_program']) ?></td>
                    <td class="right"><?= rktRupiah($p['estimasi_anggaran']) ?></td>
                    <td class="right"><?= rktRupiah($sumAct) ?></td>
                    <td><?= htmlspecialchars($p['sumber_dana'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$programs): ?><tr><td colspan="6" class="center muted">Belum ada data.</td></tr><?php endif; ?>
        </tbody>
        <tfoot>
            <tr><th colspan="3" class="right">Total Estimasi Anggaran Program</th><th class="right"><?= rktRupiah($total_program_anggaran) ?></th><th class="right"><?= rktRupiah($total_activity_anggaran) ?></th><th></th></tr>
        </tfoot>
    </table>
    <p class="note">Nilai di atas adalah estimasi perencanaan, bukan realisasi pengeluaran. Realisasi yang telah dicatat melalui monitoring ditampilkan pada bagian Monitoring dan Evaluasi.</p>
    <?php if (!empty($by_field)): ?>
        <h3>Rekapitulasi per Bidang</h3>
        <table>
            <thead><tr><th>Bidang</th><th>Jumlah Program</th><th>Estimasi Anggaran</th></tr></thead>
            <tbody>
                <?php foreach ($by_field as $bn => $bd): ?>
                    <tr><td><?= htmlspecialchars($bn) ?></td><td class="center"><?= (int)$bd['count'] ?></td><td class="right"><?= rktRupiah($bd['anggaran']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- 12. Monitoring dan Evaluasi -->
    <h2 class="page-break">Monitoring dan Evaluasi</h2>
    <p>Monitoring dan evaluasi dilakukan terhadap ketercapaian target program dan kegiatan. Persentase capaian hanya dihitung apabila indikator bersifat kuantitatif dan targetnya jelas. Indikator kualitatif dinilai melalui hasil evaluasi atau penilaian manual.</p>
    <?php if (!$programs): ?><p class="muted">Belum ada data program.</p><?php endif; ?>
    <?php foreach ($programs as $p): ?>
        <h3><?= htmlspecialchars($p['nama_program']) ?></h3>
        <p><strong>Mekanisme monev:</strong> <?= cetak_rkt_nl($p['monitoring_evaluasi']) ?></p>
        <table>
            <thead><tr><th>Tanggal</th><th>Cakupan</th><th>Target</th><th>Realisasi</th><th>Capaian</th><th>Status</th><th>Kendala</th><th>Tindak Lanjut</th><th>Output Aktual</th><th>Bukti</th><th>Biaya Realisasi</th><th>Evaluator</th></tr></thead>
            <tbody>
                <?php $rows = $monitoring[(int)$p['id']] ?? []; ?>
                <?php if (!$rows): ?><tr><td colspan="12" class="center muted">Belum ada catatan monitoring.</td></tr><?php endif; ?>
                <?php foreach ($rows as $m): ?>
                    <tr>
                        <td class="sub"><?= htmlspecialchars(rktTanggal($m['tanggal_pelaksanaan'])) ?></td>
                        <td><?= $m['activity_id'] ? 'Kegiatan: ' . htmlspecialchars($m['nama_kegiatan'] ?: '-') : 'Program' ?></td>
                        <td><?= cetak_rkt_nl($m['target']) ?></td>
                        <td><?= cetak_rkt_nl($m['realisasi']) ?></td>
                        <td class="center"><?= ($m['persentase_capaian'] !== null && $m['persentase_capaian'] !== '') ? (float)$m['persentase_capaian'] . '%' : htmlspecialchars($m['penilaian_kualitatif'] ?: '-') ?></td>
                        <td><?= cetak_rkt_status($m['status_pelaksanaan'], $statusOpts) ?></td>
                        <td><?= cetak_rkt_nl($m['kendala']) ?></td>
                        <td><?= cetak_rkt_nl($m['tindak_lanjut']) ?></td>
                        <td><?= cetak_rkt_nl($m['output_aktual']) ?></td>
                        <td><?= cetak_rkt_nl($m['bukti']) ?></td>
                        <td class="right"><?= $m['realisasi_biaya'] !== null ? rktRupiah($m['realisasi_biaya']) : '-' ?></td>
                        <td><?= htmlspecialchars($m['petugas_evaluasi'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>
    <p><strong>Total realisasi anggaran tercatat:</strong> <?= rktRupiah($total_realisasi) ?> (berdasarkan catatan monitoring level program pada data yang ditampilkan).</p>

    <!-- 13. Penutup -->
    <h2>Penutup</h2>
    <p>Rencana Kerja Tahunan ini menjadi acuan pelaksanaan program dan kegiatan madrasah pada tahun <?= (int)$doc['tahun'] ?>. Pelaksanaan dan capaiannya akan dipantau serta dievaluasi secara berkala, dan hasil evaluasi digunakan sebagai bahan penyusunan perbaikan pada tahun berikutnya.</p>

    <!-- 14. Pengesahan -->
    <h2 class="page-break">Lembar Pengesahan</h2>
    <p>Dokumen Rencana Kerja Tahunan (RKT) tahun <?= (int)$doc['tahun'] ?> ini disusun untuk digunakan sebagai pedoman pelaksanaan program kerja madrasah.</p>
    <table class="sign">
        <tr>
            <td style="width:50%">
                Mengetahui,<br>Kepala Madrasah
                <div class="space"></div>
                <strong><?= htmlspecialchars($nama_kepala ?: '..............................') ?></strong><br>
                <?= $nip_kepala !== '' ? 'NIP. ' . htmlspecialchars($nip_kepala) : '' ?>
            </td>
            <td style="width:50%">
                <?= htmlspecialchars($kota !== '' ? $kota : '....................') ?>, <?= htmlspecialchars(rktTanggal($doc['tanggal_penyusunan']) !== '-' ? rktTanggal($doc['tanggal_penyusunan']) : '....................') ?><br>
                Penyusun
                <div class="space"></div>
                <strong><?= htmlspecialchars($doc['nama_kepala'] ?: '..............................') ?></strong><br>
                &nbsp;
            </td>
        </tr>
    </table>
    <p class="note center">Status dokumen: <?= cetak_rkt_status($doc['status'], $docStatusOpts) ?>. Lembar pengesahan ini disediakan untuk ditandatangani sesuai mekanisme madrasah.</p>
</div>
</body>
</html>
