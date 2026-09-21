<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$page_title = 'Jadwal Supervisi';
$current_page = basename(__FILE__);
$can_manage = sv_is_supervisor($pdo);

$school_profile = getSchoolProfile($pdo);
$periode = sv_periode($pdo);

$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
    'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js',
    'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales-all.min.js',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
];

$guru_list = sv_guru_list($pdo);
$program_list = sv_program_options($pdo);
$instrumen_list = sv_instrumen_options($pdo);
$jabatan_list = getJabatanList($pdo);
$instrumen_json = json_encode($instrumen_list, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$program_json = json_encode($program_list, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$program_fokus_map = [];
try {
    $tmp = $pdo->query("SELECT id_program, fokus_supervisi FROM tb_sv_program")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tmp as $row) { $program_fokus_map[(string)$row['id_program']] = (string)($row['fokus_supervisi'] ?? ''); }
} catch (Throwable $e) {}
$program_fokus_json = json_encode($program_fokus_map, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$program_instrumen_map = [];
try {
    $progRows = $pdo->query("SELECT id_program, kode_program, jenis_supervisi FROM tb_sv_program")->fetchAll(PDO::FETCH_ASSOC);
    $insRows = $pdo->query("SELECT id_instrumen, kode_instrumen, jenis_supervisi FROM tb_sv_instrumen")->fetchAll(PDO::FETCH_ASSOC);
    $insByKode = [];
    foreach ($insRows as $ir) { $ck = strtoupper(trim((string)$ir['kode_instrumen'])); $ck = preg_replace('/^INS-/', '', $ck); if ($ck !== '') $insByKode[$ck] = (string)$ir['id_instrumen']; }
    $insByJenis = []; foreach ($insRows as $ir) { $insByJenis[$ir['jenis_supervisi']][] = $ir; }
    foreach ($insByJenis as $j => &$lst) { usort($lst, function($a,$b){ return strcmp((string)$a['kode_instrumen'], (string)$b['kode_instrumen']); }); } unset($lst);
    $progByJenis = []; foreach ($progRows as $pr) { $progByJenis[$pr['jenis_supervisi']][] = $pr; }
    foreach ($progByJenis as $j => &$lst) { usort($lst, function($a,$b){ return strcmp((string)$a['kode_program'], (string)$b['kode_program']); }); } unset($lst);
    foreach ($progRows as $pr) {
        $k = strtoupper(trim((string)$pr['kode_program']));
        $pid = (string)$pr['id_program'];
        if ($k !== '' && isset($insByKode[$k])) { $program_instrumen_map[$pid] = $insByKode[$k]; continue; }
        $jenis = $pr['jenis_supervisi'];
        $pList = $progByJenis[$jenis] ?? [];
        $iList = $insByJenis[$jenis] ?? [];
        $idx = array_search($pr['id_program'], array_column($pList, 'id_program'));
        if ($idx !== false && isset($iList[$idx])) $program_instrumen_map[$pid] = (string)$iList[$idx]['id_instrumen'];
    }
} catch (Throwable $e) {}
$program_instrumen_json = json_encode($program_instrumen_map, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $aksi = (string)($_POST['aksi'] ?? '');
    try {
        if ($aksi === 'tambah' || $aksi === 'edit') {
            $idGuru = (int)($_POST['id_guru'] ?? 0);
            $namaGuru = '';
            foreach ($guru_list as $g) {
                if ((int)$g['id_guru'] === $idGuru) {
                    $namaGuru = $g['nama_guru'];
                    break;
                }
            }
            $supervisor = trim((string)($_POST['supervisor'] ?? ''));
            if ($supervisor === '') $supervisor = sv_current_user_name($pdo);
            $jenisSip = trim((string)($_POST['jenis_supervisi'] ?? 'Akademik'));
            if (!in_array($jenisSip, sv_jenis_list(), true)) $jenisSip = 'Akademik';
            if (!empty($_POST['id_program'])) {
                try { $stC = $pdo->prepare("SELECT jenis_supervisi FROM tb_sv_program WHERE id_program = ? LIMIT 1"); $stC->execute([(int)$_POST['id_program']]); $pj = trim((string)($stC->fetchColumn() ?: '')); if ($pj !== '' && strcasecmp($pj, $jenisSip) !== 0) throw new RuntimeException('Program tidak sesuai dengan Jenis Supervisi.'); } catch (RuntimeException $e) { throw $e; } catch (Throwable $e) {}
            }
            if (!empty($_POST['id_instrumen'])) {
                try { $stC2 = $pdo->prepare("SELECT jenis_supervisi FROM tb_sv_instrumen WHERE id_instrumen = ? LIMIT 1"); $stC2->execute([(int)$_POST['id_instrumen']]); $ij = trim((string)($stC2->fetchColumn() ?: '')); if ($ij !== '' && strcasecmp($ij, $jenisSip) !== 0) throw new RuntimeException('Instrumen tidak sesuai dengan Jenis Supervisi.'); } catch (RuntimeException $e) { throw $e; } catch (Throwable $e) {}
            }
            $fokusRaw = $_POST['fokus'] ?? '';
            if (is_array($fokusRaw)) {
                $fokusRaw = array_values(array_filter(array_map('trim', $fokusRaw), function ($v) { return $v !== '' && $v !== '__placeholder__'; }));
                $fokus = implode(', ', array_unique($fokusRaw));
            } else {
                $fokus = trim((string)$fokusRaw);
            }
            if ($fokus === '' && !empty($_POST['id_program'])) {
                try { $stF = $pdo->prepare("SELECT fokus_supervisi FROM tb_sv_program WHERE id_program = ? LIMIT 1"); $stF->execute([(int)$_POST['id_program']]); $fokus = trim((string)($stF->fetchColumn() ?: '')); } catch (Throwable $e) {}
            }
            $data = [
                (int)($_POST['id_program'] ?? 0) ?: null,
                (int)($_POST['id_sasaran'] ?? 0) ?: null,
                (int)($_POST['id_instrumen'] ?? 0) ?: null,
                $idGuru ?: null,
                $namaGuru,
                trim((string)($_POST['jenis_supervisi'] ?? 'Akademik')),
                $supervisor,
                trim((string)($_POST['tanggal'] ?? '')) ?: null,
                trim((string)($_POST['jam_mulai'] ?? '')) ?: null,
                trim((string)($_POST['jam_selesai'] ?? '')) ?: null,
                trim((string)($_POST['tempat'] ?? '')),
                $fokus,
                trim((string)($_POST['status'] ?? 'Terjadwal')),
                trim((string)($_POST['keterangan'] ?? '')),
            ];
            if ($aksi === 'tambah') {
                $stmt = $pdo->prepare("INSERT INTO tb_sv_jadwal
                    (id_program, id_sasaran, id_instrumen, id_guru, nama_guru, jenis_supervisi, supervisor, tanggal, jam_mulai, jam_selesai, tempat, fokus, status, keterangan)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute($data);
                sv_log($pdo, 'Tambah Jadwal', $namaGuru);
                sv_flash('success', 'Jadwal supervisi berhasil ditambahkan.');
            } else {
                $id = (int)($_POST['id_jadwal'] ?? 0);
                $stmt = $pdo->prepare("UPDATE tb_sv_jadwal SET id_program=?, id_sasaran=?, id_instrumen=?, id_guru=?, nama_guru=?,
                    jenis_supervisi=?, supervisor=?, tanggal=?, jam_mulai=?, jam_selesai=?, tempat=?, fokus=?, status=?, keterangan=?
                    WHERE id_jadwal=?");
                $stmt->execute(array_merge($data, [$id]));
                sv_log($pdo, 'Edit Jadwal', $namaGuru);
                sv_flash('success', 'Jadwal supervisi berhasil diperbarui.');
            }
        } elseif ($aksi === 'ubah_status') {
            $id = (int)($_POST['id_jadwal'] ?? 0);
            $status = trim((string)($_POST['status'] ?? 'Terjadwal'));
            $pdo->prepare("UPDATE tb_sv_jadwal SET status = ? WHERE id_jadwal = ?")->execute([$status, $id]);
            sv_flash('success', 'Status jadwal diperbarui.');
        } elseif ($aksi === 'hapus') {
            $id = (int)($_POST['id_jadwal'] ?? 0);
            $pdo->prepare("DELETE FROM tb_sv_jadwal WHERE id_jadwal = ?")->execute([$id]);
            sv_log($pdo, 'Hapus Jadwal', 'ID ' . $id);
            sv_flash('success', 'Jadwal supervisi berhasil dihapus.');
        }
    } catch (Throwable $e) {
        sv_flash('danger', 'Gagal memproses data: ' . $e->getMessage());
    }
    redirect('jadwal_supervisi.php');
}

$filter = [
    'tanggal_dari' => trim((string)($_GET['tanggal_dari'] ?? '')),
    'tanggal_sampai' => trim((string)($_GET['tanggal_sampai'] ?? '')),
    'guru' => trim((string)($_GET['guru'] ?? '')),
    'jenis' => trim((string)($_GET['jenis'] ?? '')),
    'semester' => trim((string)($_GET['semester'] ?? '')),
    'tahun_ajaran' => trim((string)($_GET['tahun_ajaran'] ?? '')),
];

$where = [];
$params = [];
if ($filter['tanggal_dari'] !== '') {
    $where[] = 'j.tanggal >= ?';
    $params[] = $filter['tanggal_dari'];
}
if ($filter['tanggal_sampai'] !== '') {
    $where[] = 'j.tanggal <= ?';
    $params[] = $filter['tanggal_sampai'];
}
if ($filter['guru'] !== '') {
    $where[] = 'j.id_guru = ?';
    $params[] = (int)$filter['guru'];
}
if ($filter['jenis'] !== '') {
    $where[] = 'j.jenis_supervisi = ?';
    $params[] = $filter['jenis'];
}
if ($filter['tahun_ajaran'] !== '') {
    $where[] = 'p.tahun_ajaran = ?';
    $params[] = $filter['tahun_ajaran'];
}
if ($filter['semester'] !== '') {
    $where[] = 'p.semester = ?';
    $params[] = $filter['semester'];
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$rows = [];
try {
    $stmt = $pdo->prepare("SELECT j.*, p.nama_program, i.nama_instrumen
        FROM tb_sv_jadwal j
        LEFT JOIN tb_sv_program p ON p.id_program = j.id_program
        LEFT JOIN tb_sv_instrumen i ON i.id_instrumen = j.id_instrumen
        {$whereSql}
        ORDER BY j.tanggal DESC, j.jam_mulai ASC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$calendar_events = [];
foreach ($rows as $r) {
    if (empty($r['tanggal'])) {
        continue;
    }
    $color = '#6777ef';
    if ($r['status'] === 'Terlaksana') {
        $color = '#47c363';
    } elseif ($r['status'] === 'Ditunda') {
        $color = '#ffa426';
    } elseif ($r['status'] === 'Dibatalkan') {
        $color = '#fc544b';
    }
    $calendar_events[] = [
        'title' => ($r['nama_guru'] ?: 'Unit') . ' - ' . $r['jenis_supervisi'],
        'start' => $r['tanggal'] . ($r['jam_mulai'] ? 'T' . $r['jam_mulai'] : ''),
        'color' => $color,
        'url' => 'pelaksanaan_supervisi.php?jenis=' . urlencode($r['jenis_supervisi']) . '&id_jadwal=' . (int)$r['id_jadwal'],
    ];
}

$js_page = [];
$flash = sv_render_flash_js();
if ($flash !== '') {
    $js_page[] = $flash;
}
$js_page[] = 'var svEvents = ' . json_encode($calendar_events) . ';';
$js_page[] = 'var svInstrumenList = ' . $instrumen_json . ';';
$js_page[] = 'var svProgramList = ' . $program_json . ';';
$js_page[] = 'var svProgramFokus = ' . $program_fokus_json . ';';
$js_page[] = 'var svProgramInstrumen = ' . $program_instrumen_json . ';';
$js_page[] = <<<'JS'
function svEnsureOption($sel, val, label) {
    if (!val) return;
    val = String(val);
    if ($sel.find('option').filter(function(){ return String($(this).val()) === val; }).length === 0) {
        $sel.append('<option value="' + $('<div>').text(val).html() + '">' + $('<div>').text(label || val).html() + ' (lama)</option>');
    }
}
function svParseFokusList(s){ if(!s) return []; var parts=String(s).split(','); var out=[],seen={}; parts.forEach(function(p){ p=String(p).trim(); if(p && !seen[p]){ seen[p]=1; out.push(p); }}); return out; }
function svRefreshFokusJadwal(pid, selected){ var list=svParseFokusList(pid ? (svProgramFokus[String(pid)]||'') : ''); var $sel=$('#sv-fokus-jadwal'); if(!$sel.length) return; $sel.empty(); if(!list.length){ $sel.append('<option value="" disabled>Pilih Program dulu</option>'); $sel.trigger('change'); return; } var auto = !selected; list.forEach(function(f){ var sel=''; if(selected){ sel=(selected.indexOf(f)!==-1)?' selected':''; } else { sel=' selected'; } var esc=$('<div>').text(f).html(); $sel.append('<option value="'+esc+'"'+sel+'>'+esc+'</option>'); }); $sel.trigger('change'); }
function svFillFokusJadwal(pid, keepManual){ var $sel=$('#sv-fokus-jadwal'); if(!$sel.length) return; if(keepManual && $sel.find('option:selected').length) return; svRefreshFokusJadwal(pid, null); }
function svInitFokusJadwalSelect2(){ if(!$.fn.select2) return; var $s=$('#sv-fokus-jadwal'); if($s.hasClass('select2-hidden-accessible')) $s.select2('destroy'); $s.select2({ placeholder:'Pilih Fokus', width:'100%', dropdownParent: $('#modal-jadwal'), closeOnSelect:false }); }
function svFilterModalByJenis(jenis, keepVal) {
    var $p = $('#form-jadwal [name=id_program]');
    var $i = $('#form-jadwal [name=id_instrumen]');
    var prevProg = keepVal ? String($p.val()||'') : '';
    var prevInst = keepVal ? String($i.val()||'') : '';
    $p.empty().append('<option value="">- Pilih Program -</option>');
    $i.empty().append('<option value="">- Pilih Instrumen -</option>');
    svProgramList.forEach(function(r){
        if(!jenis || String(r.jenis_supervisi)===String(jenis)){
            $p.append('<option value="'+String(r.id_program)+'">'+$('<div>').text(r.nama_program).html()+' ('+String(r.jenis_supervisi)+')</option>');
        }
    });
    svInstrumenList.forEach(function(r){
        if(!jenis || String(r.jenis_supervisi)===String(jenis)){
            $i.append('<option value="'+String(r.id_instrumen)+'">'+$('<div>').text(r.nama_instrumen).html()+' ('+String(r.jenis_supervisi)+')</option>');
        }
    });
    if(keepVal){
        if(prevProg) { svEnsureOption($p, prevProg, 'Program #'+prevProg); $p.val(prevProg); }
        if(prevInst) { svEnsureOption($i, prevInst, 'Instrumen #'+prevInst); $i.val(prevInst); }
        svFillFokusJadwal($p.val()||prevProg||'', false);
    } else {
        var firstProg2='';
        if($p.find('option').length>1){ $p.prop('selectedIndex', 1); firstProg2=$p.val(); }
        var mapped2 = firstProg2 ? (svProgramInstrumen[String(firstProg2)]||'') : '';
        if(mapped2 && $i.find('option[value="'+mapped2+'"]').length){ $i.val(mapped2); } else if($i.find('option').length>1){ $i.prop('selectedIndex', 1); }
        svFillFokusJadwal(firstProg2||'', false);
    }
}
$(document).ready(function () {
    document.querySelectorAll('form[method="GET"]').forEach(function(f){f.querySelectorAll('select, input[type="date"]').forEach(function(el){el.addEventListener('change',function(){f.submit();});});});
    var dttablejadwal=$('#table-jadwal').DataTable({language:{search:'Cari:',lengthMenu:'Tampilkan _MENU_ data',info:'Menampilkan _START_ sampai _END_ dari _TOTAL_ data',infoEmpty:'Tidak ada data',zeroRecords:'Data tidak ditemukan',paginate:{first:'Awal',last:'Akhir',next:'Berikutnya',previous:'Sebelumnya'}},pageLength:10,order:[],columnDefs:[],responsive:false});dttablejadwal.on('order.dt search.dt draw.dt',function(){var info=dttablejadwal.page.info();dttablejadwal.column(0,{search:'applied',order:'applied'}).nodes().each(function(cell,i){if(cell) cell.innerHTML=info.page*info.length+i+1;});}).draw();
    $('#modal-jadwal').on('shown.bs.modal', function(){ svInitFokusJadwalSelect2(); });
    function svAutoInstrumenByProgram(pid){ var iid = svProgramInstrumen[String(pid)] || ''; if(iid){ var $i=$('#form-jadwal [name=id_instrumen]'); if($i.find('option[value="'+iid+'"]').length) $i.val(iid); else { svEnsureOption($i, iid, 'Instrumen #'+iid); $i.val(iid); } } svFillFokusJadwal(pid || '', false); }
    $('#form-jadwal [name=id_program]').on('change', function(){ svAutoInstrumenByProgram($(this).val()); });
    $('#form-jadwal [name=jenis_supervisi]').on('change', function () {
        svFilterModalByJenis($(this).val(), false);
        svFillFokusJadwal('', false);
    });
    $('#btn-tambah').on('click', function () {
        $('#form-jadwal')[0].reset();
        $('#form-jadwal [name=aksi]').val('tambah');
        $('#form-jadwal [name=id_jadwal]').val('');
        var jenis = $('#form-jadwal [name=jenis_supervisi]').val() || 'Akademik';
        svFilterModalByJenis(jenis, false);
        $('#modal-jadwal .modal-title').text('Tambah Jadwal Supervisi');
        $('#modal-jadwal').modal('show');
    });
    $(document).on('click', '.btn-edit', function () {
        var d = $(this).data('row');
        $('#form-jadwal')[0].reset();
        $('#form-jadwal [name=aksi]').val('edit');
        if (d.supervisor) svEnsureOption($('#form-jadwal [name=supervisor]'), d.supervisor, d.supervisor);
        if (d.id_program) svEnsureOption($('#form-jadwal [name=id_program]'), d.id_program, d.nama_program || ('Program #' + d.id_program));
        if (d.id_instrumen) svEnsureOption($('#form-jadwal [name=id_instrumen]'), d.id_instrumen, d.nama_instrumen || ('Instrumen #' + d.id_instrumen));
        Object.keys(d).forEach(function (k) {
            var el = $('#form-jadwal [name="' + k + '"]');
            if (el.length) { el.val(d[k]); }
        });
        svFilterModalByJenis(d.jenis_supervisi || $('#form-jadwal [name=jenis_supervisi]').val(), true);
        if (d.id_program) $('#form-jadwal [name=id_program]').val(String(d.id_program));
        if (d.id_instrumen) $('#form-jadwal [name=id_instrumen]').val(String(d.id_instrumen));
        if (d.supervisor) $('#form-jadwal [name=supervisor]').val(String(d.supervisor));
        if (d.fokus) {
            var selFokus=svParseFokusList(d.fokus);
            svRefreshFokusJadwal(d.id_program||'', selFokus);
            selFokus.forEach(function(v){ var $s=$('#sv-fokus-jadwal'); if($s.find('option').filter(function(){return $(this).val()===v;}).length===0) $s.append('<option value="'+$('<div>').text(v).html()+'" selected>'+$('<div>').text(v).html()+' (lama)</option>'); });
        } else if (d.id_program) { svFillFokusJadwal(d.id_program, true); }
        $('#modal-jadwal .modal-title').text('Edit Jadwal Supervisi');
        $('#modal-jadwal').modal('show');
    });
    $(document).on('click', '.btn-hapus', function () {
        var id = $(this).data('id');
        Swal.fire({title:'Konfirmasi Hapus',text:'Jadwal supervisi akan dihapus.',icon:'warning',showCancelButton:true,confirmButtonColor:'#d33',cancelButtonColor:'#6c757d',confirmButtonText:'Ya, Hapus!',cancelButtonText:'Batal'}).then(function(r){if(r.isConfirmed){var f=document.createElement('form');f.method='POST';f.action='jadwal_supervisi.php';var fields={aksi: 'hapus', id_jadwal: id};Object.keys(fields).forEach(function(k){var i=document.createElement('input');i.type='hidden';i.name=k;i.value=fields[k];f.appendChild(i);});document.body.appendChild(f);f.submit();}})
    });
    $('#btn-excel').on('click', function () { var table=document.getElementById('table-jadwal');if(!table) return;if(typeof XLSX!=='undefined'){var clone=table.cloneNode(true);for(var i=0;i<clone.rows.length;i++){if(clone.rows[i].cells.length>0) clone.rows[i].deleteCell(-1);}var wb=XLSX.utils.table_to_book(clone,{sheet:"Sheet1"});XLSX.writeFile(wb,'jadwal_supervisi.xlsx');}else{var clone=table.cloneNode(true);for(var i=0;i<clone.rows.length;i++){if(clone.rows[i].cells.length>0) clone.rows[i].deleteCell(-1);}var html='<table border="1">'+clone.innerHTML+'</table>';var a=document.createElement('a');a.href='data:application/vnd.ms-excel;charset=utf-8,'+encodeURIComponent(html);a.download='jadwal_supervisi.xls';a.click();}; });
    $('#btn-pdf').on('click', function () { var q = $('form[method=GET]').serialize(); window.open('cetak_supervisi.php?page=jadwal&' + q, '_blank'); });

    var calEl = document.getElementById('svCalendar');
    if (calEl && typeof FullCalendar !== 'undefined') {
        var calendar = new FullCalendar.Calendar(calEl, {
            initialView: 'dayGridMonth',
            locale: 'id',
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listWeek' },
            events: svEvents,
            eventClick: function (info) {
                if (info.event.url) {
                    info.jsEvent.preventDefault();
                    window.location.href = info.event.url;
                }
            },
            height: 620
        });
        calendar.render();
    }
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Jadwal Supervisi</h1>
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
                <div class="card-body">
                    <form method="GET" class="form-row align-items-end">
                        <div class="form-group col-md-2 mb-2"><label class="small font-weight-bold">Dari Tanggal</label><input type="date" class="form-control" name="tanggal_dari" value="<?= htmlspecialchars($filter['tanggal_dari'], ENT_QUOTES) ?>"></div>
                        <div class="form-group col-md-2 mb-2"><label class="small font-weight-bold">Sampai Tanggal</label><input type="date" class="form-control" name="tanggal_sampai" value="<?= htmlspecialchars($filter['tanggal_sampai'], ENT_QUOTES) ?>"></div>
                        <div class="form-group col-md-2 mb-2">
                            <label class="small font-weight-bold">Guru/PTK</label>
                            <select class="form-control" name="guru">
                                <option value="">Semua</option>
                                <?php foreach ($guru_list as $g): ?>
                                    <option value="<?= (int)$g['id_guru'] ?>" <?= (string)$g['id_guru'] === $filter['guru'] ? 'selected' : '' ?>><?= htmlspecialchars($g['nama_guru']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2 mb-2">
                            <label class="small font-weight-bold">Jenis</label>
                            <select class="form-control" name="jenis">
                                <option value="">Semua</option>
                                <?php foreach (sv_jenis_list() as $j): ?><option value="<?= $j ?>" <?= $j === $filter['jenis'] ? 'selected' : '' ?>><?= $j ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2 mb-2">
                            <label class="small font-weight-bold">Semester</label>
                            <select class="form-control" name="semester">
                                <option value="">Semua</option>
                                <?php foreach (sv_semester_options() as $s): ?><option value="<?= htmlspecialchars($s, ENT_QUOTES) ?>" <?= $s === $filter['semester'] ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2 mb-2">
                            <label class="small font-weight-bold">Tahun Ajaran</label>
                            <select class="form-control" name="tahun_ajaran">
                                <option value="">Semua</option>
                                <?php foreach (sv_tahun_ajaran_options($pdo) as $ta): ?><option value="<?= htmlspecialchars($ta, ENT_QUOTES) ?>" <?= $ta === $filter['tahun_ajaran'] ? 'selected' : '' ?>><?= htmlspecialchars($ta) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-12 mb-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                            <a href="jadwal_supervisi.php" class="btn btn-light">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h4>Tampilan Kalender</h4></div>
                <div class="card-body"><div id="svCalendar"></div></div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4>Daftar Jadwal Supervisi</h4>
                    <div class="card-header-action">
                        <button class="btn btn-success" id="btn-excel" type="button"><i class="fas fa-file-excel"></i> Excel</button>
                        <button class="btn btn-warning" id="btn-pdf" type="button"><i class="fas fa-file-pdf"></i> PDF</button>
                        <?php if ($can_manage): ?>
                        <button class="btn btn-primary" id="btn-tambah" type="button"><i class="fas fa-plus"></i> Tambah</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-jadwal">
                            <thead>
                                <tr>
                                    <th width="5%">No</th>
                                    <th>Program</th>
                                    <th>Guru/PTK</th>
                                    <th>Jenis</th>
                                    <th>Instrumen</th>
                                    <th>Supervisor</th>
                                    <th>Tanggal</th>
                                    <th>Jam Mulai</th>
                                    <th>Jam Selesai</th>
                                    <th>Tempat</th>
                                    <th>Status</th>
                                    <th>Keterangan</th>
                                    <?php if ($can_manage): ?><th width="10%">Aksi</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <?php
                                    $badge = 'info';
                                    if ($r['status'] === 'Terlaksana') $badge = 'success';
                                    elseif ($r['status'] === 'Ditunda') $badge = 'warning';
                                    elseif ($r['status'] === 'Dibatalkan') $badge = 'danger';
                                    ?>
                                    <tr>
                                        <td class="text-center"></td>
                                        <td><?= htmlspecialchars((string)$r['nama_program']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['nama_guru']) ?></td>
                                        <td><span class="badge badge-info"><?= htmlspecialchars($r['jenis_supervisi']) ?></span></td>
                                        <td><?= htmlspecialchars((string)$r['nama_instrumen']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['supervisor']) ?></td>
                                        <td><?= $r['tanggal'] ? date('d/m/Y', strtotime($r['tanggal'])) : '-' ?></td>
                                        <td><?= $r['jam_mulai'] ? substr($r['jam_mulai'], 0, 5) : '-' ?></td>
                                        <td><?= $r['jam_selesai'] ? substr($r['jam_selesai'], 0, 5) : '-' ?></td>
                                        <td><?= htmlspecialchars((string)$r['tempat']) ?></td>
                                        <td><span class="badge badge-<?= $badge ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                        <td><?= htmlspecialchars((string)$r['keterangan']) ?></td>
                                        <?php if ($can_manage): ?>
                                        <td>
                                            <button class="btn btn-warning btn-sm btn-edit" type="button"
                                                data-row='<?= htmlspecialchars(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>'><i class="fas fa-edit"></i></button>
                                            <button class="btn btn-danger btn-sm btn-hapus" type="button" data-id="<?= (int)$r['id_jadwal'] ?>"><i class="fas fa-trash"></i></button>
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
<div class="modal fade" id="modal-jadwal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" action="jadwal_supervisi.php" id="form-jadwal">
                <input type="hidden" name="aksi" value="tambah">
                <input type="hidden" name="id_jadwal" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Jadwal Supervisi</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Jenis Supervisi <span class="text-danger">*</span> <small class="text-muted">— pilih dulu, Program & Instrumen otomatis tersaring</small></label>
                        <select class="form-control" name="jenis_supervisi" required>
                            <?php foreach (sv_jenis_list() as $j): ?><option value="<?= $j ?>"><?= $j ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Program <small class="text-muted">sesuai Jenis</small></label>
                            <select class="form-control" name="id_program">
                                <option value="">- Pilih Program -</option>
                                <?php foreach ($program_list as $p): ?><option value="<?= (int)$p['id_program'] ?>" data-jenis="<?= htmlspecialchars($p['jenis_supervisi'] ?? '', ENT_QUOTES) ?>"><?= htmlspecialchars($p['nama_program']) ?> (<?= htmlspecialchars($p['jenis_supervisi'] ?? '') ?>)</option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Instrumen <small class="text-muted">sesuai Jenis</small></label>
                            <select class="form-control" name="id_instrumen">
                                <option value="">- Pilih Instrumen -</option>
                                <?php foreach ($instrumen_list as $i): ?><option value="<?= (int)$i['id_instrumen'] ?>" data-jenis="<?= htmlspecialchars($i['jenis_supervisi'] ?? '', ENT_QUOTES) ?>"><?= htmlspecialchars($i['nama_instrumen']) ?> (<?= htmlspecialchars($i['jenis_supervisi']) ?>)</option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Guru/PTK</label>
                            <select class="form-control" name="id_guru" required>
                                <option value="">Pilih Guru</option>
                                <?php foreach ($guru_list as $g): ?><option value="<?= (int)$g['id_guru'] ?>"><?= htmlspecialchars($g['nama_guru']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Supervisor</label>
                            <select class="form-control" name="supervisor" required>
                                <option value="">-- Pilih Jabatan --</option>
                                <?php foreach ($jabatan_list as $jb): ?><option value="<?= htmlspecialchars($jb['nama_jabatan'], ENT_QUOTES) ?>"><?= htmlspecialchars($jb['nama_jabatan']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select class="form-control" name="status">
                            <?php foreach (sv_status_jadwal_list() as $st): ?><option value="<?= $st ?>"><?= $st ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4"><label>Tanggal</label><input type="date" class="form-control" name="tanggal" required></div>
                        <div class="form-group col-md-4"><label>Jam Mulai</label><input type="time" class="form-control" name="jam_mulai"></div>
                        <div class="form-group col-md-4"><label>Jam Selesai</label><input type="time" class="form-control" name="jam_selesai"></div>
                    </div>
                    <div class="form-group"><label>Fokus <small class="text-muted">auto dari Program, bisa pilih lebih dari satu</small></label><select class="form-control" name="fokus[]" id="sv-fokus-jadwal" multiple></select></div>
                    <div class="form-group"><label>Tempat</label><input type="text" class="form-control" name="tempat"></div>
                    <div class="form-group"><label>Keterangan</label><textarea class="form-control" name="keterangan" rows="2"></textarea></div>
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
