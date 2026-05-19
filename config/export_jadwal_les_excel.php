<?php
require_once 'database.php';
require_once 'functions.php';

// Check auth
if (!isAuthorized(['admin', 'tata_usaha', 'guru', 'kepala_madrasah', 'wali', 'siswa'])) {
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

$filename = "Jadwal_Les_Kelas_6_" . date('Ymd_His') . ".xls";

// Headers for Excel download
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

?>
<!DOCTYPE html>
<html>
<head>
    <style>
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid black; padding: 5px; }
        th { background-color: #f0f0f0; text-align: center; font-weight: bold; }
        .text-center { text-align: center; }
        .header-text { text-align: center; font-weight: bold; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td colspan="5" class="header-text"><?= strtoupper($school_profile['nama_yayasan'] ?? 'YAYASAN') ?></td>
        </tr>
        <tr>
            <td colspan="5" class="header-text"><?= strtoupper($school_profile['nama_sekolah'] ?? $school_profile['nama_madrasah'] ?? 'NAMA SEKOLAH') ?></td>
        </tr>
        <tr>
            <td colspan="5" class="text-center">Tahun Ajaran: <?= $school_profile['tahun_ajaran'] ?? '-' ?> | Semester: <?= $school_profile['semester'] ?? '-' ?></td>
        </tr>
        <tr><td colspan="5"></td></tr>
        <tr>
            <td colspan="5" class="header-text" style="font-size: 14pt; text-decoration: underline;">JADWAL LES KELAS 6</td>
        </tr>
        <tr><td colspan="5"></td></tr>
        <thead>
            <tr>
                <th style="background-color: #cccccc;">No</th>
                <th style="background-color: #cccccc;">Hari</th>
                <th style="background-color: #cccccc;">Tanggal</th>
                <th style="background-color: #cccccc;">Nama Guru</th>
                <th style="background-color: #cccccc;">Waktu</th>
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
        <tr><td colspan="5"></td></tr>
        <tr>
            <td colspan="3"></td>
            <td colspan="2" class="text-center"><?= formatDateIndonesia(date('Y-m-d')) ?></td>
        </tr>
        <tr>
            <td colspan="2" class="text-center">Mengetahui,<br>Kepala Madrasah</td>
            <td></td>
            <td colspan="2" class="text-center"><br>Wali Kelas 6</td>
        </tr>
        <tr><td colspan="5" style="height: 50px;"></td></tr>
        <tr>
            <td colspan="2" class="text-center"><strong><u><?= $kepala_madrasah ?></u></strong></td>
            <td></td>
            <td colspan="2" class="text-center"><strong><u><?= $wali_kelas ?></u></strong></td>
        </tr>
        <tr>
            <td colspan="2" class="text-center">NIP. <?= $school_profile['nip_kepala'] ?? '-' ?></td>
            <td></td>
            <td colspan="2" class="text-center">NIP. -</td>
        </tr>
    </table>
</body>
</html>
