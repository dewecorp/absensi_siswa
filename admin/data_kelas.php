<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

// Set page title
$page_title = 'Data Kelas';

// Handle form submissions
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_kelas'])) {
        $nama_kelas = sanitizeInput($_POST['nama_kelas']);
        $wali_kelas = sanitizeInput($_POST['wali_kelas']);
        
        $stmt = $pdo->prepare("INSERT INTO tb_kelas (nama_kelas, wali_kelas) VALUES (?, ?)");
        if ($stmt->execute([$nama_kelas, $wali_kelas])) {
            $message = ['type' => 'success', 'text' => 'Data kelas berhasil ditambahkan!'];
            logActivity($pdo, $_SESSION['username'], 'Tambah Kelas', "Menambahkan data kelas: $nama_kelas");
        } else {
            $message = ['type' => 'danger', 'text' => 'Gagal menambahkan data kelas!'];
        }
    } elseif (isset($_POST['update_kelas'])) {
        $id_kelas = (int)$_POST['id_kelas'];
        $nama_kelas = sanitizeInput($_POST['nama_kelas']);
        $wali_kelas = sanitizeInput($_POST['wali_kelas']);
        
        $stmt = $pdo->prepare("UPDATE tb_kelas SET nama_kelas=?, wali_kelas=? WHERE id_kelas=?");
        if ($stmt->execute([$nama_kelas, $wali_kelas, $id_kelas])) {
            $message = ['type' => 'success', 'text' => 'Data kelas berhasil diupdate!'];
            logActivity($pdo, $_SESSION['username'], 'Update Kelas', "Memperbarui data kelas: $nama_kelas");
        } else {
            $message = ['type' => 'danger', 'text' => 'Gagal mengupdate data kelas!'];
        }
    } elseif (isset($_POST['delete_kelas'])) {
        $id_kelas = (int)$_POST['id_kelas'];
        $stmt = $pdo->prepare("DELETE FROM tb_kelas WHERE id_kelas=?");
        if ($stmt->execute([$id_kelas])) {
            $message = ['type' => 'success', 'text' => 'Data kelas berhasil dihapus!'];
            logActivity($pdo, $_SESSION['username'], 'Hapus Kelas', "Menghapus data kelas: $nama_kelas");
        } else {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus data kelas!'];
        }
    }
}

// Get all classes
$stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all teachers for wali kelas dropdown
$teacherStmt = $pdo->query("SELECT id_guru, nama_guru FROM tb_guru ORDER BY nama_guru ASC");
$teachers = $teacherStmt->fetchAll(PDO::FETCH_ASSOC);

// Define CSS libraries for this page
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'node_modules/datatables.net-select-bs4/css/select.bootstrap4.min.css',
    '../node_modules/select2/dist/css/select2.min.css'
];

// Define JS libraries for this page
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'node_modules/datatables.net-select-bs4/js/select.bootstrap4.min.js',
    '../node_modules/select2/dist/js/select2.full.min.js'
];

// Define page-specific JS
$js_page = [];

// Add JavaScript for delete confirmation
$js_page[] = "
\$(document).ready(function() {
    // Initialize DataTables with pagination and show entries
    function initDataTable() {
        if (typeof $.fn.DataTable === 'undefined') {
            console.warn('DataTables library not loaded, retrying...');
            setTimeout(initDataTable, 100);
            return;
        }
        
        // Check if DataTable is already initialized
        if ($.fn.DataTable.isDataTable('#table-1')) {
            $('#table-1').DataTable().destroy();
        }
        
        $('#table-1').DataTable({
            \"columnDefs\": [
                { \"sortable\": false, \"targets\": [3] }
            ],
            \"paging\": true,
            \"lengthChange\": true,
            \"pageLength\": 10,
            \"lengthMenu\": [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Semua']],
            \"dom\": 'lfrtip',
            \"info\": true,
            \"language\": {
                \"lengthMenu\": \"Tampilkan _MENU_ entri\",
                \"zeroRecords\": \"Tidak ada data yang ditemukan\",
                \"info\": \"Menampilkan _START_ sampai _END_ dari _TOTAL_ entri\",
                \"infoEmpty\": \"Menampilkan 0 sampai 0 dari 0 entri\",
                \"infoFiltered\": \"(disaring dari _MAX_ total entri)\",
                \"search\": \"Cari:\",
                \"paginate\": {
                    \"first\": \"Pertama\",
                    \"last\": \"Terakhir\",
                    \"next\": \"Selanjutnya\",
                    \"previous\": \"Sebelumnya\"
                }
            }
        });
        console.log('DataTables initialized for table-1');
    }
    
    // Initialize DataTables
    initDataTable();
    
    // Initialize Select2 for the main page
    if (\$('.select2').length > 0) {
        \$('.select2').select2({
            placeholder: 'Pilih Wali Kelas',
            allowClear: true
        });
    }
    
    // Initialize delete button handlers
    $('.delete-btn').on('click', function(e) {
        e.preventDefault();
        
        var id = \$(this).data('id');
        var name = \$(this).data('name');
        var action = \$(this).data('action');
        
        Swal.fire({
            title: 'Konfirmasi Hapus',
            text: 'Apakah Anda yakin ingin menghapus kelas ' + name + '?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                // Create a temporary form and submit it
                var form = \$('<form method=\\\"POST\\\" action=\\\"\\\">' +
                    '<input type=\\\"hidden\\\" name=\\\"id_kelas\\\" value=\\\"' + id + '\\\">' +
                    '<input type=\\\"hidden\\\" name=\\\"delete_kelas\\\" value=\\\"1\\\">' +
                    '</form>');
                \$('body').append(form);
                form.submit();
            }
        });
    });
});

// Initialize Select2 when modals are shown
$(document).on('shown.bs.modal', function(e) {
    // Small delay to ensure modal is fully rendered
    setTimeout(function() {
        var modal = $(e.target).find('.select2');
        if (modal.length > 0) {
            // Destroy if already initialized
            if (modal.data('select2')) {
                modal.select2('destroy');
            }
            
            // Re-initialize with proper settings for modals
            modal.select2({
                placeholder: 'Pilih Wali Kelas',
                allowClear: true,
                dropdownParent: $(e.target) // Important: attach dropdown to modal
            });
        }
    }, 100);
});

// Clean up Select2 when modals are hidden
$(document).on('hidden.bs.modal', function(e) {
    var modal = $(e.target).find('.select2');
    if (modal.length > 0 && modal.data('select2')) {
        modal.select2('destroy');
    }
});
";

// Set page title
$page_title = 'Data Kelas';

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Data Kelas</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item"><a href="#">Master Data</a></div>
                <div class="breadcrumb-item">Data Kelas</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Data Kelas</h4>
                            <div class="card-header-action">
                                <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addModal">Tambah Kelas</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if ($message): ?>
                            <script>
                                document.addEventListener('DOMContentLoaded', function() {
                                    Swal.fire({
                                        title: '<?php echo $message['type'] === 'success' ? 'Sukses!' : 'Error!'; ?>',
                                        text: '<?php echo addslashes($message['text']); ?>',
                                        icon: '<?php echo $message['type']; ?>',
                                        timer: <?php echo $message['type'] === 'success' ? '3000' : '5000'; ?>,
                                        timerProgressBar: true,
                                        showConfirmButton: false
                                    });
                                });
                            </script>
                            <?php endif; ?>
                            <div class="table-responsive">
                                <table class="table table-striped" id="table-1">
                                    <thead>
                                        <tr>
                                            <th>No</th>
                                            <th>Nama Kelas</th>
                                            <th>Wali Kelas</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($classes as $index => $class): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td><?php echo htmlspecialchars($class['nama_kelas']); ?></td>
                                            <td><?php echo htmlspecialchars($class['wali_kelas'] ?? '-'); ?></td>
                                            <td>
                                                <a href="#" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#editModal<?php echo $class['id_kelas']; ?>"><i class="fas fa-edit"></i></a>
                                                <a href="#" class="btn btn-danger btn-sm delete-btn" data-id="<?php echo $class['id_kelas']; ?>" data-name="<?php echo htmlspecialchars($class['nama_kelas']); ?>" data-action="delete_kelas"><i class="fas fa-trash"></i></a>
                                            </td>
                                        </tr>
                                        
                                        <!-- Edit Modal -->
                                        <div class="modal fade edit-modal" id="editModal<?php echo $class['id_kelas']; ?>" tabindex="-1" role="dialog" aria-labelledby="editModalLabel<?php echo $class['id_kelas']; ?>" aria-hidden="true">
                                            <div class="modal-dialog" role="document">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title" id="editModalLabel<?php echo $class['id_kelas']; ?>">Edit Data Kelas</h5>
                                                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                            <span aria-hidden="true">&times;</span>
                                                        </button>
                                                    </div>
                                                    <form method="POST" action="">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="id_kelas" value="<?php echo $class['id_kelas']; ?>">
                                                            <input type="hidden" name="update_kelas" value="1">
                                                            <div class="form-group">
                                                                <label>Nama Kelas</label>
                                                                <input type="text" class="form-control" name="nama_kelas" value="<?php echo htmlspecialchars($class['nama_kelas']); ?>" required>
                                                            </div>
                                                            <div class="form-group">
                                                                <label>Wali Kelas</label>
                                                                <select class="form-control select2" name="wali_kelas">
                                                                    <option value="">Pilih Wali Kelas</option>
                                                                    <?php foreach ($teachers as $teacher): ?>
                                                                    <option value="<?php echo htmlspecialchars($teacher['nama_guru']); ?>" <?php echo ($teacher['nama_guru'] == $class['wali_kelas']) ? 'selected' : ''; ?>>
                                                                        <?php echo htmlspecialchars($teacher['nama_guru']); ?>
                                                                    </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer bg-whitesmoke br">
                                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
                                                            <button type="submit" class="btn btn-primary">Simpan</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        
                                        <?php endforeach; ?>
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

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-labelledby="addModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addModalLabel">Tambah Data Kelas</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="add_kelas" value="1">
                    <div class="form-group">
                        <label>Nama Kelas</label>
                        <input type="text" class="form-control" name="nama_kelas" required>
                    </div>
                    <div class="form-group">
                        <label>Wali Kelas</label>
                        <select class="form-control select2" name="wali_kelas">
                            <option value="">Pilih Wali Kelas</option>
                            <?php foreach ($teachers as $teacher): ?>
                            <option value="<?php echo htmlspecialchars($teacher['nama_guru']); ?>">
                                <?php echo htmlspecialchars($teacher['nama_guru']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-whitesmoke br">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
include '../templates/footer.php'; 
?>