<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check authorization
if (!isAuthorized(['admin', 'guru', 'wali', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$students = [];
$title = "Cetak QR Code Siswa";

// Case 1: Print Single Student
if (isset($_GET['id'])) {
    $id_siswa = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT s.*, k.nama_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_siswa = ?");
    $stmt->execute([$id_siswa]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($student) {
        $students[] = $student;
        $title = "QR Code - " . $student['nama_siswa'];
    }
} 
// Case 2: Print All Students in a Class
elseif (isset($_GET['kelas'])) {
    $id_kelas = (int)$_GET['kelas'];
    $stmt = $pdo->prepare("SELECT s.*, k.nama_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_kelas = ? ORDER BY s.nama_siswa ASC");
    $stmt->execute([$id_kelas]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($students)) {
        $title = "QR Code Kelas " . $students[0]['nama_kelas'];
    }
}

if (empty($students)) {
    die("Data siswa tidak ditemukan.");
}
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
        .student-name {
            font-size: 16px;
            font-weight: bold;
            margin-top: 10px;
            margin-bottom: 5px;
        }
        .student-nisn {
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }
        .student-class {
            font-size: 14px;
            font-weight: 600;
            color: #333;
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

    <div class="container py-4">
        <div class="row mb-4 no-print">
            <div class="col-12 text-center">
                <button onclick="window.print()" class="btn btn-primary btn-lg"><i class="fas fa-print"></i> Cetak QR Code</button>
                <button onclick="window.close()" class="btn btn-secondary btn-lg ml-2">Tutup</button>
            </div>
        </div>

        <div class="row">
            <?php foreach ($students as $student): ?>
            <div class="col-md-4 col-sm-6">
                <div class="qr-card">
                    <div class="school-name">Kartu Absensi Digital</div>
                    
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo $student['nisn']; ?>" alt="QR Code" class="qr-code">
                    
                    <div class="student-name"><?php echo htmlspecialchars($student['nama_siswa']); ?></div>
                    <div class="student-nisn">NISN: <?php echo htmlspecialchars($student['nisn']); ?></div>
                    <div class="student-class"><?php echo htmlspecialchars($student['nama_kelas']); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

</body>
</html>
