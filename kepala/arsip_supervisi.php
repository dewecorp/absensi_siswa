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

$css_libs = [];
$js_libs = [
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
                // Nama dokumen ikut field input, fallback ke nama file
                $nm = ($nama_dokumen !== '') ? $nama_dokumen : pathinfo((string)$f['name'], PATHINFO_FILENAME);
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
            // Update metadata baris yang diedit (file lama tidak dihapus)
            $stmt = $pdo->prepare("UPDATE tb_sv_arsip SET id_pelaksanaan=?, jenis_dokumen=?, nama_dokumen=?, tautan_dokumen=?, keterangan=? WHERE id_arsip=?");
            $stmt->execute([$idPel ?: null, $jenis, $nama, $tautan_dokumen !== '' ? $tautan_dokumen : null, $keterangan, $id]);
            $inserted = 0;
            $errors = [];
            if ($files) {
                $ins = $pdo->prepare("INSERT INTO tb_sv_arsip (id_pelaksanaan, jenis_dokumen, nama_dokumen, file, tautan_dokumen, tanggal_upload, pengunggah, keterangan) VALUES (?,?,?,?,?,NOW(),?,?)");
                foreach ($files as $f) {
                    $hasil = sv_handle_upload($f, 'supervisi', $folder);
                    if (!$hasil['ok']) { $errors[] = $hasil['error']; continue; }
                    $nm = ($nama !== '') ? $nama : pathinfo((string)$f['name'], PATHINFO_FILENAME);
                    $ins->execute([$idPel, $jenis, $nm, $hasil['file'], $tautan_dokumen !== '' ? $tautan_dokumen : null, $pengunggah, $keterangan]);
                    $inserted++;
                }
            }
            sv_log($pdo, 'Edit Arsip', 'ID ' . $id);
            if ($inserted > 0) {
                sv_flash('success', $inserted . ' foto berhasil ditambahkan.');
            } elseif ($errors) {
                sv_flash('danger', implode(' ', array_unique($errors)));
            } else {
                sv_flash('success', 'Data arsip berhasil diperbarui.');
            }
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
            if (el.length && k !== 'file') { 
                el.val(d[k]);
                if (el.is('select')) {
                    el.trigger('change');
                }
            }
        });
        var prev = document.getElementById('sv-edit-file-preview');
        if (prev) prev.innerHTML = '';
        var list = (d.files && d.files.length) ? d.files : (d.file ? [d.file] : []);
        if (list.length) {
            $('#sv-edit-file-info').text(list.length + ' foto/dokumen tersimpan').parent().show();
            list.forEach(function (fp) {
                var ext = (String(fp).split('.').pop() || '').toLowerCase();
                var isImg = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].indexOf(ext) >= 0;
                var url = '../uploads/supervisi/' + String(fp).split('/').map(encodeURIComponent).join('/');
                var a = document.createElement('a');
                a.href = url; a.target = '_blank'; a.className = 'mr-2 mb-1';
                if (isImg) {
                    var img = document.createElement('img');
                    img.src = url; img.className = 'border rounded';
                    img.style.width = '60px'; img.style.height = '60px'; img.style.objectFit = 'cover';
                    a.appendChild(img);
                } else {
                    var ic = document.createElement('span');
                    ic.className = 'btn btn-sm btn-outline-secondary';
                    ic.innerHTML = '<i class="fas fa-file"></i>';
                    a.appendChild(ic);
                }
                if (prev) prev.appendChild(a);
            });
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
    $('#btn-excel').on('click', function () {
        var data = [];
        data.push(['No', 'Guru', 'Jenis Dokumen', 'Nama Dokumen', 'Jumlah File', 'Tanggal Upload', 'Pengunggah']);
        
        $('.col-12.col-md-6.col-lg-4').each(function(i) {
            var card = $(this);
            var guru = card.find('h6.font-weight-bold').text().trim();
            var jenisDok = card.find('.badge-info').first().text().trim();
            var namaDok = card.find('.fa-file-alt').parent().next().text().trim();
            var jumlahFile = card.find('.badge-light.border').first().text().trim();
            var tanggal = card.find('.far.fa-calendar-alt').parent().text().trim();
            var pengunggah = card.find('.fa-user').parent().next().text().trim();
            
            data.push([i+1, guru, jenisDok, namaDok, jumlahFile, tanggal, pengunggah]);
        });
        
        if(typeof XLSX !== 'undefined') {
            var ws = XLSX.utils.aoa_to_sheet(data);
            var wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Arsip Supervisi");
            XLSX.writeFile(wb, 'arsip_supervisi.xlsx');
        } else {
            alert('Library XLSX tidak tersedia');
        }
    });
    $('#btn-pdf').on('click', function () { window.open('cetak_supervisi.php?page=arsip', '_blank'); });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
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
                    <?php if (empty($rows)): ?>
                        <div class="alert alert-info">Tidak ada data arsip supervisi.</div>
                    <?php else: ?>
                        <?php 
                        // Grup foto berdasarkan id_pelaksanaan menjadi 1 baris per supervisi
                        $groups = [];
                        foreach ($rows as $r) {
                            $k = $r['id_pelaksanaan'] ?: 'umum';
                            if (!isset($groups[$k])) {
                                $groups[$k] = ['info' => $r, 'files' => []];
                            }
                            $groups[$k]['files'][] = $r;
                        }
                        ?>
                        <div class="row">
                            <?php foreach ($groups as $k => $g): 
                                $row = $g['info']; 
                                $namaSup = ($row['jenis_supervisi'] === 'Manajerial') ? ($row['unit_bagian'] ?? '-') : ($row['nama_guru'] ?? '-'); 
                                $fileList = []; 
                                foreach ($g['files'] as $fr) { 
                                    if (!empty($fr['file'])) $fileList[] = $fr['file']; 
                                }
                            ?>
                                <div class="col-12 col-md-6 col-lg-4 d-flex align-items-stretch">
                                    <div class="card w-100 border-0 shadow-sm hover-shadow transition" style="border-radius: 12px; overflow: hidden; background: #fff; border: 1px solid #e2e8f0 !important;">
                                        <div class="card-body p-3 d-flex flex-column justify-content-between">
                                            <div>
                                                <!-- Top Bar: Jenis Dokumen -->
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <span class="badge badge-info px-2 py-1 font-weight-bold" style="font-size: 12px; border-radius: 6px;">
                                                        <i class="fas fa-folder-open mr-1"></i> <?= htmlspecialchars((string)$row['jenis_dokumen']) ?>
                                                    </span>
                                                    <span class="badge badge-light border text-dark font-weight-bold px-2 py-1" style="border-radius: 4px; font-size: 11px;">
                                                        <i class="fas fa-images mr-1"></i> <?= count($fileList) ?> file
                                                    </span>
                                                </div>

                                                <!-- Nama Guru/Unit -->
                                                <h6 class="font-weight-bold text-dark mb-2" style="font-size: 15px; line-height: 1.4; min-height: 42px;">
                                                    <?= htmlspecialchars((string)$namaSup) ?>
                                                </h6>

                                                <!-- Nama Dokumen -->
                                                <div class="p-2 mb-2 rounded border-0" style="background: #f8fafc; font-size: 12px; line-height: 1.5;">
                                                    <i class="fas fa-file-alt mr-1 text-primary"></i> <strong>Nama Dokumen:</strong><br>
                                                    <span class="text-dark"><?= htmlspecialchars((string)$row['nama_dokumen']) ?></span>
                                                </div>

                                                <!-- Preview File/Foto -->
                                                <?php if ($fileList): ?>
                                                    <div class="mb-2">
                                                        <small class="text-muted d-block mb-2" style="font-size: 11px;">File/Foto:</small>
                                                        <div class="d-flex flex-wrap" style="gap: 6px;">
                                                            <?php foreach ($g['files'] as $i => $fr):
                                                                if (empty($fr['file'])) continue;
                                                                $ext = strtolower(pathinfo((string)$fr['file'], PATHINFO_EXTENSION));
                                                                $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
                                                                $url = sv_upload_url($fr['file']);
                                                            ?>
                                                                <a href="<?= htmlspecialchars($url, ENT_QUOTES) ?>" target="_blank" class="border rounded" style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; overflow: hidden; background: #f8fafc;">
                                                                    <?php if ($isImg): ?>
                                                                        <img src="<?= htmlspecialchars($url, ENT_QUOTES) ?>" alt="Foto" style="width: 100%; height: 100%; object-fit: cover;">
                                                                    <?php else: ?>
                                                                        <i class="fas fa-file text-secondary" style="font-size: 24px;"></i>
                                                                    <?php endif; ?>
                                                                </a>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>

                                                <!-- Tautan Dokumen -->
                                                <?php if (!empty($row['tautan_dokumen'])): ?>
                                                    <div class="mb-2">
                                                        <a href="<?= htmlspecialchars($row['tautan_dokumen'], ENT_QUOTES) ?>" target="_blank" class="btn btn-sm btn-outline-info w-100" style="font-size: 11px; border-radius: 6px;">
                                                            <i class="fas fa-link mr-1"></i> Buka Tautan Dokumen
                                                        </a>
                                                    </div>
                                                <?php endif; ?>

                                                <!-- Info Upload -->
                                                <div class="small text-muted mb-2" style="font-size: 11px;">
                                                    <div class="d-flex justify-content-between mb-1">
                                                        <span><i class="far fa-calendar-alt mr-1"></i> <?= $row['tanggal_upload'] ? date('d/m/Y H:i', strtotime($row['tanggal_upload'])) : '-' ?></span>
                                                    </div>
                                                    <div>
                                                        <i class="fas fa-user mr-1"></i> <strong>Pengunggah:</strong><br>
                                                        <span class="text-dark"><?= htmlspecialchars((string)$row['pengunggah']) ?></span>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Card Actions Footer -->
                                            <?php if ($can_manage): ?>
                                                <div class="pt-2 border-top d-flex align-items-center justify-content-between" style="border-color: #f1f5f9 !important;">
                                                    <button type="button" class="btn btn-warning btn-sm px-3 font-weight-bold btn-edit" style="border-radius: 6px; font-size: 12px;" data-row='<?= htmlspecialchars(json_encode(['id_arsip'=>$row['id_arsip'],'id_pelaksanaan'=>$row['id_pelaksanaan'],'jenis_dokumen'=>$row['jenis_dokumen'],'nama_dokumen'=>$row['nama_dokumen'],'file'=>$row['file'],'files'=>$fileList,'tautan_dokumen'=>$row['tautan_dokumen'],'keterangan'=>$row['keterangan']], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES) ?>'>
                                                        <i class="fas fa-edit mr-1"></i> Edit
                                                    </button>
                                                    <button type="button" class="btn btn-danger btn-sm px-3 font-weight-bold btn-hapus" style="border-radius: 6px; font-size: 12px;" data-id="<?= (int)$row['id_arsip'] ?>">
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
