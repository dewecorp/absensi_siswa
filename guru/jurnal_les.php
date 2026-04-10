<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has guru, wali, or admin level
if (!isAuthorized(['guru', 'wali', 'admin'])) {
    redirect('../login.php');
}

// Get teacher information
$teacher = null;

// Method 1: Direct query from tb_guru (for sessions with id_guru)
if (isset($_SESSION['user_id']) && ($_SESSION['level'] == 'guru' || $_SESSION['level'] == 'wali')) {
    $stmt = $pdo->prepare("SELECT * FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Method 2: Query through tb_pengguna (fallback if method 1 fails)
if (!$teacher && isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
    $stmt = $pdo->prepare("SELECT g.* FROM tb_guru g JOIN tb_pengguna p ON g.id_guru = p.id_guru WHERE p.id_pengguna = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Final fallback: try joining tables without checking login_source
if (!$teacher) {
    $stmt = $pdo->prepare("SELECT g.* FROM tb_guru g LEFT JOIN tb_pengguna p ON g.id_guru = p.id_guru WHERE p.id_pengguna = ? OR g.id_guru = ?");
    $stmt->execute([$_SESSION['user_id'] ?? 0, $_SESSION['user_id'] ?? 0]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$teacher) {
    die('Error: Teacher data not found');
}

// Get classes
$classes = [];
if (!empty($teacher['mengajar'])) {
    $mengajar_decoded = json_decode($teacher['mengajar'], true);
    if (is_array($mengajar_decoded) && !empty($mengajar_decoded)) {
        $all_classes_stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
        $all_classes = $all_classes_stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($mengajar_decoded as $kelas_id) {
            $kelas_id_int = is_numeric($kelas_id) ? (int)$kelas_id : null;
            foreach ($all_classes as $kelas) {
                $match = false;
                if ($kelas_id_int !== null && $kelas['id_kelas'] == $kelas_id_int) {
                    $match = true;
                } elseif ((string)$kelas['id_kelas'] == (string)$kelas_id) {
                    $match = true;
                } elseif ($kelas['nama_kelas'] == $kelas_id) {
                    $match = true;
                }
                if ($match) {
                    $exists = false;
                    foreach ($classes as $existing_class) {
                        if ($existing_class['id_kelas'] == $kelas['id_kelas']) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $classes[] = $kelas;
                    }
                    break;
                }
            }
        }
    }
}

// Also add the homeroom class
$stmt_wali = $pdo->prepare("SELECT * FROM tb_kelas WHERE wali_kelas = ?");
$stmt_wali->execute([$teacher['nama_guru']]);
$homeroom_class = $stmt_wali->fetch(PDO::FETCH_ASSOC);

if ($homeroom_class) {
    $exists = false;
    foreach ($classes as $c) {
        if ($c['id_kelas'] == $homeroom_class['id_kelas']) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        $classes[] = $homeroom_class;
    }
}

// Auto-select Grade 6 classes only (kelas 6)
$selected_class = 0;
if (!empty($classes)) {
    // Find first grade 6 class
    foreach ($classes as $c) {
        if (strpos(strtolower($c['nama_kelas']), '6') !== false || strpos(strtolower($c['nama_kelas']), 'vi') !== false) {
            $selected_class = $c['id_kelas'];
            break;
        }
    }
    // If no grade 6 found, use first class
    if ($selected_class == 0 && count($classes) > 0) {
        $selected_class = $classes[0]['id_kelas'];
    }
}

// Handle Form Submission (Add/Edit)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_journal'])) {
    $id_jurnal = !empty($_POST['id_jurnal']) ? (int)$_POST['id_jurnal'] : null;
    // Get class from POST first (when form submitted), fallback to GET/filter
    $id_kelas = isset($_POST['kelas_filter']) ? (int)$_POST['kelas_filter'] : (isset($selected_class) ? $selected_class : 0);
    $mapel_array = $_POST['mapel']; // Array dari multiselect
    $mapel = implode(', ', $mapel_array); // Gabungkan dengan koma untuk disimpan
    $materi = $_POST['materi'];
    $tanggal = $_POST['tanggal'];
    $id_guru = $teacher['id_guru'];
    
    // Get waktu from tb_jadwal_les based on the date selected
    $stmt_waktu_check = $pdo->prepare("SELECT CONCAT(TIME_FORMAT(waktu_mulai, '%H.%i'), ' - ', TIME_FORMAT(waktu_selesai, '%H.%i')) AS waktu_range FROM tb_jadwal_les WHERE id_guru = ? AND tanggal = ? LIMIT 1");
    $stmt_waktu_check->execute([$id_guru, $tanggal]);
    $waktu_result = $stmt_waktu_check->fetch(PDO::FETCH_ASSOC);
    $waktu = $waktu_result ? $waktu_result['waktu_range'] : '';
    
    // Validate if teacher has les schedule for this date
    $validation_error = false;
    $stmt_check_schedule = $pdo->prepare("
        SELECT COUNT(*) 
        FROM tb_jadwal_les 
        WHERE id_guru = ? 
        AND tanggal = ?
    ");
    $stmt_check_schedule->execute([$id_guru, $tanggal]);
    $has_schedule = $stmt_check_schedule->fetchColumn() > 0;
    
    if (!$has_schedule) {
        $validation_error = true;
        $message = ['type' => 'error', 'text' => 'Anda tidak memiliki jadwal les untuk tanggal tersebut. Tidak dapat mengisi jurnal les.'];
    }
    
    // Also validate if waktu is empty
    if (empty($waktu)) {
        $validation_error = true;
        $message = ['type' => 'error', 'text' => 'Tidak ada jadwal les yang ditemukan untuk tanggal ini.'];
    }

    if (!$validation_error) {
        try {
            if ($id_jurnal) {
                // Update existing record
                $stmt = $pdo->prepare("UPDATE tb_jurnal_les SET id_kelas = ?, id_guru = ?, waktu = ?, mapel = ?, materi = ?, tanggal = ? WHERE id = ?");
                $stmt->execute([$id_kelas, $id_guru, $waktu, $mapel, $materi, $tanggal, $id_jurnal]);
                $message = ['type' => 'info', 'text' => 'Data jurnal les berhasil diperbarui!'];
            } else {
                // Insert new record
                $stmt = $pdo->prepare("INSERT INTO tb_jurnal_les (id_kelas, id_guru, waktu, mapel, materi, tanggal) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$id_kelas, $id_guru, $waktu, $mapel, $materi, $tanggal]);
                $message = ['type' => 'success', 'text' => 'Data jurnal les berhasil disimpan!'];
            }
            
            $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
            logActivity($pdo, $username, 'Tambah/Edit Jurnal Les', "Guru {$teacher['nama_guru']} menyimpan jurnal les kelas $id_kelas");
            
            // Add notification for Admin/Kepala
            if (in_array($_SESSION['level'], ['guru', 'wali'])) {
                $nama_guru = $teacher['nama_guru'];
                $role_label = ($_SESSION['level'] == 'wali') ? 'Wali' : 'Guru';
                $action_label = $id_jurnal ? 'memperbarui' : 'mengisi';
                $msg = "$nama_guru ($role_label) telah $action_label jurnal les pada " . date('d-m-Y H:i');
                createNotification($pdo, $msg, 'jurnal_les.php');
            }
        } catch (Exception $e) {
            $message = ['type' => 'error', 'text' => 'Gagal menyimpan data: ' . $e->getMessage()];
        }
    }
}

// Handle Delete Action
if (isset($_POST['delete_journal'])) {
    $id_jurnal = (int)$_POST['id_jurnal'];
    
    try {
        // Check if user is admin
        $is_admin = ($_SESSION['level'] === 'admin');
        
        // Verify journal exists
        $stmt_check = $pdo->prepare("SELECT id_guru, id_kelas FROM tb_jurnal_les WHERE id = ?");
        $stmt_check->execute([$id_jurnal]);
        $journal = $stmt_check->fetch(PDO::FETCH_ASSOC);
        
        if (!$journal) {
            $message = ['type' => 'error', 'text' => 'Data jurnal les tidak ditemukan!'];
        } elseif (!$is_admin && $journal['id_guru'] != $teacher['id_guru']) {
            // Non-admin can only delete their own journals
            $message = ['type' => 'error', 'text' => 'Anda tidak memiliki izin untuk menghapus data ini!'];
        } else {
            // Admin can delete any, teacher can delete own
            if ($is_admin) {
                $stmt = $pdo->prepare("DELETE FROM tb_jurnal_les WHERE id = ?");
                $stmt->execute([$id_jurnal]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM tb_jurnal_les WHERE id = ? AND id_guru = ?");
                $stmt->execute([$id_jurnal, $teacher['id_guru']]);
            }
            
            $deleted_rows = $stmt->rowCount();
            
            if ($deleted_rows > 0) {
                $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                $actor = $is_admin ? 'Admin' : "Guru {$teacher['nama_guru']}";
                logActivity($pdo, $username, 'Hapus Jurnal Les', "$actor menghapus jurnal les ID: $id_jurnal");
                
                $message = ['type' => 'success', 'text' => 'Data jurnal les berhasil dihapus!'];
            } else {
                $message = ['type' => 'error', 'text' => 'Gagal menghapus data. Silakan coba lagi.'];
            }
        }
    } catch (Exception $e) {
        error_log('Jurnal Les Delete Error: ' . $e->getMessage());
        $message = ['type' => 'error', 'text' => 'Gagal menghapus data: ' . $e->getMessage()];
    }
}

// Get journal entries for the auto-selected class
$journal_entries = [];
if ($selected_class) {
    $stmt_journal = $pdo->prepare("SELECT jl.*, g.nama_guru, k.nama_kelas 
                                    FROM tb_jurnal_les jl 
                                    LEFT JOIN tb_guru g ON jl.id_guru = g.id_guru 
                                    LEFT JOIN tb_kelas k ON jl.id_kelas = k.id_kelas 
                                    WHERE jl.id_kelas = ? AND jl.id_guru = ?
                                    ORDER BY jl.tanggal DESC, jl.waktu");
    $stmt_journal->execute([$selected_class, $teacher['id_guru']]);
    $journal_entries = $stmt_journal->fetchAll(PDO::FETCH_ASSOC);
}

// Get class info
$class_info = [];
if ($selected_class) {
    $stmt_class = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas = ?");
    $stmt_class->execute([$selected_class]);
    $class_info = $stmt_class->fetch(PDO::FETCH_ASSOC);
}

// Get subjects taught by the teacher from tb_jadwal_pelajaran
$stmt_mapel = $pdo->prepare("
    SELECT DISTINCT m.nama_mapel 
    FROM tb_jadwal_pelajaran j
    JOIN tb_mata_pelajaran m ON j.mapel_id = m.id_mapel 
    WHERE j.guru_id = ? 
    AND m.nama_mapel NOT LIKE '%Asmaul Husna%' 
    AND m.nama_mapel NOT LIKE '%Upacara%' 
    AND m.nama_mapel NOT LIKE '%Istirahat%' 
    AND m.nama_mapel NOT LIKE '%Kepramukaan%' 
    AND m.nama_mapel NOT LIKE '%Ekstrakurikuler%'
    ORDER BY m.nama_mapel ASC
");
$stmt_mapel->execute([$teacher['id_guru']]);
$mapel_options = $stmt_mapel->fetchAll(PDO::FETCH_COLUMN);

// Get unique waktu options from tb_jadwal_les for the logged-in teacher, ensuring start time is before end time
$stmt_waktu = $pdo->prepare("
    SELECT DISTINCT CONCAT(TIME_FORMAT(waktu_mulai, '%H.%i'), ' - ', TIME_FORMAT(waktu_selesai, '%H.%i')) AS waktu_range, waktu_mulai 
    FROM tb_jadwal_les 
    WHERE id_guru = ? AND waktu_mulai < waktu_selesai 
    ORDER BY waktu_mulai
");
$stmt_waktu->execute([$teacher['id_guru']]);
$waktu_options = $stmt_waktu->fetchAll(PDO::FETCH_COLUMN, 0);

// Set page title
$page_title = 'Jurnal Les';

// Define CSS libraries
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css'
];

// Define JS libraries
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/sweetalert2@11',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.full.min.js'
];

// Page specific JS with SweetAlert2 only (no Bootstrap alerts)
$js_page = [];

// Check for delete success message from sessionStorage
$js_page[] = <<<JS
$(document).ready(function() {
    // Check if there's a delete success message
    var deleteSuccess = sessionStorage.getItem('deleteSuccess');
    if (deleteSuccess) {
        // Clear the message
        sessionStorage.removeItem('deleteSuccess');
        
        // Show success alert
        Swal.fire({
            icon: 'success',
            title: 'Berhasil!',
            text: deleteSuccess,
            timer: 3000,
            showConfirmButton: false
        });
    }
});
JS;

if (isset($message)) {
    $msg_type = $message['type'];
    $msg_icon = ($msg_type == 'success') ? 'success' : ($msg_type == 'info' ? 'info' : 'error');
    $msg_title = ($msg_type == 'success') ? 'Berhasil!' : ($msg_type == 'info' ? 'Informasi!' : 'Gagal!');
    $msg_text = addslashes($message['text']);
    $js_page[] = <<<JS
    $(document).ready(function() {
        Swal.fire({
            icon: '$msg_icon',
            title: '$msg_title',
            text: '$msg_text',
            timer: 3000,
            showConfirmButton: false
        });
    });
JS;
}

$js_page[] = <<<JS
$(document).ready(function() {
    // Add custom CSS for Select2 compact styling
    $('head').append('<style>' +
        '.select2-container .select2-selection--multiple { min-height: 38px !important; height: 38px !important; border: 1px solid #ced4da !important; padding: 0 5px !important; display: flex !important; align-items: center !important; }' +
        '.select2-container--default.select2-container--focus .select2-selection--multiple { border-color: #80bdff !important; }' +
        '.select2-container--default .select2-selection--multiple .select2-selection__choice { background-color: #007bff !important; border: 1px solid #007bff !important; color: white !important; padding: 1px 6px !important; margin: 2px 2px !important; border-radius: 3px !important; line-height: 1.5 !important; font-size: 0.875rem !important; }' +
        '.select2-container--default .select2-selection--multiple .select2-selection__choice__remove { color: white !important; margin-right: 4px !important; float: left !important; }' +
        '.select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover { background-color: rgba(255,255,255,0.3) !important; color: white !important; }' +
        '.select2-container--default .select2-selection--multiple .select2-selection__rendered { padding: 0 !important; margin: 0 !important; display: flex !important; flex-wrap: wrap !important; align-items: center !important; }' +
        '.select2-container--default .select2-selection--multiple .select2-search--inline { margin: 0 !important; }' +
        '.select2-container--default .select2-selection--multiple .select2-search--inline .select2-search__field { margin-top: 0 !important; padding: 2px !important; height: 24px !important; }' +
        '.select2-dropdown { border: 1px solid #ced4da !important; }' +
        '.select2-search--dropdown .select2-search__field { border: 1px solid #ced4da !important; padding: 5px !important; }' +
        '</style>');
    
    // Initialize Select2 for multiselect mapel
    $('#mapel').select2({
        placeholder: '-- Pilih Mata Pelajaran --',
        allowClear: true,
        width: '100%'
    });
    
    var t = $('#table-jurnal').DataTable({
        'language': {
            'url': 'https://cdn.datatables.net/plug-ins/1.10.25/i18n/Indonesian.json'
        },
        'pageLength': 50,
        'lengthMenu': [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Semua']],
        'columnDefs': [{ 'orderable': false, 'targets': [5] }]
    });
    
    // Edit button handler - properly loads data into modal
    $(document).on('click', '.btn-edit', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var tanggal = $(this).data('tanggal');
        var waktu = $(this).data('waktu');
        var mapel = $(this).data('mapel');
        var materi = $(this).data('materi');
        
        console.log('Editing journal ID:', id);
        console.log('Data:', { tanggal, waktu, mapel, materi });
        
        // Fill form fields
        $('#journal_id').val(id);
        $('#tanggal').val(tanggal);
        $('#waktu').val(waktu);
        
        // Handle multiple mapel (split by comma)
        var mapelArray = mapel.split(', ').map(function(item) { return item.trim(); });
        $('#mapel').val(mapelArray).trigger('change');
        
        $('#materi').val(materi);
        
        // Update modal title
        $('#jurnalModalLabel').text('Edit Jurnal Les');
        
        // Show modal
        $('#jurnalModal').modal('show');
    });
    
    // Delete confirmation
    $(document).on('click', '.btn-delete', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        console.log('Delete button clicked');
        
        var btn = $(this);
        var form = btn.closest('.delete-form');
        var idJurnal = form.find('input[name="id_jurnal"]').val();
        
        console.log('Journal ID to delete:', idJurnal);
        
        if (typeof Swal === 'undefined') {
            alert('ERROR: SweetAlert tidak tersedia!');
            return;
        }
        
        Swal.fire({
            title: 'Apakah Anda yakin?',
            text: 'Data jurnal les yang dihapus tidak dapat dikembalikan!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal',
            reverseButtons: true
        }).then((result) => {
            console.log('Swal result:', result);
            
            if (result.isConfirmed) {
                console.log('User confirmed delete, sending AJAX request...');
                
                // Show loading
                Swal.fire({
                    title: 'Menghapus...',
                    text: 'Mohon tunggu',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                // Send AJAX request
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        delete_journal: 1,
                        id_jurnal: idJurnal
                    },
                    success: function(response) {
                        console.log('Delete successful');
                        
                        // Store success message in sessionStorage before reload
                        sessionStorage.setItem('deleteSuccess', 'Data jurnal les berhasil dihapus!');
                        
                        // Reload page to see changes
                        window.location.reload();
                    },
                    error: function(xhr, status, error) {
                        console.error('Delete failed:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal!',
                            text: 'Terjadi kesalahan saat menghapus data.',
                            timer: 3000,
                            showConfirmButton: false
                        });
                    }
                });
            } else {
                console.log('User cancelled delete');
            }
        }).catch(err => {
            console.error('Swal error:', err);
            alert('Error showing confirmation: ' + err.message);
        });
    });
    
    // Modal reset on hide
    $('#jurnalModal').on('hidden.bs.modal', function() {
        $('#journalForm')[0].reset();
        $('#journal_id').val('');
        $('#tanggal').val(new Date().toISOString().split('T')[0]); // Reset to today
        $('.invalid-feedback').hide();
        $('.form-control').removeClass('is-invalid');
        $('#mapel').val(null).trigger('change'); // Reset Select2 multiselect
        $('#jurnalModalLabel').text('Tambah Jurnal Les');
    });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Jurnal Les</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Jurnal</div>
                <div class="breadcrumb-item">Jurnal Les</div>
            </div>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4>Jurnal Les Kelas 6</h4>
                    <div class="card-header-action">
                        <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#jurnalModal">
                            <i class="fas fa-plus"></i> Tambah Jurnal Les
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($journal_entries)): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover" id="table-jurnal">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Tanggal</th>
                                    <th>Waktu</th>
                                    <th>Mapel</th>
                                    <th>Materi</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $no = 1;
                                foreach ($journal_entries as $entry): 
                                ?>
                                <tr>
                                    <td><?php echo $no++; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($entry['tanggal'])); ?></td>
                                    <td><?php echo htmlspecialchars($entry['waktu']); ?></td>
                                    <td><?php echo htmlspecialchars($entry['mapel']); ?></td>
                                    <td><?php echo htmlspecialchars($entry['materi']); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-info btn-edit" 
                                                data-id="<?php echo $entry['id']; ?>" 
                                                data-tanggal="<?php echo $entry['tanggal']; ?>" 
                                                data-waktu="<?php echo htmlspecialchars($entry['waktu']); ?>" 
                                                data-mapel="<?php echo htmlspecialchars($entry['mapel']); ?>" 
                                                data-materi="<?php echo htmlspecialchars($entry['materi']); ?>">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <form method="POST" style="display:inline;" class="delete-form">
                                            <input type="hidden" name="id_jurnal" value="<?php echo $entry['id']; ?>">
                                            <button type="submit" name="delete_journal" class="btn btn-sm btn-danger btn-delete" title="Hapus">
                                                <i class="fas fa-trash"></i> Hapus
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Belum ada data jurnal les untuk kelas ini. Silakan tambah data dengan menekan tombol "Tambah Jurnal Les".
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Modal for Add/Edit -->
<div class="modal fade" id="jurnalModal" tabindex="-1" role="dialog" aria-labelledby="jurnalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" action="" id="journalForm">
                <input type="hidden" name="id_jurnal" id="journal_id" value="">
                <input type="hidden" name="kelas_filter" id="kelas_filter" value="<?php echo $selected_class; ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="jurnalModalLabel">Tambah Jurnal Les</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="tanggal">Tanggal <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="tanggal" name="tanggal" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="alert alert-info">
                        <small><i class="fas fa-info-circle"></i> <strong>Catatan:</strong> Isi jurnal les sesuai dengan tanggal yang dipilih. Sistem akan otomatis memvalidasi kesesuaian dengan jadwal les Anda.</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="mapel">Mata Pelajaran <span class="text-danger">*</span></label>
                        <select class="form-control" id="mapel" name="mapel[]" multiple="multiple" required>
                            <?php foreach ($mapel_options as $mapel): ?>
                                <option value="<?php echo htmlspecialchars($mapel); ?>"><?php echo htmlspecialchars($mapel); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">Anda dapat memilih lebih dari satu mata pelajaran</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="materi">Materi Pokok <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="materi" name="materi" rows="4" placeholder="Tulis materi pokok pembelajaran..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" name="save_journal" class="btn btn-primary">Simpan Jurnal Les</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
