<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['wali', 'guru', 'admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$is_admin_or_kepala = in_array($user_level, ['admin', 'kepala_madrasah'], true) || in_array($_GET['session_type'] ?? '', ['admin', 'kepala_madrasah'], true);
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$id_projek = (int)($_GET['id'] ?? 0);
$f_kelas = (int)($_GET['kelas'] ?? 0);

// Mode: print (pratinjau + window.print) atau pdf (unduh via Dompdf)
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
$nama_yayasan = trim((string)($school['nama_yayasan'] ?? ''));
$nama_yayasan_upper = $nama_yayasan !== '' ? (function_exists('mb_strtoupper') ? mb_strtoupper($nama_yayasan, 'UTF-8') : strtoupper($nama_yayasan)) : '';
$nama_madrasah = $school['nama_madrasah'] ?? 'Madrasah Ibtidaiyah';
$alamat_madrasah = $school['alamat'] ?? '';
$tahun_ajaran = $school['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1);
$semester = $school['semester'] ?? 'Semester 1';
$tempat_jadwal = $school['tempat_jadwal'] ?? 'Jepara';
$kepala_madrasah = $school['kepala_madrasah'] ?? '-';
$nip_kepala = $school['nip_kepala'] ?? '-';
$logo_file = $school['logo'] ?? '';

// Profil Guru/Wali
$stG = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
$stG->execute([$guru_id]);
$nama_wali = $stG->fetchColumn() ?: ($_SESSION['nama_guru'] ?? 'Wali Kelas');

// Query Projek
$where = ["1=1"];
$params = [];
if ($id_projek > 0) {
    $where[] = "p.id = ?";
    $params[] = $id_projek;
} else {
    if ($f_kelas > 0) {
        $where[] = "p.id_kelas = ?";
        $params[] = $f_kelas;
    }
    if (!$is_admin_or_kepala) {
        $where[] = "p.id_wali = ?";
        $params[] = $guru_id;
    }
}
$where_sql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT p.*, c.nama_kelas
    FROM tb_projek_kokurikuler p
    LEFT JOIN tb_kelas c ON c.id_kelas = p.id_kelas
    WHERE $where_sql
    ORDER BY c.nama_kelas ASC, p.nama_projek ASC, p.id DESC
");
$stmt->execute($params);
$projek_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($projek_list)) {
    echo "<script>alert('Tidak ada data projek untuk diekspor.'); window.history.back();</script>";
    exit;
}

// Anggota per projek
$pids = array_column($projek_list, 'id');
$in = implode(',', array_fill(0, count($pids), '?'));
$stA = $pdo->prepare("
    SELECT pa.id_projek, pa.kelompok, pa.peran, pa.catatan, s.nama_siswa, s.nisn
    FROM tb_projek_anggota pa
    LEFT JOIN tb_siswa s ON s.id_siswa = pa.id_siswa
    WHERE pa.id_projek IN ($in) AND pa.status = 'Aktif'
    ORDER BY FIELD(pa.peran, 'Ketua','Sekretaris','Anggota','Presentator','Lainnya'), s.nama_siswa ASC
");
$stA->execute($pids);
$anggota_by_projek = [];
foreach ($stA->fetchAll(PDO::FETCH_ASSOC) as $a) {
    $anggota_by_projek[(int)$a['id_projek']][] = $a;
}

// Kelompokkan anggota per kelompok (tidak dicampur)
if (!function_exists('projek_group_anggota')) {
    function projek_group_anggota(array $anggota): array {
        $groups = [];
        foreach ($anggota as $a) {
            $k = trim((string)($a['kelompok'] ?? ''));
            if ($k === '') $k = 'Tanpa Kelompok';
            $groups[$k][] = $a;
        }
        uksort($groups, static function ($x, $y) {
            if ($x === 'Tanpa Kelompok') return 1;
            if ($y === 'Tanpa Kelompok') return -1;
            return strnatcasecmp($x, $y);
        });
        return $groups;
    }
}

$is_single = (count($projek_list) === 1) && $id_projek > 0;

$nama_madrasah_upper = function_exists('mb_strtoupper') ? mb_strtoupper($nama_madrasah, 'UTF-8') : strtoupper($nama_madrasah);

// Judul & nama file: "nama projek_kelas_semester_tahun ajaran"
$p_first = $projek_list[0];
if ($is_single) {
    $kelas_label = !empty($p_first['nama_kelas']) ? $p_first['nama_kelas'] : 'Semua Kelas';
    $nama_dokumen = $p_first['nama_projek'] . '_' . $kelas_label . '_' . $semester . '_' . $tahun_ajaran;
} else {
    $names = array_values(array_unique(array_filter(array_column($projek_list, 'nama_kelas'))));
    $kelas_label = (count($names) === 1) ? $names[0] : 'Semua Kelas';
    $nama_dokumen = 'Rekapitulasi Projek Kokurikuler_' . $kelas_label . '_' . $semester . '_' . $tahun_ajaran;
}
$judul_dokumen = $nama_dokumen;
// Judul yang ditampilkan (tanpa "_kelas_semester_TA")
$judul_tampil = $is_single ? $p_first['nama_projek'] : 'REKAPITULASI PROJEK KOKURIKULER';
$filename = trim(preg_replace('/\s+/', '_', preg_replace('/[\\\\\/:*?"<>|]+/', '-', $nama_dokumen)));

// Konversi gambar ke base64 (untuk Dompdf) / URL langsung (untuk print)
if (!function_exists('projek_img_b64')) {
    function projek_img_b64(string $src): string {
        $src = trim($src);
        if ($src === '') return '';
        if (strpos($src, 'data:image') === 0) return $src;
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
                if ($code !== 200 || !is_string($data) || strlen($data) < 200) return $src;
            } else {
                $ctx = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => 'SIMAD-Madrasah/1.0']]);
                $data = @file_get_contents($src, false, $ctx);
                if (!is_string($data) || strlen($data) < 200) return $src;
            }
            return 'data:image/png;base64,' . base64_encode($data);
        }
        $fs = (strpos($src, __DIR__) === 0) ? $src : __DIR__ . '/' . ltrim($src, '/');
        if (!is_file($fs)) return '';
        $info = @getimagesize($fs);
        $mime = $info['mime'] ?? 'image/png';
        $data = @file_get_contents($fs);
        if (!is_string($data) || $data === '') return '';
        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }
}

$logo_print = '';
if (!empty($logo_file)) {
    foreach (['../assets/img/' . $logo_file, '../uploads/' . $logo_file] as $lp) {
        if (is_file(__DIR__ . '/' . $lp)) { $logo_print = $lp; break; }
    }
}
$logo_path = $logo_print;
if ($is_download && $logo_print !== '') {
    $b64 = projek_img_b64(__DIR__ . '/' . ltrim($logo_print, '/'));
    if ($b64 !== '') $logo_path = $b64;
}

// QR tanda tangan (nama) per projek
$qr_wali_url = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode("Tanda Tangan Wali Kelas: {$nama_wali} - Projek Kokurikuler - {$nama_madrasah}");
$qr_kepala_url = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode("Tanda Tangan Kepala Madrasah: {$kepala_madrasah} - Projek Kokurikuler - {$nama_madrasah}");
$qr_wali = $qr_wali_url;
$qr_kepala = $qr_kepala_url;
if ($is_download) {
    $b64w = projek_img_b64($qr_wali_url);
    $b64k = projek_img_b64($qr_kepala_url);
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
        @page { size: <?= $is_single ? '215mm 330mm' : '330mm 215mm' ?>; margin: 14mm 15mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 10pt; margin: 0; }
        .no-print {
            display: flex; justify-content: space-between; align-items: center; gap: 10px;
            background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px;
            padding: 8px 12px; margin-bottom: 12px; font-size: 9pt;
        }
        .header-kop { border-bottom: 2.5px solid #111; padding-bottom: 6px; margin-bottom: 10px; }
        .header-kop .kop-yayasan { margin: 0; font-size: 10.5pt; font-weight: bold; letter-spacing: 0.4px; }
        .header-kop h2 { margin: 0; font-size: 15pt; letter-spacing: 0.5px; text-transform: uppercase; }
        .header-kop h3 { margin: 2px 0; font-size: 11pt; font-weight: normal; }
        .header-kop p { margin: 2px 0 0; font-size: 9pt; }
        .kop-logo-img { width: 78px; height: 78px; object-fit: contain; }
        .doc-title { text-align: center; margin: 12px 0 14px; }
        .doc-kicker { font-size: 11pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; color: #1e3a8a; }
        .doc-title h4 { margin: 2px 0 0; font-size: 13pt; text-transform: uppercase; }
        .doc-title .sub { margin-top: 3px; font-size: 10pt; font-weight: bold; }
        .doc-title small { display: block; margin-top: 2px; color: #444; font-size: 8.5pt; }
        .projek-entry { margin-bottom: 18px; page-break-inside: avoid; }
        .projek-head {
            background: #1e3a8a; color: #fff; padding: 6px 10px; border-radius: 4px 4px 0 0;
            display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap;
        }
        .projek-head .nm { font-weight: bold; font-size: 10.5pt; }
        table.projek-identitas { width: 100%; table-layout: fixed; border-collapse: collapse; margin-top: 6px; }
        table.projek-identitas td {
            border: 1px solid #cbd5e1; padding: 5px 8px; font-size: 10.5pt; vertical-align: top;
            word-wrap: break-word; overflow-wrap: anywhere;
        }
        table.projek-identitas td.lbl { width: 125px; background: #f1f5f9; font-weight: bold; white-space: nowrap; }
        .kelompok-title {
            margin: 10px 0 4px; font-weight: bold; font-size: 10pt;
            background: #e2e8f0; border-left: 4px solid #1e3a8a; padding: 4px 8px;
        }
        table.table-data { width: 100%; table-layout: fixed; border-collapse: collapse; margin-top: 4px; }
        table.table-data th, table.table-data td {
            border: 1px solid #94a3b8; padding: 5px 7px; font-size: 10.5pt; vertical-align: top;
            word-wrap: break-word; overflow-wrap: anywhere;
        }
        table.table-data thead th { background: #e2e8f0; text-align: center; }
        table.table-data td.c { text-align: center; }
        table.table-rekap th, table.table-rekap td { font-size: 11pt; padding: 5px 6px; vertical-align: middle; }
        .empty-row { font-style: italic; color: #64748b; text-align: center; }
        .signature-block { margin-top: 26px; page-break-inside: avoid; break-inside: avoid; }
        .signature-block, .signature-block table, .signature-block tr, .signature-block td { page-break-inside: avoid; break-inside: avoid; }
        .signature-table { width: 100%; }
        .signature-table td { width: 50%; text-align: center; vertical-align: top; font-size: 10.5pt; padding-top: 6px; }
        .signature-table .qr-ttd { width: 70px; height: 70px; margin: 4px auto; display: block; }
        .signature-table strong { display: inline-block; margin-top: 2px; border-bottom: 1px solid #111; padding: 0 6px; }
        @media print { .no-print { display: none !important; } body { margin: 0; } }
    </style>
</head>
<body>

<?php if (!$is_download): ?>
    <div class="no-print">
        <div><strong>Pratinjau Cetak Projek Kokurikuler</strong> &bull; <span><?= htmlspecialchars($filename) ?></span></div>
        <div>
            <button onclick="window.print()" style="background:#2563eb;color:#fff;border:none;padding:6px 14px;border-radius:4px;font-weight:bold;cursor:pointer;">Cetak Sekarang</button>
            <?php $qs_pdf = $_GET; unset($qs_pdf['download']); $qs_pdf['mode'] = 'pdf'; ?>
            <a href="export_projek_pdf.php?<?= http_build_query($qs_pdf) ?>" style="background:#dc2626;color:#fff;text-decoration:none;padding:6px 12px;border-radius:4px;font-weight:bold;margin-left:6px;">Unduh File PDF</a>
            <button onclick="window.close()" style="background:#64748b;color:#fff;border:none;padding:6px 12px;border-radius:4px;margin-left:6px;cursor:pointer;">Tutup</button>
        </div>
    </div>
<?php endif; ?>

<!-- KOP MADRASAH -->
<div class="header-kop">
    <table style="width: 100%; border: none; margin: 0;">
        <tr style="border: none;">
            <td style="border: none; width: 96px; min-width: 96px; text-align: center; vertical-align: middle; padding: 0 5px;">
                <?php if ($logo_path !== ''): ?><img src="<?= $logo_path ?>" class="kop-logo-img" alt="Logo"><?php endif; ?>
            </td>
            <td style="border: none; text-align: center; vertical-align: middle;">
                <?php if ($nama_yayasan_upper !== ''): ?><div class="kop-yayasan"><?= htmlspecialchars($nama_yayasan_upper) ?></div><?php endif; ?>
                <h2><?= htmlspecialchars($nama_madrasah_upper) ?></h2>
                <p><?= htmlspecialchars($alamat_madrasah) ?></p>
            </td>
            <td style="border: none; width: 96px; min-width: 96px;"></td>
        </tr>
    </table>
</div>

<div class="doc-title">
    <div class="doc-kicker">Laporan Projek Kokurikuler</div>
    <h4><?= htmlspecialchars($judul_tampil) ?></h4>
    <div class="sub">Tahun Ajaran: <?= htmlspecialchars($tahun_ajaran) ?> (<?= htmlspecialchars($semester) ?>)</div>
    <small>Tanggal Cetak: <?= date('d F Y') ?> &bull; Wali Kelas: <?= htmlspecialchars($nama_wali) ?></small>
</div>

<?php if ($is_single): ?>
    <?php
    $p = $projek_list[0];
    $anggota = $anggota_by_projek[(int)$p['id']] ?? [];
    $groups = projek_group_anggota($anggota);
    ?>
    <div class="projek-entry">
        <table class="projek-identitas">
            <colgroup>
                <col style="width: 125px;">
                <col>
                <col style="width: 140px;">
                <col>
            </colgroup>
            <tbody>
                <tr>
                    <td class="lbl">Tema</td>
                    <td><?= htmlspecialchars($p['tema']) ?></td>
                    <td class="lbl">Kelas</td>
                    <td><?= htmlspecialchars($p['nama_kelas'] ?? '-') ?></td>
                </tr>
                <tr>
                    <td class="lbl">Pembimbing</td>
                    <td><?= htmlspecialchars($p['pembimbing'] ?? '-') ?></td>
                    <td class="lbl">Status</td>
                    <td><?= htmlspecialchars($p['status']) ?></td>
                </tr>
                <tr>
                    <td class="lbl">Tanggal Mulai</td>
                    <td><?= !empty($p['tgl_mulai']) ? date('d/m/Y', strtotime($p['tgl_mulai'])) : '-' ?></td>
                    <td class="lbl">Tanggal Selesai</td>
                    <td><?= !empty($p['tgl_selesai']) ? date('d/m/Y', strtotime($p['tgl_selesai'])) : '-' ?></td>
                </tr>
                <tr>
                    <td class="lbl">Jumlah Siswa</td>
                    <td><?= count($anggota) ?> orang</td>
                    <td class="lbl">Jumlah Kelompok</td>
                    <td><?= count($groups) ?> kelompok</td>
                </tr>
                <?php if (!empty($p['deskripsi'])): ?>
                <tr>
                    <td class="lbl">Tujuan</td>
                    <td colspan="3"><?= nl2br(htmlspecialchars(trim(strip_tags($p['deskripsi'])))) ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if (empty($groups)): ?>
            <table class="table-data">
                <tbody>
                    <tr><td class="empty-row">Belum ada anggota / kelompok pada projek ini.</td></tr>
                </tbody>
            </table>
        <?php else: ?>
            <?php foreach ($groups as $kelompok => $members): ?>
                <div class="kelompok-title"><?= htmlspecialchars($kelompok) ?> (<?= count($members) ?> siswa)</div>
                <table class="table-data">
                    <colgroup>
                        <col style="width: 36px;">
                        <col>
                        <col style="width: 100px;">
                        <col style="width: 100px;">
                        <col style="width: 150px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nama Siswa</th>
                            <th>NISN</th>
                            <th>Peran</th>
                            <th>Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1; foreach ($members as $a): ?>
                            <tr>
                                <td class="c"><?= $no++ ?></td>
                                <td><?= htmlspecialchars($a['nama_siswa'] ?? '-') ?></td>
                                <td class="c"><?= htmlspecialchars(!empty($a['nisn']) ? $a['nisn'] : '-') ?></td>
                                <td class="c"><?= htmlspecialchars($a['peran'] ?? 'Anggota') ?></td>
                                <td><?= htmlspecialchars(!empty($a['catatan']) ? $a['catatan'] : '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
<?php else: ?>
    <!-- Rekap semua projek: cukup nama projek & identitasnya (tanpa kelompok/anggota) -->
    <table class="table-data table-rekap">
        <colgroup>
            <col style="width: 4%;">
            <col style="width: 26%;">
            <col style="width: 14%;">
            <col style="width: 6%;">
            <col style="width: 14%;">
            <col style="width: 9%;">
            <col style="width: 9%;">
            <col style="width: 8%;">
            <col style="width: 10%;">
        </colgroup>
        <thead>
            <tr>
                <th>No</th>
                <th>Nama Projek</th>
                <th>Tema</th>
                <th>Kelas</th>
                <th>Pembimbing</th>
                <th>Mulai</th>
                <th>Selesai</th>
                <th>Jml Siswa</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php $no = 1; foreach ($projek_list as $p): ?>
                <?php $jml = count($anggota_by_projek[(int)$p['id']] ?? []); ?>
                <tr>
                    <td class="c"><?= $no++ ?></td>
                    <td><?= htmlspecialchars($p['nama_projek']) ?></td>
                    <td><?= htmlspecialchars($p['tema']) ?></td>
                    <td class="c"><?= htmlspecialchars($p['nama_kelas'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($p['pembimbing'] ?? '-') ?></td>
                    <td class="c"><?= !empty($p['tgl_mulai']) ? date('d/m/Y', strtotime($p['tgl_mulai'])) : '-' ?></td>
                    <td class="c"><?= !empty($p['tgl_selesai']) ? date('d/m/Y', strtotime($p['tgl_selesai'])) : '-' ?></td>
                    <td class="c"><?= $jml ?></td>
                    <td class="c"><?= htmlspecialchars($p['status']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<!-- TANDA TANGAN + QR CODE (satu blok, tidak terpotong saat ganti halaman) -->
<div class="signature-block">
<table class="signature-table">
    <tr>
        <td>
            Mengetahui,<br>
            Kepala Madrasah<br>
            <img src="<?= $qr_kepala ?>" class="qr-ttd" alt="QR TTD Kepala Madrasah">
            <strong><?= htmlspecialchars($kepala_madrasah) ?></strong><br>
            NIP: <?= htmlspecialchars($nip_kepala) ?>
        </td>
        <td>
            <?= htmlspecialchars($tempat_jadwal) ?>, <?= date('d F Y') ?><br>
            Wali Kelas,<br>
            <img src="<?= $qr_wali ?>" class="qr-ttd" alt="QR TTD Wali Kelas">
            <strong><?= htmlspecialchars($nama_wali) ?></strong>
        </td>
    </tr>
</table>
</div>

<?php if (!$is_download): ?>
    <script>
        function printWhenReady() {
            var imgs = Array.prototype.slice.call(document.images || []);
            var pending = imgs.filter(function(im) { return !im.complete; });
            if (pending.length === 0) { setTimeout(function () { window.print(); }, 350); return; }
            var done = 0;
            function tick() { done++; if (done >= pending.length) setTimeout(function () { window.print(); }, 350); }
            pending.forEach(function (im) { im.addEventListener('load', tick, { once: true }); im.addEventListener('error', tick, { once: true }); });
            setTimeout(function () { window.print(); }, 3500);
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
    if ($is_single) {
        $dompdf->setPaper([0, 0, 609.43, 935.43], 'portrait');   // F4 portrait
    } else {
        $dompdf->setPaper([0, 0, 935.43, 609.43], 'landscape');  // F4 landscape
    }
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
