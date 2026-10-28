<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/rkt_helper.php';

if (session_status() == PHP_SESSION_NONE) session_start();
if (!isAuthorized(['admin', 'kepala_madrasah'])) redirect('../login.php');

$user_level = getUserLevel();
$is_editable = in_array($user_level, ['admin', 'kepala_madrasah'], true);
$page_title = 'Kelola Template RKT';

rktEnsureSchema($pdo);
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
    $where[] = "(t.kode LIKE ? OR t.nama_program LIKE ? OR t.indikator LIKE ? OR t.bidang_nama LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($f_field > 0) {
    $where[] = "t.field_id = ?";
    $params[] = $f_field;
}
if ($f_active === '1' || $f_active === '0') {
    $where[] = "t.is_active = ?";
    $params[] = (int)$f_active;
}
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT COUNT(*) FROM rkt_template_programs t $where_sql");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

$stmt = $pdo->prepare("SELECT t.*,
        (SELECT COUNT(*) FROM rkt_template_activities a WHERE a.template_program_id = t.id) AS activity_count,
        (SELECT COUNT(*) FROM rkt_programs p WHERE p.template_id = t.id) AS used_count
        FROM rkt_template_programs t $where_sql
        ORDER BY t.bidang_nama ASC, t.kode ASC LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_active = (int)$pdo->query("SELECT COUNT(*) FROM rkt_template_programs WHERE is_active = 1")->fetchColumn();
$total_all = (int)$pdo->query("SELECT COUNT(*) FROM rkt_template_programs")->fetchColumn();

function rkt_tpl_excerpt(?string $text, int $len = 60): string
{
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));
    if ($text === '') return '-';
    if (function_exists('mb_strlen') && mb_strlen($text) > $len) {
        return mb_substr($text, 0, $len) . '...';
    }
    return strlen($text) > $len ? substr($text, 0, $len) . '...' : $text;
}

function rkt_tpl_qs(array $override = []): string
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
.rkt-table th{font-size:.73rem;text-transform:uppercase;color:#6b7391;white-space:nowrap}
.rkt-table td{font-size:.84rem;vertical-align:middle}
.rkt-section-title{font-weight:700;color:#3b4266;font-size:.85rem;text-transform:uppercase;letter-spacing:.04em;margin:18px 0 10px;padding-bottom:6px;border-bottom:1px solid #eceff7}
.rkt-modal textarea{min-height:64px;resize:vertical}
.rkt-clip{max-width:320px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rkt-act-card{border:1px solid #e2e6f0;border-radius:10px;padding:12px;margin-bottom:10px;background:#fbfcff}
.rkt-act-card .rkt-act-head{font-weight:700;color:#3b4266;font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px}
</style>
<div class="main-content">
<section class="section">
    <div class="section-header">
        <h1>Kelola Template RKT</h1>
        <div class="section-header-breadcrumb">
            <div class="breadcrumb-item active"><a href="<?= htmlspecialchars(app_url('kepala/dashboard.php')) ?>">Dashboard</a></div>
            <div class="breadcrumb-item">Program Kerja</div>
            <div class="breadcrumb-item"><a href="rkt.php">RKT</a></div>
            <div class="breadcrumb-item">Kelola Template</div>
        </div>
    </div>
    <div class="section-body">
        <div class="card">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
                <div>
                    <h4 class="mb-1">Template Program RKT</h4>
                    <div class="text-muted small"><?= (int)$total_active ?> aktif dari <?= (int)$total_all ?> template. Template hanya menjadi data awal; perubahan atau penghapusan template tidak mengubah RKT yang sudah disalin.</div>
                </div>
                <div class="mt-2 mt-md-0">
                    <a href="rkt.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left mr-1"></i>Kembali ke RKT</a>
                    <?php if ($is_editable): ?>
                        <button type="button" class="btn btn-primary btn-sm" id="btn-add-template"><i class="fas fa-plus mr-1"></i>Tambah Template</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <form method="GET" class="mb-3">
                    <div class="row">
                        <div class="col-12 col-md-5 mb-2"><input type="search" name="q" class="form-control" placeholder="Cari kode, nama program, bidang, atau indikator..." value="<?= htmlspecialchars($q) ?>"></div>
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
                    <table class="table table-hover rkt-table">
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Kode</th>
                                <th>Bidang</th>
                                <th>Nama Program</th>
                                <th>Kegiatan</th>
                                <th>Indikator Utama</th>
                                <th>Digunakan</th>
                                <th>Status</th>
                                <th class="text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$rows): ?>
                                <tr><td colspan="9" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x d-block mb-2"></i>Tidak ada template pada filter ini.</td></tr>
                            <?php endif; ?>
                            <?php $no = ($page - 1) * $per_page; foreach ($rows as $r): $no++; ?>
                                <tr>
                                    <td><?= $no ?></td>
                                    <td><code><?= htmlspecialchars($r['kode']) ?></code></td>
                                    <td><small><?= htmlspecialchars(rkt_tpl_excerpt($r['bidang_nama'], 32)) ?></small></td>
                                    <td>
                                        <div class="font-weight-bold"><?= htmlspecialchars($r['nama_program']) ?></div>
                                        <small class="text-muted">Target: <?= htmlspecialchars(rkt_tpl_excerpt($r['target'], 48)) ?></small>
                                    </td>
                                    <td class="text-center"><span class="badge badge-light border"><?= (int)$r['activity_count'] ?></span></td>
                                    <td><div class="rkt-clip" title="<?= htmlspecialchars((string)$r['indikator']) ?>"><?= htmlspecialchars(rkt_tpl_excerpt($r['indikator'], 60)) ?></div></td>
                                    <td class="text-center"><span class="badge badge-light border"><?= (int)$r['used_count'] ?></span></td>
                                    <td>
                                        <?php if ((int)$r['is_active'] === 1): ?>
                                            <span class="badge badge-success">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">Nonaktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right text-nowrap">
                                        <button type="button" class="btn btn-warning btn-sm px-2 mr-1 btn-tpl-edit" data-id="<?= (int)$r['id'] ?>" title="Edit"><i class="fas fa-edit"></i></button>
                                        <?php if ($is_editable): ?>
                                            <button type="button" class="btn btn-secondary btn-sm px-2 mr-1 btn-tpl-toggle" data-id="<?= (int)$r['id'] ?>" data-active="<?= (int)$r['is_active'] ?>" title="<?= (int)$r['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                                <i class="fas <?= (int)$r['is_active'] === 1 ? 'fa-toggle-on text-success' : 'fa-toggle-off text-muted' ?>"></i>
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm px-2 btn-tpl-delete" data-id="<?= (int)$r['id'] ?>" data-name="<?= htmlspecialchars($r['nama_program'], ENT_QUOTES) ?>" data-used="<?= (int)$r['used_count'] ?>" title="Hapus"><i class="fas fa-trash"></i></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex flex-wrap justify-content-between align-items-center">
                    <div class="text-muted small mb-2">Menampilkan <?= $total ? (($page - 1) * $per_page + 1) : 0 ?>-<?= min($total, $page * $per_page) ?> dari <?= $total ?> template</div>
                    <?php if ($total_pages > 1): ?>
                    <nav class="mb-2">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars(rkt_tpl_qs(['page' => max(1, $page - 1)])) ?>">&laquo;</a></li>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= htmlspecialchars(rkt_tpl_qs(['page' => $i])) ?>"><?= $i ?></a></li>
                            <?php endfor; ?>
                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars(rkt_tpl_qs(['page' => min($total_pages, $page + 1)])) ?>">&raquo;</a></li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
</div>

<div class="modal fade" id="templateModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <form id="templateForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="templateModalTitle">Template Program RKT</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body rkt-modal">
                    <input type="hidden" name="id" value="0">
                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label>Kode Template</label>
                            <input type="text" name="kode" class="form-control" placeholder="Otomatis jika kosong">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Bidang Pengembangan</label>
                            <select name="field_id" class="form-control">
                                <option value="">Lainnya / belum ditentukan</option>
                                <?php foreach ($fields as $f): ?>
                                    <option value="<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['nama']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Bidang Baru (opsional)</label>
                            <input type="text" name="bidang_baru" class="form-control" placeholder="Isi untuk menambah bidang">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-8 form-group">
                            <label>Nama Program</label>
                            <input type="text" name="nama_program" class="form-control" required>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Status Template</label>
                            <select name="is_active" class="form-control">
                                <option value="1">Aktif</option>
                                <option value="0">Nonaktif</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Latar Belakang / Kondisi Awal (contoh)</label>
                        <textarea name="latar_belakang" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Tujuan</label>
                            <textarea name="tujuan" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Sasaran</label>
                            <textarea name="sasaran" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Indikator Keberhasilan</label>
                        <textarea name="indikator" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Target (contoh, perlu disesuaikan)</label>
                        <textarea name="target" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label>Penanggung Jawab (contoh)</label>
                            <input type="text" name="penanggung_jawab" class="form-control">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Estimasi Anggaran (contoh)</label>
                            <input type="text" name="estimasi_anggaran" class="form-control rkt-rupiah" inputmode="numeric" placeholder="0">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Sumber Dana (rencana)</label>
                            <input type="text" name="sumber_dana" class="form-control">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-3 form-group">
                            <label>Bulan Mulai</label>
                            <select name="bulan_mulai" class="form-control">
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?= $i ?>" <?= $i === 7 ? 'selected' : '' ?>><?= $i ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Bulan Selesai</label>
                            <select name="bulan_selesai" class="form-control">
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?= $i ?>" <?= $i === 12 ? 'selected' : '' ?>><?= $i ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Output yang Diharapkan</label>
                            <input type="text" name="output" class="form-control">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Metode / Catatan Monitoring dan Evaluasi</label>
                            <textarea name="monitoring_evaluasi" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Catatan Template</label>
                            <textarea name="catatan" class="form-control" rows="2"></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
                        <h6 class="font-weight-bold mb-0">Rincian Kegiatan</h6>
                        <button type="button" class="btn btn-sm btn-primary" id="btn-add-activity-row"><i class="fas fa-plus mr-1"></i>Tambah Kegiatan</button>
                    </div>
                    <div id="template-activities"></div>
                    <small class="text-muted">Kegiatan template disalin ke RKT saat template diterapkan. Setelah disalin, perubahan template tidak memengaruhi RKT tersebut.</small>
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
    var TPL = {
        csrf: <?= json_encode($csrf) ?>,
        editable: <?= $is_editable ? 'true' : 'false' ?>,
        ajax: 'rkt_ajax.php'
    };

    // Ubah hasil $(form).serialize() menjadi objek agar tidak dipecah per karakter oleh Object.assign.
    function serializeToObject(str){
        var out = {};
        (str || '').split('&').forEach(function(pair){
            if (!pair) return;
            var idx = pair.indexOf('=');
            var key = idx < 0 ? pair : pair.slice(0, idx);
            var val = idx < 0 ? '' : pair.slice(idx + 1);
            key = decodeURIComponent(key.replace(/\+/g, ' '));
            val = decodeURIComponent(val.replace(/\+/g, ' '));
            if (!Object.prototype.hasOwnProperty.call(out, key)) {
                out[key] = val;
            } else {
                if (!Array.isArray(out[key])) out[key] = [out[key]];
                out[key].push(val);
            }
        });
        return out;
    }

    function post(action, data){
        var extra = typeof data === 'string' ? serializeToObject(data) : (data || {});
        var payload = $.extend({action: action, csrf_token: TPL.csrf}, extra);
        return $.ajax({
            url: TPL.ajax,
            type: 'POST',
            data: payload,
            dataType: 'json',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': TPL.csrf}
        });
    }
    function swalOk(msg){ Swal.fire({icon:'success', title:msg, timer:1800, showConfirmButton:false}); }
    function swalErr(msg){ Swal.fire({icon:'error', title:'Gagal', text: msg || 'Terjadi kesalahan'}); }
    function reload(){ setTimeout(function(){ location.reload(); }, 650); }
    function esc(s){ return $('<div>').text(s === null || s === undefined ? '' : s).html(); }
    function fmtRupiah(v){
        var s=(v+'').trim().replace(/[^0-9]/g,'').replace(/^0+(?=\d)/,'');
        return s ? s.replace(/\B(?=(\d{3})+(?!\d))/g,'.') : '';
    }
    $(document).on('input', 'input.rkt-rupiah', function(){ this.value = fmtRupiah(this.value); });

    var actIndex = 0;
    function activityCard(a){
        a = a || {};
        var idx = actIndex++;
        var months = '';
        for (var i = 1; i <= 12; i++) {
            months += '<option value="'+i+'" '+(parseInt(a.bulan_mulai,10) === i ? 'selected' : '')+'>'+i+'</option>';
        }
        var months2 = '';
        for (var j = 1; j <= 12; j++) {
            months2 += '<option value="'+j+'" '+(parseInt(a.bulan_selesai,10) === j ? 'selected' : '')+'>'+j+'</option>';
        }
        var html = '<div class="rkt-act-card" data-row="'+idx+'">';
        html += '<input type="hidden" class="act-id" value="'+(a.id || 0)+'">';
        html += '<div class="d-flex justify-content-between align-items-center mb-2">';
        html += '<div class="rkt-act-head mb-0">Kegiatan</div>';
        html += '<button type="button" class="btn btn-sm btn-light text-danger btn-remove-act" title="Hapus baris"><i class="fas fa-times"></i></button>';
        html += '</div>';
        html += '<div class="row">';
        html += '<div class="col-md-8 form-group mb-2"><label class="small mb-0">Nama Kegiatan</label><input type="text" class="form-control form-control-sm act-nama" value="'+esc(a.nama_kegiatan || '')+'"></div>';
        html += '<div class="col-md-4 form-group mb-2"><label class="small mb-0">Output</label><input type="text" class="form-control form-control-sm act-output" value="'+esc(a.output || '')+'"></div>';
        html += '</div>';
        html += '<div class="row">';
        html += '<div class="col-md-6 form-group mb-2"><label class="small mb-0">Tujuan</label><textarea class="form-control form-control-sm act-tujuan" rows="1">'+esc(a.tujuan || '')+'</textarea></div>';
        html += '<div class="col-md-6 form-group mb-2"><label class="small mb-0">Indikator</label><textarea class="form-control form-control-sm act-indikator" rows="1">'+esc(a.indikator || '')+'</textarea></div>';
        html += '</div>';
        html += '<div class="row">';
        html += '<div class="col-md-3 form-group mb-2"><label class="small mb-0">Target</label><input type="text" class="form-control form-control-sm act-target" value="'+esc(a.target_volume || '')+'"></div>';
        html += '<div class="col-md-3 form-group mb-2"><label class="small mb-0">Satuan</label><input type="text" class="form-control form-control-sm act-satuan" value="'+esc(a.satuan || '')+'"></div>';
        html += '<div class="col-md-3 form-group mb-2"><label class="small mb-0">Bulan Mulai</label><select class="form-control form-control-sm act-bulan-mulai">'+months+'</select></div>';
        html += '<div class="col-md-3 form-group mb-2"><label class="small mb-0">Bulan Selesai</label><select class="form-control form-control-sm act-bulan-selesai">'+months2+'</select></div>';
        html += '</div>';
        html += '<div class="row">';
        html += '<div class="col-md-4 form-group mb-2"><label class="small mb-0">Penanggung Jawab</label><input type="text" class="form-control form-control-sm act-pj" value="'+esc(a.penanggung_jawab || '')+'"></div>';
        html += '<div class="col-md-4 form-group mb-2"><label class="small mb-0">Estimasi Biaya</label><input type="text" class="form-control form-control-sm act-biaya rkt-rupiah" value="'+(a.estimasi_biaya ? fmtRupiah(a.estimasi_biaya) : '')+'" inputmode="numeric"></div>';
        html += '<div class="col-md-4 form-group mb-2"><label class="small mb-0">Sumber Dana</label><input type="text" class="form-control form-control-sm act-sumber" value="'+esc(a.sumber_dana || '')+'"></div>';
        html += '</div>';
        html += '</div>';
        return html;
    }

    function addActivityRow(a){
        $('#template-activities').append(activityCard(a || {}));
    }

    function fillTemplate(t){
        var f = $('#templateForm');
        f.find('[name=id]').val(t.id || 0);
        f.find('[name=kode]').val(t.kode || '');
        f.find('[name=field_id]').val(t.field_id || '');
        f.find('[name=bidang_baru]').val('');
        f.find('[name=nama_program]').val(t.nama_program || '');
        f.find('[name=is_active]').val(t.is_active !== undefined && parseInt(t.is_active, 10) === 0 ? '0' : '1');
        ['latar_belakang','tujuan','sasaran','indikator','target','penanggung_jawab','sumber_dana','output','monitoring_evaluasi','catatan'].forEach(function(k){
            f.find('[name='+k+']').val(t[k] || '');
        });
        f.find('[name=estimasi_anggaran]').val(t.estimasi_anggaran ? fmtRupiah(t.estimasi_anggaran) : '');
        f.find('[name=bulan_mulai]').val(t.bulan_mulai || 7);
        f.find('[name=bulan_selesai]').val(t.bulan_selesai || 12);
        $('#template-activities').empty();
        actIndex = 0;
        var acts = t.activities || [];
        if (!acts.length) {
            addActivityRow({bulan_mulai: t.bulan_mulai || 7, bulan_selesai: t.bulan_selesai || 12});
        } else {
            acts.forEach(function(a){ addActivityRow(a); });
        }
        $('#templateModalTitle').text(t.id ? 'Edit Template RKT' : 'Tambah Template RKT');
    }

    $('#btn-add-template').on('click', function(){
        $('#templateForm')[0].reset();
        fillTemplate({is_active: 1, bulan_mulai: 7, bulan_selesai: 12});
        $('#templateModal').modal('show');
    });

    $(document).on('click', '.btn-tpl-edit', function(){
        post('template_get', {id: $(this).data('id')}).done(function(res){
            if (res.success) { fillTemplate(res.data); $('#templateModal').modal('show'); }
            else swalErr(res.message);
        }).fail(function(){ swalErr('Gagal memuat template'); });
    });

    $('#btn-add-activity-row').on('click', function(){ addActivityRow({}); });
    $(document).on('click', '.btn-remove-act', function(){ $(this).closest('.rkt-act-card').remove(); });

    $('#templateForm').on('submit', function(e){
        e.preventDefault();
        var activities = [];
        $('#template-activities .rkt-act-card').each(function(){
            var c = $(this);
            var nama = (c.find('.act-nama').val() || '').trim();
            if (!nama) return;
            activities.push({
                id: parseInt(c.find('.act-id').val(), 10) || 0,
                nama_kegiatan: nama,
                tujuan: c.find('.act-tujuan').val() || '',
                indikator: c.find('.act-indikator').val() || '',
                target_volume: c.find('.act-target').val() || '',
                satuan: c.find('.act-satuan').val() || '',
                bulan_mulai: c.find('.act-bulan-mulai').val() || '',
                bulan_selesai: c.find('.act-bulan-selesai').val() || '',
                penanggung_jawab: c.find('.act-pj').val() || '',
                estimasi_biaya: c.find('.act-biaya').val() || '',
                sumber_dana: c.find('.act-sumber').val() || '',
                output: c.find('.act-output').val() || ''
            });
        });
        var data = {};
        $(this).serializeArray().forEach(function(x){ data[x.name] = x.value; });
        data.activities = JSON.stringify(activities);
        var $btn = $(this).find('button[type=submit]').prop('disabled', true);
        post('template_save', data).done(function(res){
            if (res.success) { $('#templateModal').modal('hide'); swalOk(res.message || 'Template disimpan'); reload(); }
            else { swalErr(res.message); $btn.prop('disabled', false); }
        }).fail(function(xhr){
            swalErr((xhr.responseJSON && xhr.responseJSON.message) || 'Gagal menyimpan template');
            $btn.prop('disabled', false);
        });
    });

    $(document).on('click', '.btn-tpl-toggle', function(){
        var id = $(this).data('id'), active = parseInt($(this).data('active'), 10) === 1 ? 0 : 1;
        post('template_toggle', {id: id, is_active: active}).done(function(res){
            if (res.success) { swalOk(res.message); reload(); } else swalErr(res.message);
        }).fail(function(){ swalErr('Gagal mengubah status template'); });
    });

    $(document).on('click', '.btn-tpl-delete', function(){
        var id = $(this).data('id'), nm = $(this).data('name'), used = parseInt($(this).data('used'), 10) || 0;
        var text = 'Template "'+nm+'" akan dihapus.';
        if (used > 0) {
            text += ' Template ini sudah digunakan oleh ' + used + ' program RKT. Penghapusan hanya menghapus template sumber dan tidak menghapus program atau kegiatan RKT yang sudah disalin.';
        }
        Swal.fire({title:'Hapus template?', text:text, icon:'warning', showCancelButton:true, confirmButtonColor:'#d33', confirmButtonText:'Ya, Hapus', cancelButtonText:'Batal'}).then(function(r){
            if (!r.isConfirmed) return;
            post('template_delete', {id: id}).done(function(res){
                if (res.success) { swalOk(res.message); reload(); } else swalErr(res.message);
            }).fail(function(){ swalErr('Gagal menghapus template'); });
        });
    });
})();
</script>
