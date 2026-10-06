<?php
// Full Page Document Previewer for Tugas (baca lampiran guru / berkas kumpulan siswa di tab baru).
// Pola sama seperti preview_perangkat.php: PDF, DOCX, XLS/XLSX, gambar, teks dirender di tab baru.
error_reporting(E_ALL & ~E_DEPRECATED);

require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['guru', 'wali', 'admin', 'tata_usaha', 'kepala_madrasah', 'siswa'])) {
    redirect('../login.php');
}

$id = (int)($_GET['id'] ?? 0);
$id_pengumpulan = (int)($_GET['id_pengumpulan'] ?? 0);
$mode = trim((string)($_GET['mode'] ?? ''));
if ($id <= 0 && $id_pengumpulan <= 0) {
    exit('ID Tugas tidak valid.');
}

$user_level = getUserLevel();
$is_siswa = ($user_level === 'siswa');
$siswa_id = $is_siswa ? (int)($_SESSION['user_id'] ?? 0) : 0;

$tugas = null;
$stored = '';
$judul_doc = 'Tugas';
$sub_info = '';
$back_url = 'tugas.php';

if ($id_pengumpulan > 0) {
    // Berkas kumpullan siswa
    $st = $pdo->prepare("
        SELECT tp.*, t.judul, t.id_kelas, m.nama_mapel, k.nama_kelas, s.nama_siswa, g.nama_guru
        FROM tb_tugas_pengumpulan tp
        JOIN tb_tugas t ON t.id = tp.id_tugas
        LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = t.id_mapel
        LEFT JOIN tb_kelas k ON k.id_kelas = t.id_kelas
        LEFT JOIN tb_siswa s ON s.id_siswa = tp.id_siswa
        LEFT JOIN tb_guru g ON g.id_guru = t.id_guru
        WHERE tp.id = ?
    ");
    $st->execute([$id_pengumpulan]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        exit('Berkas pengumpulan tidak ditemukan.');
    }
    if ($is_siswa && (int)$row['id_siswa'] !== $siswa_id) {
        exit('Anda tidak berhak membaca berkas ini.');
    }
    $stored = trim((string)($row['file_path'] ?? ''));
    $judul_doc = 'Kumpulan: ' . ($row['judul'] ?? 'Tugas') . ' - ' . ($row['nama_siswa'] ?? '');
    $sub_info = ($row['nama_mapel'] ?? '-') . ' (Kelas ' . ($row['nama_kelas'] ?? '-') . ') &bull; ' . ($row['nama_siswa'] ?? '');
    $back_url = $is_siswa ? '../siswa/tugas.php' : ('detail_tugas.php?id=' . (int)$row['id_tugas']);
    $tugas_judul = (string)($row['judul'] ?? 'Tugas');
} else {
    // Lampiran soal dari guru
    $st = $pdo->prepare("
        SELECT t.*, m.nama_mapel, k.nama_kelas, g.nama_guru
        FROM tb_tugas t
        LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = t.id_mapel
        LEFT JOIN tb_kelas k ON k.id_kelas = t.id_kelas
        LEFT JOIN tb_guru g ON g.id_guru = t.id_guru
        WHERE t.id = ?
    ");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        exit('Tugas tidak ditemukan.');
    }
    if ($mode === '' && $is_siswa) {
        $mode = 'soal';
    }
    $stored = trim((string)($row['lampiran'] ?? ''));
    $judul_doc = 'Soal: ' . ($row['judul'] ?? 'Tugas');
    $sub_info = ($row['nama_mapel'] ?? '-') . ' (Kelas ' . ($row['nama_kelas'] ?? '-') . ')' . (!empty($row['nama_guru']) ? ' &bull; ' . $row['nama_guru'] : '');
    $back_url = $is_siswa ? '../siswa/tugas.php' : 'tugas.php';
    $tugas_judul = (string)($row['judul'] ?? 'Tugas');
}

if ($stored === '') {
    exit('Tidak ada berkas terlampir pada tugas ini.');
}

$file_path = resolve_guru_file_path('tugas', $stored);
if (!is_file($file_path)) {
    exit('Berkas fisik tidak ditemukan di server.');
}

$ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
$file_bytes = file_get_contents($file_path);
$base64_data = base64_encode($file_bytes);
$filesize_formatted = number_format(filesize($file_path) / 1024, 1) . ' KB';
$page_title = 'Baca: ' . $tugas_judul;
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
        html, body { margin: 0; padding: 0; height: 100%; background: #525659; font-family: Arial, Helvetica, sans-serif; color: #333; }
        .preview-toolbar { position: fixed; top: 0; left: 0; right: 0; height: 58px; background: #1e293b; color: #ffffff; display: flex; align-items: center; justify-content: space-between; padding: 0 16px; z-index: 1000; box-shadow: 0 2px 8px rgba(0,0,0,0.25); }
        .preview-toolbar .title-area { display: flex; align-items: center; gap: 12px; overflow: hidden; white-space: nowrap; }
        .preview-toolbar .doc-title { font-size: 15px; font-weight: 700; color: #f8fafc; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 480px; }
        .preview-toolbar .btn-action { font-size: 13px; font-weight: 600; padding: 6px 12px; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px; }
        .preview-viewport { margin-top: 58px; min-height: calc(100vh - 58px); padding: 24px 16px; display: flex; flex-direction: column; align-items: center; overflow-y: auto; }
        .document-paper { background: #ffffff; box-shadow: 0 6px 24px rgba(0,0,0,0.35); border-radius: 4px; width: 100%; max-width: 1250px; min-height: 800px; padding: 36px 44px; margin-bottom: 24px; box-sizing: border-box; }
        .pdf-canvas { display: block; margin: 0 auto 16px; box-shadow: 0 4px 18px rgba(0,0,0,0.35); background: #ffffff; max-width: 100%; height: auto; }
        .image-container img { max-width: 100%; height: auto; box-shadow: 0 4px 18px rgba(0,0,0,0.35); border-radius: 4px; background: #fff; }
        .sheet-tabs { margin-bottom: 16px; display: flex; gap: 6px; flex-wrap: wrap; }
        .sheet-tab-btn { font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 4px; border: 1px solid #cbd5e1; background: #f8fafc; color: #334155; cursor: pointer; }
        .sheet-tab-btn.active { background: #2563eb; color: #fff; border-color: #2563eb; }
        .loading-spinner { color: #f8fafc; font-size: 16px; font-weight: 600; text-align: center; padding: 60px 0; }
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
            <a href="<?= htmlspecialchars($back_url) ?>" class="btn btn-sm btn-outline-light btn-action" title="Kembali">
                <i class="fas fa-arrow-left"></i> Kembali
            </a>
            <span class="badge badge-info px-2 py-1"><?= strtoupper(htmlspecialchars($ext)) ?></span>
            <span class="doc-title" title="<?= htmlspecialchars($judul_doc) ?>"><?= htmlspecialchars($judul_doc) ?></span>
            <span class="text-white-50 small d-none d-md-inline"><?= $sub_info ?> &bull; <?= $filesize_formatted ?></span>
        </div>
        <div class="d-flex align-items-center" style="gap: 8px;">
            <button type="button" onclick="window.print()" class="btn btn-sm btn-light btn-action font-weight-bold" title="Cetak Dokumen">
                <i class="fas fa-print"></i> Cetak
            </button>
        </div>
    </div>

    <div class="preview-viewport" id="viewport">
        <div id="loading" class="loading-spinner">
            <i class="fas fa-spinner fa-spin fa-2x mb-3 d-block text-primary"></i>
            Mempersiapkan dokumen untuk dibaca...
        </div>
        <div id="renderTarget" style="display: none; width: 100%; display: flex; flex-direction: column; align-items: center;"></div>
    </div>

    <script>
        var fileBase64 = "<?= $base64_data ?>";
        var fileExt = "<?= $ext ?>";

        function base64ToUint8Array(base64) {
            var raw = window.atob(base64);
            var rawLength = raw.length;
            var array = new Uint8Array(rawLength);
            for (var i = 0; i < rawLength; i++) { array[i] = raw.charCodeAt(i); }
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
                    target.innerHTML = '<div class="alert alert-danger">Gagal merender PDF: ' + err.message + '</div>';
                    hideLoading();
                });
            } else if (fileExt === 'docx') {
                mammoth.convertToHtml({ arrayBuffer: uint8.buffer }).then(function(result) {
                    var paper = document.createElement('div');
                    paper.className = 'document-paper';
                    paper.innerHTML = result.value;
                    target.appendChild(paper);
                    hideLoading();
                }).catch(function() {
                    target.innerHTML = '<div class="document-paper"><div class="alert alert-warning">DOCX tidak dapat dipratinjau. Silakan unduh untuk membaca.</div></div>';
                    hideLoading();
                });
            } else if (fileExt === 'xls' || fileExt === 'xlsx' || fileExt === 'csv') {
                try {
                    var textCheck = new TextDecoder('utf-8').decode(uint8.slice(0, 1000));
                    if (textCheck.indexOf('<table') !== -1 || textCheck.indexOf('<html') !== -1) {
                        var paper = document.createElement('div');
                        paper.className = 'document-paper';
                        paper.innerHTML = new TextDecoder('utf-8').decode(uint8);
                        target.appendChild(paper);
                        hideLoading();
                        return;
                    }
                    var workbook = XLSX.read(uint8, { type: 'array' });
                    var paper2 = document.createElement('div');
                    paper2.className = 'document-paper';
                    var holder = document.createElement('div');
                    holder.className = 'table-responsive';
                    paper2.appendChild(holder);
                    var ws = workbook.Sheets[workbook.SheetNames[0]];
                    holder.innerHTML = XLSX.utils.sheet_to_html(ws, { editable: false });
                    var tbl = holder.querySelector('table');
                    if (tbl) tbl.className = 'table table-bordered table-striped table-sm';
                    target.appendChild(paper2);
                    hideLoading();
                } catch (err) {
                    target.innerHTML = '<div class="document-paper"><div class="alert alert-warning">Spreadsheet tidak dapat dipratinjau.</div></div>';
                    hideLoading();
                }
            } else if (['jpg', 'jpeg', 'png', 'gif', 'webp'].indexOf(fileExt) !== -1) {
                var mime = 'image/' + (fileExt === 'jpg' ? 'jpeg' : fileExt);
                var box = document.createElement('div');
                box.className = 'image-container text-center';
                var img = document.createElement('img');
                img.src = 'data:' + mime + ';base64,' + fileBase64;
                img.onload = function() { hideLoading(); };
                box.appendChild(img);
                target.appendChild(box);
            } else if (['txt', 'log', 'md'].indexOf(fileExt) !== -1) {
                var text = new TextDecoder('utf-8').decode(uint8);
                var paper3 = document.createElement('div');
                paper3.className = 'document-paper';
                var pre = document.createElement('pre');
                pre.style.cssText = 'font-family: Consolas, monospace; font-size: 13.5px; line-height: 1.6; white-space: pre-wrap;';
                pre.textContent = text;
                paper3.appendChild(pre);
                target.appendChild(paper3);
                hideLoading();
            } else {
                var paper4 = document.createElement('div');
                paper4.className = 'document-paper text-center p-5';
                paper4.innerHTML = '<div class="py-4"><i class="fas fa-file-alt fa-4x text-primary mb-3"></i>' +
                    '<h4 class="font-weight-bold text-dark">Format .' + fileExt.toUpperCase() + '</h4>' +
                    '<p class="text-muted">Format ini tidak dapat dipratinjau langsung di browser.</p></div>';
                target.appendChild(paper4);
                hideLoading();
            }
        });
    </script>
</body>
</html>
