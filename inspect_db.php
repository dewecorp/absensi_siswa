<?php
require_once 'config/functions.php';

function inspectDB() {
    $db_host = "127.0.0.1"; $db_user = "root"; $db_pass = ""; $db_name = "spp";
    try {
        $dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";
        $pdo = new PDO($dsn, $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

        $tables = ['jenis_bayar', 'pembayaran', 'siswa', 'tahun_ajaran', 'pos_bayar'];
        foreach ($tables as $t) {
            echo "\n--- TABLE: $t ---\n";
            try {
                $stmt = $pdo->query("DESCRIBE $t");
                while ($row = $stmt->fetch()) {
                    echo "{$row['Field']} ({$row['Type']}) - {$row['Key']}\n";
                }
                
                echo "\nSample Data (1 row):\n";
                $sample = $pdo->query("SELECT * FROM $t LIMIT 1")->fetch();
                print_r($sample);
            } catch (Exception $e) {
                echo "Error or table not found: " . $e->getMessage() . "\n";
            }
        }

        // Check Adiba's data
        $nisn = '3146588936';
        echo "\n--- ADIBA DATA (NISN: $nisn) ---\n";
        $adiba = $pdo->query("SELECT * FROM siswa WHERE nisn = '$nisn'")->fetch();
        print_r($adiba);

        echo "\n--- ADIBA PAYMENTS ---\n";
        $stmt = $pdo->prepare("SELECT p.*, jb.nama_pembayaran FROM pembayaran p JOIN jenis_bayar jb ON p.id_jenis_bayar = jb.id_jenis_bayar WHERE p.nisn = ?");
        $stmt->execute([$nisn]);
        $payments = $stmt->fetchAll();
        print_r($payments);

    } catch (PDOException $e) {
        echo "Connection failed: " . $e->getMessage();
    }
}

inspectDB();
