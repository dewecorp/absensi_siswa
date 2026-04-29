<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Ensure session is started for activity logging
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$can_manage_pembina_ekstra = isAuthorized(['admin', 'tata_usaha']);
$can_view_pembina_ekstra = $can_manage_pembina_ekstra || isAuthorized(['kepala_madrasah', 'wali', 'guru']);
if (!$can_view_pembina_ekstra) {
    redirect('../login.php');
}

$school_profile = getSchoolProfile($pdo);
$page_title = 'Data Pembina Ekstrakurikuler';

// DataTables
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
];

// --- Ensure best-effort schema (create if missing) ---
$schema_error = null;

try {
    // tb_pembina
    $stmt = $pdo->query("SHOW TABLES LIKE 'tb_pembina'");
    $exists = (bool)$stmt->fetch(PDO::FETCH_NUM);
    if (!$exists) {
        $pdo->exec("
            CREATE TABLE tb_pembina (
                id_pembina INT AUTO_INCREMENT PRIMARY KEY,
                nama_pembina VARCHAR(100) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // tb_ekstrakurikuler
    $stmt = $pdo->query("SHOW TABLES LIKE 'tb_ekstrakurikuler'");
    $exists = (bool)$stmt->fetch(PDO::FETCH_NUM);
    if (!$exists) {
        $pdo->exec("
            CREATE TABLE tb_ekstrakurikuler (
                id_ekstrakurikuler INT AUTO_INCREMENT PRIMARY KEY,
                nama_ekstrakurikuler VARCHAR(100) NOT NULL,
                hari VARCHAR(30) NOT NULL,
                waktu TIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // tb_pembina_ekstrakurikuler (mapping)
    $stmt = $pdo->query("SHOW TABLES LIKE 'tb_pembina_ekstrakurikuler'");
    $exists = (bool)$stmt->fetch(PDO::FETCH_NUM);
    if (!$exists) {
        $pdo->exec("
            CREATE TABLE tb_pembina_ekstrakurikuler (
                id_pembina_ekstrakurikuler INT AUTO_INCREMENT PRIMARY KEY,
                id_pembina INT NOT NULL,
                id_ekstrakurikuler INT NOT NULL,
                UNIQUE KEY uniq_pembina_ekstrakurikuler (id_pembina, id_ekstrakurikuler),
                INDEX idx_pembina (id_pembina),
                INDEX idx_ekstrakurikuler (id_ekstrakurikuler)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
} catch (Exception $e) {
    $schema_error = $e->getMessage();
}

// --- Fetch dropdown lists ---
$pembina_list = [];
$ekstrakurikuler_list = [];
$fetch_error = null;

try {
    $pembina_list = $pdo->query("SELECT id_pembina, nama_pembina FROM tb_pembina ORDER BY nama_pembina ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $fetch_error = 'Gagal mengambil data pembina.';
}

try {
    $ekstrakurikuler_list = $pdo->query("SELECT id_ekstrakurikuler, nama_ekstrakurikuler FROM tb_ekstrakurikuler ORDER BY nama_ekstrakurikuler ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $fetch_error = $fetch_error ?: 'Gagal mengambil data ekstrakurikuler.';
}

// --- CRUD handling ---
$message = null;

function ensureId($value): int {
    return (int)($value ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_manage_pembina_ekstra) {
        $message = ['type' => 'warning', 'text' => 'Mode baca saja. CRUD tidak diizinkan untuk level Anda.'];
    } else {
    // Add mapping
    if (isset($_POST['add_pembina_ekstrakurikuler'])) {
        $id_pembina = ensureId($_POST['id_pembina'] ?? 0);
        $id_ekstrakurikuler = ensureId($_POST['id_ekstrakurikuler'] ?? 0);
        $nama_pembina = trim((string)($_POST['nama_pembina'] ?? ''));

        // Add flow: user provides pembina name; we reuse existing or create new.
        if ($nama_pembina !== '') {
            try {
                // Try find existing (case-insensitive best-effort)
                $find = $pdo->prepare("SELECT id_pembina FROM tb_pembina WHERE LOWER(nama_pembina) = LOWER(?) LIMIT 1");
                $find->execute([$nama_pembina]);
                $existingId = (int)($find->fetchColumn() ?: 0);
                if ($existingId > 0) {
                    $id_pembina = $existingId;
                } else {
                    $ins = $pdo->prepare("INSERT INTO tb_pembina (nama_pembina) VALUES (?)");
                    $ins->execute([$nama_pembina]);
                    $id_pembina = (int)$pdo->lastInsertId();
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menambahkan pembina baru: ' . $e->getMessage()];
            }
        }

        if (!$message && $id_pembina > 0 && $id_ekstrakurikuler > 0) {
            try {
                $stmt = $pdo->prepare("INSERT INTO tb_pembina_ekstrakurikuler (id_pembina, id_ekstrakurikuler) VALUES (?, ?)");
                $ok = $stmt->execute([$id_pembina, $id_ekstrakurikuler]);

                if ($ok) {
                    $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                    logActivity($pdo, $username, 'Tambah Pembina Ekstrakurikuler', "Pembina ID {$id_pembina} -> Ekstrakurikuler ID {$id_ekstrakurikuler}");
                    $message = ['type' => 'success', 'text' => 'Data pembina ekstrakurikuler berhasil ditambahkan!'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal menambahkan data pembina ekstrakurikuler.'];
                }
            } catch (Exception $e) {
                // Unique constraint or other DB issues
                $message = ['type' => 'danger', 'text' => 'Gagal menambahkan: ' . $e->getMessage()];
            }
        } else {
            if (!$message) {
                $message = ['type' => 'warning', 'text' => 'Harap isi nama pembina dan pilih ekstrakurikuler.'];
            }
        }
    }

    // Update mapping
    if (isset($_POST['update_pembina_ekstrakurikuler'])) {
        $id_map = ensureId($_POST['id_pembina_ekstrakurikuler'] ?? 0);
        $id_pembina = ensureId($_POST['edit_id_pembina'] ?? 0);
        $id_ekstrakurikuler = ensureId($_POST['edit_id_ekstrakurikuler'] ?? 0);
        $edit_nama_pembina = trim((string)($_POST['edit_nama_pembina'] ?? ''));

        if ($id_map > 0 && $id_pembina > 0 && $id_ekstrakurikuler > 0 && $edit_nama_pembina !== '') {
            try {
                $pdo->beginTransaction();

                // Update pembina name (fix typo) then update mapping
                $stmtNama = $pdo->prepare("UPDATE tb_pembina SET nama_pembina = ? WHERE id_pembina = ?");
                $stmtNama->execute([$edit_nama_pembina, $id_pembina]);

                $stmt = $pdo->prepare("UPDATE tb_pembina_ekstrakurikuler SET id_ekstrakurikuler = ? WHERE id_pembina_ekstrakurikuler = ? AND id_pembina = ?");
                $ok = $stmt->execute([$id_ekstrakurikuler, $id_map, $id_pembina]);

                $pdo->commit();

                if ($ok) {
                    $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                    logActivity($pdo, $username, 'Update Pembina Ekstrakurikuler', "Map ID {$id_map}: Pembina {$id_pembina} ({$edit_nama_pembina}) -> Ekstrakurikuler {$id_ekstrakurikuler}");
                    $message = ['type' => 'success', 'text' => 'Data pembina ekstrakurikuler berhasil diperbarui!'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal memperbarui data.'];
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $message = ['type' => 'danger', 'text' => 'Gagal memperbarui: ' . $e->getMessage()];
            }
        } else {
            $message = ['type' => 'warning', 'text' => 'Harap lengkapi input edit (nama pembina dan ekstrakurikuler).'];
        }
    }

    // Delete mapping
    if (isset($_POST['delete_pembina_ekstrakurikuler'])) {
        $id_map = ensureId($_POST['id_pembina_ekstrakurikuler'] ?? 0);
        if ($id_map > 0) {
            try {
                // Log name for better audit
                $nameStmt = $pdo->prepare("
                    SELECT pb.nama_pembina, e.nama_ekstrakurikuler
                    FROM tb_pembina_ekstrakurikuler m
                    JOIN tb_pembina pb ON pb.id_pembina = m.id_pembina
                    JOIN tb_ekstrakurikuler e ON e.id_ekstrakurikuler = m.id_ekstrakurikuler
                    WHERE m.id_pembina_ekstrakurikuler = ?
                ");
                $nameStmt->execute([$id_map]);
                $rowName = $nameStmt->fetch(PDO::FETCH_ASSOC);
                $nama_pembina = $rowName['nama_pembina'] ?? '-';
                $nama_ekstra = $rowName['nama_ekstrakurikuler'] ?? '-';

                $stmt = $pdo->prepare("DELETE FROM tb_pembina_ekstrakurikuler WHERE id_pembina_ekstrakurikuler = ?");
                $ok = $stmt->execute([$id_map]);

                if ($ok) {
                    $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                    logActivity($pdo, $username, 'Hapus Pembina Ekstrakurikuler', "Map ID {$id_map}: {$nama_pembina} -> {$nama_ekstra}");
                    $message = ['type' => 'success', 'text' => 'Data berhasil dihapus!'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal menghapus data.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
            }
        } else {
            $message = ['type' => 'warning', 'text' => 'ID tidak valid.'];
        }
    }
    }
}

// --- Fetch mapping list for table ---
$mapping_rows = [];
$table_error = null;
try {
    $stmt = $pdo->query("
        SELECT
            m.id_pembina_ekstrakurikuler,
            pb.nama_pembina,
            e.id_ekstrakurikuler,
            e.nama_ekstrakurikuler
        FROM tb_pembina_ekstrakurikuler m
        JOIN tb_pembina pb ON pb.id_pembina = m.id_pembina
        JOIN tb_ekstrakurikuler e ON e.id_ekstrakurikuler = m.id_ekstrakurikuler
        ORDER BY pb.nama_pembina ASC, e.nama_ekstrakurikuler ASC
    ");
    $mapping_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $table_error = $e->getMessage();
}

// --- Page-specific JS ---
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
    var hasEkstra = $('#hasEkstra').val() === 'true';

    // Add button behavior:
    // - Pembina boleh ditambah langsung dari halaman ini.
    // - Ekstrakurikuler tetap wajib ada (dipilih dari master).
    $('#btn-add-mapping').on('click', function(e) {
        e.preventDefault();
        if (!hasEkstra) {
            Swal.fire({
                icon: 'warning',
                title: 'Ekstrakurikuler belum ada',
                text: 'Silakan tambah data Ekstrakurikuler terlebih dahulu, lalu kembali ke halaman ini.',
                confirmButtonText: 'OK'
            });
            return;
        }
        $('#addModal').modal('show');
    });

    $('#table-1').DataTable({
        'order': [[0, 'asc']],
        'columnDefs': [
            <?php if ($can_manage_pembina_ekstra): ?>
            { 'sortable': false, 'targets': [3] } // Aksi
            <?php endif; ?>
        ],
        'language': {
            'lengthMenu': 'Tampilkan _MENU_ entri',
            'zeroRecords': 'Tidak ada data yang ditemukan',
            'emptyTable': 'Belum ada data.',
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

    // Fill edit modal
    $(document).on('click', '.edit-btn', function() {
        var id_map = $(this).data('id-map');
        var id_pembina = $(this).data('id-pembina');
        var id_ekstrakurikuler = $(this).data('id-ekstra');
        var nama_pembina = $(this).data('nama-pembina') || '';
        var nama_ekstra = $(this).data('nama-ekstra') || '';

        $('#edit_id_pembina_ekstrakurikuler').val(id_map);
        $('#edit_id_pembina').val(id_pembina);
        $('#edit_nama_pembina').val(nama_pembina);
        $('#edit_id_ekstrakurikuler').val(id_ekstrakurikuler);

        $('#editModalLabel').text('Edit Pembina Ekstrakurikuler');
        $('#editModal').modal('show');
    });

    // Delete confirmation
    $(document).on('click', '.delete-btn', function(e) {
        e.preventDefault();
        var id_map = $(this).data('id-map');
        var nama_pembina = $(this).data('nama-pembina') || '-';
        var nama_ekstra = $(this).data('nama-ekstra') || '-';

        Swal.fire({
            title: 'Konfirmasi Hapus',
            text: 'Apakah Anda yakin ingin menghapus ' + nama_pembina + ' untuk ' + nama_ekstra + '?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                var form = $('<form method="POST" action="">' +
                    '<input type="hidden" name="id_pembina_ekstrakurikuler" value="' + id_map + '">' +
                    '<input type="hidden" name="delete_pembina_ekstrakurikuler" value="1">' +
                    '</form>');
                $('body').append(form);
                form.submit();
            }
        });
    });

    // Row numbers (safe even when table is empty)
    var dt = $('#table-1').DataTable();
    dt.on('order.dt search.dt draw.dt', function() {
        var info = dt.page.info();
        var start = info.page * info.length;
        dt.column(0, { search: 'applied', order: 'applied' }).nodes().each(function(cell, i) {
            $(cell).text(start + i + 1);
        });
    }).draw();
});

function exportToExcel() {
    var table = document.getElementById('table-1');
    if (!table) return;
    
    var schoolName = $('#schoolName').val() || 'MADRASAH';
    var academicYear = $('#academicYear').val() || '-';
    
    // Clone table to remove actions column
    var newTable = table.cloneNode(true);
    var rows = newTable.rows;
    <?php if ($can_manage_pembina_ekstra): ?>
    for (var i = 0; i < rows.length; i++) {
        rows[i].deleteCell(-1); // Remove last column (Aksi)
    }
    <?php endif; ?>
    
    if (typeof XLSX !== 'undefined') {
        var wb = XLSX.utils.book_new();
        var ws = XLSX.utils.table_to_sheet(newTable);
        
        // Add header rows
        XLSX.utils.sheet_add_aoa(ws, [
            [schoolName],
            ["Data Pembina Ekstrakurikuler"],
            ["Tahun Ajaran: " + academicYear],
            []
        ], { origin: "A1" });
        
        // Adjust data position
        var range = XLSX.utils.decode_range(ws['!ref']);
        // If we add 4 rows, we need to shift existing data
        // Actually XLSX.utils.table_to_sheet starts at A1. 
        // Let's use a different approach: build AOA then append table
        
        var headerAOA = [
            [schoolName.toUpperCase()],
            ["DATA PEMBINA EKSTRAKURIKULER"],
            ["TAHUN AJARAN: " + academicYear],
            []
        ];
        var finalWS = XLSX.utils.aoa_to_sheet(headerAOA);
        XLSX.utils.sheet_add_dom(finalWS, newTable, { origin: -1 });
        
        XLSX.utils.book_append_sheet(wb, finalWS, "Data Pembina Ekstra");
        XLSX.writeFile(wb, 'data_pembina_ekstrakurikuler_' + academicYear.replace(/\//g, '-') + '.xlsx');
    } else {
        var html = newTable.outerHTML;
        var a = document.createElement('a');
        a.href = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
        a.download = 'data_pembina_ekstrakurikuler.xls';
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
    printWindow.document.write('<html><head><title>Data Pembina Ekstrakurikuler ' + academicYear + '</title>');
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
    printWindow.document.write('<h3>DATA PEMBINA EKSTRAKURIKULER</h3>');
    printWindow.document.write('<h3>TAHUN AJARAN: ' + academicYear + '</h3>');
    printWindow.document.write('</div>');
    printWindow.document.write('</div>');
    printWindow.document.write('<hr style="border: 1px solid #000; margin-bottom: 20px;">');
    
    // Clone and clean up table
    var cleanTable = table.cloneNode(true);
    var rows = cleanTable.rows;
    <?php if ($can_manage_pembina_ekstra): ?>
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
            <h1>Data Pembina Ekstrakurikuler</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Master Data</div>
                <div class="breadcrumb-item">Pembina Ekstrakurikuler</div>
            </div>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4>Data Pembina Ekstrakurikuler</h4>
                    <div class="card-header-action">
                        <button type="button" class="btn btn-success" onclick="exportToExcel()">
                            <i class="fas fa-file-excel"></i> Excel
                        </button>
                        <button type="button" class="btn btn-warning" onclick="exportToPDF()">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                        <?php if ($can_manage_pembina_ekstra): ?>
                        <button class="btn btn-primary" id="btn-add-mapping" type="button">
                            <i class="fas fa-plus"></i> Tambah
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-body">
                    <input type="hidden" id="hasEkstra" value="<?= !empty($ekstrakurikuler_list) ? 'true' : 'false' ?>">
                    <input type="hidden" id="schoolName" value="<?= htmlspecialchars($school_profile['nama_madrasah'] ?? 'MADRASAH') ?>">
                    <input type="hidden" id="schoolLogo" value="<?= !empty($school_profile['logo']) ? '../assets/img/' . $school_profile['logo'] : '' ?>">
                    <input type="hidden" id="academicYear" value="<?= htmlspecialchars($school_profile['tahun_ajaran'] ?? '-') ?>">
                    <input type="hidden" id="headName" value="<?= htmlspecialchars($school_profile['nama_kepala'] ?? '-') ?>">
                    <input type="hidden" id="headNip" value="<?= htmlspecialchars($school_profile['nip_kepala'] ?? '-') ?>">
                    <input type="hidden" id="printPlace" value="<?= htmlspecialchars($school_profile['tempat_jadwal'] ?? 'Padang') ?>">
                    <input type="hidden" id="printDate" value="<?= date('d F Y') ?>">
                    <?php if (!empty($schema_error) || !empty($fetch_error) || !empty($table_error)): ?>
                        <div class="alert alert-danger">
                            <strong>Terjadi masalah.</strong><br>
                            <?php
                            if (!empty($schema_error)) echo htmlspecialchars($schema_error) . '<br>';
                            if (!empty($fetch_error)) echo htmlspecialchars($fetch_error) . '<br>';
                            if (!empty($table_error)) echo htmlspecialchars($table_error);
                            ?>
                        </div>
                    <?php endif; ?>

                    <div class="table-responsive">
                        <table class="table table-striped" id="table-1">
                            <thead>
                                <tr>
                                    <th class="text-center" width="6%">No</th>
                                    <th>Nama Pembina</th>
                                    <th>Pembina Ekstrakurikuler</th>
                                    <?php if ($can_manage_pembina_ekstra): ?><th width="15%">Aksi</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($mapping_rows)): ?>
                                    <?php foreach ($mapping_rows as $i => $row): ?>
                                        <tr>
                                            <td class="text-center"><?= (int)($i + 1) ?></td>
                                            <td><?= htmlspecialchars($row['nama_pembina'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['nama_ekstrakurikuler'] ?? '') ?></td>
                                            <?php if ($can_manage_pembina_ekstra): ?><td>
                                                <?php
                                                // We need id_pembina for edit; fetch it with a small query by mapping id.
                                                $id_map = (int)($row['id_pembina_ekstrakurikuler'] ?? 0);
                                                $id_pembina = null;
                                                try {
                                                    if ($id_map > 0) {
                                                        $st = $pdo->prepare("SELECT id_pembina FROM tb_pembina_ekstrakurikuler WHERE id_pembina_ekstrakurikuler = ?");
                                                        $st->execute([$id_map]);
                                                        $id_pembina = $st->fetchColumn();
                                                    }
                                                } catch (Exception $e) {
                                                    $id_pembina = null;
                                                }
                                                ?>
                                                <button
                                                    class="btn btn-warning btn-sm edit-btn"
                                                    data-id-map="<?= (int)($row['id_pembina_ekstrakurikuler'] ?? 0) ?>"
                                                    data-id-pembina="<?= (int)($id_pembina ?? 0) ?>"
                                                    data-id-ekstra="<?= (int)($row['id_ekstrakurikuler'] ?? 0) ?>"
                                                    data-nama-pembina="<?= htmlspecialchars($row['nama_pembina'] ?? '', ENT_QUOTES) ?>"
                                                    data-nama-ekstra="<?= htmlspecialchars($row['nama_ekstrakurikuler'] ?? '', ENT_QUOTES) ?>"
                                                    type="button">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button
                                                    class="btn btn-danger btn-sm delete-btn"
                                                    data-id-map="<?= (int)($row['id_pembina_ekstrakurikuler'] ?? 0) ?>"
                                                    data-nama-pembina="<?= htmlspecialchars($row['nama_pembina'] ?? '', ENT_QUOTES) ?>"
                                                    data-nama-ekstra="<?= htmlspecialchars($row['nama_ekstrakurikuler'] ?? '', ENT_QUOTES) ?>"
                                                    type="button">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td><?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="<?= $can_manage_pembina_ekstra ? '4' : '3' ?>" class="text-center text-muted">Tidak ada data.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php if ($can_manage_pembina_ekstra): ?>
<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-labelledby="addModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addModalLabel">Tambah Pembina Ekstrakurikuler</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="add_pembina_ekstrakurikuler" value="1">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nama Pembina</label>
                        <input type="text" class="form-control" name="nama_pembina" placeholder="Contoh: Bapak/Ibu Ahmad" required autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label>Pembina Ekstrakurikuler</label>
                        <select class="form-control" name="id_ekstrakurikuler" required>
                            <option value="">Pilih Ekstrakurikuler</option>
                            <?php foreach ($ekstrakurikuler_list as $e): ?>
                                <option value="<?= (int)($e['id_ekstrakurikuler'] ?? 0) ?>">
                                    <?= htmlspecialchars($e['nama_ekstrakurikuler'] ?? '') ?>
                                </option>
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
                <h5 class="modal-title" id="editModalLabel">Edit Pembina Ekstrakurikuler</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="update_pembina_ekstrakurikuler" value="1">
                <input type="hidden" name="id_pembina_ekstrakurikuler" id="edit_id_pembina_ekstrakurikuler" value="">
                <input type="hidden" name="edit_id_pembina" id="edit_id_pembina" value="">

                <div class="modal-body">
                    <div class="form-group">
                        <label>Nama Pembina</label>
                        <input type="text" class="form-control" name="edit_nama_pembina" id="edit_nama_pembina" value="" required autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label>Pembina Ekstrakurikuler</label>
                        <select class="form-control" name="edit_id_ekstrakurikuler" id="edit_id_ekstrakurikuler" required>
                            <option value="">Pilih Ekstrakurikuler</option>
                            <?php foreach ($ekstrakurikuler_list as $e): ?>
                                <option value="<?= (int)($e['id_ekstrakurikuler'] ?? 0) ?>">
                                    <?= htmlspecialchars($e['nama_ekstrakurikuler'] ?? '') ?>
                                </option>
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

