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

$school_profile = getSchoolProfile($pdo);
$tahun_ajaran_aktif = $school_profile['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1);
$semester_aktif = $school_profile['semester'] ?? 'Semester 1';

$upload_dir = __DIR__ . '/../uploads/perangkat/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0755, true);
}

$message = null;

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $jenis_perangkat = trim((string)($_POST['jenis_perangkat'] ?? ''));
        $judul = trim((string)($_POST['judul'] ?? ''));
        $id_mapel = (int)($_POST['id_mapel'] ?? 0) ?: null;
        $id_kelas = (int)($_POST['id_kelas'] ?? 0) ?: null;
        $materi_tp = trim((string)($_POST['materi_tp'] ?? ''));
        $semester = trim((string)($_POST['semester'] ?? $semester_aktif));
        $tahun_ajaran = trim((string)($_POST['tahun_ajaran'] ?? $tahun_ajaran_aktif));
        $status = in_array($_POST['status'] ?? '', ['Draft', 'Aktif', 'Arsip'], true) ? $_POST['status'] : 'Aktif';
        $cp = trim((string)($_POST['cp'] ?? ''));
        $tp = trim((string)($_POST['tp'] ?? ''));
        $materi = trim((string)($_POST['materi'] ?? ''));
        $tujuan_pembelajaran = trim((string)($_POST['tujuan_pembelajaran'] ?? ''));
        $indikator = trim((string)($_POST['indikator'] ?? ''));
        $deskripsi = trim((string)($_POST['deskripsi'] ?? ''));

        $file_path = null;
        if (isset($_FILES['file_perangkat']) && $_FILES['file_perangkat']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['file_perangkat']['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip'];
            if (in_array($ext, $allowed, true)) {
                $filename = 'perangkat_' . time() . '_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['file_perangkat']['tmp_name'], $upload_dir . $filename)) {
                    $file_path = $filename;
                }
            }
        }

        if ($judul === '' || $jenis_perangkat === '') {
            $message = ['type' => 'warning', 'text' => 'Judul dan Jenis Perangkat wajib diisi.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_perangkat_pembelajaran (
                            id_guru, jenis_perangkat, judul, id_mapel, id_kelas, materi_tp,
                            semester, tahun_ajaran, file_path, status, cp, tp, materi,
                            tujuan_pembelajaran, indikator, deskripsi
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $guru_id, $jenis_perangkat, $judul, $id_mapel, $id_kelas, $materi_tp,
                        $semester, $tahun_ajaran, $file_path, $status, $cp, $tp, $materi,
                        $tujuan_pembelajaran, $indikator, $deskripsi
                    ]);
                    $message = ['type' => 'success', 'text' => 'Perangkat pembelajaran berhasil ditambahkan.'];
                } else {
                    $sql = "
                        UPDATE tb_perangkat_pembelajaran SET
                            jenis_perangkat = ?, judul = ?, id_mapel = ?, id_kelas = ?,
                            materi_tp = ?, semester = ?, tahun_ajaran = ?, status = ?,
                            cp = ?, tp = ?, materi = ?, tujuan_pembelajaran = ?,
                            indikator = ?, deskripsi = ?
                    ";
                    $params = [
                        $jenis_perangkat, $judul, $id_mapel, $id_kelas, $materi_tp,
                        $semester, $tahun_ajaran, $status, $cp, $tp, $materi,
                        $tujuan_pembelajaran, $indikator, $deskripsi
                    ];
                    if ($file_path !== null) {
                        $sql .= ", file_path = ?";
                        $params[] = $file_path;
                    }
                    $sql .= " WHERE id = ? AND id_guru = ?";
                    $params[] = $id;
                    $params[] = $guru_id;
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $message = ['type' => 'success', 'text' => 'Perangkat pembelajaran berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'arsip') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("UPDATE tb_perangkat_pembelajaran SET status = 'Arsip' WHERE id = ? AND id_guru = ?");
            $stmt->execute([$id, $guru_id]);
            $message = ['type' => 'success', 'text' => 'Perangkat berhasil diarsipkan.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal mengarsipkan: ' . $e->getMessage()];
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT file_path FROM tb_perangkat_pembelajaran WHERE id = ? AND id_guru = ?");
            $stmt->execute([$id, $guru_id]);
            $old_file = $stmt->fetchColumn();
            if ($old_file && is_file($upload_dir . $old_file)) {
                @unlink($upload_dir . $old_file);
            }
            $del = $pdo->prepare("DELETE FROM tb_perangkat_pembelajaran WHERE id = ? AND id_guru = ?");
            $del->execute([$id, $guru_id]);
            $message = ['type' => 'success', 'text' => 'Perangkat berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
}

// Master lists for filter & forms
$mapel_list = $pdo->query("SELECT id_mapel, nama_mapel FROM tb_mata_pelajaran ORDER BY nama_mapel ASC")->fetchAll(PDO::FETCH_ASSOC);
$kelas_list = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
$jenis_options = ['CP/TP', 'ATP', 'Modul Ajar', 'RPP', 'Silabus', 'Program Tahunan (Prota)', 'Program Semester (Promes)', 'Kriteria Ketercapaian (KKTP)', 'Lainnya'];
$semester_options = ['Semester 1', 'Semester 2'];

// Filters
$f_jenis = trim((string)($_GET['f_jenis'] ?? ''));
$f_mapel = (int)($_GET['f_mapel'] ?? 0);
$f_kelas = (int)($_GET['f_kelas'] ?? 0);
$f_semester = trim((string)($_GET['f_semester'] ?? ''));
$f_tahun = trim((string)($_GET['f_tahun'] ?? ''));
$f_status = trim((string)($_GET['f_status'] ?? ''));

$where = ["p.id_guru = ?"];
$params = [$guru_id];

if ($f_jenis !== '') {
    $where[] = "p.jenis_perangkat = ?";
    $params[] = $f_jenis;
}
if ($f_mapel > 0) {
    $where[] = "p.id_mapel = ?";
    $params[] = $f_mapel;
}
if ($f_kelas > 0) {
    $where[] = "p.id_kelas = ?";
    $params[] = $f_kelas;
}
if ($f_semester !== '') {
    $where[] = "p.semester = ?";
    $params[] = $f_semester;
}
if ($f_tahun !== '') {
    $where[] = "p.tahun_ajaran = ?";
    $params[] = $f_tahun;
}
if ($f_status !== '') {
    $where[] = "p.status = ?";
    $params[] = $f_status;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT p.*, m.nama_mapel, k.nama_kelas, g.nama_guru
    FROM tb_perangkat_pembelajaran p
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = p.id_mapel
    LEFT JOIN tb_kelas k ON k.id_kelas = p.id_kelas
    LEFT JOIN tb_guru g ON g.id_guru = p.id_guru
    WHERE $where_sql
    ORDER BY p.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Unique years for filter
$tahun_list = $pdo->query("SELECT DISTINCT tahun_ajaran FROM tb_perangkat_pembelajaran WHERE tahun_ajaran IS NOT NULL AND tahun_ajaran != '' ORDER BY tahun_ajaran DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($tahun_ajaran_aktif, $tahun_list, true)) {
    array_unshift($tahun_list, $tahun_ajaran_aktif);
}

$page_title = 'Daftar Perangkat Pembelajaran';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-perangkat').length) {
        $('#table-perangkat').DataTable({
            'order': [[0, 'asc']],
            'columnDefs': [{ 'sortable': false, 'targets': [8, 11] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada data ditemukan',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    // Detail Modal
    $(document).on('click', '.btn-detail', function() {
        var data = $(this).data('json');
        $('#det_jenis').text(data.jenis_perangkat || '-');
        $('#det_judul').text(data.judul || '-');
        $('#det_mapel').text(data.nama_mapel || '-');
        $('#det_kelas').text(data.nama_kelas || '-');
        $('#det_semester').text(data.semester || '-');
        $('#det_tahun').text(data.tahun_ajaran || '-');
        $('#det_status').html('<span class="badge badge-' + (data.status === 'Aktif' ? 'success' : (data.status === 'Draft' ? 'warning' : 'secondary')) + '">' + data.status + '</span>');
        $('#det_pembuat').text(data.nama_guru || '-');
        $('#det_created').text(data.created_at || '-');
        $('#det_updated').text(data.updated_at || '-');
        $('#det_materi_tp').text(data.materi_tp || '-');
        $('#det_cp').text(data.cp || '-');
        $('#det_tp').text(data.tp || '-');
        $('#det_materi').text(data.materi || '-');
        $('#det_tujuan').text(data.tujuan_pembelajaran || '-');
        $('#det_indikator').text(data.indikator || '-');
        $('#det_deskripsi').text(data.deskripsi || '-');

        if (data.file_path) {
            $('#det_file').html('<a href="../uploads/perangkat/' + encodeURIComponent(data.file_path) + '" target="_blank" class="btn btn-sm btn-primary"><i class="fas fa-download"></i> Unduh File (' + data.file_path.split('.').pop().toUpperCase() + ')</a>');
        } else {
            $('#det_file').html('<span class="text-muted">Tidak ada file terlampir</span>');
        }
        $('#modalDetail').modal('show');
    });

    // Edit Modal
    $(document).on('click', '.btn-edit', function() {
        var data = $(this).data('json');
        $('#formPerangkatAction').val('edit');
        $('#perangkatId').val(data.id);
        $('#modalPerangkatTitle').text('Edit Perangkat Pembelajaran');
        $('#inp_jenis').val(data.jenis_perangkat);
        $('#inp_judul').val(data.judul);
        $('#inp_mapel').val(data.id_mapel || '');
        $('#inp_kelas').val(data.id_kelas || '');
        $('#inp_materi_tp').val(data.materi_tp || '');
        $('#inp_semester').val(data.semester);
        $('#inp_tahun').val(data.tahun_ajaran);
        $('#inp_status').val(data.status);
        $('#inp_cp').val(data.cp || '');
        $('#inp_tp').val(data.tp || '');
        $('#inp_materi').val(data.materi || '');
        $('#inp_tujuan').val(data.tujuan_pembelajaran || '');
        $('#inp_indikator').val(data.indikator || '');
        $('#inp_deskripsi').val(data.deskripsi || '');
        $('#modalPerangkat').modal('show');
    });

    $('#btnTambahPerangkat').on('click', function() {
        $('#formPerangkatAction').val('tambah');
        $('#perangkatId').val('');
        $('#modalPerangkatTitle').text('Tambah Perangkat Pembelajaran');
        $('#formPerangkat')[0].reset();
        $('#modalPerangkat').modal('show');
    });

    $(document).on('click', '.btn-arsip', function() {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Arsipkan perangkat?',
            text: 'Status perangkat akan diubah menjadi Arsip.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Ya, Arsipkan',
            cancelButtonText: 'Batal'
        }).then(function(res) {
            if (res.isConfirmed) {
                $('#formArsipId').val(id);
                $('#formArsip').submit();
            }
        });
    });

    $(document).on('click', '.btn-hapus', function() {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Hapus perangkat?',
            text: 'Data dan file perangkat akan dihapus permanen.',
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
            <h1>Daftar Perangkat Pembelajaran</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <!-- Filter Card -->
            <div class="card mb-3">
                <div class="card-header">
                    <h4><i class="fas fa-filter mr-2"></i>Filter Perangkat</h4>
                </div>
                <div class="card-body">
                    <form method="GET" class="row">
                        <div class="col-md-3 mb-2">
                            <label class="small font-weight-bold">Jenis Perangkat</label>
                            <select name="f_jenis" class="form-control form-control-sm">
                                <option value="">-- Semua Jenis --</option>
                                <?php foreach ($jenis_options as $j): ?>
                                    <option value="<?= htmlspecialchars($j) ?>" <?= $f_jenis === $j ? 'selected' : '' ?>><?= htmlspecialchars($j) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2">
                            <label class="small font-weight-bold">Mata Pelajaran</label>
                            <select name="f_mapel" class="form-control form-control-sm">
                                <option value="">-- Semua Mapel --</option>
                                <?php foreach ($mapel_list as $m): ?>
                                    <option value="<?= (int)$m['id_mapel'] ?>" <?= $f_mapel === (int)$m['id_mapel'] ? 'selected' : '' ?>><?= htmlspecialchars($m['nama_mapel']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <label class="small font-weight-bold">Kelas</label>
                            <select name="f_kelas" class="form-control form-control-sm">
                                <option value="">-- Semua Kelas --</option>
                                <?php foreach ($kelas_list as $k): ?>
                                    <option value="<?= (int)$k['id_kelas'] ?>" <?= $f_kelas === (int)$k['id_kelas'] ? 'selected' : '' ?>><?= htmlspecialchars($k['nama_kelas']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <label class="small font-weight-bold">Semester</label>
                            <select name="f_semester" class="form-control form-control-sm">
                                <option value="">-- Semua --</option>
                                <?php foreach ($semester_options as $s): ?>
                                    <option value="<?= htmlspecialchars($s) ?>" <?= $f_semester === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <label class="small font-weight-bold">Status</label>
                            <select name="f_status" class="form-control form-control-sm">
                                <option value="">-- Semua Status --</option>
                                <?php foreach (['Aktif', 'Draft', 'Arsip'] as $st): ?>
                                    <option value="<?= $st ?>" <?= $f_status === $st ? 'selected' : '' ?>><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mt-2 d-flex">
                            <button type="submit" class="btn btn-primary btn-sm mr-2"><i class="fas fa-search"></i> Terapkan Filter</button>
                            <a href="perangkat_pembelajaran.php" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i> Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Table Card -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4>Daftar Dokumen</h4>
                    <div>
                        <button type="button" class="btn btn-primary" id="btnTambahPerangkat">
                            <i class="fas fa-plus mr-1"></i> Tambah Perangkat
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered table-sm" id="table-perangkat">
                            <thead>
                                <tr>
                                    <th width="4%">No</th>
                                    <th>Jenis Perangkat</th>
                                    <th>Judul</th>
                                    <th>Mata Pelajaran</th>
                                    <th>Kelas</th>
                                    <th>Materi/TP</th>
                                    <th>Semester</th>
                                    <th>Tahun Ajaran</th>
                                    <th width="6%">File</th>
                                    <th width="7%">Status</th>
                                    <th>Tanggal</th>
                                    <th width="14%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $i => $r): ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td><span class="badge badge-info"><?= htmlspecialchars($r['jenis_perangkat']) ?></span></td>
                                        <td><strong><?= htmlspecialchars($r['judul']) ?></strong></td>
                                        <td><?= htmlspecialchars($r['nama_mapel'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['nama_kelas'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['materi_tp'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['semester'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['tahun_ajaran'] ?? '-') ?></td>
                                        <td class="text-center">
                                            <?php if (!empty($r['file_path'])): ?>
                                                <a href="../uploads/perangkat/<?= htmlspecialchars($r['file_path']) ?>" target="_blank" class="text-primary font-weight-bold" title="Download">
                                                    <i class="fas fa-file-alt fa-lg"></i>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php
                                            $badge_cls = $r['status'] === 'Aktif' ? 'success' : ($r['status'] === 'Draft' ? 'warning' : 'secondary');
                                            ?>
                                            <span class="badge badge-<?= $badge_cls ?>"><?= htmlspecialchars($r['status']) ?></span>
                                        </td>
                                        <td><?= date('d/m/Y', strtotime($r['created_at'])) ?></td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-info btn-sm btn-detail" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Detail">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" class="btn btn-warning btn-sm btn-edit" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if (!empty($r['file_path'])): ?>
                                                <a href="../uploads/perangkat/<?= htmlspecialchars($r['file_path']) ?>" download class="btn btn-success btn-sm" title="Download">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($r['status'] !== 'Arsip'): ?>
                                                <button type="button" class="btn btn-secondary btn-sm btn-arsip" data-id="<?= (int)$r['id'] ?>" title="Arsipkan">
                                                    <i class="fas fa-archive"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-danger btn-sm btn-hapus" data-id="<?= (int)$r['id'] ?>" title="Hapus">
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
<div class="modal fade" id="modalPerangkat" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formPerangkat" enctype="multipart/form-data">
                <input type="hidden" name="action" id="formPerangkatAction" value="tambah">
                <input type="hidden" name="id" id="perangkatId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalPerangkatTitle">Tambah Perangkat Pembelajaran</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Jenis Perangkat <span class="text-danger">*</span></label>
                            <select name="jenis_perangkat" id="inp_jenis" class="form-control" required>
                                <option value="">-- Pilih Jenis --</option>
                                <?php foreach ($jenis_options as $j): ?>
                                    <option value="<?= htmlspecialchars($j) ?>"><?= htmlspecialchars($j) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Judul Perangkat <span class="text-danger">*</span></label>
                            <input type="text" name="judul" id="inp_judul" class="form-control" required placeholder="Contoh: Modul Ajar IPAS Bab 1">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Mata Pelajaran</label>
                            <select name="id_mapel" id="inp_mapel" class="form-control">
                                <option value="">-- Pilih Mapel --</option>
                                <?php foreach ($mapel_list as $m): ?>
                                    <option value="<?= (int)$m['id_mapel'] ?>"><?= htmlspecialchars($m['nama_mapel']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Kelas</label>
                            <select name="id_kelas" id="inp_kelas" class="form-control">
                                <option value="">-- Pilih Kelas --</option>
                                <?php foreach ($kelas_list as $k): ?>
                                    <option value="<?= (int)$k['id_kelas'] ?>"><?= htmlspecialchars($k['nama_kelas']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Materi / TP</label>
                            <input type="text" name="materi_tp" id="inp_materi_tp" class="form-control" placeholder="Contoh: Metamorfosis Hewan">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Semester</label>
                            <select name="semester" id="inp_semester" class="form-control">
                                <?php foreach ($semester_options as $s): ?>
                                    <option value="<?= htmlspecialchars($s) ?>" <?= $s === $semester_aktif ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Tahun Ajaran</label>
                            <input type="text" name="tahun_ajaran" id="inp_tahun" class="form-control" value="<?= htmlspecialchars($tahun_ajaran_aktif) ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Upload File Dokumen</label>
                            <input type="file" name="file_perangkat" class="form-control-file">
                            <small class="text-muted">Format didukung: PDF, Word, Excel, PPT, ZIP</small>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Status</label>
                            <select name="status" id="inp_status" class="form-control">
                                <option value="Aktif">Aktif</option>
                                <option value="Draft">Draft</option>
                                <option value="Arsip">Arsip</option>
                            </select>
                        </div>
                        <div class="col-12 form-group">
                            <label>Capaian Pembelajaran (CP)</label>
                            <textarea name="cp" id="inp_cp" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12 form-group">
                            <label>Tujuan Pembelajaran (TP)</label>
                            <textarea name="tp" id="inp_tp" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Materi Pokok</label>
                            <textarea name="materi" id="inp_materi" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Indikator Ketercapaian</label>
                            <textarea name="indikator" id="inp_indikator" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12 form-group">
                            <label>Deskripsi Tambahan / Catatan</label>
                            <textarea name="deskripsi" id="inp_deskripsi" class="form-control" rows="2"></textarea>
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

<!-- Modal Detail Perangkat -->
<div class="modal fade" id="modalDetail" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle mr-2"></i>Detail Perangkat Pembelajaran</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <table class="table table-bordered table-sm">
                    <tr><th width="30%">Jenis Perangkat</th><td id="det_jenis"></td></tr>
                    <tr><th>Judul</th><td id="det_judul" class="font-weight-bold"></td></tr>
                    <tr><th>Mata Pelajaran</th><td id="det_mapel"></td></tr>
                    <tr><th>Kelas</th><td id="det_kelas"></td></tr>
                    <tr><th>Semester / Tahun</th><td><span id="det_semester"></span> / <span id="det_tahun"></span></td></tr>
                    <tr><th>Status</th><td id="det_status"></td></tr>
                    <tr><th>Pembuat / Guru</th><td id="det_pembuat"></td></tr>
                    <tr><th>Tanggal Dibuat</th><td id="det_created"></td></tr>
                    <tr><th>Tanggal Diperbarui</th><td id="det_updated"></td></tr>
                    <tr><th>Materi / TP Ringkas</th><td id="det_materi_tp"></td></tr>
                    <tr><th>Capaian Pembelajaran (CP)</th><td id="det_cp" style="white-space: pre-wrap;"></td></tr>
                    <tr><th>Tujuan Pembelajaran (TP)</th><td id="det_tp" style="white-space: pre-wrap;"></td></tr>
                    <tr><th>Materi Pembelajaran</th><td id="det_materi" style="white-space: pre-wrap;"></td></tr>
                    <tr><th>Tujuan Pembelajaran Khusus</th><td id="det_tujuan" style="white-space: pre-wrap;"></td></tr>
                    <tr><th>Indikator</th><td id="det_indikator" style="white-space: pre-wrap;"></td></tr>
                    <tr><th>Deskripsi</th><td id="det_deskripsi" style="white-space: pre-wrap;"></td></tr>
                    <tr><th>File Dokumen</th><td id="det_file"></td></tr>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<form method="POST" id="formArsip" class="d-none">
    <input type="hidden" name="action" value="arsip">
    <input type="hidden" name="id" id="formArsipId">
</form>
<form method="POST" id="formHapus" class="d-none">
    <input type="hidden" name="action" value="hapus">
    <input type="hidden" name="id" id="formHapusId">
</form>

<?php include '../templates/footer.php'; ?>
