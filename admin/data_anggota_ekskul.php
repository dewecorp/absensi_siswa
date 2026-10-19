<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$can_manage_anggota_ekskul = isAuthorized(['admin', 'tata_usaha']);
$can_view_anggota_ekskul = $can_manage_anggota_ekskul || isAuthorized(['kepala_madrasah', 'wali', 'guru']);
if (!$can_view_anggota_ekskul) {
    redirect('../login.php');
}

// Ekskul dinamis via ?ekskul=id_ekstrakurikuler (selain Pramuka).
// Wrapper lama (pencak_silat/rebana) tetap didukung lewat $ekskul_type.
$ekskul_id = (int)($_GET['ekskul'] ?? 0);
$ekskul_legacy = isset($ekskul_type) ? (string)$ekskul_type : '';
$table_name = 'tb_anggota_ekskul';

$page_title = 'Data Anggota Ekstrakurikuler';
$message = null;
$toast_message = null;

$ekskul_list = [];
try {
    $ekskul_list = $pdo->query("SELECT id_ekstrakurikuler, nama_ekstrakurikuler FROM tb_ekstrakurikuler WHERE LOWER(nama_ekstrakurikuler) NOT LIKE '%pramuka%' ORDER BY nama_ekstrakurikuler ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $ekskul_list = [];
}

$css_libs = [
    'https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css',
];
$js_libs = [
    'https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js',
];

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$table_name} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_ekstrakurikuler INT NOT NULL DEFAULT 0,
            id_siswa INT NOT NULL,
            status ENUM('aktif','keluar') NOT NULL DEFAULT 'aktif',
            tanggal_masuk DATETIME NULL,
            tanggal_keluar DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_ekskul_siswa (id_ekstrakurikuler, id_siswa),
            INDEX idx_ekskul (id_ekstrakurikuler),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Tambah kolom id_ekstrakurikuler bila tabel sudah ada dari versi lama
    try {
        $hasCol = $pdo->query("SHOW COLUMNS FROM {$table_name} LIKE 'id_ekstrakurikuler'")->fetch(PDO::FETCH_ASSOC);
        if (!$hasCol) {
            $pdo->exec("ALTER TABLE {$table_name} ADD COLUMN id_ekstrakurikuler INT NOT NULL DEFAULT 0 AFTER id");
            $pdo->exec("ALTER TABLE {$table_name} DROP INDEX uniq_id_siswa");
            $pdo->exec("ALTER TABLE {$table_name} ADD UNIQUE KEY uniq_ekskul_siswa (id_ekstrakurikuler, id_siswa)");
            $pdo->exec("ALTER TABLE {$table_name} ADD INDEX idx_ekskul (id_ekstrakurikuler)");
        }
    } catch (Exception $e) { /* abaikan bila index belum ada */ }

    // Migrasi sekali saja dari tabel lama (pencak silat / rebana) ke tabel unifikasi
    try {
        $migrated = (int)$pdo->query("SELECT COUNT(*) FROM {$table_name}")->fetchColumn();
        if ($migrated === 0) {
            $legacy_map = [];
            try {
                $rows = $pdo->query("SELECT id_ekstrakurikuler, nama_ekstrakurikuler FROM tb_ekstrakurikuler")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $legacy_map[strtolower(trim((string)$r['nama_ekstrakurikuler']))] = (int)$r['id_ekstrakurikuler'];
                }
            } catch (Exception $e) { /* ignore */ }
            $legacy_sources = [
                'tb_anggota_pencak_silat' => ($legacy_map['pencak silat'] ?? 0),
                'tb_anggota_rebana' => ($legacy_map['rebana'] ?? 0),
            ];
            foreach ($legacy_sources as $src => $dest_id) {
                if ($dest_id <= 0) continue;
                try {
                    $pdo->exec("INSERT IGNORE INTO {$table_name} (id_ekstrakurikuler, id_siswa, status, tanggal_masuk, tanggal_keluar) SELECT {$dest_id}, id_siswa, status, tanggal_masuk, tanggal_keluar FROM {$src}");
                } catch (Exception $e) { /* tabel lama mungkin tidak ada */ }
            }
        }
    } catch (Exception $e) { /* ignore */ }
} catch (Exception $e) {
    $message = ['type' => 'danger', 'text' => 'Gagal menyiapkan tabel anggota: ' . $e->getMessage()];
}

// Tentukan ekskul aktif: ?ekskul=id, fallback wrapper lama, lalu ekskul pertama
$ekskul_id = (int)($_GET['ekskul'] ?? $_POST['ekskul'] ?? 0);
$ekskul_valid_ids = array_map(static function ($e) { return (int)($e['id_ekstrakurikuler'] ?? 0); }, $ekskul_list);
if ($ekskul_id <= 0 && $ekskul_legacy !== '') {
    foreach ($ekskul_list as $e) {
        if (strtolower(trim((string)$e['nama_ekstrakurikuler'])) === str_replace('_', ' ', strtolower($ekskul_legacy))) {
            $ekskul_id = (int)$e['id_ekstrakurikuler'];
            break;
        }
    }
}
if (($ekskul_id <= 0 || !in_array($ekskul_id, $ekskul_valid_ids, true)) && !empty($ekskul_valid_ids)) {
    $ekskul_id = (int)$ekskul_valid_ids[0];
}
$ekskul_name = '';
foreach ($ekskul_list as $e) {
    if ((int)$e['id_ekstrakurikuler'] === $ekskul_id) {
        $ekskul_name = (string)$e['nama_ekstrakurikuler'];
        break;
    }
}
if ($ekskul_name === '') {
    $message = ['type' => 'warning', 'text' => 'Belum ada data ekstrakurikuler (selain Pramuka). Tambahkan dulu di menu Data Ekstrakurikuler.'];
}
$ekskul_title = $ekskul_name !== '' ? ('Data Anggota ' . $ekskul_name) : 'Data Anggota Ekstrakurikuler';
$page_title = $ekskul_title;
$slug = $ekskul_id > 0 ? ('ekskul_' . $ekskul_id) : 'ekskul';

$ekskul_school = getSchoolProfile($pdo);
$ekskul_school_name = trim((string)($ekskul_school['nama_madrasah'] ?? ''));
if ($ekskul_school_name === '') {
    $ekskul_school_name = 'Madrasah';
}

// Jumlah anggota aktif per ekskul (untuk badge tab)
$ekskul_counts = [];
try {
    $count_rows = $pdo->query("SELECT id_ekstrakurikuler, COUNT(*) AS jml FROM {$table_name} WHERE status = 'aktif' GROUP BY id_ekstrakurikuler")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($count_rows as $cr) {
        $ekskul_counts[(int)$cr['id_ekstrakurikuler']] = (int)$cr['jml'];
    }
} catch (Exception $e) {
    $ekskul_counts = [];
}

if ($message === null && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ok'])) {
    $ok = (string)($_GET['ok'] ?? '');
    if ($ok === 'added') {
        $blocked = (int)($_GET['blocked'] ?? 0);
        if ($blocked > 0) {
            $message = ['type' => 'warning', 'text' => "Sebagian data ditambahkan. {$blocked} siswa tidak ditambahkan karena masih aktif di ekstrakurikuler lain."];
        } else {
            $message = ['type' => 'success', 'text' => 'Anggota berhasil ditambahkan.'];
        }
    } elseif ($ok === 'removed') {
        $toast_message = 'Anggota berhasil dikeluarkan dari daftar.';
    }
}

$selected_class_id = (int)($_POST['id_kelas'] ?? $_GET['kelas'] ?? 0);
$export_type = (string)($_GET['export'] ?? '');
$pdf_auto_print = (int)($_GET['auto'] ?? 0) === 1;

$classes = [];
try {
    $classes = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    if ($message === null) {
        $message = ['type' => 'danger', 'text' => 'Gagal memuat data kelas: ' . $e->getMessage()];
    }
}

$selected_class_name = '';
foreach ($classes as $c) {
    if ((int)$c['id_kelas'] === $selected_class_id) {
        $selected_class_name = (string)$c['nama_kelas'];
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_manage_anggota_ekskul) {
        $message = ['type' => 'warning', 'text' => 'Mode baca saja. CRUD tidak diizinkan untuk level Anda.'];
    } else {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_collective') {
        $ids = isset($_POST['selected_siswa']) && is_array($_POST['selected_siswa']) ? $_POST['selected_siswa'] : [];
        $ids = array_values(array_filter(array_map('intval', $ids), static function ($v) {
            return $v > 0;
        }));

        if ($selected_class_id <= 0) {
            $message = ['type' => 'warning', 'text' => 'Pilih kelas terlebih dahulu.'];
        } elseif (empty($ids)) {
            $message = ['type' => 'warning', 'text' => 'Pilih minimal satu siswa untuk ditambahkan.'];
        } else {
            try {
                $pdo->beginTransaction();

                $check_stmt = $pdo->prepare("SELECT id_kelas FROM tb_siswa WHERE id_siswa = ? LIMIT 1");
                $check_other_active_stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table_name} WHERE id_siswa = ? AND id_ekstrakurikuler <> ? AND status = 'aktif'");
                $upsert_stmt = $pdo->prepare("
                    INSERT INTO {$table_name} (id_ekstrakurikuler, id_siswa, status, tanggal_masuk, tanggal_keluar)
                    VALUES (?, ?, 'aktif', NOW(), NULL)
                    ON DUPLICATE KEY UPDATE
                        status = 'aktif',
                        tanggal_keluar = NULL,
                        tanggal_masuk = IF(tanggal_masuk IS NULL, NOW(), tanggal_masuk)
                ");

                $added = 0;
                $blocked_conflict = 0;
                foreach ($ids as $id_siswa) {
                    $check_stmt->execute([$id_siswa]);
                    $kls = (int)$check_stmt->fetchColumn();
                    if ($kls !== $selected_class_id) {
                        continue;
                    }
                    $check_other_active_stmt->execute([$id_siswa, $ekskul_id]);
                    if ((int)$check_other_active_stmt->fetchColumn() > 0) {
                        $blocked_conflict++;
                        continue;
                    }
                    $upsert_stmt->execute([$ekskul_id, $id_siswa]);
                    $added++;
                }

                $pdo->commit();

                header('Location: ' . basename($_SERVER['SCRIPT_NAME']) . '?ekskul=' . $ekskul_id . '&kelas=' . $selected_class_id . '&ok=added&blocked=' . $blocked_conflict);
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = ['type' => 'danger', 'text' => 'Gagal menambahkan anggota: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'keluarkan') {
        $id_anggota = (int)($_POST['id_anggota'] ?? 0);
        if ($id_anggota <= 0) {
            $message = ['type' => 'warning', 'text' => 'Data anggota tidak valid.'];
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE {$table_name} SET status = 'keluar', tanggal_keluar = NOW() WHERE id = ? AND id_ekstrakurikuler = ? LIMIT 1");
                $stmt->execute([$id_anggota, $ekskul_id]);

                $kelas_redirect = (int)($_POST['id_kelas'] ?? 0);
                if ($kelas_redirect > 0) {
                    header('Location: ' . basename($_SERVER['SCRIPT_NAME']) . '?ekskul=' . $ekskul_id . '&kelas=' . $kelas_redirect . '&ok=removed');
                    exit;
                }
                $toast_message = 'Anggota berhasil dikeluarkan dari daftar.';
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal mengeluarkan anggota: ' . $e->getMessage()];
            }
        }
    }
    }
}

$members = [];
$available_students = [];
$total_members_count = 0;
try {
    $stmt_total_members = $pdo->prepare("
        SELECT COUNT(*)
        FROM {$table_name}
        WHERE status = 'aktif' AND id_ekstrakurikuler = ?
    ");
    $stmt_total_members->execute([$ekskul_id]);
    $total_members_count = (int)$stmt_total_members->fetchColumn();
} catch (Exception $e) {
    $total_members_count = 0;
}
if ($selected_class_id > 0 && $ekskul_id > 0) {
    try {
        $stmt_members = $pdo->prepare("
            SELECT a.id, a.tanggal_masuk, s.nisn, s.nama_siswa, s.foto, s.jenis_kelamin
            FROM {$table_name} a
            INNER JOIN tb_siswa s ON s.id_siswa = a.id_siswa
            WHERE a.status = 'aktif' AND a.id_ekstrakurikuler = ? AND s.id_kelas = ?
            ORDER BY s.nama_siswa ASC
        ");
        $stmt_members->execute([$ekskul_id, $selected_class_id]);
        $members = $stmt_members->fetchAll(PDO::FETCH_ASSOC);

        $stmt_available = $pdo->prepare("
            SELECT s.id_siswa, s.nisn, s.nama_siswa
            FROM tb_siswa s
            LEFT JOIN {$table_name} ao ON ao.id_siswa = s.id_siswa AND ao.status = 'aktif'
            WHERE s.id_kelas = ? AND ao.id IS NULL
            ORDER BY s.nama_siswa ASC
        ");
        $stmt_available->execute([$selected_class_id]);
        $available_students = $stmt_available->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        if ($message === null) {
            $message = ['type' => 'danger', 'text' => 'Gagal memuat data anggota: ' . $e->getMessage()];
        }
    }
}

if ($export_type !== '' && $selected_class_id > 0 && $ekskul_id > 0) {
    $school_profile = getSchoolProfile($pdo);
    $nama_madrasah = (string)($school_profile['nama_madrasah'] ?? 'Madrasah');
    $tahun_ajaran = (string)($school_profile['tahun_ajaran'] ?? date('Y'));
    $kepala = (string)($school_profile['nama_kepala'] ?? '-');
    $nip = (string)($school_profile['nip_kepala'] ?? '-');
    $tempat = (string)($school_profile['tempat_jadwal'] ?? 'Padang');
    $tanggal = date('d F Y');
    $school_address = (string)($school_profile['alamat'] ?? '');
    $school_email = (string)($school_profile['email_madrasah'] ?? '');
    $school_website = (string)($school_profile['website_madrasah'] ?? '');
    $nama_yayasan = (string)($school_profile['nama_yayasan'] ?? '');

    if ($export_type === 'excel') {
        $slug_label = $ekskul_name !== '' ? $ekskul_name : 'Ekstrakurikuler';
        $excel_filename = 'Data_Anggota_' . str_replace(' ', '_', $slug_label) . '_' . preg_replace('/[^A-Za-z0-9_-]/', '-', str_replace('/', '-', trim($tahun_ajaran)));
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $excel_filename . '.xls"');
        echo '<html><head><meta charset="UTF-8"></head><body>';
        if ($nama_yayasan !== '') {
            echo '<h3 style="text-align:center;margin:0;">' . htmlspecialchars(strtoupper($nama_yayasan)) . '</h3>';
        }
        echo '<h2 style="text-align:center;margin-bottom:4px;">' . htmlspecialchars(strtoupper($nama_madrasah)) . '</h2>';
        if ($school_address !== '') {
            echo '<p style="text-align:center;margin:0;">' . htmlspecialchars($school_address) . '</p>';
        }
        if ($school_website !== '' || $school_email !== '') {
            echo '<p style="text-align:center;margin:0;">' . htmlspecialchars(trim($school_website . ($school_website !== '' && $school_email !== '' ? ' | ' : '') . $school_email)) . '</p>';
        }
        echo '<h3 style="text-align:center;margin-top:6px;">' . htmlspecialchars($ekskul_title) . '</h3>';
        echo '<p style="text-align:center;">Kelas: <strong>' . htmlspecialchars($selected_class_name) . '</strong><br>Tahun Ajaran: <strong>' . htmlspecialchars($tahun_ajaran) . '</strong></p>';
        echo '<p>Tanggal Cetak: ' . htmlspecialchars($tanggal) . '</p>';
        echo '<table border="1" cellspacing="0" cellpadding="6" style="border-collapse:collapse;width:100%;">';
        echo '<tr><th>No</th><th>NISN</th><th>Nama Siswa</th></tr>';
        foreach ($members as $i => $m) {
            echo '<tr>';
            echo '<td>' . ($i + 1) . '</td>';
            echo '<td>' . htmlspecialchars((string)$m['nisn']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$m['nama_siswa']) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '<br><br><table style="width:100%;"><tr><td style="width:60%"></td><td>';
        echo htmlspecialchars($tempat) . ', ' . htmlspecialchars($tanggal) . '<br>Kepala Madrasah<br><br><br><br>';
        echo '<strong><u>' . htmlspecialchars($kepala) . '</u></strong><br>NIP: ' . htmlspecialchars($nip);
        echo '</td></tr></table>';
        echo '</body></html>';
        exit;
    }

    if ($export_type === 'pdf') {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        $rows_html = '';
        foreach ($members as $i => $m) {
            $rows_html .= '<tr>'
                . '<td>' . ($i + 1) . '</td>'
                . '<td>' . htmlspecialchars((string)$m['nisn'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string)$m['nama_siswa'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '</tr>';
        }
        if ($rows_html === '') {
            $rows_html = '<tr><td colspan="3" style="text-align:center;">Tidak ada data.</td></tr>';
        }

        $back_url = htmlspecialchars(basename($_SERVER['SCRIPT_NAME']) . '?ekskul=' . $ekskul_id . '&kelas=' . $selected_class_id, ENT_QUOTES, 'UTF-8');
        $doc_title_base = $ekskul_title . ' ' . str_replace('/', '-', trim($tahun_ajaran));
        $school_logo = !empty($school_profile['logo']) ? ('../assets/img/' . $school_profile['logo']) : '';
        $qr_payload = "Dokumen: {$doc_title_base}\nEkstrakurikuler: {$ekskul_title}\nKelas: {$selected_class_name}\nTanggal: {$tanggal}\nKepala: {$kepala}";
        $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . rawurlencode($qr_payload);
        ?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($doc_title_base, ENT_QUOTES, 'UTF-8') ?></title>
  <style>
    @media print {
      @page { size: A4 portrait; margin: 8mm; }
      .toolbar { display: none !important; }
    }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11pt; margin: 0; background: #f3f4f6; }
    .toolbar {
      position: sticky; top: 0; z-index: 10;
      display: flex; gap: 8px; align-items: center; justify-content: space-between;
      padding: 10px 12px; background: #fff; border-bottom: 1px solid #e5e7eb;
    }
    .toolbar .left { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .toolbar button, .toolbar a {
      font-size: 14px; padding: 8px 10px; border-radius: 8px;
      border: 1px solid #d1d5db; background: #fff; cursor: pointer; text-decoration: none; color: #111827;
    }
    .toolbar button.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
    .hint { font-size: 12px; color: #6b7280; }
    .sheet { max-width: 210mm; margin: 6px auto; padding: 8mm; background: #fff; box-shadow: none; }
    table.kop-header { width: 100%; border-collapse: collapse; border-bottom: 2px solid #000; margin-bottom: 12px; padding-bottom: 6px; }
    table.kop-header td { border: none !important; padding: 0 !important; vertical-align: middle; }
    td.kop-logo { width: 80px; text-align: center; }
    td.kop-logo img { height: 65px; width: auto; max-width: 80px; object-fit: contain; }
    td.kop-text { text-align: center; }
    td.kop-text h2 { margin: 0; font-size: 13.5pt; font-weight: bold; color: #000; letter-spacing: 0.3px; }
    td.kop-text p.alamat { margin: 2px 0; font-size: 9.5pt; color: #000; }
    td.kop-text p.kontak { margin: 2px 0 0; font-size: 9pt; color: #000; }
    h2, h3, p.meta { margin: 0; text-align: center; }
    .meta { margin-top: 6px; margin-bottom: 12px; }
    table.data { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.data th, table.data td { border: 1px solid #555; padding: 6px 8px; }
    table.data th { background: #f2f2f2; }
    table.data thead { display: table-header-group; }
    table.data tr { page-break-inside: avoid; break-inside: avoid; }
    .ttd { margin-top: 12mm; width: 100%; page-break-inside: avoid; break-inside: avoid; }
    .ttd-right { width: 40%; margin-left: 60%; text-align: left; page-break-inside: avoid; break-inside: avoid; }
    .ttd-qr { width: 85px; height: 85px; margin: 8px 0 4px 0; }
  </style>
</head>
<body>
  <div class="toolbar">
    <div class="left">
      <button type="button" class="primary" onclick="window.print()">Print</button>
      <button type="button" onclick="window.location.reload()">Reload</button>
      <a href="<?= $back_url ?>">Kembali</a>
    </div>
    <div class="hint">Tab ini dapat di-reload untuk melihat data terbaru.</div>
  </div>
  <div class="sheet">
    <table class="kop-header">
      <tr>
        <td class="kop-logo">
          <?php if ($school_logo !== ''): ?>
            <img src="<?= htmlspecialchars($school_logo, ENT_QUOTES, 'UTF-8') ?>" alt="Logo Sekolah">
          <?php endif; ?>
        </td>
        <td class="kop-text">
          <?php if ($nama_yayasan !== ''): ?>
            <p class="yayasan" style="margin:0;font-size:11pt;font-weight:bold;"><?= htmlspecialchars(strtoupper($nama_yayasan), ENT_QUOTES, 'UTF-8') ?></p>
          <?php endif; ?>
          <h2><?= htmlspecialchars(strtoupper($nama_madrasah), ENT_QUOTES, 'UTF-8') ?></h2>
          <?php if ($school_address !== ''): ?>
            <p class="alamat"><?= htmlspecialchars($school_address, ENT_QUOTES, 'UTF-8') ?></p>
          <?php endif; ?>
          <?php if ($school_website !== '' || $school_email !== ''): ?>
            <p class="kontak">
              <?= $school_website !== '' ? 'Website: <span style="color:#00f;text-decoration:underline;">' . htmlspecialchars($school_website, ENT_QUOTES, 'UTF-8') . '</span>' : '' ?>
              <?= ($school_website !== '' && $school_email !== '') ? '&nbsp;&nbsp;&nbsp;&nbsp;' : '' ?>
              <?= $school_email !== '' ? 'email: <span style="color:#00f;text-decoration:underline;">' . htmlspecialchars($school_email, ENT_QUOTES, 'UTF-8') . '</span>' : '' ?>
            </p>
          <?php endif; ?>
        </td>
      </tr>
    </table>
    <h3 style="text-align:center;"><?= htmlspecialchars($ekskul_title, ENT_QUOTES, 'UTF-8') ?></h3>
    <p class="meta">Kelas: <strong><?= htmlspecialchars($selected_class_name, ENT_QUOTES, 'UTF-8') ?></strong><br>Tahun Ajaran: <strong><?= htmlspecialchars($tahun_ajaran, ENT_QUOTES, 'UTF-8') ?></strong><br>Tanggal Cetak: <?= htmlspecialchars($tanggal, ENT_QUOTES, 'UTF-8') ?></p>
    <table class="data">
      <thead>
        <tr><th style="width:12%;">No</th><th style="width:28%;">NISN</th><th>Nama Siswa</th></tr>
      </thead>
      <tbody><?= $rows_html ?></tbody>
    </table>
    <div class="ttd">
      <div class="ttd-right">
        <?= htmlspecialchars($tempat, ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($tanggal, ENT_QUOTES, 'UTF-8') ?><br>
        Kepala Madrasah<br>
        <img class="ttd-qr" src="<?= htmlspecialchars($qr_url, ENT_QUOTES, 'UTF-8') ?>" alt="QR Tanda Tangan" referrerpolicy="no-referrer"><br>
        <strong><u><?= htmlspecialchars($kepala, ENT_QUOTES, 'UTF-8') ?></u></strong><br>
        NIP: <?= htmlspecialchars($nip, ENT_QUOTES, 'UTF-8') ?>
      </div>
    </div>
  </div>
  <?php if ($pdf_auto_print): ?>
  <script>
    window.addEventListener('load', function () {
      setTimeout(function () { window.print(); }, 250);
    });
  </script>
  <?php endif; ?>
</body>
</html>
        <?php
        exit;
    }
}

$js_page = [<<<'JS'
$(function () {
  function filterCards() {
    var q = ($('#ekskulSearchInput').val() || '').toLowerCase().trim();
    var shown = 0;
    $('#ekskulCardsContainer .ekskul-card-col').each(function () {
      var hay = String($(this).attr('data-search') || '');
      var ok = q === '' || hay.indexOf(q) !== -1;
      $(this).toggle(ok);
      if (ok) { shown++; }
    });
    $('#ekskulVisibleCount').text(shown);
  }
  $('#ekskulSearchInput').on('input', filterCards);

  $('#checkAllSiswa').on('change', function () {
    $('.check-siswa').prop('checked', $(this).is(':checked'));
  });

  $('#searchSiswaTersedia').on('input', function () {
    var q = ($(this).val() || '').toLowerCase().trim();
    $('.siswa-tersedia-row').each(function () {
      var hay = String($(this).attr('data-search') || '');
      $(this).toggle(q === '' || hay.indexOf(q) !== -1);
    });
  });

  $(document).on('submit', '.form-keluarkan-angota', function (e) {
    e.preventDefault();
    var form = this;
    Swal.fire({
      title: 'Konfirmasi',
      text: 'Keluarkan anggota ini dari daftar?',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Ya, keluarkan',
      cancelButtonText: 'Batal',
      reverseButtons: true
    }).then(function (result) {
      if (result.isConfirmed) {
        form.submit();
      }
    });
  });
});
JS
];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<style>
.ekskul-tabs-wrap {
    display: flex; align-items: center; gap: 8px;
    overflow-x: auto; padding: 4px 2px 10px;
    -webkit-overflow-scrolling: touch; scrollbar-width: thin;
}
.ekskul-tabs-wrap::-webkit-scrollbar { height: 4px; }
.ekskul-tabs-wrap::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
.ekskul-tab-pill {
    display: inline-flex; align-items: center; gap: 7px;
    flex: 0 0 auto; padding: 9px 15px; border-radius: 999px !important;
    font-size: .86rem; font-weight: 700; white-space: nowrap;
    text-decoration: none !important; border: 1.5px solid #e2e8f0;
    background: #fff; color: #475569; box-shadow: 0 2px 6px rgba(15, 23, 42, .05);
    transition: all .2s ease;
}
.ekskul-tab-pill:hover { color: #2563eb; border-color: #bfdbfe; }
.ekskul-tab-pill.active {
    background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
    border-color: transparent; color: #fff;
    box-shadow: 0 6px 16px rgba(79, 70, 229, .28);
}
.ekskul-tab-count {
    font-size: .7rem; font-weight: 800; padding: 2px 7px;
    border-radius: 999px; background: #eef2ff; color: #4338ca;
}
.ekskul-tab-pill.active .ekskul-tab-count {
    background: rgba(255, 255, 255, .25); color: #fff;
}
.ekskul-toolbar-actions { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.ekskul-toolbar .ekskul-search { flex: 0 0 260px; width: 260px; }
.ekskul-toolbar .app-select-wrap { flex: 0 0 260px; }
.ekskul-toolbar .app-select-btn { width: 260px; }
.ekskul-toolbar .app-select-wrap .dropdown-menu { left: auto; right: 0; }
.ekskul-toolbar-actions .btn {
    border-radius: 12px; font-size: .8rem; font-weight: 700;
    display: inline-flex; align-items: center; gap: 6px; padding: 8px 13px;
}
.ekskul-kta-card {
    position: relative; display: flex; flex-direction: column;
    height: 100%; background: #fff; border: 1px solid #e2e8f0;
    border-radius: 16px; overflow: hidden;
    box-shadow: 0 6px 18px rgba(15, 23, 42, .06);
    transition: transform .18s, box-shadow .18s;
}
.ekskul-kta-card:hover { transform: translateY(-3px); box-shadow: 0 12px 28px rgba(15, 23, 42, .12); }
.ekskul-kta-top {
    position: relative; padding: 12px 14px; color: #fff;
    display: flex; align-items: center; gap: 10px; overflow: hidden;
    background: linear-gradient(135deg, #4338ca 0%, #2563eb 100%);
}
.ekskul-kta-top::after {
    content: ""; position: absolute; right: -26px; top: -30px;
    width: 110px; height: 110px; border-radius: 50%; background: rgba(255, 255, 255, .12);
}
.ekskul-kta-emblem {
    width: 34px; height: 34px; border-radius: 50%; flex: 0 0 auto;
    background: rgba(255, 255, 255, .2); border: 1.5px solid rgba(255, 255, 255, .35);
    display: grid; place-items: center; font-size: 1rem;
}
.ekskul-kta-top-text { min-width: 0; line-height: 1.15; }
.ekskul-kta-top-text .org {
    font-size: .66rem; letter-spacing: .09em; font-weight: 800;
    text-transform: uppercase; opacity: .9;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 46vw;
}
.ekskul-kta-top-text .title { font-size: .86rem; font-weight: 800; letter-spacing: .02em; }
.ekskul-kta-serial {
    margin-left: auto; font-size: .66rem; font-weight: 800;
    padding: 4px 9px; border-radius: 999px; white-space: nowrap;
    background: rgba(255, 255, 255, .22); border: 1px solid rgba(255, 255, 255, .35);
}
.ekskul-kta-body { position: relative; padding: 14px; display: flex; gap: 13px; flex: 1 1 auto; }
.ekskul-kta-body::before {
    content: "\f5a2"; font-family: "Font Awesome 5 Free"; font-weight: 900;
    position: absolute; right: 6px; bottom: -6px; font-size: 5.5rem;
    color: rgba(15, 23, 42, .035); pointer-events: none;
}
.ekskul-kta-photo { position: relative; flex: 0 0 auto; }
.ekskul-kta-photo .photo-frame {
    width: 72px; height: 88px; border-radius: 12px; overflow: hidden;
    background: linear-gradient(135deg, #dbeafe, #e0f2fe); border: 2px solid #fff;
    box-shadow: 0 3px 10px rgba(15, 23, 42, .15); display: grid; place-items: center;
}
.ekskul-kta-photo img, .ekskul-kta-photo .ekskul-initials {
    width: 100%; height: 100%; object-fit: cover;
    display: grid; place-items: center; font-size: 1.4rem; font-weight: 800; color: #1e40af;
    background: linear-gradient(135deg, #dbeafe, #e0f2fe);
}
.ekskul-kta-nta {
    position: absolute; left: 50%; transform: translateX(-50%); bottom: -9px;
    background: #0f172a; color: #fff; font-size: .6rem; font-weight: 800;
    padding: 3px 8px; border-radius: 999px; white-space: nowrap; border: 2px solid #fff;
}
.ekskul-kta-info { min-width: 0; flex: 1; }
.ekskul-kta-name {
    font-size: .98rem; font-weight: 800; color: #0f172a;
    line-height: 1.25; margin: 0 0 5px; overflow-wrap: anywhere;
}
.ekskul-kta-meta { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 8px; }
.ekskul-kta-pill {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: .68rem; font-weight: 700; padding: 3px 8px;
    border-radius: 999px; background: #f1f5f9; color: #334155;
}
.ekskul-kta-pill.blue { background: #eff6ff; color: #1d4ed8; }
.ekskul-kta-pill.green { background: #ecfdf5; color: #15803d; }
.ekskul-kta-detail {
    display: grid; grid-template-columns: 1fr 1fr; gap: 4px 10px;
    font-size: .72rem; color: #475569;
}
.ekskul-kta-detail .lbl {
    display: block; font-size: .62rem; color: #94a3b8;
    text-transform: uppercase; letter-spacing: .05em; font-weight: 800;
}
.ekskul-kta-detail .val { font-weight: 700; color: #1e293b; overflow-wrap: anywhere; }
.ekskul-kta-foot {
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    padding: 9px 14px; border-top: 1px dashed #e2e8f0; background: #fbfdff;
}
.ekskul-kta-status {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: .7rem; font-weight: 800; color: #15803d; margin: 0;
}
.ekskul-kta-status .dot {
    width: 7px; height: 7px; border-radius: 50%; background: #22c55e;
    box-shadow: 0 0 0 3px rgba(34, 197, 94, .16);
}
.ekskul-kta-foot .btn { border-radius: 10px; font-size: .72rem; font-weight: 700; padding: 6px 10px; }
@media (max-width: 575.98px) {
    .ekskul-kta-body { gap: 11px; padding: 13px 12px; }
    .ekskul-kta-photo .photo-frame { width: 64px; height: 78px; }
    .ekskul-kta-name { font-size: .92rem; }
    .ekskul-kta-detail { font-size: .69rem; }
    .ekskul-kta-top { padding: 10px 12px; }
    .ekskul-kta-top-text .title { font-size: .78rem; }
    .ekskul-kta-serial { font-size: .6rem; padding: 3px 7px; }
    .ekskul-toolbar-actions { width: 100%; }
    .ekskul-toolbar-actions .btn { flex: 1 1 0; justify-content: center; }
    .ekskul-toolbar .ekskul-search,
    .ekskul-toolbar .app-select-wrap,
    .ekskul-toolbar .app-select-btn { flex: 1 1 100%; width: 100%; }
}
@media (max-width: 991.98px) {
    #ekskulCardsView { padding-bottom: 12px; }
}
</style>

<?php
$members_count = count($members);
$available_count = count($available_students);
?>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><?= htmlspecialchars($ekskul_title) ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>
        <div class="section-body">

            <div class="ekskul-tabs-wrap">
                <?php if (!empty($ekskul_list)): ?>
                    <?php foreach ($ekskul_list as $e): ?>
                        <?php
                            $eid = (int)($e['id_ekstrakurikuler'] ?? 0);
                            $e_active = ($eid === $ekskul_id);
                        ?>
                        <a href="?ekskul=<?= $eid ?><?= $selected_class_id > 0 ? '&kelas=' . (int)$selected_class_id : '' ?>"
                           class="ekskul-tab-pill <?= $e_active ? 'active' : '' ?>"
                           aria-selected="<?= $e_active ? 'true' : 'false' ?>">
                            <i class="fas fa-medal"></i>
                            <span><?= htmlspecialchars((string)($e['nama_ekstrakurikuler'] ?? '')) ?></span>
                            <span class="ekskul-tab-count"><?= (int)($ekskul_counts[$eid] ?? 0) ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="text-muted small">Belum ada data ekstrakurikuler (selain Pramuka).</span>
                <?php endif; ?>
            </div>

            <div class="app-summary-row">
                <div class="app-summary-chip">
                    <div class="ico" style="background:#eef2ff;color:#4338ca;"><i class="fas fa-users"></i></div>
                    <div>
                        <div class="lbl">Total Anggota</div>
                        <div class="val"><?= (int)$total_members_count ?> siswa</div>
                    </div>
                </div>
                <div class="app-summary-chip">
                    <div class="ico" style="background:#ecfdf5;color:#15803d;"><i class="fas fa-door-open"></i></div>
                    <div>
                        <div class="lbl"><?= $selected_class_id > 0 ? 'Kelas ' . htmlspecialchars($selected_class_name) : 'Kelas' ?></div>
                        <div class="val"><?= $selected_class_id > 0 ? (int)$members_count . ' siswa' : '-' ?></div>
                    </div>
                </div>
                <div class="app-summary-chip">
                    <div class="ico" style="background:#fff7ed;color:#c2410c;"><i class="fas fa-user-plus"></i></div>
                    <div>
                        <div class="lbl">Belum Terdaftar</div>
                        <div class="val"><?= $selected_class_id > 0 ? (int)$available_count . ' siswa' : '-' ?></div>
                    </div>
                </div>
            </div>

            <div class="app-toolbar ekskul-toolbar">
                <div class="app-search ekskul-search">
                    <i class="fas fa-search"></i>
                    <input type="search" id="ekskulSearchInput" placeholder="Cari nama atau NISN…" autocomplete="off">
                </div>

                <div class="dropdown app-select-wrap">
                    <button class="app-select-btn" type="button" id="dropdownFilterKelas" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <i class="fas fa-door-open"></i>
                        <span><?= $selected_class_id > 0 ? 'Kelas ' . htmlspecialchars($selected_class_name) : 'Pilih Kelas' ?></span>
                        <i class="fas fa-chevron-down app-select-caret"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-right" aria-labelledby="dropdownFilterKelas">
                        <a class="dropdown-item <?= $selected_class_id <= 0 ? 'app-select-selected' : '' ?>" href="?ekskul=<?= (int)$ekskul_id ?>">
                            <i class="fas fa-list mr-2"></i>Pilih Kelas
                            <?php if ($selected_class_id <= 0): ?><i class="fas fa-check app-select-check"></i><?php endif; ?>
                        </a>
                        <?php foreach ($classes as $c): ?>
                            <?php $c_selected = ($selected_class_id === (int)$c['id_kelas']); ?>
                            <a class="dropdown-item <?= $c_selected ? 'app-select-selected' : '' ?>"
                               href="?ekskul=<?= (int)$ekskul_id ?>&kelas=<?= (int)$c['id_kelas'] ?>">
                                <i class="fas fa-door-open mr-2"></i><?= htmlspecialchars($c['nama_kelas']) ?>
                                <?php if ($c_selected): ?><i class="fas fa-check app-select-check"></i><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ($selected_class_id > 0): ?>
                <div class="ekskul-toolbar-actions">
                    <?php if ($can_manage_anggota_ekskul): ?>
                        <button type="button" class="btn btn-success" data-toggle="modal" data-target="#modalTambahKolektif">
                            <i class="fas fa-plus"></i><span>Tambah</span>
                        </button>
                    <?php endif; ?>
                    <a href="?ekskul=<?= (int)$ekskul_id ?>&kelas=<?= (int)$selected_class_id ?>&export=excel" class="btn btn-info">
                        <i class="fas fa-file-excel"></i><span>Excel</span>
                    </a>
                    <a href="?ekskul=<?= (int)$ekskul_id ?>&kelas=<?= (int)$selected_class_id ?>&export=pdf&amp;auto=1" target="_blank" class="btn btn-danger">
                        <i class="fas fa-file-pdf"></i><span>PDF</span>
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <div id="ekskulCardsView">
                <?php if ($selected_class_id <= 0): ?>
                    <div class="alert alert-light border text-center py-5">
                        <i class="fas fa-door-open fa-3x text-muted mb-3 d-block"></i>
                        <div class="font-weight-bold text-dark">Pilih kelas terlebih dahulu.</div>
                        <div class="small text-muted mt-1">Daftar anggota akan tampil sebagai kartu.</div>
                    </div>
                <?php elseif (empty($members)): ?>
                    <div class="alert alert-light border text-center py-5">
                        <i class="fas fa-users-slash fa-3x text-muted mb-3 d-block"></i>
                        <div class="font-weight-bold text-dark">Belum ada anggota aktif pada kelas ini.</div>
                        <div class="small text-muted mt-1">Tambahkan lewat tombol <strong>Tambah</strong>.</div>
                    </div>
                <?php else: ?>
                    <div class="row app-cards-row" id="ekskulCardsContainer">
                        <?php foreach ($members as $i => $m): ?>
                            <?php
                                $m_nama = (string)($m['nama_siswa'] ?? '');
                                $m_nisn = trim((string)($m['nisn'] ?? ''));
                                $m_jk = strtoupper(trim((string)($m['jenis_kelamin'] ?? '')));
                                $m_jk_label = ($m_jk === 'P' || $m_jk === 'PEREMPUAN') ? 'Putri' : ($m_jk === '' ? '' : 'Putra');
                                $m_masuk = (string)($m['tanggal_masuk'] ?? '');
                                $m_masuk_fmt = ($m_masuk !== '' && $m_masuk !== '0000-00-00 00:00:00') ? date('d M Y', strtotime($m_masuk)) : '-';
                                $m_initials = mb_strtoupper(mb_substr(trim($m_nama), 0, 2));
                                $m_fallback = '<div class="ekskul-initials">' . htmlspecialchars($m_initials, ENT_QUOTES, 'UTF-8') . '</div>';
                                $m_avatar = getPersonAvatarHtml((string)($m['foto'] ?? ''), $m_fallback, 'ekskul-photo-img');
                                $m_search = mb_strtolower($m_nama . ' ' . $m_nisn . ' ' . $selected_class_name);
                            ?>
                            <div class="col-12 col-md-6 mb-3 ekskul-card-col"
                                 data-search="<?= htmlspecialchars($m_search, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="ekskul-kta-card">
                                    <div class="ekskul-kta-top">
                                        <div class="ekskul-kta-emblem"><i class="fas fa-medal"></i></div>
                                        <div class="ekskul-kta-top-text">
                                            <div class="org"><?= htmlspecialchars($ekskul_school_name) ?></div>
                                            <div class="title">Kartu Anggota <?= htmlspecialchars($ekskul_name !== '' ? $ekskul_name : 'Ekstrakurikuler') ?></div>
                                        </div>
                                        <div class="ekskul-kta-serial">#<?= $i + 1 ?></div>
                                    </div>
                                    <div class="ekskul-kta-body">
                                        <div class="ekskul-kta-photo">
                                            <div class="photo-frame"><?= $m_avatar ?></div>
                                            <div class="ekskul-kta-nta"><?= $m_nisn !== '' ? htmlspecialchars($m_nisn, ENT_QUOTES, 'UTF-8') : 'NISN -' ?></div>
                                        </div>
                                        <div class="ekskul-kta-info">
                                            <div class="ekskul-kta-name"><?= htmlspecialchars($m_nama, ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="ekskul-kta-meta">
                                                <span class="ekskul-kta-pill blue"><i class="fas fa-door-open"></i> Kelas <?= htmlspecialchars($selected_class_name) ?></span>
                                                <span class="ekskul-kta-pill green"><i class="fas fa-medal"></i> <?= htmlspecialchars($ekskul_name !== '' ? $ekskul_name : 'Ekskul') ?></span>
                                                <?php if ($m_jk_label !== ''): ?>
                                                    <span class="ekskul-kta-pill"><?= $m_jk_label ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="ekskul-kta-detail">
                                                <div>
                                                    <span class="lbl">NISN</span>
                                                    <span class="val"><?= $m_nisn !== '' ? htmlspecialchars($m_nisn, ENT_QUOTES, 'UTF-8') : '-' ?></span>
                                                </div>
                                                <div>
                                                    <span class="lbl">Kelas</span>
                                                    <span class="val"><?= htmlspecialchars($selected_class_name) ?></span>
                                                </div>
                                                <div>
                                                    <span class="lbl">Tanggal Masuk</span>
                                                    <span class="val"><?= htmlspecialchars($m_masuk_fmt, ENT_QUOTES, 'UTF-8') ?></span>
                                                </div>
                                                <div>
                                                    <span class="lbl">Status</span>
                                                    <span class="val" style="color:#15803d;">Aktif</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="ekskul-kta-foot">
                                        <span class="ekskul-kta-status"><span class="dot"></span> Aktif</span>
                                        <?php if ($can_manage_anggota_ekskul): ?>
                                            <form method="POST" class="form-keluarkan-angota m-0">
                                                <input type="hidden" name="ekskul" value="<?= (int)$ekskul_id ?>">
                                                <input type="hidden" name="id_kelas" value="<?= (int)$selected_class_id ?>">
                                                <input type="hidden" name="action" value="keluarkan">
                                                <input type="hidden" name="id_anggota" value="<?= (int)$m['id'] ?>">
                                                <button type="submit" class="btn btn-warning btn-sm">
                                                    <i class="fas fa-user-minus"></i> Keluarkan
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-muted small mt-1">
                        Menampilkan <span id="ekskulVisibleCount"><?= (int)$members_count ?></span> dari <?= (int)$members_count ?> anggota
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </section>
</div>

<?php if ($can_manage_anggota_ekskul && $selected_class_id > 0): ?>
<div class="modal fade" id="modalTambahKolektif" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Anggota Kolektif - Kelas <?= htmlspecialchars($selected_class_name) ?></h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_collective">
                    <input type="hidden" name="ekskul" value="<?= (int)$ekskul_id ?>">
                    <input type="hidden" name="id_kelas" value="<?= (int)$selected_class_id ?>">

                    <?php if (!empty($available_students)): ?>
                        <div class="form-group">
                            <div class="custom-control custom-checkbox mb-2">
                                <input type="checkbox" class="custom-control-input" id="checkAllSiswa">
                                <label class="custom-control-label" for="checkAllSiswa">Pilih Semua</label>
                            </div>
                            <div class="app-search mt-2">
                                <i class="fas fa-search"></i>
                                <input type="search" id="searchSiswaTersedia" placeholder="Cari nama atau NISN…" autocomplete="off">
                            </div>
                        </div>
                        <div class="table-responsive" style="max-height:50vh;overflow:auto;">
                            <table class="table table-sm table-bordered mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;">#</th>
                                        <th>NISN</th>
                                        <th>Nama Siswa</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($available_students as $s): ?>
                                        <?php $s_search = mb_strtolower((string)($s['nama_siswa'] ?? '') . ' ' . (string)($s['nisn'] ?? '')); ?>
                                        <tr class="siswa-tersedia-row" data-search="<?= htmlspecialchars($s_search, ENT_QUOTES, 'UTF-8') ?>">
                                            <td class="text-center">
                                                <input type="checkbox" class="check-siswa" name="selected_siswa[]" value="<?= (int)$s['id_siswa'] ?>">
                                            </td>
                                            <td><?= htmlspecialchars($s['nisn'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($s['nama_siswa']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info mb-0">Semua siswa di kelas ini sudah menjadi anggota aktif ekstrakurikuler lain. Keluarkan dari ekstrakurikuler lain jika ingin menambahkan.</div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" <?= empty($available_students) ? 'disabled' : '' ?>>
                        <i class="fas fa-save"></i> Tambahkan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include '../templates/footer.php'; ?>
<style>
#toast-container > .toast:before {
  font-family: "Font Awesome 5 Free";
  font-weight: 900;
}

#toast-container > .toast-success:before {
  content: "\f00c";
}
</style>
<?php if (!empty($toast_message)): ?>
<script type="text/javascript">
toastr.options = {
  closeButton: true,
  progressBar: true,
  timeOut: 2200,
  positionClass: 'toast-top-right'
};
toastr.success(<?= json_encode((string)$toast_message) ?>, 'Berhasil');
</script>
<?php endif; ?>
<?php if (!empty($message)): ?>
<script type="text/javascript">
Swal.fire({
  icon: <?= json_encode(($message['type'] ?? '') === 'danger' ? 'error' : (($message['type'] ?? '') === 'warning' ? 'warning' : 'success')) ?>,
  title: <?= json_encode(($message['type'] ?? '') === 'danger' ? 'Gagal' : (($message['type'] ?? '') === 'warning' ? 'Perhatian' : 'Berhasil')) ?>,
  text: <?= json_encode((string)($message['text'] ?? '')) ?>,
  timer: 2200,
  showConfirmButton: false
});
</script>
<?php endif; ?>
