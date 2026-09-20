<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$page_title = 'Jadwal Supervisi';
$current_page = basename(__FILE__);
$can_manage = sv_is_supervisor($pdo);

$school_profile = getSchoolProfile($pdo);
$periode = sv_periode($pdo);

$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css',
];
$js_libs = [
    'assets/js/supervisi.js',
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
    'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js',
    'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales-all.min.js',
];

$guru_list = sv_guru_list($pdo);
$program_list = sv_program_options($pdo);
$instrumen_list = sv_instrumen_options($pdo);
$jabatan_list = getJabatanList($pdo);
$instrumen_json = json_encode($instrumen_list, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$program_json = json_encode($program_list, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $aksi = (string)($_POST['aksi'] ?? '');
    try {
        if ($aksi === 'tambah' || $aksi === 'edit') {
            $idGuru = (int)($_POST['id_guru'] ?? 0);
            $namaGuru = '';
            foreach ($guru_list as $g) {
                if ((int)$g['id_guru'] === $idGuru) {
                    $namaGuru = $g['nama_guru'];
                    break;
                }
            }
            $supervisor = trim((string)($_POST['supervisor'] ?? ''));
            if ($supervisor === '') $supervisor = sv_current_user_name($pdo);
            $data = [
                (int)($_POST['id_program'] ?? 0) ?: null,
                (int)($_POST['id_sasaran'] ?? 0) ?: null,
                (int)($_POST['id_instrumen'] ?? 0) ?: null,
                $idGuru ?: null,
                $namaGuru,
                trim((string)($_POST['jenis_supervisi'] ?? 'Akademik')),
                $supervisor,
                trim((string)($_POST['tanggal'] ?? '')) ?: null,
                trim((string)($_POST['jam_mulai'] ?? '')) ?: null,
                trim((string)($_POST['jam_selesai'] ?? '')) ?: null,
                trim((string)($_POST['tempat'] ?? '')),
                '',
                trim((string)($_POST['status'] ?? 'Terjadwal')),
                trim((string)($_POST['keterangan'] ?? '')),
            ];
            if ($aksi === 'tambah') {
                $stmt = $pdo->prepare("INSERT INTO tb_sv_jadwal
                    (id_program, id_sasaran, id_instrumen, id_guru, nama_guru, jenis_supervisi, supervisor, tanggal, jam_mulai, jam_selesai, tempat, fokus, status, keterangan)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute($data);
                sv_log($pdo, 'Tambah Jadwal', $namaGuru);
                sv_flash('success', 'Jadwal supervisi berhasil ditambahkan.');
            } else {
                $id = (int)($_POST['id_jadwal'] ?? 0);
                $stmt = $pdo->prepare("UPDATE tb_sv_jadwal SET id_program=?, id_sasaran=?, id_instrumen=?, id_guru=?, nama_guru=?,
                    jenis_supervisi=?, supervisor=?, tanggal=?, jam_mulai=?, jam_selesai=?, tempat=?, fokus=?, status=?, keterangan=?
                    WHERE id_jadwal=?");
                $stmt->execute(array_merge($data, [$id]));
                sv_log($pdo, 'Edit Jadwal', $namaGuru);
                sv_flash('success', 'Jadwal supervisi berhasil diperbarui.');
            }
        } elseif ($aksi === 'ubah_status') {
            $id = (int)($_POST['id_jadwal'] ?? 0);
            $status = trim((string)($_POST['status'] ?? 'Terjadwal'));
            $pdo->prepare("UPDATE tb_sv_jadwal SET status = ? WHERE id_jadwal = ?")->execute([$status, $id]);
            sv_flash('success', 'Status jadwal diperbarui.');
        } elseif ($aksi === 'hapus') {
            $id = (int)($_POST['id_jadwal'] ?? 0);
            $pdo->prepare("DELETE FROM tb_sv_jadwal WHERE id_jadwal = ?")->execute([$id]);
            sv_log($pdo, 'Hapus Jadwal', 'ID ' . $id);
            sv_flash('success', 'Jadwal supervisi berhasil dihapus.');
        }
    } catch (Throwable $e) {
        sv_flash('danger', 'Gagal memproses data: ' . $e->getMessage());
    }
    redirect('jadwal_supervisi.php');
}

$filter = [
    'tanggal_dari' => trim((string)($_GET['tanggal_dari'] ?? '')),
    'tanggal_sampai' => trim((string)($_GET['tanggal_sampai'] ?? '')),
    'guru' => trim((string)($_GET['guru'] ?? '')),
    'jenis' => trim((string)($_GET['jenis'] ?? '')),
    'semester' => trim((string)($_GET['semester'] ?? '')),
    'tahun_ajaran' => trim((string)($_GET['tahun_ajaran'] ?? '')),
];

$where = [];
$params = [];
if ($filter['tanggal_dari'] !== '') {
    $where[] = 'j.tanggal >= ?';
    $params[] = $filter['tanggal_dari'];
}
if ($filter['tanggal_sampai'] !== '') {
    $where[] = 'j.tanggal <= ?';
    $params[] = $filter['tanggal_sampai'];
}
if ($filter['guru'] !== '') {
    $where[] = 'j.id_guru = ?';
    $params[] = (int)$filter['guru'];
}
if ($filter['jenis'] !== '') {
    $where[] = 'j.jenis_supervisi = ?';
    $params[] = $filter['jenis'];
}
if ($filter['tahun_ajaran'] !== '') {
    $where[] = 'p.tahun_ajaran = ?';
    $params[] = $filter['tahun_ajaran'];
}
if ($filter['semester'] !== '') {
    $where[] = 'p.semester = ?';
    $params[] = $filter['semester'];
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$rows = [];
try {
    $stmt = $pdo->prepare("SELECT j.*, p.nama_program, i.nama_instrumen
        FROM tb_sv_jadwal j
        LEFT JOIN tb_sv_program p ON p.id_program = j.id_program
        LEFT JOIN tb_sv_instrumen i ON i.id_instrumen = j.id_instrumen
        {$whereSql}
        ORDER BY j.tanggal DESC, j.jam_mulai ASC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$calendar_events = [];
foreach ($rows as $r) {
    if (empty($r['tanggal'])) {
        continue;
    }
    $color = '#6777ef';
    if ($r['status'] === 'Terlaksana') {
        $color = '#47c363';
    } elseif ($r['status'] === 'Ditunda') {
        $color = '#ffa426';
    } elseif ($r['status'] === 'Dibatalkan') {
        $color = '#fc544b';
    }
    $calendar_events[] = [
        'title' => ($r['nama_guru'] ?: 'Unit') . ' - ' . $r['jenis_supervisi'],
        'start' => $r['tanggal'] . ($r['jam_mulai'] ? 'T' . $r['jam_mulai'] : ''),
        'color' => $color,
        'url' => 'pelaksanaan_supervisi.php?jenis=' . urlencode($r['jenis_supervisi']) . '&id_jadwal=' . (int)$r['id_jadwal'],
    ];
}

$js_page = [];
$flash = sv_render_flash_js();
if ($flash !== '') {
    $js_page[] = $flash;
}
$js_page[] = 'var svEvents = ' . json_encode($calendar_events) . ';';
$js_page[] = 'var svInstrumenList = ' . $instrumen_json . ';';
$js_page[] = 'var svProgramList = ' . $program_json . ';';
$js_page[] = <<<'JS'
function svEnsureOption($sel, val, label) {
    if (!val) return;
    val = String(val);
    if ($sel.find('option').filter(function(){ return String($(this).val()) === val; }).length === 0) {
        $sel.append('<option value="' + $('<div>').text(val).html() + '">' + $('<div>').text(label || val).html() + ' (lama)</option>');
    }
}
function svFilterModalByJenis(jenis, keepVal) {
    var $p = $('#form-jadwal [name=id_program]');
    var $i = $('#form-jadwal [name=id_instrumen]');
    var prevInst = keepVal ? String($i.val() || '') : '';
    var prevProg = keepVal ? String($p.val() || '') : '';
    $p.find('option').each(function () {
        var val = String($(this).val() || '');
        if (!val) return;
        var row = svProgramList.find(function (r) { return String(r.id_program) === val; });
        var show = !row || !jenis || String(row.jenis_supervisi) === String(jenis);
        if (keepVal && (val === prevProg)) show = true;
        $(this).toggle(show);
        $(this).prop('disabled', !show);
    });
    $i.find('option').each(function () {
        var val = String($(this).val() || '');
        if (!val) return;
        var row = svInstrumenList.find(function (r) { return String(r.id_instrumen) === val; });
        var show = !row || !jenis || String(row.jenis_supervisi) === String(jenis);
        if (keepVal && (val === prevInst)) show = true;
        $(this).toggle(show);
        $(this).prop('disabled', !show);
    });
    if (keepVal) {
        $i.val(prevInst);
        $p.val(prevProg);
    } else {
        $i.val('');
        $p.val('');
    }
}
$(document).ready(function () {
    SV.autoSubmitFilters('form');
    SV.initDataTable('#table-jadwal');
    $('#form-jadwal [name=jenis_supervisi]').on('change', function () {
        svFilterModalByJenis($(this).val(), false);
    });
    $('#btn-tambah').on('click', function () {
        $('#form-jadwal')[0].reset();
        $('#form-jadwal [name=aksi]').val('tambah');
        $('#form-jadwal [name=id_jadwal]').val('');
        var jenis = $('#form-jadwal [name=jenis_supervisi]').val() || 'Akademik';
        svFilterModalByJenis(jenis, false);
        $('#modal-jadwal .modal-title').text('Tambah Jadwal Supervisi');
        $('#modal-jadwal').modal('show');
    });
    $(document).on('click', '.btn-edit', function () {
        var d = $(this).data('row');
        $('#form-jadwal')[0].reset();
        $('#form-jadwal [name=aksi]').val('edit');
        if (d.supervisor) svEnsureOption($('#form-jadwal [name=supervisor]'), d.supervisor, d.supervisor);
        if (d.id_program) svEnsureOption($('#form-jadwal [name=id_program]'), d.id_program, d.nama_program || ('Program #' + d.id_program));
        if (d.id_instrumen) svEnsureOption($('#form-jadwal [name=id_instrumen]'), d.id_instrumen, d.nama_instrumen || ('Instrumen #' + d.id_instrumen));
        Object.keys(d).forEach(function (k) {
            var el = $('#form-jadwal [name="' + k + '"]');
            if (el.length) { el.val(d[k]); }
        });
        svFilterModalByJenis(d.jenis_supervisi || $('#form-jadwal [name=jenis_supervisi]').val(), true);
        if (d.id_program) $('#form-jadwal [name=id_program]').val(String(d.id_program));
        if (d.id_instrumen) $('#form-jadwal [name=id_instrumen]').val(String(d.id_instrumen));
        if (d.supervisor) $('#form-jadwal [name=supervisor]').val(String(d.supervisor));
        $('#modal-jadwal .modal-title').text('Edit Jadwal Supervisi');
        $('#modal-jadwal').modal('show');
    });
    $(document).on('click', '.btn-hapus', function () {
        var id = $(this).data('id');
        SV.confirmDelete({ text: 'Jadwal supervisi akan dihapus.', onConfirm: function () { SV.submitPost('jadwal_supervisi.php', { aksi: 'hapus', id_jadwal: id }); } });
    });
    $('#btn-excel').on('click', function () { SV.exportExcel('table-jadwal', 'Jadwal Supervisi', 'jadwal_supervisi', true); });
    $('#btn-pdf').on('click', function () { SV.printPdf('table-jadwal', 'Jadwal Supervisi', true); });

    var calEl = document.getElementById('svCalendar');
    if (calEl && typeof FullCalendar !== 'undefined') {
        var calendar = new FullCalendar.Calendar(calEl, {
            initialView: 'dayGridMonth',
            locale: 'id',
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listWeek' },
            events: svEvents,
            eventClick: function (info) {
                if (info.event.url) {
                    info.jsEvent.preventDefault();
                    window.location.href = info.event.url;
                }
            },
            height: 620
        });
        calendar.render();
    }
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Jadwal Supervisi</h1>
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
                        <div class="form-group col-md-2 mb-2"><label class="small font-weight-bold">Dari Tanggal</label><input type="date" class="form-control" name="tanggal_dari" value="<?= htmlspecialchars($filter['tanggal_dari'], ENT_QUOTES) ?>"></div>
                        <div class="form-group col-md-2 mb-2"><label class="small font-weight-bold">Sampai Tanggal</label><input type="date" class="form-control" name="tanggal_sampai" value="<?= htmlspecialchars($filter['tanggal_sampai'], ENT_QUOTES) ?>"></div>
                        <div class="form-group col-md-2 mb-2">
                            <label class="small font-weight-bold">Guru/PTK</label>
                            <select class="form-control" name="guru">
                                <option value="">Semua</option>
                                <?php foreach ($guru_list as $g): ?>
                                    <option value="<?= (int)$g['id_guru'] ?>" <?= (string)$g['id_guru'] === $filter['guru'] ? 'selected' : '' ?>><?= htmlspecialchars($g['nama_guru']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2 mb-2">
                            <label class="small font-weight-bold">Jenis</label>
                            <select class="form-control" name="jenis">
                                <option value="">Semua</option>
                                <?php foreach (sv_jenis_list() as $j): ?><option value="<?= $j ?>" <?= $j === $filter['jenis'] ? 'selected' : '' ?>><?= $j ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2 mb-2">
                            <label class="small font-weight-bold">Semester</label>
                            <select class="form-control" name="semester">
                                <option value="">Semua</option>
                                <?php foreach (sv_semester_options() as $s): ?><option value="<?= htmlspecialchars($s, ENT_QUOTES) ?>" <?= $s === $filter['semester'] ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2 mb-2">
                            <label class="small font-weight-bold">Tahun Ajaran</label>
                            <select class="form-control" name="tahun_ajaran">
                                <option value="">Semua</option>
                                <?php foreach (sv_tahun_ajaran_options($pdo) as $ta): ?><option value="<?= htmlspecialchars($ta, ENT_QUOTES) ?>" <?= $ta === $filter['tahun_ajaran'] ? 'selected' : '' ?>><?= htmlspecialchars($ta) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-12 mb-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                            <a href="jadwal_supervisi.php" class="btn btn-light">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h4>Tampilan Kalender</h4></div>
                <div class="card-body"><div id="svCalendar"></div></div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4>Daftar Jadwal Supervisi</h4>
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
                        <table class="table table-striped" id="table-jadwal">
                            <thead>
                                <tr>
                                    <th width="5%">No</th>
                                    <th>Program</th>
                                    <th>Guru/PTK</th>
                                    <th>Jenis</th>
                                    <th>Instrumen</th>
                                    <th>Supervisor</th>
                                    <th>Tanggal</th>
                                    <th>Jam Mulai</th>
                                    <th>Jam Selesai</th>
                                    <th>Tempat</th>
                                    <th>Status</th>
                                    <th>Keterangan</th>
                                    <?php if ($can_manage): ?><th width="10%">Aksi</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <?php
                                    $badge = 'info';
                                    if ($r['status'] === 'Terlaksana') $badge = 'success';
                                    elseif ($r['status'] === 'Ditunda') $badge = 'warning';
                                    elseif ($r['status'] === 'Dibatalkan') $badge = 'danger';
                                    ?>
                                    <tr>
                                        <td class="text-center"></td>
                                        <td><?= htmlspecialchars((string)$r['nama_program']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['nama_guru']) ?></td>
                                        <td><span class="badge badge-info"><?= htmlspecialchars($r['jenis_supervisi']) ?></span></td>
                                        <td><?= htmlspecialchars((string)$r['nama_instrumen']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['supervisor']) ?></td>
                                        <td><?= $r['tanggal'] ? date('d/m/Y', strtotime($r['tanggal'])) : '-' ?></td>
                                        <td><?= $r['jam_mulai'] ? substr($r['jam_mulai'], 0, 5) : '-' ?></td>
                                        <td><?= $r['jam_selesai'] ? substr($r['jam_selesai'], 0, 5) : '-' ?></td>
                                        <td><?= htmlspecialchars((string)$r['tempat']) ?></td>
                                        <td><span class="badge badge-<?= $badge ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                        <td><?= htmlspecialchars((string)$r['keterangan']) ?></td>
                                        <?php if ($can_manage): ?>
                                        <td>
                                            <button class="btn btn-warning btn-sm btn-edit" type="button"
                                                data-row='<?= htmlspecialchars(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>'><i class="fas fa-edit"></i></button>
                                            <button class="btn btn-danger btn-sm btn-hapus" type="button" data-id="<?= (int)$r['id_jadwal'] ?>"><i class="fas fa-trash"></i></button>
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
<div class="modal fade" id="modal-jadwal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" action="jadwal_supervisi.php" id="form-jadwal">
                <input type="hidden" name="aksi" value="tambah">
                <input type="hidden" name="id_jadwal" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Jadwal Supervisi</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Program</label>
                            <select class="form-control" name="id_program">
                                <option value="">- Tanpa Program -</option>
                                <?php foreach ($program_list as $p): ?><option value="<?= (int)$p['id_program'] ?>"><?= htmlspecialchars($p['nama_program']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Guru/PTK</label>
                            <select class="form-control" name="id_guru" required>
                                <option value="">Pilih Guru</option>
                                <?php foreach ($guru_list as $g): ?><option value="<?= (int)$g['id_guru'] ?>"><?= htmlspecialchars($g['nama_guru']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Jenis Supervisi</label>
                            <select class="form-control" name="jenis_supervisi">
                                <?php foreach (sv_jenis_list() as $j): ?><option value="<?= $j ?>"><?= $j ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Instrumen</label>
                            <select class="form-control" name="id_instrumen">
                                <option value="">- Tanpa Instrumen -</option>
                                <?php foreach ($instrumen_list as $i): ?><option value="<?= (int)$i['id_instrumen'] ?>"><?= htmlspecialchars($i['nama_instrumen']) ?> (<?= htmlspecialchars($i['jenis_supervisi']) ?>)</option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Supervisor</label>
                            <select class="form-control" name="supervisor" required>
                                <option value="">-- Pilih Jabatan --</option>
                                <?php foreach ($jabatan_list as $jb): ?><option value="<?= htmlspecialchars($jb['nama_jabatan'], ENT_QUOTES) ?>"><?= htmlspecialchars($jb['nama_jabatan']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Status</label>
                            <select class="form-control" name="status">
                                <?php foreach (sv_status_jadwal_list() as $st): ?><option value="<?= $st ?>"><?= $st ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4"><label>Tanggal</label><input type="date" class="form-control" name="tanggal" required></div>
                        <div class="form-group col-md-4"><label>Jam Mulai</label><input type="time" class="form-control" name="jam_mulai"></div>
                        <div class="form-group col-md-4"><label>Jam Selesai</label><input type="time" class="form-control" name="jam_selesai"></div>
                    </div>
                    <div class="form-group"><label>Tempat</label><input type="text" class="form-control" name="tempat"></div>
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
