<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has wali level
if (!isAuthorized(['wali'])) {
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

// Also add the homeroom class if not already in the list
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

// Auto-select class if only one class available
if (count($classes) == 1 && (!isset($_GET['kelas']) || empty($_GET['kelas']))) {
    $_GET['kelas'] = $classes[0]['id_kelas'];
}


// Handle Form Submission (Add/Edit)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_journal'])) {
    $id_kelas = (int)$_POST['id_kelas'];
    
    // Handle jam_ke array
    $jam_ke_input = isset($_POST['jam_ke']) ? $_POST['jam_ke'] : [];
    if (is_array($jam_ke_input)) {
        $jam_ke = implode(',', $jam_ke_input);
    } else {
        $jam_ke = $jam_ke_input;
    }

    $mapel = $_POST['mapel'];
    $materi = $_POST['materi'];
    $tanggal = $_POST['tanggal'];
    $jenis = $_POST['jenis'] ?? 'Reguler'; // Capture jenis input
    $id_guru = $teacher['id_guru'];
    
    if (isset($_POST['id_jurnal']) && !empty($_POST['id_jurnal'])) {
        // Edit
        $id_jurnal = (int)$_POST['id_jurnal'];
        // Check ownership
        $check_stmt = $pdo->prepare("SELECT id FROM tb_jurnal WHERE id = ? AND id_guru = ?");
        $check_stmt->execute([$id_jurnal, $id_guru]);
        if ($check_stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("UPDATE tb_jurnal SET id_kelas=?, jam_ke=?, mapel=?, materi=?, tanggal=?, jenis=? WHERE id=?");
            $stmt->execute([$id_kelas, $jam_ke, $mapel, $materi, $tanggal, $jenis, $id_jurnal]);

            $nama_guru_notif = $teacher['nama_guru'];
            $nama_kelas_notif = '';
            foreach ($classes as $c) {
                if ($c['id_kelas'] == $id_kelas) {
                    $nama_kelas_notif = $c['nama_kelas'];
                    break;
                }
            }

            $notif_msg = "$nama_guru_notif telah memperbarui jurnal mengajar kelas $nama_kelas_notif";
            createNotification($pdo, $notif_msg, 'jurnal_mengajar.php', 'jurnal');

            // Log activity
            $log_desc = "$nama_guru_notif memperbarui jurnal mengajar kelas $nama_kelas_notif ($mapel)";
            logActivity($pdo, $teacher['nama_guru'], 'Edit Jurnal', $log_desc);

            $message = ['type' => 'success', 'text' => 'Jurnal berhasil diperbarui!'];
        } else {
            $message = ['type' => 'error', 'text' => 'Anda tidak memiliki akses untuk mengedit jurnal ini.'];
        }
    } else {
        // Add
        $stmt = $pdo->prepare("INSERT INTO tb_jurnal (id_kelas, id_guru, jam_ke, mapel, materi, tanggal, jenis) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id_kelas, $id_guru, $jam_ke, $mapel, $materi, $tanggal, $jenis]);
        
        // Send notification to admin
        $nama_guru_notif = $teacher['nama_guru'];
        $nama_kelas_notif = '';
        foreach ($classes as $c) {
            if ($c['id_kelas'] == $id_kelas) {
                $nama_kelas_notif = $c['nama_kelas'];
                break;
            }
        }
        $notif_msg = "$nama_guru_notif telah mengisi jurnal mengajar kelas $nama_kelas_notif";
        createNotification($pdo, $notif_msg, 'jurnal_mengajar.php', 'jurnal');

        $message = ['type' => 'success', 'text' => 'Jurnal berhasil ditambahkan!'];
    }
}

// Handle Delete
if (isset($_POST['delete_journal'])) {
    $id_jurnal = (int)$_POST['id_jurnal'];
    $id_guru = $teacher['id_guru'];
    
    $check_stmt = $pdo->prepare("SELECT id FROM tb_jurnal WHERE id = ? AND id_guru = ?");
    $check_stmt->execute([$id_jurnal, $id_guru]);
    
    if ($check_stmt->rowCount() > 0) {
        $stmt = $pdo->prepare("DELETE FROM tb_jurnal WHERE id = ?");
        $stmt->execute([$id_jurnal]);
        $message = ['type' => 'success', 'text' => 'Jurnal berhasil dihapus!'];
    } else {
        $message = ['type' => 'error', 'text' => 'Anda tidak memiliki akses untuk menghapus jurnal ini.'];
    }
}

// Handle Multiple Delete Action
if (isset($_POST['delete_multiple_journal'])) {
    $ids = $_POST['ids'] ?? [];
    $id_guru = $teacher['id_guru'];
    
    if (!empty($ids)) {
        try {
            // Filter IDs that belong to this teacher
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            $check_sql = "SELECT id FROM tb_jurnal WHERE id IN ($placeholders) AND id_guru = ?";
            $check_params = array_merge($ids, [$id_guru]);
            
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute($check_params);
            $valid_ids = $check_stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($valid_ids)) {
                $delete_placeholders = str_repeat('?,', count($valid_ids) - 1) . '?';
                $delete_stmt = $pdo->prepare("DELETE FROM tb_jurnal WHERE id IN ($delete_placeholders)");
                $delete_stmt->execute($valid_ids);
                
                $count = count($valid_ids);
                logActivity($pdo, $teacher['nama_guru'], 'Hapus Jurnal Massal', "Wali menghapus $count jurnal");
                
                $message = ['type' => 'success', 'text' => "$count data jurnal berhasil dihapus!"];
            } else {
                $message = ['type' => 'error', 'text' => 'Tidak ada jurnal yang dapat dihapus (mungkin bukan milik Anda).'];
            }
        } catch (Exception $e) {
            $message = ['type' => 'error', 'text' => 'Gagal menghapus data: ' . $e->getMessage()];
        }
    }
}

// Get entries
$journal_entries = [];
$class_info = [];
if (isset($_GET['kelas']) && !empty($_GET['kelas'])) {
    $id_kelas = (int)$_GET['kelas'];
    
    // Verify access to class
    $has_access = false;
    foreach ($classes as $c) {
        if ($c['id_kelas'] == $id_kelas) {
            $has_access = true;
            $class_info = $c;
            break;
        }
    }
    
    if ($has_access) {
        $stmt = $pdo->prepare("SELECT * FROM tb_jurnal WHERE id_kelas = ? ORDER BY tanggal DESC, jam_ke DESC");
        $stmt->execute([$id_kelas]);
        $journal_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Fetch Reference Data
$jam_mengajar_stmt = $pdo->query("SELECT * FROM tb_jam_mengajar ORDER BY jam_ke ASC");
$jam_mengajar_list = $jam_mengajar_stmt->fetchAll(PDO::FETCH_ASSOC);

// Create mapping for time calculation
$jam_map = [];
foreach ($jam_mengajar_list as $jam) {
    $jam_map[$jam['jam_ke']] = [
        'mulai' => date('H:i', strtotime($jam['waktu_mulai'])),
        'selesai' => date('H:i', strtotime($jam['waktu_selesai']))
    ];
}

$mapel_stmt = $pdo->prepare("
    SELECT DISTINCT m.nama_mapel 
    FROM tb_mata_pelajaran m
    JOIN tb_jadwal_pelajaran j ON m.id_mapel = j.mapel_id
    WHERE j.guru_id = ?
    AND m.nama_mapel NOT LIKE '%Asmaul Husna%' 
    AND m.nama_mapel NOT LIKE '%Upacara%' 
    AND m.nama_mapel NOT LIKE '%Istirahat%' 
    AND m.nama_mapel NOT LIKE '%Kepramukaan%' 
    AND m.nama_mapel NOT LIKE '%Ekstrakurikuler%'
    ORDER BY m.nama_mapel ASC
");
$mapel_stmt->execute([$teacher['id_guru']]);
$mapel_list = $mapel_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get schedule map (class_id => [mapels])
$schedule_stmt = $pdo->prepare("
    SELECT DISTINCT m.nama_mapel, j.kelas_id 
    FROM tb_mata_pelajaran m
    JOIN tb_jadwal_pelajaran j ON m.id_mapel = j.mapel_id
    WHERE j.guru_id = ?
    AND m.nama_mapel NOT LIKE '%Asmaul Husna%' 
    AND m.nama_mapel NOT LIKE '%Upacara%' 
    AND m.nama_mapel NOT LIKE '%Istirahat%' 
    AND m.nama_mapel NOT LIKE '%Kepramukaan%' 
    AND m.nama_mapel NOT LIKE '%Ekstrakurikuler%'
    ORDER BY m.nama_mapel ASC
");
$schedule_stmt->execute([$teacher['id_guru']]);
$schedule_rows = $schedule_stmt->fetchAll(PDO::FETCH_ASSOC);

$schedule_map = [];
foreach ($schedule_rows as $row) {
    $schedule_map[$row['kelas_id']][] = $row['nama_mapel'];
}

if (empty($mapel_list)) {
    $mapel_stmt = $pdo->query("SELECT DISTINCT nama_mapel FROM tb_mata_pelajaran WHERE nama_mapel NOT LIKE '%Asmaul Husna%' AND nama_mapel NOT LIKE '%Upacara%' AND nama_mapel NOT LIKE '%Istirahat%' AND nama_mapel NOT LIKE '%Kepramukaan%' AND nama_mapel NOT LIKE '%Ekstrakurikuler%' ORDER BY nama_mapel ASC");
    $mapel_list = $mapel_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = 'Jurnal Mengajar';

// Libraries
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css'
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/sweetalert2@11',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js'
];

$js_page = [
    "
    var jamMengajarList = " . json_encode($jam_mengajar_list) . ";

    function updateJamOptions(selectedJenis, selectedValues = []) {
        var \$jamSelect = $('select[name=\"jam_ke[]\"]');
        \$jamSelect.empty();
        
        jamMengajarList.forEach(function(jam) {
            if (jam.jenis === selectedJenis) {
                if (['A', 'B', 'C'].includes(jam.jam_ke)) return;
                
                var startTime = jam.waktu_mulai.substring(0, 5);
                var endTime = jam.waktu_selesai.substring(0, 5);
                var label = jam.jam_ke + ' (' + startTime + ' - ' + endTime + ')';
                
                var option = new Option(label, jam.jam_ke, false, false);
                \$jamSelect.append(option);
            }
        });
        
        if (selectedValues.length > 0) {
            \$jamSelect.val(selectedValues).trigger('change');
        } else {
            \$jamSelect.trigger('change');
        }
    }

    $(document).ready(function() {
        var t = $('#table-jurnal').DataTable({
            'language': { 'url': '//cdn.datatables.net/plug-ins/1.10.25/i18n/Indonesian.json' },
            'ordering': false,
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

        $('.select2').select2({
            width: '100%'
        });
        
        $('#jenis_jadwal').change(function() {
            updateJamOptions($(this).val());
        });

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
                    form.action = '';

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

    function openModal(type, data = null) {
        if (type === 'add') {
            $('#modalTitle').text('Tambah Jurnal');
            $('#formJurnal')[0].reset();
            $('#id_jurnal').val('');
            
            // Default jenis and dynamic update
            $('#jenis_jadwal').val('Reguler');
            updateJamOptions('Reguler');
            
            $('select[name=\"mapel\"]').val('').trigger('change');
            
            // Set default date to today
            var today = new Date().toLocaleString('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit' });
            $('input[name=\"tanggal\"]').val(today);
            
            // Set class if selected
            var urlParams = new URLSearchParams(window.location.search);
            if(urlParams.has('kelas')) {
                $('select[name=\"id_kelas\"]').val(urlParams.get('kelas'));
            }
        } else {
            $('#modalTitle').text('Edit Jurnal');
            $('#id_jurnal').val(data.id);
            $('select[name=\"id_kelas\"]').val(data.id_kelas);
            $('input[name=\"tanggal\"]').val(data.tanggal);
            
            // Set jenis and update options
            var jenis = data.jenis || 'Reguler';
            $('#jenis_jadwal').val(jenis);
            
            // Handle multiselect jam_ke
            var jamKeValues = data.jam_ke.toString().split(',');
            jamKeValues = jamKeValues.map(function(item) { return item.trim(); });
            
            updateJamOptions(jenis, jamKeValues);
            
            $('select[name=\"mapel\"]').val(data.mapel).trigger('change');
            $('textarea[name=\"materi\"]').val(data.materi);
        }
        $('#jurnalModal').modal('show');
    }

    function confirmDelete(id) {
        Swal.fire({
            title: 'Hapus Jurnal?',
            text: 'Data tidak bisa dikembalikan!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus!'
        }).then((result) => {
            if (result.isConfirmed) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
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

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Jurnal Mengajar</h1>
        </div>

        <?php if (isset($message)): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    title: '<?php echo $message['type'] === 'success' ? 'Sukses!' : 'Info!'; ?>',
                    text: '<?php echo addslashes($message['text']); ?>',
                    icon: '<?php echo $message['type']; ?>',
                    timer: 3000,
                    showConfirmButton: false
                });
            });
        </script>
        <?php endif; ?>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <?php if (count($classes) > 1): ?>
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
                        <?php else: ?>
                            <?php if (!empty($classes)): ?>
                            <div class="alert alert-info mb-0">
                                Menampilkan jurnal untuk kelas <strong><?php echo htmlspecialchars($classes[0]['nama_kelas']); ?></strong>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isset($_GET['kelas']) && !empty($_GET['kelas'])): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4>Data Jurnal - <?php echo isset($class_info['nama_kelas']) ? htmlspecialchars($class_info['nama_kelas']) : ''; ?></h4>
                        <div class="card-header-action">
                            <div class="btn-group mr-2">
                                <a href="../config/export_jurnal_pdf.php?session_type=wali&kelas=<?= $_GET['kelas'] ?? '' ?>" target="_blank" class="btn btn-danger">
                                    <i class="fas fa-file-pdf"></i> Export PDF
                                </a>
                                <a href="../config/export_jurnal_excel.php?session_type=wali&kelas=<?= $_GET['kelas'] ?? '' ?>" target="_blank" class="btn btn-success">
                                    <i class="fas fa-file-excel"></i> Export Excel
                                </a>
                            </div>
                            <button id="btn-bulk-delete" class="btn btn-danger mr-2" style="display: none;" onclick="bulkDelete()">
                                <i class="fas fa-trash"></i>
                            </button>
                            <button class="btn btn-primary" onclick="openModal('add')"><i class="fas fa-plus"></i> Tambah</button>
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
                                        <th>No</th>
                                        <th>Tanggal</th>
                                        <th>Jam Ke</th>
                                        <th>Waktu</th>
                                        <th>Mapel</th>
                                        <th>Materi</th>
                                        <th>Dibuat Pada</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $no = 1; foreach ($journal_entries as $journal): ?>
                                    <tr>
                                        <td class="text-center">
                                            <?php if ($journal['id_guru'] == $teacher['id_guru']): ?>
                                            <div class="custom-checkbox custom-control">
                                                <input type="checkbox" class="custom-control-input check-item" id="checkbox-<?php echo $journal['id']; ?>" value="<?php echo $journal['id']; ?>">
                                                <label class="custom-control-label" for="checkbox-<?php echo $journal['id']; ?>"></label>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $no++; ?></td>
                                        <td data-order="<?php echo $journal['tanggal']; ?>"><?php echo date('d-m-Y', strtotime($journal['tanggal'])); ?></td>
                                        <td><?php echo htmlspecialchars($journal['jam_ke']); ?></td>
                                        <td>
                                            <?php 
                                            $jam_ke_arr = explode(',', $journal['jam_ke']);
                                            $jam_ke_arr = array_map('trim', $jam_ke_arr);
                                            sort($jam_ke_arr, SORT_NUMERIC);
                                            $first_jam = $jam_ke_arr[0];
                                            $last_jam = end($jam_ke_arr);
                                            
                                            $waktu_str = '';
                                            if (isset($jam_map[$first_jam]) && isset($jam_map[$last_jam])) {
                                                $waktu_str = $jam_map[$first_jam]['mulai'] . ' - ' . $jam_map[$last_jam]['selesai'];
                                            }
                                            echo htmlspecialchars($waktu_str);
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($journal['mapel']); ?></td>
                                        <td><?php echo htmlspecialchars($journal['materi']); ?></td>
                                        <td><?php echo date('d-m-Y H:i', strtotime($journal['created_at'])); ?></td>
                                        <td>
                                            <?php if ($journal['id_guru'] == $teacher['id_guru']): ?>
                                            <button class="btn btn-warning btn-sm" onclick='openModal("edit", <?php echo json_encode($journal); ?>)'><i class="fas fa-edit"></i></button>
                                            <button class="btn btn-danger btn-sm" onclick="confirmDelete(<?php echo $journal['id']; ?>)"><i class="fas fa-trash"></i></button>
                                            <?php endif; ?>
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
        <?php endif; ?>
    </section>
</div>

<!-- Modal -->
<div class="modal fade" id="jurnalModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Tambah Jurnal</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="formJurnal" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="save_journal" value="1">
                    <input type="hidden" name="id_jurnal" id="id_jurnal">
                    
                    <div class="form-group">
                        <label>Kelas</label>
                        <select name="id_kelas" class="form-control" required>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id_kelas']; ?>"><?php echo htmlspecialchars($class['nama_kelas']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Tanggal</label>
                        <input type="date" name="tanggal" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label>Jenis Jadwal</label>
                        <select name="jenis" id="jenis_jadwal" class="form-control" required>
                            <option value="Reguler">Reguler</option>
                            <option value="Ramadhan">Ramadhan</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Jam Ke</label>
                        <select name="jam_ke[]" class="form-control select2" multiple required>
                            <?php foreach ($jam_mengajar_list as $jam): ?>
                                <?php if (in_array($jam['jam_ke'], ['A', 'B', 'C'])) continue; ?>
                                <option value="<?php echo $jam['jam_ke']; ?>">
                                    <?php echo $jam['jam_ke'] . ' (' . date('H:i', strtotime($jam['waktu_mulai'])) . ' - ' . date('H:i', strtotime($jam['waktu_selesai'])) . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Mata Pelajaran</label>
                        <select name="mapel" class="form-control select2" required>
                            <option value="">-- Pilih Mata Pelajaran --</option>
                            <?php foreach ($mapel_list as $mpl): ?>
                                <option value="<?php echo htmlspecialchars($mpl['nama_mapel']); ?>">
                                    <?php echo htmlspecialchars($mpl['nama_mapel']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Materi Pokok</label>
                        <textarea name="materi" class="form-control" style="height: 100px" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
