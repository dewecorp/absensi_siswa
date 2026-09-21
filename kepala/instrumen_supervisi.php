<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$page_title = 'Daftar Instrumen Supervisi';
$current_page = basename(__FILE__);
$can_manage = sv_is_supervisor($pdo);

$school_profile = getSchoolProfile($pdo);
$periode = sv_periode($pdo);
$instrumen_templates = sv_instrumen_templates();

$css_libs = ['https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'];
$js_libs = [

    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $aksi = (string)($_POST['aksi'] ?? '');
    try {
        if ($aksi === 'tambah' || $aksi === 'edit') {
            $kodeDipilih = trim((string)($_POST['kode_instrumen'] ?? ''));
            $namaDipilih = trim((string)($_POST['nama_instrumen'] ?? ''));
            $jenisSip = trim((string)($_POST['jenis_supervisi'] ?? 'Akademik'));
            $tplAuto = null;
            if ($kodeDipilih !== '') {
                foreach (sv_instrumen_templates() as $jenis => $daftar) {
                    foreach ($daftar as $t) {
                        if (strcasecmp($t['kode'], $kodeDipilih) === 0) { $tplAuto = $t; break 2; }
                    }
                }
            }
            if ($tplAuto && $namaDipilih !== '' && $namaDipilih !== '__custom__' && strcasecmp($namaDipilih, $tplAuto['kode']) === 0) {
                $namaDipilih = $tplAuto['nama'];
            }
            $customName = trim((string)($_POST['nama_custom'] ?? ''));
            if (($namaDipilih === '' || $namaDipilih === '__custom__') && $customName !== '') {
                $namaDipilih = $customName;
            }
            $data = [
                $kodeDipilih ?: ($tplAuto['kode'] ?? ''),
                $namaDipilih,
                $jenisSip,
                trim((string)($_POST['tujuan'] ?? $tplAuto['tujuan'] ?? '')),
                trim((string)($_POST['sasaran'] ?? $tplAuto['sasaran'] ?? '')),
                trim((string)($_POST['skala_penilaian'] ?? $tplAuto['skala'] ?? '1-4')),
                trim((string)($_POST['status'] ?? 'Aktif')),
                trim((string)($_POST['keterangan'] ?? '')),
            ];
            if (trim((string)$data[0]) === '' && $tplAuto) { $data[0] = $tplAuto['kode']; }
            if (trim((string)$data[3]) === '' && $tplAuto) { $data[3] = $tplAuto['tujuan']; }
            if (trim((string)$data[4]) === '' && $tplAuto) { $data[4] = $tplAuto['sasaran']; }
            if ($data[1] === '') {
                sv_flash('warning', 'Nama instrumen wajib diisi.');
            } elseif ($aksi === 'tambah') {
                $stmt = $pdo->prepare("INSERT INTO tb_sv_instrumen
                    (kode_instrumen, nama_instrumen, jenis_supervisi, tujuan, sasaran, skala_penilaian, status, keterangan)
                    VALUES (?,?,?,?,?,?,?,?)");
                $stmt->execute($data);
                sv_log($pdo, 'Tambah Instrumen', $data[1]);
                sv_flash('success', 'Instrumen berhasil ditambahkan.');
            } else {
                $id = (int)($_POST['id_instrumen'] ?? 0);
                $stmt = $pdo->prepare("UPDATE tb_sv_instrumen SET kode_instrumen=?, nama_instrumen=?, jenis_supervisi=?, tujuan=?,
                    sasaran=?, skala_penilaian=?, status=?, keterangan=? WHERE id_instrumen=?");
                $stmt->execute(array_merge($data, [$id]));
                sv_log($pdo, 'Edit Instrumen', $data[1]);
                sv_flash('success', 'Instrumen berhasil diperbarui.');
            }
        } elseif ($aksi === 'nonaktif') {
            $id = (int)($_POST['id_instrumen'] ?? 0);
            $pdo->prepare("UPDATE tb_sv_instrumen SET status = 'Nonaktif' WHERE id_instrumen = ?")->execute([$id]);
            sv_flash('success', 'Instrumen dinonaktifkan.');
        } elseif ($aksi === 'aktifkan') {
            $id = (int)($_POST['id_instrumen'] ?? 0);
            $pdo->prepare("UPDATE tb_sv_instrumen SET status = 'Aktif' WHERE id_instrumen = ?")->execute([$id]);
            sv_flash('success', 'Instrumen diaktifkan.');
        } elseif ($aksi === 'duplikat') {
            $id = (int)($_POST['id_instrumen'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM tb_sv_instrumen WHERE id_instrumen = ? LIMIT 1");
            $stmt->execute([$id]);
            $src = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($src) {
                $ins = $pdo->prepare("INSERT INTO tb_sv_instrumen
                    (kode_instrumen, nama_instrumen, jenis_supervisi, tujuan, sasaran, skala_penilaian, status, keterangan)
                    VALUES (?,?,?,?,?,?,?,?)");
                $ins->execute([
                    $src['kode_instrumen'] . '-COPY',
                    $src['nama_instrumen'] . ' (Salinan)',
                    $src['jenis_supervisi'],
                    $src['tujuan'],
                    $src['sasaran'],
                    $src['skala_penilaian'],
                    'Nonaktif',
                    $src['keterangan'],
                ]);
                $newId = (int)$pdo->lastInsertId();

                $komp = $pdo->prepare("SELECT * FROM tb_sv_komponen WHERE id_instrumen = ? ORDER BY urutan ASC");
                $komp->execute([$id]);
                foreach ($komp->fetchAll(PDO::FETCH_ASSOC) as $k) {
                    $insK = $pdo->prepare("INSERT INTO tb_sv_komponen (id_instrumen, kode_komponen, nama_komponen, bobot, urutan, keterangan) VALUES (?,?,?,?,?,?)");
                    $insK->execute([$newId, $k['kode_komponen'], $k['nama_komponen'], $k['bobot'], $k['urutan'], $k['keterangan']]);
                    $newKompId = (int)$pdo->lastInsertId();

                    $ind = $pdo->prepare("SELECT * FROM tb_sv_indikator WHERE id_komponen = ? ORDER BY urutan ASC");
                    $ind->execute([$k['id_komponen']]);
                    foreach ($ind->fetchAll(PDO::FETCH_ASSOC) as $i) {
                        $insI = $pdo->prepare("INSERT INTO tb_sv_indikator (id_komponen, kode_indikator, indikator, deskripsi, bobot, skor_minimal, skor_maksimal, urutan) VALUES (?,?,?,?,?,?,?,?)");
                        $insI->execute([$newKompId, $i['kode_indikator'], $i['indikator'], $i['deskripsi'], $i['bobot'], $i['skor_minimal'], $i['skor_maksimal'], $i['urutan']]);
                    }
                }
                sv_log($pdo, 'Duplikat Instrumen', $src['nama_instrumen']);
                sv_flash('success', 'Instrumen berhasil diduplikasi (status Nonaktif).');
            }
        } elseif ($aksi === 'hapus') {
            $id = (int)($_POST['id_instrumen'] ?? 0);
            $pdo->prepare("DELETE FROM tb_sv_instrumen WHERE id_instrumen = ?")->execute([$id]);
            sv_log($pdo, 'Hapus Instrumen', 'ID ' . $id);
            sv_flash('success', 'Instrumen berhasil dihapus.');
        }
    } catch (Throwable $e) {
        sv_flash('danger', 'Gagal memproses data: ' . $e->getMessage());
    }
    redirect('instrumen_supervisi.php');
}

try {
    $cntIns = (int)$pdo->query("SELECT COUNT(*) FROM tb_sv_instrumen")->fetchColumn();
    if ($cntIns === 0) {
        sv_seed_instrumen_templates($pdo);
    }
} catch (Throwable $e) {
}

$rows = [];
try {
    $rows = $pdo->query("SELECT i.*,
            (SELECT COUNT(*) FROM tb_sv_komponen k WHERE k.id_instrumen = i.id_instrumen) AS jml_komponen,
            (SELECT COUNT(*) FROM tb_sv_indikator n JOIN tb_sv_komponen k2 ON k2.id_komponen = n.id_komponen WHERE k2.id_instrumen = i.id_instrumen) AS jml_indikator
        FROM tb_sv_instrumen i ORDER BY i.jenis_supervisi ASC, i.nama_instrumen ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$js_page = [];
$flash = sv_render_flash_js();
if ($flash !== '') {
    $js_page[] = $flash;
}
$js_page[] = 'var svInstrumenTemplates = ' . json_encode($instrumen_templates) . ';';
$js_page[] = <<<'JS'
function svTplByKode(kode) {
    for (var j in svInstrumenTemplates) {
        for (var i = 0; i < svInstrumenTemplates[j].length; i++) {
            if (String(svInstrumenTemplates[j][i].kode).toUpperCase() === String(kode || '').toUpperCase()) return svInstrumenTemplates[j][i];
        }
    }
    return null;
}
function svPopulateNamaInstrumen(jenis) {
    var list = svInstrumenTemplates[jenis] || [];
    var $nama = $('#form-instrumen [name=nama_instrumen]');
    $nama.empty();
    $nama.append('<option value="">- Pilih Instrumen -</option>');
    $nama.append('<option value="__custom__">(Instrumen Lain - isi manual)</option>');
    list.forEach(function (t) {
        $nama.append('<option value="' + $('<div>').text(t.kode).html() + '">' + $('<div>').text(t.nama).html() + '</option>');
    });
    $nama.prop('disabled', !list.length);
    $('#sv-template-hint').text(list.length ? (list.length + ' template untuk ' + jenis) : 'Belum ada template untuk ' + jenis + '.\u00a0Isi manual.');
}
function svApplyInstrumenTpl(kode) {
    if (!kode || kode === '__custom__') {
        $('#kode-display').text('');
        $('#form-instrumen [name=kode_instrumen]').val('');
        
        return;
    }
    var t = svTplByKode(kode);
    if (!t) return;
    $('#kode-display').text(t.kode);
    $('#form-instrumen [name=kode_instrumen]').val(t.kode);
    $('#form-instrumen [name=tujuan]').val(t.tujuan);
    $('#form-instrumen [name=sasaran]').val(t.sasaran);
    $('#form-instrumen [name=skala_penilaian]').val(t.skala);
    
}
$(document).ready(function () {
    var dttableinstrumen=$('#table-instrumen').DataTable({language:{search:'Cari:',lengthMenu:'Tampilkan _MENU_ data',info:'Menampilkan _START_ sampai _END_ dari _TOTAL_ data',infoEmpty:'Tidak ada data',zeroRecords:'Data tidak ditemukan',paginate:{first:'Awal',last:'Akhir',next:'Berikutnya',previous:'Sebelumnya'}},pageLength:10,order:[],columnDefs:[],responsive:false});dttableinstrumen.on('order.dt search.dt draw.dt',function(){var info=dttableinstrumen.page.info();dttableinstrumen.column(0,{search:'applied',order:'applied'}).nodes().each(function(cell,i){if(cell) cell.innerHTML=info.page*info.length+i+1;});}).draw();
    
    $('#modal-instrumen').on('shown.bs.modal', function () {  });
    $('#btn-tambah').on('click', function () {
        $('#form-instrumen')[0].reset();
        $('#form-instrumen [name=aksi]').val('tambah');
        $('#form-instrumen [name=id_instrumen]').val('');
        var jenis = $('#form-instrumen [name=jenis_supervisi]').val();
        svPopulateNamaInstrumen(jenis);
        $('#kode-display').text('');
        
        $('#modal-instrumen .modal-title').text('Tambah Instrumen');
        $('#modal-instrumen').modal('show');
    });
    $(document).on('change', '#form-instrumen [name=jenis_supervisi]', function () {
        svPopulateNamaInstrumen($(this).val());
        $('#kode-display').text('');
        $('#form-instrumen [name=kode_instrumen]').val('');
        $('#form-instrumen [name=tujuan]').val('');
        $('#form-instrumen [name=sasaran]').val('');
    });
    $(document).on('change', '#form-instrumen [name=nama_instrumen]', function () {
        var val = $(this).val();
        if (val === '__custom__') {
            $('#sv-nama-custom').removeClass('d-none').val('').focus();
            $('#sv-nama-custom-hidden').val('');
            $('#kode-display').text('');
            $('#form-instrumen [name=kode_instrumen]').val('');
            $('#form-instrumen [name=tujuan]').val('');
            $('#form-instrumen [name=sasaran]').val('');
            return;
        }
        $('#sv-nama-custom').addClass('d-none').val('');
        $('#sv-nama-custom-hidden').val('');
        svApplyInstrumenTpl(val);
    });
    $(document).on('input', '#sv-nama-custom', function () {
        $('#sv-nama-custom-hidden').val($(this).val());
    });
    $('#form-instrumen').on('submit', function (e) {
        var sel = $('#form-instrumen [name=nama_instrumen]').val();
        if (sel === '__custom__') {
            var custom = $('#sv-nama-custom').val().trim();
            if (!custom) {
                e.preventDefault();
                Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Isi nama instrumen.' });
                return false;
            }
            $('#sv-nama-custom-hidden').val(custom);
            $(this).find('[name=nama_instrumen]').val(custom);
        }
        var finalName = $(this).find('[name=nama_instrumen]').val();
        if (!finalName || finalName === '__custom__') {
            e.preventDefault();
            Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Pilih atau isi nama instrumen.' });
            return false;
        }
    });
    $(document).on('click', '.btn-edit', function () {
        var d = $(this).data('row');
        $('#form-instrumen')[0].reset();
        $('#form-instrumen [name=aksi]').val('edit');
        svPopulateNamaInstrumen(d.jenis_supervisi);
        Object.keys(d).forEach(function (k) {
            var el = $('#form-instrumen [name="' + k + '"]');
            if (el.length) { el.val(d[k]); }
        });
        var found = false;
        if (d.kode_instrumen) {
            $('#form-instrumen [name=nama_instrumen] option').each(function () { if ($(this).val() === d.kode_instrumen) { found = true; return false; } });
        }
        if (!found && d.kode_instrumen) {
            $('#form-instrumen [name=nama_instrumen]').append('<option value="' + $('<div>').text(d.kode_instrumen).html() + '">' + $('<div>').text(d.nama_instrumen || d.kode_instrumen).html() + '</option>').val(d.kode_instrumen);
        }
        $('#kode-display').text(d.kode_instrumen || '');
        $('#form-instrumen [name=kode_instrumen]').val(d.kode_instrumen || '');
        $('#modal-instrumen .modal-title').text('Edit Instrumen');
        $('#modal-instrumen').modal('show');
        setTimeout(function () {  }, 120);
    });
    $(document).on('click', '.btn-hapus', function () {
        var id = $(this).data('id');
        Swal.fire({title:'Konfirmasi Hapus',text:'Instrumen beserta komponen & indikatornya akan dihapus.',icon:'warning',showCancelButton:true,confirmButtonColor:'#d33',cancelButtonColor:'#6c757d',confirmButtonText:'Ya, Hapus!',cancelButtonText:'Batal'}).then(function(r){if(r.isConfirmed){var f=document.createElement('form');f.method='POST';f.action='instrumen_supervisi.php';var fields={aksi: 'hapus', id_instrumen: id};Object.keys(fields).forEach(function(k){var i=document.createElement('input');i.type='hidden';i.name=k;i.value=fields[k];f.appendChild(i);});document.body.appendChild(f);f.submit();}})
    });
    $(document).on('click', '.btn-nonaktif', function () {
        var f=document.createElement('form');f.method='POST';f.action='instrumen_supervisi.php';var fields={aksi: 'nonaktif', id_instrumen: $(this).data('id')};Object.keys(fields).forEach(function(k){var i=document.createElement('input');i.type='hidden';i.name=k;i.value=fields[k];f.appendChild(i);});document.body.appendChild(f);f.submit()
    });
    $(document).on('click', '.btn-aktifkan', function () {
        var f=document.createElement('form');f.method='POST';f.action='instrumen_supervisi.php';var fields={aksi: 'aktifkan', id_instrumen: $(this).data('id')};Object.keys(fields).forEach(function(k){var i=document.createElement('input');i.type='hidden';i.name=k;i.value=fields[k];f.appendChild(i);});document.body.appendChild(f);f.submit()
    });
    $(document).on('click', '.btn-duplikat', function () {
        var f=document.createElement('form');f.method='POST';f.action='instrumen_supervisi.php';var fields={aksi: 'duplikat', id_instrumen: $(this).data('id')};Object.keys(fields).forEach(function(k){var i=document.createElement('input');i.type='hidden';i.name=k;i.value=fields[k];f.appendChild(i);});document.body.appendChild(f);f.submit()
    });
    $('#btn-excel').on('click', function () { var table=document.getElementById('table-instrumen');if(!table) return;if(typeof XLSX!=='undefined'){var clone=table.cloneNode(true);for(var i=0;i<clone.rows.length;i++){if(clone.rows[i].cells.length>0) clone.rows[i].deleteCell(-1);}var wb=XLSX.utils.table_to_book(clone,{sheet:"Sheet1"});XLSX.writeFile(wb,'instrumen_supervisi.xlsx');}else{var clone=table.cloneNode(true);for(var i=0;i<clone.rows.length;i++){if(clone.rows[i].cells.length>0) clone.rows[i].deleteCell(-1);}var html='<table border="1">'+clone.innerHTML+'</table>';var a=document.createElement('a');a.href='data:application/vnd.ms-excel;charset=utf-8,'+encodeURIComponent(html);a.download='instrumen_supervisi.xls';a.click();}; });
    $('#btn-pdf').on('click', function () { window.open('cetak_supervisi.php?page=instrumen', '_blank'); });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Daftar Instrumen Supervisi</h1>
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
                    <h4>Daftar Instrumen</h4>
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
                        <table class="table table-striped" id="table-instrumen">
                            <thead>
                                <tr>
                                    <th width="5%">No</th>
                                    <th>Kode</th>
                                    <th>Nama Instrumen</th>
                                    <th>Jenis</th>
                                    <th>Tujuan</th>
                                    <th>Sasaran</th>
                                    <th>Skala</th>
                                    <th>Komponen</th>
                                    <th>Indikator</th>
                                    <th>Status</th>
                                    <th>Keterangan</th>
                                    <?php if ($can_manage): ?><th width="14%">Aksi</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="text-center"></td>
                                        <td><?= htmlspecialchars((string)$r['kode_instrumen']) ?></td>
                                        <td><?= htmlspecialchars($r['nama_instrumen']) ?></td>
                                        <td><span class="badge badge-info"><?= htmlspecialchars($r['jenis_supervisi']) ?></span></td>
                                        <td><?= htmlspecialchars((string)$r['tujuan']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['sasaran']) ?></td>
                                        <td><?= htmlspecialchars($r['skala_penilaian']) ?></td>
                                        <td class="text-center"><?= (int)$r['jml_komponen'] ?></td>
                                        <td class="text-center"><?= (int)$r['jml_indikator'] ?></td>
                                        <td><span class="badge badge-<?= $r['status'] === 'Aktif' ? 'success' : 'secondary' ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                        <td><?= htmlspecialchars((string)$r['keterangan']) ?></td>
                                        <?php if ($can_manage): ?>
                                        <td>
                                            <a class="btn btn-sm btn-outline-primary" href="komponen_instrumen.php?id_instrumen=<?= (int)$r['id_instrumen'] ?>" title="Komponen"><i class="fas fa-list"></i></a>
                                            <button class="btn btn-warning btn-sm btn-edit" type="button" data-row='<?= htmlspecialchars(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>'><i class="fas fa-edit"></i></button>
                                            <button class="btn btn-info btn-sm btn-duplikat" type="button" data-id="<?= (int)$r['id_instrumen'] ?>" title="Duplikat"><i class="fas fa-copy"></i></button>
                                            <?php if ($r['status'] === 'Aktif'): ?>
                                                <button class="btn btn-secondary btn-sm btn-nonaktif" type="button" data-id="<?= (int)$r['id_instrumen'] ?>" title="Nonaktifkan"><i class="fas fa-ban"></i></button>
                                            <?php else: ?>
                                                <button class="btn btn-success btn-sm btn-aktifkan" type="button" data-id="<?= (int)$r['id_instrumen'] ?>" title="Aktifkan"><i class="fas fa-check"></i></button>
                                            <?php endif; ?>
                                            <button class="btn btn-danger btn-sm btn-hapus" type="button" data-id="<?= (int)$r['id_instrumen'] ?>"><i class="fas fa-trash"></i></button>
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
<div class="modal fade" id="modal-instrumen" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" action="instrumen_supervisi.php" id="form-instrumen">
                <input type="hidden" name="aksi" value="tambah">
                <input type="hidden" name="id_instrumen" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Instrumen</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="kode_instrumen" value="">
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>Kode Instrumen</label>
                            <div class="form-control bg-light" id="kode-display" style="height:auto;min-height:38px;">-</div>
                        </div>
                        <div class="form-group col-md-8">
                            <label>Nama Instrumen <span class="text-danger">*</span></label>
                            <select class="form-control" name="nama_instrumen" required>
                                <option value="">- Pilih Instrumen -</option>
                            </select>
                            <input type="hidden" name="nama_custom" id="sv-nama-custom-hidden" value="">
                            <input type="text" class="form-control mt-2 d-none" id="sv-nama-custom" placeholder="Ketik nama instrumen manual...">
                            <small class="text-muted" id="sv-template-hint"></small>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>Jenis Supervisi</label>
                            <select class="form-control" name="jenis_supervisi">
                                <?php foreach (sv_jenis_list() as $j): ?><option value="<?= $j ?>"><?= $j ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            <label>Skala Penilaian</label>
                            <select class="form-control" name="skala_penilaian">
                                <?php foreach (sv_skala_list() as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            <label>Status</label>
                            <select class="form-control" name="status"><option value="Aktif">Aktif</option><option value="Nonaktif">Nonaktif</option></select>
                        </div>
                    </div>
                    <div class="form-group"><label>Tujuan</label><textarea class="form-control sv-autogrow" name="tujuan" rows="5"></textarea></div>
                    <div class="form-group"><label>Sasaran</label><textarea class="form-control sv-autogrow" name="sasaran" rows="3"></textarea></div>
                    <div class="form-group"><label>Keterangan</label><textarea class="form-control sv-autogrow" name="keterangan" rows="3"></textarea></div>
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
