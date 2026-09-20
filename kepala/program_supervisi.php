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
    'assets/js/supervisi.js',
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

function svPopulateTemplateSelect(jenis) {
    var sel = $('#sv-template-select');
    sel.empty().append('<option value="">- Pilih Template Program -</option>');
    (svProgramTemplates[jenis] || []).forEach(function (t) {
        sel.append('<option value="' + t.kode + '">' + t.kode + ' - ' + $('<div>').text(t.nama).html() + '</option>');
    });
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
    SV.autoGrowTextareas();
}

$(document).ready(function () {
    SV.initDataTable('#table-program');
    $('#sv-filter-ta').on('change', function () { var v = $(this).val(); if (v) window.location.href = 'program_supervisi.php?tahun_ajaran=' + encodeURIComponent(v); });
    SV.autoGrowTextareas();

    $('#modal-program').on('shown.bs.modal', function () {
        SV.autoGrowTextareas();
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
    $('#form-program [name=jenis_supervisi]').on('change', function () {
        var jenis = $(this).val();
        svPopulateTemplateSelect(jenis);
        svRenderFokus(jenis, svSelectedFokus());
        svRenderIndikator(jenis, svSelectedFokus(), svCurrentIndikatorMap());
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
        Object.keys(d).forEach(function (k) {
            var el = $('#form-program [name="' + k + '"]');
            if (el.length) { el.val(d[k]); }
        });
        var jenis = d.jenis_supervisi || 'Akademik';
        svPopulateTemplateSelect(jenis);
        svRenderFokus(jenis, d._fokus_arr || []);
        svRenderIndikator(jenis, d._fokus_arr || [], d._indikator_map || {});
        $('#modal-program .modal-title').text('Edit Program Supervisi');
        $('#modal-program').modal('show');    });
    $('#btn-tambah').on('click', function () {
        $('#form-program')[0].reset();
        $('#form-program [name=aksi]').val('tambah');
        $('#form-program [name=id_program]').val('');
        var jenis = $('#form-program [name=jenis_supervisi]').val() || 'Akademik';
        svPopulateTemplateSelect(jenis);
        svRenderFokus(jenis, []);
        svRenderIndikator(jenis, [], {});
        $('#modal-program .modal-title').text('Tambah Program Supervisi');
        $('#modal-program').modal('show');
    });
    $(document).on('click', '.btn-hapus', function () {
        var id = $(this).data('id');
        SV.confirmDelete({ text: 'Program supervisi akan dihapus.', onConfirm: function () { SV.submitPost('program_supervisi.php', { aksi: 'hapus', id_program: id }); } });
    });
    $('#btn-seed').on('click', function () {
        SV.confirmDelete({ title: 'Muat Template Program', text: 'Lengkapi data program yang kosong dari template? Data yang sudah benar tidak akan ditimpa.', onConfirm: function () { SV.submitPost('program_supervisi.php', { aksi: 'seed' }); } });
    });
    $('#btn-excel').on('click', function () { SV.exportExcel('table-program', 'Program Supervisi', 'program_supervisi', true); });
    $('#btn-pdf').on('click', function () { SV.printPdf('table-program', 'Program Supervisi', true); });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<div class="main-content">
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

            <div class="card">
                <div class="card-header">
                    <h4>Daftar Program Supervisi</h4>
                    <div class="card-header-action">
                        <button class="btn btn-success" id="btn-excel" type="button"><i class="fas fa-file-excel"></i> Excel</button>
                        <button class="btn btn-warning" id="btn-pdf" type="button"><i class="fas fa-file-pdf"></i> PDF</button>
                        <?php if ($can_manage): ?>
                        <button class="btn btn-info" id="btn-seed" type="button"><i class="fas fa-magic"></i> Muat Template</button>
                         <select class="form-control form-control-sm ml-2" id="sv-filter-ta" style="width:140px;display:inline-block;">
                            <?php foreach (sv_tahun_ajaran_options($pdo) as $ta): ?><option value="<?= htmlspecialchars($ta, ENT_QUOTES) ?>" <?= $ta === $filter_ta ? 'selected' : '' ?>><?= htmlspecialchars($ta) ?></option><?php endforeach; ?>
                        </select>
                        <button class="btn btn-primary" id="btn-tambah" type="button"><i class="fas fa-plus"></i> Tambah</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-program">
                            <thead>
                                <tr>
                                    <th width="5%">No</th>
                                    <th>Kode</th>
                                    <th>Tahun Ajaran</th>
                                    <th>Semester</th>
                                    <th>Jenis</th>
                                    <th>Nama Program</th>
                                    <th>Tujuan</th>
                                    <th>Sasaran</th>
                                    <th>Fokus</th>
                                    <th>Target</th>
                                    <th>Indikator</th>
                                    <th>Waktu</th>
                                    <th>Penanggung Jawab</th>
                                    <th>Status</th>
                                    <th>Keterangan</th>
                                    <?php if ($can_manage): ?><th width="10%">Aksi</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="text-center"></td>
                                        <td><?= htmlspecialchars((string)$r['kode_program']) ?></td>
                                        <td><?= htmlspecialchars($r['tahun_ajaran']) ?></td>
                                        <td><?= htmlspecialchars($r['semester']) ?></td>
                                        <td><span class="badge badge-info"><?= htmlspecialchars($r['jenis_supervisi']) ?></span></td>
                                        <td><?= htmlspecialchars($r['nama_program']) ?></td>
                                        <td><?= nl2br(htmlspecialchars((string)$r['tujuan'])) ?></td>
                                        <td><?= nl2br(htmlspecialchars((string)$r['sasaran'])) ?></td>
                                        <td><?= nl2br(htmlspecialchars((string)$r['fokus_supervisi'])) ?></td>
                                        <td><?= htmlspecialchars((string)$r['target']) ?></td>
                                        <td><?= nl2br(htmlspecialchars((string)$r['indikator_keberhasilan'])) ?></td>
                                        <td><?= htmlspecialchars(sv_format_rentang($r['tanggal_mulai'] ?? null, $r['tanggal_selesai'] ?? null) ?: (string)($r['waktu_pelaksanaan'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars((string)$r['penanggung_jawab']) ?></td>
                                        <td><?php $st = $r['status'] === 'Aktif' ? 'success' : 'secondary'; ?><span class="badge badge-<?= $st ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                        <td><?= htmlspecialchars((string)$r['keterangan']) ?></td>
                                        <?php if ($can_manage): ?>
                                        <td>
                                            <button class="btn btn-warning btn-sm btn-edit" type="button"
                                                data-row='<?= htmlspecialchars(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>'>
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-danger btn-sm btn-hapus" type="button" data-id="<?= (int)$r['id_program'] ?>"><i class="fas fa-trash"></i></button>
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
                            <label>Jenis Supervisi</label>
                            <select class="form-control" name="jenis_supervisi">
                                <?php foreach (sv_jenis_list() as $j): ?><option value="<?= $j ?>"><?= $j ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-8">
                            <label>Template Program (otomatis mengisi data, tetap dapat diedit)</label>
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
                        <textarea class="form-control sv-autogrow" name="tujuan" rows="4"></textarea>
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
                        <textarea class="form-control sv-autogrow" name="target" rows="3" placeholder="cth: Seluruh guru memperoleh supervisi akademik sesuai jadwal..."></textarea>
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
                            <input type="text" class="form-control" name="penanggung_jawab" value="<?= htmlspecialchars(sv_current_user_name($pdo), ENT_QUOTES) ?>">
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
