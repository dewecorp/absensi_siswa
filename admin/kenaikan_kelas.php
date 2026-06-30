<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

$page_title = 'Kenaikan Kelas';

try {
    ensureAlumniOriginalIdColumn($pdo);
} catch (Exception $e) {
    // Kolom ini hanya pengait riwayat; proses utama akan menampilkan error jika benar-benar gagal saat dipakai.
}

// Define CSS libraries for this page
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];

// Define JS libraries for this page
$js_libs = [
    'https://cdn.jsdelivr.net/npm/sweetalert2@11',
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

// Get school profile for academic years
$school_profile = getSchoolProfile($pdo);
$current_tahun_ajaran = $school_profile['tahun_ajaran'];

// Logic for next academic year
$years = explode('/', $current_tahun_ajaran);
if (count($years) == 2) {
    $next_tahun_ajaran = ($years[0] + 1) . '/' . ($years[1] + 1);
} else {
    $next_tahun_ajaran = (date('Y')) . '/' . (date('Y') + 1);
}

// Handle Promotion/Demotion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['promote_students']) || isset($_POST['demote_students']))) {
    $source_class_id = (int)$_POST['source_class_id'];
    $target_class_id = (int)$_POST['target_class_id'];
    $selected_students = $_POST['students'] ?? [];
    $is_demotion = isset($_POST['demote_students']);

    if (($source_class_id || $source_class_id === 0) && ($target_class_id || $target_class_id === 0) && !empty($selected_students)) {
        try {
            if (!$is_demotion) {
                ensureSiswaBaruSnapshotForActiveYear($pdo);
            }

            $pdo->beginTransaction();

            if ($is_demotion) {
                // HANDLE DEMOTION (BATAL NAIK)
                if ($source_class_id === 999999) { // From Alumni back to Class 6
                    foreach ($selected_students as $id_alumni) {
                        $stmtAlumni = $pdo->prepare("SELECT * FROM tb_alumni WHERE id_alumni = ?");
                        $stmtAlumni->execute([$id_alumni]);
                        $alumni = $stmtAlumni->fetch(PDO::FETCH_ASSOC);

                        if ($alumni) {
                            $original_id_siswa = (int)($alumni['original_id_siswa'] ?? 0);
                            $alumni_tempat_lahir = trim((string)($alumni['tempat_lahir'] ?? ''));
                            $alumni_tanggal_lahir = trim((string)($alumni['tanggal_lahir'] ?? ''));
                            $alumni_tanggal_lahir = $alumni_tanggal_lahir !== '' ? $alumni_tanggal_lahir : null;
                            $alumni_wali = trim((string)($alumni['wali'] ?? ''));
                            $stmtExisting = null;
                            if ($original_id_siswa > 0) {
                                $stmtExisting = $pdo->prepare("SELECT id_siswa FROM tb_siswa WHERE id_siswa = ? LIMIT 1");
                                $stmtExisting->execute([$original_id_siswa]);
                            }
                            $existing_id_siswa = $stmtExisting ? (int)($stmtExisting->fetchColumn() ?: 0) : 0;
                            if ($existing_id_siswa <= 0 && trim((string)($alumni['nisn'] ?? '')) !== '') {
                                $stmtExisting = $pdo->prepare("SELECT id_siswa FROM tb_siswa WHERE TRIM(nisn) = TRIM(?) ORDER BY id_siswa ASC LIMIT 1");
                                $stmtExisting->execute([$alumni['nisn']]);
                                $existing_id_siswa = (int)($stmtExisting->fetchColumn() ?: 0);
                            }

                            if ($existing_id_siswa > 0) {
                                $stmtBack = $pdo->prepare("UPDATE tb_siswa SET nama_siswa = ?, nisn = ?, jenis_kelamin = ?, tempat_lahir = CASE WHEN ? <> '' THEN ? ELSE tempat_lahir END, tanggal_lahir = COALESCE(?, tanggal_lahir), wali = CASE WHEN ? <> '' THEN ? ELSE wali END, id_kelas = ? WHERE id_siswa = ?");
                                $stmtBack->execute([$alumni['nama_siswa'], $alumni['nisn'], $alumni['jenis_kelamin'], $alumni_tempat_lahir, $alumni_tempat_lahir, $alumni_tanggal_lahir, $alumni_wali, $alumni_wali, $target_class_id, $existing_id_siswa]);
                                $new_id_siswa = $existing_id_siswa;
                            } else {
                                $stmtBack = $pdo->prepare("INSERT INTO tb_siswa (nama_siswa, nisn, jenis_kelamin, tempat_lahir, tanggal_lahir, wali, id_kelas) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                $stmtBack->execute([$alumni['nama_siswa'], $alumni['nisn'], $alumni['jenis_kelamin'], $alumni_tempat_lahir !== '' ? $alumni_tempat_lahir : null, $alumni_tanggal_lahir, $alumni_wali !== '' ? $alumni_wali : null, $target_class_id]);
                                $new_id_siswa = (int)$pdo->lastInsertId();
                            }

                            // Restore barung records to aktif and re-link to new id_siswa.
                            // Old barung rows carry the OLD id_siswa (= id_alumni), so we match
                            // by old id, NISN/NTA, or name — whichever catches more rows.
                            $alumni_nisn = (string)($alumni['nisn'] ?? '');
                            $alumni_name = (string)($alumni['nama_siswa'] ?? '');
                            $barRestore = $pdo->prepare("
                                UPDATE tb_peserta_didik_barung
                                SET status = 'aktif', tanggal_keluar = NULL, id_siswa = ?
                                WHERE (
                                    id_siswa = ?
                                    OR (? <> ''
                                        AND CONVERT(TRIM(IFNULL(nta, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                                           = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci)
                                    OR (? <> ''
                                        AND LOWER(TRIM(nama_peserta_didik)) = LOWER(TRIM(?)))
                                )
                            ");
                            $barRestore->execute([
                                $new_id_siswa,
                                (int)$alumni['id_alumni'],
                                $alumni_nisn, $alumni_nisn,
                                $alumni_name, $alumni_name,
                            ]);

                            // Deduplicate: if multiple aktif records exist for the same tingkat,
                            // keep only the oldest (lowest id) and soft-delete the rest.
                            $dedup = $pdo->prepare("
                                SELECT id_tingkat_barung,
                                       MIN(id_peserta_didik_barung) AS keep_id,
                                       GROUP_CONCAT(id_peserta_didik_barung ORDER BY id_peserta_didik_barung) AS all_ids
                                FROM tb_peserta_didik_barung
                                WHERE id_siswa = ? AND IFNULL(status,'aktif') = 'aktif'
                                GROUP BY id_tingkat_barung
                                HAVING COUNT(*) > 1
                            ");
                            $dedup->execute([$new_id_siswa]);
                            foreach ($dedup->fetchAll(PDO::FETCH_ASSOC) as $dup) {
                                $ids = explode(',', $dup['all_ids']);
                                array_shift($ids); // remove keep_id
                                $ph = str_repeat('?,', count($ids) - 1) . '?';
                                $pdo->prepare("
                                    UPDATE tb_peserta_didik_barung
                                    SET status = 'keluar', tanggal_keluar = NOW()
                                    WHERE id_peserta_didik_barung IN ($ph) AND id_siswa = ?
                                ")->execute(array_merge($ids, [$new_id_siswa]));
                            }

                            $stmtDel = $pdo->prepare("DELETE FROM tb_alumni WHERE id_alumni = ?");
                            $stmtDel->execute([$id_alumni]);
                        }
                    }
                    $message = ['type' => 'success', 'text' => "Berhasil membatalkan kelulusan " . count($selected_students) . " siswa."];
                } else {
                    $placeholders = str_repeat('?,', count($selected_students) - 1) . '?';
                    $sql = "UPDATE tb_siswa SET id_kelas = ? WHERE id_siswa IN ($placeholders) AND id_kelas = ?";
                    $params = array_merge([$target_class_id], $selected_students, [$source_class_id]);
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $message = ['type' => 'success', 'text' => "Berhasil membatalkan kenaikan " . count($selected_students) . " siswa."];
                }
                $log_action = 'Batal Kenaikan Kelas';
            } else {
                // HANDLE PROMOTION (NAIK KELAS)
                if ($target_class_id === 999999) {
                    ensureAlumniOriginalIdColumn($pdo);

                    // Pre-fetch NISN map before siswa rows are moved out of active class
                    $ph = str_repeat('?,', count($selected_students) - 1) . '?';
                    $nisnMap = $pdo->prepare("SELECT id_siswa, nisn FROM tb_siswa WHERE id_siswa IN ($ph)");
                    $nisnMap->execute($selected_students);
                    $siswaNisn = [];
                    foreach ($nisnMap->fetchAll(PDO::FETCH_ASSOC) as $ns) {
                        $siswaNisn[(int)$ns['id_siswa']] = (string)$ns['nisn'];
                    }

                    foreach ($selected_students as $id_siswa) {
                        $stmtSiswa = $pdo->prepare("SELECT * FROM tb_siswa WHERE id_siswa = ?");
                        $stmtSiswa->execute([$id_siswa]);
                        $siswa = $stmtSiswa->fetch(PDO::FETCH_ASSOC);

                        if ($siswa) {
                            cleanupPramukaDataForSiswa($pdo, [(int)$id_siswa], [$siswaNisn[(int)$id_siswa] ?? $siswa['nisn'] ?? ''], [$siswa['nama_siswa'] ?? '']);

                            $stmtAlumniCheck = $pdo->prepare("SELECT id_alumni FROM tb_alumni WHERE (original_id_siswa = ? OR TRIM(nisn) = TRIM(?)) AND tahun_lulus = ? LIMIT 1");
                            $stmtAlumniCheck->execute([(int)$id_siswa, $siswa['nisn'], $current_tahun_ajaran]);
                            $existingAlumniId = (int)($stmtAlumniCheck->fetchColumn() ?: 0);
                            if ($existingAlumniId > 0) {
                                $stmtAlumni = $pdo->prepare("UPDATE tb_alumni SET original_id_siswa = ?, nama_siswa = ?, nisn = ?, jenis_kelamin = ?, tempat_lahir = COALESCE(NULLIF(?, ''), tempat_lahir), tanggal_lahir = COALESCE(?, tanggal_lahir), wali = COALESCE(NULLIF(?, ''), wali) WHERE id_alumni = ?");
                                $stmtAlumni->execute([(int)$id_siswa, $siswa['nama_siswa'], $siswa['nisn'], $siswa['jenis_kelamin'], trim((string)($siswa['tempat_lahir'] ?? '')), !empty($siswa['tanggal_lahir']) ? $siswa['tanggal_lahir'] : null, trim((string)($siswa['wali'] ?? '')), $existingAlumniId]);
                            } else {
                                $stmtAlumni = $pdo->prepare("INSERT INTO tb_alumni (original_id_siswa, nama_siswa, nisn, jenis_kelamin, tempat_lahir, tanggal_lahir, wali, tahun_lulus) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                                $stmtAlumni->execute([(int)$id_siswa, $siswa['nama_siswa'], $siswa['nisn'], $siswa['jenis_kelamin'], $siswa['tempat_lahir'] ?? null, $siswa['tanggal_lahir'] ?? null, $siswa['wali'] ?? null, $current_tahun_ajaran]);
                            }
                            $stmtMoveOut = $pdo->prepare("UPDATE tb_siswa SET id_kelas = NULL WHERE id_siswa = ?");
                            $stmtMoveOut->execute([$id_siswa]);
                        }
                    }
                    $message = ['type' => 'success', 'text' => "Berhasil meluluskan " . count($selected_students) . " siswa ke Alumni."];
                } else {
                    $placeholders = str_repeat('?,', count($selected_students) - 1) . '?';
                    $sql = "UPDATE tb_siswa SET id_kelas = ? WHERE id_siswa IN ($placeholders) AND id_kelas = ?";
                    $params = array_merge([$target_class_id], $selected_students, [$source_class_id]);
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $message = ['type' => 'success', 'text' => "Berhasil memindahkan " . count($selected_students) . " siswa."];
                }
                $log_action = 'Kenaikan Kelas';
            }
            
            $count = count($selected_students);
            $username = $_SESSION['username'] ?? 'system';
            logActivity($pdo, $username, $log_action, "Memproses " . ($is_demotion ? 'pembatalan ' : '') . "kenaikan/kelulusan $count siswa");
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = ['type' => 'danger', 'text' => 'Gagal memproses data: ' . $e->getMessage()];
        }
    }
}

// Get all classes
$stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
$all_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function for class detection
if (!function_exists('detectClassLevel')) {
    function detectClassLevel($name) {
        $name = strtoupper(trim($name));
        $roman_map = ['VI' => 6, 'V' => 5, 'IV' => 4, 'III' => 3, 'II' => 2, 'I' => 1];
        foreach ($roman_map as $roman => $num) {
            if (preg_match('/\b' . $roman . '\b/', $name) || $name === $roman) return $num;
        }
        if (preg_match('/(\d)/', $name, $matches)) return (int)$matches[1];
        return 0;
    }
}

$mode = $_GET['mode'] ?? 'promote';
$source_id = isset($_GET['source_class']) ? (int)$_GET['source_class'] : null;
$target_id = isset($_GET['target_class']) ? (int)$_GET['target_class'] : null;

// Automatic target class selection (always fixed)
if ($source_id) {
    if ($source_id === 999999) {
        // From Alumni, suggest Class 6 (demote)
        $stmtSuggest = $pdo->prepare("SELECT id_kelas FROM tb_kelas WHERE nama_kelas LIKE '%6%' OR nama_kelas = '6' LIMIT 1");
        $stmtSuggest->execute();
        $target_id = $stmtSuggest->fetchColumn();
    } else {
        $stmtCur = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas = ?");
        $stmtCur->execute([$source_id]);
        $current_name = $stmtCur->fetchColumn();
        $current_level = detectClassLevel($current_name);
        
        if ($current_level == 6) {
            $target_id = 999999; // Alumni
        } elseif ($current_level > 0 && $current_level < 6) {
            $next_level = $current_level + 1;
            $roman_map_rev = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI'];
            $next_roman = $roman_map_rev[$next_level];
            
            $stmtNext = $pdo->prepare("
                SELECT id_kelas FROM tb_kelas 
                WHERE (nama_kelas LIKE ? OR nama_kelas = ? OR nama_kelas LIKE ? OR nama_kelas = ?) 
                AND id_kelas != ?
                ORDER BY 
                    CASE 
                        WHEN nama_kelas = ? THEN 1 
                        WHEN nama_kelas = ? THEN 2
                        ELSE 3 
                    END ASC 
                LIMIT 1
            ");
            $search_num = "%" . $next_level . "%";
            $search_roman = "%" . $next_roman . "%";
            $stmtNext->execute([$search_num, (string)$next_level, $search_roman, $next_roman, $source_id, (string)$next_level, $next_roman]);
            $target_id = $stmtNext->fetchColumn();
        }
    }
}

// Fetch Source Students
$source_students = [];
if ($source_id) {
    if ($source_id === 999999) {
        $stmt = $pdo->query("SELECT id_alumni as id_siswa, nama_siswa, nisn, jenis_kelamin FROM tb_alumni ORDER BY tahun_lulus DESC, nama_siswa ASC");
        $source_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn, jenis_kelamin FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
        $stmt->execute([$source_id]);
        $source_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$target_students = [];
$target_class_name_display = '';
if ($target_id) {
    if ($target_id === 999999) {
        $stmt = $pdo->query("SELECT id_alumni as id_siswa, nama_siswa, nisn, jenis_kelamin FROM tb_alumni ORDER BY tahun_lulus DESC, nama_siswa ASC");
        $target_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $target_class_name_display = 'Alumni';
    } else {
        $stmt = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn, jenis_kelamin FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
        $stmt->execute([$target_id]);
        $target_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmtName = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas = ?");
        $stmtName->execute([$target_id]);
        $target_class_name_display = $stmtName->fetchColumn();
    }
}

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
    .mode-switcher { margin-bottom: 20px; }
    .action-btn-container { display: none; margin-top: 15px; }
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Kenaikan Kelas</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="row">
            <!-- Left Column: Source -->
            <div class="col-md-6">
                <div class="card card-promotion">
                    <div class="card-header"><?= $current_tahun_ajaran ?> Tahun Ajaran Asal</div>
                    <div class="card-body">
                        <form method="GET" id="sourceForm">
                            <input type="hidden" name="mode" value="<?= $mode ?>">
                            <?php if ($target_id): ?><input type="hidden" name="target_class" value="<?= $target_id ?>"><?php endif; ?>
                            <div class="form-group">
                                <label>Kelas</label>
                                <select name="source_class" class="form-control" onchange="this.form.submit()">
                                    <option value="">-- Pilih Kelas --</option>
                                    <?php foreach ($all_classes as $cls): ?>
                                        <option value="<?= $cls['id_kelas'] ?>" <?= $source_id == $cls['id_kelas'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cls['nama_kelas']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="999999" <?= $source_id == 999999 ? 'selected' : '' ?>>Alumni</option>
                                </select>
                            </div>
                        </form>

                        <?php if ($source_id): ?>
                        <form method="POST">
                            <input type="hidden" name="source_class_id" value="<?= $source_id ?>">
                            <input type="hidden" name="target_class_id" value="<?= $target_id ?>">
                            <div class="table-responsive">
                                <table class="table table-sm table-striped table-bordered" id="table-source">
                                    <thead>
                                        <tr>
                                            <th width="30px"><input type="checkbox" id="checkAllSource"></th>
                                            <th width="40px">No</th>
                                            <th>NISN</th>
                                            <th>Nama</th>
                                            <th width="40px">L/P</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $no=1; foreach ($source_students as $s): ?>
                                        <tr>
                                            <td><input type="checkbox" name="students[]" value="<?= $s['id_siswa'] ?>" class="check-source"></td>
                                            <td><?= $no++ ?></td>
                                            <td><?= htmlspecialchars($s['nisn']) ?></td>
                                            <td class="student-name" style="cursor: pointer; color: #6777ef;"><?= htmlspecialchars($s['nama_siswa']) ?></td>
                                            <td><?= $s['jenis_kelamin'] ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div id="promote-btn-container" class="action-btn-container">
                                <?php if ($source_id && $target_id && count($source_students) > 0): ?>
                                    <button type="submit" name="promote_students" class="btn btn-primary btn-block">
                                        <i class="fas fa-arrow-up"></i> Proses Naik Kelas
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column: Target -->
            <div class="col-md-6">
                <div class="card card-promotion">
                    <div class="card-header"><?= $next_tahun_ajaran ?> Tahun Ajaran Tujuan</div>
                    <div class="card-body">
                        <div class="form-group">
                            <label>Kelas</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($target_class_name_display ?: '-- Kelas Tujuan Tidak Ditemukan --') ?>" readonly disabled>
                            <small class="text-muted">Kelas tujuan otomatis berdasarkan kelas asal yang dipilih</small>
                        </div>

                        <?php if ($target_id): ?>
                        <div class="info-box">
                            <i class="fas fa-info-circle"></i> <strong>Info:</strong> 
                            Kelas tujuan memiliki <?= count($target_students) ?> siswa. Siswa yang akan <?= $mode == 'promote' ? 'naik' : 'batal naik' ?> akan ditambahkan ke kelas ini.
                        </div>

                        <div class="table-responsive">
                            <form method="POST">
                                <input type="hidden" name="source_class_id" value="<?= $target_id ?>">
                                <input type="hidden" name="target_class_id" value="<?= $source_id ?>">
                                <table class="table table-sm table-striped table-bordered" id="table-target">
                                    <thead>
                                        <tr>
                                            <th width="30px"><input type="checkbox" id="checkAllTarget"></th>
                                            <th width="30px">No</th>
                                            <th>NISN</th>
                                            <th>Nama</th>
                                            <th width="40px">L/P</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $no=1; foreach ($target_students as $t): ?>
                                        <tr>
                                            <td><input type="checkbox" name="students[]" value="<?= $t['id_siswa'] ?>" class="check-target"></td>
                                            <td><?= $no++ ?></td>
                                            <td><?= htmlspecialchars($t['nisn']) ?></td>
                                            <td class="student-name-target" style="cursor: pointer; color: #6777ef;"><?= htmlspecialchars($t['nama_siswa']) ?></td>
                                            <td><?= $t['jenis_kelamin'] ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <div id="demote-btn-container" class="action-btn-container">
                                    <button type="submit" name="demote_students" class="btn btn-danger btn-block">
                                        <i class="fas fa-arrow-down"></i> Proses Batal Naik
                                    </button>
                                </div>
                            </form>
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
    // Initialize DataTables
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
    const tableTarget = $('#table-target').DataTable(dataTableConfig);

    // Show SweetAlert if there's a message
    <?php if (isset($message)): ?>
    Swal.fire({
        icon: '<?= $message['type'] == 'danger' ? 'error' : $message['type'] ?>',
        title: '<?= $message['type'] == 'success' ? 'Berhasil' : 'Perhatian' ?>',
        text: '<?= $message['text'] ?>',
        timer: 3000,
        showConfirmButton: false
    });
    <?php endif; ?>

    const checkAllSource = document.getElementById('checkAllSource');
    const promoteBtnContainer = document.getElementById('promote-btn-container');

    const checkAllTarget = document.getElementById('checkAllTarget');
    const demoteBtnContainer = document.getElementById('demote-btn-container');

    function togglePromoteBtn() {
        const anyChecked = $('.check-source:checked').length > 0;
        promoteBtnContainer.style.display = anyChecked ? 'block' : 'none';
    }

    function toggleDemoteBtn() {
        const anyChecked = $('.check-target:checked').length > 0;
        demoteBtnContainer.style.display = anyChecked ? 'block' : 'none';
    }

    if (checkAllSource) {
        checkAllSource.addEventListener('change', function() {
            const rows = tableSource.rows({ 'search': 'applied' }).nodes();
            $('input[type="checkbox"]', rows).prop('checked', this.checked);
            togglePromoteBtn();
        });
    }

    $('#table-source tbody').on('change', '.check-source', function() {
        togglePromoteBtn();
    });

    $('#table-source tbody').on('click', '.student-name', function() {
        const cb = $(this).closest('tr').find('.check-source');
        cb.prop('checked', !cb.prop('checked'));
        togglePromoteBtn();
    });

    if (checkAllTarget) {
        checkAllTarget.addEventListener('change', function() {
            const rows = tableTarget.rows({ 'search': 'applied' }).nodes();
            $('input[type="checkbox"]', rows).prop('checked', this.checked);
            toggleDemoteBtn();
        });
    }

    $('#table-target tbody').on('change', '.check-target', function() {
        toggleDemoteBtn();
    });

    $('#table-target tbody').on('click', '.student-name-target', function() {
        const cb = $(this).closest('tr').find('.check-target');
        cb.prop('checked', !cb.prop('checked'));
        toggleDemoteBtn();
    });
});
</script>

<?php require_once '../templates/footer.php'; ?>
