<?php
require_once 'config/database.php';
$stmt = $pdo->query("DESCRIBE tb_jurnal");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
