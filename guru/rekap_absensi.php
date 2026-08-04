<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has guru level
if (!isAuthorized(['guru'])) {
    redirect('../login.php');
}

// Set page title
$page_title = 'Rekap Kehadiran';

// Define CSS libraries for this page
$css_libs = [
    "assets/vendor/select2/css/select2.min.css",
    "assets/vendor/datatables/css/dataTables.bootstrap4.min.css"
];

// Define JS libraries for this page
$js_libs = [
    "assets/vendor/select2/js/select2.full.min.js",
    "assets/vendor/datatables/js/jquery.dataTables.min.js",
    "assets/vendor/datatables/js/dataTables.bootstrap4.min.js",
    "assets/vendor/xlsx/xlsx.full.min.js"
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

// Initialize variables to avoid undefined variable warnings
$year = date('Y');
$month = date('m');
$holidays = [];
$month_names = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

// Get school profile for semester information and signatures
$school_profile = getSchoolProfile($pdo);
$active_semester = $school_profile['semester'] ?? 'Semester 1';
$madrasah_head = $school_profile['kepala_madrasah'] ?? 'Kepala Madrasah';
$periode_ta = getRentangTanggalTahunAjaran($school_profile['tahun_ajaran'] ?? null);

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
    
    // Fallback: If not a valid JSON array, but contains comma separated values
    if ($mengajar_decoded === null && !empty($teacher['mengajar'])) {
        $mengajar_decoded = array_map('trim', explode(',', $teacher['mengajar']));
    }
    
    if (is_array($mengajar_decoded) && !empty($mengajar_decoded)) {
        // Get all classes first
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
                } elseif (strcasecmp($kelas['nama_kelas'], $kelas_id) === 0) {
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

// Fallback: If teacher is a wali kelas (homeroom teacher), add their class
$stmt_wali = $pdo->prepare("SELECT * FROM tb_kelas WHERE wali_kelas = ?");
$stmt_wali->execute([$teacher['nama_guru']]);
$wali_class = $stmt_wali->fetch(PDO::FETCH_ASSOC);
if ($wali_class) {
    $exists = false;
    foreach ($classes as $existing_class) {
        if ($existing_class['id_kelas'] == $wali_class['id_kelas']) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        $classes[] = $wali_class;
    }
}

// Handle form submission
$class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;

// Set default filter type and date
$filter_type = isset($_POST['filter_type']) ? $_POST['filter_type'] : 'daily';
$selected_date = isset($_POST['attendance_date']) ? $_POST['attendance_date'] : date('Y-m-d');
$selected_month = isset($_POST['month_picker']) ? $_POST['month_picker'] : date('Y-m');
$selected_student = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;

// Auto-select class if teacher only has one class and it's not a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $class_id === 0 && count($classes) === 1) {
    $class_id = $classes[0]['id_kelas'];
    // When auto-selecting class, we should also trigger the data fetch
    $_POST['class_id'] = $class_id;
    $_POST['filter_type'] = $filter_type;
    $_POST['attendance_date'] = $selected_date;
}

// Ensure results variables are initialized even if no POST
$daily_results = [];
$monthly_results = [];
$student_results = [];
$semester_results = [];

if ($class_id > 0) {
    // If it's not a POST request, but we have a class_id (from auto-select), 
    // we need to make sure the filtering logic below runs.
}

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
        // Daily filter (dibatasi tahun ajaran aktif di profil)
        $sqlDaily = "
            SELECT s.nama_siswa, s.nisn, k.nama_kelas, a.keterangan, a.tanggal, a.jam_masuk, a.jam_keluar
            FROM tb_siswa s
            LEFT JOIN tb_absensi a ON s.id_siswa = a.id_siswa AND a.tanggal = ?
            LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas
            WHERE s.id_kelas = ?";
        $bindDaily = [$selected_date, $class_id];
        // We don't filter a.tanggal in WHERE clause because it would break the LEFT JOIN for students who haven't absented yet.
        // The date filtering is already handled in the ON clause: a.tanggal = ?
        $sqlDaily .= " ORDER BY s.nama_siswa ASC";
        $stmt = $pdo->prepare($sqlDaily);
        $stmt->execute($bindDaily);
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
        $sqlMon = "
            SELECT s.id_siswa, s.nama_siswa, s.nisn, a.keterangan, DAY(a.tanggal) as day
            FROM tb_siswa s
            LEFT JOIN tb_absensi a ON s.id_siswa = a.id_siswa AND YEAR(a.tanggal) = ? AND MONTH(a.tanggal) = ?
            WHERE s.id_kelas = ?";
        $bindMon = [$year, $month, $class_id];
        // Remove academic year filter in WHERE for LEFT JOIN
        $sqlMon .= " ORDER BY s.nama_siswa, a.tanggal";
        $stmt = $pdo->prepare($sqlMon);
        $stmt->execute($bindMon);
        $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Organize data by student
        $student_attendance = [];
        
        // First, ensure all students in the class are in the list
        $stmt_students = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
        $stmt_students->execute([$class_id]);
        $all_students_in_class = $stmt_students->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($all_students_in_class as $s) {
            $student_attendance[$s['id_siswa']] = [
                'nama_siswa' => $s['nama_siswa'],
                'nisn' => $s['nisn'],
                'days' => array_fill(1, 31, ''),
                'summary' => ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0, 'Berhalangan' => 0]
            ];
        }
        
        foreach ($attendance_records as $record) {
            $student_id = $record['id_siswa'];
            // If keterangan is null, it means no attendance record for this student on this day
            if ($record['keterangan'] !== null) {
                $day = (int)$record['day'];
                $student_attendance[$student_id]['days'][$day] = $record['keterangan'];
                if (isset($student_attendance[$student_id]['summary'][$record['keterangan']])) {
                    $student_attendance[$student_id]['summary'][$record['keterangan']]++;
                }
            }
        }
        
        // Libur: kalender pendidikan (danger) + hari libur mingguan sesuai profil madrasah
        $holidays = getHolidays($pdo, $year, $month);
        
        // Convert to indexed array
        $monthly_results = array_values($student_attendance);
    } elseif ($filter_type == 'student' && $selected_student > 0) {
        // Student filter
        $sqlSt = "
            SELECT s.nama_siswa, s.nisn, k.nama_kelas, a.keterangan, a.tanggal
            FROM tb_absensi a
            LEFT JOIN tb_siswa s ON a.id_siswa = s.id_siswa
            LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas
            WHERE s.id_siswa = ?";
        $bindSt = [$selected_student];
        if ($periode_ta) {
            $sqlSt .= " AND a.tanggal >= ? AND a.tanggal <= ?";
            $bindSt[] = $periode_ta['mulai'];
            $bindSt[] = $periode_ta['sampai'];
        }
        $sqlSt .= " ORDER BY a.tanggal DESC";
        $stmt = $pdo->prepare($sqlSt);
        $stmt->execute($bindSt);
        $student_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate summary statistics
        $summary = ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0, 'Berhalangan' => 0];
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
        
        $sqlSem = "
            SELECT s.id_siswa, s.nama_siswa, s.nisn, a.keterangan, a.tanggal,
                   MONTH(a.tanggal) as month, DAY(a.tanggal) as day
            FROM tb_siswa s
            LEFT JOIN tb_absensi a ON s.id_siswa = a.id_siswa AND YEAR(a.tanggal) = ? AND MONTH(a.tanggal) BETWEEN ? AND ?
            WHERE s.id_kelas = ?";
        $bindSem = [$query_year, $start_month, $end_month, $class_id];
        // Remove academic year filter in WHERE for LEFT JOIN
        $sqlSem .= " ORDER BY s.nama_siswa, a.tanggal";
        $stmt = $pdo->prepare($sqlSem);
        $stmt->execute($bindSem);
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
                    'summary' => ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0, 'Berhalangan' => 0]
                ];
                
                for ($m = $start_month; $m <= $end_month; $m++) {
                    $student_attendance[$student_id]['monthly_totals'][$m] = [
                        'Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0, 'Berhalangan' => 0
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

// Get Summary of Absent Students (Sakit, Izin, Alpa) for selected class on selected date (for daily view)
$absent_summary = [];
if ($filter_type == 'daily' && !empty($selected_date) && $class_id > 0) {
    $sqlSum = "
        SELECT s.nama_siswa, k.nama_kelas, a.keterangan 
        FROM tb_absensi a
        JOIN tb_siswa s ON a.id_siswa = s.id_siswa
        JOIN tb_kelas k ON s.id_kelas = k.id_kelas
        WHERE a.tanggal = ? AND s.id_kelas = ? AND a.keterangan IN ('Sakit', 'Izin', 'Alpa')";
    $bindSum = [$selected_date, $class_id];
    if ($periode_ta) {
        $sqlSum .= " AND a.tanggal >= ? AND a.tanggal <= ?";
        $bindSum[] = $periode_ta['mulai'];
        $bindSum[] = $periode_ta['sampai'];
    }
    $sqlSum .= " ORDER BY s.nama_siswa ASC";
    $summary_stmt = $pdo->prepare($sqlSum);
    $summary_stmt->execute($bindSum);
    $absent_summary = $summary_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Tambahan: Pastikan daily_results berisi semua siswa jika class_id terpilih (untuk tampilan tabel utama)
if ($filter_type == 'daily' && $class_id > 0 && empty($daily_results)) {
    $sqlDailyAll = "
        SELECT s.nama_siswa, s.nisn, k.nama_kelas, a.keterangan, a.tanggal, a.jam_masuk, a.jam_keluar
        FROM tb_siswa s
        LEFT JOIN tb_absensi a ON s.id_siswa = a.id_siswa AND a.tanggal = ?
        LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas
        WHERE s.id_kelas = ?
        ORDER BY s.nama_siswa ASC";
    $stmtDailyAll = $pdo->prepare($sqlDailyAll);
    $stmtDailyAll->execute([$selected_date, $class_id]);
    $daily_results = $stmtDailyAll->fetchAll(PDO::FETCH_ASSOC);
}

include '../templates/user_header.php';
?>

<!-- Main Content -->
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Rekap Kehadiran</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Rekap Kehadiran</div>
            </div>
        </div>

        <div class="section-body">
            <?php if ($filter_type == 'daily' && !empty($selected_date)): ?>
                <!-- Ringkasan Ketidakhadiran Harian (Kelas Terpilih) - Box Tersendiri di Atas Filter -->
                <div class="row">
                    <div class="col-12">
                        <div class="card card-statistic-1 border">
                            <div class="card-header pb-0">
                                <h4>Ringkasan Ketidakhadiran Kelas (<?php echo date('d-m-Y', strtotime($selected_date)); ?>)</h4>
                            </div>
                            <div class="card-body pt-0">
                                <?php if (!empty($absent_summary)): ?>
                                    <div class="row mt-3">
                                        <?php 
                                        $counts = ['Sakit' => 0, 'Izin' => 0, 'Alpa' => 0];
                                        foreach ($absent_summary as $abs) $counts[$abs['keterangan']]++;
                                        $total_absent = array_sum($counts);
                                        
                                        $summary_items = [
                                            ['label' => 'Total Tidak Hadir', 'count' => $total_absent, 'color' => 'dark'],
                                            ['label' => 'Sakit', 'count' => $counts['Sakit'], 'color' => '#ffa426'],
                                            ['label' => 'Izin', 'count' => $counts['Izin'], 'color' => '#3abaf4'],
                                            ['label' => 'Alpa', 'count' => $counts['Alpa'], 'color' => '#fc544b']
                                        ];
                                        ?>
                                        <?php foreach ($summary_items as $item): ?>
                                        <div class="col-md-3">
                                            <div class="card mb-3" style="background-color: <?php echo $item['color'] == 'dark' ? '#343a40' : $item['color']; ?>; color: #ffffff !important;">
                                                <div class="card-body p-3 text-center">
                                                    <div class="text-small font-weight-bold" style="color: #ffffff !important; margin-bottom: 2px;"><?php echo $item['label']; ?></div>
                                                    <div class="h5 mb-0 font-weight-bold" style="color: #ffffff !important;"><?php echo $item['count']; ?> Siswa</div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="table-responsive mt-2">
                                        <table class="table table-sm table-bordered" style="font-size: 13px;">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th class="py-1 text-center" style="width: 50px;">No</th>
                                                    <th class="py-1">Nama Siswa</th>
                                                    <th class="py-1">Kelas</th>
                                                    <th class="py-1">Keterangan</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $no_abs = 1; foreach ($absent_summary as $abs): ?>
                                                    <tr>
                                                        <td class="py-1 text-center"><?php echo $no_abs++; ?></td>
                                                        <td class="py-1"><?php echo htmlspecialchars($abs['nama_siswa']); ?></td>
                                                        <td class="py-1"><?php echo htmlspecialchars($abs['nama_kelas']); ?></td>
                                                        <td class="py-1">
                                                            <span class="badge <?php 
                                                                echo $abs['keterangan'] == 'Sakit' ? 'badge-warning' : ($abs['keterangan'] == 'Izin' ? 'badge-info' : 'badge-danger'); 
                                                            ?>">
                                                                <?php echo $abs['keterangan']; ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-light mt-3 text-center">
                                        Semua siswa hadir atau belum ada data kehadiran untuk tanggal ini.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Filter Rekap Kehadiran</h4>
                        </div>
                        <div class="card-body">
                                        <form method="POST" class="row" id="attendanceFilterForm">
                                <?php if (count($classes) > 1): ?>
                                <div class="form-group col-md-3">
                                    <label>Pilih Kelas</label>
                                    <select name="class_id" class="form-control selectric" id="classSelect" required onchange="this.form.submit()">
                                        <option value="">-- Pilih Kelas --</option>
                                        <?php foreach ($classes as $c): ?>
                                            <option value="<?php echo $c['id_kelas']; ?>" <?php echo ($class_id == $c['id_kelas']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($c['nama_kelas']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php elseif (count($classes) === 1): ?>
                                <div class="form-group col-md-3">
                                    <label>Kelas</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($classes[0]['nama_kelas']); ?>" readonly>
                                    <input type="hidden" name="class_id" value="<?php echo $classes[0]['id_kelas']; ?>">
                                </div>
                                <?php else: ?>
                                    <input type="hidden" name="class_id" value="">
                                <?php endif; ?>
                                
                                            <div class="form-group col-md-3">
                                                <label>Jenis Filter</label>
                                                <select name="filter_type" class="form-control selectric" id="filterType" onchange="this.form.submit()">
                                        <option value="daily" <?php echo ($filter_type == 'daily') ? 'selected' : ''; ?>>Harian</option>
                                        <option value="monthly" <?php echo ($filter_type == 'monthly') ? 'selected' : ''; ?>>Bulanan</option>
                                        <option value="semester" <?php echo ($filter_type == 'semester') ? 'selected' : ''; ?>>Per Semester</option>
                                        <option value="student" <?php echo ($filter_type == 'student') ? 'selected' : ''; ?>>Per Siswa</option>
                                    </select>
                                </div>
                                
                                            <div class="form-group col-md-3 daily-filter" style="<?php echo ($filter_type == 'daily') ? '' : 'display:none;'; ?>">
                                                <label>Pilih Tanggal</label>
                                                <input type="date" name="attendance_date" class="form-control" 
                                                       value="<?php echo htmlspecialchars($selected_date); ?>" id="datePicker" onchange="this.form.submit()">
                                </div>
                                
                                            <div class="form-group col-md-3 monthly-filter" style="<?php echo ($filter_type == 'monthly') ? '' : 'display:none;'; ?>">
                                                <label>Pilih Bulan</label>
                                                <input type="month" name="month_picker" class="form-control" 
                                                       value="<?php echo htmlspecialchars($selected_month); ?>" id="monthPicker" onchange="this.form.submit()">
                                </div>
                                
                                            <div class="form-group col-md-3 student-filter" style="<?php echo ($filter_type == 'student') ? '' : 'display:none;'; ?>">
                                                <label>Pilih Siswa</label>
                                                <select name="student_id" class="form-control selectric" id="studentSelect" onchange="this.form.submit()">
                                        <option value="">Pilih Siswa...</option>
                                        <?php 
                                        if ($class_id > 0) {
                                            $stmt = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
                                            $stmt->execute([$class_id]);
                                            $class_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                            foreach ($class_students as $student):
                                        ?>
                                            <option value="<?php echo $student['id_siswa']; ?>" <?php echo ($selected_student == $student['id_siswa']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($student['nama_siswa']); ?>
                                            </option>
                                        <?php 
                                            endforeach;
                                        }
                                        ?>
                                    </select>
                                </div>
                            </form>
                            
                            <?php if (!empty($daily_results)): ?>
                                <!-- Daily Results -->
                                <div class="mt-4">
                                    <div class="alert alert-success alert-has-icon">
                                        <div class="alert-icon"><i class="far fa-check-circle"></i></div>
                                        <div class="alert-body">
                                            <div class="alert-title">Berhasil</div>
                                            Ditemukan <?php echo count($daily_results); ?> data kehadiran untuk tanggal yang dipilih.
                                        </div>
                                    </div>
                                    
                                    <div class="table-responsive">
                                        <table class="table table-striped table-md" id="dailyTable">
                                            <thead>
                                                <tr>
                                                    <th>No</th>
                                                    <th>Nama Siswa</th>
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
                                            Menampilkan rekap kehadiran <?php echo $active_semester; ?> Tahun Ajaran <?php echo $school_profile['tahun_ajaran'] ?? (date('Y') . '/' . (date('Y') + 1)); ?> untuk <?php echo count($semester_results); ?> siswa.
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
                                                    $start_month = ($active_semester == 'Semester 1') ? 7 : 1;
                                                    $end_month = ($active_semester == 'Semester 1') ? 12 : 6;
                                                    
                                                    for ($m = $start_month; $m <= $end_month; $m++):
                                                    ?>
                                                        <th colspan="4" class="text-center"><?php echo $month_names[$m]; ?></th>
                                                    <?php endfor; ?>
                                                    <th colspan="4" class="text-center">Total Semester</th>
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
                                                        endfor;
                                                        
                                                        echo '<td class="text-center"><span class="badge badge-success">' . $student['summary']['Hadir'] . '</span></td>';
                                                        echo '<td class="text-center"><span class="badge badge-warning">' . $student['summary']['Sakit'] . '</span></td>';
                                                        echo '<td class="text-center"><span class="badge badge-info">' . $student['summary']['Izin'] . '</span></td>';
                                                        echo '<td class="text-center"><span class="badge badge-danger">' . $student['summary']['Alpa'] . '</span></td>';
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
                                            Menampilkan rekap kehadiran bulanan untuk <?php echo count($monthly_results); ?> siswa.
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
                                        <table class="table table-bordered table-md" id="monthlyTable">
                                            <thead>
                                                <tr>
                                                    <th rowspan="2">No</th>
                                                    <th rowspan="2">Nama Siswa</th>
                                                    <th colspan="31" class="text-center">
                                                        <?php 
                                                        $month_num = (int)substr($selected_month, 5, 2);
                                                        echo $month_names[$month_num] . ' ' . substr($selected_month, 0, 4);
                                                        ?>
                                                    </th>
                                                    <th colspan="4" class="text-center">Total</th>
                                                </tr>
                                                <tr>
                                                    <?php for ($day = 1; $day <= 31; $day++): ?>
                                                        <th><?php echo $day; ?></th>
                                                    <?php endfor; ?>
                                                    <th>Hadir</th>
                                                    <th>Sakit</th>
                                                    <th>Izin</th>
                                                    <th>Alpa</th>
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
                                                                $current_date = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
                                                                $is_holiday = isset($holidays[$current_date]);
                                                                
                                                                if ($is_holiday) {
                                                                    echo '<span style="font-size: 10pt; color: red;" title="' . htmlspecialchars($holidays[$current_date]) . '">L</span>';
                                                                } elseif (!empty($status)) {
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
                                            <div class="alert-title">Data Kehadiran Siswa</div>
                                            Menampilkan riwayat kehadiran untuk <?php echo htmlspecialchars($student_results[0]['nama_siswa'] ?? ''); ?>
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

<?php
// Pass PHP variables to JS
$school_city = $school_profile['tempat_jadwal'] ?? '';
$report_date = formatDateIndonesia(date('Y-m-d'));

echo "<script>
var classTeacherName = " . json_encode($class_teacher_name) . ";
var madrasahHeadName = " . json_encode($madrasah_head) . ";
var madrasahHeadSignature = " . json_encode($school_profile['ttd_kepala'] ?? '') . ";
var schoolName = " . json_encode($school_profile['nama_madrasah'] ?? 'Madrasah Ibtidaiyah Negeri Pembina Kota Padang') . ";
var academicYear = " . json_encode($school_profile['tahun_ajaran'] ?? (date('Y') . '/' . (date('Y') + 1))) . ";
var activeSemester = " . json_encode($active_semester) . ";
var schoolCity = " . json_encode($school_city) . ";
var reportDate = " . json_encode($report_date) . ";

function recoverDropdownUiState() {
    if (!window.jQuery) return;
    // Bersihkan state overlay/backdrop yang kadang tertinggal dan membuat dropdown terasa freeze.
    $('body').removeClass('modal-open').css('padding-right', '');
    $('.modal-backdrop').remove();
    $('.dropdown.show').removeClass('show');
    $('.dropdown-menu.show').removeClass('show');

    // Re-init Select2 jika terpasang pada dropdown siswa.
    if ($.fn.select2 && $('#studentSelect').length) {
        try {
            if ($('#studentSelect').hasClass('select2-hidden-accessible')) {
                $('#studentSelect').select2('destroy');
            }
            $('#studentSelect').select2({
                placeholder: 'Pilih Siswa...',
                allowClear: true,
                width: '100%'
            });
        } catch (e) {}
    }

    // Refresh Selectric untuk class/filter dropdown agar klik kembali responsif.
    if ($.fn.selectric) {
        try { $('#classSelect').selectric('refresh'); } catch (e) {}
        try { $('#filterType').selectric('refresh'); } catch (e) {}
        try { $('#studentSelect').selectric('refresh'); } catch (e) {}
    }
}

function openPrintPreviewTab(url) {
    var previewWindow = window.open(url, '_blank', 'noopener,noreferrer');
    if (previewWindow) {
        try { previewWindow.opener = null; } catch (e) {}
    }
    // Jalankan cleanup setelah event klik export selesai diproses browser.
    setTimeout(recoverDropdownUiState, 80);
}
</script>";
?>

<script>
function exportToExcel() {
    var container = document.createElement('div');
    var headerDiv = document.createElement('div');
    headerDiv.innerHTML = '<img src="../assets/img/logo_1768301957.png" alt="Logo" style="max-width: 100px; float: left; margin-right: 20px;"><div style="display: inline-block;"><h2>Sistem Informasi Madrasah</h2>';
    headerDiv.innerHTML += '<h3><?php echo addslashes($school_profile['nama_madrasah'] ?? 'Madrasah Ibtidaiyah Negeri Pembina Kota Padang'); ?></h3>';
    headerDiv.innerHTML += '<h4>Rekap Kehadiran Bulanan - <?php echo $month_names[(int)substr($selected_month, 5, 2)] . " " . substr($selected_month, 0, 4); ?></h4></div><br style="clear: both;">';
    
    var table = document.getElementById('monthlyTable');
    if (!table) {
        // Fallback to any table-bordered if monthlyTable not found
        table = document.querySelector('.table-bordered');
    }
    if (!table) {
        Swal.fire('Error', 'Tabel tidak ditemukan', 'error');
        return;
    }
    var newTable = table.cloneNode(true);
    
    var badges = newTable.querySelectorAll('.badge');
    for (var i = 0; i < badges.length; i++) {
        var badge = badges[i];
        var textNode = document.createTextNode(badge.textContent);
        badge.parentNode.replaceChild(textNode, badge);
    }
    
    container.appendChild(headerDiv);
    container.appendChild(newTable);
    
    var html = container.innerHTML;
    
    if (typeof XLSX !== 'undefined') {
        var wb = XLSX.utils.book_new();
        var ws = XLSX.utils.table_to_sheet(newTable);
        XLSX.utils.book_append_sheet(wb, ws, "Rekap Kehadiran");
        XLSX.writeFile(wb, 'rekap_absensi_bulanan_' + '<?php echo str_replace(" ", "_", strtolower($month_names[(int)substr($selected_month, 5, 2)])); ?>' + '_' + '<?php echo substr($selected_month, 0, 4); ?>' + '.xlsx');
        
        // Fix for UI freeze after download
        setTimeout(recoverDropdownUiState, 500);
    } else {
        var a = document.createElement('a');
        var data = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
        a.href = data;
        a.download = 'rekap_absensi_bulanan_' + '<?php echo str_replace(" ", "_", $month_names[(int)substr($selected_month, 5, 2)]); ?>' + '_' + '<?php echo substr($selected_month, 0, 4); ?>' + '.xls';
        a.click();
        setTimeout(recoverDropdownUiState, 500);
    }
}

function exportToPDF() {
    var classId = $('input[name=\"class_id\"]').val() || $('#classSelect').val();
    var monthPicker = $('#monthPicker').val();
    var url = '../admin/cetak_rekap_absensi.php?type=monthly&class_id=' + classId + '&month=' + monthPicker;
    openPrintPreviewTab(url);
}

function exportDailyToPDF() {
    var classId = $('input[name=\"class_id\"]').val() || $('#classSelect').val();
    var datePicker = $('#datePicker').val();
    var url = '../admin/cetak_rekap_absensi.php?type=daily&class_id=' + classId + '&date=' + datePicker;
    openPrintPreviewTab(url);
}

function exportStudentToPDF() {
    var classId = $('input[name=\"class_id\"]').val() || $('#classSelect').val();
    var studentId = $('#studentSelect').val();
    var url = '../admin/cetak_rekap_absensi.php?type=student&class_id=' + classId + '&student_id=' + studentId;
    openPrintPreviewTab(url);
}

function exportSemesterToPDF() {
    var classId = $('input[name="class_id"]').val() || $('#classSelect').val();
    var url = '../admin/cetak_rekap_absensi.php?type=semester&class_id=' + classId;
    openPrintPreviewTab(url);
}

function fallbackPrintPDF() {
    window.print();
}

function exportSemesterToExcel() {
    var container = document.createElement('div');
    var headerDiv = document.createElement('div');
    headerDiv.innerHTML = '<img src="../assets/img/logo_1768301957.png" alt="Logo" style="max-width: 100px; float: left; margin-right: 20px;"><div style="display: inline-block;"><h2>Sistem Informasi Madrasah</h2>';
    headerDiv.innerHTML += '<h3><?php echo addslashes($school_profile['nama_madrasah'] ?? 'Madrasah Ibtidaiyah Negeri Pembina Kota Padang'); ?></h3>';
    headerDiv.innerHTML += '<h4>Tahun Ajaran: ' + academicYear + ' | Semester: ' + activeSemester + '</h4>';
    headerDiv.innerHTML += '<h4>Rekap Kehadiran <?php echo $active_semester; ?> - Tahun <?php echo date('Y'); ?></h4></div><br style="clear: both;">';
    
    var table = document.getElementById('semesterTable');
    if (!table) {
        Swal.fire('Error', 'Tabel semester tidak ditemukan', 'error');
        return;
    }
    var newTable = table.cloneNode(true);
    
    var badges = newTable.querySelectorAll('.badge');
    for (var i = 0; i < badges.length; i++) {
        var badge = badges[i];
        var textNode = document.createTextNode(badge.textContent);
        badge.parentNode.replaceChild(textNode, badge);
    }
    
    container.appendChild(headerDiv);
    container.appendChild(newTable);
    
    var html = container.innerHTML;
    
    if (typeof XLSX !== 'undefined') {
        var wb = XLSX.utils.book_new();
        var ws = XLSX.utils.table_to_sheet(newTable);
        XLSX.utils.book_append_sheet(wb, ws, "Rekap Semester");
        XLSX.writeFile(wb, 'rekap_absensi_' + '<?php echo str_replace(" ", "_", strtolower($active_semester)); ?>' + '_' + '<?php echo date('Y'); ?>' + '.xlsx');
        
        // Fix for UI freeze after download
        setTimeout(recoverDropdownUiState, 500);
    } else {
        var a = document.createElement('a');
        var data = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
        a.href = data;
        a.download = 'rekap_absensi_' + '<?php echo str_replace(" ", "_", strtolower($active_semester)); ?>' + '_' + '<?php echo date('Y'); ?>' + '.xls';
        a.click();
        setTimeout(recoverDropdownUiState, 500);
    }
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
<?php include '../templates/footer.php'; ?>
