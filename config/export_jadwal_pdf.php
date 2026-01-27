<?php
require_once 'database.php';
require_once 'functions.php';

// Check auth
if (!isAuthorized(['admin', 'tata_usaha', 'guru', 'kepala_madrasah', 'wali', 'siswa'])) {
    die("Unauthorized access");
}

$kelas_id = isset($_GET['kelas_id']) ? (int)$_GET['kelas_id'] : null;
$jenis = isset($_GET['jenis']) ? $_GET['jenis'] : 'Reguler'; // Reguler or Ramadhan
$title_jenis = strtoupper($jenis);

// Get School Profile
$school_profile = getSchoolProfile($pdo);
$kepala_madrasah = $school_profile['kepala_madrasah'] ?? '-';
$logo_file = $school_profile['logo'] ?? '';
$logo_path = '';
if ($logo_file && file_exists(__DIR__ . '/../assets/img/' . $logo_file)) {
    $logo_path = '../assets/img/' . $logo_file;
} elseif (file_exists(__DIR__ . '/../assets/img/logo.png')) {
    $logo_path = '../assets/img/logo.png';
}

// Prepare Data
$days = ['Sabtu', 'Ahad', 'Senin', 'Selasa', 'Rabu', 'Kamis'];

// Get Classes
if ($kelas_id) {
    $stmt = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas = ?");
    $stmt->execute([$kelas_id]);
    $kelas_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $classes = [$kelas_data];
    
    // For Class Schedule
    if ($jenis === 'Ramadhan') {
        $display_title = "JADWAL PELAJARAN RAMADHAN KELAS " . strtoupper($kelas_data['nama_kelas']);
    } else {
        $display_title = "JADWAL PELAJARAN KELAS " . strtoupper($kelas_data['nama_kelas']);
    }
    $tahun_file = isset($school_profile['tahun_ajaran']) ? str_replace(['/', ' '], ['-', '_'], $school_profile['tahun_ajaran']) : date('Y');
    $page_title = "jadwal_kelas_" . strtolower($jenis) . "_" . str_replace(['/', ' '], ['-', '_'], $kelas_data['nama_kelas']) . "_" . $tahun_file;
    
    $wali_kelas = $kelas_data['wali_kelas'];
} else {
    $stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // For Main Schedule
    if ($jenis === 'Ramadhan') {
        $display_title = "JADWAL PELAJARAN RAMADHAN";
    } else {
        $display_title = "JADWAL PELAJARAN";
    }
    $tahun_file = isset($school_profile['tahun_ajaran']) ? str_replace(['/', ' '], ['-', '_'], $school_profile['tahun_ajaran']) : date('Y');
    $page_title = "jadwal_utama_" . strtolower($jenis) . "_" . $tahun_file;
}

// Get Mapel Map
$stmt = $pdo->query("SELECT * FROM tb_mata_pelajaran ORDER BY nama_mapel ASC");
$mapels = $stmt->fetchAll(PDO::FETCH_ASSOC);
$mapel_map = [];
foreach ($mapels as $m) {
    $mapel_map[$m['id_mapel']] = $m;
}

// Get Guru Map
$stmt = $pdo->query("SELECT * FROM tb_guru ORDER BY nama_guru ASC");
$gurus = $stmt->fetchAll(PDO::FETCH_ASSOC);
$guru_map = [];
foreach ($gurus as $g) {
    $guru_map[$g['id_guru']] = $g;
}

// Get Jam Mengajar (Ordered by Waktu Mulai)
$stmt = $pdo->prepare("SELECT * FROM tb_jam_mengajar WHERE jenis = ? ORDER BY waktu_mulai ASC");
$stmt->execute([$jenis]);
$jam_mengajar = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Schedule Data
$stmt = $pdo->prepare("
    SELECT * FROM tb_jadwal_pelajaran 
    WHERE jenis = ?
");
$stmt->execute([$jenis]);
$all_schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

$main_schedule = [];
$used_jam_ke = [];
foreach ($all_schedules as $row) {
    $main_schedule[$row['hari']][$row['jam_ke']][$row['kelas_id']] = $row;
    // Only mark as used if this schedule belongs to one of the selected classes
    foreach ($classes as $c) {
        if ($c['id_kelas'] == $row['kelas_id']) {
            $used_jam_ke[$row['jam_ke']] = true;
        }
    }
}

// Filter Jam Mengajar to only used slots
$jam_display = array_filter($jam_mengajar, function($jam) use ($used_jam_ke) {
    return isset($used_jam_ke[$jam['jam_ke']]);
});

// HTML Content
$html = '
<!DOCTYPE html>
<html>
<head>
    <title>' . $page_title . '</title>
    <style>
        @page {
            size: 330mm 215mm; /* F4 / Folio Landscape */
            margin: 10mm;
        }
        @media print {
            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .no-print {
                display: none;
            }
        }
        body { font-family: Arial, sans-serif; font-size: 10pt; }
        .header { text-align: center; margin-bottom: 10px; border-bottom: 3px double black; padding-bottom: 5px; position: relative; min-height: 90px; }
        .header img { width: 80px; position: absolute; left: 10px; top: 5px; }
        .header h2, .header h3, .header p { margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 5px; }
        th, td { border: 1px solid black; padding: 3px; text-align: center; font-size: 9pt; word-wrap: break-word; }
        th { background-color: #f0f0f0; }
        .signature-table { margin-top: 15px; border: none; page-break-inside: avoid; }
        .signature-table td { border: none; vertical-align: top; text-align: center; padding: 10px; font-size: 10pt; }
        .special-slot { background-color: #f9f9f9; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
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
            z-index: 9999;
        }
        .print-btn:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <button class="print-btn no-print" onclick="window.print()">
        <i class="fas fa-print"></i> Cetak / Simpan PDF
    </button>

    <div class="header">';
    
if ($logo_path) {
    // For HTML print, we can use direct path or base64. Base64 is safer for cross-browser printing sometimes.
    // Reusing the base64 logic just in case path access is restricted, though relative path usually works.
    // Actually, simple src is better for HTML if accessible.
    // Let's use base64 to be 100% sure it prints.
    $type = pathinfo($logo_path, PATHINFO_EXTENSION);
    $data = file_get_contents($logo_path);
    $base64 = 'data:image/' . $type . ';base64,' . base64_encode($data);
    $html .= '<img src="' . $base64 . '">';
}

$html .= '
        <h3>' . strtoupper($school_profile['nama_yayasan'] ?? 'YAYASAN PENDIDIKAN ISLAM') . '</h3>
        <h2>' . strtoupper($school_profile['nama_madrasah']) . '</h2>
        <p style="font-size: 12pt; font-weight: bold;">Tahun Ajaran: ' . ($school_profile['tahun_ajaran'] ?? '') . '</p>
    </div>

    <h3 style="text-align: center;">' . $display_title . '</h3>

    <table>
        <thead>';

if ($kelas_id) {
    // --- JADWAL KELAS (Single Class) ---
    $html .= '
            <tr>
                <th rowspan="2" style="width: 40px;">JAM<br>KE</th>
                <th rowspan="2" style="width: 80px;">WAKTU</th>';
    
    foreach ($days as $day) {
        $html .= '<th>' . strtoupper($day) . '</th>';
    }
    $html .= '
            </tr>
            <tr>';
            
    foreach ($days as $day) {
        // Find teacher for this day (First valid teacher in schedule)
        $teacher_name = '-';
        if (isset($main_schedule[$day])) {
            foreach ($main_schedule[$day] as $jam_data) {
                if (isset($jam_data[$classes[0]['id_kelas']])) {
                    $sched = $jam_data[$classes[0]['id_kelas']];
                    if (!empty($sched['guru_id']) && isset($guru_map[$sched['guru_id']])) {
                        $teacher_name = $guru_map[$sched['guru_id']]['nama_guru'];
                        break; 
                    }
                }
            }
        }
        $html .= '<th style="font-size: 8pt;">' . htmlspecialchars($teacher_name) . '</th>';
    }
    $html .= '</tr>';

} else {
    // --- JADWAL UTAMA (All Classes) ---
    $html .= '
            <tr>
                <th rowspan="2" style="width: 40px;">JAM<br>KE</th>
                <th rowspan="2" style="width: 80px;">WAKTU</th>';
    
    foreach ($days as $day) {
        $html .= '<th colspan="' . count($classes) . '">' . strtoupper($day) . '</th>';
    }
    $html .= '
            </tr>
            <tr>';
            
    foreach ($days as $day) {
        foreach ($classes as $c) {
            $html .= '<th>' . $c['nama_kelas'] . '</th>';
        }
    }
    $html .= '</tr>';
}

$html .= '
        </thead>
        <tbody>';

foreach ($jam_display as $jam) {
    $jam_label = $jam['jam_ke'];
    $is_special = in_array(strtoupper((string)$jam_label), ['A', 'B', 'C', 'D']);
    $waktu = date('H.i', strtotime($jam['waktu_mulai'])) . '-' . date('H.i', strtotime($jam['waktu_selesai']));
    
    $html .= '<tr>';
    $html .= '<td>' . $jam_label . '</td>';
    $html .= '<td>' . $waktu . '</td>';
    
    foreach ($days as $day) {
        // Special slot logic
        $special_text = '';
        if ($is_special) {
            if (isset($main_schedule[$day][$jam_label])) {
                foreach ($main_schedule[$day][$jam_label] as $sched) {
                    if (isset($mapel_map[$sched['mapel_id']])) {
                        $special_text = $mapel_map[$sched['mapel_id']]['nama_mapel'];
                        break;
                    }
                }
            }
        }
        
        if ($is_special) {
            $html .= '<td colspan="' . count($classes) . '" class="special-slot">' . htmlspecialchars($special_text) . '</td>';
        } else {
            foreach ($classes as $c) {
                $content = '';
                if (isset($main_schedule[$day][$jam_label][$c['id_kelas']])) {
                    $sched = $main_schedule[$day][$jam_label][$c['id_kelas']];
                    if (isset($mapel_map[$sched['mapel_id']])) {
                        $m = $mapel_map[$sched['mapel_id']];
                        // If Single Class (Jadwal Kelas), show full name, else show code
                        if ($kelas_id) {
                            $content = '<b>' . $m['nama_mapel'] . '</b>';
                        } else {
                            $content = $m['kode_mapel'] ?: $m['nama_mapel'];
                        }
                    }
                }
                $html .= '<td>' . $content . '</td>';
            }
        }
    }
    $html .= '</tr>';
}

$html .= '
        </tbody>
    </table>

    <table class="signature-table" style="width: 100%;">
        <tr>';

// Signatures
$date_str = !empty($school_profile['tanggal_jadwal']) 
    ? formatDateIndonesia($school_profile['tanggal_jadwal']) 
    : formatDateIndonesia(date('Y-m-d'));

if ($kelas_id) {
    // Jadwal Kelas: Mengetahui (Kepala), Tanggal & Wali Kelas
    $html .= '
            <td width="50%">
                Mengetahui,<br>
                Kepala Madrasah<br>
                <br><br><br><br>
                <b>' . htmlspecialchars($kepala_madrasah) . '</b>
            </td>
            <td width="50%">
                Jepara, ' . $date_str . '<br>
                Wali Kelas ' . $classes[0]['nama_kelas'] . '<br>
                <br><br><br><br>
                <b>' . htmlspecialchars($wali_kelas) . '</b>
            </td>';
} else {
    // Jadwal Utama: Mengetahui (Kepala)
    $html .= '
            <td width="50%"></td> <!-- Empty Left -->
            <td width="50%">
                Jepara, ' . $date_str . '<br>
                Kepala Madrasah<br>
                <br><br><br><br>
                <b>' . htmlspecialchars($kepala_madrasah) . '</b>
            </td>';
}

$html .= '
        </tr>
    </table>
    
    <script>
        // Auto print when loaded
        // window.onload = function() {
        //    window.print();
        // }
    </script>
</body>
</html>';

echo $html;
?>