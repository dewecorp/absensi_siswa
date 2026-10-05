<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['wali', 'admin'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$message = null;

// Handle CRUD Master Konseling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $topik = trim((string)($_POST['topik'] ?? ''));
        $ringkasan = trim((string)($_POST['ringkasan'] ?? ''));
        $tindak_lanjut = trim((string)($_POST['tindak_lanjut'] ?? ''));
        $follow_up = trim((string)($_POST['follow_up'] ?? ''));

        if ($topik === '' || $ringkasan === '' || $tindak_lanjut === '') {
            $message = ['type' => 'warning', 'text' => 'Topik, ringkasan masalah, dan tindak lanjut wajib diisi.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $st = $pdo->prepare("
                        INSERT INTO tb_master_konseling (id_guru, topik, ringkasan, tindak_lanjut, follow_up)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $st->execute([$guru_id, $topik, $ringkasan, $tindak_lanjut, $follow_up]);
                    $message = ['type' => 'success', 'text' => 'Template konseling berhasil ditambahkan.'];
                } else {
                    $st = $pdo->prepare("
                        UPDATE tb_master_konseling SET
                            topik = ?, ringkasan = ?, tindak_lanjut = ?, follow_up = ?
                        WHERE id = ? " . ($user_level !== 'admin' ? "AND (id_guru = $guru_id OR id_guru IS NULL OR id_guru = 0)" : "") . "
                    ");
                    $st->execute([$topik, $ringkasan, $tindak_lanjut, $follow_up, $id]);
                    $message = ['type' => 'success', 'text' => 'Template konseling berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $st = $pdo->prepare("DELETE FROM tb_master_konseling WHERE id = ? " . ($user_level !== 'admin' ? "AND (id_guru = $guru_id OR id_guru IS NULL OR id_guru = 0)" : ""));
            $st->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Template konseling berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
}

// Filter topik
$topik_list = $pdo->query("SELECT DISTINCT topik FROM tb_master_konseling ORDER BY topik ASC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($topik_list)) {
    $topik_list = ['Motivasi Belajar', 'Penyesuaian Sosial', 'Keluarga', 'Kedisiplinan', 'Kecemasan / Emosi', 'Minat & Bakat', 'Ibadah & Spiritual', 'Lainnya'];
}
$f_topik = trim((string)($_GET['f_topik'] ?? ''));
$where = ["1=1"];
$params = [];
if ($user_level !== 'admin') {
    $where[] = "(id_guru = ? OR id_guru IS NULL OR id_guru = 0)";
    $params[] = $guru_id;
}
if ($f_topik !== '') {
    $where[] = "topik = ?";
    $params[] = $f_topik;
}
$where_sql = implode(' AND ', $where);
$st = $pdo->prepare("SELECT * FROM tb_master_konseling WHERE $where_sql ORDER BY topik ASC, id ASC");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Data Konseling Siswa';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-master-konseling').length) {
        $('#table-master-konseling').DataTable({
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada template konseling',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    $('#btnTambahKonseling').on('click', function() {
        $('#formKonselingAction').val('tambah');
        $('#konselingId').val('');
        $('#modalKonselingTitle').text('Tambah Template Konseling');
        $('#formKonseling')[0].reset();
        $('#modalKonseling').modal('show');
    });

    $(document).on('click', '.btn-edit-konseling', function() {
        var data = $(this).data('json');
        $('#formKonselingAction').val('edit');
        $('#konselingId').val(data.id);
        $('#modalKonselingTitle').text('Edit Template Konseling');
        $('#inp_b_topik').val(data.topik);
        $('#inp_b_masalah').val(data.ringkasan);
        $('#inp_b_tindak_lanjut').val(data.tindak_lanjut);
        $('#inp_b_tl').val(data.follow_up || '');
        $('#modalKonseling').modal('show');
    });

    $(document).on('click', '.btn-hapus-konseling', function() {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Hapus Template?',
            text: 'Template konseling ini akan dihapus.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#formHapusKonselingId').val(id);
                $('#formHapusKonseling').submit();
            }
        });
    });
});
JS
];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Data Konseling Siswa</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <!-- Filter -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light py-2">
                    <h6 class="mb-0 text-dark"><i class="fas fa-filter mr-1 text-primary"></i> Filter Topik / Fokus Konseling</h6>
                </div>
                <div class="card-body py-3">
                    <form method="GET" class="form-row align-items-center">
                        <div class="col-md-5 mb-2">
                            <select name="f_topik" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Topik --</option>
                                <?php foreach ($topik_list as $j): ?>
                                    <option value="<?= htmlspecialchars($j) ?>" <?= $f_topik === $j ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($j) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-7 mb-2">
                            <a href="data_konseling.php" class="btn btn-secondary btn-sm"><i class="fas fa-undo mr-1"></i> Reset</a>
                            <small class="text-muted ml-2">Filter otomatis tersubmit saat diubah.</small>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Tabel Template -->
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                    <h4>Template Topik, Ringkasan, Tindak Lanjut &amp; Follow Up</h4>
                    <div>
                        <a href="konseling_awal.php" class="btn btn-secondary btn-sm mr-2">
                            <i class="fas fa-clipboard-list mr-1"></i> Konseling Siswa
                        </a>
                        <button type="button" class="btn btn-primary btn-sm" id="btnTambahKonseling">
                            <i class="fas fa-plus mr-1"></i> Tambah Template
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover" id="table-master-konseling" style="width:100%;">
                            <thead class="thead-light text-center">
                                <tr>
                                    <th style="width: 40px;">No</th>
                                    <th style="width: 140px;">Topik</th>
                                    <th>Ringkasan Masalah / Hasil</th>
                                    <th>Tindak Lanjut / Solusi</th>
                                     <th>Rencana Follow Up</th>
                                     <th style="width: 90px;">Aksi</th>
                                 </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="text-center align-middle"><?= $no++ ?></td>
                                        <td class="align-middle text-center">
                                            <span class="badge badge-info px-2 py-1 font-weight-bold" style="font-size: 11.5px;">
                                                <?= htmlspecialchars($r['topik']) ?>
                                            </span>
                                        </td>
                                        <td class="align-middle" style="font-size: 13px; min-width: 200px;"><?= nl2br(htmlspecialchars($r['ringkasan'])) ?></td>
                                        <td class="align-middle" style="font-size: 13px; min-width: 200px;"><?= nl2br(htmlspecialchars($r['tindak_lanjut'])) ?></td>
                                        <td class="align-middle" style="font-size: 13px; min-width: 200px;"><?= !empty($r['follow_up']) ? nl2br(htmlspecialchars($r['follow_up'])) : '<span class="text-muted">-</span>' ?></td>
                                        <td class="text-center align-middle">
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-warning btn-edit-konseling" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger btn-hapus-konseling" data-id="<?= (int)$r['id'] ?>" title="Hapus">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Modal Tambah / Edit Template -->
<div class="modal fade" id="modalKonseling" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formKonseling">
                <input type="hidden" name="action" id="formKonselingAction" value="tambah">
                <input type="hidden" name="id" id="konselingId" value="">
                <div class="modal-header border-bottom py-3">
                    <h5 class="modal-title text-primary" id="modalKonselingTitle">Tambah Template Konseling</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body p-3">
                    <style>
                        #modalKonseling textarea { min-height: 110px; line-height: 1.55; resize: vertical; overflow-y: auto; }
                    </style>
                    <div class="form-group">
                        <label class="font-weight-bold">Topik Konseling <span class="text-danger">*</span></label>
                        <select name="topik" id="inp_b_topik" class="form-control" required>
                            <option value="">-- Pilih Topik --</option>
                            <?php foreach (['Motivasi Belajar', 'Penyesuaian Sosial', 'Keluarga', 'Kedisiplinan', 'Kecemasan / Emosi', 'Minat & Bakat', 'Ibadah & Spiritual', 'Lainnya'] as $j): ?>
                                <option value="<?= $j ?>"><?= $j ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Ringkasan Masalah / Hasil <span class="text-danger">*</span></label>
                        <textarea name="ringkasan" id="inp_b_masalah" class="form-control" rows="4" required placeholder="Contoh: Siswa kurang semangat belajar dan mudah menyerah..."></textarea>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Tindak Lanjut / Solusi <span class="text-danger">*</span></label>
                        <textarea name="tindak_lanjut" id="inp_b_tindak_lanjut" class="form-control" rows="4" required placeholder="Contoh: Bimbingan motivasi + target belajar kecil yang terukur..."></textarea>
                    </div>
                    <div class="form-group mb-0">
                        <label class="font-weight-bold">Rencana Follow Up</label>
                        <textarea name="follow_up" id="inp_b_tl" class="form-control" rows="4" placeholder="Contoh: Pantau progres tiap pekan, jadwal pertemuan ulang 2 minggu lagi..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save mr-1"></i> Simpan Template</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form method="POST" id="formHapusKonseling" class="d-none">
    <input type="hidden" name="action" value="hapus">
    <input type="hidden" name="id" id="formHapusKonselingId">
</form>

<?php include '../templates/footer.php'; ?>
