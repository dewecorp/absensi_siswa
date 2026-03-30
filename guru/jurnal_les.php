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

// Handle Form Submission (Add/Edit)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_journal'])) {
    $id_jurnal = !empty($_POST['id_jurnal']) ? (int)$_POST['id_jurnal'] : null;
    $id_kelas = (int)$_POST['id_kelas'];
    $waktu = $_POST['waktu'];
    $mapel = $_POST['mapel'];
    $materi = $_POST['materi'];
    $tanggal = $_POST['tanggal'];
    $id_guru = $teacher['id_guru'];
    
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

// Get selected class - use auto-selected value
$selected_class = isset($_GET['kelas']) ? $_GET['kelas'] : '';
if (empty($selected_class) && count($classes) > 0) {
    $selected_class = $classes[0]['id_kelas'];
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
    AND (m.jenis_mapel IS NULL OR m.jenis_mapel = 'Akademik')
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
    $msg_alert = "Swal.fire({icon: '$msg_icon', title: '$msg_title', text: '$msg_text', timer: 2000, showConfirmButton: false});";
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

    // Reset modal on close
    $('#jurnalModal').on('hidden.bs.modal', function () {
        $(this).find('form')[0].reset();
        $('#jurnalModalLabel').text('Tambah Jurnal Les');
        $('#id_jurnal').val('');
    });

    // Edit button click
    $(document).on('click', '.btn-edit', function() {
        var id = $(this).data('id');
        var tanggal = $(this).data('tanggal');
        var waktu = $(this).data('waktu');
        var mapel = $(this).data('mapel');
        var materi = $(this).data('materi');

        $('#jurnalModalLabel').text('Edit Jurnal Les');
        $('#id_jurnal').val(id);
        $('#tanggal').val(tanggal);
        $('#waktu').val(waktu);
        $('#mapel').val(mapel);
        $('#materi').val(materi);

        $('#jurnalModal').modal('show');
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
            <?php if (empty($classes)): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> 
                    <strong>Belum ada Kelas 6:</strong> Anda belum mengajar atau menjadi wali kelas 6. Jurnal Les hanya tersedia untuk guru kelas 6.
                </div>
            <?php elseif (!$class_info): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-circle"></i> 
                    <strong>Kelas tidak ditemukan:</strong> Silakan pilih kelas terlebih dahulu.
                </div>
            <?php else: ?>
            <div class="card">
                <div class="card-header">
                    <h4>Data Jurnal Les - <?php echo htmlspecialchars($class_info['nama_kelas']); ?></h4>
                    <div class="card-header-action">
                        <div class="btn-group mr-2">
                            <a href="../config/export_jurnal_les_pdf.php?session_type=guru&kelas=<?php echo $selected_class; ?>&guru=<?php echo $teacher['id_guru']; ?>" target="_blank" class="btn btn-danger">
                                <i class="fas fa-file-pdf"></i> PDF
                            </a>
                            <a href="../config/export_jurnal_les_excel.php?session_type=guru&kelas=<?php echo $selected_class; ?>&guru=<?php echo $teacher['id_guru']; ?>" target="_blank" class="btn btn-success">
                                <i class="fas fa-file-excel"></i> Excel
                            </a>
                        </div>
                        <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#jurnalModal">
                            <i class="fas fa-plus"></i> Tambah Jurnal Les
                        </button>
                    </div>
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
                                            <input type="hidden" name="delete_journal" value="1">
                                            <button type="submit" class="btn btn-sm btn-danger btn-delete" title="Hapus">
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

<div class="modal fade" id="jurnalModal" tabindex="-1" role="dialog" aria-labelledby="jurnalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="jurnalModalLabel">Tambah Jurnal Les</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST" id="jurnalForm">
                    <input type="hidden" name="id_jurnal" id="id_jurnal">
                    <input type="hidden" name="id_kelas" value="<?php echo $selected_class; ?>">
                    
                    <div class="row">
                        <div class="form-group col-md-6">
                            <label>Tanggal</label>
                            <input type="date" name="tanggal" id="tanggal" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="form-group col-md-6">
                            <label>Waktu Les</label>
                            <select name="waktu" id="waktu" class="form-control" required>
                                <?php foreach ($waktu_options as $waktu): ?>
                                    <option value="<?php echo htmlspecialchars($waktu); ?>"><?php echo htmlspecialchars($waktu); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Mata Pelajaran</label>
                        <select name="mapel" id="mapel" class="form-control" required>
                            <option value="">-- Pilih Mata Pelajaran --</option>
                            <?php foreach ($mapel_options as $mapel_item): ?>
                                <option value="<?php echo htmlspecialchars($mapel_item); ?>"><?php echo htmlspecialchars($mapel_item); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Materi</label>
                        <textarea name="materi" id="materi" class="form-control" placeholder="Materi Pembelajaran" required></textarea>
                    </div>
                    
                    <button type="submit" name="save_journal" class="btn btn-primary"><i class="fas fa-save"></i> Simpan</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
