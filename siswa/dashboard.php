<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

// Check if user is logged in and has siswa level
if (!isAuthorized(['siswa'])) {
    redirect('../login.php');
}

// Get student data
$id_siswa = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT s.*, k.nama_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_siswa = ?");
$stmt->execute([$id_siswa]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

$is_grade_6 = false;
if ($student && $student['nama_kelas']) {
    $cls_name = strtoupper($student['nama_kelas']);
    if (strpos($cls_name, '6') !== false || strpos($cls_name, 'VI') !== false) {
        $is_grade_6 = true;
    }
}

if (!$student) {
    echo "Data siswa tidak ditemukan.";
    exit;
}

// Handle AJAX baca pesan komunikasi ortu
$student_class_id = (int)($student['id_kelas'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'baca_pesan') {
    header('Content-Type: application/json; charset=UTF-8');
    $pesan_id = (int)($_POST['pesan_id'] ?? 0);
    if ($pesan_id > 0 && $id_siswa > 0) {
        try {
            $pdo->prepare("UPDATE tb_komunikasi_ortu SET status_dibaca = 'Sudah Dibaca' WHERE id = ? AND (id_siswa = ? OR (id_kelas = ? AND jenis_informasi = 'Pengumuman Kelas'))")->execute([$pesan_id, $id_siswa, $student_class_id]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

// Set page title
$page_title = 'Dashboard Siswa';

$tagihan_summary_response = !empty($student['nisn']) ? fetchPembayaranData((string)$student['nisn'], 'tagihan') : ['status' => 'error'];
$tagihan_summary = is_array($tagihan_summary_response['summary'] ?? null) ? $tagihan_summary_response['summary'] : [];
$tagihan_total_aktif = (int)($tagihan_summary['total_tunggakan_aktif'] ?? 0);
$tagihan_total_lama = (int)($tagihan_summary['total_tunggakan_tahun_lama'] ?? 0);
$tagihan_total_semua = (int)($tagihan_summary['total_tunggakan'] ?? ($tagihan_total_aktif + $tagihan_total_lama));
$tagihan_tahun_aktif = (string)($tagihan_summary_response['tahun_ajaran'] ?? $tagihan_summary_response['tahun_ajaran_aktif'] ?? '-');
$tagihan_tahun_lama = $tagihan_summary['tahun_ajaran_tunggakan'] ?? [];
$tagihan_tahun_lama = is_array($tagihan_tahun_lama) ? $tagihan_tahun_lama : [];
$tagihan_status_ok = ($tagihan_summary_response['status'] ?? 'error') === 'success';

// Ringkasan tabungan siswa (modul Tabungan SIMAD)
require_once __DIR__ . '/../bendahara/tabungan/config/schema.php';
require_once __DIR__ . '/../bendahara/tabungan/config/helpers.php';
$tab_nis = trim((string)($student['nisn'] ?? $student['nis'] ?? ''));
$tab_ok = true;
$tab_saldo = 0;
$tab_setor = 0;
$tab_tarik = 0;
if ($tab_nis !== '') {
    try {
        tabungan_ensure_schema($pdo);
        $st = $pdo->prepare("SELECT COALESCE(SUM(setor),0) AS s, COALESCE(SUM(tarik),0) AS t FROM tb_tabungan WHERE nisn = ?");
        $st->execute([$tab_nis]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $tab_setor = (int)($row['s'] ?? 0);
        $tab_tarik = (int)($row['t'] ?? 0);
        $tab_saldo = $tab_setor - $tab_tarik;
    } catch (Throwable $e) {
        $tab_ok = false;
    }
}

// Get today's attendance status
$today = date('Y-m-d');
$holiday = isSchoolHoliday($pdo, $today);
$stmt = $pdo->prepare("SELECT * FROM tb_absensi WHERE id_siswa = ? AND tanggal = ?");
$stmt->execute([$id_siswa, $today]);
$attendance = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle Manual Attendance - Simple Click Like Guru
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['absen_status'])) {
        $status = $_POST['absen_status']; // Hadir, Sakit, Izin
        $keterangan = '';
        
        // Only get keterangan if status is Izin
        if ($status === 'Izin') {
            $keterangan = $_POST['keterangan'] ?? '';
        }
        
        $holiday = isSchoolHoliday($pdo, $today);
        if ($holiday['is_holiday']) {
            $swal_message = [
                'title' => 'Hari Libur',
                'text' => 'Kehadiran ditutup pada hari libur: ' . $holiday['name'],
                'icon' => 'warning'
            ];
        } else {
            // Always allow update (INSERT or UPDATE)
            $prev_status = $attendance ? (string)$attendance['keterangan'] : '';
            $jam_masuk = $attendance ? $attendance['jam_masuk'] : date('H:i:s');
            
            // Validasi keterlambatan: kehadiran dari dashboard siswa yang melebihi 07:15 dinyatakan terlambat
            if ($status === 'Hadir') {
                $jam_cek = $jam_masuk ? strtotime($jam_masuk) : time();
                if ($jam_cek > strtotime('07:15:00')) {
                    $status = 'Terlambat';
                }
            }
            if (in_array($status, ['Hadir', 'Terlambat']) && empty($jam_masuk)) {
                $jam_masuk = date('H:i:s');
            }
            
            if ($attendance) {
                // Update existing attendance
                $stmt = $pdo->prepare("UPDATE tb_absensi SET keterangan = ?, jam_masuk = ? WHERE id_siswa = ? AND tanggal = ?");
                if ($stmt->execute([$status, $jam_masuk, $id_siswa, $today])) {
                    $swal_message = [
                        'title' => ($status === 'Terlambat') ? 'Maaf, Anda Terlambat!' : 'Berhasil!',
                        'text' => ($status === 'Terlambat') ? 'Maaf anda terlambat. Status kehadiran berhasil diubah!' : 'Status kehadiran berhasil diubah!',
                        'icon' => 'success'
                    ];
                    // Refresh attendance data
                    $stmt = $pdo->prepare("SELECT * FROM tb_absensi WHERE id_siswa = ? AND tanggal = ?");
                    $stmt->execute([$id_siswa, $today]);
                    $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $swal_message = [
                        'title' => 'Gagal!',
                        'text' => 'Gagal memperbarui kehadiran!',
                        'icon' => 'error'
                    ];
                }
            } else {
                // Insert new attendance
                $stmt = $pdo->prepare("INSERT INTO tb_absensi (id_siswa, tanggal, jam_masuk, keterangan) VALUES (?, ?, ?, ?)");
                if ($stmt->execute([$id_siswa, $today, $jam_masuk, $status])) {
                    $swal_message = [
                        'title' => ($status === 'Terlambat') ? 'Maaf, Anda Terlambat!' : 'Berhasil!',
                        'text' => ($status === 'Terlambat') ? 'Maaf anda terlambat. Kehadiran berhasil disimpan!' : 'Kehadiran berhasil disimpan!',
                        'icon' => 'success'
                    ];
                    // Refresh attendance data
                    $stmt = $pdo->prepare("SELECT * FROM tb_absensi WHERE id_siswa = ? AND tanggal = ?");
                    $stmt->execute([$id_siswa, $today]);
                    $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $swal_message = [
                        'title' => 'Gagal!',
                        'text' => 'Gagal menyimpan kehadiran!',
                        'icon' => 'error'
                    ];
                }
            }

            // Notifikasi kehadiran siswa mengisi sendiri -> guru & wali
            if (!empty($swal_message['icon']) && $swal_message['icon'] === 'success'
                && $status !== '' && $status !== $prev_status
                && function_exists('simadNotifKehadiranHarian')) {
                simadNotifKehadiranHarian($pdo, $id_siswa, $today, $status, 'siswa');
            }
        }
    } elseif (isset($_POST['submit_keterangan'])) {
        // Only update keterangan for Izin status
        $keterangan = $_POST['keterangan'] ?? '';
        if ($attendance && $attendance['keterangan'] === 'Izin') {
            $stmt = $pdo->prepare("UPDATE tb_absensi SET keterangan = ? WHERE id_siswa = ? AND tanggal = ?");
            if ($stmt->execute(['Izin', $id_siswa, $today])) {
                $swal_message = [
                    'title' => 'Berhasil!',
                    'text' => 'Keterangan berhasil disimpan!',
                    'icon' => 'success'
                ];
                $stmt = $pdo->prepare("SELECT * FROM tb_absensi WHERE id_siswa = ? AND tanggal = ?");
                $stmt->execute([$id_siswa, $today]);
                $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
    }
}

// Ambil pemberitahuan tugas aktif untuk siswa
$student_class_id = (int)($student['id_kelas'] ?? 0);
$student_tasks = [];
$total_tugas_belum = 0;
if ($student_class_id > 0) {
    try {
        $stTugas = $pdo->prepare("
            SELECT t.*, m.nama_mapel, g.nama_guru,
                   tp.id AS id_pengumpulan, tp.tgl_kumpul, tp.nilai, tp.status_periksa, tp.feedback
            FROM tb_tugas t
            LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = t.id_mapel
            LEFT JOIN tb_guru g ON g.id_guru = t.id_guru
            LEFT JOIN tb_tugas_pengumpulan tp ON tp.id_tugas = t.id AND tp.id_siswa = ?
            WHERE t.id_kelas = ? AND t.status = 'Aktif'
            ORDER BY (tp.id IS NULL) DESC, t.deadline ASC, t.id DESC
            LIMIT 5
        ");
        $stTugas->execute([$id_siswa, $student_class_id]);
        $student_tasks = $stTugas->fetchAll(PDO::FETCH_ASSOC);

        foreach ($student_tasks as $tk) {
            if (empty($tk['id_pengumpulan'])) {
                $total_tugas_belum++;
            }
        }
    } catch (Throwable $e) {}
}

// Ambil pesan komunikasi ortu / kelas untuk siswa
$student_messages = [];
$total_pesan_belum_baca = 0;
if ($student_class_id > 0 && $id_siswa > 0) {
    try {
        ensure_learning_schema($pdo);
        $stPesan = $pdo->prepare("
            SELECT * FROM (
                SELECT
                    k.id,
                    'ortu' AS msg_type,
                    k.tanggal,
                    COALESCE(k.tanggal_mulai, k.tanggal) AS tanggal_mulai,
                    COALESCE(k.tanggal_selesai, COALESCE(k.tanggal_mulai, k.tanggal)) AS tanggal_selesai,
                    k.waktu_mulai,
                    k.waktu_selesai,
                    k.created_at,
                    COALESCE(g.nama_guru, 'Wali Kelas') AS pengirim,
                    g.nama_guru,
                    k.jenis_informasi,
                    k.judul,
                    k.isi,
                    NULL AS lampiran,
                    k.nama_ortu,
                    k.status_dibaca
                FROM tb_komunikasi_ortu k
                LEFT JOIN tb_guru g ON g.id_guru = k.id_wali
                WHERE (k.id_siswa = :sid1 OR (k.id_kelas = :cid1 AND k.jenis_informasi = 'Pengumuman Kelas'))
                  AND k.status_kirim = 'Terkirim'

                UNION ALL

                SELECT
                    k.id,
                    'kelas' AS msg_type,
                    k.tanggal,
                    COALESCE(k.tanggal_mulai, k.tanggal) AS tanggal_mulai,
                    COALESCE(k.tanggal_selesai, COALESCE(k.tanggal_mulai, k.tanggal)) AS tanggal_selesai,
                    k.waktu_mulai,
                    k.waktu_selesai,
                    k.created_at,
                    COALESCE(g.nama_guru, 'Guru Mapel') AS pengirim,
                    g.nama_guru,
                    CONCAT('Komunikasi Guru (', k.jenis, ')') AS jenis_informasi,
                    k.judul,
                    k.isi,
                    k.lampiran,
                    NULL AS nama_ortu,
                    IF(kr.id IS NOT NULL, 'Sudah Dibaca', 'Belum Dibaca') AS status_dibaca
                FROM tb_komunikasi_kelas k
                LEFT JOIN tb_guru g ON g.id_guru = k.id_guru
                LEFT JOIN tb_komunikasi_kelas_read kr ON kr.id_komunikasi = k.id AND kr.id_siswa = :sid2
                WHERE k.id_kelas = :cid2
                  AND k.status = 'Terkirim'
            ) AS combined_msg
            ORDER BY tanggal DESC, id DESC
            LIMIT 15
        ");
        $stPesan->execute([
            ':sid1' => $id_siswa, ':cid1' => $student_class_id,
            ':sid2' => $id_siswa, ':cid2' => $student_class_id,
        ]);
        $student_messages = $stPesan->fetchAll(PDO::FETCH_ASSOC);

        foreach ($student_messages as $msg) {
            if (($msg['status_dibaca'] ?? '') === 'Belum Dibaca') {
                $total_pesan_belum_baca++;
            }
        }
    } catch (Throwable $e) {}
}

include '../templates/header.php';
include_once '../templates/sidebar.php';
?>

            <div class="main-content">
                <?php include '../templates/mobile_navbar.php'; ?>
                <?php
                $mapp_greet_name = (isset($student) && is_array($student) && !empty($student['nama_siswa'])) ? $student['nama_siswa'] : 'Siswa';
                $mapp_greet_role = 'Siswa' . (!empty($student['nama_kelas']) ? ' • Kelas ' . $student['nama_kelas'] : '');
                include '../templates/mobile_greeting.php';
                ?>

                <section class="section">
        <div class="section-header">
            <h1>Dashboard Siswa</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="#">Dashboard</a></div>
            </div>
        </div>

        <div class="row d-lg-none">
            <div class="col-12 mb-3">
                <div class="card border-0 shadow-sm" style="border-radius: 16px; overflow: hidden; background: linear-gradient(135deg, #2f6ef0 0%, #3abaf4 100%); color: #fff; position: relative;">
                    <div style="position: absolute; top: -20px; right: -20px; width: 140px; height: 140px; border-radius: 50%; background: rgba(255, 255, 255, 0.12); pointer-events: none;"></div>
                    <div style="position: absolute; bottom: -30px; right: 50px; width: 120px; height: 120px; border-radius: 50%; background: rgba(255, 255, 255, 0.06); pointer-events: none;"></div>
                    <div class="card-body p-3" style="position: relative; z-index: 1;">
                        <div class="d-flex flex-column align-items-center text-center">
                            <?php
                            $mob_foto = !empty($student['foto']) && file_exists('../assets/img/siswa/' . $student['foto'])
                                ? '../assets/img/siswa/' . rawurlencode($student['foto'])
                                : '';
                            ?>
                            <div class="rounded-circle bg-white text-primary d-flex align-items-center justify-content-center mb-2 flex-shrink-0" style="width: 96px; height: 96px; font-weight: 700; font-size: 2.2rem; overflow: hidden; box-shadow: 0 4px 12px rgba(0, 0, 0, .2); border: 3px solid #fff;">
                                <?php if ($mob_foto): ?>
                                    <img src="<?php echo $mob_foto; ?>" alt="<?php echo htmlspecialchars($student['nama_siswa']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($student['nama_siswa'], 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="mb-1 text-white" style="font-size: 1.25rem; font-weight: 700; text-shadow: 0 1px 3px rgba(0,0,0,0.35);">Assalamualaikum, <span class="font-weight-bold" style="font-size: 1.45rem; color: #ffffff; text-shadow: 0 1px 3px rgba(0,0,0,0.4);"><?php echo htmlspecialchars($student['nama_siswa']); ?></span></div>
                                <div style="color: #ffffff; font-size: 1.15rem; font-weight: 700; text-shadow: 0 1px 2px rgba(0,0,0,0.35);">Selamat datang di Sistem Informasi Madrasah</div>
                                <div style="color: #fde047; font-size: 1.1rem; font-weight: 800; text-shadow: 0 1px 2px rgba(0,0,0,0.4); margin-top: 2px;"><?php echo htmlspecialchars($school_profile['nama_madrasah'] ?? ''); ?></div>
                                <div class="mt-3 d-flex flex-wrap justify-content-center" style="gap:4px;">
                                    <span class="wb-chip wb-chip-glass" style="font-size: 0.95rem !important; padding: 6px 14px;"><i class="far fa-calendar-alt mr-1"></i> <span id="wb-date">-</span></span>
                                    <span class="wb-chip wb-chip-glass" style="font-size: 0.95rem !important; padding: 6px 14px;"><i class="fas fa-graduation-cap mr-1"></i> <?php echo htmlspecialchars($school_profile['tahun_ajaran'] ?? '-'); ?></span>
                                    <span class="wb-chip wb-chip-glass" style="font-size: 0.95rem !important; padding: 6px 14px;"><i class="fas fa-calendar-check mr-1"></i> <?php echo htmlspecialchars($school_profile['semester'] ?? '-'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row d-none d-lg-flex">
            <!-- Banner Identitas Siswa (desktop saja, mobile disembunyikan) -->
            <div class="col-12 mb-4">
                <?php
                $dash_foto = !empty($student['foto']) && file_exists('../assets/img/siswa/' . $student['foto'])
                    ? '../assets/img/siswa/' . $student['foto']
                    : null;
                $dash_inisial = strtoupper(substr((string)($student['nama_siswa'] ?? 'S'), 0, 1));
                ?>
                <div class="card border-0 shadow-sm" style="border-radius:18px;overflow:hidden;background:linear-gradient(135deg,#6777ef 0%,#3abaf4 100%);color:#fff;">
                    <div class="card-body p-4">
                        <div class="d-flex flex-column flex-md-row align-items-center" style="gap:20px;">
                            <div class="flex-shrink-0">
                                <?php if ($dash_foto): ?>
                                    <img alt="foto" src="<?php echo $dash_foto; ?>" class="rounded-circle shadow" style="width:90px;height:90px;object-fit:cover;border:4px solid #fff;">
                                <?php else: ?>
                                    <div class="rounded-circle bg-white text-primary d-flex align-items-center justify-content-center shadow" style="width:90px;height:90px;font-weight:800;font-size:2.2rem;">
                                        <?php echo $dash_inisial; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="text-center text-md-left flex-grow-1">
                                <h4 class="font-weight-bold text-white mb-1"><?php echo htmlspecialchars($student['nama_siswa']); ?></h4>
                                <div class="mb-2">
                                    <span class="badge badge-light px-3 py-2 mr-1"><i class="fas fa-chalkboard mr-1"></i>Kelas <?php echo htmlspecialchars($student['nama_kelas'] ?? '-'); ?></span>
                                    <?php if (!empty($student['jenis_kelamin'])): ?>
                                        <span class="badge px-3 py-2 <?php echo $student['jenis_kelamin'] == 'L' ? 'badge-info' : 'badge-danger'; ?>">
                                            <i class="fas fa-<?php echo $student['jenis_kelamin'] == 'L' ? 'mars' : 'venus'; ?> mr-1"></i>
                                            <?php echo $student['jenis_kelamin'] == 'L' ? 'Laki-laki' : 'Perempuan'; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div style="color:#f1f5f9;font-size:13px;">
                                    NISN <?php echo htmlspecialchars($student['nisn'] ?? '-'); ?>
                                </div>
                            </div>
                            <div class="flex-shrink-0">
                                <a href="profil.php" class="btn btn-light btn-sm font-weight-bold px-3" style="border-radius: 20px; color:#6777ef;">
                                    <i class="fas fa-user mr-1"></i>Profil Saya
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Box Kehadiran Siswa Ala Mobile Apps -->
            <div class="col-12 mb-4 mapp-col-absen">
                <div class="card w-100 mapp-absen-card border-0 shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                        <h4 class="mb-0"><i class="fas fa-user-check text-primary mr-2"></i>Kehadiran Hari Ini</h4>
                        <span class="badge badge-light border text-muted font-weight-bold"><?php echo getCurrentDateIndonesia(); ?></span>
                    </div>
                    <div class="card-body">
                        <?php if (!$holiday['is_holiday']): ?>
                            <?php
                            $status_siswa = $attendance ? $attendance['keterangan'] : '';
                            $jam_masuk_siswa = ($attendance && !empty($attendance['jam_masuk'])) ? date('H.i', strtotime($attendance['jam_masuk'])) . ' WIB' : '';

                            $hero_cls = 'is-empty';
                            $hero_icon = 'fas fa-hourglass-half';
                            $hero_title = 'Belum Mengisi Kehadiran';
                            $hero_sub = 'Silakan pilih status kehadiran Anda hari ini.';

                            if ($status_siswa === 'Hadir') {
                                $hero_cls = 'is-hadir';
                                $hero_icon = 'fas fa-check-circle';
                                $hero_title = 'Hadir - Tepat Waktu';
                                $hero_sub = $jam_masuk_siswa !== '' ? 'Kehadiran dicatat pukul ' . $jam_masuk_siswa : 'Kehadiran Anda sudah tercatat tepat waktu.';
                            } elseif ($status_siswa === 'Terlambat') {
                                $hero_cls = 'is-late';
                                $hero_icon = 'fas fa-clock';
                                $hero_title = 'Hadir - Terlambat';
                                $hero_sub = $jam_masuk_siswa !== '' ? 'Kehadiran dicatat pukul ' . $jam_masuk_siswa : 'Kehadiran dicatat melebihi pukul 07:15 WIB.';
                            } elseif ($status_siswa === 'Sakit') {
                                $hero_cls = 'is-sakit';
                                $hero_icon = 'fas fa-procedures';
                                $hero_title = 'Sakit';
                                $hero_sub = 'Semoga lekas sembuh.';
                            } elseif ($status_siswa === 'Izin') {
                                $hero_cls = 'is-izin';
                                $hero_icon = 'fas fa-paper-plane';
                                $hero_title = 'Izin';
                                $hero_sub = 'Izin Anda sudah tercatat.';
                            }
                            ?>
                            <div class="mapp-att-hero <?= $hero_cls ?>">
                                <div class="mapp-att-hero-ico"><i class="<?= $hero_icon ?>"></i></div>
                                <div class="mapp-att-hero-txt">
                                    <div class="mapp-att-hero-title"><?= $hero_title ?></div>
                                    <div class="mapp-att-hero-sub"><?= $hero_sub ?></div>
                                </div>
                            </div>

                            <div class="mapp-att-hint">
                                <i class="far fa-bell"></i>
                                <span>Silakan pilih status kehadiran Anda hari ini. Jika tidak memilih, status otomatis dianggap <b>Alpa</b>.</span>
                            </div>

                            <form method="POST" action="" id="attendanceFormSiswa">
                                <div class="mapp-att-label">Pilih Status Kehadiran</div>
                                <div class="mapp-att-actions">
                                    <button type="button" onclick="siswaAutoAtt('Hadir')" class="mapp-att-btn is-hadir <?= in_array($status_siswa, ['Hadir', 'Terlambat']) ? 'active' : '' ?>">
                                        <i class="fas fa-check"></i><span>Hadir</span>
                                    </button>
                                    <button type="button" onclick="siswaAutoAtt('Sakit')" class="mapp-att-btn is-sakit <?= ($status_siswa === 'Sakit') ? 'active' : '' ?>">
                                        <i class="fas fa-procedures"></i><span>Sakit</span>
                                    </button>
                                    <button type="button" onclick="siswaAutoAtt('Izin')" class="mapp-att-btn is-izin <?= ($status_siswa === 'Izin') ? 'active' : '' ?>">
                                        <i class="fas fa-paper-plane"></i><span>Izin</span>
                                    </button>
                                </div>
                                <input type="hidden" name="absen_status" id="attStatusSiswa" value="">
                            </form>
                            <script>
                            function siswaAutoAtt(status) {
                                var cur = '<?= in_array($status_siswa, ['Hadir', 'Terlambat']) ? 'Hadir' : $status_siswa ?>';
                                if (cur !== '' && status === cur) { return; }
                                document.getElementById('attStatusSiswa').value = status;
                                document.getElementById('attendanceFormSiswa').submit();
                            }
                            </script>
                        <?php else: ?>
                            <div class="mapp-att-hero is-holiday">
                                <div class="mapp-att-hero-ico"><i class="fas fa-umbrella-beach"></i></div>
                                <div class="mapp-att-hero-txt">
                                    <div class="mapp-att-hero-title">Hari Libur Sekolah</div>
                                    <div class="mapp-att-hero-sub">Hari ini, <strong><?php echo formatDateIndonesia(date('Y-m-d')); ?></strong> adalah <strong><?php echo htmlspecialchars($holiday['name']); ?></strong>. Kehadiran harian ditutup.</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-6 col-md-12 col-12 col-sm-12 mb-4">
                <div class="card card-warning mapp-tagihan-card h-100" style="border-radius: 12px; overflow: hidden;">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h4 class="mb-0"><i class="fas fa-file-invoice-dollar mr-2"></i>Ringkasan Tagihan Pembayaran</h4>
                        <a href="tagihan_siswa.php" class="btn btn-sm btn-warning text-white" style="border-radius: 20px;">
                            Detail Tagihan <i class="fas fa-chevron-right ml-1"></i>
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if ($tagihan_status_ok): ?>
                            <?php
                            $tagihan_lunas = $tagihan_total_semua <= 0;
                            $tagihan_aktif_pct = $tagihan_total_semua > 0 ? round($tagihan_total_aktif / $tagihan_total_semua * 100) : 0;
                            $tagihan_lama_pct = 100 - $tagihan_aktif_pct;
                            ?>
                            <!-- Hero total -->
                            <div class="p-3 mb-3 d-flex flex-wrap align-items-center justify-content-between" style="border-radius: 12px; background: <?= $tagihan_lunas ? 'linear-gradient(135deg, #d1fae5, #a7f3d0)' : 'linear-gradient(135deg, #fef3c7, #fde68a)' ?>;">
                                <div class="d-flex align-items-center mb-2 mb-md-0">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center mr-3 <?= $tagihan_lunas ? 'bg-success' : 'bg-warning' ?> text-white" style="width: 46px; height: 46px; min-width: 46px;">
                                        <i class="fas fa-<?= $tagihan_lunas ? 'check' : 'exclamation' ?> fa-lg"></i>
                                    </div>
                                    <div>
                                        <div class="small font-weight-bold text-uppercase" style="letter-spacing: .5px; color: #6b7280;">Total Tunggakan</div>
                                        <div class="h4 mb-0 font-weight-bold <?= $tagihan_lunas ? 'text-success' : 'text-danger' ?>">
                                            Rp <?= number_format($tagihan_total_semua, 0, ',', '.') ?>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <?php if ($tagihan_lunas): ?>
                                        <span class="badge badge-success px-3 py-2" style="font-size: 12px; border-radius: 20px;"><i class="fas fa-check-circle mr-1"></i>Lunas</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger px-3 py-2" style="font-size: 12px; border-radius: 20px;"><i class="fas fa-exclamation-circle mr-1"></i>Ada Tunggakan</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!$tagihan_lunas): ?>
                                <!-- Proporsi tunggakan -->
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between small text-muted mb-1">
                                        <span><i class="fas fa-circle mr-1" style="color: #f59e0b; font-size: 8px;"></i>Tahun aktif <?= $tagihan_aktif_pct ?>%</span>
                                        <span><i class="fas fa-circle mr-1" style="color: #ef4444; font-size: 8px;"></i>Tahun lama <?= $tagihan_lama_pct ?>%</span>
                                    </div>
                                    <div class="progress" style="height: 8px; border-radius: 10px; overflow: hidden;">
                                        <div class="progress-bar" role="progressbar" style="width: <?= $tagihan_aktif_pct ?>%; background: #f59e0b;" aria-valuenow="<?= $tagihan_aktif_pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                        <div class="progress-bar" role="progressbar" style="width: <?= $tagihan_lama_pct ?>%; background: #ef4444;" aria-valuenow="<?= $tagihan_lama_pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </div>

                                <!-- Rincian -->
                                <div class="border rounded mb-2 p-3 d-flex align-items-center justify-content-between" style="border-radius: 10px;">
                                    <div class="d-flex align-items-center">
                                        <div class="rounded bg-warning text-white d-flex align-items-center justify-content-center mr-3" style="width: 36px; height: 36px; min-width: 36px; border-radius: 10px;">
                                            <i class="fas fa-calendar-alt"></i>
                                        </div>
                                        <div>
                                            <div class="small text-muted">Tahun Ajaran Aktif</div>
                                            <div class="font-weight-bold text-dark"><?= htmlspecialchars($tagihan_tahun_aktif) ?></div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-weight-bold <?= $tagihan_total_aktif > 0 ? 'text-danger' : 'text-success' ?>">Rp <?= number_format($tagihan_total_aktif, 0, ',', '.') ?></div>
                                        <?php if ($tagihan_total_aktif <= 0): ?>
                                            <small class="badge badge-success">Lunas</small>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="border rounded p-3 d-flex align-items-center justify-content-between" style="border-radius: 10px;">
                                    <div class="d-flex align-items-center">
                                        <div class="rounded bg-secondary text-white d-flex align-items-center justify-content-center mr-3" style="width: 36px; height: 36px; min-width: 36px; border-radius: 10px;">
                                            <i class="fas fa-history"></i>
                                        </div>
                                        <div>
                                            <div class="small text-muted">Tunggakan Tahun Lama</div>
                                            <div class="font-weight-bold text-dark"><?= empty($tagihan_tahun_lama) ? '-' : htmlspecialchars(implode(', ', $tagihan_tahun_lama)) ?></div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-weight-bold <?= $tagihan_total_lama > 0 ? 'text-danger' : 'text-success' ?>">Rp <?= number_format($tagihan_total_lama, 0, ',', '.') ?></div>
                                        <?php if ($tagihan_total_lama <= 0): ?>
                                            <small class="badge badge-success">Lunas</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-success mb-0" style="border-radius: 10px;">
                                    <i class="fas fa-check-circle mr-2"></i>Tidak ada tagihan yang belum dibayar menurut data Pembayaran Siswa. Pertahankan!
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-center py-2">
                                <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-3 text-warning" style="width: 56px; height: 56px;">
                                    <i class="fas fa-plug fa-lg"></i>
                                </div>
                                <h6 class="font-weight-bold text-dark">Belum Terhubung ke Pembayaran</h6>
                                <p class="text-muted small mb-3">Ringkasan tagihan belum bisa diambil saat ini. Coba lagi melalui halaman detail.</p>
                                <a href="tagihan_siswa.php" class="btn btn-warning btn-sm text-white font-weight-bold px-4" style="border-radius: 20px;">
                                    <i class="fas fa-sync-alt mr-1"></i>Coba Lagi
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Box Info Tabungan -->
            <div class="col-lg-6 col-md-12 col-12 col-sm-12 mb-4">
                <div class="card card-success mapp-tabungan-card h-100" style="border-radius: 12px; overflow: hidden;">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h4 class="mb-0"><i class="fas fa-piggy-bank mr-2"></i>Info Tabungan</h4>
                        <a href="tabungan_siswa.php" class="btn btn-sm btn-success" style="border-radius: 20px;">
                            Detail <i class="fas fa-chevron-right ml-1"></i>
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if ($tab_ok): ?>
                            <?php
                            $tab_total_gerak = $tab_setor + $tab_tarik;
                            $tab_setor_pct = $tab_total_gerak > 0 ? round($tab_setor / $tab_total_gerak * 100) : 50;
                            $tab_tarik_pct = 100 - $tab_setor_pct;
                            ?>
                            <!-- Hero saldo -->
                            <div class="p-3 mb-3 d-flex flex-wrap align-items-center justify-content-between" style="border-radius: 12px; background: linear-gradient(135deg, #d1fae5, #a7f3d0);">
                                <div class="d-flex align-items-center mb-2 mb-md-0">
                                    <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center mr-3" style="width: 46px; height: 46px; min-width: 46px;">
                                        <i class="fas fa-piggy-bank fa-lg"></i>
                                    </div>
                                    <div>
                                        <div class="small font-weight-bold text-uppercase" style="letter-spacing: .5px; color: #6b7280;">Saldo Tabungan</div>
                                        <div class="h4 mb-0 font-weight-bold text-success">Rp <?php echo number_format($tab_saldo, 0, ',', '.'); ?></div>
                                    </div>
                                </div>
                                <div>
                                    <?php if ($tab_saldo > 0): ?>
                                        <span class="badge badge-success px-3 py-2" style="font-size: 12px; border-radius: 20px;"><i class="fas fa-arrow-up mr-1"></i>Surplus</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary px-3 py-2" style="font-size: 12px; border-radius: 20px;"><i class="fas fa-minus-circle mr-1"></i>Nol</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Proporsi arus kas -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between small text-muted mb-1">
                                    <span><i class="fas fa-circle mr-1" style="color: #10b981; font-size: 8px;"></i>Setoran <?= $tab_setor_pct ?>%</span>
                                    <span><i class="fas fa-circle mr-1" style="color: #ef4444; font-size: 8px;"></i>Penarikan <?= $tab_tarik_pct ?>%</span>
                                </div>
                                <div class="progress" style="height: 8px; border-radius: 10px; overflow: hidden;">
                                    <div class="progress-bar" role="progressbar" style="width: <?= $tab_setor_pct ?>%; background: #10b981;" aria-valuenow="<?= $tab_setor_pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    <div class="progress-bar" role="progressbar" style="width: <?= $tab_tarik_pct ?>%; background: #ef4444;" aria-valuenow="<?= $tab_tarik_pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                            </div>

                            <!-- Rincian -->
                            <div class="border rounded mb-2 p-3 d-flex align-items-center justify-content-between" style="border-radius: 10px;">
                                <div class="d-flex align-items-center">
                                    <div class="rounded bg-success text-white d-flex align-items-center justify-content-center mr-3" style="width: 36px; height: 36px; min-width: 36px; border-radius: 10px;">
                                        <i class="fas fa-arrow-down"></i>
                                    </div>
                                    <div>
                                        <div class="small text-muted">Total Setoran Masuk</div>
                                        <div class="font-weight-bold text-dark">Tabungan terkumpul</div>
                                    </div>
                                </div>
                                <div class="text-right font-weight-bold text-primary">Rp <?php echo number_format($tab_setor, 0, ',', '.'); ?></div>
                            </div>

                            <div class="border rounded p-3 d-flex align-items-center justify-content-between" style="border-radius: 10px;">
                                <div class="d-flex align-items-center">
                                    <div class="rounded bg-danger text-white d-flex align-items-center justify-content-center mr-3" style="width: 36px; height: 36px; min-width: 36px; border-radius: 10px;">
                                        <i class="fas fa-arrow-up"></i>
                                    </div>
                                    <div>
                                        <div class="small text-muted">Total Penarikan Keluar</div>
                                        <div class="font-weight-bold text-dark">Dana terpakai</div>
                                    </div>
                                </div>
                                <div class="text-right font-weight-bold text-danger">Rp <?php echo number_format($tab_tarik, 0, ',', '.'); ?></div>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-2">
                                <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-3 text-success" style="width: 56px; height: 56px;">
                                    <i class="fas fa-plug fa-lg"></i>
                                </div>
                                <h6 class="font-weight-bold text-dark">Data Belum Tersedia</h6>
                                <p class="text-muted small mb-3">Data tabungan belum bisa diambil saat ini. Coba lagi melalui halaman detail.</p>
                                <a href="tabungan_siswa.php" class="btn btn-success btn-sm font-weight-bold px-4" style="border-radius: 20px;">
                                    <i class="fas fa-sync-alt mr-1"></i>Coba Lagi
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Box Komunikasi Orang Tua / Informasi Sekolah -->
        <div class="row">
            <div class="col-12 mb-4">
                <div class="card card-info shadow-sm mapp-table-card" id="mapp-box-pesan" style="scroll-margin-top:90px;">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                        <h4 class="mb-0 text-info">
                            <i class="fas fa-envelope-open-text mr-2"></i>Pesan &amp; Laporan dari Wali Kelas / Sekolah
                        </h4>
                        <div>
                            <?php if ($total_pesan_belum_baca > 0): ?>
                                <span class="badge badge-warning font-weight-bold" id="badgeTotalPesan" style="font-size:12px; padding:6px 12px;">
                                    <i class="fas fa-bell mr-1"></i> <?= $total_pesan_belum_baca ?> Pesan Baru Belum Dibaca
                                </span>
                            <?php else: ?>
                                <span class="badge badge-info font-weight-bold" id="badgeTotalPesan" style="font-size:12px; padding:6px 12px;">
                                    <i class="fas fa-check-circle mr-1"></i> Semua Pesan Sudah Dibaca
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($student_messages)): ?>
                            <div class="text-center text-muted py-4">
                                <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-3 text-info" style="width: 56px; height: 56px;">
                                    <i class="fas fa-comments fa-lg"></i>
                                </div>
                                <p class="mb-0 font-weight-bold">Belum ada informasi / laporan pesan dari sekolah untuk Anda.</p>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($student_messages as $m): ?>
                                    <?php
                                    $is_read = ($m['status_dibaca'] === 'Sudah Dibaca');
                                    $raw_isi = strip_tags($m['isi']);
                                    $isi_cut = mb_strlen($raw_isi) > 90 ? mb_substr($raw_isi, 0, 90) . '...' : $raw_isi;
                                    $tm_s = !empty($m['tanggal_mulai']) ? $m['tanggal_mulai'] : $m['tanggal'];
                                    $ts_s = !empty($m['tanggal_selesai']) ? $m['tanggal_selesai'] : $tm_s;
                                    $tgl_disp = date('d/m/Y', strtotime($tm_s));
                                    if ($ts_s && $ts_s !== $tm_s) {
                                        $tgl_disp .= ' - ' . date('d/m/Y', strtotime($ts_s));
                                    }
                                    $wm_s = isset($m['waktu_mulai']) && $m['waktu_mulai'] ? substr((string)$m['waktu_mulai'], 0, 5) : '';
                                    $ws_s = isset($m['waktu_selesai']) && $m['waktu_selesai'] ? substr((string)$m['waktu_selesai'], 0, 5) : '';
                                    if ($wm_s !== '' && $ws_s !== '' && $wm_s !== $ws_s) {
                                        $waktu_disp = str_replace(':', '.', $wm_s) . ' - ' . str_replace(':', '.', $ws_s) . ' WIB';
                                    } elseif ($wm_s !== '') {
                                        $waktu_disp = str_replace(':', '.', $wm_s) . ' WIB';
                                    } elseif ($ws_s !== '') {
                                        $waktu_disp = str_replace(':', '.', $ws_s) . ' WIB';
                                    } else {
                                        $waktu_disp = '-';
                                    }
                                    $msg_key_d = ($m['msg_type'] ?? 'ortu') . '-' . ($m['id'] ?? 0);
                                    $jenis_txt = (string)($m['jenis_informasi'] ?? '-');
                                    if (stripos($jenis_txt, 'Kehadiran') !== false) { $jenis_bg = '#dbeafe'; $jenis_fg = '#1d4ed8'; $jenis_ic = 'fas fa-user-check'; }
                                    elseif (stripos($jenis_txt, 'Tugas') !== false) { $jenis_bg = '#fef3c7'; $jenis_fg = '#b45309'; $jenis_ic = 'fas fa-tasks'; }
                                    elseif (stripos($jenis_txt, 'Perkembangan') !== false) { $jenis_bg = '#ede9fe'; $jenis_fg = '#6d28d9'; $jenis_ic = 'fas fa-chart-line'; }
                                    elseif (stripos($jenis_txt, 'Pengumuman') !== false) { $jenis_bg = '#ffedd5'; $jenis_fg = '#c2410c'; $jenis_ic = 'fas fa-bullhorn'; }
                                    else { $jenis_bg = '#e0f2fe'; $jenis_fg = '#0369a1'; $jenis_ic = 'fas fa-envelope-open-text'; }
                                    ?>
                                    <div class="col-12 col-lg-6 d-flex align-items-stretch mb-3" id="row-pesan-<?= (int)$m['id'] ?>">
                                        <div class="card w-100 border shadow-sm nav-msg-item-<?= htmlspecialchars($msg_key_d) ?> <?= $is_read ? 'is-read' : 'is-unread' ?>" style="border-radius: 12px; <?= $is_read ? '' : 'border-left: 4px solid #f59e0b !important;' ?>">
                                            <div class="card-body p-3 d-flex flex-column">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <span class="badge px-2 py-1 font-weight-bold" style="background: <?= $jenis_bg ?>; color: <?= $jenis_fg ?>; font-size: 11px; border-radius: 6px;">
                                                        <i class="<?= $jenis_ic ?> mr-1"></i><?= htmlspecialchars($jenis_txt) ?>
                                                    </span>
                                                    <span class="status-baca-col">
                                                        <?php if ($is_read): ?>
                                                            <span class="badge badge-info"><i class="fas fa-check-double mr-1"></i>Sudah Dibaca</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-warning"><i class="fas fa-envelope mr-1"></i>Belum Dibaca</span>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                                <h6 class="font-weight-bold text-dark mb-1" style="font-size: 14px; line-height: 1.4;">
                                                    <?= htmlspecialchars($m['judul']) ?>
                                                </h6>
                                                <p class="small text-muted mb-2"><?= htmlspecialchars($isi_cut) ?></p>
                                                <div class="small text-muted mb-3" style="font-size: 11.5px; line-height: 1.7;">
                                                    <div><i class="fas fa-user-tie mr-1"></i><?= htmlspecialchars($m['pengirim'] ?? $m['nama_guru'] ?? 'Wali / Guru') ?></div>
                                                    <div><i class="far fa-calendar-alt mr-1"></i><?= htmlspecialchars($tgl_disp) ?> &bull; <i class="far fa-clock mr-1"></i><?= htmlspecialchars($waktu_disp) ?></div>
                                                </div>
                                                <div class="mt-auto pt-2 border-top">
                                                    <button type="button" class="btn btn-info btn-sm font-weight-bold btn-baca-pesan" data-json='<?= htmlspecialchars(json_encode($m), ENT_QUOTES, 'UTF-8') ?>' style="border-radius: 8px;">
                                                        <i class="fas fa-eye mr-1"></i>Baca Pesan
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Box Pemberitahuan Tugas Siswa -->
        <div class="row">
            <div class="col-12 mb-4">
                <div class="card card-primary shadow-sm mapp-table-card">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h4 class="mb-0 text-primary">
                            <i class="fas fa-tasks mr-2"></i>Pemberitahuan Tugas - Kelas <?= htmlspecialchars($student['nama_kelas'] ?? '') ?>
                        </h4>
                        <div>
                            <?php if ($total_tugas_belum > 0): ?>
                                <span class="badge badge-danger font-weight-bold mr-2" style="font-size:12px; padding:6px 12px;">
                                    <i class="fas fa-bell mr-1"></i> <?= $total_tugas_belum ?> Tugas Belum
                                </span>
                            <?php else: ?>
                                <span class="badge badge-success font-weight-bold mr-2" style="font-size:12px; padding:6px 12px;">
                                    <i class="fas fa-check-circle mr-1"></i> Semua Tugas Sudah
                                </span>
                            <?php endif; ?>
                            <a href="tugas.php" class="btn btn-sm btn-primary">
                                Lihat Semua Tugas <i class="fas fa-arrow-right ml-1"></i>
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($student_tasks)): ?>
                            <div class="text-center text-muted py-4">
                                <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-3 text-success" style="width: 56px; height: 56px;">
                                    <i class="fas fa-clipboard-check fa-lg"></i>
                                </div>
                                <p class="mb-0 font-weight-bold">Tidak ada tugas aktif untuk kelas Anda saat ini.</p>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($student_tasks as $t): ?>
                                    <?php
                                    $is_submitted = !empty($t['id_pengumpulan']);
                                    $is_overdue = !empty($t['deadline']) && strtotime($t['deadline']) < time() && !$is_submitted;
                                    $is_kuis_d = in_array($t['jenis_tugas'] ?? '', ['Kuis', 'CBT'], true);
                                    ?>
                                    <div class="col-12 col-md-6 col-lg-4 d-flex align-items-stretch mb-3">
                                        <div class="card w-100 border shadow-sm" style="border-radius: 12px; <?= $is_submitted ? '' : 'border-left: 4px solid #6777ef !important;' ?>">
                                            <div class="card-body p-3 d-flex flex-column">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <span class="badge badge-light border text-dark font-weight-bold px-2 py-1" style="font-size: 11px; border-radius: 6px;">
                                                        <i class="fas fa-<?= $is_kuis_d ? 'laptop-code' : 'tasks' ?> mr-1 text-primary"></i><?= htmlspecialchars($t['jenis_tugas'] ?? 'Tugas') ?>
                                                    </span>
                                                    <?php if ($is_submitted): ?>
                                                        <span class="badge badge-success"><i class="fas fa-check mr-1"></i>Sudah</span>
                                                    <?php elseif ($is_overdue): ?>
                                                        <span class="badge badge-danger"><i class="fas fa-exclamation-circle mr-1"></i>Lewat Batas</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-warning"><i class="fas fa-hourglass-half mr-1"></i>Belum</span>
                                                    <?php endif; ?>
                                                </div>
                                                <h6 class="font-weight-bold text-dark mb-1" style="font-size: 14px; line-height: 1.4;">
                                                    <?= htmlspecialchars($t['judul']) ?>
                                                </h6>
                                                <div class="small text-muted mb-2" style="font-size: 11.5px; line-height: 1.7;">
                                                    <div><i class="fas fa-book mr-1"></i><?= htmlspecialchars($t['nama_mapel'] ?? '-') ?> &bull; <i class="fas fa-chalkboard-teacher mr-1"></i><?= htmlspecialchars($t['nama_guru'] ?? '-') ?></div>
                                                    <div><i class="far fa-calendar-alt mr-1 <?= $is_overdue ? 'text-danger' : '' ?>"></i>Batas: <strong class="<?= $is_overdue ? 'text-danger' : '' ?>"><?= !empty($t['deadline']) ? date('d/m/Y H:i', strtotime($t['deadline'])) : '-' ?></strong></div>
                                                    <div><i class="fas fa-star mr-1 text-warning"></i>Nilai: <?= $t['nilai'] !== null ? '<strong class="text-primary">' . (float)$t['nilai'] . '</strong>' : '<span class="text-muted">-</span>' ?></div>
                                                </div>
                                                <div class="mt-auto pt-2 border-top">
                                                    <a href="tugas.php" class="btn btn-primary btn-sm font-weight-bold" style="border-radius: 8px;">
                                                        <i class="fas fa-external-link-alt mr-1"></i>Buka Tugas
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mobile Menu Utama - Last Position -->
        <div class="row d-lg-none mt-4">
            <div class="col-12 mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0 font-weight-bold">Menu Utama</h6>
                            <span class="badge badge-success badge-pill">Semua Fitur</span>
                        </div>
                        <?php
                        global $menu_items;
                        $mobile_menu_groups = function_exists('get_mobile_menu_groups') ? get_mobile_menu_groups($menu_items) : ['single' => [], 'grouped' => []];
                        $single_items = $mobile_menu_groups['single'];
                        $grouped_items = $mobile_menu_groups['grouped'];
                        ?>
                        <?php if (!empty($single_items) || !empty($grouped_items)): ?>
                            <div class="row mapp-menu-grid">
                                <?php if (!empty($single_items)): ?>
                                    <?php foreach ($single_items as $mg_idx => $item): ?>
                                        <div class="col-3 mb-3">
                                            <a href="<?php echo $item['url']; ?>" class="text-decoration-none text-center d-block">
                                                <div class="mx-auto mb-2 menu-grid-icon mg-c<?php echo ($mg_idx % 8) + 1; ?>">
                                                    <i class="<?php echo $item['icon']; ?>"></i>
                                                </div>
                                            <div class="mobile-menu-label small text-dark"><?php echo $item['title']; ?></div>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php if (!empty($grouped_items)): ?>
                                    <?php $tile_base = count($single_items); ?>
                                    <?php foreach ($grouped_items as $g_idx => $group): ?>
                                        <div class="col-3 mb-3">
                                            <a href="<?php echo htmlspecialchars(mapp_tile_url($group['title']), ENT_QUOTES, 'UTF-8'); ?>" class="text-decoration-none text-center d-block">
                                                <div class="mx-auto mb-2 menu-grid-icon mg-c<?php echo (($tile_base + $g_idx) % 8) + 1; ?>">
                                                    <i class="<?php echo $group['icon']; ?>"></i>
                                                </div>
                                                <div class="mobile-menu-label small text-dark"><?php echo $group['title']; ?></div>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
            </div>
        </div>

        <div class="row">
            <!-- Box Barcode Kehadiran -->
            <div class="col-lg-6 col-md-12 col-12 col-sm-12 mb-4 d-flex">
                <div class="card card-info mapp-barcode-card h-100 w-100">
                    <div class="card-header d-flex flex-column align-items-center flex-md-row justify-content-md-between py-3 h-auto">
                        <h4 class="mb-0">Barcode Kehadiran</h4>
                    </div>
                    <div class="card-body text-center">
                        <p>Gunakan barcode ini untuk kehadiran di perangkat sekolah (jika tersedia).</p>
                        <div class="barcode-container mb-3">
                            <!-- Generate QR Code using Google Charts API or similar -->
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo $student['nisn']; ?>" alt="QR Code NISN" class="img-fluid border p-2">
                        </div>
                        <div class="font-weight-bold text-xl"><?php echo htmlspecialchars($student['nisn']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 col-md-12 col-12 col-sm-12 mb-4 mapp-agenda-wrap">
                <?php echo renderDashboardAgendaBulanBerjalan($pdo); ?>
            </div>
        </div>
                </section>
                </div>
            </div>

<?php
// Box identitas + upload foto pindah ke profil.php
include '../templates/footer.php';
?>

<?php
if (isset($swal_message)): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof toastr !== 'undefined') {
            toastr.options = { positionClass: 'toast-top-right', timeOut: 2000, progressBar: false };
            var type = '<?= ($swal_message['icon'] === 'success') ? 'success' : (($swal_message['icon'] === 'warning') ? 'warning' : 'error') ?>';
            toastr[type]('<?= addslashes($swal_message['text']) ?>', '<?= addslashes($swal_message['title']) ?>');
        } else if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: '<?= addslashes($swal_message['title']) ?>',
                text: '<?= addslashes($swal_message['text']) ?>',
                icon: '<?= addslashes($swal_message['icon']) ?>',
                timer: 2000,
                showConfirmButton: false
            });
        }
    });
</script>
<?php endif; ?>

<script>
// Function to edit keterangan for Izin status
function editKeteranganSiswa() {
    // Simple prompt for editing
    const currentKeterangan = '<?php echo addslashes($attendance['keterangan'] ?? ''); ?>';
    const newKeterangan = prompt('Masukkan keterangan izin:', currentKeterangan);
    
    if (newKeterangan !== null && newKeterangan.trim() !== '') {
        // Create a temporary form and submit
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'keterangan';
        input.value = newKeterangan.trim();
        form.appendChild(input);
        
        const submitBtn = document.createElement('input');
        submitBtn.type = 'hidden';
        submitBtn.name = 'submit_keterangan';
        submitBtn.value = '1';
        form.appendChild(submitBtn);
        
        document.body.appendChild(form);
        form.submit();
    }
}

$(document).on('click', '.btn-baca-pesan', function() {
    var data = $(this).data('json');
    if (typeof openStudentMsgFromNav === 'function') {
        openStudentMsgFromNav(data, this);
    }
});
</script>
