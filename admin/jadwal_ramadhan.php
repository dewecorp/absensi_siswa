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

// Add Select2 libs
$css_libs = [
    'node_modules/select2/dist/css/select2.min.css'
];
$js_libs = [
    'node_modules/select2/dist/js/select2.full.min.js'
];

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
foreach ($gurus as &$g) {
    $guru_map[$g['id_guru']] = $g;
    
    // Process subjects (Mapel)
    $mapel_names = [];
    if (!empty($g['mengajar'])) {
        $teaching_ids = json_decode($g['mengajar'], true);
        if (is_array($teaching_ids)) {
            foreach ($teaching_ids as $mid) {
                if (isset($mapel_map[$mid])) {
                    $mapel_names[] = $mapel_map[$mid]['nama_mapel'];
                }
            }
        }
    }
    $g['mapel_display'] = !empty($mapel_names) ? implode(', ', $mapel_names) : '-';
}
unset($g);

// Get teaching hours (Ramadhan)
$stmt = $pdo->prepare("SELECT * FROM tb_jam_mengajar WHERE jenis = 'Ramadhan' ORDER BY waktu_mulai ASC");
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
        SELECT j.*, jm.waktu_mulai 
        FROM tb_jadwal_pelajaran j
        LEFT JOIN tb_jam_mengajar jm ON j.jam_ke = jm.jam_ke AND jm.jenis = 'Ramadhan'
        WHERE j.kelas_id = ? AND j.jenis = 'Ramadhan'
        ORDER BY jm.waktu_mulai ASC, j.jam_ke ASC
    ");
    $stmt->execute([$selected_kelas_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $class_schedule[$row['hari']][] = $row;
    }
}

// Fetch Main Schedule (All classes)
$stmt = $pdo->query("
    SELECT * FROM tb_jadwal_pelajaran 
    WHERE jenis = 'Ramadhan'
");
$all_schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
$main_schedule = [];
$used_jam_ke = [];
foreach ($all_schedules as $row) {
    $main_schedule[$row['hari']][$row['jam_ke']][$row['kelas_id']] = $row;
    $used_jam_ke[$row['jam_ke']] = true;
}

// Filter jam_mengajar for Main Schedule to only show used slots
$main_schedule_rows = array_filter($jam_mengajar, function($jam) use ($used_jam_ke) {
    return isset($used_jam_ke[$jam['jam_ke']]);
});


// Define days
$days = ['Sabtu', 'Ahad', 'Senin', 'Selasa', 'Rabu', 'Kamis'];

require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<style>
    /* Fix for Select2 dropdown width */
    .select2-dropdown {
        min-width: 300px !important; /* Force wider dropdown */
        width: auto !important;      /* Allow it to grow */
    }
    
    .select2-results__option {
        white-space: nowrap;         /* Prevent text wrapping */
    }
    
    /* KM Column Layout Fix */
    .km-col-container .select2-container {
        width: 100% !important;
        max-width: 100%;
        display: block;
    }
    .km-col-container .select2-selection {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        text-align: center;      /* Center the code */
        padding-left: 2px !important;
        padding-right: 2px !important;
    }
    .km-col-container .select2-selection__rendered {
        padding-left: 2px !important;
        padding-right: 2px !important;
        font-weight: bold;
    }
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Jadwal Pelajaran Ramadhan</h1>
        </div>

        <div class="section-body">
            
            <!-- Filter Section -->
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
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
                        
                        <!-- Export Buttons -->
                        <div>
                            <form method="GET" action="../config/export_jadwal_pdf.php" target="_blank" class="d-inline">
                                <input type="hidden" name="kelas_id" value="<?= $selected_kelas_id ?? '' ?>">
                                <input type="hidden" name="jenis" value="Ramadhan">
                                <button type="submit" class="btn btn-danger btn-icon icon-left">
                                    <i class="fas fa-file-pdf"></i> Export PDF
                                </button>
                            </form>
                            
                            <form method="POST" action="../config/export_jadwal_excel.php" target="_blank" class="d-inline ml-2">
                                <input type="hidden" name="kelas_id" value="<?= $selected_kelas_id ?? '' ?>">
                                <input type="hidden" name="jenis" value="Ramadhan">
                                <button type="submit" class="btn btn-success btn-icon icon-left">
                                    <i class="fas fa-file-excel"></i> Export Excel
                                </button>
                            </form>
                        </div>
                    </div>
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
                                    <div class="card-header bg-light py-2 justify-content-center">
                                        <h6 class="mb-0 font-weight-bold"><?= strtoupper($day) ?></h6>
                                    </div>
                                    <div class="card-body p-0">
                                        <table class="table table-sm table-bordered mb-0" style="font-size: 0.85rem;">
                                            <thead class="bg-light">
                                                <!-- Teacher Header Row -->
                                                <tr>
                                                    <th colspan="2" class="p-1" style="width: 25%">
                                                        <select class="form-control form-control-sm day-guru-select select2-custom" 
                                                                data-day="<?= $day ?>"
                                                                data-kelas="<?= $selected_kelas['id_kelas'] ?>"
                                                                style="width: 100%;">
                                                            <option value="">- Kode -</option>
                                                            <?php 
                                                            // Determine default teacher for the day
                                                            $current_day_guru = null;
                                                            $current_day_guru_name = '';
                                                            if (!empty($day_schedule)) {
                                                                $first_sched = reset($day_schedule);
                                                                $current_day_guru = $first_sched['guru_id'];
                                                                if ($current_day_guru && isset($guru_map[$current_day_guru])) {
                                                                    $current_day_guru_name = $guru_map[$current_day_guru]['nama_guru'];
                                                                }
                                                            }
                                                            
                                                            foreach ($gurus as $g): 
                                                                $kode = $g['kode_guru'] ?? $g['id_guru'];
                                                                $nama = $g['nama_guru'];
                                                            ?>
                                                                <option value="<?= $g['id_guru'] ?>" 
                                                                        data-nama="<?= htmlspecialchars($nama) ?>"
                                                                        data-kode="<?= htmlspecialchars($kode) ?>"
                                                                        <?= $current_day_guru == $g['id_guru'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($kode . ' | ' . $nama) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </th>
                                                    <th colspan="3" class="p-1">
                                                        <input type="text" class="form-control form-control-sm day-guru-name-display" 
                                                               value="<?= htmlspecialchars($current_day_guru_name) ?>" 
                                                               readonly 
                                                               placeholder="Nama Guru..."
                                                               style="font-weight: bold; background-color: #f9f9f9; border-left: 3px solid #007bff;">
                                                    </th>
                                                </tr>
                                                <tr class="text-center">
                                                    <th style="width: 5%">Jam Ke</th>
                                                    <th style="width: 20%">Waktu</th>
                                                    <th style="width: 15%">KM</th>
                                                    <th style="width: 55%">Mata Pelajaran</th>
                                                    <th style="width: 5%"></th>
                                                </tr>
                                            </thead>
                                            <tbody id="schedule-body-<?= $day ?>">
                                                <?php 
                                                $day_schedule = $class_schedule[$day] ?? [];
                                                // ksort($day_schedule);
                                                
                                                if (empty($day_schedule)): ?>
                                                    <tr><td colspan="5" class="text-center text-muted p-3">Belum ada jadwal</td></tr>
                                                <?php else:
                                                    foreach ($day_schedule as $sched): 
                                                        $jam_ke = $sched['jam_ke'];
                                                        $jam = $jam_map[$jam_ke] ?? null;
                                                        $waktu = $jam ? date('H.i', strtotime($jam['waktu_mulai'])) . '-' . date('H.i', strtotime($jam['waktu_selesai'])) : '-';
                                                        $current_mapel = $sched['mapel_id'] ?? '';
                                                        $current_guru = $sched['guru_id'] ?? '';
                                                        
                                                        $mapel_name = '';
                                                        if ($current_mapel && isset($mapel_map[$current_mapel])) {
                                                            $mapel_name = $mapel_map[$current_mapel]['nama_mapel'];
                                                        }
                                                ?>
                                                <!-- Row 1: Mapel -->
                                                <tr>
                                                    <td class="text-center align-middle font-weight-bold"><?= $jam_ke ?></td>
                                                    <td class="text-center align-middle" style="font-size: 0.75rem;"><?= $waktu ?></td>
                                                    
                                                    <!-- KM (Kode Mapel) -->
                                                    <td class="p-1 km-col-container">
                                                        <select class="form-control form-control-sm schedule-select mapel-select select2-custom p-1" 
                                                                data-id="<?= $sched['id_jadwal'] ?>"
                                                                data-field="mapel"
                                                                style="width: 100%; font-size: 0.8rem;">
                                                            <option value="">- Pilih Mapel -</option>
                                                            <?php foreach ($mapels as $m): 
                                                                $kode_mp = $m['kode_mapel'] ?: '-';
                                                                $nama_mp = $m['nama_mapel'];
                                                                $display = $kode_mp . ' | ' . $nama_mp;
                                                            ?>
                                                                <option value="<?= $m['id_mapel'] ?>" 
                                                                        data-kode="<?= htmlspecialchars($kode_mp) ?>"
                                                                        <?= $current_mapel == $m['id_mapel'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($display) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <!-- Hidden Guru Input -->
                                                        <input type="hidden" class="schedule-guru-id" 
                                                               data-id="<?= $sched['id_jadwal'] ?>" 
                                                               value="<?= $sched['guru_id'] ?>">
                                                    </td>
                                                    
                                                    <!-- Nama Mapel -->
                                                    <td class="p-1">
                                                        <input type="text" class="form-control form-control-sm mapel-name-display p-1" 
                                                               value="<?= htmlspecialchars($mapel_name) ?>" 
                                                               readonly 
                                                               style="height: 30px; font-size: 0.8rem; background-color: #f9f9f9;">
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
                        <table class="table table-bordered table-sm text-center table-hover" style="font-size: 0.7rem; min-width: 2000px;">
                            <thead>
                                <!-- Row 1: Days -->
                                <tr class="bg-light">
                                    <th rowspan="3" class="align-middle" style="width: 50px;">JAM<br>KE</th>
                                    <th rowspan="3" class="align-middle" style="width: 100px;">WAKTU</th>
                                    <?php foreach ($days as $day): ?>
                                        <th colspan="<?= count($classes) ?>" class="text-uppercase border-bottom-0" style="border-width: 2px;"><?= $day ?></th>
                                    <?php endforeach; ?>
                                </tr>
                                <!-- Row 2: Classes -->
                                <tr class="bg-light">
                                    <?php foreach ($days as $day): ?>
                                        <?php foreach ($classes as $kelas): ?>
                                            <th style="min-width: 40px;"><?= htmlspecialchars($kelas['nama_kelas']) ?></th>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tr>
                                <!-- Row 3: Teacher Codes -->
                                <tr class="bg-white">
                                    <?php foreach ($days as $day): ?>
                                        <?php foreach ($classes as $kelas): 
                                            // Find teacher for this day/class
                                            $day_guru_code = '-';
                                            if (isset($main_schedule[$day])) {
                                                foreach ($main_schedule[$day] as $jam_scheds) {
                                                    if (isset($jam_scheds[$kelas['id_kelas']])) {
                                                        $sched = $jam_scheds[$kelas['id_kelas']];
                                                        if (!empty($sched['guru_id']) && isset($guru_map[$sched['guru_id']])) {
                                                            $g = $guru_map[$sched['guru_id']];
                                                            $day_guru_code = $g['kode_guru'] ?? substr($g['nama_guru'], 0, 3);
                                                            break; // Found the teacher, break (assuming same teacher for the day)
                                                        }
                                                    }
                                                }
                                            }
                                        ?>
                                            <th class="font-weight-bold" style="border-bottom: 2px solid #dee2e6;"><?= htmlspecialchars($day_guru_code) ?></th>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($main_schedule_rows as $jam): 
                                    $jam_label = $jam['jam_ke'];
                                    $is_special = in_array(strtoupper((string)$jam_label), ['A', 'B', 'C', 'D']);
                                    $waktu = date('H.i', strtotime($jam['waktu_mulai'])) . '-' . date('H.i', strtotime($jam['waktu_selesai']));
                                ?>
                                <tr>
                                    <td class="font-weight-bold"><?= $jam_label ?></td>
                                    <td><?= $waktu ?></td>
                                    
                                    <?php foreach ($days as $day): ?>
                                        <?php 
                                        // Check content for this day/jam (for Special Slots logic)
                                        $special_text = '';
                                        if ($is_special) {
                                            // Find any text for this special slot in this day
                                            if (isset($main_schedule[$day][$jam_label])) {
                                                foreach ($main_schedule[$day][$jam_label] as $sched) {
                                                    if (isset($mapel_map[$sched['mapel_id']])) {
                                                        $special_text = $mapel_map[$sched['mapel_id']]['nama_mapel'];
                                                        break;
                                                    }
                                                }
                                            }
                                            // if (!$special_text) $special_text = 'ISTIRAHAT'; // Default fallback removed as per user request
                                        }

                                        if ($is_special): 
                                        ?>
                                            <td colspan="<?= count($classes) ?>" class="bg-light font-weight-bold text-uppercase" style="letter-spacing: 1px;">
                                                <?= htmlspecialchars($special_text) ?>
                                            </td>
                                        <?php else: ?>
                                            <?php foreach ($classes as $kelas): 
                                                $cell_content = '';
                                                if (isset($main_schedule[$day][$jam_label][$kelas['id_kelas']])) {
                                                    $sched = $main_schedule[$day][$jam_label][$kelas['id_kelas']];
                                                    if (isset($mapel_map[$sched['mapel_id']])) {
                                                        $m = $mapel_map[$sched['mapel_id']];
                                                        $cell_content = $m['kode_mapel'] ?: $m['nama_mapel'];
                                                    }
                                                }
                                            ?>
                                                <td><?= htmlspecialchars($cell_content) ?></td>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tr>
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
    var mapelMap = <?= json_encode($mapel_map) ?>;
    var guruMap = <?= json_encode($guru_map) ?>;

    // Initialize Select2 with Custom Template
    // Use window.load and setTimeout to ensure it runs after all other scripts (including Stisla/scripts.js)
    document.addEventListener("DOMContentLoaded", function() {
        $(window).on('load', function() {
            setTimeout(function() {
                $('.select2-custom').each(function() {
                    // Destroy existing instance if any (to prevent conflict)
                    if ($(this).hasClass("select2-hidden-accessible")) {
                        $(this).select2('destroy');
                    }
                    
                    $(this).select2({
                        width: '100%',
                        placeholder: "- Pilih -",
                        allowClear: false, // Disabled to prevent accidental deletion and fix layout
                        templateSelection: function (data) {
                            if (!data.id) { return data.text; }
                            
                            // Try to get data-kode
                            var $element = $(data.element);
                            var kode = $element.data('kode');
                            
                            if (kode) {
                                return kode;
                            }
                            
                            // Fallback: Try to extract code from text (Format: Kode | Nama)
                            if (data.text.indexOf('|') !== -1) {
                                return data.text.split('|')[0].trim();
                            }
                            
                            return data.text;
                        }
                    });
                });
            }, 100);
        });

        // Auto-update Mapel Name
        $(document).on('change', '.mapel-select', function() {
            var mapelId = $(this).val();
            var $row = $(this).closest('tr');
            var $nameInput = $row.find('.mapel-name-display');
            
            if (mapelId && mapelMap[mapelId]) {
                $nameInput.val(mapelMap[mapelId].nama_mapel);
            } else {
                $nameInput.val('');
            }
        });

        // Handle Top Guru Change
        $(document).on('change', '.day-guru-select', function() {
            console.log('Guru changed');
            var $this = $(this);
            var day = $this.data('day');
            var kelasId = $this.data('kelas');
            var guruId = $this.val();
            var $headerRow = $this.closest('tr');
            var $nameDisplay = $headerRow.find('.day-guru-name-display');
            var namaGuru = $this.find(':selected').data('nama');
            
            console.log('Selected:', guruId, namaGuru);

            // Auto-fill Name using data attribute (more reliable)
            if (guruId && namaGuru) {
                $nameDisplay.val(namaGuru);
            } else if (guruId && guruMap[guruId]) {
                // Fallback to map if data attribute fails
                $nameDisplay.val(guruMap[guruId].nama_guru);
            } else {
                $nameDisplay.val('');
            }

            $.ajax({
                url: 'update_day_guru_ajax.php',
                type: 'POST',
                data: {
                    kelas_id: kelasId,
                    hari: day,
                    guru_id: guruId,
                    jenis: 'Ramadhan'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        // Update hidden inputs for consistency
                        $('#schedule-body-' + day).find('.schedule-guru-id').val(guruId);
                        
                        Swal.fire({
                            icon: 'success',
                            title: 'Guru Diupdate',
                            text: 'Guru untuk hari ' + day + ' berhasil diperbarui',
                            timer: 1000,
                            showConfirmButton: false
                        });
                    } else {
                        Swal.fire('Gagal', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Gagal menghubungi server', 'error');
                }
            });
        });

        // Autosave
        $(document).on('change', '.schedule-select', function() {
            var $this = $(this);
            var idJadwal = $this.data('id');
            var field = $this.data('field');
            var value = $this.val();
            
            // Skip if it's the day-guru-select (handled separately)
            if ($this.hasClass('day-guru-select')) return;

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

    // Add Schedule
    window.addSchedule = function(day, kelasId) {
        // Generate options
        let options = {};
        allSlots.forEach(slot => {
            // Check format of time
            let start = slot.waktu_mulai ? slot.waktu_mulai.substring(0,5) : '';
            let end = slot.waktu_selesai ? slot.waktu_selesai.substring(0,5) : '';
            options[slot.jam_ke] = 'Jam ke-' + slot.jam_ke + ' (' + start + ' - ' + end + ')';
        });

        // Get current selected guru for this day
        let currentGuruId = $('.day-guru-select[data-day="' + day + '"]').val();

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
                        jenis: 'Ramadhan',
                        guru_id: currentGuruId
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
    window.deleteSchedule = function(idJadwal) {
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


</script>

<?php
require_once '../templates/footer.php';
?>
