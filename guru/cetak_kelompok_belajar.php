<?php
/**
 * Export Kelompok Belajar — PDF via window.print() tab baru (stabil + support reload)
 * Juga mendukung mode unduh file PDF (?mode=pdf).
 * Kertas F4/Folio portrait, header KOP standar madrasah, tanda tangan wali kelas & kepala madrasah.
 */
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['guru', 'wali', 'admin', 'kepala_madrasah', 'tata_usaha'])) {
    redirect('../login.php');
}

$user_level      = getUserLevel();
$session_type    = sanitizeInput((string)($_GET['session_type'] ?? ''));
$effective_level = $session_type ?: $user_level;
$is_admin_or_kepala = in_array($effective_level, ['admin', 'kepala_madrasah', 'tata_usaha'], true);

$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

// Mode output: print (tab baru + window.print, stabil & bisa reload) atau pdf (unduh file via Dompdf)
$mode = strtolower(trim((string)($_GET['mode'] ?? '')));
if ($mode === '' && (int)($_GET['download'] ?? 0) === 1) {
    $mode = 'pdf';
}
if (!in_array($mode, ['print', 'pdf'], true)) {
    $mode = 'print';
}
$is_download = ($mode === 'pdf');

// Kelas yang diampu jika bukan admin/kepala
$allowed_kelas_ids = [];
if (!$is_admin_or_kepala) {
    if (function_exists('getTeacherAccessibleClasses')) {
        $allowed_kelas = getTeacherAccessibleClasses($pdo, $guru_id);
        $allowed_kelas_ids = array_map('intval', array_column($allowed_kelas, 'id_kelas'));
    } elseif (function_exists('getGuruTaughtClasses')) {
        $allowed_kelas = getGuruTaughtClasses($pdo, $guru_id);
        $allowed_kelas_ids = array_map('intval', array_column($allowed_kelas, 'id_kelas'));
    }
    try {
        $stWali = $pdo->prepare("SELECT id_kelas FROM tb_kelas WHERE wali_kelas = ? OR wali_kelas = (SELECT nama_guru FROM tb_guru WHERE id_guru = ?)");
        $stWali->execute([$guru_id, $guru_id]);
        foreach ($stWali->fetchAll(PDO::FETCH_COLUMN) as $wkid) {
            $allowed_kelas_ids[] = (int)$wkid;
        }
        $allowed_kelas_ids = array_values(array_unique(array_filter($allowed_kelas_ids)));
    } catch (Throwable $e) {}
}

// Filter
$f_kelas  = (int)($_GET['kelas'] ?? 0);
$f_search = trim(sanitizeInput((string)($_GET['q'] ?? '')));

// Query kelompok
$is_admin_or_kepala_strict = $is_admin || $is_kepala;
if ($is_admin_or_kepala_strict && !$f_kelas) {
    $kelompok_list = [];
} else {
    $sql = "
        SELECT 
            kb.id_kelompok,
            kb.nama_kelompok,
            kb.id_kelas,
            kb.id_ketua,
            kb.keterangan,
            kb.created_at,
            k.nama_kelas,
            k.wali_kelas,
            sk.nama_siswa AS nama_ketua,
            sk.nisn AS nisn_ketua,
            sk.foto AS foto_ketua
        FROM tb_kelompok_belajar kb
        LEFT JOIN tb_kelas k ON kb.id_kelas = k.id_kelas
        LEFT JOIN tb_siswa sk ON kb.id_ketua = sk.id_siswa
        WHERE 1=1
    ";
    $params = [];

    if (!$is_admin_or_kepala_strict) {
        if (!empty($allowed_kelas_ids)) {
            $ph = implode(',', array_fill(0, count($allowed_kelas_ids), '?'));
            $sql .= " AND (kb.id_kelas IN ($ph) OR kb.id_guru = ?)";
            $params = array_merge($params, $allowed_kelas_ids, [$guru_id]);
        } else {
            $sql .= " AND kb.id_guru = ?";
            $params[] = $guru_id;
        }
    }

    if ($f_kelas) {
        $sql .= " AND kb.id_kelas = ?";
        $params[] = $f_kelas;
    }
    if ($f_search !== '') {
        $sql .= " AND (kb.nama_kelompok LIKE ? OR sk.nama_siswa LIKE ?)";
        $params[] = "%$f_search%";
        $params[] = "%$f_search%";
    }
    $sql .= " ORDER BY k.nama_kelas ASC, kb.nama_kelompok ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $kelompok_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Ambil anggota
$anggota_map = [];
if (!empty($kelompok_list)) {
    $ids = array_column($kelompok_list, 'id_kelompok');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stA = $pdo->prepare("
        SELECT 
            akb.id_kelompok,
            s.id_siswa,
            s.nama_siswa,
            s.nisn,
            s.jenis_kelamin
        FROM tb_anggota_kelompok_belajar akb
        JOIN tb_siswa s ON akb.id_siswa = s.id_siswa
        WHERE akb.id_kelompok IN ($placeholders)
        ORDER BY s.nama_siswa ASC
    ");
    $stA->execute($ids);
    foreach ($stA->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $anggota_map[$row['id_kelompok']][] = $row;
    }
}

// Profil madrasah
$school = getSchoolProfile($pdo);
$nama_yayasan    = $school['nama_yayasan'] ?? '';
$nama_madrasah   = $school['nama_madrasah'] ?? 'MADRASAH IBTIDAIYAH';
$alamat_madrasah = $school['alamat'] ?? '';
$telepon         = $school['telepon'] ?? '';
$tahun_ajaran    = $school['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1);
$semester        = $school['semester'] ?? 'Semester 1';
$tempat_jadwal   = $school['tempat_jadwal'] ?? $school['kota'] ?? 'Jepara';
$kepala_madrasah = $school['kepala_madrasah'] ?? '-';
$nip_kepala      = $school['nip_kepala'] ?? '-';
$logo_file       = trim($school['logo'] ?? '');

// Nama kelas terpilih
$nama_kelas_filter = '';
$wali_kelas_nama   = '';
if ($f_kelas > 0) {
    try {
        $stK = $pdo->prepare("SELECT nama_kelas, wali_kelas FROM tb_kelas WHERE id_kelas = ? LIMIT 1");
        $stK->execute([$f_kelas]);
        $kinfo = $stK->fetch(PDO::FETCH_ASSOC);
        if ($kinfo) {
            $nama_kelas_filter = (string)$kinfo['nama_kelas'];
            $wali_kelas_nama   = (string)($kinfo['wali_kelas'] ?? '');
        }
    } catch (Throwable $e) {}
} elseif (!empty($kelompok_list)) {
    $kelas_names = array_values(array_unique(array_filter(array_column($kelompok_list, 'nama_kelas'))));
    if (count($kelas_names) === 1) {
        $nama_kelas_filter = $kelas_names[0];
        $wali_kelas_nama   = (string)($kelompok_list[0]['wali_kelas'] ?? '');
    }
}

// Jika guru login wali kelas, isi nama penanda tangan
if ($wali_kelas_nama === '' && $guru_id > 0) {
    try {
        $stG = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
        $stG->execute([$guru_id]);
        $wali_kelas_nama = (string)($stG->fetchColumn() ?: '');
    } catch (Throwable $e) {}
}

$judul_dokumen = 'DAFTAR KELOMPOK BELAJAR SISWA' . ($nama_kelas_filter !== '' ? (' KELAS ' . strtoupper($nama_kelas_filter)) : '');
$filename_safe = 'Kelompok_Belajar_' . ($nama_kelas_filter ? ('Kelas_' . preg_replace('/[^A-Za-z0-9_-]/', '', $nama_kelas_filter) . '_') : '') . date('Ymd_His');

// Helper base64 image untuk mode PDF
function kb_img_b64(string $src): string {
    $src = trim($src);
    if ($src === '') return '';
    if (strpos($src, 'data:image') === 0) return $src;
    $fs = (strpos($src, __DIR__) === 0) ? $src : __DIR__ . '/' . ltrim($src, '/');
    if (!is_file($fs)) return '';
    $info = @getimagesize($fs);
    $mime = $info['mime'] ?? 'image/png';
    $data = @file_get_contents($fs);
    if (!is_string($data) || $data === '') return '';
    return 'data:' . $mime . ';base64,' . base64_encode($data);
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
    $b64 = kb_img_b64($logo_print);
    if ($b64 !== '') $logo_src = $b64;
}

$tgl_cetak = function_exists('formatDateIndonesia') ? formatDateIndonesia(date('Y-m-d')) : date('d F Y');

// QR Tanda Tangan
$qr_wali_url = 'https://api.qrserver.com/v1/create-qr-code/?size=90x90&data=' . urlencode("Wali Kelas: {$wali_kelas_nama} - Kelompok Belajar - {$nama_madrasah}");
$qr_kepala_url = 'https://api.qrserver.com/v1/create-qr-code/?size=90x90&data=' . urlencode("Kepala Madrasah: {$kepala_madrasah} - {$nama_madrasah}");
$qr_wali = $qr_wali_url;
$qr_kepala = $qr_kepala_url;
if ($is_download) {
    $b64w = kb_img_b64($qr_wali_url);
    $b64k = kb_img_b64($qr_kepala_url);
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
        @page {
            size: 215mm 330mm; /* F4 / Folio portrait */
            margin: 12mm 15mm;
        }
        * { box-sizing: border-box; }
        body {
            font-family: Arial, "Helvetica Neue", Helvetica, sans-serif;
            font-size: 10pt;
            color: #111;
            line-height: 1.35;
            background: #fff;
            margin: 0;
            padding: 10px;
        }
        /* ---- HEADER KOP STANDAR SIMAD ---- */
        .header-kop {
            border-bottom: 2.5px solid #000;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .header-kop table { width: 100%; border: none; margin: 0; table-layout: fixed; }
        .header-kop td { overflow: visible; border: none; vertical-align: middle; }
        .kop-logo-img { width: 75px; height: 75px; object-fit: contain; display: block; margin: 0 auto; }
        .header-kop .kop-yayasan { margin: 0; font-size: 10.5pt; font-weight: bold; letter-spacing: 0.4px; text-transform: uppercase; }
        .header-kop h2 { margin: 1px 0; font-size: 14pt; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
        .header-kop p { margin: 2px 0 0; font-size: 8.5pt; color: #333; line-height: 1.4; }

        .doc-title { text-align: center; margin: 12px 0 14px; }
        .doc-title h4 { margin: 0; font-size: 11pt; font-weight: 800; text-decoration: underline; text-transform: uppercase; line-height: 1.35; }
        .doc-title small { font-size: 9pt; color: #444; }

        .meta-info { width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 9.5pt; }
        .meta-info td { padding: 2px 4px; border: none; }
        .meta-info .lbl { width: 120px; font-weight: bold; }

        /* ---- TABEL KELOMPOK STANDAR (SOLID & PRESISI) ---- */
        .kelompok-wrap {
            margin-bottom: 16px;
            page-break-inside: avoid;
        }
        .table-kelompok {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
            margin: 0;
        }
        .table-kelompok th,
        .table-kelompok td {
            border: 1px solid #000;
            padding: 5px 8px;
            vertical-align: middle;
            color: #000;
        }
        .table-kelompok .row-header-kelompok {
            background: #fff;
            font-size: 9.5pt;
        }
        .table-kelompok .row-desc-kelompok {
            background: #fff;
            font-size: 8.5pt;
            font-style: italic;
        }
        .table-kelompok th.th-col {
            background: #fff;
            font-weight: 700;
            text-align: center;
            font-size: 8.5pt;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .table-kelompok td.center { text-align: center; }

        .badge-role {
            display: inline-block;
            font-size: 8.5pt;
            color: #000;
        }
        .badge-ketua { font-weight: 800; text-decoration: underline; }
        .badge-anggota { font-weight: normal; }

        /* ---- TANDA TANGAN ---- */
        .signature-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 24px;
            page-break-inside: avoid;
            font-size: 9.5pt;
        }
        .signature-table td {
            border: none;
            text-align: center;
            vertical-align: top;
            width: 50%;
            padding: 0 10px;
        }
        .qr-ttd {
            width: 75px;
            height: 75px;
            margin: 6px auto;
            display: block;
        }
        .ttd-space { height: 60px; }

        /* ---- NO PRINT ---- */
        .no-print {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            padding: 10px 14px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 6px;
        }
        @media print {
            .no-print { display: none !important; }
            body { padding: 0; }
            .kelompok-wrap { page-break-inside: avoid; }
            .signature-table { page-break-inside: avoid; }
        }
    </style>
</head>
<body>

<?php if (!$is_download): ?>
    <div class="no-print">
        <div>
            <strong>Pratinjau Cetak Kelompok Belajar</strong> &bull;
            <span style="color:#64748b;"><?= htmlspecialchars($filename_safe) ?></span>
        </div>
        <div>
            <button onclick="window.print()" style="background:#2563eb;color:#fff;border:none;padding:7px 16px;border-radius:4px;font-weight:bold;cursor:pointer;">
                &#128424; Cetak Halaman
            </button>
            <?php
            $qs_pdf = $_GET;
            unset($qs_pdf['download']);
            $qs_pdf['mode'] = 'pdf';
            ?>
            <a href="cetak_kelompok_belajar.php?<?= http_build_query($qs_pdf) ?>" style="background:#dc2626;color:#fff;text-decoration:none;padding:7px 14px;border-radius:4px;font-weight:bold;margin-left:6px;">
                Unduh PDF
            </a>
            <button onclick="window.close()" style="background:#64748b;color:#fff;border:none;padding:7px 12px;border-radius:4px;margin-left:6px;cursor:pointer;">
                Tutup
            </button>
        </div>
    </div>
<?php endif; ?>

<!-- KOP MADRASAH -->
<div class="header-kop">
    <table style="width: 100%; border: none; margin: 0;">
        <tr style="border: none;">
            <td style="border: none; width: 95px; min-width: 95px; text-align: center; vertical-align: middle; padding: 0 5px;">
                <?php if ($logo_src !== ''): ?>
                    <img src="<?= $logo_src ?>" class="kop-logo-img" alt="Logo">
                <?php endif; ?>
            </td>
            <td style="border: none; text-align: center; vertical-align: middle;">
                <?php if ($nama_yayasan): ?>
                    <div class="kop-yayasan"><?= htmlspecialchars(strtoupper($nama_yayasan)) ?></div>
                <?php endif; ?>
                <h2><?= htmlspecialchars(strtoupper($nama_madrasah)) ?></h2>
                <p>
                    <?= htmlspecialchars($alamat_madrasah) ?>
                    <?php if ($telepon): ?> &bull; Telp: <?= htmlspecialchars($telepon) ?><?php endif; ?>
                </p>
            </td>
            <td style="border: none; width: 95px; min-width: 95px;"></td>
        </tr>
    </table>
</div>

<!-- JUDUL DOKUMEN -->
<div class="doc-title">
    <h4><?= htmlspecialchars($judul_dokumen) ?></h4>
    <small>Tahun Ajaran <?= htmlspecialchars($tahun_ajaran) ?></small>
</div>

<?php if ($nama_kelas_filter !== '' || $wali_kelas_nama !== ''): ?>
<table class="meta-info">
    <tr>
        <?php if ($nama_kelas_filter !== ''): ?>
            <td class="lbl">Kelas</td>
            <td style="width:8px;">:</td>
            <td>Kelas <?= htmlspecialchars($nama_kelas_filter) ?></td>
        <?php endif; ?>
        <?php if ($wali_kelas_nama !== ''): ?>
            <td class="lbl" style="text-align:right;">Wali Kelas :</td>
            <td style="width:auto;padding-left:6px;"><strong><?= htmlspecialchars($wali_kelas_nama) ?></strong></td>
        <?php endif; ?>
    </tr>
</table>
<?php endif; ?>

<!-- DAFTAR KELOMPOK -->
<?php if (empty($kelompok_list)): ?>
    <div style="text-align:center;padding:40px;color:#64748b;border:1px dashed #cbd5e1;border-radius:8px;">
        <em>Tidak ada data kelompok belajar yang sesuai dengan filter.</em>
    </div>
<?php else: ?>
    <?php foreach ($kelompok_list as $idx => $k): ?>
        <?php
        $kid = (int)$k['id_kelompok'];
        $anggota = $anggota_map[$kid] ?? [];
        $id_ketua = (int)($k['id_ketua'] ?? 0);
        if (!empty($anggota) && $id_ketua > 0) {
            usort($anggota, static function($a, $b) use ($id_ketua) {
                $a_is = ((int)$a['id_siswa'] === $id_ketua) ? 0 : 1;
                $b_is = ((int)$b['id_siswa'] === $id_ketua) ? 0 : 1;
                if ($a_is !== $b_is) return $a_is <=> $b_is;
                return strcasecmp($a['nama_siswa'], $b['nama_siswa']);
            });
        }
        ?>
        <div class="kelompok-wrap">
            <table class="table-kelompok">
                <thead>
                    <tr class="row-header-kelompok">
                        <td colspan="5">
                            <div style="display:flex;justify-content:space-between;align-items:center;">
                                <span>
                                    <strong><?= ($idx + 1) . '. ' . htmlspecialchars($k['nama_kelompok']) ?></strong>
                                    <?php if (!empty($k['nama_kelas'])): ?>
                                        <span style="margin-left:6px;font-weight:normal;">(Kelas <?= htmlspecialchars($k['nama_kelas']) ?>)</span>
                                    <?php endif; ?>
                                </span>
                                <span style="font-size:9pt;">Total Anggota: <strong><?= count($anggota) ?></strong> Siswa</span>
                            </div>
                        </td>
                    </tr>
                    <?php if (!empty($k['keterangan'])): ?>
                        <tr class="row-desc-kelompok">
                            <td colspan="5">Catatan: <?= htmlspecialchars($k['keterangan']) ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th class="th-col" style="width:6%;">No</th>
                        <th class="th-col" style="width:18%;">NISN</th>
                        <th class="th-col">Nama Siswa</th>
                        <th class="th-col" style="width:12%;">L/P</th>
                        <th class="th-col" style="width:16%;">Peran</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($anggota)): ?>
                        <tr>
                            <td colspan="5" class="center" style="color:#666;padding:10px;">Belum ada anggota di kelompok ini.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($anggota as $no_a => $a): ?>
                            <?php $is_ketua = ($id_ketua > 0 && (int)$a['id_siswa'] === $id_ketua); ?>
                            <tr>
                                <td class="center"><?= $no_a + 1 ?></td>
                                <td class="center"><?= htmlspecialchars($a['nisn'] ?: '-') ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($a['nama_siswa']) ?></strong>
                                </td>
                                <td class="center"><?= htmlspecialchars($a['jenis_kelamin'] ?: '-') ?></td>
                                <td class="center">
                                    <?php if ($is_ketua): ?>
                                        <span class="badge-role badge-ketua">Ketua</span>
                                    <?php else: ?>
                                        <span class="badge-role badge-anggota">Anggota</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- TANDA TANGAN -->
<table class="signature-table">
    <tr>
        <td>
            Mengetahui,<br>
            Kepala <?= htmlspecialchars($nama_madrasah) ?><br>
            <?php if (!empty($qr_kepala)): ?>
                <img src="<?= $qr_kepala ?>" class="qr-ttd" alt="QR Kepala">
            <?php else: ?>
                <div class="ttd-space"></div>
            <?php endif; ?>
            <strong><u><?= htmlspecialchars($kepala_madrasah ?: '.........................') ?></u></strong><br>
            <span style="font-size:8.5pt;">NIP. <?= htmlspecialchars($nip_kepala ?: '-') ?></span>
        </td>
        <td>
            <?= htmlspecialchars($tempat_jadwal) ?>, <?= htmlspecialchars($tgl_cetak) ?><br>
            Wali Kelas <?= htmlspecialchars($nama_kelas_filter ?: '') ?><br>
            <?php if (!empty($qr_wali) && $wali_kelas_nama !== ''): ?>
                <img src="<?= $qr_wali ?>" class="qr-ttd" alt="QR Wali">
            <?php else: ?>
                <div class="ttd-space"></div>
            <?php endif; ?>
            <strong><u><?= htmlspecialchars($wali_kelas_nama ?: '.........................') ?></u></strong>
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
    $dompdf->setPaper([0, 0, 609.45, 935.43], 'portrait'); // F4 portrait
    $dompdf->render();
    $pdf_content = $dompdf->output();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename_safe . '.pdf"');
    header('Content-Length: ' . strlen($pdf_content));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    echo $pdf_content;
    exit;
}

echo $html_out;
exit;
