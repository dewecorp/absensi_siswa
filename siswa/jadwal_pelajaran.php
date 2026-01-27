<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has siswa level
if (!isAuthorized(['siswa'])) {
    redirect('../login.php');
}

// Get student data to find out their class
$id_siswa = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT s.id_kelas, k.nama_kelas FROM tb_siswa s JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_siswa = ?");
$stmt->execute([$id_siswa]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    echo "Data siswa tidak ditemukan.";
    exit;
}

$id_kelas = $student['id_kelas'];
$nama_kelas = $student['nama_kelas'];

// Set page title
$page_title = 'Jadwal Pelajaran';

include '../templates/header.php';
include '../templates/sidebar.php';

// Fetch schedule data
// We get all schedule for this class, ordered by day and time
$query = "
    SELECT 
        j.*,
        m.nama_mapel,
        m.kode_mapel,
        g.nama_guru,
        jm.waktu_mulai,
        jm.waktu_selesai
    FROM tb_jadwal_pelajaran j
    LEFT JOIN tb_mata_pelajaran m ON j.mapel_id = m.id_mapel
    LEFT JOIN tb_guru g ON j.guru_id = g.id_guru
    LEFT JOIN tb_jam_mengajar jm ON (j.jam_ke = jm.jam_ke AND j.jenis = jm.jenis)
    WHERE j.kelas_id = ?
    ORDER BY 
        CASE 
            WHEN j.hari = 'Sabtu' THEN 1
            WHEN j.hari = 'Ahad' THEN 2
            WHEN j.hari = 'Senin' THEN 3
            WHEN j.hari = 'Selasa' THEN 4
            WHEN j.hari = 'Rabu' THEN 5
            WHEN j.hari = 'Kamis' THEN 6
            WHEN j.hari = 'Jumat' THEN 7
            ELSE 8
        END,
        j.jenis,
        jm.waktu_mulai ASC,
        CAST(j.jam_ke AS UNSIGNED) ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute([$id_kelas]);
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organize data by Type (Reguler/Ramadhan) and Day
$grouped_schedules = [];
foreach ($schedules as $schedule) {
    $jenis = $schedule['jenis'];
    $hari = $schedule['hari'];
    
    if (!isset($grouped_schedules[$jenis])) {
        $grouped_schedules[$jenis] = [];
    }
    if (!isset($grouped_schedules[$jenis][$hari])) {
        $grouped_schedules[$jenis][$hari] = [];
    }
    
    $grouped_schedules[$jenis][$hari][] = $schedule;
}

// Define the display days order
$display_days = ['Sabtu', 'Ahad', 'Senin', 'Selasa', 'Rabu', 'Kamis'];

// Function to map DB day name to display name if needed (e.g. Minggu -> Ahad)
function getDisplayDayName($day) {
    return $day;
}

?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Jadwal Pelajaran - Kelas <?php echo htmlspecialchars($nama_kelas); ?></h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Jadwal Pelajaran</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Jadwal Pelajaran</h4>
                            <div class="card-header-action">
                                <ul class="nav nav-pills" id="myTab2" role="tablist">
                                    <li class="nav-item">
                                        <a class="nav-link active" id="reguler-tab" data-toggle="tab" href="#reguler" role="tab" aria-controls="reguler" aria-selected="true">Jadwal Reguler</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" id="ramadhan-tab" data-toggle="tab" href="#ramadhan" role="tab" aria-controls="ramadhan" aria-selected="false">Jadwal Ramadhan</a>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="tab-content tab-bordered" id="myTab3Content">
                                <!-- Jadwal Reguler Tab -->
                                <div class="tab-pane fade show active" id="reguler" role="tabpanel" aria-labelledby="reguler-tab">
                                    <div class="mb-3 text-right">
                                        <a href="../config/export_jadwal_pdf.php?kelas_id=<?php echo $id_kelas; ?>&jenis=Reguler" target="_blank" class="btn btn-danger btn-icon icon-left"><i class="fas fa-file-pdf"></i> Export PDF</a>
                                        <form method="POST" action="../config/export_jadwal_excel.php" target="_blank" class="d-inline">
                                            <input type="hidden" name="kelas_id" value="<?php echo $id_kelas; ?>">
                                            <input type="hidden" name="jenis" value="Reguler">
                                            <button type="submit" class="btn btn-success btn-icon icon-left"><i class="fas fa-file-excel"></i> Export Excel</button>
                                        </form>
                                    </div>
                                    <div class="row">
                                        <?php foreach ($display_days as $hari): ?>
                                            <?php 
                                            // Check if we have data for this day
                                            // The key in $grouped_schedules uses the DB value (e.g. 'Minggu')
                                            $items = isset($grouped_schedules['Reguler'][$hari]) ? $grouped_schedules['Reguler'][$hari] : [];
                                            ?>
                                            <div class="col-md-6 col-lg-4 mb-4">
                                                <div class="card card-info h-100">
                                                    <div class="card-header">
                                                        <h4><?php echo htmlspecialchars(getDisplayDayName($hari)); ?></h4>
                                                    </div>
                                                    <div class="card-body p-0">
                                                        <?php if (empty($items)): ?>
                                                            <div class="p-4 text-center text-muted">
                                                                <i class="fas fa-calendar-times mb-2" style="font-size: 2em;"></i>
                                                                <p class="mb-0">Tidak ada jadwal</p>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="table-responsive">
                                                                <table class="table table-striped table-sm mb-0">
                                                                    <thead>
                                                                        <tr>
                                                                            <th class="text-center" width="10%">Jam Ke</th>
                                                                            <th class="text-center" width="20%">Waktu</th>
                                                                            <th>Mata Pelajaran</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <?php foreach ($items as $item): ?>
                                                                            <tr>
                                                                                <td class="text-center align-middle">
                                                                                    <span class="badge badge-light">
                                                                                        <?php echo htmlspecialchars($item['jam_ke']); ?>
                                                                                    </span>
                                                                                </td>
                                                                                <td class="text-center align-middle">
                                                                                    <?php if (!empty($item['waktu_mulai']) && !empty($item['waktu_selesai'])): ?>
                                                                                    <div class="small text-muted">
                                                                                        <?php echo date('H:i', strtotime($item['waktu_mulai'])); ?> - <?php echo date('H:i', strtotime($item['waktu_selesai'])); ?>
                                                                                    </div>
                                                                                    <?php else: ?>
                                                                                        -
                                                                                    <?php endif; ?>
                                                                                </td>
                                                                                <td class="align-middle">
                                                                                    <div class="font-weight-bold text-primary"><?php echo htmlspecialchars($item['nama_mapel'] ?? '-'); ?></div>
                                                                                </td>
                                                                            </tr>
                                                                        <?php endforeach; ?>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Jadwal Ramadhan Tab -->
                                <div class="tab-pane fade" id="ramadhan" role="tabpanel" aria-labelledby="ramadhan-tab">
                                    <div class="mb-3 text-right">
                                        <a href="../config/export_jadwal_pdf.php?kelas_id=<?php echo $id_kelas; ?>&jenis=Ramadhan" target="_blank" class="btn btn-danger btn-icon icon-left"><i class="fas fa-file-pdf"></i> Export PDF</a>
                                        <form method="POST" action="../config/export_jadwal_excel.php" target="_blank" class="d-inline">
                                            <input type="hidden" name="kelas_id" value="<?php echo $id_kelas; ?>">
                                            <input type="hidden" name="jenis" value="Ramadhan">
                                            <button type="submit" class="btn btn-success btn-icon icon-left"><i class="fas fa-file-excel"></i> Export Excel</button>
                                        </form>
                                    </div>
                                    <div class="row">
                                        <?php foreach ($display_days as $hari): ?>
                                            <?php 
                                            $items = isset($grouped_schedules['Ramadhan'][$hari]) ? $grouped_schedules['Ramadhan'][$hari] : [];
                                            ?>
                                            <div class="col-md-6 col-lg-4 mb-4">
                                                <div class="card card-success h-100">
                                                    <div class="card-header">
                                                        <h4><?php echo htmlspecialchars(getDisplayDayName($hari)); ?></h4>
                                                    </div>
                                                    <div class="card-body p-0">
                                                        <?php if (empty($items)): ?>
                                                            <div class="p-4 text-center text-muted">
                                                                <i class="fas fa-calendar-times mb-2" style="font-size: 2em;"></i>
                                                                <p class="mb-0">Tidak ada jadwal</p>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="table-responsive">
                                                                <table class="table table-striped table-sm mb-0">
                                                                    <thead>
                                                                        <tr>
                                                                            <th class="text-center" width="10%">Jam Ke</th>
                                                                            <th class="text-center" width="20%">Waktu</th>
                                                                            <th>Mata Pelajaran</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <?php foreach ($items as $item): ?>
                                                                            <tr>
                                                                                <td class="text-center align-middle">
                                                                                    <span class="badge badge-light">
                                                                                        <?php echo htmlspecialchars($item['jam_ke']); ?>
                                                                                    </span>
                                                                                </td>
                                                                                <td class="text-center align-middle">
                                                                                    <?php if (!empty($item['waktu_mulai']) && !empty($item['waktu_selesai'])): ?>
                                                                                    <div class="small text-muted">
                                                                                        <?php echo date('H:i', strtotime($item['waktu_mulai'])); ?> - <?php echo date('H:i', strtotime($item['waktu_selesai'])); ?>
                                                                                    </div>
                                                                                    <?php else: ?>
                                                                                        -
                                                                                    <?php endif; ?>
                                                                                </td>
                                                                                <td class="align-middle">
                                                                                    <div class="font-weight-bold text-success"><?php echo htmlspecialchars($item['nama_mapel'] ?? '-'); ?></div>
                                                                                </td>
                                                                            </tr>
                                                                        <?php endforeach; ?>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
