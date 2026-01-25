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

$nisn = trim($_POST['nisn']);
$today = date('Y-m-d');
$currentTime = date('H:i:s');

try {
    // 1. Find student by NISN
    $stmt = $pdo->prepare("
        SELECT s.*, k.nama_kelas 
        FROM tb_siswa s 
        LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas 
        WHERE s.nisn = ?
    ");
    $stmt->execute([$nisn]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        echo json_encode([
            'success' => false,
            'message' => 'Data siswa tidak ditemukan untuk NISN: ' . htmlspecialchars($nisn),
            'icon' => 'error',
            'title' => 'Tidak Ditemukan'
        ]);
        exit;
    }

    // 2. Check if already attended today
    $checkStmt = $pdo->prepare("SELECT * FROM tb_absensi WHERE id_siswa = ? AND tanggal = ?");
    $checkStmt->execute([$student['id_siswa'], $today]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Already exists
        echo json_encode([
            'success' => false, // Treat as "fail" to show warning, or "success" with warning info? 
                                // User asked for "absensi tersimpan", if already there, maybe just show info.
                                // But JS handles success=false as error alert.
                                // Let's return success=false but with specific warning icon.
            'message' => "Siswa a.n. {$student['nama_siswa']} sudah absen hari ini (Status: {$existing['keterangan']})",
            'icon' => 'warning',
            'title' => 'Sudah Absen'
        ]);
        exit;
    }

    // 3. Insert attendance record
    // Determine id_guru (who scanned). If admin, id_guru might be null or special.
    // In absensi_harian.php: $id_guru = ($_SESSION['level'] === 'admin') ? NULL : $_SESSION['user_id'];
    $id_guru = ($_SESSION['level'] === 'admin') ? NULL : $_SESSION['user_id'];
    
    // Default status 'Hadir' for scan
    $keterangan = 'Hadir';

    $insertStmt = $pdo->prepare("INSERT INTO tb_absensi (id_siswa, tanggal, keterangan, id_guru) VALUES (?, ?, ?, ?)");
    $insertStmt->execute([$student['id_siswa'], $today, $keterangan, $id_guru]);

    // 4. Log activity
    $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
    // We don't have logActivity function defined in this file context but it is in functions.php which is required.
    // Assuming logActivity is available.
    // logActivity($pdo, $username, 'Scan Absensi', "Siswa {$student['nama_siswa']} ($nisn) absen melalui scan QR");

    // 5. Return success
    echo json_encode([
        'success' => true,
        'message' => "Absensi berhasil dicatat untuk {$student['nama_siswa']}",
        'data' => [
            'nama_siswa' => $student['nama_siswa'],
            'nisn' => $student['nisn'],
            'kelas' => $student['nama_kelas'] ?? '-',
            'keterangan' => $keterangan,
            'jam_masuk' => $currentTime
        ]
    ]);

} catch (Exception $e) {
    error_log($e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
