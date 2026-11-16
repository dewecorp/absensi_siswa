<?php
/**
 * Export Kelompok Belajar — Excel (.xlsx via PhpSpreadsheet / fallback .xls HTML)
 */
@ini_set('display_errors', '0');
error_reporting(0);

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

// Allowed class filter
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

$f_kelas  = (int)($_GET['kelas'] ?? 0);
$f_search = trim(sanitizeInput((string)($_GET['q'] ?? '')));

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
            k.nama_kelas,
            k.wali_kelas,
            sk.nama_siswa AS nama_ketua
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

// Anggota
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

$school = getSchoolProfile($pdo);
$nama_yayasan    = $school['nama_yayasan'] ?? '';
$nama_madrasah   = $school['nama_madrasah'] ?? 'MADRASAH';
$alamat_madrasah = $school['alamat'] ?? '';
$tahun_ajaran    = $school['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1);
$semester        = $school['semester'] ?? 'Semester 1';

$nama_kelas_filter = '';
if ($f_kelas > 0) {
    try {
        $stK = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas = ? LIMIT 1");
        $stK->execute([$f_kelas]);
        $nama_kelas_filter = (string)($stK->fetchColumn() ?: '');
    } catch (Throwable $e) {}
}

$filename_safe = 'Kelompok_Belajar_' . ($nama_kelas_filter ? ('Kelas_' . preg_replace('/[^A-Za-z0-9_-]/', '', $nama_kelas_filter) . '_') : '') . date('Ymd_His');

// Coba PhpSpreadsheet jika tersedia
$use_phpspreadsheet = false;
if (file_exists('../vendor/autoload.php')) {
    require_once '../vendor/autoload.php';
    if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
        $use_phpspreadsheet = true;
    }
}

if ($use_phpspreadsheet) {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Kelompok Belajar');

    // Header KOP
    $sheet->setCellValue('A1', strtoupper($nama_yayasan));
    $sheet->setCellValue('A2', strtoupper($nama_madrasah));
    $sheet->setCellValue('A3', $alamat_madrasah);
    $sheet->mergeCells('A1:G1');
    $sheet->mergeCells('A2:G2');
    $sheet->mergeCells('A3:G3');
    $sheet->getStyle('A1:A3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(13);

    // Judul
    $sheet->setCellValue('A5', 'DAFTAR KELOMPOK BELAJAR SISWA' . ($nama_kelas_filter ? (' - KELAS ' . strtoupper($nama_kelas_filter)) : ''));
    $sheet->mergeCells('A5:G5');
    $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(12);
    $sheet->getStyle('A5')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    $sheet->setCellValue('A6', 'Tahun Ajaran: ' . $tahun_ajaran . ' (' . $semester . ') | Tanggal Unduh: ' . date('d-m-Y H:i'));
    $sheet->mergeCells('A6:G6');
    $sheet->getStyle('A6')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    // Kolom tabel
    $headerRow = 8;
    $sheet->setCellValue('A' . $headerRow, 'NO');
    $sheet->setCellValue('B' . $headerRow, 'KELAS');
    $sheet->setCellValue('C' . $headerRow, 'NAMA KELOMPOK');
    $sheet->setCellValue('D' . $headerRow, 'NISN');
    $sheet->setCellValue('E' . $headerRow, 'NAMA SISWA');
    $sheet->setCellValue('F' . $headerRow, 'L/P');
    $sheet->setCellValue('G' . $headerRow, 'PERAN');

    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
        'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
    ];
    $sheet->getStyle('A' . $headerRow . ':G' . $headerRow)->applyFromArray($headerStyle);
    $sheet->getRowDimension($headerRow)->setRowHeight(24);

    $row = $headerRow + 1;
    $no = 1;
    if (empty($kelompok_list)) {
        $sheet->setCellValue('A' . $row, 'Tidak ada data kelompok belajar.');
        $sheet->mergeCells('A' . $row . ':G' . $row);
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $row++;
    } else {
        foreach ($kelompok_list as $k) {
            $kid = (int)$k['id_kelompok'];
            $id_ketua = (int)($k['id_ketua'] ?? 0);
            $anggota = $anggota_map[$kid] ?? [];

            if (!empty($anggota) && $id_ketua > 0) {
                usort($anggota, static function($a, $b) use ($id_ketua) {
                    $a_is = ((int)$a['id_siswa'] === $id_ketua) ? 0 : 1;
                    $b_is = ((int)$b['id_siswa'] === $id_ketua) ? 0 : 1;
                    if ($a_is !== $b_is) return $a_is <=> $b_is;
                    return strcasecmp($a['nama_siswa'], $b['nama_siswa']);
                });
            }

            if (empty($anggota)) {
                $sheet->setCellValue('A' . $row, $no++);
                $sheet->setCellValue('B' . $row, $k['nama_kelas'] ?? '-');
                $sheet->setCellValue('C' . $row, $k['nama_kelompok']);
                $sheet->setCellValue('D' . $row, '-');
                $sheet->setCellValue('E' . $row, '(Belum ada anggota)');
                $sheet->setCellValue('F' . $row, '-');
                $sheet->setCellValue('G' . $row, '-');
                $row++;
            } else {
                foreach ($anggota as $a) {
                    $is_ketua = ($id_ketua > 0 && (int)$a['id_siswa'] === $id_ketua);
                    $sheet->setCellValue('A' . $row, $no++);
                    $sheet->setCellValue('B' . $row, $k['nama_kelas'] ?? '-');
                    $sheet->setCellValue('C' . $row, $k['nama_kelompok']);
                    $sheet->setCellValueExplicit('D' . $row, (string)($a['nisn'] ?: '-'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $sheet->setCellValue('E' . $row, $a['nama_siswa']);
                    $sheet->setCellValue('F' . $row, $a['jenis_kelamin'] ?: '-');
                    $sheet->setCellValue('G' . $row, $is_ketua ? 'Ketua' : 'Anggota');
                    if ($is_ketua) {
                        $sheet->getStyle('A' . $row . ':G' . $row)->getFill()
                            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                            ->getStartColor()->setRGB('EFF6FF');
                    }
                    $row++;
                }
            }
        }
    }

    $lastDataRow = $row - 1;
    $sheet->getStyle('A' . ($headerRow + 1) . ':G' . $lastDataRow)->getBorders()
        ->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    $sheet->getStyle('A' . ($headerRow + 1) . ':B' . $lastDataRow)->getAlignment()
        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('D' . ($headerRow + 1) . ':D' . $lastDataRow)->getAlignment()
        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('F' . ($headerRow + 1) . ':G' . $lastDataRow)->getAlignment()
        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    foreach (range('A', 'G') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename_safe . '.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// Fallback HTML Excel (.xls) jika PhpSpreadsheet tidak termuat
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename_safe . '.xls"');
header('Cache-Control: max-age=0');
header('Pragma: public');
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<!--[if gte mso 9]>
<xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Kelompok Belajar</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml>
<![endif]-->
<style>
    th { background: #2563eb; color: #ffffff; font-weight: bold; border: 1px solid #000; text-align: center; }
    td { border: 1px solid #555; vertical-align: middle; }
    .center { text-align: center; }
</style>
</head>
<body>
    <table border="0" style="margin-bottom:10px;">
        <tr><td colspan="7" align="center"><b><?= htmlspecialchars(strtoupper($nama_yayasan)) ?></b></td></tr>
        <tr><td colspan="7" align="center" style="font-size:14pt;"><b><?= htmlspecialchars(strtoupper($nama_madrasah)) ?></b></td></tr>
        <tr><td colspan="7" align="center"><?= htmlspecialchars($alamat_madrasah) ?></td></tr>
        <tr><td colspan="7">&nbsp;</td></tr>
        <tr><td colspan="7" align="center" style="font-size:12pt;"><b>DAFTAR KELOMPOK BELAJAR SISWA<?= $nama_kelas_filter ? (' - KELAS ' . strtoupper($nama_kelas_filter)) : '' ?></b></td></tr>
        <tr><td colspan="7" align="center">Tahun Ajaran <?= htmlspecialchars($tahun_ajaran) ?> (<?= htmlspecialchars($semester) ?>)</td></tr>
    </table>
    <table border="1">
        <thead>
            <tr>
                <th style="width:40px;">No</th>
                <th style="width:80px;">Kelas</th>
                <th style="width:180px;">Nama Kelompok</th>
                <th style="width:120px;">NISN</th>
                <th style="width:220px;">Nama Siswa</th>
                <th style="width:60px;">L/P</th>
                <th style="width:100px;">Peran</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($kelompok_list)): ?>
                <tr><td colspan="7" align="center">Tidak ada data kelompok belajar.</td></tr>
            <?php else: ?>
                <?php $no = 1; foreach ($kelompok_list as $k): ?>
                    <?php 
                    $kid = (int)$k['id_kelompok'];
                    $id_ketua = (int)($k['id_ketua'] ?? 0);
                    $anggota = $anggota_map[$kid] ?? [];
                    if (!empty($anggota) && $id_ketua > 0) {
                        usort($anggota, static function($a, $b) use ($id_ketua) {
                            $a_is = ((int)$a['id_siswa'] === $id_ketua) ? 0 : 1;
                            $b_is = ((int)$b['id_siswa'] === $id_ketua) ? 0 : 1;
                            if ($a_is !== $b_is) return $a_is <=> $b_is;
                            return strcasecmp($a['nama_siswa'], $b['nama_siswa']);
                        });
                    }
                    ?>
                    <?php if (empty($anggota)): ?>
                        <tr>
                            <td class="center"><?= $no++ ?></td>
                            <td class="center"><?= htmlspecialchars($k['nama_kelas'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($k['nama_kelompok']) ?></td>
                            <td class="center">-</td>
                            <td>(Belum ada anggota)</td>
                            <td class="center">-</td>
                            <td class="center">-</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($anggota as $a): ?>
                            <?php $is_ketua = ($id_ketua > 0 && (int)$a['id_siswa'] === $id_ketua); ?>
                            <tr <?= $is_ketua ? 'style="background-color:#eff6ff;"' : '' ?>>
                                <td class="center"><?= $no++ ?></td>
                                <td class="center"><?= htmlspecialchars($k['nama_kelas'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($k['nama_kelompok']) ?></td>
                                <td class="center" style="mso-number-format:'\@';"><?= htmlspecialchars($a['nisn'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($a['nama_siswa']) ?></td>
                                <td class="center"><?= htmlspecialchars($a['jenis_kelamin'] ?: '-') ?></td>
                                <td class="center"><b><?= $is_ketua ? 'Ketua' : 'Anggota' ?></b></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
