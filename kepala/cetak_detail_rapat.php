<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/agenda.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isAuthorized(['kepala_madrasah', 'admin', 'tata_usaha', 'guru', 'wali'])) {
    redirect('../login.php');
}

ensureAgendaTables($pdo);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    die('ID Rapat tidak valid.');
}

$stmt = $pdo->prepare("SELECT * FROM tb_agenda_rapat WHERE id_rapat = ? LIMIT 1");
$stmt->execute([$id]);
$rapat = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rapat) {
    die('Data agenda rapat tidak ditemukan.');
}

$school_profile = getSchoolProfile($pdo);

$school_name = strtoupper((string)($school_profile['nama_madrasah'] ?? 'MADRASAH'));
$foundation_name = strtoupper((string)($school_profile['nama_yayasan'] ?? ''));
$school_address = (string)($school_profile['alamat'] ?? '');
$email = (string)($school_profile['email_madrasah'] ?? '');
$website = (string)($school_profile['website_madrasah'] ?? '');
$kepala_madrasah = (string)($school_profile['nama_kepala'] ?? $school_profile['kepala_madrasah'] ?? '-');
$nip_kepala = (string)($school_profile['nip_kepala'] ?? '-');
$ta = (string)($school_profile['tahun_ajaran'] ?? '');
$sem = (string)($school_profile['semester'] ?? '');

$logo_path = '../assets/img/' . basename((string)($school_profile['logo'] ?? 'logo.png'));
if (!is_readable(__DIR__ . '/' . $logo_path)) {
    $logo_path = '../assets/img/logo.png';
    if (!is_readable(__DIR__ . '/' . $logo_path)) {
        $cand = glob(__DIR__ . '/../assets/img/logo_*.png') ?: [];
        $logo_path = $cand ? '../assets/img/' . basename($cand[0]) : '';
    }
}

$tempat_cetak = (string)($school_profile['tempat_jadwal'] ?? 'Padang');
if ($tempat_cetak === '') $tempat_cetak = 'Padang';

$months = ['January'=>'Januari','February'=>'Februari','March'=>'Maret','April'=>'April','May'=>'Mei','June'=>'Juni','July'=>'Juli','August'=>'Agustus','September'=>'September','October'=>'Oktober','November'=>'November','December'=>'Desember'];
$tanggal_cetak = $tempat_cetak . ', ' . date('d') . ' ' . ($months[date('F')] ?? date('F')) . ' ' . date('Y');

$hari_tanggal_indo = formatHariTanggalIndo($rapat['hari_tanggal']);

$pemimpin = !empty($rapat['pemimpin_rapat']) ? $rapat['pemimpin_rapat'] : $kepala_madrasah;
$qr_content = "Dokumen Agenda & Notulensi Rapat\n" . $rapat['nama_rapat'] . "\nTanggal: " . $hari_tanggal_indo . "\nKepala: " . $kepala_madrasah;
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=90x90&data=" . urlencode($qr_content);

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Detail Agenda Rapat - <?= h($rapat['nama_rapat']) ?></title>
    <style>
        * {
            box-sizing: border-box;
        }
        @page {
            size: 215mm 330mm;
            size: portrait;
            margin: 12mm 15mm;
        }
        @media print {
            html, body {
                width: 100% !important;
                margin: 0 !important;
                padding: 0 4px !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .no-print {
                display: none !important;
            }
        }
        html, body {
            width: 100%;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9.5pt;
            color: #222;
            line-height: 1.45;
            background: #fff;
        }
        body {
            padding: 10mm 4px;
        }
        table.kop-header {
            width: 100%;
            border-collapse: collapse;
            border-bottom: 3px double #000;
            margin-bottom: 12px;
            padding-bottom: 6px;
        }
        table.kop-header td {
            border: none !important;
            padding: 0 !important;
            vertical-align: middle;
        }
        td.kop-logo {
            width: 70px;
            text-align: left;
        }
        td.kop-logo img {
            height: 60px;
            width: auto;
        }
        td.kop-text {
            text-align: center;
        }
        td.kop-text h2 {
            margin: 0;
            font-size: 13pt;
            font-weight: normal;
        }
        td.kop-text h1 {
            margin: 2px 0;
            font-size: 16pt;
            font-weight: bold;
        }
        td.kop-text p {
            margin: 1px 0;
            font-size: 9pt;
        }
        .title {
            text-align: center;
            font-weight: bold;
            font-size: 13pt;
            text-decoration: underline;
            margin: 12px 0 4px;
            text-transform: uppercase;
        }
        .subtitle {
            text-align: center;
            font-size: 10pt;
            color: #333;
            margin-bottom: 15px;
        }
        table.info-table {
            width: 100% !important;
            max-width: 100% !important;
            border-collapse: collapse;
            margin-bottom: 8px;
            table-layout: fixed;
        }
        table.info-table th, table.info-table td {
            border: 1px solid #333 !important;
            padding: 5px 8px;
            vertical-align: top;
            font-size: 9.5pt;
            word-wrap: break-word !important;
            overflow-wrap: anywhere !important;
            word-break: break-word !important;
        }
        table.info-table th {
            background: #f2f2f2 !important;
            text-align: left;
            width: 25%;
        }
        .section-title {
            font-weight: bold;
            font-size: 10pt;
            margin: 10px 0 4px;
            border-bottom: 1px solid #888;
            padding-bottom: 2px;
        }
        .agenda-content {
            font-size: 9.5pt;
            line-height: 1.45;
            margin-bottom: 8px;
            word-wrap: break-word !important;
            overflow-wrap: anywhere !important;
            word-break: break-word !important;
        }
        .agenda-content * {
            word-wrap: break-word !important;
            overflow-wrap: anywhere !important;
            word-break: break-word !important;
        }
        .agenda-content p {
            margin-bottom: 0.3rem;
        }
        .agenda-content ul {
            list-style-type: disc !important;
            padding-left: 20px !important;
            margin-top: 0;
            margin-bottom: 0.4rem !important;
        }
        .agenda-content ol {
            list-style-type: decimal !important;
            padding-left: 20px !important;
            margin-top: 0;
            margin-bottom: 0.4rem !important;
        }
        .agenda-content li {
            display: list-item !important;
        }
        .notulen-box {
            border: 1px dashed #666;
            border-radius: 4px;
            padding: 8px 12px;
            margin-top: 8px;
            margin-bottom: 10px;
            min-height: 275px;
        }
        .notulen-title {
            font-weight: bold;
            font-size: 8.5pt;
            color: #444;
            margin-bottom: 4px;
        }
        .ttd-wrap {
            page-break-inside: avoid;
            break-inside: avoid;
            margin-top: 10px;
        }
        .ttd-table {
            width: 100%;
            border-collapse: collapse;
        }
        .ttd-table td {
            border: none !important;
            text-align: center;
            vertical-align: top;
        }
        .print-btn {
            position: fixed;
            top: 15px;
            right: 15px;
            padding: 9px 16px;
            background: #4e73df;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            font-size: 10pt;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            z-index: 9999;
        }
        .print-btn:hover {
            background: #2e59d9;
        }
    </style>
</head>
<body>

<button class="print-btn no-print" onclick="window.print()">
    <i class="fas fa-print"></i> Cetak / Simpan PDF
</button>

<table class="kop-header">
    <tr>
        <td class="kop-logo">
            <?php if ($logo_path): ?>
                <img src="<?= h($logo_path) ?>" alt="Logo">
            <?php endif; ?>
        </td>
        <td class="kop-text">
            <?php if ($foundation_name !== ''): ?>
                <h2><?= h($foundation_name) ?></h2>
            <?php endif; ?>
            <h1><?= h($school_name) ?></h1>
            <?php if ($school_address !== ''): ?>
                <p><?= h($school_address) ?></p>
            <?php endif; ?>
            <?php if ($email !== '' || $website !== ''): ?>
                <p><?= $email !== '' ? 'Email: ' . h($email) : '' ?><?= ($email !== '' && $website !== '') ? ' | ' : '' ?><?= $website !== '' ? 'Website: ' . h($website) : '' ?></p>
            <?php endif; ?>
        </td>
    </tr>
</table>

<div class="title">AGENDA & NOTULENSI RAPAT</div>
<div class="subtitle"><?= h($rapat['nama_rapat']) ?><?= ($ta !== '' || $sem !== '') ? '<br><small style="color:#555">Tahun Ajaran ' . h($ta) . (($ta !== '' && $sem !== '') ? ' - ' : '') . h($sem) . '</small>' : '' ?></div>

<table class="info-table">
    <tr>
        <th>Nama Rapat</th>
        <td><strong><?= h($rapat['nama_rapat']) ?></strong></td>
    </tr>
    <tr>
        <th>Hari / Tanggal</th>
        <td><?= h($hari_tanggal_indo) ?></td>
    </tr>
    <tr>
        <th>Waktu</th>
        <td><?= h($rapat['waktu'] ?: '-') ?></td>
    </tr>
    <tr>
        <th>Pemimpin Rapat</th>
        <td><?= h($rapat['pemimpin_rapat'] ?: '-') ?></td>
    </tr>
    <tr>
        <th>Tempat</th>
        <td><?= h($rapat['tempat'] ?: '-') ?></td>
    </tr>
</table>

<div class="section-title">AGENDA RAPAT</div>

<div class="agenda-content">
    <?php if (!empty($rapat['agenda_rapat'])): ?>
        <?= $rapat['agenda_rapat'] ?>
    <?php else: ?>
        <em style="color:#777;">Tidak ada rincian agenda rapat.</em>
    <?php endif; ?>
</div>

<div class="section-title">HASIL NOTULENSI RAPAT</div>

<div class="notulen-box">
    <?php if (!empty($rapat['notulensi'])): ?>
        <div class="agenda-content">
            <?= $rapat['notulensi'] ?>
        </div>
    <?php else: ?>
        <div class="notulen-title">Catatan / Notulensi Manual:</div>
        <div style="height: 245px;"></div>
    <?php endif; ?>
</div>

<div class="ttd-wrap">
    <table class="ttd-table">
        <tr>
            <td style="width: 50%;"></td>
            <td style="width: 50%;">
                <p style="margin-bottom: 4px; font-size: 9.5pt;"><?= h($tanggal_cetak) ?></p>
                <p style="margin: 0 0 6px; font-size: 10pt;">Kepala Madrasah,</p>
                <img src="<?= h($qr_url) ?>" style="width: 75px; height: 75px;" alt="QR Verification">
                <div style="height: 4px;"></div>
                <p style="margin: 0; font-size: 10pt;"><strong><?= h($kepala_madrasah) ?></strong></p>
                <p style="margin: 0; font-size: 9pt;"><small>NIP. <?= h($nip_kepala) ?></small></p>
            </td>
        </tr>
    </table>
</div>

<script>
    // User klik tombol "Cetak / Simpan PDF" untuk mencetak
</script>

</body>
</html>
