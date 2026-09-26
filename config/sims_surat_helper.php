<?php
// SIMS Surat Helper for SIMAD
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/endpoint_registry.php';

if (!function_exists('get_sims_root_url')) {
    function get_sims_root_url(string $base_url): string {
        $url = trim($base_url);
        if ($url === '') return '';
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'http://' . $url;
        }
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';

        $path = preg_replace('#/api/.*$#i', '', $path);
        $path = rtrim($path, '/');

        return $scheme . '://' . $host . $port . $path;
    }
}

if (!function_exists('fetch_sims_surat')) {
    function fetch_sims_surat(string $type, array $params = []): array {
        global $pdo;
        $cfg = getInboundEndpointConfig('sims');
        $base_url = trim((string)($cfg['base_url'] ?? ''));
        $api_key = trim((string)($cfg['api_key'] ?? ''));

        if ($base_url === '') {
            return [
                'status' => 'error',
                'message' => 'Endpoint SIMS belum dikonfigurasi. Silakan isi URL & API Key SIMS di menu Integrasi API.',
                'data' => [],
                'total_data' => 0
            ];
        }

        $root_url = get_sims_root_url($base_url);
        $endpoint_filename = '';
        switch ($type) {
            case 'surat-masuk':
                $endpoint_filename = 'api/v1/surat-masuk.php';
                break;
            case 'surat-keluar':
                $endpoint_filename = 'api/v1/surat-keluar.php';
                break;
            case 'surat-keputusan':
                $endpoint_filename = 'api/v1/surat-keputusan.php';
                break;
            default:
                $endpoint_filename = 'api/v1/' . ltrim($type, '/');
                break;
        }

        $full_target = rtrim($root_url, '/') . '/' . $endpoint_filename;
        $query_args = array_merge(['api_key' => $api_key], $params);
        $full_target .= '?' . http_build_query($query_args);

        $headers = ['X-API-KEY: ' . $api_key, 'Accept: application/json'];
        [$body, $code, $err] = endpoint_fetch_url($full_target, $headers);

        $cache_dir = __DIR__ . '/../cache';
        if (!is_dir($cache_dir)) @mkdir($cache_dir, 0755, true);
        $cache_file = $cache_dir . '/sims_' . str_replace('-', '_', $type) . '.json';

        if ($body !== false && $code === 200) {
            $json = json_decode((string)$body, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($json['status']) && $json['status'] === 'success') {
                @file_put_contents($cache_file, json_encode($json));
                return $json;
            }
        }

        if (is_file($cache_file)) {
            $cached = @json_decode(@file_get_contents($cache_file), true);
            if ($cached && isset($cached['status']) && $cached['status'] === 'success') {
                $cached['from_cache'] = true;
                return $cached;
            }
        }

        $err_msg = 'Gagal terhubung ke server SIMS';
        if ($code > 0) $err_msg .= " (HTTP $code)";
        if ($err !== '') $err_msg .= ": $err";

        return [
            'status' => 'error',
            'message' => $err_msg,
            'data' => [],
            'total_data' => 0
        ];
    }
}

if (!function_exists('get_sims_surat_counts')) {
    function get_sims_surat_counts(): array {
        $cache_dir = __DIR__ . '/../cache';
        $cache_file = $cache_dir . '/sims_counts.json';
        if (is_file($cache_file) && (time() - filemtime($cache_file) < 60)) {
            $cached = @json_decode(@file_get_contents($cache_file), true);
            if ($cached && is_array($cached)) {
                return $cached;
            }
        }

        $masuk_res = fetch_sims_surat('surat-masuk', ['limit' => 1000]);
        $keluar_res = fetch_sims_surat('surat-keluar', ['limit' => 1000]);
        $sk_res = fetch_sims_surat('surat-keputusan', ['limit' => 1000]);

        $cnt_masuk = (int)($masuk_res['total_data'] ?? count($masuk_res['data'] ?? []));
        $cnt_keluar = (int)($keluar_res['total_data'] ?? count($keluar_res['data'] ?? []));
        $cnt_sk = (int)($sk_res['total_data'] ?? count($sk_res['data'] ?? []));
        $cnt_total = $cnt_masuk + $cnt_keluar + $cnt_sk;

        $res = [
            'masuk' => $cnt_masuk,
            'keluar' => $cnt_keluar,
            'keputusan' => $cnt_sk,
            'total' => $cnt_total,
        ];
        if (!is_dir($cache_dir)) @mkdir($cache_dir, 0755, true);
        @file_put_contents($cache_file, json_encode($res));
        return $res;
    }
}
