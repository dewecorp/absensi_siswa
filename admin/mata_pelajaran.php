<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Ensure session is started for activity logging
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has allowed level
if (!isAuthorized(['admin', 'guru', 'wali', 'tata_usaha', 'kepala_madrasah', 'kepala'])) {
    redirect('../login.php');
}

// Read-only view for non-admin users (no CRUD)
$user_level = getUserLevel();
$is_readonly = $user_level !== 'admin';

// Get school profile
$school_profile = getSchoolProfile($pdo);
$school_name = strtoupper($school_profile['nama_madrasah'] ?? 'Sistem Informasi Madrasah');
$logo_file = $school_profile['logo'] ?? 'logo.png';
$web_root = dirname(dirname($_SERVER['PHP_SELF']));
$web_root = $web_root == '/' || $web_root == '\\' ? '' : $web_root;
$logo_url = $web_root . '/assets/img/' . $logo_file;
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$logo_absolute_url = $scheme . '://' . $host . $logo_url;

// Set page title
$page_title = 'Mata Pelajaran';

// Ensure schema: add jenis_mapel column if missing
$has_jenis_mapel = false;
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM tb_mata_pelajaran LIKE 'jenis_mapel'");
    $has_jenis_mapel = $colCheck->rowCount() > 0;
    if (!$has_jenis_mapel) {
        $pdo->exec("ALTER TABLE tb_mata_pelajaran ADD COLUMN jenis_mapel VARCHAR(20) NULL DEFAULT 'Akademik' AFTER kode_mapel");
        $colCheck = $pdo->query("SHOW COLUMNS FROM tb_mata_pelajaran LIKE 'jenis_mapel'");
        $has_jenis_mapel = $colCheck->rowCount() > 0;
    }
} catch (Exception $e) {
    // Ignore schema change errors to avoid breaking page on limited hosting
}

// Handle form submissions
$message = '';
if (!$is_readonly && $_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_mapel'])) {
        $nama_mapel = trim($_POST['nama_mapel']);
        $kode_mapel = trim($_POST['kode_mapel']);
        $kktp = isset($_POST['kktp']) && $_POST['kktp'] !== '' ? (int)$_POST['kktp'] : null;
        $jenis_mapel = isset($_POST['jenis_mapel']) && in_array($_POST['jenis_mapel'], ['Akademik', 'Non Akademik']) ? $_POST['jenis_mapel'] : 'Akademik';
        
        // Cek duplikasi mata pelajaran
        $check = $pdo->prepare("SELECT COUNT(*) FROM tb_mata_pelajaran WHERE nama_mapel = ? OR kode_mapel = ?");
        $check->execute([$nama_mapel, $kode_mapel]);
        if ($check->fetchColumn() > 0) {
            $message = ['type' => 'danger', 'text' => 'Mata pelajaran atau Kode Mapel sudah ada!'];
        } else {
            if ($has_jenis_mapel) {
                $stmt = $pdo->prepare("INSERT INTO tb_mata_pelajaran (nama_mapel, kode_mapel, jenis_mapel, kktp) VALUES (?, ?, ?, ?)");
                $ok = $stmt->execute([$nama_mapel, $kode_mapel, $jenis_mapel, $kktp]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO tb_mata_pelajaran (nama_mapel, kode_mapel, kktp) VALUES (?, ?, ?)");
                $ok = $stmt->execute([$nama_mapel, $kode_mapel, $kktp]);
            }

            if ($ok) {
                $message = ['type' => 'success', 'text' => 'Mata pelajaran berhasil ditambahkan!'];
                $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                $kktp_log = $kktp !== null ? "dengan KKTP $kktp" : "tanpa KKTP";
                logActivity($pdo, $username, 'Tambah Mata Pelajaran', "Menambahkan mapel $nama_mapel ($kode_mapel) [$jenis_mapel] $kktp_log");
            } else {
                $message = ['type' => 'danger', 'text' => 'Gagal menambahkan mata pelajaran!'];
            }
        }
    } elseif (isset($_POST['update_mapel'])) {
        $id_mapel = (int)$_POST['id_mapel'];
        $nama_mapel = trim($_POST['nama_mapel']);
        $kode_mapel = trim($_POST['kode_mapel']);
        $kktp = isset($_POST['kktp']) && $_POST['kktp'] !== '' ? (int)$_POST['kktp'] : null;
        $jenis_mapel = isset($_POST['jenis_mapel']) && in_array($_POST['jenis_mapel'], ['Akademik', 'Non Akademik']) ? $_POST['jenis_mapel'] : 'Akademik';
        
        // Cek duplikasi mata pelajaran selain ID ini
        $check = $pdo->prepare("SELECT COUNT(*) FROM tb_mata_pelajaran WHERE (nama_mapel = ? OR kode_mapel = ?) AND id_mapel != ?");
        $check->execute([$nama_mapel, $kode_mapel, $id_mapel]);
        if ($check->fetchColumn() > 0) {
            $message = ['type' => 'danger', 'text' => 'Mata pelajaran atau Kode Mapel sudah ada!'];
        } else {
            if ($has_jenis_mapel) {
                $stmt = $pdo->prepare("UPDATE tb_mata_pelajaran SET nama_mapel=?, kode_mapel=?, jenis_mapel=?, kktp=? WHERE id_mapel=?");
                $ok = $stmt->execute([$nama_mapel, $kode_mapel, $jenis_mapel, $kktp, $id_mapel]);
            } else {
                $stmt = $pdo->prepare("UPDATE tb_mata_pelajaran SET nama_mapel=?, kode_mapel=?, kktp=? WHERE id_mapel=?");
                $ok = $stmt->execute([$nama_mapel, $kode_mapel, $kktp, $id_mapel]);
            }

            if ($ok) {
                $message = ['type' => 'success', 'text' => 'Mata pelajaran berhasil diupdate!'];
                $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                $kktp_log = $kktp !== null ? "KKTP $kktp" : "tanpa KKTP";
                logActivity($pdo, $username, 'Update Mata Pelajaran', "Update mapel ID $id_mapel menjadi $nama_mapel ($kode_mapel) [$jenis_mapel] $kktp_log");
            } else {
                $message = ['type' => 'danger', 'text' => 'Gagal mengupdate mata pelajaran!'];
            }
        }
    } elseif (isset($_POST['set_global_kktp'])) {
        $kktp = isset($_POST['kktp']) && $_POST['kktp'] !== '' ? (int)$_POST['kktp'] : null;
        $only_empty = isset($_POST['only_empty']);
        
        $sql = "UPDATE tb_mata_pelajaran SET kktp = ?";
        if ($only_empty) {
            $sql .= " WHERE kktp IS NULL";
        }
        
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute([$kktp])) {
            $count = $stmt->rowCount();
            $message = ['type' => 'success', 'text' => "Berhasil mengupdate KKTP untuk $count mata pelajaran!"];
            $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
            $kktp_log = $kktp !== null ? "KKTP $kktp" : "tanpa KKTP";
            $scope_log = $only_empty ? "untuk mapel tanpa KKTP" : "untuk SEMUA mapel";
            logActivity($pdo, $username, 'Update Global KKTP', "Update global $kktp_log $scope_log");
        } else {
            $message = ['type' => 'danger', 'text' => 'Gagal mengupdate KKTP global!'];
        }
    } elseif (isset($_POST['delete_mapel'])) {
        $id_mapel = (int)$_POST['id_mapel'];
        
        // Ambil data sebelum hapus untuk log
        $stmt = $pdo->prepare("SELECT nama_mapel FROM tb_mata_pelajaran WHERE id_mapel = ?");
        $stmt->execute([$id_mapel]);
        $mapel_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $nama_mapel = $mapel_data ? $mapel_data['nama_mapel'] : '?';

        $stmt = $pdo->prepare("DELETE FROM tb_mata_pelajaran WHERE id_mapel=?");
        if ($stmt->execute([$id_mapel])) {
            $message = ['type' => 'success', 'text' => 'Mata pelajaran berhasil dihapus!'];
            $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
            logActivity($pdo, $username, 'Hapus Mata Pelajaran', "Menghapus mapel $nama_mapel");
        } else {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus mata pelajaran!'];
        }
    }
}

// Get subjects
if ($is_readonly) {
    // Guru/Wali: akademik only
    $mata_pelajaran = getFilteredSubjects($pdo);
} else {
    // Admin: all
    // Order by kode_mapel with natural sorting (numeric aware)
    $stmt = $pdo->query("SELECT * FROM tb_mata_pelajaran ORDER BY 
        CASE 
            WHEN kode_mapel REGEXP '^[0-9]+$' THEN 1
            ELSE 0
        END,
        CASE 
            WHEN kode_mapel REGEXP '^[0-9]+$' THEN CAST(kode_mapel AS UNSIGNED)
            ELSE 999999
        END,
        kode_mapel ASC");
    $mata_pelajaran = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Define CSS libraries for this page
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'https://cdn.datatables.net/select/1.3.3/css/select.bootstrap4.min.css'
];

// Define JS libraries for this page
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.datatables.net/select/1.3.3/js/dataTables.select.min.js'
];

// Define page-specific JS
$js_page = [];

// Add JavaScript
$no_sort_targets = $is_readonly ? "[0]" : "[0, 5]";
$export_session_type = addslashes($user_level ?: 'admin');
$js_page[] = "
// Natural sort plugin for DataTables
jQuery.fn.dataTableExt.oSort['natural-asc'] = function(a, b) {
    var x = a.toString().replace(/<[^>]*>/g, ''); // Remove HTML tags
    var y = b.toString().replace(/<[^>]*>/g, '');
    return x.localeCompare(y, undefined, { numeric: true, sensitivity: 'base' });
};

jQuery.fn.dataTableExt.oSort['natural-desc'] = function(a, b) {
    var x = a.toString().replace(/<[^>]*>/g, '');
    var y = b.toString().replace(/<[^>]*>/g, '');
    return y.localeCompare(x, undefined, { numeric: true, sensitivity: 'base' });
};

$(document).ready(function() {
    // Initialize DataTable
    var table = $('#table-1').DataTable({
        \"order\": [[1, 'asc']],  // Sort by Kode Mapel (column index 1) ascending
        \"columnDefs\": [
            { \"type\": \"natural\", \"targets\": [1] },  // Natural sort for Kode Mapel
            { \"sortable\": false, \"targets\": $no_sort_targets }  // No sorting for No (and Aksi if exists)
        ],
        \"language\": {
            \"lengthMenu\": \"Tampilkan _MENU_ entri\",
            \"zeroRecords\": \"Tidak ada data yang ditemukan\",
            \"info\": \"Menampilkan _START_ sampai _END_ dari _TOTAL_ entri\",
            \"infoEmpty\": \"Menampilkan 0 sampai 0 dari 0 entri\",
            \"infoFiltered\": \"(disaring dari _MAX_ total entri)\",
            \"search\": \"Cari:\",
            \"paginate\": {
                \"first\": \"Pertama\",
                \"last\": \"Terakhir\",
                \"next\": \"Selanjutnya\",
                \"previous\": \"Sebelumnya\"
            }
        }
    });
    
    // Update row numbers after DataTables draws
    $('#table-1').on('draw.dt', function() {
        var info = table.page.info();
        $('#table-1 tbody tr').each(function(index) {
            var currentPageNumber = info.page * info.length + (index + 1);
            $(this).find('td:first-child').text(currentPageNumber);
        });
    });
    
    // Initial row numbering
    var info = table.page.info();
    $('#table-1 tbody tr').each(function(index) {
        var currentPageNumber = info.page * info.length + (index + 1);
        $(this).find('td:first-child').text(currentPageNumber);
    });

    // Handle Edit Button
    $(document).on('click', '.edit-btn', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        var kode = $(this).data('kode');
        var kktp = $(this).data('kktp');
        var jenis = $(this).data('jenis') || 'Akademik';

        $('#edit_id_mapel').val(id);
        $('#edit_nama_mapel').val(nama);
        $('#edit_kode_mapel').val(kode);
        $('#edit_kktp').val(kktp);
        $('#edit_jenis_mapel').val(jenis);
        
        $('#editModal').modal('show');
    });

    // Handle Delete Button
    $(document).on('click', '.delete-btn', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        
        Swal.fire({
            title: 'Konfirmasi Hapus',
            text: 'Apakah Anda yakin ingin menghapus Mata Pelajaran ' + nama + '?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                var form = $('<form method=\"POST\" action=\"\">' +
                    '<input type=\"hidden\" name=\"id_mapel\" value=\"' + id + '\">' +
                    '<input type=\"hidden\" name=\"delete_mapel\" value=\"1\">' +
                    '</form>');
                $('body').append(form);
                form.submit();
            }
        });
    });

    // Handle Global KKTP Update
    $('#btn-update-global-kktp').on('click', function(e) {
        e.preventDefault();
        var form = $(this).closest('form');
        
        Swal.fire({
            title: 'Konfirmasi Update',
            text: 'Apakah Anda yakin ingin mengubah data KKTP?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, Update!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                // Add hidden input to simulate the button click name
                $('<input>').attr({
                    type: 'hidden',
                    name: 'set_global_kktp',
                    value: '1'
                }).appendTo(form);
                form.submit();
            }
        });
    });

    // Handle Export
    $('.export-btn').on('click', function(e) {
        e.preventDefault();
        var type = $(this).data('type');

        // Build export table from full DataTables data (all pages, with current filter/search)
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
                    // Re-number based on full filtered dataset, not current page.
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
                '.report-title h2 { margin: 4px 0 0; font-size: 22px; font-weight: 600; letter-spacing: .4px; text-transform: uppercase; }' +
                '.report-title h2 { color: #000; }' +
                '.report-meta { margin: 8px 0 14px; font-size: 11px; color: #000; }' +
                '.report-table { width: calc(100% - 18px); border-collapse: collapse; margin-top: 8px; margin-right: 18px; color: #000; }' +
                '.report-table th { background: #e5e7eb; color: #000; font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: .3px; border: 1px solid #cbd5e1; padding: 8px 10px; }' +
                '.report-table td { border: 1px solid #d1d5db; padding: 7px 10px; font-size: 12px; }' +
                '.report-table tbody tr:nth-child(even) { background: #f9fafb; }' +
                '.text-center { text-align: center; }' +
                '.signature { margin-top: 36px; display: flex; justify-content: flex-end; page-break-inside: avoid; }' +
                '.signature-box { width: 290px; text-align: center; }' +
                '.signature-box .name { margin-top: 12px; font-weight: 700; text-decoration: none; color: #000; }' +
                '@media print { body { padding: 0; } }' +
                '</style>';

            exportTable.removeClass();
            exportTable.addClass('report-table');
            exportTable.find('th:first-child, td:first-child, th:last-child, td:last-child').addClass('text-center');
            var logoAbsoluteUrl = '$logo_absolute_url';

            var headerContent = '<div class=\"report-header\">' +
                '<div class=\"report-logo\"><img src=\"' + logoAbsoluteUrl + '\" alt=\"Logo Madrasah\" onerror=\"this.style.display=\\'none\\'\"></div>' +
                '<div class=\"report-title\"><h1>$school_name</h1><h2>Data Mata Pelajaran</h2></div>' +
                '</div>' +
                '<div class=\"report-meta\">Dicetak pada: " . formatDateIndonesia(date('Y-m-d')) . "</div>';

            // Add signature block
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

            var pdfHtml = '<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><title>Data Mata Pelajaran</title>' +
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
            var url = '../config/excel_export?session_type=$export_session_type';
            var form = $('<form method=\"POST\" action=\"' + url + '\" target=\"_blank\">' +
                '<input type=\"hidden\" name=\"table_data\" value=\"\">' +
                '<input type=\"hidden\" name=\"report_title\" value=\"Data Mata Pelajaran\">' +
                '<input type=\"hidden\" name=\"filename\" value=\"data_mata_pelajaran\">' +
                '</form>');
                
            form.find('input[name=\"table_data\"]').val(exportTable[0].outerHTML);
            $('body').append(form);
            form.submit();
            form.remove();
        }
    });
});
";

// Add SweetAlert for messages
if ($message) {
    $swal_icon = $message['type'] == 'success' ? 'success' : 'error';
    $swal_title = $message['type'] == 'success' ? 'Berhasil!' : 'Gagal!';
    $swal_text = json_encode($message['text']); // Encode to ensure safe JS string
    $swal_timer = $message['type'] == 'success' ? 1500 : 'null';
    $swal_show_confirm = $message['type'] == 'success' ? 'false' : 'true';
    
    $js_page[] = "
    Swal.fire({
        icon: '$swal_icon',
        title: '$swal_title',
        text: $swal_text,
        timer: $swal_timer,
        showConfirmButton: $swal_show_confirm
    });";
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<!-- Main Content -->
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Mata Pelajaran</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Data Mata Pelajaran</h4>
                            <div class="card-header-action">
                                <?php if (!$is_readonly): ?>
                                <button class="btn btn-primary" data-toggle="modal" data-target="#addModal">
                                    <i class="fas fa-plus"></i> Tambah Mapel
                                </button>
                                <button class="btn btn-info ml-1" data-toggle="modal" data-target="#globalKktpModal">
                                    <i class="fas fa-cog"></i> Set KKTP Global
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
                            <div class="table-responsive">
                                <table class="table table-striped" id="table-1">
                                    <thead>
                                        <tr>
                                            <th class="text-center" width="5%">No</th>
                                            <th>Kode Mapel</th>
                                            <th>Mata Pelajaran</th>
                                            <?php if (!$is_readonly): ?>
                                                <th>Jenis</th>
                                            <?php endif; ?>
                                            <th>KKTP</th>
                                            <?php if (!$is_readonly): ?>
                                                <th width="15%">Aksi</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        foreach ($mata_pelajaran as $row): 
                                        ?>
                                        <tr>
                                            <td class="text-center"></td>
                                            <td><?= htmlspecialchars($row['kode_mapel'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['nama_mapel']) ?></td>
                                            <?php if (!$is_readonly): ?>
                                                <td><?= htmlspecialchars($row['jenis_mapel'] ?? 'Akademik') ?></td>
                                            <?php endif; ?>
                                            <td><?= $row['kktp'] !== null ? htmlspecialchars($row['kktp']) : '-' ?></td>
                                            <?php if (!$is_readonly): ?>
                                                <td>
                                                    <button class="btn btn-warning btn-sm edit-btn" 
                                                            data-id="<?= $row['id_mapel'] ?>"
                                                            data-nama="<?= htmlspecialchars($row['nama_mapel']) ?>"
                                                            data-kode="<?= htmlspecialchars($row['kode_mapel'] ?? '') ?>"
                                                            data-jenis="<?= htmlspecialchars($row['jenis_mapel'] ?? 'Akademik') ?>"
                                                            data-kktp="<?= htmlspecialchars($row['kktp'] ?? '') ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-danger btn-sm delete-btn" 
                                                            data-id="<?= $row['id_mapel'] ?>"
                                                            data-nama="<?= htmlspecialchars($row['nama_mapel']) ?>">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php if (!$is_readonly): ?>
<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-labelledby="addModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addModalLabel">Tambah Mata Pelajaran</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Kode Mapel</label>
                        <input type="text" class="form-control" name="kode_mapel" required>
                    </div>
                    <div class="form-group">
                        <label>Nama Mata Pelajaran</label>
                        <input type="text" class="form-control" name="nama_mapel" required>
                    </div>
                    <div class="form-group">
                        <label>Jenis Mapel</label>
                        <select class="form-control" name="jenis_mapel">
                            <option value="Akademik">Akademik</option>
                            <option value="Non Akademik">Non Akademik</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>KKTP (Nilai Minimum)</label>
                        <input type="number" class="form-control" name="kktp" value="75">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" name="add_mapel" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Global KKTP Modal -->
<div class="modal fade" id="globalKktpModal" tabindex="-1" role="dialog" aria-labelledby="globalKktpModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="globalKktpModalLabel">Set KKTP Global</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <p>Fitur ini akan mengatur nilai KKTP untuk mata pelajaran.</p>
                    <div class="form-group">
                        <label>Nilai KKTP Baru</label>
                        <input type="number" class="form-control" name="kktp" value="75" required>
                    </div>
                    <div class="form-group form-check">
                        <input type="checkbox" class="form-check-input" id="only_empty" name="only_empty">
                        <label class="form-check-label" for="only_empty">Hanya update mapel yang KKTP-nya kosong (NULL)</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="button" id="btn-update-global-kktp" class="btn btn-info">Update Global</button>
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
                <h5 class="modal-title" id="editModalLabel">Edit Mata Pelajaran</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="id_mapel" id="edit_id_mapel">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Kode Mapel</label>
                        <input type="text" class="form-control" name="kode_mapel" id="edit_kode_mapel" required>
                    </div>
                    <div class="form-group">
                        <label>Nama Mata Pelajaran</label>
                        <input type="text" class="form-control" name="nama_mapel" id="edit_nama_mapel" required>
                    </div>
                    <div class="form-group">
                        <label>Jenis Mapel</label>
                        <select class="form-control" name="jenis_mapel" id="edit_jenis_mapel">
                            <option value="Akademik">Akademik</option>
                            <option value="Non Akademik">Non Akademik</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>KKTP (Nilai Minimum)</label>
                        <input type="number" class="form-control" name="kktp" id="edit_kktp">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" name="update_mapel" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include '../templates/footer.php'; ?>
