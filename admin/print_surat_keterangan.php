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

$mode = (string)($_GET['mode'] ?? 'single'); // single | all
$autoPrint = (int)($_GET['auto'] ?? 1) === 1;

// Load settings
$print_settings_data = [
    'ketua_gudep' => '',
    'nta_ketua_gudep' => '',
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
$gugus_depan = trim((string)($print_settings_data['gugus_depan'] ?? '03.016'));
$nomor_surat = trim((string)($print_settings_data['nomor_surat'] ?? ''));
$tempat_pelantikan = trim((string)($print_settings_data['tempat_pelantikan'] ?? ''));
$ketua_gudep = $print_settings_data['ketua_gudep'] ?: '........................';
$nta_ketua_gudep = $print_settings_data['nta_ketua_gudep'] ?: '';
$logo_pramuka = $print_settings_data['logo_pramuka'] ? ('../uploads/' . $print_settings_data['logo_pramuka']) : '';
$bingkai = !empty($print_settings_data['bingkai_surat'])
    ? ('../uploads/' . $print_settings_data['bingkai_surat'])
    : '../assets/img/bingkai_surat_keterangan.png';

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

$participants = [];
if ($mode === 'all') {
    $tingkat_id = (int)($_GET['tingkat'] ?? 0);
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
            SELECT p.id_peserta_didik_barung, p.nama_peserta_didik, p.nta, p.tempat_lahir, p.tanggal_lahir, t.nama_tingkat
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
            $participants = [$row];
        }
    }
}

if (empty($participants)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Data peserta didik tidak ditemukan.";
    exit;
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
  <title>Print Surat Keterangan</title>
  <style>
    @media print {
      @page { size: A4; margin: 0; }
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
      width: 210mm; min-height: 297mm;
      margin: 12px auto; background: #fff;
      position: relative;
      page-break-after: always;
      box-shadow: 0 8px 18px rgba(0,0,0,0.08);
    }
    .page:last-child { page-break-after: auto; }
    .bg {
      position: absolute; inset: 0;
      width: 210mm; height: 297mm;
      object-fit: contain;
      z-index: 0;
      pointer-events: none;
      user-select: none;
    }
    .content { position: relative; z-index: 1; padding: 18mm 22mm 18mm 22mm; }
    .center { text-align: center; }
    .logo { width: 22mm; height: 22mm; object-fit: contain; margin: 0 auto 3mm; display:block; }
    .h1 { font-weight: 700; letter-spacing: 0.5px; font-size: 14pt; margin: 0; }
    .h2 { font-weight: 700; letter-spacing: 0.5px; font-size: 13pt; margin: 2mm 0 0; }
    .script { font-family: "Brush Script MT", "Segoe Script", "Snell Roundhand", cursive; font-size: 26pt; margin: 6mm 0 0; }
    .nomor { font-size: 11pt; margin: 1mm 0 0; }
    .body { margin-top: 6mm; font-size: 11pt; line-height: 1.6; }
    .label-table { width: 100%; margin: 5mm 0 4mm; font-size: 11pt; }
    .label-table td { padding: 1mm 0; vertical-align: top; }
    .label { width: 40mm; padding-left: 18mm; }
    .colon { width: 4mm; text-align: center; }
    .value { font-weight: 700; }
    .para { margin: 3mm 0; text-align: justify; padding: 0 6mm; }
    .sign { margin-top: 8mm; font-size: 11pt; }
    .sign-table { width: 100%; }
    .sign-table td { vertical-align: top; }
    .sign-right { width: 55%; padding-right: 10mm; }
    .line { border-top: 1px solid #111; width: 52mm; margin: 10mm 0 2mm auto; }
    .name { font-weight: 800; text-decoration: underline; }
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
      <img class="bg" src="<?= h($bingkai) ?>" alt="Bingkai Surat">
      <div class="content">
        <div class="center">
          <?php if (!empty($logo_pramuka)): ?>
            <img class="logo" src="<?= h($logo_pramuka) ?>" alt="Logo Pramuka">
          <?php endif; ?>
          <p class="h1">GERAKAN PRAMUKA</p>
          <p class="h2">GUGUS DEPAN <?= h($gugus_depan ?: '........') ?></p>
          <div class="script">Surat Keterangan</div>
          <div class="nomor">Nomor : <?= h($nomor_surat ?: '.....................') ?></div>
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
                  <div class="line"></div>
                  <div class="center">Ketua Gugus Depan</div>
                  <div style="height: 18mm;"></div>
                  <div class="center name"><?= h($ketua_gudep) ?></div>
                  <?php if ($nta_ketua_gudep !== ''): ?>
                    <div class="center">NTA : <?= h($nta_ketua_gudep) ?></div>
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

