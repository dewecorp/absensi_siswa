<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['admin', 'tata_usaha', 'kepala_madrasah', 'wali', 'guru'])) {
    http_response_code(403);
    exit('Unauthorized');
}

$school_profile = getSchoolProfile($pdo);
$schoolName = $school_profile['nama_madrasah'] ?? 'MADRASAH';
$schoolAddress = $school_profile['alamat'] ?? '';
$email = $school_profile['email_madrasah'] ?? '';
$website = $school_profile['website_madrasah'] ?? '';
$academicYear = $school_profile['tahun_ajaran'] ?? '-';
$placeFallback = $school_profile['tempat_jadwal'] ?? 'Padang';

$ketuaGudep = $school_profile['nama_kepala'] ?? '-';
$ntaKetuaGudep = $school_profile['nip_kepala'] ?? '-';
$nomorGudep = '03.016';
$logoPramukaUrl = '';
$logoWosmUrl = '';
$printPlace = $placeFallback;

try {
    $settings = $pdo->query("SELECT * FROM tb_pengaturan_cetak_barung LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($settings) {
        $ketuaGudep = $settings['ketua_gudep'] ?? $ketuaGudep;
        $ntaKetuaGudep = $settings['nta_ketua_gudep'] ?? $ntaKetuaGudep;
        $printPlace = $settings['tempat_surat'] ?? $printPlace;
        $nomorGudep = trim((string)(($settings['nomor_gudep'] ?? '') ?: ($settings['gugus_depan'] ?? '03.016')));

        $lp = trim((string)($settings['logo_pramuka'] ?? ''));
        if ($lp !== '' && is_file(__DIR__ . '/../uploads/' . basename($lp))) {
            $logoPramukaUrl = '../uploads/' . basename($lp);
        }

        $lw = trim((string)($settings['logo_wosm'] ?? ''));
        if ($lw !== '' && is_file(__DIR__ . '/../uploads/' . basename($lw))) {
            $logoWosmUrl = '../uploads/' . basename($lw);
        }
    }
} catch (Exception $e) {
}
$printDate = date('d-m-Y');

$pembina_rows = [];
try {
    $stmt = $pdo->query("
        SELECT p.nama_pembina, p.jabatan,
               g.nama_guru,
               GROUP_CONCAT(t.nama_tingkat SEPARATOR ', ') as nama_tingkat
        FROM tb_pembina_pramuka p
        LEFT JOIN tb_guru g ON g.id_guru = p.id_guru
        LEFT JOIN tb_pembina_tingkat pt ON pt.id_pembina_pramuka = p.id_pembina_pramuka
        LEFT JOIN tb_tingkat_barung t ON t.id_tingkat_barung = pt.id_tingkat_barung
        GROUP BY p.id_pembina_pramuka
        ORDER BY COALESCE(g.nama_guru, p.nama_pembina) ASC
    ");
    $pembina_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pembina_rows = [];
}

$qrContent = "Dokumen Sah: " . $schoolName . "\nKetua Gudep: " . $ketuaGudep . "\nNTA: " . $ntaKetuaGudep;
$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=" . urlencode($qrContent);
$title = 'Data Pembina Pramuka-' . $academicYear;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
<style>
@page { size: 210mm 330mm portrait; margin: 10mm 15mm 10mm 10mm; }
* { box-sizing: border-box; }
html, body { width: 100%; }
body { font-family: Arial, sans-serif; font-size: 11pt; margin: 0; padding: 0; }
table.kop-header-pramuka { width: 100%; border-collapse: collapse; border-bottom: 2px solid #000; margin-bottom: 12px; padding-bottom: 6px; }
table.kop-header-pramuka td { border: none !important; padding: 0 !important; vertical-align: middle; }
td.kop-logo-left { width: 80px; text-align: left; }
td.kop-logo-left img { height: 65px; width: auto; max-width: 80px; object-fit: contain; }
td.kop-logo-right { width: 80px; text-align: right; }
td.kop-logo-right img { height: 65px; width: auto; max-width: 80px; object-fit: contain; }
td.kop-text { text-align: center; }
td.kop-text h2 { margin: 0; font-size: 12pt; font-weight: bold; color: #000; letter-spacing: 0.3px; }
td.kop-text h1 { margin: 2px 0; font-size: 13.5pt; font-weight: bold; color: #000; letter-spacing: 0.3px; }
td.kop-text p.alamat { margin: 2px 0; font-size: 9.5pt; color: #000; }
td.kop-text p.kontak { margin: 2px 0 0; font-size: 9pt; color: #000; }
.title { text-align: center; font-weight: bold; font-size: 13pt; text-decoration: underline; margin: 14px 0 2px; text-transform: uppercase; }
.subtitle { text-align: center; font-size: 10pt; color: #333; margin-bottom: 15px; }
table.data-table { border-collapse: collapse; width: 100%; margin-bottom: 20px; font-size: 11pt; table-layout: fixed; }
table.data-table th, table.data-table td { border: 1px solid #000; padding: 7px 6px; text-align: left; font-size: 11pt; line-height: 1.35; overflow-wrap: break-word; word-wrap: break-word; }
table.data-table th { background-color: #f2f2f2; text-align: center !important; font-weight: bold; }
.signature-container { margin-top: 40px; float: right; text-align: left; width: 280px; page-break-inside: avoid; }
.signature-header { text-align: left; margin-bottom: 5px; }
.signature-space { height: 90px; display: flex; align-items: flex-end; justify-content: flex-start; margin-bottom: 5px; }
.qr-code { height: 80px; width: 80px; margin-right: 10px; }
.signature-info { text-align: left; }
@media print { .no-print { display: none; } }
.print-btn { position: fixed; top: 20px; right: 20px; padding: 10px 20px; background: #007bff; color: #fff; border: none; border-radius: 5px; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.2); z-index: 9999; font-size: 14px; }
.print-btn:hover { background: #0056b3; }
</style>
</head>
<body>
<button type="button" class="print-btn no-print" onclick="window.print()">Cetak / Simpan PDF</button>

<table class="kop-header-pramuka">
  <tr>
    <td class="kop-logo-left">
      <?php if (!empty($logoPramukaUrl)): ?>
        <img src="<?php echo htmlspecialchars($logoPramukaUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo Pramuka">
      <?php endif; ?>
    </td>
    <td class="kop-text">
      <h2>GERAKAN PRAMUKA GUGUS DEPAN <?php echo htmlspecialchars(strtoupper($nomorGudep), ENT_QUOTES, 'UTF-8'); ?></h2>
      <h1>BERPANGKALAN PADA <?php echo htmlspecialchars(strtoupper($schoolName), ENT_QUOTES, 'UTF-8'); ?></h1>
      <?php if ($schoolAddress !== ''): ?>
        <p class="alamat"><?php echo htmlspecialchars($schoolAddress, ENT_QUOTES, 'UTF-8'); ?></p>
      <?php endif; ?>
      <?php if ($website !== '' || $email !== ''): ?>
        <p class="kontak">
          <?php echo $website !== '' ? 'Website: <span style="color:#00f;text-decoration:underline;">' . htmlspecialchars($website, ENT_QUOTES, 'UTF-8') . '</span>' : ''; ?>
          <?php echo ($website !== '' && $email !== '') ? '&nbsp;&nbsp;&nbsp;&nbsp;' : ''; ?>
          <?php echo $email !== '' ? 'email: <span style="color:#00f;text-decoration:underline;">' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</span>' : ''; ?>
        </p>
      <?php endif; ?>
    </td>
    <td class="kop-logo-right">
      <?php if (!empty($logoWosmUrl)): ?>
        <img src="<?php echo htmlspecialchars($logoWosmUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo WOSM">
      <?php endif; ?>
    </td>
  </tr>
</table>

<div class="title">DATA PEMBINA PRAMUKA</div>
<?php if ($academicYear !== ''): ?>
<div class="subtitle">Tahun Ajaran <?php echo htmlspecialchars($academicYear, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<table class="data-table">
    <colgroup>
        <col style="width:8%;">
        <col style="width:37%;">
        <col style="width:25%;">
        <col style="width:30%;">
    </colgroup>
    <thead>
        <tr>
            <th style="text-align:center;">No</th>
            <th style="text-align:center;">Nama Pembina</th>
            <th style="text-align:center;">Jabatan</th>
            <th style="text-align:center;">Pembina Tingkat</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($pembina_rows)): ?>
        <tr><td colspan="4" style="text-align:center;">Tidak ada data.</td></tr>
        <?php else: ?>
            <?php foreach ($pembina_rows as $idx => $row): ?>
            <?php $nama_tampil = trim((string)($row['nama_guru'] ?? '')) !== '' ? (string)$row['nama_guru'] : (string)($row['nama_pembina'] ?? ''); ?>
            <tr>
                <td style="text-align:center;"><?php echo (int)($idx + 1); ?></td>
                <td><?php echo htmlspecialchars($nama_tampil, ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string)($row['jabatan'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string)($row['nama_tingkat'] ?? '') !== '' ? (string)$row['nama_tingkat'] : '-', ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<div class="signature-container">
    <div class="signature-header">
        <p><?php echo htmlspecialchars($printPlace, ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars($printDate, ENT_QUOTES, 'UTF-8'); ?></p>
        <p>Ketua Gudep,</p>
    </div>
    <div class="signature-space">
        <img src="<?php echo htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8'); ?>" class="qr-code">
    </div>
    <div class="signature-info">
        <p><strong><?php echo htmlspecialchars($ketuaGudep, ENT_QUOTES, 'UTF-8'); ?></strong></p>
        <p>NTA. <?php echo htmlspecialchars($ntaKetuaGudep, ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
</div>
</body>
</html>
