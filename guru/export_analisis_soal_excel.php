<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

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

// Fetch soal
$stSoal = $pdo->prepare("SELECT * FROM tb_tugas_soal WHERE id_tugas = ? ORDER BY id ASC");
$stSoal->execute([$id_tugas]);
$soal_list = $stSoal->fetchAll(PDO::FETCH_ASSOC);

if (empty($soal_list)) {
    echo "<script>alert('Belum ada butir soal pada tugas ini.'); window.history.back();</script>";
    exit;
}

// Fetch submissions
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
$total_skor_cbt = 0;
$skor_cbt_list = [];
foreach ($students as $s) {
    if (!empty($s['tgl_kumpul'])) {
        $raw_ans = [];
        if (!empty($s['catatan_siswa'])) {
            $decoded = json_decode($s['catatan_siswa'], true);
            if (is_array($decoded)) {
                $raw_ans = $decoded;
            }
        }
        $val_nilai = $s['nilai'] !== null ? (float)$s['nilai'] : 0;
        $responden_list[] = [
            'id_siswa' => $s['id_siswa'],
            'nama_siswa' => $s['nama_siswa'],
            'nilai' => $val_nilai,
            'jawaban' => $raw_ans,
        ];
        $total_skor_cbt += $val_nilai;
        $skor_cbt_list[] = $val_nilai;
    }
}
$total_responden_cbt = count($responden_list);
$jml_siswa = count($students);
$avg_nilai_cbt = $total_responden_cbt > 0 ? round($total_skor_cbt / $total_responden_cbt, 1) : 0;
$max_nilai_cbt = !empty($skor_cbt_list) ? max($skor_cbt_list) : 0;
$min_nilai_cbt = !empty($skor_cbt_list) ? min($skor_cbt_list) : 0;

// Kelompok Atas & Bawah untuk Daya Pembeda (bila responden >= 4)
$upper_grp = [];
$lower_grp = [];
if ($total_responden_cbt >= 4) {
    $sorted_resp = $responden_list;
    usort($sorted_resp, fn($a, $b) => $b['nilai'] <=> $a['nilai']);
    $half = (int)ceil($total_responden_cbt / 2);
    $upper_grp = array_slice($sorted_resp, 0, $half);
    $lower_grp = array_slice($sorted_resp, -$half);
}

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
                    if (in_array($opt, ['A', 'B', 'C', 'D', 'E'], true)) {
                        $distribusi[$opt]++;
                    }
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

        if ($is_benar) {
            $jml_benar++;
        } else {
            $jml_salah++;
        }
    }

    $p_val = $total_responden_cbt > 0 ? round($jml_benar / $total_responden_cbt, 2) : 0;
    $pct_benar = $total_responden_cbt > 0 ? round(($jml_benar / $total_responden_cbt) * 100, 1) : 0;
    $pct_salah = $total_responden_cbt > 0 ? round(($jml_salah / $total_responden_cbt) * 100, 1) : 0;

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

    $d_val = null;
    $d_label = '-';
    if ($total_responden_cbt >= 4) {
        $bu = 0;
        $bl = 0;
        foreach ($upper_grp as $u) {
            $uj = $u['jawaban'][$sId] ?? $u['jawaban'][(string)$sId] ?? null;
            if ($tipe === 'Pilihan Ganda' && $uj !== null && strcasecmp(trim((string)$uj), $kunci) === 0) $bu++;
            elseif ($tipe === 'Pilihan Ganda Kompleks') {
                $k_arr = array_filter(array_map('strtoupper', array_map('trim', explode(',', $kunci))));
                $j_arr = array_filter(array_map('strtoupper', array_map('trim', explode(',', (string)$uj))));
                if (count($k_arr) > 0 && count(array_intersect($k_arr, $j_arr)) === count($k_arr) && count(array_diff($j_arr, $k_arr)) === 0) $bu++;
            }
        }
        foreach ($lower_grp as $l) {
            $lj = $l['jawaban'][$sId] ?? $l['jawaban'][(string)$sId] ?? null;
            if ($tipe === 'Pilihan Ganda' && $lj !== null && strcasecmp(trim((string)$lj), $kunci) === 0) $bl++;
            elseif ($tipe === 'Pilihan Ganda Kompleks') {
                $k_arr = array_filter(array_map('strtoupper', array_map('trim', explode(',', $kunci))));
                $j_arr = array_filter(array_map('strtoupper', array_map('trim', explode(',', (string)$lj))));
                if (count($k_arr) > 0 && count(array_intersect($k_arr, $j_arr)) === count($k_arr) && count(array_diff($j_arr, $k_arr)) === 0) $bl++;
            }
        }
        $half_size = count($upper_grp);
        if ($half_size > 0) {
            $d_val = round(($bu - $bl) / $half_size, 2);
            if ($d_val >= 0.40) $d_label = 'Sangat Baik';
            elseif ($d_val >= 0.30) $d_label = 'Baik';
            elseif ($d_val >= 0.20) $d_label = 'Cukup';
            else $d_label = 'Kurang / Jelek';
        }
    }

    $rekomendasi = 'Diterima';
    if ($total_responden_cbt > 0) {
        if ($p_val > 0.85) $rekomendasi = 'Terlalu Mudah (Revisi Pengecoh)';
        elseif ($p_val < 0.20) $rekomendasi = 'Terlalu Sulit (Perlu Ditinjau)';
        elseif ($d_val !== null && $d_val < 0.20) $rekomendasi = 'Daya Pembeda Rendah';
        else $rekomendasi = 'Soal Baik (Diterima)';
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
        'opsi_a' => $distribusi['A'],
        'opsi_b' => $distribusi['B'],
        'opsi_c' => $distribusi['C'],
        'opsi_d' => $distribusi['D'],
        'opsi_e' => $distribusi['E'],
        'p_val' => $p_val,
        'kesukaran' => $kesukaran_label,
        'd_val' => $d_val !== null ? $d_val : '-',
        'daya_pembeda' => $d_label,
        'rekomendasi' => $rekomendasi,
    ];
}

$school = getSchoolProfile($pdo);
$nama_madrasah = $school['nama_madrasah'] ?? 'Madrasah Ibtidaiyah';
$tahun_ajaran = $school['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1);
$guru_mapel = $tugas['nama_guru'] ?? '-';

$title = "ANALISIS BUTIR SOAL " . strtoupper((string)$tugas['jenis_tugas']) . " - " . strtoupper((string)$tugas['judul']);
$filename = "Analisis_Soal_" . (int)$tugas['id'] . "_" . preg_replace('/[^A-Za-z0-9-]+/', '_', $tugas['judul']) . "_" . date('Ymd') . ".xlsx";

$ss = new Spreadsheet();
$sh = $ss->getActiveSheet();
$sh->setTitle('Analisis Butir Soal');

// Title & Meta
$sh->setCellValue('A1', strtoupper($nama_madrasah));
$sh->setCellValue('A2', $title);
$sh->setCellValue('A3', 'Mata Pelajaran: ' . ($tugas['nama_mapel'] ?? '-') . ' | Kelas: ' . ($tugas['nama_kelas'] ?? '-') . ' | Guru: ' . $guru_mapel . ' | Tahun Ajaran: ' . $tahun_ajaran);
$sh->setCellValue('A4', 'Total Responden: ' . $total_responden_cbt . ' dari ' . $jml_siswa . ' Siswa | Rata-rata Nilai: ' . $avg_nilai_cbt . ' | Nilai Maks: ' . $max_nilai_cbt . ' | Nilai Min: ' . $min_nilai_cbt . ' | Mudah: ' . $rekap_kesukaran['Mudah'] . ' | Sedang: ' . $rekap_kesukaran['Sedang'] . ' | Sukar: ' . $rekap_kesukaran['Sukar']);

$sh->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sh->getStyle('A2')->getFont()->setBold(true)->setSize(12);
$sh->getStyle('A3')->getFont()->setSize(10);
$sh->getStyle('A4')->getFont()->setItalic(true)->setSize(10);

$row = 6;
$headers = [
    'No', 'Tipe Soal', 'Pertanyaan Soal', 'Kunci', 'Bobot',
    'Benar (Orang)', '% Benar', 'Salah (Orang)', '% Salah',
    'Pemilih A', 'Pemilih B', 'Pemilih C', 'Pemilih D', 'Pemilih E',
    'Indeks Kesukaran (P)', 'Kategori Kesukaran',
    'Daya Pembeda (D)', 'Kategori Daya Pembeda',
    'Rekomendasi / Status'
];
$lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));

foreach ($headers as $idx => $h) {
    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
    $sh->setCellValue($colLetter . $row, $h);
}

$sh->getStyle('A' . $row . ':' . $lastCol . $row)->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
]);

$row++;
$startDataRow = $row;
foreach ($analisis_rows as $r) {
    $sh->setCellValue('A' . $row, $r['no']);
    $sh->setCellValue('B' . $row, $r['tipe_soal']);
    $sh->setCellValue('C' . $row, $r['pertanyaan']);
    $sh->setCellValue('D' . $row, $r['kunci']);
    $sh->setCellValue('E' . $row, $r['bobot']);
    $sh->setCellValue('F' . $row, $r['benar']);
    $sh->setCellValue('G' . $row, $r['pct_benar'] . '%');
    $sh->setCellValue('H' . $row, $r['salah']);
    $sh->setCellValue('I' . $row, $r['pct_salah'] . '%');
    $sh->setCellValue('J' . $row, $r['opsi_a']);
    $sh->setCellValue('K' . $row, $r['opsi_b']);
    $sh->setCellValue('L' . $row, $r['opsi_c']);
    $sh->setCellValue('M' . $row, $r['opsi_d']);
    $sh->setCellValue('N' . $row, $r['opsi_e']);
    $sh->setCellValue('O' . $row, $r['p_val']);
    $sh->setCellValue('P' . $row, $r['kesukaran']);
    $sh->setCellValue('Q' . $row, $r['d_val']);
    $sh->setCellValue('R' . $row, $r['daya_pembeda']);
    $sh->setCellValue('S' . $row, $r['rekomendasi']);

    $sh->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('F' . $row . ':N' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('O' . $row . ':R' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sh->getStyle('S' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

    // Kategori Kesukaran color styling
    $colorKesukaran = 'E2E8F0';
    if ($r['kesukaran'] === 'Mudah') $colorKesukaran = 'DCFCE7';
    elseif ($r['kesukaran'] === 'Sedang') $colorKesukaran = 'FEF9C3';
    elseif ($r['kesukaran'] === 'Sukar') $colorKesukaran = 'FEE2E2';

    $sh->getStyle('P' . $row)->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colorKesukaran]],
        'font' => ['bold' => true],
    ]);

    $row++;
}

$endDataRow = $row - 1;
$sh->getStyle('A' . $startDataRow . ':' . $lastCol . $endDataRow)->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);

foreach (range(1, count($headers)) as $colIdx) {
    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
    if ($colLetter === 'C') {
        $sh->getColumnDimension($colLetter)->setWidth(45);
    } elseif (in_array($colLetter, ['A', 'D', 'E'], true)) {
        $sh->getColumnDimension($colLetter)->setWidth(8);
    } else {
        $sh->getColumnDimension($colLetter)->setAutoSize(true);
    }
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($ss);
$writer->save('php://output');
exit;
