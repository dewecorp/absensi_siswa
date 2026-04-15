<?php
// Determine session name before including functions.php
if (isset($_GET['session_type'])) {
    $type = $_GET['session_type'];
    $session_name = 'SIS_LOGIN';
    if ($type == 'admin') $session_name = 'SIS_ADMIN';
    elseif ($type == 'tata_usaha') $session_name = 'SIS_TU';
    elseif ($type == 'kepala_madrasah' || $type == 'kepala') $session_name = 'SIS_KEPALA';
    
    if (session_status() == PHP_SESSION_NONE) {
        $save_path = sys_get_temp_dir();
        if (is_string($save_path) && $save_path !== '') {
            session_save_path($save_path);
        }
        session_name($session_name);
        session_start();
    }
}

require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has proper level
if (!isAuthorized(['admin', 'tata_usaha', 'kepala_madrasah'])) {
    redirect('../login.php');
}

// Get school profile
$school_profile = getSchoolProfile($pdo);

// Set page title
$page_title = 'Data Inventaris Sarpras';

// Get user level
$user_level = getUserLevel();
$is_admin = ($user_level === 'admin');

// Handle Form Submission (Admin only)
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $is_admin) {
    if (isset($_POST['action'])) {
        try {
            if ($_POST['action'] == 'add') {
                $jumlah = (int) $_POST['jumlah'];
                // Remove dots (thousand separators) and convert to float
                $harga_satuan_raw = $_POST['harga_satuan_value'] ?? $_POST['harga_satuan'];
                $harga_satuan = (float) str_replace('.', '', $harga_satuan_raw);
                $total = $jumlah * $harga_satuan;
                
                $stmt = $pdo->prepare("INSERT INTO tb_inventaris (id_kategori, nama_inventaris, jumlah, luas, harga_satuan, total, status, kondisi, keterangan) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $_POST['id_kategori'],
                    $_POST['nama_inventaris'],
                    $jumlah,
                    $_POST['luas'],
                    $harga_satuan,
                    $total,
                    $_POST['status'],
                    $_POST['kondisi'],
                    $_POST['keterangan']
                ]);
                $message = ['type' => 'success', 'text' => 'Inventaris berhasil ditambahkan!'];
            } elseif ($_POST['action'] == 'edit') {
                $jumlah = (int) $_POST['jumlah'];
                // Remove dots (thousand separators) and convert to float
                $harga_satuan_raw = $_POST['harga_satuan_value'] ?? $_POST['harga_satuan'];
                $harga_satuan = (float) str_replace('.', '', $harga_satuan_raw);
                $total = $jumlah * $harga_satuan;
                
                $stmt = $pdo->prepare("UPDATE tb_inventaris SET id_kategori = ?, nama_inventaris = ?, jumlah = ?, luas = ?, harga_satuan = ?, total = ?, status = ?, kondisi = ?, keterangan = ? WHERE id = ?");
                $stmt->execute([
                    $_POST['id_kategori'],
                    $_POST['nama_inventaris'],
                    $jumlah,
                    $_POST['luas'],
                    $harga_satuan,
                    $total,
                    $_POST['status'],
                    $_POST['kondisi'],
                    $_POST['keterangan'],
                    $_POST['id']
                ]);
                $message = ['type' => 'success', 'text' => 'Inventaris berhasil diperbarui!'];
            } elseif ($_POST['action'] == 'delete') {
                $stmt = $pdo->prepare("DELETE FROM tb_inventaris WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                $message = ['type' => 'success', 'text' => 'Inventaris berhasil dihapus!'];
            }
            header("Location: data_inventaris.php?msg=" . urlencode($message['text']));
            exit;
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Terjadi kesalahan: ' . $e->getMessage()];
        }
    }
}

if (isset($_GET['msg'])) {
    $message = ['type' => 'success', 'text' => $_GET['msg']];
}

// Get Summary Statistics
$stmt = $pdo->query("SELECT 
    SUM(CASE WHEN kondisi = 'Baik' THEN 1 ELSE 0 END) as total_baik,
    SUM(CASE WHEN kondisi = 'Rusak' THEN 1 ELSE 0 END) as total_rusak,
    COALESCE(SUM(total), 0) as total_nilai_aset
FROM tb_inventaris");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get Categories
$stmt = $pdo->query("SELECT * FROM tb_kategori_inventaris ORDER BY nama_kategori ASC");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Inventory Data - Grouped by Category
$stmt = $pdo->query("
    SELECT i.*, k.nama_kategori 
    FROM tb_inventaris i 
    LEFT JOIN tb_kategori_inventaris k ON i.id_kategori = k.id 
    ORDER BY k.nama_kategori ASC, i.created_at DESC
");
$inventories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group inventories by category
$grouped_inventories = [];
$category_totals = [];
foreach ($inventories as $inv) {
    $kategori = $inv['nama_kategori'] ?? 'Tanpa Kategori';
    if (!isset($grouped_inventories[$kategori])) {
        $grouped_inventories[$kategori] = [];
        $category_totals[$kategori] = 0;
    }
    $grouped_inventories[$kategori][] = $inv;
    $category_totals[$kategori] += $inv['total'];
}

// Add DataTables CSS and JS
if (!isset($css_libs)) $css_libs = [];
$css_libs[] = 'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css';
if (!isset($js_libs)) $js_libs = [];
$js_libs[] = 'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js';
$js_libs[] = 'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js';

// Prepare category totals JSON for JavaScript
$category_totals_json = json_encode($category_totals);

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<style>
.badge-lg {
    font-size: 14px;
    padding: 6px 12px;
}
.table-info {
    background-color: #e3f2fd !important;
}
.table-info td {
    padding: 12px !important;
}
/* Ensure table is full width */
#table-inventaris {
    width: 100% !important;
}
/* Group header styling */
.group-row td {
    background-color: #e3f2fd !important;
    border-bottom: 2px solid #90caf9 !important;
}
/* Make DataTables full width */
.dataTables_wrapper {
    width: 100% !important;
}
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Data Inventaris Sarpras</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Data Inventaris Sarpras</div>
            </div>
        </div>

        <div class="section-body">
            <!-- Summary Cards -->
            <div class="row">
                <div class="col-lg-4 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1">
                        <div class="card-icon bg-primary">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4>Inventaris Baik</h4>
                            </div>
                            <div class="card-body">
                                <?php echo number_format($stats['total_baik'] ?? 0); ?> Unit
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1">
                        <div class="card-icon bg-danger">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4>Inventaris Rusak</h4>
                            </div>
                            <div class="card-body">
                                <?php echo number_format($stats['total_rusak'] ?? 0); ?> Unit
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 col-sm-6 col-12">
                    <div class="card card-statistic-1">
                        <div class="card-icon bg-success">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4>Total Nilai Aset</h4>
                            </div>
                            <div class="card-body">
                                <?php echo number_format($stats['total_nilai_aset'] ?? 0, 0, ',', '.'); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Inventory Table -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Daftar Inventaris Sarpras</h4>
                            <div class="card-header-action">
                                <div class="btn-group mr-2">
                                    <a href="../config/export_inventaris_pdf.php?session_type=<?= urlencode($user_level) ?>" target="_blank" class="btn btn-danger">
                                        <i class="fas fa-file-pdf"></i> Export PDF
                                    </a>
                                    <a href="../config/export_inventaris_excel.php?session_type=<?= urlencode($user_level) ?>" target="_blank" class="btn btn-success">
                                        <i class="fas fa-file-excel"></i> Export Excel
                                    </a>
                                </div>
                                <?php if ($is_admin): ?>
                                <button class="btn btn-primary" data-toggle="modal" data-target="#modalAdd">
                                    <i class="fas fa-plus"></i> Tambah Inventaris
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-bordered" id="table-inventaris">
                                    <thead>
                                        <tr>
                                            <th class="text-center" width="5%">No</th>
                                            <th class="text-center">Kategori</th>
                                            <th class="text-center">Nama Inventaris</th>
                                            <th class="text-center" width="8%">Jumlah</th>
                                            <th class="text-center" width="10%">Luas (m²)</th>
                                            <th class="text-center" width="12%">Harga Satuan</th>
                                            <th class="text-center" width="12%">Total</th>
                                            <th class="text-center" width="10%">Status</th>
                                            <th class="text-center" width="10%">Kondisi</th>
                                            <?php if ($is_admin): ?>
                                            <th class="text-center" width="12%">Aksi</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($inventories) > 0): ?>
                                            <?php foreach ($inventories as $idx => $row): ?>
                                            <tr>
                                                <td class="text-center"></td>
                                                <td><?php echo htmlspecialchars($row['nama_kategori'] ?? 'Tanpa Kategori'); ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($row['nama_inventaris']); ?></strong>
                                                </td>
                                                <td class="text-center"><?php echo number_format($row['jumlah']); ?></td>
                                                <td class="text-center"><?php echo $row['luas'] ? number_format($row['luas'], 0, ',', '.') : '-'; ?></td>
                                                <td class="text-right"><?php echo number_format($row['harga_satuan'], 0, ',', '.'); ?></td>
                                                <td class="text-right"><?php echo number_format($row['total'], 0, ',', '.'); ?></td>
                                                <td class="text-center">
                                                    <?php if ($row['status'] == 'Sertifikat'): ?>
                                                        <span class="badge badge-primary">Sertifikat</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary">Milik Sendiri</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($row['kondisi'] == 'Baik'): ?>
                                                        <span class="badge badge-success">Baik</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-danger">Rusak</span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php if ($is_admin): ?>
                                                <td class="text-center">
                                                    <button class="btn btn-warning btn-sm btn-edit" 
                                                            data-id="<?php echo $row['id']; ?>"
                                                            data-toggle="modal" 
                                                            data-target="#modalEdit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-danger btn-sm btn-delete" 
                                                            data-id="<?php echo $row['id']; ?>" 
                                                            data-nama="<?php echo htmlspecialchars($row['nama_inventaris']); ?>"
                                                            data-toggle="modal" 
                                                            data-target="#modalDelete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </td>
                                                <?php endif; ?>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="<?php echo $is_admin ? '10' : '9'; ?>" class="text-center">Tidak ada data inventaris</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Hidden data for edit modal -->
<?php if ($is_admin): ?>
<script type="text/javascript">
    var inventoriesData = <?php echo json_encode($inventories); ?>;
</script>

<!-- Add Modal -->
<div class="modal fade" id="modalAdd" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form action="" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Inventaris</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Kategori</label>
                                <select class="form-control" name="id_kategori" required>
                                    <option value="">Pilih Kategori</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['nama_kategori']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Nama Inventaris</label>
                                <input type="text" class="form-control" name="nama_inventaris" placeholder="Masukkan nama inventaris" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Jumlah</label>
                                <input type="number" class="form-control" name="jumlah" id="add_jumlah" min="1" value="1" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Luas (m²)</label>
                                <input type="number" class="form-control" name="luas" step="0.01" placeholder="Opsional">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Harga Satuan (Rp)</label>
                                <input type="text" class="form-control" name="harga_satuan" id="add_harga_satuan" placeholder="0" required>
                                <input type="hidden" name="harga_satuan_value" id="add_harga_satuan_value">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Status</label>
                                <select class="form-control" name="status" required>
                                    <option value="Sertifikat">Sertifikat</option>
                                    <option value="Milik Sendiri">Milik Sendiri</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Kondisi</label>
                                <select class="form-control" name="kondisi" required>
                                    <option value="Baik">Baik</option>
                                    <option value="Rusak">Rusak</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Total</label>
                                <input type="text" class="form-control" id="add_total" readonly style="background-color: #e9ecef;">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="modalEdit" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form action="" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Inventaris</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Kategori</label>
                                <select class="form-control" name="id_kategori" id="edit_id_kategori" required>
                                    <option value="">Pilih Kategori</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['nama_kategori']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Nama Inventaris</label>
                                <input type="text" class="form-control" name="nama_inventaris" id="edit_nama_inventaris" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Jumlah</label>
                                <input type="number" class="form-control" name="jumlah" id="edit_jumlah" min="1" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Luas (m²)</label>
                                <input type="number" class="form-control" name="luas" id="edit_luas" step="0.01">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Harga Satuan (Rp)</label>
                                <input type="text" class="form-control" name="harga_satuan" id="edit_harga_satuan" required>
                                <input type="hidden" name="harga_satuan_value" id="edit_harga_satuan_value">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Status</label>
                                <select class="form-control" name="status" id="edit_status" required>
                                    <option value="Sertifikat">Sertifikat</option>
                                    <option value="Milik Sendiri">Milik Sendiri</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Kondisi</label>
                                <select class="form-control" name="kondisi" id="edit_kondisi" required>
                                    <option value="Baik">Baik</option>
                                    <option value="Rusak">Rusak</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Total</label>
                                <input type="text" class="form-control" id="edit_total" readonly style="background-color: #e9ecef;">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="modalDelete" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Hapus Inventaris</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="delete_id">
                    <p>Apakah Anda yakin ingin menghapus inventaris <strong id="delete_nama_inventaris"></strong>?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger">Hapus</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include '../templates/footer.php'; ?>

<?php if ($message): ?>
<script type="text/javascript">
Swal.fire({ icon: '<?php echo $message['type'] == 'danger' ? 'error' : 'success'; ?>', title: '<?php echo $message['text']; ?>', timer: 2000, showConfirmButton: false });
</script>
<?php endif; ?>

<!-- Store category totals as data attribute -->
<div id="category-totals-data" style="display:none;" data-totals='<?php echo htmlspecialchars($category_totals_json, ENT_QUOTES); ?>'></div>

<script type="text/javascript">
    // Load category totals from data attribute
    var categoryTotals = {};
    try {
        var dataElement = document.getElementById('category-totals-data');
        if (dataElement && dataElement.dataset.totals) {
            categoryTotals = JSON.parse(dataElement.dataset.totals);
        }
    } catch(e) {
        console.error('Error loading category totals:', e);
    }

$(document).ready(function() {
    // Initialize DataTable with row grouping
    var table = $('#table-inventaris').DataTable({
        "order": [[1, 'asc'], [0, 'asc']], // Sort by category, then by name
        "pageLength": 25,
        "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Semua"]],
        "columnDefs": [
            { "orderable": false, "targets": -1 }, // Disable sorting on action column
            { "visible": false, "targets": 1 } // Hide category column
        ],
        "drawCallback": function(settings) {
            var api = this.api();
            var rows = api.rows({ page: 'current' }).nodes();
            var last = null;
            
            console.log('Category totals loaded:', categoryTotals);
            console.log('Total rows:', rows.length);
            
            // Add row numbers
            api.column(0, { page: 'current' }).data().each(function(value, i) {
                var index = api.row(rows[i]).index() + 1;
                $(rows[i]).find('td:eq(0)').text(index);
            });
            
            // Group by category (column 1)
            api.column(1, { page: 'current' }).data().each(function(group, i) {
                if (last !== group) {
                    var total = categoryTotals[group] || 0;
                    var totalFormatted = 'Rp ' + total.toLocaleString('id-ID');
                    console.log('Adding group header for:', group, 'Total:', totalFormatted);
                    
                    // Count visible columns (exclude hidden category column)
                    var visibleCols = $('#table-inventaris thead th').filter(':visible').length;
                    
                    $(rows).eq(i).before(
                        '<tr class="group-row table-info">' +
                            '<td colspan="' + visibleCols + '" class="font-weight-bold">' +
                                '<span class="badge badge-primary badge-lg">' + group + '</span>' +
                                '<span style="float: right; font-size: 14px;">' +
                                    '<i class="fas fa-money-bill-wave mr-1"></i>' +
                                    'Total: <strong>' + totalFormatted + '</strong>' +
                                '</span>' +
                            '</td>' +
                        '</tr>'
                    );
                    last = group;
                }
            });
        },
        "language": {
            "search": "Cari:",
            "lengthMenu": "Tampilkan _MENU_ item",
            "info": "Menampilkan _START_ - _END_ dari _TOTAL_ item",
            "infoEmpty": "Tidak ada data",
            "infoFiltered": "(difilter dari _MAX_ total item)",
            "paginate": {
                "first": "Pertama",
                "last": "Terakhir",
                "next": "Selanjutnya",
                "previous": "Sebelumnya"
            }
        }
    });
    
    // Format currency input for add modal
    $('#add_harga_satuan').on('input', function() {
        var value = $(this).val().replace(/[^0-9]/g, '');
        if (value) {
            $(this).val(parseInt(value).toLocaleString('id-ID'));
            $('#add_harga_satuan_value').val(value);
        } else {
            $('#add_harga_satuan_value').val('');
        }
        calculateAddTotal();
    });
    
    // Auto-calculate total for add modal
    $('#add_jumlah').on('input', function() {
        calculateAddTotal();
    });
    
    function calculateAddTotal() {
        var jumlah = parseInt($('#add_jumlah').val()) || 0;
        var harga = parseInt($('#add_harga_satuan_value').val()) || 0;
        var total = jumlah * harga;
        $('#add_total').val('Rp ' + total.toLocaleString('id-ID'));
    }
    
    // Format currency input for edit modal
    $('#edit_harga_satuan').on('input', function() {
        var value = $(this).val().replace(/[^0-9]/g, '');
        if (value) {
            $(this).val(parseInt(value).toLocaleString('id-ID'));
            $('#edit_harga_satuan_value').val(value);
        } else {
            $('#edit_harga_satuan_value').val('');
        }
        calculateEditTotal();
    });
    
    // Auto-calculate total for edit modal
    $('#edit_jumlah').on('input', function() {
        calculateEditTotal();
    });
    
    function calculateEditTotal() {
        var jumlah = parseInt($('#edit_jumlah').val()) || 0;
        var harga = parseInt($('#edit_harga_satuan_value').val()) || 0;
        var total = jumlah * harga;
        $('#edit_total').val('Rp ' + total.toLocaleString('id-ID'));
    }
    
    // Handle Edit Button Click
    $('.btn-edit').on('click', function() {
        var id = $(this).data('id');
        var data = inventoriesData.find(item => item.id == id);
        
        console.log('Edit clicked, ID:', id);
        console.log('Found data:', data);
        
        if (data) {
            $('#edit_id').val(data.id);
            $('#edit_id_kategori').val(data.id_kategori);
            $('#edit_nama_inventaris').val(data.nama_inventaris);
            $('#edit_jumlah').val(parseFloat(data.jumlah) || 0);
            $('#edit_luas').val(data.luas ? parseFloat(data.luas) : '');
            $('#edit_harga_satuan').val(parseFloat(data.harga_satuan).toLocaleString('id-ID') || '0');
            $('#edit_harga_satuan_value').val(parseFloat(data.harga_satuan) || 0);
            $('#edit_status').val(data.status);
            $('#edit_kondisi').val(data.kondisi);
            $('#edit_total').val('Rp ' + (parseFloat(data.total) || 0).toLocaleString('id-ID'));
            
            console.log('Modal fields populated');
        } else {
            console.error('Data not found for ID:', id);
        }
    });
    
    // Handle Delete Button Click
    $('.btn-delete').on('click', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        
        $('#delete_id').val(id);
        $('#delete_nama_inventaris').text(nama);
    });
});
</script>
