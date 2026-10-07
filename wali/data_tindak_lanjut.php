<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['wali', 'admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$is_admin_or_kepala = in_array($user_level, ['admin', 'kepala_madrasah'], true) || in_array($_GET['session_type'] ?? '', ['admin', 'kepala_madrasah'], true);
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$message = null;

// Handle CRUD Master Tindak Lanjut
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $sumber = trim((string)($_POST['sumber'] ?? ''));
        $tindakan = trim((string)($_POST['tindakan'] ?? ''));
        $penanggung_jawab = trim((string)($_POST['penanggung_jawab'] ?? ''));

        if ($sumber === '' || $tindakan === '') {
            $message = ['type' => 'warning', 'text' => 'Sumber dan tindakan/langkah perbaikan wajib diisi.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $st = $pdo->prepare("
                        INSERT INTO tb_master_tindak_lanjut (id_guru, sumber, tindakan, penanggung_jawab)
                        VALUES (?, ?, ?, ?)
                    ");
                    $st->execute([$guru_id, $sumber, $tindakan, $penanggung_jawab]);
                    $message = ['type' => 'success', 'text' => 'Template tindak lanjut berhasil ditambahkan.'];
                } else {
                    $st = $pdo->prepare("
                        UPDATE tb_master_tindak_lanjut SET
                            sumber = ?, tindakan = ?, penanggung_jawab = ?
                        WHERE id = ? " . (!$is_admin_or_kepala ? "AND (id_guru = $guru_id OR id_guru IS NULL OR id_guru = 0)" : "") . "
                    ");
                    $st->execute([$sumber, $tindakan, $penanggung_jawab, $id]);
                    $message = ['type' => 'success', 'text' => 'Template tindak lanjut berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $st = $pdo->prepare("DELETE FROM tb_master_tindak_lanjut WHERE id = ? " . (!$is_admin_or_kepala ? "AND (id_guru = $guru_id OR id_guru IS NULL OR id_guru = 0)" : ""));
            $st->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Template tindak lanjut berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
}

// Filter sumber
$sumber_list = ['Pembinaan', 'Pelanggaran', 'Konseling', 'Perkembangan'];
$f_sumber = trim((string)($_GET['f_sumber'] ?? ''));
$where = ["1=1"];
$params = [];
if (!$is_admin_or_kepala) {
    $where[] = "(id_guru = ? OR id_guru IS NULL OR id_guru = 0)";
    $params[] = $guru_id;
}
if ($f_sumber !== '') {
    $where[] = "sumber = ?";
    $params[] = $f_sumber;
}
$where_sql = implode(' AND ', $where);
$st = $pdo->prepare("SELECT * FROM tb_master_tindak_lanjut WHERE $where_sql ORDER BY sumber ASC, id ASC");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Data Tindak Lanjut';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-master-tl').length) {
        $('#table-master-tl').DataTable({
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada template tindak lanjut',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    $('#btnTambahTL').on('click', function() {
        $('#formTLAction').val('tambah');
        $('#tlId').val('');
        $('#modalTLTitle').text('Tambah Template Tindak Lanjut');
        $('#formTL')[0].reset();
        $('#modalTL').modal('show');
    });

    $(document).on('click', '.btn-edit-tl', function() {
        var data = $(this).data('json');
        $('#formTLAction').val('edit');
        $('#tlId').val(data.id);
        $('#modalTLTitle').text('Edit Template Tindak Lanjut');
        $('#inp_m_sumber').val(data.sumber);
        $('#inp_m_tindakan').val(data.tindakan);
        $('#inp_m_pj').val(data.penanggung_jawab || '');
        $('#modalTL').modal('show');
    });

    $(document).on('click', '.btn-hapus-tl', function() {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Hapus Template?',
            text: 'Template tindak lanjut ini akan dihapus.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#formHapusTLId').val(id);
                $('#formHapusTL').submit();
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
            <h1>Data Tindak Lanjut</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <!-- Filter -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light py-2">
                    <h6 class="mb-0 text-dark"><i class="fas fa-filter mr-1 text-primary"></i> Filter Sumber Tindak Lanjut</h6>
                </div>
                <div class="card-body py-3">
                    <form method="GET" class="form-row align-items-center">
                        <div class="col-md-5 mb-2">
                            <select name="f_sumber" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Sumber --</option>
                                <?php foreach ($sumber_list as $s): ?>
                                    <option value="<?= htmlspecialchars($s) ?>" <?= $f_sumber === $s ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-7 mb-2">
                            <a href="data_tindak_lanjut.php" class="btn btn-secondary btn-sm"><i class="fas fa-undo mr-1"></i> Reset</a>
                            <small class="text-muted ml-2">Filter otomatis tersubmit saat diubah.</small>
                        </div>
                    </form>
                </div>
            </div>

            <div class="alert alert-light border small text-muted mb-3">
                <i class="fas fa-gavel mr-1 text-danger"></i> <strong>Ambang sanksi akumulasi poin pelanggaran:</strong>
                0-24 Pembinaan Ringan &bull; 25-49 Dalam Pemantauan &bull; 50-74 SP 1 / Pembinaan Khusus &bull; 75-99 Skorsing &bull; 100+ Dikeluarkan (DO).
            </div>

            <!-- Tabel Template -->
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                    <h4>Template Tindakan / Langkah Perbaikan</h4>
                    <div>
                        <a href="tindak_lanjut.php" class="btn btn-secondary btn-sm mr-2">
                            <i class="fas fa-clipboard-list mr-1"></i> Tindak Lanjut
                        </a>
                        <button type="button" class="btn btn-primary btn-sm" id="btnTambahTL">
                            <i class="fas fa-plus mr-1"></i> Tambah Template
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover" id="table-master-tl" style="width:100%;">
                            <thead class="thead-light text-center">
                                <tr>
                                    <th style="width: 40px;">No</th>
                                    <th style="width: 140px;">Sumber</th>
                                    <th>Tindakan / Langkah Perbaikan</th>
                                    <th style="width: 170px;">Penanggung Jawab</th>
                                    <th style="width: 90px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="text-center align-middle"><?= $no++ ?></td>
                                        <td class="align-middle text-center">
                                            <span class="badge badge-info px-2 py-1 font-weight-bold" style="font-size: 11.5px;">
                                                <?= htmlspecialchars($r['sumber']) ?>
                                            </span>
                                        </td>
                                        <td class="align-middle" style="font-size: 13px; min-width: 240px;"><?= nl2br(htmlspecialchars($r['tindakan'])) ?></td>
                                        <td class="align-middle" style="font-size: 13px;"><?= htmlspecialchars($r['penanggung_jawab'] ?: '-') ?></td>
                                        <td class="text-center align-middle">
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-warning btn-edit-tl" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger btn-hapus-tl" data-id="<?= (int)$r['id'] ?>" title="Hapus">
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
<div class="modal fade" id="modalTL" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formTL">
                <input type="hidden" name="action" id="formTLAction" value="tambah">
                <input type="hidden" name="id" id="tlId" value="">
                <div class="modal-header border-bottom py-3">
                    <h5 class="modal-title text-primary" id="modalTLTitle">Tambah Template Tindak Lanjut</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body p-3">
                    <style>
                        #modalTL textarea { min-height: 110px; line-height: 1.55; resize: vertical; overflow-y: auto; }
                    </style>
                    <div class="form-group">
                        <label class="font-weight-bold">Sumber Masalah <span class="text-danger">*</span></label>
                        <select name="sumber" id="inp_m_sumber" class="form-control" required>
                            <option value="">-- Pilih Sumber --</option>
                            <?php foreach (['Pembinaan', 'Pelanggaran', 'Konseling', 'Perkembangan'] as $s): ?>
                                <option value="<?= $s ?>"><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Tindakan / Langkah Perbaikan <span class="text-danger">*</span></label>
                        <textarea name="tindakan" id="inp_m_tindakan" class="form-control" rows="4" required placeholder="Contoh: Pemanggilan orang tua + pembinaan intensif 2 pekan..."></textarea>
                    </div>
                    <div class="form-group mb-0">
                        <label class="font-weight-bold">Penanggung Jawab Default</label>
                        <input type="text" name="penanggung_jawab" id="inp_m_pj" class="form-control" placeholder="Contoh: Wali Kelas">
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

<form method="POST" id="formHapusTL" class="d-none">
    <input type="hidden" name="action" value="hapus">
    <input type="hidden" name="id" id="formHapusTLId">
</form>

<?php include '../templates/footer.php'; ?>
