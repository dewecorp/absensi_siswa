<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAuthorized(['siswa'])) {
    redirect('../login.php');
}

$id_siswa = (int) ($_SESSION['user_id'] ?? 0);
if ($id_siswa <= 0) {
    redirect('../login.php');
}

$stKelas = $pdo->prepare('SELECT k.nama_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_siswa = ? LIMIT 1');
$stKelas->execute([$id_siswa]);
$nama_kelas_siswa = (string) ($stKelas->fetchColumn() ?: '');
$nkUpper = strtoupper($nama_kelas_siswa);
if (strpos($nkUpper, '6') === false && strpos($nkUpper, 'VI') === false) {
    echo "<script>alert('Halaman ini hanya untuk siswa kelas 6'); window.location.href='dashboard.php';</script>";
    exit;
}

foreach ([

    ['tanggal_surat_kelulusan', "DATE DEFAULT NULL AFTER siswa_lihat_kelulusan"],

    ['kota_surat', 'VARCHAR(80) DEFAULT NULL AFTER tanggal_surat_kelulusan'],

    ['qr_tanda_tangan_payload', 'VARCHAR(768) DEFAULT NULL AFTER kota_surat'],

] as $colPair) {
    try {
        $pdo->exec("ALTER TABLE tb_kelulusan_jadwal ADD COLUMN `{$colPair[0]}` {$colPair[1]}");
    } catch (PDOException $e) {
    }
}

$school = getSchoolProfile($pdo);
$taBerjalan = trim((string) ($school['tahun_ajaran'] ?? ''));
$filterTaBerjalan = isTahunAjaranFormatValid($taBerjalan);

$sql = '
    SELECT pu.tahun_ajaran, pu.nomor_ujian, pu.is_lulus,
           j.waktu_mulai_tampil, j.tanggal_surat_kelulusan, j.kota_surat
    FROM tb_peserta_ujian pu
    INNER JOIN tb_kelulusan_jadwal j ON j.tahun_ajaran = pu.tahun_ajaran AND j.siswa_lihat_kelulusan = 1
    WHERE pu.id_siswa = ?';

$paramsKel = [$id_siswa];
if ($filterTaBerjalan) {
    $sql .= ' AND pu.tahun_ajaran = ?';
    $paramsKel[] = $taBerjalan;
}
$sql .= ' ORDER BY pu.tahun_ajaran DESC LIMIT 1';

$st = $pdo->prepare($sql);
$st->execute($paramsKel);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    redirect('info_kelulusan.php');
}

$jadwal_raw = $row['waktu_mulai_tampil'] ?? null;
if ($jadwal_raw !== null && $jadwal_raw !== '') {
    try {
        $belum = new DateTimeImmutable('now') < new DateTimeImmutable((string) $jadwal_raw);
        if ($belum) {
            redirect('info_kelulusan.php');
        }
    } catch (Exception $e) {
    }
}

$tanggal_surat = trim((string) ($row['tanggal_surat_kelulusan'] ?? ''));
if ($tanggal_surat === '') {
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>Cetak</title></head><body style="font-family:sans-serif;padding:2rem;"><p>Pengurus belum menyimpan <strong>tanggal surat</strong> di menu Admin → Data Peserta Ujian untuk tahun ajaran ini.</p><p><a href="info_kelulusan.php">Kembali</a></p></body></html>';
    exit;
}

$stS = $pdo->prepare('SELECT nama_siswa, nisn FROM tb_siswa WHERE id_siswa = ? LIMIT 1');
$stS->execute([$id_siswa]);
$siswaInfo = $stS->fetch(PDO::FETCH_ASSOC) ?: [];

$nama_siswa_teks = trim((string) ($siswaInfo['nama_siswa'] ?? ''));
$nisn_teks = trim((string) ($siswaInfo['nisn'] ?? ''));
$nomor_am = trim((string) ($row['nomor_ujian'] ?? ''));
$ta = (string) ($row['tahun_ajaran'] ?? '');
$lulus = !empty($row['is_lulus']);

$nama_mi = trim((string) ($school['nama_madrasah'] ?? 'Madrasah'));
$nama_mi_upper = strtoupper($nama_mi);
$nama_mi_isi = preg_replace('/\bMi\b/u', 'MI', ucwords(strtolower($nama_mi)));
$yayasan = trim((string) ($school['nama_yayasan'] ?? ''));
$alamat_mi = trim((string) ($school['alamat'] ?? ''));
$email_mi = trim((string) ($school['email_madrasah'] ?? ''));
$web_mi = trim((string) ($school['website_madrasah'] ?? ''));
$kepala_nama = trim((string) ($school['kepala_madrasah'] ?? ''));
$nip_kepala = trim((string) ($school['nip_kepala'] ?? ''));

$kota_surat_teks = trim((string) ($row['kota_surat'] ?? ''));
if ($kota_surat_teks === '') {
    $kota_surat_teks = 'Jepara';
}

$tgl_tertib = '';
try {
    $tgl_tertib = formatDateIndonesia($tanggal_surat);
} catch (Throwable $e) {
    $tgl_tertib = $tanggal_surat;
}

$qr_txt = 'Validasi Tanda Tangan Digital: ' . ($kepala_nama !== '' ? $kepala_nama : 'Kepala Madrasah') . ' - ' . $nama_mi;
$qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . rawurlencode($qr_txt);

$logo_fn = isset($school['logo']) ? trim((string) $school['logo']) : '';
$logo_path = '';
if ($logo_fn !== '' && preg_match('/^[a-zA-Z0-9._ -]+\.(jpe?g|png|gif)$/i', $logo_fn)) {
    $physical = dirname(__DIR__) . '/assets/img/' . basename($logo_fn);
    if (is_readable($physical)) {
        $logo_path = '../assets/img/' . htmlspecialchars(basename($logo_fn), ENT_QUOTES, 'UTF-8');
    }
}

$page_title_surat = 'Cetak Surat Kelulusan - ' . ($nama_siswa_teks !== '' ? $nama_siswa_teks : 'Siswa');
header('Content-Type: text/html; charset=utf-8');

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title_surat) ?></title>
    <style>
        @page { size: A4; margin: 18mm; }
        * { box-sizing: border-box; }
        body { font-family: "Times New Roman", Times, serif; font-size: 12pt; color: #000; margin: 0; padding: 24px 32px 40px; line-height: 1.55; }
        .no-print { text-align: center; margin-bottom: 20px; }
        .no-print button { padding: 8px 20px; font-size: 14px; cursor: pointer; }
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
        .intro { text-align: justify; text-indent: 0; margin-bottom: 16px; }
        .duk { margin: 12px 0 14px 0; }
        .duk-row { margin: 2px 0; display: table; width: 100%; font-size: 12pt; }
        .duk-l { display: table-cell; width: 15rem; font-weight: normal; vertical-align: top; }
        .duk-c { display: table-cell; width: 1.2rem; vertical-align: top; }
        .duk-r { display: table-cell; vertical-align: top; }
        .dinya { text-align: center; font-weight: bold; font-size: 13pt; margin: 22px 0 6px 0; letter-spacing: 0.06em; }
        .stat { text-align: center; font-weight: bold; font-size: 22pt; margin: 10px 0 22px 0; letter-spacing: 0.03em; }
        .stat .core { padding: 0 0.2em; }
        .strike { text-decoration: line-through; opacity: 0.55; font-weight: normal; font-size: 0.82em; }
        .penutup { text-align: center; margin: 18px 0 48px 0; line-height: 1.65; padding: 0 8%; }
        .tte { margin-top: 36px; text-align: right; width: 100%; padding-right: 4%; }
        .tte-tgl { margin-bottom: 6px; }
        .tte-jab { margin-bottom: 8px; font-weight: normal; }
        .tte-qr img { margin: 10px auto 42px auto; display: inline-block; }
        .tte-nama { font-weight: bold; font-size: 12.5pt; }
        .tte-nip { font-size: 11pt; margin-top: 2px; }
        @media print {
            body { padding: 0; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<div class="no-print">
    <button type="button" onclick="window.print();">Cetak / Unduh PDF</button>
    <a href="info_kelulusan.php">Kembali</a>
</div>

<table class="kop">
    <tr>
        <?php if ($logo_path !== ''): ?>
            <td class="kop-logo"><img src="<?= htmlspecialchars($logo_path) ?>" alt="Logo"></td>
        <?php else: ?>
            <td class="kop-logo">&nbsp;</td>
        <?php endif; ?>
        <td class="kop-text">
            <?php if ($yayasan !== ''): ?>
                <div class="kop-yayasan"><?= htmlspecialchars(strtoupper($yayasan)) ?></div>
            <?php endif; ?>
            <div class="kop-mi"><?= htmlspecialchars($nama_mi_upper, ENT_QUOTES, 'UTF-8') ?></div>
            <?php if ($alamat_mi !== ''): ?>
                <div class="kop-line"><?= htmlspecialchars($alamat_mi) ?></div>
            <?php endif; ?>
            <?php if ($email_mi !== '' || $web_mi !== ''): ?>
                <div class="kop-line"><?php if ($email_mi !== '') : ?>Email: <?= htmlspecialchars($email_mi) ?><?php endif; ?><?php if ($email_mi !== '' && $web_mi !== ''): ?> &nbsp; <?php endif; ?><?php if ($web_mi !== ''): ?>Website: <?= htmlspecialchars($web_mi) ?><?php endif; ?></div>
            <?php endif; ?>
        </td>
    </tr>
</table>
<hr class="kop-garis">

<div class="judul">SURAT KETERANGAN KELULUSAN</div>

<p class="intro">
    Berdasarkan hasil Pra Asesmen Madrasah dan Asesmen Madrasah, serta hasil Rapat Dewan Guru dan Kepala
    <strong><?= htmlspecialchars($nama_mi_isi, ENT_QUOTES, 'UTF-8') ?></strong>
    Tahun Ajaran <strong><?= htmlspecialchars($ta, ENT_QUOTES, 'UTF-8') ?></strong>
    di <strong><?= htmlspecialchars($nama_mi_isi, ENT_QUOTES, 'UTF-8') ?></strong>.
    Maka dengan ini Kepala <strong><?= htmlspecialchars($nama_mi_isi, ENT_QUOTES, 'UTF-8') ?></strong> menyatakan bahwa:
</p>

<div class="duk">
    <div class="duk-row"><span class="duk-l">Nama</span><span class="duk-c">:</span><span class="duk-r"><?= $nama_siswa_teks !== '' ? htmlspecialchars($nama_siswa_teks, ENT_QUOTES, 'UTF-8') : '—' ?></span></div>
    <div class="duk-row"><span class="duk-l">NISN</span><span class="duk-c">:</span><span class="duk-r"><?= $nisn_teks !== '' ? htmlspecialchars($nisn_teks, ENT_QUOTES, 'UTF-8') : '—' ?></span></div>
    <div class="duk-row"><span class="duk-l">Nomor Peserta AM</span><span class="duk-c">:</span><span class="duk-r"><?= $nomor_am !== '' ? htmlspecialchars($nomor_am, ENT_QUOTES, 'UTF-8') : '—' ?></span></div>
</div>

<div class="dinya">DINYATAKAN:</div>

<div class="stat">
    (&nbsp;<span class="core"><?= $lulus ? 'LULUS' : 'TIDAK LULUS' ?></span>
    <?= $lulus ? ' / <span class="strike">TIDAK LULUS</span>' : ' / <span class="strike">LULUS</span>' ?>&nbsp;)
</div>

<div class="penutup">
    <?php if ($lulus): ?>
        Sehingga yang bersangkutan berhak memperoleh ijazah <strong><?= htmlspecialchars($nama_mi_isi, ENT_QUOTES, 'UTF-8') ?></strong> Tahun Ajaran <?= htmlspecialchars($ta, ENT_QUOTES, 'UTF-8') ?>.
    <?php else: ?>
        Yang bersangkutan <strong>tidak berhak</strong> memperoleh ijazah <strong><?= htmlspecialchars($nama_mi_isi, ENT_QUOTES, 'UTF-8') ?></strong> Tahun Ajaran <?= htmlspecialchars($ta, ENT_QUOTES, 'UTF-8') ?> hingga dipenuhinya persyaratan kelulusan.
    <?php endif; ?>
</div>

<div class="tte">
    <div class="tte-tgl"><?= htmlspecialchars($kota_surat_teks) ?>, <?= htmlspecialchars($tgl_tertib) ?></div>
    <div class="tte-jab">Kepala <?= htmlspecialchars($nama_mi_isi, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="tte-qr"><img src="<?= htmlspecialchars($qr_url, ENT_QUOTES, 'UTF-8') ?>" width="144" height="144" alt="QR"></div>
    <div class="tte-nama"><?= $kepala_nama !== '' ? htmlspecialchars($kepala_nama, ENT_QUOTES, 'UTF-8') : '________________________' ?></div>
    <?php if ($nip_kepala !== ''): ?>
        <div class="tte-nip">NIP <?= htmlspecialchars($nip_kepala, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
</div>

<script>
window.addEventListener('load', function() {
    window.print();
});
</script>

</body>
</html>
