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
    die('ID Agenda tidak valid.');
}

$stmt = $pdo->prepare("SELECT a.*, j.jenis_agenda 
                       FROM tb_agenda_kepala a 
                       LEFT JOIN tb_agenda_jenis j ON j.id_jenis = a.id_jenis 
                       WHERE a.id_agenda = ? LIMIT 1");
$stmt->execute([$id]);
$agenda = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$agenda) {
    die('Data agenda kepala tidak ditemukan.');
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

$hari_tanggal_indo = formatHariTanggalIndo($agenda['hari_tanggal']);

$qr_content = "Dokumen Detail Agenda Kepala\n" . $agenda['nama_agenda'] . "\nTanggal: " . $hari_tanggal_indo . "\nKepala: " . $kepala_madrasah;
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=90x90&data=" . urlencode($qr_content);

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Detail Agenda Kepala - <?= h($agenda['nama_agenda']) ?></title>
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
            margin-bottom: 15px;
            table-layout: fixed;
        }
        table.info-table th, table.info-table td {
            border: 1px solid #333 !important;
            padding: 6px 10px;
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
            font-size: 10.5pt;
            margin: 12px 0 6px;
            border-bottom: 1px solid #888;
            padding-bottom: 3px;
        }
        .agenda-content {
            font-size: 10pt;
            line-height: 1.5;
            margin-bottom: 12px;
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
            margin-bottom: 0.35rem;
        }
        .agenda-content ul {
            list-style-type: disc !important;
            padding-left: 22px !important;
            margin-top: 0;
            margin-bottom: 0.5rem !important;
        }
        .agenda-content ol {
            list-style-type: decimal !important;
            padding-left: 22px !important;
            margin-top: 0;
            margin-bottom: 0.5rem !important;
        }
        .agenda-content li {
            display: list-item !important;
        }
        .uraian-box {
            border: 1px dashed #666;
            border-radius: 4px;
            padding: 10px 14px;
            margin-top: 12px;
            margin-bottom: 15px;
            min-height: 250px;
        }
        .uraian-title {
            font-weight: bold;
            font-size: 9pt;
            color: #444;
            margin-bottom: 6px;
        }
        .ttd-wrap {
            page-break-inside: avoid;
            break-inside: avoid;
            margin-top: 20px;
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

<div class="title">DETAIL AGENDA KEPALA MADRASAH</div>
<div class="subtitle"><?= h($agenda['nama_agenda']) ?><?= ($ta !== '' || $sem !== '') ? '<br><small style="color:#555">Tahun Ajaran ' . h($ta) . (($ta !== '' && $sem !== '') ? ' - ' : '') . h($sem) . '</small>' : '' ?></div>

<table class="info-table">
    <tr>
        <th>Nama Agenda</th>
        <td><strong><?= h($agenda['nama_agenda']) ?></strong></td>
    </tr>
    <?php if (!empty($agenda['jenis_agenda'])): ?>
    <tr>
        <th>Jenis Agenda</th>
        <td><?= h($agenda['jenis_agenda']) ?></td>
    </tr>
    <?php endif; ?>
    <tr>
        <th>Hari / Tanggal</th>
        <td><?= h($hari_tanggal_indo) ?></td>
    </tr>
    <tr>
        <th>Waktu</th>
        <td><?= h($agenda['waktu'] ?: '-') ?></td>
    </tr>
    <tr>
        <th>Tempat</th>
        <td><?= h($agenda['tempat'] ?: '-') ?></td>
    </tr>
    <?php if (!empty($agenda['keterangan'])): ?>
    <tr>
        <th>Keterangan</th>
        <td><?= h($agenda['keterangan']) ?></td>
    </tr>
    <?php endif; ?>
</table>

<div class="section-title">URAIAN KEGIATAN AGENDA</div>

<div class="uraian-box">
    <?php if (!empty($agenda['uraian_kegiatan'])): ?>
        <div class="agenda-content">
            <?= $agenda['uraian_kegiatan'] ?>
        </div>
    <?php else: ?>
        <div class="uraian-title">Catatan / Uraian Manual:</div>
        <div style="height: 200px;"></div>
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
