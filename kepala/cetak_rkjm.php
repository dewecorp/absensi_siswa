<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/rkjm_helper.php';

if (session_status() == PHP_SESSION_NONE) session_start();
if (!isAuthorized(['admin', 'kepala_madrasah'])) redirect('../login.php');

rkjmEnsureSchema($pdo);

$docId = (int)($_GET['doc'] ?? 0);
$doc = rkjmGetDocument($pdo, $docId);
if (!$doc) {
    http_response_code(404);
    echo 'Dokumen RKJM tidak ditemukan.';
    exit;
}

$fields = rkjmFields($pdo);
$years = rkjmYears($doc);
$summary = rkjmSummary($pdo, (int)$doc['id']);
$f_field = (int)($_GET['bidang'] ?? 0);
$f_tahun = (int)($_GET['tahun'] ?? 0);

$where = ['p.document_id = ?'];
$params = [(int)$doc['id']];
if ($f_field > 0) { $where[] = 'p.field_id = ?'; $params[] = $f_field; }
if ($f_tahun > 0) {
    $where[] = "EXISTS (SELECT 1 FROM rkjm_program_targets t WHERE t.program_id = p.id AND t.tahun_kalender = ? AND COALESCE(t.target,'') <> '')";
    $params[] = $f_tahun;
}
$stmt = $pdo->prepare("SELECT p.* FROM rkjm_programs p WHERE " . implode(' AND ', $where) . " ORDER BY p.bidang_nama ASC, p.urutan ASC, p.id ASC");
$stmt->execute($params);
$programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ids = array_map(static fn($p) => (int)$p['id'], $programs);
$targets_map = $ids ? rkjmTargetsByProgram($pdo, $ids) : [];

$monitoring_map = [];
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $ms = $pdo->prepare("SELECT * FROM rkjm_monitoring WHERE program_id IN ($in) ORDER BY tahun_kalender ASC, id ASC");
    $ms->execute($ids);
    foreach ($ms->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $monitoring_map[(int)$m['program_id']][] = $m;
    }
}

$statusOpts = rkjmProgramStatusOptions();
$docStatusOpts = rkjmDocStatusOptions();
$school = getSchoolProfile($pdo);
$nama_kepala = trim((string)($doc['nama_kepala'] ?? ''));
if ($nama_kepala === '') { $nama_kepala = trim((string)($school['nama_kepala'] ?? '')); }
if ($nama_kepala === '') { $nama_kepala = trim((string)($school['kepala_madrasah'] ?? '')); }
$kota = trim((string)($school['kota'] ?? ''));
$total_anggaran = 0.0;
$by_field = [];
foreach ($programs as $p) {
    $total_anggaran += (float)$p['estimasi_anggaran'];
    $bn = $p['bidang_nama'] ?: 'Lainnya';
    if (!isset($by_field[$bn])) $by_field[$bn] = ['count' => 0, 'anggaran' => 0.0];
    $by_field[$bn]['count']++;
    $by_field[$bn]['anggaran'] += (float)$p['estimasi_anggaran'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>RKJM <?= (int)$doc['tahun_awal'] ?>–<?= (int)$doc['tahun_akhir'] ?> - <?= htmlspecialchars($doc['nama_madrasah'] ?: ($school['nama_madrasah'] ?? 'Madrasah')) ?></title>
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
.toolbar .primary { background:#3b5bdb; border-color:#3b5bdb; color:#fff; }
.summary-table td { border:none; padding:2px 4px; }
@media print {
    body { background:#fff; }
    .sheet { box-shadow:none; max-width:none; padding:0; margin:0; }
    .toolbar { display:none !important; }
    h2 { page-break-after:avoid; }
    table { page-break-inside:auto; }
    tr { page-break-inside:avoid; }
}
</style>
</head>
<body>
<?php $logo = trim((string)($school['logo'] ?? '')); $logoPath = $logo !== '' ? '../assets/img/' . basename($logo) : ''; ?>
<div class="toolbar">
    <form method="GET" class="d-flex flex-wrap" style="gap:6px;align-items:center">
        <input type="hidden" name="doc" value="<?= (int)$doc['id'] ?>">
        <select name="bidang" class="form-control form-control-sm" style="width:auto">
            <option value="">Semua Bidang</option>
            <?php foreach ($fields as $f): ?>
                <option value="<?= (int)$f['id'] ?>" <?= $f_field === (int)$f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['nama']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="tahun" class="form-control form-control-sm" style="width:auto">
            <option value="">Semua Tahun</option>
            <?php foreach ($years as $tk => $th): ?>
                <option value="<?= (int)$th ?>" <?= $f_tahun === (int)$th ? 'selected' : '' ?>>Tahun <?= (int)$tk ?> (<?= (int)$th ?>)</option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-light">Terapkan Filter</button>
    </form>
    <a href="rkjm.php?doc=<?= (int)$doc['id'] ?>">Kembali ke RKJM</a>
    <button type="button" class="primary" onclick="window.print()">Cetak / Simpan PDF</button>
</div>
<div class="sheet">

    <!-- 1. Sampul -->
    <div class="cover">
        <?php if ($logoPath && is_file(__DIR__ . '/' . $logoPath)): ?>
            <img src="<?= htmlspecialchars($logoPath) ?>" alt="Logo" class="logo">
        <?php endif; ?>
        <h1>RENCANA KERJA JANGKA MENENGAH</h1>
        <h2>(RKJM)</h2>
        <div class="periode">Periode <?= (int)$doc['tahun_awal'] ?>–<?= (int)$doc['tahun_akhir'] ?></div>
        <p class="center muted"><?= htmlspecialchars($doc['nomor_dokumen'] ?: 'Nomor dokumen: ........................') ?></p>
        <div class="instansi"><?= htmlspecialchars($doc['nama_madrasah'] ?: ($school['nama_madrasah'] ?? 'NAMA MADRASAH')) ?></div>
        <div class="alamat"><?= htmlspecialchars($school['alamat'] ?? '') ?></div>
    </div>

    <!-- 2. Identitas -->
    <h2>Identitas Madrasah</h2>
    <table>
        <tr><th style="width:32%">Nama Madrasah</th><td><?= htmlspecialchars($doc['nama_madrasah'] ?: ($school['nama_madrasah'] ?? '-')) ?></td></tr>
        <tr><th>Alamat</th><td><?= htmlspecialchars($school['alamat'] ?? '-') ?></td></tr>
        <tr><th>Nama Kepala Madrasah</th><td><?= htmlspecialchars($nama_kepala ?: '-') ?></td></tr>
        <tr><th>Nomor Dokumen</th><td><?= htmlspecialchars($doc['nomor_dokumen'] ?: '-') ?></td></tr>
        <tr><th>Periode RKJM</th><td><?= (int)$doc['tahun_awal'] ?>–<?= (int)$doc['tahun_akhir'] ?> (<?= count($years) ?> tahun)</td></tr>
        <tr><th>Tanggal Penyusunan</th><td><?= $doc['tanggal_penyusunan'] ? htmlspecialchars(date('d-m-Y', strtotime($doc['tanggal_penyusunan']))) : '-' ?></td></tr>
        <tr><th>Status Dokumen</th><td><?= htmlspecialchars($docStatusOpts[$doc['status']] ?? $doc['status']) ?></td></tr>
    </table>

    <!-- 3. Pendahuluan -->
    <h2>Pendahuluan</h2>
    <p>Rencana Kerja Jangka Menengah (RKJM) ini disusun sebagai arah pengembangan <?= htmlspecialchars($doc['nama_madrasah'] ?: 'madrasah') ?> untuk periode <?= (int)$doc['tahun_awal'] ?>–<?= (int)$doc['tahun_akhir'] ?>. Dokumen ini memuat program prioritas, target tahunan, kebutuhan pembiayaan, serta mekanisme monitoring dan evaluasi yang digunakan sebagai dasar penyusunan rencana kerja tahunan.</p>
    <?php if (!empty($doc['dasar_penyusunan'])): ?>
        <h3>Dasar Penyusunan</h3>
        <p style="white-space:pre-line"><?= htmlspecialchars($doc['dasar_penyusunan']) ?></p>
    <?php endif; ?>

    <!-- 4. Profil -->
    <h2>Profil dan Kondisi Awal Madrasah</h2>
    <p style="white-space:pre-line"><?= htmlspecialchars($doc['kondisi_awal'] ?: 'Belum diisi. Lengkapi melalui menu Pengaturan RKJM.') ?></p>

    <!-- 5. Visi Misi Tujuan -->
    <h2>Visi, Misi, dan Tujuan Madrasah</h2>
    <h3>Visi</h3>
    <p style="white-space:pre-line"><?= htmlspecialchars($doc['visi'] ?: '-') ?></p>
    <h3>Misi</h3>
    <p style="white-space:pre-line"><?= htmlspecialchars($doc['misi'] ?: '-') ?></p>
    <h3>Tujuan Pengembangan</h3>
    <p style="white-space:pre-line"><?= htmlspecialchars($doc['tujuan_pengembangan'] ?: '-') ?></p>

    <!-- 6. Analisis kebutuhan -->
    <h2>Analisis Kebutuhan dan Prioritas Pengembangan</h2>
    <p>Program disusun berdasarkan bidang pengembangan berikut. Jumlah dan anggaran dihitung dari data program pada dokumen ini.</p>
    <table>
        <thead><tr><th>No.</th><th>Bidang Pengembangan</th><th>Jumlah Program</th><th>Estimasi Anggaran</th></tr></thead>
        <tbody>
            <?php $i = 0; foreach ($by_field as $bn => $agg): $i++; ?>
                <tr><td class="center"><?= $i ?></td><td><?= htmlspecialchars($bn) ?></td><td class="center"><?= (int)$agg['count'] ?></td><td class="right"><?= rkjmRupiah($agg['anggaran']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$by_field): ?><tr><td colspan="4" class="center muted">Belum ada program.</td></tr><?php endif; ?>
            <tr><th colspan="2">Total</th><th><?= (int)count($programs) ?></th><th class="right"><?= rkjmRupiah($total_anggaran) ?></th></tr>
        </tbody>
    </table>

    <!-- 7. Rencana kerja empat tahun -->
    <h2>Rencana Kerja Empat Tahun</h2>
    <table>
        <thead>
            <tr>
                <th style="width:30px">No.</th>
                <th>Program</th>
                <?php foreach ($years as $tk => $th): ?><th>Tahun <?= (int)$tk ?><br>(<?= (int)$th ?>)</th><?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (!$programs): ?><tr><td colspan="<?= 2 + count($years) ?>" class="center muted">Belum ada program.</td></tr><?php endif; ?>
            <?php foreach ($programs as $idx => $p): ?>
                <tr>
                    <td class="center"><?= $idx + 1 ?></td>
                    <td><?= htmlspecialchars($p['nama_program']) ?></td>
                    <?php foreach ($years as $tk => $th): ?>
                        <td><?= htmlspecialchars($targets_map[(int)$p['id']][$tk]['target'] ?? '-') ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- 8 & 9. Rincian per bidang -->
    <h2 class="page-break">Rincian Program per Bidang</h2>
    <?php foreach ($by_field as $bn => $agg): ?>
        <h3><?= htmlspecialchars($bn) ?></h3>
        <?php foreach ($programs as $p): if (($p['bidang_nama'] ?: 'Lainnya') !== $bn) continue; ?>
            <table>
                <tr><th style="width:28%">Kode / Nama Program</th><td><strong><?= htmlspecialchars($p['kode_program'] ?: '-') ?></strong> — <?= htmlspecialchars($p['nama_program']) ?></td></tr>
                <tr><th>Kondisi Awal</th><td style="white-space:pre-line"><?= htmlspecialchars($p['kondisi_awal'] ?: '-') ?></td></tr>
                <tr><th>Permasalahan</th><td style="white-space:pre-line"><?= htmlspecialchars($p['permasalahan'] ?: '-') ?></td></tr>
                <tr><th>Tujuan / Sasaran</th><td style="white-space:pre-line"><?= htmlspecialchars($p['tujuan'] ?: '-') ?><?= !empty($p['sasaran']) ? "\nSasaran: " . htmlspecialchars($p['sasaran']) : '' ?></td></tr>
                <tr><th>Indikator Keberhasilan</th><td style="white-space:pre-line"><?= htmlspecialchars($p['indikator'] ?: '-') ?></td></tr>
                <tr><th>Target Akhir</th><td style="white-space:pre-line"><?= htmlspecialchars($p['target_akhir'] ?: '-') ?></td></tr>
                <tr><th>Kegiatan Utama</th><td style="white-space:pre-line"><?= htmlspecialchars($p['kegiatan_utama'] ?: '-') ?></td></tr>
                <tr><th>Target Tahunan</th><td>
                    <?php foreach ($years as $tk => $th): ?>
                        <div><strong>Tahun <?= (int)$tk ?> (<?= (int)$th ?>):</strong> <?= htmlspecialchars($targets_map[(int)$p['id']][$tk]['target'] ?? '-') ?></div>
                    <?php endforeach; ?>
                </td></tr>
                <tr><th>Penanggung Jawab</th><td><?= htmlspecialchars($p['penanggung_jawab'] ?: '-') ?></td></tr>
                <tr><th>Estimasi Anggaran / Sumber</th><td><?= rkjmRupiah($p['estimasi_anggaran']) ?> — <?= htmlspecialchars($p['sumber_dana'] ?: '-') ?></td></tr>
                <tr><th>Dokumen/Bukti</th><td><?= htmlspecialchars($p['dokumen_bukti'] ?: '-') ?></td></tr>
                <tr><th>Monitoring dan Evaluasi</th><td><?= htmlspecialchars($p['monitoring_evaluasi'] ?: '-') ?></td></tr>
                <tr><th>Status</th><td><?= htmlspecialchars($statusOpts[$p['status']] ?? $p['status']) ?></td></tr>
                <?php if (!empty($monitoring_map[(int)$p['id']])): ?>
                <tr><th>Catatan Monitoring</th><td>
                    <?php foreach ($monitoring_map[(int)$p['id']] as $m): ?>
                        <div style="margin-bottom:4px">
                            <strong><?= htmlspecialchars((string)$m['tahun_kalender']) ?>:</strong>
                            <?= htmlspecialchars($statusOpts[$m['status_pelaksanaan']] ?? $m['status_pelaksanaan']) ?>
                            <?php if ($m['persentase_capaian'] !== null): ?> — <strong><?= htmlspecialchars((string)$m['persentase_capaian']) ?>%</strong><?php elseif (!empty($m['penilaian_kualitatif'])): ?> — <?= htmlspecialchars($m['penilaian_kualitatif']) ?><?php endif; ?>
                            <?php if (!empty($m['realisasi'])): ?><br>Realisasi: <?= htmlspecialchars($m['realisasi']) ?><?php endif; ?>
                            <?php if (!empty($m['kendala'])): ?><br>Kendala: <?= htmlspecialchars($m['kendala']) ?><?php endif; ?>
                            <?php if (!empty($m['tindak_lanjut'])): ?><br>Tindak lanjut: <?= htmlspecialchars($m['tindak_lanjut']) ?><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </td></tr>
                <?php endif; ?>
                <tr><th>Catatan Tambahan</th><td style="white-space:pre-line"><?= htmlspecialchars($p['catatan'] ?: '-') ?></td></tr>
            </table>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <!-- 10. Rencana pembiayaan -->
    <h2>Rencana Pembiayaan</h2>
    <table>
        <thead><tr><th>No.</th><th>Program</th><th>Tahun Mulai</th><th>Estimasi Anggaran</th><th>Sumber Dana</th></tr></thead>
        <tbody>
            <?php foreach ($programs as $idx => $p): ?>
                <?php
                $startYear = '-';
                foreach ($years as $tk => $th) {
                    if (!empty($targets_map[(int)$p['id']][$tk]['target'])) { $startYear = (string)$th; break; }
                }
                ?>
                <tr>
                    <td class="center"><?= $idx + 1 ?></td>
                    <td><?= htmlspecialchars($p['nama_program']) ?></td>
                    <td class="center"><?= htmlspecialchars($startYear) ?></td>
                    <td class="right"><?= rkjmRupiah($p['estimasi_anggaran']) ?></td>
                    <td><?= htmlspecialchars($p['sumber_dana'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$programs): ?><tr><td colspan="5" class="center muted">Belum ada program.</td></tr><?php endif; ?>
            <tr><th colspan="3">Total</th><th class="right"><?= rkjmRupiah($total_anggaran) ?></th><th></th></tr>
        </tbody>
    </table>

    <!-- 11. Monev -->
    <h2>Mekanisme Monitoring dan Evaluasi</h2>
    <p>Monitoring dilaksanakan secara berkala terhadap capaian target tahunan setiap program. Evaluasi dilakukan minimal dua kali dalam satu tahun melalui rapat tim pengembang madrasah. Hasil evaluasi dicatat pada menu RKJM, meliputi realisasi, kendala, tindak lanjut, serta status pelaksanaan program. Indikator kualitatif dinilai secara manual, sedangkan indikator kuantitatif dapat dihitung persentase capaiannya.</p>

    <!-- 12. Penutup -->
    <h2>Penutup</h2>
    <p>RKJM ini menjadi acuan penyusunan rencana kerja tahunan dan pengelolaan anggaran madrasah. Seluruh program bersifat dinamis dan dapat disesuaikan dengan kondisi, kemampuan anggaran, serta prioritas pengembangan madrasah.</p>

    <!-- 13. Pengesahan -->
    <h2>Lembar Pengesahan</h2>
    <table class="sign">
        <tr>
            <td style="width:50%">Mengetahui,<br>Ketua Komite Madrasah</td>
            <td style="width:50%"><?= htmlspecialchars($kota !== '' ? $kota : '....................') ?>, <?= $doc['tanggal_penyusunan'] ? htmlspecialchars(date('d-m-Y', strtotime($doc['tanggal_penyusunan']))) : '........................' ?><br>Kepala Madrasah</td>
        </tr>
        <tr><td class="space"></td><td class="space"></td></tr>
        <tr>
            <td>(.............................................)</td>
            <td><strong><?= htmlspecialchars($nama_kepala ?: '(.............................................)') ?></strong></td>
        </tr>
    </table>
</div>
</body>
</html>
