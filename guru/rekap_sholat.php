<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has guru level
if (!isAuthorized(['guru'])) {
    redirect('../login.php');
}

// Set page title
$page_title = 'Rekap Sholat Berjamaah';

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
    "https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js",
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

// --- Teacher Specific Logic ---
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
// --- End Teacher Specific Logic ---

// Get class information
$class_info = [];
if ($class_id > 0) {
    // Verify teacher has access to this class
    $has_access = false;
    foreach ($classes as $c) {
        if ($c['id_kelas'] == $class_id) {
            $has_access = true;
            break;
        }
    }
    
    if ($has_access) {
        $stmt = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas = ?");
        $stmt->execute([$class_id]);
        $class_info = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } else {
        $class_id = 0; // Reset if no access
    }
}

// Process search based on filter type
if ($class_id > 0) {
    // Fetch all students in class for dropdowns and processing
    $stmt = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
    $stmt->execute([$class_id]);
    $all_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($filter_type == 'daily' && !empty($selected_date)) {
        // Daily filter
        $stmt = $pdo->prepare("SELECT s.id_siswa, s.nama_siswa, s.nisn, s.jenis_kelamin, k.nama_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_kelas = ? ORDER BY s.nama_siswa ASC");
        $stmt->execute([$class_id]);
        $all_daily_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get sholat data
        $stmt = $pdo->prepare("SELECT id_siswa, status FROM tb_sholat WHERE tanggal = ?");
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
                $final_status = 'Hadir';
                $note = "";
            } else {
                $final_status = 'Belum Absen';
                $note = "";
            }

            $daily_results[] = [
                'id_siswa' => $student['id_siswa'],
                'nama_siswa' => $student['nama_siswa'],
                'nisn' => $student['nisn'],
                'jenis_kelamin' => $student['jenis_kelamin'] ?? '',
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
            FROM tb_sholat
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
             $stmt = $pdo->prepare("SELECT status, tanggal FROM tb_sholat WHERE id_siswa = ? ORDER BY tanggal DESC");
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
            FROM tb_sholat
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

// Pass variables to JS
$school_city = $school_profile['tempat_jadwal'] ?? '';
$report_date = formatDateIndonesia(date('Y-m-d'));

echo "<script>
    var studentName = " . json_encode($student_info['nama_siswa'] ?? '') . ";
    var selectedDate = " . json_encode($selected_date) . ";
    var jsMonthName = " . json_encode($js_month_name) . ";
    var jsMonthYear = " . json_encode($js_month_year) . ";
    var schoolLogo = " . json_encode($school_profile['logo'] ?? 'logo.png') . ";
    var schoolName = " . json_encode($school_profile['nama_madrasah'] ?? 'Madrasah Ibtidaiyah Negeri Pembina Kota Padang') . ";
    var schoolCity = " . json_encode($school_city) . ";
    var reportDate = " . json_encode($report_date) . ";
    var classTeacherName = " . json_encode($class_info['wali_kelas'] ?? '') . ";
    var madrasahHeadName = " . json_encode($school_profile['kepala_madrasah'] ?? 'Kepala Madrasah') . ";
    var madrasahHeadSignature = " . json_encode($school_profile['ttd_kepala'] ?? '') . ";
</script>";
?>
<script>
    function updateStatus(studentId, status) {
        Swal.fire({
            title: 'Konfirmasi',
            text: 'Set status menjadi ' + status + '?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Ya',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        action: 'update_status',
                        student_id: studentId,
                        status: status,
                        date: selectedDate
                    },
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Berhasil',
                                text: 'Status berhasil diperbarui',
                                timer: 1500,
                                showConfirmButton: false
                            });
                            // Update badge locally
                            var badgeSpan = $('#badge_' + studentId);
                            if (status == 'Berhalangan') {
                                badgeSpan.html('<span class="badge badge-danger">Berhalangan</span>');
                            }
                        } else {
                            Swal.fire('Gagal', response.message, 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error', 'Terjadi kesalahan sistem', 'error');
                    }
                });
            }
        });
    }
function exportMonthlyToExcel() {
    var container = document.createElement('div');
    var headerDiv = document.createElement('div');
    headerDiv.innerHTML = '<img src="../assets/img/' + schoolLogo + '" alt="Logo" style="max-width: 100px; float: left; margin-right: 20px;"><div style="display: inline-block;"><h2>Sistem Absensi Siswa</h2>';
    headerDiv.innerHTML += '<h3><?php echo htmlspecialchars($school_profile["nama_madrasah"] ?? "Madrasah Ibtidaiyah Negeri Pembina Kota Padang", ENT_QUOTES, "UTF-8"); ?></h3>';
    headerDiv.innerHTML += '<h4>Rekap Bulanan Sholat Berjamaah - ' + jsMonthName + ' ' + jsMonthYear + '</h4>';
    headerDiv.innerHTML += '<p>Tahun Ajaran: <?php echo htmlspecialchars($school_profile["tahun_ajaran"] ?? "", ENT_QUOTES, "UTF-8"); ?> | Semester: <?php echo htmlspecialchars($active_semester, ENT_QUOTES, "UTF-8"); ?></p></div><br style="clear: both;">';
    
    var table = document.getElementById('monthlyTable');
    var newTable = table.cloneNode(true);
    
    // Convert badges to text (though monthly table uses bg colors, not badges usually, but just in case)
    // Actually monthly table uses bg classes. We might need to keep styles or handle it. 
    // XLSX export might lose bg colors if not careful. But standard table_to_sheet usually captures basic text.
    // Let's just do standard export.
    
    container.appendChild(headerDiv);
    container.appendChild(newTable);

    if (typeof XLSX !== 'undefined') {
        var wb = XLSX.utils.book_new();
        var ws = XLSX.utils.table_to_sheet(newTable);
        XLSX.utils.book_append_sheet(wb, ws, "Rekap Bulanan");
        XLSX.writeFile(wb, 'rekap_sholat_bulanan_' + jsMonthYear + '_' + jsMonthName + '.xlsx');
    }
}

function exportMonthlyToPDF() {
    var printWindow = window.open('', '', 'height=860,width=1300');
    printWindow.document.write('<html><head><title>Rekap Bulanan Sholat Berjamaah</title>');
    printWindow.document.write('<style>');
    printWindow.document.write('@page { size: A4 landscape; margin: 1cm; }');
    printWindow.document.write('body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }');
    printWindow.document.write('table { border-collapse: collapse; width: 100%; font-size: 10px; margin-bottom: 20px; }');
    printWindow.document.write('th, td { border: 1px solid #ddd; padding: 4px; text-align: center; }');
    printWindow.document.write('th { background-color: #f2f2f2; font-weight: bold; }');
    printWindow.document.write('.header { text-align: center; margin-bottom: 20px; }');
    printWindow.document.write('.bg-success { background-color: #28a745 !important; color: white !important; -webkit-print-color-adjust: exact; }');
    printWindow.document.write('.bg-danger { background-color: #dc3545 !important; color: white !important; -webkit-print-color-adjust: exact; }');
    printWindow.document.write('.bg-warning { background-color: #ffc107 !important; color: black !important; -webkit-print-color-adjust: exact; }');
    printWindow.document.write('.signature-wrapper { margin-top: 30px; display: flex; justify-content: space-between; width: 100%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.signature-box { text-align: center; width: 45%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body>');
    
    printWindow.document.write('<div class="header">');
    printWindow.document.write('<img src="../assets/img/' + schoolLogo + '" alt="Logo" style="max-width: 80px; vertical-align: middle; margin-right: 15px;">');
    printWindow.document.write('<div style="display: inline-block; vertical-align: middle;">');
    printWindow.document.write('<h2 style="margin: 0;">Sistem Absensi Siswa</h2>');
    printWindow.document.write('<h3 style="margin: 5px 0;">' + schoolName + '</h3>');
    printWindow.document.write('<h4 style="margin: 0;">Rekap Bulanan Sholat Berjamaah - ' + jsMonthName + ' ' + jsMonthYear + '</h4>');
    printWindow.document.write('<p style="margin: 5px 0;">Tahun Ajaran: <?php echo htmlspecialchars($school_profile["tahun_ajaran"] ?? "", ENT_QUOTES, "UTF-8"); ?> | Semester: <?php echo htmlspecialchars($active_semester, ENT_QUOTES, "UTF-8"); ?></p>');
    printWindow.document.write('</div></div>');
    
    var table = document.getElementById('monthlyTable');
    if (table) {
        var tableHTML = table.outerHTML;
        printWindow.document.write(tableHTML);
    }
    
    printWindow.document.write('<div class="signature-wrapper">');
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p>' + schoolCity + ', ' + reportDate + '<br>Wali Kelas,</p>');
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
    headerDiv.innerHTML += '<h4>Rekap Semester Sholat Berjamaah</h4></div><br style="clear: both;">';
    
    var table = document.getElementById('semesterTable');
    var newTable = table.cloneNode(true);
    
    container.appendChild(headerDiv);
    container.appendChild(newTable);

    if (typeof XLSX !== 'undefined') {
        var wb = XLSX.utils.book_new();
        var ws = XLSX.utils.table_to_sheet(newTable);
        XLSX.utils.book_append_sheet(wb, ws, "Rekap Semester");
        XLSX.writeFile(wb, 'rekap_sholat_semester.xlsx');
    }
}

function exportSemesterToPDF() {
    var printWindow = window.open('', '', 'height=860,width=1300');
    printWindow.document.write('<html><head><title>Rekap Semester Sholat Berjamaah</title>');
    printWindow.document.write('<style>');
    printWindow.document.write('@page { size: A4 landscape; margin: 1cm; }');
    printWindow.document.write('body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }');
    printWindow.document.write('table { border-collapse: collapse; width: 100%; font-size: 10px; margin-bottom: 20px; }');
    printWindow.document.write('th, td { border: 1px solid #ddd; padding: 4px; text-align: center; }');
    printWindow.document.write('th { background-color: #f2f2f2; font-weight: bold; }');
    printWindow.document.write('.header { text-align: center; margin-bottom: 20px; }');
    printWindow.document.write('.signature-wrapper { margin-top: 30px; display: flex; justify-content: space-between; width: 100%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.signature-box { text-align: center; width: 45%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body>');
    
    printWindow.document.write('<div class="header">');
    printWindow.document.write('<img src="../assets/img/' + schoolLogo + '" alt="Logo" style="max-width: 80px; vertical-align: middle; margin-right: 15px;">');
    printWindow.document.write('<div style="display: inline-block; vertical-align: middle;">');
    printWindow.document.write('<h2 style="margin: 0;">Sistem Absensi Siswa</h2>');
    printWindow.document.write('<h3 style="margin: 5px 0;">' + schoolName + '</h3>');
    printWindow.document.write('<h4 style="margin: 0;">Rekap Semester Sholat Berjamaah</h4>');
    printWindow.document.write('</div></div>');
    
    var table = document.getElementById('semesterTable');
    if (table) {
        var tableHTML = table.outerHTML;
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
    printWindow.document.write('<p>&nbsp;</p>');
    printWindow.document.write('<p>Kepala Madrasah,</p>');
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
    
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.focus();
    setTimeout(function() { printWindow.print(); }, 500);
}
</script>
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
            <h1>Rekap Sholat Berjamaah</h1>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-header"><h4>Filter Rekap Sholat</h4></div>
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
                                    <?php $no=1; foreach ($daily_results as $r): ?>
                                    <tr>
                                        <td><?php echo $no++; ?></td>
                                        <td><?php echo htmlspecialchars($r['nama_siswa']); ?></td>
                                        <td>
                                            <?php if ($r['keterangan'] == 'Hadir'): ?>
                                                <span class="badge badge-success">Hadir</span>
                                            <?php elseif ($r['keterangan'] == 'Tidak Hadir'): ?>
                                                <span class="badge badge-danger">Tidak Hadir</span>
                                            <?php elseif ($r['keterangan'] == 'Melaksanakan'): ?>
                                                <span class="badge badge-success">Melaksanakan</span>
                                            <?php elseif ($r['keterangan'] == 'Tidak Melaksanakan'): ?>
                                                <span class="badge badge-danger">Tidak Melaksanakan</span>
                                            <?php elseif ($r['keterangan'] == 'Berhalangan'): ?>
                                                <span class="badge badge-danger">Berhalangan</span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary"><?php echo htmlspecialchars($r['keterangan']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($r['note']); ?></td>
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
                            <table class="table table-bordered table-striped" id="monthlyTable">
                                <thead>
                                    <tr>
                                        <th rowspan="2">No</th>
                                        <th rowspan="2">Nama Siswa</th>
                                        <th colspan="31" class="text-center">Tanggal</th>
                                        <th colspan="3" class="text-center">Total</th>
                                    </tr>
                                    <tr>
                                        <?php for($i=1; $i<=31; $i++) echo "<th class='text-center' style='min-width: 25px;'>$i</th>"; ?>
                                        <th>H</th>
                                        <th>TH</th>
                                        <th>B</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $no=1; foreach ($monthly_results as $r): ?>
                                    <tr>
                                        <td><?php echo $no++; ?></td>
                                        <td><?php echo htmlspecialchars($r['nama_siswa']); ?></td>
                                        <?php for($i=1; $i<=31; $i++): 
                                            $s = $r['days'][$i];
                                            $bg = '';
                                            if ($s == 'Hadir' || $s == 'Melaksanakan') $bg = 'bg-success text-white';
                                            elseif ($s == 'Tidak Hadir' || $s == 'Tidak Melaksanakan' || $s == 'Berhalangan') $bg = 'bg-danger text-white';
                                            elseif ($s == 'Sakit' || $s == 'Izin') $bg = 'bg-warning text-white';
                                            elseif ($s == 'Sakit' || $s == 'Izin') $bg = 'bg-warning text-white';
                                        ?>
                                            <td class="text-center <?php echo $bg; ?>" title="<?php echo $s; ?>">
                                                <?php 
                                                if ($s == 'Hadir' || $s == 'Melaksanakan') echo 'H';
                                                elseif ($s == 'Tidak Hadir' || $s == 'Tidak Melaksanakan') echo 'TH';
                                                elseif ($s == 'Berhalangan') echo 'B';
                                                elseif ($s == 'Sakit') echo 'S';
                                                elseif ($s == 'Izin') echo 'I';
                                                elseif ($s == 'Alpa') echo 'A';
                                                ?>
                                            </td>
                                        <?php endfor; ?>
                                        <td class="text-center font-weight-bold"><?php echo $r['summary']['Hadir']; ?></td>
                                        <td class="text-center font-weight-bold"><?php echo $r['summary']['Tidak Hadir']; ?></td>
                                        <td class="text-center font-weight-bold"><?php echo $r['summary']['Berhalangan']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php elseif (!empty($student_results)): ?>
                        <!-- Student Export Buttons -->
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

                        <div class="card">
                            <div class="card-body">
                                <h5><?php echo htmlspecialchars($student_info['nama_siswa']); ?> (<?php echo htmlspecialchars($student_info['nama_kelas']); ?>)</h5>
                                <div class="row mt-3">
                                    <div class="col-md-3">
                                        <div class="alert alert-success">
                                            Hadir/Melaksanakan: <strong><?php echo $student_attendance_summary['Hadir']; ?></strong>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="alert alert-danger">
                                            Tidak Hadir/Melaksanakan: <strong><?php echo $student_attendance_summary['Tidak Hadir']; ?></strong>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="alert alert-danger">
                                            Berhalangan: <strong><?php echo $student_attendance_summary['Berhalangan']; ?></strong>
                                        </div>
                                    </div>
                                </div>
                                <div class="table-responsive mt-3">
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
                                            <?php $no=1; foreach ($student_results as $r): ?>
                                            <tr>
                                                <td><?php echo $no++; ?></td>
                                                <td><?php echo date('d-m-Y', strtotime($r['tanggal'])); ?></td>
                                                <td>
                                                    <?php if ($r['keterangan'] == 'Hadir' || $r['keterangan'] == 'Melaksanakan'): ?>
                                                        <span class="badge badge-success"><?php echo $r['keterangan']; ?></span>
                                                    <?php elseif ($r['keterangan'] == 'Tidak Hadir' || $r['keterangan'] == 'Tidak Melaksanakan'): ?>
                                                        <span class="badge badge-danger"><?php echo $r['keterangan']; ?></span>
                                                    <?php elseif ($r['keterangan'] == 'Berhalangan'): ?>
                                                        <span class="badge badge-danger"><?php echo $r['keterangan']; ?></span>
                                                    <?php else: ?>
                                                        <span class="badge badge-warning"><?php echo $r['keterangan']; ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($r['note']); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
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
                            <table class="table table-bordered table-sm" id="semesterTable">
                                <thead>
                                    <tr>
                                        <th rowspan="2" style="width: 50px;">No</th>
                                        <th rowspan="2" style="width: 200px;">Nama Siswa</th>
                                        <?php for($m=$start_month; $m<=$end_month; $m++): ?>
                                            <th colspan="3"><?php echo $month_names[$m]; ?></th>
                                        <?php endfor; ?>
                                        <th colspan="3">Total</th>
                                    </tr>
                                    <tr>
                                        <?php for($m=$start_month; $m<=$end_month; $m++): ?>
                                            <th>H</th>
                                            <th>TH</th>
                                            <th>B</th>
                                        <?php endfor; ?>
                                        <th>H</th>
                                        <th>TH</th>
                                        <th>B</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $no=1; foreach ($semester_results as $r): ?>
                                    <tr>
                                        <td><?php echo $no++; ?></td>
                                        <td><?php echo htmlspecialchars($r['nama_siswa']); ?></td>
                                        <?php for($m=$start_month; $m<=$end_month; $m++): ?>
                                            <td class="text-center"><?php echo $r['monthly_totals'][$m]['Hadir']; ?></td>
                                            <td class="text-center"><?php echo $r['monthly_totals'][$m]['Tidak Hadir']; ?></td>
                                            <td class="text-center"><?php echo $r['monthly_totals'][$m]['Berhalangan']; ?></td>
                                        <?php endfor; ?>
                                        <td class="text-center font-weight-bold bg-light"><?php echo $r['summary']['Hadir']; ?></td>
                                        <td class="text-center font-weight-bold bg-light"><?php echo $r['summary']['Tidak Hadir']; ?></td>
                                        <td class="text-center font-weight-bold bg-light"><?php echo $r['summary']['Berhalangan']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Include Export Scripts (Same as admin) -->
<script>
// Copy export functions from admin/rekap_sholat.php
// Simplified for brevity, assume consistency
function exportDailyToExcel() {
    var container = document.createElement('div');
    var headerDiv = document.createElement('div');
    headerDiv.innerHTML = '<img src="../assets/img/' + schoolLogo + '" alt="Logo" style="max-width: 100px; float: left; margin-right: 20px;"><div style="display: inline-block;"><h2>Sistem Absensi Siswa</h2>';
    headerDiv.innerHTML += '<h3><?php echo htmlspecialchars($school_profile["nama_madrasah"] ?? "Madrasah Ibtidaiyah Negeri Pembina Kota Padang", ENT_QUOTES, "UTF-8"); ?></h3>';
    headerDiv.innerHTML += '<h4>Rekap Harian Sholat Berjamaah - ' + selectedDate.split('-').reverse().join('-') + '</h4></div><br style="clear: both;">';
    
    var table = document.getElementById('dailyTable');
    var newTable = table.cloneNode(true);
    
    // Convert badges to text
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
        XLSX.utils.book_append_sheet(wb, ws, "Rekap Harian");
        XLSX.writeFile(wb, 'rekap_sholat_harian_' + selectedDate + '.xlsx');
    }
}

function exportDailyToPDF() {
    var printWindow = window.open('', '', 'height=860,width=1300');
    printWindow.document.write('<html><head><title>Rekap Harian Sholat Berjamaah</title>');
    printWindow.document.write('<style>');
    printWindow.document.write('@page { size: A4 portrait; margin: 1cm; }');
    printWindow.document.write('body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }');
    printWindow.document.write('table { border-collapse: collapse; width: 100%; font-size: 12px; margin-bottom: 20px; }');
    printWindow.document.write('th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }');
    printWindow.document.write('th { background-color: #f2f2f2; font-weight: bold; }');
    printWindow.document.write('.header { text-align: center; margin-bottom: 20px; }');
    printWindow.document.write('.badge { padding: 0; color: black !important; background-color: transparent !important; border: none; font-weight: bold; }');
    printWindow.document.write('.signature-wrapper { margin-top: 30px; display: flex; justify-content: space-between; width: 100%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.signature-box { text-align: center; width: 45%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body>');
    
    printWindow.document.write('<div class="header">');
    printWindow.document.write('<img src="../assets/img/' + schoolLogo + '" alt="Logo" style="max-width: 80px; vertical-align: middle; margin-right: 15px;">');
    printWindow.document.write('<div style="display: inline-block; vertical-align: middle;">');
    printWindow.document.write('<h2 style="margin: 0;">Sistem Absensi Siswa</h2>');
    printWindow.document.write('<h3 style="margin: 5px 0;">' + schoolName + '</h3>');
    printWindow.document.write('<h4 style="margin: 0;">Rekap Harian Sholat Berjamaah - ' + selectedDate.split('-').reverse().join('-') + '</h4>');
    printWindow.document.write('</div></div>');
    
    var table = document.getElementById('dailyTable');
    if (table) {
        var tableHTML = table.outerHTML;
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
    printWindow.document.write('<p>&nbsp;</p>');
    printWindow.document.write('<p>Kepala Madrasah,</p>');
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
    
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.focus();
    setTimeout(function() { printWindow.print(); }, 500);
}

function exportStudentToExcel() {
    var container = document.createElement('div');
    var headerDiv = document.createElement('div');
    headerDiv.innerHTML = '<h3>Rekap Sholat Berjamaah - ' + studentName + '</h3>';
    
    var table = document.getElementById('studentTable');
    var newTable = table.cloneNode(true);
    
    var badges = newTable.querySelectorAll('.badge');
    badges.forEach(function(badge) {
        badge.parentNode.replaceChild(document.createTextNode(badge.textContent), badge);
    });

    container.appendChild(headerDiv);
    container.appendChild(newTable);

    var wb = XLSX.utils.book_new();
    var ws = XLSX.utils.table_to_sheet(newTable);
    XLSX.utils.book_append_sheet(wb, ws, "Rekap Siswa");
    XLSX.writeFile(wb, 'rekap_sholat_siswa_' + studentName.replace(/[^a-z0-9]/gi, '_').toLowerCase() + '.xlsx');
}

function exportStudentToPDF() {
    var printWindow = window.open('', '', 'height=860,width=1300');
    printWindow.document.write('<html><head><title>Rekap Sholat Berjamaah</title>');
    printWindow.document.write('<style>');
    printWindow.document.write('@page { size: A4 portrait; margin: 1cm; }');
    printWindow.document.write('body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }');
    printWindow.document.write('table { border-collapse: collapse; width: 100%; font-size: 12px; margin-bottom: 20px; }');
    printWindow.document.write('th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }');
    printWindow.document.write('th { background-color: #f2f2f2; font-weight: bold; }');
    printWindow.document.write('.header { text-align: center; margin-bottom: 20px; }');
    printWindow.document.write('.badge { padding: 0; color: black !important; background-color: transparent !important; border: none; font-weight: bold; }');
    printWindow.document.write('.signature-wrapper { margin-top: 30px; display: flex; justify-content: space-between; width: 100%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('.signature-box { text-align: center; width: 45%; page-break-inside: avoid; break-inside: avoid; }');
    printWindow.document.write('</style>');
    printWindow.document.write('</head><body>');
    
    printWindow.document.write('<div class="header">');
    printWindow.document.write('<img src="../assets/img/' + schoolLogo + '" alt="Logo" style="max-width: 80px; vertical-align: middle; margin-right: 15px;">');
    printWindow.document.write('<div style="display: inline-block; vertical-align: middle;">');
    printWindow.document.write('<h2 style="margin: 0;">Sistem Absensi Siswa</h2>');
    printWindow.document.write('<h3 style="margin: 5px 0;">' + schoolName + '</h3>');
    printWindow.document.write('<h4 style="margin: 0;">Rekap Sholat Berjamaah - ' + studentName + '</h4>');
    printWindow.document.write('</div></div>');
    
    var table = document.getElementById('studentTable');
    if (table) {
        var tableHTML = table.outerHTML;
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
    printWindow.document.write('<p>&nbsp;</p>');
    printWindow.document.write('<p>Kepala Madrasah,</p>');
    if (madrasahHeadSignature) {
        var qrContent = 'Validasi Tanda Tangan Digital: ' + madrasahHeadName + ' - ' + schoolName;
        var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' + encodeURIComponent(qrContent);
        printWindow.document.write('<img src="' + qrUrl + '" alt="QR Signature" style="width: 80px; height: 80px; margin: 10px 0;">');
        printWindow.document.write('<p style="font-size: 10px; margin-top: 0;">(Ditandatangani secara digital)</p>');
    } else {
        printWindow.document.write('<br><br><br>');
    }
    printWindow.document.write('<p><strong>' + madrasahHeadName + '</strong></p>');
    printWindow.document.write('</div>');
    printWindow.document.write('</div>');
    
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.focus();
    setTimeout(function() { printWindow.print(); }, 500);
}
</script>

<?php include '../templates/footer.php'; ?>
