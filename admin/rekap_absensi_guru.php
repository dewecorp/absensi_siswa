<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha'])) {
    redirect('../login.php');
}

// Set page title
$page_title = 'Rekap Absensi Guru';

// Define CSS libraries for this page
$css_libs = [
    "node_modules/select2/dist/css/select2.min.css",
    "https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css"
];

// Define JS libraries for this page
$js_libs = [
    "node_modules/select2/dist/js/select2.full.min.js",
    "https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js",
    "https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js",
    // Using SheetJS from jsDelivr as alternative to XLSX
    "https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"
];

// Handle form submission
$filter_type = $_POST['filter_type'] ?? 'daily';
$selected_date = isset($_POST['attendance_date']) ? $_POST['attendance_date'] : date('Y-m-d');
$selected_month = isset($_POST['month_picker']) ? $_POST['month_picker'] : date('Y-m');
$selected_teacher = isset($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : 0;
$semester_results = [];
$daily_results = [];
$monthly_results = [];
$teacher_results = [];
$teacher_attendance_summary = [];

// Define month names array (used in multiple places)
$month_names = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

// Prepare month name for JavaScript (if selected_month is set)
$js_month_name = '';
$js_month_year = '';
if (!empty($selected_month)) {
    $month_num = (int)substr($selected_month, 5, 2);
    $js_month_name = isset($month_names[$month_num]) ? $month_names[$month_num] : "";
    $js_month_year = substr($selected_month, 0, 4);
    $js_month_name_safe = htmlspecialchars($js_month_name, ENT_QUOTES, "UTF-8");
    $js_month_year_safe = htmlspecialchars($js_month_year, ENT_QUOTES, "UTF-8");
    $js_month_name_file = htmlspecialchars(str_replace(" ", "_", strtolower($js_month_name)), ENT_QUOTES, "UTF-8");
} else {
    $js_month_name_safe = "";
    $js_month_year_safe = date("Y");
    $js_month_name_file = "";
}

// Get school profile for semester information
$school_profile = getSchoolProfile($pdo);
$active_semester = $school_profile['semester'] ?? 'Semester 1';
$madrasah_head_name = $school_profile['kepala_madrasah'] ?? '.........................';
$madrasah_head_signature = $school_profile['ttd_kepala'] ?? '';
$school_name = $school_profile['nama_madrasah'] ?? 'Madrasah';

// Get all teachers for dropdown
$stmt = $pdo->query("SELECT id_guru, nama_guru, nuptk FROM tb_guru ORDER BY nama_guru ASC");
$teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Process search based on filter type
if ($filter_type == 'daily' && !empty($selected_date)) {
    // Daily filter
    // Get all teachers
    $stmt = $pdo->query("SELECT id_guru, nama_guru, nuptk FROM tb_guru ORDER BY nama_guru ASC");
    $all_daily_teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get attendance data for the selected date
    $stmt = $pdo->prepare("
        SELECT g.id_guru, g.nama_guru, g.nuptk, a.status as keterangan, a.keterangan as catatan, a.tanggal, a.waktu_input
        FROM tb_absensi_guru a
        LEFT JOIN tb_guru g ON a.id_guru = g.id_guru  
        WHERE a.tanggal = ?
        ORDER BY g.nama_guru ASC
    ");
    $stmt->execute([$selected_date]);
    $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Organize attendance data by teacher ID
    $attendance_by_teacher = [];
    foreach ($attendance_records as $record) {
        $attendance_by_teacher[$record['id_guru']] = $record;
    }
    
    // Combine all teachers with their attendance data
    $daily_results = [];
    foreach ($all_daily_teachers as $teacher) {
        if (isset($attendance_by_teacher[$teacher['id_guru']])) {
            // Teacher has attendance data for this date
            $daily_results[] = $attendance_by_teacher[$teacher['id_guru']];
        } else {
            // Teacher has no attendance data for this date
            $daily_results[] = [
                'nama_guru' => $teacher['nama_guru'],
                'nuptk' => $teacher['nuptk'],
                'keterangan' => 'Belum Absen', // Mark as not yet attended
                'catatan' => '',
                'tanggal' => $selected_date,
                'waktu_input' => null
            ];
        }
    }
} elseif ($filter_type == 'monthly' && !empty($selected_month)) {
    // Monthly filter
    $year = substr($selected_month, 0, 4);
    $month = substr($selected_month, 5, 2);
    
    // Get all teachers
    $stmt = $pdo->query("SELECT id_guru, nama_guru, nuptk FROM tb_guru ORDER BY nama_guru ASC");
    $all_teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get attendance data for the month
    $stmt = $pdo->prepare("
        SELECT g.id_guru, g.nama_guru, g.nuptk, a.status as keterangan, DAY(a.tanggal) as day
        FROM tb_absensi_guru a
        LEFT JOIN tb_guru g ON a.id_guru = g.id_guru
        WHERE YEAR(a.tanggal) = ? AND MONTH(a.tanggal) = ?
        ORDER BY g.nama_guru, a.tanggal
    ");
    $stmt->execute([$year, $month]);
    $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Organize attendance data by teacher ID
    $attendance_by_teacher = [];
    foreach ($attendance_records as $record) {
        $teacher_id = $record['id_guru'];
        if (!isset($attendance_by_teacher[$teacher_id])) {
            $attendance_by_teacher[$teacher_id] = [
                'days' => array_fill(1, 31, ''), // Initialize all days as empty
                'summary' => ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0]
            ];
        }
        $day = (int)$record['day'];
        $attendance_by_teacher[$teacher_id]['days'][$day] = $record['keterangan'];
        $status_key = ucfirst($record['keterangan']);
        if (isset($attendance_by_teacher[$teacher_id]['summary'][$status_key])) {
            $attendance_by_teacher[$teacher_id]['summary'][$status_key]++;
        }
    }
    
    // Combine all teachers with their attendance data
    $teacher_attendance = [];
    foreach ($all_teachers as $teacher) {
        $teacher_id = $teacher['id_guru'];
        $teacher_data = [
            'nama_guru' => $teacher['nama_guru'],
            'nuptk' => $teacher['nuptk'],
            'days' => array_fill(1, 31, ''), // Initialize all days as empty
            'summary' => ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0]
        ];
        
        // Merge with attendance data if available
        if (isset($attendance_by_teacher[$teacher_id])) {
            $teacher_data['days'] = $attendance_by_teacher[$teacher_id]['days'];
            $teacher_data['summary'] = $attendance_by_teacher[$teacher_id]['summary'];
        }
        
        $teacher_attendance[$teacher_id] = $teacher_data;
    }
    
    // Convert to indexed array
    $monthly_results = array_values($teacher_attendance);
} elseif ($filter_type == 'teacher' && $selected_teacher > 0) {
    // Teacher filter - get attendance data for specific teacher
    $stmt = $pdo->prepare("
        SELECT g.nama_guru, g.nuptk, a.status as keterangan, a.keterangan as catatan, a.tanggal
        FROM tb_absensi_guru a
        LEFT JOIN tb_guru g ON a.id_guru = g.id_guru
        WHERE g.id_guru = ?
        ORDER BY a.tanggal DESC
    ");
    $stmt->execute([$selected_teacher]);
    $teacher_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary statistics
    $summary = ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0];
    foreach ($teacher_results as $record) {
        $status_key = ucfirst($record['keterangan']);
        if (isset($summary[$status_key])) {
            $summary[$status_key]++;
        }
    }
    $teacher_attendance_summary = $summary;
} elseif ($filter_type == 'semester') {
    // Semester filter - get attendance data for the active semester
    $academic_year = $school_profile['tahun_ajaran'] ?? (date('Y') . '/' . (date('Y') + 1));
    
    // Parse academic year (e.g., "2025/2026")
    $years = explode('/', $academic_year);
    $start_year = (int)($years[0] ?? date('Y'));
    $end_year = (int)($years[1] ?? (date('Y') + 1));
    
    // Determine semester months and years
    if ($active_semester == 'Semester 1') {
        // July to December of the first year
        $query_year = $start_year;
        $start_month = 7;
        $end_month = 12;
    } else {
        // January to June of the second year
        $query_year = $end_year;
        $start_month = 1;
        $end_month = 6;
    }
    
    // Get all teachers
    $stmt = $pdo->query("SELECT id_guru, nama_guru, nuptk FROM tb_guru ORDER BY nama_guru ASC");
    $all_teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get attendance data for the semester
    $stmt = $pdo->prepare("
        SELECT g.id_guru, g.nama_guru, g.nuptk, a.status as keterangan, a.tanggal,
               MONTH(a.tanggal) as month, DAY(a.tanggal) as day
        FROM tb_absensi_guru a
        LEFT JOIN tb_guru g ON a.id_guru = g.id_guru
        WHERE YEAR(a.tanggal) = ? AND MONTH(a.tanggal) BETWEEN ? AND ?
        ORDER BY g.nama_guru, a.tanggal
    ");
    $stmt->execute([$query_year, $start_month, $end_month]);
    $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Organize attendance data by teacher ID
    $attendance_by_teacher = [];
    foreach ($attendance_records as $record) {
        $teacher_id = $record['id_guru'];
        $month = (int)$record['month'];
        $status = $record['keterangan'];
        
        if (!isset($attendance_by_teacher[$teacher_id])) {
            $attendance_by_teacher[$teacher_id] = [
                'monthly_totals' => [], // Initialize monthly totals array
                'summary' => ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0]
            ];
            
            // Initialize all months in semester
            for ($m = $start_month; $m <= $end_month; $m++) {
                $attendance_by_teacher[$teacher_id]['monthly_totals'][$m] = [
                    'Hadir' => 0, 'Sakit' => 0, 'Izin' => 0
                ];
            }
        }
        
        // Count attendance status for the month
        $status_key = ucfirst($status);
        if (isset($attendance_by_teacher[$teacher_id]['monthly_totals'][$month][$status_key])) {
            $attendance_by_teacher[$teacher_id]['monthly_totals'][$month][$status_key]++;
            $attendance_by_teacher[$teacher_id]['summary'][$status_key]++;
        }
    }
    
    // Combine all teachers with their attendance data
    $teacher_attendance = [];
    foreach ($all_teachers as $teacher) {
        $teacher_id = $teacher['id_guru'];
        $teacher_data = [
            'nama_guru' => $teacher['nama_guru'],
            'nuptk' => $teacher['nuptk'],
            'monthly_totals' => [],
            'summary' => ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0]
        ];
        
        // Initialize monthly totals for all months in semester
        for ($m = $start_month; $m <= $end_month; $m++) {
            $teacher_data['monthly_totals'][$m] = [
                'Hadir' => 0, 'Sakit' => 0, 'Izin' => 0
            ];
        }
        
        // Merge with attendance data if available
        if (isset($attendance_by_teacher[$teacher_id])) {
            $teacher_data['monthly_totals'] = $attendance_by_teacher[$teacher_id]['monthly_totals'];
            $teacher_data['summary'] = $attendance_by_teacher[$teacher_id]['summary'];
        }
        
        $teacher_attendance[$teacher_id] = $teacher_data;
    }
    
    // Convert to indexed array
    $semester_results = array_values($teacher_attendance);
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

            <!-- Custom CSS for semester table -->
            <style>
            #semesterTable {
                font-size: 10pt;
                color: black;
                table-layout: auto;
            }
            #semesterTable thead th {
                text-align: center;
                vertical-align: middle;
                background-color: #f8f9fa;
                font-weight: bold;
                color: black;
            }
            #semesterTable thead th:nth-child(2) { /* Nama Guru column */
                white-space: normal;
                text-align: center;
            }
            #semesterTable tbody td {
                color: black;
                white-space: nowrap;
            }
            #semesterTable tbody td:nth-child(2) { /* Nama Guru column */
                white-space: normal;
                text-align: left;
            }
            </style>
            
            <!-- Main Content -->
            <div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Rekap Absensi Guru</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item active"><a href="#">Dashboard</a></div>
                            <div class="breadcrumb-item">Rekap Absensi Guru</div>
                        </div>
                    </div>

                    <div class="section-body">
                        <div class="row">
                            <div class="col-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h4>Filter Absensi Guru</h4>
                                    </div>
                                    <div class="card-body">
                                        <form method="POST" class="row" id="attendanceFilterForm">
                                            <div class="form-group col-md-3">
                                                <label>Jenis Filter</label>
                                                <select name="filter_type" class="form-control selectric" id="filterType">
                                                    <option value="daily" <?php echo ($filter_type == 'daily') ? 'selected' : ''; ?>>Harian</option>
                                                    <option value="monthly" <?php echo ($filter_type == 'monthly') ? 'selected' : ''; ?>>Bulanan</option>
                                                    <option value="semester" <?php echo ($filter_type == 'semester') ? 'selected' : ''; ?>>Per Semester</option>
                                                    <option value="teacher" <?php echo ($filter_type == 'teacher') ? 'selected' : ''; ?>>Per Guru</option>
                                                </select>
                                            </div>
                                            
                                            <div class="form-group col-md-3 daily-filter" style="<?php echo ($filter_type == 'daily') ? '' : 'display:none;'; ?>">
                                                <label>Pilih Tanggal</label>
                                                <input type="date" name="attendance_date" class="form-control" 
                                                       value="<?php echo htmlspecialchars($selected_date); ?>" id="datePicker">
                                            </div>
                                            
                                            <div class="form-group col-md-3 monthly-filter" style="<?php echo ($filter_type == 'monthly') ? '' : 'display:none;'; ?>">
                                                <label>Pilih Bulan</label>
                                                <input type="month" name="month_picker" class="form-control" 
                                                       value="<?php echo htmlspecialchars($selected_month); ?>" id="monthPicker">
                                            </div>
                                            
                                            <div class="form-group col-md-3 teacher-filter" style="<?php echo ($filter_type == 'teacher') ? '' : 'display:none;'; ?>">
                                                <label>Pilih Guru</label>
                                                <select name="teacher_id" class="form-control selectric" id="teacherSelect">
                                                    <option value="">Pilih Guru...</option>
                                                    <?php foreach ($teachers as $teacher): ?>
                                                        <option value="<?php echo $teacher['id_guru']; ?>" <?php echo ($selected_teacher == $teacher['id_guru']) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($teacher['nama_guru']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="form-group col-md-2">
                                                <label>&nbsp;</label>
                                                <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-search"></i> Tampilkan</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($daily_results)): ?>
                        <div class="row">
                            <div class="col-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h4>Rekap Harian - <?php echo date('d F Y', strtotime($selected_date)); ?></h4>
                                        <div class="card-header-action">
                                            <button class="btn btn-success" id="exportExcelBtn"><i class="fas fa-file-excel"></i> Export Excel</button>
                                            <button class="btn btn-danger" id="exportPdfBtn"><i class="fas fa-file-pdf"></i> Export PDF</button>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-striped" id="dailyTable">
                                                <thead>
                                                    <tr>
                                                        <th width="5%">No</th>
                                                        <th>Nama Guru</th>
                                                        <th>NUPTK</th>
                                                        <th>Status</th>
                                                        <th>Waktu</th>
                                                        <th>Keterangan</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($daily_results as $index => $row): ?>
                                                    <tr>
                                                        <td><?php echo $index + 1; ?></td>
                                                        <td><?php echo htmlspecialchars($row['nama_guru']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['nuptk']); ?></td>
                                                        <td>
                                                            <?php 
                                                            $status = strtolower($row['keterangan']);
                                                            $badge_class = 'secondary';
                                                            if ($status == 'hadir') $badge_class = 'success';
                                                            elseif ($status == 'sakit') $badge_class = 'primary';
                                                            elseif ($status == 'izin') $badge_class = 'warning';
                                                            ?>
                                                            <span class="badge badge-<?php echo $badge_class; ?>"><?php echo ucfirst($status); ?></span>
                                                        </td>
                                                        <td><?php echo isset($row['waktu_input']) && $row['waktu_input'] ? date('H:i:s', strtotime($row['waktu_input'])) : '-'; ?></td>
                                                        <td><?php echo htmlspecialchars($row['catatan'] ?? '-'); ?></td>
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

                        <?php if (!empty($monthly_results)): ?>
                        <div class="row">
                            <div class="col-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h4>Rekap Bulanan - <?php echo $js_month_name . ' ' . $js_month_year; ?></h4>
                                        <div class="card-header-action">
                                            <button class="btn btn-success" id="exportExcelBtn"><i class="fas fa-file-excel"></i> Export Excel</button>
                                            <button class="btn btn-danger" id="exportPdfBtn"><i class="fas fa-file-pdf"></i> Export PDF</button>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-sm" id="monthlyTable">
                                                <thead>
                                                    <tr>
                                                        <th rowspan="2" class="text-center align-middle">No</th>
                                                        <th rowspan="2" class="text-center align-middle" style="min-width: 200px;">Nama Guru</th>
                                                        <th colspan="31" class="text-center">Tanggal</th>
                                                        <th colspan="3" class="text-center">Total</th>
                                                    </tr>
                                                    <tr>
                                                        <?php for($i=1; $i<=31; $i++): ?>
                                                        <th class="text-center" style="min-width: 25px; font-size: 10px;"><?php echo $i; ?></th>
                                                        <?php endfor; ?>
                                                        <th class="text-center bg-success text-white" title="Hadir">H</th>
                                                        <th class="text-center bg-primary text-white" title="Sakit">S</th>
                                                        <th class="text-center bg-warning text-white" title="Izin">I</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($monthly_results as $index => $student): ?>
                                                    <tr>
                                                        <td class="text-center"><?php echo $index + 1; ?></td>
                                                        <td><?php echo htmlspecialchars($student['nama_guru']); ?></td>
                                                        <?php for($i=1; $i<=31; $i++): 
                                                            $status = isset($student['days'][$i]) ? strtolower($student['days'][$i]) : '';
                                                            $code = '';
                                                            $bg = '';
                                                            if ($status == 'hadir') { $code = 'H'; $bg = 'bg-success text-white'; }
                                                            elseif ($status == 'sakit') { $code = 'S'; $bg = 'bg-primary text-white'; }
                                                            elseif ($status == 'izin') { $code = 'I'; $bg = 'bg-warning text-white'; }
                                                        ?>
                                                        <td class="text-center <?php echo $bg; ?>" style="padding: 2px;"><?php echo $code; ?></td>
                                                        <?php endfor; ?>
                                                        <td class="text-center font-weight-bold"><?php echo $student['summary']['Hadir']; ?></td>
                                                        <td class="text-center font-weight-bold"><?php echo $student['summary']['Sakit']; ?></td>
                                                        <td class="text-center font-weight-bold"><?php echo $student['summary']['Izin']; ?></td>
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

                        <?php if (!empty($teacher_results)): ?>
                        <div class="row">
                            <div class="col-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h4>Rekap Absensi Per Guru</h4>
                                        <div class="card-header-action">
                                            <button class="btn btn-success" id="exportExcelBtn"><i class="fas fa-file-excel"></i> Export Excel</button>
                                            <button class="btn btn-danger" id="exportPdfBtn"><i class="fas fa-file-pdf"></i> Export PDF</button>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div class="row mb-4">
                                            <div class="col-md-3">
                                                <div class="card bg-success text-white">
                                                    <div class="card-body text-center p-3">
                                                        <h5>Hadir</h5>
                                                        <h3><?php echo $teacher_attendance_summary['Hadir']; ?></h3>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="card bg-primary text-white">
                                                    <div class="card-body text-center p-3">
                                                        <h5>Sakit</h5>
                                                        <h3><?php echo $teacher_attendance_summary['Sakit']; ?></h3>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="card bg-warning text-white">
                                                    <div class="card-body text-center p-3">
                                                        <h5>Izin</h5>
                                                        <h3><?php echo $teacher_attendance_summary['Izin']; ?></h3>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="table-responsive">
                                            <table class="table table-striped" id="teacherTable">
                                                <thead>
                                                    <tr>
                                                        <th width="5%">No</th>
                                                        <th>Tanggal</th>
                                                        <th>Status</th>
                                                        <th>Keterangan</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($teacher_results as $index => $row): ?>
                                                    <tr>
                                                        <td><?php echo $index + 1; ?></td>
                                                        <td><?php echo date('d F Y', strtotime($row['tanggal'])); ?></td>
                                                        <td>
                                                            <?php 
                                                            $status = strtolower($row['keterangan']);
                                                            $badge_class = 'secondary';
                                                            if ($status == 'hadir') $badge_class = 'success';
                                                            elseif ($status == 'sakit') $badge_class = 'primary';
                                                            elseif ($status == 'izin') $badge_class = 'warning';
                                                            ?>
                                                            <span class="badge badge-<?php echo $badge_class; ?>"><?php echo ucfirst($status); ?></span>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($row['catatan'] ?? '-'); ?></td>
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

                        <?php if (!empty($semester_results)): ?>
                        <div class="row">
                            <div class="col-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h4>Rekap Semester - <?php echo $active_semester . ' (' . ($school_profile['tahun_ajaran'] ?? '') . ')'; ?></h4>
                                        <div class="card-header-action">
                                            <button class="btn btn-success" id="exportExcelBtn"><i class="fas fa-file-excel"></i> Export Excel</button>
                                            <button class="btn btn-danger" id="exportPdfBtn"><i class="fas fa-file-pdf"></i> Export PDF</button>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-sm" id="semesterTable">
                                                <thead>
                                                    <tr>
                                                        <th rowspan="2" class="text-center align-middle">No</th>
                                                        <th rowspan="2" class="text-center align-middle" style="min-width: 200px;">Nama Guru</th>
                                                        <?php 
                                                        // Headers for months
                                                        for ($m = $start_month; $m <= $end_month; $m++) {
                                                            echo '<th colspan="3" class="text-center">' . $month_names[$m] . '</th>';
                                                        }
                                                        ?>
                                                        <th colspan="3" class="text-center bg-light">Total Semester</th>
                                                    </tr>
                                                    <tr>
                                                        <?php 
                                                        // Headers for status per month
                                                        for ($m = $start_month; $m <= $end_month; $m++) {
                                                            echo '<th class="text-center bg-success text-white" style="font-size: 9px; padding: 2px;">H</th>';
                                                            echo '<th class="text-center bg-primary text-white" style="font-size: 9px; padding: 2px;">S</th>';
                                                            echo '<th class="text-center bg-warning text-white" style="font-size: 9px; padding: 2px;">I</th>';
                                                        }
                                                        // Headers for total semester
                                                        echo '<th class="text-center bg-success text-white" style="font-size: 9px; padding: 2px;">H</th>';
                                                        echo '<th class="text-center bg-primary text-white" style="font-size: 9px; padding: 2px;">S</th>';
                                                        echo '<th class="text-center bg-warning text-white" style="font-size: 9px; padding: 2px;">I</th>';
                                                        ?>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($semester_results as $index => $teacher): ?>
                                                    <tr>
                                                        <td class="text-center"><?php echo $index + 1; ?></td>
                                                        <td><?php echo htmlspecialchars($teacher['nama_guru']); ?></td>
                                                        <?php 
                                                        // Data per month
                                                        for ($m = $start_month; $m <= $end_month; $m++) {
                                                            $monthly = $teacher['monthly_totals'][$m];
                                                            echo '<td class="text-center" style="padding: 2px;">' . ($monthly['Hadir'] > 0 ? $monthly['Hadir'] : '-') . '</td>';
                                                            echo '<td class="text-center" style="padding: 2px;">' . ($monthly['Sakit'] > 0 ? $monthly['Sakit'] : '-') . '</td>';
                                                            echo '<td class="text-center" style="padding: 2px;">' . ($monthly['Izin'] > 0 ? $monthly['Izin'] : '-') . '</td>';
                                                        }
                                                        // Total semester
                                                        echo '<td class="text-center font-weight-bold bg-light" style="padding: 2px;">' . $teacher['summary']['Hadir'] . '</td>';
                                                        echo '<td class="text-center font-weight-bold bg-light" style="padding: 2px;">' . $teacher['summary']['Sakit'] . '</td>';
                                                        echo '<td class="text-center font-weight-bold bg-light" style="padding: 2px;">' . $teacher['summary']['Izin'] . '</td>';
                                                        ?>
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
                    </div>
                </section>
            </div>

            <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Handle filter type change
                $('#filterType').change(function() {
                    var type = $(this).val();
                    $('.daily-filter, .monthly-filter, .teacher-filter').hide();
                    
                    if (type == 'daily') {
                        $('.daily-filter').show();
                    } else if (type == 'monthly') {
                        $('.monthly-filter').show();
                    } else if (type == 'teacher') {
                        $('.teacher-filter').show();
                    }
                });

                // Trigger change on load
                $('#filterType').trigger('change');
                
                // Initialize DataTables
                if ($('#dailyTable').length) {
                    $('#dailyTable').DataTable();
                }
                if ($('#teacherTable').length) {
                    $('#teacherTable').DataTable();
                }
                
                // Excel Export
                $('#exportExcelBtn').click(function() {
                    var filterType = '<?php echo $filter_type; ?>';
                    var wb = XLSX.utils.book_new();
                    var fileName = 'Rekap_Absensi_Guru';
                    var tableId = '';
                    var titleInfo = '';
                    
                    if (filterType === 'daily') {
                        var date = '<?php echo $selected_date; ?>';
                        fileName += '_Harian_' + date;
                        tableId = 'dailyTable';
                        titleInfo = 'Harian - ' + '<?php echo date('d F Y', strtotime($selected_date)); ?>';
                    } else if (filterType === 'monthly') {
                        var month = '<?php echo $js_month_name_file . "_" . $js_month_year_safe; ?>';
                        fileName += '_Bulanan_' + month;
                        tableId = 'monthlyTable';
                        titleInfo = 'Bulanan - <?php echo $js_month_name_safe . " " . $js_month_year_safe; ?>';
                    } else if (filterType === 'semester') {
                        var semester = '<?php echo str_replace(" ", "_", $active_semester); ?>';
                        fileName += '_' + semester;
                        tableId = 'semesterTable';
                        titleInfo = '<?php echo $active_semester; ?>';
                    } else if (filterType === 'teacher') {
                        fileName += '_PerGuru';
                        tableId = 'teacherTable';
                        titleInfo = 'Per Guru';
                    }
                    
                    // Create header info
                    var ws = XLSX.utils.aoa_to_sheet([
                        ['<?php echo $school_profile["nama_madrasah"] ?? "Sistem Absensi Siswa"; ?>'],
                        ['Rekap Absensi Guru - ' + titleInfo],
                        ['Tahun Ajaran: <?php echo $school_profile["tahun_ajaran"] ?? "-"; ?> | Semester: <?php echo $active_semester ?? "-"; ?>'],
                        [''] // Spacer
                    ]);

                    // Append table data starting from row 5 (index 4)
                    var table = document.getElementById(tableId);
                    if(table) {
                        XLSX.utils.sheet_add_dom(ws, table, {origin: "A5"});
                    }
                    
                    XLSX.utils.book_append_sheet(wb, ws, "Rekap Absensi");
                    XLSX.writeFile(wb, fileName + '.xlsx');
                });
                
                // PDF Export using window.print() in new tab
                $('#exportPdfBtn').click(function() {
                    var filterType = '<?php echo $filter_type; ?>';
                    var title = 'Rekap Absensi Guru';
                    var tableId = '';
                    
                    if (filterType === 'daily') {
                        title += ' - Harian (<?php echo date('d F Y', strtotime($selected_date)); ?>)';
                        tableId = '#dailyTable';
                    } else if (filterType === 'monthly') {
                        title += ' - Bulanan (<?php echo $js_month_name_safe . " " . $js_month_year_safe; ?>)';
                        tableId = '#monthlyTable';
                    } else if (filterType === 'semester') {
                        title += ' - <?php echo $active_semester . " (" . ($school_profile["tahun_ajaran"] ?? "") . ")"; ?>';
                        tableId = '#semesterTable';
                    } else if (filterType === 'teacher') {
                        title += ' - Per Guru';
                        tableId = '#teacherTable';
                    }
                    
                    var table = document.querySelector(tableId);
                    if (!table) {
                        alert('Tabel tidak ditemukan!');
                        return;
                    }

                    // Clone table to avoid modifying original
                    var tableClone = table.cloneNode(true);
                    
                    // Create new window
                    var printWindow = window.open('', '_blank');
                    printWindow.document.write('<!DOCTYPE html><html><head><title>' + title + '</title>');
                    printWindow.document.write('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">');
                    printWindow.document.write('<style>');
                    printWindow.document.write('@page { size: landscape; margin: 10mm; }');
                    printWindow.document.write('@media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } .no-print { display: none !important; } }');
                    printWindow.document.write('body { font-family: Arial, sans-serif; margin: 20px; }');
                    printWindow.document.write('.header { text-align: center; margin-bottom: 20px; }');
                    printWindow.document.write('.header h2 { margin: 0; color: #333; }');
                    printWindow.document.write('.header p { margin: 5px 0; color: #666; }');
                    printWindow.document.write('table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 10px; }'); // Smaller font for big tables
                    printWindow.document.write('th, td { border: 1px solid #000; padding: 4px; text-align: center; }');
                    printWindow.document.write('th { background-color: #368DBC !important; color: white !important; font-weight: bold; }');
                    printWindow.document.write('tr:nth-child(even) { background-color: #f2f2f2; }');
                    printWindow.document.write('.print-btn { position: fixed; top: 20px; right: 20px; padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.2); z-index: 9999; }');
                    printWindow.document.write('.print-btn:hover { background: #0056b3; }');
                    printWindow.document.write('</style>');
                    printWindow.document.write('</head><body>');
                    
                    printWindow.document.write('<button class="print-btn no-print" onclick="window.print()"><i class="fas fa-print"></i> Cetak / Simpan PDF</button>');
                    
                    printWindow.document.write('<div class="header">');
                    printWindow.document.write('<h2>' + title + '</h2>');
                    printWindow.document.write('<p><?php echo $school_profile["nama_madrasah"] ?? "Sistem Absensi Siswa"; ?></p>');
                    printWindow.document.write('<p>Tahun Ajaran: <?php echo $school_profile["tahun_ajaran"] ?? "-"; ?> | Semester: <?php echo $active_semester ?? "-"; ?></p>');
                    printWindow.document.write('<p>Dicetak pada: ' + new Date().toLocaleString('id-ID') + '</p>');
                    printWindow.document.write('</div>');
                    
                    printWindow.document.write(tableClone.outerHTML);
                    
                    // Add signature block
                    printWindow.document.write('<div style="margin-top: 30px; display: flex; justify-content: flex-end; width: 100%; page-break-inside: avoid;">');
                    printWindow.document.write('<div style="text-align: center; width: 300px;">');
                    
                    var madrasahHeadName = '<?php echo addslashes($madrasah_head_name); ?>';
                    var madrasahHeadSignature = '<?php echo addslashes($madrasah_head_signature); ?>';
                    var schoolName = '<?php echo addslashes($school_name); ?>';
                    var schoolCity = '<?php echo addslashes($school_profile['tempat_jadwal'] ?? 'Padang'); ?>';
                    var reportDate = '<?php echo formatDateIndonesia(date('Y-m-d')); ?>';
                    
                    printWindow.document.write('<p>' + schoolCity + ', ' + reportDate + '<br>Kepala Madrasah,</p>');
                    
                    if (madrasahHeadSignature) {
                        var qrContent = 'Validasi Tanda Tangan Digital: ' + madrasahHeadName + ' - ' + schoolName;
                        var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' + encodeURIComponent(qrContent);
                        printWindow.document.write('<img src="' + qrUrl + '" alt="QR Signature" style="width: 80px; height: 80px; margin: 10px auto; display: block;">');
                        printWindow.document.write('<p style="font-size: 10px; margin-top: 0;">(Ditandatangani secara digital)</p>');
                    } else {
                        printWindow.document.write('<br><br><br>');
                    }
                    
                    printWindow.document.write('<p><strong>' + madrasahHeadName + '</strong></p>');
                    printWindow.document.write('</div>');
                    printWindow.document.write('</div>');

                    printWindow.document.write('<script>window.onload = function() { window.print(); }<\/script>');
                    printWindow.document.write('</body></html>');
                    printWindow.document.close();
                });
            });
            </script>

<?php include '../templates/footer.php'; ?>
