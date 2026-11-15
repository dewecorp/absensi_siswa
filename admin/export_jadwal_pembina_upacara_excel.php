<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$session_type = sanitizeInput((string)($_GET['session_type'] ?? ''));
$allowed = ['admin'];
if (in_array($session_type, ['kepala_madrasah', 'tata_usaha', 'guru', 'wali'], true)) {
    $allowed[] = $session_type;
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_jadwal_pembina_upacara (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tanggal DATE NOT NULL,
        id_guru INT NOT NULL,
        keterangan VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_tanggal (tanggal),
        KEY idx_id_guru (id_guru)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$rows = $pdo->query("
    SELECT j.*, g.nama_guru 
    FROM tb_jadwal_pembina_upacara j 
    JOIN tb_guru g ON j.id_guru = g.id_guru 
    ORDER BY j.tanggal ASC, j.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$filename = 'jadwal_pembina_upacara_' . date('Ymd_His') . '.xls';
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
        <x:Name>Pembina Upacara</x:Name>
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
    <tr><td colspan="4" class="title-yayasan"><?= htmlspecialchars($yayasan) ?></td></tr>
    <?php endif; ?>
    <tr><td colspan="4" class="title-madrasah"><?= htmlspecialchars($madrasah) ?></td></tr>
    <tr><td colspan="4" class="title-alamat"><?= htmlspecialchars($alamat) ?><?= $telp ? ' | Telp: ' . htmlspecialchars($telp) : '' ?></td></tr>
    <tr><td colspan="4"></td></tr>
    <tr><td colspan="4" class="doc-title">JADWAL PEMBINA UPACARA</td></tr>
    <tr><td colspan="4" class="text-center" style="font-size:10pt;">Tahun Ajaran: <?= htmlspecialchars($ta) ?></td></tr>
    <tr><td colspan="4"></td></tr>
</table>

<table class="data-table">
    <thead>
        <tr>
            <th style="width: 50px;">No</th>
            <th style="width: 180px;">Hari, Tanggal</th>
            <th style="width: 280px;">Nama Guru</th>
            <th style="width: 220px;">Keterangan</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($rows)): ?>
        <tr><td colspan="4" class="text-center" style="padding: 10px;">Belum ada data jadwal pembina upacara.</td></tr>
        <?php else: ?>
        <?php foreach ($rows as $i => $r): ?>
        <?php
            $hari_name = getHariIndonesia($r['tanggal']);
            $tgl_name = formatDateIndonesia($r['tanggal']);
        ?>
        <tr class="<?= $i % 2 === 1 ? 'bg-alt' : '' ?>">
            <td class="text-center"><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($hari_name . ', ' . $tgl_name) ?></td>
            <td><?= htmlspecialchars($r['nama_guru']) ?></td>
            <td><?= htmlspecialchars($r['keterangan'] ?: '-') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
</body>
</html>
