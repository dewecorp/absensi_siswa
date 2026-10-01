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
    $stVer = $pdo->prepare("SELECT id, id_guru, deadline, status FROM tb_tugas WHERE id = ? AND id_kelas = ? AND status = 'Aktif'");
    $stVer->execute([$id_tugas, $id_kelas]);
    $tugas_ver = $stVer->fetch(PDO::FETCH_ASSOC);

    if (!$tugas_ver) {
        $message = ['type' => 'danger', 'text' => 'Tugas tidak ditemukan atau sudah tidak aktif.'];
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
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-tugas-siswa').length) {
        $('#table-tugas-siswa').DataTable({
            'order': [[0, 'asc']],
            'columnDefs': [{ 'sortable': false, 'targets': [8] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada tugas yang sesuai',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari Tugas:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

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
            $('#det_lampiran').html('<a href="../uploads/tugas/' + String(data.lampiran).split('/').map(encodeURIComponent).join('/') + '" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-download mr-1"></i> Unduh Lampiran Guru</a>');
        } else {
            $('#det_lampiran').html('<span class="text-muted">Tidak ada berkas lampiran</span>');
        }

        if (data.feedback) {
            $('#det_feedback_wrap').show();
            $('#det_feedback').text(data.feedback);
        } else {
            $('#det_feedback_wrap').hide();
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
                                <i class="fas fa-clock mr-1"></i> Belum Dikumpulkan
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $filter_status === 'sudah' ? 'active' : '' ?>" href="tugas.php?status=sudah">
                                <i class="fas fa-check mr-1"></i> Sudah Dikumpulkan
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $filter_status === 'dinilai' ? 'active' : '' ?>" href="tugas.php?status=dinilai">
                                <i class="fas fa-award mr-1"></i> Sudah Dinilai
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Tabel Tugas Siswa -->
            <div class="card">
                <div class="card-header">
                    <h4>Daftar Tugas Kelas <?= htmlspecialchars($nama_kelas) ?></h4>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered table-sm" id="table-tugas-siswa">
                            <thead>
                                <tr>
                                    <th width="4%" class="text-center">No</th>
                                    <th>Judul Tugas</th>
                                    <th>Mata Pelajaran</th>
                                    <th>Guru</th>
                                    <th>Batas Waktu (Deadline)</th>
                                    <th class="text-center">Status Kumpul</th>
                                    <th class="text-center">Nilai</th>
                                    <th class="text-center">Pemeriksaan</th>
                                    <th class="text-center" width="16%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tasks as $i => $t): ?>
                                    <?php
                                    $is_submitted = !empty($t['id_pengumpulan']);
                                    $is_overdue = !empty($t['deadline']) && strtotime($t['deadline']) < time() && !$is_submitted;
                                    $p_badge = ($t['status_periksa'] ?? '') === 'Sudah Diperiksa' ? 'success' : (($t['status_periksa'] ?? '') === 'Perlu Revisi' ? 'warning' : 'secondary');
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td>
                                            <strong class="text-dark"><?= htmlspecialchars($t['judul']) ?></strong>
                                            <?php if ($is_overdue): ?>
                                                <span class="badge badge-danger ml-1" style="font-size:10px;">Lewat Batas</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge badge-light border"><?= htmlspecialchars($t['nama_mapel'] ?? '-') ?></span></td>
                                        <td><small><?= htmlspecialchars($t['nama_guru'] ?? '-') ?></small></td>
                                        <td>
                                            <?php if (!empty($t['deadline'])): ?>
                                                <?= date('d/m/Y H:i', strtotime($t['deadline'])) ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($is_submitted): ?>
                                                <span class="badge badge-success"><i class="fas fa-check mr-1"></i>Dikumpulkan</span>
                                                <small class="d-block text-muted"><?= date('d/m H:i', strtotime($t['tgl_kumpul'])) ?></small>
                                            <?php else: ?>
                                                <span class="badge badge-warning"><i class="fas fa-hourglass-half mr-1"></i>Belum</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center font-weight-bold" style="font-size: 15px;">
                                            <?= $t['nilai'] !== null ? '<span class="text-primary font-weight-bold">' . (float)$t['nilai'] . '</span>' : '-' ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-<?= $p_badge ?>"><?= htmlspecialchars($t['status_periksa'] ?? 'Belum') ?></span>
                                        </td>
                                        <td class="text-center" style="white-space: nowrap;">
                                            <button type="button" class="btn btn-info btn-sm btn-lihat-tugas" data-json='<?= htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8') ?>' title="Lihat Petunjuk Tugas">
                                                <i class="fas fa-eye mr-1"></i> Petunjuk
                                            </button>
                                            <button type="button" class="btn btn-<?= $is_submitted ? 'warning' : 'primary' ?> btn-sm btn-kumpul-modal" data-json='<?= htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8') ?>' title="<?= $is_submitted ? 'Perbarui Pengumpulan' : 'Kumpulkan Tugas' ?>">
                                                <i class="fas fa-upload mr-1"></i> <?= $is_submitted ? 'Ubah' : 'Kumpul' ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
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
            <div class="modal-footer border-top py-2">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
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
