<?php
require_once 'config/database.php';
header('Content-Type: text/plain; charset=utf-8');
$stmt = $pdo->query("
    SELECT k.*, g.foto AS foto_wali, g.nip,
           (SELECT COUNT(*) FROM tb_siswa s WHERE s.id_kelas = k.id_kelas) AS total_siswa
    FROM tb_kelas k
    LEFT JOIN tb_guru g ON LOWER(TRIM(g.nama_guru)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(k.wali_kelas)) COLLATE utf8mb4_unicode_ci
    ORDER BY k.nama_kelas ASC
");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
@unlink(__FILE__);
