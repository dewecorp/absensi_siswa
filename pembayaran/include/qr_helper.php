<?php

function generate_qr_from_text($text, $size = 80)
{
    if (class_exists('\chillerlan\QRCode\QRCode')) {
        $scale = max(1, (int)round($size / 20));
        $options = new \chillerlan\QRCode\QROptions([
            'outputType' => \chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG,
            'eccLevel' => \chillerlan\QRCode\QRCode::ECC_L,
            'scale' => $scale,
            'imageBase64' => true,
        ]);
        $qr = new \chillerlan\QRCode\QRCode($options);
        return $qr->render($text);
    }
    $url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . urlencode($text);
    return $url;
}

function generate_qr_bendahara($nama_bendahara, $nama_sekolah, $size = 80)
{
    $text = 'Bendahara: ' . $nama_bendahara . ' - ' . $nama_sekolah;
    return generate_qr_from_text($text, $size);
}

