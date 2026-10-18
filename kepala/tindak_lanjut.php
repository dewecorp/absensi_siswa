<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$page_title = 'Tindak Lanjut';
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

$pelaksanaan_list = [];
try {
    $pelaksanaan_list = $pdo->query("SELECT p.id_pelaksanaan, p.id_guru, p.nama_guru, p.unit_bagian, p.jenis_supervisi, p.tanggal, p.temuan, p.rekomendasi, p.nilai
        FROM tb_sv_pelaksanaan p 
        LEFT JOIN tb_sv_tindak_lanjut t ON t.id_pelaksanaan = p.id_pelaksanaan
        WHERE t.id_tindak_lanjut IS NULL
        ORDER BY p.tanggal DESC, p.id_pelaksanaan DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $aksi = (string)($_POST['aksi'] ?? '');
    try {
        if ($aksi === 'tambah' || $aksi === 'edit') {
            $id_pelaksanaan = (int)($_POST['id_pelaksanaan'] ?? 0);
            $id_guru = null;
            $nama_guru = '';
            $unit_bagian = '';
            try {
                $st = $pdo->prepare("SELECT id_guru, nama_guru, unit_bagian FROM tb_sv_pelaksanaan WHERE id_pelaksanaan = ? LIMIT 1");
                $st->execute([$id_pelaksanaan]);
                if ($plRow = $st->fetch(PDO::FETCH_ASSOC)) {
                    $id_guru = (int)($plRow['id_guru'] ?: 0) ?: null;
                    $nama_guru = (string)($plRow['nama_guru'] ?? '');
                    $unit_bagian = (string)($plRow['unit_bagian'] ?? '');
                }
            } catch (Throwable $e) {
            }
            if ($aksi === 'edit' && $nama_guru === '' && $unit_bagian === '') {
                try {
                    $eidTmp = (int)($_POST['id_tindak_lanjut'] ?? 0);
                    $stOld = $pdo->prepare("SELECT id_guru, nama_guru, unit_bagian FROM tb_sv_tindak_lanjut WHERE id_tindak_lanjut = ? LIMIT 1");
                    $stOld->execute([$eidTmp]);
                    if ($oldRow = $stOld->fetch(PDO::FETCH_ASSOC)) {
                        if (!$id_guru) {
                            $id_guru = (int)($oldRow['id_guru'] ?: 0) ?: null;
                        }
                        $nama_guru = (string)($oldRow['nama_guru'] ?? '');
                        $unit_bagian = (string)($oldRow['unit_bagian'] ?? '');
                    }
                } catch (Throwable $e) {}
            }

            $bukti = trim((string)($_POST['bukti'] ?? ''));
            $tautan = trim((string)($_POST['tautan_dokumen'] ?? ''));
            if ($tautan !== '' && !preg_match('#^https?://#i', $tautan)) {
                $tautan = 'https://' . ltrim($tautan, '/');
            }
            $buktiFile = null;
            $existingBuktiFile = null;
            $existingTautan = null;
            if ($aksi === 'edit') {
                $eid = (int)($_POST['id_tindak_lanjut'] ?? 0);
                try {
                    $tmp = $pdo->prepare("SELECT bukti_file, tautan_dokumen FROM tb_sv_tindak_lanjut WHERE id_tindak_lanjut = ? LIMIT 1");
                    $tmp->execute([$eid]);
                    if ($rowTmp = $tmp->fetch(PDO::FETCH_ASSOC)) {
                        $existingBuktiFile = $rowTmp['bukti_file'] ? (string)$rowTmp['bukti_file'] : null;
                        $existingTautan = $rowTmp['tautan_dokumen'] ? (string)$rowTmp['tautan_dokumen'] : null;
                    }
                } catch (Throwable $e) {}
                $buktiFile = $existingBuktiFile;
                if ($tautan === '' && $existingTautan) {
                    $tautan = $existingTautan;
                }
            }
            if (!empty($_FILES['bukti_file']['name'])) {
                $up = sv_handle_upload($_FILES['bukti_file'], 'tl_bukti');
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

            $rencanaArr = (array)($_POST['rencana_tindakan_select'] ?? []);
            $rencanaArr = array_map(function($v){ return trim((string)$v); }, $rencanaArr);
            $rencanaArr = array_filter($rencanaArr, function($v){ return $v !== '' && $v !== '__manual__'; });
            $rencanaManual = trim((string)($_POST['rencana_tindakan_manual'] ?? ''));
            if ($rencanaManual !== '') {
                $rencanaArr[] = $rencanaManual;
            }
            $rencanaTindakanVal = implode(", ", array_unique($rencanaArr));

            $data = [
                $id_pelaksanaan ?: null,
                $id_guru,
                $nama_guru,
                $unit_bagian,
                trim((string)($_POST['temuan'] ?? '')),
                trim((string)($_POST['rekomendasi'] ?? '')),
                trim((string)($_POST['bentuk_tindak_lanjut'] ?? 'Pembinaan')),
                $rencanaTindakanVal,
                trim((string)($_POST['penanggung_jawab'] ?? '')),
                trim((string)($_POST['target_selesai'] ?? '')) ?: null,
                trim((string)($_POST['realisasi'] ?? '')) ?: null,
                $bukti,
                $buktiFile,
                $tautan !== '' ? $tautan : null,
                trim((string)($_POST['status'] ?? 'Belum Ditindaklanjuti')),
                trim((string)($_POST['tanggal_selesai'] ?? '')) ?: null,
                trim((string)($_POST['catatan'] ?? '')),
            ];
            if ($aksi === 'tambah') {
                $stmt = $pdo->prepare("INSERT INTO tb_sv_tindak_lanjut
                    (id_pelaksanaan, id_guru, nama_guru, unit_bagian, temuan, rekomendasi, bentuk_tindak_lanjut, rencana_tindakan,
                     penanggung_jawab, target_selesai, realisasi, bukti, bukti_file, tautan_dokumen, status, tanggal_selesai, catatan)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute($data);
                sv_log($pdo, 'Tambah Tindak Lanjut', $data[2] ?: $data[3]);
                sv_flash('success', 'Tindak lanjut berhasil ditambahkan.');
            } else {
                $id = (int)($_POST['id_tindak_lanjut'] ?? 0);
                $stmt = $pdo->prepare("UPDATE tb_sv_tindak_lanjut SET id_pelaksanaan=?, id_guru=?, nama_guru=?, unit_bagian=?, temuan=?, rekomendasi=?,
                    bentuk_tindak_lanjut=?, rencana_tindakan=?, penanggung_jawab=?, target_selesai=?, realisasi=?, bukti=?, bukti_file=?, tautan_dokumen=?, status=?, tanggal_selesai=?, catatan=?
                    WHERE id_tindak_lanjut=?");
                $stmt->execute(array_merge($data, [$id]));
                sv_log($pdo, 'Edit Tindak Lanjut', 'ID ' . $id);
                sv_flash('success', 'Tindak lanjut berhasil diperbarui.');
            }
        } elseif ($aksi === 'hapus') {
            $id = (int)($_POST['id_tindak_lanjut'] ?? 0);
            $pdo->prepare("DELETE FROM tb_sv_monitoring WHERE id_tindak_lanjut = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM tb_sv_tindak_lanjut WHERE id_tindak_lanjut = ?")->execute([$id]);
            sv_log($pdo, 'Hapus Tindak Lanjut', 'ID ' . $id);
            sv_flash('success', 'Tindak lanjut berhasil dihapus.');
        } elseif ($aksi === 'jadwalkan_ulang') {
            $id = (int)($_POST['id_tindak_lanjut'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM tb_sv_tindak_lanjut WHERE id_tindak_lanjut = ? LIMIT 1");
            $stmt->execute([$id]);
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
                    'Dibuat otomatis dari tindak lanjut #' . $id,
                ]);
                $pdo->prepare("UPDATE tb_sv_tindak_lanjut SET status = 'Perlu Supervisi Ulang' WHERE id_tindak_lanjut = ?")->execute([$id]);
                sv_log($pdo, 'Jadwalkan Supervisi Ulang', 'TL ' . $id);
                sv_flash('success', 'Supervisi ulang berhasil dijadwalkan (7 hari dari sekarang).');
            }
        }
    } catch (Throwable $e) {
        sv_flash('danger', 'Gagal memproses data: ' . $e->getMessage());
    }
    redirect('tindak_lanjut.php');
}

$filter_status = trim((string)($_GET['status'] ?? ''));
$where = [];
$params = [];
if ($filter_status !== '') {
    $where[] = 't.status = ?';
    $params[] = $filter_status;
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$rows = [];
try {
    $stmt = $pdo->prepare("SELECT t.*, p.jenis_supervisi, p.nama_guru AS p_nama_guru, p.unit_bagian AS p_unit_bagian,
            p.tanggal AS p_tanggal, p.id_guru AS p_id_guru
        FROM tb_sv_tindak_lanjut t
        LEFT JOIN tb_sv_pelaksanaan p ON p.id_pelaksanaan = t.id_pelaksanaan
        {$whereSql}
        ORDER BY t.target_selesai ASC, t.id_tindak_lanjut DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$rr) {
        if (trim((string)($rr['nama_guru'] ?? '')) === '' && trim((string)($rr['p_nama_guru'] ?? '')) !== '') {
            $rr['nama_guru'] = $rr['p_nama_guru'];
        }
        if (trim((string)($rr['unit_bagian'] ?? '')) === '' && trim((string)($rr['p_unit_bagian'] ?? '')) !== '') {
            $rr['unit_bagian'] = $rr['p_unit_bagian'];
        }
        if (empty($rr['id_guru']) && !empty($rr['p_id_guru'])) {
            $rr['id_guru'] = $rr['p_id_guru'];
        }
        if (empty($rr['jenis_supervisi']) && !empty($rr['p_tanggal'])) {
            $rr['tanggal'] = $rr['p_tanggal'];
        } elseif (!empty($rr['p_tanggal']) && empty($rr['tanggal'])) {
            $rr['tanggal'] = $rr['p_tanggal'];
        }
    }
    unset($rr);
    try {
        $pdo->exec("UPDATE tb_sv_tindak_lanjut t JOIN tb_sv_pelaksanaan p ON p.id_pelaksanaan = t.id_pelaksanaan
            SET t.nama_guru = p.nama_guru, t.unit_bagian = p.unit_bagian, t.id_guru = p.id_guru
            WHERE (t.nama_guru IS NULL OR TRIM(t.nama_guru) = '') AND p.nama_guru IS NOT NULL AND TRIM(p.nama_guru) <> ''");
    } catch (Throwable $e) {}
} catch (Throwable $e) {
}

$js_page = [];
$flash = sv_render_flash_js();
if ($flash !== '') {
    $js_page[] = $flash;
}
$tl_pelaksanaan_js = [];
foreach ($pelaksanaan_list as $pl) {
    $tl_pelaksanaan_js[(int)$pl['id_pelaksanaan']] = [
        'label' => '#' . (int)$pl['id_pelaksanaan'] . ' - ' . ($pl['nama_guru'] ?: ($pl['unit_bagian'] ?: '-')) . ' (' . $pl['jenis_supervisi'] . ', ' . ($pl['tanggal'] ? date('d/m/Y', strtotime($pl['tanggal'])) : '-') . ')',
        'temuan' => (string)($pl['temuan'] ?? ''),
        'rekomendasi' => (string)($pl['rekomendasi'] ?? ''),
    ];
}
$js_page[] = 'var tlPelaksanaan = ' . json_encode($tl_pelaksanaan_js, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) . ';';
$js_page[] = <<<'JS'
$(document).ready(function () {
    document.querySelectorAll('form[method="GET"]').forEach(function(f){f.querySelectorAll('select, input[type="date"]').forEach(function(el){el.addEventListener('change',function(){f.submit();});});});
    var dttabletl=$('#table-tl').DataTable({language:{search:'Cari:',lengthMenu:'Tampilkan _MENU_ data',info:'Menampilkan _START_ sampai _END_ dari _TOTAL_ data',infoEmpty:'Tidak ada data',zeroRecords:'Data tidak ditemukan',paginate:{first:'Awal',last:'Akhir',next:'Berikutnya',previous:'Sebelumnya'}},pageLength:10,order:[[1,'desc']],columnDefs:[],responsive:false});dttabletl.on('order.dt search.dt draw.dt',function(){var info=dttabletl.page.info();dttabletl.column(0,{search:'applied',order:'applied'}).nodes().each(function(cell,i){if(cell) cell.innerHTML=info.page*info.length+i+1;});}).draw();
    var tlSel = document.getElementById('tl_id_pelaksanaan');
    function tlFillOptions(keep, keepData) {
        if (!tlSel) return;
        var cur = keep || tlSel.value;
        tlSel.innerHTML = '<option value="">Pilih Supervisi</option>';
        Object.keys(tlPelaksanaan).forEach(function (id) {
            var o = document.createElement('option');
            o.value = id; o.textContent = tlPelaksanaan[id].label;
            tlSel.appendChild(o);
        });
        if (keepData && keepData.id_pelaksanaan) {
            var kId = String(keepData.id_pelaksanaan);
            if (!tlPelaksanaan[kId]) {
                var oEdit = document.createElement('option');
                oEdit.value = kId;
                var guruLbl = keepData.nama_guru || keepData.p_nama_guru || keepData.unit_bagian || keepData.p_unit_bagian || '';
                if (!guruLbl && (keepData.id_guru || keepData.p_id_guru)) guruLbl = 'Guru #' + (keepData.id_guru || keepData.p_id_guru);
                if (!guruLbl) guruLbl = '-';
                var jenisLbl = keepData.jenis_supervisi || '';
                var tglLbl = keepData.tanggal || keepData.p_tanggal || keepData.target_selesai || '';
                if (tglLbl && tglLbl.length >= 10 && tglLbl[4] === '-') {
                    var tp = tglLbl.substring(0, 10).split('-');
                    tglLbl = tp[2] + '/' + tp[1] + '/' + tp[0];
                }
                oEdit.textContent = '#' + kId + ' - ' + guruLbl + ' (' + (jenisLbl || '-') + (tglLbl ? ', ' + tglLbl : '') + ')';
                tlSel.appendChild(oEdit);
            }
        }
        if (cur) tlSel.value = cur;
    }
    function tlSyncRek() {
        var id = tlSel ? tlSel.value : '';
        if ($('#form-tl [name=aksi]').val() !== 'tambah') return;
        var elT = document.getElementById('tl_temuan');
        if (elT && !elT.value) {
            elT.value = (id && tlPelaksanaan[id]) ? (tlPelaksanaan[id].temuan || '') : '';
        }
        var el = document.getElementById('tl_rekomendasi');
        if (el && !el.value) {
            el.value = (id && tlPelaksanaan[id]) ? (tlPelaksanaan[id].rekomendasi || '') : '';
        }
    }
    function svEnsureRencanaOption(currentVals){
        var s = $('#sel_rencana_tindakan'); if (!s.length) return;
        var vals = (typeof currentVals === 'string') ? currentVals.split(',') : (Array.isArray(currentVals) ? currentVals : []);
        vals = vals.map(function(v){ return String(v).trim(); }).filter(Boolean);
        var unmapped = [];
        s.find('option').prop('selected', false);
        vals.forEach(function(v){
            var exists = s.find('option').filter(function(){ return $(this).val() === v; });
            if (exists.length) {
                exists.prop('selected', true);
            } else {
                unmapped.push(v);
            }
        });
        if (unmapped.length > 0) {
            s.find('option[value="__manual__"]').prop('selected', true);
            $('#inp_rencana_tindakan_manual').val(unmapped.join(', ')).removeClass('d-none');
        } else {
            $('#inp_rencana_tindakan_manual').val('').addClass('d-none');
        }
        if ($.fn.select2) {
            s.trigger('change.select2');
        }
    }

    function svInitRencanaSelect2() {
        if (!$.fn.select2) return;
        var $s = $('#sel_rencana_tindakan');
        if ($s.hasClass('select2-hidden-accessible')) $s.select2('destroy');
        $s.select2({
            placeholder: 'Pilih Rencana Tindakan',
            width: '100%',
            dropdownParent: $('#modal-tl'),
            allowClear: true,
            closeOnSelect: false
        });
    }

    $('#modal-tl').on('shown.bs.modal', function () {
        svInitRencanaSelect2();
    });

    $(document).on('change', '#sel_rencana_tindakan', function () {
        var vals = $(this).val() || [];
        var isManual = Array.isArray(vals) ? vals.indexOf('__manual__') !== -1 : vals === '__manual__';
        if (isManual) {
            $('#inp_rencana_tindakan_manual').removeClass('d-none');
        } else {
            $('#inp_rencana_tindakan_manual').addClass('d-none').val('');
        }
    });
    tlFillOptions();
    $(document).on('change', '#tl_id_pelaksanaan', tlSyncRek);
    $('#btn-tambah').on('click', function () {
        $('#form-tl')[0].reset();
        $('#form-tl [name=aksi]').val('tambah');
        $('#form-tl [name=id_tindak_lanjut]').val('');
        $('#sel_rencana_tindakan').val([]).trigger('change');
        $('#inp_rencana_tindakan_manual').val('').addClass('d-none');
        tlFillOptions('');
        tlSyncRek();
        if (window.GDSRefresh) {
            try {
                GDSRefresh($('#tl_id_pelaksanaan'));
                GDSRefresh($('#form-tl [name="bentuk_tindak_lanjut"]'));
                GDSRefresh($('#form-tl [name="penanggung_jawab"]'));
                GDSRefresh($('#form-tl [name="status"]'));
            } catch(e){}
        }
        $('#sv-bukti-current').hide();
        $('#modal-tl .modal-title').text('Tambah Tindak Lanjut');
        $('#modal-tl').modal('show');
        setTimeout(function() {
            if (window.GDSRefresh) {
                try {
                    GDSRefresh($('#tl_id_pelaksanaan'));
                    GDSRefresh($('#form-tl [name="bentuk_tindak_lanjut"]'));
                    GDSRefresh($('#form-tl [name="penanggung_jawab"]'));
                    GDSRefresh($('#form-tl [name="status"]'));
                } catch(e){}
            }
        }, 120);
    });
    $(document).on('click', '.btn-edit', function () {
        var d = $(this).data('row');
        $('#form-tl')[0].reset();
        $('#form-tl [name=aksi]').val('edit');
        tlFillOptions(d.id_pelaksanaan, d);
        $('#tl_id_pelaksanaan').val(d.id_pelaksanaan);
        Object.keys(d).forEach(function (k) {
            var el = $('#form-tl [name="' + k + '"]');
            if (el.length && el.attr('type') !== 'file') { el.val(d[k]); }
        });
        if (d.rekomendasi) {
            $('#tl_rekomendasi').val(d.rekomendasi);
        }
        if (d.rencana_tindakan) {
            svEnsureRencanaOption(d.rencana_tindakan);
        }
        if (window.GDSRefresh) {
            try {
                GDSRefresh($('#tl_id_pelaksanaan'));
                GDSRefresh($('#form-tl [name="bentuk_tindak_lanjut"]'));
                GDSRefresh($('#form-tl [name="penanggung_jawab"]'));
                GDSRefresh($('#form-tl [name="status"]'));
            } catch(e){}
        }
        if (d.bukti_file) {
            $('#sv-bukti-file').text(d.bukti_file);
            $('#sv-bukti-current').show();
        } else {
            $('#sv-bukti-current').hide();
        }
        $('#modal-tl .modal-title').text('Edit Tindak Lanjut');
        $('#modal-tl').modal('show');
        setTimeout(function() {
            if (window.GDSRefresh) {
                try {
                    GDSRefresh($('#tl_id_pelaksanaan'));
                    GDSRefresh($('#form-tl [name="bentuk_tindak_lanjut"]'));
                    GDSRefresh($('#form-tl [name="penanggung_jawab"]'));
                    GDSRefresh($('#form-tl [name="status"]'));
                } catch(e){}
            }
        }, 120);
    });
    $('#modal-tl').on('hidden.bs.modal', function () { $('#sv-bukti-current').hide(); });
    $(document).on('click', '.btn-hapus', function () {
        var id = $(this).data('id');
        Swal.fire({title:'Konfirmasi Hapus',text:'Tindak lanjut dan monitoringnya akan dihapus.',icon:'warning',showCancelButton:true,confirmButtonColor:'#d33',cancelButtonColor:'#6c757d',confirmButtonText:'Ya, Hapus!',cancelButtonText:'Batal'}).then(function(r){if(r.isConfirmed){var f=document.createElement('form');f.method='POST';f.action='tindak_lanjut.php';var fields={aksi: 'hapus', id_tindak_lanjut: id};Object.keys(fields).forEach(function(k){var i=document.createElement('input');i.type='hidden';i.name=k;i.value=fields[k];f.appendChild(i);});document.body.appendChild(f);f.submit();}})
    });
    $(document).on('click', '.btn-ulang', function () {
        var id = $(this).data('id');
        Swal.fire({title:'Jadwalkan Supervisi Ulang',text:'Buat jadwal supervisi ulang untuk tindak lanjut ini?',icon:'warning',showCancelButton:true,confirmButtonColor:'#d33',cancelButtonColor:'#6c757d',confirmButtonText:'Ya, Hapus!',cancelButtonText:'Batal'}).then(function(r){if(r.isConfirmed){var f=document.createElement('form');f.method='POST';f.action='tindak_lanjut.php';var fields={aksi: 'jadwalkan_ulang', id_tindak_lanjut: id};Object.keys(fields).forEach(function(k){var i=document.createElement('input');i.type='hidden';i.name=k;i.value=fields[k];f.appendChild(i);});document.body.appendChild(f);f.submit();}})
    });
    function svFilterTlCards() {
        var q = $.trim($('#filter-search-tl').val() || '').toLowerCase();
        var st = $('#filter-status-card').val();
        var visible = 0;
        $('.tl-card-col').each(function () {
            var searchData = $(this).data('search') || '';
            var statusData = $(this).data('status') || '';
            var matchSearch = !q || searchData.indexOf(q) !== -1;
            var matchStatus = !st || String(statusData) === String(st);
            if (matchSearch && matchStatus) {
                $(this).removeClass('d-none');
                visible++;
            } else {
                $(this).addClass('d-none');
            }
        });
        $('#empty-search-msg').toggle(visible === 0);
        $('#tl-count-badge').text(visible + ' Tindak Lanjut');
    }
    $('#filter-search-tl').on('input', svFilterTlCards);
    $('#filter-status-card').on('change', svFilterTlCards);

    $('#btn-excel').on('click', function () { var table=document.getElementById('table-tl');if(!table) return;if(typeof XLSX!=='undefined'){var clone=table.cloneNode(true);for(var i=0;i<clone.rows.length;i++){if(clone.rows[i].cells.length>0) clone.rows[i].deleteCell(-1);}var wb=XLSX.utils.table_to_book(clone,{sheet:"Sheet1"});XLSX.writeFile(wb,'tindak_lanjut.xlsx');}else{var clone=table.cloneNode(true);for(var i=0;i<clone.rows.length;i++){if(clone.rows[i].cells.length>0) clone.rows[i].deleteCell(-1);}var html='<table border="1">'+clone.innerHTML+'</table>';var a=document.createElement('a');a.href='data:application/vnd.ms-excel;charset=utf-8,'+encodeURIComponent(html);a.download='tindak_lanjut.xls';a.click();}; });
    $('#btn-pdf').on('click', function () { var q = $('form[method=GET]').serialize(); window.open('cetak_supervisi.php?page=tindak_lanjut&' + q, '_blank'); });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';

$total_tl = count($rows);
$count_selesai_tl = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'Selesai'));
$count_proses_tl = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'Dalam Proses'));
$count_ulang_tl = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'Perlu Supervisi Ulang'));
$persen_selesai_tl = $total_tl > 0 ? round(($count_selesai_tl / $total_tl) * 100) : 0;
?>
<div class="main-content">
    <style>
    .hover-shadow { transition: all 0.2s ease-in-out; }
    .hover-shadow:hover { box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,0.1) !important; transform: translateY(-2px); }
    .transition { transition: all 0.2s ease-in-out; }
    </style>
    <section class="section">
        <div class="section-header">
            <h1>Tindak Lanjut</h1>
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

            <div class="card mb-3">
                <div class="card-body py-3">
                    <form method="GET" class="form-row align-items-end">
                        <div class="form-group col-md-3 mb-2">
                            <label class="small font-weight-bold">Filter Status Server</label>
                            <select class="form-control form-control-sm" name="status" onchange="this.form.submit()">
                                <option value="">Semua Status</option>
                                <?php foreach (sv_status_tindak_lanjut_list() as $s): ?><option value="<?= $s ?>" <?= $s === $filter_status ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-3 mb-2">
                            <label class="small font-weight-bold">Filter Kartu</label>
                            <select class="form-control form-control-sm" id="filter-status-card">
                                <option value="">Semua Kartu</option>
                                <option value="Selesai">Selesai</option>
                                <option value="Dalam Proses">Dalam Proses</option>
                                <option value="Perlu Supervisi Ulang">Perlu Supervisi Ulang</option>
                            </select>
                        </div>
                        <div class="form-group col-md-5 mb-2">
                            <label class="small font-weight-bold">Cari Tindak Lanjut</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="filter-search-tl" placeholder="Cari nama guru, rencana, penanggung jawab, catatan...">
                                <div class="input-group-append">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                </div>
                            </div>
                        </div>
                        <div class="form-group col-md-1 mb-2 d-flex align-items-end">
                            <a href="tindak_lanjut.php" class="btn btn-light btn-sm btn-block" title="Reset Filter"><i class="fas fa-undo"></i></a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Stats Chips -->
            <div class="d-flex flex-wrap align-items-center mb-3" style="gap: 8px;">
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-tasks text-primary mr-1"></i> Total: <strong id="tl-count-badge"><?= (int)$total_tl ?> Tindak Lanjut</strong>
                </span>
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-check-circle text-success mr-1"></i> Selesai: <strong><?= (int)$count_selesai_tl ?></strong> <small class="text-success font-weight-bold">(<?= $persen_selesai_tl ?>%)</small>
                </span>
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-spinner text-warning mr-1"></i> Dalam Proses: <strong><?= (int)$count_proses_tl ?></strong>
                </span>
                <?php if ($count_ulang_tl > 0): ?>
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-redo text-danger mr-1"></i> Perlu Supervisi Ulang: <strong><?= (int)$count_ulang_tl ?></strong>
                </span>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4>Daftar Tindak Lanjut</h4>
                    <div class="card-header-action">
                        <button class="btn btn-success" id="btn-excel" type="button"><i class="fas fa-file-excel"></i> Excel</button>
                        <button class="btn btn-warning" id="btn-pdf" type="button"><i class="fas fa-file-pdf"></i> PDF</button>
                        <?php if ($can_manage): ?>
                        <button class="btn btn-primary" id="btn-tambah" type="button"><i class="fas fa-plus"></i> Tambah</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($rows)): ?>
                        <div class="alert alert-info text-center py-4">
                            <i class="fas fa-info-circle fa-2x mb-2 d-block text-info"></i>
                            Belum ada data tindak lanjut supervisi pada filter ini.
                            <?php if ($can_manage): ?>
                                <br><small class="text-muted">Klik tombol <strong>"Tambah"</strong> untuk membuat rencana tindak lanjut baru.</small>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div id="empty-search-msg" class="alert alert-warning text-center" style="display:none;">
                            <i class="fas fa-search mr-1"></i> Tidak ada data tindak lanjut yang cocok dengan pencarian Anda.
                        </div>
                        <div class="row" id="tl-cards-container">
                            <?php foreach ($rows as $r): ?>
                                <?php
                                $badge = 'secondary';
                                if ($r['status'] === 'Selesai') $badge = 'success';
                                elseif ($r['status'] === 'Dalam Proses') $badge = 'warning';
                                elseif ($r['status'] === 'Perlu Supervisi Ulang') $badge = 'danger';

                                $nama = $r['nama_guru'] ?: ($r['unit_bagian'] ?: '-');
                                $searchBlob = strtolower(implode(' ', [
                                    $nama,
                                    $r['rekomendasi'] ?? '',
                                    $r['bentuk_tindak_lanjut'] ?? '',
                                    $r['rencana_tindakan'] ?? '',
                                    $r['penanggung_jawab'] ?? '',
                                    $r['status'] ?? '',
                                    $r['bukti'] ?? '',
                                    $r['catatan'] ?? ''
                                ]));
                                ?>
                                <div class="col-12 col-md-6 col-lg-4 d-flex align-items-stretch mb-4 tl-card-col"
                                     data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES) ?>"
                                     data-status="<?= htmlspecialchars($r['status'] ?? '', ENT_QUOTES) ?>">
                                    <div class="card w-100 border-0 shadow-sm hover-shadow transition" style="border-radius: 12px; overflow: hidden; background: #fff; border: 1px solid #e2e8f0 !important;">
                                        <div class="card-body p-3 d-flex flex-column justify-content-between">
                                            <div>
                                                <!-- Top Bar -->
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <span class="badge badge-info px-2 py-1 font-weight-bold" style="font-size: 11.5px; border-radius: 6px;">
                                                        <i class="fas fa-hands-helping mr-1"></i> <?= htmlspecialchars($r['bentuk_tindak_lanjut'] ?: 'Tindak Lanjut') ?>
                                                    </span>
                                                    <span class="badge badge-<?= $badge ?> px-2 py-1 font-weight-bold" style="font-size: 11px; border-radius: 6px;">
                                                        <i class="fas <?= $r['status'] === 'Selesai' ? 'fa-check-circle' : ($r['status'] === 'Dalam Proses' ? 'fa-spinner' : 'fa-redo') ?> mr-1"></i> <?= htmlspecialchars($r['status']) ?>
                                                    </span>
                                                </div>

                                                <!-- Nama Target -->
                                                <h6 class="font-weight-bold text-dark mb-1" style="font-size: 15px; line-height: 1.4;">
                                                    <?= htmlspecialchars($nama) ?>
                                                </h6>
                                                <div class="text-muted small mb-2 d-flex flex-wrap align-items-center" style="gap: 6px; font-size: 11.5px;">
                                                    <span><i class="fas fa-user-shield text-muted mr-1"></i> PJ: <strong><?= htmlspecialchars((string)($r['penanggung_jawab'] ?: '-')) ?></strong></span>
                                                    <span>&bull;</span>
                                                    <span><i class="far fa-calendar-check text-muted mr-1"></i> Target: <?= $r['target_selesai'] ? date('d/m/Y', strtotime($r['target_selesai'])) : '-' ?></span>
                                                </div>

                                                <!-- Rekomendasi & Rencana Tindakan Box -->
                                                <div class="p-2 mb-2 rounded" style="background: #f8fafc; font-size: 11.5px; border: 1px dashed #cbd5e1; line-height: 1.4;">
                                                    <?php if (!empty($r['rekomendasi'])): ?>
                                                        <div class="mb-2">
                                                            <strong class="text-warning d-block mb-1"><i class="fas fa-lightbulb mr-1"></i> Rekomendasi:</strong>
                                                            <span class="text-dark"><?= nl2br(htmlspecialchars($r['rekomendasi'])) ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="text-dark" style="font-size: 12px;">
                                                        <strong class="text-primary"><i class="fas fa-clipboard-list mr-1"></i> Rencana Tindakan:</strong>
                                                        <div class="mt-1" style="line-height: 1.35;"><?= nl2br(htmlspecialchars((string)$r['rencana_tindakan'])) ?></div>
                                                    </div>
                                                </div>

                                                <!-- Realisasi & Bukti Box -->
                                                <div class="p-2 mb-2 rounded" style="background: #f8fafc; border: 1px solid #e2e8f0; font-size: 11.5px; line-height: 1.4;">
                                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                                        <span class="text-muted">Realisasi:</span>
                                                        <strong class="text-dark"><?= $r['realisasi'] ? date('d/m/Y', strtotime($r['realisasi'])) : '-' ?></strong>
                                                    </div>
                                                    <?php if (!empty($r['tanggal_selesai'])): ?>
                                                    <div class="d-flex justify-content-between align-items-center mb-1 text-success font-weight-bold">
                                                        <span>Tgl Selesai:</span>
                                                        <span><?= date('d/m/Y', strtotime($r['tanggal_selesai'])) ?></span>
                                                    </div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($r['bukti']) || !empty($r['bukti_file']) || !empty($r['tautan_dokumen'])): ?>
                                                        <div class="pt-1 mt-1 border-top d-flex flex-wrap align-items-center" style="gap: 6px;">
                                                            <span class="text-muted small">Bukti:</span>
                                                            <?php if (!empty($r['bukti_file'])): ?>
                                                                <a href="<?= htmlspecialchars(sv_upload_url($r['bukti_file']), ENT_QUOTES) ?>" target="_blank" class="badge badge-light border text-primary" style="font-size: 10.5px;">
                                                                    <i class="fas fa-file-download mr-1"></i> File
                                                                </a>
                                                            <?php endif; ?>
                                                            <?php if (!empty($r['tautan_dokumen'])): ?>
                                                                <a href="<?= htmlspecialchars($r['tautan_dokumen'], ENT_QUOTES) ?>" target="_blank" class="badge badge-light border text-info" style="font-size: 10.5px;">
                                                                    <i class="fas fa-link mr-1"></i> Tautan
                                                                </a>
                                                            <?php endif; ?>
                                                            <?php if (!empty($r['bukti'])): ?>
                                                                <span class="text-muted" style="word-break: break-word;"><?= nl2br(htmlspecialchars($r['bukti'])) ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>

                                                <!-- Catatan -->
                                                <?php if (!empty($r['catatan'])): ?>
                                                    <div class="p-2 rounded mb-2" style="background: #fffdf5; border: 1px solid #fef08a; font-size: 11px; line-height: 1.35;">
                                                        <strong class="text-muted"><i class="far fa-comment-dots mr-1"></i> Catatan:</strong> <?= htmlspecialchars($r['catatan']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Footer Aksi -->
                                            <?php if ($can_manage): ?>
                                            <div class="pt-2 mt-2 border-top d-flex justify-content-end" style="gap: 6px;">
                                                <button class="btn btn-outline-warning btn-sm btn-edit px-3" type="button"
                                                    data-row='<?= htmlspecialchars(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>' style="border-radius: 6px; font-size: 12px;">
                                                    <i class="fas fa-edit mr-1"></i> Edit
                                                </button>
                                                <button class="btn btn-outline-info btn-sm btn-ulang px-2" type="button" data-id="<?= (int)$r['id_tindak_lanjut'] ?>" title="Jadwalkan Supervisi Ulang" style="border-radius: 6px; font-size: 12px;">
                                                    <i class="fas fa-redo"></i> Ulang
                                                </button>
                                                <button class="btn btn-outline-danger btn-sm btn-hapus px-3" type="button" data-id="<?= (int)$r['id_tindak_lanjut'] ?>" style="border-radius: 6px; font-size: 12px;">
                                                    <i class="fas fa-trash mr-1"></i> Hapus
                                                </button>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Hidden table for Excel export -->
                    <table class="d-none" id="table-tl">
                         <thead>
                             <tr>
                                 <th>No</th>
                                 <th>Guru/Unit</th>
                                 <th>Rekomendasi</th>
                                 <th>Bentuk</th>
                                 <th>Rencana Tindakan</th>
                                 <th>Penanggung Jawab</th>
                                 <th>Target Selesai</th>
                                 <th>Realisasi</th>
                                 <th>Bukti</th>
                                 <th>Status</th>
                                 <th>Tanggal Selesai</th>
                                 <th>Catatan</th>
                             </tr>
                         </thead>
                         <tbody>
                             <?php foreach ($rows as $idx => $r): ?>
                                 <tr>
                                     <td><?= $idx + 1 ?></td>
                                     <td><?= htmlspecialchars($r['nama_guru'] ?: ($r['unit_bagian'] ?: '-')) ?></td>
                                     <td><?= htmlspecialchars((string)$r['rekomendasi']) ?></td>
                                     <td><?= htmlspecialchars((string)$r['bentuk_tindak_lanjut']) ?></td>
                                     <td><?= htmlspecialchars((string)$r['rencana_tindakan']) ?></td>
                                     <td><?= htmlspecialchars((string)$r['penanggung_jawab']) ?></td>
                                     <td><?= $r['target_selesai'] ? date('d/m/Y', strtotime($r['target_selesai'])) : '-' ?></td>
                                     <td><?= $r['realisasi'] ? date('d/m/Y', strtotime($r['realisasi'])) : '-' ?></td>
                                     <td><?= htmlspecialchars((string)$r['bukti']) ?></td>
                                     <td><?= htmlspecialchars($r['status']) ?></td>
                                     <td><?= $r['tanggal_selesai'] ? date('d/m/Y', strtotime($r['tanggal_selesai'])) : '-' ?></td>
                                     <td><?= htmlspecialchars((string)$r['catatan']) ?></td>
                                 </tr>
                             <?php endforeach; ?>
                         </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</div>

<?php if ($can_manage): ?>
<div class="modal fade" id="modal-tl" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" action="tindak_lanjut.php" id="form-tl" enctype="multipart/form-data">
                <input type="hidden" name="aksi" value="tambah">
                <input type="hidden" name="id_tindak_lanjut" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Tindak Lanjut</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group"><label>ID Supervisi (Pelaksanaan)</label><select class="form-control" name="id_pelaksanaan" id="tl_id_pelaksanaan" required></select></div>
                    <div class="form-group"><label>Temuan</label><textarea class="form-control" name="temuan" id="tl_temuan" rows="2"></textarea></div>
                    <div class="form-group"><label>Rekomendasi</label><textarea class="form-control" name="rekomendasi" id="tl_rekomendasi" rows="2"></textarea></div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Bentuk Tindak Lanjut</label>
                            <select class="form-control" name="bentuk_tindak_lanjut">
                                <?php foreach (sv_bentuk_tindak_lanjut_list() as $b): ?><option value="<?= $b ?>"><?= $b ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Status</label>
                            <select class="form-control" name="status">
                                <?php foreach (sv_status_tindak_lanjut_list() as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Rencana Tindakan (Ctrl+Klik untuk pilih beberapa)</label>
                        <select class="form-control" name="rencana_tindakan_select[]" id="sel_rencana_tindakan" multiple size="5">
                            <?php foreach (sv_rencana_tindakan_list($pdo) as $rt): ?>
                                <option value="<?= htmlspecialchars($rt, ENT_QUOTES) ?>"><?= htmlspecialchars($rt) ?></option>
                            <?php endforeach; ?>
                            <option value="__manual__">Lainnya (isi manual)</option>
                        </select>
                        <textarea class="form-control mt-2 d-none" name="rencana_tindakan_manual" id="inp_rencana_tindakan_manual" rows="2" placeholder="Isi rencana tindakan manual..."></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Penanggung Jawab</label>
                            <select class="form-control" name="penanggung_jawab">
                                <option value="">-- Pilih Jabatan --</option>
                                <?php foreach (getJabatanList($pdo) as $j): ?><option value="<?= htmlspecialchars($j['nama_jabatan']) ?>"> <?= htmlspecialchars($j['nama_jabatan']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6"><label>Target Selesai</label><input type="date" class="form-control" name="target_selesai"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6"><label>Realisasi</label><input type="date" class="form-control" name="realisasi"></div>
                        <div class="form-group col-md-6"><label>Tanggal Selesai</label><input type="date" class="form-control" name="tanggal_selesai"></div>
                    </div>
                    <div class="form-group"><label>Bukti (ringkas)</label><input type="text" class="form-control" name="bukti" placeholder="cth: Foto tindak lanjut di kelas 5"></div>
                    <div class="form-row">
                        <div class="form-group col-md-6"><label>Upload Dokumen Bukti</label><input type="file" class="form-control-file" name="bukti_file" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx"></div>
                        <div class="form-group col-md-6"><label>Tautan Dokumen</label><input type="url" class="form-control" name="tautan_dokumen" placeholder="https://drive.google.com/... atau https://..."><small class="text-muted">Isi salah satu atau keduanya.</small></div>
                    </div>
                    <div class="form-group" id="sv-bukti-current" style="display:none;"><small class="text-muted">File saat ini: <span id="sv-bukti-file"></span> <label class="ml-2 mb-0"><input type="checkbox" name="hapus_bukti_file" value="1"> Hapus file</label></small></div>
                    <div class="form-group"><label>Catatan</label><textarea class="form-control" name="catatan" rows="2"></textarea></div>
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
