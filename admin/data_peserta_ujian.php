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

            foreach ($payload as $idKey => $data) {

                $id_siswa = (int) $idKey;

                if ($id_siswa <= 0 || !isset($ids_ok[$id_siswa])) {

                    continue;

                }

                if (!is_array($data)) {

                    continue;

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



$sql = 'SELECT s.id_siswa, s.nisn, s.nama_siswa, pu.nomor_ujian, pu.is_lulus, pu.keterangan_kelulusan

    FROM tb_peserta_ujian pu

    INNER JOIN tb_siswa s ON s.id_siswa = pu.id_siswa

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



require_once '../templates/header.php';

require_once '../templates/sidebar.php';

?>



<div class="main-content">

    <section class="section">

        <div class="section-header">

            <h1><?= htmlspecialchars($page_title) ?></h1>

            <div class="section-header-breadcrumb">

                <div class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></div>

                <div class="breadcrumb-item"><a href="#">Master Data</a></div>

                <div class="breadcrumb-item"><?= htmlspecialchars($page_title) ?></div>

                <div class="breadcrumb-item">Tahun Ajaran <?= htmlspecialchars($tahun_ajaran_filter) ?></div>

            </div>

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

                            <h4 class="mb-0">Data Peserta Ujian Kelas VI — Tahun Ajaran <?= htmlspecialchars($tahun_ajaran_filter) ?>

                                <span class="badge badge-info align-middle ml-2" title="Jumlah peserta untuk tahun ajaran ini"><?= (int) $jumlah_peserta_ujian ?> peserta</span>

                            </h4>

                            <div class="card-header-action d-flex flex-wrap align-items-center justify-content-end">

                                <?php if ($can_crud): ?>
                                <form method="post" class="mb-2 mb-md-0 mr-2">

                                    <input type="hidden" name="simpan_toggle_tampil_akun_siswa" value="1">

                                    <input type="hidden" name="tahun_ajaran" value="<?= htmlspecialchars($tahun_ajaran_filter, ENT_QUOTES, 'UTF-8') ?>">

                                    <input type="hidden" name="aktif_global" value="<?= $tampil_global_siswa ? '0' : '1' ?>">

                                    <button type="submit" class="btn btn-sm <?= $tampil_global_siswa ? 'btn-warning' : 'btn-outline-success' ?>">

                                        <?= $tampil_global_siswa ? 'Sembunyikan dari akun siswa' : 'Tampilkan di akun siswa' ?>

                                    </button>

                                </form>
                                <?php endif; ?>

                                <?php if ($can_crud): ?>
                                <button type="button" class="btn btn-outline-secondary btn-sm mr-2 mb-2 mb-md-0" data-toggle="modal" data-target="#modalJadwalKelulusan">

                                    <i class="fas fa-clock mr-1"></i> Atur waktu pengumuman

                                </button>
                                <?php endif; ?>

                                <form method="get" class="form-inline mb-0">

                                    <label class="mr-2 mb-0 d-none d-md-inline-block">Filter Tahun Ajaran</label>

                                    <select name="ta" class="form-control mr-2 mb-2 mb-md-0" onchange="this.form.submit()">

                                        <?php foreach ($tahun_ajaran_options as $optTa): ?>

                                        <option value="<?= htmlspecialchars($optTa) ?>"<?= ($tahun_ajaran_filter === $optTa) ? ' selected' : '' ?>><?= htmlspecialchars($optTa) ?></option>

                                        <?php endforeach; ?>

                                    </select>

                                </form>

                            </div>

                        </div>

                        <div class="card-body">

                            <?php if ($can_crud): ?>
                            <p class="text-muted small mb-3">

                                <strong>Jadwal &amp; tayang (TA <?= htmlspecialchars($tahun_ajaran_filter) ?>):</strong>

                                <?php if ($tampil_global_siswa): ?>

                                    Pengaturan <span class="text-success font-weight-bold">tampil di akun siswa</span> sedang aktif.

                                <?php else: ?>

                                    Pengaturan <span class="text-secondary font-weight-bold">tampilan di akun siswa dinonaktifkan</span> — siswa tidak melihat info kelulusan hingga Anda menekan tombol «Tampilkan di akun siswa».

                                <?php endif; ?>

                                <?php if ($jadwal_waktu): ?>

                                    Waktu mulai tayang konten pengumuman: <strong><?= htmlspecialchars((string) $jadwal_waktu) ?></strong> (server). Sebelum itu, siswa hanya melihat penundaan (jika tampilan global aktif).

                                <?php else: ?>

                                    Tanpa jadwal penundaan: jika tampilan global aktif, isi pengumuman langsung bisa tampil (setelah Anda simpan status lulus tiap siswa).

                                <?php endif; ?>

                            </p>
                            <?php endif; ?>

                            <?php if ($can_crud): ?>
                            <div class="card border mb-4 bg-light">

                                <div class="card-body pb-4">

                                    <h5 class="mb-3 text-dark"><i class="fas fa-file-signature mr-2"></i>Cetak Surat Kelulusan (TA <?= htmlspecialchars($tahun_ajaran_filter) ?>)</h5>

                                    <p class="text-muted small mb-3">Atur tanggal pada surat (<strong>bukan otomatis tanggal cetak</strong>) dan tempat kota surat untuk halaman cetak siswa.</p>

                                    <form method="post" class="">

                                        <input type="hidden" name="simpan_surat_kelulusan" value="1">

                                        <input type="hidden" name="tahun_ajaran" value="<?= htmlspecialchars($tahun_ajaran_filter, ENT_QUOTES, 'UTF-8') ?>">

                                        <div class="row">

                                            <div class="col-md-4">

                                                <div class="form-group mb-3">

                                                    <label for="tanggal_surat_kelulusan">Tanggal surat</label>

                                                    <input type="date" class="form-control" id="tanggal_surat_kelulusan" name="tanggal_surat_kelulusan" value="<?= htmlspecialchars($tanggal_surat_form_value, ENT_QUOTES, 'UTF-8') ?>">

                                                </div>

                                            </div>

                                            <div class="col-md-4">

                                                <div class="form-group mb-3">

                                                    <label for="kota_surat">Kota pada tanggal surat</label>

                                                    <input type="text" class="form-control" id="kota_surat" name="kota_surat" value="<?= htmlspecialchars($kota_surat_kelulusan, ENT_QUOTES, 'UTF-8') ?>" placeholder="Contoh: Jepara" maxlength="80">

                                                </div>

                                            </div>

                                        </div>

                                        <button type="submit" class="btn btn-secondary"><i class="fas fa-save mr-1"></i> Simpan pengaturan surat</button>
                                        <a class="btn btn-outline-primary ml-2" target="_blank" rel="noopener noreferrer" href="cetak_surat_kelulusan.php?session_type=<?= urlencode($current_level) ?>&ta=<?= urlencode($tahun_ajaran_filter) ?>&mode=all"><i class="fas fa-print mr-1"></i> Cetak Semua</a>

                                        <?php if ($tanggal_surat_kelulusan): ?>

                                            <span class="text-muted small ml-2 align-middle">Tersimpan : <?= htmlspecialchars(formatDateIndonesia((string) $tanggal_surat_kelulusan), ENT_QUOTES, 'UTF-8') ?></span>

                                        <?php endif; ?>

                                    </form>

                                </div>

                            </div>
                            <?php endif; ?>



                            <?php if ($can_crud): ?>
                            <form method="post" class="mb-3" id="form-generate-nomor-ujian">

                                <input type="hidden" name="generate_nomor_semua" value="1">

                                <input type="hidden" name="tahun_ajaran" value="<?= htmlspecialchars($tahun_ajaran_filter, ENT_QUOTES, 'UTF-8') ?>">

                                <button type="submit" class="btn btn-primary"><i class="fas fa-sync-alt mr-1"></i> Generate Nomor Ujian</button>

                            </form>
                            <?php endif; ?>



                            <?php if (empty($rows)): ?>

                            <p class="text-muted mb-0">Belum ada peserta tersimpan untuk tahun ajaran ini (0 peserta). Pilih tahun ajaran yang sesuai, lalu klik <strong>Generate Nomor Ujian</strong> (data mengikuti siswa kelas VI saat ini).</p>

                            <?php else: ?>

                            <form method="post" class="mb-3">

                                <input type="hidden" name="simpan_kelulusan" value="1">

                                <input type="hidden" name="tahun_ajaran" value="<?= htmlspecialchars($tahun_ajaran_filter, ENT_QUOTES, 'UTF-8') ?>">

                                <div class="table-responsive">

                                    <table class="table table-striped table-bordered mb-3" id="table-peserta-ujian" style="width:100%">

                                        <thead>

                                            <tr>

                                                <th style="width:48px;">No</th>

                                                <th>NISN</th>

                                                <th>Nomor Ujian</th>

                                                <th>Nama Siswa</th>

                                                <th class="text-center align-middle" style="min-width:96px;">
                                                    <div class="small font-weight-bold mb-1">Status Lulus</div>
                                                    <?php if ($can_crud): ?>
                                                    <input type="checkbox" id="pu-cb-master-lulus" class="pu-cb-master-lulus" title="Centang atau uncentang semua siswa" aria-label="Pilih semua status lulus">
                                                    <?php endif; ?>
                                                </th>

                                                <th style="min-width:120px;">Keterangan</th>
                                                <th style="min-width:120px;">Aksi</th>

                                            </tr>

                                        </thead>

                                        <tbody>

                                            <?php

                                            $nomorUrut = 0;

foreach ($rows as $r):

    $nomorUrut++;

    $nu = $r['nomor_ujian'] ?? '';

    $idS = (int) ($r['id_siswa'] ?? 0);

    $isL = !empty($r['is_lulus']);

                                            ?>

                                            <tr>

                                                <td class="align-middle"><?= $nomorUrut ?></td>

                                                <td class="align-middle"><?= htmlspecialchars($r['nisn'] ?? '-') ?></td>

                                                <td class="align-middle font-weight-bold text-dark"><?php if ($nu !== ''): ?><?= htmlspecialchars($nu) ?><?php else: ?><span class="text-muted font-weight-normal">—</span><?php endif; ?></td>

                                                <td class="align-middle"><?= htmlspecialchars($r['nama_siswa'] ?? '-') ?></td>

                                                <td class="align-middle text-center">
                                                    <?php if ($can_crud): ?>
                                                    <input type="checkbox" class="pu-cb-lulus" id="lu<?= $idS ?>" name="pu[<?= $idS ?>][lulus]" value="1" <?= $isL ? 'checked' : '' ?> aria-label="Status Lulus" title="Centang jika lulus">
                                                    <?php else: ?>
                                                    <span class="pu-ket-teks badge <?= $isL ? 'badge-success' : 'badge-danger' ?>"><?= $isL ? 'Lulus' : 'Tidak lulus' ?></span>
                                                    <?php endif; ?>
                                                </td>

                                                <td class="align-middle">

                                                    <span class="pu-ket-teks badge <?= $isL ? 'badge-success' : 'badge-danger' ?>"><?= $isL ? 'Lulus' : 'Tidak lulus' ?></span>

                                                </td>
                                                <td class="align-middle">
                                                    <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener noreferrer" href="cetak_surat_kelulusan.php?session_type=<?= urlencode($current_level) ?>&ta=<?= urlencode($tahun_ajaran_filter) ?>&id_siswa=<?= (int) $idS ?>">
                                                        <i class="fas fa-print mr-1"></i> Cetak
                                                    </a>
                                                </td>

                                            </tr>

                                            <?php endforeach; ?>

                                        </tbody>

                                    </table>

                                </div>

                                <?php if ($can_crud): ?>
                                <button type="submit" class="btn btn-success"><i class="fas fa-save mr-1"></i> Simpan status kelulusan</button>

                                <small class="text-muted d-block mt-2">Status Lulus tiap siswa serta teks «Lulus/Tidak lulus» ikut disimpan. Tampilan ke akun siswa diatur dengan satu tombol di bagian atas.</small>
                                <?php endif; ?>

                            </form>

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

                    <button type="button" class="btn btn-light" data-dismiss="modal">Batal</button>

                    <button type="submit" class="btn btn-primary">Simpan jadwal</button>

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

    var tbl = document.getElementById('table-peserta-ujian');

    if (!tbl) {

        return;

    }



    function puApplyCheckboxToRow(cb) {

        if (!cb) {

            return;

        }

        var tr = cb.closest('tr');

        var span = tr && tr.querySelector('.pu-ket-teks');

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

        var boxes = tbl.querySelectorAll('tbody .pu-cb-lulus');

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



    tbl.addEventListener('change', function(e) {

        var t = e.target;

        if (t && t.id === 'pu-cb-master-lulus') {

            var on = !!t.checked;

            tbl.querySelectorAll('tbody .pu-cb-lulus').forEach(function(cb) {

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

</script>



<?php

require_once '../templates/footer.php';

