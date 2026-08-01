<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha'])) {
    http_response_code(403);
    exit('Unauthorized');
}

// Baca tingkat dari query string
$selected_tingkat_id = (int)($_GET['tingkat'] ?? 0);

// School profile
$school_profile = getSchoolProfile($pdo);
$schoolName = $school_profile['nama_madrasah'] ?? 'MADRASAH';
$schoolLogo = !empty($school_profile['logo']) ? ('../assets/img/' . $school_profile['logo']) : '';
$academicYear = $school_profile['tahun_ajaran'] ?? '-';
$placeFallback = $school_profile['tempat_jadwal'] ?? 'Padang';

// Tingkat name
$tingkatName = '';
if ($selected_tingkat_id > 0) {
    $stTk = $pdo->prepare("SELECT nama_tingkat FROM tb_tingkat_barung WHERE id_tingkat_barung = ?");
    $stTk->execute([$selected_tingkat_id]);
    $tkRow = $stTk->fetch(PDO::FETCH_ASSOC);
    $tingkatName = (string)($tkRow['nama_tingkat'] ?? '');
}

// Print signature settings (Ketua Gudep)
$ketuaGudep = $school_profile['nama_kepala'] ?? '-';
$ntaKetuaGudep = $school_profile['nip_kepala'] ?? '-';
$printPlace = $placeFallback;
try {
    $settings = $pdo->query("SELECT ketua_gudep, nta_ketua_gudep, tempat_surat FROM tb_pengaturan_cetak_barung LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($settings) {
        $ketuaGudep = $settings['ketua_gudep'] ?? $ketuaGudep;
        $ntaKetuaGudep = $settings['nta_ketua_gudep'] ?? $ntaKetuaGudep;
        $printPlace = $settings['tempat_surat'] ?? $printPlace;
    }
} catch (Exception $e) {
    // ignore
}
// Tanggal cetak = hari ini (tanggal saat mencetak), bukan tanggal surat
$printDate = date('d-m-Y');

// Fetch peserta rows (sama seperti data_barung.php)
$peserta_rows = [];
if ($selected_tingkat_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                p.nama_peserta_didik,
                p.nta,
                COALESCE(NULLIF(TRIM(k.nama_kelas), ''), '-') AS nama_kelas,
                COALESCE(NULLIF(TRIM(s.tempat_lahir), ''), NULLIF(TRIM(p.tempat_lahir), '')) AS tempat_lahir,
                COALESCE(s.tanggal_lahir, p.tanggal_lahir) AS tanggal_lahir
            FROM tb_peserta_didik_barung p
            LEFT JOIN tb_siswa s ON (
                s.id_siswa = p.id_siswa
                OR (
                    p.id_siswa IS NULL
                    AND TRIM(IFNULL(p.nta, '')) <> ''
                    AND CONVERT(TRIM(IFNULL(s.nisn, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                        = CONVERT(TRIM(IFNULL(p.nta, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                )
            )
            LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas
            WHERE p.id_tingkat_barung = ?
              AND IFNULL(p.status, 'aktif') = 'aktif'
              AND (s.id_kelas IS NOT NULL OR p.id_siswa IS NULL)
            ORDER BY p.nama_peserta_didik ASC
        ");
        $stmt->execute([$selected_tingkat_id]);
        $peserta_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $peserta_rows = [];
    }
}

function cetakBarungUsia(?string $tanggal_lahir): string
{
    $raw = trim((string)$tanggal_lahir);
    if ($raw === '' || $raw === '0000-00-00') {
        return '-';
    }
    try {
        $lahir = new DateTime(substr($raw, 0, 10));
        $hariIni = new DateTime('today');
        if ($lahir > $hariIni) {
            return '-';
        }
        $diff = $lahir->diff($hariIni);
        $umurBulan = ($diff->y * 12) + $diff->m;
        return intdiv($umurBulan, 12) . ' tahun ' . ($umurBulan % 12) . ' bulan';
    } catch (Exception $e) {
        return '-';
    }
}

$qrContent = "Dokumen Sah: " . $schoolName . "\nKetua Gudep: " . $ketuaGudep . "\nNTA: " . $ntaKetuaGudep;
$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=" . urlencode($qrContent);
$title = 'Data Anggota Pramuka-' . $academicYear;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<style>
@page { size: 210mm 330mm landscape; margin: 10mm; }
body { font-family: Arial, sans-serif; font-size: 11pt; }
table { border-collapse: collapse; width: 100%; margin-bottom: 20px; font-size: 11pt; table-layout: fixed; }
th, td { border: 1px solid #000; padding: 7px 6px; text-align: left; font-size: 11pt; line-height: 1.35; overflow-wrap: break-word; }
th { background-color: #f2f2f2; }
th:first-child, td:first-child { width: 30px; text-align: center; }
th:nth-child(2), td:nth-child(2) { width: 32%; }
th:nth-child(3), td:nth-child(3) { width: 60px; text-align: center; }
th:nth-child(4), td:nth-child(4) { width: 12%; }
th:nth-child(5), td:nth-child(5) { width: 12%; }
th:nth-child(6), td:nth-child(6) { width: 11%; white-space: nowrap; }
th:nth-child(7), td:nth-child(7) { width: 12%; white-space: nowrap; }
h2, h3 { text-align: center; margin: 2px 0; }
.header-container { display: flex; align-items: center; justify-content: center; margin-bottom: 20px; position: relative; }
.logo { position: absolute; left: 0; top: 0; height: 70px; }
.header-text { text-align: center; width: 100%; }
.signature-container { margin-top: 40px; float: right; text-align: left; width: 280px; page-break-inside: avoid; break-inside: avoid; }
.signature-header { text-align: left; margin-bottom: 5px; }
.signature-space { height: 90px; display: flex; align-items: flex-end; justify-content: flex-start; margin-bottom: 5px; }
.qr-code { height: 80px; width: 80px; margin-right: 10px; }
.signature-info { text-align: left; }
@media print { .no-print { display: none; } }
.print-btn { position: fixed; top: 20px; right: 20px; padding: 10px 20px; background: #007bff; color: #fff; border: none; border-radius: 5px; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.2); z-index: 9999; font-size: 14px; }
.print-btn:hover { background: #0056b3; }
</style>
</head>
<body>
<button type="button" class="print-btn no-print" onclick="window.print()"><i class="fas fa-print"></i> Cetak / Simpan PDF</button>
<div class="header-container">
    <?php if ($schoolLogo !== ''): ?>
    <img src="<?php echo htmlspecialchars($schoolLogo, ENT_QUOTES, 'UTF-8'); ?>" class="logo">
    <?php endif; ?>
    <div class="header-text">
        <h2><?php echo htmlspecialchars(strtoupper($schoolName), ENT_QUOTES, 'UTF-8'); ?></h2>
        <h3>DATA ANGGOTA PRAMUKA</h3>
        <h3>TINGKAT: <?php echo htmlspecialchars(strtoupper($tingkatName), ENT_QUOTES, 'UTF-8'); ?></h3>
        <h3>TAHUN AJARAN: <?php echo htmlspecialchars($academicYear, ENT_QUOTES, 'UTF-8'); ?></h3>
    </div>
</div>
<hr style="border: 1px solid #000; margin-bottom: 20px;">
<table>
    <thead>
        <tr>
            <th>No</th>
            <th>Nama Peserta Didik</th>
            <th>Kelas</th>
            <th>NTA</th>
            <th>Tempat Lahir</th>
            <th>Tanggal Lahir</th>
            <th>Usia</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($peserta_rows)): ?>
        <tr><td colspan="7" style="text-align:center;">Tidak ada data anggota untuk tingkat ini.</td></tr>
        <?php else: ?>
            <?php foreach ($peserta_rows as $idx => $row): ?>
            <tr>
                <td style="text-align:center;"><?php echo (int)($idx + 1); ?></td>
                <td><?php echo htmlspecialchars((string)($row['nama_peserta_didik'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td style="text-align:center;"><?php echo htmlspecialchars((string)($row['nama_kelas'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string)($row['nta'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string)($row['tempat_lahir'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo !empty($row['tanggal_lahir']) ? htmlspecialchars(date('d-m-Y', strtotime((string)$row['tanggal_lahir'])), ENT_QUOTES, 'UTF-8') : ''; ?></td>
                <td><?php echo htmlspecialchars(cetakBarungUsia($row['tanggal_lahir'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<div class="signature-container">
    <div class="signature-header">
        <p><?php echo htmlspecialchars($printPlace, ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars($printDate, ENT_QUOTES, 'UTF-8'); ?></p>
        <p>Ketua Gudep,</p>
    </div>
    <div class="signature-space">
        <img src="<?php echo htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8'); ?>" class="qr-code">
    </div>
    <div class="signature-info">
        <p><strong><?php echo htmlspecialchars($ketuaGudep, ENT_QUOTES, 'UTF-8'); ?></strong></p>
        <p>NTA. <?php echo htmlspecialchars($ntaKetuaGudep, ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
</div>
</body>
</html>
