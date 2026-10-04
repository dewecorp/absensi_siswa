<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['guru', 'wali', 'admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$is_admin_or_kepala = in_array($user_level, ['admin', 'kepala_madrasah'], true) || in_array($_GET['session_type'] ?? '', ['admin', 'kepala_madrasah'], true);
$can_crud = !$is_admin_or_kepala;

$school_profile = getSchoolProfile($pdo);
$tahun_ajaran_aktif = $school_profile['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1);
$semester_aktif = $school_profile['semester'] ?? 'Semester 1';

$upload_dir = guru_upload_dir($pdo, $guru_id, 'bahan_ajar');

$message = null;

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_crud) {
        $message = ['type' => 'danger', 'text' => 'Anda tidak memiliki hak akses untuk mengubah data ini.'];
    } else {
        $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $judul = trim((string)($_POST['judul'] ?? ''));
        $jenis = in_array($_POST['jenis'] ?? '', ['PDF', 'Video', 'Link', 'Presentasi', 'LKPD', 'Dokumen'], true) ? $_POST['jenis'] : 'PDF';
        $id_mapel = (int)($_POST['id_mapel'] ?? 0) ?: null;
        $id_kelas = (int)($_POST['id_kelas'] ?? 0) ?: null;
        $materi_tp = trim((string)($_POST['materi_tp'] ?? ''));
        $semester = trim((string)($_POST['semester'] ?? $semester_aktif));
        $tahun_ajaran = trim((string)($_POST['tahun_ajaran'] ?? $tahun_ajaran_aktif));
        $status = in_array($_POST['status'] ?? '', ['Draft', 'Aktif', 'Arsip'], true) ? $_POST['status'] : 'Aktif';
        $link_url = trim((string)($_POST['link_url'] ?? ''));

        $file_link = $link_url;
        if (isset($_FILES['file_bahan']) && $_FILES['file_bahan']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['file_bahan']['name'], PATHINFO_EXTENSION));
            $filename = 'bahan_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['file_bahan']['tmp_name'], $upload_dir . $filename)) {
                $file_link = guru_folder_name($pdo, $guru_id) . '/' . $filename;
            }
        }

        if ($judul === '') {
            $message = ['type' => 'warning', 'text' => 'Judul bahan ajar wajib diisi.'];
        } elseif ($file_link === '' && $action === 'tambah') {
            $message = ['type' => 'warning', 'text' => 'File dokumen atau Tautan Link wajib diisi.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_bahan_ajar (
                            id_guru, judul, jenis, id_mapel, id_kelas, materi_tp,
                            file_link, semester, tahun_ajaran, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $guru_id, $judul, $jenis, $id_mapel, $id_kelas, $materi_tp,
                        $file_link, $semester, $tahun_ajaran, $status
                    ]);
                    $message = ['type' => 'success', 'text' => 'Bahan ajar berhasil ditambahkan.'];
                } else {
                    $sql = "
                        UPDATE tb_bahan_ajar SET
                            judul = ?, jenis = ?, id_mapel = ?, id_kelas = ?,
                            materi_tp = ?, semester = ?, tahun_ajaran = ?, status = ?
                    ";
                    $params = [
                        $judul, $jenis, $id_mapel, $id_kelas,
                        $materi_tp, $semester, $tahun_ajaran, $status
                    ];
                    if ($file_link !== '') {
                        $sql .= ", file_link = ?";
                        $params[] = $file_link;
                    }
                    $sql .= " WHERE id = ? AND id_guru = ?";
                    $params[] = $id;
                    $params[] = $guru_id;
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $message = ['type' => 'success', 'text' => 'Bahan ajar berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT file_link FROM tb_bahan_ajar WHERE id = ? AND id_guru = ?");
            $stmt->execute([$id, $guru_id]);
            $old = $stmt->fetchColumn();
            $old_path = $old ? resolve_guru_file_path('bahan_ajar', $old) : null;
            if ($old_path && is_file($old_path)) {
                @unlink($old_path);
            }
            $pdo->prepare("DELETE FROM tb_bahan_ajar WHERE id = ? AND id_guru = ?")->execute([$id, $guru_id]);
            $message = ['type' => 'success', 'text' => 'Bahan ajar berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
}
}

// Master lists
$mapel_list = $pdo->query("SELECT id_mapel, nama_mapel FROM tb_mata_pelajaran ORDER BY nama_mapel ASC")->fetchAll(PDO::FETCH_ASSOC);
$kelas_list = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
$jenis_options = ['PDF', 'Video', 'Link', 'Presentasi', 'LKPD', 'Dokumen'];
$semester_options = ['Semester 1', 'Semester 2'];

// Filters
$f_jenis = trim((string)($_GET['f_jenis'] ?? ''));
$f_mapel = (int)($_GET['f_mapel'] ?? 0);
$f_kelas = (int)($_GET['f_kelas'] ?? 0);
$f_semester = trim((string)($_GET['f_semester'] ?? ''));
$f_tahun = trim((string)($_GET['f_tahun'] ?? ''));
$f_status = trim((string)($_GET['f_status'] ?? ''));

if ($is_admin_or_kepala) {
    $where = ["1=1"];
    $params = [];
} else {
    $where = ["b.id_guru = ?"];
    $params = [$guru_id];
}

if ($f_jenis !== '') {
    $where[] = "b.jenis = ?";
    $params[] = $f_jenis;
}
if ($f_mapel > 0) {
    $where[] = "b.id_mapel = ?";
    $params[] = $f_mapel;
}
if ($f_kelas > 0) {
    $where[] = "b.id_kelas = ?";
    $params[] = $f_kelas;
}
if ($f_semester !== '') {
    $where[] = "b.semester = ?";
    $params[] = $f_semester;
}
if ($f_tahun !== '') {
    $where[] = "b.tahun_ajaran = ?";
    $params[] = $f_tahun;
}
if ($f_status !== '') {
    $where[] = "b.status = ?";
    $params[] = $f_status;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT b.*, m.nama_mapel, k.nama_kelas, g.nama_guru
    FROM tb_bahan_ajar b
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = b.id_mapel
    LEFT JOIN tb_kelas k ON k.id_kelas = b.id_kelas
    LEFT JOIN tb_guru g ON g.id_guru = b.id_guru
    WHERE $where_sql
    ORDER BY b.id DESC
");
$stmt->execute($params);
$bahan_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tahun_list = $pdo->query("SELECT DISTINCT tahun_ajaran FROM tb_bahan_ajar WHERE tahun_ajaran IS NOT NULL AND tahun_ajaran != '' ORDER BY tahun_ajaran DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($tahun_ajaran_aktif, $tahun_list, true)) {
    array_unshift($tahun_list, $tahun_ajaran_aktif);
}

$page_title = 'Daftar Bahan Ajar';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-bahan').length) {
        $('#table-bahan').DataTable({
            'order': [[0, 'asc']],
            'columnDefs': [{ 'sortable': false, 'targets': [6, 11] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada bahan ajar ditemukan',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    $('#btnTambahBahan').on('click', function() {
        $('#formBahanAction').val('tambah');
        $('#bahanId').val('');
        $('#modalBahanTitle').text('Tambah Bahan Ajar Baru');
        $('#formBahan')[0].reset();
        $('#modalBahan').modal('show');
    });

    $(document).on('click', '.btn-edit-bahan', function() {
        var data = $(this).data('json');
        $('#formBahanAction').val('edit');
        $('#bahanId').val(data.id);
        $('#modalBahanTitle').text('Edit Bahan Ajar');
        $('#inp_judul').val(data.judul);
        $('#inp_jenis').val(data.jenis);
        $('#inp_mapel').val(data.id_mapel || '');
        $('#inp_kelas').val(data.id_kelas || '');
        $('#inp_materi_tp').val(data.materi_tp || '');
        $('#inp_semester').val(data.semester);
        $('#inp_tahun').val(data.tahun_ajaran);
        $('#inp_status').val(data.status);
        if (data.file_link && (data.file_link.indexOf('http://') === 0 || data.file_link.indexOf('https://') === 0)) {
            $('#inp_link').val(data.file_link);
        } else {
            $('#inp_link').val('');
        }
        $('#modalBahan').modal('show');
    });

    // Preview Modal
    $(document).on('click', '.btn-preview-bahan', function() {
        var url = $(this).data('url');
        var title = $(this).data('title');
        var jenis = $(this).data('jenis');
        $('#modalPreviewTitle').text(title);

        var content = '';
        if (url.indexOf('http://') === 0 || url.indexOf('https://') === 0) {
            if (url.indexOf('youtube.com') !== -1 || url.indexOf('youtu.be') !== -1) {
                var vidId = url.split('v=')[1] || url.split('/').pop();
                content = '<div class="embed-responsive embed-responsive-16by9"><iframe class="embed-responsive-item" src="https://www.youtube.com/embed/' + vidId + '" allowfullscreen></iframe></div>';
            } else {
                content = '<div class="text-center p-4"><p>Tautan Eksternal:</p><a href="' + url + '" target="_blank" class="btn btn-primary"><i class="fas fa-external-link-alt mr-1"></i> Buka di Tab Baru</a></div>';
            }
        } else {
            var fullUrl = '../uploads/bahan_ajar/' + url.split('/').map(encodeURIComponent).join('/');
            var ext = url.split('.').pop().toLowerCase();
            if (ext === 'pdf') {
                content = '<iframe src="' + fullUrl + '" style="width:100%; height:550px; border:none;"></iframe>';
            } else if (['jpg', 'jpeg', 'png', 'webp', 'gif'].indexOf(ext) !== -1) {
                content = '<div class="text-center"><img src="' + fullUrl + '" class="img-fluid" style="max-height:550px;"></div>';
            } else {
                content = '<div class="text-center p-4"><p>Dokumen tidak dapat dipratinjau langsung.</p><a href="' + fullUrl + '" download class="btn btn-success"><i class="fas fa-download mr-1"></i> Unduh Berkas</a></div>';
            }
        }
        $('#modalPreviewBody').html(content);
        $('#modalPreviewBahan').modal('show');
    });

    $(document).on('click', '.btn-hapus-bahan', function() {
        var id = $(this).data('id');
        var judul = $(this).data('judul');
        Swal.fire({
            title: 'Hapus Bahan Ajar?',
            text: 'Bahan ajar "' + judul + '" akan dihapus permanen.',
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
            <h1>Daftar Bahan Ajar</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <!-- Filter Card -->
            <div class="card mb-3">
                <div class="card-header">
                    <h4><i class="fas fa-filter mr-2"></i>Filter Bahan Ajar</h4>
                </div>
                <div class="card-body">
                    <form method="GET" class="row">
                        <div class="col-md-2 mb-2">
                            <label class="small font-weight-bold">Jenis</label>
                            <select name="f_jenis" class="form-control form-control-sm">
                                <option value="">-- Semua Jenis --</option>
                                <?php foreach ($jenis_options as $j): ?>
                                    <option value="<?= $j ?>" <?= $f_jenis === $j ? 'selected' : '' ?>><?= $j ?></option>
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
                        <div class="col-md-3 mb-2">
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
                            <a href="bahan_ajar.php" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i> Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Table Card -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4>Materi & Bahan Pembelajaran</h4>
                    <?php if ($can_crud): ?>
                    <button type="button" class="btn btn-primary" id="btnTambahBahan">
                        <i class="fas fa-plus mr-1"></i> Tambah Bahan Ajar
                    </button>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered table-sm" id="table-bahan">
                            <thead>
                                <tr>
                                    <th width="4%">No</th>
                                    <th>Judul</th>
                                    <th>Jenis</th>
                                    <th>Mata Pelajaran</th>
                                    <th>Kelas</th>
                                    <th>Materi/TP</th>
                                    <th width="10%">File / Link</th>
                                    <th>Semester</th>
                                    <th>Tahun Ajaran</th>
                                    <th>Status</th>
                                    <th>Tanggal</th>
                                    <th width="16%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bahan_rows as $i => $r): ?>
                                    <?php
                                    $is_url = (strpos($r['file_link'], 'http://') === 0 || strpos($r['file_link'], 'https://') === 0);
                                    $file_dest = $is_url ? $r['file_link'] : guru_file_href('bahan_ajar', $r['file_link']);
                                    $st_badge = $r['status'] === 'Aktif' ? 'success' : ($r['status'] === 'Draft' ? 'warning' : 'secondary');
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td><strong><?= htmlspecialchars($r['judul']) ?></strong></td>
                                        <td><span class="badge badge-info"><?= htmlspecialchars($r['jenis']) ?></span></td>
                                        <td><?= htmlspecialchars($r['nama_mapel'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['nama_kelas'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['materi_tp'] ?? '-') ?></td>
                                        <td class="text-center">
                                            <?php if ($is_url): ?>
                                                <a href="<?= htmlspecialchars($r['file_link']) ?>" target="_blank" class="btn btn-sm btn-outline-info" title="Buka Tautan">
                                                    <i class="fas fa-link"></i> Link
                                                </a>
                                            <?php elseif (!empty($r['file_link'])): ?>
                                                <a href="<?= $file_dest ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="File">
                                                    <i class="fas fa-file-alt"></i> <?= strtoupper(pathinfo($r['file_link'], PATHINFO_EXTENSION)) ?>
                                                </a>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($r['semester'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['tahun_ajaran'] ?? '-') ?></td>
                                        <td class="text-center">
                                            <span class="badge badge-<?= $st_badge ?>"><?= htmlspecialchars($r['status']) ?></span>
                                        </td>
                                        <td><?= date('d/m/Y', strtotime($r['created_at'])) ?></td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-info btn-sm btn-preview-bahan"
                                                data-url="<?= htmlspecialchars($r['file_link'], ENT_QUOTES) ?>"
                                                data-title="<?= htmlspecialchars($r['judul'], ENT_QUOTES) ?>"
                                                data-jenis="<?= htmlspecialchars($r['jenis'], ENT_QUOTES) ?>"
                                                title="Preview">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <a href="<?= $file_dest ?>" target="_blank" class="btn btn-secondary btn-sm" title="Buka">
                                                <i class="fas fa-external-link-alt"></i>
                                            </a>
                                             <?php if (!$is_url && !empty($r['file_link'])): ?>
                                                 <a href="<?= $file_dest ?>" download class="btn btn-success btn-sm" title="Download">
                                                     <i class="fas fa-download"></i>
                                                 </a>
                                             <?php endif; ?>
                                             <?php if ($can_crud): ?>
                                             <button type="button" class="btn btn-warning btn-sm btn-edit-bahan" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                 <i class="fas fa-edit"></i>
                                             </button>
                                             <button type="button" class="btn btn-danger btn-sm btn-hapus-bahan" data-id="<?= (int)$r['id'] ?>" data-judul="<?= htmlspecialchars($r['judul'], ENT_QUOTES) ?>" title="Hapus">
                                                 <i class="fas fa-trash"></i>
                                             </button>
                                             <?php endif; ?>
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

<!-- Modal Form Tambah / Edit Bahan Ajar -->
<div class="modal fade" id="modalBahan" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formBahan" enctype="multipart/form-data">
                <input type="hidden" name="action" id="formBahanAction" value="tambah">
                <input type="hidden" name="id" id="bahanId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalBahanTitle">Tambah Bahan Ajar Baru</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-8 form-group">
                            <label>Judul Bahan Ajar <span class="text-danger">*</span></label>
                            <input type="text" name="judul" id="inp_judul" class="form-control" required placeholder="Contoh: Modul PPT Sistem Peredaran Darah">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Jenis Bahan Ajar</label>
                            <select name="jenis" id="inp_jenis" class="form-control">
                                <?php foreach ($jenis_options as $j): ?>
                                    <option value="<?= $j ?>"><?= $j ?></option>
                                <?php endforeach; ?>
                            </select>
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
                            <input type="text" name="materi_tp" id="inp_materi_tp" class="form-control" placeholder="Contoh: Peredaran Darah Besar">
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
                            <label>Unggah Berkas File (PDF/PPT/DOC/Video/LKPD)</label>
                            <input type="file" name="file_bahan" class="form-control-file">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Atau Tautan Link Eksternal (URL YouTube/Drive/Web)</label>
                            <input type="url" name="link_url" id="inp_link" class="form-control" placeholder="https://...">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Status</label>
                            <select name="status" id="inp_status" class="form-control">
                                <option value="Aktif">Aktif</option>
                                <option value="Draft">Draft</option>
                                <option value="Arsip">Arsip</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan Bahan Ajar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Preview Bahan Ajar -->
<div class="modal fade" id="modalPreviewBahan" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalPreviewTitle"><i class="fas fa-eye mr-2"></i>Preview Bahan Ajar</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body p-0" id="modalPreviewBody">
            </div>
        </div>
    </div>
</div>

<form method="POST" id="formHapus" class="d-none">
    <input type="hidden" name="action" value="hapus">
    <input type="hidden" name="id" id="formHapusId">
</form>

<?php include '../templates/footer.php'; ?>
