<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['wali', 'admin'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
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

// Admin bisa pilih kelas, wali default ke kelasnya
$selected_kelas_id = $wali_kelas_id;
if ($user_level === 'admin' && isset($_GET['kelas'])) {
    $selected_kelas_id = (int)$_GET['kelas'];
}

$message = null;

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $id_siswa = (int)($_POST['id_siswa'] ?? 0);
        $id_kelas = (int)($_POST['id_kelas'] ?? $selected_kelas_id);
        $tanggal = !empty($_POST['tanggal']) ? date('Y-m-d', strtotime($_POST['tanggal'])) : date('Y-m-d');
        $jenis = in_array($_POST['jenis_pembinaan'] ?? '', ['Akademik', 'Kedisiplinan', 'Sikap', 'Kehadiran', 'Sosial', 'Lainnya'], true) ? $_POST['jenis_pembinaan'] : 'Akademik';
        $permasalahan = trim((string)($_POST['permasalahan'] ?? ''));
        $tindakan = trim((string)($_POST['tindakan'] ?? ''));
        $tindak_lanjut = trim((string)($_POST['tindak_lanjut'] ?? ''));
        $status = in_array($_POST['status'] ?? '', ['Berjalan', 'Selesai', 'Dalam Pemantauan'], true) ? $_POST['status'] : 'Berjalan';

        if ($id_siswa <= 0 || $permasalahan === '' || $tindakan === '') {
            $message = ['type' => 'warning', 'text' => 'Pilih Siswa, isi Permasalahan, dan Tindakan yang diambil.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_pembinaan_siswa (
                            id_wali, id_siswa, id_kelas, tanggal, jenis_pembinaan,
                            permasalahan, tindakan, tindak_lanjut, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $guru_id, $id_siswa, $id_kelas, $tanggal, $jenis,
                        $permasalahan, $tindakan, $tindak_lanjut, $status
                    ]);
                    $message = ['type' => 'success', 'text' => 'Data pembinaan siswa berhasil dicatat.'];
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE tb_pembinaan_siswa SET
                            id_siswa = ?, id_kelas = ?, tanggal = ?, jenis_pembinaan = ?,
                            permasalahan = ?, tindakan = ?, tindak_lanjut = ?, status = ?
                        WHERE id = ? " . ($user_level !== 'admin' ? "AND id_wali = $guru_id" : "") . "
                    ");
                    $stmt->execute([
                        $id_siswa, $id_kelas, $tanggal, $jenis,
                        $permasalahan, $tindakan, $tindak_lanjut, $status, $id
                    ]);
                    $message = ['type' => 'success', 'text' => 'Data pembinaan berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM tb_pembinaan_siswa WHERE id = ? " . ($user_level !== 'admin' ? "AND id_wali = $guru_id" : ""))->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Data pembinaan berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
}

// Siswa kelas ini
$siswa_list = [];
if ($selected_kelas_id > 0) {
    $stS = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
    $stS->execute([$selected_kelas_id]);
    $siswa_list = $stS->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch rows pembinaan
$where = ["1=1"];
$params = [];
if ($selected_kelas_id > 0) {
    $where[] = "p.id_kelas = ?";
    $params[] = $selected_kelas_id;
}
if ($user_level !== 'admin') {
    $where[] = "p.id_wali = ?";
    $params[] = $guru_id;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT p.*, s.nama_siswa, s.nisn, k.nama_kelas
    FROM tb_pembinaan_siswa p
    JOIN tb_siswa s ON s.id_siswa = p.id_siswa
    LEFT JOIN tb_kelas k ON k.id_kelas = p.id_kelas
    WHERE $where_sql
    ORDER BY p.tanggal DESC, p.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$jenis_options = ['Akademik', 'Kedisiplinan', 'Sikap', 'Kehadiran', 'Sosial', 'Lainnya'];
$status_options = ['Berjalan', 'Dalam Pemantauan', 'Selesai'];

// Master template pembinaan (sinkron dengan menu Data Pembinaan)
$stMasterBina = $pdo->prepare("
    SELECT id, jenis, permasalahan, tindakan, tindak_lanjut
    FROM tb_master_pembinaan
    WHERE id_guru = ? OR id_guru IS NULL OR id_guru = 0
    ORDER BY jenis ASC, id ASC
");
$stMasterBina->execute([$guru_id]);
$master_pembinaan = $stMasterBina->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Daftar Pembinaan Siswa';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
var masterPembinaan =
JS
. json_encode($master_pembinaan) . ";\n" . <<<'JS'
$(document).ready(function() {
    if ($('#table-pembinaan').length) {
        $('#table-pembinaan').DataTable({
            'order': [[1, 'desc']],
            'columnDefs': [{ 'sortable': false, 'targets': [10] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada data pembinaan',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    function shortBina(s, n) {
        s = (s || '').toString().replace(/\s+/g, ' ').trim();
        n = n || 100;
        return s.length > n ? s.substr(0, n) + '...' : s;
    }

    function autogrowBina($ta) {
        if (!$ta || !$ta.length) return;
        $ta.css('height', 'auto');
        $ta.css('height', ($ta[0].scrollHeight + 4) + 'px');
    }
    $(document).on('input', '#modalPembinaan textarea', function() { autogrowBina($(this)); });
    $('#modalPembinaan').on('shown.bs.modal', function() {
        $('#modalPembinaan textarea').each(function() { autogrowBina($(this)); });
    });

    // Isi dropdown template sesuai jenis terpilih
    function populateBina(jenis) {
        $('#sel_bina_masalah').empty().append('<option value="">-- Pilih template permasalahan --</option>');
        $('#sel_bina_tindakan').empty().append('<option value="">-- Pilih template tindakan --</option>');
        $('#sel_bina_tl').empty().append('<option value="">-- Pilih template rencana tindak lanjut --</option>');
        if (!jenis) return;
        masterPembinaan.filter(function(m) {
            return (m.jenis || '').toLowerCase() === jenis.toLowerCase();
        }).forEach(function(m) {
            var o1 = $('<option>').val(m.permasalahan).text(shortBina(m.permasalahan, 110));
            o1.data('item', m);
            $('#sel_bina_masalah').append(o1);
            var o2 = $('<option>').val(m.tindakan).text(shortBina(m.tindakan, 110));
            o2.data('item', m);
            $('#sel_bina_tindakan').append(o2);
            if (m.tindak_lanjut) {
                var o3 = $('<option>').val(m.tindak_lanjut).text(shortBina(m.tindak_lanjut, 110));
                o3.data('item', m);
                $('#sel_bina_tl').append(o3);
            }
        });
    }

    $('#inp_jenis').on('change', function() {
        populateBina($(this).val());
    });

    // Guru tinggal pilih sesuai kondisi siswa -> isi textarea masing-masing (bisa diubah manual)
    $('#sel_bina_masalah').on('change', function() {
        var v = $(this).val() || '';
        if (v) { $('#inp_masalah').val(v); autogrowBina($('#inp_masalah')); }
    });
    $('#sel_bina_tindakan').on('change', function() {
        var v = $(this).val() || '';
        if (v) { $('#inp_tindakan').val(v); autogrowBina($('#inp_tindakan')); }
    });
    $('#sel_bina_tl').on('change', function() {
        var v = $(this).val() || '';
        if (v) { $('#inp_tindak_lanjut').val(v); autogrowBina($('#inp_tindak_lanjut')); }
    });
    // Pilih satu template lengkap langsung dari dropdown permasalahan
    $(document).on('change', '#sel_bina_masalah_full', function() {
        var item = $('#sel_bina_masalah_full option:selected').data('item');
        if (!item) return;
        $('#inp_masalah').val(item.permasalahan || '');
        $('#inp_tindakan').val(item.tindakan || '');
        $('#inp_tindak_lanjut').val(item.tindak_lanjut || '');
        $('#modalPembinaan textarea').each(function() { autogrowBina($(this)); });
    });

    $('#btnTambahPembinaan').on('click', function() {
        $('#formPembinaanAction').val('tambah');
        $('#pembinaanId').val('');
        $('#modalPembinaanTitle').text('Catat Pembinaan Siswa Baru');
        $('#formPembinaan')[0].reset();
        var curJenis = $('#inp_jenis').val() || '';
        populateBina(curJenis);
        $('#sel_bina_masalah_full').empty().append('<option value="">-- Pilih satu template lengkap (opsional) --</option>');
        if (curJenis) {
            masterPembinaan.filter(function(m) {
                return (m.jenis || '').toLowerCase() === curJenis.toLowerCase();
            }).forEach(function(m) {
                var o = $('<option>').val(m.id).text(shortBina(m.permasalahan, 110));
                o.data('item', m);
                $('#sel_bina_masalah_full').append(o);
            });
        }
        $('#modalPembinaan').modal('show');
        setTimeout(function() {
            $('#modalPembinaan textarea').each(function() { autogrowBina($(this)); });
        }, 120);
    });

    $(document).on('click', '.btn-edit-pembinaan', function() {
        var data = $(this).data('json');
        $('#formPembinaanAction').val('edit');
        $('#pembinaanId').val(data.id);
        $('#modalPembinaanTitle').text('Edit Pembinaan Siswa');
        $('#inp_siswa').val(data.id_siswa);
        $('#inp_tanggal').val(data.tanggal);
        $('#inp_jenis').val(data.jenis_pembinaan);
        populateBina(data.jenis_pembinaan);
        // Samakan dropdown dengan nilai tersimpan bila cocok persis
        $('#sel_bina_masalah option').each(function() {
            if ($(this).val() === (data.permasalahan || '')) $(this).prop('selected', true);
        });
        $('#sel_bina_tindakan option').each(function() {
            if ($(this).val() === (data.tindakan || '')) $(this).prop('selected', true);
        });
        $('#sel_bina_tl option').each(function() {
            if ($(this).val() === (data.tindak_lanjut || '')) $(this).prop('selected', true);
        });
        $('#sel_bina_masalah_full').empty().append('<option value="">-- Pilih satu template lengkap (opsional) --</option>');
        masterPembinaan.filter(function(m) {
            return (m.jenis || '').toLowerCase() === (data.jenis_pembinaan || '').toLowerCase();
        }).forEach(function(m) {
            var o = $('<option>').val(m.id).text(shortBina(m.permasalahan, 110));
            o.data('item', m);
            $('#sel_bina_masalah_full').append(o);
        });
        $('#inp_masalah').val(data.permasalahan);
        $('#inp_tindakan').val(data.tindakan);
        $('#inp_tindak_lanjut').val(data.tindak_lanjut || '');
        $('#inp_status').val(data.status);
        $('#modalPembinaan').modal('show');
        setTimeout(function() {
            $('#modalPembinaan textarea').each(function() { autogrowBina($(this)); });
        }, 120);
    });

    $(document).on('click', '.btn-detail-pembinaan', function() {
        var data = $(this).data('json');
        $('#det_tanggal').text(data.tanggal);
        $('#det_nama').text(data.nama_siswa);
        $('#det_nisn').text(data.nisn || '-');
        $('#det_kelas').text(data.nama_kelas || '-');
        $('#det_jenis').html('<span class="badge badge-info">' + data.jenis_pembinaan + '</span>');
        $('#det_status').text(data.status);
        $('#det_masalah').text(data.permasalahan);
        $('#det_tindakan').text(data.tindakan);
        $('#det_tindak_lanjut').text(data.tindak_lanjut || '-');
        $('#modalDetailPembinaan').modal('show');
    });

    $(document).on('click', '.btn-hapus-pembinaan', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        Swal.fire({
            title: 'Hapus Catatan?',
            text: 'Data pembinaan untuk ' + nama + ' akan dihapus.',
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
            <h1>Daftar Pembinaan Siswa <?= !empty($wali_kelas_name) ? '- Kelas ' . htmlspecialchars($wali_kelas_name) : '' ?></h1>
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
                    <h4>Catatan Pembinaan Wali Kelas</h4>
                    <div>
                        <a href="data_pembinaan.php" class="btn btn-outline-info btn-sm mr-2">
                            <i class="fas fa-database mr-1"></i> Data Pembinaan
                        </a>
                        <?php
                        $qs_bina = [];
                        if ($selected_kelas_id > 0) { $qs_bina['kelas'] = $selected_kelas_id; }
                        $url_bina_cetak = 'export_pembinaan_pdf.php?' . http_build_query(array_merge($qs_bina, ['mode' => 'print']));
                        $url_bina_pdf = 'export_pembinaan_pdf.php?' . http_build_query(array_merge($qs_bina, ['mode' => 'pdf']));
                        $url_bina_xls = 'export_pembinaan_excel.php?' . http_build_query($qs_bina);
                        ?>
                        <a href="<?= htmlspecialchars($url_bina_cetak) ?>" target="_blank" class="btn btn-outline-secondary btn-sm mr-1" title="Cetak (print tab baru)">
                            <i class="fas fa-print mr-1"></i> Cetak
                        </a>
                        <a href="<?= htmlspecialchars($url_bina_pdf) ?>" class="btn btn-outline-danger btn-sm mr-1" title="Unduh file PDF">
                            <i class="fas fa-file-pdf mr-1"></i> PDF
                        </a>
                        <a href="<?= htmlspecialchars($url_bina_xls) ?>" class="btn btn-outline-success btn-sm mr-2" title="Ekspor Excel">
                            <i class="fas fa-file-excel mr-1"></i> Excel
                        </a>
                        <button type="button" class="btn btn-primary" id="btnTambahPembinaan" <?= empty($siswa_list) && $user_level !== 'admin' ? 'disabled' : '' ?>>
                            <i class="fas fa-plus mr-1"></i> Catat Pembinaan
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered table-sm" id="table-pembinaan">
                            <thead>
                                <tr>
                                    <th width="4%">No</th>
                                    <th>Tanggal</th>
                                    <th>Nama Siswa</th>
                                    <th>NIS/NISN</th>
                                    <th>Kelas</th>
                                    <th>Jenis Pembinaan</th>
                                    <th>Permasalahan</th>
                                    <th>Tindakan</th>
                                    <th>Tindak Lanjut</th>
                                    <th>Status</th>
                                    <th width="12%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $i => $r): ?>
                                    <?php
                                    $st_badge = $r['status'] === 'Selesai' ? 'success' : ($r['status'] === 'Dalam Pemantauan' ? 'warning' : 'primary');
                                    $masalah_cut = mb_strlen($r['permasalahan']) > 35 ? mb_substr($r['permasalahan'], 0, 35) . '...' : $r['permasalahan'];
                                    $tindakan_cut = mb_strlen($r['tindakan']) > 35 ? mb_substr($r['tindakan'], 0, 35) . '...' : $r['tindakan'];
                                    $tl_cut = mb_strlen($r['tindak_lanjut'] ?? '') > 35 ? mb_substr($r['tindak_lanjut'], 0, 35) . '...' : ($r['tindak_lanjut'] ?: '-');
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                                        <td><strong><?= htmlspecialchars($r['nama_siswa']) ?></strong></td>
                                        <td><?= htmlspecialchars($r['nisn'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['nama_kelas'] ?? '-') ?></td>
                                        <td><span class="badge badge-info"><?= htmlspecialchars($r['jenis_pembinaan']) ?></span></td>
                                        <td><span title="<?= htmlspecialchars($r['permasalahan']) ?>"><?= htmlspecialchars($masalah_cut) ?></span></td>
                                        <td><span title="<?= htmlspecialchars($r['tindakan']) ?>"><?= htmlspecialchars($tindakan_cut) ?></span></td>
                                        <td><span title="<?= htmlspecialchars($r['tindak_lanjut'] ?? '') ?>"><?= htmlspecialchars($tl_cut) ?></span></td>
                                        <td class="text-center"><span class="badge badge-<?= $st_badge ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-info btn-sm btn-detail-pembinaan" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Detail">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" class="btn btn-warning btn-sm btn-edit-pembinaan" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm btn-hapus-pembinaan" data-id="<?= (int)$r['id'] ?>" data-nama="<?= htmlspecialchars($r['nama_siswa'], ENT_QUOTES) ?>" title="Hapus">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <div class="btn-group btn-group-sm mt-1">
                                                <a href="export_pembinaan_pdf.php?id_siswa=<?= (int)$r['id_siswa'] ?>&mode=print" target="_blank" class="btn btn-outline-secondary" title="Cetak laporan siswa ini">
                                                    <i class="fas fa-print"></i>
                                                </a>
                                                <a href="export_pembinaan_pdf.php?id=<?= (int)$r['id'] ?>&mode=pdf" class="btn btn-outline-danger" title="Unduh file PDF catatan ini">
                                                    <i class="fas fa-file-pdf"></i>
                                                </a>
                                                <a href="export_pembinaan_excel.php?id_siswa=<?= (int)$r['id_siswa'] ?>" class="btn btn-outline-success" title="Ekspor Excel siswa ini">
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
<div class="modal fade" id="modalPembinaan" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formPembinaan">
                <input type="hidden" name="action" id="formPembinaanAction" value="tambah">
                <input type="hidden" name="id" id="pembinaanId" value="">
                <input type="hidden" name="id_kelas" value="<?= (int)$selected_kelas_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalPembinaanTitle">Catat Pembinaan Siswa</h5>
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
                            <label>Jenis Pembinaan</label>
                            <select name="jenis_pembinaan" id="inp_jenis" class="form-control">
                                <?php foreach ($jenis_options as $j): ?>
                                    <option value="<?= $j ?>"><?= $j ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 form-group">
                            <label>Pilih Cepat: Satu Template Lengkap <small class="text-muted">(opsional, isi 3 kolom sekaligus)</small></label>
                            <select id="sel_bina_masalah_full" class="form-control">
                                <option value="">-- Pilih satu template lengkap (opsional) --</option>
                            </select>
                        </div>
                        <div class="col-12 form-group">
                            <label>Permasalahan / Kasus <span class="text-danger">*</span></label>
                            <select id="sel_bina_masalah" class="form-control form-control-sm mb-1">
                                <option value="">-- Pilih permasalahan sesuai kondisi siswa --</option>
                            </select>
                            <textarea name="permasalahan" id="inp_masalah" class="form-control" rows="5" style="min-height:120px;" required placeholder="Pilih dari dropdown di atas sesuai kondisi siswa, atau ketik manual..."></textarea>
                        </div>
                        <div class="col-12 form-group">
                            <label>Tindakan Pembinaan <span class="text-danger">*</span></label>
                            <select id="sel_bina_tindakan" class="form-control form-control-sm mb-1">
                                <option value="">-- Pilih tindakan sesuai kondisi siswa --</option>
                            </select>
                            <textarea name="tindakan" id="inp_tindakan" class="form-control" rows="5" style="min-height:120px;" required placeholder="Pilih dari dropdown di atas sesuai kondisi siswa, atau ketik manual..."></textarea>
                        </div>
                        <div class="col-md-8 form-group">
                            <label>Rencana Tindak Lanjut</label>
                            <select id="sel_bina_tl" class="form-control form-control-sm mb-1">
                                <option value="">-- Pilih rencana tindak lanjut --</option>
                            </select>
                            <textarea name="tindak_lanjut" id="inp_tindak_lanjut" class="form-control" rows="5" style="min-height:120px;" placeholder="Pilih dari dropdown di atas sesuai kondisi siswa, atau ketik manual..."></textarea>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Status</label>
                            <select name="status" id="inp_status" class="form-control">
                                <?php foreach ($status_options as $st): ?>
                                    <option value="<?= $st ?>"><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan Pembinaan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Detail -->
<div class="modal fade" id="modalDetailPembinaan" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle mr-2"></i>Rincian Pembinaan Siswa</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <table class="table table-bordered table-sm">
                    <tr><th width="30%">Tanggal</th><td id="det_tanggal"></td></tr>
                    <tr><th>Nama Siswa</th><td id="det_nama" class="font-weight-bold"></td></tr>
                    <tr><th>NIS/NISN</th><td id="det_nisn"></td></tr>
                    <tr><th>Kelas</th><td id="det_kelas"></td></tr>
                    <tr><th>Jenis Pembinaan</th><td id="det_jenis"></td></tr>
                    <tr><th>Status</th><td id="det_status"></td></tr>
                    <tr><th colspan="2">Permasalahan:</th></tr>
                    <tr><td colspan="2" id="det_masalah" style="white-space: pre-wrap;" class="bg-light p-2"></td></tr>
                    <tr><th colspan="2">Tindakan Pembinaan:</th></tr>
                    <tr><td colspan="2" id="det_tindakan" style="white-space: pre-wrap;" class="bg-light p-2 text-primary"></td></tr>
                    <tr><th colspan="2">Tindak Lanjut:</th></tr>
                    <tr><td colspan="2" id="det_tindak_lanjut" style="white-space: pre-wrap;" class="bg-light p-2 text-success"></td></tr>
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
