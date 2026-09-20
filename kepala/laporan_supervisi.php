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

$jenis = trim((string)($_GET['jenis'] ?? $_POST['jenis'] ?? 'program_supervisi'));
$laporan_list = sv_laporan_jenis_list();
if (!isset($laporan_list[$jenis])) {
    $jenis = 'program_supervisi';
}

$filter_ta = trim((string)($_GET['tahun_ajaran'] ?? $_POST['tahun_ajaran'] ?? $periode['tahun_ajaran']));
$filter_sem = trim((string)($_GET['semester'] ?? $_POST['semester'] ?? $periode['semester']));

$laporan = sv_laporan_data($pdo, $jenis, ['tahun_ajaran' => $filter_ta, 'semester' => $filter_sem]);
$laporan_title = $laporan['title'] ?? ($laporan_list[$jenis] ?? 'Laporan Supervisi');
$headers = $laporan['headers'] ?? [];
$rows = $laporan['rows'] ?? [];

// Export PDF server-side (Dompdf)
if (($_POST['export'] ?? '') === 'pdf') {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        die('Vendor autoload tidak ditemukan. Jalankan: composer install');
    }
    require_once $autoload;

    $logoPath = !empty($school_profile['logo']) ? __DIR__ . '/../assets/img/' . basename((string)$school_profile['logo']) : '';
    $logoData = '';
    if ($logoPath && is_readable($logoPath)) {
        $mime = function_exists('mime_content_type') ? (mime_content_type($logoPath) ?: 'image/png') : 'image/png';
        $logoData = 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($logoPath));
    }

    $h = function ($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    };

    $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
        . '@page{margin:14mm;}'
        . 'body{font-family:DejaVu Sans,Arial,sans-serif;font-size:10pt;color:#222;}'
        . '.header{text-align:center;border-bottom:2px solid #000;padding-bottom:6px;margin-bottom:10px;}'
        . '.header img{height:70px;float:left;}'
        . '.header h2{margin:0;font-size:14pt;}'
        . '.header h3{margin:2px 0;font-size:12pt;}'
        . '.meta{margin:6px 0 10px;font-size:9pt;color:#444;}'
        . 'table{width:100%;border-collapse:collapse;}'
        . 'th,td{border:1px solid #555;padding:4px 6px;vertical-align:top;font-size:9pt;}'
        . 'th{background:#eee;}'
        . '.sign{margin-top:30px;width:100%;}'
        . '.sign td{border:none;text-align:right;padding:0;}'
        . '</style></head><body>';
    $html .= '<div class="header">';
    if ($logoData) {
        $html .= '<img src="' . $logoData . '">';
    }
    $html .= '<h2>' . $h(strtoupper((string)($school_profile['nama_madrasah'] ?? 'MADRASAH'))) . '</h2>';
    $html .= '<h3>' . $h(strtoupper($laporan_title)) . '</h3>';
    $html .= '<div class="meta">Tahun Ajaran ' . $h($filter_ta) . ' &middot; ' . $h($filter_sem) . '</div>';
    $html .= '</div>';
    $html .= '<table><thead><tr><th>No</th>';
    foreach ($headers as $hd) {
        $html .= '<th>' . $h($hd) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    if ($rows) {
        foreach ($rows as $i => $row) {
            $html .= '<tr><td>' . ($i + 1) . '</td>';
            foreach ($row as $cell) {
                $html .= '<td>' . nl2br($h($cell)) . '</td>';
            }
            $html .= '</tr>';
        }
    } else {
        $html .= '<tr><td colspan="' . (count($headers) + 1) . '" style="text-align:center;">Tidak ada data.</td></tr>';
    }
    $html .= '</tbody></table>';
    $html .= '<table class="sign"><tr><td>';
    $html .= '<p>' . $h((string)($school_profile['tempat_jadwal'] ?? 'Padang')) . ', ' . date('d F Y') . '</p>';
    $html .= '<p>Kepala Madrasah,</p><br><br><br>';
    $html .= '<p><strong>' . $h((string)($school_profile['nama_kepala'] ?? '-')) . '</strong><br>NIP. ' . $h((string)($school_profile['nip_kepala'] ?? '-')) . '</p>';
    $html .= '</td></tr></table>';
    $html .= '</body></html>';

    $dompdf = new Dompdf\Dompdf(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true]);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();
    $dompdf->stream($jenis . '_' . str_replace('/', '-', $filter_ta) . '.pdf', ['Attachment' => false]);
    exit;
}

$css_libs = ['https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'];
$js_libs = [
    'assets/js/supervisi.js',
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
];

$js_page = [];
$js_page[] = <<<'JS'
$(document).ready(function () {
    SV.autoSubmitFilters('form');
    SV.initDataTable('#table-laporan');
    $('#btn-excel').on('click', function () { SV.exportExcel('table-laporan', $('#laporanTitle').val(), 'laporan_supervisi', true); });
    $('#btn-print').on('click', function () { SV.printPdf('table-laporan', $('#laporanTitle').val(), true); });
    $('#form-pdf').on('submit', function () { $(this).append('<input type="hidden" name="export" value="pdf">'); });
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
            <input type="hidden" id="svAcademicYear" value="<?= htmlspecialchars($filter_ta, ENT_QUOTES) ?>">
            <input type="hidden" id="svSemester" value="<?= htmlspecialchars($filter_sem, ENT_QUOTES) ?>">
            <input type="hidden" id="svHeadName" value="<?= htmlspecialchars($school_profile['nama_kepala'] ?? '-', ENT_QUOTES) ?>">
            <input type="hidden" id="svHeadNip" value="<?= htmlspecialchars($school_profile['nip_kepala'] ?? '-', ENT_QUOTES) ?>">
            <input type="hidden" id="svPrintPlace" value="<?= htmlspecialchars($school_profile['tempat_jadwal'] ?? 'Padang', ENT_QUOTES) ?>">
            <input type="hidden" id="svPrintDate" value="<?= date('d F Y') ?>">
            <input type="hidden" id="laporanTitle" value="<?= htmlspecialchars($laporan_title, ENT_QUOTES) ?>">

            <div class="card">
                <div class="card-body">
                    <form method="GET" class="form-row align-items-end">
                        <div class="form-group col-md-4 mb-2">
                            <label class="small font-weight-bold">Jenis Laporan</label>
                            <select class="form-control" name="jenis">
                                <?php foreach ($laporan_list as $key => $label): ?>
                                    <option value="<?= htmlspecialchars($key, ENT_QUOTES) ?>" <?= $key === $jenis ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-3 mb-2">
                            <label class="small font-weight-bold">Tahun Ajaran</label>
                            <select class="form-control" name="tahun_ajaran">
                                <?php foreach (sv_tahun_ajaran_options($pdo) as $ta): ?><option value="<?= htmlspecialchars($ta, ENT_QUOTES) ?>" <?= $ta === $filter_ta ? 'selected' : '' ?>><?= htmlspecialchars($ta) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2 mb-2">
                            <label class="small font-weight-bold">Semester</label>
                            <select class="form-control" name="semester">
                                <option value="">Semua</option>
                                <?php foreach (sv_semester_options() as $s): ?><option value="<?= htmlspecialchars($s, ENT_QUOTES) ?>" <?= $s === $filter_sem ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-3 mb-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-eye"></i> Preview</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4><?= htmlspecialchars($laporan_title) ?></h4>
                    <div class="card-header-action">
                        <button class="btn btn-success" id="btn-excel" type="button"><i class="fas fa-file-excel"></i> Excel</button>
                        <button class="btn btn-warning" id="btn-print" type="button"><i class="fas fa-print"></i> Cetak</button>
                        <form method="POST" action="laporan_supervisi.php" id="form-pdf" class="d-inline">
                            <input type="hidden" name="jenis" value="<?= htmlspecialchars($jenis, ENT_QUOTES) ?>">
                            <input type="hidden" name="tahun_ajaran" value="<?= htmlspecialchars($filter_ta, ENT_QUOTES) ?>">
                            <input type="hidden" name="semester" value="<?= htmlspecialchars($filter_sem, ENT_QUOTES) ?>">
                            <button type="submit" class="btn btn-danger"><i class="fas fa-file-pdf"></i> PDF</button>
                        </form>
                    </div>
                </div>
                <div class="card-body">
                    <div class="text-center mb-3">
                        <h5 class="mb-0"><?= htmlspecialchars(strtoupper((string)($school_profile['nama_madrasah'] ?? 'MADRASAH'))) ?></h5>
                        <div class="text-muted">Tahun Ajaran <?= htmlspecialchars($filter_ta) ?> &middot; <?= htmlspecialchars($filter_sem) ?></div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered" id="table-laporan">
                            <thead>
                                <tr>
                                    <th width="5%">No</th>
                                    <?php foreach ($headers as $hd): ?><th><?= htmlspecialchars($hd) ?></th><?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($rows): foreach ($rows as $i => $row): ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <?php foreach ($row as $cell): ?><td><?= nl2br(htmlspecialchars((string)$cell)) ?></td><?php endforeach; ?>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="<?= count($headers) + 1 ?>" class="text-center text-muted">Tidak ada data.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
<?php include '../templates/footer.php'; ?>
