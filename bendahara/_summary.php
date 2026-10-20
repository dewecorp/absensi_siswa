<?php
/**
 * Helper ringkasan keuangan Pembayaran untuk level bendahara.
 * Cache 10 menit agar halaman tidak lambat saat API jarak jauh.
 */

if (!function_exists('bendahara_summary_cache_path')) {
    function bendahara_summary_cache_path(): string
    {
        return dirname(__DIR__) . '/cache/pembayaran_summary_local.json';
    }
}

if (!function_exists('bendahara_fetch_summary')) {
    /**
     * @return array{status:string,data:array,message:string,cached:bool}
     */
    function bendahara_fetch_summary(bool $force = false): array
    {
        $cacheFile = bendahara_summary_cache_path();
        $ttl = 600; // 10 menit

        if (!$force && is_file($cacheFile) && (time() - (int)@filemtime($cacheFile)) < $ttl) {
            $cached = @json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && ($cached['status'] ?? '') === 'success') {
                $cached['cached'] = true;
                return $cached;
            }
        }

        try {
            $client = new PembayaranClient();
            $response = $client->getAllStudentSummary();
        } catch (Throwable $e) {
            $response = ['status' => 'error', 'message' => $e->getMessage()];
        }

        if (($response['status'] ?? '') !== 'success') {
            // fallback cache lama
            if (is_file($cacheFile)) {
                $cached = @json_decode((string)@file_get_contents($cacheFile), true);
                if (is_array($cached) && ($cached['status'] ?? '') === 'success') {
                    $cached['cached'] = true;
                    $cached['stale'] = true;
                    return $cached;
                }
            }
            return [
                'status' => 'error',
                'data' => [],
                'message' => (string)($response['message'] ?? 'Gagal mengambil data Pembayaran Siswa'),
                'cached' => false,
            ];
        }

        $normalized = [
            'status' => 'success',
            'data' => is_array($response['data'] ?? null) ? $response['data'] : [],
            'message' => '',
            'cached' => false,
            'fetched_at' => date('Y-m-d H:i:s'),
        ];
        @file_put_contents($cacheFile, json_encode($normalized));
        return $normalized;
    }
}

if (!function_exists('bendahara_rupiah')) {
    function bendahara_rupiah($amount): string
    {
        return 'Rp ' . number_format((int)$amount, 0, ',', '.');
    }
}

if (!function_exists('bendahara_rupiah_short')) {
    /**
     * Rupiah ringkas untuk kartu statistik sempit: Rp 53,6 jt / Rp 1,2 M / Rp 425 rb.
     */
    function bendahara_rupiah_short($amount): string
    {
        $n = (int)$amount;
        $abs = abs($n);
        if ($abs >= 1000000000) {
            return 'Rp ' . number_format($n / 1000000000, 1, ',', '.') . ' M';
        }
        if ($abs >= 1000000) {
            return 'Rp ' . number_format($n / 1000000, 1, ',', '.') . ' jt';
        }
        if ($abs >= 1000) {
            return 'Rp ' . number_format($n / 1000, 0, ',', '.') . ' rb';
        }
        return 'Rp ' . number_format($n, 0, ',', '.');
    }
}

if (!function_exists('bendahara_kelas_label')) {
    function bendahara_kelas_label(string $kelas): string
    {
        $kelas = trim($kelas);
        return $kelas !== '' ? 'Kelas ' . $kelas : 'Tanpa kelas';
    }
}
