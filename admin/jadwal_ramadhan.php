<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check auth
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

$page_title = 'Jadwal Ramadhan';

// Get all classes
$stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all subjects (for mapping)
$stmt = $pdo->query("SELECT * FROM tb_mata_pelajaran ORDER BY nama_mapel ASC");
$mapels = $stmt->fetchAll(PDO::FETCH_ASSOC);
$mapel_map = [];
foreach ($mapels as $m) {
    $mapel_map[$m['id_mapel']] = $m;
}

// Get all teachers (for mapping)
$stmt = $pdo->query("SELECT * FROM tb_guru ORDER BY nama_guru ASC");
$gurus = $stmt->fetchAll(PDO::FETCH_ASSOC);
$guru_map = [];
foreach ($gurus as $g) {
    $guru_map[$g['id_guru']] = $g;
}

// Get teaching hours (Ramadhan)
$stmt = $pdo->prepare("SELECT * FROM tb_jam_mengajar WHERE jenis = 'Ramadhan' ORDER BY jam_ke ASC");
$stmt->execute();
$jam_mengajar = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Map jam_mengajar by jam_ke for easy lookup
$jam_map = [];
foreach ($jam_mengajar as $jam) {
    $jam_map[$jam['jam_ke']] = $jam;
}

// Handle Filter
$selected_kelas_id = isset($_GET['kelas_id']) ? (int)$_GET['kelas_id'] : null;
$selected_kelas = null;
if ($selected_kelas_id) {
    foreach ($classes as $c) {
        if ($c['id_kelas'] == $selected_kelas_id) {
            $selected_kelas = $c;
            break;
        }
    }
}

// Fetch Schedule Data
// If class selected, get specific schedule
$class_schedule = [];
if ($selected_kelas_id) {
    $stmt = $pdo->prepare("
        SELECT * FROM tb_jadwal_pelajaran 
        WHERE kelas_id = ? AND jenis = 'Ramadhan'
    ");
    $stmt->execute([$selected_kelas_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $class_schedule[$row['hari']][$row['jam_ke']] = $row;
    }
}

// Fetch Main Schedule (All classes)
$stmt = $pdo->query("
    SELECT * FROM tb_jadwal_pelajaran 
    WHERE jenis = 'Ramadhan'
");
$all_schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
$main_schedule = [];
foreach ($all_schedules as $row) {
    $main_schedule[$row['hari']][$row['jam_ke']][$row['kelas_id']] = $row;
}

// Define days
$days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Ahad'];

require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Jadwal Pelajaran Ramadhan</h1>
        </div>

        <div class="section-body">
            
            <!-- Filter Section -->
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="" class="form-inline">
                        <label class="mr-2">Pilih Kelas:</label>
                        <select name="kelas_id" class="form-control mr-2" onchange="this.form.submit()">
                            <option value="">-- Tampilkan Semua --</option>
                            <?php foreach ($classes as $kelas): ?>
                                <option value="<?= $kelas['id_kelas'] ?>" <?= $selected_kelas_id == $kelas['id_kelas'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($kelas['nama_kelas']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>

            <?php if ($selected_kelas): ?>
            <!-- Class Schedule Section - Grid Layout -->
            <div id="save-status" class="alert alert-success" style="display:none; position: fixed; top: 20px; right: 20px; z-index: 9999;">
                <i class="fas fa-check-circle"></i> Jadwal tersimpan!
            </div>
            <div id="save-error" class="alert alert-danger" style="display:none; position: fixed; top: 20px; right: 20px; z-index: 9999;">
                <i class="fas fa-exclamation-circle"></i> Gagal menyimpan!
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="card mb-3">
                        <div class="card-body text-center p-3">
                            <h4 class="mb-0">JADWAL PELAJARAN (RAMADHAN)</h4>
                            <h5 class="mb-0 text-muted">KELAS <?= strtoupper($selected_kelas['nama_kelas']) ?></h5>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <?php 
                $chunks = array_chunk($days, 3); // Split days into rows of 3
                foreach ($chunks as $chunk): 
                ?>
                    <div class="col-12">
                        <div class="row">
                        <?php foreach ($chunk as $day): ?>
                            <div class="col-md-4 mb-4">
                                <div class="card h-100 border shadow-sm">
                                    <div class="card-header bg-light py-2">
                                        <div class="d-flex justify-content-between align-items-center w-100">
                                            <h6 class="mb-0 font-weight-bold"><?= strtoupper($day) ?></h6>
                                            <small class="text-muted"><?= $selected_kelas['nama_kelas'] ?></small>
                                        </div>
                                    </div>
                                    <div class="card-body p-0">
                                        <table class="table table-sm table-bordered mb-0" style="font-size: 0.85rem;">
                                            <thead class="bg-light">
                                                <tr class="text-center">
                                                    <th style="width: 10%">No</th>
                                                    <th style="width: 25%">Waktu</th>
                                                    <th style="width: 20%">KM</th>
                                                    <th style="width: 40%">Mata Pelajaran</th>
                                                    <th style="width: 5%"></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $day_schedule = $class_schedule[$day] ?? [];
                                                ksort($day_schedule);
                                                
                                                if (empty($day_schedule)): ?>
                                                    <tr><td colspan="5" class="text-center text-muted p-3">Belum ada jadwal</td></tr>
                                                <?php else:
                                                    foreach ($day_schedule as $jam_ke => $sched): 
                                                        $jam = $jam_map[$jam_ke] ?? null;
                                                        $waktu = $jam ? date('H.i', strtotime($jam['waktu_mulai'])) . '-' . date('H.i', strtotime($jam['waktu_selesai'])) : '-';
                                                        $current_mapel = $sched['mapel_id'] ?? '';
                                                        $current_guru = $sched['guru_id'] ?? '';
                                                ?>
                                                <tr>
                                                    <td class="text-center align-middle font-weight-bold"><?= $jam_ke ?></td>
                                                    <td class="text-center align-middle" style="font-size: 0.75rem;"><?= $waktu ?></td>
                                                    <td class="p-1">
                                                        <select class="form-control form-control-sm schedule-select p-1" 
                                                                data-id="<?= $sched['id_jadwal'] ?>"
                                                                data-field="guru"
                                                                style="height: 30px; font-size: 0.8rem;">
                                                            <option value=""></option>
                                                            <?php foreach ($gurus as $g): 
                                                                $kode = $g['kode_guru'] ?? $g['id_guru'];
                                                            ?>
                                                                <option value="<?= $g['id_guru'] ?>" <?= $current_guru == $g['id_guru'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($kode) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td class="p-1">
                                                        <select class="form-control form-control-sm schedule-select p-1" 
                                                                data-id="<?= $sched['id_jadwal'] ?>"
                                                                data-field="mapel"
                                                                style="height: 30px; font-size: 0.8rem;">
                                                            <option value="">- Mapel -</option>
                                                            <?php foreach ($mapels as $m): ?>
                                                                <option value="<?= $m['id_mapel'] ?>" <?= $current_mapel == $m['id_mapel'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($m['nama_mapel']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td class="text-center align-middle p-1">
                                                        <button type="button" class="btn btn-danger btn-sm p-0" style="width: 24px; height: 24px; line-height: 24px;" onclick="deleteSchedule(<?= $sched['id_jadwal'] ?>)" title="Hapus">
                                                            <i class="fas fa-times" style="font-size: 12px;"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                                <?php endforeach; endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="card-footer p-2 text-center bg-white border-top-0">
                                        <button type="button" class="btn btn-sm btn-outline-primary w-100 dashed-border" onclick="addSchedule('<?= $day ?>', <?= $selected_kelas['id_kelas'] ?>)">
                                            <i class="fas fa-plus"></i> Tambah Jam
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <!-- Main Schedule Section -->
            <div class="card">
                <div class="card-header">
                    <h4>Jadwal Utama (Semua Kelas)</h4>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm text-center" style="font-size: 0.8rem;">
                            <thead>
                                <tr>
                                    <th rowspan="2" class="align-middle">Hari</th>
                                    <th rowspan="2" class="align-middle">Jam</th>
                                    <th rowspan="2" class="align-middle">Waktu</th>
                                    <th colspan="<?= count($classes) ?>">Kelas</th>
                                </tr>
                                <tr>
                                    <?php foreach ($classes as $kelas): ?>
                                        <th><?= htmlspecialchars($kelas['nama_kelas']) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($days as $day): ?>
                                    <?php 
                                    // Check if there are any classes on this day (optional, but let's show all rows for structure)
                                    $first_row = true;
                                    foreach ($jam_mengajar as $jam): 
                                    ?>
                                    <tr>
                                        <?php if ($first_row): ?>
                                            <td rowspan="<?= count($jam_mengajar) ?>" class="align-middle font-weight-bold"><?= $day ?></td>
                                        <?php endif; ?>
                                        <td><?= $jam['jam_ke'] ?></td>
                                        <td><?= date('H:i', strtotime($jam['waktu_mulai'])) ?> - <?= date('H:i', strtotime($jam['waktu_selesai'])) ?></td>
                                        
                                        <?php foreach ($classes as $kelas): ?>
                                            <td>
                                                <?php 
                                                if (isset($main_schedule[$day][$jam['jam_ke']][$kelas['id_kelas']])) {
                                                    $sched = $main_schedule[$day][$jam['jam_ke']][$kelas['id_kelas']];
                                                    // Show Code if available, else Name
                                                    $mapel = $mapel_map[$sched['mapel_id']] ?? null;
                                                    $guru = $guru_map[$sched['guru_id']] ?? null;
                                                    
                                                    $mapel_display = $mapel ? ($mapel['kode_mapel'] ?: $mapel['nama_mapel']) : '?';
                                                    $guru_display = $guru ? ($guru['kode_guru'] ?? substr($guru['nama_guru'], 0, 3)) : '?'; // Assuming no kode_guru yet, use name
                                                    
                                                    echo htmlspecialchars($mapel_display);
                                                    // echo "<br><small>" . htmlspecialchars($guru_display) . "</small>";
                                                }
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                    <?php 
                                    $first_row = false;
                                    endforeach; ?>
                                    <!-- Separator row between days -->
                                    <tr class="bg-light"><td colspan="<?= 3 + count($classes) ?>"></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </section>
</div>

<?php endif; ?>

<!-- JS Dependencies -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    var allSlots = <?= json_encode($jam_mengajar) ?>;

    // Add Schedule
    function addSchedule(day, kelasId) {
        // Generate options
        let options = {};
        allSlots.forEach(slot => {
            // Check format of time
            let start = slot.waktu_mulai ? slot.waktu_mulai.substring(0,5) : '';
            let end = slot.waktu_selesai ? slot.waktu_selesai.substring(0,5) : '';
            options[slot.jam_ke] = 'Jam ke-' + slot.jam_ke + ' (' + start + ' - ' + end + ')';
        });

        Swal.fire({
            title: 'Tambah Jadwal (Ramadhan) - ' + day,
            input: 'select',
            inputOptions: options,
            inputPlaceholder: 'Pilih Jam Ke...',
            showCancelButton: true,
            confirmButtonText: 'Tambah',
            cancelButtonText: 'Batal',
            showLoaderOnConfirm: true,
            preConfirm: (jamKe) => {
                if (!jamKe) {
                    Swal.showValidationMessage('Silakan pilih jam');
                    return false;
                }
                return $.ajax({
                    url: 'add_jadwal_ajax.php',
                    type: 'POST',
                    data: {
                        kelas_id: kelasId,
                        hari: day,
                        jam_ke: jamKe,
                        jenis: 'Ramadhan'
                    },
                    dataType: 'json'
                }).catch(error => {
                    let msg = error.responseJSON ? error.responseJSON.message : 'Gagal menambahkan data (Mungkin duplikat)';
                    Swal.showValidationMessage(msg);
                });
            },
            allowOutsideClick: () => !Swal.isLoading()
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Berhasil!',
                    text: 'Jadwal berhasil ditambahkan',
                    icon: 'success',
                    timer: 1000,
                    showConfirmButton: false
                }).then(() => {
                    location.reload();
                });
            }
        });
    }

    // Delete Schedule
    function deleteSchedule(idJadwal) {
        Swal.fire({
            title: 'Hapus Jadwal?',
            text: "Data yang dihapus tidak dapat dikembalikan!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'delete_jadwal_ajax.php',
                    type: 'POST',
                    data: { id_jadwal: idJadwal },
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            Swal.fire(
                                'Terhapus!',
                                'Jadwal berhasil dihapus.',
                                'success'
                            ).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire('Error', response.message, 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error', 'Terjadi kesalahan sistem', 'error');
                    }
                });
            }
        });
    }

    // Autosave
    $(document).ready(function() {
        // Initialize Select2 if needed (optional for simple selects, but good for search)
        // $('.schedule-select').select2(); // If using select2, need to bind change event differently

        $('.schedule-select').on('change', function() {
            var $this = $(this);
            var idJadwal = $this.data('id');
            var field = $this.data('field');
            var value = $this.val();
            
            // Show saving indicator (optional)
            $('#save-status').hide();
            $('#save-error').hide();

            $.ajax({
                url: 'update_jadwal_ajax.php',
                type: 'POST',
                data: {
                    id_jadwal: idJadwal,
                    field: field,
                    value: value
                },
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        // Flash success
                        $('#save-status').fadeIn().delay(1000).fadeOut();
                    } else {
                        $('#save-error').text('Gagal: ' + response.message).fadeIn().delay(2000).fadeOut();
                    }
                },
                error: function() {
                    $('#save-error').text('Gagal menghubungi server').fadeIn().delay(2000).fadeOut();
                }
            });
        });
    });
</script>

<?php
require_once '../templates/footer.php';
?>
