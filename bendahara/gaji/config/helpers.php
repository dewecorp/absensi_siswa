<?php
// Security & Helper Functions
// Included from config/config.php
// All functions guarded with !function_exists() for compatibility across environments

if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
}

if (!function_exists('requireLogin')) {
    function requireLogin() {
        $ok = function_exists('isAuthorized') && isAuthorized(['bendahara', 'admin', 'kepala_madrasah']);
        if (!$ok) {
            $base = defined('SIMAD_BASE_URL') ? SIMAD_BASE_URL : '';
            header('Location: ' . $base . '/login.php');
            exit();
        }
    }
}

if (!function_exists('isAdmin')) {
    function isAdmin() {
        return function_exists('getUserLevel') && getUserLevel() === 'admin';
    }
}

if (!function_exists('requireAdmin')) {
    function requireAdmin() {
        requireLogin();
        if (!isAdmin()) {
            http_response_code(403);
            exit('Akses ditolak. Fitur ini hanya untuk administrator.');
        }
    }
}

if (!function_exists('csrfToken')) {
    function csrfToken($key = 'default') {
        if (empty($_SESSION['csrf_tokens']) || !is_array($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
        }
        if (empty($_SESSION['csrf_tokens'][$key])) {
            if (function_exists('random_bytes')) {
                $_SESSION['csrf_tokens'][$key] = bin2hex(random_bytes(32));
            } elseif (function_exists('openssl_random_pseudo_bytes')) {
                $_SESSION['csrf_tokens'][$key] = bin2hex(openssl_random_pseudo_bytes(32));
            } else {
                $_SESSION['csrf_tokens'][$key] = md5(uniqid(mt_rand(), true)) . md5(uniqid(mt_rand(), true));
            }
        }
        return $_SESSION['csrf_tokens'][$key];
    }
}

if (!function_exists('verifyCsrfToken')) {
    function verifyCsrfToken($token, $key = 'default') {
        return isset($_SESSION['csrf_tokens'][$key])
            && is_string($token)
            && hash_equals($_SESSION['csrf_tokens'][$key], $token);
    }
}

if (!function_exists('csrfField')) {
    function csrfField($key = 'default') {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken($key), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('gaji_log_jenis_aktivitas')) {
    /**
     * Ubah kalimat aktivitas CRUD jadi label pendek untuk tabel log_aktivitas.
     * Contoh: "Menambah tunjangan: Transport" -> "Tambah Tunjangan".
     */
    function gaji_log_jenis_aktivitas($activity, $type = 'info') {
        $lower = strtolower(trim((string) $activity));

        $verbs = [
            'menambah' => 'Tambah',
            'tambah' => 'Tambah',
            'mengubah' => 'Ubah',
            'memperbarui' => 'Ubah',
            'ubah' => 'Ubah',
            'menghapus' => 'Hapus',
            'hapus' => 'Hapus',
            'menyimpan' => 'Simpan',
            'simpan' => 'Simpan',
            'menggenerate' => 'Generate',
            'generate' => 'Generate',
            'menandatangani' => 'Tanda Tangan',
            'mencetak' => 'Cetak',
            'mengekspor' => 'Export',
        ];

        $jenis = 'Gaji';
        foreach ($verbs as $needle => $label) {
            if (strpos($lower, $needle) === 0) {
                $jenis = $label;
                break;
            }
        }

        $entities = [
            'legger honor' => 'Legger Honor',
            'gaji pokok' => 'Gaji Pokok',
            'tunjangan' => 'Tunjangan',
            'potongan' => 'Potongan',
            'honor' => 'Honor',
            'insentif' => 'Insentif',
            'pembina' => 'Pembina',
            'ekstrakurikuler' => 'Ekstrakurikuler',
            'legger' => 'Legger',
            'pengaturan' => 'Pengaturan',
        ];

        foreach ($entities as $needle => $label) {
            if (strpos($lower, $needle) !== false) {
                $jenis .= ' ' . $label;
                break;
            }
        }

        return mb_substr($jenis, 0, 50);
    }
}

if (!function_exists('gajiLogActivity')) {
    /**
     * Catat aktivitas CRUD gaji/honor ke tabel log_activities (tabel yang dibaca
     * Dashboard Bendahara), supaya semua perubahan gaji & honor terlihat di sana.
     */
    function gajiLogActivity($conn, $activity, $type = 'info') {
        $activity = trim((string) $activity);
        if ($activity === '' || !($conn instanceof mysqli)) {
            return false;
        }

        $jenis = gaji_log_jenis_aktivitas($activity, $type);

        // Samakan id_pengguna dengan tabel `pengguna` (dipakai Dashboard Bendahara)
        $id_pengguna = 0;
        $username = trim((string) ($_SESSION['username'] ?? ''));

        if ($username !== '') {
            $stmt = @$conn->prepare("SELECT id_pengguna FROM pengguna WHERE username = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result ? $result->fetch_assoc() : null;
                $id_pengguna = (int) ($row['id_pengguna'] ?? 0);
                $stmt->close();
            }
        }

        if ($id_pengguna <= 0) {
            $level = function_exists('getUserLevel') ? getUserLevel() : '';
            $role = $level === 'admin' ? 'admin' : 'petugas';
            $stmt = @$conn->prepare("SELECT id_pengguna FROM pengguna WHERE role = ? ORDER BY id_pengguna ASC LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $role);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result ? $result->fetch_assoc() : null;
                $id_pengguna = (int) ($row['id_pengguna'] ?? 0);
                $stmt->close();
            }
        }

        if ($id_pengguna <= 0) {
            $id_pengguna = 1;
        }

        $stmt = @$conn->prepare("INSERT INTO log_aktivitas (id_pengguna, jenis_aktivitas, deskripsi) VALUES (?, ?, ?)");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('iss', $id_pengguna, $jenis, $activity);
        $ok = $stmt->execute();
        $stmt->close();

        // Catat juga ke tb_activity_log SIMAD sebagai sumber tunggal
        try {
            global $pdo;
            if (!$pdo && file_exists(__DIR__ . '/../../../../config/database.php')) {
                require_once __DIR__ . '/../../../../config/database.php';
            }
            if ($pdo) {
                $u_act = $username !== '' ? $username : 'bendahara';
                $action_label = 'Gaji ' . ucfirst($jenis);
                $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $stmt_simad = $pdo->prepare("INSERT INTO tb_activity_log (username, action, description, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt_simad->execute([$u_act, $action_label, $activity, $ip]);
            }
        } catch (Throwable $e) {}

        return (bool) $ok;
    }
}

if (!function_exists('formatRupiah')) {
    function formatRupiah($angka) {
        $angka = floatval($angka);
        $formatted = number_format($angka, 0, ',', '.');
        return 'Rp ' . $formatted;
    }
}

if (!function_exists('formatRupiahTanpaRp')) {
    function formatRupiahTanpaRp($angka) {
        return number_format($angka, 0, ',', '.');
    }
}

if (!function_exists('gaji_ambil_gaji_pokok_modul')) {
    /**
     * Gaji pokok bulanan guru dari tabel gaji_pokok modul gaji.
     * Urutan: baris berjalan (periode kosong) -> baris periode target -> baris periode terakhir.
     * Baris riwayat per-periode tidak pernah ditimpa.
     */
    function gaji_ambil_gaji_pokok_modul($conn, int $guru_id, string $periode = ''): float
    {
        if (!($conn instanceof mysqli) || $guru_id <= 0) {
            return 0.0;
        }

        $coba = function (string $sql, array $params) use ($conn) {
            $stmt = @$conn->prepare($sql);
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param($params[0], ...array_slice($params, 1));
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            return $row;
        };

        $row = $coba("SELECT jumlah FROM gaji_pokok WHERE guru_id = ? AND (periode = '' OR periode IS NULL) ORDER BY id DESC LIMIT 1", ['i', $guru_id]);
        if ($row) {
            return (float)($row['jumlah'] ?? 0);
        }

        if ($periode !== '') {
            $row = $coba("SELECT jumlah FROM gaji_pokok WHERE guru_id = ? AND periode = ? ORDER BY id DESC LIMIT 1", ['is', $guru_id, $periode]);
            if ($row) {
                return (float)($row['jumlah'] ?? 0);
            }
        }

        $row = $coba("SELECT jumlah FROM gaji_pokok WHERE guru_id = ? ORDER BY periode DESC, id DESC LIMIT 1", ['i', $guru_id]);
        if ($row) {
            return (float)($row['jumlah'] ?? 0);
        }

        return 0.0;
    }
}

if (!function_exists('gajiLogoPrintSrc')) {
    /**
     * Sumber gambar logo untuk halaman cetak (slip/legger gaji, honor, insentif).
     *
     * Memakai URL file, bukan base64:
     * - base64 bikin HTML jadi ratusan KB dan fragile (gambar bisa terpotong
     *   bila response HTTP rusak/terpotong),
     * - URL file dijamin utuh oleh web server & lebih ringan.
     * Base64 hanya dipakai sebagai cadangan bila file tidak bisa diakses.
     */
    function gajiLogoPrintSrc(array $settings): string
    {
        $url = trim((string)($settings['logo_url'] ?? ''));
        if ($url !== '') {
            return $url;
        }

        $file = trim((string)($settings['logo_file'] ?? ''));
        if ($file !== '' && is_file($file) && is_readable($file)) {
            $mime = function_exists('mime_content_type') ? (string)mime_content_type($file) : '';
            $mime = $mime !== '' ? $mime : 'image/png';
            $data = @file_get_contents($file);
            if ($data !== false && $data !== '') {
                return 'data:' . $mime . ';base64,' . base64_encode($data);
            }
        }

        return '';
    }
}

if (!function_exists('gajiLogoFile')) {
    /**
     * Path absolut file logo madrasah.
     * Logo resmi (Pengaturan Admin) ada di assets/img root,
     * fallback ke folder logo modul (bendahara/gaji/assets/img).
     */
    function gajiLogoFile($logo) {
        $logo = trim((string)$logo);
        if ($logo === '') {
            return '';
        }
        if (is_file(__DIR__ . '/../../../assets/img/' . $logo)) {
            return __DIR__ . '/../../../assets/img/' . $logo;
        }
        if (is_file(__DIR__ . '/../assets/img/' . $logo)) {
            return __DIR__ . '/../assets/img/' . $logo;
        }
        return '';
    }
}

if (!function_exists('gajiLogoUrl')) {
    /**
     * URL logo madrasah.
     * Logo resmi disimpan di assets/img (Pengaturan Admin SIMAD).
     * Fallback: folder logo modul (bendahara/gaji/assets/img).
     */
    function gajiLogoUrl($logo) {
        $logo = trim((string)$logo);
        if ($logo === '') {
            return '';
        }

        $base = function_exists('app_base_path') ? app_base_path() : (defined('SIMAD_BASE_URL') ? SIMAD_BASE_URL : '');

        // Logo dari Pengaturan Admin (root assets/img)
        if (is_file(__DIR__ . '/../../../assets/img/' . $logo)) {
            return $base . '/assets/img/' . $logo;
        }

        // Logo bawaan modul gaji
        if (is_file(__DIR__ . '/../assets/img/' . $logo)) {
            $moduleBase = defined('BASE_URL') ? BASE_URL : $base . '/bendahara/gaji/';
            return $moduleBase . 'assets/img/' . $logo;
        }

        return $base . '/assets/img/' . $logo;
    }
}

if (!function_exists('gajiProfilMadrasah')) {
    /**
     * Identitas madrasah & pejabat yang dipakai slip/legger gaji, honor, dan insentif.
     * Sumber utama: Pengaturan Admin SIMAD (tb_profil_madrasah).
     * Fallback: tabel settings modul gaji.
     *
     * @return array{nama_madrasah:string,nama_kepala:string,nama_bendahara:string,logo:string,logo_url:string}
     */
    function gajiProfilMadrasah($conn = null) {
        $profil = [
            'nama_madrasah' => '',
            'nama_kepala' => '',
            'nama_bendahara' => '',
            'logo' => '',
            'logo_file' => '',
            'logo_url' => '',
        ];

        if (!($conn instanceof mysqli)) {
            return $profil;
        }

        // Fallback: pengaturan modul gaji
        $res = @$conn->query("SELECT nama_madrasah, nama_kepala, nama_bendahara, logo FROM settings WHERE id = 1 LIMIT 1");
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc() ?: [];
            $profil['nama_madrasah'] = trim((string)($row['nama_madrasah'] ?? ''));
            $profil['nama_kepala'] = trim((string)($row['nama_kepala'] ?? ''));
            $profil['nama_bendahara'] = trim((string)($row['nama_bendahara'] ?? ''));
            $profil['logo'] = trim((string)($row['logo'] ?? ''));
        }

        // Sumber utama: Pengaturan Admin SIMAD
        $res = @$conn->query("SELECT nama_madrasah, kepala_madrasah, id_bendahara, logo FROM tb_profil_madrasah WHERE id = 1 LIMIT 1");
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc() ?: [];

            $nama = trim((string)($row['nama_madrasah'] ?? ''));
            if ($nama !== '') {
                $profil['nama_madrasah'] = $nama;
            }

            $kepala = trim((string)($row['kepala_madrasah'] ?? ''));
            if ($kepala !== '') {
                $profil['nama_kepala'] = $kepala;
            }

            $logo = trim((string)($row['logo'] ?? ''));
            if ($logo !== '') {
                $profil['logo'] = $logo;
            }

            $id_bendahara = (int)($row['id_bendahara'] ?? 0);
            if ($id_bendahara > 0) {
                $stmt = @$conn->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $id_bendahara);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $guru = $result ? $result->fetch_assoc() : null;
                    if ($guru && trim((string)($guru['nama_guru'] ?? '')) !== '') {
                        $profil['nama_bendahara'] = trim((string)$guru['nama_guru']);
                    }
                    $stmt->close();
                }
            }
        }

        $profil['logo_file'] = gajiLogoFile($profil['logo']);
        $profil['logo_url'] = gajiLogoUrl($profil['logo']);

        return $profil;
    }
}

if (!function_exists('timeAgo')) {
    function timeAgo($datetime) {
        $timestamp = strtotime($datetime);
        $current = time();
        $diff = $current - $timestamp;
        if ($diff < 60) {
            return 'Baru saja';
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            return $minutes . ' menit yang lalu';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' jam yang lalu';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' hari yang lalu';
        } else {
            $dt = new DateTime($datetime);
            return $dt->format('d/m/Y H:i');
        }
    }
}

if (!function_exists('getCurrentPeriod')) {
    function getCurrentPeriod() {
        return date('Y-m');
    }
}

if (!function_exists('getPeriodLabel')) {
    function getPeriodLabel($period) {
        $months = [
            '01' => 'Januari', '02' => 'Februari', '03' => 'Maret',
            '04' => 'April', '05' => 'Mei', '06' => 'Juni',
            '07' => 'Juli', '08' => 'Agustus', '09' => 'September',
            '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
        ];
        $parts = explode('-', $period);
        return $months[$parts[1]] . ' ' . $parts[0];
    }
}

if (!function_exists('getPeriodRangeLabel')) {
    function getPeriodRangeLabel($periode_mulai, $periode_akhir) {
        $months = [
            '01' => 'Januari', '02' => 'Februari', '03' => 'Maret',
            '04' => 'April', '05' => 'Mei', '06' => 'Juni',
            '07' => 'Juli', '08' => 'Agustus', '09' => 'September',
            '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
        ];
        if (empty($periode_mulai) || empty($periode_akhir) || $periode_mulai == $periode_akhir) {
            return getPeriodLabel($periode_mulai ?: $periode_akhir);
        }
        $parts_mulai = explode('-', $periode_mulai);
        $parts_akhir = explode('-', $periode_akhir);
        return $months[$parts_mulai[1]] . ' ' . $parts_mulai[0] . ' - ' . $months[$parts_akhir[1]] . ' ' . $parts_akhir[0];
    }
}

if (!function_exists('formatTanggalIndonesia')) {
    function formatTanggalIndonesia($date) {
        if (empty($date) || $date == '0000-00-00') {
            return '';
        }
        $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $timestamp = strtotime($date);
        $hari_nama = $hari[date('w', $timestamp)];
        $tanggal = date('j', $timestamp);
        $bulan_nama = $bulan[date('n', $timestamp) - 1];
        $tahun = date('Y', $timestamp);
        return $hari_nama . ', ' . $tanggal . ' ' . $bulan_nama . ' ' . $tahun;
    }
}

if (!function_exists('formatTanggalTanpaHari')) {
    function formatTanggalTanpaHari($date) {
        if ($date === null || $date === '' || $date === '0000-00-00') {
            return '';
        }
        $s = trim((string)$date);
        if ($s === '') {
            return '';
        }
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})(?:[\s T].*)?$/', $s, $m)) {
            return '';
        }
        $dt = DateTime::createFromFormat('Y-m-d', $m[1], new DateTimeZone('Asia/Jakarta'));
        if ($dt === false || $dt->format('Y-m-d') !== $m[1]) {
            return '';
        }
        $bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        return (string)(int)$dt->format('j') . ' ' . $bulan[(int)$dt->format('n') - 1] . ' ' . $dt->format('Y');
    }
}

// Session timeout ditangani oleh SIMAD (isAuthorized), tidak perlu di sini.
