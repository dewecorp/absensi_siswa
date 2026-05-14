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

// Get remedial data with guru name
$stmt = $pdo->prepare("
    SELECT r.*, s.nama_siswa, s.nisn, g.nama_guru 
    FROM tb_program_remidial r
    JOIN tb_siswa s ON r.id_siswa = s.id_siswa
    LEFT JOIN tb_guru g ON r.id_guru = g.id_guru
    WHERE r.id_kelas = ? AND r.id_mapel = ? AND r.jenis_ulangan = ? 
    AND r.tahun_ajaran = ? AND r.semester = ?
    ORDER BY s.nama_siswa ASC
");
$stmt->execute([$selected_class_id, $selected_mapel_id, $selected_exam_type, $tahun_ajaran, $semester_aktif]);
$remedial_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Guru mapel untuk tanda tangan (prioritas: data program, lalu jadwal kelas–mapel)
$guru_mapel_nama = '-';
$id_guru_mapel = null;
foreach ($remedial_list as $row) {
    if (!empty($row['id_guru'])) {
        $id_guru_mapel = (int) $row['id_guru'];
        break;
    }
}
if ($id_guru_mapel) {
    $stmt = $pdo->prepare('SELECT nama_guru FROM tb_guru WHERE id_guru = ?');
    $stmt->execute([$id_guru_mapel]);
    $gn = $stmt->fetchColumn();
    if ($gn) {
        $guru_mapel_nama = $gn;
    }
} else {
    $stmt = $pdo->prepare('SELECT guru_id FROM tb_jadwal_pelajaran WHERE kelas_id = ? AND mapel_id = ? LIMIT 1');
    $stmt->execute([$selected_class_id, $selected_mapel_id]);
    $gid = $stmt->fetchColumn();
    if ($gid) {
        $stmt = $pdo->prepare('SELECT nama_guru FROM tb_guru WHERE id_guru = ?');
        $stmt->execute([(int) $gid]);
        $gn = $stmt->fetchColumn();
        if ($gn) {
            $guru_mapel_nama = $gn;
        }
    }
}
$qr_guru_content = 'Validasi Tanda Tangan Digital Guru Mata Pelajaran: ' . $guru_mapel_nama . ' - ' . $mapel . ' - ' . ($school_profile['nama_madrasah'] ?? 'Madrasah') . ' - Program Remidi';
$qr_guru_url = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qr_guru_content);
$qr_guru_img = '<img src="' . $qr_guru_url . '" alt="QR Signature Guru Mapel" style="width: 60px; height: 60px; margin: 5px auto; display: block;">';

$tanggal_cetak_indo = formatDateIndonesia(date('Y-m-d'));

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
    <title>Program Remidi - <?= htmlspecialchars($mapel) ?> - Kelas <?= htmlspecialchars($kelas) ?></title>
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
            margin: 15px 0;
            font-size: 10pt;
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
            padding: 6px 4px;
            border: 1px solid #000;
            text-align: center;
            font-size: 9pt;
        }
        .data-table td {
            padding: 5px 4px;
            border: 1px solid #000;
            font-size: 9pt;
        }
        .data-table tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        .text-center {
            text-align: center;
        }
        .signature-section {
            margin-top: 30px;
            page-break-inside: avoid;
        }
        .signature-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 20px;
        }
        .signature-box {
            text-align: center;
            min-width: 200px;
            flex: 1;
        }
        .signature-top-space {
            height: 20px;
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
        <h2>PROGRAM REMIDIAL</h2>
        <p style="font-size: 11pt; font-weight: bold; text-transform: uppercase;"><?= htmlspecialchars(strtoupper($school_profile['nama_madrasah'] ?? 'MADRASAH')) ?></p>
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
                    <td class="text-center"><?= number_format($r['nilai_ulangan'], 0) ?></td>
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

    <!-- Tanda tangan: Kepala Madrasah (kiri), Guru Mapel (kanan) -->
    <div class="signature-section">
        <div class="signature-row">
            <div class="signature-box">
                <div class="signature-top-space"></div>
                <p>Kepala Madrasah</p>
                <div class="signature-space"></div>
                <?= $qr_kepala_img ?>
                <p><strong><?= htmlspecialchars($kepala_madrasah) ?></strong></p>
                <p style="font-size: 9pt;">NIP. <?= !empty($nip_kepala) ? htmlspecialchars($nip_kepala) : '-' ?></p>
            </div>
            <div class="signature-box">
                <p><?= htmlspecialchars($tanggal_cetak_indo) ?></p>
                <p>Guru Mata Pelajaran</p>
                <div class="signature-space"></div>
                <?= $qr_guru_img ?>
                <p><strong><?= htmlspecialchars($guru_mapel_nama) ?></strong></p>
            </div>
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
