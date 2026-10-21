<?php
/**
 * Jembatan sinkronisasi Tabungan <-> Pembayaran (modul Pembayaran/Sibayar di SIMAD).
 *
 * Modul Tabungan dan modul Pembayaran memakai satu database (db_absensi),
 * sehingga penarikan tabungan dengan tujuan "pembayaran" langsung dicatat ke tabel
 * `pembayaran` (data yang sama dengan modul Pembayaran Siswa / Sibayar).
 *
 * Tidak ada lagi panggilan HTTP ke server Sibayar eksternal.
 */

require_once __DIR__ . '/../../../config/pembayaran_local.php';

if (!function_exists('tabungan_pembayaran_koneksi')) {
    /** Koneksi mysqli ke database modul Pembayaran. */
    function tabungan_pembayaran_koneksi(): ?mysqli
    {
        static $conn = null;
        static $tried = false;
        if ($tried) {
            return $conn;
        }
        $tried = true;

        if (function_exists('pembayaran_local_connection')) {
            $conn = pembayaran_local_connection();
        }
        if (!$conn && function_exists('pembayaran_db_config')) {
            $cfg = pembayaran_db_config();
            if (!empty($cfg['user']) && !empty($cfg['name'])) {
                if (function_exists('mysqli_report')) {
                    mysqli_report(MYSQLI_REPORT_OFF);
                }
                $conn = @mysqli_connect($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name'], (int)$cfg['port']);
                if ($conn) {
                    @mysqli_query($conn, "SET time_zone = '+07:00'");
                    mysqli_set_charset($conn, 'utf8mb4');
                } else {
                    $conn = null;
                }
            }
        }
        return $conn;
    }
}

if (!function_exists('tabungan_tahun_ajaran_aktif')) {
    /** Tahun ajaran aktif (dari tabel `pengaturan`). */
    function tabungan_tahun_ajaran_aktif(): string
    {
        $koneksi = tabungan_pembayaran_koneksi();
        if ($koneksi && function_exists('get_tahun_ajaran_aktif')) {
            $ta = trim((string)get_tahun_ajaran_aktif($koneksi));
            if ($ta !== '') {
                return $ta;
            }
        }
        $year = (int)date('Y');
        $month = (int)date('n');
        return $month >= 7 ? $year . '/' . ($year + 1) : ($year - 1) . '/' . $year;
    }
}

if (!function_exists('tabungan_petugas_pembayaran_id')) {
    /**
     * id_pengguna untuk kolom pembayaran.id_petugas.
     * Akun SIMAD dipetakan ke tabel `pengguna` (modul Pembayaran) bila username sama.
     */
    function tabungan_petugas_pembayaran_id(): int
    {
        static $id = null;
        if ($id !== null) {
            return $id;
        }

        $koneksi = tabungan_pembayaran_koneksi();
        if (!$koneksi) {
            return 0;
        }

        $username = trim((string)($_SESSION['username'] ?? ''));
        if ($username !== '') {
            $st = mysqli_prepare($koneksi, "SELECT id_pengguna FROM pengguna WHERE username = ? LIMIT 1");
            if ($st) {
                mysqli_stmt_bind_param($st, 's', $username);
                mysqli_stmt_execute($st);
                $res = mysqli_stmt_get_result($st);
                $row = $res ? mysqli_fetch_assoc($res) : null;
                mysqli_stmt_close($st);
                if ($row && (int)$row['id_pengguna'] > 0) {
                    $id = (int)$row['id_pengguna'];
                    return $id;
                }
            }
        }

        // Fallback: pilih user modul Pembayaran yang valid agar foreign key pembayaran tidak gagal.
        // Kolom role pada tabel pengguna milik modul Pembayaran (admin/petugas).
        $role = (($_SESSION['level'] ?? '') === 'admin') ? 'admin' : 'petugas';
        $st = mysqli_prepare($koneksi, "SELECT id_pengguna FROM pengguna WHERE role = ? ORDER BY id_pengguna ASC LIMIT 1");
        if ($st) {
            mysqli_stmt_bind_param($st, 's', $role);
            mysqli_stmt_execute($st);
            $res = mysqli_stmt_get_result($st);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            mysqli_stmt_close($st);
            if ($row && (int)$row['id_pengguna'] > 0) {
                $id = (int)$row['id_pengguna'];
                return $id;
            }
        }

        $id = 1;
        return $id;
    }
}

if (!function_exists('tabungan_jenis_bayar')) {
    /**
     * Data master jenis pembayaran (nominal & tipe) dari database Pembayaran.
     */
    function tabungan_jenis_bayar(int $id_jenis_bayar): ?array
    {
        $koneksi = tabungan_pembayaran_koneksi();
        if (!$koneksi || $id_jenis_bayar <= 0) {
            return null;
        }
        $st = mysqli_prepare($koneksi, "SELECT id_jenis_bayar, nama_pembayaran, tipe_bayar, nominal, kali_cicilan, status FROM jenis_bayar WHERE id_jenis_bayar = ? LIMIT 1");
        if (!$st) {
            return null;
        }
        mysqli_stmt_bind_param($st, 'i', $id_jenis_bayar);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($st);
        if (!$row) {
            return null;
        }
        return [
            'id_jenis_bayar' => (int)$row['id_jenis_bayar'],
            'nama_pembayaran' => (string)$row['nama_pembayaran'],
            'tipe_bayar' => (string)$row['tipe_bayar'],
            'nominal' => (int)$row['nominal'],
            'kali_cicilan' => (int)($row['kali_cicilan'] ?? 0),
            'status' => (string)$row['status'],
        ];
    }
}

if (!function_exists('tabungan_cicilan_ke_berikutnya')) {
    /**
     * Nomor cicilan berikutnya untuk satu jenis pembayaran (dihitung dari data tersimpan).
     */
    function tabungan_cicilan_ke_berikutnya(mysqli $koneksi, string $nisn, int $id_jenis_bayar, string $tahun_ajaran): int
    {
        $nisn = mysqli_real_escape_string($koneksi, $nisn);
        $id = (int)$id_jenis_bayar;
        $ta = mysqli_real_escape_string($koneksi, $tahun_ajaran);

        if (function_exists('ensure_pembayaran_arsip_table')) {
            ensure_pembayaran_arsip_table($koneksi);
        }

        $total = 0;
        $queries = [
            "SELECT COUNT(*) AS jml FROM pembayaran WHERE nisn = '$nisn' AND id_jenis_bayar = '$id' AND tahun_ajaran = '$ta'",
            "SELECT COUNT(*) AS jml FROM pembayaran_arsip WHERE nisn = '$nisn' AND id_jenis_bayar = '$id' AND tahun_ajaran = '$ta'",
        ];
        foreach ($queries as $sql) {
            $q = @mysqli_query($koneksi, $sql);
            if ($q && $row = mysqli_fetch_assoc($q)) {
                $total += (int)($row['jml'] ?? 0);
            }
        }

        return $total + 1;
    }
}

if (!function_exists('tabungan_bangun_item_tagihan')) {
    /**
     * Susun item tagihan lengkap dengan rincian per bulan (agar bisa dibayar sebagian).
     *
     * @param array{bulan?:array,sisa?:int,nominal?:int} $data
     */
    function tabungan_bangun_item_tagihan(array $data, string $tahun_ajaran, bool $is_tunggakan, int $cicilan_ke = 1): array
    {
        $id_jenis_bayar = (int)($data['id_jenis_bayar'] ?? 0);
        $tipe = (string)($data['tipe_bayar'] ?? 'Bulanan');
        $nominal = (int)($data['nominal'] ?? 0);
        $bulan = [];
        foreach ((array)($data['bulan'] ?? []) as $m) {
            $m = trim((string)$m);
            if ($m !== '') {
                $bulan[] = $m;
            }
        }
        $sisa = (int)($data['sisa'] ?? 0);

        $rincian = [];
        if ($tipe === 'Bulanan') {
            foreach ($bulan as $m) {
                $rincian[] = [
                    'id' => $id_jenis_bayar . '_' . preg_replace('/[^A-Za-z0-9]/', '', $m),
                    'label' => $m,
                    'bulan' => $m,
                    'nominal' => $nominal,
                    'sisa' => $nominal,
                    'lunas' => false,
                    'bisa_sebagian' => false,
                ];
            }
        } elseif ($sisa > 0) {
            $rincian[] = [
                'id' => $id_jenis_bayar . '_sisa',
                'label' => $tipe === 'Cicilan' ? ('Kurang ' . max(1, (int)($data['sisa_cicilan'] ?? 1)) . 'x cicilan') : 'Kurang',
                'bulan' => '',
                'nominal' => $nominal,
                'sisa' => $sisa,
                'lunas' => false,
                'bisa_sebagian' => true,
            ];
        }

        $kali_cicilan = (int)($data['kali_cicilan'] ?? 0);
        $sisa_cicilan = (int)($data['sisa_cicilan'] ?? 0);
        $per_cicilan = 0;
        if ($tipe === 'Cicilan' && $kali_cicilan > 0) {
            $per_cicilan = (int)round($nominal / $kali_cicilan);
        } elseif ($tipe === 'Bulanan') {
            $per_cicilan = $nominal;
        }

        return [
            'id_jenis_bayar' => $id_jenis_bayar,
            'nama_pembayaran' => (string)($data['nama_pembayaran'] ?? '-'),
            'tipe_bayar' => $tipe,
            'tahun_ajaran' => $tahun_ajaran,
            'nominal' => $nominal,
            'sisa' => $sisa,
            'bulan' => $bulan,
            'rincian' => $rincian,
            'cicilan_ke' => $cicilan_ke,
            'kali_cicilan' => $kali_cicilan,
            'sisa_cicilan' => $sisa_cicilan,
            'per_cicilan' => $per_cicilan,
            'is_lunas' => $sisa <= 0,
            'is_tunggakan' => $is_tunggakan,
            'sisa_per_bulan' => $tipe === 'Bulanan' ? $nominal : 0,
        ];
    }
}

if (!function_exists('tabungan_tagihan_siswa')) {
    /**
     * Daftar tagihan siswa per jenis pembayaran (data live dari modul Pembayaran).
     *
     * Mengandung tagihan tahun ajaran aktif sekaligus tunggakan tahun ajaran lama,
     * lengkap dengan rincian per bulan sehingga pembayaran dapat dilakukan sebagian (cicil).
     *
     * @return array{ok:bool,tahun_ajaran:string,items:array,message:string}
     */
    function tabungan_tagihan_siswa(string $nisn): array
    {
        $koneksi = tabungan_pembayaran_koneksi();
        if (!$koneksi) {
            return ['ok' => false, 'tahun_ajaran' => '', 'items' => [], 'message' => 'Koneksi database modul Pembayaran gagal.'];
        }

        $tahun_ajaran = tabungan_tahun_ajaran_aktif();

        $st = mysqli_prepare($koneksi, "SELECT s.nisn, s.nama, s.id_kelas, k.nama_kelas FROM siswa s LEFT JOIN kelas k ON k.id_kelas = s.id_kelas WHERE s.nisn = ? LIMIT 1");
        $siswa = null;
        if ($st) {
            mysqli_stmt_bind_param($st, 's', $nisn);
            mysqli_stmt_execute($st);
            $res = mysqli_stmt_get_result($st);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            mysqli_stmt_close($st);
            if ($row) {
                $siswa = $row;
            }
        }
        if (!$siswa) {
            return ['ok' => false, 'tahun_ajaran' => $tahun_ajaran, 'items' => [], 'message' => 'Data siswa tidak ditemukan pada database pembayaran.'];
        }

        $items = [];
        if (function_exists('pembayaran_local_billing_for_student')) {
            $billing = pembayaran_local_billing_for_student($koneksi, $siswa, $tahun_ajaran);
            foreach ($billing as $b) {
                $id_jenis_bayar = (int)($b['id_jenis_bayar'] ?? 0);
                $tipe = (string)($b['tipe_bayar'] ?? 'Bulanan');
                $nominal = (int)($b['total_nominal'] ?? 0);
                $bulan = [];
                foreach ((array)($b['item_belum_bayar'] ?? []) as $m) {
                    $bulan[] = (string)$m;
                }
                $sisa = (int)($b['sisa_tagihan'] ?? 0);
                $master = tabungan_jenis_bayar($id_jenis_bayar);
                $kali_cicilan = (int)($master['kali_cicilan'] ?? 0);
                $cicilan_ke = $tipe === 'Bulanan'
                    ? 0
                    : tabungan_cicilan_ke_berikutnya($koneksi, $nisn, $id_jenis_bayar, $tahun_ajaran);
                $sisa_cicilan = $tipe === 'Cicilan' && $kali_cicilan > 0
                    ? max(0, $kali_cicilan - max(0, $cicilan_ke - 1))
                    : 0;

                $items[] = tabungan_bangun_item_tagihan([
                    'id_jenis_bayar' => $id_jenis_bayar,
                    'nama_pembayaran' => (string)($b['nama_pembayaran'] ?? '-'),
                    'tipe_bayar' => $tipe,
                    'nominal' => $nominal,
                    'sisa' => $sisa,
                    'bulan' => $bulan,
                    'kali_cicilan' => $kali_cicilan,
                    'sisa_cicilan' => $sisa_cicilan,
                ], $tahun_ajaran, false, $cicilan_ke);
            }
        }

        // Tunggakan tahun ajaran lama -> bisa dilunasi (bahkan sebagian) lewat tabungan.
        $tunggakan = tabungan_tunggakan_tahun_lama($nisn);
        if ($tunggakan && is_array($tunggakan)) {
            foreach ($tunggakan as $ta => $list) {
                foreach ((array)$list as $b) {
                    $id_jenis_bayar = (int)($b['id_jenis_bayar'] ?? 0);
                    $tipe = (string)($b['tipe_bayar'] ?? 'Bulanan');
                    $bulan = [];
                    foreach ((array)($b['unpaid_details'] ?? []) as $m) {
                        $m = trim((string)$m);
                        if ($m !== '' && $m !== 'Belum Lunas' && $m !== 'Sisa Tagihan') {
                            $bulan[] = $m;
                        }
                    }
                    $sisa = (int)($b['sisa'] ?? 0);
                    $master = tabungan_jenis_bayar($id_jenis_bayar);
                    $kali_cicilan = (int)($master['kali_cicilan'] ?? 0);
                    $cicilan_ke = $tipe === 'Bulanan'
                        ? 0
                        : tabungan_cicilan_ke_berikutnya($koneksi, $nisn, $id_jenis_bayar, (string)$ta);
                    $sisa_cicilan = $tipe === 'Cicilan' && $kali_cicilan > 0
                        ? max(0, $kali_cicilan - max(0, $cicilan_ke - 1))
                        : 0;

                    $items[] = tabungan_bangun_item_tagihan([
                        'id_jenis_bayar' => $id_jenis_bayar,
                        'nama_pembayaran' => (string)($b['nama_pembayaran'] ?? '-'),
                        'tipe_bayar' => $tipe,
                        'nominal' => (int)($b['nominal'] ?? 0),
                        'sisa' => $sisa,
                        'bulan' => $bulan,
                        'kali_cicilan' => $kali_cicilan,
                        'sisa_cicilan' => $sisa_cicilan,
                    ], (string)$ta, true, $cicilan_ke);
                }
            }
        }

        return ['ok' => true, 'tahun_ajaran' => $tahun_ajaran, 'items' => $items, 'message' => ''];
    }
}

if (!function_exists('tabungan_tunggakan_tahun_lama')) {
    /**
     * Tunggakan tahun ajaran lama siswa (jika ada) -> array TA => items.
     */
    function tabungan_tunggakan_tahun_lama(string $nisn): array
    {
        $koneksi = tabungan_pembayaran_koneksi();
        if (!$koneksi || !function_exists('cek_tunggakan_tahun_ajaran_lama')) {
            return [];
        }
        $hasil = cek_tunggakan_tahun_ajaran_lama($koneksi, $nisn, tabungan_tahun_ajaran_aktif());
        if (!$hasil || !is_array($hasil)) {
            return [];
        }
        return $hasil;
    }
}

if (!function_exists('tabungan_no_transaksi_baru')) {
    /**
     * Nomor transaksi pembayaran khusus tabungan: TAB-YYYYMM-NNN.
     */
    function tabungan_no_transaksi_baru(mysqli $koneksi, string $tgl): string
    {
        $prefix = 'TAB-' . date('Ym', strtotime($tgl ?: date('Y-m-d'))) . '-';
        $prefix_len = strlen($prefix) + 1;

        $st = mysqli_prepare($koneksi, "SELECT COALESCE(MAX(CAST(SUBSTRING(no_transaksi, ?) AS UNSIGNED)), 0) AS last_urut FROM pembayaran WHERE no_transaksi LIKE ?");
        $next = 1;
        if ($st) {
            $like = $prefix . '%';
            mysqli_stmt_bind_param($st, 'is', $prefix_len, $like);
            mysqli_stmt_execute($st);
            $res = mysqli_stmt_get_result($st);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            mysqli_stmt_close($st);
            if ($row) {
                $next = ((int)$row['last_urut']) + 1;
            }
        }

        // Hindari nomor bentrok (kolom no_transaksi tidak UNIQUE di modul Pembayaran).
        for ($i = 0; $i < 50; $i++) {
            $no = $prefix . sprintf("%03d", $next);
            $cek = mysqli_prepare($koneksi, "SELECT COUNT(*) FROM pembayaran WHERE no_transaksi = ?");
            if ($cek) {
                mysqli_stmt_bind_param($cek, 's', $no);
                mysqli_stmt_execute($cek);
                $res = mysqli_stmt_get_result($cek);
                $row = $res ? mysqli_fetch_assoc($res) : null;
                mysqli_stmt_close($cek);
                if ($row && (int)$row['COUNT(*)'] === 0) {
                    return $no;
                }
            }
            $next++;
        }
        return $prefix . sprintf("%03d", $next);
    }
}

if (!function_exists('tabungan_catat_pembayaran')) {
    /**
     * Catat pembayaran hasil potong tabungan ke tabel `pembayaran`.
     *
     * Nominal selalu dihitung ulang di server:
     * - tagihan bulanan: nominal master x jumlah bulan yang dipilih (bisa sebagian / cicil)
     * - tagihan cicilan / sekali bayar: nominal yang dipotong (bisa lebih kecil dari sisa)
     *
     * @param array{nisn:string,tgl:string,items:array,tahun_ajaran?:string,keterangan?:string} $param
     * @return array{ok:bool,no_transaksi:array,items:array,message:string}
     */
    function tabungan_catat_pembayaran(array $param): array
    {
        $koneksi = tabungan_pembayaran_koneksi();
        if (!$koneksi) {
            return ['ok' => false, 'no_transaksi' => [], 'items' => [], 'message' => 'Koneksi database modul Pembayaran gagal.'];
        }

        $nisn = trim((string)($param['nisn'] ?? ''));
        $tgl = trim((string)($param['tgl'] ?? date('Y-m-d')));
        $tahun_ajaran = trim((string)($param['tahun_ajaran'] ?? tabungan_tahun_ajaran_aktif()));
        $items = is_array($param['items'] ?? null) ? $param['items'] : [];
        $keterangan = trim((string)($param['keterangan'] ?? 'Potongan Tabungan'));
        $id_petugas = tabungan_petugas_pembayaran_id();

        if ($nisn === '' || !$items) {
            return ['ok' => false, 'no_transaksi' => [], 'items' => [], 'message' => 'Data pembayaran belum lengkap.'];
        }
        if ($id_petugas <= 0) {
            return ['ok' => false, 'no_transaksi' => [], 'items' => [], 'message' => 'Akun pembayaran tidak valid. Silakan logout/login kembali.'];
        }

        $tahun_bayar = date('Y', strtotime($tgl ?: date('Y-m-d')));
        $no_transaksi = [];
        $hasil_items = [];

        mysqli_begin_transaction($koneksi);
        try {
            foreach ($items as $item) {
                $id_jenis_bayar = (int)($item['id_jenis_bayar'] ?? 0);
                if ($id_jenis_bayar <= 0) {
                    continue;
                }

                $item_ta = trim((string)($item['tahun_ajaran'] ?? ''));
                if ($item_ta === '') {
                    $item_ta = $tahun_ajaran;
                }

                $master = tabungan_jenis_bayar($id_jenis_bayar);
                if (!$master) {
                    throw new Exception('Jenis pembayaran #' . $id_jenis_bayar . ' tidak ditemukan.');
                }

                $tipe = (string)$master['tipe_bayar'];
                $nominal = (int)$master['nominal'];
                $bulan_bayar = '';
                $cicilan_ke = 0;
                $bulan = [];

                if ($tipe === 'Bulanan') {
                    foreach ((array)($item['bulan'] ?? []) as $m) {
                        $m = trim((string)$m);
                        if ($m !== '') {
                            $bulan[] = $m;
                        }
                    }
                    if (!$bulan) {
                        throw new Exception('Bulan pembayaran wajib dipilih untuk tagihan bulanan.');
                    }
                    // Buang bulan yang ternyata sudah dibayar (aman bila ada transaksi bersamaan).
                    if (function_exists('ambil_bulan_bayar_tersimpan')) {
                        $sudah = ambil_bulan_bayar_tersimpan($koneksi, $nisn, $id_jenis_bayar, $item_ta);
                        if ($sudah) {
                            $bulan = array_values(array_diff($bulan, $sudah));
                        }
                    }
                    if (!$bulan) {
                        throw new Exception('Bulan yang dipilih sudah lunas. Silakan muat ulang tagihan siswa.');
                    }
                    $jumlah_bayar = $nominal * count($bulan);
                    $bulan_bayar = implode(', ', $bulan);
                } else {
                    $sisa = $nominal;
                    if (function_exists('ambil_total_bayar_tersimpan')) {
                        $sisa = $nominal - ambil_total_bayar_tersimpan($koneksi, $nisn, $id_jenis_bayar, $item_ta);
                    }
                    if ($sisa <= 0) {
                        continue;
                    }
                    $jumlah_bayar = (int)($item['jumlah'] ?? 0);
                    if ($jumlah_bayar <= 0) {
                        $jumlah_bayar = $sisa;
                    }
                    if ($jumlah_bayar > $sisa) {
                        throw new Exception('Nominal ' . $jumlah_bayar . ' untuk "' . $master['nama_pembayaran'] . '" melebihi sisa tagihan ' . $sisa . '.');
                    }
                    $cicilan_ke = (int)($item['cicilan_ke'] ?? 0);
                    if ($cicilan_ke <= 0) {
                        $cicilan_ke = tabungan_cicilan_ke_berikutnya($koneksi, $nisn, $id_jenis_bayar, $item_ta);
                    }
                }

                if ($jumlah_bayar <= 0) {
                    continue;
                }

                $no = tabungan_no_transaksi_baru($koneksi, $tgl);
                $ket = $keterangan . ' - ' . (string)($item['nama_pembayaran'] ?? $master['nama_pembayaran']);
                if ($bulan_bayar !== '') {
                    $ket .= ' (' . $bulan_bayar . ')';
                } elseif ($cicilan_ke > 0) {
                    $ket .= ' (Cicilan ke-' . $cicilan_ke . ')';
                }

                $st = mysqli_prepare($koneksi, "INSERT INTO pembayaran (id_petugas, nisn, tgl_bayar, id_jenis_bayar, jumlah_bayar, cicilan_ke, ket, bulan_bayar, tahun_bayar, tahun_ajaran, no_transaksi) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$st) {
                    throw new Exception('Gagal menyiapkan statement pembayaran: ' . mysqli_error($koneksi));
                }
                mysqli_stmt_bind_param($st, 'issiiisssss', $id_petugas, $nisn, $tgl, $id_jenis_bayar, $jumlah_bayar, $cicilan_ke, $ket, $bulan_bayar, $tahun_bayar, $item_ta, $no);
                $ok = mysqli_stmt_execute($st);
                $err = mysqli_stmt_error($st);
                mysqli_stmt_close($st);
                if (!$ok) {
                    throw new Exception('Gagal menyimpan data pembayaran: ' . $err);
                }

                $no_transaksi[] = $no;
                $hasil_items[] = [
                    'id_jenis_bayar' => $id_jenis_bayar,
                    'nama_pembayaran' => (string)($item['nama_pembayaran'] ?? $master['nama_pembayaran']),
                    'tipe_bayar' => $tipe,
                    'tahun_ajaran' => $item_ta,
                    'nominal' => $nominal,
                    'sisa' => $jumlah_bayar,
                    'bulan' => $bulan,
                    'bulan_bayar' => $bulan_bayar,
                    'cicilan_ke' => $cicilan_ke,
                    'no_transaksi' => $no,
                    'jumlah_bayar' => $jumlah_bayar,
                ];
            }

            if (!$no_transaksi) {
                throw new Exception('Tidak ada item pembayaran yang valid untuk disimpan.');
            }

            if (function_exists('pembayaran_log_activity')) {
                pembayaran_log_activity($koneksi, 'Create', 'Pencatatan pembayaran dari potongan tabungan siswa NISN: ' . $nisn . ', No Transaksi: ' . implode(', ', $no_transaksi));
            }

            mysqli_commit($koneksi);

            return [
                'ok' => true,
                'no_transaksi' => $no_transaksi,
                'items' => $hasil_items,
                'message' => 'Pembayaran berhasil dicatat: ' . implode(', ', $no_transaksi),
            ];
        } catch (Throwable $e) {
            mysqli_rollback($koneksi);
            return ['ok' => false, 'no_transaksi' => [], 'items' => [], 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('tabungan_hapus_pembayaran')) {
    /**
     * Hapus pembayaran yang berumber dari penarikan tabungan (dipakai saat penarikan dihapus).
     */
    function tabungan_hapus_pembayaran(array $no_transaksi): int
    {
        $koneksi = tabungan_pembayaran_koneksi();
        if (!$koneksi) {
            return 0;
        }
        $no_transaksi = array_values(array_filter(array_map('trim', $no_transaksi), static fn($v) => $v !== ''));
        if (!$no_transaksi) {
            return 0;
        }

        $hapus = 0;
        foreach ($no_transaksi as $no) {
            $st = mysqli_prepare($koneksi, "DELETE FROM pembayaran WHERE no_transaksi = ?");
            if ($st) {
                mysqli_stmt_bind_param($st, 's', $no);
                mysqli_stmt_execute($st);
                $hapus += (int)mysqli_stmt_affected_rows($st);
                mysqli_stmt_close($st);
            }
        }
        return $hapus;
    }
}

if (!function_exists('tabungan_pembayaran_sudah_ada')) {
    /** Apakah nomor transaksi pembayaran sudah tercatat. */
    function tabungan_pembayaran_sudah_ada(string $no_transaksi): bool
    {
        $koneksi = tabungan_pembayaran_koneksi();
        if (!$koneksi || trim($no_transaksi) === '') {
            return false;
        }
        $st = mysqli_prepare($koneksi, "SELECT COUNT(*) FROM pembayaran WHERE no_transaksi = ?");
        if (!$st) {
            return false;
        }
        mysqli_stmt_bind_param($st, 's', $no_transaksi);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($st);
        return $row && (int)($row['COUNT(*)'] ?? 0) > 0;
    }
}

if (!function_exists('tabungan_nama_bendahara')) {
    /** Nama bendahara (data profil modul Pembayaran, sinkron dengan profil SIMAD). */
    function tabungan_nama_bendahara(): string
    {
        $koneksi = tabungan_pembayaran_koneksi();
        if ($koneksi) {
            $st = mysqli_prepare($koneksi, "SELECT nama_bendahara FROM pengaturan WHERE id_pengaturan = 1 LIMIT 1");
            if ($st) {
                mysqli_stmt_execute($st);
                $res = mysqli_stmt_get_result($st);
                $row = $res ? mysqli_fetch_assoc($res) : null;
                mysqli_stmt_close($st);
                if ($row && trim((string)($row['nama_bendahara'] ?? '')) !== '') {
                    return trim((string)$row['nama_bendahara']);
                }
            }
        }
        return tabungan_aktor_nama();
    }
}

if (!function_exists('tabungan_boleh_ditagihkan')) {
    /** Gate tahun ajaran aktif (sama seperti modul Pembayaran). */
    function tabungan_boleh_ditagihkan(string $tahun_ajaran): bool
    {
        $koneksi = tabungan_pembayaran_koneksi();
        if (!$koneksi || !function_exists('tahun_ajaran_boleh_ditagihkan')) {
            return true;
        }
        return (bool)tahun_ajaran_boleh_ditagihkan($koneksi, $tahun_ajaran);
    }
}
