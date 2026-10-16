<?php
require 'config/database.php';
$st = $pdo->query("SELECT id_pengguna, username, password FROM tb_pengguna LIMIT 1");
print_r($st->fetch(PDO::FETCH_ASSOC));
