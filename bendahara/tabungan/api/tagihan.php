<?php
/**
 * API modul tabungan: daftar tagihan siswa (dari modul Pembayaran).
 * Output: JSON { success, saldo, tahun_ajaran, items[], tunggakan_tahun_ajaran_lama[] }
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

$tagihan = tabungan_tagihan_siswa($nisn);
$tunggakan_lama = tabungan_tunggakan_tahun_lama($nisn);

echo json_encode([
    'success' => (bool)$tagihan['ok'],
    'message' => (string)$tagihan['message'],
    'nama' => (string)$siswa['nama_siswa'],
    'kelas' => (string)($siswa['nama_kelas'] ?? '-'),
    'saldo' => tabungan_saldo($pdo, $nisn),
    'tahun_ajaran' => (string)$tagihan['tahun_ajaran'],
    'items' => $tagihan['items'],
    'tunggakan_tahun_ajaran_lama' => array_keys($tunggakan_lama),
]);
