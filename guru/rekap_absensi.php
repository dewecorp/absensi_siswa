<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has guru level
if (!isAuthorized(['guru'])) {
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
    "https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js"
];

// Handle form submission
$class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
$filter_type = $_POST['filter_type'] ?? 'daily';
$selected_date = isset($_POST['attendance_date']) ? $_POST['attendance_date'] : '';
$selected_month = isset($_POST['month_picker']) ? $_POST['month_picker'] : date('Y-m');
$selected_student = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
$semester_results = [];
$daily_results = [];
$monthly_results = [];
$student_results = [];
$student_attendance_summary = [];

// Get school profile for semester information and signatures
$school_profile = getSchoolProfile($pdo);
$active_semester = $school_profile['semester'] ?? 'Semester 1';
$madrasah_head = $school_profile['nama_kepala_madrasah'] ?? 'Kepala Madrasah';

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

// Ensure nama_guru is set in session
if (!isset($_SESSION['nama_guru']) || empty($_SESSION['nama_guru'])) {
    $_SESSION['nama_guru'] = $teacher['nama_guru'];
}

// Get classes that this teacher teaches
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

// If class_id is not set but we have classes, default to first class? 
// Or let user select. Better let user select, but if posted, use that.
// If class_id provided but not in allowed classes, reset to 0 (security)
if ($class_id > 0) {
    $allowed = false;
    foreach ($classes as $c) {
        if ($c['id_kelas'] == $class_id) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        $class_id = 0;
    }
}

// Fetch Wali Kelas Name for the selected class
$class_teacher_name = '';
if ($class_id > 0) {
    $stmt = $pdo->prepare("SELECT wali_kelas FROM tb_kelas WHERE id_kelas = ?");
    $stmt->execute([$class_id]);
    $class_info = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($class_info) {
        $class_teacher_name = $class_info['wali_kelas'];
    }
}

// Process search based on filter type
if ($class_id > 0) {
    if ($filter_type == 'daily' && !empty($selected_date)) {
        // Daily filter
        $stmt = $pdo->prepare("
            SELECT s.nama_siswa, s.nisn, k.nama_kelas, a.keterangan, a.tanggal, a.jam_masuk, a.jam_keluar
            FROM tb_absensi a
            LEFT JOIN tb_siswa s ON a.id_siswa = s.id_siswa  
            LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas
            WHERE s.id_kelas = ? AND a.tanggal = ?
            ORDER BY s.nama_siswa ASC
        ");
        $stmt->execute([$class_id, $selected_date]);
        $daily_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($filter_type == 'monthly' && !empty($selected_month)) {
        // Monthly filter
        $year = substr($selected_month, 0, 4);
        $month = substr($selected_month, 5, 2);
        
        // Get all students in the class
        $stmt = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
        $stmt->execute([$class_id]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
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
        
        // Organize data by student
        $student_attendance = [];
        foreach ($attendance_records as $record) {
            $student_id = $record['id_siswa'];
            if (!isset($student_attendance[$student_id])) {
                $student_attendance[$student_id] = [
                    'nama_siswa' => $record['nama_siswa'],
                    'nisn' => $record['nisn'],
                    'days' => array_fill(1, 31, ''), // Initialize all days as empty
                    'summary' => ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0, 'Berhalangan' => 0]
                ];
            }
            $day = (int)$record['day'];
            $student_attendance[$student_id]['days'][$day] = $record['keterangan'];
            if (isset($student_attendance[$student_id]['summary'][$record['keterangan']])) {
                $student_attendance[$student_id]['summary'][$record['keterangan']]++;
            }
        }
        
        // Convert to indexed array
        $monthly_results = array_values($student_attendance);
    } elseif ($filter_type == 'student' && $selected_student > 0) {
        // Student filter
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
        // Semester filter
        $academic_year = $school_profile['tahun_ajaran'] ?? (date('Y') . '/' . (date('Y') + 1));
        $years = explode('/', $academic_year);
        $start_year = (int)($years[0] ?? date('Y'));
        $end_year = (int)($years[1] ?? (date('Y') + 1));
        
        if ($active_semester == 'Semester 1') {
            $query_year = $start_year;
            $start_month = 7;
            $end_month = 12;
        } else {
            $query_year = $end_year;
            $start_month = 1;
            $end_month = 6;
        }
        
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
        
        $student_attendance = [];
        foreach ($attendance_records as $record) {
            $student_id = $record['id_siswa'];
            $month = (int)$record['month'];
            $status = $record['keterangan'];
            
            if (!isset($student_attendance[$student_id])) {
                $student_attendance[$student_id] = [
                    'nama_siswa' => $record['nama_siswa'],
                    'nisn' => $record['nisn'],
                    'monthly_totals' => [],
                    'summary' => ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0]
                ];
                
                for ($m = $start_month; $m <= $end_month; $m++) {
                    $student_attendance[$student_id]['monthly_totals'][$m] = [
                        'Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0
                    ];
                }
            }
            
            if (isset($student_attendance[$student_id]['monthly_totals'][$month][$status])) {
                $student_attendance[$student_id]['monthly_totals'][$month][$status]++;
                $student_attendance[$student_id]['summary'][$status]++;
            }
        }
        
        $semester_results = array_values($student_attendance);
    }
}

include '../templates/user_header.php';
?>

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
                            <h4>Filter Rekap Absensi</h4>
                        </div>
                        <div class="card-body">
                            <form method="POST" class="row" id="attendanceFilterForm">
                                <div class="form-group col-md-3">
                                    <label>Pilih Kelas</label>
                                    <select name="class_id" class="form-control selectric" id="classSelect" required>
                                        <option value="">-- Pilih Kelas --</option>
                                        <?php foreach ($classes as $c): ?>
                                            <option value="<?php echo $c['id_kelas']; ?>" <?php echo ($class_id == $c['id_kelas']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($c['nama_kelas']); ?>
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
                                        <table class="table table-striped table-md" id="dailyTable">
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
                                    
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-md" id="semesterTable">
                                            <thead>
                                                <tr>
                                                    <th rowspan="2">No</th>
                                                    <th rowspan="2">Nama Siswa</th>
                                                    <?php 
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
                                                    $total_months = ($end_month - $start_month) + 1;
                                                    for ($i = 0; $i < $total_months + 1; $i++): 
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
                                                        for ($m = $start_month; $m <= $end_month; $m++):
                                                            $hadir = $student['monthly_totals'][$m]['Hadir'] ?? 0;
                                                            $sakit = $student['monthly_totals'][$m]['Sakit'] ?? 0;
                                                            $izin = $student['monthly_totals'][$m]['Izin'] ?? 0;
                                                            $alpa = $student['monthly_totals'][$m]['Alpa'] ?? 0;
                                                            $berhalangan = $student['monthly_totals'][$m]['Berhalangan'] ?? 0;
                                                            
                                                            echo '<td class="text-center">' . ($hadir > 0 ? '<span class="badge badge-success">' . $hadir . '</span>' : '-') . '</td>';
                                                            echo '<td class="text-center">' . ($sakit > 0 ? '<span class="badge badge-warning">' . $sakit . '</span>' : '-') . '</td>';
                                                            echo '<td class="text-center">' . ($izin > 0 ? '<span class="badge badge-info">' . $izin . '</span>' : '-') . '</td>';
                                                            echo '<td class="text-center">' . ($alpa > 0 ? '<span class="badge badge-danger">' . $alpa . '</span>' : '-') . '</td>';
                                                            echo '<td class="text-center">' . ($berhalangan > 0 ? '<span class="badge badge-danger">' . $berhalangan . '</span>' : '-') . '</td>';
                                                        endfor;
                                                        
                                                        echo '<td class="text-center"><span class="badge badge-success">' . $student['summary']['Hadir'] . '</span></td>';
                                                        echo '<td class="text-center"><span class="badge badge-warning">' . $student['summary']['Sakit'] . '</span></td>';
                                                        echo '<td class="text-center"><span class="badge badge-info">' . $student['summary']['Izin'] . '</span></td>';
                                                        echo '<td class="text-center"><span class="badge badge-danger">' . $student['summary']['Alpa'] . '</span></td>';
                                                        echo '<td class="text-center"><span class="badge badge-danger">' . $student['summary']['Berhalangan'] . '</span></td>';
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
                                                                    echo '<span class="badge ' . $status_class . ' badge-sm">' . substr($status, 0, 1) . '</span>';
                                                                }
                                                                ?>
                                                            </td>
                                                        <?php endfor; ?>
                                                        <td class="text-center"><span class="badge badge-success"><?php echo $student['summary']['Hadir']; ?></span></td>
                                                        <td class="text-center"><span class="badge badge-warning"><?php echo $student['summary']['Sakit']; ?></span></td>
                                                        <td class="text-center"><span class="badge badge-info"><?php echo $student['summary']['Izin']; ?></span></td>
                                                        <td class="text-center"><span class="badge badge-danger"><?php echo $student['summary']['Alpa']; ?></span></td>
                                                        <td class="text-center"><span class="badge badge-danger"><?php echo $student['summary']['Berhalangan']; ?></span></td>
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
                                    
                                    <div class="table-responsive">
                                        <table class="table table-striped table-md">
                                            <thead>
                                                <tr>
                                                    <th>Tanggal</th>
                                                    <th>Status</th>
                                                    <th>Kelas</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($student_results as $record): ?>
                                                    <tr>
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
                                                            <div class="badge <?php echo $status_class; ?>">
                                                                <?php echo $status_text; ?>
                                                            </div>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($record['nama_kelas']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
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

<script>
// Pass PHP variables to JS
var classTeacherName = "<?php echo addslashes($class_teacher_name); ?>";
var madrasahHeadName = "<?php echo addslashes($madrasah_head); ?>";

function exportToExcel() {
    var container = document.createElement('div');
    var headerDiv = document.createElement('div');
    headerDiv.innerHTML = '<img src="../assets/img/logo_1768301957.png" alt="Logo" style="max-width: 100px; float: left; margin-right: 20px;"><div style="display: inline-block;"><h2>Sistem Absensi Siswa</h2>';
    headerDiv.innerHTML += '<h3><?php echo addslashes($school_profile['nama_madrasah'] ?? 'Madrasah Ibtidaiyah Negeri Pembina Kota Padang'); ?></h3>';
    headerDiv.innerHTML += '<h4>Rekap Absensi Bulanan - <?php echo $month_names[(int)substr($selected_month, 5, 2)] . " " . substr($selected_month, 0, 4); ?></h4></div><br style="clear: both;">';
    
    var table = document.querySelector('.table-bordered');
    if (!table) {
        Swal.fire('Error', 'Tabel tidak ditemukan', 'error');
        return;
    }
    var newTable = table.cloneNode(true);
    container.appendChild(headerDiv);
    container.appendChild(newTable);
    
    var html = container.innerHTML;
    var a = document.createElement('a');
    var data = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
    a.href = data;
    a.download = 'rekap_absensi_bulanan_' + '<?php echo str_replace(" ", "_", $month_names[(int)substr($selected_month, 5, 2)]); ?>' + '_' + '<?php echo substr($selected_month, 0, 4); ?>' + '.xls';
    a.click();
}

function exportToPDF() {
    var printWindow = window.open('', '', 'height=860,width=1300');
    printWindow.document.write('<html><head><title>Rekap Absensi Bulanan</title>');
    printWindow.document.write('<style>');
    printWindow.document.write('@page { size: legal landscape; margin: 0.5cm; }');
    printWindow.document.write('body { font-family: Arial, sans-serif; margin: 0; padding: 10px; }');
    printWindow.document.write('table { border-collapse: collapse; width: 100%; font-size: 11px; margin-bottom: 10px; }');
    printWindow.document.write('tr { page-break-inside: avoid; page-break-after: auto; }');
    printWindow.document.write('th, td { border: 1px solid #ddd; padding: 4px; text-align: center; }');
    printWindow.document.write('td:nth-child(2) { text-align: left; white-space: nowrap; }');
    printWindow.document.write('th { background-color: #f2f2f2; font-weight: bold; }');
    printWindow.document.write('.badge { padding: 1px 3px; border-radius: 2px; font-size: 7px; }');
    printWindow.document.write('.badge-success { background-color: #28a745; color: white; }');
    printWindow.document.write('.badge-warning { background-color: #ffc107; color: black; }');
    printWindow.document.write('.badge-info { background-color: #17a2b8; color: white; }');
    printWindow.document.write('.badge-danger { background-color: #dc3545; color: white; }');
    printWindow.document.write('.header { text-align: center; margin-bottom: 15px; }');
    printWindow.document.write('.logo { max-width: 80px; float: left; margin-right: 15px; }');
    printWindow.document.write('h2, h3, h4 { margin: 5px 0; }');
    printWindow.document.write('.signature-wrapper { margin-top: 10px; display: flex; justify-content: space-between; width: 100%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.signature-box { text-align: center; width: 45%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write('<div class="header">');
    printWindow.document.write('<img src="../assets/img/logo_1768301957.png" alt="Logo" class="logo">');
    printWindow.document.write('<div style="display: inline-block;"><h2>Sistem Absensi Siswa</h2>');
    printWindow.document.write('<h3><?php echo addslashes($school_profile['nama_madrasah'] ?? 'Madrasah Ibtidaiyah Negeri Pembina Kota Padang'); ?></h3>');
    printWindow.document.write('<h4>Tahun Ajaran: ' + academicYear + ' | Semester: ' + activeSemester + '</h4>');
    printWindow.document.write('<h4>Tahun Ajaran: ' + academicYear + ' | Semester: ' + activeSemester + '</h4>');
    printWindow.document.write('<h4>Rekap Absensi Bulanan - <?php echo $month_names[(int)substr($selected_month, 5, 2)] . " " . substr($selected_month, 0, 4); ?></h4></div><br style="clear: both;">');
    
    var table = document.querySelector('.table-bordered');
    if (table) {
        printWindow.document.write(table.outerHTML);
    }
    
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
    
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.focus();
    setTimeout(function() {
        printWindow.print();
    }, 500);
}

function exportSemesterToExcel() {
    var container = document.createElement('div');
    var headerDiv = document.createElement('div');
    headerDiv.innerHTML = '<img src="../assets/img/logo_1768301957.png" alt="Logo" style="max-width: 100px; float: left; margin-right: 20px;"><div style="display: inline-block;"><h2>Sistem Absensi Siswa</h2>';
    headerDiv.innerHTML += '<h3><?php echo addslashes($school_profile['nama_madrasah'] ?? 'Madrasah Ibtidaiyah Negeri Pembina Kota Padang'); ?></h3>';
    headerDiv.innerHTML += '<h4>Tahun Ajaran: ' + academicYear + ' | Semester: ' + activeSemester + '</h4>';
    headerDiv.innerHTML += '<h4>Rekap Absensi <?php echo $active_semester; ?> - Tahun <?php echo date('Y'); ?></h4></div><br style="clear: both;">';
    
    var table = document.getElementById('semesterTable');
    if (!table) {
        Swal.fire('Error', 'Tabel semester tidak ditemukan', 'error');
        return;
    }
    var newTable = table.cloneNode(true);
    container.appendChild(headerDiv);
    container.appendChild(newTable);
    
    var html = container.innerHTML;
    var a = document.createElement('a');
    var data = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
    a.href = data;
    a.download = 'rekap_absensi_' + '<?php echo str_replace(' ', '_', strtolower($active_semester)); ?>' + '_' + '<?php echo date('Y'); ?>' + '.xls';
    a.click();
}

function exportSemesterToPDF() {
    var printWindow = window.open('', '', 'height=860,width=1300');
    printWindow.document.write('<html><head><title>Rekap Absensi Semester</title>');
    printWindow.document.write('<style>');
    printWindow.document.write('@page { size: legal landscape; margin: 0.5cm; }');
    printWindow.document.write('body { font-family: Arial, sans-serif; margin: 0; padding: 10px; }');
    printWindow.document.write('table { border-collapse: collapse; width: 100%; font-size: 11px; margin-bottom: 10px; }');
    printWindow.document.write('tr { page-break-inside: avoid; page-break-after: auto; }');
    printWindow.document.write('th, td { border: 1px solid #ddd; padding: 4px; text-align: center; }');
    printWindow.document.write('td:nth-child(2) { text-align: left; white-space: nowrap; }');
    printWindow.document.write('th { background-color: #f2f2f2; font-weight: bold; }');
    printWindow.document.write('.badge { padding: 1px 2px; border-radius: 2px; font-size: 6px; }');
    printWindow.document.write('.badge-success { background-color: #28a745; color: white; }');
    printWindow.document.write('.badge-warning { background-color: #ffc107; color: black; }');
    printWindow.document.write('.badge-info { background-color: #17a2b8; color: white; }');
    printWindow.document.write('.badge-danger { background-color: #dc3545; color: white; }');
    printWindow.document.write('.header { text-align: center; margin-bottom: 15px; }');
    printWindow.document.write('.logo { max-width: 80px; float: left; margin-right: 15px; }');
    printWindow.document.write('h2, h3, h4 { margin: 5px 0; }');
    printWindow.document.write('.signature-wrapper { margin-top: 10px; display: flex; justify-content: space-between; width: 100%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.signature-box { text-align: center; width: 45%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write('<div class="header">');
    printWindow.document.write('<img src="../assets/img/logo_1768301957.png" alt="Logo" class="logo">');
    printWindow.document.write('<div style="display: inline-block;"><h2>Sistem Absensi Siswa</h2>');
    printWindow.document.write('<h3><?php echo addslashes($school_profile['nama_madrasah'] ?? 'Madrasah Ibtidaiyah Negeri Pembina Kota Padang'); ?></h3>');
    printWindow.document.write('<h4>Rekap Absensi <?php echo $active_semester; ?> - Tahun <?php echo date('Y'); ?></h4></div><br style="clear: both;">');
    
    var table = document.getElementById('semesterTable');
    if (table) {
        printWindow.document.write(table.outerHTML);
    }
    
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
    
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.focus();
    setTimeout(function() {
        printWindow.print();
    }, 500);
}

$(document).ready(function() {
    function initStudentSelect2() {
        var $studentSelect = $('#studentSelect');
        if ($studentSelect.length > 0) {
            if ($studentSelect.hasClass('select2-hidden-accessible')) {
                $studentSelect.select2('destroy');
            }
            $studentSelect.select2({
                placeholder: 'Pilih Siswa...',
                allowClear: true,
                width: '100%'
            });
        }
    }
    
    initStudentSelect2();
    
    $('#classSelect').on('change', function() {
        setTimeout(initStudentSelect2, 100);
    });
    
    $('#filterType').on('change', function() {
        var filterType = $(this).val();
        $('.daily-filter, .monthly-filter, .student-filter').hide();
        if (filterType === 'daily') {
            $('.daily-filter').show();
        } else if (filterType === 'monthly') {
            $('.monthly-filter').show();
        } else if (filterType === 'student') {
            $('.student-filter').show();
        }
    });
    
    $(document).on('submit', '#attendanceFilterForm', function(e) {
        var filterType = $('#filterType').val();
        var classId = $('#classSelect').val();
        var datePicker = $('#datePicker').val();
        
        if (!classId || classId === '') {
            e.preventDefault();
            Swal.fire({
                title: 'Peringatan!',
                text: 'Silakan pilih kelas terlebih dahulu!',
                icon: 'warning',
                confirmButtonText: 'OK'
            });
            return false;
        }
        
        if (filterType === 'daily') {
            if (!datePicker || datePicker === '' || datePicker === null) {
                e.preventDefault();
                Swal.fire({
                    title: 'Peringatan!',
                    text: 'Untuk rekap harian, silakan pilih tanggal terlebih dahulu sebelum mencari!',
                    icon: 'warning',
                    confirmButtonText: 'OK'
                }).then(function() {
                    $('#datePicker').focus();
                });
                return false;
            }
        }
        
        if (filterType === 'monthly') {
            var monthPicker = $('#monthPicker').val();
            if (!monthPicker || monthPicker === '' || monthPicker === null) {
                e.preventDefault();
                Swal.fire({
                    title: 'Peringatan!',
                    text: 'Untuk rekap bulanan, silakan pilih bulan terlebih dahulu sebelum mencari!',
                    icon: 'warning',
                    confirmButtonText: 'OK'
                }).then(function() {
                    $('#monthPicker').focus();
                });
                return false;
            }
        }
        
        if (filterType === 'student') {
            var studentId = $('#studentSelect').val();
            if (!studentId || studentId === '') {
                e.preventDefault();
                Swal.fire({
                    title: 'Peringatan!',
                    text: 'Untuk rekap per siswa, silakan pilih siswa terlebih dahulu sebelum mencari!',
                    icon: 'warning',
                    confirmButtonText: 'OK'
                });
                return false;
            }
        }
    });
});
</script>
