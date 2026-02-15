<?php
// Set default timezone to Asia/Jakarta
date_default_timezone_set('Asia/Jakarta');

if (session_status() == PHP_SESSION_NONE) {
    // Determine session name based on directory or explicit request
    $script_path = $_SERVER['PHP_SELF'];
    $session_name = 'SIS_LOGIN'; // Default for root/login

    // Check for explicit session type request
    if (isset($_GET['session_type'])) {
        $type = $_GET['session_type'];
        if ($type == 'admin') $session_name = 'SIS_ADMIN';
        elseif ($type == 'guru') $session_name = 'SIS_GURU';
        elseif ($type == 'siswa') $session_name = 'SIS_SISWA';
        elseif ($type == 'wali') $session_name = 'SIS_WALI';
        elseif ($type == 'tata_usaha') $session_name = 'SIS_TU';
        elseif ($type == 'kepala_madrasah' || $type == 'kepala') $session_name = 'SIS_KEPALA';
    } 
    // Check directory context
    elseif (strpos($script_path, '/admin/') !== false) {
        $session_name = 'SIS_ADMIN';
        
        // Check LAST_ACTIVE_SESSION
        if (isset($_COOKIE['LAST_ACTIVE_SESSION']) && in_array($_COOKIE['LAST_ACTIVE_SESSION'], ['SIS_TU', 'SIS_KEPALA'])) {
             $session_name = $_COOKIE['LAST_ACTIVE_SESSION'];
        }
        // Fallback for TU and Kepala accessing Admin files
        elseif (!isset($_COOKIE['SIS_ADMIN'])) {
            if (isset($_COOKIE['SIS_TU'])) {
                $session_name = 'SIS_TU';
            } elseif (isset($_COOKIE['SIS_KEPALA'])) {
                $session_name = 'SIS_KEPALA';
            }
        }
    } elseif (strpos($script_path, '/guru/') !== false) {
        $session_name = 'SIS_GURU';
        
        // Check LAST_ACTIVE_SESSION
        if (isset($_COOKIE['LAST_ACTIVE_SESSION']) && in_array($_COOKIE['LAST_ACTIVE_SESSION'], ['SIS_WALI', 'SIS_ADMIN', 'SIS_TU', 'SIS_KEPALA'])) {
             $session_name = $_COOKIE['LAST_ACTIVE_SESSION'];
        }
        // Fallback for others accessing Guru files
        elseif (!isset($_COOKIE['SIS_GURU'])) {
            if (isset($_COOKIE['SIS_WALI'])) {
                $session_name = 'SIS_WALI';
            } elseif (isset($_COOKIE['SIS_ADMIN'])) {
                $session_name = 'SIS_ADMIN';
            } elseif (isset($_COOKIE['SIS_TU'])) {
                $session_name = 'SIS_TU';
            } elseif (isset($_COOKIE['SIS_KEPALA'])) {
                $session_name = 'SIS_KEPALA';
            }
        }
    } elseif (strpos($script_path, '/siswa/') !== false) {
        $session_name = 'SIS_SISWA';
    } elseif (strpos($script_path, '/wali/') !== false) {
        $session_name = 'SIS_WALI';
    } elseif (strpos($script_path, '/tata_usaha/') !== false) {
        $session_name = 'SIS_TU';
    } elseif (strpos($script_path, '/kepala/') !== false) {
        $session_name = 'SIS_KEPALA';
    }
    
    // Handle logout specific target
    if (basename($_SERVER['SCRIPT_NAME']) == 'logout.php' && isset($_GET['level'])) {
        $lvl = $_GET['level'];
        switch($lvl) {
            case 'admin': $session_name = 'SIS_ADMIN'; break;
            case 'guru': $session_name = 'SIS_GURU'; break;
            case 'siswa': $session_name = 'SIS_SISWA'; break;
            case 'wali': $session_name = 'SIS_WALI'; break;
            case 'tata_usaha': $session_name = 'SIS_TU'; break;
            case 'kepala_madrasah': 
            case 'kepala': $session_name = 'SIS_KEPALA'; break;
        }
    }

    session_name($session_name);
    session_set_cookie_params(0, '/'); // Ensure cookies are available globally
    session_start();
    
    // Update sticky session if logged in
    if (isset($_SESSION['user_id'])) {
        setcookie('LAST_ACTIVE_SESSION', $session_name, time() + 86400 * 30, '/');
    }
}

// Function to switch session context (used in login.php)
function startUserSession($level) {
    if (session_status() == PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    
    $session_name = 'SIS_LOGIN';
    switch ($level) {
        case 'admin': $session_name = 'SIS_ADMIN'; break;
        case 'guru': $session_name = 'SIS_GURU'; break;
        case 'siswa': $session_name = 'SIS_SISWA'; break;
        case 'wali': $session_name = 'SIS_WALI'; break;
        case 'tata_usaha': $session_name = 'SIS_TU'; break;
        case 'kepala_madrasah': 
        case 'kepala': $session_name = 'SIS_KEPALA'; break;
    }
    
    session_name($session_name);
    session_set_cookie_params(0, '/');
    session_start();
    session_regenerate_id(true);
    
    // Update sticky session
    setcookie('LAST_ACTIVE_SESSION', $session_name, time() + 86400 * 30, '/');
}

// Function to redirect user
function redirect($page) {
    header("Location: $page");
    exit();
}

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Function to get user level
function getUserLevel() {
    return isset($_SESSION['level']) ? $_SESSION['level'] : '';
}

// Function to check user authorization
function isAuthorized($allowed_levels = []) {
    if (!isLoggedIn()) {
        return false;
    }
    
    if (empty($allowed_levels)) {
        return true;
    }
    
    return in_array(getUserLevel(), $allowed_levels);
}

// Function to get school profile
function getSchoolProfile($pdo) {
    $stmt = $pdo->prepare("SELECT * FROM tb_profil_madrasah WHERE id = 1");
    $stmt->execute();
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$profile) {
        return [
            'nama_yayasan' => 'YAYASAN PENDIDIKAN ISLAM',
            'nama_madrasah' => 'MADRASAH IBTIDAIYAH',
            'kepala_madrasah' => 'KEPALA MADRASAH',
            'tahun_ajaran' => date('Y') . '/' . (date('Y') + 1),
            'semester' => 'Semester 1',
            'alamat' => '',
            'logo' => '',
            'dashboard_hero_image' => ''
        ];
    }
    
    return $profile;
}

// Function to format date
function formatDate($date) {
    $date = new DateTime($date);
    return $date->format('d M Y');
}

// Function to get current date in Indonesian format
function getCurrentDateIndonesia() {
    $hari = array(
        'Sunday' => 'Minggu',
        'Monday' => 'Senin',
        'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis',
        'Friday' => 'Jumat',
        'Saturday' => 'Sabtu'
    );
    
    $bulan = array(
        'January' => 'Januari',
        'February' => 'Februari',
        'March' => 'Maret',
        'April' => 'April',
        'May' => 'Mei',
        'June' => 'Juni',
        'July' => 'Juli',
        'August' => 'Agustus',
        'September' => 'September',
        'October' => 'Oktober',
        'November' => 'November',
        'December' => 'Desember'
    );
    
    $day = $hari[date('l')];
    $date = date('d');
    $month = $bulan[date('F')];
    $year = date('Y');
    
    return "$day, $date $month $year";
}

// Function to format specific date in Indonesian format
function formatDateIndonesia($date_string) {
    $bulan = array(
        'January' => 'Januari',
        'February' => 'Februari',
        'March' => 'Maret',
        'April' => 'April',
        'May' => 'Mei',
        'June' => 'Juni',
        'July' => 'Juli',
        'August' => 'Agustus',
        'September' => 'September',
        'October' => 'Oktober',
        'November' => 'November',
        'December' => 'Desember'
    );
    
    $timestamp = strtotime($date_string);
    $day = date('d', $timestamp);
    $month = $bulan[date('F', $timestamp)];
    $year = date('Y', $timestamp);
    
    return "$day $month $year";
}

// Function to log activity
function logActivity($pdo, $username, $action, $description = '') {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $pdo->prepare("INSERT INTO tb_activity_log (username, action, description, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())");
    return $stmt->execute([$username, $action, $description, $ip_address]);
}

// Function to create notification
function createNotification($pdo, $message, $link, $type = 'info') {
    // Ignoring $type as column doesn't exist in current schema
    $stmt = $pdo->prepare("INSERT INTO tb_notifikasi (message, link, created_at) VALUES (?, ?, NOW())");
    return $stmt->execute([$message, $link]);
}

// Function to get system notifications (auto delete > 24 hours)
function getNotifications($pdo) {
    // Delete notifications older than 24 hours
    $cleanup_stmt = $pdo->prepare("DELETE FROM tb_notifikasi WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $cleanup_stmt->execute();

    // Get all notifications from last 24 hours
    $stmt = $pdo->prepare("SELECT * FROM tb_notifikasi ORDER BY created_at DESC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get unread notifications (Deprecated, alias to getNotifications)
function getUnreadNotifications($pdo) {
    return getNotifications($pdo);
}

// Function to mark notification as read
function markNotificationAsRead($pdo, $id) {
    $stmt = $pdo->prepare("UPDATE tb_notifikasi SET is_read = 1 WHERE id = ?");
    return $stmt->execute([$id]);
}

// Function to calculate time ago
function timeAgo($timestamp) {
    $time_ago = strtotime($timestamp);
    $current_time = time();
    $time_difference = $current_time - $time_ago;
    $seconds = $time_difference;
    $minutes      = round($seconds / 60 );
    $hours           = round($seconds / 3600);
    $days          = round($seconds / 86400);
    $weeks          = round($seconds / 604800);
    $months          = round($seconds / 2629440);
    $years          = round($seconds / 31553280);

    if($seconds <= 60) {
        return "Baru saja";
    } else if($minutes <=60) {
        return "$minutes menit yang lalu";
    } else if($hours <=24) {
        return "$hours jam yang lalu";
    } else if($days <= 7) {
        return "$days hari yang lalu";
    } else if($weeks <= 4.3) {
        return "$weeks minggu yang lalu";
    } else if($months <=12) {
        return "$months bulan yang lalu";
    } else {
        return "$years tahun yang lalu";
    }
}

// Function to automatically update sholat attendance based on daily attendance
function updateSholatAttendance($pdo, $id_siswa, $tanggal, $keterangan_absensi) {
    $status_sholat = '';
    
    // Determine status
    if ($keterangan_absensi == 'Hadir' || $keterangan_absensi == 'Terlambat') {
        $status_sholat = 'Melaksanakan';
    } elseif (in_array($keterangan_absensi, ['Sakit', 'Izin', 'Alpa'])) {
        $status_sholat = 'Tidak Melaksanakan';
    }
    
    if ($status_sholat) {
        // Update or Insert tb_sholat (Sholat Berjamaah)
        $stmt = $pdo->prepare("SELECT id_sholat, status FROM tb_sholat WHERE id_siswa = ? AND tanggal = ?");
        $stmt->execute([$id_siswa, $tanggal]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Skip update if status is 'Berhalangan' - manual override takes precedence
        if (!$existing || $existing['status'] != 'Berhalangan') {
            if ($existing) {
                $pdo->prepare("UPDATE tb_sholat SET status = ? WHERE id_siswa = ? AND tanggal = ?")
                    ->execute([$status_sholat, $id_siswa, $tanggal]);
            } else {
                $pdo->prepare("INSERT INTO tb_sholat (id_siswa, tanggal, status) VALUES (?, ?, ?)")
                    ->execute([$id_siswa, $tanggal, $status_sholat]);
            }
        }
        
        // Update or Insert tb_sholat_dhuha (Sholat Dhuha)
        $stmt = $pdo->prepare("SELECT id_sholat, status FROM tb_sholat_dhuha WHERE id_siswa = ? AND tanggal = ?");
        $stmt->execute([$id_siswa, $tanggal]);
        $existing_dhuha = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Skip update if status is 'Berhalangan'
        if (!$existing_dhuha || $existing_dhuha['status'] != 'Berhalangan') {
            if ($existing_dhuha) {
                $pdo->prepare("UPDATE tb_sholat_dhuha SET status = ? WHERE id_siswa = ? AND tanggal = ?")
                    ->execute([$status_sholat, $id_siswa, $tanggal]);
            } else {
                $pdo->prepare("INSERT INTO tb_sholat_dhuha (id_siswa, tanggal, status) VALUES (?, ?, ?)")
                    ->execute([$id_siswa, $tanggal, $status_sholat]);
            }
        }
    }
}

// --- Helper Functions for Security ---

// Function to sanitize user input
function sanitizeInput($input) {
    $input = trim($input);
    $input = stripslashes($input);
    $input = htmlspecialchars($input);
    return $input;
}

// Function to hash password
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// Function to verify password
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Function to get teacher avatar
function getTeacherAvatarImage($teacher, $size = 30) {
    $img_dir = '../uploads/';
    $base_path = dirname(__DIR__) . '/uploads/';
    
    // Check if teacher has custom photo and file exists
    if (is_array($teacher) && isset($teacher['foto']) && !empty($teacher['foto'])) {
        if (file_exists($base_path . $teacher['foto'])) {
            return '<img alt="image" src="' . $img_dir . $teacher['foto'] . '" class="rounded-circle mr-1" width="' . $size . '" style="object-fit: cover; height: ' . $size . 'px;">';
        }
    }
    
    // Fallback to initials
    $name = 'Guru';
    if (is_array($teacher) && isset($teacher['nama_guru'])) {
        $name = $teacher['nama_guru'];
    } elseif (is_array($teacher) && isset($teacher['username'])) {
        $name = $teacher['username'];
    }
    
    $initials_url = 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&background=random&color=fff&size=128';
    
    return '<img alt="image" src="' . $initials_url . '" class="rounded-circle mr-1" width="' . $size . '" style="object-fit: cover; height: ' . $size . 'px;">';
}

// Function to get user avatar
function getUserAvatarImage($user, $size = 30) {
    $img_dir = '../assets/img/';
    $base_path = dirname(__DIR__) . '/assets/img/';
    
    if (is_array($user) && isset($user['foto']) && !empty($user['foto'])) {
        if (file_exists($base_path . $user['foto'])) {
            return '<img alt="image" src="' . $img_dir . $user['foto'] . '" class="rounded-circle mr-1" width="' . $size . '" style="object-fit: cover; height: ' . $size . 'px;">';
        }
    }
    
    // Fallback to initials
    $name = 'User';
    if (is_array($user)) {
        if (isset($user['nama']) && !empty($user['nama'])) {
            $name = $user['nama'];
        } elseif (isset($user['username']) && !empty($user['username'])) {
            $name = $user['username'];
        }
    }
    
    $initials_url = 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&background=random&color=fff&size=128';
    
    return '<img alt="image" src="' . $initials_url . '" class="rounded-circle mr-1" width="' . $size . '" style="object-fit: cover; height: ' . $size . 'px;">';
}

// Function to get all classes
function getAllKelas($pdo) {
    $stmt = $pdo->prepare("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get students by class
function getStudentsByClass($pdo, $kelas_id) {
    $stmt = $pdo->prepare("SELECT s.*, k.nama_kelas FROM tb_siswa s JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_kelas = ? ORDER BY s.nama_siswa ASC");
    $stmt->execute([$kelas_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get guru name by id
function getGuruName($pdo, $id) {
    $stmt = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([$id]);
    return $stmt->fetchColumn();
}

// Function to get activity color based on action
function getActivityColor($action) {
    $action = strtolower($action);
    if (strpos($action, 'tambah') !== false || strpos($action, 'add') !== false || strpos($action, 'create') !== false || strpos($action, 'insert') !== false) {
        return 'bg-success';
    } elseif (strpos($action, 'edit') !== false || strpos($action, 'update') !== false || strpos($action, 'ubah') !== false) {
        return 'bg-warning';
    } elseif (strpos($action, 'hapus') !== false || strpos($action, 'delete') !== false || strpos($action, 'remove') !== false) {
        return 'bg-danger';
    } elseif (strpos($action, 'login') !== false || strpos($action, 'masuk') !== false) {
        return 'bg-info';
    } elseif (strpos($action, 'logout') !== false || strpos($action, 'keluar') !== false) {
        return 'bg-secondary';
    } else {
        return 'bg-primary';
    }
}

// Function to get holidays from kalender pendidikan
function getHolidays($pdo, $year, $month = null) {
    $holidays = [];
    $query = "SELECT tgl_mulai, tgl_selesai, nama_kegiatan, warna FROM tb_kalender_pendidikan WHERE warna = 'danger'";
    $params = [];

    if ($month) {
        $query .= " AND (
            (MONTH(tgl_mulai) = ? AND YEAR(tgl_mulai) = ?) OR 
            (MONTH(tgl_selesai) = ? AND YEAR(tgl_selesai) = ?) OR
            (? BETWEEN MONTH(tgl_mulai) AND MONTH(tgl_selesai) AND ? BETWEEN YEAR(tgl_mulai) AND YEAR(tgl_selesai))
        )";
        $params = [$month, $year, $month, $year, $month, $year];
    } else {
        $query .= " AND (YEAR(tgl_mulai) = ? OR YEAR(tgl_selesai) = ?)";
        $params = [$year, $year];
    }

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $start = new DateTime($row['tgl_mulai']);
            $end = new DateTime($row['tgl_selesai']);
            $end->modify('+1 day');

            $period = new DatePeriod($start, new DateInterval('P1D'), $end);
            foreach ($period as $date) {
                $holidays[$date->format('Y-m-d')] = $row['nama_kegiatan'];
            }
        }
    } catch (PDOException $e) {
        // Table might not exist yet
    }

    // Tambahkan hari Jumat sebagai hari libur
    if ($month) {
        $num_days = cal_days_in_month(CAL_GREGORIAN, (int)$month, (int)$year);
        for ($d = 1; $d <= $num_days; $d++) {
            $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $day_of_week = date('N', strtotime($date_str));
            if ($day_of_week == 5) { // 5 adalah Jumat (ISO-8601)
                if (!isset($holidays[$date_str])) {
                    $holidays[$date_str] = 'Hari Libur (Jumat)';
                }
            }
        }
    } else {
        // Jika hanya tahun, loop seluruh bulan
        for ($m = 1; $m <= 12; $m++) {
            $num_days = cal_days_in_month(CAL_GREGORIAN, $m, (int)$year);
            for ($d = 1; $d <= $num_days; $d++) {
                $date_str = sprintf('%04d-%02d-%02d', $year, $m, $d);
                $day_of_week = date('N', strtotime($date_str));
                if ($day_of_week == 5) {
                    if (!isset($holidays[$date_str])) {
                        $holidays[$date_str] = 'Hari Libur (Jumat)';
                    }
                }
            }
        }
    }

    return $holidays;
}

// Check if a specific date is a holiday based on Kalender Pendidikan
function isSchoolHoliday($pdo, $date) {
    try {
        $year = (int)date('Y', strtotime($date));
        $month = (int)date('m', strtotime($date));
        $holidays = getHolidays($pdo, $year, $month);
        if (isset($holidays[$date])) {
            return ['is_holiday' => true, 'name' => $holidays[$date]];
        }
        return ['is_holiday' => false, 'name' => ''];
    } catch (Exception $e) {
        return ['is_holiday' => false, 'name' => ''];
    }
}
// Function to get activity icon based on action
function getActivityIcon($action) {
    $action = strtolower($action);
    if (strpos($action, 'tambah') !== false || strpos($action, 'add') !== false || strpos($action, 'create') !== false || strpos($action, 'insert') !== false) {
        return 'fas fa-plus';
    } elseif (strpos($action, 'edit') !== false || strpos($action, 'update') !== false || strpos($action, 'ubah') !== false) {
        return 'fas fa-pen';
    } elseif (strpos($action, 'hapus') !== false || strpos($action, 'delete') !== false || strpos($action, 'remove') !== false) {
        return 'fas fa-trash';
    } elseif (strpos($action, 'login') !== false || strpos($action, 'masuk') !== false) {
        return 'fas fa-sign-in-alt';
    } elseif (strpos($action, 'logout') !== false || strpos($action, 'keluar') !== false) {
        return 'fas fa-sign-out-alt';
    } else {
        return 'fas fa-info';
    }
}
