<?php
require_once '../config/database.php';
require_once '../config/functions.php';

function createSourceBackupBeforeUpdate(string $project_root): array {
    if (!class_exists('ZipArchive')) {
        return ['success' => false, 'message' => 'PHP ZipArchive tidak aktif, backup source sebelum update tidak bisa dibuat.'];
    }

    $backup_dir = $project_root . '/backups';
    $backup_file = $backup_dir . '/source_backup.zip';
    if (!is_dir($backup_dir) && !@mkdir($backup_dir, 0755, true)) {
        return ['success' => false, 'message' => 'Folder backups tidak bisa dibuat/ditulis.'];
    }
    if (is_file($backup_file) && !@unlink($backup_file)) {
        return ['success' => false, 'message' => 'Backup lama tidak bisa diganti.'];
    }

    $zip = new ZipArchive();
    if ($zip->open($backup_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['success' => false, 'message' => 'File backup source tidak bisa dibuat.'];
    }

    $excluded_dirs = ['.git', 'backups', 'node_modules', 'vendor', 'sessions', 'cache', 'tmp', 'temp', 'update_temp_folder'];
    $excluded_files = ['update_temp.zip'];
    $root_len = strlen(rtrim($project_root, '/\\')) + 1;
    $file_count = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($project_root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        $path = $file->getPathname();
        $relative = str_replace('\\', '/', substr($path, $root_len));
        $parts = explode('/', $relative);

        if (array_intersect($parts, $excluded_dirs) || in_array(basename($relative), $excluded_files, true)) {
            continue;
        }
        if ($file->isFile()) {
            $zip->addFile($path, $relative);
            $file_count++;
        }
    }

    $zip->close();
    if ($file_count === 0 || !is_file($backup_file) || filesize($backup_file) <= 0) {
        @unlink($backup_file);
        return ['success' => false, 'message' => 'Backup source kosong, update dibatalkan.'];
    }

    return ['success' => true, 'file' => $backup_file, 'count' => $file_count];
}

function failUpdateAndLock(string $message): void {
    appSaveRuntimeSettings(['self_update_enabled' => false]);
    echo json_encode(['success' => false, 'message' => $message . ' Update sistem otomatis dinonaktifkan kembali.']);
    exit;
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'check_update') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    $local_ver = '';
    $ver_file = dirname(__DIR__) . '/version.txt';
    if (is_file($ver_file)) {
        $local_ver = trim(@file_get_contents($ver_file));
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.github.com/repos/dewecorp/absensi_siswa/commits/main',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'SIMadrasah-Update-Checker/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/vnd.github.v3+json'],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200 || $resp === false) {
        echo json_encode(['success' => true, 'update_available' => false, 'message' => 'Tidak bisa memeriksa pembaruan.']);
        exit;
    }

    $data = json_decode($resp, true);
    $remote_date_str = $data['commit']['committer']['date'] ?? '';
    $remote_msg = $data['commit']['message'] ?? '';

    $need_update = false;
    if ($remote_date_str) {
        $remote_ts = strtotime($remote_date_str);
        $local_ts = $local_ver ? strtotime($local_ver) : 0;
        if ($local_ver && preg_match('/^\d{14}$/', $local_ver)) {
            $local_ts = strtotime(
                substr($local_ver, 0, 4) . '-' . substr($local_ver, 4, 2) . '-' . substr($local_ver, 6, 2) .
                ' ' . substr($local_ver, 8, 2) . ':' . substr($local_ver, 10, 2) . ':' . substr($local_ver, 12, 2)
            );
        }
        $need_update = $remote_ts > $local_ts;
    }

    echo json_encode([
        'success' => true,
        'update_available' => $need_update,
        'remote_date' => $remote_date_str
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

    $backup = createSourceBackupBeforeUpdate($project_root);
    if (!$backup['success']) {
        appSaveRuntimeSettings(['self_update_enabled' => false]);
        echo json_encode(['success' => false, 'message' => $backup['message'] . ' Update sistem otomatis dinonaktifkan kembali.']);
        exit;
    }

    if ($return_var === 0) {
        // --- METHOD 1: GIT PULL aman tanpa reset paksa ---
        chdir($project_root);
        $commands = [
            'git fetch origin main 2>&1',
            'git pull --ff-only origin main 2>&1'
        ];

        $all_success = true;
        $last_output = [];
        foreach ($commands as $cmd) {
            exec($cmd, $cmd_output, $cmd_return);
            $last_output = $cmd_output;
            if ($cmd_return !== 0) {
                $all_success = false;
                break;
            }
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

        error_log('Git update gagal: ' . implode("\n", array_slice($last_output, -20)));
    }

    // --- METHOD 2: ZIP DOWNLOAD (Fallback) ---
    if (!class_exists('ZipArchive')) {
        failUpdateAndLock('Git tidak tersedia dan PHP ZipArchive tidak aktif.');
    }
    if (!function_exists('curl_init')) {
        failUpdateAndLock('PHP cURL tidak aktif, fallback ZIP tidak bisa mengunduh update.');
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
        failUpdateAndLock('Gagal mengunduh update: ' . $curl_error);
    }

    if (file_put_contents($temp_zip, $zip_content) === false) {
        failUpdateAndLock('Gagal menyimpan file temporary di server.');
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
                        
                        // Lindungi konfigurasi lokal hosting.
                        if (strpos($dst_file, 'config/database.php') !== false || strpos($dst_file, 'config/runtime_settings.php') !== false) {
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
            failUpdateAndLock('Struktur ZIP tidak sesuai.');
        }
    } else {
        failUpdateAndLock('Gagal mengekstrak file update.');
    }
    exit;
}
