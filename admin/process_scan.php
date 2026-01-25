<?php
require_once '../config/database.php';
require_once '../config/functions.php';

header('Content-Type: application/json');

// Check authorization
if (!isAuthorized(['admin', 'guru', 'wali', 'kepala_madrasah'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['nisn'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request'
    ]);
    exit;
}

$scanned_code = trim($_POST['nisn']); // Can be NISN or NUPTK
$today = date('Y-m-d');
$currentTime = date('H:i:s');
$currentDateTime = date('Y-m-d H:i:s');

try {
    // 1. Try to find student by NISN
    $stmt = $pdo->prepare("
        SELECT s.*, k.nama_kelas 
        FROM tb_siswa s 
        LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas 
        WHERE s.nisn = ?
    ");
    $stmt->execute([$scanned_code]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($student) {
        // --- STUDENT ATTENDANCE LOGIC ---
        
        // Check if already attended today
        $checkStmt = $pdo->prepare("SELECT * FROM tb_absensi WHERE id_siswa = ? AND tanggal = ?");
        $checkStmt->execute([$student['id_siswa'], $today]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            echo json_encode([
                'success' => false,
                'message' => "Siswa a.n. {$student['nama_siswa']} sudah absen hari ini (Status: {$existing['keterangan']})",
                'icon' => 'warning',
                'title' => 'Sudah Absen'
            ]);
            exit;
        }

        // Determine id_guru (who scanned). If admin, id_guru might be null or special.
        $id_guru = ($_SESSION['level'] === 'admin') ? NULL : $_SESSION['user_id'];
        
        // Default status 'Hadir' for scan
        $keterangan = 'Hadir';

        $insertStmt = $pdo->prepare("INSERT INTO tb_absensi (id_siswa, tanggal, keterangan, id_guru, jam_masuk) VALUES (?, ?, ?, ?, ?)");
        $insertStmt->execute([$student['id_siswa'], $today, $keterangan, $id_guru, $currentTime]);

        // Log activity (optional, if logActivity exists)
        // $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
        // logActivity($pdo, $username, 'Scan Absensi', "Siswa {$student['nama_siswa']} ($scanned_code) absen melalui scan QR");

        echo json_encode([
            'success' => true,
            'message' => "Absensi Siswa berhasil dicatat: {$student['nama_siswa']}",
            'data' => [
                'nama_siswa' => $student['nama_siswa'],
                'nisn' => $student['nisn'],
                'kelas' => $student['nama_kelas'] ?? '-',
                'keterangan' => $keterangan,
                'jam_masuk' => $currentTime,
                'type' => 'siswa'
            ]
        ]);
        exit;
    }

    // 2. Try to find teacher by NUPTK
    $stmt = $pdo->prepare("SELECT * FROM tb_guru WHERE nuptk = ?");
    $stmt->execute([$scanned_code]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($teacher) {
        // --- TEACHER ATTENDANCE LOGIC ---
        
        // Check if already attended today
        $checkStmt = $pdo->prepare("SELECT * FROM tb_absensi_guru WHERE id_guru = ? AND tanggal = ?");
        $checkStmt->execute([$teacher['id_guru'], $today]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            echo json_encode([
                'success' => false,
                'message' => "Guru a.n. {$teacher['nama_guru']} sudah absen hari ini (Status: {$existing['status']})",
                'icon' => 'warning',
                'title' => 'Sudah Absen'
            ]);
            exit;
        }

        $status = 'Hadir';
        $keterangan_lanjut = ''; 

        // Insert into tb_absensi_guru
        // Columns: id_guru, tanggal, status, keterangan, waktu_input
        $insertStmt = $pdo->prepare("INSERT INTO tb_absensi_guru (id_guru, tanggal, status, keterangan, waktu_input) VALUES (?, ?, ?, ?, ?)");
        $insertStmt->execute([$teacher['id_guru'], $today, $status, $keterangan_lanjut, $currentDateTime]);

        echo json_encode([
            'success' => true,
            'message' => "Absensi Guru berhasil dicatat: {$teacher['nama_guru']}",
            'data' => [
                'nama_siswa' => $teacher['nama_guru'], // Using 'nama_siswa' key for frontend compatibility
                'nisn' => $teacher['nuptk'],
                'kelas' => 'Guru',
                'keterangan' => $status,
                'jam_masuk' => $currentTime,
                'type' => 'guru'
            ]
        ]);
        exit;
    }

    // 3. Not found
    echo json_encode([
        'success' => false,
        'message' => 'Data tidak ditemukan (Kode tidak dikenali sebagai Siswa atau Guru)',
        'icon' => 'error',
        'title' => 'Tidak Ditemukan'
    ]);

} catch (Exception $e) {
    error_log($e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage(),
        'icon' => 'error',
        'title' => 'Error'
    ]);
}
