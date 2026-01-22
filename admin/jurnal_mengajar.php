<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

// Handle Delete Action
if (isset($_POST['delete_journal'])) {
    $id_jurnal = (int)$_POST['id_jurnal'];
    $current_class = isset($_GET['kelas']) ? $_GET['kelas'] : '';
    
    try {
        $stmt = $pdo->prepare("DELETE FROM tb_jurnal WHERE id = ?");
        $stmt->execute([$id_jurnal]);
        
        $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
        logActivity($pdo, $username, 'Hapus Jurnal', "Admin menghapus jurnal ID: $id_jurnal");
        
        $message = ['type' => 'success', 'text' => 'Data jurnal berhasil dihapus!'];
    } catch (Exception $e) {
        $message = ['type' => 'error', 'text' => 'Gagal menghapus data: ' . $e->getMessage()];
    }
}

// Get all classes
$stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get teaching hours mapping
$stmt_jam = $pdo->query("SELECT * FROM tb_jam_mengajar");
$jam_mengajar_rows = $stmt_jam->fetchAll(PDO::FETCH_ASSOC);
$jam_map = [];
foreach ($jam_mengajar_rows as $row) {
    $jam_map[$row['jam_ke']] = [
        'mulai' => date('H:i', strtotime($row['waktu_mulai'])),
        'selesai' => date('H:i', strtotime($row['waktu_selesai']))
    ];
}

// Get journal entries if class is selected
$journal_entries = [];
$class_info = [];

if (isset($_GET['kelas']) && !empty($_GET['kelas'])) {
    $id_kelas = (int)$_GET['kelas'];
    
    // Get class info
    $stmt_class = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas = ?");
    $stmt_class->execute([$id_kelas]);
    $class_info = $stmt_class->fetch(PDO::FETCH_ASSOC);
    
    // Get journal entries
    // Joining with tb_guru to get teacher name if needed, though not requested in columns
    // Columns requested: No, Jam Ke, Mata Pelajaran, Materi Pokok, Aksi
    $query = "SELECT j.*, g.nama_guru 
              FROM tb_jurnal j 
              LEFT JOIN tb_guru g ON j.id_guru = g.id_guru 
              WHERE j.id_kelas = ? 
              ORDER BY j.tanggal DESC, j.jam_ke ASC";
              
    $stmt = $pdo->prepare($query);
    $stmt->execute([$id_kelas]);
    $journal_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Set page title
$page_title = 'Jurnal Mengajar';

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

// Page specific JS
$js_page = [
    "
    $(document).ready(function() {
        $('#table-jurnal').DataTable({
            'language': {
                'url': '//cdn.datatables.net/plug-ins/1.10.25/i18n/Indonesian.json'
            },
            'order': [[ 4, 'desc' ]] // Sort by date (hidden column or visible)
        });
    });

    function confirmDelete(id) {
        Swal.fire({
            title: 'Apakah Anda yakin?',
            text: 'Data jurnal yang dihapus tidak dapat dikembalikan!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                // Create a form and submit it
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = window.location.href;
                
                var inputId = document.createElement('input');
                inputId.type = 'hidden';
                inputId.name = 'id_jurnal';
                inputId.value = id;
                
                var inputDelete = document.createElement('input');
                inputDelete.type = 'hidden';
                inputDelete.name = 'delete_journal';
                inputDelete.value = '1';
                
                form.appendChild(inputId);
                form.appendChild(inputDelete);
                document.body.appendChild(form);
                form.submit();
            }
        });
    }
    "
];

include '../templates/header.php';
?>

<!-- Main Content -->
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Jurnal Mengajar</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Jurnal Mengajar</div>
            </div>
        </div>

        <?php if (isset($message)): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    title: '<?php echo $message['type'] === 'success' ? 'Sukses!' : 'Info!'; ?>',
                    text: '<?php echo addslashes($message['text']); ?>',
                    icon: '<?php echo $message['type']; ?>',
                    timer: 3000,
                    timerProgressBar: true,
                    showConfirmButton: false
                });
            });
        </script>
        <?php endif; ?>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4>Filter Data</h4>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="">
                            <div class="form-group row mb-4">
                                <label class="col-form-label text-md-right col-12 col-md-3 col-lg-3">Pilih Kelas</label>
                                <div class="col-sm-12 col-md-7">
                                    <select class="form-control select2" name="kelas" onchange="this.form.submit()">
                                        <option value="">-- Pilih Kelas --</option>
                                        <?php foreach ($classes as $class): ?>
                                            <option value="<?php echo $class['id_kelas']; ?>" <?php echo (isset($_GET['kelas']) && $_GET['kelas'] == $class['id_kelas']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($class['nama_kelas']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isset($_GET['kelas']) && !empty($_GET['kelas'])): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4>Data Jurnal Mengajar - <?php echo isset($class_info['nama_kelas']) ? htmlspecialchars($class_info['nama_kelas']) : ''; ?></h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped" id="table-jurnal">
                                <thead>
                                    <tr>
                                        <th class="text-center" style="width: 50px;">No</th>
                                        <th>Tanggal</th>
                                        <th>Jam Ke</th>
                                        <th>Waktu</th>
                                        <th>Mata Pelajaran</th>
                                        <th>Materi Pokok</th>
                                        <th>Guru</th>
                                        <th style="width: 100px;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($journal_entries) > 0): ?>
                                        <?php $no = 1; foreach ($journal_entries as $journal): ?>
                                        <tr>
                                            <td class="text-center"><?php echo $no++; ?></td>
                                            <td><?php echo date('d-m-Y', strtotime($journal['tanggal'])); ?></td>
                                            <td><?php echo htmlspecialchars($journal['jam_ke']); ?></td>
                                            <td>
                                                <?php 
                                                $jam_list = explode(',', $journal['jam_ke']);
                                                $waktu_str = '-';
                                                if (!empty($jam_list)) {
                                                    // Clean and convert to int
                                                    $jam_list = array_map(function($val) {
                                                        return (int)trim($val);
                                                    }, $jam_list);
                                                    
                                                    // Filter out 0 or invalid numbers if any
                                                    $jam_list = array_filter($jam_list);
                                                    
                                                    if (!empty($jam_list)) {
                                                        $jam_start = min($jam_list);
                                                        $jam_end = max($jam_list);
                                                        
                                                        $start_time = isset($jam_map[$jam_start]) ? $jam_map[$jam_start]['mulai'] : '';
                                                        $end_time = isset($jam_map[$jam_end]) ? $jam_map[$jam_end]['selesai'] : '';
                                                        
                                                        if ($start_time && $end_time) {
                                                            $waktu_str = $start_time . ' - ' . $end_time;
                                                        } elseif ($start_time) {
                                                            $waktu_str = $start_time;
                                                        }
                                                    }
                                                }
                                                echo $waktu_str;
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($journal['mapel']); ?></td>
                                            <td><?php echo htmlspecialchars($journal['materi']); ?></td>
                                            <td><?php echo htmlspecialchars($journal['nama_guru'] ?? '-'); ?></td>
                                            <td>
                                                <button onclick="confirmDelete(<?php echo $journal['id']; ?>)" class="btn btn-danger btn-sm">
                                                    <i class="fas fa-trash"></i> Hapus
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <!-- Table will be empty, DataTables handles "No data" message -->
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
