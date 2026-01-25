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
        }
        .qr-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
            page-break-inside: avoid;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .qr-code {
            width: 150px;
            height: 150px;
            margin: 10px auto;
        }
        .teacher-name {
            font-size: 16px;
            font-weight: bold;
            margin-top: 10px;
            margin-bottom: 5px;
        }
        .teacher-nuptk {
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }
        .school-name {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 15px;
            color: #555;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        
        @media print {
            body {
                background: none;
            }
            .no-print {
                display: none;
            }
            .col-md-4 {
                width: 33.333333%;
                float: left;
            }
            .qr-card {
                border: 1px solid #000;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="row no-print mb-4">
            <div class="col-12 text-center">
                <button onclick="window.print()" class="btn btn-primary btn-lg"><i class="fas fa-print"></i> Cetak QR Code</button>
                <button onclick="window.close()" class="btn btn-secondary btn-lg ml-2">Tutup</button>
            </div>
        </div>
        
        <div class="row">
            <?php foreach ($teachers as $teacher): ?>
            <div class="col-md-4 col-sm-6">
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
