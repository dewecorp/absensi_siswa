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
$school_name = strtoupper($school_profile['nama_madrasah'] ?? 'Sistem Informasi Madrasah');
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

// Get all teaching hours ordered by start time
$stmt = $pdo->query("SELECT * FROM tb_jam_mengajar ORDER BY waktu_mulai ASC, jam_ke ASC");
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
    'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-timepicker/0.5.2/css/bootstrap-timepicker.min.css'
];

// Define JS libraries for this page
$js_libs = [
    'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-timepicker/0.5.2/js/bootstrap-timepicker.min.js'
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
        $('#addModalLabel').text('Tambah Jam Mengajar (' + jenis + ')');
        $('#addModal').modal('show');
    });

    // Handle Edit Button (Delegate to document for both tables/cards)
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

    // Live search filter kartu jam mengajar
    $('#jam-search-input').on('input', function() {
        var q = $(this).val().toLowerCase().trim();
        $('.tab-pane.active .jam-app-card').each(function() {
            var text = $(this).text().toLowerCase();
            var match = (q === '' || text.indexOf(q) !== -1);
            $(this).toggle(match);
        });
    });

    // Handle Export
    $('.export-btn').on('click', function(e) {
        e.preventDefault();
        var type = $(this).data('type');
        
        // Determine active tab
        var activeTab = $('.nav-pills .active').attr('id');
        var isRamadhan = activeTab === 'pills-ramadhan-tab';
        var tableId = isRamadhan ? '#export-table-ramadhan' : '#export-table-reguler';
        var jenisTitle = isRamadhan ? 'Bulan Ramadhan' : 'Reguler';
        
        var table = $(tableId).clone();
        
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
            var url = '../config/excel_export?session_type={$_SESSION['level']}';
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
        $('#jam-search-input').val('');
        $('.jam-app-card').show();
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

// Function helper untuk menghitung durasi waktu dan label slot
function getSlotDurationText($start, $end) {
    $t1 = strtotime($start);
    $t2 = strtotime($end);
    if ($t2 > $t1) {
        $diff = round(($t2 - $t1) / 60);
        return $diff . ' Menit';
    }
    return '';
}

function getSlotBadgeMeta($jam_ke) {
    $jk = trim(strtoupper((string)$jam_ke));
    if ($jk === 'A') {
        return [
            'type' => 'Asmaul Husna, Tadarus dan Upacara',
            'icon' => 'fa-mosque',
            'theme' => 'slot-special'
        ];
    } elseif ($jk === 'B') {
        return [
            'type' => 'Istirahat I',
            'icon' => 'fa-coffee',
            'theme' => 'slot-special'
        ];
    } elseif ($jk === 'C') {
        return [
            'type' => 'Istirahat II',
            'icon' => 'fa-utensils',
            'theme' => 'slot-special'
        ];
    }
    return [
        'type' => 'Jam Pelajaran',
        'icon' => 'fa-book-open',
        'theme' => 'slot-standard'
    ];
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<style>
/* Modern Mobile App Styling - Jam Mengajar */
.jam-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 18px;
}
.jam-nav-pills {
    display: inline-flex;
    background: #f1f5f9;
    padding: 4px;
    border-radius: 999px;
    border: 1px solid #e2e8f0;
}
.jam-nav-pills .nav-link {
    border-radius: 999px;
    padding: 8px 18px;
    font-size: 13px;
    font-weight: 700;
    color: #64748b;
    transition: all .2s ease;
    border: 0;
}
.jam-nav-pills .nav-link.active {
    background: #4f46e5;
    color: #ffffff !important;
    box-shadow: 0 4px 12px rgba(79, 70, 229, .28);
}
.jam-search-wrap {
    position: relative;
    max-width: 320px;
    width: 100%;
}
.jam-search-wrap input {
    width: 100%;
    padding: 9px 16px 9px 38px;
    border-radius: 999px;
    border: 1.5px solid #cbd5e1;
    font-size: 13px;
    font-weight: 600;
    color: #0f172a;
    background: #ffffff;
    outline: none;
    transition: all .2s ease;
}
.jam-search-wrap input:focus {
    border-color: #4f46e5;
    box-shadow: 0 0 0 3px rgba(79, 70, 229, .15);
}
.jam-search-wrap i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #64748b;
    font-size: 13px;
}

/* App Grid & Card */
.jam-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}
@media (max-width: 767.98px) {
    .jam-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }
}

.jam-app-card {
    position: relative;
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    border-radius: 20px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-shadow: 0 4px 18px rgba(15, 23, 42, .04);
    transition: transform .18s cubic-bezier(0.4, 0, 0.2, 1), box-shadow .18s ease, border-color .18s ease;
}
.jam-app-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 28px rgba(15, 23, 42, .08);
    border-color: #cbd5e1;
}

/* Aksen atas kartu */
.jam-app-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
}
.jam-app-card.slot-standard::before { background: linear-gradient(90deg, #4f46e5, #3b82f6); }
.jam-app-card.slot-special::before { background: linear-gradient(90deg, #f59e0b, #ef4444); }

.jam-card-top {
    padding: 14px 16px 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.jam-slot-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .2px;
}
.jam-slot-tag.slot-standard {
    background: #eff6ff;
    color: #2563eb;
    border: 1px solid #bfdbfe;
}
.jam-slot-tag.slot-special {
    background: #fffbeb;
    color: #d97706;
    border: 1px solid #fde68a;
}
.jam-duration-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 999px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #0f172a;
    font-size: 11.5px;
    font-weight: 800;
}

.jam-card-main {
    padding: 4px 16px 14px;
    display: flex;
    align-items: center;
    gap: 16px;
}
.jam-circle-ke {
    width: 54px;
    height: 54px;
    border-radius: 16px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: #ffffff;
    box-shadow: 0 4px 12px rgba(15, 23, 42, .12);
}
.jam-circle-ke.slot-standard { background: linear-gradient(135deg, #4f46e5, #3b82f6); }
.jam-circle-ke.slot-special { background: linear-gradient(135deg, #f59e0b, #d97706); }
.jam-ke-label {
    font-size: 9px;
    font-weight: 700;
    letter-spacing: .5px;
    opacity: .85;
    text-transform: uppercase;
    line-height: 1;
}
.jam-ke-val {
    font-size: 20px;
    font-weight: 900;
    line-height: 1.1;
}

.jam-range-box {
    min-width: 0;
    flex: 1 1 auto;
}
.jam-time-badge-wrap {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
.jam-time-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #0f172a;
    color: #ffffff;
    padding: 6px 12px;
    border-radius: 10px;
    font-size: 13.5px;
    font-weight: 800;
    letter-spacing: .5px;
    box-shadow: 0 2px 6px rgba(15, 23, 42, .15);
}
.jam-time-badge.end-time {
    background: #334155;
}
.jam-time-arrow {
    font-size: 12px;
    color: #94a3b8;
    font-weight: 700;
}

.jam-card-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 10px 16px;
    background: #f8fafc;
    border-top: 1px solid #f1f5f9;
}
.jam-index-text {
    font-size: 11.5px;
    font-weight: 800;
    color: #94a3b8;
}

.jam-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}
.jam-app-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    border: 0;
    cursor: pointer;
    transition: all .15s ease;
    text-decoration: none !important;
}
.jam-app-btn:active { transform: scale(0.96); }

.jam-btn-edit {
    background: linear-gradient(135deg, #4f46e5, #4338ca);
    color: #ffffff !important;
    box-shadow: 0 2px 8px rgba(79, 70, 229, .25);
}
.jam-btn-edit:hover {
    box-shadow: 0 4px 12px rgba(79, 70, 229, .35);
    color: #ffffff !important;
    transform: translateY(-1px);
}

.jam-btn-del {
    background: #ffffff;
    color: #ef4444 !important;
    border: 1.5px solid #fecaca !important;
}
.jam-btn-del:hover {
    background: #fef2f2;
    border-color: #f87171 !important;
    color: #dc2626 !important;
    transform: translateY(-1px);
}
</style>

<!-- Main Content -->
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><i class="fas fa-clock mr-2"></i>Jam Mengajar</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
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
                    <div class="jam-toolbar">
                        <ul class="nav nav-pills jam-nav-pills" id="pills-tab" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link active" id="pills-reguler-tab" data-toggle="pill" href="#pills-reguler" role="tab" aria-controls="pills-reguler" aria-selected="true">
                                    <i class="fas fa-sun mr-1"></i> Reguler (<?= count($jam_reguler) ?>)
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="pills-ramadhan-tab" data-toggle="pill" href="#pills-ramadhan" role="tab" aria-controls="pills-ramadhan" aria-selected="false">
                                    <i class="fas fa-moon mr-1"></i> Ramadhan (<?= count($jam_ramadhan) ?>)
                                </a>
                            </li>
                        </ul>

                        <div class="jam-search-wrap">
                            <i class="fas fa-search"></i>
                            <input type="search" id="jam-search-input" placeholder="Cari jam ke atau waktu..." autocomplete="off">
                        </div>
                    </div>
                    
                    <div class="tab-content" id="pills-tabContent">
                        <!-- Tab Reguler -->
                        <div class="tab-pane fade show active" id="pills-reguler" role="tabpanel" aria-labelledby="pills-reguler-tab">
                            <?php if (empty($jam_reguler)): ?>
                                <div class="text-center py-5" style="color: #64748b;">
                                    <i class="fas fa-clock fa-3x mb-3 text-muted"></i>
                                    <p class="mb-0 font-weight-bold">Belum ada data jam mengajar reguler.</p>
                                </div>
                            <?php else: ?>
                                <div class="jam-grid">
                                    <?php $no = 1; foreach ($jam_reguler as $row): 
                                        $meta = getSlotBadgeMeta($row['jam_ke']);
                                        $waktu_mulai = date('H:i', strtotime($row['waktu_mulai']));
                                        $waktu_selesai = date('H:i', strtotime($row['waktu_selesai']));
                                        $durasi = getSlotDurationText($row['waktu_mulai'], $row['waktu_selesai']);
                                    ?>
                                    <div class="jam-app-card <?= $meta['theme'] ?>">
                                        <div>
                                            <div class="jam-card-top">
                                                <span class="jam-slot-tag <?= $meta['theme'] ?>">
                                                    <i class="fas <?= $meta['icon'] ?>"></i> <?= $meta['type'] ?>
                                                </span>
                                                <?php if ($durasi): ?>
                                                <span class="jam-duration-pill">
                                                    <i class="far fa-hourglass text-primary"></i> <?= $durasi ?>
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="jam-card-main">
                                                <div class="jam-circle-ke <?= $meta['theme'] ?>">
                                                    <span class="jam-ke-label">Jam</span>
                                                    <span class="jam-ke-val"><?= htmlspecialchars($row['jam_ke']) ?></span>
                                                </div>
                                                <div class="jam-range-box">
                                                    <div class="jam-time-badge-wrap">
                                                        <div class="jam-time-badge">
                                                            <i class="far fa-clock text-warning"></i> <?= $waktu_mulai ?>
                                                        </div>
                                                        <span class="jam-time-arrow"><i class="fas fa-arrow-right"></i></span>
                                                        <div class="jam-time-badge end-time">
                                                            <i class="far fa-clock text-info"></i> <?= $waktu_selesai ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="jam-card-bottom">
                                            <span class="jam-index-text">#<?= $no++ ?> Reguler</span>
                                            <div class="jam-actions">
                                                <button class="jam-app-btn jam-btn-edit edit-btn" 
                                                        data-id="<?= $row['id_jam'] ?>"
                                                        data-jam="<?= htmlspecialchars($row['jam_ke'], ENT_QUOTES) ?>"
                                                        data-mulai="<?= $waktu_mulai ?>"
                                                        data-selesai="<?= $waktu_selesai ?>"
                                                        data-jenis="Reguler" title="Edit Jam">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button class="jam-app-btn jam-btn-del delete-btn" 
                                                        data-id="<?= $row['id_jam'] ?>"
                                                        data-jam="<?= htmlspecialchars($row['jam_ke'], ENT_QUOTES) ?>"
                                                        data-jenis="Reguler" title="Hapus Jam">
                                                    <i class="fas fa-trash"></i> Hapus
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Hidden Table for Export Reguler -->
                            <div style="display:none">
                                <table id="export-table-reguler" border="1">
                                    <thead>
                                        <tr>
                                            <th>No</th>
                                            <th>Jam Ke</th>
                                            <th>Waktu Mulai</th>
                                            <th>Waktu Selesai</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $ne = 1; foreach ($jam_reguler as $row): ?>
                                        <tr>
                                            <td><?= $ne++ ?></td>
                                            <td><?= $row['jam_ke'] ?></td>
                                            <td><?= date('H:i', strtotime($row['waktu_mulai'])) ?></td>
                                            <td><?= date('H:i', strtotime($row['waktu_selesai'])) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Tab Ramadhan -->
                        <div class="tab-pane fade" id="pills-ramadhan" role="tabpanel" aria-labelledby="pills-ramadhan-tab">
                            <?php if (empty($jam_ramadhan)): ?>
                                <div class="text-center py-5" style="color: #64748b;">
                                    <i class="fas fa-moon fa-3x mb-3 text-muted"></i>
                                    <p class="mb-0 font-weight-bold">Belum ada data jam mengajar Ramadhan.</p>
                                </div>
                            <?php else: ?>
                                <div class="jam-grid">
                                    <?php $no = 1; foreach ($jam_ramadhan as $row): 
                                        $meta = getSlotBadgeMeta($row['jam_ke']);
                                        $waktu_mulai = date('H:i', strtotime($row['waktu_mulai']));
                                        $waktu_selesai = date('H:i', strtotime($row['waktu_selesai']));
                                        $durasi = getSlotDurationText($row['waktu_mulai'], $row['waktu_selesai']);
                                    ?>
                                    <div class="jam-app-card <?= $meta['theme'] ?>">
                                        <div>
                                            <div class="jam-card-top">
                                                <span class="jam-slot-tag <?= $meta['theme'] ?>">
                                                    <i class="fas <?= $meta['icon'] ?>"></i> <?= $meta['type'] ?>
                                                </span>
                                                <?php if ($durasi): ?>
                                                <span class="jam-duration-pill">
                                                    <i class="far fa-hourglass text-primary"></i> <?= $durasi ?>
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="jam-card-main">
                                                <div class="jam-circle-ke <?= $meta['theme'] ?>">
                                                    <span class="jam-ke-label">Jam</span>
                                                    <span class="jam-ke-val"><?= htmlspecialchars($row['jam_ke']) ?></span>
                                                </div>
                                                <div class="jam-range-box">
                                                    <div class="jam-time-badge-wrap">
                                                        <div class="jam-time-badge">
                                                            <i class="far fa-clock text-warning"></i> <?= $waktu_mulai ?>
                                                        </div>
                                                        <span class="jam-time-arrow"><i class="fas fa-arrow-right"></i></span>
                                                        <div class="jam-time-badge end-time">
                                                            <i class="far fa-clock text-info"></i> <?= $waktu_selesai ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="jam-card-bottom">
                                            <span class="jam-index-text">#<?= $no++ ?> Ramadhan</span>
                                            <div class="jam-actions">
                                                <button class="jam-app-btn jam-btn-edit edit-btn" 
                                                        data-id="<?= $row['id_jam'] ?>"
                                                        data-jam="<?= htmlspecialchars($row['jam_ke'], ENT_QUOTES) ?>"
                                                        data-mulai="<?= $waktu_mulai ?>"
                                                        data-selesai="<?= $waktu_selesai ?>"
                                                        data-jenis="Ramadhan" title="Edit Jam">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button class="jam-app-btn jam-btn-del delete-btn" 
                                                        data-id="<?= $row['id_jam'] ?>"
                                                        data-jam="<?= htmlspecialchars($row['jam_ke'], ENT_QUOTES) ?>"
                                                        data-jenis="Ramadhan" title="Hapus Jam">
                                                    <i class="fas fa-trash"></i> Hapus
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Hidden Table for Export Ramadhan -->
                            <div style="display:none">
                                <table id="export-table-ramadhan" border="1">
                                    <thead>
                                        <tr>
                                            <th>No</th>
                                            <th>Jam Ke</th>
                                            <th>Waktu Mulai</th>
                                            <th>Waktu Selesai</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $ne = 1; foreach ($jam_ramadhan as $row): ?>
                                        <tr>
                                            <td><?= $ne++ ?></td>
                                            <td><?= $row['jam_ke'] ?></td>
                                            <td><?= date('H:i', strtotime($row['waktu_mulai'])) ?></td>
                                            <td><?= date('H:i', strtotime($row['waktu_selesai'])) ?></td>
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
