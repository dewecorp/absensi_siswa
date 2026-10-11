<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$page_title = 'Data Monitoring';
$current_page = basename(__FILE__);
$can_manage = sv_is_supervisor($pdo);

$kategori_list = sv_monitoring_kategori_list();
$filter_kategori = trim((string)($_GET['kategori'] ?? ''));
if ($filter_kategori !== '' && !array_key_exists($filter_kategori, $kategori_list)) {
    $filter_kategori = '';
}

$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    try {
        if (isset($_POST['add_master'])) {
            $kategori = trim((string)($_POST['kategori'] ?? ''));
            $teks = trim((string)($_POST['teks'] ?? ''));
            if ($kategori === '' || $teks === '' || !array_key_exists($kategori, $kategori_list)) {
                $message = ['type' => 'danger', 'text' => 'Kategori dan isi wajib diisi dengan benar.'];
            } else {
                $chk = $pdo->prepare("SELECT id_master FROM tb_sv_hasil_master WHERE kategori = ? AND teks = ? LIMIT 1");
                $chk->execute([$kategori, $teks]);
                if ($chk->fetch()) {
                    $message = ['type' => 'danger', 'text' => 'Data sudah ada.'];
                } else {
                    $max = $pdo->prepare("SELECT COALESCE(MAX(urutan),0) FROM tb_sv_hasil_master WHERE kategori = ?");
                    $max->execute([$kategori]);
                    $stmt = $pdo->prepare("INSERT INTO tb_sv_hasil_master (kategori, teks, urutan, is_aktif) VALUES (?,?,?,1)");
                    $stmt->execute([$kategori, $teks, (int)$max->fetchColumn() + 1]);
                    sv_log($pdo, 'Tambah Data Monitoring', "$kategori: $teks");
                    $message = ['type' => 'success', 'text' => 'Data berhasil ditambahkan.'];
                }
            }
        } elseif (isset($_POST['update_master'])) {
            $id = (int)($_POST['id_master'] ?? 0);
            $kategori = trim((string)($_POST['kategori'] ?? ''));
            $teks = trim((string)($_POST['teks'] ?? ''));
            if ($id <= 0 || $kategori === '' || $teks === '' || !array_key_exists($kategori, $kategori_list)) {
                $message = ['type' => 'danger', 'text' => 'Data tidak valid.'];
            } else {
                $stmt = $pdo->prepare("UPDATE tb_sv_hasil_master SET kategori = ?, teks = ? WHERE id_master = ?");
                $stmt->execute([$kategori, $teks, $id]);
                sv_log($pdo, 'Edit Data Monitoring', "ID $id");
                $message = ['type' => 'success', 'text' => 'Data berhasil diperbarui.'];
            }
        } elseif (isset($_POST['toggle_master'])) {
            $id = (int)($_POST['id_master'] ?? 0);
            $pdo->prepare("UPDATE tb_sv_hasil_master SET is_aktif = 1 - is_aktif WHERE id_master = ?")->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Status diperbarui.'];
        } elseif (isset($_POST['delete_master'])) {
            $id = (int)($_POST['id_master'] ?? 0);
            $pdo->prepare("DELETE FROM tb_sv_hasil_master WHERE id_master = ?")->execute([$id]);
            sv_log($pdo, 'Hapus Data Monitoring', "ID $id");
            $message = ['type' => 'success', 'text' => 'Data berhasil dihapus.'];
        } elseif (isset($_POST['seed_master'])) {
            sv_seed_hasil_master($pdo);
            $message = ['type' => 'success', 'text' => 'Template bawaan berhasil dimuat.'];
        }
    } catch (Throwable $e) {
        $message = ['type' => 'danger', 'text' => 'Gagal memproses: ' . $e->getMessage()];
    }
    if ($message && ($message['type'] ?? '') === 'success') {
        sv_flash($message['type'], $message['text']);
        redirect('data_monitoring.php' . ($filter_kategori !== '' ? '?kategori=' . urlencode($filter_kategori) : ''));
    }
}

$rows = [];
try {
    if ($filter_kategori !== '') {
        $st = $pdo->prepare("SELECT * FROM tb_sv_hasil_master WHERE kategori = ? ORDER BY urutan ASC, teks ASC");
        $st->execute([$filter_kategori]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $cats = implode(',', array_map(function ($k) use ($pdo) { return $pdo->quote($k); }, array_keys($kategori_list)));
        $rows = $pdo->query("SELECT * FROM tb_sv_hasil_master WHERE kategori IN ($cats) ORDER BY kategori ASC, urutan ASC, teks ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
}

$css_libs = ['https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];
$js_page = [];
$flash = sv_render_flash_js();
if ($flash !== '') {
    $js_page[] = $flash;
}
if ($message && ($message['type'] ?? '') !== 'success') {
    $swal_icon = ($message['type'] ?? '') === 'danger' ? 'error' : 'warning';
    $js_page[] = "Swal.fire({icon:'{$swal_icon}',title:'Gagal',text:" . json_encode((string)($message['text'] ?? ''), JSON_UNESCAPED_UNICODE) . ",timer:3000,showConfirmButton:false});";
}
$js_page[] = <<<'JS'
$(document).ready(function () {
    if ($.fn.DataTable.isDataTable('#table-monitoring-master')) { $('#table-monitoring-master').DataTable().destroy(); }
    var t = $('#table-monitoring-master').DataTable({
        paging: true, lengthChange: true, pageLength: 10,
        lengthMenu: [[10,25,50,100,-1],[10,25,50,100,'Semua']],
        language: { lengthMenu: "Tampilkan _MENU_ entri", zeroRecords: "Tidak ada data", info: "Menampilkan _START_ sampai _END_ dari _TOTAL_ entri", infoEmpty: "Menampilkan 0 sampai 0 dari 0 entri", infoFiltered: "(disaring dari _MAX_ total)", search: "Cari:", paginate: { first: "Pertama", last: "Terakhir", next: "Selanjutnya", previous: "Sebelumnya" } }
    });
    t.on('draw', function () { var info = t.page.info(); $('#table-monitoring-master tbody tr').each(function (i) { $(this).find('td:first').text(info.start + i + 1); }); });
    t.draw();
    $(document).on('click', '.btn-edit', function () {
        $('#edit_id_master').val($(this).data('id'));
        $('#edit_kategori').val($(this).data('kategori'));
        $('#edit_teks').val($(this).data('teks'));
        $('#editModal').modal('show');
    });
    $(document).on('click', '.btn-hapus', function (e) {
        e.preventDefault();
        var id = $(this).data('id');
        Swal.fire({ title: 'Konfirmasi Hapus', text: 'Hapus data master ini?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#3085d6', cancelButtonColor: '#d33', confirmButtonText: 'Ya, Hapus!', cancelButtonText: 'Batal' }).then(function (r) {
            if (r.isConfirmed) { var f = $('<form method="POST"><input type="hidden" name="id_master" value="' + id + '"><input type="hidden" name="delete_master" value="1"></form>'); $('body').append(f); f.submit(); }
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
            <h1>Data Monitoring</h1>
            <?php echo render_breadcrumb(); ?>
        </div>
        <div class="section-body">
            <div class="card">
                <div class="card-body">
                    <form method="GET" class="form-row align-items-end">
                        <div class="form-group col-md-5 mb-2">
                            <label class="small font-weight-bold">Kategori</label>
                            <select class="form-control" name="kategori" onchange="this.form.submit()">
                                <option value="">Semua Kategori</option>
                                <?php foreach ($kategori_list as $k => $l): ?><option value="<?= htmlspecialchars($k, ENT_QUOTES) ?>" <?= $k === $filter_kategori ? 'selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-7 mb-2">
                            <a href="data_monitoring.php" class="btn btn-light">Reset</a>
                            <a href="monitoring_tindak_lanjut.php" class="btn btn-secondary ml-1"><i class="fas fa-arrow-left"></i> Monitoring</a>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card">
                <div class="card-header">
                    <h4>Master Tindakan / Target / Hasil / Perubahan</h4>
                    <div class="card-header-action">
                        <?php if ($can_manage): ?>
                        <form method="POST" class="d-inline"><button type="submit" name="seed_master" value="1" class="btn btn-info btn-sm"><i class="fas fa-database"></i> Muat Template</button></form>
                        <button type="button" class="btn btn-primary btn-sm ml-1" data-toggle="modal" data-target="#addModal"><i class="fas fa-plus"></i> Tambah</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-monitoring-master">
                            <thead><tr><th width="6%">No</th><th width="18%">Kategori</th><th>Isi</th><th width="12%">Status</th><?php if ($can_manage): ?><th width="20%">Aksi</th><?php endif; ?></tr></thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td class="text-center"></td>
                                    <td><span class="badge badge-info"><?= htmlspecialchars($kategori_list[$r['kategori']] ?? $r['kategori']) ?></span></td>
                                    <td><?= nl2br(htmlspecialchars($r['teks'])) ?></td>
                                    <td><?= ((int)$r['is_aktif'] === 1) ? '<span class="badge badge-success">Aktif</span>' : '<span class="badge badge-secondary">Nonaktif</span>' ?></td>
                                    <?php if ($can_manage): ?>
                                    <td>
                                        <button class="btn btn-warning btn-sm btn-edit" data-id="<?= (int)$r['id_master'] ?>" data-kategori="<?= htmlspecialchars($r['kategori'], ENT_QUOTES) ?>" data-teks="<?= htmlspecialchars($r['teks'], ENT_QUOTES) ?>"><i class="fas fa-edit"></i></button>
                                        <form method="POST" class="d-inline"><input type="hidden" name="id_master" value="<?= (int)$r['id_master'] ?>"><button type="submit" name="toggle_master" value="1" class="btn btn-sm <?= ((int)$r['is_aktif']===1)?'btn-secondary':'btn-success' ?>"><i class="fas fa-toggle-<?= ((int)$r['is_aktif']===1)?'off':'on' ?>"></i></button></form>
                                        <button class="btn btn-danger btn-sm btn-hapus" data-id="<?= (int)$r['id_master'] ?>"><i class="fas fa-trash"></i></button>
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
    </section>
</div>
<?php if ($can_manage): ?>
<div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Tambah Data Monitoring</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="add_master" value="1">
                    <div class="form-group"><label>Kategori</label><select class="form-control" name="kategori" required><option value="">Pilih</option><?php foreach ($kategori_list as $k => $l): ?><option value="<?= htmlspecialchars($k, ENT_QUOTES) ?>"><?= htmlspecialchars($l) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Isi</label><textarea class="form-control" name="teks" rows="3" required placeholder="Tulis tindakan / target / hasil / perubahan..."></textarea></div>
                </div>
                <div class="modal-footer bg-whitesmoke br"><button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button><button type="submit" class="btn btn-primary">Simpan</button></div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Edit Data Monitoring</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="id_master" id="edit_id_master"><input type="hidden" name="update_master" value="1">
                    <div class="form-group"><label>Kategori</label><select class="form-control" name="kategori" id="edit_kategori" required><?php foreach ($kategori_list as $k => $l): ?><option value="<?= htmlspecialchars($k, ENT_QUOTES) ?>"><?= htmlspecialchars($l) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Isi</label><textarea class="form-control" name="teks" id="edit_teks" rows="3" required></textarea></div>
                </div>
                <div class="modal-footer bg-whitesmoke br"><button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button><button type="submit" class="btn btn-primary">Update</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php include '../templates/footer.php'; ?>
