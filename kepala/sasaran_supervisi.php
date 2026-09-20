<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$page_title = 'Sasaran Supervisi';
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

$guru_list = sv_guru_list($pdo);
$guru_map = sv_guru_map($pdo);
$mapel_guru = sv_mapel_guru($pdo);
$kelas_guru = sv_kelas_guru($pdo);
$program_list = sv_program_options($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $aksi = (string)($_POST['aksi'] ?? '');
    try {
        if ($aksi === 'tambah_massal') {
            $ids = $_POST['id_guru'] ?? [];
            $id_program = (int)($_POST['id_program'] ?? 0);
            $jenis = trim((string)($_POST['jenis_supervisi'] ?? 'Akademik'));
            $ta = trim((string)($_POST['tahun_ajaran'] ?? $periode['tahun_ajaran']));
            $sem = trim((string)($_POST['semester'] ?? $periode['semester']));
            $jabatan = trim((string)($_POST['jabatan'] ?? 'Guru'));
            $inserted = 0;

            if (is_array($ids) && $ids) {
                $stmt = $pdo->prepare("INSERT INTO tb_sv_sasaran
                    (id_program, id_guru, nama_guru, nip_npk, jabatan, mata_pelajaran, kelas, jenis_supervisi, tahun_ajaran, semester, status_supervisi)
                    VALUES (?,?,?,?,?,?,?,?,?,?, 'Belum Disupervisi')");
                foreach ($ids as $idGuru) {
                    $idGuru = (int)$idGuru;
                    if ($idGuru <= 0 || !isset($guru_map[$idGuru])) {
                        continue;
                    }
                    $g = $guru_map[$idGuru];
                    $stmt->execute([
                        $id_program ?: null,
                        $idGuru,
                        $g['nama_guru'],
                        $g['nuptk'] ?? '',
                        $jabatan,
                        implode(', ', $mapel_guru[$idGuru] ?? []),
                        implode(', ', $kelas_guru[$idGuru] ?? []),
                        $jenis,
                        $ta,
                        $sem,
                    ]);
                    $inserted++;
                }
            }
            sv_log($pdo, 'Tambah Sasaran', $inserted . ' guru');
            sv_flash('success', $inserted . ' sasaran supervisi berhasil ditambahkan.');
        } elseif ($aksi === 'edit') {
            $id = (int)($_POST['id_sasaran'] ?? 0);
            $stmt = $pdo->prepare("UPDATE tb_sv_sasaran SET id_program=?, jenis_supervisi=?, jabatan=?, mata_pelajaran=?, kelas=?,
                tahun_ajaran=?, semester=?, status_supervisi=?, keterangan=? WHERE id_sasaran=?");
            $stmt->execute([
                (int)($_POST['id_program'] ?? 0) ?: null,
                trim((string)($_POST['jenis_supervisi'] ?? 'Akademik')),
                trim((string)($_POST['jabatan'] ?? '')),
                trim((string)($_POST['mata_pelajaran'] ?? '')),
                trim((string)($_POST['kelas'] ?? '')),
                trim((string)($_POST['tahun_ajaran'] ?? $periode['tahun_ajaran'])),
                trim((string)($_POST['semester'] ?? $periode['semester'])),
                trim((string)($_POST['status_supervisi'] ?? 'Belum Disupervisi')),
                trim((string)($_POST['keterangan'] ?? '')),
                $id,
            ]);
            sv_log($pdo, 'Edit Sasaran', 'ID ' . $id);
            sv_flash('success', 'Sasaran supervisi berhasil diperbarui.');
        } elseif ($aksi === 'hapus') {
            $id = (int)($_POST['id_sasaran'] ?? 0);
            $pdo->prepare("DELETE FROM tb_sv_sasaran WHERE id_sasaran = ?")->execute([$id]);
            sv_log($pdo, 'Hapus Sasaran', 'ID ' . $id);
            sv_flash('success', 'Sasaran supervisi berhasil dihapus.');
        }
    } catch (Throwable $e) {
        sv_flash('danger', 'Gagal memproses data: ' . $e->getMessage());
    }
    redirect('sasaran_supervisi.php');
}

$rows = [];
try {
    $rows = $pdo->query("SELECT s.*, p.nama_program,
            (SELECT MAX(pk.tanggal) FROM tb_sv_pelaksanaan pk WHERE pk.id_guru = s.id_guru AND pk.status = 'Selesai') AS supervisi_terakhir,
            (SELECT pk.nilai FROM tb_sv_pelaksanaan pk WHERE pk.id_guru = s.id_guru AND pk.status = 'Selesai' ORDER BY pk.tanggal DESC, pk.id_pelaksanaan DESC LIMIT 1) AS nilai_terakhir
        FROM tb_sv_sasaran s
        LEFT JOIN tb_sv_program p ON p.id_program = s.id_program
        ORDER BY s.tahun_ajaran DESC, s.nama_guru ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$js_page = [];
$flash = sv_render_flash_js();
if ($flash !== '') {
    $js_page[] = $flash;
}
$js_page[] = <<<'JS'
$(document).ready(function () {
    SV.initDataTable('#table-sasaran');
    function svUpdateLabel() {
        var n = $('.sv-guru-cb:checked').length;
        var label = n ? n + ' guru dipilih' : 'Pilih guru...';
        $('#sv-guru-label').text(label).toggleClass('text-muted', !n);
        $('#sv-guru-count').text(n ? '(' + n + ' terpilih)' : '');
    }
    $('#sv-guru-toggle').on('click', function (e) { e.stopPropagation(); $('#sv-guru-panel').toggle(); });
    $(document).on('click', function (e) { if (!$(e.target).closest('#sv-guru-dropdown').length) { $('#sv-guru-panel').hide(); } });
    $('#sv-guru-search').on('input', function () {
        var q = $(this).val().toLowerCase();
        $('.sv-guru-item').each(function () { $(this).toggle($(this).data('name').indexOf(q) !== -1); });
    });
    $('#sv-guru-all').on('click', function () {
        $('.sv-guru-item:visible .sv-guru-cb').prop('checked', true);
        svUpdateLabel();
    });
    $('#sv-guru-clear').on('click', function () {
        $('.sv-guru-cb').prop('checked', false);
        svUpdateLabel();
    });
    $(document).on('change', '.sv-guru-cb', svUpdateLabel);
    $('#form-sasaran-massal').on('submit', function (e) {
        if ($('.sv-guru-cb:checked').length === 0) {
            e.preventDefault();
            Swal.fire({ icon: 'warning', title: 'Pilih Guru', text: 'Pilih minimal 1 guru/PTK.' });
            $('#sv-guru-panel').show();
            return false;
        }
    });
    $('#btn-tambah').on('click', function () {
        $('#form-sasaran-massal')[0].reset();
        $('.sv-guru-cb').prop('checked', false);
        svUpdateLabel();
        $('#sv-guru-search').val('').trigger('input');
        $('#modal-sasaran .modal-title').text('Tambah Sasaran Supervisi');
        $('#modal-sasaran').modal('show');
    });
    $(document).on('click', '.btn-edit', function () {
        var d = $(this).data('row');
        $('#form-sasaran-edit')[0].reset();
        Object.keys(d).forEach(function (k) {
            var el = $('#form-sasaran-edit [name="' + k + '"]');
            if (el.length) { el.val(d[k]); }
        });
        $('#modal-sasaran-edit').modal('show');
    });
    $(document).on('click', '.btn-hapus', function () {
        var id = $(this).data('id');
        SV.confirmDelete({ text: 'Sasaran supervisi akan dihapus.', onConfirm: function () { SV.submitPost('sasaran_supervisi.php', { aksi: 'hapus', id_sasaran: id }); } });
    });
    $('#btn-excel').on('click', function () { SV.exportExcel('table-sasaran', 'Sasaran Supervisi', 'sasaran_supervisi', true); });
    $('#btn-pdf').on('click', function () { SV.printPdf('table-sasaran', 'Sasaran Supervisi', true); });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Sasaran Supervisi</h1>
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
                    <h4>Daftar Sasaran Supervisi</h4>
                    <div class="card-header-action">
                        <button class="btn btn-success" id="btn-excel" type="button"><i class="fas fa-file-excel"></i> Excel</button>
                        <button class="btn btn-warning" id="btn-pdf" type="button"><i class="fas fa-file-pdf"></i> PDF</button>
                        <?php if ($can_manage): ?>
                        <button class="btn btn-primary" id="btn-tambah" type="button"><i class="fas fa-plus"></i> Tambah dari Master PTK</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-sasaran">
                            <thead>
                                <tr>
                                    <th width="5%">No</th>
                                    <th>Guru/PTK</th>
                                    <th>NIP/NPK</th>
                                    <th>Jabatan</th>
                                    <th>Mata Pelajaran</th>
                                    <th>Kelas</th>
                                    <th>Program</th>
                                    <th>Jenis</th>
                                    <th>Tahun Ajaran</th>
                                    <th>Semester</th>
                                    <th>Status</th>
                                    <th>Supervisi Terakhir</th>
                                    <th>Nilai Terakhir</th>
                                    <th>Keterangan</th>
                                    <?php if ($can_manage): ?><th width="10%">Aksi</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="text-center"></td>
                                        <td><?= htmlspecialchars($r['nama_guru']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['nip_npk']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['jabatan']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['mata_pelajaran']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['kelas']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['nama_program']) ?></td>
                                        <td><span class="badge badge-info"><?= htmlspecialchars($r['jenis_supervisi']) ?></span></td>
                                        <td><?= htmlspecialchars($r['tahun_ajaran']) ?></td>
                                        <td><?= htmlspecialchars($r['semester']) ?></td>
                                        <td>
                                            <?php $isDone = $r['status_supervisi'] === 'Sudah Disupervisi'; ?>
                                            <span class="badge badge-<?= $isDone ? 'success' : 'warning' ?>"><?= htmlspecialchars($r['status_supervisi']) ?></span>
                                        </td>
                                        <td><?= $r['supervisi_terakhir'] ? date('d/m/Y', strtotime($r['supervisi_terakhir'])) : '-' ?></td>
                                        <td><?= $r['nilai_terakhir'] !== null ? htmlspecialchars(number_format((float)$r['nilai_terakhir'], 2)) : '-' ?></td>
                                        <td><?= htmlspecialchars((string)$r['keterangan']) ?></td>
                                        <?php if ($can_manage): ?>
                                        <td>
                                            <button class="btn btn-warning btn-sm btn-edit" type="button"
                                                data-row='<?= htmlspecialchars(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>'><i class="fas fa-edit"></i></button>
                                            <button class="btn btn-danger btn-sm btn-hapus" type="button" data-id="<?= (int)$r['id_sasaran'] ?>"><i class="fas fa-trash"></i></button>
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
<div class="modal fade" id="modal-sasaran" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" action="sasaran_supervisi.php" id="form-sasaran-massal">
                <input type="hidden" name="aksi" value="tambah_massal">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Sasaran Supervisi</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Pilih Guru/PTK <span class="text-danger">*</span> <small class="text-muted" id="sv-guru-count"></small></label>
                        <div class="sv-guru-dropdown position-relative" id="sv-guru-dropdown">
                            <button type="button" class="form-control text-left d-flex justify-content-between align-items-center" id="sv-guru-toggle">
                                <span id="sv-guru-label" class="text-muted">Pilih guru...</span>
                                <i class="fas fa-chevron-down ml-2"></i>
                            </button>
                            <div class="sv-guru-panel border rounded bg-white shadow position-absolute w-100" id="sv-guru-panel" style="display:none; top:100%; left:0; z-index:1055; max-height:340px; overflow:auto;">
                                <div class="p-2 border-bottom sticky-top bg-white" style="top:0;">
                                    <input type="text" class="form-control form-control-sm mb-2" id="sv-guru-search" placeholder="Cari nama / NUPTK...">
                                    <div class="d-flex">
                                        <button type="button" class="btn btn-sm btn-outline-primary mr-2" id="sv-guru-all">Pilih Semua</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="sv-guru-clear">Batalkan Semua</button>
                                    </div>
                                </div>
                                <div class="p-2" id="sv-guru-list">
                                    <?php foreach ($guru_list as $g): ?>
                                        <div class="custom-control custom-checkbox mb-1 sv-guru-item" data-name="<?= htmlspecialchars(strtolower($g['nama_guru'] . ' ' . ($g['nuptk'] ?? '')), ENT_QUOTES) ?>">
                                            <input type="checkbox" class="custom-control-input sv-guru-cb" id="guru-<?= (int)$g['id_guru'] ?>" name="id_guru[]" value="<?= (int)$g['id_guru'] ?>">
                                            <label class="custom-control-label" for="guru-<?= (int)$g['id_guru'] ?>">
                                                <?= htmlspecialchars($g['nama_guru']) ?><?= !empty($g['nuptk']) ? ' <small class="text-muted">- ' . htmlspecialchars($g['nuptk']) . '</small>' : '' ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Program Supervisi</label>
                            <select class="form-control" name="id_program">
                                <option value="">- Tanpa Program -</option>
                                <?php foreach ($program_list as $p): ?>
                                    <option value="<?= (int)$p['id_program'] ?>"><?= htmlspecialchars($p['nama_program']) ?> (<?= htmlspecialchars($p['jenis_supervisi']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Jenis Supervisi</label>
                            <select class="form-control" name="jenis_supervisi">
                                <?php foreach (sv_jenis_list() as $j): ?><option value="<?= $j ?>"><?= $j ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Tahun Ajaran</label>
                            <input type="text" class="form-control" name="tahun_ajaran" value="<?= htmlspecialchars($periode['tahun_ajaran'], ENT_QUOTES) ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label>Semester</label>
                            <select class="form-control" name="semester">
                                <?php foreach (sv_semester_options() as $s): ?>
                                    <option value="<?= htmlspecialchars($s, ENT_QUOTES) ?>" <?= $s === $periode['semester'] ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Jabatan</label>
                        <input type="text" class="form-control" name="jabatan" value="Guru">
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

<div class="modal fade" id="modal-sasaran-edit" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" action="sasaran_supervisi.php" id="form-sasaran-edit">
                <input type="hidden" name="aksi" value="edit">
                <input type="hidden" name="id_sasaran" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Sasaran Supervisi</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Program Supervisi</label>
                            <select class="form-control" name="id_program">
                                <option value="">- Tanpa Program -</option>
                                <?php foreach ($program_list as $p): ?>
                                    <option value="<?= (int)$p['id_program'] ?>"><?= htmlspecialchars($p['nama_program']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Jenis Supervisi</label>
                            <select class="form-control" name="jenis_supervisi">
                                <?php foreach (sv_jenis_list() as $j): ?><option value="<?= $j ?>"><?= $j ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6"><label>Jabatan</label><input type="text" class="form-control" name="jabatan"></div>
                        <div class="form-group col-md-6"><label>Mata Pelajaran</label><input type="text" class="form-control" name="mata_pelajaran"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6"><label>Kelas</label><input type="text" class="form-control" name="kelas"></div>
                        <div class="form-group col-md-6">
                            <label>Status Supervisi</label>
                            <select class="form-control" name="status_supervisi">
                                <option value="Belum Disupervisi">Belum Disupervisi</option>
                                <option value="Sudah Disupervisi">Sudah Disupervisi</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6"><label>Tahun Ajaran</label><input type="text" class="form-control" name="tahun_ajaran"></div>
                        <div class="form-group col-md-6">
                            <label>Semester</label>
                            <select class="form-control" name="semester">
                                <?php foreach (sv_semester_options() as $s): ?><option value="<?= htmlspecialchars($s, ENT_QUOTES) ?>"><?= htmlspecialchars($s) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
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
