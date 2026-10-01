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

$wali_kelas_id = 0;
$wali_kelas_name = '';
$stmtWali = $pdo->prepare("SELECT id_kelas, nama_kelas FROM tb_kelas WHERE wali_kelas = ? OR wali_kelas = (SELECT nama_guru FROM tb_guru WHERE id_guru = ?)");
$stmtWali->execute([$guru_id, $guru_id]);
$wali_class = $stmtWali->fetch(PDO::FETCH_ASSOC);
if ($wali_class) {
    $wali_kelas_id = (int)$wali_class['id_kelas'];
    $wali_kelas_name = $wali_class['nama_kelas'];
}

$all_classes = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);

$selected_kelas_id = $wali_kelas_id;
if ($user_level === 'admin' && isset($_GET['kelas'])) {
    $selected_kelas_id = (int)$_GET['kelas'];
}

$message = null;

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $id_siswa = (int)($_POST['id_siswa'] ?? 0);
        $id_kelas = (int)($_POST['id_kelas'] ?? $selected_kelas_id);
        $tanggal = !empty($_POST['tanggal']) ? date('Y-m-d', strtotime($_POST['tanggal'])) : date('Y-m-d');
        $sumber = in_array($_POST['sumber'] ?? '', ['Pembinaan', 'Pelanggaran', 'Konseling', 'Perkembangan'], true) ? $_POST['sumber'] : 'Pembinaan';
        $tindakan = trim((string)($_POST['tindakan'] ?? ''));
        $penanggung_jawab = trim((string)($_POST['penanggung_jawab'] ?? ''));
        $target_selesai = !empty($_POST['target_selesai']) ? date('Y-m-d', strtotime($_POST['target_selesai'])) : null;
        $tanggal_selesai = !empty($_POST['tanggal_selesai']) ? date('Y-m-d', strtotime($_POST['tanggal_selesai'])) : null;
        $status = in_array($_POST['status'] ?? '', ['Rencana', 'Proses', 'Selesai', 'Dibatalkan'], true) ? $_POST['status'] : 'Rencana';

        if ($id_siswa <= 0 || $tindakan === '' || $penanggung_jawab === '') {
            $message = ['type' => 'warning', 'text' => 'Pilih Siswa, isi Tindakan, dan Penanggung Jawab.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_tindak_lanjut_wali (
                            id_wali, id_siswa, id_kelas, tanggal, sumber,
                            tindakan, penanggung_jawab, target_selesai, tanggal_selesai, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $guru_id, $id_siswa, $id_kelas, $tanggal, $sumber,
                        $tindakan, $penanggung_jawab, $target_selesai, $tanggal_selesai, $status
                    ]);
                    $message = ['type' => 'success', 'text' => 'Program tindak lanjut berhasil dicatat.'];
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE tb_tindak_lanjut_wali SET
                            id_siswa = ?, id_kelas = ?, tanggal = ?, sumber = ?,
                            tindakan = ?, penanggung_jawab = ?, target_selesai = ?,
                            tanggal_selesai = ?, status = ?
                        WHERE id = ? " . ($user_level !== 'admin' ? "AND id_wali = $guru_id" : "") . "
                    ");
                    $stmt->execute([
                        $id_siswa, $id_kelas, $tanggal, $sumber,
                        $tindakan, $penanggung_jawab, $target_selesai,
                        $tanggal_selesai, $status, $id
                    ]);
                    $message = ['type' => 'success', 'text' => 'Tindak lanjut berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM tb_tindak_lanjut_wali WHERE id = ? " . ($user_level !== 'admin' ? "AND id_wali = $guru_id" : ""))->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Data tindak lanjut berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
}

$siswa_list = [];
if ($selected_kelas_id > 0) {
    $stS = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
    $stS->execute([$selected_kelas_id]);
    $siswa_list = $stS->fetchAll(PDO::FETCH_ASSOC);
}

$f_sumber = trim((string)($_GET['f_sumber'] ?? ''));
$f_status = trim((string)($_GET['f_status'] ?? ''));

$where = ["1=1"];
$params = [];
if ($selected_kelas_id > 0) {
    $where[] = "t.id_kelas = ?";
    $params[] = $selected_kelas_id;
}
if ($user_level !== 'admin') {
    $where[] = "t.id_wali = ?";
    $params[] = $guru_id;
}
if ($f_sumber !== '') {
    $where[] = "t.sumber = ?";
    $params[] = $f_sumber;
}
if ($f_status !== '') {
    $where[] = "t.status = ?";
    $params[] = $f_status;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT t.*, s.nama_siswa, s.nisn, k.nama_kelas
    FROM tb_tindak_lanjut_wali t
    JOIN tb_siswa s ON s.id_siswa = t.id_siswa
    LEFT JOIN tb_kelas k ON k.id_kelas = t.id_kelas
    WHERE $where_sql
    ORDER BY t.tanggal DESC, t.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sumber_options = ['Pembinaan', 'Pelanggaran', 'Konseling', 'Perkembangan'];
$status_options = ['Rencana', 'Proses', 'Selesai', 'Dibatalkan'];

$page_title = 'Daftar Tindak Lanjut';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-tindak-lanjut').length) {
        $('#table-tindak-lanjut').DataTable({
            'order': [[1, 'desc']],
            'columnDefs': [{ 'sortable': false, 'targets': [9] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada data tindak lanjut',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    $('#btnTambahTL').on('click', function() {
        $('#formTLAction').val('tambah');
        $('#tlId').val('');
        $('#modalTLTitle').text('Tambah Rencana Tindak Lanjut');
        $('#formTL')[0].reset();
        $('#modalTL').modal('show');
    });

    $(document).on('click', '.btn-edit-tl', function() {
        var data = $(this).data('json');
        $('#formTLAction').val('edit');
        $('#tlId').val(data.id);
        $('#modalTLTitle').text('Edit Tindak Lanjut');
        $('#inp_siswa').val(data.id_siswa);
        $('#inp_tanggal').val(data.tanggal);
        $('#inp_sumber').val(data.sumber);
        $('#inp_tindakan').val(data.tindakan);
        $('#inp_pj').val(data.penanggung_jawab);
        $('#inp_target').val(data.target_selesai || '');
        $('#inp_selesai').val(data.tanggal_selesai || '');
        $('#inp_status').val(data.status);
        $('#modalTL').modal('show');
    });

    $(document).on('click', '.btn-detail-tl', function() {
        var data = $(this).data('json');
        $('#det_tanggal').text(data.tanggal);
        $('#det_nama').text(data.nama_siswa);
        $('#det_nisn').text(data.nisn || '-');
        $('#det_kelas').text(data.nama_kelas || '-');
        $('#det_sumber').html('<span class="badge badge-info">' + data.sumber + '</span>');
        $('#det_status').text(data.status);
        $('#det_pj').text(data.penanggung_jawab);
        $('#det_target').text(data.target_selesai || '-');
        $('#det_selesai').text(data.tanggal_selesai || '-');
        $('#det_tindakan').text(data.tindakan);
        $('#modalDetailTL').modal('show');
    });

    $(document).on('click', '.btn-hapus-tl', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        Swal.fire({
            title: 'Hapus Tindak Lanjut?',
            text: 'Data tindak lanjut untuk ' + nama + ' akan dihapus.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus',
            cancelButtonText: 'Batal'
        }).then(function(res) {
            if (res.isConfirmed) {
                $('#formHapusId').val(id);
                $('#formHapus').submit();
            }
        });
    });
});
JS
];

if (!empty($message)) {
    $js_page[] = "Swal.fire({ icon: '" . ($message['type'] === 'danger' ? 'error' : $message['type']) . "', title: '" . ($message['type'] === 'success' ? 'Berhasil' : 'Perhatian') . "', text: " . json_encode($message['text']) . ", timer: 2200, showConfirmButton: false });";
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Daftar Tindak Lanjut <?= !empty($wali_kelas_name) ? '- Kelas ' . htmlspecialchars($wali_kelas_name) : '' ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <?php if ($user_level === 'admin'): ?>
            <div class="card mb-3">
                <div class="card-body p-3">
                    <form method="GET" class="form-inline">
                        <label class="mr-2">Pilih Kelas:</label>
                        <select name="kelas" class="form-control" onchange="this.form.submit()">
                            <option value="">-- Semua Kelas --</option>
                            <?php foreach ($all_classes as $c): ?>
                                <option value="<?= (int)$c['id_kelas'] ?>" <?= $selected_kelas_id === (int)$c['id_kelas'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nama_kelas']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filter Card -->
            <div class="card mb-3">
                <div class="card-body p-3">
                    <form method="GET" class="row">
                        <?php if (isset($_GET['kelas'])): ?><input type="hidden" name="kelas" value="<?= (int)$_GET['kelas'] ?>"><?php endif; ?>
                        <div class="col-md-4 mb-2">
                            <label class="small font-weight-bold">Sumber Tindak Lanjut</label>
                            <select name="f_sumber" class="form-control form-control-sm">
                                <option value="">-- Semua Sumber --</option>
                                <?php foreach ($sumber_options as $s): ?>
                                    <option value="<?= $s ?>" <?= $f_sumber === $s ? 'selected' : '' ?>><?= $s ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="small font-weight-bold">Status</label>
                            <select name="f_status" class="form-control form-control-sm">
                                <option value="">-- Semua Status --</option>
                                <?php foreach ($status_options as $st): ?>
                                    <option value="<?= $st ?>" <?= $f_status === $st ? 'selected' : '' ?>><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary btn-sm mr-2"><i class="fas fa-search"></i> Filter</button>
                            <a href="tindak_lanjut.php" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i> Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4>Monitoring Tindak Lanjut Siswa</h4>
                    <button type="button" class="btn btn-primary" id="btnTambahTL" <?= empty($siswa_list) && $user_level !== 'admin' ? 'disabled' : '' ?>>
                        <i class="fas fa-plus mr-1"></i> Rencana Baru
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered table-sm" id="table-tindak-lanjut">
                            <thead>
                                <tr>
                                    <th width="4%">No</th>
                                    <th>Tanggal</th>
                                    <th>Nama Siswa</th>
                                    <th>Sumber</th>
                                    <th>Tindakan</th>
                                    <th>Penanggung Jawab</th>
                                    <th>Target Selesai</th>
                                    <th>Tanggal Selesai</th>
                                    <th>Status</th>
                                    <th width="12%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $i => $r): ?>
                                    <?php
                                    $st_badge = 'secondary';
                                    if ($r['status'] === 'Selesai') $st_badge = 'success';
                                    elseif ($r['status'] === 'Proses') $st_badge = 'warning';
                                    elseif ($r['status'] === 'Rencana') $st_badge = 'primary';
                                    elseif ($r['status'] === 'Dibatalkan') $st_badge = 'danger';
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                                        <td><strong><?= htmlspecialchars($r['nama_siswa']) ?></strong></td>
                                        <td><span class="badge badge-light border"><?= htmlspecialchars($r['sumber']) ?></span></td>
                                        <td><?= htmlspecialchars(mb_strimwidth($r['tindakan'], 0, 40, '...')) ?></td>
                                        <td><?= htmlspecialchars($r['penanggung_jawab']) ?></td>
                                        <td><?= !empty($r['target_selesai']) ? date('d/m/Y', strtotime($r['target_selesai'])) : '-' ?></td>
                                        <td><?= !empty($r['tanggal_selesai']) ? date('d/m/Y', strtotime($r['tanggal_selesai'])) : '-' ?></td>
                                        <td class="text-center"><span class="badge badge-<?= $st_badge ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-info btn-sm btn-detail-tl" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Detail">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" class="btn btn-warning btn-sm btn-edit-tl" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm btn-hapus-tl" data-id="<?= (int)$r['id'] ?>" data-nama="<?= htmlspecialchars($r['nama_siswa'], ENT_QUOTES) ?>" title="Hapus">
                                                <i class="fas fa-trash"></i>
                                            </button>
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

<!-- Modal Form Tambah / Edit -->
<div class="modal fade" id="modalTL" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formTL">
                <input type="hidden" name="action" id="formTLAction" value="tambah">
                <input type="hidden" name="id" id="tlId" value="">
                <input type="hidden" name="id_kelas" value="<?= (int)$selected_kelas_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTLTitle">Tindak Lanjut Siswa</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Siswa <span class="text-danger">*</span></label>
                            <select name="id_siswa" id="inp_siswa" class="form-control" required>
                                <option value="">-- Pilih Siswa --</option>
                                <?php foreach ($siswa_list as $s): ?>
                                    <option value="<?= (int)$s['id_siswa'] ?>">
                                        <?= htmlspecialchars($s['nama_siswa']) ?> (<?= htmlspecialchars($s['nisn'] ?? '-') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Tanggal Rencana</label>
                            <input type="date" name="tanggal" id="inp_tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Sumber Masalah</label>
                            <select name="sumber" id="inp_sumber" class="form-control">
                                <?php foreach ($sumber_options as $sb): ?>
                                    <option value="<?= $sb ?>"><?= $sb ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 form-group">
                            <label>Tindakan / Langkah Perbaikan <span class="text-danger">*</span></label>
                            <textarea name="tindakan" id="inp_tindakan" class="form-control" rows="3" required placeholder="Langkah konkrit yang akan dilakukan..."></textarea>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Penanggung Jawab <span class="text-danger">*</span></label>
                            <input type="text" name="penanggung_jawab" id="inp_pj" class="form-control" required placeholder="Wali Kelas / Guru BK / Orang Tua">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Target Selesai</label>
                            <input type="date" name="target_selesai" id="inp_target" class="form-control">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Tanggal Realisasi Selesai</label>
                            <input type="date" name="tanggal_selesai" id="inp_selesai" class="form-control">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Status</label>
                            <select name="status" id="inp_status" class="form-control">
                                <?php foreach ($status_options as $st): ?>
                                    <option value="<?= $st ?>"><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Detail -->
<div class="modal fade" id="modalDetailTL" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle mr-2"></i>Rincian Tindak Lanjut</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <table class="table table-bordered table-sm">
                    <tr><th width="35%">Tanggal Rencana</th><td id="det_tanggal"></td></tr>
                    <tr><th>Nama Siswa</th><td id="det_nama" class="font-weight-bold"></td></tr>
                    <tr><th>NIS/NISN</th><td id="det_nisn"></td></tr>
                    <tr><th>Kelas</th><td id="det_kelas"></td></tr>
                    <tr><th>Sumber Masalah</th><td id="det_sumber"></td></tr>
                    <tr><th>Penanggung Jawab</th><td id="det_pj"></td></tr>
                    <tr><th>Target Selesai</th><td id="det_target"></td></tr>
                    <tr><th>Tanggal Selesai</th><td id="det_selesai"></td></tr>
                    <tr><th>Status</th><td id="det_status"></td></tr>
                    <tr><th colspan="2">Tindakan:</th></tr>
                    <tr><td colspan="2" id="det_tindakan" style="white-space: pre-wrap;" class="bg-light p-2"></td></tr>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<form method="POST" id="formHapus" class="d-none">
    <input type="hidden" name="action" value="hapus">
    <input type="hidden" name="id" id="formHapusId">
</form>

<?php include '../templates/footer.php'; ?>
