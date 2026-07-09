<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and is admin
if (!isAuthorized(['admin'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. (Level: ' . getUserLevel() . ')']);
    exit;
}

$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !appVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'set_self_update') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    $enabled = isset($_POST['enabled']) && (string)$_POST['enabled'] === '1';
    if (!appSaveRuntimeSettings(['self_update_enabled' => $enabled])) {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan pengaturan update. Pastikan folder config bisa ditulis oleh PHP.']);
        exit;
    }

    logActivity($pdo, (string)($_SESSION['username'] ?? 'admin'), 'Pengaturan Update Sistem', $enabled ? 'Update sistem diaktifkan' : 'Update sistem dinonaktifkan');
    echo json_encode([
        'success' => true,
        'enabled' => $enabled,
        'message' => $enabled ? 'Update sistem sudah diaktifkan. Jalankan hanya saat maintenance.' : 'Update sistem sudah dinonaktifkan.'
    ]);
    exit;
}

if (!APP_SELF_UPDATE_ENABLED) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Update aplikasi dari web dinonaktifkan demi keamanan. Aktifkan Update Sistem dari menu akun admin hanya saat maintenance.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_from_github') {
    // Bersihkan semua output sebelumnya untuk memastikan hanya JSON yang dikirim
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    // Set batas waktu lebih lama (5 menit)
    set_time_limit(300); 

    // Check if git is installed
    exec('git --version', $output, $return_var);
    $project_root = dirname(__DIR__);

    if ($return_var === 0) {
        // --- METHOD 1: GIT PULL ---
        chdir($project_root);
        $commands = [
            'git fetch --all 2>&1',
            'git reset --hard origin/main 2>&1',
            'git pull origin main 2>&1'
        ];

        $all_success = true;
        foreach ($commands as $cmd) {
            exec($cmd, $cmd_output, $cmd_return);
            if ($cmd_return !== 0) $all_success = false;
            unset($cmd_output);
        }

        if ($all_success) {
            // Write new version timestamp after successful git update
            @file_put_contents($project_root . '/version.txt', date('YmdHis'));
            appSaveRuntimeSettings(['self_update_enabled' => false]);
            logActivity($pdo, $_SESSION['username'], 'Update Aplikasi', 'Update via Git berhasil');
            echo json_encode(['success' => true, 'message' => 'Aplikasi berhasil diperbarui. Update sistem otomatis dinonaktifkan kembali.']);
            exit;
        }
    }

    // --- METHOD 2: ZIP DOWNLOAD (Fallback) ---
    if (!class_exists('ZipArchive')) {
        echo json_encode(['success' => false, 'message' => 'Git tidak tersedia dan PHP ZipArchive tidak aktif.']);
        exit;
    }

    $zip_url = 'https://github.com/dewecorp/absensi_siswa/archive/refs/heads/main.zip';
    $temp_zip = $project_root . '/update_temp.zip';
    $temp_extract_path = $project_root . '/update_temp_folder';

    // Download ZIP menggunakan cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $zip_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'PHP-Update-Script');
    $zip_content = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($zip_content === false) {
        echo json_encode(['success' => false, 'message' => 'Gagal mengunduh update: ' . $curl_error]);
        exit;
    }

    if (file_put_contents($temp_zip, $zip_content) === false) {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan file temporary di server.']);
        exit;
    }

    // Extract ZIP
    $zip = new ZipArchive;
    if ($zip->open($temp_zip) === TRUE) {
        if (!is_dir($temp_extract_path)) mkdir($temp_extract_path, 0755, true);
        $zip->extractTo($temp_extract_path);
        $zip->close();
        unlink($temp_zip);

        $extracted_folders = glob($temp_extract_path . '/*', GLOB_ONLYDIR);
        if (!empty($extracted_folders)) {
            $source = $extracted_folders[0]; 
            
            $copy_recursive = function($src, $dst) use (&$copy_recursive) {
                if (!is_dir($src)) return;
                $dir = opendir($src);
                if (!is_dir($dst)) @mkdir($dst, 0755, true);
                while (false !== ($file = readdir($dir))) {
                    if (($file != '.') && ($file != '..')) {
                        $src_file = $src . '/' . $file;
                        $dst_file = $dst . '/' . $file;
                        
                        // PROTECT DATABASE CONFIG: Jangan timpa file config/database.php
                        if (strpos($dst_file, 'config/database.php') !== false) {
                            continue;
                        }

                        if (is_dir($src_file)) {
                            $copy_recursive($src_file, $dst_file);
                        } else {
                            @copy($src_file, $dst_file);
                        }
                    }
                }
                closedir($dir);
            };

            $copy_recursive($source, $project_root);
            
            // Cleanup
            $delete_recursive = function($dir) use (&$delete_recursive) {
                if (!is_dir($dir)) return;
                $files = array_diff(scandir($dir), ['.', '..']);
                foreach ($files as $file) {
                    (is_dir("$dir/$file")) ? $delete_recursive("$dir/$file") : @unlink("$dir/$file");
                }
                return @rmdir($dir);
            };
            $delete_recursive($temp_extract_path);

            logActivity($pdo, $_SESSION['username'], 'Update Aplikasi', 'Update via ZIP berhasil');
            // Write new version timestamp after successful ZIP update
            @file_put_contents($project_root . '/version.txt', date('YmdHis'));
            appSaveRuntimeSettings(['self_update_enabled' => false]);
            echo json_encode(['success' => true, 'message' => 'Aplikasi berhasil diperbarui. Update sistem otomatis dinonaktifkan kembali.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Struktur ZIP tidak sesuai.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal mengekstrak file update.']);
    }
    exit;
}
