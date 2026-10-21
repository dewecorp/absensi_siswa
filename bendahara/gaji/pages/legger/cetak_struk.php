<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

$id = $_GET['id'] ?? 0;
$sql = "SELECT lg.*, g.* FROM legger_gaji lg JOIN guru g ON lg.guru_id = g.id WHERE lg.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$legger = $stmt->get_result()->fetch_assoc();

$sql = "SELECT * FROM settings LIMIT 1";
$settings = $conn->query($sql)->fetch_assoc();

// Get logo path - use base64 for better print compatibility
$logo_file = __DIR__ . '/../../assets/img/' . ($settings['logo'] ?? '');
$logo_exists = !empty($settings['logo']) && file_exists($logo_file);
$logo_base64 = '';
$logo_path = '';
if ($logo_exists) {
    $logo_path = BASE_URL . 'assets/img/' . $settings['logo'];
    // Convert to base64 for better print compatibility
    $image_data = file_get_contents($logo_file);
    $logo_base64 = 'data:' . mime_content_type($logo_file) . ';base64,' . base64_encode($image_data);
}

$sql = "SELECT ld.* FROM legger_detail ld
        LEFT JOIN tunjangan t ON ld.jenis = 'tunjangan' AND ld.item_id = t.id
        LEFT JOIN potongan p ON ld.jenis = 'potongan' AND ld.item_id = p.id
        WHERE ld.legger_id = ?
        AND (
            (ld.jenis = 'tunjangan' AND COALESCE(t.aktif, 0) = 1)
            OR
            (ld.jenis = 'potongan' AND COALESCE(p.aktif, 0) = 1)
        )";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$details = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$total_tunjangan_calc = 0;
$total_potongan_calc = 0;
foreach ($details as $d) {
    if (($d['jenis'] ?? '') === 'tunjangan') {
        $total_tunjangan_calc += floatval($d['jumlah'] ?? 0);
    } elseif (($d['jenis'] ?? '') === 'potongan') {
        $total_potongan_calc += floatval($d['jumlah'] ?? 0);
    }
}
$gaji_pokok_calc = floatval($legger['gaji_pokok'] ?? 0);
$gaji_bersih_calc = $gaji_pokok_calc + $total_tunjangan_calc - $total_potongan_calc;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Slip Gaji - <?php echo htmlspecialchars($legger['nama_lengkap']); ?></title>
    <style>
        @page {
            size: F4;
            margin: 10mm 5mm 5mm 5mm; /* top: 1cm, right: 5mm, bottom: 5mm, left: 5mm */
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        table, table *, table th, table td {
            box-sizing: border-box;
        }
        
        table tbody tr td,
        table thead tr th {
            box-sizing: border-box !important;
        }
        
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            overflow: visible;
        }
        
        body:empty {
            display: none;
        }
        
        .page {
            width: 100%;
            max-width: 200mm; /* 210mm - 5mm left - 5mm right */
            height: auto;
            min-height: 150mm;
            page-break-inside: avoid;
            break-inside: avoid;
            display: flex;
            flex-direction: row;
            gap: 3mm;
            padding: 2mm;
            margin: 0;
            box-sizing: border-box;
            align-items: flex-start;
            justify-content: flex-start;
        }
        
        .slip {
            width: calc((200mm - 3mm - 4mm) / 2);
            border: 1px solid #000;
            padding: 3mm;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-sizing: border-box;
        }
        
        .header {
            display: flex;
            align-items: center;
            margin-bottom: 1mm;
            border-bottom: 1px solid #000;
            padding-bottom: 2mm;
            flex-shrink: 0;
        }
        
        .header-logo {
            max-width: 35px;
            max-height: 35px;
            margin-right: 5mm;
            object-fit: contain;
            flex-shrink: 0;
        }
        
        .header-content {
            flex: 1;
            text-align: center;
        }
        
        .header-content h3 {
            font-size: 16px;
            margin: 0;
            font-weight: bold;
            line-height: 1.3;
            letter-spacing: 0.5px;
        }
        
        .header-content p {
            font-size: 12px;
            margin: 3px 0 0 0;
            line-height: 1.3;
        }
        
        .info {
            margin: 2mm 0;
            font-size: 14px;
            flex-shrink: 0;
        }
        
        .info strong {
            font-weight: bold;
        }
        
        .table-wrapper {
            width: 100%;
            width: 100%;
            margin: 2mm 0;
            flex: 1;
            min-height: 0;
            border: 1px solid #000;
            border-bottom: 1px solid #000;
            box-sizing: border-box;
            overflow: visible;
        }
        
        .table-row {
            display: flex;
            width: 100%;
            height: 22px;
            line-height: 22px;
            box-sizing: border-box;
        }
        
        .table-cell {
            flex: 1;
            height: 22px;
            line-height: 22px;
            font-size: 14px;
            padding: 0 5px;
            display: flex;
            align-items: center;
            border-right: 1px solid #000;
            border-bottom: 1px solid #000;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            box-sizing: border-box;
        }
        
        .table-cell:last-child {
            border-right: none;
            justify-content: flex-end;
            padding-right: 8px;
        }
        
        .table-header {
            flex: 1;
            height: 22px;
            line-height: 22px;
            font-size: 14px;
            padding: 0 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-right: 1px solid #000;
            border-bottom: 1px solid #000;
            background-color: #f0f0f0;
            font-weight: bold;
            text-transform: uppercase;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            box-sizing: border-box;
        }
        
        .table-header:last-child {
            border-right: none;
        }
        
        .signature-row {
            margin-top: 2mm;
            padding-top: 1mm;
            border-top: 1px solid #000;
            font-size: 11px;
            flex-shrink: 0;
            display: table;
            width: 100%;
        }
        
        .signature-col {
            display: table-cell;
            width: 50%;
            text-align: center;
            vertical-align: top;
            padding: 0 5mm;
        }
        
        .signature-col p {
            margin: 0;
            line-height: 1.2;
        }
        
        .signature-col p:last-child {
            margin-top: 1.5mm;
        }
        
        .signature-line {
            width: 55%;
            margin: 3px auto 0 auto;
            min-height: 5px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .qr-signature {
            width: 14mm;
            height: 14mm;
        }
        
        .signature-name {
            white-space: nowrap;
            font-size: 10px;
        }
        
        .tempat-tanggal {
            margin-top: 0;
            margin-bottom: 2mm;
            text-align: right;
            font-size: 11px;
            padding-right: 2mm;
            flex-shrink: 0;
        }
        
        @media print {
            body {
                margin: 0;
                padding: 0;
                overflow: visible;
            }
            
            .slip {
                width: calc((200mm - 3mm - 4mm) / 2);
            }
            
            .page {
                width: 100% !important;
                max-width: 200mm !important;
                min-height: 150mm !important;
                height: auto !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                display: flex !important;
                flex-direction: row !important;
                gap: 3mm !important;
                padding: 2mm !important;
                margin: 0 !important;
                box-sizing: border-box !important;
                align-items: flex-start !important;
                justify-content: flex-start !important;
            }
            
            .signature-col p {
                line-height: 1.2 !important;
            }
            
            .signature-name {
                white-space: nowrap !important;
            }
        }
    </style>
</head>
<body>
    <div class="page page-last">
    <div class="slip">
        <div class="header">
            <?php if ($logo_exists): ?>
            <img src="<?php echo $logo_base64; ?>" alt="Logo Madrasah" class="header-logo" onerror="this.src='<?php echo $logo_path; ?>'">
            <?php endif; ?>
            <div class="header-content">
                <h3><?php echo strtoupper(htmlspecialchars($settings['nama_madrasah'])); ?></h3>
                <p>Slip Gaji 
                    <?php 
                    $jumlah_periode = $settings['jumlah_periode'] ?? 1;
                    $periode_mulai = $settings['periode_mulai'] ?? '';
                    $periode_akhir = $settings['periode_akhir'] ?? '';
                    
                    if ($jumlah_periode > 1 && !empty($periode_mulai) && !empty($periode_akhir)) {
                        echo 'Bulan ' . getPeriodRangeLabel($periode_mulai, $periode_akhir);
                    } else {
                        echo 'Bulan ' . getPeriodLabel($legger['periode']);
                    }
                    ?>
                </p>
            </div>
        </div>
        <div class="info">
            <p><strong>Nama:</strong> <?php echo htmlspecialchars($legger['nama_lengkap']); ?></p>
        </div>
        <div class="table-wrapper">
            <div class="table-row">
                <div class="table-header" style="flex: 2;">Keterangan</div>
                <div class="table-header" style="flex: 1;">Jumlah</div>
            </div>
            <?php if ($legger['gaji_pokok'] > 0): ?>
            <div class="table-row">
                <div class="table-cell" style="flex: 2; font-weight: bold;">Gaji Pokok</div>
                <div class="table-cell" style="flex: 1; font-weight: bold;"><?php echo formatRupiah($legger['gaji_pokok']); ?></div>
            </div>
            <?php endif; ?>
            <?php 
            $tunjangan_items = [];
            foreach ($details as $d): 
                if ($d['jenis'] == 'tunjangan' && $d['jumlah'] > 0):
                    $tunjangan_items[] = $d;
                endif;
            endforeach;
            
            foreach ($tunjangan_items as $d):
            ?>
                <div class="table-row">
                    <div class="table-cell" style="flex: 2;">Tunjangan <?php echo htmlspecialchars($d['nama_item']); ?></div>
                    <div class="table-cell" style="flex: 1;"><?php echo formatRupiah($d['jumlah']); ?></div>
                </div>
            <?php endforeach; ?>
            <?php if ($total_tunjangan_calc > 0): ?>
            <div class="table-row">
                <div class="table-cell" style="flex: 2; font-weight: bold;">Total Tunjangan</div>
                <div class="table-cell" style="flex: 1; font-weight: bold;"><?php echo formatRupiah($total_tunjangan_calc); ?></div>
            </div>
            <?php endif; ?>
            <?php 
            $potongan_items = [];
            foreach ($details as $d): 
                if ($d['jenis'] == 'potongan' && $d['jumlah'] > 0):
                    $potongan_items[] = $d;
                endif;
            endforeach;
            
            foreach ($potongan_items as $d):
            ?>
                <div class="table-row">
                    <div class="table-cell" style="flex: 2;">Potongan <?php echo htmlspecialchars($d['nama_item']); ?></div>
                    <div class="table-cell" style="flex: 1;"><?php echo formatRupiah($d['jumlah']); ?></div>
                </div>
            <?php endforeach; ?>
            <?php if ($total_potongan_calc > 0): ?>
            <div class="table-row">
                <div class="table-cell" style="flex: 2; font-weight: bold;">Total Potongan</div>
                <div class="table-cell" style="flex: 1; font-weight: bold;"><?php echo formatRupiah($total_potongan_calc); ?></div>
            </div>
            <?php endif; ?>
            <div class="table-row">
                <div class="table-cell" style="flex: 2; font-weight: bold;">Gaji Bersih</div>
                <div class="table-cell" style="flex: 1; font-weight: bold;"><?php echo formatRupiah($gaji_bersih_calc); ?></div>
            </div>
        </div>
        <?php if (!empty($settings['tempat']) || !empty($settings['hari_tanggal'])): ?>
        <div class="tempat-tanggal">
            <?php if (!empty($settings['tempat'])): ?>
                <?php echo htmlspecialchars($settings['tempat']); ?><?php if (!empty($settings['hari_tanggal'])): ?>,<?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($settings['hari_tanggal'])): ?>
                <?php echo formatTanggalTanpaHari($settings['hari_tanggal']); ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="signature-row">
            <div class="signature-col">
                <p><strong>Kepala Madrasah</strong></p>
                <div class="signature-line">
                    <img class="qr-signature" src="<?php echo BASE_URL; ?>qrcode?data=<?php echo rawurlencode('Kepala Madrasah|' . ($settings['nama_kepala'] ?? '') . '|Slip Gaji'); ?>" alt="QR Kepala Madrasah">
                    <div class="signature-name"><?php echo htmlspecialchars($settings['nama_kepala'] ?? ''); ?></div>
                </div>
            </div>
            <div class="signature-col">
                <p><strong>Bendahara</strong></p>
                <div class="signature-line">
                    <img class="qr-signature" src="<?php echo BASE_URL; ?>qrcode?data=<?php echo rawurlencode('Bendahara|' . ($settings['nama_bendahara'] ?? '') . '|Slip Gaji'); ?>" alt="QR Bendahara">
                    <div class="signature-name"><?php echo htmlspecialchars($settings['nama_bendahara'] ?? ''); ?></div>
                </div>
            </div>
        </div>
    </div>
    </div>
    <script>
        window.onload = function() {
            window.print();
        };
    </script>
</body>
</html>
