<?php
require_once 'database.php';
require_once 'functions.php';

// Check if user is logged in and authorized
if (!isAuthorized(['admin'])) {
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

// For debugging - uncomment to log the received data
// error_log('Received table data: ' . substr($table_data, 0, 200));

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

// Include DomPDF
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Create new Dompdf instance
$options = new Options();
$options->set('defaultFont', 'Courier');
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);

// Get school profile for header
$school_profile = getSchoolProfile($pdo);

// HTML content with school information
$html = '
<html>
<head>
    <style>
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
            background-color: #368DBC;
            color: white;
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
    </style>
</head>
<body>
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
</body>
</html>';

// Load HTML to Dompdf
$dompdf->loadHtml($html);

// Set paper size and orientation
$dompdf->setPaper('A4', 'landscape');

// Render PDF
$dompdf->render();

// Output the PDF
$dompdf->stream($filename . '_' . date('Y-m-d_H-i-s') . '.pdf', ['Attachment' => true]);

exit();
?>