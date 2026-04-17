<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

$school_profile = getSchoolProfile($pdo);
$page_title = 'Cetak Surat Keterangan';

// DataTables
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

// Fetch tingkat list (exclude Pra Mula)
$tingkat_list = [];
try {
    $tingkat_list = $pdo->query("
            SELECT id_tingkat_barung, nama_tingkat
            FROM tb_tingkat_barung
            WHERE LOWER(REPLACE(nama_tingkat, ' ', '')) NOT IN ('pramula', 'pra-mula') 
              AND LOWER(nama_tingkat) != 'pra mula'
            ORDER BY
                CASE
                    WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('mula') THEN 1
                    WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('bantu') THEN 2
                    WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('tata') THEN 3
                    ELSE 99
                END,
                nama_tingkat ASC
        ")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // ignore
}

$selected_tingkat_id = (int)($_GET['tingkat'] ?? 0);
if ($selected_tingkat_id <= 0 && !empty($tingkat_list)) {
    $selected_tingkat_id = (int)($tingkat_list[0]['id_tingkat_barung'] ?? 0);
}

// Resolve selected tingkat name
$selected_tingkat_name = '';
foreach ($tingkat_list as $t) {
    if ((int)($t['id_tingkat_barung'] ?? 0) === $selected_tingkat_id) {
        $selected_tingkat_name = (string)($t['nama_tingkat'] ?? '');
        break;
    }
}

// Fetch peserta didik for selected tingkat
$participants = [];
if ($selected_tingkat_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT id_peserta_didik_barung, nama_peserta_didik, nta, tempat_lahir, tanggal_lahir
            FROM tb_peserta_didik_barung
            WHERE id_tingkat_barung = ?
            ORDER BY nama_peserta_didik ASC
        ");
        $stmt->execute([$selected_tingkat_id]);
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // ignore
    }
}


// Fetch print settings (needed for POST handler)
$print_settings_data = [
    'ketua_gudep' => '',
    'tempat_pelantikan' => '',
    'tempat_surat' => '',
    'tanggal_surat' => date('d F Y'),
    'bingkai_surat' => ''
];

try {
    $settings_tmp = $pdo->query("SELECT * FROM tb_pengaturan_cetak_barung LIMIT 1")->fetch();
    if ($settings_tmp) {
        $print_settings_data = [
            'ketua_gudep' => $settings_tmp['ketua_gudep'] ?? '',
            'tempat_pelantikan' => $settings_tmp['tempat_pelantikan'] ?? '',
            'tempat_surat' => $settings_tmp['tempat_surat'] ?? '',
            'tanggal_surat' => $settings_tmp['tanggal_surat'] ?? date('d F Y'),
            'bingkai_surat' => $settings_tmp['bingkai_surat'] ?? ''
        ];
    }
} catch (Exception $e) {
    // Table doesn't exist yet
}

// Handle save print settings
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_print_settings'])) {
    $ketua_gudep = $_POST['ketua_gudep'] ?? '';
    $tempat_pelantikan = $_POST['tempat_pelantikan'] ?? '';
    $tempat_surat = $_POST['tempat_surat'] ?? '';
    $tanggal_surat = $_POST['tanggal_surat'] ?? '';
    $bingkai_surat = $print_settings_data['bingkai_surat']; // Keep existing by default
    $template_surat = $print_settings_data['template_surat'] ?? '';
    
    // Handle file upload for bingkai
    if (isset($_FILES['bingkai_surat_file']) && $_FILES['bingkai_surat_file']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['bingkai_surat_file']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $max_size = 5 * 1024 * 1024; // 5MB
            if ($_FILES['bingkai_surat_file']['size'] <= $max_size) {
                $new_filename = 'bingkai_' . time() . '.' . $ext;
                $upload_dir = __DIR__ . '/../uploads/';
                
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                if (move_uploaded_file($_FILES['bingkai_surat_file']['tmp_name'], $upload_dir . $new_filename)) {
                    $bingkai_surat = $new_filename;
                }
            }
        }
    }
    
    // Handle file upload for template
    if (isset($_FILES['template_surat_file']) && $_FILES['template_surat_file']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['template_surat_file']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $max_size = 5 * 1024 * 1024; // 5MB
            if ($_FILES['template_surat_file']['size'] <= $max_size) {
                $new_filename = 'template_' . time() . '.' . $ext;
                $upload_dir = __DIR__ . '/../uploads/';
                
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                if (move_uploaded_file($_FILES['template_surat_file']['tmp_name'], $upload_dir . $new_filename)) {
                    $template_surat = $new_filename;
                }
            }
        }
    }
    
    try {
        // Check if settings exist
        $check = $pdo->query("SELECT id FROM tb_pengaturan_cetak_barung LIMIT 1")->fetch();
        
        if ($check) {
            // Update
            $stmt = $pdo->prepare("
                UPDATE tb_pengaturan_cetak_barung 
                SET ketua_gudep = ?, tempat_pelantikan = ?, tempat_surat = ?, tanggal_surat = ?, bingkai_surat = ?, template_surat = ?
            ");
            $stmt->execute([$ketua_gudep, $tempat_pelantikan, $tempat_surat, $tanggal_surat, $bingkai_surat, $template_surat]);
        } else {
            // Insert
            $stmt = $pdo->prepare("
                INSERT INTO tb_pengaturan_cetak_barung (ketua_gudep, tempat_pelantikan, tempat_surat, tanggal_surat, bingkai_surat, template_surat)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$ketua_gudep, $tempat_pelantikan, $tempat_surat, $tanggal_surat, $bingkai_surat, $template_surat]);
        }
        
        $message = ['type' => 'success', 'text' => 'Pengaturan cetak berhasil disimpan!'];
    } catch (Exception $e) {
        $message = ['type' => 'error', 'text' => 'Gagal menyimpan pengaturan: ' . $e->getMessage()];
    }
}

// Reload settings after save
try {
    $settings = $pdo->query("SELECT * FROM tb_pengaturan_cetak_barung LIMIT 1")->fetch();
    if ($settings) {
        $print_settings_data = [
            'ketua_gudep' => $settings['ketua_gudep'] ?? '',
            'tempat_pelantikan' => $settings['tempat_pelantikan'] ?? '',
            'tempat_surat' => $settings['tempat_surat'] ?? '',
            'tanggal_surat' => $settings['tanggal_surat'] ?? date('d F Y'),
            'bingkai_surat' => $settings['bingkai_surat'] ?? '',
            'template_surat' => $settings['template_surat'] ?? ''
        ];
    }
} catch (Exception $e) {
    // ignore
}

$custom_script = '';

// Settings for print
$print_settings = [
    'school_name' => $school_profile['nama_madrasah'] ?? 'MADRASAH',
    'school_logo' => !empty($school_profile['logo']) ? '../assets/img/' . $school_profile['logo'] : '',
    'academic_year' => $school_profile['tahun_ajaran'] ?? '-',
    'head_name' => $school_profile['nama_kepala'] ?? '-',
    'head_nip' => $school_profile['nip_kepala'] ?? '-',
    'print_place' => $school_profile['tempat_jadwal'] ?? 'Padang',
];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Cetak Surat Keterangan</h1>
        </div>

        <div class="section-body">
            <!-- Hidden inputs for print settings -->
            <input type="hidden" id="schoolName" value="<?= htmlspecialchars($print_settings['school_name']) ?>">
            <input type="hidden" id="schoolLogo" value="<?= htmlspecialchars($print_settings['school_logo']) ?>">
            <input type="hidden" id="academicYear" value="<?= htmlspecialchars($print_settings['academic_year']) ?>">
            <input type="hidden" id="headName" value="<?= htmlspecialchars($print_settings['head_name']) ?>">
            <input type="hidden" id="headNip" value="<?= htmlspecialchars($print_settings['head_nip']) ?>">
            <input type="hidden" id="printPlace" value="<?= htmlspecialchars($print_settings['print_place']) ?>">
            <input type="hidden" id="tingkatName" value="<?= htmlspecialchars($selected_tingkat_name) ?>">
            <input type="hidden" id="printDate" value="<?= date('d F Y') ?>">

            <!-- Data Cetak Box -->
            <div class="card">
                <div class="card-header">
                    <h4>Data Cetak</h4>
                    <div class="card-header-action">
                        <button class="btn btn-icon icon-left btn-primary" data-toggle="modal" data-target="#editDataCetakModal">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Ketua Gudep:</label>
                                <p class="font-weight-bold"><?= htmlspecialchars($print_settings_data['ketua_gudep'] ?: '-') ?></p>
                            </div>
                            <div class="form-group">
                                <label>Tempat Pelantikan:</label>
                                <p class="font-weight-bold"><?= htmlspecialchars($print_settings_data['tempat_pelantikan'] ?: '-') ?></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tempat Surat:</label>
                                <p class="font-weight-bold"><?= htmlspecialchars($print_settings_data['tempat_surat'] ?: '-') ?></p>
                            </div>
                            <div class="form-group">
                                <label>Tanggal Surat:</label>
                                <p class="font-weight-bold"><?= htmlspecialchars($print_settings_data['tanggal_surat'] ?: date('d F Y')) ?></p>
                            </div>
                        </div>
                    </div>
                                            
                        <?php if (!empty($print_settings_data['bingkai_surat'])): ?>
                    <div class="form-group">
                        <label>Bingkai Surat:</label>
                        <div class="mt-2">
                            <img src="../uploads/<?= htmlspecialchars($print_settings_data['bingkai_surat']) ?>" alt="Bingkai Surat" class="img-thumbnail" style="max-height: 200px;">
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4>Surat Keterangan Kenaikan Tingkat</h4>
                    <div class="card-header-action">
                        <button class="btn btn-info" onclick="printAllLetters()" id="btnPrintAll" style="display:none;">
                            <i class="fas fa-print"></i> Cetak Semua Surat
                        </button>
                        <button class="btn btn-primary" onclick="exportPDF()">
                            <i class="fas fa-print"></i> Cetak PDF Data
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>Pilih Tingkat:</label>
                        <select class="form-control select2" onchange="window.location.href='?tingkat=' + this.value">
                            <?php foreach ($tingkat_list as $tingkat): ?>
                                <option value="<?= $tingkat['id_tingkat_barung'] ?>" <?= $selected_tingkat_id == $tingkat['id_tingkat_barung'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($tingkat['nama_tingkat']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if (!empty($participants)): ?>
                        <div class="table-responsive">
                            <table class="table table-striped" id="table-1">
                                <thead>
                                    <tr>
                                        <th>No</th>
                                        <th>Nama Peserta Didik</th>
                                        <th>NTA</th>
                                        <th>Tempat Lahir</th>
                                        <th>Tanggal Lahir</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $no = 1; foreach ($participants as $participant): ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><?= htmlspecialchars($participant['nama_peserta_didik']) ?></td>
                                        <td><?= htmlspecialchars($participant['nta']) ?></td>
                                        <td><?= htmlspecialchars($participant['tempat_lahir'] ?? '-') ?></td>
                                        <td><?= !empty($participant['tanggal_lahir']) ? date('d-m-Y', strtotime($participant['tanggal_lahir'])) : '-' ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-success" onclick="printSingleLetter(<?php echo json_encode($participant['id_peserta_didik']) ?>, <?php echo json_encode($participant['nama_peserta_didik']) ?>, <?php echo json_encode($participant['nta']) ?>)">
                                                <i class="fas fa-print"></i> Cetak Surat
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Tidak ada data peserta didik untuk tingkat ini.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>

    <!-- Modal Edit Data Cetak -->
    <div class="modal fade" id="editDataCetakModal" tabindex="-1" role="dialog" aria-labelledby="editDataCetakModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editDataCetakModalLabel">Edit Data Cetak</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="save_print_settings" value="1">
                        
                        <div class="form-group">
                            <label>Ketua Gudep:</label>
                            <input type="text" name="ketua_gudep" class="form-control" value="<?= htmlspecialchars($print_settings_data['ketua_gudep']) ?>" placeholder="Nama Ketua Gudep">
                        </div>
                        
                        <div class="form-group">
                            <label>Tempat Pelantikan:</label>
                            <input type="text" name="tempat_pelantikan" class="form-control" value="<?= htmlspecialchars($print_settings_data['tempat_pelantikan']) ?>" placeholder="Tempat Pelantikan">
                        </div>
                        
                        <div class="form-group">
                            <label>Tempat Surat:</label>
                            <input type="text" name="tempat_surat" class="form-control" value="<?= htmlspecialchars($print_settings_data['tempat_surat']) ?>" placeholder="Kota/Tempat Surat">
                        </div>
                        
                        <div class="form-group">
                            <label>Tanggal Surat:</label>
                            <input type="date" name="tanggal_surat" class="form-control" value="<?= !empty($print_settings_data['tanggal_surat']) ? date('Y-m-d', strtotime($print_settings_data['tanggal_surat'])) : date('Y-m-d') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Bingkai Surat (gambar):</label>
                            <div class="custom-file">
                                <input type="file" name="bingkai_surat_file" class="custom-file-input" id="bingkaiSuratFile" accept="image/*">
                                <label class="custom-file-label" for="bingkaiSuratFile">Pilih gambar bingkai...</label>
                            </div>
                            <small class="text-muted">Format: JPG, PNG, maksimal 2MB</small>
                                                    
                        <?php if (!empty($print_settings_data['bingkai_surat'])): ?>
                            <div class="mt-2">
                                <img src="../uploads/<?= htmlspecialchars($print_settings_data['bingkai_surat']) ?>" alt="Bingkai Saat Ini" class="img-thumbnail" style="max-height: 150px;">
                                <p class="text-muted mb-0 mt-1"><small>Bingkai saat ini</small></p>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label>Template Surat Keterangan (gambar):</label>
                            <div class="custom-file">
                                <input type="file" name="template_surat_file" class="custom-file-input" id="templateSuratFile" accept="image/*">
                                <label class="custom-file-label" for="templateSuratFile">Pilih template surat...</label>
                            </div>
                            <small class="text-muted">Format: JPG, PNG, maksimal 5MB</small>
                            <?php if (!empty($print_settings_data['template_surat'])): ?>
                            <div class="mt-2">
                                <img src="../uploads/<?php echo htmlspecialchars($print_settings_data['template_surat']); ?>" alt="Template" class="img-thumbnail" style="max-height: 200px;">
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Simpan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Hidden field for template path -->
    <input type="hidden" id="templateSurat" value="<?= !empty($print_settings_data['template_surat']) ? '../uploads/' . htmlspecialchars($print_settings_data['template_surat']) : '' ?>">

    <script>
    // Print single letter
    function printSingleLetter(id, nama, nta) {
    const templatePath = $('#templateSurat').val();
    const tempatSurat = <?php echo json_encode(!empty($print_settings_data['tempat_surat']) ? $print_settings_data['tempat_surat'] : '................') ?>;
    const tanggalSurat = <?php echo json_encode(!empty($print_settings_data['tanggal_surat']) ? $print_settings_data['tanggal_surat'] : date('d F Y')) ?>;
    const ketuaGudep = <?php echo json_encode(!empty($print_settings_data['ketua_gudep']) ? $print_settings_data['ketua_gudep'] : '........................') ?>;
    
    if (!templatePath) {
        swal('Peringatan', 'Silakan upload template surat terlebih dahulu melalui Edit Data Cetak', 'warning');
        return;
    }
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Surat Keterangan - ${nama}</title>
            <style>
                @media print {
                    @page { size: A4; margin: 0; }
                    body { margin: 0; }
                }
                body { 
                    font-family: Arial, sans-serif; 
                    margin: 0; 
                    padding: 0;
                    position: relative;
                }
                .template-bg {
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    z-index: 0;
                }
                .template-bg img {
                    width: 100%;
                    height: 100%;
                    object-fit: contain;
                }
                .content {
                    position: relative;
                    z-index: 1;
                    padding: 60px 80px;
                }
                .header {
                    text-align: center;
                    margin-bottom: 30px;
                }
                .header h2 {
                    margin: 5px 0;
                    font-size: 18px;
                }
                .title {
                    text-align: center;
                    margin: 30px 0;
                    text-decoration: underline;
                    font-size: 16px;
                    font-weight: bold;
                }
                .body-text {
                    text-align: justify;
                    line-height: 1.8;
                    margin: 20px 0;
                }
                .student-info {
                    margin: 20px 0;
                    padding-left: 40px;
                }
                .student-info td {
                    padding: 5px;
                }
                .signature-section {
                    margin-top: 50px;
                    text-align: right;
                }
                .signature-space {
                    height: 80px;
                }
            </style>
        </head>
        <body>
            <div class="template-bg">
                <img src="${templatePath}" alt="Template">
            </div>
            <div class="content">
                <div class="header">
                    <h2>SURAT KETERANGAN KENAIKAN TINGKAT</h2>
                </div>
                
                <div class="title">
                    SURAT KETERANGAN
                </div>
                
                <div class="body-text">
                    Yang bertanda tangan di bawah ini menerangkan bahwa:
                </div>
                
                <table class="student-info">
                    <tr><td width="150">Nama</td><td>: <strong>${nama}</strong></td></tr>
                    <tr><td>NTA</td><td>: ${nta}</td></tr>
                </table>
                
                <div class="body-text">
                    Telah dinyatakan <strong>NAIK TINGKAT</strong> dalam Gerakan Pramuka.
                    Demikian surat keterangan ini dibuat untuk dapat dipergunakan sebagaimana mestinya.
                </div>
                
                <div class="signature-section">
                    <p>${tempatSurat}, ${tanggalSurat}</p>
                    <p>Ketua Gudep,</p>
                    <div class="signature-space"></div>
                    <p><strong>${ketuaGudep}</strong></p>
                </div>
            </div>
        </body>
        </html>
    `);
    printWindow.document.close();
    
    // Add print script after document is closed
    printWindow.onload = function() {
        printWindow.print();
    };
}

// Print all letters
function printAllLetters() {
    const templatePath = $('#templateSurat').val();
    const tempatSurat = <?php echo json_encode(!empty($print_settings_data['tempat_surat']) ? $print_settings_data['tempat_surat'] : '................') ?>;
    const tanggalSurat = <?php echo json_encode(!empty($print_settings_data['tanggal_surat']) ? $print_settings_data['tanggal_surat'] : date('d F Y')) ?>;
    const ketuaGudep = <?php echo json_encode(!empty($print_settings_data['ketua_gudep']) ? $print_settings_data['ketua_gudep'] : '........................') ?>;
    
    if (!templatePath) {
        swal('Peringatan', 'Silakan upload template surat terlebih dahulu melalui Edit Data Cetak', 'warning');
        return;
    }
    
    const participants = [];
    <?php foreach ($participants as $p): ?>
    participants.push({
        id: <?php echo json_encode($p['id_peserta_didik']) ?>,
        nama: <?php echo json_encode($p['nama_peserta_didik']) ?>,
        nta: <?php echo json_encode($p['nta']) ?>
    });
    <?php endforeach; ?>
    
    if (participants.length === 0) {
        swal('Peringatan', 'Tidak ada data peserta didik', 'warning');
        return;
    }
    
    let allContent = '';
    
    participants.forEach((p, index) => {
        allContent += `
            <div style="page-break-after: always; position: relative; width: 100%; height: 100vh;">
                <div style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;">
                    <img src="${templatePath}" style="width: 100%; height: 100%; object-fit: contain;">
                </div>
                <div style="position: relative; z-index: 1; padding: 60px 80px;">
                    <div style="text-align: center; margin-bottom: 30px;">
                        <h2 style="margin: 5px 0; font-size: 18px;">SURAT KETERANGAN KENAIKAN TINGKAT</h2>
                    </div>
                    
                    <div style="text-align: center; margin: 30px 0; text-decoration: underline; font-size: 16px; font-weight: bold;">
                        SURAT KETERANGAN
                    </div>
                    
                    <div style="text-align: justify; line-height: 1.8; margin: 20px 0;">
                        Yang bertanda tangan di bawah ini menerangkan bahwa:
                    </div>
                    
                    <table style="margin: 20px 0; padding-left: 40px;">
                        <tr><td width="150">Nama</td><td>: <strong>${p.nama}</strong></td></tr>
                        <tr><td>NTA</td><td>: ${p.nta}</td></tr>
                    </table>
                    
                    <div style="text-align: justify; line-height: 1.8; margin: 20px 0;">
                        Telah dinyatakan <strong>NAIK TINGKAT</strong> dalam Gerakan Pramuka.
                        Demikian surat keterangan ini dibuat untuk dapat dipergunakan sebagaimana mestinya.
                    </div>
                    
                    <div style="margin-top: 50px; text-align: right;">
                        <p>${tempatSurat}, ${tanggalSurat}</p>
                        <p>Ketua Gudep,</p>
                        <div style="height: 80px;"></div>
                        <p><strong>${ketuaGudep}</strong></p>
                    </div>
                </div>
            </div>
        `;
    });
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Semua Surat Keterangan</title>
            <style>
                @media print {
                    @page { size: A4; margin: 0; }
                    body { margin: 0; }
                }
                body { 
                    font-family: Arial, sans-serif; 
                    margin: 0; 
                    padding: 0;
                }
            </style>
        </head>
        <body>
            ${allContent}
        </body>
        </html>
    `);
    printWindow.document.close();
    
    // Add print script after document is closed
    printWindow.onload = function() {
        printWindow.print();
    };
}
</script>
