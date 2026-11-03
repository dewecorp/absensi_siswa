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
    SELECT t.*, m.nama_mapel, k.nama_kelas, g.nama_guru
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

$stSoal = $pdo->prepare("SELECT * FROM tb_tugas_soal WHERE id_tugas = ? ORDER BY id ASC");
$stSoal->execute([$id_tugas]);
$soal_list = $stSoal->fetchAll(PDO::FETCH_ASSOC);

$stS = $pdo->prepare("
    SELECT s.id_siswa, s.nama_siswa, s.nisn,
           tp.id AS id_pengumpulan, tp.catatan_siswa, tp.tgl_kumpul, tp.nilai
    FROM tb_siswa s
    LEFT JOIN tb_tugas_pengumpulan tp ON tp.id_siswa = s.id_siswa AND tp.id_tugas = ?
    WHERE s.id_kelas = ?
    ORDER BY s.nama_siswa ASC
");
$stS->execute([$id_tugas, $tugas['id_kelas']]);
$students = $stS->fetchAll(PDO::FETCH_ASSOC);

$responden_list = [];
$total_skor = 0;
$skor_list = [];
foreach ($students as $s) {
    if (!empty($s['tgl_kumpul'])) {
        $raw_ans = [];
        if (!empty($s['catatan_siswa'])) {
            $dec = json_decode($s['catatan_siswa'], true);
            if (is_array($dec)) $raw_ans = $dec;
        }
        $val_nilai = $s['nilai'] !== null ? (float)$s['nilai'] : 0;
        $responden_list[] = [
            'id_siswa' => $s['id_siswa'],
            'nama_siswa' => $s['nama_siswa'],
            'nilai' => $val_nilai,
            'jawaban' => $raw_ans,
        ];
        $total_skor += $val_nilai;
        $skor_list[] = $val_nilai;
    }
}
$total_resp = count($responden_list);
$jml_siswa = count($students);
$avg_nilai = $total_resp > 0 ? round($total_skor / $total_resp, 1) : 0;
$max_nilai = !empty($skor_list) ? max($skor_list) : 0;
$min_nilai = !empty($skor_list) ? min($skor_list) : 0;

$rekap_kesukaran = ['Mudah' => 0, 'Sedang' => 0, 'Sukar' => 0];
$analisis_rows = [];

foreach ($soal_list as $idx => $soal) {
    $sId = $soal['id'];
    $tipe = $soal['tipe_soal'];
    $kunci = trim((string)$soal['kunci_jawaban']);
    $bobot = (int)($soal['bobot'] ?? 10);

    $distribusi = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0, 'Lainnya' => 0];
    $jml_benar = 0;
    $jml_salah = 0;

    foreach ($responden_list as $resp) {
        $jwb = $resp['jawaban'][$sId] ?? $resp['jawaban'][(string)$sId] ?? null;

        $is_benar = false;
        if ($tipe === 'Pilihan Ganda') {
            $jwb_clean = trim((string)$jwb);
            $opt_u = strtoupper($jwb_clean);
            if (in_array($opt_u, ['A', 'B', 'C', 'D', 'E'], true)) {
                $distribusi[$opt_u]++;
            } else {
                $distribusi['Lainnya']++;
            }
            if ($jwb_clean !== '' && strcasecmp($jwb_clean, $kunci) === 0) {
                $is_benar = true;
            }
        } elseif ($tipe === 'Pilihan Ganda Kompleks') {
            $k_arr = array_filter(array_map('strtoupper', array_map('trim', explode(',', $kunci))));
            $j_arr = array_filter(array_map('strtoupper', array_map('trim', explode(',', (string)$jwb))));
            if (!empty($j_arr)) {
                foreach ($j_arr as $opt) {
                    if (in_array($opt, ['A', 'B', 'C', 'D', 'E'], true)) $distribusi[$opt]++;
                }
            } else {
                $distribusi['Lainnya']++;
            }
            if (count($k_arr) > 0 && count(array_intersect($k_arr, $j_arr)) === count($k_arr) && count(array_diff($j_arr, $k_arr)) === 0) {
                $is_benar = true;
            }
        } else {
            $jwb_clean = trim((string)$jwb);
            if ($jwb_clean !== '') {
                $distribusi['Lainnya']++;
                if ($kunci !== '') {
                    similar_text(strtolower($jwb_clean), strtolower($kunci), $pct);
                    if ($pct >= 70.0) $is_benar = true;
                }
            } else {
                $distribusi['Lainnya']++;
            }
        }

        if ($is_benar) $jml_benar++;
        else $jml_salah++;
    }

    $p_val = $total_resp > 0 ? round($jml_benar / $total_resp, 2) : 0;
    $pct_benar = $total_resp > 0 ? round(($jml_benar / $total_resp) * 100, 1) : 0;
    $pct_salah = $total_resp > 0 ? round(($jml_salah / $total_resp) * 100, 1) : 0;

    if ($p_val > 0.70) {
        $kesukaran_label = 'Mudah';
        $rekap_kesukaran['Mudah']++;
    } elseif ($p_val >= 0.30) {
        $kesukaran_label = 'Sedang';
        $rekap_kesukaran['Sedang']++;
    } else {
        $kesukaran_label = 'Sukar';
        $rekap_kesukaran['Sukar']++;
    }

    $rekomendasi = 'Diterima';
    if ($total_resp > 0) {
        if ($p_val > 0.85) $rekomendasi = 'Terlalu Mudah';
        elseif ($p_val < 0.20) $rekomendasi = 'Terlalu Sulit';
        else $rekomendasi = 'Soal Baik';
    }

    $analisis_rows[] = [
        'no' => $idx + 1,
        'tipe_soal' => $tipe,
        'pertanyaan' => strip_tags((string)$soal['pertanyaan']),
        'kunci' => $kunci,
        'bobot' => $bobot,
        'benar' => $jml_benar,
        'pct_benar' => $pct_benar,
        'salah' => $jml_salah,
        'pct_salah' => $pct_salah,
        'distribusi' => $distribusi,
        'p_val' => $p_val,
        'kesukaran' => $kesukaran_label,
        'rekomendasi' => $rekomendasi,
    ];
}

$school = getSchoolProfile($pdo);
$nama_madrasah = $school['nama_madrasah'] ?? 'Madrasah Ibtidaiyah';
$alamat = $school['alamat'] ?? '';
$tahun_ajaran = $school['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1);
$tempat_jadwal = $school['tempat_jadwal'] ?? 'Jepara';
$guru_mapel = $tugas['nama_guru'] ?? '-';
$kepala_madrasah = $school['kepala_madrasah'] ?? 'Kepala Madrasah';
$nip_kepala = $school['nip_kepala'] ?? '-';

$qr_guru_content = "Validasi Tanda Tangan Digital: {$guru_mapel} - Guru Mata Pelajaran - {$nama_madrasah}";
$qr_guru_url = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qr_guru_content);

$qr_kepala_content = "Validasi Tanda Tangan Digital: {$kepala_madrasah} - Kepala Madrasah - {$nama_madrasah}";
$qr_kepala_url = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qr_kepala_content);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Analisis Butir Soal - <?= htmlspecialchars($tugas['judul']) ?></title>
    <style>
        @page { size: A4 landscape; margin: 12mm 15mm; }
        body { font-family: 'Times New Roman', serif; font-size: 11pt; color: #000; margin: 0; padding: 0; line-height: 1.3; }
        .kop { text-align: center; border-bottom: 3px double #000; padding-bottom: 6px; margin-bottom: 12px; }
        .kop h2 { margin: 0; font-size: 14pt; text-transform: uppercase; }
        .kop h3 { margin: 2px 0; font-size: 12pt; text-transform: uppercase; font-weight: normal; }
        .kop p { margin: 0; font-size: 9pt; }
        .title { text-align: center; font-size: 12pt; font-weight: bold; text-transform: uppercase; margin-bottom: 12px; }
        table.meta { width: 100%; margin-bottom: 12px; font-size: 10pt; }
        table.meta td { padding: 2px 4px; }
        table.data { width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 15px; }
        table.data th, table.data td { border: 1px solid #000; padding: 4px 5px; }
        table.data th { background: #f0f0f0; text-align: center; font-weight: bold; }
        table.data tr { page-break-inside: avoid; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .ttd-wrapper {
            margin-top: 25px;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }
        table.ttd {
            width: 100%;
            font-size: 10pt;
            border-collapse: collapse;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }
        table.ttd td {
            border: none;
            vertical-align: top;
            text-align: center;
        }
        .qr-ttd {
            width: 75px;
            height: 75px;
            margin: 6px auto;
            display: block;
        }
        .no-print { margin-bottom: 15px; }
        @media print {
            .no-print { display: none !important; }
            .ttd-wrapper, table.ttd {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
        }
    </style>
</head>
<body>
    <div class="no-print" style="text-align: right; padding: 10px 0;">
        <button onclick="window.print()" style="padding: 6px 16px; font-size: 12pt; background: #007bff; color: #fff; border: none; border-radius: 4px; cursor: pointer;">
            🖨️ Cetak / Simpan PDF
        </button>
        <button onclick="window.close()" style="padding: 6px 14px; font-size: 12pt; background: #6c757d; color: #fff; border: none; border-radius: 4px; cursor: pointer;">
            Tutup
        </button>
    </div>

    <div class="kop">
        <h2><?= htmlspecialchars($nama_madrasah) ?></h2>
        <h3>Analisis Butir Soal <?= htmlspecialchars($tugas['jenis_tugas']) ?></h3>
        <p><?= htmlspecialchars($alamat) ?></p>
    </div>

    <div class="title">LAPORAN ANALISIS BUTIR SOAL</div>

    <table class="meta">
        <tr>
            <td width="15%"><strong>Judul Tugas</strong></td><td width="35%">: <?= htmlspecialchars($tugas['judul']) ?></td>
            <td width="15%"><strong>Mata Pelajaran</strong></td><td width="35%">: <?= htmlspecialchars($tugas['nama_mapel'] ?? '-') ?></td>
        </tr>
        <tr>
            <td><strong>Kelas</strong></td><td>: <?= htmlspecialchars($tugas['nama_kelas'] ?? '-') ?></td>
            <td><strong>Guru Pengampu</strong></td><td>: <?= htmlspecialchars($guru_mapel) ?></td>
        </tr>
        <tr>
            <td><strong>Jumlah Soal</strong></td><td>: <?= count($soal_list) ?> Butir</td>
            <td><strong>Total Responden</strong></td><td>: <?= $total_resp ?> dari <?= $jml_siswa ?> Siswa</td>
        </tr>
        <tr>
            <td><strong>Rata-rata Nilai</strong></td><td>: <?= $avg_nilai ?> (Maks: <?= $max_nilai ?> | Min: <?= $min_nilai ?>)</td>
            <td><strong>Rekap Kesukaran</strong></td><td>: Mudah: <?= $rekap_kesukaran['Mudah'] ?>, Sedang: <?= $rekap_kesukaran['Sedang'] ?>, Sukar: <?= $rekap_kesukaran['Sukar'] ?></td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th rowspan="2" width="3%">No</th>
                <th rowspan="2" width="10%">Tipe Soal</th>
                <th rowspan="2">Pertanyaan Soal</th>
                <th rowspan="2" width="5%">Kunci</th>
                <th colspan="2">Jawaban Benar</th>
                <th colspan="2">Jawaban Salah</th>
                <th colspan="5">Penyebaran Opsi</th>
                <th colspan="2">Tingkat Kesukaran</th>
                <th rowspan="2" width="10%">Rekomendasi</th>
            </tr>
            <tr>
                <th width="4%">Jml</th>
                <th width="5%">%</th>
                <th width="4%">Jml</th>
                <th width="5%">%</th>
                <th width="3%">A</th>
                <th width="3%">B</th>
                <th width="3%">C</th>
                <th width="3%">D</th>
                <th width="3%">E</th>
                <th width="5%">Indeks (P)</th>
                <th width="7%">Kategori</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($analisis_rows as $r): ?>
                <tr>
                    <td class="text-center"><?= $r['no'] ?></td>
                    <td class="text-center"><?= htmlspecialchars($r['tipe_soal']) ?></td>
                    <td><?= htmlspecialchars($r['pertanyaan']) ?></td>
                    <td class="text-center"><strong><?= htmlspecialchars($r['kunci']) ?></strong></td>
                    <td class="text-center"><?= $r['benar'] ?></td>
                    <td class="text-center"><?= $r['pct_benar'] ?>%</td>
                    <td class="text-center"><?= $r['salah'] ?></td>
                    <td class="text-center"><?= $r['pct_salah'] ?>%</td>
                    <td class="text-center"><?= $r['distribusi']['A'] ?></td>
                    <td class="text-center"><?= $r['distribusi']['B'] ?></td>
                    <td class="text-center"><?= $r['distribusi']['C'] ?></td>
                    <td class="text-center"><?= $r['distribusi']['D'] ?></td>
                    <td class="text-center"><?= $r['distribusi']['E'] ?></td>
                    <td class="text-center"><?= number_format($r['p_val'], 2) ?></td>
                    <td class="text-center"><strong><?= $r['kesukaran'] ?></strong></td>
                    <td class="text-center"><?= $r['rekomendasi'] ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="ttd-wrapper">
        <table class="ttd">
            <tr>
                <td width="50%">
                    Mengetahui,<br>Kepala Madrasah<br>
                    <img src="<?= $qr_kepala_url ?>" alt="QR TTD Kepala" class="qr-ttd">
                    <strong><u><?= htmlspecialchars($kepala_madrasah) ?></u></strong><br>
                    NIP. <?= htmlspecialchars($nip_kepala) ?>
                </td>
                <td width="50%">
                    <?= htmlspecialchars($tempat_jadwal) ?>, <?= date('d/m/Y') ?><br>Guru Mata Pelajaran<br>
                    <img src="<?= $qr_guru_url ?>" alt="QR TTD Guru" class="qr-ttd">
                    <strong><u><?= htmlspecialchars($guru_mapel) ?></u></strong>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
