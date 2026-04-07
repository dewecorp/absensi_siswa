<!DOCTYPE html>
<html>
<head>
    <title>Add Tanggal Column</title>
</head>
<body>
<h2>Adding 'tanggal' Column to Program Tables</h2>

<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'db_absensi');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h3>1. Adding column to tb_program_remidial...</h3>";
    try {
        $pdo->exec("ALTER TABLE tb_program_remidial ADD COLUMN tanggal DATE NOT NULL DEFAULT (CURRENT_DATE) AFTER keterangan");
        echo "<div style='background: #d4edda; padding: 10px; border: 1px solid #c3e6cb; border-radius: 5px;'>✅ Column 'tanggal' added to tb_program_remidial</div>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "<div style='background: #fff3cd; padding: 10px; border: 1px solid #ffeaa7; border-radius: 5px;'>⚠️ Column 'tanggal' already exists in tb_program_remidial</div>";
        } else {
            throw $e;
        }
    }
    
    echo "<h3>2. Adding column to tb_program_pengayaan...</h3>";
    try {
        $pdo->exec("ALTER TABLE tb_program_pengayaan ADD COLUMN tanggal DATE NOT NULL DEFAULT (CURRENT_DATE) AFTER bentuk_pengayaan");
        echo "<div style='background: #d4edda; padding: 10px; border: 1px solid #c3e6cb; border-radius: 5px;'>✅ Column 'tanggal' added to tb_program_pengayaan</div>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "<div style='background: #fff3cd; padding: 10px; border: 1px solid #ffeaa7; border-radius: 5px;'>⚠️ Column 'tanggal' already exists in tb_program_pengayaan</div>";
        } else {
            throw $e;
        }
    }
    
    echo "<h3>3. Creating indexes...</h3>";
    try {
        $pdo->exec("ALTER TABLE tb_program_remidial ADD INDEX idx_tanggal (tanggal)");
        echo "<div style='background: #d4edda; padding: 10px; border: 1px solid #c3e6cb; border-radius: 5px;'>✅ Index created for tb_program_remidial.tanggal</div>";
    } catch (PDOException $e) {
        echo "<div style='background: #fff3cd; padding: 10px; border: 1px solid #ffeaa7; border-radius: 5px;'>⚠️ Index might already exist</div>";
    }
    
    try {
        $pdo->exec("ALTER TABLE tb_program_pengayaan ADD INDEX idx_tanggal (tanggal)");
        echo "<div style='background: #d4edda; padding: 10px; border: 1px solid #c3e6cb; border-radius: 5px;'>✅ Index created for tb_program_pengayaan.tanggal</div>";
    } catch (PDOException $e) {
        echo "<div style='background: #fff3cd; padding: 10px; border: 1px solid #ffeaa7; border-radius: 5px;'>⚠️ Index might already exist</div>";
    }
    
    echo "<hr>";
    echo "<div style='background: #d1ecf1; padding: 15px; border: 1px solid #bee5eb; border-radius: 5px;'>";
    echo "<strong>✅ All done!</strong><br>";
    echo "<a href='guru/program_remidi.php'>Go to Program Remidi</a> | ";
    echo "<a href='guru/program_pengayaan.php'>Go to Program Pengayaan</a>";
    echo "</div>";
    
} catch (PDOException $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border: 1px solid #f5c6cb; border-radius: 5px;'>";
    echo "❌ <strong>Error:</strong> " . $e->getMessage();
    echo "</div>";
}
?>

</body>
</html>
