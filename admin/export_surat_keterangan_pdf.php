<?php
// Export Surat Keterangan to PDF (all peserta didik in selected tingkat)
error_reporting(E_ALL & ~E_DEPRECATED);

require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

$tingkat_id = (int)($_GET['tingkat'] ?? 0);
if ($tingkat_id <= 0) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Tingkat tidak valid.";
    exit;
}

// Fetch peserta didik for requested tingkat
$export_participants = [];
try {
    $stmt = $pdo->prepare("
        SELECT id_peserta_didik_barung, nama_peserta_didik, nta, tempat_lahir, tanggal_lahir
        FROM tb_peserta_didik_barung
        WHERE id_tingkat_barung = ?
        ORDER BY nama_peserta_didik ASC
    ");
    $stmt->execute([$tingkat_id]);
    $export_participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $export_participants = [];
}

if (empty($export_participants)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Tidak ada data peserta didik untuk tingkat ini.";
    exit;
}

// Load print settings
$print_settings_data = [
    'ketua_gudep' => '',
    'nta_ketua_gudep' => '',
    'nomor_gudep' => '',
    'gugus_depan' => '03.016',
    'nomor_surat' => '',
    'tempat_pelantikan' => '',
    'tempat_surat' => '',
    'tanggal_surat' => date('Y-m-d'),
    'logo_pramuka' => '',
];

try {
    $settings = $pdo->query("SELECT * FROM tb_pengaturan_cetak_barung LIMIT 1")->fetch();
    if ($settings) {
        $print_settings_data = [
            'ketua_gudep' => $settings['ketua_gudep'] ?? '',
            'nta_ketua_gudep' => $settings['nta_ketua_gudep'] ?? '',
            'nomor_gudep' => $settings['nomor_gudep'] ?? '',
            'gugus_depan' => $settings['gugus_depan'] ?? '03.016',
            'nomor_surat' => $settings['nomor_surat'] ?? '',
            'tempat_pelantikan' => $settings['tempat_pelantikan'] ?? '',
            'tempat_surat' => $settings['tempat_surat'] ?? '',
            'tanggal_surat' => $settings['tanggal_surat'] ?? date('Y-m-d'),
            'logo_pramuka' => $settings['logo_pramuka'] ?? '',
        ];
    }
} catch (Exception $e) {
    // ignore
}

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Vendor autoload tidak ditemukan. Jalankan: composer install";
    exit;
}
require_once $autoload;

$fileToDataUri = function (string $absolutePath): string {
    if ($absolutePath === '' || !file_exists($absolutePath)) return '';
    $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        default => 'application/octet-stream'
    };
    $data = base64_encode((string)file_get_contents($absolutePath));
    return 'data:' . $mime . ';base64,' . $data;
};

$bingkai_abs = __DIR__ . '/../assets/img/template_surat_keterangan.png';
$logo_pramuka_abs = !empty($print_settings_data['logo_pramuka'])
    ? (__DIR__ . '/../uploads/' . $print_settings_data['logo_pramuka'])
    : '';

$bingkai_data_uri = $fileToDataUri($bingkai_abs);
$logo_pramuka_data_uri = $fileToDataUri($logo_pramuka_abs);

$formatTanggalIndo = function ($value) {
    $v = trim((string)$value);
    if ($v === '') return date('d F Y');
    $ts = strtotime($v);
    if ($ts === false) return $v;
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    $m = (int)date('n', $ts);
    return (int)date('j', $ts) . ' ' . ($bulan[$m] ?? date('F', $ts)) . ' ' . date('Y', $ts);
};

$hariIndo = function ($value) {
    $ts = strtotime((string)$value);
    if ($ts === false) return '';
    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    return $hari[(int)date('w', $ts)] ?? '';
};

$tempat_surat = $print_settings_data['tempat_surat'] ?: '................';
$tanggal_surat_indo = $formatTanggalIndo($print_settings_data['tanggal_surat'] ?? '');
$hari_kegiatan = $hariIndo($print_settings_data['tanggal_surat'] ?? '');
$tempat_pelantikan = $print_settings_data['tempat_pelantikan'] ?? '';
$gugus_depan = ($print_settings_data['nomor_gudep'] ?? '') ?: ($print_settings_data['gugus_depan'] ?? '03.016');
$nomor_surat = $print_settings_data['nomor_surat'] ?? '';
$ketua_gudep = $print_settings_data['ketua_gudep'] ?: '........................';
$nta_ketua_gudep = $print_settings_data['nta_ketua_gudep'] ?: '';
$tahun_surat = date('Y', strtotime((string)($print_settings_data['tanggal_surat'] ?? date('Y-m-d'))));
$nomor_gudep_part = trim((string)$gugus_depan) !== '' ? trim((string)$gugus_depan) : '...';

// Resolve tingkat name for "SKU ... Siaga <TINGKAT>"
$tingkat_name = '';
try {
    $tingkat_stmt = $pdo->prepare("SELECT nama_tingkat FROM tb_tingkat_barung WHERE id_tingkat_barung = ? LIMIT 1");
    $tingkat_stmt->execute([$tingkat_id]);
    $tingkat_row = $tingkat_stmt->fetch(PDO::FETCH_ASSOC);
    $tingkat_name = (string)($tingkat_row['nama_tingkat'] ?? '');
} catch (Exception $e) {
    $tingkat_name = '';
}
$tingkat_upper = strtoupper(trim($tingkat_name));

$pages_html = '';
$idx_nomor = 0;
foreach ($export_participants as $p) {
    $idx_nomor++;
    $nomor_urut = str_pad((string)$idx_nomor, 3, '0', STR_PAD_LEFT);
    $nomor_surat_full = $nomor_urut . '/20.' . $nomor_gudep_part . '/' . $tahun_surat;

    $nama = htmlspecialchars((string)($p['nama_peserta_didik'] ?? ''), ENT_QUOTES, 'UTF-8');
    $ttl_place = trim((string)($p['tempat_lahir'] ?? ''));
    $ttl_date = !empty($p['tanggal_lahir']) ? $formatTanggalIndo($p['tanggal_lahir']) : '';
    $ttl = trim($ttl_place . (($ttl_place && $ttl_date) ? ', ' : '') . $ttl_date);

    $pages_html .= '
    <div class="page">
      ' . ($bingkai_data_uri ? '<img class="bg" src="' . $bingkai_data_uri . '" alt="Template">' : '') . '
      <div class="content">
        <div class="center">
          ' . ($logo_pramuka_data_uri ? '<img class="logo" src="' . $logo_pramuka_data_uri . '" alt="Logo Pramuka">' : '') . '
          <div class="h1">GERAKAN PRAMUKA</div>
          <div class="h2">GUGUS DEPAN ' . htmlspecialchars((string)$gugus_depan, ENT_QUOTES, 'UTF-8') . '</div>
          <div class="script">Surat Keterangan</div>
          <div class="nomor">Nomor : ' . htmlspecialchars((string)$nomor_surat_full, ENT_QUOTES, 'UTF-8') . '</div>
        </div>

        <div class="para">Yang bertanda tangan di bawah ini Ketua Gugus Depan Gerakan Pramuka menerangkan,</div>

        <table class="label-table">
          <tr>
            <td class="label">Nama</td><td class="colon">:</td>
            <td class="value">' . $nama . '</td>
          </tr>
          <tr>
            <td class="label">Tempat, Tgl. Lahir</td><td class="colon">:</td>
            <td>' . htmlspecialchars(($ttl ?: '............................................................'), ENT_QUOTES, 'UTF-8') . '</td>
          </tr>
          <tr>
            <td class="label">Golongan Pramuka</td><td class="colon">:</td>
            <td>SIAGA</td>
          </tr>
        </table>

        <div class="para">
          Telah menyelesaikan SKU Pramuka Golongan Siaga <strong>' . htmlspecialchars(($tingkat_upper ?: '........'), ENT_QUOTES, 'UTF-8') . '</strong> pada hari ini
          <strong>' . htmlspecialchars((string)($hari_kegiatan ?: '........'), ENT_QUOTES, 'UTF-8') . '</strong>, tanggal <strong>' . htmlspecialchars((string)$tanggal_surat_indo, ENT_QUOTES, 'UTF-8') . '</strong>,
          bertempat di <strong>' . htmlspecialchars((string)($tempat_pelantikan ?: '........'), ENT_QUOTES, 'UTF-8') . '</strong>,
          dan diberikan hak memakai Tanda Kecakapan Umum.
        </div>

        <div class="para">
          Dengan harapan senantiasa meningkatkan keterampilan dan pengetahuannya berdasarkan Dwi Satya dan Dwi Darma Pramuka.
        </div>

        <div class="sign">
          <div class="sign-right">
            <div>Dikeluarkan di : ' . htmlspecialchars((string)$tempat_surat, ENT_QUOTES, 'UTF-8') . '</div>
            <div>Pada Tanggal : ' . htmlspecialchars((string)$tanggal_surat_indo, ENT_QUOTES, 'UTF-8') . '</div>
            <div class="center ketua-block">Ketua Gugus Depan</div>
            <div style="height: 18mm;"></div>
            <div class="center name">' . htmlspecialchars((string)$ketua_gudep, ENT_QUOTES, 'UTF-8') . '</div>
            ' . ($nta_ketua_gudep !== '' ? '<div class="center">NTA : ' . htmlspecialchars((string)$nta_ketua_gudep, ENT_QUOTES, 'UTF-8') . '</div>' : '') . '
          </div>
        </div>
      </div>
    </div>';
}

$html = '<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <style>
    @page { margin: 0; }
    body { margin: 0; font-family: DejaVu Sans, Arial, sans-serif; }
    .page { position: relative; width: 210mm; height: 297mm; page-break-after: always; }
    .page:last-child { page-break-after: auto; }
    .bg { position: absolute; top: -3mm; left: -3mm; width: 216mm; height: 303mm; object-fit: contain; z-index: 0; }
    .content {
      position: relative;
      z-index: 1;
      box-sizing: border-box;
      width: 156mm;          /* tighter safe text area inside frame */
      min-height: 245mm;
      margin: 24mm auto 0;
      padding: 0;
    }
    .center { text-align: center; }
    .logo { width: 22mm; height: 22mm; object-fit: contain; margin: 10mm auto 6mm; display:block; }
    .h1 { font-weight: 700; letter-spacing: 0.5px; font-size: 14pt; }
    .h2 { font-weight: 700; letter-spacing: 0.5px; font-size: 14pt; margin-top: 2mm; }
    .script { font-family: DejaVu Sans, Arial, sans-serif; font-style: italic; font-size: 26pt; margin-top: 6mm; }
    .nomor { font-size: 12pt; margin-top: 1mm; }
    .para { margin: 4mm 0; text-align: justify; font-size: 12pt; line-height: 1.6; padding: 0; }
    .label-table { width: 96%; margin: 5mm auto 4mm; font-size: 12pt; }
    .label { width: 42mm; padding-left: 8mm; }
    .colon { width: 4mm; text-align: center; }
    .value { font-weight: 700; }
    .sign { margin-top: 12mm; font-size: 12pt; }
    .sign-right { width: 72mm; margin-left: auto; padding-right: 2mm; }
    .name { font-weight: 800; text-decoration: underline; }
    .ketua-block { margin-top: 14mm; }
  </style>
</head>
<body>' . $pages_html . '</body></html>';

$dompdf = new Dompdf\Dompdf([
    'isRemoteEnabled' => false,
    'isHtml5ParserEnabled' => true,
]);
$dompdf->setPaper('A4', 'portrait');
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->render();

$filename = 'surat_keterangan_tingkat_' . $tingkat_id . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);
exit;

