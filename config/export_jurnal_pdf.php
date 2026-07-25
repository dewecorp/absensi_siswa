<?php
require_once 'database.php';
require_once 'functions.php';

// Check auth
if (!isAuthorized(['admin', 'tata_usaha', 'guru', 'kepala_madrasah', 'wali'])) {
    die("Unauthorized access");
}

// Get Filters
$kelas_id = isset($_GET['kelas']) ? (int)$_GET['kelas'] : null;
$guru_id = isset($_GET['guru']) ? (int)$_GET['guru'] : null;
$jam_ke = isset($_GET['jam_ke']) ? $_GET['jam_ke'] : null;
$jenis = isset($_GET['jenis']) ? $_GET['jenis'] : null;
$bulan = (isset($_GET['bulan']) && preg_match('/^\d{4}-\d{2}$/', $_GET['bulan'])) ? $_GET['bulan'] : null;
$bulan_indo = [
    '01' => 'Januari',
    '02' => 'Februari',
    '03' => 'Maret',
    '04' => 'April',
    '05' => 'Mei',
    '06' => 'Juni',
    '07' => 'Juli',
    '08' => 'Agustus',
    '09' => 'September',
    '10' => 'Oktober',
    '11' => 'November',
    '12' => 'Desember',
];

// Build Query
$where_clauses = ["j.mapel NOT IN ('Istirahat I', 'Istirahat II', 'Upacara Bendera', 'Asmaul Husna')", "j.jam_ke NOT IN ('A', 'B', 'C')"];
$params = [];
$filter_title = '';

if ($kelas_id) {
    $where_clauses[] = "j.id_kelas = ?";
    $params[] = $kelas_id;
    
    $stmt_class = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas = ?");
    $stmt_class->execute([$kelas_id]);
    $class_name = $stmt_class->fetchColumn();
    $filter_title .= ($filter_title ? ' - ' : '') . ($class_name ?? '');
}

if ($guru_id) {
    $where_clauses[] = "j.id_guru = ?";
    $params[] = $guru_id;
    
    $stmt_g = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
    $stmt_g->execute([$guru_id]);
    $guru_name = $stmt_g->fetchColumn();
    $filter_title .= ($filter_title ? ' - ' : '') . $guru_name;
}

if ($jam_ke) {
    $where_clauses[] = "FIND_IN_SET(?, j.jam_ke)";
    $params[] = $jam_ke;
    $filter_title .= ($filter_title ? ' - ' : '') . 'Jam Ke-' . $jam_ke;
}

if ($jenis) {
    $where_clauses[] = "j.jenis = ?";
    $params[] = $jenis;
    $filter_title .= ($filter_title ? ' - ' : '') . $jenis;
}

if ($bulan) {
    $where_clauses[] = "DATE_FORMAT(j.tanggal, '%Y-%m') = ?";
    $params[] = $bulan;
    $parts = explode('-', $bulan);
    $filter_title .= ($filter_title ? ' - ' : '') .
        ((count($parts) === 2 && isset($bulan_indo[$parts[1]])) ? ($bulan_indo[$parts[1]] . ' ' . $parts[0]) : $bulan);
}

// Filter by active academic year date range (same as admin page)
$school_profile = getSchoolProfile($pdo);
$periode_ta = getRentangTanggalTahunAjaran($school_profile['tahun_ajaran'] ?? null);
if ($periode_ta) {
    $where_clauses[] = 'j.tanggal >= ?';
    $where_clauses[] = 'j.tanggal <= ?';
    $params[] = $periode_ta['mulai'];
    $params[] = $periode_ta['sampai'];
}

$query = "SELECT j.*, g.nama_guru, k.nama_kelas 
          FROM tb_jurnal j 
          LEFT JOIN tb_guru g ON j.id_guru = g.id_guru 
          LEFT JOIN tb_kelas k ON j.id_kelas = k.id_kelas
          WHERE " . implode(' AND ', $where_clauses) . "
          ORDER BY j.tanggal DESC, j.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$journal_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get School Profile
$school_profile = getSchoolProfile($pdo);
$kepala_madrasah = $school_profile['kepala_madrasah'] ?? '-';
$semester_raw = trim((string)($school_profile['semester'] ?? ''));
if ($semester_raw === '') {
    $semester_display = '-';
} else {
    $semester_display = preg_replace('/^\s*semester\s*/i', '', $semester_raw);
    $semester_display = trim((string)$semester_display);
    if ($semester_display === '') {
        $semester_display = '-';
    }
}

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

$page_title = "Laporan Jurnal Mengajar" . ($filter_title ? " - " . $filter_title : "");

// HTML Output
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
        <p style="margin: 2px 0; font-size: 12px;">Tahun Ajaran: <?= $school_profile['tahun_ajaran'] ?? '-' ?> | Semester: <?= htmlspecialchars($semester_display) ?></p>
    </div>

    <h3 class="text-center">LAPORAN JURNAL MENGAJAR</h3>
    <?php if ($filter_title): ?>
        <p class="text-center"><strong><?= htmlspecialchars($filter_title) ?></strong></p>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="10%">Tanggal</th>
                <th width="8%">Jam Ke</th>
                <th width="12%">Jenis</th>
                <th width="12%">Kelas</th>
                <th width="15%">Mata Pelajaran</th>
                <th width="20%">Materi Pokok</th>
                <th width="18%">Guru</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($journal_entries)): ?>
                <tr>
                    <td colspan="8" class="text-center">Tidak ada data jurnal.</td>
                </tr>
            <?php else: ?>
                <?php $no = 1; foreach ($journal_entries as $journal): ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td class="text-center"><?= date('d-m-Y', strtotime($journal['tanggal'])) ?></td>
                    <td class="text-center"><?= htmlspecialchars($journal['jam_ke']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($journal['jenis'] ?? 'Reguler') ?></td>
                    <td class="text-center"><?= htmlspecialchars($journal['nama_kelas'] ?? '-') ?></td>
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
        window.onload = function() {
            // Auto print if needed, or let user click button
            window.print();
        }
    </script>
</body>
</html>
