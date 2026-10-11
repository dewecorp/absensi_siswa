<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$page_title = 'Monitoring Tindak Lanjut';
$current_page = basename(__FILE__);
$can_manage = sv_is_supervisor($pdo);

$school_profile = getSchoolProfile($pdo);
$periode = sv_periode($pdo);

$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
];
$js_libs = [

    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
];

$tl_list = [];
try {
    $tl_list = $pdo->query("SELECT t.*, p.nilai AS nilai_awal FROM tb_sv_tindak_lanjut t
        LEFT JOIN tb_sv_pelaksanaan p ON p.id_pelaksanaan = t.id_pelaksanaan
        ORDER BY t.id_tindak_lanjut DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$tindakan_list = sv_tindakan_monitoring_list($pdo);
$target_list = sv_target_perbaikan_list($pdo);
$hasil_list = sv_hasil_monitoring_list($pdo);
$perubahan_list = sv_perubahan_list($pdo);
$status_list = sv_status_monitoring_list($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $aksi = (string)($_POST['aksi'] ?? '');
    try {
        if ($aksi === 'tambah' || $aksi === 'edit') {
            $id_tl = (int)($_POST['id_tindak_lanjut'] ?? 0);
            $id_guru = null;
            $nama_guru = '';
            $unit_bagian = '';
            try {
                $stTl = $pdo->prepare("SELECT t.id_guru, t.nama_guru, t.unit_bagian, p.id_guru AS p_id_guru, p.nama_guru AS p_nama, p.unit_bagian AS p_unit
                    FROM tb_sv_tindak_lanjut t LEFT JOIN tb_sv_pelaksanaan p ON p.id_pelaksanaan = t.id_pelaksanaan WHERE t.id_tindak_lanjut = ? LIMIT 1");
                $stTl->execute([$id_tl]);
                if ($tlRow = $stTl->fetch(PDO::FETCH_ASSOC)) {
                    $id_guru = (int)($tlRow['id_guru'] ?: ($tlRow['p_id_guru'] ?? 0)) ?: null;
                    $nama_guru = (string)($tlRow['nama_guru'] ?: ($tlRow['p_nama'] ?? ''));
                    $unit_bagian = (string)($tlRow['unit_bagian'] ?: ($tlRow['p_unit'] ?? ''));
                }
            } catch (Throwable $e) {
            }
            if ($aksi === 'edit' && trim($nama_guru . $unit_bagian) === '') {
                try {
                    $eidTmp = (int)($_POST['id_monitoring'] ?? 0);
                    $stOld = $pdo->prepare("SELECT id_guru, nama_guru, unit_bagian FROM tb_sv_monitoring WHERE id_monitoring = ? LIMIT 1");
                    $stOld->execute([$eidTmp]);
                    if ($oldRow = $stOld->fetch(PDO::FETCH_ASSOC)) {
                        if (!$id_guru) {
                            $id_guru = (int)($oldRow['id_guru'] ?: 0) ?: null;
                        }
                        if (trim($nama_guru) === '') $nama_guru = (string)($oldRow['nama_guru'] ?? '');
                        if (trim($unit_bagian) === '') $unit_bagian = (string)($oldRow['unit_bagian'] ?? '');
                    }
                } catch (Throwable $e) {}
            }
            $tindakanArr = (array)($_POST['tindakan_select'] ?? []);
            $tindakanArr = array_map(function ($v) { return trim((string)$v); }, $tindakanArr);
            $tindakanArr = array_filter($tindakanArr, function ($v) { return $v !== '' && $v !== '__manual__'; });
            $tindakanManual = trim((string)($_POST['tindakan_manual'] ?? ''));
            if ($tindakanManual !== '') {
                $tindakanArr[] = $tindakanManual;
            }
            $tindakanVal = implode(', ', array_unique($tindakanArr));
            if ($tindakanVal === '' && $aksi === 'edit') {
                try {
                    $eidT = (int)($_POST['id_monitoring'] ?? 0);
                    $stT = $pdo->prepare("SELECT tindakan FROM tb_sv_monitoring WHERE id_monitoring = ? LIMIT 1");
                    $stT->execute([$eidT]);
                    $tindakanVal = trim((string)($stT->fetchColumn() ?: ''));
                } catch (Throwable $e) {}
            }
            $targetVal = trim((string)($_POST['target_perbaikan'] ?? ''));
            if ($targetVal === '__manual__') {
                $targetVal = trim((string)($_POST['target_perbaikan_manual'] ?? ''));
            }
            $hasilVal = trim((string)($_POST['hasil_monitoring'] ?? ''));
            if ($hasilVal === '__manual__') {
                $hasilVal = trim((string)($_POST['hasil_monitoring_manual'] ?? ''));
            }
            $perubahanVal = trim((string)($_POST['perubahan'] ?? ''));
            if ($perubahanVal === '__manual__') {
                $perubahanVal = trim((string)($_POST['perubahan_manual'] ?? ''));
            }
            $statusVal = trim((string)($_POST['status'] ?? ''));
            if ($statusVal === '__manual__') {
                $statusVal = trim((string)($_POST['status_manual'] ?? ''));
            }
            $bukti = trim((string)($_POST['bukti'] ?? ''));
            $buktiFile = null;
            $existingBuktiFile = null;
            if ($aksi === 'edit') {
                $eid = (int)($_POST['id_monitoring'] ?? 0);
                try {
                    $tmp = $pdo->prepare("SELECT bukti_file FROM tb_sv_monitoring WHERE id_monitoring = ? LIMIT 1");
                    $tmp->execute([$eid]);
                    if ($rowTmp = $tmp->fetch(PDO::FETCH_ASSOC)) {
                        $existingBuktiFile = $rowTmp['bukti_file'] ? (string)$rowTmp['bukti_file'] : null;
                    }
                } catch (Throwable $e) {}
                $buktiFile = $existingBuktiFile;
            }
            if (!empty($_FILES['bukti_file']['name'])) {
                $up = sv_handle_upload($_FILES['bukti_file'], 'mon_bukti');
                if (!$up['ok']) {
                    throw new RuntimeException($up['error']);
                }
                if ($existingBuktiFile) {
                    $oldPath = sv_upload_dir() . '/' . $existingBuktiFile;
                    if (is_file($oldPath)) { @unlink($oldPath); }
                }
                $buktiFile = $up['file'];
                if ($bukti === '') {
                    $bukti = pathinfo((string)$_FILES['bukti_file']['name'], PATHINFO_FILENAME);
                }
            } elseif (isset($_POST['hapus_bukti_file']) && $_POST['hapus_bukti_file'] === '1' && $existingBuktiFile) {
                $oldPath = sv_upload_dir() . '/' . $existingBuktiFile;
                if (is_file($oldPath)) { @unlink($oldPath); }
                $buktiFile = null;
            }
            $data = [
                $id_tl,
                $id_guru,
                $nama_guru,
                $unit_bagian,
                '',
                $tindakanVal,
                $targetVal,
                trim((string)($_POST['tanggal_monitoring'] ?? '')) ?: null,
                (int)($_POST['monitoring_ke'] ?? 1),
                $hasilVal,
                $perubahanVal,
                $statusVal,
                trim((string)($_POST['catatan'] ?? '')),
                $bukti,
                $buktiFile,
            ];
            if ($aksi === 'tambah') {
                $stmt = $pdo->prepare("INSERT INTO tb_sv_monitoring
                    (id_tindak_lanjut, id_guru, nama_guru, unit_bagian, temuan_awal, tindakan, target_perbaikan, tanggal_monitoring,
                     monitoring_ke, hasil_monitoring, perubahan, status, catatan, bukti, bukti_file)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute($data);
                sv_log($pdo, 'Tambah Monitoring', 'TL ' . $id_tl);
                sv_flash('success', 'Monitoring berhasil ditambahkan.');
            } else {
                $id = (int)($_POST['id_monitoring'] ?? 0);
                $stmt = $pdo->prepare("UPDATE tb_sv_monitoring SET id_tindak_lanjut=?, id_guru=?, nama_guru=?, unit_bagian=?, temuan_awal=?, tindakan=?,
                    target_perbaikan=?, tanggal_monitoring=?, monitoring_ke=?, hasil_monitoring=?, perubahan=?, status=?, catatan=?, bukti=?, bukti_file=?
                    WHERE id_monitoring=?");
                $stmt->execute(array_merge($data, [$id]));
                sv_log($pdo, 'Edit Monitoring', 'ID ' . $id);
                sv_flash('success', 'Monitoring berhasil diperbarui.');
            }
        } elseif ($aksi === 'hapus') {
            $id = (int)($_POST['id_monitoring'] ?? 0);
            try {
                $stDel = $pdo->prepare("SELECT bukti_file FROM tb_sv_monitoring WHERE id_monitoring = ? LIMIT 1");
                $stDel->execute([$id]);
                $delFile = $stDel->fetchColumn();
                if ($delFile) {
                    $delPath = sv_upload_dir() . '/' . (string)$delFile;
                    if (is_file($delPath)) { @unlink($delPath); }
                }
            } catch (Throwable $e) {}
            $pdo->prepare("DELETE FROM tb_sv_monitoring WHERE id_monitoring = ?")->execute([$id]);
            sv_log($pdo, 'Hapus Monitoring', 'ID ' . $id);
            sv_flash('success', 'Monitoring berhasil dihapus.');
        } elseif ($aksi === 'supervisi_ulang') {
            $id_tl = (int)($_POST['id_tindak_lanjut'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM tb_sv_tindak_lanjut WHERE id_tindak_lanjut = ? LIMIT 1");
            $stmt->execute([$id_tl]);
            $tl = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($tl) {
                $ins = $pdo->prepare("INSERT INTO tb_sv_jadwal
                    (id_guru, nama_guru, jenis_supervisi, supervisor, tanggal, tempat, fokus, status, keterangan)
                    VALUES (?,?,?,?,?,?,?, 'Terjadwal', ?)");
                $ins->execute([
                    $tl['id_guru'] ?: null,
                    $tl['nama_guru'] ?: ($tl['unit_bagian'] ?: ''),
                    'Akademik',
                    sv_current_user_name($pdo),
                    date('Y-m-d', strtotime('+7 days')),
                    '',
                    'Supervisi ulang: ' . ($tl['temuan'] ?: ''),
                    'Dibuat dari monitoring tindak lanjut #' . $id_tl,
                ]);
                $pdo->prepare("UPDATE tb_sv_tindak_lanjut SET status = 'Perlu Supervisi Ulang' WHERE id_tindak_lanjut = ?")->execute([$id_tl]);
                sv_log($pdo, 'Jadwalkan Supervisi Ulang', 'TL ' . $id_tl);
                sv_flash('success', 'Supervisi ulang berhasil dijadwalkan (7 hari dari sekarang).');
            }
        }
    } catch (Throwable $e) {
        sv_flash('danger', 'Gagal memproses data: ' . $e->getMessage());
    }
    redirect('monitoring_tindak_lanjut.php');
}

$rows = [];
try {
    $rows = $pdo->query("SELECT m.*, t.bentuk_tindak_lanjut, t.status AS status_tl
        FROM tb_sv_monitoring m
        LEFT JOIN tb_sv_tindak_lanjut t ON t.id_tindak_lanjut = m.id_tindak_lanjut
        ORDER BY m.tanggal_monitoring DESC, m.id_monitoring DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$js_page = [];
$flash = sv_render_flash_js();
if ($flash !== '') {
    $js_page[] = $flash;
}
$js_page[] = <<<'JS'
$(document).ready(function () {
    var dttablemonitoring=$('#table-monitoring').DataTable({language:{search:'Cari:',lengthMenu:'Tampilkan _MENU_ data',info:'Menampilkan _START_ sampai _END_ dari _TOTAL_ data',infoEmpty:'Tidak ada data',zeroRecords:'Data tidak ditemukan',paginate:{first:'Awal',last:'Akhir',next:'Berikutnya',previous:'Sebelumnya'}},pageLength:10,order:[[1,'desc']],columnDefs:[],responsive:false});dttablemonitoring.on('order.dt search.dt draw.dt',function(){var info=dttablemonitoring.page.info();dttablemonitoring.column(0,{search:'applied',order:'applied'}).nodes().each(function(cell,i){if(cell) cell.innerHTML=info.page*info.length+i+1;});}).draw();
    function svInitTindakanSelect2() {
        if (!$.fn.select2) return;
        var $s = $('#sel_tindakan');
        if ($s.hasClass('select2-hidden-accessible')) $s.select2('destroy');
        $s.select2({
            placeholder: 'Pilih Tindakan (bisa beberapa)',
            width: '100%',
            dropdownParent: $('#modal-monitoring'),
            allowClear: true,
            closeOnSelect: false
        });
    }
    function svSetMulti($sel, csv, manualInput) {
        var vals = String(csv || '').split(',').map(function (v) { return v.trim(); }).filter(Boolean);
        var unmapped = [];
        $sel.find('option').prop('selected', false);
        vals.forEach(function (v) {
            var ex = $sel.find('option').filter(function () { return $(this).val() === v; });
            if (ex.length) { ex.prop('selected', true); } else { unmapped.push(v); }
        });
        if (unmapped.length > 0) {
            $sel.find('option[value="__manual__"]').prop('selected', true);
            $(manualInput).val(unmapped.join(', ')).removeClass('d-none');
        } else {
            $(manualInput).val('').addClass('d-none');
        }
        $sel.trigger('change');
    }
    function svSetSingle($sel, val, manualInput) {
        val = String(val || '').trim();
        if (!val) { $sel.val(''); $(manualInput).val('').addClass('d-none'); $sel.trigger('change'); return; }
        var ex = $sel.find('option').filter(function () { return $(this).val() === val; });
        if (ex.length) { $sel.val(val); $(manualInput).val('').addClass('d-none'); }
        else { $sel.val('__manual__'); $(manualInput).val(val).removeClass('d-none'); }
        $sel.trigger('change');
    }
    $('#modal-monitoring').on('shown.bs.modal', function () {
        svInitTindakanSelect2();
        if (window.GDSRefresh) {
            try {
                GDSRefresh($('#form-monitoring [name="id_tindak_lanjut"]'));
                GDSRefresh($('#sel_target_perbaikan'));
                GDSRefresh($('#sel_hasil_monitoring'));
                GDSRefresh($('#sel_perubahan'));
                GDSRefresh($('#sel_status'));
            } catch (e) {}
        }
    });
    $('#modal-monitoring').on('hidden.bs.modal', function () { $('#sv-bukti-current').hide(); });
    $('#btn-tambah').on('click', function () {
        $('#form-monitoring')[0].reset();
        $('#form-monitoring [name=aksi]').val('tambah');
        $('#form-monitoring [name=id_monitoring]').val('');
        $('#sel_tindakan').val([]).trigger('change');
        $('#inp_tindakan_manual, #inp_target_manual, #inp_hasil_manual, #inp_perubahan_manual, #inp_status_manual').val('').addClass('d-none');
        $('#sv-bukti-current').hide();
        if (window.GDSRefresh) {
            try {
                GDSRefresh($('#form-monitoring [name="id_tindak_lanjut"]'));
                GDSRefresh($('#sel_target_perbaikan'));
                GDSRefresh($('#sel_hasil_monitoring'));
                GDSRefresh($('#sel_perubahan'));
                GDSRefresh($('#sel_status'));
            } catch (e) {}
        }
        $('#modal-monitoring .modal-title').text('Tambah Monitoring');
        $('#modal-monitoring').modal('show');
    });
    $(document).on('change', '#sel_tindakan', function () {
        var vals = $(this).val() || [];
        var isManual = Array.isArray(vals) ? vals.indexOf('__manual__') !== -1 : vals === '__manual__';
        if (isManual) { $('#inp_tindakan_manual').removeClass('d-none'); }
        else { $('#inp_tindakan_manual').addClass('d-none').val(''); }
    });
    $(document).on('change', '#sel_target_perbaikan', function () {
        if ($(this).val() === '__manual__') { $('#inp_target_manual').removeClass('d-none'); }
        else { $('#inp_target_manual').addClass('d-none').val(''); }
    });
    $(document).on('change', '#sel_hasil_monitoring', function () {
        if ($(this).val() === '__manual__') { $('#inp_hasil_manual').removeClass('d-none'); }
        else { $('#inp_hasil_manual').addClass('d-none').val(''); }
    });
    $(document).on('change', '#sel_perubahan', function () {
        if ($(this).val() === '__manual__') { $('#inp_perubahan_manual').removeClass('d-none'); }
        else { $('#inp_perubahan_manual').addClass('d-none').val(''); }
    });
    $(document).on('change', '#sel_status', function () {
        if ($(this).val() === '__manual__') { $('#inp_status_manual').removeClass('d-none'); }
        else { $('#inp_status_manual').addClass('d-none').val(''); }
    });
    $(document).on('click', '.btn-edit', function () {
        var d = $(this).data('row');
        $('#form-monitoring')[0].reset();
        $('#form-monitoring [name=aksi]').val('edit');
        Object.keys(d).forEach(function (k) {
            if (k === 'tindakan' || k === 'target_perbaikan' || k === 'hasil_monitoring' || k === 'perubahan' || k === 'status') return;
            var el = $('#form-monitoring [name="' + k + '"]');
            if (el.length && el.attr('type') !== 'file') { el.val(d[k]); }
        });
        svSetMulti($('#sel_tindakan'), d.tindakan || '', '#inp_tindakan_manual');
        svSetSingle($('#sel_target_perbaikan'), d.target_perbaikan || '', '#inp_target_manual');
        svSetSingle($('#sel_hasil_monitoring'), d.hasil_monitoring || '', '#inp_hasil_manual');
        svSetSingle($('#sel_perubahan'), d.perubahan || '', '#inp_perubahan_manual');
        svSetSingle($('#sel_status'), d.status || '', '#inp_status_manual');
        if (window.GDSRefresh) {
            try {
                GDSRefresh($('#form-monitoring [name="id_tindak_lanjut"]'));
                GDSRefresh($('#sel_target_perbaikan'));
                GDSRefresh($('#sel_hasil_monitoring'));
                GDSRefresh($('#sel_perubahan'));
                GDSRefresh($('#sel_status'));
            } catch (e) {}
        }
        if (d.bukti_file) {
            $('#sv-bukti-file').text(d.bukti_file);
            $('#sv-bukti-current').show();
        } else {
            $('#sv-bukti-current').hide();
        }
        $('#modal-monitoring .modal-title').text('Edit Monitoring');
        $('#modal-monitoring').modal('show');
    });
    $(document).on('click', '.btn-hapus', function () {
        var id = $(this).data('id');
        Swal.fire({title:'Konfirmasi Hapus',text:'Data monitoring akan dihapus.',icon:'warning',showCancelButton:true,confirmButtonColor:'#d33',cancelButtonColor:'#6c757d',confirmButtonText:'Ya, Hapus!',cancelButtonText:'Batal'}).then(function(r){if(r.isConfirmed){var f=document.createElement('form');f.method='POST';f.action='monitoring_tindak_lanjut.php';var fields={aksi: 'hapus', id_monitoring: id};Object.keys(fields).forEach(function(k){var i=document.createElement('input');i.type='hidden';i.name=k;i.value=fields[k];f.appendChild(i);});document.body.appendChild(f);f.submit();}})
    });
    $(document).on('click', '.btn-ulang', function () {
        var id = $(this).data('id');
        Swal.fire({title:'Jadwalkan Supervisi Ulang',text:'Buat jadwal supervisi ulang untuk tindak lanjut ini?',icon:'warning',showCancelButton:true,confirmButtonColor:'#d33',cancelButtonColor:'#6c757d',confirmButtonText:'Ya, Hapus!',cancelButtonText:'Batal'}).then(function(r){if(r.isConfirmed){var f=document.createElement('form');f.method='POST';f.action='monitoring_tindak_lanjut.php';var fields={aksi: 'supervisi_ulang', id_tindak_lanjut: id};Object.keys(fields).forEach(function(k){var i=document.createElement('input');i.type='hidden';i.name=k;i.value=fields[k];f.appendChild(i);});document.body.appendChild(f);f.submit();}})
    });
    $('#btn-excel').on('click', function () { var table=document.getElementById('table-monitoring');if(!table) return;if(typeof XLSX!=='undefined'){var clone=table.cloneNode(true);for(var i=0;i<clone.rows.length;i++){if(clone.rows[i].cells.length>0) clone.rows[i].deleteCell(-1);}var wb=XLSX.utils.table_to_book(clone,{sheet:"Sheet1"});XLSX.writeFile(wb,'monitoring_tindak_lanjut.xlsx');}else{var clone=table.cloneNode(true);for(var i=0;i<clone.rows.length;i++){if(clone.rows[i].cells.length>0) clone.rows[i].deleteCell(-1);}var html='<table border="1">'+clone.innerHTML+'</table>';var a=document.createElement('a');a.href='data:application/vnd.ms-excel;charset=utf-8,'+encodeURIComponent(html);a.download='monitoring_tindak_lanjut.xls';a.click();}; });
    $('#btn-pdf').on('click', function () { window.open('cetak_supervisi.php?page=monitoring', '_blank'); });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Monitoring Tindak Lanjut</h1>
            <?php echo render_breadcrumb(); ?>
        </div>
        <div class="section-body">
            <input type="hidden" id="svSchoolName" value="<?= htmlspecialchars($school_profile['nama_madrasah'] ?? 'MADRASAH', ENT_QUOTES) ?>">
            <input type="hidden" id="svSchoolLogo" value="<?= !empty($school_profile['logo']) ? '../assets/img/' . htmlspecialchars($school_profile['logo'], ENT_QUOTES) : '' ?>">
            <input type="hidden" id="svAcademicYear" value="<?= htmlspecialchars($periode['tahun_ajaran'], ENT_QUOTES) ?>">
            <input type="hidden" id="svSemester" value="<?= htmlspecialchars($periode['semester'], ENT_QUOTES) ?>">
            <input type="hidden" id="svHeadName" value="<?= htmlspecialchars($school_profile['nama_kepala'] ?? '-', ENT_QUOTES) ?>">
            <input type="hidden" id="svHeadNip" value="<?= htmlspecialchars($school_profile['nip_kepala'] ?? '-', ENT_QUOTES) ?>">
            <input type="hidden" id="svPrintPlace" value="<?= htmlspecialchars($school_profile['tempat_jadwal'] ?? 'Padang', ENT_QUOTES) ?>">
            <input type="hidden" id="svPrintDate" value="<?= date('d F Y') ?>">

            <div class="card">
                <div class="card-header">
                    <h4>Daftar Monitoring</h4>
                    <div class="card-header-action">
                        <button class="btn btn-success" id="btn-excel" type="button"><i class="fas fa-file-excel"></i> Excel</button>
                        <button class="btn btn-warning" id="btn-pdf" type="button"><i class="fas fa-file-pdf"></i> PDF</button>
                        <a href="data_monitoring.php" class="btn btn-info"><i class="fas fa-database"></i> Data Monitoring</a>
                        <?php if ($can_manage): ?>
                        <button class="btn btn-primary" id="btn-tambah" type="button"><i class="fas fa-plus"></i> Tambah</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-monitoring">
                            <thead>
                                <tr>
                                    <th width="5%">No</th>
                                    <th>Tindak Lanjut</th>
                                    <th>Guru/Unit</th>
                                    <th>Tindakan</th>
                                    <th>Target Perbaikan</th>
                                    <th>Tanggal</th>
                                    <th>Ke</th>
                                    <th>Hasil Monitoring</th>
                                    <th>Perubahan</th>
                                    <th>Status</th>
                                    <th>Catatan</th>
                                    <th>Bukti</th>
                                    <?php if ($can_manage): ?><th width="12%">Aksi</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="text-center"></td>
                                        <td>#<?= (int)$r['id_tindak_lanjut'] ?> - <?= htmlspecialchars((string)$r['bentuk_tindak_lanjut']) ?></td>
                                        <td><?= htmlspecialchars($r['nama_guru'] ?: ($r['unit_bagian'] ?: '-')) ?></td>
                                        <td><?= htmlspecialchars((string)$r['tindakan']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['target_perbaikan']) ?></td>
                                        <td><?= $r['tanggal_monitoring'] ? date('d/m/Y', strtotime($r['tanggal_monitoring'])) : '-' ?></td>
                                        <td><?= (int)$r['monitoring_ke'] ?></td>
                                        <td><?= htmlspecialchars((string)$r['hasil_monitoring']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['perubahan']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['status']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['catatan']) ?></td>
                                        <td>
                                            <?php if (!empty($r['bukti_file'])): ?><a href="<?= htmlspecialchars(sv_upload_url($r['bukti_file']), ENT_QUOTES) ?>" target="_blank" title="<?= htmlspecialchars(basename($r['bukti_file'])) ?>"><i class="fas fa-file-alt fa-lg"></i></a><br><?php endif; ?>
                                            <?= htmlspecialchars((string)$r['bukti']) ?>
                                        </td>
                                        <?php if ($can_manage): ?>
                                        <td style="white-space:nowrap">
                                            <div class="d-inline-flex align-items-center">
                                            <button class="btn btn-warning btn-sm btn-edit mr-1" type="button" data-row='<?= htmlspecialchars(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>'><i class="fas fa-edit"></i></button>
                                            <button class="btn btn-info btn-sm btn-ulang mr-1" type="button" data-id="<?= (int)$r['id_tindak_lanjut'] ?>" title="Jadwalkan Supervisi Ulang"><i class="fas fa-redo"></i></button>
                                            <button class="btn btn-danger btn-sm btn-hapus" type="button" data-id="<?= (int)$r['id_monitoring'] ?>"><i class="fas fa-trash"></i></button>
                                            </div>
                                        </td>
                                        <?php endif; ?>
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

<?php if ($can_manage): ?>
<div class="modal fade" id="modal-monitoring" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" action="monitoring_tindak_lanjut.php" id="form-monitoring" enctype="multipart/form-data">
                <input type="hidden" name="aksi" value="tambah">
                <input type="hidden" name="id_monitoring" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Monitoring</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Tindak Lanjut</label>
                        <select class="form-control" name="id_tindak_lanjut" required>
                            <option value="">Pilih Tindak Lanjut</option>
                            <?php foreach ($tl_list as $tl): ?>
                                <option value="<?= (int)$tl['id_tindak_lanjut'] ?>">
                                    #<?= (int)$tl['id_tindak_lanjut'] ?> - <?= htmlspecialchars($tl['nama_guru'] ?: ($tl['unit_bagian'] ?: '-')) ?> (<?= htmlspecialchars((string)$tl['bentuk_tindak_lanjut']) ?>, <?= htmlspecialchars($tl['status']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Tindakan (bisa pilih beberapa)</label>
                        <select class="form-control" id="sel_tindakan" name="tindakan_select[]" multiple size="5">
                            <?php foreach ($tindakan_list as $t): ?>
                                <option value="<?= htmlspecialchars($t, ENT_QUOTES) ?>"><?= htmlspecialchars($t) ?></option>
                            <?php endforeach; ?>
                            <option value="__manual__">Lainnya (isi manual)</option>
                        </select>
                        <textarea class="form-control mt-2 d-none" name="tindakan_manual" id="inp_tindakan_manual" rows="2" placeholder="Isi tindakan manual..."></textarea>
                        <small class="text-muted">Kelola pilihan di <a href="data_monitoring.php?kategori=tindakan_monitoring" target="_blank">Data Monitoring</a>.</small>
                    </div>
                    <div class="form-group">
                        <label>Target Perbaikan</label>
                        <select class="form-control" id="sel_target_perbaikan" name="target_perbaikan">
                            <option value="">Pilih Target Perbaikan</option>
                            <?php foreach ($target_list as $t): ?>
                                <option value="<?= htmlspecialchars($t, ENT_QUOTES) ?>"><?= htmlspecialchars($t) ?></option>
                            <?php endforeach; ?>
                            <option value="__manual__">Lainnya (isi manual)</option>
                        </select>
                        <textarea class="form-control mt-2 d-none" name="target_perbaikan_manual" id="inp_target_manual" rows="2" placeholder="Isi target perbaikan manual..."></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4"><label>Tanggal Monitoring</label><input type="date" class="form-control" name="tanggal_monitoring"></div>
                        <div class="form-group col-md-4"><label>Monitoring Ke</label><input type="number" min="1" class="form-control" name="monitoring_ke" value="1"></div>
                        <div class="form-group col-md-4">
                            <label>Status</label>
                            <select class="form-control" id="sel_status" name="status">
                                <option value="">Pilih Status</option>
                                <?php foreach ($status_list as $t): ?>
                                    <option value="<?= htmlspecialchars($t, ENT_QUOTES) ?>"><?= htmlspecialchars($t) ?></option>
                                <?php endforeach; ?>
                                <option value="__manual__">Lainnya (isi manual)</option>
                            </select>
                            <textarea class="form-control mt-2 d-none" name="status_manual" id="inp_status_manual" rows="2" placeholder="Isi status manual..."></textarea>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Hasil Monitoring</label>
                        <select class="form-control" id="sel_hasil_monitoring" name="hasil_monitoring">
                            <option value="">Pilih Hasil Monitoring</option>
                            <?php foreach ($hasil_list as $t): ?>
                                <option value="<?= htmlspecialchars($t, ENT_QUOTES) ?>"><?= htmlspecialchars($t) ?></option>
                            <?php endforeach; ?>
                            <option value="__manual__">Lainnya (isi manual)</option>
                        </select>
                        <textarea class="form-control mt-2 d-none" name="hasil_monitoring_manual" id="inp_hasil_manual" rows="2" placeholder="Isi hasil monitoring manual..."></textarea>
                    </div>
                    <div class="form-group">
                        <label>Perubahan</label>
                        <select class="form-control" id="sel_perubahan" name="perubahan">
                            <option value="">Pilih Perubahan</option>
                            <?php foreach ($perubahan_list as $t): ?>
                                <option value="<?= htmlspecialchars($t, ENT_QUOTES) ?>"><?= htmlspecialchars($t) ?></option>
                            <?php endforeach; ?>
                            <option value="__manual__">Lainnya (isi manual)</option>
                        </select>
                        <textarea class="form-control mt-2 d-none" name="perubahan_manual" id="inp_perubahan_manual" rows="2" placeholder="Isi perubahan manual..."></textarea>
                    </div>
                    <div class="form-group"><label>Catatan</label><textarea class="form-control" name="catatan" rows="2"></textarea></div>
                    <div class="form-group"><label>Bukti (ringkas)</label><input type="text" class="form-control" name="bukti" placeholder="cth: Foto observasi kelas / dokumen bukti"></div>
                    <div class="form-group"><label>Upload Dokumen Bukti</label><input type="file" class="form-control-file" name="bukti_file" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx"><small class="text-muted">Maks 5MB: pdf, jpg, jpeg, png, gif, doc, docx, xls, xlsx.</small></div>
                    <div class="form-group" id="sv-bukti-current" style="display:none;"><small class="text-muted">File saat ini: <span id="sv-bukti-file"></span> <label class="ml-2 mb-0"><input type="checkbox" name="hapus_bukti_file" value="1"> Hapus file</label></small></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php include '../templates/footer.php'; ?>
