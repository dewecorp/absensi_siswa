<?php
/**
 * API modul tabungan: ambil saldo siswa.
 * Output: JSON { success, saldo, nama, kelas }
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');

$nisn = trim((string)($_GET['nisn'] ?? ''));

if ($nisn === '') {
    echo json_encode(['success' => false, 'message' => 'NISN wajib diisi.']);
    exit;
}

$siswa = tabungan_siswa($pdo, $nisn);
if (!$siswa) {
    echo json_encode(['success' => false, 'message' => 'Siswa tidak ditemukan.']);
    exit;
}

echo json_encode([
    'success' => true,
    'saldo' => tabungan_saldo($pdo, $nisn),
    'nama' => (string)$siswa['nama_siswa'],
    'kelas' => (string)($siswa['nama_kelas'] ?? '-'),
    'message' => '',
]);
