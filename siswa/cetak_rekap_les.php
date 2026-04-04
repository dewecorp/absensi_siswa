<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has siswa level
if (!isAuthorized(['siswa', 'admin', 'guru', 'wali', 'kepala_madrasah', 'tata_usaha'])) {
    redirect('../login.php');
}

$id_siswa = $_GET['id_siswa'] ?? $_SESSION['user_id'];
// Safety: If student is logged in, they can only print their own data
if ($_SESSION['level'] === 'siswa' && $id_siswa != $_SESSION['user_id']) {
    $id_siswa = $_SESSION['user_id'];
}

$school_profile = getSchoolProfile($pdo);

// Ambil data siswa dan kelas
$stmt = $pdo->prepare("
    SELECT s.nama_siswa, s.nisn, k.nama_kelas, k.wali_kelas, k.id_kelas
    FROM tb_siswa s
    LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas
    WHERE s.id_siswa = ?
");
$stmt->execute([$id_siswa]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    echo "Data siswa tidak ditemukan.";
    exit;
}

// Fetch all schedules for Class 6 and join with student's attendance
$stmt = $pdo->prepare("
    SELECT 
        jl.tanggal, 
        jl.hari,
        al.status
    FROM tb_jadwal_les jl
    LEFT JOIN tb_absensi_les al ON jl.tanggal = al.tanggal AND al.id_siswa = ?
    ORDER BY jl.tanggal ASC
");
$stmt->execute([$id_siswa]);
$harian_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$logo_path = '../assets/img/' . ($school_profile['logo'] ?? '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Rekap Absensi Les - <?= htmlspecialchars($student['nama_siswa']) ?></title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12pt; color: #333; line-height: 1.5; margin: 0; padding: 20px; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 3px double #000; padding-bottom: 10px; position: relative; }
        .header img { position: absolute; left: 0; top: 0; width: 80px; height: auto; }
        .header h2, .header h3 { margin: 0; text-transform: uppercase; }
        .header p { margin: 5px 0 0; font-size: 10pt; }
        
        .report-title { text-align: center; text-decoration: underline; font-weight: bold; margin: 20px 0; font-size: 14pt; }
        
        .info-table { width: 100%; margin-bottom: 20px; }
        .info-table td { padding: 3px 0; vertical-align: top; }
        .info-table td:first-child { width: 120px; }
        .info-table td:nth-child(2) { width: 10px; }
        
        table.data-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        table.data-table th, table.data-table td { border: 1px solid #000; padding: 8px; text-align: left; }
        table.data-table th { background-color: #f2f2f2; text-align: center; }
        .text-center { text-align: center; }
        
        .signature-wrapper { width: 100%; margin-top: 40px; page-break-inside: avoid; }
        .signature-box { float: right; width: 300px; text-align: center; }
        .signature-box-left { float: left; width: 300px; text-align: center; }
        .signature-space { height: 80px; margin: 10px 0; position: relative; }
        .signature-space img { width: 80px; position: absolute; left: 50%; transform: translateX(-50%); top: 0; }
        .clear { clear: both; }

        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 20px; text-align: right;">
        <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer; background: #4b49ac; color: #fff; border: none; border-radius: 4px;">
            <i class="fas fa-print"></i> Cetak Sekarang
        </button>
    </div>

    <div class="header">
        <?php if (!empty($school_profile['logo']) && file_exists(__DIR__ . '/' . $logo_path)): ?>
            <img src="<?= $logo_path ?>" alt="Logo">
        <?php endif; ?>
        <h3><?= htmlspecialchars($school_profile['nama_yayasan'] ?? 'YAYASAN') ?></h3>
        <h2><?= htmlspecialchars($school_profile['nama_madrasah'] ?? 'MADRASAH') ?></h2>
        <p><?= htmlspecialchars($school_profile['alamat'] ?? '') ?></p>
        <p>Tahun Ajaran: <?= htmlspecialchars($school_profile['tahun_ajaran'] ?? '-') ?></p>
    </div>

    <div class="report-title">REKAP ABSENSI LES KELAS 6</div>

    <table class="info-table">
        <tr>
            <td>Nama Siswa</td>
            <td>:</td>
            <td><strong><?= htmlspecialchars($student['nama_siswa']) ?></strong></td>
        </tr>
        <tr>
            <td>NISN</td>
            <td>:</td>
            <td><?= htmlspecialchars($student['nisn']) ?></td>
        </tr>
        <tr>
            <td>Kelas</td>
            <td>:</td>
            <td><?= htmlspecialchars($student['nama_kelas']) ?></td>
        </tr>
    </table>

    <table class="data-table">
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="30%">Hari, Tanggal</th>
                <th>Status Kehadiran</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($harian_data)): ?>
                <tr>
                    <td colspan="3" class="text-center">Belum ada data absensi les.</td>
                </tr>
            <?php else: ?>
                <?php $no = 1; foreach ($harian_data as $row): ?>
                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td><?= htmlspecialchars($row['hari']) ?>, <?= formatDateIndonesia($row['tanggal']) ?></td>
                        <td class="text-center">
                            <?php 
                            $status = $row['status'] ?? '';
                            if ($status == 'Hadir') echo 'Hadir';
                            elseif (in_array($status, ['Sakit', 'Izin', 'Alpa'])) echo 'Tidak Hadir (' . $status . ')';
                            else echo 'Belum Absen';
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="signature-wrapper">
        <div class="signature-box-left">
            <p>Mengetahui,<br>Kepala Madrasah,</p>
            <div class="signature-space">
                <?php 
                $kepala = $school_profile['kepala_madrasah'] ?? '-';
                $qr_kepala = 'Validasi Kepala Madrasah: ' . $kepala . ' - ' . ($school_profile['nama_madrasah'] ?? 'Madrasah');
                $qr_kepala_url = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qr_kepala);
                ?>
                <img src="<?= $qr_kepala_url ?>" alt="QR Code">
            </div>
            <p><strong><u><?= htmlspecialchars($kepala) ?></u></strong><br>NIP. <?= htmlspecialchars($school_profile['nip_kepala'] ?? '-') ?></p>
        </div>

        <div class="signature-box">
            <p><?= htmlspecialchars($school_profile['tempat_jadwal'] ?? 'Sukosono') ?>, <?= formatDateIndonesia(date('Y-m-d')) ?><br>Wali Kelas <?= htmlspecialchars($student['nama_kelas']) ?>,</p>
            <div class="signature-space">
                <?php 
                $wali = $student['wali_kelas'] ?? '-';
                $qr_wali = 'Validasi Wali Kelas: ' . $wali . ' - ' . ($school_profile['nama_madrasah'] ?? 'Madrasah');
                $qr_wali_url = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qr_wali);
                ?>
                <img src="<?= $qr_wali_url ?>" alt="QR Code">
            </div>
            <p><strong><u><?= htmlspecialchars($wali) ?></u></strong></p>
        </div>
        <div class="clear"></div>
    </div>

    <script>
        window.onload = function() {
            // Uncomment line below to auto-print on load
            // window.print();
        }
    </script>
</body>
</html>