<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAuthorized(['admin', 'tata_usaha', 'wali'])) {
    redirect('../login.php');
}

$ta = trim((string) ($_GET['ta'] ?? ''));
$mode = trim((string) ($_GET['mode'] ?? 'single'));
$idSiswaFilter = (int) ($_GET['id_siswa'] ?? 0);
if (!isTahunAjaranFormatValid($ta)) {
    die('Tahun ajaran tidak valid.');
}

$school = getSchoolProfile($pdo);
$namaMi = trim((string) ($school['nama_madrasah'] ?? 'Madrasah'));
$namaMiUpper = strtoupper($namaMi);
$namaMiIsi = preg_replace('/\bMi\b/u', 'MI', ucwords(strtolower($namaMi)));
$yayasan = trim((string) ($school['nama_yayasan'] ?? ''));
$alamatMi = trim((string) ($school['alamat'] ?? ''));
$emailMi = trim((string) ($school['email_madrasah'] ?? ''));
$webMi = trim((string) ($school['website_madrasah'] ?? ''));
$kepalaNama = trim((string) ($school['kepala_madrasah'] ?? ''));
$nipKepala = trim((string) ($school['nip_kepala'] ?? ''));

$stJ = $pdo->prepare('SELECT tanggal_surat_kelulusan, kota_surat, waktu_mulai_tampil FROM tb_kelulusan_jadwal WHERE tahun_ajaran = ? LIMIT 1');
$stJ->execute([$ta]);
$jadwal = $stJ->fetch(PDO::FETCH_ASSOC) ?: [];
$tanggalSurat = trim((string) ($jadwal['tanggal_surat_kelulusan'] ?? ''));
$kotaSurat = trim((string) ($jadwal['kota_surat'] ?? ''));
if ($kotaSurat === '') {
    $kotaSurat = 'Jepara';
}
if ($tanggalSurat === '') {
    die('Tanggal surat belum diatur di Data Peserta Ujian.');
}
$tanggalSuratIndo = formatDateIndonesia($tanggalSurat);

$sql = 'SELECT pu.id_siswa, pu.nomor_ujian, pu.is_lulus, s.nama_siswa, s.nisn, k.nama_kelas
        FROM tb_peserta_ujian pu
        INNER JOIN tb_siswa s ON s.id_siswa = pu.id_siswa
        LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas
        WHERE pu.tahun_ajaran = ?';
$params = [$ta];
if ($idSiswaFilter > 0 && $mode !== 'all') {
    $sql .= ' AND pu.id_siswa = ?';
    $params[] = $idSiswaFilter;
}
$sql .= ' ORDER BY s.nama_siswa ASC';
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    die('Data peserta ujian tidak ditemukan.');
}

$isCetakSemua = ($mode === 'all' || $idSiswaFilter <= 0);
$kelasList = [];
foreach ($rows as $_r) {
    $kelasVal = trim((string) ($_r['nama_kelas'] ?? ''));
    if ($kelasVal !== '') {
        $kelasList[] = $kelasVal;
    }
}
$kelasList = array_values(array_unique($kelasList));
$kelasLabel = $kelasList ? implode(', ', $kelasList) : 'Kelas VI';
$namaSiswaSingle = trim((string) ($rows[0]['nama_siswa'] ?? 'Siswa'));
$pageTitleCetak = $isCetakSemua
    ? ('Cetak Semua Surat Kelulusan - ' . $kelasLabel . ' - Tahun Ajaran ' . $ta)
    : ('Cetak Surat Kelulusan - ' . $namaSiswaSingle);

$logoFn = trim((string) ($school['logo'] ?? ''));
$logoPath = '';
if ($logoFn !== '' && preg_match('/^[a-zA-Z0-9._ -]+\.(jpe?g|png|gif)$/i', $logoFn)) {
    $physical = dirname(__DIR__) . '/assets/img/' . basename($logoFn);
    if (is_readable($physical)) {
        $logoPath = '../assets/img/' . basename($logoFn);
    }
}

$qrText = 'Validasi Tanda Tangan Digital: ' . ($kepalaNama !== '' ? $kepalaNama : 'Kepala Madrasah') . ' - ' . $namaMi;
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . rawurlencode($qrText);

function renderSurat(array $r, string $ta, string $namaMi, string $namaMiUpper, string $namaMiIsi, string $yayasan, string $alamatMi, string $emailMi, string $webMi, string $kotaSurat, string $tanggalSuratIndo, string $kepalaNama, string $nipKepala, string $qrUrl, string $logoPath): void
{
    $nama = trim((string) ($r['nama_siswa'] ?? ''));
    $nisn = trim((string) ($r['nisn'] ?? ''));
    $nomorAm = trim((string) ($r['nomor_ujian'] ?? ''));
    $lulus = !empty($r['is_lulus']);
    ?>
    <section class="surat">
        <table class="kop">
            <tr>
                <?php if ($logoPath !== ''): ?>
                    <td class="kop-logo"><img src="<?= htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8') ?>" alt="Logo"></td>
                <?php else: ?>
                    <td class="kop-logo">&nbsp;</td>
                <?php endif; ?>
                <td class="kop-text">
                    <?php if ($yayasan !== ''): ?><div class="kop-yayasan"><?= htmlspecialchars(strtoupper($yayasan), ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                    <div class="kop-mi"><?= htmlspecialchars($namaMiUpper, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if ($alamatMi !== ''): ?><div class="kop-line"><?= htmlspecialchars($alamatMi, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                    <?php if ($emailMi !== '' || $webMi !== ''): ?>
                        <div class="kop-line"><?php if ($emailMi !== ''): ?>Email: <?= htmlspecialchars($emailMi, ENT_QUOTES, 'UTF-8') ?><?php endif; ?><?php if ($emailMi !== '' && $webMi !== ''): ?> &nbsp; <?php endif; ?><?php if ($webMi !== ''): ?>Website: <?= htmlspecialchars($webMi, ENT_QUOTES, 'UTF-8') ?><?php endif; ?></div>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <hr class="kop-garis">
        <div class="judul">SURAT KETERANGAN KELULUSAN</div>
        <p class="intro">
            Berdasarkan hasil Pra Asesmen Madrasah dan Asesmen Madrasah, serta hasil Rapat Dewan Guru dan Kepala
            <strong><?= htmlspecialchars($namaMiIsi, ENT_QUOTES, 'UTF-8') ?></strong>
            Tahun Ajaran <strong><?= htmlspecialchars($ta, ENT_QUOTES, 'UTF-8') ?></strong>
            di <strong><?= htmlspecialchars($namaMiIsi, ENT_QUOTES, 'UTF-8') ?></strong>.
            Maka dengan ini Kepala <strong><?= htmlspecialchars($namaMiIsi, ENT_QUOTES, 'UTF-8') ?></strong> menyatakan bahwa:
        </p>
        <div class="duk">
            <div class="duk-row"><span class="duk-l">Nama</span><span class="duk-c">:</span><span class="duk-r"><?= $nama !== '' ? htmlspecialchars($nama, ENT_QUOTES, 'UTF-8') : '—' ?></span></div>
            <div class="duk-row"><span class="duk-l">NISN</span><span class="duk-c">:</span><span class="duk-r"><?= $nisn !== '' ? htmlspecialchars($nisn, ENT_QUOTES, 'UTF-8') : '—' ?></span></div>
            <div class="duk-row"><span class="duk-l">Nomor Peserta AM</span><span class="duk-c">:</span><span class="duk-r"><?= $nomorAm !== '' ? htmlspecialchars($nomorAm, ENT_QUOTES, 'UTF-8') : '—' ?></span></div>
        </div>
        <div class="dinya">DINYATAKAN:</div>
        <div class="stat">
            (&nbsp;<span class="core"><?= $lulus ? 'LULUS' : 'TIDAK LULUS' ?></span><?= $lulus ? ' / <span class="strike">TIDAK LULUS</span>' : ' / <span class="strike">LULUS</span>' ?>&nbsp;)
        </div>
        <div class="penutup">
            <?php if ($lulus): ?>
                Sehingga yang bersangkutan berhak memperoleh ijazah <strong><?= htmlspecialchars($namaMiIsi, ENT_QUOTES, 'UTF-8') ?></strong> Tahun Ajaran <?= htmlspecialchars($ta, ENT_QUOTES, 'UTF-8') ?>.
            <?php else: ?>
                Yang bersangkutan <strong>tidak berhak</strong> memperoleh ijazah <strong><?= htmlspecialchars($namaMiIsi, ENT_QUOTES, 'UTF-8') ?></strong> Tahun Ajaran <?= htmlspecialchars($ta, ENT_QUOTES, 'UTF-8') ?> hingga dipenuhinya persyaratan kelulusan.
            <?php endif; ?>
        </div>
        <div class="tte">
            <div class="tte-tgl"><?= htmlspecialchars($kotaSurat, ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($tanggalSuratIndo, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="tte-jab">Kepala <?= htmlspecialchars($namaMiIsi, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="tte-qr"><img src="<?= htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') ?>" width="144" height="144" alt="QR"></div>
            <div class="tte-nama"><?= $kepalaNama !== '' ? htmlspecialchars($kepalaNama, ENT_QUOTES, 'UTF-8') : '________________________' ?></div>
            <?php if ($nipKepala !== ''): ?><div class="tte-nip">NIP <?= htmlspecialchars($nipKepala, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        </div>
    </section>
    <?php
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitleCetak, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        @page { size: A4; margin: 18mm; }
        body { font-family: "Times New Roman", Times, serif; font-size: 12pt; margin: 0; padding: 24px 32px; line-height: 1.55; color: #000; }
        .no-print { text-align: center; margin-bottom: 18px; font-family: Arial, sans-serif; }
        .no-print button, .no-print a { padding: 8px 16px; font-size: 14px; margin: 0 6px; }
        .surat { page-break-after: always; }
        .surat:last-child { page-break-after: auto; }
        .kop { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .kop td { vertical-align: middle; padding: 4px 8px 4px 0; }
        .kop-logo { width: 95px; }
        .kop-logo img { max-width: 88px; max-height: 100px; display: block; }
        .kop-text { text-align: center; padding-left: 4px !important; }
        .kop-yayasan { font-size: 13px; letter-spacing: 0.03em; }
        .kop-mi { font-size: 16px; font-weight: bold; margin-top: 2px; }
        .kop-line { font-size: 11.5px; margin-top: 2px; }
        .kop-garis { border: none; border-top: 3px solid #000; margin: 10px 0 16px 0; }
        .judul { text-align: center; font-size: 13.5pt; font-weight: bold; text-decoration: underline; margin-bottom: 18px; letter-spacing: 0.04em; }
        .intro { text-align: justify; margin-bottom: 16px; }
        .duk { margin: 12px 0 14px 0; }
        .duk-row { margin: 2px 0; display: table; width: 100%; font-size: 12pt; }
        .duk-l { display: table-cell; width: 15rem; vertical-align: top; }
        .duk-c { display: table-cell; width: 1.2rem; vertical-align: top; }
        .duk-r { display: table-cell; vertical-align: top; }
        .dinya { text-align: center; font-weight: bold; font-size: 13pt; margin: 22px 0 6px 0; letter-spacing: 0.06em; }
        .stat { text-align: center; font-weight: bold; font-size: 22pt; margin: 10px 0 22px 0; letter-spacing: 0.03em; }
        .stat .core { padding: 0 0.2em; }
        .strike { text-decoration: line-through; opacity: 0.55; font-weight: normal; font-size: 0.82em; }
        .penutup { text-align: center; margin: 18px 0 48px 0; line-height: 1.65; padding: 0 8%; }
        .tte { margin-top: 36px; text-align: right; width: 100%; padding-right: 4%; }
        .tte-tgl { margin-bottom: 6px; }
        .tte-jab { margin-bottom: 8px; }
        .tte-qr img { margin: 10px auto 42px auto; display: inline-block; }
        .tte-nama { font-weight: bold; font-size: 12.5pt; }
        .tte-nip { font-size: 11pt; margin-top: 2px; }
        @media print { body { padding: 0; } .no-print { display: none !important; } }
    </style>
</head>
<body>
<div class="no-print">
    <button type="button" onclick="window.print();">Cetak / Unduh PDF</button>
    <a href="data_peserta_ujian.php?ta=<?= urlencode($ta) ?>">Kembali</a>
</div>
<?php foreach ($rows as $r) { renderSurat($r, $ta, $namaMi, $namaMiUpper, $namaMiIsi, $yayasan, $alamatMi, $emailMi, $webMi, $kotaSurat, $tanggalSuratIndo, $kepalaNama, $nipKepala, $qrUrl, $logoPath); } ?>
<script>
window.addEventListener('load', function() {
    window.print();
});
</script>
</body>
</html>
