<?php
// Dedicated direct file downloader for Perangkat Pembelajaran
error_reporting(E_ALL & ~E_DEPRECATED);

require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

if (!isAuthorized(['guru', 'wali', 'admin', 'tata_usaha', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('ID tidak valid.');
}

$stmt = $pdo->prepare("SELECT judul, file_path FROM tb_perangkat_pembelajaran WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['file_path'])) {
    http_response_code(404);
    exit('Berkas tidak ditemukan.');
}

ensure_learning_schema($pdo);

$file_path = resolve_guru_file_path('perangkat', $row['file_path']);

if (!is_file($file_path)) {
    http_response_code(404);
    exit('Berkas fisik tidak ditemukan di server.');
}

$ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
$clean_title = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim((string)$row['judul']));
$filename = ($clean_title !== '' ? $clean_title : 'perangkat') . '.' . $ext;

while (ob_get_level()) {
    ob_end_clean();
}

$mime = 'application/octet-stream';
if ($ext === 'pdf') $mime = 'application/pdf';
elseif ($ext === 'xls') $mime = 'application/vnd.ms-excel';
elseif ($ext === 'xlsx') $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
elseif ($ext === 'doc') $mime = 'application/msword';
elseif ($ext === 'docx') $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
elseif ($ext === 'ppt') $mime = 'application/vnd.ms-powerpoint';
elseif ($ext === 'pptx') $mime = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
elseif ($ext === 'zip') $mime = 'application/zip';

header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Access-Control-Expose-Headers: Content-Disposition');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Content-Length: ' . filesize($file_path));

readfile($file_path);
exit;
