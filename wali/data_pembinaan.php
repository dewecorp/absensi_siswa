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

// Handle CRUD Master Pembinaan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $jenis = trim((string)($_POST['jenis'] ?? ''));
        $permasalahan = trim((string)($_POST['permasalahan'] ?? ''));
        $tindakan = trim((string)($_POST['tindakan'] ?? ''));
        $tindak_lanjut = trim((string)($_POST['tindak_lanjut'] ?? ''));

        if ($jenis === '' || $permasalahan === '' || $tindakan === '') {
            $message = ['type' => 'warning', 'text' => 'Jenis, permasalahan/kasus, dan tindakan pembinaan wajib diisi.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $st = $pdo->prepare("
                        INSERT INTO tb_master_pembinaan (id_guru, jenis, permasalahan, tindakan, tindak_lanjut)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $st->execute([$guru_id, $jenis, $permasalahan, $tindakan, $tindak_lanjut]);
                    $message = ['type' => 'success', 'text' => 'Template pembinaan berhasil ditambahkan.'];
                } else {
                    $st = $pdo->prepare("
                        UPDATE tb_master_pembinaan SET
                            jenis = ?, permasalahan = ?, tindakan = ?, tindak_lanjut = ?
                        WHERE id = ? " . (!$is_admin_or_kepala ? "AND (id_guru = $guru_id OR id_guru IS NULL OR id_guru = 0)" : "") . "
                    ");
                    $st->execute([$jenis, $permasalahan, $tindakan, $tindak_lanjut, $id]);
                    $message = ['type' => 'success', 'text' => 'Template pembinaan berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $st = $pdo->prepare("DELETE FROM tb_master_pembinaan WHERE id = ? " . (!$is_admin_or_kepala ? "AND (id_guru = $guru_id OR id_guru IS NULL OR id_guru = 0)" : ""));
            $st->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Template pembinaan berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
}

// Filter jenis
$jenis_list = $pdo->query("SELECT DISTINCT jenis FROM tb_master_pembinaan ORDER BY jenis ASC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($jenis_list)) {
    $jenis_list = ['Akademik', 'Kedisiplinan', 'Sikap', 'Kehadiran', 'Sosial', 'Lainnya'];
}
$f_jenis = trim((string)($_GET['f_jenis'] ?? ''));
$where = ["1=1"];
$params = [];
if (!$is_admin_or_kepala) {
    $where[] = "(id_guru = ? OR id_guru IS NULL OR id_guru = 0)";
    $params[] = $guru_id;
}
if ($f_jenis !== '') {
    $where[] = "jenis = ?";
    $params[] = $f_jenis;
}
$where_sql = implode(' AND ', $where);
$st = $pdo->prepare("SELECT * FROM tb_master_pembinaan WHERE $where_sql ORDER BY jenis ASC, id ASC");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Data Pembinaan Siswa';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-master-bina').length) {
        $('#table-master-bina').DataTable({
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada template pembinaan',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    $('#btnTambahBina').on('click', function() {
        $('#formBinaAction').val('tambah');
        $('#binaId').val('');
        $('#modalBinaTitle').text('Tambah Template Pembinaan');
        $('#formBina')[0].reset();
        $('#modalBina').modal('show');
    });

    $(document).on('click', '.btn-edit-bina', function() {
        var data = $(this).data('json');
        $('#formBinaAction').val('edit');
        $('#binaId').val(data.id);
        $('#modalBinaTitle').text('Edit Template Pembinaan');
        $('#inp_b_jenis').val(data.jenis);
        $('#inp_b_masalah').val(data.permasalahan);
        $('#inp_b_tindakan').val(data.tindakan);
        $('#inp_b_tl').val(data.tindak_lanjut || '');
        $('#modalBina').modal('show');
    });

    $(document).on('click', '.btn-hapus-bina', function() {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Hapus Template?',
            text: 'Template pembinaan ini akan dihapus.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#formHapusBinaId').val(id);
                $('#formHapusBina').submit();
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
            <h1>Data Pembinaan Siswa</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <!-- Filter -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light py-2">
                    <h6 class="mb-0 text-dark"><i class="fas fa-filter mr-1 text-primary"></i> Filter Jenis Pembinaan</h6>
                </div>
                <div class="card-body py-3">
                    <form method="GET" class="form-row align-items-center">
                        <div class="col-md-5 mb-2">
                            <select name="f_jenis" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Jenis --</option>
                                <?php foreach ($jenis_list as $j): ?>
                                    <option value="<?= htmlspecialchars($j) ?>" <?= $f_jenis === $j ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($j) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-7 mb-2">
                            <a href="data_pembinaan.php" class="btn btn-secondary btn-sm"><i class="fas fa-undo mr-1"></i> Reset</a>
                            <small class="text-muted ml-2">Filter otomatis tersubmit saat diubah.</small>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Tabel Template -->
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                    <h4>Template Permasalahan, Tindakan &amp; Rencana Tindak Lanjut</h4>
                    <div>
                        <a href="pembinaan_siswa.php" class="btn btn-secondary btn-sm mr-2">
                            <i class="fas fa-clipboard-list mr-1"></i> Pembinaan Siswa
                        </a>
                        <button type="button" class="btn btn-primary btn-sm" id="btnTambahBina">
                            <i class="fas fa-plus mr-1"></i> Tambah Template
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover" id="table-master-bina" style="width:100%;">
                            <thead class="thead-light text-center">
                                <tr>
                                    <th style="width: 40px;">No</th>
                                    <th style="width: 140px;">Jenis</th>
                                    <th>Permasalahan / Kasus</th>
                                    <th>Tindakan Pembinaan</th>
                                    <th>Rencana Tindak Lanjut</th>
                                    <th style="width: 90px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="text-center align-middle"><?= $no++ ?></td>
                                        <td class="align-middle text-center">
                                            <span class="badge badge-info px-2 py-1 font-weight-bold" style="font-size: 11.5px;">
                                                <?= htmlspecialchars($r['jenis']) ?>
                                            </span>
                                        </td>
                                        <td class="align-middle" style="font-size: 13px; min-width: 200px;"><?= nl2br(htmlspecialchars($r['permasalahan'])) ?></td>
                                        <td class="align-middle" style="font-size: 13px; min-width: 200px;"><?= nl2br(htmlspecialchars($r['tindakan'])) ?></td>
                                        <td class="align-middle" style="font-size: 13px; min-width: 200px;"><?= !empty($r['tindak_lanjut']) ? nl2br(htmlspecialchars($r['tindak_lanjut'])) : '<span class="text-muted">-</span>' ?></td>
                                        <td class="text-center align-middle">
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-warning btn-edit-bina" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger btn-hapus-bina" data-id="<?= (int)$r['id'] ?>" title="Hapus">
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
<div class="modal fade" id="modalBina" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formBina">
                <input type="hidden" name="action" id="formBinaAction" value="tambah">
                <input type="hidden" name="id" id="binaId" value="">
                <div class="modal-header border-bottom py-3">
                    <h5 class="modal-title text-primary" id="modalBinaTitle">Tambah Template Pembinaan</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body p-3">
                    <style>
                        #modalBina textarea { min-height: 110px; line-height: 1.55; resize: vertical; overflow-y: auto; }
                    </style>
                    <div class="form-group">
                        <label class="font-weight-bold">Jenis Pembinaan <span class="text-danger">*</span></label>
                        <select name="jenis" id="inp_b_jenis" class="form-control" required>
                            <option value="">-- Pilih Jenis --</option>
                            <?php foreach (['Akademik', 'Kedisiplinan', 'Sikap', 'Kehadiran', 'Sosial', 'Lainnya'] as $j): ?>
                                <option value="<?= $j ?>"><?= $j ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Permasalahan / Kasus <span class="text-danger">*</span></label>
                        <textarea name="permasalahan" id="inp_b_masalah" class="form-control" rows="4" required placeholder="Contoh: Nilai harian turun pada 2 asesmen terakhir..."></textarea>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Tindakan Pembinaan <span class="text-danger">*</span></label>
                        <textarea name="tindakan" id="inp_b_tindakan" class="form-control" rows="4" required placeholder="Contoh: Bimbingan belajar personal + tutor sebaya..."></textarea>
                    </div>
                    <div class="form-group mb-0">
                        <label class="font-weight-bold">Rencana Tindak Lanjut</label>
                        <textarea name="tindak_lanjut" id="inp_b_tl" class="form-control" rows="4" placeholder="Contoh: Remedial terjadwal, pantau progres tiap pekan..."></textarea>
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

<form method="POST" id="formHapusBina" class="d-none">
    <input type="hidden" name="action" value="hapus">
    <input type="hidden" name="id" id="formHapusBinaId">
</form>

<?php include '../templates/footer.php'; ?>
