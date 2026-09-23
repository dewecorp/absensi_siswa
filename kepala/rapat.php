<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/agenda.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isAuthorized(['kepala_madrasah', 'admin', 'tata_usaha', 'guru', 'wali'])) {
    redirect('../login.php');
}

ensureAgendaTables($pdo);

$page_title = 'Agenda Rapat';
$current_page = basename(__FILE__);
$can_manage = isAuthorized(['kepala_madrasah', 'admin', 'tata_usaha']);

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $aksi = (string)($_POST['aksi'] ?? '');
    try {
        if ($aksi === 'tambah') {
            $nama_rapat = sanitizeInput((string)($_POST['nama_rapat'] ?? ''));
            $hari_tanggal = sanitizeInput((string)($_POST['hari_tanggal'] ?? ''));
            $waktu = sanitizeInput((string)($_POST['waktu'] ?? ''));
            // Note: agenda_rapat & notulensi are HTML content from CKEditor
            $agenda_rapat = trim((string)($_POST['agenda_rapat'] ?? ''));
            $notulensi = trim((string)($_POST['notulensi'] ?? ''));
            $pemimpin_rapat = sanitizeInput((string)($_POST['pemimpin_rapat'] ?? ''));
            $tempat = sanitizeInput((string)($_POST['tempat'] ?? ''));

            if ($nama_rapat === '' || $hari_tanggal === '') {
                $message = ['type' => 'danger', 'text' => 'Nama Rapat dan Hari/Tanggal wajib diisi.'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO tb_agenda_rapat (nama_rapat, hari_tanggal, waktu, agenda_rapat, notulensi, pemimpin_rapat, tempat) VALUES (?, ?, ?, ?, ?, ?, ?)");
                if ($stmt->execute([$nama_rapat, $hari_tanggal, $waktu, $agenda_rapat, $notulensi, $pemimpin_rapat, $tempat])) {
                    $message = ['type' => 'success', 'text' => 'Agenda Rapat berhasil ditambahkan.'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal menambahkan Agenda Rapat.'];
                }
            }
        } elseif ($aksi === 'edit') {
            $id = (int)($_POST['id_rapat'] ?? 0);
            $nama_rapat = sanitizeInput((string)($_POST['nama_rapat'] ?? ''));
            $hari_tanggal = sanitizeInput((string)($_POST['hari_tanggal'] ?? ''));
            $waktu = sanitizeInput((string)($_POST['waktu'] ?? ''));
            $agenda_rapat = trim((string)($_POST['agenda_rapat'] ?? ''));
            $notulensi = trim((string)($_POST['notulensi'] ?? ''));
            $pemimpin_rapat = sanitizeInput((string)($_POST['pemimpin_rapat'] ?? ''));
            $tempat = sanitizeInput((string)($_POST['tempat'] ?? ''));

            if ($id <= 0 || $nama_rapat === '' || $hari_tanggal === '') {
                $message = ['type' => 'danger', 'text' => 'Data tidak valid atau belum lengkap.'];
            } else {
                $stmt = $pdo->prepare("UPDATE tb_agenda_rapat SET nama_rapat = ?, hari_tanggal = ?, waktu = ?, agenda_rapat = ?, notulensi = ?, pemimpin_rapat = ?, tempat = ? WHERE id_rapat = ?");
                if ($stmt->execute([$nama_rapat, $hari_tanggal, $waktu, $agenda_rapat, $notulensi, $pemimpin_rapat, $tempat, $id])) {
                    $message = ['type' => 'success', 'text' => 'Agenda Rapat berhasil diperbarui.'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal memperbarui Agenda Rapat.'];
                }
            }
        } elseif ($aksi === 'hapus') {
            $id = (int)($_POST['id_rapat'] ?? 0);
            if ($id <= 0) {
                $message = ['type' => 'danger', 'text' => 'ID tidak valid.'];
            } else {
                $stmt = $pdo->prepare("DELETE FROM tb_agenda_rapat WHERE id_rapat = ?");
                if ($stmt->execute([$id])) {
                    $message = ['type' => 'success', 'text' => 'Agenda Rapat berhasil dihapus.'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal menghapus Agenda Rapat.'];
                }
            }
        }
    } catch (Throwable $e) {
        $message = ['type' => 'danger', 'text' => 'Terjadi kesalahan: ' . $e->getMessage()];
    }
}

// Fetch all rapat rows
$rows = [];
try {
    $rows = $pdo->query("SELECT * FROM tb_agenda_rapat ORDER BY hari_tanggal DESC, id_rapat DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rows = [];
}

$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'
];
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
        CKEDITOR.replace('ck_agenda_tambah', { height: 200, toolbar: 'Full', versionCheck: false });
        CKEDITOR.replace('ck_notulensi_tambah', { height: 200, toolbar: 'Full', versionCheck: false });
        CKEDITOR.replace('ck_agenda_edit', { height: 200, toolbar: 'Full', versionCheck: false });
        CKEDITOR.replace('ck_notulensi_edit', { height: 200, toolbar: 'Full', versionCheck: false });
    }

    var table = $('#table-rapat').DataTable({
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
        columnDefs: [{ sortable: false, targets: [8] }]
    });

    table.on('order.dt search.dt draw.dt', function () {
        var info = table.page.info();
        table.column(0, { search: 'applied', order: 'applied', page: 'current' }).nodes().each(function (cell, i) {
            if (cell) cell.innerHTML = info.start + i + 1;
        });
    }).draw();

    // Reset CKEditor on modal show
    $('#modal-tambah').on('shown.bs.modal', function() {
        if (typeof CKEDITOR !== 'undefined') {
            if (CKEDITOR.instances['ck_agenda_tambah']) CKEDITOR.instances['ck_agenda_tambah'].setData('');
            if (CKEDITOR.instances['ck_notulensi_tambah']) CKEDITOR.instances['ck_notulensi_tambah'].setData('');
        }
    });

    $(document).on('click', '.btn-detail', function () {
        var row = $(this).data('row');
        if (typeof row === 'string') {
            try { row = JSON.parse(row); } catch(e) { row = null; }
        }
        if (row) {
            $('#detail_nama_rapat').text(row.nama_rapat || '-');
            $('#detail_hari_tanggal').text(row.hari_tanggal_indo || row.hari_tanggal || '-');
            $('#detail_waktu').text(row.waktu || '-');
            $('#detail_pemimpin').text(row.pemimpin_rapat || '-');
            $('#detail_tempat').text(row.tempat || '-');
            $('#detail_agenda_content').html(row.agenda_rapat || '<em class="text-muted">Tidak ada rincian agenda.</em>');
            $('#detail_notulensi_content').html(row.notulensi || '<em class="text-muted">Belum ada notulensi rapat yang diinput.</em>');
            $('#btn-cetak-detail').attr('href', 'cetak_detail_rapat.php?id=' + row.id_rapat);
            $('#modal-detail').modal('show');
        }
    });

    $(document).on('click', '.btn-edit', function () {
        var row = $(this).data('row');
        if (typeof row === 'string') {
            try { row = JSON.parse(row); } catch(e) { row = null; }
        }
        if (row) {
            $('#edit_id_rapat').val(row.id_rapat);
            $('#edit_nama_rapat').val(row.nama_rapat);
            $('#edit_hari_tanggal').val(row.hari_tanggal);
            $('#edit_waktu').val(row.waktu || '');
            $('#edit_pemimpin_rapat').val(row.pemimpin_rapat || '');
            $('#edit_tempat').val(row.tempat || '');
            
            $('#modal-edit').modal('show');
            setTimeout(function() {
                if (typeof CKEDITOR !== 'undefined') {
                    if (CKEDITOR.instances['ck_agenda_edit']) CKEDITOR.instances['ck_agenda_edit'].setData(row.agenda_rapat || '');
                    if (CKEDITOR.instances['ck_notulensi_edit']) CKEDITOR.instances['ck_notulensi_edit'].setData(row.notulensi || '');
                }
            }, 200);
        }
    });

    $(document).on('click', '.btn-hapus', function () {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        Swal.fire({
            title: 'Konfirmasi Hapus',
            text: 'Hapus agenda rapat "' + nama + '"?',
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
                f.action = 'rapat.php';
                var fields = { aksi: 'hapus', id_rapat: id };
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
        var table = document.getElementById('table-rapat');
        if (!table) return;
        if (typeof XLSX !== 'undefined') {
            var clone = table.cloneNode(true);
            for (var i = 0; i < clone.rows.length; i++) {
                if (clone.rows[i].cells.length > 0) clone.rows[i].deleteCell(-1);
            }
            var wb = XLSX.utils.table_to_book(clone, { sheet: "Sheet1" });
            XLSX.writeFile(wb, 'agenda_rapat.xlsx');
        } else {
            var clone = table.cloneNode(true);
            for (var i = 0; i < clone.rows.length; i++) {
                if (clone.rows[i].cells.length > 0) clone.rows[i].deleteCell(-1);
            }
            var html = '<table border="1">' + clone.innerHTML + '</table>';
            var a = document.createElement('a');
            a.href = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
            a.download = 'agenda_rapat.xls';
            a.click();
        }
    });

    $('#btn-pdf').on('click', function () {
        window.open('cetak_rapat.php', '_blank');
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
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Agenda Rapat</h1>
            <?php echo render_breadcrumb(); ?>
        </div>
        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4>Daftar Agenda Rapat</h4>
                    <div class="card-header-action">
                        <button class="btn btn-success" id="btn-excel" type="button"><i class="fas fa-file-excel"></i> Excel</button>
                        <button class="btn btn-warning" id="btn-pdf" type="button"><i class="fas fa-file-pdf"></i> PDF</button>
                        <?php if ($can_manage): ?>
                        <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#modal-tambah">
                            <i class="fas fa-plus"></i> Tambah Rapat
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-rapat">
                            <thead>
                                <tr>
                                    <th width="5%" class="text-center">No</th>
                                    <th>Nama Rapat</th>
                                    <th>Hari / Tanggal</th>
                                    <th>Waktu</th>
                                    <th width="20%">Agenda Rapat</th>
                                    <th width="20%">Notulensi</th>
                                    <th>Pemimpin Rapat</th>
                                    <th>Tempat</th>
                                    <th width="12%" class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): 
                                    $r['hari_tanggal_indo'] = formatHariTanggalIndo($r['hari_tanggal']);
                                ?>
                                    <tr>
                                        <td class="text-center"></td>
                                        <td><strong><?= htmlspecialchars($r['nama_rapat']) ?></strong></td>
                                        <td><?= htmlspecialchars($r['hari_tanggal_indo']) ?></td>
                                        <td><?= htmlspecialchars($r['waktu'] ?: '-') ?></td>
                                        <td>
                                            <?php if (!empty($r['agenda_rapat'])): ?>
                                                <div class="agenda-content">
                                                    <?= $r['agenda_rapat'] ?>
                                                </div>
                                            <?php else: ?>
                                                <em class="text-muted">-</em>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($r['notulensi'])): ?>
                                                <div class="agenda-content">
                                                    <?= $r['notulensi'] ?>
                                                </div>
                                            <?php else: ?>
                                                <em class="text-muted">-</em>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($r['pemimpin_rapat'] ?: '-') ?></td>
                                        <td><?= htmlspecialchars($r['tempat'] ?: '-') ?></td>
                                        <td style="white-space:nowrap">
                                            <div class="d-inline-flex align-items-center">
                                                <button class="btn btn-info btn-sm btn-detail mr-1" type="button"
                                                    data-row='<?= htmlspecialchars(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>'
                                                    title="Lihat Detail">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <a class="btn btn-secondary btn-sm mr-1" href="cetak_detail_rapat.php?id=<?= (int)$r['id_rapat'] ?>" target="_blank" title="Cetak Agenda">
                                                    <i class="fas fa-print"></i>
                                                </a>
                                                <?php if ($can_manage): ?>
                                                <button class="btn btn-warning btn-sm btn-edit mr-1" type="button"
                                                    data-row='<?= htmlspecialchars(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>'
                                                    title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-danger btn-sm btn-hapus" type="button"
                                                    data-id="<?= (int)$r['id_rapat'] ?>"
                                                    data-nama="<?= htmlspecialchars($r['nama_rapat'], ENT_QUOTES) ?>"
                                                    title="Hapus">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
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
                <h5 class="modal-title text-white"><i class="fas fa-file-alt mr-2"></i> Detail Agenda Rapat</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body p-4" style="background-color: #f8f9fa;">
                <!-- Hero Header Card -->
                <div class="card mb-3 shadow-sm border-0">
                    <div class="card-body p-3 bg-white rounded border">
                        <div class="text-primary font-weight-bold text-uppercase small mb-1"><i class="fas fa-bookmark mr-1"></i> Nama Agenda Rapat</div>
                        <h4 class="mb-0 text-dark font-weight-bold" id="detail_nama_rapat"></h4>
                    </div>
                </div>

                <!-- Info Cards Grid -->
                <div class="row mb-3">
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
                            <small class="text-muted d-block font-weight-bold text-uppercase mb-1"><i class="fas fa-user-tie text-success mr-1"></i> Pemimpin Rapat</small>
                            <span class="font-weight-bold text-dark" id="detail_pemimpin"></span>
                        </div>
                    </div>
                    <div class="col-md-6 mb-2">
                        <div class="p-3 bg-white rounded border shadow-sm h-100">
                            <small class="text-muted d-block font-weight-bold text-uppercase mb-1"><i class="fas fa-map-marker-alt text-danger mr-1"></i> Tempat</small>
                            <span class="font-weight-bold text-dark" id="detail_tempat"></span>
                        </div>
                    </div>
                </div>

                <!-- Agenda Content Box -->
                <div class="card shadow-sm border-0 mb-3">
                    <div class="card-header bg-white py-2 border-bottom">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-list-ol mr-2"></i> Poin Agenda Rapat</h6>
                    </div>
                    <div class="card-body p-3 agenda-content bg-white rounded-bottom" id="detail_agenda_content" style="min-height:90px;"></div>
                </div>

                <!-- Notulensi Content Box -->
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white py-2 border-bottom">
                        <h6 class="m-0 font-weight-bold text-success"><i class="fas fa-pen-alt mr-2"></i> Hasil Notulensi Rapat</h6>
                    </div>
                    <div class="card-body p-3 agenda-content bg-white rounded-bottom" id="detail_notulensi_content" style="min-height:90px;"></div>
                </div>
            </div>
            <div class="modal-footer bg-whitesmoke br">
                <a id="btn-cetak-detail" href="#" target="_blank" class="btn btn-primary">
                    <i class="fas fa-print mr-1"></i> Cetak Agenda
                </a>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<?php if ($can_manage): ?>
<!-- Modal Tambah -->
<div class="modal fade" id="modal-tambah" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" action="rapat.php">
                <input type="hidden" name="aksi" value="tambah">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Agenda Rapat</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nama Rapat <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nama_rapat" required placeholder="Contoh: Rapat Evaluasi Pembelajaran Semester 1">
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Hari / Tanggal <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="hari_tanggal" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Waktu</label>
                            <input type="time" class="form-control" name="waktu">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Pemimpin Rapat</label>
                            <input type="text" class="form-control" name="pemimpin_rapat" placeholder="Contoh: Kepala Madrasah / Wakamad">
                        </div>
                        <div class="form-group col-md-6">
                            <label>Tempat</label>
                            <input type="text" class="form-control" name="tempat" placeholder="Contoh: Ruang Guru / Ruang Rapat">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Agenda Rapat (Poin-Poin Rencana Rapat)</label>
                        <textarea class="form-control" name="agenda_rapat" id="ck_agenda_tambah" rows="4"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Notulensi Rapat (Catatan / Hasil Rapat)</label>
                        <textarea class="form-control" name="notulensi" id="ck_notulensi_tambah" rows="4"></textarea>
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
            <form method="POST" action="rapat.php">
                <input type="hidden" name="aksi" value="edit">
                <input type="hidden" name="id_rapat" id="edit_id_rapat">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Agenda Rapat</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nama Rapat <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nama_rapat" id="edit_nama_rapat" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Hari / Tanggal <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="hari_tanggal" id="edit_hari_tanggal" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Waktu</label>
                            <input type="time" class="form-control" name="waktu" id="edit_waktu">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Pemimpin Rapat</label>
                            <input type="text" class="form-control" name="pemimpin_rapat" id="edit_pemimpin_rapat">
                        </div>
                        <div class="form-group col-md-6">
                            <label>Tempat</label>
                            <input type="text" class="form-control" name="tempat" id="edit_tempat">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Agenda Rapat (Poin-Poin Rencana Rapat)</label>
                        <textarea class="form-control" name="agenda_rapat" id="ck_agenda_edit" rows="4"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Notulensi Rapat (Catatan / Hasil Rapat)</label>
                        <textarea class="form-control" name="notulensi" id="ck_notulensi_edit" rows="4"></textarea>
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
<?php endif; ?>

<?php include '../templates/footer.php'; ?>
