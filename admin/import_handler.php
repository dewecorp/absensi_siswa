<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/excel_import.php';

// Check if user is authorized
if (!isAuthorized(['admin'])) {
    $response = ['success' => false, 'message' => 'Akses tidak sah'];
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['import_data'])) {
    $import_type = $_POST['import_type'] ?? '';
    
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] == 0) {
        $upload_result = handleFileUpload('excel_file');
        
        if ($upload_result['success']) {
            if ($import_type == 'guru') {
                $result = importTeachersFromExcel($upload_result['file_path']);
            } else if ($import_type == 'siswa') {
                $result = importStudentsFromExcel($upload_result['file_path']);
            } else {
                $result = ['success' => false, 'message' => 'Tipe impor tidak valid'];
            }
            
            // Clean up uploaded file
            if (file_exists($upload_result['file_path'])) {
                unlink($upload_result['file_path']);
            }
            
            // Return JSON response
            header('Content-Type: application/json');
            echo json_encode($result);
            exit;
        } else {
            $response = ['success' => false, 'message' => $upload_result['message']];
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }
    } else {
        $response = ['success' => false, 'message' => 'Silakan pilih file untuk diimpor.'];
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}

// If not POST or import_data not set, return error
$response = ['success' => false, 'message' => 'Permintaan tidak valid'];
header('Content-Type: application/json');
echo json_encode($response);
exit;
?>