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
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
];

$guru_list = sv_guru_list($pdo);
$guru_map = sv_guru_map($pdo);
$mapel_guru = sv_mapel_guru($pdo);
$kelas_guru = sv_kelas_guru($pdo);
$program_list = sv_program_options($pdo);
$mapel_akademik_list = [];
try {
    $mapel_akademik_list = $pdo->query("SELECT nama_mapel FROM tb_mata_pelajaran WHERE jenis_mapel = 'Akademik' ORDER BY nama_mapel ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { $mapel_akademik_list = array_values(array_unique(array_merge(...array_values($mapel_guru ?: [[]])))); }
$mapel_akademik_list = array_values(array_unique(array_filter(array_map('trim', $mapel_akademik_list))));
sort($mapel_akademik_list);

$mapel_by_guru_json = json_encode($mapel_guru, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$kelas_by_guru_json = json_encode($kelas_guru, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $aksi = (string)($_POST['aksi'] ?? '');
    try {
        if ($aksi === 'tambah_massal') {
            $rawIds = $_POST['id_guru'] ?? [];
            $ids = is_array($rawIds) ? $rawIds : ($rawIds !== '' && $rawIds !== null ? [(string)$rawIds] : []);
            $id_program = (int)($_POST['id_program'] ?? 0);
            $jenis = trim((string)($_POST['jenis_supervisi'] ?? 'Akademik'));
            $ta = trim((string)($_POST['tahun_ajaran'] ?? $periode['tahun_ajaran']));
            $sem = trim((string)($_POST['semester'] ?? $periode['semester']));
            $jabatan = trim((string)($_POST['jabatan'] ?? 'Guru Kelas'));
            $rawMp = $_POST['mapel_selected'] ?? '';
            $rawKl = $_POST['kelas_selected'] ?? '';
            $mapel_single = trim((string)$rawMp);
            $kelas_single = trim((string)$rawKl);
            $inserted = 0;

            if (is_array($ids) && $ids) {
                $progJenis = null;
                if ($id_program) {
                    try { $st = $pdo->prepare("SELECT jenis_supervisi FROM tb_sv_program WHERE id_program = ? LIMIT 1"); $st->execute([$id_program]); $progJenis = trim((string)$st->fetchColumn()); } catch (Throwable $e) {}
                }
                if ($progJenis !== null && $progJenis !== '' && strcasecmp($progJenis, $jenis) !== 0) {
                    throw new RuntimeException('Program tidak sesuai dengan Jenis Supervisi.');
                }
                $stmt = $pdo->prepare("INSERT INTO tb_sv_sasaran
                    (id_program, id_guru, nama_guru, nip_npk, jabatan, mata_pelajaran, kelas, jenis_supervisi, tahun_ajaran, semester, status_supervisi)
                    VALUES (?,?,?,?,?,?,?,?,?,?, 'Belum Disupervisi')");
                foreach ($ids as $idGuru) {
                    $idGuru = (int)$idGuru;
                    if ($idGuru <= 0 || !isset($guru_map[$idGuru])) {
                        continue;
                    }
                    $g = $guru_map[$idGuru];
                    $allowedMp = $mapel_guru[$idGuru] ?? [];
                    $allowedKl = $kelas_guru[$idGuru] ?? [];
                    $mapel_val = '';
                    if ($mapel_single !== '') {
                        $mapel_val = ($allowedMp && !in_array($mapel_single, $allowedMp, true)) ? implode(', ', $allowedMp) : $mapel_single;
                    } else {
                        $mapel_val = implode(', ', $allowedMp);
                    }
                    $kelas_val = '';
                    if ($kelas_single !== '') {
                        $kelas_val = ($allowedKl && !in_array($kelas_single, $allowedKl, true)) ? implode(', ', $allowedKl) : $kelas_single;
                    } else {
                        $kelas_val = implode(', ', $allowedKl);
                    }
                    $stmt->execute([
                        $id_program ?: null,
                        $idGuru,
                        $g['nama_guru'],
                        $g['nuptk'] ?? '',
                        $jabatan,
                        $mapel_val,
                        $kelas_val,
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
            $mp = trim((string)($_POST['mata_pelajaran'] ?? ''));
            $kl = trim((string)($_POST['kelas'] ?? ''));
            if ($mp !== '' || $kl !== '') {
                $sid = (int)($_POST['id_sasaran'] ?? 0);
                try {
                    $st = $pdo->prepare("SELECT id_guru FROM tb_sv_sasaran WHERE id_sasaran = ? LIMIT 1");
                    $st->execute([$sid]);
                    $gid = (int)($st->fetchColumn() ?: 0);
                    if ($gid) {
                        if ($mp !== '') {
                            $allow = $mapel_guru[$gid] ?? [];
                            if ($allow && !in_array($mp, $allow, true)) {
                                $mp = $allow[0];
                            }
                        }
                        if ($kl !== '') {
                            $allowK = $kelas_guru[$gid] ?? [];
                            if ($allowK && !in_array($kl, $allowK, true)) {
                                $kl = $allowK[0];
                            }
                        }
                    }
                } catch (Throwable $e) {}
            }
            $stmt = $pdo->prepare("UPDATE tb_sv_sasaran SET id_program=?, jenis_supervisi=?, jabatan=?, mata_pelajaran=?, kelas=?,
                tahun_ajaran=?, semester=?, status_supervisi=?, keterangan=? WHERE id_sasaran=?");
            $stmt->execute([
                (int)($_POST['id_program'] ?? 0) ?: null,
                trim((string)($_POST['jenis_supervisi'] ?? 'Akademik')),
                trim((string)($_POST['jabatan'] ?? '')),
                $mp,
                $kl,
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

$filter_ta = trim((string)($_GET['tahun_ajaran'] ?? $periode['tahun_ajaran']));
$filter_sem = trim((string)($_GET['semester'] ?? ''));

$rows = [];
try {
    $sql = "SELECT s.*, p.nama_program,
            (SELECT MAX(pk.tanggal) FROM tb_sv_pelaksanaan pk WHERE pk.id_guru = s.id_guru AND pk.status = 'Selesai') AS supervisi_terakhir,
            (SELECT pk.nilai FROM tb_sv_pelaksanaan pk WHERE pk.id_guru = s.id_guru AND pk.status = 'Selesai' ORDER BY pk.tanggal DESC, pk.id_pelaksanaan DESC LIMIT 1) AS nilai_terakhir
        FROM tb_sv_sasaran s
        LEFT JOIN tb_sv_program p ON p.id_program = s.id_program";
    $params = [];
    $where = [];
    if ($filter_ta !== '' && $filter_ta !== 'semua') {
        $where[] = "s.tahun_ajaran = ?";
        $params[] = $filter_ta;
    }
    if ($filter_sem !== '') {
        $where[] = "s.semester = ?";
        $params[] = $filter_sem;
    }
    if ($where) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    $sql .= " ORDER BY s.tahun_ajaran DESC, s.nama_guru ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$js_page = [];
$flash = sv_render_flash_js();
if ($flash !== '') {
    $js_page[] = $flash;
}
$js_page[] = 'var svMapelByGuru = ' . $mapel_by_guru_json . ';';
$js_page[] = 'var svKelasByGuru = ' . $kelas_by_guru_json . ';';
$js_page[] = 'var svSasaranData = ' . json_encode($rows) . ';';
$js_page[] = <<<'JS'
$(document).ready(function () {
    function svFilterSasaranCards() {
        var query = $.trim($('#filter-search-sasaran').val() || '').toLowerCase();
        var status = $.trim($('#filter-status-card').val() || '');
        var jenis = $.trim($('#filter-jenis-card').val() || '');
        var visible = 0;
        $('.sasaran-card-col').each(function () {
            var searchTxt = $(this).attr('data-search') || '';
            var cardStatus = $(this).attr('data-status') || '';
            var cardJenis = $(this).attr('data-jenis') || '';
            var matchQuery = !query || searchTxt.indexOf(query) !== -1;
            var matchStatus = !status || cardStatus === status;
            var matchJenis = !jenis || cardJenis === jenis;
            if (matchQuery && matchStatus && matchJenis) {
                $(this).removeClass('d-none');
                visible++;
            } else {
                $(this).addClass('d-none');
            }
        });
        $('#empty-search-msg').toggle(visible === 0);
        $('#sasaran-count-badge').text(visible + ' Guru/PTK');
    }

    $('#filter-search-sasaran').on('input', svFilterSasaranCards);
    $('#filter-status-card').on('change', svFilterSasaranCards);
    $('#filter-jenis-card').on('change', svFilterSasaranCards);

    function escapeHtml(s) { return String(s ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
    function svBuildAddDropdowns() {
        var gid = $('#sv-guru-select').val();
        var mpKeys = (svMapelByGuru[gid] || svMapelByGuru[String(gid)] || []);
        var klKeys = (svKelasByGuru[gid] || svKelasByGuru[String(gid)] || []);
        var $mp = $('#sv-add-mapel-select'); var prevMp = $mp.val();
        $mp.empty();
        if (!gid) $mp.append('<option value="">Pilih Guru dulu</option>');
        else {
            $mp.append('<option value="">Semua mapel guru</option>');
            mpKeys.forEach(function(mp){ $mp.append('<option value="' + escapeHtml(mp) + '">' + escapeHtml(mp) + '</option>'); });
            if (prevMp && (mpKeys.indexOf(prevMp) !== -1 || prevMp === '')) $mp.val(prevMp);
        }
        var $kl = $('#sv-add-kelas-select'); var prevKl = $kl.val();
        $kl.empty();
        if (!gid) $kl.append('<option value="">Pilih Guru dulu</option>');
        else {
            $kl.append('<option value="">Semua kelas guru</option>');
            klKeys.forEach(function(kl){ $kl.append('<option value="' + escapeHtml(kl) + '">' + escapeHtml(kl) + '</option>'); });
            if (prevKl && (klKeys.indexOf(prevKl) !== -1 || prevKl === '')) $kl.val(prevKl);
        }
    }
    function svFilterProgram() {
        var jenis = $('#sv-jenis-select').val();
        $('#sv-program-select option').each(function () {
            var dj = $(this).data('jenis');
            if (!dj) return;
            $(this).toggle(!jenis || String(dj) === String(jenis));
        });
        if ($('#sv-program-select option:selected').is(':hidden')) { $('#sv-program-select').val(''); }
    }
    $('#sv-guru-select').on('change', function () { svBuildAddDropdowns(); });
    $('#sv-jenis-select').on('change', function () { svFilterProgram(); });
    $('#form-sasaran-massal').on('submit', function (e) {
        if (!$('#sv-guru-select').val()) {
            e.preventDefault();
            Swal.fire({ icon: 'warning', title: 'Pilih Guru', text: 'Pilih 1 guru/PTK.' });
            return false;
        }
    });
    $('#btn-tambah').on('click', function () {
        $('#form-sasaran-massal')[0].reset();
        svBuildAddDropdowns();
        $('#sv-program-select').val('');
        svFilterProgram();
        $('#modal-sasaran .modal-title').text('Tambah Sasaran Supervisi');
        $('#modal-sasaran').modal('show');
    });
    $(document).on('click', '.btn-edit', function () {
        var d = $(this).data('row');
        $('#form-sasaran-edit')[0].reset();
        Object.keys(d).forEach(function (k) {
            var el = $('#form-sasaran-edit [name="' + k + '"]');
            if (el.length && k !== 'mata_pelajaran' && k !== 'kelas') { el.val(d[k]); }
        });
        $('#sv-edit-nama-guru').val(d.nama_guru || '');
        var tid = d.id_guru;
        var mpOpts = tid ? (svMapelByGuru[tid] || svMapelByGuru[String(tid)] || []) : [];
        var klOpts = tid ? (svKelasByGuru[tid] || svKelasByGuru[String(tid)] || []) : [];
        var $em = $('#sv-edit-mapel-select'); $em.empty().append('<option value="">Semua mapel</option>');
        mpOpts.forEach(function(mp){ $em.append('<option value="' + escapeHtml(mp) + '">' + escapeHtml(mp) + '</option>'); });
        var curMp = String(d.mata_pelajaran || '').split(',').map(function(s){return s.trim();}).filter(Boolean)[0] || '';
        if (curMp && mpOpts.indexOf(curMp) !== -1) $em.val(curMp);
        var $ek = $('#sv-edit-kelas-select'); $ek.empty().append('<option value="">Semua kelas</option>');
        klOpts.forEach(function(kl){ $ek.append('<option value="' + escapeHtml(kl) + '">' + escapeHtml(kl) + '</option>'); });
        var curKl = String(d.kelas || '').split(',').map(function(s){return s.trim();}).filter(Boolean)[0] || '';
        if (curKl && klOpts.indexOf(curKl) !== -1) $ek.val(curKl);
        $('#modal-sasaran-edit').modal('show');
    });
    $(document).on('click', '.btn-hapus', function () {
        var id = $(this).data('id');
        Swal.fire({title:'Konfirmasi Hapus',text:'Sasaran supervisi akan dihapus.',icon:'warning',showCancelButton:true,confirmButtonColor:'#d33',cancelButtonColor:'#6c757d',confirmButtonText:'Ya, Hapus!',cancelButtonText:'Batal'}).then(function(r){if(r.isConfirmed){var f=document.createElement('form');f.method='POST';f.action='sasaran_supervisi.php';var fields={aksi:'hapus',id_sasaran:id};Object.keys(fields).forEach(function(k){var i=document.createElement('input');i.type='hidden';i.name=k;i.value=fields[k];f.appendChild(i);});document.body.appendChild(f);f.submit();}});
    });
    $('#btn-excel').on('click', function () {
        var data = [];
        data.push(['No', 'Nama Guru', 'NUPTK / NIP', 'Jabatan', 'Mata Pelajaran', 'Kelas', 'Jenis Supervisi', 'Program Supervisi', 'Tahun Ajaran', 'Semester', 'Status Supervisi', 'Supervisi Terakhir', 'Nilai Terakhir']);
        
        if (typeof svSasaranData !== 'undefined' && Array.isArray(svSasaranData)) {
            svSasaranData.forEach(function (r, i) {
                data.push([
                    i + 1,
                    r.nama_guru || '-',
                    r.nip_npk || '-',
                    r.jabatan || '-',
                    r.mata_pelajaran || '-',
                    r.kelas || '-',
                    r.jenis_supervisi || '-',
                    r.nama_program || '-',
                    r.tahun_ajaran || '-',
                    r.semester || '-',
                    r.status_supervisi || '-',
                    r.supervisi_terakhir ? r.supervisi_terakhir : '-',
                    r.nilai_terakhir !== null ? Number(r.nilai_terakhir).toFixed(2) : '-'
                ]);
            });
        }
        
        if (typeof XLSX !== 'undefined') {
            var ws = XLSX.utils.aoa_to_sheet(data);
            var wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Sasaran Supervisi");
            XLSX.writeFile(wb, 'sasaran_supervisi.xlsx');
        } else {
            alert('Library XLSX tidak tersedia');
        }
    });
    $('#btn-pdf').on('click', function () { window.open('cetak_supervisi.php?page=sasaran', '_blank'); });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<?php
$total_sasaran = count($rows);
$count_sudah = count(array_filter($rows, fn($r) => ($r['status_supervisi'] ?? '') === 'Sudah Disupervisi'));
$count_belum = $total_sasaran - $count_sudah;
$persen_selesai = $total_sasaran > 0 ? round(($count_sudah / $total_sasaran) * 100) : 0;
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
            <h1>Sasaran Supervisi</h1>
            <?php echo render_breadcrumb(); ?>
        </div>
        <div class="section-body">
            <input type="hidden" id="svSchoolName" value="<?= htmlspecialchars($school_profile['nama_madrasah'] ?? 'MADRASAH', ENT_QUOTES) ?>">
            <input type="hidden" id="svSchoolLogo" value="<?= !empty($school_profile['logo']) ? '../assets/img/' . htmlspecialchars($school_profile['logo'], ENT_QUOTES) : '' ?>">
            <input type="hidden" id="svAcademicYear" value="<?= htmlspecialchars($filter_ta !== '' ? $filter_ta : $periode['tahun_ajaran'], ENT_QUOTES) ?>">
            <input type="hidden" id="svSemester" value="<?= htmlspecialchars($filter_sem !== '' ? $filter_sem : $periode['semester'], ENT_QUOTES) ?>">
            <input type="hidden" id="svHeadName" value="<?= htmlspecialchars($school_profile['nama_kepala'] ?? '-', ENT_QUOTES) ?>">
            <input type="hidden" id="svHeadNip" value="<?= htmlspecialchars($school_profile['nip_kepala'] ?? '-', ENT_QUOTES) ?>">
            <input type="hidden" id="svPrintPlace" value="<?= htmlspecialchars($school_profile['tempat_jadwal'] ?? 'Padang', ENT_QUOTES) ?>">
            <input type="hidden" id="svPrintDate" value="<?= date('d F Y') ?>">

            <!-- Filter & Search Card -->
            <div class="card mb-3">
                <div class="card-body p-3">
                    <form method="GET" id="form-sasaran-filter" class="form-row align-items-end">
                        <div class="form-group col-12 col-md-3 mb-2 mb-md-0">
                            <label class="small font-weight-bold">Tahun Ajaran</label>
                            <select class="form-control form-control-sm" name="tahun_ajaran" onchange="this.form.submit()">
                                <option value="semua" <?= $filter_ta === 'semua' ? 'selected' : '' ?>>Semua Tahun Ajaran</option>
                                <?php foreach (sv_tahun_ajaran_options($pdo) as $ta): ?>
                                    <option value="<?= htmlspecialchars($ta, ENT_QUOTES) ?>" <?= $ta === $filter_ta ? 'selected' : '' ?>><?= htmlspecialchars($ta) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-12 col-md-2 mb-2 mb-md-0">
                            <label class="small font-weight-bold">Semester</label>
                            <select class="form-control form-control-sm" name="semester" onchange="this.form.submit()">
                                <option value="">Semua Semester</option>
                                <?php foreach (sv_semester_options() as $s): ?>
                                    <option value="<?= htmlspecialchars($s, ENT_QUOTES) ?>" <?= $s === $filter_sem ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-12 col-md-2 mb-2 mb-md-0">
                            <label class="small font-weight-bold">Status</label>
                            <select class="form-control form-control-sm" id="filter-status-card">
                                <option value="">Semua Status</option>
                                <option value="Sudah Disupervisi">Sudah Disupervisi</option>
                                <option value="Belum Disupervisi">Belum Disupervisi</option>
                            </select>
                        </div>
                        <div class="form-group col-12 col-md-2 mb-2 mb-md-0">
                            <label class="small font-weight-bold">Jenis</label>
                            <select class="form-control form-control-sm" id="filter-jenis-card">
                                <option value="">Semua Jenis</option>
                                <option value="Akademik">Akademik</option>
                                <option value="Administrasi">Administrasi</option>
                            </select>
                        </div>
                        <div class="form-group col-12 col-md-3 mb-0">
                            <label class="small font-weight-bold">Cari Guru / Mapel</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="filter-search-sasaran" placeholder="Cari nama, NUPTK, mapel, kelas...">
                                <div class="input-group-append">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Stats Chips -->
            <div class="d-flex flex-wrap align-items-center mb-3" style="gap: 8px;">
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-users text-primary mr-1"></i> Total: <strong id="sasaran-count-badge"><?= (int)$total_sasaran ?> Guru/PTK</strong>
                </span>
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-check-circle text-success mr-1"></i> Sudah Disupervisi: <strong><?= (int)$count_sudah ?></strong> <small class="text-success font-weight-bold">(<?= $persen_selesai ?>%)</small>
                </span>
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-clock text-warning mr-1"></i> Belum Disupervisi: <strong><?= (int)$count_belum ?></strong>
                </span>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4>Daftar Sasaran Supervisi <small class="text-muted"><?= $filter_ta !== 'semua' && $filter_ta !== '' ? 'TA ' . htmlspecialchars($filter_ta) : 'Semua Periode' ?><?= $filter_sem !== '' ? ' &bull; ' . htmlspecialchars($filter_sem) : '' ?></small></h4>
                    <div class="card-header-action">
                        <button class="btn btn-success" id="btn-excel" type="button"><i class="fas fa-file-excel"></i> Excel</button>
                        <button class="btn btn-warning" id="btn-pdf" type="button"><i class="fas fa-file-pdf"></i> PDF</button>
                        <?php if ($can_manage): ?>
                        <button class="btn btn-primary" id="btn-tambah" type="button"><i class="fas fa-plus"></i> Tambah dari Master PTK</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($rows)): ?>
                        <div class="alert alert-info text-center py-4">
                            <i class="fas fa-info-circle fa-2x mb-2 d-block text-info"></i>
                            Belum ada sasaran supervisi pada filter periode ini.
                            <?php if ($can_manage): ?>
                                <br><small class="text-muted">Klik tombol <strong>"Tambah dari Master PTK"</strong> untuk menetapkan sasaran baru.</small>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div id="empty-search-msg" class="alert alert-warning text-center" style="display:none;">
                            <i class="fas fa-search mr-1"></i> Tidak ada sasaran supervisi yang cocok dengan filter pencarian Anda.
                        </div>
                        <div class="row" id="sasaran-cards-container">
                            <?php foreach ($rows as $r): ?>
                                <?php
                                $isDone = ($r['status_supervisi'] === 'Sudah Disupervisi');
                                $statusBadge = $isDone ? 'success' : 'warning';
                                $searchBlob = strtolower(implode(' ', [
                                    $r['nama_guru'] ?? '',
                                    $r['nip_npk'] ?? '',
                                    $r['jabatan'] ?? '',
                                    $r['mata_pelajaran'] ?? '',
                                    $r['kelas'] ?? '',
                                    $r['nama_program'] ?? '',
                                    $r['jenis_supervisi'] ?? '',
                                    $r['status_supervisi'] ?? ''
                                ]));
                                ?>
                                <div class="col-12 col-md-6 col-lg-4 d-flex align-items-stretch mb-4 sasaran-card-col"
                                     data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES) ?>"
                                     data-status="<?= htmlspecialchars($r['status_supervisi'] ?? '', ENT_QUOTES) ?>"
                                     data-jenis="<?= htmlspecialchars($r['jenis_supervisi'] ?? '', ENT_QUOTES) ?>">
                                    <div class="card w-100 border-0 shadow-sm hover-shadow transition" style="border-radius: 12px; overflow: hidden; background: #fff; border: 1px solid #e2e8f0 !important;">
                                        <div class="card-body p-3 d-flex flex-column justify-content-between">
                                            <div>
                                                <!-- Top Bar: Jenis & Status Supervisi -->
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <span class="badge badge-info px-2 py-1 font-weight-bold" style="font-size: 11.5px; border-radius: 6px;">
                                                        <i class="fas fa-clipboard-check mr-1"></i> <?= htmlspecialchars($r['jenis_supervisi']) ?>
                                                    </span>
                                                    <span class="badge badge-<?= $statusBadge ?> px-2 py-1 font-weight-bold" style="font-size: 11px; border-radius: 6px;">
                                                        <i class="fas <?= $isDone ? 'fa-check-circle' : 'fa-clock' ?> mr-1"></i> <?= htmlspecialchars($r['status_supervisi']) ?>
                                                    </span>
                                                </div>

                                                <!-- Nama Guru & NUPTK/Jabatan -->
                                                <h6 class="font-weight-bold text-dark mb-1" style="font-size: 15px; line-height: 1.4;">
                                                    <?= htmlspecialchars($r['nama_guru']) ?>
                                                </h6>
                                                <div class="text-muted small mb-2 d-flex flex-wrap align-items-center" style="gap: 6px; font-size: 11.5px;">
                                                    <span><i class="fas fa-id-card text-muted mr-1"></i> <?= htmlspecialchars((string)($r['nip_npk'] ?: '-')) ?></span>
                                                    <span>&bull;</span>
                                                    <span class="badge badge-light border text-dark font-weight-normal"><?= htmlspecialchars((string)($r['jabatan'] ?: 'Guru')) ?></span>
                                                </div>

                                                <!-- Academic Info Badges -->
                                                <div class="d-flex flex-wrap align-items-center mb-3" style="gap: 6px;">
                                                    <?php if (!empty($r['mata_pelajaran'])): ?>
                                                        <span class="badge badge-light border text-dark font-weight-bold px-2 py-1" style="border-radius: 4px; font-size: 12px;">
                                                            <i class="fas fa-book text-muted mr-1"></i> <?= htmlspecialchars($r['mata_pelajaran']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($r['kelas'])): ?>
                                                        <span class="badge badge-light border text-dark font-weight-bold px-2 py-1" style="border-radius: 4px; font-size: 12px;">
                                                            <i class="fas fa-chalkboard-teacher text-muted mr-1"></i> Kls <?= htmlspecialchars($r['kelas']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <span class="badge badge-light border text-dark px-2 py-1" style="border-radius: 4px; font-size: 12px;">
                                                        <i class="far fa-calendar-alt text-muted mr-1"></i> <?= htmlspecialchars($r['tahun_ajaran']) ?> (<?= htmlspecialchars($r['semester']) ?>)
                                                    </span>
                                                </div>

                                                <!-- Program Supervisi Box -->
                                                <div class="p-2 mb-3 rounded" style="background: #f8fafc; font-size: 12px; border: 1px dashed #cbd5e1; line-height: 1.4;">
                                                    <small class="text-muted d-block font-weight-bold mb-1" style="font-size: 10.5px;"><i class="fas fa-project-diagram text-primary mr-1"></i> Program Supervisi:</small>
                                                    <span class="text-dark font-weight-bold"><?= htmlspecialchars((string)($r['nama_program'] ?: 'Belum ditautkan ke program')) ?></span>
                                                </div>

                                                <!-- Riwayat Supervisi & Nilai Terakhir -->
                                                <div class="row mb-2">
                                                    <div class="col-6">
                                                        <div class="text-center p-2 rounded" style="background: #f8fafc; height: 100%;">
                                                            <small class="text-muted d-block mb-1" style="font-size: 11px;">Supervisi Terakhir</small>
                                                            <span class="font-weight-bold text-dark" style="font-size: 12px;">
                                                                <i class="far fa-calendar-check text-success mr-1"></i> <?= $r['supervisi_terakhir'] ? date('d/m/Y', strtotime($r['supervisi_terakhir'])) : '-' ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div class="col-6">
                                                        <div class="text-center p-2 rounded" style="background: #f8fafc; height: 100%;">
                                                            <small class="text-muted d-block mb-1" style="font-size: 11px;">Nilai Terakhir</small>
                                                            <?php if ($r['nilai_terakhir'] !== null): ?>
                                                                <h5 class="mb-0 text-primary font-weight-bold" style="font-size: 18px;"><?= number_format((float)$r['nilai_terakhir'], 2) ?></h5>
                                                                <?php 
                                                                $pred = sv_predikat($r['nilai_terakhir']);
                                                                $predColor = 'info';
                                                                if (stripos($pred, 'Sangat Baik') !== false || stripos($pred, 'Amat Baik') !== false || stripos($pred, 'A') === 0) {
                                                                    $predColor = 'success';
                                                                } elseif (stripos($pred, 'Baik') !== false || stripos($pred, 'B') === 0) {
                                                                    $predColor = 'primary';
                                                                } elseif (stripos($pred, 'Cukup') !== false || stripos($pred, 'C') === 0) {
                                                                    $predColor = 'warning';
                                                                } elseif (stripos($pred, 'Kurang') !== false || stripos($pred, 'D') === 0 || stripos($pred, 'E') === 0) {
                                                                    $predColor = 'danger';
                                                                }
                                                                ?>
                                                                <span class="badge badge-<?= $predColor ?> font-weight-bold mt-1" style="font-size: 11px; padding: 4px 10px; border-radius: 6px;"><?= htmlspecialchars($pred) ?></span>
                                                            <?php else: ?>
                                                                <span class="text-muted font-italic" style="font-size: 11.5px;">Belum dinilai</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Footer: Aksi -->
                                            <?php if ($can_manage): ?>
                                            <div class="pt-3 mt-2 border-top d-flex justify-content-end" style="gap: 6px;">
                                                <button class="btn btn-outline-warning btn-sm btn-edit px-3" type="button"
                                                    data-row='<?= htmlspecialchars(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>' style="border-radius: 6px; font-size: 12px;">
                                                    <i class="fas fa-edit mr-1"></i> Edit
                                                </button>
                                                <button class="btn btn-outline-danger btn-sm btn-hapus px-3" type="button" data-id="<?= (int)$r['id_sasaran'] ?>" style="border-radius: 6px; font-size: 12px;">
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
                        <label>Pilih Guru/PTK <span class="text-danger">*</span></label>
                        <select class="form-control" name="id_guru" id="sv-guru-select" required>
                            <option value="">Pilih Guru</option>
                            <?php foreach ($guru_list as $g): ?>
                                <option value="<?= (int)$g['id_guru'] ?>"><?= htmlspecialchars($g['nama_guru']) ?><?= !empty($g['nuptk']) ? ' - ' . htmlspecialchars($g['nuptk']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Jenis Supervisi</label>
                            <select class="form-control" name="jenis_supervisi" id="sv-jenis-select">
                                <?php foreach (sv_jenis_list() as $j): ?><option value="<?= $j ?>"><?= $j ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Program Supervisi</label>
                            <select class="form-control" name="id_program" id="sv-program-select">
                                <option value="">- Tanpa Program -</option>
                                <?php foreach ($program_list as $p): ?>
                                    <option value="<?= (int)$p['id_program'] ?>" data-jenis="<?= htmlspecialchars($p['jenis_supervisi'], ENT_QUOTES) ?>"><?= htmlspecialchars($p['nama_program']) ?> (<?= htmlspecialchars($p['jenis_supervisi']) ?>)</option>
                                <?php endforeach; ?>
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
                        <select class="form-control" name="jabatan">
                            <option value="Guru Kelas">Guru Kelas</option>
                            <option value="Guru Mapel">Guru Mapel</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Mata Pelajaran yang Disupervisi</label>
                            <select class="form-control" name="mapel_selected" id="sv-add-mapel-select">
                                <option value="">Pilih Guru dulu</option>
                            </select>
                            <small class="text-muted">Kosong = semua mapel guru terpilih.</small>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Kelas yang Disupervisi</label>
                            <select class="form-control" name="kelas_selected" id="sv-add-kelas-select">
                                <option value="">Pilih Guru dulu</option>
                            </select>
                            <small class="text-muted">Kosong = semua kelas guru terpilih.</small>
                        </div>
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
                    <div class="form-group"><label>Nama Guru</label><input type="text" class="form-control" id="sv-edit-nama-guru" value="" readonly><small class="text-muted">Tidak dapat diubah.</small></div>
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
                    <div class="form-group">
                        <label>Jabatan</label>
                        <select class="form-control" name="jabatan">
                            <option value="Guru Kelas">Guru Kelas</option>
                            <option value="Guru Mapel">Guru Mapel</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6"><label>Mata Pelajaran yang Disupervisi</label><select class="form-control" name="mata_pelajaran" id="sv-edit-mapel-select"><option value="">Semua mapel</option></select></div>
                        <div class="form-group col-md-6"><label>Kelas yang Disupervisi</label><select class="form-control" name="kelas" id="sv-edit-kelas-select"><option value="">Semua kelas</option></select></div>
                    </div>
                    <div class="form-group">
                        <label>Status Supervisi</label>
                        <select class="form-control" name="status_supervisi">
                            <option value="Belum Disupervisi">Belum Disupervisi</option>
                            <option value="Sudah Disupervisi">Sudah Disupervisi</option>
                        </select>
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
