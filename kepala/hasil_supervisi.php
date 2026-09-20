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
    'assets/js/supervisi.js',
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
    SV.autoSubmitFilters('form');
    SV.initDataTable('#table-hasil');
    $('#btn-excel').on('click', function () { SV.exportExcel('table-hasil', 'Hasil Supervisi', 'hasil_supervisi', true); });
    $('#btn-pdf').on('click', function () { SV.printPdf('table-hasil', 'Hasil Supervisi', true); });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<div class="main-content">
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

            <div class="card">
                <div class="card-body">
                    <form method="GET" class="form-row align-items-end">
                        <div class="form-group col-md-2 mb-2">
                            <label class="small font-weight-bold">Tahun Ajaran</label>
                            <select class="form-control" name="tahun_ajaran">
                                <?php foreach (sv_tahun_ajaran_options($pdo) as $ta): ?><option value="<?= htmlspecialchars($ta, ENT_QUOTES) ?>" <?= $ta === $filter_ta ? 'selected' : '' ?>><?= htmlspecialchars($ta) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2 mb-2">
                            <label class="small font-weight-bold">Guru/PTK</label>
                            <select class="form-control" name="guru">
                                <option value="">Semua</option>
                                <?php foreach ($guru_list as $g): ?><option value="<?= (int)$g['id_guru'] ?>" <?= (string)$g['id_guru'] === $filter_guru ? 'selected' : '' ?>><?= htmlspecialchars($g['nama_guru']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-3 mb-2">
                            <label class="small font-weight-bold">Jenis</label>
                            <select class="form-control" name="jenis">
                                <option value="">Semua</option>
                                <?php foreach (sv_jenis_list() as $j): ?><option value="<?= $j ?>" <?= $j === $filter_jenis ? 'selected' : '' ?>><?= $j ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-3 mb-2">
                            <label class="small font-weight-bold">Status</label>
                            <select class="form-control" name="status">
                                <option value="">Semua</option>
                                <option value="Selesai" <?= $filter_status === 'Selesai' ? 'selected' : '' ?>>Selesai</option>
                                <option value="Draft" <?= $filter_status === 'Draft' ? 'selected' : '' ?>>Draft</option>
                            </select>
                        </div>
                        <div class="form-group col-md-3 mb-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                            <a href="hasil_supervisi.php" class="btn btn-light">Reset</a>
                        </div>
                    </form>
                </div>
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
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-hasil">
                            <thead>
                                <tr>
                                    <th width="5%">No</th>
                                    <th>Guru/Unit</th>
                                    <th>Mata Pelajaran</th>
                                    <th>Jenis</th>
                                    <th>Tanggal</th>
                                    <th>Supervisor</th>
                                    <th>Nilai</th>
                                    <th>Predikat</th>
                                    <th>Kekuatan</th>
                                    <th>Kelemahan</th>
                                    <th>Temuan</th>
                                    <th>Rekomendasi</th>
                                    <th>Prioritas</th>
                                    <th>Status Tindak Lanjut</th>
                                    <th>Keterangan</th>
                                    <th width="8%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
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
                                    ?>
                                    <tr>
                                        <td class="text-center"></td>
                                        <td><?= htmlspecialchars($nama) ?></td>
                                        <td><?= htmlspecialchars((string)($r['mapel_di_supervisi'] ?? '')) ?></td>
                                        <td><span class="badge badge-info"><?= htmlspecialchars($r['jenis_supervisi']) ?></span></td>
                                        <td><?= $r['tanggal'] ? date('d/m/Y', strtotime($r['tanggal'])) : '-' ?></td>
                                        <td><?= htmlspecialchars((string)$r['supervisor']) ?></td>
                                        <td><?= $r['nilai'] !== null ? htmlspecialchars(number_format((float)$r['nilai'], 2)) : '-' ?></td>
                                        <td><?= htmlspecialchars((string)$r['predikat']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['kekuatan']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['kelemahan']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['temuan']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['rekomendasi']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['prioritas_perbaikan']) ?></td>
                                        <td><span class="badge badge-<?= $tlBadge ?>"><?= $tlLabel ?></span></td>
                                        <td><?= htmlspecialchars((string)$r['keterangan']) ?></td>
                                        <td><a class="btn btn-sm btn-outline-primary" href="hasil_supervisi_detail.php?id=<?= (int)$r['id_pelaksanaan'] ?>"><i class="fas fa-eye"></i> Detail</a></td>
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
<?php include '../templates/footer.php'; ?>
