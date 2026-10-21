<?php

/**
 * Cek apakah jenis bayar berlaku untuk siswa.
 * Di form admin, tagihan_kelas berisi nama_kelas (mis. "6"); tetap dukung id_kelas string untuk data lama.
 */
function jenis_bayar_berlaku_untuk_kelas($tagihan_kelas_raw, $id_kelas_siswa, $nama_kelas_siswa) {
    $tagihan_kelas_raw = trim((string) $tagihan_kelas_raw);
    if ($tagihan_kelas_raw === '') {
        return true;
    }
    $allowed = array_map('trim', explode(',', $tagihan_kelas_raw));
    $nama = trim((string) $nama_kelas_siswa);
    $id = trim((string) $id_kelas_siswa);
    return in_array($nama, $allowed) || in_array($id, $allowed);
}

function kelas_adalah_alumni($nama_kelas) {
    return stripos(trim((string) $nama_kelas), 'alumni') !== false;
}

/**
 * Cek apakah alumni masih memiliki tagihan/tunggakan yang belum dibayar.
 * Alumni tetap ditampilkan (dan tidak dihapus) sampai tagihannya dibayarkan.
 */
function alumni_masih_punya_tagihan($koneksi, $nisn) {
    $nisn = trim((string) $nisn);
    if ($nisn === '') {
        return false;
    }

    if (cek_tunggakan_tahun_ajaran_lama($koneksi, $nisn)) {
        return true;
    }

    $berjalan = trim((string) tahun_ajaran_berjalan($koneksi));
    if ($berjalan === '') {
        return false;
    }

    // Alumni tidak ditagih untuk tahun ajaran baru; acuan = tahun terakhir mereka.
    $aktif = trim((string) get_tahun_ajaran_aktif($koneksi));
    $tahun_alumni = ($berjalan === $aktif) ? tahun_ajaran_sebelumnya($berjalan) : $berjalan;
    if (trim((string) $tahun_alumni) === '') {
        return false;
    }

    return (bool) cek_tagihan_tunggakan($koneksi, $nisn, $tahun_alumni);
}

function hapus_siswa_alumni_jika_lunas($koneksi, $nisn, $tahun_ajaran_aktif = null) {
    $nisn = trim((string) $nisn);
    if ($nisn === '') {
        return false;
    }

    $nisn_esc = mysqli_real_escape_string($koneksi, $nisn);
    $q_siswa = mysqli_query(
        $koneksi,
        "SELECT s.nisn, s.nama, k.nama_kelas
         FROM siswa s
         JOIN kelas k ON s.id_kelas = k.id_kelas
         WHERE s.nisn = '$nisn_esc'
         LIMIT 1"
    );
    $siswa = $q_siswa ? mysqli_fetch_assoc($q_siswa) : null;
    if (!$siswa || !kelas_adalah_alumni($siswa['nama_kelas'] ?? '')) {
        return false;
    }

    $tahun_ajaran_aktif = trim((string) ($tahun_ajaran_aktif ?? get_tahun_ajaran_aktif($koneksi)));
    if (alumni_masih_punya_tagihan($koneksi, $nisn)) {
        return false;
    }

    $deleted = mysqli_query($koneksi, "DELETE FROM siswa WHERE nisn = '$nisn_esc' LIMIT 1");
    if ($deleted && function_exists('pembayaran_log_activity')) {
        pembayaran_log_activity($koneksi, 'Delete', 'Menghapus data alumni lunas NISN: ' . $nisn);
    }

    return (bool) $deleted;
}

function daftar_siswa_alumni_lunas($koneksi, $tahun_ajaran_aktif = null, $limit = 200) {
    $tahun_ajaran_aktif = trim((string) ($tahun_ajaran_aktif ?? get_tahun_ajaran_aktif($koneksi)));
    $limit = max(1, (int) $limit);
    $q_alumni = mysqli_query(
        $koneksi,
        "SELECT s.nisn, s.nama
         FROM siswa s
         JOIN kelas k ON s.id_kelas = k.id_kelas
         WHERE LOWER(k.nama_kelas) LIKE '%alumni%'
         ORDER BY s.nama ASC
         LIMIT $limit"
    );
    if (!$q_alumni) {
        return [];
    }

    $alumni_lunas = [];
    while ($row = mysqli_fetch_assoc($q_alumni)) {
        $nisn = trim((string) ($row['nisn'] ?? ''));
        if ($nisn !== '' && !alumni_masih_punya_tagihan($koneksi, $nisn)) {
            $alumni_lunas[] = $row;
        }
    }

    return $alumni_lunas;
}

function hapus_semua_siswa_alumni_lunas($koneksi, $tahun_ajaran_aktif = null, $limit = 200) {
    $tahun_ajaran_aktif = trim((string) ($tahun_ajaran_aktif ?? get_tahun_ajaran_aktif($koneksi)));
    $limit = max(1, (int) $limit);
    $q_alumni = mysqli_query(
        $koneksi,
        "SELECT s.nisn
         FROM siswa s
         JOIN kelas k ON s.id_kelas = k.id_kelas
         WHERE LOWER(k.nama_kelas) LIKE '%alumni%'
         ORDER BY s.nama ASC
         LIMIT $limit"
    );
    if (!$q_alumni) {
        return 0;
    }

    $deleted = 0;
    while ($row = mysqli_fetch_assoc($q_alumni)) {
        if (hapus_siswa_alumni_jika_lunas($koneksi, $row['nisn'] ?? '', $tahun_ajaran_aktif)) {
            $deleted++;
        }
    }

    return $deleted;
}

/**
 * Map bulan akademik (nama: Juli..Juni) => pembayaran pertama yang melunasi bulan itu.
 * Satu baris pembayaran bisa berisi banyak bulan di kolom bulan_bayar (dipisah koma), seperti di transaksi.
 *
 * @return array<string, array<string, mixed>>
 */
function bulanan_map_pembayaran_per_bulan($koneksi, $nisn, $id_jenis_bayar, $tahun_ajaran = null) {
    $nisn_esc = mysqli_real_escape_string($koneksi, (string) $nisn);
    $id_esc = mysqli_real_escape_string($koneksi, (string) $id_jenis_bayar);
    $tahun_filter = '';
    if ($tahun_ajaran !== null && trim((string) $tahun_ajaran) !== '') {
        ensure_pembayaran_tahun_ajaran_column($koneksi);
        $tahun_esc = mysqli_real_escape_string($koneksi, (string) $tahun_ajaran);
        $tahun_filter = " AND tahun_ajaran = '$tahun_esc'";
    }
    $q = mysqli_query(
        $koneksi,
        "SELECT bulan_bayar, tgl_bayar, jumlah_bayar FROM pembayaran
         WHERE nisn = '$nisn_esc' AND id_jenis_bayar = '$id_esc'$tahun_filter
         ORDER BY tgl_bayar ASC, id_pembayaran ASC"
    );
    $map = [];
    if (!$q) {
        return $map;
    }
    while ($row = mysqli_fetch_assoc($q)) {
        $raw = trim((string) ($row['bulan_bayar'] ?? ''));
        if ($raw === '') {
            continue;
        }
        $parts = array_map('trim', explode(',', $raw));
        $parts = array_values(array_filter($parts, static function ($x) {
            return $x !== '';
        }));
        $n = count($parts);
        if ($n < 1) {
            continue;
        }
        $amt = (int) ($row['jumlah_bayar'] ?? 0);
        $per = (int) round($amt / $n);
        foreach ($parts as $pm) {
            if (!isset($map[$pm])) {
                $map[$pm] = [
                    'tgl_bayar' => $row['tgl_bayar'],
                    'jumlah' => $per,
                ];
            }
        }
    }
    return $map;
}

function get_tahun_ajaran_aktif($koneksi) {
    $q = mysqli_query($koneksi, "SELECT tahun_ajaran FROM pengaturan WHERE id_pengaturan = 1 LIMIT 1");
    if ($q && $row = mysqli_fetch_assoc($q)) {
        $tahun_ajaran = trim((string) ($row['tahun_ajaran'] ?? ''));
        if ($tahun_ajaran !== '') {
            return $tahun_ajaran;
        }
    }

    $year = (int) date('Y');
    $month = (int) date('n');
    return $month >= 7 ? $year . '/' . ($year + 1) : ($year - 1) . '/' . $year;
}

function default_tanggal_mulai_tahun_ajaran($tahun_ajaran) {
    if (preg_match('/^(\d{4})\s*\/\s*(\d{4})$/', trim((string) $tahun_ajaran), $m)) {
        return $m[1] . '-07-01';
    }

    return date('Y') . '-07-01';
}

function ensure_pengaturan_tanggal_mulai_column($koneksi) {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $col = mysqli_query($koneksi, "SHOW COLUMNS FROM pengaturan LIKE 'tanggal_mulai_tahun_ajaran'");
    if ($col && mysqli_num_rows($col) > 0) {
        $q = mysqli_query($koneksi, "SELECT tahun_ajaran, tanggal_mulai_tahun_ajaran FROM pengaturan WHERE id_pengaturan = 1 LIMIT 1");
        if ($q && $row = mysqli_fetch_assoc($q)) {
            if (trim((string)($row['tanggal_mulai_tahun_ajaran'] ?? '')) === '') {
                $default = mysqli_real_escape_string($koneksi, default_tanggal_mulai_tahun_ajaran($row['tahun_ajaran'] ?? ''));
                mysqli_query($koneksi, "UPDATE pengaturan SET tanggal_mulai_tahun_ajaran = '$default' WHERE id_pengaturan = 1");
            }
        }
        return;
    }

    mysqli_query($koneksi, "ALTER TABLE pengaturan ADD tanggal_mulai_tahun_ajaran DATE NULL AFTER tahun_ajaran");
    $q = mysqli_query($koneksi, "SELECT tahun_ajaran FROM pengaturan WHERE id_pengaturan = 1 LIMIT 1");
    $row = $q ? mysqli_fetch_assoc($q) : null;
    $default = mysqli_real_escape_string($koneksi, default_tanggal_mulai_tahun_ajaran($row['tahun_ajaran'] ?? ''));
    mysqli_query($koneksi, "UPDATE pengaturan SET tanggal_mulai_tahun_ajaran = '$default' WHERE id_pengaturan = 1");
}

function get_tanggal_mulai_tahun_ajaran_aktif($koneksi) {
    ensure_pengaturan_tanggal_mulai_column($koneksi);
    $q = mysqli_query($koneksi, "SELECT tahun_ajaran, tanggal_mulai_tahun_ajaran FROM pengaturan WHERE id_pengaturan = 1 LIMIT 1");
    if ($q && $row = mysqli_fetch_assoc($q)) {
        $tanggal = trim((string)($row['tanggal_mulai_tahun_ajaran'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            return $tanggal;
        }
        return default_tanggal_mulai_tahun_ajaran($row['tahun_ajaran'] ?? get_tahun_ajaran_aktif($koneksi));
    }

    return default_tanggal_mulai_tahun_ajaran(get_tahun_ajaran_aktif($koneksi));
}

function tahun_ajaran_aktif_sudah_mulai($koneksi) {
    $mulai = DateTime::createFromFormat('Y-m-d', get_tanggal_mulai_tahun_ajaran_aktif($koneksi));
    if (!$mulai) {
        return true;
    }

    $today = new DateTime(date('Y-m-d'));
    return $today >= $mulai;
}

/**
 * Tahun ajaran yang sedang berjalan (acuan tagihan aktif).
 * - Sebelum Tanggal Mulai Tahun Ajaran aktif → tahun ajaran sebelumnya masih berjalan.
 * - Pada/sesudah Tanggal Mulai Tahun Ajaran aktif → tahun ajaran aktif berjalan,
 *   dan tahun ajaran sebelumnya berubah menjadi tunggakan.
 */
function tahun_ajaran_berjalan($koneksi) {
    $aktif = get_tahun_ajaran_aktif($koneksi);
    if (tahun_ajaran_aktif_sudah_mulai($koneksi)) {
        return $aktif;
    }

    $sebelumnya = tahun_ajaran_sebelumnya($aktif);
    return $sebelumnya !== '' ? $sebelumnya : $aktif;
}

function tahun_ajaran_boleh_ditagihkan($koneksi, $tahun_ajaran) {
    $tahun_ajaran = trim((string) $tahun_ajaran);
    if ($tahun_ajaran !== get_tahun_ajaran_aktif($koneksi)) {
        return true;
    }

    return tahun_ajaran_aktif_sudah_mulai($koneksi);
}

function limit_index_bulan_tahun_ajaran($koneksi, $tahun_ajaran) {
    if ($tahun_ajaran !== get_tahun_ajaran_aktif($koneksi)) {
        return 11;
    }

    $mulai = DateTime::createFromFormat('Y-m-d', get_tanggal_mulai_tahun_ajaran_aktif($koneksi));
    if (!$mulai) {
        return -1;
    }

    $today = new DateTime(date('Y-m-d'));
    if ($today < $mulai) {
        return -1;
    }

    $diff = $mulai->diff($today);
    $index = ($diff->y * 12) + $diff->m;
    return max(0, min(11, $index));
}

/**
 * Daftar bulan yang ditampilkan pada cetak tagihan.
 * Semua bulan yang sudah berjalan (awal tahun ajaran s/d bulan sekarang).
 * Tahun ajaran lampau (tunggakan): semua bulan.
 */
function daftar_bulan_tagihan_tampil($koneksi, $tahun_ajaran) {
    $months = bulan_akademik_list();
    $limit_index = limit_index_bulan_tahun_ajaran($koneksi, $tahun_ajaran);

    if ($limit_index < 0) {
        return [];
    }

    // Tampilkan semua bulan yang sudah berjalan
    return array_slice($months, 0, $limit_index + 1);
}

function tahun_ajaran_sebelumnya($tahun_ajaran) {
    if (preg_match('/^(\d{4})\s*\/\s*(\d{4})$/', trim((string) $tahun_ajaran), $m)) {
        return ((int) $m[1] - 1) . '/' . ((int) $m[2] - 1);
    }

    return '';
}

function tahun_ajaran_awal($tahun_ajaran) {
    if (preg_match('/^(\d{4})\s*\/\s*(\d{4})$/', trim((string) $tahun_ajaran), $m)) {
        return (int) $m[1];
    }

    return null;
}

function bulan_akademik_list() {
    return ['Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni'];
}

function normalisasi_bulan_bayar_list($raw) {
    $months = bulan_akademik_list();
    $allowed = array_flip($months);
    $items = is_array($raw) ? $raw : explode(',', (string) $raw);
    $result = [];

    foreach ($items as $item) {
        $bulan = trim((string) $item);
        if ($bulan !== '' && isset($allowed[$bulan])) {
            $result[$bulan] = true;
        }
    }

    return array_values(array_filter($months, static function ($bulan) use ($result) {
        return isset($result[$bulan]);
    }));
}

function ensure_pembayaran_arsip_table($koneksi) {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    mysqli_query(
        $koneksi,
        "CREATE TABLE IF NOT EXISTS pembayaran_arsip (
            id_arsip INT AUTO_INCREMENT PRIMARY KEY,
            nisn VARCHAR(50) NOT NULL,
            id_jenis_bayar INT NOT NULL,
            tahun_ajaran VARCHAR(20) NOT NULL,
            bulan_bayar TEXT NULL,
            jumlah_bayar INT NOT NULL DEFAULT 0,
            cicilan_ke INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_pembayaran_arsip (nisn, id_jenis_bayar, tahun_ajaran),
            KEY idx_tahun_ajaran (tahun_ajaran)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function daftar_tahun_ajaran_pembayaran_tersimpan($koneksi, $nisn) {
    ensure_pembayaran_tahun_ajaran_column($koneksi);
    ensure_pembayaran_arsip_table($koneksi);

    $nisn_esc = mysqli_real_escape_string($koneksi, (string) $nisn);
    $tahun_list = [];
    $queries = [
        "SELECT DISTINCT tahun_ajaran FROM pembayaran WHERE nisn = '$nisn_esc' AND tahun_ajaran IS NOT NULL AND tahun_ajaran <> ''",
        "SELECT DISTINCT tahun_ajaran FROM pembayaran_arsip WHERE nisn = '$nisn_esc' AND tahun_ajaran IS NOT NULL AND tahun_ajaran <> ''",
    ];

    foreach ($queries as $sql) {
        $q = mysqli_query($koneksi, $sql);
        if (!$q) {
            continue;
        }
        while ($row = mysqli_fetch_assoc($q)) {
            $tahun = trim((string) ($row['tahun_ajaran'] ?? ''));
            if ($tahun !== '') {
                $tahun_list[] = $tahun;
            }
        }
    }

    return array_values(array_unique($tahun_list));
}

function ambil_bulan_bayar_tersimpan($koneksi, $nisn, $id_jenis_bayar, $tahun_ajaran) {
    ensure_pembayaran_tahun_ajaran_column($koneksi);
    ensure_pembayaran_arsip_table($koneksi);

    $nisn_esc = mysqli_real_escape_string($koneksi, (string) $nisn);
    $id_esc = mysqli_real_escape_string($koneksi, (string) $id_jenis_bayar);
    $tahun_esc = mysqli_real_escape_string($koneksi, (string) $tahun_ajaran);
    $paid_months = [];
    $queries = [
        "SELECT bulan_bayar FROM pembayaran WHERE nisn='$nisn_esc' AND id_jenis_bayar='$id_esc' AND tahun_ajaran='$tahun_esc'",
        "SELECT bulan_bayar FROM pembayaran_arsip WHERE nisn='$nisn_esc' AND id_jenis_bayar='$id_esc' AND tahun_ajaran='$tahun_esc'",
    ];

    foreach ($queries as $sql) {
        $q = mysqli_query($koneksi, $sql);
        if (!$q) {
            continue;
        }
        while ($row = mysqli_fetch_assoc($q)) {
            $paid_months = array_merge($paid_months, normalisasi_bulan_bayar_list($row['bulan_bayar'] ?? ''));
        }
    }

    return array_values(array_unique($paid_months));
}

function ambil_total_bayar_tersimpan($koneksi, $nisn, $id_jenis_bayar, $tahun_ajaran) {
    ensure_pembayaran_tahun_ajaran_column($koneksi);
    ensure_pembayaran_arsip_table($koneksi);

    $nisn_esc = mysqli_real_escape_string($koneksi, (string) $nisn);
    $id_esc = mysqli_real_escape_string($koneksi, (string) $id_jenis_bayar);
    $tahun_esc = mysqli_real_escape_string($koneksi, (string) $tahun_ajaran);
    $total = 0;
    $queries = [
        "SELECT COALESCE(SUM(jumlah_bayar), 0) AS total FROM pembayaran WHERE nisn='$nisn_esc' AND id_jenis_bayar='$id_esc' AND tahun_ajaran='$tahun_esc'",
        "SELECT COALESCE(SUM(jumlah_bayar), 0) AS total FROM pembayaran_arsip WHERE nisn='$nisn_esc' AND id_jenis_bayar='$id_esc' AND tahun_ajaran='$tahun_esc'",
    ];

    foreach ($queries as $sql) {
        $q = mysqli_query($koneksi, $sql);
        if ($q && $row = mysqli_fetch_assoc($q)) {
            $total += (int) ($row['total'] ?? 0);
        }
    }

    return $total;
}

function arsipkan_dan_hapus_transaksi_tahun_ajaran_lama($koneksi, $tahun_ajaran_aktif) {
    ensure_pembayaran_tahun_ajaran_column($koneksi);
    ensure_pembayaran_arsip_table($koneksi);

    $tahun_aktif_awal = tahun_ajaran_awal($tahun_ajaran_aktif);
    if ($tahun_aktif_awal === null) {
        return ['ok' => false, 'message' => 'Format tahun ajaran aktif tidak valid.'];
    }

    $q_tahun = mysqli_query($koneksi, "SELECT DISTINCT tahun_ajaran FROM pembayaran WHERE tahun_ajaran IS NOT NULL AND tahun_ajaran <> ''");
    if (!$q_tahun) {
        return ['ok' => false, 'message' => mysqli_error($koneksi)];
    }

    $tahun_lama = [];
    while ($row = mysqli_fetch_assoc($q_tahun)) {
        $tahun = trim((string) ($row['tahun_ajaran'] ?? ''));
        $awal = tahun_ajaran_awal($tahun);
        if ($tahun !== '' && ($awal === null || $awal < $tahun_aktif_awal)) {
            $tahun_lama[] = $tahun;
        }
    }
    $tahun_lama = array_values(array_unique($tahun_lama));

    if (empty($tahun_lama)) {
        return ['ok' => true, 'archived' => 0, 'deleted' => 0, 'years' => []];
    }

    $archived = 0;
    $deleted = 0;
    mysqli_begin_transaction($koneksi);
    try {
        foreach ($tahun_lama as $tahun) {
            $tahun_esc = mysqli_real_escape_string($koneksi, $tahun);
            $q_group = mysqli_query(
                $koneksi,
                "SELECT nisn, id_jenis_bayar, tahun_ajaran, GROUP_CONCAT(bulan_bayar SEPARATOR ',') AS bulan_bayar, SUM(jumlah_bayar) AS jumlah_bayar, MAX(cicilan_ke) AS cicilan_ke
                 FROM pembayaran
                 WHERE tahun_ajaran = '$tahun_esc'
                 GROUP BY nisn, id_jenis_bayar, tahun_ajaran"
            );
            if (!$q_group) {
                throw new Exception(mysqli_error($koneksi));
            }

            while ($row = mysqli_fetch_assoc($q_group)) {
                $nisn = (string) $row['nisn'];
                $id_jenis = (int) $row['id_jenis_bayar'];
                $bulan_bayar = implode(', ', normalisasi_bulan_bayar_list($row['bulan_bayar'] ?? ''));
                $jumlah_bayar = (int) ($row['jumlah_bayar'] ?? 0);
                $cicilan_ke = (int) ($row['cicilan_ke'] ?? 0);

                $existing_months = ambil_bulan_bayar_tersimpan($koneksi, $nisn, $id_jenis, $tahun);
                $merged_months = implode(', ', normalisasi_bulan_bayar_list(array_merge($existing_months, normalisasi_bulan_bayar_list($bulan_bayar))));

                $nisn_esc = mysqli_real_escape_string($koneksi, $nisn);
                $bulan_esc = mysqli_real_escape_string($koneksi, $merged_months);
                mysqli_query(
                    $koneksi,
                    "INSERT INTO pembayaran_arsip (nisn, id_jenis_bayar, tahun_ajaran, bulan_bayar, jumlah_bayar, cicilan_ke)
                     VALUES ('$nisn_esc', '$id_jenis', '$tahun_esc', '$bulan_esc', '$jumlah_bayar', '$cicilan_ke')
                     ON DUPLICATE KEY UPDATE
                        bulan_bayar = VALUES(bulan_bayar),
                        jumlah_bayar = jumlah_bayar + VALUES(jumlah_bayar),
                        cicilan_ke = GREATEST(cicilan_ke, VALUES(cicilan_ke))"
                );
                if (mysqli_error($koneksi)) {
                    throw new Exception(mysqli_error($koneksi));
                }
                $archived++;
            }

            $q_count = mysqli_query($koneksi, "SELECT COUNT(*) AS total FROM pembayaran WHERE tahun_ajaran = '$tahun_esc'");
            $row_count = $q_count ? mysqli_fetch_assoc($q_count) : ['total' => 0];
            $deleted += (int) ($row_count['total'] ?? 0);

            if (!mysqli_query($koneksi, "DELETE FROM pembayaran WHERE tahun_ajaran = '$tahun_esc'")) {
                throw new Exception(mysqli_error($koneksi));
            }
        }

        mysqli_commit($koneksi);
        return ['ok' => true, 'archived' => $archived, 'deleted' => $deleted, 'years' => $tahun_lama];
    } catch (Exception $e) {
        mysqli_rollback($koneksi);
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

function daftar_tahun_ajaran_lama_untuk_tunggakan($koneksi, $nisn, $tahun_ajaran_aktif = null) {
    ensure_pembayaran_tahun_ajaran_column($koneksi);

    // Acuan tunggakan = tahun ajaran yang sedang berjalan.
    // Sebelum Tanggal Mulai Tahun Ajaran, tahun sebelumnya masih berjalan (belum menjadi tunggakan).
    $acuan = tahun_ajaran_berjalan($koneksi);
    if (trim((string) $acuan) === '') {
        $acuan = trim((string) ($tahun_ajaran_aktif ?? get_tahun_ajaran_aktif($koneksi)));
    }
    $tahun_aktif_awal = tahun_ajaran_awal($acuan);
    $tahun_ajaran_opsi = [];

    $tahun_sebelumnya = tahun_ajaran_sebelumnya($acuan);
    if ($tahun_sebelumnya !== '') {
        $tahun_ajaran_opsi[] = $tahun_sebelumnya;
    }

    foreach (daftar_tahun_ajaran_pembayaran_tersimpan($koneksi, $nisn) as $tahun) {
        $tahun_ajaran_opsi[] = $tahun;
    }

    $tahun_ajaran_opsi = array_values(array_unique(array_filter($tahun_ajaran_opsi)));
    usort($tahun_ajaran_opsi, static function ($a, $b) {
        return (tahun_ajaran_awal($b) ?? 0) <=> (tahun_ajaran_awal($a) ?? 0);
    });

    $tahun_ajaran_lama = [];
    foreach ($tahun_ajaran_opsi as $tahun) {
        $awal = tahun_ajaran_awal($tahun);
        if ($awal === null || $tahun_aktif_awal === null || $awal < $tahun_aktif_awal) {
            $tahun_ajaran_lama[] = $tahun;
        }
    }

    return array_values(array_unique($tahun_ajaran_lama));
}

function cek_tunggakan_tahun_ajaran_lama($koneksi, $nisn, $tahun_ajaran_aktif = null) {
    $tahun_ajaran_aktif = trim((string) ($tahun_ajaran_aktif ?? get_tahun_ajaran_aktif($koneksi)));
    $hasil = [];

    // Siswa kelas 1 tidak punya tunggakan tahun lampau
    $q_k = mysqli_query($koneksi, "SELECT k.nama_kelas FROM siswa s JOIN kelas k ON s.id_kelas = k.id_kelas WHERE s.nisn = '" . mysqli_real_escape_string($koneksi, $nisn) . "' LIMIT 1");
    if ($q_k && ($r_k = mysqli_fetch_assoc($q_k))) {
        $nm = trim($r_k['nama_kelas']);
        if ($nm === '1' || $nm === 'I') return false;
    }

    foreach (daftar_tahun_ajaran_lama_untuk_tunggakan($koneksi, $nisn, $tahun_ajaran_aktif) as $tahun_ajaran_lama) {
        $tagihan = cek_tagihan_tunggakan($koneksi, $nisn, $tahun_ajaran_lama);
        if ($tagihan) {
            $hasil[$tahun_ajaran_lama] = $tagihan;
        }
    }

    return !empty($hasil) ? $hasil : false;
}

function isi_tahun_ajaran_pembayaran_kosong($koneksi) {
    mysqli_query(
        $koneksi,
        "UPDATE pembayaran
         SET tahun_ajaran = CASE
             WHEN MONTH(tgl_bayar) >= 7 THEN CONCAT(YEAR(tgl_bayar), '/', YEAR(tgl_bayar) + 1)
             ELSE CONCAT(YEAR(tgl_bayar) - 1, '/', YEAR(tgl_bayar))
         END
         WHERE tahun_ajaran IS NULL OR tahun_ajaran = ''"
    );
}

function normalisasi_tahun_ajaran_tagihan_tunggakan($koneksi) {
    mysqli_query(
        $koneksi,
        "UPDATE pembayaran
         SET tahun_ajaran = CASE
             WHEN MONTH(tgl_bayar) >= 7 THEN CONCAT(YEAR(tgl_bayar) - 1, '/', YEAR(tgl_bayar))
             ELSE CONCAT(YEAR(tgl_bayar) - 1, '/', YEAR(tgl_bayar))
         END
         WHERE (ket = 'Pembayaran Tagihan Tunggakan' OR no_transaksi LIKE 'TRX-TAG-%')
           AND (tahun_ajaran IS NULL OR tahun_ajaran = '' OR tahun_ajaran = CASE
               WHEN MONTH(tgl_bayar) >= 7 THEN CONCAT(YEAR(tgl_bayar), '/', YEAR(tgl_bayar) + 1)
               ELSE CONCAT(YEAR(tgl_bayar) - 1, '/', YEAR(tgl_bayar))
           END)"
    );
}

function ensure_pembayaran_tahun_ajaran_column($koneksi) {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $col = mysqli_query($koneksi, "SHOW COLUMNS FROM pembayaran LIKE 'tahun_ajaran'");
    if ($col && mysqli_num_rows($col) > 0) {
        isi_tahun_ajaran_pembayaran_kosong($koneksi);
        normalisasi_tahun_ajaran_tagihan_tunggakan($koneksi);
        return;
    }

    mysqli_query($koneksi, "ALTER TABLE pembayaran ADD tahun_ajaran VARCHAR(20) DEFAULT '' AFTER tahun_bayar");
    isi_tahun_ajaran_pembayaran_kosong($koneksi);
    normalisasi_tahun_ajaran_tagihan_tunggakan($koneksi);
}

function ensure_siswa_tanggal_masuk_column($koneksi) {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    $col = mysqli_query($koneksi, "SHOW COLUMNS FROM siswa LIKE 'tanggal_masuk'");
    if (!$col || mysqli_num_rows($col) == 0) {
        mysqli_query($koneksi, "ALTER TABLE siswa ADD tanggal_masuk DATE NULL AFTER nama_wali");
    }

    $col_tm = mysqli_query($koneksi, "SHOW COLUMNS FROM siswa LIKE 'tahun_masuk'");
    if (!$col_tm || mysqli_num_rows($col_tm) == 0) {
        mysqli_query($koneksi, "ALTER TABLE siswa ADD tahun_masuk VARCHAR(10) DEFAULT '' AFTER nama_wali");
    }
}

function get_bulan_masuk_siswa($koneksi, $nisn, $tahun_ajaran) {
    $nisn_esc = mysqli_real_escape_string($koneksi, $nisn);
    $q = mysqli_query($koneksi, "SELECT tahun_masuk, tanggal_masuk FROM siswa WHERE nisn = '$nisn_esc' LIMIT 1");
    if (!$q || !($row = mysqli_fetch_assoc($q))) return 0;
    if (!preg_match('/^(\d{4})\s*\/\s*(\d{4})$/', $tahun_ajaran, $m)) return 0;
    $tahun_mulai = (int)$m[1];

    // Prioritaskan tahun_masuk (diisi manual)
    $tahun_masuk = trim($row['tahun_masuk'] ?? '');
    if ($tahun_masuk !== '' && ctype_digit($tahun_masuk)) {
        $tahun_masuk_int = (int)$tahun_masuk;
        $diff = ($tahun_masuk_int - $tahun_mulai) * 12;
        if ($diff >= 12) return 12;
        return max(0, $diff);
    }

    // Fallback: hitung dari tanggal_masuk
    $tanggal_masuk = trim($row['tanggal_masuk'] ?? '');
    if (empty($tanggal_masuk)) return 0;
    $masuk = DateTime::createFromFormat('Y-m-d', $tanggal_masuk);
    if (!$masuk) return 0;
    $bulan_masuk = (int)$masuk->format('n');
    $tahun_masuk_int = (int)$masuk->format('Y');
    $diff = ($tahun_masuk_int - $tahun_mulai) * 12 + ($bulan_masuk - 7);
    if ($diff >= 12) return 12;
    return max(0, $diff);
}

/**
 * Periksa apakah siswa memiliki tagihan tunggakan.
 * Tagihan dianggap tunggakan jika melewati tahun ajaran berjalan atau sampai bulan Juli.
 * Mengembalikan array dengan detail tagihan atau false jika tidak ada.
 */
function cek_tagihan_tunggakan($koneksi, $nisn, $tahun_ajaran = null) {
    ensure_pembayaran_tahun_ajaran_column($koneksi);
    ensure_siswa_tanggal_masuk_column($koneksi);

    $tahun_ajaran = trim((string) ($tahun_ajaran ?? get_tahun_ajaran_aktif($koneksi)));
    if ($tahun_ajaran === '') {
        $tahun_ajaran = get_tahun_ajaran_aktif($koneksi);
    }
    if (!tahun_ajaran_boleh_ditagihkan($koneksi, $tahun_ajaran)) {
        return false;
    }

    $q_siswa = mysqli_query($koneksi, "SELECT s.*, k.nama_kelas FROM siswa s JOIN kelas k ON s.id_kelas = k.id_kelas WHERE s.nisn = '$nisn'");
    $d_siswa = mysqli_fetch_assoc($q_siswa);
    if (!$d_siswa) {
        return false;
    }
    
    $id_kelas_siswa = $d_siswa['id_kelas'];
    $nama_kelas_siswa = $d_siswa['nama_kelas'];
    
    $months = ['Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni'];
    $limit_index = limit_index_bulan_tahun_ajaran($koneksi, $tahun_ajaran);
    $min_index = get_bulan_masuk_siswa($koneksi, $nisn, $tahun_ajaran);
    
    $tagihan_tunggakan = [];
    
    $q_jenis = mysqli_query($koneksi, "SELECT * FROM jenis_bayar WHERE status = 'Aktif' ORDER BY tipe_bayar ASC");
    
    while ($jb = mysqli_fetch_assoc($q_jenis)) {
        if (jenis_bayar_berlaku_untuk_kelas($jb['tagihan_kelas'] ?? '', $id_kelas_siswa, $nama_kelas_siswa)) {
            $is_fully_paid = false;
            $unpaid_details = [];
            $sisa = 0;
            
            if ($jb['tipe_bayar'] == 'Bulanan') {
                $paid_months = ambil_bulan_bayar_tersimpan($koneksi, $nisn, $jb['id_jenis_bayar'], $tahun_ajaran);
                
                foreach ($months as $index => $m) {
                    if ($limit_index < 0) continue;
                    if ($index > $limit_index) continue;
                    if ($index < $min_index) continue;
                    
                    if (!in_array($m, $paid_months)) {
                        $unpaid_details[] = $m;
                        $sisa += (int)$jb['nominal'];
                    }
                }
                
                if (empty($unpaid_details)) {
                    $is_fully_paid = true;
                }
            } else {
                $total_bayar = ambil_total_bayar_tersimpan($koneksi, $nisn, $jb['id_jenis_bayar'], $tahun_ajaran);
                $sisa = $jb['nominal'] - $total_bayar;
                
                if ($sisa <= 0) {
                    $is_fully_paid = true;
                } else {
                    $unpaid_details[] = 'Sisa Tagihan';
                }
            }
            
            if (!$is_fully_paid && $sisa > 0) {
                $tagihan_tunggakan[] = [
                    'id_jenis_bayar' => $jb['id_jenis_bayar'],
                    'nama_pembayaran' => $jb['nama_pembayaran'],
                    'tipe_bayar' => $jb['tipe_bayar'],
                    'nominal' => $jb['nominal'],
                    'sisa' => $sisa,
                    'unpaid_details' => $unpaid_details,
                    'tahun_ajaran' => $tahun_ajaran
                ];
            }
        }
    }
    
    return !empty($tagihan_tunggakan) ? $tagihan_tunggakan : false;
}

/**
 * Buat dan kelola tabel integrasi endpoint (keluar & masuk)
 */
function ensure_integrasi_tables($koneksi) {
    static $tables_checked = false;
    if ($tables_checked) {
        return;
    }
    $tables_checked = true;

    // Table endpoint_keluar
    $q1 = "CREATE TABLE IF NOT EXISTS endpoint_keluar (
        id INT PRIMARY KEY AUTO_INCREMENT,
        nama VARCHAR(100) NOT NULL,
        deskripsi VARCHAR(255) DEFAULT '',
        metode VARCHAR(20) DEFAULT 'GET',
        path_endpoint VARCHAR(255) NOT NULL,
        status ENUM('Aktif', 'Non-aktif') DEFAULT 'Aktif',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    mysqli_query($koneksi, $q1);

    // Hapus seed keliru jika ada
    mysqli_query($koneksi, "DELETE FROM endpoint_keluar WHERE nama IN ('Data Guru', 'Data Kelas', 'Data Siswa', 'Sinkron Siswa (Pembayaran)')");

    // Seed default endpoint_keluar jika kosong
    $cek_keluar = mysqli_query($koneksi, "SELECT COUNT(*) as total FROM endpoint_keluar");
    $row_k = $cek_keluar ? mysqli_fetch_assoc($cek_keluar) : ['total' => 0];
    if (empty($row_k['total'])) {
        mysqli_query($koneksi, "INSERT INTO endpoint_keluar (nama, deskripsi, metode, path_endpoint, status) VALUES
            ('Data Tagihan & Potongan Tabungan (ETAB)', 'Endpoint data tagihan siswa & penarikan/potongan tabungan untuk ETAB.', 'GET/POST', 'api/etab.php?action=check&api_key=SPP_SECRET_KEY_2026', 'Aktif'),
            ('Data Tagihan & Tunggakan (SIMAD)', 'Endpoint rincian tagihan, tunggakan, dan riwayat bayar siswa untuk SIMAD.', 'GET', 'api/simad.php?action=get_all_summary&api_key=SPP_SECRET_KEY_2026', 'Aktif'),
            ('API Tagihan Siswa (v1)', 'Endpoint umum query tagihan dan laporan pembayaran siswa Pembayaran.', 'GET', 'api/v1.php?action=health&api_key=SPP_SECRET_KEY_2026', 'Aktif')
        ");
    }

    // Table endpoint_masuk
    $q2 = "CREATE TABLE IF NOT EXISTS endpoint_masuk (
        id INT PRIMARY KEY AUTO_INCREMENT,
        aplikasi VARCHAR(50) NOT NULL UNIQUE,
        nama_aplikasi VARCHAR(100) DEFAULT '',
        deskripsi VARCHAR(255) DEFAULT '',
        base_url VARCHAR(255) DEFAULT '',
        api_key VARCHAR(255) DEFAULT '',
        status TINYINT(1) DEFAULT 1,
        tes_terakhir_status VARCHAR(20) DEFAULT 'Belum dites',
        tes_terakhir_detail TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    mysqli_query($koneksi, $q2);

    // Hapus seed keliru jika ada
    mysqli_query($koneksi, "DELETE FROM endpoint_masuk WHERE aplikasi IN ('sigaji', 'sims', 'sibayar')");

    // Seed default endpoint_masuk jika kosong
    $cek_masuk = mysqli_query($koneksi, "SELECT COUNT(*) as total FROM endpoint_masuk");
    $row_m = $cek_masuk ? mysqli_fetch_assoc($cek_masuk) : ['total' => 0];
    if (empty($row_m['total'])) {
        mysqli_query($koneksi, "INSERT INTO endpoint_masuk (aplikasi, nama_aplikasi, deskripsi, base_url, api_key, status, tes_terakhir_status, tes_terakhir_detail) VALUES
            ('simad', 'simad', 'Ambil data siswa & kelas dari aplikasi SIMAD untuk sinkronisasi data master.', 'https://simad.misultanfattah.sch.id', 'SIS_CENTRAL_HUB_SECRET_2026', 1, 'Belum dites', NULL),
            ('etab', 'etab', 'Ambil endpoint/data dari aplikasi ETAB.', 'http://etab.test', 'ETAB_SECRET_KEY_2026', 1, 'Belum dites', NULL)
        ");
    }
}

function get_endpoint_masuk($koneksi, $aplikasi) {
    ensure_integrasi_tables($koneksi);
    $app_esc = mysqli_real_escape_string($koneksi, strtolower(trim($aplikasi)));
    $q = mysqli_query($koneksi, "SELECT * FROM endpoint_masuk WHERE LOWER(aplikasi) = '$app_esc' LIMIT 1");
    if ($q && $d = mysqli_fetch_assoc($q)) {
        return $d;
    }
    return null;
}
