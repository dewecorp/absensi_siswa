<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';
require_once '../config/ai_helper.php';

ensure_learning_schema($pdo);
ai_helper_schema($pdo);

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
        $kode_soal = trim((string)($_POST['kode_soal'] ?? ''));
        $allowed_jenis = ['Pilihan Ganda', 'Pilihan Ganda Kompleks', 'Menjodohkan', 'Isian Singkat', 'Uraian'];
        $jenis_soal = trim((string)($_POST['jenis_soal'] ?? 'Pilihan Ganda'));
        if (!in_array($jenis_soal, $allowed_jenis, true)) {
            $jenis_soal = 'Pilihan Ganda';
        }
        $id_mapel = (int)($_POST['id_mapel'] ?? 0) ?: null;
        $id_kelas = (int)($_POST['id_kelas'] ?? 0) ?: null;
        $kurikulum = in_array($_POST['kurikulum'] ?? '', ['PERMENDIKDASMEN_046', 'KMA_1503_KBC'], true) ? $_POST['kurikulum'] : 'PERMENDIKDASMEN_046';
        $semester = trim((string)($_POST['semester'] ?? ''));
        if ($semester !== '' && !in_array($semester, ['Semester 1', 'Semester 2'], true)) {
            $semester = mb_substr($semester, 0, 20);
        }
        $jenis_asesmen = trim((string)($_POST['jenis_asesmen'] ?? ''));
        if (!in_array($jenis_asesmen, ai_asesmen_list(), true)) {
            $jenis_asesmen = '';
        }
        $topik = trim((string)($_POST['topik'] ?? ''));
        $sub_topik = trim((string)($_POST['sub_topik'] ?? ''));
        $materi_tp = trim((string)($_POST['materi_tp'] ?? ''));
        $indikator = trim((string)($_POST['indikator'] ?? ''));
        $level_kognitif = strtoupper(trim((string)($_POST['level_kognitif'] ?? 'L2')));
        if (!in_array($level_kognitif, ['L1', 'L2', 'L3', 'L4'], true)) {
            $level_kognitif = 'L2';
        }
        // Tanpa input manual: default Sedang; hasil AI menimpa acak otomatis per bentuk.
        $tingkat_kesulitan = 'Sedang';
        $bobot = (float)($_POST['bobot'] ?? 1.00);
        $pertanyaan = trim((string)($_POST['pertanyaan'] ?? ''));
        $jawaban_benar = trim((string)($_POST['jawaban_benar'] ?? ''));
        $pembahasan = trim((string)($_POST['pembahasan'] ?? ''));
        $status = in_array($_POST['status'] ?? '', ['Draft', 'Aktif', 'Arsip'], true) ? $_POST['status'] : 'Aktif';

        // Pilihan jawaban JSON (PG biasa + PG kompleks)
        $pilihan_jawaban = null;
        if ($jenis_soal === 'Pilihan Ganda' || $jenis_soal === 'Pilihan Ganda Kompleks') {
            $opsi = [
                'A' => trim((string)($_POST['opsi_a'] ?? '')),
                'B' => trim((string)($_POST['opsi_b'] ?? '')),
                'C' => trim((string)($_POST['opsi_c'] ?? '')),
                'D' => trim((string)($_POST['opsi_d'] ?? '')),
            ];
            $pilihan_jawaban = json_encode($opsi);
        }

        // Auto generate kode soal jika kosong
        if ($kode_soal === '') {
            $kode_soal = 'SOAL-' . strtoupper(substr(uniqid(), -6));
        }

        if ($pertanyaan === '') {
            $message = ['type' => 'warning', 'text' => 'Pertanyaan soal wajib diisi.'];
        } elseif ($topik === '') {
            $message = ['type' => 'warning', 'text' => 'Topik/pokok bahasan wajib diisi.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_bank_soal (
                            id_guru, kode_soal, jenis_soal, id_mapel, id_kelas, kurikulum, semester, jenis_asesmen, topik, sub_topik, materi_tp,
                            indikator, level_kognitif, tingkat_kesulitan, bobot, pertanyaan, pilihan_jawaban,
                            jawaban_benar, pembahasan, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $guru_id, $kode_soal, $jenis_soal, $id_mapel, $id_kelas, $kurikulum, ($semester !== '' ? $semester : null), ($jenis_asesmen !== '' ? $jenis_asesmen : null), $topik, ($sub_topik !== '' ? $sub_topik : null), $materi_tp,
                        $indikator, $level_kognitif, $tingkat_kesulitan, $bobot, $pertanyaan, $pilihan_jawaban,
                        $jawaban_benar, $pembahasan, $status
                    ]);
                    $message = ['type' => 'success', 'text' => 'Soal berhasil disimpan ke Bank Soal.'];
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE tb_bank_soal SET
                            kode_soal = ?, jenis_soal = ?, id_mapel = ?, id_kelas = ?, kurikulum = ?, semester = ?, jenis_asesmen = ?, topik = ?, sub_topik = ?, materi_tp = ?,
                            indikator = ?, level_kognitif = ?, tingkat_kesulitan = ?, bobot = ?, pertanyaan = ?,
                            pilihan_jawaban = ?, jawaban_benar = ?, pembahasan = ?, status = ?
                        WHERE id = ? AND id_guru = ?
                    ");
                    $stmt->execute([
                        $kode_soal, $jenis_soal, $id_mapel, $id_kelas, $kurikulum, ($semester !== '' ? $semester : null), ($jenis_asesmen !== '' ? $jenis_asesmen : null), $topik, ($sub_topik !== '' ? $sub_topik : null), $materi_tp,
                        $indikator, $level_kognitif, $tingkat_kesulitan, $bobot, $pertanyaan,
                        $pilihan_jawaban, $jawaban_benar, $pembahasan, $status,
                        $id, $guru_id
                    ]);
                    $message = ['type' => 'success', 'text' => 'Soal berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM tb_bank_soal WHERE id = ? AND id_guru = ?");
            $stmt->execute([$id, $guru_id]);
            $message = ['type' => 'success', 'text' => 'Soal berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
}

// Master lists (hanya mapel akademik untuk dropdown; kelas hanya yang diajar guru login)
$mapel_list = getFilteredSubjects($pdo);
$kelas_list = function_exists('getGuruTaughtClasses') ? getGuruTaughtClasses($pdo, $guru_id) : $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
$jenis_soal_options = ['Pilihan Ganda', 'Pilihan Ganda Kompleks', 'Menjodohkan', 'Isian Singkat', 'Uraian'];
$kurikulum_options = ['PERMENDIKDASMEN_046' => 'Permendikdasmen CP 046', 'KMA_1503_KBC' => 'KMA 1503 + KBC'];
$asesmen_options = ai_asesmen_list();
$session_q = isset($_GET['session_type']) ? '?session_type=' . urlencode((string)$_GET['session_type']) : '';

// Filters
$f_mapel = (int)($_GET['f_mapel'] ?? 0);
$f_kelas = (int)($_GET['f_kelas'] ?? 0);
$f_status = trim((string)($_GET['f_status'] ?? ''));
$f_asesmen = trim((string)($_GET['f_asesmen'] ?? ''));

$where = ["b.id_guru = ?"];
$params = [$guru_id];

if ($f_mapel > 0) {
    $where[] = "b.id_mapel = ?";
    $params[] = $f_mapel;
}
if ($f_kelas > 0) {
    $where[] = "b.id_kelas = ?";
    $params[] = $f_kelas;
}
if ($f_status !== '') {
    $where[] = "b.status = ?";
    $params[] = $f_status;
}
if ($f_asesmen !== '') {
    $where[] = "b.jenis_asesmen = ?";
    $params[] = $f_asesmen;
}

$where_sql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT b.*, m.nama_mapel, k.nama_kelas, g.nama_guru
    FROM tb_bank_soal b
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = b.id_mapel
    LEFT JOIN tb_kelas k ON k.id_kelas = b.id_kelas
    LEFT JOIN tb_guru g ON g.id_guru = b.id_guru
    WHERE $where_sql
    ORDER BY b.id DESC
");
$stmt->execute($params);
$soal_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Daftar Bank Soal';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-soal').length) {
        $('#table-soal').DataTable({
            'order': [[0, 'asc']],
            'columnDefs': [{ 'sortable': false, 'targets': [10] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada soal ditemukan',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    function toggleOpsiPG(jenis) {
        if (jenis === 'Pilihan Ganda' || jenis === 'Pilihan Ganda Kompleks') {
            $('#wrapOpsiPG').show();
            if (jenis === 'Pilihan Ganda Kompleks') {
                $('#hintOpsiPG').show();
            } else {
                $('#hintOpsiPG').hide();
            }
        } else {
            $('#wrapOpsiPG').hide();
        }
    }

    $('#inp_jenis').on('change', function() {
        toggleOpsiPG($(this).val());
    });

    $('#btnTambahSoal').on('click', function() {
        $('#formSoalAction').val('tambah');
        $('#soalId').val('');
        $('#modalSoalTitle').text('Tambah Soal Baru');
        $('#formSoal')[0].reset();
        $('#inp_jenis').val('Pilihan Ganda');
        toggleOpsiPG('Pilihan Ganda');
        $('#modalSoal').modal('show');
    });

    $(document).on('click', '.btn-edit-soal', function() {
        var data = $(this).data('json');
        $('#formSoalAction').val('edit');
        $('#soalId').val(data.id);
        $('#modalSoalTitle').text('Edit Soal');
        $('#inp_kode').val(data.kode_soal);
        $('#inp_jenis').val(data.jenis_soal);
        toggleOpsiPG(data.jenis_soal);
        $('#inp_mapel').val(data.id_mapel || '');
        $('#inp_kelas').val(data.id_kelas || '');
        $('#inp_kurikulum').val(data.kurikulum || 'PERMENDIKDASMEN_046');
        $('#inp_semester').val(data.semester || '');
        $('#inp_asesmen').val(data.jenis_asesmen || '');
        $('#inp_topik').val(data.topik || '');
        $('#inp_sub_topik').val(data.sub_topik || '');
        $('#inp_materi_tp').val(data.materi_tp || '');
        $('#inp_indikator').val(data.indikator || '');
        $('#inp_level').val(data.level_kognitif || 'L2');
        $('#inp_bobot').val(data.bobot);
        $('#inp_pertanyaan').val(data.pertanyaan);
        $('#inp_jawaban_benar').val(data.jawaban_benar || '');
        $('#inp_pembahasan').val(data.pembahasan || '');
        $('#inp_status').val(data.status);

        if (data.pilihan_jawaban) {
            try {
                var ops = JSON.parse(data.pilihan_jawaban);
                $('#inp_opsi_a').val(ops.A || '');
                $('#inp_opsi_b').val(ops.B || '');
                $('#inp_opsi_c').val(ops.C || '');
                $('#inp_opsi_d').val(ops.D || '');
            } catch(e) {}
        }
        $('#modalSoal').modal('show');
    });

    // Detail Modal
    $(document).on('click', '.btn-detail-soal', function() {
        var data = $(this).data('json');
        $('#det_kode').text(data.kode_soal);
        $('#det_jenis').text(data.jenis_soal);
        $('#det_mapel').text(data.nama_mapel || '-');
        $('#det_kelas').text(data.nama_kelas || '-');
        $('#det_kurikulum').text(data.kurikulum === 'KMA_1503_KBC' ? 'KMA 1503 + KBC' : 'Permendikdasmen CP 046');
        $('#det_semester').text(data.semester || '-');
        $('#det_asesmen').text(data.jenis_asesmen || '-');
        $('#det_topik').text(data.topik || '-');
        $('#det_sub_topik').text(data.sub_topik || '-');
        $('#det_materi').text(data.materi_tp || '-');
        $('#det_cp').text(data.cp || '-');
        $('#det_tp').text(data.tp || '-');
        $('#det_indikator').text(data.indikator || '-');
        $('#det_level').html('<span class="badge badge-info">' + $('<div>').text(data.level_kognitif || 'L2').html() + '</span>');
        $('#det_bobot').text(data.bobot);
        $('#det_pertanyaan').text(data.pertanyaan);
        $('#det_jawaban_benar').text(data.jawaban_benar || '-');
        $('#det_pembahasan').text(data.pembahasan || '-');
        $('#det_pembuat').text(data.nama_guru || '-');
        $('#det_created').text(data.created_at || '-');

        if ((data.jenis_soal === 'Pilihan Ganda' || data.jenis_soal === 'Pilihan Ganda Kompleks') && data.pilihan_jawaban) {
            try {
                var ops = JSON.parse(data.pilihan_jawaban);
                var htmlOps = '<ul class="pl-3 mb-0">';
                htmlOps += '<li><strong>A.</strong> ' + (ops.A || '-') + '</li>';
                htmlOps += '<li><strong>B.</strong> ' + (ops.B || '-') + '</li>';
                htmlOps += '<li><strong>C.</strong> ' + (ops.C || '-') + '</li>';
                htmlOps += '<li><strong>D.</strong> ' + (ops.D || '-') + '</li>';
                htmlOps += '</ul>';
                $('#det_pilihan').html(htmlOps);
                $('#det_row_pilihan').show();
            } catch(e) {
                $('#det_row_pilihan').hide();
            }
        } else {
            $('#det_row_pilihan').hide();
        }
        $('#modalDetailSoal').modal('show');
    });

    $(document).on('click', '.btn-hapus-soal', function() {
        var id = $(this).data('id');
        var kode = $(this).data('kode');
        Swal.fire({
            title: 'Hapus Soal?',
            text: 'Soal ' + kode + ' akan dihapus dari bank soal.',
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
            <h1>Daftar Bank Soal</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <!-- Filter Card -->
            <div class="card mb-3">
                <div class="card-header">
                    <h4><i class="fas fa-filter mr-2"></i>Filter Soal</h4>
                </div>
                <div class="card-body">
                    <form method="GET" class="row">
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
                            <label class="small font-weight-bold">Kelas</label>
                            <select name="f_kelas" class="form-control form-control-sm">
                                <option value="">-- Semua Kelas --</option>
                                <?php foreach ($kelas_list as $k): ?>
                                    <option value="<?= (int)$k['id_kelas'] ?>" <?= $f_kelas === (int)$k['id_kelas'] ? 'selected' : '' ?>><?= htmlspecialchars($k['nama_kelas']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <label class="small font-weight-bold">Status</label>
                            <select name="f_status" class="form-control form-control-sm">
                                <option value="">-- Semua Status --</option>
                                <?php foreach (['Aktif', 'Draft', 'Arsip'] as $st): ?>
                                    <option value="<?= $st ?>" <?= $f_status === $st ? 'selected' : '' ?>><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2">
                            <label class="small font-weight-bold">Jenis Asesmen</label>
                            <select name="f_asesmen" class="form-control form-control-sm">
                                <option value="">-- Semua Asesmen --</option>
                                <?php foreach ($asesmen_options as $a): ?>
                                    <option value="<?= htmlspecialchars($a) ?>" <?= $f_asesmen === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mt-2 d-flex">
                            <button type="submit" class="btn btn-primary btn-sm mr-2"><i class="fas fa-search"></i> Terapkan Filter</button>
                            <a href="bank_soal.php<?= $session_q ?>" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i> Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Table Card -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4>Koleksi Butir Soal</h4>
                    <div>
                        <a href="generate_soal.php<?= $session_q ?>" class="btn btn-success mr-2">
                            <i class="fas fa-robot mr-1"></i> Generate AI
                        </a>
                        <button type="button" class="btn btn-primary" id="btnTambahSoal">
                            <i class="fas fa-plus mr-1"></i> Tambah Soal
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered table-sm" id="table-soal">
                            <thead>
                                <tr>
                                    <th width="4%">No</th>
                                    <th>Kode Soal</th>
                                    <th width="28%">Potongan Pertanyaan</th>
                                    <th>Jenis Soal</th>
                                    <th>Mata Pelajaran</th>
                                    <th>Kelas</th>
                                    <th>Asesmen</th>
                                    <th>Topik</th>
                                    <th>Bobot</th>
                                    <th>Status</th>
                                    <th width="12%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($soal_rows as $i => $r): ?>
                                    <?php
                                    // Potongan teks (maks 60 karakter)
                                    $raw_pertanyaan = strip_tags($r['pertanyaan']);
                                    $snippet = mb_strlen($raw_pertanyaan) > 60 ? mb_substr($raw_pertanyaan, 0, 60) . '...' : $raw_pertanyaan;

                                    $st_badge = $r['status'] === 'Aktif' ? 'success' : ($r['status'] === 'Draft' ? 'warning' : 'secondary');
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td><strong><?= htmlspecialchars($r['kode_soal']) ?></strong></td>
                                        <td>
                                            <span title="<?= htmlspecialchars($raw_pertanyaan) ?>">
                                                "<?= htmlspecialchars($snippet) ?>"
                                            </span>
                                        </td>
                                        <td><span class="badge badge-light border"><?= htmlspecialchars($r['jenis_soal']) ?></span></td>
                                        <td><?= htmlspecialchars($r['nama_mapel'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['nama_kelas'] ?? '-') ?></td>
                                        <td><span class="badge badge-light border"><?= htmlspecialchars($r['jenis_asesmen'] ?? '-') ?></span></td>
                                        <td><?= htmlspecialchars($r['topik'] ?? ($r['materi_tp'] ?? '-')) ?></td>
                                        <td class="text-center"><?= (float)$r['bobot'] ?></td>
                                        <td class="text-center">
                                            <span class="badge badge-<?= $st_badge ?>"><?= htmlspecialchars($r['status']) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-info btn-sm btn-detail-soal" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Detail Soal">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" class="btn btn-warning btn-sm btn-edit-soal" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm btn-hapus-soal" data-id="<?= (int)$r['id'] ?>" data-kode="<?= htmlspecialchars($r['kode_soal'], ENT_QUOTES) ?>" title="Hapus">
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

<!-- Modal Form Tambah / Edit Soal -->
<div class="modal fade" id="modalSoal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formSoal">
                <input type="hidden" name="action" id="formSoalAction" value="tambah">
                <input type="hidden" name="id" id="soalId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalSoalTitle">Tambah Soal Baru</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label>Kode Soal (Otomatis jika kosong)</label>
                            <input type="text" name="kode_soal" id="inp_kode" class="form-control" placeholder="SOAL-001">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Jenis Soal</label>
                            <select name="jenis_soal" id="inp_jenis" class="form-control">
                                <?php foreach ($jenis_soal_options as $js): ?>
                                    <option value="<?= $js ?>"><?= $js ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Level Kognitif</label>
                            <select name="level_kognitif" id="inp_level" class="form-control">
                                <option value="L1">L1 (C1)</option>
                                <option value="L2" selected>L2 (C2)</option>
                                <option value="L3">L3 (C3-C4)</option>
                                <option value="L4">L4 (C5-C6)</option>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Mata Pelajaran</label>
                            <select name="id_mapel" id="inp_mapel" class="form-control">
                                <option value="">-- Pilih Mapel --</option>
                                <?php foreach ($mapel_list as $m): ?>
                                    <option value="<?= (int)$m['id_mapel'] ?>"><?= htmlspecialchars($m['nama_mapel']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Kelas</label>
                            <select name="id_kelas" id="inp_kelas" class="form-control">
                                <option value="">-- Pilih Kelas --</option>
                                <?php foreach ($kelas_list as $k): ?>
                                    <option value="<?= (int)$k['id_kelas'] ?>"><?= htmlspecialchars($k['nama_kelas']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 form-group">
                            <label>Bobot Soal</label>
                            <input type="number" step="0.1" name="bobot" id="inp_bobot" class="form-control" value="1.0">
                        </div>
                        <div class="col-md-2 form-group">
                            <label>Status</label>
                            <select name="status" id="inp_status" class="form-control">
                                <option value="Aktif">Aktif</option>
                                <option value="Draft">Draft</option>
                                <option value="Arsip">Arsip</option>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Kurikulum</label>
                            <select name="kurikulum" id="inp_kurikulum" class="form-control">
                                <?php foreach ($kurikulum_options as $val => $label): ?>
                                    <option value="<?= $val ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Semester</label>
                            <select name="semester" id="inp_semester" class="form-control">
                                <option value="">-- Pilih Semester --</option>
                                <option value="Semester 1">Semester 1 (Ganjil)</option>
                                <option value="Semester 2">Semester 2 (Genap)</option>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Jenis Asesmen</label>
                            <select name="jenis_asesmen" id="inp_asesmen" class="form-control">
                                <option value="">-- Pilih Asesmen --</option>
                                <?php foreach ($asesmen_options as $a): ?>
                                    <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Topik / Pokok Bahasan <span class="text-danger">*</span></label>
                            <input type="text" name="topik" id="inp_topik" class="form-control" required placeholder="Contoh: Metamorfosis Kupu-kupu">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Sub Topik <span class="text-muted">(opsional)</span></label>
                            <input type="text" name="sub_topik" id="inp_sub_topik" class="form-control" placeholder="Contoh: Tahapan pupa">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Materi <span class="text-muted">(opsional)</span></label>
                            <input type="text" name="materi_tp" id="inp_materi_tp" class="form-control" placeholder="Contoh: Metamorfosis">
                        </div>
                        <div class="col-md-12 form-group">
                            <label>Indikator Soal</label>
                            <input type="text" name="indikator" id="inp_indikator" class="form-control" placeholder="Contoh: Siswa mampu menyebutkan...">
                        </div>
                        <div class="col-12 form-group">
                            <label>Pertanyaan Soal <span class="text-danger">*</span></label>
                            <textarea name="pertanyaan" id="inp_pertanyaan" class="form-control" rows="3" required placeholder="Tuliskan butir pertanyaan secara lengkap..."></textarea>
                        </div>

                        <!-- Opsi Pilihan Ganda -->
                        <div class="col-12" id="wrapOpsiPG">
                            <label class="font-weight-bold">Pilihan Jawaban</label>
                            <small id="hintOpsiPG" class="text-info d-block mb-2" style="display:none;">PG Kompleks: kunci boleh lebih dari satu, pisahkan koma (mis. A,C).</small>
                            <div class="row">
                                <div class="col-md-6 form-group">
                                    <div class="input-group">
                                        <div class="input-group-prepend"><span class="input-group-text font-weight-bold">A</span></div>
                                        <input type="text" name="opsi_a" id="inp_opsi_a" class="form-control" placeholder="Pilihan A">
                                    </div>
                                </div>
                                <div class="col-md-6 form-group">
                                    <div class="input-group">
                                        <div class="input-group-prepend"><span class="input-group-text font-weight-bold">B</span></div>
                                        <input type="text" name="opsi_b" id="inp_opsi_b" class="form-control" placeholder="Pilihan B">
                                    </div>
                                </div>
                                <div class="col-md-6 form-group">
                                    <div class="input-group">
                                        <div class="input-group-prepend"><span class="input-group-text font-weight-bold">C</span></div>
                                        <input type="text" name="opsi_c" id="inp_opsi_c" class="form-control" placeholder="Pilihan C">
                                    </div>
                                </div>
                                <div class="col-md-6 form-group">
                                    <div class="input-group">
                                        <div class="input-group-prepend"><span class="input-group-text font-weight-bold">D</span></div>
                                        <input type="text" name="opsi_d" id="inp_opsi_d" class="form-control" placeholder="Pilihan D">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 form-group">
                            <label>Kunci / Jawaban Benar</label>
                            <input type="text" name="jawaban_benar" id="inp_jawaban_benar" class="form-control" placeholder="Contoh: A (atau teks jawaban benar)">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Pembahasan</label>
                            <textarea name="pembahasan" id="inp_pembahasan" class="form-control" rows="2" placeholder="Ulasan atau pembahasan jawaban..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan Soal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Detail Bank Soal (Fitur 5) -->
<div class="modal fade" id="modalDetailSoal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-question-circle mr-2"></i>Detail Bank Soal</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <table class="table table-bordered table-sm">
                    <tr><th width="28%">Kode Soal</th><td id="det_kode" class="font-weight-bold text-primary"></td></tr>
                    <tr><th>Jenis Soal</th><td id="det_jenis"></td></tr>
                    <tr><th>Mata Pelajaran</th><td id="det_mapel"></td></tr>
                    <tr><th>Kelas</th><td id="det_kelas"></td></tr>
                    <tr><th>Semester</th><td id="det_semester"></td></tr>
                    <tr><th>Kurikulum</th><td id="det_kurikulum"></td></tr>
                    <tr><th>Jenis Asesmen</th><td id="det_asesmen"></td></tr>
                    <tr><th>Topik / Pokok Bahasan</th><td id="det_topik" class="font-weight-bold"></td></tr>
                    <tr><th>Sub Topik</th><td id="det_sub_topik"></td></tr>
                    <tr><th>Materi</th><td id="det_materi"></td></tr>
                    <tr><th>CP</th><td id="det_cp" style="white-space: pre-wrap;"></td></tr>
                    <tr><th>TP</th><td id="det_tp" style="white-space: pre-wrap;"></td></tr>
                    <tr><th>Indikator</th><td id="det_indikator"></td></tr>
                    <tr><th>Level Kognitif</th><td id="det_level"></td></tr>
                    <tr><th>Bobot</th><td id="det_bobot"></td></tr>
                    <tr><th>Pertanyaan</th><td id="det_pertanyaan" style="white-space: pre-wrap;" class="font-weight-bold"></td></tr>
                    <tr id="det_row_pilihan"><th>Pilihan Jawaban</th><td id="det_pilihan"></td></tr>
                    <tr><th>Jawaban Benar</th><td id="det_jawaban_benar" class="font-weight-bold text-success"></td></tr>
                    <tr><th>Pembahasan</th><td id="det_pembahasan" style="white-space: pre-wrap;"></td></tr>
                    <tr><th>Pembuat</th><td id="det_pembuat"></td></tr>
                    <tr><th>Tanggal Dibuat</th><td id="det_created"></td></tr>
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
