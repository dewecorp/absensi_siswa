<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Get session type
$session_type = isset($_GET['session_type']) ? $_GET['session_type'] : 'admin';

// Check auth
$allowed_roles = ['admin', 'tata_usaha'];
if (!isAuthorized($allowed_roles)) {
    die('Unauthorized');
}

// Get parameters
$selected_class_id = isset($_GET['kelas']) ? $_GET['kelas'] : null;
$selected_mapel_id = isset($_GET['mapel']) ? $_GET['mapel'] : null;
$selected_exam_type = isset($_GET['jenis']) ? $_GET['jenis'] : null;

if (!$selected_class_id || !$selected_mapel_id || !$selected_exam_type) {
    die('Parameter tidak lengkap');
}

// Get school profile
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'];
$semester_aktif = $school_profile['semester'];

// Get class name
$stmt = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas = ?");
$stmt->execute([$selected_class_id]);
$kelas = $stmt->fetchColumn();

// Get subject name
$stmt = $pdo->prepare("SELECT nama_mapel FROM tb_mata_pelajaran WHERE id_mapel = ?");
$stmt->execute([$selected_mapel_id]);
$mapel = $stmt->fetchColumn();

// Get Kepala Madrasah name for signature
$kepala_madrasah = $school_profile['kepala_madrasah'] ?? '';
$nip_kepala = $school_profile['nip_kepala'] ?? '';

// Digital Signature QR Code for Kepala Madrasah
$qr_kepala_content = 'Validasi Tanda Tangan Digital Kepala Madrasah: ' . $kepala_madrasah . ' - ' . ($school_profile['nama_madrasah'] ?? 'Madrasah') . ' - Program Remidi';
$qr_kepala_url = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qr_kepala_content);
$qr_kepala_img = '<img src="' . $qr_kepala_url . '" alt="QR Signature Kepala" style="width: 60px; height: 60px; margin: 5px auto; display: block;">';

// Map display name to database value for JOIN
$exam_type_map = [
    'PTS' => 'UTS',
    'PAS' => 'UAS',
    'PAT' => 'PAT',
    'Pra Ujian Madrasah' => 'Pra Ujian',
    'Ujian Madrasah' => 'Ujian'
];
$db_exam_type = $exam_type_map[$selected_exam_type] ?? $selected_exam_type;

// Get remedial data with guru name and original score
$stmt = $pdo->prepare("
    SELECT r.*, s.nama_siswa, s.nisn, g.nama_guru, n.nilai_asli
    FROM tb_program_remidial r
    JOIN tb_siswa s ON r.id_siswa = s.id_siswa
    LEFT JOIN tb_guru g ON r.id_guru = g.id_guru
    LEFT JOIN tb_nilai_semester n ON s.id_siswa = n.id_siswa 
        AND n.id_mapel = r.id_mapel 
        AND n.jenis_semester = ?
        AND n.tahun_ajaran = r.tahun_ajaran
        AND n.semester = r.semester
    WHERE r.id_kelas = ? AND r.id_mapel = ? AND r.jenis_ulangan = ? 
    AND r.tahun_ajaran = ? AND r.semester = ?
    ORDER BY s.nama_siswa ASC
");
$stmt->execute([$db_exam_type, $selected_class_id, $selected_mapel_id, $selected_exam_type, $tahun_ajaran, $semester_aktif]);
$remedial_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Logo - Check multiple possible locations
$logo_file = $school_profile['logo'] ?? '';
$logo_path = '';
if (!empty($logo_file)) {
    $possible_paths = [
        __DIR__ . '/../assets/img/' . $logo_file,
        __DIR__ . '/../uploads/' . $logo_file,
        __DIR__ . '/../' . $logo_file
    ];
    
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            $logo_path = str_replace(__DIR__ . '/', '../', $path);
            break;
        }
    }
}

// Set headers for Excel download
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"program_remidi_{$mapel}_{$kelas}.xls\"");
header("Pragma: no-cache");
header("Expires: 0");

// Start HTML output
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 11pt;
            margin: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 3px solid #000;
            padding-bottom: 15px;
        }
        .header h2 {
            margin: 5px 0;
            font-size: 16pt;
            font-weight: bold;
        }
        .header p {
            margin: 3px 0;
            font-size: 11pt;
        }
        .info-section {
            margin: 15px 0;
            font-size: 11pt;
        }
        .info-section table {
            width: 100%;
            border: none;
        }
        .info-section td {
            padding: 3px 5px;
            border: none;
        }
        .info-label {
            width: 150px;
            font-weight: bold;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        .data-table th {
            background-color: #4CAF50;
            color: #000;
            font-weight: bold;
            padding: 8px 5px;
            border: 1px solid #000;
            text-align: center;
            font-size: 10pt;
        }
        .data-table td {
            padding: 6px 5px;
            border: 1px solid #000;
            font-size: 10pt;
        }
        .data-table tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        .text-center {
            text-align: center;
        }
        .signature-table { width: 100%; margin-top: 30px; border-collapse: collapse; }
        .signature-table td { border: none; vertical-align: top; }
        .sig { text-align: center; }
        .sig-date { margin-bottom: 6px; }
        .sig-title { margin-bottom: 8px; font-weight: bold; }
        .sig-qr { margin: 6px 0; }
        .sig-name { margin-top: 40px; border-top: 2px solid #000; padding-top: 5px; }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <?php if (!empty($logo_path)): ?>
        <div class="header-logo">
            <img src="<?= $logo_path ?>" alt="Logo" style="max-height: 80px;">
        </div>
        <?php endif; ?>
        <h2>PROGRAM REMIDIAL</h2>
        <p style="font-size: 12pt; font-weight: bold; text-transform: uppercase;"><?= htmlspecialchars(strtoupper($school_profile['nama_madrasah'] ?? 'MADRASAH')) ?></p>
        <p><?= htmlspecialchars($school_profile['alamat'] ?? '') ?></p>
    </div>

    <!-- Info Section -->
    <div class="info-section">
        <table>
            <tr>
                <td class="info-label">Mata Pelajaran</td>
                <td>: <?= htmlspecialchars($mapel) ?></td>
                <td class="info-label">Kelas</td>
                <td>: <?= htmlspecialchars($kelas) ?></td>
            </tr>
            <tr>
                <td class="info-label">Tahun Ajaran</td>
                <td>: <?= htmlspecialchars($tahun_ajaran) ?></td>
                <td class="info-label">Semester</td>
                <td>: <?= htmlspecialchars(str_replace('Semester ', '', $semester_aktif)) ?></td>
            </tr>
            <tr>
                <td class="info-label">Jenis Ulangan</td>
                <td>: <?= htmlspecialchars($selected_exam_type) ?></td>
                <td></td>
                <td></td>
            </tr>
        </table>
    </div>

    <!-- Data Table -->
    <table class="data-table">
        <thead>
            <tr>
                <th width="4%">No</th>
                <th width="8%">Tanggal</th>
                <th width="18%">Nama Siswa</th>
                <th width="15%">Guru</th>
                <th width="7%">KKM</th>
                <th width="8%">Nilai Asli</th>
                <th width="16%">Bentuk Remidial</th>
                <th width="7%">Nilai Remidi</th>
                <th width="9%">Keterangan</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($remedial_list)): ?>
                <tr>
                    <td colspan="9" class="text-center">Tidak ada data remedial</td>
                </tr>
            <?php else: 
                $no = 1;
                foreach ($remedial_list as $r): 
            ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td class="text-center"><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                    <td><?= htmlspecialchars($r['nama_siswa']) ?></td>
                    <td><?= htmlspecialchars($r['nama_guru'] ?? '-') ?></td>
                    <td class="text-center"><?= number_format($r['kkm'], 0) ?></td>
                    <td class="text-center"><?= number_format($r['nilai_asli'] ?? $r['nilai_ulangan'], 0) ?></td>
                    <td><?= htmlspecialchars($r['bentuk_remidial']) ?></td>
                    <td class="text-center"><?= number_format($r['nilai_tes_remidi'], 0) ?></td>
                    <td class="text-center"><?= htmlspecialchars($r['keterangan']) ?></td>
                </tr>

            <?php 
                endforeach;
            endif; 
            ?>
        </tbody>
    </table>

    <!-- Signature Section - Only Kepala Madrasah -->
    <table class="signature-table">
        <tr>
            <td style="width:60%"></td>
            <td style="width:40%">
                <div class="sig">
                    <div class="sig-date"><?= date('d F Y') ?></div>
                    <div class="sig-title">Kepala Madrasah</div>
                    <?php if (!empty($kepala_madrasah)): ?>
                        <div class="sig-qr"><?= $qr_kepala_img ?></div>
                        <div class="sig-name">
                            <strong><?= htmlspecialchars($kepala_madrasah) ?></strong><br>
                            NIP. <?= htmlspecialchars($nip_kepala ?: '-') ?>
                        </div>
                    <?php else: ?>
                        <br><br><br>
                        <p><strong>_________________________</strong></p>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
