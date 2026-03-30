<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}

// Handle Delete Action
if (isset($_POST['delete_journal'])) {
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
if (isset($_POST['delete_multiple_journal'])) {
    $ids = $_POST['ids'] ?? [];
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

// Get all classes (only grade 6)
$stmt = $pdo->query("SELECT * FROM tb_kelas WHERE nama_kelas LIKE '6%' ORDER BY nama_kelas ASC");
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set default to first grade 6 class if no filter selected
if (empty($_GET['kelas']) && count($classes) > 0) {
    $_GET['kelas'] = $classes[0]['id_kelas'];
}

// Get all teachers
$stmt_guru = $pdo->query("SELECT * FROM tb_guru ORDER BY nama_guru ASC");
$teachers = $stmt_guru->fetchAll(PDO::FETCH_ASSOC);

// Get unique waktu options - hardcoded since tb_jadwal_les doesn't have 'waktu' column
$waktu_options = ['Pagi', 'Siang', 'Sore'];

// Get journal entries
$journal_entries = [];
$class_info = [];
$filter_title = '';

$where_clauses = [];
$params = [];

if (isset($_GET['kelas']) && !empty($_GET['kelas'])) {
    $where_clauses[] = "jl.id_kelas = ?";
    $params[] = $_GET['kelas'];
    
    $stmt_class = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas = ?");
    $stmt_class->execute([$_GET['kelas']]);
    $class_info = $stmt_class->fetch(PDO::FETCH_ASSOC);
    $filter_title .= ($filter_title ? ' - ' : '') . ($class_info['nama_kelas'] ?? '');
}

if (isset($_GET['guru']) && !empty($_GET['guru'])) {
    $where_clauses[] = "jl.id_guru = ?";
    $params[] = $_GET['guru'];
    
    $stmt_g = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
    $stmt_g->execute([$_GET['guru']]);
    $guru_name = $stmt_g->fetchColumn();
    $filter_title .= ($filter_title ? ' - ' : '') . $guru_name;
}

if (isset($_GET['waktu']) && !empty($_GET['waktu'])) {
    $where_clauses[] = "jl.waktu = ?";
    $params[] = $_GET['waktu'];
    $filter_title .= ($filter_title ? ' - ' : '') . $_GET['waktu'];
}

if (!empty($params)) {
    $query = "SELECT jl.*, g.nama_guru, k.nama_kelas 
              FROM tb_jurnal_les jl 
              LEFT JOIN tb_guru g ON jl.id_guru = g.id_guru 
              LEFT JOIN tb_kelas k ON jl.id_kelas = k.id_kelas
              WHERE " . implode(' AND ', $where_clauses) . "
              ORDER BY jl.tanggal DESC, jl.waktu";
              
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $journal_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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
$msg_alert = '';
if (isset($message)) {
    $msg_type = $message['type'];
    $msg_icon = ($msg_type == 'success') ? 'success' : 'error';
    $msg_title = ($msg_type == 'success') ? 'Berhasil!' : 'Error';
    $msg_text = addslashes($message['text']);
    $msg_alert = "Swal.fire({icon: '$msg_icon', title: '$msg_title', text: '$msg_text', timer: 2000});";
}

$js_page = [];
if (!empty($msg_alert)) {
    $js_page[] = "$(document).ready(function() { $msg_alert });";
}

$js_page[] = <<<JS
$(document).ready(function() {
    var t = $('#table-jurnal').DataTable({
        'language': {
            'url': '//cdn.datatables.net/plug-ins/1.10.25/i18n/Indonesian.json'
        },
        'pageLength': 50,
        'lengthMenu': [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Semua']],
        'columnDefs': [{ 'orderable': false, 'targets': [8] }]
    });
    
    // Delete single confirmation
    $(document).on('click', '.btn-delete', function(e) {
        e.preventDefault();
        var form = $(this).closest('form');
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
                form.submit();
            }
        });
    });
    
    // Delete multiple confirmation
    $('#delete-selected-btn').on('click', function(e) {
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
                $('#selected-ids').val(JSON.stringify(selectedIds));
                $('#delete-multiple-form').submit();
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
            <?php if (isset($message)): ?>
                <div class="alert alert-<?php echo $message['type'] == 'success' ? 'success' : 'danger'; ?> alert-dismissible" role="alert">
                    <?php echo htmlspecialchars($message['text']); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h4>Data Jurnal Les <?php echo $filter_title ? '- ' . $filter_title : ''; ?></h4>
                </div>
                <div class="card-body">
                    <?php if (!empty($journal_entries)): ?>
                    <form method="POST" id="delete-multiple-form">
                        <input type="hidden" name="selected-ids" id="selected-ids">
                        
                        <div class="mb-3">
                            <button type="button" class="btn btn-danger" id="delete-selected-btn">
                                <i class="fas fa-trash"></i> Hapus Dipilih
                            </button>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover" id="table-jurnal">
                                <thead>
                                    <tr>
                                        <th width="30">
                                            <input type="checkbox" id="select-all">
                                        </th>
                                        <th>No</th>
                                        <th>Tanggal</th>
                                        <th>Waktu</th>
                                        <th>Kelas</th>
                                        <th>Guru</th>
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
                                        <td>
                                            <input type="checkbox" name="ids[]" value="<?php echo $entry['id']; ?>">
                                        </td>
                                        <td><?php echo $no++; ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($entry['tanggal'])); ?></td>
                                        <td><?php echo htmlspecialchars($entry['waktu']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['nama_kelas']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['nama_guru'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($entry['mapel']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['materi']); ?></td>
                                        <td>
                                            <form method="POST" style="display:inline;">
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
                    </form>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Belum ada data jurnal les untuk filter yang dipilih.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
