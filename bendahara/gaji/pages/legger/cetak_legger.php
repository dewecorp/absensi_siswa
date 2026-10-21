<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

$periode = $_GET['periode'] ?? date('Y-m');

// Get settings
$sql = "SELECT * FROM settings LIMIT 1";
$settings = $conn->query($sql)->fetch_assoc();
$jumlah_periode = isset($settings['jumlah_periode']) ? intval($settings['jumlah_periode']) : 1;
$periode_mulai = $settings['periode_mulai'] ?? '';
$periode_akhir = $settings['periode_akhir'] ?? '';

// Function to get list of months from periode_mulai to periode_akhir
function getMonthList($periode_mulai, $periode_akhir, $jumlah_periode) {
    $months = [];
    if (!empty($periode_mulai) && !empty($periode_akhir) && $jumlah_periode > 1) {
        $start = new DateTime($periode_mulai . '-01');
        $end = new DateTime($periode_akhir . '-01');
        $current = clone $start;
        
        while ($current <= $end) {
            $months[] = $current->format('Y-m');
            $current->modify('+1 month');
        }
    } else {
        // If jumlah_periode = 1, use periode_aktif
        $months[] = $periode_mulai ?: date('Y-m');
    }
    return $months;
}

// Get list of months
$month_list = getMonthList($periode_mulai, $periode_akhir, $jumlah_periode);

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

// Get legger data first to get legger IDs
$sql = "SELECT lg.*, g.nama_lengkap 
        FROM legger_gaji lg 
        JOIN guru g ON lg.guru_id = g.id 
        WHERE lg.periode = ? 
        ORDER BY g.nama_lengkap";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $periode);
$stmt->execute();
$legger = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$sql = "SELECT DISTINCT t.* FROM tunjangan t 
        WHERE t.aktif = 1
        ORDER BY t.nama_tunjangan";
$stmt = $conn->prepare($sql);
$stmt->execute();
$tunjangan = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$sql = "SELECT DISTINCT p.* FROM potongan p 
        WHERE p.aktif = 1
        ORDER BY p.nama_potongan";
$stmt = $conn->prepare($sql);
$stmt->execute();
$potongan = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Legger Gaji - <?php echo getPeriodLabel($periode); ?></title>
    <style>
        @page {
            size: F4 landscape;
            margin: 0mm 10mm 10mm 5mm; /* top: 0cm, right: 1cm, bottom: 1cm, left: 0.5cm */
        }
        
        @media print {
            @page {
                size: 330mm 210mm;
                margin: 0mm 10mm 10mm 5mm; /* top: 0cm, right: 1cm, bottom: 1cm, left: 0.5cm */
            }
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            font-size: 13px;
            padding: 0;
            margin: 0;
            background: white;
        }
        
        .content-wrapper {
            width: 100%;
            max-width: 100%;
            padding: 0mm 5mm 5mm 3mm;
            box-sizing: border-box;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
            background: white;
            overflow-x: visible;
            display: flex;
            flex-direction: column;
        }
        
        .header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            padding-left: 5px;
            padding-top: 5px;
            border-bottom: 1px solid #000;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
            position: relative;
            z-index: 1;
        }
        
        .period-info {
            page-break-inside: avoid !important;
            break-inside: avoid !important;
            text-align: center;
            margin-bottom: 15px;
            margin-top: 5px;
            font-size: 13px;
            font-weight: normal;
            position: relative;
            z-index: 1;
        }
        
        .header-logo {
            max-width: 60px;
            max-height: 60px;
            margin-right: 10px;
            object-fit: contain;
            flex-shrink: 0;
        }
        
        .header-content {
            flex: 1;
            text-align: center;
        }
        
        .header-content h2 {
            font-size: 18px;
            margin: 5px 0;
            font-weight: normal;
            text-transform: uppercase;
        }
        
        .header-content p {
            font-size: 14px;
            margin: 3px 0;
            font-weight: normal;
        }
        
        table {
            width: 100%;
            max-width: 100%;
            border-collapse: collapse;
            margin: 0;
            margin-top: 10px;
            font-size: 13px;
            table-layout: fixed;
            page-break-inside: auto;
            position: relative;
            box-sizing: border-box;
        }
        
        table th,
        table td {
            border: 0.5px solid #000;
            padding: 10px 4px;
            text-align: left;
            vertical-align: middle;
            white-space: nowrap;
            overflow: hidden !important;
            text-overflow: ellipsis;
            box-sizing: border-box;
        }
        
        table td {
            height: auto;
            min-height: 50px;
        }
        
        table th {
            height: auto;
            min-height: 50px;
            background-color: #f0f0f0;
            font-weight: normal;
            text-align: center;
            font-size: 12px;
            white-space: normal;
            word-wrap: break-word;
            overflow-wrap: break-word;
            line-height: 1.4;
            overflow: hidden;
            text-overflow: ellipsis;
            box-sizing: border-box;
        }
        
        table th[colspan] {
            background-color: #e0e0e0;
            font-weight: normal;
            white-space: normal;
            word-wrap: break-word;
            overflow-wrap: break-word;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 13px;
            box-sizing: border-box;
        }
        
        /* Kolom No */
        table th:first-child,
        table td:first-child {
            width: 50px;
            min-width: 50px;
            max-width: 50px;
            text-align: center;
            padding: 10px 5px;
            font-size: 12px;
            overflow: hidden;
            box-sizing: border-box;
        }
        
        /* Kolom Nama */
        table th:nth-child(2),
        table td:nth-child(2) {
            width: 190px;
            min-width: 190px;
            max-width: 190px;
            text-align: left;
            padding: 10px 6px;
            white-space: normal;
            word-wrap: break-word;
            font-size: 12px;
            overflow: hidden;
            box-sizing: border-box;
        }
        
        /* Kolom Gaji Pokok */
        table th:nth-child(3),
        table td:nth-child(3) {
            width: 75px;
            min-width: 75px;
            max-width: 75px;
            text-align: center;
            padding: 10px 3px;
            font-size: 11px;
            overflow: hidden;
            box-sizing: border-box;
        }
        table td:nth-child(3) {
            text-align: right;
            font-family: 'Arial Narrow', Arial, sans-serif;
            font-size: 11px;
            overflow: hidden !important;
            white-space: nowrap;
            text-overflow: ellipsis;
            box-sizing: border-box;
        }
        
        /* Kolom Tunjangan individual - header */
        table thead tr:last-child th:not([rowspan]):not([colspan]) {
            width: 100px;
            min-width: 100px;
            max-width: 100px;
            font-size: 10px;
            padding: 8px 4px;
            white-space: normal;
            word-wrap: break-word;
            line-height: 1.3;
            overflow: hidden;
            box-sizing: border-box;
        }
        
        /* Kolom Tunjangan dan Potongan individual di tbody */
        table tbody tr td:not(:first-child):not(:nth-child(2)):not(:nth-child(3)):not([colspan]):not(:last-child):not(:nth-last-child(4)):not(:nth-last-child(3)):not(:nth-last-child(2)) {
            width: 100px;
            min-width: 100px;
            max-width: 100px;
            font-size: 11px;
            padding: 10px 4px;
            text-align: right;
            font-family: 'Arial Narrow', Arial, sans-serif;
            overflow: hidden !important;
            white-space: nowrap;
            text-overflow: ellipsis;
            box-sizing: border-box;
        }
        
        /* Kolom Total Tunjangan, Total Potongan, Gaji Bersih */
        table th[rowspan="2"]:nth-child(n+4):not(:last-child) {
            width: 75px;
            min-width: 75px;
            max-width: 75px;
            text-align: center;
            font-weight: normal;
            padding: 10px 3px;
            font-size: 11px;
            overflow: hidden;
            box-sizing: border-box;
        }
        
        table td:nth-last-child(4),
        table td:nth-last-child(3),
        table td:nth-last-child(2) {
            width: 75px;
            min-width: 75px;
            max-width: 75px;
            text-align: right;
            font-weight: normal;
            font-size: 11px;
            padding: 10px 3px;
            font-family: 'Arial Narrow', Arial, sans-serif;
            overflow: hidden !important;
            white-space: nowrap;
            text-overflow: ellipsis;
            box-sizing: border-box;
        }
        
        /* Kolom Tanda Tangan */
        table th:last-child,
        table td:last-child {
            width: 90px;
            min-width: 90px;
            max-width: 90px;
            text-align: center;
            padding: 10px 5px;
            font-size: 12px;
            overflow: hidden;
            box-sizing: border-box;
        }
        
        /* Total columns */
        .total {
            font-weight: normal;
            background-color: #f9f9f9;
        }
        
        /* Zebra striping */
        tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        .footer {
            margin-top: 20px;
            display: table;
            width: 100%;
            font-size: 12pt;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }
        
        .tempat-tanggal {
            text-align: right;
            margin-top: 15px;
            margin-bottom: 5px;
            font-size: 12pt;
        }
        
        .footer-left,
        .footer-right {
            display: table-cell;
            width: 50%;
            text-align: center;
            vertical-align: top;
            padding: 0 20px;
        }
        
        .footer-left p,
        .footer-right p {
            margin: 2px 0;
            line-height: 1.2;
            font-size: 12pt;
        }
        
        .signature-line {
            width: 80%;
            margin: 40px auto 5px auto;
            min-height: 40px;
            text-align: center;
        }
        
        .qr-signature {
            width: 18mm;
            height: 18mm;
        }
        
        @media print {
            body {
                padding: 0;
                margin: 0;
            }
            
            .content-wrapper {
                padding: 2mm 0mm;
                width: 100%;
                max-width: 100%;
            }
            
            .header {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                page-break-after: avoid !important;
                margin-bottom: 20px;
                padding-bottom: 12px;
                padding-top: 5px;
            }
            
            .period-info {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                page-break-after: avoid !important;
                margin-bottom: 15px;
                margin-top: 5px;
            }
            
            .footer {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                page-break-before: avoid !important;
                font-size: 12pt !important;
            }
            
            .header-logo {
                max-width: 60px;
                max-height: 60px;
                margin-right: 10px;
            }
            
            .header-content h2 {
                font-size: 18px;
            }
            
            .header-content p {
                font-size: 14px;
            }
            
            .period-info {
                font-size: 13px;
            }
            
            table {
                font-size: 12px;
                margin-top: 8px;
                page-break-inside: auto;
                width: 100%;
                max-width: 100%;
                table-layout: auto;
            }
            
            table th,
            table td {
                padding: 6px 3px;
                overflow: visible !important;
                color: #000000 !important;
                white-space: normal !important;
                text-overflow: clip !important;
                font-family: 'Arial Narrow', Arial, sans-serif !important;
            }
            
            /* Pastikan semua angka di body tabel tidak terpotong */
            table tbody tr td {
                overflow: visible !important;
                white-space: normal !important;
                text-overflow: clip !important;
                line-height: 1.2;
            }
            
            /* Override kolom angka yang sebelumnya dipaksa ellipsis */
            table tbody tr td:nth-child(3),
            table tbody tr td:not(:first-child):not(:nth-child(2)):not(:nth-child(3)):not([colspan]):not(:last-child):not(:nth-last-child(4)):not(:nth-last-child(3)):not(:nth-last-child(2)),
            table tbody tr td:nth-last-child(4),
            table tbody tr td:nth-last-child(3),
            table tbody tr td:nth-last-child(2) {
                overflow: visible !important;
                white-space: normal !important;
                text-overflow: clip !important;
            }
            
            /* Reset width kolom di print supaya tidak melebar dan tidak terpotong */
            table th,
            table td {
                width: auto !important;
                min-width: 0 !important;
                max-width: none !important;
            }
            
            /* Lebar kolom No, Nama, Gaji Pokok, Tanda Tangan di print */
            table th:first-child,
            table td:first-child {
                width: 25px !important;
            }
            
            table th:nth-child(2),
            table td:nth-child(2) {
                width: 160px !important;
            }
            
            table th:nth-child(3),
            table td:nth-child(3) {
                width: 55px !important;
            }
            
            /* Kolom Tanda Tangan boleh wrap agar muat penuh */
            table th:last-child,
            table td:last-child {
                width: 80px !important;
                white-space: normal !important;
                word-wrap: break-word;
                overflow-wrap: break-word;
                overflow: visible !important;
            }
            
            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            
            thead {
                display: table-header-group;
                page-break-inside: avoid;
            }
            
            tfoot {
                display: table-footer-group;
            }
        }
    </style>
</head>
<body>
    <div class="content-wrapper">
    <div class="header">
        <?php if ($logo_exists): ?>
        <img src="<?php echo $logo_base64; ?>" alt="Logo Madrasah" class="header-logo" onerror="this.src='<?php echo $logo_path; ?>'">
        <?php endif; ?>
        <div class="header-content">
            <h2><?php echo strtoupper(htmlspecialchars($settings['nama_madrasah'] ?? 'MADRASAH IBTIDAIYAH')); ?></h2>
            <p>LEGGER GAJI</p>
            <?php if (!empty($settings['tahun_ajaran'])): ?>
                <p>Tahun Ajaran <?php echo htmlspecialchars($settings['tahun_ajaran']); ?></p>
            <?php endif; ?>
        </div>
    </div>
    
    <?php 
    // Get base legger data (from periode_aktif, which contains multiplied data)
    $sql = "SELECT lg.*, g.nama_lengkap 
            FROM legger_gaji lg 
            JOIN guru g ON lg.guru_id = g.id 
            WHERE lg.periode = ? 
            ORDER BY g.nama_lengkap";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $periode);
    $stmt->execute();
    $legger_base = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Loop per bulan
    foreach ($month_list as $month_index => $current_month): 
        // Use base legger data and divide by jumlah_periode for each month
        $legger_month = $legger_base;
        
        // Use tunjangan and potongan from base periode (already loaded above)
        $tunjangan_month = $tunjangan;
        $potongan_month = $potongan;
        
        // If no data, skip
        if (empty($legger_month)) {
            continue;
        }
        
        // Add page break for each month except first
        if ($month_index > 0): ?>
            <div style="page-break-before: always;"></div>
        <?php endif; ?>
        
        <div class="period-info">
            Periode: <?php echo getPeriodLabel($current_month); ?>
        </div>
        
    <table>
        <thead>
            <tr>
                <th rowspan="2">No</th>
                <th rowspan="2">Nama</th>
                <th rowspan="2">Gaji Pokok</th>
                <?php if (count($tunjangan_month) > 0): ?>
                    <th colspan="<?php echo count($tunjangan_month); ?>" class="text-center">Tunjangan</th>
                <?php endif; ?>
                <th rowspan="2">Total Tunjangan</th>
                <?php if (count($potongan_month) > 0): ?>
                    <th colspan="<?php echo count($potongan_month); ?>" class="text-center">Potongan</th>
                <?php endif; ?>
                <th rowspan="2">Total Potongan</th>
                <th rowspan="2">Gaji Bersih</th>
                <th rowspan="2">Tanda Tangan</th>
            </tr>
            <tr>
                <?php foreach ($tunjangan_month as $t): ?>
                    <th><?php echo htmlspecialchars($t['nama_tunjangan']); ?></th>
                <?php endforeach; ?>
                <?php foreach ($potongan_month as $p): ?>
                    <th><?php echo htmlspecialchars($p['nama_potongan']); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1; 
            $total_gaji_pokok_month = 0;
            $total_tunjangan_all_month = 0;
            $total_potongan_all_month = 0;
            $total_gaji_bersih_month = 0;
            foreach ($legger_month as $l): 
                // Get legger details
                $sql = "SELECT * FROM legger_detail WHERE legger_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $l['id']);
                $stmt->execute();
                $details = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                
                $tunjangan_data = [];
                $potongan_data = [];
                foreach ($details as $d) {
                    if ($d['jenis'] == 'tunjangan') {
                        $tunjangan_data[$d['item_id']] = $d['jumlah'];
                    } else {
                        $potongan_data[$d['item_id']] = $d['jumlah'];
                    }
                }
                
                $gaji_pokok_per_bulan = $l['gaji_pokok'] / $jumlah_periode;
                $total_tunjangan_raw = 0;
                foreach ($tunjangan_month as $t) {
                    $total_tunjangan_raw += floatval($tunjangan_data[$t['id']] ?? 0);
                }
                $total_potongan_raw = 0;
                foreach ($potongan_month as $p) {
                    $total_potongan_raw += floatval($potongan_data[$p['id']] ?? 0);
                }
                $total_tunjangan_per_bulan = $total_tunjangan_raw / $jumlah_periode;
                $total_potongan_per_bulan = $total_potongan_raw / $jumlah_periode;
                $gaji_bersih_per_bulan = $gaji_pokok_per_bulan + $total_tunjangan_per_bulan - $total_potongan_per_bulan;
                
                $total_gaji_pokok_month += $gaji_pokok_per_bulan;
                $total_tunjangan_all_month += $total_tunjangan_per_bulan;
                $total_potongan_all_month += $total_potongan_per_bulan;
                $total_gaji_bersih_month += $gaji_bersih_per_bulan;
            ?>
            <tr>
                <td><?php echo $no++; ?></td>
                <td><?php echo htmlspecialchars($l['nama_lengkap']); ?></td>
                <td><?php echo formatRupiahTanpaRp($gaji_pokok_per_bulan); ?></td>
                <?php foreach ($tunjangan_month as $t): ?>
                    <td><?php echo formatRupiahTanpaRp(($tunjangan_data[$t['id']] ?? 0) / $jumlah_periode); ?></td>
                <?php endforeach; ?>
                <td class="total"><?php echo formatRupiahTanpaRp($total_tunjangan_per_bulan); ?></td>
                <?php foreach ($potongan_month as $p): ?>
                    <td><?php echo formatRupiahTanpaRp(($potongan_data[$p['id']] ?? 0) / $jumlah_periode); ?></td>
                <?php endforeach; ?>
                <td class="total"><?php echo formatRupiahTanpaRp($total_potongan_per_bulan); ?></td>
                <td class="total"><?php echo formatRupiahTanpaRp($gaji_bersih_per_bulan); ?></td>
                <td></td>
            </tr>
            <?php endforeach; ?>
            
            <!-- Total row -->
            <tr style="background-color: #e0e0e0; font-weight: normal;">
                <td colspan="2" style="text-align: center; font-weight: normal;">TOTAL</td>
                <td><?php echo formatRupiahTanpaRp($total_gaji_pokok_month); ?></td>
                <?php foreach ($tunjangan_month as $t): ?>
                    <td>-</td>
                <?php endforeach; ?>
                <td><?php echo formatRupiahTanpaRp($total_tunjangan_all_month); ?></td>
                <?php foreach ($potongan_month as $p): ?>
                    <td>-</td>
                <?php endforeach; ?>
                <td><?php echo formatRupiahTanpaRp($total_potongan_all_month); ?></td>
                <td><?php echo formatRupiahTanpaRp($total_gaji_bersih_month); ?></td>
                <td>-</td>
            </tr>
        </tbody>
    </table>
    
    <?php endforeach; ?>
    
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
    
    <?php
    $qr_kepala_data = 'Kepala Madrasah|' . ($settings['nama_kepala'] ?? '') . '|Legger Gaji';
    $qr_bendahara_data = 'Bendahara|' . ($settings['nama_bendahara'] ?? '') . '|Legger Gaji';
    ?>
    <div class="footer">
        <div class="footer-left">
            <p>Mengetahui,</p>
            <p>Kepala Madrasah</p>
            <div class="signature-line">
                <img 
                    class="qr-signature" 
                    src="<?php echo BASE_URL; ?>qrcode?data=<?php echo rawurlencode($qr_kepala_data); ?>" 
                    alt="QR Kepala Madrasah"
                    onerror="if(!this.dataset.fallback){this.dataset.fallback='1';this.src='https://api.qrserver.com/v1/create-qr-code/?size=180x180&data='+encodeURIComponent('<?php echo htmlspecialchars($qr_kepala_data, ENT_QUOTES); ?>');}"
                >
            </div>
            <p><?php echo htmlspecialchars($settings['nama_kepala'] ?? ''); ?></p>
        </div>
        <div class="footer-right">
            <p>&nbsp;</p>
            <p>Bendahara</p>
            <div class="signature-line">
                <img 
                    class="qr-signature" 
                    src="<?php echo BASE_URL; ?>qrcode?data=<?php echo rawurlencode($qr_bendahara_data); ?>" 
                    alt="QR Bendahara"
                    onerror="if(!this.dataset.fallback){this.dataset.fallback='1';this.src='https://api.qrserver.com/v1/create-qr-code/?size=180x180&data='+encodeURIComponent('<?php echo htmlspecialchars($qr_bendahara_data, ENT_QUOTES); ?>');}"
                >
            </div>
            <p><?php echo htmlspecialchars($settings['nama_bendahara'] ?? ''); ?></p>
        </div>
    </div>
    </div>
    
    <script>
        window.onload = function() {
            // Auto print when page loads
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>
