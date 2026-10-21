<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { $_SESSION['error'] = 'Sesi berakhir. Silakan muat ulang halaman dan coba lagi.'; header('Location: ' . BASE_URL . 'pages/potongan'); exit(); }
    $id = $_POST['id'] ?? null;
    $potongan_id = $_POST['potongan_id'] ?? 0;
    $guru_id = $_POST['guru_id'] ?? 0;
    $jumlah = str_replace(['.', ','], '', $_POST['jumlah'] ?? 0);
    $periode = $_POST['periode'] ?? '';
    
    if ($id) {
        $sql = "UPDATE potongan_detail SET guru_id=?, jumlah=?, periode=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("idsi", $guru_id, $jumlah, $periode, $id);
    } else {
        $sql = "INSERT INTO potongan_detail (guru_id, potongan_id, jumlah, periode) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iids", $guru_id, $potongan_id, $jumlah, $periode);
    }
    
    if ($stmt->execute()) {
        gajiLogActivity($conn, "Menyimpan detail potongan", 'success');
        $_SESSION['success'] = "Data berhasil disimpan";
    } else {
        $_SESSION['error'] = "Gagal menyimpan data";
    }
}

header('Location: ' . BASE_URL . 'pages/potongan/detail?potongan_id=' . $_POST['potongan_id']);
exit();
?>




