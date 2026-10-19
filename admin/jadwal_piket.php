<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) session_start();

if (!isAuthorized(['admin', 'tata_usaha', 'kepala_madrasah', 'guru', 'wali'])) {
    redirect('../login.php');
}

$can_edit = isAuthorized(['admin', 'tata_usaha']);

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_jadwal_piket (
        id INT AUTO_INCREMENT PRIMARY KEY,
        hari VARCHAR(20) NOT NULL UNIQUE,
        id_guru TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {
    error_log("Create table error: " . $e->getMessage());
}

$hari_list = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Ahad'];

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $hari = $_POST['hari'];
        $id_guru = isset($_POST['id_guru']) ? implode(',', array_map('intval', (array)$_POST['id_guru'])) : '';

        if (!$hari || !$id_guru) {
            $error = 'Data tidak lengkap.';
        } else {
            if ($action === 'add') {
                $stmt = $pdo->prepare("INSERT INTO tb_jadwal_piket (hari, id_guru) VALUES (?, ?)");
                $stmt->execute([$hari, $id_guru]);
                $_SESSION['flash'] = 'Jadwal piket ' . $hari . ' berhasil ditambahkan.';
            } else {
                $id = (int)$_POST['id'];
                $stmt = $pdo->prepare("UPDATE tb_jadwal_piket SET hari=?, id_guru=? WHERE id=?");
                $stmt->execute([$hari, $id_guru, $id]);
                $_SESSION['flash'] = 'Jadwal piket ' . $hari . ' berhasil diubah.';
            }
            header('Location: jadwal_piket.php');
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM tb_jadwal_piket WHERE id=?");
        $stmt->execute([$id]);
        $_SESSION['flash'] = 'Jadwal piket berhasil dihapus.';
        header('Location: jadwal_piket.php');
        exit;
    }
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// Fetch data
$stmt = $pdo->query("SELECT * FROM tb_jadwal_piket ORDER BY id ASC");
$jadwal = $stmt->fetchAll(PDO::FETCH_ASSOC);

$jadwal_by_hari = [];
foreach ($jadwal as $j) {
    $jadwal_by_hari[$j['hari']] = $j;
}

// All teachers for dropdown
$stmt_g = $pdo->query("SELECT id_guru, nama_guru, foto FROM tb_guru ORDER BY nama_guru ASC");
$all_guru = $stmt_g->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Jadwal Guru Piket';
$css_libs = ['https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css'];
$js_libs = ['https://cdn.jsdelivr.net/npm/sweetalert2@11', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js'];

$js_page = ["
$(document).ready(function() {
    $('.select2-multiple').select2({ placeholder: '-- Pilih Guru --', allowClear: true, width: '100%' });
    $('#editModal').on('show.bs.modal', function(e) {
        var btn = $(e.relatedTarget);
        $('#edit_id').val(btn.data('id'));
        $('#edit_hari').val(btn.data('hari'));
        var ids = (btn.data('guru') || '').split(',').map(Number);
        $('#edit_guru').val(ids).trigger('change');
    });
    $('#addModal').on('shown.bs.modal', function() {
        $('#add_guru').select2({ placeholder: '-- Pilih Guru --', allowClear: true, width: '100%', dropdownParent: $('#addModal') });
    });
    $('#editModal').on('shown.bs.modal', function() {
        $('#edit_guru').select2({ placeholder: '-- Pilih Guru --', allowClear: true, width: '100%', dropdownParent: $('#editModal') });
    });
});
var flashMsg = " . json_encode($flash) . ";
if (flashMsg) { Swal.fire({ icon: 'success', title: 'Berhasil', text: flashMsg, timer: 2000, showConfirmButton: false }); }
"];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<style>
.jadwal-card {
    border-radius: 12px;
    border: 1px solid #e2e8f0 !important;
    background: #ffffff;
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}
.jadwal-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(103, 119, 239, 0.08) !important;
    border-color: #cbd5e1 !important;
}
.jadwal-avatar {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12);
}
.jadwal-act-btn {
    border-radius: 6px;
    width: 32px;
    height: 32px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.piket-teacher-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    padding: 4px 12px 4px 4px;
    font-size: 12px;
    font-weight: 600;
    color: #334155;
    margin: 0 6px 6px 0;
}
.piket-teacher-chip .chip-avatar {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: linear-gradient(135deg, #6777ef, #3abaf4);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    flex-shrink: 0;
    overflow: hidden;
}
.piket-teacher-chip .chip-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; border-radius: inherit; }
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><i class="fas fa-clipboard-list mr-2"></i>Jadwal Guru Piket</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <!-- Card Utama Jadwal Piket (Bentuk Kartu Informatif) -->
            <div class="card mb-4 border-0 shadow-sm" style="border-radius: 14px;">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center py-3" style="border-radius: 14px 14px 0 0; border-bottom: 1px solid #f1f5f9; gap: 10px;">
                    <div>
                        <h4 class="mb-0 font-weight-bold text-dark"><i class="fas fa-user-shield text-primary mr-2"></i>Jadwal Guru Piket Harian</h4>
                        <small class="text-muted">Menampilkan <span class="font-weight-bold text-primary"><?= count($jadwal) ?></span> hari terjadwal</small>
                    </div>
                    <div class="d-flex align-items-center flex-wrap" style="gap: 8px;">
                        <?php if ($can_edit): ?>
                        <button class="btn btn-primary btn-sm font-weight-bold" data-toggle="modal" data-target="#addModal" style="border-radius: 6px;"><i class="fas fa-plus mr-1"></i> Tambah Jadwal</button>
                        <?php endif; ?>
                        <?php if (!empty($jadwal)): ?>
                        <div class="btn-group btn-pill overflow-hidden" style="border-radius: 30px;">
                            <button type="button" class="btn btn-danger btn-sm px-3 font-weight-bold" onclick="exportToPDF()" style="background-color:#ff5e5e;border:none;">
                                <i class="fas fa-file-pdf mr-1"></i> PDF
                            </button>
                            <button type="button" class="btn btn-success btn-sm px-3 font-weight-bold" onclick="exportToExcel()" style="background-color:#47c363;border:none;">
                                <i class="fas fa-file-excel mr-1"></i> Excel
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body bg-light p-3 p-md-4" style="border-radius: 0 0 14px 14px;">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <?php if (empty($jadwal)): ?>
                        <div class="text-center py-5 bg-white rounded shadow-sm border">
                            <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted font-weight-bold">Belum Ada Jadwal Piket</h5>
                            <p class="text-muted small mb-0">Silakan tambahkan jadwal guru piket baru.</p>
                        </div>
                    <?php else: ?>
                        <div class="row" style="row-gap: 16px;">
                            <?php
                            $piket_palette = [
                                ['#6777ef', '#3abaf4'],
                                ['#10b981', '#059669'],
                                ['#f59e0b', '#f97316'],
                                ['#8b5cf6', '#6366f1'],
                                ['#ec4899', '#f43f5e'],
                                ['#0ea5e9', '#06b6d4'],
                                ['#14b8a6', '#0d9488'],
                            ];
                            ?>
                            <?php $pi = 0; foreach ($jadwal as $j): ?>
                                <?php
                                $pal = $piket_palette[$pi % count($piket_palette)];
                                $pi++;
                                $ids = array_filter(explode(',', $j['id_guru']));
                                $nama_guru = [];
                                $guru_piket = [];
                                foreach ($ids as $id) {
                                    foreach ($all_guru as $g) {
                                        if ($g['id_guru'] == $id) {
                                            $nama_guru[] = $g['nama_guru'];
                                            $guru_piket[] = $g;
                                            break;
                                        }
                                    }
                                }
                                ?>
                                <div class="col-12 col-md-6 col-xl-6 d-flex align-items-stretch">
                                    <div class="card w-100 border-0 shadow-sm jadwal-card mb-0 d-flex flex-column justify-content-between" style="overflow: hidden;">
                                        <div class="card-body p-3">
                                            <div class="d-flex justify-content-between align-items-start mb-2 pb-2 border-bottom">
                                                <div class="d-flex align-items-center" style="gap: 12px; min-width: 0;">
                                                    <div class="jadwal-avatar flex-shrink-0" style="background: linear-gradient(135deg, <?= $pal[0] ?>, <?= $pal[1] ?>);">
                                                        <i class="fas fa-user-shield"></i>
                                                    </div>
                                                    <div style="min-width: 0;">
                                                        <h6 class="mb-0 font-weight-bold text-dark"><?= strtoupper(htmlspecialchars($j['hari'])) ?></h6>
                                                        <small class="text-muted">Hari piket</small>
                                                    </div>
                                                </div>
                                                <span class="badge badge-primary px-2 py-1 font-weight-bold flex-shrink-0" style="border-radius: 6px; font-size: 11px;" title="Jumlah guru piket">
                                                    <i class="fas fa-users mr-1"></i> <?= count($nama_guru) ?> Guru
                                                </span>
                                            </div>
                                            <div class="small font-weight-bold text-dark mb-2">
                                                <i class="fas fa-user-check text-primary mr-2" style="font-size: 11px;"></i> Guru Bertugas:
                                            </div>
                                            <div class="d-flex flex-wrap">
                                                <?php foreach ($guru_piket as $gp): ?>
                                                    <span class="piket-teacher-chip">
                                                        <span class="chip-avatar"><?= getPersonAvatarHtml($gp['foto'] ?? '', strtoupper(mb_substr(trim((string)$gp['nama_guru']), 0, 1))) ?></span>
                                                        <?= htmlspecialchars($gp['nama_guru']) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <?php if ($can_edit): ?>
                                        <div class="card-footer bg-white py-2 px-3 border-top d-flex justify-content-end" style="gap: 6px;">
                                            <button class="btn btn-warning btn-sm jadwal-act-btn" data-toggle="modal" data-target="#editModal"
                                                data-id="<?= $j['id'] ?>"
                                                data-hari="<?= $j['hari'] ?>"
                                                data-guru="<?= $j['id_guru'] ?>" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" style="display:inline" class="delete-form mb-0">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $j['id'] ?>">
                                                <button class="btn btn-danger btn-sm jadwal-act-btn delete-btn" type="button" title="Hapus"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($jadwal)): ?>
                    <div id="exportTableContainer" style="display:none">
                        <table border="1">
                            <thead>
                                <tr><th colspan="3" style="text-align:center;font-size:16px;font-weight:bold;">JADWAL GURU PIKET</th></tr>
                                <tr><th>No</th><th>Hari</th><th>Nama Guru</th></tr>
                            </thead>
                            <tbody>
                                <?php $no2 = 1; foreach ($jadwal as $j): ?>
                                <?php
                                $ids = array_filter(explode(',', $j['id_guru']));
                                $nama_guru = [];
                                foreach ($ids as $id) {
                                    foreach ($all_guru as $g) {
                                        if ($g['id_guru'] == $id) {
                                            $nama_guru[] = $g['nama_guru'];
                                            break;
                                        }
                                    }
                                }
                                ?>
                                <tr><td><?= $no2++ ?></td><td><?= htmlspecialchars($j['hari']) ?></td><td><?= htmlspecialchars(implode(', ', $nama_guru)) ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <form id="exportForm" method="POST" action="" target="_blank">
                        <input type="hidden" name="table_data" id="table_data">
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Tambah Jadwal Piket</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label>Hari</label>
                        <select name="hari" class="form-control" required>
                            <option value="">-- Pilih Hari --</option>
                            <?php foreach ($hari_list as $h): ?>
                            <option value="<?= $h ?>"><?= $h ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nama Guru</label>
                        <select name="id_guru[]" id="add_guru" class="form-control select2-multiple" multiple required>
                            <?php foreach ($all_guru as $g): ?>
                            <option value="<?= $g['id_guru'] ?>"><?= htmlspecialchars($g['nama_guru']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Edit Jadwal Piket</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="form-group">
                        <label>Hari</label>
                        <select name="hari" id="edit_hari" class="form-control" required>
                            <option value="">-- Pilih Hari --</option>
                            <?php foreach ($hari_list as $h): ?>
                            <option value="<?= $h ?>"><?= $h ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nama Guru</label>
                        <select name="id_guru[]" id="edit_guru" class="form-control select2-multiple" multiple required>
                            <?php foreach ($all_guru as $g): ?>
                            <option value="<?= $g['id_guru'] ?>"><?= htmlspecialchars($g['nama_guru']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php
$js_page[] = "
$(document).on('click', '.delete-btn', function() {
    var form = $(this).closest('form');
    Swal.fire({
        title: 'Hapus Jadwal?',
        text: 'Data tidak bisa dikembalikan.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Ya, Hapus!',
        cancelButtonText: 'Batal'
    }).then(function(r) { if (r.isConfirmed) form.submit(); });
});

function exportToPDF() {
    var html = document.getElementById('exportTableContainer').innerHTML;
    document.getElementById('table_data').value = html;
    document.getElementById('exportForm').action = 'export_jadwal_piket_pdf.php';
    document.getElementById('exportForm').submit();
}
function exportToExcel() {
    var html = document.getElementById('exportTableContainer').innerHTML;
    document.getElementById('table_data').value = html;
    document.getElementById('exportForm').action = 'export_jadwal_piket_excel.php';
    document.getElementById('exportForm').submit();
}
";
include '../templates/footer.php';
?>
