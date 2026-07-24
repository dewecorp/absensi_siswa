<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['admin'])) redirect('../login.php');

$school_profile = getSchoolProfile($pdo);
$tahun = $_GET['tahun'] ?? date('Y');

// Query data tahun terpilih
$stmt = $pdo->prepare("SELECT * FROM tb_prestasi_siswa WHERE tahun = ? ORDER BY nama_siswa ASC");
$stmt->execute([$tahun]);
$prestasi_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary semua tahun untuk ringkasan
$stmt_all = $pdo->query("SELECT tahun, tingkat, COUNT(*) as jml FROM tb_prestasi_siswa GROUP BY tahun, tingkat ORDER BY tahun DESC");
$all_summary = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
$summary_by_year = [];
foreach ($all_summary as $s) {
    $th = $s['tahun'];
    if (!isset($summary_by_year[$th])) $summary_by_year[$th] = ['Kecamatan' => 0, 'Kabupaten' => 0, 'Provinsi' => 0, 'Nasional' => 0];
    $summary_by_year[$th][$s['tingkat']] = (int)$s['jml'];
}

$report_title = 'DATA PRESTASI SISWA';

if (empty($prestasi_list)) {
    echo 'Tidak ada data untuk tahun ' . htmlspecialchars($tahun) . '.';
    exit;
}

// Build table HTML
$no = 1;
ob_start();
?>
<table border="1">
    <thead>
        <tr><th colspan="5" style="text-align:center;font-size:16px;font-weight:bold;background:#368DBC;color:#fff;padding:10px;">DATA PRESTASI SISWA</th></tr>
        <tr><th style="background:#368DBC;color:#fff;padding:8px;font-size:13px;">No</th><th style="background:#368DBC;color:#fff;padding:8px;font-size:13px;">Nama</th><th style="background:#368DBC;color:#fff;padding:8px;font-size:13px;">Prestasi</th><th style="background:#368DBC;color:#fff;padding:8px;font-size:13px;">Tahun</th><th style="background:#368DBC;color:#fff;padding:8px;font-size:13px;">Tingkat</th></tr>
    </thead>
    <tbody>
<?php foreach ($prestasi_list as $p): ?>
        <tr><td><?= $no++ ?></td><td><?= htmlspecialchars($p['nama_siswa']) ?></td><td><?= htmlspecialchars($p['prestasi']) ?></td><td><?= $p['tahun'] ?></td><td><?= $p['tingkat'] ?></td></tr>
<?php endforeach; ?>
    </tbody>
</table>
<?php
$table_data = ob_get_clean();

$logo_path = '../assets/img/' . ($school_profile['logo'] ?? 'logo.png');
$yayasan = strtoupper($school_profile['nama_yayasan'] ?? 'YAYASAN');
$madrasah = strtoupper($school_profile['nama_madrasah'] ?? 'MADRASAH');
$alamat = $school_profile['alamat'] ?? '';
$kepala_madrasah = $school_profile['kepala_madrasah'] ?? '';
$nip_kepala = $school_profile['nip_kepala'] ?? '-';
$tgl_cetak = formatDateIndonesia(date('Y-m-d'));
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= $report_title ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        @page { size: landscape; margin: 10mm; }
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { display: flex; align-items: center; justify-content: center; margin-bottom: 20px; }
        .header .logo { width: 80px; height: 80px; object-fit: contain; margin-right: 15px; }
        .header .info { text-align: center; }
        .header .info h3 { margin: 0; color: #333; font-size: 14px; }
        .header .info h2 { margin: 2px 0; color: #333; font-size: 18px; }
        .header .info p { margin: 2px 0; color: #666; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #000; padding: 8px; text-align: left; font-size: 13px; }
        th { background-color: #368DBC !important; color: white !important; font-weight: bold; text-align: center; }
        tr:nth-child(even) { background-color: #f2f2f2; }
        .footer { margin-top: 20px; text-align: center; font-size: 11px; color: #666; }
        .print-btn { position: fixed; top: 20px; right: 20px; padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; z-index: 9999; font-size: 14px; }
        .print-btn:hover { background: #0056b3; }
        .no-print { display: block !important; }
        @media print { .no-print { display: none !important; } body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
    <button class="print-btn no-print" onclick="window.print()"><i class="fas fa-print"></i> Cetak / Simpan PDF</button>

    <div class="header">
        <img class="logo" src="<?= $logo_path ?>" alt="Logo">
        <div class="info">
            <h3><?= $yayasan ?></h3>
            <h2><?= $madrasah ?></h2>
            <p><?= htmlspecialchars($alamat) ?></p>
            <p style="font-size: 12px; font-weight: bold; text-transform: uppercase;">DATA PRESTASI SISWA</p>
        </div>
    </div>

    <?= $table_data ?>

    <?php if (!empty($summary_by_year)): ?>
    <div style="margin-top: 8px; font-size: 12px;">
        <?php $i = 1; foreach ($summary_by_year as $th => $s): ?>
        <?= $i++ ?>. <?= htmlspecialchars($th) ?>: Kecamatan: <?= $s['Kecamatan'] ?>, Kabupaten: <?= $s['Kabupaten'] ?>, Provinsi: <?= $s['Provinsi'] ?>, Nasional: <?= $s['Nasional'] ?><br>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div style="margin-top: 30px; width: 250px; margin-left: auto; text-align: center; page-break-inside: avoid;">
        <p><?= $tgl_cetak ?></p>
        <p>Kepala Madrasah,</p>
        <?php if ($kepala_madrasah): ?>
            <?php
            $qr_content = 'Validasi Tanda Tangan Digital: ' . $kepala_madrasah . ' - ' . ($school_profile['nama_madrasah'] ?? 'Madrasah');
            $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qr_content);
            ?>
            <img src="<?= $qr_url ?>" alt="QR" style="width: 80px; height: 80px; margin: 10px 0; display: inline-block;">
            <p><strong><?= htmlspecialchars($kepala_madrasah) ?></strong></p>
            <p style="font-size: 11px;">NIP. <?= htmlspecialchars($nip_kepala) ?></p>
        <?php else: ?>
            <br><br><br>
            <p><strong>.........................</strong></p>
        <?php endif; ?>
    </div>

    <div class="footer">Laporan <?= $report_title ?> - Sistem Informasi Madrasah</div>

    
</body>
</html>
