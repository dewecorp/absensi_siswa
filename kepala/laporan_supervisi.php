<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$page_title = 'Laporan Supervisi';
$current_page = basename(__FILE__);

$school_profile = getSchoolProfile($pdo);
$periode = sv_periode($pdo);
$can_manage = sv_is_supervisor($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $aksi = (string)($_POST['aksi'] ?? '');
    if ($aksi === 'pdf' && isset($_POST['id_pelaksanaan'])) {
        $id = (int)$_POST['id_pelaksanaan'];
        $row = sv_detail_pelaksanaan($pdo, $id);
        if (!$row) {
            sv_flash('warning', 'Data tidak ditemukan.');
            redirect('laporan_supervisi.php');
        }
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (!file_exists($autoload)) {
            die('Vendor autoload tidak ditemukan. Jalankan: composer install');
        }
        require_once $autoload;
        $penilaian = sv_penilaian_rows($pdo, $id);
        $manajerial = sv_manajerial_rows($pdo, $id);
        $tindak = sv_tindak_lanjut_by_pelaksanaan($pdo, $id);
        $arsip = sv_arsip_by_pelaksanaan($pdo, $id);
        $monitoring = [];
        try {
            if ($tindak) {
                $ids = array_column($tindak, 'id_tindak_lanjut');
                $in = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("SELECT * FROM tb_sv_monitoring WHERE id_tindak_lanjut IN ($in) ORDER BY tanggal_monitoring ASC");
                $stmt->execute($ids);
                $monitoring = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $e) {}
        $isMan = ($row['jenis_supervisi'] === 'Manajerial');
        $nama = $isMan ? ($row['unit_bagian'] ?: '-') : ($row['nama_guru'] ?: '-');
        $sasaranRow = null;
        if (!$isMan && !empty($row['id_guru'])) {
            try {
                $st = $pdo->prepare("SELECT jabatan, kelas FROM tb_sv_sasaran WHERE id_guru = ? ORDER BY id_sasaran DESC LIMIT 1");
                $st->execute([(int)$row['id_guru']]);
                $sasaranRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {}
        }
        $jabatanMapel = trim(($sasaranRow['jabatan'] ?? '') . ' / ' . ($row['mapel_di_supervisi'] ?? '') . ' / ' . ($sasaranRow['kelas'] ?? ''), ' /');
        if ($jabatanMapel === '') $jabatanMapel = '-';
        $logoPath = !empty($school_profile['logo']) ? __DIR__ . '/../assets/img/' . basename((string)$school_profile['logo']) : '';
        $logoData = '';
        if ($logoPath && is_readable($logoPath)) {
            $mime = function_exists('mime_content_type') ? (mime_content_type($logoPath) ?: 'image/png') : 'image/png';
            $logoData = 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($logoPath));
        }
        $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:14mm 12mm}body{font-family:DejaVu Sans,Arial,sans-serif;font-size:9pt;color:#222;line-height:1.45}.header{text-align:center;border-bottom:2px solid #000;padding-bottom:7px;margin-bottom:10px}.header img{height:58px;float:left}h2{margin:0;font-size:13pt}h3{margin:2px 0;font-size:11pt}.meta{text-align:center;font-size:8pt;color:#444;margin:2px 0 8px}h4{margin:12px 0 6px;font-size:10pt;border-bottom:1px solid #999;padding-bottom:3px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #555;padding:4px 6px;vertical-align:top;font-size:8pt}th{background:#eee}.info td{border:none;padding:2px 6px}.sign{margin-top:22px;width:100%}.sign td{border:none;text-align:center;vertical-align:top}</style></head><body>';
        $html .= '<div class="header">';
        if ($logoData) $html .= '<img src="' . $logoData . '">';
        $html .= '<h2>' . $h(strtoupper((string)($school_profile['nama_madrasah'] ?? 'MADRASAH'))) . '</h2><h3>LAPORAN HASIL SUPERVISI</h3><div class="meta">' . $h($periode['tahun_ajaran']) . ' &middot; ' . $h($periode['semester']) . '</div></div>';
        $html .= '<h4>A. Identitas</h4><table class="info">';
        $html .= '<tr><td width="24%"><strong>Madrasah</strong></td><td>' . $h($school_profile['nama_madrasah'] ?? '-') . '</td><td width="24%"><strong>Tanggal Supervisi</strong></td><td>' . $h($row['tanggal'] ? date('d F Y', strtotime($row['tanggal'])) : '-') . '</td></tr>';
        $pr = $pdo->prepare("SELECT tahun_ajaran, semester, nama_program FROM tb_sv_program WHERE id_program = ? LIMIT 1");
        $prTa = '-'; $prSm = '-'; $prNm = '-';
        try { $pr->execute([$row['id_program']]); $prRow = $pr->fetch(PDO::FETCH_ASSOC); $prTa = $prRow['tahun_ajaran'] ?? '-'; $prSm = $prRow['semester'] ?? '-'; $prNm = $prRow['nama_program'] ?? '-'; } catch (Throwable $e) {}
        $html .= '<tr><td><strong>Tahun Ajaran</strong></td><td>' . $h($prTa) . '</td><td><strong>Nama yang Disupervisi</strong></td><td>' . $h($nama) . '</td></tr>';
        $html .= '<tr><td><strong>Semester</strong></td><td>' . $h($prSm) . '</td><td><strong>Jabatan / Kelas / Mapel</strong></td><td>' . $h($jabatanMapel) . '</td></tr>';
        $html .= '<tr><td><strong>Jenis Supervisi</strong></td><td>' . $h($row['jenis_supervisi']) . '</td><td><strong>Supervisor</strong></td><td>' . $h($row['supervisor'] ?? '-') . '</td></tr>';
        $html .= '<tr><td><strong>Program Supervisi</strong></td><td>' . $h($prNm) . '</td><td><strong>Instrumen</strong></td><td>' . $h($row['nama_instrumen'] ?? $row['kode_instrumen'] ?? '-') . '</td></tr>';
        $html .= '</table>';
        $html .= '<h4>B. Hasil Supervisi</h4>';
        $html .= '<p><strong>Nilai:</strong> ' . $h($row['nilai'] !== null ? number_format((float)$row['nilai'], 2) : '-') . ' &nbsp; <strong>Predikat:</strong> ' . $h($row['predikat'] ?? '-') . '</p>';
        $html .= '<table><thead><tr><th>No</th><th>Komponen</th><th>Indikator</th><th>Bobot</th><th>Skor</th><th>Nilai</th><th>Catatan</th></tr></thead><tbody>';
        if ($isMan) {
            foreach ($manajerial as $i => $m) $html .= '<tr><td>' . ($i+1) . '</td><td>' . $h($m['unit_bagian']) . '</td><td>' . $h($m['indikator']) . '</td><td>-</td><td>' . $h(number_format((float)$m['skor'],0)) . '</td><td>' . $h(number_format((float)$m['skor'],2)) . '</td><td>' . $h($m['temuan'] ?? '') . '</td></tr>';
        } elseif ($penilaian) {
            foreach ($penilaian as $i => $p) $html .= '<tr><td>' . ($i+1) . '</td><td>' . $h($p['komponen'] ?: ($p['komponen_master'] ?? '')) . '</td><td>' . $h($p['indikator']) . '</td><td>' . $h(number_format((float)$p['bobot'],2)) . '</td><td>' . $h(number_format((float)$p['skor'],0)) . '</td><td>' . $h(number_format((float)$p['nilai'],2)) . '</td><td>' . $h($p['catatan']) . '</td></tr>';
        } else $html .= '<tr><td colspan="7" style="text-align:center">Tidak ada detail penilaian.</td></tr>';
        $html .= '</tbody></table>';
        $html .= '<p><strong>Ringkasan:</strong><br>Kekuatan: ' . nl2br($h($row['kekuatan'] ?? '-')) . '<br>Kelemahan: ' . nl2br($h($row['kelemahan'] ?? '-')) . '</p>';
        $html .= '<h4>C. Temuan</h4><p><strong>Hal sudah baik:</strong><br>' . nl2br($h($row['kekuatan'] ?? '-')) . '</p><p><strong>Hal perlu diperbaiki:</strong><br>' . nl2br($h($row['kelemahan'] ?? '-')) . '</p><p><strong>Temuan utama:</strong><br>' . nl2br($h($row['temuan'] ?? '-')) . '</p>';
        $html .= '<h4>D. Rekomendasi dan Tindak Lanjut</h4><p><strong>Rekomendasi:</strong><br>' . nl2br($h($row['rekomendasi'] ?? '-')) . '</p>';
        if ($tindak) {
            $html .= '<table><thead><tr><th>Bentuk</th><th>Rencana</th><th>Target</th><th>Status</th></tr></thead><tbody>';
            foreach ($tindak as $t) $html .= '<tr><td>' . $h($t['bentuk_tindak_lanjut']) . '</td><td>' . $h($t['rencana_tindakan']) . '</td><td>' . $h($t['target_selesai'] ? date('d/m/Y', strtotime($t['target_selesai'])) : '-') . '</td><td>' . $h($t['status']) . '</td></tr>';
            $html .= '</tbody></table>';
            if ($monitoring) {
                $html .= '<p><strong>Hasil monitoring:</strong></p><table><thead><tr><th>Tanggal</th><th>Ke</th><th>Hasil</th><th>Nilai Sebelum / Sesudah</th></tr></thead><tbody>';
                foreach ($monitoring as $m) $html .= '<tr><td>' . $h($m['tanggal_monitoring'] ? date('d/m/Y', strtotime($m['tanggal_monitoring'])) : '-') . '</td><td>' . $h($m['monitoring_ke']) . '</td><td>' . $h($m['hasil_monitoring']) . '</td><td>' . $h(($m['nilai_sebelum'] ?? '-') . ' / ' . ($m['nilai_sesudah'] ?? '-')) . '</td></tr>';
                $html .= '</tbody></table>';
            }
        } else $html .= '<p>Tindak lanjut: -</p>';
        $html .= '<h4>E. Bukti Pendukung</h4>';
        if ($arsip) {
            $html .= '<table><thead><tr><th>Jenis</th><th>Nama Dokumen</th><th>Tautan / File</th></tr></thead><tbody>';
            foreach ($arsip as $a) $html .= '<tr><td>' . $h($a['jenis_dokumen']) . '</td><td>' . $h($a['nama_dokumen']) . '</td><td>' . $h($a['tautan_dokumen'] ?: $a['file'] ?: '-') . '</td></tr>';
            $html .= '</tbody></table>';
        } else $html .= '<p>Tidak ada bukti pendukung.</p>';
        $html .= '<h4>F. Pengesahan</h4><table class="sign"><tr><td width="50%"></td><td width="50%">' . $h($school_profile['tempat_jadwal'] ?? 'Padang') . ', ' . date('d F Y') . '<br>Kepala Madrasah / Supervisor<br><br><br><br><strong>' . $h($school_profile['nama_kepala'] ?? '-') . '</strong><br>NIP. ' . $h($school_profile['nip_kepala'] ?? '-') . '</td></tr><tr><td style="padding-top:18px">Yang disupervisi,<br><br><br><br><strong>' . $h($nama) . '</strong></td><td></td></tr></table>';
        $html .= '</body></html>';
        $dompdf = new Dompdf\Dompdf(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true]);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();
        $dompdf->stream('laporan_' . $id . '.pdf', ['Attachment' => false]);
        exit;
    }
}

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
    var dttablelaporan=$('#table-laporan').DataTable({language:{search:'Cari:',lengthMenu:'Tampilkan _MENU_ data',info:'Menampilkan _START_ sampai _END_ dari _TOTAL_ data',infoEmpty:'Tidak ada data',zeroRecords:'Data tidak ditemukan',paginate:{first:'Awal',last:'Akhir',next:'Berikutnya',previous:'Sebelumnya'}},pageLength:10,order:[],columnDefs:[],responsive:false});dttablelaporan.on('order.dt search.dt draw.dt',function(){var info=dttablelaporan.page.info();dttablelaporan.column(0,{search:'applied',order:'applied'}).nodes().each(function(cell,i){if(cell) cell.innerHTML=info.page*info.length+i+1;});}).draw();
    $('#btn-excel').on('click', function () { var table=document.getElementById('table-laporan');if(!table) return;if(typeof XLSX!=='undefined'){var clone=table.cloneNode(true);for(var i=0;i<clone.rows.length;i++){if(clone.rows[i].cells.length>0) clone.rows[i].deleteCell(-1);}var wb=XLSX.utils.table_to_book(clone,{sheet:"Sheet1"});XLSX.writeFile(wb,'laporan_supervisi.xlsx');}else{var clone=table.cloneNode(true);for(var i=0;i<clone.rows.length;i++){if(clone.rows[i].cells.length>0) clone.rows[i].deleteCell(-1);}var html='<table border="1">'+clone.innerHTML+'</table>';var a=document.createElement('a');a.href='data:application/vnd.ms-excel;charset=utf-8,'+encodeURIComponent(html);a.download='laporan_supervisi.xls';a.click();}; });
    $('#btn-pdf').on('click', function () { var q = $('form[method=GET]').serialize(); window.open('cetak_supervisi.php?page=laporan&' + q, '_blank'); });
    $('#table-laporan').on('click', '.btn-cetak-perguru', function(e){ e.preventDefault(); var id=$(this).data('id'); if(id) window.open('cetak_detail_hasil_supervisi.php?id='+id, '_blank'); });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<div class="main-content">
    <style>
    #table-laporan th:last-child,#table-laporan td:last-child{white-space:nowrap;text-align:center}
    #table-laporan td:last-child .btn{margin:1px 2px}
    </style>
    <section class="section">
        <div class="section-header">
            <h1>Laporan Supervisi</h1>
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
                            <a href="laporan_supervisi.php" class="btn btn-light">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4>Daftar Laporan Supervisi</h4>
                    <div class="card-header-action">
                        <button class="btn btn-success" id="btn-excel" type="button"><i class="fas fa-file-excel"></i> Excel</button>
                        <button class="btn btn-warning" id="btn-pdf" type="button"><i class="fas fa-file-pdf"></i> PDF</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-laporan">
                            <thead>
                                <tr>
                                    <th width="5%">No</th>
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
                                    <th width="12%">Aksi</th>
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
                                        <td><?= htmlspecialchars((string)$r['rekomendasi']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['prioritas_perbaikan']) ?></td>
                                        <td><span class="badge badge-<?= $tlBadge ?>"><?= $tlLabel ?></span></td>
                                        <td><?= htmlspecialchars((string)$r['keterangan']) ?></td>
                                        <td style="white-space:nowrap">
                                            <div class="d-inline-flex align-items-center">
                                            <a class="btn btn-sm btn-outline-primary mr-1" href="hasil_supervisi_detail.php?id=<?= (int)$r['id_pelaksanaan'] ?>&from=laporan" title="Detail"><i class="fas fa-eye"></i></a>
                                            <a class="btn btn-sm btn-outline-secondary mr-1" href="cetak_detail_hasil_supervisi.php?id=<?= (int)$r['id_pelaksanaan'] ?>" target="_blank" title="Cetak"><i class="fas fa-print"></i></a>
                                            <?php if ($can_manage): ?>
                                            <form method="POST" action="laporan_supervisi.php" class="d-inline m-0">
                                                <input type="hidden" name="aksi" value="pdf">
                                                <input type="hidden" name="id_pelaksanaan" value="<?= (int)$r['id_pelaksanaan'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="PDF"><i class="fas fa-file-pdf"></i></button>
                                            </form>
                                            <?php endif; ?>
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
<?php include '../templates/footer.php'; ?>
