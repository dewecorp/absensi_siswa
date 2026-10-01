<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['guru', 'wali'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$message = null;

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $id_siswa = (int)($_POST['id_siswa'] ?? 0);
        $id_kelas = (int)($_POST['id_kelas'] ?? 0) ?: null;
        $id_mapel = (int)($_POST['id_mapel'] ?? 0) ?: null;
        $tanggal = !empty($_POST['tanggal']) ? date('Y-m-d', strtotime($_POST['tanggal'])) : date('Y-m-d');
        $kategori = in_array($_POST['kategori'] ?? '', ['Akademik', 'Sikap', 'Keterampilan', 'Keaktifan', 'Potensi', 'Kendala Belajar', 'Catatan Guru'], true) ? $_POST['kategori'] : 'Akademik';
        $ringkasan = trim((string)($_POST['ringkasan'] ?? ''));
        $kendala = trim((string)($_POST['kendala'] ?? ''));
        $tindak_lanjut = trim((string)($_POST['tindak_lanjut'] ?? ''));
        $status = in_array($_POST['status'] ?? '', ['Aktif', 'Selesai', 'Dalam Pemantauan'], true) ? $_POST['status'] : 'Aktif';

        $perkembangan_akademik = trim((string)($_POST['perkembangan_akademik'] ?? ''));
        $perkembangan_sikap = trim((string)($_POST['perkembangan_sikap'] ?? ''));
        $perkembangan_keterampilan = trim((string)($_POST['perkembangan_keterampilan'] ?? ''));
        $keaktifan = trim((string)($_POST['keaktifan'] ?? ''));
        $potensi = trim((string)($_POST['potensi'] ?? ''));
        $catatan_guru = trim((string)($_POST['catatan_guru'] ?? ''));
        $rekomendasi = trim((string)($_POST['rekomendasi'] ?? ''));

        // Auto dapatkan id_kelas dari siswa bila tidak dipilih
        if (!$id_kelas && $id_siswa > 0) {
            $stk = $pdo->prepare("SELECT id_kelas FROM tb_siswa WHERE id_siswa = ?");
            $stk->execute([$id_siswa]);
            $id_kelas = (int)$stk->fetchColumn() ?: null;
        }

        if ($id_siswa <= 0 || $ringkasan === '') {
            $message = ['type' => 'warning', 'text' => 'Pilih Siswa dan tuliskan Ringkasan Perkembangan.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_catatan_perkembangan (
                            id_guru, id_siswa, id_kelas, id_mapel, tanggal, kategori,
                            ringkasan, kendala, tindak_lanjut, status,
                            perkembangan_akademik, perkembangan_sikap, perkembangan_keterampilan,
                            keaktifan, potensi, catatan_guru, rekomendasi
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $guru_id, $id_siswa, $id_kelas, $id_mapel, $tanggal, $kategori,
                        $ringkasan, $kendala, $tindak_lanjut, $status,
                        $perkembangan_akademik, $perkembangan_sikap, $perkembangan_keterampilan,
                        $keaktifan, $potensi, $catatan_guru, $rekomendasi
                    ]);
                    $message = ['type' => 'success', 'text' => 'Catatan perkembangan berhasil ditambahkan.'];
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE tb_catatan_perkembangan SET
                            id_siswa = ?, id_kelas = ?, id_mapel = ?, tanggal = ?, kategori = ?,
                            ringkasan = ?, kendala = ?, tindak_lanjut = ?, status = ?,
                            perkembangan_akademik = ?, perkembangan_sikap = ?, perkembangan_keterampilan = ?,
                            keaktifan = ?, potensi = ?, catatan_guru = ?, rekomendasi = ?
                        WHERE id = ? AND id_guru = ?
                    ");
                    $stmt->execute([
                        $id_siswa, $id_kelas, $id_mapel, $tanggal, $kategori,
                        $ringkasan, $kendala, $tindak_lanjut, $status,
                        $perkembangan_akademik, $perkembangan_sikap, $perkembangan_keterampilan,
                        $keaktifan, $potensi, $catatan_guru, $rekomendasi,
                        $id, $guru_id
                    ]);
                    $message = ['type' => 'success', 'text' => 'Catatan perkembangan berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM tb_catatan_perkembangan WHERE id = ? AND id_guru = ?")->execute([$id, $guru_id]);
            $message = ['type' => 'success', 'text' => 'Catatan perkembangan berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
}

// Master lists
$mapel_list = $pdo->query("SELECT id_mapel, nama_mapel FROM tb_mata_pelajaran ORDER BY nama_mapel ASC")->fetchAll(PDO::FETCH_ASSOC);
$kelas_list = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
$siswa_list = $pdo->query("SELECT s.id_siswa, s.nama_siswa, s.nisn, k.nama_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas ORDER BY k.nama_kelas ASC, s.nama_siswa ASC")->fetchAll(PDO::FETCH_ASSOC);
$kategori_options = ['Akademik', 'Sikap', 'Keterampilan', 'Keaktifan', 'Potensi', 'Kendala Belajar', 'Catatan Guru'];

// Filters
$f_kategori = trim((string)($_GET['f_kategori'] ?? ''));
$f_kelas = (int)($_GET['f_kelas'] ?? 0);
$f_mapel = (int)($_GET['f_mapel'] ?? 0);
$f_siswa = (int)($_GET['f_siswa'] ?? 0);
$f_status = trim((string)($_GET['f_status'] ?? ''));

$where = ["c.id_guru = ?"];
$params = [$guru_id];

if ($f_kategori !== '') {
    $where[] = "c.kategori = ?";
    $params[] = $f_kategori;
}
if ($f_kelas > 0) {
    $where[] = "c.id_kelas = ?";
    $params[] = $f_kelas;
}
if ($f_mapel > 0) {
    $where[] = "c.id_mapel = ?";
    $params[] = $f_mapel;
}
if ($f_siswa > 0) {
    $where[] = "c.id_siswa = ?";
    $params[] = $f_siswa;
}
if ($f_status !== '') {
    $where[] = "c.status = ?";
    $params[] = $f_status;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT c.*, s.nama_siswa, s.nisn, k.nama_kelas, m.nama_mapel, g.nama_guru
    FROM tb_catatan_perkembangan c
    JOIN tb_siswa s ON s.id_siswa = c.id_siswa
    LEFT JOIN tb_kelas k ON k.id_kelas = c.id_kelas
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = c.id_mapel
    LEFT JOIN tb_guru g ON g.id_guru = c.id_guru
    WHERE $where_sql
    ORDER BY c.tanggal DESC, c.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Catatan Perkembangan Siswa';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
];

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-catatan').length) {
        $('#table-catatan').DataTable({
            'order': [[1, 'desc']],
            'columnDefs': [{ 'sortable': false, 'targets': [11] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada catatan perkembangan',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    if ($.fn.select2) {
        $('.select2-siswa').select2({
            dropdownParent: $('#modalCatatan'),
            placeholder: '-- Pilih Siswa --',
            allowClear: true,
            width: '100%'
        });
    }

    $('#btnTambahCatatan').on('click', function() {
        $('#formCatatanAction').val('tambah');
        $('#catatanId').val('');
        $('#modalCatatanTitle').text('Tambah Catatan Perkembangan');
        $('#formCatatan')[0].reset();
        $('#inp_siswa').val('').trigger('change');
        $('#modalCatatan').modal('show');
    });

    $(document).on('click', '.btn-edit-catatan', function() {
        var data = $(this).data('json');
        $('#formCatatanAction').val('edit');
        $('#catatanId').val(data.id);
        $('#modalCatatanTitle').text('Edit Catatan Perkembangan');
        $('#inp_siswa').val(data.id_siswa).trigger('change');
        $('#inp_kelas').val(data.id_kelas || '');
        $('#inp_mapel').val(data.id_mapel || '');
        $('#inp_tanggal').val(data.tanggal);
        $('#inp_kategori').val(data.kategori);
        $('#inp_status').val(data.status);
        $('#inp_ringkasan').val(data.ringkasan);
        $('#inp_kendala').val(data.kendala || '');
        $('#inp_tindak_lanjut').val(data.tindak_lanjut || '');

        $('#inp_akademik').val(data.perkembangan_akademik || '');
        $('#inp_sikap').val(data.perkembangan_sikap || '');
        $('#inp_keterampilan').val(data.perkembangan_keterampilan || '');
        $('#inp_keaktifan').val(data.keaktifan || '');
        $('#inp_potensi').val(data.potensi || '');
        $('#inp_catatan_guru').val(data.catatan_guru || '');
        $('#inp_rekomendasi').val(data.rekomendasi || '');

        $('#modalCatatan').modal('show');
    });

    // Detail & Timeline Modal (Fitur 8)
    $(document).on('click', '.btn-detail-catatan', function() {
        var data = $(this).data('json');
        $('#det_nama').text(data.nama_siswa);
        $('#det_nisn').text(data.nisn || '-');
        $('#det_kelas').text(data.nama_kelas || '-');
        $('#det_mapel').text(data.nama_mapel || '-');
        $('#det_tanggal').text(data.tanggal);
        $('#det_kategori').text(data.kategori);
        $('#det_status').text(data.status);

        $('#det_ringkasan').text(data.ringkasan || '-');
        $('#det_kendala').text(data.kendala || '-');
        $('#det_tindak_lanjut').text(data.tindak_lanjut || '-');
        $('#det_akademik').text(data.perkembangan_akademik || '-');
        $('#det_sikap').text(data.perkembangan_sikap || '-');
        $('#det_keterampilan').text(data.perkembangan_keterampilan || '-');
        $('#det_keaktifan').text(data.keaktifan || '-');
        $('#det_potensi').text(data.potensi || '-');
        $('#det_catatan_guru').text(data.catatan_guru || '-');
        $('#det_rekomendasi').text(data.rekomendasi || '-');

        // Render timeline
        $('#timelineContainer').html('<div class="text-center p-3"><i class="fas fa-spinner fa-spin"></i> Memuat timeline...</div>');
        $.ajax({
            url: 'catatan_perkembangan.php?ajax_timeline=1&id_siswa=' + data.id_siswa,
            dataType: 'json',
            success: function(timeline) {
                if (!timeline || !timeline.length) {
                    $('#timelineContainer').html('<div class="text-muted p-3 text-center">Belum ada riwayat perkembangan lainnya.</div>');
                    return;
                }
                var html = '<div class="activities">';
                timeline.forEach(function(item) {
                    html += '<div class="activity">';
                    html += '  <div class="activity-icon bg-primary text-white shadow-primary"><i class="fas fa-chart-line"></i></div>';
                    html += '  <div class="activity-detail">';
                    html += '    <div class="mb-2">';
                    html += '      <span class="text-job text-primary font-weight-bold">' + item.tanggal_formatted + '</span>';
                    html += '      <span class="bullet"></span>';
                    html += '      <span class="badge badge-info">' + item.kategori + '</span>';
                    html += '    </div>';
                    html += '    <p class="font-weight-bold mb-1">' + item.ringkasan + '</p>';
                    if (item.kendala) html += '    <p class="mb-1 text-danger small"><strong>Kendala:</strong> ' + item.kendala + '</p>';
                    if (item.tindak_lanjut) html += '    <p class="mb-0 text-success small"><strong>Tindak Lanjut:</strong> ' + item.tindak_lanjut + '</p>';
                    html += '  </div>';
                    html += '</div>';
                });
                html += '</div>';
                $('#timelineContainer').html(html);
            }
        });

        $('#modalDetailPerkembangan').modal('show');
    });

    $(document).on('click', '.btn-hapus-catatan', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        Swal.fire({
            title: 'Hapus Catatan?',
            text: 'Catatan perkembangan untuk ' + nama + ' akan dihapus.',
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

// AJAX Timeline endpoint handler
if (isset($_GET['ajax_timeline']) && (int)$_GET['ajax_timeline'] === 1) {
    header('Content-Type: application/json; charset=UTF-8');
    $sid = (int)($_GET['id_siswa'] ?? 0);
    $stTimeline = $pdo->prepare("
        SELECT id, tanggal, kategori, ringkasan, kendala, tindak_lanjut, status
        FROM tb_catatan_perkembangan
        WHERE id_siswa = ? AND id_guru = ?
        ORDER BY tanggal DESC, id DESC
    ");
    $stTimeline->execute([$sid, $guru_id]);
    $t_rows = $stTimeline->fetchAll(PDO::FETCH_ASSOC);
    foreach ($t_rows as &$item) {
        $item['tanggal_formatted'] = date('d F Y', strtotime($item['tanggal']));
    }
    unset($item);
    echo json_encode($t_rows);
    exit;
}

if (!empty($message)) {
    $js_page[] = "Swal.fire({ icon: '" . ($message['type'] === 'danger' ? 'error' : $message['type']) . "', title: '" . ($message['type'] === 'success' ? 'Berhasil' : 'Perhatian') . "', text: " . json_encode($message['text']) . ", timer: 2200, showConfirmButton: false });";
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Daftar Catatan Perkembangan Siswa</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <!-- Filter Card -->
            <div class="card mb-3">
                <div class="card-header">
                    <h4><i class="fas fa-filter mr-2"></i>Filter Perkembangan</h4>
                </div>
                <div class="card-body">
                    <form method="GET" class="row">
                        <div class="col-md-3 mb-2">
                            <label class="small font-weight-bold">Kategori</label>
                            <select name="f_kategori" class="form-control form-control-sm">
                                <option value="">-- Semua Kategori --</option>
                                <?php foreach ($kategori_options as $k): ?>
                                    <option value="<?= $k ?>" <?= $f_kategori === $k ? 'selected' : '' ?>><?= $k ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <label class="small font-weight-bold">Kelas</label>
                            <select name="f_kelas" class="form-control form-control-sm">
                                <option value="">-- Semua Kelas --</option>
                                <?php foreach ($kelas_list as $k): ?>
                                    <option value="<?= (int)$k['id_kelas'] ?>" <?= $f_kelas === (int)$k['id_kelas'] ? 'selected' : '' ?>><?= htmlspecialchars($k['nama_kelas']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2">
                            <label class="small font-weight-bold">Mata Pelajaran</label>
                            <select name="f_mapel" class="form-control form-control-sm">
                                <option value="">-- Semua Mapel --</option>
                                <?php foreach ($mapel_list as $m): ?>
                                    <option value="<?= (int)$m['id_mapel'] ?>" <?= $f_mapel === (int)$m['id_mapel'] ? 'selected' : '' ?>><?= htmlspecialchars($m['nama_mapel']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <label class="small font-weight-bold">Status</label>
                            <select name="f_status" class="form-control form-control-sm">
                                <option value="">-- Semua --</option>
                                <?php foreach (['Aktif', 'Dalam Pemantauan', 'Selesai'] as $st): ?>
                                    <option value="<?= $st ?>" <?= $f_status === $st ? 'selected' : '' ?>><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mt-2 d-flex">
                            <button type="submit" class="btn btn-primary btn-sm mr-2"><i class="fas fa-search"></i> Terapkan Filter</button>
                            <a href="catatan_perkembangan.php" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i> Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Table Card -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4>Log Perkembangan Belajar & Karakter</h4>
                    <button type="button" class="btn btn-primary" id="btnTambahCatatan">
                        <i class="fas fa-plus mr-1"></i> Catat Perkembangan
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered table-sm" id="table-catatan">
                            <thead>
                                <tr>
                                    <th width="4%">No</th>
                                    <th>Tanggal</th>
                                    <th>Nama Siswa</th>
                                    <th>NIS/NISN</th>
                                    <th>Kelas</th>
                                    <th>Mata Pelajaran</th>
                                    <th>Kategori</th>
                                    <th width="20%">Ringkasan Perkembangan</th>
                                    <th>Kendala</th>
                                    <th>Tindak Lanjut</th>
                                    <th>Status</th>
                                    <th width="12%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $i => $r): ?>
                                    <?php
                                    $raw_ringkas = strip_tags($r['ringkasan']);
                                    $ringkasan_tampil = mb_strlen($raw_ringkas) > 50 ? mb_substr($raw_ringkas, 0, 50) . '...' : $raw_ringkas;

                                    $raw_kendala = strip_tags($r['kendala'] ?? '');
                                    $kendala_tampil = mb_strlen($raw_kendala) > 35 ? mb_substr($raw_kendala, 0, 35) . '...' : ($raw_kendala ?: '-');

                                    $raw_tl = strip_tags($r['tindak_lanjut'] ?? '');
                                    $tl_tampil = mb_strlen($raw_tl) > 35 ? mb_substr($raw_tl, 0, 35) . '...' : ($raw_tl ?: '-');

                                    $st_badge = $r['status'] === 'Selesai' ? 'success' : ($r['status'] === 'Dalam Pemantauan' ? 'warning' : 'primary');
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                                        <td><strong><?= htmlspecialchars($r['nama_siswa']) ?></strong></td>
                                        <td><?= htmlspecialchars($r['nisn'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['nama_kelas'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['nama_mapel'] ?? '-') ?></td>
                                        <td><span class="badge badge-info"><?= htmlspecialchars($r['kategori']) ?></span></td>
                                        <td>
                                            <span title="<?= htmlspecialchars($raw_ringkas) ?>">
                                                <?= htmlspecialchars($ringkasan_tampil) ?>
                                            </span>
                                        </td>
                                        <td><small class="text-danger"><?= htmlspecialchars($kendala_tampil) ?></small></td>
                                        <td><small class="text-success"><?= htmlspecialchars($tl_tampil) ?></small></td>
                                        <td class="text-center">
                                            <span class="badge badge-<?= $st_badge ?>"><?= htmlspecialchars($r['status']) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-info btn-sm btn-detail-catatan" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Detail & Timeline">
                                                <i class="fas fa-stream"></i>
                                            </button>
                                            <button type="button" class="btn btn-warning btn-sm btn-edit-catatan" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm btn-hapus-catatan" data-id="<?= (int)$r['id'] ?>" data-nama="<?= htmlspecialchars($r['nama_siswa'], ENT_QUOTES) ?>" title="Hapus">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
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

<!-- Modal Form Tambah / Edit Catatan -->
<div class="modal fade" id="modalCatatan" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formCatatan">
                <input type="hidden" name="action" id="formCatatanAction" value="tambah">
                <input type="hidden" name="id" id="catatanId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalCatatanTitle">Catat Perkembangan Siswa</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Pilih Siswa <span class="text-danger">*</span></label>
                            <select name="id_siswa" id="inp_siswa" class="form-control select2-siswa" required>
                                <option value="">-- Cari Siswa --</option>
                                <?php foreach ($siswa_list as $s): ?>
                                    <option value="<?= (int)$s['id_siswa'] ?>">
                                        <?= htmlspecialchars($s['nama_siswa']) ?> (<?= htmlspecialchars($s['nama_kelas'] ?? '-') ?> / <?= htmlspecialchars($s['nisn'] ?? '-') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Tanggal</label>
                            <input type="date" name="tanggal" id="inp_tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Kategori</label>
                            <select name="kategori" id="inp_kategori" class="form-control">
                                <?php foreach ($kategori_options as $k): ?>
                                    <option value="<?= $k ?>"><?= $k ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Mata Pelajaran (Opsional)</label>
                            <select name="id_mapel" id="inp_mapel" class="form-control">
                                <option value="">-- Umum / Tanpa Mapel --</option>
                                <?php foreach ($mapel_list as $m): ?>
                                    <option value="<?= (int)$m['id_mapel'] ?>"><?= htmlspecialchars($m['nama_mapel']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Kelas</label>
                            <select name="id_kelas" id="inp_kelas" class="form-control">
                                <option value="">-- Sesuai Kelas Siswa --</option>
                                <?php foreach ($kelas_list as $k): ?>
                                    <option value="<?= (int)$k['id_kelas'] ?>"><?= htmlspecialchars($k['nama_kelas']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Status Pemantauan</label>
                            <select name="status" id="inp_status" class="form-control">
                                <option value="Aktif">Aktif</option>
                                <option value="Dalam Pemantauan">Dalam Pemantauan</option>
                                <option value="Selesai">Selesai</option>
                            </select>
                        </div>

                        <div class="col-12 form-group">
                            <label>Ringkasan Perkembangan <span class="text-danger">*</span></label>
                            <textarea name="ringkasan" id="inp_ringkasan" class="form-control" rows="2" required placeholder="Tuliskan intisari capaian atau pengamatan perkembangan siswa..."></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Kendala Belajar / Hambatan</label>
                            <textarea name="kendala" id="inp_kendala" class="form-control" rows="2" placeholder="Kesulitan atau hambatan yang dihadapi siswa..."></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Tindak Lanjut yang Dilakukan</label>
                            <textarea name="tindak_lanjut" id="inp_tindak_lanjut" class="form-control" rows="2" placeholder="Langkah pembimbingan atau latihan tambahan..."></textarea>
                        </div>

                        <!-- Aspek Detail (Fitur 8) -->
                        <div class="col-12"><h6 class="text-primary border-bottom pb-1">Aspek Pengamatan Detail (Opsional)</h6></div>
                        <div class="col-md-6 form-group">
                            <label>Perkembangan Akademik</label>
                            <textarea name="perkembangan_akademik" id="inp_akademik" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Perkembangan Sikap / Karakter</label>
                            <textarea name="perkembangan_sikap" id="inp_sikap" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Perkembangan Keterampilan</label>
                            <textarea name="perkembangan_keterampilan" id="inp_keterampilan" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Keaktifan Siswa</label>
                            <textarea name="keaktifan" id="inp_keaktifan" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Bakat / Potensi Khusus</label>
                            <textarea name="potensi" id="inp_potensi" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Rekomendasi / Catatan Guru</label>
                            <textarea name="rekomendasi" id="inp_rekomendasi" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan Catatan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Detail & Timeline Perkembangan Siswa (Fitur 8) -->
<div class="modal fade" id="modalDetailPerkembangan" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-id-card-alt mr-2"></i>Detail & Timeline Perkembangan Siswa</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <!-- Identitas -->
                <div class="card bg-light mb-3">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-md-3"><strong>Nama Siswa:</strong> <div id="det_nama" class="text-primary font-weight-bold"></div></div>
                            <div class="col-md-3"><strong>NIS/NISN:</strong> <div id="det_nisn"></div></div>
                            <div class="col-md-3"><strong>Kelas:</strong> <div id="det_kelas"></div></div>
                            <div class="col-md-3"><strong>Mata Pelajaran:</strong> <div id="det_mapel"></div></div>
                        </div>
                    </div>
                </div>

                <!-- Nav Tabs: Rincian Catatan vs Timeline -->
                <ul class="nav nav-tabs" id="myTab" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="tab-rincian-link" data-toggle="tab" href="#tab-rincian" role="tab">Rincian Catatan Ini</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="tab-timeline-link" data-toggle="tab" href="#tab-timeline" role="tab">Timeline Perkembangan Siswa</a>
                    </li>
                </ul>
                <div class="tab-content pt-3" id="myTabContent">
                    <div class="tab-pane fade show active" id="tab-rincian" role="tabpanel">
                        <table class="table table-bordered table-sm">
                            <tr><th width="28%">Tanggal</th><td id="det_tanggal"></td></tr>
                            <tr><th>Kategori</th><td id="det_kategori"></td></tr>
                            <tr><th>Status</th><td id="det_status"></td></tr>
                            <tr><th>Ringkasan</th><td id="det_ringkasan" class="font-weight-bold"></td></tr>
                            <tr><th>Kendala</th><td id="det_kendala" class="text-danger"></td></tr>
                            <tr><th>Tindak Lanjut</th><td id="det_tindak_lanjut" class="text-success"></td></tr>
                            <tr><th>Perkembangan Akademik</th><td id="det_akademik"></td></tr>
                            <tr><th>Perkembangan Sikap</th><td id="det_sikap"></td></tr>
                            <tr><th>Perkembangan Keterampilan</th><td id="det_keterampilan"></td></tr>
                            <tr><th>Keaktifan</th><td id="det_keaktifan"></td></tr>
                            <tr><th>Potensi</th><td id="det_potensi"></td></tr>
                            <tr><th>Catatan Guru</th><td id="det_catatan_guru"></td></tr>
                            <tr><th>Rekomendasi</th><td id="det_rekomendasi"></td></tr>
                        </table>
                    </div>
                    <div class="tab-pane fade" id="tab-timeline" role="tabpanel">
                        <div id="timelineContainer"></div>
                    </div>
                </div>
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
