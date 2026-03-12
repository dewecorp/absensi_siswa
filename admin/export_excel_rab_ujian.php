<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha', 'wali', 'guru'])) {
    die('Unauthorized');
}

$school_profile = getSchoolProfile($pdo);
$school_name = strtoupper($school_profile['nama_madrasah'] ?? 'Sistem Absensi Siswa');
$foundation_name = strtoupper($school_profile['nama_yayasan'] ?? '');
$school_address = $school_profile['alamat'] ?? '';
$tahun_ajaran = $school_profile['tahun_ajaran'] ?? date('Y');
$filename_tahun = str_replace('/', '-', $tahun_ajaran);

$rencana_pengeluaran = $pdo->query("SELECT * FROM tb_pengeluaran_ujian ORDER BY kategori ASC, id_pengeluaran ASC")->fetchAll(PDO::FETCH_ASSOC);

try {
    $stmt_siswa = $pdo->query("
        SELECT COUNT(*) 
        FROM tb_siswa s 
        JOIN tb_kelas k ON s.id_kelas = k.id_kelas 
        WHERE k.nama_kelas LIKE '6%' OR k.nama_kelas LIKE 'VI%'
    ");
    $jumlah_siswa = $stmt_siswa->fetchColumn();
} catch (PDOException $e) {
    $jumlah_siswa = 0; 
}

$grouped_pengeluaran = [];
foreach ($rencana_pengeluaran as $row) {
    $kat = $row['kategori'] ?? 'Lainnya';
    if (!$kat) $kat = 'Lainnya';
    $grouped_pengeluaran[$kat][] = $row;
}

$total_pengeluaran = array_sum(array_column($rencana_pengeluaran, 'total'));
$biaya_per_siswa = $jumlah_siswa > 0 ? $total_pengeluaran / $jumlah_siswa : 0;

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=RAB_Ujian_$filename_tahun.xls");
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
            <td colspan="7" class="header-title" style="text-decoration: underline;">RAB PELAKSANAAN UJIAN</td>
        </tr>
        <tr><td colspan="7" class="no-border"></td></tr>

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
            <td colspan="6" class="text-right">TOTAL BIAYA UJIAN</td>
            <td class="text-right"><?= $total_pengeluaran ?></td>
        </tr>
        <tr><td colspan="7" class="no-border"></td></tr>

        <tr>
            <td colspan="7" class="no-border" style="border: 1px solid #000; padding: 10px;">
                Jumlah Siswa Kelas 6: <?= $jumlah_siswa ?> Siswa<br>
                <strong>Biaya Per Siswa: <?= $biaya_per_siswa ?></strong>
            </td>
        </tr>
    </table>
</body>
</html>
