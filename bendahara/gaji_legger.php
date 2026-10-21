<?php
/**
 * Legger Gaji - modul gaji SIMAD (hasil integrasi SIGaji).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (!isAuthorized(['bendahara', 'admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}

define('GAJI_EMBED', true);
require_once __DIR__ . '/gaji/config/config.php';

$gaji_page_base = BASE_URL . 'pages/legger/';

ob_start();
require __DIR__ . '/gaji/pages/legger/index.php';
$gaji_content = ob_get_clean();

$page_title = isset($page_title) && $page_title !== '' ? $page_title : 'Legger Gaji';
include __DIR__ . '/../templates/header.php';
include_once __DIR__ . '/../templates/sidebar.php';
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.2.2/css/buttons.bootstrap4.min.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars(app_base_path() . '/assets/css/gaji-module.css?v=' . (is_readable(__DIR__ . '/../assets/css/gaji-module.css') ? (string) filemtime(__DIR__ . '/../assets/css/gaji-module.css') : '1'), ENT_QUOTES, 'UTF-8'); ?>">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.2.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.bootstrap4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.print.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
window.GAJI_BASE = <?php echo json_encode(BASE_URL); ?>;
window.CSRF_TOKEN = <?php echo json_encode(csrfToken()); ?>;
window.confirmDelete = window.confirmDelete || function (url, text) {
    var doSubmit = function () {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = url;
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'csrf_token';
        input.value = window.CSRF_TOKEN || '';
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    };
    if (typeof Swal === 'undefined') {
        if (confirm('Apakah Anda yakin ingin menghapus data ini?')) doSubmit();
        return;
    }
    Swal.fire({
        title: 'Apakah Anda yakin?',
        text: text || 'Data yang dihapus tidak dapat dikembalikan!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Ya, Hapus!',
        cancelButtonText: 'Batal'
    }).then(function (result) {
        if (result.isConfirmed) doSubmit();
    });
};
</script>
<?php echo $gaji_content; ?>
<?php
// JQuery sudah dimuat di wrapper (satu instance) - cegah footer memuat versi lain.
$skip_footer_jquery = true;
include __DIR__ . '/../templates/footer.php';