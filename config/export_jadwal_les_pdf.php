<?php
session_name('SIS_ADMIN');
session_start();

require_once 'database.php';
require_once 'functions.php';

// Check auth
if (!isAuthorized(['admin'])) {
    die("Unauthorized access");
}

// Get Jadwal Les Data
$stmt = $pdo->query("
    SELECT j.*, g.nama_guru 
    FROM tb_jadwal_les j 
    JOIN tb_guru g ON j.id_guru = g.id_guru 
    ORDER BY j.tanggal ASC, j.waktu_mulai ASC
");
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get School Profile
$school_profile = getSchoolProfile($pdo);
$kepala_madrasah = $school_profile['kepala_madrasah'] ?? '-';

// Get Wali Kelas 6
$stmt_wali = $pdo->prepare("SELECT wali_kelas FROM tb_kelas WHERE id_kelas = 6");
$stmt_wali->execute();
$wali_kelas = $stmt_wali->fetchColumn() ?: '-';

// Digital Signature Logic
$qr_kepala_content = 'Validasi Tanda Tangan Digital Kepala Madrasah: ' . $kepala_madrasah . ' - ' . ($school_profile['nama_madrasah'] ?? 'Madrasah');
$qr_kepala_url = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qr_kepala_content);
$qr_kepala_img = '<img src="' . $qr_kepala_url . '" alt="QR Signature Kepala" style="width: 70px; height: 70px; margin: 5px auto; display: block;">';

$qr_wali_content = 'Validasi Tanda Tangan Digital Wali Kelas 6: ' . $wali_kelas . ' - ' . ($school_profile['nama_madrasah'] ?? 'Madrasah');
$qr_wali_url = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qr_wali_content);
$qr_wali_img = '<img src="' . $qr_wali_url . '" alt="QR Signature Wali" style="width: 70px; height: 70px; margin: 5px auto; display: block;">';

$logo_file = $school_profile['logo'] ?? '';
$logo_path = '';
if ($logo_file && file_exists(__DIR__ . '/../assets/img/' . $logo_file)) {
    $logo_path = '../assets/img/' . $logo_file;
} elseif (file_exists(__DIR__ . '/../assets/img/logo.png')) {
    $logo_path = '../assets/img/logo.png';
}

$page_title = "Jadwal Les Kelas 6";

?>
<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($page_title) ?></title>
    <style>
        @page { size: A4; margin: 15mm; }
        @media print {
            .no-print { display: none; }
        }
        body { font-family: Arial, sans-serif; font-size: 11pt; line-height: 1.4; color: #333; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 3px double black; padding-bottom: 10px; position: relative; min-height: 90px; }
        .header img { width: 80px; position: absolute; left: 10px; top: 0; }
        .header h3, .header h2, .header p { margin: 2px 0; }
        .title { text-align: center; margin-bottom: 20px; }
        .title h4 { margin: 0; text-decoration: underline; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid black; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; text-align: center; font-weight: bold; }
        .text-center { text-align: center; }
        .signature-container { margin-top: 40px; width: 100%; }
        .signature-table { width: 100%; border: none; }
        .signature-table td { border: none; text-align: center; width: 50%; vertical-align: top; padding-top: 20px; }
        .footer-date { text-align: right; margin-bottom: 10px; margin-right: 50px; }
    </style>
</head>
<body>
    <button class="no-print" onclick="window.print()" style="position: fixed; top: 10px; right: 10px; padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; z-index: 1000;">Cetak PDF</button>

    <div class="header">
        <?php if ($logo_path): ?>
            <img src="<?= $logo_path ?>" alt="Logo">
        <?php endif; ?>
        <h3><?= strtoupper($school_profile['nama_yayasan'] ?? 'YAYASAN') ?></h3>
        <h2><?= strtoupper($school_profile['nama_sekolah'] ?? $school_profile['nama_madrasah'] ?? 'NAMA SEKOLAH') ?></h2>
        <p><?= $school_profile['alamat'] ?? '' ?></p>
        <p>Tahun Ajaran: <?= $school_profile['tahun_ajaran'] ?? '-' ?> | Semester: <?= $school_profile['semester'] ?? '-' ?></p>
    </div>

    <div class="title">
        <h4>JADWAL LES KELAS 6</h4>
    </div>

    <table>
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="15%">Hari</th>
                <th width="20%">Tanggal</th>
                <th width="35%">Nama Guru</th>
                <th width="25%">Waktu</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($schedules)): ?>
                <tr>
                    <td colspan="5" class="text-center">Tidak ada data jadwal les.</td>
                </tr>
            <?php else: ?>
                <?php $no = 1; foreach ($schedules as $s): ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td class="text-center"><?= htmlspecialchars($s['hari']) ?></td>
                    <td class="text-center"><?= date('d-m-Y', strtotime($s['tanggal'])) ?></td>
                    <td><?= htmlspecialchars($s['nama_guru']) ?></td>
                    <td class="text-center"><?= date('H.i', strtotime($s['waktu_mulai'])) . ' - ' . date('H.i', strtotime($s['waktu_selesai'])) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="signature-container">
        <div class="footer-date">
            <?= formatDateIndonesia(date('Y-m-d')) ?>
        </div>
        <table class="signature-table">
            <tr>
                <td>
                    Mengetahui,<br>
                    Kepala Madrasah<br>
                    <?= $qr_kepala_img ?>
                    <strong><u><?= $kepala_madrasah ?></u></strong><br>
                    NIP. <?= $school_profile['nip_kepala'] ?? '-' ?>
                </td>
                <td>
                    <br>
                    Wali Kelas 6<br>
                    <?= $qr_wali_img ?>
                    <strong><u><?= $wali_kelas ?></u></strong><br>
                    NIP. -
                </td>
            </tr>
        </table>
    </div>

    <script>
        window.onload = function() {
            // window.print();
        }
    </script>
</body>
</html>
