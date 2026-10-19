<?php
require_once 'config/database.php';
header('Content-Type: text/plain; charset=utf-8');
$stmt = $pdo->query("SELECT username, level, nama_lengkap FROM tb_pengguna LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
@unlink(__FILE__);
