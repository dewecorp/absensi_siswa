<?php
require_once 'config/database.php';
$stmt = $pdo->query("SELECT * FROM tb_kelas");
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($classes, JSON_PRETTY_PRINT);
unlink(__FILE__);
?>