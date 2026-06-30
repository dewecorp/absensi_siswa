<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/functions.php';

echo "tb_profil_madrasah\n";
foreach ($pdo->query('SELECT id,tahun_ajaran,semester FROM tb_profil_madrasah ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    print_r($row);
}

echo "getSchoolProfile\n";
$profile = getSchoolProfile($pdo);
print_r([
    'tahun_ajaran' => $profile['tahun_ajaran'] ?? null,
    'semester' => $profile['semester'] ?? null,
]);

echo "tb_siswa_baru\n";
foreach ($pdo->query('SELECT * FROM tb_siswa_baru ORDER BY tahun_ajaran ASC')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    print_r($row);
}
