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

$upload_dir = guru_upload_dir($pdo, $guru_id, 'tugas');

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
        $id_mapel = (int)($_POST['id_mapel'] ?? 0) ?: null;
        $id_kelas = (int)($_POST['id_kelas'] ?? 0) ?: null;
        $materi_tp = trim((string)($_POST['materi_tp'] ?? ''));
        $jenis_tugas = trim((string)($_POST['jenis_tugas'] ?? 'Individu'));
        $instruksi = trim((string)($_POST['instruksi'] ?? ''));
        $tgl_mulai = !empty($_POST['tgl_mulai']) ? date('Y-m-d H:i:s', strtotime($_POST['tgl_mulai'])) : date('Y-m-d H:i:s');
        $deadline = !empty($_POST['deadline']) ? date('Y-m-d H:i:s', strtotime($_POST['deadline'])) : null;
        $nilai_maksimal = (int)($_POST['nilai_maksimal'] ?? 100) ?: 100;
        $status = in_array($_POST['status'] ?? '', ['Draft', 'Aktif', 'Selesai', 'Arsip'], true) ? $_POST['status'] : 'Aktif';

        $lampiran = null;
        if (isset($_FILES['lampiran_file']) && $_FILES['lampiran_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['lampiran_file']['name'], PATHINFO_EXTENSION));
            $filename = 'tugas_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['lampiran_file']['tmp_name'], $upload_dir . $filename)) {
                $lampiran = guru_folder_name($pdo, $guru_id) . '/' . $filename;
            }
        }

        if ($judul === '') {
            $message = ['type' => 'warning', 'text' => 'Judul tugas wajib diisi.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_tugas (
                            id_guru, judul, id_mapel, id_kelas, materi_tp, jenis_tugas,
                            instruksi, tgl_mulai, deadline, nilai_maksimal, lampiran, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $guru_id, $judul, $id_mapel, $id_kelas, $materi_tp, $jenis_tugas,
                        $instruksi, $tgl_mulai, $deadline, $nilai_maksimal, $lampiran, $status
                    ]);

                    // Kirim notifikasi sistem untuk siswa
                    try {
                        $stkName = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas = ?");
                        $stkName->execute([$id_kelas]);
                        $kelas_name_notif = $stkName->fetchColumn() ?: '';
                        createNotification($pdo, "Tugas Baru: {$judul}" . ($kelas_name_notif ? " (Kelas {$kelas_name_notif})" : ""), 'dashboard.php');
                    } catch (Throwable $e) {}

                    $message = ['type' => 'success', 'text' => 'Tugas berhasil dibuat.'];
                } else {
                    $sql = "
                        UPDATE tb_tugas SET
                            judul = ?, id_mapel = ?, id_kelas = ?, materi_tp = ?, jenis_tugas = ?,
                            instruksi = ?, tgl_mulai = ?, deadline = ?, nilai_maksimal = ?, status = ?
                    ";
                    $params = [
                        $judul, $id_mapel, $id_kelas, $materi_tp, $jenis_tugas,
                        $instruksi, $tgl_mulai, $deadline, $nilai_maksimal, $status
                    ];
                    if ($lampiran !== null) {
                        try {
                            $stOld = $pdo->prepare("SELECT lampiran FROM tb_tugas WHERE id = ? AND id_guru = ?");
                            $stOld->execute([$id, $guru_id]);
                            $old_lamp = $stOld->fetchColumn();
                            $old_path = $old_lamp ? resolve_guru_file_path('tugas', $old_lamp) : null;
                            if ($old_path && is_file($old_path)) {
                                @unlink($old_path);
                            }
                        } catch (Throwable $e) {}
                        $sql .= ", lampiran = ?";
                        $params[] = $lampiran;
                    }
                    $sql .= " WHERE id = ? AND id_guru = ?";
                    $params[] = $id;
                    $params[] = $guru_id;
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $message = ['type' => 'success', 'text' => 'Tugas berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stRows = $pdo->prepare("SELECT lampiran FROM tb_tugas WHERE id = ? AND id_guru = ?");
            $stRows->execute([$id, $guru_id]);
            $del_lamp = $stRows->fetchColumn();
            $stSubs = $pdo->prepare("SELECT file_path FROM tb_tugas_pengumpulan WHERE id_tugas = ?");
            $stSubs->execute([$id]);
            $del_subs = $stSubs->fetchAll(PDO::FETCH_COLUMN);
            $stmt = $pdo->prepare("DELETE FROM tb_tugas WHERE id = ? AND id_guru = ?");
            $stmt->execute([$id, $guru_id]);
            $pdo->prepare("DELETE FROM tb_tugas_pengumpulan WHERE id_tugas = ?")->execute([$id]);
            $garbage = array_merge($del_lamp ? [$del_lamp] : [], (array)$del_subs);
            foreach ($garbage as $gf) {
                $gp = $gf ? resolve_guru_file_path('tugas', $gf) : null;
                if ($gp && is_file($gp)) {
                    @unlink($gp);
                }
            }
            $message = ['type' => 'success', 'text' => 'Tugas berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
        }
    }
}

// Master lists
if ($is_admin_or_kepala) {
    $mapel_list = $pdo->query("SELECT id_mapel, nama_mapel FROM tb_mata_pelajaran ORDER BY nama_mapel ASC")->fetchAll(PDO::FETCH_ASSOC);
    $kelas_list = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $mapel_list = getGuruTaughtMapels($pdo, $guru_id);
    $kelas_list = getGuruTaughtClasses($pdo, $guru_id);
}
$jenis_tugas_options = ['Individu', 'Kelompok', 'Proyek', 'Praktik', 'Portofolio', 'Kuis'];

// Deteksi kelas wali jika login sebagai wali kelas
$wali_kelas_id = 0;
if (!$is_admin_or_kepala) {
    try {
        $stWali = $pdo->prepare("SELECT id_kelas FROM tb_kelas WHERE wali_kelas = ? OR wali_kelas = (SELECT nama_guru FROM tb_guru WHERE id_guru = ?)");
        $stWali->execute([$guru_id, $guru_id]);
        $wali_kelas_id = (int)$stWali->fetchColumn() ?: 0;
    } catch (Throwable $e) {}
}

// Filters
$f_mapel = (int)($_GET['f_mapel'] ?? 0);
$f_kelas = (int)($_GET['f_kelas'] ?? 0);
$f_jenis = trim((string)($_GET['f_jenis'] ?? ''));
$f_status = trim((string)($_GET['f_status'] ?? ''));
$f_periode = trim((string)($_GET['f_periode'] ?? '')); // 'aktif', 'lewat', 'semua'

if ($is_admin_or_kepala) {
    $where = ["1=1"];
    $params = [];
} elseif ($wali_kelas_id > 0) {
    // Wali kelas dapat melihat tugas yang dibuatnya sendiri DAN tugas untuk kelas yang diampunya
    $where = ["(t.id_guru = ? OR t.id_kelas = ?)"];
    $params = [$guru_id, $wali_kelas_id];
} else {
    $where = ["t.id_guru = ?"];
    $params = [$guru_id];
}

if ($f_mapel > 0) {
    $where[] = "t.id_mapel = ?";
    $params[] = $f_mapel;
}
if ($f_kelas > 0) {
    $where[] = "t.id_kelas = ?";
    $params[] = $f_kelas;
}
if ($f_jenis !== '') {
    $where[] = "t.jenis_tugas = ?";
    $params[] = $f_jenis;
}
if ($f_status !== '') {
    $where[] = "t.status = ?";
    $params[] = $f_status;
}
if ($f_periode === 'aktif') {
    $where[] = "(t.deadline IS NULL OR t.deadline >= NOW())";
} elseif ($f_periode === 'lewat') {
    $where[] = "(t.deadline IS NOT NULL AND t.deadline < NOW())";
}

$where_sql = implode(' AND ', $where);

// Query tugas + aggregat pengumpulan & total siswa kelas
$stmt = $pdo->prepare("
    SELECT t.*, m.nama_mapel, k.nama_kelas,
           (SELECT COUNT(*) FROM tb_siswa s WHERE s.id_kelas = t.id_kelas) AS total_siswa,
           (SELECT COUNT(*) FROM tb_tugas_pengumpulan tp WHERE tp.id_tugas = t.id) AS total_kumpul,
           (SELECT COUNT(*) FROM tb_tugas_pengumpulan tp WHERE tp.id_tugas = t.id AND tp.status_periksa = 'Sudah Diperiksa') AS total_diperiksa
    FROM tb_tugas t
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = t.id_mapel
    LEFT JOIN tb_kelas k ON k.id_kelas = t.id_kelas
    WHERE $where_sql
    ORDER BY t.id DESC
");
$stmt->execute($params);
$tugas_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Daftar Tugas';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-tugas').length) {
        $('#table-tugas').DataTable({
            'order': [[0, 'asc']],
            'columnDefs': [{ 'sortable': false, 'targets': [11] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada tugas ditemukan',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    // Auto-submit filter on change
    $('#formFilterTugas select').on('change', function() {
        $('#formFilterTugas').submit();
    });

    $('#btnTambahTugas').on('click', function() {
        $('#formTugasAction').val('tambah');
        $('#tugasId').val('');
        $('#modalTugasTitle').text('Tambah Tugas Baru');
        $('#formTugas')[0].reset();
        var validKelasOpts = $('#inp_kelas option').filter(function() { return this.value !== ''; });
        if (validKelasOpts.length === 1) {
            $('#inp_kelas').val(validKelasOpts.val());
        }
        $('#modalTugas').modal('show');
    });

    $(document).on('click', '.btn-edit-tugas', function() {
        var data = $(this).data('json');
        $('#formTugasAction').val('edit');
        $('#tugasId').val(data.id);
        $('#modalTugasTitle').text('Edit Tugas');
        $('#inp_judul').val(data.judul);
        $('#inp_mapel').val(data.id_mapel || '');
        $('#inp_kelas').val(data.id_kelas || '');
        $('#inp_materi_tp').val(data.materi_tp || '');
        $('#inp_jenis').val(data.jenis_tugas);
        $('#inp_instruksi').val(data.instruksi || '');
        $('#inp_mulai').val(data.tgl_mulai ? data.tgl_mulai.substring(0, 16).replace(' ', 'T') : '');
        $('#inp_deadline').val(data.deadline ? data.deadline.substring(0, 16).replace(' ', 'T') : '');
        $('#inp_nilai_maks').val(data.nilai_maksimal);
        $('#inp_status').val(data.status);
        $('#modalTugas').modal('show');
    });

    $(document).on('click', '.btn-hapus-tugas', function() {
        var id = $(this).data('id');
        var judul = $(this).data('judul');
        Swal.fire({
            title: 'Hapus Tugas?',
            text: 'Tugas "' + judul + '" dan seluruh pengumpulan siswa akan dihapus.',
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
            <h1>Daftar Tugas</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <!-- Filter Card -->
            <div class="card mb-3">
                <div class="card-header">
                    <h4><i class="fas fa-filter mr-2"></i>Filter Tugas</h4>
                </div>
                <div class="card-body">
                    <form method="GET" class="row" id="formFilterTugas">
                        <div class="col-md-3 mb-2">
                            <label class="small font-weight-bold">Mata Pelajaran</label>
                            <select name="f_mapel" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Mapel --</option>
                                <?php foreach ($mapel_list as $m): ?>
                                    <option value="<?= (int)$m['id_mapel'] ?>" <?= $f_mapel === (int)$m['id_mapel'] ? 'selected' : '' ?>><?= htmlspecialchars($m['nama_mapel']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <label class="small font-weight-bold">Kelas</label>
                            <select name="f_kelas" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Kelas --</option>
                                <?php foreach ($kelas_list as $k): ?>
                                    <option value="<?= (int)$k['id_kelas'] ?>" <?= $f_kelas === (int)$k['id_kelas'] ? 'selected' : '' ?>><?= htmlspecialchars($k['nama_kelas']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <label class="small font-weight-bold">Jenis Tugas</label>
                            <select name="f_jenis" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Jenis --</option>
                                <?php foreach ($jenis_tugas_options as $jt): ?>
                                    <option value="<?= $jt ?>" <?= $f_jenis === $jt ? 'selected' : '' ?>><?= $jt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <label class="small font-weight-bold">Status</label>
                            <select name="f_status" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Status --</option>
                                <?php foreach (['Aktif', 'Draft', 'Selesai', 'Arsip'] as $st): ?>
                                    <option value="<?= $st ?>" <?= $f_status === $st ? 'selected' : '' ?>><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2">
                            <label class="small font-weight-bold">Periode / Deadline</label>
                            <select name="f_periode" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Periode --</option>
                                <option value="aktif" <?= $f_periode === 'aktif' ? 'selected' : '' ?>>Deadline Masih Aktif</option>
                                <option value="lewat" <?= $f_periode === 'lewat' ? 'selected' : '' ?>>Deadline Lewat</option>
                            </select>
                        </div>
                        <?php if ($f_mapel > 0 || $f_kelas > 0 || $f_jenis !== '' || $f_status !== '' || $f_periode !== ''): ?>
                        <div class="col-12 mt-1">
                            <a href="tugas.php" class="btn btn-secondary btn-sm"><i class="fas fa-undo mr-1"></i> Reset Filter</a>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Table Card -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4>Daftar Penugasan</h4>
                    <?php if ($can_crud): ?>
                    <div>
                        <button type="button" class="btn btn-primary" id="btnTambahTugas">
                            <i class="fas fa-plus mr-1"></i> Buat Tugas Baru
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered table-sm" id="table-tugas">
                            <thead>
                                <tr>
                                    <th width="4%">No</th>
                                    <th>Judul Tugas</th>
                                    <th>Mata Pelajaran</th>
                                    <th>Kelas</th>
                                    <th>Materi/TP</th>
                                    <th>Jenis Tugas</th>
                                    <th>Tanggal Mulai</th>
                                    <th>Deadline</th>
                                    <th>Pengumpulan</th>
                                    <th>Sudah Diperiksa</th>
                                    <th>Status</th>
                                    <th width="14%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tugas_rows as $i => $r): ?>
                                    <?php
                                    $total_siswa = (int)($r['total_siswa'] ?? 0);
                                    $kumpul = (int)($r['total_kumpul'] ?? 0);
                                    $periksa = (int)($r['total_diperiksa'] ?? 0);

                                    // Badge warna status
                                    $status_badge = 'secondary';
                                    if ($r['status'] === 'Aktif') $status_badge = 'success';
                                    elseif ($r['status'] === 'Draft') $status_badge = 'warning';
                                    elseif ($r['status'] === 'Selesai') $status_badge = 'info';

                                    $deadline_str = '-';
                                    $is_overdue = false;
                                    if (!empty($r['deadline'])) {
                                        $deadline_str = date('d/m/Y H:i', strtotime($r['deadline']));
                                        if (strtotime($r['deadline']) < time() && $r['status'] === 'Aktif') {
                                            $is_overdue = true;
                                        }
                                    }
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td>
                                            <a href="detail_tugas.php?id=<?= (int)$r['id'] ?>" class="font-weight-bold text-primary">
                                                <?= htmlspecialchars($r['judul']) ?>
                                            </a>
                                        </td>
                                        <td><?= htmlspecialchars($r['nama_mapel'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['nama_kelas'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['materi_tp'] ?? '-') ?></td>
                                        <td><span class="badge badge-light border"><?= htmlspecialchars($r['jenis_tugas']) ?></span></td>
                                        <td><?= !empty($r['tgl_mulai']) ? date('d/m/Y H:i', strtotime($r['tgl_mulai'])) : '-' ?></td>
                                        <td>
                                            <?= $deadline_str ?>
                                            <?php if ($is_overdue): ?>
                                                <span class="badge badge-danger ml-1">Lewat</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-<?= $kumpul >= $total_siswa && $total_siswa > 0 ? 'success' : 'primary' ?>">
                                                <?= $kumpul ?>/<?= $total_siswa ?> siswa
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-<?= $periksa >= $kumpul && $kumpul > 0 ? 'success' : ($periksa > 0 ? 'warning' : 'secondary') ?>">
                                                <?= $periksa ?>/<?= $kumpul ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-<?= $status_badge ?>"><?= htmlspecialchars($r['status']) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <a href="detail_tugas.php?id=<?= (int)$r['id'] ?>" class="btn btn-info btn-sm" title="Detail & Pengumpulan">
                                                <i class="fas fa-list"></i>
                                            </a>
                                            <?php if ($can_crud): ?>
                                            <button type="button" class="btn btn-warning btn-sm btn-edit-tugas" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm btn-hapus-tugas" data-id="<?= (int)$r['id'] ?>" data-judul="<?= htmlspecialchars($r['judul'], ENT_QUOTES) ?>" title="Hapus">
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

<!-- Modal Form Tambah / Edit Tugas -->
<div class="modal fade" id="modalTugas" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formTugas" enctype="multipart/form-data">
                <input type="hidden" name="action" id="formTugasAction" value="tambah">
                <input type="hidden" name="id" id="tugasId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTugasTitle">Buat Tugas Baru</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-12 form-group">
                            <label>Judul Tugas <span class="text-danger">*</span></label>
                            <input type="text" name="judul" id="inp_judul" class="form-control" required placeholder="Contoh: Latihan Soal Bab 2">
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
                            <label>Kelas Target <?= count($kelas_list) > 1 ? '<span class="text-danger">*</span>' : '' ?></label>
                            <select name="id_kelas" id="inp_kelas" class="form-control" required>
                                <?php if (count($kelas_list) > 1): ?>
                                    <option value="">-- Pilih Kelas --</option>
                                    <?php foreach ($kelas_list as $k): ?>
                                        <option value="<?= (int)$k['id_kelas'] ?>"><?= htmlspecialchars($k['nama_kelas']) ?></option>
                                    <?php endforeach; ?>
                                <?php elseif (count($kelas_list) === 1): ?>
                                    <option value="<?= (int)$kelas_list[0]['id_kelas'] ?>" selected><?= htmlspecialchars($kelas_list[0]['nama_kelas']) ?></option>
                                <?php else: ?>
                                    <option value="">-- Tidak ada kelas --</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Materi / TP</label>
                            <input type="text" name="materi_tp" id="inp_materi_tp" class="form-control" placeholder="Contoh: Ekosistem Darat">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Jenis Tugas</label>
                            <select name="jenis_tugas" id="inp_jenis" class="form-control">
                                <?php foreach ($jenis_tugas_options as $jt): ?>
                                    <option value="<?= $jt ?>"><?= $jt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Tanggal Mulai</label>
                            <input type="datetime-local" name="tgl_mulai" id="inp_mulai" class="form-control">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Deadline Pengumpulan</label>
                            <input type="datetime-local" name="deadline" id="inp_deadline" class="form-control">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Nilai Maksimal</label>
                            <input type="number" name="nilai_maksimal" id="inp_nilai_maks" class="form-control" value="100" min="1" max="1000">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Status</label>
                            <select name="status" id="inp_status" class="form-control">
                                <option value="Aktif">Aktif</option>
                                <option value="Draft">Draft</option>
                                <option value="Selesai">Selesai</option>
                                <option value="Arsip">Arsip</option>
                            </select>
                        </div>
                        <div class="col-12 form-group">
                            <label>Lampiran Berkas (Soal/Panduan)</label>
                            <input type="file" name="lampiran_file" class="form-control-file">
                        </div>
                        <div class="col-12 form-group">
                            <label>Instruksi / Petunjuk Pengerjaan</label>
                            <textarea name="instruksi" id="inp_instruksi" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan Tugas</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form method="POST" id="formHapus" class="d-none">
    <input type="hidden" name="action" value="hapus">
    <input type="hidden" name="id" id="formHapusId">
</form>

<?php include '../templates/footer.php'; ?>
