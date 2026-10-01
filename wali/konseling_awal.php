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
        $topik = trim((string)($_POST['topik'] ?? ''));
        $ringkasan_masalah = trim((string)($_POST['ringkasan_masalah'] ?? ''));
        $tindak_lanjut = trim((string)($_POST['tindak_lanjut'] ?? ''));
        $status = in_array($_POST['status'] ?? '', ['Terbuka', 'Proses', 'Selesai'], true) ? $_POST['status'] : 'Terbuka';
        $follow_up = trim((string)($_POST['follow_up'] ?? ''));

        if ($id_siswa <= 0 || $topik === '' || $ringkasan_masalah === '') {
            $message = ['type' => 'warning', 'text' => 'Pilih Siswa, isi Topik, dan Ringkasan Masalah.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_konseling_awal (
                            id_wali, id_siswa, id_kelas, tanggal, topik,
                            ringkasan_masalah, tindak_lanjut, status, follow_up
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $guru_id, $id_siswa, $id_kelas, $tanggal, $topik,
                        $ringkasan_masalah, $tindak_lanjut, $status, $follow_up
                    ]);
                    $message = ['type' => 'success', 'text' => 'Data konseling awal berhasil disimpan.'];
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE tb_konseling_awal SET
                            id_siswa = ?, id_kelas = ?, tanggal = ?, topik = ?,
                            ringkasan_masalah = ?, tindak_lanjut = ?, status = ?, follow_up = ?
                        WHERE id = ? " . ($user_level !== 'admin' ? "AND id_wali = $guru_id" : "") . "
                    ");
                    $stmt->execute([
                        $id_siswa, $id_kelas, $tanggal, $topik,
                        $ringkasan_masalah, $tindak_lanjut, $status, $follow_up, $id
                    ]);
                    $message = ['type' => 'success', 'text' => 'Data konseling berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM tb_konseling_awal WHERE id = ? " . ($user_level !== 'admin' ? "AND id_wali = $guru_id" : ""))->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Data konseling berhasil dihapus.'];
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

$where = ["1=1"];
$params = [];
if ($selected_kelas_id > 0) {
    $where[] = "k.id_kelas = ?";
    $params[] = $selected_kelas_id;
}
if ($user_level !== 'admin') {
    $where[] = "k.id_wali = ?";
    $params[] = $guru_id;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT k.*, s.nama_siswa, s.nisn, c.nama_kelas
    FROM tb_konseling_awal k
    JOIN tb_siswa s ON s.id_siswa = k.id_siswa
    LEFT JOIN tb_kelas c ON c.id_kelas = k.id_kelas
    WHERE $where_sql
    ORDER BY k.tanggal DESC, k.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$status_options = ['Terbuka', 'Proses', 'Selesai'];

$page_title = 'Daftar Konseling Awal';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-konseling').length) {
        $('#table-konseling').DataTable({
            'order': [[1, 'desc']],
            'columnDefs': [{ 'sortable': false, 'targets': [10] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada data konseling',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    $('#btnTambahKonseling').on('click', function() {
        $('#formKonselingAction').val('tambah');
        $('#konselingId').val('');
        $('#modalKonselingTitle').text('Catat Sesi Konseling Awal');
        $('#formKonseling')[0].reset();
        $('#modalKonseling').modal('show');
    });

    $(document).on('click', '.btn-edit-konseling', function() {
        var data = $(this).data('json');
        $('#formKonselingAction').val('edit');
        $('#konselingId').val(data.id);
        $('#modalKonselingTitle').text('Edit Konseling Awal');
        $('#inp_siswa').val(data.id_siswa);
        $('#inp_tanggal').val(data.tanggal);
        $('#inp_topik').val(data.topik);
        $('#inp_masalah').val(data.ringkasan_masalah);
        $('#inp_tindak_lanjut').val(data.tindak_lanjut || '');
        $('#inp_follow_up').val(data.follow_up || '');
        $('#inp_status').val(data.status);
        $('#modalKonseling').modal('show');
    });

    $(document).on('click', '.btn-detail-konseling', function() {
        var data = $(this).data('json');
        $('#det_tanggal').text(data.tanggal);
        $('#det_nama').text(data.nama_siswa);
        $('#det_nisn').text(data.nisn || '-');
        $('#det_kelas').text(data.nama_kelas || '-');
        $('#det_topik').text(data.topik);
        $('#det_status').text(data.status);
        $('#det_masalah').text(data.ringkasan_masalah);
        $('#det_tindak_lanjut').text(data.tindak_lanjut || '-');
        $('#det_follow_up').text(data.follow_up || '-');
        $('#modalDetailKonseling').modal('show');
    });

    $(document).on('click', '.btn-hapus-konseling', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        Swal.fire({
            title: 'Hapus Sesi Konseling?',
            text: 'Catatan konseling untuk ' + nama + ' akan dihapus.',
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
            <h1>Daftar Konseling Awal <?= !empty($wali_kelas_name) ? '- Kelas ' . htmlspecialchars($wali_kelas_name) : '' ?></h1>
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
                    <h4>Catatan Konseling Pribadi & Wawancara Awal</h4>
                    <button type="button" class="btn btn-primary" id="btnTambahKonseling" <?= empty($siswa_list) && $user_level !== 'admin' ? 'disabled' : '' ?>>
                        <i class="fas fa-plus mr-1"></i> Sesi Konseling Baru
                    </button>
                </div>
                <div class="card-body">
                    <div class="alert alert-light border small text-muted mb-3">
                        <i class="fas fa-shield-alt mr-1 text-primary"></i> Data konseling bersifat rahasia dan terlindungi. Tabel hanya menampilkan ringkasan singkat; rincian sensitif dapat dibuka melalui tombol Detail.
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered table-sm" id="table-konseling">
                            <thead>
                                <tr>
                                    <th width="4%">No</th>
                                    <th>Tanggal</th>
                                    <th>Nama Siswa</th>
                                    <th>NIS/NISN</th>
                                    <th>Kelas</th>
                                    <th>Topik</th>
                                    <th width="20%">Ringkasan Masalah</th>
                                    <th>Tindak Lanjut</th>
                                    <th>Status</th>
                                    <th>Follow Up</th>
                                    <th width="12%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $i => $r): ?>
                                    <?php
                                    $st_badge = $r['status'] === 'Selesai' ? 'success' : ($r['status'] === 'Proses' ? 'warning' : 'primary');
                                    $raw_m = strip_tags($r['ringkasan_masalah']);
                                    $m_cut = mb_strlen($raw_m) > 35 ? mb_substr($raw_m, 0, 35) . '...' : $raw_m;
                                    $raw_tl = strip_tags($r['tindak_lanjut'] ?? '');
                                    $tl_cut = mb_strlen($raw_tl) > 30 ? mb_substr($raw_tl, 0, 30) . '...' : ($raw_tl ?: '-');
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                                        <td><strong><?= htmlspecialchars($r['nama_siswa']) ?></strong></td>
                                        <td><?= htmlspecialchars($r['nisn'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['nama_kelas'] ?? '-') ?></td>
                                        <td><span class="badge badge-light border"><?= htmlspecialchars($r['topik']) ?></span></td>
                                        <td><span title="<?= htmlspecialchars($raw_m) ?>"><?= htmlspecialchars($m_cut) ?></span></td>
                                        <td><small class="text-success"><?= htmlspecialchars($tl_cut) ?></small></td>
                                        <td class="text-center"><span class="badge badge-<?= $st_badge ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                        <td><small><?= htmlspecialchars(mb_strimwidth($r['follow_up'] ?? '-', 0, 25, '...')) ?></small></td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-info btn-sm btn-detail-konseling" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Detail Rahasia">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" class="btn btn-warning btn-sm btn-edit-konseling" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm btn-hapus-konseling" data-id="<?= (int)$r['id'] ?>" data-nama="<?= htmlspecialchars($r['nama_siswa'], ENT_QUOTES) ?>" title="Hapus">
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
<div class="modal fade" id="modalKonseling" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formKonseling">
                <input type="hidden" name="action" id="formKonselingAction" value="tambah">
                <input type="hidden" name="id" id="konselingId" value="">
                <input type="hidden" name="id_kelas" value="<?= (int)$selected_kelas_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalKonselingTitle">Sesi Konseling Awal Siswa</h5>
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
                            <label>Status</label>
                            <select name="status" id="inp_status" class="form-control">
                                <?php foreach ($status_options as $st): ?>
                                    <option value="<?= $st ?>"><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 form-group">
                            <label>Topik / Fokus Konseling <span class="text-danger">*</span></label>
                            <input type="text" name="topik" id="inp_topik" class="form-control" required placeholder="Contoh: Kesulitan penyesuaian sosial di kelas / motivasi belajar">
                        </div>
                        <div class="col-12 form-group">
                            <label>Ringkasan Masalah / Hasil Konseling <span class="text-danger">*</span></label>
                            <textarea name="ringkasan_masalah" id="inp_masalah" class="form-control" rows="3" required placeholder="Catatan percakapan atau hal yang dikeluhkan siswa..."></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Tindak Lanjut / Solusi yang Disepakati</label>
                            <textarea name="tindak_lanjut" id="inp_tindak_lanjut" class="form-control" rows="2" placeholder="Komitmen atau langkah penanganan..."></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Rencana Follow Up</label>
                            <textarea name="follow_up" id="inp_follow_up" class="form-control" rows="2" placeholder="Jadwal pertemuan berikutnya / pengamatan guru..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan Konseling</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Detail Rahasia -->
<div class="modal fade" id="modalDetailKonseling" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-shield mr-2"></i>Rincian Rahasia Konseling Awal</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <table class="table table-bordered table-sm">
                    <tr><th width="30%">Tanggal</th><td id="det_tanggal"></td></tr>
                    <tr><th>Nama Siswa</th><td id="det_nama" class="font-weight-bold"></td></tr>
                    <tr><th>NIS/NISN</th><td id="det_nisn"></td></tr>
                    <tr><th>Kelas</th><td id="det_kelas"></td></tr>
                    <tr><th>Topik</th><td id="det_topik" class="font-weight-bold text-primary"></td></tr>
                    <tr><th>Status</th><td id="det_status"></td></tr>
                    <tr><th colspan="2">Ringkasan Masalah / Hasil Diskusi:</th></tr>
                    <tr><td colspan="2" id="det_masalah" style="white-space: pre-wrap;" class="bg-light p-2"></td></tr>
                    <tr><th colspan="2">Tindak Lanjut yang Disepakati:</th></tr>
                    <tr><td colspan="2" id="det_tindak_lanjut" style="white-space: pre-wrap;" class="bg-light p-2 text-success"></td></tr>
                    <tr><th colspan="2">Rencana Follow Up:</th></tr>
                    <tr><td colspan="2" id="det_follow_up" style="white-space: pre-wrap;" class="bg-light p-2 text-info"></td></tr>
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
