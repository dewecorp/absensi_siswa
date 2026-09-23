<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/agenda.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isAuthorized(['kepala_madrasah', 'admin', 'tata_usaha'])) {
    redirect('../login.php');
}

ensureAgendaTables($pdo);

$page_title = 'Data Agenda';
$current_page = basename(__FILE__);

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = (string)($_POST['aksi'] ?? '');
    try {
        if ($aksi === 'tambah') {
            $jenis = sanitizeInput((string)($_POST['jenis_agenda'] ?? ''));
            if ($jenis === '') {
                $message = ['type' => 'danger', 'text' => 'Jenis Agenda tidak boleh kosong.'];
            } else {
                $chk = $pdo->prepare("SELECT id_jenis FROM tb_agenda_jenis WHERE LOWER(jenis_agenda) = LOWER(?) LIMIT 1");
                $chk->execute([$jenis]);
                if ($chk->fetch()) {
                    $message = ['type' => 'danger', 'text' => 'Jenis Agenda sudah ada.'];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO tb_agenda_jenis (jenis_agenda) VALUES (?)");
                    if ($stmt->execute([$jenis])) {
                        $message = ['type' => 'success', 'text' => 'Jenis Agenda berhasil ditambahkan.'];
                    } else {
                        $message = ['type' => 'danger', 'text' => 'Gagal menambahkan Jenis Agenda.'];
                    }
                }
            }
        } elseif ($aksi === 'edit') {
            $id = (int)($_POST['id_jenis'] ?? 0);
            $jenis = sanitizeInput((string)($_POST['jenis_agenda'] ?? ''));
            if ($id <= 0 || $jenis === '') {
                $message = ['type' => 'danger', 'text' => 'Data tidak valid.'];
            } else {
                $chk = $pdo->prepare("SELECT id_jenis FROM tb_agenda_jenis WHERE LOWER(jenis_agenda) = LOWER(?) AND id_jenis != ? LIMIT 1");
                $chk->execute([$jenis, $id]);
                if ($chk->fetch()) {
                    $message = ['type' => 'danger', 'text' => 'Jenis Agenda sudah ada.'];
                } else {
                    $stmt = $pdo->prepare("UPDATE tb_agenda_jenis SET jenis_agenda = ? WHERE id_jenis = ?");
                    if ($stmt->execute([$jenis, $id])) {
                        $message = ['type' => 'success', 'text' => 'Jenis Agenda berhasil diperbarui.'];
                    } else {
                        $message = ['type' => 'danger', 'text' => 'Gagal memperbarui Jenis Agenda.'];
                    }
                }
            }
        } elseif ($aksi === 'hapus') {
            $id = (int)($_POST['id_jenis'] ?? 0);
            if ($id <= 0) {
                $message = ['type' => 'danger', 'text' => 'ID tidak valid.'];
            } else {
                $stmt = $pdo->prepare("DELETE FROM tb_agenda_jenis WHERE id_jenis = ?");
                if ($stmt->execute([$id])) {
                    $message = ['type' => 'success', 'text' => 'Jenis Agenda berhasil dihapus.'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal menghapus Jenis Agenda.'];
                }
            }
        }
    } catch (Throwable $e) {
        $message = ['type' => 'danger', 'text' => 'Terjadi kesalahan: ' . $e->getMessage()];
    }
}

$rows = [];
try {
    $rows = $pdo->query("SELECT * FROM tb_agenda_jenis ORDER BY jenis_agenda ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rows = [];
}

$css_libs = ['https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [];
if ($message) {
    $swal_icon = ($message['type'] ?? '') === 'success' ? 'success' : 'error';
    $swal_title = ($message['type'] ?? '') === 'success' ? 'Berhasil' : 'Gagal';
    $swal_text = json_encode((string)($message['text'] ?? ''), JSON_UNESCAPED_UNICODE);
    $js_page[] = "Swal.fire({icon:'{$swal_icon}',title:'{$swal_title}',text:{$swal_text},timer:2500,showConfirmButton:false});";
}

$js_page[] = <<<'JS'
$(document).ready(function () {
    var table = $('#table-jenis-agenda').DataTable({
        language: {
            search: 'Cari:',
            lengthMenu: 'Tampilkan _MENU_ data',
            info: 'Menampilkan _START_ sampai _END_ dari _TOTAL_ data',
            infoEmpty: 'Tidak ada data',
            zeroRecords: 'Data tidak ditemukan',
            paginate: { first: 'Awal', last: 'Akhir', next: 'Berikutnya', previous: 'Sebelumnya' }
        },
        pageLength: 10,
        order: [],
        columnDefs: [{ sortable: false, targets: [2] }]
    });

    table.on('order.dt search.dt draw.dt', function () {
        var info = table.page.info();
        table.column(0, { search: 'applied', order: 'applied', page: 'current' }).nodes().each(function (cell, i) {
            if (cell) cell.innerHTML = info.start + i + 1;
        });
    }).draw();

    $(document).on('click', '.btn-edit', function () {
        var id = $(this).data('id');
        var jenis = $(this).data('jenis');
        $('#edit_id_jenis').val(id);
        $('#edit_jenis_agenda').val(jenis);
        $('#modal-edit').modal('show');
    });

    $(document).on('click', '.btn-hapus', function () {
        var id = $(this).data('id');
        var jenis = $(this).data('jenis');
        Swal.fire({
            title: 'Konfirmasi Hapus',
            text: 'Hapus jenis agenda "' + jenis + '"? Data agenda terkait juga akan terhapus.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then(function (r) {
            if (r.isConfirmed) {
                var f = document.createElement('form');
                f.method = 'POST';
                f.action = 'data_agenda.php';
                var fields = { aksi: 'hapus', id_jenis: id };
                Object.keys(fields).forEach(function (k) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = k;
                    input.value = fields[k];
                    f.appendChild(input);
                });
                document.body.appendChild(f);
                f.submit();
            }
        });
    });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Data Agenda</h1>
            <?php echo render_breadcrumb(); ?>
        </div>
        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4>Daftar Jenis Agenda</h4>
                    <div class="card-header-action">
                        <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#modal-tambah">
                            <i class="fas fa-plus"></i> Tambah Jenis Agenda
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-jenis-agenda">
                            <thead>
                                <tr>
                                    <th width="8%" class="text-center">No</th>
                                    <th>Jenis Agenda</th>
                                    <th width="15%" class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="text-center"></td>
                                        <td><?= htmlspecialchars($r['jenis_agenda']) ?></td>
                                        <td class="text-center style="white-space:nowrap"">
                                            <div class="d-inline-flex align-items-center">
                                                <button class="btn btn-warning btn-sm btn-edit mr-1" type="button"
                                                    data-id="<?= (int)$r['id_jenis'] ?>"
                                                    data-jenis="<?= htmlspecialchars($r['jenis_agenda'], ENT_QUOTES) ?>"
                                                    title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-danger btn-sm btn-hapus" type="button"
                                                    data-id="<?= (int)$r['id_jenis'] ?>"
                                                    data-jenis="<?= htmlspecialchars($r['jenis_agenda'], ENT_QUOTES) ?>"
                                                    title="Hapus">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Modal Tambah -->
<div class="modal fade" id="modal-tambah" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="data_agenda.php">
                <input type="hidden" name="aksi" value="tambah">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Jenis Agenda</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Jenis Agenda <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="jenis_agenda" required placeholder="Contoh: Internal, Rapat Koordinasi, Dinas Luar">
                    </div>
                </div>
                <div class="modal-footer bg-whitesmoke br">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit -->
<div class="modal fade" id="modal-edit" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="data_agenda.php">
                <input type="hidden" name="aksi" value="edit">
                <input type="hidden" name="id_jenis" id="edit_id_jenis">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Jenis Agenda</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Jenis Agenda <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="jenis_agenda" id="edit_jenis_agenda" required>
                    </div>
                </div>
                <div class="modal-footer bg-whitesmoke br">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
