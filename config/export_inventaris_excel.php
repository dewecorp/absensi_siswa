<?php
// Determine session name before including functions.php
if (isset($_GET['session_type'])) {
    $type = $_GET['session_type'];
    $session_name = 'SIS_LOGIN';
    if ($type == 'admin') $session_name = 'SIS_ADMIN';
    elseif ($type == 'tata_usaha') $session_name = 'SIS_TU';
    elseif ($type == 'kepala_madrasah' || $type == 'kepala') $session_name = 'SIS_KEPALA';
    
    if (session_status() == PHP_SESSION_NONE) {
        $save_path = sys_get_temp_dir();
        if (is_string($save_path) && $save_path !== '') {
            session_save_path($save_path);
        }
        session_name($session_name);
        session_start();
    }
} else {
    if (session_status() == PHP_SESSION_NONE) {
        $save_path = sys_get_temp_dir();
        if (is_string($save_path) && $save_path !== '') {
            session_save_path($save_path);
        }
        session_name('SIS_ADMIN');
        session_start();
    }
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

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
$nama_madrasah = $school_profile['nama_madrasah'] ?? 'Madrasah';

// Calculate statistics
$stats = [
    'total_baik' => 0,
    'total_rusak' => 0,
    'total_nilai_aset' => 0
];
foreach ($inventories as $inv) {
    if ($inv['kondisi'] == 'Baik') $stats['total_baik']++;
    if ($inv['kondisi'] == 'Rusak') $stats['total_rusak']++;
    $stats['total_nilai_aset'] += $inv['total'];
}

// Group by category
$grouped_inventories = [];
$category_totals = [];
foreach ($inventories as $inv) {
    $kategori = $inv['nama_kategori'] ?? 'Tanpa Kategori';
    if (!isset($grouped_inventories[$kategori])) {
        $grouped_inventories[$kategori] = [];
        $category_totals[$kategori] = 0;
    }
    $grouped_inventories[$kategori][] = $inv;
    $category_totals[$kategori] += $inv['total'];
}

$filename = "Inventaris_Sarpras_" . date('Ymd_His') . ".xls";

// Headers for Excel download
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

?>
<!DOCTYPE html>
<html>
<head>
    <style>
        table {
            border-collapse: collapse;
            width: 100%;
        }
        table, th, td {
            border: 1px solid #333;
        }
        th {
            background-color: #4472C4;
            color: white;
            padding: 8px 5px;
            text-align: center;
            font-weight: bold;
        }
        td {
            padding: 6px 5px;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .category-header {
            background-color: #E2EFDA;
            font-weight: bold;
        }
        h1, h2, h3 {
            margin: 5px 0;
        }
        .summary {
            margin-top: 20px;
        }
        .summary td {
            border: none;
            padding: 5px;
        }
    </style>
</head>
<body>
    <h1><?= $nama_madrasah ?></h1>
    <h2>LAPORAN DATA INVENTARIS SARPRAS</h2>
    <p>Tanggal Cetak: <?= date('d/m/Y H:i:s') ?></p>
    
    <table>
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="20%">Kategori</th>
                <th width="25%">Nama Inventaris</th>
                <th width="8%">Jumlah</th>
                <th width="10%">Luas (m²)</th>
                <th width="12%">Harga Satuan</th>
                <th width="12%">Total</th>
                <th width="8%">Kondisi</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            foreach ($grouped_inventories as $kategori => $items): 
            ?>
            <tr class="category-header">
                <td colspan="8">
                    <strong><?= htmlspecialchars($kategori) ?></strong> 
                    <span style="float: right;">Total: <?= number_format($category_totals[$kategori], 0, ',', '.') ?></span>
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
        </tbody>
    </table>

    <div class="summary">
        <h3>Ringkasan</h3>
        <table>
            <tr>
                <td width="250"><strong>Total Inventaris Baik</strong></td>
                <td><strong>: <?= number_format($stats['total_baik']) ?> Unit</strong></td>
            </tr>
            <tr>
                <td><strong>Total Inventaris Rusak</strong></td>
                <td><strong>: <?= number_format($stats['total_rusak']) ?> Unit</strong></td>
            </tr>
            <tr>
                <td><strong>Total Estimasi Nilai Aset</strong></td>
                <td><strong>: <?= number_format($stats['total_nilai_aset'], 0, ',', '.') ?></strong></td>
            </tr>
        </table>
    </div>
</body>
</html>
