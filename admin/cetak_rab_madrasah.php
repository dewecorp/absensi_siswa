<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha', 'wali', 'guru'])) {
    die('Unauthorized');
}

// Get school profile
$school_profile = getSchoolProfile($pdo);
$school_name = strtoupper($school_profile['nama_madrasah'] ?? 'Sistem Informasi Madrasah');
$foundation_name = strtoupper($school_profile['nama_yayasan'] ?? '');
$school_address = $school_profile['alamat'] ?? '';
$tahun_ajaran = $school_profile['tahun_ajaran'] ?? date('Y');
$kepala_madrasah = $school_profile['nama_kepala'] ?? '.........................';
$nip_kepala = $school_profile['nip_kepala'] ?? '-';
$logo_path = '../assets/img/' . ($school_profile['logo'] ?? 'logo.png'); // Adjust path as needed

// Date Formatting
$months = [
    'January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret', 'April' => 'April', 'May' => 'Mei', 'June' => 'Juni',
    'July' => 'Juli', 'August' => 'Agustus', 'September' => 'September', 'October' => 'Oktober', 'November' => 'November', 'December' => 'Desember'
];
$tempat = $school_profile['tempat_jadwal'] ?? 'Tempat';
$tanggal = $tempat . ', ' . date('d') . ' ' . $months[date('F')] . ' ' . date('Y');

// QR Code Content
$qr_content = "Ditandatangani secara elektronik oleh:\n" . $kepala_madrasah . "\nKepala Madrasah\nTanggal: " . date('d F Y');
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=" . urlencode($qr_content);

// Fetch Data
$sumber_anggaran = $pdo->query("SELECT * FROM tb_sumber_anggaran ORDER BY id_sumber ASC")->fetchAll(PDO::FETCH_ASSOC);
$rencana_pengeluaran = $pdo->query("SELECT p.*, k.nama_kategori FROM tb_rencana_pengeluaran p LEFT JOIN tb_kategori_anggaran k ON p.id_kategori = k.id_kategori ORDER BY k.nama_kategori ASC, p.sub_kategori ASC, p.id_pengeluaran ASC")->fetchAll(PDO::FETCH_ASSOC);

// Grouping Pengeluaran
$grouped_pengeluaran = [];
foreach ($rencana_pengeluaran as $row) {
    $kat = $row['nama_kategori'] ?? 'Lainnya';
    $sub = $row['sub_kategori'] ?? '';
    $grouped_pengeluaran[$kat][$sub][] = $row;
}

// Totals
$total_sumber = array_sum(array_column($sumber_anggaran, 'total'));
$total_pengeluaran = array_sum(array_column($rencana_pengeluaran, 'total'));
$sisa_anggaran = $total_sumber - $total_pengeluaran;
$filename_tahun = str_replace('/', '-', $tahun_ajaran); // Sanitize filename
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak RAB Madrasah - <?= htmlspecialchars($filename_tahun) ?></title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; }
        @media print {
            .no-print { display: none; }
            @page { margin: 1cm; size: 210mm 330mm; } /* Ukuran F4 */
        }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
        .header h1 { margin: 0; font-size: 18px; text-transform: uppercase; }
        .header p { margin: 5px 0 0; font-size: 14px; }
        .title { text-align: center; font-weight: bold; font-size: 16px; margin-bottom: 15px; text-decoration: underline; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #000; padding: 5px; vertical-align: top; }
        th { background-color: #f0f0f0; text-align: center; font-weight: bold; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .bold { font-weight: bold; }
        .bg-gray { background-color: #f9f9f9; }
        
        .cat-header { background-color: #e0e0e0; font-weight: bold; }
        .sub-cat-header { background-color: #f0f0f0; font-style: italic; padding-left: 20px; }
        
        .ttd-box { margin-top: 30px; display: flex; justify-content: space-between; page-break-inside: avoid; }
        .ttd-item { text-align: center; width: 30%; }
        .ttd-space { height: 70px; }

        @media print {
            .no-print { display: none; }
            @page { margin: 1cm; size: A4; }
        }
    </style>
</head>
<body onload="window.print()">

    <div class="no-print" style="margin-bottom: 20px; text-align: right;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #007bff; color: white; border: none; cursor: pointer;">Cetak / Simpan PDF</button>
        <button onclick="window.close()" style="padding: 10px 20px; background: #dc3545; color: white; border: none; cursor: pointer;">Tutup</button>
    </div>

    <div class="header" style="display: flex; align-items: center; justify-content: center; padding-bottom: 10px; border-bottom: 2px solid #000; position: relative;">
        <div style="position: absolute; left: 0;">
            <img src="<?= $logo_path ?>" alt="Logo" style="height: 60px;">
        </div>
        <div style="text-align: center; width: 100%;">
            <h2 style="margin: 0; font-size: 16px;"><?= htmlspecialchars($foundation_name) ?></h2>
            <h1 style="margin: 0; font-size: 20px;"><?= htmlspecialchars($school_name) ?></h1>
            <p style="margin: 2px 0 0; font-size: 14px;"><?= htmlspecialchars($school_address) ?></p>
            <p style="margin: 2px 0 0; font-size: 14px; font-weight: bold;">TAHUN AJARAN <?= htmlspecialchars($tahun_ajaran) ?></p>
        </div>
    </div>

    <div class="title">RENCANA ANGGARAN BIAYA (RAB) MADRASAH</div>

    <h3>A. SUMBER ANGGARAN</h3>
    <table>
        <thead>
            <tr>
                <th width="5%">No</th>
                <th>Uraian</th>
                <th width="10%">Vol</th>
                <th width="15%">Satuan</th>
                <th width="10%">Jml</th>
                <th width="15%">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            foreach ($sumber_anggaran as $row): ?>
            <tr>
                <td class="text-center"><?= $no++ ?></td>
                <td><?= htmlspecialchars($row['uraian']) ?></td>
                <td class="text-center"><?= number_format($row['volume'], 0, ',', '.') ?></td>
                <td class="text-right">Rp <?= number_format($row['satuan'], 0, ',', '.') ?></td>
                <td class="text-center"><?= number_format($row['jumlah'], 0, ',', '.') ?></td>
                <td class="text-right">Rp <?= number_format($row['total'], 0, ',', '.') ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="bold bg-gray">
                <td colspan="5" class="text-right">TOTAL PEMASUKAN</td>
                <td class="text-right">Rp <?= number_format($total_sumber, 0, ',', '.') ?></td>
            </tr>
        </tbody>
    </table>

    <h3>B. RENCANA PENGELUARAN</h3>
    <table>
        <thead>
            <tr>
                <th width="5%">No</th>
                <th>Uraian</th>
                <th width="10%">Vol</th>
                <th width="15%">Satuan</th>
                <th width="10%">Jml</th>
                <th width="15%">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            foreach ($grouped_pengeluaran as $kategori => $sub_groups): 
                // Hitung total kategori
                $cat_total = 0;
                foreach ($sub_groups as $items) {
                    $cat_total += array_sum(array_column($items, 'total'));
                }
            ?>
                <!-- Kategori Header -->
                <tr class="cat-header">
                    <td class="text-center"><?= $no++ ?></td>
                    <td colspan="4"><?= htmlspecialchars($kategori) ?></td>
                    <td class="text-right">Rp <?= number_format($cat_total, 0, ',', '.') ?></td>
                </tr>

                <?php foreach ($sub_groups as $sub_kategori => $items): ?>
                    <?php if ($sub_kategori): ?>
                        <tr class="sub-cat-header">
                            <td></td>
                            <td colspan="5">Sub: <?= htmlspecialchars($sub_kategori) ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td></td>
                        <td style="padding-left: <?= $sub_kategori ? '30px' : '15px' ?>;">- <?= htmlspecialchars($item['uraian']) ?></td>
                        <td class="text-center"><?= number_format($item['volume'], 0, ',', '.') ?></td>
                        <td class="text-right">Rp <?= number_format($item['satuan'], 0, ',', '.') ?></td>
                        <td class="text-center"><?= number_format($item['jumlah'], 0, ',', '.') ?></td>
                        <td class="text-right">Rp <?= number_format($item['total'], 0, ',', '.') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
            
            <tr class="bold bg-gray">
                <td colspan="5" class="text-right">TOTAL PENGELUARAN</td>
                <td class="text-right">Rp <?= number_format($total_pengeluaran, 0, ',', '.') ?></td>
            </tr>
        </tbody>
    </table>

    <div style="margin-top: 20px; border: 1px solid #000; padding: 10px; width: 300px;">
        <strong>SISA ANGGARAN:</strong> Rp <?= number_format($sisa_anggaran, 0, ',', '.') ?>
    </div>

    <div class="ttd-box">
        <div class="ttd-item"></div> <!-- Empty left side -->
        <div class="ttd-item">
            <p style="margin-bottom: 5px;"><?= htmlspecialchars($tanggal) ?></p>
            <p>Mengetahui,<br>Kepala Madrasah</p>
            <div class="ttd-space">
                <img src="<?= $qr_url ?>" alt="QR Code" style="height: 70px;">
            </div>
            <p class="bold" style="margin-bottom: 0;"><?= htmlspecialchars($kepala_madrasah) ?></p>
        </div>
    </div>

</body>
</html>
