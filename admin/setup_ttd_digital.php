<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    die("Akses ditolak. Silakan login sebagai admin.");
}

echo "<h2>Setup Tanda Tangan Digital</h2>";
echo "<pre>";

try {
    // 1. Tambah kolom ttd_kepala di tb_profil_madrasah
    $check_profil = $pdo->query("SHOW COLUMNS FROM tb_profil_madrasah LIKE 'ttd_kepala'");
    if ($check_profil->rowCount() == 0) {
        $pdo->exec("ALTER TABLE tb_profil_madrasah ADD COLUMN ttd_kepala VARCHAR(255) DEFAULT NULL AFTER dashboard_hero_image");
        echo "Kolom 'ttd_kepala' berhasil ditambahkan ke tabel 'tb_profil_madrasah'.\n";
    } else {
        echo "Kolom 'ttd_kepala' sudah ada di tabel 'tb_profil_madrasah'.\n";
    }

    // 2. Tambah kolom ttd di tb_guru
    $check_guru = $pdo->query("SHOW COLUMNS FROM tb_guru LIKE 'ttd'");
    if ($check_guru->rowCount() == 0) {
        $pdo->exec("ALTER TABLE tb_guru ADD COLUMN ttd VARCHAR(255) DEFAULT NULL AFTER foto");
        echo "Kolom 'ttd' berhasil ditambahkan ke tabel 'tb_guru'.\n";
    } else {
        echo "Kolom 'ttd' sudah ada di tabel 'tb_guru'.\n";
    }

    // 3. Buat tabel tb_ttd_digital (Sesuai permintaan user untuk "tambah tabel")
    $sql_table = "CREATE TABLE IF NOT EXISTS `tb_ttd_digital` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `id_user` INT NOT NULL,
        `level` ENUM('admin', 'guru', 'kepala') NOT NULL,
        `file_ttd` VARCHAR(255) NOT NULL,
        `status` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    $pdo->exec($sql_table);
    echo "Tabel 'tb_ttd_digital' berhasil disiapkan.\n";

    echo "\nSemua proses selesai dengan sukses.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}

echo "</pre>";
echo "<br><a href='dashboard.php'>Kembali ke Dashboard</a>";
?>
