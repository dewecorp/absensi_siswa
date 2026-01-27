<?php
require_once 'config/database.php';
$stmt = $pdo->query("SELECT * FROM tb_jam_mengajar");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
