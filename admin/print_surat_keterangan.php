<?php
// Print preview page for Surat Keterangan (reload-friendly).
error_reporting(E_ALL & ~E_DEPRECATED);

require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

// Prevent browser cache so layout changes are always reflected
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$mode = (string)($_GET['mode'] ?? 'single'); // single | all | data
$autoPrint = (int)($_GET['auto'] ?? 1) === 1;
$format = (string)($_GET['format'] ?? 'html'); // html | pdf | print
$tingkat_name = '';

// Load settings
$print_settings_data = [
    'ketua_gudep' => '',
    'nta_ketua_gudep' => '',
    'nomor_gudep' => '',
    'gugus_depan' => '03.016',
    'nomor_surat' => '',
    'tempat_surat' => '',
    'tanggal_surat' => date('d F Y'),
    'logo_pramuka' => '',
    'bingkai_surat' => '',
    'tempat_pelantikan' => '',
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
            'tempat_surat' => $settings['tempat_surat'] ?? '',
            'tanggal_surat' => $settings['tanggal_surat'] ?? date('d F Y'),
            'logo_pramuka' => $settings['logo_pramuka'] ?? '',
            'bingkai_surat' => $settings['bingkai_surat'] ?? '',
            'tempat_pelantikan' => $settings['tempat_pelantikan'] ?? '',
        ];
    }
} catch (Exception $e) {
    // ignore
}

$formatTanggal = function ($value) {
    $v = trim((string)$value);
    if ($v === '') return date('d-m-Y');
    $ts = strtotime($v);
    if ($ts !== false) return date('d-m-Y', $ts);
    return $v;
};

$tempat_surat = $print_settings_data['tempat_surat'] ?: '................';
$tanggal_surat = $formatTanggal($print_settings_data['tanggal_surat'] ?: date('d-m-Y'));
$gugus_depan = trim((string)(($print_settings_data['nomor_gudep'] ?? '') ?: ($print_settings_data['gugus_depan'] ?? '03.016')));
$nomor_surat = trim((string)($print_settings_data['nomor_surat'] ?? ''));
$tempat_pelantikan = trim((string)($print_settings_data['tempat_pelantikan'] ?? ''));
$ketua_gudep = $print_settings_data['ketua_gudep'] ?: '........................';
$nta_ketua_gudep = $print_settings_data['nta_ketua_gudep'] ?: '';
$logo_pramuka = $print_settings_data['logo_pramuka'] ? ('../uploads/' . $print_settings_data['logo_pramuka']) : '';
$bingkai = '../assets/img/template_surat_keterangan.png';
$asset_ver = (string)time();
$school_profile = getSchoolProfile($pdo);

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

$tahun_surat = date('Y', strtotime((string)($print_settings_data['tanggal_surat'] ?? date('Y-m-d'))));
$nomor_gudep_part = $gugus_depan !== '' ? $gugus_depan : '...';

$participants = [];
$tingkat_id = (int)($_GET['tingkat'] ?? 0);
if ($mode === 'all' || $mode === 'data') {
    if ($tingkat_id > 0) {
        $tingkat_stmt = $pdo->prepare("SELECT nama_tingkat FROM tb_tingkat_barung WHERE id_tingkat_barung = ? LIMIT 1");
        $tingkat_stmt->execute([$tingkat_id]);
        $tingkat_row = $tingkat_stmt->fetch(PDO::FETCH_ASSOC);
        $tingkat_name = $tingkat_row['nama_tingkat'] ?? '';

        $stmt = $pdo->prepare("
            SELECT id_peserta_didik_barung, nama_peserta_didik, nta, tempat_lahir, tanggal_lahir
            FROM tb_peserta_didik_barung
            WHERE id_tingkat_barung = ?
            ORDER BY nama_peserta_didik ASC
        ");
        $stmt->execute([$tingkat_id]);
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("
            SELECT p.id_peserta_didik_barung, p.id_tingkat_barung, p.nama_peserta_didik, p.nta, p.tempat_lahir, p.tanggal_lahir, t.nama_tingkat
            FROM tb_peserta_didik_barung
            p
            LEFT JOIN tb_tingkat_barung t ON t.id_tingkat_barung = p.id_tingkat_barung
            WHERE p.id_peserta_didik_barung = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $tingkat_name = $row['nama_tingkat'] ?? '';
            // Resolve actual order number in the selected tingkat (same as table order)
            $seq_stmt = $pdo->prepare("
                SELECT COUNT(*) + 1 AS nomor_urut
                FROM tb_peserta_didik_barung
                WHERE id_tingkat_barung = ?
                  AND (
                    nama_peserta_didik < ?
                    OR (nama_peserta_didik = ? AND id_peserta_didik_barung < ?)
                  )
            ");
            $seq_stmt->execute([
                (int)$row['id_tingkat_barung'],
                (string)$row['nama_peserta_didik'],
                (string)$row['nama_peserta_didik'],
                (int)$row['id_peserta_didik_barung']
            ]);
            $seq_row = $seq_stmt->fetch(PDO::FETCH_ASSOC);
            $row['nomor_urut'] = str_pad((string)($seq_row['nomor_urut'] ?? 1), 3, '0', STR_PAD_LEFT);
            $participants = [$row];
        }
    }
}

if (empty($participants)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Data peserta didik tidak ditemukan.";
    exit;
}

// Build printable title/filename base: Surat_keterangan_tingkat_MULA
$tingkat_name_for_file = trim((string)($tingkat_name ?? ''));
if ($tingkat_name_for_file === '') {
    $tingkat_name_for_file = (string)$tingkat_id;
}
$tingkat_name_for_file = preg_replace('/[^A-Za-z0-9]+/', '_', strtoupper($tingkat_name_for_file)) ?? (string)$tingkat_id;
$tingkat_name_for_file = trim($tingkat_name_for_file, '_');
$doc_base_name = 'Surat_keterangan_tingkat_' . $tingkat_name_for_file;

if ($mode === 'data' && $format === 'print') {
    $school_name = (string)($school_profile['nama_madrasah'] ?? $school_profile['nama_sekolah'] ?? 'Sistem Informasi Madrasah');
    $school_year = (string)($school_profile['tahun_ajaran'] ?? '-');
    $school_logo = !empty($school_profile['logo']) ? ('../assets/img/' . $school_profile['logo']) : '';
    $print_date = $formatTanggalIndo($print_settings_data['tanggal_surat'] ?? date('Y-m-d'));
    $qr_payload = "Ketua Gudep: {$ketua_gudep}\nNTA: {$nta_ketua_gudep}\nTanggal: {$print_date}\nDokumen: {$doc_base_name}";
    $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . rawurlencode($qr_payload);

    $rows_html = '';
    foreach ($participants as $idx => $p) {
        $nama = h($p['nama_peserta_didik'] ?? '');
        $nta = h($p['nta'] ?? '');
        $tempat_lahir = h($p['tempat_lahir'] ?? '-');
        $tanggal_lahir = !empty($p['tanggal_lahir']) ? date('d-m-Y', strtotime((string)$p['tanggal_lahir'])) : '-';
        $rows_html .= '<tr>'
            . '<td>' . ($idx + 1) . '</td>'
            . '<td>' . $nama . '</td>'
            . '<td>' . $nta . '</td>'
            . '<td>' . $tempat_lahir . '</td>'
            . '<td>' . h($tanggal_lahir) . '</td>'
            . '</tr>';
    }
    ?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($doc_base_name) ?>.pdf</title>
  <style>
    @media print { @page { size: A4; margin: 12mm; } }
    body { font-family: Arial, sans-serif; margin: 0; background: #f3f4f6; }
    .wrap { max-width: 980px; margin: 10px auto; background: #fff; }
    .content { padding: 12mm; }
    .header { display: flex; align-items: center; gap: 10px; border-bottom: 2px solid #333; padding-bottom: 8px; margin-bottom: 10px; }
    .header-logo { width: 56px; height: 56px; object-fit: contain; }
    .header-title h2 { margin: 0; font-size: 20px; }
    .header-title .meta { margin-top: 3px; color: #444; font-size: 13px; }
    h3 { margin: 10px 0 6px 0; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #555; padding: 6px 8px; text-align: left; }
    th { background: #f3f3f3; }
    .signature-wrap { margin-top: 10mm; display: flex; justify-content: flex-end; }
    .signature-box { width: 270px; text-align: center; }
    .signature-meta { text-align: left; margin-bottom: 8px; }
    .signature-name { font-weight: 700; text-decoration: underline; margin-top: 6px; }
    .signature-nta { margin-top: 2px; }
    .signature-qr { width: 90px; height: 90px; margin: 4px auto; display: block; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="content">
      <div class="header">
        <?php if ($school_logo): ?>
          <img class="header-logo" src="<?= h($school_logo) ?>?v=<?= h($asset_ver) ?>" alt="Logo Sekolah">
        <?php endif; ?>
        <div class="header-title">
          <h2><?= h($school_name) ?></h2>
          <div class="meta">Tahun Ajaran: <strong><?= h($school_year) ?></strong></div>
        </div>
      </div>

      <h3>Data Surat Keterangan</h3>
      <div style="margin-bottom:8mm;">Tingkat: <strong><?= h($tingkat_name ?: ('ID ' . $tingkat_id)) ?></strong></div>
      <table>
        <thead>
          <tr>
            <th style="width:8%;">No</th>
            <th style="width:34%;">Nama Peserta Didik</th>
            <th style="width:18%;">NTA</th>
            <th style="width:22%;">Tempat Lahir</th>
            <th style="width:18%;">Tanggal Lahir</th>
          </tr>
        </thead>
        <tbody><?= $rows_html ?></tbody>
      </table>

      <div class="signature-wrap">
        <div class="signature-box">
          <div class="signature-meta">
            <div>Dikeluarkan di: <?= h($tempat_surat) ?></div>
            <div>Tanggal: <?= h($print_date) ?></div>
          </div>
          <div>Ketua Gudep,</div>
          <img class="signature-qr" src="<?= h($qr_url) ?>" alt="QR Tanda Tangan" referrerpolicy="no-referrer">
          <div class="signature-name"><?= h($ketua_gudep) ?></div>
          <div class="signature-nta">NTA: <?= h($nta_ketua_gudep ?: '-') ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php if ($autoPrint): ?>
  <script>
    window.addEventListener('load', () => setTimeout(() => window.print(), 250));
  </script>
  <?php endif; ?>
</body>
</html>
<?php
    exit;
}

if ($format === 'pdf' && $mode === 'data') {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Vendor autoload tidak ditemukan. Jalankan: composer install";
        exit;
    }
    require_once $autoload;

    $rows_html = '';
    foreach ($participants as $idx => $p) {
        $nama = h($p['nama_peserta_didik'] ?? '');
        $nta = h($p['nta'] ?? '');
        $tempat_lahir = h($p['tempat_lahir'] ?? '-');
        $tanggal_lahir = !empty($p['tanggal_lahir']) ? date('d-m-Y', strtotime((string)$p['tanggal_lahir'])) : '-';
        $rows_html .= '<tr>'
            . '<td>' . ($idx + 1) . '</td>'
            . '<td>' . $nama . '</td>'
            . '<td>' . $nta . '</td>'
            . '<td>' . $tempat_lahir . '</td>'
            . '<td>' . h($tanggal_lahir) . '</td>'
            . '</tr>';
    }

    $judul_tingkat = h($tingkat_name ?: ('ID ' . $tingkat_id));
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
        . '@page { margin: 16mm; size: A4; }'
        . 'body{font-family:DejaVu Sans,Arial,sans-serif;font-size:11pt;}'
        . 'h2{margin:0 0 3mm 0;}'
        . '.meta{margin-bottom:6mm;color:#444;}'
        . 'table{width:100%;border-collapse:collapse;}'
        . 'th,td{border:1px solid #555;padding:6px 8px;vertical-align:top;}'
        . 'th{background:#f3f3f3;text-align:left;}'
        . '</style></head><body>'
        . '<h2>Data Surat Keterangan</h2>'
        . '<div class="meta">Tingkat: <strong>' . $judul_tingkat . '</strong></div>'
        . '<table><thead><tr>'
        . '<th style="width:8%;">No</th>'
        . '<th style="width:34%;">Nama Peserta Didik</th>'
        . '<th style="width:18%;">NTA</th>'
        . '<th style="width:22%;">Tempat Lahir</th>'
        . '<th style="width:18%;">Tanggal Lahir</th>'
        . '</tr></thead><tbody>' . $rows_html . '</tbody></table>'
        . '</body></html>';

    $dompdf = new Dompdf\Dompdf([
        'isRemoteEnabled' => false,
        'isHtml5ParserEnabled' => true,
    ]);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();
    $filename = $doc_base_name . '.pdf';
    $dompdf->stream($filename, ['Attachment' => false]);
    exit;
}

$page_title_print = 'Print Surat Keterangan';
if ($mode === 'single' && !empty($participants[0]['nama_peserta_didik'])) {
    $page_title_print .= ' - ' . (string)$participants[0]['nama_peserta_didik'];
} elseif ($mode === 'all') {
    $page_title_print = $doc_base_name;
} elseif ($mode === 'data') {
    $page_title_print = $doc_base_name;
}

// Dynamic nomor surat: mulai dari peserta didik pertama (001, 002, ...)
foreach ($participants as $idx => $row) {
    if (empty($participants[$idx]['nomor_urut'])) {
        $participants[$idx]['nomor_urut'] = str_pad((string)($idx + 1), 3, '0', STR_PAD_LEFT);
    }
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= h($page_title_print) ?></title>
  <style>
    @media print {
      @page { size: 210mm 330mm; margin: 0; }
      body { margin: 0; }
      .toolbar { display: none !important; }
    }
    body { font-family: Arial, sans-serif; margin: 0; background: #f3f4f6; }
    .toolbar {
      position: sticky; top: 0; z-index: 10;
      display: flex; gap: 8px; align-items: center; justify-content: space-between;
      padding: 10px 12px; background: #fff; border-bottom: 1px solid #e5e7eb;
    }
    .toolbar .left { display: flex; gap: 8px; align-items: center; }
    .toolbar button, .toolbar a {
      font-size: 14px; padding: 8px 10px; border-radius: 8px;
      border: 1px solid #d1d5db; background: #fff; cursor: pointer; text-decoration: none; color: #111827;
    }
    .toolbar button.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
    .hint { font-size: 12px; color: #6b7280; }

    .page {
      width: 210mm; min-height: 330mm;
      margin: 0 auto 12px; background: #fff;
      position: relative;
      overflow: hidden;
      page-break-after: always;
      box-shadow: 0 8px 18px rgba(0,0,0,0.08);
    }
    .page:last-child { page-break-after: auto; }
    .bg {
      position: absolute; inset: 0;
      width: 210mm; height: 330mm;
      top: 1mm; left: 0;
      object-fit: contain;
      object-position: center top;
      z-index: 0;
      pointer-events: none;
      user-select: none;
    }
    .content {
      position: relative;
      z-index: 1;
      box-sizing: border-box;
      width: 156mm;          /* tighter safe text area inside frame */
      min-height: 278mm;
      margin: 24mm auto 0;
      padding: 0;
    }
    .center { text-align: center; margin-top: 22mm; }
    .logo { width: 22mm; height: 22mm; object-fit: contain; margin: 34mm auto 10mm; display:block; }
    .h1 { font-weight: 700; letter-spacing: 0.5px; font-size: 14pt; margin: 0; }
    .h2 { font-weight: 700; letter-spacing: 0.5px; font-size: 14pt; margin: 2mm 0 0; }
    .script { font-family: "Brush Script MT", "Segoe Script", "Snell Roundhand", cursive; font-size: 32pt; margin: 6mm 0 0; }
    .nomor { font-size: 12pt; margin: 1mm 0 0; }
    .body { margin-top: 6mm; font-size: 12pt; line-height: 1.6; }
    .label-table { width: 96%; margin: 5mm auto 4mm; font-size: 12pt; }
    .label-table td { padding: 1mm 0; vertical-align: top; }
    .label { width: 42mm; padding-left: 8mm; }
    .colon { width: 4mm; text-align: center; }
    .value { font-weight: 700; }
    .para { margin: 3mm 0; text-align: justify; padding: 0; }
    .sign { margin-top: 16mm; font-size: 12pt; }
    .sign-table { width: 100%; }
    .sign-table td { vertical-align: top; }
    .sign-right { width: 44%; padding-right: 2mm; }
    .name { font-weight: 800; text-decoration: underline; margin-bottom: 1mm; }
    .nta { margin-top: 1mm; }
    .ketua-block { margin-top: 28mm; }
  </style>
</head>
<body>
  <div class="toolbar">
    <div class="left">
      <button class="primary" onclick="window.print()">Print</button>
      <button onclick="window.location.reload()">Reload</button>
      <a href="surat_keterangan.php">Kembali</a>
    </div>
    <div class="hint">Tab ini bisa di-reload untuk melihat perubahan terbaru.</div>
  </div>

  <?php foreach ($participants as $p): ?>
    <div class="page">
      <img class="bg" src="<?= h($bingkai) ?>?v=<?= h($asset_ver) ?>" alt="Bingkai Surat">
      <div class="content">
        <?php $nomor_surat_full = ($p['nomor_urut'] ?? '001') . '/20.' . $nomor_gudep_part . '/' . $tahun_surat; ?>
        <div class="center">
          <?php if (!empty($logo_pramuka)): ?>
            <img class="logo" src="<?= h($logo_pramuka) ?>?v=<?= h($asset_ver) ?>" alt="Logo Pramuka">
          <?php endif; ?>
          <p class="h1">GERAKAN PRAMUKA</p>
          <p class="h2">GUGUS DEPAN <?= h($gugus_depan ?: '........') ?></p>
          <div class="script">Surat Keterangan</div>
          <div class="nomor">Nomor : <?= h($nomor_surat_full) ?></div>
        </div>

        <div class="body">
          <div class="para">Yang bertanda tangan di bawah ini Ketua Gugus Depan Gerakan Pramuka menerangkan,</div>

          <table class="label-table">
            <tr>
              <td class="label">Nama</td><td class="colon">:</td>
              <td class="value"><?= h($p['nama_peserta_didik'] ?? '') ?></td>
            </tr>
            <tr>
              <td class="label">Tempat, Tgl. Lahir</td><td class="colon">:</td>
              <td>
                <?php
                  $ttl = trim((string)($p['tempat_lahir'] ?? ''));
                  $tgl = !empty($p['tanggal_lahir']) ? $formatTanggalIndo($p['tanggal_lahir']) : '';
                  echo h($ttl . ($ttl && $tgl ? ', ' : '') . $tgl);
                ?>
              </td>
            </tr>
            <tr>
              <td class="label">Golongan Pramuka</td><td class="colon">:</td>
              <td><?= h('SIAGA') ?></td>
            </tr>
          </table>

          <?php
            $tingkat_upper = strtoupper(trim((string)($tingkat_name ?? '')));
            $hari = $hariIndo($print_settings_data['tanggal_surat'] ?? '');
            $tgl_kegiatan = $formatTanggalIndo($print_settings_data['tanggal_surat'] ?? '');
          ?>

          <div class="para">
            Telah menyelesaikan SKU Pramuka Golongan Siaga <strong><?= h($tingkat_upper ?: '........') ?></strong> pada hari ini
            <strong><?= h($hari ?: '........') ?></strong>, tanggal <strong><?= h($tgl_kegiatan ?: '........') ?></strong>,
            bertempat di <strong><?= h($tempat_pelantikan ?: '........') ?></strong>,
            dan diberikan hak memakai Tanda Kecakapan Umum.
          </div>

          <div class="para">
            Dengan harapan senantiasa meningkatkan keterampilan dan pengetahuannya berdasarkan Dwi Satya dan Dwi Darma Pramuka.
          </div>

          <div class="sign">
            <table class="sign-table">
              <tr>
                <td></td>
                <td class="sign-right">
                  <div>Dikeluarkan di : <?= h($tempat_surat) ?></div>
                  <div>Pada Tanggal : <?= h($formatTanggalIndo($print_settings_data['tanggal_surat'] ?? '')) ?></div>
                  <div class="center ketua-block">Ketua Gugus Depan</div>
                  <div style="height: 8mm;"></div>
                  <div class="center name"><?= h($ketua_gudep) ?></div>
                  <?php if ($nta_ketua_gudep !== ''): ?>
                    <div class="center nta">NTA : <?= h($nta_ketua_gudep) ?></div>
                  <?php endif; ?>
                </td>
              </tr>
            </table>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

  <script>
    (function () {
      const auto = <?= $autoPrint ? 'true' : 'false' ?>;
      if (!auto) return;
      window.addEventListener('load', () => {
        // Small delay helps rendering before print dialog
        setTimeout(() => window.print(), 250);
      });
    })();
  </script>
</body>
</html>

