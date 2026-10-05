<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['wali', 'admin'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$f_kelas = (int)($_GET['kelas'] ?? 0);
// Mode output: print (window.print tab baru, stabil + support reload) atau pdf (unduh file via Dompdf).
$mode = strtolower(trim((string)($_GET['mode'] ?? '')));
if ($mode === '' && (int)($_GET['download'] ?? 0) === 1) {
    $mode = 'pdf';
}
if (!in_array($mode, ['print', 'pdf'], true)) {
    $mode = 'print';
}
$is_download = ($mode === 'pdf');

// Profil Madrasah
$school = getSchoolProfile($pdo);
$nama_madrasah = $school['nama_madrasah'] ?? 'Madrasah Ibtidaiyah';
$alamat_madrasah = $school['alamat'] ?? '';
$tahun_ajaran = $school['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1);
$semester = $school['semester'] ?? 'Semester 1';
$tempat_jadwal = $school['tempat_jadwal'] ?? 'Jepara';
$kepala_madrasah = $school['kepala_madrasah'] ?? '-';
$nip_kepala = $school['nip_kepala'] ?? '-';

// Wali + nama kelas dari data kelas (bukan nama login)
$nama_wali = 'Wali Kelas';
$nama_kelas = '-';
if ($f_kelas > 0) {
    $stK = $pdo->prepare("SELECT nama_kelas, wali_kelas FROM tb_kelas WHERE id_kelas = ?");
    $stK->execute([$f_kelas]);
    if ($rk = $stK->fetch(PDO::FETCH_ASSOC)) {
        $nama_kelas = $rk['nama_kelas'] ?: '-';
        $nama_wali = trim((string)($rk['wali_kelas'] ?? ''));
        if ($nama_wali === '') $nama_wali = 'Wali Kelas';
    }
} else {
    $stG = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
    $stG->execute([$guru_id]);
    $nama_wali = $stG->fetchColumn() ?: ($_SESSION['nama_guru'] ?? 'Wali Kelas');
}

// Urutan hari dari pengaturan sekolah
$days_order = function_exists('getUrutanHariJadwalSekolah') ? getUrutanHariJadwalSekolah($pdo) : ['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];

// Query Piket
$where = ["1=1"];
$params = [];
if ($f_kelas > 0) {
    $where[] = "p.id_kelas = ?";
    $params[] = $f_kelas;
}
if ($user_level !== 'admin') {
    $where[] = "p.id_wali = ?";
    $params[] = $guru_id;
}
$where_sql = implode(' AND ', $where);
$field_hari = implode(',', array_map([$pdo, 'quote'], $days_order));
$stmt = $pdo->prepare("
    SELECT p.*, s.nama_siswa, s.nisn, k.nama_kelas, g.nama_guru
    FROM tb_jadwal_piket_kelas p
    JOIN tb_siswa s ON s.id_siswa = p.id_siswa
    LEFT JOIN tb_kelas k ON k.id_kelas = p.id_kelas
    LEFT JOIN tb_guru g ON g.id_guru = p.id_wali
    WHERE $where_sql
    ORDER BY FIELD(p.hari, $field_hari), p.urutan ASC, s.nama_siswa ASC
");
$stmt->execute($params);
$piket_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($piket_list)) {
    echo "<script>alert('Tidak ada data piket untuk dicetak.'); window.history.back();</script>";
    exit;
}

// Kelompokkan per hari (hanya Aktif untuk kartu, semua untuk tabel)
$piket_by_day = [];
foreach ($days_order as $d) { $piket_by_day[$d] = []; }
foreach ($piket_list as $r) {
    if (($r['status'] ?? '') === 'Aktif') {
        $h = (string)($r['hari'] ?? '');
        if (!isset($piket_by_day[$h])) $piket_by_day[$h] = [];
        $piket_by_day[$h][] = $r;
    }
}

$ta_file = preg_replace('/[^A-Za-z0-9-]+/', '', str_replace('/', '-', $tahun_ajaran));
$kelas_safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$nama_kelas);
$judul_dokumen = "JADWAL PIKET KELAS " . strtoupper((string)$nama_kelas) . " - TAHUN AJARAN " . $tahun_ajaran;
$filename = "Jadwal_Piket_Kelas_" . $kelas_safe . "_TA" . $ta_file . "_" . date('Ymd');

$logo_file = $school['logo'] ?? '';
if (!function_exists('piket_img_b64')) {
    function piket_img_b64(string $src): string {
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
    $b64 = piket_img_b64($fs);
    if ($b64 !== '') $logo_src = $b64;
}

$qr_wali_url = 'https://api.qrserver.com/v1/create-qr-code/?size=90x90&data=' . urlencode("Tanda Tangan Wali Kelas: {$nama_wali} - Jadwal Piket Kelas {$nama_kelas} - {$nama_madrasah}");
$qr_kepala_url = 'https://api.qrserver.com/v1/create-qr-code/?size=90x90&data=' . urlencode("Tanda Tangan Kepala Madrasah: {$kepala_madrasah} - {$nama_madrasah}");
$qr_wali = $qr_wali_url;
$qr_kepala = $qr_kepala_url;
if ($is_download) {
    $b64w = piket_img_b64($qr_wali_url);
    $b64k = piket_img_b64($qr_kepala_url);
    if (strpos($b64w, 'data:image') === 0) $qr_wali = $b64w;
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
        @page { size: 215mm 330mm; margin: 12mm 12mm; }
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
        .info-table .lbl { width: 130px; font-weight: bold; color: #222; }
        .day-title { margin: 14px 0 8px; font-size: 10.5pt; border-bottom: 1.5px solid #1d4ed8; color: #1e40af; padding-bottom: 3px; font-weight: 800; text-transform: uppercase; }
        .table-data { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 9pt; table-layout: fixed; }
        .table-data th, .table-data td { border: 1px solid #333; padding: 6px 8px; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; }
        .table-data th { background-color: #f1f5f9; font-weight: bold; text-align: center; font-size: 9pt; }
        .signature-table { width: 100%; border-collapse: collapse; margin-top: 30px; page-break-inside: avoid; font-size: 9.5pt; }
        .signature-table td { text-align: center; vertical-align: top; width: 50%; }
        .no-print { background: #f8fafc; border: 1px solid #cbd5e1; padding: 10px 14px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; border-radius: 6px; }
        @media print { .no-print { display: none !important; } body { padding: 0; } .day-block { page-break-inside: avoid; } }
    </style>
</head>
<body>

<?php if (!$is_download): ?>
    <div class="no-print">
        <div><strong>Pratinjau Cetak Jadwal Piket</strong> &bull; <span class="text-muted"><?= htmlspecialchars($filename) ?></span></div>
        <div>
            <button onclick="window.print()" style="background:#2563eb;color:#fff;border:none;padding:7px 16px;border-radius:4px;font-weight:bold;cursor:pointer;">Cetak Sekarang</button>
            <?php
            $qs_pdf = $_GET;
            unset($qs_pdf['download']);
            $qs_pdf['mode'] = 'pdf';
            ?>
            <a href="export_piket_pdf.php?<?= http_build_query($qs_pdf) ?>" style="background:#dc2626;color:#fff;text-decoration:none;padding:7px 14px;border-radius:4px;font-weight:bold;margin-left:6px;">Unduh File PDF</a>
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
                <h3>JADWAL PIKET KELAS</h3>
                <p><?= htmlspecialchars($alamat_madrasah) ?></p>
            </td>
            <td style="border: none; width: 110px; min-width: 110px;"></td>
        </tr>
    </table>
</div>

<div class="doc-title">
    <h4>JADWAL PIKET KELAS <?= htmlspecialchars(strtoupper((string)$nama_kelas)) ?></h4>
    <small>Tahun Ajaran <?= htmlspecialchars($tahun_ajaran) ?> (<?= htmlspecialchars($semester) ?>) &bull; Tanggal Cetak: <?= date('d F Y') ?></small>
</div>

<?php foreach ($days_order as $hari): ?>
    <?php $petugas = $piket_by_day[$hari] ?? []; ?>
    <div class="day-block">
        <h5 class="day-title"><?= htmlspecialchars(strtoupper($hari)) ?> &mdash; <?= count($petugas) ?> SISWA</h5>
        <?php if (empty($petugas)): ?>
            <p style="color:#64748b;">Belum ada petugas piket.</p>
        <?php else: ?>
        <table class="table-data">
            <colgroup><col style="width:8%;"><col style="width:62%;"><col style="width:30%;"></colgroup>
            <thead>
                <tr><th>No</th><th>Nama Siswa</th><th>NISN</th></tr>
            </thead>
            <tbody>
                <?php $no = 1; foreach ($petugas as $p): ?>
                    <tr>
                        <td style="text-align:center;"><?= $no++ ?></td>
                        <td><strong><?= htmlspecialchars($p['nama_siswa']) ?></strong></td>
                        <td style="text-align:center;"><?= htmlspecialchars($p['nisn'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<!-- TANDA TANGAN -->
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
            Wali Kelas,<br>
            <img src="<?= $qr_wali ?>" alt="QR TTD Wali" style="width: 60px; height: 60px; margin: 6px auto; display: block;">
            <strong><?= htmlspecialchars($nama_wali) ?></strong>
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
    $dompdf->setPaper([0, 0, 609.43, 935.43], 'portrait');
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
