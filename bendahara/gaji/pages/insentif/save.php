<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$request = $_POST;

if ($method !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
    exit();
}

$conn->query("CREATE TABLE IF NOT EXISTS insentif (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_insentif VARCHAR(100) NOT NULL,
    jumlah_insentif DECIMAL(15,2) NOT NULL DEFAULT 0,
    kali INT UNSIGNED NOT NULL DEFAULT 1,
    aktif TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$chk_kali = $conn->query("SHOW COLUMNS FROM insentif LIKE 'kali'");
if ($chk_kali && $chk_kali->num_rows === 0) {
    $conn->query("ALTER TABLE insentif ADD COLUMN kali INT UNSIGNED NOT NULL DEFAULT 1 AFTER jumlah_insentif");
}

$conn->query("CREATE TABLE IF NOT EXISTS insentif_detail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guru_id INT NOT NULL,
    insentif_id INT NOT NULL,
    jumlah DECIMAL(15,2) NOT NULL DEFAULT 0,
    periode VARCHAR(7) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_guru_id (guru_id),
    INDEX idx_insentif_id (insentif_id),
    CONSTRAINT fk_insentif_detail_guru FOREIGN KEY (guru_id) REFERENCES guru(id) ON DELETE CASCADE,
    CONSTRAINT fk_insentif_detail_insentif FOREIGN KEY (insentif_id) REFERENCES insentif(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success' => false, 'message' => 'Token tidak valid. Silakan refresh halaman.']); exit(); }

if (empty($request)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request'
    ]);
    exit();
}

$id_raw = $request['id'] ?? null;
$id = null;
if ($id_raw !== null && $id_raw !== '' && $id_raw !== '0') {
    $id = intval($id_raw);
    if ($id <= 0) {
        $id = null;
    }
}

$nama_insentif = trim($request['nama_insentif'] ?? '');
$jumlah_insentif_raw = $request['jumlah_insentif_hidden'] ?? $request['jumlah_insentif'] ?? '0';
$jumlah_insentif_cleaned = preg_replace('/[^0-9.]/', '', $jumlah_insentif_raw);
$jumlah_insentif_cleaned = str_replace(',', '.', $jumlah_insentif_cleaned);
$jumlah_insentif = floatval($jumlah_insentif_cleaned);

$kali_raw = isset($request['kali_hidden']) ? trim((string)$request['kali_hidden']) : '1';
$kali_digits = preg_replace('/[^0-9]/', '', $kali_raw);
$kali = $kali_digits !== '' ? (int)$kali_digits : 1;
if ($kali < 1) {
    $kali = 1;
}

$total_ke_guru = round($jumlah_insentif * (float)$kali, 2);
if ($total_ke_guru <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Total insentif (jumlah × kali) tidak valid'
    ]);
    exit();
}

$aktif = isset($request['aktif']) ? 1 : 0;

if (empty($nama_insentif)) {
    echo json_encode([
        'success' => false,
        'message' => 'Nama insentif tidak boleh kosong'
    ]);
    exit();
}

if ($jumlah_insentif <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Jumlah insentif harus lebih besar dari 0'
    ]);
    exit();
}

if ($id && $id > 0) {
    $sql = "UPDATE insentif SET nama_insentif=?, jumlah_insentif=?, kali=?, aktif=? WHERE id=?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Gagal menyiapkan query: ' . $conn->error
        ]);
        exit();
    }
    $stmt->bind_param("sdiii", $nama_insentif, $jumlah_insentif, $kali, $aktif, $id);
    $action = 'mengubah';
} else {
    $sql = "INSERT INTO insentif (nama_insentif, jumlah_insentif, kali, aktif) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Gagal menyiapkan query: ' . $conn->error
        ]);
        exit();
    }
    $stmt->bind_param("sdii", $nama_insentif, $jumlah_insentif, $kali, $aktif);
    $action = 'menambah';
}

$execute_result = $stmt->execute();
if (!$execute_result) {
    $error_msg = $stmt->error ? $stmt->error : "Database error";
    echo json_encode([
        'success' => false,
        'message' => 'Gagal ' . ($id ? 'mengubah' : 'menambah') . ' data insentif: ' . $error_msg
    ]);
    $stmt->close();
    exit();
}

$insentif_id = $id ? $id : $conn->insert_id;

$guru_ids = [];
if (isset($request['guru_ids']) && is_array($request['guru_ids'])) {
    $guru_ids = $request['guru_ids'];
}

if (empty($guru_ids)) {
    foreach ($request as $key => $value) {
        if (strpos($key, 'guru_ids') !== false) {
            if (is_array($value)) {
                $guru_ids = array_merge($guru_ids, $value);
            } else {
                $guru_ids[] = $value;
            }
        }
    }
}

$guru_ids = array_filter(array_map('intval', $guru_ids), function($gid) {
    return $gid > 0;
});
$guru_ids = array_values($guru_ids);

if (empty($guru_ids)) {
    echo json_encode([
        'success' => false,
        'message' => 'Pilih minimal satu guru untuk insentif ini'
    ]);
    $stmt->close();
    exit();
}

$sql = "DELETE FROM insentif_detail WHERE insentif_id = ?";
$delete_stmt = $conn->prepare($sql);
$delete_stmt->bind_param("i", $insentif_id);
$delete_stmt->execute();
$delete_stmt->close();

$periode = '';

$sql = "INSERT INTO insentif_detail (guru_id, insentif_id, jumlah, periode) VALUES (?, ?, ?, ?)";
$detail_stmt = $conn->prepare($sql);
foreach ($guru_ids as $guru_id) {
    $guru_id = intval($guru_id);
    if ($guru_id > 0) {
        $detail_stmt->bind_param("iids", $guru_id, $insentif_id, $total_ke_guru, $periode);
        $detail_stmt->execute();
    }
}
$detail_stmt->close();

gajiLogActivity($conn, "{$action} insentif: {$nama_insentif}", 'success');
echo json_encode([
    'success' => true,
    'message' => 'Data insentif berhasil ' . ($id ? 'diubah' : 'ditambahkan')
]);
$stmt->close();
exit();
?>

