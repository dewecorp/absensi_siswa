<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

/** Cegah CDN/browser menyimpan cache HTML/CSS cetakan (biasanya masalah di hosting). */
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

$sql = "SELECT * FROM settings WHERE id = 1 LIMIT 1";
$qr_settings = $conn->query($sql);
$settings = ($qr_settings && $qr_settings->num_rows > 0) ? $qr_settings->fetch_assoc() : [];

$periode_aktif = trim((string)($settings['periode_aktif'] ?? ''));
if ($periode_aktif === '') {
    $periode_aktif = date('Y-m');
}
$jumlah_periode = isset($settings['jumlah_periode']) ? (int)$settings['jumlah_periode'] : 1;
$periode_mulai = trim((string)($settings['periode_mulai'] ?? ''));
$periode_akhir = trim((string)($settings['periode_akhir'] ?? ''));

/** Label bulan di header mengikuti pengaturan periode aktif / rentang banyak bulan */
if ($jumlah_periode > 1 && $periode_mulai !== '' && $periode_akhir !== '') {
    $label_bulan_insentif = getPeriodRangeLabel($periode_mulai, $periode_akhir);
} else {
    $label_bulan_insentif = getPeriodLabel($periode_aktif);
}

/** Tanggal di baris tempat+tanggal = tanggal sistem saat cetak dibuka (bukan pengaturan) */
$tanggal_cetak_terformat = formatTanggalTanpaHari(date('Y-m-d'));

/** Style inline pada sel baris data — sama di mana pun (Chrome/PDF/hosting tidak boleh beda dari stylesheet saja). */
$legger_td_data_style = 'height:11mm;min-height:11mm;padding:2.75pt 2pt;vertical-align:middle;box-sizing:border-box;line-height:1.25;font-size:11pt;';
$legger_th_style = 'padding:4.5pt 2pt;vertical-align:middle;box-sizing:border-box;line-height:1.2;font-size:11pt;';
$legger_td_total_style = 'padding:4pt 2pt;vertical-align:middle;box-sizing:border-box;font-size:11pt;';

$logo_file = __DIR__ . '/../../assets/img/' . ($settings['logo'] ?? '');
$logo_exists = !empty($settings['logo']) && file_exists($logo_file);
$logo_base64 = '';
$logo_path = '';
if ($logo_exists) {
    $logo_path = BASE_URL . 'assets/img/' . $settings['logo'];
    $image_data = file_get_contents($logo_file);
    $logo_base64 = 'data:' . mime_content_type($logo_file) . ';base64,' . base64_encode($image_data);
}

$conn->query("CREATE TABLE IF NOT EXISTS insentif (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_insentif VARCHAR(100) NOT NULL,
    jumlah_insentif DECIMAL(15,2) NOT NULL DEFAULT 0,
    aktif TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS insentif_detail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guru_id INT NOT NULL,
    insentif_id INT NOT NULL,
    jumlah DECIMAL(15,2) NOT NULL DEFAULT 0,
    periode VARCHAR(7) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_guru_id (guru_id),
    INDEX idx_insentif_id (insentif_id),
    CONSTRAINT fk_insentif_detail_guru FOREIGN KEY (guru_id) REFERENCES guru(id) ON DELETE CASCADE,
    CONSTRAINT fk_insentif_detail_insentif FOREIGN KEY (insentif_id) REFERENCES insentif(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$sql = "SELECT i.*
        FROM insentif i
        WHERE i.aktif = 1
        ORDER BY i.nama_insentif ASC";
$insentif_list = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

$sql = "SELECT DISTINCT g.id AS guru_id, g.nama_lengkap
        FROM insentif_detail idt
        JOIN guru g ON idt.guru_id = g.id
        JOIN insentif i ON idt.insentif_id = i.id
        WHERE i.aktif = 1
        ORDER BY LOWER(TRIM(g.nama_lengkap)) ASC, g.nama_lengkap ASC";
$guru_list = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

$sql = "SELECT guru_id, insentif_id, SUM(jumlah) AS jumlah
        FROM insentif_detail idt
        JOIN insentif i ON idt.insentif_id = i.id
        WHERE i.aktif = 1
        GROUP BY guru_id, insentif_id";
$detail_rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

$detail_map = [];
foreach ($detail_rows as $row) {
    $gid = intval($row['guru_id']);
    $iid = intval($row['insentif_id']);
    if (!isset($detail_map[$gid])) {
        $detail_map[$gid] = [];
    }
    $detail_map[$gid][$iid] = floatval($row['jumlah'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Legger Insentif</title>
    <style>
        @page {
            size: 330mm 210mm;
            margin: 0mm 10mm 10mm 5mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            margin: 0;
            padding: 0;
        }

        .content-wrapper {
            width: 100%;
            max-width: 100%;
            padding: 0mm 5mm 5mm 3mm;
            background: white;
            display: flex;
            flex-direction: column;
        }

        .header {
            text-align: center;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 2px solid #000;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .header-logo {
            max-width: 60px;
            max-height: 60px;
            object-fit: contain;
        }

        .header-content h2 {
            margin: 0;
            font-size: 18px;
        }

        .header-content p {
            margin: 2px 0;
            font-size: 12px;
        }

        table.legger-insentif {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            table-layout: fixed;
        }

        table.legger-insentif,
        table.legger-insentif th,
        table.legger-insentif td {
            border: 1px solid #000;
        }

        table.legger-insentif th,
        table.legger-insentif td {
            padding: 3px;
            box-sizing: border-box;
        }

        /*
         * Tinggi baris: pakai height + pt/mm — banyak engine cetak (Chrome PDF, dll.)
         * mengabaikan min-height/em pada <td>; min-height/em sering terlihat di local preview
         * tapi sama di hosting.
         */
        table.legger-insentif tbody tr:not(.total) td {
            height: 11mm;
            padding: 2.75pt 2pt;
            vertical-align: middle;
            line-height: 1.25;
        }

        table.legger-insentif thead th {
            padding: 4.5pt 2pt;
            background: #f0f0f0;
            text-align: center;
            font-size: 11pt;
            white-space: normal;
            word-break: break-word;
        }

        table.legger-insentif td {
            font-size: 11pt;
        }

        table.legger-insentif td.num {
            text-align: right;
            white-space: nowrap;
        }

        table.legger-insentif td.name {
            white-space: normal;
            word-break: break-word;
        }

        table.legger-insentif td.signature {
            min-width: 80px;
        }

        .total {
            font-weight: bold;
            background: #fafafa;
        }

        table.legger-insentif tbody tr.total td {
            padding: 4pt 2pt;
            vertical-align: middle;
        }

        .tempat-tanggal {
            text-align: right;
            margin-top: 15px;
            margin-bottom: 5px;
            font-size: 12pt;
        }

        .footer {
            margin-top: 20px;
            display: table;
            width: 100%;
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

        /* Tanggal + blok tanda tangan selalu satu kesatuan di satu halaman */
        .signature-section {
            page-break-inside: avoid;
            break-inside: avoid;
        }

        @media print {
            @page {
                size: 330mm 210mm;
                margin: 0mm 10mm 10mm 5mm;
            }

            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .content-wrapper {
                padding: 2mm 0mm;
                width: 100%;
                max-width: 100%;
            }

            thead { display: table-header-group; }
            tfoot { display: table-footer-group; }
            tr { page-break-inside: avoid; }

            /* Ulangi eksplisit di print agar host/browser tidak menyederhanakan rule layar */
            table.legger-insentif tbody tr:not(.total) td {
                height: 11mm !important;
                min-height: 11mm !important;
                padding: 2.75pt 2pt !important;
                vertical-align: middle !important;
            }

            .signature-section {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
        }
    </style>
</head>
<body>
    <div class="content-wrapper">
    <div class="header">
        <?php if ($logo_exists): ?>
            <img src="<?php echo $logo_base64; ?>" alt="Logo" class="header-logo" onerror="this.src='<?php echo $logo_path; ?>'">
        <?php endif; ?>
        <div class="header-content">
            <h2><?php echo strtoupper(htmlspecialchars($settings['nama_madrasah'] ?? 'MADRASAH')); ?></h2>
            <p>LEGGER INSENTIF</p>
            <p>Bulan <?php echo htmlspecialchars($label_bulan_insentif); ?></p>
            <?php if (!empty($settings['tahun_ajaran'])): ?>
                <p>Tahun Ajaran <?php echo htmlspecialchars($settings['tahun_ajaran']); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <table class="legger-insentif">
        <thead>
            <tr>
                <th style="width:30px;<?php echo htmlspecialchars($legger_th_style, ENT_COMPAT, 'UTF-8'); ?>">No</th>
                <th style="width:220px;<?php echo htmlspecialchars($legger_th_style, ENT_COMPAT, 'UTF-8'); ?>">Nama Guru</th>
                <?php foreach ($insentif_list as $i): ?>
                    <th style="width:95px;<?php echo htmlspecialchars($legger_th_style, ENT_COMPAT, 'UTF-8'); ?>"><?php echo htmlspecialchars($i['nama_insentif']); ?></th>
                <?php endforeach; ?>
                <th style="width:120px;<?php echo htmlspecialchars($legger_th_style, ENT_COMPAT, 'UTF-8'); ?>">Total</th>
                <th style="width:100px;<?php echo htmlspecialchars($legger_th_style, ENT_COMPAT, 'UTF-8'); ?>">Tanda Tangan</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $no = 1;
            $grand_total = 0;
            foreach ($guru_list as $g):
                $gid = intval($g['guru_id']);
                $row_total = 0;
            ?>
            <tr>
                <td class="ledger-cell" style="text-align:center;<?php echo htmlspecialchars($legger_td_data_style, ENT_COMPAT, 'UTF-8'); ?>"><?php echo $no++; ?></td>
                <td class="name ledger-cell" style="<?php echo htmlspecialchars($legger_td_data_style, ENT_COMPAT, 'UTF-8'); ?>"><?php echo htmlspecialchars($g['nama_lengkap']); ?></td>
                <?php foreach ($insentif_list as $i): ?>
                    <?php
                    $iid = intval($i['id']);
                    $val = floatval($detail_map[$gid][$iid] ?? 0);
                    $row_total += $val;
                    ?>
                    <td class="num ledger-cell" style="<?php echo htmlspecialchars($legger_td_data_style, ENT_COMPAT, 'UTF-8'); ?>"><?php echo $val > 0 ? formatRupiahTanpaRp($val) : '-'; ?></td>
                <?php endforeach; ?>
                <td class="num total ledger-cell" style="<?php echo htmlspecialchars($legger_td_data_style, ENT_COMPAT, 'UTF-8'); ?>"><?php echo formatRupiahTanpaRp($row_total); ?></td>
                <td class="signature ledger-cell" style="<?php echo htmlspecialchars($legger_td_data_style, ENT_COMPAT, 'UTF-8'); ?>"></td>
            </tr>
            <?php
                $grand_total += $row_total;
            endforeach;
            ?>
            <tr class="total">
                <td colspan="<?php echo 2 + count($insentif_list); ?>" style="text-align:center;<?php echo htmlspecialchars($legger_td_total_style, ENT_COMPAT, 'UTF-8'); ?>">TOTAL</td>
                <td class="num" style="<?php echo htmlspecialchars($legger_td_total_style, ENT_COMPAT, 'UTF-8'); ?>"><?php echo formatRupiahTanpaRp($grand_total); ?></td>
                <td style="<?php echo htmlspecialchars($legger_td_total_style, ENT_COMPAT, 'UTF-8'); ?>"></td>
            </tr>
        </tbody>
    </table>

    <div class="signature-section">
    <?php if (!empty($settings['tempat']) || $tanggal_cetak_terformat !== ''): ?>
    <div class="tempat-tanggal">
        <?php if (!empty($settings['tempat'])): ?>
            <?php echo htmlspecialchars($settings['tempat']); ?><?php if ($tanggal_cetak_terformat !== ''): ?>, <?php endif; ?>
        <?php endif; ?>
        <?php if ($tanggal_cetak_terformat !== ''): ?>
            <?php echo htmlspecialchars($tanggal_cetak_terformat); ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php
    $qr_kepala_data = 'Kepala Madrasah|' . ($settings['nama_kepala'] ?? '') . '|Legger Insentif';
    $qr_bendahara_data = 'Bendahara|' . ($settings['nama_bendahara'] ?? '') . '|Legger Insentif';
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
            setTimeout(function() {
                window.print();
            }, 200);
        };
    </script>
    </div>
</body>
</html>
