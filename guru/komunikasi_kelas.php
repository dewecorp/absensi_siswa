<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['guru', 'wali', 'admin', 'tata_usaha', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$is_admin_or_kepala = in_array($user_level, ['admin', 'kepala_madrasah', 'tata_usaha'], true) || in_array($_GET['session_type'] ?? '', ['admin', 'kepala_madrasah'], true);
$can_crud = !$is_admin_or_kepala;

// Daftar kelas: guru/wali hanya kelas diajar, admin/kepala semua kelas.
if ($is_admin_or_kepala) {
    $kelas_list = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $kelas_list = function_exists('getGuruTaughtClasses') ? getGuruTaughtClasses($pdo, $guru_id) : [];
    if (empty($kelas_list)) {
        $kelas_list = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
}
$kelas_ids = array_map(static function ($c) { return (int)($c['id_kelas'] ?? 0); }, $kelas_list);

$upload_dir = guru_upload_dir($pdo, $guru_id, 'komunikasi');

$message = null;

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_crud) {
        $message = ['type' => 'danger', 'text' => 'Anda tidak memiliki hak akses untuk mengubah data ini.'];
    } else {
        $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $tanggal = !empty($_POST['tanggal']) ? date('Y-m-d', strtotime($_POST['tanggal'])) : date('Y-m-d');
        $tanggal_mulai = !empty($_POST['tanggal_mulai']) ? date('Y-m-d', strtotime($_POST['tanggal_mulai'])) : $tanggal;
        $tanggal_selesai = !empty($_POST['tanggal_selesai']) ? date('Y-m-d', strtotime($_POST['tanggal_selesai'])) : $tanggal_mulai;
        if ($tanggal_selesai < $tanggal_mulai) { $tmpT = $tanggal_mulai; $tanggal_mulai = $tanggal_selesai; $tanggal_selesai = $tmpT; }
        $tanggal = $tanggal_mulai;
        $waktu_mulai = !empty($_POST['waktu_mulai']) ? date('H:i:s', strtotime($_POST['waktu_mulai'])) : null;
        $waktu_selesai = !empty($_POST['waktu_selesai']) ? date('H:i:s', strtotime($_POST['waktu_selesai'])) : null;
        $judul = trim((string)($_POST['judul'] ?? ''));
        $jenis = in_array($_POST['jenis'] ?? '', ['Pengumuman', 'Pesan'], true) ? $_POST['jenis'] : 'Pengumuman';
        $id_kelas = (int)($_POST['id_kelas'] ?? 0);
        $isi = trim((string)($_POST['isi'] ?? ''));
        $status = 'Terkirim';

        $lampiran = null;
        if (isset($_FILES['lampiran_file']) && $_FILES['lampiran_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['lampiran_file']['name'], PATHINFO_EXTENSION));
            $filename = 'pesan_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['lampiran_file']['tmp_name'], $upload_dir . $filename)) {
                $lampiran = guru_folder_name($pdo, $guru_id) . '/' . $filename;
            }
        }

        if ($judul === '' || $id_kelas <= 0 || $isi === '') {
            $message = ['type' => 'warning', 'text' => 'Judul, Kelas, dan Isi Pesan wajib diisi.'];
        } elseif (!$is_admin_or_kepala && !in_array($id_kelas, $kelas_ids, true)) {
            $message = ['type' => 'danger', 'text' => 'Anda hanya boleh mengirim ke kelas yang Anda ajar.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_komunikasi_kelas (
                            id_guru, tanggal, tanggal_mulai, tanggal_selesai, waktu_mulai, waktu_selesai, judul, jenis, id_kelas, isi, lampiran, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$guru_id, $tanggal, $tanggal_mulai, $tanggal_selesai, $waktu_mulai, $waktu_selesai, $judul, $jenis, $id_kelas, $isi, $lampiran, $status]);
                    $message = ['type' => 'success', 'text' => 'Komunikasi kelas berhasil dipublikasikan.'];
                } else {
                    $sql = "
                        UPDATE tb_komunikasi_kelas SET
                            tanggal = ?, tanggal_mulai = ?, tanggal_selesai = ?, waktu_mulai = ?, waktu_selesai = ?, judul = ?, jenis = ?, id_kelas = ?, isi = ?, status = ?
                    ";
                    $params = [$tanggal, $tanggal_mulai, $tanggal_selesai, $waktu_mulai, $waktu_selesai, $judul, $jenis, $id_kelas, $isi, $status];
                    if ($lampiran !== null) {
                        $sql .= ", lampiran = ?";
                        $params[] = $lampiran;
                    }
                    $sql .= " WHERE id = ? AND id_guru = ?";
                    $params[] = $id;
                    $params[] = $guru_id;
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $message = ['type' => 'success', 'text' => 'Komunikasi kelas berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT lampiran FROM tb_komunikasi_kelas WHERE id = ? AND id_guru = ?");
            $stmt->execute([$id, $guru_id]);
            $old = $stmt->fetchColumn();
            $old_path = $old ? resolve_guru_file_path('komunikasi', $old) : null;
            if ($old_path && is_file($old_path)) {
                @unlink($old_path);
            }
            $pdo->prepare("DELETE FROM tb_komunikasi_kelas WHERE id = ? AND id_guru = ?")->execute([$id, $guru_id]);
            $pdo->prepare("DELETE FROM tb_komunikasi_kelas_read WHERE id_komunikasi = ?")->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Pesan komunikasi berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
}
}

$jenis_options = ['Pengumuman', 'Pesan'];

// Filters
$f_kelas = (int)($_GET['f_kelas'] ?? 0);
$f_jenis = trim((string)($_GET['f_jenis'] ?? ''));
$f_status = trim((string)($_GET['f_status'] ?? ''));

if ($is_admin_or_kepala) {
    $where = ["1=1"];
    $params = [];
} else {
    $where = ["k.id_guru = ?"];
    $params = [$guru_id];
}

if ($f_kelas > 0) {
    $where[] = "k.id_kelas = ?";
    $params[] = $f_kelas;
}
if ($f_jenis !== '') {
    $where[] = "k.jenis = ?";
    $params[] = $f_jenis;
}
if ($f_status !== '') {
    $where[] = "k.status = ?";
    $params[] = $f_status;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT k.*, c.nama_kelas, g.nama_guru,
           COALESCE(k.tanggal_mulai, k.tanggal) AS tgl_mulai_ef,
           COALESCE(k.tanggal_selesai, COALESCE(k.tanggal_mulai, k.tanggal)) AS tgl_selesai_ef,
           (SELECT COUNT(*) FROM tb_siswa s WHERE s.id_kelas = k.id_kelas) AS total_penerima,
           (SELECT COUNT(*) FROM tb_komunikasi_kelas_read kr WHERE kr.id_komunikasi = k.id) AS total_dibaca
    FROM tb_komunikasi_kelas k
    LEFT JOIN tb_kelas c ON c.id_kelas = k.id_kelas
    LEFT JOIN tb_guru g ON g.id_guru = k.id_guru
    WHERE $where_sql
    ORDER BY k.tanggal DESC, k.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Komunikasi Kelas';
$css_libs = [];
$js_libs = [];

$js_page = [<<<'JS'
$(document).ready(function() {
    $('#cari-kartu-komunikasi').on('input', function() {
        var query = $(this).val().toLowerCase().trim();
        var visibleCount = 0;
        $('.komunikasi-card-col').each(function() {
            var cardText = $(this).text().toLowerCase();
            if (cardText.indexOf(query) !== -1) {
                $(this).show();
                visibleCount++;
            } else {
                $(this).hide();
            }
        });
        if (visibleCount === 0 && query !== '') {
            $('#no-komunikasi-search').show();
        } else {
            $('#no-komunikasi-search').hide();
        }
        $('#count-kartu-komunikasi').text(visibleCount);
    });

    $('#btnTambahPesan').on('click', function() {
        $('#formKomunikasiAction').val('tambah');
        $('#komunikasiId').val('');
        $('#modalKomunikasiTitle').text('Buat Pengumuman / Pesan Kelas Baru');
        $('#formKomunikasi')[0].reset();
        $('#modalKomunikasi').modal('show');
    });

    $(document).on('click', '.btn-edit-pesan', function() {
        var data = $(this).data('json');
        if (typeof data === 'string') {
            try { data = JSON.parse(data); } catch(e) {}
        }
        if (!data || typeof data !== 'object') return;

        $('#formKomunikasiAction').val('edit');
        $('#komunikasiId').val(data.id);
        $('#modalKomunikasiTitle').text('Edit Komunikasi Kelas');
        $('#inp_tanggal').val(data.tanggal);
        $('#inp_tanggal_mulai').val(data.tanggal_mulai || data.tgl_mulai_ef || data.tanggal);
        $('#inp_tanggal_selesai').val(data.tanggal_selesai || data.tgl_selesai_ef || data.tanggal);
        $('#inp_waktu_mulai').val(data.waktu_mulai ? String(data.waktu_mulai).substring(0, 5) : '');
        $('#inp_waktu_selesai').val(data.waktu_selesai ? String(data.waktu_selesai).substring(0, 5) : '');
        $('#inp_judul').val(data.judul);
        $('#inp_jenis').val(data.jenis);
        $('#inp_kelas').val(data.id_kelas);
        $('#inp_isi').val(data.isi);
        $('#modalKomunikasi').modal('show');
    });

    $(document).on('click', '.btn-detail-pesan', function() {
        var data = $(this).data('json');
        if (typeof data === 'string') {
            try { data = JSON.parse(data); } catch(e) {}
        }
        if (!data || typeof data !== 'object') return;

        var tm = data.tanggal_mulai || data.tgl_mulai_ef || data.tanggal;
        var ts = data.tanggal_selesai || data.tgl_selesai_ef || tm;
        var shortT = function(v) { return v ? String(v).substring(0, 5).replace(':', '.') : ''; };
        var wm = shortT(data.waktu_mulai || '');
        var ws = shortT(data.waktu_selesai || '');
        var jamStr = (wm !== '' && ws !== '' && wm !== ws) ? (wm + ' - ' + ws + ' WIB') : (wm !== '' ? (wm + ' WIB') : (ws !== '' ? (ws + ' WIB') : '-'));
        $('#det_tanggal').text(tm === ts ? tm : (tm + ' s/d ' + ts));
        $('#det_waktu').text(jamStr);
        $('#det_judul').text(data.judul);
        $('#det_jenis').html('<span class="badge badge-info">' + data.jenis + '</span>');
        $('#det_kelas').text(data.nama_kelas || '-');
        $('#det_penerima').text(data.total_penerima + ' Siswa');
        $('#det_dibaca').html('<span class="badge badge-success">' + data.total_dibaca + '/' + data.total_penerima + ' Siswa</span>');
        $('#det_status').text(data.status);
        $('#det_isi').text(data.isi);

        if (data.lampiran) {
            $('#det_lampiran').html('<a href="../uploads/komunikasi/' + String(data.lampiran).split('/').map(encodeURIComponent).join('/') + '" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-paperclip mr-1"></i>Unduh Lampiran (' + data.lampiran.split('.').pop().toUpperCase() + ')</a>');
        } else {
            $('#det_lampiran').html('<span class="text-muted">Tidak ada lampiran</span>');
        }

        $('#modalDetailPesan').modal('show');
    });

    $(document).on('click', '.btn-hapus-pesan', function() {
        var id = $(this).data('id');
        var judul = $(this).data('judul');
        Swal.fire({
            title: 'Hapus Pesan?',
            text: 'Pesan "' + judul + '" akan dihapus permanen.',
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
.card-komunikasi {
    border-radius: 12px;
    border: 1px solid #e2e8f0 !important;
    background: #ffffff;
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}
.card-komunikasi:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(103, 119, 239, 0.08) !important;
    border-color: #cbd5e1 !important;
}
.komunikasi-avatar {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12);
}
.avatar-pengumuman {
    background: linear-gradient(135deg, #6777ef, #3abaf4);
}
.avatar-pesan {
    background: linear-gradient(135deg, #10b981, #059669);
}
.bullet {
    display: inline-block;
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background-color: #cbd5e1;
    margin: 0 4px;
}
.komunikasi-preview-box {
    background: #f8fafc;
    border-left: 3.5px solid #6777ef;
    border-top: 1px solid #edf2f7;
    border-right: 1px solid #edf2f7;
    border-bottom: 1px solid #edf2f7;
    border-radius: 6px;
    padding: 12px 14px;
}
.komunikasi-preview-box.is-pesan {
    border-left-color: #10b981;
}
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Daftar Komunikasi Kelas</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <!-- Filter Card -->
            <div class="card mb-3">
                <div class="card-header py-2">
                    <h6 class="mb-0 text-dark"><i class="fas fa-filter mr-1 text-primary"></i> Filter Komunikasi</h6>
                </div>
                <div class="card-body py-3">
                    <form method="GET" class="row">
                        <?php if (isset($_GET['session_type'])): ?>
                            <input type="hidden" name="session_type" value="<?= htmlspecialchars($_GET['session_type']) ?>">
                        <?php endif; ?>
                        <div class="col-md-4 col-lg mb-2">
                            <label class="small font-weight-bold">Kelas</label>
                            <select name="f_kelas" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Kelas --</option>
                                <?php foreach ($kelas_list as $k): ?>
                                    <option value="<?= (int)$k['id_kelas'] ?>" <?= $f_kelas === (int)$k['id_kelas'] ? 'selected' : '' ?>><?= htmlspecialchars($k['nama_kelas']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 col-lg mb-2">
                            <label class="small font-weight-bold">Jenis</label>
                            <select name="f_jenis" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Jenis --</option>
                                <?php foreach ($jenis_options as $j): ?>
                                    <option value="<?= $j ?>" <?= $f_jenis === $j ? 'selected' : '' ?>><?= $j ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 col-lg mb-2">
                            <label class="small font-weight-bold">Status</label>
                            <select name="f_status" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua --</option>
                                <?php foreach (['Terkirim', 'Draft', 'Arsip'] as $st): ?>
                                    <option value="<?= $st ?>" <?= $f_status === $st ? 'selected' : '' ?>><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mt-1 d-flex align-items-center">
                            <a href="komunikasi_kelas.php<?= isset($_GET['session_type']) ? '?session_type=' . urlencode($_GET['session_type']) : '' ?>" class="btn btn-secondary btn-sm"><i class="fas fa-undo mr-1"></i> Reset</a>
                            <small class="text-muted ml-2">Filter otomatis tersubmit saat diubah.</small>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Daftar Komunikasi Kelas (Bentuk Kartu Informatif) -->
            <div class="card mb-4 border-0 shadow-sm" style="border-radius: 14px;">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center py-3" style="border-radius: 14px 14px 0 0; border-bottom: 1px solid #f1f5f9; gap: 10px;">
                    <div>
                        <h4 class="mb-0 font-weight-bold text-dark"><i class="fas fa-bullhorn text-primary mr-2"></i>Papan Komunikasi &amp; Pengumuman Kelas</h4>
                        <small class="text-muted">Menampilkan <span id="count-kartu-komunikasi" class="font-weight-bold text-primary"><?= count($rows) ?></span> pengumuman / pesan</small>
                    </div>
                    <div class="d-flex align-items-center flex-wrap" style="gap: 8px;">
                        <div class="input-group input-group-sm" style="width: 220px;">
                            <div class="input-group-prepend">
                                <span class="input-group-text bg-light border-right-0"><i class="fas fa-search text-muted"></i></span>
                            </div>
                            <input type="text" id="cari-kartu-komunikasi" class="form-control form-control-sm border-left-0" placeholder="Cari judul / pesan...">
                        </div>
                        <?php if ($can_crud): ?>
                        <button type="button" class="btn btn-primary btn-sm px-3 font-weight-bold" id="btnTambahPesan" style="border-radius: 6px;">
                            <i class="fas fa-plus mr-1"></i> Buat Pesan / Pengumuman
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body bg-light p-3 p-md-4" style="border-radius: 0 0 14px 14px;">
                    <?php if (empty($rows)): ?>
                        <div class="text-center py-5 bg-white rounded shadow-sm border">
                            <i class="fas fa-comments fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted font-weight-bold">Belum Ada Komunikasi Kelas</h5>
                            <p class="text-muted small mb-0">Silakan buat pengumuman baru atau sesuaikan filter pencarian di atas.</p>
                        </div>
                    <?php else: ?>
                        <div class="row" id="container-kartu-komunikasi" style="row-gap: 16px;">
                            <div class="col-12" id="no-komunikasi-search" style="display: none;">
                                <div class="text-center py-5 bg-white rounded shadow-sm border">
                                    <i class="fas fa-search fa-2x text-muted mb-2"></i>
                                    <p class="text-muted small mb-0">Tidak ada komunikasi kelas yang cocok dengan kata kunci pencarian.</p>
                                </div>
                            </div>
                            <?php foreach ($rows as $i => $r): ?>
                                <?php
                                $tot_penerima = (int)$r['total_penerima'];
                                $tot_baca = (int)$r['total_dibaca'];
                                $baca_pct = $tot_penerima > 0 ? round(($tot_baca / $tot_penerima) * 100) : 0;

                                $st_badge = 'badge-success';
                                $st_icon = 'fas fa-paper-plane';
                                if ($r['status'] === 'Draft') {
                                    $st_badge = 'badge-warning';
                                    $st_icon = 'fas fa-pencil-alt';
                                } elseif ($r['status'] === 'Arsip') {
                                    $st_badge = 'badge-secondary';
                                    $st_icon = 'fas fa-archive';
                                }

                                $is_pengumuman = ($r['jenis'] === 'Pengumuman');
                                $avatar_cls = $is_pengumuman ? 'avatar-pengumuman' : 'avatar-pesan';
                                $avatar_icon = $is_pengumuman ? 'fas fa-bullhorn' : 'fas fa-envelope-open-text';

                                $tm_g = $r['tanggal_mulai'] ?? $r['tgl_mulai_ef'] ?? $r['tanggal'];
                                $ts_g = $r['tanggal_selesai'] ?? $r['tgl_selesai_ef'] ?? $tm_g;
                                $tgl_g = date('d M Y', strtotime($tm_g));
                                if ($ts_g && $ts_g !== $tm_g) {
                                    $tgl_g .= ' - ' . date('d M Y', strtotime($ts_g));
                                }

                                $wm_g = !empty($r['waktu_mulai']) ? substr((string)$r['waktu_mulai'], 0, 5) : '';
                                $ws_g = !empty($r['waktu_selesai']) ? substr((string)$r['waktu_selesai'], 0, 5) : '';
                                $waktu_g = ($wm_g !== '' && $ws_g !== '' && $wm_g !== $ws_g) ? (str_replace(':', '.', $wm_g) . ' - ' . str_replace(':', '.', $ws_g) . ' WIB') : ($wm_g !== '' ? (str_replace(':', '.', $wm_g) . ' WIB') : ($ws_g !== '' ? (str_replace(':', '.', $ws_g) . ' WIB') : ''));
                                $lamp_url = guru_file_url('komunikasi', $r['lampiran'] ?? '');
                                ?>
                                <div class="col-12 col-md-6 col-xl-6 d-flex align-items-stretch komunikasi-card-col">
                                    <div class="card w-100 border-0 shadow-sm card-komunikasi d-flex flex-column justify-content-between mb-0" style="overflow: hidden;">
                                        <div class="card-body p-3 p-md-3.5">
                                            <!-- Top Bar: Avatar + Judul + Status -->
                                            <div class="d-flex justify-content-between align-items-start mb-2.5 pb-2 border-bottom">
                                                <div class="d-flex align-items-center" style="gap: 12px; min-width: 0;">
                                                    <div class="komunikasi-avatar <?= $avatar_cls ?>">
                                                        <i class="<?= $avatar_icon ?>"></i>
                                                    </div>
                                                    <div style="min-width: 0;">
                                                        <h6 class="mb-0 font-weight-bold text-truncate" style="font-size: 15px; line-height: 1.3;" title="<?= htmlspecialchars($r['judul']) ?>">
                                                            <a href="javascript:void(0)" class="text-dark btn-detail-pesan" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' style="text-decoration: none;">
                                                                <?= htmlspecialchars($r['judul']) ?>
                                                            </a>
                                                        </h6>
                                                        <div class="d-flex flex-wrap align-items-center small text-muted mt-1" style="gap: 6px;">
                                                            <span class="badge badge-light border text-dark font-weight-bold px-2 py-0.5" style="border-radius: 4px; font-size: 10.5px;">
                                                                <i class="fas fa-tag text-primary mr-1"></i><?= htmlspecialchars($r['jenis']) ?>
                                                            </span>
                                                            <span class="badge badge-light border text-dark font-weight-bold px-2 py-0.5" style="border-radius: 4px; font-size: 10.5px;">
                                                                <i class="fas fa-chalkboard text-muted mr-1"></i> Kelas <?= htmlspecialchars($r['nama_kelas'] ?? '-') ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="ml-2 flex-shrink-0">
                                                    <span class="badge <?= $st_badge ?> px-2 py-1 font-weight-bold" style="font-size: 11px; border-radius: 6px;">
                                                        <i class="<?= $st_icon ?> mr-1"></i> <?= htmlspecialchars($r['status']) ?>
                                                    </span>
                                                </div>
                                            </div>

                                            <!-- Baris Meta: Tanggal, Waktu, Guru -->
                                            <div class="d-flex flex-wrap align-items-center mb-2.5 small text-muted" style="gap: 6px;">
                                                <span class="badge badge-light border text-muted px-2 py-1 font-weight-bold" style="font-size: 11px; border-radius: 5px;">
                                                    <i class="far fa-calendar-alt text-primary mr-1"></i> <?= htmlspecialchars($tgl_g) ?>
                                                </span>
                                                <?php if ($waktu_g !== ''): ?>
                                                <span class="badge badge-light border text-dark px-2 py-1 font-weight-bold" style="font-size: 11px; border-radius: 5px;">
                                                    <i class="far fa-clock text-info mr-1"></i> <?= htmlspecialchars($waktu_g) ?>
                                                </span>
                                                <?php endif; ?>
                                                <?php if (!empty($r['nama_guru'])): ?>
                                                <span class="badge badge-light border text-muted px-2 py-1" style="font-size: 11px; border-radius: 5px;">
                                                    <i class="fas fa-user-tie mr-1"></i> <?= htmlspecialchars($r['nama_guru']) ?>
                                                </span>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Preview Isi Pesan -->
                                            <div class="mb-2.5 komunikasi-preview-box <?= $is_pengumuman ? '' : 'is-pesan' ?>">
                                                <div class="small font-weight-bold text-dark mb-1 d-flex align-items-center">
                                                    <i class="fas fa-quote-left text-muted mr-2" style="font-size: 11px;"></i> Isi Pesan / Instruksi:
                                                </div>
                                                <div class="text-dark small" style="line-height: 1.5; font-size: 12.5px; padding-left: 2px;">
                                                    <?= nl2br(htmlspecialchars(mb_strimwidth($r['isi'], 0, 150, '...'))) ?>
                                                </div>
                                            </div>

                                            <!-- Progress Baca & Lampiran Box -->
                                            <div class="p-2 rounded bg-white border mb-1">
                                                <div class="d-flex justify-content-between align-items-center mb-1 small">
                                                    <span class="text-muted font-weight-bold">
                                                        <i class="fas fa-user-check mr-1 text-success"></i> Dibaca: <?= $tot_baca ?> / <?= $tot_penerima ?> Siswa
                                                    </span>
                                                    <span class="font-weight-bold text-primary"><?= $baca_pct ?>%</span>
                                                </div>
                                                <div class="progress" style="height: 6px; border-radius: 4px; background-color: #e2e8f0;">
                                                    <div class="progress-bar <?= $baca_pct >= 100 ? 'bg-success' : 'bg-primary' ?>" role="progressbar" style="width: <?= $baca_pct ?>%; border-radius: 4px;"></div>
                                                </div>
                                                <div class="mt-2 pt-1 border-top d-flex justify-content-between align-items-center small">
                                                    <?php if ($lamp_url): ?>
                                                        <a href="<?= htmlspecialchars($lamp_url) ?>" target="_blank" class="text-primary font-weight-bold text-truncate" style="max-width: 220px;" title="Unduh Lampiran">
                                                            <i class="fas fa-paperclip mr-1"></i> Lampiran (<?= strtoupper(pathinfo($r['lampiran'], PATHINFO_EXTENSION)) ?>)
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted"><i class="fas fa-paperclip mr-1 text-muted"></i> Tanpa lampiran</span>
                                                    <?php endif; ?>
                                                    <span class="text-muted font-weight-bold"><?= $tot_penerima ?> Penerima</span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Card Footer Aksi -->
                                        <div class="card-footer bg-white pt-2 pb-2.5 px-3 border-top d-flex flex-wrap justify-content-between align-items-center" style="gap: 6px;">
                                            <button type="button" class="btn btn-outline-primary btn-sm btn-detail-pesan font-weight-bold px-2.5" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' style="border-radius: 6px; font-size: 12px;">
                                                <i class="fas fa-eye mr-1"></i> Rincian &amp; Penerima
                                            </button>
                                            <div class="d-flex align-items-center" style="gap: 5px;">
                                                <?php if ($can_crud): ?>
                                                <button type="button" class="btn btn-warning btn-sm btn-edit-pesan" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit Pesan" style="border-radius: 6px; width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center;">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger btn-sm btn-hapus-pesan" data-id="<?= (int)$r['id'] ?>" data-judul="<?= htmlspecialchars($r['judul'], ENT_QUOTES) ?>" title="Hapus Pesan" style="border-radius: 6px; width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center;">
                                                    <i class="fas fa-trash"></i>
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
            </div>
        </div>
    </section>
</div>
            </div>
        </div>
    </section>
</div>

<!-- Modal Form Tambah / Edit Komunikasi -->
<div class="modal fade" id="modalKomunikasi" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <form method="POST" id="formKomunikasi" enctype="multipart/form-data">
                <input type="hidden" name="action" id="formKomunikasiAction" value="tambah">
                <input type="hidden" name="id" id="komunikasiId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalKomunikasiTitle">Buat Pengumuman / Pesan Baru</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label class="small font-weight-bold">Judul Pesan / Pengumuman <span class="text-danger">*</span></label>
                            <input type="text" name="judul" id="inp_judul" class="form-control" required placeholder="Contoh: Pengumuman Jadwal Ulangan Harian">
                        </div>
                        <div class="col-md-4 form-group">
                            <label class="small font-weight-bold">Jenis</label>
                            <select name="jenis" id="inp_jenis" class="form-control">
                                <?php foreach ($jenis_options as $j): ?>
                                    <option value="<?= $j ?>"><?= $j ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label class="small font-weight-bold">Kelas Penerima <span class="text-danger">*</span></label>
                            <select name="id_kelas" id="inp_kelas" class="form-control" required>
                                <option value="">-- Pilih Kelas --</option>
                                <?php foreach ($kelas_list as $k): ?>
                                    <option value="<?= (int)$k['id_kelas'] ?>"><?= htmlspecialchars($k['nama_kelas']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <input type="hidden" name="tanggal" id="inp_tanggal" value="<?= date('Y-m-d') ?>">
                        <div class="col-md-4 form-group">
                            <label class="small font-weight-bold">Tanggal Mulai <span class="text-danger">*</span></label>
                            <input type="date" name="tanggal_mulai" id="inp_tanggal_mulai" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4 form-group">
                            <label class="small font-weight-bold">Tanggal Selesai <span class="text-danger">*</span></label>
                            <input type="date" name="tanggal_selesai" id="inp_tanggal_selesai" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4 form-group">
                            <label class="small font-weight-bold">Unggah Lampiran <span class="text-muted font-weight-normal">(Opsional)</span></label>
                            <input type="file" name="lampiran_file" class="form-control-file form-control-sm pt-1">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label class="small font-weight-bold">Waktu Mulai</label>
                            <input type="time" name="waktu_mulai" id="inp_waktu_mulai" class="form-control">
                        </div>
                        <div class="col-md-4 form-group">
                            <label class="small font-weight-bold">Waktu Selesai</label>
                            <input type="time" name="waktu_selesai" id="inp_waktu_selesai" class="form-control">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12 form-group mb-0">
                            <label class="small font-weight-bold">Isi Pesan / Pengumuman <span class="text-danger">*</span></label>
                            <textarea name="isi" id="inp_isi" class="form-control" rows="4" required placeholder="Tuliskan isi pengumuman atau instruksi kelas..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane mr-1"></i> Simpan & Publikasikan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Detail Pesan -->
<div class="modal fade" id="modalDetailPesan" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle mr-2"></i>Rincian Komunikasi Kelas</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <table class="table table-bordered table-sm">
                    <tr><th width="30%">Tanggal Mulai - Selesai</th><td id="det_tanggal"></td></tr>
                    <tr><th>Waktu</th><td id="det_waktu"></td></tr>
                    <tr><th>Judul</th><td id="det_judul" class="font-weight-bold"></td></tr>
                    <tr><th>Jenis</th><td id="det_jenis"></td></tr>
                    <tr><th>Kelas</th><td id="det_kelas"></td></tr>
                    <tr><th>Jumlah Penerima</th><td id="det_penerima"></td></tr>
                    <tr><th>Status Dibaca</th><td id="det_dibaca"></td></tr>
                    <tr><th>Status</th><td id="det_status"></td></tr>
                    <tr><th>Lampiran</th><td id="det_lampiran"></td></tr>
                    <tr><th colspan="2">Isi Pesan:</th></tr>
                    <tr><td colspan="2" id="det_isi" style="white-space: pre-wrap;" class="bg-light p-3"></td></tr>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<form method="POST" id="formHapus" class="d-none">
    <input type="hidden" name="action" value="hapus">
    <input type="hidden" name="id" id="formHapusId">
</form>

<?php include '../templates/footer.php'; ?>
