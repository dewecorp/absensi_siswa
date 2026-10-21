<?php

$data = $_GET['data'] ?? '';

if ($data === '') {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Missing data';
    exit;
}

$size = isset($_GET['size']) ? (int) $_GET['size'] : 180;
if ($size < 50) {
    $size = 50;
} elseif ($size > 500) {
    $size = 500;
}

$autoload = __DIR__ . '/vendor/autoload.php';

if (file_exists($autoload)) {
    require_once $autoload;
    
    try {
        $result = \Endroid\QrCode\Builder\Builder::create()
            ->writer(new \Endroid\QrCode\Writer\PngWriter())
            ->data($data)
            ->encoding(new \Endroid\QrCode\Encoding\Encoding('UTF-8'))
            ->errorCorrectionLevel(new \Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh())
            ->size($size)
            ->margin(0)
            ->build();
        
        header('Content-Type: image/png');
        echo $result->getString();
        exit;
    } catch (Throwable $e) {
        // Jika library offline error, lanjut ke fallback online di bawah
    }
}

// Fallback: gunakan layanan QR online jika library offline tidak tersedia / gagal
$remoteUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . rawurlencode($data);
header('Location: ' . $remoteUrl, true, 302);
exit;
