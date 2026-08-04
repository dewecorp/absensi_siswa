<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAuthorized(['admin'])) {
    die('Akses ditolak. Hanya admin.');
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reorder'])) {
    $stmt = $pdo->query("SELECT id_guru, nama_guru, kode_guru FROM tb_guru ORDER BY nama_guru ASC");
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare("UPDATE tb_guru SET kode_guru = ? WHERE id_guru = ?");
        $letters = range('A', 'Z');
        foreach ($teachers as $i => $t) {
            if ($i < 26) {
                $newCode = $letters[$i];
            } else {
                $newCode = chr(65 + intdiv($i, 26) - 1) . $letters[$i % 26];
            }
            $update->execute([$newCode, $t['id_guru']]);
        }
        $pdo->commit();
        $message = '<div class="alert alert-success">Berhasil mengurutkan ulang ' . count($teachers) . ' kode guru!</div>';
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = '<div class="alert alert-danger">Gagal: ' . $e->getMessage() . '</div>';
    }
}

$stmt = $pdo->query("SELECT id_guru, nama_guru, kode_guru FROM tb_guru ORDER BY nama_guru ASC");
$teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Reorder Kode Guru</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="card shadow">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Urutkan Ulang Kode Guru</h5>
        </div>
        <div class="card-body">
            <?php echo $message; ?>
            <p class="text-muted">Akan mengurutkan ulang kode guru menjadi A, B, C, ... sesuai urutan nama guru.</p>
            <table class="table table-bordered table-sm mb-4">
                <thead class="thead-light">
                    <tr><th>No</th><th>Kode Saat Ini</th><th>Nama Guru</th></tr>
                </thead>
                <tbody>
                    <?php $no = 1; foreach ($teachers as $t): ?>
                    <tr>
                        <td><?php echo $no++; ?></td>
                        <td><strong><?php echo htmlspecialchars($t['kode_guru']); ?></strong></td>
                        <td><?php echo htmlspecialchars($t['nama_guru']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <form method="POST">
                <button type="submit" name="reorder" class="btn btn-warning" onclick="return confirm('Yakin ingin mengurutkan ulang semua kode guru?')">
                    <i class="fas fa-sort-alpha-down"></i> Urutkan Ulang Kode Guru
                </button>
                <a href="data_guru.php" class="btn btn-secondary">Kembali</a>
            </form>
        </div>
    </div>
</div>
</body>
</html>
