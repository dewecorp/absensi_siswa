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
$jenis_options = [
    'CP/TP',
    'ATP',
    'Modul Ajar',
    'RPP',
    'Silabus',
    'Program Tahunan (Prota)',
    'Program Semester (Promes)',
    'Kriteria Ketercapaian (KKTP)',
    'LKPD (Lembar Kerja Peserta Didik)',
    'PPT (Slide Show) Materi Pembelajaran',
    'Lainnya'
];
$kurikulum_options = ['PERMENDIKDASMEN_046' => 'Permendikdasmen CP 046', 'KMA_1503_KBC' => 'KMA 1503 + KBC'];
$school_profile = getSchoolProfile($pdo);
$tahun_default = $school_profile['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1);

$page_title = 'Generate Perangkat AI';
$ai_cfg = ai_guru_config($pdo, $guru_id);

$js_page = [<<<'JS'
var aiLastDoc = null;
$('#formGeneratePerangkat').on('submit', function(e) {
    e.preventDefault();
    if (($('#ai_topik').val() || '').trim() === '') {
        Swal.fire({ icon: 'warning', title: 'Topik kosong', text: 'Topik / materi pokok wajib diisi.' });
        return;
    }
    var fd = new FormData(this);
    fd.append('aksi', 'generate');
    $('#btnProsesAI').prop('disabled', true);
    $('#aiLoading').show();
    $('#aiHasilWrap').hide();
    $.ajax({
        url: 'ajax_generate_perangkat.php',
        type: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        dataType: 'json',
        timeout: 180000
    }).done(function(res) {
        if (!res || !res.ok) {
            Swal.fire({ icon: 'error', title: 'Gagal', text: (res && res.msg) ? res.msg : 'Gagal generate perangkat.' });
            return;
        }
        aiLastDoc = res.dokumen || {};
        $('#aiJudulHasil').text((res.jenis_perangkat || 'Perangkat') + ' — ' + (aiLastDoc.judul || ''));
        var fields = [
            ['CP', aiLastDoc.cp], ['TP', aiLastDoc.tp], ['Materi', aiLastDoc.materi],
            ['Tujuan Pembelajaran', aiLastDoc.tujuan_pembelajaran], ['Indikator', aiLastDoc.indikator],
            ['Deskripsi', aiLastDoc.deskripsi]
        ];
        var html = '';
        fields.forEach(function(f) {
            if (f[1] && (f[1] + '').trim() !== '') {
                html += '<h6 class="font-weight-bold mt-3 mb-1 text-primary">' + $('<div>').text(f[0]).html() + '</h6>'
                    + '<div class="border rounded p-2 bg-light mb-2" style="white-space:pre-wrap;font-size:13.5px;line-height:1.6;">' + $('<div>').text(f[1]).html() + '</div>';
            }
        });

        // Format Isi Dokumen (tabel pipe ditampilkan sebagai tabel Bootstrap utuh)
        function formatIsiDokumenPreview(raw) {
            if (!raw) return '<div class="text-muted">(kosong)</div>';
            var lines = (raw + '').split(/\r\n|\r|\n/);
            var out = [];
            var tbl = [];

            function flushTbl() {
                if (!tbl.length) return;
                var t = '<div class="table-responsive my-3"><table class="table table-bordered table-sm table-striped text-dark" style="font-size:12.5px;">';
                var first = true;
                tbl.forEach(function(r) {
                    var cols = r.split('|').map(function(c) { return c.trim(); });
                    if (cols.length && cols[0] === '') cols.shift();
                    if (cols.length && cols[cols.length - 1] === '') cols.pop();
                    if (!cols.length) return;
                    var isSep = cols.every(function(c) { return /^:?-+:?$/.test(c); });
                    if (isSep) return;

                    var tag = first ? 'th' : 'td';
                    var bg = first ? ' class="thead-light"' : '';
                    t += '<tr' + bg + '>';
                    cols.forEach(function(c) {
                        t += '<' + tag + ' style="padding:6px 8px;vertical-align:middle;">' + $('<div>').text(c).html() + '</' + tag + '>';
                    });
                    t += '</tr>';
                    first = false;
                });
                t += '</table></div>';
                out.push(t);
                tbl = [];
            }

            lines.forEach(function(ln) {
                var tr = ln.trim();
                if (tr.indexOf('|') !== -1 && !/^[A-Z]\./.test(tr)) {
                    tbl.push(tr);
                } else {
                    flushTbl();
                    if (tr === '') {
                        out.push('<div style="height:6px;"></div>');
                    } else if (/^\[GAMBAR:\s*(.*?)\]$/i.test(tr)) {
                        var gm = /^\[GAMBAR:\s*(.*?)\]$/i.exec(tr);
                        var gDesc = gm ? gm[1] : '';
                        var imgId = 'aiImg_' + Math.random().toString(36).substr(2, 9);
                        out.push('<div class="my-3 text-center p-2 bg-white rounded border" style="max-width:650px;margin-left:auto;margin-right:auto;box-shadow:0 2px 6px rgba(0,0,0,0.08);">'
                            + '<img id="' + imgId + '" src="" alt="' + $('<div>').text(gDesc).html() + '" style="max-width:100%;height:auto;max-height:360px;border-radius:4px;display:none;">'
                            + '<div id="' + imgId + '_loading" class="text-muted small py-3"><i class="fas fa-spinner fa-spin mr-1"></i> Memuat gambar edukasi otentik...</div>'
                            + '<div class="mt-2 text-muted small font-italic"><i class="fas fa-image mr-1"></i>Gambar: ' + $('<div>').text(gDesc).html() + '</div>'
                            + '</div>');
                        setTimeout((function(id, desc) {
                            return function() {
                                $.getJSON('ajax_generate_perangkat.php', { aksi: 'resolve_image', desc: desc }, function(res) {
                                    $('#' + id + '_loading').hide();
                                    if (res && res.ok && res.url) {
                                        $('#' + id).attr('src', res.url).show();
                                    }
                                });
                            };
                        })(imgId, gDesc), 100);
                    } else if (/^[A-Z]\.\s+/.test(tr)) {
                        out.push('<h6 class="font-weight-bold mt-3 mb-1 text-primary border-bottom pb-1">' + $('<div>').text(tr).html() + '</h6>');
                    } else if (/^\d+\.\s+/.test(tr)) {
                        out.push('<div class="font-weight-bold mt-2 mb-1 text-dark">' + $('<div>').text(tr).html() + '</div>');
                    } else {
                        out.push('<p class="mb-1 text-dark" style="line-height:1.6;font-size:13.5px;">' + $('<div>').text(tr).html() + '</p>');
                    }
                }
            });
            flushTbl();
            return out.join('');
        }

        html += '<h6 class="font-weight-bold mt-4 mb-2 text-dark border-top pt-3"><i class="fas fa-file-alt mr-1 text-warning"></i> Isi Dokumen Lengkap</h6>'
            + '<div class="border rounded p-3 bg-white" style="max-height:600px;overflow:auto;">' + formatIsiDokumenPreview(aiLastDoc.isi_dokumen) + '</div>';
        $('#aiHasil').html(html);
        $('#aiHasilWrap').show();
        Swal.fire({ icon: 'success', title: 'Selesai', text: 'Dokumen ' + (res.jenis_perangkat || '') + ' berhasil dibuat.', timer: 1800, showConfirmButton: false });
    }).fail(function(xhr) {
        var msg = 'Gagal generate perangkat.';
        try { var r = JSON.parse(xhr.responseText); if (r.msg) msg = r.msg; } catch(e) {}
        Swal.fire({ icon: 'error', title: 'Gagal', text: msg });
    }).always(function() {
        $('#btnProsesAI').prop('disabled', false);
        $('#aiLoading').hide();
    });
});
$(document).on('click', '#btnSimpanAI', function() {
    if (!aiLastDoc || !aiLastDoc.judul) { Swal.fire({ icon: 'warning', title: 'Kosong', text: 'Belum ada hasil untuk disimpan.' }); return; }
    var payload = {
        aksi: 'simpan',
        jenis_perangkat: $('#ai_jenis').val(),
        judul: aiLastDoc.judul,
        id_mapel: $('#ai_mapel').val(),
        id_kelas: $('#ai_kelas').val(),
        semester: $('#ai_semester').val(),
        tahun_ajaran: $('#ai_tahun').val(),
        topik: $('#ai_topik').val(),
        materi: $('#ai_materi').val(),
        status: $('#ai_status').val(),
        dokumen: aiLastDoc
    };
    $.ajax({
        url: 'ajax_generate_perangkat.php',
        type: 'POST',
        data: JSON.stringify(payload),
        contentType: 'application/json',
        dataType: 'json'
    }).done(function(res) {
        if (res && res.ok) {
            Swal.fire({ icon: 'success', title: 'Tersimpan', text: 'Dokumen masuk Perangkat Pembelajaran.' }).then(function() {
                var q = new URLSearchParams(window.location.search).get('session_type');
                window.location.href = 'perangkat_pembelajaran.php' + (q ? '?session_type=' + encodeURIComponent(q) : '');
            });
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: (res && res.msg) ? res.msg : 'Gagal menyimpan.' });
        }
    }).fail(function() {
        Swal.fire({ icon: 'error', title: 'Gagal', text: 'Gagal menyimpan ke server.' });
    });
});
function aiUnduhPerangkat(format) {
    if (!aiLastDoc || !aiLastDoc.judul) { Swal.fire({ icon: 'warning', title: 'Kosong', text: 'Belum ada hasil untuk diunduh.' }); return; }
    var btnId = format === 'xlsx' ? '#btnUnduhXlsx' : (format === 'docx' ? '#btnUnduhDocx' : '#btnUnduhPdf');
    var $btn = $(btnId);
    var oldHtml = $btn.html();
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i>');

    var payload = {
        aksi: 'unduh',
        format: format,
        jenis_perangkat: $('#ai_jenis').val(),
        mapel: $('#ai_mapel option:selected').text(),
        kelas: $('#ai_kelas option:selected').text(),
        semester: $('#ai_semester').val(),
        tahun_ajaran: $('#ai_tahun').val(),
        topik: $('#ai_topik').val(),
        dokumen: aiLastDoc
    };
    fetch('ajax_generate_perangkat.php?aksi=unduh&format=' + encodeURIComponent(format), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function(r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        var disp = r.headers.get('Content-Disposition') || '';
        var fname = '';
        var m = /filename="?([^";]+)"?/i.exec(disp);
        if (m) { fname = m[1].trim(); }
        if (!fname) { fname = format === 'xlsx' ? 'perangkat.xlsx' : (format === 'docx' ? 'perangkat.docx' : 'perangkat.pdf'); }
        return r.blob().then(function(b) { return { blob: b, fname: fname }; });
    })
    .then(function(o) {
        var a = document.createElement('a');
        a.style.display = 'none';
        a.href = URL.createObjectURL(o.blob);
        a.download = o.fname;
        document.body.appendChild(a);
        a.click();
        setTimeout(function() {
            URL.revokeObjectURL(a.href);
            a.remove();
        }, 500);
    })
    .catch(function(err) {
        Swal.fire({ icon: 'error', title: 'Gagal', text: 'Gagal mengunduh file: ' + err.message });
    })
    .finally(function() {
        $btn.prop('disabled', false).html(oldHtml);
    });
}
$(document).on('click', '#btnUnduhPdf', function() { aiUnduhPerangkat('pdf'); });
$(document).on('click', '#btnUnduhXlsx', function() { aiUnduhPerangkat('xlsx'); });
$(document).on('click', '#btnUnduhDocx', function() { aiUnduhPerangkat('docx'); });
JS
];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Generate Perangkat AI</h1>
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
                            <a href="profil.php" class="badge badge-warning text-dark"><i class="fas fa-exclamation-triangle mr-1"></i>Atur Konektor AI &raquo;</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <form id="formGeneratePerangkat" enctype="multipart/form-data">
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label class="font-weight-bold">Jenis Perangkat <span class="text-danger">*</span></label>
                                <select name="jenis_perangkat" id="ai_jenis" class="form-control">
                                    <?php foreach ($jenis_options as $j): ?>
                                        <option value="<?= htmlspecialchars($j) ?>" <?= $j === 'Modul Ajar' ? 'selected' : '' ?>><?= htmlspecialchars($j) ?></option>
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
                                <label>Tahun Ajaran</label>
                                <input type="text" name="tahun_ajaran" id="ai_tahun" class="form-control" value="<?= htmlspecialchars($tahun_default) ?>" placeholder="Contoh: 2025/2026">
                            </div>
                            <div class="col-md-4 form-group">
                                <label>Topik / Materi Pokok <span class="text-danger">*</span></label>
                                <input type="text" name="topik" id="ai_topik" class="form-control" required placeholder="Contoh: Sistem Pencernaan Manusia">
                            </div>
                            <div class="col-md-4 form-group">
                                <label>Sub Topik <span class="text-muted">(opsional)</span></label>
                                <input type="text" name="sub_topik" id="ai_sub_topik" class="form-control" placeholder="Contoh: Organ pencernaan">
                            </div>
                            <div class="col-md-4 form-group">
                                <label>Upload Materi <span class="text-muted">(opsional, .docx/.txt maks 2MB)</span></label>
                                <input type="file" name="materi_file" id="ai_file" class="form-control-file" accept=".docx,.txt">
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Materi Detail <span class="text-muted small font-weight-normal">(opsional — ketik manual / tempel materi)</span></label>
                                <textarea name="materi" id="ai_materi" class="form-control" rows="10" style="min-height: 220px;" placeholder="Opsional: tempel ringkasan materi, teks bab, atau poin-poin penting... (bisa digabung dengan file upload)"></textarea>
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Instruksi Tambahan <span class="text-muted small font-weight-normal">(opsional — perintah khusus untuk AI)</span></label>
                                <textarea name="instruksi_tambahan" id="ai_instruksi_tambahan" class="form-control" rows="10" style="min-height: 220px;" placeholder="Contoh: Tambahkan deskripsi gambar/ilustrasi pada bagian yang memerlukan gambar, sertakan studi kasus kontekstual, gunakan bahasa sederhana, dll."></textarea>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-success" id="btnProsesAI"><i class="fas fa-magic mr-1"></i> Generate Perangkat</button>
                        <span id="aiLoading" class="ml-2 text-muted" style="display:none;">Memproses AI, mohon tunggu...</span>
                    </form>
                </div>
            </div>

            <div class="card" id="aiHasilWrap" style="display:none;">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4>Hasil: <span id="aiJudulHasil">Dokumen</span></h4>
                    <a href="perangkat_pembelajaran.php<?= $session_q ?>" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left mr-1"></i> Perangkat</a>
                </div>
                <div class="card-body">
                    <div id="aiHasil" class="border rounded p-3" style="max-height:560px;overflow:auto;"></div>
                    <div class="mt-3 d-flex flex-wrap" style="gap:8px;">
                        <button type="button" class="btn btn-primary" id="btnSimpanAI"><i class="fas fa-save mr-1"></i> Simpan ke Perangkat</button>
                        <button type="button" class="btn btn-outline-danger" id="btnUnduhPdf"><i class="fas fa-file-pdf mr-1"></i> Unduh PDF</button>
                        <button type="button" class="btn btn-outline-success" id="btnUnduhXlsx"><i class="fas fa-file-excel mr-1"></i> Unduh XLSX</button>
                        <button type="button" class="btn btn-outline-primary" id="btnUnduhDocx"><i class="fas fa-file-word mr-1"></i> Unduh DOCX</button>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
