<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['guru', 'wali', 'admin', 'tata_usaha', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$id_siswa = (int)($_GET['id_siswa'] ?? 0);
$id_catatan = (int)($_GET['id'] ?? 0);
$f_kelas = (int)($_GET['f_kelas'] ?? 0);
$f_mapel = (int)($_GET['f_mapel'] ?? 0);
$f_kategori = trim((string)($_GET['f_kategori'] ?? ''));
$f_status = trim((string)($_GET['f_status'] ?? ''));
// Mode output: print (window.print tab baru, stabil) atau pdf (unduh file via Dompdf).
// Kompatibel mundur: download=1 dianggap mode pdf.
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

// Profil Guru Login
$stG = $pdo->prepare("SELECT nama_guru, nuptk FROM tb_guru WHERE id_guru = ?");
$stG->execute([$guru_id]);
$guru_info = $stG->fetch(PDO::FETCH_ASSOC);
$nama_guru = $guru_info['nama_guru'] ?? ($_SESSION['nama_guru'] ?? 'Guru Pengampu');
$nip_guru = $guru_info['nuptk'] ?? '-';

// Query Catatan
$where = ["c.id_guru = ?"];
$params = [$guru_id];

if ($id_catatan > 0) {
    $where[] = "c.id = ?";
    $params[] = $id_catatan;
} elseif ($id_siswa > 0) {
    $where[] = "c.id_siswa = ?";
    $params[] = $id_siswa;
} else {
    if ($f_kelas > 0) {
        $where[] = "c.id_kelas = ?";
        $params[] = $f_kelas;
    }
    if ($f_mapel > 0) {
        $where[] = "c.id_mapel = ?";
        $params[] = $f_mapel;
    }
    if ($f_kategori !== '') {
        $where[] = "c.kategori = ?";
        $params[] = $f_kategori;
    }
    if ($f_status !== '') {
        $where[] = "c.status = ?";
        $params[] = $f_status;
    }
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT c.*, s.nama_siswa, s.nisn, s.jenis_kelamin, k.nama_kelas, m.nama_mapel, g.nama_guru
    FROM tb_catatan_perkembangan c
    JOIN tb_siswa s ON s.id_siswa = c.id_siswa
    LEFT JOIN tb_kelas k ON k.id_kelas = c.id_kelas
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = c.id_mapel
    LEFT JOIN tb_guru g ON g.id_guru = c.id_guru
    WHERE $where_sql
    ORDER BY s.nama_siswa ASC, c.tanggal DESC, c.id DESC
");
$stmt->execute($params);
$catatan_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($catatan_list)) {
    echo "<script>alert('Tidak ada data catatan perkembangan untuk dicetak.'); window.history.back();</script>";
    exit;
}

// Mode: 1 Siswa Spesifik atau Rekap Kolektif
$is_single_student = ($id_siswa > 0 || $id_catatan > 0) && count(array_unique(array_column($catatan_list, 'id_siswa'))) === 1;
$student_info = $is_single_student ? $catatan_list[0] : null;

// Judul Dokumen (tampilkan tahun ajaran; untuk per siswa tampilkan juga nama)
$ta_file = preg_replace('/[^A-Za-z0-9-]+/', '', str_replace('/', '-', $tahun_ajaran));
if ($is_single_student) {
    $nama_safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $student_info['nama_siswa']);
    $judul_dokumen = "LAPORAN PERKEMBANGAN BELAJAR PESERTA DIDIK - " . strtoupper($student_info['nama_siswa']) . " - TAHUN AJARAN " . $tahun_ajaran;
    $filename = "Laporan_Perkembangan_" . $nama_safe . "_TA" . $ta_file . "_" . date('Ymd');
} else {
    $judul_dokumen = "REKAPITULASI CATATAN PERKEMBANGAN PESERTA DIDIK - TAHUN AJARAN " . $tahun_ajaran;
    $filename = "Rekap_Catatan_Perkembangan_TA" . $ta_file . "_" . date('Ymd');
}

// Nama file logo (kop pakai path file langsung seperti cetak rekap nilai)
$logo_file = $school['logo'] ?? '';

// QR Code Signature
$qr_guru = 'https://api.qrserver.com/v1/create-qr-code/?size=90x90&data=' . urlencode("Tanda Tangan Guru: {$nama_guru} - Catatan Perkembangan - {$nama_madrasah}");
$qr_kepala = 'https://api.qrserver.com/v1/create-qr-code/?size=90x90&data=' . urlencode("Tanda Tangan Kepala Madrasah: {$kepala_madrasah} - {$nama_madrasah}");

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
            size: 330mm 215mm;
            margin: 10mm 12mm;
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
        .kop-table { width: 100%; border: none; border-collapse: collapse; margin: 0; }
        .kop-table td { vertical-align: middle; border: none !important; padding: 0; }
        .kop-logo { width: 100px; text-align: center; }
        .kop-title { text-align: center; }
        .kop-spacer { width: 100px; }
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
            background: #e0f2fe;
            color: #0369a1;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 8.5pt;
        }
        .aspect-grid {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 8.5pt;
        }
        .aspect-grid td {
            border: 1px solid #ddd;
            padding: 4px 6px;
            vertical-align: top;
            width: 50%;
        }
        .aspect-grid .asp-title {
            font-weight: bold;
            color: #1e3a8a;
            margin-bottom: 2px;
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
            <strong>Pratinjau Cetak Laporan Perkembangan</strong> &bull; <span class="text-muted"><?= htmlspecialchars($filename) ?></span>
        </div>
        <div>
            <button onclick="window.print()" style="background:#2563eb;color:#fff;border:none;padding:7px 16px;border-radius:4px;font-weight:bold;cursor:pointer;">
                <i class="fas fa-print"></i> Cetak Sekarang
            </button>
            <?php
            $qs_pdf = $_GET;
            unset($qs_pdf['download']);
            $qs_pdf['mode'] = 'pdf';
            ?>
            <a href="export_catatan_perkembangan_pdf.php?<?= http_build_query($qs_pdf) ?>" style="background:#dc2626;color:#fff;text-decoration:none;padding:7px 14px;border-radius:4px;font-weight:bold;margin-left:6px;">
                <i class="fas fa-file-pdf"></i> Unduh File PDF
            </a>
            <button onclick="window.close()" style="background:#64748b;color:#fff;border:none;padding:7px 12px;border-radius:4px;margin-left:6px;cursor:pointer;">
                Tutup
            </button>
        </div>
    </div>
<?php endif; ?>

<!-- KOP MADRASAH -->
<div class="header-kop">
    <?php
    // Samakan pola kop dengan cetak lain (path file langsung, height 80px width auto).
    $logo_path = '';
    if (!empty($logo_file)) {
        foreach (['../assets/img/' . $logo_file, __DIR__ . '/../assets/img/' . $logo_file] as $lp) {
            $fs = (strpos($lp, __DIR__) === 0) ? $lp : __DIR__ . '/' . $lp;
            if (is_file($fs)) { $logo_path = '../assets/img/' . $logo_file; break; }
        }
    }
    ?>
    <table style="width: 100%; border: none; margin: 0;">
        <tr style="border: none;">
            <td style="border: none; width: 100px; text-align: center; vertical-align: middle;">
                <?php if ($logo_path !== ''): ?>
                    <img src="<?= htmlspecialchars($logo_path) ?>" style="height: 80px; width: auto;">
                <?php endif; ?>
            </td>
            <td style="border: none; text-align: center; vertical-align: middle;">
                <h2><?= htmlspecialchars($nama_madrasah) ?></h2>
                <h3>CATATAN PERKEMBANGAN &amp; PEMBINAAN PESERTA DIDIK</h3>
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
            <td class="lbl">Kelas / Fase</td>
            <td style="width: 10px;">:</td>
            <td>Kelas <?= htmlspecialchars($student_info['nama_kelas'] ?? '-') ?></td>
        </tr>
        <tr>
            <td class="lbl">NISN</td>
            <td>:</td>
            <td><?= htmlspecialchars($student_info['nisn'] ?? '-') ?></td>
            <td class="lbl">Mata Pelajaran</td>
            <td>:</td>
            <td><?= htmlspecialchars($student_info['nama_mapel'] ?? 'Umum / Terpadu') ?></td>
        </tr>
        <tr>
            <td class="lbl">Guru Pengampu</td>
            <td>:</td>
            <td><?= htmlspecialchars($student_info['nama_guru'] ?? $nama_guru) ?></td>
            <td class="lbl">Status Pemantauan</td>
            <td>:</td>
            <td><strong><?= htmlspecialchars($student_info['status'] ?? 'Aktif') ?></strong></td>
        </tr>
    </table>

    <h5 style="margin: 14px 0 8px; font-size: 10.5pt; border-bottom: 1.5px solid #2563eb; color: #1e3a8a; padding-bottom: 3px;">
        RIWAYAT &amp; ASPEK PERKEMBANGAN PESERTA DIDIK
    </h5>

    <?php foreach ($catatan_list as $idx => $c): ?>
        <div class="card-entry">
            <div class="entry-header">
                <div>
                    <strong>Pengamatan #<?= $idx + 1 ?> &bull; <?= date('d F Y', strtotime($c['tanggal'])) ?></strong>
                    <span class="badge-asp" style="margin-left: 8px;"><?= htmlspecialchars($c['kategori']) ?></span>
                </div>
                <div>Status: <strong><?= htmlspecialchars($c['status']) ?></strong></div>
            </div>

            <div style="margin-bottom: 6px;">
                <strong>Ringkasan Perkembangan:</strong>
                <div style="margin-top: 2px;"><?= nl2br(htmlspecialchars($c['ringkasan'])) ?></div>
            </div>

            <?php if (!empty($c['kendala'])): ?>
                <div style="margin-bottom: 6px; color: #b91c1c;">
                    <strong>Kendala Pembelajaran:</strong>
                    <div style="margin-top: 2px; color: #111;"><?= nl2br(htmlspecialchars($c['kendala'])) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($c['tindak_lanjut'])): ?>
                <div style="margin-bottom: 6px; color: #15803d;">
                    <strong>Tindak Lanjut / Solusi:</strong>
                    <div style="margin-top: 2px; color: #111;"><?= nl2br(htmlspecialchars($c['tindak_lanjut'])) ?></div>
                </div>
            <?php endif; ?>

            <!-- Rincian Aspek Detail -->
            <?php
            $details = [
                ['Akademik', $c['perkembangan_akademik'] ?? ''],
                ['Sikap & Karakter', $c['perkembangan_sikap'] ?? ''],
                ['Keterampilan', $c['perkembangan_keterampilan'] ?? ''],
                ['Keaktifan Siswa', $c['keaktifan'] ?? ''],
                ['Potensi / Bakat', $c['potensi'] ?? ''],
                ['Rekomendasi Guru', $c['rekomendasi'] ?? ''],
            ];
            $has_detail = false;
            foreach ($details as $d) { if (trim((string)$d[1]) !== '' && $d[1] !== '-') { $has_detail = true; break; } }
            ?>
            <?php if ($has_detail): ?>
                <table class="aspect-grid">
                    <tr>
                        <td>
                            <div class="asp-title">Perkembangan Akademik:</div>
                            <div><?= !empty($c['perkembangan_akademik']) ? htmlspecialchars($c['perkembangan_akademik']) : '-' ?></div>
                        </td>
                        <td>
                            <div class="asp-title">Sikap &amp; Karakter:</div>
                            <div><?= !empty($c['perkembangan_sikap']) ? htmlspecialchars($c['perkembangan_sikap']) : '-' ?></div>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <div class="asp-title">Keterampilan:</div>
                            <div><?= !empty($c['perkembangan_keterampilan']) ? htmlspecialchars($c['perkembangan_keterampilan']) : '-' ?></div>
                        </td>
                        <td>
                            <div class="asp-title">Keaktifan Partisipasi:</div>
                            <div><?= !empty($c['keaktifan']) ? htmlspecialchars($c['keaktifan']) : '-' ?></div>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <div class="asp-title">Bakat &amp; Potensi Khusus:</div>
                            <div><?= !empty($c['potensi']) ? htmlspecialchars($c['potensi']) : '-' ?></div>
                        </td>
                        <td>
                            <div class="asp-title">Rekomendasi Pembimbingan:</div>
                            <div><?= !empty($c['rekomendasi']) ? htmlspecialchars($c['rekomendasi']) : '-' ?></div>
                        </td>
                    </tr>
                </table>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

<?php else: ?>
    <!-- TABEL REKAPITULASI SEMUA SISWA -->
    <table class="table-data">
        <thead>
            <tr>
                <th style="width: 25px;">No</th>
                <th style="width: 70px;">Tanggal</th>
                <th style="width: 130px;">Nama Siswa</th>
                <th style="width: 50px;">Kelas</th>
                <th style="width: 85px;">Aspek</th>
                <th>Ringkasan Perkembangan</th>
                <th>Kendala</th>
                <th>Tindak Lanjut</th>
            </tr>
        </thead>
        <tbody>
            <?php $no = 1; foreach ($catatan_list as $row): ?>
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
                    <td style="text-align: center;">
                        <span class="badge-asp"><?= htmlspecialchars($row['kategori']) ?></span>
                    </td>
                    <td><?= nl2br(htmlspecialchars($row['ringkasan'])) ?></td>
                    <td style="color: #b91c1c;"><?= htmlspecialchars($row['kendala'] ?? '-') ?></td>
                    <td style="color: #15803d;"><?= htmlspecialchars($row['tindak_lanjut'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

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
            Guru Pengampu,<br>
            <img src="<?= $qr_guru ?>" alt="QR TTD Guru" style="width: 60px; height: 60px; margin: 6px auto; display: block;">
            <strong><?= htmlspecialchars($nama_guru) ?></strong>
        </td>
    </tr>
</table>

<?php if (!$is_download): ?>
    <script>
        // Auto print dialog upon page open
        window.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                window.print();
            }, 600);
        });
    </script>
<?php endif; ?>

</body>
</html>
<?php
$html_out = ob_get_clean();

// Jika download PDF diminta
if ($is_download) {
    require_once '../vendor/autoload.php';
    while (ob_get_level()) { ob_end_clean(); }

    if (!class_exists('Dompdf\\Dompdf')) {
        die('Library Dompdf tidak tersedia.');
    }

    $dompdf = new Dompdf\Dompdf(['isHtml5ParserEnabled' => true, 'isRemoteEnabled' => true, 'defaultFont' => 'sans-serif']);
    $dompdf->loadHtml($html_out);
    // F4 landscape: 330mm x 215mm = 935pt x 609pt (agar stabil & sama dengan print tab)
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
