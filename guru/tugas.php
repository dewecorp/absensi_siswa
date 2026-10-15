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

$is_admin_or_kepala = in_array($user_level, ['admin', 'kepala_madrasah'], true) || in_array($_GET['session_type'] ?? '', ['admin', 'kepala_madrasah'], true);
$can_crud = !$is_admin_or_kepala;

$upload_dir = guru_upload_dir($pdo, $guru_id, 'tugas');

$message = null;

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_crud) {
        $message = ['type' => 'danger', 'text' => 'Anda tidak memiliki hak akses untuk mengubah data ini.'];
    } else {
        $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $judul = trim((string)($_POST['judul'] ?? ''));
        $id_mapel = (int)($_POST['id_mapel'] ?? 0) ?: null;
        $id_kelas = (int)($_POST['id_kelas'] ?? 0) ?: null;
        $materi_tp = trim((string)($_POST['materi_tp'] ?? ''));
        $jenis_tugas = trim((string)($_POST['jenis_tugas'] ?? 'Individu'));
        if ($judul !== '' && $jenis_tugas !== '') {
            $judul_bersih = preg_replace('/^(individu|kelompok|proyek|praktik|portofolio|kuis|cbt)\s+/i', '', $judul);
            $judul = $jenis_tugas . ' ' . ltrim($judul_bersih);
        }
        $instruksi = trim((string)($_POST['instruksi'] ?? ''));
        $tgl_mulai = !empty($_POST['tgl_mulai']) ? date('Y-m-d H:i:s', strtotime($_POST['tgl_mulai'])) : date('Y-m-d H:i:s');
        $deadline = !empty($_POST['deadline']) ? date('Y-m-d H:i:s', strtotime($_POST['deadline'])) : null;
        $nilai_maksimal = (int)($_POST['nilai_maksimal'] ?? 100) ?: 100;
        $durasi_menit = (int)($_POST['durasi_menit'] ?? 90) ?: 90;
        $status = in_array($_POST['status'] ?? '', ['Draft', 'Aktif', 'Selesai', 'Arsip'], true) ? $_POST['status'] : 'Aktif';

        $lampiran = null;
        if (isset($_FILES['lampiran_file']) && $_FILES['lampiran_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['lampiran_file']['name'], PATHINFO_EXTENSION));
            $filename = 'tugas_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['lampiran_file']['tmp_name'], $upload_dir . $filename)) {
                $lampiran = guru_folder_name($pdo, $guru_id) . '/' . $filename;
            }
        }

        if ($judul === '') {
            $message = ['type' => 'warning', 'text' => 'Judul tugas wajib diisi.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_tugas (
                            id_guru, judul, id_mapel, id_kelas, materi_tp, jenis_tugas,
                            instruksi, tgl_mulai, deadline, nilai_maksimal, durasi_menit, lampiran, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $guru_id, $judul, $id_mapel, $id_kelas, $materi_tp, $jenis_tugas,
                        $instruksi, $tgl_mulai, $deadline, $nilai_maksimal, $durasi_menit, $lampiran, $status
                    ]);

                    // Kirim notifikasi sistem untuk siswa
                    try {
                        $stkName = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas = ?");
                        $stkName->execute([$id_kelas]);
                        $kelas_name_notif = $stkName->fetchColumn() ?: '';
                        createNotification($pdo, "Tugas Baru: {$judul}" . ($kelas_name_notif ? " (Kelas {$kelas_name_notif})" : ""), 'dashboard.php');
                    } catch (Throwable $e) {}

                    $message = ['type' => 'success', 'text' => 'Tugas berhasil dibuat.'];
                } else {
                    $sql = "
                        UPDATE tb_tugas SET
                            judul = ?, id_mapel = ?, id_kelas = ?, materi_tp = ?, jenis_tugas = ?,
                            instruksi = ?, tgl_mulai = ?, deadline = ?, nilai_maksimal = ?, durasi_menit = ?, status = ?
                    ";
                    $params = [
                        $judul, $id_mapel, $id_kelas, $materi_tp, $jenis_tugas,
                        $instruksi, $tgl_mulai, $deadline, $nilai_maksimal, $durasi_menit, $status
                    ];
                    if ($lampiran !== null) {
                        try {
                            $stOld = $pdo->prepare("SELECT lampiran FROM tb_tugas WHERE id = ? AND id_guru = ?");
                            $stOld->execute([$id, $guru_id]);
                            $old_lamp = $stOld->fetchColumn();
                            $old_path = $old_lamp ? resolve_guru_file_path('tugas', $old_lamp) : null;
                            if ($old_path && is_file($old_path)) {
                                @unlink($old_path);
                            }
                        } catch (Throwable $e) {}
                        $sql .= ", lampiran = ?";
                        $params[] = $lampiran;
                    }
                    $sql .= " WHERE id = ? AND id_guru = ?";
                    $params[] = $id;
                    $params[] = $guru_id;
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $message = ['type' => 'success', 'text' => 'Tugas berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stRows = $pdo->prepare("SELECT lampiran FROM tb_tugas WHERE id = ? AND id_guru = ?");
            $stRows->execute([$id, $guru_id]);
            $del_lamp = $stRows->fetchColumn();
            $stSubs = $pdo->prepare("SELECT file_path FROM tb_tugas_pengumpulan WHERE id_tugas = ?");
            $stSubs->execute([$id]);
            $del_subs = $stSubs->fetchAll(PDO::FETCH_COLUMN);
            $stmt = $pdo->prepare("DELETE FROM tb_tugas WHERE id = ? AND id_guru = ?");
            $stmt->execute([$id, $guru_id]);
            $pdo->prepare("DELETE FROM tb_tugas_pengumpulan WHERE id_tugas = ?")->execute([$id]);
            $garbage = array_merge($del_lamp ? [$del_lamp] : [], (array)$del_subs);
            foreach ($garbage as $gf) {
                $gp = $gf ? resolve_guru_file_path('tugas', $gf) : null;
                if ($gp && is_file($gp)) {
                    @unlink($gp);
                }
            }
            $message = ['type' => 'success', 'text' => 'Tugas berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
        }
    }
}

// Master lists
if ($is_admin_or_kepala) {
    $mapel_list = $pdo->query("SELECT id_mapel, nama_mapel FROM tb_mata_pelajaran WHERE (jenis_mapel IS NULL OR jenis_mapel = 'Akademik') AND nama_mapel NOT LIKE '%Asmaul Husna%' AND nama_mapel NOT LIKE '%Upacara%' AND nama_mapel NOT LIKE '%Istirahat%' AND nama_mapel NOT LIKE '%Kepramukaan%' AND nama_mapel NOT LIKE '%Ekstrakurikuler%' ORDER BY nama_mapel ASC")->fetchAll(PDO::FETCH_ASSOC);
    $kelas_list = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $mapel_list = getGuruTaughtMapels($pdo, $guru_id);
    $kelas_list = getGuruTaughtClasses($pdo, $guru_id);
}
$jenis_tugas_options = ['Individu', 'Kelompok', 'Proyek', 'Praktik', 'Portofolio', 'Kuis', 'CBT'];

// Deteksi kelas wali jika login sebagai wali kelas
$wali_kelas_id = 0;
if (!$is_admin_or_kepala) {
    try {
        $stWali = $pdo->prepare("SELECT id_kelas FROM tb_kelas WHERE wali_kelas = ? OR wali_kelas = (SELECT nama_guru FROM tb_guru WHERE id_guru = ?)");
        $stWali->execute([$guru_id, $guru_id]);
        $wali_kelas_id = (int)$stWali->fetchColumn() ?: 0;
    } catch (Throwable $e) {}
}

// Filters
$f_mapel = (int)($_GET['f_mapel'] ?? 0);
$f_kelas = (int)($_GET['f_kelas'] ?? 0);
$f_jenis = trim((string)($_GET['f_jenis'] ?? ''));

if ($is_admin_or_kepala) {
    $where = ["1=1"];
    $params = [];
} elseif ($wali_kelas_id > 0) {
    // Wali kelas dapat melihat tugas yang dibuatnya sendiri DAN tugas untuk kelas yang diampunya
    $where = ["(t.id_guru = ? OR t.id_kelas = ?)"];
    $params = [$guru_id, $wali_kelas_id];
} else {
    $where = ["t.id_guru = ?"];
    $params = [$guru_id];
}

if ($f_mapel > 0) {
    $where[] = "t.id_mapel = ?";
    $params[] = $f_mapel;
}
if ($f_kelas > 0) {
    $where[] = "t.id_kelas = ?";
    $params[] = $f_kelas;
}
if ($f_jenis !== '') {
    $where[] = "t.jenis_tugas = ?";
    $params[] = $f_jenis;
}

$where_sql = implode(' AND ', $where);

// Query tugas + aggregat pengumpulan & total siswa kelas
$stmt = $pdo->prepare("
    SELECT t.*, m.nama_mapel, k.nama_kelas,
           (SELECT COUNT(*) FROM tb_siswa s WHERE s.id_kelas = t.id_kelas) AS total_siswa,
           (SELECT COUNT(*) FROM tb_tugas_pengumpulan tp WHERE tp.id_tugas = t.id) AS total_kumpul,
           (SELECT COUNT(*) FROM tb_tugas_pengumpulan tp WHERE tp.id_tugas = t.id AND tp.status_periksa = 'Sudah Diperiksa') AS total_diperiksa
    FROM tb_tugas t
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = t.id_mapel
    LEFT JOIN tb_kelas k ON k.id_kelas = t.id_kelas
    WHERE $where_sql
    ORDER BY t.id DESC
");
$stmt->execute($params);
$tugas_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Daftar Tugas';
$css_libs = [];
$js_libs = [];

$js_page = [<<<'JS'
$(document).ready(function() {

    // Auto-submit filter on change
    $('#formFilterTugas select').on('change', function() {
        $('#formFilterTugas').submit();
    });

    $('#btnTambahTugas').on('click', function() {
        $('#formTugasAction').val('tambah');
        $('#tugasId').val('');
        $('#modalTugasTitle').text('Tambah Tugas Baru');
        $('#formTugas')[0].reset();
        var validKelasOpts = $('#inp_kelas option').filter(function() { return this.value !== ''; });
        if (validKelasOpts.length === 1) {
            $('#inp_kelas').val(validKelasOpts.val());
        }
        $('#modalTugas').modal('show');
    });

    function toggleJenisTugas() {
        var v = $('#inp_jenis').val();
        if (v === 'Kuis') {
            $('#container_kuis_builder').slideDown(150);
            $('#container_cbt_builder').slideUp(150);
            $('#container_lampiran_berkas').slideUp(150);
            $('#container_durasi_ujian').slideDown(150);
        } else if (v === 'CBT') {
            $('#container_cbt_builder').slideDown(150);
            $('#container_kuis_builder').slideUp(150);
            $('#container_lampiran_berkas').slideUp(150);
            $('#container_durasi_ujian').slideDown(150);
        } else {
            $('#container_kuis_builder').slideUp(150);
            $('#container_cbt_builder').slideUp(150);
            $('#container_lampiran_berkas').slideDown(150);
            $('#container_durasi_ujian').slideUp(150);
        }
    }
    $(document).on('change', '#inp_jenis', toggleJenisTugas);

    $(document).on('click', '.btn-edit-tugas', function() {
        var data = $(this).data('json');
        if (typeof data === 'string') {
            try { data = JSON.parse(data); } catch (e) {}
        }
        if (!data || typeof data !== 'object') return;
        $('#formTugas')[0].reset();
        $('#formTugasAction').val('edit');
        $('#tugasId').val(data.id);
        $('#modalTugasTitle').text('Edit Tugas');
        $('#inp_judul').val(data.judul || '');
        var mapelId = (data.id_mapel != null && data.id_mapel !== '') ? String(data.id_mapel) : '';
        if (mapelId !== '' && $('#inp_mapel').find('option[value="' + mapelId + '"]').length === 0) {
            var lblM = data.nama_mapel || ('Mapel #' + mapelId);
            $('#inp_mapel').append(new Option(lblM, mapelId, true, true));
        }
        $('#inp_mapel').val(mapelId).trigger('change');
        var kelasId = (data.id_kelas != null && data.id_kelas !== '') ? String(data.id_kelas) : '';
        if (kelasId !== '' && $('#inp_kelas').find('option[value="' + kelasId + '"]').length === 0) {
            var lblK = data.nama_kelas || ('Kelas #' + kelasId);
            $('#inp_kelas').append(new Option(lblK, kelasId, true, true));
        }
        $('#inp_kelas').val(kelasId).trigger('change');
        $('#inp_materi_tp').val(data.materi_tp || '');
        $('#inp_jenis').val(data.jenis_tugas || 'Individu').trigger('change');
        $('#inp_instruksi').val(data.instruksi || '');
        $('#inp_mulai').val(data.tgl_mulai ? data.tgl_mulai.substring(0, 16).replace(' ', 'T') : '');
        $('#inp_deadline').val(data.deadline ? data.deadline.substring(0, 16).replace(' ', 'T') : '');
        $('#inp_nilai_maks').val(data.nilai_maksimal || 100);
        $('#inp_durasi_menit').val(data.durasi_menit || 90);
        $('#inp_status').val(data.status || 'Aktif').trigger('change');
        if (window.GDSRefresh) { try { window.GDSRefresh($('#modalTugas')[0]); } catch (e2) {} }
        $('#modalTugas').modal('show');
    });

    $(document).on('click', '.btn-hapus-tugas', function() {
        var id = $(this).data('id');
        var judul = $(this).data('judul');
        Swal.fire({
            title: 'Hapus Tugas?',
            text: 'Tugas "' + judul + '" dan seluruh pengumpulan siswa akan dihapus.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus',
            cancelButtonText: 'Batal'
        }).then(function(res) {
            if (res.isConfirmed) {
                $('#formHapusId').val(id);
                $('#formHapus').submit();
            }
        });
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
            <h1>Daftar Tugas</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <!-- Filter Card -->
            <div class="card mb-3">
                <div class="card-header">
                    <h4><i class="fas fa-filter mr-2"></i>Filter Tugas</h4>
                </div>
                <div class="card-body">
                    <form method="GET" class="row" id="formFilterTugas">
                        <div class="col-md-4 mb-2">
                            <label class="small font-weight-bold">Mata Pelajaran</label>
                            <select name="f_mapel" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Mapel --</option>
                                <?php foreach ($mapel_list as $m): ?>
                                    <option value="<?= (int)$m['id_mapel'] ?>" <?= $f_mapel === (int)$m['id_mapel'] ? 'selected' : '' ?>><?= htmlspecialchars($m['nama_mapel']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="small font-weight-bold">Kelas</label>
                            <select name="f_kelas" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Kelas --</option>
                                <?php foreach ($kelas_list as $k): ?>
                                    <option value="<?= (int)$k['id_kelas'] ?>" <?= $f_kelas === (int)$k['id_kelas'] ? 'selected' : '' ?>><?= htmlspecialchars($k['nama_kelas']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="small font-weight-bold">Jenis Tugas</label>
                            <select name="f_jenis" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Jenis --</option>
                                <?php foreach ($jenis_tugas_options as $jt): ?>
                                    <option value="<?= $jt ?>" <?= $f_jenis === $jt ? 'selected' : '' ?>><?= $jt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($f_mapel > 0 || $f_kelas > 0 || $f_jenis !== ''): ?>
                        <div class="col-12 mt-1">
                            <a href="tugas.php" class="btn btn-secondary btn-sm"><i class="fas fa-undo mr-1"></i> Reset Filter</a>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Daftar Penugasan (Card) -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap: 8px;">
                    <h4>Daftar Penugasan (<?= count($tugas_rows) ?>)</h4>
                    <?php if ($can_crud): ?>
                    <div>
                        <button type="button" class="btn btn-primary" id="btnTambahTugas">
                            <i class="fas fa-plus mr-1"></i> Buat Tugas Baru
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (empty($tugas_rows)): ?>
                <div class="card border-0 shadow-sm" style="border-radius: 12px;">
                    <div class="card-body p-5 text-center text-muted">
                        <i class="fas fa-tasks fa-3x mb-3"></i><br>
                        Belum ada tugas pada filter ini.
                    </div>
                </div>
            <?php else: ?>
                <div class="row tugas-card-grid">
                    <?php foreach ($tugas_rows as $r): ?>
                        <?php
                        $total_siswa = (int)($r['total_siswa'] ?? 0);
                        $kumpul = (int)($r['total_kumpul'] ?? 0);
                        $periksa = (int)($r['total_diperiksa'] ?? 0);
                        $kumpul_pct = $total_siswa > 0 ? round($kumpul / $total_siswa * 100) : 0;

                        $status_badge = 'secondary';
                        if ($r['status'] === 'Aktif') $status_badge = 'success';
                        elseif ($r['status'] === 'Draft') $status_badge = 'warning';
                        elseif ($r['status'] === 'Selesai') $status_badge = 'info';

                        $is_overdue = !empty($r['deadline']) && strtotime($r['deadline']) < time() && $r['status'] === 'Aktif';
                        $is_kuis_g = in_array($r['jenis_tugas'] ?? '', ['Kuis', 'CBT'], true);
                        ?>
                        <div class="col-12 col-md-6 col-lg-4 d-flex align-items-stretch mb-4">
                            <div class="card w-100 border shadow-sm" style="border-radius: 12px; overflow: hidden; background: #fff; border: 1px solid #e2e8f0 !important;">
                                <div class="card-body p-3 d-flex flex-column justify-content-between">
                                    <div>
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="badge badge-light border text-dark font-weight-bold px-2 py-1" style="font-size: 11px; border-radius: 6px;">
                                                <i class="fas fa-<?= $is_kuis_g ? 'laptop-code' : 'tasks' ?> mr-1 text-primary"></i><?= htmlspecialchars($r['jenis_tugas']) ?>
                                            </span>
                                            <span class="badge badge-<?= $status_badge ?> px-2 py-1 font-weight-bold" style="font-size: 10px; border-radius: 6px;">
                                                <?= htmlspecialchars($r['status']) ?>
                                            </span>
                                        </div>

                                        <h6 class="font-weight-bold mb-1 text-truncate-2" style="font-size: 14.5px; line-height: 1.35; min-height: 38px;">
                                            <a href="detail_tugas.php?id=<?= (int)$r['id'] ?>" class="text-dark" title="<?= htmlspecialchars($r['judul']) ?>">
                                                <?= htmlspecialchars($r['judul']) ?>
                                            </a>
                                        </h6>

                                        <div class="d-flex flex-wrap align-items-center mb-2 small text-muted" style="gap: 6px;">
                                            <span class="badge badge-light border text-dark font-weight-bold px-2 py-1" style="border-radius: 4px; font-size: 11px;">
                                                <i class="fas fa-book text-muted mr-1"></i><?= htmlspecialchars($r['nama_mapel'] ?? '-') ?>
                                            </span>
                                            <span class="badge badge-light border text-dark font-weight-bold px-2 py-1" style="border-radius: 4px; font-size: 11px;">
                                                <i class="fas fa-chalkboard text-muted mr-1"></i><?= htmlspecialchars($r['nama_kelas'] ?? '-') ?>
                                            </span>
                                        </div>

                                        <?php if (!empty($r['materi_tp'])): ?>
                                            <div class="p-2 mb-2 rounded small text-muted" style="font-size: 11.5px; line-height: 1.3; background: #f8fafc !important;">
                                                <i class="fas fa-bookmark mr-1 text-primary"></i><?= htmlspecialchars(mb_strimwidth($r['materi_tp'], 0, 75, '...')) ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="small text-muted mb-2" style="font-size: 11.5px; line-height: 1.7;">
                                            <div><i class="far fa-play-circle mr-1"></i>Mulai: <?= !empty($r['tgl_mulai']) ? date('d/m/Y H:i', strtotime($r['tgl_mulai'])) : '-' ?></div>
                                            <div>
                                                <i class="far fa-clock mr-1 <?= $is_overdue ? 'text-danger' : '' ?>"></i>Batas: <strong class="<?= $is_overdue ? 'text-danger' : '' ?>"><?= !empty($r['deadline']) ? date('d/m/Y H:i', strtotime($r['deadline'])) : '-' ?></strong>
                                                <?php if ($is_overdue): ?>
                                                    <span class="badge badge-danger ml-1" style="font-size: 10px;">Lewat</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between small text-muted mb-1" style="font-size: 11px;">
                                                <span><i class="fas fa-users mr-1"></i>Terkumpul <?= $kumpul ?>/<?= $total_siswa ?></span>
                                                <span><i class="fas fa-check-double mr-1"></i>Diperiksa <?= $periksa ?>/<?= $kumpul ?></span>
                                            </div>
                                            <div class="progress" style="height: 8px; border-radius: 10px;">
                                                <div class="progress-bar bg-success" role="progressbar" style="width: <?= $kumpul_pct ?>%;" aria-valuenow="<?= $kumpul_pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="pt-2 border-top d-flex flex-wrap align-items-center" style="border-color: #f1f5f9 !important; gap: 6px;">
                                        <a href="detail_tugas.php?id=<?= (int)$r['id'] ?>" class="btn btn-info btn-sm px-2 font-weight-bold" title="Detail & Pengumpulan" style="border-radius: 6px; font-size: 11.5px;">
                                            <i class="fas fa-list mr-1"></i>Detail
                                        </a>
                                        <?php if ($can_crud): ?>
                                            <button type="button" class="btn btn-warning btn-sm px-2 font-weight-bold btn-edit-tugas" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit" style="border-radius: 6px; font-size: 11.5px;">
                                                <i class="fas fa-edit mr-1"></i>Edit
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm px-2 font-weight-bold btn-hapus-tugas" data-id="<?= (int)$r['id'] ?>" data-judul="<?= htmlspecialchars($r['judul'], ENT_QUOTES) ?>" title="Hapus" style="border-radius: 6px; font-size: 11.5px;">
                                                <i class="fas fa-trash mr-1"></i>Hapus
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

<!-- Modal Form Tambah / Edit Tugas -->
<div class="modal fade" id="modalTugas" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formTugas" enctype="multipart/form-data">
                <input type="hidden" name="action" id="formTugasAction" value="tambah">
                <input type="hidden" name="id" id="tugasId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTugasTitle">Buat Tugas Baru</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-12 form-group">
                            <label>Judul Tugas <span class="text-danger">*</span></label>
                            <input type="text" name="judul" id="inp_judul" class="form-control" required placeholder="Contoh: Latihan Soal Bab 2">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Mata Pelajaran</label>
                            <select name="id_mapel" id="inp_mapel" class="form-control">
                                <option value="">-- Pilih Mapel --</option>
                                <?php foreach ($mapel_list as $m): ?>
                                    <option value="<?= (int)$m['id_mapel'] ?>"><?= htmlspecialchars($m['nama_mapel']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Kelas Target <?= count($kelas_list) > 1 ? '<span class="text-danger">*</span>' : '' ?></label>
                            <select name="id_kelas" id="inp_kelas" class="form-control" required>
                                <?php if (count($kelas_list) > 1): ?>
                                    <option value="">-- Pilih Kelas --</option>
                                    <?php foreach ($kelas_list as $k): ?>
                                        <option value="<?= (int)$k['id_kelas'] ?>"><?= htmlspecialchars($k['nama_kelas']) ?></option>
                                    <?php endforeach; ?>
                                <?php elseif (count($kelas_list) === 1): ?>
                                    <option value="<?= (int)$kelas_list[0]['id_kelas'] ?>" selected><?= htmlspecialchars($kelas_list[0]['nama_kelas']) ?></option>
                                <?php else: ?>
                                    <option value="">-- Tidak ada kelas --</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Materi / TP</label>
                            <input type="text" name="materi_tp" id="inp_materi_tp" class="form-control" placeholder="Contoh: Ekosistem Darat">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Jenis Tugas</label>
                            <select name="jenis_tugas" id="inp_jenis" class="form-control">
                                <?php foreach ($jenis_tugas_options as $jt): ?>
                                    <option value="<?= $jt ?>"><?= $jt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Tanggal Mulai</label>
                            <input type="datetime-local" name="tgl_mulai" id="inp_mulai" class="form-control">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Deadline Pengumpulan</label>
                            <input type="datetime-local" name="deadline" id="inp_deadline" class="form-control">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Nilai Maksimal</label>
                            <input type="number" name="nilai_maksimal" id="inp_nilai_maks" class="form-control" value="100" min="1" max="1000">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Status</label>
                            <select name="status" id="inp_status" class="form-control">
                                <option value="Aktif">Aktif</option>
                                <option value="Draft">Draft</option>
                                <option value="Selesai">Selesai</option>
                                <option value="Arsip">Arsip</option>
                            </select>
                        </div>
                        <div class="col-12 form-group" id="container_durasi_ujian" style="display:none;">
                            <div class="form-group mb-0">
                                <label class="font-weight-bold text-primary"><i class="fas fa-clock mr-1"></i> Durasi Pengerjaan Ujian (Menit)</label>
                                <input type="number" name="durasi_menit" id="inp_durasi_menit" class="form-control" value="90" min="5" max="300">
                                <small class="text-muted">Timer hitung mundur otomatis akan aktif selama siswa mengerjakan Kuis atau CBT.</small>
                            </div>
                        </div>
                        <div class="col-12 form-group" id="container_kuis_builder" style="display:none;">
                            <div class="alert alert-info small mb-0">
                                <i class="fas fa-question-circle mr-1"></i> <strong>Mode Kuis Aktif:</strong> Setelah tugas disimpan, Anda dapat menyusun butir-butir soal pilihan ganda atau essay langsung pada detail tugas.
                            </div>
                        </div>
                        <div class="col-12 form-group" id="container_cbt_builder" style="display:none;">
                            <div class="p-3 bg-light border rounded">
                                <h6 class="font-weight-bold text-primary mb-2"><i class="fas fa-laptop-code mr-1"></i> Konfigurasi Ujian CBT</h6>
                                <div class="form-row">
                                    <div class="col-md-6 form-group mb-2">
                                        <label class="small font-weight-bold">Durasi Ujian (Menit)</label>
                                        <input type="number" name="cbt_durasi" id="inp_cbt_durasi" class="form-control form-control-sm" value="90" min="10" max="300">
                                    </div>
                                    <div class="col-md-6 form-group mb-2">
                                        <label class="small font-weight-bold">Token Ujian (Auto/Kustom)</label>
                                        <input type="text" name="cbt_token" id="inp_cbt_token" class="form-control form-control-sm" value="CBT-<?= rand(1000,9999) ?>">
                                    </div>
                                </div>
                                <small class="text-muted d-block">Siswa memerlukan token di atas untuk memulai ujian CBT. Laman bank soal & generator AI dapat diakses setelah tugas ini dibuat.</small>
                            </div>
                        </div>
                        <div class="col-12 form-group" id="container_lampiran_berkas">
                            <label>Lampiran Berkas (Soal/Panduan)</label>
                            <input type="file" name="lampiran_file" class="form-control-file">
                        </div>
                        <div class="col-12 form-group">
                            <label>Instruksi / Petunjuk Pengerjaan</label>
                            <textarea name="instruksi" id="inp_instruksi" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan Tugas</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form method="POST" id="formHapus" class="d-none">
    <input type="hidden" name="action" value="hapus">
    <input type="hidden" name="id" id="formHapusId">
</form>

<?php include '../templates/footer.php'; ?>
