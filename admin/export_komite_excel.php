<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$session_type = sanitizeInput((string)($_GET['session_type'] ?? ''));
$allowed = ['admin'];
if ($session_type === 'kepala_madrasah') {
    $allowed[] = 'kepala_madrasah';
}
if (!isAuthorized($allowed)) {
    redirect('../login.php');
}

$school_profile = getSchoolProfile($pdo);
$madrasah = strtoupper($school_profile['nama_madrasah'] ?? 'MADRASAH');
$yayasan  = strtoupper($school_profile['nama_yayasan'] ?? '');
$alamat   = $school_profile['alamat'] ?? '';
$telp     = $school_profile['telepon'] ?? '';
$ta       = $school_profile['tahun_ajaran'] ?? '-';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_komite_madrasah (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        nama VARCHAR(150) NOT NULL,
        jabatan VARCHAR(100) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$rows = $pdo->query("SELECT * FROM tb_komite_madrasah ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$filename = 'komite_madrasah_' . date('Ymd_His') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<!--[if gte mso 9]>
<xml>
  <x:ExcelWorkbook>
    <x:ExcelWorksheets>
      <x:ExcelWorksheet>
        <x:Name>Komite Madrasah</x:Name>
        <x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
      </x:ExcelWorksheet>
    </x:ExcelWorksheets>
  </x:ExcelWorkbook>
</xml>
<![endif]-->
<style>
  body { font-family: Arial, sans-serif; font-size: 11pt; }
  .title-yayasan { font-size: 11pt; font-weight: bold; text-align: center; }
  .title-madrasah { font-size: 14pt; font-weight: bold; text-align: center; }
  .title-alamat { font-size: 9pt; text-align: center; color: #333; }
  .doc-title { font-size: 12pt; font-weight: bold; text-align: center; }
  table.data-table { border-collapse: collapse; width: 100%; margin-top: 10px; }
  table.data-table th { background-color: #2563eb; color: #ffffff; font-weight: bold; text-align: center; border: 0.5pt solid #000; padding: 6px; }
  table.data-table td { border: 0.5pt solid #000; padding: 5px; vertical-align: middle; }
  .text-center { text-align: center; }
  .bg-alt { background-color: #f2f5fc; }
</style>
</head>
<body>
<table>
    <?php if ($yayasan): ?>
    <tr><td colspan="3" class="title-yayasan"><?= htmlspecialchars($yayasan) ?></td></tr>
    <?php endif; ?>
    <tr><td colspan="3" class="title-madrasah"><?= htmlspecialchars($madrasah) ?></td></tr>
    <tr><td colspan="3" class="title-alamat"><?= htmlspecialchars($alamat) ?><?= $telp ? ' | Telp: ' . htmlspecialchars($telp) : '' ?></td></tr>
    <tr><td colspan="3"></td></tr>
    <tr><td colspan="3" class="doc-title">PENGURUS KOMITE MADRASAH</td></tr>
    <tr><td colspan="3" class="text-center" style="font-size:10pt;">Tahun Ajaran: <?= htmlspecialchars($ta) ?></td></tr>
    <tr><td colspan="3"></td></tr>
</table>

<table class="data-table">
    <thead>
        <tr>
            <th style="width: 50px;">No</th>
            <th style="width: 320px;">Nama</th>
            <th style="width: 220px;">Jabatan</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($rows)): ?>
        <tr><td colspan="3" class="text-center" style="padding: 10px;">Belum ada data anggota komite.</td></tr>
        <?php else: ?>
        <?php foreach ($rows as $i => $r): ?>
        <tr class="<?= $i % 2 === 1 ? 'bg-alt' : '' ?>">
            <td class="text-center"><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($r['nama']) ?></td>
            <td class="text-center"><?= htmlspecialchars($r['jabatan']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
</body>
</html>
