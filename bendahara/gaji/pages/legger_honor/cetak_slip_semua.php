<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

// Get settings first to get periode_aktif
$sql = "SELECT * FROM settings LIMIT 1";
$settings = $conn->query($sql)->fetch_assoc();
$bulan_aktif = date('Y-m'); // Bulan aktif (current month)
$periode = $_GET['periode'] ?? date('Y-m'); // Use current month for honor

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

// Get all legger_honor data for the period
$sql = "SELECT lh.*, p.nama_pembina, e.jenis_ekstrakurikuler, h.jabatan, h.jumlah_honor as honor_per_pertemuan
        FROM legger_honor lh
        JOIN pembina p ON lh.pembina_id = p.id
        JOIN ekstrakurikuler e ON lh.ekstrakurikuler_id = e.id
        JOIN honor h ON lh.honor_id = h.id
        WHERE lh.periode = ?
        ORDER BY p.nama_pembina";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $periode);
$stmt->execute();
$legger_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Cetak Slip Honor Semua - <?php echo getPeriodLabel($periode); ?></title>
    <style>
        @page {
            size: F4;
            margin: 10mm 5mm 0 5mm; /* top: 1cm (10mm), right: 5mm, bottom: 0, left: 5mm */
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            padding: 0;
            margin: 0;
        }
        
        .page {
            width: 210mm;
            min-height: auto;
            height: auto;
            padding: 0.5mm 0.5mm 0 0.5mm;
            margin: 0 auto;
            margin-bottom: 0;
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-auto-rows: auto;
            gap: 5mm;
            box-sizing: border-box;
            align-content: start;
        }
        
        .page:not(.page-last) {
            page-break-after: always;
        }
        
        .page.page-last {
            page-break-after: auto !important;
            margin-bottom: 0 !important;
        }
        
        .page:empty {
            display: none !important;
            visibility: hidden !important;
            height: 0 !important;
            min-height: 0 !important;
            max-height: 0 !important;
            padding: 0 !important;
            margin: 0 !important;
            page-break-after: avoid !important;
            page-break-before: avoid !important;
        }
        
        .page.no-content {
            display: none !important;
            visibility: hidden !important;
            height: 0 !important;
            min-height: 0 !important;
            max-height: 0 !important;
            padding: 0 !important;
            margin: 0 !important;
            page-break-after: avoid !important;
            page-break-before: avoid !important;
        }
        
        .slip {
            border: 1px solid #000;
            padding: 4mm 3mm 3mm 3mm;
            display: flex;
            flex-direction: column;
            width: 100%;
            height: auto;
            min-height: auto;
            max-height: none;
            page-break-inside: avoid;
            break-inside: avoid;
            overflow: hidden;
            box-sizing: border-box;
        }
        
        .header {
            display: flex;
            align-items: center;
            margin-bottom: 3.5mm;
            border-bottom: 1px solid #000;
            padding-bottom: 0.5mm;
            flex-shrink: 0;
        }
        
        .header-logo {
            max-width: 35px;
            max-height: 35px;
            margin-right: 2mm;
            object-fit: contain;
            flex-shrink: 0;
        }
        
        .header-content {
            flex: 1;
            text-align: center;
        }
        
        .header-content h2 {
            font-size: 16px;
            margin: 0;
            font-weight: bold;
            line-height: 1.1;
        }
        
        .header-content p {
            font-size: 12px;
            margin: 0;
            line-height: 1.1;
        }
        
        .info-table {
            margin: 1mm 0 0 0;
            border: none;
            width: 100%;
            table-layout: fixed;
            font-size: 12px;
            flex-shrink: 0;
        }
        .info-table tr {
            border: none;
        }
        .info-table td {
            border: none;
            padding: 0.3mm 0;
            vertical-align: top;
            font-size: 12px;
            line-height: 1.1;
        }
        .info-table td:first-child {
            width: 120px;
            white-space: nowrap;
            padding-right: 1mm;
        }
        .info-table td:nth-child(2) {
            width: 5px;
            text-align: left;
            padding-right: 1mm;
        }
        .info-table td:last-child {
            width: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 2mm 0 0 0;
            font-size: 12px;
            flex-shrink: 0;
        }
        
        th, td {
            border: 1px solid #000;
            padding: 1mm 0.5mm;
            text-align: left;
            line-height: 1.3;
            word-wrap: break-word;
        }
        
        th {
            background-color: #f0f0f0;
            font-weight: bold;
            font-size: 12px;
        }
        
        .text-right {
            text-align: right;
        }

        table:not(.info-table) th:last-child,
        table:not(.info-table) td:last-child {
            text-align: right !important;
            padding-right: 2mm;
            white-space: nowrap;
        }
        
        .total {
            font-weight: bold;
        }
        
        .signature-row {
            margin-top: 15mm;
            padding-top: 0.5mm;
            border-top: 1px solid #000;
            font-size: 12px;
            flex-shrink: 0;
            display: table;
            width: 100%;
        }
        
        .signature-col {
            display: table-cell;
            width: 50%;
            text-align: center;
            vertical-align: top;
            padding: 0 0.1mm;
        }
        
        .signature-col p {
            margin: 0;
            line-height: 1.1;
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
        }
        
        .qr-signature {
            width: 14mm;
            height: 14mm;
        }
        
        .tempat-tanggal {
            margin-top: 4mm;
            margin-bottom: 6mm;
            text-align: right;
            font-size: 12px;
            padding-right: 0.1mm;
            flex-shrink: 0;
        }
        
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            
            .page {
                margin: 0;
                margin-bottom: 0 !important;
                padding: 0.5mm 0.5mm 0 0.5mm !important;
                width: 210mm !important;
                min-height: auto !important;
                height: auto !important;
                display: grid !important;
                grid-template-columns: 1fr 1fr !important;
                grid-auto-rows: auto !important;
                gap: 5mm !important;
                align-content: start !important;
            }
            
            .page:not(.page-last) {
                page-break-after: always !important;
            }
            
            .page.page-last {
                page-break-after: auto !important;
                margin-bottom: 0 !important;
            }
            
            .page:empty {
                display: none !important;
                visibility: hidden !important;
                height: 0 !important;
                min-height: 0 !important;
                max-height: 0 !important;
                padding: 0 !important;
                margin: 0 !important;
                page-break-after: avoid !important;
                page-break-before: avoid !important;
            }
            
            .page.no-content {
                display: none !important;
                visibility: hidden !important;
                height: 0 !important;
                min-height: 0 !important;
                max-height: 0 !important;
                padding: 0 !important;
                margin: 0 !important;
                page-break-after: avoid !important;
                page-break-before: avoid !important;
            }
            
            .slip {
                width: 100% !important;
                height: auto !important;
                min-height: auto !important;
                max-height: none !important;
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            .header-logo {
                max-width: 35px;
                max-height: 35px;
            }
            
            
            .header {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <?php
    $count = 0;
    $total = count($legger_list);
    $slips_per_page = 4;
    $current_page = 0;
    $page_opened = false;
    
    foreach ($legger_list as $index => $legger):
        // Start new page every 4 slips (2x2 grid)
        if ($count % $slips_per_page == 0):
            if ($page_opened):
                echo '</div>'; // Close previous page
            endif;
            // Calculate remaining slips after current one (including current)
            $remaining_slips = $total - $count;
            // This is the last page if remaining slips fit in one page
            $is_last_page = ($remaining_slips <= $slips_per_page);
            $page_class = $is_last_page ? 'page page-last' : 'page';
            echo '<div class="' . $page_class . '">';
            $page_opened = true;
            $current_page++;
        endif;
    ?>
        <div class="slip">
            <div class="header">
                <?php if ($logo_exists): ?>
                <img src="<?php echo $logo_base64; ?>" alt="Logo Madrasah" class="header-logo" onerror="this.src='<?php echo $logo_path; ?>'">
                <?php endif; ?>
                <div class="header-content">
                    <h2><?php echo strtoupper(htmlspecialchars($settings['nama_madrasah'])); ?></h2>
                    <p>Slip Honor Ekstrakurikuler</p>
                </div>
            </div>
            <table class="info-table">
                <tr>
                    <td><strong>Bulan Penerimaan</strong></td>
                    <td><strong>: </strong></td>
                    <td><?php echo getPeriodLabel($bulan_aktif); ?></td>
                </tr>
                <tr>
                    <td><strong>Nama</strong></td>
                    <td><strong>: </strong></td>
                    <td><?php echo htmlspecialchars($legger['nama_pembina']); ?></td>
                </tr>
                <tr>
                    <td><strong>Jabatan</strong></td>
                    <td><strong>: </strong></td>
                    <td><?php echo htmlspecialchars($legger['jabatan']); ?></td>
                </tr>
            </table>
            <table>
                <tr>
                    <th>Keterangan</th>
                    <th class="text-right">Jumlah</th>
                </tr>
                <tr>
                    <td>Honor per Pertemuan</td>
                    <td class="text-right"><?php echo formatRupiah($legger['jumlah_honor_per_pertemuan']); ?></td>
                </tr>
                <tr>
                    <td>Jumlah Pertemuan</td>
                    <td class="text-right"><?php echo $legger['jumlah_pertemuan']; ?> x</td>
                </tr>
                <tr>
                    <td class="total">Total Honor</td>
                    <td class="text-right total"><?php echo formatRupiah($legger['total_honor']); ?></td>
                </tr>
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
            <?php
            $qr_kepala_data_honor = 'Kepala Madrasah|' . ($settings['nama_kepala'] ?? '') . '|Slip Honor';
            $qr_bendahara_data_honor = 'Bendahara|' . ($settings['nama_bendahara'] ?? '') . '|Slip Honor';
            ?>
            <div class="signature-row">
                <div class="signature-col">
                    <p><strong>Kepala Madrasah</strong></p>
                    <div class="signature-line">
                        <img 
                            class="qr-signature" 
                            src="<?php echo BASE_URL; ?>qrcode?data=<?php echo rawurlencode($qr_kepala_data_honor); ?>" 
                            alt="QR Kepala Madrasah"
                            onerror="if(!this.dataset.fallback){this.dataset.fallback='1';this.src='https://api.qrserver.com/v1/create-qr-code/?size=160x160&data='+encodeURIComponent('<?php echo htmlspecialchars($qr_kepala_data_honor, ENT_QUOTES); ?>');}"
                        >
                    </div>
                    <p><?php echo htmlspecialchars($settings['nama_kepala'] ?? ''); ?></p>
                </div>
                <div class="signature-col">
                    <p><strong>Bendahara</strong></p>
                    <div class="signature-line">
                        <img 
                            class="qr-signature" 
                            src="<?php echo BASE_URL; ?>qrcode?data=<?php echo rawurlencode($qr_bendahara_data_honor); ?>" 
                            alt="QR Bendahara"
                            onerror="if(!this.dataset.fallback){this.dataset.fallback='1';this.src='https://api.qrserver.com/v1/create-qr-code/?size=160x160&data='+encodeURIComponent('<?php echo htmlspecialchars($qr_bendahara_data_honor, ENT_QUOTES); ?>');}"
                        >
                    </div>
                    <p><?php echo htmlspecialchars($settings['nama_bendahara'] ?? ''); ?></p>
                </div>
            </div>
        </div>
    <?php
        $count++;
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
                            // Check if slip exists and has content
                            if (slip && slip.offsetHeight > 0 && slip.offsetWidth > 0) {
                                var slipText = slip.textContent || slip.innerText || '';
                                if (slipText.replace(/\s+/g, '').trim().length > 0) {
                                    hasContent = true;
                                    break;
                                }
                            }
                        }
                    }
                    
                    // If no slips or no content, mark for removal
                    if (!hasContent || slips.length === 0) {
                        pagesToRemove.push(page);
                    }
                });
                
                // Remove pages from DOM
                pagesToRemove.forEach(function(page) {
                    page.classList.add('no-content');
                    page.style.display = 'none';
                    page.style.visibility = 'hidden';
                    page.style.height = '0';
                    page.style.minHeight = '0';
                    page.style.maxHeight = '0';
                    page.style.padding = '0';
                    page.style.margin = '0';
                    page.style.pageBreakAfter = 'avoid';
                    page.style.pageBreakBefore = 'avoid';
                    if (page.parentNode) {
                        try {
                            page.parentNode.removeChild(page);
                            removed++;
                        } catch(e) {
                            console.error('Error removing page:', e);
                        }
                    }
                });
                
                // Ensure last page is marked correctly after removal
                var remainingPages = document.querySelectorAll('.page:not(.no-content)');
                if (remainingPages.length > 0) {
                    // Remove page-last from all pages first
                    remainingPages.forEach(function(p) {
                        p.classList.remove('page-last');
                        p.style.pageBreakAfter = '';
                    });
                    // Add page-last to the actual last page
                    var lastPage = remainingPages[remainingPages.length - 1];
                    if (lastPage) {
                        lastPage.classList.add('page-last');
                        lastPage.style.pageBreakAfter = 'auto';
                    }
                }
                
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
                    removeEmptyPages();
                    setTimeout(function() {
                        removeEmptyPages();
                        setTimeout(function() {
                            removeEmptyPages();
                            setTimeout(function() {
                                removeEmptyPages();
                                setTimeout(function() {
                                    removeEmptyPages();
                                    // Final check before print
                                    setTimeout(function() {
                                        removeEmptyPages();
                                        window.print();
                                    }, 100);
                                }, 50);
                            }, 50);
                        }, 50);
                    }, 50);
                }, 100);
            };
            
            // Remove empty pages before print event
            window.addEventListener('beforeprint', function() {
                removeEmptyPages();
                // Ensure last page doesn't have page-break-after
                var pages = document.querySelectorAll('.page:not(.no-content)');
                if (pages.length > 0) {
                    pages.forEach(function(p) {
                        p.classList.remove('page-last');
                        p.style.pageBreakAfter = '';
                    });
                    var lastPage = pages[pages.length - 1];
                    if (lastPage) {
                        lastPage.classList.add('page-last');
                        lastPage.style.pageBreakAfter = 'auto';
                    }
                }
            });
        })();
    </script>
</body>
</html>
