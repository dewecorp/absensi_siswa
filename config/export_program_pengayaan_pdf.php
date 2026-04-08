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

// Set headers for PDF (print dialog)
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Program Pengayaan - <?= htmlspecialchars($mapel) ?> - Kelas <?= htmlspecialchars($kelas) ?></title>
    <style>
        @media print {
            @page {
                size: landscape;
                margin: 15mm;
            }
            body {
                margin: 0;
                padding: 0;
            }
            .no-print {
                display: none;
            }
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            margin: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 15px;
            border-bottom: 3px solid #000;
            padding-bottom: 10px;
            position: relative;
            min-height: 60px;
        }
        .header-logo {
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 50px;
            height: 50px;
        }
        .header-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
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
        .signature-section {
            margin-top: 30px;
            text-align: right;
        }
        .signature-box {
            display: inline-block;
            text-align: center;
            min-width: 200px;
        }
        .signature-space {
            height: 10px;
        }
        .print-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 10px 20px;
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        .print-btn:hover {
            background: #45a049;
        }
    </style>
</head>
<body>
    <button class="print-btn no-print" onclick="window.print()">🖨️ Print / Save PDF</button>
    
    <!-- Header -->
    <div class="header">
        <?php if (!empty($logo_path)): ?>
        <div class="header-logo">
            <img src="<?= $logo_path ?>" alt="Logo" style="max-height: 80px;">
        </div>
        <?php endif; ?>
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
    <div class="signature-section">
        <div class="signature-box">
            <p><?= date('d F Y') ?></p>
            <p>Kepala Madrasah</p>
            <div class="signature-space"></div>
            <?= $qr_kepala_img ?>
            <p><strong><?= htmlspecialchars($kepala_madrasah) ?></strong></p>
            <?php if (!empty($nip_kepala)): ?>
            <p style="font-size: 9pt;">NIP. <?= htmlspecialchars($nip_kepala) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <style>
        .signature-space {
            height: 15px;
        }
    </style>

    <script>
        // Auto print dialog on load
        window.onload = function() {
            window.print();
        };
    </script>
</body>
</html>
