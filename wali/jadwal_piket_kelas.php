<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['wali', 'admin'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$can_crud = !in_array($user_level, ['admin', 'kepala_madrasah'], true);
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
$selected_kelas_name = $wali_kelas_name;
if ($user_level === 'admin') {
    $selected_kelas_id = (int)($_GET['kelas'] ?? 0);
    $selected_kelas_name = '';
    foreach ($all_classes as $c) {
        if ((int)$c['id_kelas'] === $selected_kelas_id) {
            $selected_kelas_name = (string)$c['nama_kelas'];
            break;
        }
    }
}

$days_order = getUrutanHariJadwalSekolah($pdo);

$message = null;

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_crud) {
        $message = ['type' => 'danger', 'text' => 'Akses ditolak. Pengguna hanya memiliki akses lihat (monitoring).'];
    } else {
        $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $id_kelas = (int)($_POST['id_kelas'] ?? $selected_kelas_id);
        $hari = trim((string)($_POST['hari'] ?? 'Senin'));
        $id_siswa = (int)($_POST['id_siswa'] ?? 0);
        $tugas = trim((string)($_POST['tugas'] ?? 'Piket Umum'));
        $urutan = (int)($_POST['urutan'] ?? 1);
        $status = in_array($_POST['status'] ?? '', ['Aktif', 'Nonaktif'], true) ? $_POST['status'] : 'Aktif';

        if ($id_siswa <= 0 || $hari === '') {
            $message = ['type' => 'warning', 'text' => 'Pilih Siswa dan Hari Piket.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_jadwal_piket_kelas (
                            id_wali, id_kelas, hari, id_siswa, tugas, urutan, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$guru_id, $id_kelas, $hari, $id_siswa, $tugas, $urutan, $status]);
                    $message = ['type' => 'success', 'text' => 'Jadwal piket siswa berhasil ditambahkan.'];
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE tb_jadwal_piket_kelas SET
                            id_kelas = ?, hari = ?, id_siswa = ?, tugas = ?, urutan = ?, status = ?
                        WHERE id = ? " . ($user_level !== 'admin' ? "AND id_wali = $guru_id" : "") . "
                    ");
                    $stmt->execute([$id_kelas, $hari, $id_siswa, $tugas, $urutan, $status, $id]);
                    $message = ['type' => 'success', 'text' => 'Jadwal piket berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM tb_jadwal_piket_kelas WHERE id = ? " . ($user_level !== 'admin' ? "AND id_wali = $guru_id" : ""))->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Jadwal piket berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
}
}

$siswa_list = [];
$rows = [];
if ($selected_kelas_id > 0) {
    $stS = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
    $stS->execute([$selected_kelas_id]);
    $siswa_list = $stS->fetchAll(PDO::FETCH_ASSOC);

// Fetch piket rows
$where = ["1=1"];
$params = [];
$where[] = "p.id_kelas = ?";
$params[] = $selected_kelas_id;
if ($user_level !== 'admin') {
    $where[] = "p.id_wali = ?";
    $params[] = $guru_id;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT p.*, s.nama_siswa, s.nisn, k.nama_kelas
    FROM tb_jadwal_piket_kelas p
    JOIN tb_siswa s ON s.id_siswa = p.id_siswa
    LEFT JOIN tb_kelas k ON k.id_kelas = p.id_kelas
    WHERE $where_sql
    ORDER BY p.hari ASC, p.urutan ASC, s.nama_siswa ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Kelompokkan per hari untuk tampilan Kartu Mingguan
$piket_by_day = [];
foreach ($days_order as $d) {
    $piket_by_day[$d] = [];
}
foreach ($rows as $r) {
    if ($r['status'] === 'Aktif') {
        $h = $r['hari'];
        if (!isset($piket_by_day[$h])) $piket_by_day[$h] = [];
        $piket_by_day[$h][] = $r;
    }
}

$page_title = 'Jadwal Piket Kelas';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-piket').length) {
        $('#table-piket').DataTable({
            'order': [[0, 'asc'], [3, 'asc']],
            'columnDefs': [{ 'sortable': false, 'targets': [5] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada data piket',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari Siswa:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    $('#btnTambahPiket').on('click', function() {
        $('#formPiketAction').val('tambah');
        $('#piketId').val('');
        $('#modalPiketTitle').text('Tambah Petugas Piket');
        $('#formPiket')[0].reset();
        $('#modalPiket').modal('show');
    });

    $(document).on('click', '.btn-edit-piket', function() {
        var data = $(this).data('json');
        $('#formPiketAction').val('edit');
        $('#piketId').val(data.id);
        $('#modalPiketTitle').text('Edit Petugas Piket');
        $('#inp_hari').val(data.hari);
        $('#inp_siswa').val(data.id_siswa);
        $('#inp_tugas').val(data.tugas);
        $('#inp_urutan').val(data.urutan);
        $('#inp_status').val(data.status);
        $('#modalPiket').modal('show');
    });

    $(document).on('click', '.btn-hapus-piket', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        Swal.fire({
            title: 'Hapus Petugas Piket?',
            text: nama + ' akan dihapus dari jadwal piket.',
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
            <h1>Jadwal Piket Kelas <?= $user_level === 'admin' ? (!empty($selected_kelas_name) ? '- Kelas ' . htmlspecialchars($selected_kelas_name) : '') : (!empty($wali_kelas_name) ? '- Kelas ' . htmlspecialchars($wali_kelas_name) : '') ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <?php if ($user_level === 'admin'): ?>
            <div class="card">
                <div class="card-header">
                    <h4>Filter Kelas</h4>
                </div>
                <div class="card-body">
                    <form method="GET" class="form-inline">
                        <label class="mr-2" for="selectKelasPiket">Pilih Kelas:</label>
                        <select name="kelas" id="selectKelasPiket" class="form-control" style="min-width: 220px;" onchange="this.form.submit();">
                            <option value="">-- Pilih Kelas --</option>
                            <?php foreach ($all_classes as $c): ?>
                                <option value="<?= (int)$c['id_kelas'] ?>" <?= $selected_kelas_id === (int)$c['id_kelas'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nama_kelas']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($selected_kelas_id > 0 || $user_level !== 'admin'): ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <ul class="nav nav-pills" id="piketModeTab" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="tab-kartu-link" data-toggle="tab" href="#tab-kartu" role="tab"><i class="fas fa-th-large mr-1"></i> Tampilan Kartu Mingguan</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="tab-tabel-link" data-toggle="tab" href="#tab-tabel" role="tab"><i class="fas fa-table mr-1"></i> Tampilan Tabel</a>
                        </li>
                    </ul>
                    <div>
                        <button type="button" class="btn btn-outline-primary mr-2" onclick="window.print()">
                            <i class="fas fa-print mr-1"></i> Cetak Jadwal
                        </button>
                        <?php if ($can_crud): ?>
                        <button type="button" class="btn btn-primary" id="btnTambahPiket" <?= empty($siswa_list) && $user_level !== 'admin' ? 'disabled' : '' ?>>
                            <i class="fas fa-plus mr-1"></i> Tambah Petugas
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-body">
                    <div class="tab-content" id="piketTabContent">
                        <!-- Tampilan Kartu Mingguan -->
                        <div class="tab-pane fade show active" id="tab-kartu" role="tabpanel">
                            <div class="row">
                                <?php foreach ($days_order as $hari): ?>
                                    <?php $petugas = $piket_by_day[$hari] ?? []; ?>
                                    <div class="col-md-4 col-sm-6 mb-4">
                                        <div class="card shadow-sm h-100 border">
                                            <div class="card-header bg-primary text-white py-2 justify-content-between">
                                                <h5 class="mb-0 text-white font-weight-bold" style="font-size: 16px;">
                                                    <i class="fas fa-calendar-day mr-1"></i> <?= strtoupper($hari) ?>
                                                </h5>
                                                <span class="badge badge-light text-primary"><?= count($petugas) ?> Siswa</span>
                                            </div>
                                            <div class="card-body p-3 font-monospace" style="font-family: 'Consolas', 'Courier New', monospace; font-size: 13px;">
                                                <?php if (empty($petugas)): ?>
                                                    <div class="text-muted text-center p-3">Belum ada petugas piket.</div>
                                                <?php else: ?>
                                                    <div class="font-weight-bold text-dark mb-1"><?= strtoupper($hari) ?></div>
                                                    <?php foreach ($petugas as $idx => $p): ?>
                                                        <?php
                                                        $is_last = ($idx === count($petugas) - 1);
                                                        $branch = $is_last ? '└── ' : '├── ';
                                                        ?>
                                                        <div class="d-flex justify-content-between align-items-center py-1">
                                                            <div>
                                                                <span class="text-secondary"><?= $branch ?></span>
                                                                <strong class="text-dark"><?= htmlspecialchars($p['nama_siswa']) ?></strong>
                                                                <?php if ($p['tugas'] && $p['tugas'] !== 'Piket Umum'): ?>
                                                                    <small class="text-muted">(<?= htmlspecialchars($p['tugas']) ?>)</small>
                                                                <?php endif; ?>
                                                            </div>
                                                             <?php if ($can_crud): ?>
                                                             <div class="no-print">
                                                                 <button type="button" class="btn btn-warning btn-sm py-0 px-1 btn-edit-piket" data-json='<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>'><i class="fas fa-pencil-alt" style="font-size: 10px;"></i></button>
                                                                 <button type="button" class="btn btn-danger btn-sm py-0 px-1 btn-hapus-piket" data-id="<?= (int)$p['id'] ?>" data-nama="<?= htmlspecialchars($p['nama_siswa'], ENT_QUOTES) ?>"><i class="fas fa-times" style="font-size: 10px;"></i></button>
                                                             </div>
                                                             <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Tampilan Tabel -->
                        <div class="tab-pane fade" id="tab-tabel" role="tabpanel">
                            <div class="table-responsive">
                                <table class="table table-striped table-bordered table-sm" id="table-piket">
                                    <thead>
                                        <tr>
                                            <th>Hari</th>
                                            <th>Nama Siswa</th>
                                            <th>Tugas</th>
                                            <th width="8%" class="text-center">Urutan</th>
                                            <th width="10%" class="text-center">Status</th>
                                            <?php if ($can_crud): ?>
                                            <th width="12%" class="text-center">Aksi</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rows as $r): ?>
                                            <tr>
                                                <td><span class="badge badge-light border font-weight-bold"><?= htmlspecialchars($r['hari']) ?></span></td>
                                                <td><strong><?= htmlspecialchars($r['nama_siswa']) ?></strong> (<?= htmlspecialchars($r['nisn'] ?? '-') ?>)</td>
                                                <td><?= htmlspecialchars($r['tugas']) ?></td>
                                                <td class="text-center"><?= (int)$r['urutan'] ?></td>
                                                <td class="text-center">
                                                    <span class="badge badge-<?= $r['status'] === 'Aktif' ? 'success' : 'secondary' ?>"><?= htmlspecialchars($r['status']) ?></span>
                                                </td>
                                                  <?php if ($can_crud): ?>
                                                  <td class="text-center">
                                                     <button type="button" class="btn btn-warning btn-sm btn-edit-piket" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                         <i class="fas fa-edit"></i>
                                                     </button>
                                                     <button type="button" class="btn btn-danger btn-sm btn-hapus-piket" data-id="<?= (int)$r['id'] ?>" data-nama="<?= htmlspecialchars($r['nama_siswa'], ENT_QUOTES) ?>" title="Hapus">
                                                         <i class="fas fa-trash"></i>
                                                     </button>
                                                 </td>
                                                  <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- Modal Form Tambah / Edit -->
<div class="modal fade" id="modalPiket" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <form method="POST" id="formPiket">
                <input type="hidden" name="action" id="formPiketAction" value="tambah">
                <input type="hidden" name="id" id="piketId" value="">
                <input type="hidden" name="id_kelas" value="<?= (int)$selected_kelas_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalPiketTitle">Petugas Piket Kelas</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Hari Piket <span class="text-danger">*</span></label>
                        <select name="hari" id="inp_hari" class="form-control" required>
                            <?php foreach ($days_order as $d): ?>
                                <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
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
                    <div class="form-group">
                        <label>Tugas Spesifik</label>
                        <input type="text" name="tugas" id="inp_tugas" class="form-control" value="Piket Umum" placeholder="Menyapu, Menghapus Papan, Menyiram Tanaman, dll">
                    </div>
                    <div class="form-group">
                        <label>Urutan Penomoran</label>
                        <input type="number" name="urutan" id="inp_urutan" class="form-control" value="1" min="1">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="inp_status" class="form-control">
                            <option value="Aktif">Aktif</option>
                            <option value="Nonaktif">Nonaktif</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan Petugas</button>
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
