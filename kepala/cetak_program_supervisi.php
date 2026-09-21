<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$school_profile = getSchoolProfile($pdo);
$periode = sv_periode($pdo);

$filter_ta = trim((string)($_GET['tahun_ajaran'] ?? $periode['tahun_ajaran']));

$rows = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM tb_sv_program WHERE tahun_ajaran = ? ORDER BY jenis_supervisi ASC, kode_program ASC, nama_program ASC");
    $stmt->execute([$filter_ta]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rows = [];
}

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
$tempat = (string)($school_profile['tempat_jadwal'] ?? 'Tempat');
$months = ['January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret', 'April' => 'April', 'May' => 'Mei', 'June' => 'Juni', 'July' => 'Juli', 'August' => 'Agustus', 'September' => 'September', 'October' => 'Oktober', 'November' => 'November', 'December' => 'Desember'];
$tanggal = $tempat . ', ' . date('d') . ' ' . $months[date('F')] . ' ' . date('Y');
$qr_content = "Ditandatangani secara elektronik oleh:\n" . $kepala_madrasah . "\nKepala Madrasah\nTanggal: " . date('d F Y');
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=" . urlencode($qr_content);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Cetak Program Supervisi - <?= htmlspecialchars(str_replace('/', '-', $filter_ta)) ?></title>
<style>
@page { size: 330mm 215mm; margin: 8mm 10mm; } /* F4 Landscape */
@media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } .no-print { display: none !important; } }
body { font-family: Arial, sans-serif; font-size: 9px; margin: 15px; }
.header { display: flex; align-items: center; justify-content: center; position: relative; padding-bottom: 6px; border-bottom: 2px solid #000; margin-bottom: 12px; }
.header img { position: absolute; left: 0; top: 0; height: 60px; }
.header-text { text-align: center; width: 100%; }
.header-text h2 { margin: 0; font-size: 14px; }
.header-text h1 { margin: 0; font-size: 16px; }
.header-text p { margin: 1px 0; font-size: 10px; }
.title { text-align: center; font-weight: bold; font-size: 13px; text-decoration: underline; margin: 8px 0 10px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
th, td { border: 1px solid #000; padding: 3px 4px; vertical-align: top; font-size: 8px; }
th { background: #f0f0f0; text-align: center; font-weight: bold; }
.text-center { text-align: center; }
.bold { font-weight: bold; }
.ttd-box { margin-top: 18px; display: flex; justify-content: flex-end; page-break-inside: avoid; }
.ttd-item { text-align: center; width: 280px; }
.ttd-space { height: 65px; }
.small { font-size: 7px; color: #555; }
.print-btn { position: fixed; top: 16px; right: 16px; padding: 8px 16px; background: #007bff; color: #fff; border: none; border-radius: 4px; cursor: pointer; z-index: 9999; }
</style>
</head>
<body>
<button class="print-btn no-print" onclick="window.print()">Cetak / Simpan PDF</button>

<div class="header">
    <?php if ($logo_path): ?><img src="<?= htmlspecialchars($logo_path) ?>" alt="Logo"><?php endif; ?>
    <div class="header-text">
        <?php if ($foundation_name !== ''): ?><h2><?= htmlspecialchars($foundation_name) ?></h2><?php endif; ?>
        <h1><?= htmlspecialchars($school_name) ?></h1>
        <?php if ($school_address !== ''): ?><p><?= htmlspecialchars($school_address) ?></p><?php endif; ?>
        <?php if ($email !== '' || $website !== ''): ?><p><?= $email !== '' ? 'Email: ' . htmlspecialchars($email) : '' ?><?= ($email !== '' && $website !== '') ? ' | ' : '' ?><?= $website !== '' ? 'Website: ' . htmlspecialchars($website) : '' ?></p><?php endif; ?>
    </div>
</div>

<div class="title">PROGRAM SUPERVISI</div>
<p class="text-center" style="margin:0 0 8px;">Tahun Ajaran <?= htmlspecialchars($filter_ta) ?></p>

<table>
    <thead>
        <tr>
            <th width="3%">No</th>
            <th>Kode</th>
            <th>Tahun Ajaran</th>
            <th>Semester</th>
            <th>Jenis</th>
            <th>Nama Program</th>
            <th>Tujuan</th>
            <th>Sasaran</th>
            <th>Fokus</th>
            <th>Target</th>
            <th>Indikator</th>
            <th>Waktu</th>
            <th>Penanggung Jawab</th>
            <th>Keterangan</th>
        </tr>
    </thead>
    <tbody>
        <?php $no = 1; foreach ($rows as $r): ?>
        <tr>
            <td class="text-center"><?= $no++ ?></td>
            <td><?= htmlspecialchars((string)$r['kode_program']) ?></td>
            <td><?= htmlspecialchars((string)$r['tahun_ajaran']) ?></td>
            <td><?= htmlspecialchars((string)$r['semester']) ?></td>
            <td><?= htmlspecialchars((string)$r['jenis_supervisi']) ?></td>
            <td><?= htmlspecialchars((string)$r['nama_program']) ?></td>
            <td><?= nl2br(htmlspecialchars((string)$r['tujuan'])) ?></td>
            <td><?= nl2br(htmlspecialchars((string)$r['sasaran'])) ?></td>
            <td><?= nl2br(htmlspecialchars((string)$r['fokus_supervisi'])) ?></td>
            <td><?= htmlspecialchars((string)$r['target']) ?></td>
            <td><?= nl2br(htmlspecialchars((string)$r['indikator_keberhasilan'])) ?></td>
            <td><?= htmlspecialchars(sv_format_rentang($r['tanggal_mulai'] ?? null, $r['tanggal_selesai'] ?? null) ?: (string)($r['waktu_pelaksanaan'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)$r['penanggung_jawab']) ?></td>
            <td><?= htmlspecialchars((string)$r['keterangan']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="14" class="text-center">Tidak ada data</td></tr><?php endif; ?>
    </tbody>
</table>

<p class="small">Total data: <?= count($rows) ?> | Dicetak: <?= date('d-m-Y H:i') ?></p>

<div class="ttd-box">
    <div class="ttd-item">
        <p style="margin-bottom:4px"><?= htmlspecialchars($tanggal) ?></p>
        <p>Kepala Madrasah,</p>
        <div class="ttd-space"><img src="<?= htmlspecialchars($qr_url) ?>" alt="QR" style="height:65px"></div>
        <p class="bold" style="margin-bottom:0"><?= htmlspecialchars($kepala_madrasah) ?></p>
        <?php if ($nip_kepala !== '' && $nip_kepala !== '-'): ?><p style="margin-top:2px">NIP. <?= htmlspecialchars($nip_kepala) ?></p><?php endif; ?>
    </div>
</div>
</body>
</html>
