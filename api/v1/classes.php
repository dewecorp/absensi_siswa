<?php
/**
 * Class Data API Endpoint (Central Hub)
 * Used for synchronization with external applications like Rapor.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once '../../config/database.php';

// API key diambil dari tb_pengaturan_api (menu Pengaturan Endpoint). Fallback ke key lama bila tabel belum ada.
$__api_key_row = null;
try {
    $__api_key_row = $pdo->query("SELECT api_key FROM tb_pengaturan_api ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $__api_key_row = null; }
define('API_KEY', ($__api_key_row && !empty($__api_key_row['api_key'])) ? (string)$__api_key_row['api_key'] : 'SIS_CENTRAL_HUB_SECRET_2026');

$headers = getallheaders();
$provided_key = $_GET['api_key'] ?? ($headers['X-API-KEY'] ?? '');

if ($provided_key !== API_KEY) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

try {
    $stmt = $pdo->query("SELECT id_kelas, nama_kelas, wali_kelas FROM tb_kelas ORDER BY nama_kelas ASC");
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'total_data' => count($classes),
        'data' => $classes
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
