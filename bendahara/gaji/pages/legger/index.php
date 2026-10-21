<?php
require_once __DIR__ . '/../../config/config.php';
if (!defined('GAJI_EMBED')) { $__qs = $_SERVER['QUERY_STRING'] ?? ''; header('Location: ' . SIMAD_BASE_URL . '/bendahara/gaji_legger.php' . ($__qs !== '' ? '?' . $__qs : '')); exit(); }
$page_title = 'Legger Gaji';


$sql = "SELECT * FROM settings LIMIT 1";
$settings = $conn->query($sql)->fetch_assoc();
$periode_aktif = $settings['periode_aktif'] ?? date('Y-m');
$jumlah_periode = $settings['jumlah_periode'] ?? 1;
$periode_mulai = $settings['periode_mulai'] ?? '';
$periode_akhir = $settings['periode_akhir'] ?? '';
// Periode leger mengikuti periode_aktif dari settings, tidak bisa difilter per bulan
$periode = $periode_aktif;

// Get tunjangan: hanya yang aktif
$sql = "SELECT t.* FROM tunjangan t 
        WHERE t.aktif = 1
        ORDER BY t.nama_tunjangan";
$tunjangan = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

// Get potongan: hanya yang aktif
$sql = "SELECT p.* FROM potongan p 
        WHERE p.aktif = 1
        ORDER BY p.nama_potongan";
$potongan = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

// Get all teachers
$sql = "SELECT * FROM guru ORDER BY nama_lengkap";
$guru = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

// Generate legger is now handled via AJAX (generate_ajax.php)

$sql = "SELECT lg.*, g.nama_lengkap 
        FROM legger_gaji lg 
        JOIN guru g ON lg.guru_id = g.id 
        WHERE lg.periode = ? 
        ORDER BY g.nama_lengkap";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $periode);
$stmt->execute();
$legger = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

            <div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Legger Gaji</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item"><a href="<?php echo SIMAD_BASE_URL; ?>/bendahara/gaji_ringkasan.php">Ringkasan Gaji</a></div>
                            <div class="breadcrumb-item active">Legger Gaji</div>
                        </div>
                    </div>

                    <div class="section-body">
                        <div class="card">
                            <div class="card-header">
                                <h4>Legger Gaji - <?php 
                                if ($jumlah_periode > 1 && !empty($periode_mulai) && !empty($periode_akhir)) {
                                    echo getPeriodRangeLabel($periode_mulai, $periode_akhir);
                                } else {
                                    echo getPeriodLabel($periode);
                                }
                                ?></h4>
                                <div class="card-header-action">
                                    <button type="button" id="btnGenerateLegger" class="btn btn-success">
                                        <i class="fas fa-sync"></i> <span id="btnGenerateText">Generate Legger</span>
                                        <span id="btnGenerateSpinner" class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                    </button>
                                    <a href="<?php echo $gaji_page_base; ?>cetak_slip_semua?periode=<?php echo $periode; ?>" class="btn btn-warning" target="_blank">
                                        <i class="fas fa-file-invoice"></i> Cetak Slip Semua
                                    </a>
                                    <a href="<?php echo $gaji_page_base; ?>cetak_legger?periode=<?php echo $periode; ?>" class="btn btn-primary" target="_blank">
                                        <i class="fas fa-print"></i> Cetak Legger
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php 
                                $total_data = count($legger);
                                ?>
                                <div class="mb-3">
                                    <strong>Total Data: <?php echo $total_data; ?> Guru</strong>
                                </div>
                                <div class="table-responsive" id="tableLegger" style="overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch;">
                                    <table class="table table-bordered table-striped" id="tableLeggerData">
                                        <thead>
                                            <tr>
                                                <th rowspan="2" style="text-align: center; width: 50px !important; min-width: 50px !important; max-width: 50px !important;">No</th>
                                                <th rowspan="2" style="text-align: center; width: 200px !important; min-width: 200px !important;">Nama</th>
                                                <th rowspan="2" style="text-align: center; width: 130px !important; min-width: 130px !important;">Gaji Pokok</th>
                                                <?php if (count($tunjangan) > 0): ?>
                                                    <th colspan="<?php echo count($tunjangan); ?>" class="text-center" style="background-color: #e9ecef;">Tunjangan</th>
                                                <?php endif; ?>
                                                <th rowspan="2" style="text-align: center; width: 150px !important; min-width: 150px !important; font-weight: bold; background-color: #fff3cd;">Total Tunjangan</th>
                                                <?php if (count($potongan) > 0): ?>
                                                    <th colspan="<?php echo count($potongan); ?>" class="text-center" style="background-color: #f8d7da;">Potongan</th>
                                                <?php endif; ?>
                                                <th rowspan="2" style="text-align: center; width: 150px !important; min-width: 150px !important; font-weight: bold; background-color: #f8d7da;">Total Potongan</th>
                                                <th rowspan="2" style="text-align: center; width: 150px !important; min-width: 150px !important; font-weight: bold; background-color: #d1ecf1;">Gaji Bersih</th>
                                                <th rowspan="2" style="text-align: center; width: 140px !important; min-width: 140px !important;">Aksi</th>
                                            </tr>
                                            <tr>
                                                <?php foreach ($tunjangan as $t): ?>
                                                    <th style="text-align: center; width: 160px !important; min-width: 160px !important; background-color: #e9ecef; padding: 10px 8px !important; font-size: 13px !important; white-space: nowrap !important;"><?php echo htmlspecialchars($t['nama_tunjangan']); ?></th>
                                                <?php endforeach; ?>
                                                <?php foreach ($potongan as $p): ?>
                                                    <th style="text-align: center; width: 160px !important; min-width: 160px !important; background-color: #f8d7da; padding: 10px 8px !important; font-size: 13px !important; white-space: nowrap !important;"><?php echo htmlspecialchars($p['nama_potongan']); ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $no = 1; foreach ($legger as $l): ?>
                                                <?php
                                                // Semua data di legger_gaji dan legger_detail sudah dikalikan dengan jumlah_periode saat generate (di generate_ajax.php)
                                                // Jadi langsung gunakan nilai dari database tanpa dikalikan lagi
                                                
                                                // Get legger details - data sudah dikalikan dengan jumlah_periode saat generate
                                                $sql_detail = "SELECT * FROM legger_detail WHERE legger_id = ?";
                                                $stmt_detail = $conn->prepare($sql_detail);
                                                $tunjangan_data = [];
                                                $potongan_data = [];
                                                
                                                if ($stmt_detail) {
                                                    $stmt_detail->bind_param("i", $l['id']);
                                                    $stmt_detail->execute();
                                                    $result_detail = $stmt_detail->get_result();
                                                    $details = $result_detail->fetch_all(MYSQLI_ASSOC);
                                                    
                                                    // Map data berdasarkan item_id (yang seharusnya adalah tunjangan_id atau potongan_id)
                                                    foreach ($details as $d) {
                                                        if ($d['jenis'] == 'tunjangan') {
                                                            $tunjangan_data[$d['item_id']] = floatval($d['jumlah']);
                                                        } else if ($d['jenis'] == 'potongan') {
                                                            $potongan_data[$d['item_id']] = floatval($d['jumlah']);
                                                        }
                                                    }
                                                    $stmt_detail->close();
                                                }
                                                
                                                // Pastikan semua tunjangan dan potongan ada di array (walaupun 0)
                                                // Data dari legger_detail sudah dikalikan jumlah_periode, jadi gunakan itu
                                                foreach ($tunjangan as $t) {
                                                    if (!isset($tunjangan_data[$t['id']])) {
                                                        $tunjangan_data[$t['id']] = 0;
                                                    }
                                                }
                                                foreach ($potongan as $p) {
                                                    if (!isset($potongan_data[$p['id']])) {
                                                        $potongan_data[$p['id']] = 0;
                                                    }
                                                }
                                                
                                                // Gaji pokok sudah dikalikan jumlah_periode saat generate
                                                $gaji_pokok_display = floatval($l['gaji_pokok']);
                                                
                                                $total_tunjangan_display = 0;
                                                foreach ($tunjangan as $t) {
                                                    $total_tunjangan_display += floatval($tunjangan_data[$t['id']] ?? 0);
                                                }
                                                
                                                $total_potongan_display = 0;
                                                foreach ($potongan as $p) {
                                                    $total_potongan_display += floatval($potongan_data[$p['id']] ?? 0);
                                                }
                                                
                                                $gaji_bersih_display = $gaji_pokok_display + $total_tunjangan_display - $total_potongan_display;
                                                ?>
                                                <tr>
                                                    <td style="text-align: center;"><?php echo $no++; ?></td>
                                                    <td><?php echo htmlspecialchars($l['nama_lengkap']); ?></td>
                                                    <td style="text-align: right;"><?php echo formatRupiahTanpaRp($gaji_pokok_display); ?></td>
                                                    <?php foreach ($tunjangan as $t): ?>
                                                        <td style="text-align: right; width: 160px !important; min-width: 160px !important; white-space: nowrap !important;"><?php echo formatRupiahTanpaRp($tunjangan_data[$t['id']] ?? 0); ?></td>
                                                    <?php endforeach; ?>
                                                    <td style="text-align: right; font-weight: bold;"><?php echo formatRupiahTanpaRp($total_tunjangan_display); ?></td>
                                                    <?php foreach ($potongan as $p): ?>
                                                        <td style="text-align: right; width: 160px !important; min-width: 160px !important; white-space: nowrap !important;"><?php echo formatRupiahTanpaRp($potongan_data[$p['id']] ?? 0); ?></td>
                                                    <?php endforeach; ?>
                                                    <td style="text-align: right; font-weight: bold;"><?php echo formatRupiahTanpaRp($total_potongan_display); ?></td>
                                                    <td style="text-align: right; font-weight: bold;"><?php echo formatRupiahTanpaRp($gaji_bersih_display); ?></td>
                                                    <td>
                                                        <a href="<?php echo $gaji_page_base; ?>cetak_struk?id=<?php echo $l['id']; ?>" class="btn btn-sm btn-info" target="_blank" data-toggle="tooltip" title="Cetak Slip Gaji">
                                                            <i class="fas fa-print"></i> Cetak Slip Gaji
                                                        </a>
                                                    </td>
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

<style>
#loadingOverlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 9999;
    display: none;
    justify-content: center;
    align-items: center;
}

#loadingContent {
    background: white;
    padding: 30px;
    border-radius: 10px;
    text-align: center;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
}

#loadingContent .spinner-border {
    width: 3rem;
    height: 3rem;
    border-width: 0.3em;
}

#loadingProgress {
    margin-top: 15px;
    font-size: 14px;
    color: #666;
}

/* Scroll horizontal untuk tabel - tanpa scroll vertical */
#tableLegger {
    overflow-x: auto !important;
    overflow-y: hidden !important;
    width: 100%;
    position: relative; /* Penting untuk sticky positioning */
}

/* Base table styling - sangat spesifik untuk override DataTables */
#tableLeggerData,
table#tableLeggerData,
#tableLeggerData.dataTable,
table.dataTable#tableLeggerData,
#tableLeggerData.dataTable thead th,
#tableLeggerData.dataTable tbody td {
    border-collapse: collapse !important;
    width: 100% !important;
    margin: 0 !important;
    table-layout: auto !important;
}

#tableLeggerData th,
#tableLeggerData td,
#tableLeggerData.dataTable th,
#tableLeggerData.dataTable td {
    padding: 10px 8px !important;
    vertical-align: middle !important;
    border: 1px solid #dee2e6 !important;
    font-size: 13px !important;
    box-sizing: border-box !important;
}

/* Header styling */
#tableLeggerData thead th,
#tableLeggerData.dataTable thead th {
    background-color: #f8f9fa !important;
    font-weight: 600 !important;
    border-bottom: 2px solid #dee2e6 !important;
}

/* Header dengan colspan (Tunjangan, Potongan) */
#tableLeggerData thead th[colspan],
#tableLeggerData.dataTable thead th[colspan] {
    text-align: center !important;
    background-color: #e9ecef !important;
}

/* Kolom No - center */
#tableLeggerData thead th:first-child,
#tableLeggerData tbody td:first-child,
#tableLeggerData.dataTable thead th:first-child,
#tableLeggerData.dataTable tbody td:first-child {
    text-align: center !important;
    width: 50px !important;
    min-width: 50px !important;
    max-width: 50px !important;
}

/* Kolom Nama - left, lebih lebar */
/* Kolom Nama - header center, body left */
#tableLeggerData thead th:nth-child(2),
#tableLeggerData.dataTable thead th:nth-child(2),
table.dataTable#tableLeggerData thead th:nth-child(2) {
    text-align: center !important;
    width: 200px !important;
    min-width: 200px !important;
}

#tableLeggerData tbody td:nth-child(2),
#tableLeggerData.dataTable tbody td:nth-child(2),
table.dataTable#tableLeggerData tbody td:nth-child(2) {
    text-align: left !important;
    width: 200px !important;
    min-width: 200px !important;
}

/* Kolom Gaji Pokok - header center, body right */
#tableLeggerData thead th:nth-child(3),
#tableLeggerData.dataTable thead th:nth-child(3),
table.dataTable#tableLeggerData thead th:nth-child(3) {
    text-align: center !important;
    width: 130px !important;
    min-width: 130px !important;
}

#tableLeggerData tbody td:nth-child(3),
#tableLeggerData.dataTable tbody td:nth-child(3),
table.dataTable#tableLeggerData tbody td:nth-child(3) {
    text-align: right !important;
    width: 130px !important;
    min-width: 130px !important;
    white-space: nowrap !important;
}

/* Kolom Tunjangan individual - header center, body right align, width lebih lebar - selector sangat spesifik */
#tableLeggerData thead tr:last-child th:not([colspan]):not([rowspan]):not(:first-child):not(:nth-child(2)):not(:nth-child(3)),
#tableLeggerData.dataTable thead tr:last-child th:not([colspan]):not([rowspan]):not(:first-child):not(:nth-child(2)):not(:nth-child(3)),
table.dataTable#tableLeggerData thead tr:last-child th:not([colspan]):not([rowspan]):not(:first-child):not(:nth-child(2)):not(:nth-child(3)) {
    text-align: center !important;
    width: 160px !important;
    min-width: 160px !important;
    max-width: 160px !important;
    padding: 10px 8px !important;
    font-size: 13px !important;
    white-space: nowrap !important;
    box-sizing: border-box !important;
}

/* Kolom angka tunjangan/potongan individual - header center, body right align - selector sangat spesifik */
#tableLeggerData tbody td:nth-child(n+4):not(:last-child):not(:nth-last-child(4)):not(:nth-last-child(3)):not(:nth-last-child(2)),
#tableLeggerData.dataTable tbody td:nth-child(n+4):not(:last-child):not(:nth-last-child(4)):not(:nth-last-child(3)):not(:nth-last-child(2)),
table.dataTable#tableLeggerData tbody td:nth-child(n+4):not(:last-child):not(:nth-last-child(4)):not(:nth-last-child(3)):not(:nth-last-child(2)) {
    text-align: right !important;
    white-space: nowrap !important;
    width: 160px !important;
    min-width: 160px !important;
    max-width: 160px !important;
    box-sizing: border-box !important;
}

/* Header Total (Total Tunjangan, Total Potongan, Gaji Bersih) - center align */
#tableLeggerData thead th[rowspan="2"]:nth-child(n+4):not(:last-child),
#tableLeggerData.dataTable thead th[rowspan="2"]:nth-child(n+4):not(:last-child) {
    text-align: center !important;
    width: 150px !important;
    min-width: 150px !important;
}

/* Body Total (Total Tunjangan, Total Potongan, Gaji Bersih) - right align, bold */
#tableLeggerData tbody td:nth-last-child(4),
#tableLeggerData tbody td:nth-last-child(3),
#tableLeggerData tbody td:nth-last-child(2),
#tableLeggerData.dataTable tbody td:nth-last-child(4),
#tableLeggerData.dataTable tbody td:nth-last-child(3),
#tableLeggerData.dataTable tbody td:nth-last-child(2) {
    text-align: right !important;
    font-weight: 600 !important;
    background-color: #f8f9fa !important;
    width: 150px !important;
    min-width: 150px !important;
    white-space: nowrap !important;
}

/* Kolom Aksi - center */
#tableLeggerData thead th:last-child,
#tableLeggerData tbody td:last-child,
#tableLeggerData.dataTable thead th:last-child,
#tableLeggerData.dataTable tbody td:last-child {
    text-align: center !important;
    white-space: nowrap !important;
    width: 140px !important;
    min-width: 140px !important;
}

/* Sticky kolom No saat scroll horizontal */
#tableLeggerData thead th:first-child,
#tableLeggerData.dataTable thead th:first-child {
    position: sticky !important;
    left: 0 !important;
    z-index: 15 !important; /* Lebih tinggi untuk header */
    background-color: #f8f9fa !important;
}

#tableLeggerData tbody td:first-child,
#tableLeggerData.dataTable tbody td:first-child {
    position: sticky !important;
    left: 0 !important;
    z-index: 5 !important;
}

/* Sticky kolom Nama saat scroll horizontal */
#tableLeggerData thead th:nth-child(2),
#tableLeggerData.dataTable thead th:nth-child(2) {
    position: sticky !important;
    left: 50px !important; /* Width dari kolom No */
    z-index: 15 !important; /* Lebih tinggi untuk header */
    background-color: #f8f9fa !important;
}

#tableLeggerData tbody td:nth-child(2),
#tableLeggerData.dataTable tbody td:nth-child(2) {
    position: sticky !important;
    left: 50px !important; /* Width dari kolom No */
    z-index: 5 !important;
}

/* Background color untuk kolom sticky di body - perlu mengikuti zebra striping */
#tableLeggerData tbody tr:nth-child(odd) td:first-child {
    background-color: #fff !important;
}

#tableLeggerData tbody tr:nth-child(even) td:first-child {
    background-color: #f9f9f9 !important;
}

#tableLeggerData tbody tr:nth-child(odd) td:nth-child(2) {
    background-color: #fff !important;
}

#tableLeggerData tbody tr:nth-child(even) td:nth-child(2) {
    background-color: #f9f9f9 !important;
}
</style>

<script>
var tableLegger;

// Wait for jQuery to be available (jQuery is loaded in footer.php)
(function() {
    function initLeggerPage() {
        // Check if jQuery is available
        if (typeof window.jQuery === 'undefined') {
            // Retry after a short delay
            setTimeout(initLeggerPage, 50);
            return;
        }
        
        // Use jQuery from window object
        var $ = window.jQuery;
        
        $(document).ready(function() {
            // Clear DataTable state
            if (typeof(Storage) !== "undefined") {
                Object.keys(localStorage).forEach(function(key) {
                    if (key.indexOf('DataTables_tableLeggerData') === 0 || key.indexOf('DataTables_') === 0) {
                        localStorage.removeItem(key);
                    }
                });
                Object.keys(sessionStorage).forEach(function(key) {
                    if (key.indexOf('DataTables_tableLeggerData') === 0 || key.indexOf('DataTables_') === 0) {
                        sessionStorage.removeItem(key);
                    }
                });
            }
            
            // Destroy existing instance
            if ($.fn.DataTable.isDataTable('#tableLeggerData')) {
                $('#tableLeggerData').DataTable().destroy();
            }
            
            tableLegger = $('#tableLeggerData').DataTable({
                dom: 'Bfrtip',
                buttons: [
                    { 
                        text: '<i class="fas fa-file-excel"></i> Excel', 
                        className: 'btn btn-success btn-sm',
                        action: function (e, dt, node, config) {
                            window.location.href = '<?php echo $gaji_page_base; ?>export_excel?periode=<?php echo $periode; ?>';
                        }
                    },
                    { 
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn btn-danger btn-sm',
                        action: function (e, dt, node, config) {
                            window.open('<?php echo $gaji_page_base; ?>cetak_legger?periode=<?php echo $periode; ?>', '_blank');
                        }
                    },
                    { 
                        text: '<i class="fas fa-print"></i> Print',
                        className: 'btn btn-info btn-sm',
                        action: function (e, dt, node, config) {
                            window.open('<?php echo $gaji_page_base; ?>cetak_legger?periode=<?php echo $periode; ?>', '_blank');
                        }
                    }
                ],
                language: { 
                    url: 'https://cdn.datatables.net/plug-ins/1.11.5/i18n/id.json',
                    info: "Menampilkan _START_ sampai _END_ dari _TOTAL_ data",
                    infoEmpty: "Menampilkan 0 sampai 0 dari 0 data",
                    infoFiltered: "(disaring dari _MAX_ total data)"
                },
                order: [[1, 'asc']],
                info: true
            });
            
            // Handle Generate Legger button - use off() first to prevent duplicate bindings
            $('#btnGenerateLegger').off('click').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                var btn = $(this);
                var btnText = $('#btnGenerateText');
                var btnSpinner = $('#btnGenerateSpinner');
                var loadingOverlay = $('#loadingOverlay');
                var loadingProgress = $('#loadingProgress');
                
                // Check if elements exist
                if (btn.length === 0) {
                    return;
                }
                
                // Disable button
                btn.prop('disabled', true);
                btnText.text('Memproses...');
                btnSpinner.removeClass('d-none');
                
                // Show loading overlay
                loadingOverlay.css('display', 'flex');
                loadingProgress.text('Sedang memproses generate legger, harap tunggu...');
                
                // Send AJAX request
                $.ajax({
                    url: '<?php echo $gaji_page_base; ?>generate_ajax',
                    type: 'POST',
                    data: {
                        periode: '<?php echo $periode; ?>',
                        csrf_token: window.CSRF_TOKEN || ''
                    },
                    dataType: 'json',
                    beforeSend: function() {
                    },
                    success: function(response) {
                        // Hide loading overlay first
                        loadingOverlay.hide();
                        
                        // Check if response is valid
                        if (!response) {
                            Swal.fire({
                                icon: 'error',
                                title: 'Gagal!',
                                text: 'Tidak ada response dari server'
                            });
                            btn.prop('disabled', false);
                            btnText.text('Generate Legger');
                            btnSpinner.addClass('d-none');
                            return;
                        }
                        
                        if (response.success) {
                            // Show success message
                            var icon = response.processed > 0 ? 'success' : 'warning';
                            Swal.fire({
                                icon: icon,
                                title: response.processed > 0 ? 'Berhasil!' : 'Peringatan!',
                                html: response.message || 'Legger berhasil digenerate',
                                timer: response.processed > 0 ? 3000 : 5000,
                                showConfirmButton: response.processed === 0,
                                confirmButtonText: 'OK'
                            }).then(function() {
                                if (response.processed > 0) {
                                    // Reload page after short delay to refresh table
                                    window.location.reload();
                                }
                            });
                        } else {
                            // Show error message
                            Swal.fire({
                                icon: 'error',
                                title: 'Gagal!',
                                text: response.message || 'Terjadi kesalahan saat generate legger',
                                confirmButtonText: 'OK'
                            });
                            
                            // Re-enable button
                            btn.prop('disabled', false);
                            btnText.text('Generate Legger');
                            btnSpinner.addClass('d-none');
                        }
                    },
                    error: function(xhr, status, error) {
                        var errorMessage = 'Terjadi kesalahan saat mengirim request.';
                        if (xhr.responseText) {
                            try {
                                var errorResponse = JSON.parse(xhr.responseText);
                                if (errorResponse.message) {
                                    errorMessage = errorResponse.message;
                                }
                            } catch (e) {
                                // If response is not JSON, use default message
                            }
                        }
                        
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal!',
                            text: errorMessage
                        });
                        
                        // Re-enable button
                        btn.prop('disabled', false);
                        btnText.text('Generate Legger');
                        btnSpinner.addClass('d-none');
                        loadingOverlay.hide();
                    }
                });
            });
        });
    }
    
    // Start initialization
    initLeggerPage();
})();
</script>

<!-- Loading Overlay -->
<div id="loadingOverlay">
    <div id="loadingContent">
        <div class="spinner-border text-primary" role="status">
            <span class="sr-only">Loading...</span>
        </div>
        <div id="loadingProgress">Sedang memproses...</div>
    </div>
</div>
