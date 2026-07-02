<?php
require_once 'database.php';
require_once 'functions.php';

if (!isAuthorized(['admin', 'kepala_madrasah', 'kepala', 'tata_usaha'])) {
    redirect('../login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../admin/data_alumni.php');
}

$chart_image = (string)($_POST['chart_image'] ?? '');
$chart_data_raw = (string)($_POST['chart_data'] ?? '[]');
$chart_data = json_decode($chart_data_raw, true);
if (!is_array($chart_data)) {
    $chart_data = [];
}

if (strpos($chart_image, 'data:image/png;base64,') !== 0) {
    $chart_image = '';
}

$school_profile = getSchoolProfile($pdo);
$nama_madrasah = $school_profile['nama_madrasah'] ?? 'Madrasah';
$nama_yayasan = $school_profile['nama_yayasan'] ?? '';
$alamat = $school_profile['alamat'] ?? '';
$tahun_ajaran = $school_profile['tahun_ajaran'] ?? '-';
$kepala_madrasah = $school_profile['kepala_madrasah'] ?? ($school_profile['nama_kepala'] ?? '-');
$nip_kepala = $school_profile['nip_kepala'] ?? '-';
$logo = $school_profile['logo'] ?? 'logo.png';

$total_laki = 0;
$total_perempuan = 0;
$total_alumni = 0;
foreach ($chart_data as $row) {
    $total_laki += (int)($row['jumlah_laki'] ?? 0);
    $total_perempuan += (int)($row['jumlah_perempuan'] ?? 0);
    $total_alumni += (int)($row['total'] ?? 0);
}

$qr_payload = 'Validasi Laporan Grafik Alumni Akreditasi | ' . $nama_madrasah . ' | Kepala: ' . $kepala_madrasah . ' | Dicetak: ' . date('Y-m-d H:i:s');
$qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=130x130&data=' . urlencode($qr_payload);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Laporan Grafik Alumni</title>
    <style>
        @page { size: A4 landscape; margin: 10mm 12mm; }
        body { font-family: Arial, sans-serif; color: #111; font-size: 10pt; margin: 0; }
        .no-print { position: fixed; top: 12px; right: 12px; z-index: 10; }
        .print-btn { background: #dc3545; color: #fff; border: 0; border-radius: 4px; padding: 8px 14px; cursor: pointer; }
        .header { position: relative; text-align: center; border-bottom: 3px double #111; padding-bottom: 7px; margin-bottom: 12px; min-height: 74px; }
        .header img { position: absolute; left: 0; top: 0; width: 70px; height: 70px; object-fit: contain; }
        .header h3, .header h2, .header p { margin: 1px 0; }
        .header h3 { font-size: 12pt; }
        .header h2 { font-size: 16pt; }
        .title { text-align: center; margin: 8px 0 10px; }
        .title h1 { font-size: 13pt; margin: 0; text-decoration: underline; }
        .title p { margin: 4px 0 0; }
        .summary { display: table; width: 100%; margin-bottom: 10px; }
        .summary-item { display: table-cell; width: 33.33%; border: 1px solid #bbb; padding: 7px; text-align: center; }
        .summary-label { font-size: 9pt; color: #444; }
        .summary-value { font-size: 16pt; font-weight: bold; margin-top: 2px; }
        .content { display: table; width: 100%; table-layout: fixed; }
        .chart-panel { display: table-cell; width: 66%; vertical-align: top; padding-right: 10px; }
        .table-panel { display: table-cell; width: 34%; vertical-align: top; }
        .chart-box { border: 1px solid #ccc; padding: 8px; text-align: center; }
        .chart-box img { max-width: 100%; max-height: 330px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #333; padding: 5px 4px; text-align: center; }
        th { background: #e9ecef; font-weight: bold; }
        .signature { width: 280px; margin: 18px 20px 0 auto; text-align: center; page-break-inside: avoid; }
        .signature img { width: 72px; height: 72px; margin: 4px auto; display: block; }
        .signature p { margin: 2px 0; }
        .footer { margin-top: 8px; font-size: 8.5pt; color: #555; text-align: center; }
        @media print {
            .no-print { display: none !important; }
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="print-btn" onclick="window.print()">Cetak / Simpan PDF</button>
    </div>

    <div class="header">
        <?php if (!empty($logo)): ?>
            <img src="../assets/img/<?= htmlspecialchars($logo) ?>" alt="Logo">
        <?php endif; ?>
        <?php if (!empty($nama_yayasan)): ?><h3><?= strtoupper(htmlspecialchars($nama_yayasan)) ?></h3><?php endif; ?>
        <h2><?= strtoupper(htmlspecialchars($nama_madrasah)) ?></h2>
        <p><?= htmlspecialchars($alamat) ?></p>
        <p>Tahun Ajaran Aktif: <?= htmlspecialchars($tahun_ajaran) ?></p>
    </div>

    <div class="title">
        <h1>LAPORAN GRAFIK DATA ALUMNI PER TAHUN AJARAN</h1>
        <p>Dokumen pendukung akreditasi | Dicetak: <?= formatDateIndonesia(date('Y-m-d')) ?> <?= date('H:i') ?></p>
    </div>

    <div class="summary">
        <div class="summary-item"><div class="summary-label">Total Alumni Laki-laki</div><div class="summary-value"><?= (int)$total_laki ?></div></div>
        <div class="summary-item"><div class="summary-label">Total Alumni Perempuan</div><div class="summary-value"><?= (int)$total_perempuan ?></div></div>
        <div class="summary-item"><div class="summary-label">Total Seluruh Alumni</div><div class="summary-value"><?= (int)$total_alumni ?></div></div>
    </div>

    <div class="content">
        <div class="chart-panel">
            <div class="chart-box">
                <?php if ($chart_image !== ''): ?>
                    <img src="<?= htmlspecialchars($chart_image, ENT_QUOTES, 'UTF-8') ?>" alt="Grafik Alumni">
                <?php else: ?>
                    <p>Gambar grafik tidak tersedia.</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="table-panel">
            <table>
                <thead>
                    <tr>
                        <th>Tahun Ajaran</th>
                        <th>L</th>
                        <th>P</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($chart_data)): ?>
                        <tr><td colspan="4">Belum ada data alumni.</td></tr>
                    <?php else: ?>
                        <?php foreach ($chart_data as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)($row['tahun_lulus'] ?? '-')) ?></td>
                                <td><?= (int)($row['jumlah_laki'] ?? 0) ?></td>
                                <td><?= (int)($row['jumlah_perempuan'] ?? 0) ?></td>
                                <td><strong><?= (int)($row['total'] ?? 0) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="signature">
        <p><?= formatDateIndonesia(date('Y-m-d')) ?></p>
        <p>Kepala Madrasah,</p>
        <img src="<?= htmlspecialchars($qr_url, ENT_QUOTES, 'UTF-8') ?>" alt="QR Validasi Kepala Madrasah">
        <p><strong><u><?= htmlspecialchars($kepala_madrasah) ?></u></strong></p>
        <p>NIP. <?= htmlspecialchars($nip_kepala ?: '-') ?></p>
    </div>

    <div class="footer">Laporan Grafik Alumni - Sistem Informasi Madrasah</div>

    <script>
        window.onload = function() { window.print(); };
    </script>
</body>
</html>
