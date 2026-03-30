<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has guru or wali level (only grade 6)
if (!isAuthorized(['guru', 'wali'])) {
    redirect('../login.php');
}

// Get teacher information
if ($_SESSION['level'] == 'guru' || $_SESSION['level'] == 'wali') {
    $stmt = $pdo->prepare("SELECT * FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT g.* FROM tb_guru g JOIN tb_pengguna p ON g.id_guru = p.id_guru WHERE p.id_pengguna = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$teacher) {
    die('Error: Teacher data not found');
}

// Get classes - only grade 6
$classes = [];
if (!empty($teacher['mengajar'])) {
    $mengajar_decoded = json_decode($teacher['mengajar'], true);
    if (is_array($mengajar_decoded) && !empty($mengajar_decoded)) {
        $all_classes_stmt = $pdo->query("SELECT * FROM tb_kelas WHERE nama_kelas LIKE '6%' ORDER BY nama_kelas ASC");
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

// Also add the homeroom class if grade 6
$stmt_wali = $pdo->prepare("SELECT * FROM tb_kelas WHERE wali_kelas = ? AND nama_kelas LIKE '6%'");
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

// Auto-select first class if available
if (count($classes) >= 1) {
    $_GET['kelas'] = $classes[0]['id_kelas'];
}

// Get selected class - required for grade 6 only
$selected_class = isset($_GET['kelas']) ? $_GET['kelas'] : '';
if (empty($selected_class) && count($classes) > 0) {
    $selected_class = $classes[0]['id_kelas'];
}

// Handle Form Submission (Add/Edit)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_journal'])) {
    $id_kelas = (int)$_POST['id_kelas'];
    $waktu = $_POST['waktu'];
    $mapel = $_POST['mapel'];
    $materi = $_POST['materi'];
    $tanggal = $_POST['tanggal'];
    $id_guru = $teacher['id_guru'];
    
    try {
        // Check if journal already exists for this class, teacher, waktu, and date
        $check_stmt = $pdo->prepare("SELECT id FROM tb_jurnal_les WHERE id_kelas = ? AND id_guru = ? AND waktu = ? AND tanggal = ?");
        $check_stmt->execute([$id_kelas, $id_guru, $waktu, $tanggal]);
        
        if ($check_stmt->rowCount() > 0) {
            // Update existing record
            $stmt = $pdo->prepare("UPDATE tb_jurnal_les SET mapel = ?, materi = ? WHERE id_kelas = ? AND id_guru = ? AND waktu = ? AND tanggal = ?");
            $stmt->execute([$mapel, $materi, $id_kelas, $id_guru, $waktu, $tanggal]);
            $message = ['type' => 'info', 'text' => 'Data jurnal les berhasil diperbarui!'];
        } else {
            // Insert new record
            $stmt = $pdo->prepare("INSERT INTO tb_jurnal_les (id_kelas, id_guru, waktu, mapel, materi, tanggal) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$id_kelas, $id_guru, $waktu, $mapel, $materi, $tanggal]);
            $message = ['type' => 'success', 'text' => 'Data jurnal les berhasil disimpan!'];
        }
        
        $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
        logActivity($pdo, $username, 'Tambah/Edit Jurnal Les', "Guru {$teacher['nama_guru']} menyimpan jurnal les kelas $id_kelas");
        
    } catch (Exception $e) {
        $message = ['type' => 'error', 'text' => 'Gagal menyimpan data: ' . $e->getMessage()];
    }
}

// Handle Delete Action
if (isset($_POST['delete_journal'])) {
    $id_jurnal = (int)$_POST['id_jurnal'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM tb_jurnal_les WHERE id = ?");
        $stmt->execute([$id_jurnal]);
        
        $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
        logActivity($pdo, $username, 'Hapus Jurnal Les', "Guru {$teacher['nama_guru']} menghapus jurnal les ID: $id_jurnal");
        
        $message = ['type' => 'success', 'text' => 'Data jurnal les berhasil dihapus!'];
    } catch (Exception $e) {
        $message = ['type' => 'error', 'text' => 'Gagal menghapus data: ' . $e->getMessage()];
    }
}

// Get selected class
$selected_class = isset($_GET['kelas']) ? $_GET['kelas'] : '';
$class_info = [];
if ($selected_class) {
    $stmt_class = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas = ?");
    $stmt_class->execute([$selected_class]);
    $class_info = $stmt_class->fetch(PDO::FETCH_ASSOC);
}

// Get unique waktu options - hardcoded since tb_jadwal_les doesn't have 'waktu' column
$waktu_options = ['Pagi', 'Siang', 'Sore'];

// Get journal entries for selected class and guru
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
    $msg_icon = ($msg_type == 'success') ? 'success' : ($msg_type == 'info' ? 'info' : 'error');
    $msg_title = ($msg_type == 'success') ? 'Berhasil!' : ($msg_type == 'info' ? 'Informasi' : 'Error');
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
        'columnDefs': [{ 'orderable': false, 'targets': [6] }]
    });
    
    // Delete confirmation
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
                <div class="alert alert-<?php echo $message['type'] == 'success' ? 'success' : ($message['type'] == 'info' ? 'info' : 'danger'); ?> alert-dismissible" role="alert">
                    <?php echo htmlspecialchars($message['text']); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            <?php endif; ?>

            <?php if ($selected_class): ?>
            <div class="card">
                <div class="card-header">
                    <h4>Tambah Jurnal Les - <?php echo htmlspecialchars($class_info['nama_kelas']); ?></h4>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="id_kelas" value="<?php echo $selected_class; ?>">
                        
                        <div class="row">
                            <div class="form-group col-md-3">
                                <label>Tanggal</label>
                                <input type="date" name="tanggal" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            
                            <div class="form-group col-md-3">
                                <label>Waktu Les</label>
                                <select name="waktu" class="form-control" required>
                                    <option value="">-- Pilih Waktu --</option>
                                    <?php foreach ($waktu_options as $waktu): ?>
                                        <option value="<?php echo htmlspecialchars($waktu); ?>"><?php echo htmlspecialchars($waktu); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group col-md-3">
                                <label>Mata Pelajaran</label>
                                <input type="text" name="mapel" class="form-control" placeholder="Nama Mapel" required>
                            </div>
                            
                            <div class="form-group col-md-3">
                                <label>Materi</label>
                                <input type="text" name="materi" class="form-control" placeholder="Materi Pembelajaran" required>
                            </div>
                        </div>
                        
                        <button type="submit" name="save_journal" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Jurnal Les</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4>Data Jurnal Les - <?php echo htmlspecialchars($class_info['nama_kelas']); ?></h4>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover" id="table-jurnal">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Tanggal</th>
                                    <th>Waktu</th>
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
                                    <td><?php echo $no++; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($entry['tanggal'])); ?></td>
                                    <td><?php echo htmlspecialchars($entry['waktu']); ?></td>
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
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
