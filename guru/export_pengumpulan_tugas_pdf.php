<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['guru', 'wali', 'admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$id_tugas = (int)($_GET['id'] ?? 0);
if ($id_tugas <= 0) {
    echo "<script>alert('ID Tugas tidak valid.'); window.history.back();</script>";
    exit;
}

// Mode output: print (window.print tab baru, stabil + support reload) atau pdf (unduh file via Dompdf).
$mode = strtolower(trim((string)($_GET['mode'] ?? '')));
if ($mode === '' && (int)($_GET['download'] ?? 0) === 1) {
    $mode = 'pdf';
}
if (!in_array($mode, ['print', 'pdf'], true)) {
    $mode = 'print';
}
$is_download = ($mode === 'pdf');

// Deteksi wali kelas login
$wali_kelas_id = 0;
if ($user_level !== 'admin' && $user_level !== 'kepala_madrasah') {
    try {
        $stWali = $pdo->prepare("SELECT id_kelas FROM tb_kelas WHERE wali_kelas = ? OR wali_kelas = (SELECT nama_guru FROM tb_guru WHERE id_guru = ?)");
        $stWali->execute([$guru_id, $guru_id]);
        $wali_kelas_id = (int)$stWali->fetchColumn() ?: 0;
    } catch (Throwable $e) {}
}

if ($user_level === 'admin' || $user_level === 'kepala_madrasah') {
    $auth_sql = "t.id = ?";
    $auth_params = [$id_tugas];
} else {
    $auth_sql = "t.id = ? AND (t.id_guru = ? " . ($wali_kelas_id > 0 ? "OR t.id_kelas = $wali_kelas_id" : "") . ")";
    $auth_params = [$id_tugas, $guru_id];
}

$st = $pdo->prepare("
    SELECT t.*, m.nama_mapel, k.nama_kelas, k.wali_kelas, g.nama_guru
    FROM tb_tugas t
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = t.id_mapel
    LEFT JOIN tb_kelas k ON k.id_kelas = t.id_kelas
    LEFT JOIN tb_guru g ON g.id_guru = t.id_guru
    WHERE $auth_sql
    LIMIT 1
");
$st->execute($auth_params);
$tugas = $st->fetch(PDO::FETCH_ASSOC);
if (!$tugas) {
    echo "<script>alert('Tugas tidak ditemukan atau Anda tidak berhak mengaksesnya.'); window.history.back();</script>";
    exit;
}

$stS = $pdo->prepare("
    SELECT s.nama_siswa, s.nisn,
           tp.tgl_kumpul, tp.nilai, tp.status_periksa
    FROM tb_siswa s
    LEFT JOIN tb_tugas_pengumpulan tp ON tp.id_siswa = s.id_siswa AND tp.id_tugas = ?
    WHERE s.id_kelas = ?
    ORDER BY s.nama_siswa ASC
");
$stS->execute([$id_tugas, $tugas['id_kelas']]);
$rows = $stS->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "<script>alert('Tidak ada data siswa untuk dicetak.'); window.history.back();</script>";
    exit;
}

$deadline_ts = !empty($tugas['deadline']) ? strtotime($tugas['deadline']) : null;
foreach ($rows as &$r) {
    $is_kumpul = !empty($r['tgl_kumpul']);
    $r['_kumpul'] = $is_kumpul ? 'Sudah' : 'Belum';
    $r['_tgl'] = $is_kumpul ? date('d/m/Y H:i', strtotime($r['tgl_kumpul'])) : '-';
    $late = '-';
    if ($is_kumpul && $deadline_ts) {
        $diff = strtotime($r['tgl_kumpul']) - $deadline_ts;
        if ($diff > 0) {
            $jam = floor($diff / 3600);
            $late = $jam > 24 ? ('Telat ' . floor($jam / 24) . ' hari') : ('Telat ' . $jam . ' jam');
        } else {
            $late = 'Tepat Waktu';
        }
    }
    $r['_late'] = $late;
    $r['_nilai'] = ($r['nilai'] !== null && $r['nilai'] !== '') ? (string)((float)$r['nilai']) : '-';
    $r['_periksa'] = $r['status_periksa'] ?? 'Belum Diperiksa';
}
unset($r);

// Profil Madrasah
$school = getSchoolProfile($pdo);
$nama_madrasah = $school['nama_madrasah'] ?? 'Madrasah Ibtidaiyah';
$alamat_madrasah = $school['alamat'] ?? '';
$tahun_ajaran = $school['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1);
$semester = $school['semester'] ?? 'Semester 1';
$tempat_jadwal = $school['tempat_jadwal'] ?? 'Jepara';
$kepala_madrasah = $school['kepala_madrasah'] ?? '-';
$nip_kepala = $school['nip_kepala'] ?? '-';
$guru_mapel = $tugas['nama_guru'] ?? '-';

$judul_dokumen = "REKAPITULASI PENGUMPULAN TUGAS - " . strtoupper((string)$tugas['judul']);
$ta_file = preg_replace('/[^A-Za-z0-9-]+/', '', str_replace('/', '-', $tahun_ajaran));
$filename = "Rekap_Pengumpulan_Tugas_" . (int)$tugas['id'] . "_TA" . $ta_file . "_" . date('Ymd');

$logo_file = $school['logo'] ?? '';
if (!function_exists('tugas_img_b64')) {
    function tugas_img_b64(string $src): string {
        $src = trim($src);
        if ($src === '') return '';
        if (strpos($src, 'data:image') === 0) return $src;
        $data = false;
        if (preg_match('#^https?://#i', $src)) {
            if (function_exists('curl_init')) {
                $ch = curl_init($src);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_USERAGENT, 'SIMAD-Madrasah/1.0');
                $data = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($code !== 200 || !is_string($data) || strlen($data) < 500) $data = false;
            } else {
                $ctx = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => 'SIMAD-Madrasah/1.0']]);
                $data = @file_get_contents($src, false, $ctx);
                if (!is_string($data) || strlen($data) < 500) $data = false;
            }
            if ($data === false) return $src;
            $mime = 'image/png';
        } else {
            $fs = (strpos($src, __DIR__) === 0) ? $src : __DIR__ . '/' . ltrim($src, '/');
            if (!is_file($fs)) return '';
            $info = @getimagesize($fs);
            $mime = $info['mime'] ?? 'image/png';
            $data = @file_get_contents($fs);
            if (!is_string($data) || $data === '') return '';
        }
        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }
}

$logo_print = '';
if (!empty($logo_file)) {
    foreach (['../assets/img/' . $logo_file, '../uploads/' . $logo_file] as $lp) {
        $fs = __DIR__ . '/' . $lp;
        if (is_file($fs)) { $logo_print = $lp; break; }
    }
}
$logo_src = $logo_print;
if ($is_download && $logo_print !== '') {
    $fs = (strpos($logo_print, __DIR__) === 0) ? $logo_print : __DIR__ . '/' . ltrim($logo_print, '/');
    $b64 = tugas_img_b64($fs);
    if ($b64 !== '') $logo_src = $b64;
}

$qr_guru_url = 'https://api.qrserver.com/v1/create-qr-code/?size=90x90&data=' . urlencode("Guru Mapel: {$guru_mapel} - Tugas: {$tugas['judul']} - {$nama_madrasah}");
$qr_kepala_url = 'https://api.qrserver.com/v1/create-qr-code/?size=90x90&data=' . urlencode("Kepala Madrasah: {$kepala_madrasah} - {$nama_madrasah}");
$qr_guru = $qr_guru_url;
$qr_kepala = $qr_kepala_url;
if ($is_download) {
    $b64w = tugas_img_b64($qr_guru_url);
    $b64k = tugas_img_b64($qr_kepala_url);
    if (strpos($b64w, 'data:image') === 0) $qr_guru = $b64w;
    if (strpos($b64k, 'data:image') === 0) $qr_kepala = $b64k;
}

ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($judul_dokumen) ?></title>
    <style>
        @page { size: 330mm 215mm; margin: 10mm 12mm; }
        body { font-family: Arial, "Helvetica Neue", Helvetica, sans-serif; font-size: 10pt; color: #111; line-height: 1.4; background: #fff; margin: 0; padding: 10px; }
        .header-kop { border-bottom: 2.5px solid #000; padding-bottom: 10px; margin-bottom: 15px; }
        .header-kop h2 { margin: 0; font-size: 14pt; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
        .header-kop h3 { margin: 2px 0; font-size: 12pt; font-weight: 700; }
        .header-kop p { margin: 2px 0; font-size: 9pt; color: #333; }
        .header-kop table { table-layout: fixed; }
        .header-kop td { overflow: visible; }
        .kop-logo-img { width: 82px; height: 71px; display: block; margin: 0 auto; }
        .doc-title { text-align: center; margin: 14px 0 16px; }
        .doc-title h4 { margin: 0; font-size: 10.5pt; font-weight: 800; text-decoration: underline; text-transform: uppercase; line-height: 1.35; }
        .doc-title small { font-size: 9pt; color: #444; }
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 9.5pt; }
        .info-table td { padding: 3px 6px; vertical-align: top; }
        .info-table .lbl { width: 150px; font-weight: bold; color: #222; }
        .table-data { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 9pt; table-layout: fixed; }
        .table-data th, .table-data td { border: 1px solid #333; padding: 6px 8px; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; }
        .table-data th { background-color: #f1f5f9; font-weight: bold; text-align: center; font-size: 9pt; }
        .signature-table { width: 100%; border-collapse: collapse; margin-top: 30px; page-break-inside: avoid; font-size: 9.5pt; }
        .signature-table td { text-align: center; vertical-align: top; width: 50%; }
        .no-print { background: #f8fafc; border: 1px solid #cbd5e1; padding: 10px 14px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; border-radius: 6px; }
        @media print { .no-print { display: none !important; } body { padding: 0; } }
    </style>
</head>
<body>

<?php if (!$is_download): ?>
    <div class="no-print">
        <div><strong>Pratinjau Cetak Pengumpulan Tugas</strong> &bull; <span class="text-muted"><?= htmlspecialchars($filename) ?></span></div>
        <div>
            <button onclick="window.print()" style="background:#2563eb;color:#fff;border:none;padding:7px 16px;border-radius:4px;font-weight:bold;cursor:pointer;">Cetak Sekarang</button>
            <?php
            $qs_pdf = $_GET;
            unset($qs_pdf['download']);
            $qs_pdf['mode'] = 'pdf';
            ?>
            <a href="export_pengumpulan_tugas_pdf.php?<?= http_build_query($qs_pdf) ?>" style="background:#dc2626;color:#fff;text-decoration:none;padding:7px 14px;border-radius:4px;font-weight:bold;margin-left:6px;">Unduh File PDF</a>
            <button onclick="window.close()" style="background:#64748b;color:#fff;border:none;padding:7px 12px;border-radius:4px;margin-left:6px;cursor:pointer;">Tutup</button>
        </div>
    </div>
<?php endif; ?>

<div class="header-kop">
    <table style="width: 100%; border: none; margin: 0;">
        <tr style="border: none;">
            <td style="border: none; width: 110px; min-width: 110px; text-align: center; vertical-align: middle; padding: 0 5px;">
                <?php if ($logo_src !== ''): ?>
                    <img src="<?= $logo_src ?>" class="kop-logo-img" alt="Logo">
                <?php endif; ?>
            </td>
            <td style="border: none; text-align: center; vertical-align: middle;">
                <h2><?= htmlspecialchars($nama_madrasah) ?></h2>
                <h3>REKAPITULASI PENGUMPULAN TUGAS</h3>
                <p><?= htmlspecialchars($alamat_madrasah) ?></p>
            </td>
            <td style="border: none; width: 110px; min-width: 110px;"></td>
        </tr>
    </table>
</div>

<div class="doc-title">
    <h4><?= htmlspecialchars($judul_dokumen) ?></h4>
    <small>Tahun Ajaran <?= htmlspecialchars($tahun_ajaran) ?> (<?= htmlspecialchars($semester) ?>) &bull; Tanggal Cetak: <?= date('d F Y') ?></small>
</div>

<table class="info-table">
    <tr><td class="lbl">Judul Tugas</td><td>: <strong><?= htmlspecialchars($tugas['judul']) ?></strong></td></tr>
    <tr><td class="lbl">Mata Pelajaran</td><td>: <?= htmlspecialchars($tugas['nama_mapel'] ?? '-') ?></td></tr>
    <tr><td class="lbl">Kelas</td><td>: <?= htmlspecialchars($tugas['nama_kelas'] ?? '-') ?></td></tr>
    <tr><td class="lbl">Guru Pengampu</td><td>: <?= htmlspecialchars($guru_mapel) ?></td></tr>
    <tr><td class="lbl">Deadline</td><td>: <?= !empty($tugas['deadline']) ? date('d/m/Y H:i', strtotime($tugas['deadline'])) : '-' ?></td></tr>
    <tr><td class="lbl">Nilai Maksimal</td><td>: <?= (int)$tugas['nilai_maksimal'] ?></td></tr>
</table>

<table class="table-data">
    <colgroup>
        <col style="width:5%;"><col style="width:21%;"><col style="width:12%;"><col style="width:8%;"><col style="width:12%;"><col style="width:12%;"><col style="width:8%;"><col style="width:22%;">
    </colgroup>
    <thead>
        <tr>
            <th>No</th><th>Nama Siswa</th><th>NISN</th><th>Status</th><th>Tgl Kumpul</th><th>Keterlambatan</th><th>Nilai</th><th>Pemeriksaan</th>
        </tr>
    </thead>
    <tbody>
        <?php $no = 1; foreach ($rows as $r): ?>
            <tr>
                <td style="text-align:center;"><?= $no++ ?></td>
                <td><strong><?= htmlspecialchars($r['nama_siswa']) ?></strong></td>
                <td style="text-align:center;"><?= htmlspecialchars($r['nisn'] ?? '-') ?></td>
                <td style="text-align:center;"><?= htmlspecialchars($r['_kumpul']) ?></td>
                <td style="text-align:center;"><?= htmlspecialchars($r['_tgl']) ?></td>
                <td style="text-align:center;"><?= htmlspecialchars($r['_late']) ?></td>
                <td style="text-align:center;"><?= htmlspecialchars($r['_nilai']) ?></td>
                <td style="text-align:center;"><?= htmlspecialchars($r['_periksa']) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<table class="signature-table">
    <tr>
        <td>
            Mengetahui,<br>
            Kepala Madrasah<br>
            <img src="<?= $qr_kepala ?>" alt="QR TTD Kepala" style="width: 60px; height: 60px; margin: 6px auto; display: block;">
            <strong><?= htmlspecialchars($kepala_madrasah) ?></strong><br>
            NIP: <?= htmlspecialchars($nip_kepala) ?>
        </td>
        <td>
            <?= htmlspecialchars($tempat_jadwal) ?>, <?= date('d F Y') ?><br>
            Guru Pengampu,<br>
            <img src="<?= $qr_guru ?>" alt="QR TTD Guru" style="width: 60px; height: 60px; margin: 6px auto; display: block;">
            <strong><?= htmlspecialchars($guru_mapel) ?></strong>
        </td>
    </tr>
</table>

<?php if (!$is_download): ?>
    <script>
        function printWhenReady() {
            var imgs = Array.prototype.slice.call(document.images || []);
            var pending = imgs.filter(function(im) { return !im.complete; });
            if (pending.length === 0) { setTimeout(function() { window.print(); }, 350); return; }
            var done = 0;
            function tick() { done++; if (done >= pending.length) setTimeout(function() { window.print(); }, 350); }
            pending.forEach(function(im) {
                im.addEventListener('load', tick, { once: true });
                im.addEventListener('error', tick, { once: true });
            });
            setTimeout(function() { window.print(); }, 3500);
        }
        if (document.readyState === 'complete') printWhenReady();
        else window.addEventListener('load', printWhenReady);
    </script>
<?php endif; ?>

</body>
</html>
<?php
$html_out = ob_get_clean();

if ($is_download) {
    require_once '../vendor/autoload.php';
    while (ob_get_level()) { ob_end_clean(); }
    if (!class_exists('Dompdf\\Dompdf')) {
        die('Library Dompdf tidak tersedia.');
    }
    $dompdf = new Dompdf\Dompdf(['isHtml5ParserEnabled' => true, 'isRemoteEnabled' => true, 'defaultFont' => 'sans-serif']);
    $dompdf->loadHtml($html_out);
    $dompdf->setPaper([0, 0, 935.43, 609.43], 'landscape');
    $dompdf->render();
    $pdf_content = $dompdf->output();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
    header('Content-Length: ' . strlen($pdf_content));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    echo $pdf_content;
    exit;
}

echo $html_out;
exit;
