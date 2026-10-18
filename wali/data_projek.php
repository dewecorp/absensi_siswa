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
$css_libs = [];
$js_libs = [];

$js_page = [<<<'JS'
$(document).ready(function() {
    // Filter kartu data projek (cari nama / tema)
    function dpApplyFilter() {
        var q = ($('#dpSearch').val() || '').toLowerCase().trim();
        var shown = 0;
        $('.dp-item').each(function() {
            var $i = $(this);
            var hay = ($i.data('nama') || '') + ' ' + ($i.data('tema') || '');
            var ok = (q === '' || hay.indexOf(q) !== -1);
            $i.toggleClass('d-none', !ok);
            if (ok) shown++;
        });
        $('#dpCount').text(shown);
        $('#dpEmpty').toggleClass('d-none', shown !== 0);
    }
    $('#dpSearch').on('input', dpApplyFilter);
    $('#dpSearch').on('keyup', dpApplyFilter);
    dpApplyFilter();

    // Buka/tutup teks tujuan panjang
    $(document).on('click', '.dp-more', function() {
        var $d = $(this).closest('.dp-card-desc');
        $d.toggleClass('expanded');
        if ($d.hasClass('expanded')) {
            $(this).html('Sembunyikan <i class="bi bi-chevron-up"></i>');
        } else {
            $(this).html('Selengkapnya <i class="bi bi-chevron-down"></i>');
        }
    });

    // Tampilkan tombol hanya jika teks benar-benar terpotong
    function dpCheckClamp() {
        $('.dp-card-desc').each(function() {
            var $p = $(this).find('p.dp-tujuan.clamped');
            var $btn = $(this).find('.dp-more');
            if ($p.length && $btn.length) {
                var el = $p[0];
                if (el.scrollHeight > el.clientHeight + 2) {
                    $btn.show();
                } else {
                    $btn.hide();
                }
            }
        });
    }
    dpCheckClamp();
    $(window).on('resize', dpCheckClamp);

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

if (!empty($message)) {
    $swal_icon = $message['type'] === 'danger' ? 'error' : $message['type'];
    $swal_icon = in_array($swal_icon, ['success', 'error', 'warning', 'info'], true) ? $swal_icon : 'info';
    $swal_title = $message['type'] === 'success' ? 'Berhasil' : 'Perhatian';
    $js_page[] = "if (window.Swal) { Swal.fire({ icon: " . json_encode($swal_icon) . ", title: " . json_encode($swal_title) . ", text: " . json_encode($message['text']) . ", timer: 2400, timerProgressBar: true, showConfirmButton: false }); } else { alert(" . json_encode($message['text']) . "); }";
}

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
/* ===== Tampilan Kartu Data Projek ===== */
.dp-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}
.dp-search {
    position: relative;
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1 1 240px;
    max-width: 420px;
    height: 42px;
    padding: 0 12px;
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
}
.dp-search i {
    position: static;
    transform: none;
    color: #94a3b8;
    font-size: 15px;
    line-height: 1;
    flex: 0 0 auto;
    pointer-events: none;
}
.dp-search input,
.dp-search input.form-control {
    flex: 1 1 auto;
    min-width: 0;
    width: auto;
    height: auto;
    padding: 0 !important;
    margin: 0 !important;
    border: none !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    outline: none !important;
    background: transparent !important;
    font-size: 13.5px;
    color: #0f172a;
}
.dp-search:focus-within {
    background: #fff;
    border-color: #93a0f4;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
}
.dp-count {
    font-size: 12.5px;
    font-weight: 700;
    color: #475569;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    padding: 5px 13px;
    white-space: nowrap;
}
.dp-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
    gap: 16px;
    align-items: stretch;
}
.dp-card {
    height: 100%;
    display: flex;
    flex-direction: column;
    gap: 8px;
    background: #fff;
    border: 1.5px solid #e8ecf3;
    border-radius: 16px;
    padding: 14px 16px 12px;
    box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
    transition: box-shadow 0.18s ease, transform 0.18s ease, border-color 0.18s ease;
}
.dp-card:hover {
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.09);
    transform: translateY(-2px);
    border-color: #dbe3ef;
}
.dp-card-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
}
.dp-card .dp-tema-badge {
    white-space: normal;
    text-align: left;
    max-width: 100%;
}
.dp-flag-template {
    font-size: 10px;
    font-weight: 700;
    color: #64748b;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    padding: 3px 9px;
    white-space: nowrap;
    flex: 0 0 auto;
}
.dp-flag-template i { color: #f59e0b; }
.dp-card-title {
    font-size: 14.5px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.35;
    margin: 0;
}
.dp-card-desc { flex: 1 1 auto; }
.dp-card-desc p {
    font-size: 13px;
    color: #475569;
    line-height: 1.55;
    margin: 0;
}
.dp-card-desc p.dp-tujuan.clamped {
    display: -webkit-box;
    -webkit-line-clamp: 4;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.dp-card-desc.expanded p.dp-tujuan.clamped {
    display: block;
    -webkit-line-clamp: unset;
    overflow: visible;
}
.dp-more {
    background: none;
    border: none;
    padding: 0;
    margin-top: 4px;
    font-size: 12px;
    font-weight: 700;
    color: #4f46e5;
    cursor: pointer;
    display: none;
}
.dp-more:hover { text-decoration: underline; }
.dp-more i { font-size: 10px; }
.dp-card-actions {
    display: flex;
    gap: 8px;
    border-top: 1px dashed #e8ecf3;
    padding-top: 10px;
    margin-top: auto;
}
.dp-act {
    border: none;
    border-radius: 10px;
    font-size: 12.5px;
    font-weight: 700;
    padding: 6px 13px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    cursor: pointer;
    transition: background-color 0.15s ease, transform 0.15s ease;
}
.dp-act:active { transform: scale(0.96); }
.dp-act-edit { background: #eef2ff; color: #4338ca; }
.dp-act-edit:hover { background: #e0e7ff; color: #3730a3; }
.dp-act-del { background: #fef2f2; color: #b91c1c; }
.dp-act-del:hover { background: #fee2e2; color: #991b1b; }
.dp-empty {
    text-align: center;
    padding: 34px 16px;
    color: #94a3b8;
}
.dp-empty i { font-size: 34px; display: block; margin-bottom: 8px; }
@media (max-width: 767.98px) {
    .dp-search { max-width: none; flex: 1 1 100%; height: 44px; }
    .dp-grid { grid-template-columns: 1fr; gap: 12px; }
    .dp-card { border-radius: 14px; padding: 13px 14px 11px; }
    .dp-card-title { font-size: 14px; }
}

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
                    <div class="dp-toolbar">
                        <div class="dp-search">
                            <i class="bi bi-search"></i>
                            <input type="text" id="dpSearch" class="form-control" autocomplete="off" placeholder="Cari nama projek atau tema...">
                        </div>
                        <div class="dp-count"><span id="dpCount"><?= count($rows) ?></span> projek</div>
                    </div>

                    <div class="dp-grid" id="dpGrid">
                        <?php foreach ($rows as $r): ?>
                            <?php
                            $ts = getMasterProjekTemaStyle($r['tema'] ?? '');
                            $nama_lc = function_exists('mb_strtolower') ? mb_strtolower($r['nama_projek'], 'UTF-8') : strtolower($r['nama_projek']);
                            $tema_lc = function_exists('mb_strtolower') ? mb_strtolower((string)($r['tema'] ?? ''), 'UTF-8') : strtolower((string)($r['tema'] ?? ''));
                            ?>
                            <div class="dp-item"
                                 data-nama="<?= htmlspecialchars($nama_lc, ENT_QUOTES) ?>"
                                 data-tema="<?= htmlspecialchars($tema_lc, ENT_QUOTES) ?>">
                                <div class="dp-card">
                                    <div class="dp-card-top">
                                        <span class="dp-tema-badge" style="background: <?= $ts['bg'] ?>; color: <?= $ts['color'] ?>;">
                                            <i class="<?= $ts['icon'] ?>"></i>
                                            <?= htmlspecialchars($r['tema']) ?>
                                        </span>
                                        <?php if (!empty($r['is_template'])): ?>
                                            <span class="dp-flag-template"><i class="bi bi-bookmark-star-fill"></i> Template</span>
                                        <?php endif; ?>
                                    </div>

                                    <h6 class="dp-card-title"><?= htmlspecialchars($r['nama_projek']) ?></h6>

                                    <div class="dp-card-desc">
                                        <?php if (!empty($r['tujuan'])): ?>
                                            <p class="dp-tujuan clamped"><?= nl2br(htmlspecialchars($r['tujuan'])) ?></p>
                                            <button type="button" class="dp-more">Selengkapnya <i class="bi bi-chevron-down"></i></button>
                                        <?php else: ?>
                                            <p class="text-muted font-italic small mb-0">Belum ada tujuan</p>
                                        <?php endif; ?>
                                    </div>

                                    <div class="dp-card-actions">
                                        <button type="button" class="dp-act dp-act-edit btn-edit-data-projek"
                                            data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>'
                                            title="Edit Data Projek">
                                            <i class="bi bi-pencil-fill"></i> Edit
                                        </button>
                                        <button type="button" class="dp-act dp-act-del btn-hapus-data-projek"
                                            data-id="<?= (int)$r['id'] ?>"
                                            data-nama="<?= htmlspecialchars($r['nama_projek'], ENT_QUOTES) ?>"
                                            title="Hapus Data Projek">
                                            <i class="bi bi-trash-fill"></i> Hapus
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div id="dpEmpty" class="dp-empty d-none">
                        <i class="bi bi-inbox"></i>
                        <p class="mb-0">Tidak ada data projek yang cocok.</p>
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
