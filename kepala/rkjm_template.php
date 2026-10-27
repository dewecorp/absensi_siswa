<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/rkjm_helper.php';

if (session_status() == PHP_SESSION_NONE) session_start();
if (!isAuthorized(['admin', 'kepala_madrasah'])) redirect('../login.php');

$user_level = getUserLevel();
$is_editable = in_array($user_level, ['admin', 'kepala_madrasah'], true);
$page_title = 'Kelola Template RKJM';

rkjmEnsureSchema($pdo);
$fields = rkjmFields($pdo);
$csrf = appCsrfToken();

$q = trim((string)($_GET['q'] ?? ''));
$f_field = (int)($_GET['bidang'] ?? 0);
$f_active = (string)($_GET['aktif'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;

$where = [];
$params = [];
if ($q !== '') {
    $where[] = "(kode LIKE ? OR nama_program LIKE ? OR indikator LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if ($f_field > 0) {
    $where[] = "field_id = ?";
    $params[] = $f_field;
}
if ($f_active === '1' || $f_active === '0') {
    $where[] = "is_active = ?";
    $params[] = (int)$f_active;
}
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT COUNT(*) FROM rkjm_template_programs $where_sql");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

$stmt = $pdo->prepare("SELECT t.*,
        (SELECT COUNT(*) FROM rkjm_programs p WHERE p.template_id = t.id) AS used_count
        FROM rkjm_template_programs t $where_sql
        ORDER BY t.bidang_nama ASC, t.kode ASC LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_active = (int)$pdo->query("SELECT COUNT(*) FROM rkjm_template_programs WHERE is_active = 1")->fetchColumn();
$total_all = (int)$pdo->query("SELECT COUNT(*) FROM rkjm_template_programs")->fetchColumn();

function rkjm_tpl_qs(array $override = []): string
{
    $params = array_merge($_GET, $override);
    foreach ($params as $k => $v) { if ($v === '' || $v === null) unset($params[$k]); }
    $qs = http_build_query($params);
    return $qs !== '' ? '?' . $qs : '';
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<style>
.rkjm-table th{font-size:.75rem;text-transform:uppercase;color:#6b7391;white-space:nowrap}
.rkjm-table td{font-size:.86rem;vertical-align:middle}
.rkjm-section-title{font-weight:700;color:#3b4266;font-size:.85rem;text-transform:uppercase;letter-spacing:.04em;margin:18px 0 10px;padding-bottom:6px;border-bottom:1px solid #eceff7}
.rkjm-modal textarea{min-height:70px;resize:vertical}
.rkjm-clip{max-width:320px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
</style>
<div class="main-content">
<section class="section">
    <div class="section-header">
        <h1>Kelola Template RKJM</h1>
        <div class="section-header-breadcrumb">
            <div class="breadcrumb-item active"><a href="<?= htmlspecialchars(app_url('kepala/dashboard.php')) ?>">Dashboard</a></div>
            <div class="breadcrumb-item">Program Kerja</div>
            <div class="breadcrumb-item"><a href="rkjm.php">RKJM</a></div>
            <div class="breadcrumb-item">Kelola Template</div>
        </div>
    </div>
    <div class="section-body">
        <div class="card">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
                <div>
                    <h4 class="mb-1">Template Program RKJM</h4>
                    <div class="text-muted small"><?= $total_active ?> aktif dari <?= $total_all ?> template. Template hanya menjadi data awal; perubahan template tidak mengubah program RKJM yang sudah disalin.</div>
                </div>
                <div class="mt-2 mt-md-0">
                    <a href="rkjm.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left mr-1"></i>Kembali ke RKJM</a>
                    <?php if ($is_editable): ?>
                        <button type="button" class="btn btn-primary btn-sm" id="btn-add-template"><i class="fas fa-plus mr-1"></i>Tambah Template</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <form method="GET" class="mb-3">
                    <div class="row">
                        <div class="col-12 col-md-5 mb-2"><input type="search" name="q" class="form-control" placeholder="Cari kode, nama program, atau indikator..." value="<?= htmlspecialchars($q) ?>"></div>
                        <div class="col-6 col-md-3 mb-2">
                            <select name="bidang" class="form-control">
                                <option value="">Semua Bidang</option>
                                <?php foreach ($fields as $f): ?>
                                    <option value="<?= (int)$f['id'] ?>" <?= $f_field === (int)$f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['nama']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-2 mb-2">
                            <select name="aktif" class="form-control">
                                <option value="">Semua Status</option>
                                <option value="1" <?= $f_active === '1' ? 'selected' : '' ?>>Aktif</option>
                                <option value="0" <?= $f_active === '0' ? 'selected' : '' ?>>Nonaktif</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-2 mb-2"><button class="btn btn-primary btn-block" type="submit"><i class="fas fa-search mr-1"></i>Cari</button></div>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-hover rkjm-table">
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Kode</th>
                                <th>Bidang</th>
                                <th>Nama Program</th>
                                <th>Indikator Utama</th>
                                <th>Dipakai</th>
                                <th>Status</th>
                                <th class="text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$rows): ?>
                                <tr><td colspan="8" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x d-block mb-2"></i>Tidak ada template pada filter ini.</td></tr>
                            <?php endif; ?>
                            <?php $no = $offset; foreach ($rows as $r): $no++; ?>
                                <tr>
                                    <td><?= $no ?></td>
                                    <td><code><?= htmlspecialchars($r['kode']) ?></code></td>
                                    <td><span class="badge badge-light border"><?= htmlspecialchars($r['bidang_nama'] ?: '-') ?></span></td>
                                    <td><div style="min-width:180px;font-weight:600"><?= htmlspecialchars($r['nama_program']) ?></div></td>
                                    <td><div class="rkjm-clip" title="<?= htmlspecialchars((string)$r['indikator']) ?>"><?= htmlspecialchars($r['indikator'] ?: '-') ?></div></td>
                                    <td><span class="badge badge-info"><?= (int)$r['used_count'] ?> program</span></td>
                                    <td>
                                        <?php if ($r['is_active']): ?><span class="badge badge-success">Aktif</span><?php else: ?><span class="badge badge-secondary">Nonaktif</span><?php endif; ?>
                                    </td>
                                    <td class="text-right text-nowrap">
                                        <?php if ($is_editable): ?>
                                            <button type="button" class="btn btn-sm btn-light btn-tpl-edit" data-id="<?= (int)$r['id'] ?>" title="Edit"><i class="fas fa-edit"></i></button>
                                            <button type="button" class="btn btn-sm btn-light btn-tpl-toggle" data-id="<?= (int)$r['id'] ?>" data-active="<?= (int)$r['is_active'] ?>" title="<?= $r['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>"><i class="fas <?= $r['is_active'] ? 'fa-toggle-on text-success' : 'fa-toggle-off text-muted' ?>"></i></button>
                                            <button type="button" class="btn btn-sm btn-light text-danger btn-tpl-delete" data-id="<?= (int)$r['id'] ?>" data-name="<?= htmlspecialchars($r['nama_program'], ENT_QUOTES) ?>" data-used="<?= (int)$r['used_count'] ?>" title="Hapus"><i class="fas fa-trash"></i></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex flex-wrap justify-content-between align-items-center">
                    <div class="text-muted small mb-2">Total <?= $total ?> template</div>
                    <?php if ($total_pages > 1): ?>
                        <nav class="mb-2"><ul class="pagination pagination-sm mb-0">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= htmlspecialchars(rkjm_tpl_qs(['page' => $i])) ?>"><?= $i ?></a></li>
                            <?php endfor; ?>
                        </ul></nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
</div>

<div class="modal fade" id="templateFormModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
        <div class="modal-content rkjm-modal">
            <form id="templateForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="templateModalTitle">Tambah Template RKJM</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" value="0">
                    <div class="rkjm-section-title">Identitas Template</div>
                    <div class="row">
                        <div class="col-md-5 mb-2"><label>Bidang Pengembangan</label>
                            <select class="form-control" name="field_id" id="tpl_field">
                                <option value="">-- Pilih Bidang --</option>
                                <?php foreach ($fields as $f): ?>
                                    <option value="<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['nama']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-7 mb-2"><label>Atau Bidang Baru</label><input type="text" class="form-control" name="bidang_baru" placeholder="Isi jika bidang belum tersedia"></div>
                        <div class="col-md-3 mb-2"><label>Kode Template</label><input type="text" class="form-control" name="kode" placeholder="Otomatis jika kosong"></div>
                        <div class="col-md-7 mb-2"><label>Nama Program</label><input type="text" class="form-control" name="nama_program" required></div>
                        <div class="col-md-2 mb-2"><label>Status</label>
                            <select class="form-control" name="is_active"><option value="1">Aktif</option><option value="0">Nonaktif</option></select>
                        </div>
                    </div>

                    <div class="rkjm-section-title">Kondisi, Tujuan, dan Indikator</div>
                    <div class="row">
                        <div class="col-md-6 mb-2"><label>Latar Belakang / Kondisi Awal</label><textarea class="form-control" name="latar_belakang" rows="2"></textarea></div>
                        <div class="col-md-6 mb-2"><label>Permasalahan</label><textarea class="form-control" name="permasalahan" rows="2"></textarea></div>
                        <div class="col-md-6 mb-2"><label>Tujuan</label><textarea class="form-control" name="tujuan" rows="2"></textarea></div>
                        <div class="col-md-6 mb-2"><label>Sasaran</label><textarea class="form-control" name="sasaran" rows="2"></textarea></div>
                        <div class="col-md-6 mb-2"><label>Indikator Keberhasilan</label><textarea class="form-control" name="indikator" rows="2"></textarea></div>
                        <div class="col-md-6 mb-2"><label>Target Akhir Periode</label><textarea class="form-control" name="target_akhir" rows="2"></textarea></div>
                    </div>

                    <div class="rkjm-section-title">Target Tahunan (contoh)</div>
                    <div class="row">
                        <div class="col-md-6 mb-2"><label>Tahun 1</label><textarea class="form-control" name="target_tahun1" rows="2"></textarea></div>
                        <div class="col-md-6 mb-2"><label>Tahun 2</label><textarea class="form-control" name="target_tahun2" rows="2"></textarea></div>
                        <div class="col-md-6 mb-2"><label>Tahun 3</label><textarea class="form-control" name="target_tahun3" rows="2"></textarea></div>
                        <div class="col-md-6 mb-2"><label>Tahun 4</label><textarea class="form-control" name="target_tahun4" rows="2"></textarea></div>
                    </div>

                    <div class="rkjm-section-title">Kegiatan dan Pembiayaan</div>
                    <div class="row">
                        <div class="col-12 mb-2"><label>Rincian Kegiatan Utama</label><textarea class="form-control" name="kegiatan_utama" rows="2"></textarea></div>
                        <div class="col-md-4 mb-2"><label>Estimasi Anggaran (Rp)</label><input type="text" class="form-control rkjm-rupiah" name="estimasi_anggaran" placeholder="0"></div>
                        <div class="col-md-4 mb-2"><label>Sumber Dana</label><input type="text" class="form-control" name="sumber_dana"></div>
                        <div class="col-md-4 mb-2"><label>Dokumen/Bukti</label><input type="text" class="form-control" name="dokumen_bukti"></div>
                        <div class="col-md-4 mb-2"><label>Penanggung Jawab</label><input type="text" class="form-control" name="penanggung_jawab"></div>
                        <div class="col-md-8 mb-2"><label>Metode Monitoring dan Evaluasi</label><input type="text" class="form-control" name="monitoring_evaluasi"></div>
                        <div class="col-12 mb-2"><label>Catatan Tambahan</label><textarea class="form-control" name="catatan" rows="2"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Simpan Template</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
<script>
(function(){
    var CSRF = <?= json_encode($csrf) ?>;
    var AJAX = 'rkjm_ajax.php';

    function post(action, data){
        return $.ajax({url: AJAX, type:'POST', data: Object.assign({action: action, csrf_token: CSRF}, data||{}), dataType:'json', headers:{'X-Requested-With':'XMLHttpRequest','X-CSRF-Token':CSRF}});
    }
    function swalOk(msg){ Swal.fire({icon:'success', title:msg, timer:1800, showConfirmButton:false}); }
    function swalErr(msg){ Swal.fire({icon:'error', title:'Gagal', text: msg || 'Terjadi kesalahan'}); }
    function reload(){ setTimeout(function(){ location.reload(); }, 600); }
    function fmtRupiah(v){ var s=(v+'').replace(/\D/g,'').replace(/^0+(?=\d)/,''); return s ? s.replace(/\B(?=(\d{3})+(?!\d))/g,'.') : ''; }

    $(document).on('input', 'input.rkjm-rupiah', function(){ this.value = fmtRupiah(this.value); });

    function fillTemplate(t){
        var f = $('#templateForm');
        f[0].reset();
        f.find('[name=id]').val(t.id || 0);
        f.find('[name=field_id]').val(t.field_id || '');
        f.find('[name=kode]').val(t.kode || '');
        f.find('[name=nama_program]').val(t.nama_program || '');
        f.find('[name=is_active]').val(String(t.is_active !== undefined ? t.is_active : 1));
        ['latar_belakang','permasalahan','tujuan','sasaran','indikator','target_akhir','target_tahun1','target_tahun2','target_tahun3','target_tahun4','kegiatan_utama','penanggung_jawab','sumber_dana','dokumen_bukti','monitoring_evaluasi','catatan'].forEach(function(k){ f.find('[name='+k+']').val(t[k] || ''); });
        f.find('[name=estimasi_anggaran]').val(fmtRupiah(t.estimasi_anggaran || 0));
        f.find('[name=bidang_baru]').val('');
        $('#templateModalTitle').text(t.id ? 'Edit Template RKJM' : 'Tambah Template RKJM');
    }

    $('#btn-add-template').on('click', function(){ fillTemplate({}); $('#templateFormModal').modal('show'); });

    $(document).on('click', '.btn-tpl-edit', function(){
        var id = $(this).data('id');
        post('template_get', {id: id}).done(function(res){
            if (res.success) { fillTemplate(res.data); $('#templateFormModal').modal('show'); }
            else swalErr(res.message);
        }).fail(function(){ swalErr('Gagal memuat template'); });
    });

    $('#templateForm').on('submit', function(e){
        e.preventDefault();
        var $btn = $(this).find('button[type=submit]').prop('disabled', true);
        post('template_save', $(this).serialize()).done(function(res){
            $btn.prop('disabled', false);
            if (res.success) { $('#templateFormModal').modal('hide'); swalOk(res.message || 'Template disimpan'); reload(); }
            else swalErr(res.message);
        }).fail(function(xhr){ $btn.prop('disabled', false); swalErr((xhr.responseJSON && xhr.responseJSON.message) || 'Gagal menyimpan template'); });
    });

    $(document).on('click', '.btn-tpl-toggle', function(){
        var id = $(this).data('id'), active = (parseInt($(this).data('active'), 10) ? 1 : 0);
        post('template_toggle', {id: id, is_active: active ? 0 : 1}).done(function(res){
            if (res.success) { swalOk(res.message); reload(); } else swalErr(res.message);
        }).fail(function(){ swalErr('Gagal mengubah status template'); });
    });

    $(document).on('click', '.btn-tpl-delete', function(){
        var id = $(this).data('id'), nm = $(this).data('name'), used = parseInt($(this).data('used'), 10) || 0;
        var text = 'Template "'+nm+'" akan dihapus.';
        if (used > 0) text += ' Template ini sudah dipakai pada '+used+' program RKJM. Program hasil salinan tetap tersimpan dan tidak ikut terhapus.';
        Swal.fire({title:'Hapus template?', text:text, icon:'warning', showCancelButton:true, confirmButtonColor:'#d33', confirmButtonText:'Ya, Hapus', cancelButtonText:'Batal'}).then(function(r){
            if (!r.isConfirmed) return;
            post('template_delete', {id: id}).done(function(res){
                if (res.success) { swalOk(res.message); reload(); } else swalErr(res.message);
            }).fail(function(){ swalErr('Gagal menghapus template'); });
        });
    });
})();
</script>
