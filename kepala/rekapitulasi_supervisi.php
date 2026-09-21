<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$page_title = 'Rekapitulasi Supervisi';
$current_page = basename(__FILE__);

$school_profile = getSchoolProfile($pdo);
$periode = sv_periode($pdo);

$css_libs = ['https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'];
$js_libs = [

    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
];

$guru_list = sv_guru_list($pdo);
$mapel_list = sv_mapel_list($pdo);
$kelas_list = sv_kelas_list($pdo);

$f = [
    'tahun_ajaran' => trim((string)($_GET['tahun_ajaran'] ?? $periode['tahun_ajaran'])),
    'semester' => trim((string)($_GET['semester'] ?? $periode['semester'])),
    'guru' => trim((string)($_GET['guru'] ?? '')),
    'mapel' => trim((string)($_GET['mapel'] ?? '')),
    'kelas' => trim((string)($_GET['kelas'] ?? '')),
    'jenis' => trim((string)($_GET['jenis'] ?? '')),
    'supervisor' => trim((string)($_GET['supervisor'] ?? '')),
    'predikat' => trim((string)($_GET['predikat'] ?? '')),
    'status' => trim((string)($_GET['status'] ?? '')),
    'kelompok' => trim((string)($_GET['kelompok'] ?? 'guru')),
];

$parts = explode('/', $f['tahun_ajaran']);
$ta_start = ($parts[0] ?? date('Y')) . '-07-01';
$ta_end = ($parts[1] ?? ((int)date('Y') + 1)) . '-06-30';

$where = ['p.tanggal BETWEEN ? AND ?'];
$params = [$ta_start, $ta_end];
if ($f['guru'] !== '') {
    $where[] = 'p.id_guru = ?';
    $params[] = (int)$f['guru'];
}
if ($f['jenis'] !== '') {
    $where[] = 'p.jenis_supervisi = ?';
    $params[] = $f['jenis'];
}
if ($f['supervisor'] !== '') {
    $where[] = 'p.supervisor = ?';
    $params[] = $f['supervisor'];
}
if ($f['predikat'] !== '') {
    $where[] = 'p.predikat = ?';
    $params[] = $f['predikat'];
}
if ($f['status'] !== '') {
    $where[] = 'p.status = ?';
    $params[] = $f['status'];
}
$whereSql = ' WHERE ' . implode(' AND ', $where);

$rows = [];
try {
    $stmt = $pdo->prepare("SELECT p.*, i.nama_instrumen FROM tb_sv_pelaksanaan p
        LEFT JOIN tb_sv_instrumen i ON i.id_instrumen = p.id_instrumen
        {$whereSql} ORDER BY p.nama_guru ASC, p.tanggal DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

// Ringkasan
$ringkas = [
    'jumlah_guru' => count($guru_list),
    'sudah' => 0,
    'belum' => 0,
    'jumlah_supervisi' => count($rows),
    'tertinggi' => null,
    'terendah' => null,
    'rata' => null,
    'temuan' => 0,
    'tl_selesai' => 0,
    'tl_belum' => 0,
];
$guruSudah = [];
$nilaiArr = [];
foreach ($rows as $r) {
    if ($r['status'] === 'Selesai') {
        if (!empty($r['id_guru'])) {
            $guruSudah[(int)$r['id_guru']] = true;
        }
        if ($r['nilai'] !== null) {
            $nilaiArr[] = (float)$r['nilai'];
        }
    }
    if (trim((string)$r['temuan']) !== '') {
        $ringkas['temuan']++;
    }
}
$ringkas['sudah'] = count($guruSudah);
$ringkas['belum'] = max(0, $ringkas['jumlah_guru'] - $ringkas['sudah']);
if ($nilaiArr) {
    $ringkas['tertinggi'] = max($nilaiArr);
    $ringkas['terendah'] = min($nilaiArr);
    $ringkas['rata'] = round(array_sum($nilaiArr) / count($nilaiArr), 2);
}
try {
    $ringkas['tl_selesai'] = (int)$pdo->query("SELECT COUNT(*) FROM tb_sv_tindak_lanjut WHERE status = 'Selesai'")->fetchColumn();
    $ringkas['tl_belum'] = (int)$pdo->query("SELECT COUNT(*) FROM tb_sv_tindak_lanjut WHERE status <> 'Selesai'")->fetchColumn();
} catch (Throwable $e) {
}

// Kelompok rekap
$kelompok = [];
if ($f['kelompok'] === 'mapel') {
    $mapelGuru = sv_mapel_guru($pdo);
    foreach ($rows as $r) {
        $idG = (int)$r['id_guru'];
        $names = $mapelGuru[$idG] ?? ['(Tanpa Mapel)'];
        foreach ($names as $nm) {
            if (!isset($kelompok[$nm])) {
                $kelompok[$nm] = ['label' => $nm, 'jumlah' => 0, 'nilai' => 0, 'count' => 0];
            }
            $kelompok[$nm]['jumlah']++;
            if ($r['nilai'] !== null) {
                $kelompok[$nm]['nilai'] += (float)$r['nilai'];
                $kelompok[$nm]['count']++;
            }
        }
    }
} elseif ($f['kelompok'] === 'kelas') {
    $kelasGuru = sv_kelas_guru($pdo);
    foreach ($rows as $r) {
        $idG = (int)$r['id_guru'];
        $names = $kelasGuru[$idG] ?? ['(Tanpa Kelas)'];
        foreach ($names as $nm) {
            if (!isset($kelompok[$nm])) {
                $kelompok[$nm] = ['label' => $nm, 'jumlah' => 0, 'nilai' => 0, 'count' => 0];
            }
            $kelompok[$nm]['jumlah']++;
            if ($r['nilai'] !== null) {
                $kelompok[$nm]['nilai'] += (float)$r['nilai'];
                $kelompok[$nm]['count']++;
            }
        }
    }
} elseif ($f['kelompok'] === 'jenis') {
    foreach ($rows as $r) {
        $label = $r['jenis_supervisi'];
        if (!isset($kelompok[$label])) {
            $kelompok[$label] = ['label' => $label, 'jumlah' => 0, 'nilai' => 0, 'count' => 0];
        }
        $kelompok[$label]['jumlah']++;
        if ($r['nilai'] !== null) {
            $kelompok[$label]['nilai'] += (float)$r['nilai'];
            $kelompok[$label]['count']++;
        }
    }
} else {
    foreach ($rows as $r) {
        $label = $r['nama_guru'] ?: ($r['unit_bagian'] ?: '-');
        if (!isset($kelompok[$label])) {
            $kelompok[$label] = ['label' => $label, 'jumlah' => 0, 'nilai' => 0, 'count' => 0];
        }
        $kelompok[$label]['jumlah']++;
        if ($r['nilai'] !== null) {
            $kelompok[$label]['nilai'] += (float)$r['nilai'];
            $kelompok[$label]['count']++;
        }
    }
}
foreach ($kelompok as &$k) {
    $k['rata'] = $k['count'] > 0 ? round($k['nilai'] / $k['count'], 2) : null;
}
unset($k);
ksort($kelompok);

$js_page = [];
$js_page[] = <<<'JS'
$(document).ready(function () {
    document.querySelectorAll('form[method="GET"]').forEach(function(f){f.querySelectorAll('select, input[type="date"]').forEach(function(el){el.addEventListener('change',function(){f.submit();});});});
    var dttablerekap=$('#table-rekap').DataTable({language:{search:'Cari:',lengthMenu:'Tampilkan _MENU_ data',info:'Menampilkan _START_ sampai _END_ dari _TOTAL_ data',infoEmpty:'Tidak ada data',zeroRecords:'Data tidak ditemukan',paginate:{first:'Awal',last:'Akhir',next:'Berikutnya',previous:'Sebelumnya'}},pageLength:10,order:[],columnDefs:[],responsive:false});dttablerekap.on('order.dt search.dt draw.dt',function(){var info=dttablerekap.page.info();dttablerekap.column(0,{search:'applied',order:'applied'}).nodes().each(function(cell,i){if(cell) cell.innerHTML=info.page*info.length+i+1;});}).draw();
    $('#btn-excel').on('click', function () { var table=document.getElementById('table-rekap');if(!table) return;if(typeof XLSX!=='undefined'){var clone=table.cloneNode(true);for(var i=0;i<clone.rows.length;i++){if(clone.rows[i].cells.length>0) clone.rows[i].deleteCell(-1);}var wb=XLSX.utils.table_to_book(clone,{sheet:"Sheet1"});XLSX.writeFile(wb,'rekapitulasi_supervisi.xlsx');}else{var clone=table.cloneNode(true);for(var i=0;i<clone.rows.length;i++){if(clone.rows[i].cells.length>0) clone.rows[i].deleteCell(-1);}var html='<table border="1">'+clone.innerHTML+'</table>';var a=document.createElement('a');a.href='data:application/vnd.ms-excel;charset=utf-8,'+encodeURIComponent(html);a.download='rekapitulasi_supervisi.xls';a.click();}; });
    $('#btn-pdf').on('click', function () { var q = $('form[method=GET]').serialize(); window.open('cetak_supervisi.php?page=rekapitulasi&' + q, '_blank'); });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Rekapitulasi Supervisi</h1>
            <?php echo render_breadcrumb(); ?>
        </div>
        <div class="section-body">
            <input type="hidden" id="svSchoolName" value="<?= htmlspecialchars($school_profile['nama_madrasah'] ?? 'MADRASAH', ENT_QUOTES) ?>">
            <input type="hidden" id="svSchoolLogo" value="<?= !empty($school_profile['logo']) ? '../assets/img/' . htmlspecialchars($school_profile['logo'], ENT_QUOTES) : '' ?>">
            <input type="hidden" id="svAcademicYear" value="<?= htmlspecialchars($f['tahun_ajaran'], ENT_QUOTES) ?>">
            <input type="hidden" id="svSemester" value="<?= htmlspecialchars($f['semester'], ENT_QUOTES) ?>">
            <input type="hidden" id="svHeadName" value="<?= htmlspecialchars($school_profile['nama_kepala'] ?? '-', ENT_QUOTES) ?>">
            <input type="hidden" id="svHeadNip" value="<?= htmlspecialchars($school_profile['nip_kepala'] ?? '-', ENT_QUOTES) ?>">
            <input type="hidden" id="svPrintPlace" value="<?= htmlspecialchars($school_profile['tempat_jadwal'] ?? 'Padang', ENT_QUOTES) ?>">
            <input type="hidden" id="svPrintDate" value="<?= date('d F Y') ?>">

            <div class="card">
                <div class="card-body">
                    <form method="GET" class="form-row align-items-end">
                        <div class="form-group col-md-3 mb-2">
                            <label class="small font-weight-bold">Tahun Ajaran</label>
                            <select class="form-control" name="tahun_ajaran">
                                <?php foreach (sv_tahun_ajaran_options($pdo) as $ta): ?><option value="<?= htmlspecialchars($ta, ENT_QUOTES) ?>" <?= $ta === $f['tahun_ajaran'] ? 'selected' : '' ?>><?= htmlspecialchars($ta) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2 mb-2">
                            <label class="small font-weight-bold">Semester</label>
                            <select class="form-control" name="semester">
                                <option value="">Semua</option>
                                <?php foreach (sv_semester_options() as $s): ?><option value="<?= htmlspecialchars($s, ENT_QUOTES) ?>" <?= $s === $f['semester'] ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2 mb-2">
                            <label class="small font-weight-bold">Guru</label>
                            <select class="form-control" name="guru">
                                <option value="">Semua</option>
                                <?php foreach ($guru_list as $g): ?><option value="<?= (int)$g['id_guru'] ?>" <?= (string)$g['id_guru'] === $f['guru'] ? 'selected' : '' ?>><?= htmlspecialchars($g['nama_guru']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2 mb-2">
                            <label class="small font-weight-bold">Jenis</label>
                            <select class="form-control" name="jenis">
                                <option value="">Semua</option>
                                <?php foreach (sv_jenis_list() as $j): ?><option value="<?= $j ?>" <?= $j === $f['jenis'] ? 'selected' : '' ?>><?= $j ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2 mb-2">
                            <label class="small font-weight-bold">Predikat</label>
                            <select class="form-control" name="predikat">
                                <option value="">Semua</option>
                                <?php foreach (['A - Amat Baik', 'B - Baik', 'C - Cukup', 'D - Kurang'] as $p): ?><option value="<?= $p ?>" <?= $p === $f['predikat'] ? 'selected' : '' ?>><?= $p ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2 mb-2">
                            <label class="small font-weight-bold">Status</label>
                            <select class="form-control" name="status">
                                <option value="">Semua</option>
                                <option value="Selesai" <?= $f['status'] === 'Selesai' ? 'selected' : '' ?>>Selesai</option>
                                <option value="Draft" <?= $f['status'] === 'Draft' ? 'selected' : '' ?>>Draft</option>
                            </select>
                        </div>
                        <div class="form-group col-md-2 mb-2">
                            <label class="small font-weight-bold">Rekap Per</label>
                            <select class="form-control" name="kelompok">
                                <option value="guru" <?= $f['kelompok'] === 'guru' ? 'selected' : '' ?>>Guru</option>
                                <option value="mapel" <?= $f['kelompok'] === 'mapel' ? 'selected' : '' ?>>Mata Pelajaran</option>
                                <option value="kelas" <?= $f['kelompok'] === 'kelas' ? 'selected' : '' ?>>Kelas</option>
                                <option value="jenis" <?= $f['kelompok'] === 'jenis' ? 'selected' : '' ?>>Jenis Supervisi</option>
                            </select>
                        </div>
                        <div class="form-group col-12 mb-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Terapkan</button>
                            <a href="rekapitulasi_supervisi.php" class="btn btn-light">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="row">
                <?php
                $cards = [
                    ['Jumlah Guru', $ringkas['jumlah_guru'], 'primary'],
                    ['Sudah Disupervisi', $ringkas['sudah'], 'success'],
                    ['Belum Disupervisi', $ringkas['belum'], 'warning'],
                    ['Jumlah Supervisi', $ringkas['jumlah_supervisi'], 'info'],
                    ['Nilai Tertinggi', $ringkas['tertinggi'] !== null ? number_format($ringkas['tertinggi'], 2) : '-', 'success'],
                    ['Nilai Terendah', $ringkas['terendah'] !== null ? number_format($ringkas['terendah'], 2) : '-', 'danger'],
                    ['Nilai Rata-rata', $ringkas['rata'] !== null ? number_format($ringkas['rata'], 2) : '-', 'primary'],
                    ['Jumlah Temuan', $ringkas['temuan'], 'danger'],
                    ['Tindak Lanjut Selesai', $ringkas['tl_selesai'], 'success'],
                    ['Tindak Lanjut Belum Selesai', $ringkas['tl_belum'], 'warning'],
                ];
                foreach ($cards as $c): ?>
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="card card-statistic-1">
                            <div class="card-icon bg-<?= $c[2] ?>"><i class="fas fa-chart-bar"></i></div>
                            <div class="card-wrap">
                                <div class="card-header"><h4><?= htmlspecialchars($c[0]) ?></h4></div>
                                <div class="card-body"><?= htmlspecialchars((string)$c[1]) ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4>Rekap Per <?= htmlspecialchars(ucfirst($f['kelompok'])) ?></h4>
                    <div class="card-header-action">
                        <button class="btn btn-success" id="btn-excel" type="button"><i class="fas fa-file-excel"></i> Excel</button>
                        <button class="btn btn-warning" id="btn-pdf" type="button"><i class="fas fa-file-pdf"></i> PDF</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-rekap">
                            <thead>
                                <tr>
                                    <th width="5%">No</th>
                                    <th><?= htmlspecialchars(ucfirst($f['kelompok'])) ?></th>
                                    <th>Jumlah Supervisi</th>
                                    <th>Nilai Rata-rata</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($kelompok as $k): ?>
                                    <tr>
                                        <td class="text-center"></td>
                                        <td><?= htmlspecialchars($k['label']) ?></td>
                                        <td><?= (int)$k['jumlah'] ?></td>
                                        <td><?= $k['rata'] !== null ? htmlspecialchars(number_format((float)$k['rata'], 2)) : '-' ?></td>
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
