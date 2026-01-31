<?php
require_once 'config/database.php';
require_once 'config/functions.php';

// Get current school profile
$school_profile = getSchoolProfile($pdo);
$ta = $school_profile['tahun_ajaran'];
$sem = $school_profile['semester'];

echo "Current Profile: $ta - $sem\n";

try {
    // Check if columns exist first to avoid error
    $stmt = $pdo->query("SHOW COLUMNS FROM tb_nilai_harian_header LIKE 'tahun_ajaran'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE tb_nilai_harian_header ADD COLUMN tahun_ajaran VARCHAR(20) AFTER materi");
        $pdo->exec("UPDATE tb_nilai_harian_header SET tahun_ajaran = '$ta'"); // Backfill
        echo "Added tahun_ajaran\n";
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM tb_nilai_harian_header LIKE 'semester'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE tb_nilai_harian_header ADD COLUMN semester VARCHAR(20) AFTER tahun_ajaran");
        $pdo->exec("UPDATE tb_nilai_harian_header SET semester = '$sem'"); // Backfill
        echo "Added semester\n";
    }
    
    echo "Schema update complete.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>