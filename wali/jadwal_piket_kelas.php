<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['wali', 'admin'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$can_crud = !in_array($user_level, ['admin', 'kepala_madrasah'], true);
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
if ($user_level === 'admin') {
    $selected_kelas_id = (int)($_GET['kelas'] ?? 0);
    $selected_kelas_name = '';
    foreach ($all_classes as $c) {
        if ((int)$c['id_kelas'] === $selected_kelas_id) {
            $selected_kelas_name = (string)$c['nama_kelas'];
            break;
        }
    }
}

$days_order = getUrutanHariJadwalSekolah($pdo);

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
        $hari = trim((string)($_POST['hari'] ?? 'Senin'));
        $id_siswa = (int)($_POST['id_siswa'] ?? 0);
        $tugas = trim((string)($_POST['tugas'] ?? 'Piket Umum'));
        $urutan = (int)($_POST['urutan'] ?? 1);
        $status = in_array($_POST['status'] ?? '', ['Aktif', 'Nonaktif'], true) ? $_POST['status'] : 'Aktif';

        if ($id_siswa <= 0 || $hari === '') {
            $message = ['type' => 'warning', 'text' => 'Pilih Siswa dan Hari Piket.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_jadwal_piket_kelas (
                            id_wali, id_kelas, hari, id_siswa, tugas, urutan, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$guru_id, $id_kelas, $hari, $id_siswa, $tugas, $urutan, $status]);
                    $message = ['type' => 'success', 'text' => 'Jadwal piket siswa berhasil ditambahkan.'];
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE tb_jadwal_piket_kelas SET
                            id_kelas = ?, hari = ?, id_siswa = ?, tugas = ?, urutan = ?, status = ?
                        WHERE id = ? " . ($user_level !== 'admin' ? "AND id_wali = $guru_id" : "") . "
                    ");
                    $stmt->execute([$id_kelas, $hari, $id_siswa, $tugas, $urutan, $status, $id]);
                    $message = ['type' => 'success', 'text' => 'Jadwal piket berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM tb_jadwal_piket_kelas WHERE id = ? " . ($user_level !== 'admin' ? "AND id_wali = $guru_id" : ""))->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Jadwal piket berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
}
}

$siswa_list = [];
$rows = [];
if ($selected_kelas_id > 0) {
    $stS = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
    $stS->execute([$selected_kelas_id]);
    $siswa_list = $stS->fetchAll(PDO::FETCH_ASSOC);

// Fetch piket rows
$where = ["1=1"];
$params = [];
$where[] = "p.id_kelas = ?";
$params[] = $selected_kelas_id;
if ($user_level !== 'admin') {
    $where[] = "p.id_wali = ?";
    $params[] = $guru_id;
}

$where_sql = implode(' AND ', $where);
$field_hari = implode(',', array_map([$pdo, 'quote'], $days_order));
$stmt = $pdo->prepare("
    SELECT p.*, s.nama_siswa, s.nisn, k.nama_kelas
    FROM tb_jadwal_piket_kelas p
    JOIN tb_siswa s ON s.id_siswa = p.id_siswa
    LEFT JOIN tb_kelas k ON k.id_kelas = p.id_kelas
    WHERE $where_sql
    ORDER BY FIELD(p.hari, $field_hari), p.urutan ASC, s.nama_siswa ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Kelompokkan per hari untuk tampilan Kartu Mingguan
$piket_by_day = [];
foreach ($days_order as $d) {
    $piket_by_day[$d] = [];
}
foreach ($rows as $r) {
    if ($r['status'] === 'Aktif') {
        $h = $r['hari'];
        if (!isset($piket_by_day[$h])) $piket_by_day[$h] = [];
        $piket_by_day[$h][] = $r;
    }
}

$page_title = 'Jadwal Piket Kelas';
$css_libs = [];
$js_libs = [];

$js_page = [<<<'JS'
$(document).ready(function() {
    $('#btnTambahPiket').on('click', function() {
        $('#formPiketAction').val('tambah');
        $('#piketId').val('');
        $('#modalPiketTitle').text('Tambah Petugas Piket');
        $('#formPiket')[0].reset();
        $('#modalPiket').modal('show');
    });

    $(document).on('click', '.btn-edit-piket', function() {
        var data = $(this).data('json');
        $('#formPiketAction').val('edit');
        $('#piketId').val(data.id);
        $('#modalPiketTitle').text('Edit Petugas Piket');
        $('#inp_hari').val(data.hari);
        $('#inp_siswa').val(data.id_siswa);
        $('#inp_tugas').val(data.tugas);
        $('#inp_urutan').val(data.urutan);
        $('#inp_status').val(data.status);
        $('#modalPiket').modal('show');
    });

    $(document).on('click', '.btn-hapus-piket', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        Swal.fire({
            title: 'Hapus Petugas Piket?',
            text: nama + ' akan dihapus dari jadwal piket.',
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
            <h1>Jadwal Piket Kelas <?= $user_level === 'admin' ? (!empty($selected_kelas_name) ? '- Kelas ' . htmlspecialchars($selected_kelas_name) : '') : (!empty($wali_kelas_name) ? '- Kelas ' . htmlspecialchars($wali_kelas_name) : '') ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <?php if ($user_level === 'admin'): ?>
            <div class="card">
                <div class="card-header">
                    <h4>Filter Kelas</h4>
                </div>
                <div class="card-body">
                    <form method="GET" class="form-inline">
                        <label class="mr-2" for="selectKelasPiket">Pilih Kelas:</label>
                        <select name="kelas" id="selectKelasPiket" class="form-control" style="min-width: 220px;" onchange="this.form.submit();">
                            <option value="">-- Pilih Kelas --</option>
                            <?php foreach ($all_classes as $c): ?>
                                <option value="<?= (int)$c['id_kelas'] ?>" <?= $selected_kelas_id === (int)$c['id_kelas'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nama_kelas']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($selected_kelas_id > 0 || $user_level !== 'admin'): ?>
            <?php
            $en_day = date('l');
            $map_hari = ['Sunday' => 'Ahad', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'];
            $hari_ini = $map_hari[$en_day] ?? '';
            $total_petugas = count($rows);
            $hari_terisi = 0;
            foreach ($days_order as $hd) { if (!empty($piket_by_day[$hd])) $hari_terisi++; }
            ?>
            <style>
            .piket-card { border-radius: 12px; overflow: hidden; }
            .piket-card .piket-head { background: linear-gradient(135deg, #6777ef, #3abaf4); color: #fff; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; }
            .piket-card.piket-today { border: 2px solid #6777ef !important; box-shadow: 0 4px 14px rgba(103,119,239,.35) !important; }
            .piket-day-icon { width: 38px; height: 38px; border-radius: 10px; background: rgba(255,255,255,.22); display: inline-flex; align-items: center; justify-content: center; font-size: 17px; margin-right: 10px; flex-shrink: 0; }
            .piket-item { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: 1px solid #eef1f6; border-radius: 10px; margin-bottom: 8px; background: #fff; }
            .piket-item:last-child { margin-bottom: 0; }
            .piket-num { width: 24px; height: 24px; border-radius: 50%; background: #eef2ff; color: #4338ca; font-size: 12px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
            .piket-avatar { width: 34px; height: 34px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 800; color: #fff; flex-shrink: 0; }
            .piket-name { font-size: 13.5px; line-height: 1.25; }
            .piket-nisn { font-size: 11.5px; }
            </style>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                    <h4 class="mb-0">Jadwal Piket Mingguan <?= $user_level === 'admin' && !empty($selected_kelas_name) ? '- Kelas ' . htmlspecialchars($selected_kelas_name) : '' ?></h4>
                    <div>
                        <?php
                        $qs_piket = [];
                        if ($selected_kelas_id > 0) { $qs_piket['kelas'] = $selected_kelas_id; }
                        $url_piket_cetak = 'export_piket_pdf.php?' . http_build_query(array_merge($qs_piket, ['mode' => 'print']));
                        $url_piket_xls = 'export_piket_excel.php?' . http_build_query($qs_piket);
                        ?>
                        <a href="<?= htmlspecialchars($url_piket_cetak) ?>" target="_blank" class="btn btn-danger btn-sm mr-1" title="Cetak / Simpan PDF">
                            <i class="fas fa-print mr-1"></i> Cetak / PDF
                        </a>
                        <a href="<?= htmlspecialchars($url_piket_xls) ?>" class="btn btn-success btn-sm mr-2" title="Ekspor Excel">
                            <i class="fas fa-file-excel mr-1"></i> Excel
                        </a>
                        <?php if ($can_crud): ?>
                        <button type="button" class="btn btn-primary btn-sm" id="btnTambahPiket" <?= empty($siswa_list) && $user_level !== 'admin' ? 'disabled' : '' ?>>
                            <i class="fas fa-plus mr-1"></i> Tambah Petugas
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="alert alert-light border small text-muted mb-3">
                        <i class="fas fa-info-circle mr-1 text-primary"></i>
                        Total <strong><?= (int)$total_petugas ?> petugas</strong> &bull; <?= (int)$hari_terisi ?> dari <?= count($days_order) ?> hari terisi
                        <?php if ($hari_ini !== ''): ?> &bull; Hari ini: <strong><?= htmlspecialchars($hari_ini) ?></strong><?php endif; ?>
                    </div>
                    <div class="row">
                        <?php
                        $avatar_colors = ['#6777ef', '#3abaf4', '#47c363', '#ffa426', '#fc544b', '#9467ef'];
                        foreach ($days_order as $di => $hari):
                            $petugas = $piket_by_day[$hari] ?? [];
                            $is_today = ($hari === $hari_ini);
                        ?>
                            <div class="col-md-4 col-sm-6 mb-4">
                                <div class="card shadow-sm h-100 border piket-card <?= $is_today ? 'piket-today' : '' ?>">
                                    <div class="piket-head">
                                        <div class="d-flex align-items-center">
                                            <span class="piket-day-icon"><i class="fas fa-calendar-day"></i></span>
                                            <div>
                                                <div class="font-weight-bold" style="font-size: 15px; line-height: 1.2;"><?= htmlspecialchars($hari) ?></div>
                                                <small style="opacity:.9;"><?= count($petugas) ?> petugas<?= $is_today ? ' &bull; Hari ini' : '' ?></small>
                                            </div>
                                        </div>
                                        <?php if ($is_today): ?>
                                            <span class="badge badge-warning">Hari Ini</span>
                                        <?php else: ?>
                                            <span class="badge badge-light"><?= count($petugas) ?> Siswa</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="card-body p-3" style="background:#f8fafc;">
                                        <?php if (empty($petugas)): ?>
                                            <div class="text-center text-muted py-3">
                                                <div style="font-size:28px;"><i class="fas fa-user-slash"></i></div>
                                                <div class="mt-1" style="font-size:13px;">Belum ada petugas piket.</div>
                                                <?php if ($can_crud): ?>
                                                    <div class="mt-1" style="font-size:12px;">Klik Tambah Petugas untuk mengisi hari <?= htmlspecialchars($hari) ?>.</div>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <?php foreach ($petugas as $idx => $p): ?>
                                                <?php
                                                $inisial = strtoupper(implode('', array_slice(array_map(function($w){ return mb_substr($w,0,1); }, preg_split('/\s+/', trim((string)$p['nama_siswa']))), 0, 2)));
                                                $bg = $avatar_colors[($idx + $di) % count($avatar_colors)];
                                                ?>
                                                <div class="piket-item">
                                                    <span class="piket-num"><?= $idx + 1 ?></span>
                                                    <span class="piket-avatar" style="background:<?= $bg ?>;"><?= htmlspecialchars($inisial) ?></span>
                                                    <div class="flex-grow-1" style="min-width:0;">
                                                        <div class="piket-name font-weight-bold text-dark text-truncate"><?= htmlspecialchars($p['nama_siswa']) ?></div>
                                                        <div class="piket-nisn text-muted">NISN: <?= htmlspecialchars($p['nisn'] ?? '-') ?></div>
                                                    </div>
                                                    <?php if ($can_crud): ?>
                                                    <div class="no-print d-flex" style="gap:4px;">
                                                        <button type="button" class="btn btn-warning btn-sm py-0 px-1 btn-edit-piket" data-json='<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>' title="Edit"><i class="fas fa-pencil-alt" style="font-size: 10px;"></i></button>
                                                        <button type="button" class="btn btn-danger btn-sm py-0 px-1 btn-hapus-piket" data-id="<?= (int)$p['id'] ?>" data-nama="<?= htmlspecialchars($p['nama_siswa'], ENT_QUOTES) ?>" title="Hapus"><i class="fas fa-times" style="font-size: 10px;"></i></button>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- Modal Form Tambah / Edit -->
<div class="modal fade" id="modalPiket" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <form method="POST" id="formPiket">
                <input type="hidden" name="action" id="formPiketAction" value="tambah">
                <input type="hidden" name="id" id="piketId" value="">
                <input type="hidden" name="id_kelas" value="<?= (int)$selected_kelas_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalPiketTitle">Petugas Piket Kelas</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Hari Piket <span class="text-danger">*</span></label>
                        <select name="hari" id="inp_hari" class="form-control" required>
                            <?php foreach ($days_order as $d): ?>
                                <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Siswa <span class="text-danger">*</span></label>
                        <select name="id_siswa" id="inp_siswa" class="form-control" required>
                            <option value="">-- Pilih Siswa --</option>
                            <?php foreach ($siswa_list as $s): ?>
                                <option value="<?= (int)$s['id_siswa'] ?>">
                                    <?= htmlspecialchars($s['nama_siswa']) ?> (<?= htmlspecialchars($s['nisn'] ?? '-') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Tugas Spesifik</label>
                        <input type="text" name="tugas" id="inp_tugas" class="form-control" value="Piket Umum" placeholder="Menyapu, Menghapus Papan, Menyiram Tanaman, dll">
                    </div>
                    <div class="form-group">
                        <label>Urutan Penomoran</label>
                        <input type="number" name="urutan" id="inp_urutan" class="form-control" value="1" min="1">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="inp_status" class="form-control">
                            <option value="Aktif">Aktif</option>
                            <option value="Nonaktif">Nonaktif</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan Petugas</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form method="POST" id="formHapus" class="d-none">
    <input type="hidden" name="action" value="hapus">
    <input type="hidden" name="id" id="formHapusId">
</form>

<?php include '../templates/footer.php'; ?>
