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

$id_siswa = (int)($_GET['id_siswa'] ?? 0);
$id_catatan = (int)($_GET['id'] ?? 0);
$f_kategori = trim((string)($_GET['f_kategori'] ?? ''));
$f_kelas = (int)($_GET['kelas'] ?? $_GET['f_kelas'] ?? 0);
$f_status = trim((string)($_GET['f_status'] ?? ''));
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

// Profil Wali Login
$stG = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
$stG->execute([$guru_id]);
$nama_wali = $stG->fetchColumn() ?: ($_SESSION['nama_guru'] ?? 'Wali Kelas');

// Query Pelanggaran
$where = ["1=1"];
$params = [];

if ($id_catatan > 0) {
    $where[] = "p.id = ?";
    $params[] = $id_catatan;
} elseif ($id_siswa > 0) {
    $where[] = "p.id_siswa = ?";
    $params[] = $id_siswa;
} else {
    if ($f_kelas > 0) {
        $where[] = "p.id_kelas = ?";
        $params[] = $f_kelas;
    }
    if ($f_kategori !== '') {
        $where[] = "p.kategori = ?";
        $params[] = $f_kategori;
    }
    if ($f_status !== '') {
        $where[] = "p.status = ?";
        $params[] = $f_status;
    }
}
if ($user_level !== 'admin') {
    $where[] = "p.id_wali = ?";
    $params[] = $guru_id;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT p.*, s.nama_siswa, s.nisn, k.nama_kelas, g.nama_guru
    FROM tb_pelanggaran_siswa p
    JOIN tb_siswa s ON s.id_siswa = p.id_siswa
    LEFT JOIN tb_kelas k ON k.id_kelas = p.id_kelas
    LEFT JOIN tb_guru g ON g.id_guru = p.id_wali
    WHERE $where_sql
    ORDER BY s.nama_siswa ASC, p.tanggal DESC, p.id DESC
");
$stmt->execute($params);
$langgar_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Akumulasi total poin SEMUA WAKTU per siswa (untuk status sanksi valid, bukan hanya filter tampil)
$poin_all_map = [];
try {
    $stAll = $pdo->prepare("SELECT id_siswa, COALESCE(SUM(poin),0) AS tot, COUNT(*) AS cnt FROM tb_pelanggaran_siswa WHERE 1=1 " . ($user_level !== 'admin' ? "AND id_wali = " . (int)$guru_id : "") . " GROUP BY id_siswa");
    $stAll->execute();
    foreach ($stAll->fetchAll(PDO::FETCH_ASSOC) as $pa) {
        $poin_all_map[(int)$pa['id_siswa']] = ['total' => (int)$pa['tot'], 'count' => (int)$pa['cnt']];
    }
} catch (Throwable $e) {}

if (empty($langgar_list)) {
    echo "<script>alert('Tidak ada data pelanggaran untuk dicetak.'); window.history.back();</script>";
    exit;
}

// Mode: 1 Siswa Spesifik atau Rekap Kolektif
$is_single_student = ($id_siswa > 0 || $id_catatan > 0) && count(array_unique(array_column($langgar_list, 'id_siswa'))) === 1;
$student_info = $is_single_student ? $langgar_list[0] : null;

// Judul Dokumen (tampilkan tahun ajaran; untuk per siswa tampilkan juga nama)
$ta_file = preg_replace('/[^A-Za-z0-9-]+/', '', str_replace('/', '-', $tahun_ajaran));
if ($is_single_student) {
    $nama_safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $student_info['nama_siswa']);
    $judul_dokumen = "LAPORAN PELANGGARAN SISWA - " . strtoupper($student_info['nama_siswa']) . " - TAHUN AJARAN " . $tahun_ajaran;
    $filename = "Laporan_Pelanggaran_" . $nama_safe . "_TA" . $ta_file . "_" . date('Ymd');
} else {
    $judul_dokumen = "REKAPITULASI PELANGGARAN SISWA - TAHUN AJARAN " . $tahun_ajaran;
    $filename = "Rekap_Pelanggaran_TA" . $ta_file . "_" . date('Ymd');
}

$logo_file = $school['logo'] ?? '';

// Helper: ambil gambar (lokal/remote) jadi data-URI base64 agar Dompdf selalu render
// (path relatif putus di Dompdf, URL remote kadang gagal fetch saat render PDF).
if (!function_exists('pelanggaran_img_b64')) {
    function pelanggaran_img_b64(string $src): string {
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
            if ($data === false) return $src; // fallback: biarkan URL (print-tab tetap tampil)
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

// Logo kop: mode print pakai path langsung (cepat, anti-separo);
// mode file PDF pakai base64 (path relatif putus saat render Dompdf).
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
    $b64 = pelanggaran_img_b64($fs);
    if ($b64 !== '') $logo_src = $b64;
}

// QR Code Signature: mode print pakai URL langsung (cepat);
// mode file PDF pakai base64 agar posisi stabil.
$qr_wali_url = 'https://api.qrserver.com/v1/create-qr-code/?size=90x90&data=' . urlencode("Tanda Tangan Wali Kelas: {$nama_wali} - Pelanggaran Siswa - {$nama_madrasah}");
$qr_kepala_url = 'https://api.qrserver.com/v1/create-qr-code/?size=90x90&data=' . urlencode("Tanda Tangan Kepala Madrasah: {$kepala_madrasah} - {$nama_madrasah}");
$qr_wali = $qr_wali_url;
$qr_kepala = $qr_kepala_url;
if ($is_download) {
    $b64w = pelanggaran_img_b64($qr_wali_url);
    $b64k = pelanggaran_img_b64($qr_kepala_url);
    if (strpos($b64w, 'data:image') === 0) $qr_wali = $b64w;
    if (strpos($b64k, 'data:image') === 0) $qr_kepala = $b64k;
}

// Buffer HTML
ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($judul_dokumen) ?></title>
    <style>
        @page {
            <?php if ($is_single_student): ?>
            size: 215mm 330mm;
            margin: 12mm 12mm;
            <?php else: ?>
            size: 330mm 215mm;
            margin: 10mm 12mm;
            <?php endif; ?>
        }
        body {
            font-family: Arial, "Helvetica Neue", Helvetica, sans-serif;
            font-size: 10pt;
            color: #111;
            line-height: 1.4;
            background: #fff;
            margin: 0;
            padding: 10px;
        }
        .header-kop {
            border-bottom: 2.5px solid #000;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .header-kop h2 {
            margin: 0;
            font-size: 14pt;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .header-kop h3 {
            margin: 2px 0;
            font-size: 12pt;
            font-weight: 700;
        }
        .header-kop p {
            margin: 2px 0;
            font-size: 9pt;
            color: #333;
        }
        .header-kop table { table-layout: fixed; }
        .header-kop td { overflow: visible; }
        .kop-logo-img {
            width: 82px;
            height: 71px;
            display: block;
            margin: 0 auto;
        }
        .doc-title {
            text-align: center;
            margin: 14px 0 16px;
        }
        .doc-title h4 {
            margin: 0;
            font-size: 10.5pt;
            font-weight: 800;
            text-decoration: underline;
            text-transform: uppercase;
            line-height: 1.35;
        }
        .doc-title small {
            font-size: 9pt;
            color: #444;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 9.5pt;
        }
        .info-table td {
            padding: 3px 6px;
            vertical-align: top;
        }
        .info-table .lbl {
            width: 130px;
            font-weight: bold;
            color: #222;
        }
        .table-data {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            font-size: 9pt;
        }
        .table-data th, .table-data td {
            border: 1px solid #333;
            padding: 6px 8px;
            vertical-align: top;
        }
        .table-data th {
            background-color: #f1f5f9;
            font-weight: bold;
            text-align: center;
            font-size: 9pt;
        }
        .card-entry {
            border: 1px solid #bbb;
            border-radius: 4px;
            padding: 10px 12px;
            margin-bottom: 14px;
            page-break-inside: avoid;
        }
        .card-entry .entry-header {
            border-bottom: 1px solid #ccc;
            padding-bottom: 4px;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            font-size: 9pt;
        }
        .badge-asp {
            background: #fef3c7;
            color: #92400e;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 8.5pt;
        }
        .signature-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 30px;
            page-break-inside: avoid;
            font-size: 9.5pt;
        }
        .signature-table td {
            text-align: center;
            vertical-align: top;
            width: 50%;
        }
        .no-print {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            padding: 10px 14px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 6px;
        }
        @media print {
            .no-print { display: none !important; }
            body { padding: 0; }
        }
    </style>
</head>
<body>

<?php if (!$is_download): ?>
    <div class="no-print">
        <div>
            <strong>Pratinjau Cetak Pelanggaran Siswa</strong> &bull; <span class="text-muted"><?= htmlspecialchars($filename) ?></span>
        </div>
        <div>
            <button onclick="window.print()" style="background:#2563eb;color:#fff;border:none;padding:7px 16px;border-radius:4px;font-weight:bold;cursor:pointer;">
                Cetak Sekarang
            </button>
            <?php
            $qs_pdf = $_GET;
            unset($qs_pdf['download']);
            $qs_pdf['mode'] = 'pdf';
            ?>
            <a href="export_pelanggaran_pdf.php?<?= http_build_query($qs_pdf) ?>" style="background:#dc2626;color:#fff;text-decoration:none;padding:7px 14px;border-radius:4px;font-weight:bold;margin-left:6px;">
                Unduh File PDF
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
            <td style="border: none; width: 100px; text-align: center; vertical-align: middle;">
                <?php if ($logo_src !== ''): ?>
                    <img src="<?= $logo_src ?>" class="kop-logo-img" alt="Logo">
                <?php endif; ?>
            </td>
            <td style="border: none; text-align: center; vertical-align: middle;">
                <h2><?= htmlspecialchars($nama_madrasah) ?></h2>
                <h3>PELANGGARAN TATA TERTIB PESERTA DIDIK</h3>
                <p><?= htmlspecialchars($alamat_madrasah) ?> &bull; Tahun Ajaran: <?= htmlspecialchars($tahun_ajaran) ?> (<?= htmlspecialchars($semester) ?>)</p>
            </td>
            <td style="border: none; width: 100px;"></td>
        </tr>
    </table>
</div>

<div class="doc-title">
    <h4><?= htmlspecialchars($judul_dokumen) ?></h4>
    <small>Tanggal Cetak: <?= date('d F Y') ?></small>
</div>

<?php if ($is_single_student): ?>
    <!-- INFORMASI PROFIL SISWA TUNGGAL -->
    <table class="info-table">
        <tr>
            <td class="lbl">Nama Peserta Didik</td>
            <td style="width: 10px;">:</td>
            <td><strong><?= htmlspecialchars($student_info['nama_siswa']) ?></strong></td>
            <td class="lbl">Kelas</td>
            <td style="width: 10px;">:</td>
            <td>Kelas <?= htmlspecialchars($student_info['nama_kelas'] ?? '-') ?></td>
        </tr>
        <tr>
            <td class="lbl">NISN</td>
            <td>:</td>
            <td><?= htmlspecialchars($student_info['nisn'] ?? '-') ?></td>
            <td class="lbl">Total Poin</td>
            <td>:</td>
            <?php
            $sid_info = (int)$student_info['id_siswa'];
            $tot_all = (int)($poin_all_map[$sid_info]['total'] ?? array_sum(array_column($langgar_list, 'poin')));
            $cnt_all = (int)($poin_all_map[$sid_info]['count'] ?? count($langgar_list));
            $sk_info = function_exists('pelanggaran_sanksi_by_poin') ? pelanggaran_sanksi_by_poin($tot_all) : ['level' => '-', 'desc' => ''];
            ?>
            <td><strong><?= $tot_all ?> poin</strong> (<?= htmlspecialchars($sk_info['level']) ?>)</td>
        </tr>
        <tr>
            <td class="lbl">Wali Kelas</td>
            <td>:</td>
            <td><?= htmlspecialchars($student_info['nama_guru'] ?? $nama_wali) ?></td>
            <td class="lbl">Jumlah Catatan</td>
            <td>:</td>
            <td><?= $cnt_all ?> kali pelanggaran</td>
        </tr>
        <tr>
            <td class="lbl">Status Sanksi</td>
            <td>:</td>
            <td colspan="4"><strong><?= htmlspecialchars($sk_info['level']) ?></strong> &mdash; <?= htmlspecialchars($sk_info['desc']) ?></td>
        </tr>
    </table>

    <h5 style="margin: 14px 0 8px; font-size: 10.5pt; border-bottom: 1.5px solid #b91c1c; color: #991b1b; padding-bottom: 3px;">
        RIWAYAT PELANGGARAN PESERTA DIDIK
    </h5>

    <?php $run = 0; foreach ($langgar_list as $idx => $c): $run += (int)$c['poin']; ?>
        <div class="card-entry">
            <div class="entry-header">
                <div>
                    <strong>Pelanggaran #<?= $idx + 1 ?> &bull; <?= date('d F Y', strtotime($c['tanggal'])) ?></strong>
                    <span class="badge-asp" style="margin-left: 8px;"><?= htmlspecialchars($c['kategori']) ?> &bull; +<?= (int)$c['poin'] ?> poin (akumulasi <?= $run ?>)</span>
                </div>
                <div>Status: <strong><?= htmlspecialchars($c['status']) ?></strong></div>
            </div>

            <div style="margin-bottom: 6px; color: #b91c1c;">
                <strong>Jenis Pelanggaran:</strong>
                <div style="margin-top: 2px; color: #111;"><?= nl2br(htmlspecialchars($c['jenis_pelanggaran'])) ?></div>
            </div>

            <div style="margin-bottom: 6px;">
                <strong>Tindakan / Sanksi:</strong>
                <div style="margin-top: 2px;"><?= nl2br(htmlspecialchars($c['tindakan'])) ?></div>
            </div>

            <div style="margin-bottom: 2px;">
                <strong>Keterangan Orang Tua:</strong>
                <div style="margin-top: 2px;"><?= nl2br(htmlspecialchars($c['orang_tua'] ?? '-')) ?></div>
            </div>
        </div>
    <?php endforeach; ?>

<?php else: ?>
    <!-- REKAP AKUMULASI POIN + STATUS SANKSI PER SISWA -->
    <?php
    $rekap = [];
    foreach ($langgar_list as $rw) {
        $sid = (int)$rw['id_siswa'];
        if (!isset($rekap[$sid])) {
            $tot = (int)($poin_all_map[$sid]['total'] ?? 0);
            $rekap[$sid] = [
                'nama' => $rw['nama_siswa'], 'nisn' => $rw['nisn'] ?? '-',
                'kelas' => $rw['nama_kelas'] ?? '-', 'total' => $tot,
                'count' => (int)($poin_all_map[$sid]['count'] ?? 0),
            ];
            $rekap[$sid]['sanksi'] = function_exists('pelanggaran_sanksi_by_poin') ? pelanggaran_sanksi_by_poin($tot) : ['level' => '-'];
        }
    }
    uasort($rekap, function($a, $b) { return $b['total'] <=> $a['total']; });
    ?>
    <h5 style="margin: 14px 0 8px; font-size: 10.5pt; border-bottom: 1.5px solid #b91c1c; color: #991b1b; padding-bottom: 3px;">
        AKUMULASI POIN &amp; STATUS SANKSI (25 PEMANTAUAN &bull; 50 SP1 &bull; 75 SKORSING &bull; 100 DO)
    </h5>
    <table class="table-data">
        <thead>
            <tr>
                <th style="width: 25px;">No</th>
                <th style="width: 140px;">Nama Siswa</th>
                <th style="width: 55px;">Kelas</th>
                <th style="width: 55px;">Jml</th>
                <th style="width: 55px;">Total Poin</th>
                <th style="width: 150px;">Status Sanksi</th>
            </tr>
        </thead>
        <tbody>
            <?php $no = 1; foreach ($rekap as $rk): ?>
                <tr>
                    <td style="text-align: center;"><?= $no++ ?></td>
                    <td><strong><?= htmlspecialchars($rk['nama']) ?></strong>
                        <div style="font-size: 8pt; color: #555;">NISN: <?= htmlspecialchars($rk['nisn']) ?></div>
                    </td>
                    <td style="text-align: center;"><?= htmlspecialchars($rk['kelas']) ?></td>
                    <td style="text-align: center;"><?= (int)$rk['count'] ?>x</td>
                    <td style="text-align: center; font-weight: bold; color: #b91c1c;"><?= (int)$rk['total'] ?></td>
                    <td style="text-align: center; font-weight: bold;"><?= htmlspecialchars($rk['sanksi']['level']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <!-- TABEL REKAPITULASI SEMUA SISWA -->
    <table class="table-data">
        <thead>
            <tr>
                <th style="width: 25px;">No</th>
                <th style="width: 70px;">Tanggal</th>
                <th style="width: 130px;">Nama Siswa</th>
                <th style="width: 50px;">Kelas</th>
                <th style="width: 150px;">Jenis Pelanggaran</th>
                <th style="width: 70px;">Kategori</th>
                <th style="width: 45px;">Poin</th>
                <th>Tindakan / Sanksi</th>
                <th style="width: 100px;">Orang Tua</th>
                <th style="width: 80px;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php $no = 1; foreach ($langgar_list as $row): ?>
                <tr>
                    <td style="text-align: center;"><?= $no++ ?></td>
                    <td style="text-align: center;"><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                    <td>
                        <strong><?= htmlspecialchars($row['nama_siswa']) ?></strong>
                        <?php if (!empty($row['nisn'])): ?>
                            <div style="font-size: 8pt; color: #555;">NISN: <?= htmlspecialchars($row['nisn']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center;">Kelas <?= htmlspecialchars($row['nama_kelas'] ?? '-') ?></td>
                    <td><?= nl2br(htmlspecialchars($row['jenis_pelanggaran'])) ?></td>
                    <td style="text-align: center;">
                        <span class="badge-asp"><?= htmlspecialchars($row['kategori']) ?></span>
                    </td>
                    <td style="text-align: center; font-weight: bold; color: #b91c1c;"><?= (int)$row['poin'] ?></td>
                    <td><?= htmlspecialchars($row['tindakan']) ?></td>
                    <td><?= htmlspecialchars($row['orang_tua'] ?? '-') ?></td>
                    <td style="text-align: center; font-weight: bold;"><?= htmlspecialchars($row['status']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<!-- TANDA TANGAN -->
<table class="signature-table">
    <tr>
        <td style="text-align: center; vertical-align: top; width: 50%;">
            Mengetahui,<br>
            Kepala Madrasah<br>
            <div style="height: 72px; text-align: center; margin: 4px 0;">
                <?php if ($qr_kepala !== '' && strpos($qr_kepala, 'data:image') === 0): ?>
                    <img src="<?= $qr_kepala ?>" alt="QR TTD Kepala" style="width: 64px; height: 64px;">
                <?php endif; ?>
            </div>
            <strong><?= htmlspecialchars($kepala_madrasah) ?></strong><br>
            NIP: <?= htmlspecialchars($nip_kepala) ?>
        </td>
        <td style="text-align: center; vertical-align: top; width: 50%;">
            <?= htmlspecialchars($tempat_jadwal) ?>, <?= date('d F Y') ?><br>
            Wali Kelas,<br>
            <div style="height: 72px; text-align: center; margin: 4px 0;">
                <?php if ($qr_wali !== '' && strpos($qr_wali, 'data:image') === 0): ?>
                    <img src="<?= $qr_wali ?>" alt="QR TTD Wali" style="width: 64px; height: 64px;">
                <?php endif; ?>
            </div>
            <strong><?= htmlspecialchars($nama_wali) ?></strong>
        </td>
    </tr>
</table>

<?php if (!$is_download): ?>
    <script>
        // Tunggu semua gambar (logo + QR) selesai load agar tidak kepotong/separo saat print.
        function printWhenReady() {
            var imgs = Array.prototype.slice.call(document.images || []);
            var pending = imgs.filter(function(im) { return !im.complete; });
            if (pending.length === 0) {
                setTimeout(function() { window.print(); }, 350);
                return;
            }
            var done = 0;
            function tick() {
                done++;
                if (done >= pending.length) setTimeout(function() { window.print(); }, 350);
            }
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

// Jika download file PDF diminta
if ($is_download) {
    require_once '../vendor/autoload.php';
    while (ob_get_level()) { ob_end_clean(); }

    if (!class_exists('Dompdf\\Dompdf')) {
        die('Library Dompdf tidak tersedia.');
    }

    $dompdf = new Dompdf\Dompdf(['isHtml5ParserEnabled' => true, 'isRemoteEnabled' => true, 'defaultFont' => 'sans-serif']);
    $dompdf->loadHtml($html_out);
    if ($is_single_student) {
        // F4 portrait per siswa: 215mm x 330mm = 609pt x 935pt
        $dompdf->setPaper([0, 0, 609.43, 935.43], 'portrait');
    } else {
        // F4 landscape semua siswa: 330mm x 215mm = 935pt x 609pt
        $dompdf->setPaper([0, 0, 935.43, 609.43], 'landscape');
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
