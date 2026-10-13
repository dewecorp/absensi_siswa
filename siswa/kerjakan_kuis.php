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

// Fetch soal untuk tugas ini
$stSoal = $pdo->prepare("SELECT * FROM tb_tugas_soal WHERE id_tugas = ? ORDER BY id ASC");
$stSoal->execute([$id_tugas]);
$soal_list = $stSoal->fetchAll(PDO::FETCH_ASSOC);

// Cek apakah siswa sudah pernah mengerjakan/submit
$stSub = $pdo->prepare("SELECT * FROM tb_tugas_pengumpulan WHERE id_tugas = ? AND id_siswa = ?");
$stSub->execute([$id_tugas, $id_siswa]);
$submission = $stSub->fetch(PDO::FETCH_ASSOC);

// Cek deadline & tgl_mulai
$is_upcoming = !empty($tugas['tgl_mulai']) && strtotime($tugas['tgl_mulai']) > time();
$is_expired = !empty($tugas['deadline']) && strtotime($tugas['deadline']) < time();

if ($is_upcoming && empty($submission)) {
    $page_title = 'Ujian Belum Dibuka';
    include '../templates/header.php';
    include '../templates/sidebar.php';
    echo '<div class="main-content"><section class="section"><div class="section-body"><div class="card shadow-sm border-warning"><div class="card-body text-center py-5"><div class="mb-3 text-warning" style="font-size: 50px;"><i class="fas fa-hourglass-start"></i></div><h3 class="text-warning font-weight-bold">Ujian Belum Dimulai</h3><p class="text-muted">Kuis / CBT ini baru akan dibuka pada tanggal: <strong>' . date('d/m/Y H:i', strtotime($tugas['tgl_mulai'])) . '</strong>. Mohon tunggu hingga waktu yang ditentukan.</p><a href="tugas.php" class="btn btn-primary mt-2"><i class="fas fa-arrow-left mr-1"></i> Kembali ke Daftar Tugas</a></div></div></div></section></div>';
    include '../templates/footer.php';
    exit;
}

if ($is_expired && empty($submission)) {
    $page_title = 'Waktu Habis';
    include '../templates/header.php';
    include '../templates/sidebar.php';
    echo '<div class="main-content"><section class="section"><div class="section-body"><div class="card shadow-sm border-danger"><div class="card-body text-center py-5"><div class="mb-3 text-danger" style="font-size: 50px;"><i class="fas fa-clock"></i></div><h3 class="text-danger font-weight-bold">Waktu Pengerjaan Telah Berakhir</h3><p class="text-muted">Batas waktu (deadline) tugas ini sudah terlewati. Anda tidak dapat mengerjakan kuis ini kecuali guru telah memberikan perpanjangan waktu.</p><a href="tugas.php" class="btn btn-primary mt-2"><i class="fas fa-arrow-left mr-1"></i> Kembali ke Daftar Tugas</a></div></div></div></section></div>';
    include '../templates/footer.php';
    exit;
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

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><?= htmlspecialchars($tugas['jenis_tugas']) ?>: <?= htmlspecialchars($tugas['judul']) ?></h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="tugas.php">Daftar Tugas</a></div>
                <div class="breadcrumb-item active">Kerjakan Ujian</div>
            </div>
        </div>

        <div class="section-body">
            <div class="mb-3">
                <a href="tugas.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left mr-1"></i> Kembali ke Daftar Tugas</a>
            </div>

            <?php if (!empty($submission)): ?>
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
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-light">
                            <h4><i class="fas fa-laptop-code mr-2"></i>Soal <?= htmlspecialchars($tugas['jenis_tugas']) ?> (<?= count($soal_list) ?> Butir Soal)</h4>
                        </div>
                        <div class="card-body">
                            <?php foreach ($soal_list as $idx => $soal): ?>
                                <div class="card border mb-4 shadow-sm">
                                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                        <span class="font-weight-bold text-dark">Soal No. <?= $idx + 1 ?></span>
                                        <span class="badge badge-secondary">Bobot: <?= (int)$soal['bobot'] ?></span>
                                    </div>
                                    <div class="card-body">
                                        <p class="font-weight-bold text-dark" style="font-size: 15px; line-height: 1.6;"><?= nl2br(htmlspecialchars($soal['pertanyaan'])) ?></p>
                                        
                                        <?php if ($soal['tipe_soal'] === 'Pilihan Ganda'): ?>
                                            <div class="pl-2">
                                                <?php foreach (['A' => $soal['pilihan_a'], 'B' => $soal['pilihan_b'], 'C' => $soal['pilihan_c'], 'D' => $soal['pilihan_d'], 'E' => $soal['pilihan_e']] as $k => $opt): ?>
                                                    <?php if (!empty($opt)): ?>
                                                        <div class="custom-control custom-radio mb-2">
                                                            <input type="radio" id="jwb_<?= $soal['id'] ?>_<?= $k ?>" name="jawaban[<?= $soal['id'] ?>]" value="<?= $k ?>" class="custom-control-input" required>
                                                            <label class="custom-control-label text-dark" for="jwb_<?= $soal['id'] ?>_<?= $k ?>"><strong><?= $k ?>.</strong> <?= htmlspecialchars($opt) ?></label>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php elseif ($soal['tipe_soal'] === 'Pilihan Ganda Kompleks'): ?>
                                            <div class="pl-2">
                                                <small class="text-info d-block mb-2"><i class="fas fa-info-circle mr-1"></i> Pilihan Ganda Kompleks (Dapat memilih lebih dari 1 jawaban)</small>
                                                <?php foreach (['A' => $soal['pilihan_a'], 'B' => $soal['pilihan_b'], 'C' => $soal['pilihan_c'], 'D' => $soal['pilihan_d'], 'E' => $soal['pilihan_e']] as $k => $opt): ?>
                                                    <?php if (!empty($opt)): ?>
                                                        <div class="custom-control custom-checkbox mb-2">
                                                            <input type="checkbox" id="jwb_k_<?= $soal['id'] ?>_<?= $k ?>" name="jawaban[<?= $soal['id'] ?>][]" value="<?= $k ?>" class="custom-control-input">
                                                            <label class="custom-control-label text-dark" for="jwb_k_<?= $soal['id'] ?>_<?= $k ?>"><strong><?= $k ?>.</strong> <?= htmlspecialchars($opt) ?></label>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="form-group mb-0">
                                                <textarea name="jawaban[<?= $soal['id'] ?>]" class="form-control" rows="4" placeholder="Tuliskan jawaban essay / uraian Anda di sini..." required></textarea>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <div class="text-center mt-4">
                                <button type="button" id="btnSubmitKuis" class="btn btn-success btn-lg px-5 font-weight-bold">
                                    <i class="fas fa-paper-plane mr-2"></i> Serahkan Jawaban Kuis
                                </button>
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
$js_page[] = <<<'JS'
$(document).ready(function() {
    var violationCount = 0;
    var maxViolations = 3;

    function checkVisibility() {
        if (document.hidden || document.visibilityState === 'hidden') {
            violationCount++;
            var sisa = maxViolations - violationCount;
            if (violationCount >= maxViolations) {
                Swal.fire({
                    title: 'Ujian Dihentikan!',
                    text: 'Anda terdeteksi meninggalkan halaman ujian sebanyak 3 kali. Sesuai aturan, pengerjaan kuis/CBT dihentikan dan nilai dianggap 0.',
                    icon: 'error',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    confirmButtonText: 'Keluar ke Daftar Tugas'
                }).then(function() {
                    window.location.href = 'tugas.php';
                });
                $('#formKerjakanKuis').html('<div class="alert alert-danger text-center font-weight-bold p-4">Ujian dibatalkan karena terdeteksi berpindah tab/jendela.</div>');
            } else {
                Swal.fire({
                    title: 'Peringatan (' + violationCount + '/' + maxViolations + ')',
                    text: 'Jangan membuka tab atau jendela lain! Jika Anda meninggalkan halaman ini ' + sisa + ' kali lagi, ujian akan dihentikan secara otomatis.',
                    icon: 'warning',
                    confirmButtonText: 'Saya Mengerti'
                });
            }
        }
    }

    document.addEventListener('visibilitychange', checkVisibility);
    window.addEventListener('blur', checkVisibility);

    $('#btnSubmitKuis').on('click', function(e) {
        e.preventDefault();
        Swal.fire({
            title: 'Serahkan Jawaban Kuis?',
            text: 'Pastikan semua jawaban sudah terisi dengan benar. Anda tidak dapat mengubah jawaban setelah diserahkan.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Serahkan!',
            cancelButtonText: 'Batal'
        }).then(function(res) {
            if (res.isConfirmed) {
                $('#formKerjakanKuis').submit();
            }
        });
    });
});
JS;
include '../templates/footer.php';
?>
