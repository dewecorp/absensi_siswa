<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$page_title = 'Monitoring Tindak Lanjut';
$current_page = basename(__FILE__);
$can_manage = sv_is_supervisor($pdo);

$school_profile = getSchoolProfile($pdo);
$periode = sv_periode($pdo);

$css_libs = ['https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'];
$js_libs = [

    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
];

$tl_list = [];
try {
    $tl_list = $pdo->query("SELECT t.*, p.nilai AS nilai_awal FROM tb_sv_tindak_lanjut t
        LEFT JOIN tb_sv_pelaksanaan p ON p.id_pelaksanaan = t.id_pelaksanaan
        ORDER BY t.id_tindak_lanjut DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $aksi = (string)($_POST['aksi'] ?? '');
    try {
        if ($aksi === 'tambah' || $aksi === 'edit') {
            $id_tl = (int)($_POST['id_tindak_lanjut'] ?? 0);
            $id_guru = null;
            $nama_guru = '';
            $unit_bagian = '';
            foreach ($tl_list as $tl) {
                if ((int)$tl['id_tindak_lanjut'] === $id_tl) {
                    $id_guru = (int)$tl['id_guru'] ?: null;
                    $nama_guru = (string)$tl['nama_guru'];
                    $unit_bagian = (string)$tl['unit_bagian'];
                    break;
                }
            }
            $data = [
                $id_tl,
                $id_guru,
                $nama_guru,
                $unit_bagian,
                '',
                trim((string)($_POST['tindakan'] ?? '')),
                trim((string)($_POST['target_perbaikan'] ?? '')),
                trim((string)($_POST['tanggal_monitoring'] ?? '')) ?: null,
                (int)($_POST['monitoring_ke'] ?? 1),
                trim((string)($_POST['hasil_monitoring'] ?? '')),
                trim((string)($_POST['perubahan'] ?? '')),
                trim((string)($_POST['nilai_sebelum'] ?? '')) !== '' ? (float)$_POST['nilai_sebelum'] : null,
                trim((string)($_POST['nilai_sesudah'] ?? '')) !== '' ? (float)$_POST['nilai_sesudah'] : null,
                trim((string)($_POST['status'] ?? '')),
                trim((string)($_POST['catatan'] ?? '')),
                trim((string)($_POST['bukti'] ?? '')),
            ];
            if ($aksi === 'tambah') {
                $stmt = $pdo->prepare("INSERT INTO tb_sv_monitoring
                    (id_tindak_lanjut, id_guru, nama_guru, unit_bagian, temuan_awal, tindakan, target_perbaikan, tanggal_monitoring,
                     monitoring_ke, hasil_monitoring, perubahan, nilai_sebelum, nilai_sesudah, status, catatan, bukti)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute($data);
                sv_log($pdo, 'Tambah Monitoring', 'TL ' . $id_tl);
                sv_flash('success', 'Monitoring berhasil ditambahkan.');
            } else {
                $id = (int)($_POST['id_monitoring'] ?? 0);
                $stmt = $pdo->prepare("UPDATE tb_sv_monitoring SET id_tindak_lanjut=?, id_guru=?, nama_guru=?, unit_bagian=?, temuan_awal=?, tindakan=?,
                    target_perbaikan=?, tanggal_monitoring=?, monitoring_ke=?, hasil_monitoring=?, perubahan=?, nilai_sebelum=?, nilai_sesudah=?, status=?, catatan=?, bukti=?
                    WHERE id_monitoring=?");
                $stmt->execute(array_merge($data, [$id]));
                sv_log($pdo, 'Edit Monitoring', 'ID ' . $id);
                sv_flash('success', 'Monitoring berhasil diperbarui.');
            }
        } elseif ($aksi === 'hapus') {
            $id = (int)($_POST['id_monitoring'] ?? 0);
            $pdo->prepare("DELETE FROM tb_sv_monitoring WHERE id_monitoring = ?")->execute([$id]);
            sv_log($pdo, 'Hapus Monitoring', 'ID ' . $id);
            sv_flash('success', 'Monitoring berhasil dihapus.');
        } elseif ($aksi === 'supervisi_ulang') {
            $id_tl = (int)($_POST['id_tindak_lanjut'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM tb_sv_tindak_lanjut WHERE id_tindak_lanjut = ? LIMIT 1");
            $stmt->execute([$id_tl]);
            $tl = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($tl) {
                $ins = $pdo->prepare("INSERT INTO tb_sv_jadwal
                    (id_guru, nama_guru, jenis_supervisi, supervisor, tanggal, tempat, fokus, status, keterangan)
                    VALUES (?,?,?,?,?,?,?, 'Terjadwal', ?)");
                $ins->execute([
                    $tl['id_guru'] ?: null,
                    $tl['nama_guru'] ?: ($tl['unit_bagian'] ?: ''),
                    'Akademik',
                    sv_current_user_name($pdo),
                    date('Y-m-d', strtotime('+7 days')),
                    '',
                    'Supervisi ulang: ' . ($tl['temuan'] ?: ''),
                    'Dibuat dari monitoring tindak lanjut #' . $id_tl,
                ]);
                $pdo->prepare("UPDATE tb_sv_tindak_lanjut SET status = 'Perlu Supervisi Ulang' WHERE id_tindak_lanjut = ?")->execute([$id_tl]);
                sv_log($pdo, 'Jadwalkan Supervisi Ulang', 'TL ' . $id_tl);
                sv_flash('success', 'Supervisi ulang berhasil dijadwalkan (7 hari dari sekarang).');
            }
        }
    } catch (Throwable $e) {
        sv_flash('danger', 'Gagal memproses data: ' . $e->getMessage());
    }
    redirect('monitoring_tindak_lanjut.php');
}

$rows = [];
try {
    $rows = $pdo->query("SELECT m.*, t.bentuk_tindak_lanjut, t.status AS status_tl
        FROM tb_sv_monitoring m
        LEFT JOIN tb_sv_tindak_lanjut t ON t.id_tindak_lanjut = m.id_tindak_lanjut
        ORDER BY m.tanggal_monitoring DESC, m.id_monitoring DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$js_page = [];
$flash = sv_render_flash_js();
if ($flash !== '') {
    $js_page[] = $flash;
}
$js_page[] = <<<'JS'
$(document).ready(function () {
    var dttablemonitoring=$('#table-monitoring').DataTable({language:{search:'Cari:',lengthMenu:'Tampilkan _MENU_ data',info:'Menampilkan _START_ sampai _END_ dari _TOTAL_ data',infoEmpty:'Tidak ada data',zeroRecords:'Data tidak ditemukan',paginate:{first:'Awal',last:'Akhir',next:'Berikutnya',previous:'Sebelumnya'}},pageLength:10,order:[],columnDefs:[],responsive:false});dttablemonitoring.on('order.dt search.dt draw.dt',function(){var info=dttablemonitoring.page.info();dttablemonitoring.column(0,{search:'applied',order:'applied'}).nodes().each(function(cell,i){if(cell) cell.innerHTML=info.page*info.length+i+1;});}).draw();
    $('#btn-tambah').on('click', function () {
        $('#form-monitoring')[0].reset();
        $('#form-monitoring [name=aksi]').val('tambah');
        $('#form-monitoring [name=id_monitoring]').val('');
        $('#modal-monitoring .modal-title').text('Tambah Monitoring');
        $('#modal-monitoring').modal('show');
    });
    $(document).on('click', '.btn-edit', function () {
        var d = $(this).data('row');
        $('#form-monitoring')[0].reset();
        $('#form-monitoring [name=aksi]').val('edit');
        Object.keys(d).forEach(function (k) {
            var el = $('#form-monitoring [name="' + k + '"]');
            if (el.length) { el.val(d[k]); }
        });
        $('#modal-monitoring .modal-title').text('Edit Monitoring');
        $('#modal-monitoring').modal('show');
    });
    $(document).on('click', '.btn-hapus', function () {
        var id = $(this).data('id');
        Swal.fire({title:'Konfirmasi Hapus',text:'Data monitoring akan dihapus.',icon:'warning',showCancelButton:true,confirmButtonColor:'#d33',cancelButtonColor:'#6c757d',confirmButtonText:'Ya, Hapus!',cancelButtonText:'Batal'}).then(function(r){if(r.isConfirmed){var f=document.createElement('form');f.method='POST';f.action='monitoring_tindak_lanjut.php';var fields={aksi: 'hapus', id_monitoring: id};Object.keys(fields).forEach(function(k){var i=document.createElement('input');i.type='hidden';i.name=k;i.value=fields[k];f.appendChild(i);});document.body.appendChild(f);f.submit();}})
    });
    $(document).on('click', '.btn-ulang', function () {
        var id = $(this).data('id');
        Swal.fire({title:'Jadwalkan Supervisi Ulang',text:'Buat jadwal supervisi ulang untuk tindak lanjut ini?',icon:'warning',showCancelButton:true,confirmButtonColor:'#d33',cancelButtonColor:'#6c757d',confirmButtonText:'Ya, Hapus!',cancelButtonText:'Batal'}).then(function(r){if(r.isConfirmed){var f=document.createElement('form');f.method='POST';f.action='monitoring_tindak_lanjut.php';var fields={aksi: 'supervisi_ulang', id_tindak_lanjut: id};Object.keys(fields).forEach(function(k){var i=document.createElement('input');i.type='hidden';i.name=k;i.value=fields[k];f.appendChild(i);});document.body.appendChild(f);f.submit();}})
    });
    $('#btn-excel').on('click', function () { var table=document.getElementById('table-monitoring');if(!table) return;if(typeof XLSX!=='undefined'){var clone=table.cloneNode(true);for(var i=0;i<clone.rows.length;i++){if(clone.rows[i].cells.length>0) clone.rows[i].deleteCell(-1);}var wb=XLSX.utils.table_to_book(clone,{sheet:"Sheet1"});XLSX.writeFile(wb,'monitoring_tindak_lanjut.xlsx');}else{var clone=table.cloneNode(true);for(var i=0;i<clone.rows.length;i++){if(clone.rows[i].cells.length>0) clone.rows[i].deleteCell(-1);}var html='<table border="1">'+clone.innerHTML+'</table>';var a=document.createElement('a');a.href='data:application/vnd.ms-excel;charset=utf-8,'+encodeURIComponent(html);a.download='monitoring_tindak_lanjut.xls';a.click();}; });
    $('#btn-pdf').on('click', function () { window.open('cetak_supervisi.php?page=monitoring', '_blank'); });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Monitoring Tindak Lanjut</h1>
            <?php echo render_breadcrumb(); ?>
        </div>
        <div class="section-body">
            <input type="hidden" id="svSchoolName" value="<?= htmlspecialchars($school_profile['nama_madrasah'] ?? 'MADRASAH', ENT_QUOTES) ?>">
            <input type="hidden" id="svSchoolLogo" value="<?= !empty($school_profile['logo']) ? '../assets/img/' . htmlspecialchars($school_profile['logo'], ENT_QUOTES) : '' ?>">
            <input type="hidden" id="svAcademicYear" value="<?= htmlspecialchars($periode['tahun_ajaran'], ENT_QUOTES) ?>">
            <input type="hidden" id="svSemester" value="<?= htmlspecialchars($periode['semester'], ENT_QUOTES) ?>">
            <input type="hidden" id="svHeadName" value="<?= htmlspecialchars($school_profile['nama_kepala'] ?? '-', ENT_QUOTES) ?>">
            <input type="hidden" id="svHeadNip" value="<?= htmlspecialchars($school_profile['nip_kepala'] ?? '-', ENT_QUOTES) ?>">
            <input type="hidden" id="svPrintPlace" value="<?= htmlspecialchars($school_profile['tempat_jadwal'] ?? 'Padang', ENT_QUOTES) ?>">
            <input type="hidden" id="svPrintDate" value="<?= date('d F Y') ?>">

            <div class="card">
                <div class="card-header">
                    <h4>Daftar Monitoring</h4>
                    <div class="card-header-action">
                        <button class="btn btn-success" id="btn-excel" type="button"><i class="fas fa-file-excel"></i> Excel</button>
                        <button class="btn btn-warning" id="btn-pdf" type="button"><i class="fas fa-file-pdf"></i> PDF</button>
                        <?php if ($can_manage): ?>
                        <button class="btn btn-primary" id="btn-tambah" type="button"><i class="fas fa-plus"></i> Tambah</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-monitoring">
                            <thead>
                                <tr>
                                    <th width="5%">No</th>
                                    <th>Tindak Lanjut</th>
                                    <th>Guru/Unit</th>
                                    <th>Tindakan</th>
                                    <th>Target Perbaikan</th>
                                    <th>Tanggal</th>
                                    <th>Ke</th>
                                    <th>Hasil Monitoring</th>
                                    <th>Perubahan</th>
                                    <th>Nilai Sebelum</th>
                                    <th>Nilai Sesudah</th>
                                    <th>Status</th>
                                    <th>Catatan</th>
                                    <th>Bukti</th>
                                    <?php if ($can_manage): ?><th width="12%">Aksi</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="text-center"></td>
                                        <td>#<?= (int)$r['id_tindak_lanjut'] ?> - <?= htmlspecialchars((string)$r['bentuk_tindak_lanjut']) ?></td>
                                        <td><?= htmlspecialchars($r['nama_guru'] ?: ($r['unit_bagian'] ?: '-')) ?></td>
                                        <td><?= htmlspecialchars((string)$r['tindakan']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['target_perbaikan']) ?></td>
                                        <td><?= $r['tanggal_monitoring'] ? date('d/m/Y', strtotime($r['tanggal_monitoring'])) : '-' ?></td>
                                        <td><?= (int)$r['monitoring_ke'] ?></td>
                                        <td><?= htmlspecialchars((string)$r['hasil_monitoring']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['perubahan']) ?></td>
                                        <td><?= $r['nilai_sebelum'] !== null ? htmlspecialchars(number_format((float)$r['nilai_sebelum'], 2)) : '-' ?></td>
                                        <td><?= $r['nilai_sesudah'] !== null ? htmlspecialchars(number_format((float)$r['nilai_sesudah'], 2)) : '-' ?></td>
                                        <td><?= htmlspecialchars((string)$r['status']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['catatan']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['bukti']) ?></td>
                                        <?php if ($can_manage): ?>
                                        <td>
                                            <button class="btn btn-warning btn-sm btn-edit" type="button" data-row='<?= htmlspecialchars(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>'><i class="fas fa-edit"></i></button>
                                            <button class="btn btn-info btn-sm btn-ulang" type="button" data-id="<?= (int)$r['id_tindak_lanjut'] ?>" title="Jadwalkan Supervisi Ulang"><i class="fas fa-redo"></i></button>
                                            <button class="btn btn-danger btn-sm btn-hapus" type="button" data-id="<?= (int)$r['id_monitoring'] ?>"><i class="fas fa-trash"></i></button>
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
<div class="modal fade" id="modal-monitoring" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" action="monitoring_tindak_lanjut.php" id="form-monitoring">
                <input type="hidden" name="aksi" value="tambah">
                <input type="hidden" name="id_monitoring" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Monitoring</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Tindak Lanjut</label>
                        <select class="form-control" name="id_tindak_lanjut" required>
                            <option value="">Pilih Tindak Lanjut</option>
                            <?php foreach ($tl_list as $tl): ?>
                                <option value="<?= (int)$tl['id_tindak_lanjut'] ?>">
                                    #<?= (int)$tl['id_tindak_lanjut'] ?> - <?= htmlspecialchars($tl['nama_guru'] ?: ($tl['unit_bagian'] ?: '-')) ?> (<?= htmlspecialchars((string)$tl['bentuk_tindak_lanjut']) ?>, <?= htmlspecialchars($tl['status']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Tindakan</label><textarea class="form-control" name="tindakan" rows="2"></textarea></div>
                    <div class="form-group"><label>Target Perbaikan</label><input type="text" class="form-control" name="target_perbaikan"></div>
                    <div class="form-row">
                        <div class="form-group col-md-4"><label>Tanggal Monitoring</label><input type="date" class="form-control" name="tanggal_monitoring"></div>
                        <div class="form-group col-md-4"><label>Monitoring Ke</label><input type="number" min="1" class="form-control" name="monitoring_ke" value="1"></div>
                        <div class="form-group col-md-4"><label>Status</label><input type="text" class="form-control" name="status" placeholder="cth: Tercapai / Belum Tercapai"></div>
                    </div>
                    <div class="form-group"><label>Hasil Monitoring</label><textarea class="form-control" name="hasil_monitoring" rows="2"></textarea></div>
                    <div class="form-group"><label>Perubahan</label><input type="text" class="form-control" name="perubahan"></div>
                    <div class="form-row">
                        <div class="form-group col-md-6"><label>Nilai Sebelum</label><input type="number" step="0.01" class="form-control" name="nilai_sebelum"></div>
                        <div class="form-group col-md-6"><label>Nilai Sesudah</label><input type="number" step="0.01" class="form-control" name="nilai_sesudah"></div>
                    </div>
                    <div class="form-group"><label>Catatan</label><textarea class="form-control" name="catatan" rows="2"></textarea></div>
                    <div class="form-group"><label>Bukti</label><input type="text" class="form-control" name="bukti"></div>
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
<?php include '../templates/footer.php'; ?>
