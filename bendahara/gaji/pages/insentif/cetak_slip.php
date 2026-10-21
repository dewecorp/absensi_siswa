<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

$guru_id = intval($_GET['guru_id'] ?? 0);
if ($guru_id <= 0) {
    echo 'Guru tidak valid';
    exit();
}

$sql = "SELECT * FROM settings WHERE id = 1 LIMIT 1";
$qr_settings = $conn->query($sql);
$settings = ($qr_settings && $qr_settings->num_rows > 0) ? $qr_settings->fetch_assoc() : [];

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

$stmt = $conn->prepare("SELECT * FROM guru WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $guru_id);
$stmt->execute();
$guru = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$guru) {
    echo 'Guru tidak ditemukan';
    exit();
}

$sql = "SELECT i.nama_insentif, SUM(idt.jumlah) AS jumlah
        FROM insentif_detail idt
        JOIN insentif i ON idt.insentif_id = i.id
        WHERE idt.guru_id = ?
        AND i.aktif = 1
        GROUP BY i.id, i.nama_insentif
        HAVING SUM(idt.jumlah) > 0
        ORDER BY i.nama_insentif ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $guru_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total = 0;
foreach ($items as $it) {
    $total += floatval($it['jumlah'] ?? 0);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Slip - <?php echo htmlspecialchars($guru['nama_lengkap'] ?? ''); ?></title>
    <style>
        @page {
            size: F4;
            margin: 10mm 5mm 5mm 5mm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            overflow: visible;
        }

        .page {
            width: 200mm;
            max-width: 200mm;
            min-height: 150mm;
            display: flex;
            flex-direction: row;
            gap: 3mm;
            padding: 2mm;
            margin: 0;
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
        }

        .header {
            display: flex;
            align-items: center;
            margin-bottom: 1mm;
            border-bottom: 1px solid #000;
            padding-bottom: 2mm;
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
        }

        .header-content p {
            margin: 1px 0;
            font-size: 11px;
        }

        .title {
            text-align: center;
            font-weight: bold;
            margin: 2mm 0;
            font-size: 14px;
        }

        .info {
            margin: 1mm 0 2mm 0;
            font-size: 12px;
        }

        .info p {
            margin: 1mm 0;
        }

        .table-wrapper {
            border: 1px solid #000;
            border-left: 0;
            border-right: 0;
            margin-top: 2mm;
            margin-bottom: 2mm;
            flex: 1;
        }

        .table-row {
            display: flex;
            border-bottom: 1px solid #000;
        }

        .table-row:last-child {
            border-bottom: 0;
        }

        .table-header, .table-cell {
            padding: 1.5mm;
            font-size: 11px;
        }

        .table-header {
            font-weight: bold;
            background: #f0f0f0;
            text-align: center;
        }

        .table-cell:last-child, .table-header:last-child {
            text-align: right;
            white-space: nowrap;
        }

        .tempat-tanggal {
            text-align: right;
            font-size: 11px;
            margin-top: 2mm;
            margin-bottom: 2mm;
        }

        .signature-row {
            display: flex;
            justify-content: space-between;
            gap: 10mm;
            margin-top: 2mm;
        }

        .signature-col {
            flex: 1;
            text-align: center;
        }

        .signature-line {
            height: 32mm;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end;
            gap: 4px;
        }

        .qr-signature {
            width: 60px;
            height: 60px;
            object-fit: contain;
        }

        .signature-name {
            margin-top: 2px;
            font-weight: bold;
            font-size: 11px;
        }

        @media print {
            .page { page-break-inside: avoid; break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="slip">
            <div class="header">
                <?php if ($logo_exists): ?>
                    <img src="<?php echo $logo_base64; ?>" alt="Logo" class="header-logo" onerror="this.src='<?php echo $logo_path; ?>'">
                <?php endif; ?>
                <div class="header-content">
                    <h3><?php echo strtoupper(htmlspecialchars($settings['nama_madrasah'] ?? 'MADRASAH')); ?></h3>
                    <?php if (!empty($settings['tahun_ajaran'])): ?>
                        <p>Tahun Ajaran <?php echo htmlspecialchars($settings['tahun_ajaran']); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="title">SLIP INSENTIF</div>

            <div class="info">
                <p><strong>Nama:</strong> <?php echo htmlspecialchars($guru['nama_lengkap'] ?? ''); ?></p>
            </div>

            <div class="table-wrapper">
                <div class="table-row">
                    <div class="table-header" style="flex: 2;">Keterangan</div>
                    <div class="table-header" style="flex: 1;">Jumlah</div>
                </div>

                <?php foreach ($items as $it): ?>
                    <div class="table-row">
                        <div class="table-cell" style="flex: 2;"><?php echo htmlspecialchars($it['nama_insentif'] ?? ''); ?></div>
                        <div class="table-cell" style="flex: 1;"><?php echo formatRupiah($it['jumlah'] ?? 0); ?></div>
                    </div>
                <?php endforeach; ?>

                <div class="table-row">
                    <div class="table-cell" style="flex: 2; font-weight: bold;">Total</div>
                    <div class="table-cell" style="flex: 1; font-weight: bold;"><?php echo formatRupiah($total); ?></div>
                </div>
            </div>

            <?php $tanggal_cetak_insentif = formatTanggalTanpaHari(date('Y-m-d')); ?>
            <?php if (!empty($settings['tempat']) || $tanggal_cetak_insentif !== ''): ?>
            <div class="tempat-tanggal">
                <?php if (!empty($settings['tempat'])): ?>
                    <?php echo htmlspecialchars($settings['tempat']); ?><?php if ($tanggal_cetak_insentif !== ''): ?>, <?php endif; ?>
                <?php endif; ?>
                <?php if ($tanggal_cetak_insentif !== ''): ?>
                    <?php echo htmlspecialchars($tanggal_cetak_insentif); ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="signature-row">
                <div class="signature-col">
                    <p><strong>Kepala Madrasah</strong></p>
                    <div class="signature-line">
                        <img class="qr-signature" src="<?php echo BASE_URL; ?>qrcode?data=<?php echo rawurlencode('Kepala Madrasah|' . ($settings['nama_kepala'] ?? '') . '|Slip'); ?>" alt="QR Kepala Madrasah">
                        <div class="signature-name"><?php echo htmlspecialchars($settings['nama_kepala'] ?? ''); ?></div>
                    </div>
                </div>
                <div class="signature-col">
                    <p><strong>Bendahara</strong></p>
                    <div class="signature-line">
                        <img class="qr-signature" src="<?php echo BASE_URL; ?>qrcode?data=<?php echo rawurlencode('Bendahara|' . ($settings['nama_bendahara'] ?? '') . '|Slip'); ?>" alt="QR Bendahara">
                        <div class="signature-name"><?php echo htmlspecialchars($settings['nama_bendahara'] ?? ''); ?></div>
                    </div>
                </div>
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
</body>
</html>
