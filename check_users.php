<?php
require_once 'config/database.php';

$tables = ['tb_users', 'tb_siswa'];

foreach ($tables as $table) {
    echo "Table: $table\n";
    try {
        $stmt = $pdo->query("DESCRIBE $table");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $col) {
            echo "  " . $col['Field'] . " - " . $col['Type'] . "\n";
        }
    } catch (PDOException $e) {
        echo "  Error: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

// Check sample data from tb_users
echo "Sample users:\n";
$stmt = $pdo->query("SELECT * FROM tb_users LIMIT 5");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($users as $user) {
    echo "  ID: " . $user['id_user'] . ", User: " . $user['username'] . ", Level: " . $user['level'] . "\n";
}
?>