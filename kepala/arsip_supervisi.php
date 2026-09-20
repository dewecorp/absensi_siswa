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
    'assets/js/supervisi.js',
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
];

$pelaksanaan_list = [];
try {
    $pelaksanaan_list = $pdo->query("SELECT id_pelaksanaan, nama_guru, unit_bagian, jenis_supervisi, tanggal FROM tb_sv_pelaksanaan ORDER BY tanggal DESC, id_pelaksanaan DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
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
            $hasFile = !empty($_FILES['file']['name']);
            if (!$hasFile && $tautan_dokumen === '') {
                sv_flash('warning', 'Isi file atau tautan dokumen.');
            } else {
                $fileName = null;
                if ($hasFile) {
                    $hasil = sv_handle_upload($_FILES['file'], 'supervisi');
                    if (!$hasil['ok']) {
                        sv_flash('danger', $hasil['error']);
                        $fileName = false;
                    } else {
                        $fileName = $hasil['file'];
                        if ($nama_dokumen === '') {
                            $nama_dokumen = pathinfo((string)$_FILES['file']['name'], PATHINFO_FILENAME);
                        }
                    }
                } elseif ($nama_dokumen === '' && $tautan_dokumen !== '') {
                    $nama_dokumen = 'Tautan Dokumen';
                }
                if ($fileName !== false) {
                    $stmt = $pdo->prepare("INSERT INTO tb_sv_arsip (id_pelaksanaan, jenis_dokumen, nama_dokumen, file, tautan_dokumen, tanggal_upload, pengunggah, keterangan) VALUES (?,?,?,?,?,NOW(),?,?)");
                    $stmt->execute([
                        $id_pelaksanaan ?: null,
                        $jenis_dokumen,
                        $nama_dokumen,
                        $fileName,
                        $tautan_dokumen !== '' ? $tautan_dokumen : null,
                        sv_current_user_name($pdo),
                        $keterangan,
                    ]);
                    sv_log($pdo, 'Upload Arsip', $nama_dokumen);
                    sv_flash('success', 'Dokumen berhasil diunggah.');
                }
            }
        } elseif ($aksi === 'edit') {
            $id = (int)($_POST['id_arsip'] ?? 0);
            $tautan_dokumen = trim((string)($_POST['tautan_dokumen'] ?? ''));
            if ($tautan_dokumen !== '' && !preg_match('#^https?://#i', $tautan_dokumen)) {
                $tautan_dokumen = 'https://' . ltrim($tautan_dokumen, '/');
            }
            $fileName = null;
            $keepFile = true;
            if (!empty($_FILES['file']['name'])) {
                $hasil = sv_handle_upload($_FILES['file'], 'supervisi');
                if (!$hasil['ok']) {
                    sv_flash('danger', $hasil['error']);
                    $keepFile = false;
                } else {
                    $fileName = $hasil['file'];
                    try {
                        $old = $pdo->prepare("SELECT file FROM tb_sv_arsip WHERE id_arsip = ? LIMIT 1");
                        $old->execute([$id]);
                        $oldFile = (string)($old->fetchColumn() ?: '');
                        if ($oldFile !== '' && is_file(sv_upload_dir() . '/' . $oldFile)) { @unlink(sv_upload_dir() . '/' . $oldFile); }
                    } catch (Throwable $e) {}
                }
            }
            if ($keepFile) {
                if ($fileName !== null) {
                    $stmt = $pdo->prepare("UPDATE tb_sv_arsip SET id_pelaksanaan=?, jenis_dokumen=?, nama_dokumen=?, file=?, tautan_dokumen=?, keterangan=? WHERE id_arsip=?");
                    $stmt->execute([
                        (int)($_POST['id_pelaksanaan'] ?? 0) ?: null,
                        trim((string)($_POST['jenis_dokumen'] ?? '')),
                        trim((string)($_POST['nama_dokumen'] ?? '')),
                        $fileName,
                        $tautan_dokumen !== '' ? $tautan_dokumen : null,
                        trim((string)($_POST['keterangan'] ?? '')),
                        $id,
                    ]);
                } else {
                    $stmt = $pdo->prepare("UPDATE tb_sv_arsip SET id_pelaksanaan=?, jenis_dokumen=?, nama_dokumen=?, tautan_dokumen=?, keterangan=? WHERE id_arsip=?");
                    $stmt->execute([
                        (int)($_POST['id_pelaksanaan'] ?? 0) ?: null,
                        trim((string)($_POST['jenis_dokumen'] ?? '')),
                        trim((string)($_POST['nama_dokumen'] ?? '')),
                        $tautan_dokumen !== '' ? $tautan_dokumen : null,
                        trim((string)($_POST['keterangan'] ?? '')),
                        $id,
                    ]);
                }
                sv_log($pdo, 'Edit Arsip', 'ID ' . $id);
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
    SV.autoSubmitFilters('form');
    SV.initDataTable('#table-arsip');
    $('#btn-tambah').on('click', function () {
        $('#form-arsip')[0].reset();
        $('#form-arsip [name=aksi]').val('upload');
        $('#modal-arsip .modal-title').text('Upload Dokumen Supervisi');
        $('#modal-arsip').modal('show');
    });
    $(document).on('click', '.btn-edit', function () {
        var d = $(this).data('row');
        $('#form-arsip-edit')[0].reset();
        Object.keys(d).forEach(function (k) {
            var el = $('#form-arsip-edit [name="' + k + '"]');
            if (el.length) { el.val(d[k]); }
        });
        if (d.file) { $('#sv-edit-file-info').text(d.file).parent().show(); } else { $('#sv-edit-file-info').parent().hide(); }
        $('#modal-arsip-edit').modal('show');
    });
    $(document).on('click', '.btn-hapus', function () {
        var id = $(this).data('id');
        SV.confirmDelete({ text: 'Dokumen arsip akan dihapus permanen.', onConfirm: function () { SV.submitPost('arsip_supervisi.php', { aksi: 'hapus', id_arsip: id }); } });
    });
    $('#btn-excel').on('click', function () { SV.exportExcel('table-arsip', 'Arsip Supervisi', 'arsip_supervisi', true); });
    $('#btn-pdf').on('click', function () { SV.printPdf('table-arsip', 'Arsip Supervisi', true); });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<div class="main-content">
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

            <div class="alert alert-info">
                Format diizinkan: pdf, jpg, jpeg, png, gif, doc, docx, xls, xlsx. Maksimal 5MB. Isi file atau tautan dokumen. File disimpan di folder aman <code>uploads/supervisi</code> (tanpa eksekusi script).
            </div>

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
                                    <th>ID Supervisi</th>
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
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="text-center"></td>
                                        <td>#<?= (int)$r['id_pelaksanaan'] ?></td>
                                        <td><?= htmlspecialchars((string)$r['jenis_dokumen']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['nama_dokumen']) ?></td>
                                        <td><?php if (!empty($r['file'])): ?><a href="<?= htmlspecialchars(sv_upload_url($r['file']), ENT_QUOTES) ?>" target="_blank"><i class="fas fa-paperclip"></i> <?= htmlspecialchars((string)$r['file']) ?></a><?php else: ?>-<?php endif; ?></td>
                                        <td><?php if (!empty($r['tautan_dokumen'])): ?><a href="<?= htmlspecialchars($r['tautan_dokumen'], ENT_QUOTES) ?>" target="_blank"><i class="fas fa-link"></i> <?= htmlspecialchars($r['tautan_dokumen']) ?></a><?php else: ?>-<?php endif; ?></td>
                                        <td><?= $r['tanggal_upload'] ? date('d/m/Y H:i', strtotime($r['tanggal_upload'])) : '-' ?></td>
                                        <td><?= htmlspecialchars((string)$r['pengunggah']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['keterangan']) ?></td>
                                        <?php if ($can_manage): ?>
                                        <td>
                                            <button class="btn btn-warning btn-sm btn-edit" type="button" data-row='<?= htmlspecialchars(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>'><i class="fas fa-edit"></i></button>
                                            <button class="btn btn-danger btn-sm btn-hapus" type="button" data-id="<?= (int)$r['id_arsip'] ?>"><i class="fas fa-trash"></i></button>
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
                    <div class="form-group"><label>File</label><input type="file" class="form-control-file" name="file" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx"><small class="text-muted">Kosongkan jika hanya pakai tautan.</small></div>
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
                    <div class="form-group"><label>File Saat Ini</label><p class="mb-1"><small class="text-muted" id="sv-edit-file-info"></small></p><input type="file" class="form-control-file" name="file" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx"><small class="text-muted">Kosongkan jika tidak ganti file.</small></div>
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
