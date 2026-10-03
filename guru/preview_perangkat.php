<?php
// Full Page Document Previewer for Perangkat Pembelajaran (Supports PDF, DOCX, XLS, XLSX, Images, etc.)
error_reporting(E_ALL & ~E_DEPRECATED);

require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/ai_helper.php';

if (!isAuthorized(['guru', 'wali', 'admin', 'tata_usaha', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    exit('ID Perangkat tidak valid.');
}

$stmt = $pdo->prepare("
    SELECT p.*, m.nama_mapel, k.nama_kelas, g.nama_guru
    FROM tb_perangkat_pembelajaran p
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = p.id_mapel
    LEFT JOIN tb_kelas k ON k.id_kelas = p.id_kelas
    LEFT JOIN tb_guru g ON g.id_guru = p.id_guru
    WHERE p.id = ?
");
$stmt->execute([$id]);
$perangkat = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$perangkat) {
    exit('Dokumen perangkat tidak ditemukan.');
}

require_once '../config/learning_schema.php';
ensure_learning_schema($pdo);

// Perangkat hasil Generate AI tidak punya file upload: render dari kolom teks DB.
$is_teks_ai = empty($perangkat['file_path']);
$file_path = null;
$ext = '';
$base64_data = '';
$filesize_formatted = '';
if (!$is_teks_ai) {
    $file_path = resolve_guru_file_path('perangkat', $perangkat['file_path']);
    if (!is_file($file_path)) {
        exit('Berkas fisik tidak ditemukan di server.');
    }
    $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    $file_bytes = file_get_contents($file_path);
    $base64_data = base64_encode($file_bytes);
    $filesize_formatted = number_format(filesize($file_path) / 1024, 1) . ' KB';
}
$page_title = 'Baca: ' . htmlspecialchars($perangkat['judul']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?></title>
    
    <!-- CSS & FontAwesome -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.7.2/css/all.css">

    <!-- Renderers: PDF.js, SheetJS, Mammoth.js for DOCX -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.4.21/mammoth.browser.min.js"></script>

    <style>
        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
            background: #525659;
            font-family: Arial, Helvetica, sans-serif;
            color: #333;
        }
        .preview-toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 58px;
            background: #1e293b;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            z-index: 1000;
            box-shadow: 0 2px 8px rgba(0,0,0,0.25);
        }
        .preview-toolbar .title-area {
            display: flex;
            align-items: center;
            gap: 12px;
            overflow: hidden;
            white-space: nowrap;
        }
        .preview-toolbar .doc-title {
            font-size: 15px;
            font-weight: 700;
            color: #f8fafc;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 480px;
        }
        .preview-toolbar .btn-action {
            font-size: 13px;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .preview-viewport {
            margin-top: 58px;
            min-height: calc(100vh - 58px);
            padding: 24px 16px;
            display: flex;
            flex-direction: column;
            align-items: center;
            overflow-y: auto;
        }
        .document-paper {
            background: #ffffff;
            box-shadow: 0 6px 24px rgba(0,0,0,0.35);
            border-radius: 4px;
            width: 100%;
            max-width: 1250px;
            min-height: 800px;
            padding: 36px 44px;
            margin-bottom: 24px;
            box-sizing: border-box;
            transition: transform 0.15s ease-in-out;
            transform-origin: top center;
        }
        @media print {
            @page { size: 330mm 215mm landscape; margin: 12mm 15mm; }
            body { margin: 0; background: #fff !important; }
            .preview-toolbar { display: none !important; }
            .preview-viewport { margin: 0 !important; padding: 0 !important; }
            .document-paper { box-shadow: none !important; border: none !important; padding: 0 !important; max-width: 100% !important; }
        }
        /* Document Typography for Word/Docx & Text */
        .document-paper h1, .document-paper h2, .document-paper h3, .document-paper h4 {
            color: #0f172a;
            font-weight: 700;
            margin-top: 18px;
            margin-bottom: 8px;
        }
        .document-paper p {
            line-height: 1.7;
            font-size: 14.5px;
            color: #1e293b;
            margin-bottom: 12px;
        }
        .document-paper table {
            width: 100%;
            border-collapse: collapse;
            margin: 14px 0;
            font-size: 13px;
        }
        .document-paper table th, .document-paper table td {
            border: 1px solid #cbd5e1;
            padding: 6px 10px;
        }
        .document-paper table th {
            background: #f1f5f9;
            font-weight: 700;
        }
        .pdf-canvas {
            display: block;
            margin: 0 auto 16px;
            box-shadow: 0 4px 18px rgba(0,0,0,0.35);
            background: #ffffff;
            max-width: 100%;
            height: auto;
        }
        .image-container img {
            max-width: 100%;
            height: auto;
            box-shadow: 0 4px 18px rgba(0,0,0,0.35);
            border-radius: 4px;
            background: #fff;
        }
        .sheet-tabs {
            margin-bottom: 16px;
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .sheet-tab-btn {
            font-size: 12px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 4px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            color: #334155;
            cursor: pointer;
        }
        .sheet-tab-btn.active {
            background: #2563eb;
            color: #fff;
            border-color: #2563eb;
        }
        .loading-spinner {
            color: #f8fafc;
            font-size: 16px;
            font-weight: 600;
            text-align: center;
            padding: 60px 0;
        }
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

    <!-- Header Toolbar -->
    <div class="preview-toolbar">
        <div class="title-area">
            <a href="perangkat_pembelajaran.php<?= isset($_GET['session_type']) ? '?session_type=' . urlencode((string)$_GET['session_type']) : '' ?>" class="btn btn-sm btn-outline-light btn-action" title="Kembali ke Daftar Perangkat">
                <i class="fas fa-arrow-left"></i> Kembali
            </a>
            <span class="badge badge-info px-2 py-1"><?= htmlspecialchars($perangkat['jenis_perangkat']) ?></span>
            <span class="doc-title" title="<?= htmlspecialchars($perangkat['judul']) ?>">
                <?= htmlspecialchars($perangkat['judul']) ?>
            </span>
            <span class="text-secondary small d-none d-md-inline">&bull;</span>
            <span class="text-white-50 small d-none d-md-inline">
                <?= htmlspecialchars($perangkat['nama_mapel'] ?? '-') ?> (Kelas <?= htmlspecialchars($perangkat['nama_kelas'] ?? '-') ?>)<?= $is_teks_ai ? '' : ' &bull; ' . $filesize_formatted ?>
            </span>
        </div>

        <div class="d-flex align-items-center" style="gap: 8px;">
            <button type="button" id="btnZoomOut" class="btn btn-sm btn-outline-light btn-action d-none d-sm-inline-flex" title="Perkecil">
                <i class="fas fa-search-minus"></i>
            </button>
            <button type="button" id="btnZoomReset" class="btn btn-sm btn-outline-light btn-action d-none d-sm-inline-flex" style="min-width: 58px;" title="Reset Zoom">
                <span id="zoomLevelText">100%</span>
            </button>
            <button type="button" id="btnZoomIn" class="btn btn-sm btn-outline-light btn-action d-none d-sm-inline-flex" title="Perbesar">
                <i class="fas fa-search-plus"></i>
            </button>
            <button type="button" onclick="window.print()" class="btn btn-sm btn-light btn-action font-weight-bold" title="Cetak Dokumen">
                <i class="fas fa-print"></i> Cetak
            </button>
            <?php if ($is_teks_ai): ?>
                <div class="btn-group btn-group-sm" role="group" title="Unduh Dokumen">
                    <a href="download_perangkat.php?id=<?= $id ?>&format=pdf" onclick="event.preventDefault(); triggerBrowserDownload(this.href, this);" download class="btn btn-danger font-weight-bold"><i class="fas fa-file-pdf"></i> PDF</a>
                    <a href="download_perangkat.php?id=<?= $id ?>&format=docx" onclick="event.preventDefault(); triggerBrowserDownload(this.href, this);" download class="btn btn-primary font-weight-bold"><i class="fas fa-file-word"></i> Word</a>
                    <a href="download_perangkat.php?id=<?= $id ?>&format=xlsx" onclick="event.preventDefault(); triggerBrowserDownload(this.href, this);" download class="btn btn-success font-weight-bold"><i class="fas fa-file-excel"></i> Excel</a>
                </div>
            <?php else: ?>
                <a href="download_perangkat.php?id=<?= $id ?>" class="btn btn-sm btn-success btn-action font-weight-bold" title="Unduh Berkas">
                    <i class="fas fa-download"></i> Unduh
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Viewport Container -->
    <div class="preview-viewport" id="viewport">
        <?php if ($is_teks_ai): ?>
            <?php
            $isi_raw = trim((string)($perangkat['isi_dokumen'] ?? ''));
            if ($isi_raw === '') {
                // Data lama hasil generate yang isi_dokumen-nya kosong: susun dari field terpisah
                $susunan = [];
                foreach ([
                    'A. CAPAIAN PEMBELAJARAN (CP)' => $perangkat['cp'] ?? '',
                    'B. TUJUAN PEMBELAJARAN (TP)' => $perangkat['tp'] ?? '',
                    'C. MATERI POKOK' => trim(trim((string)($perangkat['materi_tp'] ?? '')) . "\n" . trim((string)($perangkat['materi'] ?? ''))),
                    'D. TUJUAN PEMBELAJARAN KHUSUS' => $perangkat['tujuan_pembelajaran'] ?? '',
                    'E. INDIKATOR KETERCAPAIAN' => $perangkat['indikator'] ?? '',
                    'F. DESKRIPSI / CATATAN' => $perangkat['deskripsi'] ?? '',
                ] as $jdl => $val) {
                    $val = trim((string)$val);
                    if ($val !== '') $susunan[] = $jdl . "\n" . $val;
                }
                $isi_raw = implode("\n\n", $susunan);
            }
            ?>
            <div class="document-paper" id="paperTarget">
                <h2 style="text-align:center;font-weight:800;color:#0f172a;margin-bottom:6px;"><?= htmlspecialchars($perangkat['judul']) ?></h2>
                <p style="text-align:center;color:#64748b;font-size:13px;margin-bottom:24px;">
                    <span class="badge badge-primary px-2 py-1 mr-1"><?= htmlspecialchars($perangkat['jenis_perangkat']) ?></span>
                    <?= !empty($perangkat['nama_mapel']) ? htmlspecialchars($perangkat['nama_mapel']) : '' ?>
                    <?= !empty($perangkat['nama_kelas']) ? ' &bull; Kelas ' . htmlspecialchars($perangkat['nama_kelas']) : '' ?>
                    <?= !empty($perangkat['semester']) ? ' &bull; ' . htmlspecialchars($perangkat['semester']) : '' ?>
                    <?= !empty($perangkat['tahun_ajaran']) ? ' &bull; ' . htmlspecialchars($perangkat['tahun_ajaran']) : '' ?>
                    <?= !empty($perangkat['nama_guru']) ? ' &bull; Guru: ' . htmlspecialchars($perangkat['nama_guru']) : '' ?>
                </p>

                <!-- Komponen Hasil Generator AI -->
                <div class="perangkat-komponen mb-4">
                    <?php
                    $komponen = [
                        ['Capaian Pembelajaran (CP)', $perangkat['cp'] ?? '', 'fas fa-bullseye'],
                        ['Tujuan Pembelajaran (TP)', $perangkat['tp'] ?? '', 'fas fa-flag-checkered'],
                        ['Materi Pokok / Pembelajaran', trim(trim((string)($perangkat['materi_tp'] ?? '')) . "\n" . trim((string)($perangkat['materi'] ?? ''))), 'fas fa-book-open'],
                        ['Tujuan Pembelajaran Khusus', $perangkat['tujuan_pembelajaran'] ?? '', 'fas fa-check-circle'],
                        ['Indikator Ketercapaian', $perangkat['indikator'] ?? '', 'fas fa-tasks'],
                        ['Deskripsi Ringkas', $perangkat['deskripsi'] ?? '', 'fas fa-comment-alt'],
                    ];
                    foreach ($komponen as $item):
                        $val = trim((string)$item[1]);
                        if ($val === '' || $val === '-') continue;
                    ?>
                        <div class="mb-3" style="background:#ffffff;border:1px solid #cbd5e1;border-radius:6px;overflow:hidden;">
                            <div style="background:#f1f5f9;border-bottom:1px solid #cbd5e1;padding:8px 12px;font-size:12.5px;font-weight:800;color:#1e3a8a;text-transform:uppercase;letter-spacing:0.3px;">
                                <i class="<?= $item[2] ?> mr-1 text-primary"></i> <?= htmlspecialchars($item[0]) ?>
                            </div>
                            <div style="padding:12px 14px;font-size:13.5px;line-height:1.65;color:#0f172a;white-space:pre-wrap;background:#ffffff;">
                                <?= htmlspecialchars($val) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Isi Dokumen Seutuhnya -->
                <div class="mb-3" style="background:#ffffff;border:1px solid #cbd5e1;border-radius:6px;overflow:hidden;">
                    <div style="background:#0f172a;color:#ffffff;padding:10px 14px;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:0.5px;display:flex;align-items:center;justify-content:space-between;">
                        <span><i class="fas fa-file-alt mr-2 text-warning"></i> Isi Dokumen Lengkap</span>
                        <span style="font-size:11px;color:#94a3b8;font-weight:600;">Hasil Generasi AI</span>
                    </div>
                    <div style="padding:18px 20px;font-size:14px;line-height:1.7;color:#0f172a;background:#ffffff;">
                        <?= ai_format_perangkat_html($isi_raw, false) ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div id="loading" class="loading-spinner">
                <i class="fas fa-spinner fa-spin fa-2x mb-3 d-block text-primary"></i>
                Mempersiapkan dokumen untuk dibaca...
            </div>
            <div id="renderTarget" style="display: none; width: 100%; display: flex; flex-direction: column; align-items: center;"></div>
        <?php endif; ?>
    </div>

    <script>
        var currentZoom = 1.0;
        function updateZoomDisplay() {
            var pct = Math.round(currentZoom * 100) + '%';
            var zEl = document.getElementById('zoomLevelText');
            if (zEl) zEl.textContent = pct;
            var target = document.getElementById('renderTarget') || document.getElementById('paperTarget');
            if (target) {
                target.style.transform = 'scale(' + currentZoom + ')';
                target.style.transformOrigin = 'top center';
            }
        }
        var btnIn = document.getElementById('btnZoomIn');
        if (btnIn) {
            btnIn.addEventListener('click', function() {
                if (currentZoom < 2.0) {
                    currentZoom += 0.15;
                    updateZoomDisplay();
                }
            });
        }
        var btnOut = document.getElementById('btnZoomOut');
        if (btnOut) {
            btnOut.addEventListener('click', function() {
                if (currentZoom > 0.6) {
                    currentZoom -= 0.15;
                    updateZoomDisplay();
                }
            });
        }
        var btnReset = document.getElementById('btnZoomReset');
        if (btnReset) {
            btnReset.addEventListener('click', function() {
                currentZoom = 1.0;
                updateZoomDisplay();
            });
        }

        function triggerBrowserDownload(url, btnElement) {
            var oldText = btnElement.innerHTML;
            btnElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            btnElement.style.pointerEvents = 'none';

            fetch(url)
                .then(function(res) {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    var disp = res.headers.get('Content-Disposition') || '';
                    var fname = '';
                    var m = /filename="?([^";]+)"?/i.exec(disp);
                    if (m) fname = m[1].trim();
                    return res.blob().then(function(b) { return { blob: b, fname: fname }; });
                })
                .then(function(data) {
                    var blobUrl = window.URL.createObjectURL(data.blob);
                    var a = document.createElement('a');
                    a.style.display = 'none';
                    a.href = blobUrl;
                    if (data.fname) a.download = data.fname;
                    document.body.appendChild(a);
                    a.click();
                    setTimeout(function() {
                        window.URL.revokeObjectURL(blobUrl);
                        a.remove();
                    }, 500);
                })
                .catch(function(err) {
                    window.location.href = url;
                })
                .finally(function() {
                    btnElement.innerHTML = oldText;
                    btnElement.style.pointerEvents = 'auto';
                });
        }
    </script>

    <?php if (!$is_teks_ai): ?>
    <script>
        var fileBase64 = "<?= $base64_data ?>";
        var fileExt = "<?= $ext ?>";
        var currentZoom = 1.0;

        // Convert base64 to Uint8Array
        function base64ToUint8Array(base64) {
            var raw = window.atob(base64);
            var rawLength = raw.length;
            var array = new Uint8Array(rawLength);
            for (var i = 0; i < rawLength; i++) {
                array[i] = raw.charCodeAt(i);
            }
            return array;
        }

        function updateZoomDisplay() {
            var pct = Math.round(currentZoom * 100) + '%';
            document.getElementById('zoomLevelText').textContent = pct;
            var target = document.getElementById('renderTarget');
            if (target) {
                target.style.transform = 'scale(' + currentZoom + ')';
                target.style.transformOrigin = 'top center';
            }
        }

        document.getElementById('btnZoomIn').addEventListener('click', function() {
            if (currentZoom < 2.0) {
                currentZoom += 0.15;
                updateZoomDisplay();
            }
        });
        document.getElementById('btnZoomOut').addEventListener('click', function() {
            if (currentZoom > 0.5) {
                currentZoom -= 0.15;
                updateZoomDisplay();
            }
        });
        document.getElementById('btnZoomReset').addEventListener('click', function() {
            currentZoom = 1.0;
            updateZoomDisplay();
        });

        function hideLoading() {
            var el = document.getElementById('loading');
            if (el) el.style.display = 'none';
            var rt = document.getElementById('renderTarget');
            if (rt) rt.style.display = 'flex';
        }

        window.addEventListener('DOMContentLoaded', function() {
            var target = document.getElementById('renderTarget');
            var uint8 = base64ToUint8Array(fileBase64);

            // 1. PDF DOCUMENT
            if (fileExt === 'pdf') {
                pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';
                pdfjsLib.getDocument({ data: uint8 }).promise.then(function(pdf) {
                    var totalPages = pdf.numPages;
                    var chain = Promise.resolve();

                    for (var p = 1; p <= totalPages; p++) {
                        (function(pageNum) {
                            chain = chain.then(function() {
                                return pdf.getPage(pageNum).then(function(page) {
                                    var scale = 1.5;
                                    var viewport = page.getViewport({ scale: scale });
                                    var canvas = document.createElement('canvas');
                                    var ctx = canvas.getContext('2d');
                                    canvas.height = viewport.height;
                                    canvas.width = viewport.width;
                                    canvas.className = 'pdf-canvas';
                                    target.appendChild(canvas);

                                    return page.render({
                                        canvasContext: ctx,
                                        viewport: viewport
                                    }).promise;
                                });
                            });
                        })(p);
                    }

                    chain.then(function() {
                        hideLoading();
                    });
                }).catch(function(err) {
                    target.innerHTML = '<div class="alert alert-danger">Gagal merender PDF: ' + err.message + '</div>';
                    hideLoading();
                });
            }

            // 2. WORD / DOCX DOCUMENT (Mammoth.js)
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
                        target.innerHTML = '<div class="document-paper"><div class="alert alert-warning"><i class="fas fa-exclamation-triangle mr-2"></i>Dokumen DOCX tidak dapat dipratinjau langsung. Silakan gunakan tombol <strong>Unduh</strong> di atas untuk membaca dokumen ini.</div></div>';
                        hideLoading();
                    });
            }

            // 3. EXCEL / SPREADSHEET (XLS, XLSX, CSV)
            else if (fileExt === 'xls' || fileExt === 'xlsx' || fileExt === 'csv') {
                try {
                    // Check if file is HTML table disguised as .xls
                    var textCheck = new TextDecoder('utf-8').decode(uint8.slice(0, 1000));
                    if (textCheck.indexOf('<table') !== -1 || textCheck.indexOf('<html') !== -1) {
                        var fullHtmlText = new TextDecoder('utf-8').decode(uint8);
                        var paper = document.createElement('div');
                        paper.className = 'document-paper';
                        paper.innerHTML = fullHtmlText;
                        target.appendChild(paper);
                        hideLoading();
                        return;
                    }

                    // Otherwise read as binary spreadsheet via SheetJS
                    var workbook = XLSX.read(uint8, { type: 'array' });
                    var sheetNames = workbook.SheetNames;

                    var paper = document.createElement('div');
                    paper.className = 'document-paper';

                    if (sheetNames.length > 1) {
                        var tabsDiv = document.createElement('div');
                        tabsDiv.className = 'sheet-tabs';
                        sheetNames.forEach(function(sname, idx) {
                            var btn = document.createElement('button');
                            btn.className = 'sheet-tab-btn' + (idx === 0 ? ' active' : '');
                            btn.textContent = sname;
                            btn.addEventListener('click', function() {
                                document.querySelectorAll('.sheet-tab-btn').forEach(function(b) { b.classList.remove('active'); });
                                btn.classList.add('active');
                                showSheetTable(workbook, sname, tableHolder);
                            });
                            tabsDiv.appendChild(btn);
                        });
                        paper.appendChild(tabsDiv);
                    }

                    var tableHolder = document.createElement('div');
                    tableHolder.className = 'table-responsive';
                    paper.appendChild(tableHolder);

                    function showSheetTable(wb, sname, holder) {
                        var worksheet = wb.Sheets[sname];
                        var htmlTable = XLSX.utils.sheet_to_html(worksheet, { id: 'sheetTable', editable: false });
                        holder.innerHTML = htmlTable;
                        var tbl = holder.querySelector('table');
                        if (tbl) {
                            tbl.className = 'table table-bordered table-striped table-sm';
                        }
                    }

                    showSheetTable(workbook, sheetNames[0], tableHolder);
                    target.appendChild(paper);
                    hideLoading();
                } catch(err) {
                    target.innerHTML = '<div class="document-paper"><div class="alert alert-warning"><i class="fas fa-exclamation-triangle mr-2"></i>Format berkas spreadsheet ini tidak dapat dipratinjau langsung. Silakan gunakan tombol <strong>Unduh</strong> di atas.</div></div>';
                    hideLoading();
                }
            }

            // 4. IMAGES (JPG, PNG, GIF, WEBP)
            else if (['jpg', 'jpeg', 'png', 'gif', 'webp'].indexOf(fileExt) !== -1) {
                var mime = 'image/' + (fileExt === 'jpg' ? 'jpeg' : fileExt);
                var imgContainer = document.createElement('div');
                imgContainer.className = 'image-container text-center';
                var img = document.createElement('img');
                img.src = 'data:' + mime + ';base64,' + fileBase64;
                img.onload = function() { hideLoading(); };
                imgContainer.appendChild(img);
                target.appendChild(imgContainer);
            }

            // 5. TEXT / CODE
            else if (['txt', 'csv', 'log', 'md'].indexOf(fileExt) !== -1) {
                var text = new TextDecoder('utf-8').decode(uint8);
                var paper = document.createElement('div');
                paper.className = 'document-paper';
                paper.innerHTML = '<pre style="font-family: Consolas, monospace; font-size: 13.5px; line-height: 1.6; white-space: pre-wrap;">' + $('<div>').text(text).html() + '</pre>';
                target.appendChild(paper);
                hideLoading();
            }

            // 6. OTHER (DOC, PPT, PPTX, ZIP)
            else {
                var paper = document.createElement('div');
                paper.className = 'document-paper text-center p-5';
                paper.innerHTML = '<div class="py-4"><i class="fas fa-file-alt fa-4x text-primary mb-3"></i>' +
                    '<h4 class="font-weight-bold text-dark"><?= htmlspecialchars(addslashes($perangkat['judul'])) ?></h4>' +
                    '<p class="text-muted">Berkas berformat <strong>.' + fileExt.toUpperCase() + '</strong> dapat langsung dibaca dengan mengunduh atau membuka aplikasi terkait di perangkat Anda.</p>' +
                    '<a href="download_perangkat.php?id=<?= $id ?>" class="btn btn-success btn-lg px-4 font-weight-bold shadow-sm"><i class="fas fa-download mr-2"></i> Unduh dan Buka Berkas (' + fileExt.toUpperCase() + ')</a>' +
                    '</div>';
                target.appendChild(paper);
                hideLoading();
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>
