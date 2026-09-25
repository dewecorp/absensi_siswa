<?php
require_once __DIR__ . '/../config/database.php';

$legacy_nama = isset($legacy_nama) ? trim((string)$legacy_nama) : '';
$target = 'data_anggota_ekskul.php';
try {
    if ($legacy_nama !== '') {
        $st = $pdo->prepare("SELECT id_ekstrakurikuler FROM tb_ekstrakurikuler WHERE LOWER(nama_ekstrakurikuler) = LOWER(?) LIMIT 1");
        $st->execute([$legacy_nama]);
        $found = (int)$st->fetchColumn();
        if ($found > 0) {
            $target .= '?ekskul=' . $found;
        }
    }
} catch (Exception $e) { /* fallback tanpa parameter */ }
if (!empty($_GET['session_type'])) {
    $target .= (strpos($target, '?') === false ? '?' : '&') . 'session_type=' . urlencode((string)$_GET['session_type']);
}
header('Location: ' . $target);
exit;
