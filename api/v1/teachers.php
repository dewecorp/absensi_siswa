<?php
/**
 * Teacher Data API Endpoint (Central Hub)
 * Sinkronisasi data guru SIMAD ke aplikasi eksternal.
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

// Pastikan kolom pendidikan ada (tanpa memuat functions.php agar hindari efek sesi pada API).
try {
    if (!$pdo->query("SHOW COLUMNS FROM tb_guru LIKE 'pendidikan'")->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec('ALTER TABLE tb_guru ADD COLUMN pendidikan VARCHAR(10) DEFAULT NULL AFTER jenis_kelamin');
    }
} catch (PDOException $e) {
    error_log('api/v1/teachers.php kolom pendidikan: ' . $e->getMessage());
}

// API key diambil dari tb_pengaturan_api (menu Pengaturan Endpoint). Fallback ke key lama bila tabel belum ada.
$__api_key_row = null;
try {
    $__api_key_row = $pdo->query("SELECT api_key FROM tb_pengaturan_api ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $__api_key_row = null; }
define('API_KEY', ($__api_key_row && !empty($__api_key_row['api_key'])) ? (string)$__api_key_row['api_key'] : 'SIS_CENTRAL_HUB_SECRET_2026');

$headers = function_exists('getallheaders') ? getallheaders() : [];
$provided_key = $_GET['api_key'] ?? ($headers['X-API-KEY'] ?? ($headers['x-api-key'] ?? ''));
$updated_since = trim((string)($_GET['updated_since'] ?? ''));
$limit = (int)($_GET['limit'] ?? 0);

if ($provided_key !== API_KEY) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized: Invalid or missing API Key.'
    ]);
    exit;
}

try {
    $has_updated_at = (bool)$pdo->query("SHOW COLUMNS FROM tb_guru LIKE 'updated_at'")->fetch(PDO::FETCH_ASSOC);

    $updated_since_sql = '';
    $params = [];
    if ($updated_since !== '') {
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $updated_since);
        if (!$dt || $dt->format('Y-m-d H:i:s') !== $updated_since) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Parameter updated_since tidak valid. Gunakan format: Y-m-d H:i:s'
            ]);
            exit;
        }

        if (!$has_updated_at) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => "Sinkron incremental belum tersedia karena kolom updated_at belum ada di tb_guru. Gunakan full sync tanpa parameter updated_since.",
                'supported_params' => ['api_key', 'limit']
            ]);
            exit;
        }

        $updated_since_sql = " WHERE g.updated_at >= :updated_since ";
        $params[':updated_since'] = $updated_since;
    }

    $limit_sql = '';
    if ($limit > 0) {
        $safe_limit = max(1, min($limit, 1000));
        $limit_sql = " LIMIT {$safe_limit} ";
    }

    $query = "
        SELECT
            g.id_guru,
            g.nama_guru,
            g.kode_guru,
            g.email,
            g.nuptk,
            g.tempat_lahir,
            g.tanggal_lahir,
            g.jenis_kelamin,
            g.pendidikan,
            g.tmt,
            g.jabatan,
            g.wali_kelas,
            g.mengajar,
            g.foto,
            GROUP_CONCAT(DISTINCT k.nama_kelas ORDER BY k.nama_kelas SEPARATOR ', ') AS kelas_wali
        FROM tb_guru g
        LEFT JOIN tb_kelas k ON k.wali_kelas = g.nama_guru
        {$updated_since_sql}
        GROUP BY
            g.id_guru, g.nama_guru, g.kode_guru, g.email, g.nuptk, g.tempat_lahir,
            g.tanggal_lahir, g.jenis_kelamin, g.pendidikan, g.jabatan, g.wali_kelas, g.mengajar, g.foto
        ORDER BY g.nama_guru ASC
        {$limit_sql}
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($teachers as &$teacher) {
        $teacher['kelas_wali'] = !empty($teacher['kelas_wali']) ? $teacher['kelas_wali'] : null;
        if (!array_key_exists('pendidikan', $teacher) || $teacher['pendidikan'] === '' || $teacher['pendidikan'] === null) {
            $teacher['pendidikan'] = null;
        }
        if (!array_key_exists('jabatan', $teacher) || $teacher['jabatan'] === '' || $teacher['jabatan'] === null) {
            $teacher['jabatan'] = null;
        }
        if (!array_key_exists('email', $teacher) || $teacher['email'] === '' || $teacher['email'] === null) {
            $teacher['email'] = null;
        }

        $mengajar_decoded = null;
        if (!empty($teacher['mengajar'])) {
            $decoded = json_decode((string)$teacher['mengajar'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $mengajar_decoded = $decoded;
            }
        }
        $teacher['mengajar_list'] = $mengajar_decoded;
    }
    unset($teacher);

    echo json_encode([
        'status' => 'success',
        'sync_mode' => $updated_since !== '' ? 'incremental' : 'full',
        'filter_updated_since' => $updated_since !== '' ? $updated_since : null,
        'total_data' => count($teachers),
        'last_sync' => date('Y-m-d H:i:s'),
        'data' => $teachers
    ], JSON_PRETTY_PRINT);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
