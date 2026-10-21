<?php
/**
 * Riwayat Transaksi Tabungan (jejak audit).
 * Menu: Tabungan > Riwayat.
 */

require_once __DIR__ . '/tabungan/config/config.php';

$page_title = 'Riwayat Transaksi Tabungan';
$csrf = appCsrfToken();
$pesan = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_riwayat'])) {
    if (!appVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $pesan = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } else {
        $ids = $_POST['id_riwayat'] ?? [];
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        $hasil = tabungan_hapus_riwayat($pdo, $ids);
        $pesan = $hasil['message'];
    }
}

$jenis = trim((string)($_GET['jenis'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));

$riwayat = tabungan_riwayat($pdo, [
    'jenis' => $jenis !== '' ? $jenis : null,
    'status' => $status !== '' ? $status : null,
    'limit' => 1000,
]);

include __DIR__ . '/../templates/header.php';
include_once __DIR__ . '/../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Riwayat Transaksi Tabungan</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="tabungan_ringkasan.php">Ringkasan Tabungan</a></div>
                <div class="breadcrumb-item active">Riwayat</div>
            </div>
        </div>

        <div class="section-body">

            <?php if ($pesan !== ''): ?>
                <div class="alert alert-<?= stripos($pesan, 'berhasil') !== false ? 'success' : 'warning' ?>">
                    <?= htmlspecialchars($pesan) ?>
                </div>
            <?php endif; ?>

            <div class="card mb-3">
                <div class="card-body">
                    <form method="GET" class="row align-items-end">
                        <div class="col-md-4 mb-2">
                            <label for="filter_jenis">Jenis Transaksi</label>
                            <select class="form-control" id="filter_jenis" name="jenis" onchange="this.form.submit()">
                                <option value="">Semua Jenis</option>
                                <option value="ST" <?= $jenis === 'ST' ? 'selected' : '' ?>>Setoran</option>
                                <option value="TR" <?= $jenis === 'TR' ? 'selected' : '' ?>>Penarikan</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2">
                            <label for="filter_status">Status</label>
                            <select class="form-control" id="filter_status" name="status" onchange="this.form.submit()">
                                <option value="">Semua Status</option>
                                <option value="Aktif" <?= $status === 'Aktif' ? 'selected' : '' ?>>Aktif</option>
                                <option value="Tidak Aktif" <?= $status === 'Tidak Aktif' ? 'selected' : '' ?>>Tidak Aktif</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2">
                            <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-search"></i> Tampilkan</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-history mr-2" style="color:#0d9488;"></i>Daftar Riwayat</h5>
                    <div class="card-header-action">
                        <span class="badge badge-info"><?= count($riwayat) ?> baris</span>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" id="formHapusRiwayat">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="hapus_riwayat" value="1">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover" id="tableRiwayat" style="width:100%;">
                                <thead>
                                    <tr>
                                        <th>No</th>
                                        <th>Jenis</th>
                                        <th>Status</th>
                                        <th>NISN</th>
                                        <th>Nama Siswa</th>
                                        <th>Tanggal Transaksi</th>
                                        <th>Jumlah</th>
                                        <th>Admin/Bendahara Transaksi</th>
                                        <th>Tanggal Hapus</th>
                                        <th>Admin/Bendahara Hapus</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($riwayat)): ?>
                                        <tr>
                                            <td colspan="11" class="text-center py-4 text-muted">Belum ada riwayat transaksi.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($riwayat as $i => $r):
                                            $st = (string)($r['status'] ?? 'Aktif');
                                            $nominal = (string)$r['jenis'] === 'ST' ? (int)$r['setor'] : (int)$r['tarik'];
                                        ?>
                                            <tr>
                                                <td><?= $i + 1 ?></td>
                                                <td>
                                                    <?php if ((string)$r['jenis'] === 'ST'): ?>
                                                        <span class="badge badge-success">Setoran</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-danger">Penarikan</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($st === 'Aktif'): ?>
                                                        <span class="badge badge-primary">Aktif</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary">Tidak Aktif</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars((string)$r['nisn']) ?></td>
                                                <td><?= htmlspecialchars((string)($r['nama_siswa'] ?? 'Siswa tidak ditemukan')) ?></td>
                                                <td><?= htmlspecialchars(tabungan_tanggal_indo((string)$r['tgl'])) ?></td>
                                                <td class="font-weight-bold"><?= tabungan_rupiah($nominal) ?></td>
                                                <td><?= htmlspecialchars(tabungan_aktor_label($r['petugas'] ?? '-')) ?></td>
                                                <td><?= htmlspecialchars(tabungan_tanggal_indo((string)($r['tgl_hapus'] ?? ''), true)) ?></td>
                                                <td><?= htmlspecialchars(tabungan_aktor_label($r['petugas_hapus'] ?? '-')) ?></td>
                                                <td>
                                                    <?php if ($st !== 'Aktif'): ?>
                                                        <div class="custom-control custom-checkbox">
                                                            <input type="checkbox" class="custom-control-input" name="id_riwayat[]" value="<?= (int)$r['id_riwayat'] ?>" id="riwayat_<?= (int)$r['id_riwayat'] ?>">
                                                            <label class="custom-control-label" for="riwayat_<?= (int)$r['id_riwayat'] ?>" style="font-size:.7rem;">Pilih</label>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted small" title="Data aktif tidak dapat dihapus">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (!empty($riwayat)): ?>
                            <div class="mt-3">
                                <button type="submit" class="btn btn-danger btn-sm" data-tb-konfirmasi="Riwayat transaksi terpilih akan dihapus dan tidak dapat dikembalikan." data-tb-judul="Hapus Riwayat?">
                                    <i class="fas fa-trash"></i> <span>Hapus Riwayat Terpilih</span>
                                </button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

        </div>
    </section>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
