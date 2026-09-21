<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$page_title = 'Laporan Supervisi';
$current_page = basename(__FILE__);
$can_manage = sv_is_supervisor($pdo);

$school_profile = getSchoolProfile($pdo);
$periode = sv_periode($pdo);
$filter_ta_laporan = trim((string)($_GET['tahun_ajaran'] ?? $periode['tahun_ajaran']));
if ($filter_ta_laporan === '' || !isTahunAjaranFormatValid($filter_ta_laporan)) {
    $filter_ta_laporan = $periode['tahun_ajaran'];
}

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
                $html .= '<p><strong>Hasil monitoring:</strong></p><table><thead><tr><th>Tanggal</th><th>Ke</th><th>Hasil</th><th>Nilai Sebelum → Sesudah</th></tr></thead><tbody>';
                foreach ($monitoring as $m) $html .= '<tr><td>' . $h($m['tanggal_monitoring'] ? date('d/m/Y', strtotime($m['tanggal_monitoring'])) : '-') . '</td><td>' . $h($m['monitoring_ke']) . '</td><td>' . $h($m['hasil_monitoring']) . '</td><td>' . $h(($m['nilai_sebelum'] ?? '-') . ' → ' . ($m['nilai_sesudah'] ?? '-')) . '</td></tr>';
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

$rta = getRentangTanggalTahunAjaran($filter_ta_laporan);
$rows = [];
try {
    if ($rta) {
        $stmt = $pdo->prepare("SELECT p.id_pelaksanaan, p.tanggal, p.nama_guru, p.unit_bagian, p.jenis_supervisi, p.id_program, p.id_guru, p.mapel_di_supervisi, p.nilai, p.predikat, p.temuan, p.rekomendasi, p.status, p.id_instrumen, pr.nama_program, pr.tahun_ajaran, pr.semester, i.nama_instrumen
            FROM tb_sv_pelaksanaan p
            LEFT JOIN tb_sv_program pr ON pr.id_program = p.id_program
            LEFT JOIN tb_sv_instrumen i ON i.id_instrumen = p.id_instrumen
            WHERE p.tanggal BETWEEN ? AND ?
            ORDER BY p.tanggal DESC, p.id_pelaksanaan DESC");
        $stmt->execute([$rta['mulai'], $rta['sampai']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = $pdo->query("SELECT p.id_pelaksanaan, p.tanggal, p.nama_guru, p.unit_bagian, p.jenis_supervisi, p.id_program, p.id_guru, p.mapel_di_supervisi, p.nilai, p.predikat, p.temuan, p.rekomendasi, p.status, p.id_instrumen, pr.nama_program, pr.tahun_ajaran, pr.semester, i.nama_instrumen
            FROM tb_sv_pelaksanaan p
            LEFT JOIN tb_sv_program pr ON pr.id_program = p.id_program
            LEFT JOIN tb_sv_instrumen i ON i.id_instrumen = p.id_instrumen
            ORDER BY p.tanggal DESC, p.id_pelaksanaan DESC")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}

$js_libs = [

    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
];
$css_libs = ['https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'];
$js_page = [];
$flash = sv_render_flash_js();
if ($flash !== '') $js_page[] = $flash;
$js_page[] = <<<'JS'
$(document).ready(function () {
    document.querySelectorAll('form[method="GET"]').forEach(function(f){f.querySelectorAll('select, input[type="date"]').forEach(function(el){el.addEventListener('change',function(){f.submit();});});});
    var dttablelaporan=$('#table-laporan').DataTable({language:{search:'Cari:',lengthMenu:'Tampilkan _MENU_ data',info:'Menampilkan _START_ sampai _END_ dari _TOTAL_ data',infoEmpty:'Tidak ada data',zeroRecords:'Data tidak ditemukan',paginate:{first:'Awal',last:'Akhir',next:'Berikutnya',previous:'Sebelumnya'}},pageLength:10,order:[],columnDefs:[],responsive:false});dttablelaporan.on('order.dt search.dt draw.dt',function(){var info=dttablelaporan.page.info();dttablelaporan.column(0,{search:'applied',order:'applied'}).nodes().each(function(cell,i){if(cell) cell.innerHTML=info.page*info.length+i+1;});}).draw();
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Laporan Supervisi</h1>
            <?php echo render_breadcrumb(); ?>
        </div>
        <div class="section-body">
            <input type="hidden" id="svSchoolName" value="<?= htmlspecialchars($school_profile['nama_madrasah'] ?? 'MADRASAH', ENT_QUOTES) ?>">
            <input type="hidden" id="svSchoolLogo" value="<?= !empty($school_profile['logo']) ? '../assets/img/' . htmlspecialchars($school_profile['logo'], ENT_QUOTES) : '' ?>">
            <input type="hidden" id="svAcademicYear" value="<?= htmlspecialchars($filter_ta_laporan, ENT_QUOTES) ?>">
            <input type="hidden" id="svSemester" value="<?= htmlspecialchars($periode['semester'], ENT_QUOTES) ?>">

            <div class="card">
                <div class="card-body">
                    <form method="GET" class="form-row align-items-end">
                        <div class="form-group col-md-3 mb-2">
                            <label class="small font-weight-bold">Tahun Ajaran Laporan</label>
                            <select class="form-control" name="tahun_ajaran">
                                <?php foreach (sv_tahun_ajaran_options($pdo) as $ta): ?><option value="<?= htmlspecialchars($ta, ENT_QUOTES) ?>" <?= $ta === $filter_ta_laporan ? 'selected' : '' ?>><?= htmlspecialchars($ta) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-9 mb-2">
                            <small class="text-muted d-block">Default TA berjalan (<?= htmlspecialchars($periode['tahun_ajaran']) ?>) — penilaian &amp; hasil tidak dikosongkan saat ganti TA. Ganti filter untuk lihat TA sebelumnya (auto submit).</small>
                            <span class="badge badge-light border">TA berjalan: <?= htmlspecialchars($periode['tahun_ajaran']) ?></span>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-laporan">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Tanggal</th>
                                    <th>Nama</th>
                                    <th>Jenis</th>
                                    <th>Program</th>
                                    <th>Nilai</th>
                                    <th>Predikat</th>
                                    <th>Temuan</th>
                                    <th>Status TL</th>
                                    <th width="12%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $i => $r):
                                    $isMan = $r['jenis_supervisi'] === 'Manajerial';
                                    $nama = $isMan ? ($r['unit_bagian'] ?: '-') : ($r['nama_guru'] ?: '-');
                                    $tlStatus = '-';
                                    try {
                                        $st = $pdo->prepare("SELECT status FROM tb_sv_tindak_lanjut WHERE id_pelaksanaan = ? ORDER BY id_tindak_lanjut DESC LIMIT 1");
                                        $st->execute([(int)$r['id_pelaksanaan']]);
                                        $tlStatus = $st->fetchColumn() ?: '-';
                                    } catch (Throwable $e) {}
                                    $badge = $tlStatus === 'Selesai' ? 'success' : ($tlStatus === '-' ? 'secondary' : 'warning');
                                ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td><?= $r['tanggal'] ? date('d/m/Y', strtotime($r['tanggal'])) : '-' ?></td>
                                        <td><?= htmlspecialchars($nama) ?></td>
                                        <td><span class="badge badge-info"><?= htmlspecialchars($r['jenis_supervisi']) ?></span></td>
                                        <td><?= htmlspecialchars((string)($r['nama_program'] ?? '-')) ?></td>
                                        <td><?= $r['nilai'] !== null ? htmlspecialchars(number_format((float)$r['nilai'], 2)) : '-' ?></td>
                                        <td><?= htmlspecialchars((string)($r['predikat'] ?? '-')) ?></td>
                                        <td><?= htmlspecialchars(mb_strimwidth((string)($r['temuan'] ?? '-'), 0, 70, '...')) ?></td>
                                        <td><span class="badge badge-<?= $badge ?>"><?= htmlspecialchars($tlStatus) ?></span></td>
                                        <td>
                                            <a class="btn btn-sm btn-outline-primary" href="hasil_supervisi_detail.php?id=<?= (int)$r['id_pelaksanaan'] ?>&from=laporan" title="Detail"><i class="fas fa-eye"></i></a>
                                            <a class="btn btn-sm btn-outline-secondary" href="cetak_detail_hasil_supervisi.php?id=<?= (int)$r['id_pelaksanaan'] ?>" target="_blank" title="Cetak"><i class="fas fa-print"></i></a>
                                            <form method="POST" action="laporan_supervisi.php" class="d-inline">
                                                <input type="hidden" name="aksi" value="pdf">
                                                <input type="hidden" name="id_pelaksanaan" value="<?= (int)$r['id_pelaksanaan'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="PDF"><i class="fas fa-file-pdf"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <small class="text-muted">Kolom lengkap (Tahun Ajaran, Semester, Jabatan/Kelas/Mapel, Instrumen, Rekomendasi, Tindak Lanjut, Bukti) tampil di Detail. Cetak buka Detail; PDF unduh dokumen formal A–F dengan pengesahan.</small>
                </div>
            </div>
        </div>
    </section>
</div>
<?php include '../templates/footer.php'; ?>
