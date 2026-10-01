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

// Handle CRUD Projek & Anggota
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Projek CRUD
    if ($action === 'tambah_projek' || $action === 'edit_projek') {
        $id = (int)($_POST['id'] ?? 0);
        $nama_projek = trim((string)($_POST['nama_projek'] ?? ''));
        $tema = trim((string)($_POST['tema'] ?? ''));
        $id_kelas = (int)($_POST['id_kelas'] ?? $selected_kelas_id);
        $pembimbing = trim((string)($_POST['pembimbing'] ?? ''));
        $tgl_mulai = !empty($_POST['tgl_mulai']) ? date('Y-m-d', strtotime($_POST['tgl_mulai'])) : null;
        $tgl_selesai = !empty($_POST['tgl_selesai']) ? date('Y-m-d', strtotime($_POST['tgl_selesai'])) : null;
        $deskripsi = trim((string)($_POST['deskripsi'] ?? ''));
        $status = in_array($_POST['status'] ?? '', ['Perencanaan', 'Berjalan', 'Selesai', 'Arsip'], true) ? $_POST['status'] : 'Perencanaan';

        if ($nama_projek === '' || $tema === '') {
            $message = ['type' => 'warning', 'text' => 'Nama Projek dan Tema wajib diisi.'];
        } else {
            try {
                if ($action === 'tambah_projek') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_projek_kokurikuler (
                            id_wali, nama_projek, tema, id_kelas, pembimbing,
                            tgl_mulai, tgl_selesai, deskripsi, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $guru_id, $nama_projek, $tema, $id_kelas, $pembimbing,
                        $tgl_mulai, $tgl_selesai, $deskripsi, $status
                    ]);
                    $message = ['type' => 'success', 'text' => 'Projek kokurikuler berhasil ditambahkan.'];
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE tb_projek_kokurikuler SET
                            nama_projek = ?, tema = ?, id_kelas = ?, pembimbing = ?,
                            tgl_mulai = ?, tgl_selesai = ?, deskripsi = ?, status = ?
                        WHERE id = ? " . ($user_level !== 'admin' ? "AND id_wali = $guru_id" : "") . "
                    ");
                    $stmt->execute([
                        $nama_projek, $tema, $id_kelas, $pembimbing,
                        $tgl_mulai, $tgl_selesai, $deskripsi, $status, $id
                    ]);
                    $message = ['type' => 'success', 'text' => 'Projek berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus_projek') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM tb_projek_kokurikuler WHERE id = ? " . ($user_level !== 'admin' ? "AND id_wali = $guru_id" : ""))->execute([$id]);
            $pdo->prepare("DELETE FROM tb_projek_anggota WHERE id_projek = ?")->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Projek dan anggotanya berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }

    // 2. Anggota Projek CRUD (Fitur 19)
    elseif ($action === 'tambah_anggota') {
        $id_projek = (int)($_POST['id_projek'] ?? 0);
        $id_siswa = (int)($_POST['id_siswa'] ?? 0);
        $peran = in_array($_POST['peran'] ?? '', ['Ketua', 'Sekretaris', 'Anggota', 'Presentator', 'Lainnya'], true) ? $_POST['peran'] : 'Anggota';
        $kelompok = trim((string)($_POST['kelompok'] ?? 'Kelompok 1'));
        $catatan = trim((string)($_POST['catatan'] ?? ''));
        $status = in_array($_POST['status'] ?? '', ['Aktif', 'Selesai', 'Keluar'], true) ? $_POST['status'] : 'Aktif';

        if ($id_projek <= 0 || $id_siswa <= 0) {
            $message = ['type' => 'warning', 'text' => 'Pilih Siswa untuk ditambahkan ke projek.'];
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO tb_projek_anggota (id_projek, id_siswa, peran, kelompok, catatan, status)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE peran = VALUES(peran), kelompok = VALUES(kelompok), catatan = VALUES(catatan), status = VALUES(status)
                ");
                $stmt->execute([$id_projek, $id_siswa, $peran, $kelompok, $catatan, $status]);
                $message = ['type' => 'success', 'text' => 'Anggota projek berhasil ditambahkan/diperbarui.'];
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menambah anggota: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus_anggota') {
        $id_anggota = (int)($_POST['id_anggota'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM tb_projek_anggota WHERE id = ?")->execute([$id_anggota]);
            $message = ['type' => 'success', 'text' => 'Anggota dikeluarkan dari projek.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus anggota: ' . $e->getMessage()];
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

// Fetch projek rows + hitung peserta
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
    SELECT p.*, c.nama_kelas,
           (SELECT COUNT(*) FROM tb_projek_anggota pa WHERE pa.id_projek = p.id AND pa.status = 'Aktif') AS jumlah_peserta
    FROM tb_projek_kokurikuler p
    LEFT JOIN tb_kelas c ON c.id_kelas = p.id_kelas
    WHERE $where_sql
    ORDER BY p.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Endpoint JSON untuk detail anggota projek
if (isset($_GET['ajax_anggota']) && (int)$_GET['ajax_anggota'] === 1) {
    header('Content-Type: application/json; charset=UTF-8');
    $pid = (int)($_GET['id_projek'] ?? 0);
    $stA = $pdo->prepare("
        SELECT pa.*, s.nama_siswa, s.nisn
        FROM tb_projek_anggota pa
        JOIN tb_siswa s ON s.id_siswa = pa.id_siswa
        WHERE pa.id_projek = ?
        ORDER BY pa.kelompok ASC, pa.peran ASC, s.nama_siswa ASC
    ");
    $stA->execute([$pid]);
    echo json_encode($stA->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

$status_projek_options = ['Perencanaan', 'Berjalan', 'Selesai', 'Arsip'];
$peran_options = ['Ketua', 'Sekretaris', 'Anggota', 'Presentator', 'Lainnya'];

$page_title = 'Daftar Projek / Kokurikuler';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-projek').length) {
        $('#table-projek').DataTable({
            'order': [[0, 'asc']],
            'columnDefs': [{ 'sortable': false, 'targets': [9] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada projek ditemukan',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    $('#btnTambahProjek').on('click', function() {
        $('#formProjekAction').val('tambah_projek');
        $('#projekId').val('');
        $('#modalProjekTitle').text('Tambah Projek / Kokurikuler Baru');
        $('#formProjek')[0].reset();
        $('#modalProjek').modal('show');
    });

    $(document).on('click', '.btn-edit-projek', function() {
        var data = $(this).data('json');
        $('#formProjekAction').val('edit_projek');
        $('#projekId').val(data.id);
        $('#modalProjekTitle').text('Edit Projek / Kokurikuler');
        $('#inp_nama').val(data.nama_projek);
        $('#inp_tema').val(data.tema);
        $('#inp_pembimbing').val(data.pembimbing || '');
        $('#inp_mulai').val(data.tgl_mulai || '');
        $('#inp_selesai').val(data.tgl_selesai || '');
        $('#inp_deskripsi').val(data.deskripsi || '');
        $('#inp_status').val(data.status);
        $('#modalProjek').modal('show');
    });

    // Detail & Anggota Projek (Fitur 19)
    $(document).on('click', '.btn-anggota-projek', function() {
        var data = $(this).data('json');
        var pid = data.id;
        $('#mdl_projek_id_anggota').val(pid);
        $('#mdl_projek_nama').text(data.nama_projek);
        $('#mdl_projek_tema').text(data.tema);

        loadTabelAnggota(pid);
        $('#modalAnggotaProjek').modal('show');
    });

    function loadTabelAnggota(pid) {
        $('#bodyTabelAnggota').html('<tr><td colspan="7" class="text-center p-3"><i class="fas fa-spinner fa-spin"></i> Memuat anggota...</td></tr>');
        $.ajax({
            url: 'projek_kokurikuler.php?ajax_anggota=1&id_projek=' + pid,
            dataType: 'json',
            success: function(list) {
                if (!list || !list.length) {
                    $('#bodyTabelAnggota').html('<tr><td colspan="7" class="text-center text-muted">Belum ada anggota yang terdaftar pada projek ini.</td></tr>');
                    return;
                }
                var html = '';
                list.forEach(function(item, idx) {
                    var st_badge = item.status === 'Aktif' ? 'success' : (item.status === 'Selesai' ? 'info' : 'secondary');
                    html += '<tr>';
                    html += '<td class="text-center">' + (idx + 1) + '</td>';
                    html += '<td><strong>' + item.nama_siswa + '</strong></td>';
                    html += '<td>' + (item.nisn || '-') + '</td>';
                    html += '<td><span class="badge badge-light border">' + item.peran + '</span></td>';
                    html += '<td><span class="badge badge-primary">' + (item.kelompok || '-') + '</span></td>';
                    html += '<td><small>' + (item.catatan || '-') + '</small></td>';
                    html += '<td class="text-center"><span class="badge badge-' + st_badge + '">' + item.status + '</span></td>';
                    html += '<td class="text-center"><button type="button" class="btn btn-danger btn-sm py-0 px-1 btn-hapus-anggota" data-id="' + item.id + '"><i class="fas fa-times"></i></button></td>';
                    html += '</tr>';
                });
                $('#bodyTabelAnggota').html(html);
            }
        });
    }

    $(document).on('click', '.btn-hapus-anggota', function() {
        var id = $(this).data('id');
        var pid = $('#mdl_projek_id_anggota').val();
        Swal.fire({
            title: 'Keluarkan siswa?',
            text: 'Siswa akan dikeluarkan dari projek ini.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Ya, Keluarkan',
            cancelButtonText: 'Batal'
        }).then(function(res) {
            if (res.isConfirmed) {
                $('#formHapusAnggotaId').val(id);
                $('#formHapusAnggota').submit();
            }
        });
    });

    $(document).on('click', '.btn-hapus-projek', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        Swal.fire({
            title: 'Hapus Projek?',
            text: 'Projek "' + nama + '" dan seluruh data kelompok/anggota akan dihapus.',
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
            <h1>Daftar Projek / Kokurikuler <?= !empty($wali_kelas_name) ? '- Kelas ' . htmlspecialchars($wali_kelas_name) : '' ?></h1>
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
                    <h4>Projek P5 / Kokurikuler Kelas</h4>
                    <button type="button" class="btn btn-primary" id="btnTambahProjek">
                        <i class="fas fa-plus mr-1"></i> Buat Projek Baru
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered table-sm" id="table-projek">
                            <thead>
                                <tr>
                                    <th width="4%">No</th>
                                    <th>Nama Projek</th>
                                    <th>Tema</th>
                                    <th>Kelas</th>
                                    <th>Pembimbing</th>
                                    <th>Tanggal Mulai</th>
                                    <th>Tanggal Selesai</th>
                                    <th>Jumlah Peserta</th>
                                    <th>Status</th>
                                    <th width="14%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $i => $r): ?>
                                    <?php
                                    $st_badge = 'secondary';
                                    if ($r['status'] === 'Berjalan') $st_badge = 'info';
                                    elseif ($r['status'] === 'Selesai') $st_badge = 'success';
                                    elseif ($r['status'] === 'Perencanaan') $st_badge = 'warning';
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td><strong><?= htmlspecialchars($r['nama_projek']) ?></strong></td>
                                        <td><span class="badge badge-light border"><?= htmlspecialchars($r['tema']) ?></span></td>
                                        <td><?= htmlspecialchars($r['nama_kelas'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['pembimbing'] ?: '-') ?></td>
                                        <td><?= !empty($r['tgl_mulai']) ? date('d/m/Y', strtotime($r['tgl_mulai'])) : '-' ?></td>
                                        <td><?= !empty($r['tgl_selesai']) ? date('d/m/Y', strtotime($r['tgl_selesai'])) : '-' ?></td>
                                        <td class="text-center">
                                            <span class="badge badge-primary"><?= (int)$r['jumlah_peserta'] ?> Siswa</span>
                                        </td>
                                        <td class="text-center"><span class="badge badge-<?= $st_badge ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-info btn-sm btn-anggota-projek" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Kelola Anggota & Kelompok">
                                                <i class="fas fa-users mr-1"></i> Anggota
                                            </button>
                                            <button type="button" class="btn btn-warning btn-sm btn-edit-projek" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm btn-hapus-projek" data-id="<?= (int)$r['id'] ?>" data-nama="<?= htmlspecialchars($r['nama_projek'], ENT_QUOTES) ?>" title="Hapus">
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

<!-- Modal Form Tambah / Edit Projek -->
<div class="modal fade" id="modalProjek" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formProjek">
                <input type="hidden" name="action" id="formProjekAction" value="tambah_projek">
                <input type="hidden" name="id" id="projekId" value="">
                <input type="hidden" name="id_kelas" value="<?= (int)$selected_kelas_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalProjekTitle">Projek / Kokurikuler</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-8 form-group">
                            <label>Nama Projek <span class="text-danger">*</span></label>
                            <input type="text" name="nama_projek" id="inp_nama" class="form-control" required placeholder="Contoh: Pembuatan Pupuk Kompos Organik">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Tema <span class="text-danger">*</span></label>
                            <input type="text" name="tema" id="inp_tema" class="form-control" required placeholder="Gaya Hidup Berkelanjutan">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Pembimbing / Fasilitator</label>
                            <input type="text" name="pembimbing" id="inp_pembimbing" class="form-control" placeholder="Nama Guru Pembimbing">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Tanggal Mulai</label>
                            <input type="date" name="tgl_mulai" id="inp_mulai" class="form-control">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Tanggal Selesai</label>
                            <input type="date" name="tgl_selesai" id="inp_selesai" class="form-control">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Status</label>
                            <select name="status" id="inp_status" class="form-control">
                                <?php foreach ($status_projek_options as $st): ?>
                                    <option value="<?= $st ?>"><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 form-group">
                            <label>Deskripsi / Tujuan Projek</label>
                            <textarea name="deskripsi" id="inp_deskripsi" class="form-control" rows="3" placeholder="Deskripsi aktivitas atau dimensi profil lulusan yang dikembangkan..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan Projek</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Anggota Projek (Fitur 19) -->
<div class="modal fade" id="modalAnggotaProjek" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title"><i class="fas fa-users-cog mr-2"></i>Kelola Anggota & Kelompok Projek</h5>
                    <small class="text-muted"><strong id="mdl_projek_nama"></strong> | Tema: <span id="mdl_projek_tema"></span></small>
                </div>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <!-- Form Tambah Siswa ke Projek -->
                <div class="card card-primary mb-3">
                    <div class="card-header py-2">
                        <h6 class="mb-0">Tambah / Atur Siswa ke Projek</h6>
                    </div>
                    <div class="card-body p-3">
                        <form method="POST" class="row">
                            <input type="hidden" name="action" value="tambah_anggota">
                            <input type="hidden" name="id_projek" id="mdl_projek_id_anggota">
                            <div class="col-md-4 form-group mb-2">
                                <label class="small font-weight-bold">Pilih Siswa</label>
                                <select name="id_siswa" class="form-control form-control-sm" required>
                                    <option value="">-- Pilih Siswa --</option>
                                    <?php foreach ($siswa_list as $s): ?>
                                        <option value="<?= (int)$s['id_siswa'] ?>"><?= htmlspecialchars($s['nama_siswa']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 form-group mb-2">
                                <label class="small font-weight-bold">Kelompok</label>
                                <input type="text" name="kelompok" class="form-control form-control-sm" value="Kelompok 1" placeholder="Kelompok 1">
                            </div>
                            <div class="col-md-2 form-group mb-2">
                                <label class="small font-weight-bold">Peran</label>
                                <select name="peran" class="form-control form-control-sm">
                                    <?php foreach ($peran_options as $pr): ?>
                                        <option value="<?= $pr ?>"><?= $pr ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 form-group mb-2">
                                <label class="small font-weight-bold">Catatan Khusus</label>
                                <input type="text" name="catatan" class="form-control form-control-sm" placeholder="Catatan tugas / kontribusi">
                            </div>
                            <div class="col-md-1 form-group mb-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary btn-sm btn-block"><i class="fas fa-plus"></i></button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tabel Anggota Projek -->
                <div class="table-responsive">
                    <table class="table table-striped table-bordered table-sm mb-0">
                        <thead>
                            <tr>
                                <th width="4%">No</th>
                                <th>Nama Siswa</th>
                                <th>NIS/NISN</th>
                                <th>Peran</th>
                                <th>Kelompok</th>
                                <th>Catatan</th>
                                <th>Status</th>
                                <th width="6%" class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="bodyTabelAnggota">
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<form method="POST" id="formHapus" class="d-none">
    <input type="hidden" name="action" value="hapus_projek">
    <input type="hidden" name="id" id="formHapusId">
</form>

<form method="POST" id="formHapusAnggota" class="d-none">
    <input type="hidden" name="action" value="hapus_anggota">
    <input type="hidden" name="id_anggota" id="formHapusAnggotaId">
</form>

<?php include '../templates/footer.php'; ?>
