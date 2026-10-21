<?php
/**
 * Bridge data Pembayaran Siswa langsung dari database Pembayaran Siswa.
 *
 * Semua fungsi di sini adalah port dari sibayar/api/simad.php, tetapi
 * dijalankan dalam proses SIMAD tanpa HTTP/API dan tanpa web Pembayaran.
 */

require_once __DIR__ . '/pembayaran_db.php';
require_once __DIR__ . '/../pembayaran/include/laporan_helper.php';

if (!function_exists('pembayaran_local_connection')) {
    function pembayaran_local_connection(): ?mysqli
    {
        static $conn = null;
        static $tried = false;
        if ($tried) return $conn;
        $tried = true;

        $cfg = pembayaran_db_config();
        if ($cfg['user'] === '' || $cfg['name'] === '') return null;

        if (function_exists('mysqli_report')) {
            mysqli_report(MYSQLI_REPORT_OFF);
        }

        $conn = @mysqli_connect($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name'], (int)$cfg['port']);
        if (!$conn) {
            $conn = null;
            return null;
        }
        @mysqli_query($conn, "SET time_zone = '+07:00'");
        return $conn;
    }
}

if (!function_exists('pembayaran_local_format_tunggakan_lama')) {
    function pembayaran_local_format_tunggakan_lama($tunggakan_lama): array
    {
        if (!$tunggakan_lama || !is_array($tunggakan_lama)) return [];

        $result = [];
        foreach ($tunggakan_lama as $tahun => $items) {
            $formatted_items = [];
            $total = 0;
            foreach ($items as $item) {
                $sisa = (int)($item['sisa'] ?? 0);
                $formatted_items[] = [
                    'id_jenis_bayar' => (int)($item['id_jenis_bayar'] ?? 0),
                    'nama_pembayaran' => $item['nama_pembayaran'] ?? '',
                    'tipe_bayar' => $item['tipe_bayar'] ?? '',
                    'tahun_ajaran' => $tahun,
                    'total_nominal' => (int)($item['nominal'] ?? 0),
                    'sisa_tagihan' => $sisa,
                    'item_belum_bayar' => $item['unpaid_details'] ?? [],
                    'is_lunas' => false,
                ];
                $total += $sisa;
            }
            $result[$tahun] = [
                'tahun_ajaran' => $tahun,
                'total_tunggakan' => $total,
                'items' => $formatted_items,
            ];
        }
        return $result;
    }
}

if (!function_exists('pembayaran_local_total_sudah_bayar')) {
    function pembayaran_local_total_sudah_bayar($koneksi, $nisn, $tahun_ajaran): int
    {
        ensure_pembayaran_arsip_table($koneksi);
        $nisn_esc = mysqli_real_escape_string($koneksi, (string)$nisn);
        $tahun_esc = mysqli_real_escape_string($koneksi, (string)$tahun_ajaran);
        $total = 0;
        foreach ([
            "SELECT COALESCE(SUM(jumlah_bayar), 0) AS total FROM pembayaran WHERE nisn = '$nisn_esc' AND tahun_ajaran = '$tahun_esc'",
            "SELECT COALESCE(SUM(jumlah_bayar), 0) AS total FROM pembayaran_arsip WHERE nisn = '$nisn_esc' AND tahun_ajaran = '$tahun_esc'",
        ] as $sql) {
            $q = mysqli_query($koneksi, $sql);
            if ($q && $row = mysqli_fetch_assoc($q)) $total += (int)($row['total'] ?? 0);
        }
        return $total;
    }
}

if (!function_exists('pembayaran_local_billing_for_student')) {
    function pembayaran_local_billing_for_student($koneksi, $siswa, $tahun_ajaran): array
    {
        if (!tahun_ajaran_boleh_ditagihkan($koneksi, $tahun_ajaran)) return [];

        $months = bulan_akademik_list();
        $limit_index = limit_index_bulan_tahun_ajaran($koneksi, $tahun_ajaran);
        $nisn = mysqli_real_escape_string($koneksi, $siswa['nisn'] ?? '');
        $q_masuk = mysqli_query($koneksi, "SELECT tanggal_masuk FROM siswa WHERE nisn = '$nisn' LIMIT 1");
        $row_masuk = $q_masuk ? mysqli_fetch_assoc($q_masuk) : null;
        $tanggal_masuk = $row_masuk ? trim($row_masuk['tanggal_masuk'] ?? '') : '';

        $min_index = 0;
        if ($tanggal_masuk !== '' && preg_match('/^(\d{4})\s*\/\s*(\d{4})$/', $tahun_ajaran, $m_thn)) {
            $tahun_mulai = (int)$m_thn[1];
            $dt_masuk = DateTime::createFromFormat('Y-m-d', $tanggal_masuk);
            if ($dt_masuk) {
                $diff = ((int)$dt_masuk->format('Y') - $tahun_mulai) * 12 + ((int)$dt_masuk->format('n') - 7);
                $min_index = $diff >= 12 ? 12 : max(0, $diff);
            }
        }

        $billing = [];
        $q_jenis = mysqli_query($koneksi, "SELECT * FROM jenis_bayar WHERE status = 'Aktif' ORDER BY tipe_bayar ASC, nama_pembayaran ASC");
        if (!$q_jenis) return $billing;

        while ($jb = mysqli_fetch_assoc($q_jenis)) {
            if (!jenis_bayar_berlaku_untuk_kelas($jb['tagihan_kelas'] ?? '', $siswa['id_kelas'], $siswa['nama_kelas'])) continue;

            $unpaid_details = [];
            $total_sisa = 0;
            $total_bayar = 0;
            if ($jb['tipe_bayar'] === 'Bulanan') {
                $paid_months = ambil_bulan_bayar_tersimpan($koneksi, $siswa['nisn'], $jb['id_jenis_bayar'], $tahun_ajaran);
                foreach ($months as $index => $month) {
                    if ($limit_index < 0 || $index > $limit_index || $index < $min_index) continue;
                    if (in_array($month, $paid_months, true)) {
                        $total_bayar += (int)$jb['nominal'];
                        continue;
                    }
                    $unpaid_details[] = $month;
                    $total_sisa += (int)$jb['nominal'];
                }
            } else {
                $total_bayar = ambil_total_bayar_tersimpan($koneksi, $siswa['nisn'], $jb['id_jenis_bayar'], $tahun_ajaran);
                $total_sisa = max(0, (int)$jb['nominal'] - $total_bayar);
                if ($total_sisa > 0) $unpaid_details[] = 'Belum Lunas';
            }

            $billing[] = [
                'id_jenis_bayar' => (int)$jb['id_jenis_bayar'],
                'nama_pembayaran' => $jb['nama_pembayaran'],
                'tipe_bayar' => $jb['tipe_bayar'],
                'tahun_ajaran' => $tahun_ajaran,
                'total_nominal' => (int)$jb['nominal'],
                'total_bayar' => $total_bayar,
                'sisa_tagihan' => $total_sisa,
                'item_belum_bayar' => $unpaid_details,
                'is_lunas' => ($total_sisa <= 0),
            ];
        }
        return $billing;
    }
}

if (!function_exists('pembayaran_local_student_summary')) {
    function pembayaran_local_student_summary($koneksi, array $siswa, string $tahun_ajaran): array
    {
        $billing = pembayaran_local_billing_for_student($koneksi, $siswa, $tahun_ajaran);
        $tunggakan_aktif = 0;
        foreach ($billing as $item) $tunggakan_aktif += (int)($item['sisa_tagihan'] ?? 0);

        $tunggakan_lama_raw = cek_tunggakan_tahun_ajaran_lama($koneksi, $siswa['nisn'], $tahun_ajaran);
        $tunggakan_lama = pembayaran_local_format_tunggakan_lama($tunggakan_lama_raw);
        $total_tunggakan_lama = 0;
        foreach ($tunggakan_lama as $group) $total_tunggakan_lama += (int)($group['total_tunggakan'] ?? 0);

        $total_tunggakan = $tunggakan_aktif + $total_tunggakan_lama;
        return [
            'billing' => $billing,
            'tunggakan_tahun_ajaran_lama' => $tunggakan_lama,
            'total_tunggakan_aktif' => $tunggakan_aktif,
            'total_tunggakan_tahun_lama' => $total_tunggakan_lama,
            'total_tunggakan' => $total_tunggakan,
            'total_sudah_bayar' => pembayaran_local_total_sudah_bayar($koneksi, $siswa['nisn'], $tahun_ajaran),
            'tahun_ajaran_tunggakan' => array_keys($tunggakan_lama),
            'is_lunas' => ($total_tunggakan <= 0),
        ];
    }
}

if (!function_exists('pembayaran_local_fetch_payments')) {
    function pembayaran_local_fetch_payments($koneksi, $nisn, $tahun_ajaran_filter = ''): array
    {
        ensure_pembayaran_arsip_table($koneksi);
        $nisn_esc = mysqli_real_escape_string($koneksi, (string)$nisn);
        $payments = [];
        $sql = "SELECT p.*, jb.nama_pembayaran FROM pembayaran p JOIN jenis_bayar jb ON p.id_jenis_bayar = jb.id_jenis_bayar WHERE p.nisn = '$nisn_esc'";
        if ($tahun_ajaran_filter !== '') {
            $tahun_esc = mysqli_real_escape_string($koneksi, (string)$tahun_ajaran_filter);
            $sql .= " AND p.tahun_ajaran = '$tahun_esc'";
        }
        $sql .= " ORDER BY p.tgl_bayar DESC, p.id_pembayaran DESC";
        $q = mysqli_query($koneksi, $sql);
        while ($q && ($h = mysqli_fetch_assoc($q))) {
            $payments[] = [
                'no_transaksi' => $h['no_transaksi'] ?? '',
                'tgl_bayar' => $h['tgl_bayar'] ?? '',
                'jumlah_bayar' => (int)($h['jumlah_bayar'] ?? 0),
                'nama_pembayaran' => $h['nama_pembayaran'] ?? '',
                'ket' => $h['ket'] ?? '',
                'waktu_input' => $h['created_at'] ?? '',
                'tahun_bayar' => $h['tahun_bayar'] ?? '',
                'tahun_ajaran' => $h['tahun_ajaran'] ?? '',
            ];
        }
        return $payments;
    }
}

if (!function_exists('pembayaran_local_tahun_ajaran_aktif')) {
    function pembayaran_local_tahun_ajaran_aktif($koneksi): string
    {
        return trim((string)get_tahun_ajaran_aktif($koneksi));
    }
}

if (!function_exists('pembayaran_local_resolve_tahun')) {
    function pembayaran_local_resolve_tahun($koneksi, string $tahun_ajaran_param = ''): string
    {
        $tahun = trim($tahun_ajaran_param);
        if ($tahun !== '') return $tahun;
        // Default mengikuti tahun ajaran berjalan (batas: Tanggal Mulai Tahun Ajaran).
        $berjalan = trim((string)tahun_ajaran_berjalan($koneksi));
        return $berjalan !== '' ? $berjalan : pembayaran_local_tahun_ajaran_aktif($koneksi);
    }
}

if (!function_exists('pembayaran_local_get_tahun_ajaran')) {
    function pembayaran_local_get_tahun_ajaran($koneksi): array
    {
        $list = [];
        foreach ([
            "SELECT DISTINCT tahun_ajaran FROM jenis_bayar WHERE tahun_ajaran IS NOT NULL AND tahun_ajaran <> '' ORDER BY tahun_ajaran DESC",
            "SELECT DISTINCT tahun_ajaran FROM pembayaran WHERE tahun_ajaran IS NOT NULL AND tahun_ajaran <> '' ORDER BY tahun_ajaran DESC"
        ] as $sql) {
            $q = @mysqli_query($koneksi, $sql);
            while ($q && ($row = mysqli_fetch_assoc($q))) {
                $tahun = trim((string)($row['tahun_ajaran'] ?? ''));
                if ($tahun !== '' && !in_array($tahun, $list, true)) $list[] = $tahun;
            }
        }
        rsort($list);
        return $list;
    }
}

if (!function_exists('pembayaran_local_get_all_summary')) {
    function pembayaran_local_get_all_summary(string $tahun_ajaran_param = ''): array
    {
        $koneksi = pembayaran_local_connection();
        if (!$koneksi) return ['status' => 'error', 'message' => 'Database Pembayaran Siswa tidak terhubung'];
        ensure_pembayaran_tahun_ajaran_column($koneksi);
        ensure_siswa_tanggal_masuk_column($koneksi);

        $tahun_ajaran = pembayaran_local_resolve_tahun($koneksi, $tahun_ajaran_param);
        $aktif = pembayaran_local_tahun_ajaran_aktif($koneksi);
        $data = [];
        $q = mysqli_query($koneksi, "SELECT s.nisn, s.nama, k.nama_kelas, k.id_kelas FROM siswa s JOIN kelas k ON s.id_kelas = k.id_kelas ORDER BY k.nama_kelas ASC, s.nama ASC");
        while ($q && ($siswa = mysqli_fetch_assoc($q))) {
            $s = pembayaran_local_student_summary($koneksi, $siswa, $tahun_ajaran);
            $data[] = [
                'nisn' => $siswa['nisn'],
                'nama' => $siswa['nama'],
                'kelas' => $siswa['nama_kelas'],
                'tahun_ajaran' => $tahun_ajaran,
                'total_tunggakan_aktif' => $s['total_tunggakan_aktif'],
                'total_tunggakan_tahun_lama' => $s['total_tunggakan_tahun_lama'],
                'total_tunggakan' => $s['total_tunggakan'],
                'total_sudah_bayar' => $s['total_sudah_bayar'],
                'tahun_ajaran_tunggakan' => $s['tahun_ajaran_tunggakan'],
                'is_lunas' => $s['is_lunas'],
            ];
        }
        return [
            'status' => 'success',
            'data' => $data,
            'count' => count($data),
            'tahun_ajaran' => $tahun_ajaran,
            'tahun_ajaran_aktif' => $aktif,
            'env' => 'simad-local',
        ];
    }
}

if (!function_exists('pembayaran_local_get_student_data')) {
    function pembayaran_local_get_student_data(string $nisn, string $tahun_ajaran_param = ''): array
    {
        $koneksi = pembayaran_local_connection();
        if (!$koneksi) return ['status' => 'error', 'message' => 'Database Pembayaran Siswa tidak terhubung'];
        ensure_pembayaran_tahun_ajaran_column($koneksi);
        ensure_siswa_tanggal_masuk_column($koneksi);

        $tahun_ajaran = pembayaran_local_resolve_tahun($koneksi, $tahun_ajaran_param);
        $aktif = pembayaran_local_tahun_ajaran_aktif($koneksi);
        $nisn_esc = mysqli_real_escape_string($koneksi, $nisn);
        $q = mysqli_query($koneksi, "SELECT s.*, k.nama_kelas, k.id_kelas FROM siswa s JOIN kelas k ON s.id_kelas = k.id_kelas WHERE s.nisn = '$nisn_esc' LIMIT 1");
        $siswa = $q ? mysqli_fetch_assoc($q) : null;
        if (!$siswa) return ['status' => 'error', 'message' => 'Student not found'];

        $s = pembayaran_local_student_summary($koneksi, $siswa, $tahun_ajaran);
        return [
            'status' => 'success',
            'student' => [
                'nisn' => $siswa['nisn'],
                'nama' => $siswa['nama'],
                'kelas' => $siswa['nama_kelas'],
            ],
            'tahun_ajaran' => $tahun_ajaran,
            'tahun_ajaran_aktif' => $aktif,
            'billing' => $s['billing'],
            'tunggakan_tahun_ajaran_lama' => $s['tunggakan_tahun_ajaran_lama'],
            'payments' => pembayaran_local_fetch_payments($koneksi, $nisn, trim($tahun_ajaran_param)),
            'summary' => [
                'total_tunggakan_aktif' => $s['total_tunggakan_aktif'],
                'total_tunggakan_tahun_lama' => $s['total_tunggakan_tahun_lama'],
                'total_tunggakan' => $s['total_tunggakan'],
                'total_sudah_bayar' => $s['total_sudah_bayar'],
                'tahun_ajaran_tunggakan' => $s['tahun_ajaran_tunggakan'],
                'is_lunas' => $s['is_lunas'],
            ],
            'env' => 'simad-local',
        ];
    }
}
