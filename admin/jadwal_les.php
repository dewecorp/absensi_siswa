<?php
// Determine session name before including functions.php
if (isset($_GET['session_type'])) {
    $type = $_GET['session_type'];
    $session_name = 'SIS_LOGIN';
    if ($type == 'admin') $session_name = 'SIS_ADMIN';
    elseif ($type == 'tata_usaha') $session_name = 'SIS_TU';
    elseif ($type == 'kepala_madrasah' || $type == 'kepala') $session_name = 'SIS_KEPALA';
    elseif ($type == 'guru') $session_name = 'SIS_GURU';
    elseif ($type == 'wali') $session_name = 'SIS_WALI';
    elseif ($type == 'siswa') $session_name = 'SIS_SISWA';
    
    if (session_status() == PHP_SESSION_NONE) {
        $save_path = sys_get_temp_dir();
        if (is_string($save_path) && $save_path !== '') {
            session_save_path($save_path);
        }
        session_name($session_name);
        session_start();
    }
}

require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has allowed level
if (!isAuthorized(['admin', 'kepala_madrasah', 'guru', 'wali', 'siswa', 'tata_usaha'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$is_admin = ($user_level === 'admin');

// Set page title
$page_title = 'Jadwal Les';

// Handle Form Submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $is_admin) {
    if (isset($_POST['action'])) {
        try {
            if ($_POST['action'] == 'add') {
                $hari = sanitizeInput($_POST['hari']);
                $tanggal = sanitizeInput($_POST['tanggal']);
                $id_guru = (int)$_POST['id_guru'];
                $waktu_mulai = date("H:i", strtotime($_POST['waktu_mulai']));
                $waktu_selesai = date("H:i", strtotime($_POST['waktu_selesai']));
                
                $stmt = $pdo->prepare("INSERT INTO tb_jadwal_les (hari, tanggal, id_guru, waktu_mulai, waktu_selesai) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$hari, $tanggal, $id_guru, $waktu_mulai, $waktu_selesai]);
                
                $username = $_SESSION['username'] ?? 'admin';
                logActivity($pdo, $username, 'Tambah Jadwal Les', "Menambahkan jadwal les pada tanggal $tanggal untuk guru ID $id_guru");
                
                $message = ['type' => 'success', 'text' => 'Jadwal les berhasil ditambahkan!'];
            } elseif ($_POST['action'] == 'edit') {
                $id_les = (int)$_POST['id_les'];
                $hari = sanitizeInput($_POST['hari']);
                $tanggal = sanitizeInput($_POST['tanggal']);
                $id_guru = (int)$_POST['id_guru'];
                $waktu_mulai = date("H:i", strtotime($_POST['waktu_mulai']));
                $waktu_selesai = date("H:i", strtotime($_POST['waktu_selesai']));
                
                $stmt = $pdo->prepare("UPDATE tb_jadwal_les SET hari = ?, tanggal = ?, id_guru = ?, waktu_mulai = ?, waktu_selesai = ? WHERE id_les = ?");
                $stmt->execute([$hari, $tanggal, $id_guru, $waktu_mulai, $waktu_selesai, $id_les]);
                
                $username = $_SESSION['username'] ?? 'admin';
                logActivity($pdo, $username, 'Update Jadwal Les', "Memperbarui jadwal les ID $id_les");
                
                $message = ['type' => 'success', 'text' => 'Jadwal les berhasil diperbarui!'];
            } elseif ($_POST['action'] == 'delete') {
                $id_les = (int)$_POST['id_les'];
                
                $stmt = $pdo->prepare("DELETE FROM tb_jadwal_les WHERE id_les = ?");
                $stmt->execute([$id_les]);
                
                $username = $_SESSION['username'] ?? 'admin';
                logActivity($pdo, $username, 'Hapus Jadwal Les', "Menghapus jadwal les ID $id_les");
                
                $message = ['type' => 'success', 'text' => 'Jadwal les berhasil dihapus!'];
            }
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Terjadi kesalahan: ' . $e->getMessage()];
        }
    }
}

// Get Grade 6 Teachers (id_kelas = 6 based on previous check)
$stmt = $pdo->prepare("
    SELECT DISTINCT g.id_guru, g.nama_guru 
    FROM tb_guru g
    JOIN tb_jadwal_pelajaran j ON g.id_guru = j.guru_id
    WHERE j.kelas_id = 6
    UNION
    SELECT id_guru, nama_guru
    FROM tb_guru
    WHERE nama_guru IN (SELECT wali_kelas FROM tb_kelas WHERE id_kelas = 6)
    ORDER BY nama_guru ASC
");
$stmt->execute();
$grade6_teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Jadwal Les Data
$stmt = $pdo->query("
    SELECT j.*, g.nama_guru 
    FROM tb_jadwal_les j 
    JOIN tb_guru g ON j.id_guru = g.id_guru 
    ORDER BY j.tanggal ASC, j.waktu_mulai ASC
");
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Indonesian Days
$days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Ahad'];

// Define CSS and JS libraries
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-timepicker/0.5.2/css/bootstrap-timepicker.min.css',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css'
];

$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-timepicker/0.5.2/js/bootstrap-timepicker.min.js',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js'
];

$js_page = ["
$(document).ready(function() {
    $('#table-jadwal-les').DataTable({
        'language': {
            'url': 'https://cdn.datatables.net/plug-ins/1.10.25/i18n/Indonesian.json'
        }
    });

    // Guard: Select2 may fail to load on some hosts, so degrade gracefully
    if ($.fn && $.fn.select2) {
        $('.select2').select2({
            width: '100%',
            dropdownParent: $('#addModal')
        });
        $('.select2-edit').select2({
            width: '100%',
            dropdownParent: $('#editModal')
        });
    } else {
        console.warn('Select2 not loaded; falling back to native selects');
        $('.select2, .select2-edit').css('width', '100%');
    }

    // Guard: Bootstrap timepicker may fail to load; proceed without blocking
    if ($.fn && $.fn.timepicker) {
        $('.timepicker').timepicker({
            showMeridian: false,
            minuteStep: 5,
            defaultTime: '07:00',
            showInputs: true,
            icons: {
                up: 'fas fa-chevron-up',
                down: 'fas fa-chevron-down'
            }
        });
    } else {
        console.warn('Timepicker not loaded; using plain text inputs');
        $('.timepicker').attr('type', 'time');
    }

    // Auto-fill day name from date
    $('input[name=\"tanggal\"]').on('change', function() {
        var date = new Date($(this).val());
        var days = ['Ahad', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        var dayName = days[date.getDay()];
        $(this).closest('form').find('select[name=\"hari\"]').val(dayName);
    });

    // SweetAlert for PHP Messages
    " . ($message ? "
        Swal.fire({
            icon: '" . ($message['type'] === 'danger' ? 'error' : 'success') . "',
            title: '" . ($message['type'] === 'danger' ? 'Gagal' : 'Berhasil') . "',
            text: '" . $message['text'] . "',
            " . ($message['type'] === 'success' ? "
            timer: 2000,
            showConfirmButton: false,
            timerProgressBar: true
            " : "") . "
        });
    " : "") . "

    // Edit functionality (delegate to handle DataTables redraw)
    $(document).on('click', '.edit-btn', function() {
        var data = $(this).data();
        $('#edit_id_les').val(data.id);
        $('#edit_hari').val(data.hari);
        $('#edit_tanggal').val(data.tanggal);
        $('#edit_id_guru').val(data.guru).trigger('change');
        $('#edit_waktu_mulai').val(data.mulai);
        $('#edit_waktu_selesai').val(data.selesai);
        $('#editModal').modal('show');
    });

    // Delete confirmation (delegate to handle DataTables redraw)
    $(document).on('click', '.delete-btn', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        Swal.fire({
            title: 'Apakah Anda yakin?',
            text: 'Data jadwal les akan dihapus permanen!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                var form = $('<form method=\"POST\">' +
                    '<input type=\"hidden\" name=\"action\" value=\"delete\">' +
                    '<input type=\"hidden\" name=\"id_les\" value=\"' + id + '\">' +
                    '</form>');
                $('body').append(form);
                form.submit();
            }
        });
    });
});
"];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Jadwal Les Kelas 6</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Master Data</div>
                <div class="breadcrumb-item">Jadwal Les</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Data Jadwal Les</h4>
                            <div class="card-header-action">
                                <div class="btn-group mr-2">
                                    <a href="../config/export_jadwal_les_pdf?session_type=<?= $user_level ?>" target="_blank" class="btn btn-danger">
                                        <i class="fas fa-file-pdf"></i> Export PDF
                                    </a>
                                    <a href="../config/export_jadwal_les_excel?session_type=<?= $user_level ?>" target="_blank" class="btn btn-success">
                                        <i class="fas fa-file-excel"></i> Export Excel
                                    </a>
                                </div>
                                <?php if ($is_admin): ?>
                                <button class="btn btn-primary" data-toggle="modal" data-target="#addModal">
                                    <i class="fas fa-plus"></i> Tambah Jadwal
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped" id="table-jadwal-les">
                                    <thead>
                                        <tr>
                                            <th>No</th>
                                            <th>Hari</th>
                                            <th>Tanggal</th>
                                            <th>Nama Guru</th>
                                            <th>Waktu</th>
                                            <?php if ($is_admin): ?>
                                            <th>Aksi</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($schedules as $i => $s): ?>
                                            <tr>
                                                <td><?php echo $i + 1; ?></td>
                                                <td><?php echo htmlspecialchars($s['hari']); ?></td>
                                                <td><?php echo date('d-m-Y', strtotime($s['tanggal'])); ?></td>
                                                <td><?php echo htmlspecialchars($s['nama_guru']); ?></td>
                                                <td><?php echo date('H.i', strtotime($s['waktu_mulai'])) . ' - ' . date('H.i', strtotime($s['waktu_selesai'])); ?></td>
                                                <?php if ($is_admin): ?>
                                                <td>
                                                    <button class="btn btn-warning btn-sm edit-btn" 
                                                            data-id="<?php echo $s['id_les']; ?>"
                                                            data-hari="<?php echo $s['hari']; ?>"
                                                            data-tanggal="<?php echo $s['tanggal']; ?>"
                                                            data-guru="<?php echo $s['id_guru']; ?>"
                                                            data-mulai="<?php echo date('H:i', strtotime($s['waktu_mulai'])); ?>"
                                                            data-selesai="<?php echo date('H:i', strtotime($s['waktu_selesai'])); ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-danger btn-sm delete-btn" data-id="<?php echo $s['id_les']; ?>">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </td>
                                                <?php endif; ?>
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
<?php if ($is_admin): ?>
<div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-labelledby="addModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addModalLabel">Tambah Jadwal Les</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Tanggal</label>
                        <input type="date" name="tanggal" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Hari</label>
                        <select name="hari" class="form-control" required>
                            <option value="">-- Pilih Hari --</option>
                            <?php foreach ($days as $day): ?>
                                <option value="<?php echo $day; ?>"><?php echo $day; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nama Guru</label>
                        <select name="id_guru" class="form-control select2" required>
                            <option value="">-- Pilih Guru Kelas 6 --</option>
                            <?php foreach ($grade6_teachers as $teacher): ?>
                                <option value="<?php echo $teacher['id_guru']; ?>"><?php echo htmlspecialchars($teacher['nama_guru']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Dari Jam</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <div class="input-group-text">
                                            <i class="fas fa-clock"></i>
                                        </div>
                                    </div>
                                    <input type="text" name="waktu_mulai" class="form-control timepicker" required>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Sampai Jam</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <div class="input-group-text">
                                            <i class="fas fa-clock"></i>
                                        </div>
                                    </div>
                                    <input type="text" name="waktu_selesai" class="form-control timepicker" required>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id_les" id="edit_id_les">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editModalLabel">Edit Jadwal Les</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Tanggal</label>
                        <input type="date" name="tanggal" id="edit_tanggal" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Hari</label>
                        <select name="hari" id="edit_hari" class="form-control" required>
                            <option value="">-- Pilih Hari --</option>
                            <?php foreach ($days as $day): ?>
                                <option value="<?php echo $day; ?>"><?php echo $day; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nama Guru</label>
                        <select name="id_guru" id="edit_id_guru" class="form-control select2-edit" required>
                            <option value="">-- Pilih Guru Kelas 6 --</option>
                            <?php foreach ($grade6_teachers as $teacher): ?>
                                <option value="<?php echo $teacher['id_guru']; ?>"><?php echo htmlspecialchars($teacher['nama_guru']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Dari Jam</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <div class="input-group-text">
                                            <i class="fas fa-clock"></i>
                                        </div>
                                    </div>
                                    <input type="text" name="waktu_mulai" id="edit_waktu_mulai" class="form-control timepicker" required>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Sampai Jam</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <div class="input-group-text">
                                            <i class="fas fa-clock"></i>
                                        </div>
                                    </div>
                                    <input type="text" name="waktu_selesai" id="edit_waktu_selesai" class="form-control timepicker" required>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include '../templates/footer.php'; ?>
