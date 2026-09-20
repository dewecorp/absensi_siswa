<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$page_title = 'Indikator Penilaian';
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

$komponen_list = [];
try {
    $komponen_list = $pdo->query("SELECT k.id_komponen, k.nama_komponen, k.kode_komponen, i.id_instrumen, i.kode_instrumen, i.nama_instrumen
        FROM tb_sv_komponen k JOIN tb_sv_instrumen i ON i.id_instrumen = k.id_instrumen
        ORDER BY i.kode_instrumen ASC, k.urutan ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$id_instrumen_filter = (int)($_GET['id_instrumen'] ?? $_GET['instrumen'] ?? 0);
$id_komponen = (int)($_GET['id_komponen'] ?? $_POST['id_komponen'] ?? 0);
if ($id_komponen) {
    try {
        $tmp = $pdo->prepare("SELECT id_instrumen FROM tb_sv_komponen WHERE id_komponen = ? LIMIT 1");
        $tmp->execute([$id_komponen]);
        $id_instrumen_filter = (int)($tmp->fetchColumn() ?: $id_instrumen_filter);
    } catch (Throwable $e) {
    }
}
$headerSkala = '1-4';
if ($id_instrumen_filter) {
    foreach ($instrumen_list as $ins) {
        if ((int)$ins['id_instrumen'] === $id_instrumen_filter) {
            $headerSkala = $ins['skala_penilaian'];
            break;
        }
    }
}
$skalaInfo = '1=Belum Terpenuhi, 2=Mulai Terpenuhi, 3=Terpenuhi, 4=Sangat Baik';
$komponen = null;
$skala_max = 4;
if ($id_komponen > 0) {
    try {
        $stmt = $pdo->prepare("SELECT k.*, i.nama_instrumen, i.kode_instrumen, i.jenis_supervisi, i.skala_penilaian, i.id_instrumen FROM tb_sv_komponen k JOIN tb_sv_instrumen i ON i.id_instrumen = k.id_instrumen WHERE k.id_komponen = ? LIMIT 1");
        $stmt->execute([$id_komponen]);
        $komponen = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($komponen) {
            $skala_max = sv_skala_max($komponen['skala_penilaian']);
            $headerSkala = $komponen['skala_penilaian'];
        }
    } catch (Throwable $e) {
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $aksi = (string)($_POST['aksi'] ?? '');
    $targetKomponen = (int)($_POST['id_komponen'] ?? $id_komponen);
    if ($aksi === 'tambah' || $aksi === 'edit') {
        if ($targetKomponen <= 0) {
            sv_flash('warning', 'Pilih komponen terlebih dahulu.');
            redirect('indikator_instrumen.php' . ($id_instrumen_filter ? '?id_instrumen=' . $id_instrumen_filter : ''));
        }
        try {
            $tmp = $pdo->prepare("SELECT skala_penilaian FROM tb_sv_instrumen i JOIN tb_sv_komponen k ON k.id_instrumen = i.id_instrumen WHERE k.id_komponen = ? LIMIT 1");
            $tmp->execute([$targetKomponen]);
            $skalaRow = $tmp->fetchColumn();
            $skalaLoc = $skalaRow ? sv_skala_max((string)$skalaRow) : $skala_max;
        } catch (Throwable $e) {
            $skalaLoc = $skala_max;
        }
        $data = [
            $targetKomponen,
            trim((string)($_POST['kode_indikator'] ?? '')),
            trim((string)($_POST['indikator'] ?? '')),
            trim((string)($_POST['deskripsi'] ?? '')),
            (float)($_POST['bobot'] ?? 1),
            (float)($_POST['skor_minimal'] ?? 1),
            (float)($_POST['skor_maksimal'] ?? $skalaLoc),
            (int)($_POST['urutan'] ?? 0),
        ];
        if ($data[2] === '') {
            sv_flash('warning', 'Indikator wajib diisi.');
        } elseif ($aksi === 'tambah') {
            $stmt = $pdo->prepare("INSERT INTO tb_sv_indikator (id_komponen, kode_indikator, indikator, deskripsi, bobot, skor_minimal, skor_maksimal, urutan) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute($data);
            sv_log($pdo, 'Tambah Indikator', $data[2]);
            sv_flash('success', 'Indikator berhasil ditambahkan.');
        } else {
            $id = (int)($_POST['id_indikator'] ?? 0);
            $stmt = $pdo->prepare("UPDATE tb_sv_indikator SET kode_indikator=?, indikator=?, deskripsi=?, bobot=?, skor_minimal=?, skor_maksimal=?, urutan=? WHERE id_indikator=? AND id_komponen=?");
            $stmt->execute([$data[1], $data[2], $data[3], $data[4], $data[5], $data[6], $data[7], $id, $targetKomponen]);
            sv_log($pdo, 'Edit Indikator', $data[2]);
            sv_flash('success', 'Indikator berhasil diperbarui.');
        }
        redirect('indikator_instrumen.php' . ($id_instrumen_filter ? '?id_instrumen=' . $id_instrumen_filter : '') . ($targetKomponen ? ($id_instrumen_filter ? '&' : '?') . 'id_komponen=' . $targetKomponen : ''));
    } elseif ($aksi === 'hapus') {
        $id = (int)($_POST['id_indikator'] ?? 0);
        $kompTarget = (int)($_POST['id_komponen'] ?? $id_komponen);
        if ($kompTarget) {
            $pdo->prepare("DELETE FROM tb_sv_indikator WHERE id_indikator = ? AND id_komponen = ?")->execute([$id, $kompTarget]);
        } else {
            $pdo->prepare("DELETE FROM tb_sv_indikator WHERE id_indikator = ?")->execute([$id]);
        }
        sv_log($pdo, 'Hapus Indikator', 'ID ' . $id);
        sv_flash('success', 'Indikator berhasil dihapus.');
        redirect('indikator_instrumen.php' . ($id_instrumen_filter ? '?id_instrumen=' . $id_instrumen_filter : '') . ($kompTarget ? ($id_instrumen_filter ? '&' : '?') . 'id_komponen=' . $kompTarget : ''));
    }
    redirect('indikator_instrumen.php' . ($id_instrumen_filter ? '?id_instrumen=' . $id_instrumen_filter : ''));
}

$rows = [];
try {
    if ($id_komponen > 0) {
        $stmt = $pdo->prepare("SELECT n.*, k.nama_komponen, k.kode_komponen, i.kode_instrumen, i.nama_instrumen FROM tb_sv_indikator n JOIN tb_sv_komponen k ON k.id_komponen = n.id_komponen JOIN tb_sv_instrumen i ON i.id_instrumen = k.id_instrumen WHERE n.id_komponen = ? ORDER BY n.urutan ASC, n.id_indikator ASC");
        $stmt->execute([$id_komponen]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($id_instrumen_filter > 0) {
        $stmt = $pdo->prepare("SELECT n.*, k.nama_komponen, k.kode_komponen, i.kode_instrumen, i.nama_instrumen FROM tb_sv_indikator n JOIN tb_sv_komponen k ON k.id_komponen = n.id_komponen JOIN tb_sv_instrumen i ON i.id_instrumen = k.id_instrumen WHERE k.id_instrumen = ? ORDER BY k.urutan ASC, n.urutan ASC, n.id_indikator ASC");
        $stmt->execute([$id_instrumen_filter]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = $pdo->query("SELECT n.*, k.nama_komponen, k.kode_komponen, i.kode_instrumen, i.nama_instrumen FROM tb_sv_indikator n JOIN tb_sv_komponen k ON k.id_komponen = n.id_komponen JOIN tb_sv_instrumen i ON i.id_instrumen = k.id_instrumen ORDER BY i.kode_instrumen ASC, k.urutan ASC, n.urutan ASC")->fetchAll(PDO::FETCH_ASSOC);
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
    SV.initDataTable('#table-indikator');
    $('#filter-instrumen').on('change', function () {
        var v = $(this).val();
        if (v) window.location.href = 'indikator_instrumen.php?id_instrumen=' + v;
        else window.location.href = 'indikator_instrumen.php';
    });
    $('#filter-komponen').on('change', function () {
        var v = $(this).val();
        if (v) window.location.href = 'indikator_instrumen.php?id_komponen=' + v;
        else {
            var ins = $('#filter-instrumen').val();
            window.location.href = ins ? 'indikator_instrumen.php?id_instrumen=' + ins : 'indikator_instrumen.php';
        }
    });
    $('#btn-tambah').on('click', function () {
        $('#form-indikator')[0].reset();
        $('#form-indikator [name=aksi]').val('tambah');
        $('#form-indikator [name=id_indikator]').val('');
        var cur = $('#filter-komponen').val() || '<?= (int)$id_komponen ?>';
        if (cur) $('#form-indikator [name=id_komponen]').val(cur);
        var skala = '<?= htmlspecialchars((string)$headerSkala, ENT_QUOTES) ?>';
        var max = (skala === '0-100' ? '100' : (skala === '1-5' ? '5' : '4'));
        $('#form-indikator [name=skor_maksimal]').val(max);
        $('#modal-indikator .modal-title').text('Tambah Indikator');
        $('#modal-indikator').modal('show');
    });
    $(document).on('click', '.btn-edit', function () {
        var d = $(this).data('row');
        $('#form-indikator')[0].reset();
        $('#form-indikator [name=aksi]').val('edit');
        Object.keys(d).forEach(function (k) {
            var el = $('#form-indikator [name="' + k + '"]');
            if (el.length) { el.val(d[k]); }
        });
        $('#form-indikator [name=id_komponen]').val(d.id_komponen);
        $('#modal-indikator .modal-title').text('Edit Indikator');
        $('#modal-indikator').modal('show');
    });
    $(document).on('click', '.btn-hapus', function () {
        var id = $(this).data('id');
        var kid = $(this).data('kid');
        SV.confirmDelete({ text: 'Indikator akan dihapus.', onConfirm: function () { SV.submitPost(location.href, { aksi: 'hapus', id_indikator: id, id_komponen: kid }); } });
    });
    $('#btn-excel').on('click', function () { SV.exportExcel('table-indikator', 'Indikator Penilaian', 'indikator_instrumen', true); });
    $('#btn-pdf').on('click', function () { SV.printPdf('table-indikator', 'Indikator Penilaian', true); });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Indikator Penilaian</h1>
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

            <?php if ($komponen): ?>
                <div class="alert alert-info">
                    <strong>Instrumen:</strong> <?= htmlspecialchars($komponen['kode_instrumen'] . ' - ' . $komponen['nama_instrumen']) ?> &middot;
                    <strong>Komponen:</strong> <?= htmlspecialchars($komponen['nama_komponen']) ?> &middot; Skala <?= htmlspecialchars($komponen['skala_penilaian']) ?>
                    <a href="indikator_instrumen.php<?= $id_instrumen_filter ? '?id_instrumen=' . (int)$id_instrumen_filter : '' ?>" class="btn btn-sm btn-light float-right">Tampilkan Semua</a>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h4>Daftar Indikator Penilaian</h4>
                    <div class="card-header-action d-flex align-items-center">
                        <select class="form-control form-control-sm mr-2" id="filter-instrumen" style="width:200px;">
                            <option value="">Semua Instrumen</option>
                            <?php foreach ($instrumen_list as $ins): ?>
                                <option value="<?= (int)$ins['id_instrumen'] ?>" <?= $id_instrumen_filter === (int)$ins['id_instrumen'] ? 'selected' : '' ?>><?= htmlspecialchars($ins['kode_instrumen'] . ' - ' . $ins['nama_instrumen']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="form-control form-control-sm mr-2" id="filter-komponen" style="width:220px;">
                            <option value="">Semua Komponen</option>
                            <?php foreach ($komponen_list as $kp): ?>
                                <?php if ($id_instrumen_filter && (int)$kp['id_instrumen'] !== $id_instrumen_filter) continue; ?>
                                <option value="<?= (int)$kp['id_komponen'] ?>" <?= $id_komponen === (int)$kp['id_komponen'] ? 'selected' : '' ?>><?= htmlspecialchars($kp['kode_komponen'] . ' - ' . $kp['nama_komponen']) ?></option>
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
                    <div class="alert alert-light border small">
                        <strong>Skala <?= htmlspecialchars($headerSkala) ?>:</strong> <?= htmlspecialchars($skalaInfo) ?> &middot; Nilai = Σ(bobot × skor/maks) ÷ Σbobot × 100
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-indikator">
                            <thead>
                                <tr>
                                    <th width="5%">No</th>
                                    <th>Instrumen</th>
                                    <th>Komponen</th>
                                    <th>Kode</th>
                                    <th>Indikator</th>
                                    <th>Bobot</th>
                                    <th>Skor Min</th>
                                    <th>Skor Maks</th>
                                    <th>Urutan</th>
                                    <?php if ($can_manage): ?><th width="12%">Aksi</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="text-center"></td>
                                        <td><span class="badge badge-light"><?= htmlspecialchars($r['kode_instrumen']) ?></span> <?= htmlspecialchars($r['nama_instrumen']) ?></td>
                                        <td><?= htmlspecialchars($r['nama_komponen']) ?> <small class="text-muted">(<?= htmlspecialchars((string)$r['kode_komponen']) ?>)</small></td>
                                        <td><?= htmlspecialchars((string)$r['kode_indikator']) ?></td>
                                        <td><?= htmlspecialchars($r['indikator']) ?></td>
                                        <td><?= htmlspecialchars(number_format((float)$r['bobot'], 2)) ?></td>
                                        <td><?= (int)$r['skor_minimal'] ?></td>
                                        <td><?= (int)$r['skor_maksimal'] ?></td>
                                        <td><?= (int)$r['urutan'] ?></td>
                                        <?php if ($can_manage): ?>
                                        <td>
                                            <button class="btn btn-warning btn-sm btn-edit" type="button" data-row='<?= htmlspecialchars(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>'><i class="fas fa-edit"></i></button>
                                            <button class="btn btn-danger btn-sm btn-hapus" type="button" data-id="<?= (int)$r['id_indikator'] ?>" data-kid="<?= (int)$r['id_komponen'] ?>"><i class="fas fa-trash"></i></button>
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
<div class="modal fade" id="modal-indikator" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" action="indikator_instrumen.php<?= $id_instrumen_filter ? '?id_instrumen=' . (int)$id_instrumen_filter : '' ?><?= $id_komponen ? ($id_instrumen_filter ? '&' : '?') . 'id_komponen=' . (int)$id_komponen : '' ?>" id="form-indikator">
                <input type="hidden" name="aksi" value="tambah">
                <input type="hidden" name="id_indikator" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Indikator</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Komponen</label>
                        <select class="form-control" name="id_komponen" required>
                            <option value="">Pilih Komponen</option>
                            <?php foreach ($komponen_list as $kp): ?>
                                <?php if ($id_instrumen_filter && (int)$kp['id_instrumen'] !== $id_instrumen_filter) continue; ?>
                                <option value="<?= (int)$kp['id_komponen'] ?>" <?= $id_komponen === (int)$kp['id_komponen'] ? 'selected' : '' ?>><?= htmlspecialchars($kp['kode_komponen'] . ' - ' . $kp['nama_komponen'] . ' (' . $kp['kode_instrumen'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6"><label>Kode Indikator</label><input type="text" class="form-control" name="kode_indikator" placeholder="cth: K1.01"></div>
                        <div class="form-group col-md-6"><label>Urutan</label><input type="number" class="form-control" name="urutan" value="0"></div>
                    </div>
                    <div class="form-group"><label>Indikator</label><input type="text" class="form-control" name="indikator" required></div>
                    <div class="form-group"><label>Deskripsi</label><textarea class="form-control" name="deskripsi" rows="2"></textarea></div>
                    <div class="form-row">
                        <div class="form-group col-md-4"><label>Bobot</label><input type="number" step="0.01" min="0" class="form-control" name="bobot" value="1"></div>
                        <div class="form-group col-md-4"><label>Skor Minimal</label><input type="number" step="1" class="form-control" name="skor_minimal" value="1"></div>
                        <div class="form-group col-md-4"><label>Skor Maksimal</label><input type="number" step="1" class="form-control" name="skor_maksimal" value="<?= htmlspecialchars((string)$skala_max, ENT_QUOTES) ?>"></div>
                    </div>
                    <div class="alert alert-light border small mb-0">Nilai = Σ(bobot × skor/maks) ÷ Σbobot × 100 &middot; Skala 1=Belum Terpenuhi, 4=Sangat Baik.</div>
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
