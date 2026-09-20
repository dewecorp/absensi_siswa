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

$css_libs = ['https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'];
$js_libs = [
    'assets/js/supervisi.js',
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
];

$pelaksanaan_list = [];
try {
    $pelaksanaan_list = $pdo->query("SELECT p.id_pelaksanaan, p.nama_guru, p.unit_bagian, p.jenis_supervisi, p.tanggal, p.temuan, p.rekomendasi, p.nilai
        FROM tb_sv_pelaksanaan p ORDER BY p.tanggal DESC, p.id_pelaksanaan DESC")->fetchAll(PDO::FETCH_ASSOC);
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
            foreach ($pelaksanaan_list as $pl) {
                if ((int)$pl['id_pelaksanaan'] === $id_pelaksanaan) {
                    $nama_guru = (string)$pl['nama_guru'];
                    $unit_bagian = (string)$pl['unit_bagian'];
                    break;
                }
            }
            try {
                $st = $pdo->prepare("SELECT id_guru FROM tb_sv_pelaksanaan WHERE id_pelaksanaan = ? LIMIT 1");
                $st->execute([$id_pelaksanaan]);
                $id_guru = (int)($st->fetchColumn() ?: 0) ?: null;
            } catch (Throwable $e) {
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

            $data = [
                $id_pelaksanaan ?: null,
                $id_guru,
                $nama_guru,
                $unit_bagian,
                trim((string)($_POST['temuan'] ?? '')),
                trim((string)($_POST['rekomendasi'] ?? '')),
                trim((string)($_POST['bentuk_tindak_lanjut'] ?? 'Pembinaan')),
                trim((string)($_POST['rencana_tindakan'] ?? '')),
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
    $where[] = 'status = ?';
    $params[] = $filter_status;
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$rows = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM tb_sv_tindak_lanjut {$whereSql} ORDER BY target_selesai ASC, id_tindak_lanjut DESC");
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
    SV.initDataTable('#table-tl');
    $('#btn-tambah').on('click', function () {
        $('#form-tl')[0].reset();
        $('#form-tl [name=aksi]').val('tambah');
        $('#form-tl [name=id_tindak_lanjut]').val('');
        $('#sv-bukti-current').hide();
        $('#modal-tl .modal-title').text('Tambah Tindak Lanjut');
        $('#modal-tl').modal('show');
    });
    $(document).on('click', '.btn-edit', function () {
        var d = $(this).data('row');
        $('#form-tl')[0].reset();
        $('#form-tl [name=aksi]').val('edit');
        Object.keys(d).forEach(function (k) {
            var el = $('#form-tl [name="' + k + '"]');
            if (el.length) { el.val(d[k]); }
        });
        if (d.bukti_file) {
            $('#sv-bukti-file').text(d.bukti_file);
            $('#sv-bukti-current').show();
        } else {
            $('#sv-bukti-current').hide();
        }
        $('#modal-tl .modal-title').text('Edit Tindak Lanjut');
        $('#modal-tl').modal('show');
    });
    $('#modal-tl').on('hidden.bs.modal', function () { $('#sv-bukti-current').hide(); });
    $(document).on('click', '.btn-hapus', function () {
        var id = $(this).data('id');
        SV.confirmDelete({ text: 'Tindak lanjut dan monitoringnya akan dihapus.', onConfirm: function () { SV.submitPost('tindak_lanjut.php', { aksi: 'hapus', id_tindak_lanjut: id }); } });
    });
    $(document).on('click', '.btn-ulang', function () {
        var id = $(this).data('id');
        SV.confirmDelete({ title: 'Jadwalkan Supervisi Ulang', text: 'Buat jadwal supervisi ulang untuk tindak lanjut ini?', onConfirm: function () { SV.submitPost('tindak_lanjut.php', { aksi: 'jadwalkan_ulang', id_tindak_lanjut: id }); } });
    });
    $('#btn-excel').on('click', function () { SV.exportExcel('table-tl', 'Tindak Lanjut Supervisi', 'tindak_lanjut', true); });
    $('#btn-pdf').on('click', function () { SV.printPdf('table-tl', 'Tindak Lanjut Supervisi', true); });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<div class="main-content">
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

            <div class="card">
                <div class="card-body">
                    <form method="GET" class="form-row align-items-end">
                        <div class="form-group col-md-4 mb-2">
                            <label class="small font-weight-bold">Status</label>
                            <select class="form-control" name="status">
                                <option value="">Semua</option>
                                <?php foreach (sv_status_tindak_lanjut_list() as $s): ?><option value="<?= $s ?>" <?= $s === $filter_status ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-4 mb-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                            <a href="tindak_lanjut.php" class="btn btn-light">Reset</a>
                        </div>
                    </form>
                </div>
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
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-tl">
                            <thead>
                                <tr>
                                    <th width="5%">No</th>
                                    <th>ID Supervisi</th>
                                    <th>Guru/Unit</th>
                                    <th>Temuan</th>
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
                                    <?php if ($can_manage): ?><th width="12%">Aksi</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <?php
                                    $badge = 'secondary';
                                    if ($r['status'] === 'Selesai') $badge = 'success';
                                    elseif ($r['status'] === 'Dalam Proses') $badge = 'warning';
                                    elseif ($r['status'] === 'Perlu Supervisi Ulang') $badge = 'danger';
                                    ?>
                                    <tr>
                                        <td class="text-center"></td>
                                        <td>#<?= (int)$r['id_pelaksanaan'] ?></td>
                                        <td><?= htmlspecialchars($r['nama_guru'] ?: ($r['unit_bagian'] ?: '-')) ?></td>
                                        <td><?= htmlspecialchars((string)$r['temuan']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['rekomendasi']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['bentuk_tindak_lanjut']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['rencana_tindakan']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['penanggung_jawab']) ?></td>
                                        <td><?= $r['target_selesai'] ? date('d/m/Y', strtotime($r['target_selesai'])) : '-' ?></td>
                                        <td><?= $r['realisasi'] ? date('d/m/Y', strtotime($r['realisasi'])) : '-' ?></td>
                                        <td>
                                            <?php if (!empty($r['bukti_file'])): ?><a href="<?= htmlspecialchars(sv_upload_url($r['bukti_file']), ENT_QUOTES) ?>" target="_blank"><i class="fas fa-file"></i> <?= htmlspecialchars(basename($r['bukti_file'])) ?></a><br><?php endif; ?>
                                            <?= htmlspecialchars((string)$r['bukti']) ?>
                                            <?php if (!empty($r['tautan_dokumen'])): ?><br><a href="<?= htmlspecialchars($r['tautan_dokumen'], ENT_QUOTES) ?>" target="_blank"><i class="fas fa-link"></i> Tautan</a><?php endif; ?>
                                        </td>
                                        <td><span class="badge badge-<?= $badge ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                        <td><?= $r['tanggal_selesai'] ? date('d/m/Y', strtotime($r['tanggal_selesai'])) : '-' ?></td>
                                        <td><?= htmlspecialchars((string)$r['catatan']) ?></td>
                                        <?php if ($can_manage): ?>
                                        <td>
                                            <button class="btn btn-warning btn-sm btn-edit" type="button" data-row='<?= htmlspecialchars(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>'><i class="fas fa-edit"></i></button>
                                            <button class="btn btn-info btn-sm btn-ulang" type="button" data-id="<?= (int)$r['id_tindak_lanjut'] ?>" title="Jadwalkan Supervisi Ulang"><i class="fas fa-redo"></i></button>
                                            <button class="btn btn-danger btn-sm btn-hapus" type="button" data-id="<?= (int)$r['id_tindak_lanjut'] ?>"><i class="fas fa-trash"></i></button>
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
                    <div class="form-group">
                        <label>ID Supervisi (Pelaksanaan)</label>
                        <select class="form-control" name="id_pelaksanaan" required>
                            <option value="">Pilih Supervisi</option>
                            <?php foreach ($pelaksanaan_list as $pl): ?>
                                <option value="<?= (int)$pl['id_pelaksanaan'] ?>">
                                    #<?= (int)$pl['id_pelaksanaan'] ?> - <?= htmlspecialchars($pl['nama_guru'] ?: ($pl['unit_bagian'] ?: '-')) ?> (<?= htmlspecialchars($pl['jenis_supervisi']) ?>, <?= $pl['tanggal'] ? date('d/m/Y', strtotime($pl['tanggal'])) : '-' ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Temuan</label><textarea class="form-control" name="temuan" rows="2"></textarea></div>
                    <div class="form-group"><label>Rekomendasi</label><textarea class="form-control" name="rekomendasi" rows="2"></textarea></div>
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
                    <div class="form-group"><label>Rencana Tindakan</label><textarea class="form-control" name="rencana_tindakan" rows="2"></textarea></div>
                    <div class="form-row">
                        <div class="form-group col-md-6"><label>Penanggung Jawab</label><input type="text" class="form-control" name="penanggung_jawab"></div>
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
