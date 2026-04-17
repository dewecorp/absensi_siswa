<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

$page_title = 'Data Pembina Pramuka';

// DataTables
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
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
                nama_pembina VARCHAR(100) NOT NULL,
                jabatan VARCHAR(100) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } else {
        $required_cols = [
            'id_pembina_pramuka' => "INT NOT NULL",
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
    }
} catch (Exception $e) {
    $schema_error = $e->getMessage();
}

// --- CRUD handling ---
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add
    if (isset($_POST['add_pembina_pramuka'])) {
        $nama_pembina = sanitizeInput($_POST['nama_pembina'] ?? '');
        $jabatan = sanitizeInput($_POST['jabatan'] ?? '');

        if ($nama_pembina === '' || $jabatan === '') {
            $message = ['type' => 'warning', 'text' => 'Harap lengkapi nama pembina dan jabatan.'];
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO {$table_name} (nama_pembina, jabatan) VALUES (?, ?)");
                $ok = $stmt->execute([$nama_pembina, $jabatan]);
                if ($ok) {
                    $username = $_SESSION['username'] ?? 'system';
                    logActivity($pdo, $username, 'Tambah Pembina Pramuka', "{$nama_pembina} - {$jabatan}");
                    $message = ['type' => 'success', 'text' => 'Data pembina pramuka berhasil ditambahkan!'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal menambahkan data pembina pramuka.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Error DB: ' . $e->getMessage()];
            }
        }
    }

    // Update
    if (isset($_POST['update_pembina_pramuka'])) {
        $id = (int)($_POST['id_pembina_pramuka'] ?? 0);
        $nama_pembina = sanitizeInput($_POST['nama_pembina'] ?? '');
        $jabatan = sanitizeInput($_POST['jabatan'] ?? '');

        if ($id <= 0 || $nama_pembina === '' || $jabatan === '') {
            $message = ['type' => 'warning', 'text' => 'Harap lengkapi nama pembina dan jabatan.'];
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE {$table_name} SET nama_pembina = ?, jabatan = ? WHERE id_pembina_pramuka = ?");
                $ok = $stmt->execute([$nama_pembina, $jabatan, $id]);
                if ($ok) {
                    $username = $_SESSION['username'] ?? 'system';
                    logActivity($pdo, $username, 'Update Pembina Pramuka', "ID {$id}: {$nama_pembina} - {$jabatan}");
                    $message = ['type' => 'success', 'text' => 'Data pembina pramuka berhasil diperbarui!'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal memperbarui data.'];
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

// Fetch list
$rows = [];
$fetch_error = null;
try {
    $stmt = $pdo->query("SELECT id_pembina_pramuka, nama_pembina, jabatan FROM {$table_name} ORDER BY nama_pembina ASC");
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

    // Fill edit modal
    $(document).on('click', '.edit-btn', function() {
        $('#edit_id_pembina_pramuka').val($(this).data('id'));
        $('#edit_nama_pembina').val($(this).data('nama') || '');
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
                    '<input type=\"hidden\" name=\"id_pembina_pramuka\" value=\"' + id + '\">' +
                    '<input type=\"hidden\" name=\"delete_pembina_pramuka\" value=\"1\">' +
                    '</form>');
                $('body').append(form);
                form.submit();
            }
        });
    });
});
";

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
                        <button class="btn btn-primary" data-toggle="modal" data-target="#addModal" type="button">
                            <i class="fas fa-plus"></i> Tambah
                        </button>
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
                                    <th class="text-center" width="6%">No</th>
                                    <th>Nama Pembina</th>
                                    <th>Jabatan</th>
                                    <th width="15%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($rows)): ?>
                                    <?php foreach ($rows as $idx => $row): ?>
                                        <tr>
                                            <td class="text-center"><?= (int)($idx + 1) ?></td>
                                            <td><?= htmlspecialchars($row['nama_pembina'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['jabatan'] ?? '') ?></td>
                                            <td>
                                                <button class="btn btn-warning btn-sm edit-btn"
                                                    data-id="<?= (int)($row['id_pembina_pramuka'] ?? 0) ?>"
                                                    data-nama="<?= htmlspecialchars($row['nama_pembina'] ?? '', ENT_QUOTES) ?>"
                                                    data-jabatan="<?= htmlspecialchars($row['jabatan'] ?? '', ENT_QUOTES) ?>"
                                                    type="button">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-danger btn-sm delete-btn"
                                                    data-id="<?= (int)($row['id_pembina_pramuka'] ?? 0) ?>"
                                                    data-nama="<?= htmlspecialchars($row['nama_pembina'] ?? '', ENT_QUOTES) ?>"
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
                <h5 class="modal-title" id="addModalLabel">Tambah Pembina Pramuka</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="add_pembina_pramuka" value="1">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nama Pembina</label>
                        <input type="text" class="form-control" name="nama_pembina" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Jabatan</label>
                        <input type="text" class="form-control" name="jabatan" required autocomplete="off">
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
                        <label>Nama Pembina</label>
                        <input type="text" class="form-control" name="nama_pembina" id="edit_nama_pembina" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Jabatan</label>
                        <input type="text" class="form-control" name="jabatan" id="edit_jabatan" required autocomplete="off">
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

