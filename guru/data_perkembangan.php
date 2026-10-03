<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['guru', 'wali', 'admin', 'tata_usaha', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$message = null;

// Handle CRUD Master Perkembangan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $aspek = trim((string)($_POST['aspek'] ?? ''));
        $kendala = trim((string)($_POST['kendala'] ?? ''));
        $tindak_lanjut = trim((string)($_POST['tindak_lanjut'] ?? ''));
        $ringkasan = trim((string)($_POST['ringkasan'] ?? ''));
        $perkembangan_akademik = trim((string)($_POST['perkembangan_akademik'] ?? ''));
        $perkembangan_sikap = trim((string)($_POST['perkembangan_sikap'] ?? ''));
        $perkembangan_keterampilan = trim((string)($_POST['perkembangan_keterampilan'] ?? ''));
        $keaktifan = trim((string)($_POST['keaktifan'] ?? ''));
        $potensi = trim((string)($_POST['potensi'] ?? ''));
        $rekomendasi = trim((string)($_POST['rekomendasi'] ?? ''));

        if ($aspek === '' || $kendala === '' || $tindak_lanjut === '') {
            $message = ['type' => 'warning', 'text' => 'Aspek perkembangan, kendala, dan tindak lanjut wajib diisi.'];
        } else {
            // Jika ringkasan kosong, susun otomatis
            if ($ringkasan === '') {
                $ringkasan = "Pada aspek " . $aspek . ", peserta didik menghadapi kendala: " . $kendala . ". Dilakukan tindak lanjut: " . $tindak_lanjut . ".";
            }

            try {
                if ($action === 'tambah') {
                    $st = $pdo->prepare("
                        INSERT INTO tb_master_perkembangan (id_guru, aspek, kendala, tindak_lanjut, ringkasan, perkembangan_akademik, perkembangan_sikap, perkembangan_keterampilan, keaktifan, potensi, rekomendasi)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $st->execute([$guru_id, $aspek, $kendala, $tindak_lanjut, $ringkasan, $perkembangan_akademik, $perkembangan_sikap, $perkembangan_keterampilan, $keaktifan, $potensi, $rekomendasi]);
                    $message = ['type' => 'success', 'text' => 'Data pemetaan perkembangan berhasil ditambahkan.'];
                } else {
                    // Hanya izinkan edit jika milik guru tersebut atau dibuat sistem (dan diizinkan)
                    $st = $pdo->prepare("
                        UPDATE tb_master_perkembangan SET
                            aspek = ?, kendala = ?, tindak_lanjut = ?, ringkasan = ?,
                            perkembangan_akademik = ?, perkembangan_sikap = ?, perkembangan_keterampilan = ?,
                            keaktifan = ?, potensi = ?, rekomendasi = ?
                        WHERE id = ? AND (id_guru = ? OR id_guru IS NULL OR id_guru = 0)
                    ");
                    $st->execute([$aspek, $kendala, $tindak_lanjut, $ringkasan, $perkembangan_akademik, $perkembangan_sikap, $perkembangan_keterampilan, $keaktifan, $potensi, $rekomendasi, $id, $guru_id]);
                    $message = ['type' => 'success', 'text' => 'Data pemetaan perkembangan berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $st = $pdo->prepare("DELETE FROM tb_master_perkembangan WHERE id = ? AND (id_guru = ? OR id_guru IS NULL OR id_guru = 0)");
            $st->execute([$id, $guru_id]);
            $message = ['type' => 'success', 'text' => 'Data pemetaan perkembangan berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
}

// Master Aspek unik untuk filter & select
$aspek_list = $pdo->query("SELECT DISTINCT aspek FROM tb_master_perkembangan ORDER BY aspek ASC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($aspek_list)) {
    $aspek_list = ['Akademik', 'Sikap & Karakter', 'Keterampilan', 'Kedisiplinan', 'Ibadah & Spiritual', 'Sosial Emosional', 'Keaktifan & Partisipasi', 'Potensi & Minat'];
}

// Filter
$f_aspek = trim((string)($_GET['f_aspek'] ?? ''));
$where = ["(id_guru = ? OR id_guru IS NULL OR id_guru = 0)"];
$params = [$guru_id];

if ($f_aspek !== '') {
    $where[] = "aspek = ?";
    $params[] = $f_aspek;
}

$where_sql = implode(' AND ', $where);
$st = $pdo->prepare("
    SELECT * FROM tb_master_perkembangan
    WHERE $where_sql
    ORDER BY aspek ASC, id ASC
");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Data Perkembangan Siswa';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-master').length) {
        $('#table-master').DataTable({
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada data pemetaan perkembangan',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    $('#btnTambahMaster').on('click', function() {
        $('#formMasterAction').val('tambah');
        $('#masterId').val('');
        $('#modalMasterTitle').text('Tambah Pemetaan Perkembangan');
        $('#formMaster')[0].reset();
        $('#modalMaster').modal('show');
    });

    $(document).on('click', '.btn-edit-master', function() {
        var data = $(this).data('json');
        $('#formMasterAction').val('edit');
        $('#masterId').val(data.id);
        $('#modalMasterTitle').text('Edit Pemetaan Perkembangan');
        $('#inp_aspek').val(data.aspek);
        $('#inp_kendala').val(data.kendala);
        $('#inp_tindak_lanjut').val(data.tindak_lanjut);
        $('#inp_ringkasan').val(data.ringkasan);
        $('#inp_d_akademik').val(data.perkembangan_akademik || '');
        $('#inp_d_sikap').val(data.perkembangan_sikap || '');
        $('#inp_d_keterampilan').val(data.perkembangan_keterampilan || '');
        $('#inp_d_keaktifan').val(data.keaktifan || '');
        $('#inp_d_potensi').val(data.potensi || '');
        $('#inp_d_rekomendasi').val(data.rekomendasi || '');
        $('#modalMaster').modal('show');
    });

    $(document).on('click', '.btn-hapus-master', function() {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Hapus Pemetaan?',
            text: 'Data template pemetaan perkembangan ini akan dihapus.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#formHapusId').val(id);
                $('#formHapusMaster').submit();
            }
        });
    });

    // Auto-generate ringkasan di form modal
    $('#btnAutoRingkasan').on('click', function() {
        var asp = $('#inp_aspek').val() || 'perkembangan';
        var ken = $('#inp_kendala').val() || '';
        var tl = $('#inp_tindak_lanjut').val() || '';
        if (!ken && !tl) {
            Swal.fire({ icon: 'info', title: 'Perhatian', text: 'Isi kendala atau tindak lanjut terlebih dahulu.' });
            return;
        }
        var text = 'Pada aspek ' + asp + ', peserta didik menghadapi kendala: ' + (ken || '-') + '. Tindak lanjut yang dilakukan: ' + (tl || '-') + '.';
        $('#inp_ringkasan').val(text);
    });
});
JS
];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Master Data Perkembangan</h1>
            <div class="section-header-breadcrumb">
                <a href="catatan_perkembangan.php<?= isset($_GET['session_type']) ? '?session_type=' . urlencode($_GET['session_type']) : '' ?>" class="btn btn-outline-primary btn-sm mr-2">
                    <i class="fas fa-clipboard-list mr-1"></i> Catatan Perkembangan Siswa
                </a>
                <button type="button" class="btn btn-primary btn-sm" id="btnTambahMaster">
                    <i class="fas fa-plus mr-1"></i> Tambah Pemetaan
                </button>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $message['type'] ?> alert-dismissible show fade">
                <div class="alert-body">
                    <button class="close" data-dismiss="alert"><span>&times;</span></button>
                    <?= htmlspecialchars($message['text']) ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="section-body">
            <!-- Filter Aspek -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light py-2">
                    <h6 class="mb-0 text-dark"><i class="fas fa-filter mr-1 text-primary"></i> Filter Aspek Perkembangan</h6>
                </div>
                <div class="card-body py-3">
                    <form method="GET" class="form-row align-items-center">
                        <?php if (isset($_GET['session_type'])): ?>
                            <input type="hidden" name="session_type" value="<?= htmlspecialchars($_GET['session_type']) ?>">
                        <?php endif; ?>
                        <div class="col-md-5 mb-2">
                            <select name="f_aspek" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Aspek Perkembangan --</option>
                                <?php foreach ($aspek_list as $a): ?>
                                    <option value="<?= htmlspecialchars($a) ?>" <?= $f_aspek === $a ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($a) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2">
                            <a href="data_perkembangan.php<?= isset($_GET['session_type']) ? '?session_type=' . urlencode($_GET['session_type']) : '' ?>" class="btn btn-secondary btn-sm ml-1"><i class="fas fa-undo mr-1"></i> Reset</a>
                            <small class="text-muted ml-2">Filter otomatis tersubmit saat diubah.</small>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Tabel Data Pemetaan -->
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover" id="table-master" style="width:100%;">
                            <thead class="thead-light text-center">
                                <tr>
                                    <th style="width: 40px;">No</th>
                                    <th style="width: 150px;">Aspek</th>
                                    <th>Kendala Pembelajaran</th>
                                    <th>Tindak Lanjut / Solusi</th>
                                    <th>Ringkasan Terpadu</th>
                                    <th>Akademik</th>
                                    <th>Sikap / Karakter</th>
                                    <th>Keterampilan</th>
                                    <th>Keaktifan</th>
                                    <th>Potensi / Bakat</th>
                                    <th>Rekomendasi</th>
                                    <th style="width: 90px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="text-center align-middle"><?= $no++ ?></td>
                                        <td class="align-middle">
                                            <span class="badge badge-info px-2 py-1 font-weight-bold" style="font-size: 11.5px;">
                                                <?= htmlspecialchars($r['aspek']) ?>
                                            </span>
                                        </td>
                                        <td class="align-middle" style="font-size: 13px; min-width: 180px;">
                                            <?= nl2br(htmlspecialchars($r['kendala'])) ?>
                                        </td>
                                        <td class="align-middle" style="font-size: 13px; min-width: 180px;">
                                            <?= nl2br(htmlspecialchars($r['tindak_lanjut'])) ?>
                                        </td>
                                        <td class="align-middle text-muted" style="font-size: 12.5px; line-height: 1.5; min-width: 200px;">
                                            <?= nl2br(htmlspecialchars($r['ringkasan'])) ?>
                                        </td>
                                        <td class="align-middle" style="font-size: 12.5px; min-width: 170px;"><?= !empty($r['perkembangan_akademik']) ? nl2br(htmlspecialchars($r['perkembangan_akademik'])) : '<span class="text-muted">-</span>' ?></td>
                                        <td class="align-middle" style="font-size: 12.5px; min-width: 170px;"><?= !empty($r['perkembangan_sikap']) ? nl2br(htmlspecialchars($r['perkembangan_sikap'])) : '<span class="text-muted">-</span>' ?></td>
                                        <td class="align-middle" style="font-size: 12.5px; min-width: 170px;"><?= !empty($r['perkembangan_keterampilan']) ? nl2br(htmlspecialchars($r['perkembangan_keterampilan'])) : '<span class="text-muted">-</span>' ?></td>
                                        <td class="align-middle" style="font-size: 12.5px; min-width: 150px;"><?= !empty($r['keaktifan']) ? nl2br(htmlspecialchars($r['keaktifan'])) : '<span class="text-muted">-</span>' ?></td>
                                        <td class="align-middle" style="font-size: 12.5px; min-width: 150px;"><?= !empty($r['potensi']) ? nl2br(htmlspecialchars($r['potensi'])) : '<span class="text-muted">-</span>' ?></td>
                                        <td class="align-middle" style="font-size: 12.5px; min-width: 170px;"><?= !empty($r['rekomendasi']) ? nl2br(htmlspecialchars($r['rekomendasi'])) : '<span class="text-muted">-</span>' ?></td>
                                        <td class="text-center align-middle">
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-warning btn-edit-master" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger btn-hapus-master" data-id="<?= (int)$r['id'] ?>" title="Hapus">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
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

<!-- Modal Tambah / Edit Master Perkembangan -->
<div class="modal fade" id="modalMaster" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formMaster">
                <input type="hidden" name="action" id="formMasterAction" value="tambah">
                <input type="hidden" name="id" id="masterId" value="">
                <div class="modal-header border-bottom py-3">
                    <h5 class="modal-title text-primary" id="modalMasterTitle">Tambah Pemetaan Perkembangan</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body p-3">
                    <div class="form-group">
                        <label class="font-weight-bold">Aspek Perkembangan <span class="text-danger">*</span></label>
                        <input type="text" name="aspek" id="inp_aspek" list="list_aspek" class="form-control" required placeholder="Contoh: Akademik, Sikap & Karakter, Keterampilan...">
                        <datalist id="list_aspek">
                            <?php foreach ($aspek_list as $a): ?>
                                <option value="<?= htmlspecialchars($a) ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <small class="text-muted">Pilih dari daftar atau ketik aspek baru.</small>
                    </div>

                    <style>
                        #modalMaster textarea { min-height: 90px; line-height: 1.55; resize: vertical; overflow-y: auto; }
                    </style>
                    <div class="form-group">
                        <label class="font-weight-bold">Kendala yang Dihadapi Siswa <span class="text-danger">*</span></label>
                        <textarea name="kendala" id="inp_kendala" class="form-control" rows="4" style="min-height:110px;" required placeholder="Tuliskan kendala umum atau hambatan yang sering dihadapi..."></textarea>
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold">Tindak Lanjut / Solusi Pembimbingan <span class="text-danger">*</span></label>
                        <textarea name="tindak_lanjut" id="inp_tindak_lanjut" class="form-control" rows="4" style="min-height:110px;" required placeholder="Langkah penanganan, solusi pedagogis, atau bimbingan guru..."></textarea>
                    </div>

                    <div class="form-group">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="font-weight-bold mb-0">Template Ringkasan Terpadu</label>
                            <button type="button" id="btnAutoRingkasan" class="btn btn-outline-info btn-xs py-1 px-2 font-weight-bold" style="font-size: 11px;">
                                <i class="fas fa-magic mr-1"></i> Susun Otomatis
                            </button>
                        </div>
                        <textarea name="ringkasan" id="inp_ringkasan" class="form-control" rows="5" style="min-height:130px;" placeholder="Kalimat narasi rangkuman yang memadukan capaian, kendala, dan tindak lanjut..."></textarea>
                        <small class="text-muted">Kalimat ini yang otomatis menjadi draf ringkasan saat guru memilih aspek &amp; kendala ini.</small>
                    </div>

                    <h6 class="font-weight-bold text-primary border-bottom pb-1 mt-3">Rincian Aspek Detail (masuk ke modal catatan)</h6>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="small font-weight-bold">Perkembangan Akademik</label>
                            <textarea name="perkembangan_akademik" id="inp_d_akademik" class="form-control" rows="4" style="min-height:110px;"></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="small font-weight-bold">Perkembangan Sikap / Karakter</label>
                            <textarea name="perkembangan_sikap" id="inp_d_sikap" class="form-control" rows="4" style="min-height:110px;"></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="small font-weight-bold">Perkembangan Keterampilan</label>
                            <textarea name="perkembangan_keterampilan" id="inp_d_keterampilan" class="form-control" rows="4" style="min-height:110px;"></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="small font-weight-bold">Keaktifan Siswa</label>
                            <textarea name="keaktifan" id="inp_d_keaktifan" class="form-control" rows="4" style="min-height:110px;"></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="small font-weight-bold">Bakat / Potensi Khusus</label>
                            <textarea name="potensi" id="inp_d_potensi" class="form-control" rows="4" style="min-height:110px;"></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="small font-weight-bold">Rekomendasi / Catatan Guru</label>
                            <textarea name="rekomendasi" id="inp_d_rekomendasi" class="form-control" rows="4" style="min-height:110px;"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save mr-1"></i> Simpan Pemetaan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form method="POST" id="formHapusMaster" class="d-none">
    <input type="hidden" name="action" value="hapus">
    <input type="hidden" name="id" id="formHapusId">
</form>

<?php include '../templates/footer.php'; ?>
