<?php
/**
 * Class Data API Endpoint (Central Hub)
 * Used for synchronization with external applications like Rapor.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once '../../config/database.php';

define('API_KEY', 'SIS_CENTRAL_HUB_SECRET_2026'); 

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
