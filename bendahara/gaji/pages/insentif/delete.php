<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

if (!verifyCsrfToken($_REQUEST['csrf_token'] ?? '')) { $_SESSION['error'] = 'Sesi berakhir. Silakan muat ulang halaman dan coba lagi.'; header('Location: ' . BASE_URL . 'pages/insentif'); exit(); }

$id = $_REQUEST['id'] ?? 0;

$conn->query("CREATE TABLE IF NOT EXISTS insentif (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_insentif VARCHAR(100) NOT NULL,
    jumlah_insentif DECIMAL(15,2) NOT NULL DEFAULT 0,
    aktif TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$sql = "SELECT nama_insentif FROM insentif WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$insentif = $result->fetch_assoc();
$stmt->close();

if ($insentif) {
    $sql = "DELETE FROM insentif WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        gajiLogActivity($conn, "Menghapus insentif: {$insentif['nama_insentif']}", 'danger');
        $_SESSION['success'] = "Data insentif berhasil dihapus";
    } else {
        $_SESSION['error'] = "Gagal menghapus data insentif";
    }
    $stmt->close();
} else {
    $_SESSION['error'] = "Data tidak ditemukan";
}

header('Location: ' . BASE_URL . 'pages/insentif');
exit();
?>

