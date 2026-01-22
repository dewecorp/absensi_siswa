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
$page_title = 'Jam Mengajar';

// Handle form submissions
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_jam'])) {
        $jam_ke = (int)$_POST['jam_ke'];
        // Convert to 24-hour format for database
        $waktu_mulai = date("H:i", strtotime($_POST['waktu_mulai']));
        $waktu_selesai = date("H:i", strtotime($_POST['waktu_selesai']));
        
        // Cek duplikasi jam ke
        $check = $pdo->prepare("SELECT COUNT(*) FROM tb_jam_mengajar WHERE jam_ke = ?");
        $check->execute([$jam_ke]);
        if ($check->fetchColumn() > 0) {
            $message = ['type' => 'danger', 'text' => 'Jam ke-' . $jam_ke . ' sudah ada!'];
        } else {
            $stmt = $pdo->prepare("INSERT INTO tb_jam_mengajar (jam_ke, waktu_mulai, waktu_selesai) VALUES (?, ?, ?)");
            if ($stmt->execute([$jam_ke, $waktu_mulai, $waktu_selesai])) {
                $message = ['type' => 'success', 'text' => 'Jam mengajar berhasil ditambahkan!'];
                $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                logActivity($pdo, $username, 'Tambah Jam Mengajar', "Menambahkan jam ke-$jam_ke ($waktu_mulai - $waktu_selesai)");
            } else {
                $message = ['type' => 'danger', 'text' => 'Gagal menambahkan jam mengajar!'];
            }
        }
    } elseif (isset($_POST['update_jam'])) {
        $id_jam = (int)$_POST['id_jam'];
        $jam_ke = (int)$_POST['jam_ke'];
        // Convert to 24-hour format for database
        $waktu_mulai = date("H:i", strtotime($_POST['waktu_mulai']));
        $waktu_selesai = date("H:i", strtotime($_POST['waktu_selesai']));
        
        // Cek duplikasi jam ke selain ID ini
        $check = $pdo->prepare("SELECT COUNT(*) FROM tb_jam_mengajar WHERE jam_ke = ? AND id_jam != ?");
        $check->execute([$jam_ke, $id_jam]);
        if ($check->fetchColumn() > 0) {
            $message = ['type' => 'danger', 'text' => 'Jam ke-' . $jam_ke . ' sudah ada!'];
        } else {
            $stmt = $pdo->prepare("UPDATE tb_jam_mengajar SET jam_ke=?, waktu_mulai=?, waktu_selesai=? WHERE id_jam=?");
            if ($stmt->execute([$jam_ke, $waktu_mulai, $waktu_selesai, $id_jam])) {
                $message = ['type' => 'success', 'text' => 'Jam mengajar berhasil diupdate!'];
                $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                logActivity($pdo, $username, 'Update Jam Mengajar', "Update jam ke-$jam_ke ($waktu_mulai - $waktu_selesai)");
            } else {
                $message = ['type' => 'danger', 'text' => 'Gagal mengupdate jam mengajar!'];
            }
        }
    } elseif (isset($_POST['delete_jam'])) {
        $id_jam = (int)$_POST['id_jam'];
        
        // Ambil data sebelum hapus untuk log
        $stmt = $pdo->prepare("SELECT jam_ke FROM tb_jam_mengajar WHERE id_jam = ?");
        $stmt->execute([$id_jam]);
        $jam_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $jam_ke = $jam_data ? $jam_data['jam_ke'] : '?';

        $stmt = $pdo->prepare("DELETE FROM tb_jam_mengajar WHERE id_jam=?");
        if ($stmt->execute([$id_jam])) {
            $message = ['type' => 'success', 'text' => 'Jam mengajar berhasil dihapus!'];
            $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
            logActivity($pdo, $username, 'Hapus Jam Mengajar', "Menghapus jam ke-$jam_ke");
        } else {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus jam mengajar!'];
        }
    }
}

// Get all teaching hours
$stmt = $pdo->query("SELECT * FROM tb_jam_mengajar ORDER BY jam_ke ASC");
$jam_mengajar = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Define CSS libraries for this page
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-timepicker/0.5.2/css/bootstrap-timepicker.min.css',
    'node_modules/datatables.net-select-bs4/css/select.bootstrap4.min.css'
];

// Define JS libraries for this page
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-timepicker/0.5.2/js/bootstrap-timepicker.min.js',
    'node_modules/datatables.net-select-bs4/js/select.bootstrap4.min.js'
];

// Define page-specific JS
$js_page = [];

// Add Custom CSS for Timepicker in Modal
echo '<style>
.bootstrap-timepicker-widget.dropdown-menu {
    z-index: 1050 !important;
}
</style>';

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

    // Initialize Timepicker
    $('.timepicker').timepicker({
        showMeridian: false,
        defaultTime: false,
        icons: {
            up: 'fas fa-chevron-up',
            down: 'fas fa-chevron-down'
        }
    });
    
    // Ensure inputs are not readonly to allow manual typing
    $('.timepicker').removeAttr('readonly');

    // Handle Edit Button
    $('.edit-btn').on('click', function() {
        var id = $(this).data('id');
        var jam = $(this).data('jam');
        var mulai = $(this).data('mulai');
        var selesai = $(this).data('selesai');

        $('#edit_id_jam').val(id);
        $('#edit_jam_ke').val(jam);
        $('#edit_waktu_mulai').val(mulai);
        $('#edit_waktu_selesai').val(selesai);
        
        $('#editModal').modal('show');
    });

    // Handle Delete Button
    $('.delete-btn').on('click', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var jam = $(this).data('jam');
        
        Swal.fire({
            title: 'Konfirmasi Hapus',
            text: 'Apakah Anda yakin ingin menghapus Jam Ke-' + jam + '?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                var form = $('<form method=\"POST\" action=\"\">' +
                    '<input type=\"hidden\" name=\"id_jam\" value=\"' + id + '\">' +
                    '<input type=\"hidden\" name=\"delete_jam\" value=\"1\">' +
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
        
        // Replace badges with text for export
        table.find('.badge').each(function() {
            $(this).replaceWith($(this).text());
        });
        
        if (type === 'pdf') {
            var printWindow = window.open('', '_blank');
            printWindow.document.write('<html><head><title>Data Jam Mengajar</title>');
            printWindow.document.write('<link rel=\"stylesheet\" href=\"https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css\">');
            printWindow.document.write('<style>body { padding: 20px; } .table { width: 100%; margin-bottom: 1rem; color: #212529; } .table th, .table td { padding: 0.75rem; vertical-align: top; border-top: 1px solid #dee2e6; } .table thead th { vertical-align: bottom; border-bottom: 2px solid #dee2e6; } .table-striped tbody tr:nth-of-type(odd) { background-color: rgba(0, 0, 0, 0.05); } @media print { .no-print { display: none; } }</style>');
            printWindow.document.write('</head><body>');
            
            var headerContent = '<div class=\"row mb-4 border-bottom pb-3 align-items-center\" style=\"border-bottom: 2px solid #000 !important;\">' +
                '<div class=\"col-2 text-center\">' +
                '<img src=\"$logo_url\" style=\"max-height: 80px; width: auto;\">' +
                '</div>' +
                '<div class=\"col-10 text-center\">' +
                '<h2 style=\"margin: 0; font-weight: bold; font-family: Arial, sans-serif;\">$school_name</h2>' +
                '<h4 style=\"margin: 5px 0 0; font-weight: normal;\">DATA JAM MENGAJAR</h4>' +
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
                '<input type=\"hidden\" name=\"report_title\" value=\"Data Jam Mengajar\">' +
                '<input type=\"hidden\" name=\"filename\" value=\"data_jam_mengajar\">' +
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
            <h1>Jam Mengajar</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="#">Dashboard</a></div>
                <div class="breadcrumb-item"><a href="#">Master Data</a></div>
                <div class="breadcrumb-item">Jam Mengajar</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Data Jam Mengajar</h4>
                            <div class="card-header-action">
                                <button class="btn btn-primary" data-toggle="modal" data-target="#addModal">
                                    <i class="fas fa-plus"></i> Tambah Jam
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
                                            <th width="15%">Jam Ke</th>
                                            <th>Waktu</th>
                                            <th width="15%">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $no = 1;
                                        foreach ($jam_mengajar as $row): 
                                        ?>
                                        <tr>
                                            <td class="text-center"><?= $no++ ?></td>
                                            <td><?= $row['jam_ke'] ?></td>
                                            <td>
                                                <span class="badge badge-info"><?= date('H:i', strtotime($row['waktu_mulai'])) ?></span> 
                                                s/d 
                                                <span class="badge badge-info"><?= date('H:i', strtotime($row['waktu_selesai'])) ?></span>
                                            </td>
                                            <td>
                                                <button class="btn btn-warning btn-sm edit-btn" 
                                                        data-id="<?= $row['id_jam'] ?>"
                                                        data-jam="<?= $row['jam_ke'] ?>"
                                                        data-mulai="<?= date('H:i', strtotime($row['waktu_mulai'])) ?>"
                                                        data-selesai="<?= date('H:i', strtotime($row['waktu_selesai'])) ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-danger btn-sm delete-btn" 
                                                        data-id="<?= $row['id_jam'] ?>"
                                                        data-jam="<?= $row['jam_ke'] ?>">
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
                <h5 class="modal-title" id="addModalLabel">Tambah Jam Mengajar</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Jam Ke</label>
                        <input type="number" class="form-control" name="jam_ke" required min="0">
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Waktu Mulai</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <div class="input-group-text">
                                            <i class="fas fa-clock"></i>
                                        </div>
                                    </div>
                                    <input type="text" class="form-control timepicker" name="waktu_mulai" required autocomplete="off">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Waktu Selesai</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <div class="input-group-text">
                                            <i class="fas fa-clock"></i>
                                        </div>
                                    </div>
                                    <input type="text" class="form-control timepicker" name="waktu_selesai" required autocomplete="off">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" name="add_jam" class="btn btn-primary">Simpan</button>
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
                <h5 class="modal-title" id="editModalLabel">Edit Jam Mengajar</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="id_jam" id="edit_id_jam">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Jam Ke</label>
                        <input type="number" class="form-control" name="jam_ke" id="edit_jam_ke" required min="0">
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Waktu Mulai</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <div class="input-group-text">
                                            <i class="fas fa-clock"></i>
                                        </div>
                                    </div>
                                    <input type="text" class="form-control timepicker" name="waktu_mulai" id="edit_waktu_mulai" required autocomplete="off">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Waktu Selesai</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <div class="input-group-text">
                                            <i class="fas fa-clock"></i>
                                        </div>
                                    </div>
                                    <input type="text" class="form-control timepicker" name="waktu_selesai" id="edit_waktu_selesai" required autocomplete="off">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" name="update_jam" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>