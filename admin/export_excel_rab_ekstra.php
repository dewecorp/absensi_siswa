<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha', 'wali', 'guru'])) {
    die('Unauthorized');
}

$school_profile = getSchoolProfile($pdo);
$school_name = strtoupper($school_profile['nama_madrasah'] ?? 'Sistem Informasi Madrasah');
$foundation_name = strtoupper($school_profile['nama_yayasan'] ?? '');
$school_address = $school_profile['alamat'] ?? '';
$tahun_ajaran = $school_profile['tahun_ajaran'] ?? date('Y');
$filename_tahun = str_replace('/', '-', $tahun_ajaran);

$sumber_anggaran = $pdo->query("SELECT * FROM tb_sumber_ekstra ORDER BY id_sumber ASC")->fetchAll(PDO::FETCH_ASSOC);
$rencana_pengeluaran = $pdo->query("SELECT * FROM tb_pengeluaran_ekstra ORDER BY kategori ASC, id_pengeluaran ASC")->fetchAll(PDO::FETCH_ASSOC);

$grouped_pengeluaran = [];
foreach ($rencana_pengeluaran as $row) {
    $kat = $row['kategori'] ?? 'Lainnya';
    if (!$kat) $kat = 'Lainnya';
    $grouped_pengeluaran[$kat][] = $row;
}

$total_sumber = array_sum(array_column($sumber_anggaran, 'total'));
$total_pengeluaran = array_sum(array_column($rencana_pengeluaran, 'total'));
$sisa_anggaran = $total_sumber - $total_pengeluaran;

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=RAB_Ekstrakurikuler_$filename_tahun.xls");
header("Pragma: no-cache");
header("Expires: 0");
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #000; padding: 5px; vertical-align: top; }
        th { background-color: #f0f0f0; text-align: center; font-weight: bold; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .bold { font-weight: bold; }
        .header-title { font-size: 16px; font-weight: bold; text-align: center; border: none; }
        .header-text { text-align: center; border: none; }
        .no-border { border: none; }
        .bg-gray { background-color: #f0f0f0; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td colspan="7" class="header-title"><?= $foundation_name ?></td>
        </tr>
        <tr>
            <td colspan="7" class="header-title"><?= $school_name ?></td>
        </tr>
        <tr>
            <td colspan="7" class="header-text"><?= $school_address ?></td>
        </tr>
        <tr>
            <td colspan="7" class="header-text bold">TAHUN AJARAN <?= $tahun_ajaran ?></td>
        </tr>
        <tr><td colspan="7" class="no-border"></td></tr>
        <tr>
            <td colspan="7" class="header-title" style="text-decoration: underline;">RAB EKSTRAKURIKULER</td>
        </tr>
        <tr><td colspan="7" class="no-border"></td></tr>

        <!-- A. SUMBER ANGGARAN -->
        <tr>
            <td colspan="7" class="bold no-border">A. SUMBER ANGGARAN</td>
        </tr>
        <tr>
            <th width="5%">No</th>
            <th>Uraian</th>
            <th width="10%">Vol</th>
            <th width="15%">Satuan</th>
            <th width="10%">Jml</th>
            <th width="15%" colspan="2">Total</th>
        </tr>
        <?php 
        $no = 1;
        foreach ($sumber_anggaran as $row): ?>
        <tr>
            <td class="text-center"><?= $no++ ?></td>
            <td><?= htmlspecialchars($row['uraian']) ?></td>
            <td class="text-center"><?= $row['volume'] ?></td>
            <td class="text-right"><?= $row['satuan'] ?></td>
            <td class="text-center"><?= $row['jumlah'] ?></td>
            <td class="text-right" colspan="2"><?= $row['total'] ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="bold bg-gray">
            <td colspan="5" class="text-right">TOTAL PEMASUKAN</td>
            <td class="text-right" colspan="2"><?= $total_sumber ?></td>
        </tr>
        <tr><td colspan="7" class="no-border"></td></tr>

        <!-- B. RENCANA PENGELUARAN -->
        <tr>
            <td colspan="7" class="bold no-border">B. RENCANA PENGELUARAN</td>
        </tr>
        <tr>
            <th width="5%">No</th>
            <th>Uraian</th>
            <th width="10%">Vol</th>
            <th width="15%">Satuan</th>
            <th width="8%">Jml</th>
            <th width="8%">X</th>
            <th width="15%">Total</th>
        </tr>
        <?php 
        $no = 1;
        foreach ($grouped_pengeluaran as $kategori => $items): 
            $cat_total = array_sum(array_column($items, 'total'));
        ?>
            <!-- Kategori Header -->
            <tr class="bg-gray">
                <td class="text-center bold"><?= $no++ ?></td>
                <td colspan="5" class="bold"><?= htmlspecialchars($kategori) ?></td>
                <td class="text-right bold"><?= $cat_total ?></td>
            </tr>

            <?php foreach ($items as $item): ?>
            <tr>
                <td></td>
                <td style="padding-left: 20px;">- <?= htmlspecialchars($item['uraian']) ?></td>
                <td class="text-center"><?= $item['volume'] ?></td>
                <td class="text-right"><?= $item['satuan'] ?></td>
                <td class="text-center"><?= $item['jumlah'] ?></td>
                <td class="text-center"><?= $item['perkalian'] ?></td>
                <td class="text-right"><?= $item['total'] ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        
        <tr class="bold bg-gray">
            <td colspan="6" class="text-right">TOTAL PENGELUARAN</td>
            <td class="text-right"><?= $total_pengeluaran ?></td>
        </tr>
        <tr><td colspan="7" class="no-border"></td></tr>

        <tr class="bold">
            <td colspan="7" class="no-border" style="border: 1px solid #000; padding: 10px;">
                SISA ANGGARAN: <?= $sisa_anggaran ?>
            </td>
        </tr>
    </table>
</body>
</html>
