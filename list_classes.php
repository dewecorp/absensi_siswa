<?php
require_once 'config/database.php';
$stmt = $pdo->query("SELECT * FROM tb_kelas");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['id_kelas'] . ": " . $row['nama_kelas'] . "\n";
}
unlink(__FILE__);
?>