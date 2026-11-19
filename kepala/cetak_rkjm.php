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
$school = getSchoolProfile($pdo);
$nama_kepala = trim((string)($doc['nama_kepala'] ?? ''));
if ($nama_kepala === '') { $nama_kepala = trim((string)($school['nama_kepala'] ?? '')); }
if ($nama_kepala === '') { $nama_kepala = trim((string)($school['kepala_madrasah'] ?? '')); }
$kota = trim((string)($school['kota'] ?? ($school['tempat_jadwal'] ?? '')));
$nama_madrasah = trim((string)($doc['nama_madrasah'] ?: ($school['nama_madrasah'] ?? 'Madrasah')));

// Ketua Komite Madrasah (dari tb_komite_madrasah)
$nama_ketua_komite = '';
try {
    $ks = $pdo->query("SELECT nama FROM tb_komite_madrasah WHERE jabatan LIKE '%ketua%' ORDER BY id ASC LIMIT 1");
    $nama_ketua_komite = trim((string)($ks->fetchColumn() ?: ''));
} catch (PDOException $e) {
    $nama_ketua_komite = '';
}

// QR tanda tangan digital (validasi dokumen)
$qr_base = 'https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=';
$qr_komite_payload = 'Validasi Tanda Tangan Ketua Komite Madrasah: ' . ($nama_ketua_komite !== '' ? $nama_ketua_komite : '-')
    . ' | ' . $nama_madrasah . ' | RKJM ' . (int)$doc['tahun_awal'] . '-' . (int)$doc['tahun_akhir'];
$qr_kepala_payload = 'Validasi Tanda Tangan Kepala Madrasah: ' . ($nama_kepala !== '' ? $nama_kepala : '-')
    . ' | ' . $nama_madrasah . ' | RKJM ' . (int)$doc['tahun_awal'] . '-' . (int)$doc['tahun_akhir'];
$qr_komite_url = $qr_base . urlencode($qr_komite_payload);
$qr_kepala_url = $qr_base . urlencode($qr_kepala_payload);
$nama_yayasan  = trim((string)($school['nama_yayasan'] ?? ''));
$alamat        = trim((string)($school['alamat'] ?? ''));
$email_m       = trim((string)($school['email_madrasah'] ?? ''));
$website_m     = trim((string)($school['website_madrasah'] ?? ''));
$nip_kepala    = trim((string)($school['nip_kepala'] ?? ''));

// Dasar penyusunan default referensial (dipakai hanya bila kolom kosong)
$dasar_default = '<ol>
<li>Undang-Undang Nomor 20 Tahun 2003 tentang Sistem Pendidikan Nasional.</li>
<li>Peraturan Pemerintah Nomor 19 Tahun 2005 jo. PP Nomor 13 Tahun 2015 tentang Standar Nasional Pendidikan.</li>
<li>Peraturan Menteri Agama Nomor 90 Tahun 2013 jo. PMA Nomor 60 Tahun 2015 tentang Penyelenggaraan Pendidikan Madrasah.</li>
<li>Keputusan Menteri Agama Nomor 347 Tahun 2022 tentang Pedoman Implementasi Kurikulum Merdeka pada Madrasah.</li>
<li>Keputusan Direktur Jenderal Pendidikan Islam Nomor 3451 Tahun 2024 tentang Petunjuk Teknis Penyusunan RKJM Madrasah.</li>
<li>Rencana Strategis Kementerian Agama Tahun 2025–2029.</li>
<li>Hasil Evaluasi Diri Madrasah (EDM) Tahun ' . (int)$doc['tahun_awal'] . '.</li>
<li>Rapat Kerja Madrasah dan masukan dari Komite Madrasah sebagai bahan penyusunan program prioritas.</li>
</ol>';

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
@page { size: 215mm 330mm; margin: 15mm 15mm; }
* { box-sizing: border-box; }
body { font-family: "Times New Roman", Georgia, serif; color:#111; font-size:11.5pt; line-height:1.5; margin:0; background:#f5f6f8; }
.sheet { background:#fff; max-width:215mm; min-height:330mm; margin:0 auto; padding:15mm; box-shadow:0 0 10px rgba(0,0,0,.08); }
h1,h2,h3,h4 { font-family: "Times New Roman", Georgia, serif; margin:0 0 8px; }
h1 { font-size:20pt; text-align:center; }
h2 { font-size:14pt; text-transform:uppercase; letter-spacing:.4px; border-bottom:2px solid #222; padding-bottom:4px; margin:22px 0 10px; }
h3 { font-size:12pt; margin:16px 0 6px; }
p { margin:0 0 8px; text-align:justify; }
table { width:100%; border-collapse:collapse; font-size:9.5pt; margin:6px 0 12px; }
th,td { border:1px solid #444; padding:4px 6px; vertical-align:top; }
th { background:#eee; text-align:center; }
.center { text-align:center; }
.right { text-align:right; }
.muted { color:#555; }
.cover { text-align:center; padding:18mm 10mm 16mm; page-break-after:always; height:330mm; display:flex; flex-direction:column; justify-content:space-between; align-items:center; }
.cover-head { width:100%; }
.cover-logo { flex:1 1 auto; display:flex; align-items:center; justify-content:center; width:100%; }
.cover-foot { width:100%; }
.cover .logo { display:block; margin:0 auto; max-height:240px; max-width:70%; width:auto; height:auto; object-fit:contain; }
.cover h1 { font-size:20pt; letter-spacing:.3px; margin-bottom:6px; text-align:center; width:100%; white-space:nowrap; }
.cover h2 { border:none; font-size:16pt; margin:0 0 6px; text-align:center; width:100%; }
.cover .periode { font-size:15pt; font-weight:bold; margin:14px 0 4px; width:100%; }
.cover .yayasan { font-size:12pt; margin-bottom:6px; width:100%; }
.cover .instansi { font-size:14pt; font-weight:bold; width:100%; text-transform:uppercase; letter-spacing:.5px; }
.cover .alamat { font-size:11pt; margin-top:4px; width:100%; }
.page-break { page-break-before:always; }
.sign { margin-top:26px; width:100%; }
.sign td { border:none; text-align:center; padding:4px 6px; vertical-align:middle; }
.sign .qr-cell { padding:6px 0 2px; }
.sign .qr-sign { display:block; margin:0 auto; width:80px; height:80px; }
.toolbar { max-width:215mm; margin:0 auto 8px; display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; padding:8px 0; }
.toolbar button, .toolbar a { font-family:Arial, sans-serif; font-size:12px; padding:7px 14px; border-radius:6px; border:1px solid #333; background:#fff; cursor:pointer; text-decoration:none; color:#111; }
.toolbar .primary { background:#3b5bdb; border-color:#3b5bdb; color:#fff; }
.summary-table td { border:none; padding:2px 4px; }
.rich-text p { margin:0 0 6px; }
.rich-text ul { list-style:disc outside; margin:0 0 6px; padding-left:20px; }
.rich-text ol { list-style:decimal outside; margin:0 0 6px; padding-left:22px; }
.rich-text ul ul { list-style:circle outside; }
.rich-text ol ol { list-style:lower-alpha outside; }
.rich-text li { margin-bottom:2px; }
.rich-text h1, .rich-text h2, .rich-text h3, .rich-text h4 { font-size:12pt; border:none; margin:6px 0; }
.rich-text a { color:#111; text-decoration:underline; }
@media print {
    body { background:#fff; }
    .sheet { box-shadow:none; max-width:none; min-height:0; padding:0; margin:0; }
    .cover { height:290mm; min-height:0; page-break-after:always; }
    .toolbar { display:none !important; }
    h2 { page-break-after:avoid; }
    table { page-break-inside:auto; }
    tr { page-break-inside:avoid; }
}
</style>
</head>
<body>
<?php
if (!function_exists('cetak_rkjm_rich')) {
    function cetak_rkjm_rich(?string $value, string $fallback = '-'): string
    {
        $value = (string)$value;
        if (trim($value) === '') {
            return htmlspecialchars($fallback, ENT_QUOTES, 'UTF-8');
        }
        if (strpos($value, '<') === false) {
            return nl2br(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        }
        $html = sanitizeRichText($value);
        return $html !== '' ? $html : htmlspecialchars($fallback, ENT_QUOTES, 'UTF-8');
    }
}
?>
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
        <div class="cover-head">
            <h1>RENCANA KERJA JANGKA MENENGAH</h1>
            <h2>(RKJM)</h2>
            <div class="periode">Periode <?= (int)$doc['tahun_awal'] ?>–<?= (int)$doc['tahun_akhir'] ?></div>
        </div>
        <div class="cover-logo">
            <?php if ($logoPath && is_file(__DIR__ . '/' . $logoPath)): ?>
                <img src="<?= htmlspecialchars($logoPath) ?>" alt="Logo" class="logo">
            <?php endif; ?>
        </div>
        <div class="cover-foot">
            <?php if ($nama_yayasan !== ''): ?>
                <div class="yayasan"><?= htmlspecialchars($nama_yayasan) ?></div>
            <?php endif; ?>
            <div class="instansi"><?= htmlspecialchars($nama_madrasah) ?></div>
            <div class="alamat"><?= htmlspecialchars($alamat) ?></div>
            <?php if ($kota !== ''): ?><div class="alamat"><?= htmlspecialchars($kota) ?></div><?php endif; ?>
        </div>
    </div>

    <!-- 2. Identitas -->
    <h2>Identitas Madrasah</h2>
    <table>
        <tr><th style="width:32%">Nama Madrasah</th><td><?= htmlspecialchars($nama_madrasah) ?></td></tr>
        <?php if ($nama_yayasan !== ''): ?>
        <tr><th>Nama Yayasan / Penyelenggara</th><td><?= htmlspecialchars($nama_yayasan) ?></td></tr>
        <?php endif; ?>
        <tr><th>Alamat</th><td><?= htmlspecialchars($alamat ?: '-') ?></td></tr>
        <?php if ($kota !== ''): ?>
        <tr><th>Kota / Kabupaten</th><td><?= htmlspecialchars($kota) ?></td></tr>
        <?php endif; ?>
        <?php if ($email_m !== ''): ?>
        <tr><th>Email</th><td><?= htmlspecialchars($email_m) ?></td></tr>
        <?php endif; ?>
        <?php if ($website_m !== ''): ?>
        <tr><th>Website</th><td><?= htmlspecialchars($website_m) ?></td></tr>
        <?php endif; ?>
        <tr><th>Nama Kepala Madrasah</th><td><?= htmlspecialchars($nama_kepala ?: '-') ?><?= $nip_kepala && $nip_kepala !== '-' ? ' (NIP: ' . htmlspecialchars($nip_kepala) . ')' : '' ?></td></tr>
        <tr><th>Periode RKJM</th><td><?= (int)$doc['tahun_awal'] ?>–<?= (int)$doc['tahun_akhir'] ?> (<?= count($years) ?> tahun)</td></tr>
        <tr><th>Tanggal Penyusunan</th><td><?= $doc['tanggal_penyusunan'] ? htmlspecialchars(date('d F Y', strtotime($doc['tanggal_penyusunan']))) : '-' ?></td></tr>
    </table>

    <!-- 3. Pendahuluan -->
    <h2>Pendahuluan</h2>
    <p>Rencana Kerja Jangka Menengah (RKJM) merupakan dokumen perencanaan strategis <?= htmlspecialchars($nama_madrasah) ?> yang memuat arah pengembangan dan program prioritas selama empat tahun, yakni periode <?= (int)$doc['tahun_awal'] ?>–<?= (int)$doc['tahun_akhir'] ?>. RKJM disusun berdasarkan hasil Evaluasi Diri Madrasah (EDM), analisis kekuatan dan kelemahan internal, serta peluang dan tantangan eksternal yang dihadapi madrasah dalam mewujudkan pendidikan berkualitas bagi peserta didik.</p>

    <p>Penyusunan RKJM berpijak pada amanat peraturan perundang-undangan yang berlaku, khususnya Undang-Undang Nomor 20 Tahun 2003 tentang Sistem Pendidikan Nasional dan Peraturan Menteri Agama yang mengatur penyelenggaraan pendidikan madrasah. Dokumen ini menjadi acuan utama dalam penyusunan Rencana Kerja Tahunan (RKT) dan Rencana Kegiatan dan Anggaran Madrasah (RKAM), sehingga setiap kegiatan yang direncanakan dapat terukur, terarah, dan dapat dipertanggungjawabkan kepada seluruh pemangku kepentingan.</p>

    <p>Dalam dokumen ini termuat gambaran profil dan kondisi awal madrasah, visi, misi serta tujuan pengembangan, program kerja per bidang beserta target tahunan, estimasi kebutuhan pembiayaan, dan mekanisme monitoring serta evaluasi. Program-program yang tercantum disusun secara partisipatif melalui rapat kerja yang melibatkan seluruh komponen madrasah, mulai dari pimpinan, pendidik, tenaga kependidikan, hingga Komite Madrasah sebagai representasi orang tua dan masyarakat.</p>

    <p>Madrasah menyadari bahwa perencanaan yang baik merupakan separuh dari keberhasilan. Oleh karena itu, RKJM ini tidak hanya dipandang sebagai dokumen administratif, melainkan sebagai panduan operasional yang hidup dan adaptif. Program yang telah direncanakan bersifat dinamis dan dapat disesuaikan dengan perubahan kebijakan, perkembangan kebutuhan peserta didik, kemampuan anggaran, serta perkembangan lingkungan strategis madrasah sepanjang periode <?= (int)$doc['tahun_awal'] ?>–<?= (int)$doc['tahun_akhir'] ?>.</p>

    <!-- 3a. Dasar Penyusunan -->
    <h3>Dasar Penyusunan</h3>
    <div class="rich-text"><?php
        $ds = trim((string)($doc['dasar_penyusunan'] ?? ''));
        echo $ds !== '' ? cetak_rkjm_rich($ds) : $dasar_default;
    ?></div>

    <!-- 4. Profil -->
    <h2>Profil Madrasah</h2>
    <?php
    $kondisi = trim((string)($doc['kondisi_awal'] ?? ''));
    if ($kondisi !== ''): ?>
        <div class="rich-text"><?= cetak_rkjm_rich($kondisi) ?></div>
    <?php else: ?>
        <table>
            <tr><th style="width:32%">Nama Madrasah</th><td><?= htmlspecialchars($nama_madrasah) ?></td></tr>
            <?php if ($nama_yayasan !== ''): ?>
            <tr><th>Yayasan / Penyelenggara</th><td><?= htmlspecialchars($nama_yayasan) ?></td></tr>
            <?php endif; ?>
            <tr><th>Alamat Lengkap</th><td><?= htmlspecialchars($alamat ?: '-') ?></td></tr>
            <?php if ($email_m !== ''): ?><tr><th>Email</th><td><?= htmlspecialchars($email_m) ?></td></tr><?php endif; ?>
            <?php if ($website_m !== ''): ?><tr><th>Website</th><td><?= htmlspecialchars($website_m) ?></td></tr><?php endif; ?>
            <tr><th>Kepala Madrasah</th><td><?= htmlspecialchars($nama_kepala ?: '-') ?></td></tr>
            <tr><th>Tahun Ajaran</th><td><?= htmlspecialchars($school['tahun_ajaran'] ?? '-') ?></td></tr>
        </table>
        <p class="muted" style="font-size:9pt;font-style:italic">* Isi narasi profil madrasah melalui menu Pengaturan RKJM → Kondisi Awal / Profil Singkat Madrasah.</p>
    <?php endif; ?>

    <!-- 5. Visi Misi Tujuan -->
    <h2>Visi, Misi, dan Tujuan Madrasah</h2>
    <h3>Visi</h3>
    <div class="rich-text"><?= cetak_rkjm_rich($doc['visi'] ?? '') ?></div>
    <h3>Misi</h3>
    <div class="rich-text"><?= cetak_rkjm_rich($doc['misi'] ?? '') ?></div>
    <h3>Tujuan Pengembangan</h3>
    <div class="rich-text"><?= cetak_rkjm_rich($doc['tujuan_pengembangan'] ?? '') ?></div>

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
            <td style="width:50%"><?= htmlspecialchars($kota !== '' ? $kota : '....................') ?>, <?= $doc['tanggal_penyusunan'] ? htmlspecialchars(date('d F Y', strtotime($doc['tanggal_penyusunan']))) : '........................' ?><br>Kepala Madrasah</td>
        </tr>
        <tr>
            <td class="qr-cell"><img src="<?= htmlspecialchars($qr_komite_url, ENT_QUOTES, 'UTF-8') ?>" alt="QR Tanda Tangan Ketua Komite" class="qr-sign"></td>
            <td class="qr-cell"><img src="<?= htmlspecialchars($qr_kepala_url, ENT_QUOTES, 'UTF-8') ?>" alt="QR Tanda Tangan Kepala Madrasah" class="qr-sign"></td>
        </tr>
        <tr>
            <td><strong><?= htmlspecialchars($nama_ketua_komite !== '' ? $nama_ketua_komite : '(.............................................)') ?></strong></td>
            <td><strong><?= htmlspecialchars($nama_kepala ?: '(.............................................)') ?></strong></td>
        </tr>
    </table>
</div>
</body>
</html>
