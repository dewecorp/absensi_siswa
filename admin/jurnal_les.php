<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$can_manage = ($user_level === 'admin');

// Handle Delete Action
if ($can_manage && isset($_POST['delete_journal'])) {
    $id_jurnal = (int)$_POST['id_jurnal'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM tb_jurnal_les WHERE id = ?");
        $stmt->execute([$id_jurnal]);
        
        $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
        logActivity($pdo, $username, 'Hapus Jurnal Les', "Admin menghapus jurnal les ID: $id_jurnal");
        
        $message = ['type' => 'success', 'text' => 'Data jurnal les berhasil dihapus!'];
    } catch (Exception $e) {
        $message = ['type' => 'error', 'text' => 'Gagal menghapus data: ' . $e->getMessage()];
    }
}

// Handle Multiple Delete Action
if ($can_manage && isset($_POST['delete_multiple_journal'])) {
    // Get IDs from JSON string in selected-ids field
    $ids_json = $_POST['selected-ids'] ?? '';
    $ids = !empty($ids_json) ? json_decode($ids_json, true) : [];
    
    if (!empty($ids)) {
        try {
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            $stmt = $pdo->prepare("DELETE FROM tb_jurnal_les WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            
            $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
            $count = count($ids);
            logActivity($pdo, $username, 'Hapus Jurnal Les Massal', "Admin menghapus $count jurnal les");
            
            $message = ['type' => 'success', 'text' => "$count data jurnal les berhasil dihapus!"];
        } catch (Exception $e) {
            $message = ['type' => 'error', 'text' => 'Gagal menghapus data: ' . $e->getMessage()];
        }
    }
}

// Get journal entries
$journal_entries = [];
$filter_title = '';

// Build filter conditions - only guru filter
$where_clauses = [];
$params = [];

if (isset($_GET['guru']) && !empty($_GET['guru'])) {
    $where_clauses[] = "jl.id_guru = ?";
    $params[] = $_GET['guru'];
    
    $stmt_g = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
    $stmt_g->execute([$_GET['guru']]);
    $guru_name = $stmt_g->fetchColumn();
    $filter_title .= ' - ' . $guru_name;
}

// Always execute query - if no filters, show all journals
$query = "SELECT jl.*, g.nama_guru, k.nama_kelas 
          FROM tb_jurnal_les jl 
          LEFT JOIN tb_guru g ON jl.id_guru = g.id_guru 
          LEFT JOIN tb_kelas k ON jl.id_kelas = k.id_kelas";

if (!empty($where_clauses)) {
    $query .= " WHERE " . implode(' AND ', $where_clauses);
}

$query .= " ORDER BY jl.tanggal DESC, jl.waktu";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$journal_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set page title
$page_title = 'Jurnal Les';

// Define CSS libraries
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'
];

// Define JS libraries
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/sweetalert2@11'
];

// Page specific JS - prepare message variables first
$js_page = [];
if (isset($message)) {
    $msg_type = $message['type'];
    $msg_icon = ($msg_type == 'success') ? 'success' : 'error';
    $msg_title = ($msg_type == 'success') ? 'Berhasil!' : 'Error';
    $msg_text = addslashes($message['text']);
    $js_page[] = <<<JS
    $(document).ready(function() {
        Swal.fire({
            icon: '$msg_icon',
            title: '$msg_title',
            text: '$msg_text',
            timer: 2000,
            showConfirmButton: false
        });
    });
JS;
}

$js_page[] = <<<JS
$(document).ready(function() {
    var t = $('#table-jurnal').DataTable({
        'language': {
            'url': '//cdn.datatables.net/plug-ins/1.10.25/i18n/Indonesian.json'
        },
        'pageLength': 50,
        'lengthMenu': [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Semua']],
        'columnDefs': [
            <?php if ($can_manage): ?>
            { 'orderable': false, 'targets': [0, 7] }
            <?php endif; ?>
        ]
    });
    
    // Delete single confirmation
    $(document).on('click', '.btn-delete', function(e) {
        e.preventDefault();
        var form = $(this).closest('.delete-form');
        var journalId = form.find('input[name="id_jurnal"]').val();
        
        Swal.fire({
            title: 'Apakah Anda yakin?',
            text: 'Data jurnal les yang dihapus tidak dapat dikembalikan!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                // Create and submit form to ensure proper submission
                var submitForm = $('<form>').attr({
                    method: 'POST',
                    action: window.location.href
                });
                
                submitForm.append($('<input>').attr({
                    type: 'hidden',
                    name: 'id_jurnal',
                    value: journalId
                }));
                
                submitForm.append($('<input>').attr({
                    type: 'hidden',
                    name: 'delete_journal',
                    value: '1'
                }));
                
                $('body').append(submitForm);
                submitForm.submit();
            }
        });
    });
    
    // Delete multiple confirmation
    $('#delete-selected-btn').on('click', function(e) {
        e.preventDefault();
        var selectedIds = [];
        $('input[name="ids[]"]:checked').each(function() {
            selectedIds.push($(this).val());
        });
        
        if (selectedIds.length === 0) {
            Swal.fire('Error', 'Pilih minimal satu data untuk dihapus', 'error');
            return;
        }
        
        Swal.fire({
            title: 'Apakah Anda yakin?',
            text: selectedIds.length + ' data jurnal les akan dihapus!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, Hapus Semua!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                // Create and submit form to ensure proper submission
                var submitForm = $('<form>').attr({
                    method: 'POST',
                    action: window.location.href
                });
                
                submitForm.append($('<input>').attr({
                    type: 'hidden',
                    name: 'selected-ids',
                    value: JSON.stringify(selectedIds)
                }));
                
                submitForm.append($('<input>').attr({
                    type: 'hidden',
                    name: 'delete_multiple_journal',
                    value: '1'
                }));
                
                $('body').append(submitForm);
                submitForm.submit();
            }
        });
    });
    
    // Select all checkbox
    $('#select-all').on('click', function() {
        var isChecked = $(this).prop('checked');
        $('input[name="ids[]"]').prop('checked', isChecked);
    });
});
JS;

include '../templates/header.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Jurnal Les</h1>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4>Data Jurnal Les 
                        <?php 
                        if (!empty($filter_title)) {
                            echo '- ' . $filter_title;
                        } else {
                            echo 'Semua Guru Kelas 6';
                        }
                        ?>
                    </h4>
                    <div class="card-header-action">
                        <div class="btn-group">
                            <a href="../config/export_jurnal_les_pdf.php?session_type=admin&guru=<?php echo $_GET['guru'] ?? ''; ?>" target="_blank" class="btn btn-danger">
                                <i class="fas fa-file-pdf"></i> PDF
                            </a>
                            <a href="../config/export_jurnal_les_excel.php?session_type=admin&guru=<?php echo $_GET['guru'] ?? ''; ?>" target="_blank" class="btn btn-success">
                                <i class="fas fa-file-excel"></i> Excel
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Filter Section -->
                    <form method="GET" class="mb-4">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Filter Guru</label>
                                    <select name="guru" class="form-control" onchange="this.form.submit()">
                                        <option value="">Semua Guru Kelas 6</option>
                                        <?php 
                                        // Get ALL grade 6 teachers from schedule (tb_jadwal_pelajaran)
                                        // This includes teachers who may not have journal entries yet
                                        $stmt_guru_kelas_6 = $pdo->prepare("
                                            SELECT DISTINCT g.id_guru, g.nama_guru
                                            FROM tb_guru g
                                            INNER JOIN tb_jadwal_pelajaran jp ON g.id_guru = jp.guru_id
                                            INNER JOIN tb_kelas k ON jp.kelas_id = k.id_kelas
                                            WHERE k.nama_kelas LIKE '%6%' OR k.nama_kelas LIKE '%VI%'
                                            UNION
                                            SELECT id_guru, nama_guru
                                            FROM tb_guru
                                            WHERE nama_guru IN (SELECT wali_kelas FROM tb_kelas WHERE nama_kelas LIKE '%6%' OR nama_kelas LIKE '%VI%')
                                            ORDER BY nama_guru ASC
                                        ");
                                        $stmt_guru_kelas_6->execute();
                                        $guru_kelas_6 = $stmt_guru_kelas_6->fetchAll(PDO::FETCH_ASSOC);
                                        
                                        foreach ($guru_kelas_6 as $t): 
                                        ?>
                                            <option value="<?php echo $t['id_guru']; ?>" <?php echo (isset($_GET['guru']) && $_GET['guru'] == $t['id_guru']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($t['nama_guru']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </form>
                    
                    <?php if (!empty($journal_entries)): ?>
                    <form method="POST" id="delete-multiple-form">
                        <input type="hidden" name="selected-ids" id="selected-ids">
                        
                        <?php if ($can_manage): ?>
                        <div class="mb-3">
                            <button type="button" class="btn btn-danger" id="delete-selected-btn">
                                <i class="fas fa-trash"></i> Hapus Dipilih
                            </button>
                        </div>
                        <?php endif; ?>
                        
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover" id="table-jurnal">
                                <thead>
                                    <tr>
                                        <?php if ($can_manage): ?>
                                        <th width="30">
                                            <input type="checkbox" id="select-all">
                                        </th>
                                        <?php endif; ?>
                                        <th>No</th>
                                        <th>Tanggal</th>
                                        <th>Waktu</th>
                                        <th>Guru</th>
                                        <th>Mapel</th>
                                        <th>Materi</th>
                                        <?php if ($can_manage): ?>
                                        <th>Aksi</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $no = 1;
                                    foreach ($journal_entries as $entry): 
                                    ?>
                                    <tr>
                                        <?php if ($can_manage): ?>
                                        <td>
                                            <input type="checkbox" name="ids[]" value="<?php echo $entry['id']; ?>">
                                        </td>
                                        <?php endif; ?>
                                        <td><?php echo $no++; ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($entry['tanggal'])); ?></td>
                                        <td><?php echo htmlspecialchars($entry['waktu']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['nama_guru'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($entry['mapel']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['materi']); ?></td>
                                        <?php if ($can_manage): ?>
                                        <td>
                                            <form method="POST" style="display:inline;" class="delete-form">
                                                <input type="hidden" name="id_jurnal" value="<?php echo $entry['id']; ?>">
                                                <button type="submit" name="delete_journal" class="btn btn-sm btn-danger btn-delete" title="Hapus">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        <?php if (!empty($where_clauses)): ?>
                            Belum ada data jurnal les untuk filter yang dipilih. Silakan ubah filter atau reset untuk melihat semua data.
                        <?php else: ?>
                            Belum ada data jurnal les. Pastikan guru/wali sudah mengisi jurnal les terlebih dahulu.
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
