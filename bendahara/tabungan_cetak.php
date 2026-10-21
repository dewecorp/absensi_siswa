<?php
/**
 * Halaman cetak laporan tabungan.
 * ?jenis=laporan&dari=&sampai=  -> Laporan periodik
 * ?jenis=kas                    -> Laporan saldo kas
 * ?jenis=siswa&nisn=            -> Laporan tabungan per siswa
 * ?export=csv                   -> unduh CSV (jenis=laporan)
 */

require_once __DIR__ . '/tabungan/config/config.php';

$jenis = (string)($_GET['jenis'] ?? 'laporan');
$profile = getSchoolProfile($pdo);
$logo_src = tabungan_logo_src($profile);
$nama_madrasah = (string)($profile['nama_madrasah'] ?? 'Madrasah');
$alamat = (string)($profile['alamat'] ?? '');
$tahun_ajaran = trim((string)($profile['tahun_ajaran'] ?? ''));
if ($tahun_ajaran === '') {
    $tahun_ajaran = tabungan_tahun_ajaran_aktif();
}
$nama_bendahara = tabungan_nama_bendahara();

$rows = [];
$total_setor = 0;
$total_tarik = 0;
$judul = 'Laporan Tabungan Siswa';
$subjudul = '';
$extra = '';

/* ---------------- Siapkan data ---------------- */
if ($jenis === 'kas') {
    $judul = 'Laporan Saldo Kas Tabungan';
    $subjudul = 'Seluruh periode';
    $total = tabungan_total($pdo);
    $total_setor = (int)$total['total_setor'];
    $total_tarik = (int)$total['total_tarik'];
} elseif ($jenis === 'siswa') {
    $nisn = trim((string)($_GET['nisn'] ?? ''));
    $siswa = tabungan_siswa($pdo, $nisn);
    $judul = 'Laporan Tabungan Siswa';
    $extra = 'NISN: ' . $nisn . ' &bull; Nama: ' . (string)($siswa['nama_siswa'] ?? '-') . ' &bull; Kelas: ' . (string)($siswa['nama_kelas'] ?? '-');
    if ($siswa) {
        $rows = tabungan_transaksi($pdo, ['nisn' => $nisn]);
    }
} else {
    $dari = trim((string)($_GET['dari'] ?? date('Y-m-01')));
    $sampai = trim((string)($_GET['sampai'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari)) {
        $dari = date('Y-m-01');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) {
        $sampai = date('Y-m-d');
    }
    if ($dari > $sampai) {
        $sampai = $dari;
    }
    $subjudul = 'Periode: ' . tabungan_tanggal_indo($dari) . ' s/d ' . tabungan_tanggal_indo($sampai);
    $rows = tabungan_transaksi($pdo, ['dari' => $dari, 'sampai' => $sampai]);
}

/* ---------------- Total ---------------- */
foreach ($rows as $r) {
    $total_setor += (int)$r['setor'];
    $total_tarik += (int)$r['tarik'];
}
$saldo = $total_setor - $total_tarik;

/* ---------------- Export CSV ---------------- */
if (($jenis === 'laporan' || $jenis === 'siswa') && (string)($_GET['export'] ?? '') === 'csv') {
    $filename = 'Laporan_Tabungan_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['No', 'Tanggal', 'NISN', 'Nama Siswa', 'Kelas', 'Pemasukan', 'Pengeluaran', 'Admin/Bendahara']);
    $no = 0;
    foreach ($rows as $r) {
        $no++;
        fputcsv($out, [
            $no,
            tabungan_tanggal_indo((string)$r['tgl']),
            (string)$r['nisn'],
            (string)($r['nama_siswa'] ?? '-'),
            (string)($r['nama_kelas'] ?? '-'),
            (int)$r['setor'] > 0 ? (string)(int)$r['setor'] : '-',
            (int)$r['tarik'] > 0 ? (string)(int)$r['tarik'] : '-',
            tabungan_aktor_label($r['petugas'] ?? '-'),
        ]);
    }
    fputcsv($out, ['', '', '', '', 'Total', (string)$total_setor, (string)$total_tarik, '']);
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($judul) ?></title>
    <style>
        body {
            font-family: "Segoe UI", Tahoma, Arial, sans-serif;
            color: #0f172a;
            margin: 24px;
            font-size: 13px;
        }
        .cetak-head { display: flex; align-items: center; gap: 14px; border-bottom: 3px double #0f172a; padding-bottom: 10px; margin-bottom: 16px; }
        .cetak-head img { width: 78px; height: 78px; object-fit: contain; }
        .cetak-head h1 { font-size: 18px; font-weight: 800; margin: 0 0 3px; text-transform: uppercase; }
        .cetak-head p { margin: 0; font-size: 12px; color: #475569; }
        .judul-laporan { text-align: center; margin: 10px 0 6px; }
        .judul-laporan h2 { font-size: 15px; font-weight: 800; text-transform: uppercase; margin: 0 0 3px; }
        .judul-laporan p { margin: 0; font-size: 12px; color: #475569; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #cbd5e1; padding: 6px 8px; font-size: 12px; }
        th { background: #f1f5f9; font-weight: 700; text-align: left; }
        td.r, th.r { text-align: right; }
        td.c, th.c { text-align: center; }
        tfoot td { background: #f8fafc; font-weight: 800; }
        .ttd { width: 45%; }
        .ttd-kiri { width: 55%; }
        .kota { text-align: right; font-size: 12px; }
        .tgl-cetak { font-size: 11px; color: #64748b; margin-top: 6px; text-align: right; }
        .toolbar { margin-bottom: 14px; }
        @media print {
            body { margin: 0; }
            .toolbar { display: none !important; }
            .cetak-head { page-break-inside: avoid; }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <button type="button" class="btn btn-primary" onclick="window.print()" style="border-radius:10px;padding:.5rem 1rem;font-weight:700;">
        <i class="fas fa-print"></i> Cetak
    </button>
    <a href="<?= htmlspecialchars(app_base_path() . '/bendahara/' . ($jenis === 'siswa' ? 'tabungan_data.php?nisn=' . urlencode((string)($_GET['nisn'] ?? '')) : ($jenis === 'kas' ? 'tabungan_kas.php' : 'tabungan_laporan.php'))) ?>" class="btn btn-secondary" style="border-radius:10px;padding:.5rem 1rem;font-weight:700;">
        <i class="fas fa-arrow-left"></i> Kembali
    </a>
</div>

<div class="cetak-head">
    <?php if ($logo_src !== ''): ?>
        <img src="<?= htmlspecialchars($logo_src) ?>" alt="Logo">
    <?php endif; ?>
    <div>
        <h1><?= htmlspecialchars($nama_madrasah) ?></h1>
        <?php if ($alamat !== ''): ?>
            <p><?= htmlspecialchars($alamat) ?></p>
        <?php endif; ?>
        <p>Tahun Ajaran: <?= htmlspecialchars($tahun_ajaran) ?></p>
    </div>
</div>

<div class="judul-laporan">
    <h2><?= htmlspecialchars($judul) ?></h2>
    <?php if ($subjudul !== ''): ?>
        <p><?= htmlspecialchars($subjudul) ?></p>
    <?php endif; ?>
    <?php if ($extra !== ''): ?>
        <p><?= $extra ?></p>
    <?php endif; ?>
</div>

<?php if ($jenis === 'kas'): ?>
    <table>
        <tbody>
            <tr>
                <th style="width:55%;">Total Pemasukan (Setoran)</th>
                <td class="r"><?= tabungan_rupiah($total_setor) ?></td>
            </tr>
            <tr>
                <th>Total Pengeluaran (Penarikan)</th>
                <td class="r"><?= tabungan_rupiah($total_tarik) ?></td>
            </tr>
            <tr>
                <th>Saldo Akhir Kas</th>
                <td class="r" style="font-weight:800;font-size:14px;"><?= tabungan_rupiah($saldo) ?></td>
            </tr>
        </tbody>
    </table>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th class="c" style="width:5%;">No</th>
                <th style="width:14%;">Tanggal</th>
                <th style="width:12%;">NISN</th>
                <th style="width:24%;">Nama Siswa</th>
                <th class="r" style="width:15%;">Pemasukan</th>
                <th class="r" style="width:15%;">Pengeluaran</th>
                <th style="width:15%;">Admin/Bendahara</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="7" class="c">Tidak ada transaksi.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td class="c"><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars(tabungan_tanggal_indo((string)$r['tgl'])) ?></td>
                        <td><?= htmlspecialchars((string)$r['nisn']) ?></td>
                        <td><?= htmlspecialchars((string)($r['nama_siswa'] ?? '-')) ?></td>
                        <td class="r"><?= (int)$r['setor'] > 0 ? tabungan_rupiah($r['setor']) : '-' ?></td>
                        <td class="r"><?= (int)$r['tarik'] > 0 ? tabungan_rupiah($r['tarik']) : '-' ?></td>
                        <td><?= htmlspecialchars(tabungan_aktor_label($r['petugas'] ?? '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="4" class="r">Total Setoran</th>
                <th class="r"><?= tabungan_rupiah($total_setor) ?></th>
                <th class="r"><?= tabungan_rupiah($total_tarik) ?></th>
                <th>Saldo: <?= tabungan_rupiah($saldo) ?></th>
            </tr>
        </tfoot>
    </table>
<?php endif; ?>

<table style="margin-top:28px;border:0;">
    <tr>
        <td class="ttd-kiri" style="border:0;"></td>
        <td class="ttd" style="border:0;">
            <div class="kota"><?= htmlspecialchars(date('d', strtotime(date('Y-m-d'))) . ' ' . tabungan_nama_bulan((int)date('n')) . ' ' . date('Y')) ?></div>
            <div style="text-align:center;margin-top:6px;">
                Bendahara,<br><br><br>
                <b><?= htmlspecialchars($nama_bendahara) ?></b>
            </div>
        </td>
    </tr>
</table>

<div class="tgl-cetak">Dicetak pada: <?= htmlspecialchars(date('d/m/Y H:i:s')) ?></div>

<script>
window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 350);
});
</script>
</body>
</html>
