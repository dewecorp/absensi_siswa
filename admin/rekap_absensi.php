<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha'])) {
    redirect('../login.php');
}

// Set page title
$page_title = 'Rekap Absensi';

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
$class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
$filter_type = $_POST['filter_type'] ?? 'daily';
$selected_date = isset($_POST['attendance_date']) ? $_POST['attendance_date'] : date('Y-m-d');
$selected_month = isset($_POST['month_picker']) ? $_POST['month_picker'] : date('Y-m');
$selected_student = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
$semester_results = [];
$daily_results = [];
$monthly_results = [];
$student_results = [];
$student_attendance_summary = [];

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

// Get all classes
$stmt = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC");
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get class information
$class_info = [];
if ($class_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas = ?");
    $stmt->execute([$class_id]);
    $class_info = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Process search based on filter type
if ($class_id > 0) {
    if ($filter_type == 'daily' && !empty($selected_date)) {
        // Daily filter
        // Get all students in the class
        $stmt = $pdo->prepare("SELECT s.id_siswa, s.nama_siswa, s.nisn, k.nama_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_kelas = ? ORDER BY s.nama_siswa ASC");
        $stmt->execute([$class_id]);
        $all_daily_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get attendance data for the selected date
        $stmt = $pdo->prepare("
            SELECT s.id_siswa, s.nama_siswa, s.nisn, a.keterangan, a.tanggal, k.nama_kelas, a.jam_masuk, a.jam_keluar
            FROM tb_absensi a
            LEFT JOIN tb_siswa s ON a.id_siswa = s.id_siswa  
            LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas
            WHERE s.id_kelas = ? AND a.tanggal = ?
            ORDER BY s.nama_siswa ASC
        ");
        $stmt->execute([$class_id, $selected_date]);
        $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Organize attendance data by student ID
        $attendance_by_student = [];
        foreach ($attendance_records as $record) {
            $attendance_by_student[$record['id_siswa']] = $record;
        }
        
        // Combine all students with their attendance data
        $daily_results = [];
        foreach ($all_daily_students as $student) {
            if (isset($attendance_by_student[$student['id_siswa']])) {
                // Student has attendance data for this date
                $daily_results[] = $attendance_by_student[$student['id_siswa']];
            } else {
                // Student has no attendance data for this date
                $daily_results[] = [
                    'nama_siswa' => $student['nama_siswa'],
                    'nisn' => $student['nisn'],
                    'keterangan' => 'Belum Absen', // Mark as not yet attended
                    'tanggal' => $selected_date,
                    'nama_kelas' => $student['nama_kelas'], // Include class name
                    'jam_masuk' => null,
                    'jam_keluar' => null
                ];
            }
        }
    } elseif ($filter_type == 'monthly' && !empty($selected_month)) {
        // Monthly filter
        $year = substr($selected_month, 0, 4);
        $month = substr($selected_month, 5, 2);
        
        // Get all students in the class
        $stmt = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
        $stmt->execute([$class_id]);
        $all_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get attendance data for the month
        $stmt = $pdo->prepare("
            SELECT s.id_siswa, s.nama_siswa, s.nisn, a.keterangan, DAY(a.tanggal) as day
            FROM tb_absensi a
            LEFT JOIN tb_siswa s ON a.id_siswa = s.id_siswa
            WHERE s.id_kelas = ? AND YEAR(a.tanggal) = ? AND MONTH(a.tanggal) = ?
            ORDER BY s.nama_siswa, a.tanggal
        ");
        $stmt->execute([$class_id, $year, $month]);
        $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Organize attendance data by student ID
        $attendance_by_student = [];
        foreach ($attendance_records as $record) {
            $student_id = $record['id_siswa'];
            if (!isset($attendance_by_student[$student_id])) {
                $attendance_by_student[$student_id] = [
                    'days' => array_fill(1, 31, ''), // Initialize all days as empty
                    'summary' => ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0, 'Berhalangan' => 0]
                ];
            }
            $day = (int)$record['day'];
            $attendance_by_student[$student_id]['days'][$day] = $record['keterangan'];
            if (isset($attendance_by_student[$student_id]['summary'][$record['keterangan']])) {
                $attendance_by_student[$student_id]['summary'][$record['keterangan']]++;
            }
        }
        
        // Combine all students with their attendance data
        $student_attendance = [];
        foreach ($all_students as $student) {
            $student_id = $student['id_siswa'];
            $student_data = [
                'nama_siswa' => $student['nama_siswa'],
                'nisn' => $student['nisn'],
                'days' => array_fill(1, 31, ''), // Initialize all days as empty
                'summary' => ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0]
            ];
            
            // Merge with attendance data if available
            if (isset($attendance_by_student[$student_id])) {
                $student_data['days'] = $attendance_by_student[$student_id]['days'];
                $student_data['summary'] = $attendance_by_student[$student_id]['summary'];
            }
            
            $student_attendance[$student_id] = $student_data;
        }
        
        // Convert to indexed array
        $monthly_results = array_values($student_attendance);
    } elseif ($filter_type == 'student' && $selected_student > 0) {
        // Student filter - get attendance data for specific student
        $stmt = $pdo->prepare("
            SELECT s.nama_siswa, s.nisn, k.nama_kelas, a.keterangan, a.tanggal
            FROM tb_absensi a
            LEFT JOIN tb_siswa s ON a.id_siswa = s.id_siswa
            LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas
            WHERE s.id_siswa = ?
            ORDER BY a.tanggal DESC
        ");
        $stmt->execute([$selected_student]);
        $student_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate summary statistics
        $summary = ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0];
        foreach ($student_results as $record) {
            if (isset($summary[$record['keterangan']])) {
                $summary[$record['keterangan']]++;
            }
        }
        $student_attendance_summary = $summary;
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
        
        // Debug output - you can remove this later
        error_log("Semester filter debug: Semester=$active_semester, Academic Year=$academic_year, Query Year=$query_year, Months=$start_month-$end_month");
        
        // Get all students in the class
        $stmt = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
        $stmt->execute([$class_id]);
        $all_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get attendance data for the semester
        $stmt = $pdo->prepare("
            SELECT s.id_siswa, s.nama_siswa, s.nisn, a.keterangan, a.tanggal,
                   MONTH(a.tanggal) as month, DAY(a.tanggal) as day
            FROM tb_absensi a
            LEFT JOIN tb_siswa s ON a.id_siswa = s.id_siswa
            WHERE s.id_kelas = ? AND YEAR(a.tanggal) = ? 
                  AND MONTH(a.tanggal) BETWEEN ? AND ?
            ORDER BY s.nama_siswa, a.tanggal
        ");
        $stmt->execute([$class_id, $query_year, $start_month, $end_month]);
        $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Debug: Log how many records found
        error_log("Found " . count($attendance_records) . " attendance records for semester filter");
        
        // Organize attendance data by student ID
        $attendance_by_student = [];
        foreach ($attendance_records as $record) {
            $student_id = $record['id_siswa'];
            $month = (int)$record['month'];
            $status = $record['keterangan'];
            
            if (!isset($attendance_by_student[$student_id])) {
                $attendance_by_student[$student_id] = [
                    'monthly_totals' => [], // Initialize monthly totals array
                    'summary' => ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0, 'Berhalangan' => 0]
                ];
                
                // Initialize all months in semester
                for ($m = $start_month; $m <= $end_month; $m++) {
                    $attendance_by_student[$student_id]['monthly_totals'][$m] = [
                        'Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0, 'Berhalangan' => 0
                    ];
                }
            }
            
            // Count attendance status for the month
            if (isset($attendance_by_student[$student_id]['monthly_totals'][$month][$status])) {
                $attendance_by_student[$student_id]['monthly_totals'][$month][$status]++;
                $attendance_by_student[$student_id]['summary'][$status]++;
            }
        }
        
        // Combine all students with their attendance data
        $student_attendance = [];
        foreach ($all_students as $student) {
            $student_id = $student['id_siswa'];
            $student_data = [
                'nama_siswa' => $student['nama_siswa'],
                'nisn' => $student['nisn'],
                'monthly_totals' => [],
                'summary' => ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0]
            ];
            
            // Initialize monthly totals for all months in semester
            for ($m = $start_month; $m <= $end_month; $m++) {
                $student_data['monthly_totals'][$m] = [
                    'Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0
                ];
            }
            
            // Merge with attendance data if available
            if (isset($attendance_by_student[$student_id])) {
                $student_data['monthly_totals'] = $attendance_by_student[$student_id]['monthly_totals'];
                $student_data['summary'] = $attendance_by_student[$student_id]['summary'];
            }
            
            $student_attendance[$student_id] = $student_data;
        }
        
        // Convert to indexed array
        $semester_results = array_values($student_attendance);
        
        // Debug: Log results count
        error_log("Processed " . count($semester_results) . " students for semester results");
    }
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
            #semesterTable thead th:nth-child(2) { /* Nama Siswa column */
                white-space: normal;
                text-align: center;
            }
            #semesterTable tbody td {
                color: black;
                white-space: nowrap;
            }
            #semesterTable tbody td:nth-child(2) { /* Nama Siswa column */
                white-space: normal;
                text-align: left;
            }
            </style>
            
            <!-- Main Content -->
            <div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Rekap Absensi</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item active"><a href="#">Dashboard</a></div>
                            <div class="breadcrumb-item">Rekap Absensi</div>
                        </div>
                    </div>

                    <div class="section-body">
                        <div class="row">
                            <div class="col-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h4>Filter Absensi Harian</h4>
                                    </div>
                                    <div class="card-body">
                                        <form method="POST" class="row" id="attendanceFilterForm">
                                            <div class="form-group col-md-3">
                                                <label>Pilih Kelas</label>
                                                <select name="class_id" class="form-control selectric" id="classSelect" required>
                                                    <option value="">Pilih Kelas...</option>
                                                    <?php foreach ($classes as $class): ?>
                                                        <option value="<?php echo $class['id_kelas']; ?>" <?php echo ($class_id == $class['id_kelas']) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($class['nama_kelas']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="form-group col-md-2">
                                                <label>Jenis Filter</label>
                                                <select name="filter_type" class="form-control selectric" id="filterType">
                                                    <option value="daily" <?php echo ($filter_type == 'daily') ? 'selected' : ''; ?>>Harian</option>
                                                    <option value="monthly" <?php echo ($filter_type == 'monthly') ? 'selected' : ''; ?>>Bulanan</option>
                                                    <option value="semester" <?php echo ($filter_type == 'semester') ? 'selected' : ''; ?>>Per Semester</option>
                                                    <option value="student" <?php echo ($filter_type == 'student') ? 'selected' : ''; ?>>Per Siswa</option>
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
                                            
                                            <div class="form-group col-md-3 student-filter" style="<?php echo ($filter_type == 'student') ? '' : 'display:none;'; ?>">
                                                <label>Pilih Siswa</label>
                                                <select name="student_id" class="form-control selectric" id="studentSelect">
                                                    <option value="">Pilih Siswa...</option>
                                                    <?php 
                                                    if ($class_id > 0) {
                                                        $stmt = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
                                                        $stmt->execute([$class_id]);
                                                        $class_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                                        foreach ($class_students as $student):
                                                    ?>
                                                        <option value="<?php echo $student['id_siswa']; ?>" <?php echo ($selected_student == $student['id_siswa']) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($student['nama_siswa'] . ' (' . $student['nisn'] . ')'); ?>
                                                        </option>
                                                    <?php 
                                                        endforeach;
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                            
                                            <div class="form-group col-md-2 d-flex align-items-end">
                                                <button type="submit" class="btn btn-primary btn-block">
                                                    <i class="fas fa-search"></i> Cari
                                                </button>
                                            </div>
                                        </form>
                                        
                                        <?php if (!empty($daily_results)): ?>
                                            <!-- Daily Results -->
                                            <div class="mt-4">
                                                <div class="alert alert-success alert-has-icon">
                                                    <div class="alert-icon"><i class="far fa-check-circle"></i></div>
                                                    <div class="alert-body">
                                                        <div class="alert-title">Berhasil</div>
                                                        Ditemukan <?php echo count($daily_results); ?> data absensi untuk tanggal yang dipilih.
                                                    </div>
                                                </div>
                                                
                                                <div class="table-responsive">
                                                    <table class="table table-striped table-md">
                                                        <thead>
                                                            <tr>
                                                                <th>No</th>
                                                                <th>Nama Siswa</th>
                                                                <th>NISN</th>
                                                                <th>Kelas</th>
                                                                <th>Status</th>
                                                                <th>Waktu Masuk</th>
                                                                <th>Tanggal</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php $no = 1; foreach ($daily_results as $record): ?>
                                                                <tr>
                                                                    <td><?php echo $no++; ?></td>
                                                                    <td><?php echo htmlspecialchars($record['nama_siswa']); ?></td>
                                                                    <td><?php echo htmlspecialchars($record['nisn']); ?></td>
                                                                    <td><?php echo htmlspecialchars($record['nama_kelas']); ?></td>
                                                                    <td>
                                                                        <?php 
                                                                        $status_class = '';
                                                                        $status_text = $record['keterangan'] ?? 'Belum Absen';
                                                                        switch ($status_text) {
                                                                            case 'Hadir': $status_class = 'badge-success'; break;
                                                                            case 'Sakit': $status_class = 'badge-warning'; break;
                                                                            case 'Izin': $status_class = 'badge-info'; break;
                                                                            case 'Alpa': $status_class = 'badge-danger'; break;
                                                                            case 'Berhalangan': $status_class = 'badge-danger'; break;
                                                                            default: $status_class = 'badge-secondary'; break;
                                                                        }
                                                                        ?>
                                                                        <div class="badge <?php echo $status_class; ?>">
                                                                            <?php echo $status_text; ?>
                                                                        </div>
                                                                    </td>
                                                                    <td><?php echo isset($record['jam_masuk']) && $record['jam_masuk'] ? date('H:i:s', strtotime($record['jam_masuk'])) : '-'; ?></td>
                                                                    <td><?php echo $record['tanggal'] ? date('d M Y', strtotime($record['tanggal'])) : '-'; ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        <?php elseif (!empty($semester_results)): ?>
                                            <!-- Semester Results -->
                                            <div class="mt-4">
                                                <div class="alert alert-success alert-has-icon">
                                                    <div class="alert-icon"><i class="far fa-check-circle"></i></div>
                                                    <div class="alert-body">
                                                        <div class="alert-title">Rekap Per Semester</div>
                                                        Menampilkan rekap absensi <?php echo $active_semester; ?> Tahun Ajaran <?php echo $school_profile['tahun_ajaran'] ?? (date('Y') . '/' . (date('Y') + 1)); ?> untuk <?php echo count($semester_results); ?> siswa.
                                                    </div>
                                                </div>
                                                
                                                <!-- Export Buttons -->
                                                <div class="row mb-3">
                                                    <div class="col-md-12">
                                                        <div class="btn-group float-right" role="group">
                                                            <button type="button" class="btn btn-success" onclick="exportSemesterToExcel()">
                                                                <i class="fas fa-file-excel"></i> Ekspor Excel
                                                            </button>
                                                            <button type="button" class="btn btn-warning" onclick="exportSemesterToPDF()">
                                                                <i class="fas fa-file-pdf"></i> Ekspor PDF
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div style="overflow-x: auto; width: 100%;">
                                                    <table class="table table-bordered" id="semesterTable" style="min-width: 1400px; font-size: 10pt; color: black;">
                                                        <thead>
                                                            <tr>
                                                                <th rowspan="2">No</th>
                                                                <th rowspan="2">Nama Siswa</th>
                                                                <?php 
                                                                // Generate month headers based on active semester
                                                                $month_names = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                                                                $start_month = ($active_semester == 'Semester 1') ? 7 : 1;
                                                                $end_month = ($active_semester == 'Semester 1') ? 12 : 6;
                                                                
                                                                for ($m = $start_month; $m <= $end_month; $m++):
                                                                ?>
                                                                    <th colspan="5" class="text-center"><?php echo $month_names[$m]; ?></th>
                                                                <?php endfor; ?>
                                                                <th colspan="5" class="text-center">Total Semester</th>
                                                            </tr>
                                                            <tr>
                                                                <?php 
                                                                // Sub-headers for each month: Hadir, Sakit, Izin, Alpa
                                                                $total_months = ($end_month - $start_month) + 1;
                                                                for ($i = 0; $i < $total_months + 1; $i++): // +1 for semester total
                                                                ?>
                                                                    <th>H</th>
                                                                    <th>S</th>
                                                                    <th>I</th>
                                                                    <th>A</th>
                                                                    <th>B</th>
                                                                <?php endfor; ?>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($semester_results as $index => $student): ?>
                                                                <tr>
                                                                    <td><?php echo $index + 1; ?></td>
                                                                    <td><?php echo htmlspecialchars($student['nama_siswa']); ?></td>
                                                                    
                                                                    <?php 
                                                                    // Display monthly totals
                                                                    for ($m = $start_month; $m <= $end_month; $m++):
                                                                        $hadir = $student['monthly_totals'][$m]['Hadir'] ?? 0;
                                                                        $sakit = $student['monthly_totals'][$m]['Sakit'] ?? 0;
                                                                        $izin = $student['monthly_totals'][$m]['Izin'] ?? 0;
                                                        $alpa = $student['monthly_totals'][$m]['Alpa'] ?? 0;
                                                        $berhalangan = $student['monthly_totals'][$m]['Berhalangan'] ?? 0;
                                                        
                                                        echo '<td class="text-center" style="font-size: 10pt;">' . ($hadir > 0 ? $hadir : '-') . '</td>';
                                                        echo '<td class="text-center" style="font-size: 10pt;">' . ($sakit > 0 ? $sakit : '-') . '</td>';
                                                        echo '<td class="text-center" style="font-size: 10pt;">' . ($izin > 0 ? $izin : '-') . '</td>';
                                                        echo '<td class="text-center" style="font-size: 10pt;">' . ($alpa > 0 ? $alpa : '-') . '</td>';
                                                        echo '<td class="text-center" style="font-size: 10pt;">' . ($berhalangan > 0 ? $berhalangan : '-') . '</td>';
                                                    endfor;
                                                    
                                                    // Display semester totals
                                                    echo '<td class="text-center" style="font-size: 10pt;">' . $student['summary']['Hadir'] . '</td>';
                                                    echo '<td class="text-center" style="font-size: 10pt;">' . $student['summary']['Sakit'] . '</td>';
                                                    echo '<td class="text-center" style="font-size: 10pt;">' . $student['summary']['Izin'] . '</td>';
                                                    echo '<td class="text-center" style="font-size: 10pt;">' . $student['summary']['Alpa'] . '</td>';
                                                    echo '<td class="text-center" style="font-size: 10pt;">' . $student['summary']['Berhalangan'] . '</td>';
                                                    ?>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        <?php elseif (!empty($monthly_results)): ?>
                                            <!-- Monthly Results -->
                                            <div class="mt-4">
                                                <div class="alert alert-success alert-has-icon">
                                                    <div class="alert-icon"><i class="far fa-check-circle"></i></div>
                                                    <div class="alert-body">
                                                        <div class="alert-title">Rekap Bulanan</div>
                                                        Menampilkan rekap absensi bulanan untuk <?php echo count($monthly_results); ?> siswa.
                                                    </div>
                                                </div>
                                                
                                                <!-- Export Buttons -->
                                                <div class="row mb-3">
                                                    <div class="col-md-12">
                                                        <div class="btn-group float-right" role="group">
                                                            <button type="button" class="btn btn-success" onclick="exportToExcel()">
                                                                <i class="fas fa-file-excel"></i> Ekspor Excel
                                                            </button>
                                                            <button type="button" class="btn btn-warning" onclick="exportToPDF()">
                                                                <i class="fas fa-file-pdf"></i> Ekspor PDF
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="table-responsive">
                                                    <table class="table table-bordered table-md">
                                                        <thead>
                                                            <tr>
                                                                <th rowspan="2">No</th>
                                                                <th rowspan="2">Nama Siswa</th>
                                                                <th colspan="31" class="text-center">
                                                                    <?php 
                                                                    $month_names = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                                                                    $month_num = (int)substr($selected_month, 5, 2);
                                                                    echo $month_names[$month_num] . ' ' . substr($selected_month, 0, 4);
                                                                    ?>
                                                                </th>
                                                                <th colspan="5" class="text-center">Total</th>
                                                            </tr>
                                                            <tr>
                                                                <?php for ($day = 1; $day <= 31; $day++): ?>
                                                                    <th><?php echo $day; ?></th>
                                                                <?php endfor; ?>
                                                                <th>Hadir</th>
                                                                <th>Sakit</th>
                                                                <th>Izin</th>
                                                                <th>Alpa</th>
                                                                <th>Berhalangan</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($monthly_results as $index => $student): ?>
                                                                <tr>
                                                                    <td><?php echo $index + 1; ?></td>
                                                                    <td><?php echo htmlspecialchars($student['nama_siswa']); ?></td>
                                                                    <?php for ($day = 1; $day <= 31; $day++): ?>
                                                                        <td>
                                                                            <?php 
                                                                            $status = $student['days'][$day] ?? '';
                                                                            if (!empty($status)) {
                                                                                $status_class = '';
                                                                                switch ($status) {
                                                                                    case 'Hadir': $status_class = 'badge-success'; break;
                                                                                    case 'Sakit': $status_class = 'badge-warning'; break;
                                                                                    case 'Izin': $status_class = 'badge-info'; break;
                                                                                    case 'Alpa': $status_class = 'badge-danger'; break;
                                                                                    case 'Berhalangan': $status_class = 'badge-danger'; break;
                                                                                    default: $status_class = 'badge-secondary'; break;
                                                                                }
                                                                                echo '<span style="font-size: 10pt;">' . substr($status, 0, 1) . '</span>';
                                                                            }
                                                                            ?>
                                                                        </td>
                                                                    <?php endfor; ?>
                                                                    <td class="text-center" style="font-size: 10pt;"><span style="font-size: 10pt;"><?php echo $student['summary']['Hadir']; ?></span></td>
                                                                    <td class="text-center" style="font-size: 10pt;"><span style="font-size: 10pt;"><?php echo $student['summary']['Sakit']; ?></span></td>
                                                                    <td class="text-center" style="font-size: 10pt;"><span style="font-size: 10pt;"><?php echo $student['summary']['Izin']; ?></span></td>
                                                                    <td class="text-center" style="font-size: 10pt;"><span style="font-size: 10pt;"><?php echo $student['summary']['Alpa']; ?></span></td>
                                                                    <td class="text-center" style="font-size: 10pt;"><span style="font-size: 10pt;"><?php echo $student['summary']['Berhalangan']; ?></span></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        <?php elseif (!empty($student_results)): ?>
                                            <!-- Student Results -->
                                            <div class="mt-4">
                                                <div class="alert alert-success alert-has-icon">
                                                    <div class="alert-icon"><i class="far fa-check-circle"></i></div>
                                                    <div class="alert-body">
                                                        <div class="alert-title">Data Absensi Siswa</div>
                                                        Menampilkan riwayat absensi untuk <?php echo htmlspecialchars($student_results[0]['nama_siswa'] ?? ''); ?>
                                                    </div>
                                                </div>
                                                
                                                <!-- Student Summary Cards -->
                                                <div class="row mb-4">
                                                    <div class="col-md-3">
                                                        <div class="card card-statistic-1">
                                                            <div class="card-icon bg-success">
                                                                <i class="fas fa-check"></i>
                                                            </div>
                                                            <div class="card-wrap">
                                                                <div class="card-header">
                                                                    <h4>Total Hadir</h4>
                                                                </div>
                                                                <div class="card-body">
                                                                    <?php echo $student_attendance_summary['Hadir'] ?? 0; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="card card-statistic-1">
                                                            <div class="card-icon bg-warning">
                                                                <i class="fas fa-medkit"></i>
                                                            </div>
                                                            <div class="card-wrap">
                                                                <div class="card-header">
                                                                    <h4>Total Sakit</h4>
                                                                </div>
                                                                <div class="card-body">
                                                                    <?php echo $student_attendance_summary['Sakit'] ?? 0; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="card card-statistic-1">
                                                            <div class="card-icon bg-info">
                                                                <i class="fas fa-file-alt"></i>
                                                            </div>
                                                            <div class="card-wrap">
                                                                <div class="card-header">
                                                                    <h4>Total Izin</h4>
                                                                </div>
                                                                <div class="card-body">
                                                                    <?php echo $student_attendance_summary['Izin'] ?? 0; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="card card-statistic-1">
                                                            <div class="card-icon bg-danger">
                                                                <i class="fas fa-times"></i>
                                                            </div>
                                                            <div class="card-wrap">
                                                                <div class="card-header">
                                                                    <h4>Total Alpa</h4>
                                                                </div>
                                                                <div class="card-body">
                                                                    <?php echo $student_attendance_summary['Alpa'] ?? 0; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="card card-statistic-1">
                                                            <div class="card-icon bg-danger">
                                                                <i class="fas fa-ban"></i>
                                                            </div>
                                                            <div class="card-wrap">
                                                                <div class="card-header">
                                                                    <h4>Total Berhalangan</h4>
                                                                </div>
                                                                <div class="card-body">
                                                                    <?php echo $student_attendance_summary['Berhalangan'] ?? 0; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <!-- Detailed Attendance Table -->
                                                <div class="table-responsive">
                                                    <table class="table table-striped table-md" id="studentTable">
                                                        <thead>
                                                            <tr>
                                                                <th>No</th>
                                                                <th>Tanggal</th>
                                                                <th>Status Kehadiran</th>
                                                                <th>Catatan</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($student_results as $index => $record): ?>
                                                                <tr>
                                                                    <td><?php echo $index + 1; ?></td>
                                                                    <td><?php echo $record['tanggal'] ? date('d M Y', strtotime($record['tanggal'])) : '-'; ?></td>
                                                                    <td>
                                                                        <?php 
                                                                        $status_class = '';
                                                                        $status_text = $record['keterangan'] ?? 'Belum Absen';
                                                                        switch ($status_text) {
                                                                            case 'Hadir': $status_class = 'badge-success'; break;
                                                                            case 'Sakit': $status_class = 'badge-warning'; break;
                                                                            case 'Izin': $status_class = 'badge-info'; break;
                                                                            case 'Alpa': $status_class = 'badge-danger'; break;
                                                                            case 'Berhalangan': $status_class = 'badge-danger'; break;
                                                                            default: $status_class = 'badge-secondary'; break;
                                                                        }
                                                                        ?>
                                                                        <div class="badge <?php echo $status_class; ?> px-3 py-2">
                                                                            <?php echo $status_text; ?>
                                                                        </div>
                                                                    </td>
                                                                    <td>-</td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        <?php elseif (($filter_type == 'daily' && $class_id > 0 && !empty($selected_date)) || ($filter_type == 'monthly' && $class_id > 0 && !empty($selected_month)) || ($filter_type == 'student' && $class_id > 0 && $selected_student > 0)): ?>
                                            <div class="mt-4">
                                                <div class="alert alert-info alert-has-icon">
                                                    <div class="alert-icon"><i class="fas fa-info-circle"></i></div>
                                                    <div class="alert-body">
                                                        <div class="alert-title">Informasi</div>
                                                        Tidak ada data absensi ditemukan untuk kelas dan <?php 
                                                            if ($filter_type == 'daily') echo 'tanggal';
                                                            elseif ($filter_type == 'monthly') echo 'bulan';
                                                            else echo 'siswa';
                                                        ?> yang dipilih.
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

<?php include '../templates/footer.php'; ?>

<!-- Export Functions from absensi_harian.php -->
<?php 
// Prepare data for JavaScript
$madrasah_head = addslashes(htmlspecialchars($school_profile['kepala_madrasah'] ?? 'Kepala Madrasah', ENT_QUOTES, 'UTF-8'));
$class_teacher = addslashes(htmlspecialchars($class_info['wali_kelas'] ?? 'Wali Kelas', ENT_QUOTES, 'UTF-8'));
?>
<script>
// Pass actual names to JavaScript
var madrasahHeadName = '<?php echo $madrasah_head; ?>';
var classTeacherName = '<?php echo $class_teacher; ?>';

function exportToExcel() {
    // Create a container for the full report
    var container = document.createElement('div');
    
    // Add application name and school info
    var headerDiv = document.createElement('div');
    headerDiv.innerHTML = '<img src="../assets/img/logo_1768301957.png" alt="Logo" style="max-width: 100px; float: left; margin-right: 20px;"><div style="display: inline-block;"><h2>Sistem Absensi Siswa</h2>';
    headerDiv.innerHTML += '<h3><?php echo htmlspecialchars($school_profile["nama_madrasah"] ?? "Madrasah Ibtidaiyah Negeri Pembina Kota Padang", ENT_QUOTES, "UTF-8"); ?></h3>';
    headerDiv.innerHTML += '<h4>Rekap Absensi Bulanan - <?php echo htmlspecialchars($js_month_name_safe . " " . $js_month_year_safe, ENT_QUOTES, "UTF-8"); ?></h4></div><br style="clear: both;">';
    
    // Create a copy of the table to modify
    var table = document.querySelector('.table-bordered');
    if (!table) {
        alert('Tabel tidak ditemukan');
        return;
    }
    var newTable = table.cloneNode(true);
    
    // Remove badges and keep only text for cleaner Excel output
    var badges = newTable.querySelectorAll('.badge');
    for (var i = 0; i < badges.length; i++) {
        var badge = badges[i];
        var textNode = document.createTextNode(badge.textContent);
        badge.parentNode.replaceChild(textNode, badge);
    }
    
    // Append header and table to container
    container.appendChild(headerDiv);
    container.appendChild(newTable);
    
    var html = container.innerHTML;
    
    // Check if SheetJS (xlsx) library is available
    if (typeof XLSX !== 'undefined') {
        // Convert table to worksheet using SheetJS
        var wb = XLSX.utils.book_new();
        var ws = XLSX.utils.table_to_sheet(newTable);
        XLSX.utils.book_append_sheet(wb, ws, "Rekap Absensi");
        XLSX.writeFile(wb, 'rekap_absensi_bulanan_' + '<?php echo htmlspecialchars($js_month_name_file, ENT_QUOTES, "UTF-8"); ?>' + '_' + '<?php echo htmlspecialchars($js_month_year_safe, ENT_QUOTES, "UTF-8"); ?>' + '.xlsx');
    } else {
        // Fallback to HTML-based Excel export
        var a = document.createElement('a');
        var data = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
        a.href = data;
        a.download = 'rekap_absensi_bulanan_' + '<?php echo htmlspecialchars($js_month_name_file, ENT_QUOTES, "UTF-8"); ?>' + '_' + '<?php echo htmlspecialchars($js_month_year_safe, ENT_QUOTES, "UTF-8"); ?>' + '.xls';
        a.click();
    }
}

function exportToPDF() {
    fallbackPrintPDF();
}

function fallbackPrintPDF() {
    // Print the table as PDF with F4 landscape format
    var printWindow = window.open('', '_blank'); // Open in new tab
    printWindow.document.write('<html><head><title>Rekap Absensi Bulanan</title>');
    printWindow.document.write('<style>');
    printWindow.document.write('@page { size: legal landscape; margin: 0.5cm; }'); // Landscape orientation
    printWindow.document.write('@media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } .no-print { display: none !important; } }');
    printWindow.document.write('body { font-family: Arial, sans-serif; margin: 0; padding: 10px; }');
    printWindow.document.write('table { border-collapse: collapse; width: 100%; font-size: 11px; margin-bottom: 20px; }');
    printWindow.document.write('tr { page-break-inside: avoid; page-break-after: auto; }');
    printWindow.document.write('th, td { border: 1px solid #000; padding: 4px; text-align: center; }');
    printWindow.document.write('td:nth-child(2) { text-align: left; white-space: nowrap; }'); // Nama Siswa Left Align
    printWindow.document.write('th { background-color: #f2f2f2; font-weight: bold; }');
    printWindow.document.write('.badge { padding: 2px 4px; border-radius: 3px; font-size: 10px; border: 1px solid #000; }');
    printWindow.document.write('.badge-success { background-color: #28a745; color: white; }');
    printWindow.document.write('.badge-warning { background-color: #ffc107; color: black; }');
    printWindow.document.write('.badge-info { background-color: #17a2b8; color: white; }');
    printWindow.document.write('.badge-danger { background-color: #dc3545; color: white; }');
    printWindow.document.write('.header { text-align: center; margin-bottom: 15px; }');
    printWindow.document.write('.logo { max-width: 80px; float: left; margin-right: 15px; }');
    printWindow.document.write('h2, h3, h4 { margin: 5px 0; }');
    printWindow.document.write('.signature-wrapper { margin-top: 30px; display: flex; justify-content: space-between; width: 100%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.signature-box { text-align: center; width: 45%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.print-btn { position: fixed; top: 20px; right: 20px; padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; z-index: 9999; }');
    printWindow.document.write('.print-btn:hover { background: #0056b3; }');
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write('<button class="print-btn no-print" onclick="window.print()"><i class="fas fa-print"></i> Cetak / Simpan PDF</button>');
    printWindow.document.write('<div class="header">');
    printWindow.document.write('<img src="../assets/img/logo_1768301957.png" alt="Logo" class="logo">');
    printWindow.document.write('<div style="display: inline-block;"><h2>Sistem Absensi Siswa</h2>');
    printWindow.document.write('<h3><?php echo htmlspecialchars($school_profile["nama_madrasah"] ?? "Madrasah Ibtidaiyah Negeri Pembina Kota Padang", ENT_QUOTES, "UTF-8"); ?></h3>');
    printWindow.document.write('<h4>Rekap Absensi Bulanan - <?php echo htmlspecialchars($js_month_name_safe . " " . $js_month_year_safe, ENT_QUOTES, "UTF-8"); ?></h4></div><br style="clear: both;">');
    
    // Get the table
    var table = document.querySelector('.table-bordered');
    if (table) {
        printWindow.document.write(table.outerHTML);
    }
    
    // Add signatures below the table
    printWindow.document.write('<div class="signature-wrapper">');
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p>Wali Kelas,</p>');
    printWindow.document.write('<br><br><br>');
    printWindow.document.write('<p><strong>' + classTeacherName + '</strong></p>');
    printWindow.document.write('</div>');
    
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p>Kepala Madrasah,</p>');
    printWindow.document.write('<br><br><br>');
    printWindow.document.write('<p><strong>' + madrasahHeadName + '</strong></p>');
    printWindow.document.write('</div>');
    printWindow.document.write('</div>');
    
    printWindow.document.write('</div>');
    printWindow.document.write('<script>window.onload = function() { window.print(); }<\/script>');
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.focus();
}

function fallbackSemesterPrintPDF() {
    // Print the semester table as PDF with F4 landscape format
    var printWindow = window.open('', '_blank'); // Open in new tab
    printWindow.document.write('<html><head><title>Rekap Absensi Semester</title>');
    printWindow.document.write('<style>');
    printWindow.document.write('@page { size: legal landscape; margin: 0.5cm; }'); // Landscape orientation
    printWindow.document.write('@media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } .no-print { display: none !important; } }');
    printWindow.document.write('body { font-family: Arial, sans-serif; margin: 0; padding: 10px; }');
    printWindow.document.write('table { border-collapse: collapse; width: 100%; font-size: 11px; margin-bottom: 10px; }');
    printWindow.document.write('tr { page-break-inside: avoid; page-break-after: auto; }');
    printWindow.document.write('th, td { border: 1px solid #000; padding: 4px; text-align: center; }');
    printWindow.document.write('td:nth-child(2) { text-align: left; white-space: nowrap; }'); // Nama Siswa Left Align
    printWindow.document.write('th { background-color: #f2f2f2; font-weight: bold; }');
    printWindow.document.write('.badge { padding: 2px 4px; border-radius: 3px; font-size: 10px; border: 1px solid #000; }');
    printWindow.document.write('.badge-success { background-color: #28a745; color: white; }');
    printWindow.document.write('.badge-warning { background-color: #ffc107; color: black; }');
    printWindow.document.write('.badge-info { background-color: #17a2b8; color: white; }');
    printWindow.document.write('.badge-danger { background-color: #dc3545; color: white; }');
    printWindow.document.write('.header { text-align: center; margin-bottom: 15px; }');
    printWindow.document.write('.logo { max-width: 80px; float: left; margin-right: 15px; }');
    printWindow.document.write('h2, h3, h4 { margin: 5px 0; }');
    printWindow.document.write('.signature-wrapper { margin-top: 10px; display: flex; justify-content: space-between; width: 100%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.signature-box { text-align: center; width: 45%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.print-btn { position: fixed; top: 20px; right: 20px; padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; z-index: 9999; }');
    printWindow.document.write('.print-btn:hover { background: #0056b3; }');
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write('<button class="print-btn no-print" onclick="window.print()"><i class="fas fa-print"></i> Cetak / Simpan PDF</button>');
    printWindow.document.write('<div class="header">');
    printWindow.document.write('<img src="../assets/img/logo_1768301957.png" alt="Logo" class="logo">');
    printWindow.document.write('<div style="display: inline-block;"><h2>Sistem Absensi Siswa</h2>');
    printWindow.document.write('<h3><?php echo htmlspecialchars($school_profile["nama_madrasah"] ?? "Madrasah Ibtidaiyah Negeri Pembina Kota Padang", ENT_QUOTES, "UTF-8"); ?></h3>');
    printWindow.document.write('<h4>Rekap Absensi <?php echo htmlspecialchars($active_semester, ENT_QUOTES, "UTF-8"); ?> - Tahun <?php echo htmlspecialchars(date("Y"), ENT_QUOTES, "UTF-8"); ?></h4></div><br style="clear: both;">');
    
    // Get the semester table
    var table = document.getElementById('semesterTable');
    if (table) {
        printWindow.document.write(table.outerHTML);
    }
    
    // Add signatures below the table
    printWindow.document.write('<div class="signature-wrapper">');
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p>Wali Kelas,</p>');
    printWindow.document.write('<br><br><br>');
    printWindow.document.write('<p><strong>' + classTeacherName + '</strong></p>');
    printWindow.document.write('</div>');
    
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p>Kepala Madrasah,</p>');
    printWindow.document.write('<br><br><br>');
    printWindow.document.write('<p><strong>' + madrasahHeadName + '</strong></p>');
    printWindow.document.write('</div>');
    printWindow.document.write('</div>');
    
    printWindow.document.write('</div>');
    printWindow.document.write('<script>window.onload = function() { window.print(); }<\/script>');
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.focus();
}

// Semester Export Functions
function exportSemesterToExcel() {
    // Create a container for the semester report
    var container = document.createElement('div');
    
    // Add application name and school info
    var headerDiv = document.createElement('div');
    headerDiv.innerHTML = '<img src="../assets/img/logo_1768301957.png" alt="Logo" style="max-width: 100px; float: left; margin-right: 20px;"><div style="display: inline-block;"><h2>Sistem Absensi Siswa</h2>';
    headerDiv.innerHTML += '<h3><?php echo htmlspecialchars($school_profile["nama_madrasah"] ?? "Madrasah Ibtidaiyah Negeri Pembina Kota Padang", ENT_QUOTES, "UTF-8"); ?></h3>';
    headerDiv.innerHTML += '<h4>Rekap Absensi <?php echo htmlspecialchars($active_semester, ENT_QUOTES, "UTF-8"); ?> - Tahun <?php echo htmlspecialchars(date("Y"), ENT_QUOTES, "UTF-8"); ?></h4></div><br style="clear: both;">';
    
    // Create a copy of the semester table to modify
    var table = document.getElementById('semesterTable');
    if (!table) {
        alert('Tabel semester tidak ditemukan');
        return;
    }
    var newTable = table.cloneNode(true);
    
    // Remove badges and keep only text for cleaner Excel output
    var badges = newTable.querySelectorAll('.badge');
    for (var i = 0; i < badges.length; i++) {
        var badge = badges[i];
        var textNode = document.createTextNode(badge.textContent);
        badge.parentNode.replaceChild(textNode, badge);
    }
    
    // Append header and table to container
    container.appendChild(headerDiv);
    container.appendChild(newTable);
    
    var html = container.innerHTML;
    
    // Check if SheetJS (xlsx) library is available
    if (typeof XLSX !== 'undefined') {
        // Convert table to worksheet using SheetJS
        var wb = XLSX.utils.book_new();
        var ws = XLSX.utils.table_to_sheet(newTable);
        XLSX.utils.book_append_sheet(wb, ws, "Rekap Semester");
        XLSX.writeFile(wb, 'rekap_absensi_' + '<?php echo htmlspecialchars(str_replace(" ", "_", strtolower($active_semester)), ENT_QUOTES, "UTF-8"); ?>' + '_' + '<?php echo htmlspecialchars(date("Y"), ENT_QUOTES, "UTF-8"); ?>' + '.xlsx');
    } else {
        // Fallback to HTML-based Excel export
        var a = document.createElement('a');
        var data = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
        a.href = data;
        a.download = 'rekap_absensi_' + '<?php echo htmlspecialchars(str_replace(" ", "_", strtolower($active_semester)), ENT_QUOTES, "UTF-8"); ?>' + '_' + '<?php echo htmlspecialchars(date("Y"), ENT_QUOTES, "UTF-8"); ?>' + '.xls';
        a.click();
    }
}

function exportSemesterToPDF() {
    fallbackSemesterPrintPDF();
}

function fallbackSemesterPrintPDF() {
    // Print the semester table as PDF with F4 landscape format
    var printWindow = window.open('', '_blank');
    printWindow.document.write('<html><head><title>Rekap Absensi Semester</title>');
    printWindow.document.write('<style>');
    printWindow.document.write('@page { size: legal landscape; margin: 0.5cm; }'); // Landscape orientation
    printWindow.document.write('@media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } .no-print { display: none !important; } }');
    printWindow.document.write('body { font-family: Arial, sans-serif; margin: 0; padding: 10px; }');
    printWindow.document.write('table { border-collapse: collapse; width: 100%; font-size: 11px; margin-bottom: 10px; }');
    printWindow.document.write('tr { page-break-inside: avoid; page-break-after: auto; }');
    printWindow.document.write('th, td { border: 1px solid #ddd; padding: 4px; text-align: center; }');
    printWindow.document.write('td:nth-child(2) { text-align: left; white-space: nowrap; }'); // Nama Siswa Left Align
    printWindow.document.write('th { background-color: #f2f2f2; font-weight: bold; }');
    printWindow.document.write('.badge { padding: 2px 4px; border-radius: 3px; font-size: 10px; }');
    printWindow.document.write('.badge-success { background-color: #28a745; color: white; }');
    printWindow.document.write('.badge-warning { background-color: #ffc107; color: black; }');
    printWindow.document.write('.badge-info { background-color: #17a2b8; color: white; }');
    printWindow.document.write('.badge-danger { background-color: #dc3545; color: white; }');
    printWindow.document.write('.header { text-align: center; margin-bottom: 15px; }');
    printWindow.document.write('.logo { max-width: 80px; float: left; margin-right: 15px; }');
    printWindow.document.write('h2, h3, h4 { margin: 5px 0; }');
    printWindow.document.write('.signature-wrapper { margin-top: 10px; display: flex; justify-content: space-between; width: 100%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.signature-box { text-align: center; width: 45%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.print-btn { position: fixed; top: 20px; right: 20px; padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; z-index: 9999; }');
    printWindow.document.write('.print-btn:hover { background: #0056b3; }');
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write('<button class="print-btn no-print" onclick="window.print()"><i class="fas fa-print"></i> Cetak / Simpan PDF</button>');
    printWindow.document.write('<div class="header">');
    printWindow.document.write('<img src="../assets/img/logo_1768301957.png" alt="Logo" class="logo">');
    printWindow.document.write('<div style="display: inline-block;"><h2>Sistem Absensi Siswa</h2>');
    printWindow.document.write('<h3><?php echo htmlspecialchars($school_profile["nama_madrasah"] ?? "Madrasah Ibtidaiyah Negeri Pembina Kota Padang", ENT_QUOTES, "UTF-8"); ?></h3>');
    printWindow.document.write('<h4>Rekap Absensi <?php echo htmlspecialchars($active_semester, ENT_QUOTES, "UTF-8"); ?> - Tahun <?php echo htmlspecialchars(date("Y"), ENT_QUOTES, "UTF-8"); ?></h4></div><br style="clear: both;">');
    
    // Get the semester table
    var table = document.getElementById('semesterTable');
    if (table) {
        printWindow.document.write(table.outerHTML);
    }
    
    // Add signatures below the table
    printWindow.document.write('<div class="signature-wrapper">');
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p>Wali Kelas,</p>');
    printWindow.document.write('<br><br><br>');
    printWindow.document.write('<p><strong>' + classTeacherName + '</strong></p>');
    printWindow.document.write('</div>');
    
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p>Kepala Madrasah,</p>');
    printWindow.document.write('<br><br><br>');
    printWindow.document.write('<p><strong>' + madrasahHeadName + '</strong></p>');
    printWindow.document.write('</div>');
    printWindow.document.write('</div>');
    
    printWindow.document.write('</div>');
    printWindow.document.write('<script>window.onload = function() { window.print(); }<\/script>');
    printWindow.document.write('</body></html>');
    printWindow.document.close();
}

// Validate form submission - use event delegation on document level
// This ensures it works even if form is loaded dynamically
console.log('Setting up form validation handler...');
$(document).on('submit', '#attendanceFilterForm', function(e) {
    console.log('=== Form submit triggered - validation running ===');
    
    var filterType = $('#filterType').val();
    var classId = $('#classSelect').val();
    var datePicker = $('#datePicker').val();
    
    console.log('Filter Type:', filterType);
    console.log('Class ID:', classId);
    console.log('Date Picker:', datePicker);
    
    // Check if SweetAlert is available
    if (typeof Swal === 'undefined') {
        console.error('SweetAlert is not loaded!');
        alert('Untuk rekap harian, silakan pilih tanggal terlebih dahulu sebelum mencari!');
        e.preventDefault();
        e.stopPropagation();
        return false;
    }
    
    // Check if class is selected
    if (!classId || classId === '') {
        console.log('Validation failed: Class not selected');
        e.preventDefault();
        e.stopPropagation();
        Swal.fire({
            title: 'Peringatan!',
            text: 'Silakan pilih kelas terlebih dahulu!',
            icon: 'warning',
            confirmButtonText: 'OK'
        });
        return false;
    }
    
    // Check if date is selected for daily filter
    if (filterType === 'daily') {
        console.log('Checking date for daily filter...');
        if (!datePicker || datePicker === '' || datePicker === null) {
            console.log('Validation failed: Date not selected for daily filter');
            e.preventDefault();
            e.stopPropagation();
            Swal.fire({
                title: 'Peringatan!',
                text: 'Untuk rekap harian, silakan pilih tanggal terlebih dahulu sebelum mencari!',
                icon: 'warning',
                confirmButtonText: 'OK'
            }).then(function() {
                // Focus on date picker after alert is closed
                $('#datePicker').focus();
            });
            return false;
        }
    }
    
    // Check if month is selected for monthly filter
    if (filterType === 'monthly') {
        var monthPicker = $('#monthPicker').val();
        if (!monthPicker || monthPicker === '' || monthPicker === null) {
            console.log('Validation failed: Month not selected for monthly filter');
            e.preventDefault();
            e.stopPropagation();
            Swal.fire({
                title: 'Peringatan!',
                text: 'Untuk rekap bulanan, silakan pilih bulan terlebih dahulu sebelum mencari!',
                icon: 'warning',
                confirmButtonText: 'OK'
            });
            return false;
        }
    }
    
    // Check if student is selected for student filter
    if (filterType === 'student') {
        var studentSelect = $('#studentSelect').val();
        if (!studentSelect || studentSelect === '' || studentSelect === null) {
            console.log('Validation failed: Student not selected for student filter');
            e.preventDefault();
            e.stopPropagation();
            Swal.fire({
                title: 'Peringatan!',
                text: 'Untuk rekap per siswa, silakan pilih siswa terlebih dahulu sebelum mencari!',
                icon: 'warning',
                confirmButtonText: 'OK'
            });
            return false;
        }
    }
    
    // If all validations pass, allow form to submit normally
    console.log('All validations passed, allowing form submission...');
    return true;
});

// Initialize Select2 for student dropdown when page loads and when class changes
$(document).ready(function() {
    console.log('Document ready, initializing Select2');
    console.log('Form validation handler should be attached');
    console.log('Form element exists:', $('#attendanceFilterForm').length > 0);
    console.log('SweetAlert available:', typeof Swal !== 'undefined');
    
    // Initialize Select2 for student dropdown
    function initStudentSelect2() {
        var $studentSelect = $('#studentSelect');
        console.log('initStudentSelect2 called, studentSelect length:', $studentSelect.length);
        
        if ($studentSelect.length > 0) {
            console.log('Student select found, checking if already initialized');
            // Destroy if already initialized
            if ($studentSelect.hasClass('select2-hidden-accessible')) {
                console.log('Destroying existing Select2');
                $studentSelect.select2('destroy');
            }
            // Initialize Select2
            console.log('Initializing Select2');
            $studentSelect.select2({
                placeholder: 'Pilih Siswa...',
                allowClear: true,
                width: '100%'
            });
            console.log('Select2 initialized successfully');
        } else {
            console.log('Student select not found');
        }
    }
    
    // Initialize on page load
    initStudentSelect2();
    
    // Re-initialize when class changes
    $('#classSelect').on('change', function() {
        console.log('Class select changed, reinitializing student select');
        // Small delay to allow for DOM updates
        setTimeout(initStudentSelect2, 100);
    });
    
    // Handle filter type change - show/hide date picker
    $('#filterType').on('change', function() {
        var filterType = $(this).val();
        
        // Hide all filter inputs
        $('.daily-filter, .monthly-filter, .student-filter').hide();
        
        // Show appropriate filter input
        if (filterType === 'daily') {
            $('.daily-filter').show();
        } else if (filterType === 'monthly') {
            $('.monthly-filter').show();
        } else if (filterType === 'student') {
            $('.student-filter').show();
        }
    });
    
    
    // Initialize DataTables for all tables with pagination
    function initDataTables() {
        if (typeof $.fn.DataTable === 'undefined') {
            console.warn('DataTables library not loaded');
            return;
        }
        
        // Initialize daily results table
        if ($('#dailyTable').length > 0 && !$.fn.DataTable.isDataTable('#dailyTable')) {
            $('#dailyTable').DataTable({
                "paging": true,
                "lengthChange": true,
                "pageLength": -1, // Show all records by default
                "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Semua']],
                "dom": 'lfrtip',
                "info": true,
                "language": {
                    "lengthMenu": "Tampilkan _MENU_ entri",
                    "zeroRecords": "Tidak ada data yang ditemukan",
                    "info": "Menampilkan _START_ sampai _END_ dari _TOTAL_ entri",
                    "infoEmpty": "Menampilkan 0 sampai 0 dari 0 entri",
                    "infoFiltered": "(disaring dari _MAX_ total entri)",
                    "search": "Cari:",
                    "paginate": {
                        "first": "Pertama",
                        "last": "Terakhir",
                        "next": "Selanjutnya",
                        "previous": "Sebelumnya"
                    }
                }
            });
        }
        
        // Initialize student results table
        if ($('#studentTable').length > 0 && !$.fn.DataTable.isDataTable('#studentTable')) {
            $('#studentTable').DataTable({
                "paging": true,
                "lengthChange": true,
                "pageLength": -1, // Show all records by default
                "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Semua']],
                "dom": 'lfrtip',
                "info": true,
                "language": {
                    "lengthMenu": "Tampilkan _MENU_ entri",
                    "zeroRecords": "Tidak ada data yang ditemukan",
                    "info": "Menampilkan _START_ sampai _END_ dari _TOTAL_ entri",
                    "infoEmpty": "Menampilkan 0 sampai 0 dari 0 entri",
                    "infoFiltered": "(disaring dari _MAX_ total entri)",
                    "search": "Cari:",
                    "paginate": {
                        "first": "Pertama",
                        "last": "Terakhir",
                        "next": "Selanjutnya",
                        "previous": "Sebelumnya"
                    }
                }
            });
        }
        
        // Initialize semester table (if exists)
        if ($('#semesterTable').length > 0 && !$.fn.DataTable.isDataTable('#semesterTable')) {
            $('#semesterTable').DataTable({
                "paging": true,
                "lengthChange": true,
                "pageLength": -1, // Show all records by default
                "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Semua']],
                "dom": 'lfrtip',
                "info": true,
                "scrollX": true,
                "language": {
                    "lengthMenu": "Tampilkan _MENU_ entri",
                    "zeroRecords": "Tidak ada data yang ditemukan",
                    "info": "Menampilkan _START_ sampai _END_ dari _TOTAL_ entri",
                    "infoEmpty": "Menampilkan 0 sampai 0 dari 0 entri",
                    "infoFiltered": "(disaring dari _MAX_ total entri)",
                    "search": "Cari:",
                    "paginate": {
                        "first": "Pertama",
                        "last": "Terakhir",
                        "next": "Selanjutnya",
                        "previous": "Sebelumnya"
                    }
                }
            });
        }
    }
    
    // Initialize DataTables when page loads
    initDataTables();
    
    // Re-initialize after form submission (when new data is loaded)
    setTimeout(initDataTables, 500);
});
</script>