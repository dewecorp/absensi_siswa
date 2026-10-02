<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';
require_once '../config/ai_helper.php';

ensure_learning_schema($pdo);
ai_helper_schema($pdo);

if (!isAuthorized(['guru', 'wali'])) {
    redirect('../login.php');
}

$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$session_q = isset($_GET['session_type']) ? '?session_type=' . urlencode((string)$_GET['session_type']) : '';

// Master lists: mapel + kelas sesuai guru login (fallback semua bila belum ada jadwal)
$mapel_list = function_exists('getGuruTaughtMapels') ? getGuruTaughtMapels($pdo, $guru_id) : getFilteredSubjects($pdo);
$kelas_list = function_exists('getGuruTaughtClasses') ? getGuruTaughtClasses($pdo, $guru_id) : $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
$jenis_soal_options = ['Pilihan Ganda', 'Pilihan Ganda Kompleks', 'Menjodohkan', 'Isian Singkat', 'Uraian'];
$kurikulum_options = ['PERMENDIKDASMEN_046' => 'Permendikdasmen CP 046', 'KMA_1503_KBC' => 'KMA 1503 + KBC'];
$asesmen_options = ai_asesmen_list();

$page_title = 'Generate Soal AI';
$ai_cfg = ai_guru_config($pdo, $guru_id);

$js_page = [<<<'JS'
var aiLastSoal = [];
var aiLastKisi = [];
var aiLastAsesmen = '';
function aiHitungTotal() {
    var total = 0;
    $('#aiPaketWrap .ai-paket-num').each(function() {
        if (!$(this).prop('disabled')) {
            total += parseInt($(this).val() || '0', 10) || 0;
        }
    });
    $('#aiTotalPaket').text(total);
    return total;
}
$(document).on('change', '.ai-paket-check', function() {
    var num = $(this).closest('.border').find('.ai-paket-num');
    if ($(this).is(':checked')) {
        num.prop('disabled', false);
        if (parseInt(num.val() || '0', 10) <= 0) { num.val(2); }
    } else {
        num.prop('disabled', true);
        num.val(0);
    }
    aiHitungTotal();
});
$(document).on('input', '.ai-paket-num', function() {
    var v = parseInt($(this).val() || '0', 10) || 0;
    if (v < 0) { $(this).val(0); }
    aiHitungTotal();
});
aiHitungTotal();
$('#formGenerateAI').on('submit', function(e) {
    e.preventDefault();
    var total = aiHitungTotal();
    if (total < 1) {
        Swal.fire({ icon: 'warning', title: 'Paket kosong', text: 'Centang minimal 1 bentuk soal dan isi jumlahnya.' });
        return;
    }
    if (($('#ai_topik').val() || '').trim() === '') {
        Swal.fire({ icon: 'warning', title: 'Topik kosong', text: 'Topik/pokok bahasan wajib diisi.' });
        return;
    }
    var fd = new FormData(this);
    fd.append('aksi', 'generate');
    $('#btnProsesAI').prop('disabled', true);
    $('#aiLoading').show();
    $('#aiKisiWrap').hide();
    $('#aiHasilWrap').hide();
    $.ajax({
        url: 'ajax_generate_soal.php',
        type: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        dataType: 'json',
        timeout: 180000
    }).done(function(res) {
        if (!res || !res.ok) {
            Swal.fire({ icon: 'error', title: 'Gagal', text: (res && res.msg) ? res.msg : 'Gagal generate soal.' });
            return;
        }
        aiLastSoal = res.soal || [];
        aiLastKisi = res.kisi_kisi || [];
        aiLastAsesmen = res.jenis_asesmen || $('#ai_asesmen').val() || '';
        if (aiLastAsesmen) { $('#aiJudulHasil').text(aiLastAsesmen); }
        var kisi = aiLastKisi;
        var kBody = '';
        kisi.forEach(function(k) {
            kBody += '<tr><td>' + (k.no || '') + '</td><td><span class="badge badge-light border">' + $('<div>').text(k.bentuk || '').html() + '</span></td><td>' + $('<div>').text(k.materi || '').html() + '</td><td>' + $('<div>').text(k.cp || '-').html() + '</td><td>' + $('<div>').text(k.tp || '-').html() + '</td><td>' + $('<div>').text(k.indikator || '').html() + '</td><td><span class="badge badge-info">' + $('<div>').text(k.level_kognitif || 'L2').html() + '</span></td><td>' + $('<div>').text(k.kesulitan || '').html() + '</td><td>' + (k.bobot || '') + '</td></tr>';
        });
        if (kBody !== '') {
            $('#aiKisiTable tbody').html(kBody);
            $('#aiKisiWrap').show();
        }
        function aiTabelJodoh(rows) {
            if (!rows || !rows.length) { return ''; }
            var h = '<div class="table-responsive mt-2"><table class="table table-sm table-bordered mb-1"><thead><tr><th width="8%">No</th><th>Soal</th><th width="10%">Huruf</th><th>Pilihan Jawaban</th></tr></thead><tbody>';
            rows.forEach(function(r) {
                h += '<tr><td class="text-center">' + (r.no || '') + '</td><td>' + $('<div>').text(r.kiri || '').html() + '</td><td class="text-center font-weight-bold">' + $('<div>').text(r.huruf || '').html() + '</td><td>' + $('<div>').text(r.kanan || '').html() + '</td></tr>';
            });
            return h + '</tbody></table></div>';
        }
        var html = '<ol class="pl-3 mb-0">';
        aiLastSoal.forEach(function(s) {
            html += '<li class="mb-3"><span class="badge badge-primary mb-1">' + $('<div>').text(s.bentuk || '').html() + '</span> <span class="badge badge-info mb-1">' + $('<div>').text(s.level_kognitif || 'L2').html() + '</span><div class="font-weight-bold">' + $('<div>').text(s.pertanyaan || '').html() + '</div>';
            if (s.bentuk === 'Menjodohkan' && s.tabel && s.tabel.length) {
                html += aiTabelJodoh(s.tabel);
            } else if (s.opsi && typeof s.opsi === 'object' && (s.opsi.A || s.opsi.B || s.opsi.C || s.opsi.D)) {
                html += '<ul class="pl-3 mb-1">';
                ['A','B','C','D'].forEach(function(k) {
                    if (s.opsi[k]) { html += '<li><b>' + k + '.</b> ' + $('<div>').text(s.opsi[k]).html() + '</li>'; }
                });
                html += '</ul>';
            }
            html += '<div class="small">Kunci: <b class="text-success">' + $('<div>').text(s.kunci || '-').html() + '</b></div>';
            html += '<div class="small text-muted">' + $('<div>').text(s.pembahasan || '').html() + '</div></li>';
        });
        html += '</ol>';
        $('#aiHasil').html(html);
        // Hitung total butir: tiap baris pasangan Menjodohkan dihitung 1 butir
        var totalButir = 0;
        aiLastSoal.forEach(function(s) {
            if (s.bentuk === 'Menjodohkan' && s.tabel && s.tabel.length) {
                totalButir += s.tabel.length;
            } else {
                totalButir += 1;
            }
        });
        $('#aiCount').text(totalButir);
        $('#aiHasilWrap').show();
        Swal.fire({ icon: 'success', title: 'Selesai', text: totalButir + ' butir (1 paket) berhasil dibuat.', timer: 1800, showConfirmButton: false });
    }).fail(function(xhr) {
        var msg = 'Gagal generate soal.';
        try { var r = JSON.parse(xhr.responseText); if (r.msg) msg = r.msg; } catch(e) {}
        Swal.fire({ icon: 'error', title: 'Gagal', text: msg });
    }).always(function() {
        $('#btnProsesAI').prop('disabled', false);
        $('#aiLoading').hide();
    });
});
function aiCommonPayload() {
    var bentuk0 = (aiLastSoal.length && aiLastSoal[0].bentuk) ? aiLastSoal[0].bentuk : 'Pilihan Ganda';
    return {
        bentuk: bentuk0,
        jenis_asesmen: $('#ai_asesmen').val(),
        kurikulum: $('#ai_kurikulum').val(),
        id_mapel: $('#ai_mapel').val(),
        id_kelas: $('#ai_kelas').val(),
        semester: $('#ai_semester').val(),
        topik: $('#ai_topik').val(),
        sub_topik: $('#ai_sub_topik').val(),
        materi: $('#ai_materi').val(),
        instruksi_tambahan: $('#ai_instruksi_tambahan').val(),
        status: $('#ai_status').val(),
        items: aiLastSoal,
        kisi_kisi: aiLastKisi
    };
}
$(document).on('click', '#btnSimpanAI', function() {
    if (!aiLastSoal.length) { Swal.fire({ icon: 'warning', title: 'Kosong', text: 'Belum ada hasil untuk disimpan.' }); return; }
    var payload = aiCommonPayload();
    if (!(payload.topik || '').trim()) { Swal.fire({ icon: 'warning', title: 'Topik kosong', text: 'Topik/pokok bahasan wajib diisi.' }); return; }
    payload.aksi = 'simpan';
    $.ajax({
        url: 'ajax_generate_soal.php',
        type: 'POST',
        data: JSON.stringify(payload),
        contentType: 'application/json',
        dataType: 'json'
    }).done(function(res) {
        if (res && res.ok) {
            Swal.fire({ icon: 'success', title: 'Tersimpan', text: res.tersimpan + ' soal masuk Bank Soal.' }).then(function() {
                var q = new URLSearchParams(window.location.search).get('session_type');
                window.location.href = 'bank_soal.php' + (q ? '?session_type=' + encodeURIComponent(q) : '');
            });
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: (res && res.msg) ? res.msg : 'Gagal menyimpan.' });
        }
    }).fail(function() {
        Swal.fire({ icon: 'error', title: 'Gagal', text: 'Gagal menyimpan ke server.' });
    });
});
function aiUnduh(format) {
    if (!aiLastSoal.length) { Swal.fire({ icon: 'warning', title: 'Kosong', text: 'Belum ada hasil untuk diunduh.' }); return; }
    var payload = aiCommonPayload();
    payload.aksi = 'unduh';
    payload.format = format;
    fetch('ajax_generate_soal.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            var disp = r.headers.get('Content-Disposition') || '';
            var fname = '';
            var m = /filename="([^"]+)"/.exec(disp);
            if (m) { fname = m[1]; }
            if (!fname) { fname = format === 'xlsx' ? 'soal-ai.xlsx' : (format === 'docx' ? 'soal-ai.docx' : 'soal-ai.pdf'); }
            return r.blob().then(function(b) { return { blob: b, fname: fname }; });
        })
        .then(function(o) {
            var a = document.createElement('a');
            a.href = URL.createObjectURL(o.blob);
            a.download = o.fname;
            document.body.appendChild(a);
            a.click();
            a.remove();
        })
        .catch(function() { Swal.fire({ icon: 'error', title: 'Gagal', text: 'Gagal mengunduh file.' }); });
}
$(document).on('click', '#btnUnduhPdf', function() { aiUnduh('pdf'); });
$(document).on('click', '#btnUnduhXlsx', function() { aiUnduh('xlsx'); });
$(document).on('click', '#btnUnduhDocx', function() { aiUnduh('docx'); });

$('#formUploadEdit').on('submit', function(e) {
    e.preventDefault();
    var fd = new FormData(this);
    fd.append('aksi', 'upload');
    if (!fd.get('id_mapel')) fd.set('id_mapel', $('#ai_mapel').val() || '');
    if (!fd.get('id_kelas')) fd.set('id_kelas', $('#ai_kelas').val() || '');
    if (!fd.get('topik')) fd.set('topik', $('#ai_topik').val() || '');
    if (!fd.get('jenis_asesmen')) fd.set('jenis_asesmen', $('#ai_asesmen').val() || '');
    if (!fd.get('kurikulum')) fd.set('kurikulum', $('#ai_kurikulum').val() || '');
    if (!fd.get('semester')) fd.set('semester', $('#ai_semester').val() || '');

    $('#btnProsesUpload').prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Mengupload...');
    $.ajax({
        url: 'ajax_generate_soal.php',
        type: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        dataType: 'json'
    }).done(function(res) {
        if (res && res.ok) {
            Swal.fire({ icon: 'success', title: 'Berhasil', text: res.msg || 'Soal berhasil disimpan ke Bank Soal.' }).then(function() {
                var q = new URLSearchParams(window.location.search).get('session_type');
                window.location.href = 'bank_soal.php' + (q ? '?session_type=' + encodeURIComponent(q) : '');
            });
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: (res && res.msg) ? res.msg : 'Gagal mengupload soal.' });
        }
    }).fail(function() {
        Swal.fire({ icon: 'error', title: 'Gagal', text: 'Gagal mengupload ke server.' });
    }).always(function() {
        $('#btnProsesUpload').prop('disabled', false).html('<i class="fas fa-upload mr-1"></i> Upload ke Bank Soal');
    });
});
JS
];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Generate Soal AI</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4><i class="fas fa-sliders-h mr-2"></i>Pengaturan Generate</h4>
                    <div>
                        <?php if (!empty($ai_cfg['gemini_email'])): ?>
                            <span class="badge badge-success" title="Akun resmi Kemenag Gemini Pro"><i class="fas fa-check-circle mr-1"></i>Gemini Pro: <?= htmlspecialchars($ai_cfg['gemini_email']) ?></span>
                        <?php elseif (!empty($ai_cfg['gemini_key'])): ?>
                            <span class="badge badge-info"><i class="fas fa-robot mr-1"></i>Gemini AI</span>
                        <?php elseif (!empty($ai_cfg['openai_key'])): ?>
                            <span class="badge badge-info"><i class="fas fa-robot mr-1"></i>ChatGPT</span>
                        <?php else: ?>
                            <a href="profil.php" class="badge badge-warning text-dark"><i class="fas fa-exclamation-triangle mr-1"></i>Atur Konektor AI (Email Kemenag) &raquo;</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <form id="formGenerateAI" enctype="multipart/form-data">
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label>Jenis Asesmen <span class="text-danger">*</span> <small class="text-muted">(jadi nama file)</small></label>
                                <select name="jenis_asesmen" id="ai_asesmen" class="form-control">
                                    <?php foreach ($asesmen_options as $a): ?>
                                        <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 form-group">
                                <label>Kurikulum</label>
                                <select name="kurikulum" id="ai_kurikulum" class="form-control">
                                    <?php foreach ($kurikulum_options as $val => $label): ?>
                                        <option value="<?= $val ?>"><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 form-group">
                                <label>Semester</label>
                                <select name="semester" id="ai_semester" class="form-control">
                                    <option value="">-- Semua --</option>
                                    <option value="Semester 1">Semester 1 (Ganjil)</option>
                                    <option value="Semester 2">Semester 2 (Genap)</option>
                                </select>
                            </div>
                            <div class="col-md-4 form-group">
                                <label>Status Simpan</label>
                                <select id="ai_status" class="form-control">
                                    <option value="Aktif">Aktif</option>
                                    <option value="Draft">Draft</option>
                                    <option value="Arsip">Arsip</option>
                                </select>
                            </div>
                            <div class="col-md-4 form-group">
                                <label>Mata Pelajaran</label>
                                <select name="id_mapel" id="ai_mapel" class="form-control">
                                    <option value="">-- Pilih Mapel --</option>
                                    <?php foreach ($mapel_list as $m): ?>
                                        <option value="<?= (int)($m['id_mapel'] ?? 0) ?>"><?= htmlspecialchars($m['nama_mapel'] ?? '-') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 form-group">
                                <label>Kelas</label>
                                <select name="id_kelas" id="ai_kelas" class="form-control">
                                    <option value="">-- Pilih Kelas --</option>
                                    <?php foreach ($kelas_list as $k): ?>
                                        <option value="<?= (int)$k['id_kelas'] ?>"><?= htmlspecialchars($k['nama_kelas']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 form-group">
                                <label>Topik / Pokok Bahasan <span class="text-danger">*</span></label>
                                <input type="text" name="topik" id="ai_topik" class="form-control" required placeholder="Contoh: Metamorfosis Kupu-kupu">
                            </div>
                            <div class="col-md-4 form-group">
                                <label>Sub Topik <span class="text-muted">(opsional)</span></label>
                                <input type="text" name="sub_topik" id="ai_sub_topik" class="form-control" placeholder="Contoh: Tahapan pupa">
                            </div>
                            <div class="col-md-4 form-group">
                                <label>Upload Materi <span class="text-muted">(opsional, .docx/.txt maks 2MB)</span></label>
                                <input type="file" name="materi_file" id="ai_file" class="form-control-file" accept=".docx,.txt">
                            </div>
                            <div class="col-12 form-group">
                                <label class="font-weight-bold">Paket Bentuk Soal <small class="text-muted">(centang + isi jumlah per bentuk — hasil jadi 1 paket, kesulitan otomatis acak merata Mudah/Sedang/Sukar per bentuk)</small></label>
                                <div class="row" id="aiPaketWrap">
                                    <?php foreach ($jenis_soal_options as $idx => $js): ?>
                                        <?php $def_jml = ['Pilihan Ganda' => 5, 'Pilihan Ganda Kompleks' => 0, 'Menjodohkan' => 0, 'Isian Singkat' => 0, 'Uraian' => 2][$js] ?? 0; ?>
                                        <div class="col-md-4 mb-2">
                                            <div class="border rounded p-2 d-flex align-items-center">
                                                <div class="custom-control custom-checkbox mr-2">
                                                    <input type="checkbox" class="custom-control-input ai-paket-check" id="ai_chk_<?= $idx ?>" <?= $def_jml > 0 ? 'checked' : '' ?>>
                                                    <label class="custom-control-label font-weight-bold small" for="ai_chk_<?= $idx ?>"><?= $js ?><?= $js === 'Menjodohkan' ? ' <span class="text-muted font-weight-normal">(baris tabel)</span>' : '' ?></label>
                                                </div>
                                                <input type="number" name="paket[<?= $js ?>]" class="form-control form-control-sm ml-auto ai-paket-num" style="width:80px;" value="<?= $def_jml ?>" min="0" <?= $def_jml > 0 ? '' : 'disabled' ?>>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <small class="text-muted">Total paket: <b id="aiTotalPaket">7</b> butir.</small>
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Materi Detail <span class="text-muted small font-weight-normal">(opsional — ketik manual / tempel materi)</span></label>
                                <textarea name="materi" id="ai_materi" class="form-control" rows="10" style="min-height: 220px;" placeholder="Opsional: tempel ringkasan materi, teks bab, atau poin-poin penting... (bisa digabung dengan file upload)"></textarea>
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Instruksi Tambahan <span class="text-muted small font-weight-normal">(opsional — perintah khusus untuk AI)</span></label>
                                <textarea name="instruksi_tambahan" id="ai_instruksi_tambahan" class="form-control" rows="10" style="min-height: 220px;" placeholder="Contoh: Tambahkan deskripsi gambar/ilustrasi pada soal yang memerlukan gambar, sertakan studi kasus kontekstual, gunakan kosakata sederhana untuk tingkat dasar, dll."></textarea>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-success" id="btnProsesAI"><i class="fas fa-magic mr-1"></i> Generate Soal</button>
                        <span id="aiLoading" class="ml-2 text-muted" style="display:none;">Memproses AI, mohon tunggu...</span>
                    </form>
                </div>
            </div>

            <div class="card mb-3" id="aiKisiWrap" style="display:none;">
                <div class="card-header">
                    <h4>Kisi-Kisi — <span id="aiJudulHasil">Paket Soal</span></h4>
                </div>
                <div class="card-body">
                    <div class="table-responsive"><table class="table table-sm table-bordered" id="aiKisiTable"><thead><tr><th>No</th><th>Bentuk</th><th>Materi</th><th>CP</th><th>TP</th><th>Indikator</th><th>Level</th><th>Kesulitan</th><th>Bobot</th></tr></thead><tbody></tbody></table></div>
                </div>
            </div>

            <div class="card" id="aiHasilWrap" style="display:none;">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4>Preview Hasil (<span id="aiCount">0</span> butir)</h4>
                    <a href="bank_soal.php<?= $session_q ?>" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left mr-1"></i> Bank Soal</a>
                </div>
                <div class="card-body">
                    <div id="aiHasil" class="border rounded p-3" style="max-height:560px;overflow:auto;"></div>

                    <div class="row mt-4">
                        <!-- Opsi 1: Simpan Langsung -->
                        <div class="col-md-6 mb-3">
                            <div class="border rounded p-3 h-100 bg-light">
                                <h6 class="font-weight-bold text-primary"><i class="fas fa-save mr-1"></i> Pilihan 1: Simpan Langsung</h6>
                                <p class="small text-muted mb-3">Simpan butir soal yang di-generate langsung ke Bank Soal tanpa modifikasi.</p>
                                <button type="button" class="btn btn-primary" id="btnSimpanAI"><i class="fas fa-save mr-1"></i> Simpan Langsung ke Bank Soal</button>
                            </div>
                        </div>

                        <!-- Opsi 2: Unduh, Edit & Upload -->
                        <div class="col-md-6 mb-3">
                            <div class="border rounded p-3 h-100 bg-light">
                                <h6 class="font-weight-bold text-success"><i class="fas fa-file-download mr-1"></i> Pilihan 2: Unduh, Edit, lalu Upload</h6>
                                <p class="small text-muted mb-2">Unduh file, sesuaikan di komputer Anda, lalu upload kembali ke Bank Soal.</p>
                                <div class="btn-group btn-group-sm mb-3" role="group">
                                    <button type="button" class="btn btn-outline-danger" id="btnUnduhPdf"><i class="fas fa-file-pdf mr-1"></i> PDF</button>
                                    <button type="button" class="btn btn-outline-success" id="btnUnduhXlsx"><i class="fas fa-file-excel mr-1"></i> XLSX</button>
                                    <button type="button" class="btn btn-outline-primary" id="btnUnduhDocx"><i class="fas fa-file-word mr-1"></i> DOCX</button>
                                </div>
                                <form id="formUploadEdit" enctype="multipart/form-data">
                                    <div class="form-group mb-2">
                                        <label class="small font-weight-bold">Upload File Hasil Edit (.doc, .docx, .pdf, .xls, .xlsx, .txt)</label>
                                        <input type="file" name="file_soal" class="form-control-file form-control-sm" accept=".doc,.docx,.pdf,.xls,.xlsx,.txt" required>
                                    </div>
                                    <button type="submit" class="btn btn-success btn-sm" id="btnProsesUpload"><i class="fas fa-upload mr-1"></i> Upload ke Bank Soal</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
