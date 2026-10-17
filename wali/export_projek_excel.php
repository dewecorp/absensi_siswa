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
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

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

// Profil Madrasah
$school = getSchoolProfile($pdo);
$nama_yayasan = trim((string)($school['nama_yayasan'] ?? ''));
$nama_yayasan_upper = $nama_yayasan !== '' ? (function_exists('mb_strtoupper') ? mb_strtoupper($nama_yayasan, 'UTF-8') : strtoupper($nama_yayasan)) : '';
$nama_madrasah = $school['nama_madrasah'] ?? 'Madrasah Ibtidaiyah';
$nama_madrasah_upper = function_exists('mb_strtoupper') ? mb_strtoupper($nama_madrasah, 'UTF-8') : strtoupper($nama_madrasah);
$tahun_ajaran = $school['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1);
$semester = $school['semester'] ?? 'Semester 1';
$tempat_jadwal = $school['tempat_jadwal'] ?? 'Jepara';

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
$title = $is_single ? $p_first['nama_projek'] : 'REKAPITULASI PROJEK KOKURIKULER';
$filename = trim(preg_replace('/\s+/', '_', preg_replace('/[\\\\\/:*?"<>|]+/', '-', $nama_dokumen))) . ".xlsx";

$ss = new Spreadsheet();
$sh = $ss->getActiveSheet();
$sh->setTitle('Projek Kokurikuler');

$lastCol = $is_single ? 'E' : 'I';

// Header Dokumen
$hdr_start = 1;
if ($nama_yayasan_upper !== '') {
    $sh->mergeCells('A1:' . $lastCol . '1');
    $sh->setCellValue('A1', $nama_yayasan_upper);
    $sh->getStyle('A1')->getFont()->setBold(true)->setSize(11);
    $sh->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $hdr_start = 2;
}

$sh->mergeCells('A' . $hdr_start . ':' . $lastCol . $hdr_start);
$sh->setCellValue('A' . $hdr_start, $nama_madrasah_upper);
$sh->mergeCells('A' . ($hdr_start + 1) . ':' . $lastCol . ($hdr_start + 1));
$sh->setCellValue('A' . ($hdr_start + 1), $title);
$sh->mergeCells('A' . ($hdr_start + 2) . ':' . $lastCol . ($hdr_start + 2));
$sh->setCellValue('A' . ($hdr_start + 2), 'Tahun Ajaran: ' . $tahun_ajaran . ' (' . $semester . ') | Wali Kelas: ' . $nama_wali . ' | Dicetak: ' . date('d/m/Y H:i'));

$sh->getStyle('A' . $hdr_start)->getFont()->setBold(true)->setSize(14);
$sh->getStyle('A' . $hdr_start)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sh->getStyle('A' . ($hdr_start + 1))->getFont()->setBold(true)->setSize(12);
$sh->getStyle('A' . ($hdr_start + 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sh->getStyle('A' . ($hdr_start + 2))->getFont()->setItalic(true)->setSize(10);
$sh->getStyle('A' . ($hdr_start + 2))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$row = $hdr_start + 4;

if ($is_single) {
foreach ($projek_list as $p) {
    $anggota = $anggota_by_projek[(int)$p['id']] ?? [];
    $groups = $projek_group_anggota($anggota);

    // Info Projek
    $info = 'Nama Projek: ' . $p['nama_projek'] . '  |  Tema: ' . $p['tema']
        . '  |  Kelas: ' . ($p['nama_kelas'] ?? '-')
        . '  |  Pembimbing: ' . ($p['pembimbing'] ?? '-')
        . '  |  Status: ' . $p['status'];
    $sh->mergeCells('A' . $row . ':' . $lastCol . $row);
    $sh->setCellValue('A' . $row, $info);
    $sh->getStyle('A' . $row)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $row++;

    // Periode
    $periode = '';
    if (!empty($p['tgl_mulai'])) $periode .= 'Mulai: ' . date('d/m/Y', strtotime($p['tgl_mulai']));
    if (!empty($p['tgl_selesai'])) $periode .= ($periode ? '  |  ' : '') . 'Selesai: ' . date('d/m/Y', strtotime($p['tgl_selesai']));
    $periode .= ($periode ? '  |  ' : '') . 'Jumlah Siswa: ' . count($anggota) . ' orang';
    $sh->mergeCells('A' . $row . ':' . $lastCol . $row);
    $sh->setCellValue('A' . $row, $periode);
    $sh->getStyle('A' . $row)->getFont()->setItalic(true)->setSize(10);
    $row++;

    if (!empty($p['deskripsi'])) {
        $sh->mergeCells('A' . $row . ':' . $lastCol . $row);
        $sh->setCellValue('A' . $row, 'Tujuan: ' . trim(strip_tags($p['deskripsi'])));
        $sh->getStyle('A' . $row)->getFont()->setSize(10);
        $sh->getStyle('A' . $row)->getAlignment()->setWrapText(true);
        $sh->getRowDimension($row)->setRowHeight(30);
        $row++;
    }

    if (empty($groups)) {
        $sh->mergeCells('A' . $row . ':' . $lastCol . $row);
        $sh->setCellValue('A' . $row, 'Belum ada anggota / kelompok pada projek ini.');
        $sh->getStyle('A' . $row)->getFont()->setItalic(true)->getColor()->setRGB('64748B');
        $sh->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;
    } else {
        foreach ($groups as $kelompok => $members) {
            // Judul kelompok
            $sh->mergeCells('A' . $row . ':' . $lastCol . $row);
            $sh->setCellValue('A' . $row, $kelompok . ' (' . count($members) . ' siswa)');
            $sh->getStyle('A' . $row)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => '1E3A8A']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DBEAFE']],
            ]);
            $row++;

            // Header tabel anggota
            $headers = ['No', 'Nama Siswa', 'NISN', 'Peran', 'Catatan'];
            foreach ($headers as $idx => $h) {
                $sh->setCellValue(Coordinate::stringFromColumnIndex($idx + 1) . $row, $h);
            }
            $hdrRow = $row;
            $sh->getStyle('A' . $hdrRow . ':' . $lastCol . $hdrRow)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A8A']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $row++;

            $no = 1;
            foreach ($members as $a) {
                $sh->setCellValue('A' . $row, $no++);
                $sh->setCellValue('B' . $row, $a['nama_siswa'] ?? '-');
                $sh->setCellValue('C' . $row, !empty($a['nisn']) ? ' ' . $a['nisn'] : '-');
                $sh->setCellValue('D' . $row, $a['peran'] ?? 'Anggota');
                $sh->setCellValue('E' . $row, !empty($a['catatan']) ? $a['catatan'] : '-');

                $sh->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sh->getStyle('C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sh->getStyle('D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $row++;
            }
            $endDataRow = $row - 1;

            $sh->getStyle('A' . $hdrRow . ':' . $lastCol . $endDataRow)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']]],
            ]);
            $row++;
        }
    }

    $row += 1; // jarak antar projek
    }

    // Lebar kolom (mode per projek)
    $sh->getColumnDimension('A')->setWidth(6);
    $sh->getColumnDimension('B')->setWidth(30);
    $sh->getColumnDimension('C')->setWidth(16);
    $sh->getColumnDimension('D')->setWidth(14);
    $sh->getColumnDimension('E')->setWidth(34);
} else {
    // Rekap semua projek: cukup nama projek & identitasnya (tanpa kelompok/anggota)
    $headers = ['No', 'Nama Projek', 'Tema', 'Kelas', 'Pembimbing', 'Mulai', 'Selesai', 'Jml Siswa', 'Status'];
    $lastRekapCol = Coordinate::stringFromColumnIndex(count($headers));
    foreach ($headers as $idx => $h) {
        $sh->setCellValue(Coordinate::stringFromColumnIndex($idx + 1) . $row, $h);
    }
    $hdrRow = $row;
    $sh->getStyle('A' . $hdrRow . ':' . $lastRekapCol . $hdrRow)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A8A']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $row++;

    $no = 1;
    foreach ($projek_list as $p) {
        $jml = count($anggota_by_projek[(int)$p['id']] ?? []);
        $sh->setCellValue('A' . $row, $no++);
        $sh->setCellValue('B' . $row, $p['nama_projek']);
        $sh->setCellValue('C' . $row, $p['tema']);
        $sh->setCellValue('D' . $row, $p['nama_kelas'] ?? '-');
        $sh->setCellValue('E' . $row, $p['pembimbing'] ?? '-');
        $sh->setCellValue('F' . $row, !empty($p['tgl_mulai']) ? date('d/m/Y', strtotime($p['tgl_mulai'])) : '-');
        $sh->setCellValue('G' . $row, !empty($p['tgl_selesai']) ? date('d/m/Y', strtotime($p['tgl_selesai'])) : '-');
        $sh->setCellValue('H' . $row, $jml);
        $sh->setCellValue('I' . $row, $p['status']);

        foreach (['A', 'D', 'F', 'G', 'H', 'I'] as $cc) {
            $sh->getStyle($cc . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $row++;
    }

    $sh->getStyle('A' . $hdrRow . ':' . $lastRekapCol . ($row - 1))->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']]],
    ]);

    // Lebar kolom (mode rekap semua projek)
    $sh->getColumnDimension('A')->setWidth(6);
    $sh->getColumnDimension('B')->setWidth(40);
    $sh->getColumnDimension('C')->setWidth(26);
    $sh->getColumnDimension('D')->setWidth(10);
    $sh->getColumnDimension('E')->setWidth(24);
    $sh->getColumnDimension('F')->setWidth(13);
    $sh->getColumnDimension('G')->setWidth(13);
    $sh->getColumnDimension('H')->setWidth(10);
    $sh->getColumnDimension('I')->setWidth(14);
}

// Tanda Tangan
$sig_c1 = $is_single ? 'D' : 'H';
$sig_c2 = $is_single ? 'E' : 'I';
$row += 1;
$sh->mergeCells($sig_c1 . $row . ':' . $sig_c2 . $row);
$sh->setCellValue($sig_c1 . $row, $tempat_jadwal . ', ' . date('d F Y'));
$row++;
$sh->mergeCells($sig_c1 . $row . ':' . $sig_c2 . $row);
$sh->setCellValue($sig_c1 . $row, 'Wali Kelas,');
$row += 4;
$sh->mergeCells($sig_c1 . $row . ':' . $sig_c2 . $row);
$sh->setCellValue($sig_c1 . $row, $nama_wali);
$sh->getStyle($sig_c1 . $row)->getFont()->setBold(true)->setUnderline(true);

// Pengaturan Cetak: F4 (Folio), rekap semua projek = landscape
if ($is_single) {
    $sh->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
} else {
    $sh->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
}
$sh->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_FOLIO); // F4
$sh->getPageSetup()->setFitToWidth(1);
$sh->getPageSetup()->setFitToHeight(0);
$sh->getPageSetup()->setFitToPage(true);

while (ob_get_level()) { ob_end_clean(); }

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($ss);
$writer->save('php://output');
exit;
