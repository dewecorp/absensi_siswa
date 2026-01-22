<?php
require_once 'config/database.php';
try {
    $stmt = $pdo->query('DESCRIBE tb_absensi_guru');
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo 'Table not found: ' . $e->getMessage();
}
?>