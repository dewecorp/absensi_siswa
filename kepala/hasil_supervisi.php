<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$page_title = 'Hasil Supervisi';
$current_page = basename(__FILE__);

$school_profile = getSchoolProfile($pdo);
$periode = sv_periode($pdo);

$css_libs = ['https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'];
$js_libs = [

    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
];

$filter_ta = trim((string)($_GET['tahun_ajaran'] ?? $periode['tahun_ajaran']));
$filter_jenis = trim((string)($_GET['jenis'] ?? ''));
$filter_status = trim((string)($_GET['status'] ?? ''));
$filter_guru = trim((string)($_GET['guru'] ?? ''));

$where = [];
$params = [];
if ($filter_ta !== '' && isTahunAjaranFormatValid($filter_ta)) {
    $rta = getRentangTanggalTahunAjaran($filter_ta);
    if ($rta) {
        $where[] = 'p.tanggal BETWEEN ? AND ?';
        $params[] = $rta['mulai'];
        $params[] = $rta['sampai'];
    }
}
if ($filter_jenis !== '') {
    $where[] = 'p.jenis_supervisi = ?';
    $params[] = $filter_jenis;
}
if ($filter_status !== '') {
    $where[] = 'p.status = ?';
    $params[] = $filter_status;
}
if ($filter_guru !== '') {
    $where[] = 'p.id_guru = ?';
    $params[] = (int)$filter_guru;
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$rows = [];
try {
    $stmt = $pdo->prepare("SELECT p.*, i.nama_instrumen,
            (SELECT COUNT(*) FROM tb_sv_tindak_lanjut t WHERE t.id_pelaksanaan = p.id_pelaksanaan) AS jml_tl,
            (SELECT COUNT(*) FROM tb_sv_tindak_lanjut t WHERE t.id_pelaksanaan = p.id_pelaksanaan AND t.status = 'Selesai') AS jml_tl_selesai
        FROM tb_sv_pelaksanaan p
        LEFT JOIN tb_sv_instrumen i ON i.id_instrumen = p.id_instrumen
        {$whereSql}
        ORDER BY p.tanggal DESC, p.id_pelaksanaan DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$guru_list = sv_guru_list($pdo);

$js_page = [];
$js_page[] = <<<'JS'
$(document).ready(function () {
    document.querySelectorAll('form[method="GET"]').forEach(function(f){f.querySelectorAll('select, input[type="date"]').forEach(function(el){el.addEventListener('change',function(){f.submit();});});});
    
    function svFilterHasilCards() {
        var q = $.trim($('#filter-search-hasil').val() || '').toLowerCase();
        var tl = $('#filter-tl-card').val();
        var visible = 0;
        $('.hasil-card-col').each(function () {
            var searchData = $(this).data('search') || '';
            var tlData = $(this).data('tl') || '';
            var matchSearch = !q || searchData.indexOf(q) !== -1;
            var matchTl = !tl || String(tlData) === String(tl);
            if (matchSearch && matchTl) {
                $(this).removeClass('d-none');
                visible++;
            } else {
                $(this).addClass('d-none');
            }
        });
        $('#empty-search-msg').toggle(visible === 0);
        $('#hasil-count-badge').text(visible + ' Hasil Supervisi');
    }
    $('#filter-search-hasil').on('input', svFilterHasilCards);
    $('#filter-tl-card').on('change', svFilterHasilCards);

    $('#btn-excel').on('click', function () { var table=document.getElementById('table-hasil');if(!table) return;if(typeof XLSX!=='undefined'){var clone=table.cloneNode(true);for(var i=0;i<clone.rows.length;i++){if(clone.rows[i].cells.length>0) clone.rows[i].deleteCell(-1);}var wb=XLSX.utils.table_to_book(clone,{sheet:"Sheet1"});XLSX.writeFile(wb,'hasil_supervisi.xlsx');}else{var clone=table.cloneNode(true);for(var i=0;i<clone.rows.length;i++){if(clone.rows[i].cells.length>0) clone.rows[i].deleteCell(-1);}var html='<table border="1">'+clone.innerHTML+'</table>';var a=document.createElement('a');a.href='data:application/vnd.ms-excel;charset=utf-8,'+encodeURIComponent(html);a.download='hasil_supervisi.xls';a.click();}; });
    $('#btn-pdf').on('click', function () { var q = $('form[method=GET]').serialize(); window.open('cetak_supervisi.php?page=hasil&' + q, '_blank'); });
    $(document).on('click', '.btn-cetak-perguru', function(e){ e.preventDefault(); var id=$(this).data('id'); if(id) window.open('cetak_detail_hasil_supervisi.php?id='+id, '_blank'); });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';

$total_hasil = count($rows);
$count_selesai_tl = 0;
$count_proses_tl = 0;
$count_belum_tl = 0;
$scores_hasil = [];

foreach ($rows as $r) {
    if ($r['nilai'] !== null) $scores_hasil[] = (float)$r['nilai'];
    if ((int)$r['jml_tl'] > 0) {
        if ((int)$r['jml_tl_selesai'] >= (int)$r['jml_tl']) $count_selesai_tl++;
        else $count_proses_tl++;
    } else {
        $count_belum_tl++;
    }
}
$rata_nilai_hasil = count($scores_hasil) > 0 ? round(array_sum($scores_hasil) / count($scores_hasil), 2) : 0;
?>
<div class="main-content">
    <style>
    .hover-shadow { transition: all 0.2s ease-in-out; }
    .hover-shadow:hover { box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,0.1) !important; transform: translateY(-2px); }
    .transition { transition: all 0.2s ease-in-out; }
    </style>
    <section class="section">
        <div class="section-header">
            <h1>Hasil Supervisi</h1>
            <?php echo render_breadcrumb(); ?>
        </div>
        <div class="section-body">
            <input type="hidden" id="svSchoolName" value="<?= htmlspecialchars($school_profile['nama_madrasah'] ?? 'MADRASAH', ENT_QUOTES) ?>">
            <input type="hidden" id="svSchoolLogo" value="<?= !empty($school_profile['logo']) ? '../assets/img/' . htmlspecialchars($school_profile['logo'], ENT_QUOTES) : '' ?>">
            <input type="hidden" id="svAcademicYear" value="<?= htmlspecialchars($periode['tahun_ajaran'], ENT_QUOTES) ?>">
            <input type="hidden" id="svSemester" value="<?= htmlspecialchars($periode['semester'], ENT_QUOTES) ?>">
            <input type="hidden" id="svHeadName" value="<?= htmlspecialchars($school_profile['nama_kepala'] ?? '-', ENT_QUOTES) ?>">
            <input type="hidden" id="svHeadNip" value="<?= htmlspecialchars($school_profile['nip_kepala'] ?? '-', ENT_QUOTES) ?>">
            <input type="hidden" id="svPrintPlace" value="<?= htmlspecialchars($school_profile['tempat_jadwal'] ?? 'Padang', ENT_QUOTES) ?>">
            <input type="hidden" id="svPrintDate" value="<?= date('d F Y') ?>">

            <div class="card mb-3">
                <div class="card-body py-3">
                    <form method="GET" class="form-row align-items-end">
                        <div class="form-group col-md-2 mb-2">
                            <label class="small font-weight-bold">Tahun Ajaran</label>
                            <select class="form-control form-control-sm" name="tahun_ajaran" onchange="this.form.submit()">
                                <?php foreach (sv_tahun_ajaran_options($pdo) as $ta): ?><option value="<?= htmlspecialchars($ta, ENT_QUOTES) ?>" <?= $ta === $filter_ta ? 'selected' : '' ?>><?= htmlspecialchars($ta) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-3 mb-2">
                            <label class="small font-weight-bold">Guru/PTK</label>
                            <select class="form-control form-control-sm" name="guru" onchange="this.form.submit()">
                                <option value="">Semua Guru/PTK</option>
                                <?php foreach ($guru_list as $g): ?><option value="<?= (int)$g['id_guru'] ?>" <?= (string)$g['id_guru'] === $filter_guru ? 'selected' : '' ?>><?= htmlspecialchars($g['nama_guru']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2 mb-2">
                            <label class="small font-weight-bold">Jenis</label>
                            <select class="form-control form-control-sm" name="jenis" onchange="this.form.submit()">
                                <option value="">Semua Jenis</option>
                                <?php foreach (sv_jenis_list() as $j): ?><option value="<?= $j ?>" <?= $j === $filter_jenis ? 'selected' : '' ?>><?= $j ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2 mb-2">
                            <label class="small font-weight-bold">Status Tindak Lanjut</label>
                            <select class="form-control form-control-sm" id="filter-tl-card">
                                <option value="">Semua Status TL</option>
                                <option value="Selesai">TL Selesai</option>
                                <option value="Dalam Proses">TL Dalam Proses</option>
                                <option value="Belum Ada">Belum Ada TL</option>
                            </select>
                        </div>
                        <div class="form-group col-md-3 mb-2">
                            <label class="small font-weight-bold">Cari Hasil Supervisi</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="filter-search-hasil" placeholder="Cari nama, mapel, supervisor, temuan...">
                                <div class="input-group-append">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Stats Chips -->
            <div class="d-flex flex-wrap align-items-center mb-3" style="gap: 8px;">
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-poll text-primary mr-1"></i> Total: <strong id="hasil-count-badge"><?= (int)$total_hasil ?> Hasil Supervisi</strong>
                </span>
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-check-double text-success mr-1"></i> TL Selesai: <strong><?= (int)$count_selesai_tl ?></strong>
                </span>
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-spinner text-warning mr-1"></i> TL Berjalan: <strong><?= (int)$count_proses_tl ?></strong>
                </span>
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-hourglass-start text-secondary mr-1"></i> Belum Ada TL: <strong><?= (int)$count_belum_tl ?></strong>
                </span>
                <?php if ($total_hasil > 0): ?>
                <span class="badge badge-light border text-dark px-3 py-2" style="border-radius: 8px; font-size: 12px;">
                    <i class="fas fa-chart-line text-info mr-1"></i> Rata-rata Nilai: <strong><?= number_format($rata_nilai_hasil, 2) ?></strong>
                </span>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4>Daftar Hasil Supervisi</h4>
                    <div class="card-header-action">
                        <button class="btn btn-success" id="btn-excel" type="button"><i class="fas fa-file-excel"></i> Excel</button>
                        <button class="btn btn-warning" id="btn-pdf" type="button"><i class="fas fa-file-pdf"></i> PDF</button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($rows)): ?>
                        <div class="alert alert-info text-center py-4">
                            <i class="fas fa-info-circle fa-2x mb-2 d-block text-info"></i>
                            Belum ada hasil supervisi yang ditemukan pada filter ini.
                        </div>
                    <?php else: ?>
                        <div id="empty-search-msg" class="alert alert-warning text-center" style="display:none;">
                            <i class="fas fa-search mr-1"></i> Tidak ada data hasil supervisi yang cocok dengan pencarian Anda.
                        </div>
                        <div class="row" id="hasil-cards-container">
                            <?php foreach ($rows as $r): ?>
                                <?php
                                $tlLabel = 'Belum Ada';
                                $tlBadge = 'secondary';
                                if ((int)$r['jml_tl'] > 0) {
                                    if ((int)$r['jml_tl_selesai'] >= (int)$r['jml_tl']) {
                                        $tlLabel = 'Selesai';
                                        $tlBadge = 'success';
                                    } else {
                                        $tlLabel = 'Dalam Proses';
                                        $tlBadge = 'warning';
                                    }
                                }
                                $nama = $r['jenis_supervisi'] === 'Manajerial' ? ($r['unit_bagian'] ?: '-') : ($r['nama_guru'] ?: '-');
                                $pred = (string)($r['predikat'] ?? '');
                                $predColor = 'secondary';
                                if (stripos($pred, 'Amat Baik') !== false || stripos($pred, 'Sangat Baik') !== false || stripos($pred, 'A') === 0) {
                                    $predColor = 'success';
                                } elseif (stripos($pred, 'Baik') !== false || stripos($pred, 'B') === 0) {
                                    $predColor = 'primary';
                                } elseif (stripos($pred, 'Cukup') !== false || stripos($pred, 'C') === 0) {
                                    $predColor = 'warning';
                                } elseif (stripos($pred, 'Kurang') !== false || stripos($pred, 'D') === 0) {
                                    $predColor = 'danger';
                                }

                                $searchBlob = strtolower(implode(' ', [
                                    $nama,
                                    $r['mapel_di_supervisi'] ?? '',
                                    $r['jenis_supervisi'] ?? '',
                                    $r['nama_instrumen'] ?? '',
                                    $r['supervisor'] ?? '',
                                    $r['predikat'] ?? '',
                                    $tlLabel,
                                    $r['kekuatan'] ?? '',
                                    $r['kelemahan'] ?? '',
                                    $r['rekomendasi'] ?? '',
                                    $r['prioritas_perbaikan'] ?? '',
                                    $r['keterangan'] ?? ''
                                ]));
                                ?>
                                <div class="col-12 col-md-6 col-lg-4 d-flex align-items-stretch mb-4 hasil-card-col"
                                     data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES) ?>"
                                     data-tl="<?= htmlspecialchars($tlLabel, ENT_QUOTES) ?>">
                                    <div class="card w-100 border-0 shadow-sm hover-shadow transition" style="border-radius: 12px; overflow: hidden; background: #fff; border: 1px solid #e2e8f0 !important;">
                                        <div class="card-body p-3 d-flex flex-column justify-content-between">
                                            <div>
                                                <!-- Top Bar -->
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <span class="badge badge-info px-2 py-1 font-weight-bold" style="font-size: 11.5px; border-radius: 6px;">
                                                        <i class="fas fa-clipboard-check mr-1"></i> <?= htmlspecialchars($r['jenis_supervisi']) ?>
                                                    </span>
                                                    <span class="badge badge-<?= $tlBadge ?> px-2 py-1 font-weight-bold" style="font-size: 11px; border-radius: 6px;">
                                                        <i class="fas <?= $tlLabel === 'Selesai' ? 'fa-check-double' : ($tlLabel === 'Dalam Proses' ? 'fa-spinner' : 'fa-hourglass-start') ?> mr-1"></i> TL: <?= $tlLabel ?>
                                                    </span>
                                                </div>

                                                <!-- Nama Target -->
                                                <h6 class="font-weight-bold text-dark mb-1" style="font-size: 15px; line-height: 1.4;">
                                                    <?= htmlspecialchars($nama) ?>
                                                </h6>
                                                <div class="text-muted small mb-2 d-flex flex-wrap align-items-center" style="gap: 6px; font-size: 11.5px;">
                                                    <?php if (!empty($r['mapel_di_supervisi'])): ?>
                                                        <span class="badge badge-light border text-dark font-weight-normal"><i class="fas fa-book text-muted mr-1"></i> <?= htmlspecialchars($r['mapel_di_supervisi']) ?></span>
                                                        <span>&bull;</span>
                                                    <?php endif; ?>
                                                    <span><i class="far fa-calendar-alt text-muted mr-1"></i> <?= $r['tanggal'] ? date('d/m/Y', strtotime($r['tanggal'])) : '-' ?></span>
                                                </div>

                                                <!-- Supervisor Info Box -->
                                                <div class="p-2 mb-2 rounded" style="background: #f8fafc; font-size: 11.5px; border: 1px dashed #cbd5e1; line-height: 1.4;">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span class="text-muted"><i class="fas fa-user-tie mr-1 text-primary"></i> Supervisor:</span>
                                                        <strong class="text-dark"><?= htmlspecialchars((string)($r['supervisor'] ?: '-')) ?></strong>
                                                    </div>
                                                    <?php if (!empty($r['nama_instrumen'])): ?>
                                                    <div class="mt-1 pt-1 border-top text-muted">
                                                        <i class="fas fa-tasks mr-1 text-info"></i> <?= htmlspecialchars((string)$r['nama_instrumen']) ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>

                                                <!-- Metrik Nilai & Predikat -->
                                                <div class="p-2 mb-2 rounded d-flex justify-content-between align-items-center" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                                                    <div>
                                                        <small class="text-muted d-block" style="font-size: 10.5px;">Nilai Hasil</small>
                                                        <h5 class="mb-0 text-primary font-weight-bold" style="font-size: 20px;">
                                                            <?= $r['nilai'] !== null ? number_format((float)$r['nilai'], 2) : '-' ?>
                                                        </h5>
                                                    </div>
                                                    <div class="text-right">
                                                        <small class="text-muted d-block" style="font-size: 10.5px;">Predikat</small>
                                                        <?php if (!empty($pred)): ?>
                                                            <span class="badge badge-<?= $predColor ?> font-weight-bold" style="font-size: 11px; padding: 4px 10px; border-radius: 6px;">
                                                                <?= htmlspecialchars($pred) ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted font-italic" style="font-size: 11px;">Belum dinilai</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>

                                                <!-- Temuan Ringkas Box -->
                                                <?php if (!empty($r['kekuatan']) || !empty($r['kelemahan']) || !empty($r['rekomendasi'])): ?>
                                                    <div class="p-2 rounded mb-2" style="background: #fffdf5; border: 1px solid #fef08a; font-size: 11.5px; line-height: 1.5;">
                                                        <?php if (!empty($r['kekuatan'])): ?>
                                                            <div class="mb-2">
                                                                <strong class="text-success d-block mb-1"><i class="fas fa-thumbs-up mr-1"></i> Kekuatan:</strong>
                                                                <span class="text-dark"><?= nl2br(htmlspecialchars($r['kekuatan'])) ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($r['kelemahan'])): ?>
                                                            <div class="mb-2 <?= !empty($r['kekuatan']) ? 'pt-2 border-top' : '' ?>">
                                                                <strong class="text-danger d-block mb-1"><i class="fas fa-exclamation-triangle mr-1"></i> Kelemahan:</strong>
                                                                <span class="text-dark"><?= nl2br(htmlspecialchars($r['kelemahan'])) ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($r['rekomendasi'])): ?>
                                                            <div class="<?= (!empty($r['kekuatan']) || !empty($r['kelemahan'])) ? 'pt-2 border-top' : '' ?>">
                                                                <strong class="text-warning d-block mb-1"><i class="fas fa-lightbulb mr-1"></i> Rekomendasi:</strong>
                                                                <span class="text-dark"><?= nl2br(htmlspecialchars($r['rekomendasi'])) ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Footer Aksi -->
                                            <div class="pt-2 mt-2 border-top d-flex justify-content-between align-items-center" style="gap: 6px;">
                                                <button class="btn btn-outline-info btn-sm btn-cetak-perguru px-2" type="button" data-id="<?= (int)$r['id_pelaksanaan'] ?>" style="border-radius: 6px; font-size: 11.5px;" title="Cetak Hasil Guru Ini">
                                                    <i class="fas fa-print mr-1"></i> Cetak
                                                </button>
                                                <a class="btn btn-outline-primary btn-sm px-3" href="hasil_supervisi_detail.php?id=<?= (int)$r['id_pelaksanaan'] ?>" style="border-radius: 6px; font-size: 12px;">
                                                    <i class="fas fa-eye mr-1"></i> Detail Hasil
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Hidden table for Excel export -->
                    <table class="d-none" id="table-hasil">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Guru/Unit</th>
                                <th>Mapel yang Disupervisi</th>
                                <th>Jenis</th>
                                <th>Tanggal</th>
                                <th>Supervisor</th>
                                <th>Nilai</th>
                                <th>Predikat</th>
                                <th>Kekuatan</th>
                                <th>Kelemahan</th>
                                <th>Rekomendasi</th>
                                <th>Prioritas</th>
                                <th>Status Tindak Lanjut</th>
                                <th>Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $idx => $r): ?>
                                <?php
                                $tlLabel = 'Belum Ada';
                                if ((int)$r['jml_tl'] > 0) {
                                    $tlLabel = ((int)$r['jml_tl_selesai'] >= (int)$r['jml_tl']) ? 'Selesai' : 'Dalam Proses';
                                }
                                $nama = $r['jenis_supervisi'] === 'Manajerial' ? ($r['unit_bagian'] ?: '-') : ($r['nama_guru'] ?: '-');
                                ?>
                                <tr>
                                    <td><?= $idx + 1 ?></td>
                                    <td><?= htmlspecialchars($nama) ?></td>
                                    <td><?= htmlspecialchars((string)($r['mapel_di_supervisi'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars($r['jenis_supervisi']) ?></td>
                                    <td><?= $r['tanggal'] ? date('d/m/Y', strtotime($r['tanggal'])) : '-' ?></td>
                                    <td><?= htmlspecialchars((string)$r['supervisor']) ?></td>
                                    <td><?= $r['nilai'] !== null ? htmlspecialchars(number_format((float)$r['nilai'], 2)) : '-' ?></td>
                                    <td><?= htmlspecialchars((string)$r['predikat']) ?></td>
                                    <td><?= htmlspecialchars((string)$r['kekuatan']) ?></td>
                                    <td><?= htmlspecialchars((string)$r['kelemahan']) ?></td>
                                    <td><?= htmlspecialchars((string)$r['rekomendasi']) ?></td>
                                    <td><?= htmlspecialchars((string)$r['prioritas_perbaikan']) ?></td>
                                    <td><?= $tlLabel ?></td>
                                    <td><?= htmlspecialchars((string)$r['keterangan']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</div>
<?php include '../templates/footer.php'; ?>
