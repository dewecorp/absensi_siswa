<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/ekstra_kehadiran.php';

if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha', 'guru', 'wali'])) {
    redirect('../login.php');
}

$can_edit = isAuthorized(['admin', 'tata_usaha', 'guru', 'wali']);

try {
    ekstraKehadiranEnsureSchema($pdo);
} catch (Throwable $e) {
}

$ekskul_list = ekstraKehadiranList($pdo, true);
$ekskul_id = isset($_GET['ekskul']) ? (int)$_GET['ekskul'] : (isset($_POST['ekskul']) ? (int)$_POST['ekskul'] : 0);
$valid_ids = array_map(static fn($e) => (int)$e['id_ekstrakurikuler'], $ekskul_list);
if ($ekskul_id <= 0 || !in_array($ekskul_id, $valid_ids, true)) {
    $ekskul_id = $valid_ids[0] ?? 0;
}
$ekskul = ekstraKehadiranResolve($pdo, $ekskul_id);
if (!$ekskul) {
    die('Belum ada data ekstrakurikuler. Tambahkan dulu di menu Data Ekstrakurikuler.');
}

$tanggal = isset($_GET['tanggal']) && $_GET['tanggal'] !== ''
    ? (string)$_GET['tanggal']
    : (isset($_POST['tanggal']) ? (string)$_POST['tanggal'] : date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    $tanggal = date('Y-m-d');
}

$is_schedule_day = ekstraKehadiranIsScheduleDay($ekskul, $tanggal);
$jadwal_label = ekstraKehadiranScheduleLabel($ekskul);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['single_kehadiran_ekstra'])) {
    header('Content-Type: application/json');
    if (!$can_edit) {
        echo json_encode(['success' => false, 'error' => 'Anda tidak memiliki izin mengubah kehadiran.']);
        exit;
    }
    $id_siswa_js = (int)($_POST['id_siswa'] ?? 0);
    $tanggal_js = (string)($_POST['tanggal'] ?? $tanggal);
    $res = ekstraKehadiranSaveSingle($pdo, $ekskul_id, $id_siswa_js, $tanggal_js, (string)($_POST['status'] ?? ''));
    if (!empty($res['success'])) {
        try {
            logActivity($pdo, $_SESSION['username'] ?? 'system', 'Input Kehadiran ' . $ekskul['nama_ekstrakurikuler'], 'Siswa ID: ' . $id_siswa_js . ' tanggal ' . $tanggal_js);
        } catch (Throwable $e) {
        }
    }
    echo json_encode($res);
    exit;
}

$anggota = ekstraKehadiranAnggota($pdo, $ekskul_id, $tanggal);
$page_title = 'Kehadiran ' . $ekskul['nama_ekstrakurikuler'];

$css_libs = [];
$js_libs = [];

$ekskul_name_js = addslashes(htmlspecialchars($ekskul['nama_ekstrakurikuler'], ENT_QUOTES, 'UTF-8'));
$js_page = [];
$js_page[] = "
function updateBadgeKehadiranEkstra(id, selectedValue) {
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

function showKehadiranEkstraToast(type, message) {
    if (typeof toastr !== 'undefined') {
        if (type === 'success') toastr.success(message, 'Tersimpan');
        else toastr.error(message, 'Gagal');
        return;
    }
    if (typeof Swal !== 'undefined') {
        Swal.fire({ icon: type, title: type === 'success' ? 'Tersimpan' : 'Gagal', text: message, timer: 1400, showConfirmButton: false });
    }
}

function simadAutoSaveKehadiranEkstra(idSiswa, status) {
    var tanggalEl = document.getElementById('tanggalInput');
    var tanggal = tanggalEl && tanggalEl.value ? tanggalEl.value : new Date().toISOString().slice(0, 10);
    var body = new URLSearchParams();
    body.append('single_kehadiran_ekstra', '1');
    body.append('ekskul', '$ekskul_id');
    body.append('id_siswa', idSiswa);
    body.append('tanggal', tanggal);
    body.append('status', status);
    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString()
    }).then(function (r) { return r.json(); }).then(function (resp) {
        if (resp && resp.success) showKehadiranEkstraToast('success', 'Kehadiran diperbarui');
        else showKehadiranEkstraToast('error', (resp && resp.error) ? resp.error : 'Gagal menyimpan kehadiran');
    }).catch(function () {
        showKehadiranEkstraToast('error', 'Gagal menyimpan kehadiran');
    });
}

$(document).ready(function () {
    $('#ekskulSelect').on('change', function () {
        if ($(this).val()) { $('#filterForm').submit(); }
    });

    var lastSubmit = 0;
    function submitTanggal() {
        var el = document.getElementById('tanggalInput');
        var ekskul = document.getElementById('ekskulSelect');
        if (!el || !ekskul || !el.value || !ekskul.value) return;
        if (!/^\\d{4}-\\d{2}-\\d{2}$/.test(el.value)) return;
        if (el.dataset.lastAutoSubmit === el.value) return;
        var now = Date.now();
        if (now - lastSubmit < 300) return;
        lastSubmit = now;
        el.dataset.lastAutoSubmit = el.value;
        var form = document.getElementById('filterForm');
        if (!form) return;
        if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
    }
    $('#tanggalInput').on('change blur', submitTanggal);

    $(document).on('click', '.btn-kehadiran-ekstra', function () {
        var idSiswa = $(this).data('id');
        var status = $(this).data('status');
        var card = $(this).closest('.att-card');
        card.find('.btn-kehadiran-ekstra').removeClass('active').prop('disabled', false);
        $(this).addClass('active').prop('disabled', true);
        card.find('.kehadiran-status-input').val(status);
        updateBadgeKehadiranEkstra(idSiswa, status);
        simadAutoSaveKehadiranEkstra(idSiswa, status);
    });
});
";

$_GET['ekskul'] = $ekskul_id;

include '../templates/header.php';
?>

<?php if (!$is_schedule_day): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof Swal === 'undefined') return;
        Swal.fire({
            icon: 'warning',
            title: 'Bukan Hari Ekstrakurikuler',
            text: 'Kehadiran <?php echo htmlspecialchars($ekskul['nama_ekstrakurikuler'], ENT_QUOTES, 'UTF-8'); ?> hanya dibuka pada jadwal: <?php echo htmlspecialchars($jadwal_label, ENT_QUOTES, 'UTF-8'); ?>.',
            confirmButtonText: 'OK'
        });
    });
    </script>
<?php endif; ?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Kehadiran <?php echo htmlspecialchars($ekskul['nama_ekstrakurikuler']); ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Form Kehadiran <?php echo htmlspecialchars($ekskul['nama_ekstrakurikuler']); ?></h4>
                            <div class="card-header-action">
                                <a class="btn btn-primary" href="rekap_kehadiran_ekstra.php?ekskul=<?php echo (int)$ekskul_id; ?>">
                                    <i class="fas fa-chart-bar"></i> Rekap Kehadiran
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="filterForm">
                                <div class="row">
                                    <div class="col-md-5">
                                        <div class="form-group">
                                            <label>Ekstrakurikuler</label>
                                            <select class="form-control" name="ekskul" id="ekskulSelect" required>
                                                <?php foreach ($ekskul_list as $e): ?>
                                                    <option value="<?php echo (int)$e['id_ekstrakurikuler']; ?>" <?php echo $ekskul_id === (int)$e['id_ekstrakurikuler'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($e['nama_ekstrakurikuler']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label>Tanggal</label>
                                            <input type="date" class="form-control" name="tanggal" id="tanggalInput" value="<?php echo htmlspecialchars($tanggal); ?>" required>
                                            <small class="form-text text-muted">Jadwal: <?php echo htmlspecialchars($jadwal_label); ?></small>
                                        </div>
                                    </div>
                                </div>
                            </form>

                            <?php if (!$is_schedule_day): ?>
                                <div class="alert alert-warning">
                                    <div class="alert-title">Kehadiran Ditutup</div>
                                    <?php echo htmlspecialchars($ekskul['nama_ekstrakurikuler']); ?> hanya dilaksanakan sesuai jadwal: <?php echo htmlspecialchars($jadwal_label); ?>. Tanggal yang dipilih bukan hari ekstrakurikuler.
                                </div>
                            <?php elseif (!empty($anggota)): ?>
                                <div class="att-grid">
                                    <?php foreach ($anggota as $index => $row): ?>
                                        <?php
                                        $id_siswa = (int)$row['id_siswa'];
                                        $status = $row['keterangan'] ?? null;
                                        $status_now = $status ?: '';
                                        $stat_slug = $status ? strtolower($status) : 'belum';
                                        switch ($status) {
                                            case 'Hadir': $badgeClass = 'badge-success'; break;
                                            case 'Sakit': $badgeClass = 'badge-warning'; break;
                                            case 'Izin': $badgeClass = 'badge-info'; break;
                                            case 'Alpa': $badgeClass = 'badge-danger'; break;
                                            default: $badgeClass = 'badge-secondary';
                                        }
                                        $nm = trim((string)($row['nama_siswa'] ?? ''));
                                        $parts = preg_split('/\s+/', $nm);
                                        $ini = '';
                                        foreach (array_slice($parts, 0, 2) as $w) {
                                            if ($w !== '') $ini .= strtoupper(substr($w, 0, 1));
                                        }
                                        ?>
                                        <div class="att-card">
                                            <div class="att-card-head">
                                                <div class="att-avatar att-avatar--<?= htmlspecialchars($stat_slug) ?>"><?= getPersonAvatarHtml($row['foto'] ?? '', htmlspecialchars($ini !== '' ? $ini : '?')) ?></div>
                                                <div class="att-card-id">
                                                    <div class="att-name"><?= htmlspecialchars($nm) ?></div>
                                                    <div class="att-sub">
                                                        <span class="att-num">No <?= $index + 1 ?><?php echo !empty($row['nama_kelas']) ? ' · ' . htmlspecialchars((string)$row['nama_kelas']) : ''; ?></span>
                                                        <span class="badge <?= $badgeClass ?>" id="badge_<?= $id_siswa ?>"><?= htmlspecialchars($status ?: 'Belum Mengisi Kehadiran') ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="att-actions attendance-btn-group">
                                                <button type="button" class="att-opt is-hadir btn-kehadiran-ekstra <?= $status_now === 'Hadir' ? 'active' : '' ?>" data-id="<?= $id_siswa ?>" data-status="Hadir" <?= (!$can_edit || $status_now === 'Hadir') ? 'disabled' : '' ?>>
                                                    <i class="fas fa-check"></i><span>Hadir</span>
                                                </button>
                                                <button type="button" class="att-opt is-sakit btn-kehadiran-ekstra <?= $status_now === 'Sakit' ? 'active' : '' ?>" data-id="<?= $id_siswa ?>" data-status="Sakit" <?= (!$can_edit || $status_now === 'Sakit') ? 'disabled' : '' ?>>
                                                    <i class="fas fa-procedures"></i><span>Sakit</span>
                                                </button>
                                                <button type="button" class="att-opt is-izin btn-kehadiran-ekstra <?= $status_now === 'Izin' ? 'active' : '' ?>" data-id="<?= $id_siswa ?>" data-status="Izin" <?= (!$can_edit || $status_now === 'Izin') ? 'disabled' : '' ?>>
                                                    <i class="fas fa-envelope-open-text"></i><span>Izin</span>
                                                </button>
                                                <button type="button" class="att-opt is-alpa btn-kehadiran-ekstra <?= $status_now === 'Alpa' ? 'active' : '' ?>" data-id="<?= $id_siswa ?>" data-status="Alpa" <?= (!$can_edit || $status_now === 'Alpa') ? 'disabled' : '' ?>>
                                                    <i class="fas fa-user-times"></i><span>Alpa</span>
                                                </button>
                                            </div>
                                            <input type="hidden" class="kehadiran-status-input" value="<?= htmlspecialchars($status_now, ENT_QUOTES) ?>">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($can_edit): ?>
                                    <p class="text-center text-muted mb-0 mt-3"><i class="fas fa-info-circle"></i> Perubahan tersimpan otomatis saat memilih status.</p>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="alert alert-info">
                                    <p class="text-center mb-0">Belum ada anggota aktif pada ekstrakurikuler ini.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
