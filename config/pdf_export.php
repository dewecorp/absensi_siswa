<?php
require_once 'database.php';
require_once 'functions.php';

// Check if user is logged in and authorized
if (!isAuthorized(['admin', 'tata_usaha'])) {
    redirect('../login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../admin/data_guru.php');
}

// Get the table data from POST
$table_data = $_POST['table_data'] ?? '';
$export_type = $_POST['export_type'] ?? '';
$report_title = $_POST['report_title'] ?? 'Data Guru';
$filename = $_POST['filename'] ?? 'data_guru';

if (empty($table_data)) {
    // If no table data, fetch from database and create basic table
    // Only execute this fallback if we are actually exporting data guru
    if ($report_title === 'Data Guru') {
        $stmt = $pdo->query("
            SELECT g.*, 
                   GROUP_CONCAT(k.nama_kelas ORDER BY k.nama_kelas SEPARATOR ', ') as kelas_wali
            FROM tb_guru g
            LEFT JOIN tb_kelas k ON k.wali_kelas = g.nama_guru
            GROUP BY g.id_guru
            ORDER BY g.nama_guru ASC
        ");
        $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Decode mengajar JSON
        $classes_stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
        $classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
        $class_lookup = [];
        foreach ($classes as $kelas) {
            $class_lookup[$kelas['id_kelas']] = $kelas['nama_kelas'];
        }
        
        // Build a basic HTML table
        $table_data = '<table><thead><tr><th>No</th><th>Nama Guru</th><th>NUPTK</th><th>Tempat Lahir</th><th>Tanggal Lahir</th><th>Jenis Kelamin</th><th>Mengajar</th><th>Wali Kelas</th></tr></thead><tbody>';
        $no = 1;
        foreach ($teachers as $teacher) {
            // Decode mengajar JSON and get class names
            $mengajar_names = [];
            if (!empty($teacher['mengajar'])) {
                $decoded = json_decode($teacher['mengajar'], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $kelas_id) {
                        $kelas_id = (string)$kelas_id;
                        if (isset($class_lookup[(int)$kelas_id])) {
                            $mengajar_names[] = $class_lookup[(int)$kelas_id];
                        } elseif (isset($class_lookup[$kelas_id])) {
                            $mengajar_names[] = $class_lookup[$kelas_id];
                        }
                    }
                }
            }
            $mengajar_display = !empty($mengajar_names) ? implode(', ', $mengajar_names) : '-';
            
            $table_data .= '<tr>';
            $table_data .= '<td>' . $no++ . '</td>';
            $table_data .= '<td>' . htmlspecialchars($teacher['nama_guru']) . '</td>';
            $table_data .= '<td>' . htmlspecialchars($teacher['nuptk']) . '</td>';
            $table_data .= '<td>' . htmlspecialchars($teacher['tempat_lahir']) . '</td>';
            $table_data .= '<td>' . ($teacher['tanggal_lahir'] ? date('d-m-Y', strtotime($teacher['tanggal_lahir'])) : '-') . '</td>';
            $table_data .= '<td>' . htmlspecialchars($teacher['jenis_kelamin']) . '</td>';
            $table_data .= '<td>' . htmlspecialchars($mengajar_display) . '</td>';
            $table_data .= '<td>' . htmlspecialchars($teacher['kelas_wali'] ?? '-') . '</td>';
            $table_data .= '</tr>';
        }
        $table_data .= '</tbody></table>';
    } else {
        die("No data provided for export.");
    }
}

// Get school profile for header
$school_profile = getSchoolProfile($pdo);

// HTML content with school information
$html = '
<!DOCTYPE html>
<html>
<head>
    <title>' . htmlspecialchars($report_title) . '</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        @page {
            size: landscape;
            margin: 10mm;
        }
        @media print {
            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .no-print {
                display: none !important;
            }
        }
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .header h2 {
            margin: 0;
            color: #333;
        }
        .header p {
            margin: 5px 0;
            color: #666;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #368DBC !important; /* Important for print */
            color: white !important;
            font-weight: bold;
            text-align: center;
        }
        tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 12px;
            color: #666;
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
            z-index: 9999;
            font-size: 14px;
        }
        .print-btn:hover {
            background: #0056b3;
        }
        .print-btn i {
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <button class="print-btn no-print" onclick="window.print()">
        <i class="fas fa-print"></i> Cetak / Simpan PDF
    </button>

    <div class="header">
        <h2>' . strtoupper($report_title) . '</h2>
        <p>' . ($school_profile['nama_madrasah'] ?? 'Sistem Absensi Siswa') . '</p>
        <p>Dicetak pada: ' . date('d/m/Y H:i:s') . '</p>
    </div>
';

// Add the table data
$html .= $table_data;

$html .= '
    <div class="footer">
        Laporan ' . $report_title . ' - Sistem Absensi Siswa
    </div>

    <script>
        window.onload = function() {
            window.print();
        }
    </script>
</body>
</html>';

echo $html;
?>
