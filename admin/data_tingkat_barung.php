<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAuthorized(['admin', 'tata_usaha'])) {
    redirect('../login.php');
}

$page_title = 'Data Tingkat Pramuka';

function tingkat_barung_resolve_slug(?string $nama_tingkat): ?string
{
    $raw = trim((string)$nama_tingkat);
    if ($raw === '') {
        return null;
    }

    $compact = strtolower(preg_replace('/[^a-z]/u', '', $raw));
    switch ($compact) {
        case 'pramula':
            return 'pra_mula';
        case 'mula':
            return 'mula';
        case 'bantu':
            return 'bantu';
        case 'tata':
            return 'tata';
        case 'praramu':
            return 'pra_ramu';
        case 'ramu':
            return 'ramu';
        default:
            return null;
    }
}

function tingkat_barung_golongan_otomatis(?string $nama_tingkat, ?string $fallback = null): string
{
    $slug = tingkat_barung_resolve_slug($nama_tingkat);
    if (in_array($slug, ['pra_mula', 'mula', 'bantu', 'tata'], true)) {
        return 'Siaga';
    }
    if (in_array($slug, ['pra_ramu', 'ramu'], true)) {
        return 'Penggalang';
    }

    return trim((string)$fallback) !== '' ? (string)$fallback : 'Siaga';
}

// DataTables
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

// --- Ensure schema (best-effort) ---
$table_name = 'tb_tingkat_barung';
$schema_error = null;
try {
    $stmt = $pdo->query("SHOW TABLES LIKE '{$table_name}'");
    $table_exists = (bool)$stmt->fetch(PDO::FETCH_NUM);

    if (!$table_exists) {
        $pdo->exec("
            CREATE TABLE {$table_name} (
                id_tingkat_barung INT AUTO_INCREMENT PRIMARY KEY,
                nama_tingkat VARCHAR(100) NOT NULL,
                golongan VARCHAR(50) NOT NULL DEFAULT 'Siaga'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } else {
        $required_cols = [
            'id_tingkat_barung' => "INT NOT NULL",
            'nama_tingkat' => "VARCHAR(100) NOT NULL",
            'golongan' => "VARCHAR(50) NOT NULL DEFAULT 'Siaga'",
        ];
        foreach ($required_cols as $col => $typeDef) {
            $colStmt = $pdo->query("SHOW COLUMNS FROM {$table_name} LIKE '" . addslashes($col) . "'");
            $has_col = (bool)$colStmt->fetch(PDO::FETCH_ASSOC);
            if (!$has_col) {
                $pdo->exec("ALTER TABLE {$table_name} ADD COLUMN {$col} {$typeDef}");
            }
        }
    }
    $pdo->exec("
        UPDATE {$table_name}
        SET golongan = CASE
            WHEN LOWER(REPLACE(REPLACE(nama_tingkat, ' ', ''), '-', '')) IN ('pramula', 'mula', 'bantu', 'tata') THEN 'Siaga'
            WHEN LOWER(REPLACE(REPLACE(nama_tingkat, ' ', ''), '-', '')) IN ('praramu', 'ramu') THEN 'Penggalang'
            ELSE golongan
        END
    ");
} catch (Exception $e) {
    $schema_error = $e->getMessage();
}

// --- CRUD handling ---
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add
    if (isset($_POST['add_tingkat_barung'])) {
        $nama_tingkat = sanitizeInput($_POST['nama_tingkat'] ?? '');
        $golongan = sanitizeInput($_POST['golongan'] ?? 'Siaga');
        $golongan = tingkat_barung_golongan_otomatis($nama_tingkat, $golongan);
        if ($nama_tingkat === '') {
            $message = ['type' => 'warning', 'text' => 'Harap isi nama tingkat.'];
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO {$table_name} (nama_tingkat, golongan) VALUES (?, ?)");
                $ok = $stmt->execute([$nama_tingkat, $golongan]);
                if ($ok) {
                    $username = $_SESSION['username'] ?? 'system';
                    logActivity($pdo, $username, 'Tambah Tingkat Pramuka', $nama_tingkat);
                    $message = ['type' => 'success', 'text' => 'Data tingkat Pramuka berhasil ditambahkan!'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal menambahkan data tingkat Pramuka.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Error DB: ' . $e->getMessage()];
            }
        }
    }

    // Update
    if (isset($_POST['update_tingkat_barung'])) {
        $id = (int)($_POST['id_tingkat_barung'] ?? 0);
        $nama_tingkat = sanitizeInput($_POST['nama_tingkat'] ?? '');
        $golongan = sanitizeInput($_POST['golongan'] ?? 'Siaga');
        $golongan = tingkat_barung_golongan_otomatis($nama_tingkat, $golongan);
        if ($id <= 0 || $nama_tingkat === '') {
            $message = ['type' => 'warning', 'text' => 'Harap isi nama tingkat.'];
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE {$table_name} SET nama_tingkat = ?, golongan = ? WHERE id_tingkat_barung = ?");
                $ok = $stmt->execute([$nama_tingkat, $golongan, $id]);
                if ($ok) {
                    $username = $_SESSION['username'] ?? 'system';
                    logActivity($pdo, $username, 'Update Tingkat Pramuka', "ID {$id}: {$nama_tingkat}");
                    $message = ['type' => 'success', 'text' => 'Data tingkat Pramuka berhasil diperbarui!'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal memperbarui data.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Error DB: ' . $e->getMessage()];
            }
        }
    }

    // Delete
    if (isset($_POST['delete_tingkat_barung'])) {
        $id = (int)($_POST['id_tingkat_barung'] ?? 0);
        if ($id <= 0) {
            $message = ['type' => 'warning', 'text' => 'ID tidak valid.'];
        } else {
            try {
                $nameStmt = $pdo->prepare("SELECT nama_tingkat FROM {$table_name} WHERE id_tingkat_barung = ?");
                $nameStmt->execute([$id]);
                $nama = (string)($nameStmt->fetchColumn() ?: '-');

                $stmt = $pdo->prepare("DELETE FROM {$table_name} WHERE id_tingkat_barung = ?");
                $ok = $stmt->execute([$id]);
                if ($ok) {
                    $username = $_SESSION['username'] ?? 'system';
                    logActivity($pdo, $username, 'Hapus Tingkat Pramuka', "ID {$id}: {$nama}");
                    $message = ['type' => 'success', 'text' => 'Data tingkat Pramuka berhasil dihapus!'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal menghapus data.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Error DB: ' . $e->getMessage()];
            }
        }
    }
}

// Fetch list
$rows = [];
$fetch_error = null;
$school_profile = getSchoolProfile($pdo);
$print_settings_data = [
    'ketua_gudep' => '',
    'nta_ketua_gudep' => '',
    'nomor_gudep' => '',
    'gugus_depan' => '03.016',
    'tempat_surat' => '',
    'logo_pramuka' => '',
    'logo_wosm' => '',
];
try {
    $settings = $pdo->query("SELECT * FROM tb_pengaturan_cetak_barung LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($settings) {
        $print_settings_data = [
            'ketua_gudep' => $settings['ketua_gudep'] ?? '',
            'nta_ketua_gudep' => $settings['nta_ketua_gudep'] ?? '',
            'nomor_gudep' => $settings['nomor_gudep'] ?? '',
            'gugus_depan' => $settings['gugus_depan'] ?? '03.016',
            'tempat_surat' => $settings['tempat_surat'] ?? '',
            'logo_pramuka' => $settings['logo_pramuka'] ?? '',
            'logo_wosm' => $settings['logo_wosm'] ?? '',
        ];
    }
} catch (Exception $e) { /* ignore */ }

try {
    $stmt = $pdo->query("
        SELECT id_tingkat_barung, nama_tingkat, golongan
        FROM {$table_name}
        ORDER BY
            CASE
                WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('pramula', 'pra-mula') OR LOWER(nama_tingkat) = 'pra mula' THEN 0
                WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('mula') THEN 1
                WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('bantu') THEN 2
                WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('tata') THEN 3
                WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('garuda') THEN 4
                ELSE 99
            END,
            nama_tingkat ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $fetch_error = $e->getMessage();
}

// Page-specific JS
$js_page = [];
if ($message) {
    $swal_icon = $message['type'] === 'success' ? 'success' : ($message['type'] === 'warning' ? 'warning' : 'error');
    $swal_title = $message['type'] === 'success' ? 'Berhasil!' : 'Perhatian!';
    $swal_text = json_encode($message['text']);
    $js_page[] = "
        Swal.fire({
            icon: '{$swal_icon}',
            title: '{$swal_title}',
            text: {$swal_text},
            timer: " . ($message['type'] === 'success' ? 1800 : 2500) . ",
            showConfirmButton: false
        });
    ";
}

$js_page[] = "
$(document).ready(function() {
    var table = $('#table-1').DataTable({
        'order': [[1, 'asc']],
        'columnDefs': [
            { 'sortable': false, 'targets': [3] }
        ],
        'language': {
            'lengthMenu': 'Tampilkan _MENU_ entri',
            'zeroRecords': 'Tidak ada data yang ditemukan',
            'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
            'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
            'search': 'Cari:',
            'paginate': {
                'first': 'Pertama',
                'last': 'Terakhir',
                'next': 'Selanjutnya',
                'previous': 'Sebelumnya'
            }
        }
    });

    table.on('order.dt search.dt draw.dt', function() {
        var info = table.page.info();
        var start = info.page * info.length;
        table.column(0, { search: 'applied', order: 'applied' }).nodes().each(function(cell, i) {
            $(cell).text(start + i + 1);
        });
    }).draw();

    $(document).on('click', '.edit-btn', function() {
        $('#edit_id_tingkat_barung').val($(this).data('id'));
        $('#edit_nama_tingkat').val($(this).data('nama') || '');
        $('#edit_golongan').val($(this).data('golongan') || 'Siaga');
        $('#editModal').modal('show');
    });

    $(document).on('click', '.delete-btn', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var nama = $(this).data('nama') || '-';
        Swal.fire({
            title: 'Konfirmasi Hapus',
            text: 'Apakah Anda yakin ingin menghapus \"' + nama + '\"?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                var form = $('<form method=\"POST\" action=\"\">' +
                    '<input type=\"hidden\" name=\"id_tingkat_barung\" value=\"' + id + '\">' +
                    '<input type=\"hidden\" name=\"delete_tingkat_barung\" value=\"1\">' +
                    '</form>');
                $('body').append(form);
                form.submit();
            }
        });
    });
function exportToPDF() {
    var table = document.getElementById('table-1');
    if (!table) return;

    var schoolName = $('#schoolName').val() || 'MADRASAH';
    var schoolAddress = $('#schoolAddress').val() || '';
    var schoolEmail = $('#schoolEmail').val() || '';
    var schoolWebsite = $('#schoolWebsite').val() || '';
    var nomorGudep = $('#nomorGudep').val() || '03.016';
    var logoPramuka = $('#logoPramuka').val() || '';
    var logoWosm = $('#logoWosm').val() || '';
    var academicYear = $('#academicYear').val() || '-';
    var headName = $('#headName').val() || '-';
    var headNip = $('#headNip').val() || '-';
    var printPlace = $('#printPlace').val() || 'Padang';
    var printDate = $('#printDate').val() || '';

    var qrContent = 'Dokumen Sah: ' + schoolName + ' | Ketua Gudep: ' + headName + ' | NTA: ' + headNip;
    var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' + encodeURIComponent(qrContent);

    var printWindow = window.open('', '_blank');
    printWindow.document.write('<html><head><title>Data Tingkat Pramuka ' + academicYear + '</title>');
    printWindow.document.write('<style>');
    printWindow.document.write('@page { size: 210mm 330mm portrait; margin: 10mm; }');
    printWindow.document.write('body { font-family: Arial, sans-serif; font-size: 11pt; margin: 0; }');
    printWindow.document.write('table.kop-header-pramuka { width: 100%; border-collapse: collapse; border-bottom: 2px solid #000; margin-bottom: 12px; padding-bottom: 6px; }');
    printWindow.document.write('table.kop-header-pramuka td { border: none !important; padding: 0 !important; vertical-align: middle; }');
    printWindow.document.write('td.kop-logo-left { width: 80px; text-align: left; }');
    printWindow.document.write('td.kop-logo-left img { height: 65px; width: auto; max-width: 80px; object-fit: contain; }');
    printWindow.document.write('td.kop-logo-right { width: 80px; text-align: right; }');
    printWindow.document.write('td.kop-logo-right img { height: 65px; width: auto; max-width: 80px; object-fit: contain; }');
    printWindow.document.write('td.kop-text { text-align: center; }');
    printWindow.document.write('td.kop-text h2 { margin: 0; font-size: 12pt; font-weight: bold; color: #000; letter-spacing: 0.3px; }');
    printWindow.document.write('td.kop-text h1 { margin: 2px 0; font-size: 13.5pt; font-weight: bold; color: #000; letter-spacing: 0.3px; }');
    printWindow.document.write('td.kop-text p.alamat { margin: 2px 0; font-size: 9.5pt; color: #000; }');
    printWindow.document.write('td.kop-text p.kontak { margin: 2px 0 0; font-size: 9pt; color: #000; }');
    printWindow.document.write('.title { text-align: center; font-weight: bold; font-size: 13pt; text-decoration: underline; margin: 14px 0 2px; text-transform: uppercase; }');
    printWindow.document.write('.subtitle { text-align: center; font-size: 10pt; color: #333; margin-bottom: 15px; }');
    printWindow.document.write('table.data-table { border-collapse: collapse; width: 100%; margin-bottom: 20px; font-size: 11pt; }');
    printWindow.document.write('table.data-table th, table.data-table td { border: 1px solid #000; padding: 7px 6px; text-align: left; font-size: 11pt; line-height: 1.35; }');
    printWindow.document.write('table.data-table th { background-color: #f2f2f2; text-align: center !important; font-weight: bold; }');
    printWindow.document.write('.signature-wrapper { margin-top: 30px; display: flex; justify-content: flex-end; }');
    printWindow.document.write('.signature-box { width: 250px; text-align: left; }');
    printWindow.document.write('.signature-space { padding: 10px 0; }');
    printWindow.document.write('.qr-code { height: 80px; width: 80px; object-fit: contain; }');
    printWindow.document.write('.no-print { display: none; }');
    printWindow.document.write('</style></head><body>');

    printWindow.document.write('<table class=\"kop-header-pramuka\"><tr>');
    printWindow.document.write('<td class=\"kop-logo-left\">');
    if (logoPramuka) {
        printWindow.document.write('<img src=\"' + logoPramuka + '\" alt=\"Logo Pramuka\">');
    }
    printWindow.document.write('</td><td class=\"kop-text\">');
    printWindow.document.write('<h2>GERAKAN PRAMUKA GUGUS DEPAN ' + nomorGudep.toUpperCase() + '</h2>');
    printWindow.document.write('<h1>BERPANGKALAN PADA ' + schoolName.toUpperCase() + '</h1>');
    if (schoolAddress) {
        printWindow.document.write('<p class=\"alamat\">' + schoolAddress + '</p>');
    }
    if (schoolWebsite || schoolEmail) {
        var kontak = '';
        if (schoolWebsite) kontak += 'Website: <span style=\"color:#00f;text-decoration:underline;\">' + schoolWebsite + '</span>';
        if (schoolWebsite && schoolEmail) kontak += '&nbsp;&nbsp;&nbsp;&nbsp;';
        if (schoolEmail) kontak += 'email: <span style=\"color:#00f;text-decoration:underline;\">' + schoolEmail + '</span>';
        printWindow.document.write('<p class=\"kontak\">' + kontak + '</p>');
    }
    printWindow.document.write('</td><td class=\"kop-logo-right\">');
    if (logoWosm) {
        printWindow.document.write('<img src=\"' + logoWosm + '\" alt=\"Logo WOSM\">');
    }
    printWindow.document.write('</td></tr></table>');

    printWindow.document.write('<div class=\"title\">DATA TINGKAT PRAMUKA</div>');
    printWindow.document.write('<div class=\"subtitle\">Tahun Ajaran ' + academicYear + '</div>');

    var cleanTable = table.cloneNode(true);
    cleanTable.className = 'data-table';
    var rows = cleanTable.rows;
    for (var i = 0; i < rows.length; i++) {
        rows[i].deleteCell(-1);
    }

    printWindow.document.write(cleanTable.outerHTML);

    printWindow.document.write('<div class=\"signature-wrapper\">');
    printWindow.document.write('<div class=\"signature-box\">');
    printWindow.document.write('<p>' + printPlace + ', ' + printDate + '</p>');
    printWindow.document.write('<p>Ketua Gudep,</p>');
    printWindow.document.write('<div class=\"signature-space\">');
    printWindow.document.write('<img src=\"' + qrUrl + '\" class=\"qr-code\">');
    printWindow.document.write('</div>');
    printWindow.document.write('<p style=\"margin-bottom: 0;\"><strong>' + headName + '</strong></p>');
    printWindow.document.write('<p style=\"margin-top: 0;\">NTA. ' + headNip + '</p>');
    printWindow.document.write('</div>');
    printWindow.document.write('</div>');

    printWindow.document.write('<script>window.onload = function() { setTimeout(function() { window.print(); window.close(); }, 500); }<\/script>');
    printWindow.document.write('</body></html>');
    printWindow.document.close();
}
});
";

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Data Tingkat Pramuka</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4>Data Tingkat Pramuka</h4>
                    <div class="card-header-action">
                        <button type="button" class="btn btn-warning mr-1" onclick="exportToPDF()">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                        <button class="btn btn-primary" data-toggle="modal" data-target="#addModal" type="button">
                            <i class="fas fa-plus"></i> Tambah
                        </button>
                    </div>
                </div>

                <div class="card-body">
                    <input type="hidden" id="schoolName" value="<?= htmlspecialchars($school_profile['nama_madrasah'] ?? 'MADRASAH') ?>">
                    <input type="hidden" id="schoolAddress" value="<?= htmlspecialchars($school_profile['alamat'] ?? '') ?>">
                    <input type="hidden" id="schoolEmail" value="<?= htmlspecialchars($school_profile['email_madrasah'] ?? '') ?>">
                    <input type="hidden" id="schoolWebsite" value="<?= htmlspecialchars($school_profile['website_madrasah'] ?? '') ?>">
                    <input type="hidden" id="academicYear" value="<?= htmlspecialchars($school_profile['tahun_ajaran'] ?? '-') ?>">
                    <input type="hidden" id="headName" value="<?= htmlspecialchars($print_settings_data['ketua_gudep'] ?? $school_profile['nama_kepala'] ?? '-') ?>">
                    <input type="hidden" id="headNip" value="<?= htmlspecialchars($print_settings_data['nta_ketua_gudep'] ?? $school_profile['nip_kepala'] ?? '-') ?>">
                    <input type="hidden" id="nomorGudep" value="<?= htmlspecialchars(trim((string)(($print_settings_data['nomor_gudep'] ?? '') ?: ($print_settings_data['gugus_depan'] ?? '03.016')))) ?>">
                    <input type="hidden" id="logoPramuka" value="<?= !empty($print_settings_data['logo_pramuka']) && is_file(__DIR__ . '/../uploads/' . basename($print_settings_data['logo_pramuka'])) ? '../uploads/' . basename($print_settings_data['logo_pramuka']) : '' ?>">
                    <input type="hidden" id="logoWosm" value="<?= !empty($print_settings_data['logo_wosm']) && is_file(__DIR__ . '/../uploads/' . basename($print_settings_data['logo_wosm'])) ? '../uploads/' . basename($print_settings_data['logo_wosm']) : '' ?>">
                    <input type="hidden" id="printPlace" value="<?= htmlspecialchars($print_settings_data['tempat_surat'] ?? $school_profile['tempat_jadwal'] ?? 'Padang') ?>">
                    <input type="hidden" id="printDate" value="<?= date('d-m-Y') ?>">
                    <?php if ($schema_error || $fetch_error): ?>
                        <div class="alert alert-danger">
                            <strong>Terjadi masalah pada database.</strong><br>
                            <?php if (!empty($schema_error)) echo htmlspecialchars($schema_error); ?>
                            <?php if (!empty($fetch_error)) echo htmlspecialchars($fetch_error); ?>
                        </div>
                    <?php endif; ?>

                    <div class="table-responsive">
                        <table class="table table-striped" id="table-1">
                            <thead>
                                <tr>
                                    <th class="text-center" width="8%">No</th>
                                    <th>Nama Tingkat</th>
                                    <th>Golongan</th>
                                    <th width="15%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($rows)): ?>
                                    <?php foreach ($rows as $idx => $row): ?>
                                        <?php
                                            $golongan_tampil = tingkat_barung_golongan_otomatis($row['nama_tingkat'] ?? '', $row['golongan'] ?? '');
                                            $golongan_badge = $golongan_tampil === 'Penggalang' ? 'badge-danger' : 'badge-success';
                                        ?>
                                        <tr>
                                            <td class="text-center"><?= (int)($idx + 1) ?></td>
                                            <td><?= htmlspecialchars($row['nama_tingkat'] ?? '') ?></td>
                                            <td><span class="badge <?= $golongan_badge ?>"><?= htmlspecialchars($golongan_tampil) ?></span></td>
                                            <td>
                                                <button class="btn btn-warning btn-sm edit-btn"
                                                    data-id="<?= (int)($row['id_tingkat_barung'] ?? 0) ?>"
                                                    data-nama="<?= htmlspecialchars($row['nama_tingkat'] ?? '', ENT_QUOTES) ?>"
                                                    data-golongan="<?= htmlspecialchars($golongan_tampil, ENT_QUOTES) ?>"
                                                    type="button">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-danger btn-sm delete-btn"
                                                    data-id="<?= (int)($row['id_tingkat_barung'] ?? 0) ?>"
                                                    data-nama="<?= htmlspecialchars($row['nama_tingkat'] ?? '', ENT_QUOTES) ?>"
                                                    type="button">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-labelledby="addModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addModalLabel">Tambah Tingkat Pramuka</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="add_tingkat_barung" value="1">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nama Tingkat</label>
                        <input type="text" class="form-control" name="nama_tingkat" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Golongan</label>
                        <select class="form-control" name="golongan" id="add_golongan" required>
                            <option value="Siaga">Siaga</option>
                            <option value="Penggalang">Penggalang</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editModalLabel">Edit Tingkat Pramuka</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="update_tingkat_barung" value="1">
                <input type="hidden" name="id_tingkat_barung" id="edit_id_tingkat_barung" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nama Tingkat</label>
                        <input type="text" class="form-control" name="nama_tingkat" id="edit_nama_tingkat" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Golongan</label>
                        <select class="form-control" name="golongan" id="edit_golongan" required>
                            <option value="Siaga">Siaga</option>
                            <option value="Penggalang">Penggalang</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
