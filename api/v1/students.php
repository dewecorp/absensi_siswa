<?php
/**
 * Student Data API Endpoint (Central Hub)
 * Used for synchronization with external applications like Rapor.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Allow requests from other domains
header('Access-Control-Allow-Methods: GET');

require_once '../../config/database.php';

// --- CONFIGURATION ---
// Change this key to something very secure and share it only with trusted apps.
define('API_KEY', 'SIS_CENTRAL_HUB_SECRET_2026'); 

// --- AUTHENTICATION ---
$headers = getallheaders();
$provided_key = $_GET['api_key'] ?? ($headers['X-API-KEY'] ?? '');

if ($provided_key !== API_KEY) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized: Invalid or missing API Key.'
    ]);
    exit;
}

try {
    // --- DATA RETRIEVAL ---
    $query = "SELECT s.id_siswa, s.nama_siswa, s.nisn, s.jenis_kelamin, 
                     s.tempat_lahir, s.tanggal_lahir, s.wali,
                     k.id_kelas, k.nama_kelas 
              FROM tb_siswa s 
              LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas 
              ORDER BY k.nama_kelas ASC, s.nama_siswa ASC";
    
    $stmt = $pdo->query($query);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- RESPONSE ---
    echo json_encode([
        'status' => 'success',
        'total_data' => count($students),
        'last_sync' => date('Y-m-d H:i:s'),
        'data' => $students
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
