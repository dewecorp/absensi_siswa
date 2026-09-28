<?php
// Preview / Print viewer for Surat Files (SIMS Integration)
error_reporting(E_ALL & ~E_DEPRECATED);

require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/endpoint_registry.php';
require_once '../config/sims_surat_helper.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha'])) {
    redirect('../login.php');
}

$file_url = trim((string)($_GET['url'] ?? ''));
$title = trim((string)($_GET['title'] ?? 'Preview Surat'));

if ($file_url === '') {
    exit('URL File Surat tidak ditemukan.');
}

// Check if URL is an HTML print page (e.g., print_surat_keluar.php or print_surat_keputusan.php)
$clean_path = parse_url($file_url, PHP_URL_PATH) ?? '';
$ext = strtolower(pathinfo($clean_path, PATHINFO_EXTENSION));
$is_html_print = ($ext === 'php' || $ext === 'html' || $ext === '' || stripos($clean_path, 'print_surat_') !== false || isset($_GET['type']));

if ($is_html_print) {
    $cfg = getInboundEndpointConfig('sims');
    $api_key = trim((string)($cfg['api_key'] ?? ''));
    $root_url = get_sims_root_url($cfg['base_url'] ?? '');
    $headers = [];
    if ($api_key !== '') {
        $headers[] = 'X-API-KEY: ' . $api_key;
    }

    $fetch_url = $file_url;
    if ($api_key !== '' && strpos($fetch_url, 'api_key=') === false) {
        $fetch_url .= (strpos($fetch_url, '?') === false ? '?' : '&') . 'api_key=' . urlencode($api_key);
    }

    [$html_body, $code, $err] = endpoint_fetch_url($fetch_url, $headers);

    if ($html_body !== false && $code === 200 && (stripos($html_body, '<html') !== false || stripos($html_body, '<!DOCTYPE') !== false)) {
        if ($root_url !== '') {
            $sims_base = rtrim($root_url, '/') . '/';
            $html_body = preg_replace_callback('#(src|href)=["\'](?!https?://|data:|javascript:|//|\#)([^"\']+)["\']#i', static function($m) use ($sims_base) {
                $attr = $m[1];
                $rel = ltrim($m[2], '/');
                return $attr . '="' . $sims_base . $rel . '"';
            }, $html_body);
        }

        if (stripos($html_body, 'window.print()') === false) {
            $print_script = '<script>window.addEventListener("load", function() { setTimeout(function() { window.print(); }, 400); });</script>';
            if (stripos($html_body, '</body>') !== false) {
                $html_body = str_ireplace('</body>', $print_script . '</body>', $html_body);
            } else {
                $html_body .= $print_script;
            }
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $html_body;
        exit;
    }
}

// Proxy mode for static files (PDF/Images): returns JSON with base64 to prevent IDM
if (isset($_GET['proxy']) && $_GET['proxy'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $cfg = getInboundEndpointConfig('sims');
    $api_key = trim((string)($cfg['api_key'] ?? ''));
    $headers = [];
    if ($api_key !== '') {
        $headers[] = 'X-API-KEY: ' . $api_key;
    }
    [$body, $code, $err] = endpoint_fetch_url($file_url, $headers);

    if ($body === false || $code !== 200) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'File tidak dapat dimuat dari SIMS (' . $code . ').']);
        exit;
    }

    $mime = 'application/pdf';
    if (in_array($ext, ['jpg', 'jpeg'], true)) $mime = 'image/jpeg';
    elseif ($ext === 'png') $mime = 'image/png';
    elseif ($ext === 'gif') $mime = 'image/gif';
    elseif ($ext === 'webp') $mime = 'image/webp';

    echo json_encode([
        'status' => 'success',
        'ext' => $ext,
        'mime' => $mime,
        'data' => base64_encode($body)
    ]);
    exit;
}

$proxy_json_url = 'view_surat.php?proxy=1&url=' . urlencode($file_url);
$is_pdf = ($ext === 'pdf');
$is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
    <style>
        html, body {
            margin: 0;
            padding: 0;
            min-height: 100%;
            background: #525659;
            font-family: Arial, Helvetica, sans-serif;
            color: #fff;
        }
        .loading-text {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 15px;
            font-weight: bold;
            background: rgba(0,0,0,0.85);
            padding: 14px 28px;
            border-radius: 8px;
            z-index: 100;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        #documentContainer {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px 0;
            gap: 15px;
            width: 100%;
        }
        .pdf-page-canvas {
            box-shadow: 0 4px 18px rgba(0,0,0,0.4);
            background: #fff;
            max-width: 98%;
            height: auto;
        }
        .image-preview {
            max-width: 95%;
            max-height: 90vh;
            object-fit: contain;
            box-shadow: 0 4px 18px rgba(0,0,0,0.4);
            background: #fff;
        }
        @media print {
            html, body { background: #fff !important; color: #000 !important; }
            .loading-text { display: none !important; }
            #documentContainer { padding: 0 !important; gap: 0 !important; }
            .pdf-page-canvas { box-shadow: none !important; max-width: 100% !important; page-break-after: always; }
            .image-preview { max-width: 100% !important; max-height: none !important; box-shadow: none !important; page-break-after: always; }
        }
    </style>
</head>
<body>
    <div id="loadingText" class="loading-text">Memuat dokumen...</div>
    <div id="documentContainer"></div>

    <script>
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';
        var proxyUrl = <?= json_encode($proxy_json_url) ?>;
        var loadingEl = document.getElementById('loadingText');
        var container = document.getElementById('documentContainer');

        fetch(proxyUrl)
            .then(function(response) { return response.json(); })
            .then(function(json) {
                if (!json || json.status !== 'success' || !json.data) {
                    if (loadingEl) loadingEl.textContent = 'Gagal memuat surat: ' + ((json && json.message) || 'Data kosong');
                    return;
                }

                if (json.ext === 'pdf' || json.mime === 'application/pdf') {
                    var binaryString = atob(json.data);
                    var len = binaryString.length;
                    var bytes = new Uint8Array(len);
                    for (var i = 0; i < len; i++) {
                        bytes[i] = binaryString.charCodeAt(i);
                    }

                    pdfjsLib.getDocument({ data: bytes }).promise.then(function(pdf) {
                        var totalPages = pdf.numPages;
                        var pageChain = Promise.resolve();

                        for (var p = 1; p <= totalPages; p++) {
                            (function(pageNum) {
                                pageChain = pageChain.then(function() {
                                    return pdf.getPage(pageNum).then(function(page) {
                                        var scale = 1.5;
                                        var viewport = page.getViewport({ scale: scale });
                                        var canvas = document.createElement('canvas');
                                        var ctx = canvas.getContext('2d');
                                        canvas.height = viewport.height;
                                        canvas.width = viewport.width;
                                        canvas.className = 'pdf-page-canvas';
                                        container.appendChild(canvas);

                                        return page.render({
                                            canvasContext: ctx,
                                            viewport: viewport
                                        }).promise;
                                    });
                                });
                            })(p);
                        }

                        pageChain.then(function() {
                            if (loadingEl) loadingEl.style.display = 'none';
                            setTimeout(function() {
                                window.focus();
                                window.print();
                            }, 300);
                        });
                    }).catch(function(err) {
                        if (loadingEl) loadingEl.textContent = 'Gagal memproses file PDF.';
                    });
                } else {
                    // Image files
                    var img = document.createElement('img');
                    img.className = 'image-preview';
                    img.src = 'data:' + json.mime + ';base64,' + json.data;
                    img.onload = function() {
                        if (loadingEl) loadingEl.style.display = 'none';
                        setTimeout(function() {
                            window.focus();
                            window.print();
                        }, 300);
                    };
                    container.appendChild(img);
                }
            })
            .catch(function(err) {
                if (loadingEl) loadingEl.textContent = 'Gagal terhubung ke server proxy.';
            });
    </script>
</body>
</html>
