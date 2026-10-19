<?php

require_once '../config/database.php';

require_once '../config/functions.php';



if (session_status() == PHP_SESSION_NONE) {

    session_start();

}



if (!isAuthorized(['admin', 'tata_usaha', 'wali'])) {

    redirect('../login.php');

}

$current_level = getUserLevel();
$is_wali = $current_level === 'wali';
$is_tata_usaha = $current_level === 'tata_usaha';
$is_admin = $current_level === 'admin';
// Mode pratinjau (session_type) — petunjuk Generate hanya untuk tampilan admin asli
$view_session_type = strtolower(trim((string)($_GET['session_type'] ?? '')));
$is_admin_view = $is_admin && ($view_session_type === '' || $view_session_type === 'admin');
$is_wali_kelas_6 = false;

if ($is_wali && isset($_SESSION['nama_guru'])) {
    $stmt_cls = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE wali_kelas = ? LIMIT 1");
    $stmt_cls->execute([$_SESSION['nama_guru']]);
    $kelas_wali = (string) ($stmt_cls->fetchColumn() ?: '');
    $kelas_wali_upper = strtoupper($kelas_wali);
    if (strpos($kelas_wali_upper, '6') !== false || strpos($kelas_wali_upper, 'VI') !== false) {
        $is_wali_kelas_6 = true;
    }
}

if ($is_wali && !$is_wali_kelas_6) {
    redirect('../wali/dashboard.php');
}

$can_crud = $is_admin || $is_tata_usaha;



$school_profile = getSchoolProfile($pdo);

$profileTa = $school_profile['tahun_ajaran'] ?? null;



try {

    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_peserta_ujian (

        id INT NOT NULL AUTO_INCREMENT,

        id_siswa INT NOT NULL,

        tahun_ajaran VARCHAR(30) NOT NULL,

        nomor_ujian VARCHAR(64) DEFAULT NULL,

        is_lulus TINYINT(1) NOT NULL DEFAULT 0,

        keterangan_kelulusan VARCHAR(500) DEFAULT NULL,

        tampil_di_akun_siswa TINYINT(1) NOT NULL DEFAULT 0,

        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        PRIMARY KEY (id),

        UNIQUE KEY uniq_pu_siswa_ta (id_siswa, tahun_ajaran),

        KEY idx_pu_ta (tahun_ajaran),

        KEY idx_pu_siswa (id_siswa)

    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

} catch (PDOException $e) {

    error_log('tb_peserta_ujian: ' . $e->getMessage());

}



foreach ([

    'is_lulus' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER nomor_ujian',

    'keterangan_kelulusan' => 'VARCHAR(500) DEFAULT NULL AFTER is_lulus',

    'tampil_di_akun_siswa' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER keterangan_kelulusan',

] as $col => $definition) {

    try {

        $pdo->exec("ALTER TABLE tb_peserta_ujian ADD COLUMN {$col} {$definition}");

    } catch (PDOException $e) {

        // Kolom sudah ada

    }

}



try {

    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_kelulusan_jadwal (

        tahun_ajaran VARCHAR(30) NOT NULL,

        waktu_mulai_tampil DATETIME DEFAULT NULL COMMENT 'Informasi baru tampil di akun siswa setelah waktu ini',

        siswa_lihat_kelulusan TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Satu pengaturan global: tampilkan info kelulusan di akun siswa',

        tanggal_surat_kelulusan DATE DEFAULT NULL,

        kota_surat VARCHAR(80) DEFAULT NULL,

        qr_tanda_tangan_payload VARCHAR(768) DEFAULT NULL,

        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        PRIMARY KEY (tahun_ajaran)

    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

} catch (PDOException $e) {

    error_log('tb_kelulusan_jadwal: ' . $e->getMessage());

}



try {

    $pdo->exec('ALTER TABLE tb_kelulusan_jadwal ADD COLUMN siswa_lihat_kelulusan TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'Tampilan global untuk akun siswa\' AFTER waktu_mulai_tampil');

} catch (PDOException $e) {

}

foreach ([

    ['tanggal_surat_kelulusan', "DATE DEFAULT NULL COMMENT 'Tanggal surat keterangan (bukan tanggal cetak)' AFTER siswa_lihat_kelulusan"],

    ['kota_surat', "VARCHAR(80) DEFAULT NULL COMMENT 'Tempat kota di tanggal surat' AFTER tanggal_surat_kelulusan"],

    ['qr_tanda_tangan_payload', "VARCHAR(768) DEFAULT NULL COMMENT 'Isi QR tanda tangan kepala (kosong=otomatis)' AFTER kota_surat"],

] as $colPair) {

    try {

        $pdo->exec("ALTER TABLE tb_kelulusan_jadwal ADD COLUMN `{$colPair[0]}` {$colPair[1]}");

    } catch (PDOException $e) {

    }

}



$tambahan_ta = [];

try {

    $tambahan_ta = array_merge($tambahan_ta, $pdo->query("SELECT DISTINCT tahun_ajaran FROM tb_peserta_ujian WHERE tahun_ajaran IS NOT NULL AND tahun_ajaran <> ''")->fetchAll(PDO::FETCH_COLUMN));

} catch (PDOException $e) {

}

try {

    $tambahan_ta = array_merge($tambahan_ta, $pdo->query("SELECT DISTINCT tahun_ajaran FROM tb_nilai_semester WHERE tahun_ajaran IS NOT NULL AND tahun_ajaran <> ''")->fetchAll(PDO::FETCH_COLUMN));

} catch (PDOException $e) {

}



$tahun_ajaran_options = buildTahunAjaranProfilOptions($profileTa, $tambahan_ta);



if (isset($_GET['ta'])) {

    $ta_requested = trim((string) $_GET['ta']);

    if (isTahunAjaranFormatValid($ta_requested) && !in_array($ta_requested, $tahun_ajaran_options, true)) {

        $tahun_ajaran_options[] = $ta_requested;

        rsort($tahun_ajaran_options, SORT_STRING);

    }

}



$defaultTa = '';

if ($profileTa && isTahunAjaranFormatValid($profileTa)) {

    $defaultTa = $profileTa;

} elseif (!empty($tahun_ajaran_options)) {

    $defaultTa = $tahun_ajaran_options[0];

}

if (!$defaultTa) {

    $defaultTa = date('Y') . '/' . (date('Y') + 1);

}



$tahun_ajaran_filter = isset($_GET['ta']) ? trim((string) $_GET['ta']) : $defaultTa;

if (!isTahunAjaranFormatValid($tahun_ajaran_filter) || !in_array($tahun_ajaran_filter, $tahun_ajaran_options, true)) {

    $tahun_ajaran_filter = in_array($defaultTa, $tahun_ajaran_options, true) ? $defaultTa : ($tahun_ajaran_options[0] ?? $defaultTa);

}



$message = null;



if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$can_crud) {
    $message = ['type' => 'danger', 'text' => 'Akses dibatasi. Wali Kelas 6 hanya dapat melihat informasi Data Peserta Ujian.'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_jadwal_kelulusan'])) {

    $ta_post = trim((string) ($_POST['tahun_ajaran'] ?? ''));

    $mode = ($_POST['mode_jadwal'] ?? '') === 'jadwal' ? 'jadwal' : 'langsung';

    if (!isTahunAjaranFormatValid($ta_post) || !in_array($ta_post, $tahun_ajaran_options, true)) {

        $message = ['type' => 'danger', 'text' => 'Tahun Ajaran Tidak Valid Untuk Jadwal.'];

    } else {

        $waktuDb = null;

        if ($mode === 'jadwal') {

            $raw = trim((string) ($_POST['waktu_mulai_tampil'] ?? ''));

            if ($raw === '') {

                $message = ['type' => 'danger', 'text' => 'Isi tanggal dan waktu mulai tayang, atau pilih tanpa jadwal.'];

            } else {

                $dtObj = DateTime::createFromFormat('Y-m-d\TH:i', $raw);

                if ($dtObj instanceof DateTime) {

                    $waktuDb = $dtObj->format('Y-m-d H:i:s');

                } else {

                    $message = ['type' => 'danger', 'text' => 'Format tanggal atau waktu tidak valid.'];

                }

            }

        }

        if ($message === null) {

            try {

                $pdo->prepare(

                    'INSERT INTO tb_kelulusan_jadwal (tahun_ajaran, waktu_mulai_tampil) VALUES (?, ?)

                     ON DUPLICATE KEY UPDATE waktu_mulai_tampil = VALUES(waktu_mulai_tampil)'

                )->execute([$ta_post, $waktuDb]);

                if (function_exists('logActivity')) {

                    logActivity($pdo, $_SESSION['username'] ?? 'admin', 'Data Peserta Ujian', 'Jadwal kelulusan TA ' . $ta_post . ' → ' . ($waktuDb ?? 'langsung/tanpa jadwal'));

                }

                redirect('data_peserta_ujian.php?ta=' . urlencode($ta_post) . '&ok=jadwal');

            } catch (Throwable $e) {

                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan jadwal: ' . $e->getMessage()];

            }

        }

    }

}



if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_toggle_tampil_akun_siswa'])) {

    $ta_post = trim((string) ($_POST['tahun_ajaran'] ?? ''));

    $aktif_global = ($_POST['aktif_global'] ?? '') === '1' ? 1 : 0;

    if (!isTahunAjaranFormatValid($ta_post) || !in_array($ta_post, $tahun_ajaran_options, true)) {

        $message = ['type' => 'danger', 'text' => 'Tahun Ajaran Tidak Valid.'];

    } else {

        try {

            $pdo->prepare(

                'INSERT INTO tb_kelulusan_jadwal (tahun_ajaran, siswa_lihat_kelulusan) VALUES (?, ?)

                 ON DUPLICATE KEY UPDATE siswa_lihat_kelulusan = VALUES(siswa_lihat_kelulusan)'

            )->execute([$ta_post, $aktif_global]);

            if (function_exists('logActivity')) {

                logActivity($pdo, $_SESSION['username'] ?? 'admin', 'Data Peserta Ujian', 'Tampil global kelulusan TA ' . $ta_post . ' = ' . $aktif_global);

            }

            redirect('data_peserta_ujian.php?ta=' . urlencode($ta_post) . '&ok=tampil');

        } catch (Throwable $e) {

            $message = ['type' => 'danger', 'text' => 'Gagal menyimpan pengaturan: ' . $e->getMessage()];

        }

    }

}



if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_surat_kelulusan'])) {

    $ta_post = trim((string) ($_POST['tahun_ajaran'] ?? ''));

    if (!isTahunAjaranFormatValid($ta_post) || !in_array($ta_post, $tahun_ajaran_options, true)) {

        $message = ['type' => 'danger', 'text' => 'Tahun Ajaran Tidak Valid Untuk Pengaturan Surat.'];

    } else {

        $raw_tgl = trim((string) ($_POST['tanggal_surat_kelulusan'] ?? ''));

        $tanggal_db = null;

        if ($raw_tgl !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw_tgl)) {

            $tanggal_db = $raw_tgl;

        }

        $kota = trim((string) ($_POST['kota_surat'] ?? ''));

        $kota = $kota === '' ? null : mb_substr($kota, 0, 80);

        try {

            $pdo->prepare(

                'INSERT INTO tb_kelulusan_jadwal (tahun_ajaran, tanggal_surat_kelulusan, kota_surat)

                 VALUES (?,?,?)

                 ON DUPLICATE KEY UPDATE tanggal_surat_kelulusan = VALUES(tanggal_surat_kelulusan), kota_surat = VALUES(kota_surat)'

            )->execute([$ta_post, $tanggal_db, $kota]);

            if (function_exists('logActivity')) {

                logActivity($pdo, $_SESSION['username'] ?? 'admin', 'Data Peserta Ujian', 'Surat kelulusan TA ' . $ta_post . ' tgl ' . ($tanggal_db ?? '-'));

            }

            redirect('data_peserta_ujian.php?ta=' . urlencode($ta_post) . '&ok=surat');

        } catch (Throwable $e) {

            $message = ['type' => 'danger', 'text' => 'Gagal menyimpan pengaturan surat: ' . $e->getMessage()];

        }

    }

}



if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_kelulusan'])) {

    $ta_post = trim((string) ($_POST['tahun_ajaran'] ?? ''));

    if (!isTahunAjaranFormatValid($ta_post) || !in_array($ta_post, $tahun_ajaran_options, true)) {

        $message = ['type' => 'danger', 'text' => 'Tahun Ajaran Tidak Valid.'];

    } else {

        $ids_ok = [];

        try {

            $stIds = $pdo->prepare('SELECT id_siswa FROM tb_peserta_ujian WHERE tahun_ajaran = ?');

            $stIds->execute([$ta_post]);

            $ids_ok = array_flip(array_map('intval', $stIds->fetchAll(PDO::FETCH_COLUMN)));

        } catch (Throwable $e) {

            $ids_ok = [];

        }

        $payload = $_POST['pu'] ?? [];

        if (!is_array($payload)) {

            $payload = [];

        }

        try {

            $pdo->beginTransaction();

            $up = $pdo->prepare(

                'UPDATE tb_peserta_ujian SET is_lulus = ?, keterangan_kelulusan = ?

                 WHERE id_siswa = ? AND tahun_ajaran = ?'

            );

            /* Checkbox tidak ikut POST jika tidak dicentang — iterasi semua peserta TA agar uncheck / partial uncheck tersimpan. */
            foreach (array_keys($ids_ok) as $idKey) {

                $id_siswa = (int) $idKey;

                if ($id_siswa <= 0) {

                    continue;

                }

                $data = $payload[$id_siswa] ?? $payload[(string) $id_siswa] ?? [];

                if (!is_array($data)) {

                    $data = [];

                }

                $is_lulus = !empty($data['lulus']) ? 1 : 0;

                $ket = $is_lulus ? 'Lulus' : 'Tidak lulus';

                $up->execute([$is_lulus, $ket, $id_siswa, $ta_post]);

            }

            $pdo->commit();

            if (function_exists('logActivity')) {

                logActivity($pdo, $_SESSION['username'] ?? 'admin', 'Data Peserta Ujian', 'Simpan kelulusan TA ' . $ta_post);

            }

            redirect('data_peserta_ujian.php?ta=' . urlencode($ta_post) . '&ok=kelulusan');

        } catch (Throwable $e) {

            $pdo->rollBack();

            $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];

        }

    }

}



if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_nomor_semua'])) {

    $ta_post = trim((string) ($_POST['tahun_ajaran'] ?? ''));

    if (!isTahunAjaranFormatValid($ta_post) || !in_array($ta_post, $tahun_ajaran_options, true)) {

        $message = ['type' => 'danger', 'text' => 'Tahun Ajaran Tidak Valid.'];

    } else {

        $yy = tahunAjaranSuffix2($ta_post);

        $stmtList = $pdo->prepare(

            'SELECT s.id_siswa FROM tb_siswa s

            INNER JOIN tb_kelas k ON s.id_kelas = k.id_kelas

            WHERE (k.nama_kelas LIKE ? OR k.nama_kelas LIKE ?)

            ORDER BY s.nama_siswa ASC'

        );

        $stmtList->execute(['%6%', '%VI%']);

        $list = $stmtList->fetchAll(PDO::FETCH_COLUMN);

        if (empty($list)) {

            $message = ['type' => 'warning', 'text' => 'Tidak Ada Siswa Kelas VI Untuk Diberi Nomor.'];

        } else {

            try {

                $pdo->beginTransaction();

                $up = $pdo->prepare(

                    'INSERT INTO tb_peserta_ujian (id_siswa, tahun_ajaran, nomor_ujian) VALUES (?,?,?)

                     ON DUPLICATE KEY UPDATE nomor_ujian=VALUES(nomor_ujian)'

                );

                $urut = 0;

                foreach ($list as $id_siswa) {

                    $urut++;

                    $nomor = susunNomorUjianFormal((string) $yy, $urut);

                    $up->execute([(int) $id_siswa, $ta_post, $nomor]);

                }

                $pdo->commit();

                if (function_exists('logActivity')) {

                    logActivity($pdo, $_SESSION['username'] ?? 'admin', 'Data Peserta Ujian', 'Generate nomor TA ' . $ta_post . ' (' . $urut . ' siswa)');

                }

                redirect('data_peserta_ujian.php?ta=' . urlencode($ta_post) . '&ok=gen&n=' . $urut);

            } catch (Throwable $e) {

                $pdo->rollBack();

                $message = ['type' => 'danger', 'text' => 'Gagal Generate: ' . $e->getMessage()];

            }

        }

    }

}



if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    if (isset($_GET['ok']) && $_GET['ok'] === 'gen') {

        $n = (int) ($_GET['n'] ?? 0);

        $message = ['type' => 'success', 'text' => $n > 0 ? 'Berhasil Memperbarui ' . $n . ' Nomor Ujian.' : 'Data Peserta Diperbarui.'];

    }

    if (isset($_GET['ok']) && $_GET['ok'] === 'kelulusan') {

        $message = ['type' => 'success', 'text' => 'Status kelulusan dan keterangan disimpan.'];

    }

    if (isset($_GET['ok']) && $_GET['ok'] === 'jadwal') {

        $message = ['type' => 'success', 'text' => 'Jadwal pengumuman kelulusan diperbarui.'];

    }

    if (isset($_GET['ok']) && $_GET['ok'] === 'tampil') {

        $message = ['type' => 'success', 'text' => 'Pengaturan tampil di akun siswa diperbarui.'];

    }

    if (isset($_GET['ok']) && $_GET['ok'] === 'surat') {

        $message = ['type' => 'success', 'text' => 'Pengaturan surat kelulusan (tanggal surat) disimpan.'];

    }

}



$sql = 'SELECT s.id_siswa, s.nisn, s.nama_siswa, s.foto, k.nama_kelas, pu.nomor_ujian, pu.is_lulus, pu.keterangan_kelulusan

    FROM tb_peserta_ujian pu

    INNER JOIN tb_siswa s ON s.id_siswa = pu.id_siswa

    LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas

    WHERE pu.tahun_ajaran = ?

    ORDER BY s.nama_siswa ASC';

$stmt = $pdo->prepare($sql);

$stmt->execute([$tahun_ajaran_filter]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$jumlah_peserta_ujian = count($rows);



$jadwal_waktu = null;

$tampil_global_siswa = false;

$tanggal_surat_kelulusan = null;

$kota_surat_kelulusan = '';

try {

    $stJ = $pdo->prepare('SELECT waktu_mulai_tampil, COALESCE(siswa_lihat_kelulusan, 0) AS siswa_lihat_kelulusan, tanggal_surat_kelulusan, kota_surat FROM tb_kelulusan_jadwal WHERE tahun_ajaran = ? LIMIT 1');

    $stJ->execute([$tahun_ajaran_filter]);

    $rowJ = $stJ->fetch(PDO::FETCH_ASSOC);

    if ($rowJ) {

        $jadwal_waktu = $rowJ['waktu_mulai_tampil'] ?? null;

        if ($jadwal_waktu === '') {

            $jadwal_waktu = null;

        }

        $tampil_global_siswa = ((int) ($rowJ['siswa_lihat_kelulusan'] ?? 0)) === 1;

        $tanggal_surat_kelulusan = $rowJ['tanggal_surat_kelulusan'] ?? null;

        if ($tanggal_surat_kelulusan === '') {

            $tanggal_surat_kelulusan = null;

        }

        $kota_surat_kelulusan = trim((string) ($rowJ['kota_surat'] ?? ''));

    }

} catch (PDOException $e) {

    $jadwal_waktu = null;

    $tampil_global_siswa = false;

}

$tanggal_surat_form_value = '';

if ($tanggal_surat_kelulusan) {

    try {

        $tanggal_surat_form_value = (new DateTime((string) $tanggal_surat_kelulusan))->format('Y-m-d');

    } catch (Exception $e) {

        $tanggal_surat_form_value = '';

    }

}



$jadwal_mode = $jadwal_waktu ? 'jadwal' : 'langsung';

$jadwal_input_value = '';

if ($jadwal_waktu) {

    $jd = DateTime::createFromFormat('Y-m-d H:i:s', (string) $jadwal_waktu);

    if ($jd instanceof DateTime) {

        $jadwal_input_value = $jd->format('Y-m-d\TH:i');

    }

}



$page_title = 'Data Peserta Ujian';

$pu_logo_path = '';
$pu_logo_file = trim((string) ($school_profile['logo'] ?? ''));
if ($pu_logo_file !== '' && is_file(__DIR__ . '/../assets/img/' . $pu_logo_file)) {
    $pu_logo_path = '../assets/img/' . $pu_logo_file;
} elseif (is_file(__DIR__ . '/../assets/img/logo_madrasah.png')) {
    $pu_logo_path = '../assets/img/logo_madrasah.png';
}

require_once '../templates/header.php';

require_once '../templates/sidebar.php';

?>

<style>
/* Global Crisp Dark Fonts — Hilangkan Font Grey Pudar di Seluruh Sistem */
body,
.main-sidebar,
.main-sidebar .sidebar-menu li a,
.main-sidebar .sidebar-menu li ul.dropdown-menu li a,
.main-content,
.main-content p,
.main-content label,
.main-content table,
.card,
.card-header,
.card-body,
.card-footer,
.form-group label,
.form-inline label,
.breadcrumb-item,
.breadcrumb-item a,
.section-title,
.section-lead {
    color: #0f172a !important;
}

/* Sidebar Menu Font & Headers */
.main-sidebar .sidebar-menu li.menu-header {
    color: #475569 !important;
    font-weight: 700 !important;
    letter-spacing: 1.4px !important;
}
.main-sidebar .sidebar-menu li a {
    color: #1e293b !important;
    font-weight: 600 !important;
}
.main-sidebar .sidebar-menu li a i {
    color: #334155 !important;
}
.main-sidebar .sidebar-menu li a:hover {
    color: #6777ef !important;
    background-color: #f1f5f9 !important;
}
.main-sidebar .sidebar-menu li a:hover i {
    color: #6777ef !important;
}
.main-sidebar .sidebar-menu li.active > a {
    color: #6777ef !important;
    font-weight: 700 !important;
    background-color: #eef2ff !important;
}
.main-sidebar .sidebar-menu li.active > a i {
    color: #6777ef !important;
}
.main-sidebar .sidebar-menu li ul.dropdown-menu li a {
    color: #334155 !important;
    font-weight: 500 !important;
}
.main-sidebar .sidebar-menu li ul.dropdown-menu li a:hover,
.main-sidebar .sidebar-menu li ul.dropdown-menu li.active > a,
.main-sidebar .sidebar-menu li ul.dropdown-menu li a.active {
    color: #6777ef !important;
    font-weight: 700 !important;
}

/* Seluruh text-muted dibuat kontras gelap (#334155), bukan abu-abu pudar */
.text-muted, .text-secondary {
    color: #334155 !important;
}
.small, small {
    color: #334155 !important;
}

/* Navbar Top Header: Text putih bersih kontras tinggi di atas latar biru */
.main-navbar,
.main-navbar .text-white,
.main-navbar .navbar-nav,
.main-navbar .navbar-nav li,
.main-navbar .navbar-nav span,
.main-navbar .navbar-nav a,
.main-navbar #header-date-time {
    color: #ffffff !important;
}
.main-navbar .navbar-nav a:hover {
    color: #e0e7ff !important;
}

/* Badge Status: Font PUTIH bersih agar kontras dengan latar badge */
.badge,
.badge-danger,
.badge-success,
.badge-warning,
.badge-primary,
.badge-info,
.badge-dark,
.pu-ket-teks,
.pu-card-status .badge {
    color: #ffffff !important;
    font-weight: 700 !important;
}
.badge.badge-light,
.badge.badge-white {
    color: #0f172a !important;
}

/* Header Card Bar — Judul & Filter Kiri, Tombol Aksi Kanan */
.card .card-header {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    flex-wrap: wrap !important;
    gap: 12px 20px !important;
    padding: 18px 22px !important;
    min-height: auto !important;
}
.pu-header-left {
    display: flex !important;
    align-items: center !important;
    flex-wrap: wrap !important;
    gap: 12px 16px !important;
}
.card .card-header h4 {
    margin: 0 !important;
    line-height: 1.3 !important;
    color: #0f172a !important;
    font-size: 16px !important;
    font-weight: 800 !important;
}
.pu-header-actions {
    display: flex !important;
    align-items: center !important;
    flex-wrap: wrap !important;
    gap: 10px !important;
    margin-left: auto !important;
}
.pu-filter-inline {
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    margin: 0 !important;
}
.pu-filter-inline label {
    margin: 0 !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    color: #1e293b !important;
    white-space: nowrap !important;
}
.pu-filter-inline .pu-select-pill {
    margin: 0 !important;
    background: #f8fafc !important;
    border: 1.5px solid #cbd5e1 !important;
    color: #0f172a !important;
    font-weight: 700 !important;
}

/* Mobile App Button Styles */
.pu-btn-app {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border-radius: 999px;
    font-weight: 700;
    font-size: 13px;
    line-height: 1.2;
    padding: 8px 16px;
    border: 0;
    cursor: pointer;
    transition: transform .12s ease, box-shadow .15s ease, background .15s ease;
    text-decoration: none !important;
    user-select: none;
    white-space: nowrap;
}
.pu-btn-app:active { transform: scale(0.96); }
.pu-btn-app-sm { padding: 6px 14px; font-size: 12px; gap: 5px; }
.pu-btn-app-lg { padding: 11px 22px; font-size: 13.5px; gap: 8px; }
.pu-btn-app-primary {
    background: linear-gradient(135deg, #6777ef 0%, #3abaf4 100%);
    color: #fff !important;
    box-shadow: 0 4px 12px rgba(103, 119, 239, .28);
}
.pu-btn-app-primary:hover {
    box-shadow: 0 6px 16px rgba(103, 119, 239, .38);
    color: #fff !important;
    transform: translateY(-1px);
}
.pu-btn-app-success {
    background: linear-gradient(135deg, #47c363 0%, #2ecc71 100%);
    color: #fff !important;
    box-shadow: 0 4px 12px rgba(71, 195, 99, .28);
}
.pu-btn-app-success:hover {
    box-shadow: 0 6px 16px rgba(71, 195, 99, .38);
    color: #fff !important;
    transform: translateY(-1px);
}
.pu-btn-app-warning {
    background: linear-gradient(135deg, #ffa426 0%, #f39c12 100%);
    color: #fff !important;
    box-shadow: 0 4px 12px rgba(255, 164, 38, .28);
}
.pu-btn-app-warning:hover {
    box-shadow: 0 6px 16px rgba(255, 164, 38, .38);
    color: #fff !important;
    transform: translateY(-1px);
}
.pu-btn-app-outline {
    background: #ffffff;
    color: #6777ef !important;
    border: 1.5px solid #dbe4f0;
    box-shadow: 0 2px 6px rgba(20, 30, 70, .04);
}
.pu-btn-app-outline:hover {
    background: #f4f6f9;
    border-color: #6777ef;
    color: #6777ef !important;
    transform: translateY(-1px);
}
.pu-select-pill {
    border-radius: 999px !important;
    padding: 6px 14px !important;
    font-weight: 600 !important;
    font-size: 13px !important;
    height: auto !important;
}

.pu-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 18px;
    padding: 12px 16px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: 0 2px 8px rgba(20, 30, 70, .04);
}
.pu-master-label { display: inline-flex; align-items: center; gap: 8px; font-weight: 700; font-size: 13px; color: #334155; cursor: pointer; user-select: none; }
.pu-master-label input { width: 17px; height: 17px; margin: 0; accent-color: #4f46e5; }
.pu-toolbar-info { color: #64748b; font-size: 13px; font-weight: 700; white-space: nowrap; }
.pu-search { position: relative; flex: 1 1 240px; min-width: 180px; }
.pu-search input { width: 100%; padding: 9px 14px 9px 38px; border: 1.5px solid #e2e8f0; border-radius: 999px; background: #f8fafc; color: #334155; font-size: 13px; font-weight: 600; outline: none; transition: border-color .15s ease, box-shadow .15s ease, background .15s ease; }
.pu-search input:focus { background: #fff; border-color: #818cf8; box-shadow: 0 0 0 3px rgba(99, 102, 241, .15); }
.pu-search i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 13px; }

/* Grid 2 Kolom */
.pu-card-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
@media (max-width: 767.98px) {
    .pu-card-grid { grid-template-columns: 1fr; gap: 12px; }
}

.pu-card {
    position: relative;
    display: flex;
    flex-direction: column;
    background: #fff;
    border: 1px solid #dfe6f0;
    border-radius: 16px;
    box-shadow: 0 4px 16px rgba(20, 30, 70, .05);
    overflow: hidden;
    transition: transform .18s ease, box-shadow .18s ease;
}
.pu-card:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(20, 30, 70, .10); }
.pu-card::before { content: ''; position: absolute; inset: 0 0 auto 0; height: 4px; background: linear-gradient(90deg, #4f46e5, #3abaf4); }
.pu-card-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    padding: 14px 16px 11px;
    border-bottom: 1px dashed #e2e8f0;
}
.pu-card-school { display: flex; align-items: center; gap: 10px; min-width: 0; }
.pu-card-logo { width: 36px; height: 36px; object-fit: contain; flex: 0 0 36px; }
.pu-card-logo-icon { display: inline-flex; align-items: center; justify-content: center; border-radius: 9px; background: #eef2ff; color: #4f46e5; font-size: 16px; }
.pu-card-title { font-size: 12px; line-height: 1.2; font-weight: 800; letter-spacing: .45px; color: #1e293b; text-transform: uppercase; }
.pu-card-school-name { margin-top: 3px; font-size: 11px; line-height: 1.3; color: #64748b; }
.pu-card-number { flex-shrink: 0; padding: 4px 10px; border-radius: 999px; background: #eef2ff; color: #4338ca; font-size: 11px; font-weight: 800; white-space: nowrap; }
.pu-card-body { display: flex; gap: 14px; padding: 15px 16px; flex: 1 1 auto; }
.pu-card-photo {
    width: 76px;
    height: 94px;
    flex: 0 0 76px;
    border-radius: 12px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #eef2ff 0%, #e0f2fe 100%);
    border: 1px solid #dbe4f0;
    color: #4f46e5;
    font-size: 22px;
    font-weight: 800;
}
.pu-card-photo-img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pu-card-photo-initials { line-height: 1; }
.pu-card-info { min-width: 0; flex: 1 1 auto; }
.pu-card-name { margin-bottom: 9px; color: #0f172a; font-size: 15px; line-height: 1.35; font-weight: 800; word-break: break-word; }
.pu-card-meta { display: flex; flex-direction: column; gap: 5px; }
.pu-card-meta-row { display: flex; justify-content: space-between; gap: 10px; font-size: 12px; line-height: 1.4; }
.pu-card-meta-row span { color: #64748b; white-space: nowrap; }
.pu-card-meta-row strong { color: #1e293b; text-align: right; word-break: break-word; }
.pu-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 11px 16px;
    background: #f8fafc;
    border-top: 1px solid #eef2f7;
}
.pu-card-status { display: flex; flex-wrap: wrap; align-items: center; gap: 7px; min-width: 0; }
.pu-card-status input { width: 17px; height: 17px; margin: 0; flex-shrink: 0; accent-color: #10b981; }
.pu-card-status .badge { font-size: 10.5px; padding: 4px 9px; border-radius: 999px; font-weight: 700; }
.pu-card-note { flex-basis: 100%; color: #64748b; font-size: 10.5px; line-height: 1.35; }

.pu-card-print {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 999px;
    background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
    color: #fff !important;
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: .25px;
    border: 0;
    box-shadow: 0 3px 10px rgba(79, 70, 229, .28);
    transition: transform .12s ease, box-shadow .15s ease;
    text-decoration: none !important;
    white-space: nowrap;
}
.pu-card-print:hover {
    transform: translateY(-1px);
    box-shadow: 0 5px 14px rgba(79, 70, 229, .38);
    color: #fff !important;
}
.pu-card-print:active { transform: scale(0.96); }

.pu-settings-box {
    background: #ffffff;
    border: 1.5px solid #cbd5e1;
    border-radius: 18px;
    padding: 22px 24px;
    margin-bottom: 22px;
    box-shadow: 0 4px 18px rgba(15, 23, 42, .06);
}
.pu-settings-box h5 { font-size: 16px; font-weight: 800; color: #0f172a; }
.pu-settings-box .form-control { border-radius: 12px; border: 1.5px solid #cbd5e1; font-size: 13.5px; color: #0f172a; font-weight: 600; padding: 10px 14px; height: auto; }
.pu-settings-box .form-control:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, .18); }
.pu-settings-desc { font-size: 13px; color: #334155; line-height: 1.5; margin-bottom: 16px; font-weight: 500; }
.pu-field-label { display: block; font-size: 12.5px; font-weight: 700; color: #1e293b; margin-bottom: 6px; letter-spacing: .2px; }

/* Status Banner ala Mobile Apps */
.pu-status-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 16px 20px;
    border-radius: 16px;
    margin-bottom: 20px;
    border: 1.5px solid;
    box-shadow: 0 4px 16px rgba(15, 23, 42, .05);
}
.pu-status-banner.is-active {
    background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
    border-color: #86efac;
}
.pu-status-banner.is-inactive {
    background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
    border-color: #fde68a;
}
.pu-status-main { display: flex; align-items: flex-start; gap: 14px; min-width: 0; flex: 1 1 auto; }
.pu-status-content { min-width: 0; flex: 1 1 auto; }
.pu-status-action { flex-shrink: 0; display: flex; align-items: center; }
.pu-status-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    margin-top: 2px;
}
.pu-status-banner.is-active .pu-status-icon { background: #dcfce7; color: #15803d; }
.pu-status-banner.is-inactive .pu-status-icon { background: #fef3c7; color: #b45309; }
.pu-status-pill {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .4px;
    text-transform: uppercase;
    margin-bottom: 3px;
}
.pu-status-banner.is-active .pu-status-pill { background: #22c55e; color: #ffffff; }
.pu-status-banner.is-inactive .pu-status-pill { background: #f59e0b; color: #ffffff; }
.pu-status-title { font-size: 14.5px; font-weight: 800; color: #0f172a; line-height: 1.3; }
.pu-status-desc { font-size: 12.5px; color: #334155; line-height: 1.45; margin-top: 2px; }
.pu-status-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.pu-status-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 8px;
    background: #ffffff;
    border: 1px solid rgba(0, 0, 0, .08);
    font-size: 11.5px;
    font-weight: 700;
    color: #1e293b;
    line-height: 1.35;
}

@media (max-width: 991.98px) {
    /* Mode Tablet & Mobile: Tombol Atur Waktu Rata Kiri */
    .card .card-header {
        align-items: flex-start !important;
        gap: 12px 16px !important;
    }
    .pu-header-actions {
        width: 100% !important;
        margin-left: 0 !important;
        justify-content: flex-start !important;
    }
    .pu-header-actions .pu-btn-app {
        width: auto !important;
    }
}

@media (max-width: 767.98px) {
    .card .card-header {
        padding: 14px 16px !important;
        gap: 12px !important;
    }
    .pu-header-left {
        width: 100% !important;
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 10px !important;
    }
    .pu-header-left h4 {
        width: 100% !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        font-size: 15px !important;
    }
    .pu-filter-inline {
        width: 100% !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
    }
    .pu-filter-inline .pu-select-pill {
        flex: 1 1 auto !important;
        max-width: none !important;
    }
    .pu-header-actions {
        width: 100% !important;
        margin-left: 0 !important;
        justify-content: flex-start !important;
    }
    .pu-header-actions .pu-btn-app {
        width: 100% !important;
    }

    /* Banner mobile stacking */
    .pu-status-banner {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 14px !important;
        padding: 15px 16px !important;
    }
    .pu-status-main {
        width: 100% !important;
        gap: 12px !important;
    }
    .pu-status-action {
        width: 100% !important;
    }
    .pu-status-action form {
        width: 100% !important;
    }
    .pu-status-action .pu-btn-app {
        width: 100% !important;
        padding: 10px 16px !important;
    }
    .pu-status-chips {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 6px !important;
    }
    .pu-status-chip {
        width: 100% !important;
    }
}

@media (max-width: 575.98px) {
    .card-body { padding: 14px 12px !important; }
    .pu-settings-box { padding: 16px 14px !important; border-radius: 14px !important; }
    .pu-card-body { gap: 12px; padding: 13px; }
    .pu-card-photo { width: 68px; height: 84px; flex-basis: 68px; }
    .pu-card-top, .pu-card-footer { padding-left: 13px; padding-right: 13px; }
}
</style>

<div class="main-content">

    <section class="section">

        <div class="section-header">
            <h1><?= htmlspecialchars($page_title) ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>



        <div class="section-body">

            <?php if ($message): ?>

            <script>

                document.addEventListener('DOMContentLoaded', function() {

                    Swal.fire({

                        title: <?= json_encode($message['type'] === 'success' ? 'Berhasil' : 'Perhatian') ?>,

                        text: <?= json_encode($message['text']) ?>,

                        icon: <?= json_encode($message['type']) ?>,

                        timer: <?= $message['type'] === 'success' ? '2500' : '5000' ?>,

                        timerProgressBar: true,

                        showConfirmButton: false

                    });

                });

            </script>

            <?php endif; ?>



            <div class="row">

                <div class="col-12">

                    <div class="card">

                        <div class="card-header">

                            <div class="pu-header-left">

                                <h4 class="mb-0">Data Peserta Ujian Kelas VI
                                    <span class="badge badge-info align-middle ml-2" title="Jumlah peserta untuk tahun ajaran ini"><?= (int) $jumlah_peserta_ujian ?> peserta</span>
                                </h4>

                                <form method="get" class="pu-filter-inline">

                                    <label>Tahun Ajaran:</label>

                                    <select name="ta" class="form-control pu-select-pill" onchange="this.form.submit()">

                                        <?php foreach ($tahun_ajaran_options as $optTa): ?>

                                        <option value="<?= htmlspecialchars($optTa) ?>"<?= ($tahun_ajaran_filter === $optTa) ? ' selected' : '' ?>><?= htmlspecialchars($optTa) ?></option>

                                        <?php endforeach; ?>

                                    </select>

                                </form>

                            </div>

                            <div class="pu-header-actions">

                                <?php if ($can_crud): ?>
                                <button type="button" class="pu-btn-app pu-btn-app-sm pu-btn-app-outline" data-toggle="modal" data-target="#modalJadwalKelulusan">

                                    <i class="fas fa-clock"></i> Atur Waktu Pengumuman

                                </button>
                                <?php endif; ?>

                            </div>

                        </div>

                        <div class="card-body">

                            <?php if ($can_crud): ?>
                            <div class="pu-status-banner <?= $tampil_global_siswa ? 'is-active' : 'is-inactive' ?>">
                                <div class="pu-status-main">
                                    <div class="pu-status-icon">
                                        <i class="fas <?= $tampil_global_siswa ? 'fa-broadcast-tower' : 'fa-eye-slash' ?>"></i>
                                    </div>
                                    <div class="pu-status-content">
                                        <div>
                                            <span class="pu-status-pill"><?= $tampil_global_siswa ? 'Aktif Tayang' : 'Belum Ditampilkan' ?></span>
                                        </div>
                                        <div class="pu-status-title">Status Pengumuman di Akun Siswa (TA <?= htmlspecialchars($tahun_ajaran_filter) ?>)</div>
                                        <div class="pu-status-desc">
                                            <?= $tampil_global_siswa
                                                ? 'Siswa kelas VI sudah dapat melihat pengumuman kelulusan di akun masing-masing.'
                                                : 'Siswa belum bisa melihat status kelulusan sampai Anda mengaktifkannya lewat tombol di bawah/samping.'
                                            ?>
                                        </div>
                                        <div class="pu-status-chips">
                                            <span class="pu-status-chip">
                                                <i class="far fa-clock text-primary"></i>
                                                <?= $jadwal_waktu ? 'Mulai tayang: ' . htmlspecialchars((string) $jadwal_waktu) : 'Tanpa jadwal tunda (langsung tampil jika aktif)' ?>
                                            </span>
                                            <span class="pu-status-chip">
                                                <i class="fas fa-users text-info"></i>
                                                <?= (int) $jumlah_peserta_ujian ?> peserta terdata
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="pu-status-action">
                                    <form method="post" class="m-0">
                                        <input type="hidden" name="simpan_toggle_tampil_akun_siswa" value="1">
                                        <input type="hidden" name="tahun_ajaran" value="<?= htmlspecialchars($tahun_ajaran_filter, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="aktif_global" value="<?= $tampil_global_siswa ? '0' : '1' ?>">
                                        <button type="submit" class="pu-btn-app pu-btn-app-sm <?= $tampil_global_siswa ? 'pu-btn-app-warning' : 'pu-btn-app-success' ?>">
                                            <i class="fas <?= $tampil_global_siswa ? 'fa-eye-slash' : 'fa-eye' ?>"></i>
                                            <?= $tampil_global_siswa ? 'Sembunyikan dari siswa' : 'Tampilkan di akun siswa' ?>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($can_crud): ?>
                            <div class="pu-settings-box">

                                <div>

                                    <h5 class="mb-2"><i class="fas fa-file-signature text-primary mr-2"></i>Pengaturan Cetak Surat Kelulusan (TA <?= htmlspecialchars($tahun_ajaran_filter) ?>)</h5>

                                    <p class="pu-settings-desc">Tentukan tanggal dan kota yang akan tercetak pada surat keterangan kelulusan siswa (bukan tanggal otomatis printer).</p>

                                    <form method="post" class="">

                                        <input type="hidden" name="simpan_surat_kelulusan" value="1">

                                        <input type="hidden" name="tahun_ajaran" value="<?= htmlspecialchars($tahun_ajaran_filter, ENT_QUOTES, 'UTF-8') ?>">

                                        <div class="row">

                                            <div class="col-md-4">

                                                <div class="form-group mb-3">

                                                    <label for="tanggal_surat_kelulusan" class="pu-field-label">Tanggal Surat</label>

                                                    <input type="date" class="form-control" id="tanggal_surat_kelulusan" name="tanggal_surat_kelulusan" value="<?= htmlspecialchars($tanggal_surat_form_value, ENT_QUOTES, 'UTF-8') ?>">

                                                </div>

                                            </div>

                                            <div class="col-md-4">

                                                <div class="form-group mb-3">

                                                    <label for="kota_surat" class="pu-field-label">Kota Pada Tanggal Surat</label>

                                                    <input type="text" class="form-control" id="kota_surat" name="kota_surat" value="<?= htmlspecialchars($kota_surat_kelulusan, ENT_QUOTES, 'UTF-8') ?>" placeholder="Contoh: Jepara" maxlength="80">

                                                </div>

                                            </div>

                                        </div>

                                        <div class="d-flex flex-wrap align-items-center gap-2" style="gap: 10px;">
                                            <button type="submit" class="pu-btn-app pu-btn-app-primary"><i class="fas fa-save"></i> Simpan Pengaturan</button>
                                            <a class="pu-btn-app pu-btn-app-outline" target="_blank" rel="noopener noreferrer" href="cetak_surat_kelulusan.php?session_type=<?= urlencode($current_level) ?>&ta=<?= urlencode($tahun_ajaran_filter) ?>&mode=all"><i class="fas fa-print"></i> Cetak Semua</a>

                                            <?php if ($tanggal_surat_kelulusan): ?>

                                                <span class="ml-md-2 align-middle font-weight-bold" style="color: #0f172a; font-size: 13px;"><i class="fas fa-check-circle text-success mr-1"></i>Tersimpan: <?= htmlspecialchars(formatDateIndonesia((string) $tanggal_surat_kelulusan), ENT_QUOTES, 'UTF-8') ?></span>

                                            <?php endif; ?>
                                        </div>

                                    </form>

                                </div>

                            </div>
                            <?php endif; ?>



                            <?php if ($can_crud): ?>
                            <form method="post" class="mb-3" id="form-generate-nomor-ujian">

                                <input type="hidden" name="generate_nomor_semua" value="1">

                                <input type="hidden" name="tahun_ajaran" value="<?= htmlspecialchars($tahun_ajaran_filter, ENT_QUOTES, 'UTF-8') ?>">

                                <button type="submit" class="pu-btn-app pu-btn-app-outline"><i class="fas fa-sync-alt"></i> Generate Ulang Nomor Ujian</button>

                            </form>
                            <?php endif; ?>



                            <?php if (empty($rows)): ?>

                            <?php if ($is_admin_view): ?>

                            <p class="text-muted mb-0">Belum ada peserta tersimpan untuk tahun ajaran ini (0 peserta). Pilih tahun ajaran yang sesuai, lalu klik <strong>Generate Nomor Ujian</strong> (data mengikuti siswa kelas VI saat ini).</p>

                            <?php else: ?>

                            <div class="text-center text-muted py-4">
                                <i class="far fa-folder-open fa-3x mb-3"></i>
                                <p class="mb-0">Belum ada data peserta ujian untuk tahun ajaran ini.</p>
                            </div>

                            <?php endif; ?>

                            <?php else: ?>

                                <?php if (!$can_crud): ?>
                                <div class="mb-3">
                                    <a class="pu-btn-app pu-btn-app-primary" target="_blank" rel="noopener noreferrer" href="cetak_surat_kelulusan.php?session_type=<?= urlencode($current_level) ?>&ta=<?= urlencode($tahun_ajaran_filter) ?>&mode=all"><i class="fas fa-print"></i> Cetak Semua Surat</a>
                                </div>
                                <?php endif; ?>

                                <?php if ($can_crud): ?>
                            <form method="post" class="mb-3">

                                <input type="hidden" name="simpan_kelulusan" value="1">

                                <input type="hidden" name="tahun_ajaran" value="<?= htmlspecialchars($tahun_ajaran_filter, ENT_QUOTES, 'UTF-8') ?>">

                                <?php else: ?>
                            <div class="mb-3">
                                <?php endif; ?>

                                <div id="pu-card-area">
                                    <div class="pu-toolbar">
                                        <div class="pu-search">
                                            <i class="fas fa-search"></i>
                                            <input type="search" id="pu-search-input" placeholder="Cari nama, NISN, atau nomor ujian..." autocomplete="off">
                                        </div>
                                        <?php if ($can_crud): ?>
                                        <label class="pu-master-label mb-0">
                                            <input type="checkbox" id="pu-cb-master-lulus" class="pu-cb-master-lulus" title="Centang atau uncentang semua siswa" aria-label="Pilih semua status lulus">
                                            <span>Pilih semua lulus</span>
                                        </label>
                                        <?php endif; ?>
                                        <span class="pu-toolbar-info"><i class="fas fa-id-card mr-1"></i><span id="pu-toolbar-count"><?= (int) $jumlah_peserta_ujian ?> kartu peserta</span></span>
                                    </div>

                                    <div class="pu-card-grid" id="pu-card-grid">
                                        <?php
                                        $nomorUrut = 0;
                                        foreach ($rows as $r):
                                            $nomorUrut++;
                                            $nu = $r['nomor_ujian'] ?? '';
                                            $idS = (int) ($r['id_siswa'] ?? 0);
                                            $isL = !empty($r['is_lulus']);
                                            $pu_nama = trim((string) ($r['nama_siswa'] ?? '-'));
                                            $pu_parts = preg_split('/\s+/', $pu_nama);
                                            $pu_ini = '';
                                            foreach (array_slice($pu_parts, 0, 2) as $pu_w) {
                                                if ($pu_w !== '') $pu_ini .= strtoupper(substr($pu_w, 0, 1));
                                            }
                                            if ($pu_ini === '') $pu_ini = 'S';
                                        ?>
                                        <div class="pu-card">
                                            <div class="pu-card-top">
                                                <div class="pu-card-school">
                                                    <?php if ($pu_logo_path): ?>
                                                        <img src="<?= htmlspecialchars($pu_logo_path, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="pu-card-logo">
                                                    <?php else: ?>
                                                        <span class="pu-card-logo pu-card-logo-icon"><i class="fas fa-graduation-cap"></i></span>
                                                    <?php endif; ?>
                                                    <div>
                                                        <div class="pu-card-title">Kartu Peserta Ujian</div>
                                                        <div class="pu-card-school-name"><?= htmlspecialchars($school_profile['nama_madrasah'] ?? 'Madrasah', ENT_QUOTES, 'UTF-8') ?></div>
                                                    </div>
                                                </div>
                                                <span class="pu-card-number">#<?= (int) $nomorUrut ?> &middot; <?= htmlspecialchars($tahun_ajaran_filter, ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <div class="pu-card-body">
                                                <div class="pu-card-photo">
                                                    <?= getPersonAvatarHtml($r['foto'] ?? '', '<span class="pu-card-photo-initials">' . htmlspecialchars($pu_ini, ENT_QUOTES, 'UTF-8') . '</span>', 'pu-card-photo-img') ?>
                                                </div>
                                                <div class="pu-card-info">
                                                    <div class="pu-card-name"><?= htmlspecialchars($pu_nama, ENT_QUOTES, 'UTF-8') ?></div>

                                                    <div class="pu-card-meta">
                                                        <div class="pu-card-meta-row"><span>NISN</span><strong><?= htmlspecialchars($r['nisn'] ?? '-', ENT_QUOTES, 'UTF-8') ?></strong></div>
                                                        <div class="pu-card-meta-row"><span>No. Ujian</span><strong class="<?= $nu === '' ? 'text-muted' : '' ?>"><?= $nu !== '' ? htmlspecialchars($nu, ENT_QUOTES, 'UTF-8') : '&mdash;' ?></strong></div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="pu-card-footer">
                                                <div class="pu-card-status">
                                                    <?php if ($can_crud): ?>
                                                    <input type="checkbox" class="pu-cb-lulus" id="lu<?= $idS ?>" name="pu[<?= $idS ?>][lulus]" value="1" <?= $isL ? 'checked' : '' ?> aria-label="Status Lulus" title="Centang jika lulus">
                                                    <?php endif; ?>
                                                    <span class="pu-ket-teks badge <?= $isL ? 'badge-success' : 'badge-danger' ?>"><?= $isL ? 'Lulus' : 'Tidak lulus' ?></span>
                                                    <?php if (!empty($r['keterangan_kelulusan'])): ?>
                                                        <span class="pu-card-note"><?= htmlspecialchars($r['keterangan_kelulusan'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <a class="pu-card-print" target="_blank" rel="noopener noreferrer" href="cetak_surat_kelulusan.php?session_type=<?= urlencode($current_level) ?>&ta=<?= urlencode($tahun_ajaran_filter) ?>&id_siswa=<?= (int) $idS ?>">
                                                    <i class="fas fa-print"></i> Cetak Surat
                                                </a>
                                            </div>
                                        </div>

                                            <?php endforeach; ?>

                                    </div>
                                </div>

                                <?php if ($can_crud): ?>
                                <button type="submit" class="pu-btn-app pu-btn-app-success pu-btn-app-lg"><i class="fas fa-save"></i> Simpan Status Kelulusan</button>
                                <?php endif; ?>

                                <?php if ($can_crud): ?>
                            </form>
                                <?php else: ?>
                            </div>
                                <?php endif; ?>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </section>

</div>



<?php if ($can_crud): ?>
<div class="modal fade" id="modalJadwalKelulusan" tabindex="-1" role="dialog" aria-labelledby="modalJadwalKelulusanLabel" aria-hidden="true">

    <div class="modal-dialog" role="document">

        <div class="modal-content">

            <form method="post">

                <input type="hidden" name="simpan_jadwal_kelulusan" value="1">

                <input type="hidden" name="tahun_ajaran" value="<?= htmlspecialchars($tahun_ajaran_filter, ENT_QUOTES, 'UTF-8') ?>">

                <div class="modal-header">

                    <h5 class="modal-title" id="modalJadwalKelulusanLabel">Waktu pengumuman kelulusan</h5>

                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">

                        <span aria-hidden="true">&times;</span>

                    </button>

                </div>

                <div class="modal-body">

                    <p class="text-muted small">Tahun ajaran: <strong><?= htmlspecialchars($tahun_ajaran_filter) ?></strong></p>

                    <p class="small">Siswa hanya bisa melihat isi pengumuman setelah waktu mulai (jika diatur), selain itu harus menggunakan tombol «Tampilkan di akun siswa» pada halaman ini.</p>

                    <div class="custom-control custom-radio mb-2">

                        <input type="radio" class="custom-control-input" id="modeLangsung" name="mode_jadwal" value="langsung"<?= $jadwal_mode === 'langsung' ? ' checked' : '' ?>>

                        <label class="custom-control-label" for="modeLangsung">Tanpa jadwal penundaan waktu tayang</label>

                    </div>

                    <div class="custom-control custom-radio mb-3">

                        <input type="radio" class="custom-control-input" id="modeJadwal" name="mode_jadwal" value="jadwal"<?= $jadwal_mode === 'jadwal' ? ' checked' : '' ?>>

                        <label class="custom-control-label" for="modeJadwal">Mulai tampil pada tanggal dan jam:</label>

                    </div>

                    <div class="form-group">

                        <label for="waktuMulaiInput">Waktu mulai tayang</label>

                        <input type="datetime-local" class="form-control" id="waktuMulaiInput" name="waktu_mulai_tampil" value="<?= htmlspecialchars($jadwal_input_value, ENT_QUOTES, 'UTF-8') ?>">

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button" class="pu-btn-app pu-btn-app-sm pu-btn-app-outline" data-dismiss="modal">Batal</button>

                    <button type="submit" class="pu-btn-app pu-btn-app-sm pu-btn-app-primary"><i class="fas fa-save"></i> Simpan Jadwal</button>

                </div>

            </form>

        </div>

    </div>

</div>
<?php endif; ?>



<script>

(function() {

    var modeJ = document.getElementById('modeJadwal');

    var modeL = document.getElementById('modeLangsung');

    var inp = document.getElementById('waktuMulaiInput');

    function sync() {

        if (!inp) return;

        inp.disabled = modeL && modeL.checked;

    }

    if (modeJ) modeJ.addEventListener('change', sync);

    if (modeL) modeL.addEventListener('change', sync);

    sync();

})();



document.addEventListener('DOMContentLoaded', function() {

    var area = document.getElementById('pu-card-area');

    if (!area) {

        return;

    }



    function puApplyCheckboxToRow(cb) {

        if (!cb) {

            return;

        }

        var card = cb.closest('.pu-card');

        var span = card && card.querySelector('.pu-ket-teks');

        if (!span) {

            return;

        }

        if (cb.checked) {

            span.textContent = 'Lulus';

            span.className = 'pu-ket-teks badge badge-success';

        } else {

            span.textContent = 'Tidak lulus';

            span.className = 'pu-ket-teks badge badge-danger';

        }

    }



    function puSyncMasterCheckbox() {

        var master = document.getElementById('pu-cb-master-lulus');

        if (!master) {

            return;

        }

        var boxes = area.querySelectorAll('.pu-cb-lulus');

        if (!boxes.length) {

            master.checked = false;

            master.indeterminate = false;

            return;

        }

        var total = boxes.length;

        var checked = 0;

        for (var i = 0; i < boxes.length; i++) {

            if (boxes[i].checked) {

                checked++;

            }

        }

        master.checked = checked === total;

        master.indeterminate = checked > 0 && checked < total;

    }



    area.addEventListener('change', function(e) {

        var t = e.target;

        if (t && t.id === 'pu-cb-master-lulus') {

            var on = !!t.checked;

            area.querySelectorAll('.pu-cb-lulus').forEach(function(cb) {

                cb.checked = on;

                puApplyCheckboxToRow(cb);

            });

            t.indeterminate = false;

            return;

        }

        if (!t || !t.classList.contains('pu-cb-lulus')) {

            return;

        }

        puApplyCheckboxToRow(t);

        puSyncMasterCheckbox();

    });



    puSyncMasterCheckbox();

});



document.addEventListener('DOMContentLoaded', function() {

    var fg = document.getElementById('form-generate-nomor-ujian');

    if (!fg) {

        return;

    }

    var msg = 'Generate ulang nomor ujian untuk semua siswa kelas VI pada tahun ajaran yang dipilih? Nomor lama untuk tahun ini akan diganti.';

    fg.addEventListener('submit', function(e) {

        e.preventDefault();

        if (typeof Swal === 'undefined') {

            if (window.confirm(msg)) {

                fg.submit();

            }

            return;

        }

        Swal.fire({

            title: 'Konfirmasi generate',

            text: msg,

            icon: 'warning',

            showCancelButton: true,

            confirmButtonColor: '#6777ef',

            cancelButtonColor: '#6c757d',

            confirmButtonText: 'Ya, generate',

            cancelButtonText: 'Batal',

            reverseButtons: true

        }).then(function(result) {

            if (result.value || result.isConfirmed) {

                fg.submit();

            }

        });

    });

});

document.addEventListener('DOMContentLoaded', function() {
    var input = document.getElementById('pu-search-input');
    var grid = document.getElementById('pu-card-grid');
    if (!input || !grid) {
        return;
    }
    var cards = Array.prototype.slice.call(grid.querySelectorAll('.pu-card'));
    var countEl = document.getElementById('pu-toolbar-count');
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
        }
    });
    input.addEventListener('input', function() {
        var q = input.value.trim().toLowerCase();
        var visible = 0;
        cards.forEach(function(card) {
            var match = q === '' || card.innerText.toLowerCase().indexOf(q) !== -1;
            card.style.display = match ? '' : 'none';
            if (match) {
                visible++;
            }
        });
        if (countEl) {
            countEl.textContent = visible + ' kartu peserta';
        }
    });
});

document.addEventListener('DOMContentLoaded', function() {
    var tanggalSuratSudahDiatur = <?= $tanggal_surat_kelulusan ? 'true' : 'false' ?>;
    if (tanggalSuratSudahDiatur) {
        return;
    }
    var backUrl = 'data_peserta_ujian.php?ta=' + encodeURIComponent(<?= json_encode((string) $tahun_ajaran_filter) ?>);
    document.addEventListener('click', function(e) {
        var link = e.target && e.target.closest ? e.target.closest('a[href*="cetak_surat_kelulusan.php"]') : null;
        if (!link) {
            return;
        }
        e.preventDefault();
        if (typeof Swal === 'undefined') {
            window.location.href = link.href;
            return;
        }
        Swal.fire({
            icon: 'warning',
            title: 'Tanggal surat belum diatur',
            html: 'Isi <strong>tanggal surat kelulusan</strong> terlebih dahulu agar surat dapat dicetak.',
            showCancelButton: true,
            confirmButtonText: 'Atur Sekarang',
            cancelButtonText: 'Tutup',
            confirmButtonColor: '#6777ef',
            cancelButtonColor: '#94a3b8',
            reverseButtons: true
        }).then(function(result) {
            if (!result.isConfirmed) {
                return;
            }
            var el = document.getElementById('tanggal_surat_kelulusan');
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                el.focus();
            } else {
                window.location.href = backUrl;
            }
        });
    });
});
</script>



<?php

require_once '../templates/footer.php';

