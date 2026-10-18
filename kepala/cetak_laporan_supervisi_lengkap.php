<?php
/**
 * Laporan Pelaksanaan Supervisi (lengkap, untuk akreditasi & pengawas).
 * Semua data ditarik dinamis dari modul supervisi.
 * Print-view: buka di tab baru lalu Cetak / Simpan sebagai PDF.
 *
 * Parameter: ?tahun_ajaran=2025/2026&semester=Semester%201&autoprint=1
 */
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$school_profile = getSchoolProfile($pdo);
$periode = sv_periode($pdo);

$ta_berjalan = trim((string)($_GET['tahun_ajaran'] ?? $periode['tahun_ajaran']));

// Hitung 1 tahun ajaran sebelumnya untuk analisis data hasil supervisi
// Contoh: TA berjalan 2026/2027 -> TA lalu yang dilaporkan hasilnya 2025/2026
if (preg_match('/^(\d{4})\/(\d{4})$/', $ta_berjalan, $m_ta)) {
    $y1 = (int)$m_ta[1] - 1;
    $y2 = (int)$m_ta[2] - 1;
    $ta_lalu = sprintf('%04d/%04d', $y1, $y2);
} else {
    $ta_lalu = $ta_berjalan;
}

// Laporan mencakup seluruh periode (tanpa filter semester default).
$filter_sem = isset($_GET['semester']) ? trim((string)$_GET['semester']) : '';
$filter_jenis = trim((string)($_GET['jenis'] ?? ''));
if (!in_array($filter_jenis, ['Akademik', 'Administrasi'], true)) {
    $filter_jenis = '';
}
$autoprint = isset($_GET['autoprint']);

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('nf')) {
    function nf($v, $d = 2) { return $v === null || $v === '' ? '-' : number_format((float)$v, $d); }
}

$school_name = strtoupper((string)($school_profile['nama_madrasah'] ?? 'MADRASAH'));
$foundation_name = strtoupper((string)($school_profile['nama_yayasan'] ?? ''));
$school_address = (string)($school_profile['alamat'] ?? '');
$email = (string)($school_profile['email_madrasah'] ?? '');
$website = (string)($school_profile['website_madrasah'] ?? '');
$kepala_madrasah = (string)($school_profile['nama_kepala'] ?? $school_profile['kepala_madrasah'] ?? '-');
$nip_kepala = (string)($school_profile['nip_kepala'] ?? '');
$tempat = (string)($school_profile['tempat_jadwal'] ?? '');

$logo_path = '../assets/img/' . basename((string)($school_profile['logo'] ?? 'logo.png'));
if (!is_readable(__DIR__ . '/' . $logo_path)) {
    $logo_path = '../assets/img/logo.png';
    if (!is_readable(__DIR__ . '/' . $logo_path)) {
        $cand = glob(__DIR__ . '/../assets/img/logo_*.png') ?: [];
        $logo_path = $cand ? '../assets/img/' . basename($cand[0]) : '';
    }
}

$months = ['January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret', 'April' => 'April', 'May' => 'Mei', 'June' => 'Juni', 'July' => 'Juli', 'August' => 'Agustus', 'September' => 'September', 'October' => 'Oktober', 'November' => 'November', 'December' => 'Desember'];
$tanggal_indo = trim($tempat . ($tempat !== '' ? ', ' : '') . date('d') . ' ' . $months[date('F')] . ' ' . date('Y'));
$hari_indo = ['Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'];

// Rentang tanggal TA BERJALAN (untuk Program, Sasaran, Jadwal di BAB I & II)
$rta_berjalan = getRentangTanggalTahunAjaran($ta_berjalan);
$parts_b = explode('/', $ta_berjalan);
$start_berjalan = $rta_berjalan['mulai'] ?? (($parts_b[0] ?? date('Y')) . '-07-01');
$end_berjalan = $rta_berjalan['sampai'] ?? (($parts_b[1] ?? ((int)date('Y') + 1)) . '-06-30');

// Rentang tanggal TA LALU (untuk Pelaksanaan, Nilai, Analisis di BAB III & IV)
$rta_lalu = getRentangTanggalTahunAjaran($ta_lalu);
$parts_l = explode('/', $ta_lalu);
$start_lalu = $rta_lalu['mulai'] ?? (($parts_l[0] ?? (date('Y') - 1)) . '-07-01');
$end_lalu = $rta_lalu['sampai'] ?? (($parts_l[1] ?? (int)date('Y')) . '-06-30');

// ================= AMBIL DATA =================
// 1. Program, Sasaran & Jadwal -> TAHUN BERJALAN
$sasaran = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM tb_sv_sasaran WHERE tahun_ajaran = ? ORDER BY nama_guru ASC");
    $stmt->execute([$ta_berjalan]);
    $sasaran = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$total_guru = count($sasaran);
if ($total_guru === 0) {
    try { $total_guru = (int)$pdo->query("SELECT COUNT(*) FROM tb_guru")->fetchColumn(); } catch (Throwable $e) {}
}

$jadwal = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM tb_sv_jadwal WHERE tanggal BETWEEN ? AND ? ORDER BY tanggal ASC, jam_mulai ASC");
    $stmt->execute([$start_berjalan, $end_berjalan]);
    $jadwal = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// 2. Pelaksanaan, Penilaian, Temuan, Tindak Lanjut -> TAHUN SEBELUMNYA
$pelaksanaan = [];
try {
    $stmt = $pdo->prepare("SELECT p.*, i.nama_instrumen FROM tb_sv_pelaksanaan p
        LEFT JOIN tb_sv_instrumen i ON i.id_instrumen = p.id_instrumen
        WHERE p.tanggal BETWEEN ? AND ? AND p.jenis_supervisi <> 'Manajerial'"
        . ($filter_jenis !== '' ? " AND p.jenis_supervisi = ?" : '')
        . " ORDER BY p.nama_guru ASC, p.tanggal ASC");
    $stmt->execute($filter_jenis !== '' ? [$start_lalu, $end_lalu, $filter_jenis] : [$start_lalu, $end_lalu]);
    $pelaksanaan = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$penMap = [];
$ids = array_column($pelaksanaan, 'id_pelaksanaan');
if ($ids) {
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM tb_sv_penilaian WHERE id_pelaksanaan IN ($in) ORDER BY id_penilaian ASC");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $penMap[(int)$r['id_pelaksanaan']][] = $r;
        }
    } catch (Throwable $e) {}
}

$tindak = [];
if ($ids) {
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM tb_sv_tindak_lanjut WHERE id_pelaksanaan IN ($in) ORDER BY target_selesai ASC, id_tindak_lanjut ASC");
        $stmt->execute($ids);
        $tindak = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

$instrumen_used = [];
try {
    $stmt = $pdo->prepare("SELECT DISTINCT i.* FROM tb_sv_instrumen i
        JOIN tb_sv_pelaksanaan p ON p.id_instrumen = i.id_instrumen
        WHERE p.tanggal BETWEEN ? AND ? ORDER BY i.jenis_supervisi ASC, i.nama_instrumen ASC");
    $stmt->execute([$start_lalu, $end_lalu]);
    $instrumen_used = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ================= OLAH DATA =================
if (!function_exists('sv_rep_fase')) {
    function sv_rep_fase($komponen) {
        $k = strtolower((string)$komponen);
        if (strpos($k, 'asesmen') !== false || strpos($k, 'penilaian') !== false) return 'asesmen';
        if (strpos($k, 'perencanaan') !== false || strpos($k, 'persiapan') !== false || strpos($k, 'modul') !== false
            || strpos($k, 'rpp') !== false || strpos($k, 'atp') !== false || strpos($k, 'administrasi') !== false
            || strpos($k, 'dokumen') !== false) return 'perencanaan';
        return 'pelaksanaan';
    }
}

$rekap = [];
$sumCol = ['perencanaan' => [0, 0], 'pelaksanaan' => [0, 0], 'asesmen' => [0, 0], 'akhir' => [0, 0]];
$kekuatanFreq = [];
$kelemahanFreq = [];
$prioritasFreq = [];

foreach ($pelaksanaan as $p) {
    $rows = $penMap[(int)$p['id_pelaksanaan']] ?? [];
    $sums = ['perencanaan' => [0.0, 0], 'pelaksanaan' => [0.0, 0], 'asesmen' => [0.0, 0]];
    foreach ($rows as $r) {
        $namaK = trim((string)($r['komponen'] ?? ''));
        if ($namaK === '') $namaK = (string)($r['komponen_master'] ?? '');
        $f = sv_rep_fase($namaK);
        $sums[$f][0] += (float)$r['nilai'];
        $sums[$f][1]++;
    }
    $avg = [];
    foreach ($sums as $k => $v) {
        $avg[$k] = $v[1] > 0 ? round($v[0] / $v[1], 2) : null;
    }
    $num = 0.0; $den = 0.0;
    if ($avg['perencanaan'] !== null) { $num += $avg['perencanaan'] * 0.30; $den += 0.30; }
    if ($avg['pelaksanaan'] !== null) { $num += $avg['pelaksanaan'] * 0.50; $den += 0.50; }
    if ($avg['asesmen'] !== null) { $num += $avg['asesmen'] * 0.20; $den += 0.20; }
    $akhirCalc = $den > 0 ? round($num / $den, 2) : null;
    $akhir = $p['nilai'] !== null ? (float)$p['nilai'] : $akhirCalc;

    $namaSubjek = $p['nama_guru'] ?: ($p['unit_bagian'] ?: '-');
    $rekap[] = [
        'nama' => $namaSubjek,
        'mapel' => (string)$p['mapel_di_supervisi'],
        'instrumen' => (string)($p['nama_instrumen'] ?? ''),
        'perencanaan' => $avg['perencanaan'],
        'pelaksanaan' => $avg['pelaksanaan'],
        'asesmen' => $avg['asesmen'],
        'akhir' => $akhir,
        'predikat' => $p['predikat'] ?: ($akhir !== null ? sv_predikat($akhir) : '-'),
        'jenis' => (string)$p['jenis_supervisi'],
    ];

    foreach (['perencanaan', 'pelaksanaan', 'asesmen'] as $k) {
        if ($avg[$k] !== null) { $sumCol[$k][0] += $avg[$k]; $sumCol[$k][1]++; }
    }
    if ($akhir !== null) { $sumCol['akhir'][0] += $akhir; $sumCol['akhir'][1]++; }

    // frekuensi temuan
    $addFreq = function (&$bucket, $text) {
        $text = trim((string)$text);
        if ($text === '') return;
        foreach (preg_split('/\s*,\s*/', $text) as $item) {
            $item = trim($item);
            if ($item === '') continue;
            if (!isset($bucket[$item])) $bucket[$item] = 0;
            $bucket[$item]++;
        }
    };
    $addFreq($kekuatanFreq, $p['kekuatan'] ?? '');
    $addFreq($kelemahanFreq, $p['kelemahan'] ?? '');
    $addFreq($prioritasFreq, $p['prioritas_perbaikan'] ?? '');
}

$avgCol = [];
foreach ($sumCol as $k => $v) {
    $avgCol[$k] = $v[1] > 0 ? round($v[0] / $v[1], 2) : null;
}
$rataSatuan = $avgCol['akhir'];

arsort($kekuatanFreq);
arsort($kelemahanFreq);
arsort($prioritasFreq);

$nPelaksanaan = count($rekap);
$predikatCount = [];
foreach ($rekap as $r) {
    $kat = $r['predikat'];
    if ($kat === '' || $kat === null) $kat = '-';
    if (!isset($predikatCount[$kat])) $predikatCount[$kat] = 0;
    $predikatCount[$kat]++;
}

$jenisPrograms = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM tb_sv_program WHERE tahun_ajaran = ? ORDER BY jenis_supervisi ASC, nama_program ASC");
    $stmt->execute([$ta_berjalan]);
    $jenisPrograms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$judulTA = $ta_berjalan;
$judulTALalu = $ta_lalu;

// Judul jenis supervisi untuk cover & narasi
$judulJenis = match ($filter_jenis) {
    'Akademik'     => 'Akademik',
    'Administrasi' => 'Administrasi',
    default        => 'Akademik dan Administrasi',
};

// Tanggal penyusunan laporan (formal, bulan + tahun saat ini)
$bulanTahunDisusun = $months[date('F')] . ' ' . date('Y');

// ================= PENGATURAN LAPORAN =================
$latar_belakang    = sv_pengaturan_get($pdo, 'laporan_latar_belakang');
$landasan_hukum    = sv_pengaturan_get($pdo, 'laporan_landasan_hukum');
$tujuan_supervisi  = sv_pengaturan_get($pdo, 'laporan_tujuan_supervisi');

$default_latar = '<p>Mutu pendidikan di madrasah sangat dipengaruhi oleh kualitas proses belajar mengajar di ruang kelas. Guru sebagai garda terdepan pembelajaran membutuhkan bimbingan, pendampingan, dan refleksi berkala guna menjaga serta meningkatkan kompetensi pedagogik maupun profesionalnya. Kepala madrasah memegang peranan strategis melalui supervisi akademik klinis&mdash;bukan untuk mencari kesalahan (inspectorial), melainkan sebagai kemitraan reflektif dalam mencari solusi bersama atas kendala pembelajaran.</p><p>Pelaksanaan supervisi akademik di ' . h($school_name) . ' pada Tahun Ajaran ' . h($judulTA) . ' dilaksanakan secara terencana, terprogram, dan berkelanjutan sebagai bagian dari upaya penjaminan mutu pembelajaran.</p>';
$default_landasan  = '<ol><li>Undang-Undang Nomor 20 Tahun 2003 tentang Sistem Pendidikan Nasional.</li><li>Peraturan Menteri Agama (PMA) Nomor 58 Tahun 2017 tentang Kepala Madrasah.</li><li>Permendikbudristek Nomor 16 Tahun 2022 tentang Standar Proses pada Pendidikan Anak Usia Dini, Jenjang Pendidikan Dasar, dan Jenjang Pendidikan Menengah.</li><li>Keputusan Menteri Agama (KMA) Nomor 450 Tahun 2024 tentang Pedoman Implementasi Kurikulum pada Madrasah.</li><li>Panduan Akreditasi Lembaga/Satuan Pendidikan dari BAN-PDM.</li></ol>';
$default_tujuan    = '<ol><li>Mengidentifikasi ketercapaian perencanaan dan administrasi pembelajaran guru (Kurikulum Merdeka/K13).</li><li>Membina kemampuan guru dalam mengelola pembelajaran yang berpusat pada peserta didik, diferensiasi, dan pemanfaatan media/TPACK.</li><li>Mengembangkan sistem asesmen autentik (formatif dan sumatif) yang objektif dan terukur.</li><li>Menghasilkan rekomendasi tindak lanjut berupa pelatihan, <strong>in-house training</strong> (IHT), atau pembinaan KKG/Kombel.</li></ol>';

$latar_belakang   = ($latar_belakang   !== null && trim($latar_belakang)   !== '') ? $latar_belakang   : $default_latar;
$landasan_hukum   = ($landasan_hukum   !== null && trim($landasan_hukum)   !== '') ? $landasan_hukum   : $default_landasan;
$tujuan_supervisi = ($tujuan_supervisi !== null && trim($tujuan_supervisi)  !== '') ? $tujuan_supervisi : $default_tujuan;

// ================= KESIMPULAN & REKOMENDASI dari DATA =================
// Menarik data supervisi tahun sebelumnya ($judulTALalu).
// Jika tahun sebelumnya belum ada data supervisi, BAB V kosong.
if ($nPelaksanaan === 0) {
    $kesimpulan_html = '<p class="small">Belum ada pelaksanaan supervisi yang tercatat pada Tahun Ajaran ' . h($judulTALalu) . ($filter_sem !== '' ? ' ' . h($filter_sem) : '') . ', sehingga kesimpulan pencapaian kinerja belum dapat dirumuskan.</p>';
    $rekomendasi_html = '<p class="small">Belum ada rekomendasi tindak lanjut karena data hasil supervisi Tahun Ajaran sebelumnya (' . h($judulTALalu) . ') belum tersedia.</p>';
} else {
    // Kesimpulan berbasis data tahun sebelumnya
    $kes_parts = [];
    $kes_parts[] = 'Berdasarkan evaluasi pelaksanaan supervisi ' . strtolower($judulJenis)
        . ' di ' . h($school_name) . ' pada Tahun Ajaran ' . h($judulTALalu)
        . ($filter_sem !== '' ? ' ' . h($filter_sem) : '')
        . ' yang mencakup <strong>' . (int)$nPelaksanaan . ' kegiatan</strong> supervisi:';
    if ($rataSatuan !== null) {
        $kes_parts[] = 'Rata-rata nilai kinerja pembelajaran guru mencapai <strong>' . nf($rataSatuan) . '</strong> dengan predikat kategori <strong>' . h(sv_predikat($rataSatuan)) . '</strong>.';
    }
    if ($predikatCount) {
        $rincian = [];
        foreach ($predikatCount as $pred => $jml) {
            $rincian[] = h($pred) . ' (' . (int)$jml . ' guru)';
        }
        $kes_parts[] = 'Sebaran predikat: ' . implode(', ', $rincian) . '.';
    }
    if ($kekuatanFreq) {
        $topK = array_key_first($kekuatanFreq);
        $kes_parts[] = 'Kekuatan utama yang teridentifikasi adalah aspek <strong>' . h($topK) . '</strong>.';
    }
    if ($kelemahanFreq) {
        $topL = array_key_first($kelemahanFreq);
        $kes_parts[] = 'Area yang masih memerlukan peningkatan terutama pada aspek <strong>' . h($topL) . '</strong>.';
    }
    $kesimpulan_html = '<p>' . implode(' ', $kes_parts) . '</p>';

    // Rekomendasi untuk TA BERJALAN ($judulTA) berbasis temuan TA LALU ($judulTALalu)
    $rek_items = [];
    if ($kelemahanFreq) {
        $tops = array_slice(array_keys($kelemahanFreq), 0, 3);
        $rek_items[] = 'Pembinaan intensif pada Tahun Ajaran ' . h($judulTA) . ' difokuskan pada aspek kelemahan: <strong>' . implode(', </strong><strong>', array_map('htmlspecialchars', $tops)) . '</strong>.';
    }
    if ($prioritasFreq) {
        $topP = array_key_first($prioritasFreq);
        $rek_items[] = 'Prioritas perbaikan utama berdasarkan evaluasi TA ' . h($judulTALalu) . ' adalah <strong>' . h($topP) . '</strong>; kepala madrasah menjadwalkan tindak lanjut terstruktur pada program supervisi Tahun Ajaran ' . h($judulTA) . '.';
    }
    $rek_items[] = 'Penguatan komunitas belajar intra-madrasah (Kombel/KKG) untuk berbagi praktik baik pembelajaran berdiferensiasi secara rutin pada TA ' . h($judulTA) . '.';
    if ($tindak) {
        $belum = array_filter($tindak, fn($t) => strtolower((string)($t['status'] ?? '')) !== 'selesai');
        if (count($belum) > 0) {
            $rek_items[] = 'Terdapat <strong>' . count($belum) . ' rencana tindak lanjut</strong> dari TA ' . h($judulTALalu) . ' yang belum tuntas; perlu monitoring prioritas pada awal semester TA ' . h($judulTA) . '.';
        }
    }
    $rek_items[] = 'Pengarsipan dokumen supervisi (instrumen, bukti observasi, tindak lanjut) dilakukan secara tertib sebagai bukti fisik akreditasi.';
    if ($total_guru > $nPelaksanaan) {
        $sisaGuru = $total_guru - $nPelaksanaan;
        $rek_items[] = 'Terdapat <strong>' . (int)$sisaGuru . ' guru</strong> yang belum tersupervisi pada TA ' . h($judulTALalu) . '; diprioritaskan masuk sasaran supervisi Tahun Ajaran ' . h($judulTA) . '.';
    }
    $rekomendasi_html = '<ol>' . implode('', array_map(fn($item) => '<li>' . $item . '</li>', $rek_items)) . '</ol>';
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Laporan Pelaksanaan Supervisi <?= h($judulTA) ?></title>
<style>
@page { size: A4 portrait; margin: 0; }
html, body { margin: 0; padding: 0; }
:root { --cx: 0.62cm; }
body { font-family: Arial, Helvetica, sans-serif; font-size: 12pt; line-height: 1.6; color: #111; background: #e5e7eb; }
h1, h2, h3, h4 { font-family: Arial, Helvetica, sans-serif; }
.sheet { width: 210mm; min-height: 297mm; box-sizing: border-box; padding: 18mm 20mm 20mm 25mm; position: relative; background: #fff; margin: 0 auto; }
.sheet .flow { height: 259mm; overflow: hidden; }
.sheet .pgnum { position: absolute; bottom: 9mm; right: 20mm; text-align: right; font-size: 11pt; color: #333; }
@media screen {
    body { padding: 16px 0; }
    .sheet { margin: 14px auto; box-shadow: 0 0 8px rgba(0,0,0,.18); }
}
@media print {
    body { background: #fff; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .no-print { display: none !important; }
    .sheet { margin: 0; box-shadow: none; page-break-after: always; }
    .sheet:last-of-type { page-break-after: auto; }
    .avoid-break { page-break-inside: avoid; }
}
.cover { text-align: center; }
.cover .logo { height: 90px; margin-bottom: 8px; }
.cover h2 { margin: 2px 0; font-size: 15pt; text-transform: uppercase; }
.cover h3 { margin: 2px 0; font-size: 13pt; font-weight: normal; }
.cover .addr { font-size: 10.5pt; color: #333; margin-bottom: 26px; }
.cover .doc-title { font-size: 17pt; font-weight: bold; margin: 60px 0 6px; text-transform: uppercase; line-height: 1.4; }
.cover .doc-sub { font-size: 13pt; margin-bottom: 40px; }
.cover .meta { font-size: 12pt; margin-top: 60px; }
.cover .meta .school { font-weight: bold; font-size: 14pt; margin-top: 8px; }
.page-header { display: flex; align-items: center; padding-bottom: 6px; border-bottom: 2px solid #000; margin-bottom: 14px; }
.page-header img { height: 58px; margin-right: 14px; }
.page-header .ht { flex: 1; text-align: center; }
.page-header .ht h2 { margin: 0; font-size: 13pt; text-transform: uppercase; }
.page-header .ht h1 { margin: 0; font-size: 14pt; text-transform: uppercase; }
.page-header .ht p { margin: 1px 0; font-size: 9.5pt; color: #333; }
h1.bab { font-size: 14pt; text-align: center; text-transform: uppercase; margin: 26px 0 10px; }
h2.sub { font-size: 12pt; margin: 18px 0 6px; }
h3.subsub { font-size: 11.5pt; margin: 14px 0 5px; font-weight: bold; padding-left: var(--cx); }
p { text-align: justify; margin: 6px 0; text-indent: 1.25cm; }
ol, ul { margin: 6px 0 6px var(--cx); padding: 0; list-style: none; counter-reset: cxli; }
li { margin: 3px 0; text-align: justify; position: relative; padding-left: var(--cx); }
ol > li { counter-increment: cxli; }
ol > li::before { content: counter(cxli) "."; position: absolute; left: 0; top: 0; width: var(--cx); }
table { width: 100%; border-collapse: collapse; margin: 10px 0 14px; }
th, td { border: 1px solid #333; padding: 4px 6px; vertical-align: top; font-size: 12pt; }
th { background: #e9e9e9; text-align: center; font-weight: bold; }
td.c { text-align: center; }
td.r { text-align: right; }
/* Isi tiap sub sejajar dengan teks judul (setelah angka/huruf) */
.sub .mk, .subsub .mk { display: inline-block; width: var(--cx); }
p, table, .muted-box { margin-left: var(--cx); }
table { max-width: calc(100% - var(--cx)); margin-left: var(--cx) !important; }
.cover p, .ttd p, .page-header p, p.no-indent, p.center, .muted-box p { text-indent: 0; }
.cover p, .ttd p, .page-header p, p.no-indent, p.center, .muted-box p, .p-pengantar { margin-left: 0; }
.ind3 { margin-left: calc(2 * var(--cx)); }
.toc { margin-left: 4px; }
.toc div { display: flex; align-items: baseline; margin: 3px 0; }
.toc .txt { }
.toc .dots { flex: 1; border-bottom: 1px dotted #666; margin: 0 6px; }
.toc .pg { min-width: 2.5em; text-align: right; }
.toc .lvl1 { font-weight: bold; }
.toc .lvl2 { margin-left: 22px; }
.small { font-size: 9pt; color: #444; }
.ttd { margin-top: 40px; display: flex; justify-content: space-between; page-break-inside: avoid; }
.ttd .col { width: 45%; text-align: center; }
.ttd .space { height: 70px; }
.ttd .name { font-weight: bold; text-decoration: underline; margin-bottom: 0; }
.ttd .nip { margin-top: 2px; }
.badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 9pt; background: #eee; }
.print-btn { position: fixed; top: 16px; right: 16px; padding: 10px 18px; background: #1d4ed8; color: #fff; border: none; border-radius: 6px; cursor: pointer; z-index: 9999; font-size: 12pt; box-shadow: 0 2px 8px rgba(0,0,0,.25); }
.print-btn:hover { background: #1e40af; }
.center { text-align: center; }
.muted-box { background: #f7f7f7; border-left: 3px solid #999; padding: 8px 12px; margin: 8px 0; }
</style>
</head>
<body>
<button class="print-btn no-print" onclick="window.print()">Cetak / Simpan PDF</button>

<div id="report-content">
<!-- ================= COVER ================= -->
<div class="cover">
    <?php if ($logo_path): ?><img class="logo" src="<?= h($logo_path) ?>" alt="Logo"><?php endif; ?>
    <h2><?= h($school_name) ?></h2>
    <?php if ($foundation_name !== ''): ?><h3><?= h($foundation_name) ?></h3><?php endif; ?>
    <?php if ($school_address !== ''): ?><div class="addr"><?= h($school_address) ?></div><?php endif; ?>

    <div class="doc-title">Laporan Pelaksanaan<br>Supervisi <?= h($judulJenis) ?></div>
    <div class="doc-sub">Tahun Ajaran <?= h($judulTA) ?><?= $filter_sem !== '' ? ' &middot; ' . h($filter_sem) : '' ?></div>

    <div class="meta">
        <div>Disusun sebagai bentuk akuntabilitas kinerja manajerial Kepala Madrasah</div>
        <div>serta pemenuhan bukti fisik Akreditasi Madrasah</div>
        <div class="school"><?= h($school_name) ?></div>
        <div>Tahun <?= h(date('Y')) ?></div>
    </div>
</div>

<!-- ================= KATA PENGANTAR ================= -->
<div class="page-break"></div>
<h1 class="bab" data-sec="kata-pengantar">Kata Pengantar</h1>
<p class="p-pengantar">Puji syukur kami panjatkan ke hadirat Allah SWT atas rahmat dan karunia-Nya sehingga Laporan Pelaksanaan Supervisi Akademik Kepala Madrasah <?= h($school_name) ?> Tahun Ajaran <?= h($judulTA) ?> ini dapat diselesaikan dengan baik.</p>
<p class="p-pengantar">Supervisi akademik merupakan salah satu fungsi manajerial dan kepemimpinan kepala madrasah yang bertujuan membantu pendidik mengembangkan kemampuannya dalam mencapai tujuan pembelajaran, meningkatkan kualitas proses pembelajaran, serta memfasilitasi ketercapaian kompetensi peserta didik. Laporan ini disusun sebagai bentuk akuntabilitas kinerja manajerial kepala madrasah serta pemenuhan bukti fisik standar pengelolaan dan proses pembelajaran untuk akreditasi madrasah.</p>
<p class="p-pengantar">Terima kasih kami sampaikan kepada seluruh dewan guru dan tenaga kependidikan yang telah bekerja sama secara terbuka dan profesional selama kegiatan supervisi berlangsung. Kritik dan saran yang membangun senantiasa kami harapkan demi kesempurnaan pelaksanaan supervisi di masa mendatang.</p>
<div class="ttd">
    <div class="col"></div>
    <div class="col">
        <p><?= h($tanggal_indo) ?></p>
        <p>Kepala Madrasah,</p>
        <div class="space"></div>
        <p class="name"><?= h($kepala_madrasah) ?></p>
        <?php if ($nip_kepala !== '' && $nip_kepala !== '-'): ?><p class="nip">NIP. <?= h($nip_kepala) ?></p><?php endif; ?>
    </div>
</div>

<!-- ================= DAFTAR ISI ================= -->
<div class="page-break"></div>
<h1 class="bab" data-sec="daftar-isi">Daftar Isi</h1>
<div class="toc">
    <div class="lvl1"><span class="txt">Kata Pengantar</span><span class="dots"></span><span class="pg" data-toc="kata-pengantar"></span></div>
    <div class="lvl1"><span class="txt">Daftar Isi</span><span class="dots"></span><span class="pg" data-toc="daftar-isi"></span></div>
    <div class="lvl1"><span class="txt">BAB I &mdash; PENDAHULUAN</span><span class="dots"></span><span class="pg" data-toc="bab1"></span></div>
    <div class="lvl2"><span class="txt">A. Latar Belakang</span><span class="dots"></span><span class="pg" data-toc="bab1a"></span></div>
    <div class="lvl2"><span class="txt">B. Landasan Hukum</span><span class="dots"></span><span class="pg" data-toc="bab1b"></span></div>
    <div class="lvl2"><span class="txt">C. Tujuan Supervisi</span><span class="dots"></span><span class="pg" data-toc="bab1c"></span></div>
    <div class="lvl2"><span class="txt">D. Ruang Lingkup dan Sasaran</span><span class="dots"></span><span class="pg" data-toc="bab1d"></span></div>
    <div class="lvl1"><span class="txt">BAB II &mdash; PROGRAM DAN JADWAL SUPERVISI</span><span class="dots"></span><span class="pg" data-toc="bab2"></span></div>
    <div class="lvl2"><span class="txt">A. Pendekatan dan Metode Supervisi</span><span class="dots"></span><span class="pg" data-toc="bab2a"></span></div>
    <div class="lvl2"><span class="txt">B. Tahapan / Siklus Supervisi</span><span class="dots"></span><span class="pg" data-toc="bab2b"></span></div>
    <div class="lvl2"><span class="txt">C. Jadwal Pelaksanaan Supervisi</span><span class="dots"></span><span class="pg" data-toc="bab2c"></span></div>
    <div class="lvl1"><span class="txt">BAB III &mdash; HASIL PELAKSANAAN SUPERVISI</span><span class="dots"></span><span class="pg" data-toc="bab3"></span></div>
    <div class="lvl2"><span class="txt">A. Rekapitulasi Nilai Supervisi</span><span class="dots"></span><span class="pg" data-toc="bab3a"></span></div>
    <div class="lvl2"><span class="txt">B. Rekapitulasi Kategori / Predikat</span><span class="dots"></span><span class="pg" data-toc="bab3b"></span></div>
    <div class="lvl2"><span class="txt">C. Rincian Instrumen yang Digunakan</span><span class="dots"></span><span class="pg" data-toc="bab3c"></span></div>
    <div class="lvl1"><span class="txt">BAB IV &mdash; ANALISIS, EVALUASI, DAN TINDAK LANJUT</span><span class="dots"></span><span class="pg" data-toc="bab4"></span></div>
    <div class="lvl2"><span class="txt">A. Analisis Temuan (Kekuatan dan Kelemahan)</span><span class="dots"></span><span class="pg" data-toc="bab4a"></span></div>
    <div class="lvl2"><span class="txt">B. Rencana Tindak Lanjut (RTL) Pembinaan</span><span class="dots"></span><span class="pg" data-toc="bab4b"></span></div>
    <div class="lvl1"><span class="txt">BAB V &mdash; PENUTUP</span><span class="dots"></span><span class="pg" data-toc="bab5"></span></div>
    <div class="lvl2"><span class="txt">A. Kesimpulan</span><span class="dots"></span><span class="pg" data-toc="bab5a"></span></div>
    <div class="lvl2"><span class="txt">B. Rekomendasi</span><span class="dots"></span><span class="pg" data-toc="bab5b"></span></div>
    <div class="lvl1"><span class="txt">Lampiran</span><span class="dots"></span><span class="pg" data-toc="lampiran"></span></div>
</div>

<!-- ================= BAB I ================= -->
<div class="page-break"></div>
<h1 class="bab" data-sec="bab1">BAB I<br>Pendahuluan</h1>

<h2 class="sub" data-sec="bab1a">A. Latar Belakang</h2>
<?= $latar_belakang ?>

<h2 class="sub" data-sec="bab1b">B. Landasan Hukum</h2>
<?= $landasan_hukum ?>

<h2 class="sub" data-sec="bab1c">C. Tujuan Supervisi</h2>
<?= $tujuan_supervisi ?>

<h2 class="sub" data-sec="bab1d">D. Ruang Lingkup dan Sasaran</h2>
<p>Sasaran pelaksanaan supervisi akademik ini mencakup seluruh pendidik pada <?= h($school_name) ?><?= $filter_sem !== '' ? ' pada ' . h($filter_sem) : '' ?> Tahun Ajaran <?= h($judulTA) ?>.</p>
<table class="avoid-break">
    <tr><th style="width:55%">Uraian</th><th>Jumlah</th></tr>
    <tr><td>Guru/PTK sasaran supervisi</td><td class="c"><?= (int)$total_guru ?> orang</td></tr>
    <tr><td>Jadwal supervisi tersusun</td><td class="c"><?= count($jadwal) ?> kegiatan</td></tr>
    <tr><td>Pelaksanaan supervisi (Akademik/Administrasi) TA Sebelumnya (<?= h($judulTALalu) ?>)</td><td class="c"><?= (int)$nPelaksanaan ?> kegiatan</td></tr>
    <tr><td>Instrumen yang digunakan (TA <?= h($judulTALalu) ?>)</td><td class="c"><?= count($instrumen_used) ?> instrumen</td></tr>
</table>
<h3 class="subsub">Daftar Guru Sasaran Supervisi</h3>
<table>
    <thead><tr><th style="width:6%">No</th><th>Nama Guru/PTK</th><th>Jabatan</th><th>Mata Pelajaran</th><th>Kelas</th><th>Status</th></tr></thead>
    <tbody>
    <?php if ($sasaran): ?>
        <?php foreach ($sasaran as $i => $s): ?>
            <tr>
                <td class="c"><?= $i + 1 ?></td>
                <td><?= h($s['nama_guru']) ?></td>
                <td><?= h($s['jabatan'] ?: '-') ?></td>
                <td><?= h($s['mata_pelajaran'] ?: '-') ?></td>
                <td><?= h($s['kelas'] ?: '-') ?></td>
                <td class="c"><?= h($s['status_supervisi'] ?: '-') ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="6" class="c text-muted" style="padding:12px;font-style:italic">Belum ada data guru sasaran supervisi pada periode ini.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<!-- ================= BAB II ================= -->
<div class="page-break"></div>
<h1 class="bab" data-sec="bab2">BAB II<br>Program dan Jadwal Supervisi</h1>

<h2 class="sub" data-sec="bab2a">A. Pendekatan dan Metode Supervisi</h2>
<p>Supervisi dilaksanakan dengan pendekatan klinis bersahabat (<strong>collegial clinical supervision</strong>) yang menekankan kemitraan, keterbukaan, dan fokus pada perbaikan pembelajaran, bukan penilaian yang bersifat menghukum.</p>
<h3 class="subsub">Program Kerja Supervisi</h3>
<table>
    <thead><tr><th style="width:5%">No</th><th>Jenis</th><th>Nama Program</th><th>Tujuan</th><th>Penanggung Jawab</th><th class="c">Status</th></tr></thead>
    <tbody>
    <?php if ($jenisPrograms): ?>
        <?php foreach ($jenisPrograms as $i => $pr): ?>
            <tr>
                <td class="c"><?= $i + 1 ?></td>
                <td><?= h($pr['jenis_supervisi']) ?></td>
                <td><?= h($pr['nama_program']) ?></td>
                <td><?= nl2br(h($pr['tujuan'] ?? '')) ?></td>
                <td><?= h($pr['penanggung_jawab'] ?: '-') ?></td>
                <td class="c"><?= h($pr['status'] ?: '-') ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="6" class="c text-muted" style="padding:12px;font-style:italic">Belum ada data program kerja supervisi pada periode ini.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<h2 class="sub" data-sec="bab2b">B. Tahapan / Siklus Supervisi</h2>
<ol>
    <li><strong>Pra-Observasi (Wawancara Perencanaan):</strong> reviu Modul Ajar/RPP, penetapan fokus observasi, dan instrumen yang digunakan.</li>
    <li><strong>Observasi Kelas:</strong> pengamatan langsung interaksi belajar mengajar di kelas menggunakan lembar instrumen observasi.</li>
    <li><strong>Pasca-Observasi (Refleksi Bersama):</strong> pemberian umpan balik konstruktif, analisis kekuatan/kelemahan, serta penyusunan kesepakatan tindak lanjut.</li>
</ol>

<h2 class="sub" data-sec="bab2c">C. Jadwal Pelaksanaan Supervisi</h2>
<table>
    <thead><tr><th style="width:5%">No</th><th>Nama Guru</th><th>Jenis</th><th>Hari, Tanggal</th><th>Waktu</th><th>Supervisor</th><th>Tempat</th></tr></thead>
    <tbody>
    <?php if ($jadwal): ?>
        <?php foreach ($jadwal as $i => $j):
            $tgl = $j['tanggal'] ? date('d/m/Y', strtotime($j['tanggal'])) : '-';
            $hari = $j['tanggal'] ? ($hari_indo[date('l', strtotime($j['tanggal']))] ?? '') : '';
            $waktu = ($j['jam_mulai'] ? substr($j['jam_mulai'], 0, 5) : '-') . ' - ' . ($j['jam_selesai'] ? substr($j['jam_selesai'], 0, 5) : '-');
        ?>
            <tr>
                <td class="c"><?= $i + 1 ?></td>
                <td><?= h($j['nama_guru'] ?: '-') ?></td>
                <td><?= h($j['jenis_supervisi'] ?: '-') ?></td>
                <td><?= h(($hari !== '' ? $hari . ', ' : '') . $tgl) ?></td>
                <td class="c"><?= h($waktu) ?></td>
                <td><?= h($j['supervisor'] ?: '-') ?></td>
                <td><?= h($j['tempat'] ?: '-') ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="7" class="c text-muted" style="padding:12px;font-style:italic">Belum ada data jadwal supervisi pada periode ini.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<!-- ================= BAB III ================= -->
<div class="page-break"></div>
<h1 class="bab" data-sec="bab3">BAB III<br>Hasil Pelaksanaan Supervisi<br>Tahun Ajaran <?= h($judulTALalu) ?></h1>

<h2 class="sub" data-sec="bab3a">A. Rekapitulasi Nilai Supervisi</h2>
<p class="small">Data evaluasi hasil supervisi Tahun Ajaran <?= h($judulTALalu) ?> sebagai dasar refleksi perencanaan. Kriteria Penilaian: 91&ndash;100 = Amat Baik (A); 81&ndash;90 = Baik (B); 71&ndash;80 = Cukup (C); &lt; 70 = Kurang (D). Kolom perencanaan, pelaksanaan, dan asesmen merupakan rata-rata capaian tiap fase (dihitung dari indikator).</p>
<table>
    <thead>
        <tr>
            <th style="width:5%">No</th>
            <th>Nama Guru</th>
            <th>Perencanaan (30%)</th>
            <th>Pelaksanaan (50%)</th>
            <th>Asesmen (20%)</th>
            <th>Nilai Akhir</th>
            <th>Kategori</th>
        </tr>
    </thead>
    <tbody>
    <?php if ($rekap): ?>
        <?php foreach ($rekap as $i => $r): ?>
            <tr>
                <td class="c"><?= $i + 1 ?></td>
                <td><?= h($r['nama']) ?><?= $r['mapel'] !== '' ? '<br><span class="small">' . h($r['mapel']) . '</span>' : '' ?></td>
                <td class="c"><?= nf($r['perencanaan']) ?></td>
                <td class="c"><?= nf($r['pelaksanaan']) ?></td>
                <td class="c"><?= nf($r['asesmen']) ?></td>
                <td class="c"><strong><?= nf($r['akhir']) ?></strong></td>
                <td class="c"><?= h($r['predikat']) ?></td>
            </tr>
        <?php endforeach; ?>
            <tr style="background:#f2f2f2;font-weight:bold">
                <td class="c" colspan="2">Rata-Rata Satuan</td>
                <td class="c"><?= nf($avgCol['perencanaan']) ?></td>
                <td class="c"><?= nf($avgCol['pelaksanaan']) ?></td>
                <td class="c"><?= nf($avgCol['asesmen']) ?></td>
                <td class="c"><?= nf($rataSatuan) ?></td>
                <td class="c"><?= h($rataSatuan !== null ? sv_predikat($rataSatuan) : '-') ?></td>
            </tr>
    <?php else: ?>
        <tr><td colspan="7" class="c text-muted" style="padding:12px;font-style:italic">Belum ada data pelaksanaan supervisi akademik/administrasi pada Tahun Ajaran sebelumnya (<?= h($judulTALalu) ?>).</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<h2 class="sub" data-sec="bab3b">B. Rekapitulasi Kategori / Predikat</h2>
<table class="avoid-break">
    <thead><tr><th style="width:10%">No</th><th>Kategori</th><th>Jumlah Guru/Supervisi</th><th>Persentase</th></tr></thead>
    <tbody>
    <?php if ($predikatCount): ?>
        <?php $no = 1; foreach ($predikatCount as $kat => $jml): ?>
            <tr>
                <td class="c"><?= $no++ ?></td>
                <td><?= h($kat) ?></td>
                <td class="c"><?= (int)$jml ?></td>
                <td class="c"><?= $nPelaksanaan > 0 ? nf($jml / $nPelaksanaan * 100, 1) : '0' ?>%</td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td class="c">1</td><td>Amat Baik (A)</td><td class="c">0</td><td class="c">0%</td></tr>
        <tr><td class="c">2</td><td>Baik (B)</td><td class="c">0</td><td class="c">0%</td></tr>
        <tr><td class="c">3</td><td>Cukup (C)</td><td class="c">0</td><td class="c">0%</td></tr>
        <tr><td class="c">4</td><td>Kurang (D)</td><td class="c">0</td><td class="c">0%</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<h2 class="sub" data-sec="bab3c">C. Rincian Instrumen yang Digunakan</h2>
<table>
    <thead><tr><th style="width:5%">No</th><th>Kode</th><th>Nama Instrumen</th><th>Jenis</th><th>Skala</th></tr></thead>
    <tbody>
    <?php if ($instrumen_used): ?>
        <?php foreach ($instrumen_used as $i => $ins): ?>
            <tr>
                <td class="c"><?= $i + 1 ?></td>
                <td><?= h($ins['kode_instrumen']) ?></td>
                <td><?= h($ins['nama_instrumen']) ?></td>
                <td class="c"><?= h($ins['jenis_supervisi']) ?></td>
                <td class="c"><?= h($ins['skala_penilaian']) ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="5" class="c text-muted" style="padding:12px;font-style:italic">Belum ada data instrumen yang digunakan pada Tahun Ajaran sebelumnya (<?= h($judulTALalu) ?>).</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<!-- ================= BAB IV ================= -->
<div class="page-break"></div>
<h1 class="bab" data-sec="bab4">BAB IV<br>Analisis, Evaluasi, dan Tindak Lanjut</h1>

<h2 class="sub" data-sec="bab4a">A. Analisis Temuan</h2>
<h3 class="subsub">1. Kekuatan</h3>
<table>
    <thead><tr><th style="width:6%">No</th><th>Aspek Kekuatan</th><th style="width:16%">Frekuensi</th><th style="width:16%">Persentase</th></tr></thead>
    <tbody>
    <?php if ($kekuatanFreq): ?>
        <?php $no = 1; foreach ($kekuatanFreq as $item => $jml): ?>
            <tr>
                <td class="c"><?= $no++ ?></td>
                <td><?= h($item) ?></td>
                <td class="c"><?= (int)$jml ?></td>
                <td class="c"><?= $nPelaksanaan > 0 ? nf($jml / $nPelaksanaan * 100, 1) : '0' ?>%</td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="4" class="c text-muted" style="padding:12px;font-style:italic">Belum ada catatan kekuatan pada data supervisi Tahun Ajaran sebelumnya (<?= h($judulTALalu) ?>).</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<h3 class="subsub">2. Area yang Memerlukan Peningkatan</h3>
<table>
    <thead><tr><th style="width:6%">No</th><th>Aspek Kelemahan / Kendala</th><th style="width:16%">Frekuensi</th><th style="width:16%">Persentase</th></tr></thead>
    <tbody>
    <?php if ($kelemahanFreq): ?>
        <?php $no = 1; foreach ($kelemahanFreq as $item => $jml): ?>
            <tr>
                <td class="c"><?= $no++ ?></td>
                <td><?= h($item) ?></td>
                <td class="c"><?= (int)$jml ?></td>
                <td class="c"><?= $nPelaksanaan > 0 ? nf($jml / $nPelaksanaan * 100, 1) : '0' ?>%</td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="4" class="c text-muted" style="padding:12px;font-style:italic">Belum ada catatan kelemahan / kendala pada data supervisi Tahun Ajaran sebelumnya (<?= h($judulTALalu) ?>).</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<h3 class="subsub">3. Prioritas Perbaikan</h3>
<table>
    <thead><tr><th style="width:6%">No</th><th>Prioritas Perbaikan</th><th style="width:16%">Frekuensi</th><th style="width:16%">Persentase</th></tr></thead>
    <tbody>
    <?php if ($prioritasFreq): ?>
        <?php $no = 1; foreach ($prioritasFreq as $item => $jml): ?>
            <tr>
                <td class="c"><?= $no++ ?></td>
                <td><?= h($item) ?></td>
                <td class="c"><?= (int)$jml ?></td>
                <td class="c"><?= $nPelaksanaan > 0 ? nf($jml / $nPelaksanaan * 100, 1) : '0' ?>%</td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="4" class="c text-muted" style="padding:12px;font-style:italic">Belum ada data prioritas perbaikan pada Tahun Ajaran sebelumnya (<?= h($judulTALalu) ?>).</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<h2 class="sub" data-sec="bab4b">B. Rencana Tindak Lanjut (RTL) Pembinaan</h2>
<table>
    <thead>
        <tr><th style="width:5%">No</th><th>Guru/Unit</th><th>Masalah / Temuan</th><th>Bentuk Kegiatan</th><th>Rencana Tindakan</th><th>Target Selesai</th><th>Penanggung Jawab</th><th>Status</th></tr>
    </thead>
    <tbody>
    <?php if ($tindak): ?>
        <?php foreach ($tindak as $i => $t): ?>
            <tr>
                <td class="c"><?= $i + 1 ?></td>
                <td><?= h($t['nama_guru'] ?: ($t['unit_bagian'] ?: '-')) ?></td>
                <td><?= h($t['temuan'] ?: '-') ?></td>
                <td><?= h($t['bentuk_tindak_lanjut'] ?: '-') ?></td>
                <td><?= h($t['rencana_tindakan'] ?: '-') ?></td>
                <td class="c"><?= $t['target_selesai'] ? date('d/m/Y', strtotime($t['target_selesai'])) : '-' ?></td>
                <td><?= h($t['penanggung_jawab'] ?: '-') ?></td>
                <td class="c"><?= h($t['status'] ?: '-') ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="8" class="c text-muted" style="padding:12px;font-style:italic">Belum ada data rencana tindak lanjut pembinaan. Tindak lanjut disusun berdasarkan temuan evaluasi.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<!-- ================= BAB V ================= -->
<div class="page-break"></div>
<h1 class="bab" data-sec="bab5">BAB V<br>Penutup</h1>

<h2 class="sub" data-sec="bab5a">A. Kesimpulan</h2>
<?= $kesimpulan_html ?>

<h2 class="sub" data-sec="bab5b">B. Rekomendasi</h2>
<?= $rekomendasi_html ?>

<!-- ================= LAMPIRAN ================= -->
<div class="page-break"></div>
<h1 class="bab" data-sec="lampiran">Lampiran</h1>
<p class="no-indent">Dokumen pendukung yang menyertai laporan ini:</p>
<ol>
    <li>Instrumen Pra-Observasi, Observasi Kelas, dan Pasca-Observasi.</li>
    <li>Rekapitulasi nilai supervisi per guru.</li>
    <li>Lembar tindak lanjut dan bukti monitoring.</li>
    <li>Dokumentasi kegiatan supervisi (foto/dokumen).</li>
</ol>

<h2 class="sub" data-sec="lampiran-instrumen">Daftar Instrumen Supervisi</h2>
<table>
    <thead><tr><th style="width:5%">No</th><th>Kode</th><th>Nama Instrumen</th><th>Jenis</th><th>Tujuan</th></tr></thead>
    <tbody>
    <?php if ($instrumen_used): ?>
        <?php foreach ($instrumen_used as $i => $ins): ?>
            <tr>
                <td class="c"><?= $i + 1 ?></td>
                <td><?= h($ins['kode_instrumen']) ?></td>
                <td><?= h($ins['nama_instrumen']) ?></td>
                <td class="c"><?= h($ins['jenis_supervisi']) ?></td>
                <td><?= h($ins['tujuan'] ?? '-') ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="5" class="c text-muted" style="padding:12px;font-style:italic">Belum ada data instrumen supervisi.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<!-- ================= PENGESAHAN ================= -->
<div class="ttd avoid-break" style="margin-top:30px">
    <div class="col">
        <p>Mengetahui,<br>Pengawas Madrasah</p>
        <div class="space"></div>
        <p class="name">&nbsp;</p>
        <p class="nip">NIP. ...........................................</p>
    </div>
    <div class="col">
        <p><?= h($tanggal_indo) ?><br>Kepala Madrasah,</p>
        <div class="space"></div>
        <p class="name"><?= h($kepala_madrasah) ?></p>
        <?php if ($nip_kepala !== '' && $nip_kepala !== '-'): ?><p class="nip">NIP. <?= h($nip_kepala) ?></p><?php endif; ?>
    </div>
</div>

<p class="small no-indent" style="margin-top:24px">Dicetak: <?= h(date('d-m-Y H:i')) ?> &middot; Sumber: Sistem Informasi Madrasah (SIMAD)</p>
</div><!-- /#report-content -->

<script>
(function () {
    'use strict';
    var content = document.getElementById('report-content');
    if (!content) return;

    var container = document.createElement('div');
    container.id = 'report-paged';
    content.parentNode.insertBefore(container, content.nextSibling);

    var sheets = [];
    var cur = null;

    function newSheet() {
        var sheet = document.createElement('div');
        sheet.className = 'sheet';
        var flow = document.createElement('div');
        flow.className = 'flow';
        var pg = document.createElement('div');
        pg.className = 'pgnum';
        sheet.appendChild(flow);
        sheet.appendChild(pg);
        container.appendChild(sheet);
        cur = { sheet: sheet, flow: flow, pg: pg };
        sheets.push(cur);
        return cur;
    }

    function overflows(flow) {
        return flow.scrollHeight > flow.clientHeight + 1;
    }

    function cloneTableWithHead(table) {
        var t = document.createElement('table');
        if (table.className) t.className = table.className;
        if (table.getAttribute('style')) t.setAttribute('style', table.getAttribute('style'));
        var cg = table.querySelector('colgroup');
        if (cg) t.appendChild(cg.cloneNode(true));
        if (table.tHead) t.appendChild(table.tHead.cloneNode(true));
        t.appendChild(document.createElement('tbody'));
        return t;
    }

    function isHeading(el) {
        if (!el || el.nodeType !== 1) return false;
        return /^H[1-6]$/i.test(el.tagName);
    }

    function pullTrailingHeadings(flow) {
        if (!flow) return [];
        var hasNonHeading = false;
        for (var i = 0; i < flow.children.length; i++) {
            if (!isHeading(flow.children[i])) {
                hasNonHeading = true;
                break;
            }
        }
        if (!hasNonHeading) return [];

        var pulled = [];
        while (flow.lastElementChild && isHeading(flow.lastElementChild)) {
            var h = flow.lastElementChild;
            flow.removeChild(h);
            pulled.unshift(h);
        }
        return pulled;
    }

    function splitTableInto(table, rows) {
        var idx = 0;
        while (idx < rows.length) {
            var t = cloneTableWithHead(table);
            cur.flow.appendChild(t);
            var body = t.tBodies[0];
            var placed = 0;
            while (idx < rows.length) {
                body.appendChild(rows[idx]);
                if (overflows(cur.flow)) {
                    if (body.rows.length > 1) {
                        body.removeChild(rows[idx]);
                        break;
                    } else {
                        idx++;
                        placed++;
                        break;
                    }
                }
                idx++;
                placed++;
            }
            if (idx < rows.length) {
                newSheet();
            }
        }
    }

    function appendTableSplitting(table) {
        var tbody = table.tBodies[0];
        var rows = tbody ? Array.prototype.slice.call(tbody.rows) : [];

        if (rows.length === 0 || table.classList.contains('avoid-break')) {
            cur.flow.appendChild(table);
            if (!overflows(cur.flow)) return;

            cur.flow.removeChild(table);
            var pulled = pullTrailingHeadings(cur.flow);
            newSheet();
            pulled.forEach(function (h) { cur.flow.appendChild(h); });
            cur.flow.appendChild(table);
            if (overflows(cur.flow)) {
                cur.flow.style.height = 'auto';
            }
            return;
        }

        var t = cloneTableWithHead(table);
        cur.flow.appendChild(t);
        var body = t.tBodies[0];
        body.appendChild(rows[0]);

        if (overflows(cur.flow)) {
            cur.flow.removeChild(t);
            var pulledHeadings = pullTrailingHeadings(cur.flow);
            newSheet();
            pulledHeadings.forEach(function (h) { cur.flow.appendChild(h); });
            splitTableInto(table, rows);
        } else {
            var idx = 1;
            while (idx < rows.length) {
                body.appendChild(rows[idx]);
                if (overflows(cur.flow)) {
                    body.removeChild(rows[idx]);
                    break;
                }
                idx++;
            }
            if (idx < rows.length) {
                newSheet();
                var remainingRows = rows.slice(idx);
                splitTableInto(table, remainingRows);
            }
        }
    }

    // Tandai isi di bawah sub-sub judul (h3) agar menjorok satu tingkat lagi
    var lvl = 1;
    Array.prototype.forEach.call(content.children, function (el) {
        var tg = el.tagName;
        if (tg === 'H1') { lvl = 1; return; }
        if (tg === 'H2') { lvl = 2; return; }
        if (tg === 'H3') { lvl = 3; return; }
        if (lvl === 3 && (tg === 'P' || tg === 'OL' || tg === 'UL')) {
            el.classList.add('ind3');
        }
    });

    // Pisahkan angka/huruf judul sub ke kotak tetap agar teks judul & isi sejajar
    Array.prototype.forEach.call(content.querySelectorAll('h2.sub, h3.subsub'), function (hd) {
        var t = hd.textContent;
        var m = t.match(/^([A-Za-z0-9]+\.)\s+(.*)$/);
        var marker = m ? m[1] : '';
        var rest = m ? m[2] : t;
        hd.textContent = '';
        var mk = document.createElement('span');
        mk.className = 'mk';
        mk.textContent = marker;
        hd.appendChild(mk);
        hd.appendChild(document.createTextNode(rest));
    });

    var nodes = Array.prototype.slice.call(content.children);
    newSheet();

    nodes.forEach(function (node) {
        if (node.nodeType !== 1) return;
        if (node.classList && node.classList.contains('page-break')) {
            if (cur && cur.flow.children.length === 0) return;
            newSheet();
            return;
        }
        var clone = node.cloneNode(true);

        if (clone.tagName === 'TABLE') {
            appendTableSplitting(clone);
            return;
        }

        cur.flow.appendChild(clone);
        if (!overflows(cur.flow)) return;

        cur.flow.removeChild(clone);

        if (cur.flow.children.length === 0) {
            cur.flow.appendChild(clone);
            cur.flow.style.height = 'auto';
            return;
        }

        var pulled = pullTrailingHeadings(cur.flow);
        newSheet();
        pulled.forEach(function (h) { cur.flow.appendChild(h); });
        cur.flow.appendChild(clone);
        if (overflows(cur.flow)) {
            cur.flow.style.height = 'auto';
        }
    });

    sheets = sheets.filter(function (s) {
        if (s.flow.children.length === 0) {
            if (s.sheet.parentNode) s.sheet.parentNode.removeChild(s.sheet);
            return false;
        }
        return true;
    });

    sheets.forEach(function (s, i) {
        s.pg.textContent = (i === 0) ? '' : (i + 1);
    });

    function sheetIndexOf(el) {
        var s = el.closest('.sheet');
        if (!s) return null;
        return Array.prototype.indexOf.call(container.children, s);
    }
    Array.prototype.forEach.call(container.querySelectorAll('.pg[data-toc]'), function (span) {
        var key = span.getAttribute('data-toc');
        var target = container.querySelector('[data-sec="' + key + '"]');
        if (!target) { span.textContent = ''; return; }
        var idx = sheetIndexOf(target);
        span.textContent = (idx === null) ? '' : (idx + 1);
    });

    content.style.display = 'none';
})();
</script>

<?php if ($autoprint): ?>
<script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 600); });</script>
<?php endif; ?>
</body>
</html>
