<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['guru', 'wali', 'admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$is_kepala = ($user_level === 'kepala_madrasah') || (($_GET['session_type'] ?? '') === 'kepala_madrasah');
$can_crud = !$is_kepala;

$id_tugas = (int)($_GET['id'] ?? 0);
if ($id_tugas <= 0) {
    redirect('tugas.php');
}

// Deteksi kelas wali jika login sebagai wali
$wali_kelas_id = 0;
if ($user_level !== 'admin' && !$is_kepala) {
    try {
        $stWali = $pdo->prepare("SELECT id_kelas FROM tb_kelas WHERE wali_kelas = ? OR wali_kelas = (SELECT nama_guru FROM tb_guru WHERE id_guru = ?)");
        $stWali->execute([$guru_id, $guru_id]);
        $wali_kelas_id = (int)$stWali->fetchColumn() ?: 0;
    } catch (Throwable $e) {}
}

if ($user_level === 'admin' || $is_kepala) {
    $auth_sql = "t.id = ?";
    $auth_params = [$id_tugas];
} else {
    // Guru pengampu tugas atau Wali Kelas target dapat mengakses
    $auth_sql = "t.id = ? AND (t.id_guru = ? " . ($wali_kelas_id > 0 ? "OR t.id_kelas = $wali_kelas_id" : "") . ")";
    $auth_params = [$id_tugas, $guru_id];
}

// Fetch tugas info
$stmt = $pdo->prepare("
    SELECT t.*, m.nama_mapel, k.nama_kelas, g.nama_guru
    FROM tb_tugas t
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = t.id_mapel
    LEFT JOIN tb_kelas k ON k.id_kelas = t.id_kelas
    LEFT JOIN tb_guru g ON g.id_guru = t.id_guru
    WHERE $auth_sql
    LIMIT 1
");
$stmt->execute($auth_params);
$tugas = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tugas) {
    redirect('tugas.php');
}

$message = null;

// Handle periksa / nilai / feedback
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_crud) {
        $message = ['type' => 'danger', 'text' => 'Anda tidak memiliki hak akses untuk mengubah nilai.'];
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'nilai_tugas') {
        $id_pengumpulan = (int)($_POST['id_pengumpulan'] ?? 0);
        $id_siswa = (int)($_POST['id_siswa'] ?? 0);
        $nilai = $_POST['nilai'] !== '' ? (float)$_POST['nilai'] : null;
        $status_periksa = in_array($_POST['status_periksa'] ?? '', ['Belum Diperiksa', 'Sudah Diperiksa', 'Perlu Revisi'], true) ? $_POST['status_periksa'] : 'Sudah Diperiksa';
        $feedback = trim((string)($_POST['feedback'] ?? ''));

        try {
            if ($id_pengumpulan > 0) {
                $upd = $pdo->prepare("
                    UPDATE tb_tugas_pengumpulan SET
                        nilai = ?, status_periksa = ?, feedback = ?, diperiksa_at = NOW()
                    WHERE id = ? AND id_tugas = ?
                ");
                $upd->execute([$nilai, $status_periksa, $feedback, $id_pengumpulan, $id_tugas]);
            } elseif ($id_siswa > 0) {
                // Guru memasukkan nilai manual untuk siswa yang belum submit berkas
                $ins = $pdo->prepare("
                    INSERT INTO tb_tugas_pengumpulan (
                        id_tugas, id_siswa, nilai, status_periksa, feedback, diperiksa_at, tgl_kumpul
                    ) VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        nilai = VALUES(nilai), status_periksa = VALUES(status_periksa), feedback = VALUES(feedback), diperiksa_at = NOW()
                ");
                $ins->execute([$id_tugas, $id_siswa, $nilai, $status_periksa, $feedback]);
            }
            $message = ['type' => 'success', 'text' => 'Nilai dan feedback berhasil disimpan.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menyimpan nilai: ' . $e->getMessage()];
        }
    }
}
}

// Fetch all students in this class with their submission status
$students_stmt = $pdo->prepare("
    SELECT s.id_siswa, s.nama_siswa, s.nisn,
           tp.id AS id_pengumpulan, tp.file_path, tp.catatan_siswa, tp.tgl_kumpul,
           tp.keterlambatan, tp.nilai, tp.status_periksa, tp.feedback, tp.diperiksa_at
    FROM tb_siswa s
    LEFT JOIN tb_tugas_pengumpulan tp ON tp.id_siswa = s.id_siswa AND tp.id_tugas = ?
    WHERE s.id_kelas = ?
    ORDER BY s.nama_siswa ASC
");
$students_stmt->execute([$id_tugas, $tugas['id_kelas']]);
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$jml_siswa = count($students);
$sudah_kumpul = 0;
$belum_kumpul = 0;
$terlambat = 0;
$sudah_diperiksa = 0;
$belum_diperiksa = 0;

$deadline_ts = !empty($tugas['deadline']) ? strtotime($tugas['deadline']) : null;

foreach ($students as $st) {
    if (!empty($st['tgl_kumpul'])) {
        $sudah_kumpul++;
        if ($deadline_ts && strtotime($st['tgl_kumpul']) > $deadline_ts) {
            $terlambat++;
        }
        if (($st['status_periksa'] ?? '') === 'Sudah Diperiksa') {
            $sudah_diperiksa++;
        } else {
            $belum_diperiksa++;
        }
    } else {
        $belum_kumpul++;
        $belum_diperiksa++;
    }
}

$page_title = 'Detail Tugas: ' . $tugas['judul'];
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$can_crud_js = json_encode((bool)$can_crud);
$js_page = [<<<JS
$(document).ready(function() {
    if ($('#table-pengumpulan').length) {
        $('#table-pengumpulan').DataTable({
            'order': [[1, 'asc']],
            'columnDefs': [{ 'sortable': false, 'targets': [8, 9] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada data pengumpulan',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari Siswa:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    $(document).on('click', '.btn-periksa', function() {
        var data = $(this).data('json');
        $('#mdl_id_pengumpulan').val(data.id_pengumpulan || '');
        $('#mdl_id_siswa').val(data.id_siswa);
        $('#mdl_nama_siswa').text(data.nama_siswa);
        $('#mdl_nilai').val(data.nilai !== null ? data.nilai : '');
        $('#mdl_status_periksa').val(data.status_periksa || 'Sudah Diperiksa');
        $('#mdl_feedback').val(data.feedback || '');
        $('#mdl_nilai, #mdl_status_periksa, #mdl_feedback').prop('disabled', !$can_crud_js);

        if (data.file_path) {
            var ext = data.file_path.split('.').pop().toLowerCase();
            $('#mdl_file_wrap').html('<a href="preview_tugas.php?id_pengumpulan=' + data.id_pengumpulan + '" target="_blank" class="btn btn-sm btn-primary"><i class="fas fa-book-reader mr-1"></i>Baca (' + ext.toUpperCase() + ')</a>');
        } else {
            $('#mdl_file_wrap').html('<span class="text-muted">Tidak ada berkas yang diunggah siswa</span>');
        }
        $('#mdl_catatan_siswa').text(data.catatan_siswa || '-');
        $('#modalPeriksa').modal('show');
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
        <div class="section-header d-flex justify-content-between">
            <div>
                <h1>Detail Tugas</h1>
                <?php echo render_breadcrumb(); ?>
            </div>
            <a href="tugas.php" class="btn btn-secondary"><i class="fas fa-arrow-left mr-1"></i> Kembali ke Daftar</a>
        </div>

        <div class="section-body">
            <!-- Informasi Tugas & Statistik -->
            <div class="row">
                <!-- Info Tugas -->
                <div class="col-lg-7 mb-4">
                    <div class="card h-100">
                        <div class="card-header bg-light">
                            <h4><i class="fas fa-info-circle mr-2"></i>Informasi Tugas</h4>
                            <div class="card-header-action">
                                <span class="badge badge-<?= $tugas['status'] === 'Aktif' ? 'success' : 'secondary' ?>">
                                    <?= htmlspecialchars($tugas['status']) ?>
                                </span>
                            </div>
                        </div>
                        <div class="card-body">
                            <h5 class="text-primary"><?= htmlspecialchars($tugas['judul']) ?></h5>
                            <table class="table table-sm table-bordered mt-3">
                                <tr><th width="30%">Mata Pelajaran</th><td><?= htmlspecialchars($tugas['nama_mapel'] ?? '-') ?></td></tr>
                                <tr><th>Kelas</th><td><?= htmlspecialchars($tugas['nama_kelas'] ?? '-') ?></td></tr>
                                <tr><th>Materi / TP</th><td><?= htmlspecialchars($tugas['materi_tp'] ?? '-') ?></td></tr>
                                <tr><th>Jenis Tugas</th><td><span class="badge badge-light border"><?= htmlspecialchars($tugas['jenis_tugas']) ?></span></td></tr>
                                <tr><th>Tanggal Mulai</th><td><?= !empty($tugas['tgl_mulai']) ? date('d/m/Y H:i', strtotime($tugas['tgl_mulai'])) : '-' ?></td></tr>
                                <tr><th>Deadline</th><td class="font-weight-bold text-danger"><?= !empty($tugas['deadline']) ? date('d/m/Y H:i', strtotime($tugas['deadline'])) : '-' ?></td></tr>
                                <tr><th>Nilai Maksimal</th><td><?= (int)$tugas['nilai_maksimal'] ?></td></tr>
                                <tr>
                                    <th>Lampiran Berkas</th>
                                    <td>
                                        <?php if (!empty($tugas['lampiran'])): ?>
                                            <a href="preview_tugas.php?id=<?= (int)$tugas['id'] ?>" target="_blank" class="btn btn-sm btn-primary">
                                                <i class="fas fa-book-reader mr-1"></i> Baca Dokumen
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">Tidak ada lampiran</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Instruksi</th>
                                    <td style="white-space: pre-wrap;"><?= htmlspecialchars($tugas['instruksi'] ?? '-') ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Statistik -->
                <div class="col-lg-5 mb-4">
                    <div class="card h-100">
                        <div class="card-header bg-light">
                            <h4><i class="fas fa-chart-pie mr-2"></i>Statistik Pengumpulan</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-6 mb-3">
                                    <div class="border rounded p-3 text-center">
                                        <div class="text-muted small">Total Siswa</div>
                                        <h3 class="mb-0 text-dark"><?= $jml_siswa ?></h3>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="border rounded p-3 text-center">
                                        <div class="text-muted small">Sudah</div>
                                        <h3 class="mb-0 text-success"><?= $sudah_kumpul ?></h3>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="border rounded p-3 text-center">
                                        <div class="text-muted small">Belum</div>
                                        <h3 class="mb-0 text-danger"><?= $belum_kumpul ?></h3>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="border rounded p-3 text-center">
                                        <div class="text-muted small">Terlambat</div>
                                        <h3 class="mb-0 text-warning"><?= $terlambat ?></h3>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-3 text-center">
                                        <div class="text-muted small">Sudah Diperiksa</div>
                                        <h3 class="mb-0 text-info"><?= $sudah_diperiksa ?></h3>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-3 text-center">
                                        <div class="text-muted small">Belum Diperiksa</div>
                                        <h3 class="mb-0 text-secondary"><?= $belum_diperiksa ?></h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabel Pengumpulan Tugas -->
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-users-cog mr-2"></i>Tabel Pengumpulan Siswa</h4>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered table-sm" id="table-pengumpulan">
                            <thead>
                                <tr>
                                    <th width="4%">No</th>
                                    <th>Nama Siswa</th>
                                    <th>NIS/NISN</th>
                                    <th>Status Kumpul</th>
                                    <th>Tanggal Kumpul</th>
                                    <th>Keterlambatan</th>
                                    <th>Nilai</th>
                                    <th>Status Pemeriksaan</th>
                                    <th width="10%" class="text-center">Berkas Siswa</th>
                                    <th width="12%" class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $i => $s): ?>
                                    <?php
                                    $is_kumpul = !empty($s['tgl_kumpul']);
                                    $keterlambatan_label = '-';
                                    if ($is_kumpul && $deadline_ts) {
                                        $diff = strtotime($s['tgl_kumpul']) - $deadline_ts;
                                        if ($diff > 0) {
                                            $jam = floor($diff / 3600);
                                            $keterlambatan_label = $jam > 24 ? floor($jam / 24) . ' hari' : $jam . ' jam';
                                        } else {
                                            $keterlambatan_label = 'Tepat Waktu';
                                        }
                                    }
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td><strong><?= htmlspecialchars($s['nama_siswa']) ?></strong></td>
                                        <td><?= htmlspecialchars($s['nisn'] ?? '-') ?></td>
                                        <td class="text-center">
                                            <?php if ($is_kumpul): ?>
                                                <span class="badge badge-success">Sudah</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Belum</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $is_kumpul ? date('d/m/Y H:i', strtotime($s['tgl_kumpul'])) : '-' ?></td>
                                        <td>
                                            <?php if ($keterlambatan_label === 'Tepat Waktu'): ?>
                                                <span class="badge badge-light text-success"><?= $keterlambatan_label ?></span>
                                            <?php elseif ($keterlambatan_label !== '-'): ?>
                                                <span class="badge badge-danger">Telat <?= $keterlambatan_label ?></span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center font-weight-bold text-primary" style="font-size: 15px;">
                                            <?= $s['nilai'] !== null ? (float)$s['nilai'] : '-' ?>
                                        </td>
                                        <td class="text-center">
                                            <?php
                                            $st_periksa = $s['status_periksa'] ?? 'Belum Diperiksa';
                                            $p_badge = $st_periksa === 'Sudah Diperiksa' ? 'success' : ($st_periksa === 'Perlu Revisi' ? 'warning' : 'secondary');
                                            ?>
                                            <span class="badge badge-<?= $p_badge ?>"><?= htmlspecialchars($st_periksa) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <?php if (!empty($s['file_path'])): ?>
                                                <?php $ext = strtoupper(pathinfo($s['file_path'], PATHINFO_EXTENSION)); ?>
                                                <a href="preview_tugas.php?id_pengumpulan=<?= (int)$s['id_pengumpulan'] ?>" target="_blank" class="btn btn-primary btn-sm" title="Baca Berkas Siswa (Tab Baru)">
                                                    <i class="fas fa-book-reader mr-1"></i> <?= $ext ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($can_crud): ?>
                                            <button type="button" class="btn btn-primary btn-sm btn-periksa"
                                                data-json='<?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>'
                                                title="Periksa, Nilai & Feedback">
                                                <i class="fas fa-check-circle mr-1"></i> Periksa / Nilai
                                            </button>
                                            <?php else: ?>
                                            <button type="button" class="btn btn-info btn-sm btn-periksa"
                                                data-json='<?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>'
                                                title="Lihat Detail Nilai & Feedback">
                                                <i class="fas fa-eye mr-1"></i> Detail
                                            </button>
                                            <?php endif; ?>
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

<!-- Modal Periksa, Nilai & Feedback -->
<div class="modal fade" id="modalPeriksa" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="nilai_tugas">
                <input type="hidden" name="id_pengumpulan" id="mdl_id_pengumpulan">
                <input type="hidden" name="id_siswa" id="mdl_id_siswa">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-award mr-2"></i>Pemeriksaan Tugas</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-light border">
                        Siswa: <strong id="mdl_nama_siswa"></strong>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Berkas Pengumpulan Siswa</label>
                        <div id="mdl_file_wrap"></div>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Catatan Siswa</label>
                        <div id="mdl_catatan_siswa" class="p-3 bg-light rounded border font-weight-normal" style="font-size: 14px; line-height: 1.6; color: #1e293b !important; white-space: pre-wrap;"></div>
                    </div>
                    <hr>
                    <div class="form-group">
                        <label class="font-weight-bold">Nilai (Maks: <?= (int)$tugas['nilai_maksimal'] ?>) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="nilai" id="mdl_nilai" class="form-control" required min="0" max="<?= (int)$tugas['nilai_maksimal'] ?>">
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Status Pemeriksaan</label>
                        <select name="status_periksa" id="mdl_status_periksa" class="form-control">
                            <option value="Sudah Diperiksa">Sudah Diperiksa</option>
                            <option value="Perlu Revisi">Perlu Revisi</option>
                            <option value="Belum Diperiksa">Belum Diperiksa</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Feedback / Komentar Guru</label>
                        <textarea name="feedback" id="mdl_feedback" class="form-control" rows="3" placeholder="Berikan catatan perbaikan atau apresiasi..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
                    <?php if ($can_crud): ?>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan Penilaian</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
