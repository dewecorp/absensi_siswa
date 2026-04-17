<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

$page_title = 'Kenaikan Tingkat';

$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.jsdelivr.net/npm/sweetalert2@11',
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

// --- Ensure schema (best-effort) ---
$schema_error = null;
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'tb_tingkat_barung'");
    $exists = (bool)$stmt->fetch(PDO::FETCH_NUM);
    if (!$exists) {
        $pdo->exec("
            CREATE TABLE tb_tingkat_barung (
                id_tingkat_barung INT AUTO_INCREMENT PRIMARY KEY,
                nama_tingkat VARCHAR(100) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    $stmt = $pdo->query("SHOW TABLES LIKE 'tb_peserta_didik_barung'");
    $exists = (bool)$stmt->fetch(PDO::FETCH_NUM);
    if (!$exists) {
        $pdo->exec("
            CREATE TABLE tb_peserta_didik_barung (
                id_peserta_didik_barung INT AUTO_INCREMENT PRIMARY KEY,
                id_tingkat_barung INT NOT NULL,
                nama_peserta_didik VARCHAR(120) NOT NULL,
                nta VARCHAR(50) NOT NULL,
                tempat_lahir VARCHAR(120) NULL,
                tanggal_lahir DATE NULL,
                INDEX idx_tingkat (id_tingkat_barung)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
} catch (Exception $e) {
    $schema_error = $e->getMessage();
}

// Same ordering rule used elsewhere
function tingkatSortKey(string $name): int {
    $n = strtolower(trim($name));
    $n2 = strtolower(str_replace(' ', '', $n));
    if ($n === 'pra mula' || $n2 === 'pramula' || $n2 === 'pra-mula') return 1;
    if ($n2 === 'mula') return 2;
    if ($n2 === 'bantu') return 3;
    if ($n2 === 'tata') return 4;
    return 99;
}

// Fetch tingkat list ordered
$tingkat_list = [];
try {
    $tingkat_list = $pdo->query("
        SELECT id_tingkat_barung, nama_tingkat
        FROM tb_tingkat_barung
        ORDER BY
            CASE
                WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('pramula', 'pra-mula') OR LOWER(nama_tingkat) = 'pra mula' THEN 1
                WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('mula') THEN 2
                WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('bantu') THEN 3
                WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('tata') THEN 4
                ELSE 99
            END,
            nama_tingkat ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // ignore, shown below via schema_error or message
}

// Helper to find next/previous tingkat id based on ordered list
function findAdjacentTingkatId(array $list, int $sourceId, int $dir): ?int {
    // $dir: +1 next, -1 prev
    $idx = null;
    foreach ($list as $i => $t) {
        if ((int)($t['id_tingkat_barung'] ?? 0) === $sourceId) {
            $idx = $i;
            break;
        }
    }
    if ($idx === null) return null;
    $newIdx = $idx + $dir;
    if (!isset($list[$newIdx])) return null;
    return (int)($list[$newIdx]['id_tingkat_barung'] ?? 0) ?: null;
}

$mode = $_GET['mode'] ?? 'promote'; // promote | demote
$source_id = isset($_GET['source_tingkat']) ? (int)$_GET['source_tingkat'] : null;
$target_id = isset($_GET['target_tingkat']) ? (int)$_GET['target_tingkat'] : null;

// Automatic target based on source + mode
if ($source_id) {
    $target_id = findAdjacentTingkatId($tingkat_list, $source_id, $mode === 'demote' ? -1 : 1);
}

// Handle Promotion/Demotion (move selected peserta didik)
$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['promote_members']) || isset($_POST['demote_members']))) {
    $source_tingkat_id = (int)($_POST['source_tingkat_id'] ?? 0);
    $target_tingkat_id = (int)($_POST['target_tingkat_id'] ?? 0);
    $selected = $_POST['members'] ?? [];
    $is_demotion = isset($_POST['demote_members']);

    if ($source_tingkat_id > 0 && $target_tingkat_id > 0 && !empty($selected)) {
        try {
            $pdo->beginTransaction();
            $placeholders = str_repeat('?,', count($selected) - 1) . '?';
            $sql = "
                UPDATE tb_peserta_didik_barung
                SET id_tingkat_barung = ?
                WHERE id_peserta_didik_barung IN ($placeholders) AND id_tingkat_barung = ?
            ";
            $params = array_merge([$target_tingkat_id], $selected, [$source_tingkat_id]);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $count = count($selected);
            $username = $_SESSION['username'] ?? 'system';
            $log_action = $is_demotion ? 'Batal Kenaikan Tingkat' : 'Kenaikan Tingkat';
            logActivity($pdo, $username, $log_action, "Memindahkan {$count} peserta (source {$source_tingkat_id} -> target {$target_tingkat_id})");

            $pdo->commit();
            $message = ['type' => 'success', 'text' => ($is_demotion ? 'Berhasil membatalkan kenaikan ' : 'Berhasil menaikkan ') . $count . ' peserta didik.'];

            // Keep UI consistent after action
            $mode = $is_demotion ? 'demote' : 'promote';
            $source_id = $source_tingkat_id;
            $target_id = $target_tingkat_id;
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = ['type' => 'danger', 'text' => 'Gagal memproses data: ' . $e->getMessage()];
        }
    } else {
        $message = ['type' => 'warning', 'text' => 'Pilih tingkat asal, tingkat tujuan, dan minimal 1 peserta didik.'];
    }
}

// Fetch members by tingkat
$source_members = [];
if ($source_id) {
    $stmt = $pdo->prepare("
        SELECT id_peserta_didik_barung, nama_peserta_didik, nta
        FROM tb_peserta_didik_barung
        WHERE id_tingkat_barung = ?
        ORDER BY nama_peserta_didik ASC
    ");
    $stmt->execute([$source_id]);
    $source_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$target_members = [];
$target_name_display = '';
if ($target_id) {
    $stmt = $pdo->prepare("
        SELECT id_peserta_didik_barung, nama_peserta_didik, nta
        FROM tb_peserta_didik_barung
        WHERE id_tingkat_barung = ?
        ORDER BY nama_peserta_didik ASC
    ");
    $stmt->execute([$target_id]);
    $target_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Resolve display names
function tingkatNameById(array $list, ?int $id): string {
    if (!$id) return '';
    foreach ($list as $t) {
        if ((int)($t['id_tingkat_barung'] ?? 0) === (int)$id) {
            return (string)($t['nama_tingkat'] ?? '');
        }
    }
    return '';
}
$source_name_display = tingkatNameById($tingkat_list, $source_id);
$target_name_display = tingkatNameById($tingkat_list, $target_id);

require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<style>
    .promotion-header { background-color: #fff; color: #34395e; padding: 15px; border-radius: 5px; box-shadow: 0 4px 8px rgba(0,0,0,0.03); border: 1px solid #e3e6f0; }
    .promotion-header h5 { margin: 0; font-size: 1.1rem; color: #6777ef; }
    .promotion-header p { margin: 5px 0 0; font-size: 0.85rem; color: #858796; }
    .card-promotion { border: none; box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); margin-bottom: 20px; }
    .card-promotion .card-header { background-color: #6777ef; color: white; font-weight: bold; padding: 10px 15px; }
    .info-box { background-color: #e3f2fd; border-left: 5px solid #6777ef; padding: 15px; margin-bottom: 15px; border-radius: 4px; font-size: 0.9rem; }
    .table-sm td, .table-sm th { font-size: 0.85rem; }
    .action-btn-container { display: none; margin-top: 15px; }
    .mode-switcher .btn { min-width: 150px; }
</style>

<div class="main-content">
    <section class="section">
        <div class="promotion-header mb-4">
            <h5><i class="fas fa-arrow-up"></i> Kenaikan Tingkat</h5>
            <p>Menu ini digunakan untuk menaikkan peserta didik Barung dari tingkat sebelumnya (Pra Mula → Mula → Bantu → Tata).</p>
        </div>

        <?php if ($schema_error): ?>
            <div class="alert alert-danger">
                <strong>Terjadi masalah pada database.</strong><br>
                <?= htmlspecialchars($schema_error) ?>
            </div>
        <?php endif; ?>

        <div class="mode-switcher mb-3">
            <a href="?mode=promote" class="btn <?= $mode === 'promote' ? 'btn-primary' : 'btn-outline-primary' ?>">
                <i class="fas fa-arrow-up"></i> Naik Tingkat
            </a>
            <a href="?mode=demote" class="btn <?= $mode === 'demote' ? 'btn-danger' : 'btn-outline-danger' ?>">
                <i class="fas fa-arrow-down"></i> Batal Naik
            </a>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="card card-promotion">
                    <div class="card-header">Tingkat Asal</div>
                    <div class="card-body">
                        <form method="GET" id="sourceForm">
                            <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
                            <?php if ($target_id): ?><input type="hidden" name="target_tingkat" value="<?= (int)$target_id ?>"><?php endif; ?>
                            <div class="form-group">
                                <label>Tingkat</label>
                                <select name="source_tingkat" class="form-control" onchange="this.form.submit()">
                                    <option value="">-- Pilih Tingkat --</option>
                                    <?php foreach ($tingkat_list as $t): ?>
                                        <option value="<?= (int)($t['id_tingkat_barung'] ?? 0) ?>" <?= (int)$source_id === (int)($t['id_tingkat_barung'] ?? 0) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($t['nama_tingkat'] ?? '') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">
                                    Tingkat tujuan akan dipilih otomatis (<?= $mode === 'demote' ? 'tingkat sebelumnya' : 'tingkat berikutnya' ?>).
                                </small>
                            </div>
                        </form>

                        <?php if ($source_id): ?>
                            <?php if (!$target_id): ?>
                                <div class="alert alert-warning">
                                    Tingkat tujuan tidak ditemukan. Pastikan urutan tingkat sudah lengkap (Pra Mula, Mula, Bantu, Tata).
                                </div>
                            <?php endif; ?>

                            <form method="POST">
                                <input type="hidden" name="source_tingkat_id" value="<?= (int)$source_id ?>">
                                <input type="hidden" name="target_tingkat_id" value="<?= (int)$target_id ?>">

                                <div class="table-responsive">
                                    <table class="table table-sm table-striped table-bordered" id="table-source">
                                        <thead>
                                            <tr>
                                                <th width="30px"><input type="checkbox" id="checkAllSource"></th>
                                                <th width="40px">No</th>
                                                <th width="120px">NTA</th>
                                                <th>Nama</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $no=1; foreach ($source_members as $m): ?>
                                                <tr>
                                                    <td><input type="checkbox" name="members[]" value="<?= (int)($m['id_peserta_didik_barung'] ?? 0) ?>" class="check-source"></td>
                                                    <td><?= $no++ ?></td>
                                                    <td><?= htmlspecialchars($m['nta'] ?? '') ?></td>
                                                    <td class="member-name" style="cursor: pointer; color: #6777ef;"><?= htmlspecialchars($m['nama_peserta_didik'] ?? '') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div id="promote-btn-container" class="action-btn-container">
                                    <?php if ($source_id && $target_id && count($source_members) > 0): ?>
                                        <?php if ($mode === 'demote'): ?>
                                            <button type="submit" name="demote_members" class="btn btn-danger btn-block">
                                                <i class="fas fa-arrow-down"></i> Proses Batal Naik
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" name="promote_members" class="btn btn-primary btn-block">
                                                <i class="fas fa-arrow-up"></i> Proses Naik Tingkat
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card card-promotion">
                    <div class="card-header">Tingkat Tujuan</div>
                    <div class="card-body">
                        <div class="form-group">
                            <label>Tingkat</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($target_name_display !== '' ? $target_name_display : '-- Tingkat Tujuan Tidak Ditemukan --') ?>" readonly disabled>
                            <small class="text-muted">Tingkat tujuan otomatis berdasarkan tingkat asal yang dipilih</small>
                        </div>

                        <?php if ($target_id): ?>
                            <div class="info-box">
                                <i class="fas fa-info-circle"></i> <strong>Info:</strong>
                                Tingkat tujuan memiliki <?= count($target_members) ?> peserta didik.
                            </div>

                            <div class="table-responsive">
                                <table class="table table-sm table-striped table-bordered" id="table-target">
                                    <thead>
                                        <tr>
                                            <th width="40px">No</th>
                                            <th width="120px">NTA</th>
                                            <th>Nama</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $no=1; foreach ($target_members as $m): ?>
                                            <tr>
                                                <td><?= $no++ ?></td>
                                                <td><?= htmlspecialchars($m['nta'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($m['nama_peserta_didik'] ?? '') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const dataTableConfig = {
        'pageLength': -1,
        'lengthMenu': [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Semua']],
        'language': {
            'lengthMenu': 'Tampilkan _MENU_ entri',
            'zeroRecords': 'Tidak ada data yang ditemukan',
            'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
            'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
            'infoFiltered': '(disaring dari _MAX_ total entri)',
            'search': 'Cari:',
            'paginate': {
                'first': 'Pertama',
                'last': 'Terakhir',
                'next': 'Selanjutnya',
                'previous': 'Sebelumnya'
            }
        },
        'columnDefs': [
            { 'orderable': false, 'targets': 0 }
        ],
        'order': [[1, 'asc']]
    };

    const tableSource = $('#table-source').DataTable(dataTableConfig);
    $('#table-target').DataTable(Object.assign({}, dataTableConfig, { columnDefs: [] }));

    <?php if (isset($message)): ?>
    Swal.fire({
        icon: '<?= $message['type'] == 'danger' ? 'error' : $message['type'] ?>',
        title: '<?= $message['type'] == 'success' ? 'Berhasil' : 'Perhatian' ?>',
        text: '<?= addslashes($message['text']) ?>',
        timer: 3000,
        showConfirmButton: false
    });
    <?php endif; ?>

    const checkAllSource = document.getElementById('checkAllSource');
    const promoteBtnContainer = document.getElementById('promote-btn-container');

    function toggleActionBtn() {
        const anyChecked = $('.check-source:checked').length > 0;
        if (promoteBtnContainer) promoteBtnContainer.style.display = anyChecked ? 'block' : 'none';
    }

    if (checkAllSource) {
        checkAllSource.addEventListener('change', function() {
            const rows = tableSource.rows({ 'search': 'applied' }).nodes();
            $('input[type="checkbox"]', rows).prop('checked', this.checked);
            toggleActionBtn();
        });
    }

    $('#table-source tbody').on('change', '.check-source', function() {
        toggleActionBtn();
    });

    $('#table-source tbody').on('click', '.member-name', function() {
        const cb = $(this).closest('tr').find('.check-source');
        cb.prop('checked', !cb.prop('checked'));
        toggleActionBtn();
    });
});
</script>

<?php require_once '../templates/footer.php'; ?>

