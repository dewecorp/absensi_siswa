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

$ekskul_type = isset($ekskul_type) ? (string)$ekskul_type : 'pencak_silat';
$ekskul_title = isset($ekskul_title) ? (string)$ekskul_title : 'Data Anggota';
$table_name = $ekskul_type === 'rebana' ? 'tb_anggota_rebana' : 'tb_anggota_pencak_silat';
$other_table_name = $ekskul_type === 'rebana' ? 'tb_anggota_pencak_silat' : 'tb_anggota_rebana';
$other_ekskul_label = $ekskul_type === 'rebana' ? 'Pencak Silat' : 'Rebana';
$slug = $ekskul_type === 'rebana' ? 'rebana' : 'pencak_silat';

$page_title = $ekskul_title;
$message = null;
$toast_message = null;

$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js',
];

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$table_name} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_siswa INT NOT NULL,
            status ENUM('aktif','keluar') NOT NULL DEFAULT 'aktif',
            tanggal_masuk DATETIME NULL,
            tanggal_keluar DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_id_siswa (id_siswa),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    $message = ['type' => 'danger', 'text' => 'Gagal menyiapkan tabel anggota: ' . $e->getMessage()];
}

if ($message === null && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ok'])) {
    $ok = (string)($_GET['ok'] ?? '');
    if ($ok === 'added') {
        $blocked = (int)($_GET['blocked'] ?? 0);
        if ($blocked > 0) {
            $message = ['type' => 'warning', 'text' => "Sebagian data ditambahkan. {$blocked} siswa tidak ditambahkan karena masih aktif di {$other_ekskul_label}."];
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
                $check_other_active_stmt = $pdo->prepare("SELECT COUNT(*) FROM {$other_table_name} WHERE id_siswa = ? AND status = 'aktif'");
                $upsert_stmt = $pdo->prepare("
                    INSERT INTO {$table_name} (id_siswa, status, tanggal_masuk, tanggal_keluar)
                    VALUES (?, 'aktif', NOW(), NULL)
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
                    $check_other_active_stmt->execute([$id_siswa]);
                    if ((int)$check_other_active_stmt->fetchColumn() > 0) {
                        $blocked_conflict++;
                        continue;
                    }
                    $upsert_stmt->execute([$id_siswa]);
                    $added++;
                }

                $pdo->commit();

                header('Location: ' . basename($_SERVER['SCRIPT_NAME']) . '?kelas=' . $selected_class_id . '&ok=added&blocked=' . $blocked_conflict);
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
                $stmt = $pdo->prepare("UPDATE {$table_name} SET status = 'keluar', tanggal_keluar = NOW() WHERE id = ? LIMIT 1");
                $stmt->execute([$id_anggota]);

                $kelas_redirect = (int)($_POST['id_kelas'] ?? 0);
                if ($kelas_redirect > 0) {
                    header('Location: ' . basename($_SERVER['SCRIPT_NAME']) . '?kelas=' . $kelas_redirect . '&ok=removed');
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
    $stmt_total_members = $pdo->query("
        SELECT COUNT(*) 
        FROM {$table_name}
        WHERE status = 'aktif'
    ");
    $total_members_count = (int)$stmt_total_members->fetchColumn();
} catch (Exception $e) {
    $total_members_count = 0;
}
if ($selected_class_id > 0) {
    try {
        $stmt_members = $pdo->prepare("
            SELECT a.id, s.nisn, s.nama_siswa
            FROM {$table_name} a
            INNER JOIN tb_siswa s ON s.id_siswa = a.id_siswa
            WHERE a.status = 'aktif' AND s.id_kelas = ?
            ORDER BY s.nama_siswa ASC
        ");
        $stmt_members->execute([$selected_class_id]);
        $members = $stmt_members->fetchAll(PDO::FETCH_ASSOC);

        $stmt_available = $pdo->prepare("
            SELECT s.id_siswa, s.nisn, s.nama_siswa
            FROM tb_siswa s
            LEFT JOIN {$table_name} a ON a.id_siswa = s.id_siswa AND a.status = 'aktif'
            LEFT JOIN {$other_table_name} ao ON ao.id_siswa = s.id_siswa AND ao.status = 'aktif'
            WHERE s.id_kelas = ? AND a.id IS NULL AND ao.id IS NULL
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

if ($export_type !== '' && $selected_class_id > 0) {
    $school_profile = getSchoolProfile($pdo);
    $nama_madrasah = (string)($school_profile['nama_madrasah'] ?? 'Madrasah');
    $tahun_ajaran = (string)($school_profile['tahun_ajaran'] ?? date('Y'));
    $kepala = (string)($school_profile['nama_kepala'] ?? '-');
    $nip = (string)($school_profile['nip_kepala'] ?? '-');
    $tempat = (string)($school_profile['tempat_jadwal'] ?? 'Padang');
    $tanggal = date('d F Y');

    if ($export_type === 'excel') {
        $slug_label = $ekskul_type === 'rebana' ? 'Rebana' : 'Pencak Silat';
        $excel_filename = 'Data_Anggota_' . str_replace(' ', '_', $slug_label) . '_' . preg_replace('/[^A-Za-z0-9_-]/', '-', str_replace('/', '-', trim($tahun_ajaran)));
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $excel_filename . '.xls"');
        echo '<html><head><meta charset="UTF-8"></head><body>';
        echo '<h2 style="text-align:center;margin-bottom:4px;">' . htmlspecialchars(strtoupper($nama_madrasah)) . '</h2>';
        echo '<h3 style="text-align:center;margin-top:0;">' . htmlspecialchars($ekskul_title) . '</h3>';
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

        $back_url = htmlspecialchars(basename($_SERVER['SCRIPT_NAME']) . '?kelas=' . $selected_class_id, ENT_QUOTES, 'UTF-8');
        $doc_title_base = 'Data Anggota ' . ($ekskul_type === 'rebana' ? 'Rebana' : 'Pencak Silat') . ' ' . str_replace('/', '-', trim($tahun_ajaran));
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
    .doc-header { display: flex; align-items: center; gap: 12px; border-bottom: 2px solid #333; padding-bottom: 8px; }
    .header-logo { width: 62px; height: 62px; object-fit: contain; flex-shrink: 0; }
    .header-text { flex: 1; text-align: center; }
    h2, h3, p.meta { margin: 0; text-align: center; }
    .meta { margin-top: 6px; margin-bottom: 12px; }
    table.data { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.data th, table.data td { border: 1px solid #555; padding: 6px 8px; }
    table.data th { background: #f2f2f2; }
    .ttd { margin-top: 12mm; width: 100%; }
    .ttd-right { width: 40%; margin-left: 60%; text-align: left; }
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
    <div class="doc-header">
      <?php if ($school_logo !== ''): ?>
        <img class="header-logo" src="<?= htmlspecialchars($school_logo, ENT_QUOTES, 'UTF-8') ?>" alt="Logo Sekolah">
      <?php endif; ?>
      <div class="header-text">
        <h2><?= htmlspecialchars(strtoupper($nama_madrasah), ENT_QUOTES, 'UTF-8') ?></h2>
        <h3><?= htmlspecialchars($ekskul_title, ENT_QUOTES, 'UTF-8') ?></h3>
      </div>
    </div>
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
  if ($('#table-anggota').length) {
    $('#table-anggota').DataTable();
  }

  $('#checkAllSiswa').on('change', function () {
    $('.check-siswa').prop('checked', $(this).is(':checked'));
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

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><?= htmlspecialchars($ekskul_title) ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>
        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4>Filter Kelas</h4>
                </div>
                <div class="card-body">
                    <form method="GET" class="form-inline" id="formFilterKelasAnggota">
                        <label class="mr-2" for="selectKelasAnggota">Pilih Kelas:</label>
                        <select name="kelas" id="selectKelasAnggota" class="form-control" style="min-width: 220px;" onchange="this.form.submit();">
                            <option value="">-- Pilih Kelas --</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?= (int)$c['id_kelas'] ?>" <?= $selected_class_id === (int)$c['id_kelas'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['nama_kelas']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="badge badge-primary ml-2">
                            Jumlah Anggota: <?= $selected_class_id > 0 ? (int)count($members) : (int)$total_members_count ?>
                        </span>
                    </form>
                </div>
            </div>

            <?php if ($selected_class_id > 0): ?>
            <div class="card">
                <div class="card-header">
                    <h4>Daftar Anggota Kelas <?= htmlspecialchars($selected_class_name) ?></h4>
                    <div class="card-header-action">
                        <?php if ($can_manage_anggota_ekskul): ?>
                        <button class="btn btn-success" data-toggle="modal" data-target="#modalTambahKolektif">
                            <i class="fas fa-plus"></i> Tambah Anggota
                        </button>
                        <?php endif; ?>
                        <a href="?kelas=<?= (int)$selected_class_id ?>&export=excel" class="btn btn-info">
                            <i class="fas fa-file-excel"></i> Ekspor Excel
                        </a>
                        <a href="?kelas=<?= (int)$selected_class_id ?>&export=pdf&amp;auto=1" target="_blank" class="btn btn-danger">
                            <i class="fas fa-file-pdf"></i> Ekspor PDF
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-anggota">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>NISN</th>
                                    <th>Nama Siswa</th>
                                    <?php if ($can_manage_anggota_ekskul): ?><th>Aksi</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($members)): ?>
                                    <?php foreach ($members as $i => $m): ?>
                                        <tr>
                                            <td><?= $i + 1 ?></td>
                                            <td><?= htmlspecialchars($m['nisn'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($m['nama_siswa']) ?></td>
                                            <?php if ($can_manage_anggota_ekskul): ?><td>
                                                <form method="POST" class="d-inline form-keluarkan-angota">
                                                    <input type="hidden" name="id_kelas" value="<?= (int)$selected_class_id ?>">
                                                    <input type="hidden" name="action" value="keluarkan">
                                                    <input type="hidden" name="id_anggota" value="<?= (int)$m['id'] ?>">
                                                    <button type="submit" class="btn btn-warning btn-sm">
                                                        <i class="fas fa-user-minus"></i> Keluarkan
                                                    </button>
                                                </form>
                                            </td><?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <?php if (empty($members)): ?>
                            <div class="alert alert-info mb-0">Belum ada anggota aktif pada kelas ini.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($can_manage_anggota_ekskul): ?>
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
                                <input type="hidden" name="id_kelas" value="<?= (int)$selected_class_id ?>">

                                <?php if (!empty($available_students)): ?>
                                    <div class="form-group">
                                        <div class="custom-control custom-checkbox mb-2">
                                            <input type="checkbox" class="custom-control-input" id="checkAllSiswa">
                                            <label class="custom-control-label" for="checkAllSiswa">Pilih Semua</label>
                                        </div>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered">
                                            <thead>
                                                <tr>
                                                    <th style="width: 40px;">#</th>
                                                    <th>NISN</th>
                                                    <th>Nama Siswa</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($available_students as $s): ?>
                                                    <tr>
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
            <?php endif; ?>
        </div>
    </section>
</div>

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
