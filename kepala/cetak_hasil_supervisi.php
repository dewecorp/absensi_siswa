<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';
sv_ensure_schema($pdo);
sv_require_access($pdo);
$id = (int)($_GET['id'] ?? 0);
$data = sv_detail_pelaksanaan($pdo, $id);
if (!$data) {
    sv_flash('warning', 'Data supervisi tidak ditemukan.');
    redirect('hasil_supervisi.php');
}
$school_profile = getSchoolProfile($pdo);
$periode = sv_periode($pdo);
$school_name = strtoupper((string)($school_profile['nama_madrasah'] ?? 'MADRASAH'));
$foundation_name = strtoupper((string)($school_profile['nama_yayasan'] ?? ''));
$school_address = (string)($school_profile['alamat'] ?? '');
$email = (string)($school_profile['email_madrasah'] ?? '');
$website = (string)($school_profile['website_madrasah'] ?? '');
$kepala_madrasah = (string)($school_profile['nama_kepala'] ?? $school_profile['kepala_madrasah'] ?? '-');
$nip_kepala = (string)($school_profile['nip_kepala'] ?? '-');
$logo_path = '../assets/img/' . basename((string)($school_profile['logo'] ?? 'logo.png'));
if (!is_readable(__DIR__ . '/' . $logo_path)) {
    $logo_path = '../assets/img/logo.png';
    if (!is_readable(__DIR__ . '/' . $logo_path)) {
        $cand = glob(__DIR__ . '/../assets/img/logo_*.png') ?: [];
        $logo_path = $cand ? '../assets/img/' . basename($cand[0]) : '';
    }
}
$tempat = (string)($school_profile['tempat_jadwal'] ?? 'Padang');
if ($tempat === '') $tempat = 'Padang';
$months = ['January'=>'Januari','February'=>'Februari','March'=>'Maret','April'=>'April','May'=>'Mei','June'=>'Juni','July'=>'Juli','August'=>'Agustus','September'=>'September','October'=>'Oktober','November'=>'November','December'=>'Desember'];
$rawTanggalCetak = sv_tanggal_cetak($pdo, 'hasil');
$tsTanggalCetak = strtotime($rawTanggalCetak);
$tanggal = $tempat . ', ' . date('d', $tsTanggalCetak) . ' ' . ($months[date('F', $tsTanggalCetak)] ?? date('F', $tsTanggalCetak)) . ' ' . date('Y', $tsTanggalCetak);
$penilaian = sv_penilaian_rows($pdo, $id);
$manajerial = sv_manajerial_rows($pdo, $id);
$tindak_lanjut = sv_tindak_lanjut_by_pelaksanaan($pdo, $id);
$arsip = sv_arsip_by_pelaksanaan($pdo, $id);
$is_manajerial = ($data['jenis_supervisi'] === 'Manajerial');
$nama = $is_manajerial ? ($data['unit_bagian'] ?: '-') : ($data['nama_guru'] ?: '-');
$guruInfo = null;
if (!$is_manajerial && !empty($data['id_guru'])) {
    try { $st=$pdo->prepare("SELECT id_guru, nama_guru, nuptk FROM tb_guru WHERE id_guru=? LIMIT 1"); $st->execute([(int)$data['id_guru']]); $guruInfo=$st->fetch(PDO::FETCH_ASSOC)?:null; } catch(Throwable $e){}
}
$sasaranRow=null;
if (!$is_manajerial && !empty($data['id_guru'])) {
    try { $st=$pdo->prepare("SELECT jabatan, kelas FROM tb_sv_sasaran WHERE id_guru=? ORDER BY id_sasaran DESC LIMIT 1"); $st->execute([(int)$data['id_guru']]); $sasaranRow=$st->fetch(PDO::FETCH_ASSOC)?:null; } catch(Throwable $e){}
}
$qrGuruUrl='https://api.qrserver.com/v1/create-qr-code/?size=90x90&data='.urlencode('Ditandatangani oleh: '.$nama.' | '.$tanggal);
$qrKepalaUrl='https://api.qrserver.com/v1/create-qr-code/?size=90x90&data='.urlencode('Ditandatangani oleh: '.$kepala_madrasah.' | Kepala Madrasah | '.$tanggal);
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Cetak Hasil Supervisi - <?= h($nama) ?></title>
<style>
@page { size: A4 portrait; margin: 12mm 14mm; }
@media print { body{-webkit-print-color-adjust:exact;print-color-adjust:exact} .no-print{display:none!important} }
body{font-family:Arial,sans-serif;font-size:9pt;color:#222;line-height:1.45;margin:14px}
.header{display:flex;align-items:center;position:relative;border-bottom:2px solid #000;padding-bottom:8px;margin-bottom:12px}
.header img{height:60px;margin-right:14px}
.header-text{flex:1;text-align:center}
.header-text h2{margin:0;font-size:14pt}
.header-text h1{margin:0;font-size:16pt}
.header-text p{margin:1px 0;font-size:9pt}
.title{text-align:center;font-weight:bold;font-size:12pt;text-decoration:underline;margin:10px 0 4px}
.subtitle{text-align:center;font-size:9pt;color:#444;margin-bottom:10px}
h4{margin:14px 0 6px;font-size:10pt;border-bottom:1px solid #999;padding-bottom:3px}
table{width:100%;border-collapse:collapse;margin-bottom:10px}
th,td{border:1px solid #555;padding:4px 6px;vertical-align:top;font-size:8.5pt}
th{background:#eee;text-align:center}
.table-borderless th,.table-borderless td{border:none;padding:2px 6px;text-align:left}
.table-borderless th{width:32%;background:transparent}
.badge{display:inline-block;padding:2px 7px;border-radius:10px;font-size:8pt;color:#fff}
.badge-info{background:#3abaf4}
.ttd{margin-top:18px;width:100%}
.ttd td{border:none;text-align:center;vertical-align:top}
.print-btn{position:fixed;top:14px;right:14px;padding:8px 14px;background:#6777ef;color:#fff;border:none;border-radius:4px;cursor:pointer;z-index:999}
.small{font-size:7.5pt;color:#555}
</style>
</head>
<body>
<button class="print-btn no-print" onclick="window.print()">Cetak / Simpan PDF</button>
<div class="header">
<?php if ($logo_path): ?><img src="<?= h($logo_path) ?>" alt="Logo"><?php endif; ?>
<div class="header-text">
<?php if ($foundation_name !== ''): ?><h2><?= h($foundation_name) ?></h2><?php endif; ?>
<h1><?= h($school_name) ?></h1>
<?php if ($school_address !== ''): ?><p><?= h($school_address) ?></p><?php endif; ?>
<?php if ($email !== '' || $website !== ''): ?><p><?= $email!==''?'Email: '.h($email):'' ?><?= ($email!==''&&$website!=='')?' | ':'' ?><?= $website!==''?'Website: '.h($website):'' ?></p><?php endif; ?>
</div>
</div>
<div class="title">DETAIL HASIL SUPERVISI</div>
<div class="subtitle"><?= h($periode['tahun_ajaran']) ?> &middot; <?= h($periode['semester']) ?> &middot; <?= h($nama) ?></div>

<h4>Identitas Guru yang Disupervisi</h4>
<table class="table-borderless">
<tr><th>Nama Guru</th><td><?= h($nama) ?></td></tr>
<tr><th>NIP / NPK</th><td><?= h((string)($guruInfo['nuptk'] ?? $data['nama_guru'] ?? '-')) ?></td></tr>
<tr><th>Jabatan</th><td><?= h((string)($sasaranRow['jabatan'] ?? '-')) ?></td></tr>
<tr><th>Mapel yang Disupervisi</th><td><?= h((string)($data['mapel_di_supervisi'] ?? '-')) ?></td></tr>
<tr><th>Kelas</th><td><?= h((string)($sasaranRow['kelas'] ?? '-')) ?></td></tr>
<?php if ($is_manajerial): ?><tr><th>Unit / Bagian</th><td><?= h((string)$data['unit_bagian']) ?></td></tr><tr><th>Penanggung Jawab</th><td><?= h((string)$data['penanggung_jawab']) ?></td></tr><?php endif; ?>
<tr><th>Jenis Supervisi</th><td><?= h($data['jenis_supervisi']) ?></td></tr>
<tr><th>Tanggal Supervisi</th><td><?= $data['tanggal']?date('d F Y', strtotime($data['tanggal'])):'-' ?></td></tr>
<tr><th>Supervisor</th><td><?= h((string)$data['supervisor']) ?></td></tr>
<tr><th>Instrumen</th><td><?= h((string)($data['nama_instrumen'] ?: $data['kode_instrumen'] ?? '-')) ?></td></tr>
<tr><th>Nilai / Predikat</th><td><?= $data['nilai']!==null?h(number_format((float)$data['nilai'],2)):'-' ?> (<?= h((string)($data['predikat'] ?? '-')) ?>)</td></tr>
<tr><th>Status</th><td><?= h($data['status']) ?></td></tr>
</table>

<h4>Uraian Hasil</h4>
<p><strong>Kekuatan:</strong><br><?= nl2br(h((string)$data['kekuatan'])) ?: '-' ?></p>
<p><strong>Kelemahan:</strong><br><?= nl2br(h((string)$data['kelemahan'])) ?: '-' ?></p>
<p><strong>Rekomendasi:</strong><br><?= nl2br(h((string)$data['rekomendasi'])) ?: '-' ?></p>
<p><strong>Prioritas Perbaikan:</strong> <?= h((string)$data['prioritas_perbaikan']) ?: '-' ?></p>

<h4>Seluruh Indikator</h4>
<table>
<thead><tr><th width="5%">No</th><th>Komponen</th><th>Indikator</th><th>Bobot</th><th>Skor</th><th>Nilai</th><th>Catatan</th></tr></thead>
<tbody>
<?php if ($is_manajerial): foreach ($manajerial as $i=>$m): ?>
<tr><td class="small" style="text-align:center"><?= $i+1 ?></td><td><?= h((string)$m['unit_bagian']) ?></td><td><?= h((string)$m['indikator']) ?></td><td>-</td><td><?= h(number_format((float)$m['skor'],2)) ?></td><td><?= h(number_format((float)$m['skor'],2)) ?></td><td><?php if($m['target']!=='') echo '<small>Target: '.h((string)$m['target']).'</small><br>'; if($m['realisasi']!=='') echo '<small>Realisasi: '.h((string)$m['realisasi']).'</small><br>'; if($m['temuan']!=='') echo '<small>Temuan: '.h((string)$m['temuan']).'</small><br>'; if($m['kendala']!=='') echo '<small>Kendala: '.h((string)$m['kendala']).'</small><br>'; if($m['rekomendasi']!=='') echo '<small>Rekomendasi: '.h((string)$m['rekomendasi']).'</small>'; ?></td></tr>
<?php endforeach; elseif ($penilaian): foreach ($penilaian as $i=>$p): ?>
<tr><td style="text-align:center"><?= $i+1 ?></td><td><?= h((string)$p['komponen']) ?></td><td><?= h((string)$p['indikator']) ?></td><td><?= h(number_format((float)$p['bobot'],2)) ?></td><td><?= h(number_format((float)$p['skor'],2)) ?></td><td><?= h(number_format((float)$p['nilai'],2)) ?></td><td><?= h((string)$p['catatan']) ?></td></tr>
<?php endforeach; else: ?><tr><td colspan="7" style="text-align:center" class="small">Tidak ada detail penilaian.</td></tr><?php endif; ?>
</tbody>
</table>

<?php if ($tindak_lanjut): ?>
<h4>Tindak Lanjut</h4>
<table><thead><tr><th>Bentuk</th><th>Rencana</th><th>Status</th></tr></thead><tbody>
<?php foreach ($tindak_lanjut as $t): ?><tr><td><?= h((string)$t['bentuk_tindak_lanjut']) ?></td><td><?= h((string)$t['rencana_tindakan']) ?></td><td><?= h($t['status']) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>

<?php if ($arsip): ?>
<h4>Arsip / Bukti</h4>
<table><thead><tr><th>Jenis</th><th>Nama Dokumen</th><th>File</th></tr></thead><tbody>
<?php foreach ($arsip as $a): ?><tr><td><?= h((string)$a['jenis_dokumen']) ?></td><td><?= h((string)$a['nama_dokumen']) ?></td><td><?= h($a['tautan_dokumen'] ?: $a['file'] ?: '-') ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>

<p style="text-align:right;margin:18px 0 6px"><?= h($tanggal) ?></p>
<table class="ttd"><tr>
<td><p>Yang Disupervisi,</p><img src="<?= h($qrGuruUrl) ?>" style="width:90px;height:90px" alt="QR Guru"><p><strong><?= h($nama) ?></strong></p></td>
<td><p>Kepala Madrasah,</p><img src="<?= h($qrKepalaUrl) ?>" style="width:90px;height:90px" alt="QR Kepala"><p><strong><?= h($kepala_madrasah) ?></strong><br><small>NIP. <?= h($nip_kepala) ?></small></p></td>
</tr></table>

<p class="small">Dicetak: <?= date('d-m-Y H:i') ?> | Reload aman — buka <?= h($_SERVER['REQUEST_URI'] ?? '') ?></p>
</body>
</html>
