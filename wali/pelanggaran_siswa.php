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

// Deteksi kelas wali
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
        $message = ['type' => 'danger', 'text' => 'Akses ditolak. Admin hanya mode lihat.'];
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'tambah' || $action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $id_siswa = (int)($_POST['id_siswa'] ?? 0);
            $id_kelas = (int)($_POST['id_kelas'] ?? $selected_kelas_id);
            $tanggal = !empty($_POST['tanggal']) ? date('Y-m-d', strtotime($_POST['tanggal'])) : date('Y-m-d');
            $jenis_pelanggaran = trim((string)($_POST['jenis_pelanggaran'] ?? ''));
            $kategori = trim((string)($_POST['kategori'] ?? 'Ringan'));
            $poin = (int)($_POST['poin'] ?? 0);
            $tindakan = trim((string)($_POST['tindakan'] ?? ''));
            $orang_tua = trim((string)($_POST['orang_tua'] ?? 'Belum Dipanggil'));
            if ($id_siswa <= 0 || $jenis_pelanggaran === '') {
                $message = ['type' => 'warning', 'text' => 'Pilih Siswa dan isi Jenis Pelanggaran.'];
            } else {
                $detJ = function_exists('pelanggaran_deteksi_jenis') ? pelanggaran_deteksi_jenis($jenis_pelanggaran, $kategori) : ['jenis' => 'Kedisiplinan'];
                $jenis_binaan = $detJ['jenis'];
                try {
                    if ($action === 'tambah') {
                        $stmt = $pdo->prepare("
                            INSERT INTO tb_pelanggaran_siswa (
                                id_wali, id_siswa, id_kelas, tanggal, jenis_pelanggaran,
                                kategori, poin, tindakan, orang_tua, status, jenis_binaan
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Dicatat', ?)
                        ");
                        $stmt->execute([
                            $guru_id, $id_siswa, $id_kelas, $tanggal, $jenis_pelanggaran,
                            $kategori, $poin, $tindakan, $orang_tua, $jenis_binaan
                        ]);
                        $message = ['type' => 'success', 'text' => 'Pelanggaran siswa berhasil dicatat.'];
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE tb_pelanggaran_siswa SET
                                id_siswa = ?, id_kelas = ?, tanggal = ?, jenis_pelanggaran = ?,
                                kategori = ?, poin = ?, tindakan = ?, orang_tua = ?, jenis_binaan = ?
                            WHERE id = ? " . (!$is_admin_or_kepala ? "AND id_wali = $guru_id" : "") . "
                        ");
                        $stmt->execute([
                            $id_siswa, $id_kelas, $tanggal, $jenis_pelanggaran,
                            $kategori, $poin, $tindakan, $orang_tua, $jenis_binaan, $id
                        ]);
                        $message = ['type' => 'success', 'text' => 'Data pelanggaran berhasil diperbarui.'];
                    }
                    sync_pelanggaran_statuses($pdo);
                } catch (Exception $e) {
                    $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
                }
            }
        } elseif ($action === 'hapus') {
            $id = (int)($_POST['id'] ?? 0);
            try {
                $pdo->prepare("DELETE FROM tb_pelanggaran_siswa WHERE id = ? " . (!$is_admin_or_kepala ? "AND id_wali = $guru_id" : ""))->execute([$id]);
                $message = ['type' => 'success', 'text' => 'Data pelanggaran berhasil dihapus.'];
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
            }
        }
    }
}

// Siswa kelas ini
$siswa_list = [];
if ($selected_kelas_id > 0) {
    $stS = $pdo->prepare("SELECT s.id_siswa, s.nama_siswa, s.nisn, k.nama_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas WHERE s.id_kelas = ? ORDER BY s.nama_siswa ASC");
    $stS->execute([$selected_kelas_id]);
    $siswa_list = $stS->fetchAll(PDO::FETCH_ASSOC);
} elseif ($is_admin_or_kepala) {
    $siswa_list = $pdo->query("SELECT s.id_siswa, s.nama_siswa, s.nisn, k.nama_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas ORDER BY k.nama_kelas ASC, s.nama_siswa ASC")->fetchAll(PDO::FETCH_ASSOC);
}

$f_kategori = trim((string)($_GET['f_kategori'] ?? ''));
$f_status = trim((string)($_GET['f_status'] ?? ''));

// Fetch rows pelanggaran
sync_pelanggaran_statuses($pdo);
$where = ["1=1"];
$params = [];
if ($selected_kelas_id > 0) {
    $where[] = "p.id_kelas = ?";
    $params[] = $selected_kelas_id;
}
if (!$is_admin_or_kepala) {
    $where[] = "p.id_wali = ?";
    $params[] = $guru_id;
}
if ($f_kategori !== '') {
    $where[] = "p.kategori = ?";
    $params[] = $f_kategori;
}
if ($f_status !== '') {
    $where[] = "p.status = ?";
    $params[] = $f_status;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT p.*, s.nama_siswa, s.nisn, k.nama_kelas,
           (SELECT COUNT(*) FROM tb_pembinaan_siswa b WHERE b.id_pelanggaran = p.id) AS n_bina
    FROM tb_pelanggaran_siswa p
    JOIN tb_siswa s ON s.id_siswa = p.id_siswa
    LEFT JOIN tb_kelas k ON k.id_kelas = p.id_kelas
    WHERE $where_sql
    ORDER BY p.tanggal DESC, p.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$kategori_options = ['Ringan', 'Sedang', 'Berat'];
$status_options = ['Dicatat', 'Ditindaklanjuti', 'Selesai'];

// Master template pelanggaran (sinkron dengan menu Data Pelanggaran)
$stMasterLanggar = $pdo->prepare("
    SELECT id, kategori, jenis, tindakan, poin, jenis_binaan
    FROM tb_master_pelanggaran
    WHERE id_guru = ? OR id_guru IS NULL OR id_guru = 0
    ORDER BY FIELD(kategori, 'Ringan', 'Sedang', 'Berat'), poin ASC, id ASC
");
$stMasterLanggar->execute([$guru_id]);
$master_pelanggaran = $stMasterLanggar->fetchAll(PDO::FETCH_ASSOC);

// Akumulasi poin per siswa (dari data tampil) untuk badge total di tabel
$poin_total_map = [];
$poin_count_map = [];
foreach ($rows as $tr) {
    $sid = (int)$tr['id_siswa'];
    $poin_total_map[$sid] = ($poin_total_map[$sid] ?? 0) + (int)$tr['poin'];
    $poin_count_map[$sid] = ($poin_count_map[$sid] ?? 0) + 1;
}

// AJAX Timeline + akumulasi poin per siswa (untuk modal detail)
if (isset($_GET['ajax_timeline']) && (int)$_GET['ajax_timeline'] === 1) {
    header('Content-Type: application/json; charset=UTF-8');
    $sid = (int)($_GET['id_siswa'] ?? 0);
    $stT = $pdo->prepare("
        SELECT id, tanggal, jenis_pelanggaran, kategori, poin, tindakan, orang_tua, status
        FROM tb_pelanggaran_siswa
        WHERE id_siswa = ? " . (!$is_admin_or_kepala ? "AND id_wali = $guru_id" : "") . "
        ORDER BY tanggal DESC, id DESC
    ");
    $stT->execute([$sid]);
    $t_rows = $stT->fetchAll(PDO::FETCH_ASSOC);
    $total = 0;
    foreach ($t_rows as $tr) { $total += (int)$tr['poin']; }
    foreach ($t_rows as &$it) {
        $it['tanggal_formatted'] = date('d F Y', strtotime($it['tanggal']));
    }
    unset($it);
    $sanksi = function_exists('pelanggaran_sanksi_by_poin') ? pelanggaran_sanksi_by_poin($total) : ['level' => '-', 'badge' => 'secondary', 'desc' => ''];
    echo json_encode(['total' => $total, 'count' => count($t_rows), 'sanksi' => $sanksi, 'timeline' => $t_rows]);
    exit;
}

$page_title = 'Daftar Pelanggaran Siswa';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
var masterPelanggaran =
JS
. json_encode($master_pelanggaran) . ";\n" . <<<'JS'
$(document).ready(function() {
    if ($('#table-pelanggaran').length) {
        $('#table-pelanggaran').DataTable({
            'order': [[1, 'desc']],
            'columnDefs': [{ 'sortable': false, 'targets': [10] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada catatan pelanggaran',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    function shortLanggar(s, n) {
        s = (s || '').toString().replace(/\s+/g, ' ').trim();
        n = n || 110;
        return s.length > n ? s.substr(0, n) + '...' : s;
    }

    function autogrowLanggar($ta) {
        if (!$ta || !$ta.length) return;
        $ta.css('height', 'auto');
        $ta.css('height', ($ta[0].scrollHeight + 4) + 'px');
    }
    $(document).on('input', '#modalPelanggaran textarea', function() { autogrowLanggar($(this)); });
    $('#modalPelanggaran').on('shown.bs.modal', function() {
        $('#modalPelanggaran textarea').each(function() { autogrowLanggar($(this)); });
    });

    // Isi dropdown template sesuai kategori terpilih
    function populateLanggar(kategori) {
        $('#sel_langgar_jenis').empty().append('<option value="">-- Pilih jenis pelanggaran sesuai kondisi --</option>');
        $('#sel_langgar_tindakan').empty().append('<option value="">-- Pilih tindakan/sanksi sesuai kondisi --</option>');
        if (!kategori) return;
        masterPelanggaran.filter(function(m) {
            return (m.kategori || '').toLowerCase() === kategori.toLowerCase();
        }).forEach(function(m) {
            var o1 = $('<option>').val(m.jenis).text(shortLanggar(m.jenis, 110) + ' (' + m.poin + ' poin)');
            o1.data('item', m);
            $('#sel_langgar_jenis').append(o1);
            var o2 = $('<option>').val(m.tindakan).text(shortLanggar(m.tindakan, 110));
            o2.data('item', m);
            $('#sel_langgar_tindakan').append(o2);
        });
    }

    $('#inp_kategori').on('change', function() {
        populateLanggar($(this).val());
    });

    // Guru tinggal pilih sesuai kondisi siswa -> isi textarea masing-masing (bisa diubah manual)
    $('#sel_langgar_jenis').on('change', function() {
        var item = $('#sel_langgar_jenis option:selected').data('item');
        if ($('#sel_langgar_jenis').val()) {
            $('#inp_jenis').val($('#sel_langgar_jenis').val());
            autogrowLanggar($('#inp_jenis'));
        }
        if (item) {
            $('#inp_poin').val(item.poin || 0);
            $('#inp_kategori').val(item.kategori || $('#inp_kategori').val());
        }
    });
    $('#sel_langgar_tindakan').on('change', function() {
        if ($('#sel_langgar_tindakan').val()) {
            $('#inp_tindakan').val($('#sel_langgar_tindakan').val());
            autogrowLanggar($('#inp_tindakan'));
        }
    });

    $('#btnTambahPelanggaran').on('click', function() {
        $('#formPelanggaranAction').val('tambah');
        $('#pelanggaranId').val('');
        $('#modalPelanggaranTitle').text('Catat Pelanggaran Siswa');
        $('#formPelanggaran')[0].reset();
        var curKat = $('#inp_kategori').val() || 'Ringan';
        populateLanggar(curKat);
        $('#modalPelanggaran').modal('show');
        setTimeout(function() {
            $('#modalPelanggaran textarea').each(function() { autogrowLanggar($(this)); });
        }, 120);
    });

    $(document).on('click', '.btn-edit-pelanggaran', function() {
        var data = $(this).data('json');
        $('#formPelanggaranAction').val('edit');
        $('#pelanggaranId').val(data.id);
        $('#modalPelanggaranTitle').text('Edit Pelanggaran Siswa');
        $('#inp_siswa').val(data.id_siswa);
        $('#inp_tanggal').val(data.tanggal);
        $('#inp_kategori').val(data.kategori || 'Ringan');
        populateLanggar(data.kategori || 'Ringan');
        // Samakan dropdown dengan nilai tersimpan bila cocok persis
        $('#sel_langgar_jenis option').each(function() {
            if ($(this).val() === (data.jenis_pelanggaran || '')) $(this).prop('selected', true);
        });
        $('#sel_langgar_tindakan option').each(function() {
            if ($(this).val() === (data.tindakan || '')) $(this).prop('selected', true);
        });
        $('#inp_jenis').val(data.jenis_pelanggaran);
        $('#inp_poin').val(data.poin || 0);
        $('#inp_tindakan').val(data.tindakan || '');
        $('#inp_ortu').val(data.orang_tua || '');
        $('#inp_status').val(data.status);
        $('#modalPelanggaran').modal('show');
        setTimeout(function() {
            $('#modalPelanggaran textarea').each(function() { autogrowLanggar($(this)); });
        }, 120);
    });

    $(document).on('click', '.btn-detail-pelanggaran', function() {
        var data = $(this).data('json');
        $('#det_tanggal').text(data.tanggal);
        $('#det_nama').text(data.nama_siswa);
        $('#det_nisn').text(data.nisn || '-');
        $('#det_kelas').text(data.nama_kelas || '-');
        $('#det_jenis').text(data.jenis_pelanggaran);
        $('#det_kategori').html('<span class="badge badge-' + (data.kategori === 'Berat' ? 'danger' : (data.kategori === 'Sedang' ? 'warning' : 'info')) + '">' + data.kategori + '</span>');
        $('#det_poin').text(data.poin);
        $('#det_status').text(data.status);
        $('#det_tindakan').text(data.tindakan || '-');
        $('#det_ortu').text(data.orang_tua || '-');
        $('#timelineLanggar').html('<div class="text-center p-3"><i class="fas fa-spinner fa-spin"></i> Memuat timeline...</div>');
        $('#akumulasiLanggar').html('');
        $.ajax({
            url: 'pelanggaran_siswa.php?ajax_timeline=1&id_siswa=' + data.id_siswa,
            dataType: 'json',
            success: function(res) {
                var total = (res && typeof res.total !== 'undefined') ? res.total : 0;
                var count = (res && typeof res.count !== 'undefined') ? res.count : 0;
                var sanksi = (res && res.sanksi) ? res.sanksi : { level: '-', badge: 'secondary', desc: '' };
                var pct = Math.max(4, Math.min(100, total));
                var acc = '<div class="border rounded p-3 mb-2" style="background:#f8fafc;border-color:#e2e8f0 !important;">';
                acc += '<div class="d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">';
                acc += '<div class="text-dark"><strong>Total ' + total + ' poin</strong> <span class="text-muted">dari ' + count + ' pelanggaran</span></div>';
                acc += '<span class="badge badge-' + sanksi.badge + '" style="font-size:12px;">' + sanksi.level + '</span>';
                acc += '</div><div class="small mt-1 text-dark">' + sanksi.desc + '</div>';
                acc += '<div class="progress mt-2" style="height:12px;background:#e2e8f0;border-radius:999px;overflow:hidden;"><div class="progress-bar bg-' + sanksi.badge + '" role="progressbar" aria-valuenow="' + total + '" aria-valuemin="0" aria-valuemax="100" style="width:' + pct + '%;font-size:10px;font-weight:700;line-height:12px;color:#fff;">' + total + '</div></div>';
                acc += '<div class="d-flex justify-content-between small font-weight-bold text-dark mt-1"><span>0</span><span>25 Pemantauan</span><span>50 SP1</span><span>75 Skorsing</span><span>100 DO</span></div></div>';
                $('#akumulasiLanggar').html(acc);
                var tl = (res && res.timeline) ? res.timeline : [];
                if (!tl.length) {
                    $('#timelineLanggar').html('<div class="text-muted p-3 text-center">Belum ada riwayat lain.</div>');
                    return;
                }
                var run = 0;
                var html = '<div class="activities">';
                tl.forEach(function(item) {
                    run += parseInt(item.poin || 0, 10);
                    var kb = item.kategori === 'Berat' ? 'danger' : (item.kategori === 'Sedang' ? 'warning' : 'info');
                    html += '<div class="activity">';
                    html += '  <div class="activity-icon bg-danger text-white"><i class="fas fa-exclamation-triangle"></i></div>';
                    html += '  <div class="activity-detail">';
                    html += '    <div class="mb-1"><span class="text-job text-danger font-weight-bold">' + item.tanggal + '</span>';
                    html += ' <span class="badge badge-' + kb + '">' + item.kategori + '</span>';
                    html += ' <span class="badge badge-dark">+' + item.poin + ' poin (akumulasi ' + run + ')</span></div>';
                    html += '    <p class="font-weight-bold mb-1">' + $('<div>').text(item.jenis_pelanggaran).html() + '</p>';
                    if (item.tindakan) html += '    <p class="mb-0 small"><strong>Sanksi:</strong> ' + $('<div>').text(item.tindakan).html() + '</p>';
                    html += '  </div></div>';
                });
                html += '</div>';
                $('#timelineLanggar').html(html);
            },
            error: function() {
                $('#timelineLanggar').html('<div class="text-muted p-3 text-center">Gagal memuat timeline.</div>');
            }
        });
        $('#modalDetailPelanggaran').modal('show');
    });

    $(document).on('click', '.btn-hapus-pelanggaran', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        Swal.fire({
            title: 'Hapus Pelanggaran?',
            text: 'Data pelanggaran untuk ' + nama + ' akan dihapus.',
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
#table-pelanggaran td:last-child { white-space: nowrap; }
</style>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Daftar Pelanggaran Siswa <?= !empty($wali_kelas_name) ? '- Kelas ' . htmlspecialchars($wali_kelas_name) : '' ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <!-- Filter Card -->
            <div class="card mb-3">
                <div class="card-body p-3">
                    <form method="GET" class="row align-items-end">
                        <?php if (isset($_GET['session_type'])): ?><input type="hidden" name="session_type" value="<?= htmlspecialchars($_GET['session_type']) ?>"><?php endif; ?>
                        <?php if ($is_admin_or_kepala): ?>
                        <div class="col-md-4 mb-2">
                            <label class="small font-weight-bold">Kelas</label>
                            <select name="kelas" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Kelas --</option>
                                <?php foreach ($all_classes as $c): ?>
                                    <option value="<?= (int)$c['id_kelas'] ?>" <?= $selected_kelas_id === (int)$c['id_kelas'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nama_kelas']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-4 mb-2">
                            <label class="small font-weight-bold">Kategori Pelanggaran</label>
                            <select name="f_kategori" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Kategori --</option>
                                <?php foreach ($kategori_options as $k): ?>
                                    <option value="<?= $k ?>" <?= $f_kategori === $k ? 'selected' : '' ?>><?= $k ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="small font-weight-bold">Status</label>
                            <select name="f_status" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Status --</option>
                                <?php foreach ($status_options as $st): ?>
                                    <option value="<?= $st ?>" <?= $f_status === $st ? 'selected' : '' ?>><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                    <h4>Catatan Kedisiplinan & Pelanggaran Tata Tertib</h4>
                    <div>
                        <a href="data_pelanggaran.php" class="btn btn-info btn-sm mr-1">
                            <i class="fas fa-database mr-1"></i> Data Pelanggaran
                        </a>
                        <?php
                        $qs_lg = [];
                        if ($selected_kelas_id > 0) { $qs_lg['kelas'] = $selected_kelas_id; }
                        $url_lg_cetak = 'export_pelanggaran_pdf.php?' . http_build_query(array_merge($qs_lg, ['mode' => 'print']));
                        $url_lg_xls = 'export_pelanggaran_excel.php?' . http_build_query($qs_lg);
                        ?>
                        <a href="<?= htmlspecialchars($url_lg_cetak) ?>" target="_blank" class="btn btn-danger btn-sm mr-1" title="Cetak / Simpan PDF">
                            <i class="fas fa-print mr-1"></i> Cetak / PDF
                        </a>
                        <a href="<?= htmlspecialchars($url_lg_xls) ?>" class="btn btn-success btn-sm mr-2" title="Ekspor Excel">
                            <i class="fas fa-file-excel mr-1"></i> Excel
                        </a>
                        <?php if ($can_crud): ?>
                        <button type="button" class="btn btn-primary btn-sm" id="btnTambahPelanggaran" <?= empty($siswa_list) && !$is_admin_or_kepala ? 'disabled' : '' ?>>
                            <i class="fas fa-plus mr-1"></i> Catat Pelanggaran
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered table-sm" id="table-pelanggaran">
                            <thead>
                                <tr>
                                    <th width="4%">No</th>
                                    <th>Tanggal</th>
                                    <th>Nama Siswa</th>
                                    <th>NISN</th>
                                    <th>Jenis Pelanggaran</th>
                                    <th>Kategori</th>
                                    <th width="6%">Poin</th>
                                    <th>Tindakan</th>
                                    <th>Orang Tua</th>
                                    <th>Status</th>
                                    <th style="width:180px;min-width:180px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $i => $r): ?>
                                    <?php
                                    $st_badge = $r['status'] === 'Selesai' ? 'success' : ($r['status'] === 'Ditindaklanjuti' ? 'warning' : 'danger');
                                    $kat_badge = $r['kategori'] === 'Berat' ? 'danger' : ($r['kategori'] === 'Sedang' ? 'warning' : 'info');
                                    $sid = (int)$r['id_siswa'];
                                    $tot = (int)($poin_total_map[$sid] ?? 0);
                                    $sk = function_exists('pelanggaran_sanksi_by_poin') ? pelanggaran_sanksi_by_poin($tot) : ['level' => '-', 'badge' => 'secondary'];
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                                        <td><strong><?= htmlspecialchars($r['nama_siswa']) ?></strong>
                                            <div class="mt-1"><span class="badge badge-<?= $sk['badge'] ?>" title="<?= htmlspecialchars($sk['level']) ?>">Total <?= $tot ?> poin &bull; <?= htmlspecialchars($sk['level']) ?></span></div>
                                        </td>
                                        <td><?= htmlspecialchars($r['nisn'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['jenis_pelanggaran']) ?>
                                            <?php if ((int)($r['n_bina'] ?? 0) > 0): ?>
                                                <div class="mt-1"><span class="badge badge-success">Sudah dibina</span></div>
                                            <?php else: ?>
                                                <div class="mt-1"><span class="badge badge-warning">Belum dibina</span></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><span class="badge badge-<?= $kat_badge ?>"><?= htmlspecialchars($r['kategori']) ?></span></td>
                                        <td class="text-center font-weight-bold text-danger"><?= (int)$r['poin'] ?></td>
                                        <td><?= htmlspecialchars(mb_strimwidth($r['tindakan'], 0, 35, '...')) ?></td>
                                        <td><small><?= htmlspecialchars($r['orang_tua'] ?: '-') ?></small></td>
                                        <td class="text-center"><span class="badge badge-<?= $st_badge ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                        <td class="text-center align-middle">
                                            <div class="aksi-satu-baris">
                                                <button type="button" class="btn btn-info btn-sm btn-detail-pelanggaran" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Detail">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if ($can_crud): ?>
                                                <button type="button" class="btn btn-warning btn-sm btn-edit-pelanggaran" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger btn-sm btn-hapus-pelanggaran" data-id="<?= (int)$r['id'] ?>" data-nama="<?= htmlspecialchars($r['nama_siswa'], ENT_QUOTES) ?>" title="Hapus">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                                <a href="export_pelanggaran_pdf.php?id_siswa=<?= (int)$r['id_siswa'] ?>&mode=print" target="_blank" class="btn btn-danger btn-sm" title="Cetak / Simpan PDF laporan siswa ini">
                                                    <i class="fas fa-print"></i>
                                                </a>
                                                <a href="export_pelanggaran_excel.php?id_siswa=<?= (int)$r['id_siswa'] ?>" class="btn btn-success btn-sm" title="Ekspor Excel siswa ini">
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
<div class="modal fade" id="modalPelanggaran" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formPelanggaran">
                <input type="hidden" name="action" id="formPelanggaranAction" value="tambah">
                <input type="hidden" name="id" id="pelanggaranId" value="">
                <input type="hidden" name="id_kelas" value="<?= (int)$selected_kelas_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalPelanggaranTitle">Catat Pelanggaran Siswa</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <style>
                        #modalPelanggaran textarea { min-height: 110px; line-height: 1.55; resize: vertical; overflow-y: auto; }
                    </style>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Siswa <span class="text-danger">*</span></label>
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
                            <label class="font-weight-bold">Tanggal</label>
                            <input type="date" name="tanggal" id="inp_tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3 form-group">
                            <label class="font-weight-bold">Kategori</label>
                            <select name="kategori" id="inp_kategori" class="form-control">
                                <?php foreach ($kategori_options as $k): ?>
                                    <option value="<?= $k ?>"><?= $k ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Jenis Pelanggaran <span class="text-danger">*</span></label>
                            <select id="sel_langgar_jenis" class="form-control form-control-sm mb-1">
                                <option value="">-- Pilih jenis pelanggaran sesuai kondisi --</option>
                            </select>
                            <textarea name="jenis_pelanggaran" id="inp_jenis" class="form-control" rows="4" required placeholder="Pilih dari dropdown di atas sesuai kondisi, atau ketik manual..."></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Poin Pelanggaran</label>
                            <input type="number" name="poin" id="inp_poin" class="form-control mb-1" value="5" min="0">
                            <label class="font-weight-bold mt-2">Tindakan / Sanksi yang Diberikan</label>
                            <select id="sel_langgar_tindakan" class="form-control form-control-sm mb-1">
                                <option value="">-- Pilih tindakan/sanksi sesuai kondisi --</option>
                            </select>
                            <textarea name="tindakan" id="inp_tindakan" class="form-control" rows="4" placeholder="Pilih dari dropdown di atas sesuai kondisi, atau ketik manual..."></textarea>
                        </div>
                        <div class="col-12 form-group mb-0">
                            <label class="font-weight-bold">Keterangan Orang Tua</label>
                            <input type="text" name="orang_tua" id="inp_ortu" class="form-control" placeholder="Contoh: Surat pemberitahuan terkirim">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan Catatan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Detail + Timeline + Akumulasi Poin -->
<div class="modal fade" id="modalDetailPelanggaran" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle mr-2"></i>Rincian, Timeline & Akumulasi Poin</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <table class="table table-bordered table-sm">
                    <tr><th width="30%">Tanggal</th><td id="det_tanggal"></td></tr>
                    <tr><th>Nama Siswa</th><td id="det_nama" class="font-weight-bold"></td></tr>
                    <tr><th>NISN</th><td id="det_nisn"></td></tr>
                    <tr><th>Kelas</th><td id="det_kelas"></td></tr>
                    <tr><th>Jenis Pelanggaran</th><td id="det_jenis" class="text-danger font-weight-bold"></td></tr>
                    <tr><th>Kategori</th><td id="det_kategori"></td></tr>
                    <tr><th>Poin</th><td id="det_poin" class="font-weight-bold text-danger"></td></tr>
                    <tr><th>Status</th><td id="det_status"></td></tr>
                    <tr><th>Orang Tua</th><td id="det_ortu"></td></tr>
                    <tr><th colspan="2">Tindakan / Sanksi:</th></tr>
                    <tr><td colspan="2" id="det_tindakan" style="white-space: pre-wrap;" class="bg-light p-2 text-dark"></td></tr>
                </table>
                <div id="akumulasiLanggar"></div>
                <div class="card border mb-0">
                    <div class="card-header bg-white py-2 border-bottom">
                        <h6 class="mb-0 text-dark"><i class="fas fa-history mr-1 text-danger"></i> Timeline Pelanggaran + Poin Berjalan</h6>
                    </div>
                    <div class="card-body p-2" id="timelineLanggar" style="max-height: 280px; overflow-y: auto;"></div>
                </div>
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

