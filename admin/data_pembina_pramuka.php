<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$can_manage_pembina_pramuka = isAuthorized(['admin', 'tata_usaha']);
$can_view_pembina_pramuka = $can_manage_pembina_pramuka || isAuthorized(['kepala_madrasah', 'wali', 'guru']);
if (!$can_view_pembina_pramuka) {
    redirect('../login.php');
}

$school_profile = getSchoolProfile($pdo);
$page_title = 'Data Pembina Pramuka';

// DataTables
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
];

// --- Ensure schema (best-effort) ---
$table_name = 'tb_pembina_pramuka';
$schema_error = null;
try {
    $stmt = $pdo->query("SHOW TABLES LIKE '{$table_name}'");
    $table_exists = (bool)$stmt->fetch(PDO::FETCH_NUM);

    if (!$table_exists) {
        $pdo->exec("
            CREATE TABLE {$table_name} (
                id_pembina_pramuka INT AUTO_INCREMENT PRIMARY KEY,
                id_guru INT NULL,
                nama_pembina VARCHAR(100) NOT NULL,
                jabatan VARCHAR(100) NOT NULL,
                UNIQUE KEY uniq_id_guru (id_guru)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } else {
        $required_cols = [
            'id_pembina_pramuka' => "INT NOT NULL",
            'id_guru' => "INT NULL",
            'nama_pembina' => "VARCHAR(100) NOT NULL",
            'jabatan' => "VARCHAR(100) NOT NULL",
        ];
        foreach ($required_cols as $col => $typeDef) {
            $colStmt = $pdo->query("SHOW COLUMNS FROM {$table_name} LIKE '" . addslashes($col) . "'");
            $has_col = (bool)$colStmt->fetch(PDO::FETCH_ASSOC);
            if (!$has_col) {
                $pdo->exec("ALTER TABLE {$table_name} ADD COLUMN {$col} {$typeDef}");
            }
        }
        // Pastikan unique id_guru (abaikan jika sudah ada)
        try {
            $idx = $pdo->query("SHOW INDEX FROM {$table_name} WHERE Key_name = 'uniq_id_guru'")->fetch();
            if (!$idx) {
                $pdo->exec("ALTER TABLE {$table_name} ADD UNIQUE KEY uniq_id_guru (id_guru)");
            }
        } catch (Exception $e) { /* ignore */ }
        // Sinkronkan id_guru dari nama lama (best-effort)
        try {
            $pdo->exec("
                UPDATE {$table_name} p
                INNER JOIN tb_guru g ON TRIM(LOWER(g.nama_guru)) = TRIM(LOWER(p.nama_pembina))
                SET p.id_guru = g.id_guru
                WHERE p.id_guru IS NULL
            ");
        } catch (Exception $e) { /* ignore */ }
    }
} catch (Exception $e) {
    $schema_error = $e->getMessage();
}

$jabatan_options = [
    'Pembina Gudep' => 'Pembina Gudep',
    'Pembina Satuan' => 'Pembina Satuan',
];

// --- CRUD handling ---
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_manage_pembina_pramuka) {
        $message = ['type' => 'warning', 'text' => 'Mode baca saja. CRUD tidak diizinkan untuk level Anda.'];
    } else {
    // Add
    if (isset($_POST['add_pembina_pramuka'])) {
        $id_guru = (int)($_POST['id_guru'] ?? 0);
        $jabatan = sanitizeInput($_POST['jabatan'] ?? '');

        if ($id_guru <= 0 || $jabatan === '') {
            $message = ['type' => 'warning', 'text' => 'Harap pilih guru pembina dan jabatan.'];
        } elseif (!isset($jabatan_options[$jabatan])) {
            $message = ['type' => 'warning', 'text' => 'Pilihan jabatan tidak valid.'];
        } else {
            try {
                $gstmt = $pdo->prepare('SELECT nama_guru FROM tb_guru WHERE id_guru = ? LIMIT 1');
                $gstmt->execute([$id_guru]);
                $nama_guru = trim((string)$gstmt->fetchColumn());
                if ($nama_guru === '') {
                    $message = ['type' => 'warning', 'text' => 'Guru tidak ditemukan.'];
                } else {
                    $dup = $pdo->prepare("SELECT COUNT(*) FROM {$table_name} WHERE id_guru = ?");
                    $dup->execute([$id_guru]);
                    if ((int)$dup->fetchColumn() > 0) {
                        $message = ['type' => 'warning', 'text' => 'Guru tersebut sudah terdaftar sebagai pembina Pramuka.'];
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO {$table_name} (id_guru, nama_pembina, jabatan) VALUES (?, ?, ?)");
                        $ok = $stmt->execute([$id_guru, $nama_guru, $jabatan]);
                        if ($ok) {
                            $username = $_SESSION['username'] ?? 'system';
                            logActivity($pdo, $username, 'Tambah Pembina Pramuka', "{$nama_guru} - {$jabatan}");
                            $message = ['type' => 'success', 'text' => 'Data pembina pramuka berhasil ditambahkan!'];
                        } else {
                            $message = ['type' => 'danger', 'text' => 'Gagal menambahkan data pembina pramuka.'];
                        }
                    }
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Error DB: ' . $e->getMessage()];
            }
        }
    }

    // Update
    if (isset($_POST['update_pembina_pramuka'])) {
        $id = (int)($_POST['id_pembina_pramuka'] ?? 0);
        $id_guru = (int)($_POST['id_guru'] ?? 0);
        $jabatan = sanitizeInput($_POST['jabatan'] ?? '');

        if ($id <= 0 || $id_guru <= 0 || $jabatan === '') {
            $message = ['type' => 'warning', 'text' => 'Harap pilih guru pembina dan jabatan.'];
        } elseif (!isset($jabatan_options[$jabatan])) {
            $message = ['type' => 'warning', 'text' => 'Pilihan jabatan tidak valid.'];
        } else {
            try {
                $gstmt = $pdo->prepare('SELECT nama_guru FROM tb_guru WHERE id_guru = ? LIMIT 1');
                $gstmt->execute([$id_guru]);
                $nama_guru = trim((string)$gstmt->fetchColumn());
                if ($nama_guru === '') {
                    $message = ['type' => 'warning', 'text' => 'Guru tidak ditemukan.'];
                } else {
                    $dup = $pdo->prepare("SELECT COUNT(*) FROM {$table_name} WHERE id_guru = ? AND id_pembina_pramuka != ?");
                    $dup->execute([$id_guru, $id]);
                    if ((int)$dup->fetchColumn() > 0) {
                        $message = ['type' => 'warning', 'text' => 'Guru tersebut sudah terdaftar sebagai pembina Pramuka lain.'];
                    } else {
                        $stmt = $pdo->prepare("UPDATE {$table_name} SET id_guru = ?, nama_pembina = ?, jabatan = ? WHERE id_pembina_pramuka = ?");
                        $ok = $stmt->execute([$id_guru, $nama_guru, $jabatan, $id]);
                        if ($ok) {
                            $username = $_SESSION['username'] ?? 'system';
                            logActivity($pdo, $username, 'Update Pembina Pramuka', "ID {$id}: {$nama_guru} - {$jabatan}");
                            $message = ['type' => 'success', 'text' => 'Data pembina pramuka berhasil diperbarui!'];
                        } else {
                            $message = ['type' => 'danger', 'text' => 'Gagal memperbarui data.'];
                        }
                    }
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Error DB: ' . $e->getMessage()];
            }
        }
    }

    // Delete
    if (isset($_POST['delete_pembina_pramuka'])) {
        $id = (int)($_POST['id_pembina_pramuka'] ?? 0);
        if ($id <= 0) {
            $message = ['type' => 'warning', 'text' => 'ID tidak valid.'];
        } else {
            try {
                $nameStmt = $pdo->prepare("SELECT nama_pembina FROM {$table_name} WHERE id_pembina_pramuka = ?");
                $nameStmt->execute([$id]);
                $nama = (string)($nameStmt->fetchColumn() ?: '-');

                $stmt = $pdo->prepare("DELETE FROM {$table_name} WHERE id_pembina_pramuka = ?");
                $ok = $stmt->execute([$id]);
                if ($ok) {
                    $username = $_SESSION['username'] ?? 'system';
                    logActivity($pdo, $username, 'Hapus Pembina Pramuka', "ID {$id}: {$nama}");
                    $message = ['type' => 'success', 'text' => 'Data pembina pramuka berhasil dihapus!'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal menghapus data.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Error DB: ' . $e->getMessage()];
            }
        }
    }
    }
}

// Daftar guru + id yang sudah dipakai pembina (satu guru satu baris)
$guru_list = [];
$used_guru_ids = [];
try {
    $guru_list = $pdo->query('SELECT id_guru, nama_guru FROM tb_guru ORDER BY nama_guru ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    /* ignore */
}
try {
    $used_guru_ids = $pdo->query("SELECT id_guru FROM {$table_name} WHERE id_guru IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    $used_guru_ids = array_map('intval', (array)$used_guru_ids);
} catch (Exception $e) {
    $used_guru_ids = [];
}

// Fetch list
$rows = [];
$fetch_error = null;
try {
    $stmt = $pdo->query("
        SELECT p.id_pembina_pramuka, p.id_guru, p.nama_pembina, p.jabatan, g.nama_guru
        FROM {$table_name} p
        LEFT JOIN tb_guru g ON g.id_guru = p.id_guru
        ORDER BY COALESCE(g.nama_guru, p.nama_pembina) ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $fetch_error = $e->getMessage();
}

$jabatan_select_opts = $jabatan_options;
foreach ($rows as $r) {
    $j = trim((string)($r['jabatan'] ?? ''));
    if ($j !== '' && !isset($jabatan_select_opts[$j])) {
        $jabatan_select_opts[$j] = $j;
    }
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

$js_page[] = <<<'JS_BLOCK'
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

    // Fill edit modal
    $(document).on('click', '.edit-btn', function() {
        $('#edit_id_pembina_pramuka').val($(this).data('id'));
        var idGuru = $(this).data('id-guru') || $(this).data('idGuru');
        if (idGuru) {
            $('#edit_id_guru').val(String(idGuru));
        }
        $('#edit_jabatan').val($(this).data('jabatan') || '');
        $('#editModal').modal('show');
    });

    // Delete confirmation
    $(document).on('click', '.delete-btn', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var nama = $(this).data('nama') || '-';
        Swal.fire({
            title: 'Konfirmasi Hapus',
            text: 'Apakah Anda yakin ingin menghapus "' + nama + '"?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                var form = $('<form method="POST" action="">' +
                    '<input type="hidden" name="id_pembina_pramuka" value="' + id + '">' +
                    '<input type="hidden" name="delete_pembina_pramuka" value="1">' +
                    '</form>');
                $('body').append(form);
                form.submit();
            }
        });
    });
});

function exportToExcel() {
    var table = document.getElementById('table-1');
    if (!table) return;
    
    var schoolName = $('#schoolName').val() || 'MADRASAH';
    var academicYear = $('#academicYear').val() || '-';
    
    // Clone table to remove actions column
    var newTable = table.cloneNode(true);
    var rows = newTable.rows;
    <?php if ($can_manage_pembina_pramuka): ?>
    for (var i = 0; i < rows.length; i++) {
        rows[i].deleteCell(-1); // Remove last column (Aksi)
    }
    <?php endif; ?>
    
    if (typeof XLSX !== 'undefined') {
        var wb = XLSX.utils.book_new();
        
        var headerAOA = [
            [schoolName.toUpperCase()],
            ["DATA PEMBINA PRAMUKA"],
            ["TAHUN AJARAN: " + academicYear],
            []
        ];
        var finalWS = XLSX.utils.aoa_to_sheet(headerAOA);
        XLSX.utils.sheet_add_dom(finalWS, newTable, { origin: -1 });
        
        XLSX.utils.book_append_sheet(wb, finalWS, "Data Pembina Pramuka");
        XLSX.writeFile(wb, 'data_pembina_pramuka_' + academicYear.replace(/\//g, '-') + '.xlsx');
    } else {
        var html = newTable.outerHTML;
        var a = document.createElement('a');
        a.href = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
        a.download = 'data_pembina_pramuka.xls';
        a.click();
    }
}

function exportToPDF() {
    var table = document.getElementById('table-1');
    if (!table) return;
    
    var schoolName = $('#schoolName').val() || 'MADRASAH';
    var schoolLogo = $('#schoolLogo').val() || '';
    var academicYear = $('#academicYear').val() || '-';
    var headName = $('#headName').val() || '-';
    var headNip = $('#headNip').val() || '-';
    var printPlace = $('#printPlace').val() || 'Padang';
    var printDate = $('#printDate').val() || '';
    
    // Generate QR Code content
    var qrContent = "Dokumen Sah: " + schoolName + "\nKepala Madrasah: " + headName + "\nNIP: " + headNip;
    var qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=" + encodeURIComponent(qrContent);
    
    // Create a new window for printing
    var printWindow = window.open('', '_blank');
    printWindow.document.write('<html><head><title>Data Pembina Pramuka ' + academicYear + '</title>');
    printWindow.document.write('<style>');
    printWindow.document.write('table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }');
    printWindow.document.write('th, td { border: 1px solid #000; padding: 8px; text-align: left; }');
    printWindow.document.write('th { background-color: #f2f2f2; }');
    printWindow.document.write('h2, h3 { text-align: center; margin: 2px 0; }');
    printWindow.document.write('.header-container { display: flex; align-items: center; justify-content: center; margin-bottom: 20px; position: relative; }');
    printWindow.document.write('.logo { position: absolute; left: 0; top: 0; height: 70px; }');
    printWindow.document.write('.header-text { text-align: center; width: 100%; }');
    printWindow.document.write('.signature-wrapper { margin-top: 30px; display: flex; justify-content: flex-end; }');
    printWindow.document.write('.signature-box { width: 250px; text-align: left; }');
    printWindow.document.write('.signature-space { padding: 10px 0; }');
    printWindow.document.write('.qr-code { height: 80px; width: 80px; object-fit: contain; }');
    printWindow.document.write('.no-print { display: none; }');
    printWindow.document.write('</style></head><body>');
    
    printWindow.document.write('<div class="header-container">');
    if (schoolLogo) {
        printWindow.document.write('<img src="' + schoolLogo + '" class="logo">');
    }
    printWindow.document.write('<div class="header-text">');
    printWindow.document.write('<h2>' + schoolName.toUpperCase() + '</h2>');
    printWindow.document.write('<h3>DATA PEMBINA PRAMUKA</h3>');
    printWindow.document.write('<h3>TAHUN AJARAN: ' + academicYear + '</h3>');
    printWindow.document.write('</div>');
    printWindow.document.write('</div>');
    printWindow.document.write('<hr style="border: 1px solid #000; margin-bottom: 20px;">');
    
    // Clone and clean up table
    var cleanTable = table.cloneNode(true);
    var rows = cleanTable.rows;
    <?php if ($can_manage_pembina_pramuka): ?>
    for (var i = 0; i < rows.length; i++) {
        rows[i].deleteCell(-1); // Remove action column
    }
    <?php endif; ?>
    
    printWindow.document.write(cleanTable.outerHTML);
    
    // Add signature section
    printWindow.document.write('<div class="signature-wrapper">');
    printWindow.document.write('<div class="signature-box">');
    printWindow.document.write('<p>' + printPlace + ', ' + printDate + '</p>');
    printWindow.document.write('<p>Kepala Madrasah,</p>');
    printWindow.document.write('<div class="signature-space">');
    printWindow.document.write('<img src="' + qrUrl + '" class="qr-code">');
    printWindow.document.write('</div>');
    printWindow.document.write('<p style="margin-bottom: 0;"><strong>' + headName + '</strong></p>');
    printWindow.document.write('<p style="margin-top: 0;">NIP. ' + headNip + '</p>');
    printWindow.document.write('</div>');
    printWindow.document.write('</div>');
    
    printWindow.document.write('<script>window.onload = function() { setTimeout(function() { window.print(); window.close(); }, 500); }<\/script>');
    printWindow.document.write('</body></html>');
    printWindow.document.close();
}
JS_BLOCK;

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Data Pembina Pramuka</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Ekstrakurikuler</div>
                <div class="breadcrumb-item">Pembina Pramuka</div>
            </div>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4>Data Pembina Pramuka</h4>
                    <div class="card-header-action">
                        <button type="button" class="btn btn-success" onclick="exportToExcel()">
                            <i class="fas fa-file-excel"></i> Excel
                        </button>
                        <button type="button" class="btn btn-warning" onclick="exportToPDF()">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                        <?php if ($can_manage_pembina_pramuka): ?>
                        <button class="btn btn-primary" data-toggle="modal" data-target="#addModal" type="button">
                            <i class="fas fa-plus"></i> Tambah
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-body">
                    <input type="hidden" id="schoolName" value="<?= htmlspecialchars($school_profile['nama_madrasah'] ?? 'MADRASAH') ?>">
                    <input type="hidden" id="schoolLogo" value="<?= !empty($school_profile['logo']) ? '../assets/img/' . $school_profile['logo'] : '' ?>">
                    <input type="hidden" id="academicYear" value="<?= htmlspecialchars($school_profile['tahun_ajaran'] ?? '-') ?>">
                    <input type="hidden" id="headName" value="<?= htmlspecialchars($school_profile['nama_kepala'] ?? '-') ?>">
                    <input type="hidden" id="headNip" value="<?= htmlspecialchars($school_profile['nip_kepala'] ?? '-') ?>">
                    <input type="hidden" id="printPlace" value="<?= htmlspecialchars($school_profile['tempat_jadwal'] ?? 'Padang') ?>">
                    <input type="hidden" id="printDate" value="<?= date('d F Y') ?>">
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
                                    <th class="text-center" width="6%">No</th>
                                    <th>Nama Pembina</th>
                                    <th>Jabatan</th>
                                    <?php if ($can_manage_pembina_pramuka): ?><th width="15%">Aksi</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($rows)): ?>
                                    <?php foreach ($rows as $idx => $row): ?>
                                        <?php
                                            $nama_tampil = trim((string)($row['nama_guru'] ?? '')) !== ''
                                                ? (string)$row['nama_guru']
                                                : (string)($row['nama_pembina'] ?? '');
                                        ?>
                                        <tr>
                                            <td class="text-center"><?= (int)($idx + 1) ?></td>
                                            <td><?= htmlspecialchars($nama_tampil) ?></td>
                                            <td><?= htmlspecialchars($row['jabatan'] ?? '') ?></td>
                                            <?php if ($can_manage_pembina_pramuka): ?><td>
                                                <button class="btn btn-warning btn-sm edit-btn"
                                                    data-id="<?= (int)($row['id_pembina_pramuka'] ?? 0) ?>"
                                                    data-id-guru="<?= (int)($row['id_guru'] ?? 0) ?>"
                                                    data-nama="<?= htmlspecialchars($nama_tampil, ENT_QUOTES) ?>"
                                                    data-jabatan="<?= htmlspecialchars($row['jabatan'] ?? '', ENT_QUOTES) ?>"
                                                    type="button">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-danger btn-sm delete-btn"
                                                    data-id="<?= (int)($row['id_pembina_pramuka'] ?? 0) ?>"
                                                    data-nama="<?= htmlspecialchars($nama_tampil, ENT_QUOTES) ?>"
                                                    type="button">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td><?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="<?= $can_manage_pembina_pramuka ? '4' : '3' ?>" class="text-center text-muted">Tidak ada data.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php if ($can_manage_pembina_pramuka): ?>
<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-labelledby="addModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addModalLabel">Tambah Pembina Pramuka</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="add_pembina_pramuka" value="1">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nama Pembina (dari data guru)</label>
                        <select name="id_guru" class="form-control" required>
                            <option value="">-- Pilih Guru --</option>
                            <?php foreach ($guru_list as $g): ?>
                                <?php if (in_array((int)$g['id_guru'], $used_guru_ids, true)) {
                                    continue;
                                } ?>
                                <option value="<?= (int)$g['id_guru'] ?>"><?= htmlspecialchars($g['nama_guru']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Jabatan</label>
                        <select name="jabatan" class="form-control" required>
                            <option value="">-- Pilih Jabatan --</option>
                            <?php foreach ($jabatan_options as $val => $label): ?>
                                <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
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
<?php endif; ?>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editModalLabel">Edit Pembina Pramuka</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="update_pembina_pramuka" value="1">
                <input type="hidden" name="id_pembina_pramuka" id="edit_id_pembina_pramuka" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nama Pembina (dari data guru)</label>
                        <select name="id_guru" id="edit_id_guru" class="form-control" required>
                            <option value="">-- Pilih Guru --</option>
                            <?php foreach ($guru_list as $g): ?>
                                <option value="<?= (int)$g['id_guru'] ?>"><?= htmlspecialchars($g['nama_guru']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Jabatan</label>
                        <select name="jabatan" id="edit_jabatan" class="form-control" required>
                            <option value="">-- Pilih Jabatan --</option>
                            <?php foreach ($jabatan_select_opts as $val => $label): ?>
                                <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
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

