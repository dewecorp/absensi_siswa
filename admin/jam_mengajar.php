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
        $jam_ke = $_POST['jam_ke'];
        $jenis = $_POST['jenis'];
        // Convert to 24-hour format for database
        $waktu_mulai = date("H:i", strtotime($_POST['waktu_mulai']));
        $waktu_selesai = date("H:i", strtotime($_POST['waktu_selesai']));
        
        // Cek duplikasi jam ke pada jenis yang sama
        $check = $pdo->prepare("SELECT COUNT(*) FROM tb_jam_mengajar WHERE jam_ke = ? AND jenis = ?");
        $check->execute([$jam_ke, $jenis]);
        if ($check->fetchColumn() > 0) {
            $message = ['type' => 'danger', 'text' => "Jam ke-$jam_ke untuk $jenis sudah ada!"];
        } else {
            $stmt = $pdo->prepare("INSERT INTO tb_jam_mengajar (jam_ke, waktu_mulai, waktu_selesai, jenis) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$jam_ke, $waktu_mulai, $waktu_selesai, $jenis])) {
                $message = ['type' => 'success', 'text' => 'Jam mengajar berhasil ditambahkan!'];
                $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                logActivity($pdo, $username, 'Tambah Jam Mengajar', "Menambahkan jam ke-$jam_ke ($waktu_mulai - $waktu_selesai) [$jenis]");
            } else {
                $message = ['type' => 'danger', 'text' => 'Gagal menambahkan jam mengajar!'];
            }
        }
    } elseif (isset($_POST['update_jam'])) {
        $id_jam = (int)$_POST['id_jam'];
        $jam_ke = $_POST['jam_ke'];
        $jenis = $_POST['jenis'];
        // Convert to 24-hour format for database
        $waktu_mulai = date("H:i", strtotime($_POST['waktu_mulai']));
        $waktu_selesai = date("H:i", strtotime($_POST['waktu_selesai']));
        
        // Cek duplikasi jam ke selain ID ini pada jenis yang sama
        $check = $pdo->prepare("SELECT COUNT(*) FROM tb_jam_mengajar WHERE jam_ke = ? AND jenis = ? AND id_jam != ?");
        $check->execute([$jam_ke, $jenis, $id_jam]);
        if ($check->fetchColumn() > 0) {
            $message = ['type' => 'danger', 'text' => "Jam ke-$jam_ke untuk $jenis sudah ada!"];
        } else {
            $stmt = $pdo->prepare("UPDATE tb_jam_mengajar SET jam_ke=?, waktu_mulai=?, waktu_selesai=?, jenis=? WHERE id_jam=?");
            if ($stmt->execute([$jam_ke, $waktu_mulai, $waktu_selesai, $jenis, $id_jam])) {
                $message = ['type' => 'success', 'text' => 'Jam mengajar berhasil diupdate!'];
                $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                logActivity($pdo, $username, 'Update Jam Mengajar', "Update jam ke-$jam_ke ($waktu_mulai - $waktu_selesai) [$jenis]");
            } else {
                $message = ['type' => 'danger', 'text' => 'Gagal mengupdate jam mengajar!'];
            }
        }
    } elseif (isset($_POST['delete_jam'])) {
        $id_jam = (int)$_POST['id_jam'];
        
        // Ambil data sebelum hapus untuk log
        $stmt = $pdo->prepare("SELECT jam_ke, jenis FROM tb_jam_mengajar WHERE id_jam = ?");
        $stmt->execute([$id_jam]);
        $jam_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $jam_ke = $jam_data ? $jam_data['jam_ke'] : '?';
        $jenis_log = $jam_data ? $jam_data['jenis'] : '?';

        $stmt = $pdo->prepare("DELETE FROM tb_jam_mengajar WHERE id_jam=?");
        if ($stmt->execute([$id_jam])) {
            $message = ['type' => 'success', 'text' => 'Jam mengajar berhasil dihapus!'];
            $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
            logActivity($pdo, $username, 'Hapus Jam Mengajar', "Menghapus jam ke-$jam_ke [$jenis_log]");
        } else {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus jam mengajar!'];
        }
    }
}

// Get all teaching hours
$stmt = $pdo->query("SELECT * FROM tb_jam_mengajar ORDER BY jam_ke ASC");
$all_jam = $stmt->fetchAll(PDO::FETCH_ASSOC);

$jam_reguler = [];
$jam_ramadhan = [];

foreach ($all_jam as $row) {
    if ($row['jenis'] == 'Ramadhan') {
        $jam_ramadhan[] = $row;
    } else {
        $jam_reguler[] = $row;
    }
}

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
    // Initialize DataTables
    var tableOptions = {
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
    };

    $('#table-reguler').DataTable(tableOptions);
    $('#table-ramadhan').DataTable(tableOptions);

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

    // Handle Add Button (Auto-detect Tab)
    $('#btn-add-jam').on('click', function() {
        var activeTab = $('.nav-pills .active').attr('id');
        var jenis = 'Reguler'; // Default
        if (activeTab === 'pills-ramadhan-tab') {
            jenis = 'Ramadhan';
        }
        
        $('#add_jenis_hidden').val(jenis);
        $('#add_jenis_view').val(jenis);
        $('#add_jenis_view').val(jenis);
        $('#addModalLabel').text('Tambah Jam Mengajar (' + jenis + ')');
        $('#addModal').modal('show');
    });

    // Handle Edit Button (Delegate to document for both tables)
    $(document).on('click', '.edit-btn', function() {
        var id = $(this).data('id');
        var jam = $(this).data('jam');
        var mulai = $(this).data('mulai');
        var selesai = $(this).data('selesai');
        var jenis = $(this).data('jenis');

        $('#edit_id_jam').val(id);
        $('#edit_jam_ke').val(jam);
        $('#edit_waktu_mulai').val(mulai);
        $('#edit_waktu_selesai').val(selesai);
        
        $('#edit_jenis_view').val(jenis);
        $('#edit_jenis_hidden').val(jenis);
        $('#editModalLabel').text('Edit Jam Mengajar (' + jenis + ')');
        
        $('#editModal').modal('show');
    });

    // Handle Delete Button (Delegate to document)
    $(document).on('click', '.delete-btn', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var jam = $(this).data('jam');
        var jenis = $(this).data('jenis');
        
        Swal.fire({
            title: 'Konfirmasi Hapus',
            text: 'Apakah Anda yakin ingin menghapus Jam Ke-' + jam + ' (' + jenis + ')?',
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
        
        // Determine active tab
        var activeTabId = $('.tab-pane.active table').attr('id');
        var jenisTitle = activeTabId === 'table-ramadhan' ? 'Bulan Ramadhan' : 'Reguler';
        
        // Clone table to remove action column
        var table = $('#' + activeTabId).clone();
        table.find('th:last-child, td:last-child').remove();
        
        // Replace badges with text for export
        table.find('.badge').each(function() {
            $(this).replaceWith($(this).text());
        });
        
        if (type === 'pdf') {
            var printWindow = window.open('', '_blank');
            printWindow.document.write('<html><head><title>Data Jam Mengajar - ' + jenisTitle + '</title>');
            printWindow.document.write('<link rel=\"stylesheet\" href=\"https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css\">');
            printWindow.document.write('<style>body { padding: 20px; } .table { width: 100%; margin-bottom: 1rem; color: #212529; } .table th, .table td { padding: 0.75rem; vertical-align: top; border-top: 1px solid #dee2e6; } .table thead th { vertical-align: bottom; border-bottom: 2px solid #dee2e6; } .table-striped tbody tr:nth-of-type(odd) { background-color: rgba(0, 0, 0, 0.05); } @media print { .no-print { display: none; } }</style>');
            printWindow.document.write('</head><body>');
            
            var headerContent = '<div class=\"row mb-4 border-bottom pb-3 align-items-center\" style=\"border-bottom: 2px solid #000 !important;\">' +
                '<div class=\"col-2 text-center\">' +
                '<img src=\"$logo_url\" style=\"max-height: 80px; width: auto;\">' +
                '</div>' +
                '<div class=\"col-10 text-center\">' +
                '<h2 style=\"margin: 0; font-weight: bold; font-family: Arial, sans-serif;\">$school_name</h2>' +
                '<h4 style=\"margin: 5px 0 0; font-weight: normal;\">DATA JAM MENGAJAR (' + jenisTitle.toUpperCase() + ')</h4>' +
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
                '<input type=\"hidden\" name=\"report_title\" value=\"Data Jam Mengajar (' + jenisTitle + ')\">' +
                '<input type=\"hidden\" name=\"filename\" value=\"data_jam_mengajar_' + jenisTitle.toLowerCase().replace(' ', '_') + '\">' +
                '</form>');
                
            form.find('input[name=\"table_data\"]').val(table[0].outerHTML);
            $('body').append(form);
            form.submit();
            form.remove();
        }
    });

    // Persist Tab
    $('a[data-toggle=\"pill\"]').on('shown.bs.tab', function (e) {
        localStorage.setItem('activeTab_jam', $(e.target).attr('id'));
    });

    var activeTab = localStorage.getItem('activeTab_jam');
    if(activeTab){
        $('#' + activeTab).tab('show');
    }
});
";

// Add SweetAlert for messages
if ($message) {
    $swal_icon = $message['type'] == 'success' ? 'success' : 'error';
    $swal_title = $message['type'] == 'success' ? 'Berhasil!' : 'Gagal!';
    $swal_text = json_encode($message['text']); // Encode to ensure safe JS string
    
    // Auto close for success messages
    if ($message['type'] == 'success') {
        $js_page[] = "
        Swal.fire({
            icon: '$swal_icon',
            title: '$swal_title',
            text: $swal_text,
            timer: 1500,
            timerProgressBar: true,
            showConfirmButton: false
        });";
    } else {
        // Require manual close for errors
        $js_page[] = "
        Swal.fire({
            icon: '$swal_icon',
            title: '$swal_title',
            text: $swal_text,
            showConfirmButton: true
        });";
    }
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
                                <button class="btn btn-primary" id="btn-add-jam">
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
                            <ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">
                                <li class="nav-item">
                                    <a class="nav-link active" id="pills-reguler-tab" data-toggle="pill" href="#pills-reguler" role="tab" aria-controls="pills-reguler" aria-selected="true">Reguler</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="pills-ramadhan-tab" data-toggle="pill" href="#pills-ramadhan" role="tab" aria-controls="pills-ramadhan" aria-selected="false">Ramadhan</a>
                                </li>
                            </ul>
                            
                            <div class="tab-content" id="pills-tabContent">
                                <div class="tab-pane fade show active" id="pills-reguler" role="tabpanel" aria-labelledby="pills-reguler-tab">
                                    <div class="table-responsive">
                                        <table class="table table-striped" id="table-reguler" style="width:100%">
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
                                                foreach ($jam_reguler as $row): 
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
                                                                data-selesai="<?= date('H:i', strtotime($row['waktu_selesai'])) ?>"
                                                                data-jenis="Reguler">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn btn-danger btn-sm delete-btn" 
                                                                data-id="<?= $row['id_jam'] ?>"
                                                                data-jam="<?= $row['jam_ke'] ?>"
                                                                data-jenis="Reguler">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="pills-ramadhan" role="tabpanel" aria-labelledby="pills-ramadhan-tab">
                                    <div class="table-responsive">
                                        <table class="table table-striped" id="table-ramadhan" style="width:100%">
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
                                                foreach ($jam_ramadhan as $row): 
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
                                                                data-selesai="<?= date('H:i', strtotime($row['waktu_selesai'])) ?>"
                                                                data-jenis="Ramadhan">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn btn-danger btn-sm delete-btn" 
                                                                data-id="<?= $row['id_jam'] ?>"
                                                                data-jam="<?= $row['jam_ke'] ?>"
                                                                data-jenis="Ramadhan">
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
                    <input type="hidden" name="jenis" id="add_jenis_hidden">
                    <div class="form-group">
                        <label>Jam Ke</label>
                        <input type="text" class="form-control" name="jam_ke" required>
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
                    <input type="hidden" name="jenis" id="edit_jenis_hidden">
                    <div class="form-group">
                        <label>Jam Ke</label>
                        <input type="text" class="form-control" name="jam_ke" id="edit_jam_ke" required>
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
