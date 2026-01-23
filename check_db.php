<?php
require 'config/database.php';

echo "--- Table: tb_pengguna ---\n";
$stmt = $pdo->query("DESCRIBE tb_pengguna");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo $col['Field'] . " | " . $col['Type'] . "\n";
}

echo "\n--- Table: tb_profil_madrasah ---\n";
$stmt = $pdo->query("SELECT * FROM tb_profil_madrasah LIMIT 1");
$profile = $stmt->fetch(PDO::FETCH_ASSOC);
print_r($profile);
?>