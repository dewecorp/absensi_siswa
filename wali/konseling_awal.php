<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['wali', 'admin'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$can_crud = !in_array($user_level, ['admin', 'kepala_madrasah'], true);
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
if ($user_level === 'admin' && isset($_GET['kelas'])) {
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
        $tanggal = !empty($_POST['tanggal']) ? date('Y-m-d', strtotime($_POST['tanggal'])) : date('Y-m-d');
        $topik = trim((string)($_POST['topik'] ?? ''));
        $ringkasan_masalah = trim((string)($_POST['ringkasan_masalah'] ?? ''));
        $tindak_lanjut = trim((string)($_POST['tindak_lanjut'] ?? ''));
        $status = in_array($_POST['status'] ?? '', ['Terbuka', 'Proses', 'Selesai'], true) ? $_POST['status'] : 'Terbuka';
        $follow_up = trim((string)($_POST['follow_up'] ?? ''));

        if ($id_siswa <= 0 || $topik === '' || $ringkasan_masalah === '') {
            $message = ['type' => 'warning', 'text' => 'Pilih Siswa, isi Topik, dan Ringkasan Masalah.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_konseling_awal (
                            id_wali, id_siswa, id_kelas, tanggal, topik,
                            ringkasan_masalah, tindak_lanjut, status, follow_up
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $guru_id, $id_siswa, $id_kelas, $tanggal, $topik,
                        $ringkasan_masalah, $tindak_lanjut, $status, $follow_up
                    ]);
                    $message = ['type' => 'success', 'text' => 'Data konseling awal berhasil disimpan.'];
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE tb_konseling_awal SET
                            id_siswa = ?, id_kelas = ?, tanggal = ?, topik = ?,
                            ringkasan_masalah = ?, tindak_lanjut = ?, status = ?, follow_up = ?
                        WHERE id = ? " . ($user_level !== 'admin' ? "AND id_wali = $guru_id" : "") . "
                    ");
                    $stmt->execute([
                        $id_siswa, $id_kelas, $tanggal, $topik,
                        $ringkasan_masalah, $tindak_lanjut, $status, $follow_up, $id
                    ]);
                    $message = ['type' => 'success', 'text' => 'Data konseling berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM tb_konseling_awal WHERE id = ? " . ($user_level !== 'admin' ? "AND id_wali = $guru_id" : ""))->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Data konseling berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
}
}

$siswa_list = [];
if ($selected_kelas_id > 0) {
    $stS = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
    $stS->execute([$selected_kelas_id]);
    $siswa_list = $stS->fetchAll(PDO::FETCH_ASSOC);
}

$where = ["1=1"];
$params = [];
if ($selected_kelas_id > 0) {
    $where[] = "k.id_kelas = ?";
    $params[] = $selected_kelas_id;
}
if ($user_level !== 'admin') {
    $where[] = "k.id_wali = ?";
    $params[] = $guru_id;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT k.*, s.nama_siswa, s.nisn, c.nama_kelas
    FROM tb_konseling_awal k
    JOIN tb_siswa s ON s.id_siswa = k.id_siswa
    LEFT JOIN tb_kelas c ON c.id_kelas = k.id_kelas
    WHERE $where_sql
    ORDER BY k.tanggal DESC, k.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$status_options = ['Terbuka', 'Proses', 'Selesai'];
$topik_options = ['Motivasi Belajar', 'Penyesuaian Sosial', 'Keluarga', 'Kedisiplinan', 'Kecemasan / Emosi', 'Minat & Bakat', 'Ibadah & Spiritual', 'Lainnya'];

// Master template konseling (sinkron dengan menu Data Konseling)
$stMasterKons = $pdo->prepare("
    SELECT id, topik, ringkasan, tindak_lanjut, follow_up
    FROM tb_master_konseling
    WHERE id_guru = ? OR id_guru IS NULL OR id_guru = 0
    ORDER BY topik ASC, id ASC
");
$stMasterKons->execute([$guru_id]);
$master_konseling = $stMasterKons->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Daftar Konseling Siswa';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
var masterKonseling =
JS
. json_encode($master_konseling) . ";\n" . <<<'JS'
$(document).ready(function() {
    if ($('#table-konseling').length) {
        $('#table-konseling').DataTable({
            'order': [[1, 'desc']],
            'columnDefs': [{ 'sortable': false, 'targets': [10] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada data konseling',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    function shortKons(s, n) {
        s = (s || '').toString().replace(/\s+/g, ' ').trim();
        n = n || 100;
        return s.length > n ? s.substr(0, n) + '...' : s;
    }

    function autogrowKons($ta) {
        if (!$ta || !$ta.length) return;
        $ta.css('height', 'auto');
        $ta.css('height', ($ta[0].scrollHeight + 4) + 'px');
    }
    $(document).on('input', '#modalKonseling textarea', function() { autogrowKons($(this)); });
    $('#modalKonseling').on('shown.bs.modal', function() {
        $('#modalKonseling textarea').each(function() { autogrowKons($(this)); });
    });

    function populateKons(topik) {
        $('#sel_kons_masalah').empty().append('<option value="">-- Pilih template ringkasan --</option>');
        $('#sel_kons_tl').empty().append('<option value="">-- Pilih template tindak lanjut --</option>');
        $('#sel_kons_fu').empty().append('<option value="">-- Pilih template rencana follow up --</option>');
        if (!topik) return;
        masterKonseling.filter(function(m) {
            return (m.topik || '').toLowerCase() === topik.toLowerCase();
        }).forEach(function(m) {
            var o1 = $('<option>').val(m.ringkasan).text(shortKons(m.ringkasan, 110));
            o1.data('item', m);
            $('#sel_kons_masalah').append(o1);
            if (m.tindak_lanjut) {
                var o2 = $('<option>').val(m.tindak_lanjut).text(shortKons(m.tindak_lanjut, 110));
                o2.data('item', m);
                $('#sel_kons_tl').append(o2);
            }
            if (m.follow_up) {
                var o3 = $('<option>').val(m.follow_up).text(shortKons(m.follow_up, 110));
                o3.data('item', m);
                $('#sel_kons_fu').append(o3);
            }
        });
    }

    function fillFullKons() {
        $('#sel_kons_full').empty().append('<option value="">-- Pilih satu template lengkap (opsional) --</option>');
        var cur = $('#inp_topik').val() || '';
        if (!cur) return;
        masterKonseling.filter(function(m) {
            return (m.topik || '').toLowerCase() === cur.toLowerCase();
        }).forEach(function(m) {
            var o = $('<option>').val(m.id).text(shortKons(m.ringkasan, 110));
            o.data('item', m);
            $('#sel_kons_full').append(o);
        });
    }

    $('#inp_topik').on('change', function() {
        populateKons($(this).val());
        fillFullKons();
    });

    $('#sel_kons_masalah').on('change', function() {
        var v = $(this).val() || '';
        if (v) { $('#inp_masalah').val(v); autogrowKons($('#inp_masalah')); }
    });
    $('#sel_kons_tl').on('change', function() {
        var v = $(this).val() || '';
        if (v) { $('#inp_tindak_lanjut').val(v); autogrowKons($('#inp_tindak_lanjut')); }
    });
    $('#sel_kons_fu').on('change', function() {
        var v = $(this).val() || '';
        if (v) { $('#inp_follow_up').val(v); autogrowKons($('#inp_follow_up')); }
    });
    $(document).on('change', '#sel_kons_full', function() {
        var item = $('#sel_kons_full option:selected').data('item');
        if (!item) return;
        $('#inp_masalah').val(item.ringkasan || '');
        $('#inp_tindak_lanjut').val(item.tindak_lanjut || '');
        $('#inp_follow_up').val(item.follow_up || '');
        $('#modalKonseling textarea').each(function() { autogrowKons($(this)); });
    });

    $('#btnTambahKonseling').on('click', function() {
        $('#formKonselingAction').val('tambah');
        $('#konselingId').val('');
        $('#modalKonselingTitle').text('Catat Sesi Konseling Siswa');
        $('#formKonseling')[0].reset();
        var curTopik = $('#inp_topik').val() || '';
        populateKons(curTopik);
        fillFullKons();
        $('#modalKonseling').modal('show');
        setTimeout(function() {
            $('#modalKonseling textarea').each(function() { autogrowKons($(this)); });
        }, 120);
    });

    $(document).on('click', '.btn-edit-konseling', function() {
        var data = $(this).data('json');
        $('#formKonselingAction').val('edit');
        $('#konselingId').val(data.id);
        $('#modalKonselingTitle').text('Edit Konseling Siswa');
        $('#inp_siswa').val(data.id_siswa);
        $('#inp_tanggal').val(data.tanggal);
        $('#inp_topik').val(data.topik);
        populateKons(data.topik);
        $('#sel_kons_masalah option').each(function() {
            if ($(this).val() === (data.ringkasan_masalah || '')) $(this).prop('selected', true);
        });
        $('#sel_kons_tl option').each(function() {
            if ($(this).val() === (data.tindak_lanjut || '')) $(this).prop('selected', true);
        });
        $('#sel_kons_fu option').each(function() {
            if ($(this).val() === (data.follow_up || '')) $(this).prop('selected', true);
        });
        fillFullKons();
        $('#inp_masalah').val(data.ringkasan_masalah);
        $('#inp_tindak_lanjut').val(data.tindak_lanjut || '');
        $('#inp_follow_up').val(data.follow_up || '');
        $('#inp_status').val(data.status);
        $('#modalKonseling').modal('show');
        setTimeout(function() {
            $('#modalKonseling textarea').each(function() { autogrowKons($(this)); });
        }, 120);
    });

    $(document).on('click', '.btn-detail-konseling', function() {
        var data = $(this).data('json');
        $('#det_tanggal').text(data.tanggal);
        $('#det_nama').text(data.nama_siswa);
        $('#det_nisn').text(data.nisn || '-');
        $('#det_kelas').text(data.nama_kelas || '-');
        $('#det_topik').text(data.topik);
        $('#det_status').text(data.status);
        $('#det_masalah').text(data.ringkasan_masalah);
        $('#det_tindak_lanjut').text(data.tindak_lanjut || '-');
        $('#det_follow_up').text(data.follow_up || '-');
        $('#modalDetailKonseling').modal('show');
    });

    $(document).on('click', '.btn-hapus-konseling', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        Swal.fire({
            title: 'Hapus Sesi Konseling?',
            text: 'Catatan konseling untuk ' + nama + ' akan dihapus.',
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
.aksi-satu-baris { display: inline-flex; flex-wrap: nowrap; gap: 4px; align-items: center; justify-content: center; white-space: nowrap; }
.aksi-satu-baris .btn { margin: 0; flex: 0 0 auto; width: 30px; height: 30px; padding: 0; display: inline-flex; align-items: center; justify-content: center; line-height: 1; }
.aksi-satu-baris .btn i { margin: 0; font-size: 13px; line-height: 1; }
#table-konseling td:last-child { white-space: nowrap; }
</style>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Daftar Konseling Siswa <?= !empty($wali_kelas_name) ? '- Kelas ' . htmlspecialchars($wali_kelas_name) : '' ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <?php if ($user_level === 'admin'): ?>
            <div class="card mb-3">
                <div class="card-body p-3">
                    <form method="GET" class="form-inline">
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

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                    <h4>Catatan Konseling Pribadi & Wawancara Awal</h4>
                    <div>
                        <a href="data_konseling.php" class="btn btn-info btn-sm mr-1">
                            <i class="fas fa-database mr-1"></i> Data Konseling
                        </a>
                        <?php
                        $qs_kons = [];
                        if ($selected_kelas_id > 0) { $qs_kons['kelas'] = $selected_kelas_id; }
                        $url_kons_cetak = 'export_konseling_pdf.php?' . http_build_query(array_merge($qs_kons, ['mode' => 'print']));
                        $url_kons_xls = 'export_konseling_excel.php?' . http_build_query($qs_kons);
                        ?>
                        <a href="<?= htmlspecialchars($url_kons_cetak) ?>" target="_blank" class="btn btn-danger btn-sm mr-1" title="Cetak / Simpan PDF">
                            <i class="fas fa-print mr-1"></i> Cetak / PDF
                        </a>
                        <a href="<?= htmlspecialchars($url_kons_xls) ?>" class="btn btn-success btn-sm mr-2" title="Ekspor Excel">
                            <i class="fas fa-file-excel mr-1"></i> Excel
                        </a>
                        <?php if ($can_crud): ?>
                        <button type="button" class="btn btn-primary btn-sm" id="btnTambahKonseling" <?= empty($siswa_list) && $user_level !== 'admin' ? 'disabled' : '' ?>>
                            <i class="fas fa-plus mr-1"></i> Sesi Konseling Baru
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="alert alert-light border small text-muted mb-3">
                        <i class="fas fa-shield-alt mr-1 text-primary"></i> Data konseling bersifat rahasia dan terlindungi. Tabel hanya menampilkan ringkasan singkat; rincian sensitif dapat dibuka melalui tombol Detail.
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered table-sm" id="table-konseling">
                            <thead>
                                <tr>
                                    <th width="4%">No</th>
                                    <th>Tanggal</th>
                                    <th>Nama Siswa</th>
                                    <th>NISN</th>
                                    <th>Kelas</th>
                                    <th>Topik</th>
                                    <th width="20%">Ringkasan Masalah</th>
                                    <th>Tindak Lanjut</th>
                                    <th>Status</th>
                                    <th>Follow Up</th>
                                    <th style="width:180px;min-width:180px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $i => $r): ?>
                                    <?php
                                    $st_badge = $r['status'] === 'Selesai' ? 'success' : ($r['status'] === 'Proses' ? 'warning' : 'primary');
                                    $raw_m = strip_tags($r['ringkasan_masalah']);
                                    $m_cut = mb_strlen($raw_m) > 35 ? mb_substr($raw_m, 0, 35) . '...' : $raw_m;
                                    $raw_tl = strip_tags($r['tindak_lanjut'] ?? '');
                                    $tl_cut = mb_strlen($raw_tl) > 30 ? mb_substr($raw_tl, 0, 30) . '...' : ($raw_tl ?: '-');
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                                        <td><strong><?= htmlspecialchars($r['nama_siswa']) ?></strong></td>
                                        <td><?= htmlspecialchars($r['nisn'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['nama_kelas'] ?? '-') ?></td>
                                        <td><span class="badge badge-light border"><?= htmlspecialchars($r['topik']) ?></span></td>
                                        <td><span title="<?= htmlspecialchars($raw_m) ?>"><?= htmlspecialchars($m_cut) ?></span></td>
                                        <td><small class="text-success"><?= htmlspecialchars($tl_cut) ?></small></td>
                                        <td class="text-center"><span class="badge badge-<?= $st_badge ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                        <td><small><?= htmlspecialchars(mb_strimwidth($r['follow_up'] ?? '-', 0, 25, '...')) ?></small></td>
                                        <td class="text-center align-middle">
                                             <div class="aksi-satu-baris">
                                                 <button type="button" class="btn btn-info btn-sm btn-detail-konseling" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Detail Rahasia">
                                                     <i class="fas fa-eye"></i>
                                                 </button>
                                                 <?php if ($can_crud): ?>
                                                 <button type="button" class="btn btn-warning btn-sm btn-edit-konseling" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                     <i class="fas fa-edit"></i>
                                                 </button>
                                                 <button type="button" class="btn btn-danger btn-sm btn-hapus-konseling" data-id="<?= (int)$r['id'] ?>" data-nama="<?= htmlspecialchars($r['nama_siswa'], ENT_QUOTES) ?>" title="Hapus">
                                                     <i class="fas fa-trash"></i>
                                                 </button>
                                                 <?php endif; ?>
                                                 <a href="export_konseling_pdf.php?id_siswa=<?= (int)$r['id_siswa'] ?>&mode=print" target="_blank" class="btn btn-danger btn-sm" title="Cetak / Simpan PDF laporan siswa ini">
                                                    <i class="fas fa-print"></i>
                                                </a>
                                                <a href="export_konseling_excel.php?id_siswa=<?= (int)$r['id_siswa'] ?>" class="btn btn-success btn-sm" title="Ekspor Excel siswa ini">
                                                    <i class="fas fa-file-excel"></i>
                                                </a>
                                            </div>
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

<!-- Modal Form Tambah / Edit -->
<div class="modal fade" id="modalKonseling" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formKonseling">
                <input type="hidden" name="action" id="formKonselingAction" value="tambah">
                <input type="hidden" name="id" id="konselingId" value="">
                <input type="hidden" name="id_kelas" value="<?= (int)$selected_kelas_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalKonselingTitle">Sesi Konseling Siswa</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Siswa <span class="text-danger">*</span></label>
                            <select name="id_siswa" id="inp_siswa" class="form-control" required>
                                <option value="">-- Pilih Siswa --</option>
                                <?php foreach ($siswa_list as $s): ?>
                                    <option value="<?= (int)$s['id_siswa'] ?>">
                                        <?= htmlspecialchars($s['nama_siswa']) ?> (<?= htmlspecialchars($s['nisn'] ?? '-') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Tanggal</label>
                            <input type="date" name="tanggal" id="inp_tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Status</label>
                            <select name="status" id="inp_status" class="form-control">
                                <?php foreach ($status_options as $st): ?>
                                    <option value="<?= $st ?>"><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Topik / Fokus Konseling <span class="text-danger">*</span></label>
                            <select name="topik" id="inp_topik" class="form-control" required>
                                <option value="">-- Pilih Topik --</option>
                                <?php foreach ($topik_options as $t): ?>
                                    <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 form-group">
                            <label>Pilih Cepat: Satu Template Lengkap <small class="text-muted">(opsional, isi 3 kolom sekaligus)</small></label>
                            <select id="sel_kons_full" class="form-control">
                                <option value="">-- Pilih satu template lengkap (opsional) --</option>
                            </select>
                        </div>
                        <div class="col-12 form-group">
                            <label>Ringkasan Masalah / Hasil Konseling <span class="text-danger">*</span></label>
                            <select id="sel_kons_masalah" class="form-control form-control-sm mb-1">
                                <option value="">-- Pilih template ringkasan sesuai kondisi siswa --</option>
                            </select>
                            <textarea name="ringkasan_masalah" id="inp_masalah" class="form-control" rows="5" style="min-height:120px;" required placeholder="Pilih dari dropdown di atas sesuai kondisi siswa, atau ketik manual..."></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Tindak Lanjut / Solusi yang Disepakati</label>
                            <select id="sel_kons_tl" class="form-control form-control-sm mb-1">
                                <option value="">-- Pilih template tindak lanjut --</option>
                            </select>
                            <textarea name="tindak_lanjut" id="inp_tindak_lanjut" class="form-control" rows="5" style="min-height:120px;" placeholder="Pilih dari dropdown di atas sesuai kondisi siswa, atau ketik manual..."></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Rencana Follow Up</label>
                            <select id="sel_kons_fu" class="form-control form-control-sm mb-1">
                                <option value="">-- Pilih template rencana follow up --</option>
                            </select>
                            <textarea name="follow_up" id="inp_follow_up" class="form-control" rows="5" style="min-height:120px;" placeholder="Pilih dari dropdown di atas sesuai kondisi siswa, atau ketik manual..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan Konseling</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Detail Rahasia -->
<div class="modal fade" id="modalDetailKonseling" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-shield mr-2"></i>Rincian Rahasia Konseling Siswa</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <table class="table table-bordered table-sm">
                    <tr><th width="30%">Tanggal</th><td id="det_tanggal"></td></tr>
                    <tr><th>Nama Siswa</th><td id="det_nama" class="font-weight-bold"></td></tr>
                    <tr><th>NISN</th><td id="det_nisn"></td></tr>
                    <tr><th>Kelas</th><td id="det_kelas"></td></tr>
                    <tr><th>Topik</th><td id="det_topik" class="font-weight-bold text-primary"></td></tr>
                    <tr><th>Status</th><td id="det_status"></td></tr>
                    <tr><th colspan="2">Ringkasan Masalah / Hasil Diskusi:</th></tr>
                    <tr><td colspan="2" id="det_masalah" style="white-space: pre-wrap;" class="bg-light p-2"></td></tr>
                    <tr><th colspan="2">Tindak Lanjut yang Disepakati:</th></tr>
                    <tr><td colspan="2" id="det_tindak_lanjut" style="white-space: pre-wrap;" class="bg-light p-2 text-success"></td></tr>
                    <tr><th colspan="2">Rencana Follow Up:</th></tr>
                    <tr><td colspan="2" id="det_follow_up" style="white-space: pre-wrap;" class="bg-light p-2 text-info"></td></tr>
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


