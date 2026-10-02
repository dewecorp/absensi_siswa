<?php
// Halaman Pratinjau Paket Bank Soal (pengganti modal — leluasa dibaca & dicetak).
// Paket file upload  -> tampilkan format file asli (PDF/DOCX/XLS/XLSX/TXT).
// Paket generator AI -> tampilkan tabel Kisi-Kisi + Daftar Butir Soal.
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';
require_once '../config/ai_helper.php';

if (!isAuthorized(['guru', 'wali'])) {
    redirect('../login.php');
}

$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$kode_paket = trim((string)($_GET['kode_paket'] ?? ''));
if ($kode_paket === '') {
    exit('Kode paket tidak valid.');
}

ensure_learning_schema($pdo);
ai_helper_schema($pdo);

$session_q = isset($_GET['session_type']) ? '?session_type=' . urlencode((string)$_GET['session_type']) : '';
$session_amp = $session_q ? '&' . ltrim($session_q, '?') : '';

$st = $pdo->prepare("
    SELECT b.*, m.nama_mapel, k.nama_kelas
    FROM tb_bank_soal b
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = b.id_mapel
    LEFT JOIN tb_kelas k ON k.id_kelas = b.id_kelas
    WHERE b.kode_paket = ? AND b.id_guru = ?
    ORDER BY b.id ASC
");
$st->execute([$kode_paket, $guru_id]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    exit('Paket soal tidak ditemukan.');
}

$first = $rows[0];
$judul = trim((string)($first['jenis_asesmen'] ?? '')) !== '' ? $first['jenis_asesmen'] : 'Paket Soal';
$topik = trim((string)($first['topik'] ?? ''));
$mapel_nama = trim((string)($first['nama_mapel'] ?? '-'));
$kelas_nama = trim((string)($first['nama_kelas'] ?? '-'));

// File asli bila paket berasal dari upload manual
$file_stored = null;
foreach ($rows as $r) {
    if (trim((string)($r['file_soal'] ?? '')) !== '') {
        $file_stored = trim((string)$r['file_soal']);
        break;
    }
}
$is_file = false;
$file_path = null;
$ext = '';
$base64_data = '';
$filesize_formatted = '';
if ($file_stored !== null) {
    $file_path = resolve_guru_file_path('bank_soal', $file_stored);
    if (is_file($file_path)) {
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $raw = (string)@file_get_contents($file_path);
        if ($raw !== '' && strlen($raw) <= 12 * 1024 * 1024) {
            $is_file = true;
            $base64_data = base64_encode($raw);
            $filesize_formatted = number_format(filesize($file_path) / 1024, 1) . ' KB';
        }
    }
}

// Parser tabel Menjodohkan (ringkas, lokal halaman ini)
function pv_split_menjodohkan(string $pertanyaan): array {
    $lines = preg_split('/\r\n|\r|\n/', trim($pertanyaan));
    $soal = [];
    $tabel = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('/^(\d+)[\.\)]\s*(.*?)\s*\|\s*([A-Za-z])[\.\)]\s*(.*)$/u', $line, $m)) {
            $tabel[] = ['no' => (int)$m[1], 'kiri' => trim($m[2]), 'huruf' => strtoupper(trim($m[3])), 'kanan' => trim($m[4])];
        } elseif (preg_match_all('/(?:^|\s+)(\d+)[\.\)]\s*([^|]+?)\s*\|\s*([A-Za-z])[\.\)]\s*(.*?)(?=(?:\s+\d+[\.\)]|$))/u', $line, $all, PREG_SET_ORDER)) {
            $fp = strpos($line, $all[0][0]);
            if ($fp > 0) {
                $pfx = trim(substr($line, 0, $fp));
                if ($pfx !== '') $soal[] = $pfx;
            }
            foreach ($all as $am) {
                $tabel[] = ['no' => (int)$am[1], 'kiri' => trim($am[2]), 'huruf' => strtoupper(trim($am[3])), 'kanan' => trim($am[4])];
            }
        } else {
            $soal[] = $line;
        }
    }
    $clean = trim(implode("\n", $soal));
    if ($clean === '') $clean = 'Jodohkan pernyataan pada kolom kiri dengan pilihan yang sesuai pada kolom kanan:';
    return [$clean, $tabel];
}

// Susun items + kisi (mode generator)
$items = [];
$kisi = [];
$has_kisi = false;
if (!$is_file) {
    foreach ($rows as $r) {
        $ind0 = trim((string)($r['indikator'] ?? ''));
        $cp0 = trim((string)($r['cp'] ?? ''));
        $tp0 = trim((string)($r['tp'] ?? ''));
        if ($ind0 !== '' && $ind0 !== '-' && (($cp0 !== '' && $cp0 !== '-') || ($tp0 !== '' && $tp0 !== '-'))) {
            $has_kisi = true;
            break;
        }
    }
    foreach ($rows as $r) {
        $opsi_arr = [];
        $tabel_arr = [];
        if (!empty($r['pilihan_jawaban'])) {
            $pj = json_decode((string)$r['pilihan_jawaban'], true) ?: [];
            if (isset($pj[0]['no']) || isset($pj[0]['kiri'])) $tabel_arr = $pj;
            else $opsi_arr = $pj;
        }
        $tanya = (string)($r['pertanyaan'] ?? '');
        if (($r['jenis_soal'] ?? '') === 'Menjodohkan') {
            if (empty($tabel_arr)) {
                [$tanya, $tabel_arr] = pv_split_menjodohkan($tanya);
            } else {
                [$tanya] = pv_split_menjodohkan($tanya);
            }
        }
        $materi_item = trim((string)($r['topik'] ?? ($r['materi_tp'] ?? '-')));
        $cp_v = trim((string)($r['cp'] ?? ''));
        $tp_v = trim((string)($r['tp'] ?? ''));
        if ($cp_v === '' || $cp_v === '-') $cp_v = 'Memahami dan menguasai materi ' . $materi_item . ' sesuai capaian pembelajaran kurikulum.';
        if ($tp_v === '' || $tp_v === '-') $tp_v = 'Menganalisis, mengidentifikasi, dan menerapkan konsep ' . $materi_item . ' dalam pemecahan masalah.';
        $items[] = [
            'bentuk' => $r['jenis_soal'] ?? 'Pilihan Ganda',
            'level' => $r['level_kognitif'] ?? 'L2',
            'sulit' => $r['tingkat_kesulitan'] ?? 'Sedang',
            'tanya' => $tanya,
            'opsi' => $opsi_arr,
            'tabel' => $tabel_arr,
            'kunci' => $r['jawaban_benar'] ?? '',
            'bahas' => $r['pembahasan'] ?? '',
        ];
        if ($has_kisi) {
            if (($r['jenis_soal'] ?? '') === 'Menjodohkan' && !empty($tabel_arr)) {
                foreach ($tabel_arr as $si => $tr) {
                    $kk = trim((string)($tr['kiri'] ?? ''));
                    $kn = trim((string)($tr['kanan'] ?? ''));
                    $kisi[] = [
                        'no' => count($kisi) + 1, 'materi' => $materi_item, 'cp' => $cp_v, 'tp' => $tp_v,
                        'indikator' => 'Peserta didik dapat menjodohkan ' . ($kk !== '' ? $kk : 'butir ke-' . ($si + 1)) . ' dengan ' . ($kn !== '' ? $kn : 'pasangannya yang tepat') . '.',
                        'bentuk' => 'Menjodohkan', 'level' => $r['level_kognitif'] ?? 'L2',
                        'sulit' => $r['tingkat_kesulitan'] ?? 'Sedang', 'bobot' => 1,
                    ];
                }
            } else {
                $kisi[] = [
                    'no' => count($kisi) + 1, 'materi' => $materi_item, 'cp' => $cp_v, 'tp' => $tp_v,
                    'indikator' => trim((string)($r['indikator'] ?? '')) !== '' ? $r['indikator'] : '-',
                    'bentuk' => $r['jenis_soal'] ?? 'Pilihan Ganda', 'level' => $r['level_kognitif'] ?? 'L2',
                    'sulit' => $r['tingkat_kesulitan'] ?? 'Sedang', 'bobot' => (float)($r['bobot'] ?? 1),
                ];
            }
        }
    }
}

$page_title = 'Pratinjau: ' . $judul . ($topik !== '' ? ' — ' . $topik : '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.7.2/css/all.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.4.21/mammoth.browser.min.js"></script>
    <style>
        html, body { margin: 0; padding: 0; min-height: 100%; background: #525659; font-family: Arial, Helvetica, sans-serif; color: #333; }
        .preview-toolbar { position: fixed; top: 0; left: 0; right: 0; min-height: 58px; background: #1e293b; color: #ffffff; display: flex; align-items: center; justify-content: space-between; padding: 8px 16px; z-index: 1000; box-shadow: 0 2px 8px rgba(0,0,0,0.25); flex-wrap: wrap; gap: 8px; }
        .preview-toolbar .title-area { display: flex; align-items: center; gap: 12px; overflow: hidden; white-space: nowrap; }
        .preview-toolbar .doc-title { font-size: 15px; font-weight: 700; color: #f8fafc; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 480px; }
        .preview-toolbar .btn-action { font-size: 13px; font-weight: 600; padding: 6px 12px; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px; }
        .preview-viewport { margin-top: 70px; min-height: calc(100vh - 70px); padding: 24px 16px 60px; display: flex; flex-direction: column; align-items: center; }
        .document-paper { background: #ffffff; box-shadow: 0 6px 24px rgba(0,0,0,0.35); border-radius: 4px; width: 100%; max-width: 960px; min-height: 400px; padding: 40px 48px; margin-bottom: 24px; box-sizing: border-box; color: #1e293b; }
        .document-paper h3 { color: #0f172a; font-weight: 700; text-align: center; }
        .document-paper h4 { color: #0f172a; font-weight: 700; margin-top: 18px; margin-bottom: 8px; border-bottom: 1px solid #cbd5e1; padding-bottom: 6px; }
        .document-paper p { line-height: 1.7; font-size: 14.5px; margin-bottom: 12px; }
        .document-paper table { width: 100%; border-collapse: collapse; margin: 14px 0; font-size: 13px; }
        .document-paper table th, .document-paper table td { border: 1px solid #94a3b8; padding: 6px 10px; vertical-align: top; }
        .document-paper table th { background: #f1f5f9; font-weight: 700; }
        .document-paper ol.soal-list { padding-left: 22px; }
        .document-paper ol.soal-list > li { margin-bottom: 16px; }
        .pdf-canvas { display: block; margin: 0 auto 16px; box-shadow: 0 4px 18px rgba(0,0,0,0.35); background: #ffffff; max-width: 100%; height: auto; }
        .loading-spinner { color: #f8fafc; font-size: 16px; font-weight: 600; text-align: center; padding: 60px 0; }
        .sheet-tabs { margin-bottom: 16px; display: flex; gap: 6px; flex-wrap: wrap; }
        .sheet-tab-btn { font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 4px; border: 1px solid #cbd5e1; background: #f8fafc; color: #334155; cursor: pointer; }
        .sheet-tab-btn.active { background: #2563eb; color: #fff; border-color: #2563eb; }
        @media print {
            body { background: #ffffff !important; }
            .preview-toolbar { display: none !important; }
            .preview-viewport { margin-top: 0 !important; padding: 0 !important; }
            .document-paper { box-shadow: none !important; border-radius: 0 !important; padding: 0 !important; max-width: 100% !important; margin: 0 !important; }
            .pdf-canvas { box-shadow: none !important; page-break-after: always; max-width: 100% !important; }
            .sheet-tabs { display: none !important; }
        }
    </style>
</head>
<body>

    <div class="preview-toolbar">
        <div class="title-area">
            <a href="bank_soal.php<?= $session_q ?>" class="btn btn-sm btn-outline-light btn-action" title="Kembali ke Bank Soal">
                <i class="fas fa-arrow-left"></i> Kembali
            </a>
            <?php if ($is_file): ?>
                <span class="badge badge-info px-2 py-1"><?= strtoupper(htmlspecialchars($ext)) ?> ASLI</span>
            <?php else: ?>
                <span class="badge badge-success px-2 py-1">GENERATOR</span>
            <?php endif; ?>
            <span class="doc-title" title="<?= htmlspecialchars($judul . ($topik !== '' ? ' — ' . $topik : '')) ?>">
                <?= htmlspecialchars($judul . ($topik !== '' ? ' — ' . $topik : '')) ?>
            </span>
            <span class="text-white-50 small d-none d-md-inline">
                <?= htmlspecialchars($mapel_nama) ?> (Kelas <?= htmlspecialchars($kelas_nama) ?>)
            </span>
        </div>
        <div class="d-flex align-items-center" style="gap: 8px;">
            <button type="button" onclick="window.print()" class="btn btn-sm btn-light btn-action font-weight-bold" title="Cetak Dokumen">
                <i class="fas fa-print"></i> Cetak
            </button>
            <?php if ($is_file): ?>
                <a href="download_bank_soal.php?kode_paket=<?= urlencode($kode_paket) ?><?= $session_amp ?>" class="btn btn-sm btn-success btn-action font-weight-bold" title="Unduh Berkas Asli">
                    <i class="fas fa-download"></i> Unduh
                </a>
            <?php else: ?>
                <div class="btn-group btn-group-sm" role="group" title="Unduh Paket">
                    <a href="ajax_generate_soal.php?aksi=unduh_paket&kode_paket=<?= urlencode($kode_paket) ?>&format=pdf<?= $session_amp ?>" target="_blank" class="btn btn-danger font-weight-bold"><i class="fas fa-file-pdf"></i> PDF</a>
                    <a href="ajax_generate_soal.php?aksi=unduh_paket&kode_paket=<?= urlencode($kode_paket) ?>&format=docx<?= $session_amp ?>" target="_blank" class="btn btn-primary font-weight-bold"><i class="fas fa-file-word"></i> Word</a>
                    <a href="ajax_generate_soal.php?aksi=unduh_paket&kode_paket=<?= urlencode($kode_paket) ?>&format=xlsx<?= $session_amp ?>" target="_blank" class="btn btn-success font-weight-bold"><i class="fas fa-file-excel"></i> Excel</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="preview-viewport" id="viewport">
        <?php if ($is_file): ?>
            <div id="loading" class="loading-spinner">
                <i class="fas fa-spinner fa-spin fa-2x mb-3 d-block text-primary"></i>
                Mempersiapkan dokumen untuk dibaca...
            </div>
            <div id="renderTarget" style="display: none; width: 100%; display: flex; flex-direction: column; align-items: center;"></div>
        <?php else: ?>
            <div class="document-paper">
                <h3><?= htmlspecialchars($judul) ?></h3>
                <p style="text-align:center;color:#64748b;font-size:13px;">
                    Mata Pelajaran: <strong><?= htmlspecialchars($mapel_nama) ?></strong> |
                    Kelas: <strong><?= htmlspecialchars($kelas_nama) ?></strong> |
                    Jumlah: <strong><?= count($kisi) > 0 ? count($kisi) : count($items) ?> butir</strong>
                </p>
                <?php if ($has_kisi && !empty($kisi)): ?>
                    <h4>Kisi-Kisi</h4>
                    <div style="overflow-x:auto;">
                    <table>
                        <thead><tr><th style="width:36px;">No</th><th>Bentuk</th><th>Materi</th><th>CP</th><th>TP</th><th>Indikator</th><th>Level</th><th>Kesulitan</th><th>Bobot</th></tr></thead>
                        <tbody>
                            <?php foreach ($kisi as $k): ?>
                                <tr>
                                    <td style="text-align:center;"><?= htmlspecialchars((string)($k['no'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($k['bentuk'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($k['materi'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($k['cp'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($k['tp'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($k['indikator'] ?? '')) ?></td>
                                    <td style="text-align:center;"><?= htmlspecialchars((string)($k['level'] ?? '')) ?></td>
                                    <td style="text-align:center;"><?= htmlspecialchars((string)($k['sulit'] ?? '')) ?></td>
                                    <td style="text-align:center;"><?= htmlspecialchars((string)($k['bobot'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
                <h4>Soal</h4>
                <ol class="soal-list">
                    <?php foreach ($items as $it): ?>
                        <li>
                            <p><strong>[<?= htmlspecialchars((string)($it['bentuk'] ?? '')) ?>][<?= htmlspecialchars((string)($it['level'] ?? '')) ?>]</strong>
                            <?= nl2br(htmlspecialchars((string)($it['tanya'] ?? ''))) ?></p>
                            <?php if (($it['bentuk'] ?? '') === 'Menjodohkan' && !empty($it['tabel'])): ?>
                                <table>
                                    <thead><tr><th style="width:36px;">No</th><th>Soal</th><th style="width:52px;">Huruf</th><th>Pilihan Jawaban</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($it['tabel'] as $tr): ?>
                                            <tr>
                                                <td style="text-align:center;"><?= htmlspecialchars((string)($tr['no'] ?? '')) ?></td>
                                                <td><?= htmlspecialchars((string)($tr['kiri'] ?? '')) ?></td>
                                                <td style="text-align:center;"><strong><?= htmlspecialchars((string)($tr['huruf'] ?? '')) ?></strong></td>
                                                <td><?= htmlspecialchars((string)($tr['kanan'] ?? '')) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php elseif (!empty($it['opsi']) && is_array($it['opsi'])): ?>
                                <ul style="list-style:none;padding-left:6px;">
                                    <?php foreach (['A', 'B', 'C', 'D'] as $ok): ?>
                                        <?php if (trim((string)($it['opsi'][$ok] ?? '')) !== ''): ?>
                                            <li><strong><?= $ok ?>.</strong> <?= htmlspecialchars((string)$it['opsi'][$ok]) ?></li>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <p><strong>Kunci:</strong> <?= htmlspecialchars((string)($it['kunci'] ?? '') !== '' ? $it['kunci'] : '-') ?></p>
                            <?php if (trim((string)($it['bahas'] ?? '')) !== ''): ?>
                                <p style="color:#64748b;"><em>Pembahasan: <?= nl2br(htmlspecialchars((string)$it['bahas'])) ?></em></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($is_file): ?>
    <script>
        var fileBase64 = "<?= $base64_data ?>";
        var fileExt = "<?= $ext ?>";
        var paketKode = "<?= htmlspecialchars($kode_paket, ENT_QUOTES, 'UTF-8') ?>";

        function base64ToUint8Array(base64) {
            var raw = window.atob(base64);
            var rawLength = raw.length;
            var array = new Uint8Array(rawLength);
            for (var i = 0; i < rawLength; i++) {
                array[i] = raw.charCodeAt(i);
            }
            return array;
        }

        function hideLoading() {
            var el = document.getElementById('loading');
            if (el) el.style.display = 'none';
            var rt = document.getElementById('renderTarget');
            if (rt) rt.style.display = 'flex';
        }

        window.addEventListener('DOMContentLoaded', function() {
            var target = document.getElementById('renderTarget');
            var uint8 = base64ToUint8Array(fileBase64);

            if (fileExt === 'pdf') {
                // 1) Coba gambar hasil render server (paling akurat, sama persis dgn PDF asli)
                fetch('ajax_generate_soal.php?aksi=preview_gambar&kode_paket=' + encodeURIComponent(paketKode))
                    .then(function(r) { return r.json(); })
                    .then(function(j) {
                        if (!j || !j.ok || !j.images || !j.images.length) throw new Error('render server gagal');
                        j.images.forEach(function(src) {
                            var img = document.createElement('img');
                            img.src = src;
                            img.className = 'pdf-canvas';
                            img.alt = 'Halaman soal';
                            target.appendChild(img);
                        });
                        hideLoading();
                    })
                    .catch(function() {
                        // 2) Cadangan: render via PDF.js di browser
                        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';
                        pdfjsLib.getDocument({ data: uint8 }).promise.then(function(pdf) {
                            var chain = Promise.resolve();
                            for (var p = 1; p <= pdf.numPages; p++) {
                                (function(pageNum) {
                                    chain = chain.then(function() {
                                        return pdf.getPage(pageNum).then(function(page) {
                                            var viewport = page.getViewport({ scale: 1.5 });
                                            var canvas = document.createElement('canvas');
                                            canvas.height = viewport.height;
                                            canvas.width = viewport.width;
                                            canvas.className = 'pdf-canvas';
                                            target.appendChild(canvas);
                                            return page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise;
                                        });
                                    });
                                })(p);
                            }
                            chain.then(function() { hideLoading(); });
                        }).catch(function(err) {
                            target.innerHTML = '<div class="document-paper"><div class="alert alert-danger">Gagal merender PDF: ' + err.message + '</div></div>';
                            hideLoading();
                        });
                    });
            }
            else if (fileExt === 'docx') {
                mammoth.convertToHtml({ arrayBuffer: uint8.buffer })
                    .then(function(result) {
                        var paper = document.createElement('div');
                        paper.className = 'document-paper';
                        paper.innerHTML = result.value;
                        target.appendChild(paper);
                        hideLoading();
                    })
                    .catch(function(err) {
                        target.innerHTML = '<div class="document-paper"><div class="alert alert-warning"><i class="fas fa-exclamation-triangle mr-2"></i>Dokumen DOCX tidak dapat dipratinjau langsung. Silakan gunakan tombol <strong>Unduh</strong> di atas.</div></div>';
                        hideLoading();
                    });
            }
            else if (fileExt === 'xls' || fileExt === 'xlsx') {
                try {
                    var workbook = XLSX.read(uint8, { type: 'array' });
                    var paper = document.createElement('div');
                    paper.className = 'document-paper';
                    var tableHolder = document.createElement('div');
                    function showSheet(sname) {
                        tableHolder.innerHTML = XLSX.utils.sheet_to_html(workbook.Sheets[sname], { editable: false });
                        var tbl = tableHolder.querySelector('table');
                        if (tbl) tbl.className = 'table table-bordered table-striped table-sm';
                    }
                    if (workbook.SheetNames.length > 1) {
                        var tabs = document.createElement('div');
                        tabs.className = 'sheet-tabs';
                        workbook.SheetNames.forEach(function(sname, idx) {
                            var b = document.createElement('button');
                            b.className = 'sheet-tab-btn' + (idx === 0 ? ' active' : '');
                            b.textContent = sname;
                            b.addEventListener('click', function() {
                                tabs.querySelectorAll('.sheet-tab-btn').forEach(function(x) { x.classList.remove('active'); });
                                b.classList.add('active');
                                showSheet(sname);
                            });
                            tabs.appendChild(b);
                        });
                        paper.appendChild(tabs);
                    }
                    paper.appendChild(tableHolder);
                    showSheet(workbook.SheetNames[0]);
                    target.appendChild(paper);
                    hideLoading();
                } catch (err) {
                    target.innerHTML = '<div class="document-paper"><div class="alert alert-warning">Spreadsheet tidak dapat dipratinjau. Silakan gunakan tombol <strong>Unduh</strong>.</div></div>';
                    hideLoading();
                }
            }
            else if (fileExt === 'txt') {
                var text = new TextDecoder('utf-8').decode(uint8);
                var paper = document.createElement('div');
                paper.className = 'document-paper';
                var pre = document.createElement('pre');
                pre.style.cssText = 'font-family: Consolas, monospace; font-size: 13.5px; line-height: 1.6; white-space: pre-wrap;';
                pre.textContent = text;
                paper.appendChild(pre);
                target.appendChild(paper);
                hideLoading();
            }
            else {
                target.innerHTML = '<div class="document-paper text-center p-5"><div class="py-4"><i class="fas fa-file-alt fa-4x text-primary mb-3"></i>'
                    + '<p class="text-muted">Berkas berformat <strong>.' + fileExt.toUpperCase() + '</strong> tidak dapat dipratinjau langsung. Silakan unduh untuk membukanya.</p></div></div>';
                hideLoading();
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>
