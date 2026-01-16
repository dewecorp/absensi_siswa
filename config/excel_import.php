<?php
// Excel import utility functions

require_once 'database.php';
require_once 'functions.php';

// Check if user is authorized
if (!isAuthorized(['admin'])) {
    die('Unauthorized access');
}

// Try to load PhpSpreadsheet, fallback to CSV if not available
$hasSpreadsheet = false;
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $hasSpreadsheet = class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet');
}

/**
 * Import teachers from Excel file
 */
function importTeachersFromExcel($filePath) {
    global $pdo, $hasSpreadsheet;
    
    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $fileExtension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    
    if ($hasSpreadsheet && in_array($fileExtension, ['xlsx', 'xls'])) {
        // Use PhpSpreadsheet for Excel files
        return importTeachersFromExcelFile($filePath);
    } else {
        return [
            'success' => false,
            'message' => 'File format not supported. Please use XLS or XLSX Excel format.'
        ];
    }
}

/**
 * Import students from Excel file
 */
function importStudentsFromExcel($filePath) {
    global $pdo, $hasSpreadsheet;
    
    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $fileExtension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    
    if ($hasSpreadsheet && in_array($fileExtension, ['xlsx', 'xls'])) {
        // Use PhpSpreadsheet for Excel files
        return importStudentsFromExcelFile($filePath);
    } else {
        return [
            'success' => false,
            'message' => 'File format not supported. Please use XLS or XLSX Excel format.'
        ];
    }
}

/**
 * Import teachers from Excel using PhpSpreadsheet
 */
function importTeachersFromExcelFile($filePath) {
    global $pdo;
    
    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();
        
        // Skip header row
        array_shift($rows);
        
        $rowCount = 0;
        $duplicateCount = 0;
        $errors = [];
        
        foreach ($rows as $index => $row) {
            if (count($row) >= 6) { // Assuming 6 columns: nama_guru, nuptk, tempat_lahir, tanggal_lahir, jenis_kelamin, password
                $nama_guru = trim($row[0]);
                $nuptk = trim($row[1]);
                $tempat_lahir = trim($row[2]);
                $tanggal_lahir = trim($row[3]);
                $jenis_kelamin = trim($row[4]);
                $password = trim($row[5]);
                // wali_kelas is now managed in data kelas, so set to NULL
                $wali_kelas = NULL;
                
                // Validate required fields
                if (empty($nama_guru) || empty($nuptk)) {
                    $errors[] = "Row " . ($index + 2) . ": Missing required fields (Nama Guru or NUPTK)";
                    continue;
                }
                
                // Handle empty birth date - if tanggal_lahir is empty, set to NULL
                $tanggal_lahir_val = !empty($tanggal_lahir) ? $tanggal_lahir : NULL;
                $tempat_lahir_val = !empty($tempat_lahir) ? $tempat_lahir : NULL;
                
                // Hash password if provided
                $default_password = '123456';
                $password_to_use = !empty($password) ? $password : $default_password;
                $hashed_password = hashPassword($password_to_use);
                $password_plain = $password_to_use; // Store plain text password
                
                // Check if teacher with same NUPTK already exists
                $checkStmt = $pdo->prepare("SELECT id_guru FROM tb_guru WHERE nuptk = ?");
                $checkStmt->execute([$nuptk]);
                $existingTeacher = $checkStmt->fetch();
                
                if ($existingTeacher) {
                    // Update existing teacher (overwrite duplicate)
                    try {
                        $updateStmt = $pdo->prepare("UPDATE tb_guru SET nama_guru=?, tempat_lahir=?, tanggal_lahir=?, jenis_kelamin=?, wali_kelas=?, password=?, password_plain=? WHERE nuptk=?");
                        $updateStmt->execute([$nama_guru, $tempat_lahir_val, $tanggal_lahir_val, $jenis_kelamin, $wali_kelas, $hashed_password, $password_plain, $nuptk]);
                        $rowCount++; // Count as updated/saved
                        $duplicateCount++; // Track duplicate records that were overwritten
                    } catch (PDOException $e) {
                        $errors[] = "Row " . ($index + 2) . ": " . $e->getMessage();
                    }
                } else {
                    // Insert new teacher
                    try {
                        $insertStmt = $pdo->prepare("INSERT INTO tb_guru (nama_guru, nuptk, tempat_lahir, tanggal_lahir, jenis_kelamin, wali_kelas, password, password_plain) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $insertStmt->execute([$nama_guru, $nuptk, $tempat_lahir_val, $tanggal_lahir_val, $jenis_kelamin, $wali_kelas, $hashed_password, $password_plain]);
                        $rowCount++;
                    } catch (PDOException $e) {
                        $errors[] = "Row " . ($index + 2) . ": " . $e->getMessage();
                    }
                }
            }
        }
        
        // Count failed rows from errors array
        $failedCount = count($errors);
        
        return [
            'success' => true,
            'imported_rows' => $rowCount,
            'duplicate_rows' => $duplicateCount,
            'failed_rows' => $failedCount,
            'errors' => $errors
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error reading Excel file: ' . $e->getMessage()
        ];
    }
}

/**
 * Import students from Excel using PhpSpreadsheet
 */
function importStudentsFromExcelFile($filePath) {
    global $pdo;
    
    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();
        
        // Skip header row
        array_shift($rows);
        
        $rowCount = 0;
        $errors = [];
        
        foreach ($rows as $index => $row) {
            if (count($row) >= 4) { // Assuming 4 columns: nama_siswa, nisn, jenis_kelamin, id_kelas
                $nama_siswa = trim($row[0]);
                $nisn = trim($row[1]);
                $jenis_kelamin = trim($row[2]);
                $id_kelas = trim($row[3]);
                
                // Validate required fields
                if (empty($nama_siswa) || empty($nisn) || empty($jenis_kelamin) || empty($id_kelas)) {
                    $errors[] = "Row " . ($index + 2) . ": Missing required fields (Nama Siswa, NISN, Jenis Kelamin, or ID Kelas)";
                    continue;
                }
                
                // Validate jenis_kelamin value
                if ($jenis_kelamin !== 'L' && $jenis_kelamin !== 'P') {
                    $errors[] = "Row " . ($index + 2) . ": Jenis Kelamin must be 'L' or 'P'";
                    continue;
                }
                
                // Insert into database
                try {
                    $stmt = $pdo->prepare("INSERT INTO tb_siswa (nama_siswa, nisn, jenis_kelamin, id_kelas) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$nama_siswa, $nisn, $jenis_kelamin, $id_kelas]);
                    $rowCount++;
                } catch (PDOException $e) {
                    $errors[] = "Row " . ($index + 2) . ": " . $e->getMessage();
                }
            } elseif (count($row) >= 3) { // Support for backward compatibility
                $nama_siswa = trim($row[0]);
                $nisn = trim($row[1]);
                $id_kelas = trim($row[2]);
                
                // Validate required fields
                if (empty($nama_siswa) || empty($nisn)) {
                    $errors[] = "Row " . ($index + 2) . ": Missing required fields (Nama Siswa or NISN)";
                    continue;
                }
                
                // Insert into database without jenis_kelamin
                try {
                    $stmt = $pdo->prepare("INSERT INTO tb_siswa (nama_siswa, nisn, id_kelas) VALUES (?, ?, ?)");
                    $stmt->execute([$nama_siswa, $nisn, $id_kelas]);
                    $rowCount++;
                } catch (PDOException $e) {
                    $errors[] = "Row " . ($index + 2) . ": " . $e->getMessage();
                }
            }
        }
        
        return [
            'success' => true,
            'imported_rows' => $rowCount,
            'errors' => $errors
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error reading Excel file: ' . $e->getMessage()
        ];
    }
}

/**
 * Import teachers from CSV
 */
function importTeachersFromCSV($filePath) {
    global $pdo;
    
    $handle = fopen($filePath, "r");
    if (!$handle) {
        return [
            'success' => false,
            'message' => 'Could not open CSV file.'
        ];
    }
    
    $rowCount = 0;
    $duplicateCount = 0;
    $errors = [];
    
    // Skip header row
    fgetcsv($handle);
    
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if (count($data) >= 6) { // Assuming 6 columns: nama_guru, nuptk, tempat_lahir, tanggal_lahir, jenis_kelamin, password
            $nama_guru = trim($data[0]);
            $nuptk = trim($data[1]);
            $tempat_lahir = trim($data[2]);
            $tanggal_lahir = trim($data[3]);
            $jenis_kelamin = trim($data[4]);
            $password = trim($data[5]);
            // wali_kelas is now managed in data kelas, so set to NULL
            $wali_kelas = NULL;
            
            // Validate required fields
            if (empty($nama_guru) || empty($nuptk)) {
                $errors[] = "Row " . ($rowCount + 2) . ": Missing required fields (Nama Guru or NUPTK)";
                continue;
            }
            
            // Handle empty birth date - if tanggal_lahir is empty, set to NULL
            $tanggal_lahir_val = !empty($tanggal_lahir) ? $tanggal_lahir : NULL;
            $tempat_lahir_val = !empty($tempat_lahir) ? $tempat_lahir : NULL;
            
            // Hash password if provided
            $default_password = '123456';
            $password_to_use = !empty($password) ? $password : $default_password;
            $hashed_password = hashPassword($password_to_use);
            $password_plain = $password_to_use; // Store plain text password
            
            // Check if teacher with same NUPTK already exists
            $checkStmt = $pdo->prepare("SELECT id_guru FROM tb_guru WHERE nuptk = ?");
            $checkStmt->execute([$nuptk]);
            $existingTeacher = $checkStmt->fetch();
            
            if ($existingTeacher) {
                // Update existing teacher (overwrite duplicate)
                try {
                    $updateStmt = $pdo->prepare("UPDATE tb_guru SET nama_guru=?, tempat_lahir=?, tanggal_lahir=?, jenis_kelamin=?, wali_kelas=?, password=?, password_plain=? WHERE nuptk=?");
                    $updateStmt->execute([$nama_guru, $tempat_lahir_val, $tanggal_lahir_val, $jenis_kelamin, $wali_kelas, $hashed_password, $password_plain, $nuptk]);
                    $rowCount++; // Count as updated/saved
                    $duplicateCount++; // Track duplicate records that were overwritten
                } catch (PDOException $e) {
                    $errors[] = "Row " . ($rowCount + 2) . ": " . $e->getMessage();
                }
            } else {
                // Insert new teacher
                try {
                    $insertStmt = $pdo->prepare("INSERT INTO tb_guru (nama_guru, nuptk, tempat_lahir, tanggal_lahir, jenis_kelamin, wali_kelas, password, password_plain) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $insertStmt->execute([$nama_guru, $nuptk, $tempat_lahir_val, $tanggal_lahir_val, $jenis_kelamin, $wali_kelas, $hashed_password, $password_plain]);
                    $rowCount++;
                } catch (PDOException $e) {
                    $errors[] = "Row " . ($rowCount + 2) . ": " . $e->getMessage();
                }
            }
        }
    }
    fclose($handle);
    
    return [
        'success' => true,
        'imported_rows' => $rowCount,
        'duplicate_rows' => $duplicateCount,
        'failed_rows' => count($errors),
        'errors' => $errors
    ];
}

/**
 * Import students from CSV
 */
function importStudentsFromCSV($filePath) {
    global $pdo;
    
    $handle = fopen($filePath, "r");
    if (!$handle) {
        return [
            'success' => false,
            'message' => 'Could not open CSV file.'
        ];
    }
    
    $rowCount = 0;
    $errors = [];
    
    // Skip header row
    fgetcsv($handle);
    
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if (count($data) >= 4) { // Assuming 4 columns: nama_siswa, nisn, jenis_kelamin, id_kelas
            $nama_siswa = trim($data[0]);
            $nisn = trim($data[1]);
            $jenis_kelamin = trim($data[2]);
            $id_kelas = trim($data[3]);
            
            // Validate required fields
            if (empty($nama_siswa) || empty($nisn) || empty($jenis_kelamin) || empty($id_kelas)) {
                $errors[] = "Row " . ($rowCount + 2) . ": Missing required fields (Nama Siswa, NISN, Jenis Kelamin, or ID Kelas)";
                continue;
            }
            
            // Validate jenis_kelamin value
            if ($jenis_kelamin !== 'L' && $jenis_kelamin !== 'P') {
                $errors[] = "Row " . ($rowCount + 2) . ": Jenis Kelamin must be 'L' or 'P'";
                continue;
            }
            
            // Insert into database
            try {
                $stmt = $pdo->prepare("INSERT INTO tb_siswa (nama_siswa, nisn, jenis_kelamin, id_kelas) VALUES (?, ?, ?, ?)");
                $stmt->execute([$nama_siswa, $nisn, $jenis_kelamin, $id_kelas]);
                $rowCount++;
            } catch (PDOException $e) {
                $errors[] = "Row " . ($rowCount + 2) . ": " . $e->getMessage();
            }
        } elseif (count($data) >= 3) { // Support for backward compatibility
            $nama_siswa = trim($data[0]);
            $nisn = trim($data[1]);
            $id_kelas = trim($data[2]);
            
            // Validate required fields
            if (empty($nama_siswa) || empty($nisn)) {
                $errors[] = "Row " . ($rowCount + 2) . ": Missing required fields (Nama Siswa or NISN)";
                continue;
            }
            
            // Insert into database without jenis_kelamin
            try {
                $stmt = $pdo->prepare("INSERT INTO tb_siswa (nama_siswa, nisn, id_kelas) VALUES (?, ?, ?)");
                $stmt->execute([$nama_siswa, $nisn, $id_kelas]);
                $rowCount++;
            } catch (PDOException $e) {
                $errors[] = "Row " . ($rowCount + 2) . ": " . $e->getMessage();
            }
        }
    }
    fclose($handle);
    
    return [
        'success' => true,
        'imported_rows' => $rowCount,
        'errors' => $errors
    ];
}

/**
 * Handle file upload
 */
function handleFileUpload($fileKey) {
    $targetDir = __DIR__ . '/../uploads/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }
    
    $targetFile = $targetDir . basename($_FILES[$fileKey]['name']);
    $uploadOk = 1;
    $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
    
    // Check if file is a actual CSV/Excel file
    $check = $_FILES[$fileKey];
    if ($check['size'] > 5000000) { // 5MB max file size
        return ['success' => false, 'message' => 'Sorry, your file is too large.'];
    }
    
    // Allow certain file formats
    if ($fileType != "xlsx" && $fileType != "xls") {
        return ['success' => false, 'message' => 'Sorry, only XLS and XLSX files are allowed.'];
    }
    
    // Check if $uploadOk is set to 0 by an error
    if ($uploadOk == 0) {
        return ['success' => false, 'message' => 'Sorry, your file was not uploaded.'];
    } else {
        if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $targetFile)) {
            return ['success' => true, 'file_path' => $targetFile];
        } else {
            return ['success' => false, 'message' => 'Sorry, there was an error uploading your file.'];
        }
    }
}
?>