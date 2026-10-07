<?php
// Unduh file soal asli hasil upload manual (Bank Soal)
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

if (!isAuthorized(['guru', 'wali', 'admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$kode_paket = trim((string)($_GET['kode_paket'] ?? ''));
if ($kode_paket === '') {
    http_response_code(400);
    exit('Kode paket tidak valid.');
}

ensure_learning_schema($pdo);

$st = $pdo->prepare("SELECT file_soal, topik FROM tb_bank_soal WHERE kode_paket = ? AND id_guru = ? AND file_soal IS NOT NULL AND file_soal != '' ORDER BY id ASC LIMIT 1");
$st->execute([$kode_paket, $guru_id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    exit('Berkas soal asli tidak ditemukan untuk paket ini.');
}

$file_path = resolve_guru_file_path('bank_soal', $row['file_soal']);

if (!is_file($file_path)) {
    http_response_code(404);
    exit('Berkas fisik tidak ditemukan di server.');
}

$ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
$clean_topik = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim((string)($row['topik'] ?? '')));
$filename = ($clean_topik !== '' ? substr($clean_topik, 0, 60) : 'soal') . '.' . $ext;

while (ob_get_level()) {
    ob_end_clean();
}

$mime = 'application/octet-stream';
if ($ext === 'pdf') $mime = 'application/pdf';
elseif ($ext === 'xls') $mime = 'application/vnd.ms-excel';
elseif ($ext === 'xlsx') $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
elseif ($ext === 'doc') $mime = 'application/msword';
elseif ($ext === 'docx') $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
elseif ($ext === 'txt') $mime = 'text/plain; charset=utf-8';

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
