<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['wali', 'guru', 'admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$is_admin_or_kepala = in_array($user_level, ['admin', 'kepala_madrasah'], true) || in_array($_GET['session_type'] ?? '', ['admin', 'kepala_madrasah'], true);

$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$message = null;

// Handle CRUD Master Data Projek (Nama Projek, Tema, Tujuan)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $nama_projek = trim((string)($_POST['nama_projek'] ?? ''));
        $tema = trim((string)($_POST['tema'] ?? ''));
        $tujuan = trim((string)($_POST['tujuan'] ?? ''));

        if ($nama_projek === '' || $tema === '') {
            $message = ['type' => 'warning', 'text' => 'Nama Projek dan Tema wajib diisi.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $st = $pdo->prepare("INSERT INTO tb_data_projek (nama_projek, tema, tujuan, is_template) VALUES (?, ?, ?, 0)");
                    $st->execute([$nama_projek, $tema, $tujuan]);
                    $message = ['type' => 'success', 'text' => 'Data projek berhasil ditambahkan.'];
                } else {
                    $st = $pdo->prepare("UPDATE tb_data_projek SET nama_projek = ?, tema = ?, tujuan = ? WHERE id = ?");
                    $st->execute([$nama_projek, $tema, $tujuan, $id]);
                    $message = ['type' => 'success', 'text' => 'Data projek berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan data projek: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM tb_data_projek WHERE id = ?")->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Data projek berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus data projek: ' . $e->getMessage()];
        }
    }
}

// Ambil daftar data projek (Master)
$rows = $pdo->query("
    SELECT * FROM tb_data_projek
    ORDER BY nama_projek ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Daftar tema standar P5 / PPRA Kurikulum Merdeka
$standar_tema = [
    'Gaya Hidup Berkelanjutan',
    'Kearifan Lokal',
    'Bhinneka Tunggal Ika',
    'Bangunlah Jiwa dan Raganya',
    'Suara Demokrasi',
    'Rekayasa dan Teknologi',
    'Kewirausahaan',
    'Kebekerjaan',
    // Nilai Delapan Dimensi Profil Lulusan
    'Keimanan dan Ketakwaan kepada Tuhan YME',
    'Kewargaan',
    'Penalaran Kritis',
    'Kreativitas',
    'Kolaborasi',
    'Kemandirian',
    'Kesehatan',
    'Komunikasi',
    // Nilai Kurikulum Berbasis Cinta (KBC)
    'Cinta kepada Allah dan Rasul-Nya',
    'Cinta kepada Ilmu',
    'Cinta kepada Diri Sendiri',
    'Cinta kepada Sesama Manusia',
    'Cinta kepada Lingkungan',
    'Cinta kepada Tanah Air',
    'Cinta kepada Kedamaian',
];

if (!function_exists('getMasterProjekTemaStyle')) {
    function getMasterProjekTemaStyle(string $tema): array {
        $t = strtolower(trim($tema));
        $dpl_dimensi = [
            'keimanan dan ketakwaan kepada tuhan yme', 'kewargaan', 'penalaran kritis',
            'kreativitas', 'kolaborasi', 'kemandirian', 'kesehatan', 'komunikasi',
        ];
        if (strpos($t, 'cinta') !== false) {
            return ['bg' => '#ffe4e6', 'color' => '#be123c', 'icon' => 'bi bi-heart-fill'];
        } elseif (in_array($t, $dpl_dimensi, true)) {
            return ['bg' => '#ede9fe', 'color' => '#6d28d9', 'icon' => 'bi bi-mortarboard-fill'];
        } elseif (strpos($t, 'hidup') !== false || strpos($t, 'lingkungan') !== false || strpos($t, 'alam') !== false) {
            return ['bg' => '#dcfce7', 'color' => '#15803d', 'icon' => 'bi bi-tree-fill'];
        } elseif (strpos($t, 'kearifan') !== false || strpos($t, 'budaya') !== false) {
            return ['bg' => '#fef3c7', 'color' => '#b45309', 'icon' => 'bi bi-flower1'];
        } elseif (strpos($t, 'teknologi') !== false || strpos($t, 'rekayasa') !== false) {
            return ['bg' => '#e0f2fe', 'color' => '#0284c7', 'icon' => 'bi bi-cpu-fill'];
        } elseif (strpos($t, 'wirausaha') !== false || strpos($t, 'bisnis') !== false || strpos($t, 'kerja') !== false) {
            return ['bg' => '#ccfbf1', 'color' => '#0f766e', 'icon' => 'bi bi-shop-window'];
        } elseif (strpos($t, 'bhinneka') !== false || strpos($t, 'demokrasi') !== false) {
            return ['bg' => '#f3e8ff', 'color' => '#7e22ce', 'icon' => 'bi bi-people-fill'];
        } elseif (strpos($t, 'jiwa') !== false || strpos($t, 'kesehatan') !== false || strpos($t, 'raga') !== false) {
            return ['bg' => '#fee2e2', 'color' => '#b91c1c', 'icon' => 'bi bi-heart-pulse-fill'];
        }
        return ['bg' => '#f0fdf4', 'color' => '#16a34a', 'icon' => 'bi bi-journal-code'];
    }
}

$page_title = 'Data Projek Kokurikuler';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-master-projek').length) {
        $('#table-master-projek').DataTable({
            'columnDefs': [{ 'orderable': false, 'targets': [4] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Belum ada data projek',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    $('#btnTambahDataProjek').on('click', function() {
        $('#formDataProjekAction').val('tambah');
        $('#dataProjekId').val('');
        $('#modalDataProjekTitle').text('Tambah Data Projek Kokurikuler');
        $('#formDataProjek')[0].reset();
        $('#modalDataProjek').modal('show');
    });

    $(document).on('click', '.btn-edit-data-projek', function() {
        var data = $(this).data('json');
        if (typeof data === 'string') {
            try { data = JSON.parse(data); } catch (e) {}
        }
        $('#formDataProjekAction').val('edit');
        $('#dataProjekId').val(data.id);
        $('#modalDataProjekTitle').text('Edit Data Projek Kokurikuler');
        $('#inp_dp_nama').val(data.nama_projek);
        $('#inp_dp_tema').val(data.tema);
        $('#inp_dp_tujuan').val(data.tujuan || '');
        $('#modalDataProjek').modal('show');
    });

    $(document).on('click', '.btn-hapus-data-projek', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        Swal.fire({
            title: 'Hapus Data Projek?',
            text: 'Data projek "' + nama + '" akan dihapus dari daftar.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Hapus',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#formHapusDataId').val(id);
                $('#formHapusData').submit();
            }
        });
    });

    $(document).on('click', '.btn-quick-tema', function() {
        $('#inp_dp_tema').val($(this).data('tema'));
    });

    // Auto-tinggi textarea Tujuan agar teks panjang tidak terpotong
    function simadAutoGrowTujuan() {
        var el = document.getElementById('inp_dp_tujuan');
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = Math.max(180, el.scrollHeight + 2) + 'px';
    }
    $(document).on('input', '#inp_dp_tujuan', simadAutoGrowTujuan);
    $('#modalDataProjek').on('shown.bs.modal', simadAutoGrowTujuan);
});
JS
];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<style>
.dp-tema-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11.5px;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 999px;
    line-height: 1.2;
    white-space: nowrap;
}
#table-master-projek td {
    vertical-align: middle;
}
.dp-nama-projek {
    font-weight: 700;
    color: #0f172a;
}
.dp-tujuan-cell {
    max-width: 420px;
    font-size: 13px;
    color: #334155;
    line-height: 1.5;
}
.dp-btn-act {
    width: 34px;
    height: 34px;
    border-radius: 11px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: none !important;
    outline: none !important;
    cursor: pointer;
    font-size: 13.5px;
    transition: transform 0.15s ease, background-color 0.15s ease;
}
.dp-btn-act:active { transform: scale(0.92); }
.dp-btn-edit { background: #fef3c7; color: #b45309; }
.dp-btn-edit:hover { background: #fde68a; color: #92400e; }
.dp-btn-del { background: #fee2e2; color: #b91c1c; }
.dp-btn-del:hover { background: #fecaca; color: #991b1b; }

.quick-tema-tag {
    cursor: pointer;
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    padding: 3px 10px;
    display: inline-block;
    margin: 2px 2px;
    transition: all 0.15s ease;
}
.quick-tema-tag:hover {
    background: #e0f2fe;
    color: #0284c7;
    border-color: #bae6fd;
}
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Data Projek Kokurikuler</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <?php if ($message): ?>
                <div class="alert alert-<?= $message['type'] ?> alert-dismissible show fade">
                    <div class="alert-body">
                        <button class="close" data-dismiss="alert"><span>&times;</span></button>
                        <?= htmlspecialchars($message['text']) ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card card-primary">
                <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <h4 class="mb-0">Daftar Data Projek Kokurikuler</h4>
                        <small class="text-muted">Daftar induk projek &mdash; dipakai guru saat membuat projek pada menu Projek / Kokurikuler.</small>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm mt-2 mt-sm-0" id="btnTambahDataProjek" style="border-radius: 8px;">
                        <i class="bi bi-plus-circle mr-1"></i> Tambah Data Projek
                    </button>
                </div>

                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover" id="table-master-projek" style="width:100%;">
                            <thead>
                                <tr>
                                    <th style="width: 45px;" class="text-center">No</th>
                                    <th>Nama Projek</th>
                                    <th style="width: 200px;">Tema</th>
                                    <th>Tujuan</th>
                                    <th style="width: 110px;" class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; foreach ($rows as $r): ?>
                                    <?php $ts = getMasterProjekTemaStyle($r['tema'] ?? ''); ?>
                                    <tr>
                                        <td class="text-center font-weight-bold text-muted"><?= $no++ ?></td>
                                        <td>
                                            <div class="dp-nama-projek">
                                                <i class="<?= $ts['icon'] ?> mr-1" style="color: <?= $ts['color'] ?>;"></i>
                                                <?= htmlspecialchars($r['nama_projek']) ?>
                                                <?php if (!empty($r['is_template'])): ?>
                                                    <span class="badge badge-light border ml-1" style="font-size: 10px; font-weight: 600; color: #64748b; vertical-align: middle;">
                                                        <i class="bi bi-bookmark-star-fill text-warning"></i> Template
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="dp-tema-badge" style="background: <?= $ts['bg'] ?>; color: <?= $ts['color'] ?>;">
                                                <?= htmlspecialchars($r['tema']) ?>
                                            </span>
                                        </td>
                                        <td class="dp-tujuan-cell">
                                            <?php if (!empty($r['tujuan'])): ?>
                                                <?= nl2br(htmlspecialchars($r['tujuan'])) ?>
                                            <?php else: ?>
                                                <span class="text-muted font-italic small">Belum ada tujuan</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="dp-btn-act dp-btn-edit btn-edit-data-projek"
                                                data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>'
                                                title="Edit Data Projek">
                                                <i class="bi bi-pencil-fill"></i>
                                            </button>
                                            <button type="button" class="dp-btn-act dp-btn-del btn-hapus-data-projek ml-1"
                                                data-id="<?= (int)$r['id'] ?>"
                                                data-nama="<?= htmlspecialchars($r['nama_projek'], ENT_QUOTES) ?>"
                                                title="Hapus Data Projek">
                                                <i class="bi bi-trash-fill"></i>
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

<!-- Modal Form Tambah / Edit Data Projek -->
<div class="modal fade" id="modalDataProjek" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" style="border-radius: 16px; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
            <form method="POST" id="formDataProjek">
                <input type="hidden" name="action" id="formDataProjekAction" value="tambah">
                <input type="hidden" name="id" id="dataProjekId" value="">
                <div class="modal-header" style="border-bottom: 1px solid #e2e8f0; padding: 16px 20px;">
                    <h5 class="modal-title font-weight-bold" id="modalDataProjekTitle"><i class="bi bi-journal-plus mr-2 text-primary"></i>Data Projek Kokurikuler</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body" style="padding: 20px;">
                    <div class="alert alert-light border mb-3 py-2 px-3" style="border-radius: 10px; font-size: 13px;">
                        <i class="bi bi-info-circle text-primary mr-1"></i>
                        Isi <strong>Nama Projek</strong>, <strong>Tema</strong>, dan <strong>Tujuan</strong>. Data ini menjadi pilihan saat guru membuat projek pada menu Projek / Kokurikuler.
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Nama Projek <span class="text-danger">*</span></label>
                        <input type="text" name="nama_projek" id="inp_dp_nama" class="form-control" required placeholder="Contoh: Pembuatan Sabun Alami / Budidaya Sayuran Hidroponik">
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Tema Projek Kokurikuler <span class="text-danger">*</span></label>
                        <input type="text" name="tema" id="inp_dp_tema" list="listTemaP5" class="form-control" required placeholder="Pilih atau ketik tema projek">
                        <datalist id="listTemaP5">
                            <?php foreach ($standar_tema as $st): ?>
                                <option value="<?= htmlspecialchars($st) ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <div class="mt-2">
                            <span class="small text-muted d-block mb-1">Pilihan Cepat Tema:</span>
                            <div class="d-flex flex-wrap">
                                <?php foreach ($standar_tema as $st): ?>
                                    <span class="quick-tema-tag btn-quick-tema" data-tema="<?= htmlspecialchars($st) ?>">
                                        <?= htmlspecialchars($st) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="form-group mb-2">
                        <label class="font-weight-bold text-dark">Tujuan Projek</label>
                        <textarea name="tujuan" id="inp_dp_tujuan" class="form-control" rows="8" style="min-height: 180px; resize: vertical;" placeholder="Tuliskan tujuan, dimensi profil lulusan yang dikembangkan, atau hasil yang diharapkan..."></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid #e2e8f0; background: #f8fafc; padding: 12px 20px;">
                    <button type="button" class="btn btn-secondary btn-sm px-3" data-dismiss="modal" style="border-radius: 8px;">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm px-4" style="border-radius: 8px;">
                        <i class="bi bi-save mr-1"></i> Simpan Data Projek
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<form method="POST" id="formHapusData" class="d-none">
    <input type="hidden" name="action" value="hapus">
    <input type="hidden" name="id" id="formHapusDataId">
</form>

<?php include '../templates/footer.php'; ?>
