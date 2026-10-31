<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/pramuka_latihan.php';

// Kehadiran latihan: admin, tata usaha, guru, wali
if (!isAuthorized(['admin', 'tata_usaha', 'guru', 'wali'])) {
    redirect('../login.php');
}

$school_profile = getSchoolProfile($pdo);

try {
    pramukaLatihanEnsureSchema($pdo);
} catch (Throwable $e) {
    // biarkan halaman tampil; query akan gagal dengan pesan yang jelas
}

$scope = pramukaLatihanScope($pdo);
$tingkat_list = pramukaLatihanTingkatList($pdo, $scope);
$tingkat_ids = array_map(static fn($t) => (int)$t['id_tingkat_barung'], $tingkat_list);

$tanggal = isset($_GET['tanggal']) && $_GET['tanggal'] !== '' ? (string)$_GET['tanggal'] : (isset($_POST['tanggal']) ? (string)$_POST['tanggal'] : date('Y-m-d'));
$is_latihan_day = pramukaLatihanIsScheduleDay($pdo, $tanggal);
$jadwal_label = pramukaLatihanScheduleLabel($pdo);

// Auto-save satu peserta (tanpa tombol simpan)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['single_absensi_latihan']) || isset($_POST['single_absensi_siswa']))) {
    header('Content-Type: application/json');
    $tanggal_js = (string)($_POST['tanggal'] ?? date('Y-m-d'));
    if (!pramukaLatihanIsScheduleDay($pdo, $tanggal_js)) {
        echo json_encode(['success' => false, 'error' => 'Kehadiran latihan hanya dibuka sesuai jadwal Pramuka (' . pramukaLatihanScheduleLabel($pdo) . ').']);
        exit;
    }
    $id_peserta_js = (int)($_POST['id_peserta_didik_barung'] ?? $_POST['id_siswa'] ?? 0);
    $res = pramukaLatihanSaveSingle($pdo, $scope, $id_peserta_js, $tanggal_js, (string)($_POST['status'] ?? ''));
    if (!empty($res['success'])) {
        $username = $_SESSION['username'] ?? 'system';
        try {
            logActivity($pdo, $username, 'Input Kehadiran Latihan Pramuka', 'Peserta ID: ' . $id_peserta_js . ' tanggal ' . $tanggal_js);
        } catch (Throwable $e) {
        }
    }
    echo json_encode($res);
    exit;
}

// Validasi pilihan tingkat terhadap cakupan pembina
$selected_tingkat = isset($_GET['tingkat']) ? (int)$_GET['tingkat'] : 0;
if ($selected_tingkat > 0 && !pramukaLatihanCanAccessTingkat($scope, $selected_tingkat)) {
    $selected_tingkat = 0;
}

$tingkat_info = [];
$peserta = [];

if ($selected_tingkat > 0) {
    foreach ($tingkat_list as $t) {
        if ((int)$t['id_tingkat_barung'] === $selected_tingkat) {
            $tingkat_info = $t;
            break;
        }
    }

    try {
        $stmt = $pdo->prepare("
            SELECT p.*, t.nama_tingkat, t.golongan, s.foto,
                   a.keterangan, a.jam_masuk
            FROM tb_peserta_didik_barung p
            INNER JOIN tb_tingkat_barung t ON t.id_tingkat_barung = p.id_tingkat_barung
            LEFT JOIN tb_siswa s ON s.id_siswa = p.id_siswa
            LEFT JOIN tb_absensi_latihan_pramuka a
                   ON a.id_peserta_didik_barung = p.id_peserta_didik_barung
                  AND a.tanggal = ?
            WHERE p.id_tingkat_barung = ?
              AND " . pramukaLatihanPesertaAktifSql('p') . "
            ORDER BY p.nama_peserta_didik ASC
        ");
        $stmt->execute([$tanggal, $selected_tingkat]);
        $peserta = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $peserta = [];
    }
}

$page_title = 'Kehadiran Latihan Pramuka';

$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$school_name_js = htmlspecialchars($school_profile['nama_madrasah'] ?? '', ENT_QUOTES, 'UTF-8');
$madrasah_head = addslashes(htmlspecialchars($school_profile['kepala_madrasah'] ?? 'Kepala Madrasah', ENT_QUOTES, 'UTF-8'));
$madrasah_head_signature = addslashes(htmlspecialchars($school_profile['ttd_kepala'] ?? '', ENT_QUOTES, 'UTF-8'));
$pembina_name = addslashes(htmlspecialchars((string)($_SESSION['nama_guru'] ?? ($_SESSION['username'] ?? 'Pembina Pramuka')), ENT_QUOTES, 'UTF-8'));
$school_city = addslashes(htmlspecialchars($school_profile['tempat_jadwal'] ?? 'Padang', ENT_QUOTES, 'UTF-8'));
$report_date = formatDateIndonesia(date('Y-m-d'));
$selected_tingkat_name = addslashes(htmlspecialchars((string)($tingkat_info['nama_tingkat'] ?? ''), ENT_QUOTES, 'UTF-8'));

$js_page = [];
$js_page[] = "
var madrasahHeadName = '$madrasah_head';
var madrasahHeadSignature = '$madrasah_head_signature';
var pembinaName = '$pembina_name';
var schoolCity = '$school_city';
var reportDate = '$report_date';
var schoolName = '$school_name_js';
var selectedTingkatName = '$selected_tingkat_name';

function updateBadgeLatihan(id, selectedValue) {
    var badge = $('#badge_' + id);
    badge.text(selectedValue ? selectedValue : 'Belum Mengisi Kehadiran');
    badge.removeClass('badge-success badge-info badge-warning badge-danger badge-secondary');
    switch (selectedValue) {
        case 'Hadir': badge.addClass('badge-success'); break;
        case 'Sakit': badge.addClass('badge-warning'); break;
        case 'Izin': badge.addClass('badge-info'); break;
        case 'Alpa': badge.addClass('badge-danger'); break;
        default: badge.addClass('badge-secondary');
    }
}

function showLatihanToast(type, message) {
    if (typeof toastr !== 'undefined') {
        if (type === 'success') toastr.success(message, 'Tersimpan');
        else toastr.error(message, 'Gagal');
        return;
    }
    if (typeof Swal !== 'undefined') {
        Swal.fire({ icon: type, title: type === 'success' ? 'Tersimpan' : 'Gagal', text: message, timer: 1400, showConfirmButton: false });
    }
}

function simadAutoSaveLatihanPramuka(idPeserta, status) {
    var tanggalEl = document.getElementById('tanggalInput');
    var tanggal = tanggalEl && tanggalEl.value ? tanggalEl.value : new Date().toISOString().slice(0, 10);
    var body = new URLSearchParams();
    body.append('single_absensi_latihan', '1');
    body.append('id_peserta_didik_barung', idPeserta);
    body.append('tanggal', tanggal);
    body.append('status', status);
    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString()
    }).then(function (r) { return r.json(); }).then(function (resp) {
        if (resp && resp.success) showLatihanToast('success', 'Kehadiran diperbarui');
        else showLatihanToast('error', (resp && resp.error) ? resp.error : 'Gagal menyimpan kehadiran');
    }).catch(function () {
        showLatihanToast('error', 'Gagal menyimpan kehadiran');
    });
}

function buildLatihanExportRows() {
    var html = '';
    $('.att-card').each(function (i) {
        var name = $(this).find('.att-name').text().trim();
        var status = $(this).find('.latihan-status-input').val() || 'Belum Mengisi Kehadiran';
        var esc = $('<div>').text(name).html();
        html += '<tr><td>' + (i + 1) + '</td><td>' + esc + '</td><td>' + status + '</td></tr>';
    });
    return html;
}

function exportLatihanToExcel() {
    var container = document.createElement('div');
    container.innerHTML = '<h2>Kehadiran Latihan Pramuka</h2><h3>' + schoolName + '</h3><h4>Tingkat ' + selectedTingkatName + ' - Tanggal ' + document.getElementById('tanggalInput').value + '</h4>';
    var table = document.createElement('table');
    table.innerHTML = '<thead><tr><th>No</th><th>Nama Peserta</th><th>Status Kehadiran</th></tr></thead><tbody>' + buildLatihanExportRows() + '</tbody></table>';
    container.appendChild(table);
    var a = document.createElement('a');
    a.href = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(container.innerHTML);
    a.download = 'kehadiran_latihan_' + selectedTingkatName.replace(/[^a-zA-Z0-9]/g, '_') + '_' + new Date().toISOString().slice(0, 10) + '.xls';
    a.click();
}

function exportLatihanToPDF() {
    var w = window.open('', '', 'height=860,width=1300');
    w.document.write('<html><head><title>Kehadiran Latihan Pramuka</title>');
    w.document.write('<style>body{font-family:Arial,sans-serif}table{border-collapse:collapse;width:100%;font-size:11px}th,td{border:1px solid #000;padding:4px;text-align:center}td:nth-child(2){text-align:left}h2,h3,h4{text-align:center;margin:5px 0}</style>');
    w.document.write('</head><body>');
    w.document.write('<h2>Kehadiran Latihan Pramuka</h2><h3>' + schoolName + '</h3>');
    w.document.write('<h4>Tingkat ' + selectedTingkatName + ' - Tanggal ' + document.getElementById('tanggalInput').value + '</h4>');
    w.document.write('<table><thead><tr><th>No</th><th>Nama Peserta</th><th>Status Kehadiran</th></tr></thead><tbody>' + buildLatihanExportRows() + '</tbody></table>');
    w.document.write('<div style=\"margin-top:24px;display:flex;justify-content:space-between;\"><div style=\"text-align:center;width:45%\"><p>' + schoolCity + ', ' + reportDate + '<br>Pembina Pramuka,</p><br><br><br><p><strong>' + pembinaName + '</strong></p></div><div style=\"text-align:center;width:45%\"><p>' + schoolCity + ', ' + reportDate + '<br>Kepala Madrasah,</p><br><br><br><p><strong>' + madrasahHeadName + '</strong></p></div></div>');
    w.document.write('</body></html>');
    w.document.close();
    w.print();
}

$(document).ready(function () {
    $('#tingkatSelect').on('change', function () {
        if ($(this).val()) { $('#filterForm').submit(); }
    });

    // Datepicker: pilih tanggal langsung muat kehadiran tanggal itu.
    var lastLatihanSubmit = 0;
    function submitLatihanTanggal() {
        var el = document.getElementById('tanggalInput');
        var tingkat = document.getElementById('tingkatSelect');
        if (!el || !tingkat || !el.value || !tingkat.value) return;
        if (!/^\\d{4}-\\d{2}-\\d{2}$/.test(el.value)) return;
        if (el.dataset.lastAutoSubmit === el.value) return;
        var now = Date.now();
        if (now - lastLatihanSubmit < 300) return;
        lastLatihanSubmit = now;
        el.dataset.lastAutoSubmit = el.value;
        var form = document.getElementById('filterForm');
        if (!form) return;
        if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
    }
    $('#tanggalInput').on('change blur', submitLatihanTanggal);

    $(document).on('click', '.btn-kehadiran-latihan', function () {
        var pesertaId = $(this).data('id');
        var status = $(this).data('status');
        var input = $('#status_' + pesertaId);
        if (input.val() === status) return false;

        var group = $(this).closest('.attendance-btn-group');
        group.find('.btn-kehadiran-latihan').removeClass('active').prop('disabled', false);
        $(this).addClass('active').prop('disabled', true);

        input.val(status);
        updateBadgeLatihan(pesertaId, status);

        var card = $(this).closest('.att-card');
        var avatar = card.find('.att-avatar');
        avatar.removeClass(function (i, c) { return (c.match(/att-avatar--\\S+/g) || []).join(' '); });
        avatar.addClass('att-avatar--' + status.toLowerCase());

        simadAutoSaveLatihanPramuka(pesertaId, status);
    });
});
";

include '../templates/header.php';
?>

<?php if (!empty($tanggal) && !$is_latihan_day): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        Swal.fire({
            icon: 'warning',
            title: 'Bukan Hari Latihan',
            text: 'Kehadiran latihan Pramuka hanya dibuka pada jadwal: <?php echo htmlspecialchars($jadwal_label, ENT_QUOTES, 'UTF-8'); ?>. Pilih tanggal sesuai jadwal untuk mengisi kehadiran.',
            confirmButtonText: 'OK'
        });
    });
    </script>
<?php endif; ?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Kehadiran Latihan Pramuka</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Form Kehadiran Latihan</h4>
                        </div>
                        <div class="card-body">
                            <?php if (empty($scope['all']) && empty($tingkat_ids)): ?>
                                <div class="alert alert-warning">
                                    Anda belum ditetapkan sebagai pembina pada tingkatan Pramuka mana pun. Hubungi admin atau tata usaha untuk mengatur tingkatan binaan.
                                </div>
                            <?php else: ?>
                                <form method="GET" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="filterForm">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>Tingkatan</label>
                                                <select class="form-control" name="tingkat" id="tingkatSelect" required>
                                                    <option value="">Pilih Tingkatan</option>
                                                    <?php foreach ($tingkat_list as $t): ?>
                                                        <option value="<?php echo (int)$t['id_tingkat_barung']; ?>" <?php echo ($selected_tingkat === (int)$t['id_tingkat_barung']) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars((string)$t['nama_tingkat']); ?><?php echo !empty($t['golongan']) ? ' (' . htmlspecialchars((string)$t['golongan']) . ')' : ''; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>Tanggal</label>
                                                <input type="date" class="form-control" name="tanggal" id="tanggalInput" value="<?php echo htmlspecialchars($tanggal); ?>" required>
                                                <small class="form-text text-muted">Pilih tanggal untuk memuat kehadiran latihan sebelumnya secara otomatis.</small>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>&nbsp;</label><br>
                                                <button type="button" class="btn btn-success" onclick="exportLatihanToExcel()">Ekspor Excel</button>
                                                <button type="button" class="btn btn-warning" onclick="exportLatihanToPDF()">Ekspor PDF</button>
                                            </div>
                                        </div>
                                    </div>
                                </form>

                                <?php if (!$is_latihan_day): ?>
                                    <div class="alert alert-warning">
                                        <div class="alert-title">Kehadiran Latihan Ditutup</div>
                                        Latihan Pramuka hanya dilaksanakan sesuai jadwal ekstrakurikuler: <?php echo htmlspecialchars($jadwal_label); ?>. Tanggal yang dipilih bukan hari latihan, jadi kehadiran tidak dapat diisi.
                                    </div>
                                <?php elseif (!empty($peserta)): ?>
                                    <form method="POST" action="">
                                        <input type="hidden" name="tanggal" value="<?php echo htmlspecialchars($tanggal); ?>">
                                        <div class="att-grid">
                                            <?php foreach ($peserta as $index => $row): ?>
                                                <?php
                                                $id_peserta = (int)$row['id_peserta_didik_barung'];
                                                $status = $row['keterangan'] ?? null;
                                                $status_now = $status ?: '';
                                                $stat_slug = $status ? strtolower($status) : 'belum-absen';
                                                switch ($status) {
                                                    case 'Hadir': $badgeClass = 'badge-success'; break;
                                                    case 'Sakit': $badgeClass = 'badge-warning'; break;
                                                    case 'Izin': $badgeClass = 'badge-info'; break;
                                                    case 'Alpa': $badgeClass = 'badge-danger'; break;
                                                    default: $badgeClass = 'badge-secondary';
                                                }
                                                $nm = trim((string)($row['nama_peserta_didik'] ?? ''));
                                                $parts = preg_split('/\s+/', $nm);
                                                $ini = '';
                                                foreach (array_slice($parts, 0, 2) as $w) { if ($w !== '') $ini .= strtoupper(substr($w, 0, 1)); }
                                                ?>
                                                <div class="att-card">
                                                    <div class="att-card-head">
                                                        <div class="att-avatar att-avatar--<?= htmlspecialchars($stat_slug) ?>"><?= getPersonAvatarHtml($row['foto'] ?? '', htmlspecialchars($ini !== '' ? $ini : '?')) ?></div>
                                                        <div class="att-card-id">
                                                            <div class="att-name"><?= htmlspecialchars($nm) ?></div>
                                                            <div class="att-sub">
                                                                <span class="att-num">No <?= $index + 1 ?><?php echo !empty($row['nta']) ? ' · NTA ' . htmlspecialchars((string)$row['nta']) : ''; ?></span>
                                                                <span class="badge <?= $badgeClass ?>" id="badge_<?= $id_peserta ?>"><?= htmlspecialchars($status ?: 'Belum Mengisi Kehadiran') ?></span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="att-actions attendance-btn-group">
                                                        <button type="button" class="att-opt is-hadir btn-kehadiran-latihan <?= $status_now === 'Hadir' ? 'active' : '' ?>" data-id="<?= $id_peserta ?>" data-status="Hadir" <?= $status_now === 'Hadir' ? 'disabled' : '' ?>>
                                                            <i class="fas fa-check"></i><span>Hadir</span>
                                                        </button>
                                                        <button type="button" class="att-opt is-sakit btn-kehadiran-latihan <?= $status_now === 'Sakit' ? 'active' : '' ?>" data-id="<?= $id_peserta ?>" data-status="Sakit" <?= $status_now === 'Sakit' ? 'disabled' : '' ?>>
                                                            <i class="fas fa-procedures"></i><span>Sakit</span>
                                                        </button>
                                                        <button type="button" class="att-opt is-izin btn-kehadiran-latihan <?= $status_now === 'Izin' ? 'active' : '' ?>" data-id="<?= $id_peserta ?>" data-status="Izin" <?= $status_now === 'Izin' ? 'disabled' : '' ?>>
                                                            <i class="fas fa-envelope-open-text"></i><span>Izin</span>
                                                        </button>
                                                        <button type="button" class="att-opt is-alpa btn-kehadiran-latihan <?= $status_now === 'Alpa' ? 'active' : '' ?>" data-id="<?= $id_peserta ?>" data-status="Alpa" <?= $status_now === 'Alpa' ? 'disabled' : '' ?>>
                                                            <i class="fas fa-user-times"></i><span>Alpa</span>
                                                        </button>
                                                    </div>
                                                    <input type="hidden" class="latihan-status-input" name="keterangan_<?= $id_peserta ?>" id="status_<?= $id_peserta ?>" value="<?= htmlspecialchars($status_now, ENT_QUOTES) ?>">
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <p class="text-center text-muted mb-0"><i class="fas fa-info-circle"></i> Perubahan tersimpan otomatis saat memilih status.</p>
                                    </form>
                                <?php elseif ($selected_tingkat > 0): ?>
                                    <div class="alert alert-info">
                                        <p class="text-center mb-0">Belum ada peserta aktif pada tingkatan ini.</p>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
