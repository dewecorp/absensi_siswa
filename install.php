<?php
// Installation script to create database and tables

// Database configuration
$host = 'localhost';
$username = 'root';
$password = '';
$db_name = 'db_absensi';

try {
    // Create connection
    $pdo = new PDO("mysql:host=$host", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create database if not exists
    $sql = "CREATE DATABASE IF NOT EXISTS `$db_name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    $pdo->exec($sql);
    
    // Select the database
    $pdo->exec("USE `$db_name`");
    
    // SQL to create tables
    $sql = "
    CREATE TABLE IF NOT EXISTS `tb_guru` (
      `id_guru` int(11) NOT NULL AUTO_INCREMENT,
      `nama_guru` varchar(100) NOT NULL,
      `nuptk` varchar(50) NOT NULL,
      `tempat_lahir` varchar(50) NOT NULL,
      `tanggal_lahir` date NOT NULL,
      `jenis_kelamin` enum('Laki-laki','Perempuan') NOT NULL,
      `wali_kelas` varchar(50) DEFAULT NULL,
      `password` varchar(255) NOT NULL,
      PRIMARY KEY (`id_guru`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `tb_kelas` (
      `id_kelas` int(11) NOT NULL AUTO_INCREMENT,
      `nama_kelas` varchar(50) NOT NULL,
      `wali_kelas` varchar(100) DEFAULT NULL,
      PRIMARY KEY (`id_kelas`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `tb_siswa` (
      `id_siswa` int(11) NOT NULL AUTO_INCREMENT,
      `nama_siswa` varchar(100) NOT NULL,
      `nisn` varchar(20) NOT NULL,
      `id_kelas` int(11) DEFAULT NULL,
      PRIMARY KEY (`id_siswa`),
      KEY `id_kelas` (`id_kelas`),
      CONSTRAINT `tb_siswa_ibfk_1` FOREIGN KEY (`id_kelas`) REFERENCES `tb_kelas` (`id_kelas`) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `tb_absensi` (
      `id_absensi` int(11) NOT NULL AUTO_INCREMENT,
      `id_siswa` int(11) NOT NULL,
      `tanggal` date NOT NULL,
      `jam_masuk` time DEFAULT NULL,
      `jam_keluar` time DEFAULT NULL,
      `keterangan` enum('Hadir','Sakit','Izin','Alpa') NOT NULL,
      `id_guru` int(11) DEFAULT NULL,
      PRIMARY KEY (`id_absensi`),
      KEY `id_siswa` (`id_siswa`),
      KEY `id_guru` (`id_guru`),
      CONSTRAINT `tb_absensi_ibfk_1` FOREIGN KEY (`id_siswa`) REFERENCES `tb_siswa` (`id_siswa`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `tb_absensi_ibfk_2` FOREIGN KEY (`id_guru`) REFERENCES `tb_guru` (`id_guru`) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `tb_pengguna` (
      `id_pengguna` int(11) NOT NULL AUTO_INCREMENT,
      `username` varchar(50) NOT NULL,
      `password` varchar(255) NOT NULL,
      `level` enum('admin','guru','wali') NOT NULL,
      `id_guru` int(11) DEFAULT NULL,
      PRIMARY KEY (`id_pengguna`),
      KEY `id_guru` (`id_guru`),
      CONSTRAINT `tb_pengguna_ibfk_1` FOREIGN KEY (`id_guru`) REFERENCES `tb_guru` (`id_guru`) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `tb_profil_madrasah` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `nama_madrasah` varchar(200) NOT NULL,
      `logo` varchar(100) DEFAULT NULL,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `tb_backup_restore` (
      `id_backup` int(11) NOT NULL AUTO_INCREMENT,
      `nama_file` varchar(200) NOT NULL,
      `tanggal_backup` datetime NOT NULL,
      `ukuran_file` varchar(20) NOT NULL,
      `keterangan` text DEFAULT NULL,
      PRIMARY KEY (`id_backup`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- Insert default school profile
    INSERT IGNORE INTO `tb_profil_madrasah` (`id`, `nama_madrasah`, `logo`) VALUES
    (1, 'MI Sultan Fattah Sukosono', 'logo.png');

    -- Insert default admin user (password: admin)
    INSERT IGNORE INTO `tb_pengguna` (`id_pengguna`, `username`, `password`, `level`, `id_guru`) VALUES
    (1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', NULL);

    -- Auto increment for tables
    ALTER TABLE `tb_guru` MODIFY `id_guru` int(11) NOT NULL AUTO_INCREMENT;
    ALTER TABLE `tb_kelas` MODIFY `id_kelas` int(11) NOT NULL AUTO_INCREMENT;
    ALTER TABLE `tb_siswa` MODIFY `id_siswa` int(11) NOT NULL AUTO_INCREMENT;
    ALTER TABLE `tb_absensi` MODIFY `id_absensi` int(11) NOT NULL AUTO_INCREMENT;
    ALTER TABLE `tb_pengguna` MODIFY `id_pengguna` int(11) NOT NULL AUTO_INCREMENT;
    ALTER TABLE `tb_backup_restore` MODIFY `id_backup` int(11) NOT NULL AUTO_INCREMENT;
    ";
    
    // Execute the SQL
    $pdo->exec($sql);
    
    echo "<h2>Installation Successful!</h2>";
    echo "<p>Database and tables have been created successfully.</p>";
    echo "<p><a href='login.php'>Go to Login Page</a></p>";
    
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>