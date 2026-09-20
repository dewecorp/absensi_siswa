<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$page_title = 'Komponen Instrumen';
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

$instrumen_list = [];
try {
    if ((int)$pdo->query("SELECT COUNT(*) FROM tb_sv_instrumen")->fetchColumn() === 0) {
        sv_seed_instrumen_templates($pdo);
    }
    $instrumen_list = $pdo->query("SELECT id_instrumen, kode_instrumen, nama_instrumen, jenis_supervisi, skala_penilaian FROM tb_sv_instrumen ORDER BY kode_instrumen ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$id_instrumen = (int)($_GET['id_instrumen'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_instrumen = (int)($_POST['id_instrumen'] ?? $id_instrumen);
}
$instrumen = null;
if ($id_instrumen > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM tb_sv_instrumen WHERE id_instrumen = ? LIMIT 1");
        $stmt->execute([$id_instrumen]);
        $instrumen = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $aksi = (string)($_POST['aksi'] ?? '');
    try {
        if ($aksi === 'tambah' || $aksi === 'edit') {
            $targetInstrumen = (int)($_POST['id_instrumen'] ?? $id_instrumen);
            if ($targetInstrumen <= 0) {
                sv_flash('warning', 'Pilih instrumen terlebih dahulu.');
                redirect('komponen_instrumen.php' . ($id_instrumen ? '?id_instrumen=' . $id_instrumen : ''));
            }
            $data = [
                $targetInstrumen,
                trim((string)($_POST['kode_komponen'] ?? '')),
                trim((string)($_POST['nama_komponen'] ?? '')),
                (float)($_POST['bobot'] ?? 1),
                (int)($_POST['urutan'] ?? 0),
                trim((string)($_POST['keterangan'] ?? '')),
            ];
            if ($data[2] === '') {
                sv_flash('warning', 'Nama komponen wajib diisi.');
            } elseif ($aksi === 'tambah') {
                $stmt = $pdo->prepare("INSERT INTO tb_sv_komponen (id_instrumen, kode_komponen, nama_komponen, bobot, urutan, keterangan) VALUES (?,?,?,?,?,?)");
                $stmt->execute($data);
                sv_log($pdo, 'Tambah Komponen', $data[2]);
                sv_flash('success', 'Komponen berhasil ditambahkan.');
            } else {
                $id = (int)($_POST['id_komponen'] ?? 0);
                $stmt = $pdo->prepare("UPDATE tb_sv_komponen SET kode_komponen=?, nama_komponen=?, bobot=?, urutan=?, keterangan=? WHERE id_komponen=? AND id_instrumen=?");
                $stmt->execute([$data[1], $data[2], $data[3], $data[4], $data[5], $id, $targetInstrumen]);
                sv_log($pdo, 'Edit Komponen', $data[2]);
                sv_flash('success', 'Komponen berhasil diperbarui.');
            }
        } elseif ($aksi === 'hapus') {
            $id = (int)($_POST['id_komponen'] ?? 0);
            $targetInstrumen = (int)($_POST['id_instrumen'] ?? $id_instrumen);
            $pdo->prepare("DELETE FROM tb_sv_indikator WHERE id_komponen = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM tb_sv_komponen WHERE id_komponen = ?" . ($targetInstrumen ? " AND id_instrumen = " . (int)$targetInstrumen : ""))->execute([$id]);
            sv_log($pdo, 'Hapus Komponen', 'ID ' . $id);
            sv_flash('success', 'Komponen berhasil dihapus.');
        }
    } catch (Throwable $e) {
        sv_flash('danger', 'Gagal memproses data: ' . $e->getMessage());
    }
    redirect('komponen_instrumen.php' . ($id_instrumen ? '?id_instrumen=' . $id_instrumen : ''));
}

$rows = [];
try {
    if ($id_instrumen > 0) {
        $stmt = $pdo->prepare("SELECT k.*, i.kode_instrumen, i.nama_instrumen, i.jenis_supervisi FROM tb_sv_komponen k JOIN tb_sv_instrumen i ON i.id_instrumen = k.id_instrumen WHERE k.id_instrumen = ? ORDER BY k.urutan ASC, k.id_komponen ASC");
        $stmt->execute([$id_instrumen]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = $pdo->query("SELECT k.*, i.kode_instrumen, i.nama_instrumen, i.jenis_supervisi FROM tb_sv_komponen k JOIN tb_sv_instrumen i ON i.id_instrumen = k.id_instrumen ORDER BY i.kode_instrumen ASC, k.urutan ASC, k.id_komponen ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
}

$js_page = [];
$flash = sv_render_flash_js();
if ($flash !== '') {
    $js_page[] = $flash;
}
$js_page[] = <<<'JS'
$(document).ready(function () {
    SV.initDataTable('#table-komponen');
    $('#filter-instrumen').on('change', function () {
        var v = $(this).val();
        if (v) { window.location.href = 'komponen_instrumen.php?id_instrumen=' + v; }
        else { window.location.href = 'komponen_instrumen.php'; }
    });
    $('#btn-tambah').on('click', function () {
        $('#form-komponen')[0].reset();
        $('#form-komponen [name=aksi]').val('tambah');
        $('#form-komponen [name=id_komponen]').val('');
        var cur = $('#filter-instrumen').val();
        if (cur) { $('#form-komponen [name=id_instrumen]').val(cur); }
        $('#modal-komponen .modal-title').text('Tambah Komponen');
        $('#modal-komponen').modal('show');
    });
    $(document).on('click', '.btn-edit', function () {
        var d = $(this).data('row');
        $('#form-komponen')[0].reset();
        $('#form-komponen [name=aksi]').val('edit');
        Object.keys(d).forEach(function (k) {
            var el = $('#form-komponen [name="' + k + '"]');
            if (el.length) { el.val(d[k]); }
        });
        $('#form-komponen [name=id_instrumen]').val(d.id_instrumen);
        $('#modal-komponen .modal-title').text('Edit Komponen');
        $('#modal-komponen').modal('show');
    });
    $(document).on('click', '.btn-hapus', function () {
        var id = $(this).data('id');
        var iid = $(this).data('iid');
        SV.confirmDelete({ text: 'Komponen dan indikator di dalamnya akan dihapus.', onConfirm: function () { SV.submitPost(location.href, { aksi: 'hapus', id_komponen: id, id_instrumen: iid }); } });
    });
    $('#btn-excel').on('click', function () { SV.exportExcel('table-komponen', 'Komponen Instrumen', 'komponen_instrumen', false); });
    $('#btn-pdf').on('click', function () { SV.printPdf('table-komponen', 'Komponen Instrumen', false); });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Komponen Instrumen</h1>
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

            <?php if ($instrumen): ?>
                <div class="alert alert-info">
                    <strong>Instrumen:</strong> <?= htmlspecialchars($instrumen['kode_instrumen'] . ' - ' . $instrumen['nama_instrumen']) ?>
                    (<?= htmlspecialchars($instrumen['jenis_supervisi']) ?> &middot; Skala <?= htmlspecialchars($instrumen['skala_penilaian']) ?>)
                    <a href="komponen_instrumen.php" class="btn btn-sm btn-light float-right">Tampilkan Semua</a>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h4>Daftar Komponen Instrumen</h4>
                    <div class="card-header-action d-flex align-items-center">
                        <select class="form-control form-control-sm mr-2" id="filter-instrumen" style="width:260px;">
                            <option value="">Semua Instrumen</option>
                            <?php foreach ($instrumen_list as $ins): ?>
                                <option value="<?= (int)$ins['id_instrumen'] ?>" <?= $id_instrumen === (int)$ins['id_instrumen'] ? 'selected' : '' ?>><?= htmlspecialchars($ins['kode_instrumen'] . ' - ' . $ins['nama_instrumen']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-success btn-sm mr-1" id="btn-excel" type="button"><i class="fas fa-file-excel"></i> Excel</button>
                        <button class="btn btn-warning btn-sm mr-1" id="btn-pdf" type="button"><i class="fas fa-file-pdf"></i> PDF</button>
                        <?php if ($can_manage): ?>
                        <button class="btn btn-primary btn-sm" id="btn-tambah" type="button"><i class="fas fa-plus"></i> Tambah</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-komponen">
                            <thead>
                                <tr>
                                    <th width="5%">No</th>
                                    <th>Instrumen</th>
                                    <th>Kode Komponen</th>
                                    <th>Nama Komponen</th>
                                    <th>Bobot</th>
                                    <th>Urutan</th>
                                    <th>Keterangan</th>
                                    <?php if ($can_manage): ?><th width="12%">Aksi</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="text-center"></td>
                                        <td><span class="badge badge-light"><?= htmlspecialchars($r['kode_instrumen']) ?></span> <?= htmlspecialchars($r['nama_instrumen']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['kode_komponen']) ?></td>
                                        <td><?= htmlspecialchars($r['nama_komponen']) ?></td>
                                        <td><?= htmlspecialchars(number_format((float)$r['bobot'], 2)) ?></td>
                                        <td><?= (int)$r['urutan'] ?></td>
                                        <td><?= htmlspecialchars((string)$r['keterangan']) ?></td>
                                        <?php if ($can_manage): ?>
                                        <td>
                                            <a class="btn btn-sm btn-outline-primary" href="indikator_instrumen.php?id_komponen=<?= (int)$r['id_komponen'] ?>" title="Indikator"><i class="fas fa-list"></i></a>
                                            <button class="btn btn-warning btn-sm btn-edit" type="button" data-row='<?= htmlspecialchars(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>'><i class="fas fa-edit"></i></button>
                                            <button class="btn btn-danger btn-sm btn-hapus" type="button" data-id="<?= (int)$r['id_komponen'] ?>" data-iid="<?= (int)$r['id_instrumen'] ?>"><i class="fas fa-trash"></i></button>
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
<div class="modal fade" id="modal-komponen" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="komponen_instrumen.php<?= $id_instrumen ? '?id_instrumen=' . (int)$id_instrumen : '' ?>" id="form-komponen">
                <input type="hidden" name="aksi" value="tambah">
                <input type="hidden" name="id_komponen" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Komponen</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Instrumen</label>
                        <select class="form-control" name="id_instrumen" required>
                            <option value="">Pilih Instrumen</option>
                            <?php foreach ($instrumen_list as $ins): ?>
                                <option value="<?= (int)$ins['id_instrumen'] ?>" <?= $id_instrumen === (int)$ins['id_instrumen'] ? 'selected' : '' ?>><?= htmlspecialchars($ins['kode_instrumen'] . ' - ' . $ins['nama_instrumen']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6"><label>Kode Komponen</label><input type="text" class="form-control" name="kode_komponen" placeholder="cth: K1"></div>
                        <div class="form-group col-md-6"><label>Bobot</label><input type="number" step="0.01" min="0" class="form-control" name="bobot" value="1"></div>
                    </div>
                    <div class="form-group"><label>Nama Komponen</label><input type="text" class="form-control" name="nama_komponen" required></div>
                    <div class="form-group"><label>Urutan</label><input type="number" class="form-control" name="urutan" value="0"></div>
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
