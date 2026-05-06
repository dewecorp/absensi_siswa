<?php
// Determine session name before including functions.php
if (isset($_GET['session_type'])) {
    $type = $_GET['session_type'];
    $session_name = 'SIS_LOGIN';
    if ($type == 'admin') $session_name = 'SIS_ADMIN';
    elseif ($type == 'guru') $session_name = 'SIS_GURU';
    elseif ($type == 'siswa') $session_name = 'SIS_SISWA';
    elseif ($type == 'wali') $session_name = 'SIS_WALI';
    elseif ($type == 'tata_usaha') $session_name = 'SIS_TU';
    elseif ($type == 'kepala_madrasah' || $type == 'kepala') $session_name = 'SIS_KEPALA';
    
    if (session_status() == PHP_SESSION_NONE) {
        $save_path = sys_get_temp_dir();
        if (is_string($save_path) && $save_path !== '') {
            session_save_path($save_path);
        }
        session_name($session_name);
        session_start();
    }
}

require_once 'database.php';
require_once 'functions.php';

// Check auth
if (!isAuthorized(['admin', 'tata_usaha', 'guru', 'kepala_madrasah', 'wali'])) {
    die("Unauthorized access");
}

// Get Filters
$kelas_id = isset($_GET['kelas']) ? (int)$_GET['kelas'] : null;
$guru_id = isset($_GET['guru']) ? (int)$_GET['guru'] : null;

// Build Query
$where_clauses = [];
$params = [];
$filter_title = '';
$school_profile = getSchoolProfile($pdo);
$periode_ta = getRentangTanggalTahunAjaran($school_profile['tahun_ajaran'] ?? null);

if ($periode_ta) {
    $where_clauses[] = "jl.tanggal >= ? AND jl.tanggal <= ?";
    $params[] = $periode_ta['mulai'];
    $params[] = $periode_ta['sampai'];
}

if ($kelas_id) {
    $where_clauses[] = "jl.id_kelas = ?";
    $params[] = $kelas_id;
    
    $stmt_class = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas = ?");
    $stmt_class->execute([$kelas_id]);
    $class_name = $stmt_class->fetchColumn();
    $filter_title .= ($filter_title ? ' - ' : '') . ($class_name ?? '');
}

if ($guru_id) {
    $where_clauses[] = "jl.id_guru = ?";
    $params[] = $guru_id;
    
    $stmt_g = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
    $stmt_g->execute([$guru_id]);
    $guru_name = $stmt_g->fetchColumn();
    $filter_title .= ($filter_title ? ' - ' : '') . $guru_name;
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(' AND ', $where_clauses) : "";

$query = "SELECT jl.*, g.nama_guru, k.nama_kelas 
          FROM tb_jurnal_les jl 
          LEFT JOIN tb_guru g ON jl.id_guru = g.id_guru 
          LEFT JOIN tb_kelas k ON jl.id_kelas = k.id_kelas
          $where_sql
          ORDER BY jl.tanggal DESC, jl.waktu ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$journal_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get School Profile
$kepala_madrasah = $school_profile['kepala_madrasah'] ?? '-';

// Digital Signature Logic
$qr_content = 'Validasi Tanda Tangan Digital: ' . $kepala_madrasah . ' - ' . ($school_profile['nama_madrasah'] ?? 'Madrasah');
$qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qr_content);
$qr_img = '<img src="' . $qr_url . '" alt="QR Signature" style="width: 80px; height: 80px; margin: 10px auto; display: block;">';

$logo_file = $school_profile['logo'] ?? '';
$logo_path = '';
if ($logo_file && file_exists(__DIR__ . '/../assets/img/' . $logo_file)) {
    $logo_path = '../assets/img/' . $logo_file;
} elseif (file_exists(__DIR__ . '/../assets/img/logo.png')) {
    $logo_path = '../assets/img/logo.png';
}

$page_title = "Laporan Jurnal Les" . ($filter_title ? " - " . $filter_title : "");

?>
<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($page_title) ?></title>
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        @media print {
            .no-print { display: none; }
        }
        body { font-family: Arial, sans-serif; font-size: 10pt; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 3px double black; padding-bottom: 10px; position: relative; min-height: 90px; }
        .header img { width: 80px; position: absolute; left: 10px; top: 5px; }
        .header h2, .header h3, .header p { margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid black; padding: 5px; text-align: left; vertical-align: top; }
        th { background-color: #f0f0f0; text-align: center; }
        .text-center { text-align: center; }
        .signature-table { margin-top: 30px; border: none; }
        .signature-table td { border: none; text-align: center; padding: 20px; }
    </style>
</head>
<body>
    <button class="no-print" onclick="window.print()" style="position: fixed; top: 10px; right: 10px; padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer;">Print PDF</button>

    <div class="header">
        <?php if ($logo_path): ?>
            <img src="<?= $logo_path ?>" alt="Logo">
        <?php endif; ?>
        <h3><?= strtoupper($school_profile['nama_yayasan'] ?? 'YAYASAN') ?></h3>
        <h2><?= strtoupper($school_profile['nama_sekolah'] ?? $school_profile['nama_madrasah'] ?? 'NAMA SEKOLAH') ?></h2>
        <p style="margin: 2px 0; font-size: 12px;">Tahun Ajaran: <?= $school_profile['tahun_ajaran'] ?? '-' ?> | Semester: <?= $school_profile['semester'] ?? '-' ?></p>
    </div>

    <h3 class="text-center">LAPORAN JURNAL LES</h3>
    <?php if ($filter_title): ?>
        <p class="text-center"><strong><?= htmlspecialchars($filter_title) ?></strong></p>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="15%">Tanggal</th>
                <th width="15%">Waktu</th>
                <th width="20%">Mata Pelajaran</th>
                <th width="25%">Materi Pokok</th>
                <th width="20%">Guru</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($journal_entries)): ?>
                <tr>
                    <td colspan="6" class="text-center">Tidak ada data jurnal les.</td>
                </tr>
            <?php else: ?>
                <?php $no = 1; foreach ($journal_entries as $journal): ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td class="text-center"><?= date('d-m-Y', strtotime($journal['tanggal'])) ?></td>
                    <td class="text-center"><?= htmlspecialchars($journal['waktu']) ?></td>
                    <td><?= htmlspecialchars($journal['mapel']) ?></td>
                    <td><?= htmlspecialchars($journal['materi']) ?></td>
                    <td><?= htmlspecialchars($journal['nama_guru'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <table class="signature-table">
        <tr>
            <td width="33%"></td>
            <td width="33%"></td>
            <td width="33%">
                <?php
                $bulan_indo = [
                    'January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret', 'April' => 'April',
                    'May' => 'Mei', 'June' => 'Juni', 'July' => 'Juli', 'August' => 'Agustus',
                    'September' => 'September', 'October' => 'Oktober', 'November' => 'November', 'December' => 'Desember'
                ];
                $tanggal_indo = date('d') . ' ' . $bulan_indo[date('F')] . ' ' . date('Y');
                ?>
                <?= $tanggal_indo ?><br>
                Kepala Madrasah<br>
                <?= $qr_img ?>
                <strong><u><?= $kepala_madrasah ?></u></strong><br>
                NIP. <?= $school_profile['nip_kepala'] ?? '-' ?>
            </td>
        </tr>
    </table>

    <script>
        // Auto print when page loads
        window.onload = function() { 
            window.print(); 
            // Optional: close window after print
            // window.onafterprint = function() { window.close(); }
        }
    </script>
</body>
</html>
