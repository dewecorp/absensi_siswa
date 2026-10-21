<?php
require_once __DIR__ . '/../../config/config.php';
if (!defined('GAJI_EMBED')) { $__qs = $_SERVER['QUERY_STRING'] ?? ''; header('Location: ' . SIMAD_BASE_URL . '/bendahara/gaji_legger_honor.php' . ($__qs !== '' ? '?' . $__qs : '')); exit(); }
$page_title = 'Legger Honor';


// Get settings
$sql = "SELECT * FROM settings LIMIT 1";
$settings = $conn->query($sql)->fetch_assoc();
// Legger honor selalu menggunakan bulan saat ini (tidak ikut settingan periode gaji)
$periode_aktif = date('Y-m');

// Get legger_honor data
$sql = "SELECT lh.*, p.nama_pembina, e.jenis_ekstrakurikuler, h.jabatan, h.jumlah_honor as honor_per_pertemuan
        FROM legger_honor lh
        JOIN pembina p ON lh.pembina_id = p.id
        JOIN ekstrakurikuler e ON lh.ekstrakurikuler_id = e.id
        JOIN honor h ON lh.honor_id = h.id
        WHERE lh.periode = ?
        ORDER BY p.nama_pembina ASC";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("s", $periode_aktif);
    $stmt->execute();
    $result = $stmt->get_result();
    $legger = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
} else {
    $legger = [];
}
?>

            <div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Legger Honor</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item"><a href="<?php echo SIMAD_BASE_URL; ?>/bendahara/gaji_ringkasan.php">Ringkasan Gaji</a></div>
                            <div class="breadcrumb-item active">Legger Honor</div>
                        </div>
                    </div>

                    <div class="section-body">
                        <div class="card">
                            <div class="card-header">
                                <h4>Legger Honor - <?php echo getPeriodLabel($periode_aktif); ?></h4>
                                <div class="card-header-action">
                                    <button type="button" id="btnGenerateLegger" class="btn btn-success">
                                        <i class="fas fa-sync"></i> <span id="btnGenerateText">Generate Legger</span>
                                        <span id="btnGenerateSpinner" class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                    </button>
                                    <a href="<?php echo $gaji_page_base; ?>cetak_slip_semua?periode=<?php echo $periode_aktif; ?>" class="btn btn-info" target="_blank">
                                        <i class="fas fa-print"></i> Cetak Semua Slip
                                    </a>
                                    <a href="<?php echo $gaji_page_base; ?>cetak_legger?periode=<?php echo $periode_aktif; ?>" class="btn btn-warning" target="_blank">
                                        <i class="fas fa-file-alt"></i> Cetak Legger
                                    </a>
                                    <a href="<?php echo $gaji_page_base; ?>export_excel?periode=<?php echo $periode_aktif; ?>" class="btn btn-success">
                                        <i class="fas fa-file-excel"></i> Ekspor Excel
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped" id="tableLeggerHonor">
                                        <thead>
                                            <tr>
                                                <th>No</th>
                                                <th>Nama Pembina</th>
                                                <th>Jabatan</th>
                                                <th>Jumlah Honor</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($legger)): ?>
                                                <tr>
                                                    <td colspan="5" class="text-center">
                                                        <p class="text-muted">Tidak ada data legger honor untuk periode <?php echo getPeriodLabel($periode_aktif); ?>.</p>
                                                        <p class="text-muted">Silakan klik tombol "Generate Legger" untuk membuat data legger honor.</p>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php $no = 1; foreach ($legger as $l): ?>
                                                    <tr>
                                                        <td><?php echo $no++; ?></td>
                                                        <td><?php echo htmlspecialchars($l['nama_pembina']); ?></td>
                                                        <td><?php echo htmlspecialchars($l['jabatan']); ?></td>
                                                        <td style="text-align: right; white-space: nowrap;"><?php echo formatRupiah($l['total_honor']); ?></td>
                                                        <td>
                                                            <a href="<?php echo $gaji_page_base; ?>cetak_slip?id=<?php echo $l['id']; ?>" class="btn btn-sm btn-info" target="_blank">
                                                                <i class="fas fa-print"></i> Cetak Slip
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

<script>
// Wait for jQuery to be loaded
(function() {
    function initLeggerHonor() {
        if (typeof jQuery === 'undefined' || typeof window.jQuery === 'undefined') {
            setTimeout(initLeggerHonor, 50);
            return;
        }
        
        var $ = window.jQuery;
        
        $(document).ready(function() {
            console.log('Legger Honor page initialized');
            
            // Ensure button is enabled
            $('#btnGenerateLegger').prop('disabled', false);
            console.log('Button disabled status:', $('#btnGenerateLegger').prop('disabled'));
            
            // Only initialize DataTable if table has data rows (not just empty message)
            var tableBody = $('#tableLeggerHonor tbody');
            var allRows = tableBody.find('tr');
            var hasDataRows = false;
            
            // Check if there's at least one row without colspan (actual data row)
            allRows.each(function() {
                var $row = $(this);
                var $td = $row.find('td');
                if ($td.length > 0 && !$td.attr('colspan')) {
                    hasDataRows = true;
                    return false; // break loop
                }
            });
            
            if (hasDataRows) {
                try {
                    $('#tableLeggerHonor').DataTable({
                        language: { url: 'https://cdn.datatables.net/plug-ins/1.11.5/i18n/id.json' },
                        order: [[1, 'asc']],
                        columnDefs: [
                            { targets: 3, className: 'text-right' } // Jumlah Honor column
                        ]
                    });
                    console.log('DataTable initialized successfully');
                } catch (e) {
                    console.error('Error initializing DataTable:', e);
                }
            } else {
                console.log('Table is empty, skipping DataTable initialization');
            }

            // Remove any existing handlers and add new one
            $('#btnGenerateLegger').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                console.log('Generate button clicked');
                
                var btn = $(this);
                var btnText = $('#btnGenerateText');
                var btnSpinner = $('#btnGenerateSpinner');
                
                btn.prop('disabled', true);
                btnText.text('Memproses...');
                btnSpinner.removeClass('d-none');
                
                $.ajax({
                    url: '<?php echo $gaji_page_base; ?>generate_ajax',
                    type: 'POST',
                    data: { periode: '<?php echo $periode_aktif; ?>', csrf_token: window.CSRF_TOKEN || '' },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Berhasil!',
                                text: response.message,
                                timer: 2000
                            }).then(function() {
                                window.location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Gagal!',
                                text: response.message
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', status, error);
                        console.error('Response:', xhr.responseText);
                        var errorMsg = 'Terjadi kesalahan saat generate legger';
                        try {
                            var response = JSON.parse(xhr.responseText);
                            errorMsg = response.message || errorMsg;
                        } catch(e) {
                            errorMsg = xhr.responseText || errorMsg;
                        }
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: errorMsg,
                            confirmButtonText: 'OK'
                        });
                    },
                    complete: function() {
                        btn.prop('disabled', false);
                        btnText.text('Generate Legger');
                        btnSpinner.addClass('d-none');
                    }
                });
            });
        });
    }
    
    initLeggerHonor();
})();

// Fallback: ensure button works even if jQuery ready hasn't fired yet
(function() {
    setTimeout(function() {
        if (typeof window.jQuery !== 'undefined') {
            var $ = window.jQuery;
            var btn = $('#btnGenerateLegger');
            if (btn.length && btn.prop('disabled')) {
                btn.prop('disabled', false);
                console.log('Button enabled via fallback');
            }
        }
    }, 500);
})();
</script>
