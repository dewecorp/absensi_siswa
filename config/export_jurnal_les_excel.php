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

$filename = "Jurnal_Les_" . date('Ymd_His') . ".xls";

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
    <p class="text-center">Tahun Ajaran: <?= $school_profile['tahun_ajaran'] ?? '-' ?> | Semester: <?= $school_profile['semester'] ?? '-' ?></p>
    <h4 class="text-center" style="margin-top: 20px;">LAPORAN JURNAL LES</h4>
    <?php if ($filter_title): ?>
        <p class="text-center"><strong><?= htmlspecialchars($filter_title) ?></strong></p>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Tanggal</th>
                <th>Waktu</th>
                <th>Mata Pelajaran</th>
                <th>Materi Pokok</th>
                <th>Guru</th>
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
</body>
</html>
