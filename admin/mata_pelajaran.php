<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Ensure session is started for activity logging
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

// Get school profile
$school_profile = getSchoolProfile($pdo);
$school_name = strtoupper($school_profile['nama_madrasah'] ?? 'Sistem Absensi Siswa');
$logo_file = $school_profile['logo'] ?? 'logo.png';
$web_root = dirname(dirname($_SERVER['PHP_SELF']));
$web_root = $web_root == '/' || $web_root == '\\' ? '' : $web_root;
$logo_url = $web_root . '/assets/img/' . $logo_file;

// Set page title
$page_title = 'Mata Pelajaran';

// Handle form submissions
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_mapel'])) {
        $nama_mapel = trim($_POST['nama_mapel']);
        $kode_mapel = trim($_POST['kode_mapel']);
        
        // Cek duplikasi mata pelajaran
        $check = $pdo->prepare("SELECT COUNT(*) FROM tb_mata_pelajaran WHERE nama_mapel = ? OR kode_mapel = ?");
        $check->execute([$nama_mapel, $kode_mapel]);
        if ($check->fetchColumn() > 0) {
            $message = ['type' => 'danger', 'text' => 'Mata pelajaran atau Kode Mapel sudah ada!'];
        } else {
            $stmt = $pdo->prepare("INSERT INTO tb_mata_pelajaran (nama_mapel, kode_mapel) VALUES (?, ?)");
            if ($stmt->execute([$nama_mapel, $kode_mapel])) {
                $message = ['type' => 'success', 'text' => 'Mata pelajaran berhasil ditambahkan!'];
                $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                logActivity($pdo, $username, 'Tambah Mata Pelajaran', "Menambahkan mapel $nama_mapel ($kode_mapel)");
            } else {
                $message = ['type' => 'danger', 'text' => 'Gagal menambahkan mata pelajaran!'];
            }
        }
    } elseif (isset($_POST['update_mapel'])) {
        $id_mapel = (int)$_POST['id_mapel'];
        $nama_mapel = trim($_POST['nama_mapel']);
        $kode_mapel = trim($_POST['kode_mapel']);
        
        // Cek duplikasi mata pelajaran selain ID ini
        $check = $pdo->prepare("SELECT COUNT(*) FROM tb_mata_pelajaran WHERE (nama_mapel = ? OR kode_mapel = ?) AND id_mapel != ?");
        $check->execute([$nama_mapel, $kode_mapel, $id_mapel]);
        if ($check->fetchColumn() > 0) {
            $message = ['type' => 'danger', 'text' => 'Mata pelajaran atau Kode Mapel sudah ada!'];
        } else {
            $stmt = $pdo->prepare("UPDATE tb_mata_pelajaran SET nama_mapel=?, kode_mapel=? WHERE id_mapel=?");
            if ($stmt->execute([$nama_mapel, $kode_mapel, $id_mapel])) {
                $message = ['type' => 'success', 'text' => 'Mata pelajaran berhasil diupdate!'];
                $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                logActivity($pdo, $username, 'Update Mata Pelajaran', "Update mapel ID $id_mapel menjadi $nama_mapel ($kode_mapel)");
            } else {
                $message = ['type' => 'danger', 'text' => 'Gagal mengupdate mata pelajaran!'];
            }
        }
    } elseif (isset($_POST['delete_mapel'])) {
        $id_mapel = (int)$_POST['id_mapel'];
        
        // Ambil data sebelum hapus untuk log
        $stmt = $pdo->prepare("SELECT nama_mapel FROM tb_mata_pelajaran WHERE id_mapel = ?");
        $stmt->execute([$id_mapel]);
        $mapel_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $nama_mapel = $mapel_data ? $mapel_data['nama_mapel'] : '?';

        $stmt = $pdo->prepare("DELETE FROM tb_mata_pelajaran WHERE id_mapel=?");
        if ($stmt->execute([$id_mapel])) {
            $message = ['type' => 'success', 'text' => 'Mata pelajaran berhasil dihapus!'];
            $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
            logActivity($pdo, $username, 'Hapus Mata Pelajaran', "Menghapus mapel $nama_mapel");
        } else {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus mata pelajaran!'];
        }
    }
}

// Get all subjects
$stmt = $pdo->query("SELECT * FROM tb_mata_pelajaran ORDER BY nama_mapel ASC");
$mata_pelajaran = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Define CSS libraries for this page
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'node_modules/datatables.net-select-bs4/css/select.bootstrap4.min.css'
];

// Define JS libraries for this page
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'node_modules/datatables.net-select-bs4/js/select.bootstrap4.min.js'
];

// Define page-specific JS
$js_page = [];

// Add JavaScript
$js_page[] = "
$(document).ready(function() {
    // Initialize DataTable
    $('#table-1').DataTable({
        \"columnDefs\": [
            { \"sortable\": false, \"targets\": [3] }
        ],
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

    // Handle Edit Button
    $('#table-1').on('click', '.edit-btn', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        var kode = $(this).data('kode');

        $('#edit_id_mapel').val(id);
        $('#edit_nama_mapel').val(nama);
        $('#edit_kode_mapel').val(kode);
        
        $('#editModal').modal('show');
    });

    // Handle Delete Button
    $('#table-1').on('click', '.delete-btn', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        
        Swal.fire({
            title: 'Konfirmasi Hapus',
            text: 'Apakah Anda yakin ingin menghapus Mata Pelajaran ' + nama + '?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                var form = $('<form method=\"POST\" action=\"\">' +
                    '<input type=\"hidden\" name=\"id_mapel\" value=\"' + id + '\">' +
                    '<input type=\"hidden\" name=\"delete_mapel\" value=\"1\">' +
                    '</form>');
                $('body').append(form);
                form.submit();
            }
        });
    });

    // Handle Export
    $('.export-btn').on('click', function(e) {
        e.preventDefault();
        var type = $(this).data('type');
        
        // Clone table to remove action column
        var table = $('#table-1').clone();
        table.find('th:last-child, td:last-child').remove();
        
        if (type === 'pdf') {
            var printWindow = window.open('', '_blank');
            printWindow.document.write('<html><head><title>Data Mata Pelajaran</title>');
            printWindow.document.write('<link rel=\"stylesheet\" href=\"https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css\">');
            printWindow.document.write('<style>body { padding: 20px; } .table { width: 100%; margin-bottom: 1rem; color: #212529; } .table th, .table td { padding: 0.75rem; vertical-align: top; border-top: 1px solid #dee2e6; } .table thead th { vertical-align: bottom; border-bottom: 2px solid #dee2e6; } .table-striped tbody tr:nth-of-type(odd) { background-color: rgba(0, 0, 0, 0.05); } @media print { .no-print { display: none; } }</style>');
            printWindow.document.write('</head><body>');
            
            var headerContent = '<div class=\"row mb-4 border-bottom pb-3 align-items-center\" style=\"border-bottom: 2px solid #000 !important;\">' +
                '<div class=\"col-2 text-center\">' +
                '<img src=\"$logo_url\" style=\"max-height: 80px; width: auto;\">' +
                '</div>' +
                '<div class=\"col-10 text-center\">' +
                '<h2 style=\"margin: 0; font-weight: bold; font-family: Arial, sans-serif;\">$school_name</h2>' +
                '<h4 style=\"margin: 5px 0 0; font-weight: normal;\">DATA MATA PELAJARAN</h4>' +
                '</div>' +
                '</div>';
                
            printWindow.document.write(headerContent);
            printWindow.document.write(table[0].outerHTML);
            printWindow.document.write('<script>window.onload = function() { window.print(); window.close(); }<\/script>');
            printWindow.document.write('</body></html>');
            printWindow.document.close();
        } else {
            var url = '../config/excel_export.php';
            var form = $('<form method=\"POST\" action=\"' + url + '\" target=\"_blank\">' +
                '<input type=\"hidden\" name=\"table_data\" value=\"\">' +
                '<input type=\"hidden\" name=\"report_title\" value=\"Data Mata Pelajaran\">' +
                '<input type=\"hidden\" name=\"filename\" value=\"data_mata_pelajaran\">' +
                '</form>');
                
            form.find('input[name=\"table_data\"]').val(table[0].outerHTML);
            $('body').append(form);
            form.submit();
            form.remove();
        }
    });
});
";

// Add SweetAlert for messages
if ($message) {
    $swal_icon = $message['type'] == 'success' ? 'success' : 'error';
    $swal_title = $message['type'] == 'success' ? 'Berhasil!' : 'Gagal!';
    $swal_text = json_encode($message['text']); // Encode to ensure safe JS string
    $swal_timer = $message['type'] == 'success' ? 1500 : 'null';
    $swal_show_confirm = $message['type'] == 'success' ? 'false' : 'true';
    
    $js_page[] = "
    Swal.fire({
        icon: '$swal_icon',
        title: '$swal_title',
        text: $swal_text,
        timer: $swal_timer,
        showConfirmButton: $swal_show_confirm
    });";
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<!-- Main Content -->
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Mata Pelajaran</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="#">Dashboard</a></div>
                <div class="breadcrumb-item"><a href="#">Master Data</a></div>
                <div class="breadcrumb-item">Mata Pelajaran</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Data Mata Pelajaran</h4>
                            <div class="card-header-action">
                                <button class="btn btn-primary" data-toggle="modal" data-target="#addModal">
                                    <i class="fas fa-plus"></i> Tambah Mapel
                                </button>
                                <div class="dropdown d-inline mr-2">
                                    <button class="btn btn-success dropdown-toggle" type="button" id="exportDropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                        <i class="fas fa-file-export"></i> Export
                                    </button>
                                    <div class="dropdown-menu" aria-labelledby="exportDropdown">
                                        <a class="dropdown-item export-btn" href="#" data-type="excel"><i class="fas fa-file-excel"></i> Excel</a>
                                        <a class="dropdown-item export-btn" href="#" data-type="pdf"><i class="fas fa-file-pdf"></i> PDF</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped" id="table-1">
                                    <thead>
                                        <tr>
                                            <th class="text-center" width="5%">No</th>
                                            <th>Kode Mapel</th>
                                            <th>Mata Pelajaran</th>
                                            <th width="15%">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $no = 1;
                                        foreach ($mata_pelajaran as $row): 
                                        ?>
                                        <tr>
                                            <td class="text-center"><?= $no++ ?></td>
                                            <td><?= htmlspecialchars($row['kode_mapel'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['nama_mapel']) ?></td>
                                            <td>
                                                <button class="btn btn-warning btn-sm edit-btn" 
                                                        data-id="<?= $row['id_mapel'] ?>"
                                                        data-nama="<?= htmlspecialchars($row['nama_mapel']) ?>"
                                                        data-kode="<?= htmlspecialchars($row['kode_mapel'] ?? '') ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-danger btn-sm delete-btn" 
                                                        data-id="<?= $row['id_mapel'] ?>"
                                                        data-nama="<?= htmlspecialchars($row['nama_mapel']) ?>">
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
            </div>
        </div>
    </section>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-labelledby="addModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addModalLabel">Tambah Mata Pelajaran</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Kode Mapel</label>
                        <input type="text" class="form-control" name="kode_mapel" required>
                    </div>
                    <div class="form-group">
                        <label>Nama Mata Pelajaran</label>
                        <input type="text" class="form-control" name="nama_mapel" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" name="add_mapel" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editModalLabel">Edit Mata Pelajaran</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="id_mapel" id="edit_id_mapel">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Kode Mapel</label>
                        <input type="text" class="form-control" name="kode_mapel" id="edit_kode_mapel" required>
                    </div>
                    <div class="form-group">
                        <label>Nama Mata Pelajaran</label>
                        <input type="text" class="form-control" name="nama_mapel" id="edit_nama_mapel" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" name="update_mapel" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
