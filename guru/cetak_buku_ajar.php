<?php
/**
 * Cetak Data Buku Ajar — PDF via window.print()
 * Kertas F4, header lengkap, tanda tangan sesuai role
 */
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['guru', 'wali', 'admin', 'kepala_madrasah', 'tata_usaha'])) {
    die('Unauthorized');
}

$user_level    = getUserLevel();
$session_type  = $_GET['session_type'] ?? '';
$effective_level = $session_type ?: $user_level;
$guru_id       = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$is_admin_or_viewer = in_array($effective_level, ['admin', 'kepala_madrasah', 'tata_usaha'], true);
$is_wali            = ($effective_level === 'wali');

// Cari kelas milik wali
$wali_kelas_id = null;
$nama_guru_val = '';
if ($guru_id > 0) {
    $stmt_g = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
    $stmt_g->execute([$guru_id]);
    $nama_guru_val = $stmt_g->fetchColumn() ?: '';
}
if ($is_wali && $nama_guru_val) {
    $stmt_wali = $pdo->prepare("SELECT id_kelas FROM tb_kelas WHERE wali_kelas = ?");
    $stmt_wali->execute([$nama_guru_val]);
    $wali_kelas_id = $stmt_wali->fetchColumn() ?: null;
}

$school_profile     = getSchoolProfile($pdo);
$tahun_ajaran_aktif = $school_profile['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1);
$semester_aktif     = $school_profile['semester'] ?? 'Semester 1';

// Tanda tangan sesuai role
$penanda_tangan = '';
$jabatan_ttd    = '';
$ttd_kepala     = '';

if ($is_admin_or_viewer) {
    $penanda_tangan = $school_profile['kepala_madrasah'] ?? '.........................';
    $ttd_kepala     = $school_profile['ttd_kepala'] ?? '';
    $jabatan_ttd    = 'Kepala Madrasah';
} elseif ($is_wali) {
    $penanda_tangan = $nama_guru_val ?: '.........................';
    $jabatan_ttd    = 'Wali Kelas';
} else {
    $penanda_tangan = $nama_guru_val ?: '.........................';
    $jabatan_ttd    = 'Guru Mata Pelajaran';
}

// Filters
$f_mapel   = (int)($_GET['f_mapel']   ?? 0);
$f_kelas   = (int)($_GET['f_kelas']   ?? 0);
$f_jenis   = trim((string)($_GET['f_jenis']   ?? ''));
$f_semester = trim((string)($_GET['f_semester'] ?? ''));

$where  = [];
$params = [];

if ($is_admin_or_viewer) {
    if ($f_kelas > 0) {
        $where[] = "b.id_kelas = ?";
        $params[] = $f_kelas;
    }
} elseif ($is_wali && $wali_kelas_id) {
    if ($f_kelas > 0) {
        $where[] = "b.id_kelas = ? AND (b.id_kelas = ? OR b.id_guru = ?)";
        $params[] = $f_kelas;
        $params[] = $wali_kelas_id;
        $params[] = $guru_id;
    } else {
        $where[] = "(b.id_kelas = ? OR b.id_guru = ?)";
        $params[] = $wali_kelas_id;
        $params[] = $guru_id;
    }
} else {
    $where[] = "b.id_guru = ?";
    $params[] = $guru_id;
    if ($f_kelas > 0) {
        $where[] = "b.id_kelas = ?";
        $params[] = $f_kelas;
    }
}

if ($f_mapel > 0)   { $where[] = "b.id_mapel = ?";  $params[] = $f_mapel; }
if ($f_jenis !== '') { $where[] = "b.jenis_buku = ?"; $params[] = $f_jenis; }
if ($f_semester !== '') { $where[] = "b.semester = ?"; $params[] = $f_semester; }

$where_sql = !empty($where) ? implode(' AND ', $where) : "1=1";
$stmt = $pdo->prepare("
    SELECT b.*, m.nama_mapel, k.nama_kelas, g.nama_guru
    FROM tb_buku_ajar b
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = b.id_mapel
    LEFT JOIN tb_kelas k ON k.id_kelas = b.id_kelas
    LEFT JOIN tb_guru g ON g.id_guru = b.id_guru
    WHERE $where_sql
    ORDER BY m.nama_mapel ASC, b.judul ASC
");
$stmt->execute($params);
$buku_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Nama kelas terpilih
$kelas_name = '';
if ($f_kelas > 0) {
    $k = $pdo->query("SELECT nama_kelas FROM tb_kelas WHERE id_kelas = " . (int)$f_kelas)->fetch();
    $kelas_name = $k['nama_kelas'] ?? '';
}

$report_date = function_exists('formatDateIndonesia') ? formatDateIndonesia(date('Y-m-d')) : date('d F Y');
$school_city = $school_profile['tempat_jadwal'] ?? 'Jepara';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Cetak Data Buku Ajar</title>
    <style>
        @page {
            size: F4;
            margin: 12mm 15mm;
        }

        * { box-sizing: border-box; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #1a1a1a;
            margin: 0;
            padding: 0;
            line-height: 1.4;
        }

        /* ---- HEADER KOP ---- */
        .kop {
            text-align: center;
            border-bottom: 3px double #000;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .kop-table { width: 100%; border: none; margin: 0; }
        .kop-table td { border: none; vertical-align: middle; padding: 4px; }
        .kop-logo { width: 70px; text-align: center; }
        .kop-logo img { height: 65px; width: auto; }
        .kop-mid { text-align: center; }
        .kop-yayasan {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0;
        }
        .kop-madrasah {
            font-size: 16px;
            font-weight: 800;
            text-transform: uppercase;
            margin: 3px 0;
            letter-spacing: 0.5px;
        }
        .kop-alamat {
            font-size: 10px;
            margin: 0;
            color: #333;
        }

        /* ---- JUDUL ---- */
        .judul {
            text-align: center;
            margin: 14px 0 10px;
        }
        .judul h3 {
            font-size: 14px;
            font-weight: 800;
            margin: 0 0 6px;
            text-decoration: underline;
            text-transform: uppercase;
        }
        .judul-info { font-size: 11px; margin: 2px 0; }

        /* ---- TABEL ---- */
        table.data {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            page-break-inside: auto;
        }
        table.data thead { display: table-header-group; }
        table.data tr { page-break-inside: avoid; }
        table.data th, table.data td {
            border: 1px solid #333;
            padding: 5px 6px;
            font-size: 10.5px;
            vertical-align: middle;
        }
        table.data th {
            background: #e9ecef;
            font-weight: 700;
            text-align: center;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        table.data td { text-align: left; }
        table.data td.num { text-align: center; width: 4%; }
        table.data td.center { text-align: center; width: 10%; }

        /* ---- TANDA TANGAN ---- */
        .ttd-section {
            margin-top: 28px;
            page-break-inside: avoid;
            display: flex;
            justify-content: flex-end;  /* selalu rata kanan */
        }
        .ttd-box {
            width: 220px;
            text-align: center;
        }
        .ttd-date {
            font-size: 11px;
            margin: 0 0 3px;
        }
        .ttd-role {
            font-size: 11px;
            font-weight: 700;
            margin: 0 0 8px;
        }
        .ttd-qr {
            width: 80px;
            height: 80px;
            margin: 0 auto 4px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .ttd-qr img {
            width: 80px;
            height: 80px;
            display: block;
        }
        .ttd-space {
            /* ruang tanda tangan jika tidak ada QR */
            height: 80px;
        }
        .ttd-name {
            font-size: 12px;
            font-weight: 700;
            margin: 4px 0 0;
            padding-top: 4px;
        }

        /* ---- PRINT ONLY ---- */
        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
        }

        .btn-print {
            display: inline-block;
            padding: 8px 18px;
            background: #0d6efd;
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            margin: 10px;
        }
        .btn-print:hover { background: #0b5ed7; }
    </style>
</head>
<body>

<!-- Tombol Cetak -->
<div class="no-print" style="text-align: center; padding: 10px; background: #f8f9fa; border-bottom: 1px solid #ddd;">
    <button class="btn-print" onclick="window.print()">
        <span style="font-size:16px;">🖨️</span> Cetak Halaman Ini
    </button>
    <button class="btn-print" style="background:#6c757d;" onclick="window.close()">
        ✕ Tutup
    </button>
</div>

<!-- KOP SEKOLAH -->
<div class="kop">
    <table class="kop-table">
        <tr>
            <td class="kop-logo">
                <?php if (!empty($school_profile['logo'])): ?>
                    <img src="../assets/img/<?= htmlspecialchars($school_profile['logo']) ?>" alt="Logo">
                <?php endif; ?>
            </td>
            <td class="kop-mid">
                <p class="kop-yayasan"><?= htmlspecialchars($school_profile['nama_yayasan'] ?? '') ?></p>
                <p class="kop-madrasah"><?= htmlspecialchars($school_profile['nama_madrasah'] ?? '') ?></p>
                <p class="kop-alamat"><?= htmlspecialchars($school_profile['alamat'] ?? '') ?></p>
            </td>
            <td style="width:70px;"></td>
        </tr>
    </table>
</div>

<!-- JUDUL -->
<div class="judul">
    <h3>Data Buku Ajar</h3>
    <p class="judul-info">
        Tahun Ajaran <?= htmlspecialchars($tahun_ajaran_aktif) ?> — <?= htmlspecialchars($semester_aktif) ?>
        <?php if ($kelas_name): ?> | Kelas: <?= htmlspecialchars($kelas_name) ?><?php endif; ?>
        <?php if ($f_jenis): ?> | Jenis: <?= htmlspecialchars($f_jenis) ?><?php endif; ?>
    </p>
</div>

<!-- TABEL DATA -->
<table class="data">
    <thead>
        <tr>
            <th class="num">No</th>
            <th>Mata Pelajaran</th>
            <th>Judul Buku</th>
            <th>Penyusun</th>
            <th class="center">Jenis Buku</th>
            <?php if ($is_admin_or_viewer): ?>
                <th class="center">Kelas</th>
                <th>Guru Pengampu</th>
            <?php endif; ?>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($buku_rows)): ?>
            <tr>
                <td colspan="<?= $is_admin_or_viewer ? '7' : '5' ?>" style="text-align:center; padding:20px; color:#999;">
                    Tidak ada data buku ajar.
                </td>
            </tr>
        <?php else: ?>
            <?php $no = 1; foreach ($buku_rows as $r): ?>
                <tr>
                    <td class="num"><?= $no++ ?></td>
                    <td><?= htmlspecialchars($r['nama_mapel'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($r['judul']) ?></td>
                    <td><?= htmlspecialchars($r['penyusun'] ?: '-') ?></td>
                    <td class="center"><?= htmlspecialchars($r['jenis_buku']) ?></td>
                    <?php if ($is_admin_or_viewer): ?>
                        <td class="center"><?= htmlspecialchars($r['nama_kelas'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($r['nama_guru'] ?? '-') ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<!-- TANDA TANGAN -->
<div class="ttd-section">
    <div class="ttd-box">
        <p class="ttd-date"><?= htmlspecialchars($school_city) ?>, <?= htmlspecialchars($report_date) ?></p>
        <p class="ttd-role"><?= htmlspecialchars($jabatan_ttd) ?>,</p>
        <?php
        $show_qr = false;
        $qr_url  = '';
        if ($is_admin_or_viewer && !empty($ttd_kepala)) {
            $show_qr = true;
            $qrData  = 'Validasi TTD: ' . $penanda_tangan . ' - ' . ($school_profile['nama_madrasah'] ?? 'Madrasah');
            $qr_url  = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qrData);
        } elseif (!$is_admin_or_viewer && $penanda_tangan && $penanda_tangan !== '.........................') {
            $show_qr = true;
            $qrData  = 'Validasi TTD: ' . $penanda_tangan . ' - ' . ($school_profile['nama_madrasah'] ?? 'Madrasah');
            $qr_url  = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qrData);
        }
        ?>
        <div class="ttd-qr">
            <?php if ($show_qr): ?>
                <img src="<?= $qr_url ?>" alt="QR Tanda Tangan">
            <?php else: ?>
                <div class="ttd-space"></div>
            <?php endif; ?>
        </div>
        <p class="ttd-name"><?= htmlspecialchars($penanda_tangan) ?></p>
    </div>
</div>

<script>
// Tunggu semua gambar load dengan timeout fallback, lalu print
window.addEventListener('load', function() {
    var images = Array.from(document.querySelectorAll('img'));
    if (images.length === 0) {
        setTimeout(function() { window.print(); }, 300);
        return;
    }

    var done = 0;
    var total = images.length;
    var printed = false;

    function tryPrint() {
        if (printed) return;
        printed = true;
        setTimeout(function() { window.print(); }, 300);
    }

    images.forEach(function(img) {
        if (img.complete) {
            done++;
            if (done >= total) tryPrint();
        } else {
            img.addEventListener('load',  function() { done++; if (done >= total) tryPrint(); });
            img.addEventListener('error', function() { done++; if (done >= total) tryPrint(); });
        }
    });

    // Fallback: print paksa setelah 4 detik walau gambar belum selesai
    setTimeout(tryPrint, 4000);
});
</script>

</body>
</html>