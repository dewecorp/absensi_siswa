<?php
// Registry endpoint integrasi SIMAD (keluar & masuk).
// Base URL dihitung otomatis dari request -> tahan ganti domain/hosting tanpa bongkar backend.

if (!function_exists('endpoint_registry_schema')) {
    function endpoint_registry_schema(PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_endpoint_keluar (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nama VARCHAR(100) NOT NULL,
                path VARCHAR(255) NOT NULL,
                metode VARCHAR(10) NOT NULL DEFAULT 'GET',
                deskripsi TEXT NULL,
                aktif TINYINT(1) NOT NULL DEFAULT 1,
                updated_at DATETIME NULL,
                UNIQUE KEY uniq_path (path)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_endpoint_masuk (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nama_aplikasi VARCHAR(100) NOT NULL,
                base_url VARCHAR(255) NOT NULL DEFAULT '',
                api_key VARCHAR(255) NULL,
                deskripsi TEXT NULL,
                aktif TINYINT(1) NOT NULL DEFAULT 1,
                last_test_at DATETIME NULL,
                last_test_status VARCHAR(20) NULL,
                last_test_note VARCHAR(255) NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY uniq_nama (nama_aplikasi)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_pengaturan_api (
                id INT AUTO_INCREMENT PRIMARY KEY,
                api_key VARCHAR(255) NOT NULL DEFAULT '',
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

if (!function_exists('endpoint_seeds')) {
    function endpoint_seeds(): array {
        return [
            ['nama' => 'Data Siswa', 'path' => 'api/v1/students.php', 'metode' => 'GET', 'deskripsi' => 'Sinkron data siswa ke aplikasi eksternal (mis. Rapor).'],
            ['nama' => 'Sinkron Siswa (Sibayar)', 'path' => 'api/v1/sync_siswa.php', 'metode' => 'GET', 'deskripsi' => 'Data siswa + tanggal_masuk untuk penentuan tagihan Sibayar.'],
            ['nama' => 'Data Guru', 'path' => 'api/v1/teachers.php', 'metode' => 'GET', 'deskripsi' => 'Sinkron data guru ke aplikasi eksternal.'],
            ['nama' => 'Data Kelas', 'path' => 'api/v1/classes.php', 'metode' => 'GET', 'deskripsi' => 'Sinkron data kelas ke aplikasi eksternal.'],
            ['nama' => 'Data Ekstrakurikuler', 'path' => 'api/v1/extracurriculars.php', 'metode' => 'GET', 'deskripsi' => 'Sinkron data ekstrakurikuler + pembina + anggota aktif.'],
        ];
    }
}

if (!function_exists('endpoint_inbound_seeds')) {
    function endpoint_inbound_seeds(): array {
        return [
            ['nama_aplikasi' => 'sigaji', 'deskripsi' => 'Ambil endpoint/data dari aplikasi Sigaji.'],
            ['nama_aplikasi' => 'sibayar', 'deskripsi' => 'Ambil endpoint/data dari aplikasi Sibayar.'],
            ['nama_aplikasi' => 'etab', 'deskripsi' => 'Ambil endpoint/data dari aplikasi Etab.'],
        ];
    }
}

if (!function_exists('endpoint_seed_all')) {
    function endpoint_seed_all(PDO $pdo): void {
        foreach (endpoint_seeds() as $s) {
            try {
                $st = $pdo->prepare("INSERT IGNORE INTO tb_endpoint_keluar (nama, path, metode, deskripsi, aktif, updated_at) VALUES (?, ?, ?, ?, 1, NOW())");
                $st->execute([$s['nama'], $s['path'], $s['metode'], $s['deskripsi']]);
            } catch (Exception $e) { /* ignore */ }
        }
        foreach (endpoint_inbound_seeds() as $s) {
            try {
                $st = $pdo->prepare("INSERT IGNORE INTO tb_endpoint_masuk (nama_aplikasi, base_url, deskripsi, aktif, updated_at) VALUES (?, '', ?, 1, NOW())");
                $st->execute([$s['nama_aplikasi'], $s['deskripsi']]);
            } catch (Exception $e) { /* ignore */ }
        }
        try {
            $has = $pdo->query("SELECT COUNT(*) FROM tb_pengaturan_api")->fetchColumn();
            if ((int)$has === 0) {
                $pdo->exec("INSERT INTO tb_pengaturan_api (api_key, updated_at) VALUES ('SIS_CENTRAL_HUB_SECRET_2026', NOW())");
            }
        } catch (Exception $e) { /* ignore */ }
    }
}

if (!function_exists('endpoint_base_url')) {
    // Base URL SIMAD otomatis dari request aktif (tahan ganti domain).
    function endpoint_base_url(): string {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == '443');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/admin/pengaturan_endpoint.php'));
        // Ambil direktori skrip (mis. /simad/admin/x.php -> /simad/admin), lalu naik 1 level ke root SIMAD.
        $base = rtrim(str_replace('\\', '/', dirname(dirname($script))), '/');
        if ($base === '/' || $base === '\\' || $base === '.') $base = '';
        return $scheme . '://' . $host . $base;
    }
}

if (!function_exists('endpoint_api_key')) {
    function endpoint_api_key(PDO $pdo): string {
        try {
            $row = $pdo->query("SELECT api_key FROM tb_pengaturan_api ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            return (string)($row['api_key'] ?? '');
        } catch (Exception $e) {
            return '';
        }
    }
}

if (!function_exists('endpoint_full_url')) {
    // URL lengkap siap salin: {base}/{path}?api_key={key}
    function endpoint_full_url(string $path, string $api_key = ''): string {
        $url = rtrim(endpoint_base_url(), '/') . '/' . ltrim($path, '/');
        if ($api_key !== '') {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'api_key=' . urlencode($api_key);
        }
        return $url;
    }
}

if (!function_exists('endpoint_fetch_url')) {
    // Fetch dengan redirect manual agar header (X-API-KEY) tetap terkirim,
    // sekaligus memperbaiki redirect rusak berisi path Windows (D:/...) dari htaccess sigaji.
    // Kembalikan [body|false, http_code(int), err(string), final_url(string)]
    function endpoint_fetch_url(string $url, array $headers = []) {
        $tries = 0;
        $code = 0;
        $err = '';
        while ($tries < 4) {
            $tries++;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_NOBODY => false,
                CURLOPT_HEADER => true,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $resp = curl_exec($ch);
            $err = (string)curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            if ($resp === false) return [false, $code, $err !== '' ? $err : 'connect failed', $url];
            $body = substr($resp, $hsize);
            if (!in_array($code, [301, 302, 303, 307, 308], true)) return [$body, $code, '', $url];
            $hblock = substr($resp, 0, $hsize);
            if (!preg_match('/^location:\s*(.+)$/mi', $hblock, $m)) return [$body, $code, '', $url];
            $loc = trim($m[1]);
            if (preg_match('#[A-Za-z]:/#', $loc)) {
                if (preg_match('#(/api/.*)$#', $loc, $mm)) {
                    $parts = parse_url($url);
                    $loc = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '') . $mm[1];
                } else {
                    return [$body, $code, 'redirect rusak dari server tujuan', $url];
                }
            } elseif (!preg_match('#^https?://#i', $loc)) {
                $parts = parse_url($url);
                $loc = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '') . '/' . ltrim($loc, '/');
            }
            $url = $loc;
        }
        return [false, $code, 'terlalu banyak redirect', $url];
    }
}

if (!function_exists('endpoint_test_url')) {
    // Tes koneksi endpoint masuk: GET base_url (+ api_key bila ada) + timeout 8 detik, catat status.
    // Kembalikan [ok(bool), http_code(int), note(string), ms(int)]
    function endpoint_test_url(string $base_url, string $api_key = ''): array {
        $t0 = microtime(true);
        $url = trim($base_url);
        if ($url === '') {
            return [false, 0, 'Base URL kosong.', 0];
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'http://' . $url;
        }
        // Sertakan api_key seperti pola API SIMAD (query + header) agar tak 401.
        $headers = [];
        if ($api_key !== '') {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'api_key=' . urlencode($api_key);
            $headers[] = 'X-API-KEY: ' . $api_key;
        }
        if (!function_exists('curl_init')) {
            $opts = ['http' => ['timeout' => 8, 'ignore_errors' => true, 'method' => 'GET']];
            if ($headers) $opts['http']['header'] = implode("\r\n", $headers);
            $ctx = stream_context_create($opts);
            $body = @file_get_contents($url, false, $ctx);
            $code = 0;
            foreach ((array)($http_response_header ?? []) as $h) {
                if (preg_match('#HTTP/\S+\s+(\d+)#', (string)$h, $m)) { $code = (int)$m[1]; break; }
            }
            $ms = (int)round((microtime(true) - $t0) * 1000);
            if ($body === false) {
                return [false, $code, 'Gagal konek ke ' . $url, $ms];
            }
            $ok = $code >= 200 && $code < 400;
            $snippet = trim(substr((string)$body, 0, 120));
            return [$ok, $code, $ok ? ("HTTP $code OK {$ms}ms") : ("HTTP $code " . ($snippet !== '' ? $snippet : 'tanpa respon')), $ms];
        }
        [$body, $code, $err] = endpoint_fetch_url($url, $headers);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if ($body === false) {
            return [false, $code, 'Gagal konek: ' . $err, $ms];
        }
        $ok = $code >= 200 && $code < 400;
        $snippet = trim(substr((string)$body, 0, 120));
        return [$ok, $code, $ok ? ("HTTP $code OK {$ms}ms") : ("HTTP $code " . ($snippet !== '' ? $snippet : 'tanpa respon')), $ms];
    }
}
