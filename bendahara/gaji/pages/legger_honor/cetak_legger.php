<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

// Get settings first to get periode_aktif
$sql = "SELECT * FROM settings LIMIT 1";
$settings = $conn->query($sql)->fetch_assoc();
$bulan_aktif = date('Y-m'); // Bulan aktif (current month)
$periode = $_GET['periode'] ?? $bulan_aktif;
$jumlah_periode = isset($settings['jumlah_periode']) ? intval($settings['jumlah_periode']) : 1;
$periode_mulai = $settings['periode_mulai'] ?? '';
$periode_akhir = $settings['periode_akhir'] ?? '';

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

// Get legger_honor data
$sql = "SELECT lh.*, p.nama_pembina, e.jenis_ekstrakurikuler, h.jabatan, h.jumlah_honor as honor_per_pertemuan
        FROM legger_honor lh
        JOIN pembina p ON lh.pembina_id = p.id
        JOIN ekstrakurikuler e ON lh.ekstrakurikuler_id = e.id
        JOIN honor h ON lh.honor_id = h.id
        WHERE lh.periode = ?
        ORDER BY p.nama_pembina ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $periode);
$stmt->execute();
$legger = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Legger Honor - <?php echo getPeriodLabel($periode); ?></title>
    <style>
        @page {
            size: A4 landscape;
            margin: 5mm 10mm 10mm 0mm;
            orphans: 2;
            widows: 2;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            font-size: 11pt;
            padding: 10mm 10mm 10mm 10mm;
            margin: 0;
            margin-bottom: 10mm;
            orphans: 3;
            widows: 3;
            height: auto;
            min-height: auto;
        }
        
        .header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
        }
        
        .header-logo {
            max-width: 60px;
            max-height: 60px;
            margin-right: 3px;
            object-fit: contain;
            flex-shrink: 0;
        }
        
        .header-content {
            flex: 1;
            text-align: center;
        }
        
        .header-content h2 {
            font-size: 18pt;
            margin: 5px 0;
            font-weight: bold;
        }
        
        .header-content p {
            font-size: 12pt;
            margin: 3px 0;
        }
        
        .period-info {
            text-align: center;
            margin-bottom: 15px;
            font-size: 11pt;
            font-weight: bold;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
            margin-bottom: 0;
            font-size: 11pt;
        }
        
        table th,
        table td {
            border: 1px solid #000;
            padding: 6px 8px;
            text-align: left;
            vertical-align: middle;
            height: auto;
            font-size: 11pt;
        }
        
        table th {
            background-color: #f0f0f0;
            font-weight: bold;
            text-align: center;
            font-size: 11pt;
        }
        
        /* Kolom No - width tetap kecil */
        table th:first-child,
        table td:first-child {
            width: 50px;
            text-align: center;
            padding: 6px 8px;
        }
        
        /* Kolom Nama Pembina - width lebih besar */
        table th:nth-child(2),
        table td:nth-child(2) {
            width: 220px;
            min-width: 220px;
            padding: 6px 8px;
        }
        
        /* Kolom Jabatan */
        table th:nth-child(3),
        table td:nth-child(3) {
            width: 180px;
            min-width: 180px;
            padding: 6px 8px;
        }
        
        /* Kolom dengan angka - align right, gunakan font yang sama dengan teks lain */
        table td:nth-child(n+4):not(:last-child) {
            text-align: right;
        }
        
        /* Kolom Honor per Pertemuan */
        table th:nth-child(4) {
            width: 150px;
            text-align: center;
            padding: 6px 8px;
        }
        table td:nth-child(4) {
            width: 150px;
            text-align: right;
            padding: 6px 8px;
        }
        
        /* Kolom Jumlah Pertemuan */
        table th:nth-child(5),
        table td:nth-child(5) {
            width: 100px;
            text-align: center;
            padding: 6px 8px;
        }
        
        /* Kolom Total Honor */
        table th:nth-child(6) {
            width: 160px;
            text-align: center;
            padding: 6px 8px;
        }
        table td:nth-child(6) {
            width: 160px;
            text-align: right;
            padding: 6px 8px;
        }
        
        /* Kolom Tanda Tangan - align center */
        table th:last-child,
        table td:last-child {
            text-align: center;
            font-family: Arial, sans-serif;
            min-width: 150px;
            width: 150px;
            padding: 6px 8px;
        }
        
        /* Total columns - bold */
        .total {
            font-weight: bold;
            background-color: #f9f9f9;
        }
        
        /* Zebra striping */
        tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        .footer {
            margin-top: 0;
            margin-bottom: 0;
            padding-top: 0;
            padding-bottom: 0;
            display: table;
            width: 100%;
            font-size: 11pt;
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
            font-size: 11pt;
        }
        
        .tempat-tanggal {
            text-align: right;
            margin-bottom: 20px;
            font-size: 11pt;
        }
        
        .signature-line {
            width: 80%;
            margin: 30px auto 0 auto;
            min-height: 40px;
            border-top: 1px solid transparent;
            text-align: center;
        }
        
        .qr-signature {
            width: 18mm;
            height: 18mm;
        }
        
        .footer-left p:last-child,
        .footer-right p:last-child {
            margin-top: 8px;
        }
        
        @media print {
            body {
                padding: 5mm 5mm 10mm 0mm;
                margin: 0;
                margin-bottom: 10mm;
            }
            
            .header {
                page-break-inside: avoid;
            }
            
            .header-logo {
                max-width: 60px;
                max-height: 60px;
            }
            
            .header-content h2 {
                font-size: 18pt;
            }
            
            .header-content p {
                font-size: 12pt;
            }
            
            .period-info {
                font-size: 11pt;
            }
            
            .tempat-tanggal {
                text-align: right;
                margin-bottom: 20px;
                font-size: 11pt;
            }
            
            table {
                font-size: 11pt;
            }
            
            table th,
            table td {
                padding: 6px 8px;
                height: auto;
                font-size: 11pt;
            }
            
            /* Ensure table fits on page */
            table {
                page-break-inside: auto;
            }
            
            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            
            thead {
                display: table-header-group;
            }
            
            tfoot {
                display: table-footer-group;
            }
            
            .footer {
                margin-top: 0 !important;
                margin-bottom: 0 !important;
                padding-top: 0 !important;
                padding-bottom: 0 !important;
                font-size: 11pt !important;
                page-break-after: avoid !important;
                break-after: avoid !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            
            .signature-line {
                margin: 30px auto 0 auto !important;
                min-height: 40px !important;
            }
            
            /* Ensure table and footer stay together */
            table {
                page-break-after: avoid !important;
                break-after: avoid !important;
                margin-bottom: 0 !important;
            }
            
            /* Prevent page break before footer */
            table + .footer {
                page-break-before: avoid !important;
                break-before: avoid !important;
            }
            
            .footer-left p,
            .footer-right p {
                margin: 2px 0 !important;
                line-height: 1.2 !important;
                font-size: 11pt !important;
            }
            
            /* Prevent empty pages */
            @page {
                orphans: 2;
                widows: 2;
            }
            
            html, body {
                height: auto !important;
                overflow: visible !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            body {
                padding: 5mm 5mm 10mm 5mm !important;
                margin-bottom: 0 !important;
            }
            
            /* Remove any trailing space */
            body::after {
                display: none !important;
                content: none !important;
            }
            
            /* Prevent page break after footer */
            .footer {
                page-break-after: avoid !important;
                break-after: avoid !important;
            }
            
            /* Ensure table and footer fit on same page */
            table + .footer {
                page-break-before: avoid !important;
                break-before: avoid !important;
            }
            
            /* Prevent empty pages */
            @page {
                orphans: 2;
                widows: 2;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <?php if ($logo_exists): ?>
        <img src="<?php echo $logo_base64; ?>" alt="Logo Madrasah" class="header-logo" onerror="this.src='<?php echo $logo_path; ?>'">
        <?php endif; ?>
        <div class="header-content">
            <h2><?php echo strtoupper(htmlspecialchars($settings['nama_madrasah'] ?? 'MADRASAH IBTIDAIYAH')); ?></h2>
            <p>Legger Honor Ekstrakurikuler</p>
        </div>
    </div>
    
    <div class="period-info">
        Bulan Penerimaan: <?php echo getPeriodLabel($bulan_aktif); ?>
    </div>
    
    <?php
    $qr_kepala_data_legger_honor = 'Kepala Madrasah|' . ($settings['nama_kepala'] ?? '') . '|Legger Honor';
    $qr_bendahara_data_legger_honor = 'Bendahara|' . ($settings['nama_bendahara'] ?? '') . '|Legger Honor';
    ?>
    
    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Nama Pembina</th>
                <th>Jabatan</th>
                <th>Honor per Pertemuan</th>
                <th>Jumlah Pertemuan</th>
                <th>Total Honor</th>
                <th>Tanda Tangan</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1; 
            $total_honor = 0;
            foreach ($legger as $l): 
                $total_honor += $l['total_honor'];
            ?>
            <tr>
                <td><?php echo $no++; ?></td>
                <td><?php echo htmlspecialchars($l['nama_pembina']); ?></td>
                <td><?php echo htmlspecialchars($l['jabatan']); ?></td>
                <td><?php echo formatRupiahTanpaRp($l['jumlah_honor_per_pertemuan']); ?></td>
                <td><?php echo $l['jumlah_pertemuan']; ?></td>
                <td class="total"><?php echo formatRupiahTanpaRp($l['total_honor']); ?></td>
                <td></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <!-- Total row tetap di tfoot; tanda tangan tidak ikut di dalam tabel -->
            <tr style="background-color: #e0e0e0; font-weight: bold;">
                <td colspan="5" style="text-align: center; font-weight: bold;">TOTAL</td>
                <td><?php echo formatRupiahTanpaRp($total_honor); ?></td>
                <td>-</td>
            </tr>
        </tfoot>
    </table>
    
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
    
    <div class="footer" style="margin: 0; padding: 0;">
        <div class="footer-left">
            <p style="margin: 2px 0;"><strong>Mengetahui,</strong></p>
            <p style="margin: 2px 0;"><strong>Kepala Madrasah</strong></p>
            <div class="signature-line">
                <img 
                    class="qr-signature" 
                    src="<?php echo BASE_URL; ?>qrcode?data=<?php echo rawurlencode($qr_kepala_data_legger_honor); ?>" 
                    alt="QR Kepala Madrasah"
                    onerror="if(!this.dataset.fallback){this.dataset.fallback='1';this.src='https://api.qrserver.com/v1/create-qr-code/?size=180x180&data='+encodeURIComponent('<?php echo htmlspecialchars($qr_kepala_data_legger_honor, ENT_QUOTES); ?>');}"
                >
            </div>
            <p style="margin-top: 8px; margin-bottom: 1px;"><?php echo htmlspecialchars($settings['nama_kepala'] ?? ''); ?></p>
        </div>
        <div class="footer-right">
            <p style="margin: 2px 0;">&nbsp;</p>
            <p style="margin: 2px 0;"><strong>Bendahara</strong></p>
            <div class="signature-line">
                <img 
                    class="qr-signature" 
                    src="<?php echo BASE_URL; ?>qrcode?data=<?php echo rawurlencode($qr_bendahara_data_legger_honor); ?>" 
                    alt="QR Bendahara"
                    onerror="if(!this.dataset.fallback){this.dataset.fallback='1';this.src='https://api.qrserver.com/v1/create-qr-code/?size=180x180&data='+encodeURIComponent('<?php echo htmlspecialchars($qr_bendahara_data_legger_honor, ENT_QUOTES); ?>');}"
                >
            </div>
            <p style="margin-top: 8px; margin-bottom: 1px;"><?php echo htmlspecialchars($settings['nama_bendahara'] ?? ''); ?></p>
        </div>
    </div>
    
    <script>
        window.onload = function() {
            // Wait for content to fully render
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>
