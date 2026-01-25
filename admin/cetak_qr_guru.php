<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check authorization
if (!isAuthorized(['admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$teachers = [];
$title = "Cetak QR Code Guru";

// Case 1: Print Single Teacher
if (isset($_GET['id'])) {
    $id_guru = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([$id_guru]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($teacher) {
        $teachers[] = $teacher;
        $title = "QR Code - " . $teacher['nama_guru'];
    }
} 
// Case 2: Print All Teachers
elseif (isset($_GET['all'])) {
    $stmt = $pdo->query("SELECT * FROM tb_guru ORDER BY nama_guru ASC");
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $title = "QR Code Semua Guru";
}

if (empty($teachers)) {
    die("Data guru tidak ditemukan.");
}

// Get school profile for header
$school_profile = getSchoolProfile($pdo);
$school_name = strtoupper($school_profile['nama_madrasah'] ?? 'SEKOLAH');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title; ?></title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.7.2/css/all.css" integrity="sha384-fnmOCqbTlWIlj8LyTjo7mOUStjsKC4pOpQbqyi7RrhN7udi9RwhKkMHpvLbHG9Sr" crossorigin="anonymous">
    <style>
        body {
            background-color: #f4f6f9;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .qr-grid {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-start;
            margin: 0 -0.4cm;
        }
        .qr-col {
            width: 6.2cm;
            padding: 0 0.4cm;
            margin-bottom: 25px;
            flex: 0 0 6.2cm;
        }
        .qr-card {
            width: 5.4cm;
            height: 8.6cm;
            background: #fff;
            border: 1px solid #999;
            border-radius: 8px;
            padding: 10px 5px;
            text-align: center;
            margin: 0 auto;
            page-break-inside: avoid;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            position: relative;
            overflow: hidden;
        }
        .qr-code {
            width: 3.5cm;
            height: 3.5cm;
            margin: 1.0cm auto 10px auto;
            object-fit: contain;
        }
        .teacher-name {
            font-size: 11pt;
            font-weight: bold;
            margin-top: 5px;
            margin-bottom: 2px;
            line-height: 1.2;
            word-wrap: break-word;
            max-width: 100%;
        }
        .teacher-nuptk {
            font-size: 9pt;
            color: #333;
            margin-bottom: 0;
        }
        .school-name {
            font-size: 8pt;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 5px;
            color: #333;
            border-bottom: 2px solid #333;
            padding-bottom: 5px;
            width: 100%;
            line-height: 1.2;
        }
        
        @media print {
            @page {
                size: 330mm 215mm; /* F4 Landscape */
                margin: 10mm;
            }
            body {
                background: none;
                margin: 0;
            }
            .container {
                width: 100% !important;
                max-width: none !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .qr-grid {
                display: block !important;
            }
            .qr-col {
                float: left !important;
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .qr-card {
                border: 1px solid #000;
                box-shadow: none;
            }

            #print-controls, .no-print {
                display: none !important;
                visibility: hidden !important;
            }
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div id="print-controls" class="mb-4 text-center no-print d-print-none">
            <button onclick="window.print()" class="btn btn-primary btn-lg"><i class="fas fa-print"></i> Cetak QR Code</button>
            <button onclick="window.close()" class="btn btn-secondary btn-lg ml-2">Tutup</button>
        </div>
        
        <div class="qr-grid">
            <?php foreach ($teachers as $teacher): ?>
            <div class="qr-col">
                <div class="qr-card">
                    <div class="school-name"><?php echo htmlspecialchars($school_name); ?></div>
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo $teacher['nuptk']; ?>" alt="QR Code" class="qr-code">
                    <div class="teacher-name"><?php echo htmlspecialchars($teacher['nama_guru']); ?></div>
                    <div class="teacher-nuptk">NUPTK: <?php echo htmlspecialchars($teacher['nuptk']); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
