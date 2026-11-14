<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$session_type = sanitizeInput((string)($_GET['session_type'] ?? ''));
$allowed = ['admin'];
if ($session_type === 'kepala_madrasah') $allowed[] = 'kepala_madrasah';
if (!isAuthorized($allowed)) redirect('../login.php');

$school_profile = getSchoolProfile($pdo);
$yayasan   = strtoupper($school_profile['nama_yayasan'] ?? '');
$madrasah  = strtoupper($school_profile['nama_madrasah'] ?? 'MADRASAH');
$alamat    = $school_profile['alamat'] ?? '';
$telp      = $school_profile['telepon'] ?? '';
$logo_file = trim($school_profile['logo'] ?? 'logo.png');
$logo_path = '../assets/img/' . ($logo_file ?: 'logo.png');

$kepala_madrasah  = $school_profile['kepala_madrasah'] ?? '';
$nip_kepala       = $school_profile['nip_kepala'] ?? '';
$tempat_jadwal    = $school_profile['tempat_jadwal'] ?? $school_profile['kota'] ?? '';
$tgl_cetak        = formatDateIndonesia(date('Y-m-d'));

// Pastikan tabel ada
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_komite_madrasah (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        nama VARCHAR(150) NOT NULL,
        jabatan VARCHAR(100) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$rows = $pdo->query("SELECT * FROM tb_komite_madrasah ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Pengurus Komite Madrasah – <?= htmlspecialchars($madrasah) ?></title>
<style>
/* ---- PAGE SETUP ---- */
@page { size: A4 portrait; margin: 15mm 15mm 15mm 20mm; }
@media print {
    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .no-print { display: none !important; }
    table { page-break-inside: auto; }
    tr { page-break-inside: avoid; }
}

/* ---- BASE ---- */
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, sans-serif; font-size: 12px; color: #000; background: #fff; padding: 20px; }

/* ---- TOMBOL CETAK (layar saja) ---- */
.btn-print {
    position: fixed; top: 18px; right: 18px; z-index: 9999;
    padding: 9px 20px; background: #2563eb; color: #fff;
    border: none; border-radius: 6px; cursor: pointer;
    font-size: 13px; font-weight: 600;
    box-shadow: 0 2px 8px rgba(37,99,235,.35);
    display: flex; align-items: center; gap: 7px;
}
.btn-print:hover { background: #1d4ed8; }

/* ---- KOP SURAT ---- */
.kop {
    display: flex; align-items: center;
    border-bottom: 3px solid #000;
    padding-bottom: 8px; margin-bottom: 14px;
}
.kop img { width: 72px; height: 72px; object-fit: contain; margin-right: 16px; flex-shrink: 0; }
.kop-text { flex: 1; text-align: center; }
.kop-text .kop-yayasan  { font-size: 11px; font-weight: 600; }
.kop-text .kop-madrasah { font-size: 17px; font-weight: 900; letter-spacing: .5px; margin: 2px 0; }
.kop-text .kop-alamat   { font-size: 10.5px; color: #333; line-height: 1.5; }

/* ---- JUDUL DOKUMEN ---- */
.doc-title { text-align: center; margin: 14px 0 12px; }
.doc-title h2 { font-size: 14px; font-weight: 900; text-transform: uppercase; letter-spacing: .4px; }
.doc-title .doc-sub { font-size: 11px; color: #444; margin-top: 2px; }

/* ---- TABEL ---- */
table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
thead th {
    background: #2563eb !important; color: #fff !important;
    font-weight: 700; text-align: center;
    padding: 7px 8px; border: 1px solid #000; font-size: 12px;
}
tbody td { border: 1px solid #555; padding: 6px 8px; vertical-align: middle; font-size: 12px; }
tbody tr:nth-child(even) { background: #f0f4ff; }
.td-no { text-align: center; width: 7%; }
.td-jabatan { text-align: center; width: 30%; }

/* ---- TANDA TANGAN ---- */
.ttd-wrap { display: flex; justify-content: flex-end; margin-top: 24px; }
.ttd-box { text-align: center; width: 230px; }
.ttd-box p { font-size: 12px; line-height: 1.6; }
.ttd-box img.qr { width: 80px; height: 80px; margin: 8px auto; display: block; }
.ttd-nama { font-weight: 700; margin-top: 4px; }
.ttd-nip  { font-size: 11px; }
.ttd-space { height: 56px; }

/* ---- FOOTER CETAK ---- */
.footer-cetak { margin-top: 10px; font-size: 10px; color: #777; text-align: center; }
</style>
</head>
<body>

<button class="btn-print no-print" onclick="window.print()">
    &#128424; Cetak / Simpan PDF
</button>

<!-- KOP SURAT -->
<div class="kop">
    <img src="<?= htmlspecialchars($logo_path) ?>" alt="Logo">
    <div class="kop-text">
        <?php if ($yayasan): ?>
        <div class="kop-yayasan"><?= htmlspecialchars($yayasan) ?></div>
        <?php endif; ?>
        <div class="kop-madrasah"><?= htmlspecialchars($madrasah) ?></div>
        <div class="kop-alamat">
            <?= htmlspecialchars($alamat) ?>
            <?php if ($telp): ?> &nbsp;|&nbsp; Telp: <?= htmlspecialchars($telp) ?><?php endif; ?>
        </div>
    </div>
</div>

<!-- JUDUL -->
<div class="doc-title">
    <h2>Pengurus Komite Madrasah</h2>
    <div class="doc-sub">Tahun Ajaran: <?= htmlspecialchars($school_profile['tahun_ajaran'] ?? '-') ?></div>
</div>

<!-- TABEL DATA -->
<table>
    <thead>
        <tr>
            <th class="td-no">No</th>
            <th>Nama</th>
            <th class="td-jabatan">Jabatan</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($rows)): ?>
        <tr><td colspan="3" style="text-align:center;color:#888;padding:14px;">Belum ada data anggota komite.</td></tr>
        <?php else: ?>
        <?php foreach ($rows as $i => $r): ?>
        <tr>
            <td class="td-no"><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($r['nama']) ?></td>
            <td class="td-jabatan"><?= htmlspecialchars($r['jabatan']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<!-- TANDA TANGAN -->
<div class="ttd-wrap">
    <div class="ttd-box">
        <p><?= htmlspecialchars($tempat_jadwal ?: '-') ?>, <?= htmlspecialchars($tgl_cetak) ?><br>Kepala Madrasah,</p>
        <?php if ($kepala_madrasah):
            $qr_data = 'Validasi: ' . $kepala_madrasah . ' – ' . ($school_profile['nama_madrasah'] ?? '');
            $qr_url  = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode($qr_data);
        ?>
        <img class="qr" src="<?= htmlspecialchars($qr_url) ?>" alt="QR Tanda Tangan">
        <?php else: ?>
        <div class="ttd-space"></div>
        <?php endif; ?>
        <div class="ttd-nama"><?= htmlspecialchars($kepala_madrasah ?: '.........................') ?></div>
        <?php if ($nip_kepala): ?>
        <div class="ttd-nip">NIP. <?= htmlspecialchars($nip_kepala) ?></div>
        <?php endif; ?>
    </div>
</div>

<div class="footer-cetak">Dicetak: <?= date('d-m-Y H:i:s') ?> &nbsp;|&nbsp; Sistem Informasi Madrasah</div>

<script>
window.onload = function() {
    setTimeout(function() { window.print(); }, 300);
};
</script>
</body>
</html>
