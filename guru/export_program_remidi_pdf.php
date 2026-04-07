<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check auth
if (!isAuthorized(['guru', 'wali'])) {
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

// Get teacher name
$id_guru = $_SESSION['user_id'];
if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
    $stmt = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $id_guru = $stmt->fetchColumn();
}

$stmt = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
$stmt->execute([$id_guru]);
$nama_guru = $stmt->fetchColumn();

// Get Kepala Madrasah name
$kepala_madrasah = $school_profile['kepala_madrasah'] ?? '-';
$nip_kepala = $school_profile['nip_kepala'] ?? '-';

// Digital Signature QR Code for Guru
$qr_guru_content = 'Validasi Tanda Tangan Digital Guru: ' . $nama_guru . ' - ' . $mapel . ' - ' . ($school_profile['nama_madrasah'] ?? 'Madrasah');
$qr_guru_url = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qr_guru_content);
$qr_guru_img = '<img src="' . $qr_guru_url . '" alt="QR Signature Guru" style="width: 60px; height: 60px; margin: 5px auto; display: block;">';

// Digital Signature QR Code for Kepala Madrasah
$qr_kepala_content = 'Validasi Tanda Tangan Digital Kepala Madrasah: ' . $kepala_madrasah . ' - ' . ($school_profile['nama_madrasah'] ?? 'Madrasah');
$qr_kepala_url = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qr_kepala_content);
$qr_kepala_img = '<img src="' . $qr_kepala_url . '" alt="QR Signature Kepala" style="width: 60px; height: 60px; margin: 5px auto; display: block;">';

// Logo - Check multiple possible locations
$logo_file = $school_profile['logo'] ?? '';
$logo_path = '';
if (!empty($logo_file)) {
    // Try different paths
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

// Get remedial data
$stmt = $pdo->prepare("
    SELECT r.*, s.nama_siswa, s.nisn 
    FROM tb_program_remidial r
    JOIN tb_siswa s ON r.id_siswa = s.id_siswa
    WHERE r.id_kelas = ? AND r.id_mapel = ? AND r.jenis_ulangan = ? 
    AND r.tahun_ajaran = ? AND r.semester = ?
    ORDER BY s.nama_siswa ASC
");
$stmt->execute([$selected_class_id, $selected_mapel_id, $selected_exam_type, $tahun_ajaran, $semester_aktif]);
$remedial_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers for PDF (print dialog)
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Program Remidi - <?= htmlspecialchars($mapel) ?></title>
    <style>
        @media print {
            @page {
                size: A4;
                margin: 10mm;
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
        .header h2 {
            margin: 5px 0;
            font-size: 14pt;
            font-weight: bold;
            text-transform: uppercase;
        }
        .header p {
            margin: 2px 0;
            font-size: 10pt;
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
            padding: 2px 5px;
            border: none;
        }
        .info-label {
            width: 140px;
            font-weight: bold;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 9pt;
        }
        .data-table th {
            background-color: #4CAF50;
            color: #000;
            font-weight: bold;
            padding: 6px 4px;
            border: 1px solid #000;
            text-align: center;
        }
        .data-table td {
            padding: 5px 4px;
            border: 1px solid #000;
        }
        .data-table tr:nth-child(even) {
            background-color: #f9f9f9;
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
        .signature-space {
            height: 10px; /* Minimal space between text and QR code */
        }
        .signature-top-space {
            height: 20px;
        }
        .btn-print {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 10px 20px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12pt;
            z-index: 1000;
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
        }
        .btn-print:hover {
            background-color: #45a049;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <?php if (!empty($logo_path)): ?>
        <div class="header-logo">
            <img src="<?= $logo_path ?>" alt="Logo">
        </div>
        <?php endif; ?>
        <h2 style="text-transform: uppercase;">Program Remidial</h2>
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
                <th width="20%">Nama Siswa</th>
                <th width="8%">KKM</th>
                <th width="10%">Nilai Asli</th>
                <th width="18%">Indikator Tidak Dikuasai</th>
                <th width="18%">Bentuk Remidial</th>
                <th width="8%">No. Soal</th>
                <th width="10%">Nilai Remidi</th>
                <th width="11%">Keterangan</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($remedial_list)): ?>
                <tr>
                    <td colspan="10" class="text-center">Tidak ada data remedial</td>
                </tr>
            <?php else: 
                $no = 1;
                foreach ($remedial_list as $r): 
            ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td><?= htmlspecialchars($r['nama_siswa']) ?></td>
                    <td class="text-center"><?= number_format($r['kkm'], 0) ?></td>
                    <td class="text-center"><?= number_format($r['nilai_ulangan'], 0) ?></td>
                    <td><?= htmlspecialchars($r['indikator_tidak_dikuasai']) ?></td>
                    <td><?= htmlspecialchars($r['bentuk_remidial']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($r['nomor_soal']) ?></td>
                    <td class="text-center"><?= number_format($r['nilai_tes_remidi'], 0) ?></td>
                    <td class="text-center">
                        <span style="color: <?= $r['keterangan'] == 'Tuntas' ? 'green' : 'red' ?>; font-weight: bold;">
                            <?= htmlspecialchars($r['keterangan']) ?>
                        </span>
                    </td>
                </tr>

            <?php 
                endforeach;
            endif; 
            ?>
        </tbody>
    </table>

    <!-- Signature Section -->
    <div class="signature-section">
        <div class="signature-row">
            <div class="signature-box">
                <div class="signature-top-space"></div>
                <p>Kepala Madrasah</p>
                <div class="signature-space"></div>
                <?= $qr_kepala_img ?>
                <p><strong><?= htmlspecialchars($kepala_madrasah) ?></strong></p>
            </div>
            <div class="signature-box">
                <p><?= date('d F Y') ?></p>
                <p>Guru Mata Pelajaran</p>
                <div class="signature-space"></div>
                <?= $qr_guru_img ?>
                <p><strong><?= htmlspecialchars($nama_guru) ?></strong></p>
            </div>
        </div>
    </div>

    <script>
        // Auto print dialog on load
        window.onload = function() {
            window.print();
        };
    </script>
</body>
</html>

