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
$qr_kepala_content = 'Validasi Tanda Tangan Digital Kepala Madrasah: ' . $kepala_madrasah . ' - ' . ($school_profile['nama_madrasah'] ?? 'Madrasah') . ' - Program Pengayaan';
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

// Get enrichment data with guru name
$stmt = $pdo->prepare("
    SELECT p.*, s.nama_siswa, g.nama_guru, n.nilai_asli
    FROM tb_program_pengayaan p
    JOIN tb_siswa s ON p.id_siswa = s.id_siswa
    LEFT JOIN tb_guru g ON p.id_guru = g.id_guru
    LEFT JOIN tb_nilai_semester n ON s.id_siswa = n.id_siswa 
        AND n.id_mapel = p.id_mapel 
        AND n.jenis_semester = ?
        AND n.tahun_ajaran = p.tahun_ajaran
        AND n.semester = p.semester
    WHERE p.id_kelas = ? AND p.id_mapel = ? AND p.jenis_ulangan = ? 
    AND p.tahun_ajaran = ? AND p.semester = ?
    ORDER BY s.nama_siswa ASC
");
$stmt->execute([$db_exam_type, $selected_class_id, $selected_mapel_id, $selected_exam_type, $tahun_ajaran, $semester_aktif]);
$enrichment_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
header("Content-Disposition: attachment; filename=\"program_pengayaan_{$mapel}_{$kelas}.xls\"");
header("Pragma: no-cache");
header("Expires: 0");
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 3px solid #000;
            padding-bottom: 15px;
        }
        .header h2 {
            margin: 5px 0;
            font-size: 14pt;
            font-weight: bold;
        }
        .header p {
            margin: 3px 0;
            font-size: 11pt;
        }
        .info-section {
            margin-bottom: 15px;
        }
        .info-section table {
            width: 100%;
            border-collapse: collapse;
        }
        .info-section td {
            padding: 3px 5px;
            border: none;
        }
        .info-label {
            font-weight: bold;
            width: 15%;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .data-table th, .data-table td {
            border: 1px solid #000;
            padding: 6px 8px;
        }
        .data-table th {
            background-color: #4CAF50;
            color: #000;
            font-weight: bold;
            text-align: center;
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
        <h2>PROGRAM PENGAYAAN</h2>
        <p><?= htmlspecialchars(strtoupper($school_profile['nama_madrasah'] ?? 'MADRASAH')) ?></p>
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
                <th width="25%">Nama Siswa</th>
                <th width="15%">Guru</th>
                <th width="10%">Nilai Ulangan</th>
                <th width="38%">Bentuk Pengayaan</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($enrichment_list)): ?>
                <tr>
                    <td colspan="6" class="text-center">Tidak ada data</td>
                </tr>
            <?php else: 
                $no = 1;
                foreach ($enrichment_list as $p): 
            ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td class="text-center"><?= date('d/m/Y', strtotime($p['tanggal'])) ?></td>
                    <td><?= htmlspecialchars($p['nama_siswa']) ?></td>
                    <td><?= htmlspecialchars($p['nama_guru'] ?? '-') ?></td>
                    <td class="text-center"><?= number_format($p['nilai_asli'] ?? 0, 0) ?></td>
                    <td><?= htmlspecialchars($p['bentuk_pengayaan']) ?></td>
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
