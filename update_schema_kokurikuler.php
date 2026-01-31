<?php
require_once 'config/database.php';
require_once 'config/functions.php';

try {
    // Get active semester info
    $school_profile = getSchoolProfile($pdo);
    $ta = $school_profile['tahun_ajaran'];
    $sem = $school_profile['semester'];

    echo "Checking schema for tb_nilai_kokurikuler_header...<br>";
    
    // Check columns
    $stmt = $pdo->query("DESCRIBE tb_nilai_kokurikuler_header");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('tahun_ajaran', $columns)) {
        echo "Adding tahun_ajaran column...<br>";
        $pdo->exec("ALTER TABLE tb_nilai_kokurikuler_header ADD COLUMN tahun_ajaran VARCHAR(20) AFTER tgl_kegiatan");
        echo "Backfilling tahun_ajaran with '$ta'...<br>";
        $pdo->exec("UPDATE tb_nilai_kokurikuler_header SET tahun_ajaran = '$ta'");
    }
    
    if (!in_array('semester', $columns)) {
        echo "Adding semester column...<br>";
        $pdo->exec("ALTER TABLE tb_nilai_kokurikuler_header ADD COLUMN semester VARCHAR(20) AFTER tahun_ajaran");
        echo "Backfilling semester with '$sem'...<br>";
        $pdo->exec("UPDATE tb_nilai_kokurikuler_header SET semester = '$sem'");
    }

    echo "Schema update completed successfully.";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>