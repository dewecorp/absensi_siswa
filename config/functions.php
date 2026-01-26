<?php
// Set default timezone to Asia/Jakarta
date_default_timezone_set('Asia/Jakarta');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
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
    return $stmt->fetch(PDO::FETCH_ASSOC);
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
    $date = date('d', $timestamp);
    $month = $bulan[date('F', $timestamp)];
    $year = date('Y', $timestamp);
    
    return "$date $month $year";
}

// Function to get current time
function getCurrentTime() {
    return date('H:i:s');
}

// Function to sanitize input
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(strip_tags(trim($input)));
}

// === NOTIFICATION SYSTEM ===

// Function to create a new notification
function createNotification($pdo, $message, $link = '#', $type = 'info') {
    try {
        // Use Asia/Jakarta timezone
        date_default_timezone_set('Asia/Jakarta');
        
        $stmt = $pdo->prepare("INSERT INTO tb_notifikasi (message, link, created_at) VALUES (?, ?, NOW())");
        $result = $stmt->execute([$message, $link]);
        
        // Auto cleanup old notifications (older than 24 hours)
        cleanupNotifications($pdo);
        
        return $result;
    } catch (PDOException $e) {
        error_log("Error creating notification: " . $e->getMessage());
        return false;
    }
}

// Function to get unread notifications for admin
function getUnreadNotifications($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM tb_notifikasi ORDER BY created_at DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching notifications: " . $e->getMessage());
        return [];
    }
}

// Function to delete notifications older than 24 hours
function cleanupNotifications($pdo) {
    try {
        $stmt = $pdo->prepare("DELETE FROM tb_notifikasi WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $stmt->execute();
    } catch (PDOException $e) {
        error_log("Error cleaning up notifications: " . $e->getMessage());
    }
}

// Function to mark notification as read
function markNotificationAsRead($pdo, $id) {
    try {
        $stmt = $pdo->prepare("UPDATE tb_notifikasi SET is_read = 1 WHERE id = ?");
        return $stmt->execute([$id]);
    } catch (PDOException $e) {
        error_log("Error marking notification as read: " . $e->getMessage());
        return false;
    }
}

// Function to convert timestamp to relative time in Indonesian
function timeAgo($datetime) {
    $now = new DateTime();
    $time = new DateTime($datetime);
    $interval = $now->diff($time);
    
    if ($interval->y > 0) {
        return $interval->y . ' tahun yang lalu';
    } elseif ($interval->m > 0) {
        return $interval->m . ' bulan yang lalu';
    } elseif ($interval->d > 0) {
        if ($interval->d == 1) {
            return 'kemarin';
        } else {
            return $interval->d . ' hari yang lalu';
        }
    } elseif ($interval->h > 0) {
        return $interval->h . ' jam yang lalu';
    } elseif ($interval->i > 0) {
        return $interval->i . ' menit yang lalu';
    } else {
        return 'baru saja';
    }
}

// Function to hash password
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// Function to verify password
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Function to generate random string
function generateRandomString($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $randomString;
}

// Function to get icon based on activity type
function getActivityIcon($action) {
    // Convert action to lowercase for comparison
    $action_lower = strtolower($action);
    
    if (strpos($action_lower, 'absen') !== false || strpos($action_lower, 'kehadiran') !== false || strpos($action_lower, 'attendance') !== false) {
        return 'fas fa-calendar-check';
    } elseif (strpos($action_lower, 'login') !== false || strpos($action_lower, 'masuk') !== false) {
        return 'fas fa-sign-in-alt';
    } elseif (strpos($action_lower, 'logout') !== false || strpos($action_lower, 'keluar') !== false) {
        return 'fas fa-sign-out-alt';
    } elseif (strpos($action_lower, 'tambah') !== false || strpos($action_lower, 'add') !== false || strpos($action_lower, 'create') !== false) {
        return 'fas fa-plus';
    } elseif (strpos($action_lower, 'edit') !== false || strpos($action_lower, 'ubah') !== false || strpos($action_lower, 'update') !== false) {
        return 'fas fa-edit';
    } elseif (strpos($action_lower, 'hapus') !== false || strpos($action_lower, 'delete') !== false || strpos($action_lower, 'remove') !== false) {
        return 'fas fa-trash';
    } elseif (strpos($action_lower, 'cetak') !== false || strpos($action_lower, 'print') !== false || strpos($action_lower, 'export') !== false) {
        return 'fas fa-print';
    } elseif (strpos($action_lower, 'upload') !== false || strpos($action_lower, 'import') !== false) {
        return 'fas fa-upload';
    } elseif (strpos($action_lower, 'download') !== false || strpos($action_lower, 'ambil') !== false) {
        return 'fas fa-download';
    } elseif (strpos($action_lower, 'setting') !== false || strpos($action_lower, 'profil') !== false || strpos($action_lower, 'profile') !== false) {
        return 'fas fa-cog';
    } elseif (strpos($action_lower, 'pesan') !== false || strpos($action_lower, 'chat') !== false || strpos($action_lower, 'message') !== false) {
        return 'fas fa-comment';
    } else {
        // Default icon
        return 'fas fa-user';
    }
}

// Function to get activity color based on action
function getActivityColor($action) {
    // Convert action to lowercase for comparison
    $action_lower = strtolower($action);
    
    if (strpos($action_lower, 'login') !== false || strpos($action_lower, 'masuk') !== false) {
        return 'bg-primary';
    } elseif (strpos($action_lower, 'logout') !== false || strpos($action_lower, 'keluar') !== false) {
        return 'bg-secondary';
    } elseif (strpos($action_lower, 'tambah') !== false || strpos($action_lower, 'add') !== false || strpos($action_lower, 'create') !== false) {
        return 'bg-success';
    } elseif (strpos($action_lower, 'edit') !== false || strpos($action_lower, 'ubah') !== false || strpos($action_lower, 'update') !== false) {
        return 'bg-warning';
    } elseif (strpos($action_lower, 'hapus') !== false || strpos($action_lower, 'delete') !== false || strpos($action_lower, 'remove') !== false) {
        return 'bg-danger';
    } elseif (strpos($action_lower, 'cetak') !== false || strpos($action_lower, 'print') !== false || strpos($action_lower, 'export') !== false) {
        return 'bg-info';
    } elseif (strpos($action_lower, 'upload') !== false || strpos($action_lower, 'import') !== false) {
        return 'bg-info';
    } elseif (strpos($action_lower, 'download') !== false || strpos($action_lower, 'ambil') !== false) {
        return 'bg-success';
    } elseif (strpos($action_lower, 'setting') !== false || strpos($action_lower, 'profil') !== false || strpos($action_lower, 'profile') !== false) {
        return 'bg-dark';
    } elseif (strpos($action_lower, 'absen') !== false || strpos($action_lower, 'kehadiran') !== false || strpos($action_lower, 'attendance') !== false) {
        return 'bg-success';
    } else {
        // Default color
        return 'bg-primary';
    }
}

// Function to log admin activity
function logActivity($pdo, $username, $action, $description = '') {
    // Check if session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Always allow logging - don't check user_id anymore
    // The username parameter is already provided, so we can log regardless of session state
    if (empty($username)) {
        error_log("logActivity: Username is empty. Action: $action");
        return false;
    }
    
    // Sanitize username to prevent SQL injection (though PDO prepare should handle this)
    $username = trim($username);
    if (empty($username)) {
        error_log("logActivity: Username is empty after trim. Action: $action");
        return false;
    }
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    
    try {
        // Don't clean up here - let dashboard handle it to avoid race conditions
        $stmt = $pdo->prepare("INSERT INTO tb_activity_log (username, action, description, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())");
        $result = $stmt->execute([$username, $action, $description, $ip_address]);
        
        if (!$result) {
            $errorInfo = $stmt->errorInfo();
            error_log("logActivity: Failed to insert activity. Username: $username, Action: $action, Description: $description, Error: " . print_r($errorInfo, true));
        }
        
        return $result;
    } catch (Exception $e) {
        error_log("logActivity: Exception - " . $e->getMessage() . " | Username: $username, Action: $action, Description: $description");
        return false;
    }
}

// Function to get all classes
function getAllKelas() {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get students by class
function getStudentsByClass($kelas_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT s.*, k.nama_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_kelas = ? ORDER BY s.nama_siswa ASC");
    $stmt->execute([$kelas_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get days with day names for a given month and year
function getDaysWithNames($month, $year) {
    $days = [];
    $jumlah_hari = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    
    for ($day = 1; $day <= $jumlah_hari; $day++) {
        $date = mktime(0, 0, 0, $month, $day, $year);
        $tanggal = date('Y-m-d', $date);
        $hari = date('l', $date);
        
        $hari_indonesia = [
            'Sunday' => 'Minggu',
            'Monday' => 'Senin',
            'Tuesday' => 'Selasa',
            'Wednesday' => 'Rabu',
            'Thursday' => 'Kamis',
            'Friday' => 'Jumat',
            'Saturday' => 'Sabtu'
        ];
        
        $days[] = [
            'tanggal' => $tanggal,
            'hari' => $hari_indonesia[$hari],
            'tanggal_angka' => $day
        ];
    }
    
    return $days;
}

// Function to get user avatar - either photo or initials
function getUserAvatar($user, $size = 40) {
    // If user has uploaded photo, return it
    if (!empty($user['foto'])) {
        return '../assets/img/' . $user['foto'];
    }
    
    // Otherwise, generate initials from username
    $initials = '';
    $username = $user['username'] ?? 'U';
    
    // Split username by spaces or other separators and get first letters
    $words = preg_split('/[\s_-]+/', $username);
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper(substr($word, 0, 1));
        }
    }
    
    // Limit to 2 characters max
    $initials = substr($initials, 0, 2);
    
    // Create a colored background based on the first letter for consistency
    $colors = [
        '#FF9AA2', '#FFB7B2', '#FFDAC1', '#E2F0CB', '#B5EAD7', '#C7CEEA', '#F8B195',
        '#F67280', '#C06C84', '#6C5B7B', '#355C7D', '#99B898', '#FECEAB', '#FF847C',
        '#E84A5F', '#2A363B', '#3E5151', '#7F8C8D', '#BDC3C7', '#95A5A6'
    ];
    
    $firstChar = ord(strtoupper($username[0])) % count($colors);
    $bg_color = $colors[$firstChar];
    
    // Create SVG with initials
    $svg = "<svg xmlns='http://www.w3.org/2000/svg' width='{$size}' height='{$size}' viewBox='0 0 100 100'>
            <rect width='100' height='100' rx='50' ry='50' fill='{$bg_color}'/>
            <text x='50' y='50' font-family='Arial, sans-serif' font-size='40' font-weight='bold' fill='#FFFFFF' text-anchor='middle' dominant-baseline='middle'>{$initials}</text>
          </svg>";
    
    // Encode SVG as data URI
    $data_uri = 'data:image/svg+xml;base64,' . base64_encode($svg);
    
    return $data_uri;
}

// Function to get user avatar image tag
function getUserAvatarImage($user, $size = 40) {
    $avatar_url = getUserAvatar($user, $size);
    $username = $user['username'] ?? 'User';
    
    if (strpos($avatar_url, 'data:image') === 0) {
        // It's an SVG data URI for initials
        return "<img src='" . $avatar_url . "' alt='" . $username . "' width='{$size}' height='{$size}' class='rounded-circle'>";
    } else {
        // It's a regular image file
        return "<img src='" . $avatar_url . "' alt='" . $username . "' width='{$size}' height='{$size}' class='rounded-circle'>";
    }
}

// Function to get teacher avatar - either photo or initials
function getTeacherAvatar($teacher, $size = 40) {
    // If teacher has uploaded photo, return it
    if (!empty($teacher['foto'])) {
        return '../uploads/' . $teacher['foto'];
    }
    
    // Otherwise, generate initials from teacher's name
    $initials = '';
    $name = $teacher['nama_guru'] ?? 'T';
    
    // Split name by spaces or other separators and get first letters
    $words = preg_split('/[\s_-]+/', $name);
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper(substr($word, 0, 1));
        }
    }
    
    // Limit to 2 characters max
    $initials = substr($initials, 0, 2);
    
    // Create a colored background based on the first letter for consistency
    $colors = [
        '#FF9AA2', '#FFB7B2', '#FFDAC1', '#E2F0CB', '#B5EAD7', '#C7CEEA', '#F8B195',
        '#F67280', '#C06C84', '#6C5B7B', '#355C7D', '#99B898', '#FECEAB', '#FF847C',
        '#E84A5F', '#2A363B', '#3E5151', '#7F8C8D', '#BDC3C7', '#95A5A6'
    ];
    
    $firstChar = ord(strtoupper($name[0])) % count($colors);
    $bg_color = $colors[$firstChar];
    
    // Create SVG with initials
    $svg = "<svg xmlns='http://www.w3.org/2000/svg' width='{$size}' height='{$size}' viewBox='0 0 100 100'>
            <rect width='100' height='100' rx='50' ry='50' fill='{$bg_color}'/>
            <text x='50' y='50' font-family='Arial, sans-serif' font-size='40' font-weight='bold' fill='#FFFFFF' text-anchor='middle' dominant-baseline='middle'>{$initials}</text>
          </svg>";
    
    // Encode SVG as data URI
    $data_uri = 'data:image/svg+xml;base64,' . base64_encode($svg);
    
    return $data_uri;
}

// Function to get teacher avatar image tag
function getTeacherAvatarImage($teacher, $size = 40) {
    $avatar_url = getTeacherAvatar($teacher, $size);
    $name = $teacher['nama_guru'] ?? 'Teacher';
    
    if (strpos($avatar_url, 'data:image') === 0) {
        // It's an SVG data URI for initials
        return "<img src='" . $avatar_url . "' alt='" . $name . "' width='{$size}' height='{$size}' class='rounded-circle'>";
    } else {
        // It's a regular image file
        return "<img src='" . $avatar_url . "' alt='" . $name . "' width='{$size}' height='{$size}' class='rounded-circle'>";
    }
}

// Function to get the class assigned to a wali
function getWaliKelas($pdo, $wali_id) {
    // First get the teacher's name based on ID
    $stmt = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([$wali_id]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$teacher) {
        return null;
    }
    
    // Then find the class where this teacher is assigned as wali
    $stmt = $pdo->prepare("SELECT k.* FROM tb_kelas k WHERE k.wali_kelas = ?");
    $stmt->execute([$teacher['nama_guru']]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

?>