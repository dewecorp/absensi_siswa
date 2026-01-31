<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

// Set page title
$page_title = 'Data Guru';

// Add Select2 CSS and JS
if (!isset($css_libs)) {
    $css_libs = [];
}
$css_libs[] = 'node_modules/select2/dist/css/select2.min.css';
$css_libs[] = 'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css';

if (!isset($js_libs)) {
    $js_libs = [];
}
$js_libs[] = 'node_modules/select2/dist/js/select2.full.min.js';
$js_libs[] = 'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js';
$js_libs[] = 'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js';

// Function to fetch teachers with wali kelas information
function fetchTeachersWithWaliKelas($pdo) {
    $stmt = $pdo->query("
        SELECT g.*, 
               GROUP_CONCAT(k.nama_kelas ORDER BY k.nama_kelas SEPARATOR ', ') as kelas_wali
        FROM tb_guru g
        LEFT JOIN tb_kelas k ON k.wali_kelas = g.nama_guru
        GROUP BY g.id_guru
        ORDER BY g.nama_guru ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle update teacher form submission (AJAX) - MUST BE FIRST
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_guru']) && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    $id_guru = (int)$_POST['id_guru'];
    $nama_guru = sanitizeInput($_POST['nama_guru']);
    $kode_guru = sanitizeInput($_POST['kode_guru'] ?? '');
    $nuptk = sanitizeInput($_POST['nuptk']);
    $tempat_lahir = sanitizeInput($_POST['tempat_lahir']);
    $tanggal_lahir = !empty($_POST['tanggal_lahir']) ? $_POST['tanggal_lahir'] : null;
    $jenis_kelamin = sanitizeInput($_POST['jenis_kelamin']);
    $password = $_POST['password'] ?? '';
    $mengajar = isset($_POST['mengajar']) ? json_encode($_POST['mengajar']) : null;
    
    $error_message = null;
    $foto = null;
    $update_foto = false;
    
    // Handle photo upload
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif'];
        $max_file_size = 2 * 1024 * 1024; // 2MB
        $file_extension = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
        
        // Validate MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->file($_FILES['foto']['tmp_name']);
        
        // Check file size
        if ($_FILES['foto']['size'] > $max_file_size) {
            $error_message = 'Ukuran foto terlalu besar! Maksimal 2MB.';
        } elseif (in_array($file_extension, $allowed_extensions) && in_array($mime_type, $allowed_mimes)) {
            // Create uploads directory if it doesn't exist
            $upload_dir = '../uploads/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Use safe filename
            $foto_filename = 'guru_' . time() . '_' . uniqid() . '.' . $file_extension;
            $target_path = $upload_dir . $foto_filename;
            
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $target_path)) {
                // Delete old photo if exists
                $current_teacher = $pdo->prepare("SELECT foto FROM tb_guru WHERE id_guru = ?");
                $current_teacher->execute([$id_guru]);
                $current_foto = $current_teacher->fetchColumn();
                
                if ($current_foto && file_exists($upload_dir . $current_foto)) {
                    unlink($upload_dir . $current_foto);
                }
                
                $foto = $foto_filename;
                $update_foto = true;
            } else {
                $error_message = 'Gagal mengunggah foto!';
            }
        } else {
            $error_message = 'Format foto tidak didukung atau file korup! Hanya JPG, JPEG, PNG, dan GIF yang diperbolehkan.';
        }
    }
    
    // Validate required fields
    if ($error_message) {
        echo json_encode(['success' => false, 'message' => $error_message]);
        exit;
    }
    
    if (empty($nama_guru) || empty($nuptk) || empty($jenis_kelamin)) {
        echo json_encode(['success' => false, 'message' => 'Harap lengkapi semua field yang wajib diisi!']);
        exit;
    }
    
    // Check if NUPTK already exists for another teacher
    $check_stmt = $pdo->prepare("SELECT id_guru FROM tb_guru WHERE nuptk = ? AND id_guru != ?");
    $check_stmt->execute([$nuptk, $id_guru]);
    
    if ($check_stmt->rowCount() > 0) {
        echo json_encode(['success' => false, 'message' => 'NUPTK sudah terdaftar oleh guru lain!']);
        exit;
    }

    // Check if Kode Guru already exists for another teacher
    if (!empty($kode_guru)) {
        $check_kode = $pdo->prepare("SELECT id_guru FROM tb_guru WHERE kode_guru = ? AND id_guru != ?");
        $check_kode->execute([$kode_guru, $id_guru]);
        
        if ($check_kode->rowCount() > 0) {
            echo json_encode(['success' => false, 'message' => 'Kode Guru sudah terdaftar oleh guru lain!']);
            exit;
        }
    }
    
    $params = [$nama_guru, $kode_guru, $nuptk, $tempat_lahir, $tanggal_lahir, $jenis_kelamin, $mengajar];
    $sql = "UPDATE tb_guru SET nama_guru=?, kode_guru=?, nuptk=?, tempat_lahir=?, tanggal_lahir=?, jenis_kelamin=?, mengajar=?";
    
    // Add password to update if provided
    if (!empty($password)) {
        $hashed_password = hashPassword($password);
        $sql .= ", password=?, password_plain=?";
        $params[] = $hashed_password;
        $params[] = $password; // Store plain text password
    }
    
    // Add foto to update if provided
    if ($update_foto) {
        $sql .= ", foto=?";
        $params[] = $foto;
    }
    
    $sql .= " WHERE id_guru=?";
    $params[] = $id_guru;
    
    $stmt = $pdo->prepare($sql);
    if ($stmt->execute($params)) {
        // Log activity - ensure session is available
        $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
        $log_result = logActivity($pdo, $username, 'Update Guru', "Memperbarui data guru: $nama_guru");
        
        if (!$log_result) {
            error_log("Failed to log activity for Update Guru: $nama_guru");
        }
        
        echo json_encode(['success' => true, 'message' => 'Data guru berhasil diperbarui!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal memperbarui data guru!']);
    }
    exit;
}

// Handle bulk edit form submission FIRST (before any output)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_edit_guru'])) {
    $ids = $_POST['bulk_edit_ids'] ?? [];
    $names = $_POST['bulk_edit_nama'] ?? [];
    $kode_gurus = $_POST['bulk_edit_kode_guru'] ?? [];
    $nuptks = $_POST['bulk_edit_nuptk'] ?? [];
    $jenis_kelamins = $_POST['bulk_edit_jenis_kelamin'] ?? [];
    
    if (!empty($ids) && is_array($ids)) {
        $updatedCount = 0;
        $errors = [];
        
        for ($i = 0; $i < count($ids); $i++) {
            if (isset($ids[$i]) && !empty($ids[$i])) {
                $id = (int)$ids[$i];
                $nama = sanitizeInput($names[$i] ?? '');
                $kode_guru = sanitizeInput($kode_gurus[$i] ?? '');
                $nuptk = sanitizeInput($nuptks[$i] ?? '');
                $jenis_kelamin = sanitizeInput($jenis_kelamins[$i] ?? '');
                
                // Validate
                if (empty($nama) || empty($nuptk) || empty($jenis_kelamin)) {
                    $errors[] = "Data ke-" . ($i + 1) . " tidak lengkap";
                    continue;
                }
                
                // Check if NUPTK already exists for another teacher
                $check_stmt = $pdo->prepare("SELECT id_guru FROM tb_guru WHERE nuptk = ? AND id_guru != ?");
                $check_stmt->execute([$nuptk, $id]);
                
                if ($check_stmt->rowCount() > 0) {
                    $errors[] = "NUPTK " . $nuptk . " sudah terdaftar oleh guru lain";
                    continue;
                }

                // Check if Kode Guru already exists for another teacher (if not empty)
                if (!empty($kode_guru)) {
                    $check_kode = $pdo->prepare("SELECT id_guru FROM tb_guru WHERE kode_guru = ? AND id_guru != ?");
                    $check_kode->execute([$kode_guru, $id]);
                    
                    if ($check_kode->rowCount() > 0) {
                        $errors[] = "Kode Guru " . $kode_guru . " sudah terdaftar oleh guru lain";
                        continue;
                    }
                }
                
                // Update
                $stmt = $pdo->prepare("UPDATE tb_guru SET nama_guru=?, kode_guru=?, nuptk=?, jenis_kelamin=? WHERE id_guru=?");
                if ($stmt->execute([$nama, $kode_guru, $nuptk, $jenis_kelamin, $id])) {
                    $updatedCount++;
                }
            }
        }
        
        header('Content-Type: application/json');
        if ($updatedCount > 0) {
            $message = "Berhasil memperbarui $updatedCount data guru!";
            if (!empty($errors)) {
                $message .= " " . count($errors) . " data gagal: " . implode(', ', $errors);
            }
            echo json_encode([
                'success' => true,
                'message' => $message
            ]);
            $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
            $log_result = logActivity($pdo, $username, 'Bulk Edit Guru', "Memperbarui $updatedCount data guru");
            if (!$log_result) error_log("Failed to log activity for Bulk Edit Guru: $updatedCount data");
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Gagal memperbarui data: ' . implode(', ', $errors)
            ]);
        }
        exit;
    }
}

// Handle form submissions
$message = null; // Initialize as null instead of empty string

// Handle AJAX request to get teacher data for bulk edit
if (isset($_POST['get_teacher_data'])) {
    $ids = $_POST['get_teacher_data'];
    $teachers = [];
    
    if (is_array($ids)) {
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $pdo->prepare("SELECT * FROM tb_guru WHERE id_guru IN ($placeholders)");
        $stmt->execute($ids);
        $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'teachers' => $teachers]);
    exit;
}

// Handle bulk update for selected teachers
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') == 'POST' && isset($_POST['bulk_update_guru'])) {
    $ids = $_POST['id_guru'] ?? [];
    $names = $_POST['nama_guru'] ?? [];
    $kode_gurus = $_POST['kode_guru'] ?? [];
    $nuptks = $_POST['nuptk'] ?? [];
    $tempat_lahir = $_POST['tempat_lahir'] ?? [];
    $tanggal_lahir = $_POST['tanggal_lahir'] ?? [];
    $jenis_kelamin = $_POST['jenis_kelamin'] ?? [];
    $wali_kelas = $_POST['wali_kelas'] ?? [];
    $passwords = $_POST['password'] ?? [];
    
    if (is_array($ids) && count($ids) > 0) {
        $updated_count = 0;
        for ($i = 0; $i < count($ids); $i++) {
            if (isset($ids[$i]) && !empty($ids[$i])) {
                $id = $ids[$i];
                $data = [
                    'nama_guru' => isset($names[$i]) ? sanitizeInput($names[$i]) : '',
                    'kode_guru' => isset($kode_gurus[$i]) ? sanitizeInput($kode_gurus[$i]) : '',
                    'nuptk' => isset($nuptks[$i]) ? sanitizeInput($nuptks[$i]) : '',
                    'tempat_lahir' => isset($tempat_lahir[$i]) ? sanitizeInput($tempat_lahir[$i]) : '',
                    'tanggal_lahir' => isset($tanggal_lahir[$i]) ? sanitizeInput($tanggal_lahir[$i]) : '',
                    'jenis_kelamin' => isset($jenis_kelamin[$i]) ? sanitizeInput($jenis_kelamin[$i]) : '',
                    'wali_kelas' => isset($wali_kelas[$i]) ? sanitizeInput($wali_kelas[$i]) : ''
                ];
                
                if (!empty($passwords[$i])) {
                    $data['password'] = hashPassword($passwords[$i]);
                    $data['password_plain'] = $passwords[$i]; // Store plain text password
                }
                
                $stmt = $pdo->prepare("UPDATE tb_guru SET nama_guru = ?, kode_guru = ?, nuptk = ?, tempat_lahir = ?, tanggal_lahir = ?, jenis_kelamin = ?, wali_kelas = ?" . 
                    (!empty($passwords[$i]) ? ", password = ?, password_plain = ?" : "") . " WHERE id_guru = ?");
                
                $params = [$data['nama_guru'], $data['kode_guru'], $data['nuptk'], $data['tempat_lahir'], $data['tanggal_lahir'], $data['jenis_kelamin'], $data['wali_kelas']];
                if (!empty($passwords[$i])) {
                    $params[] = $data['password'];
                    $params[] = $data['password_plain'];
                }
                $params[] = $id;
                
                if ($stmt->execute($params)) {
                    $updated_count++;
                }
            }
        }
        
        if ($updated_count > 0) {
            $message = ['type' => 'success', 'text' => "Berhasil memperbarui $updated_count data guru!"];
            
            // Log activity - ensure session is available
            $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
            $log_result = logActivity($pdo, $username, 'Bulk Update Guru', "Bulk memperbarui $updated_count data guru");
            
            if (!$log_result) {
                error_log("Failed to log activity for Bulk Update Guru: $updated_count data");
            }
        } else {
            $message = ['type' => 'danger', 'text' => 'Gagal memperbarui data guru!'];
        }
    }
}

// Handle delete teacher
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') == 'POST' && isset($_POST['delete_guru'])) {
    $id_guru = $_POST['id_guru'] ?? '';
    
    if (!empty($id_guru)) {
        // Check if teacher is assigned as class advisor
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM tb_kelas WHERE wali_kelas = (SELECT nama_guru FROM tb_guru WHERE id_guru = ?)");
        $check_stmt->execute([$id_guru]);
        $kelas_count = $check_stmt->fetchColumn();
        
        if ($kelas_count > 0) {
            $message = ['type' => 'danger', 'text' => 'Guru tidak dapat dihapus karena masih menjadi wali kelas!'];
        } else {
            // Get teacher name for logging
            $name_stmt = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
            $name_stmt->execute([$id_guru]);
            $teacher_name = $name_stmt->fetchColumn();
            
            $stmt = $pdo->prepare("DELETE FROM tb_guru WHERE id_guru = ?");
            if ($stmt->execute([$id_guru])) {
                $message = ['type' => 'success', 'text' => 'Data guru berhasil dihapus!'];
                
                // Log activity - ensure session is available
                $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                $log_result = logActivity($pdo, $username, 'Hapus Guru', "Menghapus data guru: $teacher_name");
                
                if (!$log_result) {
                    error_log("Failed to log activity for Hapus Guru: $teacher_name");
                }
            } else {
                $message = ['type' => 'danger', 'text' => 'Gagal menghapus data guru!'];
            }
        }
    }
}

// Handle bulk delete
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') == 'POST' && isset($_POST['bulk_delete_guru'])) {
    $ids = $_POST['id_guru'] ?? [];
    
    if (is_array($ids) && count($ids) > 0) {
        $deleted_count = 0;
        foreach ($ids as $id) {
            if (!empty($id)) {
                // Check if teacher is assigned as class advisor
                $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM tb_kelas WHERE wali_kelas = (SELECT nama_guru FROM tb_guru WHERE id_guru = ?)");
                $check_stmt->execute([$id]);
                $kelas_count = $check_stmt->fetchColumn();
                
                if ($kelas_count == 0) {
                    $stmt = $pdo->prepare("DELETE FROM tb_guru WHERE id_guru = ?");
                    if ($stmt->execute([$id])) {
                        $deleted_count++;
                    }
                }
            }
        }
        
        if ($deleted_count > 0) {
            $message = ['type' => 'success', 'text' => "Berhasil menghapus $deleted_count data guru!"];
            
            // Log activity - ensure session is available
            $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
            $log_result = logActivity($pdo, $username, 'Bulk Hapus Guru', "Bulk menghapus $deleted_count data guru");
            
            if (!$log_result) {
                error_log("Failed to log activity for Bulk Hapus Guru: $deleted_count data");
            }
        } else {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus data guru!'];
        }
    }
}

// Handle add teacher form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_guru'])) {
    $nama_guru = sanitizeInput($_POST['nama_guru']);
    $kode_guru = sanitizeInput($_POST['kode_guru'] ?? '');
    $nuptk = sanitizeInput($_POST['nuptk']);
    $tempat_lahir = sanitizeInput($_POST['tempat_lahir']);
    $tanggal_lahir = !empty($_POST['tanggal_lahir']) ? $_POST['tanggal_lahir'] : null;
    $jenis_kelamin = sanitizeInput($_POST['jenis_kelamin']);
    $password = $_POST['password'];
    $mengajar = isset($_POST['mengajar']) ? json_encode($_POST['mengajar']) : null;
    
    // Handle photo upload
    $foto = null;
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        $max_file_size = 2 * 1024 * 1024; // 2MB
        $file_extension = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
        
        // Check file size
        if ($_FILES['foto']['size'] > $max_file_size) {
            $message = ['type' => 'danger', 'text' => 'Ukuran foto terlalu besar! Maksimal 2MB.'];
        } elseif (in_array($file_extension, $allowed_extensions)) {
            // Create uploads directory if it doesn't exist
            $upload_dir = '../uploads/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $foto_filename = 'guru_' . time() . '_' . basename($_FILES['foto']['name']);
            $target_path = $upload_dir . $foto_filename;
            
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $target_path)) {
                $foto = $foto_filename;
            } else {
                $message = ['type' => 'danger', 'text' => 'Gagal mengunggah foto!'];
            }
        } else {
            $message = ['type' => 'danger', 'text' => 'Format foto tidak didukung! Hanya JPG, JPEG, PNG, dan GIF yang diperbolehkan.'];
        }
    }
    
    // Validate required fields
    if (empty($nama_guru) || empty($nuptk) || empty($jenis_kelamin)) {
        $message = ['type' => 'danger', 'text' => 'Harap lengkapi semua field yang wajib diisi!'];
    } else {
        // Check if NUPTK already exists
        $check_stmt = $pdo->prepare("SELECT id_guru FROM tb_guru WHERE nuptk = ?");
        $check_stmt->execute([$nuptk]);
        
        if ($check_stmt->rowCount() > 0) {
            $message = ['type' => 'danger', 'text' => 'NUPTK sudah terdaftar!'];
        } else {
            // Check if Kode Guru already exists
            $kode_exists = false;
            if (!empty($kode_guru)) {
                $check_kode = $pdo->prepare("SELECT id_guru FROM tb_guru WHERE kode_guru = ?");
                $check_kode->execute([$kode_guru]);
                if ($check_kode->rowCount() > 0) {
                    $message = ['type' => 'danger', 'text' => 'Kode Guru sudah terdaftar!'];
                    $kode_exists = true;
                }
            }

            if (!$kode_exists) {
                // Hash password if provided, otherwise use default
                $default_password = '123456';
                $password_to_use = !empty($password) ? $password : $default_password;
                $hashed_password = hashPassword($password_to_use);
                $password_plain = $password_to_use; // Store plain text password
                
                $stmt = $pdo->prepare("INSERT INTO tb_guru (nama_guru, kode_guru, nuptk, tempat_lahir, tanggal_lahir, jenis_kelamin, mengajar, password, password_plain, foto) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt->execute([$nama_guru, $kode_guru, $nuptk, $tempat_lahir, $tanggal_lahir, $jenis_kelamin, $mengajar, $hashed_password, $password_plain, $foto])) {
                    $message = ['type' => 'success', 'text' => 'Data guru berhasil ditambahkan!'];
                    
                    // Log activity - ensure session is available
                    $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                    $log_result = logActivity($pdo, $username, 'Tambah Guru', "Menambahkan data guru: $nama_guru");
                    
                    if (!$log_result) {
                        error_log("Failed to log activity for Tambah Guru: $nama_guru");
                    }
                    
                    // Refresh data
                    $teachers = fetchTeachersWithWaliKelas($pdo);
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal menambahkan data guru!'];
                }
            }
        }
    }
}

// Handle update teacher form submission (non-AJAX fallback)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_guru']) && !isset($_POST['ajax'])) {
    $id_guru = (int)$_POST['id_guru'];
    $nama_guru = sanitizeInput($_POST['nama_guru']);
    $kode_guru = sanitizeInput($_POST['kode_guru'] ?? '');
    $nuptk = sanitizeInput($_POST['nuptk']);
    $tempat_lahir = sanitizeInput($_POST['tempat_lahir']);
    $tanggal_lahir = !empty($_POST['tanggal_lahir']) ? $_POST['tanggal_lahir'] : null;
    $jenis_kelamin = sanitizeInput($_POST['jenis_kelamin']);
    $password = $_POST['password'];
    $mengajar = isset($_POST['mengajar']) ? json_encode($_POST['mengajar']) : null;
    
    // Handle photo upload
    $foto = null;
    $update_foto = false;
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif'];
        $max_file_size = 2 * 1024 * 1024; // 2MB
        $file_extension = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
        
        // Validate MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->file($_FILES['foto']['tmp_name']);
        
        // Check file size
        if ($_FILES['foto']['size'] > $max_file_size) {
            $message = ['type' => 'danger', 'text' => 'Ukuran foto terlalu besar! Maksimal 2MB.'];
        } elseif (in_array($file_extension, $allowed_extensions) && in_array($mime_type, $allowed_mimes)) {
            // Create uploads directory if it doesn't exist
            $upload_dir = '../uploads/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Use safe filename
            $foto_filename = 'guru_' . time() . '_' . uniqid() . '.' . $file_extension;
            $target_path = $upload_dir . $foto_filename;
            
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $target_path)) {
                // Delete old photo if exists
                $current_teacher = $pdo->prepare("SELECT foto FROM tb_guru WHERE id_guru = ?");
                $current_teacher->execute([$id_guru]);
                $current_foto = $current_teacher->fetchColumn();
                
                if ($current_foto && file_exists($upload_dir . $current_foto)) {
                    unlink($upload_dir . $current_foto);
                }
                
                $foto = $foto_filename;
                $update_foto = true;
            } else {
                $message = ['type' => 'danger', 'text' => 'Gagal mengunggah foto!'];
            }
        } else {
            $message = ['type' => 'danger', 'text' => 'Format foto tidak didukung atau file korup! Hanya JPG, JPEG, PNG, dan GIF yang diperbolehkan.'];
        }
    }
    
    // Validate required fields
    if (empty($nama_guru) || empty($nuptk) || empty($jenis_kelamin)) {
        $message = ['type' => 'danger', 'text' => 'Harap lengkapi semua field yang wajib diisi!'];
    } else {
        // Check if NUPTK already exists for another teacher
        $check_stmt = $pdo->prepare("SELECT id_guru FROM tb_guru WHERE nuptk = ? AND id_guru != ?");
        $check_stmt->execute([$nuptk, $id_guru]);
        
        if ($check_stmt->rowCount() > 0) {
            $message = ['type' => 'danger', 'text' => 'NUPTK sudah terdaftar oleh guru lain!'];
        } else {
            $params = [$nama_guru, $nuptk, $tempat_lahir, $tanggal_lahir, $jenis_kelamin, $mengajar];
            $sql = "UPDATE tb_guru SET nama_guru=?, nuptk=?, tempat_lahir=?, tanggal_lahir=?, jenis_kelamin=?, mengajar=?";
            
            // Add password to update if provided
            if (!empty($password)) {
                $hashed_password = hashPassword($password);
                $sql .= ", password=?, password_plain=?";
                $params[] = $hashed_password;
                $params[] = $password; // Store plain text password
            }
            
            // Add foto to update if provided
            if ($update_foto) {
                $sql .= ", foto=?";
                $params[] = $foto;
            }
            
            $sql .= " WHERE id_guru=?";
            $params[] = $id_guru;
            
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute($params)) {
                $message = ['type' => 'success', 'text' => 'Data guru berhasil diperbarui!'];
                
                // Log activity - ensure session is available
                $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                $log_result = logActivity($pdo, $username, 'Update Guru', "Memperbarui data guru: $nama_guru");
                
                if (!$log_result) {
                    error_log("Failed to log activity for Update Guru: $nama_guru");
                }
                
                // Refresh data
                $teachers = fetchTeachersWithWaliKelas($pdo);
            } else {
                $message = ['type' => 'danger', 'text' => 'Gagal memperbarui data guru!'];
            }
        }
    }
}

// Handle bulk operations via AJAX
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') === 'POST') {
    if (isset($_POST['bulk_update']) && $_POST['bulk_update'] === '1') {
        // Handle bulk update
        $ids = json_decode($_POST['ids'], true);
        $fields = $_POST['fields'];
        $newValue = trim($_POST['new_value']);
        
        if (!empty($ids) && !empty($fields) && $newValue !== '') {
            try {
                $updatedCount = 0;
                
                foreach ($fields as $field) {
                    // Validate field name to prevent SQL injection
                    $allowedFields = ['nama_guru', 'kode_guru', 'nuptk', 'tempat_lahir', 'tanggal_lahir', 'jenis_kelamin', 'wali_kelas'];
                    if (!in_array($field, $allowedFields)) {
                        continue; // Skip invalid fields
                    }
                    
                    // Prepare the update query
                    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                    $sql = "UPDATE tb_guru SET $field = ? WHERE id_guru IN ($placeholders)";
                    
                    // Prepare values array: [new_value, id1, id2, ...]
                    $values = array_merge([$newValue], $ids);
                    
                    $stmt = $pdo->prepare($sql);
                    if ($stmt->execute($values)) {
                        $updatedCount += $stmt->rowCount();
                    }
                }
                
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => "Berhasil memperbarui $updatedCount data guru!"
                ]);
            } catch (Exception $e) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Gagal memperbarui data: ' . $e->getMessage()
                ]);
            }
        } else {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Data tidak lengkap!'
            ]);
        }
        exit;
    }
    
    if (isset($_POST['bulk_delete']) && $_POST['bulk_delete'] === '1') {
        // Handle bulk delete
        $ids = json_decode($_POST['ids'], true);
        
        if (!empty($ids)) {
            try {
                $deletedCount = 0;
                
                foreach ($ids as $id) {
                    // Check if teacher is assigned as class advisor
                    $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM tb_kelas WHERE wali_kelas = (SELECT nama_guru FROM tb_guru WHERE id_guru = ?)");
                    $check_stmt->execute([$id]);
                    $kelas_count = $check_stmt->fetchColumn();
                    
                    if ($kelas_count == 0) {
                        $stmt = $pdo->prepare("DELETE FROM tb_guru WHERE id_guru = ?");
                        if ($stmt->execute([$id])) {
                            $deletedCount++;
                        }
                    }
                }
                
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => "Berhasil menghapus $deletedCount data guru!"
                ]);
            } catch (Exception $e) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Gagal menghapus data: ' . $e->getMessage()
                ]);
            }
        } else {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Tidak ada data yang dipilih!'
            ]);
        }
        exit;
    }
}

// DEBUG: Check before data fetching
echo "<!-- DEBUG: Before data fetching -->\n";

// Fetch all teachers with their wali kelas information
$teachers = fetchTeachersWithWaliKelas($pdo);

// DEBUG: Check after data fetching
echo "<!-- DEBUG: After data fetching, teachers count: " . count($teachers) . " -->\n";

// Fetch all classes for dropdown
$classes_stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get school profile for export functions
try {
    $school_profile = getSchoolProfile($pdo);
} catch (Exception $e) {
    $school_profile = ['nama_madrasah' => 'Madrasah Ibtidaiyah Negeri Pembina Kota Padang'];
    error_log('Failed to get school profile: ' . $e->getMessage());
}

// Add JavaScript for bulk operations
if (!isset($js_page)) {
    $js_page = [];
}

$js_page[] = "
// Define bulk functions globally
window.bulkEdit = function() {
    console.log('bulkEdit function called!');
    if (typeof jQuery === 'undefined' || typeof $ === 'undefined') {
        console.error('jQuery is not loaded!');
        Swal.fire({
            title: 'Error!',
            text: 'jQuery tidak dimuat. Silakan refresh halaman.',
            icon: 'error',
            confirmButtonText: 'OK'
        });
        return;
    }
    var checkedBoxes = $('input[type=\"checkbox\"][data-checkboxes=\"guru\"]:checked').not('[data-checkbox-role=\"dad\"]');
    var selectedIds = [];
    
    checkedBoxes.each(function() {
        var id = $(this).val();
        if (id && id !== 'on') {
            selectedIds.push(id);
        }
    });
    
    if (selectedIds.length === 0) {
        Swal.fire({
            title: 'Tidak ada data terpilih',
            text: 'Silakan pilih setidaknya satu data guru untuk diedit.',
            icon: 'warning'
        });
        return;
    }
    
    // Get selected teacher data
    var selectedTeachers = [];
    checkedBoxes.each(function() {
        var id = $(this).val();
        if (id && id !== 'on') {
            var row = $(this).closest('tr');
            var cells = row.find('td');
            selectedTeachers.push({
                id: id,
                nama: cells.eq(3).text().trim(),
                kode_guru: cells.eq(4).text().trim(),
                nuptk: cells.eq(5).text().trim(),
                jenis_kelamin: cells.eq(7).text().trim()
            });
        }
    });
    
    // Populate modal table
    var tableBody = $('#bulkEditModal tbody');
    tableBody.empty();
    
    selectedTeachers.forEach(function(teacher, index) {
        var row = '<tr>' +
            '<td>' + (index + 1) + '</td>' +
            '<td>' + teacher.nama + '</td>' +
            '<td><input type=\"text\" class=\"form-control form-control-sm\" name=\"bulk_edit_nama[]\" value=\"' + teacher.nama.replace(/\"/g, '&quot;') + '\" required></td>' +
            '<td><input type=\"text\" class=\"form-control form-control-sm\" name=\"bulk_edit_kode_guru[]\" value=\"' + teacher.kode_guru.replace(/\"/g, '&quot;') + '\" required></td>' +
            '<td><input type=\"text\" class=\"form-control form-control-sm\" name=\"bulk_edit_nuptk[]\" value=\"' + teacher.nuptk.replace(/\"/g, '&quot;') + '\" required></td>' +
            '<td>' +
            '<select class=\"form-control form-control-sm\" name=\"bulk_edit_jenis_kelamin[]\" required>' +
            '<option value=\"Laki-laki\"' + (teacher.jenis_kelamin === 'Laki-laki' ? ' selected' : '') + '>Laki-laki</option>' +
            '<option value=\"Perempuan\"' + (teacher.jenis_kelamin === 'Perempuan' ? ' selected' : '') + '>Perempuan</option>' +
            '</select>' +
            '<input type=\"hidden\" name=\"bulk_edit_ids[]\" value=\"' + teacher.id + '\">' +
            '</td>' +
            '</tr>';
        tableBody.append(row);
    });
    
    // Update count
    $('#bulkEditCount').text(selectedTeachers.length);
    
    // Show modal
    $('#bulkEditModal').modal('show');
};

window.bulkDelete = function() {
    if (typeof jQuery === 'undefined' || typeof $ === 'undefined') {
        console.error('jQuery is not loaded!');
        Swal.fire({
            title: 'Error!',
            text: 'jQuery tidak dimuat. Silakan refresh halaman.',
            icon: 'error',
            confirmButtonText: 'OK'
        });
        return;
    }
    console.log('bulkDelete function called!');
    var checkedBoxes = $('input[type=\"checkbox\"][data-checkboxes=\"guru\"]:checked').not('[data-checkbox-role=\"dad\"]');
    var selectedIds = [];
    var selectedNames = [];
    
    checkedBoxes.each(function() {
        var id = $(this).val();
        if (id && id !== 'on') {
            selectedIds.push(id);
            // Nama guru ada di kolom ke-4 (index 3) setelah No, Checkbox, Foto
            var row = $(this).closest('tr');
            var nameCell = row.find('td').eq(3); // Nama Guru column
            var name = nameCell.text().trim();
            if (name) {
                selectedNames.push(name);
            }
        }
    });
    
    if (selectedIds.length === 0) {
        Swal.fire({
            title: 'Tidak ada data terpilih',
            text: 'Silakan pilih setidaknya satu data guru untuk dihapus.',
            icon: 'warning'
        });
        return;
    }
    
    var deleteMessage = 'Apakah Anda yakin ingin menghapus <strong>' + selectedIds.length + ' data guru</strong>?';
    if (selectedNames.length > 0 && selectedNames.length <= 5) {
        deleteMessage += '<br><br>Data yang akan dihapus:<br>' + selectedNames.join('<br>');
    } else if (selectedNames.length > 5) {
        deleteMessage += '<br><br>Data yang akan dihapus:<br>' + selectedNames.slice(0, 5).join('<br>') + '<br>... dan ' + (selectedNames.length - 5) + ' data lainnya';
    }
    
    Swal.fire({
        title: 'Konfirmasi Hapus',
        html: deleteMessage,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Ya, Hapus!',
        cancelButtonText: 'Batal'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'data_guru.php',
                type: 'POST',
                data: {
                    'bulk_delete': '1',
                    'ids': JSON.stringify(selectedIds)
                },
                dataType: 'json',
                success: function(response) {
                    if (response && response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Dihapus!',
                            text: response.message,
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Gagal!', response ? response.message : 'Terjadi kesalahan', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error, xhr.responseText);
                    Swal.fire('Error!', 'Terjadi kesalahan saat menghapus data: ' + error, 'error');
                }
            });
        }
    });
};

$(document).ready(function() {
    // Function to update button states based on currently selected checkboxes
    function updateBulkButtons() {
        const checkedCount = $('input[data-checkboxes=\"guru\"]:checked:not([data-checkbox-role=\"dad\"])').length;
        const totalCount = $('input[data-checkboxes=\"guru\"]:not([data-checkbox-role=\"dad\"])').length;
        
        // Check if all visible checkboxes are checked (for select all functionality with DataTables)
        var visibleCheckboxes = $('#table-1 tbody tr:visible input[data-checkboxes=\"guru\"]:not([data-checkbox-role=\"dad\"])');
        var visibleChecked = visibleCheckboxes.filter(':checked').length;
        var allVisibleChecked = visibleCheckboxes.length > 0 && visibleChecked === visibleCheckboxes.length;
        
        $('#bulk-edit-btn').prop('disabled', checkedCount === 0);
        $('#bulk-delete-btn').prop('disabled', checkedCount === 0);
        
        // Update select all checkbox state based on visible checkboxes
        $('#checkbox-all').prop('checked', allVisibleChecked && totalCount > 0);
        $('#checkbox-all').prop('indeterminate', checkedCount > 0 && checkedCount < totalCount);
        
        console.log('Checked count:', checkedCount, 'Total:', totalCount, 'Visible checked:', visibleChecked, 'Visible total:', visibleCheckboxes.length);
    }
    
    // Handle select all checkbox
    $(document).on('change', '#checkbox-all', function() {
        const isChecked = $(this).is(':checked');
        // Only check/uncheck visible checkboxes (current page)
        $('#table-1 tbody tr:visible input[data-checkboxes=\"guru\"]:not([data-checkbox-role=\"dad\"])').prop('checked', isChecked);
        updateBulkButtons();
    });
    
    // Use event delegation to handle individual checkbox changes
    $(document).on('change', 'input[data-checkboxes=\"guru\"]:not([data-checkbox-role=\"dad\"])', function() {
        updateBulkButtons();
    });
    
    // Attach click handlers to bulk action buttons
    $('#bulk-edit-btn').on('click', function(e) {
        e.preventDefault();
        if (typeof window.bulkEdit === 'function') {
            window.bulkEdit();
        } else {
            console.error('bulkEdit function is not available');
            Swal.fire({
                title: 'Error!',
                text: 'Fungsi edit belum dimuat. Silakan refresh halaman.',
                icon: 'error',
                confirmButtonText: 'OK'
            });
        }
    });
    
    $('#bulk-delete-btn').on('click', function(e) {
        e.preventDefault();
        if (typeof window.bulkDelete === 'function') {
            window.bulkDelete();
        } else {
            console.error('bulkDelete function is not available');
            Swal.fire({
                title: 'Error!',
                text: 'Fungsi hapus belum dimuat. Silakan refresh halaman.',
                icon: 'error',
                confirmButtonText: 'OK'
            });
        }
    });

    // Mobile buttons handlers
    $(document).on('click', '#bulk-edit-btn-mobile', function(e) {
        e.preventDefault();
        if ($(this).hasClass('disabled')) return;
        
        if (typeof window.bulkEdit === 'function') {
            window.bulkEdit();
        }
    });

    $(document).on('click', '#bulk-delete-btn-mobile', function(e) {
        e.preventDefault();
        if ($(this).hasClass('disabled')) return;
        
        if (typeof window.bulkDelete === 'function') {
            window.bulkDelete();
        }
    });
    
    // Initialize DataTables with pagination and show entries
    // Wait for DataTables library to be fully loaded
    function initDataTable() {
        if (typeof $.fn.DataTable !== 'undefined') {
            // Check if DataTable is already initialized
            if ($.fn.DataTable.isDataTable('#table-1')) {
                // If already initialized, destroy and reinitialize
                $('#table-1').DataTable().destroy();
            }
            
            $('#table-1').DataTable({
                \"columnDefs\": [
                    { \"sortable\": false, \"targets\": [1, 2, 11] } // Disable sorting for checkbox, foto, and action columns
                ],
                \"paging\": true,
                \"lengthChange\": true,
                \"pageLength\": 10,
                \"lengthMenu\": [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Semua']],
                \"dom\": 'lfrtip', // length, filter, info, pagination, table
                \"info\": true,
                \"language\": {
                    \"lengthMenu\": \"Tampilkan _MENU_ entri\",
                    \"zeroRecords\": \"Tidak ada data yang ditemukan\",
                    \"info\": \"Menampilkan _START_ sampai _END_ dari _TOTAL_ entri\",
                    \"infoEmpty\": \"Menampilkan 0 sampai 0 dari 0 entri\",
                    \"infoFiltered\": \"(disaring dari _MAX_ total entri)\",
                    \"search\": \"Cari:\",
                    \"paginate\": {
                        \"first\": \"Pertama\",
                        \"last\": \"Terakhir\",
                        \"next\": \"Selanjutnya\",
                        \"previous\": \"Sebelumnya\"
                    }
                },
                \"drawCallback\": function(settings) {
                    // Update bulk buttons after table redraw
                    setTimeout(updateBulkButtons, 100);
                }
            });
            console.log('DataTables initialized successfully');
        } else {
            console.warn('DataTables library not loaded, retrying...');
            // Retry after a short delay
            setTimeout(initDataTable, 100);
        }
    }
    
    // Initialize DataTables after a short delay to ensure libraries are loaded
    setTimeout(initDataTable, 200);
    
    // Also try to initialize immediately
    initDataTable();
});

// Debug: Verify functions are available
console.log('Bulk functions loaded:', typeof window.bulkEdit, typeof window.bulkDelete);
if (typeof window.bulkEdit === 'undefined') {
    console.error('bulkEdit function is not defined!');
}
if (typeof window.bulkDelete === 'undefined') {
    console.error('bulkDelete function is not defined!');
}";

// DEBUG: Check before template inclusion
echo "<!-- DEBUG: Before template inclusion -->\n";

include '../templates/header.php';
include '../templates/sidebar.php';

echo "<!-- DEBUG: After template inclusion -->\n";
?>
            <!-- Main Content -->
            <div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Data Guru</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                            <div class="breadcrumb-item">Data Guru</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h4>Daftar Guru</h4>
                                    <div class="card-header-action">
                                        <!-- Desktop View -->
                                        <div class="d-none d-md-block">
                                            <a href="#" class="btn btn-primary" data-toggle="modal" data-target="#addModal"><i class="fas fa-plus"></i> Tambah Guru</a>
                                            <a href="#" class="btn btn-info" data-toggle="modal" data-target="#importModal" onclick="setImportType('guru')"><i class="fas fa-file-import"></i> Impor Excel</a>
                                            <button type="button" class="btn btn-success" onclick="exportGuruToExcel()"><i class="fas fa-file-excel"></i> Ekspor Excel</button>
                                            <button type="button" class="btn btn-danger" onclick="exportGuruToPDF()"><i class="fas fa-file-pdf"></i> Ekspor PDF</button>
                                            <a href="cetak_qr_guru.php?all=1" target="_blank" class="btn btn-dark"><i class="fas fa-qrcode"></i> Cetak Semua QR</a>
                                            <button type="button" class="btn btn-warning" id="bulk-edit-btn" disabled><i class="fas fa-edit"></i> Edit Terpilih</button>
                                            <button type="button" class="btn btn-danger" id="bulk-delete-btn" disabled><i class="fas fa-trash"></i> Hapus Terpilih</button>
                                        </div>
                                        <!-- Mobile View -->
                                        <div class="d-block d-md-none">
                                            <div class="dropdown">
                                                <button class="btn btn-primary dropdown-toggle btn-block" type="button" id="actionMenuButton" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                                    <i class="fas fa-cogs"></i> Menu Aksi
                                                </button>
                                                <div class="dropdown-menu dropdown-menu-right w-100" aria-labelledby="actionMenuButton">
                                                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#addModal"><i class="fas fa-plus mr-2"></i> Tambah Guru</a>
                                                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#importModal" onclick="setImportType('guru')"><i class="fas fa-file-import mr-2"></i> Impor Excel</a>
                                                    <a class="dropdown-item" href="#" onclick="exportGuruToExcel()"><i class="fas fa-file-excel mr-2"></i> Ekspor Excel</a>
                                                    <a class="dropdown-item" href="#" onclick="exportGuruToPDF()"><i class="fas fa-file-pdf mr-2"></i> Ekspor PDF</a>
                                                    <a class="dropdown-item" href="cetak_qr_guru.php?all=1" target="_blank"><i class="fas fa-qrcode mr-2"></i> Cetak Semua QR</a>
                                                    <div class="dropdown-divider"></div>
                                                    <a class="dropdown-item text-warning disabled" href="#" id="bulk-edit-btn-mobile"><i class="fas fa-edit mr-2"></i> Edit Terpilih</a>
                                                    <a class="dropdown-item text-danger disabled" href="#" id="bulk-delete-btn-mobile"><i class="fas fa-trash mr-2"></i> Hapus Terpilih</a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <?php if (isset($message)): ?>
                                        <script>
                                        $(document).ready(function() {
                                            Swal.fire({
                                                title: '<?php echo $message['type'] == 'success' ? 'Berhasil!' : 'Perhatian!'; ?>',
                                                text: '<?php echo addslashes($message['text']); ?>',
                                                icon: '<?php echo $message['type'] == 'success' ? 'success' : ($message['type'] == 'danger' ? 'error' : 'warning'); ?>',
                                                confirmButtonText: 'OK',
                                                timer: <?php echo $message['type'] == 'success' ? '3000' : '5000'; ?>,
                                                timerProgressBar: true
                                            }).then(function() {
                                                // Remove message from URL if exists
                                                if (window.location.search.includes('message=')) {
                                                    window.history.replaceState({}, document.title, window.location.pathname);
                                                }
                                            });
                                        });
                                        </script>
                                    <?php endif; ?>

                                    <div class="table-responsive">
                                        <table class="table table-striped" id="table-1">
                                            <thead>
                                                <tr>
                                                    <th>No</th>
                                                    <th class="text-center">
                                                        <div class="custom-checkbox custom-control">
                                                            <input type="checkbox" data-checkboxes="guru" data-checkbox-role="dad" class="custom-control-input" id="checkbox-all">
                                                            <label for="checkbox-all" class="custom-control-label">&nbsp;</label>
                                                        </div>
                                                    </th>
                                                    <th>Foto</th>
                                                    <th>Nama Guru</th>
                                                    <th>Kode Guru</th>
                                                    <th>NUPTK</th>
                                                    <th>Tempat Tanggal Lahir</th>
                                                    <th>Jenis Kelamin</th>
                                                    <th>Mengajar</th>
                                                    <th>Wali Kelas</th>
                                                    <th>Password</th>
                                                    <th>Aksi</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $no = 1;
                                                // Create lookup array for classes (ID => name)
                                                $class_lookup = [];
                                                foreach ($classes as $kelas) {
                                                    $class_lookup[$kelas['id_kelas']] = $kelas['nama_kelas'];
                                                }
                                                
                                                foreach ($teachers as $teacher): 
                                                    // Decode mengajar JSON and get class names
                                                    $mengajar_classes = [];
                                                    $mengajar_names = [];
                                                    if (!empty($teacher['mengajar'])) {
                                                        $decoded = json_decode($teacher['mengajar'], true);
                                                        if (is_array($decoded)) {
                                                            $mengajar_classes = $decoded;
                                                            // Get class names from IDs
                                                            foreach ($decoded as $kelas_id) {
                                                                // Handle both numeric IDs and string IDs
                                                                $kelas_id = (string)$kelas_id;
                                                                if (isset($class_lookup[(int)$kelas_id])) {
                                                                    $mengajar_names[] = $class_lookup[(int)$kelas_id];
                                                                } elseif (isset($class_lookup[$kelas_id])) {
                                                                    $mengajar_names[] = $class_lookup[$kelas_id];
                                                                } else {
                                                                    // If not found by ID, check if it's a name
                                                                    foreach ($classes as $kelas) {
                                                                        if ($kelas['nama_kelas'] == $kelas_id) {
                                                                            $mengajar_names[] = $kelas['nama_kelas'];
                                                                            break;
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }
                                                    $mengajar_display = !empty($mengajar_names) ? implode(', ', $mengajar_names) : '-';
                                                ?>
                                                <tr>
                                                    <td class="text-center"><?php echo $no++; ?></td>
                                                    <td>
                                                        <div class="custom-checkbox custom-control">
                                                            <input type="checkbox" data-checkboxes="guru" class="custom-control-input" id="checkbox-<?php echo $teacher['id_guru']; ?>" value="<?php echo $teacher['id_guru']; ?>">
                                                            <label for="checkbox-<?php echo $teacher['id_guru']; ?>" class="custom-control-label">&nbsp;</label>
                                                        </div>
                                                    </td>
                                                    <td><?php echo getTeacherAvatarImage($teacher, 30); ?></td>
                                                    <td><?php echo htmlspecialchars($teacher['nama_guru']); ?></td>
                                                    <td><?php echo htmlspecialchars($teacher['kode_guru'] ?? '-'); ?></td>
                                                    <td><?php echo htmlspecialchars($teacher['nuptk']); ?></td>
                                                    <td><?php echo htmlspecialchars($teacher['tempat_lahir']); ?>, <?php echo $teacher['tanggal_lahir'] ? date('d-m-Y', strtotime($teacher['tanggal_lahir'])) : '-'; ?></td>
                                                    <td><?php echo htmlspecialchars($teacher['jenis_kelamin']); ?></td>
                                                    <td><?php echo htmlspecialchars($mengajar_display ?: '-'); ?></td>
                                                    <td><?php echo htmlspecialchars($teacher['kelas_wali'] ?? '-'); ?></td>
                                                    <td><?php echo !empty($teacher['password_plain']) ? htmlspecialchars($teacher['password_plain']) : ($teacher['password'] ? '***' : 'Belum Diatur'); ?></td>
                                                    <td>
                                                        <a href="cetak_qr_guru.php?id=<?php echo $teacher['id_guru']; ?>" target="_blank" class="btn btn-info btn-sm" title="Cetak QR"><i class="fas fa-qrcode"></i></a>
                                                        <a href="#" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#editModal<?php echo $teacher['id_guru']; ?>"><i class="fas fa-edit"></i></a>
                                                        <a href="#" class="btn btn-danger btn-sm delete-btn" data-id="<?php echo $teacher['id_guru']; ?>" data-name="<?php echo htmlspecialchars($teacher['nama_guru']); ?>" data-action="delete_guru"><i class="fas fa-trash"></i></a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

<?php
// Add JavaScript for delete confirmation
if (!isset($js_page)) {
    $js_page = [];
}
ob_start(); ?>

$(document).ready(function() {
    $(document).on('click', '.delete-btn', function(e) {
        e.preventDefault();
        
        var id = $(this).data('id');
        var name = $(this).data('name');
        var action = $(this).data('action');
        
        Swal.fire({
            title: 'Konfirmasi Hapus',
            text: 'Apakah Anda yakin ingin menghapus data guru ' + name + '?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                // Create a temporary form and submit it
                var form = $('<form method=\"POST\" action=\"\"><input type=\"hidden\" name=\"id_guru\" value=\"' + id + '\"><input type=\"hidden\" name=\"delete_guru\" value=\"1\"></form>');
                $('body').append(form);
                form.submit();
            }
        });
    });
});

<?php
    $js_content = ob_get_contents();
    ob_end_clean();
    $js_page[] = $js_content;

    // Add the import function separately
    $js_page[] = "<script>
function setImportType(type) {
    \$('#importModal input[name=\"import_type\"]').val(type);
    let templateUrl = '../download_template.php?type=' + type;
    if (type === 'siswa' && \$('#filter_kelas').length > 0) {
        let selectedClassId = \$('#filter_kelas').val();
        if (selectedClassId) {
            templateUrl += '&class_id=' + selectedClassId;
        }
    }
    \$('#importModal .btn-info').attr('href', templateUrl);
}
</script>";

                // Include the import modal
            include '../templates/import_modal.php';
            
            // Add modal for adding teacher
            // Fetch all classes for dropdown
            $classes_stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
            $kelas_list = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo '<!-- Add Modal -->
            <div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-labelledby="addModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="addModalLabel">Tambah Data Guru</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <form method="POST" action="" enctype="multipart/form-data">
                            <div class="modal-body">
                                <input type="hidden" name="add_guru" value="1">
                                <div class="form-group">
                                    <label>Foto</label>
                                    <input type="file" class="form-control" name="foto" accept="image/jpeg,image/jpg,image/png,image/gif">
                                    <small class="form-text text-muted">Format: JPG, JPEG, PNG, atau GIF (maksimal 2MB)</small>
                                </div>
                                <div class="form-group">
                                    <label>Nama Guru</label>
                                    <input type="text" class="form-control" name="nama_guru" required>
                                </div>
                                <div class="form-group">
                                    <label>Kode Guru</label>
                                    <input type="text" class="form-control" name="kode_guru" required>
                                </div>
                                <div class="form-group">
                                    <label>NUPTK</label>
                                    <input type="text" class="form-control" name="nuptk" required>
                                </div>
                                <div class="form-group">
                                    <label>Tempat Lahir</label>
                                    <input type="text" class="form-control" name="tempat_lahir">
                                </div>
                                <div class="form-group">
                                    <label>Tanggal Lahir</label>
                                    <input type="date" class="form-control" name="tanggal_lahir">
                                </div>
                                <div class="form-group">
                                    <label>Jenis Kelamin</label>
                                    <select class="form-control" name="jenis_kelamin" required>
                                        <option value="">Pilih Jenis Kelamin</option>
                                        <option value="Laki-laki">Laki-laki</option>
                                        <option value="Perempuan">Perempuan</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Mengajar Kelas</label>
                                    <select class="form-control select2" name="mengajar[]" multiple="multiple" data-placeholder="Pilih kelas yang diajarkan" style="width: 100%;">
                                    '; foreach ($kelas_list as $kelas) { echo '<option value="' . htmlspecialchars($kelas['id_kelas']) . '">' . htmlspecialchars($kelas['nama_kelas']) . '</option>'; } echo '
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Password</label>
                                    <input type="password" class="form-control" name="password" placeholder="Kosongkan jika tidak ingin diubah">
                                    <small class="form-text text-muted">Kosongkan jika tidak ingin mengatur password</small>
                                </div>
                            </div>
                            <div class="modal-footer bg-whitesmoke br">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
                                <button type="submit" class="btn btn-primary">Simpan</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>';
            
            // Add edit modals for each teacher
            // Fetch all classes for dropdown
            $classes_stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
            $kelas_list = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decode mengajar JSON for each teacher
            foreach ($teachers as $teacher) {
                // Decode mengajar JSON - handle both IDs and names
                $mengajar_classes = [];
                if (!empty($teacher['mengajar'])) {
                    $decoded = json_decode($teacher['mengajar'], true);
                    if (is_array($decoded)) {
                        // Convert class names to IDs if needed
                        foreach ($decoded as $item) {
                            // Check if it's already an ID (numeric) or a name
                            if (is_numeric($item)) {
                                $mengajar_classes[] = $item;
                            } else {
                                // Find ID by name
                                foreach ($kelas_list as $kelas) {
                                    if ($kelas['nama_kelas'] == $item) {
                                        $mengajar_classes[] = $kelas['id_kelas'];
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
                
                echo '<!-- Edit Modal -->
                <div class="modal fade edit-modal" id="editModal' . $teacher['id_guru'] . '" tabindex="-1" role="dialog" aria-labelledby="editModalLabel' . $teacher['id_guru'] . '" aria-hidden="true">
                    <div class="modal-dialog" role="document">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="editModalLabel' . $teacher['id_guru'] . '">Edit Data Guru</h5>
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                            <form method="POST" action="" enctype="multipart/form-data">
                                <div class="modal-body">
                                    <input type="hidden" name="id_guru" value="' . $teacher['id_guru'] . '">
                                    <input type="hidden" name="update_guru" value="1">
                                    <div class="form-group">
                                        <label>Foto</label>';
                                        if (!empty($teacher['foto']) && file_exists('../uploads/' . $teacher['foto'])) {
                                            echo '<div class="mb-2">
                                                <img src="../uploads/' . htmlspecialchars($teacher['foto']) . '" alt="Foto Guru" style="max-width: 100px; max-height: 100px; border-radius: 5px;">
                                            </div>';
                                        }
                                        echo '<input type="file" class="form-control" name="foto" accept="image/jpeg,image/jpg,image/png,image/gif">
                                        <small class="form-text text-muted">Format: JPG, JPEG, PNG, atau GIF (maksimal 2MB). Kosongkan jika tidak ingin mengubah foto.</small>
                                    </div>
                                    <div class="form-group">
                                        <label>Nama Guru</label>
                                        <input type="text" class="form-control" name="nama_guru" value="' . htmlspecialchars($teacher['nama_guru']) . '" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Kode Guru</label>
                                        <input type="text" class="form-control" name="kode_guru" value="' . htmlspecialchars($teacher['kode_guru'] ?? '') . '" required>
                                    </div>
                                    <div class="form-group">
                                        <label>NUPTK</label>
                                        <input type="text" class="form-control" name="nuptk" value="' . htmlspecialchars($teacher['nuptk']) . '" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Tempat Lahir</label>
                                        <input type="text" class="form-control" name="tempat_lahir" value="' . htmlspecialchars($teacher['tempat_lahir']) . '">
                                    </div>
                                    <div class="form-group">
                                        <label>Tanggal Lahir</label>
                                        <input type="date" class="form-control" name="tanggal_lahir" value="' . $teacher['tanggal_lahir'] . '">
                                    </div>
                                    <div class="form-group">
                                        <label>Jenis Kelamin</label>
                                        <select class="form-control" name="jenis_kelamin" required>
                                            <option value="">Pilih Jenis Kelamin</option>
                                            <option value="Laki-laki" ' . ($teacher['jenis_kelamin'] == 'Laki-laki' ? 'selected' : '') . '>Laki-laki</option>
                                            <option value="Perempuan" ' . ($teacher['jenis_kelamin'] == 'Perempuan' ? 'selected' : '') . '>Perempuan</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Mengajar Kelas</label>
                                        <select class="form-control select2" name="mengajar[]" multiple="multiple" data-placeholder="Pilih kelas yang diajarkan" style="width: 100%;">
                                        '; 
                                        // Normalize mengajar_classes to integers for comparison
                                        $mengajar_classes_int = array_map('intval', $mengajar_classes);
                                        foreach ($kelas_list as $kelas) { 
                                            $kelas_id_int = (int)$kelas['id_kelas'];
                                            $is_selected = in_array($kelas_id_int, $mengajar_classes_int) || in_array($kelas['id_kelas'], $mengajar_classes) || in_array((string)$kelas['id_kelas'], $mengajar_classes);
                                            echo '<option value="' . htmlspecialchars($kelas['id_kelas']) . '" ' . ($is_selected ? 'selected' : '') . '>' . htmlspecialchars($kelas['nama_kelas']) . '</option>'; 
                                        } 
                                        echo '
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Password</label>
                                        <input type="password" class="form-control" name="password" placeholder="Kosongkan jika tidak ingin diubah">
                                        <small class="form-text text-muted">Kosongkan jika tidak ingin mengubah password</small>
                                    </div>
                                </div>
                                <div class="modal-footer bg-whitesmoke br">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
                                    <button type="submit" class="btn btn-primary">Simpan</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>';
            }
            
            // Add JavaScript for initializing Select2 elements
            echo '<script>
            $(document).ready(function() {
                // Initialize Select2 when modals are shown
                $(\'#addModal\').on(\'shown.bs.modal\', function() {
                    var $select = $(this).find(\'select.select2\');
                    if ($select.length > 0 && !$select.hasClass(\'select2-hidden-accessible\')) {
                        $select.select2({
                            placeholder: "Pilih kelas yang diajarkan",
                            allowClear: true,
                            width: "100%",
                            dropdownParent: $(this)
                        });
                    }
                });
                
                // Initialize Select2 for edit modals when shown
                $(\'.edit-modal\').on(\'shown.bs.modal\', function() {
                    var $select = $(this).find(\'select.select2\');
                    if ($select.length > 0 && !$select.hasClass(\'select2-hidden-accessible\')) {
                        $select.select2({
                            placeholder: "Pilih kelas yang diajarkan",
                            allowClear: true,
                            width: "100%",
                            dropdownParent: $(this)
                        });
                    }
                });
                
                // Destroy Select2 when modals are hidden to prevent conflicts
                $(\'#addModal, .edit-modal\').on(\'hidden.bs.modal\', function() {
                    var $select = $(this).find(\'select.select2\');
                    if ($select.length > 0 && $select.hasClass(\'select2-hidden-accessible\')) {
                        $select.select2(\'destroy\');
                    }
                });
            });
            </script>';
            
            // Add Bulk Edit Modal
            echo '<!-- Bulk Edit Modal -->
            <div class="modal fade" id="bulkEditModal" tabindex="-1" role="dialog" aria-labelledby="bulkEditModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="bulkEditModalLabel">Edit Data Terpilih</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <form id="bulkEditForm" method="POST" action="">
                            <div class="modal-body">
                                <input type="hidden" name="bulk_edit_guru" value="1">
                                <p class="mb-3">Edit data untuk <strong id="bulkEditCount">0</strong> data guru yang dipilih:</p>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm">
                                        <thead>
                                            <tr>
                                                <th width="5%">No</th>
                                                <th width="20%">Nama Guru</th>
                                                <th width="20%">Nama Baru</th>
                                                <th width="15%">Kode Guru Baru</th>
                                                <th width="20%">NUPTK Baru</th>
                                                <th width="20%">Jenis Kelamin</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <!-- Data akan diisi oleh JavaScript -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="modal-footer bg-whitesmoke br">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>';
            
            // Add export functions
            $js_page[] = "
            function exportGuruToExcel() {
                // Create form and submit to excel_export.php
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = '../config/excel_export.php';
                
                // Get table HTML
                var table = document.getElementById('table-1');
                if (!table) {
                    Swal.fire({
                        title: 'Error!',
                        text: 'Tabel tidak ditemukan',
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                    return;
                }
                
                var tableClone = table.cloneNode(true);
                
                // Remove checkbox and action columns
                var rows = tableClone.querySelectorAll('tr');
                rows.forEach(function(row) {
                    var cells = row.querySelectorAll('td, th');
                    if (cells.length > 0) {
                        // Remove checkbox column (find by checkbox input)
                        var checkboxCell = null;
                        for (var i = 0; i < cells.length; i++) {
                            if (cells[i].querySelector && cells[i].querySelector('input[type=\"checkbox\"]')) {
                                checkboxCell = cells[i];
                                break;
                            }
                        }
                        if (checkboxCell) {
                            checkboxCell.remove();
                        }
                        
                        // Remove action column (find column with edit/delete buttons)
                        var actionCell = null;
                        for (var j = 0; j < cells.length; j++) {
                            var cell = cells[j];
                            if (cell && (cell.querySelector('a.btn-primary') || cell.querySelector('a.btn-danger') || cell.querySelector('a.delete-btn') || cell.querySelector('a[data-toggle=\"modal\"]'))) {
                                actionCell = cell;
                                break;
                            }
                        }
                        if (actionCell) {
                            actionCell.remove();
                        }
                    }
                });
                
                // Also remove action header from thead
                var theadRows = tableClone.querySelectorAll('thead tr');
                theadRows.forEach(function(theadRow) {
                    var thCells = theadRow.querySelectorAll('th');
                    thCells.forEach(function(th) {
                        if (th.textContent.trim() === 'Aksi' || th.textContent.trim() === 'Action') {
                            th.remove();
                        }
                    });
                });
                
                // Convert images to text
                var images = tableClone.querySelectorAll('img');
                images.forEach(function(img) {
                    var span = document.createElement('span');
                    span.textContent = img.alt || '[Foto]';
                    img.parentNode.replaceChild(span, img);
                });
                
                var tableInput = document.createElement('input');
                tableInput.type = 'hidden';
                tableInput.name = 'table_data';
                tableInput.value = tableClone.outerHTML;
                
                form.appendChild(tableInput);
                document.body.appendChild(form);
                form.submit();
                document.body.removeChild(form);
            }
            
            function exportGuruToPDF() {
                // Create form and submit to pdf_export.php
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = '../config/pdf_export.php';
                form.target = '_blank'; // Open in new tab
                
                // Get table HTML
                var table = document.getElementById('table-1');
                if (!table) {
                    Swal.fire({
                        title: 'Error!',
                        text: 'Tabel tidak ditemukan',
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                    return;
                }
                
                var tableClone = table.cloneNode(true);
                
                // Remove checkbox and action columns
                var rows = tableClone.querySelectorAll('tr');
                rows.forEach(function(row) {
                    var cells = row.querySelectorAll('td, th');
                    if (cells.length > 0) {
                        // Remove checkbox column (find by checkbox input)
                        var checkboxCell = null;
                        for (var i = 0; i < cells.length; i++) {
                            if (cells[i].querySelector && cells[i].querySelector('input[type=\"checkbox\"]')) {
                                checkboxCell = cells[i];
                                break;
                            }
                        }
                        if (checkboxCell) {
                            checkboxCell.remove();
                        }
                        
                        // Remove action column (find column with edit/delete buttons)
                        var actionCell = null;
                        for (var j = 0; j < cells.length; j++) {
                            var cell = cells[j];
                            if (cell && (cell.querySelector('a.btn-primary') || cell.querySelector('a.btn-danger') || cell.querySelector('a.delete-btn') || cell.querySelector('a[data-toggle=\"modal\"]'))) {
                                actionCell = cell;
                                break;
                            }
                        }
                        if (actionCell) {
                            actionCell.remove();
                        }
                    }
                });
                
                // Also remove action header from thead
                var theadRows = tableClone.querySelectorAll('thead tr');
                theadRows.forEach(function(theadRow) {
                    var thCells = theadRow.querySelectorAll('th');
                    thCells.forEach(function(th) {
                        if (th.textContent.trim() === 'Aksi' || th.textContent.trim() === 'Action') {
                            th.remove();
                        }
                    });
                });
                
                // Convert images to text
                var images = tableClone.querySelectorAll('img');
                images.forEach(function(img) {
                    var span = document.createElement('span');
                    span.textContent = img.alt || '[Foto]';
                    img.parentNode.replaceChild(span, img);
                });
                
                var tableInput = document.createElement('input');
                tableInput.type = 'hidden';
                tableInput.name = 'table_data';
                tableInput.value = tableClone.outerHTML;
                
                form.appendChild(tableInput);
                document.body.appendChild(form);
                form.submit();
                document.body.removeChild(form);
            }
            
            $(document).ready(function() {
                // Handle bulk edit form submission
                $('#bulkEditForm').on('submit', function(e) {
                    e.preventDefault();
                    
                    var formData = $(this).serialize();
                    
                    $.ajax({
                        url: 'data_guru.php',
                        type: 'POST',
                        data: formData,
                        dataType: 'json',
                        success: function(response) {
                            if (response && response.success) {
                                Swal.fire({
                                    title: 'Berhasil!',
                                    text: response.message,
                                    icon: 'success',
                                    timer: 1500,
                                    showConfirmButton: false
                                }).then(function() {
                                    location.reload();
                                });
                            } else {
                                Swal.fire('Gagal!', response ? response.message : 'Terjadi kesalahan', 'error');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Error:', error, xhr.responseText);
                            Swal.fire('Error!', 'Terjadi kesalahan saat memperbarui data: ' + error, 'error');
                        }
                    });
                });
                
                // Update count when modal is shown
                $('#bulkEditModal').on('show.bs.modal', function() {
                    var count = $('#bulkEditModal tbody tr').length;
                    $('#bulkEditCount').text(count);
                });
                
                // Handle edit form submission with AJAX (using event delegation for dynamically created forms)
                $(document).on('submit', '.edit-modal form', function(e) {
                    e.preventDefault();
                    var form = $(this);
                    var formData = new FormData(this);
                    formData.append('ajax', '1');
                    
                    // Show loading
                    var submitBtn = form.find('button[type=submit]');
                    var originalText = submitBtn.html();
                    submitBtn.prop('disabled', true).html('<i class=\"fas fa-spinner fa-spin\"></i> Menyimpan...');
                    
                    $.ajax({
                        url: 'data_guru.php',
                        type: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: function(response) {
                            if (response && response.success) {
                                Swal.fire({
                                    title: 'Berhasil!',
                                    text: response.message,
                                    icon: 'success',
                                    timer: 2000,
                                    timerProgressBar: true,
                                    showConfirmButton: false
                                }).then(function() {
                                    // Close modal
                                    form.closest('.modal').modal('hide');
                                    // Reload page to refresh data
                                    location.reload();
                                });
                            } else {
                                Swal.fire({
                                    title: 'Gagal!',
                                    text: response ? response.message : 'Terjadi kesalahan',
                                    icon: 'error',
                                    confirmButtonText: 'OK'
                                });
                                submitBtn.prop('disabled', false).html(originalText);
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Error:', error, xhr.responseText);
                            var errorMessage = 'Terjadi kesalahan saat memperbarui data';
                            try {
                                var response = JSON.parse(xhr.responseText);
                                if (response && response.message) {
                                    errorMessage = response.message;
                                }
                            } catch(e) {
                                errorMessage += ': ' + error;
                            }
                            Swal.fire({
                                title: 'Error!',
                                text: errorMessage,
                                icon: 'error',
                                confirmButtonText: 'OK'
                            });
                            submitBtn.prop('disabled', false).html(originalText);
                        }
                    });
                });
            });";
            
            include '../templates/footer.php';
            ?>