<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['wali', 'admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$is_admin_or_kepala = in_array($user_level, ['admin', 'kepala_madrasah'], true) || in_array($_GET['session_type'] ?? '', ['admin', 'kepala_madrasah'], true);
$can_crud = !$is_admin_or_kepala;
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$wali_kelas_id = 0;
$wali_kelas_name = '';
$stmtWali = $pdo->prepare("SELECT id_kelas, nama_kelas FROM tb_kelas WHERE wali_kelas = ? OR wali_kelas = (SELECT nama_guru FROM tb_guru WHERE id_guru = ?)");
$stmtWali->execute([$guru_id, $guru_id]);
$wali_class = $stmtWali->fetch(PDO::FETCH_ASSOC);
if ($wali_class) {
    $wali_kelas_id = (int)$wali_class['id_kelas'];
    $wali_kelas_name = $wali_class['nama_kelas'];
}

$all_classes = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);

$selected_kelas_id = $wali_kelas_id;
if ($is_admin_or_kepala && isset($_GET['kelas'])) {
    $selected_kelas_id = (int)$_GET['kelas'];
}

$message = null;

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_crud) {
        $message = ['type' => 'danger', 'text' => 'Akses ditolak. Pengguna hanya memiliki akses lihat (monitoring).'];
    } else {
        $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $id_siswa = (int)($_POST['id_siswa'] ?? 0);
        $id_kelas = (int)($_POST['id_kelas'] ?? $selected_kelas_id);
        $tanggal = date('Y-m-d');
        $nama_ortu = trim((string)($_POST['nama_ortu'] ?? ''));
        $jenis_informasi = in_array($_POST['jenis_informasi'] ?? '', ['Pesan Individu', 'Informasi Kehadiran', 'Informasi Tugas', 'Informasi Perkembangan'], true) ? $_POST['jenis_informasi'] : 'Pesan Individu';
        $judul = trim((string)($_POST['judul'] ?? ''));
        $isi = trim((string)($_POST['isi'] ?? ''));

        // Auto isi nama ortu jika kosong
        if ($nama_ortu === '' && $id_siswa > 0) {
            $sto = $pdo->prepare("SELECT wali FROM tb_siswa WHERE id_siswa = ?");
            $sto->execute([$id_siswa]);
            $nama_ortu = (string)($sto->fetchColumn() ?: 'Orang Tua / Wali');
        }

        if ($id_siswa <= 0 || $judul === '' || $isi === '') {
            $message = ['type' => 'warning', 'text' => 'Pilih Siswa, Judul, dan Isi Pesan Komunikasi.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $status_kirim = 'Terkirim';
                    $status_dibaca = 'Belum Dibaca';
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_komunikasi_ortu (
                            id_wali, id_siswa, id_kelas, tanggal, nama_ortu,
                            jenis_informasi, judul, isi, status_kirim, status_dibaca
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $guru_id, $id_siswa, $id_kelas, $tanggal, $nama_ortu,
                        $jenis_informasi, $judul, $isi, $status_kirim, $status_dibaca
                    ]);
                    $message = ['type' => 'success', 'text' => 'Komunikasi orang tua berhasil dikirim/disimpan.'];
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE tb_komunikasi_ortu SET
                            id_siswa = ?, id_kelas = ?, nama_ortu = ?,
                            jenis_informasi = ?, judul = ?, isi = ?
                        WHERE id = ? " . (!$is_admin_or_kepala ? "AND id_wali = $guru_id" : "") . "
                    ");
                    $stmt->execute([
                        $id_siswa, $id_kelas, $nama_ortu,
                        $jenis_informasi, $judul, $isi, $id
                    ]);
                    $message = ['type' => 'success', 'text' => 'Data komunikasi orang tua diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM tb_komunikasi_ortu WHERE id = ? " . (!$is_admin_or_kepala ? "AND id_wali = $guru_id" : ""))->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Data komunikasi berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
}
}

$siswa_list = [];
if ($selected_kelas_id > 0) {
    $stS = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn, wali FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
    $stS->execute([$selected_kelas_id]);
    $siswa_list = $stS->fetchAll(PDO::FETCH_ASSOC);
}

$f_jenis = trim((string)($_GET['f_jenis'] ?? ''));
$f_kirim = trim((string)($_GET['f_kirim'] ?? ''));
$f_baca = trim((string)($_GET['f_baca'] ?? ''));

$where = ["1=1"];
$params = [];
if ($selected_kelas_id > 0) {
    $where[] = "k.id_kelas = ?";
    $params[] = $selected_kelas_id;
}
if (!$is_admin_or_kepala) {
    $where[] = "k.id_wali = ?";
    $params[] = $guru_id;
}
if ($f_jenis !== '') {
    $where[] = "k.jenis_informasi = ?";
    $params[] = $f_jenis;
}
if ($f_kirim !== '') {
    $where[] = "k.status_kirim = ?";
    $params[] = $f_kirim;
}
if ($f_baca !== '') {
    $where[] = "k.status_dibaca = ?";
    $params[] = $f_baca;
}

$where_sql = implode(' AND ', $where);
if ($f_jenis === 'Pengumuman Kelas') {
    $where_sql .= " AND 1=0";
}
$stmt = $pdo->prepare("
    SELECT k.*, s.nama_siswa, s.nisn, s.wali AS wali_asli, c.nama_kelas
    FROM tb_komunikasi_ortu k
    JOIN tb_siswa s ON s.id_siswa = k.id_siswa
    LEFT JOIN tb_kelas c ON c.id_kelas = k.id_kelas
    WHERE $where_sql
    ORDER BY k.tanggal DESC, k.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$jenis_options = ['Pesan Individu', 'Informasi Kehadiran', 'Informasi Tugas', 'Informasi Perkembangan'];

$page_title = 'Daftar Komunikasi Orang Tua';
$css_libs = [];
$js_libs = [];

$js_page = [<<<'JS'
$(document).ready(function() {

    function syncNamaOrtuFromSiswa(fallbackNama) {
        var wali = $('#inp_siswa').find(':selected').data('wali') || '';
        wali = String(wali).trim();
        if (wali !== '') {
            $('#inp_nama_ortu').val(wali);
        } else if (typeof fallbackNama !== 'undefined' && String(fallbackNama || '').trim() !== '') {
            $('#inp_nama_ortu').val(String(fallbackNama).trim());
        } else if ($('#inp_nama_ortu').val() === 'Orang Tua / Wali') {
            $('#inp_nama_ortu').val('');
        }
    }

    $('#inp_siswa').on('change', function() {
        syncNamaOrtuFromSiswa('');
    });

    $('#btnTambahKomOrtu').on('click', function() {
        $('#formKomOrtuAction').val('tambah');
        $('#komOrtuId').val('');
        $('#modalKomOrtuTitle').text('Kirim Informasi / Pesan ke Orang Tua');
        $('#formKomOrtu')[0].reset();
        syncNamaOrtuFromSiswa('');
        $('#modalKomOrtu').modal('show');
    });

    $(document).on('click', '.btn-edit-kom-ortu', function() {
        var data = $(this).data('json');
        $('#formKomOrtuAction').val('edit');
        $('#komOrtuId').val(data.id);
        $('#modalKomOrtuTitle').text('Edit Komunikasi Orang Tua');
        $('#inp_siswa').val(data.id_siswa);
        $('#inp_jenis').val(data.jenis_informasi);
        $('#inp_judul').val(data.judul);
        $('#inp_isi').val(data.isi);
        syncNamaOrtuFromSiswa(data.nama_ortu || data.wali_asli || '');
        $('#modalKomOrtu').modal('show');
    });

    $(document).on('click', '.btn-detail-kom-ortu', function() {
        var data = $(this).data('json');
        $('#det_tanggal').text(data.tanggal);
        $('#det_nama').text(data.nama_siswa);
        $('#det_ortu').text(data.nama_ortu || '-');
        $('#det_kelas').text(data.nama_kelas || '-');
        $('#det_jenis').html('<span class="badge badge-info">' + data.jenis_informasi + '</span>');
        $('#det_judul').text(data.judul);
        $('#det_kirim').text(data.status_kirim);
        $('#det_baca').text(data.status_dibaca);
        $('#det_isi').text(data.isi);
        $('#modalDetailKomOrtu').modal('show');
    });

    $(document).on('click', '.btn-hapus-kom-ortu', function() {
        var id = $(this).data('id');
        var judul = $(this).data('judul');
        Swal.fire({
            title: 'Hapus Pesan?',
            text: 'Informasi "' + judul + '" akan dihapus.',
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

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Daftar Komunikasi Orang Tua <?= !empty($wali_kelas_name) ? '- Kelas ' . htmlspecialchars($wali_kelas_name) : '' ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <?php if ($is_admin_or_kepala): ?>
            <div class="card mb-3">
                <div class="card-body p-3">
                    <form method="GET" class="form-inline">
                        <?php if (isset($_GET['session_type'])): ?><input type="hidden" name="session_type" value="<?= htmlspecialchars($_GET['session_type']) ?>"><?php endif; ?>
                        <label class="mr-2">Pilih Kelas:</label>
                        <select name="kelas" class="form-control" onchange="this.form.submit()">
                            <option value="">-- Semua Kelas --</option>
                            <?php foreach ($all_classes as $c): ?>
                                <option value="<?= (int)$c['id_kelas'] ?>" <?= $selected_kelas_id === (int)$c['id_kelas'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nama_kelas']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filter Card -->
            <div class="card mb-3">
                <div class="card-body p-3">
                    <form method="GET" class="row">
                        <?php if (isset($_GET['kelas'])): ?><input type="hidden" name="kelas" value="<?= (int)$_GET['kelas'] ?>"><?php endif; ?>
                        <div class="col-md-4 mb-2">
                            <label class="small font-weight-bold">Jenis Informasi</label>
                            <select name="f_jenis" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Jenis --</option>
                                <?php foreach ($jenis_options as $j): ?>
                                    <option value="<?= $j ?>" <?= $f_jenis === $j ? 'selected' : '' ?>><?= $j ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2">
                            <label class="small font-weight-bold">Status Kirim</label>
                            <select name="f_kirim" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua --</option>
                                <?php foreach (['Terkirim', 'Draft', 'Gagal'] as $sk): ?>
                                    <option value="<?= $sk ?>" <?= $f_kirim === $sk ? 'selected' : '' ?>><?= $sk ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2">
                            <label class="small font-weight-bold">Status Dibaca</label>
                            <select name="f_baca" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua --</option>
                                <?php foreach (['Belum Dibaca', 'Sudah Dibaca'] as $sb): ?>
                                    <option value="<?= $sb ?>" <?= $f_baca === $sb ? 'selected' : '' ?>><?= $sb ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2 d-flex align-items-end">
                            <a href="komunikasi_ortu.php" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i> Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap: 8px;">
                    <h4>Pesan & Laporan untuk Orang Tua / Wali Siswa (<?= count($rows) ?>)</h4>
                    <?php if ($can_crud): ?>
                    <button type="button" class="btn btn-primary" id="btnTambahKomOrtu" <?= empty($siswa_list) && !$is_admin_or_kepala ? 'disabled' : '' ?>>
                        <i class="fas fa-paper-plane mr-1"></i> Kirim Pesan Baru
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <style>
            .kom-timeline { position: relative; padding: 6px 0 6px 8px; }
            .kom-timeline::before { content: ''; position: absolute; left: 57px; top: 10px; bottom: 10px; width: 3px; border-radius: 3px; background: linear-gradient(180deg, #6777ef, #3abaf4); opacity: .35; }
            .kom-month { position: relative; z-index: 1; display: inline-block; margin: 14px 0 14px 34px; padding: 4px 16px; border-radius: 20px; background: #6777ef; color: #fff; font-weight: 800; font-size: 12px; letter-spacing: .3px; box-shadow: 0 4px 10px rgba(103,119,239,.35); }
            .kom-month:first-child { margin-top: 0; }
            .kom-item { position: relative; display: flex; gap: 14px; margin-bottom: 14px; }
            .kom-date { flex: 0 0 76px; text-align: center; z-index: 1; }
            .kom-date .d { display: block; width: 46px; height: 46px; margin: 0 auto; border-radius: 50%; background: #fff; border: 3px solid var(--tl-color, #6777ef); color: var(--tl-color, #6777ef); font-weight: 800; font-size: 17px; line-height: 40px; box-shadow: 0 3px 8px rgba(0,0,0,.10); }
            .kom-date .m { display: block; margin-top: 3px; font-size: 10.5px; font-weight: 800; color: #6c757d; text-transform: uppercase; }
            .kom-card { flex: 1; min-width: 0; background: #fff; border: 1px solid #e9edf5; border-radius: 12px; box-shadow: 0 4px 14px rgba(20,30,70,.07); padding: 12px 14px; border-left: 4px solid var(--tl-color, #6777ef); transition: box-shadow .2s ease, transform .2s ease; }
            .kom-card:hover { box-shadow: 0 10px 26px rgba(20,30,70,.13); transform: translateY(-2px); }
            @media (max-width: 575.98px) {
                .kom-timeline::before { left: 47px; }
                .kom-item { gap: 10px; }
                .kom-date { flex-basis: 60px; }
                .kom-date .d { width: 38px; height: 38px; font-size: 15px; line-height: 32px; }
            }
            </style>

            <?php if (empty($rows)): ?>
                <div class="card border-0 shadow-sm" style="border-radius: 12px;">
                    <div class="card-body p-5 text-center text-muted">
                        <i class="fas fa-comments fa-3x mb-3"></i><br>
                        Belum ada pesan pada filter ini.
                    </div>
                </div>
            <?php else: ?>
                <?php
                $bulan_id = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                $cur_month = '';
                $kom_color_map = [
                    'Pesan Individu' => '#6777ef',
                    'Pengumuman Kelas' => '#ea580c',
                    'Informasi Kehadiran' => '#059669',
                    'Informasi Tugas' => '#d97706',
                    'Informasi Perkembangan' => '#7c3aed',
                ];
                ?>
                <div class="kom-timeline">
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $mk = date('Y-m', strtotime($r['tanggal']));
                        if ($mk !== $cur_month) {
                            $cur_month = $mk;
                            echo '<div class="kom-month"><i class="far fa-calendar-alt mr-1"></i>' . $bulan_id[(int)date('n', strtotime($r['tanggal']))] . ' ' . date('Y', strtotime($r['tanggal'])) . '</div>';
                        }
                        $jenis_kom = (string)($r['jenis_informasi'] ?? '');
                        $tl_color = $kom_color_map[$jenis_kom] ?? '#6777ef';
                        $k_badge = $r['status_kirim'] === 'Terkirim' ? 'success' : ($r['status_kirim'] === 'Draft' ? 'warning' : 'danger');
                        $b_badge = $r['status_dibaca'] === 'Sudah Dibaca' ? 'info' : 'secondary';
                        $isi_cut = !empty($r['isi']) ? mb_strimwidth(strip_tags($r['isi']), 0, 110, '...') : '';
                        ?>
                        <div class="kom-item">
                            <div class="kom-date" style="--tl-color: <?= $tl_color ?>;">
                                <span class="d"><?= date('d', strtotime($r['tanggal'])) ?></span>
                                <span class="m"><?= mb_substr($bulan_id[(int)date('n', strtotime($r['tanggal']))], 0, 3) ?></span>
                            </div>
                            <div class="kom-card" style="--tl-color: <?= $tl_color ?>;">
                                <div class="d-flex justify-content-between align-items-center flex-wrap mb-1" style="gap: 6px;">
                                    <span class="badge px-2 py-1 font-weight-bold" style="background: <?= $tl_color ?>1a; color: <?= $tl_color ?>; font-size: 11px; border-radius: 6px;">
                                        <?= htmlspecialchars($jenis_kom !== '' ? $jenis_kom : 'Pesan') ?>
                                    </span>
                                    <div class="d-flex" style="gap: 4px;">
                                        <span class="badge badge-<?= $k_badge ?>"><?= htmlspecialchars($r['status_kirim']) ?></span>
                                        <span class="badge badge-<?= $b_badge ?>"><?= htmlspecialchars($r['status_dibaca']) ?></span>
                                    </div>
                                </div>
                                <h6 class="font-weight-bold text-dark mb-1" style="font-size: 14.5px;"><?= htmlspecialchars($r['judul']) ?></h6>
                                <?php if ($isi_cut !== ''): ?>
                                    <p class="small text-muted mb-2"><?= htmlspecialchars($isi_cut) ?></p>
                                <?php endif; ?>
                                <div class="small text-muted mb-2" style="font-size: 11.5px; line-height: 1.8;">
                                    <span class="mr-3"><i class="far fa-calendar-alt mr-1"></i><?= date('d/m/Y', strtotime($r['tanggal'])) ?></span>
                                    <span class="mr-3"><i class="fas fa-user-graduate mr-1"></i><?= htmlspecialchars($r['nama_siswa']) ?></span>
                                    <span><i class="fas fa-user-friends mr-1"></i><?= htmlspecialchars($r['nama_ortu'] ?: '-') ?></span>
                                </div>
                                <div class="pt-2 border-top d-flex flex-wrap" style="gap: 6px;">
                                    <button type="button" class="btn btn-info btn-sm px-2 font-weight-bold btn-detail-kom-ortu" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Detail" style="border-radius: 6px; font-size: 11.5px;">
                                        <i class="fas fa-eye mr-1"></i>Detail
                                    </button>
                                    <?php if ($can_crud): ?>
                                        <button type="button" class="btn btn-warning btn-sm px-2 font-weight-bold btn-edit-kom-ortu" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit" style="border-radius: 6px; font-size: 11.5px;">
                                            <i class="fas fa-edit mr-1"></i>Edit
                                        </button>
                                        <button type="button" class="btn btn-danger btn-sm px-2 font-weight-bold btn-hapus-kom-ortu" data-id="<?= (int)$r['id'] ?>" data-judul="<?= htmlspecialchars($r['judul'], ENT_QUOTES) ?>" title="Hapus" style="border-radius: 6px; font-size: 11.5px;">
                                            <i class="fas fa-trash mr-1"></i>Hapus
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- Modal Form Tambah / Edit -->
<div class="modal fade" id="modalKomOrtu" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formKomOrtu">
                <input type="hidden" name="action" id="formKomOrtuAction" value="tambah">
                <input type="hidden" name="id" id="komOrtuId" value="">
                <input type="hidden" name="id_kelas" value="<?= (int)$selected_kelas_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalKomOrtuTitle">Kirim Pesan ke Orang Tua</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Siswa <span class="text-danger">*</span></label>
                            <select name="id_siswa" id="inp_siswa" class="form-control" required>
                                <option value="">-- Pilih Siswa --</option>
                                <?php foreach ($siswa_list as $s): ?>
                                    <option value="<?= (int)$s['id_siswa'] ?>" data-wali="<?= htmlspecialchars($s['wali'] ?? '') ?>">
                                        <?= htmlspecialchars($s['nama_siswa']) ?> (<?= htmlspecialchars($s['nisn'] ?? '-') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Nama Orang Tua / Wali</label>
                            <input type="text" name="nama_ortu" id="inp_nama_ortu" class="form-control" placeholder="Bpk / Ibu ...">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Jenis Informasi</label>
                            <select name="jenis_informasi" id="inp_jenis" class="form-control">
                                <?php foreach ($jenis_options as $j): ?>
                                    <option value="<?= $j ?>"><?= $j ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 form-group">
                            <label>Judul / Subjek Pesan <span class="text-danger">*</span></label>
                            <input type="text" name="judul" id="inp_judul" class="form-control" required placeholder="Contoh: Pemberitahuan Perkembangan Belajar Ananda">
                        </div>
                        <div class="col-12 form-group">
                            <label>Isi Pesan Informasi <span class="text-danger">*</span></label>
                            <textarea name="isi" id="inp_isi" class="form-control" rows="6" style="min-height: 150px;" required placeholder="Tuliskan pesan atau laporan yang disampaikan kepada orang tua..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane mr-1"></i> Kirim Pesan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Detail -->
<div class="modal fade" id="modalDetailKomOrtu" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-envelope-open mr-2"></i>Rincian Pesan Orang Tua</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <table class="table table-bordered table-sm">
                    <tr><th width="35%">Tanggal</th><td id="det_tanggal"></td></tr>
                    <tr><th>Nama Siswa</th><td id="det_nama" class="font-weight-bold"></td></tr>
                    <tr><th>Orang Tua / Wali</th><td id="det_ortu"></td></tr>
                    <tr><th>Kelas</th><td id="det_kelas"></td></tr>
                    <tr><th>Jenis Informasi</th><td id="det_jenis"></td></tr>
                    <tr><th>Judul</th><td id="det_judul" class="font-weight-bold text-primary"></td></tr>
                    <tr><th>Status Pengiriman</th><td id="det_kirim"></td></tr>
                    <tr><th>Status Dibaca</th><td id="det_baca"></td></tr>
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
