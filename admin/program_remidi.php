<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

$page_title = 'Program Remidial';
$user_role = $_SESSION['level'];

// Get school profile
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'];
$semester_aktif = $school_profile['semester'];

// Get all classes
$stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all subjects
$subjects = getFilteredSubjects($pdo);

// Selected filters
$selected_class_id = isset($_GET['kelas']) ? $_GET['kelas'] : null;
$selected_mapel_id = isset($_GET['mapel']) ? $_GET['mapel'] : null;
$selected_exam_type = isset($_GET['jenis']) ? $_GET['jenis'] : null;

// Exam types
$exam_types = ['PTS', 'PAS', 'PAT', 'Pra Ujian Madrasah', 'Ujian Madrasah'];

$selected_class = null;
if ($selected_class_id) {
    foreach ($classes as $c) {
        if ($c['id_kelas'] == $selected_class_id) {
            $selected_class = $c;
            break;
        }
    }
}
$selected_mapel = null;
if ($selected_mapel_id) {
    foreach ($subjects as $m) {
        if ($m['id_mapel'] == $selected_mapel_id) {
            $selected_mapel = $m;
            break;
        }
    }
}

// Fetch remedial data for the table
$remedial_list = [];
if ($selected_class_id && $selected_mapel_id && $selected_exam_type) {
    $stmt = $pdo->prepare("
        SELECT r.*, s.nama_siswa, m.nama_mapel, g.nama_guru
        FROM tb_program_remidial r
        JOIN tb_siswa s ON r.id_siswa = s.id_siswa
        JOIN tb_mata_pelajaran m ON r.id_mapel = m.id_mapel
        LEFT JOIN tb_guru g ON r.id_guru = g.id_guru
        WHERE r.id_kelas = ? AND r.id_mapel = ? AND r.jenis_ulangan = ? 
        AND r.tahun_ajaran = ? AND r.semester = ?
        ORDER BY s.nama_siswa ASC
    ");
    $stmt->execute([$selected_class_id, $selected_mapel_id, $selected_exam_type, $tahun_ajaran, $semester_aktif]);
    $remedial_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$css_libs = [];
$js_libs = [];

require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><?= $page_title ?></h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Remidial</div>
                <div class="breadcrumb-item"><?= $page_title ?></div>
            </div>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="" class="mb-4">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Kelas</label>
                                    <select name="kelas" class="form-control" onchange="this.form.submit()">
                                        <option value="">Pilih Kelas</option>
                                        <?php foreach ($classes as $cls): ?>
                                            <option value="<?= $cls['id_kelas'] ?>" <?= $selected_class_id == $cls['id_kelas'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cls['nama_kelas']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Mata Pelajaran</label>
                                    <select name="mapel" class="form-control" onchange="this.form.submit()">
                                        <option value="">Pilih Mata Pelajaran</option>
                                        <?php foreach ($subjects as $mpl): ?>
                                            <option value="<?= $mpl['id_mapel'] ?>" <?= $selected_mapel_id == $mpl['id_mapel'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($mpl['nama_mapel']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Jenis Ulangan</label>
                                    <select name="jenis" class="form-control" onchange="this.form.submit()">
                                        <option value="">Pilih Jenis Ulangan</option>
                                        <?php foreach ($exam_types as $type): ?>
                                            <option value="<?= $type ?>" <?= $selected_exam_type == $type ? 'selected' : '' ?>>
                                                <?= $type ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </form>

                    <?php if ($selected_class_id && $selected_mapel_id && $selected_exam_type): ?>
                        <div class="nilai-desktop-view">
                        <div class="row mb-3">
                            <div class="col-md-6"></div>
                            <div class="col-md-6 text-right">
                                <div class="btn-group">
                                    <a href="../config/export_program_remidi_excel.php?kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>&jenis=<?= urlencode($selected_exam_type) ?>&session_type=admin" target="_blank" class="btn btn-success">
                                        <i class="fas fa-file-excel"></i> Export Excel
                                    </a>
                                    <a href="../config/export_program_remidi_pdf.php?kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>&jenis=<?= urlencode($selected_exam_type) ?>&session_type=admin" target="_blank" class="btn btn-danger">
                                        <i class="fas fa-file-pdf"></i> Export PDF
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th class="text-center" width="4%">No</th>
                                        <th class="text-center" width="8%">Tanggal</th>
                                        <th>Nama Siswa</th>
                                        <th>Guru</th>
                                        <th class="text-center" width="6%">KKM</th>
                                        <th class="text-center" width="7%">Nilai Asli</th>
                                        <th width="18%">Bentuk Remidial</th>
                                        <th class="text-center" width="7%">Nilai Remidi</th>
                                        <th class="text-center" width="8%">Keterangan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($remedial_list)): ?>
                                        <tr><td colspan="9" class="text-center">Belum ada data remedial.</td></tr>
                                    <?php else: $no = 1; foreach ($remedial_list as $r): ?>
                                        <tr>
                                            <td class="text-center"><?= $no++ ?></td>
                                            <td class="text-center"><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                                            <td><?= htmlspecialchars($r['nama_siswa']) ?></td>
                                            <td><?= htmlspecialchars($r['nama_guru'] ?? '-') ?></td>
                                            <td class="text-center"><?= (float)$r['kkm'] ?></td>
                                            <td class="text-center"><?= (float)$r['nilai_ulangan'] ?></td>
                                            <td><?= htmlspecialchars($r['bentuk_remidial']) ?></td>
                                            <td class="text-center"><?= (float)$r['nilai_tes_remidi'] ?></td>
                                            <td class="text-center">
                                                <span class="badge badge-<?= $r['keterangan'] == 'Tuntas' ? 'success' : 'danger' ?>">
                                                    <?= $r['keterangan'] ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                        </div> <!-- End .nilai-desktop-view -->

                        <!-- ==========================================
                             MOBILE APP VIEW (NO HORIZONTAL SCROLL)
                             ========================================== -->
                        <div class="nilai-mobile-app">
                            <div class="nilai-hero-card">
                                <div class="nilai-hero-top">
                                    <span class="nilai-hero-badge">
                                        <i class="fas fa-book-open"></i> TA <?= htmlspecialchars($tahun_ajaran) ?> &bull; Sem <?= htmlspecialchars($semester_aktif) ?>
                                    </span>
                                    <span class="nilai-kktp-badge">
                                        <i class="fas fa-layer-group mr-1"></i><?= htmlspecialchars($selected_exam_type) ?>
                                    </span>
                                </div>
                                <div class="nilai-hero-title"><?= htmlspecialchars($selected_mapel['nama_mapel'] ?? 'Mata Pelajaran') ?></div>
                                <div class="nilai-hero-sub">
                                    <span><i class="fas fa-chalkboard mr-1"></i><?= htmlspecialchars($selected_class['nama_kelas'] ?? 'Kelas') ?></span>
                                    <span>&bull;</span>
                                    <span><?= count($remedial_list) ?> Siswa Remidi</span>
                                </div>

                                <div class="nilai-actions-bar">
                                    <a href="../config/export_program_remidi_excel.php?kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>&jenis=<?= urlencode($selected_exam_type) ?>&session_type=admin" target="_blank" class="nilai-action-chip">
                                        <i class="fas fa-file-excel text-success"></i> Excel
                                    </a>
                                    <a href="../config/export_program_remidi_pdf.php?kelas=<?= $selected_class_id ?>&mapel=<?= $selected_mapel_id ?>&jenis=<?= urlencode($selected_exam_type) ?>&session_type=admin" target="_blank" class="nilai-action-chip">
                                        <i class="fas fa-file-pdf text-danger"></i> PDF
                                    </a>
                                </div>
                            </div>

                            <?php if (empty($remedial_list)): ?>
                                <div class="card p-4 text-center" style="border-radius: 18px;">
                                    <i class="fas fa-user-check fa-3x text-muted mb-3"></i>
                                    <h6 class="font-weight-bold">Belum Ada Data Remidi</h6>
                                    <p class="text-muted small">Semua siswa tuntas atau data remidi belum ditambahkan untuk ulangan ini.</p>
                                </div>
                            <?php else: ?>
                                <div class="nilai-student-list">
                                    <?php 
                                    $mNo = 1;
                                    $countTuntas = 0;
                                    $sumRemidi = 0;
                                    $countRemidiVal = 0;
                                    foreach ($remedial_list as $r): 
                                        $isTuntas = ($r['keterangan'] === 'Tuntas');
                                        if ($isTuntas) $countTuntas++;
                                        if ((float)$r['nilai_tes_remidi'] > 0) {
                                            $sumRemidi += (float)$r['nilai_tes_remidi'];
                                            $countRemidiVal++;
                                        }
                                    ?>
                                        <div class="nilai-student-card flex-column align-items-stretch">
                                            <div class="d-flex align-items-start justify-content-between mb-2">
                                                <div class="nilai-student-left">
                                                    <div class="nilai-student-num"><?= $mNo++ ?></div>
                                                    <div class="nilai-student-info">
                                                        <div class="nilai-student-name"><?= htmlspecialchars($r['nama_siswa']) ?></div>
                                                        <div class="nilai-student-sub">
                                                            <span><i class="fas fa-user-tie mr-1"></i><?= htmlspecialchars($r['nama_guru'] ?? '-') ?></span>
                                                            <span>&bull;</span>
                                                            <span>KKM: <strong><?= (float)$r['kkm'] ?></strong></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="d-flex align-items-center justify-content-between pt-2 border-top">
                                                <div class="small text-muted font-weight-bold" style="font-size: 11px;">
                                                    <?= htmlspecialchars($r['bentuk_remidial']) ?> &bull; <?= date('d/m/Y', strtotime($r['tanggal'])) ?>
                                                </div>
                                                <div class="d-flex align-items-center" style="gap: 6px;">
                                                    <div class="program-score-pill">
                                                        <span class="score-lbl">Asli</span>
                                                        <span class="score-val text-danger"><?= (float)$r['nilai_ulangan'] ?></span>
                                                    </div>
                                                    <div class="program-score-pill">
                                                        <span class="score-lbl">Remidi</span>
                                                        <span class="score-val text-success"><?= (float)$r['nilai_tes_remidi'] ?></span>
                                                    </div>
                                                    <span class="badge badge-<?= $isTuntas ? 'success' : 'danger' ?>" style="border-radius: 8px; font-weight: 800; font-size: 11px; padding: 6px 8px;">
                                                        <?= $r['keterangan'] ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="nilai-summary-card">
                                    <div class="nilai-summary-header">
                                        <span class="nilai-summary-title">
                                            <i class="fas fa-chart-pie text-primary mr-1"></i>
                                            Rekap Hasil Remidial
                                        </span>
                                    </div>
                                    <div class="nilai-summary-grid">
                                        <div class="nilai-summary-box box-max">
                                            <div class="box-label">Siswa Tuntas</div>
                                            <div class="box-value text-success"><?= $countTuntas ?></div>
                                            <div class="box-sub">Dari <?= count($remedial_list) ?> Siswa</div>
                                        </div>
                                        <div class="nilai-summary-box box-min">
                                            <div class="box-label">Belum Tuntas</div>
                                            <div class="box-value text-danger"><?= count($remedial_list) - $countTuntas ?></div>
                                            <div class="box-sub">Perlu Tindak Lanjut</div>
                                        </div>
                                        <div class="nilai-summary-box box-avg">
                                            <div class="box-label">Rata-rata Tes</div>
                                            <div class="box-value text-primary"><?= $countRemidiVal > 0 ? round($sumRemidi / $countRemidiVal, 1) : '-' ?></div>
                                            <div class="box-sub">Nilai Akhir Remidi</div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">Pilih filter di atas untuk melihat data remedial.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<?php require_once '../templates/footer.php'; ?>
