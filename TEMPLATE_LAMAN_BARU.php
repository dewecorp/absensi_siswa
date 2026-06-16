<?php
// Template Laman Baru - Copy file ini dan ubah sesuai kebutuhan

// 1. Atur judul laman
$page_title = 'Judul Laman Anda';

// 2. Atur nama file (untuk pengecekan menu aktif
$current_page = basename(__FILE__);

// 3. Include header (sesuaikan dengan level user
require_once '../templates/header.php';

// 4. Include sidebar
require_once '../templates/sidebar.php';
?>

<!-- Konten Utama Anda
<div class="main-content">
    <section class="section">
        <!-- Bagian Header dengan Breadcrumb OTOMATIS!
        <div class="section-header">
            <h1><?= htmlspecialchars($page_title) ?></h1>
            <!-- Hanya butuh baris ini untuk breadcrumb!
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4><?= htmlspecialchars($page_title) ?></h4>
                </div>
                <div class="card-body">
                    <p>Isi konten laman Anda di sini...</p>
                </div>
            </div>
        </div>
    </section>
</div>

<?php
// 5. Include footer
require_once '../templates/footer.php';
?>