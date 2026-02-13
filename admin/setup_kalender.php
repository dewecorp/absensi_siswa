<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

// Hanya izinkan admin yang menjalankan script ini
if (!isAuthorized(['admin'])) {
    die("Akses ditolak. Anda harus login sebagai Admin untuk menjalankan script ini.");
}

try {
    $sql = "CREATE TABLE IF NOT EXISTS tb_kalender_pendidikan (
        id_kalender INT AUTO_INCREMENT PRIMARY KEY,
        nama_kegiatan VARCHAR(255) NOT NULL,
        tgl_mulai DATE NOT NULL,
        tgl_selesai DATE NOT NULL,
        tahun_ajaran VARCHAR(20) NOT NULL,
        warna VARCHAR(20) DEFAULT 'info',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $pdo->exec($sql);
    echo "<div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #ccc; border-radius: 5px; background-color: #f9f9f9;'>";
    echo "<h2 style='color: #28a745;'>Berhasil!</h2>";
    echo "<p>Tabel <b>tb_kalender_pendidikan</b> telah berhasil dibuat atau sudah ada.</p>";
    echo "<a href='kalender_pendidikan.php' style='display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 3px;'>Kembali ke Kalender</a>";
    echo "</div>";

} catch (PDOException $e) {
    echo "<div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #dc3545; border-radius: 5px; background-color: #fff5f5;'>";
    echo "<h2 style='color: #dc3545;'>Gagal!</h2>";
    echo "<p>Terjadi kesalahan saat membuat tabel: " . $e->getMessage() . "</p>";
    echo "</div>";
}
?>
