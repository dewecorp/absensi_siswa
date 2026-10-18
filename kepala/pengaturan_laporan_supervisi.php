<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$page_title    = 'Pengaturan Laporan Supervisi';
$current_page  = basename(__FILE__);
$can_manage    = sv_is_supervisor($pdo);
$flash         = sv_render_flash_js();

// ================= SAVE =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $fields = ['laporan_latar_belakang', 'laporan_landasan_hukum', 'laporan_tujuan_supervisi'];
    foreach ($fields as $f) {
        $val = (string)($_POST[$f] ?? '');
        sv_pengaturan_set($pdo, $f, $val);
    }
    sv_log($pdo, 'Pengaturan Laporan', 'Latar belakang/landasan/tujuan diperbarui');
    sv_flash('success', 'Pengaturan laporan berhasil disimpan.');
    redirect('pengaturan_laporan_supervisi.php');
}

// ================= LOAD =================
$defaults = [
    'laporan_latar_belakang' => '<p>Mutu pendidikan di madrasah sangat dipengaruhi oleh kualitas proses belajar mengajar di ruang kelas. Guru sebagai garda terdepan pembelajaran membutuhkan bimbingan, pendampingan, dan refleksi berkala guna menjaga serta meningkatkan kompetensi pedagogik maupun profesionalnya. Kepala madrasah memegang peranan strategis melalui supervisi akademik klinis&mdash;bukan untuk mencari kesalahan (<em>inspectorial</em>), melainkan sebagai kemitraan reflektif dalam mencari solusi bersama atas kendala pembelajaran.</p><p>Pelaksanaan supervisi akademik dilaksanakan secara terencana, terprogram, dan berkelanjutan sebagai bagian dari upaya penjaminan mutu pembelajaran.</p>',
    'laporan_landasan_hukum' => '<ol><li>Undang-Undang Nomor 20 Tahun 2003 tentang Sistem Pendidikan Nasional.</li><li>Peraturan Menteri Agama (PMA) Nomor 58 Tahun 2017 tentang Kepala Madrasah.</li><li>Permendikbudristek Nomor 16 Tahun 2022 tentang Standar Proses pada Pendidikan Anak Usia Dini, Jenjang Pendidikan Dasar, dan Jenjang Pendidikan Menengah.</li><li>Keputusan Menteri Agama (KMA) Nomor 450 Tahun 2024 tentang Pedoman Implementasi Kurikulum pada Madrasah.</li><li>Panduan Akreditasi Lembaga/Satuan Pendidikan dari BAN-PDM.</li></ol>',
    'laporan_tujuan_supervisi' => '<ol><li>Mengidentifikasi ketercapaian perencanaan dan administrasi pembelajaran guru (Kurikulum Merdeka/K13).</li><li>Membina kemampuan guru dalam mengelola pembelajaran yang berpusat pada peserta didik, diferensiasi, dan pemanfaatan media/TPACK.</li><li>Mengembangkan sistem asesmen autentik (formatif dan sumatif) yang objektif dan terukur.</li><li>Menghasilkan rekomendasi tindak lanjut berupa pelatihan, <strong>in-house training</strong> (IHT), atau pembinaan KKG/Kombel.</li></ol>',
];

$vals = [];
foreach ($defaults as $k => $def) {
    $v = sv_pengaturan_get($pdo, $k);
    $vals[$k] = ($v !== null && trim($v) !== '') ? $v : $def;
}
?>
<?php require_once '../templates/header.php'; ?>
<?php require_once '../templates/sidebar.php'; ?>

<div class="main-content">
<section class="section">
<div class="section-header">
    <h1><i class="fas fa-cog"></i> Pengaturan Laporan Supervisi</h1>
    <div class="section-header-breadcrumb">
        <div class="breadcrumb-item"><a href="laporan_supervisi.php">Laporan Supervisi</a></div>
        <div class="breadcrumb-item active">Pengaturan Laporan</div>
    </div>
</div>

<div class="section-body">

<div class="card">
    <div class="card-header">
        <h4><i class="fas fa-edit"></i> Edit Isi BAB I Laporan</h4>
        <div class="card-header-action">
            <a href="laporan_supervisi.php" class="btn btn-light"><i class="fas fa-arrow-left"></i> Kembali</a>
        </div>
    </div>
    <div class="card-body">
        <div class="alert alert-info mb-4">
            <i class="fas fa-info-circle"></i>
            Isi ini ditampilkan secara otomatis di Laporan Lengkap (BAB I). Simpan setelah selesai mengedit.
            Kesimpulan dan Rekomendasi (BAB V) dihasilkan otomatis dari data supervisi.
        </div>

        <form method="POST" id="form-pengaturan">
            <!-- LATAR BELAKANG -->
            <div class="form-group">
                <label class="font-weight-bold" style="font-size:15px;">A. Latar Belakang</label>
                <textarea name="laporan_latar_belakang" id="ed_latar_belakang" rows="8" class="form-control"><?= htmlspecialchars($vals['laporan_latar_belakang'], ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <!-- LANDASAN HUKUM -->
            <div class="form-group mt-4">
                <label class="font-weight-bold" style="font-size:15px;">B. Landasan Hukum</label>
                <textarea name="laporan_landasan_hukum" id="ed_landasan_hukum" rows="8" class="form-control"><?= htmlspecialchars($vals['laporan_landasan_hukum'], ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <!-- TUJUAN SUPERVISI -->
            <div class="form-group mt-4">
                <label class="font-weight-bold" style="font-size:15px;">C. Tujuan Supervisi</label>
                <textarea name="laporan_tujuan_supervisi" id="ed_tujuan_supervisi" rows="8" class="form-control"><?= htmlspecialchars($vals['laporan_tujuan_supervisi'], ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <div class="form-group mt-4 text-right">
                <button type="submit" class="btn btn-primary btn-lg" id="btn-simpan">
                    <i class="fas fa-save"></i> Simpan Pengaturan
                </button>
            </div>
        </form>
    </div>
</div><!-- /card -->
</div><!-- /section-body -->
</section>
</div><!-- /main-content -->

<?php require_once '../templates/footer.php'; ?>

<!-- TinyMCE CDN -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>
<script>
tinymce.init({
    selector: '#ed_latar_belakang, #ed_landasan_hukum, #ed_tujuan_supervisi',
    height: 380,
    menubar: true,
    language: 'id',
    plugins: [
        'advlist','autolink','lists','link','image','charmap','preview',
        'anchor','searchreplace','visualblocks','code','fullscreen',
        'insertdatetime','media','table','help','wordcount','emoticons',
        'codesample','pagebreak','nonbreaking'
    ],
    toolbar: [
        'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor',
        'alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | removeformat | link table codesample emoticons | fullscreen code help'
    ],
    toolbar_mode: 'wrap',
    content_style: 'body { font-family: Arial, sans-serif; font-size: 12pt; }',
    setup: function(editor) {
        // Update the underlying textarea value on form submit
        editor.on('change', function() {
            editor.save();
        });
    }
});

document.getElementById('form-pengaturan').addEventListener('submit', function() {
    tinymce.triggerSave();
});
<?php if ($flash): ?>
<?= $flash ?>
<?php endif; ?>
</script>
</html>
