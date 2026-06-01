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
$page_title = 'Rekap Absensi Les Siswa';

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

// Get all Grade 6 Classes
$stmt_grade6_all = $pdo->query("SELECT id_kelas, nama_kelas, wali_kelas FROM tb_kelas WHERE nama_kelas LIKE '%VI%' OR nama_kelas LIKE '%6%' ORDER BY nama_kelas ASC");
$all_grade6_classes = $stmt_grade6_all->fetchAll(PDO::FETCH_ASSOC);

// Determine which classes to show
$current_guru_id = null;
if (in_array($user_level, ['guru', 'wali'])) {
    if (isset($_SESSION['user_id'])) {
        $id_check = $_SESSION['user_id'];
        if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
            $stmt_uid = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
            $stmt_uid->execute([$id_check]);
            $current_guru_id = $stmt_uid->fetchColumn();
        } else {
            $current_guru_id = $id_check;
        }
    }
}

$classes_to_show = [];
if (in_array($user_level, ['admin', 'tata_usaha', 'kepala_madrasah'])) {
    $classes_to_show = $all_grade6_classes;
} elseif ($current_guru_id) {
    // Get teacher's classes
    $stmt_g = $pdo->prepare("SELECT mengajar FROM tb_guru WHERE id_guru = ?");
    $stmt_g->execute([$current_guru_id]);
    $mengajar_json = (string)$stmt_g->fetchColumn();
    $mengajar_arr = json_decode($mengajar_json, true) ?? [];
    
    // Filter to Grade 6 only
    foreach ($all_grade6_classes as $cls) {
        if (in_array($cls['id_kelas'], $mengajar_arr) || in_array($cls['nama_kelas'], $mengajar_arr)) {
            $classes_to_show[] = $cls;
        }
    }
}

// If no classes to show but it's grade 6 wali, try to find by wali_kelas name
if (empty($classes_to_show) && $user_level === 'wali' && isset($_SESSION['nama_guru'])) {
    $stmt_wali_check = $pdo->prepare("SELECT id_kelas, nama_kelas, wali_kelas FROM tb_kelas WHERE wali_kelas = ? AND (nama_kelas LIKE '%VI%' OR nama_kelas LIKE '%6%')");
    $stmt_wali_check->execute([$_SESSION['nama_guru']]);
    $classes_to_show = $stmt_wali_check->fetchAll(PDO::FETCH_ASSOC);

    if (empty($classes_to_show)) $classes_to_show = $all_grade6_classes;
}

// Default selection
$id_kelas_selected = isset($_POST['kelas']) ? (int)$_POST['kelas'] : (isset($_GET['kelas']) ? (int)$_GET['kelas'] : (count($classes_to_show) > 0 ? $classes_to_show[0]['id_kelas'] : 0));
if ($id_kelas_selected == 0 && count($all_grade6_classes) > 0) {
    $id_kelas_selected = $all_grade6_classes[0]['id_kelas'];
}

// Get selected class info
$nama_kelas_selected = '';
$wali_kelas_selected = '-';
foreach ($all_grade6_classes as $cls) {
    if ($cls['id_kelas'] == $id_kelas_selected) {
        $nama_kelas_selected = $cls['nama_kelas'];
        $wali_kelas_selected = $cls['wali_kelas'];
        break;
    }
}

// Get school profile
$school_profile = getSchoolProfile($pdo);

$daily_results = [];
$all_results = [];
$absent_summary = [];
$scheduled_dates = [];

// Process search based on filter type
if ($filter_type == 'daily') {
    $stmt = $pdo->prepare("
        SELECT s.id_siswa, s.nama_siswa, al.status, al.tanggal, al.waktu_input
        FROM tb_siswa s
        LEFT JOIN tb_absensi_les al ON s.id_siswa = al.id_siswa AND al.tanggal = ?
        WHERE s.id_kelas = ?
        ORDER BY s.nama_siswa ASC
    ");
    $stmt->execute([$selected_date, $id_kelas_selected]);
    $daily_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ringkasan ketidakhadiran les (Sakit/Izin/Alpa) seperti rekap absensi harian
    $summary_stmt = $pdo->prepare("
        SELECT s.nama_siswa, al.status AS keterangan
        FROM tb_absensi_les al
        JOIN tb_siswa s ON s.id_siswa = al.id_siswa
        WHERE al.tanggal = ?
          AND s.id_kelas = ?
          AND al.status IN ('Sakit', 'Izin', 'Alpa')
        ORDER BY s.nama_siswa ASC
    ");
    $summary_stmt->execute([$selected_date, $id_kelas_selected]);
    $absent_summary = $summary_stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($filter_type == 'all') {
    // Get all students
    $stmt = $pdo->prepare("SELECT id_siswa, nama_siswa FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
    $stmt->execute([$id_kelas_selected]);
    $all_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all scheduled dates
    $stmt_sched = $pdo->query("
        SELECT DISTINCT tanggal 
        FROM tb_jadwal_les 
        ORDER BY tanggal ASC
    ");
    $scheduled_dates = $stmt_sched->fetchAll(PDO::FETCH_COLUMN);

    // Get all attendance data
    $stmt = $pdo->prepare("
        SELECT s.id_siswa, al.status, al.tanggal
        FROM tb_absensi_les al
        JOIN tb_siswa s ON al.id_siswa = s.id_siswa
        WHERE s.id_kelas = ?
    ");
    $stmt->execute([$id_kelas_selected]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $attendance_by_student = [];
    foreach ($records as $r) {
        $sid = $r['id_siswa'];
        $date = $r['tanggal'];
        if (!isset($attendance_by_student[$sid])) {
            $attendance_by_student[$sid] = [
                'dates' => [],
                'summary' => ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0]
            ];
        }
        $attendance_by_student[$sid]['dates'][$date] = $r['status'];
        if (isset($attendance_by_student[$sid]['summary'][$r['status']])) {
            $attendance_by_student[$sid]['summary'][$r['status']]++;
        }
    }
    
    foreach ($all_students as $student) {
        $sid = $student['id_siswa'];
        $all_results[] = [
            'nama_siswa' => $student['nama_siswa'],
            'dates' => $attendance_by_student[$sid]['dates'] ?? [],
            'summary' => $attendance_by_student[$sid]['summary'] ?? ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0]
        ];
    }
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Rekap Absensi Les Siswa</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Rekap Absensi Les</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Filter Rekap Absensi Les</h4>
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
                                <h4 style="margin-top: 15px; text-decoration: underline;">REKAP ABSENSI LES SISWA KELAS <?= $nama_kelas_selected ?></h4>
                            </div>

                            <?php
                                $excel_export_url = '../config/export_rekap_les_excel?filter_type=' . urlencode($filter_type)
                                    . ($filter_type == 'daily' ? '&date=' . urlencode($selected_date) : '')
                                    . '&session_type=' . urlencode($session_type)
                                    . '&kelas=' . $id_kelas_selected;
                                $pdf_export_url = '../config/export_rekap_les_pdf?filter_type=' . urlencode($filter_type)
                                    . ($filter_type == 'daily' ? '&date=' . urlencode($selected_date) : '')
                                    . '&session_type=' . urlencode($session_type)
                                    . '&kelas=' . $id_kelas_selected;
                            ?>
                            <form method="POST" class="row align-items-end">
                                <div class="form-group col-md-3">
                                    <label>Jenis Rekap</label>
                                    <select name="filter_type" class="form-control" id="filterType" onchange="this.form.submit()">
                                        <option value="all" <?= $filter_type == 'all' ? 'selected' : '' ?>>Semua</option>
                                        <option value="daily" <?= $filter_type == 'daily' ? 'selected' : '' ?>>Harian</option>
                                    </select>
                                </div>

                                <?php if (count($classes_to_show) > 1): ?>
                                <div class="form-group col-md-3">
                                    <label>Kelas</label>
                                    <select name="kelas" class="form-control" onchange="this.form.submit()">
                                        <?php foreach ($classes_to_show as $cls): ?>
                                        <option value="<?= $cls['id_kelas'] ?>" <?= $id_kelas_selected == $cls['id_kelas'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cls['nama_kelas']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php else: ?>
                                    <input type="hidden" name="kelas" value="<?= $id_kelas_selected ?>">
                                <?php endif; ?>
                                
                                <div class="form-group col-md-3" id="dailyFilter" style="<?= $filter_type == 'daily' ? '' : 'display:none;' ?>">
                                    <label>Pilih Tanggal</label>
                                    <input type="date" name="attendance_date" class="form-control" value="<?= $selected_date ?>" onchange="this.form.submit()">
                                </div>
                                <div class="form-group col-md-<?= count($classes_to_show) > 1 ? '3' : '6' ?> text-md-right text-left mb-3">
                                    <div class="btn-group">
                                        <a href="<?= htmlspecialchars($excel_export_url) ?>" target="_blank" class="btn btn-success">
                                            <i class="fas fa-file-excel"></i> Excel
                                        </a>
                                        <a href="<?= htmlspecialchars($pdf_export_url) ?>" target="_blank" class="btn btn-danger">
                                            <i class="fas fa-file-pdf"></i> PDF
                                        </a>
                                    </div>
                                </div>
                            </form>

                            <?php if ($filter_type == 'daily' && !empty($daily_results)): ?>
                                <div class="mt-4">
                                    <?php
                                        $counts = ['Sakit' => 0, 'Izin' => 0, 'Alpa' => 0];
                                        foreach ($absent_summary as $abs) {
                                            if (isset($counts[$abs['keterangan']])) {
                                                $counts[$abs['keterangan']]++;
                                            }
                                        }
                                    ?>
                                    <div class="mb-3 p-3 border rounded bg-light">
                                        <h6 class="mb-3">Ringkasan Ketidakhadiran Les (<?= htmlspecialchars(formatDateIndonesia($selected_date)) ?>)</h6>
                                        <div class="row">
                                            <div class="col-md-4 mb-2">
                                                <div class="card mb-0 bg-warning text-dark">
                                                    <div class="card-body py-2 text-center">
                                                        <small class="d-block font-weight-bold">Sakit</small>
                                                        <strong style="font-size:20px;"><?= (int)$counts['Sakit'] ?></strong>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-4 mb-2">
                                                <div class="card mb-0 bg-info text-white">
                                                    <div class="card-body py-2 text-center">
                                                        <small class="d-block font-weight-bold">Izin</small>
                                                        <strong style="font-size:20px;"><?= (int)$counts['Izin'] ?></strong>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-4 mb-2">
                                                <div class="card mb-0 bg-danger text-white">
                                                    <div class="card-body py-2 text-center">
                                                        <small class="d-block font-weight-bold">Alpa</small>
                                                        <strong style="font-size:20px;"><?= (int)$counts['Alpa'] ?></strong>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if (!empty($absent_summary)): ?>
                                            <div class="table-responsive mt-2">
                                                <table class="table table-sm table-bordered mb-0">
                                                    <thead class="thead-light">
                                                        <tr>
                                                            <th style="width:50px;">No</th>
                                                            <th>Nama Siswa</th>
                                                            <th style="width:120px;">Keterangan</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($absent_summary as $idx_abs => $abs): ?>
                                                            <?php
                                                                $badge_class = $abs['keterangan'] === 'Sakit'
                                                                    ? 'badge-warning'
                                                                    : ($abs['keterangan'] === 'Izin' ? 'badge-info' : 'badge-danger');
                                                            ?>
                                                            <tr>
                                                                <td><?= (int)($idx_abs + 1) ?></td>
                                                                <td><?= htmlspecialchars($abs['nama_siswa']) ?></td>
                                                                <td><span class="badge <?= $badge_class ?>"><?= htmlspecialchars($abs['keterangan']) ?></span></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-success mb-0 mt-2 py-2">
                                                Tidak ada ketidakhadiran les (Sakit/Izin/Alpa) pada tanggal ini.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-striped table-md" id="table-daily">
                                            <thead>
                                                <tr>
                                                    <th>No</th>
                                                    <th>Nama Siswa</th>
                                                    <th>Status</th>
                                                    <th>Waktu Input</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($daily_results as $i => $r): ?>
                                                <tr>
                                                    <td><?= $i+1 ?></td>
                                                    <td><?= htmlspecialchars($r['nama_siswa']) ?></td>
                                                    <td>
                                                        <?php 
                                                        $status = $r['status'] ?? '';
                                                        $display_status = '';
                                                        $badge = 'badge-secondary';

                                                        if ($status == 'Hadir') {
                                                            $display_status = 'Hadir';
                                                            $badge = 'badge-success';
                                                        } elseif (in_array($status, ['Sakit', 'Izin', 'Alpa'])) {
                                                            $display_status = 'Tidak Hadir (' . $status . ')';
                                                            $badge = 'badge-danger';
                                                        } else {
                                                            $display_status = 'Belum Absen';
                                                            $badge = 'badge-warning';
                                                        }
                                                        ?>
                                                        <span class="badge <?= $badge ?>"><?= $display_status ?></span>
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
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-sm" id="table-all">
                                            <thead>
                                                <tr>
                                                    <th rowspan="2" class="align-middle text-center">No</th>
                                                    <th rowspan="2" class="align-middle">Nama Siswa</th>
                                                    <th colspan="<?= count($scheduled_dates) ?: 1 ?>" class="text-center">Rekap Absensi Les (Semua Jadwal)</th>
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
                                                    <td style="white-space:nowrap;"><?= htmlspecialchars($r['nama_siswa']) ?></td>
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
                                        <div class="signature-box">
                                            <p><?= $school_profile['tempat_jadwal'] ?? 'Sukosono' ?>, <?= formatDateIndonesia(date('Y-m-d')) ?><br>Wali Kelas <?= $nama_kelas_selected ?>,</p>
                                            <div style="height: 60px;">
                                                <?php 
                                                $qr_wali = 'Validasi Wali Kelas: ' . $wali_kelas_selected . ' - ' . ($school_profile['nama_madrasah'] ?? 'Madrasah');
                                                $qr_wali_url = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qr_wali);
                                                ?>
                                                <img src="<?= $qr_wali_url ?>" style="width: 60px;">
                                            </div>
                                            <p><strong><u><?= $wali_kelas_selected ?></u></strong></p>
                                        </div>
                                        <div class="signature-box">
                                            <p><br>Mengetahui,<br>Kepala Madrasah,</p>
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
    
    .signature-wrapper { display: flex !important; justify-content: space-between; margin-top: 30px; page-break-inside: avoid; }
    .signature-box { text-align: center; width: 40%; }
}
.print-header, .signature-wrapper { display: none; }
</style>

<script>
function exportToExcel(type) {
    var tableId = type === 'daily' ? 'table-daily' : 'table-all';
    var table = document.getElementById(tableId);
    var newTable = table.cloneNode(true);
    
    // Create Excel Header
    var excelHtml = '<table>';
    excelHtml += '<tr><td colspan="5" style="text-align:center; font-weight:bold; font-size:14pt;"><?= strtoupper($school_profile['nama_yayasan'] ?? 'YAYASAN') ?></td></tr>';
    excelHtml += '<tr><td colspan="5" style="text-align:center; font-weight:bold; font-size:16pt;"><?= strtoupper($school_profile['nama_madrasah'] ?? 'MADRASAH') ?></td></tr>';
    excelHtml += '<tr><td colspan="5" style="text-align:center;"><?= $school_profile['alamat'] ?? '' ?></td></tr>';
    excelHtml += '<tr><td colspan="5"></td></tr>';
    excelHtml += '<tr><td colspan="5" style="text-align:center; font-weight:bold; text-decoration:underline;">REKAP ABSENSI LES SISWA KELAS <?= $nama_kelas_selected ?></td></tr>';
    excelHtml += '<tr><td colspan="5"></td></tr>';
    excelHtml += '</table>';

    var wb = XLSX.utils.book_new();
    var ws = XLSX.utils.table_to_sheet(newTable);
    
    // Prepend header manually in Excel is hard with table_to_sheet, 
    // so we'll use a simpler approach for the file name and content
    XLSX.utils.book_append_sheet(wb, ws, "Rekap Absensi Les");
    XLSX.writeFile(wb, "Rekap_Absensi_Les_Siswa_" + type + "_" + new Date().getTime() + ".xlsx");
}

function printReport() {
    window.print();
}
</script>

<?php include '../templates/footer.php'; ?>
