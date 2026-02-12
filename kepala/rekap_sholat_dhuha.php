<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha'])) {
    redirect('../login.php');
}

// Set page title
$page_title = 'Rekap Sholat Dhuha';

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
    "https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js",
    // jsPDF libraries for PDF export
    "https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js",
    "https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.29/jspdf.plugin.autotable.min.js"
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

// Define month names array
$month_names = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

// Prepare month name for JavaScript
$js_month_name = '';
$js_month_year = '';
if (!empty($selected_month)) {
    $month_num = (int)substr($selected_month, 5, 2);
    $js_month_name = isset($month_names[$month_num]) ? $month_names[$month_num] : "";
    $js_month_year = substr($selected_month, 0, 4);
}

// Get school profile for semester information
$school_profile = getSchoolProfile($pdo);
$active_semester = $school_profile['semester'] ?? 'Semester 1';
$schoolCity = $school_profile['tempat_jadwal'] ?? 'Padang';
$reportDate = formatDateIndonesia(date('Y-m-d'));
$schoolName = $school_profile['nama_madrasah'] ?? 'Madrasah';

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
    // Fetch all students in class for dropdowns and processing
    $stmt = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
    $stmt->execute([$class_id]);
    $all_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($filter_type == 'daily' && !empty($selected_date)) {
        // Daily filter
        $stmt = $pdo->prepare("SELECT s.id_siswa, s.nama_siswa, s.nisn, k.nama_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_kelas = ? ORDER BY s.nama_siswa ASC");
        $stmt->execute([$class_id]);
        $all_daily_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get sholat data
        $stmt = $pdo->prepare("SELECT id_siswa, status FROM tb_sholat_dhuha WHERE tanggal = ?");
        $stmt->execute([$selected_date]);
        $sholat_records = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // id_siswa => status
        
        // Get daily attendance data (for override)
        $stmt = $pdo->prepare("SELECT id_siswa, keterangan FROM tb_absensi WHERE tanggal = ?");
        $stmt->execute([$selected_date]);
        $absensi_records = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // id_siswa => keterangan

        $daily_results = [];
        foreach ($all_daily_students as $student) {
            $sid = $student['id_siswa'];
            $status_absensi = $absensi_records[$sid] ?? null;
            $status_sholat = $sholat_records[$sid] ?? null;
            
            // Priority Logic
            if (in_array($status_absensi, ['Sakit', 'Izin', 'Alpa'])) {
                $final_status = 'Tidak Hadir';
                $note = "(Absensi: $status_absensi)";
            } elseif ($status_sholat) {
                $final_status = $status_sholat;
                $note = "";
            } elseif ($status_absensi == 'Hadir') {
                // If present in daily attendance but no specific sholat record, assume Hadir
                $final_status = 'Hadir';
                $note = ""; // Optional: "(Auto)";
            } else {
                $final_status = 'Belum Absen';
                $note = "";
            }

            $daily_results[] = [
                'nama_siswa' => $student['nama_siswa'],
                'nisn' => $student['nisn'],
                'keterangan' => $final_status,
                'note' => $note,
                'tanggal' => $selected_date,
                'nama_kelas' => $student['nama_kelas']
            ];
        }

    } elseif ($filter_type == 'monthly' && !empty($selected_month)) {
        // Monthly filter
        $year = substr($selected_month, 0, 4);
        $month = substr($selected_month, 5, 2);
        
        $stmt = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
        $stmt->execute([$class_id]);
        $all_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get sholat data
        $stmt = $pdo->prepare("
            SELECT id_siswa, status, DAY(tanggal) as day
            FROM tb_sholat_dhuha
            WHERE YEAR(tanggal) = ? AND MONTH(tanggal) = ?
        ");
        $stmt->execute([$year, $month]);
        $sholat_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get absensi data
        $stmt = $pdo->prepare("
            SELECT id_siswa, keterangan, DAY(tanggal) as day
            FROM tb_absensi
            WHERE YEAR(tanggal) = ? AND MONTH(tanggal) = ?
        ");
        $stmt->execute([$year, $month]);
        $absensi_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Organize data
        $sholat_by_student = [];
        foreach ($sholat_raw as $r) {
            $sholat_by_student[$r['id_siswa']][$r['day']] = $r['status'];
        }
        
        $absensi_by_student = [];
        foreach ($absensi_raw as $r) {
            $absensi_by_student[$r['id_siswa']][$r['day']] = $r['keterangan'];
        }

        $student_attendance = [];
        foreach ($all_students as $student) {
            $sid = $student['id_siswa'];
            $days = array_fill(1, 31, '');
            $summary = ['Hadir' => 0, 'Tidak Hadir' => 0, 'Berhalangan' => 0];

            for ($d = 1; $d <= 31; $d++) {
                $abs = $absensi_by_student[$sid][$d] ?? null;
                $sho = $sholat_by_student[$sid][$d] ?? null;
                
                if (in_array($abs, ['Sakit', 'Izin', 'Alpa'])) {
                    $status = 'Tidak Hadir';
                } elseif ($sho) {
                    $status = $sho;
                } elseif ($abs === 'Hadir') {
                    $status = 'Hadir';
                } else {
                    $status = '';
                }
                
                $days[$d] = $status;
                if ($status === 'Hadir' || $status === 'Melaksanakan') $summary['Hadir']++;
                if ($status === 'Tidak Hadir' || $status === 'Tidak Melaksanakan') $summary['Tidak Hadir']++;
                if ($status === 'Berhalangan') $summary['Berhalangan']++;
            }

            $student_attendance[] = [
                'nama_siswa' => $student['nama_siswa'],
                'nisn' => $student['nisn'],
                'days' => $days,
                'summary' => $summary
            ];
        }
        $monthly_results = $student_attendance;

    } elseif ($filter_type == 'student' && $selected_student > 0) {
        // Student filter
        
        $stmt = $pdo->prepare("SELECT nama_siswa, nisn, k.nama_kelas FROM tb_siswa s JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_siswa = ?");
        $stmt->execute([$selected_student]);
        $student_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($student_info) {
             $stmt = $pdo->prepare("SELECT status, tanggal FROM tb_sholat_dhuha WHERE id_siswa = ? ORDER BY tanggal DESC");
             $stmt->execute([$selected_student]);
             $sholat_recs = $stmt->fetchAll(PDO::FETCH_ASSOC);
             
             $stmt = $pdo->prepare("SELECT keterangan, tanggal FROM tb_absensi WHERE id_siswa = ? ORDER BY tanggal DESC");
             $stmt->execute([$selected_student]);
             $absensi_recs = $stmt->fetchAll(PDO::FETCH_ASSOC);
             
             // Merge by date
             $dates = [];
             foreach ($sholat_recs as $r) $dates[$r['tanggal']] = true;
             foreach ($absensi_recs as $r) $dates[$r['tanggal']] = true;
             krsort($dates); // Sort by date DESC
             
             $sholat_map = [];
             foreach ($sholat_recs as $r) $sholat_map[$r['tanggal']] = $r['status'];
             
             $absensi_map = [];
             foreach ($absensi_recs as $r) $absensi_map[$r['tanggal']] = $r['keterangan'];
             
             $summary = ['Hadir' => 0, 'Tidak Hadir' => 0, 'Berhalangan' => 0];
             
             foreach (array_keys($dates) as $date) {
                 $abs = $absensi_map[$date] ?? null;
                 $sho = $sholat_map[$date] ?? null;
                 
                 if (in_array($abs, ['Sakit', 'Izin', 'Alpa'])) {
                 $final = 'Tidak Hadir';
                 $note = "(Absensi: $abs)";
             } elseif ($sho) {
                 $final = $sho;
                 $note = "";
             } elseif ($abs === 'Hadir') {
                 $final = 'Hadir';
                 $note = "";
             } else {
                 continue; 
             }
                 
                 $student_results[] = [
                     'tanggal' => $date,
                     'keterangan' => $final,
                     'note' => $note,
                     'nama_siswa' => $student_info['nama_siswa'],
                     'nisn' => $student_info['nisn'],
                     'nama_kelas' => $student_info['nama_kelas']
                 ];
                 
                 if ($final === 'Hadir' || $final === 'Melaksanakan') $summary['Hadir']++;
                 if ($final === 'Tidak Hadir' || $final === 'Tidak Melaksanakan') $summary['Tidak Hadir']++;
                 if ($final === 'Berhalangan') $summary['Berhalangan']++;
             }
             $student_attendance_summary = $summary;
        }

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
        
        $stmt = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
        $stmt->execute([$class_id]);
        $all_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch all records for the semester
        $stmt = $pdo->prepare("
            SELECT id_siswa, status, MONTH(tanggal) as month, DAY(tanggal) as day
            FROM tb_sholat_dhuha
            WHERE id_siswa IN (SELECT id_siswa FROM tb_siswa WHERE id_kelas = ?)
            AND YEAR(tanggal) = ? AND MONTH(tanggal) BETWEEN ? AND ?
        ");
        $stmt->execute([$class_id, $query_year, $start_month, $end_month]);
        $sholat_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("
            SELECT id_siswa, keterangan, MONTH(tanggal) as month, DAY(tanggal) as day
            FROM tb_absensi
            WHERE id_siswa IN (SELECT id_siswa FROM tb_siswa WHERE id_kelas = ?)
            AND YEAR(tanggal) = ? AND MONTH(tanggal) BETWEEN ? AND ?
        ");
        $stmt->execute([$class_id, $query_year, $start_month, $end_month]);
        $absensi_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Organize
        $sholat_map = [];
        foreach ($sholat_raw as $r) {
            $sholat_map[$r['id_siswa']][$r['month']][$r['day']] = $r['status'];
        }
        
        $absensi_map = [];
        foreach ($absensi_raw as $r) {
            $absensi_map[$r['id_siswa']][$r['month']][$r['day']] = $r['keterangan'];
        }
        
        $semester_results = [];
        foreach ($all_students as $student) {
            $sid = $student['id_siswa'];
            $monthly_totals = [];
            $sem_summary = ['Hadir' => 0, 'Tidak Hadir' => 0, 'Berhalangan' => 0];
            
            for ($m = $start_month; $m <= $end_month; $m++) {
                $monthly_totals[$m] = ['Hadir' => 0, 'Tidak Hadir' => 0, 'Berhalangan' => 0];
                // Loop through days 1-31
                for ($d = 1; $d <= 31; $d++) {
                    $abs = $absensi_map[$sid][$m][$d] ?? null;
                    $sho = $sholat_map[$sid][$m][$d] ?? null;
                    
                    if (in_array($abs, ['Sakit', 'Izin', 'Alpa'])) {
                        $st = 'Tidak Hadir';
                    } elseif ($sho) {
                        $st = $sho;
                    } elseif ($abs === 'Hadir') {
                        $st = 'Hadir';
                    } else {
                        $st = null;
                    }
                    
                    if ($st === 'Hadir' || $st === 'Melaksanakan') {
                        $monthly_totals[$m]['Hadir']++;
                        $sem_summary['Hadir']++;
                    } elseif ($st === 'Tidak Hadir' || $st === 'Tidak Melaksanakan') {
                        $monthly_totals[$m]['Tidak Hadir']++;
                        $sem_summary['Tidak Hadir']++;
                    } elseif ($st === 'Berhalangan') {
                        $monthly_totals[$m]['Berhalangan']++;
                        $sem_summary['Berhalangan']++;
                    }
                }
            }
            
            $semester_results[] = [
                'nama_siswa' => $student['nama_siswa'],
                'nisn' => $student['nisn'],
                'monthly_totals' => $monthly_totals,
                'summary' => $sem_summary
            ];
        }
    }
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<style>
    #semesterTable { font-size: 10pt; color: black; table-layout: auto; }
    #semesterTable thead th { text-align: center; vertical-align: middle; background-color: #f8f9fa; font-weight: bold; color: black; }
    #semesterTable tbody td { color: black; white-space: nowrap; }
    #semesterTable tbody td:nth-child(2) { white-space: normal; text-align: left; }
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Rekap Sholat Dhuha</h1>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-header"><h4>Filter Rekap Sholat Dhuha</h4></div>
                <div class="card-body">
                    <form method="POST" class="row">
                        <div class="form-group col-md-3">
                            <label>Kelas</label>
                            <select name="class_id" class="form-control selectric" onchange="this.form.submit()">
                                <option value="">Pilih Kelas...</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id_kelas']; ?>" <?php echo ($class_id == $class['id_kelas']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['nama_kelas']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group col-md-3">
                            <label>Jenis Filter</label>
                            <select name="filter_type" class="form-control selectric" onchange="this.form.submit()">
                                <option value="daily" <?php echo ($filter_type == 'daily') ? 'selected' : ''; ?>>Harian</option>
                                <option value="monthly" <?php echo ($filter_type == 'monthly') ? 'selected' : ''; ?>>Bulanan</option>
                                <option value="semester" <?php echo ($filter_type == 'semester') ? 'selected' : ''; ?>>Per Semester</option>
                                <option value="student" <?php echo ($filter_type == 'student') ? 'selected' : ''; ?>>Per Siswa</option>
                            </select>
                        </div>
                        
                        <?php if ($filter_type == 'daily'): ?>
                        <div class="form-group col-md-3">
                            <label>Tanggal</label>
                            <input type="date" name="attendance_date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>">
                        </div>
                        <?php elseif ($filter_type == 'monthly'): ?>
                        <div class="form-group col-md-3">
                            <label>Bulan</label>
                            <input type="month" name="month_picker" class="form-control" value="<?php echo htmlspecialchars($selected_month); ?>">
                        </div>
                        <?php elseif ($filter_type == 'student'): ?>
                        <div class="form-group col-md-3">
                            <label>Siswa</label>
                            <select name="student_id" class="form-control selectric">
                                <option value="">Pilih Siswa...</option>
                                <?php if (isset($all_students)): foreach($all_students as $s): ?>
                                    <option value="<?php echo $s['id_siswa']; ?>" <?php echo ($selected_student == $s['id_siswa']) ? 'selected' : ''; ?>><?php echo $s['nama_siswa']; ?></option>
                                <?php endforeach; endif; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="form-group col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-search"></i> Cari</button>
                        </div>
                    </form>

                    <?php if (!empty($daily_results)): ?>
                        <!-- Export Buttons -->
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <div class="btn-group float-right" role="group">
                                    <button type="button" class="btn btn-success" onclick="exportDailyToExcel()">
                                        <i class="fas fa-file-excel"></i> Ekspor Excel
                                    </button>
                                    <button type="button" class="btn btn-warning" onclick="exportDailyToPDF()">
                                        <i class="fas fa-file-pdf"></i> Ekspor PDF
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive mt-4">
                            <table class="table table-striped" id="dailyTable">
                                <thead>
                                    <tr>
                                        <th>No</th>
                                        <th>Nama Siswa</th>
                                        <th>Status</th>
                                        <th>Keterangan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $no = 1; foreach ($daily_results as $r): ?>
                                    <tr>
                                        <td><?php echo $no++; ?></td>
                                        <td><?php echo htmlspecialchars($r['nama_siswa']); ?></td>
                                        <td>
                                            <?php if ($r['keterangan'] == 'Hadir'): ?>
                                                <span class="badge badge-success">Hadir</span>
                                            <?php elseif ($r['keterangan'] == 'Berhalangan'): ?>
                                                <span class="badge badge-warning">Berhalangan</span>
                                            <?php elseif ($r['keterangan'] == 'Tidak Hadir'): ?>
                                                <span class="badge badge-danger">Tidak Hadir</span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">Belum Absen</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $r['note']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    <?php elseif (!empty($monthly_results)): ?>
                        <!-- Export Buttons -->
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <div class="btn-group float-right" role="group">
                                    <button type="button" class="btn btn-success" onclick="exportMonthlyToExcel()">
                                        <i class="fas fa-file-excel"></i> Ekspor Excel
                                    </button>
                                    <button type="button" class="btn btn-warning" onclick="exportMonthlyToPDF()">
                                        <i class="fas fa-file-pdf"></i> Ekspor PDF
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive mt-4">
                            <table class="table table-bordered text-center table-sm" style="font-size: 0.9em;" id="monthlyTable">
                                <thead>
                                    <tr>
                                        <th rowspan="2" style="vertical-align: middle;">No</th>
                                        <th rowspan="2" style="vertical-align: middle; text-align: left;">Nama Siswa</th>
                                        <th colspan="31">Tanggal</th>
                                        <th colspan="3">Total</th>
                                    </tr>
                                    <tr>
                                        <?php for($d=1; $d<=31; $d++) echo "<th>$d</th>"; ?>
                                        <th>H</th>
                                        <th>TH</th>
                                        <th>B</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($monthly_results as $idx => $r): ?>
                                    <tr>
                                        <td><?php echo $idx + 1; ?></td>
                                        <td class="text-left"><?php echo htmlspecialchars($r['nama_siswa']); ?></td>
                                        <?php for($d=1; $d<=31; $d++): ?>
                                            <td>
                                                <?php 
                                                    $s = $r['days'][$d];
                                                    if ($s === 'Hadir') echo '<i class="fas fa-check text-success"></i>';
                                                    elseif ($s === 'Berhalangan') echo '<i class="fas fa-ban text-warning"></i>';
                                                    elseif ($s === 'Tidak Hadir') echo '<i class="fas fa-times text-danger"></i>';
                                                ?>
                                            </td>
                                        <?php endfor; ?>
                                        <td><?php echo $r['summary']['Hadir']; ?></td>
                                        <td><?php echo $r['summary']['Tidak Hadir']; ?></td>
                                        <td><?php echo $r['summary']['Berhalangan']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    <?php elseif (!empty($semester_results)): ?>
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

                        <div class="table-responsive mt-4">
                            <table class="table table-bordered" id="semesterTable">
                                <thead>
                                    <tr>
                                        <th rowspan="2">No</th>
                                        <th rowspan="2">Nama Siswa</th>
                                        <?php 
                                            $start_month = ($active_semester == 'Semester 1') ? 7 : 1;
                                            $end_month = ($active_semester == 'Semester 1') ? 12 : 6;
                                            for ($m = $start_month; $m <= $end_month; $m++) {
                                                echo "<th colspan='2' class='text-center'>{$month_names[$m]}</th>";
                                            }
                                        ?>
                                        <th colspan="3" class="text-center">Total</th>
                                    </tr>
                                    <tr>
                                        <?php for ($m = $start_month; $m <= $end_month; $m++): ?>
                                            <th>H</th>
                                            <th>TH</th>
                                        <?php endfor; ?>
                                        <th>H</th>
                                        <th>TH</th>
                                        <th>B</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($semester_results as $idx => $r): ?>
                                    <tr>
                                        <td><?php echo $idx + 1; ?></td>
                                        <td><?php echo htmlspecialchars($r['nama_siswa']); ?></td>
                                        <?php 
                                            for ($m = $start_month; $m <= $end_month; $m++) {
                                                echo '<td class="text-center">' . ($r['monthly_totals'][$m]['Hadir'] ?: '-') . '</td>';
                                                echo '<td class="text-center">' . ($r['monthly_totals'][$m]['Tidak Hadir'] ?: '-') . '</td>';
                                            }
                                            echo '<td class="text-center font-weight-bold">' . $r['summary']['Hadir'] . '</td>';
                                            echo '<td class="text-center font-weight-bold">' . $r['summary']['Tidak Hadir'] . '</td>';
                                        ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    <?php elseif (!empty($student_results)): ?>
                        <!-- Export Buttons -->
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <div class="btn-group float-right" role="group">
                                    <button type="button" class="btn btn-success" onclick="exportStudentToExcel()">
                                        <i class="fas fa-file-excel"></i> Ekspor Excel
                                    </button>
                                    <button type="button" class="btn btn-warning" onclick="exportStudentToPDF()">
                                        <i class="fas fa-file-pdf"></i> Ekspor PDF
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <td width="150">Nama Siswa</td>
                                            <td>: <strong><?php echo htmlspecialchars($student_results[0]['nama_siswa']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <td>NISN</td>
                                            <td>: <?php echo htmlspecialchars($student_results[0]['nisn']); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Kelas</td>
                                            <td>: <?php echo htmlspecialchars($student_results[0]['nama_kelas']); ?></td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-6 text-right">
                                    <div class="d-inline-block p-2 bg-success text-white rounded text-center mr-3" style="min-width: 100px;">
                                        <h3 class="mb-0"><?php echo $student_attendance_summary['Hadir']; ?></h3>
                                        <small>Total Hadir</small>
                                    </div>
                                    <div class="d-inline-block p-2 bg-danger text-white rounded text-center" style="min-width: 100px;">
                                        <h3 class="mb-0"><?php echo $student_attendance_summary['Tidak Hadir']; ?></h3>
                                        <small>Total Tidak Hadir</small>
                                    </div>
                                    <div class="d-inline-block p-2 bg-warning text-white rounded text-center ml-3" style="min-width: 100px;">
                                        <h3 class="mb-0"><?php echo $student_attendance_summary['Berhalangan']; ?></h3>
                                        <small>Total Berhalangan</small>
                                    </div>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-striped" id="studentTable">
                                    <thead>
                                        <tr>
                                            <th>No</th>
                                            <th>Tanggal</th>
                                            <th>Status</th>
                                            <th>Keterangan</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $no = 1; foreach ($student_results as $r): ?>
                                        <tr>
                                            <td><?php echo $no++; ?></td>
                                            <td><?php echo date('d-m-Y', strtotime($r['tanggal'])); ?></td>
                                            <td>
                                                <?php if ($r['keterangan'] == 'Hadir'): ?>
                                                    <span class="badge badge-success">Hadir</span>
                                                <?php elseif ($r['keterangan'] == 'Tidak Hadir'): ?>
                                                    <span class="badge badge-danger">Tidak Hadir</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $r['note']; ?></td>
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
    </section>
</div>

<?php include '../templates/footer.php'; ?>

<!-- Export Functions -->
<?php 
// Prepare data for JavaScript
$madrasah_head = addslashes(htmlspecialchars($school_profile['kepala_madrasah'] ?? 'Kepala Madrasah', ENT_QUOTES, 'UTF-8'));
$class_teacher = addslashes(htmlspecialchars($class_info['wali_kelas'] ?? 'Wali Kelas', ENT_QUOTES, 'UTF-8'));
$school_logo = $school_profile['logo'] ?? 'logo.png';
$madrasah_head_signature = $school_profile['ttd_kepala'] ?? '';
$school_city = addslashes(htmlspecialchars($school_profile['tempat_jadwal'] ?? 'Kota', ENT_QUOTES, 'UTF-8'));
$report_date = formatDateIndonesia(date('Y-m-d'));
?>
<script>
// Pass actual names to JavaScript
var madrasahHeadName = '<?php echo $madrasah_head; ?>';
var classTeacherName = '<?php echo $class_teacher; ?>';
var schoolLogo = '<?php echo $school_logo; ?>';
var madrasahHeadSignature = '<?php echo $madrasah_head_signature; ?>';
var schoolCity = '<?php echo $school_city; ?>';
var reportDate = '<?php echo $report_date; ?>';
var studentName = '<?php echo isset($student_results[0]) ? addslashes($student_results[0]['nama_siswa']) : ""; ?>';
var selectedDate = '<?php echo htmlspecialchars($selected_date); ?>';

function replaceIconsWithText(tableClone) {
    // Replace check icons with "v"
    var checkIcons = tableClone.querySelectorAll('.fa-check');
    checkIcons.forEach(function(icon) {
        var textNode = document.createTextNode('v');
        icon.parentNode.replaceChild(textNode, icon);
    });

    // Replace times icons with "x"
    var timesIcons = tableClone.querySelectorAll('.fa-times');
    timesIcons.forEach(function(icon) {
        var textNode = document.createTextNode('x');
        icon.parentNode.replaceChild(textNode, icon);
    });
    
    // Replace ban icons with "b"
    var banIcons = tableClone.querySelectorAll('.fa-ban');
    banIcons.forEach(function(icon) {
        var textNode = document.createTextNode('b');
        icon.parentNode.replaceChild(textNode, icon);
    });

    // Replace badges with text
    var badges = tableClone.querySelectorAll('.badge');
    badges.forEach(function(badge) {
        var text = badge.textContent.trim();
        var textNode = document.createTextNode(text);
        badge.parentNode.replaceChild(textNode, badge);
    });
}

function exportMonthlyToExcel() {
    var container = document.createElement('div');
    var headerDiv = document.createElement('div');
    headerDiv.innerHTML = '<img src="../assets/img/' + schoolLogo + '" alt="Logo" style="max-width: 100px; float: left; margin-right: 20px;"><div style="display: inline-block;"><h2>Sistem Absensi Siswa</h2>';
    headerDiv.innerHTML += '<h3><?php echo htmlspecialchars($school_profile["nama_madrasah"] ?? "Madrasah Ibtidaiyah Negeri Pembina Kota Padang", ENT_QUOTES, "UTF-8"); ?></h3>';
    headerDiv.innerHTML += '<h4>Rekap Sholat Dhuha - <?php echo htmlspecialchars($js_month_name . " " . $js_month_year, ENT_QUOTES, "UTF-8"); ?></h4></div><br style="clear: both;">';
    
    var table = document.getElementById('monthlyTable');
    if (!table) { alert('Tabel tidak ditemukan'); return; }
    
    var newTable = table.cloneNode(true);
    replaceIconsWithText(newTable);
    
    container.appendChild(headerDiv);
    container.appendChild(newTable);
    
    var html = container.innerHTML;
    
    if (typeof XLSX !== 'undefined') {
        var wb = XLSX.utils.book_new();
        var ws = XLSX.utils.table_to_sheet(newTable);
        XLSX.utils.book_append_sheet(wb, ws, "Rekap Sholat Dhuha");
        XLSX.writeFile(wb, 'rekap_sholat_dhuha_<?php echo htmlspecialchars($js_month_name . "_" . $js_month_year, ENT_QUOTES, "UTF-8"); ?>.xlsx');
    } else {
        var a = document.createElement('a');
        var data = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
        a.href = data;
        a.download = 'rekap_sholat_dhuha.xls';
        a.click();
    }
}

function exportMonthlyToPDF() {
    var printWindow = window.open('', '', 'height=860,width=1300');
    printWindow.document.write('<html><head><title>Rekap Sholat Dhuha</title>');
    printWindow.document.write('<style>');
    printWindow.document.write('@page { size: legal landscape; margin: 0.5cm; }');
    printWindow.document.write('body { font-family: Arial, sans-serif; margin: 0; padding: 10px; }');
    printWindow.document.write('table { border-collapse: collapse; width: 100%; font-size: 11px; margin-bottom: 20px; }');
    printWindow.document.write('th, td { border: 1px solid #ddd; padding: 4px; text-align: center; }');
    printWindow.document.write('td:nth-child(2) { text-align: left; white-space: nowrap; }');
    printWindow.document.write('th { background-color: #f2f2f2; font-weight: bold; }');
    printWindow.document.write('.header { text-align: center; margin-bottom: 15px; }');
    printWindow.document.write('.fa-check { color: green; font-family: sans-serif; font-style: normal; } .fa-check:before { content: "v"; }');
    printWindow.document.write('.fa-times { color: red; font-family: sans-serif; font-style: normal; } .fa-times:before { content: "x"; }');
    printWindow.document.write('.signature-wrapper { margin-top: 30px; display: flex; justify-content: space-between; width: 100%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.signature-box { text-align: center; width: 45%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.print-btn { position: fixed; top: 20px; right: 20px; padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; z-index: 9999; }');
    printWindow.document.write('.print-btn:hover { background: #0056b3; }');
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write('<button class="print-btn no-print" onclick="window.print()"><i class="fas fa-print"></i> Cetak / Simpan PDF</button>');
    printWindow.document.write('<div class="header">');
    printWindow.document.write('<img src="../assets/img/' + schoolLogo + '" alt="Logo" style="max-width: 80px; vertical-align: middle; margin-right: 15px;">');
    printWindow.document.write('<div style="display: inline-block; vertical-align: middle;">');
    printWindow.document.write('<h2 style="margin: 0;">Sistem Absensi Siswa</h2>');
    printWindow.document.write('<h3 style="margin: 5px 0;"><?php echo htmlspecialchars($school_profile["nama_madrasah"] ?? "Madrasah Ibtidaiyah Negeri Pembina Kota Padang", ENT_QUOTES, "UTF-8"); ?></h3>');
    printWindow.document.write('<h4 style="margin: 0;">Rekap Sholat Dhuha - <?php echo htmlspecialchars($js_month_name . " " . $js_month_year, ENT_QUOTES, "UTF-8"); ?></h4></div>');
    printWindow.document.write('</div>');
    
    var table = document.getElementById('monthlyTable');
    if (table) {
        var tableHTML = table.outerHTML;
        // For PDF print, we can rely on CSS to style icons or replace them
        // Let's replace icons with characters for simplicity in print view
        tableHTML = tableHTML.replace(/<i class="fas fa-check[^"]*"><\/i>/g, 'v');
        tableHTML = tableHTML.replace(/<i class="fas fa-times[^"]*"><\/i>/g, 'x');
        tableHTML = tableHTML.replace(/<i class="fas fa-ban[^"]*"><\/i>/g, 'b');
        printWindow.document.write(tableHTML);
    }
    
    printWindow.document.write('<div class="signature-wrapper">');
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p>' + schoolCity + ', ' + reportDate + '</p>');
    printWindow.document.write('<p>Wali Kelas,</p>');
    if (classTeacherName) {
        var qrContentWali = 'Validasi Tanda Tangan Digital: ' + classTeacherName + ' - ' + schoolName;
        var qrUrlWali = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' + encodeURIComponent(qrContentWali);
        printWindow.document.write('<img src="' + qrUrlWali + '" alt="QR Signature" style="width: 80px; height: 80px; margin: 10px auto; display: block;">');
        printWindow.document.write('<p style="font-size: 10px; margin-top: 0;">(Ditandatangani secara digital)</p>');
    } else {
        printWindow.document.write('<br><br><br>');
    }
    printWindow.document.write('<p><strong>' + classTeacherName + '</strong></p>');
    printWindow.document.write('</div>');
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p>' + schoolCity + ', ' + reportDate + '</p>');
    printWindow.document.write('<p>Kepala Madrasah,</p>');
    if (madrasahHeadSignature) {
        var qrContentKepala = 'Validasi Tanda Tangan Digital: ' + madrasahHeadName + ' - ' + schoolName;
        var qrUrlKepala = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' + encodeURIComponent(qrContentKepala);
        printWindow.document.write('<img src="' + qrUrlKepala + '" alt="QR Signature" style="width: 80px; height: 80px; margin: 10px auto; display: block;">');
        printWindow.document.write('<p style="font-size: 10px; margin-top: 0;">(Ditandatangani secara digital)</p>');
    } else {
        printWindow.document.write('<br><br><br>');
    }
    printWindow.document.write('<p><strong>' + madrasahHeadName + '</strong></p></div>');
    printWindow.document.write('</div>');
    
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.focus();
    setTimeout(function() { printWindow.print(); }, 500);
}

function exportSemesterToExcel() {
    var container = document.createElement('div');
    var headerDiv = document.createElement('div');
    headerDiv.innerHTML = '<img src="../assets/img/' + schoolLogo + '" alt="Logo" style="max-width: 100px; float: left; margin-right: 20px;"><div style="display: inline-block;"><h2>Sistem Absensi Siswa</h2>';
    headerDiv.innerHTML += '<h3><?php echo htmlspecialchars($school_profile["nama_madrasah"] ?? "Madrasah Ibtidaiyah Negeri Pembina Kota Padang", ENT_QUOTES, "UTF-8"); ?></h3>';
    headerDiv.innerHTML += '<h4>Rekap Sholat Dhuha <?php echo htmlspecialchars($active_semester, ENT_QUOTES, "UTF-8"); ?></h4></div><br style="clear: both;">';
    
    var table = document.getElementById('semesterTable');
    if (!table) { alert('Tabel tidak ditemukan'); return; }
    
    var newTable = table.cloneNode(true);
    replaceIconsWithText(newTable);
    
    container.appendChild(headerDiv);
    container.appendChild(newTable);
    
    if (typeof XLSX !== 'undefined') {
        var wb = XLSX.utils.book_new();
        var ws = XLSX.utils.table_to_sheet(newTable);
        XLSX.utils.book_append_sheet(wb, ws, "Rekap Semester");
        XLSX.writeFile(wb, 'rekap_sholat_dhuha_semester_<?php echo htmlspecialchars($active_semester, ENT_QUOTES, "UTF-8"); ?>.xlsx');
    } else {
        // Fallback
        var html = container.innerHTML;
        var a = document.createElement('a');
        var data = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
        a.href = data;
        a.download = 'rekap_sholat_dhuha_semester.xls';
        a.click();
    }
}

function exportSemesterToPDF() {
    var printWindow = window.open('', '', 'height=860,width=1300');
    printWindow.document.write('<html><head><title>Rekap Sholat Dhuha Semester</title>');
    printWindow.document.write('<style>');
    printWindow.document.write('@page { size: legal landscape; margin: 0.5cm; }');
    printWindow.document.write('body { font-family: Arial, sans-serif; margin: 0; padding: 10px; }');
    printWindow.document.write('table { border-collapse: collapse; width: 100%; font-size: 11px; margin-bottom: 20px; }');
    printWindow.document.write('th, td { border: 1px solid #ddd; padding: 4px; text-align: center; }');
    printWindow.document.write('td:nth-child(2) { text-align: left; white-space: nowrap; }');
    printWindow.document.write('th { background-color: #f2f2f2; font-weight: bold; }');
    printWindow.document.write('.header { text-align: center; margin-bottom: 15px; }');
    printWindow.document.write('.signature-wrapper { margin-top: 30px; display: flex; justify-content: space-between; width: 100%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.signature-box { text-align: center; width: 45%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.print-btn { position: fixed; top: 20px; right: 20px; padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; z-index: 9999; }');
    printWindow.document.write('.print-btn:hover { background: #0056b3; }');
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write('<button class="print-btn no-print" onclick="window.print()"><i class="fas fa-print"></i> Cetak / Simpan PDF</button>');
    printWindow.document.write('<div class="header">');
    printWindow.document.write('<img src="../assets/img/' + schoolLogo + '" alt="Logo" style="max-width: 80px; vertical-align: middle; margin-right: 15px;">');
    printWindow.document.write('<div style="display: inline-block; vertical-align: middle;">');
    printWindow.document.write('<h2 style="margin: 0;">Sistem Absensi Siswa</h2>');
    printWindow.document.write('<h3 style="margin: 5px 0;"><?php echo htmlspecialchars($school_profile["nama_madrasah"] ?? "Madrasah Ibtidaiyah Negeri Pembina Kota Padang", ENT_QUOTES, "UTF-8"); ?></h3>');
    printWindow.document.write('<h4 style="margin: 0;">Rekap Sholat Dhuha <?php echo htmlspecialchars($active_semester, ENT_QUOTES, "UTF-8"); ?></h4></div>');
    printWindow.document.write('</div>');
    
    var table = document.getElementById('semesterTable');
    if (table) {
        printWindow.document.write(table.outerHTML);
    }
    
    printWindow.document.write('<div class="signature-wrapper">');
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p><br>Wali Kelas,</p>');
    if (classTeacherName) {
        var qrContentWali = 'Validasi Tanda Tangan Digital: ' + classTeacherName + ' - <?php echo addslashes($school_profile["nama_madrasah"] ?? "Madrasah"); ?>';
        var qrUrlWali = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' + encodeURIComponent(qrContentWali);
        printWindow.document.write('<img src="' + qrUrlWali + '" alt="QR Signature" style="width: 80px; height: 80px; margin: 10px auto; display: block;">');
        printWindow.document.write('<p style="font-size: 10px; margin-top: 0;">(Ditandatangani secara digital)</p>');
    } else {
        printWindow.document.write('<br><br><br>');
    }
    printWindow.document.write('<p><strong>' + classTeacherName + '</strong></p>');
    printWindow.document.write('</div>');
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p>' + schoolCity + ', ' + reportDate + '<br>Kepala Madrasah,</p>');
    if (madrasahHeadSignature) {
        var qrContentHead = 'Validasi Tanda Tangan Digital: ' + madrasahHeadName + ' - <?php echo addslashes($school_profile["nama_madrasah"] ?? "Madrasah"); ?>';
        var qrUrlHead = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' + encodeURIComponent(qrContentHead);
        printWindow.document.write('<img src="' + qrUrlHead + '" alt="QR Signature" style="width: 80px; height: 80px; margin: 10px auto; display: block;">');
        printWindow.document.write('<p style="font-size: 10px; margin-top: 0;">(Ditandatangani secara digital)</p>');
    } else {
        printWindow.document.write('<br><br><br>');
    }
    printWindow.document.write('<p><strong>' + madrasahHeadName + '</strong></p></div>');
    printWindow.document.write('</div>');
    
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.focus();
    setTimeout(function() { printWindow.print(); }, 500);
}

function exportDailyToExcel() {
    var container = document.createElement('div');
    var headerDiv = document.createElement('div');
    headerDiv.innerHTML = '<img src="../assets/img/' + schoolLogo + '" alt="Logo" style="max-width: 100px; float: left; margin-right: 20px;"><div style="display: inline-block;"><h2>Sistem Absensi Siswa</h2>';
    headerDiv.innerHTML += '<h3><?php echo htmlspecialchars($school_profile["nama_madrasah"] ?? "Madrasah Ibtidaiyah Negeri Pembina Kota Padang", ENT_QUOTES, "UTF-8"); ?></h3>';
    headerDiv.innerHTML += '<h4>Rekap Harian Sholat Dhuha - ' + selectedDate.split('-').reverse().join('-') + '</h4></div><br style="clear: both;">';
    
    var table = document.getElementById('dailyTable');
    if (!table) { alert('Tabel tidak ditemukan'); return; }
    
    var newTable = table.cloneNode(true);
    replaceIconsWithText(newTable);
    
    container.appendChild(headerDiv);
    container.appendChild(newTable);
    
    if (typeof XLSX !== 'undefined') {
        var wb = XLSX.utils.book_new();
        var ws = XLSX.utils.table_to_sheet(newTable);
        XLSX.utils.book_append_sheet(wb, ws, "Rekap Harian");
        XLSX.writeFile(wb, 'rekap_sholat_dhuha_harian_' + selectedDate + '.xlsx');
    } else {
        var html = container.innerHTML;
        var a = document.createElement('a');
        var data = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
        a.href = data;
        a.download = 'rekap_sholat_dhuha_harian.xls';
        a.click();
    }
}

function exportDailyToPDF() {
    var printWindow = window.open('', '', 'height=860,width=1300');
    printWindow.document.write('<html><head><title>Rekap Harian Sholat Dhuha</title>');
    printWindow.document.write('<style>');
    printWindow.document.write('@page { size: legal portrait; margin: 0.5cm; }');
    printWindow.document.write('body { font-family: Arial, sans-serif; margin: 0; padding: 10px; }');
    printWindow.document.write('table { border-collapse: collapse; width: 100%; font-size: 11px; margin-bottom: 20px; }');
    printWindow.document.write('th, td { border: 1px solid #ddd; padding: 4px; text-align: left; }');
    printWindow.document.write('th { background-color: #f2f2f2; font-weight: bold; }');
    printWindow.document.write('.header { text-align: center; margin-bottom: 15px; }');
    printWindow.document.write('.badge { padding: 2px 5px; border-radius: 3px; font-size: 10px; }');
    printWindow.document.write('.badge-success { background-color: #28a745; color: white; }');
    printWindow.document.write('.badge-danger { background-color: #dc3545; color: white; }');
    printWindow.document.write('.badge-secondary { background-color: #6c757d; color: white; }');
    printWindow.document.write('.signature-wrapper { margin-top: 30px; display: flex; justify-content: space-between; width: 100%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.signature-box { text-align: center; width: 45%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.print-btn { position: fixed; top: 20px; right: 20px; padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; z-index: 9999; }');
    printWindow.document.write('.print-btn:hover { background: #0056b3; }');
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write('<button class="print-btn no-print" onclick="window.print()"><i class="fas fa-print"></i> Cetak / Simpan PDF</button>');
    printWindow.document.write('<div class="header">');
    printWindow.document.write('<img src="../assets/img/' + schoolLogo + '" alt="Logo" style="max-width: 80px; vertical-align: middle; margin-right: 15px;">');
    printWindow.document.write('<div style="display: inline-block; vertical-align: middle;">');
    printWindow.document.write('<h2 style="margin: 0;">Sistem Absensi Siswa</h2>');
    printWindow.document.write('<h3 style="margin: 5px 0;"><?php echo htmlspecialchars($school_profile["nama_madrasah"] ?? "Madrasah Ibtidaiyah Negeri Pembina Kota Padang", ENT_QUOTES, "UTF-8"); ?></h3>');
    printWindow.document.write('<h4 style="margin: 0;">Rekap Harian Sholat Dhuha - ' + selectedDate.split('-').reverse().join('-') + '</h4></div>');
    printWindow.document.write('</div>');
    
    var table = document.getElementById('dailyTable');
    if (table) {
        var newTable = table.cloneNode(true);
        var badges = newTable.querySelectorAll('.badge');
        badges.forEach(function(badge) {
            var text = badge.textContent;
            var textNode = document.createTextNode(text);
            badge.parentNode.replaceChild(textNode, badge);
        });
        printWindow.document.write(newTable.outerHTML);
    }
    
    printWindow.document.write('<div class="signature-wrapper">');
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p><br>Wali Kelas,</p>');
    if (classTeacherName) {
        var qrContentWali = 'Validasi Tanda Tangan Digital: ' + classTeacherName + ' - <?php echo addslashes($school_profile["nama_madrasah"] ?? "Madrasah"); ?>';
        var qrUrlWali = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' + encodeURIComponent(qrContentWali);
        printWindow.document.write('<img src="' + qrUrlWali + '" alt="QR Signature" style="width: 80px; height: 80px; margin: 10px auto; display: block;">');
        printWindow.document.write('<p style="font-size: 10px; margin-top: 0;">(Ditandatangani secara digital)</p>');
    } else {
        printWindow.document.write('<br><br><br>');
    }
    printWindow.document.write('<p><strong>' + classTeacherName + '</strong></p>');
    printWindow.document.write('</div>');
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p>' + schoolCity + ', ' + reportDate + '<br>Kepala Madrasah,</p>');
    if (madrasahHeadSignature) {
        var qrContentHead = 'Validasi Tanda Tangan Digital: ' + madrasahHeadName + ' - <?php echo addslashes($school_profile["nama_madrasah"] ?? "Madrasah"); ?>';
        var qrUrlHead = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' + encodeURIComponent(qrContentHead);
        printWindow.document.write('<img src="' + qrUrlHead + '" alt="QR Signature" style="width: 80px; height: 80px; margin: 10px auto; display: block;">');
        printWindow.document.write('<p style="font-size: 10px; margin-top: 0;">(Ditandatangani secara digital)</p>');
    } else {
        printWindow.document.write('<br><br><br>');
    }
    printWindow.document.write('<p><strong>' + madrasahHeadName + '</strong></p></div>');
    printWindow.document.write('</div>');
    
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.focus();
    setTimeout(function() { printWindow.print(); }, 500);
}

function exportStudentToExcel() {
    var container = document.createElement('div');
    var headerDiv = document.createElement('div');
    
    headerDiv.innerHTML = '<img src="../assets/img/' + schoolLogo + '" alt="Logo" style="max-width: 100px; float: left; margin-right: 20px;"><div style="display: inline-block;"><h2>Sistem Absensi Siswa</h2>';
    headerDiv.innerHTML += '<h3><?php echo htmlspecialchars($school_profile["nama_madrasah"] ?? "Madrasah Ibtidaiyah Negeri Pembina Kota Padang", ENT_QUOTES, "UTF-8"); ?></h3>';
    headerDiv.innerHTML += '<h4>Rekap Sholat Dhuha Siswa: ' + studentName + '</h4></div><br style="clear: both;">';
    
    var table = document.getElementById('studentTable');
    if (!table) { alert('Tabel tidak ditemukan'); return; }
    
    var newTable = table.cloneNode(true);
    var badges = newTable.querySelectorAll('.badge');
    badges.forEach(function(badge) {
        var text = badge.textContent;
        var textNode = document.createTextNode(text);
        badge.parentNode.replaceChild(textNode, badge);
    });
    
    container.appendChild(headerDiv);
    container.appendChild(newTable);
    
    if (typeof XLSX !== 'undefined') {
        var wb = XLSX.utils.book_new();
        var ws = XLSX.utils.table_to_sheet(newTable);
        XLSX.utils.book_append_sheet(wb, ws, "Rekap Siswa");
        XLSX.writeFile(wb, 'rekap_sholat_dhuha_siswa_' + studentName.replace(/[^a-z0-9]/gi, '_').toLowerCase() + '.xlsx');
    } else {
        var html = container.innerHTML;
        var a = document.createElement('a');
        var data = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
        a.href = data;
        a.download = 'rekap_sholat_dhuha_siswa.xls';
        a.click();
    }
}

function exportStudentToPDF() {
    var printWindow = window.open('', '', 'height=860,width=1300');
    printWindow.document.write('<html><head><title>Rekap Sholat Dhuha Siswa</title>');
    printWindow.document.write('<style>');
    printWindow.document.write('@page { size: legal portrait; margin: 0.5cm; }');
    printWindow.document.write('body { font-family: Arial, sans-serif; margin: 0; padding: 10px; }');
    printWindow.document.write('table { border-collapse: collapse; width: 100%; font-size: 11px; margin-bottom: 20px; }');
    printWindow.document.write('th, td { border: 1px solid #ddd; padding: 4px; text-align: left; }');
    printWindow.document.write('th { background-color: #f2f2f2; font-weight: bold; }');
    printWindow.document.write('.header { text-align: center; margin-bottom: 15px; }');
    printWindow.document.write('.badge { padding: 2px 5px; border-radius: 3px; font-size: 10px; }');
    printWindow.document.write('.badge-success { background-color: #28a745; color: white; }');
    printWindow.document.write('.badge-danger { background-color: #dc3545; color: white; }');
    printWindow.document.write('.badge-secondary { background-color: #6c757d; color: white; }');
    printWindow.document.write('.signature-wrapper { margin-top: 30px; display: flex; justify-content: space-between; width: 100%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.signature-box { text-align: center; width: 45%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.print-btn { position: fixed; top: 20px; right: 20px; padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; z-index: 9999; }');
    printWindow.document.write('.print-btn:hover { background: #0056b3; }');
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write('<button class="print-btn no-print" onclick="window.print()"><i class="fas fa-print"></i> Cetak / Simpan PDF</button>');
    printWindow.document.write('<div class="header">');
    printWindow.document.write('<img src="../assets/img/' + schoolLogo + '" alt="Logo" style="max-width: 80px; vertical-align: middle; margin-right: 15px;">');
    printWindow.document.write('<div style="display: inline-block; vertical-align: middle;">');
    printWindow.document.write('<h2 style="margin: 0;">Sistem Absensi Siswa</h2>');
    printWindow.document.write('<h3 style="margin: 5px 0;"><?php echo htmlspecialchars($school_profile["nama_madrasah"] ?? "Madrasah Ibtidaiyah Negeri Pembina Kota Padang", ENT_QUOTES, "UTF-8"); ?></h3>');
    printWindow.document.write('<h4 style="margin: 0;">Rekap Sholat Dhuha Siswa: ' + studentName + '</h4></div>');
    printWindow.document.write('</div>');
    
    var table = document.getElementById('studentTable');
    if (table) {
        var newTable = table.cloneNode(true);
        var badges = newTable.querySelectorAll('.badge');
        badges.forEach(function(badge) {
            var text = badge.textContent;
            var textNode = document.createTextNode(text);
            badge.parentNode.replaceChild(textNode, badge);
        });
        printWindow.document.write(newTable.outerHTML);
    }
    
    printWindow.document.write('<div class="signature-wrapper">');
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p><br>Wali Kelas,</p>');
    if (classTeacherName) {
        var qrContentWali = 'Validasi Tanda Tangan Digital: ' + classTeacherName + ' - <?php echo addslashes($school_profile["nama_madrasah"] ?? "Madrasah"); ?>';
        var qrUrlWali = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' + encodeURIComponent(qrContentWali);
        printWindow.document.write('<img src="' + qrUrlWali + '" alt="QR Signature" style="width: 80px; height: 80px; margin: 10px auto; display: block;">');
        printWindow.document.write('<p style="font-size: 10px; margin-top: 0;">(Ditandatangani secara digital)</p>');
    } else {
        printWindow.document.write('<br><br><br>');
    }
    printWindow.document.write('<p><strong>' + classTeacherName + '</strong></p>');
    printWindow.document.write('</div>');
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p>' + schoolCity + ', ' + reportDate + '<br>Kepala Madrasah,</p>');
    if (madrasahHeadSignature) {
        var qrContentHead = 'Validasi Tanda Tangan Digital: ' + madrasahHeadName + ' - <?php echo addslashes($school_profile["nama_madrasah"] ?? "Madrasah"); ?>';
        var qrUrlHead = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' + encodeURIComponent(qrContentHead);
        printWindow.document.write('<img src="' + qrUrlHead + '" alt="QR Signature" style="width: 80px; height: 80px; margin: 10px auto; display: block;">');
        printWindow.document.write('<p style="font-size: 10px; margin-top: 0;">(Ditandatangani secara digital)</p>');
    } else {
        printWindow.document.write('<br><br><br>');
    }
    printWindow.document.write('<p><strong>' + madrasahHeadName + '</strong></p></div>');
    printWindow.document.write('</div>');
    
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.focus();
    setTimeout(function() { printWindow.print(); }, 500);
}
</script>
