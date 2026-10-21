<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

$periode = $_GET['periode'] ?? date('Y-m');

// Get settings
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

// Get all legger data for the period
$sql = "SELECT lg.*, g.nama_lengkap 
        FROM legger_gaji lg 
        JOIN guru g ON lg.guru_id = g.id 
        WHERE lg.periode = ? 
        ORDER BY g.nama_lengkap";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $periode);
$stmt->execute();
$legger_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get all legger details
$all_details = [];
foreach ($legger_list as $l) {
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
    $stmt->bind_param("i", $l['id']);
    $stmt->execute();
    $details = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $all_details[$l['id']] = $details;
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Cetak Slip Gaji Semua - <?php echo getPeriodLabel($periode); ?></title>
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
            font-size: 12px;
            padding: 0;
            margin: 0;
            overflow: visible;
        }
        
        /* Prevent empty pages */
        body:empty {
            display: none;
        }
        
        .page {
            width: 200mm; /* 210mm - 5mm left - 5mm right */
            min-height: 150mm; /* Minimum height for 1 slip */
            max-height: 315mm; /* Maximum height for 2 slips */
            height: auto;
            page-break-inside: avoid;
            break-inside: avoid;
            display: flex;
            flex-direction: row;
            gap: 3mm;
            padding: 2mm;
            margin: 0 auto;
            margin-bottom: 0;
            box-sizing: border-box;
            align-items: flex-start;
        }
        
        /* Add page break only between pages with content */
        .page:not(.page-last) {
            page-break-after: always;
            margin-bottom: 0;
        }
        
        .page.page-last {
            page-break-after: auto !important;
            margin-bottom: 0 !important;
        }
        
        /* Hide completely empty pages - handled by JavaScript */
        .page.no-content {
            display: none !important;
        }
        
        .page:empty {
            display: none !important;
            visibility: hidden !important;
            height: 0 !important;
            min-height: 0 !important;
            max-height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            overflow: hidden !important;
        }
        
        /* Hide pages with no visible slips - handled by JavaScript */
        .page.no-slips {
            display: none !important;
        }
        
        .slip {
            width: calc((200mm - 3mm - 4mm) / 2); /* Setengah lebar halaman dikurangi gap dan padding */
            height: auto;
            min-height: auto;
            max-height: 300mm !important; /* Maksimal tinggi untuk memastikan 2 slip muat */
            overflow: hidden;
            border: 1px solid #000;
            padding: 3mm;
            display: flex;
            flex-direction: column;
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
        
        table {
            width: 100%;
            border-collapse: collapse;
            border-spacing: 0;
            margin: 2mm 0 2mm 0;
            flex: 1;
            min-height: 0;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .total {
            font-weight: bold;
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
            border-top: none;
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
            
            /* Prevent empty pages in print */
            body:empty {
                display: none !important;
            }
            
            .page {
                width: 200mm !important;
                min-height: 150mm !important;
                max-height: 315mm !important;
                height: auto !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                display: flex !important;
                flex-direction: row !important;
                gap: 3mm !important;
                padding: 2mm !important;
                margin-bottom: 0 !important;
                box-sizing: border-box !important;
                align-items: flex-start !important;
            }
            
            /* Add page break only between pages with content */
            .page:not(.page-last) {
                page-break-after: always !important;
                margin-bottom: 0 !important;
            }
            
            .page.page-last {
                page-break-after: auto !important;
                margin-bottom: 0 !important;
            }
            
            /* Hide completely empty pages in print - handled by JavaScript */
            .page.no-content {
                display: none !important;
            }
            
            .page:empty {
                display: none !important;
                visibility: hidden !important;
                height: 0 !important;
                min-height: 0 !important;
                max-height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: hidden !important;
                page-break-after: avoid !important;
                page-break-before: avoid !important;
            }
            
            /* Hide pages with no visible slips in print - handled by JavaScript */
            .page.no-slips {
                display: none !important;
            }
            
            .page:last-child {
                page-break-after: auto !important;
            }
            
            .slip {
                width: calc((200mm - 3mm - 4mm) / 2) !important;
                height: auto !important;
                min-height: auto !important;
                max-height: 300mm !important;
                overflow: hidden !important;
                padding: 3mm !important;
            }
            
            .signature-row {
                margin-top: 0.5mm !important;
                padding-top: 0.5mm !important;
            }
            
            .signature-col p {
                margin: 0 !important;
                line-height: 1.1 !important;
            }
            
            .signature-name {
                position: static !important;
                margin-top: 1mm !important;
                margin-bottom: 0 !important;
                white-space: nowrap !important;
            }
            
            .header {
                margin-bottom: 1mm !important;
                padding-bottom: 2mm !important;
            }
            
            .header-logo {
                max-width: 35px !important;
                max-height: 35px !important;
                margin-right: 5mm !important;
            }
            
            .header-content h3 {
                font-size: 16px !important;
                line-height: 1.3 !important;
            }
            
            .header-content p {
                font-size: 12px !important;
                line-height: 1.3 !important;
            }
            
            .info {
                margin: 2mm 0 !important;
                font-size: 14px !important;
            }
            
            .table-wrapper {
                margin: 2mm 0 !important;
            }
            
            .table-row {
                height: 22px !important;
                min-height: 22px !important;
                max-height: 22px !important;
            }
            
            .table-cell, .table-header {
                height: 22px !important;
                min-height: 22px !important;
                max-height: 22px !important;
                line-height: 22px !important;
                font-size: 14px !important;
                padding: 0 5px !important;
                border-bottom: 1px solid #000 !important;
            }
            
            .table-header {
                text-transform: uppercase !important;
            }
            
            .signature-row {
                margin-top: 2mm !important;
                padding-top: 1mm !important;
                font-size: 11px !important;
            }
            
            .signature-col {
                padding: 0 5mm !important;
            }
            
            .signature-col p {
                line-height: 1.2 !important;
            }
            
            .signature-col p:last-child {
                margin-top: 10mm !important;
            }
        }
    </style>
</head>
<body>
    <?php
    $count = 0;
    $total = count($legger_list);
    $slips_per_page = 2;
    $total_pages = ceil($total / $slips_per_page);
    $current_page = 0;
    $page_opened = false;
    
    foreach ($legger_list as $index => $legger):
        $details = $all_details[$legger['id']];
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
        
        // Start new page every 2 slips (at position 0, 2, 4, etc.)
        if ($count % $slips_per_page == 0):
            // Close previous page if exists (except for first page)
            if ($page_opened):
                echo '</div>'; // Close previous page
            endif;
            // Determine if this will be the last page
            $current_page++;
            $is_last_page = ($current_page == $total_pages);
            $page_class = $is_last_page ? 'page page-last' : 'page';
            echo '<div class="' . $page_class . '">';
            $page_opened = true;
        endif;
        
        $count++;
    ?>
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
    <?php
    endforeach;
    
    // Always close the last page after loop ends (only if page was opened)
    if ($page_opened):
        echo '</div>'; // Close last page
    endif;
    ?>
    <script>
        (function() {
            function removeEmptyPages() {
                var pages = document.querySelectorAll('.page');
                var removed = 0;
                var pagesToRemove = [];
                
                pages.forEach(function(page) {
                    var slips = page.querySelectorAll('.slip');
                    var hasContent = false;
                    
                    // Check if page has any visible slips with content
                    if (slips.length > 0) {
                        for (var i = 0; i < slips.length; i++) {
                            var slip = slips[i];
                            // Check if slip has visible dimensions
                            if (slip.offsetHeight > 0 && slip.offsetWidth > 0) {
                                // Check if slip has actual content
                                var slipText = slip.textContent || slip.innerText || '';
                                if (slipText.replace(/\s+/g, '').trim().length > 0) {
                                    hasContent = true;
                                    break;
                                }
                            }
                        }
                    }
                    
                    // Also check page's direct text content (excluding whitespace)
                    if (!hasContent) {
                        var pageText = page.textContent || page.innerText || '';
                        var trimmed = pageText.replace(/\s+/g, '').trim();
                        if (trimmed.length > 0) {
                            hasContent = true;
                        }
                    }
                    
                    // If no content found, mark for removal
                    if (!hasContent || slips.length === 0) {
                        pagesToRemove.push(page);
                    }
                });
                
                // Remove pages from DOM
                pagesToRemove.forEach(function(page) {
                    page.classList.add('no-slips', 'no-content');
                    page.style.display = 'none';
                    page.style.visibility = 'hidden';
                    page.style.height = '0';
                    page.style.margin = '0';
                    page.style.padding = '0';
                    if (page.parentNode) {
                        try {
                            page.parentNode.removeChild(page);
                            removed++;
                        } catch(e) {
                            console.error('Error removing page:', e);
                        }
                    }
                });
                
                return removed;
            }
            
            // Remove empty pages immediately when DOM is ready
            function init() {
                var removed = removeEmptyPages();
                if (removed > 0) {
                    console.log('Removed ' + removed + ' empty pages');
                }
            }
            
            // Run immediately if DOM is ready, otherwise wait
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    setTimeout(init, 50);
                });
            } else {
                setTimeout(init, 50);
            }
            
            // Also remove before print - multiple passes to be absolutely sure
            window.onload = function() {
                setTimeout(function() {
                    var removed1 = removeEmptyPages();
                    setTimeout(function() {
                        var removed2 = removeEmptyPages();
                        setTimeout(function() {
                            var removed3 = removeEmptyPages();
                            setTimeout(function() {
                                var removed4 = removeEmptyPages();
                                setTimeout(function() {
                                    var removed5 = removeEmptyPages();
                                    var totalRemoved = removed1 + removed2 + removed3 + removed4 + removed5;
                                    if (totalRemoved > 0) {
                                        console.log('Removed ' + totalRemoved + ' empty pages before print');
                                    }
                                    // Final check before print
                                    setTimeout(function() {
                                        removeEmptyPages();
                                        window.print();
                                    }, 100);
                                }, 30);
                            }, 30);
                        }, 30);
                    }, 30);
                }, 100);
            };
        })();
    </script>
</body>
</html>
