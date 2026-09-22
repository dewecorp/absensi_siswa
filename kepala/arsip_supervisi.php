<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$page_title = 'Arsip / Bukti Supervisi';
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

$pelaksanaan_list = [];
try {
    $pelaksanaan_list = $pdo->query("SELECT id_pelaksanaan, nama_guru, unit_bagian, jenis_supervisi, tanggal FROM tb_sv_pelaksanaan ORDER BY tanggal DESC, id_pelaksanaan DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

if (!function_exists('sv_normalize_files')) {
    function sv_normalize_files($f): array
    {
        $out = [];
        if (!is_array($f) || !isset($f['name'])) return $out;
        if (is_array($f['name'])) {
            foreach ($f['name'] as $i => $name) {
                if ($name === '' || (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $out[] = ['name' => $name, 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '', 'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $f['size'][$i] ?? 0];
            }
        } else {
            if ($f['name'] !== '' && (int)$f['error'] !== UPLOAD_ERR_NO_FILE) $out[] = $f;
        }
        return $out;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $aksi = (string)($_POST['aksi'] ?? '');
    try {
        if ($aksi === 'upload') {
            $id_pelaksanaan = (int)($_POST['id_pelaksanaan'] ?? 0);
            $jenis_dokumen = trim((string)($_POST['jenis_dokumen'] ?? 'Dokumen pendukung'));
            $nama_dokumen = trim((string)($_POST['nama_dokumen'] ?? ''));
            $keterangan = trim((string)($_POST['keterangan'] ?? ''));
            $tautan_dokumen = trim((string)($_POST['tautan_dokumen'] ?? ''));
            if ($tautan_dokumen !== '' && !preg_match('#^https?://#i', $tautan_dokumen)) {
                $tautan_dokumen = 'https://' . ltrim($tautan_dokumen, '/');
            }
            $files = sv_normalize_files($_FILES['file'] ?? null);
            $folder = 'umum';
            if ($id_pelaksanaan) {
                try {
                    $pq = $pdo->prepare("SELECT nama_guru, unit_bagian, jenis_supervisi FROM tb_sv_pelaksanaan WHERE id_pelaksanaan = ? LIMIT 1");
                    $pq->execute([$id_pelaksanaan]);
                    $pl = $pq->fetch(PDO::FETCH_ASSOC) ?: [];
                    $nmGuru = (($pl['jenis_supervisi'] ?? '') === 'Manajerial') ? ($pl['unit_bagian'] ?? '') : ($pl['nama_guru'] ?? '');
                    $folder = sv_folder_name((string)$nmGuru);
                } catch (Throwable $e) {}
            }
            $stmtArsip = $pdo->prepare("INSERT INTO tb_sv_arsip (id_pelaksanaan, jenis_dokumen, nama_dokumen, file, tautan_dokumen, tanggal_upload, pengunggah, keterangan) VALUES (?,?,?,?,?,NOW(),?,?)");
            $pengunggah = sv_current_user_name($pdo);
            $sukses = 0;
            $errors = [];
            foreach ($files as $f) {
                $hasil = sv_handle_upload($f, 'supervisi', $folder);
                if (!$hasil['ok']) { $errors[] = $hasil['error']; continue; }
                // Nama hanya berdasarkan nama file asli, biar beda tiap file
                $nm = pathinfo((string)$f['name'], PATHINFO_FILENAME);
                $stmtArsip->execute([$id_pelaksanaan ?: null, $jenis_dokumen, $nm, $hasil['file'], $tautan_dokumen !== '' ? $tautan_dokumen : null, $pengunggah, $keterangan]);
                $sukses++;
            }
            if (!$files && $tautan_dokumen !== '') {
                $nm = $nama_dokumen !== '' ? $nama_dokumen : 'Tautan Dokumen';
                $stmtArsip->execute([$id_pelaksanaan ?: null, $jenis_dokumen, $nm, null, $tautan_dokumen, $pengunggah, $keterangan]);
                $sukses++;
            }
            if ($sukses > 0) {
                sv_log($pdo, 'Upload Arsip', $sukses . ' dokumen');
                sv_flash('success', $sukses . ' dokumen berhasil diunggah.');
                if ($errors) { sv_flash('warning', implode(' ', array_unique($errors))); }
            } elseif ($errors) {
                sv_flash('danger', implode(' ', array_unique($errors)));
            } else {
                sv_flash('warning', 'Isi file atau tautan dokumen.');
            }
        } elseif ($aksi === 'edit') {
            $id = (int)($_POST['id_arsip'] ?? 0);
            $idPel = (int)($_POST['id_pelaksanaan'] ?? 0) ?: null;
            $jenis = trim((string)($_POST['jenis_dokumen'] ?? ''));
            $nama = trim((string)($_POST['nama_dokumen'] ?? ''));
            $keterangan = trim((string)($_POST['keterangan'] ?? ''));
            $tautan_dokumen = trim((string)($_POST['tautan_dokumen'] ?? ''));
            if ($tautan_dokumen !== '' && !preg_match('#^https?://#i', $tautan_dokumen)) {
                $tautan_dokumen = 'https://' . ltrim($tautan_dokumen, '/');
            }
            $files = sv_normalize_files($_FILES['file'] ?? null);
            $folder = 'umum';
            if ($idPel) {
                try {
                    $pq = $pdo->prepare("SELECT nama_guru, unit_bagian, jenis_supervisi FROM tb_sv_pelaksanaan WHERE id_pelaksanaan = ? LIMIT 1");
                    $pq->execute([$idPel]);
                    $pl = $pq->fetch(PDO::FETCH_ASSOC) ?: [];
                    $nmGuru = (($pl['jenis_supervisi'] ?? '') === 'Manajerial') ? ($pl['unit_bagian'] ?? '') : ($pl['nama_guru'] ?? '');
                    $folder = sv_folder_name((string)$nmGuru);
                } catch (Throwable $e) {}
            }
            $pengunggah = sv_current_user_name($pdo);
            if ($files) {
                // Simpan semua file baru (multi)
                foreach ($files as $f) {
                    $hasil = sv_handle_upload($f, 'supervisi', $folder);
                    if (!$hasil['ok']) {
                        sv_flash('danger', $hasil['error']);
                    } else {
                        $nm = pathinfo((string)$f['name'], PATHINFO_FILENAME);
                        $stmtArsip->execute([$idPel, $jenis, $nm, $hasil['file'], $tautan_dokumen !== '' ? $tautan_dokumen : null, $pengunggah, $keterangan]);
                    }
                }
                // Hapus file lama
                try {
                    $old = $pdo->prepare("SELECT file FROM tb_sv_arsip WHERE id_arsip = ? LIMIT 1");
                    $old->execute([$id]);
                    $oldFile = (string)($old->fetchColumn() ?: '');
                    if ($oldFile !== '' && is_file(sv_upload_dir() . '/' . $oldFile)) { @unlink(sv_upload_dir() . '/' . $oldFile); }
                } catch (Throwable $e) {}
            } else {
                // Hanya update tanpa upload file
                $stmt = $pdo->prepare("UPDATE tb_sv_arsip SET id_pelaksanaan=?, jenis_dokumen=?, nama_dokumen=?, tautan_dokumen=?, keterangan=? WHERE id_arsip=?");
                $stmt->execute([$idPel, $jenis, $nama, $tautan_dokumen !== '' ? $tautan_dokumen : null, $keterangan, $id]);
            }
            sv_log($pdo, 'Edit Arsip', 'ID ' . $id);
            sv_flash('success', 'Data arsip berhasil diperbarui.');
        } elseif ($aksi === 'hapus') {
            $id = (int)($_POST['id_arsip'] ?? 0);
            $stmt = $pdo->prepare("SELECT file FROM tb_sv_arsip WHERE id_arsip = ? LIMIT 1");
            $stmt->execute([$id]);
            $file = (string)($stmt->fetchColumn() ?: '');
            if ($file !== '') {
                $path = sv_upload_dir() . '/' . $file;
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            $pdo->prepare("DELETE FROM tb_sv_arsip WHERE id_arsip = ?")->execute([$id]);
            sv_log($pdo, 'Hapus Arsip', 'ID ' . $id);
            sv_flash('success', 'Arsip berhasil dihapus.');
        }
    } catch (Throwable $e) {
        sv_flash('danger', 'Gagal memproses data: ' . $e->getMessage());
    }
    redirect('arsip_supervisi.php');
}

$filter_jenis = trim((string)($_GET['jenis_dokumen'] ?? ''));
$where = [];
$params = [];
if ($filter_jenis !== '') {
    $where[] = 'a.jenis_dokumen = ?';
    $params[] = $filter_jenis;
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$rows = [];
try {
    $stmt = $pdo->prepare("SELECT a.*, p.nama_guru, p.unit_bagian, p.jenis_supervisi
        FROM tb_sv_arsip a LEFT JOIN tb_sv_pelaksanaan p ON p.id_pelaksanaan = a.id_pelaksanaan
        {$whereSql} ORDER BY a.tanggal_upload DESC, a.id_arsip DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$js_page = [];
$flash = sv_render_flash_js();
if ($flash !== '') {
    $js_page[] = $flash;
}
$js_page[] = <<<'JS'
$(document).ready(function () {
    document.querySelectorAll('form[method="GET"]').forEach(function(f){f.querySelectorAll('select, input[type="date"]').forEach(function(el){el.addEventListener('change',function(){f.submit();});});});
    var dttablearsip=$('#table-arsip').DataTable({language:{search:'Cari:',lengthMenu:'Tampilkan _MENU_ data',info:'Menampilkan _START_ sampai _END_ dari _TOTAL_ data',infoEmpty:'Tidak ada data',zeroRecords:'Data tidak ditemukan',paginate:{first:'Awal',last:'Akhir',next:'Berikutnya',previous:'Sebelumnya'}},pageLength:10,order:[],columnDefs:[],responsive:false});dttablearsip.on('order.dt search.dt draw.dt',function(){var info=dttablearsip.page.info();dttablearsip.column(0,{search:'applied',order:'applied'}).nodes().each(function(cell,i){if(cell) cell.innerHTML=info.page*info.length+i+1;});}).draw();
    $('#btn-tambah').on('click', function () {
        $('#form-arsip')[0].reset();
        $('#sv-file-preview').empty();
        $('#form-arsip [name=aksi]').val('upload');
        $('#modal-arsip .modal-title').text('Upload Dokumen Supervisi');
        $('#modal-arsip').modal('show');
    });
    $(document).on('change', '#sv-file-input', function () {
        var wrap = document.getElementById('sv-file-preview');
        if (!wrap) return;
        wrap.innerHTML = '';
        Array.prototype.forEach.call(this.files, function (file) {
            var a = document.createElement('a');
            a.href = 'javascript:void(0)';
            a.className = 'mr-2 mb-2';
            if (file.type && file.type.indexOf('image/') === 0) {
                var img = document.createElement('img');
                img.src = URL.createObjectURL(file);
                img.style.width = '90px'; img.style.height = '90px'; img.style.objectFit = 'cover';
                img.className = 'border rounded';
                img.title = file.name;
                a.appendChild(img);
            } else {
                var ic = document.createElement('div');
                ic.className = 'border rounded d-flex align-items-center justify-content-center text-secondary';
                ic.style.width = '90px'; ic.style.height = '90px';
                ic.innerHTML = '<i class="fas fa-file fa-2x"></i>';
                ic.title = file.name;
                a.appendChild(ic);
            }
            wrap.appendChild(a);
        });
    });
    function svFilePreviewHtml(url, isImg, name) {
        if (isImg) return '<a href="' + url + '" target="_blank"><img src="' + url + '" class="border rounded" style="max-width:160px;max-height:160px"></a>';
        return '<a href="' + url + '" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-file"></i> ' + (name || 'Lihat Dokumen') + '</a>';
    }
    $(document).on('click', '.btn-edit', function () {
        var d = $(this).data('row');
        if (typeof d === 'string') { try { d = JSON.parse(d); } catch (e) { d = null; } }
        if (!d || typeof d !== 'object') { d = {}; }
        var form = document.getElementById('form-arsip-edit');
        if (form) { form.reset(); }
        Object.keys(d).forEach(function (k) {
            var el = $('#form-arsip-edit [name="' + k + '"]');
            if (el.length && k !== 'file') { el.val(d[k]); }
        });
        var prev = document.getElementById('sv-edit-file-preview');
        if (prev) prev.innerHTML = '';
        if (d.file) {
            $('#sv-edit-file-info').text(d.file).parent().show();
            var ext = (String(d.file).split('.').pop() || '').toLowerCase();
            var isImg = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].indexOf(ext) >= 0;
            var segs = String(d.file).split('/').map(encodeURIComponent).join('/');
            if (prev) prev.innerHTML = svFilePreviewHtml('../uploads/supervisi/' + segs, isImg, '');
        } else {
            $('#sv-edit-file-info').parent().hide();
        }
        $('#modal-arsip-edit').modal('show');
    });
    $(document).on('change', '#sv-edit-file-input', function () {
        var prev = document.getElementById('sv-edit-file-preview');
        if (!prev) return;
        prev.innerHTML = '';
        Array.prototype.forEach.call(this.files, function (f) {
            var a = document.createElement('a');
            a.href = 'javascript:void(0)';
            a.className = 'mr-2 mb-2';
            if (f.type && f.type.indexOf('image/') === 0) {
                var img = document.createElement('img');
                img.src = URL.createObjectURL(f);
                img.style.width = '90px'; img.style.height = '90px'; img.style.objectFit = 'cover';
                img.className = 'border rounded';
                img.title = f.name;
                a.appendChild(img);
            } else {
                var ic = document.createElement('div');
                ic.className = 'border rounded d-flex align-items-center justify-content-center text-secondary';
                ic.style.width = '90px'; ic.style.height = '90px';
                ic.innerHTML = '<i class="fas fa-file fa-2x"></i>';
                ic.title = f.name;
                a.appendChild(ic);
            }
            prev.appendChild(a);
        });
    });
    $(document).on('click', '.btn-hapus', function () {
        var id = $(this).data('id');
        Swal.fire({title:'Konfirmasi Hapus',text:'Dokumen arsip akan dihapus permanen.',icon:'warning',showCancelButton:true,confirmButtonColor:'#d33',cancelButtonColor:'#6c757d',confirmButtonText:'Ya, Hapus!',cancelButtonText:'Batal'}).then(function(r){if(r.isConfirmed){var f=document.createElement('form');f.method='POST';f.action='arsip_supervisi.php';var fields={aksi: 'hapus', id_arsip: id};Object.keys(fields).forEach(function(k){var i=document.createElement('input');i.type='hidden';i.name=k;i.value=fields[k];f.appendChild(i);});document.body.appendChild(f);f.submit();}})
    });
    $('#btn-excel').on('click', function () { var table=document.getElementById('table-arsip');if(!table) return;if(typeof XLSX!=='undefined'){var clone=table.cloneNode(true);for(var i=0;i<clone.rows.length;i++){if(clone.rows[i].cells.length>0) clone.rows[i].deleteCell(-1);}var wb=XLSX.utils.table_to_book(clone,{sheet:"Sheet1"});XLSX.writeFile(wb,'arsip_supervisi.xlsx');}else{var clone=table.cloneNode(true);for(var i=0;i<clone.rows.length;i++){if(clone.rows[i].cells.length>0) clone.rows[i].deleteCell(-1);}var html='<table border="1">'+clone.innerHTML+'</table>';var a=document.createElement('a');a.href='data:application/vnd.ms-excel;charset=utf-8,'+encodeURIComponent(html);a.download='arsip_supervisi.xls';a.click();}; });
    $('#btn-pdf').on('click', function () { window.open('cetak_supervisi.php?page=arsip', '_blank'); });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<div class="main-content">
    <style>
    #table-arsip th:last-child,#table-arsip td:last-child{white-space:nowrap;text-align:center}
    #table-arsip td:last-child .btn{margin:1px 2px}
    </style>
    <section class="section">
        <div class="section-header">
            <h1>Arsip / Bukti Supervisi</h1>
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
                        <div class="form-group col-md-4 mb-2">
                            <label class="small font-weight-bold">Jenis Dokumen</label>
                            <select class="form-control" name="jenis_dokumen">
                                <option value="">Semua</option>
                                <?php foreach (sv_jenis_dokumen_list() as $jd): ?><option value="<?= $jd ?>" <?= $jd === $filter_jenis ? 'selected' : '' ?>><?= $jd ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-4 mb-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                            <a href="arsip_supervisi.php" class="btn btn-light">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4>Daftar Arsip / Bukti</h4>
                    <div class="card-header-action">
                        <button class="btn btn-success" id="btn-excel" type="button"><i class="fas fa-file-excel"></i> Excel</button>
                        <button class="btn btn-warning" id="btn-pdf" type="button"><i class="fas fa-file-pdf"></i> PDF</button>
                        <?php if ($can_manage): ?>
                        <button class="btn btn-primary" id="btn-tambah" type="button"><i class="fas fa-upload"></i> Upload</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-arsip">
                            <thead>
                                <tr>
                                    <th width="5%">No</th>
                                    <th>Guru yang Disupervisi</th>
                                    <th>Jenis Dokumen</th>
                                    <th>Nama Dokumen</th>
                                    <th>File</th>
                                    <th>Tautan Dokumen</th>
                                    <th>Tanggal Upload</th>
                                    <th>Pengunggah</th>
                                    <th>Keterangan</th>
                                    <?php if ($can_manage): ?><th width="10%">Aksi</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): $namaSup = ($r['jenis_supervisi'] === 'Manajerial') ? ($r['unit_bagian'] ?: '-') : ($r['nama_guru'] ?: '-'); ?>
                                    <tr>
                                        <td class="text-center"></td>
                                        <td><?= htmlspecialchars((string)$namaSup) ?></td>
                                        <td><?= htmlspecialchars((string)$r['jenis_dokumen']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['nama_dokumen']) ?></td>
                                        <td class="text-center"><?php if (!empty($r['file'])):
                                            $ext = strtolower(pathinfo((string)$r['file'], PATHINFO_EXTENSION));
                                            $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
                                            $url = sv_upload_url($r['file']);
                                            if ($isImg): ?>
                                                <a href="<?= htmlspecialchars($url, ENT_QUOTES) ?>" target="_blank" title="Lihat foto"><img src="<?= htmlspecialchars($url, ENT_QUOTES) ?>" class="border rounded" style="width:56px;height:56px;object-fit:cover"></a>
                                            <?php else: ?>
                                                <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($url, ENT_QUOTES) ?>" target="_blank" title="Lihat dokumen"><i class="fas fa-eye"></i></a>
                                            <?php endif; ?>
                                        <?php else: ?>-<?php endif; ?></td>
                                        <td><?php if (!empty($r['tautan_dokumen'])): ?><a href="<?= htmlspecialchars($r['tautan_dokumen'], ENT_QUOTES) ?>" target="_blank"><i class="fas fa-link"></i> <?= htmlspecialchars($r['tautan_dokumen']) ?></a><?php else: ?>-<?php endif; ?></td>
                                        <td><?= $r['tanggal_upload'] ? date('d/m/Y H:i', strtotime($r['tanggal_upload'])) : '-' ?></td>
                                        <td><?= htmlspecialchars((string)$r['pengunggah']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['keterangan']) ?></td>
                                        <?php if ($can_manage): ?>
                                        <td style="white-space:nowrap">
                                            <div class="d-inline-flex align-items-center">
                                            <button class="btn btn-warning btn-sm btn-edit mr-1" type="button" data-row='<?= htmlspecialchars(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES) ?>'><i class="fas fa-edit"></i></button>
                                            <button class="btn btn-danger btn-sm btn-hapus" type="button" data-id="<?= (int)$r['id_arsip'] ?>"><i class="fas fa-trash"></i></button>
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
<div class="modal fade" id="modal-arsip" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="arsip_supervisi.php" id="form-arsip" enctype="multipart/form-data">
                <input type="hidden" name="aksi" value="upload">
                <div class="modal-header">
                    <h5 class="modal-title">Upload Dokumen Supervisi</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>ID Supervisi</label>
                        <select class="form-control" name="id_pelaksanaan">
                            <option value="">- Tanpa Kaitan -</option>
                            <?php foreach ($pelaksanaan_list as $pl): ?>
                                <option value="<?= (int)$pl['id_pelaksanaan'] ?>">#<?= (int)$pl['id_pelaksanaan'] ?> - <?= htmlspecialchars($pl['nama_guru'] ?: ($pl['unit_bagian'] ?: '-')) ?> (<?= htmlspecialchars($pl['jenis_supervisi']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Jenis Dokumen</label>
                        <select class="form-control" name="jenis_dokumen">
                            <?php foreach (sv_jenis_dokumen_list() as $jd): ?><option value="<?= $jd ?>"><?= $jd ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Nama Dokumen</label><input type="text" class="form-control" name="nama_dokumen"></div>
                    <div class="form-group">
                        <label>File <small class="text-muted">(bisa pilih beberapa sekaligus)</small></label>
                        <input type="file" class="form-control-file" name="file[]" id="sv-file-input" multiple accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx">
                        <small class="text-muted">Kosongkan jika hanya pakai tautan. Maks 5MB/file.</small>
                        <div id="sv-file-preview" class="d-flex flex-wrap mt-2"></div>
                    </div>
                    <div class="form-group"><label>Tautan Dokumen</label><input type="url" class="form-control" name="tautan_dokumen" placeholder="https://drive.google.com/... atau https://..."><small class="text-muted">Isi file atau tautan, salah satu wajib.</small></div>
                    <div class="form-group"><label>Keterangan</label><textarea class="form-control" name="keterangan" rows="2"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-arsip-edit" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="arsip_supervisi.php" id="form-arsip-edit" enctype="multipart/form-data">
                <input type="hidden" name="aksi" value="edit">
                <input type="hidden" name="id_arsip" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Arsip</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>ID Supervisi</label>
                        <select class="form-control" name="id_pelaksanaan">
                            <option value="">- Tanpa Kaitan -</option>
                            <?php foreach ($pelaksanaan_list as $pl): ?>
                                <option value="<?= (int)$pl['id_pelaksanaan'] ?>">#<?= (int)$pl['id_pelaksanaan'] ?> - <?= htmlspecialchars($pl['nama_guru'] ?: ($pl['unit_bagian'] ?: '-')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Jenis Dokumen</label>
                        <select class="form-control" name="jenis_dokumen">
                            <?php foreach (sv_jenis_dokumen_list() as $jd): ?><option value="<?= $jd ?>"><?= $jd ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Nama Dokumen</label><input type="text" class="form-control" name="nama_dokumen"></div>
                    <div class="form-group">
                        <label>File <small class="text-muted">(pilih beberapa untuk menambah foto baru)</small></label>
                        <p class="mb-1"><small class="text-muted" id="sv-edit-file-info"></small></p>
                        <div id="sv-edit-file-preview" class="d-flex flex-wrap mb-2"></div>
                        <input type="file" class="form-control-file" name="file[]" id="sv-edit-file-input" multiple accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx">
                        <small class="text-muted">File pertama mengganti file lama, sisanya ditambahkan. Kosongkan jika tidak ganti.</small>
                    </div>
                    <div class="form-group"><label>Tautan Dokumen</label><input type="url" class="form-control" name="tautan_dokumen" placeholder="https://..."></div>
                    <div class="form-group"><label>Keterangan</label><textarea class="form-control" name="keterangan" rows="2"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php include '../templates/footer.php'; ?>
