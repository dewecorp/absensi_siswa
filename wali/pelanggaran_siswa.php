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

// Deteksi kelas wali
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
        $jenis_pelanggaran = trim((string)($_POST['jenis_pelanggaran'] ?? ''));
        $kategori = trim((string)($_POST['kategori'] ?? 'Ringan'));
        $poin = (int)($_POST['poin'] ?? 0);
        $tindakan = trim((string)($_POST['tindakan'] ?? ''));
        $orang_tua = trim((string)($_POST['orang_tua'] ?? 'Belum Dipanggil'));
        $status = in_array($_POST['status'] ?? '', ['Dicatat', 'Ditindaklanjuti', 'Selesai'], true) ? $_POST['status'] : 'Dicatat';

        if ($id_siswa <= 0 || $jenis_pelanggaran === '') {
            $message = ['type' => 'warning', 'text' => 'Pilih Siswa dan isi Jenis Pelanggaran.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_pelanggaran_siswa (
                            id_wali, id_siswa, id_kelas, tanggal, jenis_pelanggaran,
                            kategori, poin, tindakan, orang_tua, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $guru_id, $id_siswa, $id_kelas, $tanggal, $jenis_pelanggaran,
                        $kategori, $poin, $tindakan, $orang_tua, $status
                    ]);
                    $message = ['type' => 'success', 'text' => 'Pelanggaran siswa berhasil dicatat.'];
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE tb_pelanggaran_siswa SET
                            id_siswa = ?, id_kelas = ?, tanggal = ?, jenis_pelanggaran = ?,
                            kategori = ?, poin = ?, tindakan = ?, orang_tua = ?, status = ?
                        WHERE id = ? " . ($user_level !== 'admin' ? "AND id_wali = $guru_id" : "") . "
                    ");
                    $stmt->execute([
                        $id_siswa, $id_kelas, $tanggal, $jenis_pelanggaran,
                        $kategori, $poin, $tindakan, $orang_tua, $status, $id
                    ]);
                    $message = ['type' => 'success', 'text' => 'Data pelanggaran berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM tb_pelanggaran_siswa WHERE id = ? " . ($user_level !== 'admin' ? "AND id_wali = $guru_id" : ""))->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Data pelanggaran berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
}

// Siswa kelas ini
$siswa_list = [];
if ($selected_kelas_id > 0) {
    $stS = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
    $stS->execute([$selected_kelas_id]);
    $siswa_list = $stS->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch rows pelanggaran
$where = ["1=1"];
$params = [];
if ($selected_kelas_id > 0) {
    $where[] = "p.id_kelas = ?";
    $params[] = $selected_kelas_id;
}
if ($user_level !== 'admin') {
    $where[] = "p.id_wali = ?";
    $params[] = $guru_id;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT p.*, s.nama_siswa, s.nisn, k.nama_kelas
    FROM tb_pelanggaran_siswa p
    JOIN tb_siswa s ON s.id_siswa = p.id_siswa
    LEFT JOIN tb_kelas k ON k.id_kelas = p.id_kelas
    WHERE $where_sql
    ORDER BY p.tanggal DESC, p.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$kategori_options = ['Ringan', 'Sedang', 'Berat'];
$status_options = ['Dicatat', 'Ditindaklanjuti', 'Selesai'];

$page_title = 'Daftar Pelanggaran Siswa';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-pelanggaran').length) {
        $('#table-pelanggaran').DataTable({
            'order': [[1, 'desc']],
            'columnDefs': [{ 'sortable': false, 'targets': [10] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada catatan pelanggaran',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    $('#btnTambahPelanggaran').on('click', function() {
        $('#formPelanggaranAction').val('tambah');
        $('#pelanggaranId').val('');
        $('#modalPelanggaranTitle').text('Catat Pelanggaran Siswa');
        $('#formPelanggaran')[0].reset();
        $('#modalPelanggaran').modal('show');
    });

    $(document).on('click', '.btn-edit-pelanggaran', function() {
        var data = $(this).data('json');
        $('#formPelanggaranAction').val('edit');
        $('#pelanggaranId').val(data.id);
        $('#modalPelanggaranTitle').text('Edit Pelanggaran Siswa');
        $('#inp_siswa').val(data.id_siswa);
        $('#inp_tanggal').val(data.tanggal);
        $('#inp_jenis').val(data.jenis_pelanggaran);
        $('#inp_kategori').val(data.kategori || 'Ringan');
        $('#inp_poin').val(data.poin || 0);
        $('#inp_tindakan').val(data.tindakan || '');
        $('#inp_ortu').val(data.orang_tua || '');
        $('#inp_status').val(data.status);
        $('#modalPelanggaran').modal('show');
    });

    $(document).on('click', '.btn-detail-pelanggaran', function() {
        var data = $(this).data('json');
        $('#det_tanggal').text(data.tanggal);
        $('#det_nama').text(data.nama_siswa);
        $('#det_nisn').text(data.nisn || '-');
        $('#det_kelas').text(data.nama_kelas || '-');
        $('#det_jenis').text(data.jenis_pelanggaran);
        $('#det_kategori').html('<span class="badge badge-' + (data.kategori === 'Berat' ? 'danger' : (data.kategori === 'Sedang' ? 'warning' : 'info')) + '">' + data.kategori + '</span>');
        $('#det_poin').text(data.poin);
        $('#det_status').text(data.status);
        $('#det_tindakan').text(data.tindakan || '-');
        $('#det_ortu').text(data.orang_tua || '-');
        $('#modalDetailPelanggaran').modal('show');
    });

    $(document).on('click', '.btn-hapus-pelanggaran', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        Swal.fire({
            title: 'Hapus Pelanggaran?',
            text: 'Data pelanggaran untuk ' + nama + ' akan dihapus.',
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
            <h1>Daftar Pelanggaran Siswa <?= !empty($wali_kelas_name) ? '- Kelas ' . htmlspecialchars($wali_kelas_name) : '' ?></h1>
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

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4>Catatan Kedisiplinan & Pelanggaran Tata Tertib</h4>
                    <button type="button" class="btn btn-primary" id="btnTambahPelanggaran" <?= empty($siswa_list) && $user_level !== 'admin' ? 'disabled' : '' ?>>
                        <i class="fas fa-plus mr-1"></i> Catat Pelanggaran
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered table-sm" id="table-pelanggaran">
                            <thead>
                                <tr>
                                    <th width="4%">No</th>
                                    <th>Tanggal</th>
                                    <th>Nama Siswa</th>
                                    <th>NIS/NISN</th>
                                    <th>Jenis Pelanggaran</th>
                                    <th>Kategori</th>
                                    <th width="6%">Poin</th>
                                    <th>Tindakan</th>
                                    <th>Orang Tua</th>
                                    <th>Status</th>
                                    <th width="12%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $i => $r): ?>
                                    <?php
                                    $st_badge = $r['status'] === 'Selesai' ? 'success' : ($r['status'] === 'Ditindaklanjuti' ? 'warning' : 'danger');
                                    $kat_badge = $r['kategori'] === 'Berat' ? 'danger' : ($r['kategori'] === 'Sedang' ? 'warning' : 'info');
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                                        <td><strong><?= htmlspecialchars($r['nama_siswa']) ?></strong></td>
                                        <td><?= htmlspecialchars($r['nisn'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['jenis_pelanggaran']) ?></td>
                                        <td class="text-center"><span class="badge badge-<?= $kat_badge ?>"><?= htmlspecialchars($r['kategori']) ?></span></td>
                                        <td class="text-center font-weight-bold text-danger"><?= (int)$r['poin'] ?></td>
                                        <td><?= htmlspecialchars(mb_strimwidth($r['tindakan'], 0, 35, '...')) ?></td>
                                        <td><small><?= htmlspecialchars($r['orang_tua'] ?: '-') ?></small></td>
                                        <td class="text-center"><span class="badge badge-<?= $st_badge ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-info btn-sm btn-detail-pelanggaran" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Detail">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" class="btn btn-warning btn-sm btn-edit-pelanggaran" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm btn-hapus-pelanggaran" data-id="<?= (int)$r['id'] ?>" data-nama="<?= htmlspecialchars($r['nama_siswa'], ENT_QUOTES) ?>" title="Hapus">
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
<div class="modal fade" id="modalPelanggaran" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formPelanggaran">
                <input type="hidden" name="action" id="formPelanggaranAction" value="tambah">
                <input type="hidden" name="id" id="pelanggaranId" value="">
                <input type="hidden" name="id_kelas" value="<?= (int)$selected_kelas_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalPelanggaranTitle">Catat Pelanggaran Siswa</h5>
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
                            <label>Tanggal</label>
                            <input type="date" name="tanggal" id="inp_tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Kategori</label>
                            <select name="kategori" id="inp_kategori" class="form-control">
                                <?php foreach ($kategori_options as $k): ?>
                                    <option value="<?= $k ?>"><?= $k ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8 form-group">
                            <label>Jenis Pelanggaran <span class="text-danger">*</span></label>
                            <input type="text" name="jenis_pelanggaran" id="inp_jenis" class="form-control" required placeholder="Contoh: Datang terlambat lebih dari 15 menit">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Poin Pelanggaran</label>
                            <input type="number" name="poin" id="inp_poin" class="form-control" value="5" min="0">
                        </div>
                        <div class="col-12 form-group">
                            <label>Tindakan / Sanksi yang Diberikan</label>
                            <textarea name="tindakan" id="inp_tindakan" class="form-control" rows="2" placeholder="Contoh: Peringatan lisan dan tugas kebersihan"></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Keterangan Orang Tua</label>
                            <input type="text" name="orang_tua" id="inp_ortu" class="form-control" placeholder="Contoh: Surat pemberitahuan terkirim">
                        </div>
                        <div class="col-md-6 form-group">
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
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan Catatan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Detail -->
<div class="modal fade" id="modalDetailPelanggaran" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle mr-2"></i>Rincian Pelanggaran Siswa</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <table class="table table-bordered table-sm">
                    <tr><th width="30%">Tanggal</th><td id="det_tanggal"></td></tr>
                    <tr><th>Nama Siswa</th><td id="det_nama" class="font-weight-bold"></td></tr>
                    <tr><th>NIS/NISN</th><td id="det_nisn"></td></tr>
                    <tr><th>Kelas</th><td id="det_kelas"></td></tr>
                    <tr><th>Jenis Pelanggaran</th><td id="det_jenis" class="text-danger font-weight-bold"></td></tr>
                    <tr><th>Kategori</th><td id="det_kategori"></td></tr>
                    <tr><th>Poin</th><td id="det_poin" class="font-weight-bold text-danger"></td></tr>
                    <tr><th>Status</th><td id="det_status"></td></tr>
                    <tr><th>Orang Tua</th><td id="det_ortu"></td></tr>
                    <tr><th colspan="2">Tindakan / Sanksi:</th></tr>
                    <tr><td colspan="2" id="det_tindakan" style="white-space: pre-wrap;" class="bg-light p-2 text-dark"></td></tr>
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
