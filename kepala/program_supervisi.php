<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$page_title = 'Program Supervisi';
$current_page = basename(__FILE__);
$can_manage = sv_is_supervisor($pdo);

$school_profile = getSchoolProfile($pdo);
$periode = sv_periode($pdo);

$css_libs = ['https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'];
$js_libs = [

    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
];

$templates = sv_program_templates();

// Seed otomatis saat tabel kosong (idempoten, tidak menimpa data benar).
try {
    $jumlahProgram = (int)$pdo->query("SELECT COUNT(*) FROM tb_sv_program")->fetchColumn();
    if ($jumlahProgram === 0) {
        sv_seed_program_templates($pdo, $periode['tahun_ajaran'], $periode['semester']);
    }
} catch (Throwable $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $aksi = (string)($_POST['aksi'] ?? '');

    try {
        if ($aksi === 'tambah' || $aksi === 'edit') {
            $fokusArr = $_POST['fokus'] ?? [];
            $fokusArr = is_array($fokusArr) ? array_values(array_filter(array_map('trim', $fokusArr), function ($v) {
                return $v !== '';
            })) : [];
            $fokusText = implode(', ', array_unique($fokusArr));

            $indikatorMap = [];
            foreach ((array)($_POST['indikator'] ?? []) as $pair) {
                $parts = explode("\x1F", (string)$pair, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                $fk = trim($parts[0]);
                $ind = trim($parts[1]);
                if ($fk === '' || $ind === '') {
                    continue;
                }
                if (!isset($indikatorMap[$fk])) {
                    $indikatorMap[$fk] = [];
                }
                $indikatorMap[$fk][] = $ind;
            }
            $indikatorText = sv_build_indikator_text($indikatorMap);

            $data = [
                trim((string)($_POST['kode_program'] ?? '')),
                trim((string)($_POST['tahun_ajaran'] ?? $periode['tahun_ajaran'])),
                trim((string)($_POST['semester'] ?? $periode['semester'])),
                trim((string)($_POST['jenis_supervisi'] ?? 'Akademik')),
                trim((string)($_POST['nama_program'] ?? '')),
                trim((string)($_POST['tujuan'] ?? '')),
                trim((string)($_POST['sasaran'] ?? '')),
                $fokusText,
                trim((string)($_POST['target'] ?? '')),
                $indikatorText,
                trim((string)($_POST['waktu_pelaksanaan'] ?? '')),
                trim((string)($_POST['tanggal_mulai'] ?? '')) ?: null,
                trim((string)($_POST['tanggal_selesai'] ?? '')) ?: null,
                trim((string)($_POST['penanggung_jawab'] ?? '')),
                trim((string)($_POST['status'] ?? 'Aktif')),
                trim((string)($_POST['keterangan'] ?? '')),
            ];

            $tm = $data[11];
            $ts = $data[12];
            if ($tm !== null && $ts !== null && $ts !== '' && $tm !== '' && $ts < $tm) {
                sv_flash('warning', 'Waktu selesai tidak boleh sebelum waktu mulai.');
            } elseif ($data[4] === '') {
                sv_flash('warning', 'Nama program wajib diisi.');
            } elseif ($aksi === 'tambah') {
                $stmt = $pdo->prepare("INSERT INTO tb_sv_program
                    (kode_program, tahun_ajaran, semester, jenis_supervisi, nama_program, tujuan, sasaran, fokus_supervisi,
                     target, indikator_keberhasilan, waktu_pelaksanaan, tanggal_mulai, tanggal_selesai, penanggung_jawab, status, keterangan, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute(array_merge($data, [sv_current_user_name($pdo)]));
                sv_log($pdo, 'Tambah Program', $data[4]);
                sv_flash('success', 'Program supervisi berhasil ditambahkan.');
            } else {
                $id = (int)($_POST['id_program'] ?? 0);
                $stmt = $pdo->prepare("UPDATE tb_sv_program SET kode_program=?, tahun_ajaran=?, semester=?, jenis_supervisi=?, nama_program=?,
                    tujuan=?, sasaran=?, fokus_supervisi=?, target=?, indikator_keberhasilan=?, waktu_pelaksanaan=?, tanggal_mulai=?, tanggal_selesai=?,
                    penanggung_jawab=?, status=?, keterangan=? WHERE id_program=?");
                $stmt->execute(array_merge($data, [$id]));
                sv_log($pdo, 'Edit Program', $data[4]);
                sv_flash('success', 'Program supervisi berhasil diperbarui.');
            }
        } elseif ($aksi === 'hapus') {
            $id = (int)($_POST['id_program'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM tb_sv_program WHERE id_program = ?");
            $stmt->execute([$id]);
            sv_log($pdo, 'Hapus Program', 'ID ' . $id);
            sv_flash('success', 'Program supervisi berhasil dihapus.');
        } elseif ($aksi === 'seed') {
            $hasil = sv_seed_program_templates($pdo, $periode['tahun_ajaran'], $periode['semester']);
            sv_log($pdo, 'Muat Template Program', 'insert=' . $hasil['inserted'] . ', update=' . $hasil['updated']);
            sv_flash('success', 'Template dimuat: ' . $hasil['inserted'] . ' program baru, ' . $hasil['updated'] . ' program dilengkapi, ' . $hasil['skipped'] . ' sudah lengkap.');
        }
    } catch (Throwable $e) {
        sv_flash('danger', 'Gagal memproses data: ' . $e->getMessage());
    }

    redirect('program_supervisi.php');
}

$filter_ta = trim((string)($_GET['tahun_ajaran'] ?? $periode['tahun_ajaran']));
try {
    $stmt = $pdo->prepare("SELECT * FROM tb_sv_program WHERE tahun_ajaran = ? ORDER BY jenis_supervisi ASC, kode_program ASC, nama_program ASC");
    $stmt->execute([$filter_ta]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rows = [];
    try {
        $rows = $pdo->query("SELECT * FROM tb_sv_program ORDER BY jenis_supervisi ASC, kode_program ASC, nama_program ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ex) {}
}
foreach ($rows as &$row) {
    $row['_fokus_arr'] = sv_parse_fokus($row['fokus_supervisi'] ?? '');
    $row['_indikator_map'] = sv_parse_indikator_text($row['indikator_keberhasilan'] ?? '');
}
unset($row);

$js_page = [];
$flash = sv_render_flash_js();
if ($flash !== '') {
    $js_page[] = $flash;
}
$js_page[] = 'var svProgramTemplates = ' . json_encode($templates) . ';';
$js_page[] = 'var svProgramData = ' . json_encode($rows) . ';';
$js_page[] = <<<'JS'
function svTemplateFokusSet(jenis) {
    var set = [];
    var seen = {};
    (svProgramTemplates[jenis] || []).forEach(function (t) {
        Object.keys(t.fokus || {}).forEach(function (f) {
            if (!seen[f]) { seen[f] = true; set.push(f); }
        });
    });
    return set;
}

function svTemplateIndikator(jenis, fokus) {
    var out = [];
    (svProgramTemplates[jenis] || []).forEach(function (t) {
        if (t.fokus && t.fokus[fokus]) {
            t.fokus[fokus].forEach(function (i) { if (out.indexOf(i) === -1) { out.push(i); } });
        }
    });
    return out;
}

function svRenderFokus(jenis, selectedFokus) {
    selectedFokus = selectedFokus || [];
    var set = svTemplateFokusSet(jenis);
    selectedFokus.forEach(function (f) { if (set.indexOf(f) === -1) { set.push(f); } });
    var html = '';
    set.forEach(function (f) {
        var checked = selectedFokus.indexOf(f) !== -1 ? ' checked' : '';
        html += '<div class="custom-control custom-checkbox mr-3 mb-1 d-inline-block">' +
            '<input type="checkbox" class="custom-control-input sv-fokus" id="fokus-' + svSlug(f) + '" name="fokus[]" value="' + $('<div>').text(f).html() + '"' + checked + '>' +
            '<label class="custom-control-label" for="fokus-' + svSlug(f) + '">' + $('<div>').text(f).html() + '</label></div>';
    });
    if (!html) { html = '<div class="text-muted small">Pilih jenis supervisi terlebih dahulu.</div>'; }
    $('#sv-fokus-box').html(html);
}

function svSlug(s) {
    return String(s).replace(/[^A-Za-z0-9]+/g, '-').toLowerCase();
}

function svRenderIndikator(jenis, selectedFokus, indikatorMap) {
    indikatorMap = indikatorMap || {};
    var box = $('#sv-indikator-box');
    box.empty();
    if (!selectedFokus.length) {
        box.html('<div class="text-muted small">Pilih fokus supervisi untuk menampilkan indikator penilaian.</div>');
        return;
    }
    var html = '';
    selectedFokus.forEach(function (f) {
        var inds = svTemplateIndikator(jenis, f).slice();
        (indikatorMap[f] || []).forEach(function (i) { if (inds.indexOf(i) === -1) { inds.push(i); } });
        var chosen = indikatorMap[f] || [];
        html += '<div class="card mb-2"><div class="card-header py-2 d-flex justify-content-between align-items-center">' +
            '<strong>' + $('<div>').text(f).html() + '</strong>' +
            '<span><button type="button" class="btn btn-sm btn-outline-primary sv-check-group" data-fokus="' + svSlug(f) + '">Pilih Semua</button> ' +
            '<button type="button" class="btn btn-sm btn-outline-secondary sv-uncheck-group" data-fokus="' + svSlug(f) + '">Batalkan</button></span>' +
            '</div><div class="card-body p-2">';
        if (!inds.length) {
            html += '<div class="text-muted small">Belum ada indikator untuk fokus ini.</div>';
        }
        inds.forEach(function (i, idx) {
            var checked = chosen.indexOf(i) !== -1 ? ' checked' : '';
            var id = 'ind-' + svSlug(f) + '-' + idx;
            html += '<div class="custom-control custom-checkbox mb-1">' +
                '<input type="checkbox" class="custom-control-input sv-indikator" id="' + id + '" data-fokus="' + svSlug(f) + '" name="indikator[]" value="' + $('<div>').text(f + '\x1F' + i).html() + '"' + checked + '>' +
                '<label class="custom-control-label" for="' + id + '">' + $('<div>').text(i).html() + '</label></div>';
        });
        html += '</div></div>';
    });
    box.html(html);
}

function svSelectedFokus() {
    var arr = [];
    $('.sv-fokus:checked').each(function () { arr.push($(this).val()); });
    return arr;
}

function svCurrentIndikatorMap() {
    var map = {};
    $('.sv-indikator:checked').each(function () {
        var v = $(this).val().split('\x1F');
        if (v.length !== 2) { return; }
        if (!map[v[0]]) { map[v[0]] = []; }
        map[v[0]].push(v[1]);
    });
    return map;
}

function svPopulateTemplateSelect(jenis, selectedKode) {
    var sel = $('#sv-template-select');
    sel.empty().append('<option value="">- Pilih Template Program -</option>');
    jenis = (jenis||'').toString().trim();
    if (!jenis) { sel.append('<option value="" disabled>Pilih Jenis Supervisi dulu</option>'); sel.prop('disabled', true); return; }
    sel.prop('disabled', false);
    var list = svProgramTemplates[jenis];
    if (!list) {
        for (var k in svProgramTemplates) { if (k.toLowerCase() === jenis.toLowerCase()) { list = svProgramTemplates[k]; break; } }
    }
    if (!list || !list.length) { sel.append('<option value="" disabled>Tidak ada template untuk ' + $('<div>').text(jenis).html() + '</option>'); sel.prop('disabled', true); return; }
    list.forEach(function (t) {
        var opt = $('<option>').val(String(t.kode)).text(String(t.kode) + ' - ' + t.nama);
        if (selectedKode && String(t.kode) === String(selectedKode)) {
            opt.prop('selected', true);
        }
        sel.append(opt);
    });
    sel.prop('disabled', false);
}

function svApplyTemplate(kode) {
    if (!kode) { return; }
    var jenis = $('#form-program [name=jenis_supervisi]').val();
    var tpl = null;
    (svProgramTemplates[jenis] || []).forEach(function (t) { if (t.kode === kode) { tpl = t; } });
    if (!tpl) { return; }
    $('#form-program [name=kode_program]').val(tpl.kode);
    $('#form-program [name=nama_program]').val(tpl.nama);
    $('#form-program [name=tujuan]').val(tpl.tujuan);
    $('#form-program [name=sasaran]').val(tpl.sasaran);
    $('#form-program [name=target]').val(tpl.target);
    var fokusList = Object.keys(tpl.fokus || {});
    svRenderFokus(jenis, fokusList);
    var map = {};
    fokusList.forEach(function (f) { map[f] = (tpl.fokus[f] || []).slice(); });
    svRenderIndikator(jenis, fokusList, map);

}

$(document).ready(function () {
    function svFilterCards() {
        var query = $.trim($('#filter-search').val() || '').toLowerCase();
        var jenis = $.trim($('#filter-jenis-card').val() || '');
        var visible = 0;
        $('.program-card-col').each(function () {
            var searchTxt = $(this).attr('data-search') || '';
            var cardJenis = $(this).attr('data-jenis') || '';
            var matchQuery = !query || searchTxt.indexOf(query) !== -1;
            var matchJenis = !jenis || cardJenis === jenis;
            if (matchQuery && matchJenis) {
                $(this).removeClass('d-none');
                visible++;
            } else {
                $(this).addClass('d-none');
            }
        });
        $('#empty-search-msg').toggle(visible === 0);
        $('#program-count-badge').text(visible + ' Program');
    }

    $('#filter-search').on('input', svFilterCards);
    $('#filter-jenis-card').on('change', svFilterCards);

    $('#modal-program').on('shown.bs.modal', function () {

    });

    $('#form-program').on('submit', function (e) {
        var tm = this.tanggal_mulai && this.tanggal_mulai.value;
        var ts = this.tanggal_selesai && this.tanggal_selesai.value;
        if (tm && ts && ts < tm) {
            e.preventDefault();
            Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Waktu selesai tidak boleh sebelum waktu mulai.' });
            return false;
        }
    });
    $(document).on('change', '#form-program [name=jenis_supervisi]', function () {
        var jenis = $.trim($(this).val() || '');
        $('#sv-template-select').val('');
        $('#form-program [name=kode_program]').val('');
        $('#form-program [name=nama_program]').val('');
        $('#form-program [name=tujuan]').val('');
        $('#form-program [name=sasaran]').val('');
        $('#form-program [name=target]').val('');
        svPopulateTemplateSelect(jenis);
        svRenderFokus(jenis, []);
        svRenderIndikator(jenis, [], {});
    });
    $('#sv-template-select').on('change', function () { svApplyTemplate($(this).val()); });

    $(document).on('change', '.sv-fokus', function () {
        var jenis = $('#form-program [name=jenis_supervisi]').val();
        svRenderIndikator(jenis, svSelectedFokus(), svCurrentIndikatorMap());
    });
    $(document).on('click', '.sv-check-group', function () {
        var f = $(this).data('fokus');
        $('.sv-indikator[data-fokus="' + f + '"]').prop('checked', true);
    });
    $(document).on('click', '.sv-uncheck-group', function () {
        var f = $(this).data('fokus');
        $('.sv-indikator[data-fokus="' + f + '"]').prop('checked', false);
    });
    $('#sv-pilih-semua-fokus').on('click', function () {
        $('.sv-fokus').prop('checked', true);
        var jenis = $('#form-program [name=jenis_supervisi]').val();
        svRenderIndikator(jenis, svSelectedFokus(), svCurrentIndikatorMap());
    });
    $('#sv-batal-semua-fokus').on('click', function () {
        $('.sv-fokus').prop('checked', false);
        var jenis = $('#form-program [name=jenis_supervisi]').val();
        svRenderIndikator(jenis, [], {});
    });
    $('#sv-pilih-semua-indikator').on('click', function () { $('.sv-indikator').prop('checked', true); });
    $('#sv-batal-semua-indikator').on('click', function () { $('.sv-indikator').prop('checked', false); });

    $(document).on('click', '.btn-edit', function () {
        var d = $(this).data('row');
        $('#form-program')[0].reset();
        $('#form-program [name=aksi]').val('edit');
        if (d.penanggung_jawab) {
            var pjSel = $('#form-program [name="penanggung_jawab"]');
            var pjVal = String(d.penanggung_jawab);
            var esc = pjVal.replace(/"/g, '&quot;');
            if (pjSel.find('option').filter(function(){ return $(this).val() === pjVal; }).length === 0) {
                pjSel.append('<option value="' + $('<div>').text(pjVal).html() + '">' + $('<div>').text(pjVal).html() + ' (lama)</option>');
            }
        }
        Object.keys(d).forEach(function (k) {
            var el = $('#form-program [name="' + k + '"]');
            if (el.length) { el.val(d[k]); }
        });
        var jenis = d.jenis_supervisi || 'Akademik';
        svPopulateTemplateSelect(jenis, d.kode_program || '');
        svRenderFokus(jenis, d._fokus_arr || []);
        svRenderIndikator(jenis, d._fokus_arr || [], d._indikator_map || {});
        $('#modal-program .modal-title').text('Edit Program Supervisi');
        $('#modal-program').modal('show');    });
    $('#btn-tambah').on('click', function () {
        $('#form-program')[0].reset();
        $('#form-program [name=aksi]').val('tambah');
        $('#form-program [name=id_program]').val('');
        $('#form-program [name=jenis_supervisi]').val($('#form-program [name=jenis_supervisi] option').filter(function(){return $.trim($(this).val())==='';}).length? '' : ($('#form-program [name=jenis_supervisi]').val()||''));
        var j0 = $.trim($('#form-program [name=jenis_supervisi]').val()||'');
        svPopulateTemplateSelect(j0);
        svRenderFokus(j0, []);
        svRenderIndikator(j0, [], {});
        $('#modal-program .modal-title').text('Tambah Program Supervisi');
        $('#modal-program').modal('show');
    });
    $(document).on('click', '.btn-hapus', function () {
        var id = $(this).data('id');
        Swal.fire({title:'Konfirmasi Hapus',text:'Program supervisi akan dihapus.',icon:'warning',showCancelButton:true,confirmButtonColor:'#d33',cancelButtonColor:'#6c757d',confirmButtonText:'Ya, Hapus!',cancelButtonText:'Batal'}).then(function(r){if(r.isConfirmed){var f=document.createElement('form');f.method='POST';f.action='program_supervisi.php';var fields={aksi: 'hapus', id_program: id};Object.keys(fields).forEach(function(k){var i=document.createElement('input');i.type='hidden';i.name=k;i.value=fields[k];f.appendChild(i);});document.body.appendChild(f);f.submit();}})
    });
    $('#btn-seed').on('click', function () {
        Swal.fire({title:'Muat Template Program',text:'Lengkapi data program yang kosong dari template? Data yang sudah benar tidak akan ditimpa.',icon:'warning',showCancelButton:true,confirmButtonColor:'#d33',cancelButtonColor:'#6c757d',confirmButtonText:'Ya, Hapus!',cancelButtonText:'Batal'}).then(function(r){if(r.isConfirmed){var f=document.createElement('form');f.method='POST';f.action='program_supervisi.php';var fields={aksi: 'seed'};Object.keys(fields).forEach(function(k){var i=document.createElement('input');i.type='hidden';i.name=k;i.value=fields[k];f.appendChild(i);});document.body.appendChild(f);f.submit();}})
    });
    $('#btn-excel').on('click', function () {
        var data = [];
        data.push(['No', 'Kode Program', 'Nama Program', 'Jenis Supervisi', 'Tahun Ajaran', 'Semester', 'Penanggung Jawab', 'Target', 'Jadwal / Waktu', 'Status', 'Tujuan', 'Sasaran', 'Fokus Supervisi', 'Indikator Keberhasilan', 'Keterangan']);
        
        if (typeof svProgramData !== 'undefined' && Array.isArray(svProgramData)) {
            svProgramData.forEach(function (r, i) {
                var rentang = '';
                if (r.tanggal_mulai || r.tanggal_selesai) {
                    rentang = (r.tanggal_mulai || '') + ' s/d ' + (r.tanggal_selesai || '');
                } else {
                    rentang = r.waktu_pelaksanaan || '-';
                }
                data.push([
                    i + 1,
                    r.kode_program || '-',
                    r.nama_program || '-',
                    r.jenis_supervisi || '-',
                    r.tahun_ajaran || '-',
                    r.semester || '-',
                    r.penanggung_jawab || '-',
                    r.target || '-',
                    rentang,
                    r.status || '-',
                    r.tujuan || '-',
                    r.sasaran || '-',
                    r.fokus_supervisi || '-',
                    r.indikator_keberhasilan || '-',
                    r.keterangan || '-'
                ]);
            });
        }
        
        if (typeof XLSX !== 'undefined') {
            var ws = XLSX.utils.aoa_to_sheet(data);
            var wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Program Supervisi");
            XLSX.writeFile(wb, 'program_supervisi.xlsx');
        } else {
            alert('Library XLSX tidak tersedia');
        }
    });
    $('#btn-pdf').on('click', function () { window.open('cetak_program_supervisi.php?tahun_ajaran=' + encodeURIComponent($('#sv-filter-ta').val() || ''), '_blank'); });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<?php
$total_prog = count($rows);
$count_akd = count(array_filter($rows, fn($r) => ($r['jenis_supervisi'] ?? '') === 'Akademik'));
$count_adm = count(array_filter($rows, fn($r) => ($r['jenis_supervisi'] ?? '') === 'Administrasi'));
$count_man = count(array_filter($rows, fn($r) => ($r['jenis_supervisi'] ?? '') === 'Manajerial'));
$count_aktif = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'Aktif'));
?>
<div class="main-content">
    <style>
    .hover-shadow {
        transition: all 0.2s ease-in-out;
    }
    .hover-shadow:hover {
        box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,0.1) !important;
        transform: translateY(-2px);
    }
    .transition {
        transition: all 0.2s ease-in-out;
    }
    </style>
    <section class="section">
        <div class="section-header">
            <h1>Program Supervisi</h1>
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
            <input type="hidden" id="svFoundation" value="<?= htmlspecialchars($school_profile['nama_yayasan'] ?? '', ENT_QUOTES) ?>">
            <input type="hidden" id="svAddress" value="<?= htmlspecialchars($school_profile['alamat'] ?? '', ENT_QUOTES) ?>">
            <input type="hidden" id="svEmail" value="<?= htmlspecialchars($school_profile['email_madrasah'] ?? '', ENT_QUOTES) ?>">
            <input type="hidden" id="svWebsite" value="<?= htmlspecialchars($school_profile['website_madrasah'] ?? '', ENT_QUOTES) ?>">

            <!-- Filter & Search Card -->
            <div class="card mb-3">
                <div class="card-body p-3">
                    <div class="form-row align-items-end">
                        <div class="form-group col-12 col-md-3 mb-2 mb-md-0">
                            <label class="small font-weight-bold">Tahun Ajaran</label>
                            <form method="GET" id="form-ta-filter">
                                <select class="form-control form-control-sm" name="tahun_ajaran" id="sv-filter-ta" onchange="this.form.submit()">
                                    <?php foreach (sv_tahun_ajaran_options($pdo) as $ta): ?>
                                        <option value="<?= htmlspecialchars($ta, ENT_QUOTES) ?>" <?= $ta === $filter_ta ? 'selected' : '' ?>><?= htmlspecialchars($ta) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                        <div class="form-group col-12 col-md-3 mb-2 mb-md-0">
                            <label class="small font-weight-bold">Jenis Supervisi</label>
                            <select class="form-control form-control-sm" id="filter-jenis-card">
                                <option value="">Semua Jenis</option>
                                <option value="Akademik">Akademik</option>
                                <option value="Administrasi">Administrasi</option>
                                <option value="Manajerial">Manajerial</option>
                            </select>
                        </div>
                        <div class="form-group col-12 col-md-6 mb-0">
                            <label class="small font-weight-bold">Cari Program</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="filter-search" placeholder="Cari nama program, kode, fokus, sasaran...">
                                <div class="input-group-append">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Chips -->
            <div class="d-flex flex-wrap align-items-center mb-3" style="gap: 8px;">
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-layer-group text-primary mr-1"></i> Total: <strong id="program-count-badge"><?= (int)$total_prog ?> Program</strong>
                </span>
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-graduation-cap text-info mr-1"></i> Akademik: <strong><?= (int)$count_akd ?></strong>
                </span>
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-file-invoice text-warning mr-1"></i> Administrasi: <strong><?= (int)$count_adm ?></strong>
                </span>
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-building text-secondary mr-1"></i> Manajerial: <strong><?= (int)$count_man ?></strong>
                </span>
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-check-circle text-success mr-1"></i> Aktif: <strong><?= (int)$count_aktif ?></strong>
                </span>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4>Daftar Program Supervisi <small class="text-muted">TA <?= htmlspecialchars($filter_ta) ?></small></h4>
                    <div class="card-header-action">
                        <button class="btn btn-success btn-sm" id="btn-excel" type="button"><i class="fas fa-file-excel"></i> Excel</button>
                        <button class="btn btn-warning btn-sm" id="btn-pdf" type="button"><i class="fas fa-file-pdf"></i> PDF</button>
                        <?php if ($can_manage): ?>
                        <button class="btn btn-info btn-sm" id="btn-seed" type="button"><i class="fas fa-magic"></i> Muat Template</button>
                        <button class="btn btn-primary btn-sm" id="btn-tambah" type="button"><i class="fas fa-plus"></i> Tambah</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($rows)): ?>
                        <div class="alert alert-info text-center py-4">
                            <i class="fas fa-info-circle fa-2x mb-2 d-block text-info"></i>
                            Belum ada program supervisi pada Tahun Ajaran <?= htmlspecialchars($filter_ta) ?>.
                            <?php if ($can_manage): ?>
                                <br><small class="text-muted">Klik tombol <strong>"Muat Template"</strong> atau <strong>"Tambah"</strong> untuk membuat program baru.</small>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div id="empty-search-msg" class="alert alert-warning text-center" style="display:none;">
                            <i class="fas fa-search mr-1"></i> Tidak ada program supervisi yang cocok dengan filter pencarian Anda.
                        </div>
                        <div class="row" id="program-cards-container">
                            <?php foreach ($rows as $r): ?>
                                <?php
                                $searchBlob = strtolower(implode(' ', [
                                    $r['kode_program'] ?? '',
                                    $r['nama_program'] ?? '',
                                    $r['jenis_supervisi'] ?? '',
                                    $r['tujuan'] ?? '',
                                    $r['sasaran'] ?? '',
                                    $r['penanggung_jawab'] ?? '',
                                    $r['target'] ?? '',
                                    $r['fokus_supervisi'] ?? '',
                                    $r['keterangan'] ?? ''
                                ]));
                                $stBadge = ($r['status'] ?? '') === 'Aktif' ? 'success' : 'secondary';
                                $rentangWaktu = sv_format_rentang($r['tanggal_mulai'] ?? null, $r['tanggal_selesai'] ?? null) ?: (string)($r['waktu_pelaksanaan'] ?? '-');
                                ?>
                                <div class="col-12 col-md-6 col-lg-4 d-flex align-items-stretch mb-4 program-card-col"
                                     data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES) ?>"
                                     data-jenis="<?= htmlspecialchars($r['jenis_supervisi'] ?? '', ENT_QUOTES) ?>">
                                    <div class="card w-100 border-0 shadow-sm hover-shadow transition" style="border-radius: 12px; overflow: hidden; background: #fff; border: 1px solid #e2e8f0 !important;">
                                        <div class="card-body p-3 d-flex flex-column justify-content-between">
                                            <div>
                                                <!-- Top Bar: Kode & Jenis & Status -->
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <div class="d-flex align-items-center" style="gap: 5px;">
                                                        <?php if (!empty($r['kode_program'])): ?>
                                                            <span class="badge badge-light border font-weight-bold" style="font-size: 11px; border-radius: 6px;">
                                                                <i class="fas fa-barcode text-muted mr-1"></i> <?= htmlspecialchars($r['kode_program']) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <span class="badge badge-info font-weight-bold" style="font-size: 11px; border-radius: 6px;">
                                                            <?= htmlspecialchars($r['jenis_supervisi']) ?>
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <span class="badge badge-<?= $stBadge ?> font-weight-bold" style="font-size: 11px; border-radius: 6px;">
                                                            <i class="fas fa-circle mr-1" style="font-size: 7px;"></i> <?= htmlspecialchars($r['status'] ?: 'Aktif') ?>
                                                        </span>
                                                    </div>
                                                </div>

                                                <!-- Nama Program -->
                                                <h6 class="font-weight-bold text-dark mb-2" style="font-size: 15px; line-height: 1.4; min-height: 42px;">
                                                    <?= htmlspecialchars($r['nama_program']) ?>
                                                </h6>

                                                <!-- Info Badges (Semester & PJ) -->
                                                <div class="d-flex flex-wrap align-items-center mb-3" style="gap: 6px;">
                                                    <span class="badge badge-light border text-dark px-2 py-1" style="border-radius: 4px; font-size: 12px;">
                                                        <i class="fas fa-calendar-alt text-muted mr-1"></i> <?= htmlspecialchars($r['tahun_ajaran']) ?> (<?= htmlspecialchars($r['semester']) ?>)
                                                    </span>
                                                    <span class="badge badge-light border text-dark px-2 py-1" style="border-radius: 4px; font-size: 12px;">
                                                        <i class="fas fa-user-tie text-muted mr-1"></i> <?= htmlspecialchars((string)($r['penanggung_jawab'] ?: 'Kepala Madrasah')) ?>
                                                    </span>
                                                </div>

                                                <!-- Target & Waktu Grid -->
                                                <div class="row mb-3">
                                                    <div class="col-6">
                                                        <div class="p-2 rounded" style="background: #f8fafc; height: 100%;">
                                                            <small class="text-muted d-block font-weight-bold" style="font-size: 10.5px;"><i class="fas fa-bullseye text-primary mr-1"></i> Target</small>
                                                            <span class="text-dark font-weight-bold" style="font-size: 12.5px;"><?= htmlspecialchars((string)($r['target'] ?: '-')) ?></span>
                                                        </div>
                                                    </div>
                                                    <div class="col-6">
                                                        <div class="p-2 rounded" style="background: #f8fafc; height: 100%;">
                                                            <small class="text-muted d-block font-weight-bold" style="font-size: 10.5px;"><i class="far fa-clock text-info mr-1"></i> Jadwal</small>
                                                            <span class="text-dark" style="font-size: 11.5px; line-height: 1.3; display: block;"><?= htmlspecialchars($rentangWaktu) ?></span>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Tujuan & Sasaran -->
                                                <?php if (!empty($r['tujuan'])): ?>
                                                    <div class="p-2 mb-2 rounded border-0" style="background: #eff6ff; font-size: 12px; line-height: 1.45;">
                                                        <i class="fas fa-flag text-primary mr-1"></i> <strong>Tujuan:</strong><br>
                                                        <span class="text-dark"><?= nl2br(htmlspecialchars(mb_strimwidth($r['tujuan'], 0, 110, '...'))) ?></span>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if (!empty($r['sasaran'])): ?>
                                                    <div class="p-2 mb-2 rounded border-0" style="background: #f0fdf4; font-size: 12px; line-height: 1.45;">
                                                        <i class="fas fa-users text-success mr-1"></i> <strong>Sasaran:</strong><br>
                                                        <span class="text-dark"><?= nl2br(htmlspecialchars(mb_strimwidth($r['sasaran'], 0, 95, '...'))) ?></span>
                                                    </div>
                                                <?php endif; ?>

                                                <!-- Fokus Supervisi Tags -->
                                                <?php if (!empty($r['_fokus_arr'])): ?>
                                                    <div class="mb-2">
                                                        <div class="font-weight-bold text-dark mb-1" style="font-size: 11.5px;">
                                                            <i class="fas fa-crosshairs mr-1" style="color: #4f46e5;"></i> Fokus Supervisi:
                                                        </div>
                                                        <div class="d-flex flex-wrap" style="gap: 5px;">
                                                            <?php foreach (array_slice($r['_fokus_arr'], 0, 3) as $fokus): ?>
                                                                <span class="badge" style="background: #eef2ff; color: #312e81; border: 1px solid #c7d2fe; font-size: 11px; font-weight: 600; padding: 4px 8px; border-radius: 6px; letter-spacing: 0.1px;"><?= htmlspecialchars($fokus) ?></span>
                                                            <?php endforeach; ?>
                                                            <?php if (count($r['_fokus_arr']) > 3): ?>
                                                                <span class="badge" style="background: #e0e7ff; color: #4338ca; border: 1px solid #c7d2fe; font-size: 10.5px; font-weight: 700; padding: 4px 7px; border-radius: 6px;">+<?= count($r['_fokus_arr']) - 3 ?> lainnya</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Footer: Aksi -->
                                            <?php if ($can_manage): ?>
                                            <div class="pt-3 mt-2 border-top d-flex justify-content-end" style="gap: 6px;">
                                                <button class="btn btn-outline-warning btn-sm btn-edit px-3" type="button"
                                                    data-row='<?= htmlspecialchars(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>' style="border-radius: 6px; font-size: 12px;">
                                                    <i class="fas fa-edit mr-1"></i> Edit
                                                </button>
                                                <button class="btn btn-outline-danger btn-sm btn-hapus px-3" type="button" data-id="<?= (int)$r['id_program'] ?>" style="border-radius: 6px; font-size: 12px;">
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
                </div>
            </div>
        </div>
    </section>
</div>

<?php if ($can_manage): ?>
<div class="modal fade" id="modal-program" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <form method="POST" action="program_supervisi.php" id="form-program">
                <input type="hidden" name="aksi" value="tambah">
                <input type="hidden" name="id_program" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Program Supervisi</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>Jenis Supervisi <span class="text-danger">*</span></label>
                            <select class="form-control" name="jenis_supervisi" required>
                                <option value="">-- Pilih Jenis Supervisi --</option>
                                <?php foreach (sv_jenis_list() as $j): ?><option value="<?= $j ?>"><?= $j ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-8">
                            <label>Template Program <small class="text-muted">pilih Jenis dulu</small></label>
                            <select class="form-control" id="sv-template-select">
                                <option value="">- Pilih Template Program -</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>Kode Program</label>
                            <input type="text" class="form-control" name="kode_program" placeholder="cth: AKD-01">
                        </div>
                        <div class="form-group col-md-4">
                            <label>Tahun Ajaran</label>
                            <input type="text" class="form-control" name="tahun_ajaran" value="<?= htmlspecialchars($periode['tahun_ajaran'], ENT_QUOTES) ?>" required>
                        </div>
                        <div class="form-group col-md-4">
                            <label>Semester</label>
                            <select class="form-control" name="semester">
                                <?php foreach (sv_semester_options() as $s): ?>
                                    <option value="<?= htmlspecialchars($s, ENT_QUOTES) ?>" <?= $s === $periode['semester'] ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Nama Program</label>
                        <input type="text" class="form-control" name="nama_program" required>
                    </div>
                    <div class="form-group">
                        <label>Tujuan</label>
                        <textarea class="form-control sv-autogrow" name="tujuan" rows="6" style="min-height:140px"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Sasaran</label>
                        <textarea class="form-control sv-autogrow" name="sasaran" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Fokus Supervisi (pilih satu/beberapa)</label>
                        <div class="mb-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="sv-pilih-semua-fokus">Pilih Semua</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="sv-batal-semua-fokus">Batalkan Semua</button>
                        </div>
                        <div class="border rounded p-2" id="sv-fokus-box"></div>
                    </div>
                    <div class="form-group">
                        <label>Target</label>
                        <textarea class="form-control sv-autogrow" name="target" rows="6" style="min-height:140px" placeholder="cth: Seluruh guru memperoleh supervisi akademik sesuai jadwal..."></textarea>
                    </div>
                    <div class="form-group">
                        <label>Indikator Penilaian (mengikuti fokus terpilih)</label>
                        <div class="mb-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="sv-pilih-semua-indikator">Pilih Semua Indikator</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="sv-batal-semua-indikator">Batalkan Semua Indikator</button>
                        </div>
                        <div id="sv-indikator-box"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>Waktu Mulai</label>
                            <input type="date" class="form-control" name="tanggal_mulai">
                        </div>
                        <div class="form-group col-md-4">
                            <label>Waktu Selesai</label>
                            <input type="date" class="form-control" name="tanggal_selesai">
                        </div>
                        <div class="form-group col-md-4">
                            <label>Keterangan Waktu (opsional)</label>
                            <input type="text" class="form-control" name="waktu_pelaksanaan" placeholder="cth: Smt 1">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Penanggung Jawab</label>
                            <select class="form-control" name="penanggung_jawab" required>
                                <option value="">-- Pilih Jabatan --</option>
                                <?php foreach (getJabatanList($pdo) as $jb): ?><option value="<?= htmlspecialchars($jb['nama_jabatan'], ENT_QUOTES) ?>"><?= htmlspecialchars($jb['nama_jabatan']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Status</label>
                            <select class="form-control" name="status">
                                <option value="Aktif">Aktif</option>
                                <option value="Selesai">Selesai</option>
                                <option value="Draft">Draft</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Keterangan</label>
                        <textarea class="form-control sv-autogrow" name="keterangan" rows="2"></textarea>
                    </div>
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
