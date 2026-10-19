<?php
require_once 'config/database.php';
require_once 'config/functions.php';
header('Content-Type: text/plain; charset=utf-8');
$stmt = $pdo->query("SELECT username, level, nama_lengkap FROM tb_users WHERE level IN ('admin', 'tata_usaha') LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
@unlink(__FILE__);
