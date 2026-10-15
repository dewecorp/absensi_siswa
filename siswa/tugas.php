<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['siswa'])) {
    redirect('../login.php');
}

$id_siswa = (int)($_SESSION['user_id'] ?? 0);
$stmt = $pdo->prepare("SELECT s.*, k.nama_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_siswa = ?");
$stmt->execute([$id_siswa]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student || empty($student['id_kelas'])) {
    echo "Data kelas siswa tidak ditemukan.";
    exit;
}

$id_kelas = (int)$student['id_kelas'];
$nama_kelas = (string)$student['nama_kelas'];

$upload_dir = dirname(__DIR__) . '/uploads/tugas/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0755, true);
}

$message = null;

// Handle submit tugas siswa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'kumpul_tugas') {
    $id_tugas = (int)($_POST['id_tugas'] ?? 0);
    $catatan_siswa = trim((string)($_POST['catatan_siswa'] ?? ''));

    // Verify task exists and is for this class
    $stVer = $pdo->prepare("SELECT id, id_guru, tgl_mulai, deadline, status FROM tb_tugas WHERE id = ? AND id_kelas = ? AND status = 'Aktif'");
    $stVer->execute([$id_tugas, $id_kelas]);
    $tugas_ver = $stVer->fetch(PDO::FETCH_ASSOC);

    if (!$tugas_ver) {
        $message = ['type' => 'danger', 'text' => 'Tugas tidak ditemukan atau sudah tidak aktif.'];
    } elseif (!empty($tugas_ver['tgl_mulai']) && strtotime($tugas_ver['tgl_mulai']) > time()) {
        $message = ['type' => 'danger', 'text' => 'Tugas belum dibuka (waktu mulai belum tercapai).'];
    } else {
        $submit_dir = guru_upload_dir($pdo, (int)($tugas_ver['id_guru'] ?? 0), 'tugas');
        $file_path = null;
        if (isset($_FILES['file_tugas']) && $_FILES['file_tugas']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['file_tugas']['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'zip', 'rar'];
            if (in_array($ext, $allowed, true)) {
                $filename = 'submit_' . $id_tugas . '_' . $id_siswa . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['file_tugas']['tmp_name'], $submit_dir . $filename)) {
                    $file_path = guru_folder_name($pdo, (int)($tugas_ver['id_guru'] ?? 0)) . '/' . $filename;
                }
            }
        }

        // Hitung status keterlambatan
        $keterlambatan = null;
        if (!empty($tugas_ver['deadline'])) {
            $diff = time() - strtotime($tugas_ver['deadline']);
            if ($diff > 0) {
                $jam = floor($diff / 3600);
                $keterlambatan = $jam > 24 ? floor($jam / 24) . ' hari' : $jam . ' jam';
            }
        }

        try {
            $stIns = $pdo->prepare("
                INSERT INTO tb_tugas_pengumpulan (
                    id_tugas, id_siswa, file_path, catatan_siswa, tgl_kumpul, keterlambatan, status_periksa
                ) VALUES (?, ?, ?, ?, NOW(), ?, 'Belum Diperiksa')
                ON DUPLICATE KEY UPDATE
                    file_path = COALESCE(VALUES(file_path), file_path),
                    catatan_siswa = VALUES(catatan_siswa),
                    tgl_kumpul = NOW(),
                    keterlambatan = VALUES(keterlambatan),
                    status_periksa = 'Belum Diperiksa'
            ");
            $stIns->execute([$id_tugas, $id_siswa, $file_path, $catatan_siswa, $keterlambatan]);
            try {
                $stNt = $pdo->prepare("SELECT t.judul, s.nama_siswa, k.nama_kelas FROM tb_tugas t LEFT JOIN tb_siswa s ON s.id_siswa = ? LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas WHERE t.id = ?");
                $stNt->execute([$id_siswa, $id_tugas]);
                $nt = $stNt->fetch(PDO::FETCH_ASSOC);
                $nm_siswa = trim((string)($nt['nama_siswa'] ?? $student['nama_siswa'] ?? 'Siswa'));
                $judul_t = trim((string)($nt['judul'] ?? 'Tugas'));
                $kelas_n = trim((string)($nt['nama_kelas'] ?? $nama_kelas));
                createNotification($pdo, "Tugas dikumpulkan: {$nm_siswa} - {$judul_t}" . ($kelas_n ? " (Kelas {$kelas_n})" : ""), 'detail_tugas.php?id=' . $id_tugas);
            } catch (Throwable $e) {}
            $message = ['type' => 'success', 'text' => 'Tugas berhasil dikumpulkan.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal mengumpulkan tugas: ' . $e->getMessage()];
        }
    }
}

// Fetch all active tasks for this class with submission status
$stmt = $pdo->prepare("
    SELECT t.*, m.nama_mapel, g.nama_guru,
           tp.id AS id_pengumpulan, tp.file_path AS file_siswa, tp.catatan_siswa,
           tp.tgl_kumpul, tp.keterlambatan, tp.nilai, tp.status_periksa, tp.feedback
    FROM tb_tugas t
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = t.id_mapel
    LEFT JOIN tb_guru g ON g.id_guru = t.id_guru
    LEFT JOIN tb_tugas_pengumpulan tp ON tp.id_tugas = t.id AND tp.id_siswa = ?
    WHERE t.id_kelas = ? AND t.status = 'Aktif'
    ORDER BY (tp.id IS NULL) DESC, t.deadline ASC, t.id DESC
");
$stmt->execute([$id_siswa, $id_kelas]);
$all_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Abaikan pengumpulan prematur (tgl_kumpul < tgl_mulai) agar status konsisten antar siswa
foreach ($all_tasks as &$at) {
    if (!empty($at['tgl_kumpul']) && !empty($at['tgl_mulai']) && strtotime($at['tgl_kumpul']) < strtotime($at['tgl_mulai'])) {
        $at['id_pengumpulan'] = null;
        $at['file_siswa'] = null;
        $at['catatan_siswa'] = null;
        $at['tgl_kumpul'] = null;
        $at['keterlambatan'] = null;
        $at['nilai'] = null;
        $at['status_periksa'] = null;
        $at['feedback'] = null;
    }
}
unset($at);

$filter_status = trim((string)($_GET['status'] ?? ''));
$tasks = $all_tasks;
if ($filter_status === 'belum') {
    $tasks = array_filter($all_tasks, static function ($t) { return empty($t['id_pengumpulan']); });
} elseif ($filter_status === 'sudah') {
    $tasks = array_filter($all_tasks, static function ($t) { return !empty($t['id_pengumpulan']); });
} elseif ($filter_status === 'dinilai') {
    $tasks = array_filter($all_tasks, static function ($t) { return $t['nilai'] !== null; });
}

$page_title = 'Tugas Siswa';
$css_libs = [];
$js_libs = [];

$js_page = [<<<'JS'
$(document).ready(function() {

    // Modal Lihat Petunjuk Tugas
    $(document).on('click', '.btn-lihat-tugas', function() {
        var data = $(this).data('json');
        $('#det_judul').text(data.judul);
        $('#det_mapel').text(data.nama_mapel || '-');
        $('#det_guru').text(data.nama_guru || '-');
        $('#det_materi').text(data.materi_tp || '-');
        $('#det_jenis').text(data.jenis_tugas || 'Individu');
        $('#det_deadline').text(data.deadline || '-');
        $('#det_nilai_maks').text(data.nilai_maksimal || '100');
        $('#det_instruksi').text(data.instruksi || '-');

        if (data.lampiran) {
            $('#det_lampiran').html('<a href="../guru/preview_tugas.php?id=' + data.id + '" target="_blank" class="btn btn-sm btn-primary"><i class="fas fa-book-reader mr-1"></i> Baca Dokumen</a>');
        } else {
            $('#det_lampiran').html('<span class="text-muted">Tidak ada berkas lampiran</span>');
        }

        if (data.feedback) {
            $('#det_feedback_wrap').show();
            $('#det_feedback').text(data.feedback);
        } else {
            $('#det_feedback_wrap').hide();
        }

        if (data.jenis_tugas === 'Kuis' || data.jenis_tugas === 'CBT') {
            $('#det_footer_action').html('<a href="kerjakan_kuis.php?id=' + data.id + '" class="btn btn-success"><i class="fas fa-laptop-code mr-1"></i> Mulai Mengerjakan ' + data.jenis_tugas + ' Sekarang</a>');
        } else {
            $('#det_footer_action').html('');
        }

        $('#modalLihatTugas').modal('show');
    });

    // Modal Kumpulkan Tugas
    $(document).on('click', '.btn-kumpul-modal', function() {
        var data = $(this).data('json');
        $('#kumpul_id_tugas').val(data.id);
        $('#kumpul_judul').text(data.judul);
        $('#kumpul_mapel').text(data.nama_mapel || '-');
        $('#kumpul_deadline').text(data.deadline || '-');
        $('#kumpul_catatan').val(data.catatan_siswa || '');

        if (data.file_siswa) {
            $('#kumpul_status_file').html('<div class="small text-success mb-2"><i class="fas fa-check-circle mr-1"></i> Berkas sebelumnya sudah terunggah: <strong>' + data.file_siswa + '</strong>. Unggah berkas baru bila ingin mengganti.</div>');
        } else {
            $('#kumpul_status_file').html('');
        }

        $('#modalKumpulTugas').modal('show');
    });
});
JS
];

if (!empty($message)) {
    $js_page[] = "Swal.fire({ icon: '" . ($message['type'] === 'danger' ? 'error' : $message['type']) . "', title: '" . ($message['type'] === 'success' ? 'Berhasil' : 'Perhatian') . "', text: " . json_encode($message['text']) . ", timer: 2200, showConfirmButton: false });";
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<style>
.tugas-card-grid .card { transition: box-shadow .2s ease, transform .2s ease; }
.tugas-card-grid .card:hover { box-shadow: 0 10px 28px rgba(20,30,70,.14) !important; transform: translateY(-2px); }
.text-truncate-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
</style>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Tugas Siswa - Kelas <?= htmlspecialchars($nama_kelas) ?></h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Tugas Siswa</div>
            </div>
        </div>

        <div class="section-body">
            <!-- Filter Tabs -->
            <div class="card mb-3">
                <div class="card-body p-2 d-flex flex-wrap align-items-center justify-content-between">
                    <ul class="nav nav-pills" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link <?= $filter_status === '' ? 'active' : '' ?>" href="tugas.php">
                                <i class="fas fa-list mr-1"></i> Semua Tugas (<?= count($all_tasks) ?>)
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $filter_status === 'belum' ? 'active' : '' ?>" href="tugas.php?status=belum">
                                <i class="fas fa-clock mr-1"></i> Belum
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $filter_status === 'sudah' ? 'active' : '' ?>" href="tugas.php?status=sudah">
                                <i class="fas fa-check mr-1"></i> Sudah
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $filter_status === 'dinilai' ? 'active' : '' ?>" href="tugas.php?status=dinilai">
                                <i class="fas fa-award mr-1"></i> Sudah Dinilai
                            </a>
                        </li>
                    </ul>
                    <div class="mt-2 mt-md-0">
                        <a href="bahan_ajar.php" class="btn btn-outline-info btn-sm font-weight-bold" style="border-radius: 20px;">
                            <i class="fas fa-book-reader mr-1"></i> Bahan Ajar
                        </a>
                    </div>
                </div>
            </div>

            <!-- Daftar Tugas Siswa (Card) -->
            <?php if (empty($tasks)): ?>
                <div class="card border-0 shadow-sm" style="border-radius: 12px;">
                    <div class="card-body p-5 text-center text-muted">
                        <i class="far fa-clipboard fa-3x mb-3"></i><br>
                        Tidak ada tugas pada filter ini.
                    </div>
                </div>
            <?php else: ?>
                <div class="row tugas-card-grid">
                    <?php foreach ($tasks as $t): ?>
                        <?php
                        $is_submitted = !empty($t['id_pengumpulan']);
                        $is_upcoming = !empty($t['tgl_mulai']) && strtotime($t['tgl_mulai']) > time();
                        $is_overdue = !empty($t['deadline']) && strtotime($t['deadline']) < time() && !$is_submitted && !$is_upcoming;
                        $is_kuis = in_array($t['jenis_tugas'] ?? '', ['Kuis', 'CBT'], true);
                        if ($is_upcoming) {
                            $st_badge = '<span class="badge badge-warning px-2 py-1 font-weight-bold" style="font-size: 10px; border-radius: 6px;"><i class="fas fa-hourglass-start mr-1"></i>Belum Dibuka</span>';
                        } elseif ($is_submitted) {
                            $st_badge = '<span class="badge badge-success px-2 py-1 font-weight-bold" style="font-size: 10px; border-radius: 6px;"><i class="fas fa-check mr-1"></i>Sudah Dikumpulkan</span>';
                        } elseif ($is_overdue) {
                            $st_badge = '<span class="badge badge-danger px-2 py-1 font-weight-bold" style="font-size: 10px; border-radius: 6px;"><i class="fas fa-exclamation-circle mr-1"></i>Lewat Batas</span>';
                        } else {
                            $st_badge = '<span class="badge badge-warning px-2 py-1 font-weight-bold" style="font-size: 10px; border-radius: 6px;"><i class="fas fa-hourglass-half mr-1"></i>Belum Dikumpulkan</span>';
                        }
                        $p_badge = ($t['status_periksa'] ?? '') === 'Sudah Diperiksa' ? 'success' : ((($t['status_periksa'] ?? '') === 'Perlu Revisi') ? 'warning' : 'secondary');
                        ?>
                        <div class="col-12 col-md-6 col-lg-4 d-flex align-items-stretch mb-4">
                            <div class="card w-100 border shadow-sm" style="border-radius: 12px; overflow: hidden; background: #fff; border: 1px solid #e2e8f0 !important;">
                                <div class="card-body p-3 d-flex flex-column justify-content-between">
                                    <div>
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="badge badge-light border text-dark font-weight-bold px-2 py-1" style="font-size: 11px; border-radius: 6px;">
                                                <i class="fas fa-<?= $is_kuis ? 'laptop-code' : 'tasks' ?> mr-1 text-primary"></i><?= htmlspecialchars($t['jenis_tugas'] ?? 'Tugas') ?>
                                            </span>
                                            <?= $st_badge ?>
                                        </div>

                                        <h6 class="font-weight-bold text-dark mb-1 text-truncate-2" title="<?= htmlspecialchars($t['judul']) ?>" style="font-size: 14.5px; line-height: 1.35; min-height: 38px;">
                                            <?= htmlspecialchars($t['judul']) ?>
                                        </h6>

                                        <div class="d-flex flex-wrap align-items-center mb-2 small text-muted" style="gap: 6px;">
                                            <span class="badge badge-light border text-dark font-weight-bold px-2 py-1" style="border-radius: 4px; font-size: 11px;">
                                                <i class="fas fa-book text-muted mr-1"></i><?= htmlspecialchars($t['nama_mapel'] ?? '-') ?>
                                            </span>
                                            <span class="badge badge-light border text-dark font-weight-bold px-2 py-1" style="border-radius: 4px; font-size: 11px;">
                                                <i class="fas fa-chalkboard-teacher text-muted mr-1"></i><?= htmlspecialchars($t['nama_guru'] ?? '-') ?>
                                            </span>
                                        </div>

                                        <div class="small mb-2 d-flex justify-content-between align-items-center" style="font-size: 11.5px;">
                                            <span class="<?= $is_overdue ? 'text-danger font-weight-bold' : 'text-muted' ?>">
                                                <i class="far fa-clock mr-1"></i>Batas: <?= !empty($t['deadline']) ? date('d/m/Y H:i', strtotime($t['deadline'])) : '-' ?>
                                            </span>
                                            <span class="font-weight-bold <?= $t['nilai'] !== null ? 'text-primary' : 'text-muted' ?>" style="font-size: 14px;">
                                                <?= $t['nilai'] !== null ? 'Nilai: ' . (float)$t['nilai'] : 'Belum Dinilai' ?>
                                            </span>
                                        </div>

                                        <?php if ($is_submitted): ?>
                                            <div class="small text-muted mb-2" style="font-size: 11px;">
                                                <i class="fas fa-check-circle mr-1 text-success"></i>Dikumpulkan: <?= date('d/m/Y H:i', strtotime($t['tgl_kumpul'])) ?>
                                                <?php if (!empty($t['keterlambatan'])): ?>
                                                    <span class="badge badge-warning ml-1" style="font-size: 10px;">Terlambat <?= htmlspecialchars($t['keterlambatan']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($is_kuis): ?>
                                            <div class="small mb-3" style="font-size: 11.5px;">
                                                <?php if ($is_submitted): ?>
                                                    <span class="badge badge-success">Sudah Dikerjakan</span>
                                                <?php else: ?>
                                                    <span class="badge badge-warning">Belum Dikerjakan</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="small mb-3" style="font-size: 11.5px;">
                                                <span class="badge badge-<?= $p_badge ?>"><?= htmlspecialchars($t['status_periksa'] ?? 'Belum Diperiksa') ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="pt-2 border-top d-flex flex-wrap align-items-center" style="border-color: #f1f5f9 !important; gap: 6px;">
                                        <button type="button" class="btn btn-outline-info btn-sm px-2 font-weight-bold btn-lihat-tugas" data-json='<?= htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8') ?>' title="Lihat Petunjuk Tugas" style="border-radius: 6px; font-size: 11.5px;">
                                            <i class="fas fa-eye mr-1"></i>Lihat
                                        </button>
                                        <?php if ($is_upcoming): ?>
                                            <button type="button" class="btn btn-secondary btn-sm px-2 font-weight-bold" disabled title="Tugas Belum Dibuka" style="border-radius: 6px; font-size: 11.5px;">
                                                <i class="fas fa-hourglass-start mr-1"></i>Belum Dibuka
                                            </button>
                                        <?php elseif ($is_overdue): ?>
                                            <button type="button" class="btn btn-secondary btn-sm px-2 font-weight-bold" disabled title="Waktu Habis (Deadline Terlewati)" style="border-radius: 6px; font-size: 11.5px;">
                                                <i class="fas fa-lock mr-1"></i>Waktu Habis
                                            </button>
                                        <?php elseif ($is_kuis): ?>
                                            <a href="kerjakan_kuis.php?id=<?= (int)$t['id'] ?>" class="btn btn-success btn-sm px-2 font-weight-bold" title="Mulai Kerjakan <?= htmlspecialchars($t['jenis_tugas']) ?>" style="border-radius: 6px; font-size: 11.5px;">
                                                <i class="fas fa-laptop-code mr-1"></i><?= $is_submitted ? 'Lihat / Ulangi' : 'Kerjakan' ?>
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-<?= $is_submitted ? 'warning' : 'primary' ?> btn-sm px-2 font-weight-bold btn-kumpul-modal" data-json='<?= htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8') ?>' title="<?= $is_submitted ? 'Perbarui Pengumpulan' : 'Kumpulkan Tugas' ?>" style="border-radius: 6px; font-size: 11.5px;">
                                                <i class="fas fa-upload mr-1"></i><?= $is_submitted ? 'Perbarui' : 'Kumpulkan' ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- Modal Lihat Petunjuk Tugas -->
<div class="modal fade" id="modalLihatTugas" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header border-bottom py-3">
                <h5 class="modal-title text-primary"><i class="fas fa-clipboard-list mr-2"></i>Petunjuk & Informasi Tugas</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body p-3">
                <h4 id="det_judul" class="text-dark font-weight-bold mb-3"></h4>
                <table class="table table-bordered table-sm mb-3">
                    <tr><th width="30%">Mata Pelajaran</th><td id="det_mapel"></td></tr>
                    <tr><th>Guru Pengampu</th><td id="det_guru"></td></tr>
                    <tr><th>Materi / TP</th><td id="det_materi"></td></tr>
                    <tr><th>Jenis Tugas</th><td id="det_jenis"></td></tr>
                    <tr><th>Batas Waktu (Deadline)</th><td id="det_deadline" class="font-weight-bold text-danger"></td></tr>
                    <tr><th>Nilai Maksimal</th><td id="det_nilai_maks"></td></tr>
                    <tr><th>Berkas Lampiran Soal</th><td id="det_lampiran"></td></tr>
                </table>
                <div class="card card-primary border mb-3">
                    <div class="card-header bg-light py-2"><strong class="text-primary"><i class="fas fa-info-circle mr-1"></i> Instruksi Pengerjaan</strong></div>
                    <div class="card-body p-3 text-dark" id="det_instruksi" style="white-space: pre-wrap; line-height: 1.6;"></div>
                </div>
                <div id="det_feedback_wrap" style="display:none;">
                    <div class="alert alert-info mb-0">
                        <strong><i class="fas fa-comment-dots mr-1"></i> Catatan / Feedback Guru:</strong>
                        <div id="det_feedback" class="mt-1" style="white-space: pre-wrap;"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top py-2 d-flex justify-content-between">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
                <div id="det_footer_action"></div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Kumpulkan Tugas -->
<div class="modal fade" id="modalKumpulTugas" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="kumpul_tugas">
                <input type="hidden" name="id_tugas" id="kumpul_id_tugas" value="">
                <div class="modal-header border-bottom py-3">
                    <h5 class="modal-title text-primary"><i class="fas fa-upload mr-2"></i>Kumpulkan Tugas</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body p-3">
                    <div class="bg-light p-3 rounded mb-3 border">
                        <h6 id="kumpul_judul" class="font-weight-bold text-dark mb-1"></h6>
                        <div class="small text-muted">Mapel: <span id="kumpul_mapel"></span> &bull; Deadline: <strong id="kumpul_deadline" class="text-danger"></strong></div>
                    </div>

                    <div id="kumpul_status_file"></div>

                    <div class="form-group">
                        <label class="font-weight-bold">Unggah Berkas Tugas Siswa</label>
                        <input type="file" name="file_tugas" class="form-control-file">
                        <small class="text-muted d-block mt-1">Format didukung: PDF, Word, Excel, Gambar (JPG/PNG), ZIP, RAR.</small>
                    </div>

                    <div class="form-group mb-0">
                        <label class="font-weight-bold">Catatan Pengerjaan (Opsional)</label>
                        <textarea name="catatan_siswa" id="kumpul_catatan" class="form-control" rows="3" placeholder="Tuliskan keterangan jawaban singkat atau link tugas tambahan..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top py-2">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane mr-1"></i> Kirim Tugas</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
