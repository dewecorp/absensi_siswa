<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['siswa'])) {
    redirect('../login.php');
}

$id_siswa = (int)($_SESSION['user_id'] ?? 0);
$id_tugas = (int)($_GET['id_tugas'] ?? $_GET['id'] ?? 0);

if ($id_tugas <= 0) {
    redirect('tugas.php');
}

$stmt = $pdo->prepare("
    SELECT t.*, m.nama_mapel, g.nama_guru
    FROM tb_tugas t
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = t.id_mapel
    LEFT JOIN tb_guru g ON g.id_guru = t.id_guru
    WHERE t.id = ? AND t.status = 'Aktif'
    LIMIT 1
");
$stmt->execute([$id_tugas]);
$tugas = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tugas || !in_array($tugas['jenis_tugas'], ['Kuis', 'CBT'], true)) {
    redirect('tugas.php');
}

// Cek deadline & tgl_mulai
$is_upcoming = !empty($tugas['tgl_mulai']) && strtotime($tugas['tgl_mulai']) > time();
$is_expired = !empty($tugas['deadline']) && strtotime($tugas['deadline']) < time();

if ($is_upcoming) {
    $page_title = 'Ujian Belum Dibuka';
    include '../templates/header.php';
    include '../templates/sidebar.php';
    echo '<div class="main-content"><section class="section"><div class="section-body"><div class="card shadow-sm border-warning"><div class="card-body text-center py-5"><div class="mb-3 text-warning" style="font-size: 50px;"><i class="fas fa-hourglass-start"></i></div><h3 class="text-warning font-weight-bold">Ujian Belum Dimulai</h3><p class="text-muted">Kuis / CBT ini baru akan dibuka pada tanggal: <strong>' . date('d/m/Y H:i', strtotime($tugas['tgl_mulai'])) . '</strong>. Mohon tunggu hingga waktu yang ditentukan.</p><a href="tugas.php" class="btn btn-primary mt-2"><i class="fas fa-arrow-left mr-1"></i> Kembali ke Daftar Tugas</a></div></div></div></section></div>';
    include '../templates/footer.php';
    exit;
}

// Fetch soal untuk tugas ini
$stSoal = $pdo->prepare("SELECT * FROM tb_tugas_soal WHERE id_tugas = ? ORDER BY id ASC");
$stSoal->execute([$id_tugas]);
$soal_list = $stSoal->fetchAll(PDO::FETCH_ASSOC);

// Cek apakah siswa sudah pernah mengerjakan/submit
$stSub = $pdo->prepare("SELECT * FROM tb_tugas_pengumpulan WHERE id_tugas = ? AND id_siswa = ?");
$stSub->execute([$id_tugas, $id_siswa]);
$submission = $stSub->fetch(PDO::FETCH_ASSOC);

if ($is_expired && empty($submission)) {
    $page_title = 'Waktu Habis';
    include '../templates/header.php';
    include '../templates/sidebar.php';
    echo '<div class="main-content"><section class="section"><div class="section-body"><div class="card shadow-sm border-danger"><div class="card-body text-center py-5"><div class="mb-3 text-danger" style="font-size: 50px;"><i class="fas fa-clock"></i></div><h3 class="text-danger font-weight-bold">Waktu Pengerjaan Telah Berakhir</h3><p class="text-muted">Batas waktu (deadline) tugas ini sudah terlewati. Anda tidak dapat mengerjakan kuis ini kecuali guru telah memberikan perpanjangan waktu.</p><a href="tugas.php" class="btn btn-primary mt-2"><i class="fas fa-arrow-left mr-1"></i> Kembali ke Daftar Tugas</a></div></div></div></section></div>';
    include '../templates/footer.php';
    exit;
}

// Hanya tampilkan kartu nilai jika sudah submit DAN waktu sudah habis (expired), 
// jika waktu masih ada, siswa boleh edit/perbaiki jawabannya!
$tampilkan_hasil_final = (!empty($submission) && $is_expired);

$saved_answers = [];
if (!empty($submission['catatan_siswa'])) {
    $saved_answers = json_decode($submission['catatan_siswa'], true) ?: [];
}

$message = null;

// Handle submit jawaban kuis/CBT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($submission)) {
    $jawaban_siswa = $_POST['jawaban'] ?? [];
    $total_skor = 0;
    $max_skor = 0;
    $detail_jawaban = [];

    foreach ($soal_list as $soal) {
        $sId = $soal['id'];
        $bobot = (int)($soal['bobot'] ?? 10);
        $max_skor += $bobot;
        $jwb = $jawaban_siswa[$sId] ?? '';
        if (is_array($jwb)) {
            $jwb = implode(',', $jwb);
        }
        $jwb = trim((string)$jwb);

        $detail_jawaban[$sId] = $jwb;

        if ($soal['tipe_soal'] === 'Pilihan Ganda' || $soal['tipe_soal'] === 'Pilihan Ganda Kompleks') {
            if (strcasecmp($jwb, trim($soal['kunci_jawaban'])) === 0) {
                $total_skor += $bobot;
            }
        } elseif ($soal['tipe_soal'] === 'Essay') {
            $kunci_essay = trim((string)$soal['kunci_jawaban']);
            if ($kunci_essay !== '' && $jwb !== '') {
                similar_text(strtolower($jwb), strtolower($kunci_essay), $percent);
                if ($percent >= 80.0) {
                    $total_skor += $bobot;
                } elseif ($percent >= 50.0) {
                    $total_skor += ($bobot * 0.5);
                }
            }
        }
    }

    $nilai_akhir = $max_skor > 0 ? round(($total_skor / $max_skor) * 100, 2) : 0;

    try {
        $stIns = $pdo->prepare("
            INSERT INTO tb_tugas_pengumpulan (
                id_tugas, id_siswa, catatan_siswa, nilai, tgl_kumpul, status_periksa
            ) VALUES (?, ?, ?, ?, NOW(), 'Sudah Diperiksa')
        ");
        $stIns->execute([
            $id_tugas,
            $id_siswa,
            json_encode($detail_jawaban),
            $nilai_akhir
        ]);
        $message = ['type' => 'success', 'text' => "Kuis berhasil diserahkan! Nilai Anda: {$nilai_akhir}"];
        $stSub->execute([$id_tugas, $id_siswa]);
        $submission = $stSub->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $message = ['type' => 'danger', 'text' => 'Gagal menyerahkan kuis: ' . $e->getMessage()];
    }
}

$page_title = 'Kerjakan ' . $tugas['jenis_tugas'];
include '../templates/header.php';
include '../templates/sidebar.php';
?>
<style>
body.exam-mode .main-sidebar,
body.exam-mode .navbar,
body.exam-mode .main-footer {
    display: none !important;
}
body.exam-mode .main-content {
    padding-left: 0 !important;
    margin-left: 0 !important;
    margin-top: 0 !important;
    min-height: 100vh;
}
@media (max-width: 991.98px) {
    .main-content { padding: 10px !important; }
    .card { margin-bottom: 12px !important; }
    .btn-nav-soal { width: 38px !important; height: 38px !important; font-size: 13px !important; }
}
</style>

<div class="main-content">
    <!-- Overlay Masuk Ujian & Fullscreen Menutupi Seluruh Halaman -->
    <div id="startExamOverlay" style="position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.98); z-index: 999999; display: flex; align-items: center; justify-content: center; padding: 20px;">
        <div class="card shadow-lg border-0 text-center p-4" style="max-width: 520px; width: 100%; border-radius: 14px; background: #fff;">
            <div class="card-body">
                <div class="mb-3 text-primary" style="font-size: 60px;"><i class="fas fa-laptop-code"></i></div>
                <h3 class="font-weight-bold text-dark mb-2">Konfirmasi Memulai Ujian</h3>
                <p class="text-muted small mb-4">Ujian ini wajib dikerjakan dalam mode <strong>Layar Penuh (Full Screen)</strong>. Dilarang membuka tab atau jendela lain (maksimal 3 kali peringatan sebelum ujian dihentikan otomatis).</p>
                <button type="button" id="btnStartExam" class="btn btn-success btn-lg btn-block font-weight-bold py-3 shadow">
                    <i class="fas fa-expand mr-2"></i> Mulai Ujian & Aktifkan Layar Penuh
                </button>
            </div>
        </div>
    </div>

    <section class="section">
        <div class="section-header">
            <h1><?= htmlspecialchars($tugas['jenis_tugas']) ?>: <?= htmlspecialchars($tugas['judul']) ?></h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="tugas.php">Daftar Tugas</a></div>
                <div class="breadcrumb-item active">Ujian Berlangsung</div>
            </div>
        </div>

        <div class="section-body">
            <?php if ($tampilkan_hasil_final): ?>
                <div class="card shadow-sm border-success">
                    <div class="card-body text-center py-5">
                        <div class="mb-3 text-success" style="font-size: 50px;"><i class="fas fa-check-circle"></i></div>
                        <h3 class="text-success font-weight-bold">Anda Telah Mengerjakan <?= htmlspecialchars($tugas['jenis_tugas']) ?> Ini</h3>
                        <p class="text-muted">Tanggal Kumpul: <?= date('d/m/Y H:i', strtotime($submission['tgl_kumpul'])) ?></p>
                        <div class="display-4 font-weight-bold text-primary mb-3">Nilai: <?= (float)$submission['nilai'] ?> / 100</div>
                        <a href="tugas.php" class="btn btn-primary"><i class="fas fa-arrow-left mr-1"></i> Kembali ke Daftar Tugas</a>
                    </div>
                </div>
            <?php elseif (empty($soal_list)): ?>
                <div class="card shadow-sm">
                    <div class="card-body text-center py-5">
                        <p class="text-muted">Belum ada butir soal yang diunggah oleh guru pengampu untuk kuis ini.</p>
                        <a href="tugas.php" class="btn btn-primary">Kembali</a>
                    </div>
                </div>
            <?php else: ?>
                <form method="POST" id="formKerjakanKuis">
                    <div class="row">
                        <!-- Kolom Kiri: Soal Aktif -->
                        <div class="col-lg-8 mb-4">
                            <div class="card shadow-sm h-100 border">
                                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0 text-dark font-weight-bold" id="labelNoSoal">Soal No. 1</h5>
                                    <span class="badge badge-primary" id="badgeBobotSoal">Bobot: 10</span>
                                </div>
                                <div class="card-body" style="min-height: 280px;">
                                    <?php foreach ($soal_list as $idx => $soal): ?>
                                        <div class="soal-item" id="soalBox_<?= $idx ?>" style="display: <?= $idx === 0 ? 'block' : 'none' ?>;">
                                            <p class="font-weight-bold text-dark mb-4" style="font-size: 16px; line-height: 1.6;"><?= nl2br(htmlspecialchars($soal['pertanyaan'])) ?></p>
                                            
                                             <?php if ($soal['tipe_soal'] === 'Pilihan Ganda'): ?>
                                                 <div class="pl-2">
                                                     <?php 
                                                     $val_pg = $saved_answers[$soal['id']] ?? '';
                                                     foreach (['A' => $soal['pilihan_a'], 'B' => $soal['pilihan_b'], 'C' => $soal['pilihan_c'], 'D' => $soal['pilihan_d'], 'E' => $soal['pilihan_e']] as $k => $opt): 
                                                     ?>
                                                         <?php if (!empty($opt)): ?>
                                                             <div class="custom-control custom-radio mb-3">
                                                                 <input type="radio" id="jwb_<?= $soal['id'] ?>_<?= $k ?>" name="jawaban[<?= $soal['id'] ?>]" value="<?= $k ?>" class="custom-control-input soal-input" data-index="<?= $idx ?>" <?= $val_pg === $k ? 'checked' : '' ?>>
                                                                 <label class="custom-control-label text-dark" style="font-size: 15px;" for="jwb_<?= $soal['id'] ?>_<?= $k ?>"><strong><?= $k ?>.</strong> <?= htmlspecialchars($opt) ?></label>
                                                             </div>
                                                         <?php endif; ?>
                                                     <?php endforeach; ?>
                                                 </div>
                                             <?php elseif ($soal['tipe_soal'] === 'Pilihan Ganda Kompleks'): ?>
                                                 <div class="pl-2">
                                                     <small class="text-info d-block mb-3"><i class="fas fa-info-circle mr-1"></i> Pilihan Ganda Kompleks (Dapat memilih lebih dari 1 jawaban)</small>
                                                     <?php 
                                                     $val_kompleks = explode(',', (string)($saved_answers[$soal['id']] ?? ''));
                                                     foreach (['A' => $soal['pilihan_a'], 'B' => $soal['pilihan_b'], 'C' => $soal['pilihan_c'], 'D' => $soal['pilihan_d'], 'E' => $soal['pilihan_e']] as $k => $opt): 
                                                     ?>
                                                         <?php if (!empty($opt)): ?>
                                                             <div class="custom-control custom-checkbox mb-3">
                                                                 <input type="checkbox" id="jwb_k_<?= $soal['id'] ?>_<?= $k ?>" name="jawaban[<?= $soal['id'] ?>][]" value="<?= $k ?>" class="custom-control-input soal-input" data-index="<?= $idx ?>" <?= in_array($k, $val_kompleks, true) ? 'checked' : '' ?>>
                                                                 <label class="custom-control-label text-dark" style="font-size: 15px;" for="jwb_k_<?= $soal['id'] ?>_<?= $k ?>"><strong><?= $k ?>.</strong> <?= htmlspecialchars($opt) ?></label>
                                                             </div>
                                                         <?php endif; ?>
                                                     <?php endforeach; ?>
                                                 </div>
                                             <?php else: ?>
                                                 <div class="form-group mb-0">
                                                     <textarea name="jawaban[<?= $soal['id'] ?>]" class="form-control soal-input" rows="5" placeholder="Tuliskan jawaban essay / uraian Anda di sini..." data-index="<?= $idx ?>"><?= htmlspecialchars($saved_answers[$soal['id']] ?? '') ?></textarea>
                                                 </div>
                                             <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="card-footer bg-light d-flex justify-content-between">
                                    <button type="button" id="btnPrev" class="btn btn-secondary font-weight-bold" disabled>
                                        <i class="fas fa-arrow-left mr-1"></i> Sebelumnya
                                    </button>
                                    <button type="button" id="btnNext" class="btn btn-primary font-weight-bold">
                                        Selanjutnya <i class="fas fa-arrow-right ml-1"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Kolom Kanan: Navigasi Soal -->
                        <div class="col-lg-4 mb-4">
                            <div class="card shadow-sm h-100 border">
                                <div class="card-header bg-light">
                                    <h5 class="mb-0 text-dark font-weight-bold"><i class="fas fa-th mr-2"></i>Navigasi Soal</h5>
                                </div>
                                <div class="card-body">
                                    <div class="d-flex flex-wrap" style="gap: 8px;">
                                        <?php foreach ($soal_list as $idx => $soal): ?>
                                            <button type="button" class="btn btn-outline-secondary btn-nav-soal font-weight-bold <?= $idx === 0 ? 'active' : '' ?>" data-index="<?= $idx ?>" id="navBtn_<?= $idx ?>" style="width: 44px; height: 44px;">
                                                <?= $idx + 1 ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                    <hr class="my-4">
                                     <div class="text-center" style="display:flex; flex-direction:column; gap:10px;">
                                         <button type="button" id="btnSubmitKuis" class="btn btn-success btn-lg btn-block font-weight-bold py-3 shadow mb-0">
                                             <i class="fas fa-paper-plane mr-2"></i> Selesai & Serahkan Ujian
                                         </button>
                                         <button type="button" id="btnKeluarUjian" class="btn btn-outline-danger btn-sm font-weight-bold">
                                             <i class="fas fa-sign-out-alt mr-1"></i> Keluar Ujian / Batal
                                         </button>
                                     </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php
$js_page = [];
if ($message) {
    $js_page[] = "Swal.fire({ icon: '" . ($message['type'] === 'danger' ? 'error' : $message['type']) . "', title: '" . ($message['type'] === 'success' ? 'Berhasil' : 'Perhatian') . "', text: " . json_encode($message['text']) . ", timer: 2500, showConfirmButton: false });";
}
$js_page[] = '
$(document).ready(function() {
    var totalSoal = ' . count($soal_list) . ';
    totalSoal = parseInt(totalSoal) || 1;
    var currentIndex = 0;
    var bobotList = ' . json_encode(array_column($soal_list, 'bobot')) . ';

    function showSoal(idx) {
        if (idx < 0 || idx >= totalSoal) return;
        currentIndex = idx;
        $(\'.soal-item\').hide();
        $(\'#soalBox_\' + idx).show();
        $(\'#labelNoSoal\').text(\'Soal No. \' + (idx + 1));
        $(\'#badgeBobotSoal\').text(\'Bobot: \' + (bobotList[idx] || 10));

        $(\'#btnPrev\').prop(\'disabled\', idx === 0);
        $(\'#btnNext\').prop(\'disabled\', idx === totalSoal - 1);

        $(\'.btn-nav-soal\').removeClass(\'active\');
        $(\'#navBtn_\' + idx).addClass(\'active\');
    }

    $(document).on(\'click\', \'.btn-nav-soal\', function() {
        var idx = parseInt($(this).data(\'index\'));
        showSoal(idx);
    });

    $(\'#btnPrev\').on(\'click\', function() {
        showSoal(currentIndex - 1);
    });

    $(\'#btnNext\').on(\'click\', function() {
        showSoal(currentIndex + 1);
    });

    $(document).on(\'change input\', \'.soal-input\', function() {
        var idx = parseInt($(this).data(\'index\'));
        var isAnswered = false;
        var box = $(\'#soalBox_\' + idx);
        
        box.find(\'input, textarea\').each(function() {
            if ($(this).is(\':checkbox, :radio\')) {
                if ($(this).is(\':checked\')) isAnswered = true;
            } else {
                if ($.trim($(this).val()) !== \'\') isAnswered = true;
            }
        });

        if (isAnswered) {
            $(\'#navBtn_\' + idx).removeClass(\'btn-outline-secondary\').addClass(\'btn-primary text-white\');
        } else {
            $(\'#navBtn_\' + idx).removeClass(\'btn-primary text-white\').addClass(\'btn-outline-secondary\');
        }
    });

    function requestFullscreen() {
        var elem = document.documentElement;
        var requestFn = elem.requestFullscreen || elem.webkitRequestFullscreen || elem.msRequestFullscreen;
        if (requestFn) {
            requestFn.call(elem).catch(function(err) {
                console.log("Fullscreen blocked or failed: ", err);
            });
        }
        $(\'body\').addClass(\'exam-mode\');
        $(\'#startExamOverlay\').fadeOut(200);
    }

    $(document).on(\'click\', \'#btnStartExam\', function() {
        requestFullscreen();
    });

    var violationCount = 0;
    var maxViolations = 3;

    function checkVisibility() {
        if (document.hidden || document.visibilityState === \'hidden\') {
            violationCount++;
            var sisa = maxViolations - violationCount;
            if (violationCount >= maxViolations) {
                Swal.fire({
                    title: \'Ujian Dihentikan!\',
                    text: \'Anda terdeteksi meninggalkan halaman ujian sebanyak 3 kali. Sesuai aturan, pengerjaan kuis/CBT dihentikan dan nilai dianggap 0.\',
                    icon: \'error\',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    confirmButtonText: \'Keluar ke Daftar Tugas\'
                }).then(function() {
                    window.location.href = \'tugas.php\';
                });
                $(\'#formKerjakanKuis\').html(\'<div class="alert alert-danger text-center font-weight-bold p-4">Ujian dibatalkan karena terdeteksi berpindah tab/jendela.</div>\');
            } else {
                Swal.fire({
                    title: \'Peringatan (\' + violationCount + \'/\' + maxViolations + \')\',
                    text: \'Jangan membuka tab atau jendela lain! Jika Anda meninggalkan halaman ini \' + sisa + \' kali lagi, ujian akan dihentikan secara otomatis.\',
                    icon: \'warning\',
                    confirmButtonText: \'Saya Mengerti\'
                });
            }
        }
    }

    document.addEventListener(\'visibilitychange\', checkVisibility);
    window.addEventListener(\'blur\', checkVisibility);

    $(document).on(\'keydown\', function(e) {
        if (e.key === \'Escape\' || e.keyCode === 27 || e.key === \'F11\' || e.keyCode === 122) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }
    });

    $(document).on(\'click\', \'#btnKeluarUjian\', function(e) {
        e.preventDefault();
        Swal.fire({
            title: \'Keluar Ujian?\',
            text: \'Jawaban Anda tidak akan tersimpan jika keluar sekarang.\',
            icon: \'warning\',
            showCancelButton: true,
            confirmButtonColor: \'#d33\',
            cancelButtonColor: \'#6c757d\',
            confirmButtonText: \'Ya, Keluar\',
            cancelButtonText: \'Lanjutkan Ujian\'
        }).then(function(res) {
            if (res.isConfirmed) {
                if (document.exitFullscreen) { document.exitFullscreen(); }
                else if (document.webkitExitFullscreen) { document.webkitExitFullscreen(); }
                window.location.href = \'tugas.php\';
            }
        });
    });
    $(document).on(\'click\', \'#btnSubmitKuis\', function(e) {
        e.preventDefault();
        Swal.fire({
            title: \'Serahkan Jawaban Ujian?\',
            text: \'Pastikan semua soal telah dikerjakan dengan benar. Anda tidak dapat mengubah jawaban setelah diserahkan.\',
            icon: \'question\',
            showCancelButton: true,
            confirmButtonColor: \'#28a745\',
            cancelButtonColor: \'#6c757d\',
            confirmButtonText: \'Ya, Serahkan Sekarang!\',
            cancelButtonText: \'Periksa Kembali\'
        }).then(function(res) {
            if (res.isConfirmed) {
                $(\'#formKerjakanKuis\').submit();
            }
        });
    });
});
';
include '../templates/footer.php';
?>
