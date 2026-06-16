<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Ensure session is started for activity logging (some pages rely on it)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Admin/TU full akses; level lain baca saja
$can_manage_ekstrakurikuler = isAuthorized(['admin', 'tata_usaha']);
$can_view_ekstrakurikuler = $can_manage_ekstrakurikuler || isAuthorized(['kepala_madrasah', 'wali', 'guru']);
if (!$can_view_ekstrakurikuler) {
    redirect('../login.php');
}

// Page title
$page_title = 'Data Ekstrakurikuler';

// DataTables
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];

$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$school_profile = getSchoolProfile($pdo);
$school_name = strtoupper($school_profile['nama_madrasah'] ?? 'Sistem Informasi Madrasah');
$logo_file = $school_profile['logo'] ?? 'logo.png';
$web_root = dirname(dirname($_SERVER['PHP_SELF']));
$web_root = $web_root == '/' || $web_root == '\\' ? '' : $web_root;
$logo_url = $web_root . '/assets/img/' . $logo_file;
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$logo_absolute_url = $scheme . '://' . $host . $logo_url;
$tahun_ajaran = (string)($school_profile['tahun_ajaran'] ?? '');
$tahun_ajaran_slug = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $tahun_ajaran);
$tahun_ajaran_slug = trim($tahun_ajaran_slug, '_');
$ekstrakurikuler_report_title = 'Data Ekstrakurikuler' . ($tahun_ajaran !== '' ? ' - ' . $tahun_ajaran : '');
$ekstrakurikuler_excel_filename = 'data_ekstrakurikuler' . ($tahun_ajaran_slug !== '' ? '_' . $tahun_ajaran_slug : '');

// --- Ensure schema (best-effort) ---
$table_name = 'tb_ekstrakurikuler';
$schema_checked = false;
$schema_error = null;

try {
    $stmt = $pdo->query("SHOW TABLES LIKE '{$table_name}'");
    $table_exists = (bool)$stmt->fetch(PDO::FETCH_NUM);

    if (!$table_exists) {
        // Create minimal schema expected by this page
        $pdo->exec("
            CREATE TABLE {$table_name} (
                id_ekstrakurikuler INT AUTO_INCREMENT PRIMARY KEY,
                nama_ekstrakurikuler VARCHAR(100) NOT NULL,
                hari VARCHAR(30) NOT NULL,
                waktu TIME NOT NULL,
                waktu_selesai TIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } else {
        // Ensure columns exist (skip failures on limited hosting)
        $required_cols = [
            'id_ekstrakurikuler' => "INT NOT NULL",
            'nama_ekstrakurikuler' => "VARCHAR(100) NOT NULL",
            'hari' => "VARCHAR(30) NOT NULL",
            'waktu' => "TIME NOT NULL",
            'waktu_selesai' => "TIME NULL",
        ];

        foreach ($required_cols as $col => $typeDef) {
            $colStmt = $pdo->query("SHOW COLUMNS FROM {$table_name} LIKE '" . addslashes($col) . "'");
            $has_col = (bool)$colStmt->fetch(PDO::FETCH_ASSOC);
            if (!$has_col) {
                $pdo->exec("ALTER TABLE {$table_name} ADD COLUMN {$col} {$typeDef}");
            }
        }
    }
    $schema_checked = true;
} catch (Exception $e) {
    $schema_error = $e->getMessage();
}

// --- CRUD handling ---
$message = null; // ['type'=>'success'|'danger'|'warning', 'text'=>...]

function normalizeWaktuForInput($waktu) {
    if (empty($waktu)) return '';
    // TIME could be HH:MM:SS, while <input type="time"> expects HH:MM
    return substr((string)$waktu, 0, 5);
}

/** Format TIME ke tampilan 07.10 (titik seperti contoh pengguna). */
function formatJamTitik($waktu_raw) {
    if (empty($waktu_raw)) {
        return '';
    }
    $t = strtotime((string)$waktu_raw);
    if ($t === false) {
        return '';
    }
    return date('H.i', $t);
}

/** Kolom Waktu untuk tampilan: 07.10 - 08.55; jika selesai kosong sama dengan mulai, tampilkan satu jam saja */
function formatWaktuEkstraRange($waktu_mulai, $waktu_selesai) {
    $a = formatJamTitik($waktu_mulai);
    if ($a === '') {
        return '-';
    }
    $b = formatJamTitik($waktu_selesai);
    if ($b === '' || $b === $a) {
        return $a;
    }
    return $a . ' - ' . $b;
}

function validateHari($hari) {
    $hari = trim((string)$hari);
    return $hari;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_manage_ekstrakurikuler) {
        $message = ['type' => 'warning', 'text' => 'Mode baca saja. CRUD tidak diizinkan untuk level Anda.'];
    } else {
    // Add
    if (isset($_POST['add_ekstrakurikuler'])) {
        $nama_ekstrakurikuler = sanitizeInput($_POST['nama_ekstrakurikuler'] ?? '');
        $hari = sanitizeInput(validateHari($_POST['hari'] ?? ''));
        $waktu = sanitizeInput($_POST['waktu'] ?? '');
        $waktu_selesai = sanitizeInput($_POST['waktu_selesai'] ?? '');

        if ($nama_ekstrakurikuler === '' || $hari === '' || $waktu === '' || $waktu_selesai === '') {
            $message = ['type' => 'warning', 'text' => 'Harap lengkapi semua data ekstrakurikuler, termasuk waktu mulai dan selesai.'];
        } elseif (strtotime($waktu_selesai) <= strtotime($waktu)) {
            $message = ['type' => 'warning', 'text' => 'Waktu selesai harus setelah waktu mulai.'];
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO {$table_name} (nama_ekstrakurikuler, hari, waktu, waktu_selesai)
                    VALUES (?, ?, ?, ?)
                ");
                $ok = $stmt->execute([$nama_ekstrakurikuler, $hari, $waktu, $waktu_selesai]);

                if ($ok) {
                    $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                    logActivity($pdo, $username, 'Tambah Ekstrakurikuler', "Menambahkan ekstrakurikuler: {$nama_ekstrakurikuler} ({$hari}, {$waktu}-{$waktu_selesai})");
                    $message = ['type' => 'success', 'text' => 'Data ekstrakurikuler berhasil ditambahkan!'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal menambahkan data ekstrakurikuler.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Error DB: ' . $e->getMessage()];
            }
        }
    }

    // Update
    if (isset($_POST['update_ekstrakurikuler'])) {
        $id_ekstrakurikuler = (int)($_POST['id_ekstrakurikuler'] ?? 0);
        $nama_ekstrakurikuler = sanitizeInput($_POST['nama_ekstrakurikuler'] ?? '');
        $hari = sanitizeInput(validateHari($_POST['hari'] ?? ''));
        $waktu = sanitizeInput($_POST['waktu'] ?? '');
        $waktu_selesai = sanitizeInput($_POST['waktu_selesai'] ?? '');

        if ($id_ekstrakurikuler <= 0 || $nama_ekstrakurikuler === '' || $hari === '' || $waktu === '' || $waktu_selesai === '') {
            $message = ['type' => 'warning', 'text' => 'Harap lengkapi semua data ekstrakurikuler, termasuk waktu mulai dan selesai.'];
        } elseif (strtotime($waktu_selesai) <= strtotime($waktu)) {
            $message = ['type' => 'warning', 'text' => 'Waktu selesai harus setelah waktu mulai.'];
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE {$table_name}
                    SET nama_ekstrakurikuler = ?, hari = ?, waktu = ?, waktu_selesai = ?
                    WHERE id_ekstrakurikuler = ?
                ");
                $ok = $stmt->execute([$nama_ekstrakurikuler, $hari, $waktu, $waktu_selesai, $id_ekstrakurikuler]);

                if ($ok) {
                    $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                    logActivity($pdo, $username, 'Update Ekstrakurikuler', "Update ID {$id_ekstrakurikuler}: {$nama_ekstrakurikuler} ({$hari}, {$waktu}-{$waktu_selesai})");
                    $message = ['type' => 'success', 'text' => 'Data ekstrakurikuler berhasil diperbarui!'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal memperbarui data ekstrakurikuler.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Error DB: ' . $e->getMessage()];
            }
        }
    }

    // Delete
    if (isset($_POST['delete_ekstrakurikuler'])) {
        $id_ekstrakurikuler = (int)($_POST['id_ekstrakurikuler'] ?? 0);
        if ($id_ekstrakurikuler <= 0) {
            $message = ['type' => 'warning', 'text' => 'ID ekstrakurikuler tidak valid.'];
        } else {
            try {
                $nameStmt = $pdo->prepare("SELECT nama_ekstrakurikuler FROM {$table_name} WHERE id_ekstrakurikuler = ?");
                $nameStmt->execute([$id_ekstrakurikuler]);
                $rowName = $nameStmt->fetch(PDO::FETCH_ASSOC);
                $nama = $rowName ? ($rowName['nama_ekstrakurikuler'] ?? '-') : '-';

                $stmt = $pdo->prepare("DELETE FROM {$table_name} WHERE id_ekstrakurikuler = ?");
                $ok = $stmt->execute([$id_ekstrakurikuler]);

                if ($ok) {
                    $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                    logActivity($pdo, $username, 'Hapus Ekstrakurikuler', "Menghapus ekstrakurikuler: {$nama} (ID: {$id_ekstrakurikuler})");
                    $message = ['type' => 'success', 'text' => 'Data ekstrakurikuler berhasil dihapus!'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal menghapus data ekstrakurikuler.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Error DB: ' . $e->getMessage()];
            }
        }
    }
    }
}

// Fetch list
$ekstrakurikuler = [];
$fetch_error = null;
try {
    $stmt = $pdo->query("
        SELECT id_ekstrakurikuler, nama_ekstrakurikuler, hari, waktu, waktu_selesai
        FROM {$table_name}
        ORDER BY nama_ekstrakurikuler ASC, hari ASC
    ");
    $ekstrakurikuler = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $fetch_error = $e->getMessage();
}

// Page-specific JS
$js_page = [];

// SweetAlert messages
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
            { 'sortable': false, 'targets': [4] }
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

    $('#table-1').on('draw.dt', function() {
        var info = table.page.info();
        $('#table-1 tbody tr').each(function(index) {
            var currentPageNumber = info.page * info.length + (index + 1);
            $(this).find('td:first-child').text(currentPageNumber);
        });
    });

    // Set initial row numbers
    var info = table.page.info();
    $('#table-1 tbody tr').each(function(index) {
        $(this).find('td:first-child').text(info.page * info.length + (index + 1));
    });

    // Fill edit modal
    $(document).on('click', '.edit-btn', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama') || '';
        var hari = $(this).data('hari') || '';
        var mulai = $(this).data('mulai') || '';
        var selesai = $(this).data('selesai') || '';

        if (mulai && mulai.length > 5) mulai = mulai.substring(0, 5);
        if (selesai && selesai.length > 5) selesai = selesai.substring(0, 5);

        $('#edit_id_ekstrakurikuler').val(id);
        $('#edit_nama_ekstrakurikuler').val(nama);
        $('#edit_hari').val(hari);
        $('#edit_waktu').val(mulai);
        $('#edit_waktu_selesai').val(selesai);
        $('#editModal').modal('show');
    });

    // Delete confirmation
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
                    '<input type=\"hidden\" name=\"id_ekstrakurikuler\" value=\"' + id + '\">' +
                    '<input type=\"hidden\" name=\"delete_ekstrakurikuler\" value=\"1\">' +
                    '</form>');
                $('body').append(form);
                form.submit();
            }
        });
    });

    // Export handlers
    $('.export-btn').on('click', function(e) {
        e.preventDefault();
        var type = $(this).data('type');
        var sourceTable = $('#table-1');
        var aksiIndex = -1;
        var exportHeaders = [];

        sourceTable.find('thead th').each(function(i) {
            var text = $(this).text().trim();
            if (text.toLowerCase() === 'aksi') {
                aksiIndex = i;
                return;
            }
            exportHeaders.push(text);
        });

        var exportTable = $('<table class=\"table table-striped\"></table>');
        var thead = $('<thead><tr></tr></thead>');
        exportHeaders.forEach(function(h) {
            thead.find('tr').append('<th>' + h + '</th>');
        });
        exportTable.append(thead);

        var tbody = $('<tbody></tbody>');
        var allRows = table.rows({ search: 'applied', order: 'applied' }).data().toArray();
        allRows.forEach(function(rowData, rowIdx) {
            var tr = $('<tr></tr>');
            var exportColIdx = 0;

            rowData.forEach(function(cell, colIdx) {
                if (colIdx === aksiIndex) return;

                var cellText = $('<div>' + (cell == null ? '' : cell) + '</div>').text().trim();
                if (exportColIdx === 0) {
                    cellText = String(rowIdx + 1);
                }
                tr.append('<td>' + cellText + '</td>');
                exportColIdx++;
            });

            tbody.append(tr);
        });
        exportTable.append(tbody);

        if (type === 'pdf') {
            var pdfStyles = '<style>' +
                'body { font-family: Arial, sans-serif; color: #000; margin: 0; padding: 24px 42px 24px 24px; font-size: 12px; }' +
                '.report-header { border-bottom: 2px solid #111827; margin-bottom: 16px; padding-bottom: 12px; display: flex; align-items: center; }' +
                '.report-logo { width: 90px; text-align: center; }' +
                '.report-logo img { max-height: 72px; width: auto; }' +
                '.report-title { flex: 1; text-align: center; padding-right: 90px; }' +
                '.report-title h1 { margin: 0; font-size: 24px; letter-spacing: .4px; text-transform: uppercase; font-weight: 700; color: #000; }' +
                '.report-title h2 { margin: 4px 0 0; font-size: 22px; font-weight: 600; letter-spacing: .4px; text-transform: uppercase; color: #000; }' +
                '.report-meta { margin: 8px 0 14px; font-size: 11px; color: #000; }' +
                '.report-table { width: calc(100% - 18px); border-collapse: collapse; margin-top: 8px; margin-right: 18px; color: #000; }' +
                '.report-table th { background: #e5e7eb; color: #000; font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: .3px; border: 1px solid #cbd5e1; padding: 8px 10px; }' +
                '.report-table td { border: 1px solid #d1d5db; padding: 7px 10px; font-size: 12px; }' +
                '.report-table tbody tr:nth-child(even) { background: #f9fafb; }' +
                '.text-center { text-align: center; }' +
                '.signature { margin-top: 36px; display: flex; justify-content: flex-end; page-break-inside: avoid; }' +
                '.signature-box { width: 290px; text-align: center; }' +
                '.signature-box .name { margin-top: 12px; font-weight: 700; text-decoration: none; color: #000; }' +
                '.report-year { font-size: 11px; margin-top: 4px; }' +
                '@media print { body { padding: 0; } }' +
                '</style>';

            exportTable.removeClass();
            exportTable.addClass('report-table');
            exportTable.find('th:first-child, td:first-child').addClass('text-center');

            var headerContent = '<div class=\"report-header\">' +
                '<div class=\"report-logo\"><img src=\"$logo_absolute_url\" alt=\"Logo Madrasah\" onerror=\"this.style.display=\\'none\\'\"></div>' +
                '<div class=\"report-title\"><h1>$school_name</h1><h2>Data Ekstrakurikuler</h2><div class=\"report-year\">Tahun Ajaran $tahun_ajaran</div></div>' +
                '</div>' +
                '<div class=\"report-meta\">Dicetak pada: " . formatDateIndonesia(date('Y-m-d')) . "</div>';

            var madrasahHeadName = '" . addslashes($school_profile['kepala_madrasah'] ?? '.........................') . "';
            var madrasahHeadSignature = '" . addslashes($school_profile['ttd_kepala'] ?? '') . "';
            var schoolName = '" . addslashes($school_profile['nama_madrasah'] ?? 'Madrasah') . "';
            var schoolCity = '" . addslashes($school_profile['tempat_jadwal'] ?? 'Padang') . "';
            var reportDate = '" . formatDateIndonesia(date('Y-m-d')) . "';

            var signatureContent = '<div class=\"signature\"><div class=\"signature-box\">' +
                '<p>' + schoolCity + ', ' + reportDate + '<br>Kepala Madrasah,</p>';
            if (madrasahHeadSignature) {
                var qrContent = 'Validasi Tanda Tangan Digital: ' + madrasahHeadName + ' - ' + schoolName;
                var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' + encodeURIComponent(qrContent);
                signatureContent += '<img src=\"' + qrUrl + '\" alt=\"QR Signature\" style=\"width: 78px; height: 78px; margin: 10px auto; display: block;\">';
            } else {
                signatureContent += '<br><br><br><br><br>';
            }
            signatureContent += '<p class=\"name\">' + madrasahHeadName + '</p></div></div>';

            var pdfHtml = '<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><title>Data Ekstrakurikuler - $tahun_ajaran</title>' +
                pdfStyles +
                '</head><body>' +
                headerContent +
                exportTable[0].outerHTML +
                signatureContent +
                '<script>window.onload = function() { window.print(); };<\/script>' +
                '</body></html>';

            var pdfBlob = new Blob([pdfHtml], { type: 'text/html' });
            var pdfUrl = URL.createObjectURL(pdfBlob);
            window.open(pdfUrl, '_blank');
        } else {
            var form = $('<form method=\"POST\" action=\"../config/excel_export.php?session_type=admin\" target=\"_blank\">' +
                '<input type=\"hidden\" name=\"table_data\" value=\"\">' +
                '<input type=\"hidden\" name=\"report_title\" value=\"$ekstrakurikuler_report_title\">' +
                '<input type=\"hidden\" name=\"filename\" value=\"$ekstrakurikuler_excel_filename\">' +
                '</form>');

            form.find('input[name=\"table_data\"]').val(exportTable[0].outerHTML);
            $('body').append(form);
            form.submit();
            form.remove();
        }
    });
});
";

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Data Ekstrakurikuler</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Ekstrakurikuler</div>
                <div class="breadcrumb-item">Data Ekstrakurikuler</div>
            </div>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4>Data Ekstrakurikuler</h4>
                    <div class="card-header-action">
                        <?php if ($can_manage_ekstrakurikuler): ?>
                        <button class="btn btn-primary" data-toggle="modal" data-target="#addModal">
                            <i class="fas fa-plus"></i> Tambah
                        </button>
                        <?php endif; ?>
                        <a href="#" class="btn btn-success ml-1 export-btn" data-type="excel">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </a>
                        <a href="#" class="btn btn-danger ml-1 export-btn" data-type="pdf">
                            <i class="fas fa-file-pdf"></i> Export PDF
                        </a>
                    </div>
                </div>

                <div class="card-body">
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
                                    <th class="text-center" width="5%">No</th>
                                    <th>Nama Ekstrakurikuler</th>
                                    <th>Hari</th>
                                    <th>Waktu</th>
                                    <?php if ($can_manage_ekstrakurikuler): ?><th width="15%">Aksi</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($ekstrakurikuler)): ?>
                                    <?php foreach ($ekstrakurikuler as $idx => $row): ?>
                                        <?php
                                            $waktu_mulai = normalizeWaktuForInput($row['waktu'] ?? '');
                                            $waktu_selesai_in = normalizeWaktuForInput($row['waktu_selesai'] ?? '');
                                        ?>
                                        <tr>
                                            <td class="text-center"><?= (int)($idx + 1) ?></td>
                                            <td><?= htmlspecialchars($row['nama_ekstrakurikuler'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['hari'] ?? '') ?></td>
                                            <td><?= htmlspecialchars(formatWaktuEkstraRange($row['waktu'] ?? '', $row['waktu_selesai'] ?? '')) ?></td>
                                            <?php if ($can_manage_ekstrakurikuler): ?><td>
                                                <button class="btn btn-warning btn-sm edit-btn"
                                                    data-id="<?= (int)($row['id_ekstrakurikuler'] ?? 0) ?>"
                                                    data-nama="<?= htmlspecialchars($row['nama_ekstrakurikuler'] ?? '', ENT_QUOTES) ?>"
                                                    data-hari="<?= htmlspecialchars($row['hari'] ?? '', ENT_QUOTES) ?>"
                                                    data-mulai="<?= htmlspecialchars($waktu_mulai, ENT_QUOTES) ?>"
                                                    data-selesai="<?= htmlspecialchars($waktu_selesai_in, ENT_QUOTES) ?>"
                                                    type="button">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-danger btn-sm delete-btn"
                                                    data-id="<?= (int)($row['id_ekstrakurikuler'] ?? 0) ?>"
                                                    data-nama="<?= htmlspecialchars($row['nama_ekstrakurikuler'] ?? '', ENT_QUOTES) ?>"
                                                    type="button">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td><?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="<?= $can_manage_ekstrakurikuler ? '5' : '4' ?>" class="text-center text-muted">Tidak ada data.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php if ($can_manage_ekstrakurikuler): ?>
<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-labelledby="addModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addModalLabel">Tambah Ekstrakurikuler</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="add_ekstrakurikuler" value="1">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nama Ekstrakurikuler</label>
                        <input type="text" class="form-control" name="nama_ekstrakurikuler" required>
                    </div>
                    <div class="form-group">
                        <label>Hari</label>
                        <input type="text" class="form-control" name="hari" placeholder="Contoh: Senin" required>
                    </div>
                    <div class="form-group">
                        <label>Waktu mulai</label>
                        <input type="time" class="form-control" name="waktu" required>
                    </div>
                    <div class="form-group">
                        <label>Waktu selesai</label>
                        <input type="time" class="form-control" name="waktu_selesai" required>
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

<!-- Edit Modal (single modal for all rows) -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editModalLabel">Edit Ekstrakurikuler</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="update_ekstrakurikuler" value="1">
                <input type="hidden" name="id_ekstrakurikuler" id="edit_id_ekstrakurikuler" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nama Ekstrakurikuler</label>
                        <input type="text" class="form-control" name="nama_ekstrakurikuler" id="edit_nama_ekstrakurikuler" required>
                    </div>
                    <div class="form-group">
                        <label>Hari</label>
                        <input type="text" class="form-control" name="hari" id="edit_hari" required>
                    </div>
                    <div class="form-group">
                        <label>Waktu mulai</label>
                        <input type="time" class="form-control" name="waktu" id="edit_waktu" required>
                    </div>
                    <div class="form-group">
                        <label>Waktu selesai</label>
                        <input type="time" class="form-control" name="waktu_selesai" id="edit_waktu_selesai" required>
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
<?php endif; ?>

<?php include '../templates/footer.php'; ?>

