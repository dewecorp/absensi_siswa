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

// Handle Multiple Delete Action
if (isset($_POST['delete_multiple_journal'])) {
    $ids = $_POST['ids'] ?? [];
    if (!empty($ids)) {
        try {
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            $stmt = $pdo->prepare("DELETE FROM tb_jurnal WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            
            $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
            $count = count($ids);
            logActivity($pdo, $username, 'Hapus Jurnal Massal', "Admin menghapus $count jurnal");
            
            $message = ['type' => 'success', 'text' => "$count data jurnal berhasil dihapus!"];
        } catch (Exception $e) {
            $message = ['type' => 'error', 'text' => 'Gagal menghapus data: ' . $e->getMessage()];
        }
    }
}

// Get all classes
$stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all teachers
$stmt_guru = $pdo->query("SELECT * FROM tb_guru ORDER BY nama_guru ASC");
$teachers = $stmt_guru->fetchAll(PDO::FETCH_ASSOC);

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

// Get journal entries
$journal_entries = [];
$class_info = [];
$filter_title = '';

// Get unique jam_ke options
$jam_ke_options = [];
foreach ($jam_mengajar_rows as $row) {
    if (!in_array($row['jam_ke'], ['A', 'B', 'C'])) {
        $jam_ke_options[] = $row['jam_ke'];
    }
}
$jam_ke_options = array_unique($jam_ke_options);
sort($jam_ke_options, SORT_NUMERIC);

$where_clauses = ["j.mapel NOT IN ('Istirahat I', 'Istirahat II', 'Upacara Bendera', 'Asmaul Husna')", "j.jam_ke NOT IN ('A', 'B', 'C')"];
$params = [];

if (isset($_GET['kelas']) && !empty($_GET['kelas'])) {
    $where_clauses[] = "j.id_kelas = ?";
    $params[] = $_GET['kelas'];
    
    $stmt_class = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas = ?");
    $stmt_class->execute([$_GET['kelas']]);
    $class_info = $stmt_class->fetch(PDO::FETCH_ASSOC);
    $filter_title .= ($filter_title ? ' - ' : '') . ($class_info['nama_kelas'] ?? '');
}

if (isset($_GET['guru']) && !empty($_GET['guru'])) {
    $where_clauses[] = "j.id_guru = ?";
    $params[] = $_GET['guru'];
    
    $stmt_g = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
    $stmt_g->execute([$_GET['guru']]);
    $guru_name = $stmt_g->fetchColumn();
    $filter_title .= ($filter_title ? ' - ' : '') . $guru_name;
}

if (isset($_GET['jam_ke']) && !empty($_GET['jam_ke'])) {
    $where_clauses[] = "FIND_IN_SET(?, j.jam_ke)";
    $params[] = $_GET['jam_ke'];
    $filter_title .= ($filter_title ? ' - ' : '') . 'Jam Ke-' . $_GET['jam_ke'];
}

if (!empty($params)) {
    $query = "SELECT j.*, g.nama_guru, k.nama_kelas 
              FROM tb_jurnal j 
              LEFT JOIN tb_guru g ON j.id_guru = g.id_guru 
              LEFT JOIN tb_kelas k ON j.id_kelas = k.id_kelas
              WHERE " . implode(' AND ', $where_clauses) . "
              ORDER BY j.tanggal DESC, j.jam_ke ASC";
              
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
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
        var t = $('#table-jurnal').DataTable({
            'language': {
                'url': '//cdn.datatables.net/plug-ins/1.10.25/i18n/Indonesian.json'
            },
            'order': [[ 8, 'desc' ]],
            'columnDefs': [ {
                'searchable': false,
                'orderable': false,
                'targets': [0, 1]
            } ]
        });

        t.on( 'order.dt search.dt', function () {
            t.column(1, {search:'applied', order:'applied'}).nodes().each( function (cell, i) {
                cell.innerHTML = i+1;
            } );
        } ).draw();

        // Check all functionality
        $('#check-all').click(function() {
            $('.check-item').prop('checked', this.checked);
            toggleBulkDeleteBtn();
        });

        $(document).on('change', '.check-item', function() {
            toggleBulkDeleteBtn();
            if ($('.check-item:checked').length == $('.check-item').length) {
                $('#check-all').prop('checked', true);
            } else {
                $('#check-all').prop('checked', false);
            }
        });
    });

    function toggleBulkDeleteBtn() {
        if ($('.check-item:checked').length > 0) {
            $('#btn-bulk-delete').show();
        } else {
            $('#btn-bulk-delete').hide();
        }
    }

    function bulkDelete() {
        var ids = [];
        $('.check-item:checked').each(function() {
            ids.push($(this).val());
        });

        if (ids.length > 0) {
            Swal.fire({
                title: 'Apakah Anda yakin?',
                text: ids.length + ' data jurnal akan dihapus permanen!',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Ya, hapus!',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    var form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.href;

                    ids.forEach(function(id) {
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'ids[]';
                        input.value = id;
                        form.appendChild(input);
                    });

                    var inputAction = document.createElement('input');
                    inputAction.type = 'hidden';
                    inputAction.name = 'delete_multiple_journal';
                    inputAction.value = '1';
                    form.appendChild(inputAction);

                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
    }

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
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Pilih Kelas</label>
                                        <select class="form-control select2" name="kelas" onchange="this.form.submit()">
                                            <option value="">-- Semua Kelas --</option>
                                            <?php foreach ($classes as $class): ?>
                                                <option value="<?php echo $class['id_kelas']; ?>" <?php echo (isset($_GET['kelas']) && $_GET['kelas'] == $class['id_kelas']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($class['nama_kelas']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Pilih Guru</label>
                                        <select class="form-control select2" name="guru" onchange="this.form.submit()">
                                            <option value="">-- Semua Guru --</option>
                                            <?php foreach ($teachers as $teacher): ?>
                                                <option value="<?php echo $teacher['id_guru']; ?>" <?php echo (isset($_GET['guru']) && $_GET['guru'] == $teacher['id_guru']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($teacher['nama_guru']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Pilih Jam Ke</label>
                                        <select class="form-control select2" name="jam_ke" onchange="this.form.submit()">
                                            <option value="">-- Semua Jam --</option>
                                            <?php foreach ($jam_ke_options as $jam): ?>
                                                <option value="<?php echo $jam; ?>" <?php echo (isset($_GET['jam_ke']) && $_GET['jam_ke'] == $jam) ? 'selected' : ''; ?>>
                                                    <?php echo $jam; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($params)): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4>Data Jurnal Mengajar <?php echo $filter_title ? '- ' . htmlspecialchars($filter_title) : ''; ?></h4>
                        <div class="card-header-action">
                            <button id="btn-bulk-delete" class="btn btn-danger" style="display: none;" onclick="bulkDelete()">
                                <i class="fas fa-trash"></i> Hapus Terpilih
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped" id="table-jurnal">
                                <thead>
                                    <tr>
                                        <th class="text-center" style="width: 40px;">
                                            <div class="custom-checkbox custom-control">
                                                <input type="checkbox" class="custom-control-input" id="check-all">
                                                <label class="custom-control-label" for="check-all"></label>
                                            </div>
                                        </th>
                                        <th class="text-center" style="width: 50px;">No</th>
                                        <th>Tanggal</th>
                                        <th>Jam Ke</th>
                                        <th>Waktu</th>
                                        <th>Mata Pelajaran</th>
                                        <th>Materi Pokok</th>
                                        <th>Guru</th>
                                        <th>Dibuat Pada</th>
                                        <?php if (getUserLevel() === 'admin'): ?>
                                        <th style="width: 100px;">Aksi</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($journal_entries) > 0): ?>
                                        <?php foreach ($journal_entries as $journal): ?>
                                        <tr>
                                            <td class="text-center">
                                                <div class="custom-checkbox custom-control">
                                                    <input type="checkbox" class="custom-control-input check-item" id="checkbox-<?php echo $journal['id']; ?>" value="<?php echo $journal['id']; ?>">
                                                    <label class="custom-control-label" for="checkbox-<?php echo $journal['id']; ?>"></label>
                                                </div>
                                            </td>
                                            <td class="text-center"></td>
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
                                            <td><?php echo date('d-m-Y H:i', strtotime($journal['created_at'])); ?></td>
                                            <?php if (getUserLevel() === 'admin'): ?>
                                            <td>
                                                <button onclick="confirmDelete(<?php echo $journal['id']; ?>)" class="btn btn-danger btn-sm">
                                                    <i class="fas fa-trash"></i> Hapus
                                                </button>
                                            </td>
                                            <?php endif; ?>
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
