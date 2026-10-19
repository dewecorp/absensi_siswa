<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has allowed level
if (!isAuthorized(['admin', 'kepala_madrasah', 'guru', 'wali', 'siswa', 'tata_usaha'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$is_editable = in_array($user_level, ['admin', 'tata_usaha']);

// Set page title
$page_title = 'Jadwal Les';

// Handle Form Submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $is_editable) {
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
    SELECT j.*, g.nama_guru, g.foto 
    FROM tb_jadwal_les j 
    JOIN tb_guru g ON j.id_guru = g.id_guru 
    ORDER BY j.tanggal ASC, j.waktu_mulai ASC
");
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Indonesian Days
$days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Ahad'];

// Define CSS and JS libraries
$css_libs = [
    'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-timepicker/0.5.2/css/bootstrap-timepicker.min.css',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css'
];

$js_libs = [
    'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-timepicker/0.5.2/js/bootstrap-timepicker.min.js',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js'
];

$js_page = ["
$(document).ready(function() {
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

    // Edit functionality
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

    // Delete confirmation
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

<style>
.tl-timeline { position: relative; padding: 10px 0 10px 8px; }
.tl-timeline::before { content: ''; position: absolute; left: 57px; top: 12px; bottom: 12px; width: 3px; border-radius: 3px; background: linear-gradient(180deg, #6777ef, #3abaf4); opacity: .35; }
.tl-month-header { position: relative; z-index: 1; display: inline-block; margin: 16px 0 16px 34px; padding: 5px 18px; border-radius: 20px; background: #6777ef; color: #fff; font-weight: 800; font-size: 12.5px; letter-spacing: .3px; box-shadow: 0 4px 12px rgba(103,119,239,.35); }
.tl-month-header:first-child { margin-top: 0; }
.tl-timeline-item { position: relative; display: flex; gap: 14px; margin-bottom: 16px; }
.tl-timeline-date { flex: 0 0 76px; text-align: center; z-index: 1; }
.tl-timeline-date .d { display: block; width: 48px; height: 48px; margin: 0 auto; border-radius: 50%; background: #fff; border: 3px solid var(--tl-color, #6777ef); color: var(--tl-color, #6777ef); font-weight: 800; font-size: 18px; line-height: 42px; box-shadow: 0 3px 8px rgba(0,0,0,.10); }
.tl-timeline-date .m { display: block; margin-top: 4px; font-size: 11px; font-weight: 800; color: #6c757d; text-transform: uppercase; }
.tl-timeline-card { flex: 1; min-width: 0; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 4px 14px rgba(20,30,70,.06); padding: 14px 16px; border-left: 4.5px solid var(--tl-color, #6777ef); transition: box-shadow .2s ease, transform .2s ease; }
.tl-timeline-card:hover { box-shadow: 0 8px 24px rgba(20,30,70,.12); transform: translateY(-2px); }
.tl-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 800;
    flex-shrink: 0;
    overflow: hidden;
    box-shadow: 0 2px 6px rgba(0,0,0,.15);
}
.tl-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; border-radius: inherit; }
.tl-time-box {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #f0f7ff;
    border: 1px solid #cfe3ff;
    border-radius: 20px;
    padding: 4px 14px;
    font-size: 12.5px;
    font-weight: 800;
    color: #2c5fb3;
}
.tl-act-btn {
    border-radius: 6px;
    width: 32px;
    height: 32px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
@media (max-width: 575.98px) {
    .tl-timeline::before { left: 47px; }
    .tl-timeline-item { gap: 10px; }
    .tl-timeline-date { flex-basis: 60px; }
    .tl-timeline-date .d { width: 38px; height: 38px; font-size: 15px; line-height: 32px; }
}
</style>

        <div class="section-body">
            <!-- Card Utama Timeline Jadwal Les -->
            <div class="card mb-4 border-0 shadow-sm" style="border-radius: 14px;">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center py-3" style="border-radius: 14px 14px 0 0; border-bottom: 1px solid #f1f5f9; gap: 10px;">
                    <div>
                        <h4 class="mb-0 font-weight-bold text-dark"><i class="fas fa-book-reader text-primary mr-2"></i>Timeline Jadwal Les</h4>
                        <small class="text-muted">Menampilkan <span class="font-weight-bold text-primary"><?= count($schedules) ?></span> kegiatan les kelas 6</small>
                    </div>
                    <div class="d-flex align-items-center flex-wrap" style="gap: 8px;">
                        <div class="btn-group">
                            <a href="../config/export_jadwal_les_pdf?session_type=<?= $user_level ?>" target="_blank" class="btn btn-danger btn-sm font-weight-bold">
                                <i class="fas fa-file-pdf mr-1"></i> PDF
                            </a>
                            <a href="../config/export_jadwal_les_excel?session_type=<?= $user_level ?>" target="_blank" class="btn btn-success btn-sm font-weight-bold">
                                <i class="fas fa-file-excel mr-1"></i> Excel
                            </a>
                        </div>
                        <?php if ($is_editable): ?>
                        <button class="btn btn-primary btn-sm font-weight-bold" data-toggle="modal" data-target="#addModal" style="border-radius: 6px;">
                            <i class="fas fa-plus mr-1"></i> Tambah Jadwal
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body bg-light p-3 p-md-4" style="border-radius: 0 0 14px 14px;">
                    <?php if (empty($schedules)): ?>
                        <div class="text-center py-5 bg-white rounded shadow-sm border">
                            <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted font-weight-bold">Belum Ada Jadwal Les</h5>
                            <p class="text-muted small mb-0">Silakan tambahkan jadwal les kelas 6 terlebih dahulu.</p>
                        </div>
                    <?php else: ?>
                        <?php
                        $bulan_id = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                        $les_palette = ['#6777ef', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#0ea5e9', '#14b8a6'];
                        $cur_month = '';
                        $les_i = 0;
                        ?>
                        <div class="tl-timeline">
                            <?php foreach ($schedules as $i => $s): ?>
                                <?php
                                $tgl_raw = $s['tanggal'] ?? date('Y-m-d');
                                $mk = date('Y-m', strtotime($tgl_raw));
                                if ($mk !== $cur_month):
                                    $cur_month = $mk;
                                ?>
                                    <div class="tl-month-header" data-month="<?= $cur_month ?>">
                                        <i class="far fa-calendar-alt mr-1"></i> <?= $bulan_id[(int)date('n', strtotime($tgl_raw))] . ' ' . date('Y', strtotime($tgl_raw)) ?>
                                    </div>
                                <?php endif; ?>

                                <?php
                                $tl_color = $les_palette[$les_i % count($les_palette)];
                                $les_i++;
                                $parts = preg_split('/\s+/', trim((string)$s['nama_guru']));
                                $initials = '';
                                if (!empty($parts[0])) $initials .= mb_strtoupper(mb_substr($parts[0], 0, 1));
                                if (isset($parts[1]) && !empty($parts[1])) $initials .= mb_strtoupper(mb_substr($parts[1], 0, 1));
                                if ($initials === '') $initials = 'G';
                                $jam_mulai = date('H.i', strtotime($s['waktu_mulai']));
                                $jam_selesai = date('H.i', strtotime($s['waktu_selesai']));
                                ?>
                                <div class="tl-timeline-item" data-month="<?= $cur_month ?>" style="--tl-color: <?= $tl_color ?>;">
                                    <div class="tl-timeline-date">
                                        <span class="d"><?= date('d', strtotime($tgl_raw)) ?></span>
                                        <span class="m"><?= mb_substr($bulan_id[(int)date('n', strtotime($tgl_raw))], 0, 3) ?></span>
                                    </div>
                                    <div class="tl-timeline-card">
                                        <div class="d-flex justify-content-between align-items-start mb-2 pb-2 border-bottom">
                                            <div class="d-flex align-items-center" style="gap: 12px; min-width: 0;">
                                                <div class="tl-avatar" style="background: <?= $tl_color ?>;">
                                                    <?= getPersonAvatarHtml($s['foto'] ?? '', htmlspecialchars($initials)) ?>
                                                </div>
                                                <div style="min-width: 0;">
                                                    <h6 class="mb-0 font-weight-bold text-truncate" style="font-size: 15px; line-height: 1.3;" title="<?= htmlspecialchars($s['nama_guru']) ?>">
                                                        <?= htmlspecialchars($s['nama_guru']) ?>
                                                    </h6>
                                                    <div class="d-flex flex-wrap align-items-center small text-muted mt-1" style="gap: 6px;">
                                                        <span class="badge badge-light border text-dark font-weight-bold px-2 py-0.5" style="border-radius: 4px; font-size: 10px;">
                                                            <i class="far fa-calendar-check text-primary mr-1"></i><?= htmlspecialchars($s['hari']) ?>
                                                        </span>
                                                        <span class="badge badge-light border text-muted px-2 py-0.5" style="border-radius: 4px; font-size: 10px;">
                                                            <i class="far fa-clock mr-1"></i><?= date('d-m-Y', strtotime($tgl_raw)) ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="ml-2 flex-shrink-0">
                                                <span class="tl-time-box">
                                                    <i class="fas fa-hourglass-half"></i> <?= $jam_mulai ?> - <?= $jam_selesai ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center flex-wrap" style="gap: 8px;">
                                            <div class="small text-muted">
                                                <i class="fas fa-chalkboard-teacher text-primary mr-1"></i> Guru Pendamping Les Kelas 6
                                            </div>
                                            <?php if ($is_editable): ?>
                                            <div class="d-flex align-items-center" style="gap: 5px;">
                                                <button class="btn btn-warning btn-sm edit-btn tl-act-btn"
                                                        data-id="<?php echo $s['id_les']; ?>"
                                                        data-hari="<?php echo $s['hari']; ?>"
                                                        data-tanggal="<?php echo $s['tanggal']; ?>"
                                                        data-guru="<?php echo $s['id_guru']; ?>"
                                                        data-mulai="<?php echo date('H:i', strtotime($s['waktu_mulai'])); ?>"
                                                        data-selesai="<?php echo date('H:i', strtotime($s['waktu_selesai'])); ?>"
                                                        title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-danger btn-sm delete-btn tl-act-btn" data-id="<?php echo $s['id_les']; ?>" title="Hapus">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Add Modal -->
<?php if ($is_editable): ?>
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
