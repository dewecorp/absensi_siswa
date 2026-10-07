<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['wali', 'admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$is_admin_or_kepala = in_array($user_level, ['admin', 'kepala_madrasah'], true) || in_array($_GET['session_type'] ?? '', ['admin', 'kepala_madrasah'], true);
$can_crud = !$is_admin_or_kepala;
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$wali_kelas_id = 0;
$wali_kelas_name = '';
$stmtWali = $pdo->prepare("SELECT id_kelas, nama_kelas FROM tb_kelas WHERE wali_kelas = ? OR wali_kelas = (SELECT nama_guru FROM tb_guru WHERE id_guru = ?)");
$stmtWali->execute([$guru_id, $guru_id]);
$wali_class = $stmtWali->fetch(PDO::FETCH_ASSOC);
if ($wali_class) {
    $wali_kelas_id = (int)$wali_class['id_kelas'];
    $wali_kelas_name = $wali_class['nama_kelas'];
}

$all_classes = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);

$selected_kelas_id = $wali_kelas_id;
$selected_kelas_name = $wali_kelas_name;
if ($is_admin_or_kepala) {
    $selected_kelas_id = (int)($_GET['kelas'] ?? 0);
    if ($selected_kelas_id <= 0 && isset($_GET['f_kelas'])) {
        $selected_kelas_id = (int)$_GET['f_kelas'];
    }
    $selected_kelas_name = '';
    foreach ($all_classes as $c) {
        if ((int)$c['id_kelas'] === $selected_kelas_id) {
            $selected_kelas_name = (string)$c['nama_kelas'];
            break;
        }
    }
}

$message = null;

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_crud) {
        $message = ['type' => 'danger', 'text' => 'Akses ditolak. Pengguna hanya memiliki akses lihat (monitoring).'];
    } else {
        $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $id_kelas = (int)($_POST['id_kelas'] ?? $selected_kelas_id);
        $tanggal = !empty($_POST['tanggal']) ? date('Y-m-d', strtotime($_POST['tanggal'])) : date('Y-m-d');
        $tanggal_mulai = !empty($_POST['tanggal_mulai']) ? date('Y-m-d', strtotime($_POST['tanggal_mulai'])) : $tanggal;
        $tanggal_selesai = !empty($_POST['tanggal_selesai']) ? date('Y-m-d', strtotime($_POST['tanggal_selesai'])) : $tanggal_mulai;
        if ($tanggal_selesai < $tanggal_mulai) { $tmpT = $tanggal_mulai; $tanggal_mulai = $tanggal_selesai; $tanggal_selesai = $tmpT; }
        $tanggal = $tanggal_mulai;
        $waktu_mulai = !empty($_POST['waktu_mulai']) ? date('H:i:s', strtotime($_POST['waktu_mulai'])) : null;
        $waktu_selesai = !empty($_POST['waktu_selesai']) ? date('H:i:s', strtotime($_POST['waktu_selesai'])) : null;
        $nama_agenda = trim((string)($_POST['nama_agenda'] ?? ''));
        $jenis = in_array($_POST['jenis'] ?? '', ['Ujian', 'Kegiatan Kelas', 'Kegiatan Madrasah', 'Piket', 'Projek', 'Kokurikuler'], true) ? $_POST['jenis'] : 'Kegiatan Kelas';
        $tempat = trim((string)($_POST['tempat'] ?? 'Ruang Kelas'));
        $penanggung_jawab = trim((string)($_POST['penanggung_jawab'] ?? 'Wali Kelas'));
        $status = in_array($_POST['status'] ?? '', ['Rencana', 'Berjalan', 'Selesai', 'Batal'], true) ? $_POST['status'] : 'Rencana';
        $keterangan = trim((string)($_POST['keterangan'] ?? ''));

        if ($nama_agenda === '') {
            $message = ['type' => 'warning', 'text' => 'Nama Agenda wajib diisi.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_agenda_kelas (
                            id_wali, id_kelas, tanggal, tanggal_mulai, tanggal_selesai, waktu_mulai, waktu_selesai,
                            nama_agenda, jenis, tempat, penanggung_jawab, status, keterangan
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $guru_id, $id_kelas, $tanggal, $tanggal_mulai, $tanggal_selesai, $waktu_mulai, $waktu_selesai,
                        $nama_agenda, $jenis, $tempat, $penanggung_jawab, $status, $keterangan
                    ]);
                    $message = ['type' => 'success', 'text' => 'Agenda kelas berhasil ditambahkan.'];
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE tb_agenda_kelas SET
                            id_kelas = ?, tanggal = ?, tanggal_mulai = ?, tanggal_selesai = ?, waktu_mulai = ?, waktu_selesai = ?,
                            nama_agenda = ?, jenis = ?, tempat = ?, penanggung_jawab = ?,
                            status = ?, keterangan = ?
                        WHERE id = ? " . (!$is_admin_or_kepala ? "AND id_wali = $guru_id" : "") . "
                    ");
                    $stmt->execute([
                        $id_kelas, $tanggal, $tanggal_mulai, $tanggal_selesai, $waktu_mulai, $waktu_selesai,
                        $nama_agenda, $jenis, $tempat, $penanggung_jawab,
                        $status, $keterangan, $id
                    ]);
                    $message = ['type' => 'success', 'text' => 'Agenda kelas berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM tb_agenda_kelas WHERE id = ? " . (!$is_admin_or_kepala ? "AND id_wali = $guru_id" : ""))->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Agenda kelas berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
}
}

// Filters
$f_jenis = trim((string)($_GET['f_jenis'] ?? ''));
$f_status = trim((string)($_GET['f_status'] ?? ''));

$rows = [];
$calendar_events = [];
$where = ["1=1"];
$params = [];
if ($selected_kelas_id > 0) {
    $where[] = "a.id_kelas = ?";
    $params[] = $selected_kelas_id;
}
if (!$is_admin_or_kepala) {
    $where[] = "a.id_wali = ?";
    $params[] = $guru_id;
}
if ($f_jenis !== '') {
    $where[] = "a.jenis = ?";
    $params[] = $f_jenis;
}
if ($f_status !== '') {
    $where[] = "a.status = ?";
    $params[] = $f_status;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT a.*, c.nama_kelas,
           COALESCE(a.tanggal_mulai, a.tanggal) AS tgl_mulai_ef,
           COALESCE(a.tanggal_selesai, COALESCE(a.tanggal_mulai, a.tanggal)) AS tgl_selesai_ef
    FROM tb_agenda_kelas a
    LEFT JOIN tb_kelas c ON c.id_kelas = a.id_kelas
    WHERE $where_sql
    ORDER BY tgl_mulai_ef ASC, a.waktu_mulai ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$jenis_options = ['Ujian', 'Kegiatan Kelas', 'Kegiatan Madrasah', 'Piket', 'Projek', 'Kokurikuler'];
$status_options = ['Rencana', 'Berjalan', 'Selesai', 'Batal'];

// Siapkan event JSON untuk tampilan kalender
// FullCalendar end eksklusif: all-day +1 hari, timed pakai jam asli tanpa +1 hari.
$agenda_color_map = [
    'Kegiatan Kelas' => '#6777ef',
    'Ujian' => '#ffa426',
    'Kegiatan Madrasah' => '#47c363',
    'Piket' => '#3abaf4',
    'Projek' => '#9467ef',
    'Kokurikuler' => '#20c997',
];
$calendar_events = [];
$legend_used = [];
foreach ($rows as $r) {
    $ev_start_tgl = $r['tgl_mulai_ef'] ?? $r['tanggal'];
    $ev_end_tgl = $r['tgl_selesai_ef'] ?? $ev_start_tgl;
    $is_libur = stripos(($r['nama_agenda'] ?? '') . ' ' . ($r['jenis'] ?? '') . ' ' . ($r['keterangan'] ?? ''), 'libur') !== false;
    if ($is_libur) {
        $color = '#dc3545';
        $legend_used['Libur'] = '#dc3545';
    } else {
        $jenis_ev = (string)($r['jenis'] ?? '');
        $color = $agenda_color_map[$jenis_ev] ?? '#6777ef';
        if ($jenis_ev !== '') $legend_used[$jenis_ev] = $color;
    }

    $has_time = !empty($r['waktu_mulai']) || !empty($r['waktu_selesai']);
    if (!$has_time) {
        $ev_start = $ev_start_tgl;
        $ev_end = date('Y-m-d', strtotime($ev_end_tgl . ' +1 day'));
    } elseif ($ev_start_tgl === $ev_end_tgl) {
        $ev_start = $ev_start_tgl . (!empty($r['waktu_mulai']) ? 'T' . substr((string)$r['waktu_mulai'], 0, 5) : '');
        $ev_end = $ev_end_tgl . (!empty($r['waktu_selesai']) ? 'T' . substr((string)$r['waktu_selesai'], 0, 5) : (!empty($r['waktu_mulai']) ? 'T' . substr((string)$r['waktu_mulai'], 0, 5) : ''));
    } else {
        $ev_start = $ev_start_tgl . (!empty($r['waktu_mulai']) ? 'T' . substr((string)$r['waktu_mulai'], 0, 5) : '');
        $ev_end = $ev_end_tgl . (!empty($r['waktu_selesai']) ? 'T' . substr((string)$r['waktu_selesai'], 0, 5) : '');
    }
    $calendar_events[] = [
        'id' => $r['id'],
        'title' => $r['nama_agenda'],
        'start' => $ev_start,
        'end' => $ev_end,
        'allDay' => !$has_time,
        'backgroundColor' => $color,
        'borderColor' => $color,
        'textColor' => '#ffffff',
        'extendedProps' => $r
    ];
}

$page_title = 'Daftar Agenda Kelas';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js',
];

$calendar_events_json = json_encode($calendar_events);
$js_page = [<<<JS
var calendarEvents = $calendar_events_json;
$(document).ready(function() {
    if ($('#table-agenda').length) {
        $('#table-agenda').DataTable({
            'order': [[1, 'asc']],
            'columnDefs': [{ 'sortable': false, 'targets': [9] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada agenda ditemukan',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    // FullCalendar Init
    var calendarEl = document.getElementById('calendarView');
    var calendar = null;
    if (calendarEl) {
        calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,listMonth'
            },
            locale: 'id',
            buttonText: {
                today: 'Hari Ini',
                month: 'Bulan',
                week: 'Minggu',
                list: 'Agenda List'
            },
            displayEventEnd: false,
            displayEventTime: false,
            eventDisplay: 'block',
            eventTextColor: '#ffffff',
            events: calendarEvents,
            eventClick: function(info) {
                var data = info.event.extendedProps;
                showDetailAgenda(data);
            }
        });
    }

    $('a[data-toggle="tab"]').on('shown.bs.tab', function(e) {
        if (e.target.id === 'tab-kalender-link' && calendar) {
            calendar.render();
            calendar.updateSize();
        }
    });

    $('#btnTambahAgenda').on('click', function() {
        $('#formAgendaAction').val('tambah');
        $('#agendaId').val('');
        $('#modalAgendaTitle').text('Tambah Agenda Kelas Baru');
        $('#formAgenda')[0].reset();
        $('#modalAgenda').modal('show');
    });

    function showDetailAgenda(data) {
        var tglM = data.tanggal_mulai || data.tgl_mulai_ef || data.tanggal;
        var tglS = data.tanggal_selesai || data.tgl_selesai_ef || tglM;
        $('#det_tanggal').text(tglM === tglS ? tglM : (tglM + ' s/d ' + tglS));
        var waktu = (data.waktu_mulai ? data.waktu_mulai.substring(0,5) : '-') + ' s/d ' + (data.waktu_selesai ? data.waktu_selesai.substring(0,5) : '-');
        $('#det_waktu').text(waktu);
        $('#det_nama').text(data.nama_agenda);
        $('#det_jenis').html('<span class="badge badge-info">' + data.jenis + '</span>');
        $('#det_kelas').text(data.nama_kelas || '-');
        $('#det_tempat').text(data.tempat || '-');
        $('#det_pj').text(data.penanggung_jawab || '-');
        $('#det_status').text(data.status);
        $('#det_ket').text(data.keterangan || '-');
        $('#modalDetailAgenda').modal('show');
    }

    $(document).on('click', '.btn-detail-agenda', function() {
        var data = $(this).data('json');
        showDetailAgenda(data);
    });

    $(document).on('click', '.btn-edit-agenda', function() {
        var data = $(this).data('json');
        $('#formAgendaAction').val('edit');
        $('#agendaId').val(data.id);
        $('#modalAgendaTitle').text('Edit Agenda Kelas');
        $('#inp_tanggal').val(data.tanggal);
        $('#inp_tanggal_mulai').val(data.tanggal_mulai || data.tgl_mulai_ef || data.tanggal);
        $('#inp_tanggal_selesai').val(data.tanggal_selesai || data.tgl_selesai_ef || data.tanggal);
        $('#inp_mulai').val(data.waktu_mulai ? data.waktu_mulai.substring(0,5) : '');
        $('#inp_selesai').val(data.waktu_selesai ? data.waktu_selesai.substring(0,5) : '');
        $('#inp_nama').val(data.nama_agenda);
        $('#inp_jenis').val(data.jenis);
        $('#inp_tempat').val(data.tempat || '');
        $('#inp_pj').val(data.penanggung_jawab || '');
        $('#inp_status').val(data.status);
        $('#inp_ket').val(data.keterangan || '');
        $('#modalAgenda').modal('show');
    });

    $(document).on('click', '.btn-hapus-agenda', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        Swal.fire({
            title: 'Hapus Agenda?',
            text: 'Agenda "' + nama + '" akan dihapus.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus',
            cancelButtonText: 'Batal'
        }).then(function(res) {
            if (res.isConfirmed) {
                $('#formHapusId').val(id);
                $('#formHapus').submit();
            }
        });
    });
});
JS
];

if (!empty($message)) {
    $js_page[] = "Swal.fire({ icon: '" . ($message['type'] === 'danger' ? 'error' : $message['type']) . "', title: '" . ($message['type'] === 'success' ? 'Berhasil' : 'Perhatian') . "', text: " . json_encode($message['text']) . ", timer: 2200, showConfirmButton: false });";
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Daftar Agenda Kelas <?= $is_admin_or_kepala ? (!empty($selected_kelas_name) ? '- Kelas ' . htmlspecialchars($selected_kelas_name) : '') : (!empty($wali_kelas_name) ? '- Kelas ' . htmlspecialchars($wali_kelas_name) : '') ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <?php if ($is_admin_or_kepala): ?>
            <div class="card">
                <div class="card-header">
                    <h4>Filter Kelas</h4>
                </div>
                <div class="card-body">
                    <form method="GET" class="form-inline">
                        <?php if (isset($_GET['session_type'])): ?><input type="hidden" name="session_type" value="<?= htmlspecialchars($_GET['session_type']) ?>"><?php endif; ?>
                        <label class="mr-2" for="selectKelasAgenda">Pilih Kelas:</label>
                        <select name="kelas" id="selectKelasAgenda" class="form-control" style="min-width: 220px;" onchange="this.form.submit();">
                            <option value="">-- Pilih Kelas --</option>
                            <?php foreach ($all_classes as $c): ?>
                                <option value="<?= (int)$c['id_kelas'] ?>" <?= $selected_kelas_id === (int)$c['id_kelas'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nama_kelas']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if (true): ?>
            <!-- Filter & Mode Tabs -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <ul class="nav nav-pills" id="agendaModeTab" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="tab-tabel-link" data-toggle="tab" href="#tab-tabel" role="tab"><i class="fas fa-list mr-1"></i> Tampilan Tabel</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="tab-kalender-link" data-toggle="tab" href="#tab-kalender" role="tab"><i class="fas fa-calendar-alt mr-1"></i> Tampilan Kalender</a>
                        </li>
                    </ul>
                    <?php if ($can_crud): ?>
                    <button type="button" class="btn btn-primary" id="btnTambahAgenda">
                        <i class="fas fa-plus mr-1"></i> Tambah Agenda
                    </button>
                    <?php endif; ?>
                </div>

                <div class="card-body">
                    <div class="tab-content" id="agendaTabContent">
                        <!-- Tampilan Tabel -->
                        <div class="tab-pane fade show active" id="tab-tabel" role="tabpanel">
                            <form method="GET" class="row mb-3">
                                <input type="hidden" name="kelas" value="<?= (int)$selected_kelas_id ?>">
                                <div class="col-md-4 mb-2">
                                    <label class="small font-weight-bold">Jenis Agenda</label>
                                    <select name="f_jenis" class="form-control form-control-sm" onchange="this.form.submit()">
                                        <option value="">-- Semua Jenis --</option>
                                        <?php foreach ($jenis_options as $j): ?>
                                            <option value="<?= $j ?>" <?= $f_jenis === $j ? 'selected' : '' ?>><?= $j ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-2">
                                    <label class="small font-weight-bold">Status</label>
                                    <select name="f_status" class="form-control form-control-sm" onchange="this.form.submit()">
                                        <option value="">-- Semua Status --</option>
                                        <?php foreach ($status_options as $st): ?>
                                            <option value="<?= $st ?>" <?= $f_status === $st ? 'selected' : '' ?>><?= $st ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-2 d-flex align-items-end">
                                    <a href="agenda_kelas.php?kelas=<?= (int)$selected_kelas_id ?>" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i> Reset</a>
                                </div>
                            </form>

                            <div class="table-responsive">
                                <table class="table table-striped table-bordered table-sm" id="table-agenda">
                                    <thead>
                                        <tr>
                                            <th width="4%">No</th>
                                            <th>Tanggal Mulai - Selesai</th>
                                            <th>Waktu</th>
                                            <th>Nama Agenda</th>
                                            <th>Jenis</th>
                                            <th>Kelas</th>
                                            <th>Tempat</th>
                                            <th>Penanggung Jawab</th>
                                            <th>Status</th>
                                            <th width="12%">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rows as $i => $r): ?>
                                            <?php
                                            $st_badge = 'secondary';
                                            if ($r['status'] === 'Selesai') $st_badge = 'success';
                                            elseif ($r['status'] === 'Berjalan') $st_badge = 'info';
                                            elseif ($r['status'] === 'Rencana') $st_badge = 'primary';
                                            elseif ($r['status'] === 'Batal') $st_badge = 'danger';

                                            $waktu_str = '-';
                                            if (!empty($r['waktu_mulai'])) {
                                                $waktu_str = substr($r['waktu_mulai'], 0, 5) . (!empty($r['waktu_selesai']) ? ' - ' . substr($r['waktu_selesai'], 0, 5) : '');
                                            }
                                            ?>
                                            <tr>
                                                <td class="text-center"><?= $i + 1 ?></td>
                                                <?php
                                                $tm_ef = $r['tanggal_mulai'] ?? $r['tgl_mulai_ef'] ?? $r['tanggal'];
                                                $ts_ef = $r['tanggal_selesai'] ?? $r['tgl_selesai_ef'] ?? $tm_ef;
                                                $tgl_str = date('d/m/Y', strtotime($tm_ef));
                                                if ($ts_ef !== $tm_ef) $tgl_str .= ' - ' . date('d/m/Y', strtotime($ts_ef));
                                                ?>
                                                <td><?= $tgl_str ?></td>
                                                <td class="text-center"><?= $waktu_str ?></td>
                                                <td><strong><?= htmlspecialchars($r['nama_agenda']) ?></strong></td>
                                                <td><span class="badge badge-light border"><?= htmlspecialchars($r['jenis']) ?></span></td>
                                                <td><?= htmlspecialchars($r['nama_kelas'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($r['tempat'] ?: '-') ?></td>
                                                <td><?= htmlspecialchars($r['penanggung_jawab'] ?: '-') ?></td>
                                                <td class="text-center"><span class="badge badge-<?= $st_badge ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-info btn-sm btn-detail-agenda" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Detail">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if ($can_crud): ?>
                                                    <button type="button" class="btn btn-warning btn-sm btn-edit-agenda" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-danger btn-sm btn-hapus-agenda" data-id="<?= (int)$r['id'] ?>" data-nama="<?= htmlspecialchars($r['nama_agenda'], ENT_QUOTES) ?>" title="Hapus">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Tampilan Kalender -->
                        <div class="tab-pane fade" id="tab-kalender" role="tabpanel">
                            <?php if (!empty($legend_used)): ?>
                            <div class="d-flex flex-wrap mb-2" style="gap:6px;font-size:12px;">
                                <?php foreach ($legend_used as $lg_name => $lg_color): ?>
                                    <span class="badge" style="background:<?= htmlspecialchars($lg_color) ?>;color:#fff;font-weight:700;padding:5px 10px;border-radius:4px;text-shadow:0 1px 2px rgba(0,0,0,.45);"><?= htmlspecialchars($lg_name) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <div class="p-2" id="calendarView"></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- Modal Form Tambah / Edit -->
<div class="modal fade" id="modalAgenda" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formAgenda">
                <input type="hidden" name="action" id="formAgendaAction" value="tambah">
                <input type="hidden" name="id" id="agendaId" value="">
                <input type="hidden" name="id_kelas" value="<?= (int)$selected_kelas_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalAgendaTitle">Tambah Agenda Kelas</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-8 form-group">
                            <label>Nama Agenda <span class="text-danger">*</span></label>
                            <input type="text" name="nama_agenda" id="inp_nama" class="form-control" required placeholder="Contoh: Ujian Tengah Semester Genap">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Jenis Agenda</label>
                            <select name="jenis" id="inp_jenis" class="form-control">
                                <?php foreach ($jenis_options as $j): ?>
                                    <option value="<?= $j ?>"><?= $j ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <input type="hidden" name="tanggal" id="inp_tanggal" value="<?= date('Y-m-d') ?>">
                        <div class="col-md-6 form-group">
                            <label>Tanggal Mulai <span class="text-danger">*</span></label>
                            <input type="date" name="tanggal_mulai" id="inp_tanggal_mulai" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Tanggal Selesai <span class="text-danger">*</span></label>
                            <input type="date" name="tanggal_selesai" id="inp_tanggal_selesai" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Waktu Mulai</label>
                            <input type="time" name="waktu_mulai" id="inp_mulai" class="form-control">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Waktu Selesai</label>
                            <input type="time" name="waktu_selesai" id="inp_selesai" class="form-control">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Tempat / Ruang</label>
                            <input type="text" name="tempat" id="inp_tempat" class="form-control" value="Ruang Kelas">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Penanggung Jawab</label>
                            <input type="text" name="penanggung_jawab" id="inp_pj" class="form-control" value="Wali Kelas">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Status</label>
                            <select name="status" id="inp_status" class="form-control">
                                <?php foreach ($status_options as $st): ?>
                                    <option value="<?= $st ?>"><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 form-group">
                            <label>Keterangan Tambahan</label>
                            <textarea name="keterangan" id="inp_ket" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan Agenda</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Detail -->
<div class="modal fade" id="modalDetailAgenda" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-calendar-check mr-2"></i>Rincian Agenda Kelas</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <table class="table table-bordered table-sm">
                    <tr><th width="35%">Nama Agenda</th><td id="det_nama" class="font-weight-bold text-primary"></td></tr>
                    <tr><th>Tanggal Mulai - Selesai</th><td id="det_tanggal"></td></tr>
                    <tr><th>Waktu</th><td id="det_waktu"></td></tr>
                    <tr><th>Jenis</th><td id="det_jenis"></td></tr>
                    <tr><th>Kelas</th><td id="det_kelas"></td></tr>
                    <tr><th>Tempat</th><td id="det_tempat"></td></tr>
                    <tr><th>Penanggung Jawab</th><td id="det_pj"></td></tr>
                    <tr><th>Status</th><td id="det_status"></td></tr>
                    <tr><th>Keterangan</th><td id="det_ket" style="white-space: pre-wrap;"></td></tr>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<form method="POST" id="formHapus" class="d-none">
    <input type="hidden" name="action" value="hapus">
    <input type="hidden" name="id" id="formHapusId">
</form>

<?php include '../templates/footer.php'; ?>
