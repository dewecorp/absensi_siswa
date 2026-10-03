<?php
// Dedicated direct file downloader for Perangkat Pembelajaran (supports uploaded files & AI-generated documents)
error_reporting(E_ALL & ~E_DEPRECATED);

require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';
require_once '../config/ai_helper.php';

if (!isAuthorized(['guru', 'wali', 'admin', 'tata_usaha', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('ID tidak valid.');
}

ensure_learning_schema($pdo);
ai_helper_schema($pdo);

$stmt = $pdo->prepare("
    SELECT p.*, m.nama_mapel, k.nama_kelas, g.nama_guru
    FROM tb_perangkat_pembelajaran p
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = p.id_mapel
    LEFT JOIN tb_kelas k ON k.id_kelas = p.id_kelas
    LEFT JOIN tb_guru g ON g.id_guru = p.id_guru
    WHERE p.id = ?
");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    exit('Dokumen perangkat tidak ditemukan.');
}

// Case 1: Berkas fisik upload manual
if (!empty($row['file_path'])) {
    $file_path = resolve_guru_file_path('perangkat', $row['file_path']);
    if (!is_file($file_path)) {
        http_response_code(404);
        exit('Berkas fisik tidak ditemukan di server.');
    }

    $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    $clean_title = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim((string)$row['judul']));
    $filename = ($clean_title !== '' ? $clean_title : 'perangkat') . '.' . $ext;

    while (ob_get_level()) { ob_end_clean(); }

    $mime = 'application/octet-stream';
    if ($ext === 'pdf') $mime = 'application/pdf';
    elseif ($ext === 'xls') $mime = 'application/vnd.ms-excel';
    elseif ($ext === 'xlsx') $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    elseif ($ext === 'doc') $mime = 'application/msword';
    elseif ($ext === 'docx') $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    elseif ($ext === 'ppt') $mime = 'application/vnd.ms-powerpoint';
    elseif ($ext === 'pptx') $mime = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
    elseif ($ext === 'zip') $mime = 'application/zip';

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Access-Control-Expose-Headers: Content-Disposition');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file_path));

    readfile($file_path);
    exit;
}

// Case 2: Dokumen hasil Generate AI (teks di database) -> Buat PDF / DOCX / XLSX on the fly
$format = strtolower(trim((string)($_GET['format'] ?? 'pdf')));
if ($format === 'xls') $format = 'xlsx';
if ($format === 'doc') $format = 'docx';
if (!in_array($format, ['pdf', 'docx', 'xlsx'], true)) {
    $format = 'pdf';
}

$clean_title = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim((string)$row['judul']));
if ($clean_title === '') $clean_title = 'perangkat';
$clean_title = substr($clean_title, 0, 80);

$dok = [
    'jenis_perangkat' => $row['jenis_perangkat'] ?? 'Dokumen',
    'judul' => $row['judul'] ?? 'Dokumen',
    'cp' => (string)($row['cp'] ?? ''),
    'tp' => (string)($row['tp'] ?? ''),
    'materi' => (string)($row['materi'] ?? ''),
    'tujuan_pembelajaran' => (string)($row['tujuan_pembelajaran'] ?? ''),
    'indikator' => (string)($row['indikator'] ?? ''),
    'deskripsi' => (string)($row['deskripsi'] ?? ''),
    'isi_dokumen' => (function () use ($row) {
        $isi0 = trim((string)($row['isi_dokumen'] ?? ''));
        if ($isi0 !== '') return $isi0;
        $parts = [];
        $add = function ($j, $v) use (&$parts) {
            $v = trim((string)$v);
            if ($v !== '' && $v !== '-') $parts[] = $j . "\n" . $v;
        };
        $add('A. CAPAIAN PEMBELAJARAN (CP)', $row['cp'] ?? '');
        $add('B. TUJUAN PEMBELAJARAN (TP)', $row['tp'] ?? '');
        $add('C. MATERI POKOK', trim(trim((string)($row['materi_tp'] ?? '')) . "\n" . trim((string)($row['materi'] ?? ''))));
        $add('D. TUJUAN PEMBELAJARAN KHUSUS', $row['tujuan_pembelajaran'] ?? '');
        $add('E. INDIKATOR KETERCAPAIAN', $row['indikator'] ?? '');
        $add('F. DESKRIPSI / CATATAN', $row['deskripsi'] ?? '');
        return implode("\n\n", $parts);
    })(),
    'mapel' => (string)($row['nama_mapel'] ?? ''),
    'kelas' => (string)($row['nama_kelas'] ?? ''),
    'semester' => (string)($row['semester'] ?? ''),
    'tahun_ajaran' => (string)($row['tahun_ajaran'] ?? ''),
    'topik' => (string)($row['materi_tp'] ?? ''),
    'guru' => (string)($row['nama_guru'] ?? ''),
];

require_once '../vendor/autoload.php';
while (ob_get_level()) { ob_end_clean(); }

// 2a. Ekspor XLSX (Sheet Identitas + Sheet Matriks Tabel Utuh Landscape F4)
if ($format === 'xlsx') {
    $xlsx_out = ai_build_perangkat_xlsx($dok);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $clean_title . '.xlsx"');
    header('Access-Control-Expose-Headers: Content-Disposition');
    header('Content-Length: ' . strlen($xlsx_out));
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    echo $xlsx_out;
    exit;
}

// 2b. Ekspor DOCX (Paket OpenXML Utuh Landscape F4 dengan Tabel Asli)
if ($format === 'docx' || $format === 'doc') {
    $doc_out = ai_build_perangkat_docx($dok);
    $ext_out = $format === 'doc' ? 'doc' : 'docx';
    $mime_out = $format === 'doc' ? 'application/msword' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    header('Content-Type: ' . $mime_out);
    header('Content-Disposition: attachment; filename="' . $clean_title . '.' . $ext_out . '"');
    header('Access-Control-Expose-Headers: Content-Disposition');
    header('Content-Length: ' . strlen($doc_out));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    echo $doc_out;
    exit;
}

// 2c. Ekspor PDF via Dompdf (Format Kertas Landscape F4 / Folio 330mm x 215mm)
if (!class_exists('Dompdf\\Dompdf')) {
    http_response_code(500);
    exit('Dompdf tidak tersedia.');
}

$html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
    . '<style>'
    . '@page { size: 330mm 215mm landscape; margin: 12mm 15mm; }'
    . 'body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 9pt; color: #111; line-height: 1.45; }'
    . 'h2 { text-align: center; font-size: 14pt; font-weight: bold; margin-bottom: 2px; }'
    . 'h3 { font-size: 11pt; border-bottom: 1.5px solid #2563eb; color: #1e3a8a; padding-bottom: 3px; margin-top: 14px; }'
    . 'table { border-collapse: collapse; width: 100%; margin: 8px 0; table-layout: auto; }'
    . 'th, td { border: 1px solid #666; padding: 4px 6px; vertical-align: top; font-size: 8pt; word-wrap: break-word; }'
    . 'th { background-color: #e2e8f0; font-weight: bold; text-align: center; }'
    . '</style></head><body>';
$html .= '<h2>' . htmlspecialchars($dok['judul']) . '</h2>';
$html .= '<p style="text-align:center;color:#555;font-size:8.5pt;">' . htmlspecialchars($dok['jenis_perangkat'])
    . ($dok['mapel'] !== '' ? ' | ' . htmlspecialchars($dok['mapel']) : '')
    . ($dok['kelas'] !== '' ? ' | Kelas ' . htmlspecialchars($dok['kelas']) : '')
    . ($dok['semester'] !== '' ? ' | ' . htmlspecialchars($dok['semester']) : '')
    . ($dok['tahun_ajaran'] !== '' ? ' | ' . htmlspecialchars($dok['tahun_ajaran']) : '')
    . ($dok['guru'] !== '' ? ' | Guru: ' . htmlspecialchars($dok['guru']) : '')
    . '</p>';
$html .= '<h3>Identitas &amp; Capaian</h3><table>';
foreach ([
    'Topik' => $dok['topik'],
    'Guru Pengampu' => $dok['guru'],
    'Capaian Pembelajaran (CP)' => $dok['cp'],
    'Tujuan Pembelajaran (TP)' => $dok['tp'],
    'Materi Pembelajaran' => $dok['materi'],
    'Tujuan Pembelajaran Khusus' => $dok['tujuan_pembelajaran'],
    'Indikator Ketercapaian' => $dok['indikator'],
    'Deskripsi' => $dok['deskripsi'],
] as $label => $val) {
    if (trim($val) === '') continue;
    $html .= '<tr><th style="width:160px;text-align:left;">' . htmlspecialchars($label) . '</th><td>' . nl2br(htmlspecialchars($val)) . '</td></tr>';
}
$html .= '</table><h3>Isi Dokumen Lengkap</h3>';
$isi_raw = trim((string)$dok['isi_dokumen']);
$isi_html = ai_format_perangkat_html($isi_raw, true);
$html .= '<div style="margin-top: 10px;">' . $isi_html . '</div></body></html>';

$dompdf = new Dompdf\Dompdf(['isHtml5ParserEnabled' => true, 'isRemoteEnabled' => true, 'defaultFont' => 'sans-serif']);
$dompdf->loadHtml($html);
// Kertas F4 / Folio Landscape: 330mm x 215mm = 935.43pt x 609.45pt
$dompdf->setPaper([0, 0, 609.45, 935.43], 'landscape');
$dompdf->render();
$pdf_out = $dompdf->output();

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $clean_title . '.pdf"');
header('Access-Control-Expose-Headers: Content-Disposition');
header('Content-Length: ' . strlen($pdf_out));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
echo $pdf_out;
exit;
