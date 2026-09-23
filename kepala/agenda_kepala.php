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

$page_title = 'Agenda Kepala';
$current_page = basename(__FILE__);

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = (string)($_POST['aksi'] ?? '');
    try {
        if ($aksi === 'tambah') {
            $nama_agenda = sanitizeInput((string)($_POST['nama_agenda'] ?? ''));
            $id_jenis = (int)($_POST['id_jenis'] ?? 0);
            $hari_tanggal = sanitizeInput((string)($_POST['hari_tanggal'] ?? ''));
            $waktu = sanitizeInput((string)($_POST['waktu'] ?? ''));
            $tempat = sanitizeInput((string)($_POST['tempat'] ?? ''));
            $uraian_kegiatan = trim((string)($_POST['uraian_kegiatan'] ?? ''));
            $keterangan = sanitizeInput((string)($_POST['keterangan'] ?? ''));

            if ($nama_agenda === '' || $id_jenis <= 0 || $hari_tanggal === '') {
                $message = ['type' => 'danger', 'text' => 'Nama Agenda, Jenis Agenda, dan Tanggal wajib diisi.'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO tb_agenda_kepala (nama_agenda, id_jenis, hari_tanggal, waktu, tempat, uraian_kegiatan, keterangan) VALUES (?, ?, ?, ?, ?, ?, ?)");
                if ($stmt->execute([$nama_agenda, $id_jenis, $hari_tanggal, $waktu, $tempat, $uraian_kegiatan, $keterangan])) {
                    $message = ['type' => 'success', 'text' => 'Agenda Kepala berhasil ditambahkan.'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal menambahkan Agenda Kepala.'];
                }
            }
        } elseif ($aksi === 'edit') {
            $id = (int)($_POST['id_agenda'] ?? 0);
            $nama_agenda = sanitizeInput((string)($_POST['nama_agenda'] ?? ''));
            $id_jenis = (int)($_POST['id_jenis'] ?? 0);
            $hari_tanggal = sanitizeInput((string)($_POST['hari_tanggal'] ?? ''));
            $waktu = sanitizeInput((string)($_POST['waktu'] ?? ''));
            $tempat = sanitizeInput((string)($_POST['tempat'] ?? ''));
            $uraian_kegiatan = trim((string)($_POST['uraian_kegiatan'] ?? ''));
            $keterangan = sanitizeInput((string)($_POST['keterangan'] ?? ''));

            if ($id <= 0 || $nama_agenda === '' || $id_jenis <= 0 || $hari_tanggal === '') {
                $message = ['type' => 'danger', 'text' => 'Data tidak valid atau belum lengkap.'];
            } else {
                $stmt = $pdo->prepare("UPDATE tb_agenda_kepala SET nama_agenda = ?, id_jenis = ?, hari_tanggal = ?, waktu = ?, tempat = ?, uraian_kegiatan = ?, keterangan = ? WHERE id_agenda = ?");
                if ($stmt->execute([$nama_agenda, $id_jenis, $hari_tanggal, $waktu, $tempat, $uraian_kegiatan, $keterangan, $id])) {
                    $message = ['type' => 'success', 'text' => 'Agenda Kepala berhasil diperbarui.'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal memperbarui Agenda Kepala.'];
                }
            }
        } elseif ($aksi === 'hapus') {
            $id = (int)($_POST['id_agenda'] ?? 0);
            if ($id <= 0) {
                $message = ['type' => 'danger', 'text' => 'ID tidak valid.'];
            } else {
                $stmt = $pdo->prepare("DELETE FROM tb_agenda_kepala WHERE id_agenda = ?");
                if ($stmt->execute([$id])) {
                    $message = ['type' => 'success', 'text' => 'Agenda Kepala berhasil dihapus.'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal menghapus Agenda Kepala.'];
                }
            }
        }
    } catch (Throwable $e) {
        $message = ['type' => 'danger', 'text' => 'Terjadi kesalahan: ' . $e->getMessage()];
    }
}

// Fetch list jenis agenda for dropdown
$jenis_list = [];
try {
    $jenis_list = $pdo->query("SELECT * FROM tb_agenda_jenis ORDER BY jenis_agenda ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $jenis_list = [];
}

// Fetch all agenda kepala rows
$rows = [];
try {
    $rows = $pdo->query("SELECT a.*, j.jenis_agenda 
                         FROM tb_agenda_kepala a 
                         LEFT JOIN tb_agenda_jenis j ON j.id_jenis = a.id_jenis 
                         ORDER BY a.hari_tanggal DESC, a.id_agenda DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rows = [];
}

$css_libs = ['https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
    'https://cdn.ckeditor.com/4.22.1/full/ckeditor.js'
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
    // Initialize CKEditor 4 for Add and Edit Forms
    if (typeof CKEDITOR !== 'undefined') {
        CKEDITOR.config.versionCheck = false;
        CKEDITOR.replace('ck_uraian_tambah', { height: 200, toolbar: 'Full', versionCheck: false });
        CKEDITOR.replace('ck_uraian_edit', { height: 200, toolbar: 'Full', versionCheck: false });
    }

    var table = $('#table-agenda-kepala').DataTable({
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
        columnDefs: [{ sortable: false, targets: [7] }]
    });

    table.on('order.dt search.dt draw.dt', function () {
        var info = table.page.info();
        table.column(0, { search: 'applied', order: 'applied', page: 'current' }).nodes().each(function (cell, i) {
            if (cell) cell.innerHTML = info.start + i + 1;
        });
    }).draw();

    // Reset CKEditor on modal show
    $('#modal-tambah').on('shown.bs.modal', function() {
        if (typeof CKEDITOR !== 'undefined' && CKEDITOR.instances['ck_uraian_tambah']) {
            CKEDITOR.instances['ck_uraian_tambah'].setData('');
        }
    });

    $(document).on('click', '.btn-detail', function () {
        var row = $(this).data('row');
        if (typeof row === 'string') {
            try { row = JSON.parse(row); } catch(e) { row = null; }
        }
        if (row) {
            $('#detail_nama_agenda').text(row.nama_agenda || '-');
            $('#detail_jenis_agenda').text(row.jenis_agenda || '-');
            $('#detail_hari_tanggal').text(row.hari_tanggal_indo || row.hari_tanggal || '-');
            $('#detail_waktu').text(row.waktu || '-');
            $('#detail_tempat').text(row.tempat || '-');
            $('#detail_keterangan').text(row.keterangan || '-');
            $('#detail_uraian_content').html(row.uraian_kegiatan || '<em class="text-muted">Tidak ada uraian kegiatan.</em>');
            $('#modal-detail').modal('show');
        }
    });

    $(document).on('click', '.btn-edit', function () {
        var row = $(this).data('row');
        if (typeof row === 'string') {
            try { row = JSON.parse(row); } catch(e) { row = null; }
        }
        if (row) {
            $('#edit_id_agenda').val(row.id_agenda);
            $('#edit_nama_agenda').val(row.nama_agenda);
            $('#edit_id_jenis').val(row.id_jenis);
            $('#edit_hari_tanggal').val(row.hari_tanggal);
            $('#edit_waktu').val(row.waktu || '');
            $('#edit_tempat').val(row.tempat);
            $('#edit_keterangan').val(row.keterangan || '');
            
            $('#modal-edit').modal('show');
            setTimeout(function() {
                if (typeof CKEDITOR !== 'undefined' && CKEDITOR.instances['ck_uraian_edit']) {
                    CKEDITOR.instances['ck_uraian_edit'].setData(row.uraian_kegiatan || '');
                }
            }, 200);
        }
    });

    $(document).on('click', '.btn-hapus', function () {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        Swal.fire({
            title: 'Konfirmasi Hapus',
            text: 'Hapus agenda "' + nama + '"?',
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
                f.action = 'agenda_kepala.php';
                var fields = { aksi: 'hapus', id_agenda: id };
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

    $('#btn-excel').on('click', function () {
        var table = document.getElementById('table-agenda-kepala');
        if (!table) return;
        if (typeof XLSX !== 'undefined') {
            var clone = table.cloneNode(true);
            for (var i = 0; i < clone.rows.length; i++) {
                if (clone.rows[i].cells.length > 0) clone.rows[i].deleteCell(-1);
            }
            var wb = XLSX.utils.table_to_book(clone, { sheet: "Sheet1" });
            XLSX.writeFile(wb, 'agenda_kepala.xlsx');
        } else {
            var clone = table.cloneNode(true);
            for (var i = 0; i < clone.rows.length; i++) {
                if (clone.rows[i].cells.length > 0) clone.rows[i].deleteCell(-1);
            }
            var html = '<table border="1">' + clone.innerHTML + '</table>';
            var a = document.createElement('a');
            a.href = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
            a.download = 'agenda_kepala.xls';
            a.click();
        }
    });

    $('#btn-pdf').on('click', function () {
        window.open('cetak_agenda_kepala.php', '_blank');
    });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<style>
.cke_notification_warning { display: none !important; }
.agenda-content {
    font-size: 13px;
    line-height: 1.5;
}
.agenda-content p {
    margin-bottom: 0.3rem;
}
.agenda-content ul {
    list-style-type: disc !important;
    padding-left: 20px !important;
    margin-top: 0;
    margin-bottom: 0.5rem !important;
}
.agenda-content ol {
    list-style-type: decimal !important;
    padding-left: 20px !important;
    margin-top: 0;
    margin-bottom: 0.5rem !important;
}
.agenda-content li {
    display: list-item !important;
}
.agenda-content-preview {
    font-size: 13px;
    line-height: 1.45;
    word-wrap: break-word;
    overflow-wrap: break-word;
    word-break: break-word;
    white-space: normal;
    max-height: 105px;
    overflow: hidden;
}
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Agenda Kepala</h1>
            <?php echo render_breadcrumb(); ?>
        </div>
        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4>Daftar Agenda Kepala Madrasah</h4>
                    <div class="card-header-action">
                        <button class="btn btn-success" id="btn-excel" type="button"><i class="fas fa-file-excel"></i> Excel</button>
                        <button class="btn btn-warning" id="btn-pdf" type="button"><i class="fas fa-file-pdf"></i> PDF</button>
                        <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#modal-tambah">
                            <i class="fas fa-plus"></i> Tambah Agenda
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-agenda-kepala">
                            <thead>
                                <tr>
                                    <th width="5%" class="text-center">No</th>
                                    <th width="18%">Nama Agenda</th>
                                    <th width="12%">Jenis Agenda</th>
                                    <th width="13%">Hari / Tanggal</th>
                                    <th width="10%">Waktu</th>
                                    <th width="12%">Tempat</th>
                                    <th width="20%">Uraian Kegiatan</th>
                                    <th width="10%" class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): 
                                    $r['hari_tanggal_indo'] = formatHariTanggalIndo($r['hari_tanggal']);
                                ?>
                                    <tr>
                                        <td class="text-center"></td>
                                        <td>
                                            <strong><?= htmlspecialchars($r['nama_agenda']) ?></strong>
                                            <?php if (!empty($r['keterangan'])): ?>
                                                <small class="d-block text-muted"><?= htmlspecialchars($r['keterangan']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge badge-info"><?= htmlspecialchars($r['jenis_agenda'] ?? '-') ?></span></td>
                                        <td><?= htmlspecialchars($r['hari_tanggal_indo']) ?></td>
                                        <td><?= htmlspecialchars($r['waktu'] ?: '-') ?></td>
                                        <td><?= htmlspecialchars($r['tempat'] ?: '-') ?></td>
                                        <td>
                                            <?php if (!empty($r['uraian_kegiatan'])): ?>
                                                <div class="agenda-content-preview">
                                                    <?= $r['uraian_kegiatan'] ?>
                                                </div>
                                            <?php else: ?>
                                                <em class="text-muted">-</em>
                                            <?php endif; ?>
                                        </td>
                                        <td style="white-space:nowrap">
                                            <div class="d-inline-flex align-items-center">
                                                <button class="btn btn-info btn-sm btn-detail mr-1" type="button"
                                                    data-row='<?= htmlspecialchars(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>'
                                                    title="Lihat Detail">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-warning btn-sm btn-edit mr-1" type="button"
                                                    data-row='<?= htmlspecialchars(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>'
                                                    title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-danger btn-sm btn-hapus" type="button"
                                                    data-id="<?= (int)$r['id_agenda'] ?>"
                                                    data-nama="<?= htmlspecialchars($r['nama_agenda'], ENT_QUOTES) ?>"
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

<!-- Modal Detail -->
<div class="modal fade" id="modal-detail" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title text-white"><i class="fas fa-file-alt mr-2"></i> Detail Agenda Kepala</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body p-4" style="background-color: #f8f9fa;">
                <!-- Hero Header Card -->
                <div class="card mb-3 shadow-sm border-0">
                    <div class="card-body p-3 bg-white rounded border">
                        <div class="text-primary font-weight-bold text-uppercase small mb-1"><i class="fas fa-bookmark mr-1"></i> Nama Agenda</div>
                        <h4 class="mb-0 text-dark font-weight-bold" id="detail_nama_agenda"></h4>
                    </div>
                </div>

                <!-- Info Cards Grid -->
                <div class="row mb-3">
                    <div class="col-md-6 mb-2">
                        <div class="p-3 bg-white rounded border shadow-sm h-100">
                            <small class="text-muted d-block font-weight-bold text-uppercase mb-1"><i class="fas fa-tag text-primary mr-1"></i> Jenis Agenda</small>
                            <span class="font-weight-bold text-dark" id="detail_jenis_agenda"></span>
                        </div>
                    </div>
                    <div class="col-md-6 mb-2">
                        <div class="p-3 bg-white rounded border shadow-sm h-100">
                            <small class="text-muted d-block font-weight-bold text-uppercase mb-1"><i class="fas fa-calendar-day text-info mr-1"></i> Hari / Tanggal</small>
                            <span class="font-weight-bold text-dark" id="detail_hari_tanggal"></span>
                        </div>
                    </div>
                    <div class="col-md-6 mb-2">
                        <div class="p-3 bg-white rounded border shadow-sm h-100">
                            <small class="text-muted d-block font-weight-bold text-uppercase mb-1"><i class="fas fa-clock text-warning mr-1"></i> Waktu</small>
                            <span class="font-weight-bold text-dark" id="detail_waktu"></span>
                        </div>
                    </div>
                    <div class="col-md-6 mb-2">
                        <div class="p-3 bg-white rounded border shadow-sm h-100">
                            <small class="text-muted d-block font-weight-bold text-uppercase mb-1"><i class="fas fa-map-marker-alt text-danger mr-1"></i> Tempat</small>
                            <span class="font-weight-bold text-dark" id="detail_tempat"></span>
                        </div>
                    </div>
                    <div class="col-md-12 mb-2">
                        <div class="p-3 bg-white rounded border shadow-sm h-100">
                            <small class="text-muted d-block font-weight-bold text-uppercase mb-1"><i class="fas fa-info-circle text-muted mr-1"></i> Keterangan</small>
                            <span class="font-weight-bold text-dark" id="detail_keterangan"></span>
                        </div>
                    </div>
                </div>

                <!-- Uraian Content Box -->
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white py-2 border-bottom">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-list-ol mr-2"></i> Uraian Kegiatan</h6>
                    </div>
                    <div class="card-body p-3 agenda-content bg-white rounded-bottom" id="detail_uraian_content" style="min-height:120px;"></div>
                </div>
            </div>
            <div class="modal-footer bg-whitesmoke br">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Tambah -->
<div class="modal fade" id="modal-tambah" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" action="agenda_kepala.php">
                <input type="hidden" name="aksi" value="tambah">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Agenda Kepala</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nama Agenda <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nama_agenda" required placeholder="Contoh: Rapat Koordinasi Bersama Pengawas">
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>Jenis Agenda <span class="text-danger">*</span></label>
                            <select class="form-control" name="id_jenis" required>
                                <option value="">Pilih Jenis Agenda</option>
                                <?php foreach ($jenis_list as $j): ?>
                                    <option value="<?= (int)$j['id_jenis'] ?>"><?= htmlspecialchars($j['jenis_agenda']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($jenis_list)): ?>
                                <small class="text-danger">Belum ada Jenis Agenda. Silakan atur di menu Data Agenda terlebih dahulu.</small>
                            <?php endif; ?>
                        </div>
                        <div class="form-group col-md-4">
                            <label>Hari / Tanggal <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="hari_tanggal" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group col-md-4">
                            <label>Waktu</label>
                            <input type="time" class="form-control" name="waktu">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Tempat</label>
                        <input type="text" class="form-control" name="tempat" placeholder="Contoh: Ruang Kepala / Aula Kemenag">
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Uraian Kegiatan</label>
                        <textarea class="form-control" name="uraian_kegiatan" id="ck_uraian_tambah" rows="5"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Keterangan</label>
                        <textarea class="form-control" name="keterangan" rows="2" placeholder="Catatan tambahan mengenai agenda..."></textarea>
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
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" action="agenda_kepala.php">
                <input type="hidden" name="aksi" value="edit">
                <input type="hidden" name="id_agenda" id="edit_id_agenda">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Agenda Kepala</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nama Agenda <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nama_agenda" id="edit_nama_agenda" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>Jenis Agenda <span class="text-danger">*</span></label>
                            <select class="form-control" name="id_jenis" id="edit_id_jenis" required>
                                <option value="">Pilih Jenis Agenda</option>
                                <?php foreach ($jenis_list as $j): ?>
                                    <option value="<?= (int)$j['id_jenis'] ?>"><?= htmlspecialchars($j['jenis_agenda']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            <label>Hari / Tanggal <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="hari_tanggal" id="edit_hari_tanggal" required>
                        </div>
                        <div class="form-group col-md-4">
                            <label>Waktu</label>
                            <input type="time" class="form-control" name="waktu" id="edit_waktu">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Tempat</label>
                        <input type="text" class="form-control" name="tempat" id="edit_tempat">
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Uraian Kegiatan</label>
                        <textarea class="form-control" name="uraian_kegiatan" id="ck_uraian_edit" rows="5"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Keterangan</label>
                        <textarea class="form-control" name="keterangan" id="edit_keterangan" rows="2"></textarea>
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
