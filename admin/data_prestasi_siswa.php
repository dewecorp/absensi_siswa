<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) session_start();

if (!isAuthorized(['admin', 'tata_usaha'])) {
    redirect('../login.php');
}

// Success message from query param
$success_msg = '';
if (isset($_GET['success'])) {
    $msgs = [
        'add' => 'Prestasi berhasil ditambahkan.',
        'edit' => 'Prestasi berhasil diubah.',
        'del' => 'Prestasi berhasil dihapus.'
    ];
    $success_msg = $msgs[$_GET['success']] ?? '';
}

$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];
$page_title = 'Data Prestasi Siswa';
$js_page = ["
var successMsg = " . json_encode($success_msg) . ";

$(document).ready(function() {
    if (successMsg) {
        Swal.fire({ icon: 'success', title: 'Berhasil', text: successMsg, timer: 2000, showConfirmButton: false });
    }
    if ($.fn.DataTable.isDataTable('#table-1')) $('#table-1').DataTable().destroy();
    $('#table-1').DataTable({
        'language': {
            'lengthMenu': 'Tampilkan _MENU_ entri',
            'zeroRecords': 'Tidak ada data',
            'info': 'Menampilkan _START_-_END_ dari _TOTAL_ entri',
            'infoEmpty': '0 entri',
            'infoFiltered': '(filter dari _MAX_)',
            'search': 'Cari:',
            'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
        }
    });
});
"];

// Create table if not exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_prestasi_siswa (
        id_prestasi INT AUTO_INCREMENT PRIMARY KEY,
        nama_siswa VARCHAR(100) NOT NULL,
        prestasi VARCHAR(255) NOT NULL,
        tahun VARCHAR(4) NOT NULL,
        tingkat ENUM('Kecamatan','Kabupaten','Provinsi','Nasional') NOT NULL DEFAULT 'Kecamatan',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Migrasi: jika kolom nama_siswa belum ada (tabel lama pake id_siswa)
    $check = $pdo->query("SHOW COLUMNS FROM tb_prestasi_siswa LIKE 'nama_siswa'");
    if ($check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE tb_prestasi_siswa ADD COLUMN nama_siswa VARCHAR(100) NOT NULL AFTER id_prestasi");
        $pdo->exec("ALTER TABLE tb_prestasi_siswa DROP FOREIGN KEY tb_prestasi_siswa_ibfk_1");
        $pdo->exec("ALTER TABLE tb_prestasi_siswa DROP COLUMN id_siswa");
    }
} catch (PDOException $e) {
    error_log("Setup tb_prestasi_siswa error: " . $e->getMessage());
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $nama_siswa = trim($_POST['nama_siswa']);
        $prestasi = trim($_POST['prestasi']);
        $tahun = trim($_POST['tahun']);
        $tingkat = $_POST['tingkat'];

        if (!$nama_siswa || !$prestasi || !$tahun || !in_array($tingkat, ['Kecamatan', 'Kabupaten', 'Provinsi', 'Nasional'])) {
            $error = 'Data tidak lengkap.';
        } else {
            if ($action === 'add') {
                $stmt = $pdo->prepare("INSERT INTO tb_prestasi_siswa (nama_siswa, prestasi, tahun, tingkat) VALUES (?, ?, ?, ?)");
                $stmt->execute([$nama_siswa, $prestasi, $tahun, $tingkat]);
                logActivity($pdo, $_SESSION['username'] ?? 'admin', 'Prestasi Siswa', "Tambah prestasi: $prestasi");
            } else {
                $id = (int)$_POST['id_prestasi'];
                $stmt = $pdo->prepare("UPDATE tb_prestasi_siswa SET nama_siswa=?, prestasi=?, tahun=?, tingkat=? WHERE id_prestasi=?");
                $stmt->execute([$nama_siswa, $prestasi, $tahun, $tingkat, $id]);
                logActivity($pdo, $_SESSION['username'] ?? 'admin', 'Prestasi Siswa', "Edit prestasi ID: $id");
            }
            $s = $action === 'add' ? 'add' : 'edit';
            $loc = strtok($_SERVER['REQUEST_URI'], '?');
            header('Location: ' . $loc . '?tahun=' . urlencode($tahun) . '&success=' . $s);
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id_prestasi'];
        $stmt = $pdo->prepare("DELETE FROM tb_prestasi_siswa WHERE id_prestasi=?");
        $stmt->execute([$id]);
        logActivity($pdo, $_SESSION['username'] ?? 'admin', 'Prestasi Siswa', "Hapus prestasi ID: $id");
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?tahun=' . urlencode($_POST['tahun'] ?? '') . '&success=del');
        exit;
    }
}

// Fetch years for filter
$stmt_years = $pdo->query("SELECT DISTINCT tahun FROM tb_prestasi_siswa ORDER BY tahun DESC");
$filter_years = $stmt_years->fetchAll(PDO::FETCH_COLUMN);
$selected_tahun = $_GET['tahun'] ?? ($filter_years[0] ?? '');

// Fetch prestasi data
if ($selected_tahun) {
    $stmt = $pdo->prepare("SELECT * FROM tb_prestasi_siswa WHERE tahun = ? ORDER BY nama_siswa ASC");
    $stmt->execute([$selected_tahun]);
} else {
    $stmt = $pdo->query("SELECT * FROM tb_prestasi_siswa ORDER BY tahun DESC, nama_siswa ASC");
}
$prestasi_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary counts per tingkat
$summary = ['Kecamatan' => 0, 'Kabupaten' => 0, 'Provinsi' => 0, 'Nasional' => 0];
foreach ($prestasi_list as $p) {
    if (isset($summary[$p['tingkat']])) $summary[$p['tingkat']]++;
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><i class="fas fa-trophy mr-2"></i>Data Prestasi Siswa</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4>Daftar Prestasi Siswa</h4>
                    <div class="card-header-action">
                        <button class="btn btn-primary" data-toggle="modal" data-target="#addModal"><i class="fas fa-plus"></i> Tambah Prestasi</button>
                        <?php if (!empty($prestasi_list)): ?>
                        <div class="btn-group btn-pill ml-2 overflow-hidden" style="border-radius: 30px;">
                            <button type="button" class="btn btn-danger px-3" onclick="exportToPDF()" style="background-color: #ff5e5e; border: none; border-top-left-radius: 30px; border-bottom-left-radius: 30px;">
                                <i class="fas fa-file-pdf"></i> PDF
                            </button>
                            <button type="button" class="btn btn-success px-3" onclick="exportToExcel()" style="background-color: #47c363; border: none; border-top-right-radius: 30px; border-bottom-right-radius: 30px;">
                                <i class="fas fa-file-excel"></i> Excel
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="GET" class="mb-4">
                        <div class="form-row align-items-end">
                            <div class="col-md-4">
                                <label>Filter Tahun</label>
                                <select name="tahun" class="form-control" onchange="this.form.submit()">
                                    <option value="">-- Semua Tahun --</option>
                                    <?php foreach ($filter_years as $th): ?>
                                        <option value="<?= htmlspecialchars($th) ?>" <?= $selected_tahun == $th ? 'selected' : '' ?>><?= htmlspecialchars($th) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <div class="alert alert-info mb-0 py-2">Jumlah: <strong><?= count($prestasi_list) ?></strong> prestasi</div>
                            </div>
                        </div>
                    </form>

                    <?php if (!empty($prestasi_list)): ?>
                    <div class="row mb-3">
                        <div class="col-12">
                            <div class="d-flex flex-wrap align-items-center" style="gap:8px">
                                <?php if ($selected_tahun): ?><strong class="mr-2"><?= $selected_tahun ?>:</strong><?php endif; ?>
                                <span class="badge badge-secondary p-2">Kecamatan: <?= $summary['Kecamatan'] ?></span>
                                <span class="badge badge-info p-2">Kabupaten: <?= $summary['Kabupaten'] ?></span>
                                <span class="badge badge-primary p-2">Provinsi: <?= $summary['Provinsi'] ?></span>
                                <span class="badge badge-warning p-2">Nasional: <?= $summary['Nasional'] ?></span>
                                <span class="badge badge-dark p-2">Total: <?= count($prestasi_list) ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="table-responsive">
                        <table class="table table-striped" id="table-1">
                            <thead>
                                <tr>
                                    <th class="text-center" style="width:50px">No</th>
                                    <th>Nama</th>
                                    <th>Prestasi</th>
                                    <th class="text-center">Tahun</th>
                                    <th class="text-center">Tingkat</th>
                                    <th class="text-center" style="width:100px">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; foreach ($prestasi_list as $p): ?>
                                <tr>
                                    <td class="text-center"><?= $no++ ?></td>
                                    <td><?= htmlspecialchars($p['nama_siswa']) ?></td>
                                    <td><?= htmlspecialchars($p['prestasi']) ?></td>
                                    <td class="text-center"><?= htmlspecialchars($p['tahun']) ?></td>
                                    <td class="text-center"><span class="badge badge-<?= $p['tingkat'] === 'Nasional' ? 'warning' : ($p['tingkat'] === 'Provinsi' ? 'primary' : ($p['tingkat'] === 'Kabupaten' ? 'info' : 'secondary')) ?>"><?= htmlspecialchars($p['tingkat']) ?></span></td>
                                    <td class="text-center" style="white-space:nowrap">
                                        <button class="btn btn-sm btn-warning px-2" data-toggle="modal" data-target="#editModal"
                                            data-id="<?= $p['id_prestasi'] ?>"
                                            data-nama="<?= htmlspecialchars($p['nama_siswa'], ENT_QUOTES) ?>"
                                            data-prestasi="<?= htmlspecialchars($p['prestasi'], ENT_QUOTES) ?>"
                                            data-tahun="<?= $p['tahun'] ?>"
                                            data-tingkat="<?= $p['tingkat'] ?>" title="Edit"><i class="fas fa-edit"></i></button>
                                        <form method="POST" class="delete-form" style="display:inline">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id_prestasi" value="<?= $p['id_prestasi'] ?>">
                                            <input type="hidden" name="tahun" value="<?= $selected_tahun ?>">
                                            <button class="btn btn-sm btn-danger px-2 delete-btn" type="button" title="Hapus"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($prestasi_list)): ?>
                                <tr><td colspan="6" class="text-center text-muted">Belum ada data prestasi.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Hidden export table -->
                    <?php if (!empty($prestasi_list)): ?>
                    <div id="exportTableContainer" style="display:none">
                        <table border="1">
                            <thead>
                                <tr><th colspan="5" style="text-align:center;font-size:16px;font-weight:bold;">DATA PRESTASI SISWA</th></tr>
                                <tr><th>No</th><th>Nama Siswa</th><th>Prestasi</th><th>Tahun</th><th>Tingkat</th></tr>
                            </thead>
                            <tbody>
                                <?php $no2 = 1; foreach ($prestasi_list as $p): ?>
                                <tr><td><?= $no2++ ?></td><td><?= htmlspecialchars($p['nama_siswa']) ?></td><td><?= htmlspecialchars($p['prestasi']) ?></td><td><?= $p['tahun'] ?></td><td><?= $p['tingkat'] ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <form id="exportForm" method="GET" action="export_prestasi_siswa_pdf.php" target="_blank">
                        <input type="hidden" name="tahun" value="<?= $selected_tahun ?>">
                        <input type="hidden" name="table_data" id="table_data">
                        <input type="hidden" name="report_title" value="DATA PRESTASI SISWA">
                        <input type="hidden" name="filename" value="data_prestasi_siswa_<?= $selected_tahun ?>">
                        <input type="hidden" name="tahun_export" value="<?= $selected_tahun ?>">
                        <input type="hidden" name="session_type" value="admin">
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Modal Add -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Tambah Prestasi</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label>Nama</label>
                        <input type="text" name="nama_siswa" class="form-control" placeholder="Nama siswa/kelompok" required>
                    </div>
                    <div class="form-group">
                        <label>Prestasi</label>
                        <input type="text" name="prestasi" class="form-control" placeholder="Contoh: Juara 1 OSN Matematika" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Tahun</label>
                            <input type="text" name="tahun" class="form-control" placeholder="2026" maxlength="4" pattern="\d{4}" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Tingkat</label>
                            <select name="tingkat" class="form-control" required>
                                <option value="Kecamatan">Kecamatan</option>
                                <option value="Kabupaten">Kabupaten</option>
                                <option value="Provinsi">Provinsi</option>
                                <option value="Nasional">Nasional</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Edit Prestasi</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id_prestasi" id="edit_id">
                    <div class="form-group">
                        <label>Nama</label>
                        <input type="text" name="nama_siswa" id="edit_nama" class="form-control" placeholder="Nama siswa/kelompok" required>
                    </div>
                    <div class="form-group">
                        <label>Prestasi</label>
                        <input type="text" name="prestasi" id="edit_prestasi" class="form-control" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Tahun</label>
                            <input type="text" name="tahun" id="edit_tahun" class="form-control" maxlength="4" pattern="\d{4}" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Tingkat</label>
                            <select name="tingkat" id="edit_tingkat" class="form-control" required>
                                <option value="Kecamatan">Kecamatan</option>
                                <option value="Kabupaten">Kabupaten</option>
                                <option value="Provinsi">Provinsi</option>
                                <option value="Nasional">Nasional</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php
$js_page[] = "
$(document).ready(function() {
    $('#editModal').on('show.bs.modal', function(e) {
        var btn = $(e.relatedTarget);
        $('#edit_id').val(btn.data('id'));
        $('#edit_nama').val(btn.data('nama'));
        $('#edit_prestasi').val(btn.data('prestasi'));
        $('#edit_tahun').val(btn.data('tahun'));
        $('#edit_tingkat').val(btn.data('tingkat'));
    });

    $(document).on('click', '.delete-btn', function() {
        var form = $(this).closest('form');
        Swal.fire({
            title: 'Hapus Prestasi?',
            text: 'Data yang dihapus tidak bisa dikembalikan.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then(function(result) {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
});
function exportToPDF() {
    document.getElementById('exportForm').submit();
}
function exportToExcel() {
    var html = document.getElementById('exportTableContainer').innerHTML;
    document.getElementById('table_data').value = html;
    var f = document.getElementById('exportForm');
    f.action = 'export_prestasi_siswa_excel.php';
    f.method = 'POST';
    f.submit();
}
";
include '../templates/footer.php';
?>
