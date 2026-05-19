<?php
require_once 'database.php';
require_once 'functions.php';

// Check auth
if (!isAuthorized(['admin', 'tata_usaha', 'kepala_madrasah'])) {
    die("Unauthorized access");
}

// Get Inventory Data
$stmt = $pdo->query("
    SELECT i.*, k.nama_kategori 
    FROM tb_inventaris i 
    LEFT JOIN tb_kategori_inventaris k ON i.id_kategori = k.id 
    ORDER BY k.nama_kategori ASC, i.created_at DESC
");
$inventories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get School Profile
$school_profile = getSchoolProfile($pdo);
$kepala_madrasah = $school_profile['kepala_madrasah'] ?? '-';
$tempat_ttd = $school_profile['tempat_jadwal'] ?? ($school_profile['kabupaten'] ?? 'Tempat');
$tahun_ajaran = $school_profile['tahun_ajaran'] ?? date('Y');
$filename_tahun = str_replace(['/', '\\', ' '], ['-', '-', ''], (string)$tahun_ajaran);
$pdf_title = "Laporan_Inventaris_Sarpras_{$filename_tahun}";

// Digital Signature QR Code
$qr_kepala_content = 'Validasi Tanda Tangan Digital Kepala Madrasah: ' . $kepala_madrasah . ' - ' . ($school_profile['nama_madrasah'] ?? 'Madrasah');
$qr_kepala_url = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qr_kepala_content);
$qr_kepala_img = '<img src="' . $qr_kepala_url . '" alt="QR Signature Kepala" style="width: 70px; height: 70px; margin: 5px auto; display: block;">';

// Logo
$logo_file = $school_profile['logo'] ?? '';
$logo_path = '';
if ($logo_file && file_exists(__DIR__ . '/../assets/img/' . $logo_file)) {
    $logo_path = '../assets/img/' . $logo_file;
} elseif (file_exists(__DIR__ . '/../assets/img/logo.png')) {
    $logo_path = '../assets/img/logo.png';
}

// Group by category
$grouped_inventories = [];
$category_totals = [];
$stats = [
    'total_baik' => 0,
    'total_rusak' => 0,
    'total_nilai_aset' => 0
];
foreach ($inventories as $inv) {
    $kategori = $inv['nama_kategori'] ?? 'Tanpa Kategori';
    if (!isset($grouped_inventories[$kategori])) {
        $grouped_inventories[$kategori] = [];
        $category_totals[$kategori] = 0;
    }
    $grouped_inventories[$kategori][] = $inv;
    $category_totals[$kategori] += $inv['total'];
    
    // Count statistics
    if ($inv['kondisi'] == 'Baik') $stats['total_baik']++;
    if ($inv['kondisi'] == 'Rusak') $stats['total_rusak']++;
    $stats['total_nilai_aset'] += $inv['total'];
}

// Calculate total
$total_nilai = array_sum($category_totals);

// Output HTML for PDF
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pdf_title) ?></title>
    <style>
        @page { size: A4; margin: 15mm; }
        @media print {
            .no-print { display: none; }
        }
        body { font-family: Arial, sans-serif; font-size: 11pt; line-height: 1.4; color: #000; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 3px double black; padding-bottom: 10px; position: relative; min-height: 90px; }
        .header img { width: 80px; position: absolute; left: 10px; top: 0; }
        .header h3, .header h2, .header p { margin: 2px 0; color: #000; }
        .title { text-align: center; margin-bottom: 20px; }
        .title h4 { margin: 0; color: #000; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid black; padding: 8px; text-align: left; color: #000; }
        th { background-color: #f2f2f2; text-align: center; font-weight: bold; color: #000; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .category-header { background-color: #E2EFDA !important; font-weight: bold; }
        .category-header td { color: #000; }
        .summary { margin-top: 20px; padding: 15px; background-color: #f9f9f9; border: 2px solid #000; }
        .summary table { border: none; }
        .summary td { border: none; padding: 5px; color: #000; }
        .summary-label { font-weight: bold; width: 250px; color: #000; }
        .summary-value { text-align: right; font-weight: bold; font-size: 12pt; color: #000; }
        .signature-container { margin-top: 40px; width: 100%; }
        .signature-table { width: 100%; border: none; }
        .signature-table td { border: none; text-align: center; width: 50%; vertical-align: top; padding-top: 20px; color: #000; }
        .footer-date { text-align: right; margin-bottom: 10px; margin-right: 50px; color: #000; }
    </style>
</head>
<body>
    <button class="no-print" onclick="window.print()" style="position: fixed; top: 10px; right: 10px; padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; z-index: 1000;">Cetak PDF</button>

    <div class="header">
        <?php if ($logo_path): ?>
            <img src="<?= $logo_path ?>" alt="Logo">
        <?php endif; ?>
        <h3><?= strtoupper($school_profile['nama_yayasan'] ?? 'YAYASAN') ?></h3>
        <h2><?= strtoupper($school_profile['nama_sekolah'] ?? $school_profile['nama_madrasah'] ?? 'NAMA MADRASAH') ?></h2>
        <p><?= $school_profile['alamat'] ?? '' ?></p>
        <p>Tahun Ajaran: <?= htmlspecialchars($school_profile['tahun_ajaran'] ?? '-') ?></p>
    </div>

    <div class="title">
        <h4>LAPORAN DATA INVENTARIS SARPRAS</h4>
    </div>

    <table>
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="18%">Kategori</th>
                <th width="25%">Nama Inventaris</th>
                <th width="7%">Jumlah</th>
                <th width="8%">Luas (m²)</th>
                <th width="12%">Harga Satuan</th>
                <th width="12%">Total</th>
                <th width="8%">Kondisi</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            if (empty($inventories)): 
            ?>
            <tr>
                <td colspan="8" class="text-center">Tidak ada data inventaris.</td>
            </tr>
            <?php else: ?>
                <?php foreach ($grouped_inventories as $kategori => $items): ?>
                <tr class="category-header">
                    <td colspan="8">
                        <strong><?= htmlspecialchars($kategori) ?></strong> 
                        <span class="text-right" style="float: right;">Total: <?= number_format($category_totals[$kategori], 0, ',', '.') ?></span>
                    </td>
                </tr>
                <?php foreach ($items as $row): ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td><?= htmlspecialchars($row['nama_kategori'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($row['nama_inventaris']) ?></td>
                    <td class="text-center"><?= number_format($row['jumlah']) ?></td>
                    <td class="text-center"><?= $row['luas'] ? number_format($row['luas'], 2, ',', '.') : '-' ?></td>
                    <td class="text-right"><?= number_format($row['harga_satuan'], 0, ',', '.') ?></td>
                    <td class="text-right"><?= number_format($row['total'], 0, ',', '.') ?></td>
                    <td class="text-center"><?= $row['kondisi'] ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="summary">
        <table>
            <tr>
                <td class="summary-label">Total Inventaris Baik</td>
                <td class="summary-value"><?= number_format($stats['total_baik']) ?> Unit</td>
            </tr>
            <tr>
                <td class="summary-label">Total Inventaris Rusak</td>
                <td class="summary-value"><?= number_format($stats['total_rusak']) ?> Unit</td>
            </tr>
            <tr>
                <td class="summary-label">Total Estimasi Nilai Aset</td>
                <td class="summary-value">Rp <?= number_format($stats['total_nilai_aset'], 0, ',', '.') ?></td>
            </tr>
        </table>
    </div>

    <div class="signature-container">
        <div class="footer-date">
            <?= htmlspecialchars($tempat_ttd) ?>, <?= formatDateIndonesia(date('Y-m-d')) ?>
        </div>
        <table class="signature-table">
            <tr>
                <td>
                </td>
                <td>
                    Mengetahui,<br>
                    Kepala Madrasah<br>
                    <?= $qr_kepala_img ?>
                    <strong><u><?= $kepala_madrasah ?></u></strong><br>
                    NIP. <?= $school_profile['nip_kepala'] ?? '-' ?>
                </td>
            </tr>
        </table>
    </div>

    <script>
        window.onload = function() {
            window.print();
        }
    </script>
</body>
</html>
