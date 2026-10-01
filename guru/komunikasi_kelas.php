<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['guru', 'wali'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$upload_dir = __DIR__ . '/../uploads/komunikasi/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0755, true);
}

$message = null;

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $tanggal = !empty($_POST['tanggal']) ? date('Y-m-d', strtotime($_POST['tanggal'])) : date('Y-m-d');
        $judul = trim((string)($_POST['judul'] ?? ''));
        $jenis = in_array($_POST['jenis'] ?? '', ['Pengumuman', 'Pesan', 'Diskusi'], true) ? $_POST['jenis'] : 'Pengumuman';
        $id_kelas = (int)($_POST['id_kelas'] ?? 0);
        $isi = trim((string)($_POST['isi'] ?? ''));
        $status = in_array($_POST['status'] ?? '', ['Terkirim', 'Draft', 'Arsip'], true) ? $_POST['status'] : 'Terkirim';

        $lampiran = null;
        if (isset($_FILES['lampiran_file']) && $_FILES['lampiran_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['lampiran_file']['name'], PATHINFO_EXTENSION));
            $filename = 'pesan_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['lampiran_file']['tmp_name'], $upload_dir . $filename)) {
                $lampiran = $filename;
            }
        }

        if ($judul === '' || $id_kelas <= 0 || $isi === '') {
            $message = ['type' => 'warning', 'text' => 'Judul, Kelas, dan Isi Pesan wajib diisi.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_komunikasi_kelas (
                            id_guru, tanggal, judul, jenis, id_kelas, isi, lampiran, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$guru_id, $tanggal, $judul, $jenis, $id_kelas, $isi, $lampiran, $status]);
                    $message = ['type' => 'success', 'text' => 'Komunikasi kelas berhasil dipublikasikan.'];
                } else {
                    $sql = "
                        UPDATE tb_komunikasi_kelas SET
                            tanggal = ?, judul = ?, jenis = ?, id_kelas = ?, isi = ?, status = ?
                    ";
                    $params = [$tanggal, $judul, $jenis, $id_kelas, $isi, $status];
                    if ($lampiran !== null) {
                        $sql .= ", lampiran = ?";
                        $params[] = $lampiran;
                    }
                    $sql .= " WHERE id = ? AND id_guru = ?";
                    $params[] = $id;
                    $params[] = $guru_id;
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $message = ['type' => 'success', 'text' => 'Komunikasi kelas berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT lampiran FROM tb_komunikasi_kelas WHERE id = ? AND id_guru = ?");
            $stmt->execute([$id, $guru_id]);
            $old = $stmt->fetchColumn();
            if ($old && is_file($upload_dir . $old)) {
                @unlink($upload_dir . $old);
            }
            $pdo->prepare("DELETE FROM tb_komunikasi_kelas WHERE id = ? AND id_guru = ?")->execute([$id, $guru_id]);
            $pdo->prepare("DELETE FROM tb_komunikasi_kelas_read WHERE id_komunikasi = ?")->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Pesan komunikasi berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
}

// Master lists
$kelas_list = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
$jenis_options = ['Pengumuman', 'Pesan', 'Diskusi'];

// Filters
$f_kelas = (int)($_GET['f_kelas'] ?? 0);
$f_jenis = trim((string)($_GET['f_jenis'] ?? ''));
$f_status = trim((string)($_GET['f_status'] ?? ''));

$where = ["k.id_guru = ?"];
$params = [$guru_id];

if ($f_kelas > 0) {
    $where[] = "k.id_kelas = ?";
    $params[] = $f_kelas;
}
if ($f_jenis !== '') {
    $where[] = "k.jenis = ?";
    $params[] = $f_jenis;
}
if ($f_status !== '') {
    $where[] = "k.status = ?";
    $params[] = $f_status;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT k.*, c.nama_kelas, g.nama_guru,
           (SELECT COUNT(*) FROM tb_siswa s WHERE s.id_kelas = k.id_kelas) AS total_penerima,
           (SELECT COUNT(*) FROM tb_komunikasi_kelas_read kr WHERE kr.id_komunikasi = k.id) AS total_dibaca
    FROM tb_komunikasi_kelas k
    LEFT JOIN tb_kelas c ON c.id_kelas = k.id_kelas
    LEFT JOIN tb_guru g ON g.id_guru = k.id_guru
    WHERE $where_sql
    ORDER BY k.tanggal DESC, k.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Komunikasi Kelas';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-komunikasi').length) {
        $('#table-komunikasi').DataTable({
            'order': [[1, 'desc']],
            'columnDefs': [{ 'sortable': false, 'targets': [6, 9] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada data komunikasi',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    $('#btnTambahPesan').on('click', function() {
        $('#formKomunikasiAction').val('tambah');
        $('#komunikasiId').val('');
        $('#modalKomunikasiTitle').text('Buat Pengumuman / Pesan Kelas Baru');
        $('#formKomunikasi')[0].reset();
        $('#modalKomunikasi').modal('show');
    });

    $(document).on('click', '.btn-edit-pesan', function() {
        var data = $(this).data('json');
        $('#formKomunikasiAction').val('edit');
        $('#komunikasiId').val(data.id);
        $('#modalKomunikasiTitle').text('Edit Komunikasi Kelas');
        $('#inp_tanggal').val(data.tanggal);
        $('#inp_judul').val(data.judul);
        $('#inp_jenis').val(data.jenis);
        $('#inp_kelas').val(data.id_kelas);
        $('#inp_isi').val(data.isi);
        $('#inp_status').val(data.status);
        $('#modalKomunikasi').modal('show');
    });

    $(document).on('click', '.btn-detail-pesan', function() {
        var data = $(this).data('json');
        $('#det_tanggal').text(data.tanggal);
        $('#det_judul').text(data.judul);
        $('#det_jenis').html('<span class="badge badge-info">' + data.jenis + '</span>');
        $('#det_kelas').text(data.nama_kelas || '-');
        $('#det_penerima').text(data.total_penerima + ' Siswa');
        $('#det_dibaca').html('<span class="badge badge-success">' + data.total_dibaca + '/' + data.total_penerima + ' Siswa</span>');
        $('#det_status').text(data.status);
        $('#det_isi').text(data.isi);

        if (data.lampiran) {
            $('#det_lampiran').html('<a href="../uploads/komunikasi/' + encodeURIComponent(data.lampiran) + '" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-paperclip mr-1"></i>Unduh Lampiran (' + data.lampiran.split('.').pop().toUpperCase() + ')</a>');
        } else {
            $('#det_lampiran').html('<span class="text-muted">Tidak ada lampiran</span>');
        }

        $('#modalDetailPesan').modal('show');
    });

    $(document).on('click', '.btn-hapus-pesan', function() {
        var id = $(this).data('id');
        var judul = $(this).data('judul');
        Swal.fire({
            title: 'Hapus Pesan?',
            text: 'Pesan "' + judul + '" akan dihapus permanen.',
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
            <h1>Daftar Komunikasi Kelas</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <!-- Filter Card -->
            <div class="card mb-3">
                <div class="card-header">
                    <h4><i class="fas fa-filter mr-2"></i>Filter Komunikasi</h4>
                </div>
                <div class="card-body">
                    <form method="GET" class="row">
                        <div class="col-md-4 mb-2">
                            <label class="small font-weight-bold">Kelas</label>
                            <select name="f_kelas" class="form-control form-control-sm">
                                <option value="">-- Semua Kelas --</option>
                                <?php foreach ($kelas_list as $k): ?>
                                    <option value="<?= (int)$k['id_kelas'] ?>" <?= $f_kelas === (int)$k['id_kelas'] ? 'selected' : '' ?>><?= htmlspecialchars($k['nama_kelas']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2">
                            <label class="small font-weight-bold">Jenis</label>
                            <select name="f_jenis" class="form-control form-control-sm">
                                <option value="">-- Semua Jenis --</option>
                                <?php foreach ($jenis_options as $j): ?>
                                    <option value="<?= $j ?>" <?= $f_jenis === $j ? 'selected' : '' ?>><?= $j ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2">
                            <label class="small font-weight-bold">Status</label>
                            <select name="f_status" class="form-control form-control-sm">
                                <option value="">-- Semua --</option>
                                <?php foreach (['Terkirim', 'Draft', 'Arsip'] as $st): ?>
                                    <option value="<?= $st ?>" <?= $f_status === $st ? 'selected' : '' ?>><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mt-2 d-flex">
                            <button type="submit" class="btn btn-primary btn-sm mr-2"><i class="fas fa-search"></i> Terapkan Filter</button>
                            <a href="komunikasi_kelas.php" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i> Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Table Card -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4>Papan Pengumuman & Komunikasi Siswa</h4>
                    <button type="button" class="btn btn-primary" id="btnTambahPesan">
                        <i class="fas fa-plus mr-1"></i> Buat Pesan / Pengumuman
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered table-sm" id="table-komunikasi">
                            <thead>
                                <tr>
                                    <th width="4%">No</th>
                                    <th>Tanggal</th>
                                    <th>Judul</th>
                                    <th>Jenis</th>
                                    <th>Kelas</th>
                                    <th>Penerima</th>
                                    <th width="6%">Lampiran</th>
                                    <th>Status</th>
                                    <th>Dibaca</th>
                                    <th width="12%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $i => $r): ?>
                                    <?php
                                    $tot_penerima = (int)$r['total_penerima'];
                                    $tot_baca = (int)$r['total_dibaca'];
                                    $st_badge = $r['status'] === 'Terkirim' ? 'success' : ($r['status'] === 'Draft' ? 'warning' : 'secondary');
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                                        <td><strong><?= htmlspecialchars($r['judul']) ?></strong></td>
                                        <td><span class="badge badge-light border"><?= htmlspecialchars($r['jenis']) ?></span></td>
                                        <td><?= htmlspecialchars($r['nama_kelas'] ?? '-') ?></td>
                                        <td><?= $tot_penerima ?> penerima</td>
                                        <td class="text-center">
                                            <?php if (!empty($r['lampiran'])): ?>
                                                <a href="../uploads/komunikasi/<?= htmlspecialchars($r['lampiran']) ?>" target="_blank" class="text-primary font-weight-bold" title="Lampiran">
                                                    <i class="fas fa-paperclip"></i>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><span class="badge badge-<?= $st_badge ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                        <td class="text-center">
                                            <span class="badge badge-<?= $tot_baca >= $tot_penerima && $tot_penerima > 0 ? 'success' : ($tot_baca > 0 ? 'info' : 'secondary') ?>">
                                                <?= $tot_baca ?>/<?= $tot_penerima ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-info btn-sm btn-detail-pesan" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Detail">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" class="btn btn-warning btn-sm btn-edit-pesan" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm btn-hapus-pesan" data-id="<?= (int)$r['id'] ?>" data-judul="<?= htmlspecialchars($r['judul'], ENT_QUOTES) ?>" title="Hapus">
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

<!-- Modal Form Tambah / Edit Komunikasi -->
<div class="modal fade" id="modalKomunikasi" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formKomunikasi" enctype="multipart/form-data">
                <input type="hidden" name="action" id="formKomunikasiAction" value="tambah">
                <input type="hidden" name="id" id="komunikasiId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalKomunikasiTitle">Buat Pengumuman / Pesan Baru</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-8 form-group">
                            <label>Judul Pesan / Pengumuman <span class="text-danger">*</span></label>
                            <input type="text" name="judul" id="inp_judul" class="form-control" required placeholder="Contoh: Pengumuman Jadwal Ulangan Harian">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Jenis</label>
                            <select name="jenis" id="inp_jenis" class="form-control">
                                <?php foreach ($jenis_options as $j): ?>
                                    <option value="<?= $j ?>"><?= $j ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Kelas Penerima <span class="text-danger">*</span></label>
                            <select name="id_kelas" id="inp_kelas" class="form-control" required>
                                <option value="">-- Pilih Kelas --</option>
                                <?php foreach ($kelas_list as $k): ?>
                                    <option value="<?= (int)$k['id_kelas'] ?>"><?= htmlspecialchars($k['nama_kelas']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Tanggal</label>
                            <input type="date" name="tanggal" id="inp_tanggal" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Status</label>
                            <select name="status" id="inp_status" class="form-control">
                                <option value="Terkirim">Terkirim</option>
                                <option value="Draft">Draft</option>
                                <option value="Arsip">Arsip</option>
                            </select>
                        </div>
                        <div class="col-12 form-group">
                            <label>Isi Pesan / Pengumuman <span class="text-danger">*</span></label>
                            <textarea name="isi" id="inp_isi" class="form-control" rows="4" required placeholder="Tuliskan isi pengumuman atau instruksi kelas..."></textarea>
                        </div>
                        <div class="col-12 form-group">
                            <label>Unggah Lampiran (Opsional)</label>
                            <input type="file" name="lampiran_file" class="form-control-file">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane mr-1"></i> Simpan & Publikasikan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Detail Pesan -->
<div class="modal fade" id="modalDetailPesan" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle mr-2"></i>Rincian Komunikasi Kelas</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <table class="table table-bordered table-sm">
                    <tr><th width="30%">Tanggal</th><td id="det_tanggal"></td></tr>
                    <tr><th>Judul</th><td id="det_judul" class="font-weight-bold"></td></tr>
                    <tr><th>Jenis</th><td id="det_jenis"></td></tr>
                    <tr><th>Kelas</th><td id="det_kelas"></td></tr>
                    <tr><th>Jumlah Penerima</th><td id="det_penerima"></td></tr>
                    <tr><th>Status Dibaca</th><td id="det_dibaca"></td></tr>
                    <tr><th>Status</th><td id="det_status"></td></tr>
                    <tr><th>Lampiran</th><td id="det_lampiran"></td></tr>
                    <tr><th colspan="2">Isi Pesan:</th></tr>
                    <tr><td colspan="2" id="det_isi" style="white-space: pre-wrap;" class="bg-light p-3"></td></tr>
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
