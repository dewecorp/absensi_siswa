<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check auth
if (!isAuthorized(['guru', 'wali', 'kepala_madrasah', 'tata_usaha', 'admin'])) {
    die('Unauthorized');
}

// Get parameters
$selected_class_id = isset($_GET['kelas']) ? $_GET['kelas'] : null;
$selected_jenis = isset($_GET['jenis']) ? $_GET['jenis'] : null;
$selected_tipe = isset($_GET['tipe']) ? $_GET['tipe'] : 'nilai_jadi';

if (!$selected_class_id || !$selected_jenis) {
    die('Parameter tidak lengkap');
}

// Get Class Info
$stmt = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas = ?");
$stmt->execute([$selected_class_id]);
$class_info = $stmt->fetch(PDO::FETCH_ASSOC);

// Use Wali Kelas name for signature
$nama_guru = $class_info['wali_kelas'];

// Get All Subjects
$subjects = [];
$stmt = $pdo->query("SELECT * FROM tb_mata_pelajaran 
    WHERE nama_mapel NOT LIKE '%Asmaul Husna%'
    AND nama_mapel NOT LIKE '%Upacara%'
    AND nama_mapel NOT LIKE '%Istirahat%'
    AND nama_mapel NOT LIKE '%Kepramukaan%'
    AND nama_mapel NOT LIKE '%Ekstrakurikuler%'
    ORDER BY nama_mapel ASC");
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Active Semester
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'];
$semester_aktif = $school_profile['semester'];
$madrasah_head_name = $school_profile['kepala_madrasah'] ?? '.........................';
$madrasah_head_signature = $school_profile['ttd_kepala'] ?? '';
$school_city = $school_profile['tempat_jadwal'] ?? '';
$report_date = formatDateIndonesia(date('Y-m-d'));

// Data Fetching
$students = [];
$rekap_data = [];

// Get Students
$stmt = $pdo->prepare("SELECT * FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
$stmt->execute([$selected_class_id]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Grades
foreach ($students as $student) {
    $total_nilai = 0;
    $count_mapel = 0;
    
    foreach ($subjects as $mapel) {
        $nilai = 0;
        
        if ($selected_jenis == 'Harian') {
            $stmt = $pdo->prepare("
                SELECT d.* 
                FROM tb_nilai_harian_detail d
                JOIN tb_nilai_harian_header h ON d.id_header = h.id_header
                WHERE h.id_kelas = ? AND h.id_mapel = ?
                AND h.tahun_ajaran = ? AND h.semester = ?
                AND d.id_siswa = ?
            ");
            $stmt->execute([$selected_class_id, $mapel['id_mapel'], $tahun_ajaran, $semester_aktif, $student['id_siswa']]);
            $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($details)) {
                $sum = 0;
                $count = 0;
                foreach ($details as $d) {
                    $val = ($selected_tipe == 'nilai_asli') ? $d['nilai'] : $d['nilai_jadi'];
                    if ($val > 0) {
                        $sum += $val;
                        $count++;
                    }
                }
                if ($count > 0) {
                    $nilai = round($sum / $count);
                }
            }
        } else {
            $stmt = $pdo->prepare("
                SELECT * FROM tb_nilai_semester 
                WHERE id_kelas = ? AND id_mapel = ? 
                AND jenis_semester = ? AND tahun_ajaran = ? AND semester = ?
                AND id_siswa = ?
            ");
            $stmt->execute([$selected_class_id, $mapel['id_mapel'], $selected_jenis, $tahun_ajaran, $semester_aktif, $student['id_siswa']]);
            $grade = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($grade) {
                $val = ($selected_tipe == 'nilai_asli') ? $grade['nilai_asli'] : $grade['nilai_jadi'];
                $nilai = $val > 0 ? (float)$val : 0;
            }
        }

        $rekap_data[$student['id_siswa']][$mapel['id_mapel']] = $nilai;
        
        if ($nilai > 0) {
            $total_nilai += $nilai;
            $count_mapel++;
        }
    }
    
    $rekap_data[$student['id_siswa']]['total'] = $total_nilai;
    $rekap_data[$student['id_siswa']]['rerata'] = $count_mapel > 0 ? round($total_nilai / $count_mapel, 1) : 0;
}

// Calculate Ranking
$averages = [];
foreach ($students as $student) {
    $averages[$student['id_siswa']] = $rekap_data[$student['id_siswa']]['rerata'];
}
arsort($averages);

$rank = 1;
$prev_avg = -1;
$real_rank = 1;

foreach ($averages as $id_siswa => $avg) {
    if ($avg != $prev_avg) {
        $rank = $real_rank;
    }
    $rekap_data[$id_siswa]['ranking'] = $rank;
    $prev_avg = $avg;
    $real_rank++;
}

$title = "REKAP NILAI " . strtoupper($selected_jenis);
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($title) ?> - <?= htmlspecialchars($class_info['nama_kelas']) ?></title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 10px; margin: 0; padding: 10px; }
        .header { text-align: center; margin-bottom: 20px; }
        .header h2, .header h3, .header p { margin: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #000; padding: 4px; text-align: center; }
        th { background-color: #f2f2f2; }
        .text-left { text-align: left; }
        
        @page { size: landscape; margin: 10mm; }
        
        .no-break {
            page-break-inside: avoid;
            break-inside: avoid;
        }
    </style>
</head>
<body>
    <div class="header" style="border-bottom: 3px solid #000; padding-bottom: 10px; margin-bottom: 20px;">
        <table style="width: 100%; border: none; margin: 0;">
            <tr style="border: none;">
                <td style="border: none; width: 100px; text-align: center; vertical-align: middle;">
                    <?php if (!empty($school_profile['logo'])): ?>
                        <img src="../assets/img/<?= htmlspecialchars($school_profile['logo']) ?>" style="height: 80px; width: auto;">
                    <?php endif; ?>
                </td>
                <td style="border: none; text-align: center; vertical-align: middle;">
                    <h3 style="margin: 0; font-size: 16px; text-transform: uppercase; letter-spacing: 1px;"><?= htmlspecialchars($school_profile['nama_yayasan'] ?? '') ?></h3>
                    <h2 style="margin: 5px 0; font-size: 22px; text-transform: uppercase; font-weight: bold;"><?= htmlspecialchars($school_profile['nama_madrasah'] ?? '') ?></h2>
                    <p style="margin: 0; font-size: 12px;"><?= htmlspecialchars($school_profile['alamat'] ?? '') ?></p>
                </td>
                <td style="border: none; width: 100px;"></td>
            </tr>
        </table>
    </div>

    <div style="text-align: center; margin-bottom: 20px;">
        <h3 style="margin: 0; margin-bottom: 15px; text-decoration: underline;"><?= $title ?></h3>
        <table style="width: 100%; border: none; font-size: 12px; margin-top: 10px;">
            <tr style="border: none;">
                <td style="border: none; text-align: left; width: 15%; padding: 2px;">Kelas</td>
                <td style="border: none; text-align: left; width: 35%; padding: 2px;">: <?= htmlspecialchars($class_info['nama_kelas']) ?></td>
                <td style="border: none; text-align: left; width: 15%; padding: 2px;">Tipe Nilai</td>
                <td style="border: none; text-align: left; width: 35%; padding: 2px;">: <?= $selected_tipe == 'nilai_asli' ? 'NILAI ASLI' : 'NILAI JADI' ?></td>
            </tr>
            <tr style="border: none;">
                <td style="border: none; text-align: left; width: 15%; padding: 2px;">Wali Kelas</td>
                <td style="border: none; text-align: left; width: 35%; padding: 2px;">: <?= htmlspecialchars($class_info['wali_kelas'] ?? '-') ?></td>
                <td style="border: none; text-align: left; width: 15%; padding: 2px;">Tahun Ajaran</td>
                <td style="border: none; text-align: left; width: 35%; padding: 2px;">: <?= $tahun_ajaran ?> (<?= $semester_aktif ?>)</td>
            </tr>
        </table>
    </div>

    <table>
        <thead>
            <tr>
                <th width="3%">No</th>
                <th width="15%">Nama Siswa</th>
                <?php foreach ($subjects as $mapel): ?>
                    <th><?= htmlspecialchars($mapel['nama_mapel']) ?></th>
                <?php endforeach; ?>
                <th width="5%">Jml</th>
                <th width="5%">Rata</th>
                <th width="5%">Rank</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            foreach ($students as $student): 
                $data = $rekap_data[$student['id_siswa']] ?? [];
            ?>
            <tr>
                <td><?= $no++ ?></td>
                <td class="text-left"><?= htmlspecialchars($student['nama_siswa']) ?></td>
                <?php foreach ($subjects as $mapel): ?>
                    <td>
                        <?php 
                        $val = $data[$mapel['id_mapel']] ?? 0;
                        echo $val > 0 ? $val : '-';
                        ?>
                    </td>
                <?php endforeach; ?>
                <td><?= $data['total'] ?></td>
                <td><?= $data['rerata'] ?></td>
                <td><?= $data['ranking'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div class="no-break" style="margin-top: 30px; display: flex; justify-content: flex-end; gap: 50px; padding-right: 50px;">
        <div style="text-align: center; width: 250px;">
            <p><?= htmlspecialchars($school_city) ?>, <?= htmlspecialchars($report_date) ?><br>Wali Kelas,</p>
            <?php
            if ($nama_guru) {
                $qrContent = 'Validasi Tanda Tangan Digital: ' . $nama_guru . ' - ' . ($school_profile['nama_madrasah'] ?? 'Madrasah');
                $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qrContent);
                echo '<img src="' . $qrUrl . '" alt="QR Signature" style="width: 80px; height: 80px; margin: 10px auto; display: block;">';
                echo '<p style="font-size: 10px; margin-top: 0;">(Ditandatangani secara digital)</p>';
            } else {
                echo '<br><br><br><br><br>';
            }
            ?>
            <p><b><?= htmlspecialchars($nama_guru ?? '.........................') ?></b></p>
        </div>

        <div style="text-align: center; width: 250px;">
            <p><?= htmlspecialchars($school_city) ?>, <?= htmlspecialchars($report_date) ?><br>Kepala Madrasah,</p>
            <?php
            if ($madrasah_head_signature) {
                $qrContentHead = 'Validasi Tanda Tangan Digital: ' . $madrasah_head_name . ' - ' . ($school_profile['nama_madrasah'] ?? 'Madrasah');
                $qrUrlHead = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qrContentHead);
                echo '<img src="' . $qrUrlHead . '" alt="QR Signature" style="width: 80px; height: 80px; margin: 10px auto; display: block;">';
                echo '<p style="font-size: 10px; margin-top: 0;">(Ditandatangani secara digital)</p>';
            } else {
                echo '<br><br><br><br><br>';
            }
            ?>
            <p><b><?= htmlspecialchars($madrasah_head_name) ?></b></p>
        </div>
    </div>

    <script>
        window.onload = function() {
            window.print();
        }
    </script>
</body>
</html>
