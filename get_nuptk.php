<?php
require 'config/database.php';
$st = $pdo->query("SELECT id_guru, nuptk, nama_guru, password_plain FROM tb_guru LIMIT 3");
print_r($st->fetchAll(PDO::FETCH_ASSOC));
