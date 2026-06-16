<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has allowed level
if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha', 'wali', 'guru'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$session_type = $user_level;
if ($user_level == 'kepala_madrasah') $session_type = 'kepala';

// Set page title
$page_title = 'Rekap Absensi Les Guru';

// Define CSS and JS libraries
$css_libs = [
    "https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css"
];
$js_libs = [
    "https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js",
    "https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js",
    "https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"
];

// Handle filters
$filter_type = $_POST['filter_type'] ?? 'all';
$selected_date = $_POST['attendance_date'] ?? date('Y-m-d');

// Get school profile
$school_profile = getSchoolProfile($pdo);

// Get Grade 6 Class IDs
$stmt_grade6 = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas WHERE nama_kelas LIKE '%6%' OR nama_kelas LIKE '%VI%'");
$grade6_classes = $stmt_grade6->fetchAll(PDO::FETCH_ASSOC);
$grade6_ids = array_column($grade6_classes, 'id_kelas');
$grade6_names = array_column($grade6_classes, 'nama_kelas');

$daily_results = [];
$all_results = [];

// Process search based on filter type
if ($filter_type == 'daily') {
    // Get all teachers first to filter by grade 6
    $stmt = $pdo->query("SELECT id_guru, nama_guru, nuptk, mengajar FROM tb_guru ORDER BY nama_guru ASC");
    $teachers_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $grade6_teacher_ids = [];
    foreach ($teachers_raw as $t) {
        $mengajar = json_decode($t['mengajar'], true) ?? [];
        foreach ($mengajar as $m) {
            if (in_array($m, $grade6_ids) || in_array($m, $grade6_names)) {
                $grade6_teacher_ids[] = $t['id_guru'];
                break;
            }
        }
    }

    if (!empty($grade6_teacher_ids)) {
        $placeholders = str_repeat('?,', count($grade6_teacher_ids) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT g.id_guru, g.nama_guru, g.nuptk, alg.status, alg.tanggal, alg.waktu_input
            FROM tb_guru g
            LEFT JOIN tb_absensi_les_guru alg ON g.id_guru = alg.id_guru AND alg.tanggal = ?
            WHERE g.id_guru IN ($placeholders)
            ORDER BY g.nama_guru ASC
        ");
        $params = array_merge([$selected_date], $grade6_teacher_ids);
        $stmt->execute($params);
        $daily_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif ($filter_type == 'all') {
    // Get all teachers and filter by grade 6
    $stmt = $pdo->query("SELECT id_guru, nama_guru, nuptk, mengajar FROM tb_guru ORDER BY nama_guru ASC");
    $all_teachers_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $all_teachers = [];
    foreach ($all_teachers_raw as $t) {
        $mengajar = json_decode($t['mengajar'], true) ?? [];
        foreach ($mengajar as $m) {
            if (in_array($m, $grade6_ids) || in_array($m, $grade6_names)) {
                $all_teachers[] = $t;
                break;
            }
        }
    }
    
    // Get all scheduled dates
    $stmt_sched = $pdo->query("
        SELECT DISTINCT tanggal 
        FROM tb_jadwal_les 
        ORDER BY tanggal ASC
    ");
    $scheduled_dates = $stmt_sched->fetchAll(PDO::FETCH_COLUMN);

    // Get all attendance data
    $stmt = $pdo->query("
        SELECT id_guru, status, tanggal
        FROM tb_absensi_les_guru
    ");
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $attendance_by_teacher = [];
    foreach ($records as $r) {
        $gid = $r['id_guru'];
        $date = $r['tanggal'];
        if (!isset($attendance_by_teacher[$gid])) {
            $attendance_by_teacher[$gid] = [
                'dates' => [],
                'summary' => ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0]
            ];
        }
        $attendance_by_teacher[$gid]['dates'][$date] = $r['status'];
        if (isset($attendance_by_teacher[$gid]['summary'][$r['status']])) {
            $attendance_by_teacher[$gid]['summary'][$r['status']]++;
        }
    }
    
    foreach ($all_teachers as $teacher) {
        $gid = $teacher['id_guru'];
        $all_results[] = [
            'nama_guru' => $teacher['nama_guru'],
            'nuptk' => $teacher['nuptk'],
            'dates' => $attendance_by_teacher[$gid]['dates'] ?? [],
            'summary' => $attendance_by_teacher[$gid]['summary'] ?? ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0]
        ];
    }
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Rekap Absensi Les Guru</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Filter Rekap Absensi Les Guru</h4>
                        </div>
                        <div class="card-body">
                            <!-- Print Header -->
                            <div class="print-header" style="display:none;">
                                <?php 
                                $logo_file = $school_profile['logo'] ?? '';
                                $logo_path = '../assets/img/' . $logo_file;
                                if ($logo_file && file_exists(__DIR__ . '/../assets/img/' . $logo_file)): ?>
                                    <img src="<?= $logo_path ?>" alt="Logo">
                                <?php endif; ?>
                                <h3><?= strtoupper($school_profile['nama_yayasan'] ?? 'YAYASAN') ?></h3>
                                <h2><?= strtoupper($school_profile['nama_sekolah'] ?? $school_profile['nama_madrasah'] ?? 'MADRASAH') ?></h2>
                                <p><?= $school_profile['alamat'] ?? '' ?></p>
                                <p>Tahun Ajaran: <?= $school_profile['tahun_ajaran'] ?? '-' ?> | Semester: <?= $school_profile['semester'] ?? '-' ?></p>
                                <hr style="border: 1px solid black; margin-top: 5px;">
                                <h4 style="margin-top: 15px; text-decoration: underline;">REKAP ABSENSI LES GURU</h4>
                            </div>

                            <form method="POST" class="row">
                                <div class="form-group col-md-3">
                                    <label>Jenis Rekap</label>
                                    <select name="filter_type" class="form-control" id="filterType" onchange="this.form.submit()">
                                        <option value="all" <?= $filter_type == 'all' ? 'selected' : '' ?>>Semua</option>
                                        <option value="daily" <?= $filter_type == 'daily' ? 'selected' : '' ?>>Harian</option>
                                    </select>
                                </div>
                                
                                <div class="form-group col-md-3" id="dailyFilter" style="<?= $filter_type == 'daily' ? '' : 'display:none;' ?>">
                                    <label>Pilih Tanggal</label>
                                    <input type="date" name="attendance_date" class="form-control" value="<?= $selected_date ?>" onchange="this.form.submit()">
                                </div>
                            </form>

                            <?php if ($filter_type == 'daily' && !empty($daily_results)): ?>
                                <div class="mt-4">
                                    <div class="btn-group mb-3 float-right">
                                        <a href="../config/export_rekap_les_guru_excel?filter_type=daily&date=<?= $selected_date ?>&session_type=<?= $session_type ?>" target="_blank" class="btn btn-success"><i class="fas fa-file-excel"></i> Excel</a>
                                        <a href="../config/export_rekap_les_guru_pdf?filter_type=daily&date=<?= $selected_date ?>&session_type=<?= $session_type ?>" target="_blank" class="btn btn-danger"><i class="fas fa-file-pdf"></i> PDF</a>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-striped table-md" id="table-daily">
                                            <thead>
                                                <tr>
                                                    <th>No</th>
                                                    <th>Nama Guru</th>
                                                    <th>NUPTK</th>
                                                    <th>Status</th>
                                                    <th>Waktu Input</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($daily_results as $i => $r): ?>
                                                <tr>
                                                    <td><?= $i+1 ?></td>
                                                    <td><?= htmlspecialchars($r['nama_guru']) ?></td>
                                                    <td><?= htmlspecialchars($r['nuptk'] ?: '-') ?></td>
                                                    <td>
                                                        <?php 
                                                        $badge = 'badge-secondary';
                                                        $status = $r['status'] ?: 'Belum Absen';
                                                        if ($status == 'Hadir') $badge = 'badge-success';
                                                        elseif ($status == 'Sakit') $badge = 'badge-info';
                                                        elseif ($status == 'Izin') $badge = 'badge-warning';
                                                        elseif ($status == 'Alpa') $badge = 'badge-danger';
                                                        ?>
                                                        <span class="badge <?= $badge ?>"><?= $status ?></span>
                                                    </td>
                                                    <td><?= $r['waktu_input'] ? date('H:i:s', strtotime($r['waktu_input'])) : '-' ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php elseif ($filter_type == 'all' && !empty($all_results)): ?>
                                <div class="mt-4">
                                    <div class="btn-group mb-3 float-right">
                                        <a href="../config/export_rekap_les_guru_excel?filter_type=all&session_type=<?= $session_type ?>" target="_blank" class="btn btn-success"><i class="fas fa-file-excel"></i> Excel</a>
                                        <a href="../config/export_rekap_les_guru_pdf?filter_type=all&session_type=<?= $session_type ?>" target="_blank" class="btn btn-danger"><i class="fas fa-file-pdf"></i> PDF</a>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-sm" id="table-all">
                                            <thead>
                                                <tr>
                                                    <th rowspan="2" class="align-middle text-center">No</th>
                                                    <th rowspan="2" class="align-middle">Nama Guru</th>
                                                    <th colspan="<?= count($scheduled_dates) ?: 1 ?>" class="text-center">Rekap Absensi Les Guru (Semua Jadwal)</th>
                                                    <th colspan="4" class="text-center">Total</th>
                                                </tr>
                                                <tr>
                                                    <?php 
                                                    if (empty($scheduled_dates)) {
                                                        echo "<th>-</th>";
                                                    } else {
                                                        foreach($scheduled_dates as $d) {
                                                            $short_date = date('d/m', strtotime($d));
                                                            echo "<th class='text-center' title='$d' style='font-size: 8pt;'>$short_date</th>"; 
                                                        }
                                                    }
                                                    ?>
                                                    <th>H</th><th>S</th><th>I</th><th>A</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($all_results as $i => $r): ?>
                                                <tr>
                                                    <td class="text-center"><?= $i+1 ?></td>
                                                    <td style="white-space:nowrap;"><?= htmlspecialchars($r['nama_guru']) ?></td>
                                                    <?php 
                                                    if (empty($scheduled_dates)) {
                                                        echo "<td class='text-center'>-</td>";
                                                    } else {
                                                        foreach($scheduled_dates as $d): 
                                                    ?>
                                                        <td class="text-center" style="font-size: 8pt;">
                                                            <?php 
                                                            $s = $r['dates'][$d] ?? '';
                                                            if ($s == 'Hadir') echo 'H';
                                                            elseif ($s == 'Sakit') echo 'S';
                                                            elseif ($s == 'Izin') echo 'I';
                                                            elseif ($s == 'Alpa') echo 'A';
                                                            else echo '-';
                                                            ?>
                                                        </td>
                                                    <?php 
                                                        endforeach; 
                                                    }
                                                    ?>
                                                    <td class="text-center"><?= $r['summary']['Hadir'] ?></td>
                                                    <td class="text-center"><?= $r['summary']['Sakit'] ?></td>
                                                    <td class="text-center"><?= $r['summary']['Izin'] ?></td>
                                                    <td class="text-center"><?= $r['summary']['Alpa'] ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <!-- Print Signatures -->
                                    <div class="signature-wrapper" style="display:none;">
                                        <div class="signature-box" style="width: 100%;">
                                            <p><?= $school_profile['tempat_jadwal'] ?? 'Sukosono' ?>, <?= formatDateIndonesia(date('Y-m-d')) ?><br>Mengetahui,<br>Kepala Madrasah,</p>
                                            <div style="height: 60px;">
                                                <?php 
                                                $kepala = $school_profile['kepala_madrasah'] ?? '-';
                                                $qr_kepala = 'Validasi Kepala Madrasah: ' . $kepala . ' - ' . ($school_profile['nama_madrasah'] ?? 'Madrasah');
                                                $qr_kepala_url = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qr_kepala);
                                                ?>
                                                <img src="<?= $qr_kepala_url ?>" style="width: 60px;">
                                            </div>
                                            <p><strong><u><?= $kepala ?></u></strong><br>NIP. <?= $school_profile['nip_kepala'] ?? '-' ?></p>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<style>
@media print {
    @page { size: legal landscape; margin: 10mm; }
    .main-sidebar, .main-navbar, .section-header-breadcrumb, .btn-group, .card-header, form, .main-footer { display: none !important; }
    .main-content { padding-top: 0 !important; margin-left: 0 !important; width: 100% !important; }
    .section-body { margin: 0 !important; padding: 0 !important; }
    .card { border: none !important; box-shadow: none !important; }
    .table-responsive { overflow: visible !important; }
    table { width: 100% !important; border-collapse: collapse !important; table-layout: auto !important; }
    th, td { border: 1px solid black !important; padding: 3px 2px !important; font-size: 8pt !important; color: black !important; }
    .badge { border: none !important; padding: 0 !important; color: black !important; background: transparent !important; }
    
    .print-header { display: block !important; text-align: center; margin-bottom: 20px; border-bottom: 3px double black; padding-bottom: 10px; position: relative; }
    .print-header img { position: absolute; left: 0; top: 0; width: 70px; }
    .print-header h2, .print-header h3, .print-header p { margin: 2px 0; color: black !important; }
    
    .signature-wrapper { display: flex !important; justify-content: center; margin-top: 30px; page-break-inside: avoid; }
    .signature-box { text-align: center; width: 100%; }
}
.print-header, .signature-wrapper { display: none; }
</style>

<script>
function exportToExcel(type) {
    var tableId = type === 'daily' ? 'table-daily' : 'table-all';
    var table = document.getElementById(tableId);
    var newTable = table.cloneNode(true);
    
    var wb = XLSX.utils.book_new();
    var ws = XLSX.utils.table_to_sheet(newTable);
    
    XLSX.utils.book_append_sheet(wb, ws, "Rekap Absensi Les Guru");
    XLSX.writeFile(wb, "Rekap_Absensi_Les_Guru_" + type + "_" + new Date().getTime() + ".xlsx");
}

function printReport() {
    window.print();
}
</script>

<?php include '../templates/footer.php'; ?>
