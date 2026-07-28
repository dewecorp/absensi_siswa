<?php
/**
 * Student Sync API Endpoint (for Sibayar)
 * Menyediakan data siswa termasuk tanggal_masuk untuk menentukan tagihan.
 * Dipanggil oleh server Sibayar untuk mencegah siswa baru terkena tunggakan tahun lama.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-KEY');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once '../../config/database.php';

// Auto-create tanggal_masuk column
try {
    $pdo->exec("ALTER TABLE tb_siswa ADD COLUMN IF NOT EXISTS tanggal_masuk DATE DEFAULT NULL AFTER tanggal_lahir");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE tb_siswa ADD COLUMN tanggal_masuk DATE DEFAULT NULL AFTER tanggal_lahir"); } catch (PDOException $e2) {}
}

define('API_KEY', 'SIS_CENTRAL_HUB_SECRET_2026');

$headers = function_exists('getallheaders') ? getallheaders() : [];
$provided_key = $_GET['api_key'] ?? ($headers['X-API-KEY'] ?? ($headers['x-api-key'] ?? ''));
$nisn = trim((string)($_GET['nisn'] ?? ''));
$updated_since = trim((string)($_GET['updated_since'] ?? ''));
$limit = (int)($_GET['limit'] ?? 0);

if ($provided_key !== API_KEY) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid or missing API Key.']);
    exit;
}

try {
    $where = [];
    $params = [];

    if ($nisn !== '') {
        $where[] = 's.nisn = ?';
        $params[] = $nisn;
    }

    if ($updated_since !== '') {
        $where[] = 's.updated_at >= ?';
        $params[] = $updated_since;
    }

    $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $limit_clause = $limit > 0 ? 'LIMIT ' . $limit : '';

    $sql = "SELECT s.id_siswa, s.nisn, s.nama_siswa, s.jenis_kelamin,
                   s.tempat_lahir, s.tanggal_lahir, s.tanggal_masuk,
                   s.wali, k.nama_kelas, k.id_kelas,
                   s.created_at, s.updated_at
            FROM tb_siswa s
            LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas
            $where_clause
            ORDER BY s.id_siswa ASC
            $limit_clause";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format tanggal_masuk: fallback ke created_at jika null, pastikan format Y-m-d
    foreach ($students as &$s) {
        if (empty($s['tanggal_masuk'])) {
            $s['tanggal_masuk'] = $s['created_at'] ? date('Y-m-d', strtotime($s['created_at'])) : null;
        }
    }
    unset($s);

    // Auto-set tanggal_masuk di database untuk record yang masih null (proses satu kali)
    $pdo->exec("UPDATE tb_siswa SET tanggal_masuk = DATE(created_at) WHERE tanggal_masuk IS NULL AND created_at IS NOT NULL");

    echo json_encode([
        'status' => 'success',
        'total' => count($students),
        'data' => $students
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
