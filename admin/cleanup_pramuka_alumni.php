<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

$page_title = 'Cleanup Data Pramuka Alumni';
$message = null;

// Handle cleanup action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cleanup_now'])) {
    try {
        $pdo->beginTransaction();

        // Step 1: Find all pramuka members linked to siswa with id_kelas IS NULL (alumni)
        $findStmt = $pdo->prepare("
            SELECT p.id_peserta_didik_barung, p.nama_peserta_didik, p.nta
            FROM tb_peserta_didik_barung p
            INNER JOIN tb_siswa s ON (
                s.id_siswa = p.id_siswa
                OR (
                    p.id_siswa IS NULL
                    AND TRIM(IFNULL(p.nta, '')) <> ''
                    AND CONVERT(TRIM(IFNULL(s.nisn, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                        = CONVERT(TRIM(IFNULL(p.nta, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                )
            )
            WHERE IFNULL(p.status, 'aktif') = 'aktif'
              AND s.id_kelas IS NULL
        ");
        $findStmt->execute();
        $toCleanup = $findStmt->fetchAll(PDO::FETCH_ASSOC);
        $cleanupCount = count($toCleanup);

        if ($cleanupCount > 0) {
            // Get the ids to cleanup
            $ids = array_map(fn($row) => (int)$row['id_peserta_didik_barung'], $toCleanup);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            // First delete related SKU values
            $pdo->prepare("DELETE FROM tb_sku_kecakapan_nilai WHERE id_peserta_didik_barung IN ($placeholders)")
                ->execute($ids);

            // Then mark the pramuka members as keluar
            $updateStmt = $pdo->prepare("
                UPDATE tb_peserta_didik_barung
                SET status = 'keluar', tanggal_keluar = NOW()
                WHERE id_peserta_didik_barung IN ($placeholders)
            ");
            $updateStmt->execute($ids);
        }

        $pdo->commit();
        $message = [
            'type' => 'success',
            'text' => "Berhasil membersihkan {$cleanupCount} data anggota pramuka yang sudah menjadi alumni!"
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = [
            'type' => 'danger',
            'text' => 'Gagal membersihkan data: ' . $e->getMessage()
        ];
    }
}

// Get count of records to cleanup
$countToCleanup = 0;
try {
    $countStmt = $pdo->query("
        SELECT COUNT(*) AS cnt
        FROM tb_peserta_didik_barung p
        INNER JOIN tb_siswa s ON (
            s.id_siswa = p.id_siswa
            OR (
                p.id_siswa IS NULL
                AND TRIM(IFNULL(p.nta, '')) <> ''
                AND CONVERT(TRIM(IFNULL(s.nisn, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                    = CONVERT(TRIM(IFNULL(p.nta, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci
            )
        )
        WHERE IFNULL(p.status, 'aktif') = 'aktif'
          AND s.id_kelas IS NULL
    ");
    $countToCleanup = (int)($countStmt->fetchColumn() ?: 0);
} catch (Exception $e) {
    // Ignore count error
}

require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><?= $page_title ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4>Cleanup Data Anggota Pramuka Alumni</h4>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <p><strong>Info:</strong> Halaman ini membantu membersihkan data anggota pramuka yang sudah menjadi alumni (tidak memiliki kelas).</p>
                        <p>Saat ini terdapat <strong><?= $countToCleanup ?></strong> data anggota pramuka yang perlu dibersihkan.</p>
                    </div>

                    <?php if ($message): ?>
                        <div class="alert alert-<?= $message['type'] ?>">
                            <?= htmlspecialchars($message['text']) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="mt-4">
                        <button type="submit" name="cleanup_now" class="btn btn-danger" <?= $countToCleanup === 0 ? 'disabled' : '' ?>>
                            <i class="fas fa-broom"></i> Bersihkan Data Alumni dari Pramuka
                        </button>
                        <a href="data_barung.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Kembali ke Data Pramuka
                        </a>
                    </form>
                </div>
            </div>
        </div>
    </section>
</div>

<?php require_once '../templates/footer.php'; ?>
