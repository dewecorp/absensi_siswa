<?php
// Set session name based on request BEFORE including functions.php
if (isset($_GET['session_type'])) {
    $type = $_GET['session_type'];
    if ($type == 'admin') session_name('SIS_ADMIN');
    elseif ($type == 'guru') session_name('SIS_GURU');
    elseif ($type == 'siswa') session_name('SIS_SISWA');
    elseif ($type == 'wali') session_name('SIS_WALI');
    elseif ($type == 'tata_usaha') session_name('SIS_TU');
    elseif ($type == 'kepala_madrasah' || $type == 'kepala') session_name('SIS_KEPALA');
    
    session_start();
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
$jam_ke = isset($_GET['jam_ke']) ? $_GET['jam_ke'] : null;

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

$query = "SELECT j.*, g.nama_guru, k.nama_kelas 
          FROM tb_jurnal j 
          LEFT JOIN tb_guru g ON j.id_guru = g.id_guru 
          LEFT JOIN tb_kelas k ON j.id_kelas = k.id_kelas
          WHERE " . implode(' AND ', $where_clauses) . "
          ORDER BY j.tanggal DESC, j.jam_ke DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$journal_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get School Profile
$school_profile = getSchoolProfile($pdo);

$filename = "Jurnal_Mengajar_" . date('Ymd_His') . ".xls";

// Headers for Excel download
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

?>
<!DOCTYPE html>
<html>
<head>
    <style>
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid black; padding: 5px; }
        th { background-color: #f0f0f0; text-align: center; }
        .text-center { text-align: center; }
    </style>
</head>
<body>
    <h3 class="text-center"><?= strtoupper($school_profile['nama_yayasan'] ?? 'YAYASAN') ?></h3>
    <h4 class="text-center"><?= strtoupper($school_profile['nama_sekolah'] ?? $school_profile['nama_madrasah'] ?? 'NAMA SEKOLAH') ?></h4>
    <h4 class="text-center" style="margin-top: 20px;">LAPORAN JURNAL MENGAJAR</h4>
    <?php if ($filter_title): ?>
        <p class="text-center"><strong><?= htmlspecialchars($filter_title) ?></strong></p>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Tanggal</th>
                <th>Jam Ke</th>
                <th>Kelas</th>
                <th>Mata Pelajaran</th>
                <th>Materi Pokok</th>
                <th>Guru</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($journal_entries)): ?>
                <tr>
                    <td colspan="7" class="text-center">Tidak ada data jurnal.</td>
                </tr>
            <?php else: ?>
                <?php $no = 1; foreach ($journal_entries as $journal): ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td class="text-center"><?= date('d-m-Y', strtotime($journal['tanggal'])) ?></td>
                    <td class="text-center"><?= htmlspecialchars($journal['jam_ke']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($journal['nama_kelas'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($journal['mapel']) ?></td>
                    <td><?= htmlspecialchars($journal['materi']) ?></td>
                    <td><?= htmlspecialchars($journal['nama_guru'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
