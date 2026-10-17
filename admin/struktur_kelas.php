<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha', 'wali'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$is_wali = ($user_level === 'wali');
$message = null;

// Siapkan tabel struktur kelas
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_struktur_kelas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_siswa INT NOT NULL,
        jabatan VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_id_siswa (id_siswa)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    $message = ['type' => 'danger', 'text' => 'Gagal menyiapkan tabel struktur kelas: ' . $e->getMessage()];
}

$jabatan_options = [
    'Ketua Kelas', 'Wakil Ketua Kelas', 'Sekretaris', 'Wakil Sekretaris',
    'Bendahara', 'Wakil Bendahara', 'Seksi Kebersihan', 'Seksi Keamanan',
    'Seksi Kerohanian', 'Seksi Kesehatan', 'Anggota',
];

$classes = [];
try {
    $classes = $pdo->query("SELECT id_kelas, nama_kelas, wali_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // ignore
}

$selected_class_id = 0;
$selected_class_name = '';

if ($is_wali) {
    $teacher_name = trim((string)($_SESSION['nama_guru'] ?? ''));
    if ($teacher_name === '') {
        $teacher_name = trim((string)($_SESSION['username'] ?? ''));
    }
    if ($teacher_name !== '') {
        foreach ($classes as $c) {
            if (trim((string)($c['wali_kelas'] ?? '')) === $teacher_name) {
                $selected_class_id = (int)$c['id_kelas'];
                $selected_class_name = (string)$c['nama_kelas'];
                break;
            }
        }
    }
    if ($selected_class_id <= 0) {
        $message = ['type' => 'warning', 'text' => 'Anda belum terdaftar sebagai wali kelas.'];
    }
} else {
    $selected_class_id = (int)($_GET['kelas'] ?? 0);
    foreach ($classes as $c) {
        if ((int)$c['id_kelas'] === $selected_class_id) {
            $selected_class_name = (string)$c['nama_kelas'];
            break;
        }
    }
}

// Handle POST (khusus wali): tambah / edit / hapus jabatan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_wali) {
    $action = (string)($_POST['action'] ?? '');
    $qs = $is_wali ? '?ok=saved' : ('?kelas=' . $selected_class_id . '&ok=saved');
    try {
        if ($action === 'tambah' && isset($_POST['id_siswa'], $_POST['jabatan'])) {
            $id_siswa = (int)$_POST['id_siswa'];
            $jabatan = trim((string)$_POST['jabatan']);
            if ($id_siswa > 0 && $jabatan !== '') {
                $stmt = $pdo->prepare("INSERT INTO tb_struktur_kelas (id_siswa, jabatan) VALUES (?, ?) ON DUPLICATE KEY UPDATE jabatan = VALUES(jabatan)");
                $stmt->execute([$id_siswa, $jabatan]);
            }
        } elseif ($action === 'edit' && isset($_POST['id'], $_POST['jabatan'])) {
            $id = (int)$_POST['id'];
            $jabatan = trim((string)$_POST['jabatan']);
            if ($id > 0 && $jabatan !== '') {
                $pdo->prepare("UPDATE tb_struktur_kelas SET jabatan = ? WHERE id = ?")->execute([$jabatan, $id]);
            }
        } elseif ($action === 'hapus' && isset($_POST['id'])) {
            $id = (int)$_POST['id'];
            if ($id > 0) {
                $pdo->prepare("DELETE FROM tb_struktur_kelas WHERE id = ?")->execute([$id]);
            }
        }
    } catch (Exception $e) {
        $qs = $is_wali ? '?err=1' : ('?kelas=' . $selected_class_id . '&err=1');
        header('Location: struktur_kelas.php' . $qs);
        exit;
    }
    header('Location: struktur_kelas.php' . $qs);
    exit;
}

if (isset($_GET['ok']) && $_GET['ok'] === 'saved') {
    $message = ['type' => 'success', 'text' => 'Struktur kelas berhasil disimpan.'];
} elseif (isset($_GET['err']) && $_GET['err'] === '1') {
    $message = ['type' => 'danger', 'text' => 'Gagal menyimpan struktur kelas.'];
}

// Data struktur kelas (hanya siswa yang punya jabatan)
$struktur = [];
// Siswa kelas yang belum punya jabatan (untuk dropdown tambah)
$available_students = [];
if ($selected_class_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT sk.id, s.id_siswa, s.nama_siswa, s.nisn, s.jenis_kelamin, sk.jabatan
            FROM tb_struktur_kelas sk
            INNER JOIN tb_siswa s ON s.id_siswa = sk.id_siswa
            WHERE s.id_kelas = ?
            ORDER BY sk.id ASC
        ");
        $stmt->execute([$selected_class_id]);
        $struktur = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtAvail = $pdo->prepare("
            SELECT s.id_siswa, s.nama_siswa
            FROM tb_siswa s
            LEFT JOIN tb_struktur_kelas sk ON sk.id_siswa = s.id_siswa
            WHERE s.id_kelas = ? AND sk.id IS NULL
            ORDER BY s.nama_siswa ASC
        ");
        $stmtAvail->execute([$selected_class_id]);
        $available_students = $stmtAvail->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $message = ['type' => 'danger', 'text' => 'Gagal memuat data: ' . $e->getMessage()];
    }
}

$page_title = 'Struktur Kelas';

function getJabatanBadgeStyle(string $jabatan): array {
    $j = strtolower(trim($jabatan));
    if (strpos($j, 'ketua') !== false) {
        return ['bg' => '#e0f2fe', 'color' => '#0284c7', 'icon' => 'bi bi-person-badge-fill'];
    } elseif (strpos($j, 'wakil') !== false) {
        return ['bg' => '#dbeafe', 'color' => '#1d4ed8', 'icon' => 'bi bi-person-badge'];
    } elseif (strpos($j, 'sekretaris') !== false) {
        return ['bg' => '#f3e8ff', 'color' => '#7e22ce', 'icon' => 'bi bi-pencil-square'];
    } elseif (strpos($j, 'bendahara') !== false) {
        return ['bg' => '#fef3c7', 'color' => '#b45309', 'icon' => 'bi bi-wallet2'];
    } elseif (strpos($j, 'keamanan') !== false || strpos($j, 'ketertiban') !== false) {
        return ['bg' => '#fee2e2', 'color' => '#b91c1c', 'icon' => 'bi bi-shield-check'];
    } elseif (strpos($j, 'kebersihan') !== false) {
        return ['bg' => '#dcfce7', 'color' => '#15803d', 'icon' => 'bi bi-stars'];
    } elseif (strpos($j, 'keagamaan') !== false || strpos($j, 'ibadah') !== false) {
        return ['bg' => '#ccfbf1', 'color' => '#0f766e', 'icon' => 'bi bi-book-half'];
    }
    return ['bg' => '#f1f5f9', 'color' => '#475569', 'icon' => 'bi bi-award-fill'];
}

// --- Ekspor Excel / PDF ---
$export_type = (string)($_GET['export'] ?? '');
if ($export_type !== '' && $selected_class_id > 0) {
    $school_profile = getSchoolProfile($pdo);
    $nama_yayasan = (string)($school_profile['nama_yayasan'] ?? 'YAYASAN PENDIDIKAN ISLAM');
    $nama_madrasah = (string)($school_profile['nama_madrasah'] ?? 'Madrasah');
    $tahun_ajaran = (string)($school_profile['tahun_ajaran'] ?? date('Y'));
    $tempat = (string)($school_profile['tempat_jadwal'] ?? 'Padang');
    $tanggal = date('d F Y');

    // Wali kelas untuk tanda tangan
    $wali_kelas_name = '';
    $wali_kelas_nip = '-';
    try {
        $stK = $pdo->prepare("SELECT wali_kelas FROM tb_kelas WHERE id_kelas = ?");
        $stK->execute([$selected_class_id]);
        $wali_kelas_name = (string)($stK->fetchColumn() ?: '');
        if ($wali_kelas_name !== '') {
            $stG = $pdo->prepare("SELECT nuptk FROM tb_guru WHERE nama_guru = ? LIMIT 1");
            $stG->execute([$wali_kelas_name]);
            $wali_kelas_nip = (string)($stG->fetchColumn() ?: '-');
        }
    } catch (Exception $e) {
        // ignore
    }
    if ($wali_kelas_name === '') {
        $wali_kelas_name = '.........................';
    }

    if ($export_type === 'excel') {
        $excel_filename = 'Struktur_Kelas_' . str_replace(' ', '_', $selected_class_name) . '_' . str_replace('/', '-', trim($tahun_ajaran));
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $excel_filename . '.xls"');
        echo '<html><head><meta charset="UTF-8"></head><body>';
        echo '<h2 style="text-align:center;margin-bottom:4px;">' . htmlspecialchars(strtoupper($nama_yayasan)) . '</h2>';
        echo '<h3 style="text-align:center;margin:0;">' . htmlspecialchars(strtoupper($nama_madrasah)) . '</h3>';
        echo '<h4 style="text-align:center;margin-top:0;">Struktur Kelas ' . htmlspecialchars(strtoupper($selected_class_name)) . '</h4>';
        echo '<p style="text-align:center;font-weight:bold;">TAHUN AJARAN ' . htmlspecialchars(strtoupper($tahun_ajaran)) . '</p>';
        echo '<table border="1" cellspacing="0" cellpadding="6" style="border-collapse:collapse;width:100%;">';
        echo '<tr><th>No</th><th>Nama Siswa</th><th>Jabatan</th></tr>';
        foreach ($struktur as $i => $row) {
            echo '<tr>';
            echo '<td>' . ($i + 1) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['nama_siswa']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$row['jabatan']) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '<br><br><table style="width:100%;"><tr><td style="width:60%"></td><td style="text-align:center;">';
        echo htmlspecialchars($tempat) . ', ' . htmlspecialchars($tanggal) . '<br><br><br><br>Wali Kelas<br><br><br><br>';
        echo '<strong style="font-size: 16px;">' . htmlspecialchars($wali_kelas_name) . '</strong>';
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
        foreach ($struktur as $i => $row) {
            $rows_html .= '<tr>'
                . '<td>' . ($i + 1) . '</td>'
                . '<td>' . htmlspecialchars((string)$row['nama_siswa'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string)$row['jabatan'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '</tr>';
        }
        if ($rows_html === '') {
            $rows_html = '<tr><td colspan="3" style="text-align:center;">Tidak ada data.</td></tr>';
        }

        $back_url = 'struktur_kelas.php' . ($selected_class_id > 0 && !$is_wali ? '?kelas=' . $selected_class_id : '');
        $doc_title_base = 'Struktur Kelas ' . $selected_class_name . ' ' . str_replace('/', '-', trim($tahun_ajaran));
        $school_logo = !empty($school_profile['logo']) ? ('../assets/img/' . $school_profile['logo']) : '';
        $qr_payload = "Dokumen: {$doc_title_base}\nKelas: {$selected_class_name}\nTanggal: {$tanggal}\nWali Kelas: {$wali_kelas_name}";
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
      @page { size: 210mm 330mm; margin: 10mm; }
      .toolbar { display: none !important; }
    }
    body { font-family: Arial, sans-serif; font-size: 11pt; margin: 0; background: #f3f4f6; }
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
      <a href="<?= htmlspecialchars($back_url, ENT_QUOTES, 'UTF-8') ?>">Kembali</a>
    </div>
    <div class="hint">Tab ini dapat di-reload untuk melihat data terbaru.</div>
  </div>
  <div class="sheet">
    <div class="doc-header">
      <?php if ($school_logo !== ''): ?>
        <img class="header-logo" src="<?= htmlspecialchars($school_logo, ENT_QUOTES, 'UTF-8') ?>" alt="Logo Sekolah">
      <?php endif; ?>
      <div class="header-text">
        <h2><?= htmlspecialchars(strtoupper($nama_yayasan), ENT_QUOTES, 'UTF-8') ?></h2>
        <h3><?= htmlspecialchars(strtoupper($nama_madrasah), ENT_QUOTES, 'UTF-8') ?></h3>
        <h3>STRUKTUR KELAS <?= htmlspecialchars(strtoupper($selected_class_name), ENT_QUOTES, 'UTF-8') ?></h3>
        <p style="font-size: 11pt; font-weight: bold; margin: 2px 0 0 0;">TAHUN AJARAN <?= htmlspecialchars(strtoupper($tahun_ajaran), ENT_QUOTES, 'UTF-8') ?></p>
      </div>
    </div>
    <table class="data">
      <thead>
        <tr><th style="width:10%;">No</th><th style="width:60%;">Nama Siswa</th><th>Jabatan</th></tr>
      </thead>
      <tbody><?= $rows_html ?></tbody>
    </table>
    <div class="ttd">
      <div class="ttd-right" style="text-align: center;">
        <?= htmlspecialchars($tempat, ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($tanggal, ENT_QUOTES, 'UTF-8') ?><br>
        <br><br>
        Wali Kelas<br>
        <img class="ttd-qr" src="<?= htmlspecialchars($qr_url, ENT_QUOTES, 'UTF-8') ?>" alt="QR Tanda Tangan" referrerpolicy="no-referrer"><br>
        <strong style="font-size: 13pt;"><?= htmlspecialchars($wali_kelas_name, ENT_QUOTES, 'UTF-8') ?></strong>
      </div>
    </div>
  </div>
  <?php if (isset($_GET['auto']) && (int)$_GET['auto'] === 1): ?>
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

$css_libs = [
    "https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css",
    "https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css",
];
$js_libs = [
    "https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.full.min.js",
    "https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js",
];

$js_page = [];
if ($is_wali) {
    $js_page[] = <<<'JS'
$(document).ready(function() {
    if ($.fn.select2) {
        $('#selectNamaSiswa').select2({
            dropdownParent: $('#modalTambahJabatan'),
            placeholder: '-- Pilih Siswa --',
            allowClear: true
        });
    }

    $('.btn-edit-jabatan').on('click', function() {
        $('#edit_id').val($(this).data('id'));
        $('#edit_nama').val($(this).data('nama'));
        $('#edit_jabatan').val($(this).data('jabatan'));
        $('#modalEditJabatan').modal('show');
    });

    $(document).on('submit', '.form-hapus-jabatan', function(e) {
        e.preventDefault();
        var form = this;
        Swal.fire({
            title: 'Konfirmasi',
            text: 'Hapus jabatan siswa ini?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Ya, hapus',
            cancelButtonText: 'Batal',
            reverseButtons: true
        }).then(function(result) {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
});
JS;
}

if (!empty($message)) {
    if (($message['type'] ?? '') === 'success') {
        $js_page[] = "toastr.options = { closeButton: true, progressBar: true, timeOut: 2200, positionClass: 'toast-top-right' }; toastr.success(" . json_encode((string)($message['text'] ?? '')) . ", 'Berhasil');";
    } else {
        $js_page[] = "Swal.fire({ icon: " . json_encode(($message['type'] ?? '') === 'danger' ? 'error' : 'warning') . ", title: " . json_encode(($message['type'] ?? '') === 'danger' ? 'Gagal' : 'Perhatian') . ", text: " . json_encode((string)($message['text'] ?? '')) . ", timer: 2200, showConfirmButton: false });";
    }
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<style>
/* Styling Kartu Struktur Kelas Ala Mobile App */
.struktur-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
}
.struktur-card {
    background: #ffffff;
    border-radius: 18px;
    border: 1px solid #f1f5f9;
    padding: 16px 18px;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.05);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.2s ease;
    position: relative;
    overflow: hidden;
}
.struktur-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 22px rgba(15, 23, 42, 0.09);
    border-color: #e2e8f0;
}
.struktur-card-left {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
    flex: 1;
}
.struktur-card-icon {
    width: 52px;
    height: 52px;
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    flex-shrink: 0;
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
    transition: transform 0.18s ease;
}
.struktur-card:hover .struktur-card-icon {
    transform: scale(1.05);
}
.struktur-card-info {
    min-width: 0;
    flex: 1;
}
.struktur-badge-pill {
    display: inline-flex;
    align-items: center;
    font-size: 11px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 999px;
    letter-spacing: 0.2px;
    margin-bottom: 5px;
    line-height: 1.2;
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
}
.struktur-card-name {
    font-size: 14.5px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.35;
    margin-bottom: 2px;
    word-break: break-word;
    overflow-wrap: break-word;
    white-space: normal;
}
.struktur-card-nisn {
    font-size: 11.5px;
    color: #64748b;
    font-weight: 500;
}
.struktur-card-actions {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}
.struktur-btn-act {
    width: 34px;
    height: 34px;
    border-radius: 11px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
    -webkit-appearance: none;
    appearance: none;
    cursor: pointer;
    font-size: 13px;
    transition: all 0.15s ease;
}
.struktur-btn-act:focus,
.struktur-btn-act:focus-visible {
    outline: none !important;
    box-shadow: none !important;
}
.struktur-btn-act:active {
    transform: scale(0.9);
}
.struktur-btn-edit {
    background: #fef3c7;
    color: #b45309;
}
.struktur-btn-edit:hover {
    background: #fde68a;
    color: #92400e;
}
.struktur-btn-del {
    background: #fee2e2;
    color: #b91c1c;
}
.struktur-btn-del:hover {
    background: #fecaca;
    color: #991b1b;
}
@media (max-width: 575.98px) {
    .struktur-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    .struktur-card {
        padding: 14px 16px;
        border-radius: 16px;
    }
    .struktur-card-icon {
        width: 48px;
        height: 48px;
        border-radius: 16px;
        font-size: 22px;
    }
    .struktur-card-name {
        font-size: 14px;
    }
}
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Struktur Kelas</h1>
            <?php echo render_breadcrumb(); ?>
        </div>
        <div class="section-body">
            <?php if (!$is_wali): ?>
            <div class="card">
                <div class="card-header">
                    <h4>Filter Kelas</h4>
                </div>
                <div class="card-body">
                    <form method="GET" class="form-row align-items-end">
                        <div class="form-group col-md-4 mb-0">
                            <label for="selectKelasStruktur">Pilih Kelas:</label>
                            <select name="kelas" id="selectKelasStruktur" class="form-control" style="width: 100%; max-width: 320px;" onchange="this.form.submit();">
                                <option value="">-- Pilih Kelas --</option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?= (int)$c['id_kelas'] ?>" <?= $selected_class_id === (int)$c['id_kelas'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['nama_kelas']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($selected_class_id > 0): ?>
            <div class="card">
                <div class="card-header">
                    <h4>Struktur Kelas <?= htmlspecialchars($selected_class_name) ?></h4>
                    <div class="card-header-action">
                        <?php if ($is_wali): ?>
                        <button type="button" class="btn btn-success" data-toggle="modal" data-target="#modalTambahJabatan">
                            <i class="fas fa-plus"></i> Tambah
                        </button>
                        <?php endif; ?>
                        <?php
                        $export_base = $is_wali
                            ? 'struktur_kelas.php?export='
                            : 'struktur_kelas.php?kelas=' . (int)$selected_class_id . '&export=';
                        ?>
                        <a href="<?= htmlspecialchars($export_base . 'excel', ENT_QUOTES) ?>" class="btn btn-info">
                            <i class="fas fa-file-excel"></i> Ekspor Excel
                        </a>
                        <a href="<?= htmlspecialchars($export_base . 'pdf&auto=1', ENT_QUOTES) ?>" target="_blank" class="btn btn-danger">
                            <i class="fas fa-file-pdf"></i> Ekspor PDF
                        </a>
                        <span class="badge badge-primary"><?= count($struktur) ?> Jabatan</span>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($struktur)): ?>
                        <div class="struktur-grid">
                            <?php foreach ($struktur as $i => $row): ?>
                                <?php
                                $badgeStyle = getJabatanBadgeStyle($row['jabatan']);
                                ?>
                                <div class="struktur-card">
                                    <div class="struktur-card-left">
                                        <div class="struktur-card-icon" style="background: <?= $badgeStyle['bg'] ?>; color: <?= $badgeStyle['color'] ?>;">
                                            <i class="<?= $badgeStyle['icon'] ?>"></i>
                                        </div>
                                        <div class="struktur-card-info">
                                            <span class="struktur-badge-pill" style="background: <?= $badgeStyle['bg'] ?>; color: <?= $badgeStyle['color'] ?>;">
                                                <?= htmlspecialchars($row['jabatan']) ?>
                                            </span>
                                            <div class="struktur-card-name" title="<?= htmlspecialchars($row['nama_siswa']) ?>">
                                                <?= htmlspecialchars($row['nama_siswa']) ?>
                                            </div>
                                            <div class="struktur-card-nisn">
                                                NISN: <?= !empty($row['nisn']) ? htmlspecialchars($row['nisn']) : '-' ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if ($is_wali): ?>
                                    <div class="struktur-card-actions">
                                        <button type="button" class="struktur-btn-act struktur-btn-edit btn-edit-jabatan"
                                                title="Edit Jabatan"
                                                data-id="<?= (int)$row['id'] ?>"
                                                data-nama="<?= htmlspecialchars($row['nama_siswa'], ENT_QUOTES) ?>"
                                                data-jabatan="<?= htmlspecialchars($row['jabatan'], ENT_QUOTES) ?>">
                                            <i class="bi bi-pencil-fill"></i>
                                        </button>
                                        <form method="POST" class="d-inline form-hapus-jabatan mb-0">
                                            <input type="hidden" name="action" value="hapus">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="struktur-btn-act struktur-btn-del" title="Hapus">
                                                <i class="bi bi-trash-fill"></i>
                                            </button>
                                        </form>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-people mb-2" style="font-size: 2.5rem; display: block; color: #cbd5e1;"></i>
                            <div>Belum ada data jabatan pada kelas ini.</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php if ($is_wali): ?>
<!-- Modal Tambah Jabatan -->
<div class="modal fade" id="modalTambahJabatan" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Jabatan - Kelas <?= htmlspecialchars($selected_class_name) ?></h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="tambah">
                    <div class="form-group">
                        <label>Nama Siswa</label>
                        <select name="id_siswa" id="selectNamaSiswa" class="form-control" required>
                            <option value="">-- Pilih Siswa --</option>
                            <?php foreach ($available_students as $s): ?>
                                <option value="<?= (int)$s['id_siswa'] ?>"><?= htmlspecialchars($s['nama_siswa']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($available_students)): ?>
                        <small class="form-text text-muted">Semua siswa di kelas ini sudah memiliki jabatan.</small>
                        <?php endif; ?>
                    </div>
                    <div class="form-group mb-0">
                        <label>Jabatan</label>
                        <select name="jabatan" class="form-control" required>
                            <option value="">-- Pilih Jabatan --</option>
                            <?php foreach ($jabatan_options as $j): ?>
                                <option value="<?= htmlspecialchars($j) ?>"><?= htmlspecialchars($j) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" <?= empty($available_students) ? 'disabled' : '' ?>>
                        <i class="fas fa-save"></i> Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit Jabatan -->
<div class="modal fade" id="modalEditJabatan" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Jabatan</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_id" value="">
                    <div class="form-group">
                        <label>Nama Siswa</label>
                        <input type="text" class="form-control" id="edit_nama" readonly>
                    </div>
                    <div class="form-group mb-0">
                        <label>Jabatan</label>
                        <select name="jabatan" id="edit_jabatan" class="form-control" required>
                            <option value="">-- Pilih Jabatan --</option>
                            <?php foreach ($jabatan_options as $j): ?>
                                <option value="<?= htmlspecialchars($j) ?>"><?= htmlspecialchars($j) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include '../templates/footer.php'; ?>
