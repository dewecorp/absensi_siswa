<?php
/**
 * Helper data penggajian (Sigaji) untuk level bendahara.
 * Sumber: endpoint masuk "sigaji" di menu Pengaturan Endpoint.
 */

if (!function_exists('bendahara_gaji_cache_path')) {
    function bendahara_gaji_cache_path(): string
    {
        return dirname(__DIR__) . '/cache/sigaji_gaji.json';
    }
}

if (!function_exists('bendahara_fetch_gaji')) {
    /**
     * @return array{status:string,guru:array,period_info:array,message:string,cached:bool,stale:bool}
     */
    function bendahara_fetch_gaji(bool $force = false): array
    {
        $cacheFile = bendahara_gaji_cache_path();
        $ttl = 300; // 5 menit

        if (!$force && is_file($cacheFile) && (time() - (int)@filemtime($cacheFile)) < $ttl) {
            $cached = @json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && ($cached['status'] ?? '') === 'success') {
                return [
                    'status' => 'success',
                    'guru' => is_array($cached['guru'] ?? null) ? $cached['guru'] : [],
                    'period_info' => is_array($cached['period_info'] ?? null) ? $cached['period_info'] : [],
                    'message' => '',
                    'cached' => true,
                    'stale' => false,
                ];
            }
        }

        $message = '';
        $response = false;
        $http_code = 0;
        $curl_error = '';

        try {
            global $pdo;
            $stmt = $pdo->prepare("SELECT base_url, api_key FROM tb_endpoint_masuk WHERE nama_aplikasi = 'sigaji' AND aktif = 1 LIMIT 1");
            $stmt->execute();
            $ep = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $base = trim((string)($ep['base_url'] ?? ''));
            $key = trim((string)($ep['api_key'] ?? ''));

            if ($base === '') {
                $message = 'Endpoint Sigaji belum dikonfigurasi. Isi base URL + API key di menu Integrasi > Pengaturan Endpoint.';
            } else {
                require_once dirname(__DIR__) . '/config/endpoint_registry.php';
                $api_url = rtrim($base, '/');
                if ($key !== '') {
                    $api_url .= (strpos($api_url, '?') === false ? '?' : '&') . 'api_key=' . urlencode($key);
                }
                $headers = ['Accept: application/json', 'Content-Type: application/json'];
                if ($key !== '') $headers[] = 'X-API-KEY: ' . $key;
                [$response, $http_code, $curl_error] = endpoint_fetch_url($api_url, $headers);

                if ($response === false) {
                    $message = 'Gagal terhubung ke server penggajian: ' . $curl_error;
                } elseif ((int)$http_code !== 200) {
                    $message = 'Server penggajian merespon kode ' . (int)$http_code . '.';
                } else {
                    $decoded = json_decode((string)$response, true);
                    if (!is_array($decoded)) {
                        $message = 'Gagal memproses data penggajian: ' . json_last_error_msg();
                    } elseif (($decoded['status'] ?? '') !== 'success') {
                        $message = (string)($decoded['message'] ?? 'Server penggajian mengembalikan status gagal.');
                    } else {
                        @file_put_contents($cacheFile, json_encode($decoded));
                        return [
                            'status' => 'success',
                            'guru' => is_array($decoded['guru'] ?? null) ? $decoded['guru'] : [],
                            'period_info' => is_array($decoded['period_info'] ?? null) ? $decoded['period_info'] : [],
                            'message' => '',
                            'cached' => false,
                            'stale' => false,
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            $message = $e->getMessage();
        }

        // fallback cache lama
        if (is_file($cacheFile)) {
            $cached = @json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && ($cached['status'] ?? '') === 'success') {
                return [
                    'status' => 'success',
                    'guru' => is_array($cached['guru'] ?? null) ? $cached['guru'] : [],
                    'period_info' => is_array($cached['period_info'] ?? null) ? $cached['period_info'] : [],
                    'message' => '',
                    'cached' => true,
                    'stale' => true,
                ];
            }
        }

        return [
            'status' => 'error',
            'guru' => [],
            'period_info' => [],
            'message' => $message !== '' ? $message : 'Data penggajian tidak tersedia.',
            'cached' => false,
            'stale' => false,
        ];
    }
}

if (!function_exists('bendahara_periode_label')) {
    function bendahara_periode_label(string $periode): string
    {
        $bulan = [
            '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
            '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
            '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember',
        ];
        if (preg_match('/^(\d{4})-(\d{2})$/', $periode, $m)) {
            return ($bulan[$m[2]] ?? $m[2]) . ' ' . $m[1];
        }
        return $periode;
    }
}

if (!function_exists('bendahara_gaji_map_guru')) {
    /**
     * Petakan simad_id_guru ke data guru lokal.
     * @return array<int,array{id_guru:int,nama_guru:string,nip:string}>
     */
    function bendahara_gaji_map_guru(PDO $pdo): array
    {
        $map = [];
        try {
            foreach ($pdo->query("SELECT id_guru, nama_guru, nuptk, kode_guru FROM tb_guru") as $g) {
                $map[(int)$g['id_guru']] = [
                    'id_guru' => (int)$g['id_guru'],
                    'nama_guru' => (string)($g['nama_guru'] ?? ''),
                    'nip' => (string)(trim((string)($g['nuptk'] ?? '')) !== '' ? $g['nuptk'] : ($g['kode_guru'] ?? '')),
                ];
            }
        } catch (Throwable $e) {
            // biarkan kosong
        }
        return $map;
    }
}
