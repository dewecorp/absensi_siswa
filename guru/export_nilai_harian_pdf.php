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

if (!$selected_class_id || !$selected_mapel_id) {
    die('Parameter tidak lengkap');
}

// Get teacher data
$id_guru = $_SESSION['user_id'];
if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
    $stmt = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $id_guru = $stmt->fetchColumn();
}

// Get Class Info
$stmt = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas = ?");
$stmt->execute([$selected_class_id]);
$class_info = $stmt->fetch(PDO::FETCH_ASSOC);

// Get Mapel Info
$stmt = $pdo->prepare("SELECT * FROM tb_mata_pelajaran WHERE id_mapel = ?");
$stmt->execute([$selected_mapel_id]);
$mapel_info = $stmt->fetch(PDO::FETCH_ASSOC);

// Get Students
$stmt = $pdo->prepare("SELECT * FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
$stmt->execute([$selected_class_id]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Grade Headers
$stmt = $pdo->prepare("SELECT * FROM tb_nilai_harian_header WHERE id_guru = ? AND id_kelas = ? AND id_mapel = ? ORDER BY created_at ASC");
$stmt->execute([$id_guru, $selected_class_id, $selected_mapel_id]);
$grade_headers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Grades
$grades_data = [];
if (!empty($grade_headers)) {
    $header_ids = array_column($grade_headers, 'id_header');
    $placeholders = str_repeat('?,', count($header_ids) - 1) . '?';
    
    $stmt = $pdo->prepare("SELECT * FROM tb_nilai_harian_detail WHERE id_header IN ($placeholders)");
    $stmt->execute($header_ids);
    $all_grades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($all_grades as $g) {
        $grades_data[$g['id_siswa']][$g['id_header']] = [
            'nilai' => $g['nilai'],
            'nilai_jadi' => $g['nilai_jadi']
        ];
    }
}

// Helper function
function getGuruName($pdo, $id) {
    $stmt = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([$id]);
    return $stmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Nilai Harian - <?= htmlspecialchars($class_info['nama_kelas']) ?> - <?= htmlspecialchars($mapel_info['nama_mapel']) ?></title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; margin: 0; padding: 20px; }
        .header { text-align: center; margin-bottom: 20px; }
        .header h2, .header h3, .header p { margin: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #000; padding: 5px; text-align: center; font-size: 11px; }
        th { background-color: #f2f2f2; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .text-left { text-align: left; }
        
        .no-break {
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        @media print {
            @page { size: landscape; margin: 10mm; }
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none; }
            .no-break { page-break-inside: avoid; }
        }
        
        .print-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        .print-btn:hover { background: #0056b3; }
    </style>
</head>
<body>
    <button class="print-btn no-print" onclick="window.print()">
        Cetak / Simpan PDF
    </button>

    <div class="header">
        <h2>DAFTAR NILAI HARIAN</h2>
        <h3><?= htmlspecialchars($class_info['nama_kelas'] ?? '') ?> - <?= htmlspecialchars($mapel_info['nama_mapel'] ?? '') ?></h3>
        <p>Guru: <?= htmlspecialchars(getGuruName($pdo, $id_guru) ?: '') ?></p>
    </div>

    <table>
        <thead>
            <tr>
                <th width="5%" rowspan="3">NO</th>
                <th width="30%" rowspan="3">NAMA SISWA</th>
                <?php foreach ($grade_headers as $header): ?>
                    <th colspan="2"><?= htmlspecialchars($header['nama_penilaian'] ?? '') ?></th>
                <?php endforeach; ?>
                <th width="10%" rowspan="3">RERATA</th>
            </tr>
            <tr>
                <?php foreach ($grade_headers as $header): ?>
                    <th colspan="2" style="font-weight: normal; font-style: italic; background-color: #fff;">
                        <?= htmlspecialchars($header['materi'] ?? '-') ?>
                    </th>
                <?php endforeach; ?>
            </tr>
            <tr>
                <?php foreach ($grade_headers as $header): ?>
                    <th>Nilai</th>
                    <th>Jadi</th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            foreach ($students as $student): 
            ?>
            <tr>
                <td><?= $no++ ?></td>
                <td class="text-left"><?= htmlspecialchars($student['nama_siswa']) ?></td>
                
                <?php
                $total = 0;
                $count = 0;
                
                foreach ($grade_headers as $header) {
                    $data = isset($grades_data[$student['id_siswa']][$header['id_header']]) 
                            ? $grades_data[$student['id_siswa']][$header['id_header']] 
                            : ['nilai' => '', 'nilai_jadi' => ''];
                    
                    $nilai = $data['nilai'];
                    $nilai_jadi = $data['nilai_jadi'];
                    
                    echo '<td>' . htmlspecialchars($nilai ?? '') . '</td>';
                    echo '<td>' . htmlspecialchars($nilai_jadi ?? '') . '</td>';
                    
                    // For average, use nilai (original) to match table
                    $valForAvg = $nilai;
                    
                    if ($valForAvg !== '' && $valForAvg !== null) {
                        $total += (float)$valForAvg;
                        $count++;
                    }
                }
                
                $avg = $count > 0 ? round($total / $count, 1) : '-';
                echo '<td>' . $avg . '</td>';
                ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div class="no-break" style="margin-top: 30px; text-align: right; margin-right: 50px;">
        <p>Jepara, <?= date('d F Y') ?></p>
        <p>Wali Kelas</p>
        <br><br><br>
        <p><b><?= htmlspecialchars($class_info['wali_kelas'] ?? '') ?></b></p>
    </div>

    <script>
        window.onload = function() {
            window.print();
        }
    </script>
</body>
</html>
